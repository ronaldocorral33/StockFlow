/* Tab "Entradas": captura de un pedido de compra por lote, con reparto de envío entre piezas. */
const Entradas = (() => {
  let rows = [];
  let rowSeq = 0;

  function init() {
    document.getElementById('p-fcompra').value = today();
    addRow();
    renderKpis();
  }

  function addRow(count = 1) {
    for (let i = 0; i < count; i++) {
      rows.push({ rid: ++rowSeq });
    }
    renderRows();
  }

  function removeRow(rid) {
    rows = rows.filter(r => r.rid !== rid);
    renderRows();
  }

  function renderRows() {
    const attrDefs = Attributes.all();
    const head = document.getElementById('entrada-head');
    head.innerHTML = `<th>Nombre</th>${attrDefs.map(d => `<th>${esc(d.label)}</th>`).join('')}
      <th>Costo</th><th>+Envío c/u</th><th>Costo total</th><th>Venta <span style="font-weight:400;text-transform:none">(opcional)</span></th><th></th>`;

    const tb = document.getElementById('entrada-items');
    if (!rows.length) {
      tb.innerHTML = `<tr><td colspan="${5 + attrDefs.length}"><div class="empty">Agrega al menos un producto.</div></td></tr>`;
      return;
    }
    tb.innerHTML = rows.map(r => `
      <tr data-rid="${r.rid}">
        <td><input class="wide" data-f="name" placeholder="Ej. FC Barcelona" oninput="Entradas.calc()"></td>
        ${attrDefs.map(d => `<td>${Attributes.renderInput(d, '', 'row')}</td>`).join('')}
        <td><input data-f="cost" type="number" step="0.01" placeholder="0" oninput="Entradas.calc()"></td>
        <td class="calc-cell" data-envio>$0</td>
        <td class="calc-cell" data-total>$0</td>
        <td><input data-f="sale_price" type="number" step="0.01" placeholder="opcional"></td>
        <td><button class="rowbtn" onclick="Entradas.removeRow(${r.rid})" title="Quitar">${icon('trash', 16)}</button></td>
      </tr>
    `).join('');
    calc();
  }

  function currency() {
    return document.getElementById('p-moneda').value;
  }

  function calc() {
    const envioTotalRaw = parseFloat(document.getElementById('p-envio').value) || 0;
    const tc = parseFloat(document.getElementById('p-tc').value) || 1;
    const factor = currency() === 'USD' ? tc : 1;
    const envioTotal = envioTotalRaw * factor;
    const trs = [...document.querySelectorAll('#entrada-items tr[data-rid]')];
    const n = trs.length;
    const envioCU = n > 0 ? envioTotal / n : 0;

    let sumCosto = 0, sumVenta = 0;
    trs.forEach(tr => {
      const cost = (parseFloat(tr.querySelector('[data-f="cost"]').value) || 0) * factor;
      const total = cost + envioCU;
      tr.querySelector('[data-envio]').textContent = mx(envioCU);
      tr.querySelector('[data-total]').textContent = mx(total);
      const venta = (parseFloat(tr.querySelector('[data-f="sale_price"]').value) || 0) * factor;
      sumCosto += total;
      if (venta) sumVenta += venta;
    });

    document.getElementById('envio-reparto').textContent = n
      ? `Envío repartido entre ${n} pieza${n === 1 ? '' : 's'}: ${mx(envioCU)} c/u`
      : '';
    document.getElementById('entrada-summary').innerHTML = `
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

  async function guardar() {
    const validRows = [...document.querySelectorAll('#entrada-items tr[data-rid]')]
      .map(tr => {
        const name = tr.querySelector('[data-f="name"]').value.trim();
        if (!name) return null;
        return {
          name,
          cost: tr.querySelector('[data-f="cost"]').value,
          sale_price: tr.querySelector('[data-f="sale_price"]').value,
          attributes: Attributes.collect(tr),
        };
      })
      .filter(Boolean);

    if (!validRows.length) {
      toast('Agrega al menos un producto con nombre');
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
      await Api.post('purchase_orders.php', { header, items: validRows });
      toast(`Pedido guardado con ${validRows.length} pieza(s)`);
      reset();
      renderKpis();
    } catch (e) { /* Api.post ya mostró el toast de error */ }
  }

  function reset() {
    if (rows.length && !confirm('¿Limpiar la captura actual?')) return;
    rows = [];
    document.getElementById('p-num').value = '';
    document.getElementById('p-prov').value = '';
    document.getElementById('p-envio').value = '';
    document.getElementById('p-fllegada').value = '';
    addRow();
  }

  return { init, addRow, removeRow, calc, guardar, reset, renderKpis };
})();
