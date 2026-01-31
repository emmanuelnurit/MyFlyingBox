<?php

declare(strict_types=1);

namespace MyFlyingBox\Service;

use MyFlyingBox\Model\MyFlyingBoxEmailTemplate;
use MyFlyingBox\Model\MyFlyingBoxEmailTemplateQuery;
use Propel\Runtime\ActiveQuery\Criteria;
use Psr\Log\LoggerInterface;

/**
 * Service for managing email templates
 */
final class EmailTemplateService
{
    public const TEMPLATE_SHIPPED = 'shipped';
    public const TEMPLATE_DELIVERED = 'delivered';

    public const SUPPORTED_LOCALES = ['fr_FR', 'en_US'];

    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Get template by code and locale with fallback chain
     */
    public function getTemplate(string $code, string $locale): ?MyFlyingBoxEmailTemplate
    {
        // Try exact locale first
        $template = MyFlyingBoxEmailTemplateQuery::create()
            ->filterByCode($code)
            ->filterByLocale($locale)
            ->filterByIsActive(true)
            ->findOne();

        if ($template !== null) {
            return $template;
        }

        // Fallback to base language (fr_FR -> fr_%)
        $baseLang = substr($locale, 0, 2);
        $template = MyFlyingBoxEmailTemplateQuery::create()
            ->filterByCode($code)
            ->filterByLocale($baseLang . '_%', Criteria::LIKE)
            ->filterByIsActive(true)
            ->findOne();

        if ($template !== null) {
            return $template;
        }

        // Final fallback to fr_FR (default)
        return MyFlyingBoxEmailTemplateQuery::create()
            ->filterByCode($code)
            ->filterByLocale('fr_FR')
            ->filterByIsActive(true)
            ->findOne();
    }

    /**
     * Get template by ID
     */
    public function getTemplateById(int $id): ?MyFlyingBoxEmailTemplate
    {
        return MyFlyingBoxEmailTemplateQuery::create()->findPk($id);
    }

    /**
     * Get all templates
     *
     * @return MyFlyingBoxEmailTemplate[]
     */
    public function getAllTemplates(): array
    {
        return MyFlyingBoxEmailTemplateQuery::create()
            ->orderByCode()
            ->orderByLocale()
            ->find()
            ->getData();
    }

    /**
     * Save a template (create or update)
     */
    public function saveTemplate(
        ?int $id,
        string $code,
        string $locale,
        string $name,
        string $subject,
        string $htmlContent,
        ?string $textContent = null,
        bool $isActive = true
    ): MyFlyingBoxEmailTemplate {
        if ($id !== null) {
            $template = $this->getTemplateById($id);
            if ($template === null) {
                throw new \InvalidArgumentException("Template with ID {$id} not found");
            }
        } else {
            // Check for existing template with same code/locale
            $existing = MyFlyingBoxEmailTemplateQuery::create()
                ->filterByCode($code)
                ->filterByLocale($locale)
                ->findOne();

            if ($existing !== null) {
                throw new \InvalidArgumentException(
                    "A template already exists for code '{$code}' and locale '{$locale}'"
                );
            }

            $template = new MyFlyingBoxEmailTemplate();
            $template->setCode($code);
            $template->setLocale($locale);
        }

        $template->setName($name);
        $template->setSubject($subject);
        $template->setHtmlContent($htmlContent);
        $template->setTextContent($textContent);
        $template->setIsActive($isActive);
        $template->save();

        $this->logger->info('Email template saved', [
            'id' => $template->getId(),
            'code' => $code,
            'locale' => $locale,
        ]);

        return $template;
    }

    /**
     * Delete a template (only non-default templates can be deleted)
     */
    public function deleteTemplate(int $id): bool
    {
        $template = $this->getTemplateById($id);

        if ($template === null) {
            return false;
        }

        if ($template->getIsDefault()) {
            throw new \InvalidArgumentException(
                'Default templates cannot be deleted. Use reset instead.'
            );
        }

        $template->delete();

        $this->logger->info('Email template deleted', [
            'id' => $id,
            'code' => $template->getCode(),
            'locale' => $template->getLocale(),
        ]);

        return true;
    }

