<?php
/*
 * API del Pulso y Seguimiento Operativo Trantor Technologies (PULSOTT).
 * Es la que espera el "modo programa" del HTML:
 *   GET    api/ping                    -> el HTML detecta que hay servidor (no requiere sesión)
 *   GET    api/sesion                  -> quién está conectado, su rol y el token CSRF
 *   GET    api/proyectos               -> lista de proyectos
 *   GET    api/proyectos/{id}          -> {version, data}
 *   PUT    api/proyectos/{id}          -> body {data, base}; 409 con {version, data} si alguien guardó antes
 *   GET/POST api/seguimientos/{id}     -> historial y nuevas actualizaciones de desviaciones
 *   DELETE api/proyectos/{id}          -> solo administradores
 *   GET    api/proyectos/{id}/version  -> {version}
 *   GET    api/fotos                   -> {claveNombre: dataURL} fotos de coordinadores y responsables
 *   PUT    api/fotos                   -> body {nombre, imagen|null}; operador o administrador
 *
 * Seguridad: todo (menos ping) exige sesión iniciada. PUT y DELETE exigen además el
 * encabezado X-CSRF-Token y el rol adecuado.
 */
declare(strict_types=1);

require __DIR__ . '/../app/nucleo.php';
require __DIR__ . '/../app/glpi.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

const JSON_OPC = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

function responder(int $estado, mixed $datos): never
{
    http_response_code($estado);
    echo is_string($datos) ? $datos : json_encode($datos, JSON_OPC);
    exit;
}

function error(int $estado, string $mensaje, array $extra = []): never
{
    responder($estado, ['error' => $mensaje] + $extra);
}

// Se decodifica como objetos (no arreglos) para que {} siga siendo {} al guardarlo de nuevo.
function leerCuerpo(): object
{
    if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
        error(415, 'Se esperaba JSON.');
    }
    $cuerpo = json_decode(file_get_contents('php://input') ?: '', false, 512);
    if (!is_object($cuerpo)) {
        error(400, 'El cuerpo debe ser un objeto JSON.');
    }
    return $cuerpo;
}

function guardarVersion(PDO $db, string $id, int $version, string $accion, string $json, int $conservar, string $usuario): void
{
    $db->prepare('INSERT INTO proyectos_versiones (proyecto_id, version, accion, datos, usuario, ip) VALUES (?, ?, ?, ?, ?, ?)')
       ->execute([$id, $version, $accion, $json, $usuario, ip()]);

    // Se conservan solo los últimos N respaldos del proyecto
    $q = $db->prepare("SELECT id FROM proyectos_versiones WHERE proyecto_id = ? ORDER BY id DESC LIMIT 1 OFFSET $conservar");
    $q->execute([$id]);
    $corte = $q->fetchColumn();
    if ($corte !== false) {
        $db->prepare('DELETE FROM proyectos_versiones WHERE proyecto_id = ? AND id <= ?')->execute([$id, $corte]);
    }
}

/* ---------- Rutas ---------- */

function ping(PDO $db): never
{
    $db->query('SELECT 1');
    responder(200, ['tablero' => true]);
}

function sesion(array $u): never
{
    responder(200, [
        'usuario'     => $u['usuario'],
        'nombre'      => $u['nombre'],
        'rol'         => $u['rol'],
        'area'        => $u['area'] ?? null,
        'puedeEditar' => puedeEditar($u),
        'csrf'        => csrfToken(),
    ]);
}

