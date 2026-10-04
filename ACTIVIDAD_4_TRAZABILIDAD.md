# Actividad 4 — Trazabilidad del desarrollo y publicación de StockFlow

**Alumno(a):** [Escribe tu nombre]  
**Fecha de entrega:** [dd/mm/aaaa]  
**Proyecto:** StockFlow, sistema web de control de inventario

## 1. Desarrollo del sitio web

Desarrollé y mantuve el proyecto como una aplicación web en español para registrar pedidos de proveedor, inventario, ventas y reportes. El trabajo se realizó principalmente en un entorno local con **XAMPP**, utilizando **Apache, PHP 8.2 o superior y MariaDB/MySQL**.

El código del servidor está escrito en **PHP sin framework ni Composer**. La interfaz se elaboró con **HTML, CSS y JavaScript puro**; no se utilizó React, Angular, Vue ni Bootstrap. Para el control de versiones se utilizó **Git** y el repositorio remoto se alojó en **GitHub**.

Cronológicamente, primero se configuró el proyecto local y la base de datos; después se desarrollaron las pantallas de captura de pedidos, inventario, ventas, reportes y configuración de campos. Posteriormente se añadieron correcciones y mejoras, por ejemplo la edición de pedidos y la posibilidad de agregar productos a un pedido ya existente. Antes de publicar o actualizar, se revisó el código y se ejecutaron pruebas automatizadas locales.

## 2. Uso de Inteligencia Artificial

Sí se utilizó **Codex de OpenAI** como apoyo durante el desarrollo. Se empleó para analizar el código existente, proponer e implementar cambios puntuales, revisar errores y ejecutar/verificar pruebas. Por ejemplo, se utilizó para incorporar la edición de pedidos y permitir agregar o eliminar productos de un pedido ya registrado.

Las decisiones sobre qué cambios realizar, la revisión del resultado y la integración final fueron supervisadas por mí. La IA se utilizó como herramienta de asistencia y no se le proporcionaron contraseñas, tokens, llaves privadas ni datos de acceso.

La aplicación también integra un asistente de consulta para el usuario final que puede usar servicios de IA configurados mediante variables de entorno. Este uso es una funcionalidad del sistema y es distinto del uso de Codex como apoyo durante el desarrollo.

## 3. Recursos externos utilizados

Se utilizaron los siguientes recursos externos:

- **Chart.js 4**, cargado desde CDN, para las gráficas de reportes y paneles.
- **SheetJS / xlsx 0.18.5**, cargado desde CDN, para importar y procesar archivos de Excel.
- **GitHub**, para alojar el repositorio remoto y mantener historial de cambios mediante Git.
- **XAMPP**, como entorno local de Apache, PHP y MariaDB.
- Documentación oficial de PHP, MariaDB/MySQL, cPanel y las bibliotecas mencionadas, como referencia técnica cuando fue necesario.

No se utilizó una plantilla comercial o un tema descargado completo. La estructura visual, hojas de estilo y scripts principales se desarrollaron y adaptaron para este proyecto.

## 4. Origen de los archivos

Los archivos principales del proyecto fueron desarrollados o adaptados específicamente para StockFlow:

- Archivos PHP de pantallas, API, modelos, servicios y autenticación.
- Archivos JavaScript de captura de pedidos, inventario, ventas, reportes y configuración de campos.
- Hojas de estilo y estructura HTML de la interfaz.
- Scripts SQL, scripts de mantenimiento y pruebas automatizadas.

Los recursos provenientes de terceros fueron las bibliotecas Chart.js y SheetJS, que se cargan desde sus servicios CDN. También se conserva un archivo de datos de Excel utilizado como insumo o referencia de inventario; no forma parte del código fuente de la aplicación.

## 5. Proceso de publicación

Se preparó una guía de despliegue para un hosting con **cPanel**. El procedimiento previsto o seguido fue:

1. Crear un dominio o subdominio propio en cPanel y configurar PHP 8.2 o superior.
2. Crear la base de datos y usuarios de base de datos desde cPanel.
3. Importar el volcado SQL mediante **phpMyAdmin**, seleccionando la codificación `utf8mb4`.
4. Subir el paquete de la aplicación al directorio público del subdominio mediante **File Manager de cPanel**.
5. Extraer el archivo comprimido y comprobar que `index.php` quedara directamente en la raíz del sitio.
6. Crear el archivo `.env` directamente en el servidor con las variables de producción, sin copiar ni publicar el archivo local de credenciales.
7. Verificar el sitio por HTTPS, la conexión a la base de datos, los acentos y las pantallas principales.
8. Eliminar cualquier archivo temporal de diagnóstico usado durante la verificación.

**Dato que debes confirmar antes de entregar:** [Indica si este procedimiento se realizó efectivamente y escribe la fecha aproximada. Si usaste otro método, por ejemplo FTPS, sustitúyelo aquí.] No se deben incluir dominio, usuario de cPanel, contraseña, nombre de base de datos ni claves de API.

## 6. Programa de transferencia

Para el procedimiento documentado se utilizó el **Administrador de archivos (File Manager) de cPanel**, por lo que no fue necesario instalar un programa FTP/FTPS adicional.

**Si usaste otro programa, reemplaza esta oración por el nombre real**, por ejemplo FileZilla, WinSCP o un cliente del proveedor de hosting.

## 7. Cambios posteriores a la publicación inicial

Después de la versión inicial se realizaron mejoras funcionales al proyecto, entre ellas:

- Corrección y edición de encabezados de pedidos.
- Eliminación controlada de productos no vendidos y protección de productos con ventas registradas.
- Opción para agregar nuevos productos a un pedido existente usando la captura completa, con grupos, unidades y campos personalizados.
- Redistribución automática del costo de envío al cambiar la cantidad de productos de un pedido.
- Pruebas automatizadas para comprobar que los cambios no dejen pedidos incompletos ni alteren ventas existentes.

Cuando se publique una actualización, se deben subir solamente los archivos modificados, sin reemplazar el archivo `.env` ni cargar secretos o respaldos. Si ya realizaste una actualización en el servidor, agrega la fecha aproximada y los archivos o módulos modificados: **[completar]**.

## 8. Situaciones anormales y medidas preventivas

Durante el desarrollo se identificaron riesgos que se atendieron con medidas preventivas:

- Al distribuir envío entre varias piezas, se implementó el reparto en centavos para no perder ni inventar dinero por redondeo.
- No se permite eliminar una pieza ya vendida ni dejar un pedido sin productos, para proteger el historial de ventas.
- Se revisó la codificación `utf8mb4` durante las importaciones para evitar caracteres acentuados dañados.
- Los archivos `.env`, respaldos, hojas de cálculo con datos y archivos de pruebas se excluyen del control de versiones y del paquete público cuando contienen información sensible o no necesaria.

**Situaciones anormales observadas en el servidor:** [Si no observaste ninguna, escribe: “No observé mensajes de error, archivos desconocidos, cambios inesperados, problemas de acceso ni comportamientos inusuales durante la publicación y las verificaciones realizadas.” Si sí ocurrió algo, describe únicamente el hecho, fecha aproximada y cómo se atendió, sin incluir credenciales.]

## Declaración final

Esta bitácora describe las herramientas, recursos y procedimientos que conozco y realicé en el proyecto. Antes de entregarla, completé los campos entre corchetes para que el documento refleje de forma exacta mi método real de publicación y las situaciones observadas.
