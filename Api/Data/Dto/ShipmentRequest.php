<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Api\Data\Dto;

/**
 * Shipment request DTO.
 *
 * Input to
 * {@see \Shubo\ShippingCore\Api\ShipmentOrchestratorInterface::dispatch()}.
 * `clientTrackingCode` is the idempotency key; repeating it returns the
 * existing shipment. Monetary values are integer tetri (cents).
 *
 * @api
 */
class ShipmentRequest
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly int $orderId,
        public readonly int $merchantId,
        public readonly string $clientTrackingCode,
        public readonly ContactAddress $origin,
        public readonly ContactAddress $destination,
        public readonly ParcelSpec $parcel,
        public readonly bool $codEnabled,
        public readonly int $codAmountCents,
        public readonly ?string $preferredCarrierCode,
        public readonly array $metadata = [],
    ) {
    }
}
