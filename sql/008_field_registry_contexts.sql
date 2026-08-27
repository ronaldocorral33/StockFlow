-- FASE 4 — El registro de campos gobierna las CUATRO pantallas.
--
-- Hasta ahora el registro controlaba la visibilidad y el orden de Inventario
-- (show_in_table / sort_order) y de Salidas (visible_in_sales / sort_order_sales).
-- Entradas y Exportación seguían con listas de columnas escritas en el código.
--
-- Esta migración completa el patrón: cada contexto tiene su propia visibilidad y su
-- propio orden, por la misma razón que se decidió en la fase 3B — cada pantalla
-- responde una pregunta distinta. En Entradas importa lo que se captura; en Salidas,
-- el margen; en Exportación, lo que el usuario quiere llevarse a Excel.
--
-- POR QUÉ COLUMNAS Y NO UN JSON DE VISTAS
-- Un JSON `views` habría evitado cuatro columnas, pero el orden y la visibilidad se
-- usan para ORDENAR y FILTRAR en SQL. Con JSON cada consulta tendría que extraer y
-- convertir, y se perdería el índice. Se mantiene el patrón ya establecido.
--
-- ADEMÁS:
--   · archived_at   — archivar en vez de borrar: los valores del JSON se conservan y
--                     el campo se puede reactivar sin pérdida.
--   · is_editable   — un campo calculado (total_cost, profit) no se puede escribir.
--   · field_type gana 'boolean'.
--
-- IDEMPOTENTE. No borra ni convierte datos.

USE control_inventario;

-- ---------------------------------------------------------------
-- 1. Visibilidad y orden para los dos contextos que faltaban
-- ---------------------------------------------------------------

ALTER TABLE attribute_definitions
  ADD COLUMN IF NOT EXISTS visible_in_entries TINYINT(1) NOT NULL DEFAULT 0 AFTER visible_in_sales,
  ADD COLUMN IF NOT EXISTS visible_in_export  TINYINT(1) NOT NULL DEFAULT 0 AFTER visible_in_entries,
  ADD COLUMN IF NOT EXISTS sort_order_entries INT NOT NULL DEFAULT 0 AFTER sort_order_sales,
  ADD COLUMN IF NOT EXISTS sort_order_export  INT NOT NULL DEFAULT 0 AFTER sort_order_entries,

  -- Archivar en vez de borrar. NULL = activo.
  ADD COLUMN IF NOT EXISTS archived_at DATETIME NULL AFTER analytics_enabled,

  -- Un campo calculado por la base (total_cost, profit) nunca debe poder escribirse.
  -- Se guarda como bandera y no se deduce del tipo para que una pantalla de
  -- configuración futura no pueda volverlo editable por descuido.
  ADD COLUMN IF NOT EXISTS is_editable TINYINT(1) NOT NULL DEFAULT 1 AFTER is_required;

ALTER TABLE attribute_definitions
  MODIFY COLUMN field_type ENUM('text','number','date','select','boolean','computed')
    NOT NULL DEFAULT 'text';

-- Consultar "los campos activos de este negocio" es la operación más frecuente del
-- registro: la hacen las cuatro pantallas, el importador y la capa semántica.
ALTER TABLE attribute_definitions
  ADD KEY IF NOT EXISTS idx_attrdef_active (business_id, archived_at, storage);

-- ---------------------------------------------------------------
-- 2. Siembra: los valores replican EXACTAMENTE lo que hoy se ve
-- ---------------------------------------------------------------
-- El criterio es el mismo de la fase 3B: al empezar a leer el registro, cada pantalla
-- debe verse igual que antes. Cualquier diferencia posterior será una decisión
-- explícita del usuario, no un efecto colateral de esta migración.

-- ENTRADAS. Hoy el formulario de captura tiene: Nombre, los atributos personalizados,
-- Costo y Venta. El envío y el costo total son calculados y no se capturan por pieza;
-- proveedor, pedido y fechas viven en el encabezado del lote, no en cada renglón.
UPDATE attribute_definitions
SET visible_in_entries = 1
WHERE archived_at IS NULL
  AND (
    (storage = 'column' AND field_key IN ('name', 'cost', 'sale_price'))
    OR storage = 'json'
  );

-- El orden de captura: primero el producto, luego los atributos, y al final los
-- importes. Un campo personalizado nuevo se agrega al final de su grupo.
UPDATE attribute_definitions
SET sort_order_entries = CASE field_key
    WHEN 'name'       THEN 1
    WHEN 'cost'       THEN 90
    WHEN 'sale_price' THEN 91
    ELSE sort_order + 10
  END
WHERE archived_at IS NULL;

-- EXPORTACIÓN. Replica las columnas que exportRows() escribía a mano.
UPDATE attribute_definitions
SET visible_in_export = 1
WHERE archived_at IS NULL
  AND (
    (storage = 'column' AND field_key IN (
      'order_number', 'supplier', 'purchase_date', 'arrival_date', 'sale_date',
      'name', 'variant_label', 'category', 'subcategory',
      'cost', 'shipping_cost', 'total_cost', 'sale_price', 'profit'
    ))
    OR storage = 'json'
  );

UPDATE attribute_definitions
SET sort_order_export = CASE field_key
    WHEN 'order_number'  THEN 1
    WHEN 'supplier'      THEN 2
    WHEN 'purchase_date' THEN 3
    WHEN 'arrival_date'  THEN 4
    WHEN 'sale_date'     THEN 5
    WHEN 'name'          THEN 6
    WHEN 'variant_label' THEN 7
    WHEN 'category'      THEN 8
    WHEN 'subcategory'   THEN 9
    WHEN 'cost'          THEN 90
    WHEN 'shipping_cost' THEN 91
    WHEN 'total_cost'    THEN 92
    WHEN 'sale_price'    THEN 93
    WHEN 'profit'        THEN 94
    ELSE sort_order + 10
  END
WHERE archived_at IS NULL;

-- ---------------------------------------------------------------
-- 3. Campos calculados: no editables
-- ---------------------------------------------------------------
-- total_cost y profit son columnas GENERADAS por MariaDB. Escribirlas es un error de
-- SQL, no una decisión de producto.
UPDATE attribute_definitions
SET is_editable = 0
WHERE field_type = 'computed' OR field_key IN ('total_cost', 'profit');

-- sale_date marca una pieza como vendida. Se puede ver y filtrar, pero asignarla
-- fuera del flujo de venta dejaría piezas "vendidas" sin precio, corrompiendo el
-- estado que separa stock de vendido.
UPDATE attribute_definitions
SET is_editable = 0
WHERE storage = 'column' AND field_key = 'sale_date';
