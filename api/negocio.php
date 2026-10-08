<?php
/**
 * Datos del negocio para el asistente IA (server-side, no editable por el visitante).
 *
 * IMPORTANTE: si cambian los datos de contacto o las propiedades en
 * script/script.js (window.GP), replicar el cambio acá para que el asistente
 * no responda con información vieja.
 */

if (!defined('CHAT_API_ENTRY')) {
    http_response_code(403);
    exit;
}

function negocio_datos(): array
{
    if (!defined('PROPS_ENTRY')) define('PROPS_ENTRY', true);
    require_once __DIR__ . '/propiedades_store.php';

    // Solo propiedades publicadas (con fotos y activas) — igual criterio que usa la web.
    $propiedades = array_values(array_filter(propiedades_cargar(), function ($p) {
        return !empty($p['fotos']) && ($p['activa'] ?? true) !== false;
    }));
    $propiedades = array_map(function ($p) {
        return [
            'id' => $p['id'] ?? '',
            'tipo' => $p['tipo'] ?? '',
            'operacion' => $p['operacion'] ?? '',
            'zona' => $p['zona'] ?? '',
            'direccion' => $p['direccion'] ?? '',
            'precio' => $p['precio'] ?? '',
            'm2' => $p['m2'] ?? 0,
            'dorm' => $p['dorm'] ?? 0,
            'banos' => $p['banos'] ?? 0,
            'amb' => $p['amb'] ?? 0,
        ];
    }, $propiedades);

    return [
        'empresa' => [
            'nombre' => 'Marcelo Ragonese Propiedades',
            'direccion' => 'Bolívar 699, Ramos Mejía',
            'telefono' => '+54 9 11 4673 8707',
            'whatsapp' => '5491146738707',
            'email' => 'marceloragonesepropiedades@gmail.com',
            'matricula' => 'Colegio Martillero de La Matanza · Matrícula 070 - 1160',
            'horario' => 'Lunes a viernes 9 a 18h · Sábados 9 a 13h',
            'zonas' => 'Ramos Mejía, Caseros, San Martín, Francisco Álvarez y toda la Zona Oeste',
        ],
        'servicios' => [
            'Compra',
            'Venta',
            'Tasaciones (sin costo ni compromiso)',
            'Alquileres',
            'Administración de propiedades',
        ],
        // Se genera desde api/data/propiedades.json — misma fuente que la web y el panel.
        'propiedades' => $propiedades,
    ];
}
