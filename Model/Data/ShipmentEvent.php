<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Model\Data;

use Magento\Framework\Model\AbstractModel;
use Shubo\ShippingCore\Api\Data\ShipmentEventInterface;
use Shubo\ShippingCore\Model\ResourceModel\ShipmentEvent as ShipmentEventResource;

/**
 * Active-record model for an append-only shipment-event row.
 *
 * The `raw_payload_json` column is encoded/decoded by the resource model so
 * callers always see decoded arrays through {@see self::getRawPayload()} and
 * {@see self::setRawPayload()}.
 */
class ShipmentEvent extends AbstractModel implements ShipmentEventInterface
{
    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init(ShipmentEventResource::class);
        $this->setIdFieldName(self::FIELD_EVENT_ID);
    }

    public function getEventId(): ?int
    {
        $v = $this->getData(self::FIELD_EVENT_ID);
        return $v === null ? null : (int)$v;
    }

    public function getShipmentId(): int
    {
        return (int)$this->getData(self::FIELD_SHIPMENT_ID);
    }

    public function setShipmentId(int $shipmentId): self
    {
        $this->setData(self::FIELD_SHIPMENT_ID, $shipmentId);
        return $this;
    }

    public function getCarrierCode(): string
    {
        return (string)$this->getData(self::FIELD_CARRIER_CODE);
    }

    public function setCarrierCode(string $carrierCode): self
    {
        $this->setData(self::FIELD_CARRIER_CODE, $carrierCode);
        return $this;
    }

    public function getEventType(): string
    {
        return (string)$this->getData(self::FIELD_EVENT_TYPE);
    }

    public function setEventType(string $eventType): self
    {
        $this->setData(self::FIELD_EVENT_TYPE, $eventType);
        return $this;
    }

    public function getCarrierStatusRaw(): ?string
    {
        $v = $this->getData(self::FIELD_CARRIER_STATUS_RAW);
        return $v === null ? null : (string)$v;
    }

    public function setCarrierStatusRaw(?string $status): self
    {
        $this->setData(self::FIELD_CARRIER_STATUS_RAW, $status);
        return $this;
    }

    public function getNormalizedStatus(): ?string
    {
        $v = $this->getData(self::FIELD_NORMALIZED_STATUS);
        return $v === null ? null : (string)$v;
    }

    public function setNormalizedStatus(?string $status): self
    {
        $this->setData(self::FIELD_NORMALIZED_STATUS, $status);
        return $this;
    }

    public function getOccurredAt(): ?string
    {
        $v = $this->getData(self::FIELD_OCCURRED_AT);
        return $v === null ? null : (string)$v;
    }

    public function setOccurredAt(?string $timestamp): self
    {
        $this->setData(self::FIELD_OCCURRED_AT, $timestamp);
        return $this;
    }

    public function getReceivedAt(): ?string
    {
        $v = $this->getData(self::FIELD_RECEIVED_AT);
        return $v === null ? null : (string)$v;
    }

    public function getSource(): string
    {
        return (string)$this->getData(self::FIELD_SOURCE);
    }

    public function setSource(string $source): self
    {
        $this->setData(self::FIELD_SOURCE, $source);
        return $this;
    }

    public function getExternalEventId(): ?string
    {
        $v = $this->getData(self::FIELD_EXTERNAL_EVENT_ID);
        return $v === null ? null : (string)$v;
    }

    public function setExternalEventId(?string $externalEventId): self
    {
        $this->setData(self::FIELD_EXTERNAL_EVENT_ID, $externalEventId);
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getRawPayload(): array
    {
        $v = $this->getData(self::FIELD_RAW_PAYLOAD_JSON);
        if (is_array($v)) {
            return $v;
        }
        if (is_string($v) && $v !== '') {
            $decoded = json_decode($v, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function setRawPayload(array $payload): self
    {
        $this->setData(self::FIELD_RAW_PAYLOAD_JSON, $payload);
        return $this;
    }
}
