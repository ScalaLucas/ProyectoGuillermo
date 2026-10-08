<?php
require __DIR__ . '/lib.php';
admin_requerir_login();
require_once __DIR__ . '/../api/meli.php';

$todos = array_reverse(propiedades_cargar());
$msg = $_GET['msg'] ?? '';
$estado = $_GET['estado'] ?? 'todas';

function admin_propiedad_activa(array $p): bool { return ($p['activa'] ?? true) !== false; }

$totalActivas = count(array_filter($todos, 'admin_propiedad_activa'));
$totalSuspendidas = count($todos) - $totalActivas;

$props = $todos;
if ($estado === 'activa') $props = array_values(array_filter($todos, 'admin_propiedad_activa'));
elseif ($estado === 'suspendida') $props = array_values(array_filter($todos, fn($p) => !admin_propiedad_activa($p)));

$op = $_GET['op'] ?? 'todas';
$totalVenta = count(array_filter($props, fn($p) => ($p['operacion'] ?? '') === 'Venta'));
$totalAlquiler = count(array_filter($props, fn($p) => ($p['operacion'] ?? '') === 'Alquiler'));
$totalEnLista = count($props);
if ($op === 'venta') $props = array_values(array_filter($props, fn($p) => ($p['operacion'] ?? '') === 'Venta'));
elseif ($op === 'alquiler') $props = array_values(array_filter($props, fn($p) => ($p['operacion'] ?? '') === 'Alquiler'));
else $op = 'todas';

