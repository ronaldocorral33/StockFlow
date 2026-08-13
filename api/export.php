<?php
require dirname(__DIR__) . '/src/Support/bootstrap.php';

use App\Services\ImportExportService;

$userId = require_login(true);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Método no soportado'], 405);
}

json_response(['rows' => ImportExportService::exportRows($userId)]);
