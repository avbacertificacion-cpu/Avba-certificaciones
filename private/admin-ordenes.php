<?php
require_once '../config/config.php';
require_once '../config/mayusculas.php';
require_once '../config/documentos-lib.php';
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== ROLE_ADMIN) {
    header('Location: ../public/login.html'); exit;
}
$nombre = $_SESSION['nombre'];
$uid    = $_SESSION['usuario_id'];

// ── Crear tabla si no existe (migración ligera) ──────────────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS ordenes_compra (
        id                INT AUTO_INCREMENT PRIMARY KEY,
        descripcion       TEXT,
        foto              VARCHAR(255) DEFAULT NULL,
        fecha_tentativa   DATE DEFAULT NULL,
        fecha_instalacion DATE DEFAULT NULL,
        creado_por        INT  DEFAULT NULL,
        created_at        DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$UPLOAD_DIR = __DIR__ . '/../uploads/ordenes/';
$flash = '';

function fechaValida($v) {
    $v = trim((string)$v);
    if ($v === '') return null;
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : null;
}

function guardarFoto($file, $UPLOAD_DIR) {
    if (empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
    if ($file['size'] > 6 * 1024 * 1024) return null; // 6 MB
    $info = @getimagesize($file['tmp_name']);
    if (!$info) return null; // no es imagen
    $extPorMime = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
    $ext = $extPorMime[$info['mime']] ?? null;
    if (!$ext) return null;
    if (!is_dir($UPLOAD_DIR)) @mkdir($UPLOAD_DIR, 0755, true);
    $nombre = 'oc_' . date('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 8) . '.' . $ext;
    if (move_uploaded_file($file['tmp_name'], $UPLOAD_DIR . $nombre)) return $nombre;
    return null;
}

// ── Acciones (POST) con patrón PRG ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'crear' || $accion === 'editar') {
        $descripcion = aMayusculas(trim($_POST['descripcion'] ?? ''));
        $f_tent = fechaValida($_POST['fecha_tentativa'] ?? '');
        $f_inst = fechaValida($_POST['fecha_instalacion'] ?? '');
        $foto   = guardarFoto($_FILES['foto'] ?? [], $UPLOAD_DIR);

        if ($descripcion === '') {
            $flash = 'La descripción es obligatoria.';
        } elseif ($accion === 'crear') {
            $st = $pdo->prepare("INSERT INTO ordenes_compra (descripcion, foto, fecha_tentativa, fecha_instalacion, creado_por) VALUES (?,?,?,?,?)");
            $st->execute([$descripcion, $foto, $f_tent, $f_inst, $uid]);
        } else {
            $id = intval($_POST['id'] ?? 0);
            if ($id) {
                if ($foto) {
                    // borrar foto anterior
                    $old = $pdo->prepare("SELECT foto FROM ordenes_compra WHERE id=?");
                    $old->execute([$id]);
                    $of = $old->fetchColumn();
                    if ($of && is_file($UPLOAD_DIR . $of)) @unlink($UPLOAD_DIR . $of);
                    $st = $pdo->prepare("UPDATE ordenes_compra SET descripcion=?, foto=?, fecha_tentativa=?, fecha_instalacion=? WHERE id=?");
                    $st->execute([$descripcion, $foto, $f_tent, $f_inst, $id]);
                } else {
                    $st = $pdo->prepare("UPDATE ordenes_compra SET descripcion=?, fecha_tentativa=?, fecha_instalacion=? WHERE id=?");
                    $st->execute([$descripcion, $f_tent, $f_inst, $id]);
                }
            }
        }
    } elseif ($accion === 'eliminar') {
        $id = intval($_POST['id'] ?? 0);
        if ($id) {
            $old = $pdo->prepare("SELECT foto FROM ordenes_compra WHERE id=?");
            $old->execute([$id]);
            $of = $old->fetchColumn();
            if ($of && is_file($UPLOAD_DIR . $of)) @unlink($UPLOAD_DIR . $of);
            $pdo->prepare("DELETE FROM ordenes_compra WHERE id=?")->execute([$id]);
            borrarDocumentosDe($pdo, 'orden_compra', $id);
        }
    }

    header('Location: admin-ordenes.php'); exit;
}

