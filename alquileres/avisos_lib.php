<?php
/**
 * Avisos a inquilinos y propietarios (mail automático + botón de WhatsApp).
 * Registro de envíos en data/avisos.jsonl, configuración en data/avisos-config.json.
 */
require_once __DIR__ . '/lib.php';
define('AVISOS_LOG', ALQ_DATA . '/avisos.jsonl');
define('AVISOS_CFG', ALQ_DATA . '/avisos-config.json');

const AVISOS_TIPOS = [
    'recordatorio' => 'Recordatorio de pago',
    'vencimiento'  => 'Vencimiento',
    'mora'         => 'Pago pendiente',
    'mora_fiador'  => 'Aviso de mora al fiador',
    'ajuste_prox'  => 'Actualización próxima',
    'cobro_ok'     => 'Cobro registrado',
    'ajuste_ok'    => 'Actualización aplicada',
];
const AVISOS_DESTINOS = [
    'recordatorio' => ['inq'], 'vencimiento' => ['inq'], 'mora' => ['inq'], 'mora_fiador' => ['gar1', 'gar2', 'gar_txt'],
    'ajuste_prox' => ['inq', 'prop'], 'cobro_ok' => ['inq', 'prop'], 'ajuste_ok' => ['inq', 'prop'],
];
/** Etiqueta legible de un destinatario ('inq', 'prop', 'gar1', 'gar2', 'gar_txt'). */
function avisos_dest_label(string $dest): string {
    return ['inq' => 'inquilino', 'gar1' => 'fiador 1', 'gar2' => 'fiador 2', 'gar_txt' => 'garantía (nota del contrato)'][$dest] ?? 'propietario';
}
/** Mail encontrado dentro de la nota libre "garantes" (seguro de caución, garantía propietaria, etc.), si hay. */
function avisos_email_garantes(array $c): string {
    return preg_match('/[\w.+-]+@[\w-]+\.[\w.-]+/', (string)($c['garantes'] ?? ''), $m) ? $m[0] : '';
}

