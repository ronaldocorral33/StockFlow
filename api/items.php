<?php
require dirname(__DIR__) . '/src/Support/bootstrap.php';

use App\Models\InventoryItem;
use App\Services\Authz;

['user_id' => $userId, 'business_id' => $businessId] = require_business(true);
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($method === 'GET' && $action === 'meta') {
    Authz::require('view', 'inventory_items');
    json_response([
        'categories' => InventoryItem::distinctCategories($businessId),
        'purchase_orders' => InventoryItem::distinctPurchaseOrders($businessId),
        // Base para estimar el valor de las piezas que aún no tienen precio asignado.
        'avg_sold_price' => InventoryItem::avgSoldPrice($businessId),
    ]);
}

if ($method === 'GET' && $id > 0) {
    Authz::require('view', 'inventory_items');
    $item = InventoryItem::find($id, $businessId);
    if (!$item) {
        json_response(['error' => 'No encontrado'], 404);
    }
    json_response(['item' => $item]);
}

if ($method === 'GET') {
    Authz::require('view', 'inventory_items');
    $filters = [
        'q' => $_GET['q'] ?? '',
        'status' => $_GET['status'] ?? '',
        'category' => $_GET['category'] ?? '',
        'purchase_order_id' => $_GET['purchase_order_id'] ?? '',
        // Filtros por campo del registro. Llegan como attr[clave]=valor; el modelo
        // valida cada clave contra el registro, así que aquí no hace falta filtrar.
        'attrs' => is_array($_GET['attr'] ?? null) ? $_GET['attr'] : [],
        'sort' => $_GET['sort'] ?? 'order_number',
        'dir' => $_GET['dir'] ?? 'desc',
    ];
    json_response(['items' => InventoryItem::list($businessId, $filters)]);
}

if (in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
    csrf_check(true);
}

if ($method === 'POST' && $action === 'sell' && $id > 0) {
    Authz::require('sell', 'inventory_items');
    $body = json_body();
    if (!isset($body['sale_price']) || !is_numeric($body['sale_price'])) {
        json_response(['error' => 'Precio de venta inválido'], 422);
    }
    InventoryItem::sell($id, $businessId, (float)$body['sale_price'], $body['sale_date'] ?? date('Y-m-d'));
    json_response(['ok' => true]);
}

if ($method === 'POST' && $action === 'bulk-sell') {
    Authz::require('sell', 'inventory_items');
    $body = json_body();
    $count = InventoryItem::bulkSell($businessId, $body['rows'] ?? [], $body['sale_date'] ?? date('Y-m-d'));
    json_response(['ok' => true, 'count' => $count]);
}

if ($method === 'POST' && $action === 'bulk-arrival') {
    Authz::require('update', 'inventory_items');
    $body = json_body();
    $count = InventoryItem::bulkArrival($businessId, $body['ids'] ?? [], $body['arrival_date'] ?? date('Y-m-d'));
    json_response(['ok' => true, 'count' => $count]);
}

if ($method === 'POST' && $action === 'bulk-update') {
    // Editar en lote es editar inventario: exige el permiso de edición, no el de
    // creación. Un rol que solo puede dar de alta piezas no debería poder reescribir
    // 116 de golpe.
    Authz::require('update', 'inventory_items');
    $body = json_body();

    $ids = $body['ids'] ?? [];
    $fields = $body['fields'] ?? [];
    if (!is_array($ids) || !$ids) {
        json_response(['error' => 'No seleccionaste ninguna pieza.'], 422);
    }
    if (!is_array($fields) || !$fields) {
        json_response(['error' => 'No marcaste ningún campo para cambiar.'], 422);
    }

    try {
        $r = InventoryItem::bulkUpdate($businessId, $userId, $ids, $fields);
    } catch (\InvalidArgumentException $e) {
        // Dato inválido del usuario: se le dice qué corregir, y NADA se modificó.
        json_response(['error' => $e->getMessage()], 422);
    } catch (\Throwable $e) {
        error_log('[bulk-update] ' . $e->getMessage());
        json_response(['error' => 'No se pudieron aplicar los cambios.'], 500);
    }
    json_response(['ok' => true] + $r);
}
if ($method === 'POST' && $action === 'void-sale' && $id > 0) {
    Authz::require('sell', 'inventory_items');
    InventoryItem::voidSale($id, $businessId);
    json_response(['ok' => true]);
}

if ($method === 'POST' && $action === '') {
    Authz::require('create', 'inventory_items');
    $body = json_body();
    try {
        $newId = InventoryItem::create($businessId, $userId, $body);
        json_response(['ok' => true, 'id' => $newId]);
    } catch (\InvalidArgumentException $e) {
        json_response(['error' => $e->getMessage()], 422);
    }
}

if ($method === 'PUT' && $id > 0) {
    Authz::require('update', 'inventory_items');
    try {
        InventoryItem::update($id, $businessId, $userId, json_body());
        json_response(['ok' => true]);
    } catch (\InvalidArgumentException $e) {
        json_response(['error' => $e->getMessage()], 422);
    }
}

if ($method === 'DELETE' && $id > 0) {
    Authz::require('delete', 'inventory_items');
    InventoryItem::delete($id, $businessId);
    json_response(['ok' => true]);
}

json_response(['error' => 'Solicitud no soportada'], 405);
