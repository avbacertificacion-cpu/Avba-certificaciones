<?php
require_once '../config/config.php';
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== ROLE_INSPECTOR) {
    header('Location: ../public/login.html'); exit;
}
$nombre    = $_SESSION['nombre'];
$inspector = $_SESSION['usuario_id'];
$qr_param  = htmlspecialchars($_GET['qr'] ?? '');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inspección de Extintor</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Segoe UI',sans-serif;background:#f4f6fb}
        .navbar{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;padding:16px 32px;display:flex;justify-content:space-between;align-items:center}
        .navbar a{color:#fff;text-decoration:none;font-size:13px;margin-left:16px}
        .container{max-width:700px;margin:32px auto;padding:0 20px}

        /* Scanner */
        .scan-box{background:#fff;border-radius:12px;padding:32px;box-shadow:0 2px 12px rgba(0,0,0,.08);text-align:center;margin-bottom:24px}
        .scan-box h2{margin-bottom:20px;color:#333}
        .input-group{display:flex;gap:10px;max-width:440px;margin:0 auto}
        .input-group input{flex:1;padding:12px;border:2px solid #ddd;border-radius:8px;font-size:15px;text-transform:uppercase}
        .input-group input:focus{outline:none;border-color:#667eea}
        .btn{padding:11px 22px;border:none;border-radius:8px;cursor:pointer;font-weight:700;font-size:14px;transition:.2s}
        .btn-primary{background:#667eea;color:#fff}
        .btn-primary:hover{background:#5568d3}
        .btn-success{background:#27ae60;color:#fff}
        .btn-success:hover{background:#1e8449}
        .btn-warning{background:#f39c12;color:#fff}
        .btn-warning:hover{background:#d68910}
        .btn-danger{background:#e74c3c;color:#fff}
        .btn-danger:hover{background:#c0392b}

        /* Info card del extintor */
        .extintor-card{background:#fff;border-radius:12px;padding:24px;box-shadow:0 2px 12px rgba(0,0,0,.08);margin-bottom:24px;border-left:5px solid #667eea}
        .extintor-card h3{color:#667eea;margin-bottom:16px;font-size:18px}
        .info-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px}
        .info-item{background:#f4f6fb;padding:10px 14px;border-radius:8px}
        .info-item .label{font-size:11px;color:#888;font-weight:700;text-transform:uppercase;margin-bottom:4px}
        .info-item .value{font-size:14px;color:#333;font-weight:600}

        /* Checklist */
        .checklist-card{background:#fff;border-radius:12px;padding:24px;box-shadow:0 2px 12px rgba(0,0,0,.08);margin-bottom:24px}
        .checklist-card h3{margin-bottom:6px;color:#333}
        .checklist-card .subtitle{font-size:12px;color:#888;margin-bottom:20px}
        table{width:100%;border-collapse:collapse}
        thead{background:#f0f3ff}
        th,td{padding:10px 12px;text-align:center;font-size:13px;border-bottom:1px solid #eee}
        th:first-child,td:first-child{text-align:left}
        .radio-group{display:flex;gap:6px;justify-content:center;flex-wrap:wrap}
        .radio-group label{cursor:pointer;padding:4px 8px;border-radius:4px;font-size:11px;font-weight:700;border:2px solid #ddd;transition:.2s;white-space:nowrap}
        input[type=radio]{display:none}
        input[type=radio][value=OK]:checked + label{background:#27ae60;color:#fff;border-color:#27ae60}
        input[type=radio][value=NC]:checked + label{background:#e74c3c;color:#fff;border-color:#e74c3c}
        input[type=radio][value=NA]:checked + label{background:#95a5a6;color:#fff;border-color:#95a5a6}
        input[type=radio][value=PO]:checked + label{background:#f39c12;color:#fff;border-color:#f39c12}

        /* Datos extintor */
        .datos-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin:16px 0}
        .form-group{margin-bottom:0}
        .form-group label{display:block;font-size:12px;font-weight:700;color:#555;margin-bottom:6px}
        .form-group input,.form-group select,.form-group textarea{width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;font-size:13px}
        .form-group textarea{resize:vertical;min-height:70px}

        .legend{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px;font-size:12px}
        .legend-item{display:flex;align-items:center;gap:5px}
        .legend-dot{width:14px;height:14px;border-radius:3px}
        .dot-ok{background:#27ae60}.dot-nc{background:#e74c3c}.dot-na{background:#95a5a6}.dot-po{background:#f39c12}

        .alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:14px}
        .alert-success{background:#d4edda;color:#155724}
        .alert-error{background:#f8d7da;color:#721c24}
        .alert-info{background:#d1ecf1;color:#0c5460}

        .last-insp{background:#fff3cd;padding:12px 16px;border-radius:8px;font-size:13px;margin-bottom:16px;color:#856404}

        #form-inspeccion{display:none}
    </style>
</head>
<body>

<div class="navbar">
    <h1>🔍 Inspección de Extintor</h1>
    <div>
        <a href="inspector-dashboard.php">← Panel</a>
        <span style="margin-left:16px;font-size:13px"><?= htmlspecialchars($nombre) ?></span>
    </div>
</div>

<div class="container">
    <div id="alert-box"></div>

    <!-- Buscar extintor -->
    <div class="scan-box">
        <h2>🔍 Buscar Extintor</h2>
        <p style="color:#888;font-size:13px;margin-bottom:20px">
            Escanea el código QR o ingresa el código manual (ej: EXT-001)
        </p>
        <div class="input-group">
            <input type="text" id="codigo-input" placeholder="EXT-001 o código QR"
                   value="<?= $qr_param ?>" onkeydown="if(event.key==='Enter') buscarExtintor()">
            <button class="btn btn-primary" onclick="buscarExtintor()">Buscar</button>
        </div>
        <p style="margin-top:16px;font-size:12px;color:#aaa">
            Si el extintor no existe, podrás crearlo al buscar
        </p>
    </div>

    <!-- Contenido dinámico -->
    <div id="contenido"></div>
</div>

<script>
const inspectorNombre = '<?= htmlspecialchars($nombre) ?>';
let extActual = null;

// Auto-buscar si viene QR en URL
window.onload = () => {
    const q = document.getElementById('codigo-input').value.trim();
    if (q) buscarExtintor();
};

// ── Buscar extintor ───────────────────────────────────────────────────────────
async function buscarExtintor() {
    const codigo = document.getElementById('codigo-input').value.trim().toUpperCase();
    if (!codigo) { showAlert('Ingresa un código', 'error'); return; }

    const r = await fetch(`../api/extintores.php?action=buscar_qr&codigo=${encodeURIComponent(codigo)}`);
    const d = await r.json();

    if (d.found) {
        extActual = d.data;
        mostrarExtintor(d.data);
    } else {
        mostrarNoEncontrado(codigo);
    }
}

// ── Extintor encontrado: mostrar info + checklist ─────────────────────────────
function mostrarExtintor(ext) {
    const ultimaInsp = ext.ultima_inspeccion;
    const cont = document.getElementById('contenido');

    cont.innerHTML = `
        <div class="extintor-card">
            <h3>🧯 ${ext.codigo_manual} — ${ext.ubicacion}</h3>
            ${ultimaInsp ? `
            <div class="last-insp">
                ⏱ Última inspección: <strong>${ultimaInsp.fecha}</strong>
                por ${ultimaInsp.inspector_nombre}
            </div>` : '<div class="last-insp" style="background:#f8d7da;color:#721c24">⚠️ Sin inspecciones previas</div>'}
            <div class="info-grid">
                <div class="info-item">
                    <div class="label">Empresa</div>
                    <div class="value">${ext.empresa_nombre}</div>
                </div>
                <div class="info-item">
                    <div class="label">Tipo</div>
                    <div class="value">${ext.tipo}</div>
                </div>
                <div class="info-item">
                    <div class="label">Capacidad</div>
                    <div class="value">${ext.capacidad || '—'}</div>
                </div>
                <div class="info-item">
                    <div class="label">Fecha Recarga</div>
                    <div class="value">${ext.fecha_recarga || '—'}</div>
                </div>
                <div class="info-item">
                    <div class="label">Prueba Hidrostática</div>
                    <div class="value">${ext.fecha_ph || '—'}</div>
                </div>
                <div class="info-item">
                    <div class="label">Estado</div>
                    <div class="value">${ext.estado}</div>
                </div>
            </div>
        </div>

        ${formularioInspeccion(ext)}
    `;
}

// ── Extintor NO encontrado ────────────────────────────────────────────────────
function mostrarNoEncontrado(codigo) {
    document.getElementById('contenido').innerHTML = `
        <div class="extintor-card" style="border-left-color:#e74c3c">
            <h3 style="color:#e74c3c">❌ Extintor no encontrado</h3>
            <p style="color:#666;margin:12px 0">
                El código <strong>${codigo}</strong> no está registrado.
                ¿Deseas crear este extintor?
            </p>
            <button class="btn btn-success" onclick="window.location.href='extintores.php'">
                + Ir a crear extintor
            </button>
        </div>
    `;
}

// ── Formulario de inspección ──────────────────────────────────────────────────
function formularioInspeccion(ext) {
    const items = [
        {key:'ser', label:'SER – Señalamiento y soporte'},
        {key:'mg',  label:'MG – Manguera'},
        {key:'po',  label:'PO – Extintor en préstamo'},
        {key:'ph',  label:'PH – Prueba hidrostática'},
        {key:'sg',  label:'SG – Seguro (pasador y cincho)'},
        {key:'ps',  label:'PS – Presión'},
        {key:'ob',  label:'OB – Obstruido'},
        {key:'dan', label:'DAÑ – Daño'},
        {key:'pin', label:'PIN – Pintura y etiqueta'},
        {key:'fn',  label:'FN – Funda'},
        {key:'gb',  label:'GB – Gabinete'},
        {key:'rv',  label:'RV – Recarga vigente'},
    ];

    const filas = items.map(it => `
        <tr>
            <td>${it.label}</td>
            <td>
                <div class="radio-group">
                    ${['OK','NC','NA','PO'].map(v => `
                        <input type="radio" name="${it.key}" id="${it.key}-${v}" value="${v}">
                        <label for="${it.key}-${v}">${v}</label>
                    `).join('')}
                </div>
            </td>
        </tr>
    `).join('');

    return `
    <div class="checklist-card">
        <h3>📋 Checklist de Inspección</h3>
        <p class="subtitle">Conforme a la NOM-002-STPS-2010</p>

        <div class="legend">
            <div class="legend-item"><div class="legend-dot dot-ok"></div> OK – Cumple NOM</div>
            <div class="legend-item"><div class="legend-dot dot-nc"></div> NC – No cumple NOM</div>
            <div class="legend-item"><div class="legend-dot dot-na"></div> NA – No aplica</div>
            <div class="legend-item"><div class="legend-dot dot-po"></div> PO – En préstamo</div>
        </div>

        <table>
            <thead><tr><th>Elemento</th><th>Resultado</th></tr></thead>
            <tbody>${filas}</tbody>
        </table>

        <h3 style="margin-top:24px;margin-bottom:12px">Observaciones</h3>
        <div class="form-group">
            <textarea id="obs-insp" placeholder="Notas, condiciones especiales…"></textarea>
        </div>

        <div style="display:flex;gap:12px;margin-top:20px;justify-content:flex-end">
            <button class="btn btn-warning" onclick="document.getElementById('codigo-input').value=''; document.getElementById('contenido').innerHTML=''">
                Limpiar
            </button>
            <button class="btn btn-success" onclick="guardarInspeccion()">
                ✓ Guardar Inspección
            </button>
        </div>
    </div>`;
}

// ── Guardar inspección ────────────────────────────────────────────────────────
async function guardarInspeccion() {
    if (!extActual) return;

    const getRadio = name => {
        const sel = document.querySelector(`input[name="${name}"]:checked`);
        return sel ? sel.value : null;
    };

    // Capturar fecha y hora del cliente
    const ahora = new Date();
    const fecha = ahora.toISOString().split('T')[0]; // YYYY-MM-DD
    const hora = ahora.toTimeString().slice(0, 8); // HH:MM:SS

    const body = {
        extintor_id:     extActual.id,
        fecha:           fecha,
        hora:            hora,
        ser:             getRadio('ser'),
        mg:              getRadio('mg'),
        po:              getRadio('po'),
        ph:              getRadio('ph'),
        sg:              getRadio('sg'),
        ps:              getRadio('ps'),
        ob:              getRadio('ob'),
        dan:             getRadio('dan'),
        pin:             getRadio('pin'),
        fn:              getRadio('fn'),
        gb:              getRadio('gb'),
        rv:              getRadio('rv'),
        observaciones:   document.getElementById('obs-insp').value   || null,
    };

    const r = await fetch('../api/inspecciones.php?action=guardar', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify(body)
    });
    const d = await r.json();

    if (d.success) {
        showAlert('✅ Inspección guardada correctamente', 'success');
        document.getElementById('contenido').innerHTML = '';
        document.getElementById('codigo-input').value = '';
        extActual = null;
    } else {
        showAlert(d.error || 'Error al guardar', 'error');
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function showAlert(msg, tipo) {
    const b = document.getElementById('alert-box');
    b.innerHTML = `<div class="alert alert-${tipo}">${msg}</div>`;
    setTimeout(() => b.innerHTML = '', 5000);
}
</script>
</body>
</html>
