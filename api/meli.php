<?php
/**
 * Integración con Mercado Libre (Argentina).
 *
 * IMPORTANTE — leído esto antes de esperar que la publicación funcione solo:
 * la categoría "Inmuebles" de Mercado Libre Argentina es un caso especial
 * (vertical "real_estate"). Al consultar la API pública de categorías,
 * "listing_allowed" figura en false y la categoría no usa el sistema de
 * atributos común ("attributable": false) — a diferencia de una categoría
 * normal de productos. Eso quiere decir que publicar ahí puede requerir que
 * la cuenta de Mercado Libre de la inmobiliaria tenga habilitado el producto
 * "Mercado Libre Inmuebles" (a veces se gestiona por feed/XML con un
 * ejecutivo de cuenta, no por API abierta). Este archivo intenta publicar
 * por la vía estándar (API de Items); si Mercado Libre lo rechaza, el motivo
 * exacto que devuelvan queda guardado en meli_error de la propiedad (se ve
 * en el panel) — con ese mensaje se ajusta esta función.
 *
 * Flujo:
 *  1) Cargar MELI_CLIENT_ID / MELI_CLIENT_SECRET / MELI_REDIRECT_URI en api/config.php
 *  2) Entrar a admin/meli-connect.php y autorizar con la cuenta real de ML
 *  3) A partir de ahí, cada alta/edición en el panel intenta publicar sola
 */

if (!defined('CHAT_API_ENTRY')) {
    http_response_code(403);
    exit;
}

define('MELI_TOKENS_PATH', __DIR__ . '/data/meli_tokens.json');
define('MELI_API', 'https://api.mercadolibre.com');

// tipo + operación (nuestra web) => id de categoría HOJA real de Mercado Libre.
// Cada categoría de Inmuebles tiene subcategorías Alquiler/Venta, y varias
// "Venta" tienen un nivel más adentro (Emprendimientos vs. Propiedades
// Individuales — "en pozo" vs. reventa). Usamos siempre "Propiedades
// Individuales": es lo correcto para el caso normal de reventa. Las dos
// propiedades "emprendimiento" que haya (entrega a futuro, en pozo) van a
// quedar publicadas en la categoría equivocada — si hace falta publicarlas
// bien, avisar para mapearlas aparte a su categoría "Emprendimientos".
// (confirmado contra la API pública de categorías el 2026-08-11)
const MELI_CATEGORIAS = [
    'Departamento' => ['Venta' => 'MLA401686', 'Alquiler' => 'MLA1473'],
    'Casa' => ['Venta' => 'MLA401685', 'Alquiler' => 'MLA1467'],
    'Chalet' => ['Venta' => 'MLA401685', 'Alquiler' => 'MLA1467'], // ML no tiene categoría propia; entra en Casas
    'PH' => ['Venta' => 'MLA105182', 'Alquiler' => 'MLA105181'],
    'Dúplex' => ['Venta' => 'MLA401685', 'Alquiler' => 'MLA1467'], // ML no tiene categoría propia; entra en Casas
    'Tríplex' => ['Venta' => 'MLA401685', 'Alquiler' => 'MLA1467'], // ML no tiene categoría propia; entra en Casas
    'Terreno' => ['Venta' => 'MLA401687', 'Alquiler' => 'MLA1494'],
    'Lote' => ['Venta' => 'MLA401687', 'Alquiler' => 'MLA1494'], // igual que Terreno
    'Local' => ['Venta' => 'MLA79244', 'Alquiler' => 'MLA79243'],
    'Salón' => ['Venta' => 'MLA79244', 'Alquiler' => 'MLA79243'], // ML no tiene categoría propia; entra en Locales
    'Oficina' => ['Venta' => 'MLA401684', 'Alquiler' => 'MLA50539'],
];

