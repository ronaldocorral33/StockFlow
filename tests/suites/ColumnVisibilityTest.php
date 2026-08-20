<?php
/**
 * Pruebas de la visibilidad de columnas (Fase 3B).
 *
 * Lo importante aquí no es que una casilla guarde un 1 o un 0, sino que las dos
 * tablas tengan visibilidad INDEPENDIENTE y que el aviso de "sin datos" diga la
 * verdad: ése es el que convierte el selector en una decisión informada en vez de
 * prueba y error.
 */

use App\Models\AttributeDefinition as AD;

const CV_BIZ = 1;

/** Un campo del registro por su clave. */
function cvField(string $key): array
{
    foreach (AD::listRegistry(CV_BIZ) as $f) {
        if ($f['field_key'] === $key) { return $f; }
    }
    throw new RuntimeException("no existe el campo {$key}");
}

// ---------------------------------------------------------------
// Independencia de las dos tablas
// ---------------------------------------------------------------

test('las dos tablas tienen visibilidad independiente', function () {
    $f = cvField('cost');
    $invAntes = $f['show_in_table'];
    $salAntes = $f['visible_in_sales'];

    // Se cambia SOLO en Salidas; Inventario no debe moverse.
    AD::setVisibility((int)$f['id'], CV_BIZ, 'sales', !$salAntes);
    $d = cvField('cost');
    assertSame(!$salAntes, $d['visible_in_sales'], 'Salidas debió cambiar');
    assertSame($invAntes, $d['show_in_table'], 'Inventario NO debía cambiar');

    AD::setVisibility((int)$f['id'], CV_BIZ, 'sales', $salAntes);
});

test('la visibilidad por omisión de Salidas replica sus columnas de antes', function () {
    // Al empezar a leer el registro, la pantalla debía verse igual que antes. Si esto
    // falla, la migración cambió la interfaz sin que nadie lo pidiera.
    $esperadas = ['sale_date', 'order_number', 'name', 'total_cost', 'sale_price', 'profit'];
    foreach ($esperadas as $k) {
        assertTrue(cvField($k)['visible_in_sales'], "{$k} debía venir visible en Salidas");
    }
});

test('una tabla desconocida se rechaza', function () {
    $id = (int)cvField('name')['id'];
    $rechazado = false;
    try {
        AD::setVisibility($id, CV_BIZ, 'reportes', true);
    } catch (\InvalidArgumentException $e) {
        $rechazado = true;
    }
    assertTrue($rechazado, 'el nombre de columna sale de una lista blanca, no del argumento');
});

test('un campo CANÓNICO sí se puede ocultar (es reversible y no toca datos)', function () {
    // A diferencia de delete(), ocultar no destruye nada: es la operación que resuelve
    // la queja original de las columnas vacías.
    $f = cvField('category');
    $antes = $f['show_in_table'];

    AD::setVisibility((int)$f['id'], CV_BIZ, 'inventory', true);
    assertTrue(cvField('category')['show_in_table']);
    AD::setVisibility((int)$f['id'], CV_BIZ, 'inventory', false);
    assertSame(false, cvField('category')['show_in_table']);

    AD::setVisibility((int)$f['id'], CV_BIZ, 'inventory', (bool)$antes);
});

// ---------------------------------------------------------------
// El aviso de "sin datos"
// ---------------------------------------------------------------

test('las tasas de llenado detectan las columnas realmente vacías', function () {
    $r = AD::fillRates(CV_BIZ);
    // category y subcategory están vacías en las 738 piezas: es la queja original.
    assertSame(0.0, $r['category'], 'category debe reportarse vacía');
    assertSame(0.0, $r['subcategory'], 'subcategory debe reportarse vacía');
    // name está poblada en todas.
    assertSame(1.0, $r['name'], 'name debe reportarse completa');
});

