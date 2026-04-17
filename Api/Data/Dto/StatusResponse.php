<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Api\Data\Dto;

/**
 * Status response DTO.
 *
 * Output of
 * {@see \Shubo\ShippingCore\Api\CarrierGatewayInterface::getShipmentStatus()}.
 * `normalizedStatus` is a Core enum value (see design doc §10.1);
 * `carrierStatusRaw` is the exact string from the carrier.
 *
 * @api
 */
class StatusResponse
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly string $normalizedStatus,
        public readonly string $carrierStatusRaw,
        public readonly ?string $occurredAt,
        public readonly ?string $codCollectedAt,
        public readonly array $raw = [],
    ) {
    }
}
