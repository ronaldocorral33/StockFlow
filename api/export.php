<?php
require dirname(__DIR__) . '/src/Support/bootstrap.php';

use App\Services\ImportExportService;
use App\Services\Authz;

['business_id' => $businessId] = require_business(true);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Método no soportado'], 405);
}
Authz::require('export', 'inventory_items');

// La exportación deja de ser una lista fija: el cliente puede mandar qué campos
// quiere y sobre qué piezas. Las claves se validan contra el registro dentro del
// servicio, así que aquí no hace falta filtrar.
$fields = isset($_GET['fields']) && $_GET['fields'] !== ''
    ? array_values(array_filter(array_map('trim', explode(',', (string)$_GET['fields']))))
    : null;

$scope = in_array($_GET['scope'] ?? 'all', ['all', 'stock', 'sold'], true)
    ? $_GET['scope']
    : 'all';

// El ID se incluye por omisión porque es lo que permite reimportar y sincronizar sin
// duplicar; se puede omitir cuando el archivo es para leerse, no para volver.
$includeId = ($_GET['id'] ?? '1') !== '0';

json_response([
    'rows' => ImportExportService::exportRows($businessId, $fields, $scope, $includeId),
    'scope' => $scope,
]);
