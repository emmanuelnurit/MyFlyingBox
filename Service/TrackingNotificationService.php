<?php

declare(strict_types=1);

namespace MyFlyingBox\Service;

use MyFlyingBox\Model\MyFlyingBoxParcelQuery;
use MyFlyingBox\Model\MyFlyingBoxServiceQuery;
use MyFlyingBox\Model\MyFlyingBoxShipment;
use MyFlyingBox\MyFlyingBox;
use Psr\Log\LoggerInterface;
use Thelia\Mailer\MailerFactory;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderQuery;

/**
 * Service for sending tracking notification emails to customers
 */
class TrackingNotificationService
{
    public const STATUS_SHIPPED = 'shipped';
    public const STATUS_DELIVERED = 'delivered';

    private MailerFactory $mailer;
    private LoggerInterface $logger;
    private ?EmailTemplateService $emailTemplateService = null;
    private ?EmailRenderingService $emailRenderingService = null;

    public function __construct(MailerFactory $mailer, LoggerInterface $logger)
    {
        $this->mailer = $mailer;
        $this->logger = $logger;
    }

    /**
     * Set the email template service (optional, for template-based emails)
     */
    public function setEmailTemplateService(EmailTemplateService $emailTemplateService): void
    {
        $this->emailTemplateService = $emailTemplateService;
    }

    /**
     * Set the email rendering service (optional, for template-based emails)
     */
    public function setEmailRenderingService(EmailRenderingService $emailRenderingService): void
    {
        $this->emailRenderingService = $emailRenderingService;
    }

    /**
     * Check if email notifications are enabled
     */
    public function isEnabled(): bool
    {
        return (bool) MyFlyingBox::getConfigValue('myflyingbox_email_notifications_enabled', true);
    }

    /**
     * Send notification email when shipment status changes
     *
     * @param MyFlyingBoxShipment $shipment The shipment
     * @param string $previousStatus The previous status
     * @param string $newStatus The new status
     */
    public function sendStatusChangeNotification(
        MyFlyingBoxShipment $shipment,
        string $previousStatus,
        string $newStatus
    ): bool {
        if (!$this->isEnabled()) {
            $this->logger->debug('Email notifications disabled');
            return false;
        }

        // Only send for shipped and delivered statuses
        if (!in_array($newStatus, [self::STATUS_SHIPPED, self::STATUS_DELIVERED])) {
            return false;
        }

        // Don't send if status hasn't actually changed
        if ($previousStatus === $newStatus) {
            return false;
        }

        // Don't send notifications for return shipments (optional behavior)
        if ($shipment->getIsReturn()) {
            $this->logger->debug('Skipping notification for return shipment');
            return false;
        }

        try {
            $order = OrderQuery::create()->findPk($shipment->getOrderId());
            if (!$order) {
                $this->logger->warning('Order not found for shipment notification', [
                    'shipment_id' => $shipment->getId(),
                    'order_id' => $shipment->getOrderId(),
                ]);
                return false;
            }

            $customer = $order->getCustomer();
            if (!$customer) {
                $this->logger->warning('Customer not found for order', [
                    'order_id' => $order->getId(),
                ]);
                return false;
            }

            // Get customer locale
            $locale = $customer->getCustomerLang()?->getLocale() ?? 'fr_FR';

            // Try to use database template first
            $rendered = $this->renderFromTemplate($shipment, $order, $newStatus, $locale);

            if ($rendered !== null) {
                // Use template-based content
                $subject = $rendered['subject'];
                $htmlBody = $rendered['html'];
                $textBody = $rendered['text'];

                $this->logger->debug('Using database email template', [
                    'status' => $newStatus,
                    'locale' => $locale,
                ]);
            } else {
                // Fallback to hardcoded content
                $emailData = $this->buildEmailData($shipment, $order, $newStatus);
                $subject = $this->getEmailSubject($newStatus, $locale, $order->getRef());
                $htmlBody = $this->buildHtmlBody($emailData, $newStatus, $locale);
                $textBody = $this->buildTextBody($emailData, $newStatus, $locale);

                $this->logger->debug('Using fallback hardcoded email content', [
                    'status' => $newStatus,
                    'locale' => $locale,
                ]);
            }

            // Send email
            $storeName = ConfigQuery::getStoreName();
            $storeEmail = ConfigQuery::getStoreEmail();

            $this->mailer->sendSimpleEmailMessage(
                [$storeEmail => $storeName],
                [$customer->getEmail() => $customer->getFirstname() . ' ' . $customer->getLastname()],
                $subject,
                $htmlBody,
                $textBody
            );

            $this->logger->info('Tracking notification email sent', [
                'shipment_id' => $shipment->getId(),
                'order_id' => $order->getId(),
                'customer_email' => $customer->getEmail(),
                'status' => $newStatus,
            ]);

            return true;

        } catch (\Exception $e) {
            $this->logger->error('Failed to send tracking notification email: ' . $e->getMessage(), [
                'shipment_id' => $shipment->getId(),
                'exception' => $e,
            ]);
            return false;
        }
    }

