<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Api\Data;

/**
 * Shipment event data interface.
 *
 * Append-only log of every state transition, poll, webhook touching a
 * shipment. Event writes happen exclusively through the repository —
 * adapters never insert directly.
 *
 * @api
 */
interface ShipmentEventInterface
{
    public const TABLE = 'shubo_shipping_shipment_event';

    public const FIELD_EVENT_ID = 'event_id';
    public const FIELD_SHIPMENT_ID = 'shipment_id';
    public const FIELD_CARRIER_CODE = 'carrier_code';
    public const FIELD_EVENT_TYPE = 'event_type';
    public const FIELD_CARRIER_STATUS_RAW = 'carrier_status_raw';
    public const FIELD_NORMALIZED_STATUS = 'normalized_status';
    public const FIELD_OCCURRED_AT = 'occurred_at';
    public const FIELD_RECEIVED_AT = 'received_at';
    public const FIELD_SOURCE = 'source';
    public const FIELD_EXTERNAL_EVENT_ID = 'external_event_id';
    public const FIELD_RAW_PAYLOAD_JSON = 'raw_payload_json';

    /** Event type enum values */
    public const EVENT_TYPE_CREATED = 'created';
    public const EVENT_TYPE_DISPATCHED = 'dispatched';
    public const EVENT_TYPE_STATUS_CHANGE = 'status_change';
    public const EVENT_TYPE_PICKED_UP = 'picked_up';
    public const EVENT_TYPE_DELIVERED = 'delivered';
    public const EVENT_TYPE_CANCELLED = 'cancelled';
    public const EVENT_TYPE_RETURNED = 'returned';
    public const EVENT_TYPE_COD_COLLECTED = 'cod_collected';
    public const EVENT_TYPE_FAILED = 'failed';
    public const EVENT_TYPE_WEBHOOK_RECEIVED = 'webhook_received';
    public const EVENT_TYPE_POLL_NOOP = 'poll_noop';

    /** Event source enum values */
    public const SOURCE_WEBHOOK = 'webhook';
    public const SOURCE_POLL = 'poll';
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_SYSTEM = 'system';

    /**
     * @return int|null
     */
    public function getEventId(): ?int;

    /**
     * @return int
     */
    public function getShipmentId(): int;

    /**
     * @param int $shipmentId
     * @return $this
     */
    public function setShipmentId(int $shipmentId): self;

    /**
     * @return string
     */
    public function getCarrierCode(): string;

    /**
     * @param string $carrierCode
     * @return $this
     */
    public function setCarrierCode(string $carrierCode): self;

    /**
     * @return string
     */
    public function getEventType(): string;

    /**
     * @param string $eventType
     * @return $this
     */
    public function setEventType(string $eventType): self;

    /**
     * @return string|null
     */
    public function getCarrierStatusRaw(): ?string;

    /**
     * @param string|null $status
     * @return $this
     */
    public function setCarrierStatusRaw(?string $status): self;

    /**
     * @return string|null
     */
    public function getNormalizedStatus(): ?string;

    /**
     * @param string|null $status
     * @return $this
     */
    public function setNormalizedStatus(?string $status): self;

    /**
     * @return string|null
     */
    public function getOccurredAt(): ?string;

    /**
     * @param string|null $timestamp
     * @return $this
     */
    public function setOccurredAt(?string $timestamp): self;

    /**
     * @return string|null
     */
    public function getReceivedAt(): ?string;

    /**
     * @return string
     */
    public function getSource(): string;

    /**
     * @param string $source
     * @return $this
     */
    public function setSource(string $source): self;

    /**
     * @return string|null
     */
    public function getExternalEventId(): ?string;

    /**
     * @param string|null $externalEventId
     * @return $this
     */
    public function setExternalEventId(?string $externalEventId): self;

    /**
     * Raw payload as decoded array (repository handles JSON encode/decode).
     *
     * @return array<string, mixed>
     */
    public function getRawPayload(): array;

    /**
     * @param array<string, mixed> $payload
     * @return $this
     */
    public function setRawPayload(array $payload): self;
}
