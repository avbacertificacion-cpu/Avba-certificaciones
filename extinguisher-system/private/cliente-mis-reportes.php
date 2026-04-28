<?php
require_once '../config/config.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== ROLE_CLIENTE) {
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

        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-top: 20px;
        }

        thead {
            background: #28a745;
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

        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            background: #28a745;
            color: white;
        }

        .btn:hover {
            background: #218838;
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
        <h1>🔥 Mis Reportes de Inspección</h1>
        <div>
            <a href="../dashboard.php">← Atrás</a>
            <span><?php echo htmlspecialchars($nombre_usuario); ?></span>
        </div>
    </div>

    <div class="container">
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

            if (reportes.length === 0) {
                mostrarMensajeVacio();
                return;
            }

            const html = `
                <table>
                    <thead>
                        <tr>
                            <th>Número de Reporte</th>
                            <th>Plantilla</th>
                            <th>Empresa</th>
                            <th>Fecha</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${reportes.map(r => `
                            <tr>
                                <td><strong>${r.numero_reporte}</strong></td>
                                <td>${r.plantilla_nombre}</td>
                                <td>${r.empresa_nombre}</td>
                                <td>${new Date(r.fecha_creacion).toLocaleDateString('es-MX')}</td>
                                <td>
                                    <button class="btn" onclick="verReporte(${r.id})">Ver Reporte</button>
                                    <button class="btn" onclick="descargarPDF(${r.id})">Descargar PDF</button>
                                </td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            `;
            container.innerHTML = html;
        }

        function verReporte(id) {
            alert('Funcionalidad en desarrollo');
        }

        function descargarPDF(id) {
            alert('Descarga de PDF en desarrollo');
        }

        function mostrarMensajeVacio() {
            document.getElementById('reportesTable').innerHTML = `
                <div class="empty-state">
                    <div class="icon">📋</div>
                    <h3>No hay reportes disponibles</h3>
                    <p>Por el momento no tienes reportes aprobados. Pronto estarán disponibles.</p>
                </div>
            `;
        }

        cargarReportes();
    </script>
</body>
</html>
