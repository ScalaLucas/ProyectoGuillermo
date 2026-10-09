<?php
require __DIR__ . '/lib.php';
alq_requerir();
$que = $_GET['que'] ?? 'contratos';
$contratos = alq_cargar();
$mes = periodo_actual();

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="alquileres-' . $que . '-' . date('Y-m-d') . '.csv"');
echo "\xEF\xBB\xBF"; // BOM: Excel abre bien los acentos
$out = fopen('php://output', 'w');
$sep = ';';

if ($que === 'cobros') {
    fputcsv($out, ['Recibo', 'Contrato', 'Propiedad', 'Inquilino', 'Propietario', 'Período', 'Fecha de pago', 'Alquiler cobrado', 'Otros cobrados', 'Concepto otros', 'Descuentos', 'Concepto descuentos', 'Medio', 'Nota', 'Moneda'], $sep);
    foreach ($contratos as $c) foreach ($c['cobros'] ?? [] as $co) {
        $descIt = alq_descuentos_items($co);
        $descTotal = array_sum(array_column($descIt, 'monto'));
        $descConcepto = implode(', ', array_column($descIt, 'concepto'));
        fputcsv($out, [$co['recibo'], $c['id'], $c['propiedad_txt'], $c['inquilino']['nombre'] ?? '', $c['propietario']['nombre'] ?? '', $co['periodo'], $co['fecha'], $co['monto'], $co['extras'] ?? 0, $co['extras_concepto'] ?? '', $descTotal, $descConcepto, $co['medio'], $co['nota'] ?? '', alq_moneda($c)], $sep);
    }
} else {
    fputcsv($out, ['Contrato', 'ID propiedad web', 'Propiedad', 'Estado', 'Propietario', 'Tel. propietario', 'Inquilino', 'DNI inquilino', 'Tel. inquilino', 'Inicio', 'Fin', 'Meses', 'Alquiler inicial', 'Alquiler actual', 'Actualiza por', 'Cada (meses)', 'Próx. actualización', 'Comisión %', 'Depósito', 'Deuda actual', 'Moneda'], $sep);
    foreach ($contratos as $c) {
        $pa = alq_prox_ajuste($c);
        fputcsv($out, [$c['id'], $c['propiedad_id'], $c['propiedad_txt'], $c['estado'], $c['propietario']['nombre'] ?? '', $c['propietario']['tel'] ?? '', $c['inquilino']['nombre'] ?? '', $c['inquilino']['dni'] ?? '', $c['inquilino']['tel'] ?? '', $c['inicio'], alq_fin($c), $c['meses'], $c['monto_inicial'], alq_monto_actual($c), $c['ajuste_tipo'], $c['ajuste_cada'], $pa ?? '', $c['comision'], $c['deposito'], alq_deuda($c), alq_moneda($c)], $sep);
    }
}
fclose($out);
