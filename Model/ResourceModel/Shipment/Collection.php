<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Model\ResourceModel\Shipment;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Shubo\ShippingCore\Api\Data\ShipmentInterface;
use Shubo\ShippingCore\Model\Data\Shipment;
use Shubo\ShippingCore\Model\ResourceModel\Shipment as ShipmentResource;

/**
 * Collection for {@see \Shubo\ShippingCore\Model\Data\Shipment}.
 *
 * Used by {@see \Shubo\ShippingCore\Model\Shipment\ShipmentRepository::getList()}
 * through the standard collection-processor chain.
 */
class Collection extends AbstractCollection
{
    /**
     * @var string
     */
    protected $_idFieldName = ShipmentInterface::FIELD_SHIPMENT_ID;

    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init(Shipment::class, ShipmentResource::class);
    }
}
