<?php

declare(strict_types=1);

namespace MyFlyingBox\Service;

use MyFlyingBox\Model\MyFlyingBoxEmailTemplate;
use MyFlyingBox\Model\MyFlyingBoxParcelQuery;
use MyFlyingBox\Model\MyFlyingBoxServiceQuery;
use MyFlyingBox\Model\MyFlyingBoxShipment;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Order;

/**
 * Service for rendering email templates with variable substitution
 */
final class EmailRenderingService
{
    private EmailTemplateService $templateService;

    public function __construct(EmailTemplateService $templateService)
    {
        $this->templateService = $templateService;
    }

    /**
     * Render a template with variables
     *
     * @param array<string, string> $variables
     * @return array{subject: string, html: string, text: string}
     */
    public function render(MyFlyingBoxEmailTemplate $template, array $variables): array
    {
        $subject = $this->replaceVariables($template->getSubject(), $variables);
        $htmlBody = $this->replaceVariables($template->getHtmlContent(), $variables);

        $textContent = $template->getTextContent();
        $textBody = !empty($textContent)
            ? $this->replaceVariables($textContent, $variables)
            : $this->htmlToText($htmlBody);

        return [
            'subject' => $subject,
            'html' => $htmlBody,
            'text' => $textBody,
        ];
    }

    /**
     * Build variables array from shipment and order data
     *
     * @return array<string, string>
     */
    public function buildVariablesFromShipment(
        MyFlyingBoxShipment $shipment,
        Order $order,
        string $status
    ): array {
        $variables = [
            '{order_ref}' => $order->getRef(),
            '{customer_name}' => '',
            '{carrier_name}' => '',
            '{carrier_code}' => '',
            '{tracking_number}' => '',
            '{tracking_numbers}' => '',
            '{tracking_url}' => '',
            '{tracking_links}' => '',
            '{delivery_address}' => '',
            '{relay_name}' => '',
            '{store_name}' => ConfigQuery::getStoreName() ?? '',
            '{store_url}' => ConfigQuery::getStoreUrl() ?? '',
            '{status_label}' => $this->getStatusLabel($status, $order),
        ];

        // Customer name
        $customer = $order->getCustomer();
        if ($customer !== null) {
            $variables['{customer_name}'] = trim($customer->getFirstname() . ' ' . $customer->getLastname());
        }

        // Carrier info
        if ($shipment->getServiceId() !== null) {
            $service = MyFlyingBoxServiceQuery::create()->findPk($shipment->getServiceId());
            if ($service !== null) {
                $variables['{carrier_name}'] = $service->getName();
                $variables['{carrier_code}'] = $service->getCarrierCode();
            }
        }

        // Tracking numbers and URLs
        $this->buildTrackingVariables($shipment, $variables);

        // Delivery address
        $variables['{delivery_address}'] = $this->buildDeliveryAddress($shipment);

        // Relay point
        $relayCode = $shipment->getRelayDeliveryCode();
        if (!empty($relayCode)) {
            $relayName = $shipment->getRelayName();
            $variables['{relay_name}'] = !empty($relayName) ? $relayName : $relayCode;
        }

        return $variables;
    }

    /**
     * Preview a template with sample data
     *
     * @return array{subject: string, html: string, text: string}
     */
    public function preview(MyFlyingBoxEmailTemplate $template): array
    {
        return $this->render($template, $this->getSampleVariables());
    }

    /**
     * Preview with custom content (for live editing)
     *
     * @return array{subject: string, html: string, text: string}
     */
    public function previewWithCustomContent(
        string $subject,
        string $htmlContent,
        ?string $textContent = null
    ): array {
        $variables = $this->getSampleVariables();

        $renderedSubject = $this->replaceVariables($subject, $variables);
        $renderedHtml = $this->replaceVariables($htmlContent, $variables);
        $renderedText = !empty($textContent)
            ? $this->replaceVariables($textContent, $variables)
            : $this->htmlToText($renderedHtml);

        return [
            'subject' => $renderedSubject,
            'html' => $renderedHtml,
            'text' => $renderedText,
        ];
    }

