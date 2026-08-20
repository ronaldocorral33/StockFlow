-- FASE 3B — visibilidad de columnas por tabla.
--
-- En la Fase 3A pospuse esta columna a propósito, con este motivo escrito en
-- sql/006: "no existe hoy ninguna pantalla de ventas con columnas configurables que
-- lo lea; agregarla sería infraestructura sin consumidor". Ese consumidor ya existe:
-- la tabla de Salidas pasa a dibujarse desde el registro y necesita su propia
-- visibilidad, distinta a la de Inventario.
--
-- POR QUÉ DOS BANDERAS Y NO UNA
-- Inventario y Salidas responden preguntas distintas. En Inventario importa el costo
-- y qué tienes; en Salidas importa qué se vendió y con qué margen. Una sola bandera
-- obligaría a mostrar lo mismo en ambas, que es justo la rigidez que estamos
-- quitando.
--
-- show_in_table conserva su nombre (= visible en Inventario) por compatibilidad,
-- como se acordó en 3A: renombrarla obligaría a migrar attributes.js, inventario.js
-- y api/attributes.php sin ningún beneficio.
--
-- IDEMPOTENTE. No borra ni vacía nada.

USE control_inventario;

ALTER TABLE attribute_definitions
  ADD COLUMN IF NOT EXISTS visible_in_sales TINYINT(1) NOT NULL DEFAULT 0 AFTER show_in_table,
  -- El ORDEN también es por tabla, por la misma razón que la visibilidad: Salidas es
  -- un registro cronológico y empieza por la fecha; Inventario es un catálogo y
  -- empieza por el producto. Con un solo sort_order, al empezar a leer el registro la
  -- fecha de venta se habría ido al final de Salidas — un cambio que nadie pidió.
  ADD COLUMN IF NOT EXISTS sort_order_sales INT NOT NULL DEFAULT 0 AFTER sort_order;

-- Valores por omisión: EXACTAMENTE las columnas que la tabla de Salidas dibuja hoy.
-- Así, al empezar a leer el registro, la pantalla se ve igual que antes y cualquier
-- diferencia posterior es una decisión explícita del usuario, no un efecto colateral.
--
-- Los campos personalizados quedan en 0 por omisión (el DEFAULT de la columna): que
-- un negocio haya decidido ver "Talla" en su inventario no implica que la quiera
-- también en el historial de ventas. Se activan desde el selector de columnas.
UPDATE attribute_definitions
SET visible_in_sales = 1
WHERE storage = 'column'
  AND field_key IN ('sale_date', 'order_number', 'name', 'total_cost', 'sale_price', 'profit');

-- Orden de Salidas: EXACTAMENTE el que tenía la tabla antes de leer el registro
-- (fecha, pedido, producto, costo, venta, ganancia). Los campos personalizados que el
-- usuario active después se agregan al final de su propio grupo.
UPDATE attribute_definitions SET sort_order_sales = CASE field_key
    WHEN 'sale_date'    THEN 1
    WHEN 'order_number' THEN 2
    WHEN 'name'         THEN 3
    WHEN 'total_cost'   THEN 4
    WHEN 'sale_price'   THEN 5
    WHEN 'profit'       THEN 6
    ELSE sort_order
  END
WHERE storage = 'column';