/* ---------- configuración ---------- */
function avisos_guardar_config(array $c): bool {
    alq_asegurar_dir();
    return @file_put_contents(AVISOS_CFG, json_encode($c, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) !== false;
}
function avisos_config(): array {
    $def = ['auto' => false, 'dias_antes' => 3, 'dias_mora' => 3, 'dias_mora_fiador' => 5, 'dias_ajuste' => 30,
            'desde' => 'avisos@marceloragonesepropiedades.com', 'copia' => '', 'token' => ''];
    $d = json_decode((string)@file_get_contents(AVISOS_CFG), true);
    $c = array_merge($def, is_array($d) ? $d : []);
    if ($c['token'] === '') { $c['token'] = bin2hex(random_bytes(16)); avisos_guardar_config($c); }
    return $c;
}

/* ---------- registro de envíos ---------- */
function avisos_log_add(array $e): void {
    alq_asegurar_dir();
    $e = ['ts' => date('Y-m-d H:i:s'), 'usuario' => (seg_usuario_actual()['usuario'] ?? ($_SESSION['usuario'] ?? 'auto'))] + $e;
    @file_put_contents(AVISOS_LOG, json_encode($e, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}
function avisos_log_leer(): array {
    $out = [];
    foreach (@file(AVISOS_LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
        $d = json_decode($l, true);
        if (is_array($d)) $out[] = $d;
    }
    return $out;
}
function avisos_clave(string $cid, string $tipo, string $ref, string $dest, string $canal): string { return "$cid|$tipo|$ref|$dest|$canal"; }
/** Mapa clave => ['ok' => bool, 'errores' => int] a partir del registro. */
function avisos_indice(array $log): array {
    $ix = [];
    foreach ($log as $e) {
        $k = avisos_clave($e['cid'] ?? '', $e['tipo'] ?? '', (string)($e['ref'] ?? ''), $e['dest'] ?? '', $e['canal'] ?? '');
        $ix[$k] ??= ['ok' => false, 'errores' => 0];
        if (($e['estado'] ?? '') === 'ok') $ix[$k]['ok'] = true;
        if (($e['estado'] ?? '') === 'error') $ix[$k]['errores']++;
    }
    return $ix;
}

/* ---------- contactos ---------- */
function avisos_es_ejemplo(array $c): bool { return str_starts_with((string)($c['propiedad_txt'] ?? ''), 'EJEMPLO'); }
function avisos_persona(array $c, string $dest): array {
    if ($dest === 'gar_txt') return ['nombre' => 'Garantía del contrato', 'email' => avisos_email_garantes($c)];
    $k = ['inq' => 'inquilino', 'gar1' => 'garante1', 'gar2' => 'garante2'][$dest] ?? 'propietario';
    return $c[$k] ?? [];
}
function avisos_email(array $c, string $dest): string {
    $e = trim((string)(avisos_persona($c, $dest)['email'] ?? ''));
    return filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : '';
}
/** Teléfono en formato internacional para wa.me (Argentina: 549 + área + número). '' si no se puede armar. */
function avisos_telefono(array $c, string $dest): string {
    $d = preg_replace('/\D+/', '', (string)(avisos_persona($c, $dest)['tel'] ?? ''));
    if ($d === '') return '';
    if (str_starts_with($d, '00')) $d = substr($d, 2);
    if (str_starts_with($d, '54')) {
        $d = substr($d, 2);
        if (str_starts_with($d, '9')) $d = substr($d, 1);
    }
    $d = ltrim($d, '0');
    if (strlen($d) === 12 && preg_match('/^(\d{2,4}?)15(\d{6,8})$/', $d, $m) && strlen($m[1] . $m[2]) === 10) $d = $m[1] . $m[2]; // quita el 15
    if (strlen($d) === 10) return '549' . $d; // celular argentino reconocido: se arma el formato exacto de WhatsApp
    // No tiene el formato de celular argentino esperado, pero hay un teléfono cargado: se usa tal cual, con el prefijo de WhatsApp,
    // en vez de dejar el botón sin número. Si el dato está mal cargado, el chat no va a abrir a nadie, pero no se le pisa el intento.
    if (strlen($d) >= 6 && strlen($d) <= 13) return '549' . $d;
    return '';
}

/* ---------- textos ---------- */
function avisos_limpio(string $s): string { return trim(preg_replace('/\s*\(EJEMPLO\)|^EJEMPLO\s*·\s*/u', '', $s)); }
function avisos_cobro(array $c, string $ref): ?array { foreach ($c['cobros'] ?? [] as $co) if ((string)$co['recibo'] === $ref) return $co; return null; }
function avisos_ajuste(array $c, string $ref): ?array { foreach ($c['ajustes'] ?? [] as $a) if ($a['desde'] === $ref) return $a; return null; }
function avisos_venc(array $c, string $per): string { return $per . '-' . str_pad((string)max(1, min(28, (int)($c['dia_venc'] ?? 10))), 2, '0', STR_PAD_LEFT); }

/** [asunto, cuerpo] o null si faltan datos (p. ej. el cobro ya no existe). */
function avisos_mensaje(string $tipo, array $c, string $dest, string $ref): ?array {
    $nom = avisos_limpio((string)(avisos_persona($c, $dest)['nombre'] ?? ''));
    $dir = avisos_limpio((string)($c['propiedad_txt'] ?? ''));
    $firma = "\n\nSaludos cordiales,\n" . ALQ_EMPRESA . "\n" . ALQ_TELEFONO;
    $hola = "Hola $nom,\n\n";
    switch ($tipo) {
        case 'recordatorio': case 'vencimiento': case 'mora':
            $per = $ref;
            $falta = max(0, alq_monto_periodo($c, $per) - alq_cobrado_periodo($c, $per));
            $venc = avisos_venc($c, $per);
            $pp = periodo_es($per);
            if ($tipo === 'recordatorio') return ["Recordatorio: el alquiler de $pp vence el " . fecha_es($venc),
                $hola . "Te recordamos que el alquiler de $pp de $dir vence el " . fecha_es($venc) . ".\n\nMonto a abonar: " . dinero($falta) . "\n\nSi ya realizaste el pago, ignorá este mensaje o enviános el comprobante para registrarlo." . $firma];
            if ($tipo === 'vencimiento') return ["Vencimiento del alquiler de $pp",
                $hola . "El alquiler de $pp de $dir " . (date('Y-m-d') > $venc ? 'venció el ' : 'vence hoy, ') . fecha_es($venc) . " y todavía no registramos el pago.\n\nMonto a abonar: " . dinero($falta) . "\n\nSi ya pagaste, enviános el comprobante para registrarlo." . $firma];
            return ["Pago pendiente: alquiler de $pp",
                $hola . "No registramos el pago del alquiler de $pp de $dir (venció el " . fecha_es($venc) . ").\n\nSaldo pendiente: " . dinero($falta) . "\n\nTe pedimos que lo regularices a la brevedad. Si ya pagaste, enviános el comprobante; si tenés alguna dificultad, comunicate con nosotros y lo vemos." . $firma];
        case 'mora_fiador':
            $per = $ref;
            $falta = max(0, alq_monto_periodo($c, $per) - alq_cobrado_periodo($c, $per));
            $venc = avisos_venc($c, $per);
            $pp = periodo_es($per);
            $inq = avisos_limpio((string)($c['inquilino']['nombre'] ?? 'la parte locataria'));
            $saludo = $dest === 'gar_txt' ? "Estimados,\n\n" : $hola;
            $calidad = $dest === 'gar_txt' ? 'garantía constituida' : 'fiador';
            return ["Aviso de mora — alquiler de $pp — $dir",
                $saludo . "Les informamos, en su carácter de $calidad del contrato de locación de $dir, que $inq se encuentra en mora con el alquiler de $pp (venció el " . fecha_es($venc) . ").\n\nSaldo pendiente: " . dinero($falta) . "\n\nEste aviso se realiza a los efectos previstos en el contrato y no implica prórroga del plazo ni extingue la fianza." . $firma];
        case 'ajuste_prox':
            $pp = periodo_es($ref);
            $ind = ($c['ajuste_tipo'] ?? '') ?: 'el índice pactado';
            if ($dest === 'inq') return ["Tu alquiler se actualiza desde $pp",
                $hola . "Según el contrato de $dir, a partir de $pp el alquiler se actualiza por $ind.\n\nAlquiler actual: " . dinero(alq_monto_actual($c)) . "\n\nCuando esté calculado te confirmamos el nuevo valor." . $firma];
            return ["Actualización próxima del alquiler de $dir",
                $hola . "Te avisamos que a partir de $pp corresponde actualizar el alquiler de $dir por $ind (alquiler actual: " . dinero(alq_monto_actual($c)) . ").\n\nCalculamos el nuevo valor y te lo confirmamos." . $firma];
        case 'cobro_ok':
            $co = avisos_cobro($c, $ref); if (!$co) return null;
            $pp = periodo_es($co['periodo']);
            $extrasIt = alq_extras_items($co);
            $descIt = alq_descuentos_items($co);
            $extra = $extrasIt ? ' más ' . implode(' y ', array_map(fn($it) => dinero($it['monto']) . ' de ' . $it['concepto'], $extrasIt)) : '';
            $extra .= $descIt ? ' menos ' . implode(' y ', array_map(fn($it) => dinero($it['monto']) . ' de ' . $it['concepto'], $descIt)) : '';
            $rec = str_pad((string)$co['recibo'], 6, '0', STR_PAD_LEFT);
            if ($dest === 'inq') return ["Recibimos tu pago — alquiler de $pp",
                $hola . "Registramos tu pago del alquiler de $pp de $dir: " . dinero($co['monto']) . $extra . ", recibido el " . fecha_es($co['fecha']) . ".\n\nRecibo N° $rec.\n\n¡Muchas gracias!" . $firma];
            $com = (float)($c['comision'] ?? 0);
            $neto = (float)$co['monto'] * (1 - $com / 100);
            return ["Cobramos el alquiler de $pp — $dir",
                $hola . "Te avisamos que cobramos el alquiler de $pp de $dir: " . dinero($co['monto']) . ", recibido el " . fecha_es($co['fecha']) . ".\n\nRecibo N° $rec." . ($com > 0 ? "\nA liquidarte: " . dinero($neto) . " (alquiler menos comisión de administración del " . rtrim(rtrim(number_format($com, 2, ',', ''), '0'), ',') . "%)." : '') . $firma];
        case 'ajuste_ok':
            $a = avisos_ajuste($c, $ref); if (!$a) return null;
            $pp = periodo_es($a['desde']);
            $pct = number_format((float)$a['porcentaje'], 2, ',', '.');
            if ($dest === 'inq') return ["Nuevo valor de tu alquiler desde $pp",
                $hola . "Aplicamos la actualización del alquiler de $dir ({$a['indice']}, $pct %).\n\nDesde $pp el alquiler pasa de " . dinero($a['monto_anterior']) . " a " . dinero($a['monto_nuevo']) . "." . $firma];
            return ["Se actualizó el alquiler de $dir desde $pp",
                $hola . "Aplicamos la actualización del alquiler de $dir ({$a['indice']}, $pct %).\n\nDesde $pp el alquiler pasa de " . dinero($a['monto_anterior']) . " a " . dinero($a['monto_nuevo']) . "." . $firma];
    }
    return null;
}

/* ---------- canales ---------- */
function avisos_wa_url(array $c, string $tipo, string $ref, string $dest): string {
    $m = avisos_mensaje($tipo, $c, $dest, $ref);
    $tel = avisos_es_ejemplo($c) ? '' : avisos_telefono($c, $dest); // los ejemplos nunca apuntan a un número real
    return 'https://wa.me/' . $tel . '?text=' . rawurlencode($m ? "*{$m[0]}*\n\n{$m[1]}" : '');
}
function avisos_transporte(string $to, string $asunto, string $cuerpo, array $cfg): bool {
    if (!empty($GLOBALS['AVISOS_TRANSPORTE'])) return (bool)$GLOBALS['AVISOS_TRANSPORTE']($to, $asunto, $cuerpo, $cfg);
    $h = ['MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: 8bit',
          'From: ' . ALQ_EMPRESA . ' <' . $cfg['desde'] . '>', 'Reply-To: ' . ALQ_EMAIL];
    if (filter_var($cfg['copia'], FILTER_VALIDATE_EMAIL)) $h[] = 'Bcc: ' . $cfg['copia'];
    return @mail($to, '=?UTF-8?B?' . base64_encode($asunto) . '?=', $cuerpo, implode("\r\n", $h), '-f' . $cfg['desde']);
}
/** Envía un aviso por mail y lo registra. Devuelve ok | error | sin_dato | ejemplo | sin_texto. */
function avisos_enviar_mail(array $c, string $tipo, string $ref, string $dest): string {
    $base = ['cid' => $c['id'], 'tipo' => $tipo, 'ref' => $ref, 'dest' => $dest, 'canal' => 'mail'];
    if (avisos_es_ejemplo($c)) { avisos_log_add($base + ['estado' => 'ejemplo']); return 'ejemplo'; }
    $to = avisos_email($c, $dest);
    if ($to === '') { avisos_log_add($base + ['estado' => 'sin_dato']); return 'sin_dato'; }
    $m = avisos_mensaje($tipo, $c, $dest, $ref);
    if (!$m) return 'sin_texto';
    $ok = avisos_transporte($to, $m[0], $m[1], avisos_config());
    avisos_log_add($base + ['estado' => $ok ? 'ok' : 'error', 'a' => $to, 'asunto' => $m[0]]);
    return $ok ? 'ok' : 'error';
}

/* ---------- qué corresponde avisar ---------- */
/** Avisos por fechas (recordatorio / vencimiento / mora / actualización próxima) que corresponden hoy. */
function avisos_scan(?array $contratos = null, ?string $hoy = null): array {
    $cfg = avisos_config();
    $contratos ??= alq_cargar();
    $h = new DateTimeImmutable($hoy ?? date('Y-m-d'));
    $act = $h->format('Y-m');
    $out = [];
    foreach ($contratos as $c) {
        if (($c['estado'] ?? 'vigente') !== 'vigente' || !empty($c['sin_avisos'])) continue;
        $ini = substr($c['inicio'], 0, 7); $fin = substr(alq_fin($c), 0, 7);
        foreach ([periodo_mas($act, -1), $act, periodo_mas($act, 1)] as $p) {
            if ($p < $ini || $p > $fin) continue;
            if (alq_cobrado_periodo($c, $p) >= alq_monto_periodo($c, $p) - 0.5) continue;
            $venc = new DateTimeImmutable(avisos_venc($c, $p));
            $antes = $venc->modify('-' . (int)$cfg['dias_antes'] . ' days');
            $mora = $venc->modify('+' . max(1, (int)$cfg['dias_mora']) . ' days');
            $tope = $venc->modify('+30 days');
            $tipo = null;
            if ($h >= $antes && $h < $venc) $tipo = 'recordatorio';
            elseif ($h >= $venc && $h < $mora) $tipo = 'vencimiento';
            elseif ($h >= $mora && $h < $tope) $tipo = 'mora';
            if ($tipo) $out[] = ['cid' => $c['id'], 'tipo' => $tipo, 'ref' => $p, 'dest' => 'inq'];
            $moraFiador = $venc->modify('+' . max(1, (int)$cfg['dias_mora_fiador']) . ' days');
            if ($h >= $moraFiador && $h < $tope) {
                foreach (['gar1' => 'garante1', 'gar2' => 'garante2'] as $gd => $gk) {
                    if (trim((string)($c[$gk]['nombre'] ?? '')) !== '') $out[] = ['cid' => $c['id'], 'tipo' => 'mora_fiador', 'ref' => $p, 'dest' => $gd];
                }
                // La nota libre "Garantes" (seguro de caución, garantía propietaria, etc.) no tiene
                // nombre/teléfono estructurado: solo se puede avisar si tiene un mail adentro del texto.
                if (avisos_email_garantes($c) !== '') $out[] = ['cid' => $c['id'], 'tipo' => 'mora_fiador', 'ref' => $p, 'dest' => 'gar_txt'];
            }
        }
        $pa = alq_prox_ajuste($c);
        if ($pa) {
            $f = new DateTimeImmutable($pa . '-01');
            if ($h >= $f->modify('-' . (int)$cfg['dias_ajuste'] . ' days') && $h <= $f)
                foreach (['inq', 'prop'] as $d) $out[] = ['cid' => $c['id'], 'tipo' => 'ajuste_prox', 'ref' => $pa, 'dest' => $d];
        }
    }
    return $out;
}
/** Tarea diaria: envía por mail lo que corresponde y todavía no se envió. Solo si el envío automático está activado. */
function avisos_correr(?array $contratos = null): array {
    $r = ['activo' => false, 'ok' => 0, 'error' => 0, 'sin_dato' => 0, 'ya_enviados' => 0];
    if (empty(avisos_config()['auto'])) return $r;
    $r['activo'] = true;
    $contratos ??= alq_cargar();
    $ix = avisos_indice(avisos_log_leer());
    $desde = (string)(avisos_config()['auto_desde'] ?? '');
    foreach (avisos_scan($contratos) as $a) {
        // Los avisos por fecha solo rigen desde el mes en que se activó el envío automático (no reclama meses anteriores).
        if ($a['tipo'] !== 'ajuste_prox' && $desde !== '' && $a['ref'] < $desde) continue;
        $k = $ix[avisos_clave($a['cid'], $a['tipo'], $a['ref'], $a['dest'], 'mail')] ?? ['ok' => false, 'errores' => 0];
        if ($k['ok'] || $k['errores'] >= 3) { $r['ya_enviados']++; continue; }
        $c = alq_encontrar($contratos, $a['cid']);
        if (!$c || avisos_es_ejemplo($c)) continue;
        if (!avisos_email($c, $a['dest'])) { $r['sin_dato']++; continue; } // se avisa a mano; no se llena el registro todos los días
        $res = avisos_enviar_mail($c, $a['tipo'], $a['ref'], $a['dest']);
        if (isset($r[$res])) $r[$res]++;
    }
    return $r;
}
/** Aviso inmediato al registrar un cobro o una actualización (si el envío automático está activado). */
function avisos_evento(array $c, string $tipo, string $ref): void {
    if (empty(avisos_config()['auto']) || !empty($c['sin_avisos'])) return;
    foreach (AVISOS_DESTINOS[$tipo] as $d) avisos_enviar_mail($c, $tipo, $ref, $d);
}

/* ---------- botones (contrato.php) ---------- */
function avisos_botones(array $c, string $tipo, string $ref): string {
    $ix = avisos_indice(avisos_log_leer());
    $s = '<div class="avisos"><span class="hint">Avisar:</span> ';
    foreach (AVISOS_DESTINOS[$tipo] as $d) {
        $lab = avisos_dest_label($d);
        $mail = avisos_email($c, $d); $tel = avisos_telefono($c, $d);
        $hecho = fn(string $canal) => !empty($ix[avisos_clave($c['id'], $tipo, $ref, $d, $canal)]['ok']);
        $s .= '<span class="av-d">' . h($lab) . ': ';
        if ($mail) {
            $s .= '<form method="post" action="avisos.php" style="display:inline"><input type="hidden" name="csrf" value="' . h(alq_csrf()) . '"><input type="hidden" name="accion" value="mail"><input type="hidden" name="cid" value="' . h($c['id']) . '"><input type="hidden" name="tipo" value="' . h($tipo) . '"><input type="hidden" name="ref" value="' . h($ref) . '"><input type="hidden" name="dest" value="' . $d . '"><input type="hidden" name="volver" value="contrato"><button class="btn sm gh" type="submit">✉ Mail' . ($hecho('mail') ? ' ✓' : '') . '</button></form> ';
        }
        $s .= '<a class="btn sm gh" target="_blank" rel="noopener" href="' . h(avisos_url_wa_click($c['id'], $tipo, $ref, $d)) . '">WhatsApp' . ($hecho('wa') ? ' ✓' : ($tel || avisos_es_ejemplo($c) ? '' : ' (sin nº, elegís el contacto)')) . '</a>';
        if (!$mail) $s .= '<span class="hint">sin mail cargado</span>';
        $s .= '</span> ';
    }
    return $s . '</div>';
}
function avisos_url_wa_click(string $cid, string $tipo, string $ref, string $dest): string {
    return 'avisos.php?ir=1&c=' . urlencode($cid) . '&t=' . urlencode($tipo) . '&r=' . urlencode($ref) . '&d=' . urlencode($dest);
}
