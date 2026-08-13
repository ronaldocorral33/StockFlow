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
        $count = count($items);
        $shippingPerUnit = $count > 0 ? round($shippingTotalMxn / $count) : 0;

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
                  shipping_total_original, shipping_total_mxn, item_count)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $businessId, $actorUserId, $orderNumber, $supplierId,
                ($header['purchase_date'] ?? null) ?: null, ($header['arrival_date'] ?? null) ?: null,
                $currency, $exchangeRate, $shippingTotalOriginal, $shippingTotalMxn, $count,
            ]);
            $poId = (int)$pdo->lastInsertId();

            $itemStmt = $pdo->prepare(
                'INSERT INTO inventory_items
                 (business_id, user_id, purchase_order_id, supplier_id, name, variant_label, category, subcategory,
                  attributes, cost, shipping_cost, sale_price, purchase_date, arrival_date)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );

            $itemIds = [];
            foreach ($items as $item) {
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
                    $attributes, $cost, $shippingPerUnit, $salePrice,
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
