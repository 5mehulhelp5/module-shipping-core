<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Api\Data\Dto;

/**
 * Normalized contact address DTO.
 *
 * Used for both pickup (origin) and delivery (destination) addresses.
 * `carrierExtras` holds adapter-specific fields (PUDO id, delivery
 * instructions) that don't belong in the normalized columns.
 *
 * @api
 */
class ContactAddress
{
    /**
     * @param array<string, mixed> $carrierExtras
     */
    public function __construct(
        public readonly string $name,
        public readonly string $phone,
        public readonly ?string $email,
        public readonly string $country,
        public readonly string $subdivision,
        public readonly string $city,
        public readonly ?string $district,
        public readonly string $street,
        public readonly ?string $building,
        public readonly ?string $floor,
        public readonly ?string $apartment,
        public readonly ?string $postcode,
        public readonly ?float $latitude,
        public readonly ?float $longitude,
        public readonly ?string $instructions,
        public readonly array $carrierExtras = [],
    ) {
    }
}
