<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Api\Data\Dto;

/**
 * Quote request DTO.
 *
 * Input to {@see \Shubo\ShippingCore\Api\CarrierGatewayInterface::quote()}
 * and {@see \Shubo\ShippingCore\Api\RateQuoteServiceInterface::quote()}.
 * Monetary values are integer tetri (cents).
 *
 * @api
 */
class QuoteRequest
{
    public function __construct(
        public readonly int $merchantId,
        public readonly ContactAddress $origin,
        public readonly ContactAddress $destination,
        public readonly ParcelSpec $parcel,
        public readonly bool $codRequested = false,
        public readonly int $codAmountCents = 0,
        public readonly ?string $preferredCarrierCode = null,
    ) {
    }
}
