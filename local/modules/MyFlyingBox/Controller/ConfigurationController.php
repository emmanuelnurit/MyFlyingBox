<?php

namespace MyFlyingBox\Controller;

use MyFlyingBox\Form\ConfigurationForm;
use MyFlyingBox\Model\MyFlyingBoxDimension;
use MyFlyingBox\Model\MyFlyingBoxDimensionQuery;
use MyFlyingBox\Model\MyFlyingBoxService;
use MyFlyingBox\Model\MyFlyingBoxServiceQuery;
use MyFlyingBox\MyFlyingBox;
use MyFlyingBox\Service\LceApiService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thelia\Controller\Admin\BaseAdminController;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;

class ConfigurationController extends BaseAdminController
{
    /**
     * Display module configuration page
     */
    public function indexAction()
    {
        if (null !== $response = $this->checkAuth(AdminResources::MODULE, 'MyFlyingBox', AccessManager::VIEW)) {
            return $response;
        }

        return $this->render('module-configure', [
            'module_code' => 'MyFlyingBox',
        ]);
    }

    /**
     * Save module configuration
     */
    public function saveAction(Request $request): RedirectResponse
    {
        if (null !== $response = $this->checkAuth(AdminResources::MODULE, 'MyFlyingBox', AccessManager::UPDATE)) {
            return $response;
        }

        $form = $this->createForm(ConfigurationForm::getName());

        try {
            $data = $this->validateForm($form)->getData();

            // Save API settings
            MyFlyingBox::setConfigValue(MyFlyingBox::CONFIG_API_LOGIN, $data['api_login'] ?? '');
            if (!empty($data['api_password'])) {
                MyFlyingBox::setConfigValue(MyFlyingBox::CONFIG_API_PASSWORD, $data['api_password']);
            }
            MyFlyingBox::setConfigValue(MyFlyingBox::CONFIG_API_ENV, $data['api_env'] ?? 'staging');

            // Save shipper address
            MyFlyingBox::setConfigValue(MyFlyingBox::CONFIG_DEFAULT_SHIPPER_NAME, $data['shipper_name'] ?? '');
            MyFlyingBox::setConfigValue(MyFlyingBox::CONFIG_DEFAULT_SHIPPER_COMPANY, $data['shipper_company'] ?? '');
            MyFlyingBox::setConfigValue(MyFlyingBox::CONFIG_DEFAULT_SHIPPER_STREET, $data['shipper_street'] ?? '');
            MyFlyingBox::setConfigValue(MyFlyingBox::CONFIG_DEFAULT_SHIPPER_CITY, $data['shipper_city'] ?? '');
            MyFlyingBox::setConfigValue(MyFlyingBox::CONFIG_DEFAULT_SHIPPER_POSTAL_CODE, $data['shipper_postal_code'] ?? '');
            MyFlyingBox::setConfigValue(MyFlyingBox::CONFIG_DEFAULT_SHIPPER_COUNTRY, strtoupper($data['shipper_country'] ?? 'FR'));
            MyFlyingBox::setConfigValue(MyFlyingBox::CONFIG_DEFAULT_SHIPPER_PHONE, $data['shipper_phone'] ?? '');
            MyFlyingBox::setConfigValue(MyFlyingBox::CONFIG_DEFAULT_SHIPPER_EMAIL, $data['shipper_email'] ?? '');

            // Save price settings
            MyFlyingBox::setConfigValue(MyFlyingBox::CONFIG_PRICE_SURCHARGE_PERCENT, $data['price_surcharge_percent'] ?? 0);
            MyFlyingBox::setConfigValue(MyFlyingBox::CONFIG_PRICE_SURCHARGE_STATIC, $data['price_surcharge_static'] ?? 0);
            MyFlyingBox::setConfigValue(MyFlyingBox::CONFIG_PRICE_ROUND_INCREMENT, max(1, $data['price_round_increment'] ?? 1));
            MyFlyingBox::setConfigValue(MyFlyingBox::CONFIG_MAX_WEIGHT, $data['max_weight'] ?? 30);
            MyFlyingBox::setConfigValue(MyFlyingBox::CONFIG_TAX_RULE_ID, $data['tax_rule_id'] ?? null);

            // Save Google Maps key
            MyFlyingBox::setConfigValue(MyFlyingBox::CONFIG_GOOGLE_MAPS_API_KEY, $data['google_maps_api_key'] ?? '');

            // Save webhook configuration
            // Handle checkbox value (may be '1', 'on', or empty/null)
            $webhookEnabled = isset($data['webhook_enabled']) && ($data['webhook_enabled'] === '1' || $data['webhook_enabled'] === 'on' || $data['webhook_enabled'] === true) ? '1' : '0';
            MyFlyingBox::setConfigValue(MyFlyingBox::CONFIG_WEBHOOK_ENABLED, $webhookEnabled);
            MyFlyingBox::setConfigValue(MyFlyingBox::CONFIG_WEBHOOK_SECRET, $data['webhook_secret'] ?? '');

            // Save email notifications configuration
            $emailNotificationsEnabled = isset($data['email_notifications_enabled']) && ($data['email_notifications_enabled'] === '1' || $data['email_notifications_enabled'] === 'on' || $data['email_notifications_enabled'] === true) ? '1' : '0';
            MyFlyingBox::setConfigValue(MyFlyingBox::CONFIG_EMAIL_NOTIFICATIONS_ENABLED, $emailNotificationsEnabled);

            // Redirect back to module configuration page
            return new RedirectResponse('/admin/module/MyFlyingBox');

        } catch (\Exception $e) {
            $this->setupFormErrorContext(
                'Configuration',
                $e->getMessage(),
                $form
            );

            // Redirect back to module configuration page on error too
            return new RedirectResponse('/admin/module/MyFlyingBox');
        }
    }

