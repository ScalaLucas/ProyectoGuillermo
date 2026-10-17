<?php
require __DIR__ . '/lib.php';
admin_requerir_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }
admin_csrf_verificar();

require_once __DIR__ . '/../api/meli.php';

$id = trim((string)($_POST['id'] ?? ''));
$props = propiedades_cargar();
$meliWarn = false;

// Destacar / quitar de destacadas de la página principal (estrella del listado).
// Va en este mismo archivo porque en el hosting no se pueden crear archivos nuevos.
if (($_POST['accion'] ?? '') === 'destacar') {
    $msg = 'dest_ok';
    foreach ($props as $i => $p) {
        if (($p['id'] ?? '') !== $id) continue;
        if (admin_es_destacada($p)) {
            unset($props[$i]['destacada_home']);
            $log = 'propiedad_quitada_destacadas';
        } elseif (admin_total_destacadas($props) >= ADMIN_DESTACADAS_MAX) {
            $msg = 'dest_lleno';
        } else {
            $props[$i]['destacada_home'] = date('c');
            $log = 'propiedad_destacada';
        }
        break;
    }
    if ($msg === 'dest_ok') {
        if (!propiedades_guardar($props)) $msg = 'estado_save_err';
        elseif (isset($log)) seg_log($log, $id, 'propiedades');
    }
    $volver = $_POST['volver'] ?? '';
    $volver = preg_match('/^[a-z0-9=&_]*$/i', $volver) ? $volver : '';
    header('Location: index.php?' . ($volver !== '' ? $volver . '&' : '') . 'msg=' . $msg . '#p-' . rawurlencode($id));
    exit;
}

foreach ($props as $i => $p) {
    if (($p['id'] ?? '') === $id) {
        $activaAntes = ($p['activa'] ?? true) !== false;
        $activaNueva = !$activaAntes;
        $props[$i]['activa'] = $activaNueva;

        if (!empty($p['meli_item_id']) && meli_conectado()) {
            $r = meli_cambiar_estado($p['meli_item_id'], $activaNueva ? 'active' : 'paused');
            if (!$r['ok']) $meliWarn = true;
        }
        break;
    }
}
$guardado = propiedades_guardar($props);
if ($guardado) seg_log($activaNueva ?? true ? 'propiedad_reactivada' : 'propiedad_suspendida', $id, 'propiedades');

header('Location: index.php?msg=' . (!$guardado ? 'estado_save_err' : ($meliWarn ? 'estado_meli_err' : 'estado_ok')));
