/* Pruebas de la lógica de GRUPOS que vive en el navegador.
 *
 * POR QUÉ EN NODE Y NO EN LA SUITE DE PHP
 * Dos de las reglas más importantes de esta función son propiedades de JavaScript, no
 * de la base de datos:
 *
 *   · Duplicar un grupo debe COPIAR EN PROFUNDIDAD. Si se copiaran las referencias,
 *     escribir en el grupo nuevo cambiaría el original en silencio — el error clásico
 *     al clonar objetos anidados.
 *   · Cambiar la cantidad de un grupo no debe tocar a los demás.
 *
 * Reproducirlas en PHP probaría una traducción, no el código que corre. Se cargan las
 * funciones reales de entradas.js con los mínimos globales que necesitan.
 *
 * Uso:  node tests\js\grupos.test.js
 */

const fs = require('fs');
const path = require('path');

// --- Mínimos globales que entradas.js espera del navegador ---
const registro = [
  { field_key: 'name', label: 'Producto', storage: 'column', field_type: 'text', editable: true },
  { field_key: 'cost', label: 'Costo', storage: 'column', field_type: 'number', editable: true },
  { field_key: 'talla', label: 'Talla', storage: 'json', field_type: 'select', options: ['S', 'M', 'L'], editable: true },
  { field_key: 'temporada', label: 'Temporada', storage: 'json', field_type: 'text', editable: true },
];

global.esc = (s) => String(s ?? '');
global.mx = (n) => '$' + Number(n || 0).toFixed(2);
global.icon = () => '';
global.today = () => '2026-08-22';
global.toast = () => {};
global.confirm = () => true;
global.animateKpis = () => {};
global.Api = { get: async () => ({ items: [] }), post: async () => ({}) };
global.Fields = {
  load: async () => registro,
  columns: () => registro,
  all: () => registro,
  principalField: () => registro[0],
  datalists: () => '',
  input: (f, v) => '<input data-fk="' + f.field_key + '" value="' + (v ?? '') + '">',
};

// El DOM mínimo: solo lo que el módulo toca al renderizar y calcular.
const nodoFalso = { value: '', textContent: '', innerHTML: '', classList: { add() {}, remove() {} }, focus() {} };
global.document = {
  getElementById: () => Object.assign({}, nodoFalso),
  querySelectorAll: () => [],
};

const src = fs.readFileSync(path.join(__dirname, '../../assets/js/entradas.js'), 'utf8');
const Entradas = new Function(src + '; return Entradas;')();

// --- Runner mínimo ---
let pasaron = 0, fallaron = 0;
function test(nombre, fn) {
  try { fn(); pasaron++; console.log('  ok   ' + nombre); }
  catch (e) { fallaron++; console.log('  FAIL ' + nombre + '\n       ' + e.message); }
}
function assertSame(esperado, real, msg) {
  const a = JSON.stringify(esperado), b = JSON.stringify(real);
  if (a !== b) throw new Error((msg || 'valores distintos') + ' — esperado: ' + a + ', obtenido: ' + b);
}
function assertTrue(cond, msg) { if (cond !== true) throw new Error(msg || 'se esperaba true'); }

/** Estado de grupos armado a mano, como quedaría tras capturar. */
function estadoDosGrupos() {
  return [
    { gid: 1, compartido: { name: true, cost: true, temporada: true }, colapsado: false,
      valores: { name: 'Real Madrid', cost: '500', temporada: '2026/27' },
      unidades: [{ rid: 1, valores: { talla: 'S' } }, { rid: 2, valores: { talla: 'M' } }] },
    { gid: 2, compartido: { name: true, cost: true, temporada: true }, colapsado: false,
      valores: { name: 'Barcelona', cost: '650', temporada: '2026/27' },
      unidades: [{ rid: 3, valores: { talla: 'L' } }] },
  ];
}

console.log('\ngrupos.test.js');

// ---------------------------------------------------------------
// Aplanado: donde los grupos dejan de existir
// ---------------------------------------------------------------

