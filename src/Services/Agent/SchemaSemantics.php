<?php
namespace App\Services\Agent;

if (!defined('APP_BOOTSTRAP')) { http_response_code(403); exit('Forbidden'); }

use App\Database;
use App\Models\AttributeDefinition;

/**
 * Capa semántica: le explica al modelo QUÉ SIGNIFICAN los datos, no solo cómo se llaman.
 *
 * EL PROBLEMA QUE RESUELVE
 * Antes se le entregaba al modelo una lista plana de columnas: "name, variant_label,
 * category, attributes JSON...". Con eso, ante "¿cuál fue mi jersey más vendida?", el
 * modelo hizo lo razonable: vio una columna llamada "category" y filtró por
 * category='Jersey'. Está vacía en las 738 piezas. Y ante "¿el equipo más vendido?"
 * inventó attributes.$.team, obtuvo NULL, y su propio COALESCE etiquetó el hueco como
 * "Sin equipo": un dato fabricado que se lee como un hallazgo.
 *
 * LA SOLUCIÓN, Y POR QUÉ NO ES UNA LISTA DE SINÓNIMOS
 * El mecanismo central son los VALORES DE EJEMPLO tomados de los datos reales del
 * negocio. Cuando el modelo lee:
 *
 *     Producto (name) — ejemplos: Mexico, Cruz Azul, FC Barcelona, Chivas
 *
 * deduce por su cuenta que "equipo" es esa dimensión. Para una joyería leería
 * "Anillo, Collar, Pulsera" y deduciría que "joya" es esa dimensión. Una lista de
 * sinónimos escrita a mano solo cubre las verticales que alguien anticipó; los
 * ejemplos cubren las que no.
 *
 * Por eso aquí NO hay una sola palabra de un giro concreto: ni "jersey", ni "equipo",
 * ni "talla". Desde la fase 4 tampoco hay etiquetas: TODAS —incluidas las canónicas—
 * salen del registro del negocio, así que si el dueño renombró "Producto" a "Equipo",
 * el asistente dice "Equipo". Lo único que queda escrito aquí es qué SIGNIFICA cada
 * campo dentro del modelo de datos, que es plomería del esquema y no vocabulario.
 *
 * FRONTERA DE SEGURIDAD
 * Las dimensiones son un catálogo CERRADO. El modelo manda una clave; si no está en el
 * mapa, se rechaza. Nunca se interpola texto del modelo en el SQL: la expresión sale
 * siempre de este archivo.
 */
class SchemaSemantics
{
    /** Cuántos valores de ejemplo se muestran por dimensión. Compacto a propósito:
     *  el prompt cuesta dinero y más ejemplos no enseñan más. */
    private const EXAMPLES_PER_DIMENSION = 4;

    /** Debajo de esta proporción de valores presentes, la dimensión se marca inservible.
     *  Es el freno que evita reportar un grupo que en realidad es "columna vacía". */
    public const MIN_FILL_RATE = 0.10;

    /**
     * Descripción de cada dimensión canónica.
     *
     * Ya NO contiene etiquetas: la etiqueta la pone el negocio en el registro
     * (attribute_definitions.label), y si el dueño renombró "Producto" a "Equipo", el
     * asistente debe decir "Equipo".
     *
     * Lo que queda aquí es lo que NO es vocabulario del negocio: qué significa el campo
     * dentro del modelo de datos. Eso es plomería del esquema y es igual para todos.
     */
    private const CANONICAL_HINTS = [
        'name' => 'Identificador principal del producto. Es la dimensión por defecto '
            . 'cuando la pregunta es sobre "qué se vendió más" sin más detalle.',
        'variant_label' => 'Distingue dos piezas del mismo producto (edición, personalización).',
        'category' => 'Agrupación amplia, opcional. Muchos negocios no la usan.',
        'subcategory' => 'Subdivisión de la categoría, opcional.',
        'supplier' => 'De quién se compró la pieza.',
        'order_number' => 'Pedido de compra al que pertenece la pieza.',
    ];
    /**
     * Sinónimos TRANSVERSALES a cualquier vertical. No es vocabulario de un giro:
     * "producto" o "vendido" significan lo mismo en jerseys, joyería o refacciones.
     * El vocabulario específico del negocio NO vive aquí — llega por los ejemplos.
     */
    private const GENERIC_SYNONYMS = [
        'name' => ['producto', 'artículo', 'articulo', 'pieza', 'modelo', 'item'],
        'variant_label' => ['variante', 'variación', 'variacion', 'versión personalizada'],
        'category' => ['categoría', 'categoria', 'tipo', 'línea', 'linea'],
        'subcategory' => ['subcategoría', 'subcategoria'],
        'supplier' => ['proveedor', 'vendedor', 'origen', 'fabricante'],
    ];

    /** Memoria por request: el tool loop puede dar varios pasos y no vamos a
     *  reconsultar el catálogo en cada uno. */
    private static array $cache = [];

