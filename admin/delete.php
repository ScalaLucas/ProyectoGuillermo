<?php
require __DIR__ . '/lib.php';
admin_requerir_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }
admin_csrf_verificar();
// Borrado permanente: reservado a administradores. Un operador puede
// suspender una propiedad, pero no eliminarla del todo.
if (!seg_es_admin()) { header('Location: index.php?msg=solo_admin'); exit; }

require_once __DIR__ . '/images.php';

$id = trim((string)($_POST['id'] ?? ''));
$props = propiedades_cargar();
$restantes = [];
foreach ($props as $p) {
    if (($p['id'] ?? '') === $id) {
        foreach (($p['fotos'] ?? []) as $foto) admin_borrar_foto($foto);
        foreach (($p['videos'] ?? []) as $video) {
            if (!preg_match('/^https?:\/\//i', $video)) admin_borrar_video($video);
        }
        continue;
    }
    $restantes[] = $p;
}
$guardado = propiedades_guardar($restantes);
if ($guardado) seg_log('propiedad_eliminada', $id, 'propiedades');

header('Location: index.php?msg=' . ($guardado ? 'deleted' : 'delete_save_err'));
