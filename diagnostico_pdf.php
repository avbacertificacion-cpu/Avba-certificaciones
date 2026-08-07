<?php
/**
 * AVBA — Diagnóstico de generación de PDF
 *
 * Comprueba en el propio servidor por qué un PDF generado no se puede abrir:
 * qué librerías hay, si las carpetas existen y se pueden escribir, si el
 * archivo se crea realmente y si el servidor lo entrega por HTTP.
 *
 * USO: https://gestion.avba.com.mx/diagnostico_pdf.php?secret=avba_diag_2026
 * BORRAR este archivo del servidor después de revisarlo.
 */

header('Content-Type: text/plain; charset=utf-8');
if (($_GET['secret'] ?? '') !== 'avba_diag_2026') {
    http_response_code(403);
    exit("Acceso denegado. Agrega ?secret=avba_diag_2026 a la URL.\n");
}
while (ob_get_level() > 0) { ob_end_flush(); }
ob_implicit_flush(true);

$cfg = __DIR__ . '/config/config.php';
if (!is_file($cfg)) exit("No se encontró config/config.php\n");
require_once $cfg;

function ok($t)   { echo "  ✔ $t\n"; }
function mal($t)  { echo "  ✘ $t\n"; }
function info($t) { echo "    $t\n"; }

echo "═══ DIAGNÓSTICO DE PDF — AVBA ═══\n\n";
echo "PHP: " . PHP_VERSION . "\n";
echo "Fecha: " . date('d/m/Y H:i:s') . "\n\n";

// ── 1. Librerías ───────────────────────────────────────────
echo "1) LIBRERÍAS\n";
$autoload = __DIR__ . '/vendor/autoload.php';
if (is_file($autoload)) { require_once $autoload; ok("vendor/autoload.php cargado"); }
else                    { mal("NO existe vendor/autoload.php — no hay librerías instaladas"); }

$fpdi  = class_exists('\setasign\Fpdi\Fpdi');
$fpdf  = class_exists('\setasign\Fpdf\Fpdf') || class_exists('FPDF');
$mpdf  = class_exists('\Mpdf\Mpdf');
$fpdi ? ok("FPDI disponible (combina PDFs)") : mal("FPDI NO disponible — sin esto no se puede unir PDFs");
$fpdf ? ok("FPDF disponible") : mal("FPDF NO disponible");
$mpdf ? ok("mPDF disponible (genera dictámenes y certificados)") : mal("mPDF NO disponible");

// ── 2. Carpetas ────────────────────────────────────────────
echo "\n2) CARPETAS DE SALIDA\n";
info("UPLOAD_DIR = " . UPLOAD_DIR);
info("UPLOAD_URL = " . UPLOAD_URL);
info("SITE_URL   = " . SITE_URL);
foreach (['impresiones', 'reportes'] as $sub) {
    $dir = rtrim(UPLOAD_DIR, '/') . '/' . $sub . '/';
    if (!is_dir($dir)) {
        if (@mkdir($dir, 0755, true)) ok("$sub/ no existía y se creó");
        else { mal("$sub/ NO existe y NO se pudo crear (permisos)"); continue; }
    } else ok("$sub/ existe");
    is_writable($dir) ? ok("$sub/ se puede escribir") : mal("$sub/ NO se puede escribir (permisos)");
}

// ── 3. Escritura real de un PDF ────────────────────────────
echo "\n3) PRUEBA DE ESCRITURA\n";
$urlPrueba = '';
if ($fpdi) {
    try {
        $dir = rtrim(UPLOAD_DIR, '/') . '/impresiones/';
        $nom = 'PRUEBA_DIAG_' . date('Ymd_His') . '.pdf';
        $pdf = new \setasign\Fpdi\Fpdi();
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', '', 14);
        $pdf->Cell(0, 10, 'Prueba de diagnostico AVBA', 0, 1, 'C');
        $pdf->Output('F', $dir . $nom);            // FPDI: (destino, nombre)
        if (is_file($dir . $nom) && filesize($dir . $nom) > 100) {
            ok("PDF de prueba escrito (" . filesize($dir . $nom) . " bytes)");
            $urlPrueba = rtrim(SITE_URL, '/') . '/' . UPLOAD_URL . 'impresiones/' . $nom;
            info("URL: $urlPrueba");
        } else {
            mal("Output() no dejó el archivo en disco");
            // Probar el orden inverso por si la version instalada lo espera asi
            $nom2 = 'PRUEBA_DIAG2_' . date('Ymd_His') . '.pdf';
            $pdf2 = new \setasign\Fpdi\Fpdi();
            $pdf2->AddPage(); $pdf2->SetFont('Helvetica','',14);
            $pdf2->Cell(0,10,'Prueba 2',0,1,'C');
            $pdf2->Output($dir . $nom2, 'F');
            is_file($dir . $nom2) ? ok("¡Funciona con el orden INVERSO Output(ruta,'F')!")
                                  : mal("Tampoco con el orden inverso");
        }
    } catch (\Throwable $e) {
        mal("Excepción al generar: " . get_class($e) . ' — ' . $e->getMessage());
    }
} else {
    info("Se omite: FPDI no está disponible");
}

// ── 4. ¿El servidor entrega el archivo? ────────────────────
echo "\n4) ENTREGA POR HTTP\n";
$ht = __DIR__ . '/uploads/.htaccess';
if (is_file($ht)) {
    ok("uploads/.htaccess existe");
    foreach (explode("\n", file_get_contents($ht)) as $l) {
        if (stripos($l, 'RewriteRule') !== false) info(trim($l));
    }
} else info("uploads/.htaccess no existe (se sirve sin restricciones)");

if ($urlPrueba && function_exists('curl_init')) {
    $ch = curl_init($urlPrueba);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>15, CURLOPT_SSL_VERIFYPEER=>false]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 200 && strncmp((string)$body, '%PDF', 4) === 0) {
        ok("El servidor SÍ entrega el PDF (HTTP 200)");
        echo "\n   ✅ Abre esta URL en tu navegador; debe descargarse el PDF:\n   $urlPrueba\n";
    } else {
        mal("El servidor NO entrega el archivo (HTTP $code)");
        info("El archivo existe en disco pero Apache lo bloquea o no lo encuentra.");
        info("Primeros bytes: " . substr((string)$body, 0, 60));
    }
}

// ── 5. Archivos del módulo ─────────────────────────────────
echo "\n5) ARCHIVOS DESPLEGADOS\n";
foreach (['api/ClienteImpresion.php','api/Arneses.php','api/index.php'] as $f) {
    is_file(__DIR__ . '/' . $f)
        ? ok("$f (" . date('d/m/Y H:i', filemtime(__DIR__ . '/' . $f)) . ")")
        : mal("$f NO existe — el despliegue no llegó");
}
$idx = @file_get_contents(__DIR__ . '/api/index.php');
strpos((string)$idx, 'IMPRIMIR_EXPEDIENTE') !== false
    ? ok("api/index.php contiene la ruta IMPRIMIR_EXPEDIENTE")
    : mal("api/index.php NO tiene la ruta — está desactualizado");

echo "\n═══ FIN ═══\n";
echo "Copia todo este texto y compártelo para interpretarlo.\n";
echo "Después BORRA diagnostico_pdf.php del servidor.\n";
