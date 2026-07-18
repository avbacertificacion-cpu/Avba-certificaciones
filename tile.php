<?php
/**
 * AVBA Certificaciones — Proxy interno de teselas (tiles) de OpenStreetMap
 *
 * En algunas redes móviles/DNS de los inspectores, el navegador no logra
 * cargar las teselas directamente desde tile.openstreetmap.org (el mapa se
 * queda en gris). Este proxy las descarga desde el servidor —que sí tiene
 * conectividad estable— y las sirve desde el propio dominio, de modo que el
 * mapa siempre pinta. Las teselas se cachean en disco para respetar la
 * política de uso de OSM y acelerar cargas posteriores.
 *
 * Uso: tile.php?z=<zoom>&x=<col>&y=<fila>
 */

$z = isset($_GET['z']) ? (int)$_GET['z'] : -1;
$x = isset($_GET['x']) ? (int)$_GET['x'] : -1;
$y = isset($_GET['y']) ? (int)$_GET['y'] : -1;

// Validar rangos válidos de una tesela XYZ
$max = (1 << max($z, 0)) - 1;
if ($z < 0 || $z > 19 || $x < 0 || $y < 0 || $x > $max || $y > $max) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Parámetros de tesela inválidos.');
}

$cacheDir  = __DIR__ . '/uploads/tiles/' . $z . '/' . $x;
$cacheFile = $cacheDir . '/' . $y . '.png';

function servirTesela(string $bytes): void {
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=2592000'); // 30 días
    echo $bytes;
    exit;
}

// 1) Servir desde caché si existe y es reciente (30 días)
if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 2592000) {
    $bytes = @file_get_contents($cacheFile);
    if ($bytes !== false && $bytes !== '') servirTesela($bytes);
}

// 2) Descargar desde OpenStreetMap (con User-Agent identificable, requerido)
$url = "https://tile.openstreetmap.org/{$z}/{$x}/{$y}.png";
$bytes = '';
if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_USERAGENT      => 'AVBA-Certificaciones/1.0 (+https://gestion.avba.com.mx)',
        CURLOPT_HTTPHEADER     => ['Referer: https://gestion.avba.com.mx'],
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp !== false && $code === 200) $bytes = $resp;
}

// 3) Fallback: si falló la descarga pero hay una copia cacheada vieja, úsala
if ($bytes === '' && is_file($cacheFile)) {
    $viejo = @file_get_contents($cacheFile);
    if ($viejo !== false && $viejo !== '') servirTesela($viejo);
}

if ($bytes === '') {
    http_response_code(502);
    header('Content-Type: text/plain; charset=utf-8');
    exit('No se pudo obtener la tesela del mapa.');
}

// 4) Guardar en caché (mejor esfuerzo) y servir
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
if (is_dir($cacheDir)) @file_put_contents($cacheFile, $bytes);

servirTesela($bytes);
