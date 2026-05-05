<?php

declare(strict_types=1);

namespace MyFlyingBox;

use MyFlyingBox\Model\MyFlyingBoxServiceQuery;
use Propel\Runtime\Connection\ConnectionInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Symfony\Component\Finder\Finder;
use Thelia\Core\Template\TemplateDefinition;
use Thelia\Core\Translation\Translator;
use Thelia\Install\Database;
use Thelia\Model\Country;
use Thelia\Model\HookQuery;
use Thelia\Model\LangQuery;
use Thelia\Model\Message;
use Thelia\Model\MessageQuery;
use Thelia\Model\ModuleHook;
use Thelia\Model\ModuleHookQuery;
use Thelia\Model\ModuleQuery;
use Thelia\Model\State;
use Thelia\Model\OrderPostage;
use Thelia\Module\AbstractDeliveryModuleWithState;
use Thelia\Module\Exception\DeliveryException;

class MyFlyingBox extends AbstractDeliveryModuleWithState
{
    public const DOMAIN_NAME = 'myflyingbox';

    // Configuration keys
    public const CONFIG_API_LOGIN = 'myflyingbox_api_login';
    public const CONFIG_API_PASSWORD = 'myflyingbox_api_password';
    public const CONFIG_API_ENV = 'myflyingbox_api_env';
    public const CONFIG_DEFAULT_SHIPPER_NAME = 'myflyingbox_shipper_name';
    public const CONFIG_DEFAULT_SHIPPER_COMPANY = 'myflyingbox_shipper_company';
    public const CONFIG_DEFAULT_SHIPPER_STREET = 'myflyingbox_shipper_street';
    public const CONFIG_DEFAULT_SHIPPER_CITY = 'myflyingbox_shipper_city';
    public const CONFIG_DEFAULT_SHIPPER_POSTAL_CODE = 'myflyingbox_shipper_postal_code';
    public const CONFIG_DEFAULT_SHIPPER_COUNTRY = 'myflyingbox_shipper_country';
    public const CONFIG_DEFAULT_SHIPPER_PHONE = 'myflyingbox_shipper_phone';
    public const CONFIG_DEFAULT_SHIPPER_EMAIL = 'myflyingbox_shipper_email';
    public const CONFIG_PRICE_SURCHARGE_PERCENT = 'myflyingbox_surcharge_percent';
    public const CONFIG_PRICE_SURCHARGE_STATIC = 'myflyingbox_surcharge_static';
    public const CONFIG_PRICE_ROUND_INCREMENT = 'myflyingbox_round_increment';
    public const CONFIG_MAX_WEIGHT = 'myflyingbox_max_weight';
    public const CONFIG_TAX_RULE_ID = 'myflyingbox_tax_rule_id';
    public const CONFIG_GOOGLE_MAPS_API_KEY = 'myflyingbox_google_maps_key';

    // Webhook configuration keys
    public const CONFIG_WEBHOOK_SECRET = 'myflyingbox_webhook_secret';
    public const CONFIG_WEBHOOK_ENABLED = 'myflyingbox_webhook_enabled';

    // Email notification configuration keys
    public const CONFIG_EMAIL_NOTIFICATIONS_ENABLED = 'myflyingbox_email_notifications_enabled';

    protected ?Translator $translator = null;

    public function postActivation(ConnectionInterface $con = null): void
    {
        // Create tables
        if (!$this->getConfigValue('is_initialized', false)) {
            $database = new Database($con);
            $database->insertSql(null, [__DIR__ . '/Config/TheliaMain.sql']);
            $this->setConfigValue('is_initialized', true);
        }

        // Set default configuration
        $this->initConfigValue(self::CONFIG_API_ENV, 'staging');
        $this->initConfigValue(self::CONFIG_PRICE_SURCHARGE_PERCENT, '0');
        $this->initConfigValue(self::CONFIG_PRICE_SURCHARGE_STATIC, '0');
        $this->initConfigValue(self::CONFIG_PRICE_ROUND_INCREMENT, '1');
        $this->initConfigValue(self::CONFIG_MAX_WEIGHT, '30');
        $this->initConfigValue(self::CONFIG_DEFAULT_SHIPPER_COUNTRY, 'FR');

        // Webhook configuration (disabled by default for security)
        $this->initConfigValue(self::CONFIG_WEBHOOK_ENABLED, '0');

        // Register email messages for shipping notifications
        $this->registerEmailMessages();

        // Register module.configuration hook to enable "Configure" button
        $this->registerConfigurationHook();
    }

