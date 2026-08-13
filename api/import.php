<?php
require dirname(__DIR__) . '/src/Support/bootstrap.php';

use App\Services\ImportExportService;

$userId = require_login(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Método no soportado'], 405);
}
csrf_check(true);

$body = json_body();
$rows = $body['rows'] ?? [];
if (!is_array($rows) || empty($rows)) {
    json_response(['error' => 'No se encontraron filas para importar'], 422);
}

$result = ImportExportService::importRows($userId, $rows);
json_response(['ok' => true] + $result);
