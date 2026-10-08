<?php
/**
 * Núcleo de seguridad compartido por el panel de propiedades (/admin/) y el
 * de alquileres (/alquileres/): usuarios individuales, verificación en dos
 * pasos (TOTP, compatible con Google Authenticator / Microsoft Authenticator /
 * Authy) y registro de actividad. Una sola sesión sirve para ambos paneles.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('gp_admin_sess');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
    session_start();
}
date_default_timezone_set('America/Argentina/Buenos_Aires');
define('SEG_DATA', __DIR__ . '/data');
define('SEG_ISSUER', 'Marcelo Ragonese Propiedades');

function seg_h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function seg_ip(): string { return substr((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '?'), 0, 45); }

/* ---------- almacenamiento ---------- */
function seg_asegurar_dir(): void {
    if (!is_dir(SEG_DATA)) {
        @mkdir(SEG_DATA, 0755, true);
        @file_put_contents(SEG_DATA . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        @file_put_contents(SEG_DATA . '/index.html', '');
    }
}
function seg_leer(string $nombre): array {
    $d = json_decode((string)@file_get_contents(SEG_DATA . '/' . $nombre), true);
    return is_array($d) ? $d : [];
}
function seg_escribir(string $nombre, array $datos): bool {
    seg_asegurar_dir();
    return @file_put_contents(SEG_DATA . '/' . $nombre, json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) !== false;
}
function seg_usuarios(): array { return seg_leer('usuarios.json'); }
function seg_guardar_usuarios(array $u): bool { return seg_escribir('usuarios.json', $u); }
function seg_hay_usuarios(): bool { return count(seg_usuarios()) > 0; }
function seg_config(): array {
    return array_merge(['exigir_2fa' => true, 'legacy_propiedades' => true, 'legacy_alquileres' => true], seg_leer('config.json'));
}
function seg_guardar_config(array $c): bool { return seg_escribir('config.json', $c); }

/* ---------- registro de actividad ---------- */
function seg_log(string $accion, string $detalle = '', string $panel = '', ?string $usuario = null): void {
    seg_asegurar_dir();
    $f = SEG_DATA . '/actividad.jsonl';
    if (is_file($f) && filesize($f) > 2000000) @rename($f, SEG_DATA . '/actividad-' . date('Ymd-His') . '.jsonl');
    $linea = json_encode([
        'fecha' => date('Y-m-d H:i:s'), 'usuario' => $usuario ?? ($_SESSION['usuario'] ?? (!empty($_SESSION['admin_ok']) || !empty($_SESSION['alq_ok']) ? '(clave antigua)' : '(sin sesión)')),
        'panel' => $panel, 'accion' => $accion, 'detalle' => mb_substr($detalle, 0, 300), 'ip' => seg_ip(),
    ], JSON_UNESCAPED_UNICODE) . "\n";
    @file_put_contents($f, $linea, FILE_APPEND | LOCK_EX);
}

/* ---------- TOTP (RFC 6238) ---------- */
const SEG_B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
function seg_b32enc(string $bin): string {
    $bits = '';
    foreach (str_split($bin) as $ch) $bits .= str_pad(decbin(ord($ch)), 8, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 5) as $c) $out .= SEG_B32[bindec(str_pad($c, 5, '0'))];
    return $out;
}
function seg_b32dec(string $s): string {
    $s = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s));
    $bits = '';
    foreach (str_split($s) as $c) $bits .= str_pad(decbin(strpos(SEG_B32, $c)), 5, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 8) as $b) if (strlen($b) === 8) $out .= chr(bindec($b));
    return $out;
}
function seg_nuevo_secreto(): string { return seg_b32enc(random_bytes(20)); }
function seg_hotp(string $secretoB32, int $contador): string {
    $h = hash_hmac('sha1', pack('J', $contador), seg_b32dec($secretoB32), true);
    $o = ord($h[19]) & 0xf;
    $v = ((ord($h[$o]) & 0x7f) << 24) | ((ord($h[$o + 1]) & 0xff) << 16) | ((ord($h[$o + 2]) & 0xff) << 8) | (ord($h[$o + 3]) & 0xff);
    return str_pad((string)($v % 1000000), 6, '0', STR_PAD_LEFT);
}
/** Valida un código de 6 dígitos (tolera ±30 s de desfase). $ultimo evita reusar el mismo código. */
function seg_totp_valido(string $secretoB32, string $codigo, int &$ultimo): bool {
    $codigo = preg_replace('/\D/', '', $codigo);
    if (strlen($codigo) !== 6) return false;
    $t = intdiv(time(), 30);
    foreach ([0, -1, 1] as $off) {
        $c = $t + $off;
        if ($c > $ultimo && hash_equals(seg_hotp($secretoB32, $c), $codigo)) { $ultimo = $c; return true; }
    }
    return false;
}
function seg_otpauth(string $usuario, string $secreto): string {
    return 'otpauth://totp/' . rawurlencode(SEG_ISSUER . ':' . $usuario) . '?secret=' . $secreto . '&issuer=' . rawurlencode(SEG_ISSUER) . '&algorithm=SHA1&digits=6&period=30';
}
function seg_codigos_recuperacion(): array {
    $plano = []; $hashes = [];
    for ($i = 0; $i < 8; $i++) { $c = strtolower(bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(2))); $plano[] = $c; $hashes[] = password_hash($c, PASSWORD_DEFAULT); }
    return [$plano, $hashes];
}

