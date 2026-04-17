<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Api\Data;

/**
 * Invoice import data interface.
 *
 * One row per carrier invoice ingested. The lines belonging to the import
 * live in {@see InvoiceLineInterface}.
 *
 * @api
 */
interface InvoiceImportInterface
{
    public const TABLE = 'shubo_shipping_invoice_import';

    public const FIELD_IMPORT_ID = 'import_id';
    public const FIELD_CARRIER_CODE = 'carrier_code';
    public const FIELD_PERIOD_START = 'period_start';
    public const FIELD_PERIOD_END = 'period_end';
    public const FIELD_SOURCE_FILE_HASH = 'source_file_hash';
    public const FIELD_SOURCE_FILE_PATH = 'source_file_path';
    public const FIELD_SOURCE_FORMAT = 'source_format';
    public const FIELD_TOTAL_LINES = 'total_lines';
    public const FIELD_MATCHED_LINES = 'matched_lines';
    public const FIELD_UNMATCHED_LINES = 'unmatched_lines';
    public const FIELD_DISPUTED_LINES = 'disputed_lines';
    public const FIELD_STATUS = 'status';
    public const FIELD_IMPORTED_AT = 'imported_at';
    public const FIELD_RECONCILED_AT = 'reconciled_at';
    public const FIELD_IMPORTED_BY_ADMIN_ID = 'imported_by_admin_id';
    public const FIELD_ERROR_MESSAGE = 'error_message';

    /** Status enum values */
    public const STATUS_PENDING = 'pending';
    public const STATUS_PARSING = 'parsing';
    public const STATUS_IMPORTED = 'imported';
    public const STATUS_RECONCILED = 'reconciled';
    public const STATUS_FAILED = 'failed';

    /** Source format enum values */
    public const FORMAT_XLSX = 'xlsx';
    public const FORMAT_CSV = 'csv';
    public const FORMAT_PDF = 'pdf';
    public const FORMAT_JSON = 'json';

    /**
     * @return int|null
     */
    public function getImportId(): ?int;

    /**
     * @return string
     */
    public function getCarrierCode(): string;

    /**
     * @param string $carrierCode
     * @return $this
     */
    public function setCarrierCode(string $carrierCode): self;

    /**
     * @return string
     */
    public function getPeriodStart(): string;

    /**
     * @param string $date
     * @return $this
     */
    public function setPeriodStart(string $date): self;

    /**
     * @return string
     */
    public function getPeriodEnd(): string;

    /**
     * @param string $date
     * @return $this
     */
    public function setPeriodEnd(string $date): self;

    /**
     * @return string
     */
    public function getSourceFileHash(): string;

    /**
     * @param string $hash
     * @return $this
     */
    public function setSourceFileHash(string $hash): self;

    /**
     * @return string
     */
    public function getSourceFilePath(): string;

    /**
     * @param string $path
     * @return $this
     */
    public function setSourceFilePath(string $path): self;

    /**
     * @return string
     */
    public function getSourceFormat(): string;

    /**
     * @param string $format
     * @return $this
     */
    public function setSourceFormat(string $format): self;

    /**
     * @return int
     */
    public function getTotalLines(): int;

    /**
     * @param int $count
     * @return $this
     */
    public function setTotalLines(int $count): self;

    /**
     * @return int
     */
    public function getMatchedLines(): int;

    /**
     * @param int $count
     * @return $this
     */
    public function setMatchedLines(int $count): self;

    /**
     * @return int
     */
    public function getUnmatchedLines(): int;

    /**
     * @param int $count
     * @return $this
     */
    public function setUnmatchedLines(int $count): self;

    /**
     * @return int
     */
    public function getDisputedLines(): int;

    /**
     * @param int $count
     * @return $this
     */
    public function setDisputedLines(int $count): self;

    /**
     * @return string
     */
    public function getStatus(): string;

    /**
     * @param string $status
     * @return $this
     */
    public function setStatus(string $status): self;

    /**
     * @return string|null
     */
    public function getImportedAt(): ?string;

    /**
     * @param string|null $timestamp
     * @return $this
     */
    public function setImportedAt(?string $timestamp): self;

    /**
     * @return string|null
     */
    public function getReconciledAt(): ?string;

    /**
     * @param string|null $timestamp
     * @return $this
     */
    public function setReconciledAt(?string $timestamp): self;

    /**
     * @return int|null
     */
    public function getImportedByAdminId(): ?int;

    /**
     * @param int|null $adminId
     * @return $this
     */
    public function setImportedByAdminId(?int $adminId): self;

    /**
     * @return string|null
     */
    public function getErrorMessage(): ?string;

    /**
     * @param string|null $message
     * @return $this
     */
    public function setErrorMessage(?string $message): self;
}
