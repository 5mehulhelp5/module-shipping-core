<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Model\Data;

use Magento\Framework\ObjectManagerInterface;

/**
 * Factory for {@see Shipment}.
 *
 * Magento's di:compile / di:generate pipeline will normally produce this
 * factory in `generated/code/`. We ship an explicit hand-written copy here
 * so:
 *
 *   - Unit tests that don't boot the code generator can still load the class.
 *   - The module compiles standalone outside a Magento install (the
 *     open-source-first invariant, per CLAUDE.md).
 *
 * Semantics mirror the generator output exactly — `create()` delegates to the
 * ObjectManager and accepts optional constructor arguments.
 *
 * @api
 */
class ShipmentFactory
{
    /**
     * @param ObjectManagerInterface $objectManager
     * @param class-string           $instanceName
     */
    public function __construct(
        protected readonly ObjectManagerInterface $objectManager,
        protected readonly string $instanceName = Shipment::class,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data = []): Shipment
    {
        /** @var Shipment $instance */
        $instance = $this->objectManager->create($this->instanceName, $data);
        return $instance;
    }
}
