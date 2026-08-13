<?php
namespace App\Services;

if (!defined('APP_BOOTSTRAP')) { http_response_code(403); exit('Forbidden'); }

/**
 * Valida el SQL que genera el LLM antes de ejecutarlo. Principio: nunca se confía en que el
 * modelo se limite solo al negocio correcto ni en que su SQL sea sintácticamente segura —
 * esta clase valida y el llamador sustituye el business_id real, siempre.
 */
class SqlGuard
{
    private const BLOCKED_KEYWORDS = [
        'INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'TRUNCATE', 'CREATE', 'REPLACE',
        'GRANT', 'REVOKE', 'EXEC', 'EXECUTE', 'CALL', 'LOAD_FILE', 'OUTFILE', 'DUMPFILE',
        'SLEEP', 'BENCHMARK', 'UNION', 'INFORMATION_SCHEMA', 'PERFORMANCE_SCHEMA', 'MYSQL',
    ];

    /**
     * Columnas de identidad que el LLM puede referenciar, cada una solo vía su token.
     * business_id es el límite real de tenant (obligatorio); user_id identifica al empleado
     * que hizo la acción y es opcional (ej. "qué capturó cada quién" dentro del mismo negocio).
     */
    private const SCOPE_COLUMNS = [
        'business_id' => ['token' => '{{BUSINESS_ID}}', 'required' => true],
        'user_id'     => ['token' => '{{USER_ID}}',     'required' => false],
    ];

    /** @param string[] $allowedTables nombres de tabla permitidos, en minúsculas. */
    public static function validate(string $raw, array $allowedTables): array
    {
        $sql = self::extractFromFence($raw) ?? trim($raw);
        $trimmed = ltrim($sql);

        if ($trimmed === '') {
            return self::reject('La respuesta del modelo vino vacía.');
        }
        if (stripos($trimmed, 'SELECT') !== 0) {
            return self::reject('La consulta generada no empieza con SELECT.');
        }

        $body = rtrim($trimmed, "; \t\n\r");
        if (strpos($body, ';') !== false) {
            return self::reject('La consulta contiene un punto y coma (posible múltiple sentencia).');
        }
        if (preg_match('/--|#|\/\*/', $body)) {
            return self::reject('La consulta contiene un comentario SQL.');
        }

        foreach (self::BLOCKED_KEYWORDS as $kw) {
            if (preg_match('/\b' . preg_quote($kw, '/') . '\b/i', $body)) {
                return self::reject("La consulta usa una palabra no permitida: $kw");
            }
        }

        $tables = self::extractTableNames($body);
        if (empty($tables)) {
            return self::reject('No se pudo identificar ninguna tabla en la consulta.');
        }
        foreach ($tables as $t) {
            if (!in_array(strtolower($t), $allowedTables, true)) {
                return self::reject("La consulta usa una tabla no permitida: $t");
            }
        }

        // Cada columna de identidad debe aparecer EXACTAMENTE como "[alias.]columna = {{TOKEN}}" —
        // nunca combinada con OR, comparada contra un literal, o repetida de otra forma. Esto evita
        // que el modelo (o una pregunta con prompt injection) cuele un "OR business_id = 1" que se
        // colaría igual de "válido" si solo revisáramos que el token existe en algún lugar.
        // OJO: cada token contiene su propio nombre de columna en mayúsculas, así que primero hay
        // que quitar los tokens del texto antes de contar menciones sueltas de la columna.
        foreach (self::SCOPE_COLUMNS as $column => $cfg) {
            $occurrences = substr_count($body, $cfg['token']);
            if ($cfg['required'] && $occurrences < 1) {
                return self::reject("La consulta no incluye el filtro obligatorio de negocio.");
            }
            if ($occurrences === 0) {
                // Columna opcional ausente: aún así hay que asegurar que no se mencione "a mano".
                if (preg_match('/\b' . $column . '\b/i', $body)) {
                    return self::reject("La consulta menciona $column sin usar su token obligatorio.");
                }
                continue;
            }

            preg_match_all(
                '/(?:\w+\.)?' . $column . '\s*=\s*' . preg_quote($cfg['token'], '/') . '/i',
                $body,
                $safeMentions
            );
            $bodyWithoutTokens = str_replace($cfg['token'], '', $body);
            preg_match_all('/\b' . $column . '\b/i', $bodyWithoutTokens, $allMentions);
            if (count($allMentions[0]) !== count($safeMentions[0])) {
                return self::reject("La consulta manipula el filtro de $column de forma no permitida.");
            }
        }

        $body = self::enforceLimit($body);

        return ['valid' => true, 'sql' => $body, 'reason' => null];
    }

    /** Sustituye {{BUSINESS_ID}} por el entero real, SIEMPRE después de validate(). */
    public static function scopeToBusiness(string $validatedSql, int $businessId): string
    {
        return str_replace(self::SCOPE_COLUMNS['business_id']['token'], (string)$businessId, $validatedSql);
    }

    /** Sustituye {{USER_ID}} si está presente (no-op si el modelo no lo usó). */
    public static function scopeToUser(string $validatedSql, int $userId): string
    {
        return str_replace(self::SCOPE_COLUMNS['user_id']['token'], (string)$userId, $validatedSql);
    }

    private static function reject(string $reason): array
    {
        return ['valid' => false, 'sql' => null, 'reason' => $reason];
    }

    private static function extractFromFence(string $raw): ?string
    {
        if (preg_match('/```sql\s*(.+?)\s*```/is', $raw, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/```\s*(.+?)\s*```/is', $raw, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    private static function extractTableNames(string $sql): array
    {
        preg_match_all('/\b(?:FROM|JOIN)\s+`?(\w+)`?/i', $sql, $matches);
        return array_values(array_unique($matches[1] ?? []));
    }

    private static function enforceLimit(string $sql): string
    {
        if (!preg_match('/\bLIMIT\s+\d+\b/i', $sql)) {
            return $sql . ' LIMIT 200';
        }
        return preg_replace_callback('/\bLIMIT\s+(\d+)\b/i', function ($m) {
            return 'LIMIT ' . min((int)$m[1], 500);
        }, $sql);
    }
}
