<?php
namespace App\Services\Agent;

if (!defined('APP_BOOTSTRAP')) { http_response_code(403); exit('Forbidden'); }

/**
 * Resuelve periodos en PHP, no en el modelo.
 *
 * POR QUÉ EXISTE
 * Un LLM no sabe qué día es hoy. Nada en el prompt se lo decía, así que ante "este mes"
 * o "últimos 30 días" solo podía adivinar — normalmente con la fecha de su corte de
 * entrenamiento, que puede estar a meses de distancia. Es un error silencioso: la
 * consulta se ejecuta sin fallar y devuelve el periodo equivocado.
 *
 * La auditoría confirmó que con fechas ABSOLUTAS ("agosto de 2026") el modelo ya
 * acertaba. Esta clase no toca ese camino: solo cubre las relativas, y le entrega a las
 * herramientas un par de fechas ya calculado.
 *
 * CONVENCIÓN DE INTERVALO
 * Se devuelve [inicio, fin) — fin EXCLUSIVO. Es lo que ya generaba el modelo
 * (`>= '2026-08-01' AND < '2026-09-01'`) y evita el error clásico de perder el último
 * día cuando la columna lleva hora.
 */
class DateResolver
{
    /** Periodos relativos reconocidos. Las claves son las que se le ofrecen al modelo. */
    public const PERIODS = [
        'hoy', 'ayer', 'esta_semana', 'semana_pasada', 'este_mes', 'mes_pasado',
        'ultimos_7_dias', 'ultimos_30_dias', 'ultimos_90_dias',
        'este_trimestre', 'trimestre_pasado', 'este_ano', 'ano_pasado',
    ];

    /**
     * @param string $period Una de PERIODS.
     * @param string|null $today Fecha de referencia (Y-m-d). Inyectable para pruebas
     *        deterministas: sin esto, una prueba de "este mes" fallaría al cambiar el mes.
     * @return array{inicio:string, fin:string, etiqueta:string}|null null si no se reconoce.
     */
    public static function resolve(string $period, ?string $today = null): ?array
    {
        $hoy = new \DateTimeImmutable($today ?? 'today');

        switch ($period) {
            case 'hoy':
                return self::span($hoy, $hoy->modify('+1 day'), 'hoy');

            case 'ayer':
                $a = $hoy->modify('-1 day');
                return self::span($a, $hoy, 'ayer');

            // La semana arranca en lunes: es la convención de negocio en México y la
            // que usa ISO-8601. 'monday this week' ya lo resuelve así en PHP.
            case 'esta_semana':
                $ini = $hoy->modify('monday this week');
                return self::span($ini, $ini->modify('+7 days'), 'esta semana');

            case 'semana_pasada':
                $ini = $hoy->modify('monday last week');
                return self::span($ini, $ini->modify('+7 days'), 'la semana pasada');

            case 'este_mes':
                $ini = $hoy->modify('first day of this month');
                return self::span($ini, $ini->modify('+1 month'), 'este mes');

            case 'mes_pasado':
                $ini = $hoy->modify('first day of last month');
                return self::span($ini, $ini->modify('+1 month'), 'el mes pasado');

            // "Últimos N días" incluye hoy: si hoy es el 30 y pides 7 días, el rango es
            // 24–30, no 23–29. Es lo que la gente espera al preguntar.
            case 'ultimos_7_dias':
                return self::span($hoy->modify('-6 days'), $hoy->modify('+1 day'), 'los últimos 7 días');

            case 'ultimos_30_dias':
                return self::span($hoy->modify('-29 days'), $hoy->modify('+1 day'), 'los últimos 30 días');

            case 'ultimos_90_dias':
                return self::span($hoy->modify('-89 days'), $hoy->modify('+1 day'), 'los últimos 90 días');

            case 'este_trimestre':
                $ini = self::quarterStart($hoy);
                return self::span($ini, $ini->modify('+3 months'), 'este trimestre');

            case 'trimestre_pasado':
                $ini = self::quarterStart($hoy)->modify('-3 months');
                return self::span($ini, $ini->modify('+3 months'), 'el trimestre pasado');

            case 'este_ano':
                $ini = $hoy->modify('first day of January this year');
                return self::span($ini, $ini->modify('+1 year'), 'este año');

            case 'ano_pasado':
                $ini = $hoy->modify('first day of January last year');
                return self::span($ini, $ini->modify('+1 year'), 'el año pasado');
        }

        return null;
    }