/* ---------- freno a intentos fallidos (por IP y por usuario) ---------- */
function seg_bloqueado(string $clave): bool {
    $d = seg_leer('intentos.json');
    $d[$clave] = array_values(array_filter($d[$clave] ?? [], fn($t) => $t > time() - 900));
    return count($d[$clave]) >= 6;
}
function seg_fallo(string $clave): void {
    $d = seg_leer('intentos.json');
    foreach ($d as $k => $v) $d[$k] = array_values(array_filter($v, fn($t) => $t > time() - 900));
    $d[$clave][] = time();
    seg_escribir('intentos.json', array_filter($d));
}
function seg_limpiar_fallos(string $clave): void {
    $d = seg_leer('intentos.json'); unset($d[$clave]); seg_escribir('intentos.json', $d);
}

/* ---------- sesión ---------- */
function seg_csrf(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24)); return $_SESSION['csrf']; }
function seg_csrf_ok(): bool { return hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? '')); }
function seg_usuario_actual(): ?array {
    if (empty($_SESSION['usuario'])) return null;
    $u = seg_usuarios()[$_SESSION['usuario']] ?? null;
    return ($u && ($u['activo'] ?? true)) ? $u : null;
}
function seg_puede(string $panel): bool {
    $u = seg_usuario_actual();
    if (!$u) return false;
    return ($u['rol'] ?? '') === 'admin' || in_array($panel, $u['paneles'] ?? [], true);
}
function seg_es_admin(): bool { $u = seg_usuario_actual(); return $u && ($u['rol'] ?? '') === 'admin'; }
function seg_iniciar_sesion(string $usuario): void {
    $us = seg_usuarios();
    $u = $us[$usuario];
    session_regenerate_id(true);
    foreach (['seg_pre', 'seg_enrolar', 'seg_secreto_pend'] as $k) unset($_SESSION[$k]);
    $_SESSION['usuario'] = $usuario;
    $paneles = $u['paneles'] ?? [];
    $admin = ($u['rol'] ?? '') === 'admin';
    if ($admin || in_array('propiedades', $paneles, true)) $_SESSION['admin_ok'] = true; else unset($_SESSION['admin_ok']);
    if ($admin || in_array('alquileres', $paneles, true)) $_SESSION['alq_ok'] = true; else unset($_SESSION['alq_ok']);
    $us[$usuario]['ultimo_acceso'] = date('Y-m-d H:i:s');
    seg_guardar_usuarios($us);
    // Si es una clave temporal (recién creada o restablecida por un admin), se
    // obliga a cambiarla antes de dejar usar cualquier panel.
    $_SESSION['seg_forzar_clave'] = !empty($u['cambiar_clave']);
    seg_log('ingreso', '2FA ' . (($u['totp_activo'] ?? false) ? 'verificado' : 'no activo'), '', $usuario);
}
/** true si esta sesión todavía tiene pendiente cambiar una clave temporal. */
function seg_forzar_clave_pendiente(): bool { return !empty($_SESSION['seg_forzar_clave']); }
function seg_cerrar_sesion(): void {
    seg_log('salida');
    $_SESSION = [];
    if (ini_get('session.use_cookies')) { $p = session_get_cookie_params(); setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']); }
    session_destroy();
}
function seg_ruta_panel(string $a): string {
    return ['propiedades' => '../admin/index.php', 'alquileres' => '../alquileres/index.php', 'usuarios' => 'usuarios.php'][$a] ?? 'cuenta.php';
}

/* ---------- interfaz común ---------- */
function seg_cabecera(string $titulo, bool $nav = true): void {
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>' . seg_h($titulo) . ' · Seguridad</title><meta name="robots" content="noindex, nofollow"><link rel="stylesheet" href="../alquileres/alquileres.css?v=5"></head><body>';
    if ($nav) {
        $u = seg_usuario_actual();
        // Solo se muestra el link a cada panel si el usuario realmente puede
        // entrar ahí — para las cuentas con clave antigua (sin usuario propio)
        // se usa la sesión de ese panel, igual que siempre funcionó separado.
        $vePropiedades = $u ? seg_puede('propiedades') : !empty($_SESSION['admin_ok']);
        $veAlquileres = $u ? seg_puede('alquileres') : !empty($_SESSION['alq_ok']);
        echo '<header class="top"><div class="top__in"><a class="brand" href="cuenta.php"><img src="../assets/logo-mr-icon.png" alt=""><span><strong>Seguridad</strong><small>' . seg_h(SEG_ISSUER) . '</small></span></a><nav>';
        if ($vePropiedades) echo '<a href="../admin/index.php">Propiedades</a>';
        if ($veAlquileres) echo '<a href="../alquileres/index.php">Alquileres</a>';
        echo '<a href="cuenta.php">Mi cuenta</a>';
        if (seg_es_admin()) echo '<a href="usuarios.php">Usuarios</a><a href="actividad.php">Actividad</a>';
        echo '<a href="logout.php">Salir' . ($u ? ' (' . seg_h($u['usuario']) . ')' : '') . '</a></nav></div></header><main class="wrap">';
    } else echo '<main>';
}
function seg_pie(): void { echo '</main></body></html>'; }
