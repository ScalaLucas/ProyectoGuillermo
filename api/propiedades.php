<?php
/**
 * Endpoint público de solo lectura: devuelve el listado de propiedades.
 * Es la MISMA fuente que usa el asistente de IA (negocio.php) y el panel
 * de administración (admin/), para que nunca queden datos desincronizados.
 */

define('PROPS_ENTRY', true);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

require_once __DIR__ . '/propiedades_store.php';

$publicas = array_values(array_filter(propiedades_cargar(), function ($p) {
    return ($p['activa'] ?? true) !== false;
}));

// "interno" (agente, datos del propietario) es uso exclusivo del panel:
// nunca debe llegar al navegador del visitante.
$publicas = array_map(function ($p) {
    unset($p['interno']);
    return $p;
}, $publicas);

echo json_encode($publicas, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
