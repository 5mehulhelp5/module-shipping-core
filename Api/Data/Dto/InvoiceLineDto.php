<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Api\Data\Dto;

/**
 * Invoice line DTO.
 *
 * Output of
 * {@see \Shubo\ShippingCore\Api\InvoiceImporterInterface::parse()}.
 * Used to hand off parsed rows to the reconciliation service before any
 * DB write. Monetary values are integer tetri (cents);
 * `reportedFeeCents` is signed because carriers can issue credits.
 *
 * @api
 */
class InvoiceLineDto
{
    /**
     * @param array<string, mixed> $rawLine
     */
    public function __construct(
        public readonly ?string $externalLineId,
        public readonly ?string $carrierTrackingId,
        public readonly int $reportedCodCents,
        public readonly int $reportedFeeCents,
        public readonly int $reportedVatCents,
        public readonly array $rawLine = [],
    ) {
    }
}
