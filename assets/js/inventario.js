/* Tab "Inventario": tabla con búsqueda/filtro/orden, selección múltiple, modal de edición y venta. */
const Inventario = (() => {
  let cache = [];
  let selected = new Set();
  let sort = { col: 'created_at', dir: 'desc' };
  let editingId = null;
  let sellingId = null;
  let metaLoaded = false;
  let avgSoldPrice = null; // precio de venta promedio histórico, para estimar piezas sin precio

  // Las columnas ya no se escriben aquí: salen del registro de campos del negocio
  // (attribute_definitions), que el usuario controla desde el selector de columnas.
  // Antes esta constante era un arreglo literal de 9 columnas, y por eso Categoría
  // aparecía siempre aunque estuviera vacía en las 738 piezas.

  async function loadMeta() {
    if (metaLoaded) return;
    const meta = await Api.get('items.php?action=meta');
    const catSel = document.getElementById('filt-categoria');
    catSel.innerHTML = '<option value="">Todas las categorías</option>' +
      meta.categories.map(c => `<option value="${esc(c)}">${esc(c)}</option>`).join('');
    const pedSel = document.getElementById('filt-pedido');
    pedSel.innerHTML = '<option value="">Todos los pedidos</option>' +
      meta.purchase_orders.map(p => `<option value="${p.id}">#${p.order_number}</option>`).join('');
    avgSoldPrice = meta.avg_sold_price != null ? Number(meta.avg_sold_price) : null;
    // El registro de campos define qué columnas dibujar. Se carga una vez por sesión.
    await Fields.load();
    metaLoaded = true;
  }

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

  async function render() {
    await loadMeta();
    const f = currentFilters();
    const qs = new URLSearchParams(Object.entries(f).filter(([, v]) => v !== '')).toString();
    const res = await Api.get('items.php?' + qs);
    cache = res.items;
    renderKpis(cache);
    renderTable(cache);
    renderBulkBar();
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
    cache.forEach(r => { if (!r.sale_date) { if (on) selected.add(r.id); else selected.delete(r.id); } });
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
      <button class="btn sm ghost" onclick="Inventario.openBulkArrival()">${icon('inbox', 14)} Asignar fecha de llegada</button>
      <button class="btn sm gold" onclick="Inventario.openBulkSell()">${icon('dollar', 14)} Vender seleccionadas</button>
      <button class="btn sm ghost" onclick="Inventario.clearSel()">Cancelar</button>`;
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
    render, setSort, pickColumns, toggleOne, toggleAll, clearSel,
    openBulkArrival, openBulkSell, applyBulkPrice, bulkRowCalc, closeBulk, confirmBulkSell,
    openSell, sellCalc, closeSell, confirmSell, voidSale, remove,
    openModal, modalCalc, closeModal, save,
  };
})();
