<?php
require dirname(__DIR__) . '/src/Support/bootstrap.php';

use App\Models\Supplier;
use App\Services\Authz;

['user_id' => $userId, 'business_id' => $businessId] = require_business(true);
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    Authz::require('view', 'suppliers');
    json_response(['suppliers' => Supplier::listForBusiness($businessId)]);
}

if ($method === 'POST') {
    csrf_check(true);
    Authz::require('create', 'suppliers');
    $body = json_body();
    $id = Supplier::resolveOrCreate($businessId, $userId, $body['name'] ?? '');
    json_response(['ok' => true, 'id' => $id]);
}

json_response(['error' => 'Método no soportado'], 405);
