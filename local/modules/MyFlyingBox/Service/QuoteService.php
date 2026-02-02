<?php

namespace MyFlyingBox\Service;

use MyFlyingBox\Model\MyFlyingBoxOffer;
use MyFlyingBox\Model\MyFlyingBoxOfferQuery;
use MyFlyingBox\Model\MyFlyingBoxQuote;
use MyFlyingBox\Model\MyFlyingBoxQuoteQuery;
use MyFlyingBox\Model\MyFlyingBoxService;
use MyFlyingBox\Model\MyFlyingBoxServiceQuery;
use MyFlyingBox\MyFlyingBox;
use Propel\Runtime\ActiveQuery\Criteria;
use Psr\Log\LoggerInterface;
use Thelia\Model\Address;
use Thelia\Model\Cart;
use Thelia\Model\Country;

/**
 * Service for managing shipping quotes
 */
class QuoteService
{
    private const QUOTE_VALIDITY_SECONDS = 1800; // 30 minutes

    private LceApiService $apiService;
    private DimensionService $dimensionService;
    private LoggerInterface $logger;

    public function __construct(
        LceApiService $apiService,
        DimensionService $dimensionService,
        LoggerInterface $logger
    ) {
        $this->apiService = $apiService;
        $this->dimensionService = $dimensionService;
        $this->logger = $logger;
    }

    /**
     * Get or create a quote for the given cart and address
     * Returns cached quote if still valid, otherwise creates a new one
     *
     * @param Cart $cart The shopping cart
     * @param Address|null $address The delivery address (if selected)
     * @param Country|null $country Fallback country for quotes when no address is available
     */
    public function getQuoteForCart(Cart $cart, ?Address $address = null, ?Country $country = null): ?MyFlyingBoxQuote
    {
        if (!$this->apiService->isConfigured()) {
            $this->logger->warning('MyFlyingBox API not configured');
            return null;
        }

        $addressId = $address?->getId();

        // Check for existing valid quote
        try {
            $existingQuote = MyFlyingBoxQuoteQuery::create()
                ->filterByCartId($cart->getId())
                ->filterByAddressId($addressId)
                ->orderByCreatedAt(Criteria::DESC)
                ->findOne();

            if ($existingQuote && $this->isQuoteValid($existingQuote)) {
                return $existingQuote;
            }
        } catch (\Exception $e) {
            // Table may not exist yet
            $this->logger->debug('Quote table not available: ' . $e->getMessage());
        }

        // Create new quote
        return $this->createQuote($cart, $address, $country);
    }

    /**
     * Check if a quote is still valid (not expired)
     */
    private function isQuoteValid(MyFlyingBoxQuote $quote): bool
    {
        $createdAt = $quote->getCreatedAt();
        if (!$createdAt) {
            return false;
        }

        return (time() - $createdAt->getTimestamp()) < self::QUOTE_VALIDITY_SECONDS;
    }

