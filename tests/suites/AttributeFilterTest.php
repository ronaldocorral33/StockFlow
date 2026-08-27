<?php
/**
 * Pruebas del filtro por campo del registro.
 *
 * Es el consumidor que le faltaba a la bandera `filterable`, y el que hace posible
 * corregir un atributo mal capturado: primero hay que poder AISLAR las piezas
 * equivocadas, y hasta ahora no se podía.
 *
 * Lo que más importa probar es que el nombre de la columna nunca salga de la petición:
 * el filtro recibe claves que vienen del navegador y las convierte en SQL.
 */

use App\Models\AttributeDefinition as AD;
use App\Models\InventoryItem as I;

const AF_BIZ = 1;

test('filtra por un atributo personalizado', function () {
    // Este negocio tiene temporadas; la prueba no fija cuál, la toma de los datos.
    $valores = AD::filterValues(AF_BIZ);
    assertTrue(isset($valores['temporada']), 'debe haber valores de temporada para filtrar');

    $valor = $valores['temporada']['values'][0]['valor'];
    $esperado = $valores['temporada']['values'][0]['n'];

    $filtradas = I::list(AF_BIZ, ['attrs' => ['temporada' => $valor]]);
    assertSame($esperado, count($filtradas), "filtrar por {$valor} debe dar las piezas que ese valor reporta");

    foreach ($filtradas as $r) {
        assertSame($valor, $r['attributes']['temporada'] ?? null, 'toda fila debe cumplir el filtro');
    }
});

test('filtra por un campo canónico', function () {
    $valores = AD::filterValues(AF_BIZ);
    $nombre = $valores['name']['values'][0]['valor'];
    $n = $valores['name']['values'][0]['n'];

    $filtradas = I::list(AF_BIZ, ['attrs' => ['name' => $nombre]]);
    assertSame($n, count($filtradas));
    foreach ($filtradas as $r) {
        assertSame($nombre, $r['name']);
    }
});

test('combina varios filtros (el caso real: producto + temporada)', function () {
    // "Las jerseys del America con la temporada equivocada."
    $nombre = AD::filterValues(AF_BIZ)['name']['values'][0]['valor'];
    $temporada = AD::filterValues(AF_BIZ)['temporada']['values'][0]['valor'];

    $ambos = I::list(AF_BIZ, ['attrs' => ['name' => $nombre, 'temporada' => $temporada]]);
    $soloNombre = I::list(AF_BIZ, ['attrs' => ['name' => $nombre]]);

    assertTrue(count($ambos) <= count($soloNombre), 'agregar un filtro solo puede reducir');
    foreach ($ambos as $r) {
        assertSame($nombre, $r['name']);
        assertSame($temporada, $r['attributes']['temporada'] ?? null);
    }
});

test('el filtro alcanza también a las piezas YA VENDIDAS', function () {
    // Es la mitad que faltaba: un atributo mal capturado también está mal en lo
    // vendido, y si el filtro solo trajera stock no habría forma de corregirlo.
    $temporada = AD::filterValues(AF_BIZ)['temporada']['values'][0]['valor'];
    $todas = I::list(AF_BIZ, ['attrs' => ['temporada' => $temporada]]);
    $vendidas = array_filter($todas, fn($r) => $r['sale_date'] !== null);
    assertTrue(count($vendidas) > 0, 'con estos datos debe haber vendidas en ese grupo');
});

// ---------------------------------------------------------------
// Seguridad: la clave viene del navegador
// ---------------------------------------------------------------

test('SEGURIDAD: un campo que no está en el registro se IGNORA', function () {
    $todas = count(I::list(AF_BIZ, []));
    // Si el nombre de columna se interpolara, esto rompería el SQL o filtraría de más.
    $r = count(I::list(AF_BIZ, ['attrs' => ['password' => 'x', 'campo_inventado' => 'y']]));
    assertSame($todas, $r, 'un campo desconocido no debe filtrar ni reventar');
});

test('SEGURIDAD: no se puede filtrar por un campo marcado como NO filtrable', function () {
    $db = App\Database::connection();
    // Se apaga temporalmente la bandera de un campo y se comprueba que se respeta.
    $campo = null;
    foreach (AD::listRegistry(AF_BIZ) as $f) {
        if ($f['field_key'] === 'temporada') { $campo = $f; break; }
    }
    $db->prepare('UPDATE attribute_definitions SET filterable = 0 WHERE id = ?')->execute([$campo['id']]);

    $todas = count(I::list(AF_BIZ, []));
    $r = count(I::list(AF_BIZ, ['attrs' => ['temporada' => '2025/2026']]));
    assertSame($todas, $r, 'la bandera filterable debe respetarse');

    $db->prepare('UPDATE attribute_definitions SET filterable = 1 WHERE id = ?')->execute([$campo['id']]);
});

