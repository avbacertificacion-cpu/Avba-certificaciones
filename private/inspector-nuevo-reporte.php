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
    <title>Nuevo Reporte - Gestión de Extintores</title>
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
            font-size: 14px;
        }

        .container {
            max-width: 1000px;
            margin: 40px auto;
            padding: 0 20px;
        }

        .card {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }

        .card h2 {
            margin-bottom: 20px;
            color: #333;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #333;
        }

        select {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
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

        .alert {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
        }

        .plantillas-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }

        .plantilla-card {
            background: #f9f9f9;
            padding: 20px;
            border-radius: 5px;
            border: 2px solid #ddd;
            cursor: pointer;
            transition: all 0.3s;
        }

        .plantilla-card:hover {
            border-color: #667eea;
            box-shadow: 0 2px 8px rgba(102,126,234,0.2);
        }

        .plantilla-card h3 {
            margin-bottom: 10px;
            color: #333;
        }

        .plantilla-card p {
            font-size: 12px;
            color: #666;
            margin-bottom: 15px;
        }

        .plantilla-card .btn {
            width: 100%;
        }
    </style>
</head>
<body>
    <div class="navbar">
        <h1>🔥 Llenar Nuevo Reporte</h1>
        <div>
            <a href="../dashboard.php">← Atrás</a>
            <span><?php echo htmlspecialchars($nombre_usuario); ?></span>
        </div>
    </div>

    <div class="container">
        <div id="alert-container"></div>

        <div class="card">
            <h2>Seleccionar Plantilla</h2>
            <p>Elige una plantilla para comenzar a llenar el reporte de inspección:</p>
            <div class="plantillas-grid" id="plantillasGrid"></div>
        </div>
    </div>

    <script>
        async function cargarPlantillas() {
            try {
                const response = await fetch('../api/plantillas.php?action=listar');
                const data = await response.json();

                if (data.success) {
                    const grid = document.getElementById('plantillasGrid');
                    grid.innerHTML = data.data.map(p => `
                        <div class="plantilla-card">
                            <h3>${p.nombre}</h3>
                            <p><strong>${p.numero_reporte}</strong></p>
                            <p>${p.descripcion || 'Sin descripción'}</p>
                            <button class="btn btn-primary" onclick="crearReporte(${p.id})">Usar esta plantilla</button>
                        </div>
                    `).join('');
                } else {
                    mostrarAlerta('No hay plantillas disponibles', 'error');
                }
            } catch (error) {
                mostrarAlerta('Error al cargar plantillas', 'error');
            }
        }

        async function crearReporte(plantilla_id) {
            try {
                const response = await fetch('../api/reportes.php?action=crear', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ plantilla_id })
                });

                const data = await response.json();

                if (data.success) {
                    mostrarAlerta('Reporte creado. Redirigiendo...', 'success');
                    setTimeout(() => {
                        window.location.href = `inspector-llenar-reporte.php?id=${data.id}`;
                    }, 1500);
                } else {
                    mostrarAlerta(data.error, 'error');
                }
            } catch (error) {
                mostrarAlerta('Error de conexión', 'error');
            }
        }

        function mostrarAlerta(mensaje, tipo) {
            const container = document.getElementById('alert-container');
            const div = document.createElement('div');
            div.className = `alert alert-${tipo}`;
            div.textContent = mensaje;
            container.innerHTML = '';
            container.appendChild(div);

            setTimeout(() => {
                div.remove();
            }, 5000);
        }

        cargarPlantillas();
    </script>
</body>
</html>
