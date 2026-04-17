<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Api\Data;

/**
 * Shipment data interface.
 *
 * Represents one marketplace shipment regardless of carrier. This is the
 * canonical source of truth for shipping state; carrier-specific details
 * live on the event stream (see {@see ShipmentEventInterface}).
 *
 * Monetary fields are stored as integer tetri (cents).
 *
 * @api
 */
interface ShipmentInterface
{
    public const TABLE = 'shubo_shipping_shipment';

    public const FIELD_SHIPMENT_ID = 'shipment_id';
    public const FIELD_MAGENTO_SHIPMENT_ID = 'magento_shipment_id';
    public const FIELD_ORDER_ID = 'order_id';
    public const FIELD_MERCHANT_ID = 'merchant_id';
    public const FIELD_CARRIER_CODE = 'carrier_code';
    public const FIELD_CARRIER_TRACKING_ID = 'carrier_tracking_id';
    public const FIELD_CLIENT_TRACKING_CODE = 'client_tracking_code';
    public const FIELD_STATUS = 'status';
    public const FIELD_PICKUP_ADDRESS_ID = 'pickup_address_id';
    public const FIELD_DELIVERY_ADDRESS_JSON = 'delivery_address_json';
    public const FIELD_PARCEL_WEIGHT_GRAMS = 'parcel_weight_grams';
    public const FIELD_PARCEL_VALUE_CENTS = 'parcel_value_cents';
    public const FIELD_COD_ENABLED = 'cod_enabled';
    public const FIELD_COD_AMOUNT_CENTS = 'cod_amount_cents';
    public const FIELD_COD_COLLECTED_AT = 'cod_collected_at';
    public const FIELD_COD_RECONCILED_AT = 'cod_reconciled_at';
    public const FIELD_LABEL_URL = 'label_url';
    public const FIELD_LABEL_PDF_STORED_AT = 'label_pdf_stored_at';
    public const FIELD_CREATED_AT = 'created_at';
    public const FIELD_UPDATED_AT = 'updated_at';
    public const FIELD_LAST_POLLED_AT = 'last_polled_at';
    public const FIELD_NEXT_POLL_AT = 'next_poll_at';
    public const FIELD_POLL_STRATEGY = 'poll_strategy';
    public const FIELD_WEBHOOK_SECRET = 'webhook_secret';
    public const FIELD_FAILED_AT = 'failed_at';
    public const FIELD_FAILURE_REASON = 'failure_reason';
    public const FIELD_METADATA_JSON = 'metadata_json';

    /** Normalized status values (see design doc §10.1) */
    public const STATUS_PENDING = 'pending';
    public const STATUS_READY_FOR_PICKUP = 'ready_for_pickup';
    public const STATUS_PICKED_UP = 'picked_up';
    public const STATUS_IN_TRANSIT = 'in_transit';
    public const STATUS_OUT_FOR_DELIVERY = 'out_for_delivery';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_DELIVERY_ATTEMPTED = 'delivery_attempted';
    public const STATUS_RETURNED_TO_SENDER = 'returned_to_sender';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_FAILED = 'failed';

    /** Poll strategy values */
    public const POLL_STRATEGY_ADAPTIVE = 'adaptive';
    public const POLL_STRATEGY_FREQUENT = 'frequent';
    public const POLL_STRATEGY_DISABLED = 'disabled';

    /**
     * @return int|null
     */
    public function getShipmentId(): ?int;

    /**
     * @return int|null
     */
    public function getMagentoShipmentId(): ?int;

    /**
     * @param int|null $magentoShipmentId
     * @return $this
     */
    public function setMagentoShipmentId(?int $magentoShipmentId): self;

    /**
     * @return int
     */
    public function getOrderId(): int;

    /**
     * @param int $orderId
     * @return $this
     */
    public function setOrderId(int $orderId): self;

    /**
     * @return int
     */
    public function getMerchantId(): int;

    /**
     * @param int $merchantId
     * @return $this
     */
    public function setMerchantId(int $merchantId): self;

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
     * @return string|null
     */
    public function getCarrierTrackingId(): ?string;

    /**
     * @param string|null $carrierTrackingId
     * @return $this
     */
    public function setCarrierTrackingId(?string $carrierTrackingId): self;

