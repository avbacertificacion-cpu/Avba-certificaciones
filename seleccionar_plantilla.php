<?php
/**
 * seleccionar_plantilla.php
 * Selector visual de plantillas de certificado para Calidad.
 *
 * Modos de uso:
 *   1. Standalone:  abrir directamente — guarda en $_SESSION y redirige a ?redirect=...
 *   2. Modal:       cargar en un iframe/dialog — al seleccionar emite postMessage al padre
 *
 * GET  ?tipo=equipos   → preselecciona un tipo
 * GET  ?redirect=url   → redirige con ?plantilla=tipo tras confirmar
 * POST ?accion=guardar → guarda en sesión y devuelve JSON {ok, tipo}
 */

session_start();

// ── Guardar selección vía POST (AJAX) ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar') {
    $plantillas = require __DIR__ . '/config/plantillas.php';
    $tipo = preg_replace('/[^a-z0-9_]/', '', $_POST['tipo'] ?? '');
    if (!isset($plantillas[$tipo])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Tipo inválido']);
        exit;
    }
    $_SESSION['plantilla_tipo'] = $tipo;
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'tipo' => $tipo, 'titulo' => $plantillas[$tipo]['titulo']]);
    exit;
}

$plantillas   = require __DIR__ . '/config/plantillas.php';
$tipoActual   = $_SESSION['plantilla_tipo'] ?? 'equipos';
$redirect     = filter_var($_GET['redirect'] ?? '', FILTER_SANITIZE_URL);
$modoModal    = isset($_GET['modal']);           // cuando se abre desde un iframe
$tipoGet      = preg_replace('/[^a-z0-9_]/', '', $_GET['tipo'] ?? $tipoActual);
if (isset($plantillas[$tipoGet])) $tipoActual = $tipoGet;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Seleccionar Plantilla — AVBA</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
  font-family: 'Segoe UI', Arial, sans-serif;
  background: <?= $modoModal ? 'transparent' : '#f0f4f8' ?>;
  min-height: 100vh;
  padding: <?= $modoModal ? '0' : '32px 16px 48px' ?>;
  color: #1e293b;
}

