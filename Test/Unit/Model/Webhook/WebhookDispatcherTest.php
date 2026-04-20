<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Test\Unit\Model\Webhook;

use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shubo\ShippingCore\Api\CarrierRegistryInterface;
use Shubo\ShippingCore\Api\Data\Dto\DispatchResult;
use Shubo\ShippingCore\Api\Data\Dto\WebhookResult;
use Shubo\ShippingCore\Api\Data\ShipmentEventInterface;
use Shubo\ShippingCore\Api\Data\ShipmentEventInterfaceFactory;
use Shubo\ShippingCore\Api\Data\ShipmentInterface;
use Shubo\ShippingCore\Api\ShipmentEventRepositoryInterface;
use Shubo\ShippingCore\Api\ShipmentRepositoryInterface;
use Shubo\ShippingCore\Api\WebhookHandlerInterface;
use Shubo\ShippingCore\Model\Logging\StructuredLogger;
use Shubo\ShippingCore\Model\Webhook\WebhookDispatcher;
use Shubo\ShippingCore\Model\Webhook\WebhookIdempotencyGuard;

/**
 * Unit tests for {@see WebhookDispatcher}. Exercises design-doc §11.2
 * pseudocode at every branch: unknown carrier, handler rejection, handler-
 * reported duplicate, DB-backed duplicate, accepted-with-status-change,
 * accepted-with-no-change, missing-shipment, and handler exceptions.
 */
class WebhookDispatcherTest extends TestCase
{
    private const CARRIER_CODE = 'wolt';

    /** @var CarrierRegistryInterface&MockObject */
    private CarrierRegistryInterface $registry;

    /** @var ShipmentRepositoryInterface&MockObject */
    private ShipmentRepositoryInterface $shipmentRepository;

    /** @var ShipmentEventRepositoryInterface&MockObject */
    private ShipmentEventRepositoryInterface $eventRepository;

    /** @var EventManagerInterface&MockObject */
    private EventManagerInterface $eventManager;

    /** @var StructuredLogger&MockObject */
    private StructuredLogger $logger;

    /** @var ShipmentEventInterfaceFactory&MockObject */
    private ShipmentEventInterfaceFactory $eventFactory;

    /** @var DateTime&MockObject */
    private DateTime $dateTime;

    /** @var WebhookHandlerInterface&MockObject */
    private WebhookHandlerInterface $handler;

    private WebhookIdempotencyGuard $guard;

    /** @var list<ShipmentEventInterface> */
    private array $savedEvents = [];

    /** @var list<ShipmentInterface> */
    private array $savedShipments = [];

    /** @var list<array{name:string, data:array<string,mixed>}> */
    private array $capturedEvents = [];

    protected function setUp(): void
    {
        $this->registry = $this->createMock(CarrierRegistryInterface::class);
        $this->shipmentRepository = $this->createMock(ShipmentRepositoryInterface::class);
        $this->eventRepository = $this->createMock(ShipmentEventRepositoryInterface::class);
        $this->eventManager = $this->createMock(EventManagerInterface::class);
        $this->logger = $this->createMock(StructuredLogger::class);
        $this->eventFactory = $this->createMock(ShipmentEventInterfaceFactory::class);
        $this->dateTime = $this->createMock(DateTime::class);
        $this->handler = $this->createMock(WebhookHandlerInterface::class);

        $this->guard = new WebhookIdempotencyGuard($this->eventRepository);

        $this->eventFactory->method('create')->willReturnCallback(
            fn (): ShipmentEventInterface => new \Shubo\ShippingCore\Test\Unit\Fake\InMemoryShipmentEvent(),
        );

        $this->savedEvents = [];
        $this->eventRepository->method('save')->willReturnCallback(
            function (ShipmentEventInterface $event): ShipmentEventInterface {
                $this->savedEvents[] = $event;
                return $event;
            },
        );

        $this->savedShipments = [];
        $this->shipmentRepository->method('save')->willReturnCallback(
            function (ShipmentInterface $s): ShipmentInterface {
                $this->savedShipments[] = $s;
                return $s;
            },
        );

        $this->capturedEvents = [];
        $this->eventManager->method('dispatch')->willReturnCallback(
            function (string $name, array $data = []): void {
                $this->capturedEvents[] = ['name' => $name, 'data' => $data];
            },
        );

        $this->dateTime->method('gmtTimestamp')->willReturn(1_704_110_400);
    }

