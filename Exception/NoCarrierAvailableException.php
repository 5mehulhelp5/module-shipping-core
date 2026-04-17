<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Exception;

use Magento\Framework\Exception\LocalizedException;

/**
 * Thrown by the orchestrator when no carrier can serve the requested
 * shipment (all eligible carriers are disabled, circuit-open, or
 * outside the service area).
 *
 * @api
 */
class NoCarrierAvailableException extends LocalizedException
{
}
