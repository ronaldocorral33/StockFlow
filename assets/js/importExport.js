/* Import/Export de Excel usando SheetJS del lado del cliente.
 *
 * El flujo de importación tiene tres pasos a propósito: leer → ENSAYAR → confirmar.
 * El ensayo (dry run) pega al servidor con dry_run:true, que hace todo el trabajo
 * real de emparejamiento dentro de una transacción y la deshace. Así ves "738 se
 * actualizarán, 0 nuevas" ANTES de tocar nada, en vez de descubrir después que
 * duplicaste el inventario. */
const ImportExport = (() => {
  let pendientes = null;   // filas leídas del Excel, esperando confirmación
  let ultimoEnsayo = null; // resultado del último dry run

  function importFile() {
    document.getElementById('fileinput').click();
  }

  function handleFile(e) {
    const file = e.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = (ev) => {
      try {
        const wb = XLSX.read(ev.target.result, { type: 'array', cellDates: true });
        const sheetName = wb.SheetNames.find(n => /producto|invent|jersey|venta/i.test(n)) || wb.SheetNames[0];
        const ws = wb.Sheets[sheetName];
        const rows = XLSX.utils.sheet_to_json(ws, { raw: false, dateNF: 'yyyy-mm-dd' });
        if (!rows.length) { toast('El archivo no tiene filas'); return; }

        pendientes = rows;
        document.getElementById('import-resumen').textContent =
          `${file.name} — ${rows.length} fila(s) en la hoja "${sheetName}".`;
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

  function modoElegido() {
    const el = document.querySelector('input[name="import-mode"]:checked');
    return el ? el.value : 'sync';
  }

  /** Ensayo: pregunta al servidor qué pasaría, sin escribir nada. */
  async function preview() {
    if (!pendientes) return;
    const box = document.getElementById('import-preview');
    const btn = document.getElementById('import-go');
    btn.disabled = true;
    box.innerHTML = '<span class="muted" style="font-size:.82rem">Revisando contra tu inventario…</span>';

    try {
      const res = await Api.post('import.php', { rows: pendientes, mode: modoElegido(), dry_run: true });
      ultimoEnsayo = res;
      box.innerHTML = renderEnsayo(res);
      btn.disabled = false;
      btn.textContent = res.inserted && !res.updated
        ? `Agregar ${res.inserted}`
        : `Aplicar cambios`;
    } catch (err) {
      console.error(err);
      box.innerHTML = '<span class="muted" style="font-size:.82rem">No pude revisar el archivo. Intenta de nuevo.</span>';
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
      html += `<p class="muted" style="font-size:.78rem; margin-top:9px">Se reconocieron ${r.orders} pedido(s) en el archivo.</p>`;
    }
    // El aviso importante: en modo "agregar", insertar todo es exactamente lo que duplica.
    if (r.mode === 'add' && r.inserted > 0) {
      html += `<div class="impwarn"><span>⚠</span><span>Se agregarán las ${r.inserted} filas sin revisar si ya las tienes.
        Si este archivo incluye piezas que ya están en tu inventario, quedarán duplicadas.</span></div>`;
    }
    if (r.errors && r.errors.length) {
      html += `<div class="impwarn"><span>⚠</span><span>${r.errors.length} fila(s) con problemas; se omitirán.
        Primera: fila ${r.errors[0].row} — ${esc(r.errors[0].reason)}</span></div>`;
    }
    return html;
  }

  /** Confirmación: la misma operación, ahora sí escribiendo. */
  async function confirm() {
    if (!pendientes) return;
    const btn = document.getElementById('import-go');
    btn.disabled = true;
    btn.textContent = 'Importando…';

    try {
      const res = await Api.post('import.php', { rows: pendientes, mode: modoElegido(), dry_run: false });
      const partes = [];
      if (res.inserted) partes.push(`${res.inserted} nueva(s)`);
      if (res.updated) partes.push(`${res.updated} actualizada(s)`);
      if (res.unchanged) partes.push(`${res.unchanged} sin cambios`);
      if (res.skipped) partes.push(`${res.skipped} omitida(s)`);
      toast(partes.length ? partes.join(', ') : 'Nada que importar');
      if (res.errors && res.errors.length) console.warn('Errores de importación:', res.errors);

      close();
      Inventario.render();
      Entradas.renderKpis();
    } catch (err) {
      console.error(err);
      toast('Error al importar');
      btn.disabled = false;
      btn.textContent = 'Importar';
    }
  }

  function close() {
    document.getElementById('import-bg').classList.remove('show');
    document.getElementById('import-preview').innerHTML = '';
    document.getElementById('import-go').disabled = true;
    document.getElementById('import-go').textContent = 'Importar';
    pendientes = null;
    ultimoEnsayo = null;
  }

  async function exportFile() {
    const res = await Api.get('export.php');
    if (!res.rows.length) { toast('No hay productos para exportar'); return; }
    const ws = XLSX.utils.json_to_sheet(res.rows);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Inventario');
    XLSX.writeFile(wb, 'inventario_' + today() + '.xlsx');
    toast('Excel exportado');
  }

  return { importFile, handleFile, preview, confirm, close, exportFile };
})();
