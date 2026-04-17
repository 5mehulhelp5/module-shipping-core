<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Api\Data\Dto;

/**
 * Webhook result DTO.
 *
 * Output of
 * {@see \Shubo\ShippingCore\Api\WebhookHandlerInterface::handle()}.
 * Handlers do not mutate the shipment — they return this intended effect
 * and the dispatcher applies it centrally.
 *
 * @api
 */
class WebhookResult
{
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_DUPLICATE = 'duplicate';

    public function __construct(
        public readonly string $status,
        public readonly ?string $carrierTrackingId,
        public readonly ?string $normalizedStatus,
        public readonly ?string $externalEventId,
        public readonly ?string $occurredAt,
        public readonly string $rawPayload,
        public readonly ?string $rejectionReason = null,
    ) {
    }
}
