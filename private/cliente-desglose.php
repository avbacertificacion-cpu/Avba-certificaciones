<?php
require_once '../config/config.php';
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== ROLE_CLIENTE) {
    header('Location: ../public/login.html'); exit;
}
$nombre     = $_SESSION['nombre'];
$empresa_id = intval($_SESSION['empresa_id'] ?? 0);

$empresa = null;
$total = 0;
$por_tipo = [];

if ($empresa_id) {
    $st = $pdo->prepare("SELECT id, nombre, domicilio FROM empresas WHERE id=?");
    $st->execute([$empresa_id]);
    $empresa = $st->fetch(PDO::FETCH_ASSOC);

    if ($empresa) {
        // Total (todos los extintores, sin importar el estado)
        $st = $pdo->prepare("SELECT COUNT(*) FROM extintores WHERE empresa_id=?");
        $st->execute([$empresa_id]);
        $total = (int) $st->fetchColumn();

        // Desglose por tipo + capacidad (todos los extintores)
        $st = $pdo->prepare("
            SELECT te.nombre AS tipo,
                   COALESCE(NULLIF(TRIM(e.capacidad),''),'Sin especificar') AS capacidad,
                   COUNT(*) AS cantidad
            FROM extintores e JOIN tipos_extintores te ON te.id = e.tipo
            WHERE e.empresa_id=?
            GROUP BY e.tipo, e.capacidad
            ORDER BY te.nombre ASC, cantidad DESC");
        $st->execute([$empresa_id]);
        $por_tipo = $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

$labels_chart = array_map(fn($t) => $t['tipo'] . ' ' . $t['capacidad'], $por_tipo);
$json_tipos      = json_encode($labels_chart);
$json_tipos_cant = json_encode(array_map('intval', array_column($por_tipo, 'cantidad')));
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Desglose de mis extintores</title>
<script src="../public/assets/js/chart.umd.min.js"></script>
<style>
    *{margin:0;padding:0;box-sizing:border-box}
    body{font-family:'Segoe UI',system-ui,sans-serif;background:#eef2fb;color:#1a2138}
    .navbar{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;padding:16px 26px;
            display:flex;justify-content:space-between;align-items:center;box-shadow:0 4px 16px rgba(102,126,234,.2)}
    .navbar a{color:#fff;text-decoration:none;font-size:13px;opacity:.9}.navbar a:hover{opacity:1;text-decoration:underline}
    .container{max-width:1100px;margin:0 auto;padding:28px 20px}
    h2{font-size:24px;color:#1e293b;margin-bottom:4px}
    .sub{color:#64748b;font-size:13px;margin-bottom:22px}

    .total-card{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;border-radius:14px;
                padding:24px;margin-bottom:24px;display:flex;align-items:center;gap:20px;box-shadow:0 6px 18px rgba(102,126,234,.25)}
    .total-card .big{font-size:46px;font-weight:800;line-height:1}
    .total-card .lbl{font-size:14px;opacity:.95}
    .total-card .emp{font-size:13px;opacity:.85;margin-top:2px}

    .grid{display:grid;grid-template-columns:1fr 1fr;gap:22px}
    @media(max-width:820px){.grid{grid-template-columns:1fr}}
    .card{background:#fff;border-radius:14px;padding:20px;box-shadow:0 4px 14px rgba(30,41,59,.08)}
    .card h3{font-size:15px;color:#334155;margin-bottom:16px;font-weight:700}
    .chart-box{position:relative;height:300px}
    table{width:100%;border-collapse:collapse}
    th,td{padding:9px 10px;font-size:13px;text-align:left;border-bottom:1px solid #eef1f7}
    th{color:#475569;font-size:12px;background:#f7f9fc}
    td.n,th.n{text-align:right;font-weight:700}
    tfoot td{font-weight:800;border-top:2px solid #cbd5e1;background:#f7f9fc}
    .empty{text-align:center;padding:60px 20px;color:#94a3b8}
    .empty .ic{font-size:56px;margin-bottom:12px}
</style>
</head>
<body>
<div class="navbar">
    <a href="cliente-dashboard.php">← Mi panel</a>
    <span style="font-size:13px">👤 <?= htmlspecialchars($nombre) ?></span>
</div>

<div class="container">
    <h2>🧯 Desglose de mis extintores</h2>
    <div class="sub">Resumen del total y desglose por tipo y capacidad de tu planta.</div>

    <?php if (!$empresa): ?>
        <div class="card"><div class="empty"><div class="ic">🏭</div><p>Tu cuenta no tiene una planta asociada. Contacta al administrador.</p></div></div>
    <?php elseif ($total === 0): ?>
        <div class="total-card">
            <div class="big">0</div>
            <div><div class="lbl">Extintores en total</div><div class="emp"><?= htmlspecialchars($empresa['nombre']) ?></div></div>
        </div>
        <div class="card"><div class="empty"><div class="ic">🧯</div><p>Aún no hay extintores registrados en tu planta.</p></div></div>
    <?php else: ?>
        <div class="total-card">
            <div class="big"><?= $total ?></div>
            <div>
                <div class="lbl">Extintores en total</div>
                <div class="emp"><?= htmlspecialchars($empresa['nombre']) ?><?= $empresa['domicilio'] ? ' · '.htmlspecialchars($empresa['domicilio']) : '' ?></div>
            </div>
        </div>

        <div class="grid">
            <div class="card">
                <h3>Distribución por tipo y capacidad</h3>
                <div class="chart-box"><canvas id="chTipo"></canvas></div>
            </div>
            <div class="card">
                <h3>Desglose por tipo y capacidad</h3>
                <table>
                    <thead><tr><th>Tipo</th><th>Capacidad</th><th class="n">Cantidad</th><th class="n">%</th></tr></thead>
                    <tbody>
                    <?php foreach ($por_tipo as $t): $pct = $total>0 ? round($t['cantidad']/$total*100) : 0; ?>
                        <tr>
                            <td><?= htmlspecialchars($t['tipo']) ?></td>
                            <td><?= htmlspecialchars($t['capacidad']) ?></td>
                            <td class="n"><?= (int)$t['cantidad'] ?></td>
                            <td class="n"><?= $pct ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot><tr><td colspan="2">Total</td><td class="n"><?= $total ?></td><td class="n">100%</td></tr></tfoot>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php if ($empresa && $total > 0): ?>
<script>
(function(){
if (typeof Chart === 'undefined') {
    document.querySelector('.chart-box').innerHTML =
        '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:#94a3b8;font-size:13px;text-align:center;padding:16px">No se pudo cargar la librería de gráficas.<br>Revisa tu conexión y recarga.</div>';
    return;
}
new Chart(document.getElementById('chTipo'), {
    type: 'pie',
    data: {
        labels: <?= $json_tipos ?>,
        datasets: [{ data: <?= $json_tipos_cant ?>,
            backgroundColor: ['#667eea','#27ae60','#f39c12','#e74c3c','#3498db','#9b59b6','#1abc9c','#e67e22'],
            borderColor:'#fff', borderWidth:2 }]
    },
    options: { responsive:true, maintainAspectRatio:false, plugins:{legend:{position:'bottom'}} }
});
})();
</script>
<?php endif; ?>
</body>
</html>
