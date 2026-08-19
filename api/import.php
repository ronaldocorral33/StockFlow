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

// Actualizar filas existentes es editar inventario, no solo crearlo: se exige el
// permiso de edición ADEMÁS del de creación. Un rol que solo puede dar de alta
// piezas no debería poder reescribir el inventario entero desde un Excel.
$mode = ($body['mode'] ?? '') === ImportExportService::MODE_SYNC
    ? ImportExportService::MODE_SYNC
    : ImportExportService::MODE_ADD;
if ($mode === ImportExportService::MODE_SYNC) {
    Authz::require('update', 'inventory_items');
}

$dryRun = !empty($body['dry_run']);

$result = ImportExportService::importRows($businessId, $userId, $rows, $mode, $dryRun);
json_response(['ok' => true] + $result);
