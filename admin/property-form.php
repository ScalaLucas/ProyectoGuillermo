<?php
require __DIR__ . '/lib.php';
admin_requerir_login();

$id = trim((string)($_GET['id'] ?? ''));
$p = $id !== '' ? propiedades_encontrar($id) : null;
$esNueva = $p === null;
if (!$esNueva) { /* editando */ } else { $p = []; }

$zonas = ['Ramos Mejía', 'Villa Luzuriaga', 'Caseros', 'San Martín', 'Francisco Álvarez', 'Ituzaingó', 'Haedo', 'Villa Sarmiento'];
$tipos = ['Departamento', 'Casa', 'Chalet', 'PH', 'Dúplex', 'Tríplex', 'Terreno', 'Lote', 'Local', 'Salón', 'Oficina', 'Depósito', 'Cochera'];

// Para precargar el formulario al editar: busca una etiqueta dentro de
// caracteristicas (en cualquier grupo) y devuelve solo el número que tenga.
function carac_valor_texto(array $p, string $etiqueta): string
{
    foreach ((array)($p['caracteristicas'] ?? []) as $items) {
        if (is_array($items) && array_key_exists($etiqueta, $items)) return (string)$items[$etiqueta];
    }
    return '';
}
function carac_valor_num(array $p, string $etiqueta): string
{
    $v = preg_replace('/[^\d]/', '', carac_valor_texto($p, $etiqueta));
    return $v === '' ? '' : $v;
}
function carac_valor_decimal(array $p, string $etiqueta): string
{
    $v = preg_replace('/[^\d.]/', '', carac_valor_texto($p, $etiqueta));
    return $v === '' ? '' : $v;
}
function carac_marcado(array $p, string $etiqueta): bool
{
    return mb_strtolower(trim(carac_valor_texto($p, $etiqueta))) === 'sí' || mb_strtolower(trim(carac_valor_texto($p, $etiqueta))) === 'si';
}
// Características personalizadas que el administrador escribió a mano
// (guardadas aparte del catálogo fijo, para poder precargarlas al editar).
function carac_personalizadas(array $p): array
{
    $grupo = $p['caracteristicas']['Otras características'] ?? [];
    $out = [];
    foreach ($grupo as $etiqueta => $valor) {
        $v = mb_strtolower(trim((string)$valor));
        if ($v === 'sí' || $v === 'si') $out[] = $etiqueta;
    }
    return $out;
}
$customLabels = carac_personalizadas($p);