/* ── Header ── */
.page-header {
  max-width: 1100px;
  margin: 0 auto 28px;
  display: flex;
  align-items: center;
  gap: 14px;
}
.page-header .logo { height: 38px; }
.page-title { font-size: 18px; font-weight: 700; color: #0B2545; }
.page-sub   { font-size: 12px; color: #64748b; margin-top: 2px; }

/* ── Cards grid ── */
.grid {
  max-width: 1100px;
  margin: 0 auto;
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
  gap: 24px;
}

.card {
  background: #fff;
  border-radius: 14px;
  box-shadow: 0 2px 16px rgba(11,37,69,.09);
  border: 2.5px solid transparent;
  overflow: hidden;
  cursor: pointer;
  transition: border-color .18s, box-shadow .18s, transform .15s;
  display: flex;
  flex-direction: column;
}
.card:hover { box-shadow: 0 6px 28px rgba(11,37,69,.15); transform: translateY(-2px); }
.card.selected {
  border-color: #2060a8;
  box-shadow: 0 0 0 4px rgba(32,96,168,.15), 0 6px 28px rgba(11,37,69,.12);
}

/* ── Iframe preview ── */
.preview-area {
  position: relative;
  width: 100%;
  height: 340px;
  overflow: hidden;
  background: #e8edf3;
  cursor: pointer;
}
/* The cert is 215mm×279mm ≈ 812px×1054px at 96dpi.
   We want to fit 812px into the card width (~300px) → scale ≈ 0.37 */
.preview-area iframe {
  width: 812px;
  height: 1054px;
  border: none;
  transform: scale(0.37);
  transform-origin: 0 0;
  pointer-events: none;
  background: #fff;
}
.preview-overlay {
  position: absolute; inset: 0;
  display: flex; align-items: center; justify-content: center;
}
.preview-spinner {
  width: 36px; height: 36px;
  border: 3px solid #c7d8ea;
  border-top-color: #2060a8;
  border-radius: 50%;
  animation: spin .7s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }
.preview-area iframe.loaded + .preview-overlay { display: none; }

/* ── Card body ── */
.card-body { padding: 18px 20px 20px; flex: 1; display: flex; flex-direction: column; }
.card-badge {
  display: inline-block;
  font-size: 10px; font-weight: 700; letter-spacing: .6px;
  text-transform: uppercase; padding: 3px 8px; border-radius: 20px;
  margin-bottom: 10px;
}
.card-titulo { font-size: 15px; font-weight: 700; color: #0B2545; margin-bottom: 5px; }
.card-desc   { font-size: 12px; color: #64748b; line-height: 1.5; flex: 1; }
.card-campos {
  margin-top: 10px;
  font-size: 10.5px; color: #94a3b8; line-height: 1.6;
}
.card-campos strong { color: #475569; }

/* ── Select button ── */
.btn-select {
  margin-top: 14px;
  display: block; width: 100%;
  padding: 11px 16px;
  border-radius: 8px; border: none; cursor: pointer;
  font-size: 13px; font-weight: 700; letter-spacing: .2px;
  transition: opacity .15s, background .15s;
}
.btn-select:hover { opacity: .88; }

.card.selected .btn-select {
  background: #2060a8; color: #fff;
}
.card:not(.selected) .btn-select {
  background: #f1f5f9; color: #0B2545;
  border: 1.5px solid #cbd5e1;
}

/* ── Checkmark ── */
.check-badge {
  position: absolute; top: 10px; right: 10px;
  width: 28px; height: 28px; border-radius: 50%;
  background: #2060a8; color: #fff;
  display: none; align-items: center; justify-content: center;
  font-size: 14px; font-weight: 900;
  box-shadow: 0 2px 8px rgba(32,96,168,.4);
  z-index: 10;
}
.card.selected .check-badge { display: flex; }

/* ── Bottom bar ── */
.bottom-bar {
  position: <?= $modoModal ? 'sticky' : 'fixed' ?>;
  bottom: 0; left: 0; right: 0;
  background: #fff;
  border-top: 1px solid #e2e8f0;
  padding: 14px 24px;
  display: flex; align-items: center; justify-content: space-between;
  gap: 16px;
  z-index: 100;
  box-shadow: 0 -2px 16px rgba(11,37,69,.07);
}
.selected-label { font-size: 13px; color: #64748b; }
.selected-label strong { color: #0B2545; font-size: 14px; }
.btn-confirmar {
  padding: 11px 28px; border-radius: 8px; border: none; cursor: pointer;
  background: #0B2545; color: #fff;
  font-size: 13px; font-weight: 700; letter-spacing: .3px;
  transition: opacity .15s;
}
.btn-confirmar:hover { opacity: .87; }
.btn-confirmar:disabled { opacity: .4; cursor: default; }
</style>
</head>
<body>

<?php if (!$modoModal): ?>
<div class="page-header">
  <img src="assets/logos/avba.png" alt="AVBA" class="logo">
  <div>
    <div class="page-title">Seleccionar Plantilla de Certificado</div>
    <div class="page-sub">Elige la plantilla adecuada — la vista previa muestra el certificado real con datos de ejemplo</div>
  </div>
</div>
<?php endif; ?>

<div class="grid" id="grid">
<?php
$colores = ['equipos'=>'#0B2545','accesorios'=>'#1a5580','personal'=>'#2d6a4f'];
$labels  = [
  'equipos'    => 'Maquinaria',
  'accesorios' => 'Accesorios',
  'personal'   => 'Capacitación',
];
foreach ($plantillas as $tipo => $p):
  $sel   = ($tipo === $tipoActual) ? ' selected' : '';
  $color = $colores[$tipo] ?? '#0B2545';
  $label = $labels[$tipo] ?? $tipo;
?>
<div class="card<?= $sel ?>" data-tipo="<?= $tipo ?>" onclick="seleccionar('<?= $tipo ?>')">

  <div class="preview-area">
    <div class="check-badge">✓</div>
    <iframe
      src="api/preview_plantilla.php?tipo=<?= $tipo ?>"
      loading="lazy"
      onload="this.classList.add('loaded')">
    </iframe>
    <div class="preview-overlay"><div class="preview-spinner"></div></div>
  </div>

  <div class="card-body">
    <span class="card-badge" style="background:<?= $color ?>22;color:<?= $color ?>"><?= $label ?></span>
    <div class="card-titulo"><?= htmlspecialchars($p['titulo']) ?></div>
    <div class="card-desc"><?= htmlspecialchars($p['detalle']) ?></div>
    <div class="card-campos">
      <strong>Módulos:</strong> <?= implode(', ', $p['modulos']) ?>
    </div>
    <button class="btn-select" onclick="event.stopPropagation();seleccionar('<?= $tipo ?>')">
      <?= ($tipo === $tipoActual) ? '✓ Seleccionada' : 'Usar esta plantilla' ?>
    </button>
  </div>

</div>
<?php endforeach; ?>
</div><!-- /.grid -->

<div class="bottom-bar">
  <div class="selected-label">
    Plantilla seleccionada: <strong id="lbl-selected"><?= htmlspecialchars($plantillas[$tipoActual]['titulo']) ?></strong>
  </div>
  <button class="btn-confirmar" id="btn-confirmar" onclick="confirmar()">
    Confirmar selección
  </button>
</div>

<script>
const plantillas = <?= json_encode(array_map(fn($t, $p) => ['tipo'=>$t,'titulo'=>$p['titulo']], array_keys($plantillas), $plantillas), JSON_UNESCAPED_UNICODE) ?>;
let tipoSeleccionado = '<?= $tipoActual ?>';

function seleccionar(tipo) {
  tipoSeleccionado = tipo;

  // Update card states
  document.querySelectorAll('.card').forEach(c => {
    const esSel = c.dataset.tipo === tipo;
    c.classList.toggle('selected', esSel);
    const btn = c.querySelector('.btn-select');
    btn.textContent = esSel ? '✓ Seleccionada' : 'Usar esta plantilla';
  });

  // Update bottom bar
  const info = plantillas.find(p => p.tipo === tipo);
  document.getElementById('lbl-selected').textContent = info ? info.titulo : tipo;
}

function confirmar() {
  const btn = document.getElementById('btn-confirmar');
  btn.disabled = true;
  btn.textContent = 'Guardando…';

  fetch('seleccionar_plantilla.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'accion=guardar&tipo=' + encodeURIComponent(tipoSeleccionado)
  })
  .then(r => r.json())
  .then(data => {
    if (!data.ok) { alert('Error: ' + data.error); btn.disabled = false; btn.textContent = 'Confirmar selección'; return; }

    <?php if ($modoModal): ?>
    // Notify parent window (modal mode)
    window.parent.postMessage({ avba_plantilla: tipoSeleccionado, titulo: data.titulo }, '*');
    <?php elseif ($redirect): ?>
    window.location.href = <?= json_encode($redirect) ?> + (<?= json_encode($redirect) ?>.includes('?') ? '&' : '?') + 'plantilla=' + encodeURIComponent(tipoSeleccionado);
    <?php else: ?>
    btn.textContent = '✓ Guardado';
    btn.style.background = '#2d6a4f';
    setTimeout(() => { btn.disabled = false; btn.textContent = 'Confirmar selección'; btn.style.background = ''; }, 2000);
    <?php endif; ?>
  })
  .catch(() => { btn.disabled = false; btn.textContent = 'Confirmar selección'; alert('Error de red'); });
}
</script>

</body>
</html>
