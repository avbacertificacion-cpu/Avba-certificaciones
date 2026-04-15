<?php
/**
 * AVBA — Microservicio de conversión Word → PDF protegido
 *
 * Despliega este archivo en un VPS con LibreOffice y qpdf instalados.
 * Configura la API_KEY en este mismo archivo (línea 18).
 *
 * Uso:
 *   POST /convert.php
 *   Content-Type: multipart/form-data
 *   Campos: file (docx), api_key, owner_pass (opcional)
 *
 * Respuesta exitosa: binario application/pdf
 * Respuesta de error: application/json  {"error": "..."}
 *
 * Instalación en VPS Ubuntu/Debian:
 *   apt-get install -y libreoffice qpdf
 */

// ── Configura tu clave secreta ────────────────────────────────
define('API_KEY', 'CAMBIA_ESTA_CLAVE_POR_UNA_SEGURA');

// ── Binarios (ajusta si están en otra ruta) ───────────────────
define('SOFFICE_BIN', buscarBin(['soffice', 'libreoffice'], [
    '/usr/bin', '/usr/local/bin', '/opt/libreoffice/program',
    '/opt/libreoffice7.6/program', '/opt/libreoffice7.5/program',
]));
define('QPDF_BIN', buscarBin(['qpdf'], ['/usr/bin', '/usr/local/bin']));

// ─────────────────────────────────────────────────────────────
header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204); exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Método no permitido.', 405);
}

// ── Autenticación ─────────────────────────────────────────────
$apiKey = $_POST['api_key']
       ?? $_SERVER['HTTP_X_API_KEY']
       ?? '';

if (!hash_equals(API_KEY, $apiKey)) {
    jsonError('No autorizado.', 401);
}

// ── Archivo DOCX ─────────────────────────────────────────────
if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    jsonError('Archivo no recibido o con error al subir.', 400);
}

$ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
if ($ext !== 'docx') {
    jsonError('Solo se aceptan archivos .docx.', 400);
}

if (!SOFFICE_BIN) {
    jsonError('LibreOffice no está instalado en el servidor de conversión.', 500);
}

// ── Directorios temporales ────────────────────────────────────
$uid    = uniqid('avba_', true);
$tmpDir = sys_get_temp_dir() . '/' . $uid . '/';
mkdir($tmpDir, 0755, true);

$tmpDocx  = $tmpDir . 'documento.docx';
$tmpPdfRaw= $tmpDir . 'documento.pdf';
$tmpPdfOk = $tmpDir . 'protegido.pdf';

move_uploaded_file($_FILES['file']['tmp_name'], $tmpDocx);

// ── 1. Convertir DOCX → PDF con LibreOffice ──────────────────
$cmd = 'HOME=/tmp ' . escapeshellarg(SOFFICE_BIN)
     . ' --headless --convert-to pdf --outdir '
     . escapeshellarg($tmpDir) . ' '
     . escapeshellarg($tmpDocx) . ' 2>&1';

exec($cmd, $outLO, $codeLO);

if ($codeLO !== 0 || !file_exists($tmpPdfRaw)) {
    cleanup($tmpDir);
    jsonError('Error de conversión con LibreOffice: ' . implode(' | ', $outLO), 500);
}

// ── 2. Proteger PDF con qpdf ──────────────────────────────────
$ownerPass = trim($_POST['owner_pass'] ?? '') ?: ('AVBA' . strtoupper(substr(md5($uid), 0, 10)));

if (QPDF_BIN) {
    $cmd = escapeshellarg(QPDF_BIN)
         . ' --encrypt "" ' . escapeshellarg($ownerPass) . ' 128 '
         . '--print=full --modify=none --extract=n '
         . '-- ' . escapeshellarg($tmpPdfRaw) . ' ' . escapeshellarg($tmpPdfOk)
         . ' 2>&1';
    exec($cmd, $outQP, $codeQP);
    @unlink($tmpPdfRaw);

    if ($codeQP !== 0 || !file_exists($tmpPdfOk)) {
        cleanup($tmpDir);
        jsonError('Error al proteger PDF con qpdf: ' . implode(' | ', $outQP), 500);
    }
    $pdfFinal = $tmpPdfOk;
} else {
    // qpdf no disponible: devolver PDF sin protección
    $pdfFinal = $tmpPdfRaw;
}

// ── 3. Enviar PDF al cliente ──────────────────────────────────
header('Content-Type: application/pdf');
header('Content-Length: ' . filesize($pdfFinal));
header('Content-Disposition: inline; filename="documento.pdf"');
readfile($pdfFinal);

cleanup($tmpDir);
exit;

// ─────────────────────────────────────────────────────────────
function jsonError(string $msg, int $code = 500): never {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['error' => $msg]);
    exit;
}

function cleanup(string $dir): void {
    foreach (glob($dir . '*') ?: [] as $f) @unlink($f);
    @rmdir($dir);
}

function buscarBin(array $nombres, array $dirs): ?string {
    foreach ($dirs as $dir) {
        foreach ($nombres as $nombre) {
            $ruta = rtrim($dir, '/') . '/' . $nombre;
            if (is_executable($ruta)) return $ruta;
        }
    }
    return null;
}
