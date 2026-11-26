<?php
/* ══════════════════════════════════════════════════════════════
   AVBA Control de Accesos — fotos de credenciales y PDF, sólo con sesión

   uploads/accesos/ está cerrada al acceso directo (son datos personales):
   lo que guarda se entrega aquí, a una cuenta con sesión vigente.

     descargar.php?tipo=acceso_foto&id=<registro>[&m=1]&token=<sesión>
     descargar.php?tipo=incidencia_foto&id=<incidencia>[&m=1]&token=<sesión>
     descargar.php?tipo=accesos_pdf&f=<archivo>&token=<sesión>

   El guardia de caseta sólo ve las fotos que registró él y no baja PDF.
   ══════════════════════════════════════════════════════════════ */

function fallo(int $codigo, string $msg): void {
    http_response_code($codigo);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    exit($msg);
}

if (!is_file(__DIR__ . '/config/config.php')) fallo(500, 'Falta la configuración del servidor.');
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/api/helpers.php';
require_once __DIR__ . '/api/ControlAccesos.php';

$tipo = (string)($_GET['tipo'] ?? '');
$pdo = Database::conexion();
$usr = validarToken($pdo, tokenDePeticion());
if (!$usr || !ControlAccesos::permitido($usr)) fallo(401, 'Tu sesión venció. Vuelve a iniciar sesión.');

if ($tipo === 'acceso_foto' || $tipo === 'incidencia_foto') {
    $ca = new ControlAccesos($pdo);
    $abs = $tipo === 'acceso_foto' ? $ca->rutaFoto((int)($_GET['id'] ?? 0), !empty($_GET['m']), $usr)
                                   : $ca->rutaFotoIncidencia((int)($_GET['id'] ?? 0), !empty($_GET['m']), $usr);
    if (!$abs) fallo(404, 'La foto ya no está disponible.');
    $mime = @mime_content_type($abs) ?: 'image/jpeg';
    header('Content-Type: ' . (in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) ? $mime : 'image/jpeg'));
    header('Cache-Control: private, max-age=86400');
} elseif ($tipo === 'accesos_pdf') {
    if (ControlAccesos::esCaseta($usr)) fallo(403, 'Esta cuenta sólo registra entradas y salidas.');
    $archivo = basename((string)($_GET['f'] ?? ''));
    $abs = ControlAccesos::rutaPdf($archivo);
    if (!$abs) fallo(404, 'El PDF ya no está disponible. Vuelve a generarlo.');
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $archivo . '"');
    header('Cache-Control: no-store');
} else {
    fallo(400, 'Solicitud no válida.');
}
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($abs));
readfile($abs);
