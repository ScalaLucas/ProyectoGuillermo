<?php
require __DIR__ . '/lib.php';
admin_requerir_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }
admin_csrf_verificar();

require_once __DIR__ . '/images.php';
require_once __DIR__ . '/../api/meli.php';
require_once __DIR__ . '/../api/geocoding.php';

$idEnviado = trim((string)($_POST['id'] ?? ''));
$props = propiedades_cargar();
$existente = null;
foreach ($props as $pr) { if (($pr['id'] ?? '') === $idEnviado) { $existente = $pr; break; } }

$esNueva = $idEnviado === '' || $existente === null;
$id = $esNueva ? propiedades_siguiente_id() : $idEnviado;

function campo_texto(string $nombre, int $max = 500): string
{
    return mb_substr(trim((string)($_POST[$nombre] ?? '')), 0, $max);
}
function campo_num(string $nombre): int
{
    return max(0, (int)($_POST[$nombre] ?? 0));
}

$operacion = campo_texto('operacion', 20);
$tipo = campo_texto('tipo', 30);
$zona = campo_texto('zona', 60);
$direccion = campo_texto('direccion', 160);
$titulo = campo_texto('titulo', 200);
$precio = campo_texto('precio', 40);
$m2 = campo_num('m2');
$amb = campo_num('amb');
$dorm = campo_num('dorm');
$banos = campo_num('banos');
$destacado = !empty($_POST['destacado']);
$descripcion = campo_texto('descripcion', 6000);

// Cochera, terreno, local, depósito y oficina no tienen ambientes: solo los
// tipos de vivienda los exigen.
$tiposConAmbientes = ['Departamento', 'Casa', 'Chalet', 'PH', 'Dúplex', 'Tríplex'];
$faltaAmb = in_array($tipo, $tiposConAmbientes, true) && $amb <= 0;

if ($operacion === '' || $tipo === '' || $zona === '' || $direccion === '' || $precio === '' || $m2 <= 0 || $faltaAmb) {
    header('Location: property-form.php?id=' . urlencode($esNueva ? '' : $id) . '&err=faltan_datos');
    exit;
}

// --- Características: arma el JSON agrupado a partir de los campos del formulario ---
$caracteristicas = ['Principales' => []];

$carNum = function (string $nombre): int { return max(0, (int)($_POST[$nombre] ?? 0)); };
if (($v = $carNum('carac_sup_cubierta')) > 0) $caracteristicas['Principales']['Superficie cubierta'] = $v . ' m²';
if (($v = $carNum('carac_cocheras')) > 0) $caracteristicas['Principales']['Cocheras'] = (string)$v;
if (($v = $carNum('carac_bauleras')) > 0) $caracteristicas['Principales']['Bauleras'] = (string)$v;
if (($v = $carNum('carac_antiguedad')) > 0) $caracteristicas['Principales']['Antigüedad'] = $v . ' años';
if (($v = $carNum('carac_expensas')) > 0) $caracteristicas['Principales']['Expensas'] = number_format($v, 0, ',', '.') . ' ARS';
if (($v = $carNum('carac_pisos')) > 0) $caracteristicas['Principales']['Cantidad de pisos'] = (string)$v;

$carSel = fn(string $nombre) => trim((string)($_POST[$nombre] ?? ''));
if (($v = $carSel('carac_disposicion')) !== '') $caracteristicas['Principales']['Disposición'] = $v;
if (($v = $carSel('carac_orientacion')) !== '') $caracteristicas['Principales']['Orientación'] = $v;
if (($v = $carSel('carac_tipo_seguridad')) !== '') $caracteristicas['Seguridad']['Tipo de seguridad'] = $v;

// Información privada de la operación: no se muestra en la web pública
// (ver script.js, que salta este grupo), solo se manda a Mercado Libre.
if (($v = $carSel('carac_requisitos_transaccion')) !== '') $caracteristicas['Información privada de la operación']['Requerimientos transacción'] = $v;
$comision = trim((string)($_POST['carac_comision_operacion'] ?? ''));
if ($comision !== '' && is_numeric($comision) && $comision >= 0) {
    $caracteristicas['Información privada de la operación']['Comisión operación'] = rtrim(rtrim(number_format((float)$comision, 2, '.', ''), '0'), '.') . '%';
}

if (empty($caracteristicas['Principales'])) unset($caracteristicas['Principales']);

