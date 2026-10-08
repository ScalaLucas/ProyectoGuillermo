<?php
require __DIR__ . '/lib.php';
// Con usuarios creados se ingresa por /seguridad/ (usuario + 2FA); la clave antigua
// solo sigue valiendo mientras un administrador la deje habilitada.
$hayUsuarios = seg_hay_usuarios();
if ($hayUsuarios && !seg_config()['legacy_propiedades']) { header('Location: ../seguridad/login.php?a=propiedades'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (admin_login_bloqueado()) {
        $error = 'Demasiados intentos fallidos. Esperá unos minutos y volvé a probar.';
    } else {
        $user = (string)($_POST['user'] ?? '');
        $pass = (string)($_POST['pass'] ?? '');
        if (hash_equals(ADMIN_USER, $user) && password_verify($pass, ADMIN_PASS_HASH)) {
            admin_login_registrar_exito();
            seg_log('ingreso_clave_antigua', '', 'propiedades');
            session_regenerate_id(true);
            $_SESSION['admin_ok'] = true;
            header('Location: index.php');
            exit;
        }
        admin_login_registrar_fallo();
        $error = 'Usuario o clave incorrectos.';
    }
}
if (admin_logueado()) { header('Location: index.php'); exit; }
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Ingresar · Panel Marcelo Ragonese Propiedades</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="admin.css?v=2">
</head>
<body>
<div class="wrap">
  <div class="login-box card">
    <h1 style="margin-top:0">Panel de administración</h1>
    <?php if ($error): ?><div class="msg msg--err"><?= admin_html($error) ?></div><?php endif; ?>
    <form method="post">
      <div class="field"><label>Usuario</label><input type="text" name="user" autocomplete="username" required autofocus></div>
      <div class="field"><label>Contraseña</label><input type="password" name="pass" autocomplete="current-password" required></div>
      <button class="btn" type="submit" style="width:100%;justify-content:center">Ingresar</button>
    </form>
    <?php if ($hayUsuarios): ?><p style="margin-top:16px;text-align:center"><a class="btn btn--ghost" href="../seguridad/login.php?a=propiedades">Ingresar con usuario y verificación en dos pasos →</a></p><?php endif; ?>
  </div>
</div>
</body>
</html>
