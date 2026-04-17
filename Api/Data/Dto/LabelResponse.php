<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Api\Data\Dto;

/**
 * Label response DTO.
 *
 * Output of
 * {@see \Shubo\ShippingCore\Api\CarrierGatewayInterface::fetchLabel()}.
 * `pdfBytes` is the raw binary PDF; the label storage implementation
 * persists it to media storage.
 *
 * @api
 */
class LabelResponse
{
    public function __construct(
        public readonly string $pdfBytes,
        public readonly string $contentType,
        public readonly string $filename,
    ) {
    }
}