    public function testDispatchUnknownCarrierReturnsUnknownCarrier(): void
    {
        $dispatcher = $this->dispatcher(handlers: []);

        $this->shipmentRepository->expects(self::never())->method('getByCarrierTrackingId');
        $this->eventRepository->expects(self::never())->method('save');

        $result = $dispatcher->dispatch('unknown', '{}', []);

        self::assertSame(DispatchResult::STATUS_UNKNOWN_CARRIER, $result->status);
        self::assertEmpty($this->savedEvents);
        self::assertEmpty($this->savedShipments);
    }

    public function testDispatchRejectedByHandlerReturnsRejected(): void
    {
        $this->handler->method('handle')->willReturn(new WebhookResult(
            status: WebhookResult::STATUS_REJECTED,
            carrierTrackingId: null,
            normalizedStatus: null,
            externalEventId: null,
            occurredAt: null,
            rawPayload: '',
            rejectionReason: 'signature_invalid',
        ));

        $dispatcher = $this->dispatcher(handlers: [self::CARRIER_CODE => $this->handler]);

        $this->shipmentRepository->expects(self::never())->method('getByCarrierTrackingId');
        $this->eventRepository->expects(self::never())->method('save');

        $result = $dispatcher->dispatch(self::CARRIER_CODE, '{}', []);

        self::assertSame(DispatchResult::STATUS_REJECTED, $result->status);
        self::assertSame('signature_invalid', $result->reason);
    }

    public function testDispatchHandlerReportedDuplicateShortCircuitsBeforeDbCheck(): void
    {
        $this->handler->method('handle')->willReturn(new WebhookResult(
            status: WebhookResult::STATUS_DUPLICATE,
            carrierTrackingId: 'TRK-42',
            normalizedStatus: null,
            externalEventId: 'evt-42',
            occurredAt: null,
            rawPayload: '{}',
        ));

        $this->eventRepository->expects(self::never())->method('existsByExternalEventId');
        $this->shipmentRepository->expects(self::never())->method('getByCarrierTrackingId');
        $this->eventRepository->expects(self::never())->method('save');

        $dispatcher = $this->dispatcher(handlers: [self::CARRIER_CODE => $this->handler]);
        $result = $dispatcher->dispatch(self::CARRIER_CODE, '{}', []);

        self::assertSame(DispatchResult::STATUS_DUPLICATE, $result->status);
    }

    public function testDispatchDbIdempotencyCheckFindsDuplicate(): void
    {
        $this->handler->method('handle')->willReturn(new WebhookResult(
            status: WebhookResult::STATUS_ACCEPTED,
            carrierTrackingId: 'TRK-42',
            normalizedStatus: ShipmentInterface::STATUS_DELIVERED,
            externalEventId: 'evt-42',
            occurredAt: '2024-01-01 12:00:00',
            rawPayload: '{"x":1}',
        ));

        $this->eventRepository->method('existsByExternalEventId')
            ->with(self::CARRIER_CODE, 'evt-42')
            ->willReturn(true);

        $this->shipmentRepository->expects(self::never())->method('getByCarrierTrackingId');
        $this->eventRepository->expects(self::never())->method('save');

        $dispatcher = $this->dispatcher(handlers: [self::CARRIER_CODE => $this->handler]);
        $result = $dispatcher->dispatch(self::CARRIER_CODE, '{"x":1}', []);

        self::assertSame(DispatchResult::STATUS_DUPLICATE, $result->status);
    }

