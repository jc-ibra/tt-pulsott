<?php
require __DIR__ . '/app/nucleo.php';

// Solo por POST con token: así ninguna otra página puede cerrarte la sesión con un enlace
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirigir('./');
}
verificarPost();
if ($u = usuarioActual()) {
    bitacora('logout', null, $u);
}
cerrarSesion();
redirigir('login.php?salio=1');
