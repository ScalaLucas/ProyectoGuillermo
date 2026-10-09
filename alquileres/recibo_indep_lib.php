<?php
/**
 * Recibos independientes: recibos sueltos, sin contrato asociado, con todos los
 * datos cargados a mano (persona, concepto, monto, fecha). Numeración propia.
 * Datos en data/recibos-independientes.json y data/recibos-independientes-config.json.
 */
require_once __DIR__ . '/lib.php';
define('RI_JSON', ALQ_DATA . '/recibos-independientes.json');
define('RI_CFG', ALQ_DATA . '/recibos-independientes-config.json');

function ri_cargar(): array {
    $raw = @file_get_contents(RI_JSON);
    $d = $raw ? json_decode($raw, true) : [];
    return is_array($d) ? $d : [];
}
function ri_guardar(array $recibos): bool {
    alq_asegurar_dir();
    $json = json_encode(array_values($recibos), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    return @file_put_contents(RI_JSON, $json, LOCK_EX) !== false;
}
function ri_encontrar(array $recibos, string $id): ?array {
    foreach ($recibos as $r) if (($r['id'] ?? '') === $id) return $r;
    return null;
}
function ri_nuevo_id(array $recibos): string {
    $ids = array_column($recibos, 'id');
    do { $id = 'RI' . random_int(10000, 99999); } while (in_array($id, $ids, true));
    return $id;
}
function ri_config(): array {
    $def = ['serie' => 'A'];
    $d = json_decode((string)@file_get_contents(RI_CFG), true);
    return array_merge($def, is_array($d) ? $d : []);
}
function ri_guardar_config(array $c): bool {
    alq_asegurar_dir();
    return @file_put_contents(RI_CFG, json_encode($c, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) !== false;
}
/** Próximo número sugerido para una serie (el mayor ya usado en esa serie + 1). */
function ri_siguiente_numero(array $recibos, string $serie): int {
    $max = 0;
    foreach ($recibos as $r) if (($r['serie'] ?? '') === $serie) $max = max($max, (int)($r['numero'] ?? 0));
    return $max + 1;
}
/** Monto en letras, formato "pesos con NN/100". */
function ri_monto_letras(float $total, string $moneda = 'ARS'): string {
    return alq_monto_letras($total, $moneda);
}
