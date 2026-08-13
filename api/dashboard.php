<?php
require dirname(__DIR__) . '/src/Support/bootstrap.php';

use App\Database;
use App\Services\TrendService;

$userId = require_login(true);
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
         FROM inventory_items WHERE user_id = ? AND sale_date IS NOT NULL',
        [$userId]
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
    $stock = scalarQuery($pdo,
        'SELECT COUNT(*) AS n, COALESCE(SUM(total_cost),0) AS invested
         FROM inventory_items WHERE user_id = ? AND sale_date IS NULL',
        [$userId]
    );
    $avgSale = scalarQuery($pdo,
        'SELECT AVG(sale_price) AS avg_sale FROM inventory_items WHERE user_id = ? AND sale_date IS NOT NULL',
        [$userId]
    )['avg_sale'];

    $n = (int)$stock['n'];
    $invested = (float)$stock['invested'];
    $potentialRevenue = $avgSale !== null ? round($avgSale * $n) : null;
    json_response([
        'count' => $n,
        'invested' => $invested,
        'avg_historical_sale' => $avgSale !== null ? round((float)$avgSale) : null,
        'potential_revenue' => $potentialRevenue,
        'potential_profit' => $potentialRevenue !== null ? round($potentialRevenue - $invested) : null,
    ]);
}

if ($action === 'top-products') {
    $stmt = $pdo->prepare(
        'SELECT name, SUM(profit) AS profit, COUNT(*) AS units
         FROM inventory_items WHERE user_id = ? AND sale_date IS NOT NULL
         GROUP BY name ORDER BY profit DESC LIMIT 10'
    );
    $stmt->execute([$userId]);
    json_response(['rows' => $stmt->fetchAll()]);
}

if ($action === 'groupable-fields') {
    $stmt = $pdo->prepare('SELECT field_key, label FROM attribute_definitions WHERE user_id = ? ORDER BY sort_order ASC, id ASC');
    $stmt->execute([$userId]);
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
        $stmt = $pdo->prepare('SELECT 1 FROM attribute_definitions WHERE user_id = ? AND field_key = ?');
        $stmt->execute([$userId, $field]);
        if (!$stmt->fetch()) {
            json_response(['error' => 'Campo no válido'], 422);
        }
        $col = "JSON_UNQUOTE(JSON_EXTRACT(i.attributes, '$.\"$field\"'))";
    } else {
        json_response(['error' => 'Campo no válido'], 422);
    }

    $stmt = $pdo->prepare(
        "SELECT COALESCE($col, 'Sin dato') AS value, COUNT(*) AS units, SUM(i.profit) AS profit
         FROM inventory_items i WHERE i.user_id = ? AND i.sale_date IS NOT NULL
         GROUP BY value ORDER BY profit DESC LIMIT 10"
    );
    $stmt->execute([$userId]);
    json_response(['rows' => $stmt->fetchAll()]);
}

if ($action === 'sales-trend') {
    json_response(['rows' => TrendService::monthlySeries($userId)]);
}

if ($action === 'slow-movers') {
    $stmt = $pdo->prepare(
        "SELECT po.order_number, i.name, i.total_cost,
                DATEDIFF(CURDATE(), COALESCE(i.arrival_date, i.purchase_date)) AS days
         FROM inventory_items i
         LEFT JOIN purchase_orders po ON po.id = i.purchase_order_id
         WHERE i.user_id = ? AND i.sale_date IS NULL
           AND COALESCE(i.arrival_date, i.purchase_date) IS NOT NULL
         ORDER BY days DESC LIMIT 10"
    );
    $stmt->execute([$userId]);
    json_response(['rows' => $stmt->fetchAll()]);
}

if ($action === 'projection') {
    $months = (int)($_GET['months'] ?? 1);
    json_response(TrendService::projectNextMonths($userId, $months));
}

json_response(['error' => 'Acción no soportada'], 400);
