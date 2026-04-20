<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Test\Unit\Model\Data;

use PHPUnit\Framework\TestCase;
use Shubo\ShippingCore\Api\Data\ShipmentEventInterface;
use Shubo\ShippingCore\Model\Data\ShipmentEvent;

/**
 * Unit tests for the {@see ShipmentEvent} AbstractModel getters/setters.
 *
 * The resource model handles JSON encode/decode at the save/load boundary,
 * but the model itself must tolerate both raw (string) and decoded (array)
 * states — the latter is what callers see after `setRawPayload()` and the
 * former is what DB-loaded rows look like before `_afterLoad` runs.
 */
class ShipmentEventTest extends TestCase
{
    private function newEvent(): ShipmentEvent
    {
        return $this->getMockBuilder(ShipmentEvent::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
    }

    public function testFluentSettersReturnSelf(): void
    {
        $event = $this->newEvent();
        self::assertSame($event, $event->setShipmentId(1));
        self::assertSame($event, $event->setCarrierCode('fake'));
        self::assertSame($event, $event->setEventType('status_change'));
        self::assertSame($event, $event->setCarrierStatusRaw('DLV'));
        self::assertSame($event, $event->setNormalizedStatus('delivered'));
        self::assertSame($event, $event->setOccurredAt('2024-01-01 12:00:00'));
        self::assertSame($event, $event->setSource('webhook'));
        self::assertSame($event, $event->setExternalEventId('evt-1'));
        self::assertSame($event, $event->setRawPayload(['a' => 'b']));
    }

    public function testGettersRoundTripFields(): void
    {
        $event = $this->newEvent();
        $event->setShipmentId(7);
        $event->setCarrierCode('fake');
        $event->setEventType(ShipmentEventInterface::EVENT_TYPE_DELIVERED);
        $event->setCarrierStatusRaw('DLV');
        $event->setNormalizedStatus(ShipmentEventInterface::EVENT_TYPE_DELIVERED);
        $event->setOccurredAt('2024-01-01 12:00:00');
        $event->setSource(ShipmentEventInterface::SOURCE_WEBHOOK);
        $event->setExternalEventId('evt-1');
        $event->setRawPayload(['status' => 'DLV']);

        self::assertSame(7, $event->getShipmentId());
        self::assertSame('fake', $event->getCarrierCode());
        self::assertSame('delivered', $event->getEventType());
        self::assertSame('DLV', $event->getCarrierStatusRaw());
        self::assertSame('delivered', $event->getNormalizedStatus());
        self::assertSame('2024-01-01 12:00:00', $event->getOccurredAt());
        self::assertSame(ShipmentEventInterface::SOURCE_WEBHOOK, $event->getSource());
        self::assertSame('evt-1', $event->getExternalEventId());
        self::assertSame(['status' => 'DLV'], $event->getRawPayload());
    }

    public function testNullableGettersDefaultToNull(): void
    {
        $event = $this->newEvent();
        self::assertNull($event->getEventId());
        self::assertNull($event->getCarrierStatusRaw());
        self::assertNull($event->getNormalizedStatus());
        self::assertNull($event->getOccurredAt());
        self::assertNull($event->getReceivedAt());
        self::assertNull($event->getExternalEventId());
    }

    public function testGetRawPayloadDecodesJsonString(): void
    {
        $event = $this->newEvent();
        // Simulate the pre-`_afterLoad` state: raw JSON string in the column.
        $event->setData(ShipmentEventInterface::FIELD_RAW_PAYLOAD_JSON, '{"a":"b","n":1}');

        self::assertSame(['a' => 'b', 'n' => 1], $event->getRawPayload());
    }

    public function testGetRawPayloadReturnsEmptyArrayOnInvalidJson(): void
    {
        $event = $this->newEvent();
        $event->setData(ShipmentEventInterface::FIELD_RAW_PAYLOAD_JSON, 'not-json');
        self::assertSame([], $event->getRawPayload());
    }

    public function testGetRawPayloadReturnsEmptyArrayOnNull(): void
    {
        $event = $this->newEvent();
        self::assertSame([], $event->getRawPayload());
    }
}
