-- Migración multi-tenant: negocios, roles, permisos, auditoría, rate limiting.
-- Puramente aditiva: no modifica ni renombra ninguna columna existente.
-- Después de correr esto, ejecutar scripts/migrate_to_multitenant.php para el backfill,
-- y solo al final sql/003_multitenant_harden.sql para endurecer NOT NULL/UNIQUE.

USE control_inventario;

-- ============================================================
-- ROLES (catálogo fijo)
-- ============================================================
CREATE TABLE IF NOT EXISTS roles (
  id TINYINT UNSIGNED PRIMARY KEY,
  role_key VARCHAR(30) NOT NULL,
  label VARCHAR(60) NOT NULL,
  UNIQUE KEY uq_roles_key (role_key)
) ENGINE=InnoDB;

INSERT IGNORE INTO roles (id, role_key, label) VALUES
  (1, 'administrador', 'Administrador'),
  (2, 'gerente', 'Gerente'),
  (3, 'vendedor', 'Vendedor'),
  (4, 'almacenista', 'Almacenista'),
  (5, 'analista', 'Analista');

-- ============================================================
-- PERMISOS (catálogo fijo) + matriz por rol
-- ============================================================
CREATE TABLE IF NOT EXISTS permissions (
  id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  resource VARCHAR(40) NOT NULL,
  action VARCHAR(20) NOT NULL,
  UNIQUE KEY uq_perm (resource, action)
) ENGINE=InnoDB;

INSERT IGNORE INTO permissions (resource, action) VALUES
  ('inventory_items','view'), ('inventory_items','create'), ('inventory_items','update'),
  ('inventory_items','delete'), ('inventory_items','sell'), ('inventory_items','export'),
  ('purchase_orders','view'), ('purchase_orders','create'), ('purchase_orders','update'), ('purchase_orders','delete'),
  ('suppliers','view'), ('suppliers','create'), ('suppliers','update'), ('suppliers','delete'),
  ('attribute_definitions','view'), ('attribute_definitions','create'),
  ('attribute_definitions','update'), ('attribute_definitions','delete'),
  ('reports','view'), ('reports','export'),
  ('chat','use'),
  ('business','view'), ('business','update'),
  ('business_users','view'), ('business_users','invite'), ('business_users','update'), ('business_users','delete');

CREATE TABLE IF NOT EXISTS role_permissions (
  role_id TINYINT UNSIGNED NOT NULL,
  permission_id SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id),
  CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions(id)
) ENGINE=InnoDB;

-- Administrador (1): todo.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
  SELECT 1, id FROM permissions;

-- Gerente (2): todo excepto administrar la cuenta del negocio y crear/editar otros administradores
-- (esa distinción de "no puede tocar admins" se aplica en código, no en esta tabla).
INSERT IGNORE INTO role_permissions (role_id, permission_id)
  SELECT 2, id FROM permissions WHERE NOT (resource = 'business' AND action = 'update');

-- Vendedor (3): ver + vender inventario, ver compras/proveedores, usar chatbot.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
  SELECT 3, id FROM permissions WHERE
    (resource='inventory_items' AND action IN ('view','sell')) OR
    (resource='purchase_orders' AND action='view') OR
    (resource='suppliers' AND action='view') OR
    (resource='attribute_definitions' AND action='view') OR
    (resource='reports' AND action='view') OR
    (resource='chat' AND action='use');

-- Almacenista (4): captura/edita inventario y compras, sin vender ni borrar.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
  SELECT 4, id FROM permissions WHERE
    (resource='inventory_items' AND action IN ('view','create','update')) OR
    (resource='purchase_orders' AND action IN ('view','create','update')) OR
    (resource='suppliers' AND action IN ('view','create','update')) OR
    (resource='attribute_definitions' AND action='view') OR
    (resource='reports' AND action='view');

-- Analista (5): solo lectura + exportar + chatbot.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
  SELECT 5, id FROM permissions WHERE
    (resource='inventory_items' AND action IN ('view','export')) OR
    (resource='purchase_orders' AND action IN ('view','export')) OR
    (resource='suppliers' AND action='view') OR
    (resource='attribute_definitions' AND action='view') OR
    (resource='reports' AND action IN ('view','export')) OR
    (resource='chat' AND action='use');