    /**
     * Create a new quote via API
     *
     * @param Cart $cart The shopping cart
     * @param Address|null $address The delivery address (if selected)
     * @param Country|null $country Fallback country for quotes when no address is available
     */
    public function createQuote(Cart $cart, ?Address $address = null, ?Country $country = null): ?MyFlyingBoxQuote
    {
        try {
            // Build shipper address from config
            $shipper = $this->buildShipperAddress();

            if (empty($shipper['city']) || empty($shipper['postal_code'])) {
                $this->logger->warning('Shipper address not configured');
                return null;
            }

            // Build recipient address
            $recipient = $this->buildRecipientAddress($address, $cart, $country);

            if (empty($recipient['country'])) {
                $this->logger->warning('No recipient address available');
                return null;
            }

            // Get parcel data
            $parcels = $this->dimensionService->getParcelDataFromCart($cart);

            // Get active service codes
            $productCodes = $this->getActiveServiceCodes();

            // Build quote params
            $quoteParams = [
                'shipper' => $shipper,
                'recipient' => $recipient,
                'parcels' => $parcels,
            ];

            if (!empty($productCodes)) {
                $quoteParams['product_codes'] = $productCodes;
            }

            // Request quote from API
            $apiResponse = $this->apiService->requestQuote($quoteParams);

            // API v2 returns data in 'data' key
            $quoteData = $apiResponse['data'] ?? $apiResponse['quote'] ?? null;

            if (empty($quoteData)) {
                $this->logger->warning('Empty quote response from API', [
                    'response' => $apiResponse,
                ]);
                return null;
            }

            // Save quote to database
            $quote = new MyFlyingBoxQuote();
            $quote->setCartId($cart->getId());
            $quote->setAddressId($address?->getId());
            $quote->setApiQuoteUuid($quoteData['id'] ?? null);
            $quote->save();

            // Save offers
            if (!empty($quoteData['offers'])) {
                $this->saveOffers($quote, $quoteData['offers']);
            }

            $this->logger->info('Quote created', [
                'quote_id' => $quote->getId(),
                'offers_count' => count($quoteData['offers'] ?? []),
            ]);

            return $quote;

        } catch (\Exception $e) {
            $this->logger->error('Failed to create quote: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            return null;
        }
    }

    /**
     * Build shipper address from module configuration
     */
    private function buildShipperAddress(): array
    {
        return [
            'city' => MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_DEFAULT_SHIPPER_CITY, ''),
            'postal_code' => MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_DEFAULT_SHIPPER_POSTAL_CODE, ''),
            'country' => MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_DEFAULT_SHIPPER_COUNTRY, 'FR'),
        ];
    }

    /**
     * Build recipient address from delivery address, cart customer, or fallback country
     *
     * @param Address|null $address The delivery address (if selected)
     * @param Cart $cart The shopping cart
     * @param Country|null $fallbackCountry Fallback country when no address is available
     */
    private function buildRecipientAddress(?Address $address, Cart $cart, ?Country $fallbackCountry = null): array
    {
        if ($address) {
            $country = $address->getCountry();
            return [
                'city' => $address->getCity() ?? '',
                'postal_code' => $address->getZipcode() ?? '',
                'country' => $country?->getIsoalpha2() ?? 'FR',
            ];
        }

        // Try to get from cart customer's default address
        $customer = $cart->getCustomer();
        if ($customer) {
            $defaultAddress = $customer->getDefaultAddress();
            if ($defaultAddress) {
                $country = $defaultAddress->getCountry();
                return [
                    'city' => $defaultAddress->getCity() ?? '',
                    'postal_code' => $defaultAddress->getZipcode() ?? '',
                    'country' => $country?->getIsoalpha2() ?? 'FR',
                ];
            }
        }

        // Use fallback country with shipper city/postal for estimation
        // API requires city and postal_code, so we use shipper address as estimate
        if ($fallbackCountry) {
            $shipperCity = MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_DEFAULT_SHIPPER_CITY, '');
            $shipperPostal = MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_DEFAULT_SHIPPER_POSTAL_CODE, '');
            $countryCode = $fallbackCountry->getIsoalpha2() ?? 'FR';

            // If same country as shipper, use shipper address for realistic estimate
            // Otherwise use capital city placeholder (API requires non-empty values)
            if ($countryCode === MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_DEFAULT_SHIPPER_COUNTRY, 'FR')) {
                return [
                    'city' => $shipperCity,
                    'postal_code' => $shipperPostal,
                    'country' => $countryCode,
                ];
            }

            // For international, use a generic address (capital)
            $defaultCities = [
                'FR' => ['city' => 'Paris', 'postal_code' => '75001'],
                'BE' => ['city' => 'Bruxelles', 'postal_code' => '1000'],
                'DE' => ['city' => 'Berlin', 'postal_code' => '10115'],
                'ES' => ['city' => 'Madrid', 'postal_code' => '28001'],
                'IT' => ['city' => 'Roma', 'postal_code' => '00100'],
                'GB' => ['city' => 'London', 'postal_code' => 'SW1A 1AA'],
                'NL' => ['city' => 'Amsterdam', 'postal_code' => '1011'],
                'PT' => ['city' => 'Lisboa', 'postal_code' => '1100-001'],
                'CH' => ['city' => 'Zurich', 'postal_code' => '8001'],
                'LU' => ['city' => 'Luxembourg', 'postal_code' => '1111'],
            ];

            $default = $defaultCities[$countryCode] ?? ['city' => 'Paris', 'postal_code' => '75001'];

            return [
                'city' => $default['city'],
                'postal_code' => $default['postal_code'],
                'country' => $countryCode,
            ];
        }

        return [];
    }

    /**
     * Get active service product codes
     */
    private function getActiveServiceCodes(): array
    {
        try {
            $services = MyFlyingBoxServiceQuery::create()
                ->filterByActive(true)
                ->find();

            $codes = [];
            foreach ($services as $service) {
                $codes[] = $service->getCode();
            }

            return $codes;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Save offers from API response (API v2 format)
     */
    private function saveOffers(MyFlyingBoxQuote $quote, array $offersData): void
    {
        foreach ($offersData as $offerData) {
            // Find or create service
            $productCode = $offerData['product']['code'] ?? '';
            $service = $this->findOrCreateService($offerData);

            if (!$service) {
                continue;
            }

            // Create offer
            // API v2 price structure: price.amount (HT), total_price.amount (TTC)
            $offer = new MyFlyingBoxOffer();
            $offer->setQuoteId($quote->getId());
            $offer->setServiceId($service->getId());
            $offer->setApiOfferUuid($offerData['id'] ?? null);
            $offer->setLceProductCode($productCode);
            $offer->setBasePriceInCents($this->priceToCents($offerData['price']['amount'] ?? 0));
            $offer->setTotalPriceInCents($this->priceToCents($offerData['total_price']['amount'] ?? $offerData['price']['amount'] ?? 0));
            $offer->setInsurancePriceInCents($this->priceToCents($offerData['insurance_price']['amount'] ?? 0));
            $offer->setCurrency($offerData['price']['currency'] ?? 'EUR');
            // API v2: delay is in product.delay (e.g., "24" or "48-72")
            $offer->setDeliveryDays($offerData['product']['delay'] ?? null);
            $offer->save();
        }
    }

    /**
     * Find existing service or create new one from offer data (API v2 format)
     */
    private function findOrCreateService(array $offerData): ?MyFlyingBoxService
    {
        $productCode = $offerData['product']['code'] ?? '';

        if (empty($productCode)) {
            return null;
        }

        try {
            $service = MyFlyingBoxServiceQuery::create()
                ->filterByCode($productCode)
                ->findOne();

            if ($service) {
                return $service;
            }

            // Create new service
            // API v2 field names: pick_up, drop_off, preset_delivery_location
            $service = new MyFlyingBoxService();
            $service->setCode($productCode);
            $service->setCarrierCode($offerData['product']['carrier_code'] ?? 'unknown');
            $service->setName($offerData['product']['name'] ?? 'Unknown Service');
            $service->setPickupAvailable($offerData['product']['pick_up'] ?? false);
            $service->setDropoffAvailable($offerData['product']['drop_off'] ?? false);
            $service->setRelayDelivery($offerData['product']['preset_delivery_location'] ?? false);
            $service->setTrackingUrl($offerData['product']['tracking_url'] ?? null);
            $service->setActive(true);
            $service->save();

            return $service;

        } catch (\Exception $e) {
            $this->logger->error('Failed to create service: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Convert price (float euros) to cents (integer)
     */
    private function priceToCents($amount): int
    {
        return (int) round((float) $amount * 100);
    }

    /**
     * Get the best (cheapest) offer price for a quote
     */
    public function getBestOfferPrice(MyFlyingBoxQuote $quote): ?float
    {
        try {
            $offer = MyFlyingBoxOfferQuery::create()
                ->filterByQuoteId($quote->getId())
                ->orderByTotalPriceInCents(Criteria::ASC)
                ->findOne();

            if ($offer) {
                return $offer->getTotalPriceInCents() / 100;
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to get best offer: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Get all offers for a quote
     */
    public function getOffersForQuote(MyFlyingBoxQuote $quote): array
    {
        try {
            return MyFlyingBoxOfferQuery::create()
                ->filterByQuoteId($quote->getId())
                ->orderByTotalPriceInCents(Criteria::ASC)
                ->find()
                ->getData();
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Invalidate (delete) all quotes for a cart
     * Call this when cart contents change
     */
    public function invalidateCartQuotes(int $cartId): void
    {
        try {
            MyFlyingBoxQuoteQuery::create()
                ->filterByCartId($cartId)
                ->delete();
        } catch (\Exception $e) {
            // Silently fail - quotes will be recreated
        }
    }
}
