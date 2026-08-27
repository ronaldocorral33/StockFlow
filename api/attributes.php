<?php
require dirname(__DIR__) . '/src/Support/bootstrap.php';

use App\Models\AttributeDefinition;
use App\Services\Authz;

/**
 * API del REGISTRO DE CAMPOS: la única fuente de verdad sobre qué campos tiene un
 * negocio y cómo se presentan en cada pantalla.
 *
 * La consumen Entradas, Inventario, Salidas, Exportación, el importador, la pantalla
 * "Campos y vistas" y la capa semántica del asistente. Que todas pasen por aquí es lo
 * que evita que cada archivo vuelva a tener su propia lista de columnas.
 *
 * NINGÚN nombre de columna llega desde el cliente: el cliente manda la CLAVE de un
 * campo o el NOMBRE de una pantalla, y el modelo los resuelve contra sus listas
 * blancas (CONTEXTS, USER_TYPES, ROLES).
 */

['user_id' => $userId, 'business_id' => $businessId] = require_business(true);
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if (in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
    csrf_check(true);
}

// ---------------------------------------------------------------
// Lectura
// ---------------------------------------------------------------

if ($method === 'GET') {
    Authz::require('view', 'attribute_definitions');

    // El registro COMPLETO. Va en un scope aparte para no cambiarle la forma a los
    // consumidores anteriores, que esperan solo los campos personalizados.
    if (($_GET['scope'] ?? '') === 'registry') {
        $incluirArchivados = !empty($_GET['archived']);
        $campos = AttributeDefinition::listRegistry($businessId, $incluirArchivados);
        $llenado = AttributeDefinition::fillRates($businessId);
        $valores = AttributeDefinition::filterValues($businessId);
        $editables = array_flip(array_column(AttributeDefinition::editableFields($businessId), 'field_key'));

        foreach ($campos as $i => $c) {
            $campos[$i]['fill_rate'] = $llenado[$c['field_key']] ?? null;
            $campos[$i]['editable'] = isset($editables[$c['field_key']]);
            $campos[$i]['filter_values'] = $valores[$c['field_key']]['values'] ?? null;
        }

        json_response([
            'fields' => $campos,
            'contexts' => AttributeDefinition::CONTEXTS,
            'types' => AttributeDefinition::USER_TYPES,
            'principal' => AttributeDefinition::productNameField($businessId),
        ]);
    }

    // Cuántas piezas tienen valor en un campo. Se consulta antes de archivar, para
    // poder advertir con un número en vez de una frase genérica.
    if ($action === 'value-count') {
        $id = (int)($_GET['id'] ?? 0);
        json_response(['count' => $id > 0 ? AttributeDefinition::valueCount($id, $businessId) : 0]);
    }

    // Comportamiento histórico: solo los campos personalizados.
    json_response(['attributes' => AttributeDefinition::listForBusiness($businessId)]);
}

// ---------------------------------------------------------------
// Escritura
// ---------------------------------------------------------------

/** Traduce una regla de negocio violada en un 422 legible en vez de un 500. */
function fieldsGuard(callable $fn): void
{
    try {
        $fn();
    } catch (\InvalidArgumentException $e) {
        json_response(['error' => $e->getMessage()], 422);
    }
}

if ($method === 'POST' && $action === 'visibility') {
    // Mostrar u ocultar es configuración del negocio compartida por sus empleados:
    // exige permiso de edición, no solo de lectura.
    Authz::require('update', 'attribute_definitions');
    $body = json_body();
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) {
        json_response(['error' => 'Falta el id del campo'], 422);
    }
    fieldsGuard(fn() => AttributeDefinition::setVisibility(
        $id, $businessId, (string)($body['table'] ?? $body['context'] ?? ''), !empty($body['visible'])
    ));
    json_response(['ok' => true]);
}

if ($method === 'POST' && $action === 'reorder-context') {
    Authz::require('update', 'attribute_definitions');
    $body = json_body();
    fieldsGuard(fn() => AttributeDefinition::reorderContext(
        $businessId, (string)($body['context'] ?? ''), $body['ids'] ?? []
    ));
    json_response(['ok' => true]);
}

if ($method === 'POST' && $action === 'archive') {
    Authz::require('delete', 'attribute_definitions');
    $body = json_body();
    fieldsGuard(fn() => AttributeDefinition::archive((int)($body['id'] ?? 0), $businessId));
    json_response(['ok' => true]);
}

if ($method === 'POST' && $action === 'restore') {
    Authz::require('update', 'attribute_definitions');
    $body = json_body();
    AttributeDefinition::restore((int)($body['id'] ?? 0), $businessId);
    json_response(['ok' => true]);
}

if ($method === 'POST' && $action === 'principal') {
    Authz::require('update', 'attribute_definitions');
    $body = json_body();
    fieldsGuard(fn() => AttributeDefinition::setPrincipal((int)($body['id'] ?? 0), $businessId));
    json_response(['ok' => true]);
}

// Orden histórico de campos personalizados (botones ↑↓ de la pantalla anterior).
if ($method === 'POST' && $action === 'reorder') {
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
        json_response(['ok' => true, 'id' => $id, 'field' => AttributeDefinition::find($id, $businessId)]);
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
    fieldsGuard(fn() => AttributeDefinition::configure($id, $businessId, json_body()));
    json_response(['ok' => true]);
}

if ($method === 'DELETE') {
    // Borrar se reinterpreta como ARCHIVAR: los valores guardados en el JSON de cada
    // pieza se conservan y vuelven intactos al reactivar el campo. Borrarlos de verdad
    // sería una pérdida de datos que el usuario no pidió.
    Authz::require('delete', 'attribute_definitions');
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        json_response(['error' => 'Falta id'], 422);
    }
    fieldsGuard(fn() => AttributeDefinition::archive($id, $businessId));
    json_response(['ok' => true, 'archived' => true]);
}

json_response(['error' => 'Método no soportado'], 405);
