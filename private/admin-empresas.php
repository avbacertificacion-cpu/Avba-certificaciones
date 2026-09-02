<?php
require_once '../config/config.php';
require_once '../config/empresa-config.php';
require_once '../config/emisor.php';
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== ROLE_ADMIN) {
    header('Location: ../public/login.html'); exit;
}
$nombre = $_SESSION['nombre'];

// Crea la columna de preferencia la primera vez que un admin entra aquí
asegurarColumnaAlertas($pdo);
asegurarColumnaFotos($pdo);
asegurarColumnasFiscales($pdo);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Empresas</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        html{scroll-behavior:smooth}
        body{font-family:'Segoe UI',sans-serif;background:#f0f4ff;color:#333}

        .navbar{background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:#fff;padding:16px 20px;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;z-index:50;box-shadow:0 4px 12px rgba(102,126,234,.15)}
        .navbar h1{font-size:20px;font-weight:700}
        .navbar a{color:#fff;text-decoration:none;font-size:13px;transition:.2s}
        .navbar a:hover{opacity:.8}

        .container{max-width:1300px;margin:24px auto;padding:0 16px}

        .page-header{margin-bottom:28px}
        .page-header h2{font-size:28px;color:#333;margin-bottom:8px}
        .page-header p{color:#888;font-size:14px}

        .toolbar{margin-bottom:24px}
        .btn{padding:12px 20px;border:none;border-radius:8px;cursor:pointer;font-weight:700;font-size:13px;transition:.2s;display:inline-flex;align-items:center;gap:8px}
        .btn:active{transform:scale(.98)}
        .btn:disabled{opacity:.5;cursor:not-allowed}

        .btn-primary{background:#667eea;color:#fff}.btn-primary:hover{background:#5568d3;box-shadow:0 4px 12px rgba(102,126,234,.3)}
        .btn-warning{background:#f39c12;color:#fff}.btn-warning:hover{background:#d68910;box-shadow:0 4px 12px rgba(243,156,18,.3)}
        .btn-danger{background:#e74c3c;color:#fff}.btn-danger:hover{background:#c0392b;box-shadow:0 4px 12px rgba(231,76,60,.3)}
        .btn-sm{padding:8px 12px;font-size:12px}

        .empresa-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:20px;margin-top:20px}
        .empresa-card{background:#fff;border-radius:12px;padding:20px;box-shadow:0 2px 8px rgba(0,0,0,.06);border-left:5px solid #667eea;display:flex;flex-direction:column;transition:.2s}
        .empresa-card:hover{box-shadow:0 8px 20px rgba(0,0,0,.1);transform:translateY(-4px)}

        .empresa-card h3{color:#333;margin-bottom:12px;font-size:16px;font-weight:700;word-break:break-word}
        .empresa-info{flex:1;margin-bottom:16px;font-size:13px;color:#666}
        .empresa-info p{margin-bottom:8px;line-height:1.5}
        .empresa-info strong{color:#333;display:block;font-size:12px;text-transform:uppercase;letter-spacing:.5px;margin-bottom:2px}
        .empresa-actions{display:flex;gap:8px;margin-top:auto}
        .empresa-actions .btn{flex:1;justify-content:center}

        .empty{text-align:center;padding:60px 20px;background:#fff;border-radius:12px;color:#aaa}
        .empty .icon{font-size:64px;margin-bottom:16px}

        @media(max-width:768px){
            .navbar h1{font-size:18px}
            .page-header h2{font-size:22px}
            .empresa-grid{grid-template-columns:1fr}
            .btn{width:100%;justify-content:center}
            .empresa-actions{flex-direction:column}
        }

        /* Modal */
        .modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100;justify-content:center;align-items:center;padding:16px}
        .modal-overlay.open{display:flex}
        .modal{background:#fff;border-radius:12px;padding:32px;width:100%;max-width:500px;max-height:90vh;overflow-y:auto;box-shadow:0 10px 40px rgba(0,0,0,.2)}
        .modal h2{margin-bottom:24px}
        .form-group{margin-bottom:16px}
        .form-group label{display:block;font-size:13px;font-weight:700;color:#444;margin-bottom:6px}
        .form-group input,.form-group textarea,.form-group select{width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;font-size:14px;font-family:inherit}
        .form-group textarea{resize:vertical;min-height:80px}
        .modal-actions{display:flex;gap:12px;justify-content:flex-end;margin-top:20px;padding-top:18px;border-top:1px solid #eee;flex-shrink:0}
        .alert{padding:12px;border-radius:6px;margin-bottom:14px;font-size:13px}
        .alert-success{background:#d4edda;color:#155724}
        .alert-error{background:#f8d7da;color:#721c24}

        @media (max-width:600px) {
            .modal{padding:20px;max-width:calc(100% - 32px)}
            .modal h2{font-size:18px;margin-bottom:16px}
        }
    </style>
</head>
<body>
<div class="navbar">
    <h1>🏢 Empresas</h1>
    <div>
        <a href="admin-dashboard.php">← Volver</a>
        <span style="margin-left:16px;font-size:13px"><?= htmlspecialchars($nombre) ?></span>
    </div>
</div>

<div class="container">
    <div class="page-header">
        <h2>🏢 Gestión de Empresas</h2>
        <p>Administra las empresas clientes del sistema</p>
    </div>

    <div id="alert-box"></div>

    <div class="toolbar">
        <button class="btn btn-primary" onclick="abrirModalNuevo()">+ Nueva empresa</button>
    </div>

    <div id="grid"></div>
</div>

<!-- Modal Crear / Editar -->
<div class="modal-overlay" id="modalEmpresa" onclick="if(event.target===this) cerrarModal()">
    <div class="modal">
        <h2 id="modalTitulo">Nueva Empresa</h2>
        <p class="subtitle" id="modalSubtitulo">Agrega una nueva empresa al sistema</p>
        <div id="modal-alert"></div>
        <input type="hidden" id="e-id">

        <div class="form-group">
            <label>Nombre de la empresa *</label>
            <input type="text" id="e-nombre" placeholder="Ej: Quantum Energía S.A." required>
            <small>Nombre oficial de la empresa</small>
        </div>

        <div class="form-group">
            <label>RFC</label>
            <input type="text" id="e-rfc" placeholder="XXXXXXXXXXXXXXX" maxlength="13">
            <small>Registro Federal de Contribuyentes (13 caracteres)</small>
        </div>

        <div class="form-group">
            <label>Régimen fiscal</label>
            <select id="e-regimen">
                <option value="">— Sin especificar —</option>
                <?php foreach (catRegimenFiscal() as $clave => $desc): ?>
                    <option value="<?= $clave ?>"><?= $clave ?> - <?= htmlspecialchars($desc) ?></option>
                <?php endforeach; ?>
            </select>
            <small>Aparece en el presupuesto que se le envía al cliente</small>
        </div>

        <div class="form-group">
            <label>Código postal fiscal</label>
            <input type="text" id="e-cp" placeholder="89600" maxlength="10">
            <small>Domicilio fiscal registrado ante el SAT</small>
        </div>

        <div class="form-group">
            <label>Email</label>
            <input type="email" id="e-email" placeholder="contacto@empresa.com">
            <small>Email de contacto principal</small>
        </div>

        <div class="form-group">
            <label>Teléfono</label>
            <input type="tel" id="e-telefono" placeholder="+52 1234 567 890">
            <small>Número telefónico con código de país</small>
        </div>

        <div class="form-group">
            <label>Persona de contacto</label>
            <input type="text" id="e-contacto" placeholder="Nombre completo del responsable">
            <small>Quien será el representante de la empresa</small>
        </div>

        <div class="form-group">
            <label>Domicilio</label>
            <textarea id="e-domicilio" placeholder="Calle, número, ciudad, estado, código postal…" style="resize:vertical;min-height:80px"></textarea>
            <small>Dirección fiscal completa</small>
        </div>

        <div class="form-group" style="background:#f7f9fc;border:1.5px solid #e0e0ff;border-radius:8px;padding:14px">
            <label style="display:flex;align-items:center;gap:10px;cursor:pointer;margin:0">
                <input type="checkbox" id="e-alertas" checked style="width:18px;height:18px;cursor:pointer">
                <span>Mostrar alertas y vencimientos en el panel del cliente</span>
            </label>
            <small style="margin-top:8px;display:block">
                Si lo desactivas, el cliente de esta empresa no verá la sección
                «Alertas y Vencimientos» ni la tabla «Próximos Vencimientos».
                Útil cuando las fechas de prueba hidrostática aún no están depuradas.
            </small>
        </div>

        <div class="form-group" style="background:#f7f9fc;border:1.5px solid #e0e0ff;border-radius:8px;padding:14px">
            <label style="display:flex;align-items:center;gap:10px;cursor:pointer;margin:0">
                <input type="checkbox" id="e-fotos" style="width:18px;height:18px;cursor:pointer">
                <span>Solicitar evidencia fotográfica al generar el reporte</span>
            </label>
            <small style="margin-top:8px;display:block">
                Si lo activas, al crear un reporte de esta planta se pedirán hasta
                9 fotografías, que se incluirán al final del reporte.
            </small>
        </div>

        <div class="modal-actions">
            <button class="btn btn-warning" onclick="cerrarModal()">Cancelar</button>
            <button class="btn btn-primary" onclick="guardarEmpresa()">Guardar empresa</button>
        </div>
    </div>
</div>

<script>
let empresas = [];

// ── Cargar ────────────────────────────────────────────────────────────────────
async function cargar() {
    const r = await fetch('../api/usuarios.php?action=listar_empresas');
    const d = await r.json();
    if (d.success) {
        empresas = d.data;
        render(empresas);
    }
}

// ── Render ────────────────────────────────────────────────────────────────────
function render(data) {
    const c = document.getElementById('grid');
    if (!data.length) {
        c.innerHTML = '<div class="empty"><div class="icon">🏢</div><p>No hay empresas aún. Crea la primera para comenzar.</p></div>';
        return;
    }

    c.innerHTML = `
    <div class="empresa-grid">
    ${data.map(e => `
        <div class="empresa-card">
            <h3>${sanitize(e.nombre)}</h3>
            <div class="empresa-info">
                ${e.rfc ? `<p><strong>RFC</strong> ${sanitize(e.rfc)}</p>` : ''}
                ${e.email ? `<p><strong>Email</strong> ${sanitize(e.email)}</p>` : ''}
                ${e.telefono ? `<p><strong>Teléfono</strong> ${sanitize(e.telefono)}</p>` : ''}
                ${e.contacto ? `<p><strong>Contacto</strong> ${sanitize(e.contacto)}</p>` : ''}
                ${e.domicilio ? `<p><strong>Domicilio</strong> ${sanitize(e.domicilio)}</p>` : ''}
                ${Number(e.mostrar_alertas) === 0 ? `<p style="margin-top:8px"><span style="display:inline-block;background:#fef3c7;color:#92400e;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:700">🔕 Alertas ocultas al cliente</span></p>` : ''}
                ${Number(e.requiere_fotos) === 1 ? `<p style="margin-top:8px"><span style="display:inline-block;background:#dbeafe;color:#1d4ed8;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:700">📷 Pide fotos en el reporte</span></p>` : ''}
            </div>
            <div class="empresa-actions">
                <button class="btn btn-sm btn-warning" onclick="editarEmpresa(${parseInt(e.id)})" title="Editar empresa">✏️ Editar</button>
                <button class="btn btn-sm btn-danger" onclick="eliminarEmpresa(${parseInt(e.id)})" title="Eliminar empresa">🗑️</button>
            </div>
        </div>
    `).join('')}
    </div>`;
}

function sanitize(str) {
    if (typeof str !== 'string') return String(str);
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function abrirModalNuevo() {
    limpiarModal();
    document.getElementById('modalTitulo').textContent = 'Nueva Empresa';
    document.getElementById('modalSubtitulo').textContent = 'Agrega una nueva empresa al sistema';
    document.getElementById('e-nombre').focus();
    document.getElementById('modalEmpresa').classList.add('open');
}

function editarEmpresa(id) {
    const e = empresas.find(x => x.id === id);
    if (!e) return;
    limpiarModal();
    document.getElementById('modalTitulo').textContent = 'Editar Empresa';
    document.getElementById('modalSubtitulo').textContent = 'Modifica los datos de esta empresa';
    document.getElementById('e-id').value = e.id;
    document.getElementById('e-nombre').value = e.nombre;
    document.getElementById('e-rfc').value = e.rfc || '';
    document.getElementById('e-regimen').value = e.regimen_fiscal || '';
    document.getElementById('e-cp').value = e.cp || '';
    document.getElementById('e-email').value = e.email || '';
    document.getElementById('e-telefono').value = e.telefono || '';
    document.getElementById('e-contacto').value = e.contacto || '';
    document.getElementById('e-domicilio').value = e.domicilio || '';
    // Sin dato guardado se asume activado (comportamiento por defecto)
    document.getElementById('e-alertas').checked = (e.mostrar_alertas === undefined) || Number(e.mostrar_alertas) === 1;
    document.getElementById('e-fotos').checked = Number(e.requiere_fotos) === 1;
    document.getElementById('modalEmpresa').classList.add('open');
}

function limpiarModal() {
    document.getElementById('e-id').value = '';
    document.getElementById('e-nombre').value = '';
    document.getElementById('e-rfc').value = '';
    document.getElementById('e-regimen').value = '';
    document.getElementById('e-cp').value = '';
    document.getElementById('e-email').value = '';
    document.getElementById('e-telefono').value = '';
    document.getElementById('e-contacto').value = '';
    document.getElementById('e-domicilio').value = '';
    document.getElementById('e-alertas').checked = true;
    document.getElementById('e-fotos').checked = false;
    document.getElementById('modal-alert').innerHTML = '';
}

function cerrarModal() {
    document.getElementById('modalEmpresa').classList.remove('open');
    limpiarModal();
}

async function guardarEmpresa() {
    const id     = document.getElementById('e-id').value;
    const nombre = document.getElementById('e-nombre').value.trim();
    const rfc    = document.getElementById('e-rfc').value.trim().toUpperCase();
    const email  = document.getElementById('e-email').value.trim().toLowerCase();

    if (!nombre || nombre.length < 3) {
        modalAlert('El nombre debe tener al menos 3 caracteres', 'error');
        document.getElementById('e-nombre').focus();
        return;
    }

    if (rfc && rfc.length !== 13) {
        modalAlert('El RFC debe tener exactamente 13 caracteres', 'error');
        document.getElementById('e-rfc').focus();
        return;
    }

    if (email && !validarEmail(email)) {
        modalAlert('Email inválido', 'error');
        document.getElementById('e-email').focus();
        return;
    }

    try {
        const body = {
            nombre,
            rfc:      rfc || null,
            email:    email || null,
            telefono: document.getElementById('e-telefono').value.trim() || null,
            contacto: document.getElementById('e-contacto').value.trim() || null,
            domicilio: document.getElementById('e-domicilio').value.trim() || null,
            mostrar_alertas: document.getElementById('e-alertas').checked ? 1 : 0,
            requiere_fotos:  document.getElementById('e-fotos').checked ? 1 : 0,
            regimen_fiscal:  document.getElementById('e-regimen').value || null,
            cp:              document.getElementById('e-cp').value.trim() || null,
        };
        if (id) body.id = parseInt(id);

        const r = await fetch('../api/usuarios.php?action=crear_empresa', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify(body),
            credentials: 'same-origin'
        });

        if (!r.ok) throw new Error(`Error ${r.status}`);

        const d = await r.json();

        if (d.success) {
            cerrarModal();
            showAlert(`✓ Empresa "${nombre}" guardada correctamente`, 'success');
            await cargar();
        } else {
            modalAlert(d.error || 'Error al guardar', 'error');
        }
    } catch(e) {
        modalAlert('Error de conexión: ' + e.message, 'error');
    }
}

async function eliminarEmpresa(id) {
    const empresa = empresas.find(e => e.id === id);
    if (!empresa) return;

    if (!confirm(`¿Estás seguro de que deseas eliminar a "${empresa.nombre}"?`)) return;

    try {
        const r = await fetch(`../api/usuarios.php?action=desactivar_empresa&id=${parseInt(id)}`);
        if (!r.ok) throw new Error(`Error ${r.status}`);

        const d = await r.json();

        if (d.success) {
            showAlert(`✓ Empresa "${empresa.nombre}" desactivada correctamente`, 'success');
            await cargar();
        } else {
            showAlert(d.error || 'Error al desactivar empresa', 'error');
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

cargar();
</script>
</body>
</html>
