<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Api\Data;

/**
 * Geo cache data interface.
 *
 * Per-carrier geographical entity (city, region, PUDO, district) with
 * carrier-specific external identifier and optional lat/lng.
 *
 * @api
 */
interface GeoCacheInterface
{
    public const TABLE = 'shubo_shipping_geo_cache';

    public const FIELD_GEO_ID = 'geo_id';
    public const FIELD_CARRIER_CODE = 'carrier_code';
    public const FIELD_GEO_TYPE = 'geo_type';
    public const FIELD_EXTERNAL_ID = 'external_id';
    public const FIELD_NAME = 'name';
    public const FIELD_NAME_EN = 'name_en';
    public const FIELD_PARENT_ID = 'parent_id';
    public const FIELD_LATITUDE = 'latitude';
    public const FIELD_LONGITUDE = 'longitude';
    public const FIELD_METADATA_JSON = 'metadata_json';
    public const FIELD_REFRESHED_AT = 'refreshed_at';

    /** Geo type enum values */
    public const GEO_TYPE_CITY = 'city';
    public const GEO_TYPE_REGION = 'region';
    public const GEO_TYPE_PUDO = 'pudo';
    public const GEO_TYPE_DISTRICT = 'district';

    /**
     * @return int|null
     */
    public function getGeoId(): ?int;

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
    public function getGeoType(): string;

    /**
     * @param string $geoType
     * @return $this
     */
    public function setGeoType(string $geoType): self;

    /**
     * @return string
     */
    public function getExternalId(): string;

    /**
     * @param string $externalId
     * @return $this
     */
    public function setExternalId(string $externalId): self;

    /**
     * @return string
     */
    public function getName(): string;

    /**
     * @param string $name
     * @return $this
     */
    public function setName(string $name): self;

    /**
     * @return string|null
     */
    public function getNameEn(): ?string;

    /**
     * @param string|null $nameEn
     * @return $this
     */
    public function setNameEn(?string $nameEn): self;

    /**
     * @return int|null
     */
    public function getParentId(): ?int;

    /**
     * @param int|null $parentId
     * @return $this
     */
    public function setParentId(?int $parentId): self;

    /**
     * @return float|null
     */
    public function getLatitude(): ?float;

    /**
     * @param float|null $latitude
     * @return $this
     */
    public function setLatitude(?float $latitude): self;

    /**
     * @return float|null
     */
    public function getLongitude(): ?float;

    /**
     * @param float|null $longitude
     * @return $this
     */
    public function setLongitude(?float $longitude): self;

    /**
     * Geo metadata as decoded array (repository handles JSON encode/decode).
     *
     * @return array<string, mixed>
     */
    public function getMetadata(): array;

    /**
     * @param array<string, mixed> $metadata
     * @return $this
     */
    public function setMetadata(array $metadata): self;

    /**
     * @return string|null
     */
    public function getRefreshedAt(): ?string;
}