// zona (nuestra web) => id de ciudad de Mercado Libre. Los items de Inmuebles
// exigen "location" con ciudad como mínimo (no alcanza con la dirección en texto).
// OJO: la ciudad de ML es a nivel partido/localidad grande, no a nivel barrio —
// por eso varios barrios de un mismo partido comparten el mismo id. Si aparece
// una zona nueva que no matchea acá, revisar meli_ciudad_para_zona() más abajo.
const MELI_CIUDADES = [
    'Ramos Mejía' => 'TUxBQ0xBTWF0YW56',       // La Matanza
    'Villa Luzuriaga' => 'TUxBQ0xBTWF0YW56',   // La Matanza
    'Lomas del Mirador' => 'TUxBQ0xBTWF0YW56', // La Matanza
    'Gregorio de Laferrere' => 'TUxBQ0xBTWF0YW56', // La Matanza
    'La Fraternidad' => 'TUxBQ0xBTWF0YW56',    // La Matanza
    'San Justo' => 'TUxBQ0xBTWF0YW56',         // La Matanza
    'Ciudadela' => 'TUxBQ1RSRTMxODE5NA',       // Tres de Febrero
    'Caseros' => 'TUxBQ0NBUzE3NTU5Mg',
    'San Martín' => 'TUxBQ0dFTmVyYWxz',        // General San Martín
    'Francisco Álvarez' => 'TUxBQ01PUmViMTE3', // Moreno
    'Ituzaingó' => 'TUxBQ0lUVTNjNDFm',
    'Haedo' => 'TUxBQ01PUmI1NTBj',             // Morón
    'Villa Sarmiento' => 'TUxBQ01PUmI1NTBj',   // Morón
];

/**
 * Busca la ciudad de ML para una zona de forma tolerante: probá exacto primero
 * y, si no matchea, normalizá (sin tildes, minúsculas) y buscá por el nombre
 * base de la zona (ej. "Ramos Mejía Sur", "Ramos Mejia Norte" y "San Justo
 * (Centro)" deben resolver igual que su localidad base). Devuelve null si de
 * verdad no hay ninguna localidad conocida que matchee — ahí sí hay que avisar
 * a Marcelo en vez de adivinar.
 */
function meli_ciudad_para_zona(string $zona): ?string
{
    if (isset(MELI_CIUDADES[$zona])) return MELI_CIUDADES[$zona];
    $norm = static function (string $s): string {
        $s = mb_strtolower(trim($s), 'UTF-8');
        $s = str_replace(['á', 'é', 'í', 'ó', 'ú'], ['a', 'e', 'i', 'o', 'u'], $s);
        return preg_replace('/\s+/', ' ', $s);
    };
    $zn = $norm($zona);
    foreach (MELI_CIUDADES as $nombre => $id) {
        if (str_starts_with($zn, $norm($nombre))) return $id;
    }
    return null;
}

function meli_configurado(): bool
{
    return MELI_CLIENT_ID !== '' && MELI_CLIENT_SECRET !== '' && MELI_REDIRECT_URI !== '';
}

function meli_conectado(): bool
{
    $t = meli_tokens_leer();
    return !empty($t['access_token']);
}

function meli_tokens_leer(): array
{
    $raw = @file_get_contents(MELI_TOKENS_PATH);
    $data = $raw ? json_decode($raw, true) : null;
    return is_array($data) ? $data : [];
}

function meli_tokens_guardar(array $tokens): void
{
    $dir = dirname(MELI_TOKENS_PATH);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    @file_put_contents(MELI_TOKENS_PATH, json_encode($tokens, JSON_PRETTY_PRINT), LOCK_EX);
}

function meli_authorize_url(string $state): string
{
    return 'https://auth.mercadolibre.com.ar/authorization?' . http_build_query([
        'response_type' => 'code',
        'client_id' => MELI_CLIENT_ID,
        'redirect_uri' => MELI_REDIRECT_URI,
        'state' => $state,
    ]);
}

