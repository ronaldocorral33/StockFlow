<?php
/**
 * Pruebas de la memoria de conversación.
 *
 * Dos cosas distintas que probar, con dos estrategias distintas:
 *
 * 1. isValidId() es lógica pura (una regex): se prueba sin base de datos, con muchos
 *    casos, porque es barato y es la primera línea de defensa contra un id inventado.
 *
 * 2. messagesFor() toca la base de datos, así que la prueba inserta filas de mentira
 *    dentro de una TRANSACCIÓN y hace rollback al final. Así se prueba contra el motor
 *    real (el filtro WHERE de verdad, no una simulación) sin dejar basura en los datos.
 *    Esa es la prueba que importa de seguridad: que un conversation_id ajeno NO traiga
 *    la conversación de otra persona.
 */

use App\Database;
use App\Services\ChatHistory;

// ---------------------------------------------------------------
// 1. Validación de formato (sin base de datos)
// ---------------------------------------------------------------

test('acepta un UUID v4 bien formado', function () {
    assertTrue(ChatHistory::isValidId('3f8a1c2e-9b4d-4a71-8e0f-2c5d7a1b9e33'));
});

test('acepta mayúsculas (los navegadores no siempre normalizan)', function () {
    assertTrue(ChatHistory::isValidId('3F8A1C2E-9B4D-4A71-8E0F-2C5D7A1B9E33'));
});

test('rechaza null y cadena vacía', function () {
    assertSame(false, ChatHistory::isValidId(null));
    assertSame(false, ChatHistory::isValidId(''));
});

test('rechaza formatos que no son UUID', function () {
    foreach ([
        'hola',
        '12345',
        '3f8a1c2e9b4d4a718e0f2c5d7a1b9e33',              // sin guiones
        '3f8a1c2e-9b4d-4a71-8e0f-2c5d7a1b9e3',           // un dígito de menos
        '3f8a1c2e-9b4d-4a71-8e0f-2c5d7a1b9e333',         // uno de más
        'zzzzzzzz-9b4d-4a71-8e0f-2c5d7a1b9e33',          // fuera de hexadecimal
    ] as $bad) {
        assertSame(false, ChatHistory::isValidId($bad), "debía rechazar: {$bad}");
    }
});

test('rechaza un id con SQL pegado (no llega ni a la consulta)', function () {
    assertSame(false, ChatHistory::isValidId("3f8a1c2e-9b4d-4a71-8e0f-2c5d7a1b9e33' OR '1'='1"));
});

// ---------------------------------------------------------------
// 2. Aislamiento real contra la base de datos
// ---------------------------------------------------------------

$db = Database::connection();
$db->beginTransaction();

// Usuarios y negocios de mentira. Hacen falta porque chat_messages tiene llaves
// foráneas: la base NO deja escribir un mensaje de un usuario que no existe.
// Eso ya es una prueba en sí — la integridad referencial no es opcional.
$db->prepare('INSERT INTO users (id, name, email, password_hash) VALUES (?,?,?,?)')
   ->execute([900801, 'Prueba A', 'prueba-a@test.local', 'x']);
$db->prepare('INSERT INTO users (id, name, email, password_hash) VALUES (?,?,?,?)')
   ->execute([900802, 'Prueba B', 'prueba-b@test.local', 'x']);
$db->prepare('INSERT INTO users (id, name, email, password_hash) VALUES (?,?,?,?)')
   ->execute([900803, 'Prueba C', 'prueba-c@test.local', 'x']);
$db->prepare('INSERT INTO businesses (id, name, owner_user_id) VALUES (?,?,?)')
   ->execute([900901, 'Negocio A', 900801]);
$db->prepare('INSERT INTO businesses (id, name, owner_user_id) VALUES (?,?,?)')
   ->execute([900902, 'Negocio B', 900802]);

// Dos hilos de dos dueños distintos, con el MISMO id de conversación a propósito:
// así se prueba que el filtro por negocio/usuario es lo que separa, no el id.
$SHARED_ID = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
$OTHER_ID  = 'ffffffff-1111-4222-8333-444444444444';

