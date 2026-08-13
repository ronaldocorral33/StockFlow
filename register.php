<?php
require __DIR__ . '/src/Support/bootstrap.php';

use App\Auth;
use App\Models\Business;

if (Auth::currentUserId() !== null) {
    header('Location: ' . url('dashboard.php'));
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (rate_limit_hit('register:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 3600, 5)) {
        $error = 'Demasiados intentos de registro. Intenta de nuevo en un rato.';
    } else {
        try {
            $name = (string)($_POST['name'] ?? '');
            $userId = Auth::register($name, (string)($_POST['email'] ?? ''), (string)($_POST['password'] ?? ''));
            Auth::attempt((string)$_POST['email'], (string)$_POST['password']);
            $businessId = Business::createWithOwner("Negocio de {$name}", $userId);
            $_SESSION['active_business_id'] = $businessId;
            header('Location: ' . url('onboarding.php'));
            exit;
        } catch (\InvalidArgumentException $e) {
            $error = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Crear cuenta · StockFlow</title>
<link rel="stylesheet" href="<?= esc(url('assets/css/app.css')) ?>">
</head>
<body>
<div class="authwrap">
  <div class="auth-brand">
    <div class="top"><div class="mark">S</div><div class="name">StockFlow</div></div>
    <div class="pitch">
      <h2>Configura tu inventario en minutos, sin importar qué vendas.</h2>
      <p>Jerseys, tenis, ropa, maquillaje — define tus propios campos y StockFlow se adapta a tu negocio, no al revés.</p>
    </div>
    <div class="foot">© <?= date('Y') ?> StockFlow</div>
  </div>
  <div class="auth-form">
    <div class="authcard">
      <h1>Crea tu cuenta</h1>
      <p class="sub">Lleva el control de inventario de cualquier producto</p>
      <?php if ($error): ?><div class="errbox"><?= esc($error) ?></div><?php endif; ?>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
        <div class="field"><label>Nombre</label><input name="name" required autofocus value="<?= esc($_POST['name'] ?? '') ?>"></div>
        <div class="field"><label>Correo</label><input type="email" name="email" required value="<?= esc($_POST['email'] ?? '') ?>"></div>
        <div class="field"><label>Contraseña</label><input type="password" name="password" required minlength="8"></div>
        <button class="btn primary block" type="submit">Crear cuenta</button>
      </form>
      <p class="switch">¿Ya tienes cuenta? <a href="<?= esc(url('login.php')) ?>">Inicia sesión</a></p>
    </div>
  </div>
</div>
</body>
</html>
