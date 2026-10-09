<?php
require __DIR__ . '/avisos_lib.php';
alq_requerir();
$contratos = alq_cargar();
$id = trim((string)($_GET['id'] ?? ''));
$c = $id !== '' ? alq_encontrar($contratos, $id) : null;
$nuevo = $c === null;
$msg = $_GET['msg'] ?? '';
$c = $c ?? ['id' => '', 'propiedad_id' => '', 'propiedad_txt' => '', 'propietario' => [], 'inquilino' => [], 'garante1' => [], 'garante2' => [], 'garantes' => '', 'inicio' => date('Y-m-01'), 'meses' => 24, 'monto_inicial' => '', 'ajuste_tipo' => 'ICL', 'ajuste_cada' => 6, 'ajuste_pct_fijo' => '', 'comision' => 5, 'deposito' => '', 'interes_mora' => '', 'dia_venc' => 10, 'seguro_contratado' => false, 'seguro_poliza' => '', 'seguro_vencimiento' => '', 'notas' => '', 'estado' => 'vigente', 'ajustes' => [], 'cobros' => []];

$mon = alq_moneda($c);
$fmt = fn($n) => dinero($n, $mon);
$props = alq_propiedades();
usort($props, fn($a, $b) => [($a['operacion'] ?? '') === 'Alquiler' ? 0 : 1, $a['direccion'] ?? ''] <=> [($b['operacion'] ?? '') === 'Alquiler' ? 0 : 1, $b['direccion'] ?? '']);
$propActual = $c['propiedad_id'] ? alq_propiedad($c['propiedad_id']) : null;
$foto = $propActual['fotos'][0] ?? null;
$P = fn($k) => h($c['propietario'][$k] ?? '');
$I = fn($k) => h($c['inquilino'][$k] ?? '');
$G1 = fn($k) => h($c['garante1'][$k] ?? '');
$G2 = fn($k) => h($c['garante2'][$k] ?? '');

alq_cabecera($nuevo ? 'Nuevo contrato' : 'Contrato ' . $c['id'], $nuevo ? 'contrato.php' : '');
?>
<h1><?= $nuevo ? 'Nuevo contrato' : h($c['propiedad_txt']) ?></h1>
<p class="sub"><?= $nuevo ? 'Completá los datos del contrato de alquiler.' : 'Contrato ' . h($c['id']) . ' · ' . h(ucfirst($c['estado'])) ?></p>
<?php $errs = ['guardado' => 'ok:Cambios guardados.', 'cobro' => 'ok:Cobro registrado.', 'ajuste' => 'ok:Actualización aplicada.', 'borrado' => 'ok:Registro eliminado.', 'error' => 'err:No se pudo guardar. Probá de nuevo.', 'datos' => 'err:Faltan datos obligatorios o son inválidos.'];
if (isset($_GET['aviso'])) $errs['aviso'] = (['ok' => 'ok:Mail enviado.', 'error' => 'err:El servidor no pudo enviar el mail.', 'sin_dato' => 'err:Esa persona no tiene un mail válido cargado.', 'ejemplo' => 'err:Es un contrato de ejemplo: no se envían mails reales.'][$_GET['aviso']] ?? 'err:No se pudo enviar.'); if (isset($_GET['aviso'])) $msg = 'aviso';
if (isset($errs[$msg])): [$t, $m] = explode(':', $errs[$msg], 2); ?><div class="msg <?= $t ?>"><?= h($m) ?></div><?php endif; ?>

<?php if (!$nuevo && alq_riesgo_desalojo($c)): ?><div class="msg err">⚠ <?= alq_meses_adeudados($c) ?> meses sin pagar: según la cláusula habitual del contrato, esto habilita a iniciar acciones de desalojo.</div><?php endif; ?>

