<?php
require __DIR__ . '/src/Support/bootstrap.php';

use App\Auth;

require_login();
$user = Auth::currentUser();
if ($user === null) {
    header('Location: ' . url('login.php'));
    exit;
}
if ($user['onboarded_at'] === null) {
    header('Location: ' . url('onboarding.php'));
    exit;
}
$initials = mb_strtoupper(mb_substr($user['name'], 0, 1));
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>StockFlow</title>
<link rel="stylesheet" href="<?= esc(url('assets/css/app.css')) ?>">
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
</head>
<body data-base-url="<?= esc(url('')) ?>">
<!--
THESIS: cada pieza física cuenta su propia historia de costo/ganancia; StockFlow
  se niega al panel-tabla-generico que promedia todo en un SKU.
OWN-WORLD: blanco + azul rey (fintech premium, ref. Mercury/Arc), shell de sidebar
  fija con indicador deslizante, tarjetas KPI sin borde de color, sombras suaves
  con offset+blur, iconos de línea propios (sin emoji), tipografía Inter tabular.
STORY: el dueño entiende de un vistazo su stock/ganancia, actúa sobre la
  proyección, y le pregunta a su propio inventario en lenguaje natural.
FIRST VIEWPORT: sidebar izquierda (marca + nav + usuario) · topbar (título +
  acciones) · KPIs arriba del contenido de cada tab, acción primaria en azul rey.
FORM: estrategia de color restringida (neutrales + un acento), canon pedido por
  el usuario en sus propias palabras (blanco/azul rey minimalista con movimiento),
  quality bar: Mercury + Arc Browser.
FINISH: unreviewed and undocumented is unfinished; this build ends with the
  finish review, the verdict, and DESIGN.md.
