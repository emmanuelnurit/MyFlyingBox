<?php

namespace MyFlyingBox\Service;

use MyFlyingBox\Model\MyFlyingBoxDimensionQuery;
use MyFlyingBox\MyFlyingBox;
use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Model\Cart;

/**
 * Service for calculating parcel dimensions from cart weight
 */
class DimensionService
{
    /**
     * Calculate parcel data from cart
     * Returns array of parcels with dimensions
     *
     * @param Cart $cart The shopping cart
     * @return array Array of parcels with length, width, height, weight
     */
    public function getParcelDataFromCart(Cart $cart): array
    {
        $maxWeight = (float) MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_MAX_WEIGHT, 30);
        $totalWeight = $cart->getWeight();

        // Handle empty or zero weight cart
        if ($totalWeight <= 0) {
            return [[
                'length' => 15,
                'width' => 15,
                'height' => 15,
                'weight' => 0.5,
            ]];
        }

        $parcels = [];

        // Split into multiple parcels if over max weight
        if ($totalWeight > $maxWeight) {
            $numParcels = (int) ceil($totalWeight / $maxWeight);
            $weightPerParcel = $totalWeight / $numParcels;

            for ($i = 0; $i < $numParcels; $i++) {
                $dimensions = $this->getDimensionsForWeight($weightPerParcel);
                $parcels[] = [
                    'length' => $dimensions['length'],
                    'width' => $dimensions['width'],
                    'height' => $dimensions['height'],
                    'weight' => round($weightPerParcel, 3),
                ];
            }
        } else {
            // Single parcel
            $dimensions = $this->getDimensionsForWeight($totalWeight);
            $parcels[] = [
                'length' => $dimensions['length'],
                'width' => $dimensions['width'],
                'height' => $dimensions['height'],
                'weight' => round($totalWeight, 3),
            ];
        }

        return $parcels;
    }

    /**
     * Get dimensions for a given weight from the mapping table
     *
     * @param float $weight Weight in kg
     * @return array Dimensions (length, width, height) in cm
     */
    public function getDimensionsForWeight(float $weight): array
    {
        try {
            $dimension = MyFlyingBoxDimensionQuery::create()
                ->filterByWeightFrom($weight, Criteria::LESS_EQUAL)
                ->filterByWeightTo($weight, Criteria::GREATER_EQUAL)
                ->findOne();

            if ($dimension) {
                return [
                    'length' => $dimension->getLength(),
                    'width' => $dimension->getWidth(),
                    'height' => $dimension->getHeight(),
                ];
            }
        } catch (\Exception $e) {
            // Table may not exist yet during installation
        }

        // Default dimensions based on weight ranges (fallback)
        return $this->getDefaultDimensions($weight);
    }

    /**
     * Get default dimensions when no mapping is found
     */
    private function getDefaultDimensions(float $weight): array
    {
        // Default dimension mapping
        $mappings = [
            1 => ['length' => 15, 'width' => 15, 'height' => 15],
            2 => ['length' => 18, 'width' => 18, 'height' => 18],
            3 => ['length' => 20, 'width' => 20, 'height' => 20],
            5 => ['length' => 25, 'width' => 25, 'height' => 20],
            7 => ['length' => 30, 'width' => 25, 'height' => 20],
            10 => ['length' => 35, 'width' => 30, 'height' => 25],
            15 => ['length' => 40, 'width' => 35, 'height' => 30],
            20 => ['length' => 50, 'width' => 40, 'height' => 30],
            25 => ['length' => 55, 'width' => 45, 'height' => 35],
            30 => ['length' => 60, 'width' => 50, 'height' => 40],
        ];

        foreach ($mappings as $maxWeight => $dims) {
            if ($weight <= $maxWeight) {
                return $dims;
            }
        }

        // Very heavy items
        return ['length' => 60, 'width' => 50, 'height' => 40];
    }

    /**
     * Calculate volumetric weight
     * Formula: (L x W x H) / 5000
     *
     * @param int $length Length in cm
     * @param int $width Width in cm
     * @param int $height Height in cm
     * @return float Volumetric weight in kg
     */
    public function calculateVolumetricWeight(int $length, int $width, int $height): float
    {
        return ($length * $width * $height) / 5000;
    }

    /**
     * Get the billable weight (max of actual and volumetric)
     */
    public function getBillableWeight(float $actualWeight, int $length, int $width, int $height): float
    {
        $volumetricWeight = $this->calculateVolumetricWeight($length, $width, $height);
        return max($actualWeight, $volumetricWeight);
    }
}
