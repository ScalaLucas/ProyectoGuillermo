<?php
/**
 * Sistema de administración de alquileres — núcleo compartido.
 * Datos propios en data/contratos.json. Las propiedades de la web se leen
 * (solo lectura) desde api/data/propiedades.json para vincularlas a cada contrato.
 */
// Sesión y usuarios compartidos con el panel de propiedades (ver /seguridad/).
require_once __DIR__ . '/../seguridad/auth.php';

require_once __DIR__ . '/config.php';
define('ALQ_DATA', __DIR__ . '/data');
define('PROPIEDADES_JSON', dirname(__DIR__) . '/api/data/propiedades.json');

/* ---------- utilidades ---------- */
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function dinero($n): string { return '$ ' . number_format((float)$n, 2, ',', '.'); }
function fecha_es(?string $ymd): string { return $ymd ? (new DateTimeImmutable($ymd))->format('d/m/Y') : '—'; }
function periodo_es(string $p): string {
    $m = ['01'=>'Enero','02'=>'Febrero','03'=>'Marzo','04'=>'Abril','05'=>'Mayo','06'=>'Junio','07'=>'Julio','08'=>'Agosto','09'=>'Septiembre','10'=>'Octubre','11'=>'Noviembre','12'=>'Diciembre'];
    return ($m[substr($p, 5, 2)] ?? $p) . ' ' . substr($p, 0, 4);
}
function periodo_mas(string $p, int $meses): string {
    return (new DateTimeImmutable($p . '-01'))->modify(($meses >= 0 ? '+' : '') . $meses . ' months')->format('Y-m');
}
function periodo_actual(): string { return date('Y-m'); }

/* ---------- autenticación ---------- */
function alq_hash_clave(): string {
    $f = ALQ_DATA . '/clave.json';
    if (is_file($f)) { $d = json_decode((string)@file_get_contents($f), true); if (!empty($d['hash'])) return $d['hash']; }
    return ALQ_PASS_HASH_INICIAL;
}
function alq_logueado(): bool {
    if (empty($_SESSION['alq_ok'])) return false;
    if (empty($_SESSION['usuario']) && seg_hay_usuarios() && !seg_config()['legacy_alquileres']) return false;
    return true;
}
function alq_requerir(): void {
    if (!alq_logueado()) { header('Location: login.php'); exit; }
    // Clave temporal pendiente de cambiar: se manda a "Mi cuenta" primero.
    if (function_exists('seg_forzar_clave_pendiente') && seg_forzar_clave_pendiente()) {
        header('Location: ../seguridad/cuenta.php?forzar=1');
        exit;
    }
}
function alq_csrf(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16)); return $_SESSION['csrf']; }
function alq_csrf_ok(): bool { return hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? '')); }

