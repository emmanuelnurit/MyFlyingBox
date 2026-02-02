<?php

declare(strict_types=1);

namespace MyFlyingBox\Service;

use Thelia\Core\Translation\Translator;

/**
 * Service for translating API error messages into user-friendly messages
 *
 * This service maps raw error messages from the LCE API to translation keys,
 * allowing for localized, user-friendly error messages while preserving
 * the original error for logging purposes.
 */
final class ApiErrorTranslator
{
    /**
     * Error patterns mapped to translation keys
     * Patterns are matched in order, so more specific patterns should come first
     */
    private const ERROR_PATTERNS = [
        // Order state errors (most specific first)
        'order not booked' => 'api_error.order_not_booked',
        'not booked' => 'api_error.order_not_booked',
        'already cancelled' => 'api_error.order_already_cancelled',
        'cancelled' => 'api_error.order_already_cancelled',
        'already shipped' => 'api_error.order_already_shipped',
        'already delivered' => 'api_error.order_already_delivered',

        // Address validation errors
        'invalid address' => 'api_error.invalid_address',
        'postal code' => 'api_error.invalid_address',
        'city required' => 'api_error.invalid_address',

        // Authentication errors
        'unauthorized' => 'api_error.authentication_failed',
        '(401)' => 'api_error.authentication_failed',
        'invalid credentials' => 'api_error.authentication_failed',
        'authentication' => 'api_error.authentication_failed',

        // Offer/service availability errors
        'no offer' => 'api_error.no_offers_available',
        'no service' => 'api_error.no_offers_available',
        'not available' => 'api_error.no_offers_available',

        // Parcel validation errors
        'invalid parcel' => 'api_error.invalid_parcel',
        'weight' => 'api_error.invalid_parcel',
        'dimension' => 'api_error.invalid_parcel',

        // Service availability errors
        'timeout' => 'api_error.service_unavailable',
        'connection' => 'api_error.service_unavailable',
        '(503)' => 'api_error.service_unavailable',
        '(500)' => 'api_error.service_unavailable',
        '(502)' => 'api_error.service_unavailable',
    ];

    /**
     * Translate a raw API error message into a user-friendly message
     *
     * @param string $rawError The raw error message from the API
     * @return array{message: string, original: string, key: string}
     */
    public function translate(string $rawError): array
    {
        $translationKey = $this->mapToTranslationKey($rawError);
        $translatedMessage = Translator::getInstance()->trans($translationKey, [], 'myflyingbox');

        // If no translation found (key returned as-is), fall back to unknown error
        if ($translatedMessage === $translationKey) {
            $translatedMessage = Translator::getInstance()->trans('api_error.unknown', [], 'myflyingbox');
        }

        return [
            'message' => $translatedMessage,
            'original' => $rawError,
            'key' => $translationKey,
        ];
    }

    /**
     * Map a raw error message to a translation key
     */
    private function mapToTranslationKey(string $error): string
    {
        $errorLower = strtolower($error);

        foreach (self::ERROR_PATTERNS as $pattern => $key) {
            if (str_contains($errorLower, $pattern)) {
                return $key;
            }
        }

        return 'api_error.unknown';
    }
}
