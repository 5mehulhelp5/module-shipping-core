<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Model\Webhook;

/**
 * Internal signal thrown by {@see WebhookDispatcher::persistWebhookEvent()}
 * when a {@see \Magento\Framework\Exception\CouldNotSaveException} is
 * interpreted as a concurrent duplicate race — i.e. two webhook requests
 * with the same `(carrier_code, external_event_id)` arrived at the same
 * moment, both passed the idempotency pre-check, and the second one hit
 * the unique-index constraint on `shubo_shipping_shipment_event` (design
 * doc §11.4).
 *
 * This exception is caught inside {@see WebhookDispatcher::dispatch()} and
 * translated into a {@see \Shubo\ShippingCore\Api\Data\Dto\DispatchResult}
 * with status DUPLICATE so the HTTP boundary answers 200 OK instead of 500,
 * which would make the carrier retry a payload that is already recorded.
 *
 * Scope: strictly internal to {@see WebhookDispatcher}. Never exposed to
 * handlers, controllers, or other modules.
 *
 * @internal
 */
class WebhookDuplicateRaceException extends \RuntimeException
{
}
