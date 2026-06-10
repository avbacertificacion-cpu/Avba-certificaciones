<?php
require_once '../config/config.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== ROLE_ADMIN) {
    http_response_code(403);
    die('Sin permiso');
}

$usuario_id = intval($_GET['usuario_id'] ?? 0);
if (!$usuario_id) {
    http_response_code(400);
    die('Usuario requerido');
}

// Obtener datos del usuario
$stmt = $pdo->prepare("
    SELECT u.*, e.nombre as empresa_nombre
    FROM usuarios u
    LEFT JOIN empresas e ON e.id = u.empresa_id
    WHERE u.id = ?
");
$stmt->execute([$usuario_id]);
$usuario = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$usuario) {
    http_response_code(404);
    die('Usuario no encontrado');
}

// Generar código QR con datos del usuario
$qr_data = json_encode([
    'usuario_id' => $usuario['id'],
    'nombre' => $usuario['nombre'],
    'rol' => $usuario['rol']
]);
$qr_hash = hash('sha256', $qr_data);

// Usar API externa para generar QR
$qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($qr_hash);

// Cargar logo
$logo_path = '../public/assets/logos/avba_logo.png';
$logo_base64 = '';
if (file_exists($logo_path)) {
    $logo_base64 = base64_encode(file_get_contents($logo_path));
}

// Determinar icono del rol
$rol_icono = match($usuario['rol']) {
    'administrador' => '👤',
    'inspector' => '🔍',
    'cliente' => '🏢',
    default => '👤'
};

$rol_color = match($usuario['rol']) {
    'administrador' => '#667eea',
    'inspector' => '#27ae60',
    'cliente' => '#3498db',
    default => '#667eea'
};

