<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Test\Unit\Model\Orchestrator;

use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\Model\AbstractModel;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shubo\ShippingCore\Api\CarrierGatewayInterface;
use Shubo\ShippingCore\Api\CarrierRegistryInterface;
use Shubo\ShippingCore\Api\Data\CircuitBreakerStateInterface;
use Shubo\ShippingCore\Api\Data\Dto\ContactAddress;
use Shubo\ShippingCore\Api\Data\Dto\ParcelSpec;
use Shubo\ShippingCore\Api\Data\Dto\ShipmentRequest;
use Shubo\ShippingCore\Api\Data\Dto\ShipmentResponse;
use Shubo\ShippingCore\Api\Data\ShipmentInterface;
use Shubo\ShippingCore\Api\RateLimiterInterface;
use Shubo\ShippingCore\Api\ShipmentRepositoryInterface;
use Shubo\ShippingCore\Exception\CircuitOpenException;
use Shubo\ShippingCore\Exception\RateLimitedException;
use Shubo\ShippingCore\Exception\ShipmentDispatchFailedException;
use Shubo\ShippingCore\Exception\TransientHttpException;
use Shubo\ShippingCore\Model\Data\Shipment;
use Shubo\ShippingCore\Model\Logging\StructuredLogger;
use Shubo\ShippingCore\Model\Orchestrator\IdempotencyStore;
use Shubo\ShippingCore\Model\Orchestrator\ShipmentOrchestrator;
use Shubo\ShippingCore\Model\Resilience\DeadLetterPublisher;
use Shubo\ShippingCore\Model\Resilience\RetryPolicy;
use Shubo\ShippingCore\Model\Resilience\Sleeper;
use Shubo\ShippingCore\Test\Unit\Fake\FakeCarrierGateway;
use Shubo\ShippingCore\Test\Unit\Fake\InMemoryCircuitBreaker;

/**
 * Unit tests for {@see ShipmentOrchestrator}. Covers the exit-criteria from
 * design doc Phase 4:
 *
 * - Idempotent double-dispatch: second call returns same row, no second
 *   carrier call, no second `_dispatched` event.
 * - Circuit-open short-circuits without calling the gateway.
 * - Retry path on transient 5xx eventually succeeds.
 * - Hard-fail after retry exhaustion publishes to DLQ and fires
 *   `_dispatch_failed`.
 * - Rate-limiter exhaustion classifies as transient and retries.
 *
 * ShipmentFactory handling: Magento's `*Factory` classes are generated at
 * runtime; in unit tests we don't have the generator, so we pass a minimal
 * anonymous factory whose `create()` returns a pre-built Shipment. This keeps
 * the orchestrator's factory-typed constructor signature honoured.
 */
class ShipmentOrchestratorTest extends TestCase
{
    private const CARRIER_CODE = 'fake';
    private const CLIENT_CODE = 'trk-unit-001';
    private const ORDER_ID = 42;
    private const MERCHANT_ID = 7;

    /** @var CarrierRegistryInterface&MockObject */
    private CarrierRegistryInterface $registry;

    /** @var ShipmentRepositoryInterface&MockObject */
    private ShipmentRepositoryInterface $repository;

    /** @var IdempotencyStore&MockObject */
    private IdempotencyStore $idempotencyStore;

    /** @var RateLimiterInterface&MockObject */
    private RateLimiterInterface $rateLimiter;

    /** @var DeadLetterPublisher&MockObject */
    private DeadLetterPublisher $deadLetterPublisher;

    /** @var EventManagerInterface&MockObject */
    private EventManagerInterface $eventManager;

    /** @var StructuredLogger&MockObject */
    private StructuredLogger $logger;

    private InMemoryCircuitBreaker $circuitBreaker;

    private RetryPolicy $retryPolicy;

    private FakeCarrierGateway $gateway;

    /** @var list<array{name:string, data:array<string,mixed>}> */
    private array $capturedEvents = [];

