<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Api;

use Magento\Framework\Api\SearchCriteriaInterface;
use Shubo\ShippingCore\Api\Data\InvoiceImportInterface;
use Shubo\ShippingCore\Api\Data\Search\InvoiceImportSearchResultsInterface;

/**
 * Repository for invoice-import records.
 *
 * @api
 */
interface InvoiceImportRepositoryInterface
{
    /**
     * Load an invoice import by its ID.
     *
     * @param int $importId
     * @return InvoiceImportInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getById(int $importId): InvoiceImportInterface;

    /**
     * Load an invoice import by the source-file SHA-256 hash; returns NULL
     * if the file has never been ingested for this carrier.
     *
     * @param string $carrierCode
     * @param string $sha256
     * @return InvoiceImportInterface|null
     */
    public function getByHash(string $carrierCode, string $sha256): ?InvoiceImportInterface;

    /**
     * Persist an invoice import.
     *
     * @param InvoiceImportInterface $import
     * @return InvoiceImportInterface
     * @throws \Magento\Framework\Exception\CouldNotSaveException
     */
    public function save(InvoiceImportInterface $import): InvoiceImportInterface;

    /**
     * List invoice imports matching search criteria.
     *
     * @param SearchCriteriaInterface $criteria
     * @return InvoiceImportSearchResultsInterface
     */
    public function getList(SearchCriteriaInterface $criteria): InvoiceImportSearchResultsInterface;
}
