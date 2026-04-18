<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Model\Carrier;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
use Magento\Quote\Model\Quote\Address\RateResult\Method;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Shipping\Model\Rate\Result;
use Magento\Shipping\Model\Rate\ResultFactory;
use Psr\Log\LoggerInterface;
use Shubo\ShippingCore\Api\Data\Dto\ContactAddress;
use Shubo\ShippingCore\Api\Data\Dto\ParcelSpec;
use Shubo\ShippingCore\Api\Data\Dto\QuoteRequest;
use Shubo\ShippingCore\Api\Data\Dto\RateOption;
use Shubo\ShippingCore\Api\RateQuoteServiceInterface;
use Shubo\ShippingCore\Model\Logging\StructuredLogger;

/**
 * Magento shipping carrier model that fronts {@see RateQuoteServiceInterface}.
 *
 * Registered as carrier `shuboflat`. Configured in `etc/config.xml` and
 * `etc/adminhtml/system.xml`. Exposes a single method `standard`.
 *
 * Behavior contract (design doc 13.1):
 *   - Carrier disabled in config: return false.
 *   - RateQuoteService throws or returns empty: return false; NEVER block
 *     checkout.
 *   - Merchant context cannot be resolved: return false. The cart is
 *     either empty or not marketplace-scoped, so no flat-rate quote.
 *   - One Magento Rate\Result per carrier call; one Method row per
 *     returned {@see RateOption}. priceCents is converted to GEL with
 *     bcdiv to dodge float rounding.
 *
 * Carrier code is intentionally `shuboflat` (no underscore) — see
 * {@see FlatRateGateway} for the rationale.
 */
class MagentoShippingMethod extends AbstractCarrier implements CarrierInterface
{
    public const EVENT_RESOLVE_RATE_CONTEXT = 'shubo_shipping_resolve_rate_context';

    /**
     * @var string
     */
    protected $_code = 'shuboflat';

