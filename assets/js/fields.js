/* Registro de campos del negocio + selector de columnas.
 *
 * QUÉ RESUELVE
 * Antes, la tabla de Inventario dibujaba un arreglo literal de 9 columnas escrito en
 * JavaScript, y la de Salidas otro de 7. Eso hacía imposible que un negocio ocultara
 * una columna que no usa (Categoría estaba vacía en las 738 piezas y aun así ocupaba
 * espacio) o mostrara una que sí usa (Talla no aparecía en Salidas).
 *
 * Ahora las dos tablas se dibujan recorriendo el registro que vive en la base de
 * datos, y el usuario decide qué ver desde el selector.
 *
 * DÓNDE VIVE LA CONFIGURACIÓN
 * En attribute_definitions, por NEGOCIO: la comparten todos sus empleados. Es una
 * decisión deliberada — "qué columnas usa esta tienda" es configuración del negocio,
 * no una preferencia personal. Guardarla por usuario habría necesitado otra tabla sin
 * que nadie lo pidiera.
 */
const Fields = (() => {
  let registry = [];
  let loaded = false;

  /**
   * Cómo se dibuja cada campo canónico.
   *
   * Este mapa ES plomería de presentación, y está bien que lo sea: sus claves son
   * columnas del esquema, que son fijas, no vocabulario de ningún giro. Los campos
   * personalizados no necesitan entrada aquí — todos se dibujan igual, leyendo del
   * JSON de atributos.
   */
  const RENDER = {
    name: (r, opts) => `<b>${esc(r.name)}</b>` +
      // El subtítulo con la variante solo se dibuja si "Variante" NO es una columna
      // visible por su cuenta; si no, el dato saldría dos veces en la misma fila.
      (!opts.variantAsColumn && r.variant_label ? `<div class="muted">${esc(r.variant_label)}</div>` : ''),
    variant_label: (r) => esc(r.variant_label || '—'),
    category: (r) => esc(r.category || '—'),
    subcategory: (r) => esc(r.subcategory || '—'),
    supplier: (r) => esc(r.supplier_name || '—'),
    order_number: (r) => r.order_number ? '#' + esc(r.order_number) : '—',
    cost: (r) => ({ cls: 'money', html: mx(r.cost) }),
    shipping_cost: (r) => ({ cls: 'money', html: mx(r.shipping_cost) }),
    total_cost: (r) => ({ cls: 'money', html: mx(r.total_cost) }),
    sale_price: (r) => ({ cls: 'money', html: r.sale_price == null ? '—' : mx(r.sale_price) }),
    profit: (r) => ({
      cls: 'money ' + (r.profit > 0 ? 'pos' : (r.profit < 0 ? 'neg' : '')),
      html: r.profit == null ? '—' : mx(r.profit),
    }),
    purchase_date: (r) => esc(r.purchase_date || '—'),
    arrival_date: (r) => esc(r.arrival_date || '—'),
    sale_date: (r) => esc(r.sale_date || '—'),
  };

  /** Campos que el backend puede ordenar (InventoryItem::SORTABLE). El resto se
   *  dibuja sin encabezado clicable, igual que hoy los atributos personalizados. */
  const SORTABLE = new Set([
    'name', 'category', 'subcategory', 'cost', 'shipping_cost',
    'sale_price', 'total_cost', 'profit', 'purchase_date', 'arrival_date', 'sale_date',
  ]);

  async function load(force = false, includeArchived = false) {
    if (loaded && !force) return registry;
    const res = await Api.get('attributes.php?scope=registry' + (includeArchived ? '&archived=1' : ''));
    registry = res.fields || [];
    if (res.contexts) CONTEXTS = res.contexts;
    if (res.types) types = res.types;
    principal = res.principal || null;
    loaded = true;
    return registry;
  }

  /**
   * Qué columnas del registro gobiernan cada pantalla.
   *
   * Llega del servidor (AttributeDefinition::CONTEXTS) para que exista UNA sola
   * definición. Se conserva una copia local como respaldo si el registro aún no cargó,
   * pero el servidor manda: si mañana se agrega una pantalla, este archivo no cambia.
   */
  let CONTEXTS = {
    entries:   { visible: 'visible_in_entries', order: 'sort_order_entries' },
    inventory: { visible: 'show_in_table',      order: 'sort_order' },
    sales:     { visible: 'visible_in_sales',   order: 'sort_order_sales' },
    export:    { visible: 'visible_in_export',  order: 'sort_order_export' },
  };
  let principal = null;
  let types = ['text', 'number', 'date', 'select', 'boolean'];

  function cfgFor(table) {
    return CONTEXTS[table] || CONTEXTS.inventory;
  }

  /** El campo designado como identificador principal del producto, o null. */
  function principalField() { return principal; }
  function contexts() { return CONTEXTS; }
  function fieldTypes() { return types; }
  function byKey(key) { return registry.find(f => f.field_key === key) || null; }
  function byId(id) { return registry.find(f => f.id === id) || null; }

  function flagFor(table) {
    return cfgFor(table).visible;
  }

  /**
   * Columnas visibles de una tabla, en orden.
   *
   * El orden se resuelve POR GRUPO —canónicos y luego personalizados— porque cada
   * grupo tiene su propia numeración de sort_order. Es exactamente el orden que las
   * tablas tenían antes de leer el registro.
   */
  function columns(table) {
    const cfg = cfgFor(table);
    const visibles = registry.filter(f => f[cfg.visible] && !f.archived);
    const canon = visibles.filter(f => f.storage === 'column');
    const custom = visibles.filter(f => f.storage === 'json');
    // Cada tabla ordena por SU columna de orden: Salidas empieza por la fecha de
    // venta, Inventario por el producto.
    const porOrden = (a, b) => (a[cfg.order] - b[cfg.order]) || (a.id - b.id);
    return [...canon.sort(porOrden), ...custom.sort(porOrden)];
  }

  /** Todos los campos del registro, para el selector. */
  function all() { return registry; }

  /** Encabezado de una columna, con flecha de orden si es ordenable. */
  function headerCell(field, sortState, onSortFn) {
    const ordenable = field.storage === 'column' && SORTABLE.has(field.field_key);
    if (!ordenable) {
      return `<th>${esc(field.label)}</th>`;
    }
    const flecha = sortState && sortState.col === field.field_key
      ? `<span class="ar">${icon(sortState.dir > 0 ? 'chevronUp' : 'chevronDown', 12)}</span>`
      : '';
    return `<th onclick="${onSortFn}('${field.field_key}')">${esc(field.label)}${flecha}</th>`;
  }

  /** Celda de una fila para un campo. */
  function cell(field, row, opts = {}) {
    if (field.storage === 'json') {
      const v = (row.attributes || {})[field.field_key];
      return `<td>${esc(v ?? '—')}</td>`;
    }
    const fn = RENDER[field.field_key];
    if (!fn) {
      return '<td>—</td>';
    }
    const out = fn(row, opts);
    if (typeof out === 'string') {
      return `<td>${out}</td>`;
    }
    return `<td class="${out.cls}">${out.html}</td>`;
  }

  /** ¿"Variante" es una columna visible por su cuenta en esta tabla? */
  function variantIsColumn(table) {
    return columns(table).some(f => f.field_key === 'variant_label');
  }

  /**
   * Dibuja un control de captura para un campo, sea canónico o personalizado.
   *
   * Es lo que permite que Entradas deje de tener inputs escritos a mano: el tipo del
   * campo decide el control, y las opciones de una lista salen de su configuración.
   */
  function input(field, value, extraAttrs = '') {
    const v = value == null ? '' : String(value);
    const base = `data-fk="${field.field_key}" ${extraAttrs}`;
    switch (field.field_type) {
      case 'select': {
        const opts = (field.options || []).map(o =>
          `<option value="${esc(o)}" ${o === v ? 'selected' : ''}>${esc(o)}</option>`).join('');
        return `<select ${base}><option value="">—</option>${opts}</select>`;
      }
      case 'number':
        return `<input type="number" step="0.01" ${base} value="${esc(v)}">`;
      case 'date':
        return `<input type="date" ${base} value="${esc(v)}">`;
      case 'boolean':
        return `<select ${base}><option value="">—</option>
          <option value="1" ${v === '1' ? 'selected' : ''}>Sí</option>
          <option value="0" ${v === '0' ? 'selected' : ''}>No</option></select>`;
      default:
        return `<input type="text" ${base} value="${esc(v)}" ${field.filter_values && field.filter_values.length ? `list="dl-${field.field_key}"` : ''}>`;
    }
  }

  /** Listas de sugerencias con los valores ya usados: evita escribir variantes del mismo dato. */
  function datalists() {
    return registry
      .filter(f => f.field_type === 'text' && f.filter_values && f.filter_values.length)
      .map(f => `<datalist id="dl-${f.field_key}">` +
        f.filter_values.map(v => `<option value="${esc(v.valor)}">`).join('') + '</datalist>')
      .join('');
  }
  // ---------------------------------------------------------------
  // Selector de columnas
  // ---------------------------------------------------------------

  let pickerTable = null;
  let pickerOnChange = null;

  function openPicker(table, onChange) {
    pickerTable = table;
    pickerOnChange = onChange;
    renderPicker();
    document.getElementById('cols-bg').classList.add('show');
  }

  function closePicker() {
    document.getElementById('cols-bg').classList.remove('show');
    pickerTable = null;
  }

  function renderPicker() {
    const flag = flagFor(pickerTable);
    const TITULOS = { entries: 'Entradas', inventory: 'Inventario', sales: 'Salidas', export: 'Exportación' };
    const titulo = TITULOS[pickerTable] || 'Inventario';
    document.getElementById('cols-title').textContent = 'Columnas de ' + titulo;

    const grupo = (etiqueta, campos) => {
      if (!campos.length) return '';
      return `<div class="colgroup"><div class="colgroup-t">${etiqueta}</div>` +
        campos.map(f => {
          // El aviso de columna vacía es lo que convierte una lista de casillas en una
          // decisión informada: sin esto habría que activar la columna, mirar la tabla
          // y volver a desactivarla para descubrir que no tiene datos.
          const vacia = f.fill_rate !== null && f.fill_rate === 0;
          const parcial = f.fill_rate !== null && f.fill_rate > 0 && f.fill_rate < 0.5;
          let nota = '';
          if (vacia) nota = '<span class="colhint warn">sin datos</span>';
          else if (parcial) nota = `<span class="colhint">${Math.round(f.fill_rate * 100)}% con dato</span>`;

          return `<label class="colopt">
            <input type="checkbox" ${f[flag] ? 'checked' : ''}
                   onchange="Fields.toggle(${f.id}, this.checked)">
            <span class="colopt-l">${esc(f.label)}${nota}</span>
          </label>`;
        }).join('') + '</div>';
    };

    const canon = registry.filter(f => f.storage === 'column' && !f.archived);
    const custom = registry.filter(f => f.storage === 'json' && !f.archived);

    document.getElementById('cols-body').innerHTML =
      grupo('Campos base', canon) +
      grupo('Campos personalizados de tu negocio', custom) +
      (custom.length
        ? ''
        : `<p class="muted" style="font-size:.8rem">Aún no has creado campos propios. Puedes hacerlo en Configuración.</p>`);
  }

  /** Guarda el cambio de una casilla. Se persiste al instante: un botón "Guardar"
   *  solo agregaría un paso y la posibilidad de perder el cambio al cerrar. */
  async function toggle(id, visible) {
    const campo = registry.find(f => f.id === id);
    if (!campo) return;
    const flag = flagFor(pickerTable);
    campo[flag] = visible;   // optimista: la tabla se redibuja de inmediato

    try {
      await Api.post('attributes.php?action=visibility', { id, context: pickerTable, visible });
      if (pickerOnChange) pickerOnChange();
    } catch (e) {
      campo[flag] = !visible;   // se revierte si el servidor lo rechazó
      renderPicker();
      // El servidor explica la razón (por ejemplo, ocultar el campo principal): se
      // muestra tal cual en vez de un mensaje genérico.
      toast(e && e.message ? e.message : 'No se pudo guardar el cambio');
    }
  }

  return {
    load, all, columns, cell, headerCell, variantIsColumn,
    openPicker, closePicker, toggle,
    principalField, contexts, fieldTypes, byKey, byId, input, datalists,
  };
})();
