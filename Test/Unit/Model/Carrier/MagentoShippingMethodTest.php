<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Test\Unit\Model\Carrier;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Address\RateResult\Method;
use Magento\Shipping\Model\Rate\Result;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shubo\ShippingCore\Api\Data\Dto\ContactAddress;
use Shubo\ShippingCore\Api\Data\Dto\QuoteRequest;
use Shubo\ShippingCore\Api\Data\Dto\RateOption;
use Shubo\ShippingCore\Api\RateQuoteServiceInterface;
use Shubo\ShippingCore\Model\Carrier\MagentoShippingMethod;
use Shubo\ShippingCore\Model\Logging\StructuredLogger;

/**
 * Unit tests for {@see MagentoShippingMethod}.
 *
 * Covers the contract from Phase 6 exit criteria:
 *   - carrier code is 'shuboflat'
 *   - disabled config -> collectRates returns false
 *   - no merchant context (observer didn't answer) -> returns false
 *   - observer resolved merchant but NOT origin -> returns false (skip)
 *   - rate service returns options -> Result populated with one Method per option
 *   - rate service throws -> false, never blocks checkout
 *   - rate service returns empty -> false
 *   - getAllowedMethods() exposes the 'standard' method
 *   - priceCents converted to GEL via bcdiv (500 -> "5.00")
 *
 * Factory mocking: the three factories consumed by the carrier
 * (ErrorFactory, ResultFactory, MethodFactory) are Magento ObjectManager-
 * generated classes with no corresponding file in vendor. We stub them
 * as minimal real classes in the bootstrap side-effect block above so
 * PHPUnit's reflection mock builder can synthesize instances.
 */
class MagentoShippingMethodTest extends TestCase
{
    /** @var ScopeConfigInterface&MockObject */
    private ScopeConfigInterface $scopeConfig;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    /** @var object&MockObject */
    private object $rateErrorFactory;

    /** @var object&MockObject */
    private object $rateResultFactory;

    /** @var object&MockObject */
    private object $rateMethodFactory;

    /** @var RateQuoteServiceInterface&MockObject */
    private RateQuoteServiceInterface $rateQuoteService;

    /** @var EventManagerInterface&MockObject */
    private EventManagerInterface $eventManager;

