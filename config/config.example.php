<?php
if (!defined('APP_BOOTSTRAP')) { http_response_code(403); exit('Forbidden'); }

use App\Support\Env;

/**
 * Plantilla de configuracion — copia este archivo a config.php tal cual.
 *
 * Ya NO hay que editarlo: todos los valores salen de Env::get(), que prefiere una
 * variable de entorno real del servidor y cae al archivo .env de la raiz. Copia
 * .env.example a .env y llena ahi tus credenciales.
 *
 * Esta plantilla debe ser IDENTICA a config.php salvo este encabezado. Si se
 * separan, una instalacion nueva copia una configuracion que ignora el .env y falla
 * de una forma dificil de diagnosticar.
 */

return [
    'db' => [
        'host' => Env::get('DB_HOST', '127.0.0.1'),
        'port' => Env::get('DB_PORT', '3306'),
        'name' => Env::get('DB_NAME', 'control_inventario'),
        'user' => Env::get('DB_USER', 'root'),
        'pass' => Env::get('DB_PASS', ''),
        'charset' => 'utf8mb4',
    ],
    // Conexión de solo lectura usada exclusivamente por el chatbot (defensa en profundidad).
    'db_chatbot_ro' => [
        'host' => Env::get('DB_HOST', '127.0.0.1'),
        'port' => Env::get('DB_PORT', '3306'),
        'name' => Env::get('DB_NAME', 'control_inventario'),
        'user' => Env::get('DB_CHATBOT_USER', 'chatbot_ro'),
        'pass' => Env::get('DB_CHATBOT_PASS', ''),
        'charset' => 'utf8mb4',
    ],
    'session' => [
        'name' => 'ci_session',
    ],
    // Proveedor de LLM que usa el chatbot: 'azure_openai', 'anthropic' o 'auto'.
    'llm_provider' => Env::get('LLM_PROVIDER', 'auto'),
    'anthropic' => [
        'api_key' => Env::get('ANTHROPIC_API_KEY', ''),
        'model' => Env::get('ANTHROPIC_MODEL', 'claude-haiku-4-5'),
        'api_url' => 'https://api.anthropic.com/v1/messages',
        'api_version' => '2023-06-01',
    ],
    'azure_openai' => [
        'api_key' => Env::get('AZURE_OPENAI_API_KEY', ''),
        // Ej. "https://TU-RECURSO.openai.azure.com/openai/v1" (sin slash final).
        'endpoint' => Env::get('AZURE_OPENAI_ENDPOINT', ''),
        // Nombre del deployment en Azure AI Foundry (ej. "gpt-5.4"), se manda como "model".
        'deployment' => Env::get('AZURE_OPENAI_DEPLOYMENT', ''),
    ],
    'app' => [
        'name' => 'StockFlow',
        'default_currency' => 'MXN',
        // 'development' o 'production'. El valor por omisión es el seguro: si el
        // servidor no define nada, la aplicación se comporta como producción y no
        // imprime errores en pantalla. Ver el bloque de errores en bootstrap.php.
        'env' => Env::get('APP_ENV', 'production'),
        'debug' => Env::get('APP_ENV', 'production') === 'development',
    ],
];