function meli_http(string $metodo, string $url, array $opciones = []): array
{
    $ch = curl_init($url);
    $headers = $opciones['headers'] ?? [];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $metodo,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if (isset($opciones['json'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($opciones['json'], JSON_UNESCAPED_UNICODE));
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    } elseif (isset($opciones['form'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $opciones['form']);
    }
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) return ['ok' => false, 'status' => 0, 'body' => null, 'error' => $err];
    $body = json_decode($raw, true);
    return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => $body, 'error' => null];
}

/** Intercambia el "code" del callback OAuth por tokens y los guarda. */
function meli_exchange_code(string $code): array
{
    $r = meli_http('POST', MELI_API . '/oauth/token', [
        'headers' => ['Accept: application/json'],
        'form' => [
            'grant_type' => 'authorization_code',
            'client_id' => MELI_CLIENT_ID,
            'client_secret' => MELI_CLIENT_SECRET,
            'code' => $code,
            'redirect_uri' => MELI_REDIRECT_URI,
        ],
    ]);
    if (!$r['ok'] || empty($r['body']['access_token'])) {
        return ['ok' => false, 'mensaje' => 'Mercado Libre rechazó la autorización: ' . json_encode($r['body'] ?? $r['error'])];
    }
    $b = $r['body'];
    meli_tokens_guardar([
        'access_token' => $b['access_token'],
        'refresh_token' => $b['refresh_token'] ?? null,
        'user_id' => $b['user_id'] ?? null,
        'expira_en' => time() + (int)($b['expires_in'] ?? 21600) - 60,
    ]);
    return ['ok' => true, 'mensaje' => 'Cuenta de Mercado Libre conectada.'];
}

function meli_refrescar_token(): bool
{
    $t = meli_tokens_leer();
    if (empty($t['refresh_token'])) return false;
    $r = meli_http('POST', MELI_API . '/oauth/token', [
        'headers' => ['Accept: application/json'],
        'form' => [
            'grant_type' => 'refresh_token',
            'client_id' => MELI_CLIENT_ID,
            'client_secret' => MELI_CLIENT_SECRET,
            'refresh_token' => $t['refresh_token'],
        ],
    ]);
    if (!$r['ok'] || empty($r['body']['access_token'])) return false;
    $b = $r['body'];
    meli_tokens_guardar([
        'access_token' => $b['access_token'],
        'refresh_token' => $b['refresh_token'] ?? $t['refresh_token'],
        'user_id' => $b['user_id'] ?? $t['user_id'] ?? null,
        'expira_en' => time() + (int)($b['expires_in'] ?? 21600) - 60,
    ]);
    return true;
}

function meli_access_token_valido(): ?string
{
    $t = meli_tokens_leer();
    if (empty($t['access_token'])) return null;
    if (($t['expira_en'] ?? 0) < time()) {
        if (!meli_refrescar_token()) return null;
        $t = meli_tokens_leer();
    }
    return $t['access_token'] ?? null;
}

/** Sube una foto ya guardada en assets/propiedades/ y devuelve el picture_id de Mercado Libre. */
function meli_subir_foto(string $token, string $rutaAbsoluta): ?string
{
    if (!is_file($rutaAbsoluta)) return null;
    $ch = curl_init(MELI_API . '/pictures/items/upload');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
        CURLOPT_POSTFIELDS => ['file' => new CURLFile($rutaAbsoluta, 'image/webp', basename($rutaAbsoluta))],
    ]);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status < 200 || $status >= 300) return null;
    $data = json_decode($raw, true);
    return $data['id'] ?? null;
}

/**
 * Consulta en Mercado Libre si una publicación sigue existiendo (por si se
 * borró o se cerró directamente desde Mercado Libre, sin pasar por acá).
 * Devuelve ['existe' => bool, 'status' => string|null, 'mensaje' => string].
 * "existe" en false significa "hay que desvincularla": no se encontró, o
 * Mercado Libre la tiene cerrada (equivalente a borrada para el vendedor).
 */
