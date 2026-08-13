<?php
if (!defined('APP_BOOTSTRAP')) { http_response_code(403); exit('Forbidden'); }

use App\Auth;
use App\Database;
use App\Models\Business;

/** Escapa una cadena para salida segura en HTML. */
function esc(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** Responde JSON y termina la ejecución. */
function json_response($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Lee y decodifica el cuerpo JSON de la petición actual. */
function json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/** Exige sesión iniciada; para páginas HTML redirige a login, para API responde 401. */
function require_login(bool $isApi = false): int
{
    $userId = Auth::currentUserId();
    if ($userId === null) {
        if ($isApi) {
            json_response(['error' => 'No autenticado'], 401);
        }
        header('Location: ' . url('login.php'));
        exit;
    }
    return $userId;
}

/**
 * Exige sesión iniciada Y un negocio activo seleccionado; regresa ambos ids.
 * Si el usuario pertenece a exactamente 1 negocio, se auto-selecciona sin fricción.
 * Si pertenece a 2+, hay que pasar por select_business.php primero.
 * @return array{user_id:int, business_id:int}
 */
function require_business(bool $isApi = false): array
{
    $userId = require_login($isApi);

    $businessId = $_SESSION['active_business_id'] ?? null;
    if ($businessId !== null && !Business::isMember((int)$businessId, $userId)) {
        $businessId = null; // ya no pertenece (removido/deshabilitado) — se re-resuelve abajo
    }

    if ($businessId === null) {
        $businesses = Business::listForUser($userId);
        if (count($businesses) === 1) {
            $businessId = (int)$businesses[0]['id'];
            $_SESSION['active_business_id'] = $businessId;
        } else {
            if ($isApi) {
                json_response(['error' => 'Selecciona un negocio primero', 'businesses' => $businesses], 409);
            }
            header('Location: ' . url('select_business.php'));
            exit;
        }
    }

    return ['user_id' => $userId, 'business_id' => (int)$businessId];
}

/**
 * Limita intentos por bucket + ventana fija (sin dependencias externas).
 * @return bool true si YA se pasó del límite (la llamada actual debe rechazarse).
 */
function rate_limit_hit(string $bucket, int $windowSeconds, int $maxAttempts): bool
{
    $windowStart = date('Y-m-d H:i:s', intdiv(time(), $windowSeconds) * $windowSeconds);
    $pdo = Database::connection();
    $pdo->prepare(
        'INSERT INTO rate_limits (bucket_key, window_start, attempts) VALUES (?, ?, 1)
         ON DUPLICATE KEY UPDATE attempts = attempts + 1'
    )->execute([$bucket, $windowStart]);

    $stmt = $pdo->prepare('SELECT attempts FROM rate_limits WHERE bucket_key = ? AND window_start = ?');
    $stmt->execute([$bucket, $windowStart]);
    return (int)$stmt->fetchColumn() > $maxAttempts;
}

/** Genera (o reutiliza) el token CSRF de la sesión. */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Verifica el token CSRF recibido (header X-CSRF-Token o campo csrf_token). */
function csrf_check(bool $isApi = false): void
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
    $valid = !empty($_SESSION['csrf_token']) && is_string($sent) && hash_equals($_SESSION['csrf_token'], $sent);
    if (!$valid) {
        if ($isApi) {
            json_response(['error' => 'Token CSRF inválido'], 403);
        }
        http_response_code(403);
        exit('Token CSRF inválido');
    }
}
