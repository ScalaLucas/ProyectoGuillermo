<?php
require __DIR__ . '/lib.php';
alq_requerir();
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && alq_csrf_ok()) {
    $actual = (string)($_POST['actual'] ?? '');
    $nueva = (string)($_POST['nueva'] ?? '');
    if (!password_verify($actual, alq_hash_clave())) $msg = 'err:La clave actual no es correcta.';
    elseif (strlen($nueva) < 8) $msg = 'err:La clave nueva debe tener al menos 8 caracteres.';
    elseif ($nueva !== (string)($_POST['nueva2'] ?? '')) $msg = 'err:Las dos claves nuevas no coinciden.';
    else {
        alq_asegurar_dir();
        file_put_contents(ALQ_DATA . '/clave.json', json_encode(['hash' => password_hash($nueva, PASSWORD_DEFAULT)]), LOCK_EX);
        $msg = 'ok:Clave cambiada correctamente.';
    }
}
alq_cabecera('Cambiar clave', 'clave.php');
?>
<h1>Cambiar clave</h1><p class="sub">Elegí una clave de al menos 8 caracteres.</p>
<?php if ($msg): [$t, $m] = explode(':', $msg, 2); ?><div class="msg <?= $t ?>"><?= h($m) ?></div><?php endif; ?>
<form class="card" method="post" style="max-width:420px">
<input type="hidden" name="csrf" value="<?= h(alq_csrf()) ?>">
<div class="f"><label>Clave actual</label><input type="password" name="actual" required></div>
<div class="f"><label>Clave nueva</label><input type="password" name="nueva" required minlength="8"></div>
<div class="f"><label>Repetir clave nueva</label><input type="password" name="nueva2" required minlength="8"></div>
<button class="btn" type="submit">Guardar clave</button></form>
<?php alq_pie();
