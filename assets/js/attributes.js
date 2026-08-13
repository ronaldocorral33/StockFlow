/* Carga y renderiza los campos personalizados (attribute_definitions) del usuario. */
const Attributes = (() => {
  let defs = [];

  async function load() {
    const res = await Api.get('attributes.php');
    defs = res.attributes || [];
    return defs;
  }

  function all() { return defs; }
  function tableFields() { return defs.filter(d => d.show_in_table); }

  /** Regresa el HTML de un input/select para una definición, con un valor inicial opcional. */
  function renderInput(def, value, namePrefix) {
    const name = `${namePrefix}[${def.field_key}]`;
    const v = value == null ? '' : value;
    if (def.field_type === 'select') {
      const opts = (def.options || []).map(o =>
        `<option value="${esc(o)}" ${String(v) === String(o) ? 'selected' : ''}>${esc(o)}</option>`
      ).join('');
      return `<select name="${esc(name)}" data-field="${esc(def.field_key)}">
        <option value="">—</option>${opts}
      </select>`;
    }
    const type = def.field_type === 'number' ? 'number' : (def.field_type === 'date' ? 'date' : 'text');
    return `<input type="${type}" name="${esc(name)}" data-field="${esc(def.field_key)}" value="${esc(v)}">`;
  }

  /** Lee del DOM (dentro de container) los valores de cada attribute_definition -> objeto JSON. */
  function collect(container) {
    const out = {};
    defs.forEach(def => {
      const el = container.querySelector(`[data-field="${def.field_key}"]`);
      if (el && el.value !== '') out[def.field_key] = el.value;
    });
    return out;
  }

  return { load, all, tableFields, renderInput, collect };
})();
