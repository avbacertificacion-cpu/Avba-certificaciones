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
<title>Cotizaciones</title>
<style>
    *{margin:0;padding:0;box-sizing:border-box}
    body{font-family:'Segoe UI',system-ui,sans-serif;background:#eef2fb;color:#1a2138}
    .navbar{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;padding:16px 26px;
            display:flex;justify-content:space-between;align-items:center;box-shadow:0 4px 16px rgba(102,126,234,.2)}
    .navbar a{color:#fff;text-decoration:none;font-size:13px;opacity:.9}.navbar a:hover{opacity:1;text-decoration:underline}
    .container{max-width:1350px;margin:0 auto;padding:26px 20px}
    h2{font-size:24px;color:#1e293b}
    .sub{color:#64748b;font-size:13px;margin-bottom:20px}

    .kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-bottom:22px}
    .kpi{background:#fff;border-radius:14px;padding:18px;box-shadow:0 4px 14px rgba(30,41,59,.08);border-left:5px solid #667eea}
    .kpi.ok{border-left-color:#27ae60}.kpi.warn{border-left-color:#f39c12}.kpi.pur{border-left-color:#8e44ad}
    .kpi .v{font-size:26px;font-weight:800;color:#1e293b;line-height:1.2}
    .kpi .l{font-size:12px;color:#64748b;margin-top:4px}

    .btn{padding:10px 18px;border:none;border-radius:8px;font-weight:700;cursor:pointer;font-size:14px}
    .btn-primary{background:#667eea;color:#fff}.btn-primary:hover{background:#5568d3}
    .btn-danger{background:#e74c3c;color:#fff}.btn-warning{background:#f39c12;color:#fff}
    .btn-ghost{background:#eef2fb;color:#475569}
    .btn-sm{padding:6px 11px;font-size:12px}

    .card{background:#fff;border-radius:14px;padding:20px;box-shadow:0 4px 14px rgba(30,41,59,.08)}
    .toolbar{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:16px}
    .toolbar input,.toolbar select{padding:10px 12px;border:2px solid #e0e0ff;border-radius:8px;font-size:14px}
    .toolbar input:focus,.toolbar select:focus{outline:none;border-color:#667eea}

    table{width:100%;border-collapse:collapse}
    thead{background:#f1f5fb}
    th{padding:11px 9px;text-align:left;font-size:11px;color:#475569;font-weight:700;text-transform:uppercase}
    td{padding:11px 9px;font-size:13px;border-bottom:1px solid #f1f5f9}
    td.n,th.n{text-align:right}
    tbody tr:hover{background:#f8faff}

    .badge{padding:4px 10px;border-radius:20px;font-size:11px;font-weight:700;display:inline-block}
    .b-borrador{background:#e2e8f0;color:#475569}
    .b-enviada{background:#dbeafe;color:#1d4ed8}
    .b-aceptada{background:#d1fae5;color:#047857}
    .b-rechazada{background:#fee2e2;color:#b91c1c}
    .util{font-weight:800;color:#047857}
    .util.neg{color:#c0392b}

    .modal-ov{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100;align-items:flex-start;justify-content:center;padding:20px;overflow-y:auto}
    .modal-ov.open{display:flex}
    .modal{background:#fff;border-radius:14px;width:100%;max-width:1050px;padding:24px;margin:auto}
    .modal h3{font-size:19px;margin-bottom:16px;color:#1e293b}
    .fg{margin-bottom:14px}
    .fg label{display:block;font-size:12px;font-weight:700;color:#475569;margin-bottom:5px}
    .fg input,.fg select,.fg textarea{width:100%;padding:10px;border:2px solid #e0e0ff;border-radius:8px;font-size:14px;font-family:inherit}
    .fg input:focus,.fg select:focus,.fg textarea:focus{outline:none;border-color:#667eea}
    .grid4{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
    .grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    .modal-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:14px;flex-wrap:wrap}

    .items{width:100%;border-collapse:collapse;margin-bottom:10px}
    .items th{background:#f1f5fb;padding:8px 6px;font-size:10px}
    .items td{padding:5px 4px;border-bottom:1px solid #f1f5f9}
    .items input,.items select{width:100%;padding:7px 6px;border:1px solid #dbe2f5;border-radius:6px;font-size:13px}
    .items input:focus,.items select:focus{outline:none;border-color:#667eea}
    .items input.n{text-align:right}
    .items .ro{font-size:13px;text-align:right;white-space:nowrap}
    .items .del{background:#fee2e2;color:#b91c1c;border:none;border-radius:6px;padding:6px 9px;cursor:pointer;font-weight:700}

    .tot{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;background:#f8faff;
         border:2px solid #e0e7ff;border-radius:12px;padding:14px;margin-top:6px}
    .tot div span{display:block;font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase}
    .tot div b{font-size:19px;color:#1e293b}

    .empty{text-align:center;padding:50px 20px;color:#94a3b8}.empty .ic{font-size:52px;margin-bottom:10px}
    .msg{font-size:13px;font-weight:600;margin-bottom:12px}
    .hint{font-size:11px;color:#94a3b8;margin-top:4px}
    @media(max-width:800px){ .grid4,.grid2{grid-template-columns:1fr} }

    /* Vista para imprimir/enviar al cliente: nunca muestra costos ni utilidad */
    #areaImpresion{display:none}
    @media print{
        body>*{display:none !important}
        body{background:#fff !important}
        #areaImpresion{display:block !important;position:static}
        #areaImpresion th{background:#eef2fb !important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
        #areaImpresion tr{page-break-inside:avoid}
        @page{size:letter portrait;margin:14mm}
    }
    #areaImpresion{font-size:13px;color:#111}
    #areaImpresion h1{font-size:22px;margin-bottom:2px}
    #areaImpresion table{width:100%;border-collapse:collapse;margin-top:14px}
    #areaImpresion th{background:#eef2fb;border:1px solid #cbd5e1;padding:7px;font-size:11px}
    #areaImpresion td{border:1px solid #cbd5e1;padding:7px}
</style>
</head>
<body>
<div class="navbar">
    <a href="admin-dashboard.php">← Panel Admin</a>
    <span style="font-size:13px">👤 <?= htmlspecialchars($nombre) ?></span>
</div>

<div class="container">
    <h2>💰 Cotizaciones</h2>
    <div class="sub">Quién te la pide, a qué proveedor se la compraste, cuánto te cuesta y cuánto ganas.</div>

    <div class="kpis" id="kpis"></div>

    <div class="toolbar">
        <input type="text" id="busca" placeholder="🔍 Buscar folio o cliente…" oninput="render()">
        <select id="filtroEstado" onchange="render()">
            <option value="">Todos los estados</option>
            <option value="borrador">Borrador</option>
            <option value="enviada">Enviada</option>
            <option value="aceptada">Aceptada</option>
            <option value="rechazada">Rechazada</option>
        </select>
        <button class="btn btn-primary" onclick="nueva()">＋ Nueva cotización</button>
        <a class="btn btn-ghost" href="admin-proveedores.php" style="text-decoration:none">🏭 Proveedores y precios</a>
    </div>

    <div id="msg" class="msg"></div>
    <div class="card"><div id="tabla"></div></div>
</div>

<!-- Editor de cotización -->
<div class="modal-ov" id="modalCot">
    <div class="modal">
        <h3 id="tituloCot">Nueva cotización</h3>
        <input type="hidden" id="k-id">

        <div class="grid2">
            <div class="fg">
                <label>Cliente que la solicita *</label>
                <select id="k-empresa" onchange="empresaElegida()"></select>
                <div class="hint">Si aún no es cliente registrado, elige “Otro / prospecto” y escribe el nombre.</div>
            </div>
            <div class="fg">
                <label>Nombre del cliente / prospecto *</label>
                <input type="text" id="k-cliente" placeholder="Nombre que aparecerá en la cotización">
            </div>
        </div>
        <div class="grid4">
            <div class="fg"><label>Persona de contacto</label><input type="text" id="k-contacto" placeholder="Quién la pidió"></div>
            <div class="fg"><label>Fecha</label><input type="date" id="k-fecha"></div>
            <div class="fg"><label>Vigencia (días)</label><input type="number" id="k-vigencia" min="0" value="15"></div>
            <div class="fg"><label>Estado</label>
                <select id="k-estado">
                    <option value="borrador">Borrador</option>
                    <option value="enviada">Enviada</option>
                    <option value="aceptada">Aceptada</option>
                    <option value="rechazada">Rechazada</option>
                </select>
            </div>
        </div>

        <div class="fg">
            <label>Partidas</label>
            <div style="overflow-x:auto">
            <table class="items">
                <thead><tr>
                    <th style="min-width:200px">Descripción</th>
                    <th style="width:70px">Cant.</th>
                    <th style="width:85px">Unidad</th>
                    <th style="min-width:150px">Proveedor (a quién se lo pedí)</th>
                    <th style="width:105px">Costo unit.</th>
                    <th style="width:105px">Precio venta</th>
                    <th style="width:95px">Utilidad</th>
                    <th style="width:60px">%</th>
                    <th style="width:40px"></th>
                </tr></thead>
                <tbody id="items"></tbody>
            </table>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
                <button class="btn btn-ghost btn-sm" onclick="agregarFila()">＋ Agregar partida</button>
                <select id="k-catalogo" onchange="desdeCatalogo()" style="padding:7px;border:2px solid #e0e0ff;border-radius:8px;font-size:13px">
                    <option value="">Traer del catálogo de precios…</option>
                </select>
            </div>
        </div>

        <div class="tot">
            <div><span>Costo total</span><b id="t-costo">$0.00</b></div>
            <div><span>Precio de venta</span><b id="t-venta">$0.00</b></div>
            <div><span>Utilidad</span><b id="t-util" style="color:#047857">$0.00</b></div>
            <div><span>Margen (sobre venta)</span><b id="t-margen">0%</b></div>
            <div><span>Markup (sobre costo)</span><b id="t-markup">0%</b></div>
        </div>

        <div class="fg" style="margin-top:14px"><label>Notas / condiciones</label><textarea id="k-notas" rows="2" placeholder="Tiempo de entrega, forma de pago…"></textarea></div>

        <div class="modal-actions">
            <button class="btn btn-warning" onclick="cerrar('modalCot')">Cancelar</button>
            <button class="btn btn-ghost" id="btnImprimir" onclick="imprimirActual()" style="display:none">🖨️ Imprimir para el cliente</button>
            <button class="btn btn-primary" onclick="guardarCot()">Guardar cotización</button>
        </div>
    </div>
</div>

<div id="areaImpresion"></div>

<script>
const API = '../api/cotizaciones.php';
let cotizaciones = [], proveedores = [], catalogo = [], empresas = [];

const esc = s => { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; };
const money = n => '$' + Number(n || 0).toLocaleString('es-MX', {minimumFractionDigits:2, maximumFractionDigits:2});
const cerrar = id => document.getElementById(id).classList.remove('open');
const num = id => parseFloat(document.getElementById(id).value) || 0;
function aviso(t, ok) {
    const m = document.getElementById('msg');
    m.textContent = t; m.style.color = ok ? '#27ae60' : '#c0392b';
    setTimeout(() => { m.textContent = ''; }, 4000);
}
function fechaCorta(f) {
    if (!f) return '—';
    const p = String(f).substring(0,10).split('-');
    return p.length === 3 ? `${p[2]}/${p[1]}/${p[0]}` : f;
}

// ── Carga inicial ───────────────────────────────────────────────────────────
async function cargar() {
    const [rc, rp, rk, re, rr] = await Promise.all([
        fetch(`${API}?action=listar_cotizaciones`).then(r => r.json()).catch(() => ({})),
        fetch(`${API}?action=listar_proveedores`).then(r => r.json()).catch(() => ({})),
        fetch(`${API}?action=listar_catalogo`).then(r => r.json()).catch(() => ({})),
        fetch('../api/usuarios.php?action=listar_empresas').then(r => r.json()).catch(() => ({})),
        fetch(`${API}?action=resumen`).then(r => r.json()).catch(() => ({})),
    ]);
    cotizaciones = rc.success ? rc.data : [];
    proveedores  = rp.success ? rp.data : [];
    catalogo     = rk.success ? rk.data : [];
    empresas     = re.success ? re.data : (Array.isArray(re) ? re : []);

    document.getElementById('k-empresa').innerHTML =
        '<option value="">— Otro / prospecto —</option>' +
        empresas.map(e => `<option value="${e.id}">${esc(e.nombre)}</option>`).join('');
    document.getElementById('k-catalogo').innerHTML =
        '<option value="">Traer del catálogo de precios…</option>' +
        catalogo.map(c => `<option value="${c.id}">${esc(c.descripcion)} — ${money(c.costo)}${c.proveedor_nombre ? ' (' + esc(c.proveedor_nombre) + ')' : ''}</option>`).join('');

    pintarKpis(rr.success ? rr.data : null);
    render();
}

function pintarKpis(r) {
    const c = document.getElementById('kpis');
    if (!r) { c.innerHTML = ''; return; }
    c.innerHTML = `
        <div class="kpi"><div class="v">${r.total}</div><div class="l">Cotizaciones registradas</div></div>
        <div class="kpi ok"><div class="v">${r.aceptadas}</div><div class="l">Aceptadas</div></div>
        <div class="kpi ok"><div class="v">${money(r.venta_aceptada)}</div><div class="l">Venta aceptada</div></div>
        <div class="kpi pur"><div class="v">${money(r.utilidad_aceptada)}</div><div class="l">Utilidad de lo aceptado</div></div>
        <div class="kpi warn"><div class="v">${money(r.venta_pendiente)}</div><div class="l">En borrador / enviadas</div></div>
        <div class="kpi"><div class="v">${r.margen_promedio}%</div><div class="l">Margen promedio</div></div>`;
}

// ── Listado ─────────────────────────────────────────────────────────────────
function render() {
    const q  = document.getElementById('busca').value.toLowerCase();
    const fe = document.getElementById('filtroEstado').value;
    const data = cotizaciones.filter(c =>
        (!q || (c.folio + ' ' + c.cliente_nombre).toLowerCase().includes(q)) &&
        (!fe || c.estado === fe));

    const cont = document.getElementById('tabla');
    if (!data.length) {
        cont.innerHTML = '<div class="empty"><div class="ic">💰</div><p>Sin cotizaciones todavía. Crea la primera con “Nueva cotización”.</p></div>';
        return;
    }
    cont.innerHTML = `<table>
        <thead><tr>
            <th>Folio</th><th>Fecha</th><th>Cliente</th><th class="n">Partidas</th>
            <th class="n">Costo</th><th class="n">Venta</th><th class="n">Utilidad</th>
            <th class="n">Margen</th><th class="n">Markup</th><th>Estado</th><th></th>
        </tr></thead>
        <tbody>${data.map(c => `<tr>
            <td style="font-weight:700">${esc(c.folio)}</td>
            <td>${fechaCorta(c.fecha)}</td>
            <td>${esc(c.cliente_nombre)}</td>
            <td class="n">${c.num_partidas}</td>
            <td class="n">${money(c.total_costo)}</td>
            <td class="n" style="font-weight:700">${money(c.total_venta)}</td>
            <td class="n util ${Number(c.utilidad) < 0 ? 'neg' : ''}">${money(c.utilidad)}</td>
            <td class="n">${c.margen_pct}%</td>
            <td class="n">${c.markup_pct}%</td>
            <td><span class="badge b-${esc(c.estado)}">${esc(c.estado)}</span></td>
            <td style="white-space:nowrap;text-align:right">
                <button class="btn btn-warning btn-sm" onclick="editar(${c.id})" title="Editar">✏️</button>
                <button class="btn btn-ghost btn-sm" onclick="imprimir(${c.id})" title="Imprimir para el cliente">🖨️</button>
                <button class="btn btn-danger btn-sm" onclick="borrar(${c.id})" title="Eliminar">🗑️</button>
            </td></tr>`).join('')}</tbody></table>`;
}

// ── Editor ──────────────────────────────────────────────────────────────────
let cotActual = null;

function nueva() {
    cotActual = null;
    document.getElementById('tituloCot').textContent = 'Nueva cotización';
    document.getElementById('k-id').value = '';
    document.getElementById('k-empresa').value = '';
    document.getElementById('k-cliente').value = '';
    document.getElementById('k-contacto').value = '';
    document.getElementById('k-fecha').value = new Date().toISOString().substring(0,10);
    document.getElementById('k-vigencia').value = 15;
    document.getElementById('k-estado').value = 'borrador';
    document.getElementById('k-notas').value = '';
    document.getElementById('items').innerHTML = '';
    document.getElementById('btnImprimir').style.display = 'none';
    agregarFila();
    recalcular();
    document.getElementById('modalCot').classList.add('open');
}

async function editar(id) {
    const r = await fetch(`${API}?action=obtener_cotizacion&id=${id}`);
    const d = await r.json().catch(() => ({}));
    if (!r.ok || !d.success) { aviso(d.error || 'No se pudo abrir la cotización', false); return; }
    const c = d.data;
    cotActual = c;

    document.getElementById('tituloCot').textContent = 'Cotización ' + c.folio;
    document.getElementById('k-id').value = c.id;
    document.getElementById('k-empresa').value = c.empresa_id || '';
    document.getElementById('k-cliente').value = c.cliente_nombre || '';
    document.getElementById('k-contacto').value = c.contacto || '';
    document.getElementById('k-fecha').value = (c.fecha || '').substring(0,10);
    document.getElementById('k-vigencia').value = c.vigencia_dias;
    document.getElementById('k-estado').value = c.estado;
    document.getElementById('k-notas').value = c.notas || '';
    document.getElementById('btnImprimir').style.display = '';

    document.getElementById('items').innerHTML = '';
    (c.items || []).forEach(it => agregarFila(it));
    if (!(c.items || []).length) agregarFila();
    recalcular();
    document.getElementById('modalCot').classList.add('open');
}

function empresaElegida() {
    const sel = document.getElementById('k-empresa');
    if (sel.value) {
        const e = empresas.find(x => String(x.id) === sel.value);
        if (e) document.getElementById('k-cliente').value = e.nombre;
    }
}

function agregarFila(it) {
    it = it || {};
    const tr = document.createElement('tr');
    const opts = '<option value="">—</option>' +
        proveedores.map(p => `<option value="${p.id}"${String(p.id) === String(it.proveedor_id || '') ? ' selected' : ''}>${esc(p.nombre)}</option>`).join('');
    tr.innerHTML = `
        <td><input type="text" class="i-desc" value="${esc(it.descripcion || '')}" placeholder="Ej: Recarga extintor PQS 9 kg"></td>
        <td><input type="number" class="i-cant n" step="0.01" min="0" value="${it.cantidad != null ? it.cantidad : 1}" oninput="recalcular()"></td>
        <td><input type="text" class="i-unidad" value="${esc(it.unidad || '')}" placeholder="pza"></td>
        <td><select class="i-prov">${opts}</select></td>
        <td><input type="number" class="i-costo n" step="0.01" min="0" value="${it.costo_unitario != null ? it.costo_unitario : 0}" oninput="recalcular()"></td>
        <td><input type="number" class="i-precio n" step="0.01" min="0" value="${it.precio_unitario != null ? it.precio_unitario : 0}" oninput="recalcular()"></td>
        <td class="ro i-util">$0.00</td>
        <td class="ro i-pct">0%</td>
        <td><button type="button" class="del" onclick="this.closest('tr').remove();recalcular()">✕</button></td>`;
    document.getElementById('items').appendChild(tr);
    recalcular();
}

function desdeCatalogo() {
    const sel = document.getElementById('k-catalogo');
    const c = catalogo.find(x => String(x.id) === sel.value);
    sel.value = '';
    if (!c) return;
    agregarFila({
        descripcion: c.descripcion,
        cantidad: 1,
        unidad: c.unidad || '',
        proveedor_id: c.proveedor_id,
        costo_unitario: c.costo,
        precio_unitario: 0,
    });
}

function leerItems() {
    return Array.from(document.querySelectorAll('#items tr')).map(tr => ({
        descripcion:     tr.querySelector('.i-desc').value.trim(),
        cantidad:        parseFloat(tr.querySelector('.i-cant').value) || 0,
        unidad:          tr.querySelector('.i-unidad').value.trim(),
        proveedor_id:    parseInt(tr.querySelector('.i-prov').value) || 0,
        costo_unitario:  parseFloat(tr.querySelector('.i-costo').value) || 0,
        precio_unitario: parseFloat(tr.querySelector('.i-precio').value) || 0,
    }));
}

function recalcular() {
    let costo = 0, venta = 0;
    document.querySelectorAll('#items tr').forEach(tr => {
        const cant = parseFloat(tr.querySelector('.i-cant').value) || 0;
        const cu   = parseFloat(tr.querySelector('.i-costo').value) || 0;
        const pu   = parseFloat(tr.querySelector('.i-precio').value) || 0;
        const sc = cant * cu, sv = cant * pu, u = sv - sc;
        costo += sc; venta += sv;
        const eUtil = tr.querySelector('.i-util'), ePct = tr.querySelector('.i-pct');
        eUtil.textContent = money(u);
        eUtil.style.color = u < 0 ? '#c0392b' : '#047857';
        ePct.textContent  = sv > 0 ? (u / sv * 100).toFixed(1) + '%' : '0%';
    });
    const util = venta - costo;
    document.getElementById('t-costo').textContent  = money(costo);
    document.getElementById('t-venta').textContent  = money(venta);
    const tu = document.getElementById('t-util');
    tu.textContent = money(util);
    tu.style.color = util < 0 ? '#c0392b' : '#047857';
    document.getElementById('t-margen').textContent = (venta > 0 ? (util / venta * 100).toFixed(1) : '0.0') + '%';
    document.getElementById('t-markup').textContent = (costo > 0 ? (util / costo * 100).toFixed(1) : '0.0') + '%';
}

async function guardarCot() {
    const items = leerItems().filter(i => i.descripcion !== '');
    const body = {
        id:             parseInt(document.getElementById('k-id').value) || 0,
        empresa_id:     parseInt(document.getElementById('k-empresa').value) || 0,
        cliente_nombre: document.getElementById('k-cliente').value.trim(),
        contacto:       document.getElementById('k-contacto').value.trim(),
        fecha:          document.getElementById('k-fecha').value,
        vigencia_dias:  parseInt(document.getElementById('k-vigencia').value) || 0,
        estado:         document.getElementById('k-estado').value,
        notas:          document.getElementById('k-notas').value.trim(),
        items:          items,
    };
    if (!body.cliente_nombre) { alert('Indica quién solicita la cotización.'); return; }
    if (!items.length)        { alert('Agrega al menos una partida con descripción.'); return; }

    const r = await fetch(`${API}?action=guardar_cotizacion`, {
        method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body)
    });
    const d = await r.json().catch(() => ({}));
    if (r.ok && d.success) { cerrar('modalCot'); aviso('✓ Cotización guardada', true); cargar(); }
    else aviso(d.error || 'Error al guardar', false);
}

async function borrar(id) {
    if (!confirm('¿Eliminar esta cotización y todas sus partidas?')) return;
    const r = await fetch(`${API}?action=eliminar_cotizacion&id=${id}`);
    const d = await r.json().catch(() => ({}));
    if (r.ok && d.success) { aviso('✓ Cotización eliminada', true); cargar(); }
    else aviso(d.error || 'Error al eliminar', false);
}

// ── Impresión para el cliente (sin costos ni utilidad) ──────────────────────
async function imprimir(id) {
    const r = await fetch(`${API}?action=obtener_cotizacion&id=${id}`);
    const d = await r.json().catch(() => ({}));
    if (!r.ok || !d.success) { aviso(d.error || 'No se pudo abrir la cotización', false); return; }
    pintarImpresion(d.data);
}
function imprimirActual() { if (cotActual) imprimir(cotActual.id); }

function pintarImpresion(c) {
    const filas = (c.items || []).map((it, i) => {
        const imp = (parseFloat(it.cantidad) || 0) * (parseFloat(it.precio_unitario) || 0);
        return `<tr>
            <td style="text-align:center">${i + 1}</td>
            <td>${esc(it.descripcion)}</td>
            <td style="text-align:center">${Number(it.cantidad)}</td>
            <td style="text-align:center">${esc(it.unidad || '')}</td>
            <td style="text-align:right">${money(it.precio_unitario)}</td>
            <td style="text-align:right">${money(imp)}</td>
        </tr>`;
    }).join('');

    document.getElementById('areaImpresion').innerHTML = `
        <h1>Cotización ${esc(c.folio)}</h1>
        <div style="color:#555;margin-bottom:12px">AVBA Inspections · Fecha: ${fechaCorta(c.fecha)} · Vigencia: ${c.vigencia_dias} días</div>
        <div><b>Cliente:</b> ${esc(c.cliente_nombre)}</div>
        ${c.contacto ? `<div><b>Atención:</b> ${esc(c.contacto)}</div>` : ''}
        <table>
            <thead><tr><th style="width:35px">#</th><th>Descripción</th><th style="width:60px">Cant.</th>
            <th style="width:70px">Unidad</th><th style="width:100px">P. unitario</th><th style="width:110px">Importe</th></tr></thead>
            <tbody>${filas}</tbody>
            <tfoot><tr>
                <td colspan="5" style="text-align:right;font-weight:700">Total</td>
                <td style="text-align:right;font-weight:700">${money(c.total_venta)}</td>
            </tr></tfoot>
        </table>
        ${c.notas ? `<p style="margin-top:14px"><b>Notas:</b> ${esc(c.notas)}</p>` : ''}
        <p style="margin-top:18px;font-size:11px;color:#666">Precios en pesos mexicanos. Esta cotización no incluye IVA salvo que se indique lo contrario.</p>`;
    window.print();
}

document.querySelectorAll('.modal-ov').forEach(m =>
    m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); }));

cargar();
</script>
</body>
</html>
