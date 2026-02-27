<?php

namespace MyFlyingBox\Service;

use MyFlyingBox\MyFlyingBox;
use Psr\Log\LoggerInterface;

/**
 * Service for communicating with the LCE (Low Cost Express) API
 * API Documentation: https://www.myflyingbox.com/api
 */
class LceApiService
{
    private const API_URL_STAGING = 'https://test.myflyingbox.com/v2';
    private const API_URL_PRODUCTION = 'https://api.myflyingbox.com/v2';

    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Get API credentials from module configuration
     */
    private function getCredentials(): array
    {
        return [
            'login' => MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_API_LOGIN),
            'password' => MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_API_PASSWORD),
        ];
    }

    /**
     * Get the API base URL based on environment
     */
    public function getApiUrl(): string
    {
        $env = MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_API_ENV, 'staging');
        return $env === 'production' ? self::API_URL_PRODUCTION : self::API_URL_STAGING;
    }

    /**
     * Check if API credentials are configured
     */
    public function isConfigured(): bool
    {
        $credentials = $this->getCredentials();
        return !empty($credentials['login']) && !empty($credentials['password']);
    }

    /**
     * Make an HTTP request to the LCE API
     *
     * @param string $method HTTP method (GET, POST, PUT, DELETE)
     * @param string $endpoint API endpoint (e.g., '/quotes', '/orders')
     * @param array $data Request body data for POST/PUT
     * @return array Decoded JSON response
     * @throws \RuntimeException If request fails
     */
    private function request(string $method, string $endpoint, array $data = []): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('LCE API credentials not configured');
        }

        $credentials = $this->getCredentials();
        $url = $this->getApiUrl() . $endpoint;

        $ch = curl_init();

        // Base options
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $credentials['login'] . ':' . $credentials['password'],
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: Thelia-MyFlyingBox/1.0',
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        // Method-specific options
        switch (strtoupper($method)) {
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                break;
            case 'PUT':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                break;
            case 'DELETE':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                break;
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        // Log request for debugging
        $this->logger->debug('LCE API Request', [
            'method' => $method,
            'url' => $url,
            'http_code' => $httpCode,
        ]);

        // Handle curl errors
        if ($errno) {
            $this->logger->error('LCE API curl error', [
                'errno' => $errno,
                'error' => $error,
            ]);
            throw new \RuntimeException('API request failed: ' . $error);
        }

        // Decode response
        $decoded = json_decode($response, true);

        // Handle API failure status (may return HTTP 200 but with status: failure)
        if (isset($decoded['status']) && $decoded['status'] === 'failure') {
            $errorMessage = $this->extractErrorMessage($decoded);

            $this->logger->error('LCE API returned failure status', [
                'http_code' => $httpCode,
                'error' => $errorMessage,
                'response' => $response,
            ]);

            throw new \RuntimeException('API error: ' . $errorMessage);
        }

        // Handle HTTP errors
        if ($httpCode >= 400) {
            $errorMessage = $this->extractErrorMessage($decoded);

            $this->logger->error('LCE API HTTP error', [
                'http_code' => $httpCode,
                'error' => $errorMessage,
                'response' => $response,
            ]);

            throw new \RuntimeException('API error (' . $httpCode . '): ' . $errorMessage);
        }

        return $decoded ?? [];
    }

    /**
     * Extract a meaningful error message from API response
     *
     * The LCE API returns errors in this format:
     * {"error": {"type": "...", "message": "...", "details": ["..."]}}
     *
     * The details array often contains the actual specific error,
     * while message is generic (e.g., "Order not booked.").
     */
    private function extractErrorMessage(?array $decoded): string
    {
        if (!isset($decoded['error'])) {
            return $decoded['message'] ?? 'Unknown API error';
        }

        $error = $decoded['error'];

        if (!is_array($error)) {
            return $error;
        }

        // If details are available, use the first detail as it's more specific
        if (!empty($error['details']) && is_array($error['details'])) {
            $firstDetail = $error['details'][0];
            // Extract just the meaningful part if it follows the pattern "CODE : message"
            if (is_string($firstDetail) && str_contains($firstDetail, ' : ')) {
                return $firstDetail;
            }
            return $firstDetail;
        }

        // Fall back to message or type
        return $error['message'] ?? $error['type'] ?? 'Unknown error';
    }

    /**
     * Test API connection with current credentials
     *
     * @throws \RuntimeException If connection fails
     */
    public function testConnection(): bool
    {
        // This will throw an exception if the connection fails
        $this->request('GET', '/products');
        return true;
    }

    /**
     * Test API connection with custom credentials (for form testing before save)
     *
     * @param string $login API login
     * @param string $password API password (empty = use saved password)
     * @param string $environment 'staging' or 'production'
     * @return array ['success' => bool, 'server_url' => string, 'error' => string|null]
     */
    public function testConnectionWithCredentials(string $login, string $password, string $environment): array
    {
        // Use provided credentials, or fall back to saved ones if empty
        $effectiveLogin = !empty($login) ? $login : MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_API_LOGIN);
        $effectivePassword = !empty($password) ? $password : MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_API_PASSWORD);
        $serverUrl = $environment === 'production' ? self::API_URL_PRODUCTION : self::API_URL_STAGING;

        if (empty($effectiveLogin) || empty($effectivePassword)) {
            return [
                'success' => false,
                'server_url' => $serverUrl,
                'error' => 'API credentials not configured',
            ];
        }

        $url = $serverUrl . '/products';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $effectiveLogin . ':' . $effectivePassword,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return [
                'success' => false,
                'server_url' => $serverUrl,
                'error' => 'Connection error: ' . $curlError,
            ];
        }

        if ($httpCode >= 400) {
            $decoded = json_decode($response, true);
            $errorMessage = 'HTTP ' . $httpCode;
            if (isset($decoded['error'])) {
                if (is_array($decoded['error'])) {
                    $errorMessage = $decoded['error']['message'] ?? $decoded['error']['type'] ?? $errorMessage;
                } else {
                    $errorMessage = $decoded['error'];
                }
            }
            return [
                'success' => false,
                'server_url' => $serverUrl,
                'error' => $errorMessage,
            ];
        }

        return [
            'success' => true,
            'server_url' => $serverUrl,
            'error' => null,
        ];
    }

    /**
     * Get available products (shipping services/carriers)
     *
     * @return array List of products with carrier codes, names, features
     */
    public function getProducts(): array
    {
        return $this->request('GET', '/products');
    }

    /**
     * Request a shipping quote
     *
     * @param array $params Quote parameters:
     *   - shipper: array with city, postal_code, country
     *   - recipient: array with city, postal_code, country, is_a_company
     *   - parcels: array of parcels with length, width, height, weight
     *   - product_codes: (optional) array of product codes to filter
     * @return array Quote response with offers
     */
    public function requestQuote(array $params): array
    {
        $this->logger->info('Requesting LCE quote', [
            'shipper_country' => $params['shipper']['country'] ?? 'unknown',
            'recipient_country' => $params['recipient']['country'] ?? 'unknown',
            'parcels_count' => count($params['parcels'] ?? []),
        ]);

        // Ensure is_a_company is set for recipient (required by API v2)
        if (!isset($params['recipient']['is_a_company'])) {
            $params['recipient']['is_a_company'] = false;
        }

        // API v2 expects data wrapped in "quote" key
        return $this->request('POST', '/quotes', ['quote' => $params]);
    }

    /**
     * Get quote details by UUID
     */
    public function getQuote(string $uuid): array
    {
        return $this->request('GET', '/quotes/' . urlencode($uuid));
    }

    /**
     * Place a shipping order (book shipment)
     *
     * @param array $params Order parameters:
     *   - offer_uuid: UUID of the selected offer
     *   - shipper: full shipper address and contact
     *   - recipient: full recipient address and contact
     *   - parcels: array with parcel details
     *   - collection_date: (optional) pickup date
     * @return array Order response with tracking info
     */
    public function placeOrder(array $params): array
    {
        $this->logger->info('Placing LCE order', [
            'offer_id' => $params['offer_id'] ?? 'unknown',
        ]);

        // API v2 expects data wrapped in "order" key
        return $this->request('POST', '/orders', ['order' => $params]);
    }

    /**
     * Get order details (for tracking)
     */
    public function getOrder(string $uuid): array
    {
        return $this->request('GET', '/orders/' . urlencode($uuid));
    }

    /**
     * Get tracking information for an order
     */
    public function getOrderTracking(string $uuid): array
    {
        return $this->request('GET', '/orders/' . urlencode($uuid) . '/tracking');
    }

    /**
     * Get delivery locations (relay points) for an offer
     *
     * @param string $offerUuid UUID of the offer
     * @param array $params Location search parameters:
     *   - address: street address
     *   - city: city name
     *   - postal_code: postal code
     *   - country: country code (2 letters)
     * @return array List of delivery locations
     */
    public function getDeliveryLocations(string $offerUuid, array $params): array
    {
        // Use available_delivery_locations endpoint (API v2)
        // See: https://dashboard.myflyingbox.com/en/docs/api/v2
        // Format: location[postal_code]=...&location[city]=...&location[street]=...&location[country]=...
        $locationParams = ['location' => $params];
        $queryString = http_build_query($locationParams);
        $endpoint = '/offers/' . urlencode($offerUuid) . '/available_delivery_locations';
        if (!empty($queryString)) {
            $endpoint .= '?' . $queryString;
        }
        return $this->request('GET', $endpoint);
    }

    /**
     * Get shipping label PDF URL
     * Note: The API returns a raw PDF file, not JSON
     * This method returns the direct URL to download the labels
     */
    public function getLabelUrl(string $orderUuid, string $format = 'pdf'): string
    {
        return $this->getApiUrl() . '/orders/' . urlencode($orderUuid) . '/labels?format=' . $format;
    }

    /**
     * Download the shipping label PDF content
     * Returns the raw PDF binary content
     *
     * @throws \RuntimeException If download fails
     */
    public function downloadLabel(string $orderUuid, string $format = 'pdf'): string
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('LCE API credentials not configured');
        }

        $credentials = $this->getCredentials();
        $url = $this->getLabelUrl($orderUuid, $format);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $credentials['login'] . ':' . $credentials['password'],
            CURLOPT_HTTPHEADER => [
                'Accept: application/pdf',
                'User-Agent: Thelia-MyFlyingBox/1.0',
            ],
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno) {
            $this->logger->error('Failed to download label', [
                'errno' => $errno,
                'error' => $error,
            ]);
            throw new \RuntimeException('Failed to download label: ' . $error);
        }

        if ($httpCode >= 400) {
            // Try to parse error response as JSON
            $decoded = json_decode($response, true);
            $errorMessage = 'HTTP ' . $httpCode;
            if (isset($decoded['error'])) {
                if (is_array($decoded['error'])) {
                    $errorMessage = $decoded['error']['message'] ?? $decoded['error']['type'] ?? $errorMessage;
                } else {
                    $errorMessage = $decoded['error'];
                }
            }
            $this->logger->error('Label download HTTP error', [
                'http_code' => $httpCode,
                'error' => $errorMessage,
            ]);
            throw new \RuntimeException('Label download failed: ' . $errorMessage);
        }

        // Verify we got a PDF
        if (strpos($contentType, 'application/pdf') === false && strpos($response, '%PDF') !== 0) {
            $this->logger->error('Label response is not a PDF', [
                'content_type' => $contentType,
                'response_start' => substr($response, 0, 100),
            ]);
            throw new \RuntimeException('Label response is not a valid PDF');
        }

        return $response;
    }

    /**
     * Get shipping label PDF URL (legacy method for compatibility)
     * @deprecated Use getLabelUrl() instead
     */
    public function getLabel(string $orderUuid, string $format = 'pdf'): array
    {
        // Return the URL in a structured format for backward compatibility
        return [
            'url' => $this->getLabelUrl($orderUuid, $format),
            'format' => $format,
        ];
    }

    /**
     * Cancel an order
     */
    public function cancelOrder(string $uuid): array
    {
        return $this->request('DELETE', '/orders/' . urlencode($uuid));
    }
}
