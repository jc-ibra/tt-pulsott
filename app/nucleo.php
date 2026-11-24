<?php
/*
 * Núcleo compartido: configuración, conexión, sesión segura, CSRF, roles y bitácora.
 * Toda página PHP del sistema empieza con: require __DIR__ . '/app/nucleo.php';
 */
declare(strict_types=1);

const ROLES = [
    'admin' => 'Administrador',
    'operador' => 'Operador',
    'consulta' => 'Consulta',
    'responsable' => 'Responsable de área',
];
const AREAS_RESPONSABLE = ['Compras', 'Finanzas', 'Legal', 'Logística', 'Operaciones', 'Recursos Humanos', 'Transporte', 'Cliente'];
const ETIQUETAS_AREA = ['Operaciones' => 'Operaciones Gerentes/Director'];

$CONFIG = require __DIR__ . '/../config/config.php';
$SEG = $CONFIG['seguridad'];

/* ---------- Cabeceras de seguridad ---------- */

header_remove('X-Powered-By');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
header('Cross-Origin-Opener-Policy: same-origin');

function esHttps(): bool
{
    return ($_SERVER['HTTPS'] ?? '') === 'on' || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

if ($SEG['forzar_https'] && !esHttps() && PHP_SAPI !== 'cli') {
    header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'], true, 301);
    exit;
}
if (esHttps()) {
    header('Strict-Transport-Security: max-age=31536000');
}

/* ---------- Base de datos ---------- */

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = $GLOBALS['CONFIG']['db'];
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['puerto'], $c['nombre']),
            $c['usuario'],
            $c['clave'],
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    }
    return $pdo;
}

function ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function bitacora(string $accion, ?string $detalle = null, ?array $u = null): void
{
    $u ??= $_SESSION['usuario'] ?? null;
    try {
        db()->prepare('INSERT INTO bitacora (usuario_id, usuario, accion, detalle, ip) VALUES (?, ?, ?, ?, ?)')
            ->execute([$u['id'] ?? null, $u['usuario'] ?? null, $accion, $detalle !== null ? mb_substr($detalle, 0, 500) : null, ip()]);
    } catch (Throwable $e) {
        error_log('[PULSOTT] bitácora: ' . $e->getMessage());
    }
}

/* ---------- Sesión ---------- */

function iniciarSesion(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.gc_maxlifetime', (string)($GLOBALS['SEG']['horas_sesion_maxima'] * 3600));
    session_name('PULSOTT_SESION');
    $base = rutaBase();
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => $base === '/' ? '/' : $base . '/', // la cookie solo viaja a este sistema
        'secure'   => esHttps(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

// Carpeta web del sistema, por ejemplo /PULSOTT
function rutaBase(): string
{
    $raiz = str_replace('\\', '/', realpath(__DIR__ . '/..'));
    $doc = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '');
    $rel = $doc !== '' && str_starts_with(strtolower($raiz), strtolower($doc)) ? substr($raiz, strlen($doc)) : '';
    return '/' . trim(implode('/', array_map('rawurlencode', explode('/', $rel))), '/');
}

function cerrarSesion(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 3600] + array_intersect_key($p, array_flip(['path', 'domain', 'secure', 'httponly', 'samesite'])));
        session_destroy();
    }
}

/**
 * Devuelve el usuario con sesión válida o null.
 * Se revisa contra la base en cada petición: si lo desactivan, le cambian el rol o la contraseña, la sesión deja de servir.
 * $actividad=false para peticiones automáticas (el refresco cada 20 s) que no deben contar como "uso".
 */
function usuarioActual(bool $actividad = true): ?array
{
    iniciarSesion();
    $s = $_SESSION['usuario'] ?? null;
    if (!$s) {
        return null;
    }
    $ahora = time();
    $SEG = $GLOBALS['SEG'];
    if ($ahora - ($_SESSION['inicio'] ?? 0) > $SEG['horas_sesion_maxima'] * 3600
        || $ahora - ($_SESSION['ultimo_uso'] ?? 0) > $SEG['minutos_inactividad'] * 60) {
        bitacora('sesion_expirada', null, $s);
        cerrarSesion();
        return null;
    }
    $q = db()->prepare('SELECT id, usuario, nombre, rol, area, activo, debe_cambiar_clave, version_sesion FROM usuarios WHERE id = ?');
    $q->execute([$s['id']]);
    $u = $q->fetch();
    if (!$u || !$u['activo'] || (int)$u['version_sesion'] !== (int)$s['version_sesion']) {
        cerrarSesion();
        return null;
    }
    if ($actividad) {
        $_SESSION['ultimo_uso'] = $ahora;
    }
    $u['id'] = (int)$u['id'];
    $u['debe_cambiar_clave'] = (bool)$u['debe_cambiar_clave'];
    $_SESSION['usuario'] = ['id' => $u['id'], 'usuario' => $u['usuario'], 'version_sesion' => (int)$u['version_sesion']];
    return $u;
}

