<?php
require __DIR__ . '/avisos_lib.php';
alq_requerir();
$contratos = alq_cargar();
$puedeConfig = !seg_usuario_actual() || seg_es_admin();

/* --- WhatsApp: registra el clic y abre el chat con el mensaje escrito --- */
if (isset($_GET['ir'])) {
    $c = alq_encontrar($contratos, (string)($_GET['c'] ?? ''));
    $tipo = (string)($_GET['t'] ?? ''); $dest = (string)($_GET['d'] ?? ''); $ref = (string)($_GET['r'] ?? '');
    if (!$c || !isset(AVISOS_TIPOS[$tipo]) || !in_array($dest, ['inq', 'prop', 'gar1', 'gar2', 'gar_txt'], true) || !avisos_mensaje($tipo, $c, $dest, $ref)) { header('Location: avisos.php'); exit; }
    if (!avisos_es_ejemplo($c)) avisos_log_add(['cid' => $c['id'], 'tipo' => $tipo, 'ref' => $ref, 'dest' => $dest, 'canal' => 'wa', 'estado' => 'ok']);
    header('Location: ' . avisos_wa_url($c, $tipo, $ref, $dest)); exit;
}

/* --- acciones POST --- */
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!alq_csrf_ok()) { header('Location: avisos.php'); exit; }
    $acc = (string)($_POST['accion'] ?? '');
    if ($acc === 'mail') {
        $c = alq_encontrar($contratos, (string)($_POST['cid'] ?? ''));
        $tipo = (string)($_POST['tipo'] ?? ''); $dest = (string)($_POST['dest'] ?? '');
        if ($c && isset(AVISOS_TIPOS[$tipo]) && in_array($dest, ['inq', 'prop', 'gar1', 'gar2', 'gar_txt'], true)) {
            $res = avisos_enviar_mail($c, $tipo, (string)($_POST['ref'] ?? ''), $dest);
            seg_log('aviso_mail_' . $res, $c['id'] . ' ' . $tipo . ' ' . $dest, 'alquileres');
            $back = ($_POST['volver'] ?? '') === 'contrato' ? 'contrato.php?id=' . urlencode($c['id']) . '&aviso=' . $res . '#cobros' : 'avisos.php?m=' . $res;
            header('Location: ' . $back); exit;
        }
    } elseif ($acc === 'config' && $puedeConfig) {
        $cfg = avisos_config();
        $antes = !empty($cfg['auto']);
        $cfg['auto'] = !empty($_POST['auto']);
        if ($cfg['auto'] && !$antes) $cfg['auto_desde'] = periodo_actual();
        $cfg['dias_antes'] = max(0, min(15, (int)($_POST['dias_antes'] ?? 3)));
        $cfg['dias_mora'] = max(1, min(30, (int)($_POST['dias_mora'] ?? 3)));
        $cfg['dias_mora_fiador'] = max(1, min(60, (int)($_POST['dias_mora_fiador'] ?? 5)));
        $cfg['dias_ajuste'] = max(1, min(90, (int)($_POST['dias_ajuste'] ?? 30)));
        $d = trim((string)($_POST['desde'] ?? '')); if (filter_var($d, FILTER_VALIDATE_EMAIL)) $cfg['desde'] = $d;
        $cp = trim((string)($_POST['copia'] ?? '')); $cfg['copia'] = filter_var($cp, FILTER_VALIDATE_EMAIL) ? $cp : '';
        avisos_guardar_config($cfg);
        seg_log('aviso_config', $cfg['auto'] ? 'auto=on' : 'auto=off', 'alquileres');
        header('Location: avisos.php?m=config'); exit;
    } elseif ($acc === 'prueba' && $puedeConfig) {
        $to = trim((string)($_POST['to'] ?? ''));
        $ej = ['id' => 'PRUEBA', 'propiedad_txt' => 'Departamento · Av. San Martín 1234 4°B (Ramos Mejía)', 'inquilino' => ['nombre' => 'Nombre del inquilino'], 'propietario' => ['nombre' => 'Nombre del propietario'],
               'inicio' => date('Y-m-01'), 'meses' => 24, 'monto_inicial' => 450000, 'ajuste_tipo' => 'ICL', 'ajuste_cada' => 6, 'comision' => 5, 'dia_venc' => 10, 'ajustes' => [], 'cobros' => []];
        $m = avisos_mensaje('recordatorio', $ej, 'inq', date('Y-m'));
        $ok = filter_var($to, FILTER_VALIDATE_EMAIL) && avisos_transporte($to, '[PRUEBA] ' . $m[0], $m[1], avisos_config());
        seg_log('aviso_prueba', $ok ? 'ok' : 'error', 'alquileres');
        header('Location: avisos.php?m=' . ($ok ? 'prueba_ok' : 'prueba_err')); exit;
    }
    header('Location: avisos.php'); exit;
}

$cfg = avisos_config();
$log = avisos_log_leer();
$ix = avisos_indice($log);
$scan = avisos_scan($contratos);
$m = (string)($_GET['m'] ?? '');

