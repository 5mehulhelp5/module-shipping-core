<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Api\Data;

/**
 * Invoice line data interface.
 *
 * One row per parsed carrier invoice line. Matched to a shipment after
 * reconciliation; unmatched lines become orphans for manual review.
 *
 * Monetary fields are stored as integer tetri (cents). The reported fee
 * is signed because carriers can issue credits on a monthly statement.
 *
 * @api
 */
interface InvoiceLineInterface
{
    public const TABLE = 'shubo_shipping_invoice_line';

    public const FIELD_LINE_ID = 'line_id';
    public const FIELD_IMPORT_ID = 'import_id';
    public const FIELD_CARRIER_TRACKING_ID = 'carrier_tracking_id';
    public const FIELD_EXTERNAL_LINE_ID = 'external_line_id';
    public const FIELD_SHIPMENT_ID = 'shipment_id';
    public const FIELD_EXPECTED_COD_CENTS = 'expected_cod_cents';
    public const FIELD_REPORTED_COD_CENTS = 'reported_cod_cents';
    public const FIELD_REPORTED_FEE_CENTS = 'reported_fee_cents';
    public const FIELD_REPORTED_VAT_CENTS = 'reported_vat_cents';
    public const FIELD_MATCH_STATUS = 'match_status';
    public const FIELD_DISPUTE_REASON = 'dispute_reason';
    public const FIELD_MATCHED_AT = 'matched_at';
    public const FIELD_RAW_LINE_JSON = 'raw_line_json';

    /** Match status enum values */
    public const MATCH_STATUS_PENDING = 'pending';
    public const MATCH_STATUS_MATCHED = 'matched';
    public const MATCH_STATUS_ORPHAN = 'orphan';
    public const MATCH_STATUS_DISPUTED = 'disputed';
    public const MATCH_STATUS_RESOLVED = 'resolved';

    /**
     * @return int|null
     */
    public function getLineId(): ?int;

    /**
     * @return int
     */
    public function getImportId(): int;

    /**
     * @param int $importId
     * @return $this
     */
    public function setImportId(int $importId): self;

    /**
     * @return string|null
     */
    public function getCarrierTrackingId(): ?string;

    /**
     * @param string|null $trackingId
     * @return $this
     */
    public function setCarrierTrackingId(?string $trackingId): self;

    /**
     * @return string|null
     */
    public function getExternalLineId(): ?string;

    /**
     * @param string|null $lineId
     * @return $this
     */
    public function setExternalLineId(?string $lineId): self;

    /**
     * @return int|null
     */
    public function getShipmentId(): ?int;

    /**
     * @param int|null $shipmentId
     * @return $this
     */
    public function setShipmentId(?int $shipmentId): self;

    /**
     * @return int Expected COD in tetri (cents)
     */
    public function getExpectedCodCents(): int;

    /**
     * @param int $cents
     * @return $this
     */
    public function setExpectedCodCents(int $cents): self;

    /**
     * @return int Reported COD in tetri (cents)
     */
    public function getReportedCodCents(): int;

    /**
     * @param int $cents
     * @return $this
     */
    public function setReportedCodCents(int $cents): self;

    /**
     * @return int Reported fee in tetri (cents); signed
     */
    public function getReportedFeeCents(): int;

    /**
     * @param int $cents
     * @return $this
     */
    public function setReportedFeeCents(int $cents): self;

    /**
     * @return int Reported VAT in tetri (cents)
     */
    public function getReportedVatCents(): int;

    /**
     * @param int $cents
     * @return $this
     */
    public function setReportedVatCents(int $cents): self;

    /**
     * @return string
     */
    public function getMatchStatus(): string;

    /**
     * @param string $matchStatus
     * @return $this
     */
    public function setMatchStatus(string $matchStatus): self;

    /**
     * @return string|null
     */
    public function getDisputeReason(): ?string;

    /**
     * @param string|null $reason
     * @return $this
     */
    public function setDisputeReason(?string $reason): self;

    /**
     * @return string|null
     */
    public function getMatchedAt(): ?string;

    /**
     * @param string|null $timestamp
     * @return $this
     */
    public function setMatchedAt(?string $timestamp): self;

    /**
     * Raw parsed line as decoded array (repository handles JSON encode/decode).
     *
     * @return array<string, mixed>
     */
    public function getRawLine(): array;

    /**
     * @param array<string, mixed> $line
     * @return $this
     */
    public function setRawLine(array $line): self;
}
