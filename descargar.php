<?php
/* ══════════════════════════════════════════════════════════════
   AVBA Control de Accesos — fotos de credenciales y PDF, sólo con sesión

   uploads/accesos/ está cerrada al acceso directo (son datos personales):
   lo que guarda se entrega aquí, a una cuenta con sesión vigente.

     descargar.php?tipo=acceso_foto&id=<registro>[&m=1]&token=<sesión>
     descargar.php?tipo=incidencia_foto&id=<incidencia>[&m=1]&token=<sesión>
     descargar.php?tipo=accesos_pdf&f=<archivo>&token=<sesión>
     descargar.php?tipo=reporte&f=<archivo>&token=<sesión>      (Excel y PDF del tablero)

   El guardia de caseta sólo ve las fotos que registró él y no baja PDF.
   El cliente sólo baja los reportes del tablero (ni fotos ni los otros PDF).
   ══════════════════════════════════════════════════════════════ */

// Cualquier texto que se cuele antes (un espacio o salto de línea fuera de las etiquetas
// de PHP en config.php) dañaría la foto: se guarda aparte y se tira antes de mandar el archivo.
ob_start();

function fallo(int $codigo, string $msg): void {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code($codigo);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    exit($msg);
}

if (!is_file(__DIR__ . '/config/config.php')) fallo(500, 'Falta la configuración del servidor.');
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/api/helpers.php';
require_once __DIR__ . '/api/ControlAccesos.php';
require_once __DIR__ . '/api/Tablero.php';

$tipo = (string)($_GET['tipo'] ?? '');
$pdo = Database::conexion();
$usr = validarToken($pdo, tokenDePeticion());
if (!$usr || !ControlAccesos::permitido($usr)) fallo(401, 'Tu sesión venció. Vuelve a iniciar sesión.');

if (ControlAccesos::esCliente($usr) && $tipo !== 'reporte') fallo(403, 'Esta cuenta sólo descarga los reportes del tablero.');

if ($tipo === 'acceso_foto' || $tipo === 'incidencia_foto') {
    $ca = new ControlAccesos($pdo);
    $abs = $tipo === 'acceso_foto' ? $ca->rutaFoto((int)($_GET['id'] ?? 0), !empty($_GET['m']), $usr)
                                   : $ca->rutaFotoIncidencia((int)($_GET['id'] ?? 0), !empty($_GET['m']), $usr);
    if (!$abs) fallo(404, 'La foto ya no está disponible.');
    $mime = function_exists('mime_content_type') ? (@mime_content_type($abs) ?: '') : '';
    if ($mime === '') $mime = (@getimagesize($abs)['mime'] ?? '') ?: 'image/jpeg';
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
} elseif ($tipo === 'reporte') {
    if (!Tablero::puede($usr)) fallo(403, 'Tu cuenta no tiene acceso al tablero.');
    $archivo = basename((string)($_GET['f'] ?? ''));
    $abs = Tablero::rutaReporte($archivo);
    if (!$abs) fallo(404, 'El reporte ya no está disponible. Vuelve a generarlo.');
    $excel = str_ends_with($archivo, '.xlsx');
    header('Content-Type: ' . ($excel ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'application/pdf'));
    header('Content-Disposition: ' . ($excel ? 'attachment' : 'inline') . '; filename="' . $archivo . '"');
    header('Cache-Control: no-store');
} else {
    fallo(400, 'Solicitud no válida.');
}
while (ob_get_level() > 0) ob_end_clean();
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($abs));
readfile($abs);
