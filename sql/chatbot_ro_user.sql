-- Usuario MySQL de SOLO LECTURA usado exclusivamente por el chatbot (defensa en profundidad:
-- aunque SqlGuard fallara, este usuario no puede escribir ni leer tablas de cuentas/permisos).
--
-- Reemplaza __CHATBOT_RO_PASSWORD__ por la contraseña real y ponla también en
-- config/config.php bajo db_chatbot_ro.pass. NUNCA subas la contraseña real a git.
--
-- Uso: mysql -u root < sql/chatbot_ro_user.sql   (tras editar el placeholder)

CREATE USER IF NOT EXISTS 'chatbot_ro'@'localhost' IDENTIFIED BY '__CHATBOT_RO_PASSWORD__';

-- Solo SELECT, y solo sobre las 3 tablas de datos de negocio que el chatbot puede consultar.
-- Deliberadamente NO incluye: users, business_users, roles, permissions, role_permissions,
-- business_invitations, audit_log, rate_limits, attribute_definitions ni chat_messages.
GRANT SELECT ON control_inventario.inventory_items TO 'chatbot_ro'@'localhost';
GRANT SELECT ON control_inventario.purchase_orders TO 'chatbot_ro'@'localhost';
GRANT SELECT ON control_inventario.suppliers       TO 'chatbot_ro'@'localhost';

FLUSH PRIVILEGES;

-- Verificación: debe listar exactamente los 3 GRANT SELECT de arriba.
-- SHOW GRANTS FOR 'chatbot_ro'@'localhost';