    /** @var StructuredLogger&MockObject */
    private StructuredLogger $structuredLogger;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->rateErrorFactory = $this->createMock(
            'Magento\\Quote\\Model\\Quote\\Address\\RateResult\\ErrorFactory',
        );
        $this->rateResultFactory = $this->createMock(
            'Magento\\Shipping\\Model\\Rate\\ResultFactory',
        );
        $this->rateMethodFactory = $this->createMock(
            'Magento\\Quote\\Model\\Quote\\Address\\RateResult\\MethodFactory',
        );
        $this->rateQuoteService = $this->createMock(RateQuoteServiceInterface::class);
        $this->eventManager = $this->createMock(EventManagerInterface::class);
        $this->structuredLogger = $this->createMock(StructuredLogger::class);
    }

    public function testCarrierCodeIsShuboflat(): void
    {
        $method = $this->newCarrier();

        self::assertSame('shuboflat', $method->getCarrierCode());
    }

    public function testGetAllowedMethodsReturnsStandard(): void
    {
        $method = $this->newCarrier();

        self::assertSame(['standard' => 'Standard'], $method->getAllowedMethods());
    }

    public function testDisabledConfigReturnsFalse(): void
    {
        $this->stubConfigFlag(active: false);
        // The carrier must bail before dispatching any event or calling the
        // quote service when it's disabled.
        $this->eventManager->expects(self::never())->method('dispatch');
        $this->rateQuoteService->expects(self::never())->method('quote');

        $method = $this->newCarrier();

        self::assertFalse($method->collectRates($this->newRateRequest()));
    }

    public function testNoMerchantContextResolvedReturnsFalse(): void
    {
        $this->stubConfigFlag(active: true);
        // Event dispatched but observer does not set merchant_id -> false.
        $this->stubEventSetsContext(merchantId: null, origin: null);
        $this->rateQuoteService->expects(self::never())->method('quote');

        $method = $this->newCarrier();

        self::assertFalse($method->collectRates($this->newRateRequest()));
    }

    public function testMerchantResolvedButNoOriginReturnsFalse(): void
    {
        $this->stubConfigFlag(active: true);
        $this->stubEventSetsContext(merchantId: 7, origin: null);
        $this->rateQuoteService->expects(self::never())->method('quote');

        $method = $this->newCarrier();

        self::assertFalse($method->collectRates($this->newRateRequest()));
    }

    public function testRateServiceReturnsRateBuildsResultWithGelPrice(): void
    {
        $this->stubConfigFlag(active: true);
        $origin = $this->newAddress();
        $this->stubEventSetsContext(merchantId: 7, origin: $origin);

        $option = new RateOption(
            carrierCode: 'shuboflat',
            methodCode: 'standard',
            priceCents: 500,
            etaDays: 2,
            serviceLevel: 'standard',
            rationale: 'chosen',
        );
        $this->rateQuoteService->expects(self::once())
            ->method('quote')
            ->willReturnCallback(function (QuoteRequest $req) use ($origin, $option): array {
                self::assertSame(7, $req->merchantId);
                self::assertSame($origin, $req->origin);
                return [$option];
            });

        $rateResult = $this->makeRecordingResult();
        $this->rateResultFactory->method('create')->willReturn($rateResult);

        $createdMethod = $this->makeStubMethod();
        $this->rateMethodFactory->method('create')->willReturn($createdMethod);

        $carrier = $this->newCarrier();

        $result = $carrier->collectRates($this->newRateRequest());

        self::assertSame($rateResult, $result);
        self::assertSame(1, $rateResult->appendCount);
        self::assertSame('shuboflat', $createdMethod->getCarrier());
        self::assertSame('Shubo Flat Rate', $createdMethod->getCarrierTitle());
        self::assertSame('standard', $createdMethod->getMethod());
        self::assertSame('Standard', $createdMethod->getMethodTitle());
        self::assertSame('5.00', $createdMethod->getPrice());
        self::assertSame('5.00', $createdMethod->getCost());
    }

    public function testRateServiceThrowsReturnsFalse(): void
    {
        $this->stubConfigFlag(active: true);
        $this->stubEventSetsContext(merchantId: 7, origin: $this->newAddress());

        $this->rateQuoteService->method('quote')->willThrowException(new \RuntimeException('boom'));

        // Must log but must NOT throw out of collectRates.
        $this->structuredLogger->expects(self::once())
            ->method('logDispatchFailed')
            ->with('shuboflat', 'collectRates.quote', self::isInstanceOf(\RuntimeException::class));

        $carrier = $this->newCarrier();

        self::assertFalse($carrier->collectRates($this->newRateRequest()));
    }

    public function testRateServiceEmptyReturnsFalse(): void
    {
        $this->stubConfigFlag(active: true);
        $this->stubEventSetsContext(merchantId: 7, origin: $this->newAddress());

        $this->rateQuoteService->method('quote')->willReturn([]);

        $carrier = $this->newCarrier();

        self::assertFalse($carrier->collectRates($this->newRateRequest()));
    }

    public function testPriceCentsUsesBcmathConversion(): void
    {
        // An odd cents value (749 -> "7.49") exercises bcdiv rounding.
        $this->stubConfigFlag(active: true);
        $this->stubEventSetsContext(merchantId: 1, origin: $this->newAddress());

        $option = new RateOption(
            carrierCode: 'shuboflat',
            methodCode: 'standard',
            priceCents: 749,
            etaDays: 2,
            serviceLevel: 'standard',
            rationale: 'chosen',
        );
        $this->rateQuoteService->method('quote')->willReturn([$option]);

        $this->rateResultFactory->method('create')->willReturn($this->makeRecordingResult());

        $createdMethod = $this->makeStubMethod();
        $this->rateMethodFactory->method('create')->willReturn($createdMethod);

        $carrier = $this->newCarrier();

        $carrier->collectRates($this->newRateRequest());

        self::assertSame('7.49', $createdMethod->getPrice());
        self::assertSame('7.49', $createdMethod->getCost());
    }

    /**
     * Build a Method subclass that skips the parent constructor (which
     * wants a PriceCurrencyInterface) and override setPrice to skip the
     * currency->round() call — the carrier is responsible for supplying
     * the already-rounded string, so we just store it verbatim.
     */
    private function makeStubMethod(): Method
    {
        return new class extends Method {
            // phpcs:disable Magento2.PHP.ReturnSelfMagento
            public function __construct()
            {
                // Skip parent constructor — PriceCurrencyInterface irrelevant.
            }

            public function setPrice($price)
            {
                $this->setData('price', $price);
                return $this;
            }
            // phpcs:enable
        };
    }

    private function newCarrier(): MagentoShippingMethod
    {
        return new MagentoShippingMethod(
            $this->scopeConfig,
            // @phpstan-ignore-next-line  ObjectManager-generated factory mock
            $this->rateErrorFactory,
            $this->logger,
            // @phpstan-ignore-next-line  ObjectManager-generated factory mock
            $this->rateResultFactory,
            // @phpstan-ignore-next-line  ObjectManager-generated factory mock
            $this->rateMethodFactory,
            $this->rateQuoteService,
            $this->eventManager,
            $this->structuredLogger,
        );
    }

    private function stubConfigFlag(bool $active): void
    {
        // AbstractCarrier::getConfigFlag returns a bool based on scopeConfig
        // isSetFlag lookup; getConfigData reads from scopeConfig::getValue.
        $this->scopeConfig->method('isSetFlag')->willReturnMap([
            ['carriers/shuboflat/active', 'store', null, $active],
        ]);
        $this->scopeConfig->method('getValue')->willReturnMap([
            ['carriers/shuboflat/title', 'store', null, 'Shubo Flat Rate'],
            ['carriers/shuboflat/name', 'store', null, 'Standard'],
        ]);
    }

    private function stubEventSetsContext(?int $merchantId, ?ContactAddress $origin): void
    {
        $this->eventManager->method('dispatch')->willReturnCallback(
            static function (string $name, array $data) use ($merchantId, $origin): void {
                if ($name !== MagentoShippingMethod::EVENT_RESOLVE_RATE_CONTEXT) {
                    return;
                }
                $context = $data['context'] ?? null;
                if (!$context instanceof DataObject) {
                    return;
                }
                if ($merchantId !== null) {
                    $context->setData('merchant_id', $merchantId);
                }
                if ($origin !== null) {
                    $context->setData('origin', $origin);
                }
            },
        );
    }

    /**
     * A Result subclass that records how many times append() was called
     * without needing the parent's StoreManager dependencies.
     */
    private function makeRecordingResult(): Result
    {
        return new class extends Result {
            public int $appendCount = 0;

            // phpcs:disable Magento2.PHP.ReturnSelfMagento
            public function __construct()
            {
                // Skip parent constructor — irrelevant for this test.
            }

            public function append($result)
            {
                $this->appendCount++;
                return $this;
            }
            // phpcs:enable
        };
    }

    private function newRateRequest(): RateRequest
    {
        $r = new RateRequest();
        $r->setDestCountryId('GE');
        $r->setDestRegionCode('GE-TB');
        $r->setDestCity('Tbilisi');
        $r->setDestStreet('Rustaveli 1');
        $r->setDestPostcode('0105');
        $r->setPackageWeight(2.5);
        $r->setPackageValue(120.0);
        $r->setAllItems([]);
        return $r;
    }

    private function newAddress(): ContactAddress
    {
        return new ContactAddress(
            name: 'Pickup',
            phone: '+995555000000',
            email: null,
            country: 'GE',
            subdivision: 'GE-TB',
            city: 'Tbilisi',
            district: null,
            street: 'Origin Street 1',
            building: null,
            floor: null,
            apartment: null,
            postcode: '0105',
            latitude: null,
            longitude: null,
            instructions: null,
        );
    }
}