    protected function setUp(): void
    {
        $this->registry = $this->createMock(CarrierRegistryInterface::class);
        $this->repository = $this->createMock(ShipmentRepositoryInterface::class);
        $this->idempotencyStore = $this->createMock(IdempotencyStore::class);
        $this->rateLimiter = $this->createMock(RateLimiterInterface::class);
        $this->deadLetterPublisher = $this->createMock(DeadLetterPublisher::class);
        $this->eventManager = $this->createMock(EventManagerInterface::class);
        $this->logger = $this->createMock(StructuredLogger::class);

        $this->circuitBreaker = new InMemoryCircuitBreaker();
        $sleeper = $this->createMock(Sleeper::class);
        $sleeper->method('sleepMs')->willReturnCallback(static function (int $ms): void {
            // no-op: zero-sleep so retry tests don't pause real-time.
        });
        $this->retryPolicy = new RetryPolicy($sleeper, $this->createMock(StructuredLogger::class));

        $this->gateway = new FakeCarrierGateway(self::CARRIER_CODE);

        $this->capturedEvents = [];
        $this->eventManager->method('dispatch')->willReturnCallback(
            function (string $name, array $data = []): void {
                $this->capturedEvents[] = ['name' => $name, 'data' => $data];
            },
        );
    }

    public function testDispatchSuccessPersistsRowAndFiresCreatedAndDispatchedEvents(): void
    {
        $shipment = $this->newShipment(100);
        $orchestrator = $this->orchestratorWith(factoryInstance: $shipment, gateway: $this->gateway);

        $this->registry->method('has')->with(self::CARRIER_CODE)->willReturn(true);
        $this->registry->method('get')->with(self::CARRIER_CODE)->willReturn($this->gateway);
        $this->idempotencyStore->method('findExisting')->willReturn(null);
        $this->rateLimiter->method('acquire')->willReturn(true);

        // Repository save called: once for pending, once after carrier success.
        $this->repository->expects(self::exactly(2))->method('save')->willReturn($shipment);

        $this->gateway->setNextResponse('createShipment', new ShipmentResponse(
            carrierTrackingId: 'CARRIER-XYZ',
            labelUrl: 'https://labels/xyz.pdf',
            status: ShipmentInterface::STATUS_READY_FOR_PICKUP,
            raw: [],
        ));

        $result = $orchestrator->dispatch($this->newRequest());

        self::assertSame($shipment, $result);
        self::assertSame('CARRIER-XYZ', $result->getCarrierTrackingId());
        self::assertSame('https://labels/xyz.pdf', $result->getLabelUrl());
        self::assertSame(ShipmentInterface::STATUS_READY_FOR_PICKUP, $result->getStatus());

        $this->assertEventFired('shubo_shipping_shipment_created');
        $this->assertEventFired('shubo_shipping_shipment_dispatched');
        $this->assertEventNotFired('shubo_shipping_dispatch_failed');
    }

    public function testIdempotentDoubleDispatchReturnsSameRowAndSkipsCarrierCall(): void
    {
        $existing = $this->newShipment(555);
        $existing->setCarrierTrackingId('ALREADY-DISPATCHED');
        $existing->setStatus(ShipmentInterface::STATUS_IN_TRANSIT);

        $orchestrator = $this->orchestratorWith(factoryInstance: null, gateway: $this->gateway);

        $this->registry->method('has')->with(self::CARRIER_CODE)->willReturn(true);
        $this->registry->method('get')->with(self::CARRIER_CODE)->willReturn($this->gateway);
        $this->idempotencyStore->method('findExisting')
            ->with(self::CARRIER_CODE, self::CLIENT_CODE)
            ->willReturn(555);
        $this->repository->method('getById')->with(555)->willReturn($existing);

        // No pending-row creation on an idempotent hit.
        $this->repository->expects(self::never())->method('save');
        // And the carrier must not be called a second time.
        $this->gateway->setNextError('createShipment', new \LogicException('must not call'));

        $result = $orchestrator->dispatch($this->newRequest());

        self::assertSame($existing, $result);
        self::assertSame('ALREADY-DISPATCHED', $result->getCarrierTrackingId());
        // No events at all — re-firing created/dispatched would be a bug.
        self::assertSame([], $this->capturedEvents);
    }

