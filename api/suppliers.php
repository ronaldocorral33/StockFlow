<?php
require dirname(__DIR__) . '/src/Support/bootstrap.php';

use App\Models\Supplier;

$userId = require_login(true);
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    json_response(['suppliers' => Supplier::listForUser($userId)]);
}

if ($method === 'POST') {
    csrf_check(true);
    $body = json_body();
    $id = Supplier::resolveOrCreate($userId, $body['name'] ?? '');
    json_response(['ok' => true, 'id' => $id]);
}

json_response(['error' => 'Método no soportado'], 405);
