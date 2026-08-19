<?php
require dirname(__DIR__) . '/src/Support/bootstrap.php';

use App\Database;
use App\Models\InventoryItem;
use App\Services\TrendService;
use App\Services\Authz;

['business_id' => $businessId] = require_business(true);
Authz::require('view', 'reports');
$action = $_GET['action'] ?? 'kpis';
$pdo = Database::connection();

function scalarQuery(\PDO $pdo, string $sql, array $params) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch();
}

if ($action === 'kpis') {
    $row = scalarQuery($pdo,
        'SELECT COUNT(*) AS units_sold, COALESCE(SUM(sale_price),0) AS revenue, COALESCE(SUM(profit),0) AS profit
         FROM inventory_items WHERE business_id = ? AND sale_date IS NOT NULL',
        [$businessId]
    );
    $units = (int)$row['units_sold'];
    $revenue = (float)$row['revenue'];
    $profit = (float)$row['profit'];
    json_response([
        'units_sold' => $units,
        'revenue' => $revenue,
        'profit' => $profit,
        'margin' => $revenue > 0 ? round($profit / $revenue * 100, 1) : 0,
        'avg_ticket' => $units > 0 ? round($revenue / $units) : 0,
    ]);
}

if ($action === 'stock-kpis') {
    // Misma definición de "venta potencial" que usa la pestaña Inventario:
    // SIEMPRE piezas en stock × precio de venta promedio histórico. Una sola base
    // de cálculo, aunque algunas piezas ya traigan un precio propio asignado.
    $stock = scalarQuery($pdo,
        'SELECT COUNT(*) AS n, COALESCE(SUM(total_cost),0) AS invested
         FROM inventory_items WHERE business_id = ? AND sale_date IS NULL',
        [$businessId]
    );
    $avgSale = InventoryItem::avgSoldPrice($businessId);

    $n = (int)$stock['n'];
    $invested = (float)$stock['invested'];
    $potentialRevenue = $avgSale !== null ? round($n * $avgSale) : null;

    json_response([
        'count' => $n,
        'invested' => $invested,
        'avg_historical_sale' => $avgSale,
        'potential_revenue' => $potentialRevenue,
        'potential_profit' => $potentialRevenue !== null ? round($potentialRevenue - $invested) : null,
    ]);
}

if ($action === 'top-products') {
    $stmt = $pdo->prepare(
        'SELECT name, SUM(profit) AS profit, COUNT(*) AS units
         FROM inventory_items WHERE business_id = ? AND sale_date IS NOT NULL
         GROUP BY name ORDER BY profit DESC LIMIT 10'
    );
    $stmt->execute([$businessId]);
    json_response(['rows' => $stmt->fetchAll()]);
}

if ($action === 'groupable-fields') {
    $stmt = $pdo->prepare('SELECT field_key, label FROM attribute_definitions WHERE business_id = ? ORDER BY sort_order ASC, id ASC');
    $stmt->execute([$businessId]);
    $fields = [
        ['field_key' => 'category', 'label' => 'Categoría'],
        ['field_key' => 'subcategory', 'label' => 'Subcategoría'],
    ];
    json_response(['fields' => array_merge($fields, $stmt->fetchAll())]);
}

if ($action === 'by-attribute') {
    $field = (string)($_GET['field'] ?? 'category');
    if (in_array($field, ['category', 'subcategory'], true)) {
        $col = "i.$field";
    } elseif (preg_match('/^[a-z0-9_]+$/', $field)) {
        $stmt = $pdo->prepare('SELECT 1 FROM attribute_definitions WHERE business_id = ? AND field_key = ?');
        $stmt->execute([$businessId, $field]);
        if (!$stmt->fetch()) {
            json_response(['error' => 'Campo no válido'], 422);
        }
        $col = "JSON_UNQUOTE(JSON_EXTRACT(i.attributes, '$.\"$field\"'))";
    } else {
        json_response(['error' => 'Campo no válido'], 422);
    }

    $stmt = $pdo->prepare(
        "SELECT COALESCE($col, 'Sin dato') AS value, COUNT(*) AS units, SUM(i.profit) AS profit
         FROM inventory_items i WHERE i.business_id = ? AND i.sale_date IS NOT NULL
         GROUP BY value ORDER BY profit DESC LIMIT 10"
    );
    $stmt->execute([$businessId]);
    json_response(['rows' => $stmt->fetchAll()]);
}

if ($action === 'sales-trend') {
    json_response(['rows' => TrendService::monthlySeries($businessId)]);
}

if ($action === 'slow-movers') {
    $stmt = $pdo->prepare(
        "SELECT po.order_number, i.name, i.total_cost,
                DATEDIFF(CURDATE(), COALESCE(i.arrival_date, i.purchase_date)) AS days
         FROM inventory_items i
         LEFT JOIN purchase_orders po ON po.id = i.purchase_order_id
         WHERE i.business_id = ? AND i.sale_date IS NULL
           AND COALESCE(i.arrival_date, i.purchase_date) IS NOT NULL
         ORDER BY days DESC LIMIT 10"
    );
    $stmt->execute([$businessId]);
    json_response(['rows' => $stmt->fetchAll()]);
}

if ($action === 'order-profitability') {
    // Rentabilidad pedido por pedido: cuánto pusiste, cuánto ha regresado, y en qué
    // punto va tu posición neta.
    //
    // Las tres cifras se calculan en SQL sobre TODAS las piezas del pedido, no en PHP
    // sobre una muestra: son dinero, y una suma parcial que parece total es peor que
    // no mostrar el dato.
    //
    // "recuperado" solo suma piezas VENDIDAS (sale_date IS NOT NULL). Una pieza en
    // stock con precio sugerido no es dinero que ya entró; contarla infla el número
    // y da una falsa sensación de que el pedido ya se pagó.
    $stmt = $pdo->prepare(
        'SELECT po.id, po.order_number, po.purchase_date, po.arrival_date,
                s.name AS supplier_name,
                COUNT(i.id) AS pieces,
                SUM(i.sale_date IS NOT NULL) AS sold,
                COALESCE(SUM(i.total_cost), 0) AS invested,
                COALESCE(SUM(CASE WHEN i.sale_date IS NOT NULL THEN i.sale_price ELSE 0 END), 0) AS recovered
         FROM purchase_orders po
         LEFT JOIN inventory_items i ON i.purchase_order_id = po.id
         LEFT JOIN suppliers s ON s.id = po.supplier_id
         WHERE po.business_id = ?
         GROUP BY po.id
         HAVING pieces > 0
         ORDER BY po.order_number ASC'
    );
    $stmt->execute([$businessId]);

    $rows = array_map(function ($r) {
        $invested = (float)$r['invested'];
        $recovered = (float)$r['recovered'];
        return [
            'order_number' => (int)$r['order_number'],
            'supplier_name' => $r['supplier_name'],
            'purchase_date' => $r['purchase_date'],
            'pieces' => (int)$r['pieces'],
            'sold' => (int)$r['sold'],
            'invested' => $invested,
            'recovered' => $recovered,
            // Posición neta: negativa mientras el pedido no se haya pagado solo.
            'net' => round($recovered - $invested, 2),
            // % de la inversión ya recuperada. Sirve para ordenar y para el tooltip.
            'recovered_pct' => $invested > 0 ? round($recovered / $invested * 100, 1) : null,
        ];
    }, $stmt->fetchAll());

    json_response(['rows' => $rows]);
}
if ($action === 'projection') {
    $months = (int)($_GET['months'] ?? 1);
    json_response(TrendService::projectNextMonths($businessId, $months));
}

json_response(['error' => 'Acción no soportada'], 400);
