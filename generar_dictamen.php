<?php
/**
 * generar_dictamen.php
 * Generates dictamen_mpdf.pdf from dictamen_preview.html using mPDF.
 */

require __DIR__ . '/vendor/autoload.php';

// Read the HTML source
$htmlFile = __DIR__ . '/dictamen_preview.html';
if (!file_exists($htmlFile)) {
    fwrite(STDERR, "ERROR: dictamen_preview.html not found\n");
    exit(1);
}
$html = file_get_contents($htmlFile);

// mPDF configuration
$config = [
    'mode'          => 'utf-8',
    'format'        => [215, 279],   // width x height in mm
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

// Set base path so mPDF resolves relative asset paths (assets/logos/*.svg)
$mpdf->SetBasePath(__DIR__ . '/');

// Suppress default footer
$mpdf->SetHTMLFooter('');

// Allow @page CSS rules from the HTML to take effect
$mpdf->use_kwt          = false;
$mpdf->useSubstitutions = true;

// Write the HTML and generate the PDF
$mpdf->WriteHTML($html);

$outputPath = __DIR__ . '/dictamen_mpdf.pdf';
$mpdf->Output($outputPath, 'F');

$pageCount = $mpdf->page;
echo "OK pages={$pageCount}\n";
