<?php

declare(strict_types=1);

namespace MyFlyingBox\Service;

use MyFlyingBox\MyFlyingBox;

/**
 * Shared service for applying price surcharges (percentage + static + rounding)
 */
final readonly class PriceSurchargeService
{
    /**
     * Apply configured price surcharges to a base price
     *
     * @param float $price Base price in euros
     * @return float Adjusted price in euros, rounded to 2 decimals
     */
    public function apply(float $price): float
    {
        // Percentage surcharge
        $percentSurcharge = (float) MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_PRICE_SURCHARGE_PERCENT, 0);
        if ($percentSurcharge > 0) {
            $price += $price * ($percentSurcharge / 100);
        }

        // Static surcharge (stored in cents, convert to euros)
        $staticSurcharge = (float) MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_PRICE_SURCHARGE_STATIC, 0);
        if ($staticSurcharge > 0) {
            $price += $staticSurcharge / 100;
        }

        // Rounding
        $roundIncrement = (int) MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_PRICE_ROUND_INCREMENT, 1);
        if ($roundIncrement > 1) {
            $price = ceil($price * 100 / $roundIncrement) * $roundIncrement / 100;
        }

        return round($price, 2);
    }
}
