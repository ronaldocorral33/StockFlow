<?php
require dirname(__DIR__) . '/src/Support/bootstrap.php';

use App\Models\PurchaseOrder;
use App\Services\Authz;

['user_id' => $userId, 'business_id' => $businessId] = require_business(true);
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET' && ($_GET['action'] ?? '') === 'next-number') {
    Authz::require('view', 'purchase_orders');
    json_response(['order_number' => PurchaseOrder::nextOrderNumber($businessId)]);
}

if ($method === 'POST') {
    csrf_check(true);
    Authz::require('create', 'purchase_orders');
    $body = json_body();
    try {
        $result = PurchaseOrder::createWithItems($businessId, $userId, $body['header'] ?? [], $body['items'] ?? []);
        json_response(['ok' => true] + $result);
    } catch (\InvalidArgumentException $e) {
        json_response(['error' => $e->getMessage()], 422);
    }
}

json_response(['error' => 'Método no soportado'], 405);
