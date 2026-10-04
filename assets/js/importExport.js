/* Import/Export de Excel usando SheetJS del lado del cliente.
 *
 * IMPORTACIÓN: leer → MAPEAR → ensayar → confirmar.
 *
 * El paso de mapeo es el que faltaba. Antes el importador adivinaba a qué campo iba
 * cada columna con una lista de alias y no lo decía; cuando fallaba —la columna
 * "Pedido" no estaba en esa lista y se perdía en cada importación— no había forma de
 * enterarse hasta ver el resultado. Ahora el sistema PROPONE, el usuario CONFIRMA, y
 * puede corregir o ignorar cualquier columna.
 *
 * El ensayo (dry run) se conserva: hace el emparejamiento real dentro de una
 * transacción y la deshace, así se ve "738 se actualizan, 0 nuevas" antes de escribir.
 */
const ImportExport = (() => {
  let pendientes = null;    // filas leídas del Excel
  let encabezados = [];     // columnas del archivo
  let mapeo = {};           // { encabezado: field_key | '__ignorar__' }
  let camposDestino = [];   // campos a los que se puede mapear

  function importFile() {
    document.getElementById('fileinput').click();
  }

  async function handleFile(e) {
    const file = e.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = async (ev) => {
      try {
        const wb = XLSX.read(ev.target.result, { type: 'array', cellDates: true });
        const sheetName = wb.SheetNames.find(n => /producto|invent|jersey|venta/i.test(n)) || wb.SheetNames[0];
        const ws = wb.Sheets[sheetName];
        const rows = XLSX.utils.sheet_to_json(ws, { raw: false, dateNF: 'yyyy-mm-dd' });
        if (!rows.length) { toast('El archivo no tiene filas'); return; }

        pendientes = rows;
        // Se recorren las primeras filas y no solo la primera: una hoja real puede
        // traer columnas que solo aparecen más abajo.
        const set = new Set();
        rows.slice(0, 50).forEach(r => Object.keys(r).forEach(k => set.add(k)));
        encabezados = [...set];

        document.getElementById('import-resumen').textContent =
          `${file.name} — ${rows.length} fila(s), ${encabezados.length} columna(s) en la hoja "${sheetName}".`;

        const res = await Api.post('import.php?action=analyze', { headers: encabezados });
        camposDestino = res.fields || [];
        mapeo = {};
        (res.mapping || []).forEach(m => { mapeo[m.header] = m.field_key || '__ignorar__'; });

        renderMapeo(res.mapping || []);
        document.getElementById('import-bg').classList.add('show');
        preview();
      } catch (err) {
        console.error(err);
        toast('Error al leer el Excel');
      }
    };
    reader.readAsArrayBuffer(file);
    e.target.value = '';
  }

  /** Tabla de mapeo: una fila por columna del archivo, con su destino propuesto. */
  function renderMapeo(propuesta) {
    const opciones = (sel) =>
      `<option value="__ignorar__" ${sel === '__ignorar__' ? 'selected' : ''}>— Ignorar esta columna —</option>` +
      `<option value="id" ${sel === 'id' ? 'selected' : ''}>ID (para sincronizar)</option>` +
      camposDestino.map(f =>
        `<option value="${esc(f.field_key)}" ${sel === f.field_key ? 'selected' : ''}>${esc(f.label)}</option>`
      ).join('');

    // Un ejemplo del propio archivo hace evidente si el mapeo es correcto: ver
    // "Real Madrid" junto a "Producto" confirma más que leer el nombre de la columna.
    const ejemplo = (h) => {
      const fila = pendientes.find(r => r[h] !== undefined && r[h] !== '');
      return fila ? String(fila[h]).slice(0, 24) : '';
    };

    document.getElementById('import-mapeo').innerHTML = `
      <div class="tscroll"><table class="camposT" style="min-width:520px">
        <thead><tr><th>Columna del archivo</th><th>Ejemplo</th><th>Se guardará en</th></tr></thead>
        <tbody>${propuesta.map(m => `
          <tr>
            <td><b>${esc(m.header)}</b><div class="muted" style="font-size:.7rem">${esc(m.reason)}</div></td>
            <td class="muted">${esc(ejemplo(m.header))}</td>
            <td><select onchange="ImportExport.setMapeo('${esc(m.header).replace(/'/g, "\\'")}', this.value)">
              ${opciones(m.field_key || '__ignorar__')}
            </select></td>
          </tr>`).join('')}
        </tbody>
      </table></div>`;
  }

  function setMapeo(header, fieldKey) {
    mapeo[header] = fieldKey;
    preview();
  }

  function modoElegido() {
    const el = document.querySelector('input[name="import-mode"]:checked');
    return el ? el.value : 'sync';
  }

  function esReemplazo() { return modoElegido() === 'replace'; }

  function textoBoton(r) {
    if (r.mode === 'replace') return 'Reemplazar e importar';
    return r.inserted && !r.updated ? `Agregar ${r.inserted}` : 'Aplicar cambios';
  }

  /** Ensayo: pregunta al servidor qué pasaría, sin escribir nada. */
  async function preview() {
    if (!pendientes) return;
    const box = document.getElementById('import-preview');
    const btn = document.getElementById('import-go');
    btn.disabled = true;
    box.innerHTML = '<span class="muted" style="font-size:.82rem">Revisando contra tu inventario…</span>';

    try {
      const res = await Api.post('import.php', {
        rows: pendientes, mode: modoElegido(), dry_run: true, mapping: mapeo,
      });
      box.innerHTML = renderEnsayo(res);
      btn.disabled = false;
      btn.textContent = textoBoton(res);
    } catch (err) {
      box.innerHTML = '<span class="muted" style="font-size:.82rem">No pude revisar el archivo. Revisa el mapeo.</span>';
    }
  }

  function renderEnsayo(r) {
    const stat = (n, etiqueta, clase) =>
      `<div class="impstat ${clase || ''}"><b>${n}</b><span>${etiqueta}</span></div>`;

    let html = '<div class="impgrid">' +
      stat(r.inserted, 'nuevas', r.inserted ? 'nuevo' : '') +
      stat(r.updated, 'se actualizan') +
      stat(r.unchanged, 'sin cambios') +
      (r.skipped ? stat(r.skipped, 'omitidas', 'warn') : '') +
      '</div>';

    if (r.orders) {
      html += `<p class="muted" style="font-size:.78rem; margin-top:9px">Se reconocieron ${r.orders} pedido(s).</p>`;
    }
    if (r.skipped) {
      html += `<div class="impwarn"><span>⚠</span><span>${r.skipped} fila(s) se omitirán por no tener
        el identificador del producto. Revisa que esa columna esté mapeada arriba.</span></div>`;
    }
    if (r.mode === 'add' && r.inserted > 0) {
      html += `<div class="impwarn"><span>⚠</span><span>Se agregarán las ${r.inserted} filas sin revisar si ya las tienes.
        Si el archivo incluye piezas que ya están en tu inventario, quedarán duplicadas.</span></div>`;
    }
    if (r.mode === 'replace') {
      html += `<div class="impwarn"><span>⚠</span><span><b>Se reemplazará tu inventario actual:</b> se eliminarán ${r.replaced_items || 0} pieza(s) y después se cargarán las ${r.inserted} fila(s) válidas de este Excel.</span></div>`;
    }
    if (r.errors && r.errors.length) {
      html += `<div class="impwarn"><span>⚠</span><span>${r.errors.length} fila(s) con problemas; se omitirán.
        Primera: fila ${r.errors[0].row} — ${esc(r.errors[0].reason)}</span></div>`;
    }
    return html;
  }

  async function confirm() {
    if (!pendientes) return;
    const btn = document.getElementById('import-go');
    if (esReemplazo() && !window.confirm(
      'Vas a borrar todas las piezas actuales para cargar este Excel. Esta acción no se puede deshacer. ¿Deseas continuar?'
    )) {
      return;
    }
    btn.disabled = true;
    btn.textContent = esReemplazo() ? 'Reemplazando…' : 'Importando…';

    try {
      const res = await Api.post('import.php', {
        rows: pendientes, mode: modoElegido(), dry_run: false, mapping: mapeo,
      });
      const partes = [];
      if (res.inserted) partes.push(`${res.inserted} nueva(s)`);
      if (res.updated) partes.push(`${res.updated} actualizada(s)`);
      if (res.unchanged) partes.push(`${res.unchanged} sin cambios`);
      if (res.skipped) partes.push(`${res.skipped} omitida(s)`);
      toast(res.mode === 'replace'
        ? `Inventario reemplazado: ${partes.length ? partes.join(', ') : 'sin filas válidas'}`
        : (partes.length ? partes.join(', ') : 'Nada que importar'));
      if (res.errors && res.errors.length) console.warn('Errores de importación:', res.errors);

      close();
      Inventario.render();
      Entradas.renderKpis();
    } catch (err) {
      btn.disabled = false;
      btn.textContent = esReemplazo() ? 'Reemplazar e importar' : 'Importar';
    }
  }

  function close() {
    document.getElementById('import-bg').classList.remove('show');
    document.getElementById('import-preview').innerHTML = '';
    document.getElementById('import-mapeo').innerHTML = '';
    document.getElementById('import-go').disabled = true;
    document.getElementById('import-go').textContent = 'Importar';
    pendientes = null;
    encabezados = [];
    mapeo = {};
  }

  // ---------------------------------------------------------------
  // Exportación con selección de columnas
  // ---------------------------------------------------------------

  /**
   * Abre el selector de exportación.
   *
   * Antes se exportaba siempre la misma lista de 14 columnas. Ahora las columnas salen
   * del registro y el usuario puede elegir cuáles quiere para ESTE archivo, sin
   * cambiar su configuración guardada.
   */
  async function openExport() {
    await Fields.load();
    const campos = Fields.all().filter(f => !f.archived);
    const preseleccion = new Set(Fields.columns('export').map(f => f.field_key));

    const grupo = (etiqueta, lista) => lista.length ? `
      <div class="colgroup"><div class="colgroup-t">${etiqueta}</div>
        ${lista.map(f => `<label class="colopt">
          <input type="checkbox" value="${esc(f.field_key)}" ${preseleccion.has(f.field_key) ? 'checked' : ''}>
          <span class="colopt-l">${esc(f.label)}${f.fill_rate === 0 ? '<span class="colhint warn">sin datos</span>' : ''}</span>
        </label>`).join('')}
      </div>` : '';

    document.getElementById('exp-campos').innerHTML =
      grupo('Campos base', campos.filter(f => f.storage === 'column')) +
      grupo('Campos personalizados', campos.filter(f => f.storage === 'json'));

    document.getElementById('exp-bg').classList.add('show');
  }

  function closeExport() { document.getElementById('exp-bg').classList.remove('show'); }

  async function doExport() {
    const seleccion = [...document.querySelectorAll('#exp-campos input:checked')].map(i => i.value);
    if (!seleccion.length) { toast('Elige al menos una columna'); return; }

    const scope = document.getElementById('exp-scope').value;
    const conId = document.getElementById('exp-id').checked;

    const qs = new URLSearchParams({ fields: seleccion.join(','), scope, id: conId ? '1' : '0' });
    const res = await Api.get('export.php?' + qs.toString());
    if (!res.rows.length) { toast('No hay piezas que exportar con esos criterios'); return; }

    const ws = XLSX.utils.json_to_sheet(res.rows);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Inventario');
    XLSX.writeFile(wb, 'inventario_' + today() + '.xlsx');
    toast(`${res.rows.length} fila(s) exportada(s)`);
    closeExport();
  }

  /** Exportación rápida con la configuración guardada, sin abrir el selector. */
  async function exportFile() { return openExport(); }

  return {
    importFile, handleFile, setMapeo, preview, confirm, close,
    exportFile, openExport, closeExport, doExport,
  };
})();
