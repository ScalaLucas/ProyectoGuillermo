<?php
require __DIR__ . '/auth.php';
if (!empty($_SESSION['usuario'])) seg_cerrar_sesion();
else {
    // Sesión con clave antigua compartida (sin usuario individual): igual queda registrada la salida.
    if (!empty($_SESSION['admin_ok'])) seg_log('salida_clave_antigua', '', 'propiedades');
    elseif (!empty($_SESSION['alq_ok'])) seg_log('salida_clave_antigua', '', 'alquileres');
    $_SESSION = [];
    @session_destroy();
}
header('Location: login.php');
