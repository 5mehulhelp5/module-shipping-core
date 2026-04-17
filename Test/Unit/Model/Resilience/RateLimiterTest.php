<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Test\Unit\Model\Resilience;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shubo\ShippingCore\Model\Logging\StructuredLogger;
use Shubo\ShippingCore\Model\Resilience\RateLimiter;
use Shubo\ShippingCore\Model\Resilience\Sleeper;
use Shubo\ShippingCore\Model\ResourceModel\RateLimitState;

/**
 * Unit tests for {@see RateLimiter}. Covers under-limit, at-limit, window
 * rollover, DB error fail-closed, sequential "no over-issue" invariant,
 * and the blocking acquire path.
 *
 * Note: since the Redis primary path was removed (see RateLimiter class
 * docblock), every test here exercises the DB path through a mocked
 * {@see RateLimitState}. The authoritative concurrency proof lives in
 * `Test/Integration/Model/Resilience/RateLimiterConcurrencyTest`, which
 * spawns real worker processes against a real MySQL.
 */
class RateLimiterTest extends TestCase
{
    private const CARRIER = 'trackings_ge';
    private const RPM = 60;

    /** @var RateLimitState&MockObject */
    private RateLimitState $dbResource;

    /** @var ScopeConfigInterface&MockObject */
    private ScopeConfigInterface $scopeConfig;

    /** @var Sleeper&MockObject */
    private Sleeper $sleeper;

    /** @var DateTime&MockObject */
    private DateTime $dateTime;

    /** @var StructuredLogger&MockObject */
    private StructuredLogger $logger;

    private int $now = 1_700_000_000;

    private RateLimiter $limiter;

    protected function setUp(): void
    {
        $this->dbResource = $this->createMock(RateLimitState::class);
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->sleeper = $this->createMock(Sleeper::class);
        $this->dateTime = $this->createMock(DateTime::class);
        $this->logger = $this->createMock(StructuredLogger::class);

        $this->scopeConfig->method('getValue')->willReturnCallback(
            fn (string $path): ?string => $path === 'shubo_shipping/rate_limit/default_rpm'
                ? (string)self::RPM
                : null,
        );
        $this->dateTime->method('gmtTimestamp')->willReturnCallback(fn (): int => $this->now);

        $this->limiter = new RateLimiter(
            $this->dbResource,
            $this->scopeConfig,
            $this->dateTime,
            $this->sleeper,
            $this->logger,
        );
    }

    public function testAcquireUnderLimitSucceeds(): void
    {
        $this->dbResource->expects(self::once())
            ->method('incrementTokens')
            ->with(self::CARRIER, 1, self::RPM, $this->now)
            ->willReturn(true);
        $this->dbResource->method('fetchTokensUsed')->willReturn(1);

        self::assertTrue($this->limiter->acquire(self::CARRIER, 1));
    }

    public function testAcquireAtLimitFails(): void
    {
        $this->dbResource->expects(self::once())
            ->method('incrementTokens')
            ->with(self::CARRIER, 1, self::RPM, $this->now)
            ->willReturn(false);
        $this->dbResource->expects(self::never())->method('fetchTokensUsed');

        self::assertFalse($this->limiter->acquire(self::CARRIER, 1));
    }

    public function testAcquireFailsClosedWhenDbThrows(): void
    {
        $this->dbResource->method('incrementTokens')
            ->willThrowException(new \RuntimeException('mysql gone away'));
        // Observers / pollers must not treat a DB outage as "go ahead" —
        // that would blow past the carrier's stated limit.
        $this->logger->expects(self::atLeastOnce())->method('logRateLimit')
            ->with(self::CARRIER, -1);

        self::assertFalse($this->limiter->acquire(self::CARRIER, 1));
    }

    public function testWindowRolloverResetsTokens(): void
    {
        // RateLimitState::incrementTokens handles the window-reset
        // semantics internally (see its DB transaction). From RateLimiter's
        // POV, window rollover is just "the DB keeps returning true while
        // the logical cap is recomputed downstream."
        $calls = 0;
        $this->dbResource->method('incrementTokens')
            ->willReturnCallback(function () use (&$calls): bool {
                $calls++;
                // First RPM calls succeed, next one fails (within window),
                // then the caller bumps $now by 60s and the new window
                // succeeds again.
                if ($calls <= self::RPM) {
                    return true;
                }
                if ($calls === self::RPM + 1) {
                    return false;
                }
                return true;
            });
        $this->dbResource->method('fetchTokensUsed')->willReturn(0);

        for ($i = 0; $i < self::RPM; $i++) {
            self::assertTrue($this->limiter->acquire(self::CARRIER, 1));
        }
        self::assertFalse($this->limiter->acquire(self::CARRIER, 1));

        // Advance time one minute — new window.
        $this->now += 60;
        self::assertTrue($this->limiter->acquire(self::CARRIER, 1));
    }

    public function testConcurrentInvariantNoOverIssueSequential(): void
    {
        // Simulates the DB's atomic conditional UPDATE by tracking a
        // counter in the mock. Under sequential invocation we assert the
        // same invariant the DB transaction gives us under parallel
        // invocation: never more than $rpm acquisitions succeed.
        $used = 0;
        $this->dbResource->method('incrementTokens')
            ->willReturnCallback(static function (
                string $_carrier,
                int $tokens,
                int $rpm,
            ) use (&$used): bool {
                if ($used + $tokens > $rpm) {
                    return false;
                }
                $used += $tokens;
                return true;
            });
        $this->dbResource->method('fetchTokensUsed')
            ->willReturnCallback(static fn (): int => $used);

        $successes = 0;
        $rejects = 0;
        for ($i = 0; $i < self::RPM + 5; $i++) {
            if ($this->limiter->acquire(self::CARRIER, 1)) {
                $successes++;
            } else {
                $rejects++;
            }
        }

        self::assertSame(self::RPM, $successes, 'Exactly RPM calls must succeed.');
        self::assertSame(5, $rejects, 'Exactly the extras must be rejected.');
    }

    public function testAcquireBlockingReturnsZeroOnImmediateSuccess(): void
    {
        $this->dbResource->method('incrementTokens')->willReturn(true);
        $this->dbResource->method('fetchTokensUsed')->willReturn(1);
        $this->sleeper->expects(self::never())->method('sleepMs');

        self::assertSame(0, $this->limiter->acquireBlocking(self::CARRIER, 1, 1000));
    }

    public function testAcquireBlockingTimeout(): void
    {
        $this->dbResource->method('incrementTokens')->willReturn(false);
        // Count captured sleep ms to prove we actually blocked.
        $total = 0;
        $this->sleeper->method('sleepMs')->willReturnCallback(
            function (int $ms) use (&$total): void {
                $total += $ms;
            },
        );

        $waited = $this->limiter->acquireBlocking(self::CARRIER, 1, 250);
        self::assertSame(250, $waited, 'On timeout returns exactly $maxWaitMs.');
        self::assertGreaterThan(0, $total);
    }

    public function testWindowTokensReadsFromDb(): void
    {
        $this->dbResource->expects(self::once())->method('fetchTokensUsed')
            ->with(self::CARRIER, $this->now)
            ->willReturn(42);
        self::assertSame(42, $this->limiter->windowTokens(self::CARRIER));
    }
}
