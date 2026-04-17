<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Api\Data\Dto;

/**
 * Shipment response DTO.
 *
 * Output of
 * {@see \Shubo\ShippingCore\Api\CarrierGatewayInterface::createShipment()}.
 * `raw` is the adapter-normalized representation of the carrier's
 * response (kept for audit + event payload).
 *
 * @api
 */
class ShipmentResponse
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly string $carrierTrackingId,
        public readonly ?string $labelUrl,
        public readonly string $status,
        public readonly array $raw = [],
    ) {
    }
}
