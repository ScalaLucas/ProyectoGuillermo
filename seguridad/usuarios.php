<?php
require __DIR__ . '/auth.php';
if (!seg_es_admin()) { header('Location: login.php?a=usuarios'); exit; }
$yo = $_SESSION['usuario'];
$us = seg_usuarios();
$cfg = seg_config();
$msg = ''; $temp = null;

function seg_clave_temporal(): string { return substr(strtr(base64_encode(random_bytes(12)), '+/=', 'xyz'), 0, 12); }
function seg_admins_activos(array $us): int { return count(array_filter($us, fn($x) => ($x['rol'] ?? '') === 'admin' && ($x['activo'] ?? true))); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string)($_POST['accion'] ?? '');
    $objetivo = strtolower(trim((string)($_POST['objetivo'] ?? '')));
    if (!seg_csrf_ok()) { $msg = 'err:La página quedó desactualizada. Recargala.'; }
    elseif ($a === 'crear') {
        $nuevo = strtolower(trim((string)($_POST['usuario'] ?? '')));
        $paneles = array_values(array_intersect((array)($_POST['paneles'] ?? []), ['propiedades', 'alquileres']));
        if (!preg_match('/^[a-z0-9._-]{3,30}$/', $nuevo)) $msg = 'err:Usuario inválido (3 a 30 caracteres: letras, números, punto, guion).';
        elseif (isset($us[$nuevo])) $msg = 'err:Ese usuario ya existe.';
        elseif (trim((string)($_POST['nombre'] ?? '')) === '') $msg = 'err:Falta el nombre.';
        else {
            $temp = seg_clave_temporal();
            $rol = ($_POST['rol'] ?? '') === 'admin' ? 'admin' : 'operador';
            $us[$nuevo] = ['usuario' => $nuevo, 'nombre' => mb_substr(trim((string)$_POST['nombre']), 0, 80), 'hash' => password_hash($temp, PASSWORD_DEFAULT),
                'rol' => $rol, 'paneles' => $paneles, 'activo' => true, 'totp_activo' => false, 'totp_secreto' => '', 'totp_last' => 0,
                'recuperacion' => [], 'creado' => date('Y-m-d H:i:s'), 'ultimo_acceso' => '', 'cambiar_clave' => true];
            seg_guardar_usuarios($us);
            seg_log('usuario_creado', $nuevo . ' (' . $rol . ')');
            $msg = 'ok:Usuario creado. Pasale la clave temporal (se muestra abajo una sola vez); al ingresar va a activar su 2FA y cambiar la clave.';
            $temp = [$nuevo, $temp];
        }
    }
    elseif ($a === 'config') {
        $cfg['exigir_2fa'] = !empty($_POST['exigir_2fa']);
        $cfg['legacy_propiedades'] = !empty($_POST['legacy_propiedades']);
        $cfg['legacy_alquileres'] = !empty($_POST['legacy_alquileres']);
        seg_guardar_config($cfg);
        seg_log('config_cambiada', json_encode($cfg));
        $msg = 'ok:Configuración guardada.';
    }
    elseif (isset($us[$objetivo])) {
        if ($a === 'reset_clave') { $t = seg_clave_temporal(); $us[$objetivo]['hash'] = password_hash($t, PASSWORD_DEFAULT); $us[$objetivo]['cambiar_clave'] = true; seg_guardar_usuarios($us); seg_log('clave_restablecida', $objetivo); $temp = [$objetivo, $t]; $msg = 'ok:Clave restablecida. Pasale esta clave temporal.'; }
        elseif ($a === 'reset_2fa') { $us[$objetivo]['totp_activo'] = false; $us[$objetivo]['totp_secreto'] = ''; $us[$objetivo]['recuperacion'] = []; $us[$objetivo]['totp_last'] = 0; seg_guardar_usuarios($us); seg_log('2fa_restablecido', $objetivo); $msg = 'ok:2FA restablecido: en su próximo ingreso va a configurarlo de nuevo.'; }
        elseif ($a === 'toggle') {
            if ($objetivo === $yo) $msg = 'err:No podés desactivar tu propio usuario.';
            elseif (($us[$objetivo]['activo'] ?? true) && ($us[$objetivo]['rol'] ?? '') === 'admin' && seg_admins_activos($us) <= 1) $msg = 'err:Tiene que quedar al menos un administrador activo.';
            else { $us[$objetivo]['activo'] = !($us[$objetivo]['activo'] ?? true); seg_guardar_usuarios($us); seg_log($us[$objetivo]['activo'] ? 'usuario_activado' : 'usuario_desactivado', $objetivo); $msg = 'ok:Listo.'; }
        }
        elseif ($a === 'eliminar') {
            if ($objetivo === $yo) $msg = 'err:No podés eliminar tu propio usuario.';
            elseif (($us[$objetivo]['rol'] ?? '') === 'admin' && ($us[$objetivo]['activo'] ?? true) && seg_admins_activos($us) <= 1) $msg = 'err:Tiene que quedar al menos un administrador activo.';
            else { unset($us[$objetivo]); seg_guardar_usuarios($us); seg_log('usuario_eliminado', $objetivo); $msg = 'ok:Usuario eliminado.'; }
        }
    }
    $us = seg_usuarios();
}

