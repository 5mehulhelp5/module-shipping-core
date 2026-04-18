<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Model\ResourceModel;

use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Shubo\ShippingCore\Api\Data\ShipmentInterface;

/**
 * Resource model for {@see \Shubo\ShippingCore\Model\Data\Shipment}.
 *
 * JSON-backed columns (`delivery_address_json`, `metadata_json`) are
 * serialized here at the save boundary so callers always deal with
 * decoded arrays on the model. If a caller has already encoded the
 * value, we leave it intact.
 */
class Shipment extends AbstractDb
{
    /** @var list<string> */
    private const JSON_FIELDS = [
        ShipmentInterface::FIELD_DELIVERY_ADDRESS_JSON,
        ShipmentInterface::FIELD_METADATA_JSON,
    ];

    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init(ShipmentInterface::TABLE, ShipmentInterface::FIELD_SHIPMENT_ID);
    }

    /**
     * Serialize JSON-backed columns before the AbstractDb save path runs.
     *
     * @param AbstractModel $object
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function _beforeSave(AbstractModel $object)
    {
        foreach (self::JSON_FIELDS as $field) {
            $value = $object->getData($field);
            if (is_array($value)) {
                $object->setData($field, (string)json_encode(
                    $value,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                ));
            }
        }
        return parent::_beforeSave($object);
    }

    /**
     * Decode JSON-backed columns after load so getters see arrays.
     *
     * @param AbstractModel $object
     * @return $this
     */
    protected function _afterLoad(AbstractModel $object)
    {
        foreach (self::JSON_FIELDS as $field) {
            $raw = $object->getData($field);
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $object->setData($field, $decoded);
                }
            }
        }
        return parent::_afterLoad($object);
    }

    /**
     * Re-decode after save so consumers that keep using the same model
     * instance see arrays rather than the serialized JSON we just wrote.
     *
     * @param AbstractModel $object
     * @return $this
     */
    protected function _afterSave(AbstractModel $object)
    {
        foreach (self::JSON_FIELDS as $field) {
            $raw = $object->getData($field);
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $object->setData($field, $decoded);
                }
            }
        }
        return parent::_afterSave($object);
    }

}
