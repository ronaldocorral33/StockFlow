<?php
namespace App\Services;

if (!defined('APP_BOOTSTRAP')) { http_response_code(403); exit('Forbidden'); }

use App\Models\AttributeDefinition;
use App\Models\InventoryItem;
use App\Models\PurchaseOrder;

/** Mapeo de columnas de Excel <-> filas de inventory_items, tolerante a los encabezados del app original. */
class ImportExportService
{
    private const FIXED_ALIASES = [
        // 'id' es el camino de emparejamiento EXACTO en una re-importación: la
        // exportación ahora escribe esta columna justamente para poder volver.
        'id' => ['ID'],
        'order_number' => ['Pedido', 'No. de pedido', 'Número de pedido', 'Numero de pedido', 'Lote'],
        'name' => ['Nombre', 'Equipo', 'equipo', 'Producto'],
        'variant_label' => ['Jugador', 'Variante', 'Variación'],
        'category' => ['Categoría', 'Categoria', 'Deporte', 'deporte'],
        'subcategory' => ['Subcategoría', 'Subcategoria'],
        'supplier' => ['Proveedor'],
        'cost' => ['Costo (MXN)', 'Costo total (MXN)', 'Costo'],
        'shipping_cost' => ['Envío (MXN)', 'Envio (MXN)', 'Costo de envio'],
        'sale_price' => ['Venta (MXN)', 'Venta'],
        'purchase_date' => ['Fecha de compra'],
        'arrival_date' => ['Fecha de llegada', 'Fecha llegada'],
        'sale_date' => ['Fecha de Venta', 'Fecha de venta'],
    ];

    /** Filas aplanadas para exportar: columnas fijas legibles + una columna por atributo del negocio. */
    public static function exportRows(int $businessId): array
    {
        $attrDefs = AttributeDefinition::listForBusiness($businessId);
        $items = InventoryItem::list($businessId, ['sort' => 'created_at', 'dir' => 'asc']);

        return array_map(function ($item) use ($attrDefs) {
            $row = [
                'ID' => $item['id'],
                'Pedido' => $item['order_number'],
                'Proveedor' => $item['supplier_name'],
                'Fecha de compra' => $item['purchase_date'],
                'Fecha de llegada' => $item['arrival_date'],
                'Fecha de venta' => $item['sale_date'],
                'Nombre' => $item['name'],
                'Variante' => $item['variant_label'],
                'Categoría' => $item['category'],
                'Subcategoría' => $item['subcategory'],
            ];
            foreach ($attrDefs as $def) {
                $row[$def['label']] = $item['attributes'][$def['field_key']] ?? null;
            }
            $row['Costo (MXN)'] = $item['cost'];
            $row['Envío (MXN)'] = $item['shipping_cost'];
            $row['Costo total (MXN)'] = $item['total_cost'];
            $row['Venta (MXN)'] = $item['sale_price'];
            $row['Ganancia (MXN)'] = $item['profit'];
            return $row;
        }, $items);
    }

    /**
     * Mapea una fila cruda de Excel (claves = encabezados) a los datos que espera InventoryItem::create().
     * Prioridad: primero intenta hacer match con la ETIQUETA de un atributo definido por el usuario,
     * y solo si no coincide con ninguno, cae a los alias de columnas fijas.
     */
    public static function mapImportRow(array $rawRow, array $attrDefs): ?array
    {
        $normalized = [];
        foreach ($rawRow as $key => $value) {
            $normalized[self::normalizeHeader($key)] = $value;
        }

        $attributes = [];
        $consumedHeaders = [];
        foreach ($attrDefs as $def) {
            $h = self::normalizeHeader($def['label']);
            if (array_key_exists($h, $normalized) && $normalized[$h] !== '' && $normalized[$h] !== null) {
                $attributes[$def['field_key']] = (string)$normalized[$h];
                $consumedHeaders[$h] = true;
            }
        }

        $data = ['attributes' => $attributes];
        foreach (self::FIXED_ALIASES as $field => $aliases) {
            foreach ($aliases as $alias) {
                $h = self::normalizeHeader($alias);
                if (isset($consumedHeaders[$h])) {
                    continue;
                }
                if (array_key_exists($h, $normalized) && $normalized[$h] !== '' && $normalized[$h] !== null) {
                    $data[$field] = self::coerce($field, $normalized[$h]);
                    break;
                }
            }
        }

        if (empty($data['name'])) {
            return null;
        }
        return $data;
    }

    /** Solo inserta. Es el comportamiento histórico: rápido, y el que duplica si te equivocas. */
    public const MODE_ADD = 'add';
    /** Empareja contra lo que ya existe: actualiza lo conocido, inserta solo lo nuevo. */
    public const MODE_SYNC = 'sync';

