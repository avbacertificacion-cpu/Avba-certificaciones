<?php
require_once '../config/config.php';
require_once '../config/empresa-config.php';
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== ROLE_CLIENTE) {
    header('Location: ../public/login.html'); exit;
}
$nombre     = $_SESSION['nombre'];
$empresa_id = $_SESSION['empresa_id'];

// El admin puede ocultar la sección de alertas/vencimientos para esta empresa
$mostrar_alertas = empresaMuestraAlertas($pdo, $empresa_id);

// ─── MÉTRICAS PRINCIPALES ───────────────────────────────────────────────────
// Total extintores de la empresa
$stmt = $pdo->prepare("SELECT COUNT(*) FROM extintores WHERE empresa_id = ? AND estado != 'inactivo'");
$stmt->execute([$empresa_id]);
$total_extintores = $stmt->fetchColumn();

// Extintores inspeccionados este mes
$mes_actual = date('n');
$anio_actual = date('Y');
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT extintor_id) FROM inspecciones i
    JOIN extintores e ON e.id = i.extintor_id
    WHERE e.empresa_id = ? AND e.estado != 'inactivo'
      AND MONTH(i.fecha) = ? AND YEAR(i.fecha) = ?
");
$stmt->execute([$empresa_id, $mes_actual, $anio_actual]);
$inspeccionados_mes = $stmt->fetchColumn();

$porcentaje_inspecccion = $total_extintores > 0 ? round(($inspeccionados_mes / $total_extintores) * 100) : 0;

// Próximos vencimientos (PH vigencia < 15 días)
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM extintores
    WHERE empresa_id = ? AND estado != 'inactivo'
    AND fecha_ph IS NOT NULL
    AND fecha_ph <= DATE_ADD(CURDATE(), INTERVAL 15 DAY)
    AND fecha_ph > CURDATE()
");
$stmt->execute([$empresa_id]);
$proximos_vencer = $stmt->fetchColumn();

// Ya vencidos (PH)
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM extintores
    WHERE empresa_id = ? AND estado != 'inactivo'
    AND fecha_ph IS NOT NULL
    AND fecha_ph < CURDATE()
");
$stmt->execute([$empresa_id]);
$ya_vencidos = $stmt->fetchColumn();

// Sin inspección en 30 días
$fecha_hace_30 = date('Y-m-d', strtotime('-30 days'));
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT e.id) FROM extintores e
    LEFT JOIN inspecciones i ON e.id = i.extintor_id AND i.fecha >= ?
    WHERE e.empresa_id = ? AND e.estado != 'inactivo'
    AND i.id IS NULL
");
$stmt->execute([$fecha_hace_30, $empresa_id]);
$sin_inspeccion = $stmt->fetchColumn();

// ─── INSPECCIONES ÚLTIMOS 6 MESES ───────────────────────────────────────────
$inspecciones_por_mes = [];
for ($i = 5; $i >= 0; $i--) {
    $fecha = strtotime("-$i months");
    $mes = date('n', $fecha);
    $anio = date('Y', $fecha);
    $mes_nombre = ['','Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'][$mes];

    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT extintor_id) FROM inspecciones i
        JOIN extintores e ON e.id = i.extintor_id
        WHERE e.empresa_id = ? AND e.estado != 'inactivo'
          AND MONTH(i.fecha) = ? AND YEAR(i.fecha) = ?
    ");
    $stmt->execute([$empresa_id, $mes, $anio]);
    $count = $stmt->fetchColumn();

    $inspecciones_por_mes[] = ['mes' => $mes_nombre, 'cantidad' => $count];
}

// ─── REPORTES DISPONIBLES ───────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM reportes_mensuales
    WHERE empresa_id = ? AND estado = 'publicado'
");
$stmt->execute([$empresa_id]);
$total_reportes = $stmt->fetchColumn();

// ─── EXTINTORES CON VENCIMIENTO PRÓXIMO ──────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT codigo_manual, ubicacion, fecha_ph, DATEDIFF(fecha_ph, CURDATE()) as dias_restantes
    FROM extintores
    WHERE empresa_id = ? AND estado != 'inactivo'
    AND fecha_ph IS NOT NULL
    AND fecha_ph <= DATE_ADD(CURDATE(), INTERVAL 15 DAY)
    ORDER BY fecha_ph ASC
    LIMIT 5