// HTML para la credencial (tamaño tarjeta: 85.6mm x 53.98mm en puntos = 242x152 pt)
$html = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Credencial - {$usuario['nombre']}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', sans-serif;
            background: #f0f0f0;
            padding: 20px;
        }

        .page {
            width: 85.6mm;
            height: 53.98mm;
            margin: 10mm auto;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0,0,0,.15);
        }

        .credencial {
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, #0a1929 0%, #1a3a52 50%, #0f2744 100%);
            color: #fff;
            display: flex;
            flex-direction: column;
            position: relative;
            overflow: hidden;
        }

        .credencial::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(102,126,234,.1) 0%, transparent 70%);
            animation: float 6s ease-in-out infinite;
        }

        .credencial::after {
            content: '';
            position: absolute;
            bottom: 0;
            right: 0;
            width: 60mm;
            height: 60mm;
            background: linear-gradient(135deg, rgba(39,174,96,.2) 0%, transparent 100%);
            border-radius: 50%;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 6mm 8mm 4mm 8mm;
            position: relative;
            z-index: 2;
            border-bottom: 1px solid rgba(255,255,255,.1);
        }

        .logo {
            width: 20mm;
            height: auto;
        }

        .logo img {
            width: 100%;
            height: auto;
        }

        .header-text {
            text-align: center;
            flex: 1;
            font-size: 7pt;
            font-weight: 700;
            letter-spacing: 0.5px;
        }

        .header-text div:first-child {
            font-size: 8pt;
            color: #27ae60;
        }

        .header-text div:last-child {
            font-size: 5pt;
            color: rgba(255,255,255,.7);
            margin-top: 1px;
        }

        .content {
            display: flex;
            flex: 1;
            padding: 6mm 8mm;
            gap: 8mm;
            position: relative;
            z-index: 2;
        }

        .avatar {
            width: 20mm;
            height: 20mm;
            background: rgba(255,255,255,.1);
            border: 2px solid #27ae60;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10mm;
            flex-shrink: 0;
        }

        .user-info {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-width: 0;
        }

        .user-info strong {
            font-size: 7.5pt;
            display: block;
            margin-bottom: 2px;
            word-break: break-word;
        }

        .info-item {
            display: flex;
            font-size: 5.5pt;
            margin-bottom: 1.5px;
            align-items: center;
            gap: 3px;
        }

        .info-item span:first-child {
            color: #27ae60;
            font-weight: 700;
            min-width: 18px;
        }

        .info-item span:last-child {
            color: rgba(255,255,255,.85);
            word-break: break-word;
            flex: 1;
        }

        .role-badge {
            display: inline-block;
            padding: 2px 6px;
            background: {$rol_color};
            border-radius: 3px;
            font-size: 5pt;
            font-weight: 700;
            text-transform: uppercase;
            margin-top: 2px;
        }

        .qr {
            width: 18mm;
            height: 18mm;
            background: #fff;
            border-radius: 3px;
            padding: 1mm;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .qr img {
            width: 100%;
            height: 100%;
        }

        .footer {
            padding: 3mm 8mm;
            font-size: 5pt;
            text-align: center;
            color: rgba(255,255,255,.6);
            position: relative;
            z-index: 2;
            border-top: 1px solid rgba(255,255,255,.1);
        }

        .ema-seal {
            position: absolute;
            bottom: 3mm;
            right: 3mm;
            font-size: 6pt;
            color: #27ae60;
            font-weight: 700;
            z-index: 2;
        }

        @media print {
            body { padding: 0; margin: 0; background: none; }
            .page { margin: 0; box-shadow: none; }
        }
    </style>
</head>
<body>

<div class="page">
    <div class="credencial">
        <div class="header">
            <div class="logo">
                HTML_LOGO_PLACEHOLDER
            </div>
            <div class="header-text">
                <div>AVBA</div>
                <div>INSPECTIONS</div>
            </div>
        </div>

        <div class="content">
            <div class="avatar">{$rol_icono}</div>

            <div class="user-info">
                <div>
                    <strong>{$usuario['nombre']}</strong>
                    <div class="role-badge">{$usuario['rol']}</div>
                </div>

                <div>
                    <div class="info-item">
                        <span>📧</span>
                        <span>{$usuario['email']}</span>
                    </div>
                    HTML_PHONE_PLACEHOLDER
                    HTML_EMPRESA_PLACEHOLDER
                </div>
            </div>

            <div class="qr">
                <img src="{$qr_url}" alt="QR">
            </div>
        </div>

        <div class="footer">
            Credencial de Autorización
        </div>

        <div class="ema-seal">✓ EMA</div>
    </div>
</div>

</body>
</html>
HTML;

// Agregar teléfono si existe
$phone_html = '';
if (!empty($usuario['telefono'])) {
    $phone_html = '<div class="info-item"><span>📱</span><span>' . htmlspecialchars($usuario['telefono']) . '</span></div>';
}
$html = str_replace('HTML_PHONE_PLACEHOLDER', $phone_html, $html);

// Agregar empresa si existe
$empresa_html = '';
if (!empty($usuario['empresa_nombre'])) {
    $empresa_html = '<div class="info-item"><span>🏢</span><span>' . htmlspecialchars($usuario['empresa_nombre']) . '</span></div>';
}
$html = str_replace('HTML_EMPRESA_PLACEHOLDER', $empresa_html, $html);

// Agregar logo
$logo_html = '';
if ($logo_base64) {
    $logo_html = '<img src="data:image/png;base64,' . $logo_base64 . '" alt="Logo AVBA">';
} else {
    $logo_html = '<div style="width:100%;text-align:center;font-size:12pt;color:#27ae60">AVBA</div>';
}
$html = str_replace('HTML_LOGO_PLACEHOLDER', $logo_html, $html);

// Exportar a PDF usando HTML a texto
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="credencial_' . strtolower(str_replace(' ', '_', $usuario['nombre'])) . '.pdf"');

// Usar librería para convertir HTML a PDF
// Si no tienes TCPDF o similar, usa una alternativa simple
require_once '../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isPhpEnabled', true);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper([0, 0, 242, 152]); // Tarjeta 85.6mm x 53.98mm en puntos
$dompdf->render();

echo $dompdf->output();
