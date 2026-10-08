<?php
/**
 * Sesión y helpers comunes del panel de administración.
 * Todas las páginas de admin/ (menos login.php) empiezan con:
 *   require __DIR__ . '/lib.php'; admin_requerir_login();
 */

define('CHAT_API_ENTRY', true); // reutiliza el gate de api/config.php
define('PROPS_ENTRY', true);    // reutiliza el gate de api/propiedades_store.php

require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/propiedades_store.php';

session_name(ADMIN_SESSION_NAME);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
    session_start();
}
require_once __DIR__ . '/../seguridad/auth.php';

function admin_logueado(): bool
{
    if (empty($_SESSION['admin_ok'])) return false;
    // Con usuarios creados y la clave antigua deshabilitada, una sesión sin usuario deja de valer.
    if (empty($_SESSION['usuario']) && seg_hay_usuarios() && !seg_config()['legacy_propiedades']) return false;
    return true;
}

function admin_requerir_login(): void
{
    if (!admin_logueado()) {
        header('Location: login.php');
        exit;
    }
    // Clave temporal (recién creada o restablecida): no se puede usar el panel
    // hasta cambiarla, salvo en "Mi cuenta", que es donde se cambia.
    if (function_exists('seg_forzar_clave_pendiente') && seg_forzar_clave_pendiente()) {
        header('Location: ../seguridad/cuenta.php?forzar=1');
        exit;
    }
}

function admin_csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf'];
}

function admin_csrf_verificar(): void
{
    $enviado = $_POST['csrf'] ?? '';
    if (!is_string($enviado) || !hash_equals($_SESSION['csrf'] ?? '', $enviado)) {
        http_response_code(403);
        exit('Token inválido. Volvé atrás y probá de nuevo.');
    }
}

function admin_html(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/** Throttle simple de intentos de login por sesión de PHP (archivo). */
function admin_login_bloqueado(): bool
{
    $f = __DIR__ . '/../api/data/admin_login.json';
    $data = @json_decode(@file_get_contents($f), true) ?: ['fallos' => 0, 'hasta' => 0];
    return ($data['hasta'] ?? 0) > time();
}

function admin_login_registrar_fallo(): void
{
    $f = __DIR__ . '/../api/data/admin_login.json';
    $dir = dirname($f);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $data = @json_decode(@file_get_contents($f), true) ?: ['fallos' => 0, 'hasta' => 0];
    $data['fallos'] = ($data['fallos'] ?? 0) + 1;
    if ($data['fallos'] >= 5) {
        $data['hasta'] = time() + 300; // 5 intentos fallidos → 5 min de espera
        $data['fallos'] = 0;
    }
    @file_put_contents($f, json_encode($data), LOCK_EX);
}

function admin_login_registrar_exito(): void
{
    $f = __DIR__ . '/../api/data/admin_login.json';
    @file_put_contents($f, json_encode(['fallos' => 0, 'hasta' => 0]), LOCK_EX);
}

/* ---------- interfaz común (mismo estilo de encabezado que Alquileres) ---------- */
function admin_cabecera(string $titulo, string $activo = ''): void {
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<title>' . admin_html($titulo) . ' · Propiedades</title><meta name="robots" content="noindex, nofollow">';
    echo '<link rel="stylesheet" href="admin.css?v=2"></head><body><header class="top"><div class="top__in">';
    echo '<a class="brand" href="index.php"><img src="../assets/logo-mr-icon.png" alt=""><span><strong>Propiedades</strong><small>Marcelo Ragonese Propiedades</small></span></a><nav>';
    $veAlquileres = seg_usuario_actual() ? seg_puede('alquileres') : !empty($_SESSION['alq_ok']);
    $nav = ['index.php' => 'Propiedades'];
    if ($veAlquileres) $nav['../alquileres/index.php'] = 'Alquileres';
    $nav['../seguridad/cuenta.php'] = 'Mi cuenta';
    if (seg_es_admin()) $nav['../seguridad/usuarios.php'] = 'Usuarios';
    foreach ($nav as $href => $txt) {
        $cls = ($href === $activo) ? ' class="on"' : '';
        echo '<a' . $cls . ' href="' . $href . '">' . admin_html($txt) . '</a>';
    }
    echo '<a href="' . (seg_usuario_actual() ? '../seguridad/logout.php' : 'logout.php') . '">Salir</a></nav></div></header><main class="wrap">';
}
function admin_pie(): void { echo '</main></body></html>'; }