-->
<div class="app-shell">
  <aside class="sidebar">
    <div class="sb-brand">
      <div class="mark">S</div>
      <div class="name">StockFlow</div>
    </div>
    <nav class="sb-nav" id="sb-nav">
      <div class="sb-indicator" id="sb-indicator"></div>
      <button class="sb-item active" data-tab="entradas" data-label="Entradas" onclick="switchTab('entradas')"><?= icon('inbox') ?><span class="lbl">Entradas</span></button>
      <button class="sb-item" data-tab="salidas" data-label="Salidas" onclick="switchTab('salidas')"><?= icon('outbox') ?><span class="lbl">Salidas</span></button>
      <button class="sb-item" data-tab="inventario" data-label="Inventario" onclick="switchTab('inventario')"><?= icon('package') ?><span class="lbl">Inventario</span></button>
      <button class="sb-item" data-tab="reportes" data-label="Reportes" onclick="switchTab('reportes')"><?= icon('chart') ?><span class="lbl">Reportes</span></button>
      <button class="sb-item" data-tab="chat" data-label="Asistente" onclick="switchTab('chat')"><?= icon('chat') ?><span class="lbl">Asistente</span></button>
    </nav>
    <div class="sb-foot">
      <a class="sb-item" href="<?= esc(url('settings.php')) ?>"><?= icon('settings') ?><span class="lbl">Configuración</span></a>
      <div class="sb-user">
        <div class="avatar"><?= esc($initials) ?></div>
        <div class="uname"><?= esc($user['name']) ?></div>
        <a class="rowbtn" href="<?= esc(url('logout.php')) ?>" title="Salir" style="margin-left:auto"><?= icon('logout', 16) ?></a>
      </div>
    </div>
  </aside>

  <div class="main">
    <div class="topbar">
      <h1 id="page-title">Entradas</h1>
      <div class="actions htools">
        <button onclick="ImportExport.importFile()"><?= icon('upload', 15) ?> Importar</button>
        <button onclick="ImportExport.exportFile()"><?= icon('download', 15) ?> Exportar</button>
      </div>
    </div>

    <div class="content">
      <section id="entradas" class="view active">
        <div class="kpis" id="kpis-ent"></div>
        <div class="cap-head">
          <h3><?= icon('inbox') ?> Capturar pedido del proveedor</h3>
          <p>Llena los datos generales, agrega tus productos abajo y pon el <b>envío total del pedido</b>. El sistema reparte el envío entre las piezas y todo se guarda en <b>pesos</b>.</p>
          <div class="cap-grid">
            <div class="field"><label># Pedido</label><input id="p-num" type="number"></div>
            <div class="field"><label>Proveedor</label><input id="p-prov" list="dl-proveedor" placeholder="Ej. Alibaba"></div>
            <div class="field"><label>Fecha de compra</label><input id="p-fcompra" type="date"></div>
            <div class="field"><label>Fecha de llegada <span class="hint">(opcional)</span></label><input id="p-fllegada" type="date"></div>
            <div class="field"><label>Moneda en que capturo</label><select id="p-moneda" onchange="Entradas.calc()"><option value="MXN">Pesos (MXN)</option><option value="USD">Dólares (USD)</option></select></div>
            <div class="field"><label>Envío total del pedido</label><input id="p-envio" type="number" step="0.01" placeholder="0" oninput="Entradas.calc()"></div>
            <div class="field"><label>Tipo de cambio <span class="hint">(si capturas en USD)</span></label><input id="p-tc" type="number" step="0.1" value="18" oninput="Entradas.calc()"></div>
          </div>
        </div>
        <div class="itemwrap">
          <div class="iw-scroll">
            <table class="itemtable">
              <thead><tr id="entrada-head"></tr></thead>
              <tbody id="entrada-items"></tbody>
            </table>
          </div>
          <div class="iw-foot">
            <button class="btn ghost sm" onclick="Entradas.addRow()"><?= icon('plus', 14) ?> Agregar producto</button>
            <button class="btn ghost sm" onclick="Entradas.addRow(5)"><?= icon('plus', 14) ?> Agregar 5</button>
            <span class="muted" id="envio-reparto"></span>
          </div>
        </div>
        <div class="summary" id="entrada-summary"></div>
        <div style="display:flex;gap:9px;flex-wrap:wrap;margin-bottom:8px">
          <button class="btn in" onclick="Entradas.guardar()"><?= icon('check', 15) ?> Guardar pedido en inventario</button>
          <button class="btn ghost" onclick="Entradas.reset()">Limpiar</button>
        </div>
      </section>

      <section id="salidas" class="view">
        <div class="kpis" id="kpis-sal"></div>
        <div class="toolbar">
          <input type="search" id="search-sal" placeholder="Buscar en ventas..." oninput="Salidas.render()">
          <select id="filt-mes" onchange="Salidas.render()"></select>
        </div>
        <div class="tablecard"><div class="tscroll"><table>
          <thead><tr id="head-sal"></tr></thead><tbody id="body-sal"></tbody>
        </table></div></div>
      </section>

      <section id="inventario" class="view">
        <div class="kpis" id="kpis-inv"></div>
        <div class="toolbar">
          <input type="search" id="search-inv" placeholder="Buscar producto, categoría..." oninput="Inventario.render()">
          <select id="filt-estado" onchange="Inventario.render()">
            <option value="">Todos los estados</option>
            <option value="stock">En stock</option>
            <option value="vendida">Vendidas</option>
          </select>
          <select id="filt-categoria" onchange="Inventario.render()"></select>
          <select id="filt-pedido" onchange="Inventario.render()"></select>
        </div>
        <div id="bulkbar" class="bulkbar"></div>
        <div class="tablecard"><div class="tscroll"><table>
          <thead><tr id="head-inv"></tr></thead><tbody id="body-inv"></tbody>
        </table></div></div>
      </section>

      <section id="reportes" class="view">
        <div class="section-label"><?= icon('outbox', 15) ?> Lo que ya vendiste</div>
        <div class="kpis" id="kpis-rep"></div>
        <div class="chartcard"><h3><?= icon('trendUp', 16) ?> Top productos por ganancia (MXN)</h3><canvas id="chart-top-productos"></canvas></div>
        <div class="chartcard"><h3><?= icon('chart', 16) ?> Ventas por mes</h3><canvas id="chart-ventas-mes"></canvas></div>
        <div class="chartcard"><h3><?= icon('dollar', 16) ?> Ganancia por mes (MXN)</h3><canvas id="chart-ganancia-mes"></canvas></div>
        <div class="chartcard">
          <h3 style="justify-content:space-between">
            <span><?= icon('sparkle', 16) ?> Analizar por</span>
            <select id="groupby-field" onchange="Reportes.loadGroupBy()" style="font:inherit;font-size:.8rem;border:1px solid var(--line);border-radius:var(--radius-sm);padding:6px 9px"></select>
          </h3>
          <canvas id="chart-groupby-units"></canvas>
        </div>
        <div class="chartcard"><h3><?= icon('dollar', 16) ?> Ganancia por <span id="groupby-label">criterio</span> (MXN)</h3><canvas id="chart-groupby-profit"></canvas></div>

        <div class="section-label"><?= icon('package', 15) ?> Lo que tienes en stock</div>
        <div class="kpis" id="kpis-stock"></div>
        <div class="chartcard"><h3>Lo que lleva más tiempo sin venderse</h3>
          <div class="tscroll"><table style="min-width:520px">
            <thead><tr><th>Ped.</th><th>Producto</th><th>Invertido</th><th>Tiempo parado</th></tr></thead>
            <tbody id="stock-antiguo"></tbody>
          </table></div>
        </div>

        <div class="section-label"><?= icon('sparkle', 15) ?> Proyección de ventas</div>
        <div class="cap-head">
          <div class="cap-grid">
            <div class="field"><label>Meses a proyectar</label>
              <select id="proj-months" onchange="Reportes.loadProjection()">
                <option value="1">1 mes</option>
                <option value="2">2 meses</option>
                <option value="3">3 meses</option>
              </select>
            </div>
          </div>
          <div id="proj-result" style="margin-top:12px"></div>
        </div>
      </section>

      <section id="chat" class="view">
        <div class="cap-head">
          <h3><?= icon('chat') ?> Pregúntale a tu inventario</h3>
          <p>Hazle preguntas en lenguaje natural sobre tus ventas, compras o proyecciones. Ejemplos: "¿Cuántas piezas tengo en stock?", "¿Cuál es mi producto más rentable?", "¿Cuánto voy a vender el próximo mes?"</p>
        </div>
        <div class="tablecard chat-log" id="chat-log"></div>
        <div class="chat-inputrow">
          <input type="text" id="chat-input" placeholder="Escribe tu pregunta..." onkeydown="if(event.key==='Enter')Chat.send()">
          <button class="btn primary" onclick="Chat.send()"><?= icon('chat', 15) ?> Enviar</button>
        </div>
      </section>
    </div>
  </div>
