<?php
require_once '../config/config.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== ROLE_INSPECTOR) {
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
    <title>Mis Reportes - Gestión de Extintores</title>
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
        }

        .navbar a {
            color: white;
            text-decoration: none;
            margin-right: 20px;
        }

        .container {
            max-width: 1000px;
            margin: 40px auto;
            padding: 0 20px;
        }

        .reportes-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }

        .reporte-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-left: 4px solid #667eea;
        }

        .reporte-card h3 {
            margin-bottom: 10px;
            color: #333;
        }

        .reporte-info {
            font-size: 13px;
            color: #666;
            margin-bottom: 10px;
        }

        .status-badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 11px;
            font-weight: 600;
            margin-bottom: 15px;
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
            margin-right: 5px;
        }

        .btn-primary {
            background: #667eea;
            color: white;
        }

        .btn-primary:hover {
            background: #5568d3;
        }

        .btn-secondary {
            background: #e0e0e0;
            color: #333;
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

        .btn-nuevo {
            margin-bottom: 30px;
        }

        .btn-nuevo .btn {
            padding: 12px 24px;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="navbar">
        <h1>🔥 Mis Reportes</h1>
        <div>
            <a href="../dashboard.php">← Atrás</a>
            <span><?php echo htmlspecialchars($nombre_usuario); ?></span>
        </div>
    </div>

    <div class="container">
        <div class="btn-nuevo">
            <button class="btn btn-primary" onclick="window.location.href='inspector-nuevo-reporte.php'">
                ➕ Crear Nuevo Reporte
            </button>
        </div>

        <div id="reportesContainer"></div>
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
            const container = document.getElementById('reportesContainer');

            if (reportes.length === 0) {
                mostrarMensajeVacio();
                return;
            }

            container.innerHTML = `
                <div class="reportes-grid">
                    ${reportes.map(r => `
                        <div class="reporte-card">
                            <h3>${r.plantilla_nombre}</h3>
                            <span class="status-badge status-${r.estado}">${getStatusLabel(r.estado)}</span>
                            <div class="reporte-info">
                                <p><strong>Número:</strong> ${r.numero_reporte}</p>
                                <p><strong>Empresa:</strong> ${r.empresa_nombre}</p>
                                <p><strong>Fecha:</strong> ${new Date(r.fecha_creacion).toLocaleDateString('es-MX')}</p>
                            </div>
                            <div>
                                ${r.estado === 'borrador' ? `
                                    <button class="btn btn-primary" onclick="editarReporte(${r.id})">Continuar</button>
                                ` : ''}
                                <button class="btn btn-secondary" onclick="verReporte(${r.id})">Ver</button>
                                ${r.estado === 'aprobado' ? `
                                    <button class="btn btn-secondary" onclick="descargarPDF(${r.id})">PDF</button>
                                ` : ''}
                            </div>
                        </div>
                    `).join('')}
                </div>
            `;
        }

        function getStatusLabel(status) {
            const labels = {
                'borrador': 'Borrador',
                'pendiente_aprobacion': 'Pendiente Aprobación',
                'aprobado': 'Aprobado',
                'rechazado': 'Rechazado'
            };
            return labels[status] || status;
        }

        function editarReporte(id) {
            window.location.href = `inspector-llenar-reporte.php?id=${id}`;
        }

        function verReporte(id) {
            window.location.href = `inspector-llenar-reporte.php?id=${id}`;
        }

        function descargarPDF(id) {
            alert('Funcionalidad de descarga de PDF en desarrollo');
        }

        function mostrarMensajeVacio() {
            document.getElementById('reportesContainer').innerHTML = `
                <div class="empty-state">
                    <div class="icon">📋</div>
                    <h3>No hay reportes</h3>
                    <p>Aún no has creado ningún reporte. Crea uno nuevo para comenzar.</p>
                </div>
            `;
        }

        cargarReportes();
    </script>
</body>
</html>
