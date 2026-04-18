<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Test\Unit\Model\Carrier;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shubo\ShippingCore\Api\CarrierGatewayInterface;
use Shubo\ShippingCore\Api\CarrierRegistryInterface;
use Shubo\ShippingCore\Api\CircuitBreakerInterface;
use Shubo\ShippingCore\Api\Data\CircuitBreakerStateInterface;
use Shubo\ShippingCore\Api\Data\Dto\ContactAddress;
use Shubo\ShippingCore\Api\Data\Dto\ParcelSpec;
use Shubo\ShippingCore\Api\Data\Dto\QuoteRequest;
use Shubo\ShippingCore\Api\Data\Dto\QuoteResponse;
use Shubo\ShippingCore\Api\Data\Dto\RateOption;
use Shubo\ShippingCore\Model\Carrier\RateQuoteService;
use Shubo\ShippingCore\Model\Logging\StructuredLogger;

/**
 * Unit tests for {@see RateQuoteService}.
 *
 * Covers the demo-scoped sequential aggregator:
 *   - Single-carrier success returns one RateOption.
 *   - Carrier throws: exception is swallowed, warning logged, loop continues.
 *   - Circuit breaker open: carrier silently skipped, gateway NOT called.
 *   - All carriers fail: empty array returned (never blocks checkout).
 *   - Multi-carrier aggregation: options from all healthy carriers returned.
 *   - Half-open breaker allows the trial call through.
 *   - No registered carriers: empty array.
 */
class RateQuoteServiceTest extends TestCase
{
    /** @var CarrierRegistryInterface&MockObject */
    private CarrierRegistryInterface $registry;

    /** @var CircuitBreakerInterface&MockObject */
    private CircuitBreakerInterface $circuitBreaker;

    /** @var StructuredLogger&MockObject */
    private StructuredLogger $logger;

    private RateQuoteService $service;

    protected function setUp(): void
    {
        $this->registry = $this->createMock(CarrierRegistryInterface::class);
        $this->circuitBreaker = $this->createMock(CircuitBreakerInterface::class);
        $this->logger = $this->createMock(StructuredLogger::class);

        $this->service = new RateQuoteService(
            $this->registry,
            $this->circuitBreaker,
            $this->logger,
        );
    }

    public function testSingleCarrierReturnsRate(): void
    {
        $option = new RateOption(
            carrierCode: 'shuboflat',
            methodCode: 'standard',
            priceCents: 500,
            etaDays: 2,
            serviceLevel: 'standard',
            rationale: 'chosen',
        );

        $gateway = $this->createMock(CarrierGatewayInterface::class);
        $gateway->expects(self::once())
            ->method('quote')
            ->willReturn(new QuoteResponse([$option], []));

        $this->registry->method('enabled')->willReturn(['shuboflat' => $gateway]);
        $this->circuitBreaker->method('stateOf')
            ->with('shuboflat')
            ->willReturn(CircuitBreakerStateInterface::STATE_CLOSED);

        $result = $this->service->quote($this->newRequest());

        self::assertCount(1, $result);
        self::assertSame($option, $result[0]);
    }

    public function testCarrierThrowsIsSkippedAndLogged(): void
    {
        $gateway = $this->createMock(CarrierGatewayInterface::class);
        $gateway->method('quote')->willThrowException(new \RuntimeException('carrier exploded'));

        $this->registry->method('enabled')->willReturn(['shuboflat' => $gateway]);
        $this->circuitBreaker->method('stateOf')
            ->with('shuboflat')
            ->willReturn(CircuitBreakerStateInterface::STATE_CLOSED);

        $this->logger->expects(self::once())
            ->method('logDispatchFailed')
            ->with('shuboflat', 'quote', self::isInstanceOf(\RuntimeException::class));

        $result = $this->service->quote($this->newRequest());

        self::assertSame([], $result);
    }

    public function testCircuitOpenCarrierIsSkippedWithoutCallingGateway(): void
    {
        $gateway = $this->createMock(CarrierGatewayInterface::class);
        $gateway->expects(self::never())->method('quote');

        $this->registry->method('enabled')->willReturn(['shuboflat' => $gateway]);
        $this->circuitBreaker->method('stateOf')
            ->with('shuboflat')
            ->willReturn(CircuitBreakerStateInterface::STATE_OPEN);

        $result = $this->service->quote($this->newRequest());

        self::assertSame([], $result);
    }