function meli_verificar_item(string $itemId): array
{
    $token = meli_access_token_valido();
    if (!$token) return ['existe' => true, 'status' => null, 'mensaje' => 'Mercado Libre no está conectado; no se pudo verificar.'];

    $r = meli_http('GET', MELI_API . '/items/' . urlencode($itemId) . '?attributes=id,status,permalink', [
        'headers' => ['Authorization: Bearer ' . $token],
    ]);

    if ($r['status'] === 404) {
        return ['existe' => false, 'status' => null, 'mensaje' => 'Ya no existe en Mercado Libre (se borró).'];
    }
    if (!$r['ok'] || !is_array($r['body']) || empty($r['body']['id'])) {
        // Error de red, permisos u otro problema puntual: no se toca el vínculo,
        // para no desvincular algo que en realidad sigue publicado.
        $detalle = is_array($r['body']) ? json_encode($r['body'], JSON_UNESCAPED_UNICODE) : ($r['error'] ?? 'sin detalle');
        return ['existe' => true, 'status' => null, 'mensaje' => 'No se pudo verificar (' . $detalle . ').'];
    }
    $status = (string)($r['body']['status'] ?? '');
    if ($status === 'closed') {
        return ['existe' => false, 'status' => $status, 'mensaje' => 'Está cerrada en Mercado Libre (equivale a borrada).'];
    }
    $legible = ['active' => 'Activa', 'paused' => 'Pausada', 'under_review' => 'En revisión', 'payment_required' => 'Pendiente de pago'][$status] ?? $status;
    return ['existe' => true, 'status' => $status, 'mensaje' => 'Sigue en Mercado Libre: ' . $legible . '.'];
}

/**
 * Los tipos de publicación disponibles dependen de la cuenta y la categoría
 * (plan pago, cupo de gratuitas, etc.) — no son fijos, hay que consultarlos
 * en cada publicación y elegir el mejor disponible.
 */
function meli_elegir_listing_type(string $token, string $userId, string $categoria): ?string
{
    $r = meli_http('GET', MELI_API . '/users/' . $userId . '/available_listing_types?category_id=' . $categoria, [
        'headers' => ['Authorization: Bearer ' . $token],
    ]);
    $disponibles = array_column($r['body']['available'] ?? [], 'id');
    if (!$disponibles) return null;

    // Preferimos SIEMPRE la opción gratuita: el código no debe generar un gasto
    // sin que la inmobiliaria lo decida a propósito desde Mercado Libre. Si
    // algún día quieren pagar por más visibilidad, esa elección la hacen ellos
    // manualmente en Mercado Libre, no este script.
    $prioridad = ['free', 'bronze', 'silver', 'gold', 'gold_premium', 'gold_special', 'gold_pro'];
    foreach ($prioridad as $tipo) {
        if (in_array($tipo, $disponibles, true)) return $tipo;
    }
    return $disponibles[0];
}

function meli_normalizar_texto(string $s): string
{
    $s = mb_strtolower(trim($s));
    $s = str_replace(['á', 'é', 'í', 'ó', 'ú', 'ñ'], ['a', 'e', 'i', 'o', 'u', 'n'], $s);
    return $s;
}

/**
 * Cada categoría de Inmuebles tiene su propio listado de atributos (superficie,
 * dormitorios, ascensor, pileta, admite mascotas, etc.). Los nombres de esos
 * atributos en Mercado Libre coinciden casi siempre con las etiquetas que ya
 * usamos en "características" (ej. "Ascensor", "Pileta", "Admite mascotas"),
 * así que los emparejamos por nombre en vez de mapear cada uno a mano.
 * Los campos estructurados de la web (m², ambientes, dormitorios, baños)
 * completan lo que "características" no traiga.
 */