    public function testCircuitOpenShortCircuitsBeforeCallingGateway(): void
    {
        $shipment = $this->newShipment(200);
        $this->circuitBreaker->forceState(
            self::CARRIER_CODE,
            CircuitBreakerStateInterface::STATE_OPEN,
            'test',
        );

        $orchestrator = $this->orchestratorWith(factoryInstance: $shipment, gateway: $this->gateway);

        $this->registry->method('has')->with(self::CARRIER_CODE)->willReturn(true);
        $this->registry->method('get')->with(self::CARRIER_CODE)->willReturn($this->gateway);
        $this->idempotencyStore->method('findExisting')->willReturn(null);
        $this->repository->method('save')->willReturn($shipment);

        // Carrier MUST NOT be called when the breaker is open.
        $this->gateway->setNextError('createShipment', new \LogicException('gateway must not be called'));

        $this->expectException(CircuitOpenException::class);
        try {
            $orchestrator->dispatch($this->newRequest());
        } finally {
            // The pending row was saved (so an operator can retry) and the
            // `_created` event fired, but NOT the `_dispatched` event and NOT
            // a DLQ publish (the caller will retry once the breaker closes).
            $this->assertEventFired('shubo_shipping_shipment_created');
            $this->assertEventNotFired('shubo_shipping_shipment_dispatched');
            $this->assertEventNotFired('shubo_shipping_dispatch_failed');
        }
    }

    public function testRetryOnTransient5xxEventuallySucceeds(): void
    {
        $shipment = $this->newShipment(300);
        $failingGateway = new class (self::CARRIER_CODE) extends FakeCarrierGateway {
            public int $attempts = 0;

            public function createShipment(ShipmentRequest $request): ShipmentResponse
            {
                $this->attempts++;
                if ($this->attempts === 1) {
                    throw TransientHttpException::create(502, 'upstream bad gateway');
                }
                return new ShipmentResponse(
                    carrierTrackingId: 'RETRY-OK',
                    labelUrl: null,
                    status: ShipmentInterface::STATUS_READY_FOR_PICKUP,
                );
            }
        };
        $orchestrator = $this->orchestratorWith(factoryInstance: $shipment, gateway: $failingGateway);

        $this->registry->method('has')->with(self::CARRIER_CODE)->willReturn(true);
        $this->registry->method('get')->with(self::CARRIER_CODE)->willReturn($failingGateway);
        $this->idempotencyStore->method('findExisting')->willReturn(null);
        $this->rateLimiter->method('acquire')->willReturn(true);
        $this->repository->method('save')->willReturn($shipment);

        $result = $orchestrator->dispatch($this->newRequest());

        self::assertSame(2, $failingGateway->attempts, 'Expected exactly one retry after a 502.');
        self::assertSame('RETRY-OK', $result->getCarrierTrackingId());
        $this->assertEventFired('shubo_shipping_shipment_dispatched');
    }

