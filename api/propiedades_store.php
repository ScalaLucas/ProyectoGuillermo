<?php
/**
 * Lectura/escritura de api/data/propiedades.json — fuente única de propiedades
 * para la web (api/propiedades.php), el asistente de IA (negocio.php) y el
 * panel de administración (admin/).
 */

if (!defined('PROPS_ENTRY')) {
    http_response_code(403);
    exit;
}

define('PROPIEDADES_JSON_PATH', __DIR__ . '/data/propiedades.json');

/**
 * Catálogo de características de tipo Sí/No, agrupadas igual que se muestran
 * en la ficha de la propiedad. Es la MISMA lista que usa el panel (para
 * dibujar los casilleros) y el guardado (para armar el JSON de la propiedad),
 * así nunca quedan desincronizados.
 */
const CARAC_GRUPOS_BOOL = [
    'Principales' => ['Amoblado', 'Admite mascotas', 'Apto crédito', 'Apto profesional', 'En barrio cerrado'],
    'Comodidades y equipamiento' => ['Ascensor', 'Lavandería', 'Rampa para silla de ruedas', 'Grupo electrógeno', 'Gimnasio', 'Salón de usos múltiples', 'Acceso a internet'],
    'Seguridad' => ['Seguridad'],
    'Operación' => ['Permuta', 'Escritura inmediata', 'Potencial alto para alquilar'],
    'Ambientes' => ['Living', 'Comedor', 'Patio', 'Jardín', 'Terraza', 'Balcón', 'Toilette', 'Vestidor', 'Dormitorio en suite', 'Cocina', 'Con lavadero', 'Pileta', 'Parrilla'],
    'Servicios' => ['Agua corriente', 'Gas natural', 'Cloaca', 'Electricidad', 'Pavimento', 'Alumbrado público', 'Aire acondicionado', 'Calefacción', 'Caldera', 'TV por cable', 'Con conexión para lavarropas'],
];

function propiedades_cargar(): array
{
    $raw = @file_get_contents(PROPIEDADES_JSON_PATH);
    if ($raw === false) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function propiedades_guardar(array $props): bool
{
    $dir = dirname(PROPIEDADES_JSON_PATH);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $json = json_encode(array_values($props), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    return @file_put_contents(PROPIEDADES_JSON_PATH, $json, LOCK_EX) !== false;
}

function propiedades_encontrar(string $id): ?array
{
    foreach (propiedades_cargar() as $p) {
        if (($p['id'] ?? '') === $id) return $p;
    }
    return null;
}

function propiedades_siguiente_id(): string
{
    $existentes = array_column(propiedades_cargar(), 'id');
    do {
        $id = 'LP' . random_int(100000, 999999);
    } while (in_array($id, $existentes, true));
    return $id;
}
