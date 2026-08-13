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
     * Envía una conversación a Claude y regresa el texto de la respuesta.
     * @param array $messages [{role: 'user'|'assistant', content: string}]
     */
    public static function send(array $messages, string $system, int $maxTokens = 1024): string
    {
        $cfg = APP_CONFIG['anthropic'];
        if (empty($cfg['api_key'])) {
            throw new \RuntimeException('El chatbot no está configurado: falta ANTHROPIC_API_KEY en config/config.php.');
        }

        $payload = json_encode([
            'model' => $cfg['model'],
            'max_tokens' => $maxTokens,
            'system' => $system,
            'messages' => $messages,
        ]);

        $ch = curl_init($cfg['api_url']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 30,
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

        $text = '';
        foreach ($data['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'];
            }
        }
        return trim($text);
    }
}