<?php if (!$nuevo):
$mes = periodo_actual(); $pa = alq_prox_ajuste($c); $resc = alq_rescision_anticipada($c);
$ajustesEf = alq_ajustes_efectivos($c);
usort($ajustesEf, fn($a, $b) => strcmp($a['desde'], $b['desde']));
$esAuto = ($c['ajuste_tipo'] ?? '') === 'Porcentaje fijo' && (float)($c['ajuste_pct_fijo'] ?? 0) != 0.0;
$proxMostrar = $pa;
if (!$proxMostrar && $esAuto) { foreach ($ajustesEf as $a) if ($a['desde'] > $mes) { $proxMostrar = $a['desde']; break; } }
?>
<div class="grid g4">
  <div class="kpi"><span>Alquiler actual</span><b><?= h($fmt(alq_monto_actual($c))) ?></b></div>
  <div class="kpi"><span>Vigencia</span><b style="font-size:17px"><?= h(fecha_es($c['inicio'])) ?> → <?= h(fecha_es(alq_fin($c))) ?></b></div>
  <div class="kpi <?= alq_deuda($c) > 0 ? 'bad' : 'ok' ?>"><span>Deuda</span><b><?= h($fmt(alq_deuda($c))) ?></b></div>
  <div class="kpi warn"><span>Próx. actualización</span><b style="font-size:17px"><?= $proxMostrar ? h(periodo_es($proxMostrar)) : '—' ?></b></div>
</div>
<div class="card" style="margin-top:16px">
  <b>Rescisión anticipada (estimado)</b>
  <p class="hint" style="margin:6px 0">Criterio habitual (Art. 1221 CCCN): habilitada a partir del 6º mes de contrato. Es una referencia general — revisá siempre la cláusula puntual de este contrato.</p>
  <p style="margin:4px 0"><?= $resc['meses_transcurridos'] ?> meses transcurridos desde el inicio · <?= $resc['habilitada'] ? '<span class="badge b-ok">Habilitada</span>' : '<span class="badge b-mut">Todavía no habilitada</span>' ?><?php if ($resc['habilitada']): ?> · Multa estimada: <b><?= h($fmt($resc['multa_monto'])) ?></b> (<?= $resc['multa_meses'] == 1.5 ? '1,5' : '1' ?> mes<?= $resc['multa_meses'] == 1.5 ? 'es' : '' ?> de alquiler)<?php endif; ?></p>
</div>
<?php if ($propActual): ?>
<div class="card" style="display:flex;gap:14px;align-items:center;margin-top:16px">
  <?php if ($foto): ?><img src="../<?= h($foto) ?>" alt="" style="width:110px;height:74px;object-fit:cover;border-radius:8px"><?php endif; ?>
  <div><b><?= h(alq_texto_propiedad($propActual)) ?></b><br><span class="hint"><?= h($propActual['operacion'] ?? '') ?> · <?= h($propActual['precio'] ?? '') ?> · <?= (int)($propActual['m2'] ?? 0) ?> m² · <?= ($propActual['activa'] ?? true) === false ? 'suspendida en la web' : 'publicada en la web' ?></span><br>
  <a href="../propiedad.html?id=<?= h($propActual['id']) ?>" target="_blank">Ver en la web ↗</a></div>
</div>
<?php endif; endif; ?>

<h2>Datos del contrato</h2>
<form class="card" method="post" action="acciones.php">
<input type="hidden" name="csrf" value="<?= h(alq_csrf()) ?>"><input type="hidden" name="accion" value="guardar_contrato"><input type="hidden" name="id" value="<?= h($c['id']) ?>">
<div class="grid g2">
  <div class="f"><label>Propiedad (de la web)</label>
    <select name="propiedad_id" id="prop">
      <option value="">— Otra propiedad (no está cargada en la web) —</option>
      <?php foreach ($props as $p): ?><option value="<?= h($p['id']) ?>" <?= $p['id'] === $c['propiedad_id'] ? 'selected' : '' ?>><?= h(($p['operacion'] ?? '') . ' · ' . alq_texto_propiedad($p) . ' · ' . $p['id']) ?></option><?php endforeach; ?>
    </select></div>
  <div class="f"><label>Dirección / descripción (si no está en la web)</label><input name="propiedad_txt" value="<?= h($c['propiedad_txt']) ?>" placeholder="Se completa sola al elegir una propiedad"></div>
