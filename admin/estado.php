<?php
require __DIR__ . '/lib.php';
admin_requerir_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }
admin_csrf_verificar();

require_once __DIR__ . '/../api/meli.php';

$id = trim((string)($_POST['id'] ?? ''));
$props = propiedades_cargar();
$meliWarn = false;

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
