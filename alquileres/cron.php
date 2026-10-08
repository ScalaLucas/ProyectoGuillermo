<?php
/**
 * Tarea diaria de avisos por mail. Se ejecuta desde el cron del hosting:
 *   wget -q -O - "https://.../alquileres/cron.php?k=TOKEN"      (el TOKEN se ve en la pantalla Avisos)
 * No requiere sesión: se protege con el token. Si el envío automático está desactivado, no hace nada.
 */
require __DIR__ . '/avisos_lib.php';
header('Content-Type: text/plain; charset=utf-8');
$cfg = avisos_config();
if (PHP_SAPI !== 'cli' && !hash_equals($cfg['token'], (string)($_GET['k'] ?? ''))) { http_response_code(403); echo "Acceso denegado\n"; exit; }
$r = avisos_correr();
seg_log('aviso_cron', json_encode($r), 'alquileres', 'cron');
echo json_encode($r), "\n";