    public function testDispatchAcceptedWithStatusChangeSavesEventAndShipmentAndFiresEvent(): void
    {
        $shipment = $this->newShipment(status: ShipmentInterface::STATUS_IN_TRANSIT);
        $this->shipmentRepository->method('getByCarrierTrackingId')
            ->with(self::CARRIER_CODE, 'TRK-42')
            ->willReturn($shipment);

        $this->eventRepository->method('existsByExternalEventId')->willReturn(false);

        $this->handler->method('handle')->willReturn(new WebhookResult(
            status: WebhookResult::STATUS_ACCEPTED,
            carrierTrackingId: 'TRK-42',
            normalizedStatus: ShipmentInterface::STATUS_DELIVERED,
            externalEventId: 'evt-42',
            occurredAt: '2024-01-01 12:00:00',
            rawPayload: '{"carrier_event":"delivered"}',
        ));

        $dispatcher = $this->dispatcher(handlers: [self::CARRIER_CODE => $this->handler]);
        $result = $dispatcher->dispatch(self::CARRIER_CODE, '{"carrier_event":"delivered"}', []);

        self::assertSame(DispatchResult::STATUS_ACCEPTED, $result->status);
        self::assertSame('evt-42', $result->externalEventId);
        self::assertCount(1, $this->savedEvents);
        self::assertSame(
            ShipmentEventInterface::EVENT_TYPE_WEBHOOK_RECEIVED,
            $this->savedEvents[0]->getEventType(),
        );
        self::assertSame(ShipmentEventInterface::SOURCE_WEBHOOK, $this->savedEvents[0]->getSource());
        self::assertSame('evt-42', $this->savedEvents[0]->getExternalEventId());
        self::assertSame(ShipmentInterface::STATUS_DELIVERED, $this->savedEvents[0]->getNormalizedStatus());
        self::assertSame(['carrier_event' => 'delivered'], $this->savedEvents[0]->getRawPayload());

        self::assertSame(ShipmentInterface::STATUS_DELIVERED, $shipment->getStatus());
        self::assertNotEmpty($this->savedShipments);

        $this->assertEventFired('shubo_shipping_shipment_status_changed');
    }

    public function testDispatchAcceptedWithSameStatusIsANoopOnTheShipment(): void
    {
        $shipment = $this->newShipment(status: ShipmentInterface::STATUS_IN_TRANSIT);
        $this->shipmentRepository->method('getByCarrierTrackingId')->willReturn($shipment);
        $this->eventRepository->method('existsByExternalEventId')->willReturn(false);

        $this->handler->method('handle')->willReturn(new WebhookResult(
            status: WebhookResult::STATUS_ACCEPTED,
            carrierTrackingId: 'TRK-42',
            normalizedStatus: ShipmentInterface::STATUS_IN_TRANSIT,
            externalEventId: 'evt-same',
            occurredAt: null,
            rawPayload: '{"tick":1}',
        ));

        $dispatcher = $this->dispatcher(handlers: [self::CARRIER_CODE => $this->handler]);
        $result = $dispatcher->dispatch(self::CARRIER_CODE, '{"tick":1}', []);

        self::assertSame(DispatchResult::STATUS_ACCEPTED, $result->status);
        self::assertCount(1, $this->savedEvents);
        self::assertEmpty(
            $this->savedShipments,
            'Shipment must not be saved when status is unchanged.',
        );
        $this->assertEventNotFired('shubo_shipping_shipment_status_changed');
    }

    public function testDispatchAcceptedWithMissingShipmentReturnsRejected(): void
    {
        $this->eventRepository->method('existsByExternalEventId')->willReturn(false);
        $this->shipmentRepository->method('getByCarrierTrackingId')
            ->willThrowException(new NoSuchEntityException(__('not found')));

        $this->handler->method('handle')->willReturn(new WebhookResult(
            status: WebhookResult::STATUS_ACCEPTED,
            carrierTrackingId: 'TRK-ghost',
            normalizedStatus: ShipmentInterface::STATUS_DELIVERED,
            externalEventId: 'evt-ghost',
            occurredAt: null,
            rawPayload: '{}',
        ));

        $this->eventRepository->expects(self::never())->method('save');

        $dispatcher = $this->dispatcher(handlers: [self::CARRIER_CODE => $this->handler]);
        $result = $dispatcher->dispatch(self::CARRIER_CODE, '{}', []);

        self::assertSame(DispatchResult::STATUS_REJECTED, $result->status);
        self::assertSame('shipment_not_found', $result->reason);
    }

