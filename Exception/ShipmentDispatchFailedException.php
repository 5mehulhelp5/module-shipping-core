<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Exception;

use Magento\Framework\Exception\LocalizedException;

/**
 * Thrown by the orchestrator after retry exhaustion when a carrier call
 * cannot be completed successfully. The failed shipment row is published
 * to the dead-letter topic for admin retry.
 *
 * @api
 */
class ShipmentDispatchFailedException extends LocalizedException
{
}