    /**
     * Register email messages for shipping notifications
     * These messages will appear in /admin/configuration/messages
     */
    protected function registerEmailMessages(): void
    {
        $messages = [
            'myflyingbox_shipped' => [
                'html_template' => 'mfb-shipped.html',
                'text_template' => 'mfb-shipped.txt',
                'title_key' => 'MyFlyingBox - Shipment sent notification',
                'subject_key' => 'Your order {$order_ref} has been shipped',
            ],
            'myflyingbox_delivered' => [
                'html_template' => 'mfb-delivered.html',
                'text_template' => 'mfb-delivered.txt',
                'title_key' => 'MyFlyingBox - Delivery confirmation',
                'subject_key' => 'Your order {$order_ref} has been delivered',
            ],
        ];

        $languages = LangQuery::create()->find();

        foreach ($messages as $messageName => $config) {
            if (null !== MessageQuery::create()->findOneByName($messageName)) {
                continue; // Message already exists
            }

            $message = new Message();
            $message->setName($messageName)
                ->setHtmlTemplateFileName($config['html_template'])
                ->setTextTemplateFileName($config['text_template'])
                ->setSecured(0);

            foreach ($languages as $language) {
                $locale = $language->getLocale();
                $message->setLocale($locale);
                $message->setTitle($this->trans($config['title_key'], [], $locale));
                $message->setSubject($this->trans($config['subject_key'], [], $locale));
            }

            $message->save();
        }
    }

    /**
     * Register the module.configuration hook for this module
     */
    protected function registerConfigurationHook(): void
    {
        // Find the module.configuration hook
        $hook = HookQuery::create()
            ->filterByCode('module.configuration')
            ->filterByType(TemplateDefinition::BACK_OFFICE)
            ->findOne();

        if (!$hook) {
            return;
        }

        // Find our module
        $module = ModuleQuery::create()
            ->filterByCode(self::getModuleCode())
            ->findOne();

        if (!$module) {
            return;
        }

        // Check if hook registration already exists
        $moduleHook = ModuleHookQuery::create()
            ->filterByModuleId($module->getId())
            ->filterByHookId($hook->getId())
            ->findOne();

        if (!$moduleHook) {
            // Create the hook registration
            $moduleHook = new ModuleHook();
            $moduleHook
                ->setModuleId($module->getId())
                ->setHookId($hook->getId())
                ->setClassname('MyFlyingBox\\Hook\\BackHook')
                ->setMethod('onModuleConfiguration')
                ->setActive(true)
                ->setHookActive(true)
                ->setModuleActive(true)
                ->setPosition(1)
                ->save();
        } elseif (!$moduleHook->getActive()) {
            // Activate if it exists but is inactive
            $moduleHook->setActive(true)->save();
        }

        // Also register module.config-js hook
        $jsHook = HookQuery::create()
            ->filterByCode('module.config-js')
            ->filterByType(TemplateDefinition::BACK_OFFICE)
            ->findOne();

        if ($jsHook) {
            $moduleJsHook = ModuleHookQuery::create()
                ->filterByModuleId($module->getId())
                ->filterByHookId($jsHook->getId())
                ->findOne();

            if (!$moduleJsHook) {
                $moduleJsHook = new ModuleHook();
                $moduleJsHook
                    ->setModuleId($module->getId())
                    ->setHookId($jsHook->getId())
                    ->setClassname('MyFlyingBox\\Hook\\BackHook')
                    ->setMethod('onModuleConfigJs')
                    ->setActive(true)
                    ->setHookActive(true)
                    ->setModuleActive(true)
                    ->setPosition(1)
                    ->save();
            } elseif (!$moduleJsHook->getActive()) {
                $moduleJsHook->setActive(true)->save();
            }
        }
    }

