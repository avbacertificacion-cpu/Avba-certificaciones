<?php
/**
 * Hoja imprimible de lo que falta por inspeccionar en un mes.
 *
 * Es la misma lista que la pantalla de Inspecciones muestra en «Faltan por
 * inspeccionar» —sale de config/pendientes.php, la misma consulta— pero
 * puesta para papel: agrupada por planta, con una casilla al margen para ir
 * palomeando en el recorrido.
 *
 * Como el resto de los reportes del sistema, no genera un PDF con una
 * librería: arma la hoja y el navegador la imprime o la guarda como PDF, que
 * es lo que hay disponible en el hosting.
 */
require_once '../config/config.php';
require_once '../config/pendientes.php';

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401); exit('No autenticado');
}
$rol = $_SESSION['rol'];
if (!in_array($rol, [ROLE_ADMIN, ROLE_INSPECTOR], true)) {
    http_response_code(403); exit('Sin permiso');
}

[$mes, $anio] = mesPedido($_GET['mes'] ?? null);
$empresaId    = intval($_GET['empresa_id'] ?? 0);

$faltan    = extintoresPendientes($pdo, $mes, $anio, $empresaId);
$resumen   = resumenPendientes($pdo, $faltan, $empresaId);
$porPlanta = pendientesPorPlanta($faltan);

$nombrePlanta = '';
if ($empresaId) {
    $st = $pdo->prepare("SELECT nombre FROM empresas WHERE id = ?");
    $st->execute([$empresaId]);
    $nombrePlanta = (string) $st->fetchColumn();
}

