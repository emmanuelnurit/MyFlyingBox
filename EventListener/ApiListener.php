<?php

namespace MyFlyingBox\EventListener;

use MyFlyingBox\MyFlyingBox;
use OpenApi\Events\DeliveryModuleOptionEvent;
use OpenApi\Events\OpenApiEvents;
use OpenApi\Model\Api\DeliveryModuleOption;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Translation\Translator;
use Thelia\Model\ModuleQuery;
use Thelia\Module\Exception\DeliveryException;

class ApiListener implements EventSubscriberInterface
{
    /** @var ContainerInterface */
    protected $container;

    /** @var Request|null */
    protected $request;

    public function __construct(ContainerInterface $container, RequestStack $requestStack)
    {
        $this->container = $container;
        $this->request = $requestStack->getCurrentRequest();
    }

    public static function getSubscribedEvents()
    {
        return [
            OpenApiEvents::MODULE_DELIVERY_GET_OPTIONS => ['getDeliveryModuleOptions', 129],
        ];
    }

    public function getDeliveryModuleOptions(DeliveryModuleOptionEvent $event)
    {
        // Only for MyFlyingBox module
        if ((int) $event->getModule()->getId() !== (int) MyFlyingBox::getModuleId()) {
            return;
        }

        $isValid = true;
        $postage = null;
        $postageTax = null;

        try {
            $locale = $this->request ? $this->request->getSession()->getLang()->getLocale() : null;

            $propelModule = ModuleQuery::create()
                ->filterById(MyFlyingBox::getModuleId())
                ->findOne();

            if (null === $propelModule) {
                throw new \Exception('MyFlyingBox module not found');
            }

            /** @var \MyFlyingBox\MyFlyingBox $moduleInstance */
            $moduleInstance = $propelModule->getModuleInstance($this->container);

            $country = $event->getCountry();
            $state = $event->getState();

            if (!$moduleInstance->isValidDelivery($country, $state)) {
                throw new DeliveryException(Translator::getInstance()->trans('MyFlyingBox is not available'));
            }

            $orderPostage = $moduleInstance->getPostage($country, $state);
            $postage = $orderPostage->getAmount();
            $postageTax = $orderPostage->getAmountTax();
        } catch (\Exception $e) {
            $isValid = false;
        }

        /** @var DeliveryModuleOption $option */
        $option = $this->container->get('open_api.model.factory')->buildModel('DeliveryModuleOption');

        $option
            ->setCode('mfb_default')
            ->setValid($isValid)
            ->setTitle('MyFlyingBox')
            ->setImage('')
            ->setMinimumDeliveryDate('')
            ->setMaximumDeliveryDate('')
            ->setPostage($postage)
            ->setPostageTax($postageTax)
            ->setPostageUntaxed(
                ($postage !== null && $postageTax !== null) ? ($postage - $postageTax) : null
            )
        ;

        $event->appendDeliveryModuleOptions($option);
    }
}