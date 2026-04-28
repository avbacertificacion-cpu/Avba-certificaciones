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
    <title>Crear Plantilla - Gestión de Extintores</title>
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

        .navbar a:hover {
            text-decoration: underline;
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

        input[type="text"],
        input[type="email"],
        textarea,
        select {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
            font-family: inherit;
        }

        textarea {
            resize: vertical;
            min-height: 100px;
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

        .btn-secondary {
            background: #e0e0e0;
            color: #333;
            margin-left: 10px;
        }

        .btn-secondary:hover {
            background: #d0d0d0;
        }

        .btn-small {
            padding: 8px 16px;
            font-size: 12px;
        }

        .areas-list {
            margin-top: 30px;
            border-top: 1px solid #ddd;
            padding-top: 20px;
        }

        .area-item {
            background: #f9f9f9;
            padding: 20px;
            border-radius: 5px;
            margin-bottom: 20px;
            border-left: 4px solid #667eea;
        }

        .area-item h4 {
            margin-bottom: 10px;
            color: #333;
        }

        .campos-list {
            margin-top: 15px;
            padding-left: 20px;
        }

        .campo-item {
            background: white;
            padding: 10px;
            border-radius: 3px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border: 1px solid #eee;
        }

        .alert {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .two-columns {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        @media (max-width: 768px) {
            .two-columns {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="navbar">
        <h1>🔥 Crear Plantilla</h1>
        <div>
            <a href="../dashboard.php">← Atrás</a>
            <span><?php echo htmlspecialchars($nombre_usuario); ?></span>
        </div>
    </div>

    <div class="container">
        <div id="alert-container"></div>

        <div class="card">
            <h2>Nueva Plantilla de Inspección</h2>
            <form id="plantillaForm">
                <div class="two-columns">
                    <div class="form-group">
                        <label for="nombre">Nombre de la Plantilla *</label>
                        <input type="text" id="nombre" name="nombre" required placeholder="Ej: Inspección Quantum Energía">
                    </div>
                    <div class="form-group">
                        <label for="empresa_id">Empresa (Opcional)</label>
                        <input type="text" id="empresa_id" name="empresa_id" placeholder="ID de empresa">
                    </div>
                </div>
                <div class="form-group">
                    <label for="descripcion">Descripción</label>
                    <textarea id="descripcion" name="descripcion" placeholder="Descripción de la plantilla..."></textarea>
                </div>
                <button type="submit" class="btn btn-primary">Crear Plantilla</button>
            </form>
        </div>

        <div class="card" id="areasCard" style="display: none;">
            <h2>Agregar Áreas</h2>
            <form id="areaForm">
                <div class="two-columns">
                    <div class="form-group">
                        <label for="nombreArea">Nombre del Área *</label>
                        <input type="text" id="nombreArea" name="nombreArea" required placeholder="Ej: Exterior costado caseta">
                    </div>
                    <div class="form-group">
                        <label for="descripcionArea">Descripción (Opcional)</label>
                        <input type="text" id="descripcionArea" name="descripcionArea" placeholder="Descripción del área">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-small">+ Agregar Área</button>
            </form>

            <div class="areas-list" id="areasList"></div>
        </div>

        <div class="card" id="camposCard" style="display: none;">
            <h2>Agregar Campos a Área</h2>
            <form id="campoForm">
                <div class="form-group">
                    <label for="areaSelect">Seleccionar Área *</label>
                    <select id="areaSelect" name="areaSelect" required></select>
                </div>
                <div class="two-columns">
                    <div class="form-group">
                        <label for="nombreCampo">Nombre del Campo *</label>
                        <input type="text" id="nombreCampo" name="nombreCampo" required placeholder="Ej: SER, MG, PO, PH, SG...">
                    </div>
                    <div class="form-group">
                        <label for="tipoCampo">Tipo de Campo</label>
                        <input type="text" id="tipoCampo" name="tipoCampo" placeholder="Ej: text, number, select">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-small">+ Agregar Campo</button>
            </form>
        </div>

        <div class="card">
            <h2>Plantillas Existentes</h2>
            <button class="btn btn-secondary" onclick="cargarPlantillas()">Recargar</button>
            <div id="plantillasList" style="margin-top: 20px;"></div>
        </div>
    </div>

    <script>
        let plantillaActual = null;

        document.getElementById('plantillaForm').addEventListener('submit', crearPlantilla);
        document.getElementById('areaForm').addEventListener('submit', agregarArea);
        document.getElementById('campoForm').addEventListener('submit', agregarCampo);

        async function crearPlantilla(e) {
            e.preventDefault();

            const nombre = document.getElementById('nombre').value;
            const descripcion = document.getElementById('descripcion').value;
            const empresa_id = document.getElementById('empresa_id').value;

            try {
                const response = await fetch('../api/plantillas.php?action=crear', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ nombre, descripcion, empresa_id: empresa_id || null })
                });

                const data = await response.json();

                if (data.success) {
                    plantillaActual = data.id;
                    mostrarAlerta(`Plantilla creada: ${data.numero_reporte}`, 'success');
                    document.getElementById('areasCard').style.display = 'block';
                    document.getElementById('camposCard').style.display = 'block';
                    document.getElementById('plantillaForm').style.display = 'none';
                    document.getElementById('areasList').innerHTML = '';
                    cargarPlantillas();
                } else {
                    mostrarAlerta(data.error, 'error');
                }
            } catch (error) {
                mostrarAlerta('Error de conexión', 'error');
            }
        }

        async function agregarArea(e) {
            e.preventDefault();

            if (!plantillaActual) {
                mostrarAlerta('Primero crea una plantilla', 'error');
                return;
            }

            const nombre = document.getElementById('nombreArea').value;
            const descripcion = document.getElementById('descripcionArea').value;

            try {
                const response = await fetch('../api/plantillas.php?action=agregar-area', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ plantilla_id: plantillaActual, nombre, descripcion })
                });

                const data = await response.json();

                if (data.success) {
                    document.getElementById('areaForm').reset();
                    mostrarAlerta('Área agregada', 'success');
                    cargarPlantilla(plantillaActual);
                } else {
                    mostrarAlerta(data.error, 'error');
                }
            } catch (error) {
                mostrarAlerta('Error de conexión', 'error');
            }
        }

        async function agregarCampo(e) {
            e.preventDefault();

            const area_id = document.getElementById('areaSelect').value;
            const nombre_campo = document.getElementById('nombreCampo').value;
            const tipo = document.getElementById('tipoCampo').value;

            try {
                const response = await fetch('../api/plantillas.php?action=agregar-campo', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ area_id, nombre_campo, tipo })
                });

                const data = await response.json();

                if (data.success) {
                    document.getElementById('campoForm').reset();
                    mostrarAlerta('Campo agregado', 'success');
                    cargarPlantilla(plantillaActual);
                } else {
                    mostrarAlerta(data.error, 'error');
                }
            } catch (error) {
                mostrarAlerta('Error de conexión', 'error');
            }
        }

        async function cargarPlantilla(id) {
            try {
                const response = await fetch(`../api/plantillas.php?action=obtener&id=${id}`);
                const data = await response.json();

                if (data.success) {
                    const plantilla = data.data;
                    mostrarAreas(plantilla.areas);
                    actualizarSelectAreas(plantilla.areas);
                }
            } catch (error) {
                console.error('Error al cargar plantilla', error);
            }
        }

        function mostrarAreas(areas) {
            const areasList = document.getElementById('areasList');
            areasList.innerHTML = '';

            areas.forEach(area => {
                const areaDiv = document.createElement('div');
                areaDiv.className = 'area-item';
                areaDiv.innerHTML = `
                    <h4>${area.nombre}</h4>
                    <p>${area.descripcion || ''}</p>
                    <div class="campos-list">
                        ${area.campos.map(c => `<div class="campo-item">${c.nombre_campo} (${c.tipo})</div>`).join('')}
                    </div>
                `;
                areasList.appendChild(areaDiv);
            });
        }

        function actualizarSelectAreas(areas) {
            const select = document.getElementById('areaSelect');
            select.innerHTML = '<option value="">Seleccionar área...</option>';
            areas.forEach(area => {
                const option = document.createElement('option');
                option.value = area.id;
                option.textContent = area.nombre;
                select.appendChild(option);
            });
        }

        async function cargarPlantillas() {
            try {
                const response = await fetch('../api/plantillas.php?action=listar');
                const data = await response.json();

                if (data.success) {
                    const list = document.getElementById('plantillasList');
                    list.innerHTML = data.data.map(p => `
                        <div class="area-item" style="border-left-color: #28a745;">
                            <h4>${p.nombre}</h4>
                            <p>${p.numero_reporte}</p>
                            <button class="btn btn-small btn-secondary" onclick="cargarPlantilla(${p.id})">Editar</button>
                        </div>
                    `).join('');
                }
            } catch (error) {
                console.error('Error al cargar plantillas', error);
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
