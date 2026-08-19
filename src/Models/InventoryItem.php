<?php
namespace App\Models;

if (!defined('APP_BOOTSTRAP')) { http_response_code(403); exit('Forbidden'); }

use App\Database;

class InventoryItem
{
    private const SORTABLE = [
        'id', 'name', 'category', 'subcategory', 'cost', 'shipping_cost', 'sale_price',
        'total_cost', 'profit', 'purchase_date', 'arrival_date', 'sale_date', 'created_at',
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
        if (!empty($filters['purchase_order_id'])) {
            $sql .= ' AND i.purchase_order_id = ?';
            $params[] = (int)$filters['purchase_order_id'];
        }

        $sortCol = in_array($filters['sort'] ?? '', self::SORTABLE, true) ? $filters['sort'] : 'created_at';
        $dir = (($filters['dir'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';
        $sql .= " ORDER BY i.$sortCol $dir";

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

    private static function numOrNull($v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        return is_numeric($v) ? (float)$v : null;
    }
}
