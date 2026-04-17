<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Api\Data;

/**
 * Circuit breaker state data interface.
 *
 * Persisted per-carrier breaker state (closed/open/half_open) plus counters
 * and timing fields. Persistence is deliberate so state survives Redis flushes
 * and can be queried from admin dashboards.
 *
 * @api
 */
interface CircuitBreakerStateInterface
{
    public const TABLE = 'shubo_shipping_circuit_breaker';

    public const FIELD_CARRIER_CODE = 'carrier_code';
    public const FIELD_STATE = 'state';
    public const FIELD_FAILURE_COUNT = 'failure_count';
    public const FIELD_SUCCESS_COUNT_SINCE_HALFOPEN = 'success_count_since_halfopen';
    public const FIELD_LAST_FAILURE_AT = 'last_failure_at';
    public const FIELD_LAST_SUCCESS_AT = 'last_success_at';
    public const FIELD_OPENED_AT = 'opened_at';
    public const FIELD_COOLDOWN_UNTIL = 'cooldown_until';
    public const FIELD_UPDATED_AT = 'updated_at';

    /** Circuit breaker state enum values */
    public const STATE_CLOSED = 'closed';
    public const STATE_OPEN = 'open';
    public const STATE_HALF_OPEN = 'half_open';

    /**
     * @return string
     */
    public function getCarrierCode(): string;

    /**
     * @param string $carrierCode
     * @return $this
     */
    public function setCarrierCode(string $carrierCode): self;

    /**
     * @return string
     */
    public function getState(): string;

    /**
     * @param string $state
     * @return $this
     */
    public function setState(string $state): self;

    /**
     * @return int
     */
    public function getFailureCount(): int;

    /**
     * @param int $count
     * @return $this
     */
    public function setFailureCount(int $count): self;

    /**
     * @return int
     */
    public function getSuccessCountSinceHalfopen(): int;

    /**
     * @param int $count
     * @return $this
     */
    public function setSuccessCountSinceHalfopen(int $count): self;

    /**
     * @return string|null
     */
    public function getLastFailureAt(): ?string;

    /**
     * @param string|null $timestamp
     * @return $this
     */
    public function setLastFailureAt(?string $timestamp): self;

    /**
     * @return string|null
     */
    public function getLastSuccessAt(): ?string;

    /**
     * @param string|null $timestamp
     * @return $this
     */
    public function setLastSuccessAt(?string $timestamp): self;

    /**
     * @return string|null
     */
    public function getOpenedAt(): ?string;

    /**
     * @param string|null $timestamp
     * @return $this
     */
    public function setOpenedAt(?string $timestamp): self;

    /**
     * @return string|null
     */
    public function getCooldownUntil(): ?string;

    /**
     * @param string|null $timestamp
     * @return $this
     */
    public function setCooldownUntil(?string $timestamp): self;

    /**
     * @return string|null
     */
    public function getUpdatedAt(): ?string;
}
