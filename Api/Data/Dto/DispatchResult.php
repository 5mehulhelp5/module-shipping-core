<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Api\Data\Dto;

/**
 * Outcome of {@see \Shubo\ShippingCore\Model\Webhook\WebhookDispatcher::dispatch()}.
 *
 * Small, readonly value object that separates dispatcher logic from the
 * HTTP boundary. Controllers map the status string to an HTTP code without
 * having to know anything about dispatcher internals.
 *
 * @api
 */
class DispatchResult
{
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_DUPLICATE = 'duplicate';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_UNKNOWN_CARRIER = 'unknown_carrier';

    public function __construct(
        public readonly string $status,
        public readonly ?string $reason = null,
        public readonly ?string $externalEventId = null,
    ) {
    }

    public static function accepted(?string $externalEventId = null): self
    {
        return new self(self::STATUS_ACCEPTED, null, $externalEventId);
    }

    public static function duplicate(?string $externalEventId = null): self
    {
        return new self(self::STATUS_DUPLICATE, null, $externalEventId);
    }

    public static function rejected(?string $reason = null): self
    {
        return new self(self::STATUS_REJECTED, $reason);
    }

    public static function unknownCarrier(): self
    {
        return new self(self::STATUS_UNKNOWN_CARRIER);
    }
}
