<?php
/*
 * Crea un administrador (o restablece su contraseña si ya existe). Solo desde la consola:
 *   C:\xampp\php\php.exe app\crear_admin.php usuario "Nombre completo"
 * Úsalo para el primer acceso o si se pierde el acceso de todos los administradores.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/nucleo.php';

[, $usuario, $nombre] = $argv + [null, null, null];
$usuario = mb_strtolower(trim((string)$usuario));
if (!preg_match('/^[a-z0-9._-]{3,50}$/', $usuario) || trim((string)$nombre) === '') {
    fwrite(STDERR, "Uso: php app/crear_admin.php usuario \"Nombre completo\"\n");
    exit(1);
}

$temp = claveTemporal();
db()->prepare("INSERT INTO usuarios (usuario, nombre, clave_hash, rol, activo, debe_cambiar_clave)
               VALUES (?, ?, ?, 'admin', 1, 1)
               ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), clave_hash = VALUES(clave_hash), rol = 'admin', activo = 1,
                 debe_cambiar_clave = 1, intentos_fallidos = 0, bloqueado_hasta = NULL, version_sesion = version_sesion + 1")
    ->execute([$usuario, trim($nombre), hashClave($temp)]);
bitacora('usuario_creado', "$usuario (Administrador, desde consola)", ['id' => null, 'usuario' => 'consola']);

echo "Administrador listo.\n  Usuario:             $usuario\n  Contraseña temporal: $temp\nSe pedirá cambiarla al entrar.\n";
