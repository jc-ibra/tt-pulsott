<?php
require __DIR__ . '/app/nucleo.php';

$yo = requiereLogin(['admin']);

const ACCIONES = [
    'login' => 'Entró', 'logout' => 'Salió', 'login_fallido' => 'Intento fallido', 'cuenta_bloqueada' => 'Cuenta bloqueada',
    'login_cuenta_bloqueada' => 'Intento con cuenta bloqueada', 'login_ip_bloqueada' => 'Intento con IP bloqueada',
    'sesion_expirada' => 'Sesión expirada', 'cambio_clave' => 'Cambió su contraseña', 'cambio_clave_fallido' => 'Falló cambio de contraseña',
    'usuario_creado' => 'Creó usuario', 'usuario_rol' => 'Cambió rol', 'usuario_activado' => 'Activó usuario',
    'usuario_desactivado' => 'Desactivó usuario', 'clave_restablecida' => 'Restableció contraseña', 'usuario_desbloqueado' => 'Desbloqueó usuario',
    'proyecto_creado' => 'Creó proyecto', 'prospecto_creado' => 'Agregó prospecto', 'prospectos_importados' => 'Importó prospectos', 'prospecto_baja' => 'Dio de baja prospecto', 'prospecto_reactivado' => 'Reactivó prospecto', 'prospecto_borrado' => 'Borró prospecto', 'proyecto_borrado' => 'Borró proyecto', 'acceso_denegado' => 'Acceso denegado', 'csrf_invalido' => 'Solicitud no válida',
];
$ALERTA = ['login_fallido', 'cuenta_bloqueada', 'login_cuenta_bloqueada', 'login_ip_bloqueada', 'acceso_denegado', 'csrf_invalido', 'cambio_clave_fallido'];

$fUsuario = trim((string)($_GET['usuario'] ?? ''));
$fAccion = (string)($_GET['accion'] ?? '');
$where = [];
$args = [];
if ($fUsuario !== '') {
    $where[] = 'usuario LIKE ?';
    $args[] = '%' . addcslashes($fUsuario, '%_\\') . '%';
}
if (isset(ACCIONES[$fAccion])) {
    $where[] = 'accion = ?';
    $args[] = $fAccion;
}
$q = db()->prepare('SELECT * FROM bitacora' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC LIMIT 500');
$q->execute($args);

$html = '<form method="get" class="fila tarjeta">
    <label>Usuario<input name="usuario" value="' . e($fUsuario) . '"></label>
    <label>Acción<select name="accion"><option value="">Todas</option>'
    . implode('', array_map(fn($k, $v) => '<option value="' . $k . '"' . ($k === $fAccion ? ' selected' : '') . '>' . e($v) . '</option>', array_keys(ACCIONES), ACCIONES))
    . '</select></label><button>Filtrar</button></form>
  <p class="nota">Últimos 500 movimientos. Los guardados de cada proyecto quedan en la tabla <code>proyectos_versiones</code> con el usuario que guardó.</p>
  <div class="tabla"><table><thead><tr><th>Fecha</th><th>Usuario</th><th>Acción</th><th>Detalle</th><th>IP</th></tr></thead><tbody>';
foreach ($q as $f) {
    $html .= '<tr' . (in_array($f['accion'], $ALERTA, true) ? ' class="alerta"' : '') . '><td>' . e(date('d/m/Y H:i:s', strtotime($f['fecha'])))
        . '</td><td>' . e($f['usuario'] ?? '—') . '</td><td>' . e(ACCIONES[$f['accion']] ?? $f['accion'])
        . '</td><td>' . e($f['detalle'] ?? '') . '</td><td>' . e($f['ip']) . '</td></tr>';
}
$html .= '</tbody></table></div>';

pagina('Bitácora de seguridad', $html, $yo, true);
