<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Api;

/**
 * Declarative carrier capabilities.
 *
 * No side effects. Core caches the output in
 * `shubo_shipping_carrier_config.capabilities_cache_json` so admin UI
 * can render the capability grid without instantiating adapters.
 *
 * @api
 */
interface CarrierCapabilitiesInterface
{
    /**
     * Carrier code (matches {@see CarrierGatewayInterface::code()}).
     *
     * @return string
     */
    public function code(): string;

    /**
     * Human-readable display name.
     *
     * @return string
     */
    public function displayName(): string;

    /**
     * @return bool
     */
    public function supportsWebhooks(): bool;

    /**
     * @return bool
     */
    public function supportsSandbox(): bool;

    /**
     * @return bool
     */
    public function supportsCodReconciliationApi(): bool;

    /**
     * @return bool
     */
    public function supportsPudo(): bool;

    /**
     * @return bool
     */
    public function supportsExpress(): bool;

    /**
     * @return bool
     */
    public function supportsMultiParcel(): bool;

    /**
     * @return bool
     */
    public function supportsReturns(): bool;

    /**
     * @return bool
     */
    public function supportsCancelAfterPickup(): bool;

    /**
     * Recommended carrier rate limit in requests per minute.
     *
     * @return int
     */
    public function ratelimitPerMinute(): int;

    /**
     * Hard timeout for checkout-path calls (milliseconds).
     *
     * @return int
     */
    public function checkoutTimeoutMs(): int;

    /**
     * ISO 3166-2 subdivision codes the carrier serves (e.g. 'GE-TB', 'GE-IM').
     *
     * @return list<string>
     */
    public function serviceAreaSubdivisions(): array;

    /**
     * Core normalized status enum values this carrier can report.
     *
     * @return list<string>
     */
    public function producibleStatuses(): array;
}
