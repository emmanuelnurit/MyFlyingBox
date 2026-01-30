<?php

namespace MyFlyingBox\Form;

use MyFlyingBox\MyFlyingBox;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Thelia\Core\Translation\Translator;
use Thelia\Form\BaseForm;

class DimensionForm extends BaseForm
{
    protected function buildForm(): void
    {
        $translator = Translator::getInstance();

        $this->formBuilder
            ->add('id', HiddenType::class, [
                'required' => false,
            ])
            ->add('weight_from', NumberType::class, [
                'label' => $translator->trans('Weight From (kg)', [], MyFlyingBox::DOMAIN_NAME),
                'required' => true,
                'attr' => [
                    'step' => '0.001',
                    'min' => '0',
                ],
            ])
            ->add('weight_to', NumberType::class, [
                'label' => $translator->trans('Weight To (kg)', [], MyFlyingBox::DOMAIN_NAME),
                'required' => true,
                'attr' => [
                    'step' => '0.001',
                    'min' => '0',
                ],
            ])
            ->add('length', NumberType::class, [
                'label' => $translator->trans('Length (cm)', [], MyFlyingBox::DOMAIN_NAME),
                'required' => true,
                'attr' => [
                    'step' => '1',
                    'min' => '1',
                ],
            ])
            ->add('width', NumberType::class, [
                'label' => $translator->trans('Width (cm)', [], MyFlyingBox::DOMAIN_NAME),
                'required' => true,
                'attr' => [
                    'step' => '1',
                    'min' => '1',
                ],
            ])
            ->add('height', NumberType::class, [
                'label' => $translator->trans('Height (cm)', [], MyFlyingBox::DOMAIN_NAME),
                'required' => true,
                'attr' => [
                    'step' => '1',
                    'min' => '1',
                ],
            ]);
    }

    public static function getName(): string
    {
        return 'myflyingbox_dimension';
    }
}
