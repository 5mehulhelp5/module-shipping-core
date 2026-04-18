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
 * Capability declaration for the {@see FlatRateGateway}.
 *
 * Pure value bag — no side effects, no runtime mutation. The registry
 * caches this into `shubo_shipping_carrier_config.capabilities_cache_json`
 * so the admin capability matrix can render without instantiating the
 * gateway itself.
 */
class FlatRateCapabilities implements CarrierCapabilitiesInterface
{
    /**
     * @inheritDoc
     */
    public function code(): string
    {
        return FlatRateGateway::CARRIER_CODE;
    }

    /**
     * @inheritDoc
     */
    public function displayName(): string
    {
        return 'Shubo Flat Rate';
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
        // Always a demo — treat the live code as sandbox.
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
        // Demo-only adapter — no upstream carrier to rate-limit against.
        // 600 rpm == 10 rps — effectively unbounded for checkout traffic.
        return 600;
    }

    /**
     * @inheritDoc
     */
    public function checkoutTimeoutMs(): int
    {
        // Local computation only, so aim for sub-100ms.
        return 100;
    }

    /**
     * @inheritDoc
     *
     * @return list<string>
     */
    public function serviceAreaSubdivisions(): array
    {
        // All Georgian subdivisions. Empty list is NOT a synonym for
        // "no coverage"; callers that need universal coverage should
        // use a wildcard match, but for Phase 6 we enumerate explicitly.
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
        // Demo adapter exposes a minimal lifecycle. Admin row actions on
        // the Shipments grid drive all subsequent transitions manually.
        return [
            ShipmentInterface::STATUS_PENDING,
            ShipmentInterface::STATUS_READY_FOR_PICKUP,
            ShipmentInterface::STATUS_IN_TRANSIT,
            ShipmentInterface::STATUS_DELIVERED,
            ShipmentInterface::STATUS_CANCELLED,
        ];
    }
}