test('SEGURIDAD: el valor va enlazado, no concatenado', function () {
    // Si se concatenara, esta cadena devolvería TODO el inventario.
    $r = I::list(AF_BIZ, ['attrs' => ['temporada' => "' OR '1'='1"]]);
    assertSame(0, count($r), 'no debe encontrar nada, ni traer todo');
});

test('SEGURIDAD: una clave con SQL adentro no llega a la consulta', function () {
    $todas = count(I::list(AF_BIZ, []));
    $r = count(I::list(AF_BIZ, ['attrs' => ["temporada'; DROP TABLE users; --" => 'x']]));
    assertSame($todas, $r, 'la clave no está en el registro, así que se ignora');

    // Y la tabla sigue ahí.
    $n = App\Database::connection()->query('SELECT COUNT(*) FROM users')->fetchColumn();
    assertTrue((int)$n > 0, 'users debe seguir existiendo');
});

test('un valor vacío no filtra', function () {
    $todas = count(I::list(AF_BIZ, []));
    assertSame($todas, count(I::list(AF_BIZ, ['attrs' => ['temporada' => '']])));
    assertSame($todas, count(I::list(AF_BIZ, ['attrs' => []])));
});

// ---------------------------------------------------------------
// Los valores de los desplegables
// ---------------------------------------------------------------

test('los valores ofrecidos existen de verdad en los datos', function () {
    // Ofrecer un valor que no existe llevaría a una tabla vacía sin explicación.
    foreach (AD::filterValues(AF_BIZ) as $key => $info) {
        foreach (array_slice($info['values'], 0, 3) as $v) {
            $r = I::list(AF_BIZ, ['attrs' => [$key => $v['valor']]]);
            assertSame($v['n'], count($r), "el valor '{$v['valor']}' de {$key} debe traer {$v['n']} piezas");
        }
    }
});

test('los valores nunca incluyen vacíos', function () {
    foreach (AD::filterValues(AF_BIZ) as $key => $info) {
        foreach ($info['values'] as $v) {
            assertTrue(trim($v['valor']) !== '', "{$key} ofreció un valor vacío");
        }
    }
});

test('un campo sin datos no aparece entre los filtros', function () {
    // category está vacía: ofrecerla como filtro solo llevaría a resultados vacíos.
    $valores = AD::filterValues(AF_BIZ);
    assertSame(false, isset($valores['category']), 'category no debe ofrecerse');
});

test('un campo con demasiados valores se omite en vez de recortarse', function () {
    // Recortar la lista haría creer que no hay más opciones. Se prefiere omitir el
    // desplegable y dejar que el usuario use el buscador.
    foreach (AD::filterValues(AF_BIZ) as $key => $info) {
        assertTrue(
            count($info['values']) <= AD::MAX_FILTER_VALUES,
            "{$key} devolvió más valores que el tope"
        );
    }
});

test('AISLAMIENTO: los valores de filtro son solo del negocio', function () {
    $db = App\Database::connection();
    $limpiar = function () use ($db) {
        $db->prepare('DELETE FROM inventory_items WHERE business_id = ?')->execute([900913]);
        $db->prepare('DELETE FROM attribute_definitions WHERE business_id = ?')->execute([900913]);
        $db->prepare('DELETE FROM businesses WHERE id = ?')->execute([900913]);
        $db->prepare('DELETE FROM users WHERE id = ?')->execute([900813]);
    };
    $limpiar();
    $db->prepare('INSERT INTO users (id, name, email, password_hash) VALUES (?,?,?,?)')
       ->execute([900813, 'Aislado F', 'aislado-filtro@test.local', 'x']);
    $db->prepare('INSERT INTO businesses (id, name, owner_user_id) VALUES (?,?,?)')
       ->execute([900913, 'Otro negocio', 900813]);
    AD::seedCanonical(900913, 900813);
    InventoryItem_afCrear($db);

    $valores = AD::filterValues(900913);
    // Solo debe ver su propia pieza, no las 738 del negocio 1.
    assertTrue(isset($valores['name']), 'debe tener valores propios');
    assertSame(1, count($valores['name']['values']));
    assertSame('Pieza Aislada', $valores['name']['values'][0]['valor']);

    $limpiar();
});

/** Crea una pieza en el negocio aislado. */
function InventoryItem_afCrear(PDO $db): void
{
    $db->prepare('INSERT INTO inventory_items (business_id, user_id, name, cost) VALUES (?,?,?,?)')
       ->execute([900913, 900813, 'Pieza Aislada', 10]);
}
