<?php
namespace App;

if (!defined('APP_BOOTSTRAP')) { http_response_code(403); exit('Forbidden'); }

use PDO;
use PDOException;

class Database
{
    private static ?PDO $connection = null;
    private static ?PDO $chatbotReadOnly = null;

    public static function connection(): PDO
    {
        if (self::$connection === null) {
            self::$connection = self::connect(APP_CONFIG['db']);
        }
        return self::$connection;
    }

    /** Conexión de solo lectura (usuario chatbot_ro) para el chatbot SQL. */
    public static function chatbotReadOnly(): PDO
    {
        if (self::$chatbotReadOnly === null) {
            self::$chatbotReadOnly = self::connect(APP_CONFIG['db_chatbot_ro']);
        }
        return self::$chatbotReadOnly;
    }

    private static function connect(array $cfg): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $cfg['host'],
            $cfg['port'],
            $cfg['name'],
            $cfg['charset']
        );
        try {
            return new PDO($dsn, $cfg['user'], $cfg['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            error_log('DB connection failed: ' . $e->getMessage());
            http_response_code(500);
            exit('No se pudo conectar a la base de datos.');
        }
    }
}
