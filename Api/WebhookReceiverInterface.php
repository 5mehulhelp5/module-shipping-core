<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Api;

/**
 * REST-accessible entry point for carrier webhooks.
 *
 * Mirrors the frontend controller at
 * {@see \Shubo\ShippingCore\Controller\Webhook\Receive} so that carriers
 * that prefer the /rest/V1 path (over the /shubo_shipping/webhook path)
 * have a typed service contract. Returns a small status string so
 * Magento's webapi serializer has something concrete to emit.
 *
 * @api
 */
interface WebhookReceiverInterface
{
    /**
     * Dispatch a carrier webhook received through the REST route.
     *
     * @param string $carrierCode
     * @return string one of "accepted" | "duplicate" | "rejected" | "unknown_carrier"
     */
    public function receive(string $carrierCode): string;
}