/**
 * Devuelve ['atributos' => [...], 'extras' => [...]]. "extras" son las
 * características que no tienen campo equivalente en Mercado Libre (ni
 * siquiera por alias) pero valen la pena mencionar: van como texto en la
 * descripción, agrupadas, en vez de perderse. Se excluyen los "No" (no aportan
 * nada al comprador) y los campos internos de uso propio (Operación, etc.).
 */
function meli_construir_atributos(string $token, string $categoria, array $propiedad): array
{
    $r = meli_http('GET', MELI_API . '/categories/' . $categoria . '/attributes', [
        'headers' => ['Authorization: Bearer ' . $token],
    ]);
    $defs = $r['ok'] && is_array($r['body']) ? $r['body'] : [];

    $porNombre = [];
    $porId = [];
    foreach ($defs as $def) {
        $porId[$def['id'] ?? ''] = $def;
        $nombre = meli_normalizar_texto((string)($def['name'] ?? ''));
        if ($nombre !== '') $porNombre[$nombre] = $def;
    }

    $atributos = [];
    $usados = [];

    // Algunas etiquetas nuestras significan lo mismo que un atributo de Mercado
    // Libre pero con otro nombre exacto (el emparejamiento por nombre no las
    // encuentra solas). Confirmado contra el catálogo real de la categoría.
    $alias = [
        'escritorio' => 'estudio',
        'armarios empotrados' => 'placards',
    ];

    // 1) Desde "características" (texto libre agrupado) — es lo más específico
    //    que tenemos, incluye cosas que la web no pide como campo aparte.
    // En emprendimientos "en pozo" suele venir como rango ("85 m² a 201 m²",
    // "3 a 4" ambientes): eso Mercado Libre no lo acepta en un campo numérico,
    // así que esos casos se saltan acá y quedan para completarse en el paso 2.
    $extras = [];
    foreach ((array)($propiedad['caracteristicas'] ?? []) as $grupo => $items) {
        if (!is_array($items)) continue;
        foreach ($items as $etiqueta => $valor) {
            $valor = trim((string)$valor);
            if ($valor === '') continue;
            $clave = meli_normalizar_texto((string)$etiqueta);
            $clave = $alias[$clave] ?? $clave;
            $def = $porNombre[$clave] ?? null;
            if (!$def || isset($usados[$def['id']])) {
                // Sin campo equivalente en Mercado Libre: si dice algo (no es un
                // "No" liso), se guarda para agregarlo como texto a la descripción.
                if (!$def && mb_strtolower($valor) !== 'no') {
                    $extras[] = (mb_strtolower((string)$valor) === 'sí' || mb_strtolower((string)$valor) === 'si')
                        ? (string)$etiqueta
                        : $etiqueta . ': ' . $valor;
                }
                continue;
            }
            $esNumerico = in_array($def['value_type'] ?? '', ['number', 'number_unit'], true);
            if ($esNumerico && preg_match('/\d[^\d]*(\b[aA]\b|-|–|\bhasta\b)[^\d]*\d/u', $valor)) continue;
            $atributos[] = ['id' => $def['id'], 'value_name' => $valor];
            $usados[$def['id']] = true;
        }
    }

    // 2) Completa con los campos estructurados de la web (m², ambientes,
    //    dormitorios, baños) solo si "características" no los trajo ya.
    $valores = [
        'TOTAL_AREA' => $propiedad['m2'] ?? null,
        'COVERED_AREA' => $propiedad['m2'] ?? null,
        'BEDROOMS' => $propiedad['dorm'] ?? null,
        'ROOMS' => $propiedad['amb'] ?? null,
        'FULL_BATHROOMS' => $propiedad['banos'] ?? null,
        'BATHROOMS' => $propiedad['banos'] ?? null,
        'PARKING_LOTS' => 0,
    ];
    foreach ($valores as $id => $valor) {
        if (isset($usados[$id]) || $valor === null || !isset($porId[$id])) continue;
        $def = $porId[$id];
        if (($def['value_type'] ?? '') === 'number_unit') {
            $unidad = $def['default_unit'] ?? ($def['allowed_units'][0]['id'] ?? '');
            $numero = (float)$valor;
            $atributos[] = ['id' => $id, 'value_name' => (fmod($numero, 1.0) === 0.0 ? (string)(int)$numero : (string)$numero) . ' ' . $unidad];
        } else {
            $atributos[] = ['id' => $id, 'value_name' => (string)$valor];
        }
        $usados[$id] = true;
    }

    return ['atributos' => $atributos, 'extras' => $extras];
}

