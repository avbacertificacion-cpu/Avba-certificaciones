<?php
/**
 * AVBA Certificaciones — Descarga de archivos generados
 *
 * Sirve archivos generados por el sistema (por ahora, los informes de
 * inspecciones en Excel) forzando la descarga con cabeceras adecuadas. Se usa
 * en lugar del enlace estático porque uploads/.htaccess restringe el servido
 * directo, y porque la descarga por navegación funciona de forma fiable
 * también en navegadores móviles.
 *
 * Uso: descargar.php?tipo=informe&id=<id>&token=<token de sesión>
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/api/helpers.php';

$tipo  = $_GET['tipo']  ?? '';
$id    = (int)($_GET['id'] ?? 0);
$token = $_GET['token'] ?? '';

function fallo(int $code, string $msg): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    exit($msg);
}

$pdo = Database::getConnection();
$usr = validarToken($pdo, $token);
if (!$usr) fallo(401, 'No autorizado.');

// ── Expediente combinado del portal del cliente ──────────────────────────
// Se sirve por PHP y no como archivo estático porque Apache no entrega los
// archivos recién creados bajo uploads/ en este hosting (mismo caso que el
// informe de inspecciones). El id_cliente va en el nombre del archivo, así
// que un cliente sólo puede descargar los expedientes de su propia empresa.
if ($tipo === 'expediente') {
    if (!in_array($usr['rol'], ['CLIENTE', 'ADMIN'], true)) fallo(401, 'No autorizado.');

    $archivo = basename((string)($_GET['f'] ?? ''));
    if ($archivo === '' || !preg_match('/^EXPEDIENTE_[A-Za-z0-9]+_[A-Z]+_\d+_\d{8}_\d{6}\.pdf$/', $archivo)) {
        fallo(400, 'Nombre de archivo no válido.');
    }

    if ($usr['rol'] === 'CLIENTE') {
        $idc = trim((string)($usr['id_cliente'] ?? ''));
        if (ctype_digit($idc)) $idc = str_pad($idc, 5, '0', STR_PAD_LEFT);
        // EXPEDIENTE_{idCliente}_{TIPO}_{id}_{fecha}.pdf
        $partes = explode('_', $archivo);
        if ($idc === '' || ($partes[1] ?? '') !== $idc) fallo(403, 'Este expediente no pertenece a tu empresa.');
    }

    $abs = __DIR__ . '/uploads/impresiones/' . $archivo;
    if (!is_file($abs)) fallo(404, 'El expediente ya no está disponible.');

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $archivo . '"');
    header('Content-Length: ' . filesize($abs));
    header('Cache-Control: no-store');
    readfile($abs);
    exit;
}

// ── Informe de inspecciones (auditorías) ─────────────────────────────────
if (!in_array($usr['rol'], ['ADMIN', 'CALIDAD'], true)) fallo(401, 'No autorizado.');
if ($tipo !== 'informe' || $id <= 0) fallo(400, 'Solicitud inválida.');

$stmt = $pdo->prepare("SELECT archivo FROM informes_inspecciones WHERE id = ?");
$stmt->execute([$id]);
$rel = (string)($stmt->fetchColumn() ?: '');
if ($rel === '') fallo(404, 'Informe no encontrado.');

// Seguridad: sólo permitir rutas dentro de uploads/ y con extensión esperada
$rel = ltrim($rel, '/');
if (strpos($rel, '..') !== false || strncmp($rel, 'uploads/', 8) !== 0) {
    fallo(400, 'Ruta no permitida.');
}
$abs = __DIR__ . '/' . $rel;
if (!is_file($abs)) fallo(404, 'El archivo ya no está disponible en el servidor.');

$nombre = basename($abs);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $nombre . '"');
header('Content-Length: ' . filesize($abs));
header('Cache-Control: no-store');
readfile($abs);