    /**
     * @var bool
     */
    protected $_isFixed = true;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param ErrorFactory $rateErrorFactory
     * @param LoggerInterface $logger
     * @param ResultFactory $rateResultFactory
     * @param MethodFactory $rateMethodFactory
     * @param RateQuoteServiceInterface $rateQuoteService
     * @param EventManagerInterface $eventManager
     * @param StructuredLogger $structuredLogger
     * @param array $data Carrier-config overrides; Magento ObjectManager passes this from config.xml.
     * @phpstan-param array<string, mixed> $data
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ErrorFactory $rateErrorFactory,
        LoggerInterface $logger,
        private readonly ResultFactory $rateResultFactory,
        private readonly MethodFactory $rateMethodFactory,
        private readonly RateQuoteServiceInterface $rateQuoteService,
        private readonly EventManagerInterface $eventManager,
        private readonly StructuredLogger $structuredLogger,
        array $data = [],
    ) {
        parent::__construct($scopeConfig, $rateErrorFactory, $logger, $data);
    }

    /**
     * Magento-required map of method codes this carrier exposes.
     *
     * @return array<string, string>
     */
    public function getAllowedMethods(): array
    {
        return [FlatRateGateway::METHOD_CODE => 'Standard'];
    }

    /**
     * Collect and get rates for the supplied rate request.
     *
     * Returns Result on success, bool on any terminal condition. Magento
     * treats false as "carrier not applicable" and omits it from the
     * checkout rate list, which is the correct behavior when:
     *   - the carrier is disabled
     *   - no merchant context can be resolved for the cart
     *   - the rate service returns no options
     *   - any unexpected exception occurs
     *
     * @param RateRequest $request
     * @return Result|bool
     */
    public function collectRates(RateRequest $request)
    {
        if (!$this->getConfigFlag('active')) {
            return false;
        }

        try {
            $quoteRequest = $this->buildQuoteRequest($request);
        } catch (\Throwable $e) {
            // Context build failed: we lost merchant resolution or the
            // request is malformed. Fall through silently; the observer
            // path will never dispatch a rate for a merchant-less cart.
            $this->structuredLogger->logDispatchFailed(
                FlatRateGateway::CARRIER_CODE,
                'collectRates.buildContext',
                $e,
            );
            return false;
        }
        if ($quoteRequest === null) {
            return false;
        }

        try {
            $options = $this->rateQuoteService->quote($quoteRequest);
        } catch (\Throwable $e) {
            // Never let a carrier outage block checkout. Drop the rate
            // and let Magento fall back to other carriers.
            $this->structuredLogger->logDispatchFailed(
                FlatRateGateway::CARRIER_CODE,
                'collectRates.quote',
                $e,
            );
            return false;
        }

        if ($options === []) {
            return false;
        }

        /** @var Result $result */
        $result = $this->rateResultFactory->create();
        foreach ($options as $option) {
            $result->append($this->buildRateMethod($option));
        }
        return $result;
    }

    /**
     * Build a QuoteRequest from the Magento rate request, resolving merchant
     * context via a mutable DataObject event. If no observer answers, a
     * merchantId of 0 signals an unresolved context and the caller returns
     * false.
     *
     * Origin address is also populated via the event when the answering
     * observer knows the merchant's default pickup address. Flat-rate does
     * not require a real origin; we accept an empty address in its absence.
     *
     * @param RateRequest $request
     * @return QuoteRequest|null
     */
    private function buildQuoteRequest(RateRequest $request): ?QuoteRequest
    {
        $context = new DataObject([
            'merchant_id' => null,
            'origin' => null,
            // Pass items so the answering observer can resolve the merchant
            // from the first product in the cart.
            'items' => $request->getAllItems() ?? [],
            'rate_request' => $request,
        ]);

        $this->eventManager->dispatch(
            self::EVENT_RESOLVE_RATE_CONTEXT,
            ['context' => $context],
        );

        $merchantId = (int)($context->getData('merchant_id') ?? 0);
        if ($merchantId <= 0) {
            return null;
        }

        $origin = $context->getData('origin');
        if (!$origin instanceof ContactAddress) {
            // Observer resolved merchant but not pickup: skip per
            // design-doc 13.2 ("merchants with no default pickup address
            // are skipped").
            return null;
        }

        $destination = $this->destinationFromRequest($request);
        $parcel = $this->parcelFromRequest($request);

        return new QuoteRequest(
            merchantId: $merchantId,
            origin: $origin,
            destination: $destination,
            parcel: $parcel,
        );
    }

    /**
     * Extract the customer's shipping destination from the rate request.
     *
     * @param RateRequest $request
     * @return ContactAddress
     */
    private function destinationFromRequest(RateRequest $request): ContactAddress
    {
        // RateRequest carries destination fields as magic setters on a
        // DataObject — see magento/module-quote RateRequest.php docblock.
        $country = (string)($request->getDestCountryId() ?? '');
        $subdivision = (string)($request->getDestRegionCode() ?? '');
        $city = (string)($request->getDestCity() ?? '');
        $street = (string)($request->getDestStreet() ?? '');
        $postcode = (string)($request->getDestPostcode() ?? '');

        return new ContactAddress(
            name: '',
            phone: '',
            email: null,
            country: $country,
            subdivision: $subdivision,
            city: $city,
            district: null,
            street: $street,
            building: null,
            floor: null,
            apartment: null,
            postcode: $postcode === '' ? null : $postcode,
            latitude: null,
            longitude: null,
            instructions: null,
        );
    }

    /**
     * Extract parcel dimensions + declared value from the rate request.
     *
     * @param RateRequest $request
     * @return ParcelSpec
     */
    private function parcelFromRequest(RateRequest $request): ParcelSpec
    {
        $weightKg = (float)($request->getPackageWeight() ?? 0.0);
        $valueGel = (float)($request->getPackageValue() ?? 0.0);

        return new ParcelSpec(
            weightGrams: (int)round($weightKg * 1000.0),
            lengthMm: 0,
            widthMm: 0,
            heightMm: 0,
            declaredValueCents: (int)round($valueGel * 100.0),
        );
    }

    /**
     * Map a single RateOption to a Magento rate Method row.
     *
     * @param RateOption $option
     * @return Method
     */
    private function buildRateMethod(RateOption $option): Method
    {
        /** @var Method $method */
        $method = $this->rateMethodFactory->create();

        // bcdiv keeps us in string space through the conversion; Magento's
        // setPrice accepts either string or float. bcdiv returns "5.00" for
        // integer 500 which is exactly what we want displayed at checkout.
        $priceGel = bcdiv((string)$option->priceCents, '100', 2);

        $method->setCarrier(FlatRateGateway::CARRIER_CODE);
        $method->setCarrierTitle((string)$this->getConfigData('title'));
        $method->setMethod($option->methodCode);
        $method->setMethodTitle((string)$this->getConfigData('name'));
        $method->setPrice($priceGel);
        $method->setCost($priceGel);

        return $method;
    }
}
