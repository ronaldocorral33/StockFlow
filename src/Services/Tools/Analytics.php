<?php
namespace App\Services\Tools;

if (!defined('APP_BOOTSTRAP')) { http_response_code(403); exit('Forbidden'); }

use App\Database;
use App\Services\Agent\DateResolver;
use App\Services\Agent\SchemaSemantics;

/**
 * Herramientas analíticas deterministas.
 *
 * QUÉ DECIDE EL MODELO Y QUÉ DECIDE PHP
 * El modelo elige QUÉ quiere saber: una dimensión de un catálogo cerrado, un periodo,
 * unos filtros estructurados. PHP decide CÓMO se calcula: la expresión SQL, el GROUP BY,
 * el conteo, el orden y el límite.
 *
 * Ese reparto es lo que arregla el bug reportado. Antes el modelo escribía el SQL
 * completo, así que cada pregunta era una nueva oportunidad de inventar una columna. Con
 * un catálogo cerrado, inventar es imposible: una dimensión que no existe se rechaza
 * antes de tocar la base.
 *
 * NADA DE FRAGMENTOS SQL DEL MODELO
 * Los filtros llegan como datos ({campo, operador, valor}), nunca como texto SQL. El
 * campo se resuelve contra el catálogo, el operador contra una allowlist, y el valor va
 * SIEMPRE como parámetro enlazado. No hay concatenación de entrada del modelo.
 *
 * SOLO LECTURA POR CREDENCIAL
 * Todo corre sobre Database::chatbotReadOnly(), un usuario con GRANT SELECT y nada más.
 * Aunque alguien introdujera un bug aquí, la base rechazaría cualquier escritura.
 */
class Analytics
{
    /** Tope de filas devueltas en un ranking. Protege el prompt y el costo. */
    public const MAX_ROWS = 25;
    private const DEFAULT_ROWS = 10;

    /** Operadores permitidos y su plantilla SQL. El modelo solo puede usar estas claves. */
    private const OPERATORS = [
        'eq' => '= ?',
        'ne' => '<> ?',
        'contains' => 'LIKE ?',
        'gt' => '> ?',
        'lt' => '< ?',
        'gte' => '>= ?',
        'lte' => '<= ?',
    ];

    // ---------------------------------------------------------------
    // ranking_ventas
    // ---------------------------------------------------------------

