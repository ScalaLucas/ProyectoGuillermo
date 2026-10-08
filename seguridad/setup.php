<?php
/** Alta del PRIMER administrador. Solo funciona mientras no exista ningún usuario. */
require __DIR__ . '/auth.php';
seg_asegurar_dir();
if (seg_hay_usuarios()) { header('Location: login.php'); exit; }

function seg_clave_vieja_ok(string $clave): bool {
    // Clave del sistema de alquileres (la inicial o la que se haya cambiado)…
    require_once __DIR__ . '/../alquileres/config.php';
    $h = ALQ_PASS_HASH_INICIAL;
    $f = __DIR__ . '/../alquileres/data/clave.json';
    if (is_file($f)) { $d = json_decode((string)file_get_contents($f), true); if (!empty($d['hash'])) $h = $d['hash']; }
    if (password_verify($clave, $h)) return true;
    // …o la clave del panel de propiedades.
    if (!defined('CHAT_API_ENTRY')) define('CHAT_API_ENTRY', true);
    require_once __DIR__ . '/../api/config.php';
    return defined('ADMIN_PASS_HASH') && password_verify($clave, ADMIN_PASS_HASH);
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ipK = 'ip:' . seg_ip();
    $usuario = strtolower(trim((string)($_POST['usuario'] ?? '')));
    $nombre = trim((string)($_POST['nombre'] ?? ''));
    $nueva = (string)($_POST['nueva'] ?? '');
    if (seg_bloqueado($ipK)) $error = 'Demasiados intentos fallidos. Esperá unos minutos.';
    elseif (!seg_csrf_ok()) $error = 'La página quedó desactualizada. Recargala y probá de nuevo.';
    elseif (!seg_clave_vieja_ok((string)($_POST['clave_vieja'] ?? ''))) { seg_fallo($ipK); seg_log('setup_fallido', '', '', '(setup)'); $error = 'La clave actual no es correcta.'; }
    elseif (!preg_match('/^[a-z0-9._-]{3,30}$/', $usuario)) $error = 'El usuario debe tener 3 a 30 caracteres: letras, números, punto, guion.';
    elseif ($nombre === '') $error = 'Escribí el nombre completo.';
    elseif (strlen($nueva) < 10) $error = 'La clave nueva debe tener al menos 10 caracteres.';
    elseif ($nueva !== (string)($_POST['nueva2'] ?? '')) $error = 'Las dos claves nuevas no coinciden.';
    else {
        seg_guardar_usuarios([$usuario => [
            'usuario' => $usuario, 'nombre' => mb_substr($nombre, 0, 80), 'hash' => password_hash($nueva, PASSWORD_DEFAULT),
            'rol' => 'admin', 'paneles' => ['propiedades', 'alquileres'], 'activo' => true,
            'totp_activo' => false, 'totp_secreto' => '', 'totp_last' => 0, 'recuperacion' => [],
            'creado' => date('Y-m-d H:i:s'), 'ultimo_acceso' => '',
        ]]);
        seg_guardar_config(seg_config());
        seg_log('usuario_creado', 'primer administrador', '', $usuario);
        $_SESSION['seg_enrolar'] = $usuario;
        $_SESSION['seg_destino'] = 'cuenta';
        header('Location: cuenta.php?activar=1'); exit;
    }
}
seg_cabecera('Crear administrador', false);
?>
<div class="login card" style="max-width:460px">
<h1>Crear el primer administrador</h1>
<p class="sub">Vas a usar este usuario para entrar a los dos paneles, con verificación en dos pasos.</p>
<?php if ($error): ?><div class="msg err"><?= seg_h($error) ?></div><?php endif; ?>
<form method="post"><input type="hidden" name="csrf" value="<?= seg_h(seg_csrf()) ?>">
<div class="f"><label>Clave actual de cualquiera de los dos paneles</label><input type="password" name="clave_vieja" required><span class="hint">Para comprobar que sos quien administra el sitio.</span></div>
<div class="f"><label>Tu usuario</label><input name="usuario" placeholder="ej: marcelo" required></div>
<div class="f"><label>Nombre completo</label><input name="nombre" required></div>
<div class="f"><label>Clave nueva (mínimo 10 caracteres)</label><input type="password" name="nueva" minlength="10" required></div>
<div class="f"><label>Repetir clave nueva</label><input type="password" name="nueva2" minlength="10" required></div>
<button class="btn" type="submit" style="width:100%">Crear y seguir</button></form>
</div></main></body></html>
