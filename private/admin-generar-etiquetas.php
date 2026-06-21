<?php
require_once '../config/config.php';
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== ROLE_ADMIN) {
    header('Location: ../public/login.html'); exit;
}

// Obtener próximo QR disponible
$proximo_qr = 1;
try {
    $stmt = $pdo->prepare("SELECT proximo_qr FROM qr_secuencias WHERE id = 1");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) $proximo_qr = (int)$row['proximo_qr'];
} catch (Exception $e) {}

$proximo_qr_fmt = str_pad($proximo_qr, 11, '0', STR_PAD_LEFT);

// Contar QR ya asignados
$total_asignados = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM extintores WHERE codigo_qr IS NOT NULL");
    $stmt->execute();
    $total_asignados = (int)$stmt->fetchColumn();
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generar Etiquetas QR</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Segoe UI',sans-serif;background:#f4f6fb}
        .navbar{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;padding:16px 32px;display:flex;justify-content:space-between;align-items:center}
        .navbar a{color:#fff;text-decoration:none;font-size:13px;margin-left:16px}
        .container{max-width:800px;margin:32px auto;padding:0 20px}
        .card{background:#fff;border-radius:12px;padding:32px;box-shadow:0 2px 12px rgba(0,0,0,.08);margin-bottom:24px}
        .card h2{color:#333;margin-bottom:20px;font-size:20px}
        .form-row{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px}
        .form-group label{display:block;font-size:12px;font-weight:700;color:#555;margin-bottom:6px;text-transform:uppercase}
        .form-group input{width:100%;padding:12px;border:2px solid #ddd;border-radius:8px;font-size:15px}
        .form-group input:focus{outline:none;border-color:#667eea}
        .form-group small{color:#888;font-size:11px;margin-top:4px;display:block}
        .info-box{background:#f0f3ff;border:2px solid #667eea;border-radius:8px;padding:16px;margin-bottom:24px}
        .info-box p{font-size:13px;color:#444;line-height:1.6}
        .info-box strong{color:#667eea}
        .btn{padding:12px 28px;border:none;border-radius:8px;cursor:pointer;font-weight:700;font-size:15px;transition:.2s}
        .btn-primary{background:#667eea;color:#fff;width:100%}
        .btn-primary:hover{background:#5568d3}
        .preview-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:20px}
        .preview-label{border:1px dashed #ccc;border-radius:4px;padding:8px;font-size:10px;text-align:center;color:#888}
        .stat{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f0f0f0;font-size:13px}
        .stat:last-child{border-bottom:none}
        .stat span:last-child{font-weight:700;color:#667eea}
    </style>
</head>
<body>
<div class="navbar">
    <h1>🏷️ Generar Etiquetas QR</h1>
    <div>
        <a href="admin-dashboard.php">← Panel Admin</a>
    </div>
</div>

<div class="container">

    <div class="info-box">
        <p>
            <strong>📊 Estado del Sistema de QR:</strong><br>
            Los códigos QR son de <strong>11 dígitos consecutivos</strong>.
            Una vez generadas las etiquetas, los códigos quedan reservados en el sistema
            y deberán ser asignados a extintores.
        </p>
    </div>

    <div class="card">
        <h2>📈 Estado Actual</h2>
        <div class="stat"><span>Próximo QR disponible</span><span><?= $proximo_qr_fmt ?></span></div>
        <div class="stat"><span>QR ya asignados a extintores</span><span><?= number_format($total_asignados) ?></span></div>
        <div class="stat"><span>QR disponibles en secuencia</span><span><?= number_format(99999999999 - $proximo_qr + 1) ?></span></div>
    </div>

    <div class="card">
        <h2>⚙️ Configurar Generación</h2>

        <div class="form-row">
            <div class="form-group">
                <label>Primer QR a generar</label>
                <input type="text" id="primer_qr" value="<?= $proximo_qr_fmt ?>"
                       maxlength="11" pattern="[0-9]{11}" placeholder="00000000001">
                <small>Se completará con ceros a la izquierda</small>
            </div>
            <div class="form-group">
                <label>Cantidad de etiquetas</label>
                <input type="number" id="cantidad" value="160" min="1" max="5000" placeholder="160">
                <small>Máximo 5000. Se distribuirán automáticamente en el PDF.</small>
            </div>
        </div>

        <div id="preview-info" style="margin-bottom:20px;padding:12px;background:#f9f9f9;border-radius:8px;font-size:13px;color:#555">
            Se generarán etiquetas del <strong id="desde">00000000001</strong> al <strong id="hasta">00000000010</strong>
            en <strong id="hojas">2</strong> hojas.
        </div>

        <button class="btn btn-primary" onclick="generarPDF()">
            🖨️ Generar Etiquetas PDF
        </button>
    </div>

</div>

<script>
const primerQRInput = document.getElementById('primer_qr');
const cantidadInput = document.getElementById('cantidad');

function actualizarPreview() {
    let primer = primerQRInput.value.trim().padStart(11, '0').slice(0, 11);
    const cant = parseInt(cantidadInput.value) || 1;
    const primerNum = parseInt(primer) || 1;
    const ultimoNum = primerNum + cant - 1;

    document.getElementById('desde').textContent = String(primerNum).padStart(11, '0');
    document.getElementById('hasta').textContent = String(ultimoNum).padStart(11, '0');
    document.getElementById('hojas').textContent = Math.ceil(cant / 6);
}

primerQRInput.addEventListener('input', actualizarPreview);
cantidadInput.addEventListener('input', actualizarPreview);
actualizarPreview();

function generarPDF() {
    let primer = primerQRInput.value.trim();
    const cant = parseInt(cantidadInput.value) || 1;

    if (!/^\d{1,11}$/.test(primer.replace(/^0+/, '') || '0')) {
        alert('El primer QR debe ser numérico.');
        return;
    }

    primer = primer.padStart(11, '0');

    if (cant < 1 || cant > 500) {
        alert('La cantidad debe estar entre 1 y 500.');
        return;
    }

    // Abrir página de impresión
    window.open(`../api/etiquetas-qr-pdf.php?primer=${primer}&cantidad=${cant}`, '_blank');
}
</script>
</body>
</html>