    public function testAllCarriersFailReturnsEmptyArray(): void
    {
        $gateway1 = $this->createMock(CarrierGatewayInterface::class);
        $gateway1->method('quote')->willThrowException(new \RuntimeException('boom 1'));

        $gateway2 = $this->createMock(CarrierGatewayInterface::class);
        $gateway2->method('quote')->willThrowException(new \RuntimeException('boom 2'));

        $this->registry->method('enabled')->willReturn([
            'a' => $gateway1,
            'b' => $gateway2,
        ]);
        $this->circuitBreaker->method('stateOf')
            ->willReturn(CircuitBreakerStateInterface::STATE_CLOSED);

        $this->logger->expects(self::exactly(2))->method('logDispatchFailed');

        $result = $this->service->quote($this->newRequest());

        self::assertSame([], $result);
    }

    public function testMultiCarrierAggregatesOptions(): void
    {
        $optA = new RateOption(
            carrierCode: 'a',
            methodCode: 'standard',
            priceCents: 500,
            etaDays: 2,
            serviceLevel: 'standard',
            rationale: 'chosen',
        );
        $optB = new RateOption(
            carrierCode: 'b',
            methodCode: 'express',
            priceCents: 1200,
            etaDays: 1,
            serviceLevel: 'express',
            rationale: 'chosen',
        );

        $gatewayA = $this->createMock(CarrierGatewayInterface::class);
        $gatewayA->method('quote')->willReturn(new QuoteResponse([$optA], []));
        $gatewayB = $this->createMock(CarrierGatewayInterface::class);
        $gatewayB->method('quote')->willReturn(new QuoteResponse([$optB], []));

        $this->registry->method('enabled')->willReturn([
            'a' => $gatewayA,
            'b' => $gatewayB,
        ]);
        $this->circuitBreaker->method('stateOf')
            ->willReturn(CircuitBreakerStateInterface::STATE_CLOSED);

        $result = $this->service->quote($this->newRequest());

        self::assertCount(2, $result);
        self::assertContains($optA, $result);
        self::assertContains($optB, $result);
    }

    public function testNoCarriersRegisteredReturnsEmptyArray(): void
    {
        $this->registry->method('enabled')->willReturn([]);

        $result = $this->service->quote($this->newRequest());

        self::assertSame([], $result);
    }

    public function testHalfOpenBreakerAllowsQuote(): void
    {
        // A half-open breaker lets the trial call through. Rate quote must
        // not pre-emptively skip half-open carriers; only fully-open ones.
        $option = new RateOption(
            carrierCode: 'shuboflat',
            methodCode: 'standard',
            priceCents: 500,
            etaDays: 2,
            serviceLevel: 'standard',
            rationale: 'chosen',
        );

        $gateway = $this->createMock(CarrierGatewayInterface::class);
        $gateway->expects(self::once())
            ->method('quote')
            ->willReturn(new QuoteResponse([$option], []));

        $this->registry->method('enabled')->willReturn(['shuboflat' => $gateway]);
        $this->circuitBreaker->method('stateOf')
            ->with('shuboflat')
            ->willReturn(CircuitBreakerStateInterface::STATE_HALF_OPEN);

        $result = $this->service->quote($this->newRequest());

        self::assertCount(1, $result);
    }

    private function newRequest(): QuoteRequest
    {
        return new QuoteRequest(
            merchantId: 42,
            origin: $this->emptyAddress(),
            destination: $this->emptyAddress(),
            parcel: new ParcelSpec(
                weightGrams: 1000,
                lengthMm: 0,
                widthMm: 0,
                heightMm: 0,
                declaredValueCents: 5000,
            ),
        );
    }

    private function emptyAddress(): ContactAddress
    {
        return new ContactAddress(
            name: 'Test Name',
            phone: '+995555000000',
            email: null,
            country: 'GE',
            subdivision: 'GE-TB',
            city: 'Tbilisi',
            district: null,
            street: 'Rustaveli 1',
            building: null,
            floor: null,
            apartment: null,
            postcode: '0105',
            latitude: null,
            longitude: null,
            instructions: null,
        );
    }
}
