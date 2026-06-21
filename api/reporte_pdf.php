<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once '../config/config.php';

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401); exit('No autenticado');
}

$rol        = $_SESSION['rol'];
$empresa_id = $_SESSION['empresa_id'] ?? null;
$reporte_id = intval($_GET['reporte_id'] ?? 0);

if (!$reporte_id) exit('ID de reporte requerido');

// ─── Cargar datos del reporte ─────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT r.*, emp.nombre AS empresa_nombre, emp.domicilio AS empresa_domicilio,
           u.nombre AS inspector_nombre
    FROM reportes_mensuales r
    JOIN empresas emp ON emp.id = r.empresa_id
    JOIN usuarios u ON u.id = r.inspector_id
    WHERE r.id = ?
");
$stmt->execute([$reporte_id]);
$reporte = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$reporte) exit('Reporte no encontrado');

if ($rol === ROLE_CLIENTE) {
    if ($reporte['empresa_id'] != $empresa_id || $reporte['estado'] !== 'publicado') {
        exit('Sin permiso');
    }
}

// ─── Extintores agrupados por sección ────────────────────────────────────────
// Ordenar secciones por el número de extintor más bajo que contienen,
// luego por número dentro de cada sección.
// NOTA: Incluye todos los extintores sin importar el estado
$stmt = $pdo->prepare("
    SELECT e.*,
           MIN(CAST(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
               UPPER(e.codigo_manual),
               'EXT-',''), 'EXT ',''), 'EXT',''), '-',''), ' ','') AS UNSIGNED))
               OVER (PARTITION BY COALESCE(e.seccion, '')) AS seccion_orden
    FROM extintores e
    WHERE e.empresa_id = ?
    ORDER BY seccion_orden ASC,
             CAST(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
               UPPER(e.codigo_manual),
               'EXT-',''), 'EXT ',''), 'EXT',''), '-',''), ' ','') AS UNSIGNED) ASC
");
$stmt->execute([$reporte['empresa_id']]);
$extintores = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ─── Buscar inspección del mes para cada extintor ────────────────────────────
$mes  = $reporte['mes'];
$anio = $reporte['anio'];