// ── Datos ────────────────────────────────────────────────────────────────────
$ordenes = $pdo->query("SELECT * FROM ordenes_compra ORDER BY COALESCE(fecha_instalacion, fecha_tentativa, created_at) DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);

$total       = count($ordenes);
$instaladas  = count(array_filter($ordenes, fn($o) => !empty($o['fecha_instalacion'])));
$pendientes  = $total - $instaladas;

// Gráfica: por mes, tentativas vs instaladas
$mesesSet = [];
foreach ($ordenes as $o) {
    if (!empty($o['fecha_tentativa']))   $mesesSet[substr($o['fecha_tentativa'],0,7)]   = true;
    if (!empty($o['fecha_instalacion'])) $mesesSet[substr($o['fecha_instalacion'],0,7)] = true;
}
ksort($mesesSet);
$meses = array_keys($mesesSet);
$serieTent = []; $serieInst = [];
foreach ($meses as $m) {
    $t = 0; $i = 0;
    foreach ($ordenes as $o) {
        if (!empty($o['fecha_tentativa'])   && substr($o['fecha_tentativa'],0,7)   === $m) $t++;
        if (!empty($o['fecha_instalacion']) && substr($o['fecha_instalacion'],0,7) === $m) $i++;
    }
    $serieTent[] = $t; $serieInst[] = $i;
}
$labels = array_map(fn($m) => substr($m,5,2).'/'.substr($m,0,4), $meses);

$fmt = function($iso){ if(!$iso) return '—'; $d=DateTime::createFromFormat('Y-m-d', substr($iso,0,10)); return $d? $d->format('d/m/Y'):'—'; };

$json_labels = json_encode($labels);
$json_tent   = json_encode($serieTent);
$json_inst   = json_encode($serieInst);
$json_estado = json_encode([$instaladas, $pendientes]);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Órdenes de compra</title>
<script src="../public/assets/js/chart.umd.min.js"></script>
<style>
    *{margin:0;padding:0;box-sizing:border-box}
    body{font-family:'Segoe UI',system-ui,sans-serif;background:#eef2fb;color:#1a2138}
    .navbar{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;padding:16px 26px;
            display:flex;justify-content:space-between;align-items:center;box-shadow:0 4px 16px rgba(102,126,234,.2)}
    .navbar a{color:#fff;text-decoration:none;font-size:13px;opacity:.9}.navbar a:hover{opacity:1;text-decoration:underline}
    .container{max-width:1200px;margin:0 auto;padding:26px 20px}
    h2{font-size:24px;color:#1e293b}
    .sub{color:#64748b;font-size:13px;margin-bottom:20px}
    .btn{padding:10px 18px;border:none;border-radius:8px;font-weight:700;cursor:pointer;font-size:14px}
    .btn-primary{background:#667eea;color:#fff}.btn-primary:hover{background:#5568d3}
    .btn-danger{background:#e74c3c;color:#fff}.btn-warning{background:#f39c12;color:#fff}
    .btn-sm{padding:6px 12px;font-size:12px}

    .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;margin:18px 0}
    .stat{background:#fff;border-radius:12px;padding:16px 18px;box-shadow:0 4px 14px rgba(30,41,59,.08)}
    .stat .n{font-size:26px;font-weight:800;color:#1e293b}.stat .l{font-size:12px;color:#64748b;font-weight:600}
    .stat.ok .n{color:#059669}.stat.warn .n{color:#f39c12}

    .charts{display:grid;grid-template-columns:2fr 1fr;gap:20px;margin-bottom:24px}
    @media(max-width:820px){.charts{grid-template-columns:1fr}}
    .card{background:#fff;border-radius:14px;padding:20px;box-shadow:0 4px 14px rgba(30,41,59,.08)}
    .card h3{font-size:15px;color:#334155;margin-bottom:16px;font-weight:700}
    .chart-box{position:relative;height:280px}

    .grid-oc{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:18px}
    .oc{background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 4px 14px rgba(30,41,59,.08);display:flex;flex-direction:column}
    .oc-foto{width:100%;height:170px;object-fit:cover;background:#f1f5f9}
    .oc-nofoto{width:100%;height:170px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:40px}
    .oc-body{padding:14px 16px;flex:1;display:flex;flex-direction:column;gap:8px}
    .oc-desc{font-size:14px;font-weight:600;color:#1e293b;line-height:1.4}
    .oc-fechas{font-size:12px;color:#64748b;display:flex;flex-direction:column;gap:2px}
    .badge{align-self:flex-start;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700}
    .b-inst{background:#dcfce7;color:#166534}.b-pend{background:#fef3c7;color:#92400e}
    .oc-actions{display:flex;gap:8px;padding:10px 16px;border-top:1px solid #eef1f7}

    .modal-ov{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100;align-items:center;justify-content:center;padding:16px}
    .modal-ov.open{display:flex}
    .modal{background:#fff;border-radius:14px;width:100%;max-width:480px;max-height:92vh;overflow-y:auto;padding:24px}
    .modal h3{font-size:18px;margin-bottom:16px;color:#1e293b}
    .fg{margin-bottom:14px}
    .fg label{display:block;font-size:12px;font-weight:700;color:#475569;margin-bottom:5px}
    .fg input,.fg textarea{width:100%;padding:10px;border:2px solid #e0e0ff;border-radius:8px;font-size:14px;font-family:inherit}
    .fg input:focus,.fg textarea:focus{outline:none;border-color:#667eea}
    .fg .row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    .modal-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:8px}
    .empty{text-align:center;padding:50px 20px;color:#94a3b8}.empty .ic{font-size:52px;margin-bottom:10px}
    #foto-actual{max-width:100%;max-height:120px;border-radius:8px;margin-top:8px;display:none}
</style>
</head>
<body>
<div class="navbar">
    <a href="admin-dashboard.php">← Panel Admin</a>
    <span style="font-size:13px">👤 <?= htmlspecialchars($nombre) ?></span>
</div>

<div class="container">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
        <div>
            <h2>🛒 Órdenes de compra</h2>
            <div class="sub">Controla los ítems por comprar/instalar: foto, descripción y fechas.</div>
        </div>
        <button class="btn btn-primary" onclick="abrirNueva()">＋ Nueva orden</button>
    </div>

    <div class="stats">
        <div class="stat"><div class="n"><?= $total ?></div><div class="l">Órdenes totales</div></div>
        <div class="stat ok"><div class="n"><?= $instaladas ?></div><div class="l">Instaladas</div></div>
        <div class="stat warn"><div class="n"><?= $pendientes ?></div><div class="l">Pendientes</div></div>
    </div>

    <?php if ($total > 0): ?>
    <div class="charts">
        <div class="card">
            <h3>📊 Órdenes por mes (tentativas vs instaladas)</h3>
            <div class="chart-box"><canvas id="chMes"></canvas></div>
        </div>
        <div class="card">
            <h3>Estado</h3>
            <div class="chart-box"><canvas id="chEstado"></canvas></div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($total === 0): ?>
        <div class="card"><div class="empty"><div class="ic">🛒</div><p>Aún no hay órdenes de compra.</p>
            <p style="font-size:13px;margin-top:6px">Pulsa «Nueva orden» para agregar la primera.</p></div></div>
    <?php else: ?>
        <div class="grid-oc">
        <?php foreach ($ordenes as $o):
            $instalada = !empty($o['fecha_instalacion']); ?>
            <div class="oc">
                <?php if (!empty($o['foto'])): ?>
                    <img class="oc-foto" src="../uploads/ordenes/<?= htmlspecialchars($o['foto']) ?>" alt="Ítem">
                <?php else: ?>
                    <div class="oc-nofoto">🖼️</div>
                <?php endif; ?>
                <div class="oc-body">
                    <span class="badge <?= $instalada ? 'b-inst' : 'b-pend' ?>"><?= $instalada ? 'Instalada' : 'Pendiente' ?></span>
                    <div class="oc-desc"><?= nl2br(htmlspecialchars($o['descripcion'])) ?></div>
                    <div class="oc-fechas">
                        <span>📅 Tentativa: <b><?= $fmt($o['fecha_tentativa']) ?></b></span>
                        <span>✅ Instalación: <b><?= $fmt($o['fecha_instalacion']) ?></b></span>
                    </div>
                </div>
                <div class="oc-actions">
                    <button class="btn btn-sm" style="background:#eef2fb;color:#475569"
                            onclick="abrirDocs('orden_compra', <?= (int)$o['id'] ?>, <?= htmlspecialchars(json_encode('Orden #' . $o['id']), ENT_QUOTES) ?>)"
                            title="Evidencias, facturas y documentación">📎 Documentos</button>
                    <button class="btn btn-warning btn-sm" onclick='editar(<?= json_encode([
                        "id"=>(int)$o["id"], "descripcion"=>$o["descripcion"],
                        "fecha_tentativa"=>$o["fecha_tentativa"], "fecha_instalacion"=>$o["fecha_instalacion"],
                        "foto"=>$o["foto"]
                    ], JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>✏️ Editar</button>
                    <form method="post" onsubmit="return confirm('¿Eliminar esta orden?')" style="margin:0">
                        <input type="hidden" name="accion" value="eliminar">
                        <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm">🗑️</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Modal crear/editar -->
<div class="modal-ov" id="modal">
    <div class="modal">
        <h3 id="modal-titulo">Nueva orden de compra</h3>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="accion" id="m-accion" value="crear">
            <input type="hidden" name="id" id="m-id" value="">
            <div class="fg">
                <label>Descripción del ítem *</label>
                <textarea name="descripcion" id="m-desc" rows="3" placeholder="Ej: Extintor PQS 9kg + gabinete" required></textarea>
            </div>
            <div class="fg">
                <div class="row">
                    <div>
                        <label>Fecha tentativa de instalación</label>
                        <input type="date" name="fecha_tentativa" id="m-tent">
                    </div>
                    <div>
                        <label>Fecha de instalación (real)</label>
                        <input type="date" name="fecha_instalacion" id="m-inst">
                    </div>
                </div>
            </div>
            <div class="fg">
                <label>Foto del ítem</label>
                <input type="file" name="foto" id="m-foto" accept="image/*">
                <img id="foto-actual" alt="Foto actual">
                <small style="color:#94a3b8;font-size:11px" id="foto-nota"></small>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-warning" onclick="cerrar()">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar</button>
            </div>
        </form>
    </div>
</div>

<script>
function abrirNueva() {
    document.getElementById('modal-titulo').textContent = 'Nueva orden de compra';
    document.getElementById('m-accion').value = 'crear';
    document.getElementById('m-id').value = '';
    document.getElementById('m-desc').value = '';
    document.getElementById('m-tent').value = '';
    document.getElementById('m-inst').value = '';
    document.getElementById('m-foto').value = '';
    document.getElementById('foto-actual').style.display = 'none';
    document.getElementById('foto-nota').textContent = '';
    document.getElementById('modal').classList.add('open');
}
function editar(o) {
    document.getElementById('modal-titulo').textContent = 'Editar orden';
    document.getElementById('m-accion').value = 'editar';
    document.getElementById('m-id').value = o.id;
    document.getElementById('m-desc').value = o.descripcion || '';
    document.getElementById('m-tent').value = o.fecha_tentativa || '';
    document.getElementById('m-inst').value = o.fecha_instalacion || '';
    document.getElementById('m-foto').value = '';
    const img = document.getElementById('foto-actual');
    if (o.foto) {
        img.src = '../uploads/ordenes/' + o.foto;
        img.style.display = 'block';
        document.getElementById('foto-nota').textContent = 'Sube una nueva foto solo si quieres reemplazarla.';
    } else {
        img.style.display = 'none';
        document.getElementById('foto-nota').textContent = '';
    }
    document.getElementById('modal').classList.add('open');
}
function cerrar() { document.getElementById('modal').classList.remove('open'); }
document.getElementById('modal').addEventListener('click', e => { if (e.target.id === 'modal') cerrar(); });

<?php if ($total > 0): ?>
(function(){
    if (typeof Chart === 'undefined') {
        document.querySelectorAll('.chart-box').forEach(c => c.innerHTML =
            '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:#94a3b8;font-size:13px;text-align:center;padding:16px">No se pudo cargar la librería de gráficas.<br>Revisa tu conexión y recarga.</div>');
        return;
    }
    new Chart(document.getElementById('chMes'), {
        type: 'bar',
        data: {
            labels: <?= $json_labels ?>,
            datasets: [
                { label:'Tentativas', data:<?= $json_tent ?>, backgroundColor:'#f39c12', borderRadius:6 },
                { label:'Instaladas', data:<?= $json_inst ?>, backgroundColor:'#27ae60', borderRadius:6 }
            ]
        },
        options: { responsive:true, maintainAspectRatio:false, scales:{y:{beginAtZero:true, ticks:{precision:0}}} }
    });
    new Chart(document.getElementById('chEstado'), {
        type: 'doughnut',
        data: { labels:['Instaladas','Pendientes'], datasets:[{ data:<?= $json_estado ?>,
            backgroundColor:['#27ae60','#f39c12'], borderColor:'#fff', borderWidth:2 }] },
        options: { responsive:true, maintainAspectRatio:false, plugins:{legend:{position:'bottom'}} }
    });
})();
<?php endif; ?>
</script>
<?php include __DIR__ . '/documentos-widget.php'; ?>
</body>
</html>
