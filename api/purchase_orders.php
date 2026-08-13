<?php
require dirname(__DIR__) . '/src/Support/bootstrap.php';

use App\Models\PurchaseOrder;

$userId = require_login(true);
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET' && ($_GET['action'] ?? '') === 'next-number') {
    json_response(['order_number' => PurchaseOrder::nextOrderNumber($userId)]);
}

if ($method === 'POST') {
    csrf_check(true);
    $body = json_body();
    try {
        $result = PurchaseOrder::createWithItems($userId, $body['header'] ?? [], $body['items'] ?? []);
        json_response(['ok' => true] + $result);
    } catch (\InvalidArgumentException $e) {
        json_response(['error' => $e->getMessage()], 422);
    }
}

json_response(['error' => 'Método no soportado'], 405);
