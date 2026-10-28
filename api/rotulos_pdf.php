<?php
/**
 * Hoja imprimible de los puntos para rótulos de una planta.
 *
 * Es para quien va a colocarlos, aunque no tenga usuario en el sistema: cada
 * punto con su número, qué rótulo va, dónde, las fotos de referencia, los
 * comentarios y un QR que abre la ubicación en Google Maps desde el teléfono.
 *
 * Como el resto de los reportes, el navegador la imprime o la guarda en PDF.
 */
require_once '../config/config.php';
require_once '../config/rotulos.php';

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401); exit('No autenticado');
}
if (!in_array($_SESSION['rol'], [ROLE_ADMIN, ROLE_INSPECTOR], true)) {
    http_response_code(403); exit('Sin permiso');
}
asegurarTablasRotulos($pdo);

$empresaId = intval($_GET['empresa_id'] ?? 0);
$estado    = in_array($_GET['estado'] ?? '', ['pendiente', 'colocado'], true) ? $_GET['estado'] : 'todos';

$st = $pdo->prepare("SELECT nombre FROM empresas WHERE id = ?");
$st->execute([$empresaId]);
$planta = $st->fetchColumn();
if ($planta === false) { http_response_code(404); exit('Planta no encontrada'); }

$sql = "SELECT * FROM rotulos_puntos WHERE empresa_id = ?" . ($estado !== 'todos' ? " AND estado = ?" : '')
     . " ORDER BY (numero IS NULL), LENGTH(numero), numero, id";
$st = $pdo->prepare($sql);
$st->execute($estado !== 'todos' ? [$empresaId, $estado] : [$empresaId]);
$puntos = $st->fetchAll(PDO::FETCH_ASSOC);

// Fotos y comentarios de todos de una vez
$fotos = $coments = [];
if ($puntos) {
    $ids    = array_column($puntos, 'id');
    $marcas = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT id, punto_id, etapa FROM rotulos_fotos WHERE punto_id IN ($marcas) ORDER BY etapa = 'colocado', id");
    $st->execute($ids);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) $fotos[$f['punto_id']][] = $f;
    $st = $pdo->prepare("
        SELECT c.punto_id, c.texto, c.created_at, u.nombre AS autor
        FROM rotulos_comentarios c LEFT JOIN usuarios u ON u.id = c.usuario_id
        WHERE c.punto_id IN ($marcas) ORDER BY c.created_at, c.id
    ");
    $st->execute($ids);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) $coments[$c['punto_id']][] = $c;
}

// Logo, igual que en el resto de los reportes
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

