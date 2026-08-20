<?php
require dirname(__DIR__) . '/src/Support/bootstrap.php';

use App\Models\AttributeDefinition;
use App\Services\Authz;

['user_id' => $userId, 'business_id' => $businessId] = require_business(true);
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    Authz::require('view', 'attribute_definitions');

    // El registro COMPLETO (canónicos + personalizados) lo consume el selector de
    // columnas. Va en un scope aparte y no en la respuesta por omisión para no
    // cambiarle la forma a los consumidores que ya existen: attributes.js espera
    // recibir solo los campos personalizados, igual que siempre.
    if (($_GET['scope'] ?? '') === 'registry') {
        $campos = AttributeDefinition::listRegistry($businessId);
        $llenado = AttributeDefinition::fillRates($businessId);
        $valores = AttributeDefinition::filterValues($businessId);

        // La tasa de llenado viaja junto a cada campo para que el selector pueda
        // avisar "esta columna está vacía". Es lo que convierte una lista de casillas
        // en una decisión informada.
        // El frontend necesita saber qué campos se pueden escribir para generar el
        // formulario de edición en lote. La lista la decide el modelo, no el cliente.
        $editables = array_column(AttributeDefinition::editableFields($businessId), 'field_key');
        $editables = array_flip($editables);

        foreach ($campos as $i => $c) {
            $campos[$i]['fill_rate'] = $llenado[$c['field_key']] ?? null;
            $campos[$i]['editable'] = isset($editables[$c['field_key']]);
            $campos[$i]['filter_values'] = $valores[$c['field_key']]['values'] ?? null;
        }
        json_response([
            'fields' => $campos,
            'tables' => array_keys(AttributeDefinition::TABLE_CONFIG),
        ]);
    }

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

// Mostrar u ocultar una columna. Es una acción propia y no parte del PUT genérico
// porque es lo ÚNICO que la Fase 3B permite cambiar: renombrar etiquetas o cambiar
// tipos llega en 3D con su propia validación.
//
// Exige permiso de EDICIÓN de definiciones: la configuración de columnas es del
// negocio y la comparten todos sus empleados, así que no debería poder cambiarla
// cualquiera que solo tenga permiso de lectura.
if ($method === 'POST' && ($_GET['action'] ?? '') === 'visibility') {
    Authz::require('update', 'attribute_definitions');
    $body = json_body();
    $id = (int)($body['id'] ?? 0);
    $tabla = (string)($body['table'] ?? '');
    if ($id <= 0) {
        json_response(['error' => 'Falta el id del campo'], 422);
    }
    try {
        AttributeDefinition::setVisibility($id, $businessId, $tabla, !empty($body['visible']));
    } catch (\InvalidArgumentException $e) {
        json_response(['error' => $e->getMessage()], 422);
    }
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
