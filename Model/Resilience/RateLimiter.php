<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Model\Resilience;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Shubo\ShippingCore\Api\RateLimiterInterface;
use Shubo\ShippingCore\Model\Logging\StructuredLogger;
use Shubo\ShippingCore\Model\ResourceModel\RateLimitState;

/**
 * Per-carrier token-bucket rate limiter.
 *
 * Backing store: MySQL via {@see RateLimitState}. Every token acquisition
 * runs through a short transaction that performs
 * `INSERT ... ON DUPLICATE KEY UPDATE` followed by `SELECT ... FOR UPDATE`,
 * which serializes concurrent callers on the per-carrier row and makes
 * over-issue provably impossible.
 *
 * An earlier revision of this class tried a Redis-first fast path through
 * {@see \Magento\Framework\App\CacheInterface::load()} / `save()`. That
 * pattern is TOCTOU-racy — `CacheInterface` exposes no atomic increment
 * primitive — and `RateLimiterConcurrencyTest` demonstrated 20×3 parallel
 * workers routinely over-issuing 60 tokens against a 30-rpm cap (up to
 * 2× the limit). The fast path was removed in favor of the DB path,
 * which the original code already used as a fallback. Using raw Redis
 * `INCR`/`EXPIRE` would bypass the Cache abstraction entirely, so we
 * stay DB-only until the benefit justifies that coupling.
 *
 * Window is 1 minute aligned to the epoch (`floor(time()/60)*60`).
 * Default RPM is read from `shubo_shipping/rate_limit/default_rpm` (60).
 */
class RateLimiter implements RateLimiterInterface
{
    private const CONFIG_DEFAULT_RPM = 'shubo_shipping/rate_limit/default_rpm';
    private const DEFAULT_RPM = 60;
    private const BLOCKING_TICK_MS = 100;

    public function __construct(
        private readonly RateLimitState $dbResource,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly DateTime $dateTime,
        private readonly Sleeper $sleeper,
        private readonly StructuredLogger $logger,
    ) {
    }

    public function acquire(string $carrierCode, int $tokens = 1): bool
    {
        $rpm = $this->getRpm();
        $now = $this->nowTs();

        try {
            $ok = $this->dbResource->incrementTokens($carrierCode, $tokens, $rpm, $now);
        } catch (\Throwable $e) {
            // DB is the only path — log and fail closed so callers don't
            // accidentally overwhelm the carrier on the way to a real
            // outage recovery.
            $this->logger->logRateLimit($carrierCode, -1);
            return false;
        }

        // `tokens_remaining` is a rough figure for the structured log —
        // it's read without the row lock the increment just held, so it
        // may already drift by the time the log line is written. The
        // acquire decision has already been made atomically above.
        $remaining = $ok
            ? max(0, $rpm - $this->dbResource->fetchTokensUsed($carrierCode, $now))
            : 0;
        $this->logger->logRateLimit($carrierCode, $remaining);

        return $ok;
    }

    public function acquireBlocking(string $carrierCode, int $tokens = 1, int $maxWaitMs = 2000): int
    {
        $waited = 0;
        while (true) {
            if ($this->acquire($carrierCode, $tokens)) {
                return $waited;
            }
            if ($waited >= $maxWaitMs) {
                return $maxWaitMs;
            }
            $sleepMs = min(self::BLOCKING_TICK_MS, max(1, $maxWaitMs - $waited));
            $this->sleeper->sleepMs($sleepMs);
            $waited += $sleepMs;
        }
    }

    public function windowTokens(string $carrierCode): int
    {
        return $this->dbResource->fetchTokensUsed($carrierCode, $this->nowTs());
    }

    private function getRpm(): int
    {
        $value = $this->scopeConfig->getValue(self::CONFIG_DEFAULT_RPM);
        if ($value === null || $value === '') {
            return self::DEFAULT_RPM;
        }
        return (int)$value;
    }

    private function nowTs(): int
    {
        return (int)$this->dateTime->gmtTimestamp();
    }
}
