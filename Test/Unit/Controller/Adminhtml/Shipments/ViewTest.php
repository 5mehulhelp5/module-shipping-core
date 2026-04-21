<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Test\Unit\Controller\Adminhtml\Shipments;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Backend\Model\View\Result\RedirectFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Message\ManagerInterface as MessageManagerInterface;
use Magento\Framework\Registry;
use Magento\Backend\Model\View\Result\Page as BackendPage;
use Magento\Framework\View\Page\Config;
use Magento\Framework\View\Page\Title;
use Magento\Framework\View\Result\PageFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shubo\ShippingCore\Api\Data\ShipmentInterface;
use Shubo\ShippingCore\Api\ShipmentRepositoryInterface;
use Shubo\ShippingCore\Controller\Adminhtml\Shipments\View;

/**
 * Unit tests for the detail-view controller.
 *
 * Covers:
 *   - success: repository hit + Registry write + page factory rendering
 *   - missing id: flash error + redirect to grid
 *   - unknown shipment: flash error + redirect to grid
 */
class ViewTest extends TestCase
{
    /** @var Context&MockObject */
    private Context $context;

    /** @var ShipmentRepositoryInterface&MockObject */
    private ShipmentRepositoryInterface $repository;

    /** @var Registry&MockObject */
    private Registry $registry;

    /** @var PageFactory&MockObject */
    private PageFactory $pageFactory;

    /** @var MessageManagerInterface&MockObject */
    private MessageManagerInterface $messageManager;

    /** @var RedirectFactory&MockObject */
    private RedirectFactory $redirectFactory;

    /** @var RequestInterface&MockObject */
    private RequestInterface $request;

    private View $controller;

    protected function setUp(): void
    {
        $this->context = $this->createMock(Context::class);
        $this->repository = $this->createMock(ShipmentRepositoryInterface::class);
        $this->registry = $this->createMock(Registry::class);
        $this->pageFactory = $this->createMock(PageFactory::class);
        $this->messageManager = $this->createMock(MessageManagerInterface::class);
        $this->redirectFactory = $this->createMock(RedirectFactory::class);
        $this->request = $this->createMock(RequestInterface::class);

        $this->context->method('getMessageManager')->willReturn($this->messageManager);
        $this->context->method('getResultRedirectFactory')->willReturn($this->redirectFactory);
        $this->context->method('getRequest')->willReturn($this->request);

        $this->controller = new View(
            $this->context,
            $this->repository,
            $this->registry,
            $this->pageFactory,
        );
    }

    public function testExecuteRendersPageWhenShipmentExists(): void
    {
        $this->request->method('getParam')->with('shipment_id')->willReturn('42');

        $shipment = $this->createMock(ShipmentInterface::class);
        $shipment->method('getShipmentId')->willReturn(42);

        $this->repository->expects($this->once())
            ->method('getById')
            ->with(42)
            ->willReturn($shipment);

        $this->registry->expects($this->once())
            ->method('register')
            ->with(View::REGISTRY_KEY, $shipment, true);

        $page = $this->createMock(BackendPage::class);
        $config = $this->createMock(Config::class);
        $title = $this->createMock(Title::class);
        $page->method('getConfig')->willReturn($config);
        $config->method('getTitle')->willReturn($title);
        $title->expects($this->once())->method('prepend');
        $page->expects($this->once())->method('setActiveMenu')->willReturnSelf();

        $this->pageFactory->method('create')->willReturn($page);

        $result = $this->controller->execute();

        self::assertSame($page, $result);
    }

    public function testExecuteRedirectsWhenIdInvalid(): void
    {
        $this->request->method('getParam')->with('shipment_id')->willReturn('0');

        $this->messageManager->expects($this->once())
            ->method('addErrorMessage')
            ->with($this->stringContains('Invalid'));

        $redirect = $this->createMock(Redirect::class);
        $redirect->expects($this->once())
            ->method('setPath')
            ->with('shubo_shipping_admin/shipments/index')
            ->willReturnSelf();
        $this->redirectFactory->method('create')->willReturn($redirect);

        $this->repository->expects($this->never())->method('getById');

        $result = $this->controller->execute();

        self::assertSame($redirect, $result);
    }

    public function testExecuteRedirectsWhenShipmentNotFound(): void
    {
        $this->request->method('getParam')->with('shipment_id')->willReturn('999');

        $this->repository->expects($this->once())
            ->method('getById')
            ->with(999)
            ->willThrowException(new NoSuchEntityException(__('gone')));

        $this->messageManager->expects($this->once())
            ->method('addErrorMessage')
            ->with($this->stringContains('not found'));

        $redirect = $this->createMock(Redirect::class);
        $redirect->expects($this->once())
            ->method('setPath')
            ->with('shubo_shipping_admin/shipments/index')
            ->willReturnSelf();
        $this->redirectFactory->method('create')->willReturn($redirect);

        $this->registry->expects($this->never())->method('register');

        $result = $this->controller->execute();

        self::assertSame($redirect, $result);
    }
}
