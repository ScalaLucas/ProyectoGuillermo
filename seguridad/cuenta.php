<?php
require __DIR__ . '/auth.php';
seg_asegurar_dir();

$enrolando = empty($_SESSION['usuario']) && !empty($_SESSION['seg_enrolar']);
$usuario = $_SESSION['usuario'] ?? ($_SESSION['seg_enrolar'] ?? '');
$us = seg_usuarios();
if ($usuario === '' || empty($us[$usuario]) || !($us[$usuario]['activo'] ?? true)) { header('Location: login.php'); exit; }
$u = $us[$usuario];
$msg = ''; $codigosNuevos = null;
$exigir = seg_config()['exigir_2fa'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string)($_POST['accion'] ?? '');
    if (!seg_csrf_ok()) { $msg = 'err:La página quedó desactualizada. Recargala y probá de nuevo.'; }
    elseif ($accion === 'activar_2fa' && empty($u['totp_activo'])) {
        $secreto = $_SESSION['seg_secreto_pend'] ?? '';
        $ultimo = 0;
        if ($secreto !== '' && seg_totp_valido($secreto, (string)($_POST['codigo'] ?? ''), $ultimo)) {
            [$plano, $hashes] = seg_codigos_recuperacion();
            $us[$usuario]['totp_secreto'] = $secreto; $us[$usuario]['totp_activo'] = true;
            $us[$usuario]['totp_last'] = $ultimo; $us[$usuario]['recuperacion'] = $hashes;
            seg_guardar_usuarios($us);
            unset($_SESSION['seg_secreto_pend']);
            seg_log('2fa_activado', '', '', $usuario);
            $codigosNuevos = $plano;
            if ($enrolando) { seg_iniciar_sesion($usuario); $enrolando = false; }
            $u = seg_usuarios()[$usuario];
        } else $msg = 'err:El código no es correcto. Escaneá el QR de nuevo y probá con el código actual de la app.';
    }
    elseif (in_array($accion, ['clave', 'desactivar_2fa', 'regenerar'], true) && !password_verify((string)($_POST['actual'] ?? ''), $u['hash'])) {
        $msg = 'err:La clave actual no es correcta.'; seg_log('cuenta_clave_incorrecta', $accion, '', $usuario);
    }
    elseif ($accion === 'clave') {
        $n = (string)($_POST['nueva'] ?? '');
        if (strlen($n) < 10) $msg = 'err:La clave nueva debe tener al menos 10 caracteres.';
        elseif ($n !== (string)($_POST['nueva2'] ?? '')) $msg = 'err:Las dos claves nuevas no coinciden.';
        else { $us[$usuario]['hash'] = password_hash($n, PASSWORD_DEFAULT); unset($us[$usuario]['cambiar_clave']); seg_guardar_usuarios($us); unset($_SESSION['seg_forzar_clave']); seg_log('clave_cambiada', '', '', $usuario); $msg = 'ok:Clave cambiada.'; }
    }
    elseif ($accion === 'desactivar_2fa') {
        if ($exigir) $msg = 'err:La verificación en dos pasos es obligatoria. Un administrador puede restablecerla si perdiste el celular.';
        else { $us[$usuario]['totp_activo'] = false; $us[$usuario]['totp_secreto'] = ''; $us[$usuario]['recuperacion'] = []; seg_guardar_usuarios($us); seg_log('2fa_desactivado', '', '', $usuario); $msg = 'ok:Verificación en dos pasos desactivada.'; }
    }
    elseif ($accion === 'regenerar' && !empty($u['totp_activo'])) {
        [$plano, $hashes] = seg_codigos_recuperacion();
        $us[$usuario]['recuperacion'] = $hashes; seg_guardar_usuarios($us); seg_log('codigos_regenerados', '', '', $usuario);
        $codigosNuevos = $plano;
    }
    $u = seg_usuarios()[$usuario];
}

$activar = empty($u['totp_activo']);
if ($activar && empty($_SESSION['seg_secreto_pend'])) $_SESSION['seg_secreto_pend'] = seg_nuevo_secreto();
$secreto = $_SESSION['seg_secreto_pend'] ?? '';