    /** Descarta la memoria. Solo para pruebas, que cambian la configuración en vivo. */
    public static function flush(?int $businessId = null): void
    {
        if ($businessId === null) {
            self::$cache = [];
        } else {
            unset(self::$cache[$businessId]);
        }
    }

    /**
     * Catálogo semántico completo del negocio.
     *
     * @return array{dimensions:array<string,array>, date_fields:array, totals:array}
     */
    public static function forBusiness(int $businessId): array
    {
        if (isset(self::$cache[$businessId])) {
            return self::$cache[$businessId];
        }

        // TODAS las dimensiones salen del REGISTRO del negocio: canónicas y
        // personalizadas por igual. Antes las canónicas venían de una constante con
        // etiquetas fijas, así que un negocio no podía llamarle "Equipo" a su producto
        // y el asistente seguía diciendo "Producto".
        //
        // Se respeta analytics_enabled: un campo que el dueño marcó como no analizable
        // no se le ofrece al modelo, aunque exista y tenga datos.
        $dimensions = [];
        foreach (AttributeDefinition::listRegistry($businessId) as $def) {
            if (empty($def['analytics_enabled'])) {
                continue;
            }
            $key = (string)$def['field_key'];
            $esColumna = $def['storage'] === AttributeDefinition::STORAGE_COLUMN;

            // Los campos calculados y las fechas no son dimensiones para agrupar:
            // agrupar por "Ganancia" o por cada fecha distinta no responde nada útil.
            if ($esColumna && !isset(self::CANONICAL_HINTS[$key])) {
                continue;
            }

            $dimensions[$key] = [
                'key' => $key,
                'label' => (string)$def['label'],
                'source' => $esColumna ? 'column' : 'attributes',
                'description' => $esColumna
                    ? self::CANONICAL_HINTS[$key]
                    : 'Campo propio de este negocio.',
                // Los sinónimos genéricos siguen valiendo para las canónicas; la
                // etiqueta del negocio se suma como sinónimo de sí misma.
                'synonyms' => array_values(array_unique(array_merge(
                    self::GENERIC_SYNONYMS[$key] ?? [],
                    [mb_strtolower((string)$def['label'])]
                ))),
                'is_principal' => !empty($def['is_principal']),
            ];
            if ($esColumna) {
                $dimensions[$key]['column'] = AttributeDefinition::sqlExpressionFor($def);
            } else {
                $dimensions[$key]['json_key'] = $key;
            }
        }
        $stats = self::collectStats($businessId, $dimensions);
        foreach ($dimensions as $key => $_) {
            $dimensions[$key]['examples'] = $stats[$key]['examples'] ?? [];
            $dimensions[$key]['fill_rate'] = $stats[$key]['fill_rate'] ?? 0.0;
            $dimensions[$key]['distinct'] = $stats[$key]['distinct'] ?? 0;
            $dimensions[$key]['usable'] = ($stats[$key]['fill_rate'] ?? 0.0) >= self::MIN_FILL_RATE;
        }

        $catalog = [
            'dimensions' => $dimensions,
            'date_fields' => [
                'sale_date' => 'Fecha de venta. NULL = la pieza sigue en stock.',
                'purchase_date' => 'Fecha en que se compró al proveedor.',
                'arrival_date' => 'Fecha en que llegó la pieza.',
            ],
            'totals' => self::totals($businessId),
        ];

        self::$cache[$businessId] = $catalog;
        return $catalog;
    }

    /** Una dimensión por su clave, o null si no existe en este negocio. */
    public static function dimension(int $businessId, string $key): ?array
    {
        return self::forBusiness($businessId)['dimensions'][$key] ?? null;
    }

    /** Claves válidas. Es el enum que se le ofrece al modelo en el JSON Schema. */
    public static function dimensionKeys(int $businessId, bool $onlyUsable = false): array
    {
        $dims = self::forBusiness($businessId)['dimensions'];
        if ($onlyUsable) {
            $dims = array_filter($dims, fn($d) => $d['usable']);
        }
        return array_keys($dims);
    }

    /**
     * Expresión SQL de una dimensión. ESTE es el punto donde se cierra la puerta:
     * la expresión se construye desde el catálogo, jamás desde el argumento del modelo.
     * La clave ya viene validada contra el catálogo por quien llama.
     */
    public static function sqlExpression(array $dimension): string
    {
        if ($dimension['source'] === 'column') {
            return $dimension['column'];
        }
        // json_key viene de attribute_definitions (no del modelo), pero se sanea igual:
        // defensa en profundidad, por si algún día alguien alimenta esa tabla desde fuera.
        $key = preg_replace('/[^a-zA-Z0-9_]/', '', $dimension['json_key']);
        return "JSON_UNQUOTE(JSON_EXTRACT(i.attributes, '$.\"{$key}\"'))";
    }

