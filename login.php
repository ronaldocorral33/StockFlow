<?php
require __DIR__ . '/src/Support/bootstrap.php';

use App\Auth;

if (Auth::currentUserId() !== null) {
    header('Location: ' . url('dashboard.php'));
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = (string)($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    if (Auth::attempt($email, $password)) {
        header('Location: ' . url('dashboard.php'));
        exit;
    }
    $error = 'Correo o contraseña incorrectos.';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Iniciar sesión · StockFlow</title>
<link rel="stylesheet" href="<?= esc(url('assets/css/app.css')) ?>">
</head>
<body>
<div class="authwrap">
  <div class="auth-brand">
    <div class="top"><div class="mark">S</div><div class="name">StockFlow</div></div>
    <div class="pitch">
      <h2>Cada pieza tiene su propia historia de ganancia.</h2>
      <p>Compras, ventas, proyecciones y un asistente que responde preguntas sobre tu propio inventario — sin importar qué revendas.</p>
    </div>
    <div class="foot">© <?= date('Y') ?> StockFlow</div>
  </div>
  <div class="auth-form">
    <div class="authcard">
      <h1>Bienvenido de vuelta</h1>
      <p class="sub">Inicia sesión para ver tu inventario</p>
      <?php if ($error): ?><div class="errbox"><?= esc($error) ?></div><?php endif; ?>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
        <div class="field"><label>Correo</label><input type="email" name="email" required autofocus value="<?= esc($_POST['email'] ?? '') ?>"></div>
        <div class="field"><label>Contraseña</label><input type="password" name="password" required></div>
        <button class="btn primary block" type="submit">Entrar</button>
      </form>
      <p class="switch">¿No tienes cuenta? <a href="<?= esc(url('register.php')) ?>">Regístrate</a></p>
    </div>
  </div>
</div>
</body>
</html>
