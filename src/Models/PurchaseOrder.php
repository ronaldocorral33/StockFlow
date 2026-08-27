<?php
namespace App\Models;

if (!defined('APP_BOOTSTRAP')) { http_response_code(403); exit('Forbidden'); }

use App\Database;
use App\Services\PricingService;

class PurchaseOrder
{
    /** Debe llamarse dentro de una transacción ya abierta — usa FOR UPDATE para serializar
     *  la generación de números de pedido entre empleados concurrentes del mismo negocio. */
    private static function nextOrderNumberLocked(\PDO $pdo, int $businessId): int
    {
        $stmt = $pdo->prepare('SELECT COALESCE(MAX(order_number), 0) + 1 FROM purchase_orders WHERE business_id = ? FOR UPDATE');
        $stmt->execute([$businessId]);
        return (int)$stmt->fetchColumn();
    }

    public static function nextOrderNumber(int $businessId): int
    {
        $stmt = Database::connection()->prepare('SELECT COALESCE(MAX(order_number), 0) + 1 FROM purchase_orders WHERE business_id = ?');
        $stmt->execute([$businessId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Reparte el envío del pedido entre las piezas SIN perder ni inventar centavos.
     *
     * El reparto anterior era `round(total / n)` para todas las piezas: con $1,000
     * entre 3 daba $333 a cada una y el pedido "costaba" $999. Un peso por pedido
     * parece poco, pero es un error de contabilidad — la suma de los costos por pieza
     * debe ser exactamente lo que se pagó.
     *
     * Se trabaja en CENTAVOS enteros para no arrastrar el error de los flotantes, y el
     * residuo se reparte de a un centavo entre las primeras piezas. Así todas quedan
     * a lo sumo a un centavo de distancia y la suma cuadra exacto.
     *
     * @return float[] monto por pieza, en el mismo orden que $items
     */
    private static function splitShipping(float $totalMxn, int $count): array
    {
        if ($count <= 0) {
            return [];
        }
        $centavos = (int)round($totalMxn * 100);
        $base = intdiv($centavos, $count);
        $resto = $centavos - ($base * $count);

        $out = [];
        for ($i = 0; $i < $count; $i++) {
            // Las primeras $resto piezas llevan un centavo extra.
            $out[] = ($base + ($i < $resto ? 1 : 0)) / 100;
        }
        return $out;
    }
    /**
     * Devuelve el id del pedido con ese número, creándolo si no existe.
     *
     * Existe para la importación: en un Excel el pedido viene como un NÚMERO ("#12"),
     * no como el id interno de la base. Sin esta traducción, el número de pedido de un
     * archivo importado no tenía a dónde llegar y se perdía — que es exactamente por lo
     * que 739 piezas quedaron huérfanas de pedido.
     *
     * Es idempotente a propósito: importar 50 filas del pedido #12 crea UN pedido, no 50.
     */
    public static function resolveOrCreate(int $businessId, int $actorUserId, $orderNumber, array $header = []): ?int
    {
        if ($orderNumber === null || $orderNumber === '') {
            return null;
        }
        $number = (int)preg_replace('/[^0-9]/', '', (string)$orderNumber);
        if ($number <= 0) {
            return null;
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id FROM purchase_orders WHERE business_id = ? AND order_number = ?');
        $stmt->execute([$businessId, $number]);
        $existing = $stmt->fetchColumn();
        if ($existing !== false) {
            return (int)$existing;
        }

        $supplierId = Supplier::resolveOrCreate($businessId, $actorUserId, $header['supplier'] ?? null);
        $ins = $pdo->prepare(
            'INSERT INTO purchase_orders
             (business_id, user_id, order_number, supplier_id, purchase_date, arrival_date, item_count)
             VALUES (?, ?, ?, ?, ?, ?, 0)'
        );
        $ins->execute([
            $businessId, $actorUserId, $number, $supplierId,
            ($header['purchase_date'] ?? null) ?: null,
            ($header['arrival_date'] ?? null) ?: null,
        ]);
        return (int)$pdo->lastInsertId();
    }

    /** Recalcula item_count desde las piezas realmente vinculadas. La columna es un
     *  contador denormalizado: si nadie lo recalcula tras importar, miente. */
    public static function refreshItemCount(int $businessId, int $purchaseOrderId): void
    {
        Database::connection()
            ->prepare(
                'UPDATE purchase_orders po
                 SET item_count = (SELECT COUNT(*) FROM inventory_items i WHERE i.purchase_order_id = po.id)
                 WHERE po.id = ? AND po.business_id = ?'
            )
            ->execute([$purchaseOrderId, $businessId]);
    }
    /**
     * Crea un pedido de compra (lote) junto con sus piezas de inventario, en una transacción.
     * $header: order_number?, supplier?, purchase_date?, arrival_date?, currency, exchange_rate, shipping_total
     * $items: [{name, variant_label?, category?, subcategory?, cost?, sale_price?, attributes?}, ...]
     * @return array{purchase_order_id:int, item_ids:int[]}
     */
    public static function createWithItems(int $businessId, int $actorUserId, array $header, array $items): array
    {
        if (empty($items)) {
            throw new \InvalidArgumentException('El pedido necesita al menos un producto.');
        }

        $currency = ($header['currency'] ?? 'MXN') === 'USD' ? 'USD' : 'MXN';
        $exchangeRate = (float)($header['exchange_rate'] ?? 1);
        $factor = $currency === 'USD' ? $exchangeRate : 1.0;
        $shippingTotalOriginal = (float)($header['shipping_total'] ?? 0);
        $shippingTotalMxn = PricingService::toMxn($shippingTotalOriginal, $currency, $exchangeRate);
        // Las filas sin nombre se descartan más abajo. Si el envío se dividiera entre
        // TODAS las filas recibidas, una fila vacía se llevaría su parte y el reparto
        // no sumaría el envío del pedido.
        $items = array_values(array_filter(
            $items,
            fn($i) => trim((string)($i['name'] ?? '')) !== ''
        ));
        $count = count($items);
        $shippingPerUnit = self::splitShipping($shippingTotalMxn, $count);

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $supplierId = Supplier::resolveOrCreate($businessId, $actorUserId, $header['supplier'] ?? null);
            $orderNumber = !empty($header['order_number'])
                ? (int)$header['order_number']
                : self::nextOrderNumberLocked($pdo, $businessId);

            $stmt = $pdo->prepare(
                'INSERT INTO purchase_orders
                 (business_id, user_id, order_number, supplier_id, purchase_date, arrival_date, currency, exchange_rate,
                  shipping_total_original, shipping_total_mxn, item_count, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $businessId, $actorUserId, $orderNumber, $supplierId,
                ($header['purchase_date'] ?? null) ?: null, ($header['arrival_date'] ?? null) ?: null,
                $currency, $exchangeRate, $shippingTotalOriginal, $shippingTotalMxn, $count,
                // La columna existía desde el esquema original pero nadie la escribía.
                ($header['notes'] ?? null) ?: null,
            ]);
            $poId = (int)$pdo->lastInsertId();

            $itemStmt = $pdo->prepare(
                'INSERT INTO inventory_items
                 (business_id, user_id, purchase_order_id, supplier_id, name, variant_label, category, subcategory,
                  attributes, cost, shipping_cost, sale_price, purchase_date, arrival_date)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );

            $itemIds = [];
            foreach ($items as $idx => $item) {
                $name = trim((string)($item['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $cost = isset($item['cost']) && $item['cost'] !== '' ? round(((float)$item['cost']) * $factor) : null;
                $salePrice = isset($item['sale_price']) && $item['sale_price'] !== '' ? round(((float)$item['sale_price']) * $factor) : null;
                $attributes = !empty($item['attributes']) ? json_encode($item['attributes']) : null;

                $itemStmt->execute([
                    $businessId, $actorUserId, $poId, $supplierId,
                    $name, $item['variant_label'] ?? null, $item['category'] ?? null, $item['subcategory'] ?? null,
                    $attributes, $cost, $shippingPerUnit[$idx] ?? 0, $salePrice,
                    ($header['purchase_date'] ?? null) ?: null, ($header['arrival_date'] ?? null) ?: null,
                ]);
                $itemIds[] = (int)$pdo->lastInsertId();
            }

            if (empty($itemIds)) {
                throw new \InvalidArgumentException('Ningún producto tenía nombre válido.');
            }

            $pdo->commit();
            return ['purchase_order_id' => $poId, 'item_ids' => $itemIds];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
