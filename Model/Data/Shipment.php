<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Model\Data;

use Magento\Framework\Model\AbstractModel;
use Shubo\ShippingCore\Api\Data\ShipmentInterface;
use Shubo\ShippingCore\Model\ResourceModel\Shipment as ShipmentResource;

/**
 * Active-record model for a marketplace shipment row.
 *
 * Field semantics and monetary conventions (integer tetri) are documented
 * on {@see ShipmentInterface}. JSON-backed fields (delivery_address,
 * metadata) are encoded/decoded by the repository at save/load time so
 * callers always see decoded arrays.
 */
class Shipment extends AbstractModel implements ShipmentInterface
{
    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init(ShipmentResource::class);
        $this->setIdFieldName(self::FIELD_SHIPMENT_ID);
    }

    public function getShipmentId(): ?int
    {
        $v = $this->getData(self::FIELD_SHIPMENT_ID);
        return $v === null ? null : (int)$v;
    }

    public function getMagentoShipmentId(): ?int
    {
        $v = $this->getData(self::FIELD_MAGENTO_SHIPMENT_ID);
        return $v === null ? null : (int)$v;
    }

    public function setMagentoShipmentId(?int $magentoShipmentId): self
    {
        $this->setData(self::FIELD_MAGENTO_SHIPMENT_ID, $magentoShipmentId);
        return $this;
    }

    public function getOrderId(): int
    {
        return (int)$this->getData(self::FIELD_ORDER_ID);
    }

    public function setOrderId(int $orderId): self
    {
        $this->setData(self::FIELD_ORDER_ID, $orderId);
        return $this;
    }

    public function getMerchantId(): int
    {
        return (int)$this->getData(self::FIELD_MERCHANT_ID);
    }

    public function setMerchantId(int $merchantId): self
    {
        $this->setData(self::FIELD_MERCHANT_ID, $merchantId);
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

    public function getCarrierTrackingId(): ?string
    {
        $v = $this->getData(self::FIELD_CARRIER_TRACKING_ID);
        return $v === null ? null : (string)$v;
    }

    public function setCarrierTrackingId(?string $carrierTrackingId): self
    {
        $this->setData(self::FIELD_CARRIER_TRACKING_ID, $carrierTrackingId);
        return $this;
    }

    public function getClientTrackingCode(): string
    {
        return (string)$this->getData(self::FIELD_CLIENT_TRACKING_CODE);
    }

    public function setClientTrackingCode(string $clientTrackingCode): self
    {
        $this->setData(self::FIELD_CLIENT_TRACKING_CODE, $clientTrackingCode);
        return $this;
    }

    public function getStatus(): string
    {
        $v = $this->getData(self::FIELD_STATUS);
        return $v === null ? self::STATUS_PENDING : (string)$v;
    }

    public function setStatus(string $status): self
    {
        $this->setData(self::FIELD_STATUS, $status);
        return $this;
    }

    public function getPickupAddressId(): ?int
    {
        $v = $this->getData(self::FIELD_PICKUP_ADDRESS_ID);
        return $v === null ? null : (int)$v;
    }

    public function setPickupAddressId(?int $pickupAddressId): self
    {
        $this->setData(self::FIELD_PICKUP_ADDRESS_ID, $pickupAddressId);
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDeliveryAddress(): array
    {
        $v = $this->getData(self::FIELD_DELIVERY_ADDRESS_JSON);
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
     * @param array<string, mixed> $address
     */
    public function setDeliveryAddress(array $address): self
    {
        $this->setData(self::FIELD_DELIVERY_ADDRESS_JSON, $address);
        return $this;
    }

    public function getParcelWeightGrams(): int
    {
        return (int)$this->getData(self::FIELD_PARCEL_WEIGHT_GRAMS);
    }

    public function setParcelWeightGrams(int $grams): self
    {
        $this->setData(self::FIELD_PARCEL_WEIGHT_GRAMS, $grams);
        return $this;
    }

    public function getParcelValueCents(): int
    {
        return (int)$this->getData(self::FIELD_PARCEL_VALUE_CENTS);
    }

    public function setParcelValueCents(int $cents): self
    {
        $this->setData(self::FIELD_PARCEL_VALUE_CENTS, $cents);
        return $this;
    }

    public function isCodEnabled(): bool
    {
        return (int)$this->getData(self::FIELD_COD_ENABLED) === 1;
    }

    public function setCodEnabled(bool $enabled): self
    {
        $this->setData(self::FIELD_COD_ENABLED, $enabled ? 1 : 0);
        return $this;
    }

    public function getCodAmountCents(): int
    {
        return (int)$this->getData(self::FIELD_COD_AMOUNT_CENTS);
    }

    public function setCodAmountCents(int $cents): self
    {
        $this->setData(self::FIELD_COD_AMOUNT_CENTS, $cents);
        return $this;
    }

    public function getCodCollectedAt(): ?string
    {
        $v = $this->getData(self::FIELD_COD_COLLECTED_AT);
        return $v === null ? null : (string)$v;
    }

    public function setCodCollectedAt(?string $timestamp): self
    {
        $this->setData(self::FIELD_COD_COLLECTED_AT, $timestamp);
        return $this;
    }

    public function getCodReconciledAt(): ?string
    {
        $v = $this->getData(self::FIELD_COD_RECONCILED_AT);
        return $v === null ? null : (string)$v;
    }

    public function setCodReconciledAt(?string $timestamp): self
    {
        $this->setData(self::FIELD_COD_RECONCILED_AT, $timestamp);
        return $this;
    }

    public function getLabelUrl(): ?string
    {
        $v = $this->getData(self::FIELD_LABEL_URL);
        return $v === null ? null : (string)$v;
    }

    public function setLabelUrl(?string $url): self
    {
        $this->setData(self::FIELD_LABEL_URL, $url);
        return $this;
    }

    public function getLabelPdfStoredAt(): ?string
    {
        $v = $this->getData(self::FIELD_LABEL_PDF_STORED_AT);
        return $v === null ? null : (string)$v;
    }

    public function setLabelPdfStoredAt(?string $path): self
    {
        $this->setData(self::FIELD_LABEL_PDF_STORED_AT, $path);
        return $this;
    }

    public function getCreatedAt(): ?string
    {
        $v = $this->getData(self::FIELD_CREATED_AT);
        return $v === null ? null : (string)$v;
    }

    public function getUpdatedAt(): ?string
    {
        $v = $this->getData(self::FIELD_UPDATED_AT);
        return $v === null ? null : (string)$v;
    }

    public function getLastPolledAt(): ?string
    {
        $v = $this->getData(self::FIELD_LAST_POLLED_AT);
        return $v === null ? null : (string)$v;
    }

    public function setLastPolledAt(?string $timestamp): self
    {
        $this->setData(self::FIELD_LAST_POLLED_AT, $timestamp);
        return $this;
    }

    public function getNextPollAt(): ?string
    {
        $v = $this->getData(self::FIELD_NEXT_POLL_AT);
        return $v === null ? null : (string)$v;
    }

    public function setNextPollAt(?string $timestamp): self
    {
        $this->setData(self::FIELD_NEXT_POLL_AT, $timestamp);
        return $this;
    }

    public function getPollStrategy(): string
    {
        $v = $this->getData(self::FIELD_POLL_STRATEGY);
        return $v === null ? self::POLL_STRATEGY_ADAPTIVE : (string)$v;
    }

    public function setPollStrategy(string $strategy): self
    {
        $this->setData(self::FIELD_POLL_STRATEGY, $strategy);
        return $this;
    }

    public function getWebhookSecret(): ?string
    {
        $v = $this->getData(self::FIELD_WEBHOOK_SECRET);
        return $v === null ? null : (string)$v;
    }

    public function setWebhookSecret(?string $secret): self
    {
        $this->setData(self::FIELD_WEBHOOK_SECRET, $secret);
        return $this;
    }

    public function getFailedAt(): ?string
    {
        $v = $this->getData(self::FIELD_FAILED_AT);
        return $v === null ? null : (string)$v;
    }

    public function setFailedAt(?string $timestamp): self
    {
        $this->setData(self::FIELD_FAILED_AT, $timestamp);
        return $this;
    }

    public function getFailureReason(): ?string
    {
        $v = $this->getData(self::FIELD_FAILURE_REASON);
        return $v === null ? null : (string)$v;
    }

    public function setFailureReason(?string $reason): self
    {
        $this->setData(self::FIELD_FAILURE_REASON, $reason);
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        $v = $this->getData(self::FIELD_METADATA_JSON);
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
     * @param array<string, mixed> $metadata
     */
    public function setMetadata(array $metadata): self
    {
        $this->setData(self::FIELD_METADATA_JSON, $metadata);
        return $this;
    }
}