$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$fecha = fn($f) => $f ? date('d/m/Y', strtotime($f)) : '';
$total = count($puntos);
$colocados = count(array_filter($puntos, fn($p) => $p['estado'] === 'colocado'));
$titEstado = ['todos' => 'Todos los puntos', 'pendiente' => 'Por colocar', 'colocado' => 'Ya colocados'][$estado];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Rótulos — <?= $h($planta) ?></title>
<style>
    * { margin:0; padding:0; box-sizing:border-box; }
    body { font-family:'Segoe UI', Arial, sans-serif; background:#eef2fb; color:#111; font-size:9.5pt; padding:18px; }
    .hoja { background:#fff; max-width:1000px; margin:0 auto; padding:22px 26px; box-shadow:0 4px 18px rgba(30,41,59,.12); }
    .cabecera { display:flex; align-items:center; gap:18px; border-bottom:3px solid #667eea; padding-bottom:12px; margin-bottom:14px; }
    .cabecera h1 { font-size:16pt; }
    .cabecera .sub { color:#475569; font-size:9pt; margin-top:3px; }

    .punto { border:1.5px solid #cbd5e1; border-radius:10px; padding:12px 14px; margin-bottom:12px;
             break-inside:avoid; page-break-inside:avoid; }
    .cab { display:flex; gap:14px; align-items:flex-start; }
    .cab .datos { flex:1; min-width:0; }
    .num { display:inline-block; background:#ea580c; color:#fff; font-weight:800; font-size:13pt; padding:2px 12px; border-radius:14px; margin-right:6px; }
    .num.colocado { background:#16a34a; }
    .rotulo { font-weight:800; font-size:12pt; }
    .estado { font-size:8pt; font-weight:800; padding:2px 8px; border-radius:9px; margin-left:6px; vertical-align:middle; }
    .e-pendiente { background:#ffedd5; color:#9a3412; }
    .e-colocado { background:#dcfce7; color:#166534; }
    .donde { font-size:10.5pt; font-weight:700; margin:6px 0 4px; }
    .gps { font-size:8.5pt; color:#475569; }
    .gps a { color:#4338ca; word-break:break-all; }
    .qr { width:2.6cm; text-align:center; font-size:7pt; color:#64748b; }
    .qr .q { width:2.6cm; height:2.6cm; }
    .qr .q img, .qr .q canvas { width:100% !important; height:100% !important; }

    .fotos { display:grid; grid-template-columns:repeat(3, 1fr); gap:8px; margin-top:10px; }
    .fotos figure { position:relative; }
    .fotos img { width:100%; height:5.2cm; object-fit:cover; border-radius:6px; display:block; background:#e2e8f0; }
    .fotos figcaption { position:absolute; left:5px; bottom:5px; background:rgba(22,163,74,.92); color:#fff; font-size:7pt; font-weight:800; padding:1px 6px; border-radius:7px; }
    .mas { font-size:8pt; color:#64748b; margin-top:4px; }

    .coments { margin-top:10px; border-top:1px dashed #cbd5e1; padding-top:8px; }
    .coment { margin-bottom:5px; font-size:9pt; }
    .coment .quien { color:#64748b; font-size:7.5pt; }
    .vacio { padding:26px; text-align:center; color:#64748b; font-weight:700; }
    .pie { margin-top:14px; border-top:1px solid #e2e8f0; padding-top:9px; font-size:7.5pt; color:#64748b; }

    .barra { max-width:1000px; margin:0 auto 14px; display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
    .barra button { padding:11px 18px; border:none; border-radius:8px; font-weight:700; font-size:10pt; cursor:pointer; }
    .b-imprimir { background:#667eea; color:#fff; }
    .b-volver { background:#e2e8f0; color:#475569; }
    .barra span { font-size:9pt; color:#64748b; }

    @page { size: letter portrait; margin: 10mm 9mm; }
    @media print {
        body { background:#fff; padding:0; }
        .hoja { box-shadow:none; max-width:none; padding:0; }
        .no-print { display:none !important; }
        * { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    }
    @media screen and (max-width:760px) {
        body { padding:10px; }
        .hoja { padding:14px; }
        .cabecera { flex-direction:column; align-items:flex-start; gap:10px; }
        .fotos { grid-template-columns:repeat(2, 1fr); }
        .fotos img { height:34vw; }
        .barra button { flex:1; min-height:44px; }
    }
</style>
</head>
<body>

<div class="barra no-print">
    <button class="b-imprimir" onclick="imprimir()">🖨️ Imprimir / Guardar PDF</button>
    <button class="b-volver" onclick="window.close() || history.back()">← Volver</button>
    <span id="cargando">Cargando fotos…</span>
</div>

<div class="hoja">
    <div class="cabecera">
        <?= $logo_html ?>
        <div>
            <h1>Puntos para rótulos — <?= $h($planta) ?></h1>
            <div class="sub"><?= $h($titEstado) ?> · <?= $total ?> punto(s)<?= $estado === 'todos' ? " · $colocados colocado(s)" : '' ?>
                · Emitido el <?= date('d/m/Y H:i') ?></div>
        </div>
    </div>

    <?php if (!$puntos): ?>
        <p class="vacio">No hay puntos <?= $estado === 'todos' ? 'registrados' : mb_strtolower($titEstado) ?> en esta planta.</p>
    <?php endif; ?>

    <?php foreach ($puntos as $p):
        $fs = $fotos[$p['id']] ?? [];
        $mapa = enlaceMapa($p['lat'], $p['lng']); ?>
        <div class="punto">
            <div class="cab">
                <div class="datos">
                    <?php if ($p['numero']): ?><span class="num <?= $p['estado'] === 'colocado' ? 'colocado' : '' ?>">#<?= $h($p['numero']) ?></span><?php endif; ?>
                    <span class="rotulo"><?= $h($p['rotulo'] ?: 'RÓTULO') ?></span>
                    <span class="estado e-<?= $h($p['estado']) ?>"><?= $p['estado'] === 'colocado' ? 'COLOCADO ' . $fecha($p['colocado_at']) : 'POR COLOCAR' ?></span>
                    <div class="donde"><?= $h($p['ubicacion'] ?: 'Sin descripción del lugar') ?></div>
                    <div class="gps">
                        <?php if ($mapa): ?>
                            GPS: <?= $h(number_format((float) $p['lat'], 6, '.', '')) ?>, <?= $h(number_format((float) $p['lng'], 6, '.', '')) ?>
                            (precisión ±<?= (int) round((float) $p['precision_m']) ?> m)<br>
                            <a href="<?= $h($mapa) ?>"><?= $h($mapa) ?></a>
                        <?php else: ?>
                            Sin ubicación GPS: guíate por las fotos.
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($mapa): ?>
                    <div class="qr"><div class="q" data-url="<?= $h($mapa) ?>"></div>Escanéalo para<br>abrir el mapa</div>
                <?php endif; ?>
            </div>

            <?php if ($fs): ?>
                <div class="fotos">
                    <?php foreach (array_slice($fs, 0, 3) as $f): ?>
                        <figure><img src="rotulos.php?action=foto&id=<?= (int) $f['id'] ?>" alt="">
                            <?php if ($f['etapa'] === 'colocado'): ?><figcaption>COLOCADO</figcaption><?php endif; ?></figure>
                    <?php endforeach; ?>
                </div>
                <?php if (count($fs) > 3): ?><div class="mas">+<?= count($fs) - 3 ?> foto(s) más en el sistema.</div><?php endif; ?>
            <?php endif; ?>

            <?php if (!empty($coments[$p['id']])): ?>
                <div class="coments">
                    <?php foreach ($coments[$p['id']] as $c): ?>
                        <div class="coment"><?= nl2br($h($c['texto'])) ?>
                            <div class="quien"><?= $h($c['autor'] ?: '—') ?> · <?= $fecha($c['created_at']) ?></div></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <div class="pie">AVBA · La posición GPS dentro de naves puede variar varios metros: el punto exacto es el de las fotos.</div>
</div>

<script src="../public/assets/js/qrcode.min.js"></script>
<script>
if (typeof QRCode !== 'undefined') {
    document.querySelectorAll('.qr .q').forEach(el => {
        new QRCode(el, { text: el.dataset.url, width: 256, height: 256, correctLevel: QRCode.CorrectLevel.M });
    });
}
// Que no se imprima con fotos a medio cargar
const imgs = [...document.images];
const listas = () => Promise.all(imgs.map(i => i.complete ? null : new Promise(ok => { i.onload = i.onerror = ok; })));
listas().then(() => { const c = document.getElementById('cargando'); if (c) c.textContent = ''; });
async function imprimir() { await listas(); window.print(); }
</script>
</body>
</html>
