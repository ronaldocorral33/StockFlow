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

    /** Tope de caracteres del resultado en el log: la traza sirve para depurar, no
     *  para archivar los datos del negocio. */
    private const LOG_RESULT_CHARS = 600;

    /**
     * Dónde se escribe la traza. null = error_log (el log de Apache en producción).
     * Se puede inyectar un callable para capturarla en pruebas: así la suite verifica
     * el FORMATO del log sin ensuciar su propia salida.
     */
    public static $logSink = null;

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
        $tools = ToolRegistry::definitions($ctx);
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
                self::logFinal($step, $state);
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

                $t0 = microtime(true);
                $result = ToolRegistry::run($call['name'], $call['args'], $ctx);
                $state['tools_used'][] = $call['name'];
                self::absorb($state, $call, $result);
                self::logStep($step, $call, $result, microtime(true) - $t0);

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

    /**
     * Traza de un paso del ciclo, para poder reconstruir en desarrollo POR QUÉ el agente
     * respondió lo que respondió.
     *
     * Sin esto, depurar una respuesta rara obliga a adivinar qué herramienta eligió el
     * modelo y con qué argumentos. Con esto queda escrito en el log de PHP.
     *
     * NO se registran secretos: aquí solo pasan nombres de herramienta, argumentos que
     * el modelo eligió de un catálogo cerrado, y resultados de negocio. Las credenciales
     * viven en Env/Llm y nunca llegan a este punto. Los resultados se recortan porque un
     * ranking completo llenaría el log sin aportar.
     */
    private static function emit(string $msg): void
    {
        if (self::$logSink !== null) {
            (self::$logSink)($msg);
            return;
        }
        error_log($msg);
    }

    private static function logStep(int $step, array $call, array $result, float $seconds): void
    {
        $args = json_encode($call['args'], JSON_UNESCAPED_UNICODE);
        $res = json_encode($result, JSON_UNESCAPED_UNICODE);
        if ($res !== false && strlen($res) > self::LOG_RESULT_CHARS) {
            $res = substr($res, 0, self::LOG_RESULT_CHARS) . '…(recortado)';
        }

        // Un resultado con 'encontrado' => false o 'ok' => false no es un error del
        // sistema: es el agente informando que no hay datos. Se marca distinto para
        // que al leer el log se distinga de una falla real.
        $veredicto = (array_key_exists('encontrado', $result) && $result['encontrado'] === false)
            || (array_key_exists('ok', $result) && $result['ok'] === false)
            ? 'SIN-DATOS'
            : 'ok';

        self::emit(sprintf(
            "[agente][PASO %d] herramienta=%s %s %dms\n  argumentos: %s\n  resultado: %s",
            $step, $call['name'], $veredicto, (int)round($seconds * 1000), $args, $res
        ));
    }

    private static function logFinal(int $step, array $state): void
    {
        self::emit(sprintf(
            '[agente][PASO %d] respuesta final tras %s: %s',
            $step,
            $state['tools_used'] ? implode(' + ', $state['tools_used']) : 'ninguna herramienta',
            mb_substr((string)$state['answer'], 0, 200)
        ));
    }
    /**
     * Extrae de cada resultado lo que la UI y la bitácora necesitan.
     *
     * Las herramientas devuelven DATOS con la forma que le conviene a cada análisis;
     * el frontend, en cambio, dibuja una sola cosa: $rows, una lista de objetos planos
     * (rowsTable() en chat.js). Aquí se hace esa traducción, en un solo lugar.
     *
     * Es a propósito que las herramientas NO conozcan el formato del frontend: si cada
     * una devolviera "filas para la tabla", el día que cambie la UI habría que tocarlas
     * todas. Separar DATOS de PRESENTACIÓN es justo lo que evita eso.
     */
    private static function absorb(array &$state, array $call, array $result): void
    {
        switch ($call['name']) {
            case 'consultar_inventario':
                $state['sql'] = $result['sql_ejecutado'] ?? ($call['args']['sql'] ?? null);
                $state['sql_valid'] = $result['ok'];
                $state['rejection_reason'] = $result['ok'] ? null : ($result['error'] ?? null);
                if (isset($result['total_filas'])) {
                    $state['row_count'] = $result['total_filas'];
                }
                if (!empty($result['filas'])) {
                    $state['rows'] = $result['filas'];
                }
                return;

            case 'proyectar_ventas':
                $state['is_projection'] = true;
                if (isset($result['proyeccion'])) {
                    $state['row_count'] = count($result['proyeccion']);
                }
                return;

            case 'ranking_ventas':
            case 'consultar_stock':
                // La etiqueta de la dimensión se usa como encabezado de la columna, para
                // que la tabla diga "Producto" o "Talla" y no "valor".
                if (!empty($result['resultados'])) {
                    $state['rows'] = self::labelRows($result['resultados'], $result['label'] ?? 'Valor');
                    $state['row_count'] = count($result['resultados']);
                }
                return;

            case 'productos_agotados':
                if (!empty($result['agotados'])) {
                    $state['rows'] = self::labelRows($result['agotados'], $result['label'] ?? 'Valor');
                    $state['row_count'] = count($result['agotados']);
                }
                return;

            case 'comparar_periodos':
                if (!empty($result['encontrado'])) {
                    $state['rows'] = [
                        self::periodRow($result['periodo_a'] ?? []),
                        self::periodRow($result['periodo_b'] ?? []),
                    ];
                    $state['row_count'] = 2;
                }
                return;

            case 'resumen_ventas':
                // Una sola cifra global no gana nada en una tabla: la respuesta en texto
                // del modelo la comunica mejor. No se llena $rows a propósito.
                if (isset($result['unidades_vendidas'])) {
                    $state['row_count'] = $result['unidades_vendidas'];
                }
                return;
        }
    }

    /** Renombra la columna genérica "valor" con la etiqueta real de la dimensión. */
    private static function labelRows(array $rows, string $label): array
    {
        return array_map(function (array $r) use ($label) {
            $out = [];
            foreach ($r as $k => $v) {
                $out[$k === 'valor' ? $label : $k] = $v;
            }
            return $out;
        }, $rows);
    }

    private static function periodRow(array $p): array
    {
        return [
            'Periodo' => $p['etiqueta'] ?? '—',
            'unidades' => $p['unidades'] ?? 0,
            'ingresos' => $p['ingresos'] ?? 0,
            'ganancia' => $p['ganancia'] ?? 0,
        ];
    }
}
