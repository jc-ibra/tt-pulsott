<?php
// Diagrama general de PULSOTT. Pide iniciar sesión; el contenido vive en app/ (bloqueada para el navegador).
require __DIR__ . '/app/nucleo.php';

requiereLogin();

header('Content-Type: text/html; charset=utf-8');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data:; script-src 'none'; frame-ancestors 'none'; base-uri 'self'; object-src 'none'");
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body>
<?php readfile(__DIR__ . '/app/mapa_pulsott.html'); ?>
<p style="text-align:center;margin:0 0 32px;font-family:system-ui,sans-serif"><a href="./">← Volver a PULSOTT</a></p>
</body>
</html>