// Orden alfabético por dirección (con números "naturales": Belgrano 645 antes que Belgrano 1640) y activas separadas de suspendidas.
$claveDir = fn($p) => strtr(mb_strtolower((string)($p['direccion'] ?? '')), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
$ordenarDir = function (array $g) use ($claveDir) { usort($g, fn($a, $b) => strnatcmp($claveDir($a), $claveDir($b))); return $g; };
$gActivas = $ordenarDir(array_values(array_filter($props, 'admin_propiedad_activa')));
$gSuspendidas = $ordenarDir(array_values(array_filter($props, fn($p) => !admin_propiedad_activa($p))));

$totalPublicadasML = count(array_filter($todos, fn($p) => !empty($p['meli_item_id'])));
$totalSinFotos = count(array_filter($todos, fn($p) => empty($p['fotos'])));

admin_cabecera('Panel', 'index.php');
?>
<div class="bar">
  <div>
    <h1>Propiedades</h1>
    <div class="muted"><?= count($todos) ?> cargadas · <?= count(array_filter($todos, fn($p) => !empty($p['fotos']) && admin_propiedad_activa($p))) ?> publicadas en la web</div>
  </div>
  <div class="actions">
    <?php if (seg_es_admin() || !seg_usuario_actual()): ?>
    <a class="btn btn--ghost" href="meli-connect.php"><?= meli_conectado() ? '✓ Mercado Libre conectado' : 'Conectar Mercado Libre' ?></a>
    <?php elseif (meli_conectado()): ?>
    <span class="badge badge--activa" style="align-self:center">✓ Mercado Libre conectado</span>
    <?php endif; ?>
    <a class="btn" href="property-form.php">+ Nueva propiedad</a>
  </div>
</div>

<div class="grid g4" style="margin-bottom:20px">
  <div class="kpi"><span>Propiedades cargadas</span><b><?= count($todos) ?></b></div>
  <div class="kpi ok"><span>Activas</span><b><?= $totalActivas ?></b></div>
  <div class="kpi <?= $totalSuspendidas ? 'warn' : '' ?>"><span>Suspendidas</span><b><?= $totalSuspendidas ?></b></div>
  <div class="kpi ok"><span>Publicadas en Mercado Libre</span><b><?= $totalPublicadasML ?></b></div>
</div>
<?php if ($totalSinFotos): ?><div class="msg msg--warn"><?= $totalSinFotos ?> propiedad<?= $totalSinFotos === 1 ? '' : 'es' ?> sin fotos todavía — no se puede<?= $totalSinFotos === 1 ? '' : 'n' ?> publicar en Mercado Libre hasta que tenga<?= $totalSinFotos === 1 ? '' : 'n' ?> al menos una.</div><?php endif; ?>

  <?php if ($msg === 'ok'): ?><div class="msg msg--ok">Propiedad guardada.</div><?php endif; ?>
  <?php if ($msg === 'deleted'): ?><div class="msg msg--ok">Propiedad eliminada.</div><?php endif; ?>
  <?php if ($msg === 'meli_pend'): ?><div class="msg msg--warn">Se guardó en la web. Mercado Libre todavía no está conectado, así que no se publicó ahí.</div><?php endif; ?>
  <?php if ($msg === 'meli_err'): ?><div class="msg msg--err">Se guardó en la web, pero falló la publicación en Mercado Libre. Revisá el detalle en la fila de la propiedad.</div><?php endif; ?>
  <?php if ($msg === 'estado_ok'): ?><div class="msg msg--ok">Estado actualizado.</div><?php endif; ?>
  <?php if ($msg === 'estado_meli_err'): ?><div class="msg msg--warn">Se actualizó el estado en la web, pero no se pudo actualizar en Mercado Libre. Revisalo manualmente ahí.</div><?php endif; ?>
  <?php if ($msg === 'estado_save_err'): ?><div class="msg msg--err">No se pudo guardar el cambio de estado: el servidor no pudo escribir en el archivo de propiedades. Probá de nuevo; si persiste, puede ser un problema de permisos en el hosting.</div><?php endif; ?>
  <?php if ($msg === 'save_err'): ?><div class="msg msg--err">No se pudo guardar la propiedad: el servidor no pudo escribir en el archivo de propiedades. Probá de nuevo; si persiste, puede ser un problema de permisos en el hosting.</div><?php endif; ?>
  <?php if ($msg === 'delete_save_err'): ?><div class="msg msg--err">No se pudo eliminar la propiedad: el servidor no pudo escribir en el archivo de propiedades. Probá de nuevo; si persiste, puede ser un problema de permisos en el hosting.</div><?php endif; ?>
  <?php if ($msg === 'solo_admin'): ?><div class="msg msg--err">Esa acción es solo para administradores.</div><?php endif; ?>
  <?php if ($msg === 'meli_desvinculada'): ?><div class="msg msg--warn">Esa publicación ya no está en Mercado Libre (la borraron o se cerró ahí), así que se desvinculó acá. Si querés volver a publicarla, usá "Reintentar".</div><?php endif; ?>
  <?php if ($msg === 'meli_verificada'): ?><div class="msg msg--ok">Sigue publicada en Mercado Libre.</div><?php endif; ?>

  <div class="card">
    <div class="admin-tabs">
      <a class="admin-tab <?= $estado === 'todas' ? 'active' : '' ?>" href="?estado=todas&op=<?= $op ?>">Todas <span><?= count($todos) ?></span></a>
      <a class="admin-tab <?= $estado === 'activa' ? 'active' : '' ?>" href="?estado=activa&op=<?= $op ?>">Activas <span><?= $totalActivas ?></span></a>
      <a class="admin-tab <?= $estado === 'suspendida' ? 'active' : '' ?>" href="?estado=suspendida&op=<?= $op ?>">Suspendidas <span><?= $totalSuspendidas ?></span></a>
    </div>
    <div class="admin-tabs">
      <a class="admin-tab <?= $op === 'todas' ? 'active' : '' ?>" href="?estado=<?= admin_html($estado) ?>&op=todas">Venta y alquiler <span><?= $totalEnLista ?></span></a>
      <a class="admin-tab <?= $op === 'venta' ? 'active' : '' ?>" href="?estado=<?= admin_html($estado) ?>&op=venta">En venta <span><?= $totalVenta ?></span></a>
      <a class="admin-tab <?= $op === 'alquiler' ? 'active' : '' ?>" href="?estado=<?= admin_html($estado) ?>&op=alquiler">En alquiler <span><?= $totalAlquiler ?></span></a>
    </div>
    <div class="admin-search">
      <input type="search" id="prop-search" placeholder="Buscar por código o dirección…" autocomplete="off">
    </div>
    <div class="table-wrap">
    <table id="prop-table">
      <thead><tr><th>ID</th><th><button type="button" id="sort-dir" class="sort-btn" title="Ordenar por dirección (A-Z / Z-A)" style="all:unset;cursor:pointer;font-weight:inherit">Dirección <span class="arr">▼</span></button></th><th>Tipo</th><th>Operación</th><th>Precio</th><th>Fotos</th><th>Estado</th><th>Agente</th><th>Propietario</th><th>Mercado Libre</th><th></th></tr></thead>
      <?php foreach ([['Activas', $gActivas, 'activa'], ['Suspendidas', $gSuspendidas, 'suspendida']] as [$tituloGrupo, $grupo, $claveGrupo]): if (!$grupo) continue; ?>
      <tbody class="prop-group" data-grupo="<?= $claveGrupo ?>">
        <tr class="group-row"><td colspan="11" style="background:#f3efe8;font-weight:700;letter-spacing:.04em;text-transform:uppercase;font-size:12px;padding:10px 14px"><?= $tituloGrupo ?> (<?= count($grupo) ?>)</td></tr>
        <?php foreach ($grupo as $p): $interno = $p['interno'] ?? []; ?>
        <tr data-search="<?= admin_html(mb_strtolower(($p['id'] ?? '') . ' ' . ($p['direccion'] ?? '') . ' ' . ($interno['agente'] ?? '') . ' ' . ($interno['propietario_nombre'] ?? ''))) ?>">
          <td><?= admin_html($p['id'] ?? '') ?></td>
          <td><?= admin_html($p['direccion'] ?? '') ?></td>
          <td><?= admin_html($p['tipo'] ?? '') ?></td>
          <td><span class="badge <?= ($p['operacion'] ?? '') === 'Alquiler' ? 'badge--alquiler' : 'badge--venta' ?>"><?= admin_html($p['operacion'] ?? '') ?></span></td>
          <td><?= admin_html($p['precio'] ?? '') ?></td>
          <td><?= count($p['fotos'] ?? []) ?></td>
          <td>
            <?php $activa = admin_propiedad_activa($p); ?>
            <span class="badge <?= $activa ? 'badge--activa' : 'badge--suspendida' ?>"><?= $activa ? 'Activa' : 'Suspendida' ?></span>
          </td>
          <td><?= admin_html($interno['agente'] ?? '') ?: '<span class="muted">—</span>' ?></td>
          <td style="min-width:170px">
            <?php if (!empty($interno['propietario_nombre'])): ?>
              <?= admin_html($interno['propietario_nombre']) ?>
              <?php if (!empty($interno['propietario_telefono']) || !empty($interno['propietario_email'])): ?>
              <div class="hint">
                <?= admin_html($interno['propietario_telefono'] ?? '') ?>
                <?= !empty($interno['propietario_telefono']) && !empty($interno['propietario_email']) ? ' · ' : '' ?>
                <?= admin_html($interno['propietario_email'] ?? '') ?>
              </div>
              <?php endif; ?>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if (!empty($p['meli_item_id'])): ?>
              <span class="meli-status meli-status--ok">Publicada</span>
              <form method="post" action="meli-verificar.php" style="display:inline">
                <input type="hidden" name="csrf" value="<?= admin_html(admin_csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= admin_html($p['id'] ?? '') ?>">
                <button class="meli-verificar" type="submit" aria-label="Verificar en Mercado Libre" title="Consulta en Mercado Libre si esta publicación sigue existiendo">↻</button>
              </form>
            <?php elseif (!empty($p['meli_error'])): ?>
              <span class="meli-status meli-status--err" title="<?= admin_html($p['meli_error']) ?>">Error</span>
              <div class="hint" style="max-width:220px"><?= admin_html(meli_error_amigable($p['meli_error'])) ?></div>
            <?php else: ?>
              <span class="meli-status meli-status--pend">Sin publicar</span>
            <?php endif; ?>
          </td>
          <td class="actions">
            <a class="btn btn--ghost" href="property-form.php?id=<?= urlencode($p['id'] ?? '') ?>">Editar</a>
            <?php if (!empty($p['fotos'])): ?>
            <a class="btn btn--ghost" href="../reporte.html?id=<?= urlencode($p['id'] ?? '') ?>" target="_blank">Reporte</a>
            <?php endif; ?>
            <form method="post" action="estado.php" style="display:inline">
              <input type="hidden" name="csrf" value="<?= admin_html(admin_csrf_token()) ?>">
              <input type="hidden" name="id" value="<?= admin_html($p['id'] ?? '') ?>">
              <button class="btn btn--ghost" type="submit"><?= $activa ? 'Suspender' : 'Reactivar' ?></button>
            </form>
            <?php if (empty($p['meli_item_id']) && !empty($p['fotos'])): ?>
            <form method="post" action="meli-retry.php" style="display:inline">
              <input type="hidden" name="csrf" value="<?= admin_html(admin_csrf_token()) ?>">
              <input type="hidden" name="id" value="<?= admin_html($p['id'] ?? '') ?>">
              <button class="btn btn--ghost" type="submit">Reintentar</button>
            </form>
            <?php endif; ?>
            <?php if (seg_es_admin() || !seg_usuario_actual()): ?>
            <form method="post" action="delete.php" onsubmit="return confirm('¿Eliminar esta propiedad de la web? Esta acción no se puede deshacer.');" style="display:inline">
              <input type="hidden" name="csrf" value="<?= admin_html(admin_csrf_token()) ?>">
              <input type="hidden" name="id" value="<?= admin_html($p['id'] ?? '') ?>">
              <button class="btn btn--danger" type="submit">Eliminar</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <?php endforeach; ?>
      <tbody>
        <?php if (!$props): ?><tr><td colspan="11" class="muted"><?= $estado === 'suspendida' ? 'No hay propiedades suspendidas.' : ($estado === 'activa' ? 'No hay propiedades activas.' : 'Todavía no hay propiedades cargadas.') ?></td></tr><?php endif; ?>
        <tr id="prop-search-empty" hidden><td colspan="11" class="muted">Ninguna propiedad coincide con la búsqueda.</td></tr>
      </tbody>
    </table>
    </div>
  </div>
<script>
(function () {
  var input = document.getElementById('prop-search');
  var empty = document.getElementById('prop-search-empty');
  var grupos = Array.prototype.slice.call(document.querySelectorAll('#prop-table tbody.prop-group'));
  function filtrar() {
    var q = input ? input.value.trim().toLowerCase() : '';
    var visibles = 0;
    grupos.forEach(function (g) {
      var vg = 0;
      Array.prototype.forEach.call(g.querySelectorAll('tr[data-search]'), function (row) {
        var match = !q || row.getAttribute('data-search').indexOf(q) !== -1;
        row.hidden = !match;
        if (match) vg++;
      });
      g.querySelector('.group-row').hidden = vg === 0;
      visibles += vg;
    });
    if (empty) empty.hidden = !q || visibles > 0;
  }
  if (input) input.addEventListener('input', filtrar);

  var asc = true, btn = document.getElementById('sort-dir');
  if (btn) btn.addEventListener('click', function () {
    asc = !asc;
    grupos.forEach(function (g) {
      var filas = Array.prototype.slice.call(g.querySelectorAll('tr[data-search]'));
      filas.sort(function (a, b) {
        return (asc ? 1 : -1) * a.cells[1].textContent.trim().localeCompare(b.cells[1].textContent.trim(), 'es', { numeric: true, sensitivity: 'base' });
      });
      filas.forEach(function (f) { g.appendChild(f); });
    });
    btn.querySelector('.arr').textContent = asc ? '▼' : '▲';
  });
})();
</script>
<?php admin_pie(); ?>
