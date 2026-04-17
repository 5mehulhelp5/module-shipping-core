<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Api\Data\Dto;

/**
 * Reconciliation result DTO.
 *
 * Aggregate output of
 * {@see \Shubo\ShippingCore\Api\ReconciliationServiceInterface::reconcile()}.
 *
 * @api
 */
class ReconciliationResult
{
    public function __construct(
        public readonly int $importId,
        public readonly int $matchedCount,
        public readonly int $unmatchedCount,
        public readonly int $disputedCount,
        public readonly int $totalLines,
    ) {
    }
}
