<?php

declare(strict_types=1);

namespace MyFlyingBox\Form;

use MyFlyingBox\MyFlyingBox;
use MyFlyingBox\Service\EmailTemplateService;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Thelia\Core\Translation\Translator;
use Thelia\Form\BaseForm;

class EmailTemplateForm extends BaseForm
{
    protected function buildForm(): void
    {
        $translator = Translator::getInstance();

        $this->formBuilder
            ->add('id', HiddenType::class, [
                'required' => false,
            ])
            ->add('code', ChoiceType::class, [
                'label' => $translator->trans('Template Type', [], MyFlyingBox::DOMAIN_NAME),
                'choices' => [
                    $translator->trans('Shipment Sent', [], MyFlyingBox::DOMAIN_NAME) => EmailTemplateService::TEMPLATE_SHIPPED,
                    $translator->trans('Shipment Delivered', [], MyFlyingBox::DOMAIN_NAME) => EmailTemplateService::TEMPLATE_DELIVERED,
                ],
                'required' => true,
            ])
            ->add('locale', ChoiceType::class, [
                'label' => $translator->trans('Language', [], MyFlyingBox::DOMAIN_NAME),
                'choices' => [
                    'Francais (FR)' => 'fr_FR',
                    'English (US)' => 'en_US',
                ],
                'required' => true,
            ])
            ->add('name', TextType::class, [
                'label' => $translator->trans('Template Name', [], MyFlyingBox::DOMAIN_NAME),
                'required' => true,
                'attr' => [
                    'placeholder' => $translator->trans('e.g., Shipped notification FR', [], MyFlyingBox::DOMAIN_NAME),
                ],
            ])
            ->add('subject', TextType::class, [
                'label' => $translator->trans('Email Subject', [], MyFlyingBox::DOMAIN_NAME),
                'required' => true,
                'attr' => [
                    'placeholder' => $translator->trans('e.g., Your order {order_ref} has been shipped', [], MyFlyingBox::DOMAIN_NAME),
                ],
            ])
            ->add('html_content', TextareaType::class, [
                'label' => $translator->trans('HTML Content', [], MyFlyingBox::DOMAIN_NAME),
                'required' => true,
                'attr' => [
                    'rows' => 25,
                    'class' => 'html-editor',
                ],
            ])
            ->add('text_content', TextareaType::class, [
                'label' => $translator->trans('Plain Text Content (optional)', [], MyFlyingBox::DOMAIN_NAME),
                'required' => false,
                'attr' => [
                    'rows' => 12,
                    'placeholder' => $translator->trans('Leave empty to auto-generate from HTML', [], MyFlyingBox::DOMAIN_NAME),
                ],
            ])
            ->add('is_active', CheckboxType::class, [
                'label' => $translator->trans('Active', [], MyFlyingBox::DOMAIN_NAME),
                'required' => false,
                'data' => true,
            ]);
    }

    public static function getName(): string
    {
        return 'myflyingbox_email_template';
    }
}
