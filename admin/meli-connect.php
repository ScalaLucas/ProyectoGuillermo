<?php
require __DIR__ . '/lib.php';
admin_requerir_login();
// Conectar/reconectar la cuenta de Mercado Libre afecta a todas las
// publicaciones del sitio: solo un administrador puede hacerlo.
if (!seg_es_admin()) { header('Location: index.php?msg=solo_admin'); exit; }
require_once __DIR__ . '/../api/meli.php';

$aviso = '';
$avisoTipo = 'warn';

if (!meli_configurado()) {
    $aviso = 'Faltan MELI_CLIENT_ID / MELI_CLIENT_SECRET / MELI_REDIRECT_URI en api/config.php. Cargalos primero (ver instrucciones ahí mismo) y volvé a esta página.';
} elseif (isset($_GET['code'])) {
    $stateOk = isset($_GET['state']) && hash_equals($_SESSION['meli_state'] ?? '', (string)$_GET['state']);
    if (!$stateOk) {
        $aviso = 'La autorización no se pudo validar (state inválido). Probá conectar de nuevo.';
        $avisoTipo = 'err';
    } else {
        $r = meli_exchange_code((string)$_GET['code']);
        $aviso = $r['mensaje'];
        $avisoTipo = $r['ok'] ? 'ok' : 'err';
    }
} elseif (isset($_GET['error'])) {
    $aviso = 'Mercado Libre devolvió un error: ' . admin_html((string)$_GET['error']);
    $avisoTipo = 'err';
}

$conectado = meli_conectado();
$state = bin2hex(random_bytes(16));
$_SESSION['meli_state'] = $state;
$urlConectar = meli_configurado() ? meli_authorize_url($state) : '#';
?>
<?php admin_cabecera('Mercado Libre'); ?>
  <div class="bar">
    <h1>Conexión con Mercado Libre</h1>
    <a class="btn btn--ghost" href="index.php">← Volver al listado</a>
  </div>

  <?php if ($aviso): ?><div class="msg msg--<?= $avisoTipo ?>"><?= admin_html($aviso) ?></div><?php endif; ?>

  <div class="card">
    <?php if ($conectado): ?>
      <p><strong>✓ Cuenta de Mercado Libre conectada.</strong></p>
      <p class="hint">A partir de ahora, cada propiedad que guardes con fotos en el panel se intenta publicar (o actualizar) automáticamente en Mercado Libre.</p>
      <?php if (meli_configurado()): ?>
        <a class="btn btn--ghost" href="<?= admin_html($urlConectar) ?>">Volver a autorizar (por si cambiaste de cuenta)</a>
      <?php endif; ?>
    <?php elseif (meli_configurado()): ?>
      <p>Todavía no está conectada la cuenta de Mercado Libre de la inmobiliaria.</p>
      <a class="btn" href="<?= admin_html($urlConectar) ?>">Conectar con Mercado Libre</a>
      <p class="hint">Te va a pedir iniciar sesión con la cuenta real de Mercado Libre de la inmobiliaria y autorizar la app.</p>
    <?php else: ?>
      <p>Faltan datos de configuración. Pedile a quien administra el código que complete en <code>api/config.php</code>:</p>
      <ul>
        <li><code>MELI_CLIENT_ID</code> y <code>MELI_CLIENT_SECRET</code> (de tu app en developers.mercadolibre.com.ar)</li>
        <li><code>MELI_REDIRECT_URI</code>, que debe ser exactamente esta URL en HTTPS: <code><?php
          $esquema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
          echo admin_html($esquema . '://' . ($_SERVER['HTTP_HOST'] ?? '') . strtok($_SERVER['REQUEST_URI'], '?'));
        ?></code></li>
      </ul>
    <?php endif; ?>
  </div>
<?php admin_pie(); ?>