    /**
     * Test API connection with form values (before save)
     */
    public function testApiAction(Request $request, LceApiService $apiService): JsonResponse
    {
        if (null !== $this->checkAuth(AdminResources::MODULE, 'MyFlyingBox', AccessManager::VIEW)) {
            return new JsonResponse(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        // Get form values from request (allows testing before saving)
        $data = json_decode($request->getContent(), true) ?? [];

        $login = $data['api_login'] ?? '';
        $password = $data['api_password'] ?? '';
        $environment = $data['api_env'] ?? '';

        // If environment is empty, something is wrong with form reading
        if (empty($environment)) {
            $environment = MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_API_ENV, 'staging');
        }

        // Test with the provided credentials
        $result = $apiService->testConnectionWithCredentials($login, $password, $environment);

        return new JsonResponse([
            'success' => $result['success'],
            'message' => $result['success']
                ? 'Connection successful'
                : 'Connection failed',
            'error_detail' => $result['error'],
            'environment' => $environment,
            'server_url' => $result['server_url'],
            // Debug info
            'debug' => [
                'received_env' => $data['api_env'] ?? '(not set)',
                'received_login' => !empty($login) ? substr($login, 0, 8) . '...' : '(empty)',
                'received_password' => !empty($password) ? '***' : '(empty, using saved)',
            ],
        ]);
    }

    /**
     * Refresh services from API (API v2 format)
     */
    public function refreshServicesAction(LceApiService $apiService): JsonResponse
    {
        if (null !== $this->checkAuth(AdminResources::MODULE, 'MyFlyingBox', AccessManager::UPDATE)) {
            return new JsonResponse(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        try {
            $response = $apiService->getProducts();
            $count = 0;

            // API v2 returns products in 'data' key
            $products = $response['data'] ?? $response['products'] ?? [];

            if (!empty($products)) {
                foreach ($products as $product) {
                    $code = $product['code'] ?? '';
                    if (empty($code)) {
                        continue;
                    }

                    $service = MyFlyingBoxServiceQuery::create()
                        ->filterByCode($code)
                        ->findOne();

                    $isNew = false;
                    if (!$service) {
                        $service = new MyFlyingBoxService();
                        $service->setCode($code);
                        $service->setActive(true); // New services are active by default
                        $isNew = true;
                    }

                    // API v2 field names: pick_up, drop_off, preset_delivery_location
                    $carrierCode = $product['carrier_code'] ?? '';
                    $serviceName = $product['name'] ?? '';
                    $isRelay = $product['preset_delivery_location'] ?? false;

                    $service->setCarrierCode($carrierCode);
                    $service->setName($serviceName);
                    $service->setPickupAvailable($product['pick_up'] ?? false);
                    $service->setDropoffAvailable($product['drop_off'] ?? false);
                    $service->setRelayDelivery($isRelay);
                    $service->setTrackingUrl($product['tracking_url'] ?? null);

                    // Set default delivery delay for new services
                    if ($isNew || empty($service->getDeliveryDelay())) {
                        $service->setDeliveryDelay($this->guessDeliveryDelay($carrierCode, $code, $serviceName, $isRelay));
                    }

                    $service->save();
                    $count++;
                }
            }

            // Get accurate counts after update
            $totalCount = MyFlyingBoxServiceQuery::create()->count();
            $activeCount = MyFlyingBoxServiceQuery::create()->filterByActive(true)->count();

            return new JsonResponse([
                'success' => true,
                'message' => sprintf('%d services synchronized', $totalCount),
                'count' => $totalCount,
                'total_count' => $totalCount,
                'active_count' => $activeCount,
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Toggle service active status
     */
    public function toggleServiceAction(Request $request): JsonResponse
    {
        if (null !== $this->checkAuth(AdminResources::MODULE, 'MyFlyingBox', AccessManager::UPDATE)) {
            return new JsonResponse(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        try {
            $data = json_decode($request->getContent(), true);
            $serviceId = $data['id'] ?? null;
            $active = (bool) ($data['active'] ?? false);

            if (!$serviceId) {
                return new JsonResponse(['success' => false, 'message' => 'Service ID required']);
            }

            $service = MyFlyingBoxServiceQuery::create()->findPk($serviceId);

            if (!$service) {
                return new JsonResponse(['success' => false, 'message' => 'Service not found']);
            }

            $service->setActive($active);
            $service->save();

            return new JsonResponse([
                'success' => true,
                'message' => $active ? 'Service activated' : 'Service deactivated',
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Update service delivery delay
     */
    public function updateServiceDelayAction(Request $request): JsonResponse
    {
        if (null !== $this->checkAuth(AdminResources::MODULE, 'MyFlyingBox', AccessManager::UPDATE)) {
            return new JsonResponse(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        try {
            $data = json_decode($request->getContent(), true);
            $serviceId = $data['id'] ?? null;
            $deliveryDelay = trim($data['delivery_delay'] ?? '');

            if (!$serviceId) {
                return new JsonResponse(['success' => false, 'message' => 'Service ID required']);
            }

            $service = MyFlyingBoxServiceQuery::create()->findPk($serviceId);

            if (!$service) {
                return new JsonResponse(['success' => false, 'message' => 'Service not found']);
            }

            $service->setDeliveryDelay($deliveryDelay ?: null);
            $service->save();

            return new JsonResponse([
                'success' => true,
                'message' => 'Delivery delay updated',
                'delivery_delay' => $deliveryDelay,
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Save dimension mapping
     */
    public function saveDimensionAction(Request $request): JsonResponse
    {
        if (null !== $this->checkAuth(AdminResources::MODULE, 'MyFlyingBox', AccessManager::UPDATE)) {
            return new JsonResponse(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        try {
            $data = json_decode($request->getContent(), true);

            $id = $data['id'] ?? null;
            $weightFrom = (float) ($data['weight_from'] ?? 0);
            $weightTo = (float) ($data['weight_to'] ?? 0);
            $length = (int) ($data['length'] ?? 0);
            $width = (int) ($data['width'] ?? 0);
            $height = (int) ($data['height'] ?? 0);

            if ($weightFrom >= $weightTo) {
                return new JsonResponse(['success' => false, 'message' => 'Weight From must be less than Weight To']);
            }

            if ($length <= 0 || $width <= 0 || $height <= 0) {
                return new JsonResponse(['success' => false, 'message' => 'Dimensions must be positive']);
            }

            $dimension = $id ? MyFlyingBoxDimensionQuery::create()->findPk($id) : new MyFlyingBoxDimension();

            if (!$dimension) {
                $dimension = new MyFlyingBoxDimension();
            }

            $dimension->setWeightFrom($weightFrom);
            $dimension->setWeightTo($weightTo);
            $dimension->setLength($length);
            $dimension->setWidth($width);
            $dimension->setHeight($height);
            $dimension->save();

            return new JsonResponse([
                'success' => true,
                'message' => 'Dimension saved',
                'id' => $dimension->getId(),
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Delete dimension mapping
     */
    public function deleteDimensionAction(Request $request): JsonResponse
    {
        if (null !== $this->checkAuth(AdminResources::MODULE, 'MyFlyingBox', AccessManager::DELETE)) {
            return new JsonResponse(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        try {
            $data = json_decode($request->getContent(), true);
            $id = $data['id'] ?? null;

            if (!$id) {
                return new JsonResponse(['success' => false, 'message' => 'ID required']);
            }

            $dimension = MyFlyingBoxDimensionQuery::create()->findPk($id);

            if ($dimension) {
                $dimension->delete();
            }

            return new JsonResponse([
                'success' => true,
                'message' => 'Dimension deleted',
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Diagnostic endpoint to check module configuration and status
     */
    public function diagnosticAction(): JsonResponse
    {
        if (null !== $this->checkAuth(AdminResources::MODULE, 'MyFlyingBox', AccessManager::VIEW)) {
            return new JsonResponse(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        try {
            // Check module status
            $module = \Thelia\Model\ModuleQuery::create()
                ->filterByCode('MyFlyingBox')
                ->findOne();

            $moduleStatus = [
                'found' => $module !== null,
                'id' => $module?->getId(),
                'active' => $module?->getActivate() ? true : false,
                'type' => $module?->getType(),
            ];

            // Check configuration values
            $configChecks = [
                'api_login' => [
                    'key' => MyFlyingBox::CONFIG_API_LOGIN,
                    'value' => MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_API_LOGIN, ''),
                    'is_set' => !empty(MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_API_LOGIN, '')),
                ],
                'api_password' => [
                    'key' => MyFlyingBox::CONFIG_API_PASSWORD,
                    'value' => '***HIDDEN***',
                    'is_set' => !empty(MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_API_PASSWORD, '')),
                ],
                'api_env' => [
                    'key' => MyFlyingBox::CONFIG_API_ENV,
                    'value' => MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_API_ENV, ''),
                    'is_set' => !empty(MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_API_ENV, '')),
                ],
                'shipper_city' => [
                    'key' => MyFlyingBox::CONFIG_DEFAULT_SHIPPER_CITY,
                    'value' => MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_DEFAULT_SHIPPER_CITY, ''),
                    'is_set' => !empty(MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_DEFAULT_SHIPPER_CITY, '')),
                ],
                'shipper_postal_code' => [
                    'key' => MyFlyingBox::CONFIG_DEFAULT_SHIPPER_POSTAL_CODE,
                    'value' => MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_DEFAULT_SHIPPER_POSTAL_CODE, ''),
                    'is_set' => !empty(MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_DEFAULT_SHIPPER_POSTAL_CODE, '')),
                ],
                'shipper_country' => [
                    'key' => MyFlyingBox::CONFIG_DEFAULT_SHIPPER_COUNTRY,
                    'value' => MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_DEFAULT_SHIPPER_COUNTRY, ''),
                    'is_set' => !empty(MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_DEFAULT_SHIPPER_COUNTRY, '')),
                ],
            ];

            // Check services
            $servicesCount = MyFlyingBoxServiceQuery::create()->count();
            $activeServicesCount = MyFlyingBoxServiceQuery::create()->filterByActive(true)->count();

            // Check if isValidDelivery would pass
            $isValidChecks = [
                'api_credentials_ok' => $configChecks['api_login']['is_set'] && $configChecks['api_password']['is_set'],
                'shipper_address_ok' => $configChecks['shipper_city']['is_set'] && $configChecks['shipper_postal_code']['is_set'],
            ];
            $isValidWouldPass = $isValidChecks['api_credentials_ok'] && $isValidChecks['shipper_address_ok'];

            // Check France country
            $france = \Thelia\Model\CountryQuery::create()->filterByIsoalpha2('FR')->findOne();

            return new JsonResponse([
                'success' => true,
                'module' => $moduleStatus,
                'configuration' => $configChecks,
                'services' => [
                    'total' => $servicesCount,
                    'active' => $activeServicesCount,
                ],
                'isValidDelivery' => [
                    'checks' => $isValidChecks,
                    'would_pass' => $isValidWouldPass,
                ],
                'france_country_id' => $france?->getId(),
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Guess delivery delay based on carrier and service info (in hours)
     */
    private function guessDeliveryDelay(string $carrierCode, string $code, string $name, bool $isRelay): string
    {
        $carrierCode = strtolower($carrierCode);
        $code = strtolower($code);
        $name = strtolower($name);

        // Express services (24-48h)
        $expressCarriers = ['chronopost', 'dhl_express', 'dhl', 'fedex', 'ups', 'tnt'];
        if (in_array($carrierCode, $expressCarriers)
            || strpos($code, 'express') !== false
            || strpos($code, 'chrono') !== false
            || strpos($name, 'express') !== false
            || strpos($name, '24') !== false
            || strpos($name, 'j+1') !== false
        ) {
            return '24-48h';
        }

        // Relay point services (72-120h)
        if ($isRelay
            || strpos($code, 'relay') !== false
            || strpos($code, 'relais') !== false
            || strpos($code, 'pickup') !== false
            || strpos($code, 'point') !== false
            || strpos($name, 'relais') !== false
            || strpos($name, 'point') !== false
        ) {
            return '72-120h';
        }

        // Mondial Relay / Colis Privé (96-144h)
        $slowCarriers = ['mondial_relay', 'mondialrelay', 'colis_prive', 'colisprive', 'shop2shop'];
        if (in_array($carrierCode, $slowCarriers)) {
            return '96-144h';
        }

        // Standard services (48-96h)
        $standardCarriers = ['colissimo', 'laposte', 'dpd', 'gls', 'hermes'];
        if (in_array($carrierCode, $standardCarriers)
            || strpos($code, 'standard') !== false
            || strpos($name, 'standard') !== false
        ) {
            return '48-96h';
        }

        // Default
        return '72-120h';
    }
}