/**
 * Publica (alta) o actualiza una propiedad en Mercado Libre.
 * Devuelve ['ok'=>bool, 'mensaje'=>string, 'item_id'=>?string]
 */
function meli_publicar_propiedad(array $propiedad): array
{
    if (!meli_configurado()) {
        return ['ok' => false, 'mensaje' => 'Mercado Libre no está configurado (faltan client_id/secret en api/config.php).', 'item_id' => null];
    }
    $token = meli_access_token_valido();
    if (!$token) {
        return ['ok' => false, 'mensaje' => 'Mercado Libre no está conectado. Entrá a "Conectar Mercado Libre" en el panel.', 'item_id' => null];
    }
    $categoria = MELI_CATEGORIAS[$propiedad['tipo'] ?? ''][$propiedad['operacion'] ?? ''] ?? null;
    if (!$categoria) {
        return ['ok' => false, 'mensaje' => 'No hay categoría de Mercado Libre asignada para "' . ($propiedad['tipo'] ?? '') . '" en "' . ($propiedad['operacion'] ?? '') . '".', 'item_id' => null];
    }
    $ciudad = meli_ciudad_para_zona($propiedad['zona'] ?? '');
    if (!$ciudad) {
        return ['ok' => false, 'mensaje' => 'No hay ciudad de Mercado Libre asignada para la zona "' . ($propiedad['zona'] ?? '') . '".', 'item_id' => null];
    }
    if (empty($propiedad['fotos'])) {
        return ['ok' => false, 'mensaje' => 'La propiedad no tiene fotos; Mercado Libre exige al menos una.', 'item_id' => null];
    }

    // Precio: separamos moneda y monto de nuestro campo de texto libre (ej: "U$S 60.000" / "$ 900.000").
    $precioTxt = (string)($propiedad['precio'] ?? '');
    $moneda = (stripos($precioTxt, 'U$S') !== false || stripos($precioTxt, 'USD') !== false) ? 'USD' : 'ARS';
    $monto = (float)preg_replace('/[^\d]/', '', $precioTxt);
    if ($monto <= 0) {
        return ['ok' => false, 'mensaje' => 'No se pudo interpretar el precio "' . $precioTxt . '" para publicarlo en Mercado Libre.', 'item_id' => null];
    }

    $userId = (string)(meli_tokens_leer()['user_id'] ?? '');
    $listingType = $userId !== '' ? meli_elegir_listing_type($token, $userId, $categoria) : null;
    if (!$listingType) {
        return ['ok' => false, 'mensaje' => 'No hay ningún tipo de publicación disponible para esta cuenta/categoría en Mercado Libre (puede ser un tema de cupo o de plan). Revisá en Mercado Libre qué tipos de publicación tenés habilitados para Inmuebles.', 'item_id' => null];
    }

    // Antes limitado a 10: Mercado Libre acepta más fotos en Inmuebles; si en algún
    // caso rechaza el alta por exceso de fotos, el error queda visible en el panel.
    $pictures = [];
    foreach (array_slice($propiedad['fotos'], 0, 30) as $foto) {
        $abs = __DIR__ . '/../' . $foto;
        $picId = meli_subir_foto($token, $abs);
        if ($picId) $pictures[] = ['id' => $picId];
    }
    if (!$pictures) {
        return ['ok' => false, 'mensaje' => 'No se pudo subir ninguna foto a Mercado Libre.', 'item_id' => null];
    }

    $titulo = mb_substr($propiedad['titulo'] ?? ($propiedad['tipo'] . ' en ' . $propiedad['operacion'] . ' - ' . $propiedad['direccion']), 0, 200);
    $descripcion = (string)($propiedad['descripcion'] ?? ('Consultá por esta propiedad ubicada en ' . ($propiedad['direccion'] ?? '') . '.'));
    ['atributos' => $atributos, 'extras' => $extras] = meli_construir_atributos($token, $categoria, $propiedad);
    // Características sin campo propio en Mercado Libre: se agregan como texto
    // al final de la descripción, así no se pierden aunque no queden como
    // atributos filtrables.
    if ($extras) {
        $descripcion .= "\n\nOtras características:\n• " . implode("\n• ", $extras);
    }

    $payload = [
        'title' => $titulo,
        'category_id' => $categoria,
        'price' => $monto,
        'currency_id' => $moneda,
        'available_quantity' => 1,
        'buying_mode' => 'classified',
        'listing_type_id' => $listingType,
        'condition' => 'not_specified',
        'pictures' => $pictures,
        'attributes' => $atributos,
        'location' => array_filter([
            'address_line' => (string)($propiedad['direccion'] ?? ''),
            'city' => ['id' => $ciudad],
            'state' => ['id' => 'AR-B'],
            'country' => ['id' => 'AR'],
            // Con lat/lng, Mercado Libre muestra el mapa y los lugares
            // cercanos (transporte, escuelas, etc.) en la publicación.
            'latitude' => $propiedad['lat'] ?? null,
            'longitude' => $propiedad['lng'] ?? null,
        ]),
        'description' => ['plain_text' => mb_substr($descripcion, 0, 49000)],
    ];

    $existente = $propiedad['meli_item_id'] ?? null;
    $metodo = $existente ? 'PUT' : 'POST';
    $url = MELI_API . '/items' . ($existente ? '/' . $existente : '');
    if ($existente) unset($payload['category_id']); // ML no permite cambiar categoría en un update

    $r = meli_http($metodo, $url, [
        'headers' => ['Authorization: Bearer ' . $token],
        'json' => $payload,
    ]);

    // Mercado Libre puede devolver el ítem creado (con su "id") incluso cuando
    // el código HTTP no cae en el rango 2xx esperado (por ejemplo, ítems que
    // quedan pendientes de un paso extra). Lo que realmente indica éxito es
    // que la respuesta traiga el "id" del ítem creado.
    $itemIdCreado = is_array($r['body']) ? ($r['body']['id'] ?? null) : null;
    if (!$itemIdCreado) {
        $detalle = is_array($r['body']) ? json_encode($r['body'], JSON_UNESCAPED_UNICODE) : ($r['error'] ?? 'error desconocido');
        return ['ok' => false, 'mensaje' => 'Mercado Libre rechazó la publicación (HTTP ' . $r['status'] . '): ' . mb_substr($detalle, 0, 1500), 'item_id' => null];
    }

    $itemId = $r['body']['id'] ?? $existente;

    // La descripción de Inmuebles no queda guardada con el POST/PUT de /items;
    // Mercado Libre la maneja como un recurso aparte. Se intenta actualizar
    // (por si ya existe) y si no, se crea. No bloquea el éxito de la
    // publicación: la propiedad ya quedó creada aunque esto falle.
    $descBody = ['plain_text' => mb_substr($descripcion, 0, 49000)];
    $rDesc = meli_http('PUT', MELI_API . '/items/' . $itemId . '/description', [
        'headers' => ['Authorization: Bearer ' . $token],
        'json' => $descBody,
    ]);
    if (!$rDesc['ok']) {
        meli_http('POST', MELI_API . '/items/' . $itemId . '/description', [
            'headers' => ['Authorization: Bearer ' . $token],
            'json' => $descBody,
        ]);
    }

    return ['ok' => true, 'mensaje' => 'Publicada en Mercado Libre.', 'item_id' => $itemId];
}