// --- Características personalizadas: texto libre que escribe el administrador ---
$customLabels = $_POST['carac_custom_label'] ?? [];
$customChecks = $_POST['carac_custom_check'] ?? [];
$otras = [];
if (is_array($customLabels)) {
    foreach ($customLabels as $i => $etiqueta) {
        $etiqueta = trim(mb_substr((string)$etiqueta, 0, 80));
        if ($etiqueta === '' || empty($customChecks[$i])) continue;
        $otras[$etiqueta] = 'Sí';
    }
}
if ($otras) $caracteristicas['Otras características'] = $otras;

// Se guardan TODAS (tildadas y sin tildar) como Sí/No: así se ven también
// en la web y en Mercado Libre las que la propiedad NO tiene, en vez de
// simplemente no aparecer.
$marcados = $_POST['carac_check'] ?? [];
foreach (CARAC_GRUPOS_BOOL as $grupo => $etiquetas) {
    foreach ($etiquetas as $etiqueta) {
        $caracteristicas[$grupo][$etiqueta] = (is_array($marcados) && !empty($marcados[$grupo][$etiqueta])) ? 'Sí' : 'No';
    }
}

if (!$caracteristicas) $caracteristicas = null;

// --- Fotos: cuáles se mantienen de las que ya había ---
define('ADMIN_FOTOS_MAX', 50); // máximo de fotos por propiedad

$fotosOriginales = $existente['fotos'] ?? [];
$mantener = array_values(array_intersect($_POST['mantener_fotos'] ?? [], $fotosOriginales));

// Se valida el límite ANTES de borrar nada: si se rechaza el guardado, las
// fotos desmarcadas por el usuario tienen que seguir intactas.
$entrantes = 0;
if (!empty($_FILES['fotos_nuevas']) && is_array($_FILES['fotos_nuevas']['name'])) {
    foreach ($_FILES['fotos_nuevas']['error'] as $e) if ($e !== UPLOAD_ERR_NO_FILE) $entrantes++;
}
if (count($mantener) + $entrantes > ADMIN_FOTOS_MAX) {
    header('Location: property-form.php?id=' . urlencode($esNueva ? '' : $id) . '&err=demasiadas_fotos');
    exit;
}

foreach ($fotosOriginales as $f) {
    if (!in_array($f, $mantener, true)) admin_borrar_foto($f);
}

