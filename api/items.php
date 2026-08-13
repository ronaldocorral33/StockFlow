<?php
require dirname(__DIR__) . '/src/Support/bootstrap.php';

use App\Models\InventoryItem;

$userId = require_login(true);
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($method === 'GET' && $action === 'meta') {
    json_response([
        'categories' => InventoryItem::distinctCategories($userId),
        'purchase_orders' => InventoryItem::distinctPurchaseOrders($userId),
    ]);
}

if ($method === 'GET' && $id > 0) {
    $item = InventoryItem::find($id, $userId);
    if (!$item) {
        json_response(['error' => 'No encontrado'], 404);
    }
    json_response(['item' => $item]);
}

if ($method === 'GET') {
    $filters = [
        'q' => $_GET['q'] ?? '',
        'status' => $_GET['status'] ?? '',
        'category' => $_GET['category'] ?? '',
        'purchase_order_id' => $_GET['purchase_order_id'] ?? '',
        'sort' => $_GET['sort'] ?? 'created_at',
        'dir' => $_GET['dir'] ?? 'desc',
    ];
    json_response(['items' => InventoryItem::list($userId, $filters)]);
}

if (in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
    csrf_check(true);
}

if ($method === 'POST' && $action === 'sell' && $id > 0) {
    $body = json_body();
    if (!isset($body['sale_price']) || !is_numeric($body['sale_price'])) {
        json_response(['error' => 'Precio de venta inválido'], 422);
    }
    InventoryItem::sell($id, $userId, (float)$body['sale_price'], $body['sale_date'] ?? date('Y-m-d'));
    json_response(['ok' => true]);
}

if ($method === 'POST' && $action === 'bulk-sell') {
    $body = json_body();
    $count = InventoryItem::bulkSell($userId, $body['rows'] ?? [], $body['sale_date'] ?? date('Y-m-d'));
    json_response(['ok' => true, 'count' => $count]);
}

if ($method === 'POST' && $action === 'bulk-arrival') {
    $body = json_body();
    $count = InventoryItem::bulkArrival($userId, $body['ids'] ?? [], $body['arrival_date'] ?? date('Y-m-d'));
    json_response(['ok' => true, 'count' => $count]);
}

if ($method === 'POST' && $action === 'void-sale' && $id > 0) {
    InventoryItem::voidSale($id, $userId);
    json_response(['ok' => true]);
}

if ($method === 'POST' && $action === '') {
    $body = json_body();
    try {
        $newId = InventoryItem::create($userId, $body);
        json_response(['ok' => true, 'id' => $newId]);
    } catch (\InvalidArgumentException $e) {
        json_response(['error' => $e->getMessage()], 422);
    }
}

if ($method === 'PUT' && $id > 0) {
    try {
        InventoryItem::update($id, $userId, json_body());
        json_response(['ok' => true]);
    } catch (\InvalidArgumentException $e) {
        json_response(['error' => $e->getMessage()], 422);
    }
}

if ($method === 'DELETE' && $id > 0) {
    InventoryItem::delete($id, $userId);
    json_response(['ok' => true]);
}

json_response(['error' => 'Solicitud no soportada'], 405);
