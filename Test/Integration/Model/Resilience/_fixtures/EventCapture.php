<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Test\Integration\Model\Resilience\Fixtures;

use Magento\Framework\Event\ManagerInterface as EventManagerInterface;

/**
 * Test-only spy that wraps the real {@see EventManagerInterface} and
 * records every dispatch. Forwards to the wrapped manager so unrelated
 * observers still fire — the spy is deliberately non-intrusive.
 *
 * Used by {@see \Shubo\ShippingCore\Test\Integration\Model\Resilience\CircuitBreakerIntegrationTest}
 * which installs an instance into the shared-instances slot of the
 * production ObjectManager before resolving CircuitBreaker.
 */
class EventCapture implements EventManagerInterface
{
    /** @var list<array{name: string, data: array<string, mixed>}> */
    private array $events = [];

    public function __construct(private readonly EventManagerInterface $inner)
    {
    }

    /**
     * @inheritDoc
     */
    public function dispatch($eventName, array $data = [])
    {
        $this->events[] = ['name' => (string)$eventName, 'data' => $data];
        return $this->inner->dispatch($eventName, $data);
    }

    /**
     * Return every captured dispatch whose event name matches `$name`,
     * in dispatch order.
     *
     * @return list<array{name: string, data: array<string, mixed>}>
     */
    public function eventsNamed(string $name): array
    {
        return array_values(array_filter(
            $this->events,
            static fn (array $e): bool => $e['name'] === $name,
        ));
    }
}
