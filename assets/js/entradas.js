/* Tab "Entradas": captura de un pedido con VARIOS grupos de productos.
 *
 * EL MODELO
 *   Pedido           — proveedor, fechas, moneda, envío total, notas. Una vez.
 *     └ Grupo        — un producto y lo que comparten sus piezas. Tantos como haga falta.
 *         └ Unidad   — solo lo que cambia entre piezas del grupo (normalmente la talla).
 *
 * "Pedido #125: 15 Real Madrid, 15 Barcelona, 15 Manchester City" es UN pedido de 45
 * piezas, no tres pedidos. Antes había que guardar tres veces.
 *
 * LOS GRUPOS NO SE GUARDAN, Y ES DELIBERADO
 * Un grupo es una estructura de captura: existe para no escribir "Real Madrid" quince
 * veces. Al guardar, sus valores compartidos ya quedaron copiados en cada pieza —así
 * funciona el producto, una fila por unidad física— y el grupo deja de aportar nada
 * que no se pueda recuperar agrupando las piezas del pedido. Por eso no se creó una
 * tabla de grupos: sería una segunda fuente de verdad sobre las mismas piezas.
 *
 * Los campos y su orden salen del REGISTRO (contexto "entries"). Nada de jerseys aquí.
 */
const Entradas = (() => {
  let grupos = [];

  /**
   * Los ids se derivan del MÁXIMO existente en vez de un contador aislado.
   *
   * Un contador propio funciona mientras todo el estado nazca aquí, pero colisiona en
   * cuanto llega de otro lado (una prueba, un borrador restaurado). Derivarlo del
   * estado real hace que duplicar un grupo nunca produzca un id repetido, que es lo
   * que rompería la identificación de las tarjetas en el DOM.
   */
  function nextGid() {
    return grupos.reduce((m, g) => Math.max(m, g.gid), 0) + 1;
  }
  function nextRid() {
    return grupos.reduce((m, g) => g.unidades.reduce((n, u) => Math.max(n, u.rid), m), 0) + 1;
  }
  let listo = false;
  let pegandoEn = null;   // gid del grupo que está pegando desde Excel

  /** Campos de captura, del registro. Los calculados no se escriben. */
  function campos() {
    return Fields.columns('entries').filter(f => f.editable !== false && f.field_type !== 'computed');
  }
  function grupo(gid) { return grupos.find(g => g.gid === gid); }
  function compartidosDe(g) { return campos().filter(f => g.compartido[f.field_key]); }
  function porUnidadDe(g) { return campos().filter(f => !g.compartido[f.field_key]); }
  function totalUnidades() { return grupos.reduce((s, g) => s + g.unidades.length, 0); }

  async function init() {
    document.getElementById('p-fcompra').value = today();
    await Fields.load();
    if (!listo) {
      listo = true;
      if (!grupos.length) agregarGrupo();
    }
    render();
    renderKpis();
  }

  // ---------------------------------------------------------------
  // Grupos
  // ---------------------------------------------------------------

  /** Un grupo nuevo comparte por omisión el campo principal: es el que más se repite. */
  function nuevoGrupo() {
    const compartido = {};
    const p = Fields.principalField();
    if (p) compartido[p.field_key] = true;
    return { gid: nextGid(), compartido, valores: {}, unidades: [], colapsado: false };
  }

  function agregarGrupo() {
    leerTodo();
    const g = nuevoGrupo();
    g.unidades.push({ rid: nextRid(), valores: {} });
    grupos.push(g);
    render();
  }

  /**
   * Duplica un grupo COPIANDO EN PROFUNDIDAD.
   *
   * Si se copiaran las referencias, escribir en el grupo nuevo cambiaría el original
   * en silencio — el error clásico al clonar objetos anidados en JavaScript. Cada
   * objeto de valores y cada unidad se reconstruye.
   */
  function duplicarGrupo(gid) {
    leerTodo();
    const i = grupos.findIndex(g => g.gid === gid);
    if (i < 0) return;
    const orig = grupos[i];

    // nextRid() lee del estado, y las copias todavía no están ahí: se lleva el
    // contador a mano dentro del map para que cada copia reciba un id propio.
    let rid = nextRid();
    const copia = {
      gid: nextGid(),
      compartido: { ...orig.compartido },
      valores: { ...orig.valores },
      unidades: orig.unidades.map(u => ({ rid: rid++, valores: { ...u.valores } })),
      colapsado: false,
    };
    grupos.splice(i + 1, 0, copia);
    render();
    toast('Grupo duplicado — cambia el producto y listo');
  }

  function eliminarGrupo(gid) {
    const g = grupo(gid);
    if (!g) return;
    if (g.unidades.length > 1 && !confirm(`¿Quitar este grupo y sus ${g.unidades.length} unidades?`)) return;
    leerTodo();
    grupos = grupos.filter(x => x.gid !== gid);
    if (!grupos.length) agregarGrupo(); else render();
  }

  function toggleColapso(gid) {
    leerTodo();
    const g = grupo(gid);
    if (g) g.colapsado = !g.colapsado;
    render();
  }

  function toggleCompartido(gid, key) {
    leerTodo();
    const g = grupo(gid);
    if (!g) return;
    if (g.compartido[key]) {
      // Al dejar de compartir, el valor pasa a cada unidad como punto de partida.
      const v = g.valores[key];
      if (v) g.unidades.forEach(u => { if (!u.valores[key]) u.valores[key] = v; });
      delete g.compartido[key];
    } else {
      g.compartido[key] = true;
    }
    render();
  }

  // ---------------------------------------------------------------
  // Unidades dentro de un grupo
  // ---------------------------------------------------------------

  function generar(gid) {
    leerTodo();
    const g = grupo(gid);
    const n = parseInt(document.getElementById(`gc-${gid}`).value, 10) || 0;
    if (n < 1) { toast('Escribe una cantidad'); return; }
    if (n > 500) { toast('Máximo 500 piezas por grupo'); return; }

    // Solo se sustituyen las unidades si están vacías; si ya hay datos, se agregan.
    const hayDatos = g.unidades.some(u => Object.values(u.valores).some(v => v !== ''));
    if (!hayDatos) g.unidades = [];
    let r0 = nextRid();
    for (let i = 0; i < n; i++) g.unidades.push({ rid: r0++, valores: {} });
    render();
    toast(`${n} unidad(es) en este grupo`);
  }

  function agregarUnidad(gid, count = 1) {
    leerTodo();
    const g = grupo(gid);
    let r1 = nextRid();
    for (let i = 0; i < count; i++) g.unidades.push({ rid: r1++, valores: {} });
    render();
  }

  function quitarUnidad(gid, rid) {
    leerTodo();
    const g = grupo(gid);
    g.unidades = g.unidades.filter(u => u.rid !== rid);
    if (!g.unidades.length) g.unidades.push({ rid: nextRid(), valores: {} });
    render();
  }

  function duplicarUnidad(gid, rid, veces = 1) {
    leerTodo();
    const g = grupo(gid);
    const i = g.unidades.findIndex(u => u.rid === rid);
    if (i < 0) return;
    const copias = [];
    let r2 = nextRid();
    for (let k = 0; k < veces; k++) {
      copias.push({ rid: r2++, valores: { ...g.unidades[i].valores } });
    }
    g.unidades.splice(i + 1, 0, ...copias);
    render();
  }

  /** Copia el valor de la primera unidad del grupo a todas las suyas. */
  function rellenar(gid, key) {
    leerTodo();
    const g = grupo(gid);
    const v = g.unidades[0]?.valores[key] ?? '';
    g.unidades.forEach(u => { u.valores[key] = v; });
    render();
    toast(`"${v || '(vacío)'}" aplicado a ${g.unidades.length} unidad(es) de este grupo`);
  }

  /** Lee del DOM a la memoria. Se llama antes de cualquier cambio estructural. */
  function leerTodo() {
    document.querySelectorAll('[data-gid]').forEach(card => {
      const g = grupo(Number(card.dataset.gid));
      if (!g) return;
      card.querySelectorAll('[data-shared="1"]').forEach(el => { g.valores[el.dataset.fk] = el.value; });
      card.querySelectorAll('tr[data-rid]').forEach(tr => {
        const u = g.unidades.find(x => x.rid === Number(tr.dataset.rid));
        if (!u) return;
        tr.querySelectorAll('[data-fk]').forEach(el => { u.valores[el.dataset.fk] = el.value; });
      });
    });
  }

  // ---------------------------------------------------------------
  // Render
  // ---------------------------------------------------------------

  function render() {
    document.getElementById('grupos-cont').innerHTML =
      grupos.map((g, i) => renderGrupo(g, i)).join('') + Fields.datalists();
    document.getElementById('grupos-n').textContent = grupos.length;
    calc();
  }

  function renderGrupo(g, idx) {
    const shared = compartidosDe(g);
    const cols = porUnidadDe(g);
    const p = Fields.principalField();
    // El título del grupo es el valor de su campo principal: "Real Madrid" identifica
    // el grupo mucho mejor que "Grupo 2".
    const titulo = (p && g.valores[p.field_key]) ? g.valores[p.field_key] : `Grupo ${idx + 1}`;

    return `<div class="grupo ${g.colapsado ? 'col' : ''}" data-gid="${g.gid}">
      <div class="grupo-head">
        <button class="rowbtn" onclick="Entradas.toggleColapso(${g.gid})" title="${g.colapsado ? 'Expandir' : 'Contraer'}">
          ${icon(g.colapsado ? 'chevronDown' : 'chevronUp', 15)}
        </button>
        <div class="grupo-tit">${esc(titulo)}
          <span class="grupo-sub">${g.unidades.length} unidad(es)${subtotalGrupo(g)}</span>
        </div>
        <div class="grupo-act">
          <button class="btn ghost sm" onclick="Entradas.duplicarGrupo(${g.gid})" title="Duplicar este grupo con todos sus datos">
            ${icon('copy', 13)} Duplicar grupo
          </button>
          <button class="rowbtn" onclick="Entradas.eliminarGrupo(${g.gid})" title="Quitar grupo">${icon('trash', 15)}</button>
        </div>
      </div>

      ${g.colapsado ? '' : `
      <div class="grupo-body">
        <div class="colgroup-t">Datos iguales para todas las piezas de este grupo</div>
        <div class="shgrid">
          ${campos().map(f => {
            const on = !!g.compartido[f.field_key];
            return `<div class="shfield ${on ? 'on' : ''}">
              <label class="shchk">
                <input type="checkbox" ${on ? 'checked' : ''} onchange="Entradas.toggleCompartido(${g.gid}, '${f.field_key}')">
                <span>${esc(f.label)}</span>
              </label>
              <div class="shval">${on
                ? Fields.input(f, g.valores[f.field_key] ?? '', 'data-shared="1" oninput="Entradas.calc()" onchange="Entradas.calc()"')
                : '<span class="muted">cambia por pieza</span>'}</div>
            </div>`;
          }).join('')}
        </div>

        <div class="entgen" style="margin-top:14px">
          <label>Cantidad</label>
          <input id="gc-${g.gid}" type="number" min="1" max="500" value="${g.unidades.length || 15}" style="width:80px">
          <button class="btn primary sm" onclick="Entradas.generar(${g.gid})">${icon('plus', 13)} Generar unidades</button>
          <button class="btn ghost sm" onclick="Entradas.abrirPegar(${g.gid})">${icon('copy', 13)} Pegar desde Excel</button>
          <button class="btn ghost sm" onclick="Entradas.agregarUnidad(${g.gid})">${icon('plus', 13)} Una más</button>
        </div>

        ${cols.length ? `
        <div class="iw-scroll" style="margin-top:10px">
          <table class="itemtable">
            <thead><tr>
              <th style="width:34px">#</th>
              ${cols.map(f => `<th>${esc(f.label)}
                <span class="colact" title="Copiar el valor de la primera fila a todas las de este grupo"
                      onclick="Entradas.rellenar(${g.gid}, '${f.field_key}')">${icon('chevronDown', 11)}</span>
              </th>`).join('')}
              <th style="width:64px"></th>
            </tr></thead>
            <tbody>
              ${g.unidades.map((u, i) => `<tr data-rid="${u.rid}">
                <td class="muted" style="text-align:center">${i + 1}</td>
                ${cols.map(f => `<td>${Fields.input(f, u.valores[f.field_key] ?? '', 'oninput="Entradas.calc()"')}</td>`).join('')}
                <td>
                  <button class="rowbtn" title="Duplicar" onclick="Entradas.duplicarUnidad(${g.gid}, ${u.rid})">${icon('plus', 14)}</button>
                  <button class="rowbtn" title="Quitar" onclick="Entradas.quitarUnidad(${g.gid}, ${u.rid})">${icon('trash', 14)}</button>
                </td>
              </tr>`).join('')}
            </tbody>
          </table>
        </div>`
        : `<p class="muted" style="font-size:.82rem;margin-top:10px">
             Todos los campos están marcados como compartidos: las ${g.unidades.length} unidades serán idénticas.
           </p>`}
      </div>`}
    </div>`;
  }

  /** Subtotal del grupo, para el encabezado. */
  function subtotalGrupo(g) {
    const factor = currency() === 'USD' ? (parseFloat(document.getElementById('p-tc').value) || 1) : 1;
    const costoCompartido = g.compartido['cost'] ? (parseFloat(g.valores['cost']) || 0) * factor : null;
    let suma = 0;
    g.unidades.forEach(u => {
      suma += costoCompartido !== null ? costoCompartido : (parseFloat(u.valores['cost']) || 0) * factor;
    });
    return suma ? ` · ${mx(suma)} de mercancía` : '';
  }

  // ---------------------------------------------------------------
  // Pegado tabular
  // ---------------------------------------------------------------

  function abrirPegar(gid) {
    leerTodo();
    pegandoEn = gid;
    const cols = porUnidadDe(grupo(gid)).map(f => f.label).join('\t');
    document.getElementById('paste-hint').textContent = cols
      ? `Columnas esperadas, en orden: ${cols}`
      : 'Este grupo no tiene campos por unidad: todo está marcado como compartido.';
    document.getElementById('paste-area').value = '';
    document.getElementById('paste-bg').classList.add('show');
    setTimeout(() => document.getElementById('paste-area').focus(), 50);
  }
  function cerrarPegar() { document.getElementById('paste-bg').classList.remove('show'); pegandoEn = null; }
  function aplicarPegado() { procesarPegado(document.getElementById('paste-area').value, pegandoEn); }

  /**
   * Convierte texto separado por tabuladores en unidades DEL GRUPO indicado.
   *
   * Si la primera fila coincide con etiquetas de campos se toma como encabezado y cada
   * columna va a su campo; si no, se asignan en el orden de la tabla. Soporta varias
   * columnas: pegar "Talla<TAB>Precio" llena las dos.
   */
  function procesarPegado(texto, gid) {
    const g = grupo(gid);
    if (!g) return;

    const lineas = texto.replace(/\r\n?/g, '\n').split('\n').filter(l => l.trim() !== '');
    if (!lineas.length) { toast('No encontré filas que pegar'); return; }

    const filas = lineas.map(l => l.split('\t').map(c => c.trim()));
    const cols = porUnidadDe(g);

    const primera = filas[0].map(c => c.toLowerCase());
    const mapa = primera.map(h =>
      cols.find(f => f.label.toLowerCase() === h || f.field_key.toLowerCase() === h) || null);
    const hayEncabezado = mapa.filter(Boolean).length >= Math.max(1, Math.ceil(primera.length / 2));

    const cuerpo = hayEncabezado ? filas.slice(1) : filas;
    const destino = hayEncabezado ? mapa : cols;
    if (!cuerpo.length) { toast('No encontré filas que pegar'); return; }

    leerTodo();
    const hayDatos = g.unidades.some(u => Object.values(u.valores).some(v => v !== ''));
    if (!hayDatos) g.unidades = [];

    let rPeg = nextRid();
    cuerpo.forEach(celdas => {
      const valores = {};
      celdas.forEach((valor, k) => {
        const campo = destino[k];
        if (campo) valores[campo.field_key] = valor;
      });
      g.unidades.push({ rid: rPeg++, valores });
    });

    render();
    toast(`${cuerpo.length} fila(s) pegada(s)${hayEncabezado ? ' con encabezados reconocidos' : ''}`);
    cerrarPegar();
  }

  // ---------------------------------------------------------------
  // Cálculo del pedido completo
  // ---------------------------------------------------------------

  function currency() { return document.getElementById('p-moneda').value; }

  function calc() {
    leerTodo();
    const factor = currency() === 'USD' ? (parseFloat(document.getElementById('p-tc').value) || 1) : 1;
    const envioTotal = (parseFloat(document.getElementById('p-envio').value) || 0) * factor;
    const n = totalUnidades();
    // El envío es del PEDIDO: se reparte entre TODAS las unidades de TODOS los grupos.
    const envioCU = n > 0 ? envioTotal / n : 0;

    let mercancia = 0, venta = 0;
    grupos.forEach(g => {
      const costoC = g.compartido['cost'] ? (parseFloat(g.valores['cost']) || 0) * factor : null;
      const ventaC = g.compartido['sale_price'] ? (parseFloat(g.valores['sale_price']) || 0) * factor : null;
      g.unidades.forEach(u => {
        mercancia += costoC !== null ? costoC : (parseFloat(u.valores['cost']) || 0) * factor;
        venta += ventaC !== null ? ventaC : (parseFloat(u.valores['sale_price']) || 0) * factor;
      });
    });

    document.getElementById('envio-reparto').textContent = n
      ? `Envío del pedido repartido entre ${n} pieza${n === 1 ? '' : 's'} de ${grupos.length} grupo(s): ${mx(envioCU)} c/u`
      : '';

    const total = mercancia + envioTotal;
    document.getElementById('entrada-summary').innerHTML = `
      <div class="row"><span>Grupos</span><span class="v">${grupos.length}</span></div>
      <div class="row"><span>Piezas totales</span><span class="v">${n}</span></div>
      <div class="row"><span>Mercancía</span><span class="v">${mx(mercancia)}</span></div>
      <div class="row"><span>Envío del pedido</span><span class="v">${mx(envioTotal)}</span></div>
      <div class="row total"><span>Costo total del pedido</span><span class="v">${mx(total)}</span></div>
      ${venta ? `<div class="row"><span>Ganancia potencial si vendes todo al precio capturado</span><span class="v">${mx(venta - total)}</span></div>` : ''}
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
  // Aplanado, vista previa y guardado
  // ---------------------------------------------------------------

  /**
   * Aplana TODOS los grupos en la lista plana que espera el servidor.
   *
   * Aquí es donde los grupos dejan de existir: cada unidad combina los compartidos de
   * SU grupo con los suyos propios y se convierte en una pieza independiente. Los
   * valores de la unidad ganan sobre los del grupo.
   */
  function construirItems() {
    leerTodo();
    const defs = campos();
    const p = Fields.principalField();
    const claveP = p ? p.field_key : 'name';

    const items = [];
    grupos.forEach((g, gi) => {
      g.unidades.forEach(u => {
        const plano = {};
        const attrs = {};
        defs.forEach(f => {
          const k = f.field_key;
          const valor = g.compartido[k] ? (g.valores[k] ?? '') : (u.valores[k] ?? '');
          if (valor === '' || valor == null) return;
          if (f.storage === 'json') attrs[k] = valor; else plano[k] = valor;
        });

        // El servidor identifica el producto por la columna name. Si el negocio designó
        // otro campo como principal y vive en el JSON, se copia también a name para que
        // inventario, buscador y asistente sigan funcionando. Se DUPLICA, no se mueve.
        if (!plano.name) {
          plano.name = p && p.storage === 'json' ? (attrs[claveP] ?? '') : (plano[claveP] ?? '');
        }
        plano.attributes = attrs;
        plano.__grupo = gi + 1;   // solo para reportar errores; el servidor lo ignora
        items.push(plano);
      });
    });
    return items;
  }

  function problemas(items) {
    const obligatorios = campos().filter(f => f.is_required);
    const out = [];
    items.forEach((it, i) => {
      const donde = `Grupo ${it.__grupo}, unidad ${i + 1}`;
      obligatorios.forEach(f => {
        const v = f.storage === 'json' ? it.attributes[f.field_key] : it[f.field_key];
        if (v === undefined || v === '') out.push(`${donde}: falta "${f.label}"`);
      });
      if (!it.name) out.push(`${donde}: falta el identificador del producto`);
    });
    return out;
  }

  function previsualizar() {
    const items = construirItems();
    const errs = problemas(items);
    const cols = campos();

    document.getElementById('prev-count').textContent = items.length;

    // Desglose por grupo: es lo que permite verificar de un vistazo que cada producto
    // llevó su cantidad correcta antes de guardar 45 piezas.
    const porGrupo = {};
    items.forEach(it => {
      const k = it.__grupo;
      porGrupo[k] = porGrupo[k] || { n: 0, nombre: it.name };
      porGrupo[k].n++;
    });
    const factor = currency() === 'USD' ? (parseFloat(document.getElementById('p-tc').value) || 1) : 1;
    const envioTotal = (parseFloat(document.getElementById('p-envio').value) || 0) * factor;
    const envioCU = items.length ? envioTotal / items.length : 0;
    const mercancia = items.reduce((s, it) => s + (parseFloat(it.cost) || 0) * factor, 0);

    document.getElementById('prev-resumen').innerHTML = `
      <div class="impgrid">
        <div class="impstat"><b>${Object.keys(porGrupo).length}</b><span>grupos</span></div>
        <div class="impstat nuevo"><b>${items.length}</b><span>piezas</span></div>
        <div class="impstat"><b>${mx(mercancia)}</b><span>mercancía</span></div>
        <div class="impstat"><b>${mx(envioTotal)}</b><span>envío</span></div>
      </div>
      <table style="font-size:.8rem;margin-top:12px">
        <thead><tr><th>Grupo</th><th>Producto</th><th style="text-align:right">Piezas</th><th style="text-align:right">Envío asignado</th></tr></thead>
        <tbody>
          ${Object.entries(porGrupo).map(([k, v]) => `<tr>
            <td>${esc(k)}</td><td><b>${esc(v.nombre)}</b></td>
            <td style="text-align:right">${v.n}</td>
            <td style="text-align:right">${mx(envioCU * v.n)}</td>
          </tr>`).join('')}
          <tr><td colspan="2"><b>Total del pedido</b></td>
              <td style="text-align:right"><b>${items.length}</b></td>
              <td style="text-align:right"><b>${mx(envioTotal)}</b></td></tr>
        </tbody>
      </table>`;

    document.getElementById('prev-problemas').innerHTML = errs.length
      ? `<div class="impwarn"><span>⚠</span><span>${errs.slice(0, 8).map(esc).join('<br>')}
         ${errs.length > 8 ? `<br>…y ${errs.length - 8} más` : ''}</span></div>`
      : '';

    document.getElementById('prev-tabla').innerHTML = `
      <div class="tscroll"><table style="font-size:.76rem">
        <thead><tr><th>#</th><th>Grupo</th>${cols.map(f => `<th>${esc(f.label)}</th>`).join('')}</tr></thead>
        <tbody>${items.slice(0, 30).map((it, i) => `<tr>
          <td class="muted">${i + 1}</td><td class="muted">${it.__grupo}</td>
          ${cols.map(f => {
            const v = f.storage === 'json' ? it.attributes[f.field_key] : it[f.field_key];
            return `<td>${esc(v ?? '—')}</td>`;
          }).join('')}
        </tr>`).join('')}</tbody>
      </table></div>
      ${items.length > 30 ? `<p class="muted" style="font-size:.76rem;margin-top:6px">Se muestran las primeras 30 de ${items.length}.</p>` : ''}`;

    document.getElementById('prev-bg').classList.add('show');
  }

  function cerrarPrev() { document.getElementById('prev-bg').classList.remove('show'); }

  async function guardar() {
    const items = construirItems().filter(it => it.name);
    if (!items.length) {
      toast('Captura al menos una unidad con su identificador');
      return;
    }
    const errs = problemas(items);
    if (errs.length) { toast(errs[0]); return; }

    const header = {
      order_number: document.getElementById('p-num').value || null,
      supplier: document.getElementById('p-prov').value,
      purchase_date: document.getElementById('p-fcompra').value,
      arrival_date: document.getElementById('p-fllegada').value,
      currency: currency(),
      exchange_rate: document.getElementById('p-tc').value,
      shipping_total: document.getElementById('p-envio').value,
      notes: document.getElementById('p-notas').value,
    };

    // Se manda TODO en una sola operación: el servidor crea UN pedido con todas las
    // piezas dentro de una transacción. Enviar grupo por grupo crearía varios pedidos.
    const limpios = items.map(({ __grupo, ...resto }) => resto);

    try {
      const r = await Api.post('purchase_orders.php', { header, items: limpios });
      toast(`Pedido guardado: ${limpios.length} pieza(s) en ${grupos.length} grupo(s)`);
      cerrarPrev();
      reset(true);
      renderKpis();
    } catch (e) { /* Api.post ya mostró el error */ }
  }

  function reset(silencioso = false) {
    if (!silencioso && totalUnidades() && !confirm('¿Limpiar la captura actual?')) return;
    grupos = [];
    ['p-num', 'p-prov', 'p-envio', 'p-fllegada', 'p-notas'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.value = '';
    });
    agregarGrupo();
  }

  return {
    init, render, calc, renderKpis,
    agregarGrupo, duplicarGrupo, eliminarGrupo, toggleColapso, toggleCompartido,
    generar, agregarUnidad, quitarUnidad, duplicarUnidad, rellenar,
    abrirPegar, cerrarPegar, aplicarPegado, procesarPegado,
    previsualizar, cerrarPrev, guardar, reset,
    // Expuestos para la prueba de lógica en Node.
    __test: {
      estado: () => grupos,
      setEstado: (g) => { grupos = g; },
      construirItems,
    },
  };
})();
