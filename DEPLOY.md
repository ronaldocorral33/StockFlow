# Despliegue de StockFlow en cPanel

Guía para publicar StockFlow en un hosting con cPanel. Escrita para el caso concreto
de `laticweb.com` (cuenta `josefr15`), pero sirve igual para cualquier otra cuenta:
solo cambia el prefijo.

**Lo que tienes que tener a la mano** (te los entrega el paquete de despliegue):

| archivo | qué es |
|---|---|
| `stockflow_despliegue.zip` | la aplicación, 66 archivos, sin pruebas ni secretos |
| `stockflow_datos.sql` | tu base de datos completa, lista para importar |
| `env_para_el_servidor.txt` | plantilla del `.env` que vas a crear allá |
| `_diag.php` | diagnóstico de un solo uso, se borra después |
| `opcional_borrar_negocios_de_prueba.sql` | limpieza opcional al final |

---

## 0. Antes de empezar

**Todavía no tienes dominio propio.** `laticweb.com` es la cuenta compartida, y su raíz
está vacía. Vas a crear un **subdominio** tuyo. Dos razones:

- Si subes la aplicación a `public_html/` directamente, ocupas la raíz del dominio
  compartido. Si otra persona de la clase usa la misma cuenta, se pisan.
- Un subdominio te da una carpeta propia y una URL limpia, y no toca nada de lo que ya
  exista.

Vas a terminar con algo como `stockflow.laticweb.com` o `369417.laticweb.com`.

---

## 1. Crear el subdominio

cPanel → **Domains** (en versiones viejas, **Subdomains**) → *Create A Domain*.

- **Domain**: `stockflow.laticweb.com` (o el nombre que quieras)
- Desmarca *"Share document root"* si aparece marcado — quieres carpeta propia.
- **Document Root**: déjalo como te lo proponga, normalmente
  `/home/josefr15/stockflow.laticweb.com`. **Anótalo**, lo vas a necesitar en el paso 5.

Después, cPanel → **SSL/TLS Status** → marca el subdominio → *Run AutoSSL*. Sin HTTPS,
la cookie de sesión viaja sin el flag `secure` (el código lo activa solo cuando detecta
HTTPS) y cualquiera en la misma red puede robarla.

---

## 2. Poner la versión de PHP

cPanel → **MultiPHP Manager** → marca tu subdominio → elige **PHP 8.2** o superior →
*Apply*.

El código usa sintaxis de PHP 8 (`match`, propiedades tipadas, argumentos nombrados).
Con PHP 7.x no arranca: verás una página en blanco o un error 500 sin explicación.

---

## 3. Crear la base de datos y sus dos usuarios

cPanel → **MySQL Databases**.

**La base:** en *Create New Database* escribe `stockflow`. cPanel la va a llamar
`josefr15_stockflow` — **el prefijo lo pone él, no lo escribas tú.** Anota el nombre
completo.

**Usuario 1, el de la aplicación:** en *Add New User*, nombre `sfadmin` (queda
`josefr15_sfadmin`). Usa el generador de contraseñas y **guárdala**, no se vuelve a
mostrar. Luego, en *Add User To Database*, agrégalo a la base y marca **ALL PRIVILEGES**.

**Usuario 2, el del asistente:** repite con nombre `sfro` (queda `josefr15_sfro`), otra
contraseña, y al agregarlo a la base marca **solo `SELECT`**. Nada más.

> **Diferencia contra tu instalación local, y es a propósito que la sepas.**
> En XAMPP, `chatbot_ro` tiene permiso de lectura sobre **tres tablas** concretas
> (`inventory_items`, `purchase_orders`, `suppliers`) y ninguna más — ni siquiera puede
> ver la tabla de usuarios. cPanel no permite permisos por tabla, solo por base
> completa, así que en el servidor el usuario del asistente podrá *leer* toda la base.
>
> Sigue sin poder **escribir** nada, y `SqlGuard` sigue siendo el candado principal
> (valida la consulta y le impone el `business_id` antes de ejecutarla). Pero la
> segunda capa queda más floja que en local. Si algún día tienes acceso `root` al
> MySQL del servidor, corre `sql/chatbot_ro_user.sql` ajustando el nombre de la base y
> recuperas los permisos por tabla.

---

## 4. Importar los datos

cPanel → **phpMyAdmin** → en la lista de la izquierda haz clic en `josefr15_stockflow`
**antes de nada** (si no seleccionas la base, el import no sabe dónde meter las tablas)
→ pestaña **Import**.

- *Archivo a importar*: `stockflow_datos.sql`
- **Juego de caracteres del archivo: `utf8mb4`.** ← Esto no es un detalle.

