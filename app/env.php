<?php
/*
 * Lee las credenciales del archivo .env (raíz del proyecto).
 * El .env NO se sube al repositorio (.gitignore) y está bloqueado para el navegador (.htaccess).
 * Formato: una variable por línea, NOMBRE=valor. Las líneas que empiezan con # son comentarios.
 */
declare(strict_types=1);

function cargarEnv(string $archivo): void
{
    if (!is_file($archivo) || !is_readable($archivo)) {
        http_response_code(500);
        error_log("PULSOTT: no se encontró el archivo de credenciales $archivo");
        exit('Falta el archivo .env con las credenciales del sistema. Copia .env.example como .env y llena los valores.');
    }
    foreach (file($archivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linea) {
        $linea = trim($linea);
        if ($linea === '' || $linea[0] === '#' || !str_contains($linea, '=')) {
            continue;
        }
        [$nombre, $valor] = array_map('trim', explode('=', $linea, 2));
        if (preg_match('/^export\s+/', $nombre)) {
            $nombre = trim(substr($nombre, 7));
        }
        $n = strlen($valor);
        if ($n >= 2 && ($valor[0] === '"' || $valor[0] === "'") && $valor[$n - 1] === $valor[0]) {
            $valor = substr($valor, 1, -1);
        } elseif (($p = strpos($valor, ' #')) !== false) {
            $valor = rtrim(substr($valor, 0, $p)); // comentario al final de la línea
        }
        // Una variable real del servidor (p. ej. definida en Apache) tiene prioridad sobre el .env
        if (getenv($nombre) === false && !isset($_ENV[$nombre])) {
            $_ENV[$nombre] = $valor;
        }
    }
}

function env(string $nombre, ?string $porDefecto = null): ?string
{
    $v = getenv($nombre);
    if ($v !== false) {
        return $v;
    }
    return $_ENV[$nombre] ?? $porDefecto;
}

function envBool(string $nombre, bool $porDefecto): bool
{
    $v = env($nombre);
    return $v === null || $v === '' ? $porDefecto : in_array(strtolower($v), ['1', 'true', 'si', 'sí', 'yes', 'on'], true);
}
