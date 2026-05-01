<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Test\Unit\Model\Carrier;

use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shubo\ShippingCore\Api\Data\Dto\ContactAddress;
use Shubo\ShippingCore\Api\Data\Dto\ParcelSpec;
use Shubo\ShippingCore\Api\Data\Dto\QuoteRequest;
use Shubo\ShippingCore\Api\Data\Dto\ShipmentRequest;
use Shubo\ShippingCore\Api\Data\ShipmentInterface;
use Shubo\ShippingCore\Model\Carrier\PickupInStoreGateway;

/**
 * Unit tests for {@see PickupInStoreGateway}. Mirror of
 * {@see FlatRateGatewayTest} with two important differences:
 *   - quote() returns a constant zero-cost option and MUST NOT consult
 *     scope config (we assert ScopeConfig::getValue is never called).
 *   - tracking ids are prefixed `SHUBO-PICKUP-` and the producible
 *     status set is the pickup-skipping subset.
 */
class PickupInStoreGatewayTest extends TestCase
{
    /** @var ScopeConfigInterface&MockObject */
    private ScopeConfigInterface $scopeConfig;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
    }

    public function testCodeIsShubopickup(): void
    {
        $gateway = new PickupInStoreGateway($this->scopeConfig);

        self::assertSame('shubopickup', $gateway->code());
    }

    public function testQuoteReturnsZeroPriceOption(): void
    {
        $gateway = new PickupInStoreGateway($this->scopeConfig);

        $response = $gateway->quote($this->newQuoteRequest());

        self::assertCount(1, $response->options);
        $option = $response->options[0];
        self::assertSame('shubopickup', $option->carrierCode);
        self::assertSame('pickup', $option->methodCode);
        self::assertSame(0, $option->priceCents);
        self::assertSame(1, $option->etaDays);
        self::assertSame('pickup', $option->serviceLevel);
        self::assertSame('in_store_pickup', $option->rationale);
    }

    public function testQuoteIgnoresScopeConfig(): void
    {
        // Strict mock: any getValue() call fails the test. Pickup is
        // hard-coded to zero cost — there is no `carriers/shubopickup/price`
        // knob and a future maintainer must not add one without rewriting
        // this test.
        $this->scopeConfig->expects(self::never())->method('getValue');

        $gateway = new PickupInStoreGateway($this->scopeConfig);

        $response = $gateway->quote($this->newQuoteRequest());

        self::assertSame(0, $response->options[0]->priceCents);
    }

    public function testCreateShipmentReturnsShuboPickupPrefixedTrackingId(): void
    {
        $gateway = new PickupInStoreGateway(
            $this->scopeConfig,
            static fn (): string => 'fixed-id-1234',
        );

        $response = $gateway->createShipment($this->newShipmentRequest());

        self::assertSame('SHUBO-PICKUP-fixed-id-1234', $response->carrierTrackingId);
        self::assertNull($response->labelUrl);
        self::assertSame(ShipmentInterface::STATUS_PENDING, $response->status);
    }

    public function testCreateShipmentEchoesMetadataInRawPayload(): void
    {
        $gateway = new PickupInStoreGateway(
            $this->scopeConfig,
            static fn (): string => 'fixed-id',
        );

        $response = $gateway->createShipment($this->newShipmentRequest());

        self::assertSame('mshp_42', $response->raw['client_tracking_code']);
        self::assertSame(42, $response->raw['order_id']);
        self::assertSame(7, $response->raw['merchant_id']);
    }

    public function testDefaultIdGeneratorProducesUniqueIds(): void
    {
        $gateway = new PickupInStoreGateway($this->scopeConfig);

        $a = $gateway->createShipment($this->newShipmentRequest());
        $b = $gateway->createShipment($this->newShipmentRequest());

        self::assertNotSame($a->carrierTrackingId, $b->carrierTrackingId);
        self::assertStringStartsWith('SHUBO-PICKUP-', $a->carrierTrackingId);
        self::assertStringStartsWith('SHUBO-PICKUP-', $b->carrierTrackingId);
    }

    public function testGetShipmentStatusReturnsPending(): void
    {
        $gateway = new PickupInStoreGateway($this->scopeConfig);

        $status = $gateway->getShipmentStatus('SHUBO-PICKUP-abc');

        self::assertSame(ShipmentInterface::STATUS_PENDING, $status->normalizedStatus);
        self::assertSame('PENDING', $status->carrierStatusRaw);
        self::assertNull($status->occurredAt);
        self::assertNull($status->codCollectedAt);
    }

    public function testCancelShipmentAlwaysReportsSuccess(): void
    {
        $gateway = new PickupInStoreGateway($this->scopeConfig);

        $response = $gateway->cancelShipment('SHUBO-PICKUP-abc', 'customer no-show');

        self::assertTrue($response->success);
        self::assertNull($response->carrierMessage);
        self::assertSame('SHUBO-PICKUP-abc', $response->raw['carrier_tracking_id']);
        self::assertSame('customer no-show', $response->raw['reason']);
    }

    public function testFetchLabelReturnsPdfHeader(): void
    {
        $gateway = new PickupInStoreGateway($this->scopeConfig);

        $label = $gateway->fetchLabel('SHUBO-PICKUP-abc');

        self::assertStringStartsWith('%PDF-', $label->pdfBytes);
        self::assertSame('application/pdf', $label->contentType);
        self::assertSame('label-SHUBO-PICKUP-abc.pdf', $label->filename);
    }

    public function testListCitiesAndPudosReturnEmptyArrays(): void
    {
        $gateway = new PickupInStoreGateway($this->scopeConfig);

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
            preferredCarrierCode: 'shubopickup',
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