</div>
<div class="grid g2">
  <div><h2 style="margin-top:6px">Locador (propietario)</h2>
    <div class="f"><label>Nombre y apellido *</label><input name="prop_nombre" value="<?= $P('nombre') ?>" required></div>
    <div class="grid g2"><div class="f"><label>DNI / CUIT</label><input name="prop_dni" value="<?= $P('dni') ?>"></div><div class="f"><label>Teléfono</label><input name="prop_tel" value="<?= $P('tel') ?>"></div></div>
    <div class="f"><label>Domicilio</label><input name="prop_domicilio" value="<?= $P('domicilio') ?>" placeholder="Domicilio real del propietario"></div>
    <div class="grid g2"><div class="f"><label>Email</label><input type="email" name="prop_email" value="<?= $P('email') ?>"></div><div class="f"><label>CBU / Alias</label><input name="prop_cbu" value="<?= $P('cbu') ?>"></div></div></div>
  <div><h2 style="margin-top:6px">Locatario (inquilino)</h2>
    <div class="f"><label>Nombre y apellido *</label><input name="inq_nombre" value="<?= $I('nombre') ?>" required></div>
    <div class="grid g2"><div class="f"><label>DNI / CUIT</label><input name="inq_dni" value="<?= $I('dni') ?>"></div><div class="f"><label>Teléfono</label><input name="inq_tel" value="<?= $I('tel') ?>"></div></div>
    <div class="f"><label>Domicilio</label><input name="inq_domicilio" value="<?= $I('domicilio') ?>" placeholder="Domicilio real del inquilino"></div>
    <div class="f"><label>Email</label><input type="email" name="inq_email" value="<?= $I('email') ?>"></div></div>
</div>
<h3 style="margin:18px 0 4px">Garante</h3>
<div class="grid g2">
  <div><div class="hint" style="margin-bottom:4px">Garante 1</div>
    <div class="f"><label>Nombre y apellido</label><input name="gar1_nombre" value="<?= $G1('nombre') ?>"></div>
    <div class="grid g2"><div class="f"><label>DNI / CUIT</label><input name="gar1_dni" value="<?= $G1('dni') ?>"></div><div class="f"><label>Teléfono</label><input name="gar1_tel" value="<?= $G1('tel') ?>"></div></div>
    <div class="f"><label>Domicilio (del inmueble en garantía)</label><input name="gar1_domicilio" value="<?= $G1('domicilio') ?>"></div></div>
  <div><div class="hint" style="margin-bottom:4px">Garante 2 (opcional)</div>
    <div class="f"><label>Nombre y apellido</label><input name="gar2_nombre" value="<?= $G2('nombre') ?>"></div>
    <div class="grid g2"><div class="f"><label>DNI / CUIT</label><input name="gar2_dni" value="<?= $G2('dni') ?>"></div><div class="f"><label>Teléfono</label><input name="gar2_tel" value="<?= $G2('tel') ?>"></div></div>
    <div class="f"><label>Domicilio (del inmueble en garantía)</label><input name="gar2_domicilio" value="<?= $G2('domicilio') ?>"></div></div>
</div>
<div class="f"><label>Otras notas sobre la garantía (opcional)</label><textarea name="garantes" rows="2" placeholder="Ej: seguro de caución, garantía propietaria, depósito adicional…"><?= h($c['garantes']) ?></textarea></div>
<h2>Condiciones económicas</h2>
<div class="grid g4">
  <div class="f"><label>Moneda del contrato</label><select name="moneda" id="sel-moneda"><option value="ARS"<?= $mon === 'ARS' ? ' selected' : '' ?>>Pesos ($)</option><option value="USD"<?= $mon === 'USD' ? ' selected' : '' ?>>Dólares (US$)</option></select><small class="hint">Todos los montos de este contrato se cargan y se muestran en esta moneda.</small></div>
</div>
<div class="grid g4">
  <div class="f"><label>Inicio *</label><input type="date" name="inicio" value="<?= h($c['inicio']) ?>" required></div>
  <div class="f"><label>Duración (meses) *</label><input type="number" name="meses" min="1" max="120" value="<?= h($c['meses']) ?>" required></div>
  <div class="f"><label>Alquiler inicial (<span class="sim"><?= $mon === 'USD' ? 'US$' : '$' ?></span>) *</label><input type="number" name="monto_inicial" min="0" step="0.01" value="<?= h($c['monto_inicial']) ?>" required></div>
  <div class="f"><label>Día de vencimiento</label><input type="number" name="dia_venc" min="1" max="28" value="<?= h($c['dia_venc']) ?>"></div>
