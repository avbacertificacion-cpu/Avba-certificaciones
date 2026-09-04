<?php
require_once '../config/config.php';
require_once '../config/emisor.php';
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== ROLE_ADMIN) {
    header('Location: ../public/login.html'); exit;
}
$nombre = $_SESSION['nombre'];

/** Opciones "<clave> - <descripción>" para los selects de catálogos del SAT. */
function opcionesCatalogo(array $catalogo, string $seleccionada = ''): string {
    $html = '';
    foreach ($catalogo as $clave => $desc) {
        $html .= '<option value="' . htmlspecialchars($clave) . '"'
               . ($clave === $seleccionada ? ' selected' : '') . '>'
               . htmlspecialchars($clave . ' - ' . $desc) . '</option>';
    }
    return $html;
}

// Valores por defecto: los que trae el formato de la empresa
$optRegimen = opcionesCatalogo(catRegimenFiscal());
$optUso     = opcionesCatalogo(catUsoCfdi(), 'G01');
$optMetodo  = opcionesCatalogo(catMetodoPago(), 'PPD');
$optForma   = opcionesCatalogo(catFormaPago(), '99');
$optUnidad  = opcionesCatalogo(catClaveUnidad());
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
    .b-pendiente{background:#fef3c7;color:#92400e}
    .b-aceptada{background:#dbeafe;color:#1d4ed8}
    .b-pagada{background:#d1fae5;color:#047857}
    .b-rechazada{background:#fee2e2;color:#b91c1c}
    .util{font-weight:800;color:#047857}
    .util.neg{color:#c0392b}

    .modal-ov{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100;align-items:flex-start;justify-content:center;padding:20px;overflow-y:auto}
    .modal-ov.open{display:flex}
    .modal{background:#fff;border-radius:14px;width:100%;max-width:1180px;padding:24px;margin:auto}
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

    .pct-bar{background:#f1f5fb;border:2px solid #dbe3f7;border-radius:12px;padding:14px 16px;margin-bottom:16px}
    .pct-bar label{display:block;font-size:12px;font-weight:700;color:#475569;margin-bottom:6px}
    .pct-campos{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
    .pct-campos input{width:100px;padding:9px;border:2px solid #e0e0ff;border-radius:8px;font-size:15px;font-weight:700;text-align:right;background:#fff}
    .pct-campos input:focus,.pct-campos select:focus{outline:none;border-color:#667eea}
    .pct-campos select{padding:9px;border:2px solid #e0e0ff;border-radius:8px;font-size:13px;background:#fff}
    .pct-nota{font-size:12px;color:#64748b;margin-top:8px}
    .items input.manual{border-color:#f39c12;background:#fffbeb}
    /* Las tres cifras del negocio —lo que compré, la ganancia y el precio al
       cliente— se agrupan para que se lean de un vistazo. */
    .items th.col-dinero{background:#e8effb}
    .items td.col-dinero{background:#f8faff}
    .items td.i-importe{font-weight:700;color:#1e293b}
    .items .th-nota{display:block;font-weight:400;text-transform:none;font-size:9px;color:#64748b;margin-top:1px}
    .items .rest{background:none;border:none;cursor:pointer;font-size:13px;padding:0 4px;color:#f39c12}

    .tot{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;background:#f8faff;
         border:2px solid #e0e7ff;border-radius:12px;padding:14px;margin-top:6px}
    .tot div span{display:block;font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase}
    .tot div b{font-size:19px;color:#1e293b}

    .empty{text-align:center;padding:50px 20px;color:#94a3b8}.empty .ic{font-size:52px;margin-bottom:10px}
    .msg{font-size:13px;font-weight:600;margin-bottom:12px}
    .hint{font-size:11px;color:#94a3b8;margin-top:4px}
    .fiscal{border:2px solid #e0e0ff;border-radius:10px;padding:12px 14px;margin-bottom:14px;background:#fafbff}
    .fiscal summary{cursor:pointer;font-weight:700;font-size:13px;color:#475569}
    .fiscal summary .hint-sum{font-weight:400;color:#94a3b8;font-size:12px;margin-left:6px}
    .fiscal[open] summary{margin-bottom:12px}
    .chk{display:flex;align-items:center;gap:7px;font-size:12px;font-weight:700;color:#475569;cursor:pointer}
    .chk input{width:auto}
    .col-sat{display:none}
    .ver-sat .col-sat{display:table-cell}
    /* Con tantas columnas, la tabla se desborda y se recorre en horizontal:
       si se dejara encoger, las cifras del negocio quedan ilegibles. */
    .items{min-width:1074px}
    .items.ver-sat{min-width:1434px}
    .items td.sel,.items th:first-child{text-align:center}
    .items .i-sel{width:auto;cursor:pointer}
    /* Una partida fuera de la selección se atenúa, pero sigue ahí: la
       cotización se guarda y se imprime completa. */
    .items tr.fuera{opacity:.4}
    .sel-bar{display:flex;gap:9px;align-items:center;flex-wrap:wrap;background:#f1f5fb;
             border:2px solid #dbe3f7;border-radius:10px;padding:9px 12px;margin-bottom:10px}
    .sel-bar .sel-et{font-size:12px;font-weight:700;color:#475569}
    /* width:auto para que no herede el 100% de los selects del formulario
       y quepa en la misma línea que el rótulo y los botones. */
    .sel-bar select{width:auto;min-width:210px;max-width:330px;padding:8px 10px;
                    border:2px solid #e0e0ff;border-radius:8px;font-size:13px;background:#fff}
    .sel-bar select:focus{outline:none;border-color:#667eea}
    .sel-info{font-size:12px;color:#64748b}
    .tot-cab{font-size:12px;font-weight:700;color:#92400e;background:#fef3c7;border-radius:8px 8px 0 0;
             padding:8px 12px;margin-top:6px;display:none}
    .tot-cab.visible{display:block}
    @media(max-width:800px){ .grid4,.grid2{grid-template-columns:1fr} }

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
            <option value="pendiente">Pendiente</option>
            <option value="aceptada">Aceptada</option>
            <option value="pagada">Pagada</option>
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
                    <option value="pendiente">Pendiente</option>
                    <option value="aceptada">Aceptada</option>
                    <option value="pagada">Pagada</option>
                    <option value="rechazada">Rechazada</option>
                </select>
            </div>
        </div>

        <details class="fiscal" id="boxFiscal">
            <summary>Datos fiscales del presupuesto <span class="hint-sum">RFC, uso de CFDI, forma de pago y claves del SAT</span></summary>

            <div class="grid4">
                <div class="fg"><label>RFC del cliente</label><input type="text" id="k-rfc" maxlength="13" placeholder="XAXX010101000"></div>
                <div class="fg"><label>Régimen fiscal del cliente</label>
                    <select id="k-regimen"><option value="">— Sin especificar —</option><?= $optRegimen ?></select>
                </div>
                <div class="fg"><label>C.P. del cliente</label><input type="text" id="k-cp" maxlength="10" placeholder="89600"></div>
                <div class="fg"><label>Uso del CFDI</label>
                    <select id="k-uso"><?= $optUso ?></select>
                </div>
            </div>

            <div class="grid4">
                <div class="fg"><label>Método de pago</label><select id="k-metodo"><?= $optMetodo ?></select></div>
                <div class="fg"><label>Forma de pago</label><select id="k-forma"><?= $optForma ?></select></div>
                <div class="fg"><label>Moneda</label>
                    <select id="k-moneda"><option value="MXN">MXN — Peso mexicano</option><option value="USD">USD — Dólar</option><option value="EUR">EUR — Euro</option></select>
                </div>
                <div class="fg"><label>Tipo de cambio</label><input type="number" id="k-tc" step="0.000001" min="0.000001" value="1"></div>
            </div>

            <div class="grid4">
                <div class="fg"><label>Condiciones de pago</label><input type="text" id="k-condiciones" placeholder="Ej: 50% anticipo, 50% contra entrega"></div>
                <div class="fg"><label>Clave prod/serv del SAT</label><input type="text" id="k-prodserv" maxlength="20" placeholder="90101802">
                    <div class="hint">Se usa en todas las partidas que no traigan la suya.</div>
                </div>
                <div class="fg"><label>Clave de unidad del SAT</label>
                    <select id="k-unidad"><option value="">— Sin especificar —</option><?= $optUnidad ?></select>
                </div>
                <div class="fg" style="display:flex;align-items:flex-end">
                    <label class="chk"><input type="checkbox" id="k-verClaves" onchange="alternarClaves()"> Capturar claves por partida</label>
                </div>
            </div>
        </details>

        <div class="pct-bar">
            <div>
                <label>% de ganancia de esta cotización</label>
                <div class="pct-campos">
                    <input type="number" id="k-pct" step="0.1" min="0" value="40" oninput="aplicarPorcentaje()">
                    <span>% sobre el costo</span>
                    <button class="btn btn-primary btn-sm" onclick="aplicarPorcentaje(true)">↻ Recalcular todas</button>
                </div>
            </div>
            <div class="pct-nota" id="pct-nota"></div>
        </div>

        <div class="fg">
            <label>Partidas</label>

            <div class="sel-bar">
                <span class="sel-et">Ver totales de</span>
                <select id="k-filtroProv" onchange="filtrarPorProveedor()">
                    <option value="">Todas las partidas</option>
                </select>
                <button type="button" class="btn btn-ghost btn-sm" onclick="marcarTodas(true)">Marcar todas</button>
                <button type="button" class="btn btn-ghost btn-sm" onclick="marcarTodas(false)">Ninguna</button>
                <span class="sel-info" id="sel-info"></span>
            </div>

            <div style="overflow-x:auto">
            <table class="items">
                <thead><tr>
                    <th style="width:34px" title="Partidas incluidas en los totales">
                        <input type="checkbox" id="selTodas" onclick="marcarTodas(this.checked)" checked></th>
                    <th style="min-width:135px">Proveedor (a quién se lo pedí)</th>
                    <th style="min-width:150px">Producto del proveedor</th>
                    <th style="min-width:165px">Descripción para el cliente</th>
                    <th style="width:78px">Cant.</th>
                    <th style="width:76px">Unidad</th>
                    <th class="col-sat" style="width:92px">Código</th>
                    <th class="col-sat" style="width:96px">Clave<br>prod/serv</th>
                    <th class="col-sat" style="width:86px">Clave<br>unidad</th>
                    <th class="col-sat" style="width:74px">IVA</th>
                    <th class="col-dinero" style="width:112px">Costo unit.<br><span class="th-nota">lo que compré</span></th>
                    <th class="col-dinero" style="width:88px">% util.<br><span class="th-nota">ganancia</span></th>
                    <th class="col-dinero" style="width:145px">Precio venta<br><span class="th-nota">unitario al cliente</span></th>
                    <th class="col-dinero" style="width:112px">Importe<br><span class="th-nota">lo que paga</span></th>
                    <th style="width:100px">Utilidad</th>
                    <th style="width:36px"></th>
                </tr></thead>
                <tbody id="items"></tbody>
            </table>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
                <button class="btn btn-ghost btn-sm" onclick="agregarFila()">＋ Agregar partida</button>
                <span class="hint" style="margin:0">Elige el proveedor y su lista de productos aparecerá en la siguiente columna con su costo.</span>
            </div>
        </div>

        <div class="tot-cab" id="tot-cab"></div>
        <div class="tot">
            <div><span>Costo total</span><b id="t-costo">$0.00</b></div>
            <div><span>Subtotal (sin IVA)</span><b id="t-venta">$0.00</b></div>
            <div><span>Utilidad</span><b id="t-util" style="color:#047857">$0.00</b></div>
            <div><span>% de ganancia (sobre costo)</span><b id="t-ganancia">0%</b></div>
            <div><span>IVA trasladado</span><b id="t-iva">$0.00</b></div>
            <div><span>Total con IVA</span><b id="t-total">$0.00</b></div>
        </div>

        <div class="fg" style="margin-top:14px"><label>Notas / condiciones</label><textarea id="k-notas" rows="2" placeholder="Tiempo de entrega, forma de pago…"></textarea></div>

        <div class="modal-actions">
            <button class="btn btn-warning" onclick="cerrar('modalCot')">Cancelar</button>
            <button class="btn btn-ghost" id="btnDocs" onclick="docsActual()" style="display:none">📎 Documentos</button>
            <button class="btn btn-ghost" id="btnImprimir" onclick="imprimirActual('cliente')" style="display:none">🖨️ Copia del cliente</button>
            <button class="btn btn-ghost" id="btnInterna" onclick="imprimirActual('interna')" style="display:none">🔒 Copia interna</button>
            <button class="btn btn-primary" onclick="guardarCot()">Guardar cotización</button>
        </div>
    </div>
</div>

<?php include __DIR__ . '/documentos-widget.php'; ?>


<script>
const API = '../api/cotizaciones.php';
let cotizaciones = [], proveedores = [], catalogo = [], empresas = [], adjuntos = {};

const esc = s => { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; };
const money = n => '$' + Number(n || 0).toLocaleString('es-MX', {minimumFractionDigits:2, maximumFractionDigits:2});
const cerrar = id => document.getElementById(id).classList.remove('open');
const num = id => parseFloat(document.getElementById(id).value) || 0;
function aviso(t, ok) {
    const m = document.getElementById('msg');
    m.textContent = t; m.style.color = ok ? '#27ae60' : '#c0392b';
    setTimeout(() => { m.textContent = ''; }, 4000);
}
/** El último porcentaje usado se recuerda, que casi siempre es el mismo. */
function pctPorDefecto() {
    const v = parseFloat(localStorage.getItem('avba_cot_pct'));
    return (isFinite(v) && v > 0) ? v : 40;
}

/** Opciones de IVA de una partida (16% general, 8% fronterizo, 0%). */
function opcionesIva(tasa) {
    const t = (tasa === undefined || tasa === null || tasa === '') ? 16 : Number(tasa);
    return [16, 8, 0].map(v =>
        `<option value="${v}"${v === t ? ' selected' : ''}>${v}%</option>`).join('');
}

/** Muestra u oculta las columnas de claves del SAT en las partidas. */
function alternarClaves() {
    const ver = document.getElementById('k-verClaves').checked;
    document.querySelector('.items').classList.toggle('ver-sat', ver);
    try { localStorage.setItem('avba_cot_sat', ver ? '1' : ''); } catch (e) {}
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
    adjuntos = await contarDocs('cotizacion');

    pintarKpis(rr.success ? rr.data : null);
    render();
}

/** Abre el panel de documentos de una cotización. */
function docsDe(id) {
    const c = cotizaciones.find(x => x.id === id);
    abrirDocs('cotizacion', id, c ? `${c.folio} — ${c.cliente_nombre}` : '');
}
// Al cerrar el panel se refrescan los contadores de adjuntos del listado
window.alCerrarDocs = async function () { adjuntos = await contarDocs('cotizacion'); render(); };

function pintarKpis(r) {
    const c = document.getElementById('kpis');
    if (!r) { c.innerHTML = ''; return; }
    c.innerHTML = `
        <div class="kpi"><div class="v">${r.total}</div><div class="l">Cotizaciones registradas</div></div>
        <div class="kpi ok"><div class="v">${r.aceptadas}</div><div class="l">Aceptadas y pagadas</div></div>
        <div class="kpi ok"><div class="v">${money(r.venta_aceptada)}</div><div class="l">Venta ganada</div></div>
        <div class="kpi pur"><div class="v">${money(r.utilidad_aceptada)}</div><div class="l">Utilidad de lo ganado</div></div>
        <div class="kpi ok"><div class="v">${money(r.venta_pagada || 0)}</div><div class="l">Ya cobrado</div></div>
        <div class="kpi warn"><div class="v">${money(r.venta_pendiente)}</div><div class="l">Pendientes de respuesta</div></div>
        <div class="kpi"><div class="v">${r.ganancia_promedio}%</div><div class="l">% de ganancia promedio</div></div>`;
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
            <th class="n">% ganancia</th><th>Estado</th><th>Docs.</th><th></th>
        </tr></thead>
        <tbody>${data.map(c => `<tr>
            <td style="font-weight:700">${esc(c.folio)}</td>
            <td>${fechaCorta(c.fecha)}</td>
            <td>${esc(c.cliente_nombre)}</td>
            <td class="n">${c.num_partidas}</td>
            <td class="n">${money(c.total_costo)}</td>
            <td class="n" style="font-weight:700">${money(c.total_venta)}</td>
            <td class="n util ${Number(c.utilidad) < 0 ? 'neg' : ''}">${money(c.utilidad)}</td>
            <td class="n">${c.markup_pct}%</td>
            <td><span class="badge b-${esc(c.estado)}">${esc(c.estado)}</span></td>
            <td><button class="btn btn-ghost btn-sm" onclick="docsDe(${c.id})" title="Evidencias, facturas y documentación">📎 ${adjuntos[c.id] || 0}</button></td>
            <td style="white-space:nowrap;text-align:right">
                <button class="btn btn-warning btn-sm" onclick="editar(${c.id})" title="Editar">✏️</button>
                <button class="btn btn-ghost btn-sm" onclick="imprimir(${c.id},'cliente')" title="Copia para el cliente (sólo precios de venta)">🖨️</button>
                <button class="btn btn-ghost btn-sm" onclick="imprimir(${c.id},'interna')" title="Copia interna (con costos y utilidad)">🔒</button>
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
    document.getElementById('k-estado').value = 'pendiente';
    document.getElementById('k-notas').value = '';
    ponerFiscales({});
    document.getElementById('k-pct').value = pctPorDefecto();
    document.getElementById('items').innerHTML = '';
    document.getElementById('k-filtroProv').value = '';
    document.getElementById('btnImprimir').style.display = 'none';
    document.getElementById('btnInterna').style.display = 'none';
    document.getElementById('btnDocs').style.display = 'none';
    agregarFila();
    aplicarPorcentaje();
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
    ponerFiscales(c);
    document.getElementById('k-pct').value  = Number(c.utilidad_pct) > 0 ? Number(c.utilidad_pct) : pctPorDefecto();
    document.getElementById('btnImprimir').style.display = '';
    document.getElementById('btnInterna').style.display = '';
    document.getElementById('btnDocs').style.display = '';

    document.getElementById('items').innerHTML = '';
    document.getElementById('k-filtroProv').value = '';
    (c.items || []).forEach(it => agregarFila(it));
    if (!(c.items || []).length) agregarFila();
    aplicarPorcentaje();   // pinta la nota; los precios guardados quedan intactos
    document.getElementById('modalCot').classList.add('open');
}

/**
 * Vuelca los datos fiscales de una cotización al formulario. Los que no trae
 * (cotización nueva) caen en los valores con los que se emite normalmente.
 */
function ponerFiscales(c) {
    document.getElementById('k-rfc').value         = c.cliente_rfc || '';
    document.getElementById('k-regimen').value     = c.cliente_regimen || '';
    document.getElementById('k-cp').value          = c.cliente_cp || '';
    document.getElementById('k-uso').value         = c.uso_cfdi || 'G01';
    document.getElementById('k-metodo').value      = c.metodo_pago || 'PPD';
    document.getElementById('k-forma').value       = c.forma_pago || '99';
    document.getElementById('k-moneda').value      = c.moneda || 'MXN';
    document.getElementById('k-tc').value          = c.tipo_cambio ? Number(c.tipo_cambio) : 1;
    document.getElementById('k-condiciones').value = c.condiciones_pago || '';
    document.getElementById('k-prodserv').value    = c.clave_prodserv || '';
    document.getElementById('k-unidad').value      = c.clave_unidad || '';
}

function empresaElegida() {
    const sel = document.getElementById('k-empresa');
    if (!sel.value) return;
    const e = empresas.find(x => String(x.id) === sel.value);
    if (!e) return;
    document.getElementById('k-cliente').value = e.nombre;
    // Los datos fiscales del cliente sólo se rellenan si están vacíos: si el
    // admin ya escribió algo a mano para esta cotización, no se le pisa.
    const copiar = (id, valor) => {
        const campo = document.getElementById(id);
        if (valor && !campo.value) campo.value = valor;
    };
    copiar('k-rfc', e.rfc);
    copiar('k-regimen', e.regimen_fiscal);
    copiar('k-cp', e.cp);
}

function agregarFila(it) {
    it = it || {};
    const tr = document.createElement('tr');
    const opts = '<option value="">—</option>' +
        proveedores.map(p => `<option value="${p.id}"${String(p.id) === String(it.proveedor_id || '') ? ' selected' : ''}>${esc(p.nombre)}</option>`).join('');
    tr.innerHTML = `
        <td class="sel"><input type="checkbox" class="i-sel" checked onchange="recalcular()"></td>
        <td><select class="i-prov" onchange="cambioProveedor(this)">${opts}</select></td>
        <td><select class="i-cat" onchange="desdeCatalogo(this)"></select></td>
        <td><input type="text" class="i-desc" value="${esc(it.descripcion || '')}" placeholder="Ej: Recarga extintor PQS 9 kg"></td>
        <td><input type="number" class="i-cant n" step="0.01" min="0" value="${it.cantidad != null ? it.cantidad : 1}" oninput="recalcular()"></td>
        <td><input type="text" class="i-unidad" value="${esc(it.unidad || '')}" placeholder="pza"></td>
        <td class="col-sat"><input type="text" class="i-codigo" value="${esc(it.codigo || '')}" placeholder="—"></td>
        <td class="col-sat"><input type="text" class="i-prodserv" value="${esc(it.clave_prodserv || '')}" placeholder="general"></td>
        <td class="col-sat"><input type="text" class="i-claveunidad" value="${esc(it.clave_unidad || '')}" placeholder="general"></td>
        <td class="col-sat"><select class="i-iva" onchange="recalcular()">${opcionesIva(it.iva_tasa)}</select></td>
        <td class="col-dinero"><input type="number" class="i-costo n" step="0.01" min="0" value="${it.costo_unitario != null ? it.costo_unitario : 0}" oninput="cambioCosto(this)"></td>
        <td class="col-dinero"><input type="number" class="i-pct n" step="0.1" title="Porcentaje de utilidad de esta partida" oninput="pctManual(this)"></td>
        <td class="col-dinero" style="white-space:nowrap">
            <input type="number" class="i-precio n" step="0.01" min="0" value="${it.precio_unitario != null ? it.precio_unitario : 0}"
                   style="width:calc(100% - 24px)" oninput="precioManual(this)">
            <button type="button" class="rest" title="Volver a calcularlo con el porcentaje" onclick="restaurarPrecio(this)">↻</button>
        </td>
        <td class="col-dinero ro i-importe">$0.00</td>
        <td class="ro i-util">$0.00</td>
        <td><button type="button" class="del" onclick="quitarFila(this)">✕</button></td>`;
    document.getElementById('items').appendChild(tr);

    // Una partida que ya traía precio guardado se respeta tal cual
    marcarManual(tr, it.precio_unitario != null && Number(it.precio_unitario) > 0);

    llenarProductos(tr);
    // Si la partida vino del catálogo, se deja seleccionado su producto
    if (it.catalogo_id) tr.querySelector('.i-cat').value = it.catalogo_id;
    aplicarPorcentajeFila(tr);   // una partida nueva ya nace con su precio calculado
    refrescarFiltroProveedores();
    recalcular();
}

// ── Productos del proveedor elegido ─────────────────────────────────────────
/** Llena la lista de productos con los del proveedor seleccionado en esa fila. */
function llenarProductos(tr) {
    const prov = tr.querySelector('.i-prov').value;
    const sel  = tr.querySelector('.i-cat');
    const previo = sel.value;
    const items = catalogo.filter(c => prov ? String(c.proveedor_id) === prov : true);

    if (!prov) {
        sel.innerHTML = '<option value="">— Elige primero el proveedor —</option>';
        return;
    }
    if (!items.length) {
        sel.innerHTML = '<option value="">— Sin productos en el catálogo —</option>';
        return;
    }
    sel.innerHTML = '<option value="">— Elegir producto —</option>' +
        items.map(c => `<option value="${c.id}">${esc(c.descripcion)} · ${money(c.costo)}</option>`).join('');
    // Si el producto que ya tenía sigue siendo de este proveedor, se conserva
    if (previo && items.some(c => String(c.id) === previo)) sel.value = previo;
}

function cambioProveedor(sel) {
    llenarProductos(sel.closest('tr'));
    refrescarFiltroProveedores();
}

/** Al elegir un producto se traen su descripción, unidad y costo del proveedor. */
function desdeCatalogo(sel) {
    const tr = sel.closest('tr');
    const c  = catalogo.find(x => String(x.id) === sel.value);
    if (!c) return;
    tr.querySelector('.i-desc').value   = c.descripcion || '';
    tr.querySelector('.i-unidad').value = c.unidad || '';
    tr.querySelector('.i-costo').value  = Number(c.costo) || 0;
    // El precio vuelve a salir del porcentaje, aunque antes se hubiera escrito a mano
    marcarManual(tr, false);
    aplicarPorcentajeFila(tr);
    recalcular();
}

// ── Precio de venta a partir del porcentaje ─────────────────────────────────
/** Marca (o desmarca) una fila cuyo precio se escribió a mano. */
function marcarManual(tr, manual) {
    const inp = tr.querySelector('.i-precio');
    tr.dataset.manual = manual ? '1' : '';
    inp.classList.toggle('manual', !!manual);
    tr.querySelector('.i-pct').classList.toggle('manual', !!manual);
    inp.title = manual ? 'Precio propio de esta partida: el porcentaje general no lo cambia' : '';
    tr.querySelector('.rest').style.visibility = manual ? 'visible' : 'hidden';
}

function precioManual(inp) { marcarManual(inp.closest('tr'), true); recalcular(); }

/** Al escribir el % de una partida, su precio de venta se recalcula con ese porcentaje. */
function pctManual(inp) {
    const tr = inp.closest('tr');
    const costo = parseFloat(tr.querySelector('.i-costo').value) || 0;
    const pct   = parseFloat(inp.value) || 0;
    marcarManual(tr, true);
    tr.querySelector('.i-precio').value = precioDesdeCosto(costo, pct).toFixed(2);
    recalcular();
}
function restaurarPrecio(btn) { marcarManual(btn.closest('tr'), false); aplicarPorcentajeFila(btn.closest('tr')); recalcular(); }
function cambioCosto(inp) { aplicarPorcentajeFila(inp.closest('tr')); recalcular(); }

/** Calcula el precio de venta de una sola partida, si no se escribió a mano. */
function aplicarPorcentajeFila(tr) {
    if (tr.dataset.manual === '1') return;
    const pct   = parseFloat(document.getElementById('k-pct').value) || 0;
    const costo = parseFloat(tr.querySelector('.i-costo').value) || 0;
    tr.querySelector('.i-precio').value = precioDesdeCosto(costo, pct).toFixed(2);
}

/**
 * El porcentaje de ganancia siempre se calcula sobre el costo: lo que cuesta
 * 10 con 50% se vende en 15.
 */
function porcentajeDeFila(costo, precio) {
    costo  = Number(costo) || 0;
    precio = Number(precio) || 0;
    return costo > 0 ? (precio - costo) / costo * 100 : 0;
}

/** Precio de venta a partir del costo: precio = costo × (1 + %). */
function precioDesdeCosto(costo, pct) {
    costo = Number(costo) || 0;
    pct   = Number(pct) || 0;
    return costo > 0 ? costo * (1 + pct / 100) : 0;
}

/**
 * Aplica el porcentaje a todas las partidas.
 * @param {boolean} forzar  true = también reescribe los precios puestos a mano.
 */
function aplicarPorcentaje(forzar) {
    const pct = parseFloat(document.getElementById('k-pct').value) || 0;

    document.querySelectorAll('#items tr').forEach(tr => {
        if (forzar) marcarManual(tr, false);
        if (tr.dataset.manual === '1') return;
        const costo = parseFloat(tr.querySelector('.i-costo').value) || 0;
        tr.querySelector('.i-precio').value = precioDesdeCosto(costo, pct).toFixed(2);
    });

    document.getElementById('pct-nota').textContent =
        `Cada partida se vende a costo × ${(1 + pct / 100).toFixed(2)}: lo que cuesta $10 se cotiza en $${(10 * (1 + pct / 100)).toFixed(2)}. ` +
        `Puedes darle su propio % (o su propio precio) a cualquier partida en su renglón; queda en ámbar y este porcentaje ya no la toca.`;
    recalcular();
}

// ── Aislar partidas para ver sus totales ────────────────────────────────────
// Es sólo una lupa: la cotización se guarda y se imprime completa, sin importar
// qué esté marcado. Sirve para responder "¿cuánto de esto es del proveedor X?"

/** Rellena el filtro con los proveedores que de verdad aparecen en las partidas. */
function refrescarFiltroProveedores() {
    const sel = document.getElementById('k-filtroProv');
    const previo = sel.value;
    const usados = new Map();
    let hayLibres = false;
    document.querySelectorAll('#items tr').forEach(tr => {
        const id = tr.querySelector('.i-prov').value;
        if (!id) { hayLibres = true; return; }
        const p = proveedores.find(x => String(x.id) === id);
        if (p) usados.set(String(p.id), p.nombre);
    });

    sel.innerHTML = '<option value="">Todas las partidas</option>'
        + [...usados].map(([id, nom]) => `<option value="${id}">${esc(nom)}</option>`).join('')
        + (hayLibres ? '<option value="__sin">— Sin proveedor —</option>' : '');
    // Si el proveedor que estaba elegido sigue estando, se conserva
    sel.value = [...sel.options].some(o => o.value === previo) ? previo : '';
}

/** Marca sólo las partidas del proveedor elegido (o todas). */
function filtrarPorProveedor() {
    const elegido = document.getElementById('k-filtroProv').value;
    document.querySelectorAll('#items tr').forEach(tr => {
        const prov = tr.querySelector('.i-prov').value;
        const entra = !elegido || (elegido === '__sin' ? !prov : prov === elegido);
        tr.querySelector('.i-sel').checked = entra;
    });
    recalcular();
}

function marcarTodas(marcar) {
    document.querySelectorAll('#items .i-sel').forEach(c => { c.checked = marcar; });
    // Marcar a mano deja de corresponder a un proveedor concreto
    document.getElementById('k-filtroProv').value = '';
    recalcular();
}

function quitarFila(btn) {
    btn.closest('tr').remove();
    refrescarFiltroProveedores();
    recalcular();
}

function leerItems() {
    return Array.from(document.querySelectorAll('#items tr')).map(tr => ({
        descripcion:     tr.querySelector('.i-desc').value.trim(),
        cantidad:        parseFloat(tr.querySelector('.i-cant').value) || 0,
        unidad:          tr.querySelector('.i-unidad').value.trim(),
        proveedor_id:    parseInt(tr.querySelector('.i-prov').value) || 0,
        catalogo_id:     parseInt(tr.querySelector('.i-cat').value) || 0,
        costo_unitario:  parseFloat(tr.querySelector('.i-costo').value) || 0,
        precio_unitario: parseFloat(tr.querySelector('.i-precio').value) || 0,
        codigo:          tr.querySelector('.i-codigo').value.trim(),
        clave_prodserv:  tr.querySelector('.i-prodserv').value.trim(),
        clave_unidad:    tr.querySelector('.i-claveunidad').value.trim(),
        iva_tasa:        parseFloat(tr.querySelector('.i-iva').value) || 0,
    }));
}

function recalcular() {
    let costo = 0, venta = 0, iva = 0;          // sólo lo marcado
    let ventaTodo = 0, filas = 0, marcadas = 0; // el total real de la cotización
    document.querySelectorAll('#items tr').forEach(tr => {
        const cant = parseFloat(tr.querySelector('.i-cant').value) || 0;
        const cu   = parseFloat(tr.querySelector('.i-costo').value) || 0;
        const pu   = parseFloat(tr.querySelector('.i-precio').value) || 0;
        const sc = cant * cu, sv = cant * pu, u = sv - sc;

        filas++;
        ventaTodo += sv;
        const dentro = tr.querySelector('.i-sel').checked;
        tr.classList.toggle('fuera', !dentro);
        if (dentro) {
            marcadas++;
            costo += sc; venta += sv;
            // El IVA no es ingreso: se suma aparte y nunca entra en la utilidad
            iva += sv * ((parseFloat(tr.querySelector('.i-iva').value) || 0) / 100);
        }
        const eUtil = tr.querySelector('.i-util'), ePct = tr.querySelector('.i-pct');
        tr.querySelector('.i-importe').textContent = money(sv);
        eUtil.textContent = money(u);
        eUtil.style.color = u < 0 ? '#c0392b' : '#047857';
        // No se reescribe mientras se teclea en esa misma casilla
        if (ePct !== document.activeElement) {
            ePct.value = porcentajeDeFila(cu, pu).toFixed(1);
        }
    });
    const util = venta - costo;
    document.getElementById('t-costo').textContent  = money(costo);
    document.getElementById('t-venta').textContent  = money(venta);
    const tu = document.getElementById('t-util');
    tu.textContent = money(util);
    tu.style.color = util < 0 ? '#c0392b' : '#047857';
    document.getElementById('t-ganancia').textContent = (costo > 0 ? (util / costo * 100).toFixed(1) : '0.0') + '%';
    document.getElementById('t-iva').textContent   = money(iva);
    document.getElementById('t-total').textContent = money(venta + iva);

    // Aviso cuando lo que se ve no es la cotización completa
    const cab = document.getElementById('tot-cab');
    const parcial = marcadas !== filas;
    cab.classList.toggle('visible', parcial);
    if (parcial) {
        const prov = document.getElementById('k-filtroProv');
        const de = prov.value ? ` (${esc(prov.options[prov.selectedIndex].text)})` : '';
        cab.textContent = `Totales de ${marcadas} de ${filas} partidas${de}. `
            + `La cotización completa suma ${money(ventaTodo)} sin IVA y se guarda e imprime entera.`;
    }
    document.getElementById('selTodas').checked = filas > 0 && marcadas === filas;
    document.getElementById('sel-info').textContent =
        filas ? `${marcadas} de ${filas} partidas incluidas` : '';
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
        utilidad_pct:   parseFloat(document.getElementById('k-pct').value) || 0,
        utilidad_base:  'costo',
        notas:          document.getElementById('k-notas').value.trim(),
        cliente_rfc:      document.getElementById('k-rfc').value.trim(),
        cliente_regimen:  document.getElementById('k-regimen').value,
        cliente_cp:       document.getElementById('k-cp').value.trim(),
        uso_cfdi:         document.getElementById('k-uso').value,
        metodo_pago:      document.getElementById('k-metodo').value,
        forma_pago:       document.getElementById('k-forma').value,
        moneda:           document.getElementById('k-moneda').value,
        tipo_cambio:      parseFloat(document.getElementById('k-tc').value) || 1,
        condiciones_pago: document.getElementById('k-condiciones').value.trim(),
        clave_prodserv:   document.getElementById('k-prodserv').value.trim(),
        clave_unidad:     document.getElementById('k-unidad').value,
        items:          items,
    };
    if (!body.cliente_nombre) { alert('Indica quién solicita la cotización.'); return; }
    if (!items.length)        { alert('Agrega al menos una partida con descripción.'); return; }

    const r = await fetch(`${API}?action=guardar_cotizacion`, {
        method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body)
    });
    const d = await r.json().catch(() => ({}));
    if (r.ok && d.success) {
        try { localStorage.setItem('avba_cot_pct', String(body.utilidad_pct)); } catch (e) {}
        cerrar('modalCot'); aviso('✓ Cotización guardada', true); cargar();
    }
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
/**
 * Abre el presupuesto con el formato fiscal, listo para imprimir o guardar en PDF.
 * @param {string} vista  'cliente' (precios de venta) o 'interna' (con costos y utilidad).
 */
function imprimir(id, vista) {
    const q = vista === 'interna' ? '&vista=interna' : '';
    window.open(`../api/cotizacion_pdf.php?id=${id}${q}`, '_blank');
}
function imprimirActual(vista) { if (cotActual) imprimir(cotActual.id, vista); }
function docsActual() { if (cotActual) docsDe(cotActual.id); }

// La preferencia de mostrar las claves del SAT se recuerda entre sesiones
try {
    if (localStorage.getItem('avba_cot_sat')) {
        document.getElementById('k-verClaves').checked = true;
        alternarClaves();
    }
} catch (e) {}

document.querySelectorAll('.modal-ov').forEach(m =>
    m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); }));

cargar();
</script>
</body>
</html>
