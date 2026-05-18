<?php
/**
 * generar_cert_web.php
 * Streams certificado_preview.html as PDF to the browser via mPDF.
 * Used by test_cert.php — TEMPORAL, solo para pruebas.
 */

require __DIR__ . '/vendor/autoload.php';

$htmlFile = __DIR__ . '/certificado_preview.html';
if (!file_exists($htmlFile)) {
    http_response_code(500);
    die('ERROR: certificado_preview.html no encontrado.');
}

$html = file_get_contents($htmlFile);

// Only strip page box-shadow/margin — preserve body background-image for pattern
$mpdfOverride = '<style>'
    . '.page{min-height:0!important;margin:0!important;box-shadow:none!important;}'
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
$mpdf->Output('certificado_avba.pdf', $modo);