</div>
<div class="grid g4">
  <div class="f"><label>Actualización por</label><select name="ajuste_tipo"><?php foreach (['ICL', 'IPC', 'CASA', 'Porcentaje fijo', 'Ninguno'] as $t): ?><option <?= $c['ajuste_tipo'] === $t ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></div>
  <div class="f"><label>Cada (meses)</label><input type="number" name="ajuste_cada" min="0" max="24" value="<?= h($c['ajuste_cada']) ?>"></div>
  <div class="f"><label>Comisión de la inmobiliaria (%)</label><input type="number" name="comision" min="0" max="100" step="0.01" value="<?= h($c['comision']) ?>"></div>
  <div class="f"><label>Depósito en garantía (<span class="sim"><?= $mon === 'USD' ? 'US$' : '$' ?></span>)</label><input type="number" name="deposito" min="0" step="0.01" value="<?= h($c['deposito']) ?>"></div>
</div>
<div class="grid g2">
  <div class="f"><label>Interés por mora (% diario)</label><input type="number" name="interes_mora" min="0" max="10" step="0.01" value="<?= h($c['interes_mora'] ?? '') ?>" placeholder="Ej: 1"><small class="hint">Opcional. Se cobra sobre lo que esté impago, desde el día siguiente al vencimiento.</small></div>
  <div class="f"><label>% fijo de ajuste</label><input type="number" name="ajuste_pct_fijo" step="0.01" value="<?= h($c['ajuste_pct_fijo'] ?? '') ?>" placeholder="Ej: 10"><small class="hint">Solo si "Actualización por" es Porcentaje fijo (u otro acuerdo con % conocido). Precarga la variación en cada actualización — se puede corregir ahí.</small></div>
</div>
<h3 style="margin:18px 0 4px">Seguro (opcional)</h3>
<div class="grid g3">
  <div class="f"><label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="seguro_contratado" value="1" <?= !empty($c['seguro_contratado']) ? 'checked' : '' ?>> Seguro contratado</label><small class="hint">Opcional: si el contrato lo exige, cargalo acá.</small></div>
  <div class="f"><label>Compañía / N° de póliza</label><input name="seguro_poliza" value="<?= h($c['seguro_poliza'] ?? '') ?>"></div>
  <div class="f"><label>Vencimiento del seguro</label><input type="date" name="seguro_vencimiento" value="<?= h($c['seguro_vencimiento'] ?? '') ?>"></div>
</div>
<div class="grid g2">
  <div class="f"><label>Estado del contrato</label><select name="estado"><?php foreach (['vigente', 'finalizado', 'rescindido'] as $e): ?><option <?= $c['estado'] === $e ? 'selected' : '' ?>><?= $e ?></option><?php endforeach; ?></select></div>
  <div class="f"><label>Notas internas</label><input name="notas" value="<?= h($c['notas']) ?>"></div>
</div>
<label style="display:block;margin:6px 0 12px"><input type="checkbox" name="sin_avisos" value="1" <?= !empty($c['sin_avisos']) ? 'checked' : '' ?>> No enviar avisos automáticos por mail en este contrato</label>
<button class="btn" type="submit">Guardar contrato</button> <a class="btn gh" href="index.php">Volver</a>
</form>
<script>
document.getElementById('sel-moneda')?.addEventListener('change',function(){var s=this.value==='USD'?'US$':'$';document.querySelectorAll('.sim').forEach(function(e){e.textContent=s;});});
(function(){var s=document.getElementById('prop'),t=document.querySelector('[name=propiedad_txt]');
s.addEventListener('change',function(){var o=s.options[s.selectedIndex];if(s.value){var x=o.text.split(' · ');t.value=x.slice(1,-1).join(' · ');}});})();
</script>

