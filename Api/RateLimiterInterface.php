<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Api;

/**
 * Per-carrier token-bucket rate limiter.
 *
 * Backed by Redis in production with a DB fallback; both back-ends
 * expose the same interface here.
 *
 * @api
 */
interface RateLimiterInterface
{
    /**
     * Consume N tokens. Returns true on success, false if insufficient
     * tokens are currently available.
     *
     * @param string $carrierCode
     * @param int    $tokens
     * @return bool
     */
    public function acquire(string $carrierCode, int $tokens = 1): bool;

    /**
     * Wait until tokens are available, up to `$maxWaitMs`. Returns the
     * actual milliseconds waited (0 if tokens were immediately available;
     * equal to $maxWaitMs on timeout).
     *
     * @param string $carrierCode
     * @param int    $tokens
     * @param int    $maxWaitMs
     * @return int
     */
    public function acquireBlocking(string $carrierCode, int $tokens = 1, int $maxWaitMs = 2000): int;

    /**
     * Current tokens available for inspection (admin dashboards).
     *
     * @param string $carrierCode
     * @return int
     */
    public function windowTokens(string $carrierCode): int;
}
