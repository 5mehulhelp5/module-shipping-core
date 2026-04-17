<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Api;

use Magento\Framework\Api\SearchCriteriaInterface;
use Shubo\ShippingCore\Api\Data\Search\ShipmentSearchResultsInterface;
use Shubo\ShippingCore\Api\Data\ShipmentInterface;

/**
 * Repository for shipment persistence and retrieval.
 *
 * @api
 */
interface ShipmentRepositoryInterface
{
    /**
     * Load a shipment by its internal ID.
     *
     * @param int $shipmentId
     * @return ShipmentInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getById(int $shipmentId): ShipmentInterface;

    /**
     * Load a shipment by its client tracking code (our UUIDv7).
     *
     * @param string $code
     * @return ShipmentInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getByClientTrackingCode(string $code): ShipmentInterface;

    /**
     * Load a shipment by the carrier's tracking identifier.
     *
     * @param string $carrierCode
     * @param string $trackingId
     * @return ShipmentInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getByCarrierTrackingId(string $carrierCode, string $trackingId): ShipmentInterface;

    /**
     * Persist a shipment.
     *
     * @param ShipmentInterface $shipment
     * @return ShipmentInterface
     * @throws \Magento\Framework\Exception\CouldNotSaveException
     */
    public function save(ShipmentInterface $shipment): ShipmentInterface;

    /**
     * List shipments matching search criteria.
     *
     * @param SearchCriteriaInterface $criteria
     * @return ShipmentSearchResultsInterface
     */
    public function getList(SearchCriteriaInterface $criteria): ShipmentSearchResultsInterface;

    /**
     * Shipments whose next_poll_at has elapsed, ordered by next_poll_at ASC.
     *
     * @param int         $limit
     * @param string|null $carrierCode Filter by carrier code (NULL = all enabled carriers).
     * @return list<ShipmentInterface>
     */
    public function getDuePolls(int $limit, ?string $carrierCode = null): array;
}