    /**
     * Bloque compacto para el prompt. Es lo que en la práctica enseña la semántica:
     * etiqueta + qué es + cómo se ven los valores reales.
     */
    public static function promptBlock(int $businessId): string
    {
        $c = self::forBusiness($businessId);
        $lines = [];
        $lines[] = 'DIMENSIONES DISPONIBLES EN ESTE NEGOCIO (usa la clave exacta, no inventes otras):';

        foreach ($c['dimensions'] as $key => $d) {
            if (!$d['usable']) {
                continue;
            }
            $ej = $d['examples'] ? ' — ejemplos: ' . implode(', ', $d['examples']) : '';
            // Marcar el campo principal le dice al modelo cuál usar cuando la pregunta
            // es "qué se vendió más" sin decir por qué agrupar.
            $marca = !empty($d['is_principal']) ? ' [PRINCIPAL: identifica al producto]' : '';
            $lines[] = sprintf('- %s → "%s"%s (%d valores distintos)%s', $key, $d['label'], $marca, $d['distinct'], $ej);
        }

        // Decir explícitamente qué NO sirve es tan útil como decir qué sirve: evita que
        // el modelo agrupe por una columna vacía y presente el hueco como un grupo.
        $vacias = array_filter($c['dimensions'], fn($d) => !$d['usable']);
        if ($vacias) {
            $etiquetas = array_map(fn($d) => $d['label'] . ' (' . $d['key'] . ')', $vacias);
            $lines[] = 'SIN DATOS en este negocio, no las uses ni filtres por ellas: '
                . implode(', ', $etiquetas) . '.';
        }

        $t = $c['totals'];
        $lines[] = sprintf(
            'Contexto: %d piezas en total, %d vendidas, %d en stock. Una fila = UNA pieza física, '
            . 'así que las unidades vendidas se cuentan con COUNT(*): no existe columna de cantidad.',
            $t['pieces'], $t['sold'], $t['in_stock']
        );

        return implode("\n", $lines);
    }

    // ---------------------------------------------------------------
    // Consultas de apoyo
    // ---------------------------------------------------------------

    private static function totals(int $businessId): array
    {
        $stmt = Database::chatbotReadOnly()->prepare(
            'SELECT COUNT(*) AS pieces,
                    SUM(sale_date IS NOT NULL) AS sold,
                    SUM(sale_date IS NULL) AS in_stock
             FROM inventory_items WHERE business_id = ?'
        );
        $stmt->execute([$businessId]);
        $r = $stmt->fetch() ?: [];
        return [
            'pieces' => (int)($r['pieces'] ?? 0),
            'sold' => (int)($r['sold'] ?? 0),
            'in_stock' => (int)($r['in_stock'] ?? 0),
        ];
    }

    /**
     * Ejemplos, cardinalidad y proporción de valores presentes, por dimensión.
     *
     * Rendimiento: una consulta por dimensión (~10 en un negocio típico), y el
     * resultado se memoiza por request. Se hace en una sola pasada agrupada en vez de
     * traer filas a PHP, para que el costo no crezca con el tamaño del inventario.
     */
    private static function collectStats(int $businessId, array $dimensions): array
    {
        $pdo = Database::chatbotReadOnly();
        $out = [];

        foreach ($dimensions as $key => $d) {
            $expr = self::sqlExpression($d);
            // Se incluyen SIEMPRE los dos JOIN en vez de deducir cuál hace falta:
            // son LEFT JOIN sobre llaves foráneas indexadas, su costo es despreciable, y
            // decidirlo por dimensión ya produjo un fallo silencioso cuando order_number
            // pasó a ser dimensión y nadie recordó agregar su tabla.
            $joins = 'LEFT JOIN suppliers s ON s.id = i.supplier_id'
                . ' LEFT JOIN purchase_orders po ON po.id = i.purchase_order_id';

            $sql = "SELECT $expr AS val, COUNT(*) AS n
                    FROM inventory_items i $joins
                    WHERE i.business_id = ?
                    GROUP BY val
                    ORDER BY n DESC";
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$businessId]);
                $rows = $stmt->fetchAll();
            } catch (\Throwable $e) {
                // Una dimensión que no se puede consultar se marca inservible en lugar
                // de tumbar toda la capa semántica.
                error_log('[semantics] dimensión ilegible ' . $key . ': ' . $e->getMessage());
                $out[$key] = ['examples' => [], 'fill_rate' => 0.0, 'distinct' => 0];
                continue;
            }

            $total = 0;
            $presentes = 0;
            $distinct = 0;
            $examples = [];
            foreach ($rows as $r) {
                $n = (int)$r['n'];
                $total += $n;
                $val = $r['val'];
                if ($val === null || trim((string)$val) === '') {
                    continue;
                }
                $presentes += $n;
                $distinct++;
                if (count($examples) < self::EXAMPLES_PER_DIMENSION) {
                    $examples[] = (string)$val;
                }
            }

            $out[$key] = [
                'examples' => $examples,
                'fill_rate' => $total > 0 ? round($presentes / $total, 4) : 0.0,
                'distinct' => $distinct,
            ];
        }

        return $out;
    }
}
