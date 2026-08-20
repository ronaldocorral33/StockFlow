-- La capa semántica (SchemaSemantics) necesita leer attribute_definitions para saber
-- qué dimensiones definió cada negocio.
--
-- Se otorga SELECT al usuario de solo lectura en lugar de usar la conexión de
-- escritura para leer metadatos. Así el invariante del agente queda a nivel de
-- CREDENCIAL, no de disciplina de programación: todo lo que el agente toca pasa por
-- una conexión que físicamente no puede escribir, ni por un error futuro.
--
-- attribute_definitions es metadatos de configuración (nombres de campos y etiquetas),
-- no datos sensibles, y siempre se consulta acotada por business_id.

GRANT SELECT ON control_inventario.attribute_definitions TO 'chatbot_ro'@'localhost';
FLUSH PRIVILEGES;
