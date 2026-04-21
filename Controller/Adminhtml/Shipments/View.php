<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Controller\Adminhtml\Shipments;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Page as BackendPage;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Registry;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use Shubo\ShippingCore\Api\ShipmentRepositoryInterface;

/**
 * Admin shipment detail view.
 *
 * Route: `shubo_shipping_admin/shipments/view/shipment_id/<N>`.
 *
 * Loads the shipment via the repository, registers it with the core Registry
 * under `shubo_shipping_current_shipment` so the layout block can read it,
 * then renders the `shubo_shipping_admin_shipments_view` layout handle.
 *
 * Missing shipment: flash an error and redirect to the grid.
 */
class View extends Action
{
    public const ADMIN_RESOURCE = 'Shubo_ShippingCore::shipments_manage';
    public const REGISTRY_KEY = 'shubo_shipping_current_shipment';

    public function __construct(
        Context $context,
        private readonly ShipmentRepositoryInterface $shipmentRepository,
        private readonly Registry $coreRegistry,
        private readonly PageFactory $pageFactory,
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $shipmentId = (int)$this->getRequest()->getParam('shipment_id');
        if ($shipmentId <= 0) {
            $this->messageManager->addErrorMessage((string)__('Invalid shipment ID.'));
            return $this->redirectToGrid();
        }

        try {
            $shipment = $this->shipmentRepository->getById($shipmentId);
        } catch (NoSuchEntityException) {
            $this->messageManager->addErrorMessage(
                (string)__('Shipment #%1 not found.', $shipmentId),
            );
            return $this->redirectToGrid();
        }

        $this->coreRegistry->register(self::REGISTRY_KEY, $shipment, true);

        $result = $this->pageFactory->create();
        // The PageFactory in adminhtml returns the Backend Page subclass
        // which declares setActiveMenu; defensive instanceof so PHPStan
        // level 8 is happy without a forced cast.
        if ($result instanceof BackendPage) {
            $result->setActiveMenu('Shubo_ShippingCore::shipments');
        }
        $result->getConfig()->getTitle()->prepend((string)__('Shipment #%1', $shipmentId));
        return $result;
    }

    private function redirectToGrid(): ResultInterface
    {
        $redirect = $this->resultRedirectFactory->create();
        $redirect->setPath('shubo_shipping_admin/shipments/index');
        return $redirect;
    }
}