    /**
     * Get sample variables for preview
     *
     * @return array<string, string>
     */
    public function getSampleVariables(): array
    {
        $storeName = ConfigQuery::getStoreName() ?? 'Ma Boutique';
        $storeUrl = ConfigQuery::getStoreUrl() ?? 'https://www.example.com';

        return [
            '{order_ref}' => 'CMD-2024-00123',
            '{customer_name}' => 'Jean Dupont',
            '{carrier_name}' => 'Colissimo',
            '{carrier_code}' => 'COLISSIMO',
            '{tracking_number}' => '6A12345678901',
            '{tracking_numbers}' => '6A12345678901, 6A12345678902',
            '{tracking_url}' => 'https://www.laposte.fr/outils/suivre-vos-envois?code=6A12345678901',
            '{tracking_links}' => '<a href="https://www.laposte.fr/outils/suivre-vos-envois?code=6A12345678901" class="btn" style="display:inline-block;padding:12px 24px;background-color:#3498db;color:white;text-decoration:none;border-radius:5px;">Suivre mon colis</a>',
            '{delivery_address}' => '123 Rue de la Paix, 75001 Paris, France',
            '{relay_name}' => 'Tabac Presse du Centre',
            '{store_name}' => $storeName,
            '{store_url}' => $storeUrl,
            '{status_label}' => 'Expedie',
        ];
    }

    /**
     * Replace variables in content
     *
     * @param array<string, string> $variables
     */
    private function replaceVariables(string $content, array $variables): string
    {
        return str_replace(
            array_keys($variables),
            array_map('htmlspecialchars', array_values($variables)),
            $content
        );
    }

    /**
     * Convert HTML to plain text
     */
    private function htmlToText(string $html): string
    {
        // Remove style and script tags
        $text = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html);
        $text = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $text);

        // Convert links to text format
        $text = preg_replace('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>([^<]+)<\/a>/i', '$2 ($1)', $text);

        // Convert line breaks
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
        $text = preg_replace('/<\/p>/i', "\n\n", $text);
        $text = preg_replace('/<\/div>/i', "\n", $text);
        $text = preg_replace('/<\/tr>/i', "\n", $text);
        $text = preg_replace('/<\/h[1-6]>/i', "\n\n", $text);

        // Strip remaining HTML tags
        $text = strip_tags($text);

        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Normalize whitespace
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    /**
     * Build tracking variables from shipment parcels
     *
     * @param array<string, string> $variables
     */
    private function buildTrackingVariables(MyFlyingBoxShipment $shipment, array &$variables): void
    {
        $parcels = MyFlyingBoxParcelQuery::create()
            ->filterByShipmentId($shipment->getId())
            ->find();

        $trackingNumbers = [];
        $trackingLinks = [];
        $firstTrackingUrl = '';

        $service = $shipment->getServiceId() !== null
            ? MyFlyingBoxServiceQuery::create()->findPk($shipment->getServiceId())
            : null;

        foreach ($parcels as $parcel) {
            $trackingNumber = $parcel->getTrackingNumber();
            if (empty($trackingNumber)) {
                continue;
            }

            $trackingNumbers[] = $trackingNumber;

            if ($service !== null && !empty($service->getTrackingUrl())) {
                $trackingUrl = str_replace(
                    '{tracking_number}',
                    $trackingNumber,
                    $service->getTrackingUrl()
                );

                if (empty($firstTrackingUrl)) {
                    $firstTrackingUrl = $trackingUrl;
                }

                $trackingLinks[] = sprintf(
                    '<a href="%s" class="btn" style="display:inline-block;padding:12px 24px;background-color:#3498db;color:white;text-decoration:none;border-radius:5px;margin:5px 0;">Suivre %s</a>',
                    htmlspecialchars($trackingUrl),
                    htmlspecialchars($trackingNumber)
                );
            }
        }

        $variables['{tracking_number}'] = $trackingNumbers[0] ?? '';
        $variables['{tracking_numbers}'] = implode(', ', $trackingNumbers);
        $variables['{tracking_url}'] = $firstTrackingUrl;
        $variables['{tracking_links}'] = implode('<br>', $trackingLinks);
    }

    /**
     * Build delivery address string from shipment
     */
    private function buildDeliveryAddress(MyFlyingBoxShipment $shipment): string
    {
        $parts = [];

        $street = $shipment->getRecipientStreet();
        if (!empty($street)) {
            $parts[] = $street;
        }

        $postalCode = $shipment->getRecipientPostalCode();
        $city = $shipment->getRecipientCity();
        if (!empty($postalCode) || !empty($city)) {
            $parts[] = trim($postalCode . ' ' . $city);
        }

        $country = $shipment->getRecipientCountry();
        if (!empty($country)) {
            $parts[] = $country;
        }

        return implode(', ', array_filter($parts));
    }

    /**
     * Get status label based on status and order locale
     */
    private function getStatusLabel(string $status, Order $order): string
    {
        $customer = $order->getCustomer();
        $locale = $customer?->getCustomerLang()?->getLocale() ?? 'fr_FR';
        $isFrench = str_starts_with($locale, 'fr');

        $labels = [
            'shipped' => $isFrench ? 'Expedie' : 'Shipped',
            'delivered' => $isFrench ? 'Livre' : 'Delivered',
        ];

        return $labels[$status] ?? $status;
    }
}