    /**
     * Ranking de unidades vendidas por una dimensión cualquiera del negocio.
     *
     * Sirve igual para "producto más vendido", "talla más vendida" o
     * "marca más vendida": lo único que cambia es la dimensión, que viene del catálogo.
     * Por eso NO existen funciones por concepto — una sola cubre todas, presentes y
     * futuras, sin código nuevo.
     */
    public static function rankingVentas(ToolContext $ctx, array $args): array
    {
        $bid = $ctx->businessId;

        $dim = self::resolveDimension($bid, $args['dimension'] ?? null);
        if (isset($dim['error'])) {
            return $dim;
        }

        // FRENO CONTRA DATOS FABRICADOS
        // Si la dimensión está prácticamente vacía, agrupar produciría un único grupo
        // NULL que el modelo etiquetaría como si fuera una categoría real ("Sin equipo:
        // 15 ventas"). Se corta aquí y se explica la causa verdadera.
        if (!$dim['usable']) {
            return [
                'encontrado' => false,
                'motivo' => sprintf(
                    'La dimensión "%s" no tiene datos en este negocio (%.0f%% de las piezas la traen vacía), '
                    . 'así que no se puede hacer un ranking con ella.',
                    $dim['label'],
                    (1 - $dim['fill_rate']) * 100
                ),
                'dimension' => $dim['key'],
                'dimensiones_con_datos' => self::usableLabels($bid),
            ];
        }

        $periodo = DateResolver::normalize(
            $args['periodo'] ?? null,
            $args['fecha_inicio'] ?? null,
            $args['fecha_fin'] ?? null,
            $ctx->today
        );
        if (!$periodo['ok']) {
            return ['encontrado' => false, 'motivo' => $periodo['error']];
        }

        $filtros = self::buildFilters($bid, $args['filtros'] ?? []);
        if (isset($filtros['error'])) {
            return ['encontrado' => false, 'motivo' => $filtros['error']];
        }

        $limite = self::clampLimit($args['limite'] ?? null);
        $expr = SchemaSemantics::sqlExpression($dim);

        $where = ['i.business_id = ?', 'i.sale_date IS NOT NULL'];
        $params = [$bid];
        self::applyPeriod($where, $params, 'i.sale_date', $periodo);
        foreach ($filtros['clauses'] as $c) { $where[] = $c; }
        foreach ($filtros['params'] as $p) { $params[] = $p; }

        // Excluir el grupo vacío del ranking: "sin dato" no es un valor de negocio y
        // ganaría el primer lugar en cuanto falten datos. Se cuenta aparte y se reporta.
        $sql = "SELECT $expr AS valor,
                       COUNT(*) AS unidades,
                       COALESCE(SUM(i.sale_price), 0) AS ingresos,
                       COALESCE(SUM(i.profit), 0) AS ganancia
                FROM inventory_items i
                LEFT JOIN suppliers s ON s.id = i.supplier_id
                LEFT JOIN purchase_orders po ON po.id = i.purchase_order_id
                WHERE " . implode(' AND ', $where) . "
                  AND $expr IS NOT NULL AND TRIM($expr) <> ''
                GROUP BY valor
                ORDER BY unidades DESC, ingresos DESC
                LIMIT $limite";

        $rows = self::run($sql, $params);
        if ($rows === null) {
            return ['encontrado' => false, 'motivo' => 'No se pudo consultar la base de datos.'];
        }

        if (!$rows) {
            return [
                'encontrado' => false,
                'motivo' => 'No hay ventas registradas ' . $periodo['etiqueta']
                    . ($filtros['descripcion'] ? ' con los filtros aplicados (' . $filtros['descripcion'] . ')' : '')
                    . '.',
                'dimension' => $dim['key'],
                'periodo' => self::periodOut($periodo),
            ];
        }

        $resultados = array_map(fn($r) => [
            'valor' => $r['valor'],
            'unidades' => (int)$r['unidades'],
            'ingresos' => (float)$r['ingresos'],
            'ganancia' => (float)$r['ganancia'],
        ], $rows);

        return [
            'encontrado' => true,
            'dimension' => $dim['key'],
            'label' => $dim['label'],
            'periodo' => self::periodOut($periodo),
            'filtros_aplicados' => $filtros['descripcion'] ?: null,
            'top' => $resultados[0],
            'resultados' => $resultados,
        ];
    }

    // ---------------------------------------------------------------
    // resumen_ventas
    // ---------------------------------------------------------------

    /** Cifras globales de un periodo: unidades, ingresos, ganancia, margen y ticket. */
    public static function resumenVentas(ToolContext $ctx, array $args): array
    {
        $bid = $ctx->businessId;

        $periodo = DateResolver::normalize(
            $args['periodo'] ?? null,
            $args['fecha_inicio'] ?? null,
            $args['fecha_fin'] ?? null,
            $ctx->today
        );
        if (!$periodo['ok']) {
            return ['encontrado' => false, 'motivo' => $periodo['error']];
        }

        $filtros = self::buildFilters($bid, $args['filtros'] ?? []);
        if (isset($filtros['error'])) {
            return ['encontrado' => false, 'motivo' => $filtros['error']];
        }

        $r = self::salesAggregate($bid, $periodo, $filtros);
        if ($r === null) {
            return ['encontrado' => false, 'motivo' => 'No se pudo consultar la base de datos.'];
        }

        if ($r['unidades'] === 0) {
            return [
                'encontrado' => false,
                'motivo' => 'No hay ventas ' . $periodo['etiqueta']
                    . ($filtros['descripcion'] ? ' con los filtros aplicados' : '') . '.',
                'periodo' => self::periodOut($periodo),
            ];
        }

        return [
            'encontrado' => true,
            'periodo' => self::periodOut($periodo),
            'filtros_aplicados' => $filtros['descripcion'] ?: null,
            'unidades_vendidas' => $r['unidades'],
            'ingresos' => $r['ingresos'],
            'ganancia' => $r['ganancia'],
            // El margen se calcula sobre ingresos: el % de cada peso vendido que quedó.
            'margen_pct' => $r['ingresos'] > 0 ? round($r['ganancia'] / $r['ingresos'] * 100, 1) : null,
            'ticket_promedio' => round($r['ingresos'] / $r['unidades'], 2),
        ];
    }

