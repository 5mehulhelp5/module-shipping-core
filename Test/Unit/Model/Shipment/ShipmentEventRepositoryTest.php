<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Test\Unit\Model\Shipment;

use Magento\Framework\Exception\CouldNotSaveException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shubo\ShippingCore\Api\Data\ShipmentEventInterface;
use Shubo\ShippingCore\Model\Data\ShipmentEvent;
use Shubo\ShippingCore\Model\ResourceModel\ShipmentEvent as ShipmentEventResource;
use Shubo\ShippingCore\Model\ResourceModel\ShipmentEvent\Collection as ShipmentEventCollection;
use Shubo\ShippingCore\Model\ResourceModel\ShipmentEvent\CollectionFactory as ShipmentEventCollectionFactory;
use Shubo\ShippingCore\Model\Shipment\ShipmentEventRepository;

/**
 * Unit tests for {@see ShipmentEventRepository}.
 *
 * Covers the full interface contract:
 * - {@see ShipmentEventRepositoryInterface::save()} new + existing (update) rows
 * - save() wrapping of resource exceptions in {@see CouldNotSaveException}
 * - save() rejecting non-{@see ShipmentEvent} instances (required so JSON
 *   encode/decode in the resource model is never bypassed)
 * - {@see ShipmentEventRepositoryInterface::getByShipmentId()} ordering +
 *   limit cap (min 1)
 * - {@see ShipmentEventRepositoryInterface::existsByExternalEventId()} both
 *   positive and negative cases
 */
class ShipmentEventRepositoryTest extends TestCase
{
    /** @var ShipmentEventResource&MockObject */
    private ShipmentEventResource $resource;

    /** @var ShipmentEventCollectionFactory&MockObject */
    private ShipmentEventCollectionFactory $collectionFactory;

