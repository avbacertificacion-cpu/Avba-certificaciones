<?php
/**
 * Socios Comerciales AVBA — Diagnóstico de instalación
 *
 * Reporta el estado del servidor y de la base de datos para localizar
 * fallos de configuración. NO expone credenciales: solo indica si las
 * constantes están definidas, nunca su valor.
 *
 * Se invoca con: api/index.php?action=DIAGNOSTICO
 */

function scDiagnostico(): array {
    $r = [
        'status'  => 'success',
        'php'     => [
            'version'             => PHP_VERSION,
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size'       => ini_get('post_max_size'),
            'memory_limit'        => ini_get('memory_limit'),
        ],
        'extensiones' => [
            'pdo_mysql' => extension_loaded('pdo_mysql'),
            'mbstring'  => extension_loaded('mbstring'),
            'fileinfo'  => extension_loaded('fileinfo'),
            'gd'        => extension_loaded('gd'),
        ],
        'correo' => [
            'funcion_mail_disponible' => function_exists('mail'),
            'remitente'               => defined('SC_MAIL_FROM') ? SC_MAIL_FROM : '(sin definir)',
        ],
        'url_base' => scUrlBase(),
    ];

    // ── Carpetas de subida ────────────────────────────────
    foreach (['cv', 'fotos', 'logos'] as $carpeta) {
        $ruta = __DIR__ . '/../uploads/' . $carpeta;
        $r['uploads'][$carpeta] = [
            'existe'   => is_dir($ruta),
            'escribible' => is_dir($ruta) && is_writable($ruta),
        ];
    }

    // ── Constantes de configuración (sin revelar valores) ──
    foreach (['SC_DB_HOST', 'SC_DB_NAME', 'SC_DB_USER', 'SC_DB_PASS'] as $c) {
        $r['config'][$c] = defined($c) ? (constant($c) !== '' ? 'definida' : 'VACÍA') : 'NO DEFINIDA';
    }

    // ── Base de datos ─────────────────────────────────────
    [$pdo, $errorConexion] = ScDatabase::probarConexion();

    if (!$pdo) {
        $r['status'] = 'error';
        $r['base_de_datos'] = ['conexion' => 'FALLÓ', 'error' => $errorConexion];
        return $r;
    }

    $r['base_de_datos'] = ['conexion' => 'OK'];

    try {
        $r['base_de_datos']['servidor'] = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
    } catch (Throwable $e) { /* opcional */ }

    // Estado del esquema ANTES de intentar migrar
    $r['esquema_antes'] = ScDatabase::estadoEsquema($pdo);

    // Intentar crear/migrar y reportar el error exacto si falla
    try {
        ScDatabase::instalarEsquema($pdo);
        $r['migracion'] = 'OK';
    } catch (Throwable $e) {
        $r['status']    = 'error';
        $r['migracion'] = 'FALLÓ: ' . $e->getMessage();
    }

    $r['esquema_despues'] = ScDatabase::estadoEsquema($pdo);

    return $r;
}
