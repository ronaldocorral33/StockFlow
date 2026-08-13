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
     * Envía una conversación a Azure OpenAI y regresa el texto de la respuesta.
     * @param array $messages [{role: 'user'|'assistant', content: string}]
     */
    public static function send(array $messages, string $system, int $maxTokens = 1024): string
    {
        $cfg = APP_CONFIG['azure_openai'];
        if (!self::isConfigured()) {
            throw new \RuntimeException('El chatbot no está configurado: falta api_key/endpoint/deployment de Azure OpenAI en config/config.php.');
        }

        $url = rtrim($cfg['endpoint'], '/') . '/chat/completions';

        $payload = json_encode([
            'model' => $cfg['deployment'],
            'messages' => array_merge(
                [['role' => 'system', 'content' => $system]],
                $messages
            ),
            // Los modelos de la familia gpt-5/o-series no aceptan "max_tokens"; usan este nombre.
            'max_completion_tokens' => $maxTokens,
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 30,
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

        return trim($data['choices'][0]['message']['content'] ?? '');
    }
}
