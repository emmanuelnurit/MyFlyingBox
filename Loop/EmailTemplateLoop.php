<?php

declare(strict_types=1);

namespace MyFlyingBox\Loop;

use MyFlyingBox\Model\MyFlyingBoxEmailTemplateQuery;
use Thelia\Core\Template\Element\BaseLoop;
use Thelia\Core\Template\Element\LoopResult;
use Thelia\Core\Template\Element\LoopResultRow;
use Thelia\Core\Template\Element\PropelSearchLoopInterface;
use Thelia\Core\Template\Loop\Argument\Argument;
use Thelia\Core\Template\Loop\Argument\ArgumentCollection;

/**
 * Loop to display MyFlyingBox email templates
 *
 * {loop type="myflyingbox.email_templates" name="templates" active="true"}
 *   {$ID} {$CODE} {$LOCALE} {$NAME} {$SUBJECT} {$IS_ACTIVE} {$IS_DEFAULT}
 * {/loop}
 */
class EmailTemplateLoop extends BaseLoop implements PropelSearchLoopInterface
{
    protected function getArgDefinitions(): ArgumentCollection
    {
        return new ArgumentCollection(
            Argument::createIntTypeArgument('id'),
            Argument::createAnyTypeArgument('code'),
            Argument::createAnyTypeArgument('locale'),
            Argument::createBooleanTypeArgument('active'),
            Argument::createBooleanTypeArgument('is_default'),
            Argument::createAnyTypeArgument('order', 'code')
        );
    }

    public function buildModelCriteria(): MyFlyingBoxEmailTemplateQuery
    {
        $query = MyFlyingBoxEmailTemplateQuery::create();

        if (null !== $id = $this->getId()) {
            $query->filterById($id);
        }

        if (null !== $code = $this->getCode()) {
            $query->filterByCode($code);
        }

        if (null !== $locale = $this->getLocale()) {
            $query->filterByLocale($locale);
        }

        if (null !== $active = $this->getActive()) {
            $query->filterByIsActive($active);
        }

        if (null !== $isDefault = $this->getIsDefault()) {
            $query->filterByIsDefault($isDefault);
        }

        // Handle ordering
        $order = $this->getOrder();
        switch ($order) {
            case 'id':
                $query->orderById();
                break;
            case 'locale':
                $query->orderByLocale();
                break;
            case 'name':
                $query->orderByName();
                break;
            case 'updated_at':
                $query->orderByUpdatedAt();
                break;
            case 'code':
            default:
                $query->orderByCode()->orderByLocale();
                break;
        }

        return $query;
    }

    public function parseResults(LoopResult $loopResult): LoopResult
    {
        foreach ($loopResult->getResultDataCollection() as $template) {
            $row = new LoopResultRow($template);

            $row->set('ID', $template->getId())
                ->set('CODE', $template->getCode())
                ->set('LOCALE', $template->getLocale())
                ->set('NAME', $template->getName())
                ->set('SUBJECT', $template->getSubject())
                ->set('HTML_CONTENT', $template->getHtmlContent())
                ->set('TEXT_CONTENT', $template->getTextContent())
                ->set('IS_ACTIVE', $template->getIsActive())
                ->set('IS_DEFAULT', $template->getIsDefault())
                ->set('CREATED_AT', $template->getCreatedAt())
                ->set('UPDATED_AT', $template->getUpdatedAt());

            $loopResult->addRow($row);
        }

        return $loopResult;
    }
}
