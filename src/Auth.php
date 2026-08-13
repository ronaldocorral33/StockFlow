<?php
namespace App;

if (!defined('APP_BOOTSTRAP')) { http_response_code(403); exit('Forbidden'); }

use PDO;

class Auth
{
    /** @return int ID del usuario recién creado. */
    public static function register(string $name, string $email, string $password): int
    {
        $name = trim($name);
        $email = strtolower(trim($email));

        if ($name === '' || $email === '' || $password === '') {
            throw new \InvalidArgumentException('Todos los campos son obligatorios.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('El correo no es válido.');
        }
        if (strlen($password) < 8) {
            throw new \InvalidArgumentException('La contraseña debe tener al menos 8 caracteres.');
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            throw new \InvalidArgumentException('Ya existe una cuenta con ese correo.');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)');
        $stmt->execute([$name, $email, $hash]);

        return (int)$pdo->lastInsertId();
    }

    /** Intenta iniciar sesión; regresa true/false. En éxito, deja al usuario logueado. */
    public static function attempt(string $email, string $password): bool
    {
        $email = strtolower(trim($email));
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id, password_hash FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($password, $row['password_hash'])) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$row['id'];
        return true;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public static function currentUserId(): ?int
    {
        return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    }

    public static function currentUser(): ?array
    {
        $id = self::currentUserId();
        if ($id === null) {
            return null;
        }
        $stmt = Database::connection()->prepare(
            'SELECT id, name, email, currency_default, exchange_rate_default, onboarded_at FROM users WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function markOnboarded(int $userId): void
    {
        $stmt = Database::connection()->prepare('UPDATE users SET onboarded_at = NOW() WHERE id = ?');
        $stmt->execute([$userId]);
    }
}
