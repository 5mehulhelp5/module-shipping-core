<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Model\Carrier;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Shubo\ShippingCore\Api\CarrierGatewayInterface;
use Shubo\ShippingCore\Api\Data\Dto\CancelResponse;
use Shubo\ShippingCore\Api\Data\Dto\LabelResponse;
use Shubo\ShippingCore\Api\Data\Dto\QuoteRequest;
use Shubo\ShippingCore\Api\Data\Dto\QuoteResponse;
use Shubo\ShippingCore\Api\Data\Dto\RateOption;
use Shubo\ShippingCore\Api\Data\Dto\ShipmentRequest;
use Shubo\ShippingCore\Api\Data\Dto\ShipmentResponse;
use Shubo\ShippingCore\Api\Data\Dto\StatusResponse;
use Shubo\ShippingCore\Api\Data\ShipmentInterface;

/**
 * Demo-scoped flat-rate carrier gateway.
 *
 * Not a real carrier integration. Returns a single configurable rate from
 * `carriers/shuboflat/price` (default 5.00 GEL), emits synthetic tracking
 * IDs, and marks the shipment pending. The operator-facing Shipments grid
 * drives subsequent status changes manually via row actions.
 *
 * The carrier code is intentionally `shuboflat` (no underscore). This
 * sidesteps the `explode('_', $method, 2)` logic at
 * {@see \Shubo\ShippingCore\Observer\CreateShipmentOnMagentoShipment::resolveCarrierCode()}
 * which would otherwise split a code like `shubo_flat` into just `shubo`.
 *
 * @phpstan-type GenerateIdFn callable(): string
 */
class FlatRateGateway implements CarrierGatewayInterface
{
    public const CARRIER_CODE = 'shuboflat';
    public const METHOD_CODE = 'standard';

    private const CONFIG_PRICE = 'carriers/shuboflat/price';
    private const DEFAULT_PRICE_GEL = '5.00';

    private const ETA_DAYS = 2;

    /** @var GenerateIdFn|null */
    private $idGenerator;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param GenerateIdFn|null    $idGenerator Optional override for tracking-id
     *     generation. Null in production — constructor picks a default that
     *     delegates to random_bytes so PHPUnit can still stub it.
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        ?callable $idGenerator = null,
    ) {
        $this->idGenerator = $idGenerator;
    }

    /**
     * @inheritDoc
     */
    public function code(): string
    {
        return self::CARRIER_CODE;
    }

    /**
     * @inheritDoc
     */
    public function quote(QuoteRequest $request): QuoteResponse
    {
        $priceCents = $this->readPriceCents();
        $option = new RateOption(
            carrierCode: self::CARRIER_CODE,
            methodCode: self::METHOD_CODE,
            priceCents: $priceCents,
            etaDays: self::ETA_DAYS,
            serviceLevel: 'standard',
            rationale: 'flat_rate',
        );

        return new QuoteResponse([$option], []);
    }

    /**
     * @inheritDoc
     */
    public function createShipment(ShipmentRequest $request): ShipmentResponse
    {
        $trackingId = sprintf('SHUBO-FLAT-%s', $this->generateId());

        return new ShipmentResponse(
            carrierTrackingId: $trackingId,
            labelUrl: null,
            status: ShipmentInterface::STATUS_PENDING,
            raw: [
                'client_tracking_code' => $request->clientTrackingCode,
                'order_id' => $request->orderId,
                'merchant_id' => $request->merchantId,
            ],
        );
    }

    /**
     * @inheritDoc
     */
    public function cancelShipment(string $carrierTrackingId, ?string $reason = null): CancelResponse
    {
        // No-op: no real carrier behind this adapter.
        return new CancelResponse(
            success: true,
            carrierMessage: null,
            raw: [
                'carrier_tracking_id' => $carrierTrackingId,
                'reason' => $reason,
            ],
        );
    }

    /**
     * @inheritDoc
     */
    public function getShipmentStatus(string $carrierTrackingId): StatusResponse
    {
        return new StatusResponse(
            normalizedStatus: ShipmentInterface::STATUS_PENDING,
            carrierStatusRaw: 'PENDING',
            occurredAt: null,
            codCollectedAt: null,
            raw: ['carrier_tracking_id' => $carrierTrackingId],
        );
    }

    /**
     * @inheritDoc
     */
    public function fetchLabel(string $carrierTrackingId): LabelResponse
    {
        // Minimal valid PDF header bytes. Demo-only; a future phase may
        // render an actual label PDF on the merchant pickup address.
        return new LabelResponse(
            pdfBytes: "%PDF-1.4\n%SHUBO-FLAT-DEMO\n",
            contentType: 'application/pdf',
            filename: sprintf('label-%s.pdf', $carrierTrackingId),
        );
    }

    /**
     * @inheritDoc
     *
     * @return list<\Shubo\ShippingCore\Api\Data\GeoCacheInterface>
     */
    public function listCities(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     *
     * @return list<\Shubo\ShippingCore\Api\Data\GeoCacheInterface>
     */
    public function listPudos(?string $cityCode = null): array
    {
        return [];
    }

    /**
     * Read `carriers/shuboflat/price` from config and convert to integer
     * tetri. Magento stores this as a string in core_config_data; we treat
     * the absence as the documented 5.00 GEL default.
     *
     * Uses bcmath so 5.00 * 100 doesn't hit float rounding.
     *
     * @return int
     */
    private function readPriceCents(): int
    {
        $raw = $this->scopeConfig->getValue(self::CONFIG_PRICE, ScopeInterface::SCOPE_STORE);
        $price = ($raw === null || (string)$raw === '')
            ? self::DEFAULT_PRICE_GEL
            : (string)$raw;

        // bcmul returns a string; convert to int cents for RateOption.
        $cents = bcmul($price, '100', 0);
        return (int)$cents;
    }

    /**
     * Return a fresh synthetic tracking-id suffix.
     *
     * @return string
     */
    private function generateId(): string
    {
        $fn = $this->idGenerator;
        if ($fn !== null) {
            return $fn();
        }
        // UUIDv7-ish: time-ordered hex prefix + 10 random hex chars. Good
        // enough for synthetic demo IDs and avoids the uuid-ossp dependency.
        return sprintf(
            '%08x-%s',
            time(),
            bin2hex(random_bytes(5)),
        );
    }
}
