/* Tab "Inventario": tabla con búsqueda/filtro/orden, selección múltiple, modal de edición y venta. */
const Inventario = (() => {
  let cache = [];
  let selected = new Set();
  // La vista inicial agrupa por pedido y muestra primero el último capturado.
  let sort = { col: 'order_number', dir: 'desc' };
  let editingId = null;
  let sellingId = null;
  let metaLoaded = false;
  let avgSoldPrice = null; // precio de venta promedio histórico, para estimar piezas sin precio
  // Filtros por campo del registro: { temporada: '2025/2026', version: 'Visita' }.
  // Viven aquí porque son parte de la consulta, no de la configuración del negocio.
  let attrFilters = {};

  // Las columnas ya no se escriben aquí: salen del registro de campos del negocio
  // (attribute_definitions), que el usuario controla desde el selector de columnas.
  // Antes esta constante era un arreglo literal de 9 columnas, y por eso Categoría
  // aparecía siempre aunque estuviera vacía en las 738 piezas.

  /**
   * @param {boolean} force Recarga aunque ya se haya cargado antes.
   *
   * Se carga UNA vez por sesión porque las categorías y los pedidos casi no cambian
   * mientras alguien navega. Pero "casi" no es "nunca": borrar un pedido desde su
   * pantalla dejaba su número vivo en este selector — un pedido fantasma que ya no
   * existe y que al filtrarlo no devuelve nada. Por eso Pedidos puede forzar la
   * recarga cuando su lista cambió.
   */
  async function loadMeta(force = false) {
    if (metaLoaded && !force) return;
    const meta = await Api.get('items.php?action=meta');
    const pedAntes = document.getElementById('filt-pedido').value;
    const catAntes = document.getElementById('filt-categoria').value;
    const catSel = document.getElementById('filt-categoria');
    catSel.innerHTML = '<option value="">Todas las categorías</option>' +
      meta.categories.map(c => `<option value="${esc(c)}">${esc(c)}</option>`).join('');
    const pedSel = document.getElementById('filt-pedido');
    pedSel.innerHTML = '<option value="">Todos los pedidos</option>' +
      meta.purchase_orders.map(p => `<option value="${p.id}">#${p.order_number}</option>`).join('');
    // Se conserva lo que el usuario tuviera filtrado, si esa opción sigue existiendo.
    // Si el pedido filtrado fue el que se borró, el selector vuelve a "todos" en vez
    // de quedarse en un valor que ya no está en la lista.
    pedSel.value = pedAntes;
    catSel.value = catAntes;
    avgSoldPrice = meta.avg_sold_price != null ? Number(meta.avg_sold_price) : null;
    // El registro de campos define qué columnas dibujar. Se carga una vez por sesión.
    await Fields.load();
    metaLoaded = true;
  }

  /** Vuelve a leer categorías y pedidos. La llama Pedidos tras borrar o renumerar. */
  const refreshMeta = () => loadMeta(true);

  function currentFilters() {
    return {
      q: document.getElementById('search-inv').value,
      status: document.getElementById('filt-estado').value,
      category: document.getElementById('filt-categoria').value,
      purchase_order_id: document.getElementById('filt-pedido').value,
      sort: sort.col,
      dir: sort.dir > 0 ? 'asc' : 'desc',
    };
  }

  /** Los filtros por atributo viajan como attr[clave]=valor. */
  function attrQuery() {
    const p = new URLSearchParams();
    Object.entries(attrFilters).forEach(([k, v]) => {
      if (v !== '' && v != null) p.append(`attr[${k}]`, v);
    });
    return p.toString();
  }

  async function render() {
    await loadMeta();
    const f = currentFilters();
    const qs = new URLSearchParams(Object.entries(f).filter(([, v]) => v !== '')).toString();
    const extra = attrQuery();
    const res = await Api.get('items.php?' + qs + (extra ? '&' + extra : ''));
    cache = res.items;
    renderKpis(cache);
    renderTable(cache);
    renderBulkBar();
    renderFilterChips();
  }

  function renderKpis(items) {
    const stock = items.filter(r => !r.sale_date);
    const invCost = stock.reduce((s, r) => s + Number(r.total_cost || 0), 0);

    // Venta potencial = piezas en stock × precio de venta promedio histórico.
    // Se usa SIEMPRE el promedio, incluso en las piezas que ya traen un precio propio:
    // una sola base de cálculo hace que el número sea comparable entre periodos y fácil
    // de explicar. (Antes se sumaba solo el precio de las pocas que lo tenían, lo que
    // ignoraba casi todo el inventario y daba una cifra sin sentido.)
    const potVenta = avgSoldPrice != null ? stock.length * avgSoldPrice : 0;

    // Ganancia YA REALIZADA de lo que se ve con el filtro actual.
    // Solo cuenta piezas vendidas: una pieza en stock con precio sugerido todavía no
    // te dio un peso, y sumarla aquí inflaría la ganancia con dinero que no ha entrado.
    // (En este inventario hay $1,500 en precios sugeridos de piezas sin vender: la
    // diferencia entre "lo que vale" y "lo que ya cobraste".)
    const vendidas = items.filter(r => r.sale_date);
    const ganancia = vendidas.reduce((s, r) => s + Number(r.profit || 0), 0);
    const ingresos = vendidas.reduce((s, r) => s + Number(r.sale_price || 0), 0);
    // El margen se calcula sobre ingresos, no sobre costo: es el % de cada peso
    // vendido que te quedaste.
    const margen = ingresos > 0 ? (ganancia / ingresos * 100) : null;

    // La OTRA lectura de ganancia, la misma que el gráfico de pedidos: cuánto ha
    // regresado contra TODO lo que costó lo que estás viendo — incluyendo lo que
    // sigue en stock. Es negativa mientras no recuperes la inversión.
    //
    // Las dos conviven a propósito: "Ganancia (ya vendido)" dice si vendes con buen
    // margen; "Neto" dice si eso que compraste ya se pagó solo. Un lote recién
    // llegado puede tener excelente margen y aun así deberte dinero.
    const costoTodo = items.reduce((s, r) => s + Number(r.total_cost || 0), 0);
    const neto = ingresos - costoTodo;
    const subGan = !vendidas.length
      ? 'aún no hay ventas en esta vista'
      : `${vendidas.length} de ${items.length} vendidas${margen != null ? ` · margen ${margen.toFixed(1)}%` : ''}`;
    const sub = !stock.length ? ''
      : avgSoldPrice != null
        ? `${stock.length} piezas × ${mx(avgSoldPrice)} promedio de venta`
        : 'aún no hay ventas registradas para estimar';

    document.getElementById('kpis-inv').innerHTML = `
      <div class="kpi in"><div class="lbl">${icon('package', 14)} Piezas visibles</div><div class="val" data-raw="${items.length}" data-format="int">0</div></div>
      <div class="kpi r"><div class="lbl">${icon('dollar', 14)} Invertido (en stock)</div><div class="val" data-raw="${invCost}" data-format="money">$0</div></div>
      <div class="kpi g"><div class="lbl">${icon('trendUp', 14)} Venta potencial estimada</div><div class="val" data-raw="${potVenta}" data-format="money">$0</div><div class="sub">${sub}</div></div>
      <div class="kpi ${ganancia < 0 ? 'r' : 'g'}"><div class="lbl">${icon('dollar', 14)} Ganancia (ya vendido)</div><div class="val" data-raw="${ganancia}" data-format="money">$0</div><div class="sub">${subGan}</div></div>
      <div class="kpi ${neto < 0 ? 'r' : 'g'}"><div class="lbl">${icon('chart', 14)} Neto (recuperado − costo)</div><div class="val" data-raw="${neto}" data-format="money">$0</div><div class="sub">${mx(ingresos)} recuperados de ${mx(costoTodo)} invertidos</div></div>`;
    animateKpis('kpis-inv');
  }


  function renderTable(items) {
    const cols = Fields.columns('inventory');
    const variantAsColumn = Fields.variantIsColumn('inventory');

    let head = `<th style="cursor:default;width:34px"><input type="checkbox" id="chk-all" onclick="Inventario.toggleAll(this.checked)"></th>`;
    cols.forEach(f => { head += Fields.headerCell(f, sort, 'Inventario.setSort'); });
    head += `<th>Estado</th><th></th>`;
    document.getElementById('head-inv').innerHTML = head;

    const tb = document.getElementById('body-inv');
    if (!items.length) {
      tb.innerHTML = `<tr><td colspan="${cols.length + 3}"><div class="empty">${icon('package', 30)}No hay productos que coincidan.</div></td></tr>`;
      return;
    }
    tb.innerHTML = items.map(r => {
      const sold = !!r.sale_date;
      const chk = `<input type="checkbox" ${selected.has(r.id) ? 'checked' : ''} onclick="Inventario.toggleOne(${r.id}, this.checked)">`;
      let cells = `<td>${chk}</td>`;
      cols.forEach(f => { cells += Fields.cell(f, r, { variantAsColumn }); });
      cells += `<td>${sold ? '<span class="pill vendida">Vendida</span>' : '<span class="pill stock">En stock</span>'}</td>`;
      cells += `<td>
        ${!sold ? `<button class="rowbtn" title="Vender" onclick="Inventario.openSell(${r.id})">${icon('dollar', 16)}</button>` : `<button class="rowbtn" title="Anular venta" onclick="Inventario.voidSale(${r.id})">${icon('undo', 16)}</button>`}
        <button class="rowbtn" title="Editar" onclick="Inventario.openModal(${r.id})">${icon('edit', 16)}</button>
        <button class="rowbtn" title="Eliminar" onclick="Inventario.remove(${r.id})">${icon('trash', 16)}</button>
      </td>`;
      return `<tr class="${sold ? 'sold' : ''} ${selected.has(r.id) ? 'rowsel' : ''}">${cells}</tr>`;
    }).join('');
  }

  /**
   * Panel de filtros por campo del registro.
   *
   * Sin esto no había forma de aislar "las piezas del America con la temporada
   * equivocada": los únicos filtros eran búsqueda, estado, categoría y pedido, y la
   * columna de temporada estaba oculta. Había que reconocerlas a ojo entre 51 filas.
   *
   * Los desplegables se llenan con los valores que EXISTEN en los datos, no con una
   * lista configurada: así no hay que recordar si se escribió "2025/2026" o "25/26".
   */
  function openFilters() {
    const campos = Fields.all().filter(f => f.filterable && f.filter_values && f.filter_values.length);

    document.getElementById('filt-fields').innerHTML = campos.map(f => {
      const actual = attrFilters[f.field_key] ?? '';
      const opts = f.filter_values.map(v =>
        `<option value="${esc(v.valor)}" ${v.valor === actual ? 'selected' : ''}>${esc(v.valor)} (${v.n})</option>`
      ).join('');
      return `<div class="beditrow">
        <label class="beditchk" style="cursor:default"><span>${esc(f.label)}</span></label>
        <div class="beditval">
          <select onchange="Inventario.setAttrFilter('${f.field_key}', this.value)">
            <option value="">— cualquiera —</option>${opts}
          </select>
        </div>
      </div>`;
    }).join('') || '<p class="muted" style="font-size:.82rem">No hay campos con datos para filtrar.</p>';

    document.getElementById('filt-bg').classList.add('show');
  }

  function closeFilters() {
    document.getElementById('filt-bg').classList.remove('show');
  }

  function setAttrFilter(key, value) {
    if (value === '') { delete attrFilters[key]; } else { attrFilters[key] = value; }
    // La selección se limpia: las piezas seleccionadas podrían ya no estar visibles, y
    // aplicar un cambio en lote a algo que no ves es la peor forma de perder datos.
    clearSel();
    render();
  }

  function clearAttrFilters() {
    attrFilters = {};
    clearSel();
    closeFilters();
    render();
  }

  /** Etiquetas de los filtros activos, para que se vean sin abrir el panel. */
  function renderFilterChips() {
    const cont = document.getElementById('filt-chips');
    if (!cont) return;
    const claves = Object.keys(attrFilters);
    const btn = document.getElementById('filt-btn-count');
    if (btn) btn.textContent = claves.length ? ` (${claves.length})` : '';

    cont.innerHTML = claves.map(k => {
      const f = Fields.all().find(x => x.field_key === k);
      return `<span class="fchip">${esc(f ? f.label : k)}: <b>${esc(attrFilters[k])}</b>
        <span class="fchip-x" onclick="Inventario.setAttrFilter('${k}', '')" title="Quitar">${icon('x', 12)}</span></span>`;
    }).join('');
  }
  /** Abre el selector de columnas de esta tabla y la redibuja al cambiar. */
  function pickColumns() {
    Fields.openPicker('inventory', () => renderTable(cache));
  }
  function setSort(col) {
    if (sort.col === col) sort.dir *= -1; else { sort.col = col; sort.dir = 1; }
    render();
  }

  function toggleOne(id, on) {
    if (on) selected.add(id); else selected.delete(id);
    renderBulkBar();
    renderTable(cache);
  }

  function toggleAll(on) {
    // Antes solo seleccionaba piezas en stock, porque las únicas acciones de lote
    // eran asignar fecha de llegada y vender. Corregir un atributo mal capturado
    // aplica también a las YA VENDIDAS, y excluirlas dejaba imposible arreglarlas.
    // La venta en lote filtra las vendidas por su cuenta, así que esto es seguro.
    cache.forEach(r => { if (on) selected.add(r.id); else selected.delete(r.id); });
    renderBulkBar();
    renderTable(cache);
  }

  function clearSel() { selected.clear(); renderBulkBar(); renderTable(cache); }

  function renderBulkBar() {
    const bar = document.getElementById('bulkbar');
    const n = selected.size;
    bar.classList.toggle('show', n > 0);
    if (!n) return;
    bar.innerHTML = `
      <span>${n} seleccionada${n === 1 ? '' : 's'}</span>
      <button class="btn sm ghost" onclick="Inventario.openBulkEdit()">${icon('edit', 14)} Editar seleccionadas</button>
      <button class="btn sm ghost" onclick="Inventario.openBulkArrival()">${icon('inbox', 14)} Asignar fecha de llegada</button>
      <button class="btn sm gold" onclick="Inventario.openBulkSell()">${icon('dollar', 14)} Vender seleccionadas</button>
      <button class="btn sm ghost" onclick="Inventario.clearSel()">Cancelar</button>`;
  }

  /**
   * Edición en lote.
   *
   * El formulario se genera desde el registro de campos, así que sirve para cualquier
   * campo de cualquier negocio: una refaccionaria que configure "marca" la ve aquí sin
   * que nadie escriba código.
   *
   * CADA CAMPO LLEVA SU PROPIA CASILLA, y solo se envían los marcados. Es lo que
   * distingue "no toques este campo" de "pon este campo en blanco". Sin esa
   * distinción, corregir la versión de 116 piezas les borraría el costo.
   */
  function openBulkEdit() {
    if (!selected.size) return;
    const campos = Fields.all().filter(f => f.editable);

    document.getElementById('bedit-count').textContent = selected.size;
    document.getElementById('bedit-fields').innerHTML = campos.map(f => {
      const opciones = (f.field_type === 'select' && Array.isArray(f.options))
        ? `<select id="be-v-${f.id}" disabled onchange="Inventario.bulkEditSummary()"><option value="">— sin valor —</option>` +
          f.options.map(o => `<option value="${esc(o)}">${esc(o)}</option>`).join('') + '</select>'
        : `<input id="be-v-${f.id}" type="${f.field_type === 'number' ? 'number' : (f.field_type === 'date' ? 'date' : 'text')}" disabled oninput="Inventario.bulkEditSummary()">`;

      return `<div class="beditrow">
        <label class="beditchk">
          <input type="checkbox" id="be-c-${f.id}" onchange="Inventario.bulkEditToggle(${f.id})">
          <span>${esc(f.label)}</span>
        </label>
        <div class="beditval">${opciones}</div>
      </div>`;
    }).join('');

    document.getElementById('bedit-summary').innerHTML =
      '<span class="muted">Marca los campos que quieres cambiar.</span>';
    document.getElementById('bedit-bg').classList.add('show');
  }

  /** La casilla habilita su campo: un campo deshabilitado no se envía. */
  function bulkEditToggle(id) {
    const on = document.getElementById(`be-c-${id}`).checked;
    const input = document.getElementById(`be-v-${id}`);
    input.disabled = !on;
    if (on) input.focus();
    bulkEditSummary();
  }

  /** Resumen de lo que va a pasar, antes de aplicarlo. */
  function bulkEditSummary() {
    const cambios = collectBulkEdit();
    const claves = Object.keys(cambios);
    const box = document.getElementById('bedit-summary');
    if (!claves.length) {
      box.innerHTML = '<span class="muted">Marca los campos que quieres cambiar.</span>';
      return;
    }
    const detalle = claves.map(k => {
      const f = Fields.all().find(x => x.field_key === k);
      const v = cambios[k];
      return `<b>${esc(f.label)}</b> → ${v === '' ? '<i>vaciar</i>' : esc(v)}`;
    }).join('<br>');
    box.innerHTML = `Se aplicará a <b>${selected.size}</b> pieza(s):<br>${detalle}`;
  }

  /** Solo los campos con casilla marcada. */
  function collectBulkEdit() {
    const out = {};
    Fields.all().filter(f => f.editable).forEach(f => {
      const chk = document.getElementById(`be-c-${f.id}`);
      if (chk && chk.checked) {
        out[f.field_key] = document.getElementById(`be-v-${f.id}`).value;
      }
    });
    return out;
  }

  function closeBulkEdit() {
    document.getElementById('bedit-bg').classList.remove('show');
  }

  async function confirmBulkEdit() {
    const fields = collectBulkEdit();
    if (!Object.keys(fields).length) {
      toast('Marca al menos un campo');
      return;
    }
    const btn = document.getElementById('bedit-go');
    btn.disabled = true;
    btn.textContent = 'Aplicando…';
    try {
      const res = await Api.post('items.php?action=bulk-update', { ids: [...selected], fields });
      toast(`${res.updated} pieza(s) actualizada(s): ${res.fields.join(', ')}`);
      closeBulkEdit();
      clearSel();
      render();
    } catch (e) {
      toast('No se pudieron aplicar los cambios');
    } finally {
      btn.disabled = false;
      btn.textContent = 'Aplicar cambios';
    }
  }
  async function openBulkArrival() {
    const fecha = prompt('Fecha de llegada (YYYY-MM-DD):', today());
    if (!fecha) return;
    await Api.post('items.php?action=bulk-arrival', { ids: [...selected], arrival_date: fecha });
    toast('Fecha de llegada asignada');
    clearSel();
    render();
  }

  function openBulkSell() {
    const items = [...selected].map(id => cache.find(r => r.id === id)).filter(r => r && !r.sale_date);
    if (!items.length) { toast('No hay piezas sin vender seleccionadas'); return; }
    document.getElementById('bulk-fecha').value = today();
    document.getElementById('bulk-precio').value = '';
    document.getElementById('bulk-list').innerHTML = items.map(r => `
      <div class="bulk-row" data-id="${r.id}">
        <div class="bulk-nm"><b>${esc(r.name)}</b><div class="muted">${mx(r.total_cost)} costo</div></div>
        <input type="number" step="1" class="bulk-precio-row" placeholder="Precio" oninput="Inventario.bulkRowCalc()">
        <div class="bulk-gan" data-gid="${r.id}">—</div>
      </div>`).join('');
    document.getElementById('bulk-bg').classList.add('show');
  }

  function applyBulkPrice() {
    const v = document.getElementById('bulk-precio').value;
    if (v === '') return;
    document.querySelectorAll('.bulk-precio-row').forEach(inp => { inp.value = v; });
    bulkRowCalc();
  }

  function bulkRowCalc() {
    let total = 0, gan = 0;
    document.querySelectorAll('#bulk-list .bulk-row').forEach(row => {
      const id = +row.dataset.id;
      const r = cache.find(x => x.id === id);
      const v = parseFloat(row.querySelector('.bulk-precio-row').value);
      const gEl = row.querySelector(`[data-gid="${id}"]`);
      if (!isNaN(v)) {
        const g = Math.round(v - Number(r.total_cost || 0));
        gEl.textContent = mx(g);
        total += v; gan += g;
      } else {
        gEl.textContent = '—';
      }
    });
    document.getElementById('bulk-summary').innerHTML = `<div class="row"><span>Total venta</span><span class="v">${mx(total)}</span></div><div class="row total"><span>Ganancia</span><span class="v">${mx(gan)}</span></div>`;
  }

  function closeBulk() { document.getElementById('bulk-bg').classList.remove('show'); }

  async function confirmBulkSell() {
    const fecha = document.getElementById('bulk-fecha').value || today();
    const rows = [...document.querySelectorAll('#bulk-list .bulk-row')].map(row => ({
      id: +row.dataset.id,
      sale_price: row.querySelector('.bulk-precio-row').value,
    })).filter(r => r.sale_price !== '');
    if (!rows.length) { toast('Pon al menos un precio'); return; }
    const res = await Api.post('items.php?action=bulk-sell', { rows, sale_date: fecha });
    toast(`${res.count} venta(s) registrada(s)`);
    closeBulk();
    clearSel();
    render();
  }

  function openSell(id) {
    sellingId = id;
    const r = cache.find(x => x.id === id);
    document.getElementById('sell-info').innerHTML = `<b>${esc(r.name)}</b><br>Costo total: ${mx(r.total_cost)}`;
    document.getElementById('sell-precio').value = r.sale_price || '';
    document.getElementById('sell-fecha').value = today();
    sellCalc();
    document.getElementById('sell-bg').classList.add('show');
  }

  function sellCalc() {
    const r = cache.find(x => x.id === sellingId);
    const v = parseFloat(document.getElementById('sell-precio').value);
    const g = isNaN(v) ? null : Math.round(v - Number(r.total_cost || 0));
    document.getElementById('sell-ganancia').textContent = g == null ? '—' : mx(g);
  }

  function closeSell() { document.getElementById('sell-bg').classList.remove('show'); sellingId = null; }

  async function confirmSell() {
    const v = parseFloat(document.getElementById('sell-precio').value);
    if (isNaN(v)) { toast('Pon un precio de venta'); return; }
    const fecha = document.getElementById('sell-fecha').value || today();
    await Api.post(`items.php?action=sell&id=${sellingId}`, { sale_price: v, sale_date: fecha });
    toast('Venta registrada');
    closeSell();
    render();
  }

  async function voidSale(id) {
    if (!confirm('¿Anular esta venta? La pieza vuelve a stock.')) return;
    await Api.post(`items.php?action=void-sale&id=${id}`, {});
    toast('Venta anulada — vuelve a stock');
    render();
  }

  async function remove(id) {
    if (!confirm('¿Borrar este producto?')) return;
    await Api.del(`items.php?id=${id}`);
    toast('Producto borrado');
    render();
  }

  async function openModal(id) {
    editingId = id;
    let r = {};
    if (id) {
      r = cache.find(x => x.id === id);
      if (!r) {
        const res = await Api.get(`items.php?id=${id}`);
        r = res.item;
      }
    }
    document.getElementById('modal-title').textContent = id ? 'Editar producto' : 'Agregar producto';
    const attrs = Attributes.all();
    document.getElementById('modal-fields').innerHTML = `
      <div class="field full"><label>Nombre</label><input id="f-name" value="${esc(r.name || '')}"></div>
      <div class="field"><label>Categoría</label><input id="f-category" value="${esc(r.category || '')}"></div>
      <div class="field"><label>Subcategoría</label><input id="f-subcategory" value="${esc(r.subcategory || '')}"></div>
      ${attrs.map(d => `<div class="field"><label>${esc(d.label)}</label>${Attributes.renderInput(d, (r.attributes || {})[d.field_key], 'modal')}</div>`).join('')}
      <div class="field"><label>Proveedor</label><input id="f-supplier" value="${esc(r.supplier_name || '')}"></div>
      <div class="field"><label>Costo (MXN)</label><input id="f-cost" type="number" step="1" value="${r.cost ?? ''}" oninput="Inventario.modalCalc()"></div>
      <div class="field"><label>Costo envío (MXN)</label><input id="f-shipping" type="number" step="1" value="${r.shipping_cost ?? ''}" oninput="Inventario.modalCalc()"></div>
      <div class="field"><label>Precio venta (MXN)</label><input id="f-sale" type="number" step="1" value="${r.sale_price ?? ''}" oninput="Inventario.modalCalc()"></div>
      <div class="field"><label>Fecha de compra</label><input id="f-fcompra" type="date" value="${r.purchase_date || ''}"></div>
      <div class="field"><label>Fecha de llegada</label><input id="f-fllegada" type="date" value="${r.arrival_date || ''}"></div>
      <div class="field full"><label>Fecha de venta <span class="hint">(vacía = aún en stock)</span></label><input id="f-fventa" type="date" value="${r.sale_date || ''}"></div>
    `;
    modalCalc();
    document.getElementById('modal-bg').classList.add('show');
  }

  function modalCalc() {
    const c = parseFloat(document.getElementById('f-cost').value) || 0;
    const s = parseFloat(document.getElementById('f-shipping').value) || 0;
    const v = parseFloat(document.getElementById('f-sale').value);
    const g = isNaN(v) ? null : Math.round(v - c - s);
    document.getElementById('calc-ganancia').textContent = g == null ? '$0.00' : mx(g);
  }

  function closeModal() { document.getElementById('modal-bg').classList.remove('show'); editingId = null; }

  async function save() {
    const container = document.getElementById('modal-fields');
    const data = {
      name: document.getElementById('f-name').value,
      category: document.getElementById('f-category').value,
      subcategory: document.getElementById('f-subcategory').value,
      supplier: document.getElementById('f-supplier').value,
      cost: document.getElementById('f-cost').value,
      shipping_cost: document.getElementById('f-shipping').value,
      sale_price: document.getElementById('f-sale').value,
      purchase_date: document.getElementById('f-fcompra').value,
      arrival_date: document.getElementById('f-fllegada').value,
      sale_date: document.getElementById('f-fventa').value,
      attributes: Attributes.collect(container),
    };
    try {
      if (editingId) {
        await Api.put(`items.php?id=${editingId}`, data);
      } else {
        await Api.post('items.php', data);
      }
      toast('Guardado');
      closeModal();
      render();
    } catch (e) { /* Api ya mostró el error */ }
  }

  return {
    render, refreshMeta, setSort, pickColumns, toggleOne, toggleAll, clearSel,
    openFilters, closeFilters, setAttrFilter, clearAttrFilters,
    openBulkEdit, bulkEditToggle, bulkEditSummary, closeBulkEdit, confirmBulkEdit,
    openBulkArrival, openBulkSell, applyBulkPrice, bulkRowCalc, closeBulk, confirmBulkSell,
    openSell, sellCalc, closeSell, confirmSell, voidSale, remove,
    openModal, modalCalc, closeModal, save,
  };
})();
