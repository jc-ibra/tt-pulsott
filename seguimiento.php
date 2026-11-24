<?php
require __DIR__ . '/app/nucleo.php';

$yo = requiereLogin(['admin', 'responsable']);
$db = db();
$esAdmin = $yo['rol'] === 'admin';
$area = $esAdmin ? trim((string)($_GET['area'] ?? '')) : (string)($yo['area'] ?? '');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    pagina('Seguimiento de desviaciones', '<div class="aviso error">Esta pantalla es de consulta. Para documentar una actualización, hazlo desde Desviaciones.</div>', $yo);
    exit;
}

if (!$esAdmin && !in_array($area, AREAS_RESPONSABLE, true)) {
    http_response_code(403);
    pagina('Seguimiento de desviaciones', '<div class="aviso error">Tu cuenta no tiene un área válida asignada. Pide al administrador que la configure.</div>', $yo);
    exit;
}
if ($esAdmin && $area !== '' && !in_array($area, AREAS_RESPONSABLE, true)) {
    $area = '';
}

$desviaciones = [];
$proyectos = $db->query('SELECT id, nombre, datos FROM proyectos WHERE archivado = 0 ORDER BY nombre')->fetchAll();
foreach ($proyectos as $registroProyecto) {
    $proyecto = json_decode($registroProyecto['datos'], true);
    if (!is_array($proyecto)) {
        continue;
    }
    $mapa = is_array($proyecto['cfg']['map'] ?? null) ? $proyecto['cfg']['map'] : [];
    $filas = is_array($proyecto['rows'] ?? null) ? $proyecto['rows'] : [];
    foreach ($filas as $fila) {
        if (!is_array($fila)) {
            continue;
        }
        $sistema = is_array($fila['sys'] ?? null) ? $fila['sys'] : [];
        $areaFila = trim((string)($sistema['area'] ?? ''));
        $motivo = trim((string)($sistema['motivo'] ?? ''));
        $filaId = trim((string)($fila['id'] ?? ''));
        if (!empty($fila['gone']) || $filaId === '' || mb_strlen($filaId) > 255 || $motivo === '' || $areaFila === '' || ($area !== '' && $areaFila !== $area)) {
            continue;
        }
        $raw = is_array($fila['raw'] ?? null) ? $fila['raw'] : [];
        $valor = static function (string $campo) use ($mapa, $raw): string {
            $columna = $mapa[$campo] ?? '';
            return $columna !== '' ? trim((string)($raw[$columna] ?? '')) : '';
        };
        $desviaciones[] = [
            'proyecto_id' => (string)$registroProyecto['id'],
            'proyecto' => (string)$registroProyecto['nombre'],
            'fila_id' => $filaId,
            'nombre' => $valor('nombre'),
            'identificador' => $valor('id'),
            'responsable' => $valor('resp'),
            'estatus' => (string)($sistema['estatus'] ?? ''),
            'motivo' => $motivo,
            'comentario' => (string)($sistema['comentario'] ?? ''),
            'area' => $areaFila,
        ];
    }
}

$q = $area !== ''
    ? $db->prepare('SELECT proyecto_id, fila_id, area, usuario, comentario, dependencias, compromiso, tiene_glpi, folio_glpi, creado_en
                    FROM seguimiento_desviaciones WHERE area = ? ORDER BY id')
    : $db->prepare('SELECT proyecto_id, fila_id, area, usuario, comentario, dependencias, compromiso, tiene_glpi, folio_glpi, creado_en
                    FROM seguimiento_desviaciones ORDER BY id');
if ($area !== '') {
    $q->execute([$area]);
} else {
    $q->execute();
}
$historial = [];
foreach ($q->fetchAll() as $seguimiento) {
    $key = $seguimiento['proyecto_id'] . "\0" . $seguimiento['fila_id'] . "\0" . $seguimiento['area'];
    $historial[$key][] = $seguimiento;
}

$html = '';
$html .= '<div class="tarjeta"><p>Consulta de desviaciones e historial de compromisos. Para registrar una actualización, abre el registro desde Desviaciones y usa “Documentar seguimiento”. ';
$html .= $esAdmin
    ? 'Como administrador, puedes consultar cualquier área.'
    : 'Tu cuenta puede consultar las desviaciones de <b>' . e($area) . '</b>.';
$html .= '</p>';
if ($esAdmin) {
    $html .= '<form method="get" class="fila"><label>Filtrar por área<select name="area"><option value="">Todas las áreas</option>';
    foreach (AREAS_RESPONSABLE as $opcionArea) {
        $html .= '<option value="' . e($opcionArea) . '"' . ($area === $opcionArea ? ' selected' : '') . '>' . e(ETIQUETAS_AREA[$opcionArea] ?? $opcionArea) . '</option>';
    }
    $html .= '</select></label><button class="primario">Filtrar</button></form>';
}
$html .= '</div>';

if (!$desviaciones) {
    $html .= '<div class="aviso">No hay desviaciones vigentes' . ($area !== '' ? ' asignadas a ' . e($area) : '') . '.</div>';
}
foreach ($desviaciones as $desviacion) {
    $key = $desviacion['proyecto_id'] . "\0" . $desviacion['fila_id'] . "\0" . $desviacion['area'];
    $comentarios = $historial[$key] ?? [];
    $html .= '<section class="tarjeta"><h2>' . e($desviacion['nombre'] ?: $desviacion['identificador'] ?: 'Registro sin nombre') . '</h2>'
        . '<p><b>Proyecto:</b> ' . e($desviacion['proyecto']) . ' · <b>ID:</b> ' . e($desviacion['identificador'] ?: $desviacion['fila_id'])
        . ' · <b>Área:</b> ' . e($desviacion['area']) . '</p>'
        . '<p><b>Responsable:</b> ' . e($desviacion['responsable'] ?: '—') . ' · <b>Estatus:</b> ' . e($desviacion['estatus'] ?: '—') . '</p>'
        . '<p><b>Motivo de desviación:</b> ' . e($desviacion['motivo']) . '</p>';
    if ($desviacion['comentario'] !== '') {
        $html .= '<p><b>Comentario del proyecto:</b> ' . nl2br(e($desviacion['comentario'])) . '</p>';
    }
    if ($comentarios) {
        $html .= '<details><summary>Historial de seguimiento (' . count($comentarios) . ')</summary><ol>';
        foreach ($comentarios as $comentario) {
            $html .= '<li><b>' . e($comentario['usuario']) . '</b> · ' . e(date('d/m/Y H:i', strtotime($comentario['creado_en'])))
                . '<br>' . nl2br(e($comentario['comentario']))
                . ($comentario['dependencias'] ? '<br><b>Dependencias:</b> ' . nl2br(e($comentario['dependencias'])) : '')
                . '<br><b>Compromiso:</b> ' . ($comentario['compromiso'] ? e(date('d/m/Y H:i', strtotime($comentario['compromiso']))) : '—')
                . ' · <b>GLPI:</b> ' . ($comentario['tiene_glpi'] ? e($comentario['folio_glpi']) : 'No') . '</li>';
        }
        $html .= '</ol></details>';
    } else {
        $html .= '<p class="nota">Aún no hay seguimientos documentados.</p>';
    }
    $html .= '</section>';
}

pagina('Seguimiento de desviaciones', $html, $yo, true);
