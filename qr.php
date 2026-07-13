<?php
/**
 * AVBA Certificaciones — Generador interno de códigos QR
 *
 * Reemplaza la dependencia de un servicio externo (quickchart.io) para
 * mostrar códigos QR en el navegador (por ejemplo, el QR de acceso a una
 * sesión de curso, o el QR de un certificado en su vista de detalle).
 * No requiere sesión — es equivalente a lo que antes pedía un tercero.
 *
 * Uso: qr.php?text=<contenido a codificar>&size=<ancho total en px, opcional>&margin=<opcional>
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/api/helpers.php';

$texto  = (string)($_GET['text'] ?? '');
$size   = max(50, min(1000, (int)($_GET['size']   ?? 300)));
$margin = max(0,  min(30,   (int)($_GET['margin'] ?? 8)));

if ($texto === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Falta el parámetro "text".');
}
if (strlen($texto) > 2000) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Texto demasiado largo para un código QR.');
}

$bytes = qrPngBytes($texto, $size, $margin);
if ($bytes === '') {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('No se pudo generar el código QR.');
}

header('Content-Type: image/png');
// El QR de un mismo texto siempre es idéntico — se puede cachear largo tiempo.
header('Cache-Control: public, max-age=604800, immutable');
echo $bytes;
