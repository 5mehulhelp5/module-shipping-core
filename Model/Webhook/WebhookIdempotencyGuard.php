<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Model\Webhook;

use Shubo\ShippingCore\Api\Data\Dto\WebhookResult;
use Shubo\ShippingCore\Api\ShipmentEventRepositoryInterface;

/**
 * Webhook replay-protection helper.
 *
 * Design-doc §11.4: `(carrier_code, external_event_id)` is unique on the
 * event table. Some carriers (e.g. Trackings) do not supply an id in the
 * webhook payload — for those cases this guard synthesizes a stable id by
 * hashing the raw body with SHA-256. The `sha256:` prefix makes it easy to
 * tell synthesized ids from carrier-supplied ones when tailing the log.
 *
 * The guard itself is stateless; the backing uniqueness check lives on the
 * event repository.
 */
class WebhookIdempotencyGuard
{
    private const SYNTHESIS_PREFIX = 'sha256:';

    public function __construct(
        private readonly ShipmentEventRepositoryInterface $eventRepository,
    ) {
    }

    /**
     * Produce a deterministic synthesized id for a raw webhook body.
     *
     * Used when the handler does not extract an id from the payload.
     */
    public function synthesizeEventId(string $rawBody): string
    {
        return self::SYNTHESIS_PREFIX . hash('sha256', $rawBody);
    }

    /**
     * Return the external event id the dispatcher should record, preferring
     * the handler-supplied value and falling back to a synthesis.
     */
    public function resolveExternalEventId(WebhookResult $result, string $rawBody): string
    {
        if ($result->externalEventId !== null && $result->externalEventId !== '') {
            return $result->externalEventId;
        }
        return $this->synthesizeEventId($rawBody);
    }

    /**
     * Whether the (carrierCode, externalEventId) pair already exists in the
     * event log. Thin delegate — the repository owns the unique key.
     */
    public function isDuplicate(string $carrierCode, string $externalEventId): bool
    {
        return $this->eventRepository->existsByExternalEventId($carrierCode, $externalEventId);
    }
}
