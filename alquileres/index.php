<?php
require __DIR__ . '/lib.php';
alq_requerir();
$contratos = alq_cargar();
$mes = periodo_actual();
$msg = $_GET['msg'] ?? '';

$vigentes = array_values(array_filter($contratos, fn($c) => ($c['estado'] ?? 'vigente') === 'vigente'));
$esperadoMes = ['ARS' => 0, 'USD' => 0]; $cobradoMes = ['ARS' => 0, 'USD' => 0]; $atrasados = []; $ajustes = []; $porVencer = [];
foreach ($vigentes as $c) {
    if (in_array($mes, alq_periodos($c), true)) {
        $mo = alq_moneda($c);
        $esperadoMes[$mo] += alq_monto_periodo($c, $mes);
        $cobradoMes[$mo] += min(alq_cobrado_periodo($c, $mes), alq_monto_periodo($c, $mes));
    }
    if (alq_deuda($c) > 0) $atrasados[] = $c;
    $pa = alq_prox_ajuste($c);
    if ($pa !== null && alq_meses_para($pa) <= 1) $ajustes[] = [$c, $pa];
    $d = alq_dias_fin($c);
    if ($d <= 90) $porVencer[] = [$c, $d];
}
usort($porVencer, fn($a, $b) => $a[1] <=> $b[1]);

$q = mb_strtolower(trim((string)($_GET['q'] ?? '')));
$fe = (string)($_GET['estado'] ?? 'vigente');
$lista = array_values(array_filter($contratos, function ($c) use ($q, $fe) {
    if ($fe !== 'todos' && ($c['estado'] ?? 'vigente') !== $fe) return false;
    if ($q === '') return true;
    $hay = mb_strtolower(($c['propiedad_txt'] ?? '') . ' ' . ($c['inquilino']['nombre'] ?? '') . ' ' . ($c['propietario']['nombre'] ?? '') . ' ' . ($c['id'] ?? ''));
    return str_contains($hay, $q);
}));
usort($lista, fn($a, $b) => strcmp($a['propiedad_txt'] ?? '', $b['propiedad_txt'] ?? ''));

alq_cabecera('Panel', 'index.php');
?>
<h1>Panel de alquileres</h1>
<p class="sub"><?= h(periodo_es($mes)) ?> · <?= count($vigentes) ?> contratos vigentes</p>
<?php if ($msg === 'guardado'): ?><div class="msg ok">Contrato guardado.</div><?php endif; ?>
<?php if ($msg === 'eliminado'): ?><div class="msg ok">Contrato eliminado.</div><?php endif; ?>
<?php if ($msg === 'error'): ?><div class="msg err">No se pudo guardar. Probá de nuevo.</div><?php endif; ?>

<div class="grid g4">
  <div class="kpi"><span>Contratos vigentes</span><b><?= count($vigentes) ?></b></div>
  <div class="kpi ok"><span>Cobrado este mes</span><b><?= h(dinero_multi($cobradoMes)) ?></b></div>
  <div class="kpi <?= $atrasados ? 'bad' : '' ?>"><span>Con deuda (<?= count($atrasados) ?>)</span><b><?= h(dinero_multi(array_reduce($atrasados, function ($t, $c) { $t[alq_moneda($c)] += alq_deuda($c); return $t; }, ['ARS' => 0, 'USD' => 0]))) ?></b></div>
  <?php $interesAcum = array_reduce($atrasados, function ($t, $c) { $t[alq_moneda($c)] += alq_interes_mora_total($c); return $t; }, ['ARS' => 0, 'USD' => 0]); ?>
  <?php if (array_sum($interesAcum) > 0): ?><div class="kpi bad"><span>Interés por mora acumulado</span><b><?= h(dinero_multi($interesAcum)) ?></b></div><?php endif; ?>
  <div class="kpi warn"><span>Falta cobrar este mes</span><b><?= h(dinero_multi(['ARS' => max(0, $esperadoMes['ARS'] - $cobradoMes['ARS']), 'USD' => max(0, $esperadoMes['USD'] - $cobradoMes['USD'])])) ?></b></div>
</div>

