<?php
require_once '../config/config.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== ROLE_INSPECTOR) {
    header('Location: ../public/login.html');
    exit;
}

$nombre_usuario = $_SESSION['nombre'];
$reporte_id = $_GET['id'] ?? '';

if (empty($reporte_id)) {
    header('Location: inspector-nuevo-reporte.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Llenar Reporte - Gestión de Extintores</title>
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

        .info-header {
            background: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .info-header h2 {
            margin-bottom: 15px;
            color: #333;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .info-item {
            padding: 10px;
            background: #f9f9f9;
            border-radius: 5px;
        }

        .info-item strong {
            display: block;
            color: #667eea;
            font-size: 12px;
            text-transform: uppercase;
            margin-bottom: 5px;
        }

        .area-section {
            background: white;
            padding: 30px;
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-left: 4px solid #667eea;
        }

        .area-section h3 {
            margin-bottom: 20px;
            color: #333;
        }

        .campos-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: #333;
            font-size: 14px;
        }

        input[type="text"],
        input[type="number"],
        textarea,
        select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 13px;
            font-family: inherit;
        }

        textarea {
            resize: vertical;
            min-height: 80px;
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

        .btn-success {
            background: #28a745;
            color: white;
        }

        .btn-success:hover {
            background: #218838;
        }

        .actions {
            display: flex;
            gap: 10px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
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

        .loading {
            text-align: center;
            padding: 40px;
        }

        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #667eea;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="navbar">
        <h1>🔥 Llenar Reporte de Inspección</h1>
        <div>
            <a href="inspector-nuevo-reporte.php">← Atrás</a>
            <span><?php echo htmlspecialchars($nombre_usuario); ?></span>
        </div>
    </div>

    <div class="container">
        <div id="alert-container"></div>
        <div id="loading" class="loading" style="display: none;">
            <div class="spinner"></div>
            <p>Cargando reporte...</p>
        </div>

        <div id="content" style="display: none;">
            <div class="info-header">
                <h2>Información del Reporte</h2>
                <div class="info-grid">
                    <div class="info-item">
                        <strong>Número de Reporte</strong>
                        <span id="numeroReporte"></span>
                    </div>
                    <div class="info-item">
                        <strong>Empresa</strong>
                        <span id="nombreEmpresa"></span>
                    </div>
                    <div class="info-item">
                        <strong>Plantilla</strong>
                        <span id="nombrePlantilla"></span>
                    </div>
                    <div class="info-item">
                        <strong>Inspector</strong>
                        <span id="nombreInspector"></span>
                    </div>
                </div>
            </div>

            <div id="areasContainer"></div>

            <div class="actions">
                <button class="btn btn-primary" onclick="guardarBorrador()">💾 Guardar Borrador</button>
                <button class="btn btn-success" onclick="enviarReporte()">✓ Enviar Reporte</button>
            </div>
        </div>
    </div>

    <script>
        const reporteId = <?php echo json_encode($reporte_id); ?>;

        async function cargarReporte() {
            document.getElementById('loading').style.display = 'block';

            try {
                const response = await fetch(`../api/reportes.php?action=obtener&id=${reporteId}`);
                const data = await response.json();

                if (data.success) {
                    mostrarReporte(data.data);
                } else {
                    mostrarAlerta('Error al cargar reporte', 'error');
                }
            } catch (error) {
                mostrarAlerta('Error de conexión', 'error');
            } finally {
                document.getElementById('loading').style.display = 'none';
                document.getElementById('content').style.display = 'block';
            }
        }

        function mostrarReporte(reporte) {
            document.getElementById('numeroReporte').textContent = reporte.numero_reporte;
            document.getElementById('nombreEmpresa').textContent = reporte.empresa_nombre;
            document.getElementById('nombrePlantilla').textContent = reporte.plantilla_nombre;
            document.getElementById('nombreInspector').textContent = '<?php echo htmlspecialchars($nombre_usuario); ?>';

            const container = document.getElementById('areasContainer');
            container.innerHTML = '';

            reporte.areas.forEach(area => {
                const section = document.createElement('div');
                section.className = 'area-section';
                section.innerHTML = `<h3>Área: ${area.nombre}</h3>`;

                const grid = document.createElement('div');
                grid.className = 'campos-grid';

                area.campos.forEach(campo => {
                    const div = document.createElement('div');
                    div.className = 'form-group';
                    div.innerHTML = `
                        <label>${campo.nombre_campo}</label>
                        <input type="text"
                            class="campo-input"
                            data-reporte="${reporte.id}"
                            data-area="${area.id}"
                            data-campo="${campo.id}"
                            value="${campo.valor || ''}"
                            placeholder="Ingresa el valor">
                        <label style="margin-top: 10px;">Observaciones</label>
                        <textarea class="campo-observaciones"
                            data-reporte="${reporte.id}"
                            data-area="${area.id}"
                            data-campo="${campo.id}"
                            placeholder="Agregar observaciones...">${campo.observaciones || ''}</textarea>
                    `;
                    grid.appendChild(div);
                });

                section.appendChild(grid);
                container.appendChild(section);
            });
        }

        async function guardarBorrador() {
            const inputs = document.querySelectorAll('.campo-input');
            const promises = [];

            inputs.forEach(input => {
                const reporte = input.dataset.reporte;
                const area = input.dataset.area;
                const campo = input.dataset.campo;
                const valor = input.value;
                const textarea = document.querySelector(
                    `.campo-observaciones[data-reporte="${reporte}"][data-area="${area}"][data-campo="${campo}"]`
                );
                const observaciones = textarea ? textarea.value : '';

                const promise = fetch('../api/reportes.php?action=guardar-datos', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ reporte_id: reporte, area_id: area, campo_id: campo, valor, observaciones })
                });

                promises.push(promise);
            });

            Promise.all(promises)
                .then(() => mostrarAlerta('Borrador guardado', 'success'))
                .catch(() => mostrarAlerta('Error al guardar', 'error'));
        }

        async function enviarReporte() {
            if (!confirm('¿Estás seguro de que deseas enviar este reporte para aprobación?')) return;

            try {
                // Primero guardar todos los datos
                await guardarBorrador();

                // Luego cambiar estado a pendiente_aprobacion
                const response = await fetch('../api/reportes.php?action=aprobar', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: reporteId, estado: 'pendiente_aprobacion' })
                });

                const data = await response.json();

                if (data.success) {
                    mostrarAlerta('Reporte enviado para aprobación', 'success');
                    setTimeout(() => {
                        window.location.href = 'inspector-mis-reportes.php';
                    }, 2000);
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

        cargarReporte();
    </script>
</body>
</html>
