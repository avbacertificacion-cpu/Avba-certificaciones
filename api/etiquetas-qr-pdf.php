<?php
require_once '../config/config.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== ROLE_ADMIN) {
    http_response_code(401); exit('No autorizado');
}

$primer   = preg_replace('/\D/', '', $_GET['primer']   ?? '1');
$cantidad = max(1, min(500, intval($_GET['cantidad']   ?? 10)));
$primer   = str_pad($primer ?: '1', 11, '0', STR_PAD_LEFT);
$primerNum = (int)$primer;

// Construir URL pública para QR codes
// Detectar si es local o producción y construir URL correcta
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
           (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
$protocol = $isHttps ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$baseURL = $protocol . '://' . $host . '/extintores/public/validar-extintor.html';

// Generar array de códigos QR
$codigos = [];
for ($i = 0; $i < $cantidad; $i++) {
    $codigos[] = str_pad($primerNum + $i, 11, '0', STR_PAD_LEFT);
}

// Imagen de plantilla en base64
$plantillaPath = '../public/assets/img/plantilla-etiqueta.png';
$plantillaB64  = '';
if (file_exists($plantillaPath)) {
    $plantillaB64 = 'data:image/png;base64,' . base64_encode(file_get_contents($plantillaPath));
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Etiquetas QR - AVBA</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            background: #e0e0e0;
            font-family: Arial, sans-serif;
        }

        /* ─── Botones pantalla ─── */
        .btn-bar {
            max-width: 800px;
            margin: 16px auto;
            display: flex;
            gap: 10px;
            padding: 0 16px;
        }
        .btn-bar button {
            padding: 10px 24px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 700;
            font-size: 14px;
        }
        .btn-print { background: #667eea; color: #fff; }
        .btn-back  { background: #e0e0e0; color: #333; }

        /* ─── Página de impresión ─── */
        .page {
            width: 21cm;
            min-height: 29.7cm;
            background: white;
            margin: 0 auto 20px;
            padding: 1cm;
            box-shadow: 0 2px 8px rgba(0,0,0,.2);
        }

        /* ─── Grid de etiquetas: 3 columnas × 3 filas ─── */
        .etiquetas-grid {
            display: grid;
            grid-template-columns: repeat(3, 5.5cm);
            gap: 0.3cm;
            justify-content: center;
        }

        /* ─── Etiqueta individual: 5.5cm × 8cm ─── */
        .etiqueta {
            width: 5.5cm;
            height: 8cm;
            position: relative;
            overflow: hidden;
            page-break-inside: avoid;
        }

        .etiqueta-bg {
            position: absolute;
            top: 0; left: 0;
            width: 100%; height: 100%;
            object-fit: fill;
        }

        /* ─── QR code overlay: dentro de la caja con borde verde ─── */
        .qr-area {
            position: absolute;
            top: 45%;
            left: 57%;
            width: 35%;
            height: 33%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .qr-area canvas,
        .qr-area img {
            width: 1.8cm !important;
            height: 1.8cm !important;
        }

        /* ─── Código numérico: espacio blanco bajo la franja verde ─── */
        .codigo-area {
            position: absolute;
            bottom: 11%;
            left: 0; right: 0;
            text-align: center;
        }

        .codigo-texto {
            font-family: 'Courier New', monospace;
            font-size: 11pt;
            font-weight: bold;
            color: #0b2545;
            letter-spacing: 1px;
        }

        /* ─── Impresión ─── */
        @page {
            size: A4 portrait;
            margin: 8mm;
        }

        @media print {
            body { background: white; }
            .btn-bar { display: none; }
            .page {
                margin: 0;
                padding: 0.5cm;
                box-shadow: none;
                page-break-after: always;
            }
            .page:last-child { page-break-after: auto; }
        }

        @media screen {
            .page { margin: 0 auto 20px; }
        }
    </style>
</head>
<body>

<div class="btn-bar no-print">
    <button class="btn-print" onclick="window.print()">🖨️ Imprimir / Guardar PDF</button>
    <button class="btn-back"  onclick="window.close()">✕ Cerrar</button>
    <span style="line-height:38px;font-size:13px;color:#555">
        <?= $cantidad ?> etiquetas · QR <?= htmlspecialchars($codigos[0]) ?> al <?= htmlspecialchars($codigos[count($codigos)-1]) ?>
    </span>
</div>

<?php
// Dividir en páginas de 9 etiquetas (3 columnas × 3 filas)
$porPagina = 9;
$paginas   = array_chunk($codigos, $porPagina);
foreach ($paginas as $pag):
?>
<div class="page">
    <div class="etiquetas-grid">
        <?php foreach ($pag as $idx => $codigo): ?>
        <div class="etiqueta" id="etiqueta-<?= $codigo ?>">
            <?php if ($plantillaB64): ?>
            <img class="etiqueta-bg" src="<?= $plantillaB64 ?>" alt="">
            <?php else: ?>
            <div class="etiqueta-bg" style="background:linear-gradient(180deg,#1a3a5c 0%,#1a3a5c 30%,#fff 30%,#fff 75%,#2d7a2d 75%)"></div>
            <?php endif; ?>

            <!-- QR Code -->
            <div class="qr-area">
                <div id="qr-<?= $codigo ?>"></div>
            </div>

            <!-- Código numérico -->
            <div class="codigo-area">
                <span class="codigo-texto"><?= $codigo ?></span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>

<script>
// Generar QR codes una vez que el DOM esté listo
const codigos = <?= json_encode($codigos) ?>;
const baseURL = <?= json_encode($baseURL) ?>;

codigos.forEach(function(codigo) {
    const container = document.getElementById('qr-' + codigo);
    if (!container) return;

    const validacionURL = baseURL + '?qr=' + encodeURIComponent(codigo);

    new QRCode(container, {
        text: validacionURL,
        width: 200,
        height: 200,
        colorDark: '#000000',
        colorLight: '#ffffff',
        correctLevel: QRCode.CorrectLevel.M
    });
});
</script>
</body>
</html>