<?php if (!$nuevo): $meses = array_reverse(alq_periodos($c)); ?>
<?php $tieneMora = (float)($c['interes_mora'] ?? 0) > 0; ?>
<h2 id="cobros">Cobros mensuales</h2>
<div class="tablewrap"><table><thead><tr><th>Período</th><th class="r">Alquiler</th><th class="r">Cobrado</th><th>Estado</th><?php if ($tieneMora): ?><th class="r">Interés por mora</th><?php endif; ?><th>Pagos / recibos</th></tr></thead><tbody>
<?php foreach ($meses as $per): $est = alq_estado_periodo($c, $per); $cls = ['pagado' => 'b-ok', 'parcial' => 'b-warn', 'atrasado' => 'b-bad', 'pendiente' => 'b-mut'][$est]; ?>
<tr><td><b><?= h(periodo_es($per)) ?></b></td><td class="r"><?= h($fmt(alq_monto_periodo($c, $per))) ?></td><td class="r"><?= h($fmt(alq_cobrado_periodo($c, $per))) ?></td>
<td><span class="badge <?= $cls ?>"><?= h(ucfirst($est)) ?></span></td>
<?php if ($tieneMora): $int = alq_interes_mora($c, $per); ?>
<td class="r"><?php if ($int > 0): ?><span class="badge b-bad"><?= h($fmt($int)) ?></span> <span class="hint"><?= alq_dias_atraso($c, $per) ?> d</span><?php else: ?>—<?php endif; ?></td>
<?php endif; ?>
<td><?php foreach ($c['cobros'] as $co) if ($co['periodo'] === $per): ?>
  <?php $extrasIt = alq_extras_items($co); $descIt = alq_descuentos_items($co); ?>
  <div><?= h(fecha_es($co['fecha'])) ?> · <?= h($fmt($co['monto'])) ?><?php foreach ($extrasIt as $it): ?> + <?= h($fmt($it['monto'])) ?> <?= h($it['concepto']) ?><?php endforeach; ?><?php foreach ($descIt as $it): ?> − <?= h($fmt($it['monto'])) ?> <?= h($it['concepto']) ?><?php endforeach; ?> · <?= h($co['medio']) ?>
  · <a href="recibo.php?c=<?= h($c['id']) ?>&n=<?= (int)$co['recibo'] ?>" target="_blank">Recibo N° <?= str_pad((string)$co['recibo'], 6, '0', STR_PAD_LEFT) ?></a>
  <?= avisos_botones($c, 'cobro_ok', (string)$co['recibo']) ?>
  <form method="post" action="acciones.php" style="display:inline" onsubmit="return confirm('¿Eliminar este cobro?')"><input type="hidden" name="csrf" value="<?= h(alq_csrf()) ?>"><input type="hidden" name="accion" value="borrar_cobro"><input type="hidden" name="id" value="<?= h($c['id']) ?>"><input type="hidden" name="recibo" value="<?= (int)$co['recibo'] ?>"><button class="btn sm red" type="submit">✕</button></form></div>
<?php endif; ?></td></tr>
<?php endforeach; if (!$meses): ?><tr><td colspan="<?= $tieneMora ? 6 : 5 ?>" class="hint">El contrato todavía no empezó.</td></tr><?php endif; ?></tbody></table></div>

<h3 style="margin:18px 0 8px">Registrar un cobro</h3>
<form class="card" method="post" action="acciones.php"><input type="hidden" name="csrf" value="<?= h(alq_csrf()) ?>"><input type="hidden" name="accion" value="cobro"><input type="hidden" name="id" value="<?= h($c['id']) ?>">
<div class="grid g4">
  <div class="f"><label>Período</label><input type="month" name="periodo" id="cb-per" value="<?= h(periodo_actual()) ?>" required><small class="hint">Se puede elegir un mes futuro: pago anticipado.</small></div>
  <div class="f"><label>Alquiler cobrado (<span class="sim"><?= $mon === 'USD' ? 'US$' : '$' ?></span>)</label><input type="number" name="monto" id="cb-monto" min="0" step="0.01" value="<?= h(alq_monto_periodo($c, periodo_actual())) ?>" required></div>
  <div class="f"><label>Fecha de pago</label><input type="date" name="fecha" value="<?= h(date('Y-m-d')) ?>" required></div>
  <div class="f"><label>Medio</label><select name="medio"><?php foreach (['Efectivo', 'Transferencia', 'Mercado Pago', 'Cheque', 'Otro'] as $m): ?><option><?= $m ?></option><?php endforeach; ?></select></div>
</div>
<div class="grid g2 cobro-2">
  <div class="f"><label>Hasta el período</label><input type="month" name="hasta" id="cb-hasta"><small class="hint">Opcional: si pagó varios meses juntos, cargá arriba el <b>total</b> pagado. Se completa solo con lo que falta de esos meses y se reparte con un recibo por mes.</small></div>
  <div class="f"><label>Nota</label><input name="nota"><small class="hint">Opcional.</small></div>
