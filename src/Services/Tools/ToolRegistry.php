<?php
namespace App\Services\Tools;

if (!defined('APP_BOOTSTRAP')) { http_response_code(403); exit('Forbidden'); }

use App\Database;
use App\Services\Authz;
use App\Services\ResultSummary;
use App\Services\Agent\SchemaSemantics;
use App\Services\Agent\DateResolver;
use App\Services\SqlGuard;
use App\Services\TrendService;

/**
 * Catálogo de herramientas que el modelo puede pedir, y el único punto donde se ejecutan.
 *
 * El modelo NO ejecuta nada. Solo emite "quiero usar la herramienta X con estos
 * argumentos". Esta clase es la que decide si esa petición es legítima y, en su caso,
 * corre el código PHP correspondiente.
 *
 * Cada herramienta declara:
 *   - nombre y descripción  → lo que el modelo lee para decidir si le sirve
 *   - parameters (JSON Schema) → la forma exacta de los argumentos
 *   - permiso requerido      → se verifica contra el rol del usuario ANTES de ejecutar
 *   - ejecutor PHP           → el trabajo real
 */
class ToolRegistry
{
    /** Tablas que el SQL generado puede tocar. Igual que antes: lista blanca cerrada. */
    private const ALLOWED_TABLES = ['inventory_items', 'purchase_orders', 'suppliers'];

    private const MAX_ROWS_TO_MODEL = 20;
    private const MIN_MONTHS = 1;
    private const MAX_MONTHS = 6;

