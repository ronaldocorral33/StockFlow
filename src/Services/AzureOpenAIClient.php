<?php
namespace App\Services;

if (!defined('APP_BOOTSTRAP')) { http_response_code(403); exit('Forbidden'); }

/**
 * Wrapper mínimo (cURL puro, sin SDK) para el endpoint de Azure OpenAI compatible con la
 * API de OpenAI (.../openai/v1), el mismo que usa el SDK oficial de Python vía `base_url`.
 * No requiere api-version ni el path /deployments/{name}/ — el nombre del deployment
 * simplemente se manda como "model" en el cuerpo, igual que con la API de OpenAI normal.
 */
class AzureOpenAIClient
{
    public static function isConfigured(): bool
    {
        $cfg = APP_CONFIG['azure_openai'];
        return !empty($cfg['api_key']) && !empty($cfg['endpoint']) && !empty($cfg['deployment']);
    }

    /**
     * Envía una conversación y regresa el TEXTO de la respuesta.
     * @param array $messages [{role: 'user'|'assistant', content: string}]
     */
    public static function send(array $messages, string $system, int $maxTokens = 1024): string
    {
        $data = self::request(self::basePayload($messages, $system, $maxTokens));
        return trim($data['choices'][0]['message']['content'] ?? '');
    }

    /**
     * Envía una conversación exigiendo que la respuesta cumpla un JSON Schema
     * (Structured Outputs). Regresa el JSON ya decodificado, no texto.
     *
     * `strict => true` hace que el proveedor garantice la forma del JSON: no puede
     * devolver campos extra, ni omitir requeridos, ni usar un valor fuera del enum.
     *
     * @return array|null null si la respuesta no fue JSON decodificable.
     */
    public static function sendStructured(
        array $messages,
        string $system,
        array $schema,
        string $schemaName,
        int $maxTokens = 1024
    ): ?array {
        $payload = self::basePayload($messages, $system, $maxTokens);
        $payload['response_format'] = [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => $schemaName,
                'strict' => true,
                'schema' => $schema,
            ],
        ];

        $data = self::request($payload);
        $content = $data['choices'][0]['message']['content'] ?? '';
        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function basePayload(array $messages, string $system, int $maxTokens): array
    {
        return [
            'model' => APP_CONFIG['azure_openai']['deployment'],
            'messages' => array_merge([['role' => 'system', 'content' => $system]], $messages),
            // Los modelos de la familia gpt-5/o-series no aceptan "max_tokens"; usan este nombre.
            'max_completion_tokens' => $maxTokens,
        ];
    }

    /** Transporte HTTP compartido por send() y sendStructured(). */
    private static function request(array $payload): array
    {
        $cfg = APP_CONFIG['azure_openai'];
        if (!self::isConfigured()) {
            throw new \RuntimeException('El chatbot no está configurado: falta api_key/endpoint/deployment de Azure OpenAI en config/config.php.');
        }

        $ch = curl_init(rtrim($cfg['endpoint'], '/') . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $cfg['api_key'],
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('No se pudo contactar a Azure OpenAI: ' . $curlError);
        }
        $data = json_decode($response, true);
        if ($httpCode >= 400) {
            $msg = $data['error']['message'] ?? ('HTTP ' . $httpCode);
            throw new \RuntimeException('Error de Azure OpenAI: ' . $msg);
        }
        return is_array($data) ? $data : [];
    }
}