seg_cabecera('Mi cuenta', !$enrolando);
if ($enrolando) echo '<main class="wrap">';
$destino = seg_ruta_panel($_SESSION['seg_destino'] ?? 'cuenta');
?>
<h1>Mi cuenta</h1>
<p class="sub"><?= seg_h($u['nombre']) ?> · usuario <b><?= seg_h($usuario) ?></b> · <?= ($u['rol'] ?? '') === 'admin' ? 'administrador' : 'operador' ?></p>
<?php if (seg_forzar_clave_pendiente()): ?><div class="msg warn">Estás usando una clave temporal. Antes de poder usar el panel, elegí una clave propia abajo.</div><?php endif; ?>
<?php if ($msg): [$t, $m] = explode(':', $msg, 2); ?><div class="msg <?= $t ?>"><?= seg_h($m) ?></div><?php endif; ?>

<?php if ($codigosNuevos): ?>
<div class="card" style="border:2px solid var(--gold)"><h2 style="margin-top:0">Tus códigos de recuperación</h2>
<p><b>Guardalos ahora</b> (foto, gestor de claves o papel en un lugar seguro). Se muestran una sola vez. Cada uno sirve <b>una vez</b> si perdés el celular.</p>
<pre style="font-size:18px;line-height:1.9;background:#faf7f2;padding:12px 16px;border-radius:10px;display:inline-block"><?= seg_h(implode("\n", $codigosNuevos)) ?></pre>
<p><a class="btn" href="<?= seg_h($destino) ?>">Ya los guardé, continuar →</a></p></div>
<?php elseif ($activar): ?>
<div class="card"><h2 style="margin-top:0">Activar la verificación en dos pasos</h2>
<?php if ($enrolando): ?><p class="hint">Para proteger los datos, el ingreso requiere un código del celular. Son 2 minutos.</p><?php endif; ?>
<ol class="lista"><li>Instalá en el celular una app de autenticación: <b>Google Authenticator</b>, <b>Microsoft Authenticator</b> o <b>Authy</b>.</li>
<li>Abrila, elegí “agregar cuenta” y <b>escaneá este QR</b>:</li></ol>
<div id="qr" style="margin:12px 0"></div>
<p class="hint">¿No podés escanear? Ingresá esta clave a mano: <code style="font-size:15px"><?= seg_h(trim(chunk_split($secreto, 4, ' '))) ?></code></p>
<form method="post" style="max-width:300px"><input type="hidden" name="csrf" value="<?= seg_h(seg_csrf()) ?>"><input type="hidden" name="accion" value="activar_2fa">
<div class="f"><label>Código de 6 dígitos que muestra la app</label><input name="codigo" inputmode="numeric" autocomplete="one-time-code" required placeholder="123456"></div>
<button class="btn" type="submit">Activar</button></form></div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>try{new QRCode(document.getElementById('qr'),{text:<?= json_encode(seg_otpauth($usuario, $secreto)) ?>,width:200,height:200,correctLevel:QRCode.CorrectLevel.M})}catch(e){document.getElementById('qr').textContent='(No se pudo dibujar el QR; usá la clave de abajo.)'}</script>
<?php else: ?>
<div class="card"><h2 style="margin-top:0">Verificación en dos pasos <span class="badge b-ok">Activada</span></h2>
<p class="hint">Te quedan <b><?= count($u['recuperacion'] ?? []) ?></b> códigos de recuperación sin usar.</p>
<form method="post" class="bar"><input type="hidden" name="csrf" value="<?= seg_h(seg_csrf()) ?>"><input type="password" name="actual" placeholder="Tu clave actual" required>
<button class="btn gh" name="accion" value="regenerar" type="submit">Generar códigos de recuperación nuevos</button>
<?php if (!$exigir): ?><button class="btn red" name="accion" value="desactivar_2fa" type="submit" onclick="return confirm('¿Desactivar la verificación en dos pasos?')">Desactivar</button><?php endif; ?></form></div>
<?php endif; ?>

<?php if (!$enrolando && !$codigosNuevos): ?>
<div class="card"><h2 style="margin-top:0">Cambiar clave</h2>
<form method="post" style="max-width:380px"><input type="hidden" name="csrf" value="<?= seg_h(seg_csrf()) ?>"><input type="hidden" name="accion" value="clave">
<div class="f"><label>Clave actual</label><input type="password" name="actual" required></div>
<div class="f"><label>Clave nueva (mínimo 10 caracteres)</label><input type="password" name="nueva" minlength="10" required></div>
<div class="f"><label>Repetir clave nueva</label><input type="password" name="nueva2" minlength="10" required></div>
<button class="btn" type="submit">Guardar clave</button></form></div>
<?php endif; ?>
</main></body></html>
