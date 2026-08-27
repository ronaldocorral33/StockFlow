<?php
/**
 * Pantalla "Campos y vistas".
 *
 * Toda la configuración pasa por api/attributes.php, que es el guardián del registro
 * de campos: valida los tipos, protege los canónicos y los calculados, y garantiza
 * que exista un solo campo principal. Esta página solo dibuja.
 *
 * La versión anterior manejaba la configuración con formularios POST a sí misma y solo
 * cubría los campos personalizados. Se reemplaza porque ahora hay cuatro contextos de
 * visibilidad y un archivado que necesitan ir y venir sin recargar la página entera.
 */
require __DIR__ . '/src/Support/bootstrap.php';

use App\Services\Authz;

['business_id' => $businessId] = require_business();
Authz::require('view', 'attribute_definitions');
$puedeEditar = Authz::can('update', 'attribute_definitions');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Campos y vistas · StockFlow</title>
<link rel="stylesheet" href="<?= esc(asset('assets/css/app.css')) ?>">
</head>
<body data-base-url="<?= esc(url()) ?>">
<div class="settings-shell">
<div class="settings-top">
  <div class="brand"><div class="mark">S</div>Campos y vistas</div>
  <div class="htools"><a class="btnlink" href="<?= esc(url('dashboard.php')) ?>"><?= icon('package', 15) ?> Volver al inventario</a></div>
</div>

<div class="settings-wrap">
  <?php if (!$puedeEditar): ?>
    <div class="errbox">Puedes ver la configuración, pero no cambiarla. Pídele a un administrador del negocio que la ajuste.</div>
  <?php endif; ?>

  <div class="cap-head">
    <div class="chat-head">
      <div>
        <h3><?= icon('sliders', 16) ?> Los campos de tu negocio</h3>
        <p style="margin-bottom:0">Aquí decides qué información guardas de cada pieza y en qué pantalla aparece.
        Lo que cambies se refleja de inmediato en Entradas, Inventario, Salidas, Exportación y en el asistente.</p>
      </div>
      <button class="btn ghost sm" id="btn-archivados" onclick="Campos.toggleArchivados()">Ver archivados</button>
    </div>

    <div class="entgen" style="margin-top:14px">
      <label>Reordenar para</label>
      <select id="campos-ctx">
        <option value="inventory">Inventario</option>
        <option value="entries">Entradas</option>
        <option value="sales">Salidas</option>
        <option value="export">Exportación</option>
      </select>
      <span class="muted">Las flechas cambian el orden de la pantalla elegida. La visibilidad se marca en cada columna.</span>
    </div>
  </div>

  <div id="campos-tabla"></div>
  <div id="campos-archivados"></div>

  <!-- Leyenda: las tres clases tienen reglas distintas y conviene decir por qué. -->
  <div class="cap-head" style="margin-top:18px">
    <h3>Qué significa cada clase</h3>
    <div class="cols3-lite">
      <div><span class="cbadge base">Base</span>
        <p class="muted">El sistema los usa para calcular costos y ganancias. Puedes renombrarlos y ocultarlos, pero no archivarlos.</p></div>
      <div><span class="cbadge calc">Calculado</span>
        <p class="muted">Los calcula la base de datos a partir de otros campos. No se capturan ni se editan.</p></div>
      <div><span class="cbadge cust">Personalizado</span>
        <p class="muted">Tuyos. Se crean, renombran y archivan libremente. Al archivar, los valores guardados se conservan.</p></div>
    </div>
  </div>

  <?php if ($puedeEditar): ?>
  <div class="cap-head" style="margin-top:18px">
    <h3><?= icon('plus', 16) ?> Agregar un campo</h3>
    <p>Por ejemplo <b>Talla</b>, <b>Material</b>, <b>Marca</b> o <b>IMEI</b>: lo que tu negocio necesite registrar de cada pieza.</p>
    <div class="cap-grid">
      <div class="field"><label>Etiqueta</label><input id="label-nuevo" placeholder="Ej. Material"></div>
      <div class="field"><label>Tipo</label><select id="tipo-nuevo"></select></div>
      <div class="field"><label>Opciones <span class="hint">(solo si es lista, separadas por coma)</span></label><input id="opciones-nuevo" placeholder="Oro, Plata, Acero"></div>
      <div class="field"><label><input type="checkbox" id="req-nuevo"> Obligatorio al capturar</label></div>
      <div class="field"><button class="btn in" onclick="Campos.crear()"><?= icon('plus', 15) ?> Agregar campo</button></div>
    </div>
  </div>
  <?php endif; ?>
</div>
</div>

<div id="toast" class="toast"></div>
<script>window.__csrf = <?= json_encode(csrf_token()) ?>;</script>
<script src="<?= esc(asset('assets/js/icons.js')) ?>"></script>
<script src="<?= esc(asset('assets/js/api.js')) ?>"></script>
<script src="<?= esc(asset('assets/js/app.js')) ?>"></script>
<script src="<?= esc(asset('assets/js/campos.js')) ?>"></script>
<script>Campos.cargar();</script>
</body>
</html>
