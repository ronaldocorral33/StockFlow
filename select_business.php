<?php
require __DIR__ . '/src/Support/bootstrap.php';

use App\Auth;
use App\Models\Business;

$userId = require_login();
$businesses = Business::listForUser($userId);

if (count($businesses) === 1) {
    $_SESSION['active_business_id'] = (int)$businesses[0]['id'];
    header('Location: ' . url('dashboard.php'));
    exit;
}
if (!$businesses) {
    // No debería pasar en flujo normal (registro siempre crea uno), pero por si acaso.
    header('Location: ' . url('login.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $chosen = (int)($_POST['business_id'] ?? 0);
    $valid = array_filter($businesses, fn($b) => (int)$b['id'] === $chosen);
    if ($valid) {
        $_SESSION['active_business_id'] = $chosen;
        header('Location: ' . url('dashboard.php'));
        exit;
    }
}

$user = Auth::currentUser();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Elige tu negocio · StockFlow</title>
<link rel="stylesheet" href="<?= esc(url('assets/css/app.css')) ?>">
</head>
<body>
<div class="onboard-shell">
  <div class="onboard-card">
    <div class="top">
      <div class="mark">S</div>
      <h1>¿Con qué negocio quieres trabajar?</h1>
      <p class="sub">Hola <?= esc($user['name']) ?> — perteneces a más de un negocio.</p>
    </div>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
      <div class="templates">
        <?php foreach ($businesses as $b): ?>
        <div class="tplcard" onclick="document.getElementById('bid-<?= (int)$b['id'] ?>').checked=true; this.closest('form').submit();">
          <h3><?= esc($b['name']) ?></h3>
          <p>Tu rol: <?= esc($b['role_label']) ?></p>
          <input type="radio" name="business_id" id="bid-<?= (int)$b['id'] ?>" value="<?= (int)$b['id'] ?>" style="display:none">
        </div>
        <?php endforeach; ?>
      </div>
    </form>
  </div>
</div>
</body>
</html>
