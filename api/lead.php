<?php
/**
 * Recibe los datos de contacto que el visitante escribe en el chat cuando
 * quiere que lo derivemos a un asesor. A propósito NO pasa por Gemini:
 * los datos personales (nombre, teléfono, email) los procesa solo este script.
 */

define('CHAT_API_ENTRY', true);

header('Content-Type: application/json; charset=utf-8');
error_reporting(0);
ini_set('display_errors', '0');

require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['error' => 'method_not_allowed']);
}
if (!same_origin_ok()) {
    respond(403, ['error' => 'forbidden']);
}

$ip = client_ip();
if (!rate_limit_check($ip)) {
    respond(429, ['ok' => false, 'error' => 'rate_limited']);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    respond(400, ['error' => 'bad_request']);
}

$nombre = trim((string)($input['nombre'] ?? ''));
$telefono = trim((string)($input['telefono'] ?? ''));
$email = trim((string)($input['email'] ?? ''));
$motivo = trim((string)($input['motivo'] ?? ''));
$resumen = trim((string)($input['resumen'] ?? ''));

if ($nombre === '' || $telefono === '') {
    respond(400, ['ok' => false, 'error' => 'faltan_datos']);
}
if (mb_strlen($nombre) > 120) $nombre = mb_substr($nombre, 0, 120);
if (mb_strlen($telefono) > 40) $telefono = mb_substr($telefono, 0, 40);
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $email = '';
}
if (mb_strlen($motivo) > 200) $motivo = mb_substr($motivo, 0, 200);
if (mb_strlen($resumen) > 500) $resumen = mb_substr($resumen, 0, 500);

$datos = negocio_datos_lead();

$lead = [
    'fecha' => date('c'),
    'nombre' => $nombre,
    'telefono' => $telefono,
    'email' => $email,
    'motivo' => $motivo,
    'resumen' => $resumen,
    'ip' => $ip,
];

guardar_lead($lead);
notificar_lead_por_email($lead, $datos);

$mensajeWa = "Hola, soy {$nombre}." . ($motivo !== '' ? " Motivo: {$motivo}." : '') . ($resumen !== '' ? " {$resumen}" : '');
$waLink = 'https://wa.me/' . $datos['whatsapp'] . '?text=' . rawurlencode($mensajeWa);

respond(200, ['ok' => true, 'whatsapp_link' => $waLink]);

/** --- helpers propios de este archivo --- */

function negocio_datos_lead(): array
{
    require_once __DIR__ . '/negocio.php';
    return negocio_datos()['empresa'];
}

function guardar_lead(array $lead): void
{
    $dir = __DIR__ . '/data';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $file = $dir . '/leads.jsonl';
    @file_put_contents($file, json_encode($lead, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}

function notificar_lead_por_email(array $lead, array $empresa): void
{
    $to = $empresa['email'] ?? '';
    if ($to === '' || !function_exists('mail')) return;

    $subject = 'Nuevo interesado desde la web';
    $body = "Nombre: {$lead['nombre']}\n"
        . "Teléfono: {$lead['telefono']}\n"
        . ($lead['email'] !== '' ? "Email: {$lead['email']}\n" : '')
        . ($lead['motivo'] !== '' ? "Motivo: {$lead['motivo']}\n" : '')
        . ($lead['resumen'] !== '' ? "Resumen de la conversación: {$lead['resumen']}\n" : '')
        . "Fecha: {$lead['fecha']}\n";

    $headers = "Content-Type: text/plain; charset=UTF-8\r\n";
    @mail($to, $subject, $body, $headers);
}
