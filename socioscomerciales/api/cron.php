<?php
/**
 * Socios Comerciales AVBA — Tareas programadas
 *
 * Se invoca desde el cron del hosting, una vez al día:
 *
 *   curl -s "https://gestion.avba.com.mx/socioscomerciales/api/cron.php?clave=LA_CLAVE"
 *
 * Hoy solo manda el correo automático de la semana. Está protegido con
 * SC_CRON_CLAVE porque una URL abierta que dispara correos es un botón de
 * spam para cualquiera que la descubra.
 *
 * No es imprescindible: el portal también manda esos correos aprovechando su
 * propio tráfico (ver el final de api/index.php). El cron sirve para que
 * salgan a su hora aunque nadie entre al portal ese día.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/Correos.php';

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

if (!defined('SC_CRON_CLAVE') || SC_CRON_CLAVE === '') {
    http_response_code(403);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Define SC_CRON_CLAVE en config/config.php para poder usar el cron.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// hash_equals evita distinguir la clave por el tiempo de respuesta
if (!hash_equals(SC_CRON_CLAVE, (string) ($_GET['clave'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'No autorizado.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// El cron corre sin prisa: puede vaciar la cola de una pasada en vez de
// dejarla para el día siguiente.
@set_time_limit(300);

try {
    $correos = new ScCorreos(scDB());

    // Varias vueltas hasta agotar la cola, con tope por si algo va mal y la
    // condición nunca deja de cumplirse.
    $totalEnviados = 0;
    $totalFallidos = 0;

    for ($vuelta = 0; $vuelta < 25; $vuelta++) {
        $r = $correos->procesarAutomaticos(20);
        $totalEnviados += (int) ($r['enviados'] ?? 0);
        $totalFallidos += (int) ($r['fallidos'] ?? 0);

        if ((int) ($r['enviados'] ?? 0) === 0 && (int) ($r['fallidos'] ?? 0) === 0) break;
    }

    echo json_encode([
        'status'   => 'success',
        'enviados' => $totalEnviados,
        'fallidos' => $totalFallidos,
        'fecha'    => date('c'),
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('SC cron: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Error al ejecutar las tareas.'], JSON_UNESCAPED_UNICODE);
}