$stmt = $pdo->prepare("
    SELECT i.*, u.nombre AS inspector_nombre
    FROM inspecciones i
    JOIN usuarios u ON u.id = i.inspector_id
    WHERE i.extintor_id = ?
      AND MONTH(i.fecha) = ? AND YEAR(i.fecha) = ?
    ORDER BY i.fecha DESC, i.hora DESC
    LIMIT 1
");

foreach ($extintores as &$ext) {
    $stmt->execute([$ext['id'], $mes, $anio]);
    $insp = $stmt->fetch(PDO::FETCH_ASSOC);
    $ext['inspeccion'] = $insp ?: null;
}
unset($ext);

// ─── Si es el mes actual, usar estado ACTUAL del extintor; si es mes pasado, usar histórico
$fecha_actual = new DateTime('now', new DateTimeZone('America/Mexico_City'));
$mes_actual = (int)$fecha_actual->format('m');
$anio_actual = (int)$fecha_actual->format('Y');

$es_mes_actual = ($mes === $mes_actual && $anio === $anio_actual);

if ($es_mes_actual) {
    // Para el mes actual: usar estado ACTUAL del extintor de la BD
    foreach ($extintores as &$ext) {
        if ($ext['inspeccion']) {
            // Mapear estado actual del extintor a una marca en la inspección
            if ($ext['estado'] === 'en_prestamo') {
                $ext['inspeccion']['po'] = 'PO';
            } else if ($ext['estado'] !== 'activo') {
                // Si tiene otro estado, crear un marcador
                $ext['inspeccion']['_estado_actual'] = $ext['estado'];
            }
        }
    }
    unset($ext);
}

// Usar el inspector asignado al reporte
$inspector_header = strtoupper($reporte['inspector_nombre']);

$meses_es = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio',
             'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$mes_texto = strtoupper(substr($meses_es[$mes], 0, 3)) . '-' . substr($anio, 2, 2);
$num_reporte = $reporte['numero_reporte'];

// ─── Ubicación (usamos observaciones como campo ubicación) ───────────────────
$ubicacion = strtoupper(trim($reporte['observaciones'] ?? ''));

// ─── Dividir extintores en páginas (~30 filas por página) ────────────────────
// Construir filas con secciones intercaladas
$filas = [];
$seccion_actual = null;
$num = 1;

foreach ($extintores as $ext) {
    $seccion = trim($ext['seccion'] ?? '') ?: 'SIN SECCIÓN';
    $seccion = strtoupper($seccion);

    // Agregar fila de sección si cambió
    if ($seccion !== $seccion_actual) {
        $filas[] = ['tipo' => 'seccion', 'texto' => $seccion];
        $seccion_actual = $seccion;
    }

    // Asignar número consecutivo al extintor
    $ext['numero'] = $num++;
    $filas[] = ['tipo' => 'extintor', 'ext' => $ext];
}

// Dividir en páginas de máximo 30 extintores por página
$paginas = [];
$pagina_actual = [];
$extintores_en_pagina = 0;
$seccion_actual_pag = null;

foreach ($filas as $fila) {
    // Si es una nueva sección y ya hay extintores, reinicia la página
    if ($fila['tipo'] === 'seccion' && $extintores_en_pagina >= 25) {
        $paginas[] = $pagina_actual;
        $pagina_actual = [];
        $extintores_en_pagina = 0;
    }

    // Si es un extintor, contar
    if ($fila['tipo'] === 'extintor') {
        if ($extintores_en_pagina >= 30) {
            $paginas[] = $pagina_actual;
            $pagina_actual = [];
            $extintores_en_pagina = 0;
        }
        $extintores_en_pagina++;
    }

    $pagina_actual[] = $fila;
}

if (!empty($pagina_actual)) {
    $paginas[] = $pagina_actual;
}

$total_paginas = count($paginas);

if ($total_paginas === 0) {
    exit('No hay extintores registrados para la empresa "' . htmlspecialchars($reporte['empresa_nombre']) . '". Agrega extintores primero.');
}

// ─── Helper: badge ────────────────────────────────────────────────────────────
function badge(?string $val): string {
    if (!$val) return '';
    $estilos = [
        'OK' => 'background:#d4edda;color:#155724',
        'NC' => 'background:#f8d7da;color:#721c24',
        'NA' => 'background:#fff3cd;color:#856404',
        'PO' => 'background:#cce5ff;color:#004085',
    ];
    $s = $estilos[$val] ?? 'background:#eee;color:#333';
    return "<span style=\"display:inline-block;min-width:24px;padding:1px 3px;border-radius:2px;
                          font-weight:700;font-size:8pt;text-align:center;{$s}\">{$val}</span>";
}

// ─── Helper: formato fecha PH/Recarga → MM-AA ─────────────────────────────────
function fmtFecha(?string $fecha): string {
    if (!$fecha) return '';
    $ts = strtotime($fecha);
    return $ts ? date('m-y', $ts) : '';
}

// ─── Logo AVBA en base64 ──────────────────────────────────────────────────────
$logo_html = '';
$logo_paths = [
    '../public/assets/img/logo-avba.jpg',
    '../public/assets/logos/avba_logo.png',
    '../assets/img/logo.png',
    '../assets/img/logo.jpg',
    '../public/img/logo.png'
];
foreach ($logo_paths as $lp) {
    if (file_exists($lp)) {
        $ext_img = pathinfo($lp, PATHINFO_EXTENSION);
        $mime = ($ext_img === 'png') ? 'image/png' : 'image/jpeg';
        $b64  = base64_encode(file_get_contents($lp));
        $logo_html = "<img src=\"data:{$mime};base64,{$b64}\" style=\"width:120px;height:auto;\" alt=\"AVBA Logo\">";
        break;
    }
}
if (!$logo_html) {
    $logo_html = '<div style="width:100px;border:1px solid #ccc;padding:6px;text-align:center;
                              font-size:7.5pt;font-weight:bold;line-height:1.5">
                    AVBA<br>INSPECTIONS
                  </div>';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($num_reporte) ?> – <?= htmlspecialchars($reporte['empresa_nombre']) ?></title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family: Arial, Helvetica, sans-serif; font-size:8.5pt; color:#000; background:#fff; }

        /* ─── Encabezado de página ─── */
        .page-header { width:100%; margin-bottom:4px; }
        .header-top  { display:flex; justify-content:space-between; align-items:center; margin-bottom:4px; }
        .header-title { flex:1; text-align:center; padding:0 12px; }
        .header-title h1 { font-size:12pt; font-weight:bold; text-transform:uppercase; margin-bottom:3px; }
        .header-title p  { font-size:7pt; color:#333; line-height:1.4; }
        .rev-num { font-size:10pt; font-weight:bold; min-width:70px; text-align:right; }

        /* ─── Info empresa + Leyenda ─── */
        .info-leyenda { display:flex; justify-content:space-between; align-items:flex-start; margin:5px 0; }
        .info-table { border-collapse:collapse; font-size:8.5pt; }
        .info-table td { padding:1px 6px 1px 0; vertical-align:top; }
        .info-table td:first-child { font-weight:bold; white-space:nowrap; }

        .leyenda { border:1px solid #666; border-collapse:collapse; font-size:8pt; }
        .leyenda td { padding:2px 8px; border:1px solid #666; white-space:nowrap; }
        .leg-ok  { background:#d4edda; color:#155724; font-weight:bold; text-align:center; }
        .leg-na  { background:#fff3cd; color:#856404; font-weight:bold; text-align:center; }
        .leg-nc  { background:#f8d7da; color:#721c24; font-weight:bold; text-align:center; }
        .leg-po  { background:#cce5ff; color:#004085; font-weight:bold; text-align:center; }

        /* ─── Tabla principal ─── */
        .main-table { width:100%; border-collapse:collapse; font-size:7.5pt; }
        .main-table th, .main-table td {
            border: 1px solid #666;
            padding: 2px 3px;
            text-align: center;
            vertical-align: middle;
        }
        .main-table td.td-area { text-align:left; max-width:200px; }
        .main-table td.td-obs  { text-align:left; font-size:7pt; }

        .th-dark  { background:#4a4a4a; color:#fff; font-size:7.5pt; padding:3px; }
        .th-desc  { background:#2c2c2c; color:#fff; font-size:6pt; font-weight:normal;
                    white-space:normal; padding:3px; }
        .th-gray  { background:#d9d9d9; color:#000; font-size:7pt; }

        .row-seccion td { background:#bdd7ee; color:#000; font-weight:bold;
                          text-align:left; font-size:8pt; text-transform:uppercase;
                          padding:3px 6px; border:1px solid #666; }

        /* ─── Firma y pie ─── */
        .firma-wrap { display:flex; justify-content:space-between; align-items:flex-end;
                      margin-top:6px; }
        .firma-box  { border:1px solid #666; padding:4px 16px; text-align:center;
                      font-size:8pt; font-weight:bold; min-width:160px; min-height:40px;
                      display:flex; align-items:flex-end; justify-content:center; }
        .pagina-num { font-size:8pt; text-align:right; }

        /* ─── Separador de páginas ─── */
        .page-block { margin-bottom:8px; }

        /* ─── Impresión ─── */
        @page { size: letter landscape; margin: 8mm 8mm; }

        @media print {
            .no-print { display:none !important; }
            body { font-size: 7.5pt; }
            .page-block { page-break-after: always; }
            .page-block:last-child { page-break-after: auto; }
        }

        @media screen {
            body { padding:16px; background:#e0e0e0; }
            .page-block { background:#fff; padding:14px; margin:0 auto 20px;
                          max-width:1050px; box-shadow:0 2px 8px rgba(0,0,0,.2); }
        }

        /* ─── Botones pantalla ─── */
        .btn-bar { max-width:1050px; margin:0 auto 16px; display:flex; gap:10px; }
        .btn-bar button { padding:10px 24px; border:none; border-radius:6px;
                          cursor:pointer; font-weight:700; font-size:14px; }
        .btn-print { background:#667eea; color:#fff; }
        .btn-back  { background:#e0e0e0; color:#333; }
    </style>
</head>
<body>

<!-- Botones solo en pantalla -->
<div class="btn-bar no-print">
    <button class="btn-print" onclick="window.print()">🖨️ Imprimir / Guardar PDF</button>
    <button class="btn-back"  onclick="window.history.back()">← Volver</button>
</div>

<?php foreach ($paginas as $pag_idx => $filas_pagina):
    $pag_num = $pag_idx + 1;
?>
<div class="page-block">

    <!-- ── ENCABEZADO ─────────────────────────────────────────────────────── -->
    <div class="page-header">
        <div class="header-top">
            <?= $logo_html ?>
            <div class="header-title">
                <h1>Reporte de Inspección Mensual de Extintores</h1>
                <p>Conforme a los requerimientos de la NORMA Oficial Mexicana NOM-002-STPS-2010, Condiciones de seguridad-Prevención y protección contra incendios en los centros de trabajo</p>
            </div>
            <div class="rev-num"><?= htmlspecialchars($num_reporte) ?></div>
        </div>

        <div class="info-leyenda">
            <table class="info-table">
                <tr>
                    <td>Empresa:</td>
                    <td><strong><?= htmlspecialchars(strtoupper($reporte['empresa_nombre'])) ?></strong></td>
                </tr>
                <tr>
                    <td>Inspeccionado por:</td>
                    <td><?= htmlspecialchars($inspector_header) ?></td>
                </tr>
                <tr>
                    <td>Fecha de inspección:</td>
                    <td style="color:#0070c0;font-weight:bold"><?= $mes_texto ?></td>
                </tr>
                <tr>
                    <td>Ubicación:</td>
                    <td><?= htmlspecialchars($ubicacion) ?></td>
                </tr>
            </table>

            <table class="leyenda">
                <tr><td class="leg-ok">OK</td><td>Cumple con la NOM-002-STPS</td></tr>
                <tr><td class="leg-na">NA</td><td>No Aplica</td></tr>
                <tr><td class="leg-nc">NC</td><td>No cumple con la NOM-002-STPS</td></tr>
                <tr><td class="leg-po">PO</td><td>Extintor en préstamo</td></tr>
            </table>
        </div>
    </div>

    <!-- ── TABLA ──────────────────────────────────────────────────────────── -->
    <table class="main-table">
        <thead>
            <tr>
                <th class="th-dark" rowspan="3" style="width:24px">No</th>
                <th class="th-dark" rowspan="3">AREA</th>
                <th class="th-dark" rowspan="3" style="width:36px">TIPO</th>
                <th class="th-dark" rowspan="3" style="width:54px">CAPACIDAD</th>
                <th class="th-dark" rowspan="3" style="width:38px">PH</th>
                <th class="th-dark" rowspan="3" style="width:46px">RECARGA</th>
                <th class="th-dark" colspan="13">Inspección en campo</th>
            </tr>
            <tr>
                <th class="th-desc" colspan="12">
                    SEÑ-Señalamiento y soporte, MG-Manguera, PO Extintor de prestado, PH- Prueba hidrostática,
                    SG-Seguro (pasador y cincho), PS-Presión, OB- Obstruido, DAÑ-Daño, PIN-Pintura y etiqueta,
                    FN-Funda, GB-Gabinete, RV-Recarga vigente
                </th>
                <th class="th-dark" rowspan="2" style="min-width:70px;font-size:7pt">Observaciones</th>
            </tr>
            <tr>
                <?php foreach(['SEÑ','MG','PO','PH','SG','PS','OB','DAÑ','PIN','FN','GB','RV'] as $col): ?>
                <th class="th-gray" style="width:28px"><?= $col ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($filas_pagina as $fila):
            if ($fila['tipo'] === 'seccion'): ?>
            <tr class="row-seccion">
                <td colspan="19"><?= htmlspecialchars($fila['texto']) ?></td>
            </tr>
            <?php else:
                $ext  = $fila['ext'];
                $insp = $ext['inspeccion'];
                $keys = ['ser','mg','po','ph','sg','ps','ob','dan','pin','fn','gb','rv'];
            ?>
            <tr>
                <td><?= $ext['numero'] ?></td>
                <td class="td-area"><?= htmlspecialchars($ext['ubicacion']) ?></td>
                <td><?= htmlspecialchars($ext['tipo']) ?></td>
                <td><?= htmlspecialchars($ext['capacidad'] ?? '') ?></td>
                <td><?= fmtFecha($ext['fecha_ph']) ?></td>
                <td><?= fmtFecha($ext['fecha_recarga']) ?></td>
                <?php if ($insp): ?>
                    <?php foreach ($keys as $k): ?>
                    <td><?= badge($insp[$k] ?? null) ?></td>
                    <?php endforeach; ?>
                    <td class="td-obs"><?= htmlspecialchars($insp['observaciones'] ?? '') ?></td>
                <?php else: ?>
                    <?php for ($i = 0; $i < 13; $i++): ?>
                    <td></td>
                    <?php endfor; ?>
                <?php endif; ?>
            </tr>
            <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
    </table>

    <!-- ── FIRMA Y PIE ────────────────────────────────────────────────────── -->
    <div class="firma-wrap">
        <div class="firma-box">FIRMA ENTERADO</div>
        <div class="pagina-num">Hoja <?= $pag_num ?> de <?= $total_paginas ?></div>
    </div>

</div><!-- .page-block -->
<?php endforeach; ?>

</body>
</html>