// Contratos vigentes sin ningún dato de contacto del inquilino: se avisan a mano.
$sinContacto = [];
foreach ($contratos as $c) {
    if (($c['estado'] ?? 'vigente') !== 'vigente' || avisos_es_ejemplo($c)) continue;
    if (!avisos_email($c, 'inq') && !avisos_telefono($c, 'inq')) $sinContacto[] = $c;
}

$urlCron = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'marceloragonesepropiedades.com') . dirname($_SERVER['SCRIPT_NAME']) . '/cron.php?k=' . $cfg['token'];
$estados = ['ok' => ['Enviado', 'b-ok'], 'error' => ['Error', 'b-bad'], 'sin_dato' => ['Sin dato', 'b-warn'], 'ejemplo' => ['Ejemplo', 'b-mut']];

alq_cabecera('Avisos', 'avisos.php');
?>
<h1>Avisos a inquilinos y propietarios</h1>
<p class="sub">Mail automático y WhatsApp con un clic. Los avisos de “cobro registrado” y “actualización aplicada” se mandan desde cada contrato.</p>
<?php
$avisosMsg = ['config' => ['ok', 'Configuración guardada.'], 'prueba_ok' => ['ok', 'Mail de prueba enviado. Revisá la bandeja (y el spam).'], 'prueba_err' => ['err', 'No se pudo enviar la prueba. Revisá la dirección y que exista la casilla remitente en Hostinger.'],
    'ok' => ['ok', 'Mail enviado.'], 'error' => ['err', 'El servidor no pudo enviar el mail.'], 'sin_dato' => ['err', 'Esa persona no tiene un mail válido cargado: avisá por WhatsApp o a mano.'], 'ejemplo' => ['err', 'Es un contrato de ejemplo: no se envían mails reales.']];
if (isset($avisosMsg[$m])): ?><div class="msg <?= $avisosMsg[$m][0] ?>"><?= h($avisosMsg[$m][1]) ?></div><?php endif; ?>

<div class="card av-cfg">
  <div class="av-head"><b>Envío automático por mail</b> <?= $cfg['auto'] ? '<span class="badge b-ok">ACTIVADO</span>' : '<span class="badge b-mut">Desactivado</span>' ?></div>
  <?php if ($puedeConfig): ?>
  <form method="post"><input type="hidden" name="csrf" value="<?= h(alq_csrf()) ?>"><input type="hidden" name="accion" value="config">
    <label class="av-switch"><input type="checkbox" name="auto" value="1" <?= $cfg['auto'] ? 'checked' : '' ?>>
      <span><b>Enviar los mails automáticamente</b><small>Recordatorios, pagos pendientes, cobros y actualizaciones, todos los días a la mañana.</small></span></label>

    <div class="av-sec">Cuándo avisar</div>
    <div class="grid g4">
      <div class="f"><label>Recordatorio</label><input type="number" name="dias_antes" min="0" max="15" value="<?= (int)$cfg['dias_antes'] ?>"><small class="hint">días antes del vencimiento</small></div>
      <div class="f"><label>Pago pendiente</label><input type="number" name="dias_mora" min="1" max="30" value="<?= (int)$cfg['dias_mora'] ?>"><small class="hint">días después del vencimiento</small></div>
      <div class="f"><label>Aviso al fiador</label><input type="number" name="dias_mora_fiador" min="1" max="60" value="<?= (int)($cfg['dias_mora_fiador'] ?? 5) ?>"><small class="hint">días de mora para avisar al fiador</small></div>
      <div class="f"><label>Actualización</label><input type="number" name="dias_ajuste" min="1" max="90" value="<?= (int)$cfg['dias_ajuste'] ?>"><small class="hint">días antes de que cambie el alquiler</small></div>
    </div>

    <div class="av-sec">Remitente</div>
    <div class="grid g2">
      <div class="f"><label>Mail desde el que se envía</label><input type="email" name="desde" value="<?= h($cfg['desde']) ?>"><small class="hint">Tiene que existir como casilla en el hosting.</small></div>
      <div class="f"><label>Copia oculta (opcional)</label><input type="email" name="copia" value="<?= h($cfg['copia']) ?>" placeholder="tu@mail.com"><small class="hint">Recibís una copia de cada aviso enviado.</small></div>
    </div>
    <button class="btn" type="submit">Guardar configuración</button>
  </form>

  <div class="av-sec">Probar el envío</div>
  <form method="post" class="av-prueba"><input type="hidden" name="csrf" value="<?= h(alq_csrf()) ?>"><input type="hidden" name="accion" value="prueba">
    <input type="email" name="to" required placeholder="Mail donde querés recibir la prueba">
    <button class="btn gh" type="submit">Enviar prueba</button>
  </form>

  <details class="av-det"><summary>Tarea diaria (cron del hosting)</summary>
    <p class="hint">Se ejecuta una vez por día en Hostinger. Comando:</p>
    <input class="av-cmd" readonly onclick="this.select()" value='wget -q -O /dev/null "<?= h($urlCron) ?>"'>
    <p class="hint">Antes de activar el envío automático, cargá los cobros ya hechos: si un mes figura impago, se le avisa al inquilino. Un contrato puede excluirse con “No enviar avisos” en su ficha.</p>
  </details>
  <?php else: ?><p class="hint">Solo un administrador puede cambiar la configuración.</p><?php endif; ?>
