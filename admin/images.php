<?php
/**
 * Procesa fotos subidas por el panel: valida que sean imágenes reales
 * (getimagesize, no solo la extensión), las re-codifica a .webp con GD
 * -lo que además elimina cualquier dato "de más" escondido en el archivo
 * original- y las guarda en assets/propiedades/ con un nombre generado
 * por el servidor.
 */

define('ADMIN_IMG_MAX_BYTES', 12 * 1024 * 1024); // 12 MB por foto
define('ADMIN_IMG_MAX_DIM', 2048); // lado más largo, en px
// Calidad del .webp que se guarda y se manda a Mercado Libre. Se subió de 82
// a 92 porque Mercado Libre vuelve a comprimir la imagen de su lado (la pasa
// a .jpg en su CDN) — partir de una fuente más nítida reduce esa pérdida
// doble. El archivo pesa un poco más, pero sigue siendo liviano para la web.
define('ADMIN_IMG_CALIDAD', 92);

/**
 * @param array $file  Un elemento de $_FILES (ya individual, no el array multi-file crudo)
 * @return string|null Ruta relativa (assets/propiedades/xxx.webp) o null si falló/no era imagen
 */
function admin_procesar_foto(array $file, string $idSlug, int $indice): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
    if (($file['size'] ?? 0) <= 0 || $file['size'] > ADMIN_IMG_MAX_BYTES) return null;
    if (!is_uploaded_file($file['tmp_name'])) return null;

    $info = @getimagesize($file['tmp_name']);
    if (!$info) return null; // no es una imagen válida

    $tipo = $info[2];
    $src = null;
    switch ($tipo) {
        case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($file['tmp_name']); break;
        case IMAGETYPE_PNG:  $src = @imagecreatefrompng($file['tmp_name']); break;
        case IMAGETYPE_WEBP: if (function_exists('imagecreatefromwebp')) $src = @imagecreatefromwebp($file['tmp_name']); break;
        case IMAGETYPE_GIF:  $src = @imagecreatefromgif($file['tmp_name']); break;
    }
    if (!$src) return null;

    $w = imagesx($src);
    $h = imagesy($src);
    $max = ADMIN_IMG_MAX_DIM;
    if ($w > $max || $h > $max) {
        $ratio = min($max / $w, $max / $h);
        $nw = max(1, (int)round($w * $ratio));
        $nh = max(1, (int)round($h * $ratio));
        $resized = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($resized, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);
        $src = $resized;
    }

    $dir = __DIR__ . '/../assets/propiedades';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $nombre = $idSlug . '-' . $indice . '-' . substr(bin2hex(random_bytes(3)), 0, 6) . '.webp';
    $ruta = $dir . '/' . $nombre;

    $ok = function_exists('imagewebp') ? @imagewebp($src, $ruta, ADMIN_IMG_CALIDAD) : false;
    imagedestroy($src);
    if (!$ok) return null;

    return 'assets/propiedades/' . $nombre;
}

/** Borra un archivo de foto por su ruta relativa (assets/propiedades/xxx.webp). Nunca deja salir de esa carpeta. */
function admin_borrar_foto(string $rutaRelativa): void
{
    $base = realpath(__DIR__ . '/../assets/propiedades');
    $full = realpath(__DIR__ . '/../' . $rutaRelativa);
    if ($base && $full && str_starts_with($full, $base)) {
        @unlink($full);
    }
}

define('ADMIN_VIDEO_MAX_BYTES', 200 * 1024 * 1024); // 200 MB por video
const ADMIN_VIDEO_EXTENSIONES = ['mp4', 'webm', 'mov', 'm4v'];

/**
 * Guarda un video subido por el panel tal cual (sin recodificar: eso
 * necesitaría ffmpeg, que no está disponible en este hosting). Valida
 * extensión + tipo MIME real del archivo antes de guardarlo.
 *
 * @return string|null Ruta relativa (assets/videos/xxx.mp4) o null si falló/no era un video válido
 */
function admin_procesar_video(array $file, string $idSlug, int $indice): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
    if (($file['size'] ?? 0) <= 0 || $file['size'] > ADMIN_VIDEO_MAX_BYTES) return null;
    if (!is_uploaded_file($file['tmp_name'])) return null;

    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ADMIN_VIDEO_EXTENSIONES, true)) return null;

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!$mime || strpos($mime, 'video/') !== 0) return null; // el tipo real del archivo, no solo la extensión

    $dir = __DIR__ . '/../assets/videos';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $nombre = $idSlug . '-' . $indice . '-' . substr(bin2hex(random_bytes(3)), 0, 6) . '.' . $ext;
    $ruta = $dir . '/' . $nombre;

    if (!@move_uploaded_file($file['tmp_name'], $ruta)) return null;
    return 'assets/videos/' . $nombre;
}

/** Borra un archivo de video por su ruta relativa (assets/videos/xxx.mp4). Nunca deja salir de esa carpeta. */
function admin_borrar_video(string $rutaRelativa): void
{
    $base = realpath(__DIR__ . '/../assets/videos');
    $full = realpath(__DIR__ . '/../' . $rutaRelativa);
    if ($base && $full && str_starts_with($full, $base)) {
        @unlink($full);
    }
}
