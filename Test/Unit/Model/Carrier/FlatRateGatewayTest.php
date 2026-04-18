<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Test\Unit\Model\Carrier;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shubo\ShippingCore\Api\Data\Dto\ContactAddress;
use Shubo\ShippingCore\Api\Data\Dto\ParcelSpec;
use Shubo\ShippingCore\Api\Data\Dto\QuoteRequest;
use Shubo\ShippingCore\Api\Data\Dto\ShipmentRequest;
use Shubo\ShippingCore\Api\Data\ShipmentInterface;
use Shubo\ShippingCore\Model\Carrier\FlatRateGateway;

/**
 * Unit tests for {@see FlatRateGateway}. Covers:
 *   - quote() reads the configured price, defaults to 5.00 GEL.
 *   - quote() always emits carrierCode='shuboflat' methodCode='standard'.
 *   - createShipment() returns a synthetic tracking id prefixed
 *     "SHUBO-FLAT-" with pending status.
 *   - createShipment() echoes client_tracking_code, order_id, merchant_id
 *     in the raw payload for the orchestrator's audit trail.
 *   - getShipmentStatus() returns STATUS_PENDING.
 *   - cancelShipment() is idempotent and reports success=true.
 *   - fetchLabel() returns a PDF-headed body.
 */
class FlatRateGatewayTest extends TestCase
{
    /** @var ScopeConfigInterface&MockObject */
    private ScopeConfigInterface $scopeConfig;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
    }

    public function testCodeIsShuboflat(): void
    {
        $gateway = new FlatRateGateway($this->scopeConfig);

        self::assertSame('shuboflat', $gateway->code());
    }

    public function testQuoteReturnsConfiguredPriceInCents(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('carriers/shuboflat/price', ScopeInterface::SCOPE_STORE)
            ->willReturn('7.50');

        $gateway = new FlatRateGateway($this->scopeConfig);

        $response = $gateway->quote($this->newQuoteRequest());

        self::assertCount(1, $response->options);
        $option = $response->options[0];
        self::assertSame('shuboflat', $option->carrierCode);
        self::assertSame('standard', $option->methodCode);
        self::assertSame(750, $option->priceCents);
        self::assertSame(2, $option->etaDays);
    }

    public function testQuoteDefaultsTo500CentsWhenConfigMissing(): void
    {
        $this->scopeConfig->method('getValue')
            ->willReturn(null);

        $gateway = new FlatRateGateway($this->scopeConfig);

        $response = $gateway->quote($this->newQuoteRequest());

        self::assertSame(500, $response->options[0]->priceCents);
    }

    public function testQuoteDefaultsTo500CentsWhenConfigEmptyString(): void
    {
        $this->scopeConfig->method('getValue')
            ->willReturn('');

        $gateway = new FlatRateGateway($this->scopeConfig);

        $response = $gateway->quote($this->newQuoteRequest());

        self::assertSame(500, $response->options[0]->priceCents);
    }

    public function testCreateShipmentReturnsShuboFlatPrefixedTrackingId(): void
    {
        $gateway = new FlatRateGateway(
            $this->scopeConfig,
            static fn (): string => 'fixed-id-1234',
        );

        $response = $gateway->createShipment($this->newShipmentRequest());

        self::assertSame('SHUBO-FLAT-fixed-id-1234', $response->carrierTrackingId);
        self::assertNull($response->labelUrl);
        self::assertSame(ShipmentInterface::STATUS_PENDING, $response->status);
    }

    public function testCreateShipmentEchoesMetadataInRawPayload(): void
    {
        $gateway = new FlatRateGateway(
            $this->scopeConfig,
            static fn (): string => 'fixed-id',
        );
        $request = $this->newShipmentRequest();

        $response = $gateway->createShipment($request);

        self::assertSame('mshp_42', $response->raw['client_tracking_code']);
        self::assertSame(42, $response->raw['order_id']);
        self::assertSame(7, $response->raw['merchant_id']);
    }

    public function testDefaultIdGeneratorProducesUniqueIds(): void
    {
        $gateway = new FlatRateGateway($this->scopeConfig);

        $a = $gateway->createShipment($this->newShipmentRequest());
        $b = $gateway->createShipment($this->newShipmentRequest());

        self::assertNotSame($a->carrierTrackingId, $b->carrierTrackingId);
        self::assertStringStartsWith('SHUBO-FLAT-', $a->carrierTrackingId);
        self::assertStringStartsWith('SHUBO-FLAT-', $b->carrierTrackingId);
    }

    public function testGetShipmentStatusReturnsPending(): void
    {
        $gateway = new FlatRateGateway($this->scopeConfig);

        $status = $gateway->getShipmentStatus('SHUBO-FLAT-abc');

        self::assertSame(ShipmentInterface::STATUS_PENDING, $status->normalizedStatus);
        self::assertSame('PENDING', $status->carrierStatusRaw);
        self::assertNull($status->occurredAt);
        self::assertNull($status->codCollectedAt);
    }

    public function testCancelShipmentAlwaysReportsSuccess(): void
    {
        $gateway = new FlatRateGateway($this->scopeConfig);

        $response = $gateway->cancelShipment('SHUBO-FLAT-abc', 'customer request');

        self::assertTrue($response->success);
        self::assertNull($response->carrierMessage);
        self::assertSame('SHUBO-FLAT-abc', $response->raw['carrier_tracking_id']);
        self::assertSame('customer request', $response->raw['reason']);
    }

    public function testFetchLabelReturnsPdfHeader(): void
    {
        $gateway = new FlatRateGateway($this->scopeConfig);

        $label = $gateway->fetchLabel('SHUBO-FLAT-abc');

        self::assertStringStartsWith('%PDF-', $label->pdfBytes);
        self::assertSame('application/pdf', $label->contentType);
        self::assertSame('label-SHUBO-FLAT-abc.pdf', $label->filename);
    }

    public function testListCitiesAndPudosReturnEmptyArrays(): void
    {
        $gateway = new FlatRateGateway($this->scopeConfig);

        self::assertSame([], $gateway->listCities());
        self::assertSame([], $gateway->listPudos());
        self::assertSame([], $gateway->listPudos('anything'));
    }

    private function newQuoteRequest(): QuoteRequest
    {
        return new QuoteRequest(
            merchantId: 7,
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

    private function newShipmentRequest(): ShipmentRequest
    {
        return new ShipmentRequest(
            orderId: 42,
            merchantId: 7,
            clientTrackingCode: 'mshp_42',
            origin: $this->emptyAddress(),
            destination: $this->emptyAddress(),
            parcel: new ParcelSpec(
                weightGrams: 1000,
                lengthMm: 0,
                widthMm: 0,
                heightMm: 0,
                declaredValueCents: 5000,
            ),
            codEnabled: false,
            codAmountCents: 0,
            preferredCarrierCode: 'shuboflat',
        );
    }

    private function emptyAddress(): ContactAddress
    {
        return new ContactAddress(
            name: 'Test',
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