seg_cabecera('Usuarios');
?>
<h1>Usuarios y seguridad</h1>
<p class="sub">Cada persona tiene su usuario, su clave y su código del celular. Todo lo que hace queda registrado en “Actividad”.</p>
<?php if ($msg): [$t, $m] = explode(':', $msg, 2); ?><div class="msg <?= $t ?>"><?= seg_h($m) ?></div><?php endif; ?>
<?php if (is_array($temp)): ?><div class="card" style="border:2px solid var(--gold)"><b>Usuario:</b> <code><?= seg_h($temp[0]) ?></code> &nbsp; <b>Clave temporal:</b> <code style="font-size:18px"><?= seg_h($temp[1]) ?></code><br><span class="hint">Se muestra una sola vez. Pasásela en persona o por un medio privado.</span></div><?php endif; ?>

<div class="tablewrap"><table><thead><tr><th>Usuario</th><th>Nombre</th><th>Rol</th><th>Paneles</th><th>2FA</th><th>Último ingreso</th><th>Estado</th><th></th></tr></thead><tbody>
<?php foreach ($us as $k => $x): $act = $x['activo'] ?? true; ?>
<tr><td><b><?= seg_h($k) ?></b></td><td><?= seg_h($x['nombre']) ?></td><td><?= ($x['rol'] ?? '') === 'admin' ? 'Administrador' : 'Operador' ?></td>
<td><?= ($x['rol'] ?? '') === 'admin' ? 'Todos' : seg_h(implode(', ', $x['paneles'] ?? []) ?: '—') ?></td>
<td><?= !empty($x['totp_activo']) ? '<span class="badge b-ok">Activo</span>' : '<span class="badge b-warn">Pendiente</span>' ?></td>
<td><?= seg_h($x['ultimo_acceso'] ?: '—') ?></td><td><?= $act ? '<span class="badge b-ok">Activo</span>' : '<span class="badge b-mut">Desactivado</span>' ?></td>
<td><form method="post" style="display:flex;gap:4px;flex-wrap:wrap"><input type="hidden" name="csrf" value="<?= seg_h(seg_csrf()) ?>"><input type="hidden" name="objetivo" value="<?= seg_h($k) ?>">
<button class="btn sm gh" name="accion" value="reset_clave" onclick="return confirm('¿Restablecer la clave de <?= seg_h($k) ?>?')">Nueva clave</button>
<button class="btn sm gh" name="accion" value="reset_2fa" onclick="return confirm('¿Restablecer el 2FA de <?= seg_h($k) ?>?')">Reset 2FA</button>
<?php if ($k !== $yo): ?><button class="btn sm gh" name="accion" value="toggle"><?= $act ? 'Desactivar' : 'Activar' ?></button><button class="btn sm red" name="accion" value="eliminar" onclick="return confirm('¿Eliminar a <?= seg_h($k) ?>?')">✕</button><?php endif; ?></form></td></tr>
<?php endforeach; ?></tbody></table></div>

<h2>Agregar usuario</h2>
<form class="card" method="post"><input type="hidden" name="csrf" value="<?= seg_h(seg_csrf()) ?>"><input type="hidden" name="accion" value="crear">
<div class="grid g4">
  <div class="f"><label>Usuario</label><input name="usuario" placeholder="ej: laura" required></div>
  <div class="f"><label>Nombre completo</label><input name="nombre" required></div>
  <div class="f"><label>Rol</label><select name="rol"><option value="operador">Operador (solo lo que se le habilite)</option><option value="admin">Administrador (todo + usuarios)</option></select></div>
  <div class="f"><label>Puede entrar a</label><label style="text-transform:none;letter-spacing:0;font-weight:400"><input type="checkbox" name="paneles[]" value="propiedades" checked style="width:auto"> Propiedades</label><label style="text-transform:none;letter-spacing:0;font-weight:400"><input type="checkbox" name="paneles[]" value="alquileres" checked style="width:auto"> Alquileres</label></div>
</div><button class="btn" type="submit">Crear usuario</button></form>

<h2>Opciones de seguridad</h2>
<form class="card" method="post"><input type="hidden" name="csrf" value="<?= seg_h(seg_csrf()) ?>"><input type="hidden" name="accion" value="config">
<p><label style="text-transform:none;letter-spacing:0;font-weight:600"><input type="checkbox" name="exigir_2fa" <?= $cfg['exigir_2fa'] ? 'checked' : '' ?> style="width:auto"> Exigir verificación en dos pasos a todos</label></p>
<p><label style="text-transform:none;letter-spacing:0;font-weight:600"><input type="checkbox" name="legacy_propiedades" <?= $cfg['legacy_propiedades'] ? 'checked' : '' ?> style="width:auto"> Permitir además la <u>clave antigua</u> del panel de Propiedades (sin usuario ni 2FA)</label></p>
<p><label style="text-transform:none;letter-spacing:0;font-weight:600"><input type="checkbox" name="legacy_alquileres" <?= $cfg['legacy_alquileres'] ? 'checked' : '' ?> style="width:auto"> Permitir además la <u>clave antigua</u> del panel de Alquileres (sin usuario ni 2FA)</label></p>
<p class="hint"><b>Importante:</b> dejá las claves antiguas activadas solo mientras probás. Cuando confirmes que todos pueden entrar con su usuario y 2FA, <b>destildalas</b>: así nadie puede saltearse la verificación.</p>
<button class="btn" type="submit">Guardar opciones</button></form>
</main></body></html>