test('las tasas cubren campos canónicos Y personalizados', function () {
    $r = AD::fillRates(CV_BIZ);
    $registro = AD::listRegistry(CV_BIZ);
    foreach ($registro as $f) {
        assertTrue(
            array_key_exists($f['field_key'], $r),
            "falta la tasa de {$f['field_key']}: el selector no podría avisar sobre ella"
        );
        assertTrue($r[$f['field_key']] >= 0 && $r[$f['field_key']] <= 1, 'debe ser una proporción');
    }
});

test('la tasa concuerda con un conteo directo', function () {
    // Se verifica contra otra forma de calcular lo mismo, no contra un número fijo:
    // así sigue valiendo cuando el dueño registre más piezas.
    $r = AD::fillRates(CV_BIZ);
    $db = App\Database::connection();
    $total = (int)$db->query('SELECT COUNT(*) FROM inventory_items WHERE business_id = ' . CV_BIZ)->fetchColumn();
    $conNombre = (int)$db->query(
        "SELECT COUNT(*) FROM inventory_items WHERE business_id = " . CV_BIZ . " AND name IS NOT NULL AND TRIM(name) <> ''"
    )->fetchColumn();
    assertSame(round($conNombre / $total, 4), $r['name']);
});

test('un negocio SIN piezas no reporta todo como vacío', function () {
    // Sería engañoso: en un negocio que apenas empieza, "sin datos" no significa que
    // el campo sea inútil, solo que aún no hay inventario.
    $db = App\Database::connection();
    $db->prepare('DELETE FROM attribute_definitions WHERE business_id = ?')->execute([909]);
    $db->prepare('DELETE FROM businesses WHERE id = ?')->execute([909]);
    $db->prepare('DELETE FROM users WHERE id = ?')->execute([809]);
    $db->prepare('INSERT INTO users (id, name, email, password_hash) VALUES (?,?,?,?)')
       ->execute([809, 'Vacio', 'vacio-cols@test.local', 'x']);
    $db->prepare('INSERT INTO businesses (id, name, owner_user_id) VALUES (?,?,?)')
       ->execute([909, 'Negocio sin piezas', 809]);
    AD::seedCanonical(909, 809);

    assertSame([], AD::fillRates(909), 'sin piezas no se afirma nada');

    $db->prepare('DELETE FROM attribute_definitions WHERE business_id = ?')->execute([909]);
    $db->prepare('DELETE FROM businesses WHERE id = ?')->execute([909]);
    $db->prepare('DELETE FROM users WHERE id = ?')->execute([809]);
});

// ---------------------------------------------------------------
// Aislamiento
// ---------------------------------------------------------------

test('AISLAMIENTO: no se puede cambiar la visibilidad de otro negocio', function () {
    $id = (int)cvField('name')['id'];
    $antes = cvField('name')['show_in_table'];

    // Se intenta con el business_id equivocado: el WHERE debe impedirlo.
    AD::setVisibility($id, 999, 'inventory', !$antes);

    assertSame($antes, cvField('name')['show_in_table'], 'el campo del negocio 1 no debió cambiar');
});

// ---------------------------------------------------------------
// Cordura de la configuración resultante
// ---------------------------------------------------------------

test('cada tabla tiene al menos una columna visible', function () {
    // Una tabla sin columnas sería una pantalla inservible. Hoy nada lo impide a nivel
    // de datos, así que esto vigila que la configuración sembrada sea usable.
    foreach (['show_in_table', 'visible_in_sales'] as $flag) {
        $n = 0;
        foreach (AD::listRegistry(CV_BIZ) as $f) {
            if ($f[$flag]) { $n++; }
        }
        assertTrue($n > 0, "la tabla con bandera {$flag} quedó sin columnas");
    }
});

test('el campo product_name está visible en ambas tablas', function () {
    // Una tabla de inventario o de ventas sin el nombre del producto no se puede leer.
    $p = AD::productNameField(CV_BIZ);
    assertTrue($p['show_in_table'], 'debe verse en Inventario');
    assertTrue($p['visible_in_sales'], 'y en Salidas');
});