$ins = $db->prepare(
    'INSERT INTO chat_messages (business_id, user_id, conversation_id, question, answer)
     VALUES (?, ?, ?, ?, ?)'
);
$ins->execute([900901, 900801, $SHARED_ID, 'primera del negocio A', 'respuesta A1']);
$ins->execute([900901, 900801, $SHARED_ID, 'segunda del negocio A', 'respuesta A2']);
$ins->execute([900902, 900802, $SHARED_ID, 'pregunta del negocio B', 'respuesta B1']);
$ins->execute([900901, 900801, $OTHER_ID,  'otro hilo del mismo dueño', 'respuesta otra']);

test('trae el hilo del dueño, en orden cronológico y como pares user/assistant', function () use ($SHARED_ID) {
    $m = ChatHistory::messagesFor(900901, 900801, $SHARED_ID);
    assertSame(4, count($m), 'dos intercambios = cuatro mensajes');
    assertSame('user', $m[0]['role']);
    assertSame('primera del negocio A', $m[0]['content']);
    assertSame('assistant', $m[1]['role']);
    assertSame('respuesta A1', $m[1]['content']);
    assertSame('segunda del negocio A', $m[2]['content'], 'el más reciente va al final');
});

test('AISLAMIENTO: el mismo conversation_id no cruza de negocio', function () use ($SHARED_ID) {
    // El negocio B usó el MISMO id. Si el filtro fuera solo por conversation_id,
    // aquí se filtrarían las preguntas del negocio A.
    $m = ChatHistory::messagesFor(900902, 900802, $SHARED_ID);
    assertSame(2, count($m));
    assertSame('pregunta del negocio B', $m[0]['content']);
});

test('AISLAMIENTO: adivinar el id de otro usuario no trae nada', function () use ($SHARED_ID) {
    // El tercer usuario no tiene nada en ese hilo, aunque el id sea correcto.
    assertSame([], ChatHistory::messagesFor(900901, 900803, $SHARED_ID));
});

test('hilos distintos del mismo dueño no se mezclan', function () use ($OTHER_ID) {
    $m = ChatHistory::messagesFor(900901, 900801, $OTHER_ID);
    assertSame(2, count($m));
    assertSame('otro hilo del mismo dueño', $m[0]['content']);
});

test('un id con formato inválido ni siquiera consulta: devuelve vacío', function () {
    assertSame([], ChatHistory::messagesFor(900901, 900801, 'no-es-uuid'));
    assertSame([], ChatHistory::messagesFor(900901, 900801, null));
});

test('respeta MAX_EXCHANGES: no crece sin límite', function () use ($db) {
    $id = 'cccccccc-dddd-4eee-8fff-000000000000';
    $ins = $db->prepare(
        'INSERT INTO chat_messages (business_id, user_id, conversation_id, question, answer)
         VALUES (?, ?, ?, ?, ?)'
    );
    for ($i = 1; $i <= 10; $i++) {
        $ins->execute([900901, 900801, $id, "pregunta {$i}", "respuesta {$i}"]);
    }
    $m = ChatHistory::messagesFor(900901, 900801, $id);
    assertSame(ChatHistory::MAX_EXCHANGES * 2, count($m), 'solo los últimos intercambios');
    assertSame('pregunta 8', $m[0]['content'], 'debe quedarse con los MÁS RECIENTES');
    assertSame('respuesta 10', $m[5]['content']);
});

test('ignora filas sin respuesta (preguntas que fallaron a medias)', function () use ($db) {
    $id = '11111111-2222-4333-8444-555555555555';
    $db->prepare('INSERT INTO chat_messages (business_id, user_id, conversation_id, question, answer) VALUES (?,?,?,?,?)')
       ->execute([900901, 900801, $id, 'ésta sí respondió', 'ok']);
    $db->prepare('INSERT INTO chat_messages (business_id, user_id, conversation_id, question, answer) VALUES (?,?,?,?,NULL)')
       ->execute([900901, 900801, $id, 'ésta quedó sin respuesta']);
    $m = ChatHistory::messagesFor(900901, 900801, $id);
    assertSame(2, count($m), 'un intercambio completo, no dos');
    assertSame('ésta sí respondió', $m[0]['content']);
});

$db->rollBack(); // nada de esto queda en la base
