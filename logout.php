<?php
require __DIR__ . '/src/Support/bootstrap.php';

use App\Auth;

Auth::logout();
header('Location: ' . url('login.php'));
exit;
