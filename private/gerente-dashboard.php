<?php
require_once '../config/config.php';
require_once '../config/roles-extra.php';
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== ROLE_GERENTE) {
    header('Location: ../public/login.html'); exit;
}
$nombre = $_SESSION['nombre'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Panel de Gerente</title>
<style>
    *{margin:0;padding:0;box-sizing:border-box}
    body{font-family:'Segoe UI',system-ui,sans-serif;background:#eef2fb;color:#1a2138;min-height:100vh}

    .navbar{background:linear-gradient(135deg,#1e3a8a,#4338ca);color:#fff;padding:16px 28px;
            display:flex;justify-content:space-between;align-items:center;box-shadow:0 4px 16px rgba(30,58,138,.25)}
    .navbar h1{font-size:20px;font-weight:700;display:flex;align-items:center;gap:10px}
    .navbar .user{display:flex;align-items:center;gap:14px;font-size:13px}
    .navbar a{color:#fff;text-decoration:none;opacity:.9}
    .navbar a:hover{opacity:1;text-decoration:underline}

    .container{max-width:1300px;margin:0 auto;padding:32px 20px}
    .page-title{margin-bottom:6px;font-size:26px;font-weight:800;color:#1e293b}
    .page-sub{color:#64748b;font-size:14px;margin-bottom:28px}

    .cards-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:22px}

    .card{position:relative;border-radius:18px;overflow:hidden;cursor:pointer;
          background:#fff;box-shadow:0 6px 20px rgba(30,41,59,.10);
          transition:transform .18s ease,box-shadow .18s ease;text-decoration:none;color:inherit;display:block}
    .card:hover{transform:translateY(-6px);box-shadow:0 16px 32px rgba(30,41,59,.20)}

    .card-top{padding:22px 22px 16px;color:#fff;
              background:linear-gradient(135deg,var(--c1),var(--c2))}
    .card-top .emp{font-size:18px;font-weight:800;line-height:1.25;margin-bottom:4px}
    .card-top .dom{font-size:12px;opacity:.9;line-height:1.4;min-height:17px}

    .ring-row{display:flex;align-items:center;gap:18px;padding:20px 22px}
    .ring{--p:0;--size:84px;width:var(--size);height:var(--size);border-radius:50%;flex-shrink:0;
          display:grid;place-items:center;
          background:conic-gradient(var(--ring) calc(var(--p)*1%), #e6eaf3 0)}
    .ring::before{content:'';position:absolute;width:64px;height:64px;border-radius:50%;background:#fff}
    .ring span{position:relative;font-size:19px;font-weight:800;color:#1e293b}
    .ring-wrap{position:relative;display:grid;place-items:center}

    .metrics{flex:1;display:flex;flex-direction:column;gap:10px}
    .metric{display:flex;align-items:center;justify-content:space-between}
    .metric .lbl{font-size:12px;color:#64748b;font-weight:600}
    .metric .val{font-size:17px;font-weight:800;color:#1e293b}
    .metric .val.alert{color:#dc2626}

    .card-foot{padding:12px 22px;border-top:1px solid #eef1f7;display:flex;justify-content:space-between;
               align-items:center;font-size:12px;color:#4338ca;font-weight:700}
    .card-foot .arrow{transition:transform .18s}
    .card:hover .card-foot .arrow{transform:translateX(4px)}

    .empty{grid-column:1/-1;text-align:center;padding:70px 20px;background:#fff;border-radius:16px;color:#94a3b8}
    .empty .ic{font-size:60px;margin-bottom:14px}
    .loading{grid-column:1/-1;text-align:center;padding:60px;color:#94a3b8}
    .spin{display:inline-block;width:26px;height:26px;border:3px solid #dbe3f4;border-top-color:#4338ca;
          border-radius:50%;animation:giro .8s linear infinite}
    @keyframes giro{to{transform:rotate(360deg)}}

    @media(max-width:600px){
        .navbar h1{font-size:17px}
        .container{padding:20px 14px}
        .cards-grid{grid-template-columns:1fr}
    }
</style>
</head>
<body>
<div class="navbar">
    <h1>📊 Panel de Gerente</h1>
    <div class="user">
        <span>👤 <?= htmlspecialchars($nombre) ?></span>
        <a href="#" onclick="cerrarSesion();return false;">Cerrar sesión</a>
    </div>
</div>

<div class="container">
    <div class="page-title">Mis clientes</div>
    <div class="page-sub">Selecciona un cliente para ver sus extintores y estadísticas.</div>

    <div class="cards-grid" id="cards">
        <div class="loading"><span class="spin"></span></div>
    </div>
</div>

<script>
// Paletas de color para las tarjetas (se asignan por orden)
const PALETAS = [
    ['#2563eb','#4338ca','#4338ca'], ['#0891b2','#0e7490','#0e7490'],
    ['#7c3aed','#6d28d9','#6d28d9'], ['#059669','#047857','#047857'],
    ['#ea580c','#c2410c','#c2410c'], ['#db2777','#be185d','#be185d']
];

async function cargar() {
    try {
        const r = await fetch('../api/gerente.php?action=mis_empresas');
        const d = await r.json();
        if (!d.success) { render([]); return; }
        render(d.data || []);
    } catch (e) {
        document.getElementById('cards').innerHTML =
            '<div class="empty"><div class="ic">⚠️</div><p>No se pudieron cargar tus clientes.</p></div>';
    }
}

function esc(s){const d=document.createElement('div');d.textContent=s==null?'':String(s);return d.innerHTML;}

function render(data) {
    const c = document.getElementById('cards');
    if (!data.length) {
        c.innerHTML = '<div class="empty"><div class="ic">🏢</div><p>Aún no tienes clientes asignados.</p>' +
                      '<p style="font-size:13px;margin-top:6px">Pide al administrador que te asigne plantas.</p></div>';
        return;
    }
    c.innerHTML = data.map((e, i) => {
        const p = PALETAS[i % PALETAS.length];
        const pct = e.porcentaje || 0;
        return `
        <a class="card" href="gerente-empresa.php?empresa_id=${e.id}">
            <div class="card-top" style="--c1:${p[0]};--c2:${p[1]}">
                <div class="emp">${esc(e.nombre)}</div>
                <div class="dom">${esc(e.domicilio || '')}</div>
            </div>
            <div class="ring-row">
                <div class="ring-wrap">
                    <div class="ring" style="--p:${pct};--ring:${p[2]}"></div>
                    <span style="position:absolute;font-size:18px;font-weight:800;color:#1e293b">${pct}%</span>
                </div>
                <div class="metrics">
                    <div class="metric"><span class="lbl">Extintores</span><span class="val">${e.total_extintores}</span></div>
                    <div class="metric"><span class="lbl">Inspeccionados (mes)</span><span class="val">${e.inspeccionados_mes}</span></div>
                    <div class="metric"><span class="lbl">PH vencidos</span><span class="val ${e.vencidos>0?'alert':''}">${e.vencidos}</span></div>
                </div>
            </div>
            <div class="card-foot"><span>Ver detalle</span><span class="arrow">→</span></div>
        </a>`;
    }).join('');
}

function cerrarSesion() {
    fetch('../api/auth.php?action=logout', {method:'POST'})
        .then(() => window.location.href = '../public/login.html')
        .catch(() => window.location.href = '../public/login.html');
}

cargar();
</script>
</body>
</html>