    /**
     * Reset a template to its default content
     */
    public function resetToDefault(int $id): MyFlyingBoxEmailTemplate
    {
        $template = $this->getTemplateById($id);

        if ($template === null) {
            throw new \InvalidArgumentException("Template with ID {$id} not found");
        }

        $defaults = $this->getDefaultTemplates();
        $key = $template->getCode() . '_' . $template->getLocale();

        if (!isset($defaults[$key])) {
            throw new \InvalidArgumentException(
                "No default template found for code '{$template->getCode()}' and locale '{$template->getLocale()}'"
            );
        }

        $default = $defaults[$key];
        $template->setName($default['name']);
        $template->setSubject($default['subject']);
        $template->setHtmlContent($default['html_content']);
        $template->setTextContent($default['text_content']);
        $template->setIsActive(true);
        $template->save();

        $this->logger->info('Email template reset to default', [
            'id' => $id,
            'code' => $template->getCode(),
            'locale' => $template->getLocale(),
        ]);

        return $template;
    }

    /**
     * Seed default templates (called on module activation/update)
     */
    public function seedDefaultTemplates(): void
    {
        $defaults = $this->getDefaultTemplates();

        foreach ($defaults as $key => $data) {
            [$code, $locale] = explode('_', $key, 2);

            $existing = MyFlyingBoxEmailTemplateQuery::create()
                ->filterByCode($code)
                ->filterByLocale($locale)
                ->findOne();

            if ($existing !== null) {
                continue;
            }

            $template = new MyFlyingBoxEmailTemplate();
            $template->setCode($code);
            $template->setLocale($locale);
            $template->setName($data['name']);
            $template->setSubject($data['subject']);
            $template->setHtmlContent($data['html_content']);
            $template->setTextContent($data['text_content']);
            $template->setIsActive(true);
            $template->setIsDefault(true);
            $template->save();

            $this->logger->info('Default email template seeded', [
                'code' => $code,
                'locale' => $locale,
            ]);
        }
    }

    /**
     * Get available template codes
     *
     * @return array<string, string>
     */
    public function getAvailableCodes(): array
    {
        return [
            self::TEMPLATE_SHIPPED => 'Shipment Sent',
            self::TEMPLATE_DELIVERED => 'Shipment Delivered',
        ];
    }

    /**
     * Get available variables for templates
     *
     * @return array<string, string>
     */
    public function getAvailableVariables(): array
    {
        return [
            '{order_ref}' => 'Order reference number',
            '{customer_name}' => 'Customer full name',
            '{carrier_name}' => 'Carrier/transporter name',
            '{carrier_code}' => 'Carrier code',
            '{tracking_number}' => 'First tracking number',
            '{tracking_numbers}' => 'All tracking numbers (comma-separated)',
            '{tracking_url}' => 'First tracking URL',
            '{tracking_links}' => 'All tracking links (HTML)',
            '{delivery_address}' => 'Full delivery address',
            '{relay_name}' => 'Relay point name (if applicable)',
            '{store_name}' => 'Store name',
            '{store_url}' => 'Store URL',
            '{status_label}' => 'Status label (Shipped/Delivered)',
        ];
    }

    /**
     * Get default template content for all codes and locales
     *
     * @return array<string, array<string, string>>
     */
    private function getDefaultTemplates(): array
    {
        return [
            'shipped_fr_FR' => [
                'name' => 'Expedition envoyee (FR)',
                'subject' => 'Votre commande {order_ref} a ete expediee - {store_name}',
                'html_content' => $this->getDefaultHtmlTemplate('shipped', 'fr_FR'),
                'text_content' => $this->getDefaultTextTemplate('shipped', 'fr_FR'),
            ],
            'shipped_en_US' => [
                'name' => 'Shipment Sent (EN)',
                'subject' => 'Your order {order_ref} has been shipped - {store_name}',
                'html_content' => $this->getDefaultHtmlTemplate('shipped', 'en_US'),
                'text_content' => $this->getDefaultTextTemplate('shipped', 'en_US'),
            ],
            'delivered_fr_FR' => [
                'name' => 'Expedition livree (FR)',
                'subject' => 'Votre commande {order_ref} a ete livree - {store_name}',
                'html_content' => $this->getDefaultHtmlTemplate('delivered', 'fr_FR'),
                'text_content' => $this->getDefaultTextTemplate('delivered', 'fr_FR'),
            ],
            'delivered_en_US' => [
                'name' => 'Shipment Delivered (EN)',
                'subject' => 'Your order {order_ref} has been delivered - {store_name}',
                'html_content' => $this->getDefaultHtmlTemplate('delivered', 'en_US'),
                'text_content' => $this->getDefaultTextTemplate('delivered', 'en_US'),
            ],
        ];
    }

