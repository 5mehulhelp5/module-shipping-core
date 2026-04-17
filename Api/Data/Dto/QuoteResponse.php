<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Api\Data\Dto;

/**
 * Quote response DTO.
 *
 * Aggregate output of
 * {@see \Shubo\ShippingCore\Api\CarrierGatewayInterface::quote()}.
 * A carrier may return zero options with error messages.
 *
 * @api
 */
class QuoteResponse
{
    /**
     * @param list<RateOption> $options
     * @param list<string>     $errors
     */
    public function __construct(
        public readonly array $options,
        public readonly array $errors = [],
    ) {
    }
}
