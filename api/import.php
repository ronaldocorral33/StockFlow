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

// PASO 1 del flujo: el cliente manda los encabezados del archivo y recibe una
// propuesta de mapeo. No se escribe nada; es solo para que el usuario confirme.
if (($_GET['action'] ?? '') === 'analyze') {
    $headers = $body['headers'] ?? [];
    if (!is_array($headers) || !$headers) {
        json_response(['error' => 'El archivo no trae encabezados.'], 422);
    }
    json_response([
        'ok' => true,
        'mapping' => ImportExportService::suggestMapping($businessId, $headers),
        'fields' => array_map(
            fn($f) => ['field_key' => $f['field_key'], 'label' => $f['label'], 'storage' => $f['storage']],
            App\Models\AttributeDefinition::editableFields($businessId)
        ),
    ]);
}

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

// El mapeo confirmado por el usuario. Si no viene, se conserva el camino automático
// para no romper las importaciones que ya funcionaban.
$mapping = is_array($body['mapping'] ?? null) ? $body['mapping'] : null;

$result = ImportExportService::importRows($businessId, $userId, $rows, $mode, $dryRun, $mapping);
json_response(['ok' => true] + $result);
