<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Api;

use Shubo\ShippingCore\Api\Data\Dto\InvoiceImportMetadata;
use Shubo\ShippingCore\Api\Data\Dto\InvoiceLineDto;

/**
 * Per-carrier invoice importer.
 *
 * Parses a carrier's monthly invoice (XLSX/CSV/PDF/JSON) into normalized
 * line DTOs. Implementations must not touch the DB; persistence and
 * reconciliation happen in Core.
 *
 * @api
 */
interface InvoiceImporterInterface
{
    /**
     * Carrier code (matches {@see CarrierGatewayInterface::code()}).
     *
     * @return string
     */
    public function code(): string;

    /**
     * Supported file format extensions (lowercase).
     *
     * @return list<string>
     */
    public function supportedFormats(): array;

    /**
     * Parse a carrier invoice file into normalized line DTOs.
     *
     * @param string $absoluteFilePath
     * @param string $format
     * @return list<InvoiceLineDto>
     * @throws \Shubo\ShippingCore\Exception\InvoiceParseException
     */
    public function parse(string $absoluteFilePath, string $format): array;

    /**
     * Extract invoice metadata (covering period, statement number).
     *
     * @param string $absoluteFilePath
     * @param string $format
     * @return InvoiceImportMetadata
     * @throws \Shubo\ShippingCore\Exception\InvoiceParseException
     */
    public function extractMetadata(string $absoluteFilePath, string $format): InvoiceImportMetadata;
}
