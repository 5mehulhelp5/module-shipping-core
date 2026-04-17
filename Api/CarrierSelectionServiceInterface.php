<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Api;

use Shubo\ShippingCore\Api\Data\Dto\RateOption;
use Shubo\ShippingCore\Api\Data\Dto\ShipmentRequest;

/**
 * Picks an ordered list of eligible carriers for a shipment.
 *
 * @api
 */
interface CarrierSelectionServiceInterface
{
    /**
     * Ordered rate options for the given shipment request.
     *
     * Cheapest first by default, respecting merchant preference and any
     * resilience constraints (circuit-open carriers are skipped). Returns
     * an empty list if no carrier can serve.
     *
     * @param ShipmentRequest $request
     * @return list<RateOption>
     */
    public function selectFor(ShipmentRequest $request): array;
}