    public function testHardFailAfterRetryExhaustionPublishesToDlqAndFiresDispatchFailed(): void
    {
        $shipment = $this->newShipment(400);
        $exhaustGateway = new class (self::CARRIER_CODE) extends FakeCarrierGateway {
            public int $attempts = 0;

            public function createShipment(ShipmentRequest $request): ShipmentResponse
            {
                $this->attempts++;
                throw TransientHttpException::create(502, 'upstream still bad');
            }
        };
        $orchestrator = $this->orchestratorWith(factoryInstance: $shipment, gateway: $exhaustGateway);

        $this->registry->method('has')->with(self::CARRIER_CODE)->willReturn(true);
        $this->registry->method('get')->with(self::CARRIER_CODE)->willReturn($exhaustGateway);
        $this->idempotencyStore->method('findExisting')->willReturn(null);
        $this->rateLimiter->method('acquire')->willReturn(true);
        $this->repository->method('save')->willReturn($shipment);

        $this->deadLetterPublisher->expects(self::once())->method('publish')
            ->with(400, self::CARRIER_CODE, 'createShipment', self::stringContains('upstream still bad'));

        try {
            $orchestrator->dispatch($this->newRequest());
            self::fail('Orchestrator must throw after retry exhaustion.');
        } catch (ShipmentDispatchFailedException) {
            // expected
        }

        self::assertSame(
            RetryPolicy::MAX_ATTEMPTS,
            $exhaustGateway->attempts,
            'Expected exactly MAX_ATTEMPTS carrier calls before give-up.',
        );
        self::assertSame(ShipmentInterface::STATUS_FAILED, $shipment->getStatus());
        self::assertNotNull($shipment->getFailedAt());
        self::assertNotNull($shipment->getFailureReason());

        $this->assertEventFired('shubo_shipping_shipment_created');
        $this->assertEventFired('shubo_shipping_dispatch_failed');
        $this->assertEventNotFired('shubo_shipping_shipment_dispatched');
    }

    public function testRateLimiterBackpressureTriggersRetry(): void
    {
        $shipment = $this->newShipment(500);
        $orchestrator = $this->orchestratorWith(factoryInstance: $shipment, gateway: $this->gateway);

        $this->registry->method('has')->with(self::CARRIER_CODE)->willReturn(true);
        $this->registry->method('get')->with(self::CARRIER_CODE)->willReturn($this->gateway);
        $this->idempotencyStore->method('findExisting')->willReturn(null);
        $this->repository->method('save')->willReturn($shipment);

        // First acquire returns false -> RetryPolicy sees a RateLimitedException
        // and backs off. Second acquire returns true and the carrier succeeds.
        $acquireCalls = 0;
        $this->rateLimiter->method('acquire')->willReturnCallback(
            function () use (&$acquireCalls): bool {
                $acquireCalls++;
                return $acquireCalls >= 2;
            },
        );

        $this->gateway->setNextResponse('createShipment', new ShipmentResponse(
            carrierTrackingId: 'RL-OK',
            labelUrl: null,
            status: ShipmentInterface::STATUS_READY_FOR_PICKUP,
        ));

        $result = $orchestrator->dispatch($this->newRequest());

        self::assertGreaterThanOrEqual(2, $acquireCalls);
        self::assertSame('RL-OK', $result->getCarrierTrackingId());
        self::assertSame(ShipmentInterface::STATUS_READY_FOR_PICKUP, $result->getStatus());
    }

    public function testRateLimitedExceptionIsClassifiedAsRetryable(): void
    {
        // Sanity-check that RateLimitedException with no Retry-After is
        // retryable. The orchestrator exercises the same wiring above; this
        // assertion on the policy keeps the relationship explicit.
        $sleeper = $this->createMock(Sleeper::class);
        $policy = new RetryPolicy($sleeper, $this->createMock(StructuredLogger::class));
        $calls = 0;
        $result = $policy->execute(
            self::CARRIER_CODE,
            'createShipment',
            function () use (&$calls): string {
                $calls++;
                if ($calls === 1) {
                    throw RateLimitedException::create(null, 'tokens gone');
                }
                return 'ok';
            },
        );
        self::assertSame('ok', $result);
        self::assertSame(2, $calls);
    }

    // -- helpers ------------------------------------------------------------

