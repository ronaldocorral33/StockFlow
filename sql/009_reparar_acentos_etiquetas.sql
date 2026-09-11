-- Reparación de etiquetas con acentos rotos (mojibake) en el registro de campos.
--
-- QUÉ PASÓ
-- Tres etiquetas canónicas se ven así en la pantalla "Campos y vistas":
--
--     Categor├¡a     Subcategor├¡a     Env├¡o
--
-- Los bytes lo explican. "Categoría" correcta termina en:  ...72 C3AD 61
-- y en los negocios afectados está guardada como:          ...72 E2949C C2A1 61
--
-- C3 AD son los dos bytes UTF-8 de "í". Leídos como CP437 (la página de códigos de
-- la consola de Windows) esos dos bytes se ven como "├" y "¡", y al volver a
-- guardarse en UTF-8 se convirtieron en E2949C y C2A1. Es una DOBLE codificación.
--
-- POR QUÉ NO ES UN ERROR DE CÓDIGO
-- El código está bien y no hay nada que arreglar en PHP:
--   · sql/006_field_registry.sql tiene 'Categoría' en UTF-8 correcto (C3 AD).
--   · config/config.php declara 'charset' => 'utf8mb4' en la conexión PDO.
--   · Prueba definitiva: los negocios creados DESPUÉS, cuyas etiquetas las siembra
--     AttributeDefinition::seedCanonical() a través de esa conexión PDO, tienen las
--     tres etiquetas perfectas.
--
-- El daño lo hizo la EJECUCIÓN de la migración 006, una sola vez, desde un cliente
-- que declaró latin1 en vez de utf8mb4. El servidor recibió bytes UTF-8 y le
-- creyó al cliente que eran latin1, así que los volvió a codificar.
--
--     Correcto:  mysql --default-character-set=utf8mb4 -u root < sql/006_....sql
--     Lo que se hizo:  mysql -u root < sql/006_....sql
--
-- POR QUÉ ESTE ARCHIVO ESCRIBE HEX Y NO TEXTO
-- Si aquí se escribiera  SET label = 'Categoría'  este archivo tendría exactamente
-- el mismo problema que vino a reparar: ejecutado con el charset equivocado,
-- volvería a guardar la etiqueta rota. Con UNHEX() los bytes que llegan a la
-- columna son los mismos sin importar qué charset declare el cliente, así que la
-- reparación es correcta incluso ejecutada mal.
--
-- POR QUÉ COMPARA BYTES EXACTOS Y NO UN PATRÓN
-- La condición es el valor roto COMPLETO, byte por byte. Eso hace dos cosas:
--   · Si alguien renombró "Categoría" a "Tipo de producto", esta migración no lo
--     toca. Un patrón amplio (LIKE '%├%') sí podría pisarle una etiqueta suya.
--   · La hace IDEMPOTENTE por construcción: una vez reparada, la fila ya no
--     coincide con la condición y la segunda ejecución actualiza 0 filas.
--
-- ALCANCE VERIFICADO ANTES DE ESCRIBIRLA: 9 filas (3 campos × 3 negocios).
-- Cero filas de inventory_items afectadas — el daño está solo en las etiquetas,
-- no en los datos capturados. Esas tres son las únicas etiquetas canónicas con
-- acento, así que la reparación es completa, no parcial.

USE control_inventario;

UPDATE attribute_definitions
   SET label = CONVERT(UNHEX('43617465676F72C3AD61') USING utf8mb4)          -- Categoría
 WHERE field_key = 'category'
   AND HEX(label) = '43617465676F72E2949CC2A161';                            -- Categor├¡a

UPDATE attribute_definitions
   SET label = CONVERT(UNHEX('53756263617465676F72C3AD61') USING utf8mb4)    -- Subcategoría
 WHERE field_key = 'subcategory'
   AND HEX(label) = '53756263617465676F72E2949CC2A161';                      -- Subcategor├¡a

UPDATE attribute_definitions
   SET label = CONVERT(UNHEX('456E76C3AD6F') USING utf8mb4)                  -- Envío
 WHERE field_key = 'shipping_cost'
   AND HEX(label) = '456E76E2949CC2A16F';                                    -- Env├¡o
