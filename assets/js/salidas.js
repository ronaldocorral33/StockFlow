/* Tab "Salidas": lista de productos vendidos, con búsqueda y filtro por mes. */
const Salidas = (() => {
  let cache = [];

  function mesKey(d) { return d ? d.slice(0, 7) : null; }
  function mesNombre(k) {
    if (!k) return '';
    const [y, m] = k.split('-');
    const M = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
    return M[+m - 1] + ' ' + y;
  }

  async function render() {
    const q = document.getElementById('search-sal').value;
    const qs = new URLSearchParams({ status: 'sold', q, sort: 'sale_date', dir: 'desc' }).toString();
    const res = await Api.get('items.php?' + qs);
    cache = res.items;
    await Fields.load();
    fillMonthFilter();
    renderKpis(cache);
    renderTable();
  }

  function fillMonthFilter() {
    const sel = document.getElementById('filt-mes');
    const cur = sel.value;
    const meses = [...new Set(cache.map(r => mesKey(r.sale_date)))].sort().reverse();
    sel.innerHTML = '<option value="">Todos los meses</option>' +
      meses.map(m => `<option value="${m}">${mesNombre(m)}</option>`).join('');
    sel.value = cur;
  }

  function renderKpis(items) {
    const totVenta = items.reduce((s, r) => s + Number(r.sale_price || 0), 0);
    const totGan = items.reduce((s, r) => s + Number(r.profit || 0), 0);
    const margen = totVenta ? (totGan / totVenta * 100) : 0;
    document.getElementById('kpis-sal').innerHTML = `
      <div class="kpi g"><div class="lbl">${icon('dollar', 14)} Ganancia</div><div class="val pos" data-raw="${totGan}" data-format="money">$0</div></div>
      <div class="kpi b"><div class="lbl">${icon('outbox', 14)} Ventas</div><div class="val" data-raw="${totVenta}" data-format="money">$0</div><div class="sub">${items.length} piezas</div></div>
      <div class="kpi"><div class="lbl">${icon('trendUp', 14)} Margen</div><div class="val" data-raw="${margen}" data-format="pct">0%</div></div>`;
    animateKpis('kpis-sal');
  }

  function renderTable() {
    const fm = document.getElementById('filt-mes').value;
    const rows = cache.filter(r => !fm || mesKey(r.sale_date) === fm);

    // Las columnas salen del registro, igual que en Inventario, pero con su propia
    // bandera de visibilidad: en Salidas interesa el margen; en Inventario, el costo.
    const cols = Fields.columns('sales');
    const variantAsColumn = Fields.variantIsColumn('sales');

    let head = '';
    cols.forEach(f => { head += `<th>${esc(f.label)}</th>`; });
    head += '<th></th>';
    document.getElementById('head-sal').innerHTML = head;

    const tb = document.getElementById('body-sal');
    if (!rows.length) {
      tb.innerHTML = `<tr><td colspan="${cols.length + 1}"><div class="empty">${icon('outbox', 30)}Aún no has vendido nada este periodo.</div></td></tr>`;
      return;
    }
    tb.innerHTML = rows.map(r => {
      let cells = '';
      cols.forEach(f => { cells += Fields.cell(f, r, { variantAsColumn }); });
      cells += `<td>
        <button class="rowbtn" title="Editar" onclick="Inventario.openModal(${r.id})">${icon('edit', 16)}</button>
        <button class="rowbtn" title="Anular venta" onclick="Salidas.voidSale(${r.id})">${icon('undo', 16)}</button>
      </td>`;
      return `<tr>${cells}</tr>`;
    }).join('');
  }

  /** Abre el selector de columnas de Salidas. */
  function pickColumns() {
    Fields.openPicker('sales', renderTable);
  }
  async function voidSale(id) {
    if (!confirm('¿Anular esta venta? La pieza vuelve a stock.')) return;
    await Api.post(`items.php?action=void-sale&id=${id}`, {});
    toast('Venta anulada — vuelve a stock');
    render();
  }

  return { render, voidSale, pickColumns };
})();
