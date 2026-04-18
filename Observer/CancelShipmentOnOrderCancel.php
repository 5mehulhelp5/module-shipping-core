<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Observer;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order as SalesOrder;
use Shubo\ShippingCore\Api\Data\ShipmentInterface;
use Shubo\ShippingCore\Api\ShipmentOrchestratorInterface;
use Shubo\ShippingCore\Api\ShipmentRepositoryInterface;
use Shubo\ShippingCore\Model\Logging\StructuredLogger;

/**
 * Observer on `order_cancel_after`.
 *
 * When a Magento order is cancelled, any non-terminal Core shipment row
 * belonging to that order must be cancelled at the carrier and marked
 * {@see ShipmentInterface::STATUS_CANCELLED} locally. The orchestrator handles
 * both sides via {@see ShipmentOrchestratorInterface::cancel()} which is
 * itself idempotent.
 *
 * Idempotency: the orchestrator's `cancel()` is a no-op for already-cancelled
 * rows; re-firing this observer (Magento events can fire multiple times)
 * therefore cannot double-cancel.
 *
 * Failure handling: individual cancel failures are logged and swallowed. One
 * failing shipment must not prevent the remaining shipments from being
 * cancelled, and a carrier outage must not roll back the Magento order cancel.
 */
class CancelShipmentOnOrderCancel implements ObserverInterface
{
    private const TERMINAL_STATUSES = [
        ShipmentInterface::STATUS_DELIVERED,
        ShipmentInterface::STATUS_RETURNED_TO_SENDER,
        ShipmentInterface::STATUS_CANCELLED,
        ShipmentInterface::STATUS_FAILED,
    ];

    public function __construct(
        private readonly ShipmentOrchestratorInterface $orchestrator,
        private readonly ShipmentRepositoryInterface $shipmentRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly StructuredLogger $logger,
    ) {
    }

    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getData('order');
        if (!$order instanceof SalesOrder) {
            return;
        }
        $orderId = (int)($order->getEntityId() ?? 0);
        if ($orderId === 0) {
            return;
        }

        $criteria = $this->searchCriteriaBuilder
            ->addFilter(ShipmentInterface::FIELD_ORDER_ID, $orderId)
            ->create();

        try {
            $results = $this->shipmentRepository->getList($criteria);
        } catch (\Throwable $e) {
            $this->logger->logDispatchFailed(
                'unknown',
                'observer.cancel_order.list',
                $e,
            );
            return;
        }

        foreach ($results->getItems() as $shipment) {
            $shipmentId = $shipment->getShipmentId();
            if ($shipmentId === null) {
                continue;
            }
            if (in_array($shipment->getStatus(), self::TERMINAL_STATUSES, true)) {
                // Delivered / already-cancelled / failed — nothing to do.
                continue;
            }
            try {
                $this->orchestrator->cancel((int)$shipmentId, 'order_cancelled');
            } catch (\Throwable $e) {
                $this->logger->logDispatchFailed(
                    $shipment->getCarrierCode(),
                    'observer.cancel_order',
                    $e,
                );
            }
        }
    }
}
