<?php
require __DIR__ . '/lib.php';
admin_requerir_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }
admin_csrf_verificar();

require_once __DIR__ . '/../api/meli.php';

$id = trim((string)($_POST['id'] ?? ''));
$props = propiedades_cargar();
$propiedad = null;
foreach ($props as $p) { if (($p['id'] ?? '') === $id) { $propiedad = $p; break; } }

if (!$propiedad) { header('Location: index.php?msg=meli_err'); exit; }

$resultado = meli_publicar_propiedad($propiedad);

foreach ($props as $i => $p) {
    if (($p['id'] ?? '') === $id) {
        if ($resultado['ok']) {
            $props[$i]['meli_item_id'] = $resultado['item_id'];
            unset($props[$i]['meli_error']);
        } else {
            $props[$i]['meli_error'] = $resultado['mensaje'];
        }
        break;
    }
}
propiedades_guardar($props);

header('Location: index.php?msg=' . ($resultado['ok'] ? 'ok' : (meli_conectado() ? 'meli_err' : 'meli_pend')));
