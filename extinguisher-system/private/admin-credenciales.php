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
    <title>Generar Credenciales</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Segoe UI',sans-serif;background:#f0f4ff;color:#333}

        .navbar{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;padding:16px 20px;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;z-index:50;box-shadow:0 4px 12px rgba(102,126,234,.15)}
        .navbar h1{font-size:20px;font-weight:700}
        .navbar a{color:#fff;text-decoration:none;font-size:13px;margin-left:16px}
        .navbar a:hover{opacity:.8}

        .container{max-width:1000px;margin:32px auto;padding:0 16px}

        .page-header{margin-bottom:28px}
        .page-header h2{font-size:28px;color:#333;margin-bottom:8px}
        .page-header p{color:#888;font-size:14px}

        .card{background:#fff;border-radius:12px;padding:24px;box-shadow:0 2px 8px rgba(0,0,0,.06);margin-bottom:24px}

        .section-title{font-size:16px;font-weight:700;color:#333;margin-bottom:16px;border-bottom:2px solid #667eea;padding-bottom:8px}

        .usuarios-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:16px;margin-top:16px}

        .usuario-card{background:#f9fafb;border:2px solid #e5e7eb;border-radius:8px;padding:16px;cursor:pointer;transition:.2s;display:flex;align-items:center;gap:12px}
        .usuario-card:hover{background:#f0f3ff;border-color:#667eea}
        .usuario-card input[type="checkbox"]{width:20px;height:20px;cursor:pointer}
        .usuario-info{flex:1}
        .usuario-info strong{display:block;color:#333}
        .usuario-info small{color:#888;font-size:12px}

        .btn{padding:12px 20px;border:none;border-radius:8px;cursor:pointer;font-weight:700;font-size:13px;transition:.2s;display:inline-flex;align-items:center;gap:8px}
        .btn:active{transform:scale(.98)}
        .btn:disabled{opacity:.5;cursor:not-allowed}

        .btn-primary{background:#667eea;color:#fff}.btn-primary:hover{background:#5568d3}
        .btn-success{background:#27ae60;color:#fff}.btn-success:hover{background:#1e8449}
        .btn-warning{background:#f39c12;color:#fff}.btn-warning:hover{background:#d68910}
        .btn-danger{background:#e74c3c;color:#fff}.btn-danger:hover{background:#c0392b}

        .toolbar{display:flex;gap:12px;margin-bottom:24px;flex-wrap:wrap}

        .alert{padding:12px;border-radius:6px;margin-bottom:16px;font-size:13px}
        .alert-success{background:#d4edda;border:1px solid #c3e6cb;color:#155724}
        .alert-error{background:#f8d7da;border:1px solid #f5c6cb;color:#721c24}
        .alert-info{background:#d1ecf1;border:1px solid #bee5eb;color:#0c5460}

        .stats{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;margin-bottom:24px}
        .stat-box{background:#f0f3ff;padding:16px;border-radius:8px;text-align:center}
        .stat-label{font-size:11px;color:#667eea;font-weight:700;text-transform:uppercase;margin-bottom:4px}
        .stat-value{font-size:24px;font-weight:700;color:#333}

        .filters{display:flex;gap:12px;margin-bottom:24px;flex-wrap:wrap}
        .filters input,.filters select{padding:10px 14px;border:1px solid #ddd;border-radius:6px;font-size:14px}

        @media(max-width:768px){
            .usuarios-list{grid-template-columns:1fr}
            .navbar h1{font-size:18px}
            .page-header h2{font-size:22px}
        }
    </style>
</head>
<body>

<div class="navbar">
    <h1>📋 Generar Credenciales</h1>
    <div>
        <a href="admin-dashboard.php">← Panel</a>
        <span style="margin-left:16px;font-size:13px"><?= htmlspecialchars($nombre) ?></span>
    </div>
</div>

<div class="container">
    <div class="page-header">
        <h2>📋 Generar Credenciales PDF</h2>
        <p>Selecciona usuarios para generar sus credenciales en PDF para imprimir</p>
    </div>

    <div id="alert-box"></div>

    <div class="card">
        <div class="section-title">👥 Selecciona usuarios</div>

        <div class="filters">
            <input type="text" id="filtro" placeholder="Buscar por nombre, email…" oninput="filtrarUsuarios()">
            <select id="filtroRol" onchange="filtrarUsuarios()">
                <option value="">Todos los roles</option>
                <option value="inspector">Inspector</option>
                <option value="administrador">Administrador</option>
                <option value="cliente">Cliente</option>
            </select>
        </div>

        <div class="stats">
            <div class="stat-box">
                <div class="stat-label">Total Usuarios</div>
                <div class="stat-value" id="totalUsuarios">0</div>
            </div>
            <div class="stat-box">
                <div class="stat-label">Seleccionados</div>
                <div class="stat-value" id="seleccionados">0</div>
            </div>
        </div>

        <div class="toolbar">
            <button class="btn btn-success" onclick="seleccionarTodos()">✓ Seleccionar todos</button>
            <button class="btn btn-warning" onclick="deseleccionarTodos()">✗ Deseleccionar todos</button>
            <button class="btn btn-primary" onclick="generarCredenciales()" id="btnGenerar" disabled>
                📄 Generar PDF(s)
            </button>
        </div>

        <div class="usuarios-list" id="usuariosList"></div>
    </div>
</div>

<script>
let usuariosTotal = [];
let usuariosFiltrados = [];

async function cargarUsuarios() {
    const r = await fetch('../api/usuarios.php?action=listar');
    const d = await r.json();

    if (d.success) {
        usuariosTotal = d.data;
        document.getElementById('totalUsuarios').textContent = usuariosTotal.length;
        renderUsuarios(usuariosTotal);
    }
}

function renderUsuarios(usuarios) {
    const html = usuarios.map(u => `
        <div class="usuario-card" onclick="toggleUsuario(this, ${u.id})">
            <input type="checkbox" class="usuario-check" data-id="${u.id}" onchange="actualizarSeleccionados()">
            <div class="usuario-info">
                <strong>${htmlEscape(u.nombre)}</strong>
                <small>${u.email || '—'}</small>
                <small style="color:#667eea">${u.rol}</small>
            </div>
        </div>
    `).join('');

    document.getElementById('usuariosList').innerHTML = html;
}

function toggleUsuario(el, id) {
    const checkbox = el.querySelector('.usuario-check');
    checkbox.checked = !checkbox.checked;
    actualizarSeleccionados();
}

function filtrarUsuarios() {
    const texto = document.getElementById('filtro').value.toLowerCase();
    const rol = document.getElementById('filtroRol').value;

    usuariosFiltrados = usuariosTotal.filter(u =>
        (!texto || u.nombre.toLowerCase().includes(texto) || u.email.toLowerCase().includes(texto)) &&
        (!rol || u.rol === rol)
    );

    renderUsuarios(usuariosFiltrados);
}

function seleccionarTodos() {
    document.querySelectorAll('.usuario-check').forEach(cb => cb.checked = true);
    actualizarSeleccionados();
}

function deseleccionarTodos() {
    document.querySelectorAll('.usuario-check').forEach(cb => cb.checked = false);
    actualizarSeleccionados();
}

function actualizarSeleccionados() {
    const count = document.querySelectorAll('.usuario-check:checked').length;
    document.getElementById('seleccionados').textContent = count;
    document.getElementById('btnGenerar').disabled = count === 0;
}

async function generarCredenciales() {
    const seleccionados = Array.from(document.querySelectorAll('.usuario-check:checked'))
        .map(cb => parseInt(cb.dataset.id));

    if (seleccionados.length === 0) {
        mostrarAlerta('Selecciona al menos un usuario', 'error');
        return;
    }

    const btnGenerar = document.getElementById('btnGenerar');
    btnGenerar.disabled = true;
    btnGenerar.textContent = '⏳ Generando…';

    try {
        // Si es un solo usuario, genera PDF directo
        if (seleccionados.length === 1) {
            window.open(`../api/credencial_pdf.php?usuario_id=${seleccionados[0]}`, '_blank');
        } else {
            // Si son varios, genera un ZIP
            const r = await fetch('../api/credenciales_zip.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({usuario_ids: seleccionados})
            });

            if (r.ok) {
                const blob = await r.blob();
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = `credenciales_${new Date().getTime()}.zip`;
                a.click();
                mostrarAlerta(`✅ ${seleccionados.length} credencial(es) generada(s)`, 'success');
            } else {
                mostrarAlerta('Error generando credenciales', 'error');
            }
        }
    } catch(e) {
        mostrarAlerta('Error: ' + e.message, 'error');
    } finally {
        btnGenerar.disabled = false;
        btnGenerar.textContent = '📄 Generar PDF(s)';
    }
}

function mostrarAlerta(msg, tipo) {
    const clase = tipo === 'error' ? 'alert-error' : (tipo === 'success' ? 'alert-success' : 'alert-info');
    document.getElementById('alert-box').innerHTML = `<div class="alert ${clase}">${htmlEscape(msg)}</div>`;
}

function htmlEscape(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

cargarUsuarios();
</script>

</body>
</html>
