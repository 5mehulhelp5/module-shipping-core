<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Api\Data\Search;

use Magento\Framework\Api\SearchResultsInterface;
use Shubo\ShippingCore\Api\Data\ShipmentInterface;

/**
 * Search results for shipments.
 *
 * @api
 */
interface ShipmentSearchResultsInterface extends SearchResultsInterface
{
    /**
     * @return list<ShipmentInterface>
     */
    public function getItems(): array;

    /**
     * @param list<ShipmentInterface> $items
     * @return $this
     */
    public function setItems(array $items): ShipmentSearchResultsInterface;
}
