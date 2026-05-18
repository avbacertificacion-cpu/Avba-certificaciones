<?php
/**
 * generar_dictamen_web.php
 * Streams dictamen_preview.html as PDF to the browser via mPDF.
 * Used by test_dictamen.php — TEMPORAL, solo para pruebas.
 */

require __DIR__ . '/vendor/autoload.php';

$htmlFile = __DIR__ . '/dictamen_preview.html';
if (!file_exists($htmlFile)) {
    http_response_code(500);
    die('ERROR: dictamen_preview.html no encontrado.');
}

$html = file_get_contents($htmlFile);

$mpdfOverride = '<style>'
    . '.page{min-height:0!important;margin:0!important;box-shadow:none!important;}'
    . 'body{background:#fff!important;}'
    . '</style>';
$html = str_replace('</head>', $mpdfOverride . '</head>', $html);

$config = [
    'mode'          => 'utf-8',
    'format'        => [215, 279],
    'margin_left'   => 0,
    'margin_right'  => 0,
    'margin_top'    => 0,
    'margin_bottom' => 0,
    'margin_header' => 0,
    'margin_footer' => 0,
    'dpi'           => 96,
    'default_font'  => 'dejavusans',
];

$mpdf = new \Mpdf\Mpdf($config);
$mpdf->SetBasePath(__DIR__ . '/');
$mpdf->SetHTMLFooter('');
$mpdf->use_kwt          = false;
$mpdf->useSubstitutions = true;
$mpdf->WriteHTML($html);

// 'I' = inline en navegador  |  'D' = forzar descarga
$modo = ($_GET['descarga'] ?? '0') === '1' ? 'D' : 'I';
$mpdf->Output('dictamen_avba.pdf', $modo);