function listarProyectos(PDO $db): never
{
    $filas = $db->query("SELECT id, nombre, actualizado, num_filas, archivado, motivo_archivo, color,
                                JSON_UNQUOTE(JSON_EXTRACT(datos, '$.archivedAt')) AS archivado_en
                         FROM proyectos ORDER BY actualizado DESC")->fetchAll();
    responder(200, array_map(fn($f) => array_filter([
        'id'         => $f['id'],
        'name'       => $f['nombre'],
        'updated'    => $f['actualizado'],
        'n'          => (int)$f['num_filas'],
        'archived'   => (bool)$f['archivado'],
        'archReason' => $f['motivo_archivo'],
        'archivedAt' => $f['archivado'] && $f['archivado_en'] !== 'null' ? $f['archivado_en'] : null,
        'color'      => $f['color'],
    ], fn($v) => $v !== null), $filas));
}

function leerProyecto(PDO $db, string $id): never
{
    $q = $db->prepare('SELECT version, datos FROM proyectos WHERE id = ?');
    $q->execute([$id]);
    $f = $q->fetch() ?: error(404, 'No existe el proyecto.');
    // Se manda el JSON tal cual está guardado, sin volver a procesarlo
    responder(200, '{"version":' . (int)$f['version'] . ',"data":' . $f['datos'] . '}');
}

function leerVersion(PDO $db, string $id): never
{
    $q = $db->prepare('SELECT version FROM proyectos WHERE id = ?');
    $q->execute([$id]);
    $v = $q->fetchColumn();
    $v === false ? error(404, 'No existe el proyecto.') : responder(200, ['version' => (int)$v]);
}

function listarSeguimientos(PDO $db, string $proyectoId): never
{
    $filaId = trim((string)($_GET['fila'] ?? ''));
    if ($filaId === '' || mb_strlen($filaId) > 255) {
        error(400, 'Identificador de registro no válido.');
    }

    $q = $db->prepare('SELECT usuario, comentario, dependencias, compromiso, tiene_glpi, folio_glpi, resuelta, creado_en
                       FROM seguimiento_desviaciones
                       WHERE proyecto_id = ? AND fila_id = ?
                       ORDER BY id');
    $q->execute([$proyectoId, $filaId]);
    $filas = $q->fetchAll();
    responder(200, array_map(static fn(array $f): array => [
        'usuario' => $f['usuario'],
        'comentario' => $f['comentario'],
        'dependencias' => $f['dependencias'],
        'compromiso' => $f['compromiso'] ? date('d/m/Y H:i', strtotime($f['compromiso'])) : '',
        'resuelta' => (bool)$f['resuelta'],
        'tieneGlpi' => (bool)$f['tiene_glpi'],
        'folioGlpi' => $f['folio_glpi'],
        'creadoEn' => date('d/m/Y H:i', strtotime($f['creado_en'])),
    ], $filas));
}

function guardarSeguimiento(PDO $db, string $proyectoId, array $u, int $conservar): never
{
    if (!in_array($u['rol'], ['admin', 'responsable'], true)) {
        error(403, 'Solo un administrador o responsable de área puede documentar este seguimiento.');
    }

    $cuerpo = leerCuerpo();
    $area = $u['rol'] === 'admin'
        ? trim((string)($cuerpo->area ?? ''))
        : (string)($u['area'] ?? '');
    if (!in_array($area, AREAS_RESPONSABLE, true)) {
        error(403, 'No tienes permiso para documentar seguimientos de esta área.');
    }
    $filaId = trim((string)($cuerpo->fila ?? ''));
    $comentario = trim((string)($cuerpo->comentario ?? ''));
    $dependencias = trim((string)($cuerpo->dependencias ?? ''));
    $compromisoEntrada = trim((string)($cuerpo->compromiso ?? ''));
    $tieneGlpiEntrada = $cuerpo->tieneGlpi ?? null;
    $folioGlpi = trim((string)($cuerpo->folioGlpi ?? ''));
    $resuelta = ($cuerpo->resuelta ?? false) === true;

    if ($filaId === '' || mb_strlen($filaId) > 255) {
        error(400, 'Identificador de registro no válido.');
    }
    if ($comentario === '' || mb_strlen($comentario) > 3000) {
        error(400, 'Escribe un comentario de hasta 3,000 caracteres.');
    }
    if (mb_strlen($dependencias) > 3000) {
        error(400, 'Las dependencias no pueden exceder 3,000 caracteres.');
    }
    if (!is_bool($tieneGlpiEntrada)) {
        error(400, 'Indica si el seguimiento cuenta con folio GLPI.');
    }
    if ($tieneGlpiEntrada && ($folioGlpi === '' || mb_strlen($folioGlpi) > 100)) {
        error(400, 'Escribe el folio GLPI (máximo 100 caracteres).');
    }
    if (!$tieneGlpiEntrada) {
        $folioGlpi = '';
    }

    // Al marcarla como resuelta ya no hay compromiso pendiente: la fecha es opcional
    $fechaCompromiso = null;
    if (!$resuelta || $compromisoEntrada !== '') {
        $fechaCompromiso = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $compromisoEntrada);
        $fechaErrores = DateTimeImmutable::getLastErrors();
        if (!$fechaCompromiso
            || ($fechaErrores !== false && ($fechaErrores['warning_count'] > 0 || $fechaErrores['error_count'] > 0))
            || $fechaCompromiso->format('Y-m-d\TH:i') !== $compromisoEntrada) {
            error(400, 'Indica una fecha y hora de compromiso válidas.');
        }
    }

    $db->beginTransaction();
    $q = $db->prepare('SELECT version, datos FROM proyectos WHERE id = ? AND archivado = 0 FOR UPDATE');
    $q->execute([$proyectoId]);
    $filaProyecto = $q->fetch();
    $jsonProyecto = $filaProyecto ? $filaProyecto['datos'] : null;
    $proyecto = is_string($jsonProyecto) ? json_decode($jsonProyecto, true) : null;
    $filasProyecto = is_array($proyecto['rows'] ?? null) ? $proyecto['rows'] : [];
    $filaActual = null;
    $indiceFila = null;
    foreach ($filasProyecto as $i => $fila) {
        if (is_array($fila) && (string)($fila['id'] ?? '') === $filaId) {
            $filaActual = $fila;
            $indiceFila = $i;
            break;
        }
    }
    $sistemaActual = is_array($filaActual['sys'] ?? null) ? $filaActual['sys'] : [];

    if (!$filaActual
        || !empty($filaActual['gone'])
        || ((string)($sistemaActual['area'] ?? '') === 'Cliente / Externo' ? 'Cliente' : (string)($sistemaActual['area'] ?? '')) !== $area
        || trim((string)($sistemaActual['motivo'] ?? '')) === '') {
        $db->rollBack();
        error(409, 'La desviación ya no está asignada a esa área o dejó de estar vigente. Actualiza la página.');
    }

    $db->prepare('INSERT INTO seguimiento_desviaciones
        (proyecto_id, fila_id, area, usuario_id, usuario, comentario, dependencias, compromiso, tiene_glpi, folio_glpi, resuelta)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([
            $proyectoId,
            $filaId,
            $area,
            $u['id'],
            $u['usuario'],
            $comentario,
            $dependencias !== '' ? $dependencias : null,
            $fechaCompromiso ? $fechaCompromiso->format('Y-m-d H:i:s') : null,
            $tieneGlpiEntrada ? 1 : 0,
            $tieneGlpiEntrada ? $folioGlpi : null,
            $resuelta ? 1 : 0,
        ]);

    // Resuelta: se cierra en el registro (queda en su historial con quién y cuándo) y deja de estar activa.
    // Nada se borra: el motivo y el área quedan en la bitácora del registro.
    $version = null;
    if ($resuelta) {
        // Se edita como objetos para no convertir {} vacíos en [] al volver a guardar
        $ahora = gmdate('Y-m-d\TH:i:s.v\Z');
        $obj = json_decode($jsonProyecto);
        $sys = $obj->rows[$indiceFila]->sys;
        if (!isset($sys->log) || !is_array($sys->log)) {
            $sys->log = [];
        }
        $sys->log[] = (object)[
            'at' => $ahora,
            'corte' => is_string($obj->corte ?? null) && $obj->corte !== '' ? $obj->corte : date('Y-m-d'),
            'estatus' => $sys->estatus ?? '',
            'avance' => $sys->avance ?? 0,
            'adicionales' => $sys->adicionales ?? 0,
            'motivo' => (string)$sys->motivo,
            'area' => $area,
            'comentario' => 'Desviación resuelta: ' . $comentario,
            'resuelta' => true,
            'por' => $u['usuario'],
        ];
        $sys->motivo = '';
        $sys->area = '';
        $obj->updated = $ahora;
        $json = json_encode($obj, JSON_OPC);
        $version = (int)$filaProyecto['version'] + 1;
        $db->prepare('UPDATE proyectos SET datos = ?, actualizado = ?, version = ? WHERE id = ?')
           ->execute([$json, $ahora, $version, $proyectoId]);
        guardarVersion($db, $proyectoId, $version, 'guardar', $json, $conservar, $u['usuario']);
    }
    $db->commit();
    bitacora($resuelta ? 'desviacion_resuelta' : 'seguimiento_desviacion', "$area · proyecto $proyectoId · fila $filaId");
    responder(201, ['ok' => true, 'resuelta' => $resuelta, 'version' => $version]);
}

/**
 * Anular una desviación capturada por error: deja de contar en el área (ni activa ni resuelta),
 * pero no se borra: sus anotaciones quedan marcadas como anuladas y se agrega quién, cuándo y por qué.
 */
function anularDesviacion(PDO $db, string $proyectoId, array $u, int $conservar): never
{
    $cuerpo = leerCuerpo();
    $filaId = trim((string)($cuerpo->fila ?? ''));
    $razon = trim((string)($cuerpo->razon ?? ''));
    if ($filaId === '' || mb_strlen($filaId) > 255) {
        error(400, 'Identificador de registro no válido.');
    }
    if ($razon === '' || mb_strlen($razon) > 1000) {
        error(400, 'Escribe cuál fue el error (hasta 1,000 caracteres).');
    }

    $db->beginTransaction();
    $q = $db->prepare('SELECT version, datos FROM proyectos WHERE id = ? AND archivado = 0 FOR UPDATE');
    $q->execute([$proyectoId]);
    $f = $q->fetch();
    $obj = $f ? json_decode($f['datos']) : null;
    $fila = null;
    foreach (($obj->rows ?? []) as $r) {
        if (is_object($r) && (string)($r->id ?? '') === $filaId) {
            $fila = $r;
            break;
        }
    }
    $sys = $fila->sys ?? null;
    $area = trim((string)($sys->area ?? ''));
    if (!$sys || !empty($fila->gone) || $area === '' || trim((string)($sys->motivo ?? '')) === '') {
        $db->rollBack();
        error(409, 'Ese registro ya no tiene una desviación vigente. Actualiza la página.');
    }

    // las anotaciones de esta desviación: las de esa área desde su último cierre o anulación
    $log = is_array($sys->log ?? null) ? $sys->log : [];
    $desde = 0;
    foreach ($log as $i => $l) {
        if ((string)($l->area ?? '') === $area && (!empty($l->resuelta) || !empty($l->anulacion))) {
            $desde = $i + 1;
        }
    }
    $marcadas = 0;
    for ($i = $desde; $i < count($log); $i++) {
        if ((string)($log[$i]->area ?? '') === $area && trim((string)($log[$i]->motivo ?? '')) !== '') {
            $log[$i]->anulada = true;
            $marcadas++;
        }
    }
    $ahora = gmdate('Y-m-d\TH:i:s.v\Z');
    $log[] = (object)[
        'at' => $ahora,
        'corte' => is_string($obj->corte ?? null) && $obj->corte !== '' ? $obj->corte : date('Y-m-d'),
        'estatus' => $sys->estatus ?? '',
        'avance' => $sys->avance ?? 0,
        'adicionales' => $sys->adicionales ?? 0,
        'motivo' => '',
        'area' => $area,
        'anulacion' => true,
        'motivoAnulado' => (string)$sys->motivo,
        'comentario' => 'Desviación anulada (fue un error): ' . $razon,
        'por' => $u['usuario'],
    ];
    $sys->log = $log;
    $sys->motivo = '';
    $sys->area = '';
    $obj->updated = $ahora;
    $json = json_encode($obj, JSON_OPC);
    $version = (int)$f['version'] + 1;
    $db->prepare('UPDATE proyectos SET datos = ?, actualizado = ?, version = ? WHERE id = ?')
       ->execute([$json, $ahora, $version, $proyectoId]);
    guardarVersion($db, $proyectoId, $version, 'guardar', $json, $conservar, $u['usuario']);
    $db->commit();
    bitacora('desviacion_anulada', "$area · proyecto $proyectoId · fila $filaId · $razon");
    responder(200, ['ok' => true, 'version' => $version, 'marcadas' => $marcadas]);
}

function guardarProyecto(PDO $db, string $id, int $conservar, array $u): never
{
    $cuerpo = leerCuerpo();
    $p = $cuerpo->data ?? null;
    if (!is_object($p) || !is_array($p->rows ?? null)) {
        error(400, 'Se esperaba {data: proyecto, base: versión}.');
    }
    if (($p->id ?? $id) !== $id) {
        error(400, 'El id del proyecto no coincide con la ruta.');
    }
    unset($p->auth); // la contraseña vieja del HTML ya no aplica: el acceso lo controla el servidor
    $base = (int)($cuerpo->base ?? 0);
    $json = json_encode($p, JSON_OPC);

    $db->beginTransaction();
    $q = $db->prepare('SELECT version, datos FROM proyectos WHERE id = ? FOR UPDATE');
    $q->execute([$id]);
    $actual = $q->fetch();

    // Alguien más guardó desde que esta pantalla abrió el proyecto: el HTML junta ambos cambios y reintenta
    if ($actual && (int)$actual['version'] !== $base) {
        $db->rollBack();
        responder(409, '{"version":' . (int)$actual['version'] . ',"data":' . $actual['datos'] . '}');
    }

    $nueva = $actual ? (int)$actual['version'] + 1 : 1;
    $nombre = mb_substr(trim((string)($p->name ?? '')), 0, 255);
    $campos = [
        $nombre,
        !empty($p->archived) ? 1 : 0,
        !empty($p->archived) ? mb_substr((string)($p->archiveReason ?? 'concluido'), 0, 20) : null,
        is_string($p->color ?? null) ? mb_substr($p->color, 0, 20) : null,
        count($p->rows),
        is_string($p->updated ?? null) ? mb_substr($p->updated, 0, 32) : gmdate('Y-m-d\TH:i:s.v\Z'),
        $json,
    ];
    if ($actual) {
        $db->prepare('UPDATE proyectos SET nombre = ?, archivado = ?, motivo_archivo = ?, color = ?, num_filas = ?,
                      actualizado = ?, datos = ?, version = ? WHERE id = ?')
           ->execute([...$campos, $nueva, $id]);
    } else {
        $db->prepare('INSERT INTO proyectos (nombre, archivado, motivo_archivo, color, num_filas, actualizado, datos, version, id)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
           ->execute([...$campos, $nueva, $id]);
    }
    guardarVersion($db, $id, $nueva, 'guardar', $json, $conservar, $u['usuario']);
    $db->commit();
    if (!$actual) {
        bitacora('proyecto_creado', $nombre);
    }
    responder(200, ['version' => $nueva]);
}

function borrarProyecto(PDO $db, string $id, int $conservar, array $u): never
{
    $db->beginTransaction();
    $q = $db->prepare('SELECT version, nombre, datos FROM proyectos WHERE id = ? FOR UPDATE');
    $q->execute([$id]);
    if ($f = $q->fetch()) {
        // Queda una copia en proyectos_versiones por si se borró por error
        guardarVersion($db, $id, (int)$f['version'], 'borrar', $f['datos'], $conservar, $u['usuario']);
        $db->prepare('DELETE FROM proyectos WHERE id = ?')->execute([$id]);
        bitacora('proyecto_borrado', "{$f['nombre']} (id $id)");
    }
    $db->commit();
    responder(200, ['ok' => true]);
}

/* ---------- GLPI: consultar el estado de los tickets (solo lectura) ---------- */

/** Folios GLPI que pertenecen a un proyecto (solo esos se consultan). @return array<int,string> folio => fila */
function foliosDelProyecto(PDO $db, string $proyectoId): array
{
    $q = $db->prepare('SELECT datos FROM proyectos WHERE id = ? AND archivado = 0');
    $q->execute([$proyectoId]);
    $json = $q->fetchColumn();
    $p = is_string($json) ? json_decode($json, true) : null;
    if (!is_array($p) || !is_array($p['rows'] ?? null)) {
        error(404, 'No existe el proyecto o está archivado.');
    }
    $out = [];
    $agregar = function (mixed $texto, string $fila) use (&$out): void {
        preg_match_all('/\b\d{3,9}\b/', (string)$texto, $m);
        foreach ($m[0] as $f) {
            $out[(int)$f] ??= $fila;
        }
    };
    foreach ($p['rows'] as $r) {
        if (!is_array($r) || !empty($r['gone'])) {
            continue;
        }
        $fila = (string)($r['id'] ?? '');
        foreach (($r['sys']['folios'] ?? []) as $f) {
            $agregar($f['folio'] ?? '', $fila);
        }
        $agregar($r['sys']['custom']['folios_gpli'] ?? '', $fila);
        foreach (($r['raw'] ?? []) as $col => $v) {
            if (preg_match('/^folios?\s+g(pl|lp)i$/i', trim((string)$col))) {
                $agregar($v, $fila);
            }
        }
    }
    return $out;
}

function glpiCliente(array $CONFIG): Glpi
{
    $cfg = $CONFIG['glpi'] ?? [];
    if (!Glpi::configurado($cfg)) {
        error(503, 'Falta configurar la conexión con GLPI (GLPI_URL y tokens) en el archivo .env.', ['glpi' => 'sin_configurar']);
    }
    return new Glpi($cfg);
}

function glpiConsultar(PDO $db, string $proyectoId, array $CONFIG): never
{
    $cuerpo = leerCuerpo();
    $pedidos = array_values(array_unique(array_map('intval', is_array($cuerpo->folios ?? null) ? $cuerpo->folios : [])));
    if (!$pedidos || count($pedidos) > 1500) {
        error(400, 'Se esperaba una lista de 1 a 1500 folios.');
    }
    $validos = foliosDelProyecto($db, $proyectoId);
    $pedidos = array_values(array_filter($pedidos, fn($f) => isset($validos[$f])));

    $g = glpiCliente($CONFIG);
    try {
        $g->abrir();
        $tickets = $g->tickets($pedidos);
    } catch (GlpiError $e) {
        error(502, $e->getMessage());
    } finally {
        $g->cerrar();
    }

    responder(200, ['tickets' => (object)$tickets]);
}

/* ---------- Prospectos (oportunidades de venta) ---------- */

const ESTATUS_PROSPECTO = ['Pendiente', 'Envío de propuesta', 'En negociación', 'Ganado', 'Perdido'];

function prospectoJson(array $f): array
{
    return [
        'id'          => (int)$f['id'],
        'cliente'     => $f['cliente'],
        'descripcion' => $f['descripcion'],
        'alcance'     => $f['alcance'] ?? '',
        'unidades'    => (float)$f['unidades'],
        'precio'      => (float)$f['precio_unitario'],
        'importe'     => (float)$f['importe'],
        'factura'     => $f['factura'] ?? '',
        'cobrado'     => (float)$f['cobrado'],
        'estatus'     => $f['estatus'],
        'fechaPropuesta' => $f['fecha_propuesta'] ?? '',
        'baja'        => (bool)$f['baja'],
        'motivoBaja'  => $f['motivo_baja'] ?? '',
        'modificadoPor' => $f['modificado_por'] ?? '',
        'modificado'  => $f['modificado_en'],
    ];
}

/** Valida y limpia un prospecto que llega del navegador; devuelve los valores en orden de columna. */
function datosProspecto(object $p): array
{
    $txt = fn($v, int $max) => mb_substr(trim((string)($v ?? '')), 0, $max);
    $numero = function ($v, float $max, string $campo) {
        $n = is_numeric($v) ? (float)$v : 0.0;
        if ($n < 0 || $n > $max) {
            error(400, "El valor de $campo no es válido.");
        }
        return round($n, 2);
    };
    $cliente = $txt($p->cliente ?? '', 120);
    $descripcion = $txt($p->descripcion ?? '', 500);
    if ($cliente === '' || $descripcion === '') {
        error(400, 'El cliente y la descripción son obligatorios.');
    }
    $estatus = in_array($p->estatus ?? '', ESTATUS_PROSPECTO, true) ? $p->estatus : 'Pendiente';
    // fecha de envío de la propuesta: AAAA-MM-DD válida; obligatoria con el estatus "Envío de propuesta"
    $fecha = trim((string)($p->fechaPropuesta ?? ''));
    if ($fecha !== '' && !(preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) && checkdate((int)substr($fecha, 5, 2), (int)substr($fecha, 8, 2), (int)substr($fecha, 0, 4)))) {
        error(400, 'La fecha de envío de la propuesta no es válida.');
    }
    if ($estatus === 'Envío de propuesta' && $fecha === '') {
        error(400, 'Indica la fecha en que se envió la propuesta.');
    }
    return [
        $cliente,
        $descripcion,
        $txt($p->alcance ?? '', 1000),
        $numero($p->unidades ?? 1, 1e9, 'unidades'),
        $numero($p->precio ?? 0, 1e13, 'precio unitario'),
        $txt($p->factura ?? '', 60),
        $numero($p->cobrado ?? 0, 1e15, 'cobrado'),
        $estatus,
        $fecha !== '' ? $fecha : null,
        !empty($p->baja) ? 1 : 0,
        !empty($p->baja) ? $txt($p->motivoBaja ?? '', 300) : null,
    ];
}

function leerProspecto(PDO $db, int $id): ?array
{
    $q = $db->prepare('SELECT * FROM prospectos WHERE id = ?');
    $q->execute([$id]);
    return $q->fetch() ?: null;
}

function listarProspectos(PDO $db): never
{
    $filas = $db->query('SELECT * FROM prospectos ORDER BY baja, importe DESC, id')->fetchAll();
    responder(200, array_map('prospectoJson', $filas));
}

function crearProspecto(PDO $db, array $u): never
{
    $v = datosProspecto(leerCuerpo());
    $db->prepare('INSERT INTO prospectos (cliente, descripcion, alcance, unidades, precio_unitario, factura, cobrado, estatus, fecha_propuesta, baja, motivo_baja, creado_por, modificado_por)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
       ->execute([...$v, $u['usuario'], $u['usuario']]);
    $id = (int)$db->lastInsertId();
    bitacora('prospecto_creado', "{$v[0]}: {$v[1]}");
    responder(201, prospectoJson(leerProspecto($db, $id)));
}

function importarProspectos(PDO $db, array $u): never
{
    $items = leerCuerpo()->items ?? null;
    if (!is_array($items) || !$items || count($items) > 2000) {
        error(400, 'Se esperaba una lista de 1 a 2000 prospectos.');
    }
    $filas = array_map(fn($p) => is_object($p) ? datosProspecto($p) : error(400, 'Prospecto no válido.'), $items);
    $db->beginTransaction();
    $ins = $db->prepare('INSERT INTO prospectos (cliente, descripcion, alcance, unidades, precio_unitario, factura, cobrado, estatus, fecha_propuesta, baja, motivo_baja, creado_por, modificado_por)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($filas as $v) {
        $ins->execute([...$v, $u['usuario'], $u['usuario']]);
    }
    $db->commit();
    bitacora('prospectos_importados', count($filas) . ' prospectos desde Excel');
    responder(200, ['importados' => count($filas)]);
}

function actualizarProspecto(PDO $db, int $id, array $u): never
{
    $antes = leerProspecto($db, $id) ?? error(404, 'No existe el prospecto.');
    $v = datosProspecto(leerCuerpo());
    $db->prepare('UPDATE prospectos SET cliente = ?, descripcion = ?, alcance = ?, unidades = ?, precio_unitario = ?, factura = ?, cobrado = ?,
                  estatus = ?, fecha_propuesta = ?, baja = ?, motivo_baja = ?, modificado_por = ? WHERE id = ?')
       ->execute([...$v, $u['usuario'], $id]);
    if ((bool)$antes['baja'] !== (bool)$v[9]) {
        bitacora($v[9] ? 'prospecto_baja' : 'prospecto_reactivado', "{$v[0]}: {$v[1]}" . ($v[10] ? " ({$v[10]})" : ''));
    }
    responder(200, prospectoJson(leerProspecto($db, $id)));
}

function borrarProspecto(PDO $db, int $id): never
{
    $p = leerProspecto($db, $id) ?? error(404, 'No existe el prospecto.');
    $db->prepare('DELETE FROM prospectos WHERE id = ?')->execute([$id]);
    bitacora('prospecto_borrado', "{$p['cliente']}: {$p['descripcion']}");
    responder(200, ['ok' => true]);
}

/* ---------- Fotos de personas (coordinadores, responsables) ---------- */

function claveFoto(string $nombre): string
{
    $s = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $nombre)), 'UTF-8');
    $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    return mb_substr($s, 0, 190, 'UTF-8');
}

function listarFotos(PDO $db): never
{
    $out = new stdClass();
    foreach ($db->query('SELECT clave, imagen FROM fotos_personas') as $f) {
        $out->{$f['clave']} = $f['imagen'];
    }
    responder(200, $out);
}

// body {nombre, imagen}: imagen es un data URL (JPEG/PNG/WebP) o null para quitar la foto
function guardarFoto(PDO $db, array $u): never
{
    $b = leerCuerpo();
    $nombre = trim((string)($b->nombre ?? ''));
    $clave = claveFoto($nombre);
    if ($clave === '') {
        error(400, 'Falta el nombre de la persona.');
    }
    if (!isset($b->imagen) || $b->imagen === null || $b->imagen === '') {
        $db->prepare('DELETE FROM fotos_personas WHERE clave = ?')->execute([$clave]);
        bitacora('foto_quitada', $nombre);
        responder(200, ['ok' => true]);
    }
    $img = (string)$b->imagen;
    if (strlen($img) > 300000 || !preg_match('#^data:image/(jpeg|png|webp);base64,([A-Za-z0-9+/=]+)$#', $img, $m)) {
        error(400, 'La foto no es válida o es demasiado grande.');
    }
    $bin = base64_decode($m[2], true);
    $info = $bin !== false ? @getimagesizefromstring($bin) : false;
    if ($info === false || !in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)) {
        error(400, 'El archivo no es una imagen.');
    }
    $db->prepare('INSERT INTO fotos_personas (clave, nombre, imagen, modificado_por) VALUES (?, ?, ?, ?)
                  ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), imagen = VALUES(imagen), modificado_por = VALUES(modificado_por)')
       ->execute([$clave, mb_substr($nombre, 0, 190, 'UTF-8'), $img, $u['usuario'] ?? null]);
    bitacora('foto_guardada', $nombre);
    responder(200, ['ok' => true]);
}

/* ---------- Despacho ---------- */

$metodo = $_SERVER['REQUEST_METHOD'];
$partes = array_values(array_filter(explode('/', (string)($_GET['ruta'] ?? '')), 'strlen'));
$recurso = $partes[0] ?? 'ping';
$id = $partes[1] ?? null;
$conservar = max(1, (int)($CONFIG['versiones_a_conservar'] ?? 50));
$escribe = in_array($metodo, ['PUT', 'POST', 'DELETE', 'PATCH'], true);

if ($id !== null && !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) {
    error(400, 'Id de proyecto no válido.');
}

try {
    $db = db();

    if ($recurso === 'ping' && $metodo === 'GET') {
        ping($db);
    }

    // El refresco automático (cada 20 s) no cuenta como actividad: si nadie usa la pantalla, la sesión expira
    $esRefresco = ($partes[2] ?? '') === 'version';
    $u = usuarioActual(!$esRefresco) ?? error(401, 'Inicia sesión.', ['login' => 'login.php?expirada=1']);
    if ($u['debe_cambiar_clave']) {
        error(403, 'Debes cambiar tu contraseña.', ['login' => 'cambiar_clave.php']);
    }

    if ($escribe) {
        // Rechaza peticiones que vengan de otro sitio (CSRF)
        $origen = $_SERVER['HTTP_ORIGIN'] ?? null;
        if ($origen !== null && parse_url($origen, PHP_URL_HOST) . (parse_url($origen, PHP_URL_PORT) ? ':' . parse_url($origen, PHP_URL_PORT) : '') !== $_SERVER['HTTP_HOST']) {
            bitacora('csrf_invalido', "Origen $origen");
            error(403, 'Origen no permitido.');
        }
        if (!csrfValido($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
            bitacora('csrf_invalido', "$metodo api/" . implode('/', $partes));
            error(403, 'Token de seguridad no válido. Recarga la página.');
        }
    } else {
        session_write_close(); // las lecturas no modifican la sesión: no bloquean otras peticiones
    }

    $soloLectura = fn() => error(403, 'Tu usuario es de consulta: no puede modificar.');

    match (true) {
        $recurso === 'sesion' && $metodo === 'GET'                                                => sesion($u),
        // La contraseña de operador del HTML queda reemplazada por los usuarios del servidor
        $recurso === 'auth' && $metodo === 'GET'                                                  => responder(200, '{}'),
        $recurso === 'auth'                                                                       => error(410, 'Las contraseñas se administran en Usuarios.'),
        $recurso === 'proyectos' && $id === null && $metodo === 'GET'                             => listarProyectos($db),
        $recurso === 'proyectos' && $esRefresco && $metodo === 'GET'                              => leerVersion($db, $id),
        $recurso === 'proyectos' && $id !== null && count($partes) === 2 && $metodo === 'GET'     => leerProyecto($db, $id),
        $recurso === 'seguimientos' && $id !== null && count($partes) === 2 && $metodo === 'GET' => listarSeguimientos($db, $id),
        $recurso === 'seguimientos' && $id !== null && count($partes) === 2 && $metodo === 'POST' => guardarSeguimiento($db, $id, $u, $conservar),
        $recurso === 'desviaciones' && $id !== null && ($partes[2] ?? '') === 'anular' && $metodo === 'POST' =>
            puedeEditar($u) ? anularDesviacion($db, $id, $u, $conservar) : error(403, 'Solo el administrador o un operador pueden anular desviaciones.'),
        $recurso === 'proyectos' && $id !== null && count($partes) === 2 && $metodo === 'PUT'     =>
            puedeEditar($u) ? guardarProyecto($db, $id, $conservar, $u) : $soloLectura(),
        $recurso === 'proyectos' && $id !== null && count($partes) === 2 && $metodo === 'DELETE'  =>
            $u['rol'] === 'admin' ? borrarProyecto($db, $id, $conservar, $u) : error(403, 'Solo un administrador puede borrar proyectos.'),
        $recurso === 'glpi' && $id === null && $metodo === 'GET'                                  =>
            responder(200, ['configurado' => Glpi::configurado($CONFIG['glpi'] ?? [])]),
        $recurso === 'glpi' && $id !== null && ($partes[2] ?? '') === 'consultar' && $metodo === 'POST' =>
            puedeEditar($u) ? glpiConsultar($db, $id, $CONFIG) : $soloLectura(),
        $recurso === 'fotos' && $id === null && $metodo === 'GET'                                 => listarFotos($db),
        $recurso === 'fotos' && $id === null && $metodo === 'PUT'                                 =>
            puedeEditar($u) ? guardarFoto($db, $u) : $soloLectura(),
        $recurso === 'prospectos' && $id === null && $metodo === 'GET'                            => listarProspectos($db),
        $recurso === 'prospectos' && $id === null && $metodo === 'POST'                           =>
            puedeEditar($u) ? crearProspecto($db, $u) : $soloLectura(),
        $recurso === 'prospectos' && $id === 'importar' && $metodo === 'POST'                     =>
            puedeEditar($u) ? importarProspectos($db, $u) : $soloLectura(),
        $recurso === 'prospectos' && ctype_digit((string)$id) && $metodo === 'PUT'                =>
            puedeEditar($u) ? actualizarProspecto($db, (int)$id, $u) : $soloLectura(),
        $recurso === 'prospectos' && ctype_digit((string)$id) && $metodo === 'DELETE'             =>
            $u['rol'] === 'admin' ? borrarProspecto($db, (int)$id) : error(403, 'Solo un administrador puede borrar prospectos.'),
        default => error(404, 'Ruta no encontrada.'),
    };
} catch (PDOException $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[PULSOTT] ' . $e->getMessage());
    if ($e->getCode() === '42S22' || (isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1054)) {
        error(503, 'La base de datos necesita actualizarse para guardar seguimientos. Ejecuta la actualización indicada en PULSOTT.');
    }
    error(503, 'No se pudo guardar en la base de datos. Verifica la conexión y vuelve a intentarlo.');
}