// Miniatura para la grilla de videos: solo YouTube tiene una URL de
// thumbnail directa y gratuita; para el resto (Vimeo, tours 360, etc.)
// se muestra un ícono genérico en vez de la miniatura.
function admin_video_thumb(string $url): ?string
{
    if (preg_match('/(?:youtube\.com\/(?:watch\?v=|shorts\/|embed\/)|youtu\.be\/)([A-Za-z0-9_-]{6,})/', $url, $m)) {
        return 'https://img.youtube.com/vi/' . $m[1] . '/hqdefault.jpg';
    }
    return null;
}
?>
<?php admin_cabecera($esNueva ? 'Nueva propiedad' : 'Editar propiedad'); ?>
  <div class="bar">
    <h1><?= $esNueva ? 'Nueva propiedad' : 'Editar propiedad · ' . admin_html($p['id']) ?></h1>
    <a class="btn btn--ghost" href="index.php">← Volver al listado</a>
  </div>

  <?php if (($_GET['err'] ?? '') === 'faltan_datos'): ?>
    <div class="msg msg--err">No se guardó: faltan datos obligatorios (operación, tipo, zona, dirección, precio, m² y, para viviendas, ambientes). Revisá los campos y volvé a guardar.</div>
  <?php endif; ?>
  <?php if (($_GET['err'] ?? '') === 'demasiadas_fotos'): ?>
    <div class="msg msg--err">No se guardó: una propiedad admite hasta 50 fotos en total (las que ya tenía más las nuevas). Sacá alguna e intentá de nuevo.</div>
  <?php endif; ?>

  <form class="card" method="post" action="save.php" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= admin_html(admin_csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= admin_html($p['id'] ?? '') ?>">

    <div class="row3">
      <div class="field"><label>Operación</label>
        <select name="operacion" required>
          <option value="Venta" <?= ($p['operacion'] ?? '') === 'Venta' ? 'selected' : '' ?>>Venta</option>
          <option value="Alquiler" <?= ($p['operacion'] ?? '') === 'Alquiler' ? 'selected' : '' ?>>Alquiler</option>
        </select>
      </div>
      <div class="field"><label>Tipo</label>
        <select name="tipo" required>
          <?php foreach ($tipos as $t): ?>
            <option value="<?= admin_html($t) ?>" <?= ($p['tipo'] ?? '') === $t ? 'selected' : '' ?>><?= admin_html($t) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Zona</label>
        <input list="zonas" name="zona" value="<?= admin_html($p['zona'] ?? '') ?>" required>
        <datalist id="zonas"><?php foreach ($zonas as $z): ?><option value="<?= admin_html($z) ?>"><?php endforeach; ?></datalist>
      </div>
    </div>

    <div class="row2">
      <div class="field"><label>Dirección</label><input type="text" name="direccion" value="<?= admin_html($p['direccion'] ?? '') ?>" required></div>
      <div class="field"><label>Título (opcional, para la tarjeta y el título de la página)</label><input type="text" name="titulo" value="<?= admin_html($p['titulo'] ?? '') ?>"></div>
    </div>

    <div class="row3">
      <div class="field"><label>Precio (texto libre, ej: U$S 60.000)</label><input type="text" name="precio" value="<?= admin_html($p['precio'] ?? '') ?>" required></div>
      <div class="field"><label>m² totales</label><input type="number" name="m2" min="0" value="<?= admin_html((string)($p['m2'] ?? '')) ?>" required></div>
      <div class="field"><label>Ambientes</label><input type="number" name="amb" min="0" value="<?= admin_html((string)($p['amb'] ?? '')) ?>" required></div>
    </div>
    <div class="row3">
      <div class="field"><label>Dormitorios</label><input type="number" name="dorm" min="0" value="<?= admin_html((string)($p['dorm'] ?? 0)) ?>"></div>
      <div class="field"><label>Baños</label><input type="number" name="banos" min="0" value="<?= admin_html((string)($p['banos'] ?? 0)) ?>"></div>
      <div class="field"><label>&nbsp;</label>
        <label style="flex-direction:row;align-items:center;gap:8px;font-weight:400">
          <input type="checkbox" name="destacado" value="1" <?= !empty($p['destacado']) ? 'checked' : '' ?> style="width:auto"> Mostrar en destacadas (home)
        </label>
      </div>
    </div>

    <h3 style="margin:28px 0 4px">Más datos de la propiedad</h3>
    <div class="hint" style="margin-bottom:12px">Esto se muestra en la ficha de la web y se manda también a Mercado Libre.</div>

    <div class="row3">
      <div class="field"><label>Superficie cubierta (m²)</label><input type="number" name="carac_sup_cubierta" min="0" value="<?= admin_html(carac_valor_num($p, 'Superficie cubierta')) ?>"></div>
      <div class="field"><label>Cocheras</label><input type="number" name="carac_cocheras" min="0" value="<?= admin_html(carac_valor_num($p, 'Cocheras')) ?>"></div>
      <div class="field"><label>Bauleras</label><input type="number" name="carac_bauleras" min="0" value="<?= admin_html(carac_valor_num($p, 'Bauleras')) ?>"></div>
    </div>
    <div class="row3">
      <div class="field"><label>Antigüedad (años)</label><input type="number" name="carac_antiguedad" min="0" value="<?= admin_html(carac_valor_num($p, 'Antigüedad')) ?>"></div>
      <div class="field"><label>Expensas (ARS)</label><input type="number" name="carac_expensas" min="0" value="<?= admin_html(carac_valor_num($p, 'Expensas')) ?>"></div>
      <div class="field"><label>Cantidad de plantas</label><input type="number" name="carac_pisos" min="0" value="<?= admin_html(carac_valor_num($p, 'Cantidad de pisos')) ?>"></div>
    </div>
    <div class="row3">
      <div class="field"><label>Disposición</label>
        <select name="carac_disposicion">
          <option value="">— Sin especificar —</option>
          <?php foreach (['Frente', 'Contrafrente', 'Lateral', 'Interno'] as $opt): ?>
            <option <?= carac_valor_texto($p, 'Disposición') === $opt ? 'selected' : '' ?>><?= $opt ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Orientación</label>
        <select name="carac_orientacion">
          <option value="">— Sin especificar —</option>
          <?php foreach (['Norte', 'Sur', 'Este', 'Oeste', 'Noreste', 'Noroeste', 'Sudeste', 'Sudoeste'] as $opt): ?>
            <option <?= carac_valor_texto($p, 'Orientación') === $opt ? 'selected' : '' ?>><?= $opt ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Tipo de seguridad</label>
        <select name="carac_tipo_seguridad">
          <option value="">— Sin especificar —</option>
          <?php foreach (['Virtual', 'Física'] as $opt): ?>
            <option <?= carac_valor_texto($p, 'Tipo de seguridad') === $opt ? 'selected' : '' ?>><?= $opt ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="field"><label>Otras características (escribí lo que quieras y tildá para agregarlo)</label>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:16px 18px;margin-top:4px">
        <?php for ($i = 0; $i < 6; $i++): $lbl = $customLabels[$i] ?? ''; ?>
        <label style="flex-direction:row;align-items:center;gap:8px;font-weight:400">
          <input type="checkbox" name="carac_custom_check[<?= $i ?>]" value="1" <?= $lbl !== '' ? 'checked' : '' ?> style="width:auto">
          <input type="text" name="carac_custom_label[<?= $i ?>]" value="<?= admin_html($lbl) ?>" placeholder="Ej: Cochera cubierta" maxlength="80" style="flex:1;padding:8px 10px;border:1px solid #d8d4c8;border-radius:6px;font-size:13px;font-family:inherit">
        </label>
        <?php endfor; ?>
      </div>
      <div class="hint" style="margin-top:10px">Escribí el texto y tildá la casilla para que se agregue como característica de la propiedad (se muestra en la ficha de la web y se manda a Mercado Libre si corresponde).</div>
    </div>

    <?php foreach (CARAC_GRUPOS_BOOL as $grupo => $etiquetas): ?>
    <div class="field"><label><?= admin_html($grupo) ?></label>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:8px 16px">
        <?php foreach ($etiquetas as $etiqueta): ?>
        <label style="flex-direction:row;align-items:center;gap:8px;font-weight:400">
          <input type="checkbox" name="carac_check[<?= admin_html($grupo) ?>][<?= admin_html($etiqueta) ?>]" value="1" <?= carac_marcado($p, $etiqueta) ? 'checked' : '' ?> style="width:auto">
          <?= admin_html($etiqueta) ?>
        </label>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>

    <div class="field"><label>Descripción (separá párrafos con una línea en blanco)</label>
      <textarea name="descripcion" id="campo-descripcion" rows="6"><?= admin_html($p['descripcion'] ?? '') ?></textarea>
      <button type="button" class="btn btn--ghost" id="ia-abrir" style="margin-top:8px;align-self:flex-start">✨ Generar descripción con IA</button>
    </div>

    <div class="field"><label>Multimedia</label>
      <div class="media-tabs">
        <button type="button" class="media-tab active" data-tab="fotos">Fotos</button>
        <button type="button" class="media-tab" data-tab="videos">Videos / 360</button>
      </div>

      <div id="media-tab-fotos">
        <div class="hint" style="margin:12px 0 14px">📷 Después de subir imágenes podés hacer click en una para establecerla como portada, o arrastrarlas para reordenarlas. Máximo 50 fotos por propiedad.</div>
        <div class="media-grid" id="media-grid">
          <?php foreach (($p['fotos'] ?? []) as $foto): ?>
          <div class="media-tile" draggable="true">
            <img src="../<?= admin_html($foto) ?>" alt="">
            <button type="button" class="media-tile__remove" aria-label="Quitar foto">✕</button>
            <input type="checkbox" name="mantener_fotos[]" value="<?= admin_html($foto) ?>" checked hidden>
          </div>
          <?php endforeach; ?>
          <div class="media-tile media-tile--add" id="media-add-tile" title="Agregar fotos">
            <span>+</span>
          </div>
        </div>
        <input type="file" name="fotos_nuevas[]" id="fotos-input" accept="image/jpeg,image/png,image/webp" multiple hidden>
      </div>

      <div id="media-tab-videos" style="display:none">
        <div class="hint" style="margin:12px 0 14px">🎥 Subí un video propio, o pegá un link de YouTube, Vimeo o un tour 360 (Matterport, Kuula, etc.).</div>
        <div class="media-grid" id="video-grid">
          <?php foreach (($p['videos'] ?? []) as $video): $esLocal = !preg_match('/^https?:\/\//i', $video); ?>
          <div class="media-tile media-tile--video">
            <?php if ($esLocal): ?>
              <video src="../<?= admin_html($video) ?>" muted preload="metadata" playsinline></video>
              <input type="checkbox" name="mantener_videos[]" value="<?= admin_html($video) ?>" checked hidden>
            <?php else: ?>
              <?php if ($thumb = admin_video_thumb($video)): ?>
                <img src="<?= admin_html($thumb) ?>" alt="">
              <?php else: ?>
                <div class="media-tile__icon">🎬</div>
              <?php endif; ?>
              <input type="hidden" name="videos_urls[]" value="<?= admin_html($video) ?>">
            <?php endif; ?>
            <span class="media-tile__play">▶</span>
            <button type="button" class="media-tile__remove" aria-label="Quitar video">✕</button>
          </div>
          <?php endforeach; ?>
          <div class="media-tile media-tile--add" id="video-add-tile" title="Subir un video">
            <span>+</span>
          </div>
        </div>
        <input type="file" name="videos_nuevos[]" id="video-file-input" accept="video/mp4,video/webm,video/quicktime,video/x-m4v" multiple hidden>
        <div class="video-add-row">
          <input type="url" id="video-url-input" placeholder="O pegá un link: https://www.youtube.com/watch?v=...">
          <button type="button" class="btn btn--ghost" id="video-add-btn">Agregar link</button>
        </div>
        <div class="hint">Videos propios hasta 200 MB (mp4, webm o mov).</div>
      </div>
    </div>

    <h3 style="margin:32px 0 8px">Información privada de la operación</h3>
    <div class="hint" style="margin-bottom:20px">No se muestra en la web pública: solo se manda a Mercado Libre como información privada.</div>
    <div class="row2" style="margin-bottom:28px">
      <div class="field"><label>Requerimientos transacción</label>
        <input type="text" name="carac_requisitos_transaccion" value="<?= admin_html(carac_valor_texto($p, 'Requerimientos transacción')) ?>" placeholder="Condiciones necesarias para completar la operación">
      </div>
      <div class="field"><label>Comisión operación (%)</label>
        <input type="number" name="carac_comision_operacion" min="0" max="100" step="0.01" value="<?= admin_html(carac_valor_decimal($p, 'Comisión operación')) ?>" placeholder="Ej: 4">
      </div>
    </div>

    <h3 style="margin:32px 0 8px">Información interna del equipo</h3>
    <div class="hint" style="margin-bottom:20px">Solo la ve el equipo en este panel. Nunca se publica en la web ni se envía a Mercado Libre.</div>
    <div class="row2" style="margin-bottom:14px">
      <div class="field"><label>Agente que cargó la propiedad</label>
        <input list="agentes" name="interno_agente" value="<?= admin_html($p['interno']['agente'] ?? '') ?>" placeholder="Ej: Marcelo">
        <datalist id="agentes"><?php foreach (['Marcelo', 'Lucas', 'Viviana', 'Joaquín', 'Juana'] as $a): ?><option value="<?= admin_html($a) ?>"><?php endforeach; ?></datalist>
      </div>
      <div class="field"><label>Nombre completo del propietario</label>
        <input type="text" name="interno_propietario_nombre" value="<?= admin_html($p['interno']['propietario_nombre'] ?? '') ?>" placeholder="Ej: Juan Pérez">
      </div>
    </div>
    <div class="row2" style="margin-bottom:28px">
      <div class="field"><label>Email del propietario</label>
        <input type="email" name="interno_propietario_email" value="<?= admin_html($p['interno']['propietario_email'] ?? '') ?>" placeholder="propietario@ejemplo.com">
      </div>
      <div class="field"><label>Teléfono del propietario</label>
        <input type="tel" name="interno_propietario_telefono" value="<?= admin_html($p['interno']['propietario_telefono'] ?? '') ?>" placeholder="+54 9 11 xxxx xxxx">
      </div>
    </div>


    <button class="btn" type="submit" style="margin-top:8px">Guardar<?= $esNueva ? ' y publicar' : ' cambios' ?></button>
  </form>

<div class="modal-backdrop" id="ia-modal">
  <div class="modal">
    <button type="button" class="modal__close" id="ia-modal-close" aria-label="Cerrar">✕</button>

    <div id="ia-step-1">
      <h2>Generar descripción con IA</h2>
      <div class="hint">Basada en la información completada en la ficha</div>

      <div class="field"><label>Longitud del texto</label>
        <div class="opt-group" id="ia-longitud">
          <button type="button" class="opt" data-val="corto">Corto (500 caracteres)</button>
          <button type="button" class="opt active" data-val="mediano">Mediano (1000 caracteres)</button>
          <button type="button" class="opt" data-val="largo">Largo (1500 caracteres)</button>
        </div>
      </div>
      <div class="field"><label>Estilo del texto</label>
        <div class="opt-group" id="ia-estilo">
          <button type="button" class="opt" data-val="formal">Formal / Profesional</button>
          <button type="button" class="opt active" data-val="neutro">Neutro</button>
          <button type="button" class="opt" data-val="amigable">Amigable / Juvenil</button>
        </div>
      </div>
      <div class="field"><label>Instrucciones de descripción (opcional)</label>
        <textarea id="ia-instrucciones" rows="3" placeholder="Lineamientos para la construcción de la descripción (ej. Destacá la seguridad y los espacios verdes con los que cuenta)"></textarea>
      </div>

      <div class="msg msg--err" id="ia-error" style="display:none"></div>

      <div class="modal__actions">
        <button type="button" class="btn btn--ghost" id="ia-cancelar">Cancelar</button>
        <button type="button" class="btn" id="ia-siguiente">Siguiente</button>
      </div>
    </div>

    <div id="ia-step-2" style="display:none">
      <h2>Generar descripción con IA</h2>
      <div class="hint">Basada en la información completada en la ficha</div>
      <div class="field"><label>Nueva descripción</label>
        <textarea class="ia-preview" id="ia-resultado"></textarea>
      </div>
      <div class="modal__actions">
        <button type="button" class="btn btn--ghost" id="ia-atras">Atrás</button>
        <button type="button" class="btn" id="ia-usar">Usar descripción generada</button>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  var modal = document.getElementById('ia-modal');
  var step1 = document.getElementById('ia-step-1');
  var step2 = document.getElementById('ia-step-2');
  var errorBox = document.getElementById('ia-error');
  var btnSiguiente = document.getElementById('ia-siguiente');
  var longitud = 'mediano';
  var estilo = 'neutro';

  function marcarActivo(grupoId, val) {
    var grupo = document.getElementById(grupoId);
    Array.prototype.forEach.call(grupo.querySelectorAll('.opt'), function (btn) {
      btn.classList.toggle('active', btn.dataset.val === val);
    });
  }
  document.getElementById('ia-longitud').addEventListener('click', function (e) {
    if (!e.target.classList.contains('opt')) return;
    longitud = e.target.dataset.val;
    marcarActivo('ia-longitud', longitud);
  });
  document.getElementById('ia-estilo').addEventListener('click', function (e) {
    if (!e.target.classList.contains('opt')) return;
    estilo = e.target.dataset.val;
    marcarActivo('ia-estilo', estilo);
  });

  function abrirModal() {
    errorBox.style.display = 'none';
    step1.style.display = '';
    step2.style.display = 'none';
    modal.classList.add('open');
  }
  function cerrarModal() { modal.classList.remove('open'); }

  document.getElementById('ia-abrir').addEventListener('click', abrirModal);
  document.getElementById('ia-modal-close').addEventListener('click', cerrarModal);
  document.getElementById('ia-cancelar').addEventListener('click', cerrarModal);
  document.getElementById('ia-atras').addEventListener('click', function () {
    step2.style.display = 'none';
    step1.style.display = '';
  });
  modal.addEventListener('click', function (e) { if (e.target === modal) cerrarModal(); });

  function recolectarCaracteristicas() {
    var lista = [];
    Array.prototype.forEach.call(document.querySelectorAll('input[name^="carac_check"]:checked'), function (cb) {
      var m = cb.name.match(/\]\[([^\]]+)\]$/);
      if (m) lista.push(m[1]);
    });
    for (var i = 0; i < 6; i++) {
      var cb = document.querySelector('input[name="carac_custom_check[' + i + ']"]');
      var txt = document.querySelector('input[name="carac_custom_label[' + i + ']"]');
      if (cb && cb.checked && txt && txt.value.trim() !== '') lista.push(txt.value.trim());
    }
    return lista;
  }

  btnSiguiente.addEventListener('click', function () {
    errorBox.style.display = 'none';
    var payload = {
      csrf: document.querySelector('input[name="csrf"]').value,
      operacion: document.querySelector('[name="operacion"]').value,
      tipo: document.querySelector('[name="tipo"]').value,
      zona: document.querySelector('[name="zona"]').value,
      direccion: document.querySelector('[name="direccion"]').value,
      precio: document.querySelector('[name="precio"]').value,
      m2: document.querySelector('[name="m2"]').value,
      amb: document.querySelector('[name="amb"]').value,
      dorm: document.querySelector('[name="dorm"]').value,
      banos: document.querySelector('[name="banos"]').value,
      caracteristicas: recolectarCaracteristicas(),
      longitud: longitud,
      estilo: estilo,
      instrucciones: document.getElementById('ia-instrucciones').value
    };
    btnSiguiente.disabled = true;
    btnSiguiente.textContent = 'Generando… (puede tardar hasta 1 minuto)';
    fetch('genera-descripcion.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    }).then(function (r) {
      return r.json().then(function (data) { return { ok: r.ok, data: data }; });
    }).then(function (res) {
      btnSiguiente.disabled = false;
      btnSiguiente.textContent = 'Siguiente';
      if (!res.ok || !res.data.descripcion) {
        var mensajes = {
          faltan_datos: 'Completá al menos Tipo, Zona o alguna instrucción antes de generar.',
          csrf_invalido: 'La página quedó desactualizada. Recargá el formulario y probá de nuevo.',
          ia_no_disponible: 'El generador de IA está saturado en este momento. Probá de nuevo en unos segundos.'
        };
        errorBox.textContent = mensajes[res.data && res.data.error] || 'No se pudo generar la descripción. Probá de nuevo en un momento.';
        errorBox.style.display = '';
        return;
      }
      document.getElementById('ia-resultado').value = res.data.descripcion;
      step1.style.display = 'none';
      step2.style.display = '';
    }).catch(function () {
      btnSiguiente.disabled = false;
      btnSiguiente.textContent = 'Siguiente';
      errorBox.textContent = 'No se pudo conectar con el generador. Revisá tu conexión.';
      errorBox.style.display = '';
    });
  });

  document.getElementById('ia-usar').addEventListener('click', function () {
    document.getElementById('campo-descripcion').value = document.getElementById('ia-resultado').value;
    cerrarModal();
  });
})();

