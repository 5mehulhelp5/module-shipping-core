<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Model\ResourceModel\ShipmentEvent;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Shubo\ShippingCore\Api\Data\ShipmentEventInterface;
use Shubo\ShippingCore\Model\Data\ShipmentEvent;
use Shubo\ShippingCore\Model\ResourceModel\ShipmentEvent as ShipmentEventResource;

/**
 * Collection for {@see \Shubo\ShippingCore\Model\Data\ShipmentEvent}.
 *
 * Used by {@see \Shubo\ShippingCore\Model\Shipment\ShipmentEventRepository}
 * for the newest-first per-shipment lookup and the
 * `(carrier_code, external_event_id)` idempotency probe.
 */
class Collection extends AbstractCollection
{
    /**
     * @var string
     */
    protected $_idFieldName = ShipmentEventInterface::FIELD_EVENT_ID;

    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init(ShipmentEvent::class, ShipmentEventResource::class);
    }
}
