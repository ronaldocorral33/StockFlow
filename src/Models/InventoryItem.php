<?php
namespace App\Models;

if (!defined('APP_BOOTSTRAP')) { http_response_code(403); exit('Forbidden'); }

use App\Database;

class InventoryItem
{
    private const SORTABLE = [
        'id', 'name', 'category', 'subcategory', 'cost', 'shipping_cost', 'sale_price',
        'total_cost', 'profit', 'purchase_date', 'arrival_date', 'sale_date', 'created_at', 'order_number',
    ];

    public static function list(int $businessId, array $filters = []): array
    {
        $sql = 'SELECT i.*, s.name AS supplier_name, po.order_number
                FROM inventory_items i
                LEFT JOIN suppliers s ON s.id = i.supplier_id
                LEFT JOIN purchase_orders po ON po.id = i.purchase_order_id
                WHERE i.business_id = ?';
        $params = [$businessId];

        if (!empty($filters['q'])) {
            $sql .= ' AND (i.name LIKE ? OR i.variant_label LIKE ? OR i.category LIKE ? OR i.subcategory LIKE ? OR i.attributes LIKE ?)';
            $like = '%' . $filters['q'] . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }
        if (!empty($filters['status']) && $filters['status'] === 'stock') {
            $sql .= ' AND i.sale_date IS NULL';
        } elseif (!empty($filters['status']) && $filters['status'] === 'sold') {
            $sql .= ' AND i.sale_date IS NOT NULL';
        }
        if (!empty($filters['category'])) {
            $sql .= ' AND i.category = ?';
            $params[] = $filters['category'];
        }
        // Filtros por cualquier campo FILTRABLE del registro, incluidos los atributos
        // personalizados. Es el consumidor que le faltaba a la bandera `filterable`.
        //
        // Sin esto no había forma de aislar, por ejemplo, "las piezas del America con
        // la temporada equivocada": había que reconocerlas a ojo entre 51 filas, con
        // la columna de temporada oculta.
        //
        // La clave y la expresión SQL salen del REGISTRO, nunca de la petición: un
        // campo que no exista o que no sea filtrable se ignora, así que ni un cliente
        // manipulado puede filtrar por una columna arbitraria.
        if (!empty($filters['attrs']) && is_array($filters['attrs'])) {
            $registro = [];
            foreach (AttributeDefinition::listRegistry($businessId) as $def) {
                $registro[$def['field_key']] = $def;
            }
            foreach ($filters['attrs'] as $key => $val) {
                if ($val === '' || $val === null || !isset($registro[$key])) {
                    continue;
                }
                $def = $registro[$key];
                if (!$def['filterable']) {
                    continue;
                }
                $expr = AttributeDefinition::sqlExpressionFor($def);
                if ($expr === null) {
                    continue;
                }
                // Los alias i/s/po de la expresión coinciden con los JOIN de arriba.
                $sql .= " AND $expr = ?";
                $params[] = (string)$val;
            }
        }

        if (!empty($filters['purchase_order_id'])) {
            $sql .= ' AND i.purchase_order_id = ?';
            $params[] = (int)$filters['purchase_order_id'];
        }

        // El pedido es la secuencia natural del inventario: el más reciente va primero.
        // created_at deja de ser fiable después de importar un Excel, porque cientos de
        // piezas se insertan en el mismo segundo y su orden queda indeterminado.
        $sortCol = in_array($filters['sort'] ?? '', self::SORTABLE, true) ? $filters['sort'] : 'order_number';
        $dir = (($filters['dir'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';
        if ($sortCol === 'order_number') {
            // Los artículos sin pedido se conservan visibles, pero después de los que
            // sí pertenecen a un lote. El id mantiene estable el orden dentro del pedido.
            $sql .= " ORDER BY (po.order_number IS NULL) ASC, po.order_number $dir, i.id ASC";
        } else {
            $sql .= " ORDER BY i.$sortCol $dir";
        }

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return array_map([self::class, 'decorate'], $stmt->fetchAll());
    }

    public static function find(int $id, int $businessId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT i.*, s.name AS supplier_name, po.order_number
             FROM inventory_items i
             LEFT JOIN suppliers s ON s.id = i.supplier_id
             LEFT JOIN purchase_orders po ON po.id = i.purchase_order_id
             WHERE i.id = ? AND i.business_id = ?'
        );
        $stmt->execute([$id, $businessId]);
        $row = $stmt->fetch();
        return $row ? self::decorate($row) : null;
    }

    private static function decorate(array $row): array
    {
        $row['attributes'] = $row['attributes'] ? json_decode($row['attributes'], true) : new \stdClass();
        return $row;
    }

    public static function create(int $businessId, int $actorUserId, array $data): int
    {
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('El producto necesita un nombre.');
        }
        $supplierId = Supplier::resolveOrCreate($businessId, $actorUserId, $data['supplier'] ?? null);
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO inventory_items
             (business_id, user_id, purchase_order_id, supplier_id, name, variant_label, category, subcategory, attributes,
              cost, shipping_cost, sale_price, purchase_date, arrival_date, sale_date)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $businessId, $actorUserId, $data['purchase_order_id'] ?? null, $supplierId, $name,
            $data['variant_label'] ?? null, $data['category'] ?? null, $data['subcategory'] ?? null,
            !empty($data['attributes']) ? json_encode($data['attributes']) : null,
            self::numOrNull($data['cost'] ?? null), self::numOrNull($data['shipping_cost'] ?? null) ?? 0,
            self::numOrNull($data['sale_price'] ?? null),
            ($data['purchase_date'] ?? null) ?: null, ($data['arrival_date'] ?? null) ?: null, ($data['sale_date'] ?? null) ?: null,
        ]);
        return (int)$pdo->lastInsertId();
    }

    /** Actualiza campos fijos y hace MERGE (no overwrite) del JSON de atributos. */
    public static function update(int $id, int $businessId, int $actorUserId, array $data): void
    {
        $existing = self::find($id, $businessId);
        if (!$existing) {
            throw new \InvalidArgumentException('Producto no encontrado.');
        }

        $fields = [];
        $params = [];
        $map = [
            'name' => 'string', 'variant_label' => 'string', 'category' => 'string', 'subcategory' => 'string',
            'cost' => 'num', 'shipping_cost' => 'num', 'sale_price' => 'num',
            'purchase_date' => 'date', 'arrival_date' => 'date', 'sale_date' => 'date',
        ];
        foreach ($map as $col => $type) {
            if (array_key_exists($col, $data)) {
                $fields[] = "$col = ?";
                $params[] = $type === 'num' ? self::numOrNull($data[$col]) : ($data[$col] ?: null);
            }
        }
        // El pedido se guarda como id interno, no como número: quien llama ya lo tradujo.
        if (array_key_exists('purchase_order_id', $data)) {
            $fields[] = 'purchase_order_id = ?';
            $params[] = $data['purchase_order_id'] ?: null;
        }
        if (array_key_exists('supplier', $data)) {
            $fields[] = 'supplier_id = ?';
            $params[] = Supplier::resolveOrCreate($businessId, $actorUserId, $data['supplier']);
        }
        if (!empty($data['attributes']) && is_array($data['attributes'])) {
            $merged = array_merge($existing['attributes'] instanceof \stdClass ? [] : $existing['attributes'], $data['attributes']);
            $fields[] = 'attributes = ?';
            $params[] = json_encode($merged);
        }
        if (!$fields) {
            return;
        }
        $params[] = $id;
        $params[] = $businessId;
        $sql = 'UPDATE inventory_items SET ' . implode(', ', $fields) . ' WHERE id = ? AND business_id = ?';
        Database::connection()->prepare($sql)->execute($params);
    }

    public static function delete(int $id, int $businessId): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM inventory_items WHERE id = ? AND business_id = ?');
        $stmt->execute([$id, $businessId]);
    }

    public static function sell(int $id, int $businessId, float $salePrice, string $saleDate): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE inventory_items SET sale_price = ?, sale_date = ? WHERE id = ? AND business_id = ?'
        );
        $stmt->execute([$salePrice, $saleDate, $id, $businessId]);
    }

    /** @param array $rows [{id:int, sale_price:float}] */
    public static function bulkSell(int $businessId, array $rows, string $saleDate): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('UPDATE inventory_items SET sale_price = ?, sale_date = ? WHERE id = ? AND business_id = ?');
        $count = 0;
        foreach ($rows as $row) {
            $price = self::numOrNull($row['sale_price'] ?? null);
            if ($price === null) {
                continue;
            }
            $stmt->execute([$price, $saleDate, (int)$row['id'], $businessId]);
            $count += $stmt->rowCount();
        }
        return $count;
    }

    public static function bulkArrival(int $businessId, array $ids, string $arrivalDate): int
    {
        if (empty($ids)) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_map('intval', $ids);
        array_unshift($params, $arrivalDate);
        $params[] = $businessId;
        $stmt = Database::connection()->prepare(
            "UPDATE inventory_items SET arrival_date = ? WHERE id IN ($placeholders) AND business_id = ?"
        );
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Aplica los MISMOS cambios a varias piezas de golpe.
     *
     * EL CASO REAL QUE RESUELVE
     * Llega un pedido de 116 jerseys y se capturan sin especificar la versión. Hoy la
     * única salida es abrir 116 veces el formulario de edición. Con esto se seleccionan
     * y se corrigen en un clic.
     *
     * TRES DECISIONES QUE EVITAN PERDER DATOS
     *
     * 1. Solo se toca lo que viene en $data. Un campo ausente NO se pone en blanco.
     *    Sin esta regla, editar la versión de 116 piezas les borraría el costo.
     *
     * 2. Los atributos personalizados se MEZCLAN, no se reemplazan. Se usa JSON_SET,
     *    que conserva las demás claves: poner la versión no debe borrar la talla ni la
     *    liga. Escribir el JSON completo habría sido más simple y habría destruido
     *    datos silenciosamente.
     *
     * 3. Los campos permitidos salen del REGISTRO, no de la petición. Un campo que no
     *    esté ahí se ignora, así que ni un cliente malicioso ni un bug del frontend
     *    pueden escribir una columna que no corresponde.
     *
     * Todo corre en una transacción: 116 piezas a medio actualizar serían peor que
     * ninguna.
     *
     * @param array $ids Piezas a modificar.
     * @param array $data field_key => valor. '' significa VACIAR ese campo.
     * @return array{updated:int, fields:array} cuántas filas y qué campos se tocaron.
     */
    public static function bulkUpdate(int $businessId, int $actorUserId, array $ids, array $data): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
        if (!$ids || !$data) {
            return ['updated' => 0, 'fields' => []];
        }

        $permitidos = [];
        foreach (AttributeDefinition::editableFields($businessId) as $f) {
            $permitidos[$f['field_key']] = $f;
        }

        $sets = [];
        $params = [];
        $tocados = [];
        $pdo = Database::connection();

        foreach ($data as $key => $valor) {
            if (!isset($permitidos[$key])) {
                continue;   // no está en el registro: se ignora en silencio
            }
            $campo = $permitidos[$key];
            $vacio = $valor === null || $valor === '';

            if ($campo['storage'] === AttributeDefinition::STORAGE_JSON) {
                if ($vacio) {
                    // Vaciar un atributo es quitar la clave, no dejarla en "".
                    $sets[] = "attributes = JSON_REMOVE(COALESCE(attributes, '{}'), ?)";
                    $params[] = '$."' . preg_replace('/[^a-zA-Z0-9_]/', '', $key) . '"';
                } else {
                    // COALESCE porque JSON_SET(NULL, ...) devuelve NULL: una pieza sin
                    // atributos habría quedado sin el dato nuevo.
                    $sets[] = "attributes = JSON_SET(COALESCE(attributes, '{}'), ?, ?)";
                    $params[] = '$."' . preg_replace('/[^a-zA-Z0-9_]/', '', $key) . '"';
                    $params[] = (string)$valor;
                }
                $tocados[] = $campo['label'];
                continue;
            }

            // Campos canónicos. Los dos que se resuelven por JOIN necesitan traducción:
            // el usuario escribe un nombre o un número, no un id interno.
            if ($key === 'supplier') {
                $sets[] = 'supplier_id = ?';
                $params[] = $vacio ? null : Supplier::resolveOrCreate($businessId, $actorUserId, (string)$valor);
                $tocados[] = $campo['label'];
                continue;
            }
            if ($key === 'order_number') {
                $sets[] = 'purchase_order_id = ?';
                $params[] = $vacio ? null : PurchaseOrder::resolveOrCreate($businessId, $actorUserId, $valor);
                $tocados[] = $campo['label'];
                continue;
            }

            $sets[] = "$key = ?";
            if (in_array($key, ['cost', 'shipping_cost', 'sale_price'], true)) {
                $params[] = $vacio ? null : self::requireNumber($valor, $campo['label']);
            } else {
                $params[] = $vacio ? null : trim((string)$valor);
            }
            $tocados[] = $campo['label'];
        }

        if (!$sets) {
            return ['updated' => 0, 'fields' => []];
        }

        $pdo->beginTransaction();
        try {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $sql = 'UPDATE inventory_items SET ' . implode(', ', $sets)
                . " WHERE id IN ($ph) AND business_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([...$params, ...$ids, $businessId]);
            $n = $stmt->rowCount();

            // Si se movieron piezas de pedido, los contadores denormalizados mienten.
            if (isset($data['order_number'])) {
                foreach ($pdo->query(
                    'SELECT id FROM purchase_orders WHERE business_id = ' . (int)$businessId
                )->fetchAll() as $po) {
                    PurchaseOrder::refreshItemCount($businessId, (int)$po['id']);
                }
            }

            $pdo->commit();
            return ['updated' => $n, 'fields' => array_values(array_unique($tocados))];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
    public static function voidSale(int $id, int $businessId): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE inventory_items SET sale_price = NULL, sale_date = NULL WHERE id = ? AND business_id = ?'
        );
        $stmt->execute([$id, $businessId]);
    }

    public static function distinctCategories(int $businessId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT DISTINCT category FROM inventory_items WHERE business_id = ? AND category IS NOT NULL AND category <> "" ORDER BY category'
        );
        $stmt->execute([$businessId]);
        return array_column($stmt->fetchAll(), 'category');
    }

    public static function distinctPurchaseOrders(int $businessId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, order_number FROM purchase_orders WHERE business_id = ? ORDER BY order_number DESC'
        );
        $stmt->execute([$businessId]);
        return $stmt->fetchAll();
    }

    /** Precio de venta promedio de lo YA vendido. Base para estimar el valor del stock sin precio. */
    public static function avgSoldPrice(int $businessId): ?float
    {
        $stmt = Database::connection()->prepare(
            'SELECT AVG(sale_price) FROM inventory_items
             WHERE business_id = ? AND sale_date IS NOT NULL AND sale_price IS NOT NULL'
        );
        $stmt->execute([$businessId]);
        $avg = $stmt->fetchColumn();
        return $avg !== null && $avg !== false ? round((float)$avg) : null;
    }

    /**
     * Convierte un valor a número o FALLA en voz alta.
     *
     * Existe porque numOrNull() devuelve null cuando no puede interpretar el valor, y
     * en una edición en lote eso es destructivo: escribir "1,250" —con la coma que
     * cualquiera teclea— habría puesto el costo en NULL en las 116 piezas
     * seleccionadas, sin un solo aviso.
     *
     * Aquí se separan los dos casos que numOrNull confunde:
     *   · "1,250.50" es un número escrito por una persona → se normaliza.
     *   · "abc" es un error → se rechaza con un mensaje que nombra el campo.
     *
     * Vaciar un campo sigue siendo posible, pero solo de forma explícita: mandando
     * el valor vacío, que se maneja antes de llegar aquí.
     */
    private static function requireNumber($v, string $label): float
    {
        // Se aceptan separadores de miles y símbolos de moneda: es lo que la gente
        // escribe. Se conservan solo dígitos, punto decimal y signo.
        $limpio = preg_replace('/[^0-9.\-]/', '', (string)$v);
        if ($limpio === '' || !is_numeric($limpio)) {
            throw new \InvalidArgumentException(
                "\"{$v}\" no es un número válido para el campo \"{$label}\"."
            );
        }
        return (float)$limpio;
    }

    private static function numOrNull($v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        return is_numeric($v) ? (float)$v : null;
    }
}
