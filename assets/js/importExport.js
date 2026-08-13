/* Import/Export de Excel usando SheetJS del lado del cliente. */
const ImportExport = (() => {
  function importFile() {
    document.getElementById('fileinput').click();
  }

  function handleFile(e) {
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
        if (!confirm(`Se encontraron ${rows.length} filas. ¿Agregarlas a tu inventario?`)) return;

        const res = await Api.post('import.php', { rows });
        toast(`Importadas ${res.inserted} pieza(s)${res.skipped ? `, ${res.skipped} sin nombre omitida(s)` : ''}`);
        if (res.errors && res.errors.length) {
          console.warn('Errores de importación:', res.errors);
        }
        Inventario.render();
        Entradas.renderKpis();
      } catch (err) {
        console.error(err);
        toast('Error al leer el Excel');
      }
    };
    reader.readAsArrayBuffer(file);
    e.target.value = '';
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

  return { importFile, handleFile, exportFile };
})();
