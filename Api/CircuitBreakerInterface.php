<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Api;

/**
 * Per-carrier circuit breaker.
 *
 * States are closed / open / half_open (see
 * {@see \Shubo\ShippingCore\Api\Data\CircuitBreakerStateInterface}).
 *
 * @api
 */
interface CircuitBreakerInterface
{
    /**
     * Execute a callable guarded by the breaker. Records success or
     * failure and updates state accordingly.
     *
     * @param string   $carrierCode
     * @param callable $fn
     * @return mixed The callable's return value.
     * @throws \Shubo\ShippingCore\Exception\CircuitOpenException
     */
    public function execute(string $carrierCode, callable $fn): mixed;

    /**
     * Current breaker state for the carrier. Returns one of the
     * {@see \Shubo\ShippingCore\Api\Data\CircuitBreakerStateInterface}
     * STATE_* constants.
     *
     * @param string $carrierCode
     * @return string
     */
    public function stateOf(string $carrierCode): string;

    /**
     * Force breaker state (admin-only).
     *
     * @param string $carrierCode
     * @param string $state
     * @param string $adminNote
     * @return void
     */
    public function forceState(string $carrierCode, string $state, string $adminNote): void;
}
