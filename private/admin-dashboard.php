<?php
require_once '../config/config.php';
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== ROLE_ADMIN) {
    header('Location: ../public/login.html'); exit;
}
$nombre = $_SESSION['nombre'];

// ─── MÉTRICAS PRINCIPALES ───────────────────────────────────────────────────
// Total de empresas
$stmt = $pdo->query("SELECT COUNT(*) FROM empresas WHERE estado='activo'");
$total_empresas = $stmt->fetchColumn();

// Total de extintores
$stmt = $pdo->query("SELECT COUNT(*) FROM extintores WHERE estado != 'inactivo'");
$total_extintores = $stmt->fetchColumn();

// Total de usuarios
$stmt = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE estado='activo'");
$total_usuarios = $stmt->fetchColumn();

// Extintores inspeccionados este mes.
// Se cuentan extintores distintos y sólo los que siguen activos, para que
// coincida con el total: si no, una segunda inspección del mismo extintor —o
// la de uno dado de baja— hacía que el porcentaje pasara del 100%.
$mes_actual = date('n');
$anio_actual = date('Y');
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT i.extintor_id) FROM inspecciones i
    JOIN extintores e ON e.id = i.extintor_id
    WHERE e.estado != 'inactivo' AND MONTH(i.fecha)=? AND YEAR(i.fecha)=?
");
$stmt->execute([$mes_actual, $anio_actual]);
$inspecciones_mes = $stmt->fetchColumn();

// Extintores sin inspeccionar
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT e.id) FROM extintores e
    LEFT JOIN inspecciones i ON e.id = i.extintor_id AND MONTH(i.fecha)=? AND YEAR(i.fecha)=?
    WHERE e.estado != 'inactivo' AND i.id IS NULL
");
$stmt->execute([$mes_actual, $anio_actual]);
$sin_inspeccionar = $stmt->fetchColumn();