<div class="grid g3" style="margin-top:16px">
  <div class="card"><b>⚠ Con pagos atrasados</b>
    <?php if (!$atrasados): ?><p class="hint">Ninguno. Todo al día.</p><?php else: ?><ul class="lista">
    <?php foreach ($atrasados as $c): $im = alq_interes_mora_total($c); ?><li><a href="contrato.php?id=<?= h($c['id']) ?>"><?= h($c['propiedad_txt']) ?></a> — <?= h($c['inquilino']['nombre'] ?? '') ?> <span class="badge b-bad"><?= h(dinero(alq_deuda($c), alq_moneda($c))) ?></span><?php if ($im > 0): ?> <span class="badge b-warn">+<?= h(dinero($im, alq_moneda($c))) ?> mora</span><?php endif; ?><?php if (alq_riesgo_desalojo($c)): ?> <span class="badge b-bad"><?= alq_meses_adeudados($c) ?>+ meses</span><?php endif; ?></li><?php endforeach; ?></ul><?php endif; ?></div>
  <div class="card"><b>↗ Actualizaciones de alquiler</b>
    <?php if (!$ajustes): ?><p class="hint">Ninguna en el próximo mes.</p><?php else: ?><ul class="lista">
    <?php foreach ($ajustes as [$c, $pa]): ?><li><a href="contrato.php?id=<?= h($c['id']) ?>#ajustes"><?= h($c['propiedad_txt']) ?></a> — <?= h($c['ajuste_tipo']) ?> desde <?= h(periodo_es($pa)) ?></li><?php endforeach; ?></ul><?php endif; ?></div>
  <div class="card"><b>⏳ Contratos por vencer (90 días)</b>
    <?php if (!$porVencer): ?><p class="hint">Ninguno.</p><?php else: ?><ul class="lista">
    <?php foreach ($porVencer as [$c, $d]): ?><li><a href="contrato.php?id=<?= h($c['id']) ?>"><?= h($c['propiedad_txt']) ?></a> — <?= $d < 0 ? 'venció hace ' . abs($d) . ' días' : 'vence en ' . $d . ' días' ?> (<?= h(fecha_es(alq_fin($c))) ?>)</li><?php endforeach; ?></ul><?php endif; ?></div>
</div>

<h2>Contratos</h2>
<form class="bar" method="get">
  <input type="search" name="q" placeholder="Buscar propiedad, inquilino o propietario" value="<?= h($_GET['q'] ?? '') ?>">
  <select name="estado" onchange="this.form.submit()">
    <?php foreach (['vigente' => 'Vigentes', 'finalizado' => 'Finalizados', 'rescindido' => 'Rescindidos', 'todos' => 'Todos'] as $k => $v): ?>
      <option value="<?= $k ?>" <?= $fe === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?>
  </select>
  <button class="btn gh" type="submit">Buscar</button>
  <a class="btn" href="contrato.php">+ Nuevo contrato</a>
  <a class="btn gh" href="exportar.php?que=cobros">Exportar cobros</a>
  <a class="btn gh" href="recibo-independiente.php">Generar recibo independiente</a>
</form>
<div class="tablewrap"><table>
<thead><tr><th>Propiedad</th><th>Inquilino</th><th>Propietario</th><th class="r">Alquiler actual</th><th>Este mes</th><th>Próx. actualización</th><th>Vence</th><th></th></tr></thead><tbody>
<?php foreach ($lista as $c):
    $vig = ($c['estado'] ?? 'vigente') === 'vigente';
    $enMes = in_array($mes, alq_periodos($c), true);
    $est = $enMes ? alq_estado_periodo($c, $mes) : '';
    $cls = ['pagado' => 'b-ok', 'parcial' => 'b-warn', 'atrasado' => 'b-bad', 'pendiente' => 'b-mut'][$est] ?? 'b-mut';
    $pa = alq_prox_ajuste($c);
    $dias = alq_dias_fin($c);
?>
<tr>
  <td><a href="contrato.php?id=<?= h($c['id']) ?>"><b><?= h($c['propiedad_txt']) ?></b></a><br><span class="hint"><?= h($c['id']) ?><?= !$vig ? ' · ' . h($c['estado']) : '' ?></span></td>
  <td><?= h($c['inquilino']['nombre'] ?? '') ?><br><span class="hint"><?= h($c['inquilino']['tel'] ?? '') ?></span></td>
  <td><?= h($c['propietario']['nombre'] ?? '') ?></td>
  <td class="r"><?= h(dinero(alq_monto_actual($c), alq_moneda($c))) ?></td>
  <td><?= $est ? '<span class="badge ' . $cls . '">' . h(ucfirst($est)) . '</span>' : '—' ?></td>
  <td><?= $pa ? h(periodo_es($pa)) . ' <span class="hint">(' . h($c['ajuste_tipo']) . ')</span>' : '—' ?></td>
  <td><?= h(fecha_es(alq_fin($c))) ?><?= $vig && $dias <= 90 ? ' <span class="badge ' . ($dias < 0 ? 'b-bad' : 'b-warn') . '">' . ($dias < 0 ? 'vencido' : $dias . ' d') . '</span>' : '' ?></td>
  <td><a class="btn sm gh" href="contrato.php?id=<?= h($c['id']) ?>">Abrir</a></td>
</tr>
<?php endforeach; if (!$lista): ?><tr><td colspan="8" class="hint">No hay contratos para mostrar. Cargá el primero con “+ Nuevo contrato”.</td></tr><?php endif; ?>
</tbody></table></div>
<?php alq_pie();
