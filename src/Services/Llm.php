<?php
namespace App\Services;

if (!defined('APP_BOOTSTRAP')) { http_response_code(403); exit('Forbidden'); }

/** Fachada que elige el proveedor de LLM configurado (Azure OpenAI o Anthropic) sin que el resto del código lo sepa. */
class Llm
{
    private static function provider(): ?string
    {
        $preferred = APP_CONFIG['llm_provider'] ?? 'auto';
        if ($preferred === 'azure_openai' || $preferred === 'anthropic') {
            return $preferred;
        }
        if (AzureOpenAIClient::isConfigured()) {
            return 'azure_openai';
        }
        if (ClaudeClient::isConfigured()) {
            return 'anthropic';
        }
        return null;
    }

    public static function isConfigured(): bool
    {
        return self::provider() !== null;
    }

    public static function send(array $messages, string $system, int $maxTokens = 1024): string
    {
        return match (self::provider()) {
            'azure_openai' => AzureOpenAIClient::send($messages, $system, $maxTokens),
            'anthropic' => ClaudeClient::send($messages, $system, $maxTokens),
            default => throw new \RuntimeException('Ningún proveedor de LLM está configurado.'),
        };
    }
}