> Es exactamente el error que ya rompió los acentos una vez en este proyecto. Si
> importas declarando `latin1`, el servidor recibe bytes UTF-8 y los vuelve a
> codificar: `Categoría` se guarda como `Categor├¡a`, en la base, para siempre. No se
> nota hasta que abres la pantalla de campos. El paso 7 lo verifica.

Al terminar debe decir algo como *"Importación ejecutada con éxito, 15 consultas"*.

---

## 5. Subir la aplicación

cPanel → **File Manager** → entra al Document Root que anotaste en el paso 1.

1. **Upload** → `stockflow_despliegue.zip`
2. Vuelve a la carpeta, clic derecho en el zip → **Extract**
3. Verifica que `index.php` quedó **directamente** en la carpeta, no dentro de otra
   carpeta `stockflow/`. Si quedó anidado, mueve el contenido un nivel arriba.
4. Borra el `.zip`.

---

## 6. Crear el archivo de credenciales

En File Manager, **Settings** (arriba a la derecha) → marca **Show Hidden Files
(dotfiles)** → *Save*. Sin esto no vas a poder ver ni editar el archivo después.

**+ File** → nombre exactamente `.env` (con el punto, sin extensión) → créalo → clic
derecho → **Edit**.

Pega el contenido de `env_para_el_servidor.txt` y llena:

- `DB_NAME`, `DB_USER`, `DB_PASS` → los del paso 3
- `DB_CHATBOT_USER`, `DB_CHATBOT_PASS` → los del usuario 2
- Las llaves de IA → **cópialas de tu `.env` local**, son las mismas

Deja `APP_ENV=production`. Guarda.

> El `.env` nunca se sube desde tu computadora: el tuyo apunta a XAMPP. Y el
> `.htaccess` ya bloquea el acceso web a ese archivo — si alguien pide
> `tusubdominio/.env` recibe un 403.

---

## 7. Verificar antes de cantar victoria

Sube `_diag.php` a la misma carpeta y ábrelo en el navegador:
`https://stockflow.laticweb.com/_diag.php`

Debe salir todo en `OK`:

```
OK     PHP 8.2.x
OK     extensión pdo_mysql / mbstring / curl / json
OK     conexión a la base de datos
OK     datos importados: 1599 piezas, 4 usuarios
OK     acentos en la base
OK     APP_ENV=production
OK     salida HTTPS a internet
```

Qué hacer si alguno falla:

| línea | qué significa | cómo se arregla |
|---|---|---|
| `PHP` | versión vieja | paso 2 |
| `extensión` | falta un módulo | *Select PHP Version → Extensions*, o pídelo a soporte |
| `conexión` | credenciales mal | revisa el prefijo `josefr15_` en `DB_NAME` y `DB_USER` |
| `acentos` | importaste con el charset equivocado | borra las tablas y repite el paso 4 con `utf8mb4` |
| `APP_ENV` | quedó en development | edita el `.env` |
| `salida HTTPS` | el hosting bloquea salir a internet | todo funciona **menos** la pestaña Asistente |

**Borra `_diag.php` en cuanto termines.** No expone secretos, pero no tiene por qué
estar ahí.

---

## 8. Entrar

`https://stockflow.laticweb.com` → entra con **a369417@uach.mx** y tu contraseña de
siempre. Deben aparecer tus 750 piezas y 42 pedidos.

Recorre las cinco pestañas antes de darlo por bueno: Entradas, Pedidos, Salidas,
Inventario y Reportes.

---

## 9. Cierre

1. **Opcional:** corre `opcional_borrar_negocios_de_prueba.sql` en phpMyAdmin para
   dejar solo tu negocio. Hazlo **después** de confirmar que entraste bien.
2. **Cambia la contraseña de cPanel** (*Password & Security*). La actual quedó escrita
   en el chat.
3. Si más adelante cambias código: vuelves a subir solo los archivos tocados. El
   `.env` y la base de datos **no** se tocan.

---

## Notas

**Rutas:** el código calcula su propia URL base desde `DOCUMENT_ROOT`, así que funciona
igual en la raíz de un dominio o en una subcarpeta. No hay nada que configurar.

**Migraciones futuras:** los archivos de `sql/` traen `USE control_inventario;`
escrito. En el servidor tu base se llama distinto, así que **borra o cambia esa línea**
antes de correr cualquier migración nueva en phpMyAdmin. El volcado del paso 4 ya viene
sin ella, por eso funciona con cualquier nombre.

**Qué NO se sube, y por qué:** `.env` (secretos), `.git/` (el historial completo,
incluidos archivos que ya borraste), `tests/`, `sql/` (el esquema entero de la base),
`scripts/` (`eval_agente.php` gasta dinero llamando al proveedor de IA), `backups/` y
los `.xlsx` (datos reales del negocio).
