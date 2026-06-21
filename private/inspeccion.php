<?php
require_once '../config/config.php';
if (!isset($_SESSION['usuario_id']) || !in_array($_SESSION['rol'], [ROLE_INSPECTOR, ROLE_ADMIN])) {
    header('Location: ../public/login.html'); exit;
}

$nombre    = $_SESSION['nombre'];
$inspector = $_SESSION['usuario_id'];
$qr_param  = htmlspecialchars($_GET['qr'] ?? '');

// Manejar cambio de empresa
if (isset($_GET['cambiar_empresa'])) {
    unset($_SESSION['empresa_inspeccion']);
    header('Location: inspeccion.php');
    exit;
}

// Manejar selección de empresa
if (($_POST['action'] ?? null) === 'seleccionar_empresa' && !empty($_POST['empresa_id'])) {
    $_SESSION['empresa_inspeccion'] = intval($_POST['empresa_id']);
    header('Location: inspeccion.php');
    exit;
}

// Verificar si hay empresa seleccionada
$empresa_inspeccion = $_SESSION['empresa_inspeccion'] ?? null;
$empresa_nombre = '';
$empresas_lista = [];

// Cargar lista de empresas (para mostrar selector si no hay seleccionada)
$stmt = $pdo->prepare("SELECT id, nombre FROM empresas WHERE estado='activo' ORDER BY nombre ASC");
$stmt->execute();
$empresas_lista = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($empresa_inspeccion) {
    $stmt = $pdo->prepare("SELECT nombre FROM empresas WHERE id=? AND estado='activo'");
    $stmt->execute([$empresa_inspeccion]);
    $empresa_nombre = $stmt->fetchColumn() ?: '';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inspección de Extintor</title>
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
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

        /* Modal empresa selector */
        .modal-overlay{display:flex;align-items:center;justify-content:center;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:1000}
        .modal-content{background:#fff;border-radius:16px;padding:32px;max-width:500px;width:90%;box-shadow:0 8px 32px rgba(0,0,0,.2)}
        .modal-header{font-size:24px;font-weight:700;color:#333;margin-bottom:8px}
        .modal-subtitle{font-size:14px;color:#888;margin-bottom:24px}
        .empresa-list{display:grid;grid-template-columns:1fr;gap:12px;max-height:400px;overflow-y:auto}
        .empresa-item{padding:16px;border:2px solid #ddd;border-radius:10px;cursor:pointer;transition:.2s;background:#f9f9f9}
        .empresa-item:hover{border-color:#667eea;background:#f0f3ff}
        .empresa-item.selected{border-color:#667eea;background:#f0f3ff;color:#667eea}
        .empresa-item-name{font-weight:600;margin-bottom:4px;font-size:14px}
        .empresa-item-id{font-size:12px;color:#999}
        .modal-actions{display:flex;gap:12px;margin-top:24px}
        .modal-actions button{flex:1;padding:12px;border:none;border-radius:8px;font-weight:700;cursor:pointer;transition:.2s}
        .modal-btn-cancel{background:#eee;color:#333}
        .modal-btn-cancel:hover{background:#ddd}
        .modal-btn-confirm{background:#667eea;color:#fff}
        .modal-btn-confirm:hover{background:#5568d3}
        .modal-btn-confirm:disabled{background:#ccc;cursor:not-allowed}

        @media (max-width:768px) {
            .modal-content{max-width:90%;padding:24px}
            .modal-header{font-size:20px}
        }

        /* Scanner QR Modal */
        .scanner-modal{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.8);z-index:2000;align-items:center;justify-content:center}
        .scanner-modal.show{display:flex}
        .scanner-modal-content{background:#fff;border-radius:12px;padding:20px;max-width:500px;width:90%;box-shadow:0 8px 32px rgba(0,0,0,.3)}
        .scanner-modal-header{font-size:20px;font-weight:700;color:#333;margin-bottom:16px;display:flex;justify-content:space-between;align-items:center}
        .scanner-modal-close{background:none;border:none;font-size:28px;cursor:pointer;color:#999}
        .scanner-modal-close:hover{color:#333}
        #scanner{width:100%;height:400px;border-radius:8px;overflow:hidden;margin-bottom:16px}
        .scanner-modal-buttons{display:flex;gap:12px}
        .scanner-modal-buttons button{flex:1;padding:12px;border:none;border-radius:8px;font-weight:700;cursor:pointer}
    </style>
</head>
<body>

<div class="navbar">
    <h1>🔍 Inspección de Extintor</h1>
    <div>
        <a href="inspector-dashboard.php">← Panel</a>
        <?php if ($empresa_inspeccion): ?>
        <span style="margin-left:16px;font-size:13px">📍 <?= htmlspecialchars($empresa_nombre) ?></span>
        <a href="?cambiar_empresa=1" style="color:#ffd700;margin-left:6px">Cambiar</a>
        <?php endif; ?>
        <span style="margin-left:16px;font-size:13px"><?= htmlspecialchars($nombre) ?></span>
    </div>
</div>

<?php if (!$empresa_inspeccion): ?>
    <!-- MODAL: Seleccionar Empresa -->
    <div class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">📍 Selecciona Empresa</div>
            <div class="modal-subtitle">Selecciona la empresa donde realizarás las inspecciones</div>

            <form id="form-seleccionar-empresa" onsubmit="seleccionarEmpresa(event)">
                <div class="empresa-list" id="empresas-container">
                    <?php foreach ($empresas_lista as $emp): ?>
                    <label class="empresa-item" onclick="document.getElementById('emp-radio-<?= $emp['id'] ?>').checked=true; actualizarSeleccion(<?= $emp['id'] ?>)">
                        <input type="radio" id="emp-radio-<?= $emp['id'] ?>" name="empresa_id" value="<?= $emp['id'] ?>" style="display:none">
                        <div class="empresa-item-name"><?= htmlspecialchars($emp['nombre']) ?></div>
                        <div class="empresa-item-id">ID: <?= $emp['id'] ?></div>
                    </label>
                    <?php endforeach; ?>
                </div>

                <div class="modal-actions">
                    <button type="button" class="modal-btn-cancel" onclick="window.location.href='inspector-dashboard.php'">
                        Cancelar
                    </button>
                    <button type="submit" class="modal-btn-confirm" id="btn-confirmar" disabled>
                        Continuar
                    </button>
                </div>
            </form>
        </div>
    </div>
<?php else: ?>
    <!-- INTERFAZ: Inspeccionar Extintores -->
    <div class="container">
        <div id="alert-box"></div>

        <!-- Buscar extintor -->
        <div class="scan-box">
            <h2>🔍 Buscar Extintor</h2>
            <p style="color:#888;font-size:13px;margin-bottom:20px">
                Escanea el código QR o ingresa el código manual (ej: EXT-001)
            </p>
            <button class="btn btn-success" onclick="abrirScannerQR()" style="width:100%;margin-bottom:16px">📱 Escanear Código QR</button>
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

    <!-- Scanner QR Modal -->
    <div class="scanner-modal" id="scannerModal">
        <div class="scanner-modal-content">
            <div class="scanner-modal-header">
                <span>📱 Escanear Código QR</span>
                <button class="scanner-modal-close" onclick="cerrarScannerQR()">✕</button>
            </div>
            <div id="scanner"></div>
            <div class="scanner-modal-buttons">
                <button class="btn btn-danger" onclick="cerrarScannerQR()">Cancelar</button>
            </div>
        </div>
    </div>

    <!-- Modal Asignar QR -->
    <div class="scanner-modal" id="modalAsignarQR">
        <div class="scanner-modal-content" style="max-width:500px">
            <div class="scanner-modal-header">
                <span>📝 Asignar Código QR</span>
                <button class="scanner-modal-close" onclick="cerrarModalAsignarQR()">✕</button>
            </div>
            <div id="asignar-qr-alert"></div>
            <div style="padding:20px">
                <div style="margin-bottom:16px">
                    <button class="btn" style="background:#48bb78;color:#fff;width:100%;margin-bottom:12px;padding:12px"
                            onclick="iniciarScannerAsignar()">
                        📱 Escanear QR Impreso
                    </button>
                </div>
                <div id="scanner-asignar" style="display:none;width:100%;height:300px;border-radius:8px;overflow:hidden;margin-bottom:16px"></div>
                <div style="text-align:center;margin:16px 0;color:#999">O</div>
                <div class="form-group">
                    <label>Ingresa Manual (11 dígitos)</label>
                    <input type="text" id="input-qr-asignar" placeholder="00000000001" maxlength="11"
                           pattern="[0-9]{11}" style="padding:12px;border:2px solid #ddd;border-radius:8px;font-size:16px;width:100%">
                    <small style="color:#888;margin-top:8px;display:block">Los 11 dígitos del código QR</small>
                </div>
            </div>
            <div class="scanner-modal-buttons">
                <button class="btn btn-warning" onclick="cerrarModalAsignarQR()">Cancelar</button>
                <button class="btn btn-success" onclick="guardarAsignacionQR()">Asignar QR</button>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
const inspectorNombre = '<?= htmlspecialchars($nombre) ?>';
const empresaInspeccion = <?= json_encode($empresa_inspeccion) ?>;
let extActual = null;

// ── Selección de Empresa ──────────────────────────────────────────────────────
function actualizarSeleccion(empresaId) {
    const btn = document.getElementById('btn-confirmar');
    btn.disabled = !empresaId;
    document.querySelectorAll('.empresa-item').forEach(item => {
        const radio = item.querySelector('input[type="radio"]');
        item.classList.toggle('selected', radio?.checked);
    });
}

function seleccionarEmpresa(e) {
    e.preventDefault();
    const empresaId = document.querySelector('input[name="empresa_id"]:checked')?.value;
    if (!empresaId) {
        showAlert('Selecciona una empresa', 'error');
        return;
    }

    const fd = new FormData();
    fd.append('action', 'seleccionar_empresa');
    fd.append('empresa_id', empresaId);

    fetch(window.location.href, {
        method: 'POST',
        body: fd
    }).then(() => {
        window.location.reload();
    });
}

// Auto-buscar si viene QR en URL y empresa ya seleccionada
window.onload = () => {
    if (empresaInspeccion && document.getElementById('codigo-input')) {
        const q = document.getElementById('codigo-input').value.trim();
        if (q) buscarExtintor();
    }
};

// ── Buscar extintor ───────────────────────────────────────────────────────────
async function buscarExtintor() {
    const codigo = document.getElementById('codigo-input').value.trim().toUpperCase();
    if (!codigo) { showAlert('Ingresa un código', 'error'); return; }

    const url = `../api/extintores.php?action=buscar_qr&codigo=${encodeURIComponent(codigo)}&empresa_id=${empresaInspeccion}`;
    const r = await fetch(url);
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
                <div class="info-item">
                    <div class="label">Código QR</div>
                    <div class="value">${ext.codigo_qr ? `<strong>${ext.codigo_qr}</strong>` : '<span style="color:#e74c3c">No asignado</span>'}</div>
                </div>
            </div>
            ${!ext.codigo_qr ? `<div style="margin-top:16px"><button class="btn btn-success" onclick="abrirModalAsignarQR(${ext.id})">📝 Asignar QR</button></div>` : ''}
        </div>

        ${formularioInspeccion(ext)}
    `;

    // Establecer valor por defecto: fecha actual
    setTimeout(() => {
        const ahora = new Date();
        const fecha = ahora.toISOString().split('T')[0];
        document.getElementById('fecha-insp').value = fecha;
    }, 100);
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

        <div style="margin-bottom:16px;text-align:right">
            <button class="btn" style="background:#2196F3;color:#fff;padding:6px 14px;font-size:13px" onclick="marcarTodosOK()">
                ✔ Marcar todos OK
            </button>
        </div>

        <table>
            <thead><tr><th>Elemento</th><th>Resultado</th></tr></thead>
            <tbody>${filas}</tbody>
        </table>

        <h3 style="margin-top:24px;margin-bottom:12px">📅 Fecha de Inspección</h3>
        <div style="max-width:250px">
            <div class="form-group">
                <label>Fecha *</label>
                <input type="date" id="fecha-insp" required>
            </div>
        </div>

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

// ── Marcar todos los items como OK ───────────────────────────────────────────
function marcarTodosOK() {
    ['ser','mg','po','ph','sg','ps','ob','dan','pin','fn','gb','rv'].forEach(key => {
        const radio = document.getElementById(`${key}-OK`);
        if (radio) radio.checked = true;
    });
}

// ── Guardar inspección ────────────────────────────────────────────────────────
async function guardarInspeccion() {
    if (!extActual) return;

    const getRadio = name => {
        const sel = document.querySelector(`input[name="${name}"]:checked`);
        return sel ? sel.value : null;
    };

    // Obtener fecha del formulario
    const fecha = document.getElementById('fecha-insp').value;

    if (!fecha) {
        showAlert('Ingresa fecha de inspección', 'error');
        return;
    }

    // Usar hora actual automáticamente
    const ahora = new Date();
    const hora = ahora.toTimeString().slice(0, 8);

    const body = {
        extintor_id:     extActual.id,
        empresa_id:      empresaInspeccion,
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

// ── Scanner QR ────────────────────────────────────────────────────────────────
let qrScanner = null;

function abrirScannerQR() {
    document.getElementById('scannerModal').classList.add('show');

    if (!qrScanner) {
        qrScanner = new Html5Qrcode('scanner');
        qrScanner.start(
            { facingMode: 'environment' },
            { fps: 10, qrbox: 250 },
            (decodedText) => {
                console.log('QR detectado:', decodedText);

                // Extraer código: primero intenta extraer de URL ?qr=11dígitos
                let codigo = decodedText.trim();
                const urlMatch = codigo.match(/[?&]qr=(\d{11})/);
                if (urlMatch) {
                    codigo = urlMatch[1];
                } else if (!/^\d{11}$/.test(codigo)) {
                    // Si no es 11 dígitos, intenta extraerlos
                    const digitMatch = codigo.match(/(\d{11})/);
                    if (digitMatch) {
                        codigo = digitMatch[1];
                    }
                }

                document.getElementById('codigo-input').value = codigo;
                cerrarScannerQR();
                buscarExtintor();
            },
            (error) => {}
        ).catch((err) => {
            console.error('Error al acceder a cámara:', err);
            const mensajeError = `No se pudo acceder a la cámara: ${err.message || err}

Posibles causas:
• La página no está en HTTPS
• El navegador bloqueó los permisos de cámara
• No hay cámara disponible
• Cambios en navegador/SO

Solución: Ingresa el código manualmente en el campo.`;

            showAlert(mensajeError, 'error');
            cerrarScannerQR();
        });
    }
}

function cerrarScannerQR() {
    document.getElementById('scannerModal').classList.remove('show');
    if (qrScanner) {
        qrScanner.stop().catch(() => {});
    }
}

// ── Asignar QR ────────────────────────────────────────────────────────────────
let extintor_asignar_id = null;
let scannerAsignar = null;

function abrirModalAsignarQR(extintor_id) {
    extintor_asignar_id = extintor_id;
    document.getElementById('input-qr-asignar').value = '';
    document.getElementById('asignar-qr-alert').innerHTML = '';
    document.getElementById('scanner-asignar').style.display = 'none';
    document.getElementById('modalAsignarQR').classList.add('show');
    setTimeout(() => document.getElementById('input-qr-asignar').focus(), 100);
}

function cerrarModalAsignarQR() {
    document.getElementById('modalAsignarQR').classList.remove('show');
    detenerScannerAsignar();
    extintor_asignar_id = null;
}

async function iniciarScannerAsignar() {
    const scanDiv = document.getElementById('scanner-asignar');
    const alertDiv = document.getElementById('asignar-qr-alert');

    if (scanDiv.style.display === 'block') {
        detenerScannerAsignar();
        return;
    }

    scanDiv.style.display = 'block';

    try {
        scannerAsignar = new Html5Qrcode('scanner-asignar');
        await scannerAsignar.start(
            { facingMode: 'environment' },
            { fps: 10, qrbox: 250 },
            (decodedText) => {
                // Extraer QR: primero intenta extraer de URL con formato ?qr=11dígitos
                let qr = null;
                const urlMatch = decodedText.match(/[?&]qr=(\d{11})/);
                if (urlMatch) {
                    qr = urlMatch[1];
                } else if (/^\d{11}$/.test(decodedText)) {
                    // Si es solo 11 dígitos, usar directamente
                    qr = decodedText;
                }

                if (qr && /^\d{11}$/.test(qr)) {
                    document.getElementById('input-qr-asignar').value = qr;
                    detenerScannerAsignar();
                    alertDiv.innerHTML = '<div style="background:#d1fae5;color:#065f46;padding:12px;border-radius:6px">✓ QR detectado. Presiona "Asignar QR"</div>';
                } else if (!qr) {
                    // No encontró el formato esperado, mostrar error
                    alertDiv.innerHTML = '<div style="background:#f8d7da;color:#721c24;padding:12px;border-radius:6px">QR no válido. Intenta de nuevo.</div>';
                }
            },
            (error) => { console.log('Error en scanner:', error); }
        ).catch(() => {
            alertDiv.innerHTML = '<div style="background:#f8d7da;color:#721c24;padding:12px;border-radius:6px">No se pudo acceder a la cámara</div>';
            detenerScannerAsignar();
        });
    } catch (err) {
        alertDiv.innerHTML = '<div style="background:#f8d7da;color:#721c24;padding:12px;border-radius:6px">Error al iniciar cámara</div>';
        detenerScannerAsignar();
    }
}

function detenerScannerAsignar() {
    if (scannerAsignar) {
        scannerAsignar.stop().catch(() => {});
        scannerAsignar = null;
    }
    document.getElementById('scanner-asignar').style.display = 'none';
}

async function guardarAsignacionQR() {
    const qr = document.getElementById('input-qr-asignar').value.trim();
    const alertDiv = document.getElementById('asignar-qr-alert');

    if (!qr) {
        alertDiv.innerHTML = '<div style="background:#f8d7da;color:#721c24;padding:12px;border-radius:6px">Ingresa o escanea el código QR</div>';
        return;
    }

    if (!/^\d{11}$/.test(qr)) {
        alertDiv.innerHTML = '<div style="background:#f8d7da;color:#721c24;padding:12px;border-radius:6px">El QR debe ser exactamente 11 dígitos</div>';
        return;
    }

    try {
        const res = await fetch('../api/extintores.php?action=asignar_qr', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                extintor_id: extintor_asignar_id,
                codigo_qr: qr
            })
        });

        const data = await res.json();

        if (!res.ok) {
            alertDiv.innerHTML = `<div style="background:#f8d7da;color:#721c24;padding:12px;border-radius:6px">${data.error || 'Error al asignar QR'}</div>`;
            return;
        }

        // Éxito: actualizar datos y cerrar modal
        extActual.codigo_qr = qr;
        mostrarExtintor(extActual);
        cerrarModalAsignarQR();
        showAlert('✓ QR asignado correctamente', 'success');
    } catch (err) {
        alertDiv.innerHTML = '<div style="background:#f8d7da;color:#721c24;padding:12px;border-radius:6px">Error al asignar QR</div>';
        console.error('Error:', err);
    }
}
</script>
</body>
</html>