test('aplana los grupos en una lista plana de unidades', () => {
  Entradas.__test.setEstado(estadoDosGrupos());
  const items = Entradas.__test.construirItems();

  assertSame(3, items.length, 'dos unidades del grupo 1 y una del grupo 2');
  assertSame('Real Madrid', items[0].name);
  assertSame('S', items[0].attributes.talla);
  assertSame('Barcelona', items[2].name);
  assertSame('L', items[2].attributes.talla);
});

test('cada unidad hereda los compartidos de SU grupo, no del otro', () => {
  Entradas.__test.setEstado(estadoDosGrupos());
  const items = Entradas.__test.construirItems();

  assertSame('500', items[0].cost, 'el grupo 1 tiene su costo');
  assertSame('500', items[1].cost);
  assertSame('650', items[2].cost, 'el grupo 2 tiene el suyo');
});

test('el valor de la unidad GANA sobre el compartido del grupo', () => {
  const estado = estadoDosGrupos();
  estado[0].compartido = { name: true, temporada: true };   // el costo deja de compartirse
  estado[0].unidades[0].valores.cost = '999';
  Entradas.__test.setEstado(estado);

  assertSame('999', Entradas.__test.construirItems()[0].cost, 'la unidad capturó su propio costo');
});

test('los personalizados van al JSON y los canónicos a columnas', () => {
  Entradas.__test.setEstado(estadoDosGrupos());
  const it = Entradas.__test.construirItems()[0];
  assertTrue(it.name !== undefined, 'name es columna');
  assertTrue(it.cost !== undefined, 'cost es columna');
  assertTrue(it.attributes.talla !== undefined, 'talla va al JSON');
  assertTrue(it.talla === undefined, 'y NO como columna');
});

// ---------------------------------------------------------------
// La regla que más fácil se rompe: copia en profundidad
// ---------------------------------------------------------------

test('CLAVE: duplicar un grupo NO comparte referencias con el original', () => {
  Entradas.__test.setEstado(estadoDosGrupos());
  Entradas.duplicarGrupo(1);

  const g = Entradas.__test.estado();
  assertSame(3, g.length, 'la copia queda junto al original');

  const original = g[0], copia = g[1];
  assertTrue(original.gid !== copia.gid, 'ids distintos');

  // Se modifica la COPIA; el original no debe moverse.
  copia.valores.name = 'Manchester City';
  copia.unidades[0].valores.talla = 'L';
  copia.compartido.cost = false;

  assertSame('Real Madrid', original.valores.name, 'el nombre original no debió cambiar');
  assertSame('S', original.unidades[0].valores.talla, 'ni la talla de su primera unidad');
  assertSame(true, original.compartido.cost, 'ni qué campos comparte');
});

test('CLAVE: las unidades duplicadas tienen rid propio', () => {
  Entradas.__test.setEstado(estadoDosGrupos());
  Entradas.duplicarGrupo(1);
  const g = Entradas.__test.estado();
  const rids = [];
  g.forEach(x => x.unidades.forEach(u => rids.push(u.rid)));
  assertSame(rids.length, new Set(rids).size, 'ningún rid repetido en todo el pedido');
});

test('la copia arranca con los mismos valores que el original', () => {
  Entradas.__test.setEstado(estadoDosGrupos());
  Entradas.duplicarGrupo(1);
  const g = Entradas.__test.estado();
  assertSame(g[0].valores, g[1].valores, 'mismos valores compartidos');
  assertSame(g[0].unidades.length, g[1].unidades.length, 'misma cantidad de unidades');
  assertSame(
    g[0].unidades.map(u => u.valores),
    g[1].unidades.map(u => u.valores),
    'y los mismos valores por unidad'
  );
});

// ---------------------------------------------------------------
// Independencia entre grupos
// ---------------------------------------------------------------

test('CLAVE: agregar unidades a un grupo no afecta a los demás', () => {
  Entradas.__test.setEstado(estadoDosGrupos());
  Entradas.agregarUnidad(1, 5);

  const g = Entradas.__test.estado();
  assertSame(7, g[0].unidades.length, 'el grupo 1 creció');
  assertSame(1, g[1].unidades.length, 'el grupo 2 quedó igual');
});

