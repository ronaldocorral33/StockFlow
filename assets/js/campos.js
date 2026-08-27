/* Pantalla "Campos y vistas": la configuración del registro de campos.
 *
 * Es la superficie donde el negocio decide QUÉ campos tiene y DÓNDE aparecen. Todo lo
 * que se cambia aquí se refleja de inmediato en Entradas, Inventario, Salidas,
 * Exportación y en el asistente, porque las cinco leen el mismo registro.
 *
 * La pantalla distingue visiblemente las tres clases de campo, porque las reglas son
 * distintas y el usuario necesita entender por qué:
 *   · CALCULADO     — lo calcula la base (Costo total, Ganancia). No se edita.
 *   · BASE          — el sistema lo usa para calcular. Se renombra y se oculta, no se borra.
 *   · PERSONALIZADO — del negocio. Se crea, se renombra, se archiva y se recupera.
 *
 * Los campos INTERNOS (id, business_id, llaves foráneas, fechas de auditoría) no
 * aparecen: no son vocabulario del negocio y nadie debe poder tocarlos por accidente.
 */
const Campos = (() => {
  let registro = [];
  let contextos = {};
  let tipos = [];
  let verArchivados = false;

  const CTX_ORDEN = ['entries', 'inventory', 'sales', 'export'];
  const CTX_LABEL = { entries: 'Entradas', inventory: 'Inventario', sales: 'Salidas', export: 'Exportar' };

  async function cargar() {
    const res = await Api.get('attributes.php?scope=registry' + (verArchivados ? '&archived=1' : ''));
    registro = res.fields || [];
    contextos = res.contexts || {};
    tipos = res.types || ['text', 'number', 'date', 'select', 'boolean'];
    render();
  }

  function clase(f) {
    if (f.field_type === 'computed') return { txt: 'Calculado', cls: 'calc' };
    if (f.is_canonical) return { txt: 'Base', cls: 'base' };
    return { txt: 'Personalizado', cls: 'cust' };
  }

  function render() {
    const activos = registro.filter(f => !f.archived);
    const archivados = registro.filter(f => f.archived);

    document.getElementById('campos-tabla').innerHTML = `
      <div class="tscroll"><table class="camposT">
        <thead><tr>
          <th style="width:26%">Campo</th>
          <th style="width:13%">Tipo</th>
          <th style="width:12%">Clase</th>
          ${CTX_ORDEN.map(c => `<th style="text-align:center">${CTX_LABEL[c]}</th>`).join('')}
          <th style="width:96px"></th>
        </tr></thead>
        <tbody>${activos.map((f, i) => fila(f, i, activos.length)).join('')}</tbody>
      </table></div>`;

    document.getElementById('campos-archivados').innerHTML = archivados.length
      ? `<h3 style="margin-top:22px">Campos archivados (${archivados.length})</h3>
         <p class="muted" style="font-size:.83rem">Sus valores siguen guardados en cada pieza. Al reactivarlos vuelven a verse tal cual.</p>
         <div class="tscroll"><table class="camposT"><tbody>
           ${archivados.map(f => `<tr>
             <td><b>${esc(f.label)}</b> <span class="muted">${esc(f.field_key)}</span></td>
             <td colspan="5" class="muted">Archivado</td>
             <td><button class="btn ghost sm" onclick="Campos.restaurar(${f.id})">Reactivar</button></td>
           </tr>`).join('')}
         </tbody></table></div>`
      : (verArchivados ? '<p class="muted" style="margin-top:18px">No hay campos archivados.</p>' : '');

    document.getElementById('tipo-nuevo').innerHTML = tipos.map(t =>
      `<option value="${t}">${etiquetaTipo(t)}</option>`).join('');
  }

  function etiquetaTipo(t) {
    return { text: 'Texto', number: 'Número', date: 'Fecha', select: 'Lista de opciones',
             boolean: 'Sí / No', computed: 'Calculado' }[t] || t;
  }

  function fila(f, i, total) {
    const c = clase(f);
    const bloqueado = f.field_type === 'computed';

    return `<tr class="${f.is_principal ? 'principal' : ''}">
      <td>
        <input class="lblinp" value="${esc(f.label)}" ${bloqueado ? 'disabled' : ''}
               onchange="Campos.renombrar(${f.id}, this.value)">
        <div class="muted" style="font-size:.72rem">${esc(f.field_key)}
          ${f.is_principal ? '<span class="cbadge prin">Principal</span>' : ''}
          ${f.fill_rate === 0 ? '<span class="cbadge warn">sin datos</span>' : ''}
        </div>
      </td>
      <td>
        ${f.is_canonical || bloqueado
          ? `<span class="muted">${etiquetaTipo(f.field_type)}</span>`
          : `<select onchange="Campos.cambiarTipo(${f.id}, this.value)">
               ${tipos.map(t => `<option value="${t}" ${f.field_type === t ? 'selected' : ''}>${etiquetaTipo(t)}</option>`).join('')}
             </select>`}
        ${f.field_type === 'select'
          ? `<div style="margin-top:4px"><input class="optinp" placeholder="S, M, L, XL"
               value="${esc((f.options || []).join(', '))}"
               onchange="Campos.cambiarOpciones(${f.id}, this.value)"></div>`
          : ''}
      </td>
      <td><span class="cbadge ${c.cls}">${c.txt}</span></td>
      ${CTX_ORDEN.map(ctx => {
        const col = (contextos[ctx] || {}).visible;
        return `<td style="text-align:center">
          <input type="checkbox" ${f[col] ? 'checked' : ''} ${bloqueado && ctx === 'entries' ? 'disabled' : ''}
                 onchange="Campos.visibilidad(${f.id}, '${ctx}', this.checked)">
        </td>`;
      }).join('')}
      <td>
        <button class="rowbtn" title="Subir" ${i === 0 ? 'disabled' : ''} onclick="Campos.mover(${f.id}, -1)">${icon('chevronUp', 15)}</button>
        <button class="rowbtn" title="Bajar" ${i === total - 1 ? 'disabled' : ''} onclick="Campos.mover(${f.id}, 1)">${icon('chevronDown', 15)}</button>
        ${!f.is_principal && !bloqueado
          ? `<button class="rowbtn" title="Usar como identificador principal" onclick="Campos.principal(${f.id})">${icon('sparkle', 15)}</button>` : ''}
        ${!f.is_canonical
          ? `<button class="rowbtn" title="Archivar" onclick="Campos.archivar(${f.id})">${icon('trash', 15)}</button>` : ''}
      </td>
    </tr>`;
  }

  // ---------------------------------------------------------------
  // Acciones
  // ---------------------------------------------------------------

  async function guardar(fn, exito) {
    try {
      await fn();
      if (exito) toast(exito);
      await cargar();
    } catch (e) {
      // El servidor explica la restricción (no ocultar el principal, no archivar un
      // canónico). Se muestra tal cual: el usuario necesita saber POR QUÉ.
      toast(e && e.message ? e.message : 'No se pudo guardar el cambio');
      await cargar();
    }
  }

  const renombrar = (id, label) =>
    guardar(() => Api.put(`attributes.php?id=${id}`, { label }), 'Etiqueta actualizada');

  const cambiarTipo = (id, field_type) =>
    guardar(() => Api.put(`attributes.php?id=${id}`, { field_type }), 'Tipo actualizado');

  const cambiarOpciones = (id, options) =>
    guardar(() => Api.put(`attributes.php?id=${id}`, { options }), 'Opciones actualizadas');

  const visibilidad = (id, context, visible) =>
    guardar(() => Api.post('attributes.php?action=visibility', { id, context, visible }));

  const principal = (id) =>
    guardar(() => Api.post('attributes.php?action=principal', { id }),
      'Ahora es el identificador principal del producto');

  const restaurar = (id) =>
    guardar(() => Api.post('attributes.php?action=restore', { id }), 'Campo reactivado');

  /**
   * Archivar avisa CUÁNTAS piezas tienen valor en ese campo.
   *
   * Una advertencia genérica ("se ocultará") no permite decidir; "23 piezas tienen
   * valor en Color" sí. Y como se archiva en vez de borrar, los valores vuelven
   * intactos si el usuario se arrepiente.
   */
  async function archivar(id) {
    const f = registro.find(x => x.id === id);
    let n = 0;
    try {
      const r = await Api.get(`attributes.php?action=value-count&id=${id}`);
      n = r.count || 0;
    } catch (e) { /* si falla el conteo, se pregunta igual sin el número */ }

    const msg = n
      ? `${n} pieza(s) tienen un valor en "${f.label}".\n\nAl archivar el campo dejará de verse, pero esos valores NO se borran: vuelven si lo reactivas.\n\n¿Archivar?`
      : `¿Archivar "${f.label}"? Dejará de verse en todas las pantallas y puedes reactivarlo cuando quieras.`;
    if (!confirm(msg)) return;

    guardar(() => Api.post('attributes.php?action=archive', { id }), 'Campo archivado');
  }

  /** Reordena dentro del contexto que el usuario está viendo. */
  async function mover(id, delta) {
    const ctx = document.getElementById('campos-ctx').value;
    const activos = registro.filter(f => !f.archived);
    const i = activos.findIndex(f => f.id === id);
    const j = i + delta;
    if (i < 0 || j < 0 || j >= activos.length) return;

    const ids = activos.map(f => f.id);
    [ids[i], ids[j]] = [ids[j], ids[i]];
    guardar(() => Api.post('attributes.php?action=reorder-context', { context: ctx, ids }));
  }

  async function crear() {
    const label = document.getElementById('label-nuevo').value.trim();
    if (!label) { toast('Escribe una etiqueta'); return; }
    const body = {
      label,
      field_type: document.getElementById('tipo-nuevo').value,
      options: document.getElementById('opciones-nuevo').value,
      is_required: document.getElementById('req-nuevo').checked,
      show_in_table: true,
    };
    try {
      await Api.post('attributes.php', body);
      toast(`Campo "${label}" creado`);
      document.getElementById('label-nuevo').value = '';
      document.getElementById('opciones-nuevo').value = '';
      await cargar();
    } catch (e) {
      toast(e && e.message ? e.message : 'No se pudo crear el campo');
    }
  }

  function toggleArchivados() {
    verArchivados = !verArchivados;
    document.getElementById('btn-archivados').textContent =
      verArchivados ? 'Ocultar archivados' : 'Ver archivados';
    cargar();
  }

  return {
    cargar, renombrar, cambiarTipo, cambiarOpciones, visibilidad,
    principal, archivar, restaurar, mover, crear, toggleArchivados,
  };
})();