    public function testConcurrentDuplicateRaceIsResolvedAs200Duplicate(): void
    {
        // Scenario: two concurrent webhook requests arrive with the same
        // (carrier_code, external_event_id). Both pass the pre-save
        // isDuplicate() probe (both see the row absent). The first
        // INSERT wins; the second hits the unique-index constraint and
        // surfaces as CouldNotSaveException. The dispatcher MUST re-probe
        // and answer 200 DUPLICATE — not 500 — so the carrier does not
        // retry a payload that is already recorded.
        $shipment = $this->newShipment(status: ShipmentInterface::STATUS_IN_TRANSIT);

        $this->handler->method('handle')->willReturn(new WebhookResult(
            status: WebhookResult::STATUS_ACCEPTED,
            carrierTrackingId: 'TRK-race',
            normalizedStatus: ShipmentInterface::STATUS_DELIVERED,
            externalEventId: 'evt-race',
            occurredAt: null,
            rawPayload: '{"race":1}',
        ));

        // Use a fresh repository mock for this test so we can sequence
        // both save() and existsByExternalEventId() deterministically.
        $raceEventRepo = $this->createMock(ShipmentEventRepositoryInterface::class);
        $existsCalls = 0;
        $raceEventRepo->method('existsByExternalEventId')
            ->willReturnCallback(function (string $carrier, string $eventId) use (&$existsCalls): bool {
                $existsCalls++;
                self::assertSame(self::CARRIER_CODE, $carrier);
                self::assertSame('evt-race', $eventId);
                // Pre-save probe: false. Post-catch re-probe: true.
                return $existsCalls > 1;
            });
        $raceEventRepo->expects(self::once())
            ->method('save')
            ->willThrowException(new CouldNotSaveException(new Phrase('unique key violation')));

        $raceShipmentRepo = $this->createMock(ShipmentRepositoryInterface::class);
        $raceShipmentRepo->method('getByCarrierTrackingId')
            ->with(self::CARRIER_CODE, 'TRK-race')
            ->willReturn($shipment);
        $raceShipmentRepo->expects(self::never())->method('save');

        $raceEventManager = $this->createMock(EventManagerInterface::class);
        $raceEventManager->expects(self::never())->method('dispatch');

        $raceLogger = $this->createMock(StructuredLogger::class);
        $sawRaceResolvedLog = false;
        $raceLogger->method('logWebhook')->willReturnCallback(
            function (string $event, array $context = []) use (&$sawRaceResolvedLog): void {
                if ($event === 'webhook_duplicate_race_resolved') {
                    $sawRaceResolvedLog = true;
                    self::assertSame(self::CARRIER_CODE, $context['carrier_code'] ?? null);
                    self::assertSame('evt-race', $context['external_event_id'] ?? null);
                }
            },
        );

        $raceGuard = new WebhookIdempotencyGuard($raceEventRepo);

        $dispatcher = new WebhookDispatcher(
            $this->registry,
            $raceShipmentRepo,
            $raceEventRepo,
            $raceGuard,
            $raceEventManager,
            $raceLogger,
            $this->eventFactory,
            $this->dateTime,
            [self::CARRIER_CODE => $this->handler],
        );

        $result = $dispatcher->dispatch(self::CARRIER_CODE, '{"race":1}', []);

        self::assertSame(DispatchResult::STATUS_DUPLICATE, $result->status);
        self::assertSame('evt-race', $result->externalEventId);
        self::assertSame(
            2,
            $existsCalls,
            'Pre-save probe AND post-catch re-probe must both run so the race is detected.',
        );
        self::assertTrue(
            $sawRaceResolvedLog,
            'The webhook_duplicate_race_resolved log event must be emitted.',
        );
    }

    public function testSaveFailureThatIsNotARaceIsRethrown(): void
    {
        // When save() fails with CouldNotSaveException and the post-catch
        // re-probe still returns false, it is a genuine save failure — must
        // bubble up so the HTTP boundary answers 5xx and the carrier retries.
        $shipment = $this->newShipment(status: ShipmentInterface::STATUS_IN_TRANSIT);

        $this->handler->method('handle')->willReturn(new WebhookResult(
            status: WebhookResult::STATUS_ACCEPTED,
            carrierTrackingId: 'TRK-fail',
            normalizedStatus: ShipmentInterface::STATUS_DELIVERED,
            externalEventId: 'evt-fail',
            occurredAt: null,
            rawPayload: '{"fail":1}',
        ));

        $failEventRepo = $this->createMock(ShipmentEventRepositoryInterface::class);
        $failEventRepo->method('existsByExternalEventId')->willReturn(false);
        $failEventRepo->expects(self::once())
            ->method('save')
            ->willThrowException(new CouldNotSaveException(new Phrase('db down')));

        $failShipmentRepo = $this->createMock(ShipmentRepositoryInterface::class);
        $failShipmentRepo->method('getByCarrierTrackingId')->willReturn($shipment);

        $failGuard = new WebhookIdempotencyGuard($failEventRepo);

        $dispatcher = new WebhookDispatcher(
            $this->registry,
            $failShipmentRepo,
            $failEventRepo,
            $failGuard,
            $this->eventManager,
            $this->logger,
            $this->eventFactory,
            $this->dateTime,
            [self::CARRIER_CODE => $this->handler],
        );

        $this->expectException(CouldNotSaveException::class);
        $dispatcher->dispatch(self::CARRIER_CODE, '{"fail":1}', []);
    }