</div>

<div class="modal-bg" id="modal-bg">
  <div class="modal">
    <h2><span id="modal-title">Editar producto</span><span class="x" onclick="Inventario.closeModal()"><?= icon('x', 18) ?></span></h2>
    <div class="mbody">
      <div class="grid2" id="modal-fields"></div>
      <div class="calcbox"><span>Ganancia (venta − costo):</span><b id="calc-ganancia">$0.00</b></div>
    </div>
    <div class="mfoot">
      <button class="btn ghost" onclick="Inventario.closeModal()">Cancelar</button>
      <button class="btn primary" onclick="Inventario.save()">Guardar</button>
    </div>
  </div>
</div>
<div class="modal-bg" id="sell-bg">
  <div class="modal" style="max-width:420px">
    <h2><span><?= icon('dollar', 17) ?> Registrar venta</span><span class="x" onclick="Inventario.closeSell()"><?= icon('x', 18) ?></span></h2>
    <div class="mbody">
      <div id="sell-info" class="calcbox" style="display:block"></div>
      <div class="field" style="margin-top:12px"><label>Precio de venta (MXN)</label><input id="sell-precio" type="number" step="1" inputmode="numeric" oninput="Inventario.sellCalc()"></div>
      <div class="field" style="margin-top:10px"><label>Fecha de venta</label><input id="sell-fecha" type="date"></div>
      <div class="calcbox" style="margin-top:12px"><span>Ganancia:</span><b id="sell-ganancia">—</b></div>
    </div>
    <div class="mfoot">
      <button class="btn ghost" onclick="Inventario.closeSell()">Cancelar</button>
      <button class="btn primary" onclick="Inventario.confirmSell()">Registrar venta</button>
    </div>
  </div>
</div>
<div class="modal-bg" id="bulk-bg">
  <div class="modal" style="max-width:560px">
    <h2><span><?= icon('dollar', 17) ?> Vender varias de golpe</span><span class="x" onclick="Inventario.closeBulk()"><?= icon('x', 18) ?></span></h2>
    <div class="mbody">
      <div class="grid2">
        <div class="field"><label>Precio para todas (MXN)</label>
          <div style="display:flex;gap:6px"><input id="bulk-precio" type="number" step="1" style="flex:1"><button class="btn sm" onclick="Inventario.applyBulkPrice()">Aplicar a todas</button></div>
        </div>
        <div class="field"><label>Fecha de venta</label><input id="bulk-fecha" type="date"></div>
      </div>
      <div id="bulk-list" style="margin-top:14px;display:flex;flex-direction:column;gap:8px;max-height:320px;overflow-y:auto"></div>
      <div id="bulk-summary" style="margin-top:14px;background:var(--surface);border-radius:var(--radius-sm);padding:11px 14px;font-size:.88rem"></div>
    </div>
    <div class="mfoot">
      <button class="btn ghost" onclick="Inventario.closeBulk()">Cancelar</button>
      <button class="btn primary" onclick="Inventario.confirmBulkSell()">Registrar ventas</button>
    </div>
  </div>
</div>
<datalist id="dl-proveedor"></datalist>
<input type="file" id="fileinput" accept=".xlsx,.xls,.csv" style="display:none" onchange="ImportExport.handleFile(event)">
<div class="toast" id="toast"></div>

<script>window.__csrf = <?= json_encode(csrf_token()) ?>;</script>
<script src="<?= esc(url('assets/js/icons.js')) ?>"></script>
<script src="<?= esc(url('assets/js/api.js')) ?>"></script>
<script src="<?= esc(url('assets/js/attributes.js')) ?>"></script>
<script src="<?= esc(url('assets/js/entradas.js')) ?>"></script>
<script src="<?= esc(url('assets/js/salidas.js')) ?>"></script>
<script src="<?= esc(url('assets/js/inventario.js')) ?>"></script>
<script src="<?= esc(url('assets/js/reportes.js')) ?>"></script>
<script src="<?= esc(url('assets/js/chat.js')) ?>"></script>
<script src="<?= esc(url('assets/js/importExport.js')) ?>"></script>
<script src="<?= esc(url('assets/js/app.js')) ?>"></script>
</body>
</html>
