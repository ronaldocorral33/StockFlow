<?php
namespace App\Services;

if (!defined('APP_BOOTSTRAP')) { http_response_code(403); exit('Forbidden'); }

/** Única fuente de verdad para costo total / ganancia (espeja las columnas generadas en SQL). */
class PricingService
{
    public static function totalCost(?float $cost, ?float $shipping): float
    {
        return round(($cost ?? 0) + ($shipping ?? 0));
    }

    public static function profit(?float $cost, ?float $shipping, ?float $salePrice): ?float
    {
        if ($salePrice === null) {
            return null;
        }
        return round($salePrice - ($cost ?? 0) - ($shipping ?? 0));
    }

    /** Convierte un monto a MXN según la moneda de captura. */
    public static function toMxn(float $amount, string $currency, float $exchangeRate): float
    {
        return $currency === 'USD' ? $amount * $exchangeRate : $amount;
    }
}
