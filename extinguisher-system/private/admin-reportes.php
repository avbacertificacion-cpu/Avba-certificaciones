<?php
require_once '../config/config.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== ROLE_ADMIN) {
    header('Location: ../public/login.html');
    exit;
}

$nombre_usuario = $_SESSION['nombre'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Revisar Reportes - Gestión de Extintores</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
        }

        .navbar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px 40px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .navbar a {
            color: white;
            text-decoration: none;
            margin-right: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 40px auto;
            padding: 0 20px;
        }

        .filters {
            background: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .filter-row {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .filter-group {
            flex: 1;
            min-width: 150px;
        }

        .filter-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            font-size: 12px;
            color: #666;
        }

        .filter-group select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 13px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        thead {
            background: #667eea;
            color: white;
        }

        th {
            padding: 15px;
            text-align: left;
            font-weight: 600;
        }

        td {
            padding: 15px;
            border-bottom: 1px solid #eee;
        }

        tr:hover {
            background: #f9f9f9;
        }

        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-borrador {
            background: #e0e0e0;
            color: #666;
        }

        .status-pendiente {
            background: #fff3cd;
            color: #856404;
        }

        .status-aprobado {
            background: #d4edda;
            color: #155724;
        }

        .status-rechazado {
            background: #f8d7da;
            color: #721c24;
        }

        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            transition: all 0.3s;
        }

        .btn-primary {
            background: #667eea;
            color: white;
        }

        .btn-primary:hover {
            background: #5568d3;
        }

        .btn-success {
            background: #28a745;
            color: white;
            margin-right: 5px;
        }

        .btn-danger {
            background: #dc3545;
            color: white;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }

        .empty-state .icon {
            font-size: 60px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="navbar">
        <h1>🔥 Revisar Reportes</h1>
        <div>
            <a href="../dashboard.php">← Atrás</a>
            <span><?php echo htmlspecialchars($nombre_usuario); ?></span>
        </div>
    </div>

    <div class="container">
        <div class="filters">
            <div class="filter-row">
                <div class="filter-group">
                    <label>Estado</label>
                    <select id="filterEstado" onchange="cargarReportes()">
                        <option value="">Todos</option>
                        <option value="borrador">Borrador</option>
                        <option value="pendiente_aprobacion">Pendiente Aprobación</option>
                        <option value="aprobado">Aprobado</option>
                        <option value="rechazado">Rechazado</option>
                    </select>
                </div>
            </div>
        </div>

        <div id="reportesTable"></div>
    </div>

    <script>
        async function cargarReportes() {
            try {
                const response = await fetch('../api/reportes.php?action=listar');
                const data = await response.json();

                if (data.success) {
                    mostrarReportes(data.data);
                } else {
                    mostrarMensajeVacio();
                }
            } catch (error) {
                mostrarMensajeVacio();
            }
        }

        function mostrarReportes(reportes) {
            const container = document.getElementById('reportesTable');
            const filtroEstado = document.getElementById('filterEstado').value;

            let reportesFiltrados = reportes;
            if (filtroEstado) {
                reportesFiltrados = reportes.filter(r => r.estado === filtroEstado);
            }

            if (reportesFiltrados.length === 0) {
                mostrarMensajeVacio();
                return;
            }

            const html = `
                <table>
                    <thead>
                        <tr>
                            <th>Número</th>
                            <th>Plantilla</th>
                            <th>Empresa</th>
                            <th>Inspector</th>
                            <th>Fecha</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${reportesFiltrados.map(r => `
                            <tr>
                                <td><strong>${r.numero_reporte}</strong></td>
                                <td>${r.plantilla_nombre}</td>
                                <td>${r.empresa_nombre}</td>
                                <td>${r.inspector_nombre || 'N/A'}</td>
                                <td>${new Date(r.fecha_creacion).toLocaleDateString('es-MX')}</td>
                                <td><span class="status-badge status-${r.estado}">${getStatusLabel(r.estado)}</span></td>
                                <td>
                                    <button class="btn btn-primary" onclick="verReporte(${r.id})">Ver</button>
                                    ${r.estado === 'pendiente_aprobacion' ? `
                                        <button class="btn btn-success" onclick="aprobarReporte(${r.id})">Aprobar</button>
                                        <button class="btn btn-danger" onclick="rechazarReporte(${r.id})">Rechazar</button>
                                    ` : ''}
                                </td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            `;
            container.innerHTML = html;
        }

        function getStatusLabel(status) {
            const labels = {
                'borrador': 'Borrador',
                'pendiente_aprobacion': 'Pendiente',
                'aprobado': 'Aprobado',
                'rechazado': 'Rechazado'
            };
            return labels[status] || status;
        }

        function verReporte(id) {
            // Implementar vista detallada
            alert('Funcionalidad en desarrollo');
        }

        function aprobarReporte(id) {
            if (confirm('¿Deseas aprobar este reporte?')) {
                fetch('../api/reportes.php?action=aprobar', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id, estado: 'aprobado' })
                })
                .then(() => {
                    alert('Reporte aprobado');
                    cargarReportes();
                })
                .catch(() => alert('Error al aprobar'));
            }
        }

        function rechazarReporte(id) {
            if (confirm('¿Deseas rechazar este reporte?')) {
                fetch('../api/reportes.php?action=aprobar', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id, estado: 'rechazado' })
                })
                .then(() => {
                    alert('Reporte rechazado');
                    cargarReportes();
                })
                .catch(() => alert('Error al rechazar'));
            }
        }

        function mostrarMensajeVacio() {
            document.getElementById('reportesTable').innerHTML = `
                <div class="empty-state">
                    <div class="icon">📋</div>
                    <h3>No hay reportes</h3>
                    <p>No hay reportes para mostrar en este momento</p>
                </div>
            `;
        }

        cargarReportes();
    </script>
</body>
</html>
