<?php
/**
 * Genera el Reporte de Inspección Mensual de Extintores.
 * Parámetros GET:
 *   - reporte_id : ID del reporte mensual
 *   - preview    : 1 = mostrar en pantalla, 0 = forzar descarga
 */
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
    SELECT r.*, emp.nombre AS empresa_nombre, p.nombre AS plantilla_nombre
    FROM reportes_mensuales r
    JOIN empresas   emp ON emp.id = r.empresa_id
    JOIN plantillas p   ON p.id  = r.plantilla_id
    WHERE r.id = ?
");
$stmt->execute([$reporte_id]);
$reporte = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$reporte) exit('Reporte no encontrado');

// Cliente solo puede ver sus reportes publicados
if ($rol === ROLE_CLIENTE) {
    if ($reporte['empresa_id'] != $empresa_id || $reporte['estado'] !== 'publicado') {
        exit('Sin permiso');
    }
}

// ─── Obtener todos los extintores de la empresa, agrupados por sección ─────────
$stmt = $pdo->prepare("
    SELECT e.*
    FROM extintores e
    WHERE e.empresa_id = ? AND e.estado != 'inactivo'
    ORDER BY COALESCE(e.seccion,'ZZZ') ASC, e.codigo_manual ASC
");
$stmt->execute([$reporte['empresa_id']]);
$extintores = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ─── Para cada extintor, buscar su última inspección del mes del reporte ────────
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

$inspectores_del_mes = [];

foreach ($extintores as &$ext) {
    $stmt->execute([$ext['id'], $mes, $anio]);
    $insp = $stmt->fetch(PDO::FETCH_ASSOC);
    $ext['inspeccion'] = $insp ?: null;

    if ($insp && !in_array($insp['inspector_nombre'], $inspectores_del_mes)) {
        $inspectores_del_mes[] = $insp['inspector_nombre'];
    }
}
unset($ext);

// Inspector que aparece en el encabezado
$inspector_header = implode(', ', $inspectores_del_mes) ?: '—';

// Mes en texto
$meses_es = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio',
             'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$mes_texto = strtoupper(substr($meses_es[$mes], 0, 3)) . '-' . substr($anio, 2, 2);

// Número de reporte
$num_reporte = $reporte['numero_reporte'];

// Helper: badge de valor
function badge(string $val = null): string {
    if (!$val) return '';
    $colores = [
        'OK' => ['#155724','#d4edda'],
        'NC' => ['#721c24','#f8d7da'],
        'NA' => ['#856404','#fff3cd'],
        'PO' => ['#004085','#cce5ff'],
    ];
    [$fg, $bg] = $colores[$val] ?? ['#333','#eee'];
    return "<span style=\"display:inline-block;min-width:26px;padding:2px 4px;border-radius:3px;
                          background:$bg;color:$fg;font-weight:700;font-size:9pt;text-align:center\">$val</span>";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($num_reporte) ?> – <?= htmlspecialchars($reporte['empresa_nombre']) ?></title>
    <style>
        /* ─── Reset & base ─── */
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 9pt; color: #000; background:#fff; }

        /* ─── Encabezado ─── */
        .header-wrap  { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:6px; }
        .logo-box     { width:110px; }
        .logo-box img { width:100%; }
        .logo-placeholder { width:110px; font-size:8pt; color:#555; border:1px solid #ccc; padding:8px; text-align:center; line-height:1.4; }
        .title-box    { flex:1; text-align:center; padding:0 16px; }
        .title-box h1 { font-size:13pt; font-weight:bold; text-transform:uppercase; margin-bottom:4px; }
        .title-box p  { font-size:7.5pt; color:#333; line-height:1.4; }
        .rev-box      { font-size:10pt; font-weight:bold; text-align:right; min-width:70px; }

        /* ─── Info empresa + leyenda ─── */
        .info-wrap  { display:flex; justify-content:space-between; margin:10px 0 8px; }
        .info-table { font-size:9pt; border-collapse:collapse; }
        .info-table td { padding:1px 6px 1px 0; }
        .info-table td:first-child { font-weight:bold; white-space:nowrap; }
        .leyenda    { border:1px solid #999; border-collapse:collapse; font-size:8pt; }
        .leyenda td { padding:2px 6px; border:1px solid #999; }
        .leg-ok  { background:#d4edda; color:#155724; font-weight:bold; text-align:center; }
        .leg-na  { background:#fff3cd; color:#856404; font-weight:bold; text-align:center; }
        .leg-nc  { background:#f8d7da; color:#721c24; font-weight:bold; text-align:center; }
        .leg-po  { background:#cce5ff; color:#004085; font-weight:bold; text-align:center; }

        /* ─── Tabla principal ─── */
        .main-table { width:100%; border-collapse:collapse; font-size:8pt; margin-top:4px; }
        .main-table th, .main-table td {
            border: 1px solid #999;
            padding: 3px 4px;
            text-align: center;
            vertical-align: middle;
        }
        .main-table td.area-cell { text-align:left; }

        /* Cabecera de la tabla */
        .th-main  { background:#4a4a4a; color:#fff; font-size:8pt; }
        .th-insp  { background:#4a4a4a; color:#fff; font-size:7pt; }
        .th-sub   { background:#d9d9d9; color:#000; font-size:7pt; }

        /* Filas de sección (agrupador) */
        .row-seccion td {
            background:#bdd7ee;
            color:#000;
            font-weight:bold;
            text-align:center;
            font-size:8.5pt;
            text-transform:uppercase;
            border:1px solid #999;
        }

        /* Filas de extintor */
        .row-extintor td { background:#fff; }
        .row-extintor:nth-child(even) td { background:#f7f9fc; }

        /* Sin inspección en el mes */
        .no-insp { color:#aaa; font-size:7.5pt; }

        /* ─── Print ─── */
        @page { size: letter landscape; margin: 12mm 10mm; }
        @media print {
            .no-print { display:none !important; }
            body { font-size: 8pt; }
        }
        @media screen {
            body { padding: 20px; max-width: 1100px; margin:0 auto; }
        }
    </style>
</head>
<body>

<!-- Barra de acciones (no se imprime) -->
<div class="no-print" style="margin-bottom:16px;display:flex;gap:10px">
    <button onclick="window.print()"
        style="padding:10px 24px;background:#667eea;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:700;font-size:14px">
        🖨️ Imprimir / Guardar PDF
    </button>
    <button onclick="window.history.back()"
        style="padding:10px 24px;background:#e0e0e0;color:#333;border:none;border-radius:6px;cursor:pointer;font-weight:700;font-size:14px">
        ← Volver
    </button>
</div>

<!-- ─── ENCABEZADO ────────────────────────────────────────────────────────── -->
<div class="header-wrap">
    <!-- Logo: reemplazar src con ruta real del logo AVBA -->
    <?php if (file_exists('../assets/img/logo.png')): ?>
        <img src="../assets/img/logo.png" class="logo-box" alt="AVBA Inspections">
    <?php else: ?>
        <div class="logo-placeholder">AVBA<br>INSPECTIONS</div>
    <?php endif; ?>

    <div class="title-box">
        <h1>Reporte de Inspección Mensual de Extintores</h1>
        <p>Conforme a los requerimientos de la NORMA Oficial Mexicana NOM-002-STPS-2010,<br>
           Condiciones de seguridad-Prevención y protección contra incendios en los centros de trabajo</p>
    </div>

    <div class="rev-box"><?= htmlspecialchars($num_reporte) ?></div>
</div>

<!-- ─── INFO EMPRESA + LEYENDA ───────────────────────────────────────────── -->
<div class="info-wrap">
    <table class="info-table">
        <tr>
            <td>Empresa:</td>
            <td><?= htmlspecialchars($reporte['empresa_nombre']) ?></td>
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
            <td style="color:#0070c0">Ubicación:</td>
            <td style="color:#0070c0"><?= htmlspecialchars($reporte['observaciones'] ?? '') ?></td>
        </tr>
    </table>

    <table class="leyenda">
        <tr>
            <td class="leg-ok">OK</td>
            <td>Cumple con la NOM-002-STPS</td>
        </tr>
        <tr>
            <td class="leg-na">NA</td>
            <td>No Aplica</td>
        </tr>
        <tr>
            <td class="leg-nc">NC</td>
            <td>No cumple con la NOM-002-STPS</td>
        </tr>
        <tr>
            <td class="leg-po">PO</td>
            <td>Extintor en préstamo</td>
        </tr>
    </table>
</div>

<!-- ─── TABLA PRINCIPAL ──────────────────────────────────────────────────── -->
<table class="main-table">
    <thead>
        <tr>
            <th class="th-main" rowspan="3" style="width:28px">No</th>
            <th class="th-main" rowspan="3">AREA</th>
            <th class="th-main" rowspan="3" style="width:38px">TIPO</th>
            <th class="th-main" rowspan="3" style="width:52px">CAPACIDAD</th>
            <th class="th-main" rowspan="3" style="width:40px">PH</th>
            <th class="th-main" rowspan="3" style="width:48px">RECARGA</th>
            <th class="th-insp" colspan="10">Inspección en campo</th>
        </tr>
        <tr>
            <th class="th-insp" colspan="9" style="font-size:6.5pt;font-weight:normal;background:#2c2c2c;white-space:normal;padding:3px">
                SEÑ-Señalamiento y soporte, MG-Manguera, PO Extintor de prestado, PH-Prueba hidrostática,
                SG-Seguro (pasador y cincho), PS-Presión, OB-Obstruido, DAÑ-Daño, PIN-Pintura y etiqueta
            </th>
            <th class="th-insp" rowspan="2" style="min-width:80px;background:#2c2c2c">Observaciones</th>
        </tr>
        <tr>
            <?php foreach(['SEÑ','MG','PO','PH','SG','PS','OB','DAÑ','PIN'] as $col): ?>
            <th class="th-sub" style="width:30px"><?= $col ?></th>
            <?php endforeach; ?>
        </tr>
    </thead>
    <tbody>
    <?php
    $num        = 1;
    $seccion_actual = '__NINGUNA__';

    foreach ($extintores as $ext):
        $seccion = trim($ext['seccion'] ?? '');

        // Fila de sección si cambió
        if ($seccion !== $seccion_actual):
            $seccion_actual = $seccion;
            if ($seccion !== ''):
    ?>
        <tr class="row-seccion">
            <td colspan="16"><?= htmlspecialchars(strtoupper($seccion)) ?></td>
        </tr>
    <?php
            endif;
        endif;

        $insp = $ext['inspeccion'];
        $keys = ['ser','mg','po','ph','sg','ps','ob','dan','pin'];
    ?>
        <tr class="row-extintor">
            <td><?= $num++ ?></td>
            <td class="area-cell"><?= htmlspecialchars($ext['ubicacion']) ?></td>
            <td><?= htmlspecialchars($ext['tipo']) ?></td>
            <td><?= htmlspecialchars($ext['capacidad'] ?? '') ?></td>
            <td><?= $ext['fecha_ph']      ? date('m/Y', strtotime($ext['fecha_ph']))      : '' ?></td>
            <td><?= $ext['fecha_recarga'] ? date('m/Y', strtotime($ext['fecha_recarga'])) : '' ?></td>

            <?php if ($insp): ?>
                <?php foreach ($keys as $k): ?>
                <td><?= badge($insp[$k] ?? null) ?></td>
                <?php endforeach; ?>
                <td class="area-cell" style="font-size:7.5pt"><?= htmlspecialchars($insp['observaciones'] ?? '') ?></td>
            <?php else: ?>
                <?php for ($i = 0; $i < 9; $i++): ?>
                <td class="no-insp">—</td>
                <?php endfor; ?>
                <td class="no-insp">Sin inspección</td>
            <?php endif; ?>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<!-- Pie de página -->
<div style="margin-top:16px;font-size:7.5pt;color:#666;text-align:center">
    Reporte generado el <?= date('d/m/Y H:i') ?> •
    <?= htmlspecialchars($reporte['empresa_nombre']) ?> •
    Período: <?= $meses_es[$mes] ?> <?= $anio ?>
</div>

</body>
</html>
