<?php
/**
 * Geocodificación de direcciones (dirección de texto -> latitud/longitud
 * reales) usando Nominatim (OpenStreetMap), que es gratuito. Con la
 * ubicación exacta —no solo la zona— Mercado Libre puede mostrar en la
 * publicación el mapa y los lugares cercanos (transporte, escuelas, etc.),
 * y nuestro propio mapa en la ficha de la propiedad queda más preciso.
 */

if (!defined('CHAT_API_ENTRY')) {
    http_response_code(403);
    exit;
}

function geocodificar_direccion(string $direccion, string $zona): ?array
{
    $consulta = trim($direccion . ', ' . $zona . ', Buenos Aires, Argentina');
    $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
        'format' => 'json',
        'limit' => 1,
        'countrycodes' => 'ar',
        'q' => $consulta,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        // Nominatim exige un User-Agent identificable, si no bloquea las consultas.
        CURLOPT_HTTPHEADER => ['User-Agent: MarceloRagonesePropiedades/1.0 (marceloragonesepropiedades@gmail.com)'],
    ]);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status !== 200 || !$raw) return null;
    $datos = json_decode($raw, true);
    if (!is_array($datos) || empty($datos[0]['lat']) || empty($datos[0]['lon'])) return null;

    return ['lat' => (float)$datos[0]['lat'], 'lng' => (float)$datos[0]['lon']];
}
