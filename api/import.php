<?php
require dirname(__DIR__) . '/src/Support/bootstrap.php';

use App\Services\ImportExportService;
use App\Services\Authz;

['user_id' => $userId, 'business_id' => $businessId] = require_business(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Método no soportado'], 405);
}
csrf_check(true);
Authz::require('create', 'inventory_items');

$body = json_body();
$rows = $body['rows'] ?? [];
if (!is_array($rows) || empty($rows)) {
    json_response(['error' => 'No se encontraron filas para importar'], 422);
}

$result = ImportExportService::importRows($businessId, $userId, $rows);
json_response(['ok' => true] + $result);
