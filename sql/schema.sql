-- StockFlow — esquema base de datos (v1, pre-multitenant).
-- Motor: MariaDB 10.4 (XAMPP)
--
-- IMPORTANTE: este archivo es el esquema original. Para una instalación nueva, corre EN ORDEN:
--   1) sql/schema.sql              (este archivo)
--   2) sql/002_multitenant.sql     (negocios, roles, permisos, auditoría, rate limiting)
--   3) scripts/migrate_to_multitenant.php   (crea un negocio por usuario existente + backfill)
--   4) sql/003_multitenant_harden.sql       (NOT NULL + UNIQUE por negocio)
--   5) sql/chatbot_ro_user.sql     (usuario MySQL de solo lectura para el chatbot)

CREATE DATABASE IF NOT EXISTS control_inventario
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE control_inventario;

-- ============================================================
-- 1. USUARIOS
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  currency_default ENUM('MXN','USD') NOT NULL DEFAULT 'MXN',
  exchange_rate_default DECIMAL(10,4) NOT NULL DEFAULT 1.0000,
  onboarded_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB;

-- ============================================================
-- 2. DEFINICIONES DE ATRIBUTOS (campos personalizados por usuario)
-- ============================================================
CREATE TABLE IF NOT EXISTS attribute_definitions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  field_key VARCHAR(60) NOT NULL,
  label VARCHAR(120) NOT NULL,
  field_type ENUM('text','number','date','select') NOT NULL DEFAULT 'text',
  options JSON NULL,
  is_required TINYINT(1) NOT NULL DEFAULT 0,
  show_in_table TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_attrdef_user_key (user_id, field_key),
  CONSTRAINT fk_attrdef_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- 3. PROVEEDORES
-- ============================================================
CREATE TABLE IF NOT EXISTS suppliers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL,
  notes VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_supplier_user_name (user_id, name),
  CONSTRAINT fk_supplier_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- 4. PEDIDOS DE COMPRA (lote / "Pedido")
-- ============================================================
CREATE TABLE IF NOT EXISTS purchase_orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  order_number INT NOT NULL,
  supplier_id INT UNSIGNED NULL,
  purchase_date DATE NULL,
  arrival_date DATE NULL,
  currency ENUM('MXN','USD') NOT NULL DEFAULT 'MXN',
  exchange_rate DECIMAL(10,4) NOT NULL DEFAULT 1.0000,
  shipping_total_original DECIMAL(12,2) NOT NULL DEFAULT 0,
  shipping_total_mxn DECIMAL(12,2) NOT NULL DEFAULT 0,
  item_count INT UNSIGNED NOT NULL DEFAULT 0,
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_po_user_ordernum (user_id, order_number),
  KEY idx_po_user (user_id),
  CONSTRAINT fk_po_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_po_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- 5. PIEZAS DE INVENTARIO (una fila = una unidad física)
-- ============================================================
CREATE TABLE IF NOT EXISTS inventory_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  purchase_order_id INT UNSIGNED NULL,
  supplier_id INT UNSIGNED NULL,
  name VARCHAR(160) NOT NULL,
  variant_label VARCHAR(160) NULL,
  category VARCHAR(100) NULL,
  subcategory VARCHAR(100) NULL,
  attributes JSON NULL,
  cost DECIMAL(12,2) NULL,
  shipping_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
  sale_price DECIMAL(12,2) NULL,
  purchase_date DATE NULL,
  arrival_date DATE NULL,
  sale_date DATE NULL,
  total_cost DECIMAL(12,2)
    GENERATED ALWAYS AS (ROUND(IFNULL(cost,0) + IFNULL(shipping_cost,0))) STORED,
  profit DECIMAL(12,2)
    GENERATED ALWAYS AS (
      CASE WHEN sale_price IS NULL THEN NULL
           ELSE ROUND(sale_price - IFNULL(cost,0) - IFNULL(shipping_cost,0))
      END
    ) STORED,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_items_user (user_id),
  KEY idx_items_user_sale (user_id, sale_date),
  KEY idx_items_user_po (user_id, purchase_order_id),
  KEY idx_items_user_category (user_id, category),
  KEY idx_items_name (name),
  CONSTRAINT fk_items_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_items_po FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE SET NULL,
  CONSTRAINT fk_items_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- 6. MENSAJES DEL CHATBOT (bitácora)
-- ============================================================
CREATE TABLE IF NOT EXISTS chat_messages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  question TEXT NOT NULL,
  generated_sql TEXT NULL,
  sql_valid TINYINT(1) NULL,
  rejection_reason VARCHAR(255) NULL,
  is_projection TINYINT(1) NOT NULL DEFAULT 0,
  row_count INT UNSIGNED NULL,
  answer TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_chat_user (user_id, created_at),
  CONSTRAINT fk_chat_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