    protected function initConfigValue(string $key, string $default): void
    {
        if (null === $this->getConfigValue($key)) {
            $this->setConfigValue($key, $default);
        }
    }

    public function update($currentVersion, $newVersion, ConnectionInterface $con = null): void
    {
        $updateDir = __DIR__ . '/Config/Update';

        if (!is_dir($updateDir)) {
            return;
        }

        $finder = new Finder();
        $finder->files()->name('*.sql')->sortByName()->in($updateDir);

        $database = new Database($con);

        foreach ($finder as $file) {
            $fileVersion = str_replace('.sql', '', $file->getFilename());
            if (version_compare($currentVersion, $fileVersion, '<')) {
                $database->insertSql(null, [$file->getPathname()]);
            }
        }
    }

    public function getPostage(Country $country, ?State $state = null): OrderPostage|float
    {
        $request = $this->getRequest();
        $session = $request->getSession();
        $cart = $session->getSessionCart($this->getDispatcher());

        if (!$cart || $cart->countCartItems() === 0) {
            throw new DeliveryException(
                $this->trans('Empty cart')
            );
        }

        // Check if API is configured
        $apiLogin = $this->getConfigValue(self::CONFIG_API_LOGIN);
        $apiPassword = $this->getConfigValue(self::CONFIG_API_PASSWORD);

        if (empty($apiLogin) || empty($apiPassword)) {
            throw new DeliveryException(
                $this->trans('MyFlyingBox API not configured')
            );
        }

        // Get quote service from container
        /** @var \MyFlyingBox\Service\QuoteService $quoteService */
        $quoteService = $this->getContainer()->get('myflyingbox.quote.service');

        // Get delivery address
        $addressId = $session->getOrder()?->getChoosenDeliveryAddress();
        $address = null;
        if ($addressId) {
            $address = \Thelia\Model\AddressQuery::create()->findPk($addressId);
        }

        // Get or create quote (pass country as fallback when no address is selected yet)
        $quote = $quoteService->getQuoteForCart($cart, $address, $country);

        if (!$quote) {
            throw new DeliveryException(
                $this->trans('Unable to get shipping rates for your address')
            );
        }

        $price = null;

        // --- React checkout flow (POST /open_api/checkout) ---
        // When the user selects a virtual MFB module, React POSTs with
        // deliveryModuleId + deliveryModuleOptionCode (e.g. "DPDE").
        // We map the option code to the matching offer, return its price,
        // and persist it so subsequent GET /open_api/cart calls also see it.
        $requestContent = $request->getContent();
        if ($requestContent && $request->getMethod() === 'POST') {
            $requestData = json_decode($requestContent, true);
            $postModuleId = (int) ($requestData['deliveryModuleId'] ?? 0);
            $optionCode   = strtoupper($requestData['deliveryModuleOptionCode'] ?? '');

            if ($postModuleId === (int) self::getModuleId() && $optionCode) {
                $matchingOffer = \MyFlyingBox\Model\MyFlyingBoxOfferQuery::create()
                    ->useMyFlyingBoxServiceQuery()
                        ->filterByCode([$optionCode, strtolower($optionCode)])
                    ->endUse()
                    ->filterByQuoteId($quote->getId())
                    ->findOne();

                if ($matchingOffer) {
                    $price = $matchingOffer->getTotalPriceInCents() / 100;
                    // Persist so GET /open_api/cart re-fetch shows correct price
                    $session->set('mfb_selected_offer_id', $matchingOffer->getId());
                }
            }
        }

        // --- Explicit offer selected by user (Smarty widget or persisted from React) ---
        // Only apply when MFB is the currently selected delivery module in session,
        // so the cart-page estimate (no module selected yet) always shows cheapest.
        if ($price === null) {
            $selectedOfferId   = $session->get('mfb_selected_offer_id');
            $sessionModuleId   = (int) ($session->getOrder()?->getDeliveryModuleId() ?? 0);
            $mfbIsChosen       = $sessionModuleId === (int) self::getModuleId();

            if ($selectedOfferId && $mfbIsChosen) {
                $offer = \MyFlyingBox\Model\MyFlyingBoxOfferQuery::create()
                    ->filterById($selectedOfferId)
                    ->filterByQuoteId($quote->getId())
                    ->findOne();

                if ($offer) {
                    $price = $offer->getTotalPriceInCents() / 100;
                }
            }
        }

        // --- Fallback: cheapest available offer ---
        // Covers: cart-page estimate, no module selected, or unknown option code.
        if ($price === null) {
            $price = $quoteService->getBestOfferPrice($quote);
        }

        if ($price === null) {
            throw new DeliveryException(
                $this->trans('No shipping option available for your destination')
            );
        }

        // Apply surcharges
        /** @var \MyFlyingBox\Service\PriceSurchargeService $surchargeService */
        $surchargeService = $this->getContainer()->get('myflyingbox.price_surcharge.service');
        $price = $surchargeService->apply($price);

        // Price from API is already tax-included (TTC), build OrderPostage without additional tax
        $orderPostage = new OrderPostage();
        $orderPostage->setAmount(round($price, 2));
        $orderPostage->setAmountTax(0);
        $orderPostage->setTaxRuleTitle('');

        return $orderPostage;
    }

