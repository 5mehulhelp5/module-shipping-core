<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Model\Webhook;

use Laminas\Http\Headers;
use Magento\Framework\Phrase;
use Magento\Framework\Webapi\Exception as WebapiException;
use Magento\Framework\Webapi\Rest\Request as RestRequest;
use Shubo\ShippingCore\Api\Data\Dto\DispatchResult;
use Shubo\ShippingCore\Api\WebhookReceiverInterface;

/**
 * REST-route wrapper around {@see WebhookDispatcher}.
 *
 * Magento's webapi layer translates exceptions to HTTP status codes:
 *  - {@see WebapiException::HTTP_BAD_REQUEST} for rejected payloads,
 *  - {@see WebapiException::HTTP_NOT_FOUND} for unknown carrier codes,
 *  - any other unhandled exception becomes 500 (carrier will retry).
 *
 * Successful / duplicate dispatches return the raw status string so the
 * webapi serializer has a simple JSON value to emit.
 */
class WebhookReceiver implements WebhookReceiverInterface
{
    private const MAX_BODY_BYTES = 1_048_576;

    public function __construct(
        private readonly WebhookDispatcher $dispatcher,
        private readonly RestRequest $request,
    ) {
    }

    /**
     * @throws WebapiException
     */
    public function receive(string $carrierCode): string
    {
        $body = (string)$this->request->getContent();
        if (strlen($body) > self::MAX_BODY_BYTES) {
            $body = substr($body, 0, self::MAX_BODY_BYTES);
        }

        $headers = $this->readHeaders();
        $result = $this->dispatcher->dispatch($carrierCode, $body, $headers);

        return match ($result->status) {
            DispatchResult::STATUS_UNKNOWN_CARRIER => throw new WebapiException(
                new Phrase('Unknown carrier: %1', [$carrierCode]),
                0,
                WebapiException::HTTP_NOT_FOUND,
            ),
            DispatchResult::STATUS_REJECTED => throw new WebapiException(
                new Phrase('Webhook rejected: %1', [$result->reason ?? 'unknown']),
                0,
                WebapiException::HTTP_BAD_REQUEST,
            ),
            default => $result->status,
        };
    }

    /**
     * Pull header names/values off the webapi request. The Request class
     * inherits {@see \Laminas\Http\Request::getHeaders()}; we coerce into
     * a plain array to keep the handler contract narrow.
     *
     * @return array<string, string>
     */
    private function readHeaders(): array
    {
        $headers = $this->request->getHeaders();
        if (!$headers instanceof Headers) {
            return [];
        }

        /** @var array<string, string|array<int, string>> $raw */
        $raw = $headers->toArray();
        $normalized = [];
        foreach ($raw as $name => $value) {
            if (is_array($value)) {
                $normalized[(string)$name] = implode(', ', array_map('strval', $value));
                continue;
            }
            $normalized[(string)$name] = (string)$value;
        }
        return $normalized;
    }
}
