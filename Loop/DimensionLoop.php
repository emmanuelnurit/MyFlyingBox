<?php

namespace MyFlyingBox\Loop;

use MyFlyingBox\Model\MyFlyingBoxDimensionQuery;
use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Core\Template\Element\BaseLoop;
use Thelia\Core\Template\Element\LoopResult;
use Thelia\Core\Template\Element\LoopResultRow;
use Thelia\Core\Template\Element\PropelSearchLoopInterface;
use Thelia\Core\Template\Loop\Argument\Argument;
use Thelia\Core\Template\Loop\Argument\ArgumentCollection;

/**
 * Loop to display dimension mappings
 *
 * {loop type="myflyingbox.dimensions" name="dimensions" order="weight_from"}
 *   {$ID} {$WEIGHT_FROM} {$WEIGHT_TO} {$LENGTH} {$WIDTH} {$HEIGHT}
 * {/loop}
 */
class DimensionLoop extends BaseLoop implements PropelSearchLoopInterface
{
    protected function getArgDefinitions(): ArgumentCollection
    {
        return new ArgumentCollection(
            Argument::createIntTypeArgument('id'),
            Argument::createAnyTypeArgument('order', 'weight_from')
        );
    }

    public function buildModelCriteria()
    {
        $query = MyFlyingBoxDimensionQuery::create();

        if (null !== $id = $this->getId()) {
            $query->filterById($id);
        }

        // Handle ordering
        $order = $this->getOrder();
        switch ($order) {
            case 'id':
                $query->orderById();
                break;
            case 'weight_to':
                $query->orderByWeightTo();
                break;
            case 'weight_from':
            default:
                $query->orderByWeightFrom();
                break;
        }

        return $query;
    }

    public function parseResults(LoopResult $loopResult): LoopResult
    {
        foreach ($loopResult->getResultDataCollection() as $dimension) {
            $row = new LoopResultRow($dimension);

            $row->set('ID', $dimension->getId())
                ->set('WEIGHT_FROM', $dimension->getWeightFrom())
                ->set('WEIGHT_TO', $dimension->getWeightTo())
                ->set('LENGTH', $dimension->getLength())
                ->set('WIDTH', $dimension->getWidth())
                ->set('HEIGHT', $dimension->getHeight());

            $loopResult->addRow($row);
        }

        return $loopResult;
    }
}
