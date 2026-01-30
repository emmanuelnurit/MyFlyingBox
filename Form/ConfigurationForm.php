<?php

namespace MyFlyingBox\Form;

use MyFlyingBox\MyFlyingBox;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Thelia\Core\Translation\Translator;
use Thelia\Form\BaseForm;
use Thelia\Model\TaxRuleQuery;

class ConfigurationForm extends BaseForm
{
    protected function buildForm(): void
    {
        // Add success and error URLs for proper redirect
        $this->formBuilder
            ->add('success_url', \Symfony\Component\Form\Extension\Core\Type\HiddenType::class, [
                'data' => '/admin/module/MyFlyingBox',
            ])
            ->add('error_url', \Symfony\Component\Form\Extension\Core\Type\HiddenType::class, [
                'data' => '/admin/module/MyFlyingBox',
            ]);

        $translator = Translator::getInstance();

        $this->formBuilder
            // API Settings
            ->add('api_login', TextType::class, [
                'label' => $translator->trans('API Login', [], MyFlyingBox::DOMAIN_NAME),
                'required' => false,
                'data' => MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_API_LOGIN, ''),
                'attr' => [
                    'placeholder' => $translator->trans('Your MyFlyingBox API login', [], MyFlyingBox::DOMAIN_NAME),
                ],
            ])
            ->add('api_password', PasswordType::class, [
                'label' => $translator->trans('API Password', [], MyFlyingBox::DOMAIN_NAME),
                'required' => false,
                'attr' => [
                    'autocomplete' => 'new-password',
                    'placeholder' => $translator->trans('Leave empty to keep current', [], MyFlyingBox::DOMAIN_NAME),
                ],
            ])
            ->add('api_env', ChoiceType::class, [
                'label' => $translator->trans('Environment', [], MyFlyingBox::DOMAIN_NAME),
                'choices' => [
                    $translator->trans('Staging (test)', [], MyFlyingBox::DOMAIN_NAME) => 'staging',
                    $translator->trans('Production', [], MyFlyingBox::DOMAIN_NAME) => 'production',
                ],
                'data' => MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_API_ENV, 'staging'),
            ])

            // Shipper Address
            ->add('shipper_name', TextType::class, [
                'label' => $translator->trans('Shipper Name', [], MyFlyingBox::DOMAIN_NAME),
                'required' => false,
                'data' => MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_DEFAULT_SHIPPER_NAME, ''),
            ])
            ->add('shipper_company', TextType::class, [
                'label' => $translator->trans('Company', [], MyFlyingBox::DOMAIN_NAME),
                'required' => false,
                'data' => MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_DEFAULT_SHIPPER_COMPANY, ''),
            ])
            ->add('shipper_street', TextType::class, [
                'label' => $translator->trans('Street Address', [], MyFlyingBox::DOMAIN_NAME),
                'required' => false,
                'data' => MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_DEFAULT_SHIPPER_STREET, ''),
            ])
            ->add('shipper_city', TextType::class, [
                'label' => $translator->trans('City', [], MyFlyingBox::DOMAIN_NAME),
                'required' => false,
                'data' => MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_DEFAULT_SHIPPER_CITY, ''),
            ])
            ->add('shipper_postal_code', TextType::class, [
                'label' => $translator->trans('Postal Code', [], MyFlyingBox::DOMAIN_NAME),
                'required' => false,
                'data' => MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_DEFAULT_SHIPPER_POSTAL_CODE, ''),
            ])
            ->add('shipper_country', TextType::class, [
                'label' => $translator->trans('Country Code (2 letters)', [], MyFlyingBox::DOMAIN_NAME),
                'required' => false,
                'data' => MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_DEFAULT_SHIPPER_COUNTRY, 'FR'),
                'attr' => [
                    'maxlength' => 2,
                    'style' => 'text-transform: uppercase; width: 60px;',
                ],
            ])
            ->add('shipper_phone', TextType::class, [
                'label' => $translator->trans('Phone', [], MyFlyingBox::DOMAIN_NAME),
                'required' => false,
                'data' => MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_DEFAULT_SHIPPER_PHONE, ''),
            ])
            ->add('shipper_email', TextType::class, [
                'label' => $translator->trans('Email', [], MyFlyingBox::DOMAIN_NAME),
                'required' => false,
                'data' => MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_DEFAULT_SHIPPER_EMAIL, ''),
            ])

            // Price Settings
            ->add('price_surcharge_percent', NumberType::class, [
                'label' => $translator->trans('Price Surcharge (%)', [], MyFlyingBox::DOMAIN_NAME),
                'required' => false,
                'data' => (float) MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_PRICE_SURCHARGE_PERCENT, 0),
                'attr' => [
                    'step' => '0.01',
                ],
            ])
            ->add('price_surcharge_static', NumberType::class, [
                'label' => $translator->trans('Price Surcharge (cents)', [], MyFlyingBox::DOMAIN_NAME),
                'required' => false,
                'data' => (int) MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_PRICE_SURCHARGE_STATIC, 0),
                'attr' => [
                    'step' => '1',
                ],
            ])
            ->add('price_round_increment', NumberType::class, [
                'label' => $translator->trans('Rounding Increment (cents)', [], MyFlyingBox::DOMAIN_NAME),
                'required' => false,
                'data' => (int) MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_PRICE_ROUND_INCREMENT, 1),
                'attr' => [
                    'step' => '1',
                    'min' => '1',
                ],
            ])
            ->add('max_weight', NumberType::class, [
                'label' => $translator->trans('Max Weight per Parcel (kg)', [], MyFlyingBox::DOMAIN_NAME),
                'required' => false,
                'data' => (float) MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_MAX_WEIGHT, 30),
                'attr' => [
                    'step' => '0.1',
                    'min' => '0.1',
                ],
            ])

            // Tax Rule
            ->add('tax_rule_id', ChoiceType::class, [
                'label' => $translator->trans('Tax Rule', [], MyFlyingBox::DOMAIN_NAME),
                'choices' => $this->getTaxRuleChoices(),
                'required' => false,
                'data' => MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_TAX_RULE_ID),
            ])

            // Google Maps
            ->add('google_maps_api_key', TextType::class, [
                'label' => $translator->trans('Google Maps API Key', [], MyFlyingBox::DOMAIN_NAME),
                'required' => false,
                'data' => MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_GOOGLE_MAPS_API_KEY, ''),
                'attr' => [
                    'placeholder' => $translator->trans('Required for relay point map', [], MyFlyingBox::DOMAIN_NAME),
                ],
            ])

            // Webhook Configuration
            ->add('webhook_enabled', TextType::class, [
                'label' => $translator->trans('Enable webhooks', [], MyFlyingBox::DOMAIN_NAME),
                'required' => false,
                'data' => MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_WEBHOOK_ENABLED, '0'),
            ])
            ->add('webhook_secret', TextType::class, [
                'label' => $translator->trans('Webhook Secret', [], MyFlyingBox::DOMAIN_NAME),
                'required' => false,
                'data' => MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_WEBHOOK_SECRET, ''),
                'attr' => [
                    'placeholder' => $translator->trans('Leave empty for no signature validation', [], MyFlyingBox::DOMAIN_NAME),
                ],
            ])

            // Email Notifications
            ->add('email_notifications_enabled', TextType::class, [
                'label' => $translator->trans('Enable email notifications', [], MyFlyingBox::DOMAIN_NAME),
                'required' => false,
                'data' => MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_EMAIL_NOTIFICATIONS_ENABLED, '1'),
            ]);
    }

    private function getTaxRuleChoices(): array
    {
        $translator = Translator::getInstance();
        $choices = [
            $translator->trans('No tax', [], MyFlyingBox::DOMAIN_NAME) => '',
        ];

        try {
            $taxRules = TaxRuleQuery::create()->find();
            foreach ($taxRules as $taxRule) {
                $choices[$taxRule->getTitle()] = $taxRule->getId();
            }
        } catch (\Exception $e) {
            // Ignore if tax rules table not available
        }

        return $choices;
    }

    public static function getName(): string
    {
        return 'myflyingbox_configuration';
    }
}