/**
 * Pausa o reactiva una publicación ya existente en Mercado Libre
 * (usado cuando se suspende/reactiva la propiedad en el panel).
 */
function meli_cambiar_estado(string $itemId, string $status): array
{
    $token = meli_access_token_valido();
    if (!$token) {
        return ['ok' => false, 'mensaje' => 'Mercado Libre no está conectado.'];
    }
    $r = meli_http('PUT', MELI_API . '/items/' . $itemId, [
        'headers' => ['Authorization: Bearer ' . $token],
        'json' => ['status' => $status],
    ]);
    if (!$r['ok']) {
        $detalle = is_array($r['body']) ? json_encode($r['body'], JSON_UNESCAPED_UNICODE) : ($r['error'] ?? 'error desconocido');
        return ['ok' => false, 'mensaje' => 'Mercado Libre no aceptó el cambio de estado (HTTP ' . $r['status'] . '): ' . mb_substr($detalle, 0, 1500)];
    }
    return ['ok' => true, 'mensaje' => 'Estado actualizado en Mercado Libre.'];
}

/**
 * Traduce el error técnico guardado en meli_error a una frase entendible
 * para alguien sin conocimientos técnicos (quien use el panel del día a día).
 * Se calcula al mostrarlo, no al guardarlo, así se puede mejorar la redacción
 * sin tener que volver a intentar la publicación.
 */