    /**
     * Huella estable de una pieza: lo que la identifica y NO cambia cuando la vendes.
     *
     * Deliberadamente EXCLUYE sale_price y sale_date, porque esos son justo los campos
     * que una re-importación viene a actualizar. Si formaran parte de la identidad,
     * vender una pieza la volvería "otra pieza" y el importador la duplicaría — que es
     * exactamente el error que hay que evitar.
     *
     * Los atributos personalizados SÍ entran: sin la talla, dos jerseys del mismo equipo
     * y mismo costo serían indistinguibles y la venta podría asignarse a la pieza
     * equivocada. El costo de incluirlos: si editas un atributo en el Excel, esa fila
     * parece nueva. Para eso está la columna ID de la exportación, que empareja exacto.
     */
    public static function matchKey(array $data): string
    {
        $attrs = $data['attributes'] ?? [];
        if (!is_array($attrs)) { $attrs = []; }
        ksort($attrs);
        $parts = [
            self::norm($data['name'] ?? ''),
            self::norm($data['variant_label'] ?? ''),
            self::norm($data['category'] ?? ''),
            self::norm($data['subcategory'] ?? ''),
            self::norm($data['supplier'] ?? ''),
            self::money($data['cost'] ?? null),
            self::money($data['shipping_cost'] ?? null, true),
            self::norm($data['purchase_date'] ?? ''),
            self::norm($data['arrival_date'] ?? ''),
        ];
        foreach ($attrs as $k => $v) {
            if (self::norm($v) === '') { continue; }
            $parts[] = $k . '=' . self::norm($v);
        }
        return implode('|', $parts);
    }

    /** Misma huella, pero calculada desde una fila ya guardada en la base. */
    public static function matchKeyFromRow(array $row): string
    {
        $attrs = $row['attributes'] ?? [];
        if ($attrs instanceof \stdClass) { $attrs = (array)$attrs; }
        return self::matchKey([
            'name' => $row['name'], 'variant_label' => $row['variant_label'],
            'category' => $row['category'], 'subcategory' => $row['subcategory'],
            'supplier' => $row['supplier_name'] ?? null,
            'cost' => $row['cost'], 'shipping_cost' => $row['shipping_cost'],
            'purchase_date' => $row['purchase_date'], 'arrival_date' => $row['arrival_date'],
            'attributes' => is_array($attrs) ? $attrs : [],
        ]);
    }

    private static function norm($v): string
    {
        return trim(mb_strtolower((string)($v ?? '')));
    }

    /** Normaliza dinero para comparar: "1,200.00" y 1200 son el mismo valor. */
    private static function money($v, bool $vacioEsCero = false): string
    {
        if ($v === null || $v === '') { return $vacioEsCero ? '0' : ''; }
        $n = preg_replace('/[^0-9.\-]/', '', (string)$v);
        if ($n === '') { return $vacioEsCero ? '0' : ''; }
        return (string)(float)$n;
    }

    /**
     * Importa filas de Excel en uno de dos modos.
     *
     * MODE_ADD  — inserta todo. Útil cuando sabes que el archivo trae solo cosas nuevas.
     * MODE_SYNC — empareja cada fila con una pieza existente (por columna ID si viene,
     *             si no por huella estable) y la ACTUALIZA; solo inserta lo que no
     *             encontró. Nunca borra nada.
     *
     * Con $dryRun = true no escribe nada: solo cuenta qué pasaría. Existe porque una
     * importación equivocada es cara de deshacer, y ver "738 actualizadas, 0 nuevas"
     * antes de confirmar es la diferencia entre corregir el inventario y duplicarlo.
     *
     * Todo corre dentro de una transacción: si algo truena a la mitad, no queda medio
     * inventario importado.
     */
    public static function importRows(
        int $businessId,
        int $actorUserId,
        array $rawRows,
        string $mode = self::MODE_ADD,
        bool $dryRun = false
    ): array {
        $mode = $mode === self::MODE_SYNC ? self::MODE_SYNC : self::MODE_ADD;
        $attrDefs = AttributeDefinition::listForBusiness($businessId);

        // Índices de lo que ya existe. Se arman una sola vez: emparejar 700 filas con
        // una consulta por fila serían 700 viajes a la base.
        $porHuella = [];
        $porId = [];
        if ($mode === self::MODE_SYNC) {
            foreach (InventoryItem::list($businessId) as $row) {
                $porId[(int)$row['id']] = $row;
                $porHuella[self::matchKeyFromRow($row)][] = (int)$row['id'];
            }
        }

        $r = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'errors' => []];
        $pedidos = [];

