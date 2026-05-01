<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Model\Carrier;

use Magento\Framework\App\Config\ScopeConfigInterface;
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
 * Pickup-in-store carrier gateway.
 *
 * Trivial built-in carrier that lets the customer collect their order at
 * the merchant's physical location. The point of the module is purely to
 * occupy a row in {@see CarrierRegistry} so checkout can offer "Pickup in
 * store" as a shipping method — there is no real carrier integration, no
 * label printing, no tracking poll. The merchant rings the sale up at the
 * counter and clicks Magento's normal Ship action; the existing event
 * chain in `Shubo_Payout` handles the merchant-collected ledger writes.
 *
 * The carrier code is `shubopickup` (no underscore) — same precedent as
 * `shuboflat`. {@see \Shubo\ShippingCore\Observer\CreateShipmentOnMagentoShipment::resolveCarrierCode()}
 * does `explode('_', $shippingMethod, 2)[0]`, so a code like
 * `shubo_pickup` would resolve to just `shubo` and miss the registry.
 *
 * **COD is intentionally NOT supported on this carrier.** Pickup-in-store
 * implies the merchant collects cash directly at the counter; routing
 * through the COD reconciliation flow would double-book the funds. The
 * `CarrierCapabilitiesInterface` does not currently expose a
 * `supportsCod()` flag — instead, `CreateShipmentOnMagentoShipment`
 * writes `cod_enabled=false`, `cod_amount_cents=0` for any pickup row,
 * and `Shubo_Payout`'s offline-order observer treats `cod_amount_cents=0`
 * as "merchant collected directly". A future maintainer who wants to add
 * COD here MUST first add a registry-wide `supportsCod` capability and
 * gate the orchestrator's COD path on it; do NOT bolt COD onto this
 * gateway alone.
 *
 * @phpstan-type GenerateIdFn callable(): string
 */
class PickupInStoreGateway implements CarrierGatewayInterface
{
    public const CARRIER_CODE = 'shubopickup';
    public const METHOD_CODE = 'pickup';

    private const ETA_DAYS = 1;

    /** @var GenerateIdFn|null */
    private $idGenerator;

    /**
     * @param ScopeConfigInterface $scopeConfig Mirrors {@see FlatRateGateway} for
     *     constructor-shape consistency. Pickup never reads scope config — the
     *     price is hard-coded to zero — but accepting it here means a future
     *     migration to a configurable variant doesn't require a constructor
     *     signature change.
     * @param GenerateIdFn|null    $idGenerator Optional override for tracking-id
     *     generation. Null in production — the default delegates to
     *     random_bytes so PHPUnit can still stub it.
     */
    public function __construct(
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedFunction
        // @phpstan-ignore property.onlyWritten
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
     *
     * Always returns a single zero-cost option. Does not consult scope
     * config — charging shipping for a pickup-at-counter sale contradicts
     * the entire point of the flow.
     */
    public function quote(QuoteRequest $request): QuoteResponse
    {
        $option = new RateOption(
            carrierCode: self::CARRIER_CODE,
            methodCode: self::METHOD_CODE,
            priceCents: 0,
            etaDays: self::ETA_DAYS,
            serviceLevel: 'pickup',
            rationale: 'in_store_pickup',
        );

        return new QuoteResponse([$option], []);
    }

    /**
     * @inheritDoc
     */
    public function createShipment(ShipmentRequest $request): ShipmentResponse
    {
        $trackingId = sprintf('SHUBO-PICKUP-%s', $this->generateId());

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
        // No-op: pickup carriers have no upstream cancellation API.
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
     *
     * Always reports PENDING; admin row actions on the Shipments grid
     * promote the row to READY_FOR_PICKUP -> DELIVERED manually.
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
     *
     * Returns a minimal valid PDF header. Customers don't need a shipping
     * label for a counter pickup, but the orchestrator always invokes
     * fetchLabel() after createShipment(); returning a no-op PDF lets the
     * orchestrator's contract stay uniform across carriers.
     */
    public function fetchLabel(string $carrierTrackingId): LabelResponse
    {
        return new LabelResponse(
            pdfBytes: "%PDF-1.4\n%SHUBO-PICKUP-DEMO\n",
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
     * Return a fresh synthetic tracking-id suffix. Mirrors the FlatRate
     * pattern (UUIDv7-ish: time-ordered hex prefix + 10 random hex chars).
     *
     * @return string
     */
    private function generateId(): string
    {
        $fn = $this->idGenerator;
        if ($fn !== null) {
            return $fn();
        }
        return sprintf(
            '%08x-%s',
            time(),
            bin2hex(random_bytes(5)),
        );
    }
}
