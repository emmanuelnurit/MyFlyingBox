<?php

namespace MyFlyingBox\Controller\Front;

use MyFlyingBox\Model\MyFlyingBoxCartRelay;
use MyFlyingBox\Model\MyFlyingBoxCartRelayQuery;
use MyFlyingBox\Model\MyFlyingBoxOfferQuery;
use MyFlyingBox\Model\MyFlyingBoxQuoteQuery;
use MyFlyingBox\MyFlyingBox;
use MyFlyingBox\Service\LceApiService;
use MyFlyingBox\Service\QuoteService;
use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Controller\Front\BaseFrontController;
use Thelia\Model\AddressQuery;
use Thelia\Model\CountryQuery;

/**
 * Front-office controller for relay point selection and offer management
 */
class RelayController extends BaseFrontController
{
    /**
     * Save selected offer to session
     */
    public function saveOfferAction(Request $request, EventDispatcherInterface $dispatcher): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            $cartId = $data['cart_id'] ?? null;
            $offerId = $data['offer_id'] ?? null;

            if (!$cartId || !$offerId) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Missing parameters',
                ]);
            }

            // Verify cart belongs to current session
            $sessionCart = $this->getSession()->getSessionCart($dispatcher);
            if (!$sessionCart || $sessionCart->getId() != $cartId) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Invalid cart',
                ]);
            }

            // Verify offer exists and belongs to cart's quote
            $offer = MyFlyingBoxOfferQuery::create()
                ->filterById($offerId)
                ->findOne();

            if (!$offer) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Invalid offer',
                ]);
            }

            // Verify offer belongs to a quote for this cart
            $quote = MyFlyingBoxQuoteQuery::create()
                ->filterById($offer->getQuoteId())
                ->filterByCartId($cartId)
                ->findOne();

            if (!$quote) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Offer does not belong to this cart',
                ]);
            }

            // Save selected offer ID to session
            $this->getSession()->set('mfb_selected_offer_id', $offerId);

            return new JsonResponse([
                'success' => true,
                'message' => 'Offer selection saved',
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error saving offer selection: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Get relay points for a given location
     */
    public function getRelayPointsAction(Request $request, LceApiService $apiService): JsonResponse
    {
        try {
            $query = $request->get('query', '');
            $cartId = $request->get('cart_id');

            if (empty($query) || !$cartId) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Missing parameters',
                ]);
            }

            // Get the latest quote for this cart
            $quote = MyFlyingBoxQuoteQuery::create()
                ->filterByCartId($cartId)
                ->orderByCreatedAt(Criteria::DESC)
                ->findOne();

            if (!$quote) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'No quote found for cart',
                ]);
            }

            // Find a relay offer
            $relayOffer = MyFlyingBoxOfferQuery::create()
                ->filterByQuoteId($quote->getId())
                ->useMyFlyingBoxServiceQuery()
                    ->filterByRelayDelivery(true)
                ->endUse()
                ->findOne();

            if (!$relayOffer || !$relayOffer->getApiOfferUuid()) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'No relay service available',
                ]);
            }

            // Get the country from the quote's address
            $countryCode = 'FR'; // Default to France
            if ($quote->getAddressId()) {
                $address = AddressQuery::create()->findPk($quote->getAddressId());
                if ($address && $address->getCountryId()) {
                    $country = CountryQuery::create()->findPk($address->getCountryId());
                    if ($country) {
                        $countryCode = $country->getIsoalpha2();
                    }
                }
            }

            // Parse query for postal code
            $postalCode = preg_replace('/[^0-9]/', '', $query);
            if (strlen($postalCode) < 5) {
                // Try to extract from query
                if (preg_match('/\b(\d{5})\b/', $query, $matches)) {
                    $postalCode = $matches[1];
                }
            }

            // Get delivery locations from API
            $params = [
                'street' => '',
                'city' => trim(preg_replace('/\d/', '', $query)),
                'country' => $countryCode,
            ];

            if (!empty($postalCode)) {
                $params['postal_code'] = $postalCode;
            }

            $response = $apiService->getDeliveryLocations(
                $relayOffer->getApiOfferUuid(),
                $params
            );

            // API returns data in 'data' key
            $locations = $response['data'] ?? $response['locations'] ?? [];

            if (empty($locations)) {
                return new JsonResponse([
                    'success' => true,
                    'relays' => [],
                    'message' => 'No relay points found',
                ]);
            }

            // Format relay points
            $relays = [];
            foreach ($locations as $location) {
                $relays[] = [
                    'code' => $location['code'] ?? '',
                    'name' => $location['company'] ?? $location['name'] ?? '',
                    'street' => $location['street'] ?? '',
                    'city' => $location['city'] ?? '',
                    'postal_code' => $location['postal_code'] ?? '',
                    'country' => $location['country'] ?? 'FR',
                    'latitude' => $location['latitude'] ?? null,
                    'longitude' => $location['longitude'] ?? null,
                    'distance' => isset($location['distance']) ? round($location['distance'] / 1000, 1) : null,
                    'opening_hours' => $location['opening_hours'] ?? [],
                ];
            }

            return new JsonResponse([
                'success' => true,
                'relays' => $relays,
            ]);

        } catch (\Exception $e) {
            // Log the actual error for debugging
            error_log('MyFlyingBox relay points error: ' . $e->getMessage());

            // Return a user-friendly message, along with success=true and empty relays
            // This allows the frontend to display the fallback message gracefully
            return new JsonResponse([
                'success' => true,
                'relays' => [],
                'message' => 'Service de recherche de points relais temporairement indisponible',
            ]);
        }
    }

    /**
     * Save selected relay point to cart
     */
    public function saveRelayAction(Request $request, EventDispatcherInterface $dispatcher): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            $cartId = $data['cart_id'] ?? null;
            $relayCode = $data['relay_code'] ?? null;

            if (!$cartId || !$relayCode) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Missing parameters',
                ]);
            }

            // Verify cart belongs to current session
            $sessionCart = $this->getSession()->getSessionCart($dispatcher);
            if (!$sessionCart || $sessionCart->getId() != $cartId) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Invalid cart',
                ]);
            }

            // Find or create cart relay record
            $cartRelay = MyFlyingBoxCartRelayQuery::create()
                ->filterByCartId($cartId)
                ->findOne();

            if (!$cartRelay) {
                $cartRelay = new MyFlyingBoxCartRelay();
                $cartRelay->setCartId($cartId);
            }

            // Update relay info
            $cartRelay->setRelayCode($relayCode);
            $cartRelay->setRelayName($data['relay_name'] ?? '');
            $cartRelay->setRelayStreet($data['relay_street'] ?? '');
            $cartRelay->setRelayCity($data['relay_city'] ?? '');
            $cartRelay->setRelayPostalCode($data['relay_postal_code'] ?? '');
            $cartRelay->setRelayCountry($data['relay_country'] ?? 'FR');
            $cartRelay->save();

            return new JsonResponse([
                'success' => true,
                'message' => 'Relay point saved',
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error saving relay point: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Get offers for a cart with relay_delivery information
     * This API is used by React frontend to determine if MyFlyingBox map should be shown
     */
    public function getOffersAction(Request $request, EventDispatcherInterface $dispatcher): JsonResponse
    {
        try {
            $cartId = $request->get('cart_id');

            if (!$cartId) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Missing cart_id parameter',
                ]);
            }

            // Verify cart belongs to current session
            $sessionCart = $this->getSession()->getSessionCart($dispatcher);
            if (!$sessionCart || $sessionCart->getId() != $cartId) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Invalid cart',
                ]);
            }

            // Get the latest quote for this cart
            $quote = MyFlyingBoxQuoteQuery::create()
                ->filterByCartId($cartId)
                ->orderByCreatedAt(Criteria::DESC)
                ->findOne();

            if (!$quote) {
                return new JsonResponse([
                    'success' => true,
                    'offers' => [],
                    'has_relay_offers' => false,
                ]);
            }

            // Get all offers for this quote
            $dbOffers = MyFlyingBoxOfferQuery::create()
                ->filterByQuoteId($quote->getId())
                ->joinWithMyFlyingBoxService()
                ->orderByTotalPriceInCents(Criteria::ASC)
                ->find();

            $offers = [];
            $hasRelayOffers = false;

            foreach ($dbOffers as $offer) {
                $service = $offer->getMyFlyingBoxService();
                if (!$service || !$service->getActive()) {
                    continue;
                }

                $isRelay = (bool) $service->getRelayDelivery();
                if ($isRelay) {
                    $hasRelayOffers = true;
                }

                $offers[] = [
                    'id' => $offer->getId(),
                    'service_code' => $service->getCode(),
                    'service_name' => $service->getName(),
                    'carrier_code' => $service->getCarrierCode(),
                    'price' => $offer->getTotalPriceInCents() / 100,
                    'delivery_days' => $offer->getDeliveryDays(),
                    'relay_delivery' => $isRelay,
                    'api_offer_uuid' => $offer->getApiOfferUuid(),
                ];
            }

            // Get selected offer from session
            $selectedOfferId = $this->getSession()->get('mfb_selected_offer_id');

            // Get selected relay
            $selectedRelay = null;
            $cartRelay = MyFlyingBoxCartRelayQuery::create()
                ->filterByCartId($cartId)
                ->findOne();

            if ($cartRelay) {
                $selectedRelay = [
                    'code' => $cartRelay->getRelayCode(),
                    'name' => $cartRelay->getRelayName(),
                    'street' => $cartRelay->getRelayStreet(),
                    'city' => $cartRelay->getRelayCity(),
                    'postal_code' => $cartRelay->getRelayPostalCode(),
                    'country' => $cartRelay->getRelayCountry(),
                ];
            }

            return new JsonResponse([
                'success' => true,
                'offers' => $offers,
                'has_relay_offers' => $hasRelayOffers,
                'selected_offer_id' => $selectedOfferId,
                'selected_relay' => $selectedRelay,
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error fetching offers: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Get cart delivery estimation data via AJAX
     * Used by skeleton loader pattern on cart page
     */
    public function getCartEstimateAction(
        Request $request,
        EventDispatcherInterface $dispatcher,
        QuoteService $quoteService
    ): JsonResponse {
        try {
            $cartId = $request->get('cart_id');

            if (!$cartId) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Missing cart_id parameter',
                ]);
            }

            // Verify cart belongs to current session
            $sessionCart = $this->getSession()->getSessionCart($dispatcher);
            if (!$sessionCart || $sessionCart->getId() != $cartId) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Invalid cart',
                ]);
            }

            // Check cart has items
            if ($sessionCart->countCartItems() === 0) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Cart is empty',
                ]);
            }

            // Get default delivery country
            $country = CountryQuery::create()->findOneByByDefault(1);
            if (!$country) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'No default country configured',
                ]);
            }

            // Get quote with offers
            $quote = $quoteService->getQuoteForCart($sessionCart, null, $country);
            if (!$quote) {
                return new JsonResponse([
                    'success' => true,
                    'carriers_count' => 0,
                ]);
            }

            // Get formatted offers with prices
            $dbOffers = MyFlyingBoxOfferQuery::create()
                ->filterByQuoteId($quote->getId())
                ->joinWithMyFlyingBoxService()
                ->orderByTotalPriceInCents(Criteria::ASC)
                ->find();

            $prices = [];
            $hasRelay = false;

            foreach ($dbOffers as $offer) {
                $service = $offer->getMyFlyingBoxService();
                if (!$service || !$service->getActive()) {
                    continue;
                }

                $price = $this->applyPriceSurcharges($offer->getTotalPriceInCents() / 100);
                $prices[] = $price;

                if ($service->getRelayDelivery()) {
                    $hasRelay = true;
                }
            }

            if (empty($prices)) {
                return new JsonResponse([
                    'success' => true,
                    'carriers_count' => 0,
                ]);
            }

            $minPrice = min($prices);
            $carriersCount = count($prices);

            // Build carriers label
            if ($carriersCount === 1) {
                $carriersLabel = '1 transporteur disponible';
            } else {
                $carriersLabel = $carriersCount . ' transporteurs disponibles';
            }

            return new JsonResponse([
                'success' => true,
                'min_price' => number_format($minPrice, 2, ',', ' ') . ' €',
                'carriers_count' => $carriersCount,
                'carriers_label' => $carriersLabel,
                'has_relay' => $hasRelay,
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error fetching estimate: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Apply price surcharges (percentage + static)
     */
    private function applyPriceSurcharges(float $price): float
    {
        // Percentage surcharge
        $percentSurcharge = (float) MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_PRICE_SURCHARGE_PERCENT, 0);
        if ($percentSurcharge > 0) {
            $price += $price * ($percentSurcharge / 100);
        }

        // Static surcharge (stored in cents, convert to euros)
        $staticSurcharge = (float) MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_PRICE_SURCHARGE_STATIC, 0);
        if ($staticSurcharge > 0) {
            $price += $staticSurcharge / 100;
        }

        // Rounding
        $roundIncrement = (int) MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_PRICE_ROUND_INCREMENT, 1);
        if ($roundIncrement > 1) {
            $price = ceil($price * 100 / $roundIncrement) * $roundIncrement / 100;
        }

        return round($price, 2);
    }
}
