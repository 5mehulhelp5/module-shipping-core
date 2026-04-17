<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Exception;

use Magento\Framework\Exception\LocalizedException;

/**
 * Thrown by the circuit breaker when a guarded call is rejected because
 * the breaker is currently open.
 *
 * @api
 */
class CircuitOpenException extends LocalizedException
{
}
