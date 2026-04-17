<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Api\Data\Dto;

/**
 * Invoice import metadata DTO.
 *
 * Derived from a carrier invoice file header/metadata before the full
 * line-by-line parse. Dates are ISO-8601 (YYYY-MM-DD).
 *
 * @api
 */
class InvoiceImportMetadata
{
    public function __construct(
        public readonly string $carrierCode,
        public readonly string $periodStart,
        public readonly string $periodEnd,
        public readonly ?string $statementNumber = null,
    ) {
    }
}
