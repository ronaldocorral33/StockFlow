<?php
require __DIR__ . '/src/Support/bootstrap.php';

use App\Models\AttributeDefinition;

['user_id' => $userId, 'business_id' => $businessId] = require_business();
$notice = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['do'] ?? '');
    try {
        if ($action === 'create') {
            AttributeDefinition::create($businessId, $userId, [
                'label' => $_POST['label'] ?? '',
                'field_type' => $_POST['field_type'] ?? 'text',
                'options' => $_POST['options'] ?? '',
                'is_required' => !empty($_POST['is_required']),
                'show_in_table' => !empty($_POST['show_in_table']),
            ]);
            $notice = 'Campo agregado.';
        } elseif ($action === 'update') {
            $id = (int)$_POST['id'];
            AttributeDefinition::update($id, $businessId, [
                'label' => $_POST['label'] ?? '',
                'field_type' => $_POST['field_type'] ?? 'text',
                'options' => $_POST['options'] ?? '',
                'is_required' => !empty($_POST['is_required']) ? 1 : 0,
                'show_in_table' => !empty($_POST['show_in_table']) ? 1 : 0,
            ]);
            $notice = 'Campo actualizado.';
        } elseif ($action === 'delete') {
            AttributeDefinition::delete((int)$_POST['id'], $businessId);
            $notice = 'Campo eliminado.';
        } elseif ($action === 'move') {
            $fields = AttributeDefinition::listForBusiness($businessId);
            $ids = array_column($fields, 'id');
            $id = (int)$_POST['id'];
            $dir = $_POST['dir'] === 'up' ? -1 : 1;
            $pos = array_search($id, $ids, true);
            if ($pos !== false && isset($ids[$pos + $dir])) {
                [$ids[$pos], $ids[$pos + $dir]] = [$ids[$pos + $dir], $ids[$pos]];
                AttributeDefinition::reorder($businessId, $ids);
            }
        }
    } catch (\InvalidArgumentException $e) {
        $error = $e->getMessage();
    }
}

$fields = AttributeDefinition::listForBusiness($businessId);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Configuración de campos · StockFlow</title>
<link rel="stylesheet" href="<?= esc(url('assets/css/app.css')) ?>">
</head>
<body>
<div class="settings-shell">
<div class="settings-top">
  <div class="brand"><div class="mark">S</div>Configuración de campos</div>
  <div class="htools"><a class="btnlink" href="<?= esc(url('dashboard.php')) ?>"><?= icon('package', 15) ?> Volver al inventario</a></div>
</div>
<div class="settings-wrap">
  <?php if ($notice): ?><div class="okbox"><?= esc($notice) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="errbox"><?= esc($error) ?></div><?php endif; ?>

  <div class="cap-head">
    <h3>Tus campos personalizados</h3>
    <p>Estos son los campos extra que aparecen al capturar y editar productos (además de nombre, categoría, costo, envío y venta, que siempre están disponibles).</p>
    <?php if (!$fields): ?>
      <p class="muted">Aún no tienes campos personalizados. Agrega el primero abajo.</p>
    <?php else: ?>
    <div class="tablecard"><div class="tscroll"><table style="min-width:640px">
      <thead><tr><th>Orden</th><th>Etiqueta</th><th>Clave</th><th>Tipo</th><th>Opciones</th><th>Requerido</th><th>En tabla</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($fields as $i => $f): ?>
        <tr>
          <td>
            <form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>"><input type="hidden" name="do" value="move"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><input type="hidden" name="dir" value="up"><button class="rowbtn" <?= $i === 0 ? 'disabled' : '' ?>><?= icon('chevronUp', 15) ?></button></form>
            <form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>"><input type="hidden" name="do" value="move"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><input type="hidden" name="dir" value="down"><button class="rowbtn" <?= $i === count($fields) - 1 ? 'disabled' : '' ?>><?= icon('chevronDown', 15) ?></button></form>
          </td>
          <td colspan="6">
            <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
              <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
              <input type="hidden" name="do" value="update">
              <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
              <input name="label" value="<?= esc($f['label']) ?>" style="width:140px" required>
              <span class="muted"><?= esc($f['field_key']) ?></span>
              <select name="field_type">
                <?php foreach (['text' => 'Texto', 'number' => 'Número', 'date' => 'Fecha', 'select' => 'Lista'] as $ft => $ftl): ?>
                <option value="<?= $ft ?>" <?= $f['field_type'] === $ft ? 'selected' : '' ?>><?= $ftl ?></option>
                <?php endforeach; ?>
              </select>
              <input name="options" placeholder="opción1, opción2..." value="<?= esc($f['options'] ? implode(', ', $f['options']) : '') ?>" style="width:160px">
              <label style="display:flex;align-items:center;gap:4px;font-size:.8rem"><input type="checkbox" name="is_required" <?= $f['is_required'] ? 'checked' : '' ?>> req.</label>
              <label style="display:flex;align-items:center;gap:4px;font-size:.8rem"><input type="checkbox" name="show_in_table" <?= $f['show_in_table'] ? 'checked' : '' ?>> en tabla</label>
              <button class="btn sm">Guardar</button>
            </form>
          </td>
          <td>
            <form method="post" onsubmit="return confirm('¿Eliminar este campo? Los productos existentes conservarán el valor guardado, pero dejará de mostrarse.');">
              <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
              <input type="hidden" name="do" value="delete">
              <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
              <button class="rowbtn" title="Eliminar"><?= icon('trash', 16) ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div></div>
    <?php endif; ?>
  </div>

  <div class="cap-head">
    <h3>Agregar campo nuevo</h3>
    <form method="post" class="cap-grid" style="align-items:end">
      <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
      <input type="hidden" name="do" value="create">
      <div class="field"><label>Etiqueta</label><input name="label" placeholder="Ej. Color" required></div>
      <div class="field"><label>Tipo</label>
        <select name="field_type">
          <option value="text">Texto</option>
          <option value="number">Número</option>
          <option value="date">Fecha</option>
          <option value="select">Lista (opciones)</option>
        </select>
      </div>
      <div class="field"><label>Opciones <span class="hint">(si es tipo Lista, separadas por coma)</span></label><input name="options" placeholder="S, M, L, XL"></div>
      <div class="field"><label><input type="checkbox" name="show_in_table" checked> Mostrar en tabla de inventario</label></div>
      <div class="field"><label><input type="checkbox" name="is_required"> Obligatorio</label></div>
      <div class="field"><button class="btn in"><?= icon('plus', 15) ?> Agregar campo</button></div>
    </form>
  </div>
</div>
</div>
</body>
</html>
