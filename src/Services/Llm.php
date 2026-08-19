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

    /** Nombre del proveedor que atendería ahora mismo. Para logs/diagnóstico, nunca para el usuario. */
    public static function providerName(): string
    {
        return self::provider() ?? 'ninguno';
    }

    public static function send(array $messages, string $system, int $maxTokens = 1024): string
    {
        return match (self::provider()) {
            'azure_openai' => AzureOpenAIClient::send($messages, $system, $maxTokens),
            'anthropic' => ClaudeClient::send($messages, $system, $maxTokens),
            default => throw new \RuntimeException('Ningún proveedor de LLM está configurado.'),
        };
    }

    /**
     * Envía la conversación ofreciéndole herramientas al modelo.
     *
     * El modelo puede: (a) responder texto, o (b) pedir una o más herramientas.
     * Nunca ejecuta nada — solo emite la intención. Ejecutar es trabajo de PHP.
     *
     * @return array{text:?string, tool_calls:array, assistant_message:array}
     */
    public static function sendWithTools(array $messages, string $system, array $tools, int $maxTokens = 1024): array
    {
        return match (self::provider()) {
            'azure_openai' => AzureOpenAIClient::sendWithTools($messages, $system, $tools, $maxTokens),
            'anthropic' => ClaudeClient::sendWithTools($messages, $system, $tools, $maxTokens),
            default => throw new \RuntimeException('Ningún proveedor de LLM está configurado.'),
        };
    }

    /**
     * Convierte los resultados de las herramientas en mensajes que el proveedor entienda.
     * OpenAI quiere un mensaje por herramienta; Anthropic quiere uno solo con varios
     * bloques. El llamador no necesita saberlo.
     *
     * @param array $results [['id'=>string, 'content'=>string], ...]
     */
    public static function toolResultMessages(array $results): array
    {
        return match (self::provider()) {
            'azure_openai' => AzureOpenAIClient::toolResultMessages($results),
            'anthropic' => ClaudeClient::toolResultMessages($results),
            default => throw new \RuntimeException('Ningún proveedor de LLM está configurado.'),
        };
    }

    /**
     * Igual que send(), pero exige que la respuesta cumpla un JSON Schema.
     * Cada proveedor lo consigue con un mecanismo distinto (OpenAI: response_format;
     * Anthropic: herramienta forzada) — la fachada esconde esa diferencia.
     *
     * @return array|null Objeto decodificado, o null si el modelo no produjo JSON válido.
     */
    public static function sendStructured(
        array $messages,
        string $system,
        array $schema,
        string $schemaName,
        int $maxTokens = 1024
    ): ?array {
        return match (self::provider()) {
            'azure_openai' => AzureOpenAIClient::sendStructured($messages, $system, $schema, $schemaName, $maxTokens),
            'anthropic' => ClaudeClient::sendStructured($messages, $system, $schema, $schemaName, $maxTokens),
            default => throw new \RuntimeException('Ningún proveedor de LLM está configurado.'),
        };
    }
}
