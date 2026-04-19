<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Test\Unit\Controller\Webhook;

use Laminas\Http\Headers;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shubo\ShippingCore\Api\Data\Dto\DispatchResult;
use Shubo\ShippingCore\Controller\Webhook\Receive;
use Shubo\ShippingCore\Model\Logging\StructuredLogger;
use Shubo\ShippingCore\Model\Webhook\WebhookDispatcher;

/**
 * Unit tests for {@see Receive}.
 *
 * Pattern matches {@see \Shubo\ShippingCore\Test\Unit\Controller\Adminhtml\Shipments\MarkDeliveredTest}
 * for mock wiring. Each case asserts the HTTP status code set on the Raw
 * result and whether or not the dispatcher was invoked.
 */
class ReceiveTest extends TestCase
{
    /** @var HttpRequest&MockObject */
    private HttpRequest $request;

    /** @var RawFactory&MockObject */
    private RawFactory $rawFactory;

    /** @var WebhookDispatcher&MockObject */
    private WebhookDispatcher $dispatcher;

    /** @var StructuredLogger&MockObject */
    private StructuredLogger $logger;

    /** @var Raw&MockObject */
    private Raw $raw;

    private int $httpStatus = 0;

    private string $body = '';

    protected function setUp(): void
    {
        $this->request = $this->createMock(HttpRequest::class);
        $this->rawFactory = $this->createMock(RawFactory::class);
        $this->dispatcher = $this->createMock(WebhookDispatcher::class);
        $this->logger = $this->createMock(StructuredLogger::class);

        $this->httpStatus = 0;
        $this->body = '';

        $this->raw = $this->createMock(Raw::class);
        $this->raw->method('setHttpResponseCode')->willReturnCallback(
            function (int $code): Raw {
                $this->httpStatus = $code;
                return $this->raw;
            },
        );
        $this->raw->method('setContents')->willReturnCallback(
            function (string $body): Raw {
                $this->body = $body;
                return $this->raw;
            },
        );

        $this->rawFactory->method('create')->willReturn($this->raw);

        $headersMock = $this->createMock(Headers::class);
        $headersMock->method('toArray')->willReturn([]);
        $this->request->method('getHeaders')->willReturn($headersMock);
    }

    public function testUnknownCarrierMapsTo404(): void
    {
        $this->request->method('getPathInfo')->willReturn('/shubo_shipping/webhook/ghost');
        $this->request->method('getContent')->willReturn('{}');

        $this->dispatcher->method('dispatch')
            ->with('ghost', '{}', self::anything())
            ->willReturn(DispatchResult::unknownCarrier());

        $this->controller()->execute();

        self::assertSame(404, $this->httpStatus);
    }

    public function testRejectedMapsTo400(): void
    {
        $this->request->method('getPathInfo')->willReturn('/shubo_shipping/webhook/wolt');
        $this->request->method('getContent')->willReturn('{}');

        $this->dispatcher->method('dispatch')
            ->willReturn(DispatchResult::rejected('signature_invalid'));

        $this->controller()->execute();

        self::assertSame(400, $this->httpStatus);
    }

    public function testDuplicateMapsTo200(): void
    {
        $this->request->method('getPathInfo')->willReturn('/shubo_shipping/webhook/wolt');
        $this->request->method('getContent')->willReturn('{}');

        $this->dispatcher->method('dispatch')
            ->willReturn(DispatchResult::duplicate('evt-x'));

        $this->controller()->execute();

        self::assertSame(200, $this->httpStatus);
    }

    public function testAcceptedMapsTo200(): void
    {
        $this->request->method('getPathInfo')->willReturn('/shubo_shipping/webhook/wolt');
        $this->request->method('getContent')->willReturn('{}');

        $this->dispatcher->method('dispatch')
            ->willReturn(DispatchResult::accepted('evt-42'));

        $this->controller()->execute();

        self::assertSame(200, $this->httpStatus);
    }

    public function testBodyIsTruncatedToOneMegabyte(): void
    {
        $this->request->method('getPathInfo')->willReturn('/shubo_shipping/webhook/wolt');
        $oneAndAHalfMb = str_repeat('A', 1_572_864);
        $this->request->method('getContent')->willReturn($oneAndAHalfMb);

        $capturedBody = null;
        $this->dispatcher->method('dispatch')
            ->willReturnCallback(function (string $code, string $raw, array $headers) use (&$capturedBody): DispatchResult {
                $capturedBody = $raw;
                return DispatchResult::accepted(null);
            });

        $this->controller()->execute();

        self::assertIsString($capturedBody);
        self::assertSame(1_048_576, strlen($capturedBody));
    }

    public function testEmptyCarrierCodeReturns400WithoutCallingDispatcher(): void
    {
        $this->request->method('getPathInfo')->willReturn('/shubo_shipping/webhook/');
        $this->request->method('getContent')->willReturn('{}');

        $this->dispatcher->expects(self::never())->method('dispatch');

        $this->controller()->execute();

        self::assertSame(400, $this->httpStatus);
    }

    public function testDispatcherThrowsMapsTo500(): void
    {
        $this->request->method('getPathInfo')->willReturn('/shubo_shipping/webhook/wolt');
        $this->request->method('getContent')->willReturn('{}');

        $this->dispatcher->method('dispatch')
            ->willThrowException(new \RuntimeException('boom'));

        $this->controller()->execute();

        self::assertSame(500, $this->httpStatus);
        self::assertSame('ERROR', $this->body);
    }

    public function testCsrfPairIsPermissive(): void
    {
        $controller = $this->controller();
        $req = $this->createMock(\Magento\Framework\App\RequestInterface::class);

        self::assertTrue($controller->validateForCsrf($req));
        self::assertNull($controller->createCsrfValidationException($req));
    }

    private function controller(): Receive
    {
        return new Receive(
            $this->request,
            $this->rawFactory,
            $this->dispatcher,
            $this->logger,
        );
    }
}
