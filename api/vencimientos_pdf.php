<?php
/**
 * Hoja imprimible de los extintores vencidos o por vencer.
 *
 * Es la misma lista que muestra la pantalla de Vencimientos —sale de
 * config/vencimientos.php, la misma consulta—, con los mismos filtros, puesta
 * para papel y agrupada por planta.
 *
 * Como el resto de los reportes del sistema, no genera un PDF con una
 * librería: arma la hoja y el navegador la imprime o la guarda como PDF.
 */
require_once '../config/config.php';
require_once '../config/vencimientos.php';

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401); exit('No autenticado');
}
if ($_SESSION['rol'] !== ROLE_ADMIN) {
    http_response_code(403); exit('Sin permiso');
}

$rango     = rangoPedido($_GET['rango'] ?? null);
$concepto  = conceptoPedido($_GET['concepto'] ?? null);
$empresaId = intval($_GET['empresa_id'] ?? 0);

$r = extintoresPorVencer($pdo, $rango, $concepto, $empresaId);
$porPlanta = [];
foreach ($r['filas'] as $f) $porPlanta[$f['empresa_nombre']][] = $f;

$nombrePlanta = '';
if ($empresaId) {
    $st = $pdo->prepare("SELECT nombre FROM empresas WHERE id = ?");
    $st->execute([$empresaId]);
    $nombrePlanta = (string) $st->fetchColumn();
}

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

$h     = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$fecha = fn($f) => $f ? date('d/m/Y', strtotime($f)) : '—';
$clase = fn(int $d) => $d < 0 ? 'v-vencido' : ($d <= 15 ? 'v-pronto' : 'v-mes');
$titulo = $rango === 'vencidos' ? 'Extintores vencidos' : 'Extintores por vencer — ' . mb_strtolower(VENCE_RANGOS[$rango]);
$vacio  = $rango === 'vencidos' ? 'No hay extintores vencidos' : 'No vence ningún extintor en los ' . mb_strtolower(VENCE_RANGOS[$rango]);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $h($titulo) ?></title>
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
    .dato.vencido .n { color:#b91c1c; }
    .dato.d15 .n     { color:#c2410c; }
    .dato.d30 .n     { color:#a16207; }
    .dato.activo     { border:2px solid #667eea; background:#eef2ff; }

    h2.planta { background:#eef2ff; border-left:4px solid #667eea; padding:7px 11px;
                font-size:10.5pt; margin:16px 0 0; }
    h2.planta span { float:right; color:#475569; }

    table { width:100%; border-collapse:collapse; }
    th { background:#4a4a4a; color:#fff; font-size:7.5pt; padding:5px 6px; text-align:left;
         text-transform:uppercase; letter-spacing:.3px; }
    td { border-bottom:1px solid #e5e7eb; padding:5px 6px; font-size:8.5pt; vertical-align:top; }
    td.c, th.c { text-align:center; }
    .casilla { display:inline-block; width:13px; height:13px; border:1.5px solid #94a3b8;
               border-radius:3px; }
    /* Cada vencimiento en dos renglones: qué y cuándo, y cuánto falta */
    .vence { display:block; }
    .vence + .vence { margin-top:4px; }
    .vence small { display:block; font-weight:700; }
    .vence small.v-vencido { color:#b91c1c; }
    .vence small.v-pronto  { color:#c2410c; }
    .vence small.v-mes     { color:#a16207; }
    td.codigo { white-space:nowrap; }
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
    @media screen and (max-width:760px) {
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
            <h1><?= $h($titulo) ?></h1>
            <div class="sub">
                <?= $empresaId ? $h($nombrePlanta) : 'Todas las plantas' ?>
                · <?= $h(VENCE_CONCEPTOS[$concepto]) ?>
                · Emitido el <?= date('d/m/Y H:i') ?>
            </div>
        </div>
    </div>

    <div class="resumen">
        <div class="dato vencido<?= $rango === 'vencidos' ? ' activo' : '' ?>"><div class="n"><?= $r['conteo']['vencidos'] ?></div><div class="r">Vencidos</div></div>
        <div class="dato d15<?= $rango === '15' ? ' activo' : '' ?>"><div class="n"><?= $r['conteo']['15'] ?></div><div class="r">Vencen en 15 días</div></div>
        <div class="dato d30<?= $rango === '30' ? ' activo' : '' ?>"><div class="n"><?= $r['conteo']['30'] ?></div><div class="r">Vencen en 30 días</div></div>
        <?php if ($r['en_taller']): ?>
            <div class="dato"><div class="n"><?= $r['en_taller'] ?></div><div class="r">Ya en el taller</div></div>
        <?php endif; ?>
    </div>

    <?php if (!$r['filas']): ?>
        <p style="padding:26px;text-align:center;color:#047857;font-weight:700"><?= $h($vacio) ?>.</p>
    <?php else: foreach ($porPlanta as $planta => $filas): ?>
        <h2 class="planta"><?= $h($planta) ?> <span><?= count($filas) ?> extintor(es)</span></h2>
        <table>
            <thead><tr>
                <th class="c" style="width:26px">✓</th>
                <th style="width:64px">Código</th>
                <th style="width:110px">Área</th>
                <th>Ubicación</th>
                <th style="width:80px">Tipo</th>
                <th class="c" style="width:36px">Cap.</th>
                <th style="width:165px">Qué vence</th>
                <th style="width:82px">Situación</th>
            </tr></thead>
            <tbody>
            <?php foreach ($filas as $f): ?>
                <tr>
                    <td class="c" data-et=""><span class="casilla"></span></td>
                    <td class="codigo" data-et="Código"><strong><?= $h($f['codigo_manual'] ?: '—') ?></strong></td>
                    <td data-et="Área"><?= $h($f['seccion'] ?: '—') ?></td>
                    <td data-et="Ubicación"><?= $h($f['ubicacion'] ?: '—') ?></td>
                    <td data-et="Tipo"><?= $h($f['tipo_nombre'] ?: '—') ?></td>
                    <td class="c" data-et="Cap."><?= $h($f['capacidad'] ?: '—') ?></td>
                    <td data-et="Qué vence">
                        <?php foreach ($f['vencimientos'] as $v): ?>
                            <span class="vence"><?php if ($v['desde']): ?><?= $h($v['nombre']) ?> del <?= $fecha($v['desde']) ?>,
                                vence <?= $fecha($v['fecha']) ?><?php else: ?><?= $h($v['nombre']) ?>: vence <?= $fecha($v['fecha']) ?><?php endif; ?>
                                <small class="<?= $clase($v['dias']) ?>"><?= $h(textoDias($v['dias'])) ?></small></span>
                        <?php endforeach; ?>
                    </td>
                    <td data-et="Situación">
                        <?php if ($f['taller_desde']): ?>
                            <span class="taller">En taller desde <?= $fecha($f['taller_desde']) ?></span>
                        <?php elseif ($f['estado'] === 'en_prestamo'): ?>
                            <span class="taller">En préstamo</span>
                        <?php else: ?>
                            En planta
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endforeach; endif; ?>

    <div class="pie">
        <span>AVBA · Servicio anual: 12 meses después de la última recarga o mantenimiento · Prueba hidrostática: fecha de vigencia registrada</span>
        <span>«15 días» y «30 días» cuentan desde hoy; los de 15 también están en 30.</span>
    </div>
</div>

</body>
</html>
