<?php
namespace App\Services;

if (!defined('APP_BOOTSTRAP')) { http_response_code(403); exit('Forbidden'); }

/**
 * Prepara el resultado de una consulta para mandárselo al modelo.
 *
 * EL PROBLEMA: SqlGuard permite hasta 500 filas. Mandarlas crudas al modelo es caro
 * (cada fila son tokens) y además diluye su atención.
 *
 * LA TRAMPA: truncar a secas es PEOR que no truncar. Si el usuario pregunta
 * "¿cuánto vendí en total?" y el modelo solo ve 20 de 200 filas, va a sumar esas 20
 * y responder con seguridad una cifra equivocada. Truncar sin agregados convierte una
 * respuesta lenta pero correcta en una rápida pero falsa.
 *
 * LA SOLUCIÓN: PHP calcula los agregados sobre TODAS las filas y se los entrega junto
 * a una muestra. El modelo ve pocos datos pero totales exactos, y se le avisa
 * explícitamente que está viendo una muestra.
 *
 * Esto es coherente con el principio que ya sigue el proyecto: la aritmética la hace
 * PHP, nunca el modelo.
 */
class ResultSummary
{
    public static function build(array $rows, int $maxRows): array
    {
        $total = count($rows);

        if ($total === 0) {
            return ['total_filas' => 0, 'columnas' => [], 'filas' => []];
        }

        $columns = array_keys($rows[0]);
        $summary = [
            'total_filas' => $total,
            'columnas' => $columns,
            // Agregados calculados en PHP sobre TODAS las filas, no sobre la muestra.
            'totales_calculados' => self::aggregate($rows, $columns),
        ];

        if ($total <= $maxRows) {
            $summary['filas'] = $rows;
            return $summary;
        }

        $summary['filas_mostradas'] = $maxRows;
        $summary['nota'] = "IMPORTANTE: abajo solo van {$maxRows} filas de ejemplo de un total de {$total}. "
            . 'Para cualquier cifra global usa "totales_calculados", que ya está calculado sobre las '
            . 'filas completas. Nunca sumes las filas de ejemplo para dar un total.';
        $summary['filas'] = array_slice($rows, 0, $maxRows);

        return $summary;
    }

    /** Suma/mín/máx/promedio de cada columna numérica. Las no numéricas se omiten. */
    private static function aggregate(array $rows, array $columns): array
    {
        $out = [];
        foreach ($columns as $col) {
            $values = [];
            foreach ($rows as $row) {
                $v = $row[$col] ?? null;
                if ($v !== null && $v !== '' && is_numeric($v)) {
                    $values[] = (float)$v;
                }
            }
            // Solo se agrega si TODOS los valores presentes eran numéricos: si la columna
            // mezcla texto y números, sumarla no significaría nada.
            $present = 0;
            foreach ($rows as $row) {
                if (($row[$col] ?? null) !== null && ($row[$col] ?? '') !== '') {
                    $present++;
                }
            }
            if (!$values || count($values) !== $present) {
                continue;
            }
            $out[$col] = [
                'suma' => round(array_sum($values), 2),
                'minimo' => round(min($values), 2),
                'maximo' => round(max($values), 2),
                'promedio' => round(array_sum($values) / count($values), 2),
            ];
        }
        return $out;
    }
}