    private ShipmentEventRepository $repository;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(ShipmentEventResource::class);
        $this->collectionFactory = $this->createMock(ShipmentEventCollectionFactory::class);
        $this->repository = new ShipmentEventRepository(
            $this->resource,
            $this->collectionFactory,
        );
    }

    public function testSavePersistsNewEventViaResource(): void
    {
        $event = $this->newEvent(
            shipmentId: 42,
            carrierCode: 'fake',
            eventType: ShipmentEventInterface::EVENT_TYPE_WEBHOOK_RECEIVED,
            source: ShipmentEventInterface::SOURCE_WEBHOOK,
        );

        $this->resource->expects($this->once())
            ->method('save')
            ->with($event)
            ->willReturnSelf();

        $result = $this->repository->save($event);

        self::assertSame($event, $result, 'Repository returns the same instance after save');
    }

    public function testSaveOnExistingRowPersistsUpdatedFields(): void
    {
        $event = $this->newEvent(
            shipmentId: 7,
            carrierCode: 'fake',
            eventType: ShipmentEventInterface::EVENT_TYPE_STATUS_CHANGE,
            source: ShipmentEventInterface::SOURCE_POLL,
        );
        // Simulate an already-persisted row by setting the event_id as if loaded.
        $event->setData(ShipmentEventInterface::FIELD_EVENT_ID, 1001);

        $this->resource->expects($this->once())
            ->method('save')
            ->with($event)
            ->willReturnSelf();

        $result = $this->repository->save($event);

        self::assertSame(1001, $result->getEventId());
    }

    public function testSaveWrapsResourceExceptionInCouldNotSave(): void
    {
        $event = $this->newEvent(
            shipmentId: 1,
            carrierCode: 'fake',
            eventType: ShipmentEventInterface::EVENT_TYPE_WEBHOOK_RECEIVED,
            source: ShipmentEventInterface::SOURCE_WEBHOOK,
        );

        $this->resource->expects($this->once())
            ->method('save')
            ->with($event)
            ->willThrowException(new \RuntimeException('duplicate entry'));

        $this->expectException(CouldNotSaveException::class);
        $this->expectExceptionMessageMatches('/duplicate entry/');

        $this->repository->save($event);
    }

    public function testSaveRejectsForeignInterfaceImplementation(): void
    {
        $foreign = $this->createMock(ShipmentEventInterface::class);

        $this->resource->expects($this->never())->method('save');

        $this->expectException(CouldNotSaveException::class);
        $this->repository->save($foreign);
    }

    public function testGetByShipmentIdAppliesFiltersOrderAndLimit(): void
    {
        $expectedItems = [
            $this->newEvent(shipmentId: 42, carrierCode: 'fake', eventType: 'status_change', source: 'poll'),
            $this->newEvent(shipmentId: 42, carrierCode: 'fake', eventType: 'poll_noop', source: 'poll'),
        ];

        $collection = $this->createMock(ShipmentEventCollection::class);
        $collection->expects($this->once())
            ->method('addFieldToFilter')
            ->with(ShipmentEventInterface::FIELD_SHIPMENT_ID, ['eq' => 42])
            ->willReturnSelf();
        $collection->expects($this->exactly(2))
            ->method('setOrder')
            ->willReturnSelf();
        $collection->expects($this->once())
            ->method('setPageSize')
            ->with(25)
            ->willReturnSelf();
        $collection->expects($this->once())
            ->method('setCurPage')
            ->with(1)
            ->willReturnSelf();
        $collection->expects($this->once())
            ->method('getItems')
            ->willReturn($expectedItems);

        $this->collectionFactory->expects($this->once())
            ->method('create')
            ->willReturn($collection);

        $result = $this->repository->getByShipmentId(42, 25);

        self::assertCount(2, $result);
        self::assertSame($expectedItems[0], $result[0]);
        self::assertSame($expectedItems[1], $result[1]);
    }

    public function testGetByShipmentIdCapsZeroLimitToOne(): void
    {
        $collection = $this->createMock(ShipmentEventCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setOrder')->willReturnSelf();
        $collection->expects($this->once())
            ->method('setPageSize')
            ->with(1)
            ->willReturnSelf();
        $collection->method('setCurPage')->willReturnSelf();
        $collection->method('getItems')->willReturn([]);

        $this->collectionFactory->method('create')->willReturn($collection);

        $result = $this->repository->getByShipmentId(99, 0);

        self::assertSame([], $result);
    }

    public function testExistsByExternalEventIdReturnsTrueWhenRowPresent(): void
    {
        $collection = $this->createMock(ShipmentEventCollection::class);
        $collection->expects($this->exactly(2))
            ->method('addFieldToFilter')
            ->willReturnSelf();
        $collection->expects($this->once())
            ->method('setPageSize')
            ->with(1)
            ->willReturnSelf();
        $collection->expects($this->once())
            ->method('getSize')
            ->willReturn(1);

        $this->collectionFactory->method('create')->willReturn($collection);

        self::assertTrue($this->repository->existsByExternalEventId('fake', 'evt-123'));
    }

    public function testExistsByExternalEventIdReturnsFalseWhenRowAbsent(): void
    {
        $collection = $this->createMock(ShipmentEventCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->expects($this->once())
            ->method('getSize')
            ->willReturn(0);

        $this->collectionFactory->method('create')->willReturn($collection);

        self::assertFalse($this->repository->existsByExternalEventId('fake', 'evt-missing'));
    }

    /**
     * Construct a fresh {@see ShipmentEvent} without touching the generated
     * factory. `AbstractModel::_construct` calls `_init()` which requires a
     * resource-model class name; we can skip that by calling the parent
     * constructor only with dummies that implement the expected interface.
     */
    private function newEvent(
        int $shipmentId,
        string $carrierCode,
        string $eventType,
        string $source,
    ): ShipmentEvent {
        $event = $this->getMockBuilder(ShipmentEvent::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        /** @var ShipmentEvent $event */
        $event->setShipmentId($shipmentId);
        $event->setCarrierCode($carrierCode);
        $event->setEventType($eventType);
        $event->setSource($source);
        return $event;
    }
}
