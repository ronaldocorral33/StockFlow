<?php
require __DIR__ . '/src/Support/bootstrap.php';

use App\Auth;

header('Location: ' . url(Auth::currentUserId() !== null ? 'dashboard.php' : 'login.php'));
exit;