    /**
     * Normaliza el periodo que mandó el modelo: acepta un periodo relativo por nombre
     * o un par de fechas absolutas, y siempre devuelve el mismo formato.
     *
     * Así las herramientas tienen UN solo contrato de fechas, sin importar si el usuario
     * dijo "agosto de 2026" (el modelo manda las fechas) o "este mes" (el modelo manda
     * el nombre del periodo y PHP lo calcula).
     */
    public static function normalize(
        ?string $period,
        ?string $inicio,
        ?string $fin,
        ?string $today = null
    ): array {
        if ($period !== null && $period !== '') {
            $r = self::resolve($period, $today);
            if ($r !== null) {
                return $r + ['ok' => true];
            }
            return [
                'ok' => false,
                'error' => 'Periodo no reconocido: "' . $period . '". Válidos: '
                    . implode(', ', self::PERIODS) . '. O manda fecha_inicio y fecha_fin.',
            ];
        }

        $i = self::parseDate($inicio);
        $f = self::parseDate($fin);

        if ($i === null && $f === null) {
            // Sin periodo: TODO el historial. Es un default honesto — mejor que
            // inventar "este mes" y responder sobre un rango que nadie pidió.
            return ['ok' => true, 'inicio' => null, 'fin' => null, 'etiqueta' => 'todo el historial'];
        }
        if ($i === null || $f === null) {
            return ['ok' => false, 'error' => 'Manda fecha_inicio y fecha_fin juntas, o un periodo relativo.'];
        }
        if ($f <= $i) {
            return ['ok' => false, 'error' => 'fecha_fin debe ser posterior a fecha_inicio.'];
        }

        return [
            'ok' => true,
            'inicio' => $i->format('Y-m-d'),
            'fin' => $f->format('Y-m-d'),
            'etiqueta' => 'del ' . $i->format('Y-m-d') . ' al ' . $f->modify('-1 day')->format('Y-m-d'),
        ];
    }

    /** Fecha de hoy, para inyectarla en el prompt. Sin esto el modelo adivina. */
    public static function todayContext(?string $today = null): string
    {
        $hoy = new \DateTimeImmutable($today ?? 'today');
        static $meses = [
            1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
            'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre',
        ];
        return sprintf(
            'HOY es %s (%s de %s de %s). Úsalo para cualquier referencia temporal relativa. '
            . 'Para periodos como "este mes" o "últimos 30 días" NO calcules las fechas: '
            . 'manda el nombre del periodo y el sistema las resuelve.',
            $hoy->format('Y-m-d'),
            $hoy->format('j'),
            $meses[(int)$hoy->format('n')],
            $hoy->format('Y')
        );
    }

    // ---------------------------------------------------------------

    private static function span(\DateTimeImmutable $i, \DateTimeImmutable $f, string $etiqueta): array
    {
        return [
            'inicio' => $i->format('Y-m-d'),
            'fin' => $f->format('Y-m-d'),
            'etiqueta' => $etiqueta,
        ];
    }

    private static function quarterStart(\DateTimeImmutable $d): \DateTimeImmutable
    {
        $mes = (int)$d->format('n');
        $primero = 3 * intdiv($mes - 1, 3) + 1; // 1, 4, 7 o 10
        return $d->setDate((int)$d->format('Y'), $primero, 1);
    }

    /** Solo acepta Y-m-d estricto: un formato ambiguo es peor que un error claro. */
    private static function parseDate(?string $s): ?\DateTimeImmutable
    {
        if ($s === null || trim($s) === '') {
            return null;
        }
        $s = trim($s);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $s);
        if ($d === false) {
            return null;
        }
        // createFromFormat acepta 2026-02-31 y lo rueda a marzo. Se rechaza.
        return $d->format('Y-m-d') === $s ? $d->setTime(0, 0) : null;
    }
}
