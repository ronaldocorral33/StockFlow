<?php
namespace App\Services;

if (!defined('APP_BOOTSTRAP')) { http_response_code(403); exit('Forbidden'); }

/**
 * Contrato estructurado de la decisión del asistente.
 *
 * ANTES: el modelo respondía texto libre y PHP adivinaba la intención comparando
 *        strings exactos ('NEEDS_PROJECTION') y aplicando un regex sobre la pregunta
 *        del usuario para reconstruir argumentos que el modelo ya había entendido.
 *
 * AHORA: el modelo responde un JSON que cumple un esquema declarado, y esta clase
 *        lo valida y normaliza antes de que nadie actúe sobre él.
 *
 * Regla que NO cambia: el proveedor garantiza la FORMA del JSON (modo estricto),
 * pero PHP valida el CONTENIDO de todos modos. Nunca confiamos en una garantía
 * externa para decidir qué código se ejecuta en nuestro servidor.
 */
class ChatDecision
{
    /** Consultar datos del negocio: el modelo propone un SELECT que SqlGuard validará. */
    public const ACTION_QUERY = 'consultar_datos';
    /** Proyectar ventas futuras: lo calcula TrendService en PHP, no el modelo. */
    public const ACTION_FORECAST = 'proyectar_ventas';
    /** Explicar qué es y qué puede hacer el asistente: respuesta fija, sin tocar la BD. */
    public const ACTION_EXPLAIN = 'explicar_sistema';

    public const ACTIONS = [self::ACTION_QUERY, self::ACTION_FORECAST, self::ACTION_EXPLAIN];

    public const MIN_MONTHS = 1;
    public const MAX_MONTHS = 3;

    /**
     * JSON Schema de la decisión.
     *
     * Detalle importante del modo estricto de OpenAI: TODAS las propiedades deben
     * aparecer en `required`. Un campo "opcional" no se omite de la lista — se declara
     * como nullable (`['string','null']`) y el modelo manda null cuando no aplica.
     */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['accion', 'sql', 'meses'],
            'properties' => [
                'accion' => [
                    'type' => 'string',
                    'enum' => self::ACTIONS,
                    'description' => 'Qué hacer con la pregunta del usuario.',
                ],
                'sql' => [
                    'type' => ['string', 'null'],
                    'description' => 'Solo cuando accion = consultar_datos: la sentencia SELECT. '
                        . 'En cualquier otro caso debe ser null.',
                ],
                'meses' => [
                    'type' => ['integer', 'null'],
                    'description' => 'Solo cuando accion = proyectar_ventas: cuántos meses proyectar, de '
                        . self::MIN_MONTHS . ' a ' . self::MAX_MONTHS . '. Si el usuario no lo especifica, usa 1. '
                        . 'En cualquier otro caso debe ser null.',
                ],
            ],
        ];
    }

    /**
     * Valida y normaliza la decisión cruda del modelo.
     *
     * @param array|null $raw JSON ya decodificado que devolvió el proveedor.
     * @return array{ok:bool, action?:string, sql?:string, months?:int, reason?:string}
     */
    public static function validate(?array $raw): array
    {
        if ($raw === null) {
            return self::reject('el modelo no devolvió una decisión legible');
        }

        $action = $raw['accion'] ?? null;
        if (!is_string($action) || !in_array($action, self::ACTIONS, true)) {
            return self::reject('el modelo devolvió una acción desconocida');
        }

        if ($action === self::ACTION_QUERY) {
            $sql = $raw['sql'] ?? null;
            if (!is_string($sql) || trim($sql) === '') {
                return self::reject('el modelo pidió consultar datos pero no incluyó la consulta');
            }
            // Ojo: aquí NO se valida que el SQL sea seguro. Eso sigue siendo trabajo
            // exclusivo de SqlGuard, que corre después y no cambió ni una línea.
            return ['ok' => true, 'action' => $action, 'sql' => trim($sql)];
        }

        if ($action === self::ACTION_FORECAST) {
            $months = $raw['meses'] ?? null;
            // Que falten los meses no es un error: 1 es el default razonable.
            $months = is_numeric($months) ? (int)$months : self::MIN_MONTHS;
            $months = max(self::MIN_MONTHS, min(self::MAX_MONTHS, $months));
            return ['ok' => true, 'action' => $action, 'months' => $months];
        }

        return ['ok' => true, 'action' => self::ACTION_EXPLAIN];
    }

    private static function reject(string $reason): array
    {
        return ['ok' => false, 'reason' => $reason];
    }
}
