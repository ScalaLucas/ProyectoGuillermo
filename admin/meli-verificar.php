<?php
require __DIR__ . '/lib.php';
admin_requerir_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }
admin_csrf_verificar();

require_once __DIR__ . '/../api/meli.php';

$id = trim((string)($_POST['id'] ?? ''));
$props = propiedades_cargar();
$idx = null;
foreach ($props as $i => $p) { if (($p['id'] ?? '') === $id) { $idx = $i; break; } }

if ($idx === null || empty($props[$idx]['meli_item_id'])) { header('Location: index.php?msg=meli_err'); exit; }

$r = meli_verificar_item($props[$idx]['meli_item_id']);

if (!$r['existe']) {
    // Se borró o se cerró directamente en Mercado Libre: se desvincula acá
    // también, así el panel deja de mostrarla como publicada. Si se quiere
    // republicar, "Reintentar" la vuelve a dar de alta como ítem nuevo.
    unset($props[$idx]['meli_item_id']);
    $props[$idx]['meli_error'] = $r['mensaje'];
    propiedades_guardar($props);
    seg_log('propiedad_meli_desvinculada', $id . ' — ' . $r['mensaje'], 'propiedades');
    header('Location: index.php?msg=meli_desvinculada');
    exit;
}

header('Location: index.php?msg=meli_verificada');
