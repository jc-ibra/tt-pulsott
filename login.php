<?php
require __DIR__ . '/app/nucleo.php';

if (usuarioActual()) {
    redirigir('./');
}

$error = '';
$usuario = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificarPost();
    $usuario = mb_strtolower(trim((string)($_POST['usuario'] ?? '')));
    $clave = (string)($_POST['clave'] ?? '');
    $db = db();

    $q = $db->prepare("SELECT COUNT(*) FROM bitacora WHERE ip = ? AND accion = 'login_fallido' AND fecha > NOW() - INTERVAL 15 MINUTE");
    $q->execute([ip()]);

    if ((int)$q->fetchColumn() >= $SEG['intentos_por_ip']) {
        $error = 'Demasiados intentos fallidos desde este equipo. Espera 15 minutos.';
        bitacora('login_ip_bloqueada', $usuario);
    } elseif ($usuario === '' || $clave === '') {
        $error = 'Escribe tu usuario y tu contraseña.';
    } else {
        $q = $db->prepare('SELECT *, bloqueado_hasta > NOW() AS bloqueado, TIMESTAMPDIFF(MINUTE, NOW(), bloqueado_hasta) + 1 AS minutos
                           FROM usuarios WHERE usuario = ?');
        $q->execute([$usuario]);
        $u = $q->fetch();

        if ($u && $u['bloqueado']) {
            $error = "Cuenta bloqueada por intentos fallidos. Intenta en {$u['minutos']} min o pide al administrador que la desbloquee.";
            bitacora('login_cuenta_bloqueada', null, $u);
        } elseif (!$u || !password_verify($clave, $u['clave_hash']) || !$u['activo']) {
            if (!$u) {
                password_verify($clave, '$2y$12$Fonop8XW/NvWW.EpHBzJ8.v0LLOixus4KJaoIFGQDnAxz4xLu3oY2'); // mismo tiempo de respuesta exista o no el usuario
            } elseif ($u['activo']) {
                $n = (int)$u['intentos_fallidos'] + 1;
                if ($n >= $SEG['intentos_por_usuario']) {
                    $db->prepare('UPDATE usuarios SET intentos_fallidos = 0, bloqueado_hasta = NOW() + INTERVAL ? MINUTE WHERE id = ?')
                       ->execute([$SEG['minutos_bloqueo'], $u['id']]);
                    bitacora('cuenta_bloqueada', "$n intentos fallidos", $u);
                } else {
                    $db->prepare('UPDATE usuarios SET intentos_fallidos = ? WHERE id = ?')->execute([$n, $u['id']]);
                }
            }
            $error = 'Usuario o contraseña incorrectos.';
            bitacora('login_fallido', $u ? null : "usuario: $usuario", $u ?: null);
        } else {
            if (password_needs_rehash($u['clave_hash'], PASSWORD_DEFAULT)) {
                $db->prepare('UPDATE usuarios SET clave_hash = ? WHERE id = ?')->execute([hashClave($clave), $u['id']]);
            }
            $db->prepare('UPDATE usuarios SET intentos_fallidos = 0, bloqueado_hasta = NULL, ultimo_acceso = NOW() WHERE id = ?')
               ->execute([$u['id']]);
            entrar($u);
            bitacora('login', null, $u);
            redirigir($u['debe_cambiar_clave'] ? 'cambiar_clave.php' : './');
        }
    }
}

$aviso = match (true) {
    isset($_GET['expirada']) => '<div class="aviso">Tu sesión terminó. Vuelve a entrar.</div>',
    isset($_GET['salio'])    => '<div class="aviso ok">Cerraste sesión correctamente.</div>',
    isset($_GET['clave'])    => '<div class="aviso ok">Contraseña actualizada. Entra con la nueva.</div>',
    default                  => '',
};

pagina('Iniciar sesión', $aviso
    . ($error ? '<div class="aviso error" role="alert">' . e($error) . '</div>' : '')
    . '<form method="post" class="tarjeta" autocomplete="on">' . csrfCampo() . '
        <label>Usuario<input name="usuario" value="' . e($usuario) . '" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus maxlength="50"></label>
        <label>Contraseña<input type="password" name="clave" autocomplete="current-password" required maxlength="128"></label>
        <button class="primario">Entrar</button>
      </form>
      <p class="nota">¿Olvidaste tu contraseña? Pide al administrador del sistema que la restablezca.</p>');
