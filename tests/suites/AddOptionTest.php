<?php
/**
 * Agregar una opción a un campo tipo lista desde la captura.
 *
 * EL PROBLEMA QUE RESUELVE
 * "Las tallas las tengo limitadas hasta 2XL, cuando he vendido y comprado hasta 3XL
 * y 4XL." La lista SIEMPRE se podía editar en "Campos y vistas" — pero uno descubre
 * que falta una talla capturando, y en ese momento la única salida era abandonar la
 * captura. Esto no agrega una talla: agrega la capacidad de agregarlas.
 *
 * POR QUÉ ESTAS PRUEBAS Y NO "guarda un valor"
 * Que un INSERT funcione no es lo dudoso. Lo dudoso son las tres reglas que hacen que
 * esto no ensucie los datos ni pierda trabajo:
 *
 *   · AGREGA, no reemplaza — la captura nunca manda la lista completa, así que no
 *     puede borrar opciones que no conocía.
 *   · No duplica ignorando mayúsculas — "3xl" y "3XL" son la misma talla, y tenerlas
 *     separadas parte cada reporte en dos filas que se leen como una.
 *   · Solo aplica a campos tipo lista, y solo dentro del negocio propio.
 */

use App\Models\AttributeDefinition as AD;

const AO_BIZ = 900978;
const AO_OTRO_BIZ = 900979;
const AO_USER = 900878;

$aoDb = App\Database::connection();
assertIdDePrueba(AO_BIZ);
assertIdDePrueba(AO_OTRO_BIZ);
assertIdDePrueba(AO_USER);

$aoLimpiar = function () use ($aoDb) {
    foreach ([AO_BIZ, AO_OTRO_BIZ] as $b) {
        $aoDb->prepare('DELETE FROM attribute_definitions WHERE business_id = ?')->execute([$b]);
        $aoDb->prepare('DELETE FROM businesses WHERE id = ?')->execute([$b]);
    }
    $aoDb->prepare('DELETE FROM users WHERE id = ?')->execute([AO_USER]);
};
$aoLimpiar();

$aoDb->prepare('INSERT INTO users (id, name, email, password_hash) VALUES (?,?,?,?)')
     ->execute([AO_USER, 'Opciones', 'opciones@test.local', 'x']);
foreach ([AO_BIZ => 'Negocio A', AO_OTRO_BIZ => 'Negocio B'] as $id => $nombre) {
    $aoDb->prepare('INSERT INTO businesses (id, name, owner_user_id) VALUES (?,?,?)')
         ->execute([$id, $nombre, AO_USER]);
}

$aoInsertar = function (int $biz, string $key, string $tipo, ?array $opts) use ($aoDb) {
    $aoDb->prepare(
        'INSERT INTO attribute_definitions (business_id, user_id, field_key, label, field_type, storage, options)
         VALUES (?,?,?,?,?,?,?)'
    )->execute([$biz, AO_USER, $key, ucfirst($key), $tipo, 'json',
                $opts === null ? null : json_encode($opts, JSON_UNESCAPED_UNICODE)]);
};

/** Las opciones que hay HOY guardadas, leídas de la base y no del valor devuelto. */
$aoGuardadas = function (int $biz, string $key) use ($aoDb): ?array {
    $s = $aoDb->prepare('SELECT options FROM attribute_definitions WHERE business_id = ? AND field_key = ?');
    $s->execute([$biz, $key]);
    $raw = $s->fetchColumn();
    return $raw ? json_decode($raw, true) : null;
};

$aoInsertar(AO_BIZ, 'talla', 'select', ['XS', 'S', 'M', 'L', 'XL', '2XL']);
$aoInsertar(AO_BIZ, 'temporada', 'text', null);
$aoInsertar(AO_OTRO_BIZ, 'talla', 'select', ['Único']);

// ---------------------------------------------------------------

test('agrega la opción al final y CONSERVA las que ya estaban', function () use ($aoGuardadas) {
    $r = AD::addOption(AO_BIZ, 'talla', '3XL');

    assertSame(['XS', 'S', 'M', 'L', 'XL', '2XL', '3XL'], $r, 'debió agregarse al final');
    assertSame($r, $aoGuardadas(AO_BIZ, 'talla'), 'y quedar guardada igual que se devolvió');
});

