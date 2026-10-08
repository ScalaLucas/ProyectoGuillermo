<?php
require __DIR__ . '/lib.php';
alq_asegurar_dir();
if (alq_logueado()) { header('Location: index.php'); exit; }
// Con usuarios creados: se ingresa por /seguridad/ (usuario + 2FA). La clave antigua
// solo sigue funcionando mientras un administrador la deje habilitada.
$hayUsuarios = seg_hay_usuarios();
if ($hayUsuarios && !seg_config()['legacy_alquileres']) { header('Location: ../seguridad/login.php?a=alquileres'); exit; }

$error = '';
$lockFile = ALQ_DATA . '/intentos.json';
$ip = $_SERVER['REMOTE_ADDR'] ?? '?';
$intentos = is_file($lockFile) ? (json_decode((string)@file_get_contents($lockFile), true) ?: []) : [];
$intentos[$ip] = array_values(array_filter($intentos[$ip] ?? [], fn($t) => $t > time() - 900));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (count($intentos[$ip]) >= 6) {
        $error = 'Demasiados intentos fallidos. Esperá unos minutos y probá de nuevo.';
    } elseif (password_verify((string)($_POST['clave'] ?? ''), alq_hash_clave())) {
        session_regenerate_id(true);
        $_SESSION['alq_ok'] = true;
        seg_log('ingreso_clave_antigua', '', 'alquileres');
        $intentos[$ip] = [];
        @file_put_contents($lockFile, json_encode($intentos), LOCK_EX);
        header('Location: index.php'); exit;
    } else {
        $intentos[$ip][] = time();
        @file_put_contents($lockFile, json_encode($intentos), LOCK_EX);
        $error = 'Clave incorrecta.';
    }
}
?><!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Ingresar · Alquileres</title><meta name="robots" content="noindex, nofollow"><link rel="stylesheet" href="alquileres.css?v=1"></head>
<body><div class="login card">
<h1>Alquileres</h1><p class="sub"><?= h(ALQ_EMPRESA) ?></p>
<?php if ($error): ?><div class="msg err"><?= h($error) ?></div><?php endif; ?>
<form method="post"><div class="f"><label>Clave</label><input type="password" name="clave" autofocus required></div>
<button class="btn" type="submit" style="width:100%">Ingresar</button></form>
<?php if ($hayUsuarios): ?><p style="margin-top:16px;text-align:center"><a class="btn gh" href="../seguridad/login.php?a=alquileres">Ingresar con usuario y verificación en dos pasos →</a></p><?php endif; ?>
</div></body></html>