");
$stmt->execute([$empresa_id]);
$vencimientos_proximos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// JSON para gráficos
$json_inspecciones = json_encode(array_column($inspecciones_por_mes, 'cantidad'));
$json_meses = json_encode(array_column($inspecciones_por_mes, 'mes'));
$json_pie = json_encode([$inspeccionados_mes, $total_extintores - $inspeccionados_mes]);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard de Inspección – Cliente</title>
    <script src="../public/assets/js/chart.umd.min.js"></script>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Segoe UI',sans-serif;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);min-height:100vh;color:#333}

        /* ─── Navbar ─── */
        .navbar{background:rgba(255,255,255,.1);backdrop-filter:blur(10px);color:#fff;padding:20px 32px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid rgba(255,255,255,.2)}
        .navbar h1{font-size:22px;font-weight:700}
        .navbar-right{display:flex;align-items:center;gap:20px;font-size:14px}
        .logout{background:rgba(255,255,255,.2);color:#fff;border:none;padding:8px 16px;border-radius:6px;cursor:pointer;font-weight:600;transition:.2s}
        .logout:hover{background:rgba(255,255,255,.3)}

        /* ─── Container ─── */
        .container{max-width:1400px;margin:0 auto;padding:32px 20px}

        /* ─── Welcome Section ─── */
        .welcome-section{background:#fff;border-radius:16px;padding:32px;margin-bottom:32px;box-shadow:0 8px 32px rgba(0,0,0,.1)}
        .welcome-section h2{font-size:28px;color:#333;margin-bottom:6px}
        .welcome-section p{font-size:14px;color:#888}

        /* ─── KPI Cards ─── */
        .kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:20px;margin-bottom:32px}
        .kpi-card{background:#fff;border-radius:12px;padding:24px;box-shadow:0 4px 16px rgba(0,0,0,.08);border-left:4px solid;transition:.3s;position:relative;overflow:hidden}
        .kpi-card::before{content:'';position:absolute;top:0;right:0;width:100px;height:100px;background:radial-gradient(circle,rgba(255,255,255,.3) 0%,transparent 70%);border-radius:50%}
        .kpi-card.success{border-left-color:#27ae60;background:linear-gradient(135deg,#fff 0%,#f0fdf4 100%)}
        .kpi-card.warning{border-left-color:#f39c12;background:linear-gradient(135deg,#fff 0%,#fffbf0 100%)}
        .kpi-card.danger{border-left-color:#e74c3c;background:linear-gradient(135deg,#fff 0%,#fdf8f7 100%)}
        .kpi-card.info{border-left-color:#3498db;background:linear-gradient(135deg,#fff 0%,#f0f9ff 100%)}

        .kpi-card:hover{transform:translateY(-4px);box-shadow:0 8px 24px rgba(0,0,0,.12)}
        .kpi-label{font-size:12px;color:#888;text-transform:uppercase;font-weight:700;letter-spacing:0.5px;margin-bottom:8px}
        .kpi-value{font-size:36px;font-weight:800;margin:8px 0;position:relative;z-index:1}
        .kpi-value.success{color:#27ae60}
        .kpi-value.warning{color:#f39c12}
        .kpi-value.danger{color:#e74c3c}
        .kpi-value.info{color:#3498db}
        .kpi-subtext{font-size:12px;color:#888;margin-top:6px}

        /* ─── Charts Section ─── */
        .charts-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(350px,1fr));gap:24px;margin-bottom:32px}
        .chart-card{background:#fff;border-radius:12px;padding:24px;box-shadow:0 4px 16px rgba(0,0,0,.08)}
        .chart-card h3{font-size:16px;color:#333;margin-bottom:20px;font-weight:700}
        .chart-container{position:relative;height:300px;margin-bottom:12px}

        /* ─── Alerts Section ─── */
        .alerts-section{margin-bottom:32px}
        .alerts-title{font-size:18px;font-weight:700;color:#fff;margin-bottom:16px}
        .alert{background:#fff;border-radius:12px;padding:16px;margin-bottom:12px;display:flex;align-items:center;gap:16px;box-shadow:0 4px 16px rgba(0,0,0,.08);border-left:4px solid;animation:slideIn .3s ease-out}
        .alert.danger{border-left-color:#e74c3c;background:linear-gradient(135deg,#fff 0%,#fdf8f7 100%)}
        .alert.warning{border-left-color:#f39c12;background:linear-gradient(135deg,#fff 0%,#fffbf0 100%)}
        .alert-icon{font-size:24px}
        .alert-content{flex:1}
        .alert-title{font-size:14px;font-weight:700;color:#333;margin-bottom:2px}
        .alert-text{font-size:12px;color:#888}

        @keyframes slideIn{from{opacity:0;transform:translateX(-20px)}to{opacity:1;transform:translateX(0)}}

        /* ─── Empty State ─── */
        .empty-state{text-align:center;padding:40px;color:#aaa}
        .empty-state p{font-size:14px;margin-top:8px}

        /* ─── Menu Items ─── */
        .menu-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px}
        .menu-item{background:#fff;border-radius:12px;padding:24px;text-align:center;cursor:pointer;transition:.2s;box-shadow:0 4px 16px rgba(0,0,0,.08)}
        .menu-item:hover{transform:translateY(-4px);box-shadow:0 8px 24px rgba(0,0,0,.12)}
        .menu-item-icon{font-size:40px;margin-bottom:12px}
        .menu-item h3{color:#333;font-size:15px;margin-bottom:8px;font-weight:700}
        .menu-item p{color:#888;font-size:12px}

        /* ─── Responsive ─── */
        @media (max-width:768px){
            .kpi-grid{grid-template-columns:1fr}
            .charts-grid{grid-template-columns:1fr}
            .container{padding:16px}
            .navbar{padding:16px}
            .navbar h1{font-size:18px}
            .welcome-section{padding:20px}
            .welcome-section h2{font-size:22px}
        }
    </style>
</head>
<body>

<!-- Navbar -->
<div class="navbar">
    <h1>🧯 Dashboard de Inspección</h1>
    <div class="navbar-right">
        <span><?= htmlspecialchars($nombre) ?></span>
        <button class="logout" onclick="logout()">Cerrar sesión</button>
    </div>
</div>

<div class="container">
    <!-- Welcome -->
    <div class="welcome-section">
        <h2>¡Bienvenido! 👋</h2>
        <p>Estado actual de inspección de extintores de tu planta</p>
    </div>

    <!-- KPI Cards -->
    <div class="kpi-grid">
        <div class="kpi-card success">
            <div class="kpi-label">Total de Extintores</div>
            <div class="kpi-value success"><?= $total_extintores ?></div>
            <div class="kpi-subtext">Equipos en tu planta</div>
        </div>

        <div class="kpi-card info">
            <div class="kpi-label">Inspeccionados Este Mes</div>
            <div class="kpi-value info"><?= $inspeccionados_mes ?></div>
            <div class="kpi-subtext">De <?= $total_extintores ?> total</div>
        </div>

        <div class="kpi-card <?= $porcentaje_inspecccion >= 80 ? 'success' : ($porcentaje_inspecccion >= 50 ? 'warning' : 'danger') ?>">
            <div class="kpi-label">Progreso de Inspección</div>
            <div class="kpi-value <?= $porcentaje_inspecccion >= 80 ? 'success' : ($porcentaje_inspecccion >= 50 ? 'warning' : 'danger') ?>"><?= $porcentaje_inspecccion ?>%</div>
            <div class="kpi-subtext">Completado este mes</div>
        </div>

        <?php if ($mostrar_alertas): ?>
        <div class="kpi-card <?= $ya_vencidos > 0 ? 'danger' : 'success' ?>">
            <div class="kpi-label">⚠️ Vencimientos</div>
            <div class="kpi-value <?= $ya_vencidos > 0 ? 'danger' : 'success' ?>"><?= $ya_vencidos + $proximos_vencer ?></div>
            <div class="kpi-subtext"><?= $ya_vencidos ?> vencidos, <?= $proximos_vencer ?> próximos</div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Charts -->
    <div class="charts-grid">
        <!-- Pie Chart - Inspección -->
        <div class="chart-card">
            <h3>📊 Inspecciones Este Mes</h3>
            <div class="chart-container">
                <canvas id="pieChart"></canvas>
            </div>
            <p style="font-size:12px;color:#888;text-align:center">
                <?= $inspeccionados_mes ?> de <?= $total_extintores ?> equipos inspeccionados
            </p>
        </div>

        <!-- Bar Chart - Tendencia -->
        <div class="chart-card">
            <h3>📈 Tendencia de Inspecciones (6 meses)</h3>
            <div class="chart-container">
                <canvas id="barChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Alerts Section -->
    <?php if ($mostrar_alertas && ($ya_vencidos > 0 || $proximos_vencer > 0 || $sin_inspeccion > 0)): ?>
    <div class="alerts-section">
        <div class="alerts-title">🚨 Alertas y Vencimientos</div>

        <?php if ($ya_vencidos > 0): ?>
        <div class="alert danger">
            <div class="alert-icon">⛔</div>
            <div class="alert-content">
                <div class="alert-title"><?= $ya_vencidos ?> Extintor(es) con Vigencia Vencida</div>
                <div class="alert-text">La prueba hidrostática ya pasó. Requieren renovación inmediata.</div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($proximos_vencer > 0): ?>
        <div class="alert warning">
            <div class="alert-icon">⚠️</div>
            <div class="alert-content">
                <div class="alert-title"><?= $proximos_vencer ?> Extintor(es) Próximos a Vencer (< 15 días)</div>
                <div class="alert-text">Agenda la renovación pronto para evitar interrupciones.</div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($sin_inspeccion > 0): ?>
        <div class="alert warning">
            <div class="alert-icon">📋</div>
            <div class="alert-content">
                <div class="alert-title"><?= $sin_inspeccion ?> Extintor(es) sin Inspección Reciente</div>
                <div class="alert-text">No hay inspecciones registradas en los últimos 30 días.</div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Próximos Vencimientos (Tabla) -->
    <?php if ($mostrar_alertas && !empty($vencimientos_proximos)): ?>
    <div class="chart-card" style="margin-bottom:32px">
        <h3>📅 Próximos Vencimientos (Vigencia PH)</h3>
        <div style="overflow-x:auto">
            <table style="width:100%;font-size:13px;border-collapse:collapse">
                <thead>
                    <tr style="background:#f9fafb;border-bottom:2px solid #e5e7eb">
                        <th style="padding:12px;text-align:left;font-weight:700">Equipo</th>
                        <th style="padding:12px;text-align:left;font-weight:700">Ubicación</th>
                        <th style="padding:12px;text-align:center;font-weight:700">Vencimiento</th>
                        <th style="padding:12px;text-align:center;font-weight:700">Días</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($vencimientos_proximos as $v):
                        $dias = intval($v['dias_restantes']);
                        $color = $dias < 0 ? '#e74c3c' : ($dias < 7 ? '#f39c12' : '#3498db');
                    ?>
                    <tr style="border-bottom:1px solid #e5e7eb;hover:background:#f9fafb">
                        <td style="padding:12px;color:#333"><?= htmlspecialchars($v['codigo_manual']) ?></td>
                        <td style="padding:12px;color:#666"><?= htmlspecialchars($v['ubicacion']) ?></td>
                        <td style="padding:12px;text-align:center;color:#333"><?= date('d/m/Y', strtotime($v['fecha_ph'])) ?></td>
                        <td style="padding:12px;text-align:center"><span style="background:<?= $color ?>;color:#fff;padding:4px 10px;border-radius:6px;font-weight:700;font-size:12px"><?= $dias < 0 ? 'VENCIDO' : "$dias días" ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Menu Items -->
    <div style="margin-top:32px">
        <h3 style="color:#fff;margin-bottom:16px;font-size:18px">Acciones Rápidas</h3>
        <div class="menu-grid">
            <div class="menu-item" onclick="window.location.href='cliente-reportes.php'">
                <div class="menu-item-icon">📋</div>
                <h3>Mis Reportes</h3>
                <p><?= $total_reportes ?> reportes disponibles</p>
            </div>
            <div class="menu-item" onclick="window.location.href='cliente-extintores.php'">
                <div class="menu-item-icon">🧯</div>
                <h3>Ver Extintores</h3>
                <p><?= $total_extintores ?> equipos registrados</p>
            </div>
            <div class="menu-item" onclick="window.location.href='cliente-desglose.php'">
                <div class="menu-item-icon">📦</div>
                <h3>Desglose de Extintores</h3>
                <p>Total y por tipo/capacidad</p>
            </div>
        </div>
    </div>
</div>

<script>
(function(){
// Si la librería de gráficas no cargó, mostrar aviso en vez de recuadros vacíos
if (typeof Chart === 'undefined') {
    document.querySelectorAll('.chart-container').forEach(function(c){
        c.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:#999;font-size:13px;text-align:center;padding:16px">No se pudo cargar la librería de gráficas.<br>Revisa tu conexión y recarga la página.</div>';
    });
    return;
}

// Pie Chart
const pieCtx = document.getElementById('pieChart').getContext('2d');
new Chart(pieCtx, {
    type: 'doughnut',
    data: {
        labels: ['Inspeccionados', 'Pendientes'],
        datasets: [{
            data: <?= $json_pie ?>,
            backgroundColor: ['#27ae60', '#e0e0e0'],
            borderColor: '#fff',
            borderWidth: 2
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {position: 'bottom', labels: {font: {size: 12}}}
        }
    }
});

// Bar Chart
const barCtx = document.getElementById('barChart').getContext('2d');
new Chart(barCtx, {
    type: 'bar',
    data: {
        labels: <?= $json_meses ?>,
        datasets: [{
            label: 'Inspecciones',
            data: <?= $json_inspecciones ?>,
            backgroundColor: '#667eea',
            borderColor: '#5568d3',
            borderWidth: 1,
            borderRadius: 6
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {legend: {display: true}},
        scales: {
            y: {beginAtZero: true, max: Math.max(...<?= $json_inspecciones ?>)+3}
        }
    }
});
})();

function logout() {
    fetch('../api/auth.php?action=logout', {method:'POST'})
        .then(() => window.location.href = '../public/login.html');
}
</script>

</body>
</html>
