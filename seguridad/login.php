<?php
require __DIR__ . '/auth.php';
seg_asegurar_dir();
if (!seg_hay_usuarios()) { header('Location: setup.php'); exit; }

$a = preg_replace('/[^a-z]/', '', (string)($_GET['a'] ?? $_POST['a'] ?? 'cuenta'));
if (seg_usuario_actual()) {
    if ($a === 'cuenta' || $a === 'usuarios' || seg_puede($a)) { header('Location: ' . seg_ruta_panel($a)); exit; }
    seg_cabecera('Sin permiso');
    echo '<div class="card" style="max-width:480px;margin:8vh auto"><h1>Sin permiso</h1><p>Tu usuario no tiene acceso a este panel. Pedile a un administrador que te lo habilite.</p><a class="btn" href="cuenta.php">Ir a Mi cuenta</a></div></main></body></html>'; exit;
}
$_SESSION['seg_destino'] = $a;

$error = '';
$pre = $_SESSION['seg_pre'] ?? null;
if ($pre && ($pre['t'] ?? 0) < time() - 300) { unset($_SESSION['seg_pre']); $pre = null; }

if (isset($_GET['cancelar'])) { unset($_SESSION['seg_pre']); header('Location: login.php?a=' . urlencode($a)); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ipK = 'ip:' . seg_ip();
    $paso = (string)($_POST['paso'] ?? '1');

    if ($paso === '1') {
        $usuario = strtolower(trim((string)($_POST['usuario'] ?? '')));
        $uK = 'u:' . $usuario;
        $us = seg_usuarios();
        $u = $us[$usuario] ?? null;
        if (seg_bloqueado($ipK) || seg_bloqueado($uK)) {
            $error = 'Demasiados intentos fallidos. Esperá unos minutos y probá de nuevo.';
        } else {
            $ok = password_verify((string)($_POST['clave'] ?? ''), $u['hash'] ?? '$2y$10$abcdefghijklmnopqrstuuJ4EIe7A4p6l0v8pZ1v7l1o8m2n3o4p5q');
            if ($u && ($u['activo'] ?? true) && $ok) {
                seg_limpiar_fallos($uK);
                if (!empty($u['totp_activo'])) {
                    $_SESSION['seg_pre'] = ['u' => $usuario, 't' => time()];
                    $pre = $_SESSION['seg_pre'];
                } elseif (seg_config()['exigir_2fa']) {
                    $_SESSION['seg_enrolar'] = $usuario;
                    seg_log('ingreso_pendiente_2fa', 'debe activar 2FA', '', $usuario);
                    header('Location: cuenta.php?activar=1'); exit;
                } else {
                    seg_iniciar_sesion($usuario);
                    header('Location: ' . seg_ruta_panel($a)); exit;
                }
            } else {
                seg_fallo($ipK); seg_fallo($uK);
                seg_log('ingreso_fallido', $usuario, '', $usuario ?: '(vacío)');
                $error = 'Usuario o clave incorrectos.';
            }
        }
    } elseif ($paso === '2' && $pre) {
        $usuario = $pre['u'];
        $uK = 'u:' . $usuario;
        $us = seg_usuarios();
        $u = $us[$usuario] ?? null;
        $codigo = trim((string)($_POST['codigo'] ?? ''));
        if (seg_bloqueado($ipK) || seg_bloqueado($uK)) {
            $error = 'Demasiados intentos fallidos. Esperá unos minutos y probá de nuevo.';
        } elseif ($u) {
            $valido = false;
            if (preg_match('/^[0-9a-f]{4}-[0-9a-f]{4}$/i', $codigo)) {
                foreach ($u['recuperacion'] ?? [] as $i => $h) {
                    if (password_verify(strtolower($codigo), $h)) {
                        array_splice($us[$usuario]['recuperacion'], $i, 1);
                        seg_guardar_usuarios($us);
                        seg_log('codigo_recuperacion_usado', 'quedan ' . count($us[$usuario]['recuperacion']), '', $usuario);
                        $valido = true; break;
                    }
                }
            } else {
                $ultimo = (int)($u['totp_last'] ?? 0);
                if (seg_totp_valido($u['totp_secreto'], $codigo, $ultimo)) {
                    $us[$usuario]['totp_last'] = $ultimo;
                    seg_guardar_usuarios($us);
                    $valido = true;
                }
            }
            if ($valido) {
                seg_limpiar_fallos($uK);
                seg_iniciar_sesion($usuario);
                header('Location: ' . seg_ruta_panel($a)); exit;
            }
            seg_fallo($ipK); seg_fallo($uK);
            seg_log('2fa_fallido', '', '', $usuario);
            $error = 'Código incorrecto. Revisá que la hora del celular esté en automático.';
        }
    }
}
seg_cabecera('Ingresar', false);
?>
<div class="login card">
<h1><?= $pre ? 'Verificación en dos pasos' : 'Ingresar' ?></h1><p class="sub"><?= seg_h(SEG_ISSUER) ?></p>
<?php if ($error): ?><div class="msg err"><?= seg_h($error) ?></div><?php endif; ?>
<?php if ($pre): ?>
<form method="post"><input type="hidden" name="paso" value="2"><input type="hidden" name="a" value="<?= seg_h($a) ?>">
<p class="hint">Abrí la app de autenticación del celular (Google Authenticator, Microsoft Authenticator, Authy…) e ingresá el código de 6 dígitos de <b><?= seg_h($pre['u']) ?></b>.</p>
<div class="f"><label>Código</label><input name="codigo" inputmode="numeric" autocomplete="one-time-code" autofocus required placeholder="123456"></div>
<button class="btn" type="submit" style="width:100%">Verificar</button>
<p class="hint" style="margin-top:10px">¿Perdiste el celular? Escribí en el campo uno de tus <b>códigos de recuperación</b> (formato <code>ab12-cd34</code>).</p>
<p class="hint"><a href="login.php?cancelar=1&a=<?= seg_h($a) ?>">← Volver</a></p></form>
<?php else: ?>
<form method="post"><input type="hidden" name="paso" value="1"><input type="hidden" name="a" value="<?= seg_h($a) ?>">
<div class="f"><label>Usuario</label><input name="usuario" autocomplete="username" autofocus required></div>
<div class="f"><label>Clave</label><input type="password" name="clave" autocomplete="current-password" required></div>
<button class="btn" type="submit" style="width:100%">Continuar</button></form>
<?php endif; ?>
</div></main></body></html>
