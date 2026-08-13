<?php
namespace App\Services;

if (!defined('APP_BOOTSTRAP')) { http_response_code(403); exit('Forbidden'); }

use App\Database;

/** Serie mensual de ventas y proyección simple (regresión lineal / promedio móvil). Usada por el dashboard Y el chatbot. */
class TrendService
{
    private static array $MONTH_NAMES = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];

    /** @return array<int, array{month:string,label:string,units:int,revenue:float,profit:float}> */
    public static function monthlySeries(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT DATE_FORMAT(sale_date, '%Y-%m') AS month,
                    COUNT(*) AS units,
                    SUM(sale_price) AS revenue,
                    SUM(profit) AS profit
             FROM inventory_items
             WHERE user_id = ? AND sale_date IS NOT NULL
             GROUP BY month
             ORDER BY month ASC"
        );
        $stmt->execute([$userId]);
        return array_map(function ($row) {
            $row['label'] = self::monthLabel($row['month']);
            $row['units'] = (int)$row['units'];
            $row['revenue'] = (float)$row['revenue'];
            $row['profit'] = (float)$row['profit'];
            return $row;
        }, $stmt->fetchAll());
    }

    private static function monthLabel(string $ym): string
    {
        [$y, $m] = explode('-', $ym);
        return self::$MONTH_NAMES[(int)$m - 1] . ' ' . $y;
    }

    /**
     * Proyecta unidades/ingreso/ganancia para los próximos $months meses.
     * Con 3+ meses de historia usa regresión lineal simple (mínimos cuadrados);
     * con 1-2 meses usa el promedio disponible (proyección plana);
     * sin historia, regresa method='insufficient_data'.
     */
    public static function projectNextMonths(int $userId, int $months): array
    {
        $history = self::monthlySeries($userId);
        $n = count($history);
        $months = max(1, min(6, $months));

        if ($n === 0) {
            return ['history' => [], 'projections' => [], 'method' => 'insufficient_data'];
        }

        $method = $n >= 3 ? 'linear_regression' : 'moving_average';
        $projections = [];

        foreach (['units', 'revenue', 'profit'] as $metric) {
            $ys = array_map(fn($h) => $h[$metric], $history);
            $projections[$metric] = $method === 'linear_regression'
                ? self::linearProject($ys, $months)
                : array_fill(0, $months, array_sum($ys) / count($ys));
        }

        $lastMonth = $history[$n - 1]['month'];
        $labels = [];
        for ($i = 1; $i <= $months; $i++) {
            $ym = date('Y-m', strtotime($lastMonth . '-01 +' . $i . ' months'));
            $labels[] = ['month' => $ym, 'label' => self::monthLabel($ym)];
        }

        $result = [];
        for ($i = 0; $i < $months; $i++) {
            $result[] = [
                'month' => $labels[$i]['month'],
                'label' => $labels[$i]['label'],
                'units' => max(0, round($projections['units'][$i])),
                'revenue' => max(0, round($projections['revenue'][$i])),
                'profit' => round($projections['profit'][$i]),
            ];
        }

        return ['history' => $history, 'projections' => $result, 'method' => $method];
    }

    /** Regresión lineal simple (mínimos cuadrados) sobre y=f(x), x=0..n-1; proyecta los siguientes $count puntos. */
    private static function linearProject(array $ys, int $count): array
    {
        $n = count($ys);
        $sumX = 0; $sumY = 0; $sumXY = 0; $sumXX = 0;
        foreach ($ys as $x => $y) {
            $sumX += $x; $sumY += $y; $sumXY += $x * $y; $sumXX += $x * $x;
        }
        $denom = ($n * $sumXX - $sumX * $sumX);
        $slope = $denom != 0 ? ($n * $sumXY - $sumX * $sumY) / $denom : 0;
        $intercept = ($sumY - $slope * $sumX) / $n;

        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $x = $n + $i;
            $out[] = $intercept + $slope * $x;
        }
        return $out;
    }
}