    /**
     * Build an orchestrator with a fake ShipmentFactory returning the supplied
     * pre-built shipment. Magento's `*Factory` classes are generated at runtime
     * and do not exist during unit tests; we substitute an anonymous class
     * with the right `create()` surface.
     *
     * When `$factoryInstance` is null, the factory's `create()` is still
     * defined but will only be invoked by paths that explicitly opt in
     * (the test asserts on `shipmentFactory->create()` not being called in
     * the idempotent-hit path).
     */
    private function orchestratorWith(
        ?Shipment $factoryInstance,
        CarrierGatewayInterface $gateway,
    ): ShipmentOrchestrator {
        // Subclass the real ShipmentFactory (now a hand-written class in
        // Model/Data/ShipmentFactory.php) to override create() with a
        // caller-supplied instance. We bypass the parent constructor because
        // we don't need an ObjectManager for this path.
        $factory = new class ($factoryInstance) extends \Shubo\ShippingCore\Model\Data\ShipmentFactory {
            public function __construct(private readonly ?Shipment $instance)
            {
                // skip parent::__construct on purpose
            }

            public function create(array $data = []): Shipment
            {
                if ($this->instance === null) {
                    throw new \LogicException(
                        'FakeShipmentFactory::create called when no instance was configured.',
                    );
                }
                return $this->instance;
            }
        };

        return new ShipmentOrchestrator(
            $this->registry,
            $this->repository,
            $factory,
            $this->idempotencyStore,
            $this->circuitBreaker,
            $this->retryPolicy,
            $this->rateLimiter,
            $this->deadLetterPublisher,
            $this->eventManager,
            $this->logger,
        );
    }

    private function newRequest(): ShipmentRequest
    {
        $addr = new ContactAddress(
            name: 'Test Recipient',
            phone: '+995599000000',
            email: 'recipient@example.com',
            country: 'GE',
            subdivision: 'GE-TB',
            city: 'Tbilisi',
            district: null,
            street: '1 Rustaveli Ave',
            building: null,
            floor: null,
            apartment: null,
            postcode: '0108',
            latitude: null,
            longitude: null,
            instructions: null,
        );
        $parcel = new ParcelSpec(
            weightGrams: 500,
            lengthMm: 100,
            widthMm: 100,
            heightMm: 100,
            declaredValueCents: 12_34,
        );
        return new ShipmentRequest(
            orderId: self::ORDER_ID,
            merchantId: self::MERCHANT_ID,
            clientTrackingCode: self::CLIENT_CODE,
            origin: $addr,
            destination: $addr,
            parcel: $parcel,
            codEnabled: false,
            codAmountCents: 0,
            preferredCarrierCode: self::CARRIER_CODE,
        );
    }

    /**
     * Build a Shipment without touching AbstractModel's `_construct` (which
     * requires a live ObjectManager to look up the resource model). We bypass
     * the constructor with Reflection and set the few fields the orchestrator
     * reads.
     */
    private function newShipment(int $id): Shipment
    {
        $ref = new \ReflectionClass(Shipment::class);
        /** @var Shipment $shipment */
        $shipment = $ref->newInstanceWithoutConstructor();

        // AbstractModel uses _data as the storage, accessed via setData()/getData().
        // Manually prime it so setData/getData works on a constructor-less instance.
        $dataProp = $ref->getParentClass()?->getProperty('_data');
        $dataProp?->setValue($shipment, []);

        $shipment->setData(ShipmentInterface::FIELD_SHIPMENT_ID, $id);
        $shipment->setCarrierCode(self::CARRIER_CODE);
        $shipment->setClientTrackingCode(self::CLIENT_CODE);
        $shipment->setOrderId(self::ORDER_ID);
        $shipment->setMerchantId(self::MERCHANT_ID);
        $shipment->setStatus(ShipmentInterface::STATUS_PENDING);
        $shipment->setParcelWeightGrams(500);
        $shipment->setParcelValueCents(0);
        $shipment->setCodEnabled(false);
        $shipment->setCodAmountCents(0);
        return $shipment;
    }

    private function assertEventFired(string $name): void
    {
        foreach ($this->capturedEvents as $event) {
            if ($event['name'] === $name) {
                return;
            }
        }
        self::fail(sprintf(
            'Expected event "%s" to be dispatched. Got: [%s]',
            $name,
            implode(', ', array_column($this->capturedEvents, 'name')),
        ));
    }

    private function assertEventNotFired(string $name): void
    {
        foreach ($this->capturedEvents as $event) {
            if ($event['name'] === $name) {
                self::fail(sprintf('Did not expect event "%s" to be dispatched.', $name));
            }
        }
    }
}
