<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Model\Shipment;

use Magento\Framework\Exception\CouldNotSaveException;
use Shubo\ShippingCore\Api\Data\ShipmentEventInterface;
use Shubo\ShippingCore\Api\ShipmentEventRepositoryInterface;
use Shubo\ShippingCore\Model\Data\ShipmentEvent;
use Shubo\ShippingCore\Model\ResourceModel\ShipmentEvent as ShipmentEventResource;
use Shubo\ShippingCore\Model\ResourceModel\ShipmentEvent\Collection as ShipmentEventCollection;
use Shubo\ShippingCore\Model\ResourceModel\ShipmentEvent\CollectionFactory as ShipmentEventCollectionFactory;

/**
 * Repository for {@see ShipmentEventInterface} — append-only state log.
 *
 * Writes are funnelled through {@see self::save()}. The caller constructs the
 * model via the Magento-generated `ShipmentEventInterfaceFactory` (see the
 * TrackingPoller and WebhookDispatcher call sites), populates fields through
 * the fluent `set*` API, and hands it here for persistence. MySQL's unique
 * index on `(carrier_code, external_event_id)` is the source of truth for
 * webhook-replay dedup; a duplicate-key collision surfaces as
 * {@see CouldNotSaveException} so {@see \Shubo\ShippingCore\Model\Webhook\WebhookDispatcher}
 * can detect and classify the race.
 */
class ShipmentEventRepository implements ShipmentEventRepositoryInterface
{
    public function __construct(
        private readonly ShipmentEventResource $resource,
        private readonly ShipmentEventCollectionFactory $collectionFactory,
    ) {
    }

    public function save(ShipmentEventInterface $event): ShipmentEventInterface
    {
        if (!$event instanceof ShipmentEvent) {
            throw new CouldNotSaveException(
                __('ShipmentEventRepository::save requires the Model\\Data implementation.'),
            );
        }
        try {
            $this->resource->save($event);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(
                __('Could not save shipment event: %1', $e->getMessage()),
                $e,
            );
        }
        return $event;
    }

    /**
     * @return list<ShipmentEventInterface>
     */
    public function getByShipmentId(int $shipmentId, int $limit = 100): array
    {
        /** @var ShipmentEventCollection $collection */
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter(ShipmentEventInterface::FIELD_SHIPMENT_ID, ['eq' => $shipmentId]);
        $collection->setOrder(ShipmentEventInterface::FIELD_RECEIVED_AT, 'DESC');
        $collection->setOrder(ShipmentEventInterface::FIELD_EVENT_ID, 'DESC');
        $collection->setPageSize(max(1, $limit));
        $collection->setCurPage(1);

        /** @var list<ShipmentEventInterface> $items */
        $items = array_values($collection->getItems());
        return $items;
    }

    public function existsByExternalEventId(string $carrierCode, string $externalEventId): bool
    {
        /** @var ShipmentEventCollection $collection */
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter(ShipmentEventInterface::FIELD_CARRIER_CODE, $carrierCode);
        $collection->addFieldToFilter(ShipmentEventInterface::FIELD_EXTERNAL_EVENT_ID, $externalEventId);
        $collection->setPageSize(1);
        return $collection->getSize() > 0;
    }
}