    /**
     * @return string
     */
    public function getClientTrackingCode(): string;

    /**
     * @param string $clientTrackingCode
     * @return $this
     */
    public function setClientTrackingCode(string $clientTrackingCode): self;

    /**
     * @return string
     */
    public function getStatus(): string;

    /**
     * @param string $status
     * @return $this
     */
    public function setStatus(string $status): self;

    /**
     * @return int|null
     */
    public function getPickupAddressId(): ?int;

    /**
     * @param int|null $pickupAddressId
     * @return $this
     */
    public function setPickupAddressId(?int $pickupAddressId): self;

    /**
     * Delivery address stored as decoded array (repository handles JSON encode/decode).
     *
     * @return array<string, mixed>
     */
    public function getDeliveryAddress(): array;

    /**
     * @param array<string, mixed> $address
     * @return $this
     */
    public function setDeliveryAddress(array $address): self;

    /**
     * @return int
     */
    public function getParcelWeightGrams(): int;

    /**
     * @param int $grams
     * @return $this
     */
    public function setParcelWeightGrams(int $grams): self;

    /**
     * @return int Parcel declared value in tetri (cents)
     */
    public function getParcelValueCents(): int;

    /**
     * @param int $cents
     * @return $this
     */
    public function setParcelValueCents(int $cents): self;

    /**
     * @return bool
     */
    public function isCodEnabled(): bool;

    /**
     * @param bool $enabled
     * @return $this
     */
    public function setCodEnabled(bool $enabled): self;

    /**
     * @return int COD amount in tetri (cents)
     */
    public function getCodAmountCents(): int;

    /**
     * @param int $cents
     * @return $this
     */
    public function setCodAmountCents(int $cents): self;

    /**
     * @return string|null
     */
    public function getCodCollectedAt(): ?string;

    /**
     * @param string|null $timestamp
     * @return $this
     */
    public function setCodCollectedAt(?string $timestamp): self;

    /**
     * @return string|null
     */
    public function getCodReconciledAt(): ?string;

    /**
     * @param string|null $timestamp
     * @return $this
     */
    public function setCodReconciledAt(?string $timestamp): self;

    /**
     * @return string|null
     */
    public function getLabelUrl(): ?string;

    /**
     * @param string|null $url
     * @return $this
     */
    public function setLabelUrl(?string $url): self;

    /**
     * @return string|null
     */
    public function getLabelPdfStoredAt(): ?string;

    /**
     * @param string|null $path
     * @return $this
     */
    public function setLabelPdfStoredAt(?string $path): self;

    /**
     * @return string|null
     */
    public function getCreatedAt(): ?string;

    /**
     * @return string|null
     */
    public function getUpdatedAt(): ?string;

    /**
     * @return string|null
     */
    public function getLastPolledAt(): ?string;

    /**
     * @param string|null $timestamp
     * @return $this
     */
    public function setLastPolledAt(?string $timestamp): self;

    /**
     * @return string|null
     */
    public function getNextPollAt(): ?string;

    /**
     * @param string|null $timestamp
     * @return $this
     */
    public function setNextPollAt(?string $timestamp): self;

    /**
     * @return string
     */
    public function getPollStrategy(): string;

    /**
     * @param string $strategy
     * @return $this
     */
    public function setPollStrategy(string $strategy): self;

    /**
     * @return string|null
     */
    public function getWebhookSecret(): ?string;

    /**
     * @param string|null $secret
     * @return $this
     */
    public function setWebhookSecret(?string $secret): self;

    /**
     * @return string|null
     */
    public function getFailedAt(): ?string;

    /**
     * @param string|null $timestamp
     * @return $this
     */
    public function setFailedAt(?string $timestamp): self;

    /**
     * @return string|null
     */
    public function getFailureReason(): ?string;

    /**
     * @param string|null $reason
     * @return $this
     */
    public function setFailureReason(?string $reason): self;

    /**
     * Carrier metadata stored as decoded array (repository handles JSON encode/decode).
     *
     * @return array<string, mixed>
     */
    public function getMetadata(): array;

    /**
     * @param array<string, mixed> $metadata
     * @return $this
     */
    public function setMetadata(array $metadata): self;
}
