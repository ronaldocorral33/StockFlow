-- Memoria de conversación: agrupa preguntas sucesivas en un mismo hilo.
-- Aditiva: las filas existentes quedan con conversation_id NULL (conversaciones
-- sueltas del pasado) y siguen siendo válidas para la bitácora.

USE control_inventario;

ALTER TABLE chat_messages
  ADD COLUMN conversation_id CHAR(36) NULL AFTER business_id,
  -- El índice lleva business_id y user_id ADEMÁS del hilo: cargar el historial
  -- siempre se filtra por los tres, para que un conversation_id adivinado no
  -- pueda traer la conversación de otra persona.
  ADD KEY idx_chat_conversation (business_id, user_id, conversation_id, id);
