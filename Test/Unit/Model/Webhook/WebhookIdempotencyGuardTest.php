<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Test\Unit\Model\Webhook;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shubo\ShippingCore\Api\Data\Dto\WebhookResult;
use Shubo\ShippingCore\Api\ShipmentEventRepositoryInterface;
use Shubo\ShippingCore\Model\Webhook\WebhookIdempotencyGuard;

/**
 * Unit tests for {@see WebhookIdempotencyGuard}.
 *
 * Covers:
 *  - handler-supplied external event id returned unchanged,
 *  - synthesis format when handler passes null (sha256:<64 hex>),
 *  - determinism of synthesized id across repeated invocations,
 *  - isDuplicate delegates to the repository.
 */
class WebhookIdempotencyGuardTest extends TestCase
{
    /** @var ShipmentEventRepositoryInterface&MockObject */
    private ShipmentEventRepositoryInterface $eventRepository;

    private WebhookIdempotencyGuard $guard;

    protected function setUp(): void
    {
        $this->eventRepository = $this->createMock(ShipmentEventRepositoryInterface::class);
        $this->guard = new WebhookIdempotencyGuard($this->eventRepository);
    }

    public function testResolveExternalEventIdReturnsHandlerValueUnchanged(): void
    {
        $result = new WebhookResult(
            status: WebhookResult::STATUS_ACCEPTED,
            carrierTrackingId: 'TRK-1',
            normalizedStatus: null,
            externalEventId: 'carrier-evt-abc-123',
            occurredAt: null,
            rawPayload: '{}',
        );

        self::assertSame(
            'carrier-evt-abc-123',
            $this->guard->resolveExternalEventId($result, '{}'),
        );
    }

    public function testResolveExternalEventIdSynthesisesSha256WhenHandlerGivesNull(): void
    {
        $result = new WebhookResult(
            status: WebhookResult::STATUS_ACCEPTED,
            carrierTrackingId: 'TRK-1',
            normalizedStatus: null,
            externalEventId: null,
            occurredAt: null,
            rawPayload: '{"foo":"bar"}',
        );

        $resolved = $this->guard->resolveExternalEventId($result, '{"foo":"bar"}');

        self::assertStringStartsWith('sha256:', $resolved);
        self::assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', $resolved);
    }

    public function testSynthesisedIdIsDeterministic(): void
    {
        $raw = '{"carrier":"wolt","event":"delivered"}';
        self::assertSame(
            $this->guard->synthesizeEventId($raw),
            $this->guard->synthesizeEventId($raw),
        );
        self::assertNotSame(
            $this->guard->synthesizeEventId($raw),
            $this->guard->synthesizeEventId($raw . ' '),
        );
    }

    public function testIsDuplicateDelegatesToRepository(): void
    {
        $this->eventRepository->expects(self::once())
            ->method('existsByExternalEventId')
            ->with('wolt', 'evt-42')
            ->willReturn(true);

        self::assertTrue($this->guard->isDuplicate('wolt', 'evt-42'));
    }
}