const MESES = [1=>'Enero','Febrero','Marzo','Abril','Mayo','Junio',
               'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$mesTexto = (MESES[$mes] ?? $mes) . ' ' . $anio;

// Logo, igual que en el reporte mensual
$logo_html = '';
foreach (['../public/assets/img/logo-avba.jpg', '../public/assets/logos/avba_logo.png',
          '../assets/img/logo.png', '../assets/img/logo.jpg', '../public/img/logo.png'] as $lp) {
    if (file_exists($lp)) {
        $mime = (pathinfo($lp, PATHINFO_EXTENSION) === 'png') ? 'image/png' : 'image/jpeg';
        $logo_html = '<img src="data:' . $mime . ';base64,' . base64_encode(file_get_contents($lp))
                   . '" style="width:110px;height:auto" alt="AVBA">';
        break;
    }
}

/** Días transcurridos, sólo si la fecha ya pasó. */
function diasDesde(?string $f): ?int {
    if (!$f) return null;
    $d = (int) floor((time() - strtotime($f . ' 00:00:00')) / 86400);
    return $d > 0 ? $d : null;
}
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Faltan por inspeccionar — <?= $h($mesTexto) ?></title>
<style>
    * { margin:0; padding:0; box-sizing:border-box; }
    body { font-family:'Segoe UI', Arial, sans-serif; background:#eef2fb; color:#111;
           font-size:9.5pt; padding:18px; }
    .hoja { background:#fff; max-width:1000px; margin:0 auto; padding:22px 26px;
            box-shadow:0 4px 18px rgba(30,41,59,.12); }

    .cabecera { display:flex; align-items:center; gap:18px; border-bottom:3px solid #667eea;
                padding-bottom:12px; margin-bottom:14px; }
    .cabecera h1 { font-size:16pt; letter-spacing:.3px; }
    .cabecera .sub { color:#475569; font-size:9pt; margin-top:3px; }

    .resumen { display:flex; flex-wrap:wrap; gap:10px; margin-bottom:16px; }
    .dato { border:1px solid #e2e8f0; border-radius:8px; padding:8px 14px; min-width:120px; }
    .dato .n { font-size:17pt; font-weight:800; line-height:1.1; }
    .dato .r { font-size:7.5pt; color:#64748b; text-transform:uppercase; font-weight:700; }
    .dato.falta .n { color:#b91c1c; }
    .dato.hecho .n { color:#047857; }

    h2.planta { background:#eef2ff; border-left:4px solid #667eea; padding:7px 11px;
                font-size:10.5pt; margin:16px 0 0; }
    h2.planta span { float:right; color:#b91c1c; }

    table { width:100%; border-collapse:collapse; }
    th { background:#4a4a4a; color:#fff; font-size:7.5pt; padding:5px 6px; text-align:left;
         text-transform:uppercase; letter-spacing:.3px; }
    td { border-bottom:1px solid #e5e7eb; padding:5px 6px; font-size:8.5pt; vertical-align:top; }
    td.c, th.c { text-align:center; }
    .casilla { display:inline-block; width:13px; height:13px; border:1.5px solid #94a3b8;
               border-radius:3px; }
    .nunca { color:#b91c1c; font-weight:700; }
    .taller { background:#fef3c7; color:#92400e; padding:2px 6px; border-radius:9px;
              font-size:7.5pt; font-weight:700; white-space:nowrap; }
    .pie { margin-top:18px; border-top:1px solid #e2e8f0; padding-top:9px;
           font-size:7.5pt; color:#64748b; display:flex; justify-content:space-between; gap:16px; }

    .barra { max-width:1000px; margin:0 auto 14px; display:flex; gap:10px; }
    .barra button { padding:11px 18px; border:none; border-radius:8px; font-weight:700;
                    font-size:10pt; cursor:pointer; }
    .b-imprimir { background:#667eea; color:#fff; }
    .b-volver   { background:#e2e8f0; color:#475569; }

    @page { size: letter portrait; margin: 10mm 9mm; }
    @media print {
        body { background:#fff; padding:0; font-size:8.5pt; }
        .hoja { box-shadow:none; max-width:none; padding:0; }
        .no-print { display:none !important; }
        /* Que una planta no empiece al final de la hoja con una sola fila */
        h2.planta { break-after:avoid; page-break-after:avoid; }
        tr { break-inside:avoid; page-break-inside:avoid; }
        thead { display:table-header-group; }
    }
    @media (max-width:760px) {
        body { padding:10px; }
        .hoja { padding:14px; }
        .cabecera { flex-direction:column; align-items:flex-start; gap:10px; }
        table, thead, tbody, tr, td { display:block; width:100%; }
        thead { display:none; }
        tr { border:1px solid #e6ecf8; border-radius:10px; padding:9px 12px; margin-bottom:9px; }
        td { border:none; display:flex; justify-content:space-between; gap:12px; text-align:right; padding:4px 0; }
        td::before { content:attr(data-et); font-size:7.5pt; font-weight:700; color:#64748b;
                     text-transform:uppercase; text-align:left; }
        .barra button { flex:1; min-height:44px; }
    }
</style>
</head>
<body>

<div class="barra no-print">
    <button class="b-imprimir" onclick="window.print()">🖨️ Imprimir / Guardar PDF</button>
    <button class="b-volver" onclick="window.history.back()">← Volver</button>
</div>

<div class="hoja">
    <div class="cabecera">
        <?= $logo_html ?>
        <div>
            <h1>Extintores pendientes de inspección</h1>
            <div class="sub">
                <?= $h($mesTexto) ?>
                · <?= $empresaId ? $h($nombrePlanta) : 'Todas las plantas' ?>
                · Emitido el <?= date('d/m/Y H:i') ?>
            </div>
        </div>
    </div>

    <div class="resumen">
        <div class="dato falta"><div class="n"><?= $resumen['faltan'] ?></div><div class="r">Faltan</div></div>
        <div class="dato hecho"><div class="n"><?= $resumen['inspeccionados'] ?></div><div class="r">Ya inspeccionados</div></div>
        <div class="dato"><div class="n"><?= $resumen['total'] ?></div><div class="r">Extintores activos</div></div>
        <?php if ($resumen['en_taller']): ?>
            <div class="dato"><div class="n"><?= $resumen['en_taller'] ?></div><div class="r">En el taller</div></div>
        <?php endif; ?>
    </div>

    <?php if (!$faltan): ?>
        <p style="padding:26px;text-align:center;color:#047857;font-weight:700">
            No falta ningún extintor por inspeccionar en <?= $h($mesTexto) ?>.
        </p>
    <?php else: foreach ($porPlanta as $planta => $filas): ?>
        <h2 class="planta"><?= $h($planta) ?> <span><?= count($filas) ?> pendiente(s)</span></h2>
        <table>
            <thead><tr>
                <th class="c" style="width:26px">✓</th>
                <th style="width:72px">Código</th>
                <th style="width:120px">Área</th>
                <th>Ubicación</th>
                <th style="width:92px">Tipo</th>
                <th class="c" style="width:42px">Cap.</th>
                <th style="width:118px">Última inspección</th>
                <th style="width:150px">Situación</th>
            </tr></thead>
            <tbody>
            <?php foreach ($filas as $f): $d = diasDesde($f['ultima_inspeccion']); ?>
                <tr>
                    <td class="c" data-et=""><span class="casilla"></span></td>
                    <td data-et="Código"><strong><?= $h($f['codigo_manual'] ?: '—') ?></strong></td>
                    <td data-et="Área"><?= $h($f['seccion'] ?: '—') ?></td>
                    <td data-et="Ubicación"><?= $h($f['ubicacion'] ?: '—') ?></td>
                    <td data-et="Tipo"><?= $h($f['tipo_nombre'] ?: '—') ?></td>
                    <td class="c" data-et="Cap."><?= $h($f['capacidad'] ?: '—') ?></td>
                    <td data-et="Última inspección">
                        <?php if ($f['ultima_inspeccion']): ?>
                            <?= $h(date('d/m/Y', strtotime($f['ultima_inspeccion']))) ?><?= $d ? ' (hace ' . $d . ' d)' : '' ?>
                        <?php else: ?>
                            <span class="nunca">Nunca</span>
                        <?php endif; ?>
                    </td>
                    <td data-et="Situación">
                        <?php if ($f['taller_desde']): ?>
                            <span class="taller">En taller (<?= $h($f['taller_motivo'] ?: 'sin motivo') ?>)
                                desde <?= $h(date('d/m/Y', strtotime($f['taller_desde']))) ?></span>
                        <?php else: ?>
                            Pendiente
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endforeach; endif; ?>

    <div class="pie">
        <span>AVBA · Inspección mensual conforme a la NOM-002-STPS-2010</span>
        <span>Los extintores en el taller no pueden inspeccionarse hasta que regresen.</span>
    </div>
</div>

</body>
</html>
