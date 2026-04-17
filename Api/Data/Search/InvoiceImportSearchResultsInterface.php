<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Api\Data\Search;

use Magento\Framework\Api\SearchResultsInterface;
use Shubo\ShippingCore\Api\Data\InvoiceImportInterface;

/**
 * Search results for invoice imports.
 *
 * @api
 */
interface InvoiceImportSearchResultsInterface extends SearchResultsInterface
{
    /**
     * @return list<InvoiceImportInterface>
     */
    public function getItems(): array;

    /**
     * @param list<InvoiceImportInterface> $items
     * @return $this
     */
    public function setItems(array $items): InvoiceImportSearchResultsInterface;
}
