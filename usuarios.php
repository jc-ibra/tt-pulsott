<?php
require __DIR__ . '/app/nucleo.php';

$yo = requiereLogin(['admin']);
$db = db();

function adminsActivos(PDO $db, int $sinContar = 0): int
{
    $q = $db->prepare("SELECT COUNT(*) FROM usuarios WHERE rol = 'admin' AND activo = 1 AND id <> ?");
    $q->execute([$sinContar]);
    return (int)$q->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificarPost();
    $accion = (string)($_POST['accion'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $msg = null;
    $err = null;

    $q = $db->prepare('SELECT * FROM usuarios WHERE id = ?');
    $q->execute([$id]);
    $obj = $q->fetch() ?: null;
    $esYo = $obj && (int)$obj['id'] === $yo['id'];

    if ($accion === 'crear') {
        $usuario = mb_strtolower(trim((string)($_POST['usuario'] ?? '')));
        $nombre = trim((string)($_POST['nombre'] ?? ''));
        $rol = (string)($_POST['rol'] ?? '');
        $area = trim((string)($_POST['area'] ?? ''));
        $q = $db->prepare('SELECT COUNT(*) FROM usuarios WHERE usuario = ?');
        $q->execute([$usuario]);
        if (!preg_match('/^[a-z0-9._-]{3,50}$/', $usuario)) {
            $err = 'El usuario debe tener de 3 a 50 caracteres: letras minúsculas, números, punto, guion o guion bajo.';
        } elseif (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 120) {
            $err = 'Escribe el nombre completo (máximo 120 caracteres).';
        } elseif (!isset(ROLES[$rol])) {
            $err = 'Elige un rol válido.';
        } elseif ($rol === 'responsable' && !in_array($area, AREAS_RESPONSABLE, true)) {
            $err = 'Elige el área que tendrá asignada esta persona.';
        } elseif ($q->fetchColumn()) {
            $err = "Ya existe el usuario «{$usuario}».";
        } else {
            $temp = claveTemporal();
            $db->prepare('INSERT INTO usuarios (usuario, nombre, clave_hash, rol, area, debe_cambiar_clave) VALUES (?, ?, ?, ?, ?, 1)')
               ->execute([$usuario, $nombre, hashClave($temp), $rol, $rol === 'responsable' ? $area : null]);
            bitacora('usuario_creado', "$usuario (" . ROLES[$rol] . ')');
            $msg = ['Usuario creado', $usuario, $temp];
        }
    } elseif (!$obj) {
        $err = 'No encontré ese usuario.';
    } elseif ($accion === 'rol') {
        $rol = (string)($_POST['rol'] ?? '');
        $area = trim((string)($_POST['area'] ?? ''));
        if (!isset(ROLES[$rol])) {
            $err = 'Elige un rol válido.';
        } elseif ($rol === 'responsable' && !in_array($area, AREAS_RESPONSABLE, true)) {
            $err = 'Elige el área que tendrá asignada esta persona.';
        } elseif ($esYo) {
            $err = 'No puedes cambiar tu propio rol. Pide a otro administrador que lo haga.';
        } elseif ($rol !== $obj['rol'] || ($rol === 'responsable' && $area !== $obj['area'])) {
            // Subir version_sesion obliga a esa persona a entrar de nuevo con su nuevo rol
            $areaAsignada = $rol === 'responsable' ? $area : null;
            $db->prepare('UPDATE usuarios SET rol = ?, area = ?, version_sesion = version_sesion + 1 WHERE id = ?')->execute([$rol, $areaAsignada, $id]);
            bitacora('usuario_rol', "{$obj['usuario']}: " . ROLES[$obj['rol']] . ' → ' . ROLES[$rol] . ($areaAsignada ? " ({$areaAsignada})" : ''));
            $msg = "Rol de {$obj['usuario']} cambiado a " . ROLES[$rol] . ($areaAsignada ? " de {$areaAsignada}." : '.');
        }
    } elseif ($accion === 'activo') {
        $activar = ($_POST['valor'] ?? '') === '1';
        if ($esYo) {
            $err = 'No puedes desactivar tu propia cuenta.';
        } elseif (!$activar && $obj['rol'] === 'admin' && adminsActivos($db, $id) === 0) {
            $err = 'Debe quedar al menos un administrador activo.';
        } else {
            $db->prepare('UPDATE usuarios SET activo = ?, version_sesion = version_sesion + 1 WHERE id = ?')->execute([$activar ? 1 : 0, $id]);
            bitacora($activar ? 'usuario_activado' : 'usuario_desactivado', $obj['usuario']);
            $msg = "{$obj['usuario']} " . ($activar ? 'activado.' : 'desactivado: ya no puede entrar.');
        }
    } elseif ($accion === 'restablecer') {
        if ($esYo) {
            $err = 'Para tu propia contraseña usa «Mi contraseña».';
        } else {
            $temp = claveTemporal();
            $db->prepare('UPDATE usuarios SET clave_hash = ?, debe_cambiar_clave = 1, intentos_fallidos = 0, bloqueado_hasta = NULL,
                          version_sesion = version_sesion + 1 WHERE id = ?')
               ->execute([hashClave($temp), $id]);
            bitacora('clave_restablecida', $obj['usuario']);
            $msg = ['Contraseña restablecida', $obj['usuario'], $temp];
        }
    } elseif ($accion === 'desbloquear') {
        $db->prepare('UPDATE usuarios SET intentos_fallidos = 0, bloqueado_hasta = NULL WHERE id = ?')->execute([$id]);
        bitacora('usuario_desbloqueado', $obj['usuario']);
        $msg = "{$obj['usuario']} desbloqueado.";
    } else {
        $err = 'Acción no válida.';
    }

    // Patrón POST → redirección → GET: recargar la página no repite la acción
    $_SESSION['aviso'] = ['msg' => $msg, 'err' => $err];
    redirigir('usuarios.php');
}

$aviso = $_SESSION['aviso'] ?? null;
unset($_SESSION['aviso']);

$html = '';
if ($aviso['err'] ?? null) {
    $html .= '<div class="aviso error" role="alert">' . e($aviso['err']) . '</div>';
}
if (is_array($aviso['msg'] ?? null)) {
    [$titulo, $usr, $temp] = $aviso['msg'];
    $html .= '<div class="aviso ok clave"><b>' . e($titulo) . '.</b> Entrega estos datos a la persona por un medio seguro (en persona o por teléfono).
        Esta contraseña <b>solo se muestra una vez</b> y deberá cambiarla al entrar.
        <dl><dt>Usuario</dt><dd>' . e($usr) . '</dd><dt>Contraseña temporal</dt><dd><code>' . e($temp) . '</code></dd></dl></div>';
} elseif ($aviso['msg'] ?? null) {
    $html .= '<div class="aviso ok">' . e($aviso['msg']) . '</div>';
}

$opciones = fn(string $sel) => implode('', array_map(
    fn($k, $v) => '<option value="' . $k . '"' . ($k === $sel ? ' selected' : '') . '>' . e($v) . '</option>',
    array_keys(ROLES), ROLES));
$opcionesArea = fn(?string $sel) => '<option value="">Sin área asignada</option>' . implode('', array_map(
    fn($area) => '<option value="' . e($area) . '"' . ($area === $sel ? ' selected' : '') . '>' . e(ETIQUETAS_AREA[$area] ?? $area) . '</option>',
    AREAS_RESPONSABLE));

$html .= '<details class="tarjeta" ' . ($aviso ? '' : 'open') . '><summary>Agregar usuario</summary>
    <form method="post" class="fila">' . csrfCampo() . '<input type="hidden" name="accion" value="crear">
      <label>Usuario<input name="usuario" required pattern="[a-z0-9._\-]{3,50}" maxlength="50" autocapitalize="none" spellcheck="false" placeholder="p. ej. jperez"></label>
      <label>Nombre completo<input name="nombre" required maxlength="120"></label>
      <label>Rol<select name="rol">' . $opciones('consulta') . '</select></label>
      <label>Área (para Responsable de área)<select name="area">' . $opcionesArea(null) . '</select></label>
      <button class="primario">Crear</button>
    </form>
    <p class="nota"><b>Administrador</b>: administra el sistema. <b>Operador</b>: captura y modifica proyectos. <b>Consulta</b>: solo ve y descarga. <b>Responsable de área</b>: ve el sistema y registra seguimientos solo de su área.</p>
  </details>';

$filas = $db->query('SELECT *, bloqueado_hasta > NOW() AS bloqueado FROM usuarios ORDER BY activo DESC, nombre')->fetchAll();
$html .= '<div class="tabla"><table><thead><tr><th>Nombre</th><th>Usuario</th><th>Rol</th><th>Área</th><th>Estado</th><th>Último acceso</th><th>Acciones</th></tr></thead><tbody>';
foreach ($filas as $f) {
    $esYo = (int)$f['id'] === $yo['id'];
    $form = fn(string $accion, string $boton, string $extra = '', string $clase = '') =>
        '<form method="post">' . csrfCampo() . '<input type="hidden" name="accion" value="' . $accion . '"><input type="hidden" name="id" value="' . (int)$f['id'] . '">' . $extra . '<button class="' . $clase . '">' . $boton . '</button></form>';
    $estado = !$f['activo'] ? '<span class="et gris">Inactivo</span>'
        : ($f['bloqueado'] ? '<span class="et rojo">Bloqueado</span>'
        : ($f['debe_cambiar_clave'] ? '<span class="et amarillo">Clave pendiente</span>' : '<span class="et verde">Activo</span>'));
    $acciones = $esYo ? '<span class="nota">Tu cuenta</span>'
        : $form('rol', 'Cambiar rol', '<select name="rol" aria-label="Rol">' . $opciones($f['rol']) . '</select><select name="area" aria-label="Área asignada">' . $opcionesArea($f['area']) . '</select>')
        . $form('restablecer', 'Restablecer contraseña')
        . ($f['bloqueado'] ? $form('desbloquear', 'Desbloquear') : '')
        . $form('activo', $f['activo'] ? 'Desactivar' : 'Activar', '<input type="hidden" name="valor" value="' . ($f['activo'] ? '0' : '1') . '">', $f['activo'] ? 'peligro' : '');
    $html .= '<tr' . ($f['activo'] ? '' : ' class="inactivo"') . '><td>' . e($f['nombre']) . '</td><td>' . e($f['usuario']) . '</td><td>' . e(ROLES[$f['rol']])
        . '</td><td>' . e(ETIQUETAS_AREA[$f['area'] ?? ''] ?? ($f['area'] ?? '—')) . '</td><td>' . $estado . '</td><td>' . e($f['ultimo_acceso'] ? date('d/m/Y H:i', strtotime($f['ultimo_acceso'])) : '—')
        . '</td><td class="acciones">' . $acciones . '</td></tr>';
}
$html .= '</tbody></table></div>';

pagina('Usuarios', $html, $yo, true);
