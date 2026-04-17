<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Api;

use Shubo\ShippingCore\Api\Data\ShipmentEventInterface;

/**
 * Repository for append-only shipment event rows.
 *
 * @api
 */
interface ShipmentEventRepositoryInterface
{
    /**
     * Persist a shipment event.
     *
     * @param ShipmentEventInterface $event
     * @return ShipmentEventInterface
     * @throws \Magento\Framework\Exception\CouldNotSaveException
     */
    public function save(ShipmentEventInterface $event): ShipmentEventInterface;

    /**
     * Events belonging to a shipment, newest first.
     *
     * @param int $shipmentId
     * @param int $limit
     * @return list<ShipmentEventInterface>
     */
    public function getByShipmentId(int $shipmentId, int $limit = 100): array;

    /**
     * Whether an event with the given carrier-code + external-event-id has
     * already been recorded (webhook replay guard).
     *
     * @param string $carrierCode
     * @param string $externalEventId
     * @return bool
     */
    public function existsByExternalEventId(string $carrierCode, string $externalEventId): bool;
}
