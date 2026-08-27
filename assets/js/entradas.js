/* Tab "Entradas": captura de un pedido por lote.
 *
 * EL PROBLEMA QUE RESUELVE
 * "Llegaron 20 playeras del Real Madrid. Comparten equipo, proveedor, temporada, fecha
 * y costos; solo cambia la talla. No quiero escribir Real Madrid veinte veces."
 *
 * La captura se separa en tres niveles, que es como el usuario piensa el pedido:
 *   1. Datos del PEDIDO   — proveedor, fechas, envío total, moneda.
 *   2. Datos COMPARTIDOS  — lo que vale igual para las 20 piezas. Se escribe una vez.
 *   3. Datos POR UNIDAD   — solo lo que cambia. Normalmente una o dos columnas.
 *
 * Los campos y su orden salen del REGISTRO (contexto "entries"), no de una lista
 * escrita aquí: un negocio de joyería ve "Material" y "Quilates" sin tocar código.
 *
 * Cada unidad sigue creando su propio inventory_item, porque el modelo del producto es
 * una fila por pieza física — es lo que permite costo, envío y ganancia por unidad.
 */
const Entradas = (() => {
  let unidades = [];        // [{ rid, valores: {field_key: valor} }]
  let compartido = {};      // { field_key: true }  — qué campos son iguales para todo el lote
  let rowSeq = 0;
  let listo = false;

  /** Campos de captura, del registro. Excluye los calculados: no se escriben. */
  function campos() {
    return Fields.columns('entries').filter(f => f.editable !== false && f.field_type !== 'computed');
  }
  function camposCompartidos() { return campos().filter(f => compartido[f.field_key]); }
  function camposPorUnidad() { return campos().filter(f => !compartido[f.field_key]); }

  async function init() {
    document.getElementById('p-fcompra').value = today();
    await Fields.load();
    // Por omisión se comparte el campo principal del producto: es el que más se repite
    // en un lote y el que motivó todo esto.
    const p = Fields.principalField();
    if (p && !listo) { compartido[p.field_key] = true; }
    listo = true;
    if (!unidades.length) addRow();
    renderCompartidos();
    renderRows();
    renderKpis();
  }

  // ---------------------------------------------------------------
  // Valores compartidos
  // ---------------------------------------------------------------

  function renderCompartidos() {
    const cont = document.getElementById('ent-compartidos');
    if (!cont) return;

    cont.innerHTML = campos().map(f => {
      const on = !!compartido[f.field_key];
      return `<div class="shfield ${on ? 'on' : ''}">
        <label class="shchk">
          <input type="checkbox" ${on ? 'checked' : ''} onchange="Entradas.toggleCompartido('${f.field_key}')">
          <span>${esc(f.label)}</span>
        </label>
        <div class="shval">${on ? Fields.input(f, valorCompartido(f.field_key), 'data-shared="1"') : '<span class="muted">cambia por pieza</span>'}</div>
      </div>`;
    }).join('') + Fields.datalists();

    document.getElementById('ent-compartidos-n').textContent = camposCompartidos().length;
  }

  const compartidoValores = {};
  function valorCompartido(key) { return compartidoValores[key] ?? ''; }

  function leerCompartidos() {
    document.querySelectorAll('#ent-compartidos [data-shared="1"]').forEach(el => {
      compartidoValores[el.dataset.fk] = el.value;
    });
    return { ...compartidoValores };
  }

  function toggleCompartido(key) {
    leerCompartidos();
    // Al dejar de compartir un campo, su valor pasa a cada unidad como punto de
    // partida: no se pierde lo que ya se había escrito.
    if (compartido[key]) {
      const v = compartidoValores[key];
      if (v) unidades.forEach(u => { if (!u.valores[key]) u.valores[key] = v; });
      delete compartido[key];
    } else {
      compartido[key] = true;
    }
    renderCompartidos();
    renderRows();
  }

  // ---------------------------------------------------------------
  // Unidades
  // ---------------------------------------------------------------

  function addRow(count = 1) {
    for (let i = 0; i < count; i++) unidades.push({ rid: ++rowSeq, valores: {} });
    renderRows();
  }

  /** Genera N unidades de golpe, reemplazando las vacías. */
  function generar() {
    const n = parseInt(document.getElementById('ent-cantidad').value, 10) || 0;
    if (n < 1) { toast('Escribe una cantidad'); return; }
    if (n > 500) { toast('Máximo 500 piezas por pedido'); return; }
    leerUnidades();
    // Si solo hay filas vacías, se sustituyen; si ya se capturó algo, se agregan.
    const hayDatos = unidades.some(u => Object.values(u.valores).some(v => v !== ''));
    if (!hayDatos) unidades = [];
    addRow(n);
    toast(`${n} unidad(es) generada(s)`);
  }

  function removeRow(rid) {
    leerUnidades();
    unidades = unidades.filter(u => u.rid !== rid);
    if (!unidades.length) addRow();
    renderRows();
  }

  /** Duplica una unidad N veces, con todos sus valores. */
  function duplicar(rid, veces = 1) {
    leerUnidades();
    const i = unidades.findIndex(u => u.rid === rid);
    if (i < 0) return;
    const copias = [];
    for (let k = 0; k < veces; k++) {
      copias.push({ rid: ++rowSeq, valores: { ...unidades[i].valores } });
    }
    unidades.splice(i + 1, 0, ...copias);
    renderRows();
  }

  function duplicarVarias(rid) {
    const n = parseInt(prompt('¿Cuántas copias?', '5'), 10);
    if (!n || n < 1) return;
    duplicar(rid, Math.min(n, 200));
  }

  /** Copia el valor de la primera unidad a todas (rellenar hacia abajo). */
  function rellenar(key) {
    leerUnidades();
    const v = unidades[0]?.valores[key] ?? '';
    unidades.forEach(u => { u.valores[key] = v; });
    renderRows();
    toast(`Se copió "${v || '(vacío)'}" a las ${unidades.length} unidades`);
  }

  function limpiarColumna(key) {
    leerUnidades();
    unidades.forEach(u => { u.valores[key] = ''; });
    renderRows();
  }

  function leerUnidades() {
    document.querySelectorAll('#entrada-items tr[data-rid]').forEach(tr => {
      const u = unidades.find(x => x.rid === Number(tr.dataset.rid));
      if (!u) return;
      tr.querySelectorAll('[data-fk]').forEach(el => { u.valores[el.dataset.fk] = el.value; });
    });
  }

  function renderRows() {
    const cols = camposPorUnidad();
    const head = document.getElementById('entrada-head');

    head.innerHTML = '<th style="width:38px">#</th>'
      + cols.map(f => `<th>${esc(f.label)}
          <span class="colact" title="Copiar el valor de la primera fila a todas"
                onclick="Entradas.rellenar('${f.field_key}')">${icon('chevronDown', 11)}</span>
        </th>`).join('')
      + '<th>+Envío c/u</th><th>Costo total</th><th style="width:74px"></th>';

    const tb = document.getElementById('entrada-items');
    if (!unidades.length) {
      tb.innerHTML = `<tr><td colspan="${cols.length + 4}"><div class="empty">Genera al menos una unidad.</div></td></tr>`;
      calc();
      return;
    }

    tb.innerHTML = unidades.map((u, i) => `
      <tr data-rid="${u.rid}">
        <td class="muted" style="text-align:center">${i + 1}</td>
        ${cols.map(f => `<td>${Fields.input(f, u.valores[f.field_key] ?? '', 'oninput="Entradas.calc()"')}</td>`).join('')}
        <td class="calc-cell" data-envio>$0</td>
        <td class="calc-cell" data-total>$0</td>
        <td>
          <button class="rowbtn" title="Duplicar" onclick="Entradas.duplicar(${u.rid})">${icon('plus', 15)}</button>
          <button class="rowbtn" title="Duplicar varias veces" onclick="Entradas.duplicarVarias(${u.rid})">${icon('copy', 15)}</button>
          <button class="rowbtn" title="Quitar" onclick="Entradas.removeRow(${u.rid})">${icon('trash', 15)}</button>
        </td>
      </tr>`).join('');
    calc();
  }

  // ---------------------------------------------------------------
  // Pegado tabular desde Excel o Google Sheets
  // ---------------------------------------------------------------

  /**
   * Convierte texto separado por tabuladores en unidades.
   *
   * Lo que se pega desde una hoja de cálculo son celdas separadas por TAB y filas por
   * salto de línea. Si la primera fila coincide con etiquetas de campos, se toma como
   * encabezado y cada columna se asigna a su campo; si no, se asignan en el orden en
   * que aparecen las columnas de la tabla.
   */
  function procesarPegado(texto) {
    const lineas = texto.replace(/\r\n?/g, '\n').split('\n').filter(l => l.trim() !== '');
    if (!lineas.length) return;

    const filas = lineas.map(l => l.split('\t').map(c => c.trim()));
    const cols = camposPorUnidad();

    // ¿La primera fila son encabezados? Se compara contra etiqueta y clave.
    const primera = filas[0].map(c => c.toLowerCase());
    const mapa = primera.map(h =>
      cols.find(f => f.label.toLowerCase() === h || f.field_key.toLowerCase() === h) || null);
    const hayEncabezado = mapa.filter(Boolean).length >= Math.max(1, Math.ceil(primera.length / 2));

    const cuerpo = hayEncabezado ? filas.slice(1) : filas;
    const destino = hayEncabezado ? mapa : cols;

    if (!cuerpo.length) { toast('No encontré filas que pegar'); return; }

    leerUnidades();
    const hayDatos = unidades.some(u => Object.values(u.valores).some(v => v !== ''));
    if (!hayDatos) unidades = [];

    cuerpo.forEach(celdas => {
      const valores = {};
      celdas.forEach((valor, k) => {
        const campo = destino[k];
        if (campo) valores[campo.field_key] = valor;
      });
      unidades.push({ rid: ++rowSeq, valores });
    });

    renderRows();
    toast(`${cuerpo.length} fila(s) pegada(s)${hayEncabezado ? ' con encabezados reconocidos' : ''}`);
    cerrarPegar();
  }

  function abrirPegar() {
    const cols = camposPorUnidad().map(f => f.label).join('\t');
    document.getElementById('paste-hint').textContent =
      cols ? `Columnas esperadas, en orden: ${cols}` : 'Todos los campos están marcados como compartidos.';
    document.getElementById('paste-area').value = '';
    document.getElementById('paste-bg').classList.add('show');
    setTimeout(() => document.getElementById('paste-area').focus(), 50);
  }
  function cerrarPegar() { document.getElementById('paste-bg').classList.remove('show'); }
  function aplicarPegado() { procesarPegado(document.getElementById('paste-area').value); }

  // ---------------------------------------------------------------
  // Cálculo y resumen
  // ---------------------------------------------------------------

  function currency() { return document.getElementById('p-moneda').value; }

  function calc() {
    const envioTotalRaw = parseFloat(document.getElementById('p-envio').value) || 0;
    const tc = parseFloat(document.getElementById('p-tc').value) || 1;
    const factor = currency() === 'USD' ? tc : 1;
    const envioTotal = envioTotalRaw * factor;

    const trs = [...document.querySelectorAll('#entrada-items tr[data-rid]')];
    const n = trs.length;
    // El envío del pedido se reparte en partes iguales entre las piezas: es lo que
    // convierte un costo de lote en un costo por unidad.
    const envioCU = n > 0 ? envioTotal / n : 0;

    const costoCompartido = compartido['cost']
      ? (parseFloat(document.querySelector('#ent-compartidos [data-fk="cost"]')?.value) || 0) * factor
      : null;
    const ventaCompartida = compartido['sale_price']
      ? (parseFloat(document.querySelector('#ent-compartidos [data-fk="sale_price"]')?.value) || 0) * factor
      : null;

    let sumCosto = 0, sumVenta = 0;
    trs.forEach(tr => {
      const cost = costoCompartido !== null
        ? costoCompartido
        : (parseFloat(tr.querySelector('[data-fk="cost"]')?.value) || 0) * factor;
      const total = cost + envioCU;
      tr.querySelector('[data-envio]').textContent = mx(envioCU);
      tr.querySelector('[data-total]').textContent = mx(total);

      const venta = ventaCompartida !== null
        ? ventaCompartida
        : (parseFloat(tr.querySelector('[data-fk="sale_price"]')?.value) || 0) * factor;
      sumCosto += total;
      if (venta) sumVenta += venta;
    });

    document.getElementById('envio-reparto').textContent = n
      ? `Envío repartido entre ${n} pieza${n === 1 ? '' : 's'}: ${mx(envioCU)} c/u`
      : '';
    document.getElementById('entrada-summary').innerHTML = `
      <div class="row"><span>Piezas a registrar</span><span class="v">${n}</span></div>
      <div class="row"><span>Costo total del pedido (con envío)</span><span class="v">${mx(sumCosto)}</span></div>
      ${sumVenta ? `<div class="row total"><span>Ganancia potencial si vendes todo al precio capturado</span><span class="v">${mx(sumVenta - sumCosto)}</span></div>` : ''}
    `;
  }

  async function renderKpis() {
    const { items } = await Api.get('items.php?status=stock');
    const invCost = items.reduce((s, r) => s + Number(r.total_cost || 0), 0);
    const pedidos = new Set(items.map(r => r.order_number).filter(x => x != null)).size;
    document.getElementById('kpis-ent').innerHTML = `
      <div class="kpi in"><div class="lbl">${icon('package', 14)} Piezas en stock</div><div class="val" data-raw="${items.length}" data-format="int">0</div></div>
      <div class="kpi r"><div class="lbl">${icon('dollar', 14)} Dinero invertido</div><div class="val" data-raw="${invCost}" data-format="money">$0</div></div>
      <div class="kpi"><div class="lbl">${icon('inbox', 14)} Pedidos registrados</div><div class="val" data-raw="${pedidos}" data-format="int">0</div></div>`;
    animateKpis('kpis-ent');
  }

  // ---------------------------------------------------------------
  // Vista previa y guardado
  // ---------------------------------------------------------------

  /** Combina compartidos + por unidad, en el formato que espera el servidor. */
  function construirItems() {
    leerUnidades();
    const shared = leerCompartidos();
    const principal = Fields.principalField();
    const claveP = principal ? principal.field_key : 'name';

    return unidades.map(u => {
      const plano = {};
      const attrs = {};
      campos().forEach(f => {
        const valor = compartido[f.field_key] ? (shared[f.field_key] ?? '') : (u.valores[f.field_key] ?? '');
        if (valor === '' || valor == null) return;
        if (f.storage === 'json') attrs[f.field_key] = valor;
        else plano[f.field_key] = valor;
      });

      // El servidor identifica el producto por la columna name. Si el negocio designó
      // otro campo como principal y ese campo vive en el JSON, se copia también a name
      // para que el inventario, el buscador y el asistente sigan funcionando.
      // Se documenta la decisión: se DUPLICA el valor, no se mueve, así ninguna capa
      // existente se rompe y el campo del negocio conserva su identidad.
      if (!plano.name) {
        plano.name = principal && principal.storage === 'json'
          ? (attrs[claveP] ?? '')
          : (plano[claveP] ?? '');
      }
      return { ...plano, attributes: attrs };
    });
  }

  function faltantes(items) {
    const obligatorios = campos().filter(f => f.is_required);
    const problemas = [];
    items.forEach((it, i) => {
      obligatorios.forEach(f => {
        const v = f.storage === 'json' ? it.attributes[f.field_key] : it[f.field_key];
        if (v === undefined || v === '') problemas.push(`Unidad ${i + 1}: falta "${f.label}"`);
      });
      if (!it.name) problemas.push(`Unidad ${i + 1}: falta el identificador del producto`);
    });
    return problemas;
  }

  function previsualizar() {
    const items = construirItems();
    const problemas = faltantes(items);
    const cols = campos();

    document.getElementById('prev-count').textContent = items.length;
    document.getElementById('prev-problemas').innerHTML = problemas.length
      ? `<div class="impwarn"><span>⚠</span><span>${problemas.slice(0, 6).map(esc).join('<br>')}
         ${problemas.length > 6 ? `<br>…y ${problemas.length - 6} más` : ''}</span></div>`
      : '';

    document.getElementById('prev-tabla').innerHTML = `
      <div class="tscroll"><table style="font-size:.78rem">
        <thead><tr><th>#</th>${cols.map(f => `<th>${esc(f.label)}</th>`).join('')}</tr></thead>
        <tbody>${items.slice(0, 25).map((it, i) => `<tr>
          <td class="muted">${i + 1}</td>
          ${cols.map(f => {
            const v = f.storage === 'json' ? it.attributes[f.field_key] : it[f.field_key];
            return `<td>${esc(v ?? '—')}</td>`;
          }).join('')}
        </tr>`).join('')}</tbody>
      </table></div>
      ${items.length > 25 ? `<p class="muted" style="font-size:.78rem;margin-top:7px">Se muestran las primeras 25 de ${items.length}.</p>` : ''}`;

    document.getElementById('prev-bg').classList.add('show');
  }

  function cerrarPrev() { document.getElementById('prev-bg').classList.remove('show'); }

  async function guardar() {
    const items = construirItems().filter(it => it.name);
    if (!items.length) {
      toast('Captura al menos una unidad con su identificador');
      return;
    }
    const problemas = faltantes(items);
    if (problemas.length) {
      toast(problemas[0]);
      return;
    }

    const header = {
      order_number: document.getElementById('p-num').value || null,
      supplier: document.getElementById('p-prov').value,
      purchase_date: document.getElementById('p-fcompra').value,
      arrival_date: document.getElementById('p-fllegada').value,
      currency: currency(),
      exchange_rate: document.getElementById('p-tc').value,
      shipping_total: document.getElementById('p-envio').value,
    };

    try {
      // El servidor guarda el lote completo en una transacción: si una unidad falla,
      // no queda medio pedido registrado.
      await Api.post('purchase_orders.php', { header, items });
      toast(`Pedido guardado con ${items.length} pieza(s)`);
      cerrarPrev();
      reset(true);
      renderKpis();
    } catch (e) { /* Api.post ya mostró el error */ }
  }

  function reset(silencioso = false) {
    if (!silencioso && unidades.length && !confirm('¿Limpiar la captura actual?')) return;
    unidades = [];
    Object.keys(compartidoValores).forEach(k => delete compartidoValores[k]);
    document.getElementById('p-num').value = '';
    document.getElementById('p-prov').value = '';
    document.getElementById('p-envio').value = '';
    document.getElementById('p-fllegada').value = '';
    addRow();
    renderCompartidos();
  }

  return {
    init, addRow, removeRow, generar, duplicar, duplicarVarias, rellenar, limpiarColumna,
    toggleCompartido, abrirPegar, cerrarPegar, aplicarPegado, procesarPegado,
    calc, previsualizar, cerrarPrev, guardar, reset, renderKpis,
  };
})();
