<?php
require dirname(__DIR__) . '/src/Support/bootstrap.php';

use App\Models\InventoryItem;
use App\Models\PurchaseOrder;
use App\Services\Authz;

/**
 * API de PEDIDOS.
 *
 * Antes solo sabía dos cosas: cuál es el siguiente número, y cómo crear uno. Un pedido
 * mal capturado no tenía salida — había que borrar sus piezas una por una desde
 * Inventario, y el pedido quedaba vivo con su costo en la gráfica.
 *
 * Los permisos son los que ya estaban sembrados para este recurso: el almacenista
 * puede crear y editar pedidos, pero NO borrarlos; eso queda en administrador y
 * gerente. No se agregó ni se aflojó ninguno.
 */

['user_id' => $userId, 'business_id' => $businessId] = require_business(true);
$method = $_SERVER['REQUEST_METHOD'];
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($method === 'GET' && ($_GET['action'] ?? '') === 'next-number') {
    Authz::require('view', 'purchase_orders');
    json_response(['order_number' => PurchaseOrder::nextOrderNumber($businessId)]);
}

// Un pedido con sus piezas. Las piezas se piden al modelo de inventario en vez de
// duplicar aquí una consulta: es la misma lista que dibuja Inventario, con los mismos
// campos calculados, filtrada por este pedido.
if ($method === 'GET' && $id > 0) {
    Authz::require('view', 'purchase_orders');
    $order = PurchaseOrder::find($id, $businessId);
    if (!$order) {
        json_response(['error' => 'Ese pedido no existe en este negocio.'], 404);
    }
    json_response([
        'order' => $order,
        'items' => InventoryItem::list($businessId, ['purchase_order_id' => $id, 'sort' => 'id', 'dir' => 'asc']),
    ]);
}

if ($method === 'GET') {
    Authz::require('view', 'purchase_orders');
    json_response(['orders' => PurchaseOrder::list($businessId, ['q' => $_GET['q'] ?? ''])]);
}

if (in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
    csrf_check(true);
}

if ($method === 'POST') {
    if (($_GET['action'] ?? '') === 'append-items' && $id > 0) {
        Authz::require('update', 'purchase_orders');
        try {
            $r = PurchaseOrder::appendItems($businessId, $userId, $id, json_body()['items'] ?? []);
            json_response(['ok' => true] + $r);
        } catch (\InvalidArgumentException $e) {
            json_response(['error' => $e->getMessage()], 422);
        }
    }
    if (($_GET['action'] ?? '') === 'add-item' && $id > 0) {
        Authz::require('update', 'purchase_orders');
        try {
            $r = PurchaseOrder::addItem($businessId, $userId, $id, json_body());
            json_response(['ok' => true] + $r);
        } catch (\InvalidArgumentException $e) {
            json_response(['error' => $e->getMessage()], 422);
        }
    }
    Authz::require('create', 'purchase_orders');
    $body = json_body();
    try {
        $result = PurchaseOrder::createWithItems($businessId, $userId, $body['header'] ?? [], $body['items'] ?? []);
        json_response(['ok' => true] + $result);
    } catch (\InvalidArgumentException $e) {
        json_response(['error' => $e->getMessage()], 422);
    }
}

if ($method === 'PUT' && $id > 0) {
    Authz::require('update', 'purchase_orders');
    try {
        $r = PurchaseOrder::updateHeader($businessId, $userId, $id, json_body());
    } catch (\InvalidArgumentException $e) {
        // Número repetido, envío negativo, pedido inexistente: son cosas que el
        // usuario puede corregir, así que el mensaje se le devuelve tal cual.
        json_response(['error' => $e->getMessage()], 422);
    } catch (\Throwable $e) {
        error_log('[pedidos:update] ' . $e->getMessage());
        json_response(['error' => 'No se pudieron guardar los cambios del pedido.'], 500);
    }
    json_response(['ok' => true] + $r);
}

if ($method === 'DELETE' && $id > 0) {
    if (isset($_GET['item_id'])) {
        Authz::require('update', 'purchase_orders');
        try {
            PurchaseOrder::removeItem($businessId, $id, (int)$_GET['item_id']);
            json_response(['ok' => true]);
        } catch (\InvalidArgumentException $e) {
            json_response(['error' => $e->getMessage()], 422);
        }
    }
    Authz::require('delete', 'purchase_orders');
    try {
        $r = PurchaseOrder::deleteWithItems($businessId, $id);
    } catch (\InvalidArgumentException $e) {
        // El caso importante: el pedido tiene ventas. El 409 dice "el estado actual lo
        // impide", que es más honesto que un 422 de "dato inválido" — no hay nada malo
        // en la petición, hay algo que perder.
        json_response(['error' => $e->getMessage()], 409);
    } catch (\Throwable $e) {
        error_log('[pedidos:delete] ' . $e->getMessage());
        json_response(['error' => 'No se pudo borrar el pedido.'], 500);
    }
    json_response(['ok' => true] + $r);
}

json_response(['error' => 'Método no soportado'], 405);
