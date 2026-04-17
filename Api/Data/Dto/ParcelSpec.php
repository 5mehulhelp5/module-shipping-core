<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Api\Data\Dto;

/**
 * Parcel specification DTO.
 *
 * Dimensions in millimetres, weight in grams, declared value in integer
 * tetri (cents). `skuBreakdown` is a list of
 * {sku: string, qty: int, valueCents: int} tuples for carriers that
 * require line-item declarations (COD, customs, insurance).
 *
 * @api
 */
class ParcelSpec
{
    /**
     * @param list<array{sku: string, qty: int, valueCents: int}> $skuBreakdown
     */
    public function __construct(
        public readonly int $weightGrams,
        public readonly int $lengthMm,
        public readonly int $widthMm,
        public readonly int $heightMm,
        public readonly int $declaredValueCents,
        public readonly array $skuBreakdown = [],
    ) {
    }
}