/* ---------- almacenamiento ---------- */
function alq_asegurar_dir(): void {
    if (!is_dir(ALQ_DATA)) {
        @mkdir(ALQ_DATA, 0755, true);
        @file_put_contents(ALQ_DATA . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        @file_put_contents(ALQ_DATA . '/index.html', '');
    }
}
function alq_cargar(): array {
    $raw = @file_get_contents(ALQ_DATA . '/contratos.json');
    $d = $raw ? json_decode($raw, true) : [];
    return is_array($d) ? $d : [];
}
function alq_guardar(array $contratos): bool {
    alq_asegurar_dir();
    $f = ALQ_DATA . '/contratos.json';
    // Copia de seguridad diaria del estado anterior (se conservan los últimos 30 días).
    if (is_file($f)) {
        $bk = ALQ_DATA . '/backup-' . date('Ymd') . '.json';
        if (!is_file($bk)) {
            @copy($f, $bk);
            $viejos = glob(ALQ_DATA . '/backup-*.json') ?: [];
            sort($viejos);
            while (count($viejos) > 30) @unlink(array_shift($viejos));
        }
    }
    $json = json_encode(array_values($contratos), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    return @file_put_contents($f, $json, LOCK_EX) !== false;
}
function alq_encontrar(array $contratos, string $id): ?array {
    foreach ($contratos as $c) if (($c['id'] ?? '') === $id) return $c;
    return null;
}
function alq_nuevo_id(array $contratos): string {
    $ids = array_column($contratos, 'id');
    do { $id = 'AL' . random_int(10000, 99999); } while (in_array($id, $ids, true));
    return $id;
}

/* ---------- propiedades de la web (solo lectura) ---------- */
function alq_propiedades(): array {
    $raw = @file_get_contents(PROPIEDADES_JSON);
    $d = $raw ? json_decode($raw, true) : [];
    return is_array($d) ? $d : [];
}
function alq_propiedad(string $id): ?array {
    foreach (alq_propiedades() as $p) if (($p['id'] ?? '') === $id) return $p;
    return null;
}
function alq_texto_propiedad(array $p): string {
    return trim(($p['tipo'] ?? '') . ' · ' . ($p['direccion'] ?? '') . ($p['zona'] ?? '' ? ' (' . $p['zona'] . ')' : ''));
}

/* ---------- cálculos del contrato ---------- */
function alq_fin(array $c): string {
    return (new DateTimeImmutable($c['inicio']))->modify('+' . (int)$c['meses'] . ' months')->modify('-1 day')->format('Y-m-d');
}
/** Alquiler vigente en un período 'YYYY-MM' (aplica los ajustes registrados). */
/** Redondea un monto de alquiler al millar más cercano (151.499 → 151.000, 151.501 → 152.000). */
function alq_redondear_mil(float $n): float { return round($n / 1000) * 1000; }
function alq_monto_periodo(array $c, string $periodo): float {
    $monto = (float)$c['monto_inicial'];
    $aj = alq_ajustes_efectivos($c);
    usort($aj, fn($a, $b) => strcmp($a['desde'], $b['desde']));
    foreach ($aj as $a) if ($a['desde'] <= $periodo) $monto = (float)$a['monto_nuevo'];
    return $monto;
}
/**
 * Ajustes "efectivos" para calcular el alquiler de cada período: si el
 * contrato es de Porcentaje fijo con % y frecuencia conocidos, se generan
 * automáticamente los aumentos compuestos desde el inicio hasta el fin —
 * no hace falta ir a cargarlos a mano cada vez que toca. Un ajuste cargado
 * a mano para el mismo "desde" tiene prioridad (por si ese mes puntual se
 * pactó distinto a la fórmula).
 */
function alq_ajustes_efectivos(array $c): array {
    $manuales = [];
    foreach ($c['ajustes'] ?? [] as $a) $manuales[$a['desde']] = $a;
    $tipo = $c['ajuste_tipo'] ?? 'Ninguno';
    $cada = (int)($c['ajuste_cada'] ?? 0);
    $pct = (float)($c['ajuste_pct_fijo'] ?? 0);
    if ($tipo !== 'Porcentaje fijo' || $cada <= 0 || $pct == 0.0) return array_values($manuales);
    $fin = substr(alq_fin($c), 0, 7);
    $monto = (float)$c['monto_inicial'];
    $p = periodo_mas(substr($c['inicio'], 0, 7), $cada);
    $auto = [];
    while ($p <= $fin) {
        $nuevo = alq_redondear_mil($monto * (1 + $pct / 100));
        $auto[$p] = ['desde' => $p, 'indice' => 'Porcentaje fijo', 'porcentaje' => $pct, 'monto_anterior' => $monto, 'monto_nuevo' => $nuevo, 'nota' => '', 'auto' => true];
        $monto = $nuevo;
        $p = periodo_mas($p, $cada);
    }
    return array_values($manuales + $auto);
}
function alq_monto_actual(array $c): float { return alq_monto_periodo($c, periodo_actual()); }
/** Períodos (YYYY-MM) del contrato hasta hoy o hasta el fin, lo que ocurra antes. */
function alq_periodos(array $c): array {
    $ini = substr($c['inicio'], 0, 7);
    $finP = substr(alq_fin($c), 0, 7);
    // Llega hasta hoy, pero si hay un pago anticipado (un mes futuro ya cobrado) se extiende hasta ese mes.
    $hasta = periodo_actual();
    foreach ($c['cobros'] ?? [] as $co) if (($co['periodo'] ?? '') > $hasta) $hasta = $co['periodo'];
    $hasta = min($hasta, $finP);
    $out = [];
    for ($p = $ini; $p <= $hasta; $p = periodo_mas($p, 1)) $out[] = $p;
    return $out;
}
function alq_cobrado_periodo(array $c, string $periodo): float {
    $t = 0.0;
    foreach ($c['cobros'] ?? [] as $co) if ($co['periodo'] === $periodo) $t += (float)$co['monto'];
    return $t;
}
/**
 * Conceptos extras de un cobro (expensas, multas, etc.), como lista
 * [['concepto'=>string,'monto'=>float], ...]. Lee el formato nuevo
 * ('extras_items') y, si no está, arma la lista a partir del formato viejo
 * (un solo 'extras' + 'extras_concepto') para no perder recibos anteriores.
 */
function alq_extras_items(array $co): array {
    if (!empty($co['extras_items']) && is_array($co['extras_items'])) {
        return array_values(array_filter($co['extras_items'], fn($it) => (float)($it['monto'] ?? 0) > 0));
    }
    if ((float)($co['extras'] ?? 0) > 0) {
        return [['concepto' => $co['extras_concepto'] ?: 'Otros conceptos', 'monto' => (float)$co['extras']]];
    }
    return [];
}
/**
 * Descuentos de un cobro (ej. expensas que pagó el inquilino directo al
 * consorcio y se restan del alquiler), como lista [['concepto'=>string,'monto'=>float], ...].
 */
function alq_descuentos_items(array $co): array {
    if (empty($co['descuentos_items']) || !is_array($co['descuentos_items'])) return [];
    return array_values(array_filter($co['descuentos_items'], fn($it) => (float)($it['monto'] ?? 0) > 0));
}
/** Estado de un período: pagado | parcial | atrasado | pendiente. */
function alq_estado_periodo(array $c, string $periodo): string {
    $esperado = alq_monto_periodo($c, $periodo);
    $cobrado = alq_cobrado_periodo($c, $periodo);
    if ($cobrado >= $esperado - 0.5) return 'pagado';
    $dia = max(1, min(28, (int)($c['dia_venc'] ?? 10)));
    $venc = $periodo . '-' . str_pad((string)$dia, 2, '0', STR_PAD_LEFT);
    $vencido = date('Y-m-d') > $venc;
    if ($cobrado > 0) return $vencido ? 'parcial' : 'pendiente';
    return $vencido ? 'atrasado' : 'pendiente';
}
function alq_deuda(array $c): float {
    if (($c['estado'] ?? 'vigente') !== 'vigente') return 0.0;
    $t = 0.0;
    foreach (alq_periodos($c) as $p) {
        $e = alq_estado_periodo($c, $p);
        if ($e === 'atrasado' || $e === 'parcial') $t += max(0, alq_monto_periodo($c, $p) - alq_cobrado_periodo($c, $p));
    }
    return $t;
}
/** Próximo período en el que corresponde actualizar el alquiler (null si no aplica). */
function alq_prox_ajuste(array $c): ?string {
    $cada = (int)($c['ajuste_cada'] ?? 0);
    $tipo = $c['ajuste_tipo'] ?? 'Ninguno';
    if ($tipo === 'Ninguno' || $cada <= 0) return null;
    // Porcentaje fijo con % cargado se aplica solo (ver alq_ajustes_efectivos):
    // no hay nada pendiente de cargar a mano.
    if ($tipo === 'Porcentaje fijo' && (float)($c['ajuste_pct_fijo'] ?? 0) != 0.0) return null;
    $base = substr($c['inicio'], 0, 7);
    foreach ($c['ajustes'] ?? [] as $a) if ($a['desde'] > $base) $base = $a['desde'];
    $prox = periodo_mas($base, $cada);
    return $prox <= substr(alq_fin($c), 0, 7) ? $prox : null;
}
function alq_meses_para(string $periodo): int {
    return ((int)substr($periodo, 0, 4) - (int)date('Y')) * 12 + ((int)substr($periodo, 5, 2) - (int)date('n'));
}
function alq_dias_fin(array $c): int {
    return (int)(new DateTimeImmutable(date('Y-m-d')))->diff(new DateTimeImmutable(alq_fin($c)))->format('%r%a');
}
/**
 * Interés por mora acumulado de un período puntual, sobre lo que todavía se
 * debe de ese mes, desde el día siguiente al vencimiento hasta $hasta (hoy
 * por defecto). Tasa diaria fija (ej. 1% por día) sobre el saldo actual del
 * período: es un cálculo simple, pensado para saber cuánto cobrar hoy — no
 * reconstruye día por día un historial con pagos parciales en fechas
 * distintas dentro del mismo período.
 */
function alq_interes_mora(array $c, string $periodo, ?string $hasta = null): float {
    $tasa = (float)($c['interes_mora'] ?? 0);
    if ($tasa <= 0) return 0.0;
    $saldo = max(0, alq_monto_periodo($c, $periodo) - alq_cobrado_periodo($c, $periodo));
    if ($saldo <= 0) return 0.0;
    $dia = max(1, min(28, (int)($c['dia_venc'] ?? 10)));
    $venc = new DateTimeImmutable($periodo . '-' . str_pad((string)$dia, 2, '0', STR_PAD_LEFT));
    $hastaFecha = new DateTimeImmutable($hasta ?? date('Y-m-d'));
    $dias = (int)$venc->diff($hastaFecha)->format('%r%a');
    return $dias > 0 ? round($saldo * ($tasa / 100) * $dias, 2) : 0.0;
}
/** Interés por mora acumulado de todos los períodos atrasados/parciales del contrato. */
function alq_interes_mora_total(array $c, ?string $hasta = null): float {
    if (($c['estado'] ?? 'vigente') !== 'vigente') return 0.0;
    $t = 0.0;
    foreach (alq_periodos($c) as $p) $t += alq_interes_mora($c, $p, $hasta);
    return $t;
}
/** Días transcurridos desde el vencimiento de un período hasta $hasta (0 si todavía no venció). */
function alq_dias_atraso(array $c, string $periodo, ?string $hasta = null): int {
    $dia = max(1, min(28, (int)($c['dia_venc'] ?? 10)));
    $venc = new DateTimeImmutable($periodo . '-' . str_pad((string)$dia, 2, '0', STR_PAD_LEFT));
    $hastaFecha = new DateTimeImmutable($hasta ?? date('Y-m-d'));
    $dias = (int)$venc->diff($hastaFecha)->format('%r%a');
    return max(0, $dias);
}
/** Cantidad de períodos vencidos y todavía impagos (total o parcialmente). */
function alq_meses_adeudados(array $c): int {
    if (($c['estado'] ?? 'vigente') !== 'vigente') return 0;
    $n = 0;
    foreach (alq_periodos($c) as $p) { $e = alq_estado_periodo($c, $p); if ($e === 'atrasado' || $e === 'parcial') $n++; }
    return $n;
}
/** Dos o más meses adeudados: umbral habitual para habilitar acciones de desalojo. */
function alq_riesgo_desalojo(array $c): bool { return alq_meses_adeudados($c) >= 2; }
/**
 * Estimación de la indemnización por rescisión anticipada del inquilino,
 * según el criterio habitual (Art. 1221 CCCN): habilitada recién a partir del
 * 6º mes de contrato; 1,5 meses de alquiler si rescinde dentro del primer
 * año, 1 mes si es después. Es una referencia general, no reemplaza lo que
 * diga puntualmente cada contrato.
 */
function alq_rescision_anticipada(array $c, ?string $hoy = null): array {
    $hoy = $hoy ?? date('Y-m-d');
    $inicio = new DateTimeImmutable(substr($c['inicio'], 0, 10));
    $hoyF = new DateTimeImmutable($hoy);
    $diff = $inicio->diff($hoyF);
    $meses = $diff->invert ? 0 : $diff->y * 12 + $diff->m;
    $monto = alq_monto_periodo($c, substr($hoy, 0, 7));
    $multMeses = $meses < 12 ? 1.5 : 1.0;
    return ['meses_transcurridos' => $meses, 'habilitada' => $meses >= 6, 'multa_meses' => $multMeses, 'multa_monto' => round($monto * $multMeses, 2)];
}
function alq_siguiente_recibo(array $contratos): int {
    $max = 0;
    foreach ($contratos as $c) foreach ($c['cobros'] ?? [] as $co) $max = max($max, (int)($co['recibo'] ?? 0));
    return $max + 1;
}

/* ---------- número a letras (recibos) ---------- */
function alq_letras(int $n): string {
    if ($n === 0) return 'cero';
    $u = ['', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve', 'diez', 'once', 'doce', 'trece', 'catorce', 'quince', 'dieciséis', 'diecisiete', 'dieciocho', 'diecinueve', 'veinte', 'veintiuno', 'veintidós', 'veintitrés', 'veinticuatro', 'veinticinco', 'veintiséis', 'veintisiete', 'veintiocho', 'veintinueve'];
    $d = ['', '', '', 'treinta', 'cuarenta', 'cincuenta', 'sesenta', 'setenta', 'ochenta', 'noventa'];
    $c = ['', 'ciento', 'doscientos', 'trescientos', 'cuatrocientos', 'quinientos', 'seiscientos', 'setecientos', 'ochocientos', 'novecientos'];
    $tres = function (int $x) use ($u, $d, $c): string {
        if ($x === 100) return 'cien';
        $s = [];
        if ($x >= 100) { $s[] = $c[intdiv($x, 100)]; $x %= 100; }
        if ($x >= 30) { $t = $d[intdiv($x, 10)]; $r = $x % 10; $s[] = $r ? "$t y {$u[$r]}" : $t; }
        elseif ($x > 0) $s[] = $u[$x];
        return implode(' ', $s);
    };
    $partes = [];
    $millones = intdiv($n, 1000000); $n %= 1000000;
    $miles = intdiv($n, 1000); $resto = $n % 1000;
    if ($millones) $partes[] = $millones === 1 ? 'un millón' : $tres($millones) . ' millones';
    if ($miles) $partes[] = $miles === 1 ? 'mil' : preg_replace('/uno$/', 'un', $tres($miles)) . ' mil';
    if ($resto) $partes[] = $tres($resto);
    return implode(' ', $partes);
}

/* ---------- cabecera / pie de página HTML ---------- */
function alq_cabecera(string $titulo, string $activo = ''): void {
    $nav = ['index.php' => 'Panel', 'contrato.php' => '+ Nuevo contrato', 'recibo-independiente.php' => 'Recibo independiente', 'liquidacion.php' => 'Liquidaciones', 'avisos.php' => 'Avisos', 'exportar.php' => 'Exportar Excel'];
    if (seg_usuario_actual()) { $nav['../seguridad/cuenta.php'] = 'Mi cuenta'; if (seg_es_admin()) $nav['../seguridad/usuarios.php'] = 'Usuarios'; }
    else $nav['clave.php'] = 'Cambiar clave';
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<title>' . h($titulo) . ' · Alquileres</title><meta name="robots" content="noindex, nofollow">';
    echo '<link rel="stylesheet" href="alquileres.css?v=6"></head><body><header class="top"><div class="top__in">';
    echo '<a class="brand" href="index.php"><img src="../assets/logo-mr-icon.png" alt=""><span><strong>Alquileres</strong><small>' . h(ALQ_EMPRESA) . '</small></span></a><nav>';
    foreach ($nav as $href => $txt) {
        $cls = ($href === $activo) ? ' class="on"' : '';
        echo '<a' . $cls . ' href="' . $href . '">' . h($txt) . '</a>';
    }
    echo '<a href="../seguridad/logout.php">Salir</a></nav></div></header><main class="wrap">';
}
function alq_pie(): void { echo '</main></body></html>'; }

/* ---------- recibo impreso (formato clásico "no válido como factura") ---------- */
/** CSS del recibo clásico. Se usa una sola vez, embebida en <style> por cada página que imprime recibos. */
function alq_recibo_css(): string {
    return <<<CSS
body{background:#fff}
.rec2{max-width:660px;margin:0 auto 30px;border:1px solid #333;padding:0;page-break-inside:avoid;font-family:-apple-system,'Segoe UI',Arial,sans-serif;color:#1a1a1a;font-size:13px}
.rec2 .cop{text-align:center;font-size:10px;letter-spacing:.14em;text-transform:uppercase;color:#6E1B2E;font-weight:700;padding:5px 0;border-bottom:1px solid #333}
.rec2__top{position:relative;min-height:92px;border-bottom:2px solid #333;padding:12px 16px}
.rec2__x{width:100px;margin:0 auto;display:flex;flex-direction:column;align-items:center;text-align:center}
.rec2__x b{font-size:30px;line-height:1;border:2px solid #1a1a1a;width:38px;height:38px;display:flex;align-items:center;justify-content:center;margin-bottom:6px}
.rec2__x span{font-size:8px;line-height:1.3}
.rec2__info{position:absolute;top:12px;right:16px;text-align:right;max-width:260px}
.rec2__info h2{margin:0 0 10px;font-size:20px;letter-spacing:.08em}
.rec2__fiscal{font-size:10.5px;line-height:1.7;color:#333;margin-top:10px;text-align:left}
.rec2__fecha{font-size:11px;font-weight:600;display:flex;align-items:center;gap:8px;justify-content:flex-end}
.rec2__fecha .caja{display:inline-block;border:1px solid #333;border-radius:3px;padding:3px 24px;min-width:70px;font-weight:400}
.rec2__body{padding:12px 16px}
.rec2 .fila{display:flex;gap:18px;margin:0 0 9px}
.rec2 .campo{flex:1;display:flex;align-items:baseline;gap:5px;white-space:nowrap}
.rec2 .campo b{font-weight:600;flex:none}
.rec2 .campo .val{flex:1;border-bottom:1px dotted #888;min-width:20px;padding-bottom:1px;white-space:nowrap;overflow:hidden}
.rec2__linea{border-bottom:1px dotted #888;min-height:16px;padding:1px 0 3px;font-size:13px}
.rec2__linea:first-child{margin-top:2px}
.rec2__label{font-weight:600;margin:10px 0 2px;font-size:13px}
.rec2__bottom{display:flex;justify-content:space-between;align-items:flex-end;border-top:2px solid #333;padding:10px 16px 14px;gap:20px}
.rec2__son{display:flex;align-items:center;gap:8px}
.rec2__son b{font-weight:700}
.rec2__son .caja{background:#e9e9e9;border:1px solid #999;border-radius:3px;padding:5px 14px;font-weight:700;font-size:14px}
.rec2__firma{flex:none;width:230px;text-align:center}
.rec2__firma .linea{border-bottom:1px dotted #888;height:26px;display:flex;align-items:flex-end;justify-content:center;padding-bottom:2px;font-size:11px}
.rec2__firma .lbl{font-size:10px;letter-spacing:.08em;text-transform:uppercase;color:#555;margin-top:2px}
.rec2__pie{font-size:10px;color:#767268;text-align:center;border-top:1px solid #ccc;padding:6px 0}
@media print{.rec2{border-color:#000}.rec2__top,.rec2__bottom{border-color:#000}}
CSS;
}

/**
 * Arma el bloque HTML de un recibo clásico "no válido como factura" (una
 * copia). $d: numero, serie(opc), fecha(Y-m-d), nombre, dni, domicilio(opc),
 * localidad(opc), tel(opc), iva(opc), monto(float), letras, concepto(string,
 * puede tener varias líneas separadas por "\n"), copia ("ORIGINAL"/"DUPLICADO").
 */
function alq_recibo_render(array $d): string {
    $wrap = fn(string $t, int $ancho = 62) => $t === '' ? [] : explode("\n", wordwrap($t, $ancho, "\n", true));
    $lineasMonto = $wrap($d['letras'] ?? '');
    $lineasConcepto = $wrap((string)($d['concepto'] ?? ''));
    while (count($lineasMonto) < 2) $lineasMonto[] = '';
    while (count($lineasConcepto) < 3) $lineasConcepto[] = '';

    ob_start(); ?>
<div class="rec2">
  <?php if (!empty($d['copia'])): ?><div class="cop"><?= h($d['copia']) ?></div><?php endif; ?>
  <div class="rec2__top">
    <div class="rec2__x"><b>X</b><span>DOCUMENTO<br>NO VÁLIDO<br>COMO FACTURA</span></div>
    <div class="rec2__info">
      <h2>RECIBO</h2>
      <div class="rec2__fecha">N° <?= h($d['numero'] ?? '') ?> &nbsp; FECHA <span class="caja"><?= h(fecha_es($d['fecha'] ?? null)) ?></span></div>
      <div class="rec2__fiscal">
        <?php if (ALQ_CUIT !== ''): ?>C.U.I.T.: <?= h(ALQ_CUIT) ?><br><?php endif; ?>
        <?php if (ALQ_INGRESOS_BRUTOS !== ''): ?>Ingresos Brutos: <?= h(ALQ_INGRESOS_BRUTOS) ?><br><?php endif; ?>
        <?php if (ALQ_INICIO_ACTIVIDADES !== ''): ?>Inicio de actividades: <?= h(ALQ_INICIO_ACTIVIDADES) ?><?php endif; ?>
      </div>
    </div>
  </div>

  <div class="rec2__body">
    <div class="fila"><div class="campo"><b>Señor(a):</b><span class="val"><?= h($d['nombre'] ?? '') ?></span></div></div>
    <div class="fila"><div class="campo"><b>Domicilio:</b><span class="val"><?= h($d['domicilio'] ?? '') ?></span></div></div>
    <div class="fila">
      <div class="campo"><b>Localidad:</b><span class="val"><?= h($d['localidad'] ?? '') ?></span></div>
      <div class="campo" style="flex:0 0 200px"><b>Tel.:</b><span class="val"><?= h($d['tel'] ?? '') ?></span></div>
    </div>
    <div class="fila">
      <div class="campo"><b>C.U.I.T./DNI:</b><span class="val"><?= h($d['dni'] ?? '') ?></span></div>
    </div>

    <div class="rec2__label">Recibí(mos) la suma de</div>
    <?php foreach ($lineasMonto as $l): ?><div class="rec2__linea"><?= h($l) ?></div><?php endforeach; ?>

    <div class="rec2__label">en concepto de</div>
    <?php foreach ($lineasConcepto as $l): ?><div class="rec2__linea"><?= h($l) ?></div><?php endforeach; ?>
    <div class="rec2__linea">&nbsp;</div>
  </div>

  <div class="rec2__bottom">
    <div class="rec2__son"><b>TOTAL</b> <span class="caja"><?= h(dinero($d['monto'] ?? 0)) ?></span></div>
  </div>
</div>
    <?php
    return (string)ob_get_clean();
}

/**
 * Botón "Compartir por WhatsApp" + script: arma un PDF en el navegador (una
 * página por cada ".rec2" de la pantalla — ORIGINAL y DUPLICADO) con
 * html2canvas + jsPDF, y lo comparte con el selector nativo del celular
 * (navigator.share), donde WhatsApp aparece como una opción más, ya con el
 * PDF adjunto. Si el navegador no soporta compartir archivos (desktop,
 * navegadores viejos), descarga el PDF para adjuntarlo a mano.
 */
function alq_compartir_wa_boton(string $archivo): string {
    $archivo = preg_replace('/[^A-Za-z0-9._-]/', '', $archivo) ?: 'recibo.pdf';
    ob_start(); ?>
<button class="btn gh" id="wa-share-btn" type="button">📲 Compartir por WhatsApp</button>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script>
(function(){
  var btn = document.getElementById('wa-share-btn');
  if (!btn) return;
  btn.addEventListener('click', async function(){
    var txt = btn.textContent; btn.disabled = true; btn.textContent = 'Generando PDF…';
    try {
      var jsPDF = window.jspdf.jsPDF;
      var pdf = null;
      var cards = document.querySelectorAll('.rec2');
      for (var i = 0; i < cards.length; i++) {
        var canvas = await html2canvas(cards[i], { scale: 2, backgroundColor: '#ffffff' });
        var img = canvas.toDataURL('image/jpeg', 0.95);
        var w = 190, h = w * canvas.height / canvas.width;
        if (!pdf) pdf = new jsPDF({ unit: 'mm', format: 'a4' }); else pdf.addPage();
        pdf.addImage(img, 'JPEG', 10, 10, w, h);
      }
      var blob = pdf.output('blob');
      var file = new File([blob], '<?= $archivo ?>', { type: 'application/pdf' });
      if (navigator.canShare && navigator.canShare({ files: [file] })) {
        await navigator.share({ files: [file], title: 'Recibo' });
      } else {
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a'); a.href = url; a.download = '<?= $archivo ?>';
        document.body.appendChild(a); a.click(); a.remove();
        setTimeout(function(){ URL.revokeObjectURL(url); }, 4000);
        alert('Este navegador no permite compartir directo: se descargó el PDF. Adjuntalo manualmente en WhatsApp.');
      }
    } catch (e) {
      alert('No se pudo generar el PDF: ' + e.message);
    } finally {
      btn.disabled = false; btn.textContent = txt;
    }
  });
})();
</script>
    <?php
    return (string)ob_get_clean();
}