        $pdo = \App\Database::connection();
        $pdo->beginTransaction();
        try {
            foreach ($rawRows as $i => $rawRow) {
                $data = self::mapImportRow($rawRow, $attrDefs);
                if ($data === null) {
                    $r['skipped']++;
                    continue;
                }

                // El Excel trae el pedido como NÚMERO; la base lo guarda como id interno.
                $numeroPedido = $data['order_number'] ?? null;
                unset($data['order_number']);
                if ($numeroPedido !== null && $numeroPedido !== '') {
                    $poId = PurchaseOrder::resolveOrCreate($businessId, $actorUserId, $numeroPedido, $data);
                    if ($poId) {
                        $data['purchase_order_id'] = $poId;
                        $pedidos[$poId] = true;
                    }
                }

                try {
                    $idExistente = $mode === self::MODE_SYNC
                        ? self::buscarExistente($data, $porId, $porHuella)
                        : null;

                    if ($idExistente === null) {
                        if (!$dryRun) { InventoryItem::create($businessId, $actorUserId, $data); }
                        $r['inserted']++;
                    } elseif (self::sinCambios($porId[$idExistente], $data)) {
                        $r['unchanged']++;
                    } else {
                        if (!$dryRun) { InventoryItem::update($idExistente, $businessId, $actorUserId, $data); }
                        $r['updated']++;
                    }
                } catch (\Throwable $e) {
                    $r['errors'][] = ['row' => $i + 1, 'reason' => $e->getMessage()];
                }
            }

            if ($dryRun) {
                // El ensayo escribió pedidos para poder emparejar; deshacerlo es el punto.
                $pdo->rollBack();
            } else {
                foreach (array_keys($pedidos) as $poId) {
                    PurchaseOrder::refreshItemCount($businessId, (int)$poId);
                }
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }

        return $r + [
            'mode' => $mode,
            'dry_run' => $dryRun,
            'orders' => count($pedidos),
            'total' => count($rawRows),
        ];
    }

    /**
     * Encuentra la pieza que corresponde a esta fila, y la CONSUME del índice.
     *
     * Consumirla es lo que hace correcto el caso de piezas gemelas: si tienes 8 jerseys
     * idénticos del mismo pedido, las 8 filas del Excel emparejan con las 8 de la base,
     * una a una, en vez de que las 8 apunten a la misma pieza.
     */
    private static function buscarExistente(array $data, array &$porId, array &$porHuella): ?int
    {
        // Camino exacto: la columna ID que ahora escribe la exportación.
        if (!empty($data['id'])) {
            $id = (int)$data['id'];
            if (isset($porId[$id])) {
                foreach ($porHuella as $h => $ids) {
                    $pos = array_search($id, $ids, true);
                    if ($pos !== false) {
                        unset($porHuella[$h][$pos]);
                        break;
                    }
                }
                return $id;
            }
        }

        $huella = self::matchKey($data);
        if (empty($porHuella[$huella])) {
            return null;
        }
        return (int)array_shift($porHuella[$huella]);
    }

    /** ¿La fila del Excel trae algo distinto a lo que ya está guardado? */
    private static function sinCambios(array $existente, array $data): bool
    {
        foreach (['sale_price', 'cost', 'shipping_cost'] as $c) {
            if (array_key_exists($c, $data) && self::money($data[$c]) !== self::money($existente[$c])) {
                return false;
            }
        }
        foreach (['name', 'variant_label', 'category', 'subcategory', 'sale_date', 'purchase_date', 'arrival_date'] as $c) {
            if (array_key_exists($c, $data) && self::norm($data[$c]) !== self::norm($existente[$c])) {
                return false;
            }
        }
        if (array_key_exists('purchase_order_id', $data)
            && (int)$data['purchase_order_id'] !== (int)($existente['purchase_order_id'] ?? 0)) {
            return false;
        }
        $viejos = $existente['attributes'] instanceof \stdClass ? [] : (array)$existente['attributes'];
        foreach (($data['attributes'] ?? []) as $k => $v) {
            if (self::norm($v) !== self::norm($viejos[$k] ?? '')) {
                return false;
            }
        }
        return true;
    }
    private static function normalizeHeader(string $h): string
    {
        $h = trim(mb_strtolower($h));
        $map = ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n'];
        return strtr($h, $map);
    }

    private static function coerce(string $field, $value)
    {
        if (in_array($field, ['cost', 'shipping_cost', 'sale_price'], true)) {
            $num = preg_replace('/[^0-9.\-]/', '', (string)$value);
            return $num === '' ? null : (float)$num;
        }
        if (in_array($field, ['purchase_date', 'arrival_date', 'sale_date'], true)) {
            return self::dateOrNull($value);
        }
        return trim((string)$value);
    }

    private static function dateOrNull($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $s = trim((string)$value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $s)) {
            return substr($s, 0, 10);
        }
        $ts = strtotime($s);
        return $ts ? date('Y-m-d', $ts) : null;
    }
}
