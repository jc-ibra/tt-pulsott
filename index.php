<?php
/*
 * Entrada al sistema: solo entrega la pantalla a quien inició sesión.
 * El archivo .html está bloqueado para el navegador; únicamente se sirve desde aquí.
 */
require __DIR__ . '/app/nucleo.php';

requiereLogin();

$html = file_get_contents(__DIR__ . '/PULSOTT.html');

// Los proyectos viven en la base de datos. Se quitan los que traía el archivo para que
// no se vuelvan a subir (por ejemplo, uno que un administrador ya borró).
$html = preg_replace('#(<script id="proyecto-data" type="application/json">)[\s\S]*?(</script>)#', '$1null$2', $html, 1);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com data:; img-src 'self' data: blob:; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'; object-src 'none'");
echo $html;
