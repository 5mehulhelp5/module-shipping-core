<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Model\Orchestrator;

use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Shubo\ShippingCore\Api\CarrierGatewayInterface;
use Shubo\ShippingCore\Api\CarrierRegistryInterface;
use Shubo\ShippingCore\Api\CircuitBreakerInterface;
use Shubo\ShippingCore\Api\Data\Dto\ShipmentRequest;
use Shubo\ShippingCore\Api\Data\Dto\ShipmentResponse;
use Shubo\ShippingCore\Api\Data\ShipmentInterface;
use Shubo\ShippingCore\Api\RateLimiterInterface;
use Shubo\ShippingCore\Api\ShipmentOrchestratorInterface;
use Shubo\ShippingCore\Api\ShipmentRepositoryInterface;
use Shubo\ShippingCore\Exception\CircuitOpenException;
use Shubo\ShippingCore\Exception\NoCarrierAvailableException;
use Shubo\ShippingCore\Exception\ShipmentDispatchFailedException;
use Shubo\ShippingCore\Model\Data\Shipment;
use Shubo\ShippingCore\Model\Data\ShipmentFactory;
use Shubo\ShippingCore\Model\Logging\StructuredLogger;
use Shubo\ShippingCore\Model\Resilience\DeadLetterPublisher;
use Shubo\ShippingCore\Model\Resilience\RetryPolicy;

/**
 * Primary {@see ShipmentOrchestratorInterface} implementation.
 *
 * Composition order around the adapter call (design-doc §9):
 *
 *     CircuitBreaker::execute(
 *         RetryPolicy::execute(
 *             RateLimiter::acquire()
 *             + CarrierGateway::createShipment()
 *         )
 *     )
 *
 * Breaker is outermost — if it is open, no attempt is made at all and
 * {@see CircuitOpenException} propagates immediately. The retry loop sits
 * inside the breaker so the breaker observes exactly one failure per
 * dispatch (not one per retry attempt), which matches the failure-window
 * semantics in §9.2.
 *
 * Rate limiter is innermost — tokens are consumed only when we are
 * actually going to hit the carrier. A rate-limit miss is raised as a
 * {@see \Shubo\ShippingCore\Exception\RateLimitedException} without
 * Retry-After so RetryPolicy applies its default backoff.
 *
 * Idempotency:
 *   - Before anything else we look up
 *     `(carrier_code, client_tracking_code)` via {@see IdempotencyStore}.
 *   - If a row exists, we return it with no side effects. This makes
 *     duplicate observer firings safe.
 *
 * Failure handling:
 *   - A non-retryable exception (auth / 4xx) skips retries, marks the row
 *     failed, publishes to DLQ, fires `_dispatch_failed`, and the
 *     orchestrator throws {@see ShipmentDispatchFailedException}.
 *   - Retry exhaustion produces the same terminal flow.
 */
class ShipmentOrchestrator implements ShipmentOrchestratorInterface
{
    private const OP_CREATE_SHIPMENT = 'createShipment';
    private const OP_CANCEL_SHIPMENT = 'cancelShipment';

    public function __construct(
        private readonly CarrierRegistryInterface $carrierRegistry,
        private readonly ShipmentRepositoryInterface $shipmentRepository,
        private readonly ShipmentFactory $shipmentFactory,
        private readonly IdempotencyStore $idempotencyStore,
        private readonly CircuitBreakerInterface $circuitBreaker,
        private readonly RetryPolicy $retryPolicy,
        private readonly RateLimiterInterface $rateLimiter,
        private readonly DeadLetterPublisher $deadLetterPublisher,
        private readonly EventManagerInterface $eventManager,
        private readonly StructuredLogger $logger,
    ) {
    }

