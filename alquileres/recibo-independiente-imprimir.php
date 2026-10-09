<?php
require __DIR__ . '/recibo_indep_lib.php';
alq_requerir();
$r = ri_encontrar(ri_cargar(), (string)($_GET['id'] ?? ''));
if (!$r) { http_response_code(404); exit('Recibo no encontrado.'); }

$total = (float)$r['monto'];
$numero = $r['serie'] . '-' . str_pad((string)$r['numero'], 6, '0', STR_PAD_LEFT);
$datosBase = [
    'numero' => $numero, 'fecha' => $r['fecha'], 'nombre' => $r['nombre'], 'dni' => $r['dni'] ?: '',
    'domicilio' => '', 'localidad' => '', 'tel' => '',
    'monto' => $total, 'moneda' => alq_moneda($r['moneda'] ?? 'ARS'), 'letras' => ri_monto_letras($total, alq_moneda($r['moneda'] ?? 'ARS')), 'concepto' => $r['concepto'],
];
$copias = ['ORIGINAL', 'DUPLICADO'];
?><!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Recibo <?= h($numero) ?></title>
<link rel="stylesheet" href="alquileres.css?v=1">
<style><?= alq_recibo_css() ?>
.acc{max-width:660px;margin:18px auto}
</style></head><body>
<div class="acc noprint"><button class="btn" onclick="window.print()">🖨 Imprimir / guardar PDF</button> <?= alq_compartir_wa_boton('recibo-' . $numero . '.pdf') ?> <a class="btn gh" href="recibo-independiente.php?id=<?= h($r['id']) ?>">Editar</a> <a class="btn gh" href="recibo-independiente.php">Volver</a></div>
<?php foreach ($copias as $cop) echo alq_recibo_render($datosBase + ['copia' => $cop]); ?>
</body></html>
