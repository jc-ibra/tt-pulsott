<?php
require __DIR__ . '/app/nucleo.php';

$u = requiereLogin([], true);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificarPost();
    $actual = (string)($_POST['actual'] ?? '');
    $nueva = (string)($_POST['nueva'] ?? '');

    $q = db()->prepare('SELECT clave_hash FROM usuarios WHERE id = ?');
    $q->execute([$u['id']]);

    if (!password_verify($actual, (string)$q->fetchColumn())) {
        $error = 'La contraseña actual no es correcta.';
        bitacora('cambio_clave_fallido');
    } elseif ($nueva !== (string)($_POST['repetir'] ?? '')) {
        $error = 'Las contraseñas nuevas no coinciden.';
    } elseif ($nueva === $actual) {
        $error = 'La contraseña nueva debe ser distinta de la actual.';
    } elseif ($p = problemaClave($nueva, $u['usuario'])) {
        $error = $p;
    } else {
        // Subir version_sesion cierra las sesiones abiertas en otros equipos
        db()->prepare('UPDATE usuarios SET clave_hash = ?, debe_cambiar_clave = 0, version_sesion = version_sesion + 1 WHERE id = ?')
            ->execute([hashClave($nueva), $u['id']]);
        bitacora('cambio_clave');
        cerrarSesion();
        redirigir('login.php?clave=1');
    }
}

pagina('Cambiar contraseña',
    ($u['debe_cambiar_clave'] ? '<div class="aviso">Antes de continuar, crea una contraseña personal. Nadie más debe conocerla.</div>' : '')
    . ($error ? '<div class="aviso error" role="alert">' . e($error) . '</div>' : '')
    . '<form method="post" class="tarjeta">' . csrfCampo() . '
        <input type="hidden" name="usuario" value="' . e($u['usuario']) . '" autocomplete="username">
        <label>Contraseña actual<input type="password" name="actual" autocomplete="current-password" required maxlength="128" autofocus></label>
        <label>Contraseña nueva<input type="password" name="nueva" autocomplete="new-password" required minlength="' . (int)$SEG['largo_minimo_clave'] . '" maxlength="128"></label>
        <label>Repite la contraseña nueva<input type="password" name="repetir" autocomplete="new-password" required maxlength="128"></label>
        <p class="nota">Mínimo ' . (int)$SEG['largo_minimo_clave'] . ' caracteres, con letras y números. Al cambiarla se cierra tu sesión en los demás equipos.</p>
        <button class="primario">Guardar contraseña</button>
      </form>', $u);
