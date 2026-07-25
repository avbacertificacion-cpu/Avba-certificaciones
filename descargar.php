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
if (!$usr || !in_array($usr['rol'], ['ADMIN', 'CALIDAD'], true)) {
    fallo(401, 'No autorizado.');
}

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