</div>

<h2>Para avisar hoy (<?= count($scan) ?>)</h2>
<div class="tablewrap"><table>
<thead><tr><th>Contrato</th><th>Aviso</th><th>Para</th><th>Mail</th><th>WhatsApp</th></tr></thead><tbody>
<?php foreach ($scan as $a):
    $c = alq_encontrar($contratos, $a['cid']); if (!$c) continue;
    $ej = avisos_es_ejemplo($c);
    $mail = avisos_email($c, $a['dest']); $tel = avisos_telefono($c, $a['dest']);
    $okM = !empty($ix[avisos_clave($a['cid'], $a['tipo'], $a['ref'], $a['dest'], 'mail')]['ok']);
    $okW = !empty($ix[avisos_clave($a['cid'], $a['tipo'], $a['ref'], $a['dest'], 'wa')]['ok']);
    $p = avisos_persona($c, $a['dest']);
?>
<tr>
  <td><a href="contrato.php?id=<?= h($c['id']) ?>"><b><?= h($c['propiedad_txt']) ?></b></a></td>
  <td><?= h(AVISOS_TIPOS[$a['tipo']]) ?><br><span class="hint"><?= h(periodo_es($a['ref'])) ?></span></td>
  <td><?= h($p['nombre'] ?? '') ?><br><span class="hint"><?= h(avisos_dest_label($a['dest'])) ?></span></td>
  <td><?php if ($okM): ?><span class="badge b-ok">Enviado ✓</span>
    <?php elseif ($mail): ?><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h(alq_csrf()) ?>"><input type="hidden" name="accion" value="mail"><input type="hidden" name="cid" value="<?= h($c['id']) ?>"><input type="hidden" name="tipo" value="<?= h($a['tipo']) ?>"><input type="hidden" name="ref" value="<?= h($a['ref']) ?>"><input type="hidden" name="dest" value="<?= h($a['dest']) ?>"><button class="btn sm gh" type="submit">✉ Enviar ahora</button></form>
    <?php else: ?><span class="hint">sin mail</span><?php endif; ?></td>
  <td><?php if ($okW): ?><span class="badge b-ok">Abierto ✓</span>
    <?php else: ?><a class="btn sm gh" target="_blank" rel="noopener" href="<?= h(avisos_url_wa_click($a['cid'], $a['tipo'], $a['ref'], $a['dest'])) ?>">WhatsApp<?= (!$tel && !$ej) ? ' (sin nº)' : '' ?></a><?php endif; ?></td>
</tr>
<?php endforeach; if (!$scan): ?><tr><td colspan="5" class="hint">Hoy no corresponde avisar nada.</td></tr><?php endif; ?>
</tbody></table></div>

<?php if ($sinContacto): ?>
<h2>Sin mail ni teléfono del inquilino (<?= count($sinContacto) ?>) — avisar a mano</h2>
<div class="card"><ul class="lista"><?php foreach ($sinContacto as $c): ?><li><a href="contrato.php?id=<?= h($c['id']) ?>"><?= h($c['propiedad_txt']) ?></a> — <?= h($c['inquilino']['nombre'] ?? '') ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<h2>Últimos envíos</h2>
<div class="tablewrap"><table>
<thead><tr><th>Fecha</th><th>Contrato</th><th>Aviso</th><th>Para</th><th>Canal</th><th>Resultado</th><th>Usuario</th></tr></thead><tbody>
<?php foreach (array_slice(array_reverse($log), 0, 40) as $e): [$et, $ec] = $estados[$e['estado'] ?? ''] ?? [($e['estado'] ?? ''), 'b-mut']; ?>
<tr><td><?= h($e['ts'] ?? '') ?></td><td><a href="contrato.php?id=<?= h($e['cid'] ?? '') ?>"><?= h($e['cid'] ?? '') ?></a></td><td><?= h(AVISOS_TIPOS[$e['tipo'] ?? ''] ?? '') ?> <span class="hint"><?= h($e['ref'] ?? '') ?></span></td>
<td><?= h(avisos_dest_label((string)($e['dest'] ?? ''))) ?></td><td><?= ($e['canal'] ?? '') === 'wa' ? 'WhatsApp' : 'Mail' ?></td><td><span class="badge <?= $ec ?>"><?= h($et) ?></span></td><td><?= h($e['usuario'] ?? '') ?></td></tr>
<?php endforeach; if (!$log): ?><tr><td colspan="7" class="hint">Todavía no se envió ningún aviso.</td></tr><?php endif; ?>
</tbody></table></div>
<?php alq_pie();
