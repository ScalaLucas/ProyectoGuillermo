<?php
/**
 * Endpoint público de solo lectura: devuelve el listado de propiedades.
 * Es la MISMA fuente que usa el asistente de IA (negocio.php) y el panel
 * de administración (admin/), para que nunca queden datos desincronizados.
 */

define('PROPS_ENTRY', true);

require_once __DIR__ . '/propiedades_store.php';

// --- Marca de agua en las fotos de la web ---
// Las fotos originales NO se tocan (el panel y Mercado Libre siguen usándolas
// tal cual). La web pide las fotos por acá: la primera vez se genera la copia
// con el logo y queda guardada en api/data/wm-cache/. Cualquier foto nueva que
// se suba desde el panel la recibe sola. Si algo falla, se sirve la original.
const WM_VERSION = 'v1';      // subir si se cambia el estilo del logo: se regeneran todas
const WM_ANCHO = 0.46;           // ancho del logo respecto al ancho de la foto
const WM_OPACIDAD = 0.30;        // 0 a 1
const WM_SOMBRA = 0.14;          // sombra suave para que se vea también sobre fondos claros

function wm_ruta_original(string $archivo): ?string
{
    if (!preg_match('/^[A-Za-z0-9._-]+.(webp|jpe?g|png)$/i', $archivo)) return null;
    $ruta = __DIR__ . '/../assets/propiedades/' . $archivo;
    return is_file($ruta) ? $ruta : null;
}

function wm_capa_logo($logo, int $ancho, float $op, array $rgb)
{
    $w = imagesx($logo); $h = imagesy($logo); $alto = max(1, (int)round($h * $ancho / $w));
    $c = imagecreatetruecolor($ancho, $alto);
    imagealphablending($c, false); imagesavealpha($c, true);
    imagefill($c, 0, 0, 127 << 24);
    imagecopyresampled($c, $logo, 0, 0, 0, 0, $ancho, $alto, $w, $h);
    $base = ($rgb[0] << 16) | ($rgb[1] << 8) | $rgb[2];
    for ($y = 0; $y < $alto; $y++) {
        for ($x = 0; $x < $ancho; $x++) {
            $a = (imagecolorat($c, $x, $y) >> 24) & 127;
            if ($a === 127) continue;
            imagesetpixel($c, $x, $y, ((int)round(127 - (127 - $a) * $op) << 24) | $base);
        }
    }
    return $c;
}

function wm_generar(string $orig, string $ext, string $destino): bool
{
    $logoRuta = __DIR__ . '/../assets/logo.png';
    if (!function_exists('imagecreatetruecolor') || !is_file($logoRuta)) return false;
    $img = $ext === 'webp' ? (function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($orig) : false)
        : ($ext === 'png' ? @imagecreatefrompng($orig) : @imagecreatefromjpeg($orig));
    $logo = @imagecreatefrompng($logoRuta);
    if (!$img || !$logo) return false;
    imagealphablending($logo, false); imagesavealpha($logo, true);
    $W = imagesx($img); $H = imagesy($img);
    $ancho = max(40, (int)round($W * WM_ANCHO));
    $sombra = wm_capa_logo($logo, $ancho, WM_SOMBRA, [0, 0, 0]);
    $blanco = wm_capa_logo($logo, $ancho, WM_OPACIDAD, [255, 255, 255]);
    $x = (int)(($W - $ancho) / 2); $y = (int)(($H - imagesy($blanco)) / 2);
    $off = max(1, (int)round($W / 700));
    imagealphablending($img, true);
    imagecopy($img, $sombra, $x + $off, $y + $off, 0, 0, imagesx($sombra), imagesy($sombra));
    imagecopy($img, $blanco, $x, $y, 0, 0, imagesx($blanco), imagesy($blanco));
    $tmp = $destino . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $ok = $ext === 'webp' ? (function_exists('imagewebp') && imagewebp($img, $tmp, 86))
        : ($ext === 'png' ? imagepng($img, $tmp, 6) : imagejpeg($img, $tmp, 88));
    if ($ok && @rename($tmp, $destino)) return true;
    @unlink($tmp);
    return false;
}

function wm_dir_web(): string { return __DIR__ . '/../assets/wm-' . WM_VERSION; }

// Cada foto con marca queda como archivo estático en assets/wm-<versión>/ (lo sirve el hosting directo,
// rápido y con caché). Esta función la genera la primera vez que alguien la pide.
function wm_servir(string $archivo): void
{
    $orig = wm_ruta_original($archivo);
    if (!$orig) { http_response_code(404); exit; }
    $ext = strtolower(pathinfo($archivo, PATHINFO_EXTENSION));
    $mime = $ext === 'webp' ? 'image/webp' : ($ext === 'png' ? 'image/png' : 'image/jpeg');
    $dir = wm_dir_web();
    $web = $dir . '/' . $archivo;
    $usar = $orig;
    try {
        if (!is_file($web)) {
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            if (is_dir($dir) && is_writable($dir)) {
                // copia de la primera versión de caché si existe (evita regenerar)
                $viejo = __DIR__ . '/data/wm-cache/' . md5('v1' . $archivo . filemtime($orig) . filesize($orig) . @filemtime(__DIR__ . '/../assets/logo.png')) . '.' . $ext;
                if (WM_VERSION !== 'v1' || !is_file($viejo) || !@copy($viejo, $web)) wm_generar($orig, $ext, $web);
            }
        }
        if (is_file($web)) $usar = $web;
    } catch (Throwable $e) { $usar = $orig; }
    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=2592000');
    header('Content-Length: ' . filesize($usar));
    readfile($usar);
    exit;
}

if (isset($_GET['foto'])) wm_servir(basename((string)$_GET['foto']));

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');


$publicas = array_values(array_filter(propiedades_cargar(), function ($p) {
    return ($p['activa'] ?? true) !== false;
}));

// "interno" (agente, datos del propietario) es uso exclusivo del panel:
// nunca debe llegar al navegador del visitante.
// Las fotos se publican con marca de agua (ver wm_servir); el JSON guardado conserva las rutas originales.
$publicas = array_map(function ($p) {
    unset($p['interno']);
    if (!empty($p['fotos']) && is_array($p['fotos'])) {
        $p['fotos'] = array_map(function ($f) {
            if (!is_string($f) || strpos($f, 'assets/propiedades/') !== 0) return $f;
            $archivo = basename($f);
            if (!preg_match('/^[A-Za-z0-9._-]+.(webp|jpe?g|png)$/i', $archivo)) return $f;
            if (is_file(wm_dir_web() . '/' . $archivo)) return 'assets/wm-' . WM_VERSION . '/' . rawurlencode($archivo);
            if (!wm_ruta_original($archivo)) return $f;
            return 'api/propiedades.php?foto=' . rawurlencode($archivo);
        }, $p['fotos']);
    }
    return $p;
}, $publicas);

echo json_encode($publicas, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
