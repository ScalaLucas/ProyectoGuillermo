<?php
require __DIR__ . '/lib.php';
alq_requerir();
$c = alq_encontrar(alq_cargar(), (string)($_GET['c'] ?? ''));
$n = (int)($_GET['n'] ?? 0);
$co = null;
if ($c) foreach ($c['cobros'] ?? [] as $x) if ((int)$x['recibo'] === $n) { $co = $x; break; }
if (!$c || !$co) { http_response_code(404); exit('Recibo no encontrado.'); }

$extrasIt = alq_extras_items($co);
$descIt = alq_descuentos_items($co);
$totalExtras = array_sum(array_column($extrasIt, 'monto'));
$totalDescuentos = array_sum(array_column($descIt, 'monto'));
$total = (float)$co['monto'] + $totalExtras - $totalDescuentos;
$enteros = (int)floor($total);
$cent = (int)round(($total - $enteros) * 100);
$letras = ucfirst(alq_letras($enteros)) . ' pesos' . ($cent ? ' con ' . str_pad((string)$cent, 2, '0', STR_PAD_LEFT) . '/100' : '');

$conceptoLineas = [];
$conceptoLineas[] = 'Alquiler de ' . $c['propiedad_txt'] . ', período ' . periodo_es($co['periodo']) . ': ' . dinero($co['monto']) . '.';
foreach ($extrasIt as $it) $conceptoLineas[] = ucfirst($it['concepto']) . ': ' . dinero($it['monto']) . '.';
foreach ($descIt as $it) $conceptoLineas[] = 'Descuento ' . $it['concepto'] . ': −' . dinero($it['monto']) . '.';
$conceptoLineas[] = 'Forma de pago: ' . $co['medio'] . '.' . (!empty($co['nota']) ? ' ' . $co['nota'] : '');
$conceptoLineas[] = 'Propietario: ' . ($c['propietario']['nombre'] ?? '') . ' — Contrato ' . $c['id'] . '.';

$numero = str_pad((string)$n, 6, '0', STR_PAD_LEFT);
$datosBase = [
    'numero' => $numero, 'fecha' => $co['fecha'], 'nombre' => $c['inquilino']['nombre'] ?? '',
    'dni' => $c['inquilino']['dni'] ?? '', 'domicilio' => $c['inquilino']['domicilio'] ?? '',
    'localidad' => '', 'tel' => $c['inquilino']['tel'] ?? '',
    'monto' => $total, 'letras' => $letras, 'concepto' => implode("\n", $conceptoLineas),
];
$copias = ['ORIGINAL — para el inquilino', 'DUPLICADO — para la inmobiliaria'];
?><!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Recibo <?= $numero ?></title>
<link rel="stylesheet" href="alquileres.css?v=1">
<style><?= alq_recibo_css() ?>
.acc{max-width:660px;margin:18px auto}
</style></head><body>
<div class="acc noprint"><button class="btn" onclick="window.print()">🖨 Imprimir / guardar PDF</button> <?= alq_compartir_wa_boton('recibo-' . $numero . '.pdf') ?> <a class="btn gh" href="contrato.php?id=<?= h($c['id']) ?>#cobros">Volver al contrato</a></div>
<?php foreach ($copias as $cop) echo alq_recibo_render($datosBase + ['copia' => $cop]); ?>
</body></html>
