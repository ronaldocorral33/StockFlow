<?php
require __DIR__ . '/src/Support/bootstrap.php';

use App\Auth;
use App\Models\AttributeDefinition;

$userId = require_login();
$user = Auth::currentUser();
if ($user['onboarded_at'] !== null) {
    header('Location: ' . url('dashboard.php'));
    exit;
}

$templates = require __DIR__ . '/config/attribute_templates.php';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $key = (string)($_POST['template'] ?? '');
    if (!isset($templates[$key])) {
        $error = 'Elige una plantilla válida.';
    } else {
        AttributeDefinition::applyTemplate($userId, $key);
        Auth::markOnboarded($userId);
        header('Location: ' . url('dashboard.php'));
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Configura tu inventario · StockFlow</title>
<link rel="stylesheet" href="<?= esc(url('assets/css/app.css')) ?>">
</head>
<body>
<div class="onboard-shell">
  <div class="onboard-card">
    <div class="top">
      <div class="mark">S</div>
      <h1>¿Qué vas a inventariar?</h1>
      <p class="sub">Elige una plantilla para empezar rápido — luego puedes agregar, editar o quitar campos cuando quieras desde Configuración.</p>
    </div>
    <?php if ($error): ?><div class="errbox"><?= esc($error) ?></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
      <input type="hidden" name="template" id="template-input" value="">
      <div class="templates">
        <?php foreach ($templates as $key => $tpl): ?>
        <div class="tplcard" onclick="document.getElementById('template-input').value='<?= esc($key) ?>'; this.closest('form').submit();">
          <h3><?= esc($tpl['label']) ?></h3>
          <p><?= esc($tpl['description']) ?></p>
          <div class="fields">
            <?php if (empty($tpl['fields'])): ?>
              <span>Sin campos iniciales</span>
            <?php else: foreach ($tpl['fields'] as $f): ?>
              <span><?= esc($f['label']) ?></span>
            <?php endforeach; endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </form>
  </div>
</div>
</body>
</html>
