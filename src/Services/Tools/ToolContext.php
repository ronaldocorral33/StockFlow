<?php
namespace App\Services\Tools;

if (!defined('APP_BOOTSTRAP')) { http_response_code(403); exit('Forbidden'); }

/**
 * Contexto de ejecución de una herramienta.
 *
 * ESTA CLASE ES UNA GARANTÍA DE SEGURIDAD, no una comodidad.
 *
 * Los ARGUMENTOS de una herramienta vienen del modelo (son texto que un LLM inventó,
 * potencialmente influido por lo que escribió el usuario). El CONTEXTO viene de la
 * sesión autenticada del servidor.
 *
 * Al separarlos en dos canales distintos, el modelo nunca puede decidir sobre qué
 * negocio opera: aunque alguien logre convencerlo de emitir business_id = 7, ese valor
 * viajaría por el canal de argumentos y sería ignorado, porque las herramientas leen
 * el negocio de aquí y solo de aquí.
 *
 * Regla práctica: si un dato define QUIÉN eres o QUÉ PUEDES ver, va en el contexto.
 * Si define QUÉ QUIERES saber, puede venir en los argumentos.
 */
final class ToolContext
{
    public function __construct(
        public readonly int $businessId,
        public readonly int $userId,
        /**
         * Fecha de referencia (Y-m-d) para resolver periodos relativos.
         *
         * Va en el CONTEXTO y no en los argumentos por la misma razón que business_id:
         * "hoy" no es algo que el modelo deba decidir. Un LLM no sabe qué día es, y si
         * se le deja adivinar usa la fecha de su corte de entrenamiento — un error
         * silencioso, porque la consulta corre bien y responde por el mes equivocado.
         *
         * Es inyectable para que las pruebas de "este mes" sean deterministas: null
         * significa el día de hoy real.
         */
        public readonly ?string $today = null,
    ) {
    }
}