test('agregar dos veces seguidas acumula, no reemplaza', function () use ($aoGuardadas) {
    // La regresión que más importa: si esto reemplazara en vez de agregar, capturar
    // dos tallas nuevas dejaría el campo con una sola opción y todas las piezas ya
    // registradas apuntando a valores que ya no están en la lista.
    AD::addOption(AO_BIZ, 'talla', '4XL');
    assertSame(['XS', 'S', 'M', 'L', 'XL', '2XL', '3XL', '4XL'], $aoGuardadas(AO_BIZ, 'talla'));
});

test('CLAVE: no duplica ignorando mayúsculas y devuelve el valor que ya existía', function () use ($aoGuardadas) {
    $antes = $aoGuardadas(AO_BIZ, 'talla');
    $r = AD::addOption(AO_BIZ, 'talla', '3xl');

    assertSame($antes, $r, 'la lista no debió crecer');
    assertTrue(in_array('3XL', $r, true), 'y debió conservar la forma original, no "3xl"');
});

test('recorta los espacios antes de comparar y de guardar', function () use ($aoGuardadas) {
    $antes = $aoGuardadas(AO_BIZ, 'talla');
    // "  3XL  " es la misma talla escrita con dedos torpes. Si entrara tal cual,
    // sería una segunda opción indistinguible en pantalla de la primera.
    assertSame($antes, AD::addOption(AO_BIZ, 'talla', '  3XL  '));

    AD::addOption(AO_BIZ, 'talla', '  5XL  ');
    assertTrue(in_array('5XL', $aoGuardadas(AO_BIZ, 'talla'), true), 'debió guardarse sin espacios');
});

test('un campo sin opciones todavía arranca la lista desde cero', function () use ($aoInsertar, $aoGuardadas) {
    $aoInsertar(AO_BIZ, 'material', 'select', null);
    assertSame(['Poliéster'], AD::addOption(AO_BIZ, 'material', 'Poliéster'));
    assertSame(['Poliéster'], $aoGuardadas(AO_BIZ, 'material'), 'los acentos deben sobrevivir el JSON');
});

// ---------------------------------------------------------------
// Lo que NO debe poder hacer
// ---------------------------------------------------------------

/** Ejecuta y devuelve el mensaje de la excepción esperada. */
function aoRechaza(callable $fn): string
{
    try {
        $fn();
    } catch (\InvalidArgumentException $e) {
        return $e->getMessage();
    }
    throw new RuntimeException('se esperaba que fuera rechazado, y pasó');
}

test('rechaza un campo que no es lista de opciones', function () {
    // Escribirle options a un campo de texto no rompe nada visible hoy, pero deja el
    // registro afirmando algo falso sobre sí mismo.
    assertTrue(str_contains(aoRechaza(fn() => AD::addOption(AO_BIZ, 'temporada', 'X')), 'no es una lista'));
});

test('rechaza un valor vacío o de puros espacios', function () {
    aoRechaza(fn() => AD::addOption(AO_BIZ, 'talla', ''));
    assertTrue(str_contains(aoRechaza(fn() => AD::addOption(AO_BIZ, 'talla', '   ')), 'vacía'));
});

test('rechaza un valor absurdamente largo', function () {
    assertTrue(str_contains(aoRechaza(fn() => AD::addOption(AO_BIZ, 'talla', str_repeat('X', 61))), 'larga'));
});

test('CLAVE: no puede tocar un campo de otro negocio', function () use ($aoGuardadas) {
    // La clave "talla" existe en los dos negocios. Se resuelve contra el registro del
    // negocio del contexto, así que agregar aquí no puede aparecer allá.
    AD::addOption(AO_BIZ, 'talla', '6XL');
    assertSame(['Único'], $aoGuardadas(AO_OTRO_BIZ, 'talla'), 'el otro negocio no debió cambiar');
});

test('un campo inexistente se rechaza con un mensaje, no con un 500', function () {
    assertTrue(str_contains(aoRechaza(fn() => AD::addOption(AO_BIZ, 'no_existe', 'X')), 'no existe'));
});

test('un campo archivado no acepta opciones nuevas', function () use ($aoDb, $aoInsertar) {
    $aoInsertar(AO_BIZ, 'viejo', 'select', ['A']);
    $aoDb->prepare('UPDATE attribute_definitions SET archived_at = NOW() WHERE business_id = ? AND field_key = ?')
         ->execute([AO_BIZ, 'viejo']);
    assertTrue(str_contains(aoRechaza(fn() => AD::addOption(AO_BIZ, 'viejo', 'B')), 'no existe'));
});

$aoLimpiar();
