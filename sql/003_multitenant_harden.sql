-- Paso final de endurecimiento: correr SOLO después de que
-- scripts/migrate_to_multitenant.php confirme cero business_id NULL en las 5 tablas.
-- Voltea los UNIQUE de user_id a business_id y fuerza NOT NULL.

USE control_inventario;

-- Cada tabla necesita un índice propio sobre user_id (no compuesto) antes de poder
-- soltar el índice único viejo, porque el FK fk_*_user se apoya en él.
ALTER TABLE attribute_definitions
  ADD KEY idx_attrdef_user_id (user_id),
  MODIFY business_id INT UNSIGNED NOT NULL,
  DROP INDEX uq_attrdef_user_key,
  ADD UNIQUE KEY uq_attrdef_business_key (business_id, field_key);

ALTER TABLE suppliers
  ADD KEY idx_supplier_user_id (user_id),
  MODIFY business_id INT UNSIGNED NOT NULL,
  DROP INDEX uq_supplier_user_name,
  ADD UNIQUE KEY uq_supplier_business_name (business_id, name);

ALTER TABLE purchase_orders
  ADD KEY idx_po_user_id (user_id),
  MODIFY business_id INT UNSIGNED NOT NULL,
  DROP INDEX uq_po_user_ordernum,
  ADD UNIQUE KEY uq_po_business_ordernum (business_id, order_number);

-- inventory_items/chat_messages: MariaDB 10.4 rechaza un MODIFY directo sobre una
-- columna con FK cuando la tabla tiene columnas GENERADAS (caso de inventory_items,
-- total_cost/profit) — se quita la FK, se modifica, y se vuelve a crear.
ALTER TABLE inventory_items DROP FOREIGN KEY fk_items_business;
ALTER TABLE inventory_items MODIFY business_id INT UNSIGNED NOT NULL;
ALTER TABLE inventory_items
  ADD CONSTRAINT fk_items_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE;

ALTER TABLE chat_messages DROP FOREIGN KEY fk_chat_business;
ALTER TABLE chat_messages MODIFY business_id INT UNSIGNED NOT NULL;
ALTER TABLE chat_messages
  ADD CONSTRAINT fk_chat_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE;
