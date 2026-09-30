<?php
/**
 * api/preview_plantilla.php
 * Sirve el HTML de una plantilla con datos de muestra para previsualización en iframe.
 * No genera PDF — solo renderiza el HTML en el navegador.
 */

$tipo = preg_replace('/[^a-z0-9_]/', '', strtolower($_GET['tipo'] ?? 'equipos'));

$plantillas = require __DIR__ . '/../config/plantillas.php';

if (!isset($plantillas[$tipo])) {
    http_response_code(404);
    die('Plantilla no encontrada');
}

$info = $plantillas[$tipo];
$templatePath = __DIR__ . '/../' . $info['archivo'];

if (!file_exists($templatePath)) {
    http_response_code(500);
    die('Archivo de plantilla no encontrado: ' . htmlspecialchars($info['archivo']));
}

$qr_blank = 'data:image/gif;base64,R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==';
$hoy      = date('d/m/Y');
$vigencia = date('d/m/Y', strtotime('+1 year'));

// Completar datos de muestra que dependen de la fecha actual
$muestra = $info['muestra'];
foreach ($muestra as $k => &$v) {
    if ($v === '') {
        if (str_contains($k, 'qr'))      $v = $qr_blank;
        elseif (str_contains($k, 'vigencia')) $v = $vigencia;
        else                              $v = $hoy;
    }
}
unset($v);

$html = file_get_contents($templatePath);
$html = str_replace(array_keys($muestra), array_values($muestra), $html);

// La plantilla vive en la raíz del proyecto y usa rutas relativas —las
// tipografías locales, el sello—, pero este archivo se sirve desde /api/, así
// que sin <base> el navegador las buscaba en /api/assets/ y no las encontraba.
// Va justo al abrir <head>: si se pusiera al final, el <style> con el @import de
// las tipografías ya se habría resuelto contra la ruta equivocada.
$html = preg_replace('/<head(\s[^>]*)?>/i', '$0' . "\n" . '<base href="../">', $html, 1);

// Reset de .page para que no haya sombra ni margen de previsualización.
$reset = '<style>
  body { margin:0; padding:0; background:#fff; }
  .page { box-shadow:none !important; margin:0 !important; }
</style>';
$html = str_replace('</head>', $reset . '</head>', $html);

header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: SAMEORIGIN');
header('Cache-Control: max-age=300');
echo $html;
