<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Model\ResourceModel;

use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Shubo\ShippingCore\Api\Data\ShipmentEventInterface;

/**
 * Resource model for {@see \Shubo\ShippingCore\Model\Data\ShipmentEvent}.
 *
 * `raw_payload_json` is serialized at save and decoded after load/save so
 * callers always deal with decoded arrays. Mirrors the JSON-field handling
 * in {@see Shipment}.
 *
 * Unique-key races on `(carrier_code, external_event_id)` surface as a
 * MySQL duplicate-key exception from the parent `save()` path; the
 * repository wraps that in {@see \Magento\Framework\Exception\CouldNotSaveException}
 * so {@see \Shubo\ShippingCore\Model\Webhook\WebhookDispatcher} can detect
 * the race and answer DUPLICATE.
 */
class ShipmentEvent extends AbstractDb
{
    /** @var list<string> */
    private const JSON_FIELDS = [
        ShipmentEventInterface::FIELD_RAW_PAYLOAD_JSON,
    ];

    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init(ShipmentEventInterface::TABLE, ShipmentEventInterface::FIELD_EVENT_ID);
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
     * Re-decode after save so consumers that keep the same model instance
     * see arrays rather than the serialized JSON we just wrote.
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
