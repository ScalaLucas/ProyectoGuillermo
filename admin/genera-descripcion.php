<?php
/**
 * Genera una descripción de propiedad con IA (Gemini) a partir de los datos
 * que el administrador ya completó en la ficha, sin necesidad de guardarla
 * primero. Lo llama el botón "Generar descripción con IA" de property-form.php.
 */
require __DIR__ . '/lib.php';
admin_requerir_login();
require_once __DIR__ . '/../api/lib.php';

header('Content-Type: application/json; charset=utf-8');
error_reporting(0);
ini_set('display_errors', '0');
set_time_limit(60); // Gemini puede tardar bastante en generar el texto completo.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'bad_request']);
    exit;
}

$csrfEnviado = (string)($input['csrf'] ?? '');
if (!hash_equals($_SESSION['csrf'] ?? '', $csrfEnviado)) {
    http_response_code(403);
    echo json_encode(['error' => 'csrf_invalido']);
    exit;
}

function ia_texto($input, string $clave, int $max = 200): string
{
    return mb_substr(trim((string)($input[$clave] ?? '')), 0, $max);
}
function ia_num($input, string $clave): int
{
    return max(0, (int)($input[$clave] ?? 0));
}

$operacion = ia_texto($input, 'operacion', 20);
$tipo = ia_texto($input, 'tipo', 30);
$zona = ia_texto($input, 'zona', 60);
$direccion = ia_texto($input, 'direccion', 160);
$precio = ia_texto($input, 'precio', 40);
$m2 = ia_num($input, 'm2');
$amb = ia_num($input, 'amb');
$dorm = ia_num($input, 'dorm');
$banos = ia_num($input, 'banos');
$instrucciones = ia_texto($input, 'instrucciones', 400);

$caracteristicas = [];
if (is_array($input['caracteristicas'] ?? null)) {
    foreach ($input['caracteristicas'] as $c) {
        $c = trim(mb_substr((string)$c, 0, 60));
        if ($c !== '') $caracteristicas[] = $c;
    }
}
$caracteristicas = array_slice($caracteristicas, 0, 40);

// Solo hace falta ALGO con qué trabajar: datos estructurados de la ficha
// o instrucciones escritas a mano (el vendedor puede tipear todo ahí,
// como "alquiler departamento 2 ambientes en Ramos Mejía", sin haber
// completado todavía Zona/Dirección en el formulario).
if ($zona === '' && $direccion === '' && $tipo === '' && $instrucciones === '') {
    http_response_code(400);
    echo json_encode(['error' => 'faltan_datos']);
    exit;
}

$longitudMap = [
    'corto' => ['chars' => 500, 'tokens' => 500],
    'mediano' => ['chars' => 1000, 'tokens' => 900],
    'largo' => ['chars' => 1500, 'tokens' => 1300],
];
$estiloMap = [
    'formal' => 'formal y profesional, cuidado, dirigido a compradores o inquilinos exigentes',
    'neutro' => 'neutro, claro y cordial, el tono estándar de un aviso inmobiliario',
    'amigable' => 'amigable, cercano y algo juvenil, ideal para primera vivienda o alquiler a gente joven',
];
$longitud = $longitudMap[$input['longitud'] ?? ''] ?? $longitudMap['mediano'];
$estilo = $estiloMap[$input['estilo'] ?? ''] ?? $estiloMap['neutro'];

$datosTexto = '';
if ($operacion !== '') $datosTexto .= "Operación: {$operacion}\n";
if ($tipo !== '') $datosTexto .= "Tipo de propiedad: {$tipo}\n";
if ($zona !== '') $datosTexto .= "Zona: {$zona}\n";
if ($direccion !== '') $datosTexto .= "Dirección: {$direccion}\n";
if ($precio !== '') $datosTexto .= "Precio: {$precio}\n";
if ($m2 > 0) $datosTexto .= "Superficie total: {$m2} m²\n";
if ($amb > 0) $datosTexto .= "Ambientes: {$amb}\n";
if ($dorm > 0) $datosTexto .= "Dormitorios: {$dorm}\n";
if ($banos > 0) $datosTexto .= "Baños: {$banos}\n";
if ($caracteristicas) $datosTexto .= "Características que tiene la propiedad: " . implode(', ', $caracteristicas) . "\n";

$prompt = <<<PROMPT
Sos un redactor inmobiliario experto en Argentina. Escribí la descripción de un aviso para publicar en la web de una inmobiliaria y en Mercado Libre, a partir ÚNICAMENTE de estos datos reales:

{$datosTexto}

REGLAS:
- Español rioplatense.
- Extensión aproximada: {$longitud['chars']} caracteres (podés variar un poco, pero no te pases de largo).
- Tono: {$estilo}.
- Separá el texto en 2 a 4 párrafos breves, con una línea en blanco entre cada uno.
- Usá SOLO los datos de arriba: nunca inventes características, ubicaciones, amenities, cercanías ni cifras que no estén en la lista. Si falta un dato (por ejemplo dormitorios, baños o características), simplemente no lo menciones.
- No uses signos de exclamación en exceso ni mayúsculas sostenidas. No repitas la dirección más de una vez.
- Devolvé solo el texto de la descripción, sin título, sin comillas y sin explicaciones aparte.
PROMPT;

if ($instrucciones !== '') {
    $prompt .= "\n\nINSTRUCCIONES ADICIONALES DEL VENDEDOR (seguilas siempre que no contradigan las reglas de arriba ni inventen datos falsos):\n{$instrucciones}";
}

$requestBody = [
    'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
    'generationConfig' => [
        'temperature' => 0.7,
        'maxOutputTokens' => $longitud['tokens'],
        'thinkingConfig' => ['thinkingLevel' => 'low'],
    ],
];

// Orden por defecto: primero el modelo principal (Flash-Lite, rápido y con
// capacidad libre) y, si falla, el de respaldo. 18s por intento para quedar
// por debajo del límite de ~60s del proxy de Hostinger aun con reintentos.
$modelosIA = array_values(array_unique(array_filter([
    GEMINI_MODEL,
    defined('GEMINI_MODEL_FALLBACK') ? GEMINI_MODEL_FALLBACK : '',
    'gemini-3.5-flash-lite',
    'gemini-flash-latest',
    'gemini-3.6-flash',
    'gemini-3.7-flash',
    'gemini-3.8-flash',
])));
// Cuando Google está saturado los 503 vuelven en ~1s, así que en vez de
// rendirse tras una pasada damos varias vueltas por todos los modelos hasta
// agotar ~50s (el proxy de Hostinger corta cerca de los 60s).
$texto = null;
$inicio = time();
while ($texto === null && (time() - $inicio) < 40) {
    foreach ($modelosIA as $modeloIA) {
        if ((time() - $inicio) >= 46) break;
        [$t] = call_gemini_modelo($modeloIA, $requestBody, 9);
        if (is_string($t) && trim($t) !== '') { $texto = $t; break; }
    }
    if ($texto === null) usleep(1200000);
}
if ($texto === null || trim($texto) === '') {
    http_response_code(502);
    echo json_encode(['error' => 'ia_no_disponible']);
    exit;
}

echo json_encode(['descripcion' => trim($texto)], JSON_UNESCAPED_UNICODE);
