<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Api;

use Shubo\ShippingCore\Api\Data\Dto\ReconciliationResult;
use Shubo\ShippingCore\Api\Data\InvoiceLineInterface;

/**
 * Matches imported invoice lines against shipments and flags disputes.
 *
 * @api
 */
interface ReconciliationServiceInterface
{
    /**
     * Match all lines in an import. Idempotent — re-running updates flags
     * but does not duplicate ledger entries (uses reference dedup).
     *
     * @param int $importId
     * @return ReconciliationResult
     */
    public function reconcile(int $importId): ReconciliationResult;

    /**
     * Resolve a disputed line (admin action).
     *
     * @param int    $lineId
     * @param string $resolution
     * @param string $adminNote
     * @return InvoiceLineInterface
     */
    public function resolveDispute(int $lineId, string $resolution, string $adminNote): InvoiceLineInterface;
}
