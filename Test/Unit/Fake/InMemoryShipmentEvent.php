<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Test\Unit\Fake;

use Shubo\ShippingCore\Api\Data\ShipmentEventInterface;

/**
 * In-memory implementation of {@see ShipmentEventInterface} for tests that
 * need a mutable data holder but not the Magento AbstractModel machinery.
 *
 * Extracted from the anonymous class that lived inside FactoryStubs.php so
 * tests running inside the duka Docker container (where Magento's generated
 * ShipmentEventInterfaceFactory class exists and takes an ObjectManager
 * constructor arg) can use this directly instead of `new Factory()` with
 * zero args.
 */
class InMemoryShipmentEvent implements ShipmentEventInterface
{
    private ?int $eventId = null;
    private int $shipmentId = 0;
    private string $carrierCode = '';
    private string $eventType = '';
    private ?string $carrierStatusRaw = null;
    private ?string $normalizedStatus = null;
    private ?string $occurredAt = null;
    private ?string $receivedAt = null;
    private string $source = '';
    private ?string $externalEventId = null;
    /** @var array<string, mixed> */
    private array $rawPayload = [];

    public function getEventId(): ?int
    {
        return $this->eventId;
    }

    public function getShipmentId(): int
    {
        return $this->shipmentId;
    }

    public function setShipmentId(int $shipmentId): self
    {
        $this->shipmentId = $shipmentId;
        return $this;
    }

    public function getCarrierCode(): string
    {
        return $this->carrierCode;
    }

    public function setCarrierCode(string $carrierCode): self
    {
        $this->carrierCode = $carrierCode;
        return $this;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function setEventType(string $eventType): self
    {
        $this->eventType = $eventType;
        return $this;
    }

    public function getCarrierStatusRaw(): ?string
    {
        return $this->carrierStatusRaw;
    }

    public function setCarrierStatusRaw(?string $status): self
    {
        $this->carrierStatusRaw = $status;
        return $this;
    }

    public function getNormalizedStatus(): ?string
    {
        return $this->normalizedStatus;
    }

    public function setNormalizedStatus(?string $status): self
    {
        $this->normalizedStatus = $status;
        return $this;
    }

    public function getOccurredAt(): ?string
    {
        return $this->occurredAt;
    }

    public function setOccurredAt(?string $timestamp): self
    {
        $this->occurredAt = $timestamp;
        return $this;
    }

    public function getReceivedAt(): ?string
    {
        return $this->receivedAt;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): self
    {
        $this->source = $source;
        return $this;
    }

    public function getExternalEventId(): ?string
    {
        return $this->externalEventId;
    }

    public function setExternalEventId(?string $externalEventId): self
    {
        $this->externalEventId = $externalEventId;
        return $this;
    }

    public function getRawPayload(): array
    {
        return $this->rawPayload;
    }

    public function setRawPayload(array $payload): self
    {
        $this->rawPayload = $payload;
        return $this;
    }
}
