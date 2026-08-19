<?php
namespace App\Support;

if (!defined('APP_BOOTSTRAP')) { http_response_code(403); exit('Forbidden'); }

/**
 * Lector de configuración sensible, con dos orígenes y un orden de prioridad claro:
 *
 *   1. Variable de entorno REAL del sistema/servidor  ← lo correcto en producción
 *   2. Archivo .env en la raíz del proyecto            ← práctico en desarrollo local
 *   3. Valor por defecto que se pase al llamar
 *
 * Por qué las dos capas: en un servidor de verdad las credenciales se inyectan como
 * variables de entorno (nunca tocan el disco del proyecto). Pero en XAMPP configurar
 * variables de entorno de Apache es incómodo, así que .env da el mismo resultado
 * práctico — un archivo fuera de git — sin fricción. El código no cambia entre
 * ambos casos: siempre pregunta por Env::get().
 */
class Env
{
    private static ?array $fileValues = null;

    public static function get(string $key, ?string $default = null): ?string
    {
        // 1. Variable de entorno real (getenv y $_ENV/$_SERVER, según cómo la inyecte el host).
        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return $value;
        }
        foreach ([$_ENV, $_SERVER] as $bag) {
            if (!empty($bag[$key])) {
                return (string)$bag[$key];
            }
        }

        // 2. Archivo .env.
        self::loadFile();
        if (isset(self::$fileValues[$key]) && self::$fileValues[$key] !== '') {
            return self::$fileValues[$key];
        }

        return $default;
    }

    /** Parseo mínimo de .env: CLAVE=valor, con # para comentarios y comillas opcionales. */
    private static function loadFile(): void
    {
        if (self::$fileValues !== null) {
            return;
        }
        self::$fileValues = [];

        $path = dirname(__DIR__, 2) . '/.env';
        if (!is_readable($path)) {
            return;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));
            // Quitar comillas envolventes si las trae.
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                $value = substr($value, 1, -1);
            }
            self::$fileValues[$key] = $value;
        }
    }
}
