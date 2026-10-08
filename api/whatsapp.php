<?php
/**
 * Envío de avisos por WhatsApp Business Platform (Meta Cloud API).
 *
 * Requiere en api/config.php: WABA_PHONE_NUMBER_ID, WABA_ACCESS_TOKEN,
 * WABA_NOTIFY_TO (el número que RECIBE el aviso) y WABA_TEMPLATE_NAME.
 *
 * Por qué hace falta una plantilla ("template"): WhatsApp solo deja mandar
 * texto libre dentro de una conversación que el CLIENTE abrió en las
 * últimas 24hs. Como acá es la inmobiliaria la que quiere avisarse a sí
 * misma de una consulta nueva, hay que iniciar la conversación con una
 * plantilla previamente aprobada por Meta.
 *
 * Cómo crear la plantilla (una única vez):
 *  1) business.facebook.com → WhatsApp Manager → Plantillas de mensajes → Crear.
 *  2) Categoría: "Utilidad". Nombre, por ejemplo: aviso_consulta_meli
 *  3) Cuerpo del mensaje, con una sola variable:
 *       "Nueva consulta de Mercado Libre: {{1}}"
 *  4) Enviar a aprobar (suele tardar minutos a un par de horas).
 *  5) Una vez aprobada, poner ese nombre exacto en WABA_TEMPLATE_NAME.
 */

if (!defined('CHAT_API_ENTRY')) {
    http_response_code(403);
    exit;
}

function waba_configurado(): bool
{
    return WABA_PHONE_NUMBER_ID !== '' && WABA_ACCESS_TOKEN !== '' && WABA_NOTIFY_TO !== '' && WABA_TEMPLATE_NAME !== '';
}

/**
 * Manda el aviso por WhatsApp usando la plantilla configurada, con $texto
 * como única variable {{1}} del cuerpo.
 */
function waba_enviar_aviso(string $texto): array
{
    if (!waba_configurado()) {
        return ['ok' => false, 'mensaje' => 'WhatsApp Business API no está configurado en api/config.php.'];
    }

    $texto = mb_substr($texto, 0, 1000);
    $url = 'https://graph.facebook.com/v20.0/' . WABA_PHONE_NUMBER_ID . '/messages';
    $payload = [
        'messaging_product' => 'whatsapp',
        'to' => WABA_NOTIFY_TO,
        'type' => 'template',
        'template' => [
            'name' => WABA_TEMPLATE_NAME,
            'language' => ['code' => 'es_AR'],
            'components' => [[
                'type' => 'body',
                'parameters' => [['type' => 'text', 'text' => $texto]],
            ]],
        ],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . WABA_ACCESS_TOKEN,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($status < 200 || $status >= 300) {
        $detalle = $raw ? mb_substr($raw, 0, 400) : $err;
        error_log('[whatsapp] Meta devolvió ' . $status . ': ' . $detalle);
        return ['ok' => false, 'mensaje' => 'Meta rechazó el envío: ' . $detalle];
    }
    return ['ok' => true, 'mensaje' => 'Enviado.'];
}
