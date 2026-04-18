<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Model\Carrier;

use Shubo\ShippingCore\Api\CarrierRegistryInterface;
use Shubo\ShippingCore\Api\CircuitBreakerInterface;
use Shubo\ShippingCore\Api\Data\CircuitBreakerStateInterface;
use Shubo\ShippingCore\Api\Data\Dto\QuoteRequest;
use Shubo\ShippingCore\Api\Data\Dto\RateOption;
use Shubo\ShippingCore\Api\RateQuoteServiceInterface;
use Shubo\ShippingCore\Model\Logging\StructuredLogger;

/**
 * Phase 6 demo-scoped rate aggregator.
 *
 * Design decisions (see design doc 13.3):
 *   - Sequential loop over registered carriers. The design doc calls for
 *     parallel curl-multi once more than a couple of carriers exist; with
 *     one carrier (Shubo_FlatRate) the overhead of a parallel executor is
 *     pure complexity with no checkout-latency benefit, so Phase 6 ships
 *     the sequential variant. The public contract (RateQuoteServiceInterface)
 *     does not leak the loop strategy, so a later phase can swap without
 *     touching Core callers.
 *   - Per-carrier failures are contained. Any exception thrown by a carrier
 *     gateway is logged and the loop continues. This is the "never block
 *     checkout" guarantee: a misbehaving adapter must never cause the whole
 *     rate step to fail.
 *   - Circuit-open carriers are skipped up front (no gateway call, no
 *     breaker mutation). Only fully-open breakers are skipped; half-open
 *     breakers are allowed through so the CircuitBreaker executed inside
 *     the gateway's dispatch path observes the trial call.
 */
class RateQuoteService implements RateQuoteServiceInterface
{
    private const OP = 'quote';

    /**
     * @param CarrierRegistryInterface $carrierRegistry
     * @param CircuitBreakerInterface  $circuitBreaker
     * @param StructuredLogger         $logger
     */
    public function __construct(
        private readonly CarrierRegistryInterface $carrierRegistry,
        private readonly CircuitBreakerInterface $circuitBreaker,
        private readonly StructuredLogger $logger,
    ) {
    }

    /**
     * Aggregate quotes from every enabled carrier.
     *
     * @param QuoteRequest $request
     * @return list<RateOption>
     */
    public function quote(QuoteRequest $request): array
    {
        $aggregated = [];

        foreach ($this->carrierRegistry->enabled() as $carrierCode => $gateway) {
            $code = (string)$carrierCode;

            if ($this->isBreakerOpen($code)) {
                // Silently skip open carriers. A closed breaker would have
                // its state mutated by the CircuitBreaker wrapping the
                // dispatch path; rate quote is a peek-only path so we just
                // avoid calling the adapter at all.
                continue;
            }

            try {
                $response = $gateway->quote($request);
            } catch (\Throwable $e) {
                // One failing carrier must never suppress the rest. We log
                // through StructuredLogger::logDispatchFailed so operators
                // can correlate this warning with any breaker trip that
                // may have been recorded elsewhere in the stack.
                $this->logger->logDispatchFailed($code, self::OP, $e);
                continue;
            }

            foreach ($response->options as $option) {
                $aggregated[] = $option;
            }
        }

        return $aggregated;
    }

    /**
     * Peek at the breaker state without mutating it. stateOf() reads from
     * persistence; the actual state machine only transitions when a call
     * is executed via CircuitBreaker::execute().
     *
     * @param string $carrierCode
     * @return bool
     */
    private function isBreakerOpen(string $carrierCode): bool
    {
        try {
            return $this->circuitBreaker->stateOf($carrierCode)
                === CircuitBreakerStateInterface::STATE_OPEN;
        } catch (\Throwable) {
            // If breaker state can't be read (missing row, DB hiccup), fall
            // open to calling the carrier; the worst case is one extra
            // adapter call, not a checkout outage.
            return false;
        }
    }
}
