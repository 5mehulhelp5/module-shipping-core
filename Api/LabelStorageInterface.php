<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Api;

/**
 * Abstract label persistence.
 *
 * Labels are downloaded from carriers (whose URLs are sometimes 24h-signed)
 * and stored under `pub/media/shubo_shipping/labels/`.
 *
 * @api
 */
interface LabelStorageInterface
{
    /**
     * Store label PDF bytes. Returns the storage path under `pub/media/`.
     *
     * @param int    $shipmentId
     * @param string $pdfBytes
     * @param string $filename
     * @return string
     */
    public function store(int $shipmentId, string $pdfBytes, string $filename): string;

    /**
     * Retrieve stored label bytes.
     *
     * @param string $storagePath
     * @return string
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function retrieve(string $storagePath): string;

    /**
     * Check whether a stored label exists at the given path.
     *
     * @param string $storagePath
     * @return bool
     */
    public function exists(string $storagePath): bool;
}