-- ============================================================
-- NEGOCIOS
-- ============================================================
CREATE TABLE IF NOT EXISTS businesses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  business_type_id INT UNSIGNED NULL,
  currency_default ENUM('MXN','USD') NOT NULL DEFAULT 'MXN',
  exchange_rate_default DECIMAL(10,4) NOT NULL DEFAULT 1.0000,
  owner_user_id INT UNSIGNED NOT NULL,
  deleted_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_business_owner (owner_user_id),
  CONSTRAINT fk_business_owner FOREIGN KEY (owner_user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS business_users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  business_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  role_id TINYINT UNSIGNED NOT NULL,
  status ENUM('active','invited','disabled') NOT NULL DEFAULT 'active',
  invited_by INT UNSIGNED NULL,
  joined_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_bu_business_user (business_id, user_id),
  KEY idx_bu_user (user_id),
  CONSTRAINT fk_bu_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
  CONSTRAINT fk_bu_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_bu_role FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS business_invitations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  business_id INT UNSIGNED NOT NULL,
  email VARCHAR(190) NOT NULL,
  role_id TINYINT UNSIGNED NOT NULL,
  token VARCHAR(64) NOT NULL,
  invited_by INT UNSIGNED NOT NULL,
  expires_at DATETIME NOT NULL,
  accepted_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_invite_token (token),
  KEY idx_invite_business (business_id),
  CONSTRAINT fk_invite_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
  CONSTRAINT fk_invite_role FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB;

-- ============================================================
-- AUDITORÍA
-- ============================================================
CREATE TABLE IF NOT EXISTS audit_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  business_id INT UNSIGNED NOT NULL,
  actor_user_id INT UNSIGNED NOT NULL,
  entity_type VARCHAR(40) NOT NULL,
  entity_id INT UNSIGNED NOT NULL,
  action VARCHAR(20) NOT NULL,
  changes JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_business (business_id, created_at),
  KEY idx_audit_entity (entity_type, entity_id)
) ENGINE=InnoDB;

-- ============================================================
-- RATE LIMITING (sin dependencias externas)
-- ============================================================
CREATE TABLE IF NOT EXISTS rate_limits (
  bucket_key VARCHAR(120) NOT NULL,
  window_start DATETIME NOT NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (bucket_key, window_start)
) ENGINE=InnoDB;

-- ============================================================
-- business_id NULLABLE en las 5 tablas existentes (aditivo, no rompe nada)
-- ============================================================
ALTER TABLE attribute_definitions
  ADD COLUMN business_id INT UNSIGNED NULL AFTER user_id,
  ADD CONSTRAINT fk_attrdef_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE;

ALTER TABLE suppliers
  ADD COLUMN business_id INT UNSIGNED NULL AFTER user_id,
  ADD CONSTRAINT fk_supplier_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE;

ALTER TABLE purchase_orders
  ADD COLUMN business_id INT UNSIGNED NULL AFTER user_id,
  ADD CONSTRAINT fk_po_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE;

ALTER TABLE inventory_items
  ADD COLUMN business_id INT UNSIGNED NULL AFTER user_id,
  ADD CONSTRAINT fk_items_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE;

ALTER TABLE chat_messages
  ADD COLUMN business_id INT UNSIGNED NULL AFTER user_id,
  ADD CONSTRAINT fk_chat_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE;

-- Índices líderes por business_id (se usan en paralelo a los de user_id durante la transición).
ALTER TABLE attribute_definitions ADD KEY idx_attrdef_business (business_id);
ALTER TABLE suppliers ADD KEY idx_supplier_business (business_id);
ALTER TABLE purchase_orders ADD KEY idx_po_business (business_id);
ALTER TABLE inventory_items
  ADD KEY idx_items_business (business_id),
  ADD KEY idx_items_business_sale (business_id, sale_date),
  ADD KEY idx_items_business_category (business_id, category),
  ADD KEY idx_items_business_name (business_id, name);
ALTER TABLE chat_messages ADD KEY idx_chat_business (business_id, created_at);