function entrar(array $u): void
{
    iniciarSesion();
    session_regenerate_id(true); // evita fijación de sesión
    $_SESSION = [
        'usuario'    => ['id' => (int)$u['id'], 'usuario' => $u['usuario'], 'version_sesion' => (int)$u['version_sesion']],
        'inicio'     => time(),
        'ultimo_uso' => time(),
        'csrf'       => bin2hex(random_bytes(32)),
    ];
}

/** Para páginas: si no hay sesión, manda al login. */
function requiereLogin(array $roles = [], bool $permitirCambioPendiente = false): array
{
    $u = usuarioActual();
    if (!$u) {
        redirigir('login.php');
    }
    if ($u['debe_cambiar_clave'] && !$permitirCambioPendiente) {
        redirigir('cambiar_clave.php');
    }
    if ($roles && !in_array($u['rol'], $roles, true)) {
        http_response_code(403);
        bitacora('acceso_denegado', $_SERVER['REQUEST_URI'] ?? '');
        pagina('Sin permiso', '<div class="aviso error">No tienes permiso para ver esta página.</div><p><a href="./">Volver al sistema</a></p>', $u);
        exit;
    }
    return $u;
}

function puedeEditar(array $u): bool
{
    return in_array($u['rol'], ['admin', 'operador'], true);
}

/* ---------- CSRF ---------- */

function csrfToken(): string
{
    iniciarSesion();
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function csrfCampo(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrfToken()) . '">';
}

function csrfValido(?string $token): bool
{
    iniciarSesion();
    return is_string($token) && isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

/** Para formularios: rechaza un POST sin el token correcto. */
function verificarPost(): void
{
    if (!csrfValido($_POST['csrf'] ?? null)) {
        http_response_code(400);
        bitacora('csrf_invalido', $_SERVER['REQUEST_URI'] ?? '');
        pagina('Solicitud no válida', '<div class="aviso error">La página caducó. Vuelve a intentarlo.</div><p><a href="' . e(basename($_SERVER['SCRIPT_NAME'])) . '">Regresar</a></p>');
        exit;
    }
}

/* ---------- Contraseñas ---------- */

/** Devuelve el problema de la contraseña, o null si cumple. */
function problemaClave(string $clave, string $usuario): ?string
{
    $min = $GLOBALS['SEG']['largo_minimo_clave'];
    return match (true) {
        mb_strlen($clave) < $min                         => "Usa al menos $min caracteres.",
        mb_strlen($clave) > 128                          => 'Usa máximo 128 caracteres.',
        !preg_match('/\p{L}/u', $clave)                  => 'Incluye al menos una letra.',
        !preg_match('/\d/', $clave)                      => 'Incluye al menos un número.',
        stripos($clave, $usuario) !== false              => 'No uses tu nombre de usuario dentro de la contraseña.',
        default                                          => null,
    };
}

function hashClave(string $clave): string
{
    return password_hash($clave, PASSWORD_DEFAULT);
}

/** Contraseña temporal fácil de dictar (sin 0/O ni 1/l/I). */
function claveTemporal(): string
{
    $letras = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ';
    $nums = '23456789';
    $c = '';
    for ($i = 0; $i < 10; $i++) {
        $c .= $letras[random_int(0, strlen($letras) - 1)];
    }
    for ($i = 0; $i < 4; $i++) {
        $c .= $nums[random_int(0, strlen($nums) - 1)];
    }
    return substr($c, 0, 5) . '-' . substr($c, 5, 5) . '-' . substr($c, 10);
}

/* ---------- Salida HTML ---------- */

function e(mixed $t): string
{
    return htmlspecialchars((string)$t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirigir(string $a): never
{
    header('Location: ' . $a, true, 303);
    exit;
}

function pagina(string $titulo, string $cuerpo, ?array $u = null, bool $ancha = false): void
{
    header("Content-Security-Policy: default-src 'self'; style-src 'self' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data:; script-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'; object-src 'none'");
    $menu = '';
    if ($u && !$u['debe_cambiar_clave']) {
        $menu = '<nav class="menu"><a href="./">Proyectos</a>'
            . ($u['rol'] === 'admin' ? '<a href="usuarios.php">Usuarios</a><a href="bitacora.php">Bitácora</a>' : '')
            . '<a href="cambiar_clave.php">Mi contraseña</a>'
            . '<form method="post" action="logout.php">' . csrfCampo() . '<button>Cerrar sesión</button></form></nav>';
    }
    $quien = $u ? '<span class="quien">' . e($u['nombre']) . ' · ' . e(ROLES[$u['rol']]) . '</span>' : '';
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . e($titulo) . ' · PULSOTT</title>'
        . '<link rel="icon" href="assets/icono.svg" type="image/svg+xml">'
        . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@75..100,400..800&display=swap">'
        . '<link rel="stylesheet" href="assets/sistema.css"></head><body>'
        . '<header class="barra"><a class="marca" href="./"><img src="assets/icono.svg" alt="" width="28" height="28">PULSOTT · Pulso y Seguimiento Operativo Trantor Technologies</a>' . $quien . $menu . '</header>'
        . '<main class="' . ($ancha ? 'ancha' : 'angosta') . '"><h1>' . e($titulo) . '</h1>' . $cuerpo . '</main></body></html>';
}
