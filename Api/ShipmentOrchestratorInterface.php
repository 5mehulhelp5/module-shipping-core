<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Api;

use Shubo\ShippingCore\Api\Data\Dto\ShipmentRequest;
use Shubo\ShippingCore\Api\Data\ShipmentInterface;

/**
 * The single entry point external callers use to create/cancel/retry
 * shipments.
 *
 * Wraps adapter calls in circuit breaker + retry + rate limiter; all
 * operations are idempotent.
 *
 * @api
 */
interface ShipmentOrchestratorInterface
{
    /**
     * Idempotent dispatch. Calling with the same client tracking code twice
     * returns the existing shipment.
     *
     * @param ShipmentRequest $request
     * @return ShipmentInterface
     * @throws \Shubo\ShippingCore\Exception\NoCarrierAvailableException
     * @throws \Shubo\ShippingCore\Exception\CircuitOpenException
     * @throws \Shubo\ShippingCore\Exception\ShipmentDispatchFailedException
     */
    public function dispatch(ShipmentRequest $request): ShipmentInterface;

    /**
     * Idempotent cancel. Sets shipment status to cancel_requested, calls the
     * adapter, sets cancelled on success.
     *
     * @param int         $shipmentId
     * @param string|null $reason
     * @return ShipmentInterface
     */
    public function cancel(int $shipmentId, ?string $reason = null): ShipmentInterface;

    /**
     * Re-dispatch a dead-letter entry (admin "retry" button).
     *
     * @param int $shipmentId
     * @return ShipmentInterface
     */
    public function retry(int $shipmentId): ShipmentInterface;
}
