<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Test\Unit\Model\Carrier;

use PHPUnit\Framework\TestCase;
use Shubo\ShippingCore\Api\Data\ShipmentInterface;
use Shubo\ShippingCore\Model\Carrier\PickupInStoreCapabilities;

/**
 * Unit tests for {@see PickupInStoreCapabilities}.
 *
 * Pure value-bag verification — one assertion per capability method.
 * The tests exist so a future "I'll just flip this flag" change has a
 * red bar to walk through; they do not exercise registry composition
 * (covered separately by CarrierRegistry tests).
 */
class PickupInStoreCapabilitiesTest extends TestCase
{
    private PickupInStoreCapabilities $capabilities;

    protected function setUp(): void
    {
        $this->capabilities = new PickupInStoreCapabilities();
    }

    public function testCode(): void
    {
        self::assertSame('shubopickup', $this->capabilities->code());
    }

    public function testDisplayName(): void
    {
        self::assertSame('Pickup in store', $this->capabilities->displayName());
    }

    public function testSupportsWebhooks(): void
    {
        self::assertFalse($this->capabilities->supportsWebhooks());
    }

    public function testSupportsSandbox(): void
    {
        self::assertTrue($this->capabilities->supportsSandbox());
    }

    public function testSupportsCodReconciliationApi(): void
    {
        self::assertFalse($this->capabilities->supportsCodReconciliationApi());
    }

    public function testSupportsPudo(): void
    {
        self::assertFalse($this->capabilities->supportsPudo());
    }

    public function testSupportsExpress(): void
    {
        self::assertFalse($this->capabilities->supportsExpress());
    }

    public function testSupportsMultiParcel(): void
    {
        self::assertFalse($this->capabilities->supportsMultiParcel());
    }

    public function testSupportsReturns(): void
    {
        self::assertFalse($this->capabilities->supportsReturns());
    }

    public function testSupportsCancelAfterPickup(): void
    {
        self::assertTrue($this->capabilities->supportsCancelAfterPickup());
    }

    public function testRatelimitPerMinute(): void
    {
        self::assertSame(600, $this->capabilities->ratelimitPerMinute());
    }

    public function testCheckoutTimeoutMs(): void
    {
        self::assertSame(100, $this->capabilities->checkoutTimeoutMs());
    }

    public function testServiceAreaSubdivisions(): void
    {
        self::assertSame(
            [
                'GE-TB', 'GE-AB', 'GE-AJ', 'GE-GU', 'GE-IM', 'GE-KA',
                'GE-KK', 'GE-MM', 'GE-RL', 'GE-SJ', 'GE-SK', 'GE-SZ',
            ],
            $this->capabilities->serviceAreaSubdivisions(),
        );
    }

    public function testProducibleStatuses(): void
    {
        // No IN_TRANSIT — pickup skips the courier states.
        self::assertSame(
            [
                ShipmentInterface::STATUS_PENDING,
                ShipmentInterface::STATUS_READY_FOR_PICKUP,
                ShipmentInterface::STATUS_DELIVERED,
                ShipmentInterface::STATUS_CANCELLED,
            ],
            $this->capabilities->producibleStatuses(),
        );
    }
}