test('quitar una unidad de un grupo no toca al otro', () => {
  Entradas.__test.setEstado(estadoDosGrupos());
  Entradas.quitarUnidad(1, 1);
  const g = Entradas.__test.estado();
  assertSame(1, g[0].unidades.length);
  assertSame(1, g[1].unidades.length, 'el grupo 2 intacto');
});

test('eliminar un grupo no altera los valores de los demás', () => {
  const estado = estadoDosGrupos();
  estado.push({ gid: 3, compartido: { name: true }, valores: { name: 'City' }, colapsado: false,
                unidades: [{ rid: 9, valores: { talla: 'M' } }] });
  Entradas.__test.setEstado(estado);

  Entradas.eliminarGrupo(2);
  const g = Entradas.__test.estado();
  assertSame(2, g.length);
  assertSame('Real Madrid', g[0].valores.name);
  assertSame('City', g[1].valores.name);
});

test('duplicar un grupo no cambia la cantidad de los otros', () => {
  Entradas.__test.setEstado(estadoDosGrupos());
  Entradas.duplicarGrupo(1);
  const g = Entradas.__test.estado();
  assertSame(1, g[2].unidades.length, 'el grupo 2 sigue con su única unidad');
  assertSame('Barcelona', g[2].valores.name);
});

// ---------------------------------------------------------------
// Pegado tabular dentro de UN grupo
// ---------------------------------------------------------------

test('pegar AGREGA cuando el grupo ya tiene unidades con datos', () => {
  // No se descarta trabajo ya capturado: lo existente se conserva y lo pegado se suma.
  Entradas.__test.setEstado(estadoDosGrupos());
  Entradas.procesarPegado('S\nM\nL\nXL', 2);

  const g = Entradas.__test.estado();
  assertSame(2, g[0].unidades.length, 'el grupo 1 no cambió');
  assertSame(5, g[1].unidades.length, 'la unidad que ya existía más las 4 pegadas');
  assertSame('XL', g[1].unidades[4].valores.talla);
});

test('pegar REEMPLAZA cuando las unidades del grupo están vacías', () => {
  // El caso normal: se generan 15 filas en blanco y se pegan 15 tallas. Si se
  // agregaran, quedarían 30 unidades, la mitad vacías.
  const estado = estadoDosGrupos();
  estado[1].unidades = [{ rid: 90, valores: {} }, { rid: 91, valores: {} }];
  Entradas.__test.setEstado(estado);

  Entradas.procesarPegado('S\nM\nL', 2);
  const u = Entradas.__test.estado()[1].unidades;
  assertSame(3, u.length, 'las filas en blanco se sustituyen, no se acumulan');
  assertSame('S', u[0].valores.talla);
});

test('pegar varias columnas con encabezado las reparte a sus campos', () => {
  const estado = estadoDosGrupos();
  estado[0].compartido = { name: true, temporada: true };   // costo y talla por unidad
  estado[0].unidades = [{ rid: 80, valores: {} }];          // vacías, para que reemplace
  Entradas.__test.setEstado(estado);

  Entradas.procesarPegado('Talla\tCosto\nS\t100\nM\t200', 1);

  const u = Entradas.__test.estado()[0].unidades;
  assertSame(2, u.length, 'el encabezado no cuenta como unidad');
  assertSame('S', u[0].valores.talla);
  assertSame('100', u[0].valores.cost);
  assertSame('200', u[1].valores.cost);
});

test('pegar en un grupo no toca las unidades del otro', () => {
  const estado = estadoDosGrupos();
  estado[0].unidades = [{ rid: 70, valores: {} }];
  Entradas.__test.setEstado(estado);

  Entradas.procesarPegado('S\nM\nL', 1);
  const g = Entradas.__test.estado();
  assertSame(3, g[0].unidades.length);
  assertSame(1, g[1].unidades.length, 'el grupo 2 conserva su única unidad');
  assertSame('L', g[1].unidades[0].valores.talla, 'y su valor original');
});

// ---------------------------------------------------------------

console.log('\n' + '-'.repeat(50));
console.log(pasaron + ' pasaron, ' + fallaron + ' fallaron');
process.exit(fallaron > 0 ? 1 : 0);
