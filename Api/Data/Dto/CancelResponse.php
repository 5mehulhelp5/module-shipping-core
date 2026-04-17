<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Api\Data\Dto;

/**
 * Cancel response DTO.
 *
 * Output of
 * {@see \Shubo\ShippingCore\Api\CarrierGatewayInterface::cancelShipment()}.
 * A failed cancel still resolves (success=false) rather than throwing —
 * the orchestrator decides whether the failure is terminal.
 *
 * @api
 */
class CancelResponse
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly bool $success,
        public readonly ?string $carrierMessage,
        public readonly array $raw = [],
    ) {
    }
}