    public function testDispatchHandlerExceptionIsRethrown(): void
    {
        $this->handler->method('handle')->willThrowException(new \RuntimeException('boom'));

        $dispatcher = $this->dispatcher(handlers: [self::CARRIER_CODE => $this->handler]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');
        $dispatcher->dispatch(self::CARRIER_CODE, '{}', []);
    }

    public function testSynthesisedEventIdWhenHandlerReturnsNull(): void
    {
        $shipment = $this->newShipment(status: ShipmentInterface::STATUS_IN_TRANSIT);
        $this->shipmentRepository->method('getByCarrierTrackingId')->willReturn($shipment);
        $this->eventRepository->method('existsByExternalEventId')->willReturn(false);

        $rawBody = '{"unknown_id":true}';
        $this->handler->method('handle')->willReturn(new WebhookResult(
            status: WebhookResult::STATUS_ACCEPTED,
            carrierTrackingId: 'TRK-42',
            normalizedStatus: ShipmentInterface::STATUS_DELIVERED,
            externalEventId: null,
            occurredAt: null,
            rawPayload: $rawBody,
        ));

        $dispatcher = $this->dispatcher(handlers: [self::CARRIER_CODE => $this->handler]);
        $dispatcher->dispatch(self::CARRIER_CODE, $rawBody, []);

        $expected = 'sha256:' . hash('sha256', $rawBody);
        self::assertCount(1, $this->savedEvents);
        self::assertSame($expected, $this->savedEvents[0]->getExternalEventId());
    }

    public function testDispatchFallsBackToRawStringWhenPayloadIsNotJson(): void
    {
        $shipment = $this->newShipment(status: ShipmentInterface::STATUS_IN_TRANSIT);
        $this->shipmentRepository->method('getByCarrierTrackingId')->willReturn($shipment);
        $this->eventRepository->method('existsByExternalEventId')->willReturn(false);

        $this->handler->method('handle')->willReturn(new WebhookResult(
            status: WebhookResult::STATUS_ACCEPTED,
            carrierTrackingId: 'TRK-42',
            normalizedStatus: ShipmentInterface::STATUS_DELIVERED,
            externalEventId: 'evt-foo',
            occurredAt: null,
            rawPayload: 'not-json',
        ));

        $dispatcher = $this->dispatcher(handlers: [self::CARRIER_CODE => $this->handler]);
        $dispatcher->dispatch(self::CARRIER_CODE, 'not-json', []);

        self::assertCount(1, $this->savedEvents);
        self::assertSame(['raw' => 'not-json'], $this->savedEvents[0]->getRawPayload());
    }

    /**
     * @param array<string, WebhookHandlerInterface> $handlers
     */
    private function dispatcher(array $handlers): WebhookDispatcher
    {
        return new WebhookDispatcher(
            $this->registry,
            $this->shipmentRepository,
            $this->eventRepository,
            $this->guard,
            $this->eventManager,
            $this->logger,
            $this->eventFactory,
            $this->dateTime,
            $handlers,
        );
    }

    private function newShipment(string $status): ShipmentInterface
    {
        $shipment = $this->createMock(ShipmentInterface::class);
        $statusRef = $status;
        $shipment->method('getShipmentId')->willReturn(42);
        $shipment->method('getStatus')->willReturnCallback(static function () use (&$statusRef): string {
            return $statusRef;
        });
        $shipment->method('setStatus')->willReturnCallback(
            static function (string $new) use (&$statusRef, $shipment): ShipmentInterface {
                $statusRef = $new;
                return $shipment;
            },
        );
        return $shipment;
    }

    private function assertEventFired(string $name): void
    {
        foreach ($this->capturedEvents as $event) {
            if ($event['name'] === $name) {
                return;
            }
        }
        self::fail(sprintf('Expected event "%s" to be dispatched.', $name));
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
