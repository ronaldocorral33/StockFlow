<?php
namespace App\Services;

if (!defined('APP_BOOTSTRAP')) { http_response_code(403); exit('Forbidden'); }

/** Wrapper mínimo (cURL puro, sin SDK) para la API de mensajes de Anthropic. */
class ClaudeClient
{
    public static function isConfigured(): bool
    {
        return !empty(APP_CONFIG['anthropic']['api_key']);
    }

    /**
     * Envía una conversación y regresa el TEXTO de la respuesta.
     * @param array $messages [{role: 'user'|'assistant', content: string}]
     */
    public static function send(array $messages, string $system, int $maxTokens = 1024): string
    {
        $data = self::request([
            'model' => APP_CONFIG['anthropic']['model'],
            'max_tokens' => $maxTokens,
            'system' => $system,
            'messages' => $messages,
        ]);

        $text = '';
        foreach ($data['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'];
            }
        }
        return trim($text);
    }

    /**
     * Salida estructurada en Anthropic.
     *
     * Claude no tiene `response_format` como OpenAI. La forma canónica de obtener
     * JSON garantizado es declarar UNA herramienta cuyo `input_schema` es el esquema
     * deseado, y forzar su uso con `tool_choice`. El modelo entonces no responde texto:
     * responde un bloque `tool_use` cuyo `input` ya es el objeto que queríamos.
     *
     * Nota conceptual: esto es literalmente tool calling. Lo que en OpenAI es una
     * función aparte, aquí es el mismo mecanismo que usaremos para las herramientas
     * reales más adelante.
     *
     * @return array|null null si el modelo no produjo el bloque tool_use esperado.
     */
    public static function sendStructured(
        array $messages,
        string $system,
        array $schema,
        string $schemaName,
        int $maxTokens = 1024
    ): ?array {
        $data = self::request([
            'model' => APP_CONFIG['anthropic']['model'],
            'max_tokens' => $maxTokens,
            'system' => $system,
            'messages' => $messages,
            'tools' => [[
                'name' => $schemaName,
                'description' => 'Devuelve la decisión estructurada para la pregunta del usuario.',
                'input_schema' => $schema,
            ]],
            // Obliga al modelo a usar esa herramienta: no puede contestar texto libre.
            'tool_choice' => ['type' => 'tool', 'name' => $schemaName],
        ]);

        foreach ($data['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'tool_use' && ($block['name'] ?? '') === $schemaName) {
                return is_array($block['input'] ?? null) ? $block['input'] : null;
            }
        }
        return null;
    }

    /** Transporte HTTP compartido por send() y sendStructured(). */
    private static function request(array $payload): array
    {
        $cfg = APP_CONFIG['anthropic'];
        if (empty($cfg['api_key'])) {
            throw new \RuntimeException('El chatbot no está configurado: falta ANTHROPIC_API_KEY en config/config.php.');
        }

        $ch = curl_init($cfg['api_url']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $cfg['api_key'],
                'anthropic-version: ' . $cfg['api_version'],
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('No se pudo contactar a la API de Claude: ' . $curlError);
        }
        $data = json_decode($response, true);
        if ($httpCode >= 400) {
            $msg = $data['error']['message'] ?? ('HTTP ' . $httpCode);
            throw new \RuntimeException('Error de la API de Claude: ' . $msg);
        }
        return is_array($data) ? $data : [];
    }
}
