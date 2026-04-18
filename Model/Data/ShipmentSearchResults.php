<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Model\Data;

use Magento\Framework\Api\SearchResults;
use Shubo\ShippingCore\Api\Data\Search\ShipmentSearchResultsInterface;

/**
 * Concrete {@see ShipmentSearchResultsInterface} implementation — a thin
 * typed wrapper around Magento's generic {@see SearchResults}.
 */
class ShipmentSearchResults extends SearchResults implements ShipmentSearchResultsInterface
{
    /**
     * @inheritDoc
     *
     * @return list<\Shubo\ShippingCore\Api\Data\ShipmentInterface>
     */
    public function getItems(): array
    {
        /** @var list<\Shubo\ShippingCore\Api\Data\ShipmentInterface> $items */
        $items = parent::getItems();
        return $items;
    }

    /**
     * @inheritDoc
     *
     * @param list<\Shubo\ShippingCore\Api\Data\ShipmentInterface> $items
     */
    public function setItems(array $items): ShipmentSearchResultsInterface
    {
        /**
         * The parent's signature is the untyped
         * {@see \Magento\Framework\Api\AbstractExtensibleObject} array. Our
         * child accepts the narrower {@see ShipmentInterface}. The call is
         * safe at runtime because SearchResults stores items as-is.
         *
         * @phpstan-ignore-next-line argument.type
         */
        parent::setItems($items);
        return $this;
    }
}
