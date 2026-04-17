<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Api;

use Shubo\ShippingCore\Api\Data\Dto\CancelResponse;
use Shubo\ShippingCore\Api\Data\Dto\LabelResponse;
use Shubo\ShippingCore\Api\Data\Dto\QuoteRequest;
use Shubo\ShippingCore\Api\Data\Dto\QuoteResponse;
use Shubo\ShippingCore\Api\Data\Dto\ShipmentRequest;
use Shubo\ShippingCore\Api\Data\Dto\ShipmentResponse;
use Shubo\ShippingCore\Api\Data\Dto\StatusResponse;
use Shubo\ShippingCore\Api\Data\GeoCacheInterface;

/**
 * Primary adapter contract — every carrier module implements this interface.
 *
 * Adapters are thin: they map the carrier's native API to these normalized
 * calls. They do not touch the DB, do not dispatch Magento events, and do
 * not observe the order lifecycle. Orchestration happens in Core.
 *
 * @api
 */
interface CarrierGatewayInterface
{
    /**
     * Returns the carrier code used in the registry.
     *
     * @return string
     */
    public function code(): string;

    /**
     * Request a rate quote from the carrier.
     *
     * @param QuoteRequest $request
     * @return QuoteResponse
     */
    public function quote(QuoteRequest $request): QuoteResponse;

    /**
     * Create a shipment with the carrier. MUST be idempotent with respect to
     * {@see ShipmentRequest::$clientTrackingCode}.
     *
     * @param ShipmentRequest $request
     * @return ShipmentResponse
     */
    public function createShipment(ShipmentRequest $request): ShipmentResponse;

    /**
     * Cancel an existing carrier shipment.
     *
     * @param string      $carrierTrackingId
     * @param string|null $reason
     * @return CancelResponse
     */
    public function cancelShipment(string $carrierTrackingId, ?string $reason = null): CancelResponse;

    /**
     * Fetch the current status of a carrier shipment.
     *
     * @param string $carrierTrackingId
     * @return StatusResponse
     */
    public function getShipmentStatus(string $carrierTrackingId): StatusResponse;

    /**
     * Fetch the label PDF for a carrier shipment.
     *
     * @param string $carrierTrackingId
     * @return LabelResponse
     */
    public function fetchLabel(string $carrierTrackingId): LabelResponse;

    /**
     * List cities/service areas supported by the carrier.
     *
     * @return list<GeoCacheInterface>
     */
    public function listCities(): array;

    /**
     * List PUDO (pickup/drop-off) points.
     *
     * @param string|null $cityCode Carrier's own city external_id; NULL => all PUDOs.
     * @return list<GeoCacheInterface>
     */
    public function listPudos(?string $cityCode = null): array;
}
