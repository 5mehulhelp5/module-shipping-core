<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Api;

use Shubo\ShippingCore\Api\Data\ShipmentInterface;

/**
 * Drains the poll queue and refreshes shipment status from carriers that
 * do not support webhooks.
 *
 * @api
 */
interface TrackingPollerInterface
{
    /**
     * Drain the poll queue across all enabled carriers, respecting rate
     * limits and circuit-breaker state. Returns the number of shipments
     * polled.
     *
     * @param int $maxShipments
     * @return int
     */
    public function drainBatch(int $maxShipments = 500): int;

    /**
     * Poll a single shipment immediately (admin "refresh status" button).
     *
     * @param int $shipmentId
     * @return ShipmentInterface
     */
    public function pollOne(int $shipmentId): ShipmentInterface;
}