    private function getDefaultHtmlTemplate(string $code, string $locale): string
    {
        $isFrench = str_starts_with($locale, 'fr');

        if ($code === 'delivered') {
            $texts = $isFrench ? [
                'greeting' => 'Bonjour',
                'intro' => 'Bonne nouvelle ! Votre commande a ete livree.',
                'status_badge' => 'Livre',
                'order_label' => 'Commande',
                'carrier_label' => 'Transporteur',
                'tracking_label' => 'Numero(s) de suivi',
                'track_button' => 'Voir le suivi',
                'address_label' => 'Adresse de livraison',
                'relay_label' => 'Point relais',
                'info_message' => 'Nous esperons que votre commande vous donne entiere satisfaction.',
                'thanks' => 'Merci pour votre confiance,',
                'footer' => 'Cet email a ete envoye automatiquement.',
            ] : [
                'greeting' => 'Hello',
                'intro' => 'Great news! Your order has been delivered.',
                'status_badge' => 'Delivered',
                'order_label' => 'Order',
                'carrier_label' => 'Carrier',
                'tracking_label' => 'Tracking number(s)',
                'track_button' => 'View tracking',
                'address_label' => 'Delivery address',
                'relay_label' => 'Pickup point',
                'info_message' => 'We hope you are satisfied with your order.',
                'thanks' => 'Thank you for your trust,',
                'footer' => 'This email was sent automatically.',
            ];
            $statusColor = '#27ae60';
        } else {
            $texts = $isFrench ? [
                'greeting' => 'Bonjour',
                'intro' => 'Bonne nouvelle ! Votre commande a ete expediee et est en route.',
                'status_badge' => 'Expedie',
                'order_label' => 'Commande',
                'carrier_label' => 'Transporteur',
                'tracking_label' => 'Numero(s) de suivi',
                'track_button' => 'Suivre mon colis',
                'address_label' => 'Adresse de livraison',
                'relay_label' => 'Point relais',
                'info_message' => 'Vous pouvez suivre votre colis en temps reel.',
                'thanks' => 'Merci pour votre commande,',
                'footer' => 'Cet email a ete envoye automatiquement.',
            ] : [
                'greeting' => 'Hello',
                'intro' => 'Great news! Your order has been shipped and is on its way.',
                'status_badge' => 'Shipped',
                'order_label' => 'Order',
                'carrier_label' => 'Carrier',
                'tracking_label' => 'Tracking number(s)',
                'track_button' => 'Track my package',
                'address_label' => 'Delivery address',
                'relay_label' => 'Pickup point',
                'info_message' => 'You can track your package in real-time.',
                'thanks' => 'Thank you for your order,',
                'footer' => 'This email was sent automatically.',
            ];
            $statusColor = '#3498db';
        }

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f5f5f5; }
        .container { max-width: 600px; margin: 0 auto; background-color: #ffffff; }
        .header { background-color: #2c3e50; color: white; padding: 30px; text-align: center; }
        .header h1 { margin: 0; font-size: 24px; }
        .content { padding: 30px; }
        .status-badge { display: inline-block; padding: 8px 16px; border-radius: 20px; font-weight: bold; margin: 15px 0; background-color: {$statusColor}; color: white; }
        .tracking-box { background-color: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; padding: 20px; margin: 20px 0; }
        .tracking-number { font-family: monospace; font-size: 16px; font-weight: bold; color: #2c3e50; }
        .btn { display: inline-block; padding: 12px 24px; background-color: #3498db; color: white; text-decoration: none; border-radius: 5px; margin: 10px 5px 10px 0; }
        .info-box { background-color: #e8f4fd; border-left: 4px solid #3498db; padding: 15px; margin: 20px 0; }
        .footer { background-color: #f8f9fa; padding: 20px; text-align: center; font-size: 12px; color: #666; }
        .delivery-address { padding: 15px; background-color: #f8f9fa; border-radius: 5px; margin: 15px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>{store_name}</h1>
        </div>
        <div class="content">
            <p>{$texts['greeting']}, {customer_name},</p>
            <p>{$texts['intro']}</p>

            <div style="text-align: center;">
                <span class="status-badge">{$texts['status_badge']}</span>
            </div>

            <div class="tracking-box">
                <p><strong>{$texts['order_label']}:</strong> {order_ref}</p>
                <p><strong>{$texts['carrier_label']}:</strong> {carrier_name}</p>
                <p><strong>{$texts['tracking_label']}:</strong></p>
                <p class="tracking-number">{tracking_number}</p>
                {tracking_links}
            </div>

            <div class="delivery-address">
                <p><strong>{$texts['address_label']}:</strong></p>
                <p>{delivery_address}</p>
            </div>

            <div class="info-box">
                <p>{$texts['info_message']}</p>
            </div>

            <p>{$texts['thanks']}</p>
            <p><strong>{store_name}</strong></p>
        </div>
        <div class="footer">
            <p>{$texts['footer']}</p>
        </div>
    </div>
</body>
</html>
HTML;
    }

    private function getDefaultTextTemplate(string $code, string $locale): string
    {
        $isFrench = str_starts_with($locale, 'fr');

        if ($code === 'delivered') {
            $texts = $isFrench ? [
                'greeting' => 'Bonjour',
                'intro' => 'Bonne nouvelle ! Votre commande a ete livree.',
                'order_label' => 'Commande',
                'carrier_label' => 'Transporteur',
                'tracking_label' => 'Numero(s) de suivi',
                'address_label' => 'Adresse de livraison',
                'info_message' => 'Nous esperons que votre commande vous donne entiere satisfaction.',
                'thanks' => 'Merci pour votre confiance,',
            ] : [
                'greeting' => 'Hello',
                'intro' => 'Great news! Your order has been delivered.',
                'order_label' => 'Order',
                'carrier_label' => 'Carrier',
                'tracking_label' => 'Tracking number(s)',
                'address_label' => 'Delivery address',
                'info_message' => 'We hope you are satisfied with your order.',
                'thanks' => 'Thank you for your trust,',
            ];
        } else {
            $texts = $isFrench ? [
                'greeting' => 'Bonjour',
                'intro' => 'Bonne nouvelle ! Votre commande a ete expediee et est en route.',
                'order_label' => 'Commande',
                'carrier_label' => 'Transporteur',
                'tracking_label' => 'Numero(s) de suivi',
                'address_label' => 'Adresse de livraison',
                'info_message' => 'Vous pouvez suivre votre colis en temps reel.',
                'thanks' => 'Merci pour votre commande,',
            ] : [
                'greeting' => 'Hello',
                'intro' => 'Great news! Your order has been shipped and is on its way.',
                'order_label' => 'Order',
                'carrier_label' => 'Carrier',
                'tracking_label' => 'Tracking number(s)',
                'address_label' => 'Delivery address',
                'info_message' => 'You can track your package in real-time.',
                'thanks' => 'Thank you for your order,',
            ];
        }

        return <<<TEXT
{$texts['greeting']}, {customer_name},

{$texts['intro']}

---
{$texts['order_label']}: {order_ref}
{$texts['carrier_label']}: {carrier_name}
{$texts['tracking_label']}: {tracking_numbers}
{tracking_url}
---

{$texts['address_label']}:
{delivery_address}

{$texts['info_message']}

{$texts['thanks']}
{store_name}
TEXT;
    }
}
