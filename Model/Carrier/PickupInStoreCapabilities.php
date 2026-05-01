<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Model\Carrier;

use Shubo\ShippingCore\Api\CarrierCapabilitiesInterface;
use Shubo\ShippingCore\Api\Data\ShipmentInterface;

/**
 * Capability declaration for the {@see PickupInStoreGateway}.
 *
 * Pure value bag — no side effects, no runtime mutation. The registry
 * caches this into `shubo_shipping_carrier_config.capabilities_cache_json`
 * so the admin capability matrix can render without instantiating the
 * gateway itself.
 *
 * The producible-statuses list intentionally omits `IN_TRANSIT`: a
 * counter-pickup order goes pending -> ready_for_pickup -> delivered (or
 * cancelled) and never sits with a courier.
 */
class PickupInStoreCapabilities implements CarrierCapabilitiesInterface
{
    /**
     * @inheritDoc
     */
    public function code(): string
    {
        return PickupInStoreGateway::CARRIER_CODE;
    }

    /**
     * @inheritDoc
     */
    public function displayName(): string
    {
        return 'Pickup in store';
    }

    /**
     * @inheritDoc
     */
    public function supportsWebhooks(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function supportsSandbox(): bool
    {
        // No upstream carrier — live code is sandbox-equivalent.
        return true;
    }

    /**
     * @inheritDoc
     */
    public function supportsCodReconciliationApi(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function supportsPudo(): bool
    {
        // The merchant's pickup_address is not a PUDO; it's the merchant's
        // own physical location.
        return false;
    }

    /**
     * @inheritDoc
     */
    public function supportsExpress(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function supportsMultiParcel(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function supportsReturns(): bool
    {
        // Customer comes back in person for returns — not a carrier
        // workflow.
        return false;
    }

    /**
     * @inheritDoc
     */
    public function supportsCancelAfterPickup(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function ratelimitPerMinute(): int
    {
        // Local-only computation — no upstream carrier to rate-limit
        // against. 600 rpm == 10 rps is effectively unbounded for
        // checkout traffic.
        return 600;
    }

    /**
     * @inheritDoc
     */
    public function checkoutTimeoutMs(): int
    {
        // Local computation only.
        return 100;
    }

    /**
     * @inheritDoc
     *
     * @return list<string>
     */
    public function serviceAreaSubdivisions(): array
    {
        // Universally available across Georgia — actual coverage is
        // enforced upstream by the merchant's pickup_address row.
        return [
            'GE-TB', 'GE-AB', 'GE-AJ', 'GE-GU', 'GE-IM', 'GE-KA',
            'GE-KK', 'GE-MM', 'GE-RL', 'GE-SJ', 'GE-SK', 'GE-SZ',
        ];
    }

    /**
     * @inheritDoc
     *
     * @return list<string>
     */
    public function producibleStatuses(): array
    {
        // No IN_TRANSIT — pickup skips the courier states entirely.
        return [
            ShipmentInterface::STATUS_PENDING,
            ShipmentInterface::STATUS_READY_FOR_PICKUP,
            ShipmentInterface::STATUS_DELIVERED,
            ShipmentInterface::STATUS_CANCELLED,
        ];
    }
}
