<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Api\Data;

/**
 * Carrier config data interface.
 *
 * Per-carrier runtime settings (toggle, sandbox, priority, encrypted
 * credentials, cached capabilities).
 *
 * @api
 */
interface CarrierConfigInterface
{
    public const TABLE = 'shubo_shipping_carrier_config';

    public const FIELD_CARRIER_CODE = 'carrier_code';
    public const FIELD_IS_ENABLED = 'is_enabled';
    public const FIELD_IS_SANDBOX = 'is_sandbox';
    public const FIELD_PRIORITY = 'priority';
    public const FIELD_CREDENTIALS_ENCRYPTED = 'credentials_encrypted';
    public const FIELD_CAPABILITIES_CACHE_JSON = 'capabilities_cache_json';
    public const FIELD_RATE_LIMIT_RPM = 'rate_limit_rpm';
    public const FIELD_TIMEOUT_SECONDS = 'timeout_seconds';
    public const FIELD_UPDATED_AT = 'updated_at';

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
     * @return bool
     */
    public function isEnabled(): bool;

    /**
     * @param bool $enabled
     * @return $this
     */
    public function setIsEnabled(bool $enabled): self;

    /**
     * @return bool
     */
    public function isSandbox(): bool;

    /**
     * @param bool $sandbox
     * @return $this
     */
    public function setIsSandbox(bool $sandbox): self;

    /**
     * @return int
     */
    public function getPriority(): int;

    /**
     * @param int $priority
     * @return $this
     */
    public function setPriority(int $priority): self;

    /**
     * @return string|null
     */
    public function getCredentialsEncrypted(): ?string;

    /**
     * @param string|null $encryptedBlob
     * @return $this
     */
    public function setCredentialsEncrypted(?string $encryptedBlob): self;

    /**
     * Capabilities cache as decoded array (repository handles JSON encode/decode).
     *
     * @return array<string, mixed>
     */
    public function getCapabilitiesCache(): array;

    /**
     * @param array<string, mixed> $capabilities
     * @return $this
     */
    public function setCapabilitiesCache(array $capabilities): self;

    /**
     * @return int
     */
    public function getRateLimitRpm(): int;

    /**
     * @param int $rpm
     * @return $this
     */
    public function setRateLimitRpm(int $rpm): self;

    /**
     * @return int
     */
    public function getTimeoutSeconds(): int;

    /**
     * @param int $seconds
     * @return $this
     */
    public function setTimeoutSeconds(int $seconds): self;

    /**
     * @return string|null
     */
    public function getUpdatedAt(): ?string;
}
