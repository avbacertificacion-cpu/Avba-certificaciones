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
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Gestión de Usuarios</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        html{scroll-behavior:smooth}
        body{font-family:'Segoe UI',sans-serif;background:#f0f4ff;color:#333}

        .navbar{background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:#fff;padding:16px 20px;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;z-index:50;box-shadow:0 4px 12px rgba(102,126,234,.15)}
        .navbar h1{font-size:20px;font-weight:700}
        .navbar-right{display:flex;align-items:center;gap:16px}
        .navbar a{color:#fff;text-decoration:none;font-size:13px;transition:.2s}
        .navbar a:hover{opacity:.8}

        .container{max-width:1400px;margin:24px auto;padding:0 16px}

        .page-header{margin-bottom:28px}
        .page-header h2{font-size:28px;color:#333;margin-bottom:8px;display:flex;align-items:center;gap:12px}
        .page-header p{color:#888;font-size:14px}

        .toolbar{display:grid;grid-template-columns:1fr;gap:12px;margin-bottom:24px}
        @media(min-width:768px){.toolbar{grid-template-columns:1fr 1fr 1fr auto}}

        .toolbar input,.toolbar select{padding:12px 14px;border:2px solid #e0e0ff;border-radius:8px;font-size:14px;background:#fff;transition:.2s;width:100%}
        .toolbar input:focus,.toolbar select:focus{outline:none;border-color:#667eea;box-shadow:0 0 0 3px rgba(102,126,234,.1)}

        .btn{padding:12px 20px;border:none;border-radius:8px;cursor:pointer;font-weight:700;font-size:13px;transition:.2s;display:inline-flex;align-items:center;gap:8px;white-space:nowrap}
        .btn:active{transform:scale(.98)}
        .btn:disabled{opacity:.5;cursor:not-allowed}

        .btn-primary{background:#667eea;color:#fff}.btn-primary:hover{background:#5568d3;box-shadow:0 4px 12px rgba(102,126,234,.3)}
        .btn-success{background:#27ae60;color:#fff}.btn-success:hover{background:#1e8449;box-shadow:0 4px 12px rgba(39,174,96,.3)}
        .btn-warning{background:#f39c12;color:#fff}.btn-warning:hover{background:#d68910;box-shadow:0 4px 12px rgba(243,156,18,.3)}
        .btn-danger{background:#e74c3c;color:#fff}.btn-danger:hover{background:#c0392b;box-shadow:0 4px 12px rgba(231,76,60,.3)}
        .btn-sm{padding:8px 12px;font-size:12px}

        /* Tabla responsiva */
        .table-wrapper{background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.06)}
        table{width:100%;border-collapse:collapse}
        thead{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff}
        th{padding:14px 16px;text-align:left;font-weight:600;font-size:13px;letter-spacing:.3px}
        td{padding:14px 16px;font-size:13px;border-bottom:1px solid #f0f0f0}
        tbody tr{transition:.2s}
        tbody tr:hover{background:#f8faff}

        .badge{display:inline-block;padding:5px 12px;border-radius:20px;font-size:11px;font-weight:700;letter-spacing:.4px}
        .b-admin{background:#fce4e6;color:#c62828}
        .b-inspector{background:#e3f2fd;color:#1565c0}
        .b-cliente{background:#f3e5f5;color:#6a1b9a}
        .b-activo{background:#d4edda;color:#155724}
        .b-inactivo{background:#efefef;color:#666}

        .actions-cell{display:flex;gap:8px;flex-wrap:wrap}
        .actions-cell .btn{margin:0}

        .empty{text-align:center;padding:60px 20px;background:#fff;border-radius:12px;color:#aaa}
        .empty .icon{font-size:64px;margin-bottom:16px}
        .empty p{font-size:14px}

        /* Modal mejorado */
        .modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:100;justify-content:center;align-items:center;padding:20px;backdrop-filter:blur(2px)}
        .modal-overlay.open{display:flex}

        .modal{background:#fff;border-radius:16px;padding:32px;width:100%;max-width:600px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2)}
        .modal h2{margin-bottom:8px;color:#333;font-size:24px}
        .modal .subtitle{color:#888;font-size:13px;margin-bottom:24px}

        .form-row{display:grid;grid-template-columns:1fr;gap:16px}
        @media(min-width:600px){.form-row{grid-template-columns:1fr 1fr}}

        .form-group{margin-bottom:16px}
        .form-group label{display:block;font-size:13px;font-weight:700;color:#333;margin-bottom:8px;letter-spacing:.3px}
        .form-group input,.form-group select{width:100%;padding:12px 14px;border:2px solid #e0e0ff;border-radius:8px;font-size:14px;font-family:inherit;background:#fff;transition:.2s}
        .form-group input:focus,.form-group select:focus{outline:none;border-color:#667eea;box-shadow:0 0 0 3px rgba(102,126,234,.1);background:#fafbff}
        .form-group input:invalid{border-color:#e74c3c}

        .form-group.full{grid-column:1/-1}
        .form-group small{display:block;color:#888;font-size:12px;margin-top:6px}

        .pwd-strength{height:4px;background:#e0e0e0;border-radius:2px;margin-top:6px;overflow:hidden}
        .pwd-strength-bar{height:100%;width:0;background:#e74c3c;transition:.2s}

        .modal-actions{display:flex;gap:12px;justify-content:flex-end;margin-top:28px;padding-top:20px;border-top:1px solid #eee}

        .alert{padding:14px 16px;border-radius:8px;margin-bottom:16px;font-size:13px;border-left:4px solid;display:flex;gap:12px;align-items:flex-start}
        .alert-success{background:#ecf7ed;color:#155724;border-color:#27ae60}
        .alert-error{background:#fdf1f1;color:#721c24;border-color:#e74c3c}
        .alert-icon{font-size:18px;flex-shrink:0}

        /* Responsivo móvil */
        @media(max-width:768px){
            .navbar{padding:12px 16px}
            .navbar h1{font-size:18px}
            .navbar-right{flex-direction:column;align-items:flex-start;gap:8px}

            .page-header h2{font-size:22px}

            .toolbar{grid-template-columns:1fr}

            th,td{padding:12px 12px;font-size:12px}

            .actions-cell{width:100%;margin-top:8px}
            .actions-cell .btn{flex:1;justify-content:center}

            .modal{padding:24px;max-width:calc(100% - 40px)}
            .modal h2{font-size:20px}

            table{font-size:12px}
            .b-admin,.b-inspector,.b-cliente,.b-activo,.b-inactivo{padding:4px 8px;font-size:10px}
        }

        /* Animaciones */
        @keyframes slideIn{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
        .modal-overlay.open .modal{animation:slideIn .3s ease-out}

        /* Indicador de carga */
        .loading{opacity:.6;pointer-events:none}
        .spinner{display:inline-block;width:14px;height:14px;border:2px solid #ddd;border-top-color:#667eea;border-radius:50%;animation:spin .6s linear infinite}
        @keyframes spin{to{transform:rotate(360deg)}}
    </style>
</head>
<body>
<div class="navbar">
    <h1>👥 Gestión de Usuarios</h1>
    <div class="navbar-right">
        <a href="admin-dashboard.php">← Volver al panel</a>
        <span style="font-size:13px;opacity:.9"><?= htmlspecialchars($nombre) ?></span>
    </div>
</div>

<div class="container">
    <div class="page-header">
        <h2>👥 Usuarios del Sistema</h2>
        <p>Administra roles, permisos y acceso de usuarios</p>
    </div>

    <div id="alert-box"></div>

    <div class="toolbar">
        <input type="text" id="busqueda" placeholder="🔍 Buscar por nombre o email…" oninput="filtrar()">
        <select id="filtroRol" onchange="filtrar()">
            <option value="">📋 Todos los roles</option>
            <option value="administrador">🔐 Administrador</option>
            <option value="gerente">📊 Gerente</option>
            <option value="inspector">🔍 Inspector</option>
            <option value="cliente">👤 Cliente</option>
        </select>
        <select id="filtroEstado" onchange="filtrar()">
            <option value="">📊 Todos los estados</option>
            <option value="activo">✓ Activo</option>
            <option value="inactivo">✕ Inactivo</option>
        </select>
        <button class="btn btn-primary" onclick="abrirModalNuevo()">+ Nuevo usuario</button>
    </div>

    <div class="table-wrapper">
        <div id="tabla"></div>
    </div>
</div>

<!-- Modal Crear / Editar -->
<div class="modal-overlay" id="modalUsuario" onclick="if(event.target===this) cerrarModal()">
    <div class="modal">
        <h2 id="modalTitulo">Nuevo Usuario</h2>
        <p class="subtitle" id="modalSubtitulo">Agrega un nuevo usuario al sistema</p>
        <div id="modal-alert"></div>
        <input type="hidden" id="u-id">

        <div class="form-row">
            <div class="form-group">
                <label>Nombre completo *</label>
                <input type="text" id="u-nombre" placeholder="Ej: Juan Pérez García" required>
            </div>
            <div class="form-group">
                <label>Usuario (para login) *</label>
                <input type="text" id="u-username" placeholder="Ej: juanperez" autocomplete="off" required>
                <small>Sin espacios ni caracteres especiales</small>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group full">
                <label>Email</label>
                <input type="email" id="u-email" placeholder="juan@empresa.com (opcional)">
                <small>El login se hace con usuario y contraseña, no con email</small>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>Rol *</label>
                <select id="u-rol" onchange="actualizarVisibilidadEmpresa()" required>
                    <option value="administrador">🔐 Administrador</option>
                    <option value="gerente">📊 Gerente</option>
                    <option value="inspector">🔍 Inspector</option>
                    <option value="cliente">👤 Cliente</option>
                </select>
            </div>
            <div class="form-group" id="empresa-group" style="display:none">
                <label>Empresa (solo para Clientes) *</label>
                <select id="u-empresa"></select>
                <small>Los inspectores trabajan para AVBA, los clientes para empresas específicas</small>
            </div>
        </div>

        <div class="form-row" id="gerente-group" style="display:none">
            <div class="form-group" style="flex:1">
                <label>Clientes asignados al gerente</label>
                <div id="gerente-empresas-list" style="max-height:190px;overflow-y:auto;border:2px solid #e0e0ff;border-radius:8px;padding:10px;display:flex;flex-direction:column;gap:6px"></div>
                <small>Marca las plantas/clientes que este gerente podrá ver (solo lectura).</small>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>Contraseña <span id="pwd-requerida">*</span></label>
                <input type="password" id="u-password" placeholder="Mínimo 8 caracteres" autocomplete="new-password">
                <div class="pwd-strength"><div class="pwd-strength-bar" id="pwd-bar"></div></div>
                <small id="pwd-nota"></small>
            </div>
            <div class="form-group">
                <label>Estado</label>
                <select id="u-estado">
                    <option value="activo">✓ Activo</option>
                    <option value="inactivo">✕ Inactivo</option>
                </select>
                <small>Usuarios inactivos no pueden acceder al sistema</small>
            </div>
        </div>

        <div class="modal-actions">
            <button class="btn btn-warning" onclick="cerrarModal()">Cancelar</button>
            <button class="btn btn-primary" id="btnGuardar" onclick="guardarUsuario()">Guardar usuario</button>
        </div>
    </div>
</div>

<script>
let usuarios = [];
let empresas  = [];

async function init() {
    await Promise.all([cargarEmpresas(), cargarUsuarios()]);
    document.getElementById('u-password').addEventListener('input', actualizarFuerzaPassword);
}

async function cargarEmpresas() {
    try {
        const r = await fetch('../api/usuarios.php?action=listar_empresas');
        if (!r.ok) throw new Error('Error en servidor');
        const d = await r.json();
        if (d.success) {
            empresas = d.data;
            const sel = document.getElementById('u-empresa');
            sel.innerHTML = '<option value="">Sin asignar</option>' +
                empresas.map(e => `<option value="${sanitize(e.id)}">${sanitize(e.nombre)}</option>`).join('');
            // Lista de casillas para asignar clientes a un gerente
            const lista = document.getElementById('gerente-empresas-list');
            if (lista) {
                lista.innerHTML = empresas.map(e =>
                    `<label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer">
                        <input type="checkbox" class="ger-emp" value="${sanitize(e.id)}" style="width:16px;height:16px">
                        ${sanitize(e.nombre)}
                    </label>`).join('');
            }
        }
    } catch(e) {
        showAlert('Error cargando empresas: ' + e.message, 'error');
    }
}

async function cargarUsuarios() {
    try {
        const r = await fetch('../api/usuarios.php?action=listar');
        if (!r.ok) throw new Error('Error en servidor');
        const d = await r.json();
        if (d.success) {
            usuarios = d.data;
            render(usuarios);
        }
    } catch(e) {
        showAlert('Error cargando usuarios: ' + e.message, 'error');
    }
}

function render(data) {
    const c = document.getElementById('tabla');
    if (!data.length) {
        c.innerHTML = `<div class="empty"><div class="icon">👥</div><p>No hay usuarios aún. Crea el primero para comenzar.</p></div>`;
        return;
    }

    c.innerHTML = `
    <table>
        <thead><tr>
            <th>Nombre</th><th>Usuario</th><th>Rol</th><th>Empresa</th><th>Estado</th><th style="text-align:right">Acciones</th>
        </tr></thead>
        <tbody>
        ${data.map(u => `<tr>
            <td><strong>${sanitize(u.nombre)}</strong></td>
            <td><code style="background:#f0f0f0;padding:2px 6px;border-radius:4px;font-size:12px">${sanitize(u.username)}</code></td>
            <td><span class="badge b-${sanitize(u.rol)}">${rolLabel(u.rol)}</span></td>
            <td>${sanitize(u.empresa_nombre || '—')}</td>
            <td><span class="badge b-${sanitize(u.estado)}">${estadoLabel(u.estado)}</span></td>
            <td style="text-align:right">
                <div class="actions-cell">
                    <button class="btn btn-sm btn-warning" onclick="editarUsuario(${parseInt(u.id)})" title="Editar usuario">✏️ Editar</button>
                    <button class="btn btn-sm btn-danger" onclick="eliminarUsuario(${parseInt(u.id)})" title="Desactivar usuario">🗑️</button>
                </div>
            </td>
        </tr>`).join('')}
        </tbody>
    </table>`;
}

function sanitize(str) {
    if (typeof str !== 'string') return String(str);
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function rolLabel(r) {
    const labels = {administrador:'🔐 Admin', gerente:'📊 Gerente', inspector:'🔍 Inspector', cliente:'👤 Cliente'};
    return labels[r] || r;
}

function estadoLabel(e) {
    return {activo:'✓ Activo', inactivo:'✕ Inactivo'}[e] || e;
}

function filtrar() {
    const q = document.getElementById('busqueda').value.toLowerCase();
    const r = document.getElementById('filtroRol').value;
    const e = document.getElementById('filtroEstado').value;

    render(usuarios.filter(u =>
        (!q || u.nombre.toLowerCase().includes(q) || (u.username||'').toLowerCase().includes(q) || (u.email||'').toLowerCase().includes(q)) &&
        (!r || u.rol === r) &&
        (!e || u.estado === e)
    ));
}

function abrirModalNuevo() {
    limpiarModal();
    document.getElementById('modalTitulo').textContent = 'Nuevo Usuario';
    document.getElementById('modalSubtitulo').textContent = 'Agrega un nuevo usuario al sistema';
    document.getElementById('u-password').value = '';
    document.getElementById('pwd-requerida').textContent = '*';
    document.getElementById('pwd-nota').textContent = '';
    document.getElementById('u-nombre').focus();
    document.getElementById('modalUsuario').classList.add('open');
}

function editarUsuario(id) {
    const u = usuarios.find(x => x.id == id);
    if (!u) return;
    limpiarModal();
    document.getElementById('modalTitulo').textContent = 'Editar Usuario';
    document.getElementById('modalSubtitulo').textContent = 'Modifica los datos de este usuario';
    document.getElementById('u-id').value = u.id;
    document.getElementById('u-nombre').value = u.nombre;
    document.getElementById('u-username').value = u.username;
    document.getElementById('u-email').value = u.email || '';
    document.getElementById('u-rol').value = u.rol;
    document.getElementById('u-empresa').value = u.empresa_id || '';
    document.getElementById('u-estado').value = u.estado;
    document.getElementById('u-password').value = '';
    document.getElementById('pwd-requerida').textContent = '';
    document.getElementById('pwd-nota').textContent = 'ⓘ Dejar vacío para no cambiar';
    document.getElementById('pwd-bar').style.width = '0';
    actualizarVisibilidadEmpresa();
    marcarEmpresasGerente([]);
    if (u.rol === 'gerente') {
        fetch(`../api/gerente.php?action=empresas_de_gerente&gerente_id=${u.id}`)
            .then(r => r.json())
            .then(d => { if (d.success) marcarEmpresasGerente(d.data); })
            .catch(() => {});
    }
    document.getElementById('modalUsuario').classList.add('open');
}

function limpiarModal() {
    document.getElementById('u-id').value = '';
    document.getElementById('u-nombre').value = '';
    document.getElementById('u-username').value = '';
    document.getElementById('u-email').value = '';
    document.getElementById('u-rol').value = 'administrador';
    document.getElementById('u-empresa').value = '';
    document.getElementById('u-password').value = '';
    document.getElementById('u-estado').value = 'activo';
    document.getElementById('modal-alert').innerHTML = '';
    document.getElementById('pwd-bar').style.width = '0';
    marcarEmpresasGerente([]);
}

function cerrarModal() {
    document.getElementById('modalUsuario').classList.remove('open');
    limpiarModal();
}

function actualizarVisibilidadEmpresa() {
    const rol = document.getElementById('u-rol').value;
    document.getElementById('empresa-group').style.display = (rol === 'cliente') ? 'block' : 'none';
    document.getElementById('gerente-group').style.display = (rol === 'gerente') ? 'flex' : 'none';
}

function marcarEmpresasGerente(ids) {
    const set = new Set((ids || []).map(Number));
    document.querySelectorAll('.ger-emp').forEach(c => { c.checked = set.has(Number(c.value)); });
}

function actualizarFuerzaPassword() {
    const pwd = document.getElementById('u-password').value;
    const bar = document.getElementById('pwd-bar');
    let fuerza = 0;
    let nota = '';

    if (pwd.length >= 8) fuerza += 25;
    if (pwd.length >= 12) fuerza += 25;
    if (/[a-z]/.test(pwd) && /[A-Z]/.test(pwd)) fuerza += 25;
    if (/\d/.test(pwd)) fuerza += 12;
    if (/[!@#$%^&*]/.test(pwd)) fuerza += 13;

    if (pwd.length === 0) {
        bar.style.width = '0';
        nota = '';
    } else if (fuerza < 50) {
        bar.style.background = '#e74c3c';
        bar.style.width = Math.min(fuerza, 33) + '%';
        nota = '⚠ Contraseña débil';
    } else if (fuerza < 80) {
        bar.style.background = '#f39c12';
        bar.style.width = Math.min(fuerza, 66) + '%';
        nota = '✓ Contraseña regular';
    } else {
        bar.style.background = '#27ae60';
        bar.style.width = '100%';
        nota = '✓ Contraseña fuerte';
    }

    document.getElementById('pwd-nota').textContent = nota;
}

async function guardarUsuario() {
    const id       = document.getElementById('u-id').value;
    const nombre   = document.getElementById('u-nombre').value.trim();
    const username = document.getElementById('u-username').value.trim().toLowerCase();
    const email    = document.getElementById('u-email').value.trim().toLowerCase();
    const rol      = document.getElementById('u-rol').value;
    const empresa  = document.getElementById('u-empresa').value;
    const password = document.getElementById('u-password').value;
    const estado   = document.getElementById('u-estado').value;
    const btn      = document.getElementById('btnGuardar');

    if (!nombre || nombre.length < 3) {
        modalAlert('El nombre debe tener al menos 3 caracteres', 'error');
        document.getElementById('u-nombre').focus();
        return;
    }

    if (!username || username.length < 3 || !/^[a-z0-9_]+$/.test(username)) {
        modalAlert('El usuario debe tener al menos 3 caracteres (solo letras, números y _)', 'error');
        document.getElementById('u-username').focus();
        return;
    }

    if (email && !validarEmail(email)) {
        modalAlert('El email ingresado no es válido', 'error');
        document.getElementById('u-email').focus();
        return;
    }

    if (!id && !password) {
        modalAlert('La contraseña es requerida para usuarios nuevos', 'error');
        document.getElementById('u-password').focus();
        return;
    }

    if (password && password.length < 8) {
        modalAlert('La contraseña debe tener mínimo 8 caracteres', 'error');
        document.getElementById('u-password').focus();
        return;
    }

    if (rol === 'cliente' && !empresa) {
        modalAlert('Debes seleccionar una empresa para clientes', 'error');
        document.getElementById('u-empresa').focus();
        return;
    }

    btn.classList.add('loading');
    btn.innerHTML = '<span class="spinner"></span> Guardando...';

    try {
        const body = { nombre, username, email: email || null, rol, estado };
        if (rol === 'cliente') body.empresa_id = empresa || null;
        if (password) body.password = password;
        if (id) body.id = parseInt(id);

        const action = id ? 'editar' : 'crear';
        const r = await fetch(`../api/usuarios.php?action=${action}`, {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify(body),
            credentials: 'same-origin'
        });

        // Leer el cuerpo SIEMPRE para poder mostrar el mensaje real del servidor
        const d = await r.json().catch(() => ({}));

        if (r.ok && d.success) {
            // Guardar asignación de clientes si es gerente
            const gid = id ? parseInt(id) : (d.id ? parseInt(d.id) : 0);
            if (rol === 'gerente' && gid) {
                const empIds = [...document.querySelectorAll('.ger-emp:checked')].map(c => parseInt(c.value));
                try {
                    await fetch('../api/gerente.php?action=guardar_asignacion', {
                        method: 'POST',
                        headers: {'Content-Type':'application/json'},
                        body: JSON.stringify({ gerente_id: gid, empresas: empIds })
                    });
                } catch(e) { /* el usuario se creó; la asignación se puede reintentar editando */ }
            }
            cerrarModal();
            showAlert(`✓ Usuario ${id ? 'actualizado' : 'creado'} correctamente`, 'success');
            await cargarUsuarios();
        } else {
            modalAlert(d.error || `Error al guardar usuario (${r.status})`, 'error');
        }
    } catch(e) {
        modalAlert('Error de conexión: ' + e.message, 'error');
    } finally {
        btn.classList.remove('loading');
        btn.innerHTML = 'Guardar usuario';
    }
}

async function eliminarUsuario(id) {
    const usuario = usuarios.find(u => u.id === id);
    if (!usuario) return;

    if (!confirm(`¿Estás seguro de que deseas desactivar a "${usuario.nombre}"?`)) return;

    try {
        const r = await fetch(`../api/usuarios.php?action=eliminar&id=${parseInt(id)}`);
        const d = await r.json().catch(() => ({}));

        if (r.ok && d.success) {
            showAlert(`✓ Usuario "${usuario.nombre}" desactivado correctamente`, 'success');
            await cargarUsuarios();
        } else {
            showAlert(d.error || `Error al desactivar usuario (${r.status})`, 'error');
        }
    } catch(e) {
        showAlert('Error de conexión: ' + e.message, 'error');
    }
}

function validarEmail(email) {
    const regex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return regex.test(email);
}

function showAlert(msg, tipo) {
    const b = document.getElementById('alert-box');
    const icon = tipo === 'success' ? '✓' : '✕';
    b.innerHTML = `<div class="alert alert-${tipo}"><span class="alert-icon">${icon}</span><span>${msg}</span></div>`;
    setTimeout(() => b.innerHTML = '', 5000);
}

function modalAlert(msg, tipo) {
    const icon = tipo === 'success' ? '✓' : '⚠';
    document.getElementById('modal-alert').innerHTML = `<div class="alert alert-${tipo}"><span class="alert-icon">${icon}</span><span>${msg}</span></div>`;
}

init();
</script>
</body>
</html>
