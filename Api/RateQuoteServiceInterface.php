<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Api;

use Shubo\ShippingCore\Api\Data\Dto\QuoteRequest;
use Shubo\ShippingCore\Api\Data\Dto\RateOption;

/**
 * Aggregates quotes from all enabled carriers for the Magento checkout
 * rate aggregator.
 *
 * @api
 */
interface RateQuoteServiceInterface
{
    /**
     * Aggregate quotes from all enabled carriers. Hard budget ~2 seconds
     * (see design doc §13). Circuit-open carriers are silently skipped;
     * only successful quotes are returned.
     *
     * @param QuoteRequest $request
     * @return list<RateOption>
     */
    public function quote(QuoteRequest $request): array;
}
