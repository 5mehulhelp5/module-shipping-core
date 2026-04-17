<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Api;

use Shubo\ShippingCore\Api\Data\Dto\WebhookResult;

/**
 * Per-carrier webhook handler.
 *
 * One implementation per carrier, registered in the dispatcher via a
 * DI collection keyed by carrier code. Handlers parse + verify signature
 * + validate payload, but MUST NOT mutate the shipment — the dispatcher
 * applies the returned effect centrally.
 *
 * @api
 */
interface WebhookHandlerInterface
{
    /**
     * Carrier code (matches {@see CarrierGatewayInterface::code()}).
     *
     * @return string
     */
    public function code(): string;

    /**
     * Parse + verify + validate a raw webhook payload.
     *
     * @param string                $rawBody
     * @param array<string, string> $headers
     * @return WebhookResult
     */
    public function handle(string $rawBody, array $headers): WebhookResult;
}
