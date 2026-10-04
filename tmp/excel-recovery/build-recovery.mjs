import fs from "node:fs/promises";
import { FileBlob, SpreadsheetFile, Workbook } from "@oai/artifact-tool";

const oldPath = "C:/Users/roni4/Downloads/inventario_2026-09-27_fechas_recuperadas_Dysys.xlsx";
const currentPath = "C:/Users/roni4/Downloads/inventario_2026-10-01.xlsx";
const outputPath = "C:/xampp/htdocs/Control De Inventario/output/recuperacion_nombres_jugador_2026-09-30_completado.xlsx";

const readRows = async (path) => {
  const blob = await FileBlob.load(path);
  const workbook = await SpreadsheetFile.importXlsx(blob);
  const sheet = workbook.worksheets.getItemAt(0);
  const values = sheet.getUsedRange().values;
  return { header: values[0], rows: values.slice(1) };
};

const old = await readRows(oldPath);
const current = await readRows(currentPath);

const oldKeyColumns = [0, 1, 2, 3, 4, 6, 8, 9, 10, 11, 12, 13, 14, 15];
const currentKeyColumns = [1, 5, 6, 7, 8, 10, 12, 13, 14, 15, 16, 17, 18, 19];
const oldNameColumn = 16;

function normal(value) {
  if (value instanceof Date) return value.toISOString().slice(0, 10);
  const text = String(value ?? "").trim();
  if (/^-?[0-9,]+(?:\.[0-9]+)?$/.test(text)) return String(Number(text.replaceAll(",", "")));
  return text.toLocaleLowerCase();
}

function makeKey(row, columns) {
  return columns.map((column) => normal(row[column])).join("\u001f");
}

function playerName(row) {
  return String(row[oldNameColumn] ?? "").trim();
}

function isRealPlayerName(value) {
  return value !== "" && !["sin nombre", "n/a", "na"].includes(value.toLocaleLowerCase());
}

const oldGroups = new Map();
for (const row of old.rows) {
  const key = makeKey(row, oldKeyColumns);
  oldGroups.set(key, [...(oldGroups.get(key) ?? []), row]);
}

const currentGroups = new Map();
for (const row of current.rows) {
  const key = makeKey(row, currentKeyColumns);
  currentGroups.set(key, [...(currentGroups.get(key) ?? []), row]);
}

const recoveryRows = [];
const pendingRows = [];
const assignedById = new Map();

for (const [key, targetRows] of currentGroups) {
  const sourceRows = oldGroups.get(key) ?? [];
  const sourceNames = sourceRows.map(playerName);
  const uniqueNames = [...new Set(sourceNames)];
  const oneSource = sourceRows.length === 1;
  const sameNameForEveryTwin = sourceRows.length === targetRows.length
    && uniqueNames.length === 1
    && isRealPlayerName(uniqueNames[0]);
  const recoveredName = oneSource ? sourceNames[0] : (sameNameForEveryTwin ? uniqueNames[0] : "");

  if (isRealPlayerName(recoveredName)) {
    for (const target of targetRows) {
      recoveryRows.push([target[0], recoveredName]);
      assignedById.set(Number(target[0]), recoveredName);
    }
    continue;
  }

  const candidates = [...new Set(sourceNames.filter(isRealPlayerName))];
  if (candidates.length) {
    for (const target of targetRows) {
      pendingRows.push([
        target[0],
        target[1],
        target[19],
        target[6],
        candidates.join(", "),
      ]);
    }
  }
}

// El usuario confirmó las cantidades de estos dos pedidos y autorizó distribuir los
// nombres entre IDs equivalentes. Solo se completan dentro del mismo equipo y pedido.
const quotas = [
  { product: "Real Madrid", order: 53, names: [["MBAPPE", 5], ["Bellingham", 5]] },
  { product: "San Francisco 49ers", order: 52, names: [["Purdy", 5], ["McAffrey", 5]] },
];
const controlledIds = new Set();
for (const quota of quotas) {
  const targetRows = current.rows
    .filter((row) => row[1] === quota.product && Number(row[6]) === quota.order)
    .sort((a, b) => Number(a[0]) - Number(b[0]));
  targetRows.forEach((row) => controlledIds.add(Number(row[0])));

  for (const [name, total] of quota.names) {
    const currentCount = targetRows.filter((row) =>
      String(assignedById.get(Number(row[0])) ?? "").toLocaleLowerCase() === name.toLocaleLowerCase()
    ).length;
    let remaining = Math.max(0, total - currentCount);
    for (const row of targetRows) {
      const id = Number(row[0]);
      if (remaining === 0) break;
      if (!assignedById.has(id)) {
        assignedById.set(id, name);
        recoveryRows.push([row[0], name]);
        remaining--;
      }
    }
  }
}

// Las filas de los pedidos con cantidades confirmadas que no recibieron nombre se
// dejan como estaban: el usuario indicó que el total de esos jugadores ya se cubrió.
const filteredRecovery = [...assignedById.entries()].map(([id, name]) => [id, name]);
recoveryRows.length = 0;
recoveryRows.push(...filteredRecovery);
const filteredPendingRows = pendingRows.filter((row) => !controlledIds.has(Number(row[0])));
pendingRows.length = 0;
pendingRows.push(...filteredPendingRows);

