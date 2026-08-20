-- FASE 3A — attribute_definitions se convierte en el REGISTRO DE CAMPOS del negocio.
--
-- QUÉ CAMBIA CONCEPTUALMENTE
-- Hasta hoy esta tabla significaba "campos personalizados que viven en el JSON".
-- A partir de aquí significa "campos que este negocio tiene", y la columna nueva
-- `storage` dice DÓNDE vive el valor de cada uno:
--
--   storage = 'json'    → el valor está en inventory_items.attributes  (como hasta hoy)
--   storage = 'column'  → el valor está en una columna de inventory_items
--
-- POR QUÉ UNA SOLA TABLA Y NO DOS
-- Con dos tablas habría dos fuentes de verdad sobre "qué campos tiene este negocio",
-- que es exactamente el problema que esta fase viene a eliminar. Con una, la capa
-- semántica hace una consulta y un recorrido, y el futuro importador solo necesita
-- mapear una columna de Excel a un field_key: `storage` le dice sola si debe escribir
-- una columna o una clave del JSON.
--
-- TRES NIVELES DE CAMPO (y solo dos entran aquí)
--   A) INTERNOS      — id, business_id, user_id, supplier_id, purchase_order_id,
--                      created_at, updated_at. NO se registran: son integridad del
--                      sistema, no vocabulario del negocio. Nadie debe poder
--                      ocultarlos ni renombrarlos, ni por accidente.
--   B) CANÓNICOS     — se registran con storage='column'.
--   C) PERSONALIZADOS— se registran con storage='json' (las filas que ya existían).
--
-- ESTA MIGRACIÓN NO CAMBIA NINGÚN COMPORTAMIENTO VISIBLE.
-- Solo agrega metadatos y siembra filas. Ningún consumidor las lee todavía:
-- AttributeDefinition::listForBusiness() conserva su significado actual (solo 'json'),
-- así que el frontend, el importador y el agente siguen viendo exactamente lo mismo.
--
-- ES IDEMPOTENTE: se puede correr varias veces sin duplicar nada. Las columnas usan
-- IF NOT EXISTS y la siembra usa INSERT IGNORE, que se apoya en la clave única ya
-- existente uq_attrdef_business_key (business_id, field_key).
--
-- NO BORRA NI VACÍA NADA. category, subcategory y variant_label se siembran OCULTAS,
-- no se eliminan: que un negocio no las use no prueba que otro vertical no las
-- necesite. Ocultar es reversible con un UPDATE; borrar una columna no lo es.

USE control_inventario;

-- ---------------------------------------------------------------
-- 1. Metadatos nuevos
-- ---------------------------------------------------------------

ALTER TABLE attribute_definitions
  -- Dónde vive el valor del campo.
  ADD COLUMN IF NOT EXISTS storage ENUM('json','column') NOT NULL DEFAULT 'json' AFTER field_type,

  -- Rol semántico. Por ahora SOLO se usa 'product_name': marca cuál campo es el
  -- identificador principal del producto. Hoy varias piezas de código lo asumen
  -- (SchemaSemantics lo llama "Producto", Analytics::sinStock usa 'name' por omisión,
  -- el importador descarta filas sin nombre); esto lo hace explícito y configurable.
  -- NO se agregan brand/category/variant todavía: un rol sin consumidor es una
  -- columna que se desincroniza en silencio.
  -- La unicidad de 'product_name' por negocio se valida en la aplicación
  -- (AttributeDefinition), no con un índice: un índice único sobre una columna
  -- nullable con varios NULL complicaría la migración sin ganar nada real.
  ADD COLUMN IF NOT EXISTS semantic_role VARCHAR(30) NULL AFTER storage,

  -- ¿Se puede filtrar el inventario por este campo? (lo consumirá la Fase 3B)
  ADD COLUMN IF NOT EXISTS filterable TINYINT(1) NOT NULL DEFAULT 1 AFTER show_in_table,

  -- ¿Se ofrece como dimensión al agente analítico? (lo consumirá la Fase 3C)
  ADD COLUMN IF NOT EXISTS analytics_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER filterable;

-- POSPUESTO A PROPÓSITO: visible_in_sales.
-- No existe hoy ninguna pantalla de ventas con columnas configurables que lo lea.
-- Agregar la columna ahora sería infraestructura sin consumidor: quedaría con su
-- valor por omisión y nadie sabría si es correcta. Se agregará junto con la pantalla
-- que la use.
--
-- COMPATIBILIDAD: show_in_table NO se renombra. Conceptualmente es
-- "visible_in_inventory", pero renombrarla obligaría a migrar attributes.js,
-- inventario.js y api/attributes.php en esta misma fase, sin ningún beneficio.
-- El nombre nuevo, si hace falta, llega cuando la Fase 3B toque esos archivos.

