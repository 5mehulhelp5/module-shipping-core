<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Api;

/**
 * DI-composed registry of active carrier adapters.
 *
 * Adapter modules register themselves via their own `di.xml` by adding
 * entries to the `gateways` / `capabilities` argument arrays of the
 * concrete registry type.
 *
 * @api
 */
interface CarrierRegistryInterface
{
    /**
     * Return the gateway for a carrier code.
     *
     * @param string $carrierCode
     * @return CarrierGatewayInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function get(string $carrierCode): CarrierGatewayInterface;

    /**
     * Return the capabilities declaration for a carrier code.
     *
     * @param string $carrierCode
     * @return CarrierCapabilitiesInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getCapabilities(string $carrierCode): CarrierCapabilitiesInterface;

    /**
     * Enabled gateways indexed by carrier code.
     *
     * @return array<string, CarrierGatewayInterface>
     */
    public function enabled(): array;

    /**
     * All registered codes (enabled and disabled).
     *
     * @return list<string>
     */
    public function registeredCodes(): array;

    /**
     * Whether a given code is registered.
     *
     * @param string $carrierCode
     * @return bool
     */
    public function has(string $carrierCode): bool;
}
