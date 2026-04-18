<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Model\Shipment;

use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Shubo\ShippingCore\Api\Data\Search\ShipmentSearchResultsInterface;
use Shubo\ShippingCore\Api\Data\Search\ShipmentSearchResultsInterfaceFactory;
use Shubo\ShippingCore\Api\Data\ShipmentInterface;
use Shubo\ShippingCore\Api\ShipmentRepositoryInterface;
use Shubo\ShippingCore\Model\Data\Shipment;
use Shubo\ShippingCore\Model\Data\ShipmentFactory;
use Shubo\ShippingCore\Model\ResourceModel\Shipment as ShipmentResource;
use Shubo\ShippingCore\Model\ResourceModel\Shipment\Collection as ShipmentCollection;
use Shubo\ShippingCore\Model\ResourceModel\Shipment\CollectionFactory as ShipmentCollectionFactory;

/**
 * Repository for {@see ShipmentInterface} persistence.
 *
 * Lookups by `(carrier_code, client_tracking_code)` and
 * `(carrier_code, carrier_tracking_id)` use the SearchCriteria path through
 * the collection processor, which keeps the query surface consistent with
 * the rest of Magento and honours any admin-side column filters.
 *
 * `getDuePolls()` is implemented directly on the collection (no
 * SearchCriteria) because the query is hot and uses two indexed columns
 * (`status`, `next_poll_at`) that don't warrant the generic filter-group
 * roundtrip.
 */
class ShipmentRepository implements ShipmentRepositoryInterface
{
    public function __construct(
        private readonly ShipmentResource $resource,
        private readonly ShipmentFactory $shipmentFactory,
        private readonly ShipmentCollectionFactory $collectionFactory,
        private readonly CollectionProcessorInterface $collectionProcessor,
        private readonly ShipmentSearchResultsInterfaceFactory $searchResultsFactory,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly SortOrderBuilder $sortOrderBuilder,
    ) {
    }

    public function getById(int $shipmentId): ShipmentInterface
    {
        $shipment = $this->shipmentFactory->create();
        $this->resource->load($shipment, $shipmentId, ShipmentInterface::FIELD_SHIPMENT_ID);
        if ($shipment->getShipmentId() === null) {
            throw NoSuchEntityException::singleField(ShipmentInterface::FIELD_SHIPMENT_ID, (string)$shipmentId);
        }
        return $shipment;
    }

    public function getByClientTrackingCode(string $code): ShipmentInterface
    {
        /** @var ShipmentCollection $collection */
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter(ShipmentInterface::FIELD_CLIENT_TRACKING_CODE, $code);
        $collection->setPageSize(1);
        /** @var Shipment|null $item */
        $item = $collection->getFirstItem();
        if ($item === null || $item->getShipmentId() === null) {
            throw NoSuchEntityException::singleField(
                ShipmentInterface::FIELD_CLIENT_TRACKING_CODE,
                $code,
            );
        }
        return $item;
    }

    public function getByCarrierTrackingId(string $carrierCode, string $trackingId): ShipmentInterface
    {
        /** @var ShipmentCollection $collection */
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter(ShipmentInterface::FIELD_CARRIER_CODE, $carrierCode);
        $collection->addFieldToFilter(ShipmentInterface::FIELD_CARRIER_TRACKING_ID, $trackingId);
        $collection->setPageSize(1);
        /** @var Shipment|null $item */
        $item = $collection->getFirstItem();
        if ($item === null || $item->getShipmentId() === null) {
            throw NoSuchEntityException::singleField(
                ShipmentInterface::FIELD_CARRIER_TRACKING_ID,
                $trackingId,
            );
        }
        return $item;
    }

    public function save(ShipmentInterface $shipment): ShipmentInterface
    {
        if (!$shipment instanceof Shipment) {
            throw new CouldNotSaveException(
                __('ShipmentRepository::save requires the Model\\Data implementation.'),
            );
        }
        try {
            $this->resource->save($shipment);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(
                __('Could not save shipment: %1', $e->getMessage()),
                $e,
            );
        }
        return $shipment;
    }

    public function getList(SearchCriteriaInterface $criteria): ShipmentSearchResultsInterface
    {
        /** @var ShipmentCollection $collection */
        $collection = $this->collectionFactory->create();
        $this->collectionProcessor->process($criteria, $collection);

        /** @var list<ShipmentInterface> $items */
        $items = array_values($collection->getItems());

        $results = $this->searchResultsFactory->create();
        $results->setSearchCriteria($criteria);
        $results->setItems($items);
        $results->setTotalCount($collection->getSize());
        return $results;
    }

    /**
     * @return list<ShipmentInterface>
     */
    public function getDuePolls(int $limit, ?string $carrierCode = null): array
    {
        $builder = $this->searchCriteriaBuilder;
        $builder->addFilter(ShipmentInterface::FIELD_NEXT_POLL_AT, gmdate('Y-m-d H:i:s'), 'lteq');
        $builder->addFilter(ShipmentInterface::FIELD_STATUS, [
            ShipmentInterface::STATUS_DELIVERED,
            ShipmentInterface::STATUS_RETURNED_TO_SENDER,
            ShipmentInterface::STATUS_CANCELLED,
            ShipmentInterface::STATUS_FAILED,
        ], 'nin');
        if ($carrierCode !== null) {
            $builder->addFilter(ShipmentInterface::FIELD_CARRIER_CODE, $carrierCode);
        }
        $builder->setPageSize(max(1, $limit));
        $builder->setCurrentPage(1);

        $sortOrder = $this->sortOrderBuilder
            ->setField(ShipmentInterface::FIELD_NEXT_POLL_AT)
            ->setDirection(SortOrder::SORT_ASC)
            ->create();
        $builder->addSortOrder($sortOrder);

        $criteria = $builder->create();

        /** @var list<ShipmentInterface> $items */
        $items = $this->getList($criteria)->getItems();
        return $items;
    }
}
