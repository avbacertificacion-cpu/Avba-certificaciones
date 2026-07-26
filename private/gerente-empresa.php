<?php
require_once '../config/config.php';
require_once '../config/roles-extra.php';
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== ROLE_GERENTE) {
    header('Location: ../public/login.html'); exit;
}
$nombre     = $_SESSION['nombre'];
$uid        = $_SESSION['usuario_id'];
$empresa_id = intval($_GET['empresa_id'] ?? 0);

// ── Validar que la empresa esté asignada a este gerente ──────────────────────
$stmt = $pdo->prepare("SELECT 1 FROM gerente_empresas WHERE gerente_id = ? AND empresa_id = ?");
$stmt->execute([$uid, $empresa_id]);
if (!$stmt->fetchColumn()) {
    header('Location: gerente-dashboard.php'); exit;
}

// ── Datos de la empresa ──────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT nombre, domicilio FROM empresas WHERE id = ?");
$stmt->execute([$empresa_id]);
$empresa = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$empresa) { header('Location: gerente-dashboard.php'); exit; }

$mes_actual = (int) date('n');
$anio_actual = (int) date('Y');

// ── Métricas ─────────────────────────────────────────────────────────────────
$q = function($sql, $params) use ($pdo) { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchColumn(); };

$total_extintores = (int) $q("SELECT COUNT(*) FROM extintores WHERE empresa_id=? AND estado!='inactivo'", [$empresa_id]);
$inspeccionados_mes = (int) $q("
    SELECT COUNT(DISTINCT i.extintor_id) FROM inspecciones i
    JOIN extintores e ON e.id=i.extintor_id
    WHERE e.empresa_id=? AND MONTH(i.fecha)=? AND YEAR(i.fecha)=?", [$empresa_id, $mes_actual, $anio_actual]);
$porcentaje = $total_extintores > 0 ? round(($inspeccionados_mes/$total_extintores)*100) : 0;
$vencidos = (int) $q("SELECT COUNT(*) FROM extintores WHERE empresa_id=? AND estado!='inactivo' AND fecha_ph IS NOT NULL AND fecha_ph < CURDATE()", [$empresa_id]);
$proximos = (int) $q("SELECT COUNT(*) FROM extintores WHERE empresa_id=? AND estado!='inactivo' AND fecha_ph IS NOT NULL AND fecha_ph BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 15 DAY)", [$empresa_id]);

// ── Inspecciones últimos 6 meses ─────────────────────────────────────────────
$insp_mes = [];
for ($i = 5; $i >= 0; $i--) {
    $f = strtotime("-$i months"); $m = date('n',$f); $a = date('Y',$f);
    $nom = ['','Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'][$m];
    $cant = (int) $q("SELECT COUNT(DISTINCT i.extintor_id) FROM inspecciones i JOIN extintores e ON e.id=i.extintor_id
                      WHERE e.empresa_id=? AND MONTH(i.fecha)=? AND YEAR(i.fecha)=?", [$empresa_id, $m, $a]);
    $insp_mes[] = ['mes'=>$nom, 'cantidad'=>$cant];
}

// ── Mantenimiento por mes (1 año desde recarga o sin presión) ────────────────
$mant_mes = [];
for ($i = 5; $i >= 0; $i--) {
    $f = strtotime("-$i months"); $m = date('n',$f); $a = date('Y',$f);
    $nom = ['','Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'][$m];
    $st = $pdo->prepare("
        SELECT COUNT(*) FROM (
            SELECT i.extintor_id AS eid FROM inspecciones i JOIN extintores e ON e.id=i.extintor_id
              WHERE e.empresa_id=? AND i.ps='NC' AND MONTH(i.fecha)=? AND YEAR(i.fecha)=?
            UNION
            SELECT e.id AS eid FROM extintores e
              WHERE e.empresa_id=? AND e.fecha_recarga IS NOT NULL
                AND MONTH(DATE_ADD(e.fecha_recarga, INTERVAL 1 YEAR))=? AND YEAR(DATE_ADD(e.fecha_recarga, INTERVAL 1 YEAR))=?
        ) t");
    $st->execute([$empresa_id, $m, $a, $empresa_id, $m, $a]);
    $mant_mes[] = ['mes'=>$nom, 'cantidad'=>(int)$st->fetchColumn()];
}

// ── Extintores por tipo ──────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT te.nombre AS tipo, COUNT(*) AS cantidad FROM extintores e
    JOIN tipos_extintores te ON te.id=e.tipo
    WHERE e.empresa_id=? AND e.estado!='inactivo' GROUP BY e.tipo ORDER BY cantidad DESC");
$stmt->execute([$empresa_id]);
$por_tipo = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Vigencia ─────────────────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT
    SUM(CASE WHEN fecha_ph > CURDATE() THEN 1 ELSE 0 END) AS vigentes,
    SUM(CASE WHEN fecha_ph IS NULL THEN 1 ELSE 0 END) AS sin_registro,
    SUM(CASE WHEN fecha_ph < CURDATE() THEN 1 ELSE 0 END) AS vencidos
    FROM extintores WHERE empresa_id=? AND estado!='inactivo'");
$stmt->execute([$empresa_id]);
$vig = $stmt->fetch(PDO::FETCH_ASSOC);

// ── Lista de extintores ──────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT e.codigo_manual, e.ubicacion, e.seccion, e.capacidad, e.estado,
           e.fecha_recarga, e.fecha_ph, te.nombre AS tipo_nombre,
           (SELECT MAX(fecha) FROM inspecciones WHERE extintor_id=e.id) AS ultima_inspeccion
    FROM extintores e JOIN tipos_extintores te ON te.id=e.tipo
    WHERE e.empresa_id=?
    ORDER BY CAST(REPLACE(REPLACE(UPPER(e.codigo_manual),'EXT-',''),'-','') AS UNSIGNED) ASC");
$stmt->execute([$empresa_id]);
$extintores = $stmt->fetchAll(PDO::FETCH_ASSOC);

$fmt = function($iso){ if(!$iso) return '—'; $d=DateTime::createFromFormat('Y-m-d', substr($iso,0,10)); return $d? $d->format('d/m/Y'):'—'; };

$json_insp_meses = json_encode(array_column($insp_mes,'mes'));
$json_insp_cant  = json_encode(array_column($insp_mes,'cantidad'));
$json_mant_meses = json_encode(array_column($mant_mes,'mes'));
$json_mant_cant  = json_encode(array_column($mant_mes,'cantidad'));
$json_tipos      = json_encode(array_column($por_tipo,'tipo'));
$json_tipos_cant = json_encode(array_column($por_tipo,'cantidad'));
$json_vig = json_encode([(int)($vig['vigentes']??0),(int)($vig['sin_registro']??0),(int)($vig['vencidos']??0)]);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($empresa['nombre']) ?> – Gerente</title>
<script src="../public/assets/js/chart.umd.min.js"></script>
<style>
    *{margin:0;padding:0;box-sizing:border-box}
    body{font-family:'Segoe UI',system-ui,sans-serif;background:#eef2fb;color:#1a2138}
    .navbar{background:linear-gradient(135deg,#1e3a8a,#4338ca);color:#fff;padding:16px 28px;
            display:flex;justify-content:space-between;align-items:center;box-shadow:0 4px 16px rgba(30,58,138,.25)}
    .navbar a{color:#fff;text-decoration:none;font-size:13px;opacity:.9}
    .navbar a:hover{opacity:1;text-decoration:underline}
    .container{max-width:1300px;margin:0 auto;padding:28px 20px}
    .emp-head{margin-bottom:22px}
    .emp-head h2{font-size:24px;font-weight:800;color:#1e293b}
    .emp-head p{color:#64748b;font-size:13px;margin-top:2px}

    .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:26px}
    .stat{background:#fff;border-radius:14px;padding:18px 20px;box-shadow:0 4px 14px rgba(30,41,59,.08)}
    .stat .n{font-size:28px;font-weight:800;color:#1e293b}
    .stat .l{font-size:12px;color:#64748b;font-weight:600;margin-top:2px}
    .stat.warn .n{color:#f59e0b} .stat.bad .n{color:#dc2626} .stat.ok .n{color:#059669}

    .charts{display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:20px;margin-bottom:26px}
    .chart-card{background:#fff;border-radius:14px;padding:20px;box-shadow:0 4px 14px rgba(30,41,59,.08)}
    .chart-card h3{font-size:15px;color:#334155;margin-bottom:16px;font-weight:700}
    .chart-box{position:relative;height:280px}

    .table-card{background:#fff;border-radius:14px;padding:20px;box-shadow:0 4px 14px rgba(30,41,59,.08);overflow-x:auto}
    .table-card h3{font-size:15px;color:#334155;margin-bottom:14px;font-weight:700}
    table{width:100%;border-collapse:collapse;min-width:720px}
    thead{background:#f1f5fb}
    th{padding:11px 10px;text-align:left;font-size:11px;color:#475569;font-weight:700;white-space:nowrap}
    td{padding:10px;font-size:12px;border-bottom:1px solid #f1f5f9}
    .badge{padding:3px 8px;border-radius:6px;font-size:10px;font-weight:700}
    .b-activo{background:#dcfce7;color:#166534}.b-inactivo{background:#f3f4f6;color:#4b5563}
    .b-en_prestamo{background:#fef3c7;color:#92400e}
    @media(max-width:600px){.container{padding:18px 12px}.charts{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="navbar">
    <a href="gerente-dashboard.php">← Mis clientes</a>
    <span style="font-size:13px">👤 <?= htmlspecialchars($nombre) ?></span>
</div>

<div class="container">
    <div class="emp-head">
        <h2>🏢 <?= htmlspecialchars($empresa['nombre']) ?></h2>
        <p><?= htmlspecialchars($empresa['domicilio'] ?? '') ?></p>
    </div>

    <div class="stats">
        <div class="stat"><div class="n"><?= $total_extintores ?></div><div class="l">Extintores</div></div>
        <div class="stat ok"><div class="n"><?= $porcentaje ?>%</div><div class="l">Inspeccionados este mes (<?= $inspeccionados_mes ?>)</div></div>
        <div class="stat warn"><div class="n"><?= $proximos ?></div><div class="l">PH por vencer (15 días)</div></div>
        <div class="stat bad"><div class="n"><?= $vencidos ?></div><div class="l">PH vencidos</div></div>
    </div>

    <div class="charts">
        <div class="chart-card"><h3>📈 Inspecciones últimos 6 meses</h3><div class="chart-box"><canvas id="chInsp"></canvas></div></div>
        <div class="chart-card"><h3>🧯 Distribución por tipo</h3><div class="chart-box"><canvas id="chTipo"></canvas></div></div>
        <div class="chart-card"><h3>✅ Estado de vigencia</h3><div class="chart-box"><canvas id="chVig"></canvas></div></div>
        <div class="chart-card"><h3>🛠️ A mantenimiento por mes</h3><div class="chart-box"><canvas id="chMant"></canvas></div>
            <small style="color:#94a3b8;display:block;margin-top:6px">1 año desde recarga o marcados sin presión.</small></div>
    </div>

    <div class="table-card">
        <h3>Extintores (<?= count($extintores) ?>)</h3>
        <table>
            <thead><tr>
                <th>Código</th><th>Sección</th><th>Ubicación</th><th>Tipo</th><th>Cap.</th>
                <th>Recarga</th><th>PH</th><th>Últ. inspección</th><th>Estado</th>
            </tr></thead>
            <tbody>
            <?php if (!$extintores): ?>
                <tr><td colspan="9" style="text-align:center;color:#94a3b8;padding:30px">Sin extintores registrados.</td></tr>
            <?php else: foreach ($extintores as $e): ?>
                <tr>
                    <td style="font-family:monospace;font-weight:600"><?= htmlspecialchars($e['codigo_manual']) ?></td>
                    <td><?= htmlspecialchars($e['seccion'] ?: '—') ?></td>
                    <td><?= htmlspecialchars($e['ubicacion']) ?></td>
                    <td><?= htmlspecialchars($e['tipo_nombre']) ?></td>
                    <td><?= htmlspecialchars($e['capacidad'] ?: '—') ?></td>
                    <td><?= $fmt($e['fecha_recarga']) ?></td>
                    <td><?= $fmt($e['fecha_ph']) ?></td>
                    <td><?= $e['ultima_inspeccion'] ? $fmt($e['ultima_inspeccion']) : '<span style="color:#dc2626">Sin inspección</span>' ?></td>
                    <td><span class="badge b-<?= htmlspecialchars($e['estado']) ?>"><?= htmlspecialchars(ucfirst(str_replace('_',' ',$e['estado']))) ?></span></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
(function(){
if (typeof Chart === 'undefined') {
    document.querySelectorAll('.chart-box').forEach(c => c.innerHTML =
        '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:#94a3b8;font-size:13px;text-align:center;padding:16px">No se pudo cargar la librería de gráficas.<br>Revisa tu conexión y recarga.</div>');
    return;
}

new Chart(document.getElementById('chInsp'), {
    type:'line',
    data:{labels:<?= $json_insp_meses ?>,datasets:[{label:'Inspecciones',data:<?= $json_insp_cant ?>,
        borderColor:'#4338ca',backgroundColor:'rgba(67,56,202,.1)',borderWidth:3,fill:true,tension:.4,pointRadius:4,pointBackgroundColor:'#4338ca'}]},
    options:{responsive:true,maintainAspectRatio:false,scales:{y:{beginAtZero:true,ticks:{precision:0}}}}
});
new Chart(document.getElementById('chTipo'), {
    type:'pie',
    data:{labels:<?= $json_tipos ?>,datasets:[{data:<?= $json_tipos_cant ?>,
        backgroundColor:['#4338ca','#059669','#f59e0b','#dc2626','#0891b2','#7c3aed'],borderColor:'#fff',borderWidth:2}]},
    options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom'}}}
});
new Chart(document.getElementById('chVig'), {
    type:'doughnut',
    data:{labels:['Vigentes','Sin registro','Vencidos'],datasets:[{data:<?= $json_vig ?>,
        backgroundColor:['#059669','#cbd5e1','#dc2626'],borderColor:'#fff',borderWidth:2}]},
    options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom'}}}
});
new Chart(document.getElementById('chMant'), {
    type:'bar',
    data:{labels:<?= $json_mant_meses ?>,datasets:[{label:'A mantenimiento',data:<?= $json_mant_cant ?>,
        backgroundColor:'#f59e0b',borderColor:'#d68910',borderWidth:1,borderRadius:6}]},
    options:{responsive:true,maintainAspectRatio:false,scales:{y:{beginAtZero:true,ticks:{precision:0}}}}
});
})();
</script>
</body>
</html>
