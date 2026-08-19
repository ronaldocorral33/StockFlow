<?php
namespace App\Services;

if (!defined('APP_BOOTSTRAP')) { http_response_code(403); exit('Forbidden'); }

use App\Database;

/**
 * Memoria de conversación: permite preguntas de seguimiento ("¿y en junio?").
 *
 * TRES DECISIONES DE DISEÑO, cada una con su razón:
 *
 * 1. QUÉ se reenvía: solo pregunta + respuesta final. NO los tool_calls ni los
 *    tool_results. Esos resultados pueden traer 20 filas de JSON cada uno; reenviarlos
 *    multiplicaría el costo de cada pregunta por el largo de la conversación. La
 *    respuesta final ya contiene la información destilada, que es lo que el modelo
 *    necesita para entender el contexto.
 *
 * 2. CUÁNTO se conserva: los últimos MAX_EXCHANGES intercambios. El contexto tiene que
 *    estar acotado por diseño, no por suerte: sin tope, una conversación larga crece
 *    hasta reventar la ventana del modelo (y el presupuesto) sin avisar.
 *
 * 3. CÓMO se aísla: el historial SIEMPRE se filtra por negocio + usuario + hilo. El
 *    conversation_id viene del cliente, así que es un dato NO confiable: si solo se
 *    filtrara por él, adivinar un id ajeno dejaría leer la conversación de otra persona.
 *
 * Sobre resumir en vez de truncar: todavía no vale la pena. Resumir cuesta una llamada
 * extra al modelo y agrega una fuente de error (el resumen puede perder o distorsionar
 * datos). Con preguntas cortas de negocio, quedarse con los últimos intercambios cubre
 * el caso real de seguimiento. Se justificaría si aparecieran conversaciones largas
 * donde el inicio siga siendo relevante.
 */
class ChatHistory
{
    /** Cuántos pares pregunta/respuesta previos se reenvían al modelo. */
    public const MAX_EXCHANGES = 3;

    /** Un conversation_id válido es un UUID v4 generado por el cliente. */
    public static function isValidId(?string $id): bool
    {
        return is_string($id)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) === 1;
    }

    /**
     * Mensajes previos del hilo, listos para anteponer a la pregunta actual.
     *
     * @return array [{role:'user'|'assistant', content:string}, ...] en orden cronológico
     */
    public static function messagesFor(int $businessId, int $userId, ?string $conversationId): array
    {
        if (!self::isValidId($conversationId)) {
            return [];
        }

        $stmt = Database::connection()->prepare(
            'SELECT question, answer FROM chat_messages
             WHERE business_id = ? AND user_id = ? AND conversation_id = ?
               AND answer IS NOT NULL
             ORDER BY id DESC
             LIMIT ' . (int)self::MAX_EXCHANGES
        );
        $stmt->execute([$businessId, $userId, $conversationId]);
        $rows = array_reverse($stmt->fetchAll()); // de vuelta a orden cronológico

        $messages = [];
        foreach ($rows as $row) {
            $messages[] = ['role' => 'user', 'content' => $row['question']];
            $messages[] = ['role' => 'assistant', 'content' => $row['answer']];
        }
        return $messages;
    }
}
