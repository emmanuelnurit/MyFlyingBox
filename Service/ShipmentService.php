<?php

namespace MyFlyingBox\Service;

use MyFlyingBox\Model\MyFlyingBoxCartRelay;
use MyFlyingBox\Model\MyFlyingBoxCartRelayQuery;
use MyFlyingBox\Model\MyFlyingBoxParcel;
use MyFlyingBox\Model\MyFlyingBoxParcelQuery;
use MyFlyingBox\Model\MyFlyingBoxQuoteQuery;
use MyFlyingBox\Model\MyFlyingBoxOfferQuery;
use MyFlyingBox\Model\MyFlyingBoxService;
use MyFlyingBox\Model\MyFlyingBoxServiceQuery;
use MyFlyingBox\Model\MyFlyingBoxShipment;
use MyFlyingBox\Model\MyFlyingBoxShipmentQuery;
use MyFlyingBox\Model\MyFlyingBoxShipmentEvent;
use MyFlyingBox\MyFlyingBox;
use Propel\Runtime\ActiveQuery\Criteria;
use Psr\Log\LoggerInterface;
use Thelia\Model\Order;
use Thelia\Model\OrderAddress;

/**
 * Service for managing shipments
 */
class ShipmentService
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_BOOKED = 'booked';
    public const STATUS_SHIPPED = 'shipped';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_CANCELLED = 'cancelled';

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
     * Create a shipment record for an order
     */
    public function createShipmentFromOrder(Order $order, ?int $serviceId = null, ?string $offerUuid = null): ?MyFlyingBoxShipment
    {
        try {
            // Get delivery address
            $deliveryAddress = $order->getOrderAddressRelatedByDeliveryOrderAddressId();
            if (!$deliveryAddress) {
                $this->logger->error('No delivery address for order ' . $order->getId());
                return null;
            }

            // Get service
            $service = null;
            if ($serviceId) {
                $service = MyFlyingBoxServiceQuery::create()->findPk($serviceId);
            }

            // Get relay if selected
            $relay = null;
            if ($order->getCartId()) {
                $relay = MyFlyingBoxCartRelayQuery::create()
                    ->filterByCartId($order->getCartId())
                    ->findOne();
            }

            // Get quote UUID from cart if available
            $quoteUuid = null;
            if ($order->getCartId()) {
                $quote = MyFlyingBoxQuoteQuery::create()
                    ->filterByCartId($order->getCartId())
                    ->orderByCreatedAt(Criteria::DESC)
                    ->findOne();
                if ($quote) {
                    $quoteUuid = $quote->getApiQuoteUuid();
                }
            }

            // Create shipment
            $shipment = new MyFlyingBoxShipment();
            $shipment->setOrderId($order->getId());
            $shipment->setServiceId($serviceId);
            $shipment->setApiQuoteUuid($quoteUuid);
            $shipment->setApiOfferUuid($offerUuid);
            $shipment->setStatus(self::STATUS_PENDING);
            $shipment->setIsReturn(false);

            // Set shipper info from config
            $shipment->setShipperName(MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_DEFAULT_SHIPPER_NAME, ''));
            $shipment->setShipperCompany(MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_DEFAULT_SHIPPER_COMPANY, ''));
            $shipment->setShipperStreet(MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_DEFAULT_SHIPPER_STREET, ''));
            $shipment->setShipperCity(MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_DEFAULT_SHIPPER_CITY, ''));
            $shipment->setShipperPostalCode(MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_DEFAULT_SHIPPER_POSTAL_CODE, ''));
            $shipment->setShipperCountry(MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_DEFAULT_SHIPPER_COUNTRY, 'FR'));
            $shipment->setShipperPhone(MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_DEFAULT_SHIPPER_PHONE, ''));
            $shipment->setShipperEmail(MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_DEFAULT_SHIPPER_EMAIL, ''));

            // Set recipient info from order
            $this->setRecipientFromAddress($shipment, $deliveryAddress, $order, $relay);

            $shipment->save();

            // Create default parcel from order
            $this->createDefaultParcel($shipment, $order);

            // Create creation event in history
            $this->createShipmentEvent(
                $shipment->getId(),
                'CREATED',
                'Expédition créée pour la commande #' . $order->getRef(),
                new \DateTime()
            );

            $this->logger->info('Shipment created', [
                'shipment_id' => $shipment->getId(),
                'order_id' => $order->getId(),
            ]);

            return $shipment;

        } catch (\Exception $e) {
            $this->logger->error('Failed to create shipment: ' . $e->getMessage(), [
                'order_id' => $order->getId(),
                'exception' => $e,
            ]);
            return null;
        }
    }

    /**
     * Set recipient info from delivery address and optional relay
     */
    private function setRecipientFromAddress(MyFlyingBoxShipment $shipment, OrderAddress $address, Order $order, ?MyFlyingBoxCartRelay $relay = null): void
    {
        // Base recipient info
        $name = trim($address->getFirstname() . ' ' . $address->getLastname());
        $shipment->setRecipientName($name);
        $shipment->setRecipientCompany($address->getCompany());

        // Phone is required - try multiple sources
        $phone = $address->getPhone() ?: $address->getCellphone();
        if (empty($phone) && $order->getCustomer()) {
            // Fallback to customer's default address phone
            $defaultAddress = $order->getCustomer()->getDefaultAddress();
            if ($defaultAddress) {
                $phone = $defaultAddress->getPhone() ?: $defaultAddress->getCellphone();
            }
        }
        $shipment->setRecipientPhone($phone ?: '0000000000'); // API requires phone

        // Email is required - get from customer
        $customer = $order->getCustomer();
        $email = $customer ? $customer->getEmail() : '';
        $shipment->setRecipientEmail($email);

        // Use relay address if available and relay delivery
        if ($relay && $relay->getRelayCode()) {
            $shipment->setRelayDeliveryCode($relay->getRelayCode());
            $shipment->setRelayName($relay->getRelayName());
            $shipment->setRelayStreet($relay->getRelayStreet());
            $shipment->setRelayCity($relay->getRelayCity());
            $shipment->setRelayPostalCode($relay->getRelayPostalCode());
            $shipment->setRelayCountry($relay->getRelayCountry());
            // Also set recipient address to relay for API
            $shipment->setRecipientStreet($relay->getRelayStreet());
            $shipment->setRecipientCity($relay->getRelayCity());
            $shipment->setRecipientPostalCode($relay->getRelayPostalCode());
            $shipment->setRecipientCountry($relay->getRelayCountry());
        } else {
            // Use delivery address
            $street = $address->getAddress1();
            if ($address->getAddress2()) {
                $street .= "\n" . $address->getAddress2();
            }
            if ($address->getAddress3()) {
                $street .= "\n" . $address->getAddress3();
            }

            $shipment->setRecipientStreet($street);
            $shipment->setRecipientCity($address->getCity());
            $shipment->setRecipientPostalCode($address->getZipcode());
            $shipment->setRecipientCountry($address->getCountry()?->getIsoalpha2() ?? 'FR');
        }
    }

    /**
     * Create default parcel from order products
     */
    private function createDefaultParcel(MyFlyingBoxShipment $shipment, Order $order): void
    {
        // Calculate total weight
        $totalWeight = 0;
        $totalValue = 0;
        $descriptions = [];

        foreach ($order->getOrderProducts() as $product) {
            $totalWeight += ($product->getWeight() ?? 0) * $product->getQuantity();
            $totalValue += ($product->getPrice() * $product->getQuantity()) * 100; // Convert to cents
            $descriptions[] = $product->getTitle();
        }

        if ($totalWeight <= 0) {
            $totalWeight = MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_DEFAULT_WEIGHT, 1);
        }

        // Get dimensions from weight
        $dimensions = $this->dimensionService->getDimensionsForWeight($totalWeight);

        // Create parcel
        $parcel = new MyFlyingBoxParcel();
        $parcel->setShipmentId($shipment->getId());
        $parcel->setWeight($totalWeight);
        $parcel->setLength($dimensions['length']);
        $parcel->setWidth($dimensions['width']);
        $parcel->setHeight($dimensions['height']);
        $parcel->setValue((int) $totalValue);
        $parcel->setCurrency('EUR');
        $parcel->setDescription(implode(', ', array_slice($descriptions, 0, 3)));
        $parcel->setShipperReference($order->getRef());
        $parcel->save();
    }

    /**
     * Book a shipment with the carrier via API (with detailed error reporting)
     *
     * @return array{success: bool, error: string|null}
     */
    public function bookShipmentWithDetails(MyFlyingBoxShipment $shipment, ?\DateTime $collectionDate = null): array
    {
        if (!$this->apiService->isConfigured()) {
            $this->logger->error('API not configured');
            return ['success' => false, 'error' => 'API credentials not configured. Please configure API login and password.'];
        }

        try {
            // Get parcels
            $parcels = MyFlyingBoxParcelQuery::create()
                ->filterByShipmentId($shipment->getId())
                ->find();

            if ($parcels->count() === 0) {
                $this->logger->error('No parcels for shipment ' . $shipment->getId());
                return ['success' => false, 'error' => 'No parcels defined for this shipment.'];
            }

            // Get order for fallback data
            $order = \Thelia\Model\OrderQuery::create()->findPk($shipment->getOrderId());

            // Ensure recipient email and phone are set (fallback from order)
            $recipientEmail = $shipment->getRecipientEmail();
            $recipientPhone = $shipment->getRecipientPhone();

            if (empty($recipientEmail) && $order && $order->getCustomer()) {
                $recipientEmail = $order->getCustomer()->getEmail();
                $shipment->setRecipientEmail($recipientEmail);
                $shipment->save();
            }

            if (empty($recipientPhone) && $order) {
                $deliveryAddress = $order->getOrderAddressRelatedByDeliveryOrderAddressId();
                if ($deliveryAddress) {
                    $recipientPhone = $deliveryAddress->getPhone() ?: $deliveryAddress->getCellphone();
                }
                if (empty($recipientPhone)) {
                    $recipientPhone = '+33612345678'; // API requires valid phone
                }
                $shipment->setRecipientPhone($recipientPhone);
                $shipment->save();
            }

            // Format recipient phone for API
            $recipientPhone = $this->formatPhoneForApi($recipientPhone, $shipment->getRecipientCountry() ?: 'FR');

            // Format shipper phone for API
            $shipperPhone = $this->formatPhoneForApi(
                $shipment->getShipperPhone(),
                $shipment->getShipperCountry() ?: 'FR'
            );

            // Validate shipper email
            $shipperEmail = $shipment->getShipperEmail();
            if (empty($shipperEmail) || !filter_var($shipperEmail, FILTER_VALIDATE_EMAIL)) {
                $shipperEmail = $recipientEmail ?: 'noreply@example.com';
            }

            // Build API request
            $orderParams = [
                'shipper' => [
                    'name' => $shipment->getShipperName() ?: 'Expéditeur',
                    'company' => $shipment->getShipperCompany(),
                    'street' => $shipment->getShipperStreet() ?: 'Adresse',
                    'city' => $shipment->getShipperCity() ?: 'Paris',
                    'postal_code' => $shipment->getShipperPostalCode() ?: '75001',
                    'country' => $shipment->getShipperCountry() ?: 'FR',
                    'phone' => $shipperPhone,
                    'email' => $shipperEmail,
                ],
                'recipient' => [
                    'name' => $shipment->getRecipientName(),
                    'company' => $shipment->getRecipientCompany(),
                    'street' => $shipment->getRecipientStreet(),
                    'city' => $shipment->getRecipientCity(),
                    'postal_code' => $shipment->getRecipientPostalCode(),
                    'country' => $shipment->getRecipientCountry(),
                    'phone' => $recipientPhone,
                    'email' => $recipientEmail,
                ],
                'parcels' => [],
            ];

            // Add relay code if present and not empty
            $relayCode = $shipment->getRelayDeliveryCode();
            $hasRelayCode = !empty($relayCode) && trim($relayCode) !== '';
            if ($hasRelayCode) {
                $orderParams['delivery_location_code'] = $relayCode;
            }

            // Always request a fresh quote when booking
            $offerResult = $this->getOfferIdFromQuoteWithDetails($shipment, $parcels);

            if (empty($offerResult['offer_id'])) {
                $this->logger->error('No offer ID available for shipment ' . $shipment->getId());
                return ['success' => false, 'error' => $offerResult['error'] ?? 'No shipping offer available for this route.'];
            }

            $orderParams['offer_id'] = $offerResult['offer_id'];

            // Add collection date
            if ($collectionDate) {
                $orderParams['collection_date'] = $collectionDate->format('Y-m-d');
                $shipment->setCollectionDate($collectionDate);
            }

            // Add parcels
            foreach ($parcels as $parcel) {
                $orderParams['parcels'][] = [
                    'length' => $parcel->getLength(),
                    'width' => $parcel->getWidth(),
                    'height' => $parcel->getHeight(),
                    'weight' => $parcel->getWeight(),
                    'shipper_reference' => $parcel->getShipperReference(),
                    'recipient_reference' => $parcel->getRecipientReference(),
                    'customer_reference' => $parcel->getCustomerReference(),
                    'description' => $parcel->getDescription(),
                    'value' => $parcel->getValue(),
                    'currency' => $parcel->getCurrency(),
                ];
            }

            // Call API
            $response = $this->apiService->placeOrder($orderParams);

            // API v2 returns data in 'data' or 'order' key
            $orderData = $response['data'] ?? $response['order'] ?? null;

            if (empty($orderData['id'])) {
                $this->logger->error('Invalid API response for order booking', ['response' => $response]);
                return ['success' => false, 'error' => 'Invalid API response: no order ID returned.'];
            }

            // Update shipment
            $shipment->setApiOrderUuid($orderData['id']);
            $shipment->setStatus(self::STATUS_BOOKED);
            $shipment->setDateBooking(new \DateTime());
            $shipment->save();

            // Update parcels with tracking info
            if (!empty($orderData['parcels'])) {
                $this->updateParcelsFromResponse($shipment, $orderData['parcels']);
            }

            // Create booking event in history
            $this->createShipmentEvent(
                $shipment->getId(),
                'BOOKED',
                'Expédition réservée auprès du transporteur',
                new \DateTime()
            );

            $this->logger->info('Shipment booked', [
                'shipment_id' => $shipment->getId(),
                'api_order_uuid' => $orderData['id'],
            ]);

            return ['success' => true, 'error' => null];

        } catch (\Exception $e) {
            $this->logger->error('Failed to book shipment: ' . $e->getMessage(), [
                'shipment_id' => $shipment->getId(),
                'exception' => $e,
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Book a shipment with the carrier via API
     */
    public function bookShipment(MyFlyingBoxShipment $shipment, ?\DateTime $collectionDate = null): bool
    {
        $result = $this->bookShipmentWithDetails($shipment, $collectionDate);
        return $result['success'];
    }

    /**
     * Update parcels with tracking info from API response
     */
    private function updateParcelsFromResponse(MyFlyingBoxShipment $shipment, array $apiParcels): void
    {
        $parcels = MyFlyingBoxParcelQuery::create()
            ->filterByShipmentId($shipment->getId())
            ->find();

        foreach ($parcels as $index => $parcel) {
            if (isset($apiParcels[$index])) {
                $apiParcel = $apiParcels[$index];

                // Update tracking number (try different possible keys)
                $trackingNumber = $apiParcel['tracking_number']
                    ?? $apiParcel['tracking']
                    ?? $apiParcel['parcel_tracking_number']
                    ?? null;
                if ($trackingNumber) {
                    $parcel->setTrackingNumber($trackingNumber);
                }

                // Update label URL (try different possible keys from LCE API v2)
                $labelUrl = $apiParcel['label']
                    ?? $apiParcel['label_url']
                    ?? $apiParcel['labels']['pdf'] ?? null;

                // If label is an array, try to get the URL
                if (is_array($labelUrl)) {
                    $labelUrl = $labelUrl['url'] ?? $labelUrl['pdf'] ?? $labelUrl[0] ?? null;
                }

                if ($labelUrl) {
                    $parcel->setLabelUrl($labelUrl);
                }

                $this->logger->debug('Updated parcel from API response', [
                    'parcel_id' => $parcel->getId(),
                    'tracking_number' => $trackingNumber,
                    'label_url' => $labelUrl,
                    'api_parcel_keys' => array_keys($apiParcel),
                ]);

                $parcel->save();
            }
        }
    }

    /**
     * Get all labels for a shipment as URLs
     * If labels are not in database, fetches them from API
     */
    public function getLabels(MyFlyingBoxShipment $shipment): array
    {
        $labels = [];

        $parcels = MyFlyingBoxParcelQuery::create()
            ->filterByShipmentId($shipment->getId())
            ->find();

        // Check if we need to fetch labels from API
        $needsFetch = false;
        foreach ($parcels as $parcel) {
            if (empty($parcel->getLabelUrl())) {
                $needsFetch = true;
                break;
            }
        }

        // If shipment is booked and labels are missing, fetch from API
        if ($needsFetch && $shipment->getApiOrderUuid()) {
            $this->fetchLabelsFromApi($shipment, $parcels);
            // Reload parcels to get updated label URLs
            $parcels = MyFlyingBoxParcelQuery::create()
                ->filterByShipmentId($shipment->getId())
                ->find();
        }

        foreach ($parcels as $parcel) {
            if ($parcel->getLabelUrl()) {
                $labels[] = [
                    'parcel_id' => $parcel->getId(),
                    'tracking_number' => $parcel->getTrackingNumber(),
                    'label_url' => $parcel->getLabelUrl(),
                ];
            }
        }

        return $labels;
    }

    /**
     * Fetch labels from LCE API and update parcels
     */
    private function fetchLabelsFromApi(MyFlyingBoxShipment $shipment, $parcels): void
    {
        try {
            $orderUuid = $shipment->getApiOrderUuid();
            if (empty($orderUuid)) {
                $this->logger->warning('Cannot fetch labels: no order UUID', [
                    'shipment_id' => $shipment->getId(),
                ]);
                return;
            }

            // First try: Get order details from API (may include parcel labels)
            $response = $this->apiService->getOrder($orderUuid);

            // API v2 returns data in 'data' or 'order' key
            $orderData = $response['data'] ?? $response['order'] ?? $response;

            $this->logger->info('Fetched order from API for labels', [
                'shipment_id' => $shipment->getId(),
                'order_uuid' => $orderUuid,
                'has_parcels' => !empty($orderData['parcels']),
                'response_keys' => array_keys($orderData),
            ]);

            if (!empty($orderData['parcels'])) {
                $this->updateParcelsFromResponse($shipment, $orderData['parcels']);
            }

            // Second try: If still no labels, use the direct labels URL
            // The API returns a raw PDF, so we construct the URL directly
            $parcelsWithoutLabels = MyFlyingBoxParcelQuery::create()
                ->filterByShipmentId($shipment->getId())
                ->filterByLabelUrl(null)
                ->count();

            if ($parcelsWithoutLabels > 0) {
                $this->logger->info('Labels still missing, using direct label URL', [
                    'shipment_id' => $shipment->getId(),
                    'parcels_without_labels' => $parcelsWithoutLabels,
                ]);

                // The API returns a raw PDF file, so we use the direct URL
                $labelUrl = $this->apiService->getLabelUrl($orderUuid, 'pdf');
                $this->updateAllParcelsWithLabel($shipment, $labelUrl);

                $this->logger->info('Set label URL for all parcels', [
                    'shipment_id' => $shipment->getId(),
                    'label_url' => $labelUrl,
                ]);
            }

        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch labels from API: ' . $e->getMessage(), [
                'shipment_id' => $shipment->getId(),
                'order_uuid' => $shipment->getApiOrderUuid(),
            ]);
        }
    }

    /**
     * Update all parcels with a single combined label URL
     */
    private function updateAllParcelsWithLabel(MyFlyingBoxShipment $shipment, string $labelUrl): void
    {
        $parcels = MyFlyingBoxParcelQuery::create()
            ->filterByShipmentId($shipment->getId())
            ->find();

        foreach ($parcels as $parcel) {
            if (empty($parcel->getLabelUrl())) {
                $parcel->setLabelUrl($labelUrl);
                $parcel->save();
            }
        }
    }

    /**
     * Update parcels from an array of label URLs
     */
    private function updateParcelsFromLabelsArray(MyFlyingBoxShipment $shipment, array $labels): void
    {
        $parcels = MyFlyingBoxParcelQuery::create()
            ->filterByShipmentId($shipment->getId())
            ->find();

        foreach ($parcels as $index => $parcel) {
            if (empty($parcel->getLabelUrl()) && isset($labels[$index])) {
                $labelUrl = is_array($labels[$index]) ? ($labels[$index]['url'] ?? null) : $labels[$index];
                if ($labelUrl) {
                    $parcel->setLabelUrl($labelUrl);
                    $parcel->save();
                }
            }
        }
    }

    /**
     * Get shipment by order ID
     */
    public function getShipmentByOrderId(int $orderId): ?MyFlyingBoxShipment
    {
        return MyFlyingBoxShipmentQuery::create()
            ->filterByOrderId($orderId)
            ->filterByIsReturn(false)
            ->findOne();
    }

    /**
     * Get return shipments for an order
     *
     * @param int $orderId The order ID
     * @return MyFlyingBoxShipment[]
     */
    public function getReturnShipmentsByOrderId(int $orderId): array
    {
        return MyFlyingBoxShipmentQuery::create()
            ->filterByOrderId($orderId)
            ->filterByIsReturn(true)
            ->orderByCreatedAt(Criteria::DESC)
            ->find()
            ->getData();
    }

    /**
     * Create a return shipment from an existing shipment
     *
     * This method creates a new shipment with:
     * - Shipper and recipient addresses swapped (customer becomes shipper, store becomes recipient)
     * - is_return flag set to true
     * - Option to use a different carrier service
     *
     * @param MyFlyingBoxShipment $originalShipment The original outbound shipment
     * @param int|null $serviceId Optional service ID for the return (defaults to original service)
     * @return MyFlyingBoxShipment|null The created return shipment or null on failure
     */
    public function createReturnShipment(MyFlyingBoxShipment $originalShipment, ?int $serviceId = null): ?MyFlyingBoxShipment
    {
        try {
            // Validate original shipment status - must be at least shipped
            $validStatuses = [self::STATUS_SHIPPED, self::STATUS_DELIVERED];
            if (!in_array($originalShipment->getStatus(), $validStatuses)) {
                $this->logger->warning('Cannot create return shipment: original shipment not shipped/delivered', [
                    'original_shipment_id' => $originalShipment->getId(),
                    'status' => $originalShipment->getStatus(),
                ]);
                return null;
            }

            // Get the order
            $order = \Thelia\Model\OrderQuery::create()->findPk($originalShipment->getOrderId());
            if (!$order) {
                $this->logger->error('Cannot create return shipment: order not found', [
                    'order_id' => $originalShipment->getOrderId(),
                ]);
                return null;
            }

            // Use provided service ID or fall back to original shipment's service
            $returnServiceId = $serviceId ?? $originalShipment->getServiceId();

            // Create the return shipment
            $returnShipment = new MyFlyingBoxShipment();
            $returnShipment->setOrderId($originalShipment->getOrderId());
            $returnShipment->setServiceId($returnServiceId);
            $returnShipment->setStatus(self::STATUS_PENDING);
            $returnShipment->setIsReturn(true);

            // SWAP: Original recipient becomes shipper (customer sending back)
            $returnShipment->setShipperName($originalShipment->getRecipientName());
            $returnShipment->setShipperCompany($originalShipment->getRecipientCompany());
            $returnShipment->setShipperStreet($originalShipment->getRecipientStreet());
            $returnShipment->setShipperCity($originalShipment->getRecipientCity());
            $returnShipment->setShipperPostalCode($originalShipment->getRecipientPostalCode());
            $returnShipment->setShipperCountry($originalShipment->getRecipientCountry());
            $returnShipment->setShipperPhone($originalShipment->getRecipientPhone());
            $returnShipment->setShipperEmail($originalShipment->getRecipientEmail());

            // SWAP: Original shipper becomes recipient (store receiving the return)
            // Use store config for most accurate/current address
            $returnShipment->setRecipientName(MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_DEFAULT_SHIPPER_NAME, $originalShipment->getShipperName()));
            $returnShipment->setRecipientCompany(MyFlyingBox::getConfigValue('myflyingbox_shipper_company', $originalShipment->getShipperCompany()));
            $returnShipment->setRecipientStreet(MyFlyingBox::getConfigValue('myflyingbox_shipper_street', $originalShipment->getShipperStreet()));
            $returnShipment->setRecipientCity(MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_DEFAULT_SHIPPER_CITY, $originalShipment->getShipperCity()));
            $returnShipment->setRecipientPostalCode(MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_DEFAULT_SHIPPER_POSTAL_CODE, $originalShipment->getShipperPostalCode()));
            $returnShipment->setRecipientCountry(MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_DEFAULT_SHIPPER_COUNTRY, $originalShipment->getShipperCountry() ?? 'FR'));
            $returnShipment->setRecipientPhone(MyFlyingBox::getConfigValue('myflyingbox_shipper_phone', $originalShipment->getShipperPhone()));
            $returnShipment->setRecipientEmail(MyFlyingBox::getConfigValue('myflyingbox_shipper_email', $originalShipment->getShipperEmail()));

            $returnShipment->save();

            // Copy parcels from original shipment with adjusted data
            $this->createReturnParcels($returnShipment, $originalShipment, $order);

            $this->logger->info('Return shipment created', [
                'return_shipment_id' => $returnShipment->getId(),
                'original_shipment_id' => $originalShipment->getId(),
                'order_id' => $order->getId(),
            ]);

            return $returnShipment;

        } catch (\Exception $e) {
            $this->logger->error('Failed to create return shipment: ' . $e->getMessage(), [
                'original_shipment_id' => $originalShipment->getId(),
                'exception' => $e,
            ]);
            return null;
        }
    }

    /**
     * Create parcels for a return shipment based on original shipment
     */
    private function createReturnParcels(MyFlyingBoxShipment $returnShipment, MyFlyingBoxShipment $originalShipment, Order $order): void
    {
        // Get original parcels
        $originalParcels = MyFlyingBoxParcelQuery::create()
            ->filterByShipmentId($originalShipment->getId())
            ->find();

        foreach ($originalParcels as $originalParcel) {
            $returnParcel = new MyFlyingBoxParcel();
            $returnParcel->setShipmentId($returnShipment->getId());
            $returnParcel->setWeight($originalParcel->getWeight());
            $returnParcel->setLength($originalParcel->getLength());
            $returnParcel->setWidth($originalParcel->getWidth());
            $returnParcel->setHeight($originalParcel->getHeight());
            $returnParcel->setValue($originalParcel->getValue());
            $returnParcel->setCurrency($originalParcel->getCurrency());
            $returnParcel->setDescription('Retour - ' . $originalParcel->getDescription());
            $returnParcel->setShipperReference('RET-' . $order->getRef());
            // Leave tracking_number and label_url empty - will be filled when booked
            $returnParcel->save();
        }
    }

    /**
     * Update shipment status
     */
    public function updateStatus(MyFlyingBoxShipment $shipment, string $status): void
    {
        $oldStatus = $shipment->getStatus();
        $shipment->setStatus($status);
        $shipment->save();

        // Create status change event in history
        $statusLabels = [
            self::STATUS_PENDING => 'En attente',
            self::STATUS_BOOKED => 'Réservée',
            self::STATUS_SHIPPED => 'Expédiée',
            self::STATUS_DELIVERED => 'Livrée',
            self::STATUS_CANCELLED => 'Annulée',
        ];
        $label = $statusLabels[$status] ?? $status;
        $this->createShipmentEvent(
            $shipment->getId(),
            'STATUS_CHANGE',
            'Statut modifié : ' . $label,
            new \DateTime()
        );
    }

    /**
     * Cancel a shipment
     *
     * If the shipment has been booked (has an API order UUID), this method will
     * attempt to cancel it via the LCE API before updating the local status.
     *
     * @param MyFlyingBoxShipment $shipment The shipment to cancel
     * @return bool True if cancellation succeeded, false otherwise
     */
    public function cancelShipment(MyFlyingBoxShipment $shipment): bool
    {
        // Only pending or booked shipments can be cancelled
        if (!in_array($shipment->getStatus(), [self::STATUS_PENDING, self::STATUS_BOOKED])) {
            $this->logger->warning('Cannot cancel shipment: invalid status', [
                'shipment_id' => $shipment->getId(),
                'current_status' => $shipment->getStatus(),
            ]);
            return false;
        }

        $apiOrderUuid = $shipment->getApiOrderUuid();

        // If shipment was booked with the API, try to cancel it there first
        if (!empty($apiOrderUuid)) {
            $this->logger->info('Attempting to cancel shipment via LCE API', [
                'shipment_id' => $shipment->getId(),
                'api_order_uuid' => $apiOrderUuid,
            ]);

            try {
                $response = $this->apiService->cancelOrder($apiOrderUuid);

                $this->logger->info('LCE API cancellation successful', [
                    'shipment_id' => $shipment->getId(),
                    'api_order_uuid' => $apiOrderUuid,
                    'response' => $response,
                ]);
            } catch (\RuntimeException $e) {
                $errorMessage = $e->getMessage();

                // Check if the error is recoverable or not
                // Some errors mean we should still cancel locally (e.g., already cancelled)
                // Others mean we cannot cancel (e.g., already shipped/delivered)
                $isAlreadyCancelled = stripos($errorMessage, 'cancelled') !== false
                    || stripos($errorMessage, 'annul') !== false;
                $isAlreadyShipped = stripos($errorMessage, 'shipped') !== false
                    || stripos($errorMessage, 'delivered') !== false
                    || stripos($errorMessage, 'expedi') !== false
                    || stripos($errorMessage, 'livr') !== false;
                $isNotFound = stripos($errorMessage, 'not found') !== false
                    || stripos($errorMessage, '404') !== false;

                if ($isAlreadyShipped) {
                    // Shipment has already been shipped, cannot cancel
                    $this->logger->error('Cannot cancel shipment: already shipped/delivered', [
                        'shipment_id' => $shipment->getId(),
                        'api_order_uuid' => $apiOrderUuid,
                        'error' => $errorMessage,
                    ]);
                    return false;
                }

                if ($isAlreadyCancelled || $isNotFound) {
                    // Shipment is already cancelled or not found in API, proceed with local cancellation
                    $this->logger->warning('LCE API cancellation skipped: order already cancelled or not found', [
                        'shipment_id' => $shipment->getId(),
                        'api_order_uuid' => $apiOrderUuid,
                        'error' => $errorMessage,
                    ]);
                } else {
                    // Unknown error - log but allow local cancellation to proceed
                    // This ensures the merchant can always cancel locally even if API is unavailable
                    $this->logger->error('LCE API cancellation failed, proceeding with local cancellation', [
                        'shipment_id' => $shipment->getId(),
                        'api_order_uuid' => $apiOrderUuid,
                        'error' => $errorMessage,
                    ]);
                }
            }
        } else {
            $this->logger->info('Cancelling shipment locally (not booked with API)', [
                'shipment_id' => $shipment->getId(),
            ]);
        }

        // Update local status
        $shipment->setStatus(self::STATUS_CANCELLED);
        $shipment->save();

        // Create cancellation event in history
        $this->createShipmentEvent(
            $shipment->getId(),
            'CANCELLED',
            'Expédition annulée',
            new \DateTime()
        );

        $this->logger->info('Shipment cancelled successfully', [
            'shipment_id' => $shipment->getId(),
            'was_booked' => !empty($apiOrderUuid),
        ]);

        return true;
    }

    /**
     * Get available services for a shipment destination
     */
    public function getAvailableServices(): array
    {
        try {
            return MyFlyingBoxServiceQuery::create()
                ->filterByActive(true)
                ->orderByName()
                ->find()
                ->getData();
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get an offer ID by requesting a fresh quote from API
     *
     * @return array{offer_id: string|null, error: string|null}
     */
    private function getOfferIdFromQuoteWithDetails(MyFlyingBoxShipment $shipment, $parcels): array
    {
        try {
            // Get shipper data from shipment, with fallback to module config
            $shipperCity = $shipment->getShipperCity();
            $shipperPostalCode = $shipment->getShipperPostalCode();
            $shipperCountry = $shipment->getShipperCountry();

            // Fallback to module configuration if shipment data is empty
            if (empty($shipperCity)) {
                $shipperCity = MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_DEFAULT_SHIPPER_CITY, '');
                if (!empty($shipperCity)) {
                    $shipment->setShipperCity($shipperCity);
                }
            }
            if (empty($shipperPostalCode)) {
                $shipperPostalCode = MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_DEFAULT_SHIPPER_POSTAL_CODE, '');
                if (!empty($shipperPostalCode)) {
                    $shipment->setShipperPostalCode($shipperPostalCode);
                }
            }
            if (empty($shipperCountry)) {
                $shipperCountry = MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_DEFAULT_SHIPPER_COUNTRY, 'FR');
                $shipment->setShipperCountry($shipperCountry);
            }

            // Also fill other shipper fields from config if empty
            if (empty($shipment->getShipperName())) {
                $shipment->setShipperName(MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_DEFAULT_SHIPPER_NAME, ''));
            }
            if (empty($shipment->getShipperStreet())) {
                $shipment->setShipperStreet(MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_DEFAULT_SHIPPER_STREET, ''));
            }
            if (empty($shipment->getShipperPhone())) {
                $shipment->setShipperPhone(MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_DEFAULT_SHIPPER_PHONE, ''));
            }
            if (empty($shipment->getShipperEmail())) {
                $shipment->setShipperEmail(MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_DEFAULT_SHIPPER_EMAIL, ''));
            }

            // Save updated shipment if any changes
            $shipment->save();

            // Validate addresses before API call
            if (empty($shipperCity) || empty($shipperPostalCode)) {
                return ['offer_id' => null, 'error' => "Shipper address incomplete: city ('{$shipperCity}') and postal code ('{$shipperPostalCode}') are required. Please configure shipper address in module settings."];
            }
            if (empty($shipment->getRecipientCity()) || empty($shipment->getRecipientPostalCode())) {
                return ['offer_id' => null, 'error' => 'Recipient address incomplete: city and postal code are required.'];
            }

            // Build quote request
            $quoteParams = [
                'shipper' => [
                    'city' => $shipperCity,
                    'postal_code' => $shipperPostalCode,
                    'country' => $shipperCountry ?: 'FR',
                ],
                'recipient' => [
                    'city' => $shipment->getRecipientCity(),
                    'postal_code' => $shipment->getRecipientPostalCode(),
                    'country' => $shipment->getRecipientCountry() ?: 'FR',
                    'is_a_company' => !empty($shipment->getRecipientCompany()),
                ],
                'parcels' => [],
            ];

            // Add parcels
            foreach ($parcels as $parcel) {
                $quoteParams['parcels'][] = [
                    'length' => $parcel->getLength() ?: 20,
                    'width' => $parcel->getWidth() ?: 20,
                    'height' => $parcel->getHeight() ?: 20,
                    'weight' => $parcel->getWeight() ?: 1,
                ];
            }

            // Filter by service if selected
            if ($shipment->getServiceId()) {
                $service = MyFlyingBoxServiceQuery::create()->findPk($shipment->getServiceId());
                if ($service) {
                    $quoteParams['product_codes'] = [$service->getCode()];
                }
            }

            // Request quote from API
            $response = $this->apiService->requestQuote($quoteParams);

            // API v2 returns data in 'data' key
            $quoteData = $response['data'] ?? $response['quote'] ?? null;

            if (empty($quoteData['offers'])) {
                $this->logger->error('No offers in quote response', [
                    'response_keys' => array_keys($response),
                    'quote_data' => $quoteData,
                ]);
                $serviceName = '';
                if ($shipment->getServiceId()) {
                    $service = MyFlyingBoxServiceQuery::create()->findPk($shipment->getServiceId());
                    $serviceName = $service ? " ({$service->getName()})" : '';
                }
                return [
                    'offer_id' => null,
                    'error' => "No shipping offers available for route {$shipment->getShipperCity()} ({$shipment->getShipperCountry()}) → {$shipment->getRecipientCity()} ({$shipment->getRecipientCountry()}){$serviceName}. Check if the service supports this route.",
                ];
            }

            // Get the first offer (or match by service code)
            $offers = $quoteData['offers'];
            $selectedOffer = null;
            $hasRelayCode = !empty($shipment->getRelayDeliveryCode());

            $this->logger->info('Quote offers received', [
                'total_offers' => count($offers),
                'has_relay_code' => $hasRelayCode,
                'shipment_id' => $shipment->getId(),
            ]);

            // Filter offers: exclude relay services (if no relay code) and return services (if not a return shipment)
            $filteredOffers = [];
            $isReturnShipment = $shipment->getIsReturn() ?? false;

            foreach ($offers as $offer) {
                $productCode = $offer['product']['code'] ?? '';
                $isRelayService = $offer['product']['preset_delivery_location'] ?? false;
                $isReturnService = str_contains(strtolower($productCode), 'retour');

                // Exclude relay services if no relay code
                if (!$hasRelayCode && $isRelayService) {
                    $this->logger->debug('Excluded relay offer', [
                        'offer_id' => $offer['id'] ?? 'unknown',
                        'product_code' => $productCode,
                    ]);
                    continue;
                }

                // Exclude return services if not a return shipment
                if (!$isReturnShipment && $isReturnService) {
                    $this->logger->debug('Excluded return offer', [
                        'offer_id' => $offer['id'] ?? 'unknown',
                        'product_code' => $productCode,
                    ]);
                    continue;
                }

                $filteredOffers[] = $offer;
            }

            $this->logger->info('Filtered offers', [
                'filtered_count' => count($filteredOffers),
                'excluded_count' => count($offers) - count($filteredOffers),
            ]);

            if ($shipment->getServiceId()) {
                $service = MyFlyingBoxServiceQuery::create()->findPk($shipment->getServiceId());
                if ($service) {
                    foreach ($filteredOffers as $offer) {
                        if (($offer['product']['code'] ?? '') === $service->getCode()) {
                            $selectedOffer = $offer;
                            break;
                        }
                    }
                }
            }

            // Fallback to first non-relay offer
            if (!$selectedOffer && !empty($filteredOffers)) {
                $selectedOffer = $filteredOffers[0];
            }

            if ($selectedOffer && !empty($selectedOffer['id'])) {
                // Save offer ID for future reference
                $shipment->setApiOfferUuid($selectedOffer['id']);
                $shipment->setApiQuoteUuid($quoteData['id'] ?? null);
                $shipment->save();

                $this->logger->info('Selected offer for booking', [
                    'offer_id' => $selectedOffer['id'],
                    'product_code' => $selectedOffer['product']['code'] ?? 'unknown',
                    'product_name' => $selectedOffer['product']['name'] ?? 'unknown',
                    'is_relay' => $selectedOffer['product']['preset_delivery_location'] ?? false,
                    'shipment_id' => $shipment->getId(),
                ]);

                return ['offer_id' => $selectedOffer['id'], 'error' => null];
            }

            $this->logger->error('No valid offer found after filtering', [
                'shipment_id' => $shipment->getId(),
                'has_relay_code' => $hasRelayCode,
            ]);

            $serviceName = '';
            if ($shipment->getServiceId()) {
                $service = MyFlyingBoxServiceQuery::create()->findPk($shipment->getServiceId());
                $serviceName = $service ? " for service '{$service->getName()}'" : '';
            }
            return [
                'offer_id' => null,
                'error' => "No valid offer found{$serviceName}. " . count($offers) . " offers received but all were filtered out (relay/return services excluded).",
            ];

        } catch (\Exception $e) {
            $this->logger->error('Failed to get offer from quote: ' . $e->getMessage());
            return ['offer_id' => null, 'error' => 'Quote API error: ' . $e->getMessage()];
        }
    }

    /**
     * Get an offer ID by requesting a fresh quote from API (legacy - returns only ID)
     */
    private function getOfferIdFromQuote(MyFlyingBoxShipment $shipment, $parcels): ?string
    {
        $result = $this->getOfferIdFromQuoteWithDetails($shipment, $parcels);
        return $result['offer_id'];
    }

    /**
     * Format phone number for carrier API (international format)
     */
    private function formatPhoneForApi(?string $phone, string $country = 'FR'): string
    {
        if (empty($phone)) {
            // Default valid phone by country
            return $country === 'FR' ? '+33612345678' : '+33612345678';
        }

        // Keep only digits
        $digits = preg_replace('/\D/', '', $phone);

        // Country code prefixes
        $countryPrefixes = [
            'FR' => '33',
            'BE' => '32',
            'CH' => '41',
            'DE' => '49',
            'ES' => '34',
            'IT' => '39',
            'GB' => '44',
            'NL' => '31',
            'PT' => '351',
            'LU' => '352',
        ];

        $prefix = $countryPrefixes[$country] ?? '33';

        // If too short, return default
        if (strlen($digits) < 9) {
            $this->logger->warning('Phone number too short, using default', [
                'original' => $phone,
                'digits' => $digits,
                'country' => $country,
            ]);
            return '+' . $prefix . '612345678';
        }

        // If starts with country prefix, just add +
        if (str_starts_with($digits, $prefix)) {
            return '+' . $digits;
        }

        // If starts with 0 (national format), replace with country prefix
        if (str_starts_with($digits, '0')) {
            return '+' . $prefix . substr($digits, 1);
        }

        // Otherwise assume it needs the country prefix
        return '+' . $prefix . $digits;
    }

    /**
     * Create a shipment event in history
     */
    public function createShipmentEvent(
        int $shipmentId,
        string $eventCode,
        string $eventLabel,
        ?\DateTime $eventDate = null,
        ?int $parcelId = null,
        ?string $location = null
    ): MyFlyingBoxShipmentEvent {
        $event = new MyFlyingBoxShipmentEvent();
        $event->setShipmentId($shipmentId);
        $event->setEventCode($eventCode);
        $event->setEventLabel($eventLabel);
        $event->setEventDate($eventDate ?? new \DateTime());

        if ($parcelId !== null) {
            $event->setParcelId($parcelId);
        }

        if ($location !== null) {
            $event->setLocation($location);
        }

        $event->save();

        $this->logger->info('Created shipment event', [
            'shipment_id' => $shipmentId,
            'event_code' => $eventCode,
            'event_label' => $eventLabel,
        ]);

        return $event;
    }
}