recoveryRows.sort((a, b) => Number(a[0]) - Number(b[0]));
pendingRows.sort((a, b) => Number(a[0]) - Number(b[0]));

const workbook = Workbook.create();
const recovery = workbook.worksheets.add("Recuperar nombres");
recovery.showGridLines = false;
recovery.getRange(`A1:B${recoveryRows.length + 1}`).values = [
  ["ID", "Nombre de jugador"],
  ...recoveryRows,
];
recovery.getRange("A1:B1").format = {
  fill: "#17365D",
  font: { name: "Arial", size: 10, bold: true, color: "#FFFFFF" },
  horizontalAlignment: "center",
  verticalAlignment: "center",
};
recovery.getRange(`A2:A${recoveryRows.length + 1}`).format.numberFormat = "0";
recovery.getRange(`A1:B${recoveryRows.length + 1}`).format.font = { name: "Arial", size: 10 };
recovery.getRange(`A1:B${recoveryRows.length + 1}`).format.borders = { preset: "outside", style: "thin", color: "#D9E2F3" };
recovery.getRange("A:A").format.columnWidth = 14;
recovery.getRange("B:B").format.columnWidth = 28;
recovery.freezePanes.freezeRows(1);

const pending = workbook.worksheets.add("Revisar manualmente");
pending.showGridLines = false;
pending.getRange(`A1:E${pendingRows.length + 1}`).values = [
  ["ID", "Producto", "Talla", "Pedido", "Nombres posibles"],
  ...pendingRows,
];
pending.getRange("A1:E1").format = {
  fill: "#9C5700",
  font: { name: "Arial", size: 10, bold: true, color: "#FFFFFF" },
  horizontalAlignment: "center",
  verticalAlignment: "center",
};
pending.getRange(`A1:E${pendingRows.length + 1}`).format.font = { name: "Arial", size: 10 };
pending.getRange(`A1:E${pendingRows.length + 1}`).format.borders = { preset: "outside", style: "thin", color: "#F4B183" };
pending.getRange(`A2:E${pendingRows.length + 1}`).format.fill = "#FFF2CC";
pending.getRange(`A2:A${pendingRows.length + 1}`).format.numberFormat = "0";
pending.getRange("A:A").format.columnWidth = 14;
pending.getRange("B:B").format.columnWidth = 28;
pending.getRange("C:D").format.columnWidth = 14;
pending.getRange("E:E").format.columnWidth = 36;
pending.freezePanes.freezeRows(1);

const notes = workbook.worksheets.add("Instrucciones");
notes.showGridLines = false;
notes.getRange("A1:B7").values = [
  ["Recuperación de nombres de jugador", ""],
  ["Filas listas para importar", recoveryRows.length],
  ["Filas para revisar manualmente", pendingRows.length],
  ["Paso 1", "En la cuenta nueva, crea el campo personalizado “Nombre de jugador”."],
  ["Paso 2", "Importa la primera hoja en modo “Actualizar mi inventario”."],
  ["Paso 3", "Mapea ID a ID y Nombre de jugador al campo personalizado. No uses “Empezar de cero”."],
  ["Fuentes", "inventario_2026-10-01.xlsx e inventario_2026-09-27_fechas_recuperadas_Dysys.xlsx"],
];
notes.getRange("A1:B1").merge();
notes.getRange("A1").format = {
  font: { name: "Arial", size: 14, bold: true, color: "#17365D" },
  verticalAlignment: "center",
};
notes.getRange("A2:A7").format = { font: { name: "Arial", size: 10, bold: true, color: "#17365D" } };
notes.getRange("A2:B7").format.font = { name: "Arial", size: 10 };
notes.getRange("A:A").format.columnWidth = 33;
notes.getRange("B:B").format.columnWidth = 92;
notes.getRange("B4:B7").format.wrapText = true;

workbook.recalculate();
const check = await workbook.inspect({
  kind: "table",
  range: "Recuperar nombres!A1:B12",
  include: "values,formulas",
  tableMaxRows: 12,
  tableMaxCols: 2,
});
const errors = await workbook.inspect({
  kind: "match",
  searchTerm: "#REF!|#DIV/0!|#VALUE!|#NAME\\?|#N/A|#NUM!|#NULL!|#SPILL!|#CALC!",
  options: { useRegex: true, maxResults: 100 },
  summary: "formula error scan",
});
console.log(check.ndjson);
console.log(errors.ndjson);
const preview = await workbook.render({
  sheetName: "Recuperar nombres",
  range: "A1:B20",
  scale: 2,
  format: "png",
});
await fs.writeFile("C:/xampp/htdocs/Control De Inventario/tmp/excel-recovery/preview.png", new Uint8Array(await preview.arrayBuffer()));
await fs.mkdir("C:/xampp/htdocs/Control De Inventario/output", { recursive: true });
const output = await SpreadsheetFile.exportXlsx(workbook);
await output.save(outputPath);

console.log(JSON.stringify({ outputPath, recoveryCount: recoveryRows.length, pendingCount: pendingRows.length }));
