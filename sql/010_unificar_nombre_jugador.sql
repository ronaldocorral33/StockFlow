-- Unifica el nombre del jugador en el campo personalizado `nombre`.
--
-- Contexto: los registros historicos guardaron este dato en variant_label, mientras
-- que la captura nueva lo guarda en inventory_items.attributes.nombre. Esto hacia
-- que el mismo dato apareciera unas veces debajo de Producto y otras en la columna
-- "Nombre de jugador".
--
-- Reglas de la migracion:
--   1. Solo afecta negocios que tengan activo el campo JSON `nombre`.
--   2. Si attributes.nombre ya tiene valor, ese valor gana y no se sobrescribe.
--   3. En caso contrario, copia variant_label a attributes.nombre.
--   4. Limpia variant_label para evitar que el dato siga apareciendo bajo Producto.
--   5. Conserva un respaldo exacto de cada fila tocada para poder revertirla.
--
-- Es idempotente: correrla de nuevo no duplica respaldos ni vuelve a modificar filas.

USE control_inventario;

CREATE TABLE IF NOT EXISTS migration_010_name_unification_backup (
  inventory_item_id INT UNSIGNED NOT NULL PRIMARY KEY,
  business_id INT UNSIGNED NOT NULL,
  original_variant_label VARCHAR(160) NULL,
  original_attributes LONGTEXT NULL,
  backed_up_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_m010_business (business_id)
) ENGINE=InnoDB;

INSERT IGNORE INTO migration_010_name_unification_backup
  (inventory_item_id, business_id, original_variant_label, original_attributes)
SELECT i.id, i.business_id, i.variant_label, i.attributes
FROM inventory_items i
INNER JOIN attribute_definitions d
  ON d.business_id = i.business_id
 AND d.field_key = 'nombre'
 AND d.storage = 'json'
 AND d.archived_at IS NULL
WHERE NULLIF(TRIM(i.variant_label), '') IS NOT NULL;

UPDATE inventory_items i
INNER JOIN attribute_definitions d
  ON d.business_id = i.business_id
 AND d.field_key = 'nombre'
 AND d.storage = 'json'
 AND d.archived_at IS NULL
SET
  i.attributes = CASE
    WHEN NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(i.attributes, '$.nombre'))), '') IS NOT NULL
      THEN i.attributes
    ELSE JSON_SET(COALESCE(i.attributes, JSON_OBJECT()), '$.nombre', TRIM(i.variant_label))
  END,
  i.variant_label = NULL
WHERE NULLIF(TRIM(i.variant_label), '') IS NOT NULL;

