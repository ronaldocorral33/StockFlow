<?php
namespace App\Services\Tools;

if (!defined('APP_BOOTSTRAP')) { http_response_code(403); exit('Forbidden'); }

use App\Database;
use App\Services\Authz;
use App\Services\ResultSummary;
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
    public static function definitions(): array
    {
        return [
            [
                'name' => 'consultar_inventario',
                'description' =>
                    'Consulta los datos reales del negocio (inventario, compras, proveedores) ejecutando '
                    . 'una sentencia SELECT de solo lectura. Úsala para cualquier pregunta sobre cuántas '
                    . 'piezas hay, cuánto se vendió, qué producto es más rentable, etc. '
                    . 'La consulta se valida antes de ejecutarse y el resultado se te devuelve.',
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
        ];
    }

    /** Permiso requerido por herramienta: [recurso, acción]. */
    private const PERMISSIONS = [
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
