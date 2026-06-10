<?php
require_once '../config/config.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== ROLE_ADMIN) {
    http_response_code(403);
    echo json_encode(['error' => 'Sin permiso']);
    exit;
}

$d = json_decode(file_get_contents('php://input'), true);

if (empty($d['usuario_ids']) || !is_array($d['usuario_ids'])) {
    http_response_code(400);
    echo json_encode(['error' => 'usuario_ids requerido']);
    exit;
}

// Crear directorio temporal para PDFs
$temp_dir = sys_get_temp_dir() . '/avba_credenciales_' . uniqid();
if (!mkdir($temp_dir, 0755, true)) {
    http_response_code(500);
    echo json_encode(['error' => 'No se pudo crear directorio temporal']);
    exit;
}

try {
    // Generar PDF para cada usuario
    $stmt = $pdo->prepare("
        SELECT u.*, e.nombre as empresa_nombre
        FROM usuarios u
        LEFT JOIN empresas e ON e.id = u.empresa_id
        WHERE u.id = ?
    ");

    $archivos = [];
    foreach ($d['usuario_ids'] as $uid) {
        $stmt->execute([intval($uid)]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$usuario) continue;

        // Generar QR
        $qr_data = json_encode([
            'usuario_id' => $usuario['id'],
            'nombre' => $usuario['nombre'],
            'rol' => $usuario['rol']
        ]);
        $qr_hash = hash('sha256', $qr_data);
        $qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($qr_hash);

        // Logo
        $logo_path = '../public/assets/logos/avba_logo.png';
        $logo_base64 = '';
        if (file_exists($logo_path)) {
            $logo_base64 = base64_encode(file_get_contents($logo_path));
        }

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

        // Logo
        $logo_html = '';
        if ($logo_base64) {
            $logo_html = '<img src="data:image/png;base64,' . $logo_base64 . '" alt="Logo AVBA">';
        } else {
            $logo_html = '<div style="width:100%;text-align:center;font-size:12pt;color:#27ae60">AVBA</div>';
        }

        // Teléfono
        $phone_html = '';
        if (!empty($usuario['telefono'])) {
            $phone_html = '<div class="info-item"><span>📱</span><span>' . htmlspecialchars($usuario['telefono']) . '</span></div>';
        }

        // Empresa
        $empresa_html = '';
        if (!empty($usuario['empresa_nombre'])) {
            $empresa_html = '<div class="info-item"><span>🏢</span><span>' . htmlspecialchars($usuario['empresa_nombre']) . '</span></div>';
        }

        // HTML
        $html = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Segoe UI', sans-serif;
        }
        .page {
            width: 85.6mm;
            height: 53.98mm;
            position: relative;
            overflow: hidden;
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
        }
        .header-text div:first-child {
            font-size: 8pt;
            color: #27ae60;
        }
        .header-text div:last-child {
            font-size: 5pt;
            color: rgba(255,255,255,.7);
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
    </style>
</head>
<body>
<div class="page">
    <div class="credencial">
        <div class="header">
            <div class="logo">
                {$logo_html}
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
                    {$phone_html}
                    {$empresa_html}
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

        // Generar PDF
        require_once '../vendor/autoload.php';
        use Dompdf\Dompdf;
        use Dompdf\Options;

        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isPhpEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper([0, 0, 242, 152]);
        $dompdf->render();

        // Guardar PDF
        $archivo_nombre = strtolower(str_replace(' ', '_', $usuario['nombre'])) . '.pdf';
        $archivo_ruta = $temp_dir . '/' . $archivo_nombre;
        file_put_contents($archivo_ruta, $dompdf->output());
        $archivos[] = $archivo_ruta;
    }

    if (empty($archivos)) {
        http_response_code(404);
        echo json_encode(['error' => 'No se encontraron usuarios']);
        exit;
    }

    // Crear ZIP
    $zip_path = sys_get_temp_dir() . '/credenciales_' . uniqid() . '.zip';
    $zip = new ZipArchive();

    if (!$zip->open($zip_path, ZipArchive::CREATE)) {
        http_response_code(500);
        echo json_encode(['error' => 'Error creando ZIP']);
        exit;
    }

    foreach ($archivos as $archivo) {
        $zip->addFile($archivo, basename($archivo));
    }

    $zip->close();

    // Enviar ZIP
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="credenciales_' . date('Ymd_His') . '.zip"');
    header('Content-Length: ' . filesize($zip_path));

    readfile($zip_path);

    // Limpiar archivos temporales
    array_map('unlink', $archivos);
    unlink($zip_path);
    rmdir($temp_dir);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error: ' . $e->getMessage()]);

    // Limpiar en caso de error
    @array_map('unlink', glob($temp_dir . '/*'));
    @rmdir($temp_dir);
}
