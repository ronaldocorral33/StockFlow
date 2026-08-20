<?php
if (!defined('APP_BOOTSTRAP')) { http_response_code(403); exit('Forbidden'); }

/**
 * Espejo en PHP del set de iconos de assets/js/icons.js, para páginas renderizadas
 * en servidor (login/register/onboarding/settings/dashboard shell). Mismo grosor de
 * trazo y viewBox para que ambos lados sean visualmente idénticos.
 */
const ICON_PATHS = [
    'package' => '<path d="M21 8.5 12 4 3 8.5 12 13l9-4.5Z"/><path d="M3 8.5V16l9 4.5 9-4.5V8.5"/><path d="M12 13v7.5"/>',
    'inbox' => '<path d="M4 12h4l1.5 3h5L16 12h4"/><rect x="3" y="6" width="18" height="14" rx="2"/><path d="M3 12 6 6h12l3 6"/>',
    'outbox' => '<path d="M12 3v10m0 0-3.5-3.5M12 13l3.5-3.5"/><path d="M4 13v6a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-6"/>',
    'chart' => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
    'chat' => '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5Z"/>',
    // Controles deslizantes: el gesto estándar de "elegir qué columnas ver".
    'filter' => '<path d="M4 5h16l-6 7v6l-4 2v-8L4 5Z"/>',
    'sliders' => '<path d="M4 6h16M4 12h16M4 18h16"/><circle cx="9" cy="6" r="2"/><circle cx="15" cy="12" r="2"/><circle cx="8" cy="18" r="2"/>',
    'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z"/>',
    'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
    'upload' => '<path d="M12 16V4m0 0L7 9m5-5 5 5"/><path d="M4 16v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/>',
    'download' => '<path d="M12 4v12m0 0-5-5m5 5 5-5"/><path d="M4 16v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/>',
    'edit' => '<path d="M11 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-5"/><path d="M18.4 3.6a1.98 1.98 0 1 1 2.8 2.8L11 16.7l-4 1 1-4Z"/>',
    'trash' => '<path d="M4 7h16M9 7V4.8c0-.44.36-.8.8-.8h4.4c.44 0 .8.36.8.8V7m-9 0 .8 12.2c.05.98.86 1.8 1.85 1.8h6.7c.99 0 1.8-.82 1.85-1.8L18 7"/>',
    'dollar' => '<path d="M12 2v20M17 6.5C17 4.6 14.8 3 12 3S7 4.6 7 6.5 9.2 10 12 10s5 1.4 5 3.5-2.2 3.5-5 3.5-5-1.6-5-3.5"/>',
    'undo' => '<path d="M4 8v6h6"/><path d="M4.5 14A8 8 0 1 0 6 6.3L4 8"/>',
    'plus' => '<path d="M12 5v14M5 12h14"/>',
    'search' => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
    'x' => '<path d="M18 6 6 18M6 6l12 12"/>',
    'check' => '<path d="M20 6 9 17l-5-5"/>',
    'alertCircle' => '<circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/>',
    'trendUp' => '<path d="M3 17 9 11l4 4 8-8"/><path d="M15 7h6v6"/>',
    'chevronUp' => '<path d="m18 15-6-6-6 6"/>',
    'chevronDown' => '<path d="m6 9 6 6 6-6"/>',
    'building' => '<path d="M4 21V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v17"/><path d="M15 21V9a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v12"/><path d="M9 8h.01M9 12h.01M9 16h.01M4 21h17"/>',
    'sparkle' => '<path d="M12 3v4M12 17v4M3 12h4M17 12h4M6 6l2.5 2.5M15.5 15.5 18 18M18 6l-2.5 2.5M8.5 15.5 6 18"/>',
];

function icon(string $name, int $size = 18): string
{
    $body = ICON_PATHS[$name] ?? ICON_PATHS['alertCircle'];
    return sprintf(
        '<svg width="%1$d" height="%1$d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="icon" aria-hidden="true">%2$s</svg>',
        $size,
        $body
    );
}
