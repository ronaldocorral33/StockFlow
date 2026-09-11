/* Pantalla de PEDIDOS: ver, corregir y borrar lo que ya se registró.
 *
 * QUÉ RESUELVE
 * "Registro algo en entradas, me di cuenta que está mal y ya no tengo forma de
 * quitarlo o cambiarlo." Los pedidos existían en la base de datos desde el principio,
 * pero no tenían pantalla: solo aparecían como valores del filtro de Inventario y como
 * barras en la gráfica de Reportes. Un pedido mal capturado no tenía salida.
 *
 * POR QUÉ UNA PESTAÑA Y NO UN BOTÓN DENTRO DE ENTRADAS
 * Entradas es un formulario de captura: siempre está en blanco, listo para el pedido
 * siguiente. Meter ahí la lista de pedidos pasados mezclaría dos modos — escribir algo
 * nuevo y revisar algo viejo — en la misma pantalla. Y la lista necesita su propio
 * espacio: son 44 pedidos con seis cifras cada uno.
 *
 * LA REGLA DE BORRADO VIENE DEL SERVIDOR, NO SE DEDUCE AQUÍ
 * Cada pedido llega con `puede_borrarse` ya calculado. Si esta pantalla lo volviera a
 * deducir de `vendidas > 0`, habría dos versiones de la misma regla y podrían
 * separarse. El servidor la vuelve a verificar al borrar, así que el botón deshabilitado
 * es una cortesía, no la protección.
 */
