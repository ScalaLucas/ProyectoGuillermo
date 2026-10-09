<?php
require __DIR__ . '/recibo_indep_lib.php';
alq_requerir();
$recibos = ri_cargar();
$msg = $_GET['msg'] ?? '';
$cfg = ri_config();

$id = (string)($_GET['id'] ?? '');
$r = $id ? ri_encontrar($recibos, $id) : null;
$nuevo = $r === null;
$r = $r ?? [
    'id' => '', 'serie' => $cfg['serie'], 'numero' => ri_siguiente_numero($recibos, $cfg['serie']),
    'fecha' => date('Y-m-d'), 'nombre' => '', 'dni' => '', 'concepto' => '', 'monto' => '', 'moneda' => 'ARS', 'nota' => '',
];

usort($recibos, fn($a, $b) => strcmp($b['creado'] ?? '', $a['creado'] ?? ''));

alq_cabecera($nuevo ? 'Recibo independiente' : 'Editar recibo ' . h($r['serie'] . '-' . $r['numero']), 'recibo-independiente.php');
?>
<h1>Recibo independiente</h1>
<p class="sub">Un recibo suelto, sin contrato asociado: se carga todo a mano (persona, concepto, monto y fecha) y se imprime con el mismo formato de los recibos del sistema.</p>
<?php $errs = ['guardado' => 'ok:Recibo guardado.', 'borrado' => 'ok:Recibo eliminado.', 'datos' => 'err:Faltan datos obligatorios o son inválidos.', 'error' => 'err:No se pudo guardar. Probá de nuevo.'];
if (isset($errs[$msg])): [$t, $m] = explode(':', $errs[$msg], 2); ?><div class="msg <?= $t ?>"><?= h($m) ?></div><?php endif; ?>

<form class="card" method="post" action="acciones.php">
<input type="hidden" name="csrf" value="<?= h(alq_csrf()) ?>">
<input type="hidden" name="accion" value="recibo_indep_guardar">
<input type="hidden" name="id" value="<?= h($r['id']) ?>">
<div class="grid g4">
  <div class="f"><label>Serie</label><input name="serie" maxlength="3" style="text-transform:uppercase" value="<?= h($r['serie']) ?>" required></div>
  <div class="f"><label>Número</label><input type="number" name="numero" min="1" value="<?= h($r['numero']) ?>" required></div>
  <div class="f"><label>Fecha</label><input type="date" name="fecha" value="<?= h($r['fecha']) ?>" required></div>
  <div class="f"><label>Monto</label><div style="display:flex;gap:6px"><select name="moneda" style="width:92px"><option value="ARS"<?= alq_moneda($r['moneda'] ?? 'ARS') === 'ARS' ? ' selected' : '' ?>>$ pesos</option><option value="USD"<?= alq_moneda($r['moneda'] ?? 'ARS') === 'USD' ? ' selected' : '' ?>>US$ dólares</option></select><input type="number" name="monto" min="0" step="0.01" value="<?= h($r['monto']) ?>" required></div></div>
</div>
<div class="grid g2">
  <div class="f"><label>Recibí de (nombre)</label><input name="nombre" value="<?= h($r['nombre']) ?>" required placeholder="Nombre y apellido, o razón social"></div>
  <div class="f"><label>DNI / CUIT (opcional)</label><input name="dni" value="<?= h($r['dni']) ?>"></div>
</div>
<div class="f"><label>En concepto de</label><input name="concepto" value="<?= h($r['concepto']) ?>" required placeholder="Ej: seña por alquiler de depto en Ramos Mejía"></div>
<div class="f"><label>Nota interna (opcional, no sale impresa)</label><input name="nota" value="<?= h($r['nota']) ?>"></div>
<button class="btn" type="submit" style="margin-top:6px"><?= $nuevo ? 'Generar recibo' : 'Guardar cambios' ?></button>
<?php if (!$nuevo): ?> <a class="btn gh" href="recibo-independiente.php">+ Nuevo recibo</a> <a class="btn gh" target="_blank" href="recibo-independiente-imprimir.php?id=<?= h($r['id']) ?>">🖨 Ver / imprimir</a><?php endif; ?>
</form>

<h2>Recibos generados</h2>
<div class="tablewrap"><table>
<thead><tr><th>N°</th><th>Fecha</th><th>Recibí de</th><th>Concepto</th><th class="r">Monto</th><th></th></tr></thead><tbody>
<?php foreach ($recibos as $x): ?>
<tr>
  <td><b><?= h($x['serie'] . '-' . str_pad((string)$x['numero'], 6, '0', STR_PAD_LEFT)) ?></b></td>
  <td><?= h(fecha_es($x['fecha'])) ?></td>
  <td><?= h($x['nombre']) ?></td>
  <td><?= h($x['concepto']) ?></td>
  <td class="r"><?= h(dinero($x['monto'], $x['moneda'] ?? 'ARS')) ?></td>
  <td class="actions">
    <a class="btn sm gh" target="_blank" href="recibo-independiente-imprimir.php?id=<?= h($x['id']) ?>">Imprimir</a>
    <a class="btn sm gh" href="recibo-independiente.php?id=<?= h($x['id']) ?>">Editar</a>
    <form method="post" action="acciones.php" style="display:inline" onsubmit="return confirm('¿Eliminar este recibo?')">
      <input type="hidden" name="csrf" value="<?= h(alq_csrf()) ?>"><input type="hidden" name="accion" value="recibo_indep_borrar"><input type="hidden" name="id" value="<?= h($x['id']) ?>">
      <button class="btn sm red" type="submit">✕</button>
    </form>
  </td>
</tr>
<?php endforeach; if (!$recibos): ?><tr><td colspan="6" class="hint">Todavía no se generó ningún recibo independiente.</td></tr><?php endif; ?>
</tbody></table></div>
<?php alq_pie();
