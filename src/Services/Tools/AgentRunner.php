<?php
namespace App\Services\Tools;

if (!defined('APP_BOOTSTRAP')) { http_response_code(403); exit('Forbidden'); }

use App\Services\Llm;

/**
 * El loop del agente: la pieza que convierte "LLM con herramientas" en un agente real.
 *
 * DIFERENCIA CLAVE CON LO ANTERIOR:
 * Antes el número de llamadas al modelo era fijo y conocido de antemano (1 o 2), porque
 * lo decidía el código. Aquí lo decide el MODELO en tiempo de ejecución: puede pedir una
 * herramienta, ver el resultado, decidir que necesita otra, y otra, hasta darse por
 * satisfecho. Esa incertidumbre es exactamente lo que define a un agente — y es también
 * lo que obliga a ponerle límites.
 *
 * Por eso todo loop de agente necesita frenos:
 *   1. Tope de pasos           → nunca se cicla indefinidamente
 *   2. Detección de repetición → si repite la misma llamada, no está avanzando
 *   3. Herramientas inválidas  → se reportan al modelo, no revientan el proceso
 *   4. Tope de costo           → cada paso es dinero real
 */
class AgentRunner
{
    /** Máximo de rondas modelo→herramienta→modelo antes de cortar. */
    public const MAX_STEPS = 5;

    /**
     * @return array{
     *   answer:?string, steps:int, tools_used:array, stop_reason:string,
     *   sql:?string, sql_valid:?bool, rejection_reason:?string,
     *   rows:array, row_count:?int, is_projection:bool
     * }
     */
    /**
     * @param callable|null $llm Costura de inyección para pruebas: recibe
     *        (messages, systemPrompt, tools, maxTokens) y devuelve el turno normalizado.
     *        En producción se deja en null y usa el proveedor real.
     *        Sin esta costura, los frenos del loop solo se podrían probar gastando dinero
     *        real y dependiendo de que el modelo se porte mal a propósito.
     */
    public static function run(
        string $question,
        ToolContext $ctx,
        string $systemPrompt,
        int $maxTokens = 1200,
        ?callable $llm = null,
        array $history = []
    ): array {
        $llm ??= fn(array $m, string $s, array $t, int $mt) => Llm::sendWithTools($m, $s, $t, $mt);
        $tools = ToolRegistry::definitions();
        // El historial previo va ANTES de la pregunta actual, para que el modelo
        // entienda referencias como "¿y en junio?" o "muéstrame más de esos".
        $messages = array_merge($history, [['role' => 'user', 'content' => $question]]);

        $state = [
            'answer' => null,
            'steps' => 0,
            'tools_used' => [],
            'stop_reason' => 'completado',
            'sql' => null,
            'sql_valid' => null,
            'rejection_reason' => null,
            'rows' => [],
            'row_count' => null,
            'is_projection' => false,
        ];

        $seenCalls = [];

        for ($step = 1; $step <= self::MAX_STEPS; $step++) {
            $state['steps'] = $step;

            $turn = $llm($messages, $systemPrompt, $tools, $maxTokens);

            // Sin herramientas pedidas = el modelo terminó y esta es su respuesta.
            if (empty($turn['tool_calls'])) {
                $state['answer'] = $turn['text'];
                return $state;
            }

            // Freno 2: si repite exactamente la misma llamada que ya hizo, está atorado.
            // Sin esto, un modelo confundido puede consultar lo mismo hasta agotar el tope.
            $results = [];
            foreach ($turn['tool_calls'] as $call) {
                $fingerprint = $call['name'] . ':' . json_encode($call['args']);

                if (isset($seenCalls[$fingerprint])) {
                    $results[] = [
                        'id' => $call['id'],
                        'content' => json_encode([
                            'ok' => false,
                            'error' => 'Ya ejecutaste esta misma consulta y obtuviste el mismo resultado. '
                                . 'No la repitas: responde con lo que ya tienes o prueba algo distinto.',
                        ], JSON_UNESCAPED_UNICODE),
                    ];
                    continue;
                }
                $seenCalls[$fingerprint] = true;

                $result = ToolRegistry::run($call['name'], $call['args'], $ctx);
                $state['tools_used'][] = $call['name'];
                self::absorb($state, $call, $result);

                $results[] = [
                    'id' => $call['id'],
                    'content' => json_encode($result, JSON_UNESCAPED_UNICODE),
                ];
            }

            // El resultado vuelve al modelo para que decida el siguiente paso.
            $messages[] = $turn['assistant_message'];
            foreach (Llm::toolResultMessages($results) as $m) {
                $messages[] = $m;
            }
        }

        // Freno 1: se agotó el tope de pasos. Falla controlada, no un cuelgue.
        $state['stop_reason'] = 'limite_de_pasos';
        $state['answer'] = 'Esta pregunta requiere más pasos de los que puedo dar de una vez. '
            . 'Intenta dividirla en preguntas más específicas.';
        return $state;
    }

    /** Extrae de cada resultado lo que la UI y la bitácora necesitan. */
    private static function absorb(array &$state, array $call, array $result): void
    {
        if ($call['name'] === 'consultar_inventario') {
            $state['sql'] = $result['sql_ejecutado'] ?? ($call['args']['sql'] ?? null);
            $state['sql_valid'] = $result['ok'];
            $state['rejection_reason'] = $result['ok'] ? null : ($result['error'] ?? null);
            if (isset($result['total_filas'])) {
                $state['row_count'] = $result['total_filas'];
            }
            if (!empty($result['filas'])) {
                $state['rows'] = $result['filas'];
            }
        } elseif ($call['name'] === 'proyectar_ventas') {
            $state['is_projection'] = true;
            if (isset($result['proyeccion'])) {
                $state['row_count'] = count($result['proyeccion']);
            }
        }
    }
}