    // ---------------------------------------------------------------
    // comparar_periodos
    // ---------------------------------------------------------------

    /**
     * Compara dos periodos. Existe como herramienta propia porque el cálculo de la
     * variación porcentual es justo donde un modelo se equivoca por descuido, y porque
     * la división por cero (un periodo sin ventas) necesita un criterio explícito.
     */
    public static function compararPeriodos(ToolContext $ctx, array $args): array
    {
        $bid = $ctx->businessId;

        $a = DateResolver::normalize(
            $args['periodo_a'] ?? null,
            $args['fecha_inicio_a'] ?? null,
            $args['fecha_fin_a'] ?? null,
            $ctx->today
        );
        $b = DateResolver::normalize(
            $args['periodo_b'] ?? null,
            $args['fecha_inicio_b'] ?? null,
            $args['fecha_fin_b'] ?? null,
            $ctx->today
        );
        foreach ([$a, $b] as $p) {
            if (!$p['ok']) {
                return ['encontrado' => false, 'motivo' => $p['error']];
            }
        }
        if ($a['inicio'] === null || $b['inicio'] === null) {
            return ['encontrado' => false, 'motivo' => 'Para comparar hace falta delimitar los dos periodos.'];
        }

        $filtros = self::buildFilters($bid, $args['filtros'] ?? []);
        if (isset($filtros['error'])) {
            return ['encontrado' => false, 'motivo' => $filtros['error']];
        }

        $ra = self::salesAggregate($bid, $a, $filtros);
        $rb = self::salesAggregate($bid, $b, $filtros);
        if ($ra === null || $rb === null) {
            return ['encontrado' => false, 'motivo' => 'No se pudo consultar la base de datos.'];
        }

        return [
            'encontrado' => true,
            'periodo_a' => self::periodOut($a) + $ra,
            'periodo_b' => self::periodOut($b) + $rb,
            'variacion' => [
                'unidades' => self::delta($ra['unidades'], $rb['unidades']),
                'ingresos' => self::delta($ra['ingresos'], $rb['ingresos']),
                'ganancia' => self::delta($ra['ganancia'], $rb['ganancia']),
            ],
            'filtros_aplicados' => $filtros['descripcion'] ?: null,
        ];
    }

    // ---------------------------------------------------------------
    // consultar_stock
    // ---------------------------------------------------------------