// --- Fotos nuevas ---
$slug = strtolower($id);
$nuevas = [];
if (!empty($_FILES['fotos_nuevas']) && is_array($_FILES['fotos_nuevas']['name'])) {
    $n = count($_FILES['fotos_nuevas']['name']);
    for ($i = 0; $i < $n; $i++) {
        if (($_FILES['fotos_nuevas']['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        $file = [
            'name' => $_FILES['fotos_nuevas']['name'][$i],
            'type' => $_FILES['fotos_nuevas']['type'][$i],
            'tmp_name' => $_FILES['fotos_nuevas']['tmp_name'][$i],
            'error' => $_FILES['fotos_nuevas']['error'][$i],
            'size' => $_FILES['fotos_nuevas']['size'][$i],
        ];
        $ruta = admin_procesar_foto($file, $slug, count($mantener) + count($nuevas) + 1);
        if ($ruta) $nuevas[] = $ruta;
    }
}
$fotos = array_merge($mantener, $nuevas);

// --- Videos: archivos propios subidos (assets/videos/...) + links externos ---
$videosExistentes = $existente['videos'] ?? [];
$videosLocalesOriginales = array_values(array_filter(
    $videosExistentes,
    fn($v) => !preg_match('/^https?:\/\//i', $v)
));
$mantenerVideos = array_values(array_intersect($_POST['mantener_videos'] ?? [], $videosLocalesOriginales));
foreach ($videosLocalesOriginales as $v) {
    if (!in_array($v, $mantenerVideos, true)) admin_borrar_video($v);
}

$videosUrls = [];
if (is_array($_POST['videos_urls'] ?? null)) {
    foreach ($_POST['videos_urls'] as $url) {
        $url = trim(mb_substr((string)$url, 0, 300));
        if ($url !== '' && preg_match('/^https?:\/\//i', $url)) $videosUrls[] = $url;
    }
}

$videosNuevos = [];
if (!empty($_FILES['videos_nuevos']) && is_array($_FILES['videos_nuevos']['name'])) {
    $n = count($_FILES['videos_nuevos']['name']);
    for ($i = 0; $i < $n; $i++) {
        if (($_FILES['videos_nuevos']['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        $file = [
            'name' => $_FILES['videos_nuevos']['name'][$i],
            'type' => $_FILES['videos_nuevos']['type'][$i],
            'tmp_name' => $_FILES['videos_nuevos']['tmp_name'][$i],
            'error' => $_FILES['videos_nuevos']['error'][$i],
            'size' => $_FILES['videos_nuevos']['size'][$i],
        ];
        $ruta = admin_procesar_video($file, $slug, count($mantenerVideos) + count($videosNuevos) + 1);
        if ($ruta) $videosNuevos[] = $ruta;
    }
}

$videos = array_slice(array_values(array_unique(array_merge($mantenerVideos, $videosUrls, $videosNuevos))), 0, 20);

$propiedad = [
    'id' => $id,
    'tipo' => $tipo,
    'operacion' => $operacion,
    'zona' => $zona,
    'direccion' => $direccion,
    'precio' => $precio,
    'm2' => $m2,
    'dorm' => $dorm,
    'banos' => $banos,
    'amb' => $amb,
    'destacado' => $destacado,
    'actualizado' => date('c'),
];
if ($titulo !== '') $propiedad['titulo'] = $titulo;
if ($descripcion !== '') $propiedad['descripcion'] = $descripcion;
if ($caracteristicas !== null) $propiedad['caracteristicas'] = $caracteristicas;
if ($fotos) $propiedad['fotos'] = $fotos;
if ($videos) $propiedad['videos'] = $videos;
if (!empty($existente['meli_item_id'])) $propiedad['meli_item_id'] = $existente['meli_item_id'];
if (isset($existente['activa'])) $propiedad['activa'] = $existente['activa'];

// --- Información interna del equipo (nunca se publica ni se manda a Mercado Libre) ---
$interno = [
    'agente' => campo_texto('interno_agente', 80),
    'propietario_nombre' => campo_texto('interno_propietario_nombre', 160),
    'propietario_email' => campo_texto('interno_propietario_email', 160),
    'propietario_telefono' => campo_texto('interno_propietario_telefono', 40),
];
if (array_filter($interno, fn($v) => $v !== '')) $propiedad['interno'] = $interno;

// Ubicación exacta (no solo la zona): permite mostrar el mapa preciso en
// nuestra web y que Mercado Libre muestre transporte/escuelas cercanas.
// Si falla (dirección no encontrada, sin conexión), sigue sin esos datos.
$coords = geocodificar_direccion($direccion, $zona);
if ($coords) {
    $propiedad['lat'] = $coords['lat'];
    $propiedad['lng'] = $coords['lng'];
} elseif (!empty($existente['lat']) && !empty($existente['lng'])) {
    $propiedad['lat'] = $existente['lat'];
    $propiedad['lng'] = $existente['lng'];
}

// --- Guardar en propiedades.json ---
$reemplazado = false;
foreach ($props as $i => $pr) {
    if (($pr['id'] ?? '') === $id) { $props[$i] = $propiedad; $reemplazado = true; break; }
}
if (!$reemplazado) $props[] = $propiedad;
$guardado = propiedades_guardar($props);
if (!$guardado) {
    header('Location: index.php?msg=save_err');
    exit;
}
seg_log($esNueva ? 'propiedad_creada' : 'propiedad_editada', $id . ' · ' . $tipo . ' · ' . $direccion, 'propiedades');

// --- Mercado Libre ---
$msg = 'ok';
if (!empty($fotos)) {
    $resultado = meli_publicar_propiedad($propiedad);
    $props = propiedades_cargar();
    foreach ($props as $i => $pr) {
        if (($pr['id'] ?? '') === $id) {
            if ($resultado['ok']) {
                $props[$i]['meli_item_id'] = $resultado['item_id'];
                unset($props[$i]['meli_error']);
                $msg = 'ok';
            } else {
                $props[$i]['meli_error'] = $resultado['mensaje'];
                $msg = meli_conectado() ? 'meli_err' : 'meli_pend';
            }
            break;
        }
    }
    propiedades_guardar($props);
}

header('Location: index.php?msg=' . $msg);