const Pedidos = (() => {
  let cache = [];
  let editandoId = null;

  // ---------------------------------------------------------------
  // Listar
  // ---------------------------------------------------------------

  async function render() {
    const q = (document.getElementById('search-ped') || {}).value || '';
    try {
      const r = await Api.get('purchase_orders.php' + (q ? `?q=${encodeURIComponent(q)}` : ''));
      cache = r.orders || [];
    } catch (e) {
      toast(e && e.message ? e.message : 'No se pudieron cargar los pedidos', 'error');
      return;
    }
    pintarKpis();
    pintarTabla();
  }

  function pintarKpis() {
    const costo = cache.reduce((a, p) => a + p.costo_total, 0);
    const recuperado = cache.reduce((a, p) => a + p.recuperado, 0);
    const piezas = cache.reduce((a, p) => a + p.piezas, 0);
    const vendidas = cache.reduce((a, p) => a + p.vendidas, 0);
    const neto = recuperado - costo;

    // Mismo vocabulario que las otras pantallas (kpi/lbl/val/sub + data-format), para
    // que animateKpis las cuente igual y no haya un segundo estilo de tarjeta.
    document.getElementById('kpis-ped').innerHTML = `
      <div class="kpi in"><div class="lbl">${icon('inbox', 14)} Pedidos</div>
        <div class="val" data-raw="${cache.length}" data-format="int">0</div></div>
      <div class="kpi b"><div class="lbl">${icon('package', 14)} Piezas registradas</div>
        <div class="val" data-raw="${piezas}" data-format="int">0</div>
        <div class="sub">${vendidas} vendidas · ${piezas - vendidas} disponibles</div></div>
      <div class="kpi r"><div class="lbl">${icon('dollar', 14)} Invertido</div>
        <div class="val" data-raw="${costo}" data-format="money">$0</div>
        <div class="sub">costo + envío de todas las piezas</div></div>
      <div class="kpi ${neto < 0 ? 'r' : 'g'}"><div class="lbl">${icon('chart', 14)} Neto</div>
        <div class="val" data-raw="${neto}" data-format="money">$0</div>
        <div class="sub">${mx(recuperado)} recuperados de ${mx(costo)} invertidos</div></div>`;
    animateKpis('kpis-ped');
  }

  function pintarTabla() {
    const head = document.getElementById('head-ped');
    const body = document.getElementById('body-ped');

    head.innerHTML = ['Pedido', 'Proveedor', 'Compra', 'Llegada', 'Piezas', 'Invertido', 'Recuperado', 'Neto', '']
      .map(h => `<th>${h}</th>`).join('');

    if (!cache.length) {
      body.innerHTML = `<tr><td colspan="9" class="empty">Todavía no hay pedidos registrados.</td></tr>`;
      return;
    }

    body.innerHTML = cache.map(p => {
      const netoCls = p.neto > 0 ? 'pos' : (p.neto < 0 ? 'neg' : '');
      return `<tr>
        <td><b>#${esc(p.order_number)}</b>${p.notes ? `<div class="muted">${esc(p.notes)}</div>` : ''}</td>
        <td>${esc(p.supplier_name || '—')}</td>
        <td>${esc(p.purchase_date || '—')}</td>
        <td>${esc(p.arrival_date || '—')}</td>
        <td>${p.piezas}${p.vendidas ? `<div class="muted">${p.vendidas} vendida${p.vendidas === 1 ? '' : 's'}</div>` : ''}</td>
        <td class="money">${mx(p.costo_total)}</td>
        <td class="money">${p.recuperado ? mx(p.recuperado) : '—'}</td>
        <td class="money ${netoCls}">${mx(p.neto)}</td>
        <td style="white-space:nowrap">
          <button class="rowbtn" title="Ver las piezas de este pedido" onclick="Pedidos.verPiezas(${p.id})">${icon('eye', 15)}</button>
          <button class="rowbtn" title="Corregir el pedido" onclick="Pedidos.abrirEdicion(${p.id})">${icon('edit', 15)}</button>
          <button class="rowbtn" ${p.puede_borrarse ? '' : 'disabled'}
                  title="${p.puede_borrarse ? 'Borrar el pedido y sus piezas' : 'No se puede borrar: ya vendiste piezas de este pedido'}"
                  onclick="Pedidos.borrar(${p.id})">${icon('trash', 15)}</button>
        </td>
      </tr>`;
    }).join('');
  }

  /** Salta a Inventario con el filtro de este pedido ya puesto. */
  function verPiezas(id) {
    const p = cache.find(x => x.id === id);
    if (!p) return;
    switchTab('inventario');
    // Se reusa el filtro que Inventario ya tiene, en vez de dibujar aquí una segunda
    // tabla de piezas: es la misma lista, con las mismas columnas configuradas.
    const sel = document.getElementById('filt-pedido');
    if (sel) { sel.value = String(id); Inventario.render(); }
  }

  // ---------------------------------------------------------------
  // Corregir
  // ---------------------------------------------------------------

  function abrirEdicion(id) {
    const p = cache.find(x => x.id === id);
    if (!p) return;
    editandoId = id;

    document.getElementById('ped-titulo').textContent = `Corregir pedido #${p.order_number}`;
    document.getElementById('ped-numero').value = p.order_number;
    document.getElementById('ped-proveedor').value = p.supplier_name || '';
    document.getElementById('ped-compra').value = p.purchase_date || '';
    document.getElementById('ped-llegada').value = p.arrival_date || '';
    document.getElementById('ped-envio').value = p.shipping_total_original;
    document.getElementById('ped-notas').value = p.notes || '';

    // La moneda se muestra pero no se edita, y se dice por qué en la pantalla misma:
    // el costo de cada pieza ya se guardó convertido y redondeado, así que el monto
    // original en dólares no se puede reconstruir.
    document.getElementById('ped-moneda').textContent =
      p.currency === 'USD' ? `USD a ${p.exchange_rate}` : 'Pesos (MXN)';

    document.getElementById('ped-aviso').innerHTML = p.piezas
      ? `El envío se reparte otra vez entre las <b>${p.piezas}</b> piezas del pedido. ` +
        `Las fechas y el proveedor se corrigen solo en las piezas que todavía tienen el valor viejo: ` +
        `si a alguna le pusiste otra fecha a mano, se respeta.`
      : 'Este pedido no tiene piezas.';

    document.getElementById('ped-bg').classList.add('show');
  }

  function cerrarEdicion() {
    document.getElementById('ped-bg').classList.remove('show');
    editandoId = null;
  }

  async function guardar() {
    if (!editandoId) return;
    const cuerpo = {
      order_number: document.getElementById('ped-numero').value,
      supplier: document.getElementById('ped-proveedor').value,
      purchase_date: document.getElementById('ped-compra').value,
      arrival_date: document.getElementById('ped-llegada').value,
      shipping_total: document.getElementById('ped-envio').value,
      notes: document.getElementById('ped-notas').value,
    };
    try {
      const r = await Api.put(`purchase_orders.php?id=${editandoId}`, cuerpo);
      cerrarEdicion();
      // El toast dice CUÁNTAS piezas se movieron. Sin ese número, "guardado" no
      // permite saber si la corrección alcanzó a las piezas o solo al encabezado.
      const partes = ['Pedido corregido'];
      if (r.piezas_actualizadas) partes.push(`${r.piezas_actualizadas} pieza${r.piezas_actualizadas === 1 ? '' : 's'} al día`);
      if (r.envio_repartido_en) partes.push(`envío repartido en ${r.envio_repartido_en}`);
      toast(partes.join(' · '));
      await render();
      // Renumerar un pedido cambia su etiqueta en el filtro de Inventario, que está
      // cacheada desde que se cargó la pantalla.
      await Inventario.refreshMeta();
    } catch (e) {
      // Número repetido, envío negativo: el servidor explica qué corregir y el modal
      // se queda abierto con lo escrito.
      toast(e && e.message ? e.message : 'No se pudo guardar', 'error');
    }
  }

  // ---------------------------------------------------------------
  // Borrar
  // ---------------------------------------------------------------

  async function borrar(id) {
    const p = cache.find(x => x.id === id);
    if (!p) return;

    // La confirmación dice el NÚMERO DE PIEZAS que se van. "¿Borrar este pedido?" no
    // permite decidir; "se borrarán 15 piezas" sí — y esto no se puede deshacer.
    const msg = `¿Borrar el pedido #${p.order_number}?\n\n` +
      `Se borrarán también sus ${p.piezas} pieza${p.piezas === 1 ? '' : 's'} del inventario ` +
      `(${mx(p.costo_total)} invertidos).\n\nEsto no se puede deshacer.`;
    if (!confirm(msg)) return;

    try {
      const r = await Api.del(`purchase_orders.php?id=${id}`);
      toast(`Pedido #${r.order_number} borrado · ${r.piezas_borradas} pieza${r.piezas_borradas === 1 ? '' : 's'}`);
      await render();
      // Inventario cachea la lista de pedidos de su filtro al cargarse. Sin esto, el
      // pedido recién borrado seguiría ofreciéndose ahí — un fantasma que al
      // seleccionarlo no devuelve nada. Es el mismo síntoma que motivó esta pantalla.
      await Inventario.refreshMeta();
    } catch (e) {
      toast(e && e.message ? e.message : 'No se pudo borrar el pedido', 'error');
    }
  }

  return { render, verPiezas, abrirEdicion, cerrarEdicion, guardar, borrar };
})();
