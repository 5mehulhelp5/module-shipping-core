<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Exception;

use Magento\Framework\Exception\LocalizedException;

/**
 * Thrown by invoice importers when a carrier file cannot be parsed
 * (format mismatch, missing required columns, unreadable encoding).
 *
 * @api
 */
class InvoiceParseException extends LocalizedException
{
}
