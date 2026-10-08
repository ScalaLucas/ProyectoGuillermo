<?php
require __DIR__ . '/auth.php';
if (!seg_es_admin()) { header('Location: login.php?a=usuarios'); exit; }
$f = SEG_DATA . '/actividad.jsonl';
$lineas = is_file($f) ? (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
$filtro = strtolower(trim((string)($_GET['u'] ?? '')));
// Solo ingresos y salidas de sesión (no cada acción hecha dentro del panel).
$sesion = ['ingreso' => 'Ingresó', 'salida' => 'Salió', 'ingreso_clave_antigua' => 'Ingresó (clave antigua)', 'salida_clave_antigua' => 'Salió (clave antigua)'];
$rows = [];
foreach (array_reverse($lineas) as $l) {
    $r = json_decode($l, true);
    if (!is_array($r) || !isset($sesion[$r['accion'] ?? ''])) continue;
    if ($filtro !== '' && strtolower($r['usuario'] ?? '') !== $filtro) continue;
    $rows[] = $r;
    if (count($rows) >= 300) break;
}
seg_cabecera('Actividad');
?>
<h1>Registro de actividad</h1>
<p class="sub">Ingresos y salidas de cada usuario. Se muestran los 300 más recientes.</p>
<form class="bar" method="get"><input name="u" placeholder="Filtrar por usuario" value="<?= seg_h($filtro) ?>"><button class="btn gh" type="submit">Filtrar</button></form>
<div class="tablewrap"><table><thead><tr><th>Fecha</th><th>Usuario</th><th>Panel</th><th>Movimiento</th><th>Detalle</th><th>IP</th></tr></thead><tbody>
<?php foreach ($rows as $r): $salio = str_starts_with($r['accion'] ?? '', 'salida'); ?>
<tr><td><?= seg_h($r['fecha']) ?></td><td><?= seg_h($r['usuario']) ?></td><td><?= seg_h($r['panel']) ?></td><td><span class="badge <?= $salio ? 'b-mut' : 'b-ok' ?>"><?= seg_h($sesion[$r['accion']]) ?></span></td><td><?= seg_h($r['detalle']) ?></td><td><?= seg_h($r['ip']) ?></td></tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="6" class="hint">Sin movimientos todavía.</td></tr><?php endif; ?></tbody></table></div>
</main></body></html>
