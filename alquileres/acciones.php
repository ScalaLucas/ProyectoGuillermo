<?php
require __DIR__ . '/avisos_lib.php';
alq_requerir();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !alq_csrf_ok()) { header('Location: index.php'); exit; }

function t(string $k, int $max = 300): string { return mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max); }
function n(string $k): float { return (float)str_replace(',', '.', (string)($_POST[$k] ?? 0)); }

$accion = t('accion', 40);
$id = t('id', 20);
seg_log('alq_' . $accion, $id, 'alquileres');
$contratos = alq_cargar();
$idx = null;
foreach ($contratos as $i => $c) if (($c['id'] ?? '') === $id) { $idx = $i; break; }
$volver = fn(string $msg, string $ancla = '') => header('Location: contrato.php?id=' . urlencode($id) . '&msg=' . $msg . $ancla) or exit;

switch ($accion) {
    case 'guardar_contrato':
        $inicio = t('inicio', 10);
        $meses = (int)n('meses');
        $monto = n('monto_inicial');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $inicio) || $meses < 1 || $monto <= 0 || t('prop_nombre') === '' || t('inq_nombre') === '') {
            header('Location: contrato.php' . ($idx !== null ? '?id=' . urlencode($id) . '&msg=datos' : '?msg=datos')); exit;
        }
        $propId = t('propiedad_id', 20);
        $txt = t('propiedad_txt', 200);
        if ($propId !== '' && ($p = alq_propiedad($propId))) $txt = $txt !== '' ? $txt : alq_texto_propiedad($p);
        $nuevo = [
            'id' => $idx !== null ? $id : alq_nuevo_id($contratos),
            'propiedad_id' => $propId,
            'propiedad_txt' => $txt !== '' ? $txt : 'Sin dirección',
            'propietario' => ['nombre' => t('prop_nombre', 160), 'dni' => t('prop_dni', 30), 'tel' => t('prop_tel', 40), 'domicilio' => t('prop_domicilio', 200), 'email' => t('prop_email', 160), 'cbu' => t('prop_cbu', 60)],
            'inquilino' => ['nombre' => t('inq_nombre', 160), 'dni' => t('inq_dni', 30), 'tel' => t('inq_tel', 40), 'domicilio' => t('inq_domicilio', 200), 'email' => t('inq_email', 160)],
            'garante1' => ['nombre' => t('gar1_nombre', 160), 'dni' => t('gar1_dni', 30), 'tel' => t('gar1_tel', 40), 'domicilio' => t('gar1_domicilio', 200)],
            'garante2' => ['nombre' => t('gar2_nombre', 160), 'dni' => t('gar2_dni', 30), 'tel' => t('gar2_tel', 40), 'domicilio' => t('gar2_domicilio', 200)],
            'garantes' => t('garantes', 600),
            'inicio' => $inicio, 'meses' => $meses, 'moneda' => alq_moneda(t('moneda', 3)), 'monto_inicial' => $monto,
            'ajuste_tipo' => in_array(t('ajuste_tipo'), ['ICL', 'IPC', 'CASA', 'Porcentaje fijo', 'Ninguno'], true) ? t('ajuste_tipo') : 'Ninguno',
            'ajuste_cada' => max(0, min(24, (int)n('ajuste_cada'))),
            'ajuste_pct_fijo' => t('ajuste_pct_fijo', 20) === '' ? '' : max(-99, min(1000, n('ajuste_pct_fijo'))),
            'comision' => max(0, min(100, n('comision'))),
            'deposito' => max(0, n('deposito')),
            'interes_mora' => max(0, min(10, n('interes_mora'))),
            'dia_venc' => max(1, min(28, (int)n('dia_venc') ?: 10)),
            'seguro_contratado' => !empty($_POST['seguro_contratado']),
            'seguro_poliza' => t('seguro_poliza', 120),
            'seguro_vencimiento' => preg_match('/^\d{4}-\d{2}-\d{2}$/', t('seguro_vencimiento', 10)) ? t('seguro_vencimiento', 10) : '',
            'notas' => t('notas', 400),
            'sin_avisos' => !empty($_POST['sin_avisos']),
            'estado' => in_array(t('estado'), ['vigente', 'finalizado', 'rescindido'], true) ? t('estado') : 'vigente',
            'ajustes' => $idx !== null ? ($contratos[$idx]['ajustes'] ?? []) : [],
            'cobros' => $idx !== null ? ($contratos[$idx]['cobros'] ?? []) : [],
        ];
        if ($idx !== null) $contratos[$idx] = $nuevo; else $contratos[] = $nuevo;
        $id = $nuevo['id'];
        if (!alq_guardar($contratos)) { header('Location: index.php?msg=error'); exit; }
        header('Location: ' . ($idx === null ? 'contrato.php?id=' . urlencode($id) . '&msg=guardado' : 'contrato.php?id=' . urlencode($id) . '&msg=guardado')); exit;

    case 'cobro':
        if ($idx === null) break;
        $per = t('periodo', 7); $monto = n('monto'); $fecha = t('fecha', 10);
        if (!preg_match('/^\d{4}-\d{2}$/', $per) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || $monto <= 0) $volver('datos', '#cobros');
        // Un pago puede cubrir varios meses: se reparte del período inicial hasta "hasta", un recibo por mes
        // (cada mes recibe lo que le falta; el último se lleva el resto).
        $hasta = t('hasta', 7);
        $pers = [$per];
        if (preg_match('/^\d{4}-\d{2}$/', $hasta) && $hasta > $per) for ($p = periodo_mas($per, 1); $p <= $hasta && count($pers) < 36; $p = periodo_mas($p, 1)) $pers[] = $p;
        else { // sin "hasta": si el monto supera lo que debe el período, el excedente se aplica a los meses siguientes impagos
            $falta = fn($pp) => max(0, alq_monto_periodo($contratos[$idx], $pp) - alq_cobrado_periodo($contratos[$idx], $pp));
            $acum = $falta($per); $finP = substr(alq_fin($contratos[$idx]), 0, 7); $p = $per;
            while ($acum < $monto - 0.5 && ($p = periodo_mas($p, 1)) <= $finP && count($pers) < 36) { $pers[] = $p; $acum += $falta($p); }
        }
        $parseItems = function (string $prefix): array {
            $out = [];
            foreach (($_POST[$prefix . '_concepto'] ?? []) as $k2 => $concepto) {
                $concepto = mb_substr(trim((string)$concepto), 0, 60);
                $montoIt = (float)str_replace(',', '.', (string)($_POST[$prefix . '_monto'][$k2] ?? 0));
                if ($concepto !== '' && $montoIt > 0) $out[] = ['concepto' => $concepto, 'monto' => round($montoIt, 2)];
            }
            return $out;
        };
        $extrasItems = $parseItems('extra');
        $extras = round(array_sum(array_column($extrasItems, 'monto')), 2);
        $extrasConceptoTxt = mb_substr(implode(', ', array_column($extrasItems, 'concepto')), 0, 120);
        $descuentosItems = $parseItems('descuento');
        $descuentos = round(array_sum(array_column($descuentosItems, 'monto')), 2);
        $restante = $monto; $nuevos = [];
        foreach ($pers as $k => $p) {
            $ult = $k === count($pers) - 1;
            $falta = max(0, alq_monto_periodo($contratos[$idx], $p) - alq_cobrado_periodo($contratos[$idx], $p));
            $asig = $ult ? $restante : min($falta, $restante);
            if ($asig <= 0) continue;
            $rec = alq_siguiente_recibo($contratos);
            $contratos[$idx]['cobros'][] = [
                'periodo' => $p, 'monto' => round($asig, 2), 'fecha' => $fecha,
                'medio' => t('medio', 30), 'extras' => $nuevos ? 0 : $extras, 'extras_concepto' => $nuevos ? '' : $extrasConceptoTxt,
                'extras_items' => $nuevos ? [] : $extrasItems, 'descuentos_items' => $nuevos ? [] : $descuentosItems,
                'nota' => t('nota', 200), 'recibo' => $rec,
            ];
            $nuevos[] = (string)$rec; $restante -= $asig;
        }
        if (!alq_guardar($contratos)) $volver('error');
        foreach ($nuevos as $r) { try { avisos_evento($contratos[$idx], 'cobro_ok', $r); } catch (Throwable $e) {} }
        $volver('cobro', '#cobros');

    case 'borrar_cobro':
        if ($idx === null) break;
        $r = (int)n('recibo');
        $contratos[$idx]['cobros'] = array_values(array_filter($contratos[$idx]['cobros'] ?? [], fn($co) => (int)$co['recibo'] !== $r));
        if (!alq_guardar($contratos)) $volver('error');
        $volver('borrado', '#cobros');

    case 'ajuste':
        if ($idx === null) break;
        $desde = t('desde', 7); $pct = n('porcentaje'); $montoDirecto = n('monto_nuevo');
        if (!preg_match('/^\d{4}-\d{2}$/', $desde) || ($montoDirecto <= 0 && (t('porcentaje') === '' || $pct <= -100))) $volver('datos', '#ajustes');
        $c = $contratos[$idx];
        $anterior = alq_monto_periodo($c, periodo_mas($desde, -1));
        // Si ya se sabe el alquiler nuevo, se usa tal cual y la variación se deduce de él.
        if ($montoDirecto > 0) $pct = $anterior > 0 ? round(($montoDirecto / $anterior - 1) * 100, 4) : 0;
        $contratos[$idx]['ajustes'] = array_values(array_filter($c['ajustes'] ?? [], fn($a) => $a['desde'] !== $desde));
        $contratos[$idx]['ajustes'][] = [
            'desde' => $desde, 'indice' => t('indice', 30), 'porcentaje' => $pct,
            'monto_anterior' => $anterior, 'monto_nuevo' => $montoDirecto > 0 ? round($montoDirecto, 2) : round($anterior * (1 + $pct / 100), 2), 'nota' => t('nota', 200),
        ];
        usort($contratos[$idx]['ajustes'], fn($a, $b) => strcmp($a['desde'], $b['desde']));
        if (!alq_guardar($contratos)) $volver('error');
        try { avisos_evento($contratos[$idx], 'ajuste_ok', $desde); } catch (Throwable $e) {}
        $volver('ajuste', '#ajustes');

    case 'borrar_ajuste':
        if ($idx === null) break;
        $d = t('desde', 7);
        $contratos[$idx]['ajustes'] = array_values(array_filter($contratos[$idx]['ajustes'] ?? [], fn($a) => $a['desde'] !== $d));
        if (!alq_guardar($contratos)) $volver('error');
        $volver('borrado', '#ajustes');

    case 'recibo_indep_guardar': {
        require_once __DIR__ . '/recibo_indep_lib.php';
        $recibos = ri_cargar();
        $ridx = null;
        foreach ($recibos as $i2 => $x) if (($x['id'] ?? '') === $id) { $ridx = $i2; break; }
        $serie = mb_strtoupper(t('serie', 3));
        $numero = (int)n('numero');
        $fecha = t('fecha', 10);
        $monto = n('monto');
        $nombre = t('nombre', 160);
        $concepto = t('concepto', 300);
        if ($serie === '' || $numero < 1 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || $monto <= 0 || $nombre === '' || $concepto === '') {
            header('Location: recibo-independiente.php' . ($ridx !== null ? '?id=' . urlencode($id) : '') . '&msg=datos'); exit;
        }
        $nuevoR = [
            'id' => $ridx !== null ? $id : ri_nuevo_id($recibos),
            'serie' => $serie, 'numero' => $numero, 'fecha' => $fecha, 'monto' => $monto, 'moneda' => alq_moneda(t('moneda', 3)),
            'nombre' => $nombre, 'dni' => t('dni', 30), 'concepto' => $concepto, 'nota' => t('nota', 300),
            'creado' => $ridx !== null ? ($recibos[$ridx]['creado'] ?? date('Y-m-d H:i:s')) : date('Y-m-d H:i:s'),
        ];
        if ($ridx !== null) $recibos[$ridx] = $nuevoR; else $recibos[] = $nuevoR;
        if (!ri_guardar($recibos)) { header('Location: recibo-independiente.php?msg=error'); exit; }
        $cfg = ri_config(); $cfg['serie'] = $serie; ri_guardar_config($cfg);
        header('Location: recibo-independiente.php?id=' . urlencode($nuevoR['id']) . '&msg=guardado'); exit;
    }

    case 'recibo_indep_borrar': {
        require_once __DIR__ . '/recibo_indep_lib.php';
        $recibos = array_values(array_filter(ri_cargar(), fn($x) => ($x['id'] ?? '') !== $id));
        if (!ri_guardar($recibos)) { header('Location: recibo-independiente.php?msg=error'); exit; }
        header('Location: recibo-independiente.php?msg=borrado'); exit;
    }

    case 'borrar_contrato':
        if ($idx === null) break;
        array_splice($contratos, $idx, 1);
        if (!alq_guardar($contratos)) { header('Location: index.php?msg=error'); exit; }
        header('Location: index.php?msg=eliminado'); exit;
}
header('Location: index.php');
