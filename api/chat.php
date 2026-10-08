<?php
/**
 * Proxy seguro para el asistente de IA (Gemini).
 *
 * El navegador nunca ve la clave de Gemini: la pide este script, que corre
 * en el servidor. El frontend solo manda { message, history } y recibe
 * { reply, intent, matched_property_ids, ready_for_handoff }.
 */

define('CHAT_API_ENTRY', true);

header('Content-Type: application/json; charset=utf-8');
error_reporting(0); // nunca mostrar errores de PHP crudos al visitante
ini_set('display_errors', '0');

require __DIR__ . '/config.php';
require __DIR__ . '/negocio.php';
require __DIR__ . '/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['error' => 'method_not_allowed']);
}

if (!same_origin_ok()) {
    respond(403, ['error' => 'forbidden']);
}

$ip = client_ip();
if (!rate_limit_check($ip)) {
    respond(429, [
        'reply' => 'Estamos recibiendo muchas consultas en este momento. Probá de nuevo en unos minutos, o escribinos directo por WhatsApp.',
        'intent' => 'otro',
        'matched_property_ids' => [],
        'ready_for_handoff' => false,
        'rate_limited' => true,
    ]);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    respond(400, ['error' => 'bad_request']);
}

$message = trim((string)($input['message'] ?? ''));
$history = is_array($input['history'] ?? null) ? $input['history'] : [];

if ($message === '') {
    respond(400, ['error' => 'empty_message']);
}
if (mb_strlen($message) > MAX_MESSAGE_CHARS) {
    $message = mb_substr($message, 0, MAX_MESSAGE_CHARS);
}

// Sanea y acota el historial: solo role/text válidos, últimos N turnos.
$cleanHistory = [];
foreach ($history as $turn) {
    if (!is_array($turn)) continue;
    $role = ($turn['role'] ?? '') === 'model' ? 'model' : 'user';
    $text = trim((string)($turn['text'] ?? ''));
    if ($text === '') continue;
    $cleanHistory[] = ['role' => $role, 'text' => mb_substr($text, 0, MAX_MESSAGE_CHARS)];
}
$maxTurns = MAX_HISTORY_TURNS * 2;
if (count($cleanHistory) > $maxTurns) {
    $cleanHistory = array_slice($cleanHistory, -$maxTurns);
}

$datos = negocio_datos();
$systemInstruction = build_system_instruction($datos);

$contents = [];
foreach ($cleanHistory as $turn) {
    $contents[] = ['role' => $turn['role'], 'parts' => [['text' => $turn['text']]]];
}
$contents[] = ['role' => 'user', 'parts' => [['text' => $message]]];

$requestBody = [
    'systemInstruction' => ['parts' => [['text' => $systemInstruction]]],
    'contents' => $contents,
    'generationConfig' => [
        'temperature' => 0.4,
        'maxOutputTokens' => MAX_OUTPUT_TOKENS,
        'thinkingConfig' => ['thinkingLevel' => 'low'],
        'responseMimeType' => 'application/json',
        'responseSchema' => response_schema(),
    ],
];

$result = call_gemini($requestBody);

if ($result === null) {
    respond(200, [
        'reply' => 'Estoy con mucha demanda en este momento. Probá de nuevo en unos segundos, o escribinos por WhatsApp al +54 9 11 4673 8707 y te respondemos enseguida.',
        'intent' => 'otro',
        'matched_property_ids' => [],
        'ready_for_handoff' => false,
        'fallback' => true,
    ]);
}

respond(200, $result);
