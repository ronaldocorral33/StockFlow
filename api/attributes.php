<?php
require dirname(__DIR__) . '/src/Support/bootstrap.php';

use App\Models\AttributeDefinition;
use App\Services\Authz;

['user_id' => $userId, 'business_id' => $businessId] = require_business(true);
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    Authz::require('view', 'attribute_definitions');
    json_response(['attributes' => AttributeDefinition::listForBusiness($businessId)]);
}

if (in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
    csrf_check(true);
}

if ($method === 'POST' && ($_GET['action'] ?? '') === 'reorder') {
    Authz::require('update', 'attribute_definitions');
    $body = json_body();
    AttributeDefinition::reorder($businessId, $body['ids'] ?? []);
    json_response(['ok' => true]);
}

if ($method === 'POST') {
    Authz::require('create', 'attribute_definitions');
    $body = json_body();
    try {
        $id = AttributeDefinition::create($businessId, $userId, $body);
        json_response(['ok' => true, 'id' => $id]);
    } catch (\InvalidArgumentException $e) {
        json_response(['error' => $e->getMessage()], 422);
    }
}

if ($method === 'PUT') {
    Authz::require('update', 'attribute_definitions');
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        json_response(['error' => 'Falta id'], 422);
    }
    AttributeDefinition::update($id, $businessId, json_body());
    json_response(['ok' => true]);
}

if ($method === 'DELETE') {
    Authz::require('delete', 'attribute_definitions');
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        json_response(['error' => 'Falta id'], 422);
    }
    AttributeDefinition::delete($id, $businessId);
    json_response(['ok' => true]);
}

json_response(['error' => 'Método no soportado'], 405);
