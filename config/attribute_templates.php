<?php
if (!defined('APP_BOOTSTRAP')) { http_response_code(403); exit('Forbidden'); }

/**
 * Plantillas de campos personalizados sugeridos al hacer onboarding.
 * Son datos fijos (no cambian en tiempo de ejecución), por eso viven en un
 * archivo de configuración en vez de una tabla en la base de datos.
 */
return [
    'jerseys' => [
        'label' => 'Jerseys / Ropa deportiva',
        'description' => 'Equipo, liga, versión, temporada y talla — como el control de jerseys original.',
        'fields' => [
            ['field_key' => 'liga', 'label' => 'Liga', 'field_type' => 'text', 'show_in_table' => true],
            ['field_key' => 'deporte', 'label' => 'Deporte', 'field_type' => 'text', 'show_in_table' => true],
            ['field_key' => 'version', 'label' => 'Versión', 'field_type' => 'select', 'options' => ['Local', 'Visita', 'Tercera', 'Retro', 'Mujer'], 'show_in_table' => true],
            ['field_key' => 'temporada', 'label' => 'Temporada', 'field_type' => 'text', 'show_in_table' => false],
            ['field_key' => 'talla', 'label' => 'Talla', 'field_type' => 'select', 'options' => ['XS', 'S', 'M', 'L', 'XL', '2XL'], 'show_in_table' => true],
        ],
    ],
    'ropa_calzado' => [
        'label' => 'Ropa / Calzado',
        'description' => 'Marca, color, talla y material — para tiendas de ropa o zapatos.',
        'fields' => [
            ['field_key' => 'marca', 'label' => 'Marca', 'field_type' => 'text', 'show_in_table' => true],
            ['field_key' => 'color', 'label' => 'Color', 'field_type' => 'text', 'show_in_table' => true],
            ['field_key' => 'talla', 'label' => 'Talla', 'field_type' => 'text', 'show_in_table' => true],
            ['field_key' => 'material', 'label' => 'Material', 'field_type' => 'text', 'show_in_table' => false],
        ],
    ],
    'generico' => [
        'label' => 'Genérico en blanco',
        'description' => 'Empieza sin campos personalizados y agrégalos tú mismo después.',
        'fields' => [],
    ],
];