    public function dispatch(ShipmentRequest $request): ShipmentInterface
    {
        $carrierCode = $this->resolveCarrierCode($request);
        $gateway = $this->carrierRegistry->get($carrierCode); // throws NoCarrierAvailableException

        // Idempotency — we look up first so we never create a duplicate row,
        // even if the observer fires twice for the same Magento shipment.
        $existingId = $this->idempotencyStore->findExisting(
            $carrierCode,
            $request->clientTrackingCode,
        );
        if ($existingId !== null) {
            return $this->shipmentRepository->getById($existingId);
        }

        // Persist the pending row up front so we have a stable ID to
        // reference in events + DLQ payloads, even if the carrier call
        // blows up spectacularly.
        $shipment = $this->createPendingShipment($request, $carrierCode);

        $this->eventManager->dispatch(
            'shubo_shipping_shipment_created',
            ['shipment' => $shipment],
        );

        try {
            $response = $this->callCarrier($carrierCode, $gateway, $request);
        } catch (CircuitOpenException $e) {
            // The breaker refused to call the adapter. Leave the row in
            // pending state so an admin / cron retry can pick it up.
            $this->logger->logDispatchFailed($carrierCode, self::OP_CREATE_SHIPMENT, $e);
            throw $e;
        } catch (\Throwable $e) {
            $this->markFailedAndPublishDlq($shipment, $carrierCode, $e);
            throw new ShipmentDispatchFailedException(
                __('Dispatch failed for carrier %1: %2', $carrierCode, $e->getMessage()),
                $this->asException($e),
            );
        }

        $shipment->setCarrierTrackingId($response->carrierTrackingId);
        $shipment->setLabelUrl($response->labelUrl);
        if ($response->status !== '') {
            $shipment->setStatus($response->status);
        }
        $shipment->setFailedAt(null);
        $shipment->setFailureReason(null);
        $this->shipmentRepository->save($shipment);

        $this->eventManager->dispatch(
            'shubo_shipping_shipment_dispatched',
            [
                'shipment' => $shipment,
                'carrier_tracking_id' => $response->carrierTrackingId,
            ],
        );

        return $shipment;
    }

    public function cancel(int $shipmentId, ?string $reason = null): ShipmentInterface
    {
        $shipment = $this->shipmentRepository->getById($shipmentId);
        $carrierCode = $shipment->getCarrierCode();

        if ($shipment->getStatus() === ShipmentInterface::STATUS_CANCELLED) {
            // Idempotent — already cancelled, no-op.
            return $shipment;
        }

        $trackingId = $shipment->getCarrierTrackingId();
        if ($trackingId === null || $trackingId === '') {
            // Nothing to cancel at the carrier — row never reached dispatch.
            $shipment->setStatus(ShipmentInterface::STATUS_CANCELLED);
            if ($reason !== null) {
                $shipment->setFailureReason($reason);
            }
            $this->shipmentRepository->save($shipment);
            $this->eventManager->dispatch(
                'shubo_shipping_cancelled',
                ['shipment' => $shipment, 'reason' => $reason],
            );
            return $shipment;
        }

        $gateway = $this->carrierRegistry->get($carrierCode);

        try {
            $this->circuitBreaker->execute(
                $carrierCode,
                fn (): object => $this->retryPolicy->execute(
                    $carrierCode,
                    self::OP_CANCEL_SHIPMENT,
                    function () use ($gateway, $carrierCode, $trackingId, $reason): object {
                        if (!$this->rateLimiter->acquire($carrierCode)) {
                            throw \Shubo\ShippingCore\Exception\RateLimitedException::create(
                                null,
                                sprintf('rate-limit exhausted for carrier %s', $carrierCode),
                            );
                        }
                        return $gateway->cancelShipment($trackingId, $reason);
                    },
                ),
            );
        } catch (\Throwable $e) {
            // Cancel is best-effort — we mark the row cancelled locally and
            // log the adapter failure. An admin can reconcile manually.
            $this->logger->logDispatchFailed($carrierCode, self::OP_CANCEL_SHIPMENT, $e);
        }

        $shipment->setStatus(ShipmentInterface::STATUS_CANCELLED);
        if ($reason !== null) {
            $shipment->setFailureReason($reason);
        }
        $this->shipmentRepository->save($shipment);

        $this->eventManager->dispatch(
            'shubo_shipping_cancelled',
            ['shipment' => $shipment, 'reason' => $reason],
        );

        return $shipment;
    }

    public function retry(int $shipmentId): ShipmentInterface
    {
        $shipment = $this->shipmentRepository->getById($shipmentId);

        if ($shipment->getFailedAt() === null) {
            // Nothing to retry — return as-is.
            return $shipment;
        }

        $carrierCode = $shipment->getCarrierCode();
        $gateway = $this->carrierRegistry->get($carrierCode);

        // Reset the failed-* fields before attempting so a fresh failure
        // can be observed cleanly.
        $shipment->setFailedAt(null);
        $shipment->setFailureReason(null);
        $shipment->setStatus(ShipmentInterface::STATUS_PENDING);
        $this->shipmentRepository->save($shipment);

        $request = $this->buildRequestFromShipment($shipment);

        try {
            $response = $this->callCarrier($carrierCode, $gateway, $request);
        } catch (\Throwable $e) {
            $this->markFailedAndPublishDlq($shipment, $carrierCode, $e);
            throw new ShipmentDispatchFailedException(
                __('Retry failed for carrier %1: %2', $carrierCode, $e->getMessage()),
                $this->asException($e),
            );
        }

        $shipment->setCarrierTrackingId($response->carrierTrackingId);
        $shipment->setLabelUrl($response->labelUrl);
        if ($response->status !== '') {
            $shipment->setStatus($response->status);
        }
        $this->shipmentRepository->save($shipment);

        $this->eventManager->dispatch(
            'shubo_shipping_shipment_dispatched',
            [
                'shipment' => $shipment,
                'carrier_tracking_id' => $response->carrierTrackingId,
            ],
        );

        return $shipment;
    }