    public function isValidDelivery(Country $country, State $state = null): bool
    {
        // Check if API is configured
        $apiLogin = $this->getConfigValue(self::CONFIG_API_LOGIN);
        $apiPassword = $this->getConfigValue(self::CONFIG_API_PASSWORD);

        if (empty($apiLogin) || empty($apiPassword)) {
            return false;
        }

        // Check if shipper address is configured (required for API calls)
        $shipperCity = $this->getConfigValue(self::CONFIG_DEFAULT_SHIPPER_CITY);
        $shipperPostalCode = $this->getConfigValue(self::CONFIG_DEFAULT_SHIPPER_POSTAL_CODE);

        if (empty($shipperCity) || empty($shipperPostalCode)) {
            return false;
        }

        // Note: Services are created dynamically from API responses
        // No need to check for existing services - they will be created on first quote request
        return true;
    }

    public function handleVirtualProductDelivery(): bool
    {
        return false;
    }

    public function getDeliveryMode(): string
    {
        return 'delivery';
    }

    protected function trans(string $id, array $parameters = [], ?string $locale = null): string
    {
        if (null === $this->translator) {
            $this->translator = Translator::getInstance();
        }

        return $this->translator->trans($id, $parameters, self::DOMAIN_NAME, $locale);
    }

    public static function configureServices(ServicesConfigurator $servicesConfigurator): void
    {
        $modulePath = THELIA_MODULE_DIR . ucfirst(self::getModuleCode());

        $servicesConfigurator->load(self::getModuleCode() . '\\', __DIR__)
            ->exclude([
                $modulePath . '/I18n/*',
                $modulePath . '/Tests/*',
                $modulePath . '/Resources/*',
                $modulePath . '/vendor/*',
            ])
            ->autowire(true)
            ->autoconfigure(true);
    }

    /**
     * Returns the module code
     */
    public static function getModuleCode(): string
    {
        return 'MyFlyingBox';
    }

    /**
     * Returns the module configuration page URL
     * This makes the "Configure" button appear in the modules list
     */
    public function getConfigurationUrl(): string
    {
        return '/admin/module/MyFlyingBox';
    }

    /**
     * Check if this module has a configuration page
     * This enables the "Configure" button in the modules list
     */
    public function hasConfigurationInterface(): bool
    {
        return true;
    }
}
