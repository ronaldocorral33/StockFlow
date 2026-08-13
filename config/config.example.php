<?php
if (!defined('APP_BOOTSTRAP')) { http_response_code(403); exit('Forbidden'); }

/**
 * Plantilla de configuración — copia este archivo a config.php y llena tus propios
 * valores. config.php está en .gitignore a propósito: nunca subas ese archivo a git,
 * solo esta plantilla sin secretos reales.
 */

return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => '3306',
        'name' => 'control_inventario',
        'user' => 'root',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],
    // Conexión de solo lectura usada exclusivamente por el chatbot (defensa en profundidad).
    'db_chatbot_ro' => [
        'host' => '127.0.0.1',
        'port' => '3306',
        'name' => 'control_inventario',
        'user' => 'chatbot_ro',
        'pass' => 'CAMBIA-ESTA-CONTRASEÑA',
        'charset' => 'utf8mb4',
    ],
    'session' => [
        'name' => 'ci_session',
    ],
    // Proveedor de LLM que usa el chatbot: 'azure_openai' o 'anthropic'.
    // Si lo dejas en 'auto', usa el primero de los dos de abajo que tenga api_key llena.
    'llm_provider' => 'auto',
    'anthropic' => [
        // Coloca aquí tu API key de Anthropic (https://console.anthropic.com/).
        'api_key' => getenv('ANTHROPIC_API_KEY') ?: '',
        'model' => 'claude-haiku-4-5',
        'api_url' => 'https://api.anthropic.com/v1/messages',
        'api_version' => '2023-06-01',
    ],
    'azure_openai' => [
        // Datos de tu recurso de Azure OpenAI (endpoint compatible con la API de OpenAI: .../openai/v1).
        'api_key' => getenv('AZURE_OPENAI_API_KEY') ?: '',
        // Ej. "https://TU-RECURSO.openai.azure.com/openai/v1" (sin slash final).
        'endpoint' => getenv('AZURE_OPENAI_ENDPOINT') ?: '',
        // El nombre de tu deployment en Azure AI Foundry (ej. "gpt-5.4"), se manda como "model".
        'deployment' => getenv('AZURE_OPENAI_DEPLOYMENT') ?: '',
    ],
    'app' => [
        'name' => 'StockFlow',
        'default_currency' => 'MXN',
    ],
];
