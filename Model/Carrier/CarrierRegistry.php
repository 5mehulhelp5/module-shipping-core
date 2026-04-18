<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Model\Carrier;

use Magento\Framework\Exception\NoSuchEntityException;
use Shubo\ShippingCore\Api\CarrierCapabilitiesInterface;
use Shubo\ShippingCore\Api\CarrierGatewayInterface;
use Shubo\ShippingCore\Api\CarrierRegistryInterface;
use Shubo\ShippingCore\Exception\NoCarrierAvailableException;
use Shubo\ShippingCore\Model\Logging\StructuredLogger;

/**
 * Default {@see CarrierRegistryInterface} implementation.
 *
 * DI composition: adapter modules register their gateway (and optional
 * capabilities) by adding entries to the `gateways` / `capabilities`
 * constructor arguments via their own `di.xml`. The registry is a dumb
 * map — no runtime lookup of enabled flags beyond what each adapter
 * exposes through its gateway's {@see CarrierGatewayInterface::code()}.
 *
 * Lookups for an unknown code throw {@see NoCarrierAvailableException} so
 * callers can funnel the error through the same "no carrier" path the
 * orchestrator already knows how to handle.
 */
class CarrierRegistry implements CarrierRegistryInterface
{
    /**
     * @param array<string, CarrierGatewayInterface>     $gateways
     * @param array<string, CarrierCapabilitiesInterface> $capabilities
     */
    public function __construct(
        private readonly StructuredLogger $logger,
        private readonly array $gateways = [],
        private readonly array $capabilities = [],
    ) {
    }

    public function get(string $carrierCode): CarrierGatewayInterface
    {
        if (!array_key_exists($carrierCode, $this->gateways)) {
            throw new NoCarrierAvailableException(
                __('No carrier registered for code "%1".', $carrierCode),
            );
        }
        $gateway = $this->gateways[$carrierCode];
        if (!$gateway instanceof CarrierGatewayInterface) {
            throw new NoCarrierAvailableException(
                __(
                    'Carrier "%1" is registered but does not implement CarrierGatewayInterface.',
                    $carrierCode,
                ),
            );
        }
        return $gateway;
    }

    public function getCapabilities(string $carrierCode): CarrierCapabilitiesInterface
    {
        if (!array_key_exists($carrierCode, $this->capabilities)) {
            throw NoSuchEntityException::singleField('carrier_code', $carrierCode);
        }
        $capabilities = $this->capabilities[$carrierCode];
        if (!$capabilities instanceof CarrierCapabilitiesInterface) {
            throw NoSuchEntityException::singleField('carrier_code', $carrierCode);
        }
        return $capabilities;
    }

    /**
     * @return array<string, CarrierGatewayInterface>
     */
    public function enabled(): array
    {
        // All registered gateways are considered enabled for now. Phase 6
        // will introduce a per-carrier enabled flag via shubo_shipping_carrier_config;
        // at that point this method becomes the single point where the flag is
        // consulted. Keeping the method here (instead of inlining the call
        // site) means adapters and the orchestrator never have to look that
        // flag up themselves.
        $enabled = [];
        foreach ($this->gateways as $code => $gateway) {
            if ($gateway instanceof CarrierGatewayInterface) {
                $enabled[$code] = $gateway;
            } else {
                // Defensive: a misconfigured di.xml could pass something non-gateway.
                $this->logger->logDispatchFailed(
                    (string)$code,
                    'registry_enabled',
                    new \RuntimeException('registered value is not a CarrierGatewayInterface'),
                );
            }
        }
        return $enabled;
    }

    /**
     * @return list<string>
     */
    public function registeredCodes(): array
    {
        return array_values(array_map('strval', array_keys($this->gateways)));
    }

    public function has(string $carrierCode): bool
    {
        return array_key_exists($carrierCode, $this->gateways)
            && $this->gateways[$carrierCode] instanceof CarrierGatewayInterface;
    }
}
