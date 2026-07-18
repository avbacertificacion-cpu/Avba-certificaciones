<?php
/**
 * AVBA Certificaciones — Proxy interno de geocodificación (Nominatim / OSM)
 *
 * El navegador no puede llamar directamente a nominatim.openstreetmap.org
 * porque la política de seguridad (CSP: connect-src 'self') sólo permite
 * peticiones al propio dominio. Este endpoint reenvía la consulta desde el
 * servidor —que sí tiene salida a internet y un User-Agent identificable,
 * requerido por Nominatim— y devuelve el mismo JSON.
 *
 * Uso:
 *   geocode.php?q=<dirección>              → búsqueda (autocomplete)
 *   geocode.php?lat=<lat>&lon=<lon>        → geocodificación inversa
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function pedirNominatim(string $url): string {
    if (!function_exists('curl_init')) return '';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_USERAGENT      => 'AVBA-Certificaciones/1.0 (+https://gestion.avba.com.mx)',
        CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Referer: https://gestion.avba.com.mx'],
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($resp !== false && $code === 200) ? $resp : '';
}

$q   = trim((string)($_GET['q']   ?? ''));
$lat = isset($_GET['lat']) ? (string)$_GET['lat'] : '';
$lon = isset($_GET['lon']) ? (string)$_GET['lon'] : '';

if ($q !== '') {
    if (strlen($q) > 300) { echo '[]'; exit; }
    $url = 'https://nominatim.openstreetmap.org/search?format=json&limit=6&accept-language=es&q='
         . rawurlencode($q);
    $out = pedirNominatim($url);
    echo ($out !== '') ? $out : '[]';
    exit;
}

if ($lat !== '' && $lon !== '') {
    // Validar que sean coordenadas numéricas
    if (!is_numeric($lat) || !is_numeric($lon)) { echo '{}'; exit; }
    $la = (float)$lat; $lo = (float)$lon;
    if ($la < -90 || $la > 90 || $lo < -180 || $lo > 180) { echo '{}'; exit; }
    $url = 'https://nominatim.openstreetmap.org/reverse?format=json&accept-language=es'
         . '&lat=' . rawurlencode($lat) . '&lon=' . rawurlencode($lon);
    $out = pedirNominatim($url);
    echo ($out !== '') ? $out : '{}';
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Faltan parámetros (q o lat/lon).']);