    /**
     * Try to render email from database template
     *
     * @return array{subject: string, html: string, text: string}|null
     */
    private function renderFromTemplate(
        MyFlyingBoxShipment $shipment,
        Order $order,
        string $status,
        string $locale
    ): ?array {
        // Check if template services are available
        if ($this->emailTemplateService === null || $this->emailRenderingService === null) {
            return null;
        }

        try {
            // Try to load template from database
            $template = $this->emailTemplateService->getTemplate($status, $locale);

            if ($template === null) {
                return null;
            }

            // Build variables and render
            $variables = $this->emailRenderingService->buildVariablesFromShipment($shipment, $order, $status);

            return $this->emailRenderingService->render($template, $variables);

        } catch (\Exception $e) {
            $this->logger->warning('Failed to render email from template, using fallback', [
                'status' => $status,
                'locale' => $locale,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Build email data array
     */
    private function buildEmailData(MyFlyingBoxShipment $shipment, Order $order, string $status): array
    {
        $data = [
            'order_ref' => $order->getRef(),
            'order_id' => $order->getId(),
            'customer_name' => '',
            'carrier_name' => '',
            'carrier_code' => '',
            'tracking_numbers' => [],
            'tracking_urls' => [],
            'delivery_address' => '',
            'is_relay' => false,
            'relay_name' => '',
            'store_name' => ConfigQuery::getStoreName(),
            'store_url' => ConfigQuery::getStoreUrl(),
        ];

        // Customer name
        $customer = $order->getCustomer();
        if ($customer) {
            $data['customer_name'] = $customer->getFirstname() . ' ' . $customer->getLastname();
        }

        // Carrier info
        if ($shipment->getServiceId()) {
            $service = MyFlyingBoxServiceQuery::create()->findPk($shipment->getServiceId());
            if ($service) {
                $data['carrier_name'] = $service->getName();
                $data['carrier_code'] = $service->getCarrierCode();
            }
        }

        // Tracking numbers and URLs
        $parcels = MyFlyingBoxParcelQuery::create()
            ->filterByShipmentId($shipment->getId())
            ->find();

        foreach ($parcels as $parcel) {
            if ($parcel->getTrackingNumber()) {
                $data['tracking_numbers'][] = $parcel->getTrackingNumber();

                // Build tracking URL
                $service = $shipment->getServiceId()
                    ? MyFlyingBoxServiceQuery::create()->findPk($shipment->getServiceId())
                    : null;

                if ($service && $service->getTrackingUrl()) {
                    $trackingUrl = str_replace(
                        '{tracking_number}',
                        $parcel->getTrackingNumber(),
                        $service->getTrackingUrl()
                    );
                    $data['tracking_urls'][] = [
                        'number' => $parcel->getTrackingNumber(),
                        'url' => $trackingUrl,
                    ];
                }
            }
        }

        // Delivery address
        $addressParts = [];
        if ($shipment->getRecipientStreet()) {
            $addressParts[] = $shipment->getRecipientStreet();
        }
        $addressParts[] = $shipment->getRecipientPostalCode() . ' ' . $shipment->getRecipientCity();
        $addressParts[] = $shipment->getRecipientCountry();
        $data['delivery_address'] = implode(', ', array_filter($addressParts));

        // Relay point
        if ($shipment->getRelayDeliveryCode()) {
            $data['is_relay'] = true;
            $data['relay_name'] = $shipment->getRelayDeliveryCode();
        }

        return $data;
    }

    /**
     * Get email subject based on status and locale
     */
    private function getEmailSubject(string $status, string $locale, string $orderRef): string
    {
        $storeName = ConfigQuery::getStoreName();

        $subjects = [
            'fr_FR' => [
                'shipped' => "Votre commande {$orderRef} a été expédiée - {$storeName}",
                'delivered' => "Votre commande {$orderRef} a été livrée - {$storeName}",
            ],
            'en_US' => [
                'shipped' => "Your order {$orderRef} has been shipped - {$storeName}",
                'delivered' => "Your order {$orderRef} has been delivered - {$storeName}",
            ],
        ];

        $lang = $subjects[$locale] ?? $subjects['fr_FR'];
        return $lang[$status] ?? $lang['shipped'];
    }

    /**
     * Build HTML email body
     */
    private function buildHtmlBody(array $data, string $status, string $locale): string
    {
        $isFrench = str_starts_with($locale, 'fr');

        $storeName = htmlspecialchars($data['store_name']);
        $customerName = htmlspecialchars($data['customer_name']);
        $orderRef = htmlspecialchars($data['order_ref']);
        $carrierName = htmlspecialchars($data['carrier_name']);
        $deliveryAddress = htmlspecialchars($data['delivery_address']);

        // Translations
        $texts = $this->getEmailTexts($status, $isFrench);

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f5f5f5; }
        .container { max-width: 600px; margin: 0 auto; background-color: #ffffff; }
        .header { background-color: #2c3e50; color: white; padding: 30px; text-align: center; }
        .header h1 { margin: 0; font-size: 24px; }
        .content { padding: 30px; }
        .status-badge { display: inline-block; padding: 8px 16px; border-radius: 20px; font-weight: bold; margin: 15px 0; }
        .status-shipped { background-color: #3498db; color: white; }
        .status-delivered { background-color: #27ae60; color: white; }
        .tracking-box { background-color: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; padding: 20px; margin: 20px 0; }
        .tracking-number { font-family: monospace; font-size: 18px; font-weight: bold; color: #2c3e50; }
        .btn { display: inline-block; padding: 12px 24px; background-color: #3498db; color: white; text-decoration: none; border-radius: 5px; margin: 10px 5px 10px 0; }
        .btn:hover { background-color: #2980b9; }
        .info-box { background-color: #e8f4fd; border-left: 4px solid #3498db; padding: 15px; margin: 20px 0; }
        .footer { background-color: #f8f9fa; padding: 20px; text-align: center; font-size: 12px; color: #666; }
        .delivery-address { padding: 15px; background-color: #f8f9fa; border-radius: 5px; margin: 15px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>{$storeName}</h1>
        </div>
        <div class="content">
            <p>{$texts['greeting']}, {$customerName},</p>

            <p>{$texts['intro']}</p>

            <div style="text-align: center;">
                <span class="status-badge status-{$status}">{$texts['status_label']}</span>
            </div>

            <div class="tracking-box">
                <p><strong>{$texts['order']}:</strong> {$orderRef}</p>
                <p><strong>{$texts['carrier']}:</strong> {$carrierName}</p>
HTML;

        // Add tracking numbers
        if (!empty($data['tracking_numbers'])) {
            $trackingLabel = $isFrench ? 'Numéro(s) de suivi' : 'Tracking number(s)';
            $html .= "<p><strong>{$trackingLabel}:</strong></p>";

            foreach ($data['tracking_urls'] as $tracking) {
                $number = htmlspecialchars($tracking['number']);
                $url = htmlspecialchars($tracking['url']);
                $html .= "<p class=\"tracking-number\">{$number}</p>";
                $html .= "<a href=\"{$url}\" class=\"btn\">{$texts['track_button']}</a>";
            }
        }

        $html .= <<<HTML
            </div>

            <div class="delivery-address">
                <p><strong>{$texts['delivery_address']}:</strong></p>
                <p>{$deliveryAddress}</p>
HTML;

        if ($data['is_relay']) {
            $relayLabel = $isFrench ? 'Point relais' : 'Pickup point';
            $relayName = htmlspecialchars($data['relay_name']);
            $html .= "<p><strong>{$relayLabel}:</strong> {$relayName}</p>";
        }

        $html .= <<<HTML
            </div>

            <div class="info-box">
                <p>{$texts['info_message']}</p>
            </div>

            <p>{$texts['thanks']}</p>
            <p><strong>{$storeName}</strong></p>
        </div>
        <div class="footer">
            <p>{$texts['footer']}</p>
        </div>
    </div>
</body>
</html>
HTML;

        return $html;
    }

    /**
     * Build plain text email body
     */
    private function buildTextBody(array $data, string $status, string $locale): string
    {
        $isFrench = str_starts_with($locale, 'fr');
        $texts = $this->getEmailTexts($status, $isFrench);

        $text = "{$texts['greeting']}, {$data['customer_name']},\n\n";
        $text .= "{$texts['intro']}\n\n";
        $text .= "---\n";
        $text .= "{$texts['order']}: {$data['order_ref']}\n";
        $text .= "{$texts['carrier']}: {$data['carrier_name']}\n";

        if (!empty($data['tracking_numbers'])) {
            $trackingLabel = $isFrench ? 'Numéro(s) de suivi' : 'Tracking number(s)';
            $text .= "{$trackingLabel}:\n";
            foreach ($data['tracking_urls'] as $tracking) {
                $text .= "  - {$tracking['number']}\n";
                $text .= "    {$tracking['url']}\n";
            }
        }

        $text .= "---\n\n";
        $text .= "{$texts['delivery_address']}:\n{$data['delivery_address']}\n";

        if ($data['is_relay']) {
            $relayLabel = $isFrench ? 'Point relais' : 'Pickup point';
            $text .= "{$relayLabel}: {$data['relay_name']}\n";
        }

        $text .= "\n{$texts['info_message']}\n\n";
        $text .= "{$texts['thanks']}\n";
        $text .= "{$data['store_name']}\n";

        return $text;
    }

    /**
     * Get translated texts for email
     */
    private function getEmailTexts(string $status, bool $isFrench): array
    {
        if ($isFrench) {
            if ($status === self::STATUS_DELIVERED) {
                return [
                    'greeting' => 'Bonjour',
                    'intro' => 'Bonne nouvelle ! Votre commande a été livrée.',
                    'status_label' => '✓ Livré',
                    'order' => 'Commande',
                    'carrier' => 'Transporteur',
                    'delivery_address' => 'Adresse de livraison',
                    'track_button' => 'Voir le suivi',
                    'info_message' => 'Nous espérons que votre commande vous donne entière satisfaction. N\'hésitez pas à nous contacter si vous avez des questions.',
                    'thanks' => 'Merci pour votre confiance,',
                    'footer' => 'Cet email a été envoyé automatiquement, merci de ne pas y répondre.',
                ];
            }

            // shipped
            return [
                'greeting' => 'Bonjour',
                'intro' => 'Bonne nouvelle ! Votre commande a été expédiée et est en route vers vous.',
                'status_label' => '📦 Expédié',
                'order' => 'Commande',
                'carrier' => 'Transporteur',
                'delivery_address' => 'Adresse de livraison',
                'track_button' => 'Suivre mon colis',
                'info_message' => 'Vous pouvez suivre votre colis en temps réel grâce au lien ci-dessus. Le délai de livraison dépend du transporteur et de votre zone géographique.',
                'thanks' => 'Merci pour votre commande,',
                'footer' => 'Cet email a été envoyé automatiquement, merci de ne pas y répondre.',
            ];
        }

        // English
        if ($status === self::STATUS_DELIVERED) {
            return [
                'greeting' => 'Hello',
                'intro' => 'Great news! Your order has been delivered.',
                'status_label' => '✓ Delivered',
                'order' => 'Order',
                'carrier' => 'Carrier',
                'delivery_address' => 'Delivery address',
                'track_button' => 'View tracking',
                'info_message' => 'We hope you are satisfied with your order. Please don\'t hesitate to contact us if you have any questions.',
                'thanks' => 'Thank you for your trust,',
                'footer' => 'This email was sent automatically, please do not reply.',
            ];
        }

        // shipped
        return [
            'greeting' => 'Hello',
            'intro' => 'Great news! Your order has been shipped and is on its way to you.',
            'status_label' => '📦 Shipped',
            'order' => 'Order',
            'carrier' => 'Carrier',
            'delivery_address' => 'Delivery address',
            'track_button' => 'Track my package',
            'info_message' => 'You can track your package in real-time using the link above. Delivery time depends on the carrier and your geographical area.',
            'thanks' => 'Thank you for your order,',
            'footer' => 'This email was sent automatically, please do not reply.',
        ];
    }
}
