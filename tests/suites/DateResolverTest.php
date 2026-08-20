<?php
/**
 * Pruebas del resolvedor de fechas.
 *
 * Todas inyectan una fecha de referencia fija. Sin eso, una prueba de "este mes"
 * pasaría hoy y fallaría el día 1 del mes siguiente: el clásico test que se rompe solo.
 */

use App\Services\Agent\DateResolver as DR;

const HOY = '2026-08-19'; // miércoles

test('hoy y ayer', function () {
    $h = DR::resolve('hoy', HOY);
    assertSame('2026-08-19', $h['inicio']);
    assertSame('2026-08-20', $h['fin'], 'el fin es exclusivo');

    $a = DR::resolve('ayer', HOY);
    assertSame('2026-08-18', $a['inicio']);
    assertSame('2026-08-19', $a['fin']);
});

test('este mes y mes pasado', function () {
    $e = DR::resolve('este_mes', HOY);
    assertSame('2026-08-01', $e['inicio']);
    assertSame('2026-09-01', $e['fin']);

    $p = DR::resolve('mes_pasado', HOY);
    assertSame('2026-07-01', $p['inicio']);
    assertSame('2026-08-01', $p['fin']);
});

test('la semana empieza en lunes', function () {
    // 2026-08-19 es miércoles; su lunes es el 17.
    $s = DR::resolve('esta_semana', HOY);
    assertSame('2026-08-17', $s['inicio']);
    assertSame('2026-08-24', $s['fin']);
});

test('últimos N días INCLUYEN hoy', function () {
    // Si hoy es 19 y pides 7 días, el rango es 13–19, no 12–18.
    $s = DR::resolve('ultimos_7_dias', HOY);
    assertSame('2026-08-13', $s['inicio']);
    assertSame('2026-08-20', $s['fin']);

    $t = DR::resolve('ultimos_30_dias', HOY);
    assertSame('2026-07-21', $t['inicio']);
    assertSame('2026-08-20', $t['fin']);
});

test('trimestre actual y anterior', function () {
    // Agosto cae en el tercer trimestre: julio–septiembre.
    $q = DR::resolve('este_trimestre', HOY);
    assertSame('2026-07-01', $q['inicio']);
    assertSame('2026-10-01', $q['fin']);

    $p = DR::resolve('trimestre_pasado', HOY);
    assertSame('2026-04-01', $p['inicio']);
    assertSame('2026-07-01', $p['fin']);
});

test('el trimestre se calcula bien en el borde de enero', function () {
    $q = DR::resolve('este_trimestre', '2026-01-05');
    assertSame('2026-01-01', $q['inicio']);
    $p = DR::resolve('trimestre_pasado', '2026-01-05');
    assertSame('2025-10-01', $p['inicio'], 'debe cruzar al año anterior');
});

test('año actual y anterior', function () {
    $e = DR::resolve('este_ano', HOY);
    assertSame('2026-01-01', $e['inicio']);
    assertSame('2027-01-01', $e['fin']);

    $p = DR::resolve('ano_pasado', HOY);
    assertSame('2025-01-01', $p['inicio']);
    assertSame('2026-01-01', $p['fin']);
});

test('un periodo desconocido se rechaza con la lista de válidos', function () {
    assertSame(null, DR::resolve('la_quincena_pasada', HOY));

    $n = DR::normalize('la_quincena_pasada', null, null, HOY);
    assertSame(false, $n['ok']);
    assertTrue(str_contains($n['error'], 'este_mes'), 'el error debe enseñar las opciones válidas');
});

// ---------------------------------------------------------------
// normalize: el contrato único de fechas
// ---------------------------------------------------------------

test('fechas absolutas pasan tal cual (no rompemos lo que ya funcionaba)', function () {
    // La auditoría confirmó que el modelo ya acertaba con "agosto de 2026".
    $n = DR::normalize(null, '2026-08-01', '2026-09-01', HOY);
    assertTrue($n['ok']);
    assertSame('2026-08-01', $n['inicio']);
    assertSame('2026-09-01', $n['fin']);
});

test('sin periodo ni fechas = todo el historial', function () {
    $n = DR::normalize(null, null, null, HOY);
    assertTrue($n['ok']);
    assertSame(null, $n['inicio'], 'null significa sin filtro de fecha');
    assertSame('todo el historial', $n['etiqueta']);
});

test('una sola fecha se rechaza', function () {
    $n = DR::normalize(null, '2026-08-01', null, HOY);
    assertSame(false, $n['ok']);
});

test('un rango invertido se rechaza', function () {
    $n = DR::normalize(null, '2026-09-01', '2026-08-01', HOY);
    assertSame(false, $n['ok']);
});

test('rechaza fechas mal formadas o inexistentes', function () {
    foreach (['19/08/2026', '2026-8-1', 'agosto', '2026-02-31'] as $mala) {
        $n = DR::normalize(null, $mala, '2026-09-01', HOY);
        assertSame(false, $n['ok'], "debía rechazar: {$mala}");
    }
});

test('el periodo relativo gana sobre fechas sueltas', function () {
    $n = DR::normalize('este_mes', '1990-01-01', '1990-02-01', HOY);
    assertSame('2026-08-01', $n['inicio']);
});

// ---------------------------------------------------------------
// El contexto que se inyecta al prompt
// ---------------------------------------------------------------

test('el contexto de hoy trae la fecha en formato legible y máquina', function () {
    $c = DR::todayContext(HOY);
    assertTrue(str_contains($c, '2026-08-19'), 'la fecha ISO, para que la use en SQL');
    assertTrue(str_contains($c, 'agosto'), 'y en palabras, para que entienda al usuario');
    assertTrue(str_contains($c, '2026'));
});

test('todos los periodos declarados se resuelven de verdad', function () {
    // Protege contra declarar un periodo en PERIODS y olvidar implementarlo:
    // el modelo lo vería como válido y recibiría un error confuso.
    foreach (DR::PERIODS as $p) {
        $r = DR::resolve($p, HOY);
        assertTrue($r !== null, "el periodo declarado '{$p}' no está implementado");
        assertTrue($r['inicio'] < $r['fin'], "el periodo '{$p}' produce un rango vacío");
    }
});