    /**
     * Definiciones que se le mandan al proveedor. Formato neutro; cada cliente
     * (Azure/Anthropic) lo traduce a su propio dialecto.
     */
    public static function definitions(?ToolContext $ctx = null): array
    {
        // Sin contexto no se puede construir el catálogo de dimensiones de ESTE negocio,
        // así que solo se ofrecen las herramientas que no dependen de él.
        $analiticas = $ctx !== null ? self::analyticsDefinitions($ctx) : [];

        return array_merge($analiticas, [
            [
                'name' => 'consultar_inventario',
                'description' =>
                    'HERRAMIENTA DE ÚLTIMO RECURSO. Antes de usarla, revisa si ranking_ventas, '
                    . 'resumen_ventas, comparar_periodos o consultar_stock resuelven la pregunta: '
                    . 'ésas calculan los indicadores de forma consistente y no dependen de que '
                    . 'aciertes con el SQL. Usa esta solo para consultas que ninguna cubre '
                    . '(cruces poco comunes, cálculos a la medida). Ejecuta un SELECT de solo '
                    . 'lectura, validado antes de correr.',
                'parameters' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['sql'],
                    'properties' => [
                        'sql' => [
                            'type' => 'string',
                            'description' =>
                                'Una única sentencia SELECT para MariaDB. Tablas y columnas disponibles: '
                                . 'inventory_items(id, business_id, user_id, purchase_order_id, supplier_id, name, '
                                . 'variant_label, category, subcategory, attributes JSON, cost, shipping_cost, '
                                . 'sale_price, purchase_date, arrival_date, sale_date, total_cost, profit, created_at); '
                                . 'purchase_orders(id, business_id, user_id, order_number, supplier_id, purchase_date, '
                                . 'arrival_date, currency, exchange_rate, shipping_total_mxn, item_count, notes, created_at); '
                                . 'suppliers(id, business_id, user_id, name, notes, created_at). '
                                . 'OBLIGATORIO: filtra por business_id usando el token literal {{BUSINESS_ID}} '
                                . '(ej. "WHERE inventory_items.business_id = {{BUSINESS_ID}}"). Nunca pongas un número. '
                                . 'sale_date IS NULL = en stock; sale_date IS NOT NULL = vendida. '
                                . 'Prohibido: INSERT/UPDATE/DELETE/DDL, punto y coma, comentarios y UNION.',
                        ],
                    ],
                ],
            ],
            [
                'name' => 'proyectar_ventas',
                'description' =>
                    'Calcula una proyección de ventas para los próximos meses a partir del historial real '
                    . 'del negocio. El cálculo lo hace el sistema (regresión lineal), NO tú: nunca estimes '
                    . 'cifras futuras por tu cuenta, usa esta herramienta.',
                'parameters' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['meses'],
                    'properties' => [
                        'meses' => [
                            'type' => 'integer',
                            'description' => 'Cuántos meses proyectar hacia adelante, de '
                                . self::MIN_MONTHS . ' a ' . self::MAX_MONTHS . '. Si el usuario no lo dice, usa 1.',
                        ],
                    ],
                ],
            ],
        ]);
    }

    /**
     * Definiciones de las herramientas analíticas, construidas PARA ESTE NEGOCIO.
     *
     * La pieza importante es el enum de dimensiones: se arma en tiempo de ejecución
     * desde SchemaSemantics, así que el modelo recibe la lista real de lo que este
     * negocio puede analizar — con las etiquetas que su dueño eligió — y no puede
     * proponer nada fuera de ella. Ahí muere el bug de attributes.$.team.
     *
     * Un negocio de refacciones que configure "marca" verá "marca" en el enum sin que
     * nadie escriba código: por eso no existen funciones por concepto.
     */
    private static function analyticsDefinitions(ToolContext $ctx): array
    {
        $bid = $ctx->businessId;
        $claves = SchemaSemantics::dimensionKeys($bid, true);
        if (!$claves) {
            // Un negocio sin datos aún: no se ofrecen herramientas que no podrían
            // responder nada. Mejor que ofrecerlas y devolver vacíos inexplicables.
            return [];
        }

        $catalogo = SchemaSemantics::forBusiness($bid)['dimensions'];
        $glosario = [];
        foreach ($claves as $k) {
            $d = $catalogo[$k];
            $ej = $d['examples'] ? ' (ej. ' . implode(', ', array_slice($d['examples'], 0, 3)) . ')' : '';
            $glosario[] = $k . ' = ' . $d['label'] . $ej;
        }
        $glosarioTxt = implode('; ', $glosario);

        $dimensionSchema = [
            'type' => 'string',
            'enum' => $claves,
            'description' => 'Por qué agrupar. Elige según el SIGNIFICADO, no según la palabra '
                . 'que usó el usuario: ' . $glosarioTxt . '.',
        ];

        $periodoSchema = [
            'type' => 'string',
            'enum' => DateResolver::PERIODS,
            'description' => 'Periodo relativo. Úsalo para "este mes", "últimos 30 días", etc.: '
                . 'el sistema calcula las fechas. Para un mes o rango concreto usa '
                . 'fecha_inicio y fecha_fin en su lugar.',
        ];
        $fechaSchema = [
            'type' => 'string',
            'description' => 'Fecha en formato YYYY-MM-DD. El fin es EXCLUSIVO: para agosto de 2026 '
                . 'usa fecha_inicio 2026-08-01 y fecha_fin 2026-09-01.',
        ];
        $filtrosSchema = [
            'type' => 'array',
            'description' => 'Filtros opcionales. Son datos, no SQL. El campo debe ser una de las '
                . 'dimensiones válidas: ' . implode(', ', $claves) . '.',
            'items' => [
                'type' => 'object',
                'required' => ['campo', 'valor'],
                'properties' => [
                    'campo' => ['type' => 'string', 'enum' => $claves],
                    'operador' => [
                        'type' => 'string',
                        'enum' => ['eq', 'ne', 'contains', 'gt', 'lt', 'gte', 'lte'],
                        'description' => 'Por omisión "eq" (igual).',
                    ],
                    'valor' => ['type' => 'string'],
                ],
            ],
        ];

        return [
            [
                'name' => 'ranking_ventas',
                'description' =>
                    'PREFERIDA para cualquier pregunta de "qué se vendió más/menos". Agrupa las '
                    . 'unidades vendidas por la dimensión que elijas y las ordena. Sirve para '
                    . 'producto, variante, proveedor o cualquier atributo de este negocio: '
                    . $glosarioTxt . '. Devuelve unidades, ingresos y ganancia por valor.',
                'parameters' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['dimension'],
                    'properties' => [
                        'dimension' => $dimensionSchema,
                        'periodo' => $periodoSchema,
                        'fecha_inicio' => $fechaSchema,
                        'fecha_fin' => $fechaSchema,
                        'filtros' => $filtrosSchema,
                        'limite' => [
                            'type' => 'integer',
                            'description' => 'Cuántos valores devolver (1-' . Analytics::MAX_ROWS . '). '
                                . 'Para "el más vendido" usa 1; para un top usa 5 o 10.',
                        ],
                    ],
                ],
            ],
            [
                'name' => 'resumen_ventas',
                'description' =>
                    'Cifras globales de un periodo: unidades vendidas, ingresos, ganancia, margen '
                    . 'y ticket promedio. Úsala para "cuánto vendí" o "cómo me fue". Con filtros '
                    . 'responde "cuánto vendí de X".',
                'parameters' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'periodo' => $periodoSchema,
                        'fecha_inicio' => $fechaSchema,
                        'fecha_fin' => $fechaSchema,
                        'filtros' => $filtrosSchema,
                    ],
                ],
            ],
            [
                'name' => 'comparar_periodos',
                'description' =>
                    'Compara dos periodos y devuelve la variación absoluta y porcentual. Úsala para '
                    . '"compara julio y agosto" o "cómo va contra el mes pasado". El sistema calcula '
                    . 'la variación: no la estimes tú.',
                'parameters' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'periodo_a' => $periodoSchema,
                        'fecha_inicio_a' => $fechaSchema,
                        'fecha_fin_a' => $fechaSchema,
                        'periodo_b' => $periodoSchema,
                        'fecha_inicio_b' => $fechaSchema,
                        'fecha_fin_b' => $fechaSchema,
                        'filtros' => $filtrosSchema,
                    ],
                ],
            ],
            [
                'name' => 'consultar_stock',
                'description' =>
                    'Piezas que AÚN NO se han vendido. Sin dimensión devuelve el total y lo '
                    . 'invertido; con dimensión, el desglose. Usa orden "asc" para "de qué tengo '
                    . 'menos" y "desc" para "de qué tengo más".',
                'parameters' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'dimension' => $dimensionSchema,
                        'filtros' => $filtrosSchema,
                        'orden' => ['type' => 'string', 'enum' => ['asc', 'desc']],
                        'limite' => ['type' => 'integer'],
                    ],
                ],
            ],
            [
                'name' => 'disponibilidad',
                'description' =>
                    'PREFERIDA para "qué repongo", "qué me queda" o cualquier pregunta que '
                    . 'necesite comparar lo vendido contra lo disponible. Devuelve, por cada '
                    . 'valor de la dimensión, las piezas TOTALES, las VENDIDAS y las '
                    . 'DISPONIBLES en una sola consulta, ordenadas de menos a más disponibles. '
                    . 'No pidas el ranking y el stock por separado para restarlos tú: usa esta.',
                'parameters' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['dimension'],
                    'properties' => [
                        'dimension' => $dimensionSchema,
                        'filtros' => $filtrosSchema,
                    ],
                ],
            ],
            [
                'name' => 'productos_agotados',
                'description' =>
                    'Valores de una dimensión que se vendieron completos y ya NO tienen existencias. '
                    . 'Úsala para "qué se me agotó" o "qué productos no tengo en stock". Es distinto '
                    . 'de consultar_stock, que muestra lo que sí tienes.',
                'parameters' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'dimension' => $dimensionSchema,
                        'limite' => ['type' => 'integer'],
                    ],
                ],
            ],
        ];
    }
    /** Permiso requerido por herramienta: [recurso, acción]. */
    private const PERMISSIONS = [
        'ranking_ventas' => ['reports', 'view'],
        'resumen_ventas' => ['reports', 'view'],
        'comparar_periodos' => ['reports', 'view'],
        'consultar_stock' => ['inventory_items', 'view'],
        'disponibilidad' => ['inventory_items', 'view'],
        'productos_agotados' => ['inventory_items', 'view'],
        'consultar_inventario' => ['inventory_items', 'view'],
        'proyectar_ventas' => ['reports', 'view'],
    ];

    /**
     * Ejecuta una herramienta pedida por el modelo.
     *
     * Nunca lanza excepciones hacia arriba: un fallo se devuelve como resultado con
     * 'error', porque ese texto vuelve al modelo para que reaccione (pida otra cosa,
     * corrija la consulta, o se lo explique al usuario).
     *
     * @return array{ok:bool, ...} estructura que se le devuelve al modelo como tool_result
     */
    public static function run(string $name, array $args, ToolContext $ctx): array
    {
        if (!isset(self::PERMISSIONS[$name])) {
            return ['ok' => false, 'error' => "La herramienta '{$name}' no existe."];
        }

        [$resource, $action] = self::PERMISSIONS[$name];
        if (!Authz::can($action, $resource)) {
            return ['ok' => false, 'error' => 'No tienes permiso para usar esta herramienta.'];
        }

        try {
            return match ($name) {
                'ranking_ventas' => Analytics::rankingVentas($ctx, $args),
                'resumen_ventas' => Analytics::resumenVentas($ctx, $args),
                'comparar_periodos' => Analytics::compararPeriodos($ctx, $args),
                'consultar_stock' => Analytics::consultarStock($ctx, $args),
                'disponibilidad' => Analytics::disponibilidad($ctx, $args),
                'productos_agotados' => Analytics::sinStock($ctx, $args),
                'consultar_inventario' => self::consultarInventario($args, $ctx),
                'proyectar_ventas' => self::proyectarVentas($args, $ctx),
            };
        } catch (\Throwable $e) {
            error_log("[tool] fallo inesperado en {$name}: " . $e->getMessage());
            return ['ok' => false, 'error' => 'La herramienta falló al ejecutarse.'];
        }
    }

    // ---------------------------------------------------------------
    // Herramientas de LECTURA. Ninguna modifica datos.
    // ---------------------------------------------------------------

    private static function consultarInventario(array $args, ToolContext $ctx): array
    {
        $sql = $args['sql'] ?? null;
        if (!is_string($sql) || trim($sql) === '') {
            return ['ok' => false, 'error' => 'Falta el argumento "sql".'];
        }

        // SqlGuard sigue siendo el único que autoriza la consulta. Tool calling
        // cambió CÓMO llega el SQL, no quién decide si puede ejecutarse.
        $validation = SqlGuard::validate($sql, self::ALLOWED_TABLES);
        if (!$validation['valid']) {
            // El motivo vuelve al modelo a propósito: puede corregir y reintentar.
            return ['ok' => false, 'error' => 'Consulta rechazada: ' . $validation['reason']];
        }

        // El negocio se inyecta AQUÍ, desde el contexto de sesión. Nunca desde $args.
        $scoped = SqlGuard::scopeToBusiness($validation['sql'], $ctx->businessId);
        $scoped = SqlGuard::scopeToUser($scoped, $ctx->userId);

        try {
            $rows = Database::chatbotReadOnly()->query($scoped)->fetchAll();
        } catch (\Throwable $e) {
            error_log('[tool] SQL falló: ' . $e->getMessage() . ' | ' . $scoped);
            return ['ok' => false, 'error' => 'La consulta no se pudo ejecutar en la base de datos.'];
        }

        return ['ok' => true, 'sql_ejecutado' => $scoped]
            + ResultSummary::build($rows, self::MAX_ROWS_TO_MODEL);
    }

    private static function proyectarVentas(array $args, ToolContext $ctx): array
    {
        $months = $args['meses'] ?? self::MIN_MONTHS;
        if (!is_numeric($months)) {
            return ['ok' => false, 'error' => 'El argumento "meses" debe ser un número.'];
        }
        $months = max(self::MIN_MONTHS, min(self::MAX_MONTHS, (int)$months));

        $trend = TrendService::projectNextMonths($ctx->businessId, $months);
        if ($trend['method'] === 'insufficient_data') {
            return [
                'ok' => true,
                'metodo' => 'insufficient_data',
                'nota' => 'Todavía no hay ventas registradas suficientes para proyectar.',
            ];
        }

        return [
            'ok' => true,
            'metodo' => $trend['method'],
            'historial_mensual' => $trend['history'],
            'proyeccion' => $trend['projections'],
            'nota' => 'Cifras calculadas por el sistema. Preséntalas como estimado, no como garantía.',
        ];
    }
}