(function () {
  var tabs = document.querySelectorAll('.media-tab');
  var panelFotos = document.getElementById('media-tab-fotos');
  var panelVideos = document.getElementById('media-tab-videos');
  tabs.forEach(function (tab) {
    tab.addEventListener('click', function () {
      tabs.forEach(function (t) { t.classList.remove('active'); });
      tab.classList.add('active');
      var esFotos = tab.dataset.tab === 'fotos';
      panelFotos.style.display = esFotos ? '' : 'none';
      panelVideos.style.display = esFotos ? 'none' : '';
    });
  });

  var grid = document.getElementById('media-grid');
  var addTile = document.getElementById('media-add-tile');
  var fotosInput = document.getElementById('fotos-input');
  var archivosNuevos = []; // File[] de las fotos recién elegidas (aún no guardadas)
  var tileArchivo = new Map(); // tile del DOM -> File que representa

  function actualizarInputArchivos() {
    var dt = new DataTransfer();
    archivosNuevos.forEach(function (f) { dt.items.add(f); });
    fotosInput.files = dt.files;
  }

  function actualizarBadgePortada() {
    var tiles = grid.querySelectorAll('.media-tile:not(.media-tile--add)');
    tiles.forEach(function (t, i) {
      var badge = t.querySelector('.media-tile__badge');
      if (i === 0) {
        if (!badge) {
          badge = document.createElement('span');
          badge.className = 'media-tile__badge';
          badge.textContent = 'Portada';
          t.appendChild(badge);
        }
      } else if (badge) {
        badge.remove();
      }
    });
  }

  function crearTileNueva(file) {
    var tile = document.createElement('div');
    tile.className = 'media-tile';
    tile.draggable = true;
    var img = document.createElement('img');
    img.src = URL.createObjectURL(file);
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'media-tile__remove';
    btn.setAttribute('aria-label', 'Quitar foto');
    btn.textContent = '✕';
    tile.appendChild(img);
    tile.appendChild(btn);
    tileArchivo.set(tile, file);
    return tile;
  }

  addTile.addEventListener('click', function () { fotosInput.click(); });

  fotosInput.addEventListener('change', function () {
    Array.prototype.forEach.call(fotosInput.files, function (file) {
      archivosNuevos.push(file);
      grid.insertBefore(crearTileNueva(file), addTile);
    });
    actualizarInputArchivos();
    actualizarBadgePortada();
  });

  grid.addEventListener('click', function (e) {
    var tile = e.target.closest('.media-tile');
    if (!tile || tile === addTile) return;

    if (e.target.classList.contains('media-tile__remove')) {
      if (tileArchivo.has(tile)) {
        var idx = archivosNuevos.indexOf(tileArchivo.get(tile));
        if (idx !== -1) archivosNuevos.splice(idx, 1);
        tileArchivo.delete(tile);
        actualizarInputArchivos();
      }
      tile.remove();
      actualizarBadgePortada();
      return;
    }

    // Click en la miniatura (no en la X): la manda al frente como portada.
    grid.insertBefore(tile, grid.firstChild);
    if (tileArchivo.has(tile)) {
      var f = tileArchivo.get(tile);
      archivosNuevos.splice(archivosNuevos.indexOf(f), 1);
      archivosNuevos.unshift(f);
      actualizarInputArchivos();
    }
    actualizarBadgePortada();
  });

  var arrastrando = null;
  grid.addEventListener('dragstart', function (e) {
    var tile = e.target.closest('.media-tile');
    if (!tile || tile === addTile) return;
    arrastrando = tile;
    tile.classList.add('dragging');
  });
  grid.addEventListener('dragend', function () {
    if (arrastrando) arrastrando.classList.remove('dragging');
    arrastrando = null;
    if (tileArchivo.size) {
      // Reordenamos el array de archivos nuevos según el orden visual final.
      var tiles = grid.querySelectorAll('.media-tile:not(.media-tile--add)');
      var nuevoOrden = [];
      tiles.forEach(function (t) {
        if (tileArchivo.has(t)) nuevoOrden.push(tileArchivo.get(t));
      });
      var existentes = archivosNuevos.filter(function (f) { return nuevoOrden.indexOf(f) === -1; });
      archivosNuevos = nuevoOrden.concat(existentes);
      actualizarInputArchivos();
    }
    actualizarBadgePortada();
  });
  grid.addEventListener('dragover', function (e) {
    e.preventDefault();
    var tile = e.target.closest('.media-tile');
    if (!tile || tile === arrastrando) return;
    var rect = tile.getBoundingClientRect();
    var antes = (e.clientX - rect.left) < rect.width / 2;
    if (tile === addTile) { grid.insertBefore(arrastrando, addTile); return; }
    grid.insertBefore(arrastrando, antes ? tile : tile.nextSibling);
  });

  actualizarBadgePortada();

  // ---------- Videos ----------
  var videoGrid = document.getElementById('video-grid');
  var videoAddTile = document.getElementById('video-add-tile');
  var videoFileInput = document.getElementById('video-file-input');
  var videoUrlInput = document.getElementById('video-url-input');
  var videoAddBtn = document.getElementById('video-add-btn');
  var archivosVideoNuevos = []; // File[] de los videos recién elegidos (aún no guardados)
  var tileArchivoVideo = new Map(); // tile del DOM -> File que representa

  function escAttr(s) {
    return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }
  function videoThumbHtml(url) {
    var yt = url.match(/(?:youtube\.com\/(?:watch\?v=|shorts\/|embed\/)|youtu\.be\/)([A-Za-z0-9_-]{6,})/);
    if (yt) return '<img src="https://img.youtube.com/vi/' + yt[1] + '/hqdefault.jpg" alt="">';
    return '<div class="media-tile__icon">🎬</div>';
  }
  function actualizarInputVideos() {
    var dt = new DataTransfer();
    archivosVideoNuevos.forEach(function (f) { dt.items.add(f); });
    videoFileInput.files = dt.files;
  }

  videoAddTile.addEventListener('click', function () { videoFileInput.click(); });

  videoFileInput.addEventListener('change', function () {
    Array.prototype.forEach.call(videoFileInput.files, function (file) {
      archivosVideoNuevos.push(file);
      var tile = document.createElement('div');
      tile.className = 'media-tile media-tile--video';
      var video = document.createElement('video');
      video.src = URL.createObjectURL(file);
      video.muted = true;
      video.preload = 'metadata';
      video.playsInline = true;
      var play = document.createElement('span');
      play.className = 'media-tile__play';
      play.textContent = '▶';
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'media-tile__remove';
      btn.setAttribute('aria-label', 'Quitar video');
      btn.textContent = '✕';
      tile.appendChild(video);
      tile.appendChild(play);
      tile.appendChild(btn);
      tileArchivoVideo.set(tile, file);
      videoGrid.insertBefore(tile, videoAddTile);
    });
    actualizarInputVideos();
  });

  function agregarVideoLink(url) {
    url = url.trim();
    if (!/^https?:\/\//i.test(url)) { videoUrlInput.focus(); return; }
    var tile = document.createElement('div');
    tile.className = 'media-tile media-tile--video';
    tile.innerHTML = videoThumbHtml(url) +
      '<span class="media-tile__play">▶</span>' +
      '<button type="button" class="media-tile__remove" aria-label="Quitar video">✕</button>' +
      '<input type="hidden" name="videos_urls[]" value="' + escAttr(url) + '">';
    videoGrid.insertBefore(tile, videoAddTile);
    videoUrlInput.value = '';
  }
  videoAddBtn.addEventListener('click', function () { agregarVideoLink(videoUrlInput.value); });
  videoUrlInput.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); agregarVideoLink(videoUrlInput.value); }
  });

  videoGrid.addEventListener('click', function (e) {
    if (!e.target.classList.contains('media-tile__remove')) return;
    var tile = e.target.closest('.media-tile');
    if (tileArchivoVideo.has(tile)) {
      var idx = archivosVideoNuevos.indexOf(tileArchivoVideo.get(tile));
      if (idx !== -1) archivosVideoNuevos.splice(idx, 1);
      tileArchivoVideo.delete(tile);
      actualizarInputVideos();
    }
    tile.remove();
  });
})();
</script>
<?php admin_pie(); ?>