function meli_error_amigable(?string $tecnico): string
{
    if (!$tecnico) return '';
    $t = mb_strtolower($tecnico);

    if (str_contains($t, 'no está configurado')) {
        return 'Falta terminar de configurar la conexión con Mercado Libre.';
    }
    if (str_contains($t, 'no está conectado')) {
        return 'Se desconectó la cuenta de Mercado Libre — hay que volver a "Conectar Mercado Libre".';
    }
    if (str_contains($t, 'no tiene fotos')) {
        return 'Le falta al menos una foto para poder publicarse en Mercado Libre.';
    }
    if (str_contains($t, 'no se pudo subir ninguna foto')) {
        return 'Mercado Libre no aceptó las fotos. Probá sacarlas y volver a subirlas.';
    }
    if (str_contains($t, 'no hay categoría')) {
        return 'Ese tipo de propiedad todavía no está mapeado a una categoría de Mercado Libre. Avisale a Marcelo.';
    }
    if (str_contains($t, 'no hay ciudad')) {
        return 'Esa zona todavía no está mapeada a una ciudad de Mercado Libre. Avisale a Marcelo.';
    }
    if (str_contains($t, 'no interpretar el precio')) {
        return 'El precio cargado no se pudo interpretar. Revisalo (ej: "U$S 60.000" o "$ 900.000").';
    }
    if (str_contains($t, 'category_id.invalid') || str_contains($t, 'leaf category')) {
        return 'La categoría de Mercado Libre para este tipo de propiedad no es válida. Avisale a Marcelo para ajustarla.';
    }
    if (str_contains($t, 'location.invalid')) {
        return 'Mercado Libre no aceptó la ubicación de esta propiedad. Avisale a Marcelo.';
    }
    if (str_contains($t, 'missing_required') || str_contains($t, 'attribute')) {
        return 'A Mercado Libre le faltan datos obligatorios de esta propiedad (superficie, dormitorios, baños, etc.).';
    }
    if (str_contains($t, 'listing_type')) {
        return 'El tipo de publicación no está disponible en la cuenta de Mercado Libre conectada.';
    }
    if (str_contains($t, 'no hay ningún tipo de publicación disponible')) {
        return 'La cuenta de Mercado Libre no tiene ninguna forma de publicar habilitada para este tipo de propiedad ahora mismo (cupo agotado o falta un plan pago).';
    }
    return 'Mercado Libre no aceptó esta publicación. Tocá "Reintentar" o avisale a Marcelo con el detalle técnico.';
}