    /**
     * Invoke the carrier with the full resilience wrapping. See the class
     * docblock for the order.
     */
    private function callCarrier(
        string $carrierCode,
        CarrierGatewayInterface $gateway,
        ShipmentRequest $request,
    ): ShipmentResponse {
        /** @var ShipmentResponse $response */
        $response = $this->circuitBreaker->execute(
            $carrierCode,
            fn (): ShipmentResponse => $this->retryPolicy->execute(
                $carrierCode,
                self::OP_CREATE_SHIPMENT,
                function () use ($gateway, $carrierCode, $request): ShipmentResponse {
                    if (!$this->rateLimiter->acquire($carrierCode)) {
                        throw \Shubo\ShippingCore\Exception\RateLimitedException::create(
                            null,
                            sprintf('rate-limit exhausted for carrier %s', $carrierCode),
                        );
                    }
                    return $gateway->createShipment($request);
                },
            ),
        );
        return $response;
    }

    private function createPendingShipment(ShipmentRequest $request, string $carrierCode): Shipment
    {
        /** @var Shipment $shipment */
        $shipment = $this->shipmentFactory->create();
        $shipment->setOrderId($request->orderId);
        $shipment->setMerchantId($request->merchantId);
        $shipment->setCarrierCode($carrierCode);
        $shipment->setClientTrackingCode($request->clientTrackingCode);
        $shipment->setStatus(ShipmentInterface::STATUS_PENDING);
        $shipment->setDeliveryAddress($this->addressToArray($request));
        $shipment->setParcelWeightGrams($request->parcel->weightGrams);
        $shipment->setParcelValueCents($request->parcel->declaredValueCents);
        $shipment->setCodEnabled($request->codEnabled);
        $shipment->setCodAmountCents($request->codAmountCents);
        $shipment->setMetadata($request->metadata);

        /** @var Shipment $saved */
        $saved = $this->shipmentRepository->save($shipment);
        return $saved;
    }

    /**
     * @return array<string, mixed>
     */
    private function addressToArray(ShipmentRequest $request): array
    {
        $dest = $request->destination;
        return [
            'name' => $dest->name,
            'phone' => $dest->phone,
            'email' => $dest->email,
            'country' => $dest->country,
            'subdivision' => $dest->subdivision,
            'city' => $dest->city,
            'district' => $dest->district,
            'street' => $dest->street,
            'building' => $dest->building,
            'floor' => $dest->floor,
            'apartment' => $dest->apartment,
            'postcode' => $dest->postcode,
            'latitude' => $dest->latitude,
            'longitude' => $dest->longitude,
            'instructions' => $dest->instructions,
            'carrier_extras' => $dest->carrierExtras,
        ];
    }

