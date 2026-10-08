<?php
/**
 * Callback de notificaciones de Mercado Libre.
 *
 * Esta URL hay que cargarla en tu aplicación de Mercado Libre Developers
 * (sección "Notificaciones"), con el topic "questions" activado. Tiene que
 * ser pública y HTTPS (Mercado Libre no llega a localhost) — solo funciona
 * una vez que el sitio esté publicado en el hosting real.
 *
 * URL a registrar, por ejemplo: https://tu-dominio.com/api/meli-webhook.php
 *
 * Qué hace: cuando alguien pregunta por una propiedad publicada en Mercado
 * Libre, ML llama acá. Buscamos la pregunta, identificamos a qué propiedad
 * corresponde, y avisamos por WhatsApp Business API y por mail.
 */

define('CHAT_API_ENTRY', true);
define('PROPS_ENTRY', true);

header('Content-Type: application/json; charset=utf-8');
error_reporting(0);
ini_set('display_errors', '0');

require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';
require __DIR__ . '/meli.php';
require __DIR__ . '/whatsapp.php';
require __DIR__ . '/propiedades_store.php';

// Mercado Libre espera un 200 rápido; devolvemos siempre 200 salvo método inválido.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['error' => 'method_not_allowed']);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) || ($input['topic'] ?? '') !== 'questions' || empty($input['resource'])) {
    respond(200, ['ok' => true, 'ignorado' => true]);
}

$token = meli_access_token_valido();
if (!$token) {
    error_log('[meli-webhook] Llegó una consulta pero no hay token de Mercado Libre válido.');
    respond(200, ['ok' => true, 'sin_token' => true]);
}

$r = meli_http('GET', MELI_API . $input['resource'], ['headers' => ['Authorization: Bearer ' . $token]]);
if (!$r['ok'] || empty($r['body']['text'])) {
    respond(200, ['ok' => true, 'sin_pregunta' => true]);
}
$pregunta = $r['body'];
$texto = (string)$pregunta['text'];
$itemId = (string)($pregunta['item_id'] ?? '');

$itemInfo = meli_http('GET', MELI_API . '/items/' . $itemId, ['headers' => ['Authorization: Bearer ' . $token]]);
$tituloItem = $itemInfo['ok'] ? (string)($itemInfo['body']['title'] ?? $itemId) : $itemId;
$permalink = $itemInfo['ok'] ? (string)($itemInfo['body']['permalink'] ?? '') : '';

// Intentamos matchear con nuestra propiedad para dar más contexto (dirección real).
$propiedad = null;
foreach (propiedades_cargar() as $p) {
    if (($p['meli_item_id'] ?? '') === $itemId) { $propiedad = $p; break; }
}
$refPropiedad = $propiedad ? ($propiedad['direccion'] . ' (' . $propiedad['id'] . ')') : $tituloItem;

$resumen = "Consulta de Mercado Libre — {$refPropiedad}\n\"{$texto}\"";
if ($permalink) $resumen .= "\n{$permalink}";

waba_enviar_aviso($resumen);
meli_webhook_notificar_por_email($refPropiedad, $texto, $permalink);

respond(200, ['ok' => true]);

function meli_webhook_notificar_por_email(string $refPropiedad, string $texto, string $permalink): void
{
    $empresa = negocio_empresa_email();
    if ($empresa === '' || !function_exists('mail')) return;
    $subject = 'Nueva consulta desde Mercado Libre';
    $body = "Propiedad: {$refPropiedad}\n\nPregunta: {$texto}\n\n" . ($permalink !== '' ? "Publicación: {$permalink}\n" : '') . 'Fecha: ' . date('c') . "\n";
    $headers = "Content-Type: text/plain; charset=UTF-8\r\n";
    @mail($empresa, $subject, $body, $headers);
}

function negocio_empresa_email(): string
{
    require_once __DIR__ . '/negocio.php';
    return negocio_datos()['empresa']['email'] ?? '';
}
