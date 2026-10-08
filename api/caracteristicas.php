<?php
/**
 * Endpoint público de solo lectura: el catálogo de características Sí/No
 * (el mismo que usa el panel de administración para dibujar los casilleros),
 * para que los filtros de la web siempre muestren exactamente las mismas
 * opciones que se pueden cargar en una propiedad — sin mantener dos listas.
 */

define('PROPS_ENTRY', true);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

require_once __DIR__ . '/propiedades_store.php';

echo json_encode(CARAC_GRUPOS_BOOL, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