    /**
     * Reconstruct a minimal ShipmentRequest from a persisted row — used by
     * {@see retry()} so an admin can re-dispatch without the caller having
     * to reassemble all the DTOs.
     *
     * We deliberately only reconstruct the fields the orchestrator itself
     * will touch; a carrier-specific adapter that needs full parcel
     * dimensions should read them from the shipment's metadata_json.
     */
    private function buildRequestFromShipment(ShipmentInterface $shipment): ShipmentRequest
    {
        $address = $shipment->getDeliveryAddress();
        $destination = new \Shubo\ShippingCore\Api\Data\Dto\ContactAddress(
            name: (string)($address['name'] ?? ''),
            phone: (string)($address['phone'] ?? ''),
            email: isset($address['email']) ? (string)$address['email'] : null,
            country: (string)($address['country'] ?? ''),
            subdivision: (string)($address['subdivision'] ?? ''),
            city: (string)($address['city'] ?? ''),
            district: isset($address['district']) ? (string)$address['district'] : null,
            street: (string)($address['street'] ?? ''),
            building: isset($address['building']) ? (string)$address['building'] : null,
            floor: isset($address['floor']) ? (string)$address['floor'] : null,
            apartment: isset($address['apartment']) ? (string)$address['apartment'] : null,
            postcode: isset($address['postcode']) ? (string)$address['postcode'] : null,
            latitude: isset($address['latitude']) ? (float)$address['latitude'] : null,
            longitude: isset($address['longitude']) ? (float)$address['longitude'] : null,
            instructions: isset($address['instructions']) ? (string)$address['instructions'] : null,
            carrierExtras: is_array($address['carrier_extras'] ?? null)
                ? $address['carrier_extras']
                : [],
        );

        $parcel = new \Shubo\ShippingCore\Api\Data\Dto\ParcelSpec(
            weightGrams: $shipment->getParcelWeightGrams(),
            lengthMm: 0,
            widthMm: 0,
            heightMm: 0,
            declaredValueCents: $shipment->getParcelValueCents(),
        );

        // Origin is lost to the persisted row in Phase 4 — retry paths
        // that hit a carrier which needs the full origin must be re-run
        // via Phase 6's RateQuoteService + a fresh dispatch request. For
        // Phase 4 this is acceptable because the FakeCarrierGateway does
        // not examine the origin.
        $emptyOrigin = new \Shubo\ShippingCore\Api\Data\Dto\ContactAddress(
            name: '',
            phone: '',
            email: null,
            country: '',
            subdivision: '',
            city: '',
            district: null,
            street: '',
            building: null,
            floor: null,
            apartment: null,
            postcode: null,
            latitude: null,
            longitude: null,
            instructions: null,
        );

        return new ShipmentRequest(
            orderId: $shipment->getOrderId(),
            merchantId: $shipment->getMerchantId(),
            clientTrackingCode: $shipment->getClientTrackingCode(),
            origin: $emptyOrigin,
            destination: $destination,
            parcel: $parcel,
            codEnabled: $shipment->isCodEnabled(),
            codAmountCents: $shipment->getCodAmountCents(),
            preferredCarrierCode: $shipment->getCarrierCode(),
            metadata: $shipment->getMetadata(),
        );
    }

    private function resolveCarrierCode(ShipmentRequest $request): string
    {
        $preferred = $request->preferredCarrierCode;
        if ($preferred === null || $preferred === '') {
            throw new NoCarrierAvailableException(
                __('No carrier code specified on the dispatch request.'),
            );
        }
        if (!$this->carrierRegistry->has($preferred)) {
            throw new NoCarrierAvailableException(
                __('Carrier "%1" is not registered.', $preferred),
            );
        }
        return $preferred;
    }

    /**
     * Mark the shipment row as failed, publish the failure to the DLQ
     * topic, and fire `shubo_shipping_dispatch_failed`.
     */
    private function markFailedAndPublishDlq(
        ShipmentInterface $shipment,
        string $carrierCode,
        \Throwable $e,
    ): void {
        $shipmentId = (int)($shipment->getShipmentId() ?? 0);
        $reason = $e->getMessage();

        $shipment->setStatus(ShipmentInterface::STATUS_FAILED);
        $shipment->setFailedAt(gmdate('Y-m-d H:i:s'));
        $shipment->setFailureReason($reason);
        try {
            $this->shipmentRepository->save($shipment);
        } catch (\Throwable $saveErr) {
            // If we can't even persist the failure, log but continue the
            // DLQ publish — losing the DB row is far worse than losing the
            // "failed" flag.
            $this->logger->logDispatchFailed($carrierCode, 'persist_failed_state', $saveErr);
        }

        try {
            $this->deadLetterPublisher->publish(
                $shipmentId,
                $carrierCode,
                self::OP_CREATE_SHIPMENT,
                $reason,
            );
        } catch (\Throwable $dlqErr) {
            $this->logger->logDispatchFailed($carrierCode, 'dlq_publish', $dlqErr);
        }

        $this->eventManager->dispatch(
            'shubo_shipping_dispatch_failed',
            [
                'shipment_id' => $shipmentId,
                'carrier_code' => $carrierCode,
                'failure_reason' => $reason,
                'attempts' => RetryPolicy::maxAttempts(),
            ],
        );
    }

    /**
     * Bridge \Throwable to \Exception for {@see LocalizedException} constructors
     * that explicitly type-hint \Exception. Any \Error (e.g. TypeError) is
     * wrapped in a \RuntimeException so the upstream cause is still retained as
     * its getPrevious().
     */
    private function asException(\Throwable $e): \Exception
    {
        if ($e instanceof \Exception) {
            return $e;
        }
        return new \RuntimeException($e->getMessage(), (int)$e->getCode(), $e);
    }
}
