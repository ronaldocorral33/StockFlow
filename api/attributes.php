<?php
require dirname(__DIR__) . '/src/Support/bootstrap.php';

use App\Models\AttributeDefinition;

$userId = require_login(true);
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    json_response(['attributes' => AttributeDefinition::listForUser($userId)]);
}

if (in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
    csrf_check(true);
}

if ($method === 'POST' && ($_GET['action'] ?? '') === 'reorder') {
    $body = json_body();
    AttributeDefinition::reorder($userId, $body['ids'] ?? []);
    json_response(['ok' => true]);
}

if ($method === 'POST') {
    $body = json_body();
    try {
        $id = AttributeDefinition::create($userId, $body);
        json_response(['ok' => true, 'id' => $id]);
    } catch (\InvalidArgumentException $e) {
        json_response(['error' => $e->getMessage()], 422);
    }
}

if ($method === 'PUT') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        json_response(['error' => 'Falta id'], 422);
    }
    AttributeDefinition::update($id, $userId, json_body());
    json_response(['ok' => true]);
}

if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        json_response(['error' => 'Falta id'], 422);
    }
    AttributeDefinition::delete($id, $userId);
    json_response(['ok' => true]);
}

json_response(['error' => 'Método no soportado'], 405);