-- El tipo 'computed' es para campos DERIVADOS que no se capturan: total_cost y profit
-- son columnas generadas por MariaDB. Marcarlas así evita que una futura pantalla de
-- configuración las ofrezca como editables.
ALTER TABLE attribute_definitions
  MODIFY COLUMN field_type ENUM('text','number','date','select','computed') NOT NULL DEFAULT 'text';

-- ---------------------------------------------------------------
-- 2. Siembra de los campos canónicos para TODOS los negocios
-- ---------------------------------------------------------------
--
-- show_in_table replica EXACTAMENTE las 9 columnas que hoy dibuja FIXED_COLS en
-- inventario.js, con dos excepciones deliberadas y aprobadas:
--
--   · category y subcategory se siembran OCULTAS. Están vacías en las 739 piezas y
--     ocupaban espacio en la tabla sin aportar. Cuando la Fase 3B lea el registro,
--     dejarán de mostrarse — es el primer beneficio visible del cambio.
--   · variant_label se siembra OCULTA porque hoy no es una columna: se dibuja como
--     subtítulo dentro de la celda del nombre. La Fase 3B debe decidir si conserva
--     ese subtítulo o la promueve a columna propia; sembrarla visible ahora
--     duplicaría el dato.
--
-- sort_order va 1..14 dentro del grupo canónico. No choca con el 1..6 de los campos
-- personalizados porque el orden se resuelve POR GRUPO (canónicos y luego
-- personalizados), que es como se dibuja hoy. Si alguna vez se quiere intercalar un
-- campo personalizado entre dos canónicos, habrá que renumerar a un espacio único.

INSERT IGNORE INTO attribute_definitions
    (business_id, user_id, field_key, label, field_type, storage, semantic_role,
     options, is_required, show_in_table, filterable, analytics_enabled, sort_order)
SELECT
    b.id, b.owner_user_id, c.field_key, c.label, c.field_type, 'column', c.semantic_role,
    NULL, c.is_required, c.show_in_table, c.filterable, c.analytics_enabled, c.sort_order
FROM businesses b
CROSS JOIN (
    --          field_key         label                field_type  semantic_role   req vis filt anal ord
    SELECT 'name'          AS field_key, 'Producto'          AS label, 'text'     AS field_type, 'product_name' AS semantic_role, 1 AS is_required, 1 AS show_in_table, 1 AS filterable, 1 AS analytics_enabled,  1 AS sort_order
    UNION ALL SELECT 'variant_label',    'Variante',                   'text',                   NULL,                            0,                0,                 1,               1,                     2
    UNION ALL SELECT 'category',         'Categoría',                  'text',                   NULL,                            0,                0,                 1,               1,                     3
    UNION ALL SELECT 'subcategory',      'Subcategoría',               'text',                   NULL,                            0,                0,                 1,               1,                     4
    -- 'supplier' y 'order_number' se leen por JOIN (suppliers.name,
    -- purchase_orders.order_number). Su ETIQUETA y VISIBILIDAD son configurables;
    -- CÓMO se obtiene el valor es plomería interna, no vocabulario del negocio.
    UNION ALL SELECT 'supplier',         'Proveedor',                  'text',                   NULL,                            0,                0,                 1,               1,                     5
    UNION ALL SELECT 'order_number',     'Pedido',                     'text',                   NULL,                            0,                1,                 1,               1,                     6
    UNION ALL SELECT 'cost',             'Costo',                      'number',                 NULL,                            0,                1,                 0,               0,                     7
    UNION ALL SELECT 'shipping_cost',    'Envío',                      'number',                 NULL,                            0,                1,                 0,               0,                     8
    UNION ALL SELECT 'total_cost',       'Costo total',                'computed',               NULL,                            0,                1,                 0,               0,                     9
    UNION ALL SELECT 'sale_price',       'Venta',                      'number',                 NULL,                            0,                1,                 0,               0,                    10
    UNION ALL SELECT 'profit',           'Ganancia',                   'computed',               NULL,                            0,                1,                 0,               0,                    11
    UNION ALL SELECT 'purchase_date',    'Fecha de compra',            'date',                   NULL,                            0,                0,                 1,               0,                    12
    UNION ALL SELECT 'arrival_date',     'Fecha de llegada',           'date',                   NULL,                            0,                0,                 1,               0,                    13
    -- sale_date define el estado (NULL = en stock). Es estructural: se registra para
    -- poder etiquetarla y filtrar por ella, pero nunca debería poder desactivarse.
    UNION ALL SELECT 'sale_date',        'Fecha de venta',             'date',                   NULL,                            0,                0,                 1,               0,                    14
) c;