    /** Piezas en stock, opcionalmente agrupadas por una dimensión. Cubre
     *  "¿qué productos no tienen stock?" y "¿de qué tengo más?". */
    public static function consultarStock(ToolContext $ctx, array $args): array
    {
        $bid = $ctx->businessId;

        $filtros = self::buildFilters($bid, $args['filtros'] ?? []);
        if (isset($filtros['error'])) {
            return ['encontrado' => false, 'motivo' => $filtros['error']];
        }

        $where = ['i.business_id = ?', 'i.sale_date IS NULL'];
        $params = [$bid];
        foreach ($filtros['clauses'] as $c) { $where[] = $c; }
        foreach ($filtros['params'] as $p) { $params[] = $p; }
        $whereSql = implode(' AND ', $where);

        $dimKey = $args['dimension'] ?? null;
        if ($dimKey === null || $dimKey === '') {
            $rows = self::run(
                "SELECT COUNT(*) AS piezas, COALESCE(SUM(i.total_cost),0) AS invertido
                 FROM inventory_items i LEFT JOIN suppliers s ON s.id = i.supplier_id
                LEFT JOIN purchase_orders po ON po.id = i.purchase_order_id
                 WHERE $whereSql",
                $params
            );
            if ($rows === null) {
                return ['encontrado' => false, 'motivo' => 'No se pudo consultar la base de datos.'];
            }
            $piezas = (int)$rows[0]['piezas'];
            return $piezas === 0
                ? ['encontrado' => false, 'motivo' => 'No hay piezas en stock con esos criterios.']
                : ['encontrado' => true, 'piezas_en_stock' => $piezas, 'invertido' => (float)$rows[0]['invertido']];
        }

        $dim = self::resolveDimension($bid, $dimKey);
        if (isset($dim['error'])) {
            return $dim;
        }
        if (!$dim['usable']) {
            return [
                'encontrado' => false,
                'motivo' => sprintf('La dimensión "%s" no tiene datos en este negocio.', $dim['label']),
                'dimensiones_con_datos' => self::usableLabels($bid),
            ];
        }

        $expr = SchemaSemantics::sqlExpression($dim);
        $orden = ($args['orden'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
        $limite = self::clampLimit($args['limite'] ?? null);

        $rows = self::run(
            "SELECT $expr AS valor, COUNT(*) AS piezas, COALESCE(SUM(i.total_cost),0) AS invertido
             FROM inventory_items i LEFT JOIN suppliers s ON s.id = i.supplier_id
                LEFT JOIN purchase_orders po ON po.id = i.purchase_order_id
             WHERE $whereSql AND $expr IS NOT NULL AND TRIM($expr) <> ''
             GROUP BY valor ORDER BY piezas $orden LIMIT $limite",
            $params
        );
        if ($rows === null) {
            return ['encontrado' => false, 'motivo' => 'No se pudo consultar la base de datos.'];
        }
        if (!$rows) {
            return ['encontrado' => false, 'motivo' => 'No hay piezas en stock con esos criterios.'];
        }

        return [
            'encontrado' => true,
            'dimension' => $dim['key'],
            'label' => $dim['label'],
            'resultados' => array_map(fn($r) => [
                'valor' => $r['valor'],
                'piezas' => (int)$r['piezas'],
                'invertido' => (float)$r['invertido'],
            ], $rows),
        ];
    }

    /**
     * Valores de una dimensión que se vendieron pero YA NO tienen stock.
     * Responde "¿qué productos se me agotaron?", que no es lo mismo que
     * "¿qué tengo en stock?" y se calcula distinto.
     */
    public static function sinStock(ToolContext $ctx, array $args): array
    {
        $bid = $ctx->businessId;
        $dim = self::resolveDimension($bid, $args['dimension'] ?? 'name');
        if (isset($dim['error'])) {
            return $dim;
        }
        if (!$dim['usable']) {
            return [
                'encontrado' => false,
                'motivo' => sprintf('La dimensión "%s" no tiene datos en este negocio.', $dim['label']),
                'dimensiones_con_datos' => self::usableLabels($bid),
            ];
        }

        $expr = SchemaSemantics::sqlExpression($dim);
        $limite = self::clampLimit($args['limite'] ?? null);

        $rows = self::run(
            "SELECT $expr AS valor,
                    COUNT(*) AS historico,
                    SUM(i.sale_date IS NULL) AS en_stock
             FROM inventory_items i LEFT JOIN suppliers s ON s.id = i.supplier_id
                LEFT JOIN purchase_orders po ON po.id = i.purchase_order_id
             WHERE i.business_id = ? AND $expr IS NOT NULL AND TRIM($expr) <> ''
             GROUP BY valor
             HAVING en_stock = 0
             ORDER BY historico DESC
             LIMIT $limite",
            [$bid]
        );
        if ($rows === null) {
            return ['encontrado' => false, 'motivo' => 'No se pudo consultar la base de datos.'];
        }
        if (!$rows) {
            return [
                'encontrado' => false,
                'motivo' => sprintf('Todos los valores de "%s" tienen al menos una pieza en stock.', $dim['label']),
            ];
        }

        return [
            'encontrado' => true,
            'dimension' => $dim['key'],
            'label' => $dim['label'],
            'agotados' => array_map(fn($r) => [
                'valor' => $r['valor'],
                'vendidas_historico' => (int)$r['historico'],
            ], $rows),
        ];
    }

    // ---------------------------------------------------------------
    // disponibilidad
    // ---------------------------------------------------------------

    /**
     * Totales, vendidas y disponibles por dimensión, en UN solo resultado.
     *
     * POR QUÉ HACÍA FALTA
     * "¿Qué tallas repongo y cuáles tengo disponibles?" necesita las tres cifras a la
     * vez. Con las herramientas anteriores el modelo tenía que pedir el ranking de
     * ventas, pedir el stock, y COSER los dos resultados en el texto de su respuesta.
     * Eso reintroduce justo el problema que estas herramientas vinieron a resolver: el
     * modelo haciendo aritmética, y la tabla estructurada del frontend mostrando solo
     * la mitad de los datos que menciona la prosa.
     *
     * Al calcular las tres columnas en la misma consulta agrupada, además, quedan
     * garantizadas cuadrando entre sí: disponibles = totales − vendidas, siempre.
     */
    public static function disponibilidad(ToolContext $ctx, array $args): array
    {
        $bid = $ctx->businessId;

        $dim = self::resolveDimension($bid, $args['dimension'] ?? null);
        if (isset($dim['error'])) {
            return $dim;
        }
        if (!$dim['usable']) {
            return [
                'encontrado' => false,
                'motivo' => sprintf('La dimensión "%s" no tiene datos en este negocio.', $dim['label']),
                'dimensiones_con_datos' => self::usableLabels($bid),
            ];
        }

        $filtros = self::buildFilters($bid, $args['filtros'] ?? []);
        if (isset($filtros['error'])) {
            return ['encontrado' => false, 'motivo' => $filtros['error']];
        }

        $expr = SchemaSemantics::sqlExpression($dim);
        $where = ['i.business_id = ?'];
        $params = [$bid];
        foreach ($filtros['clauses'] as $c) { $where[] = $c; }
        foreach ($filtros['params'] as $p) { $params[] = $p; }

        // Sin filtro de fecha a propósito: la pregunta es sobre el estado ACTUAL del
        // inventario, no sobre un periodo. Meter un rango dejaría "totales" contando
        // solo lo comprado en ese lapso, y la resta ya no cuadraría con el stock real.
        $sql = "SELECT $expr AS valor,
                       COUNT(*) AS totales,
                       SUM(i.sale_date IS NOT NULL) AS vendidas,
                       SUM(i.sale_date IS NULL) AS disponibles,
                       COALESCE(SUM(CASE WHEN i.sale_date IS NULL THEN i.total_cost ELSE 0 END), 0) AS invertido_disponible,
                       COALESCE(SUM(CASE WHEN i.sale_date IS NOT NULL THEN i.sale_price ELSE 0 END), 0) AS ingresos
                FROM inventory_items i
                LEFT JOIN suppliers s ON s.id = i.supplier_id
                LEFT JOIN purchase_orders po ON po.id = i.purchase_order_id
                WHERE " . implode(' AND ', $where) . "
                  AND $expr IS NOT NULL AND TRIM($expr) <> ''
                GROUP BY valor
                ORDER BY disponibles ASC, vendidas DESC";

        $rows = self::run($sql, $params);
        if ($rows === null) {
            return ['encontrado' => false, 'motivo' => 'No se pudo consultar la base de datos.'];
        }
        if (!$rows) {
            return [
                'encontrado' => false,
                'motivo' => 'No hay piezas con esos criterios'
                    . ($filtros['descripcion'] ? ' (' . $filtros['descripcion'] . ')' : '') . '.',
                'dimension' => $dim['key'],
            ];
        }

        $resultados = [];
        $agotados = [];
        foreach ($rows as $r) {
            $fila = [
                'valor' => $r['valor'],
                'totales' => (int)$r['totales'],
                'vendidas' => (int)$r['vendidas'],
                'disponibles' => (int)$r['disponibles'],
                'invertido_disponible' => (float)$r['invertido_disponible'],
                'ingresos' => (float)$r['ingresos'],
            ];
            $resultados[] = $fila;
            if ($fila['disponibles'] === 0 && $fila['vendidas'] > 0) {
                $agotados[] = $fila['valor'];
            }
        }

        return [
            'encontrado' => true,
            'dimension' => $dim['key'],
            'label' => $dim['label'],
            'filtros_aplicados' => $filtros['descripcion'] ?: null,
            // Ordenado por disponibles ascendente: lo primero de la lista es lo que
            // hay que reponer. El orden ya ES la respuesta a "qué repongo".
            'resultados' => $resultados,
            'agotados' => $agotados,
            'nota' => 'disponibles = totales − vendidas, calculado sobre el inventario actual '
                . '(sin filtro de periodo).',
        ];
    }
    // ---------------------------------------------------------------
    // Apoyo
    // ---------------------------------------------------------------

    /** Resuelve y valida la dimensión contra el catálogo cerrado del negocio. */
    private static function resolveDimension(int $businessId, $key): array
    {
        if (!is_string($key) || $key === '') {
            return [
                'error' => true,
                'encontrado' => false,
                'motivo' => 'Falta el argumento "dimension". Opciones válidas: '
                    . implode(', ', SchemaSemantics::dimensionKeys($businessId, true)) . '.',
            ];
        }
        $dim = SchemaSemantics::dimension($businessId, $key);
        if ($dim === null) {
            // Aquí muere el bug de las claves inventadas: attributes.$.team no existe,
            // así que nunca llega al SQL.
            return [
                'error' => true,
                'encontrado' => false,
                'motivo' => sprintf(
                    'La dimensión "%s" no existe en este negocio. No inventes nombres de columnas. '
                    . 'Dimensiones válidas con datos: %s.',
                    $key,
                    implode(', ', self::usableLabels($businessId))
                ),
            ];
        }
        return $dim;
    }

    private static function usableLabels(int $businessId): array
    {
        $out = [];
        foreach (SchemaSemantics::forBusiness($businessId)['dimensions'] as $k => $d) {
            if ($d['usable']) {
                $out[] = $k . ' (' . $d['label'] . ')';
            }
        }
        return $out;
    }

    /**
     * Traduce filtros estructurados a cláusulas con parámetros enlazados.
     * El modelo nunca manda SQL: manda {campo, operador, valor} y aquí se valida todo
     * contra allowlists antes de construir nada.
     */
    private static function buildFilters(int $businessId, $filtros): array
    {
        if ($filtros === null || $filtros === '' || $filtros === []) {
            return ['clauses' => [], 'params' => [], 'descripcion' => ''];
        }
        if (!is_array($filtros)) {
            return ['error' => 'Los filtros deben ser una lista de objetos {campo, operador, valor}.'];
        }

        $clauses = [];
        $params = [];
        $desc = [];

        foreach ($filtros as $f) {
            if (!is_array($f) || !isset($f['campo'])) {
                return ['error' => 'Cada filtro necesita al menos "campo" y "valor".'];
            }
            $dim = SchemaSemantics::dimension($businessId, (string)$f['campo']);
            if ($dim === null) {
                return [
                    'error' => sprintf(
                        'No puedes filtrar por "%s": no es una dimensión de este negocio. Válidas: %s.',
                        $f['campo'],
                        implode(', ', SchemaSemantics::dimensionKeys($businessId, true))
                    ),
                ];
            }

            $op = (string)($f['operador'] ?? 'eq');
            if (!isset(self::OPERATORS[$op])) {
                return [
                    'error' => sprintf('Operador "%s" no permitido. Usa: %s.', $op, implode(', ', array_keys(self::OPERATORS))),
                ];
            }

            $valor = $f['valor'] ?? null;
            if ($valor === null || $valor === '') {
                return ['error' => sprintf('El filtro sobre "%s" no trae valor.', $f['campo'])];
            }
            if (is_array($valor) || is_object($valor)) {
                return ['error' => sprintf('El valor del filtro sobre "%s" debe ser un dato simple.', $f['campo'])];
            }

            $expr = SchemaSemantics::sqlExpression($dim);
            $clauses[] = $expr . ' ' . self::OPERATORS[$op];
            // El valor SIEMPRE va enlazado, jamás concatenado.
            $params[] = $op === 'contains' ? '%' . $valor . '%' : $valor;
            $desc[] = $dim['label'] . ' ' . $op . ' ' . $valor;
        }

        return ['clauses' => $clauses, 'params' => $params, 'descripcion' => implode('; ', $desc)];
    }

    private static function applyPeriod(array &$where, array &$params, string $col, array $periodo): void
    {
        if ($periodo['inicio'] === null) {
            return;
        }
        // Intervalo semiabierto [inicio, fin): no pierde el último día si la columna
        // llegara a traer hora.
        $where[] = "$col >= ? AND $col < ?";
        $params[] = $periodo['inicio'];
        $params[] = $periodo['fin'];
    }

    private static function salesAggregate(int $bid, array $periodo, array $filtros): ?array
    {
        $where = ['i.business_id = ?', 'i.sale_date IS NOT NULL'];
        $params = [$bid];
        self::applyPeriod($where, $params, 'i.sale_date', $periodo);
        foreach ($filtros['clauses'] as $c) { $where[] = $c; }
        foreach ($filtros['params'] as $p) { $params[] = $p; }

        $rows = self::run(
            'SELECT COUNT(*) AS unidades,
                    COALESCE(SUM(i.sale_price), 0) AS ingresos,
                    COALESCE(SUM(i.profit), 0) AS ganancia
             FROM inventory_items i LEFT JOIN suppliers s ON s.id = i.supplier_id
                LEFT JOIN purchase_orders po ON po.id = i.purchase_order_id
             WHERE ' . implode(' AND ', $where),
            $params
        );
        if ($rows === null) {
            return null;
        }
        return [
            'unidades' => (int)$rows[0]['unidades'],
            'ingresos' => (float)$rows[0]['ingresos'],
            'ganancia' => (float)$rows[0]['ganancia'],
        ];
    }

    /** Variación entre dos valores, con criterio explícito para el caso desde cero. */
    private static function delta(float $a, float $b): array
    {
        $abs = round($b - $a, 2);
        return [
            'a' => $a,
            'b' => $b,
            'absoluta' => $abs,
            // Sin base no hay porcentaje: devolver null es más honesto que "+100%"
            // o que una división por cero disfrazada.
            'pct' => $a > 0 ? round(($b - $a) / $a * 100, 1) : null,
            'nota' => $a == 0 && $b > 0 ? 'El primer periodo no tuvo ventas, así que no hay variación porcentual.' : null,
        ];
    }

    private static function periodOut(array $p): array
    {
        return ['inicio' => $p['inicio'], 'fin' => $p['fin'], 'etiqueta' => $p['etiqueta']];
    }

    private static function clampLimit($v): int
    {
        $n = is_numeric($v) ? (int)$v : self::DEFAULT_ROWS;
        return max(1, min(self::MAX_ROWS, $n));
    }

    /** Ejecuta siempre con el usuario de solo lectura y con parámetros enlazados. */
    private static function run(string $sql, array $params): ?array
    {
        try {
            $stmt = Database::chatbotReadOnly()->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (\Throwable $e) {
            error_log('[analytics] ' . $e->getMessage());
            return null;
        }
    }
}
