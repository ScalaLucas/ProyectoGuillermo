<?php
require __DIR__ . '/lib.php';
alq_requerir();
$contratos = alq_cargar();
$mes = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['mes'] ?? '')) ? $_GET['mes'] : periodo_actual();

// Agrupa por propietario: lo cobrado en el mes (fecha de pago) menos la comisión.
$por = [];
foreach ($contratos as $c) {
    foreach ($c['cobros'] ?? [] as $co) {
        if (substr($co['fecha'], 0, 7) !== $mes) continue;
        $k = mb_strtolower(trim($c['propietario']['nombre'] ?? '?'));
        $por[$k]['nombre'] = $c['propietario']['nombre'] ?? '?';
        $por[$k]['cbu'] = $c['propietario']['cbu'] ?? '';
        $com = round((float)$co['monto'] * ((float)($c['comision'] ?? 0) / 100), 2);
        $por[$k]['filas'][] = ['c' => $c, 'co' => $co, 'com' => $com];
    }
}
ksort($por);
alq_cabecera('Liquidaciones', 'liquidacion.php');
?>
<h1>Liquidación a propietarios</h1>
<p class="sub">Lo cobrado en el mes, menos la comisión de la inmobiliaria. Se agrupa por propietario según la fecha de pago.</p>
<form class="bar noprint" method="get"><input type="month" name="mes" value="<?= h($mes) ?>" onchange="this.form.submit()"><button class="btn gh" type="submit">Ver</button> <button class="btn" type="button" onclick="window.print()">🖨 Imprimir</button></form>
<h2 style="margin-top:0"><?= h(periodo_es($mes)) ?></h2>
<?php if (!$por): ?><div class="card hint">No hay cobros registrados en este mes.</div><?php endif; ?>
<?php foreach ($por as $g): $bruto = 0; $comT = 0; $extra = 0; ?>
<div class="card" style="page-break-inside:avoid"><b style="font-size:17px"><?= h($g['nombre']) ?></b><?= $g['cbu'] ? ' <span class="hint">· CBU/Alias: ' . h($g['cbu']) . '</span>' : '' ?>
<div class="tablewrap" style="margin-top:8px"><table><thead><tr><th>Propiedad</th><th>Inquilino</th><th>Período</th><th class="r">Alquiler cobrado</th><th class="r">Comisión</th><th class="r">Neto propietario</th></tr></thead><tbody>
<?php foreach ($g['filas'] as $f): $m = (float)$f['co']['monto']; $bruto += $m; $comT += $f['com']; ?>
<tr><td><?= h($f['c']['propiedad_txt']) ?></td><td><?= h($f['c']['inquilino']['nombre'] ?? '') ?></td><td><?= h(periodo_es($f['co']['periodo'])) ?></td><td class="r"><?= h(dinero($m)) ?></td><td class="r"><?= h(dinero($f['com'])) ?> <span class="hint">(<?= h(rtrim(rtrim(number_format((float)$f['c']['comision'], 2, ',', ''), '0'), ',')) ?>%)</span></td><td class="r"><?= h(dinero($m - $f['com'])) ?></td></tr>
<?php endforeach; ?>
<tr><th colspan="3">Total</th><th class="r"><?= h(dinero($bruto)) ?></th><th class="r"><?= h(dinero($comT)) ?></th><th class="r"><?= h(dinero($bruto - $comT)) ?></th></tr>
</tbody></table></div></div>
<?php endforeach; alq_pie();