// Extintores con vigencia próxima a vencer
$stmt = $pdo->query("
    SELECT COUNT(*) FROM extintores
    WHERE estado != 'inactivo' AND fecha_ph IS NOT NULL
    AND fecha_ph <= DATE_ADD(CURDATE(), INTERVAL 15 DAY)
    AND fecha_ph > CURDATE()
");
$proximos_vencer = $stmt->fetchColumn();

// Extintores vencidos
$stmt = $pdo->query("
    SELECT COUNT(*) FROM extintores
    WHERE estado != 'inactivo' AND fecha_ph IS NOT NULL AND fecha_ph < CURDATE()
");
$vencidos = $stmt->fetchColumn();

// Reportes publicados este mes
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM reportes_mensuales
    WHERE estado='publicado' AND MONTH(created_at)=? AND YEAR(created_at)=?
");
$stmt->execute([$mes_actual, $anio_actual]);
$reportes_mes = $stmt->fetchColumn();

// ─── INSPECCIONES POR MES (ÚLTIMOS 6 MESES) ─────────────────────────────────
$inspecciones_por_mes = [];
for ($i = 5; $i >= 0; $i--) {
    $fecha = strtotime("-$i months");
    $mes = date('n', $fecha);
    $anio = date('Y', $fecha);
    $mes_nombre = ['','Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'][$mes];

    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT i.extintor_id) FROM inspecciones i
        JOIN extintores e ON e.id = i.extintor_id
        WHERE e.estado != 'inactivo' AND MONTH(i.fecha)=? AND YEAR(i.fecha)=?
    ");
    $stmt->execute([$mes, $anio]);
    $count = $stmt->fetchColumn();

    $inspecciones_por_mes[] = ['mes' => $mes_nombre, 'cantidad' => $count];
}

// ─── EXTINTORES A MANTENIMIENTO POR MES (ÚLTIMOS 6 MESES) ───────────────────
// Un extintor cuenta como "mantenimiento" en un mes si:
//   (1) cumple 1 año desde su recarga en ese mes (fecha_recarga + 1 año), o
//   (2) en la inspección de ese mes se marcó SIN PRESIÓN (ps = 'NC').
// El UNION evita contar dos veces al mismo extintor en el mismo mes.
$mantenimiento_por_mes = [];
for ($i = 5; $i >= 0; $i--) {
    $fecha = strtotime("-$i months");
    $mes = date('n', $fecha);
    $anio = date('Y', $fecha);
    $mes_nombre = ['','Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'][$mes];

    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM (
            SELECT i.extintor_id AS eid
            FROM inspecciones i
            JOIN extintores e ON e.id = i.extintor_id
            WHERE e.estado != 'inactivo' AND i.ps = 'NC'
              AND MONTH(i.fecha) = ? AND YEAR(i.fecha) = ?
            UNION
            SELECT e.id AS eid
            FROM extintores e
            WHERE e.estado != 'inactivo' AND e.fecha_recarga IS NOT NULL
              AND MONTH(DATE_ADD(e.fecha_recarga, INTERVAL 1 YEAR)) = ?
              AND YEAR(DATE_ADD(e.fecha_recarga, INTERVAL 1 YEAR)) = ?
        ) AS t
    ");
    $stmt->execute([$mes, $anio, $mes, $anio]);
    $count = $stmt->fetchColumn();

    $mantenimiento_por_mes[] = ['mes' => $mes_nombre, 'cantidad' => (int)$count];
}

// ─── EXTINTORES POR TIPO ────────────────────────────────────────────────────
$stmt = $pdo->query("
    SELECT te.nombre as tipo, COUNT(*) as cantidad FROM extintores e
    JOIN tipos_extintores te ON te.id = e.tipo
    WHERE e.estado != 'inactivo'
    GROUP BY e.tipo
    ORDER BY cantidad DESC
");
$extintores_por_tipo = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ─── ESTADO DE VIGENCIA ─────────────────────────────────────────────────────
$stmt = $pdo->query("
    SELECT
        SUM(CASE WHEN fecha_ph > CURDATE() THEN 1 ELSE 0 END) as vigentes,
        SUM(CASE WHEN fecha_ph IS NULL THEN 1 ELSE 0 END) as sin_registro,
        SUM(CASE WHEN fecha_ph < CURDATE() THEN 1 ELSE 0 END) as vencidos
    FROM extintores WHERE estado != 'inactivo'
");
$vigencia = $stmt->fetch(PDO::FETCH_ASSOC);

// ─── TOP EMPRESAS CON MÁS INSPECCIONES ─────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT emp.nombre, COUNT(i.id) as inspecciones
    FROM empresas emp
    LEFT JOIN extintores e ON e.empresa_id = emp.id
    LEFT JOIN inspecciones i ON i.extintor_id = e.id
        AND MONTH(i.fecha)=? AND YEAR(i.fecha)=?
    WHERE emp.estado='activo'
    GROUP BY emp.id
    ORDER BY inspecciones DESC
    LIMIT 5
");
$stmt->execute([$mes_actual, $anio_actual]);
$empresas_top = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ─── JSON PARA GRÁFICOS ────────────────────────────────────────────────────
$json_inspecciones_mes = json_encode(array_column($inspecciones_por_mes, 'cantidad'));
$json_meses = json_encode(array_column($inspecciones_por_mes, 'mes'));
$json_tipos = json_encode(array_column($extintores_por_tipo, 'tipo'));
$json_tipos_cant = json_encode(array_column($extintores_por_tipo, 'cantidad'));
$json_vigencia = json_encode([
    $vigencia['vigentes'] ?? 0,
    $vigencia['sin_registro'] ?? 0,
    $vigencia['vencidos'] ?? 0
]);
$json_empresas = json_encode(array_column($empresas_top, 'nombre'));
$json_empresas_inspecciones = json_encode(array_column($empresas_top, 'inspecciones'));
$json_mant_meses = json_encode(array_column($mantenimiento_por_mes, 'mes'));
$json_mant_cant  = json_encode(array_column($mantenimiento_por_mes, 'cantidad'));

$porcentaje_inspecccion = $total_extintores > 0 ? round(($inspecciones_mes / $total_extintores) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard de Administración</title>
    <script src="../public/assets/js/chart.umd.min.js"></script>
    <link rel="stylesheet" href="../public/assets/css/movil.css">
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
        .container{max-width:1600px;margin:0 auto;padding:32px 20px}

        /* ─── Welcome Section ─── */
        .welcome-section{background:#fff;border-radius:16px;padding:32px;margin-bottom:32px;box-shadow:0 8px 32px rgba(0,0,0,.1)}
        .welcome-section h2{font-size:28px;color:#333;margin-bottom:6px}
        .welcome-section p{font-size:14px;color:#888}

        /* ─── KPI Cards ─── */
        .kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:32px}
        .kpi-card{background:#fff;border-radius:12px;padding:24px;box-shadow:0 4px 16px rgba(0,0,0,.08);border-left:4px solid;transition:.3s;position:relative;overflow:hidden}
        .kpi-card::before{content:'';position:absolute;top:0;right:0;width:80px;height:80px;background:radial-gradient(circle,rgba(255,255,255,.3) 0%,transparent 70%);border-radius:50%}
        .kpi-card.success{border-left-color:#27ae60;background:linear-gradient(135deg,#fff 0%,#f0fdf4 100%)}
        .kpi-card.warning{border-left-color:#f39c12;background:linear-gradient(135deg,#fff 0%,#fffbf0 100%)}
        .kpi-card.danger{border-left-color:#e74c3c;background:linear-gradient(135deg,#fff 0%,#fdf8f7 100%)}
        .kpi-card.info{border-left-color:#3498db;background:linear-gradient(135deg,#fff 0%,#f0f9ff 100%)}
        .kpi-card:hover{transform:translateY(-4px);box-shadow:0 8px 24px rgba(0,0,0,.12)}
        .kpi-label{font-size:11px;color:#888;text-transform:uppercase;font-weight:700;letter-spacing:0.5px;margin-bottom:8px;position:relative;z-index:1}
        .kpi-value{font-size:32px;font-weight:800;margin:6px 0;position:relative;z-index:1}
        .kpi-value.success{color:#27ae60}
        .kpi-value.warning{color:#f39c12}
        .kpi-value.danger{color:#e74c3c}
        .kpi-value.info{color:#3498db}
        .kpi-subtext{font-size:12px;color:#888;margin-top:6px;position:relative;z-index:1}

        /* ─── Charts Section ─── */
        .charts-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(350px,1fr));gap:24px;margin-bottom:32px}
        .chart-card{background:#fff;border-radius:12px;padding:24px;box-shadow:0 4px 16px rgba(0,0,0,.08)}
        .chart-card h3{font-size:16px;color:#333;margin-bottom:20px;font-weight:700}
        .chart-container{position:relative;height:300px;margin-bottom:12px}

        /* ─── Table Section ─── */
        .table-card{background:#fff;border-radius:12px;padding:24px;box-shadow:0 4px 16px rgba(0,0,0,.08);margin-bottom:32px}
        .table-card h3{font-size:16px;color:#333;margin-bottom:20px;font-weight:700}
        table{width:100%;border-collapse:collapse;font-size:13px}
        th{background:#f9fafb;border-bottom:2px solid #e5e7eb;padding:12px;text-align:left;font-weight:700;color:#444}
        td{padding:12px;border-bottom:1px solid #e5e7eb}
        tr:hover{background:#fafbfc}
        .badge{display:inline-block;padding:4px 10px;border-radius:6px;font-size:11px;font-weight:700}
        .badge-success{background:#d4edda;color:#155724}
        .badge-warning{background:#fff3cd;color:#856404}
        .badge-danger{background:#f8d7da;color:#721c24}

        /* ─── Menu Items ─── */
        .menu-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-top:32px}
        .menu-item{background:#fff;border-radius:12px;padding:24px;text-align:center;cursor:pointer;transition:.2s;box-shadow:0 4px 16px rgba(0,0,0,.08)}
        .menu-item:hover{transform:translateY(-4px);box-shadow:0 8px 24px rgba(0,0,0,.12)}
        .menu-item-icon{font-size:40px;margin-bottom:12px}
        .menu-item h3{color:#333;font-size:14px;margin-bottom:6px;font-weight:700}
        .menu-item p{color:#888;font-size:12px}

        /* ─── Responsive ─── */
        @media (max-width:768px){
            .kpi-grid{grid-template-columns:repeat(2,1fr)}
            .charts-grid{grid-template-columns:1fr}
            .container{padding:16px}
        }
    </style>
</head>
<body>

<!-- Navbar -->
<div class="navbar">
    <h1>📊 Dashboard de Administración</h1>
    <div class="navbar-right">
        <span><?= htmlspecialchars($nombre) ?></span>
        <button class="logout" onclick="logout()">Cerrar sesión</button>
    </div>
</div>

<div class="container">
    <!-- Welcome -->
    <div class="welcome-section">
        <h2>¡Bienvenido, Admin! 👋</h2>
        <p>Estado general del sistema de gestión de extintores</p>
    </div>

    <!-- KPI Cards -->
    <div class="kpi-grid">
        <div class="kpi-card info">
            <div class="kpi-label">Empresas Activas</div>
            <div class="kpi-value info"><?= $total_empresas ?></div>
            <div class="kpi-subtext">Bajo administración</div>
        </div>

        <div class="kpi-card success">
            <div class="kpi-label">Total de Extintores</div>
            <div class="kpi-value success"><?= $total_extintores ?></div>
            <div class="kpi-subtext">Equipos activos</div>
        </div>

        <div class="kpi-card info">
            <div class="kpi-label">Usuarios Sistema</div>
            <div class="kpi-value info"><?= $total_usuarios ?></div>
            <div class="kpi-subtext">Admin, inspectores, clientes</div>
        </div>

        <div class="kpi-card <?= $porcentaje_inspecccion >= 80 ? 'success' : ($porcentaje_inspecccion >= 50 ? 'warning' : 'danger') ?>">
            <div class="kpi-label">Progreso Inspección Mes</div>
            <div class="kpi-value <?= $porcentaje_inspecccion >= 80 ? 'success' : ($porcentaje_inspecccion >= 50 ? 'warning' : 'danger') ?>"><?= $porcentaje_inspecccion ?>%</div>
            <div class="kpi-subtext"><?= $inspecciones_mes ?>/<?= $total_extintores ?> inspeccionados</div>
        </div>

        <div class="kpi-card warning">
            <div class="kpi-label">⚠️ Sin Inspeccionar</div>
            <div class="kpi-value warning"><?= $sin_inspeccionar ?></div>
            <div class="kpi-subtext">Este mes</div>
        </div>

        <div class="kpi-card <?= $vencidos > 0 ? 'danger' : 'success' ?>">
            <div class="kpi-label">🚨 Vencimientos</div>
            <div class="kpi-value <?= $vencidos > 0 ? 'danger' : 'success' ?>"><?= $vencidos + $proximos_vencer ?></div>
            <div class="kpi-subtext"><?= $vencidos ?> vencidos, <?= $proximos_vencer ?> próximos</div>
        </div>

        <div class="kpi-card info">
            <div class="kpi-label">Reportes Publicados</div>
            <div class="kpi-value info"><?= $reportes_mes ?></div>
            <div class="kpi-subtext">Este mes</div>
        </div>

        <div class="kpi-card success">
            <div class="kpi-label">Tasa de Cumplimiento</div>
            <div class="kpi-value success"><?= round(($total_extintores - $vencidos) / $total_extintores * 100) ?>%</div>
            <div class="kpi-subtext">Equipos con vigencia</div>
        </div>
    </div>

    <!-- Charts -->
    <div class="charts-grid">
        <!-- Line Chart - Inspecciones Mes -->
        <div class="chart-card">
            <h3>📈 Inspecciones Últimos 6 Meses</h3>
            <div class="chart-container">
                <canvas id="lineChart"></canvas>
            </div>
        </div>

        <!-- Pie Chart - Tipos de Extintor -->
        <div class="chart-card">
            <h3>🧯 Distribución por Tipo</h3>
            <div class="chart-container">
                <canvas id="pieChart"></canvas>
            </div>
        </div>

        <!-- Doughnut Chart - Vigencia -->
        <div class="chart-card">
            <h3>✅ Estado de Vigencia</h3>
            <div class="chart-container">
                <canvas id="doughnutChart"></canvas>
            </div>
        </div>

        <!-- Bar Chart - Empresas Top -->
        <div class="chart-card">
            <h3>🏆 Empresas Más Inspeccionadas</h3>
            <div class="chart-container">
                <canvas id="barChart"></canvas>
            </div>
        </div>

        <!-- Bar Chart - Mantenimiento por mes -->
        <div class="chart-card">
            <h3>🛠️ Extintores a Mantenimiento por Mes</h3>
            <div class="chart-container">
                <canvas id="mantChart"></canvas>
            </div>
            <small style="color:#888;display:block;margin-top:8px">1 año desde la recarga o marcados sin presión en la inspección.</small>
        </div>
    </div>

    <!-- Tables -->
    <div class="table-card">
        <h3>📋 Top 5 Empresas por Inspecciones (Este Mes)</h3>
        <table>
            <thead>
                <tr>
                    <th>Empresa</th>
                    <th>Inspecciones Realizadas</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($empresas_top as $emp):
                    $estado = $emp['inspecciones'] > 5 ? 'success' : ($emp['inspecciones'] > 2 ? 'warning' : 'danger');
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($emp['nombre']) ?></strong></td>
                    <td><?= $emp['inspecciones'] ?> inspecciones</td>
                    <td><span class="badge badge-<?= $estado ?>"><?= $emp['inspecciones'] > 5 ? '✅ Bueno' : ($emp['inspecciones'] > 2 ? '⚠️ Regular' : '❌ Bajo') ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Menu Items -->
    <div style="margin-top:32px">
        <h3 style="color:#fff;margin-bottom:16px;font-size:18px">Gestión Rápida</h3>
        <div class="menu-grid">
            <div class="menu-item" onclick="window.location.href='extintores.php'">
                <div class="menu-item-icon">🧯</div>
                <h3>Gestionar Extintores</h3>
                <p><?= $total_extintores ?> equipos</p>
            </div>
            <div class="menu-item" onclick="window.location.href='admin-usuarios.php'">
                <div class="menu-item-icon">👥</div>
                <h3>Gestionar Usuarios</h3>
                <p><?= $total_usuarios ?> usuarios</p>
            </div>
            <div class="menu-item" onclick="window.location.href='admin-empresas.php'">
                <div class="menu-item-icon">🏢</div>
                <h3>Gestionar Empresas</h3>
                <p><?= $total_empresas ?> empresas</p>
            </div>
            <div class="menu-item" onclick="window.location.href='admin-desglose.php'">
                <div class="menu-item-icon">📦</div>
                <h3>Desglose por Planta</h3>
                <p>Extintores por tipo</p>
            </div>
            <div class="menu-item" onclick="window.location.href='admin-tipos.php'">
                <div class="menu-item-icon">🧪</div>
                <h3>Tipos de Extintores</h3>
                <p>Configurar tipos</p>
            </div>
            <div class="menu-item" onclick="window.location.href='admin-reportes.php'">
                <div class="menu-item-icon">📊</div>
                <h3>Reportes Mensuales</h3>
                <p><?= $reportes_mes ?> este mes</p>
            </div>
            <div class="menu-item" onclick="window.location.href='inspeccion.php'">
                <div class="menu-item-icon">🔍</div>
                <h3>Nueva Inspección</h3>
                <p><?= $sin_inspeccionar ?> pendientes</p>
            </div>
            <div class="menu-item" onclick="window.location.href='admin-inspecciones.php'">
                <div class="menu-item-icon">📋</div>
                <h3>Registro de Inspecciones</h3>
                <p><?= $inspecciones_mes ?> este mes</p>
            </div>
            <div class="menu-item" onclick="window.location.href='admin-generar-etiquetas.php'">
                <div class="menu-item-icon">🏷️</div>
                <h3>Generar Etiquetas QR</h3>
                <p>Imprimir etiquetas consecutivas</p>
            </div>
            <div class="menu-item" onclick="window.location.href='admin-ordenes.php'">
                <div class="menu-item-icon">🛒</div>
                <h3>Órdenes de Compra</h3>
                <p>Ítems, fotos y fechas de instalación</p>
            </div>
            <div class="menu-item" onclick="window.location.href='admin-cotizaciones.php'">
                <div class="menu-item-icon">💰</div>
                <h3>Cotizaciones</h3>
                <p>Precios de venta y utilidad</p>
            </div>
            <div class="menu-item" onclick="window.location.href='admin-proveedores.php'">
                <div class="menu-item-icon">🏭</div>
                <h3>Proveedores y Precios</h3>
                <p>A quién le compras y a qué costo</p>
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

// Line Chart
const lineCtx = document.getElementById('lineChart').getContext('2d');
new Chart(lineCtx, {
    type: 'line',
    data: {
        labels: <?= $json_meses ?>,
        datasets: [{
            label: 'Inspecciones',
            data: <?= $json_inspecciones_mes ?>,
            borderColor: '#667eea',
            backgroundColor: 'rgba(102, 126, 234, 0.1)',
            borderWidth: 3,
            fill: true,
            tension: 0.4,
            pointRadius: 5,
            pointBackgroundColor: '#667eea',
            pointBorderColor: '#fff',
            pointBorderWidth: 2
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {legend: {display: true}},
        scales: {y: {beginAtZero: true}}
    }
});

// Pie Chart - Tipos
const pieCtx = document.getElementById('pieChart').getContext('2d');
new Chart(pieCtx, {
    type: 'pie',
    data: {
        labels: <?= $json_tipos ?>,
        datasets: [{
            data: <?= $json_tipos_cant ?>,
            backgroundColor: ['#667eea', '#27ae60', '#f39c12', '#e74c3c', '#3498db', '#9b59b6'],
            borderColor: '#fff',
            borderWidth: 2
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {legend: {position: 'bottom'}}
    }
});

// Doughnut Chart - Vigencia
const doughnutCtx = document.getElementById('doughnutChart').getContext('2d');
new Chart(doughnutCtx, {
    type: 'doughnut',
    data: {
        labels: ['Vigentes', 'Sin Registro', 'Vencidos'],
        datasets: [{
            data: <?= $json_vigencia ?>,
            backgroundColor: ['#27ae60', '#bdc3c7', '#e74c3c'],
            borderColor: '#fff',
            borderWidth: 2
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {legend: {position: 'bottom'}}
    }
});

// Bar Chart - Empresas
const barCtx = document.getElementById('barChart').getContext('2d');
new Chart(barCtx, {
    type: 'bar',
    data: {
        labels: <?= $json_empresas ?>,
        datasets: [{
            label: 'Inspecciones',
            data: <?= $json_empresas_inspecciones ?>,
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
        scales: {y: {beginAtZero: true}}
    }
});

// Bar Chart - Extintores a mantenimiento por mes
const mantCtx = document.getElementById('mantChart').getContext('2d');
new Chart(mantCtx, {
    type: 'bar',
    data: {
        labels: <?= $json_mant_meses ?>,
        datasets: [{
            label: 'A mantenimiento',
            data: <?= $json_mant_cant ?>,
            backgroundColor: '#f39c12',
            borderColor: '#d68910',
            borderWidth: 1,
            borderRadius: 6
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {legend: {display: true}},
        scales: {y: {beginAtZero: true, ticks: {precision: 0}}}
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
