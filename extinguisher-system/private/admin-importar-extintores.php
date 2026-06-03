<?php
require_once '../config/config.php';
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== ROLE_ADMIN) {
    header('Location: ../public/login.html'); exit;
}
$nombre = $_SESSION['nombre'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Importar Extintores</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Segoe UI',sans-serif;background:#f0f4ff;color:#333}

        .navbar{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;padding:16px 20px;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;z-index:50;box-shadow:0 4px 12px rgba(102,126,234,.15)}
        .navbar h1{font-size:20px;font-weight:700}
        .navbar a{color:#fff;text-decoration:none;font-size:13px;margin-left:16px}
        .navbar a:hover{opacity:.8}

        .container{max-width:1000px;margin:32px auto;padding:0 20px}

        .card{background:#fff;border-radius:12px;padding:24px;box-shadow:0 2px 8px rgba(0,0,0,.06);margin-bottom:24px}

        .section-title{font-size:18px;font-weight:700;color:#333;margin-bottom:16px;border-bottom:2px solid #667eea;padding-bottom:8px}

        .upload-zone{border:2px dashed #667eea;border-radius:12px;padding:32px;text-align:center;cursor:pointer;transition:.2s;background:#f9fafb}
        .upload-zone:hover{background:#f0f3ff;border-color:#764ba2}
        .upload-zone.dragover{background:#f0f3ff;border-color:#764ba2}
        .upload-icon{font-size:48px;margin-bottom:12px}
        .upload-text{font-size:14px;color:#666;margin-bottom:8px}
        .upload-hint{font-size:12px;color:#999}

        #fileInput{display:none}

        .btn{padding:10px 20px;border:none;border-radius:8px;cursor:pointer;font-weight:700;font-size:13px;transition:.2s;display:inline-flex;align-items:center;gap:8px}
        .btn-primary{background:#667eea;color:#fff}.btn-primary:hover{background:#5568d3}
        .btn-success{background:#27ae60;color:#fff}.btn-success:hover{background:#1e8449}
        .btn-warning{background:#f39c12;color:#fff}.btn-warning:hover{background:#d68910}
        .btn-danger{background:#e74c3c;color:#fff}.btn-danger:hover{background:#c0392b}
        .btn-sm{padding:8px 12px;font-size:12px}

        .toolbar{display:flex;gap:12px;margin-bottom:24px;flex-wrap:wrap}

        .preview-table{width:100%;border-collapse:collapse;margin-top:16px;font-size:12px}
        .preview-table thead{background:#667eea;color:#fff}
        .preview-table th{padding:10px;text-align:left;font-weight:700}
        .preview-table td{padding:10px;border-bottom:1px solid #eee}
        .preview-table tbody tr:hover{background:#f0f3ff}

        .error{background:#f8d7da;border:1px solid #f5c6cb;color:#721c24;padding:12px;border-radius:6px;margin-bottom:16px}
        .success{background:#d4edda;border:1px solid #c3e6cb;color:#155724;padding:12px;border-radius:6px;margin-bottom:16px}
        .warning{background:#fff3cd;border:1px solid #ffeaa7;color:#856404;padding:12px;border-radius:6px;margin-bottom:16px}

        .row-error{background:#ffebee !important}
        .row-warning{background:#fff8e1 !important}

        .status-badge{display:inline-block;padding:3px 8px;border-radius:3px;font-size:10px;font-weight:700}
        .status-ok{background:#d4edda;color:#155724}
        .status-error{background:#f8d7da;color:#721c24}
        .status-warning{background:#fff3cd;color:#856404}

        .stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:16px 0}
        .stat-box{background:#f0f3ff;padding:12px;border-radius:8px;text-align:center}
        .stat-label{font-size:11px;color:#667eea;font-weight:700;text-transform:uppercase;margin-bottom:4px}
        .stat-value{font-size:20px;font-weight:700;color:#333}

        .actions{display:flex;gap:12px;justify-content:flex-end;margin-top:20px;padding-top:20px;border-top:1px solid #eee}

        .modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:100;justify-content:center;align-items:center;padding:16px}
        .modal-overlay.open{display:flex}
        .modal{background:#fff;border-radius:16px;width:100%;max-width:500px;box-shadow:0 20px 60px rgba(0,0,0,.3)}

        .modal-header{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;padding:24px;border-radius:16px 16px 0 0;display:flex;justify-content:space-between;align-items:center}
        .modal-header h2{font-size:18px}
        .modal-body{padding:24px}
        .modal-actions{display:flex;gap:12px;justify-content:flex-end;margin-top:20px;padding-top:20px;border-top:1px solid #eee}

        @media(max-width:768px){
            .stats{grid-template-columns:repeat(2,1fr)}
            .preview-table{font-size:11px}
            .preview-table th,.preview-table td{padding:6px}
        }
    </style>
</head>
<body>

<div class="navbar">
    <h1>📥 Importar Extintores</h1>
    <div>
        <a href="admin-dashboard.php">← Panel</a>
        <span style="margin-left:16px;font-size:13px"><?= htmlspecialchars($nombre) ?></span>
    </div>
</div>

<div class="container">
    <div id="alertBox"></div>

    <div class="card">
        <div class="section-title">📋 Paso 1: Preparar archivo</div>
        <p style="color:#666;font-size:13px;margin-bottom:16px">
            Descarga la plantilla, complétala en Excel y súbela aquí.
            <strong>Formato aceptado: CSV (exporta desde Excel como CSV UTF-8)</strong>
        </p>
        <div class="toolbar">
            <a href="../public/plantilla-extintores.csv" class="btn btn-primary" download>
                📥 Descargar plantilla
            </a>
        </div>

        <div class="upload-zone" id="dropZone">
            <div class="upload-icon">📁</div>
            <div class="upload-text">Arrastra tu archivo CSV aquí</div>
            <div class="upload-hint">o haz clic para seleccionar</div>
            <input type="file" id="fileInput" accept=".csv">
        </div>
    </div>

    <div class="card" id="previewCard" style="display:none">
        <div class="section-title">👁️ Paso 2: Previsualizar datos</div>

        <div id="previewAlert"></div>

        <div class="stats">
            <div class="stat-box">
                <div class="stat-label">Total</div>
                <div class="stat-value" id="statTotal">0</div>
            </div>
            <div class="stat-box">
                <div class="stat-label">Válidos</div>
                <div class="stat-value" id="statValidos">0</div>
            </div>
            <div class="stat-box">
                <div class="stat-label">Advertencias</div>
                <div class="stat-value" id="statWarnings">0</div>
            </div>
            <div class="stat-box">
                <div class="stat-label">Errores</div>
                <div class="stat-value" id="statErrors">0</div>
            </div>
        </div>

        <table class="preview-table">
            <thead>
                <tr>
                    <th style="width:30px">#</th>
                    <th>Código</th>
                    <th>Ubicación</th>
                    <th>Tipo</th>
                    <th>Empresa</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody id="previewTable"></tbody>
        </table>

        <div class="actions">
            <button class="btn btn-warning" onclick="resetForm()">❌ Cancelar</button>
            <button class="btn btn-success" id="btnConfirmar" onclick="confirmarImportacion()" style="display:none">
                ✅ Confirmar e importar
            </button>
        </div>
    </div>
</div>

<div class="modal-overlay" id="modalConfirm" onclick="if(event.target===this)cerrarModal()">
    <div class="modal">
        <div class="modal-header">
            <h2>⚠️ Confirmar importación</h2>
        </div>
        <div class="modal-body">
            <p style="margin-bottom:16px">Se van a importar <strong id="modalTotal">0</strong> extintores.</p>
            <p style="color:#666;font-size:13px">¿Deseas continuar?</p>
        </div>
        <div class="modal-actions">
            <button class="btn btn-warning" onclick="cerrarModal()">Cancelar</button>
            <button class="btn btn-success" onclick="ejecutarImportacion()">Sí, importar</button>
        </div>
    </div>
</div>

<script>
let datosPreview = [];
let datosValidos = [];

const dropZone = document.getElementById('dropZone');
dropZone.addEventListener('click', () => document.getElementById('fileInput').click());
dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('dragover'); });
dropZone.addEventListener('dragleave', () => dropZone.classList.remove('dragover'));
dropZone.addEventListener('drop', e => {
    e.preventDefault();
    dropZone.classList.remove('dragover');
    const file = e.dataTransfer.files[0];
    if (file) procesarArchivo(file);
});

document.getElementById('fileInput').addEventListener('change', e => {
    const file = e.target.files[0];
    if (file) procesarArchivo(file);
});

function procesarArchivo(file) {
    if (!file.name.endsWith('.csv')) {
        mostrarAlerta('Solo se aceptan archivos CSV', 'error');
        return;
    }

    const reader = new FileReader();
    reader.onload = e => {
        const csv = e.target.result;
        const lineas = csv.trim().split('\n');

        if (lineas.length < 2) {
            mostrarAlerta('El archivo está vacío', 'error');
            return;
        }

        const headers = lineas[0].split(',').map(h => h.trim().toLowerCase());
        const cols = {
            codigo_manual: headers.indexOf('codigo_manual'),
            ubicacion: headers.indexOf('ubicacion'),
            tipo: headers.indexOf('tipo'),
            capacidad: headers.indexOf('capacidad'),
            seccion: headers.indexOf('seccion'),
            empresa_id: headers.indexOf('empresa_id'),
            fecha_recarga: headers.indexOf('fecha_recarga'),
            fecha_ph: headers.indexOf('fecha_ph'),
            observaciones: headers.indexOf('observaciones'),
        };

        const requeridas = ['codigo_manual', 'ubicacion', 'tipo', 'empresa_id'];
        for (let col of requeridas) {
            if (cols[col] === -1) {
                mostrarAlerta(`Falta columna: ${col}`, 'error');
                return;
            }
        }

        datosPreview = [];
        datosValidos = [];
        let errores = 0, warnings = 0;

        for (let i = 1; i < lineas.length; i++) {
            const valores = lineas[i].split(',').map(v => v.trim());
            if (valores.length < 4) continue;

            const fila = {
                num: i,
                codigo_manual: valores[cols.codigo_manual] || '',
                ubicacion: valores[cols.ubicacion] || '',
                tipo: valores[cols.tipo] || '',
                capacidad: valores[cols.capacidad] || null,
                seccion: valores[cols.seccion] || null,
                empresa_id: valores[cols.empresa_id] || '',
                fecha_recarga: valores[cols.fecha_recarga] || null,
                fecha_ph: valores[cols.fecha_ph] || null,
                observaciones: valores[cols.observaciones] || null,
                errores: [],
                warnings: []
            };

            if (!fila.codigo_manual) fila.errores.push('Código vacío');
            if (!fila.ubicacion) fila.errores.push('Ubicación vacía');
            if (!['PQS','CO2','agua','espuma','halotron','otro'].includes(fila.tipo))
                fila.errores.push('Tipo inválido');
            if (!fila.empresa_id || isNaN(fila.empresa_id))
                fila.errores.push('Empresa ID inválido');

            if (fila.errores.length > 0) errores++;
            if (fila.warnings.length > 0) warnings++;

            if (fila.errores.length === 0) datosValidos.push(fila);
            datosPreview.push(fila);
        }

        mostrarPreview(datosPreview, datosValidos, errores, warnings);
    };
    reader.readAsText(file);
}

function mostrarPreview(datos, validos, errores, warnings) {
    document.getElementById('previewCard').style.display = 'block';
    document.getElementById('statTotal').textContent = datos.length;
    document.getElementById('statValidos').textContent = validos.length;
    document.getElementById('statWarnings').textContent = warnings;
    document.getElementById('statErrors').textContent = errores;

    let alertHtml = '';
    if (errores > 0) alertHtml += `<div class="error">⚠️ <strong>${errores} fila(s) con error</strong></div>`;
    if (validos.length > 0) alertHtml += `<div class="success">✅ <strong>${validos.length} extintor(es) listos</strong></div>`;
    document.getElementById('previewAlert').innerHTML = alertHtml;

    const tbody = document.getElementById('previewTable');
    tbody.innerHTML = datos.map(f => {
        const status = f.errores.length > 0 ? 'error' : 'ok';
        const clase = status === 'error' ? 'row-error' : '';
        return `<tr class="${clase}"><td>${f.num}</td><td><strong>${f.codigo_manual}</strong></td><td>${f.ubicacion}</td><td>${f.tipo}</td><td>${f.empresa_id}</td><td><span class="status-badge status-${status}">${status === 'error' ? '❌ Error' : '✅ OK'}</span></td></tr>`;
    }).join('');

    document.getElementById('btnConfirmar').style.display = validos.length > 0 ? 'inline-flex' : 'none';
}

function confirmarImportacion() {
    document.getElementById('modalTotal').textContent = datosValidos.length;
    document.getElementById('modalConfirm').classList.add('open');
}

function cerrarModal() {
    document.getElementById('modalConfirm').classList.remove('open');
}

async function ejecutarImportacion() {
    const r = await fetch('../api/importar-extintores.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({datos: datosValidos})
    });
    const d = await r.json();

    if (d.success) {
        mostrarAlerta(`✅ Se importaron ${d.insertados} extintores`, 'success');
        setTimeout(() => resetForm(), 2000);
    } else {
        mostrarAlerta(d.error || 'Error al importar', 'error');
    }
    cerrarModal();
}

function resetForm() {
    document.getElementById('fileInput').value = '';
    document.getElementById('previewCard').style.display = 'none';
    datosPreview = [];
    datosValidos = [];
}

function mostrarAlerta(msg, tipo) {
    const clase = tipo === 'error' ? 'error' : 'success';
    document.getElementById('alertBox').innerHTML = `<div class="${clase}">${msg}</div>`;
}
</script>

</body>
</html>
