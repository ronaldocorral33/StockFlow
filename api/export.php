<?php
require dirname(__DIR__) . '/src/Support/bootstrap.php';

use App\Services\ImportExportService;
use App\Services\Authz;

['business_id' => $businessId] = require_business(true);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Método no soportado'], 405);
}
Authz::require('export', 'inventory_items');

json_response(['rows' => ImportExportService::exportRows($businessId)]);