</div>
<div class="grid g2">
  <div class="f" style="margin-top:4px">
    <label>Conceptos extras (opcional)</label>
    <div id="cb-extras-wrap"></div>
    <button type="button" class="btn sm gh" id="cb-extras-add" style="align-self:start">+ Agregar concepto extra</button>
    <small class="hint">Expensas, servicios, multas, etc. Se suman al alquiler cobrado y aparecen detallados en el recibo.<?php if ($tieneMora): ?> Si el período está atrasado, se agrega acá solo el interés por mora.<?php endif; ?></small>
  </div>
  <div class="f" style="margin-top:4px">
    <label>Descuentos (opcional)</label>
    <div id="cb-desc-wrap"></div>
    <button type="button" class="btn sm gh" id="cb-desc-add" style="align-self:start">+ Agregar descuento</button>
    <small class="hint">Ej: expensas que pagó el inquilino directo al consorcio. Se restan del total cobrado y aparecen detalladas en el recibo.</small>
  </div>
</div>
<?php
$faltas = []; $intereses = [];
for ($p = substr($c['inicio'], 0, 7); $p <= substr(alq_fin($c), 0, 7); $p = periodo_mas($p, 1)) {
    $f = round(max(0, alq_monto_periodo($c, $p) - alq_cobrado_periodo($c, $p)), 2);
    $faltas[$p] = $f < 0.5 ? 0 : $f;
    if ($tieneMora) $intereses[$p] = round(alq_interes_mora($c, $p), 2);
}
?>
<script>
(function(){
var F=<?= json_encode($faltas) ?>,I=<?= json_encode($intereses) ?>;
var p=document.getElementById('cb-per'),h=document.getElementById('cb-hasta'),m=document.getElementById('cb-monto');
var wrap=document.getElementById('cb-extras-wrap'),addBtn=document.getElementById('cb-extras-add');
var dwrap=document.getElementById('cb-desc-wrap'),daddBtn=document.getElementById('cb-desc-add');

function fila(prefix, concepto, monto, auto){
  var row=document.createElement('div');
  row.className='cb-extra-row';
  row.style.cssText='display:flex;gap:8px;margin-bottom:6px;align-items:center';
  if(auto) row.dataset.auto='1';
  row.innerHTML='<input type="text" name="'+prefix+'_concepto[]" placeholder="Ej: expensas" style="flex:2" value="'+(concepto||'').replace(/"/g,'&quot;')+'">'
    +'<input type="number" name="'+prefix+'_monto[]" placeholder="Monto" min="0" step="0.01" style="flex:1" value="'+(monto||'')+'">'
    +'<button type="button" class="btn sm red" aria-label="Quitar">✕</button>';
  row.querySelector('button').addEventListener('click', function(){ row.remove(); });
  return row;
}
addBtn.addEventListener('click', function(){ wrap.appendChild(fila('extra', '', '', false)); });
if(daddBtn) daddBtn.addEventListener('click', function(){ dwrap.appendChild(fila('descuento', '', '', false)); });

function quitarAuto(){ var r=wrap.querySelector('[data-auto="1"]'); if(r) r.remove(); }

function calc(){var d=p.value,a=h.value;if(!d)return;
if(!a||a<=d){
  m.value=(F[d]>0?F[d]:m.defaultValue);
  var interes=I[d]||0;
  var auto=wrap.querySelector('[data-auto="1"]');
  if(interes>0){
    if(auto){ auto.querySelector('[name="extra_monto[]"]').value=interes; }
    else { wrap.appendChild(fila('extra', 'Interés por mora', interes, true)); }
  } else { quitarAuto(); }
  return;
}
quitarAuto();
var t=0,x=d;while(x<=a){t+=F[x]||0;var y=+x.slice(0,4),z=+x.slice(5,7)+1;if(z>12){z=1;y++}x=y+'-'+(z<10?'0':'')+z;}m.value=Math.round(t*100)/100;
}
p.addEventListener('change',calc);h.addEventListener('change',calc);})();
</script>
<button class="btn" type="submit" style="margin-top:6px">Registrar cobro y generar recibo</button>
</form>

<h2 id="ajustes">Actualizaciones del alquiler</h2>
<?php if ($esAuto): ?><p class="hint">Porcentaje fijo: los aumentos de <?= h(number_format((float)$c['ajuste_pct_fijo'], 2, ',', '.')) ?>% cada <?= (int)$c['ajuste_cada'] ?> meses se aplican solos (abajo). Si un período puntual se pactó distinto, cargalo a mano y ese va a tener prioridad.</p>
<?php elseif ($pa): ?><p class="hint">Corresponde actualizar desde <b><?= h(periodo_es($pa)) ?></b> (<?= h($c['ajuste_tipo']) ?>, cada <?= (int)$c['ajuste_cada'] ?> meses). Ingresá el porcentaje de variación del índice del período y el sistema calcula el nuevo alquiler.</p><?php endif; ?>
<?php if ($ajustesEf): ?><div class="tablewrap"><table><thead><tr><th>Desde</th><th>Índice</th><th class="r">Variación</th><th class="r">Alquiler anterior</th><th class="r">Alquiler nuevo</th><th>Nota</th><th>Avisar</th><th></th></tr></thead><tbody>
<?php foreach ($ajustesEf as $a): ?><tr><td><?= h(periodo_es($a['desde'])) ?></td><td><?= h($a['indice']) ?><?php if (!empty($a['auto'])): ?> <span class="badge b-mut">automático</span><?php endif; ?></td><td class="r"><?= h(number_format((float)$a['porcentaje'], 2, ',', '.')) ?> %</td><td class="r"><?= h($fmt($a['monto_anterior'])) ?></td><td class="r"><b><?= h($fmt($a['monto_nuevo'])) ?></b></td><td><?= h($a['nota'] ?? '') ?></td>
<td><?= avisos_botones($c, 'ajuste_ok', $a['desde']) ?></td>
<td><?php if (empty($a['auto'])): ?><form method="post" action="acciones.php" onsubmit="return confirm('¿Eliminar esta actualización?')"><input type="hidden" name="csrf" value="<?= h(alq_csrf()) ?>"><input type="hidden" name="accion" value="borrar_ajuste"><input type="hidden" name="id" value="<?= h($c['id']) ?>"><input type="hidden" name="desde" value="<?= h($a['desde']) ?>"><button class="btn sm red" type="submit">✕</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
<form class="card" method="post" action="acciones.php" style="margin-top:12px"><input type="hidden" name="csrf" value="<?= h(alq_csrf()) ?>"><input type="hidden" name="accion" value="ajuste"><input type="hidden" name="id" value="<?= h($c['id']) ?>">
<div class="grid g4">
  <div class="f"><label>Rige desde (período)</label><input type="month" name="desde" value="<?= h($pa ?? periodo_actual()) ?>" required></div>
  <div class="f"><label>Índice</label><select name="indice"><?php foreach (['ICL', 'IPC', 'CASA', 'Porcentaje fijo', 'Acuerdo'] as $t): ?><option <?= $c['ajuste_tipo'] === $t ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></div>
  <div class="f"><label>Variación acumulada (%)</label><input type="number" name="porcentaje" step="0.01" placeholder="Ej: 32.5" value="<?= h($c['ajuste_pct_fijo'] ?? '') ?>"></div>
  <div class="f"><label>…o nuevo alquiler (<span class="sim"><?= $mon === 'USD' ? 'US$' : '$' ?></span>)</label><input type="number" name="monto_nuevo" min="0" step="0.01" placeholder="Ej: 625600"><small class="hint">Si ya sabés el valor, ponelo acá y dejá el % vacío.</small></div>
  <div class="f"><label>Nota</label><input name="nota"></div>
</div>
<p class="hint">Alquiler actual: <?= h($fmt(alq_monto_actual($c))) ?>. Cargá el <b>%</b> (nuevo valor = alquiler vigente × (1 + variación / 100)) <b>o</b> el <b>nuevo alquiler</b> directo; si cargás los dos, vale el nuevo alquiler.</p>
<button class="btn" type="submit">Aplicar actualización</button></form>

<form method="post" action="acciones.php" style="margin-top:26px" onsubmit="return confirm('¿Eliminar TODO el contrato con sus cobros? No se puede deshacer.')"><input type="hidden" name="csrf" value="<?= h(alq_csrf()) ?>"><input type="hidden" name="accion" value="borrar_contrato"><input type="hidden" name="id" value="<?= h($c['id']) ?>"><button class="btn red sm" type="submit">Eliminar contrato</button></form>
<?php endif; alq_pie();
