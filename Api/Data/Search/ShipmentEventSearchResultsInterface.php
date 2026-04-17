<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Api\Data\Search;

use Magento\Framework\Api\SearchResultsInterface;
use Shubo\ShippingCore\Api\Data\ShipmentEventInterface;

/**
 * Search results for shipment events.
 *
 * @api
 */
interface ShipmentEventSearchResultsInterface extends SearchResultsInterface
{
    /**
     * @return list<ShipmentEventInterface>
     */
    public function getItems(): array;

    /**
     * @param list<ShipmentEventInterface> $items
     * @return $this
     */
    public function setItems(array $items): ShipmentEventSearchResultsInterface;
}
