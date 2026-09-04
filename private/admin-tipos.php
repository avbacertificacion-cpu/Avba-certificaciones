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
    <title>Tipos de Extintores</title>
    <link rel="stylesheet" href="../public/assets/css/movil.css">
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Segoe UI',sans-serif;background:#f0f4ff;color:#333}

        .navbar{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;padding:16px 20px;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;z-index:50;box-shadow:0 4px 12px rgba(102,126,234,.15)}
        .navbar h1{font-size:20px;font-weight:700}
        .navbar a{color:#fff;text-decoration:none;font-size:13px;margin-left:16px}
        .navbar a:hover{opacity:.8}

        .container{max-width:900px;margin:32px auto;padding:0 16px}

        .page-header{margin-bottom:28px}
        .page-header h2{font-size:28px;color:#333;margin-bottom:8px}
        .page-header p{color:#888;font-size:14px}

        .toolbar{margin-bottom:24px}
        .btn{padding:12px 20px;border:none;border-radius:8px;cursor:pointer;font-weight:700;font-size:13px;transition:.2s;display:inline-flex;align-items:center;gap:8px}
        .btn:active{transform:scale(.98)}
        .btn:disabled{opacity:.5;cursor:not-allowed}

        .btn-primary{background:#667eea;color:#fff}.btn-primary:hover{background:#5568d3}
        .btn-warning{background:#f39c12;color:#fff}.btn-warning:hover{background:#d68910}
        .btn-danger{background:#e74c3c;color:#fff}.btn-danger:hover{background:#c0392b}
        .btn-sm{padding:8px 12px;font-size:12px}

        .tipos-table{width:100%;border-collapse:collapse;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.06)}
        .tipos-table thead{background:#667eea;color:#fff}
        .tipos-table th{padding:16px;text-align:left;font-weight:700}
        .tipos-table td{padding:16px;border-bottom:1px solid #eee}
        .tipos-table tbody tr:hover{background:#f9fafb}
        .tipos-table .actions{display:flex;gap:8px}

        .empty{text-align:center;padding:60px 20px;background:#fff;border-radius:12px;color:#aaa}
        .empty .icon{font-size:64px;margin-bottom:16px}

        .status-badge{display:inline-block;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:700}
        .status-activo{background:#d4edda;color:#155724}
        .status-inactivo{background:#f8d7da;color:#721c24}

        .alert{padding:12px;border-radius:6px;margin-bottom:16px;font-size:13px}
        .alert-success{background:#d4edda;color:#155724;border:1px solid #c3e6cb}
        .alert-error{background:#f8d7da;color:#721c24;border:1px solid #f5c6cb}

        .modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100;justify-content:center;align-items:center;padding:16px}
        .modal-overlay.open{display:flex}
        .modal{background:#fff;border-radius:12px;padding:32px;width:100%;max-width:500px;max-height:90vh;overflow-y:auto;box-shadow:0 10px 40px rgba(0,0,0,.2)}
        .modal h2{margin-bottom:24px}
        .form-group{margin-bottom:16px}
        .form-group label{display:block;font-size:13px;font-weight:700;color:#444;margin-bottom:6px}
        .form-group input,.form-group textarea{width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;font-size:14px;font-family:inherit}
        .form-group textarea{resize:vertical;min-height:80px}
        .form-group small{display:block;color:#999;margin-top:4px}
        .modal-actions{display:flex;gap:12px;justify-content:flex-end;margin-top:20px;padding-top:18px;border-top:1px solid #eee;flex-shrink:0}

        @media(max-width:768px){
            .navbar h1{font-size:18px}
            .page-header h2{font-size:22px}
            .tipos-table th,.tipos-table td{padding:12px}
            .btn{width:100%;justify-content:center}
        }
    </style>
</head>
<body>

<div class="navbar">
    <h1>🧪 Tipos de Extintores</h1>
    <div>
        <a href="admin-dashboard.php">← Volver</a>
        <span style="margin-left:16px;font-size:13px"><?= htmlspecialchars($nombre) ?></span>
    </div>
</div>

<div class="container">
    <div class="page-header">
        <h2>🧪 Tipos de Extintores</h2>
        <p>Administra los tipos de extintores disponibles en el sistema</p>
    </div>

    <div id="alert-box"></div>

    <div class="toolbar">
        <button class="btn btn-primary" onclick="abrirModalNuevo()">+ Nuevo tipo</button>
    </div>

    <div id="tabla-container"></div>
</div>

<!-- Modal Crear / Editar -->
<div class="modal-overlay" id="modalTipo" onclick="if(event.target===this) cerrarModal()">
    <div class="modal">
        <h2 id="modalTitulo">Nuevo Tipo</h2>
        <div id="modal-alert"></div>
        <input type="hidden" id="t-id">

        <div class="form-group">
            <label>Nombre del tipo *</label>
            <input type="text" id="t-nombre" placeholder="Ej: Polvo Químico Seco" required>
            <small>Nombre único para el tipo de extintor</small>
        </div>

        <div class="form-group">
            <label>Descripción</label>
            <textarea id="t-descripcion" placeholder="Ej: Extintores de polvo químico seco para fuegos tipo ABC…"></textarea>
            <small>Descripción opcional del tipo</small>
        </div>

        <div class="modal-actions">
            <button class="btn btn-warning" onclick="cerrarModal()">Cancelar</button>
            <button class="btn btn-primary" onclick="guardarTipo()">Guardar tipo</button>
        </div>
    </div>
</div>

<script>
async function cargarTipos() {
    const r = await fetch('../api/tipos.php?action=listar');
    const d = await r.json();

    if (!d.success) {
        mostrarAlerta('Error al cargar tipos', 'error');
        return;
    }

    const tipos = d.data;
    if (tipos.length === 0) {
        document.getElementById('tabla-container').innerHTML = '<div class="empty"><div class="icon">📭</div>Sin tipos registrados</div>';
        return;
    }

    let html = '<div class="tabla-env"><table class="tipos-table tabla"><thead><tr><th>Nombre</th><th>Descripción</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>';

    for (const tipo of tipos) {
        html += `<tr>
            <td data-et="Nombre" class="td-titulo"><strong>${htmlEscape(tipo.nombre)}</strong></td>
            <td data-et="Descripción">${htmlEscape(tipo.descripcion || '')}</td>
            <td data-et="Estado"><span class="status-badge status-${tipo.estado}">${tipo.estado === 'activo' ? '✓ Activo' : '✗ Inactivo'}</span></td>
            <td class="actions acciones">
                <button class="btn btn-sm btn-primary" onclick="editarTipo(${tipo.id})">Editar</button>
                <button class="btn btn-sm btn-${tipo.estado === 'activo' ? 'danger' : 'warning'}" onclick="cambiarEstado(${tipo.id}, '${tipo.estado === 'activo' ? 'inactivo' : 'activo'}')">${tipo.estado === 'activo' ? 'Desactivar' : 'Activar'}</button>
            </td>
        </tr>`;
    }

    html += '</tbody></table></div>';
    document.getElementById('tabla-container').innerHTML = html;
}

async function editarTipo(id) {
    const r = await fetch(`../api/tipos.php?action=obtener&id=${id}`);
    const d = await r.json();

    if (!d.success) {
        mostrarAlerta('Error al obtener tipo', 'error');
        return;
    }

    const tipo = d.data;
    document.getElementById('t-id').value = tipo.id;
    document.getElementById('t-nombre').value = tipo.nombre;
    document.getElementById('t-descripcion').value = tipo.descripcion || '';
    document.getElementById('modalTitulo').textContent = 'Editar Tipo';
    abrirModal();
}

function abrirModalNuevo() {
    document.getElementById('t-id').value = '';
    document.getElementById('t-nombre').value = '';
    document.getElementById('t-descripcion').value = '';
    document.getElementById('modalTitulo').textContent = 'Nuevo Tipo';
    abrirModal();
}

function abrirModal() {
    document.getElementById('modalTipo').classList.add('open');
}

function cerrarModal() {
    document.getElementById('modalTipo').classList.remove('open');
    document.getElementById('modal-alert').innerHTML = '';
}

async function guardarTipo() {
    const id = document.getElementById('t-id').value;
    const nombre = document.getElementById('t-nombre').value.trim();
    const descripcion = document.getElementById('t-descripcion').value.trim();

    if (!nombre) {
        mostrarAlertaModal('El nombre es requerido', 'error');
        return;
    }

    const payload = { nombre, descripcion };
    const url = id ? `../api/tipos.php?action=editar` : `../api/tipos.php?action=crear`;
    if (id) payload.id = id;

    const r = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    });
    const d = await r.json();

    if (d.success) {
        cerrarModal();
        mostrarAlerta(id ? 'Tipo actualizado' : 'Tipo creado', 'success');
        cargarTipos();
    } else {
        mostrarAlertaModal(d.error || 'Error al guardar', 'error');
    }
}

async function cambiarEstado(id, nuevoEstado) {
    if (!confirm(`¿Cambiar estado a "${nuevoEstado}"?`)) return;

    const r = await fetch('../api/tipos.php?action=cambiar_estado', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id, estado: nuevoEstado })
    });
    const d = await r.json();

    if (d.success) {
        mostrarAlerta('Estado actualizado', 'success');
        cargarTipos();
    } else {
        mostrarAlerta(d.error || 'Error', 'error');
    }
}

function mostrarAlerta(msg, tipo) {
    const clase = tipo === 'error' ? 'alert-error' : 'alert-success';
    document.getElementById('alert-box').innerHTML = `<div class="alert ${clase}">${htmlEscape(msg)}</div>`;
}

function mostrarAlertaModal(msg, tipo) {
    const clase = tipo === 'error' ? 'alert-error' : 'alert-success';
    document.getElementById('modal-alert').innerHTML = `<div class="alert ${clase}">${htmlEscape(msg)}</div>`;
}

function htmlEscape(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

cargarTipos();
</script>

</body>
</html>
