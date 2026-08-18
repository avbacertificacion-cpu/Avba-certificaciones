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
            // Sin SMTP los correos salen por mail() y suelen acabar en spam
            'smtp_configurado'        => scSmtpConfigurado(),
            'smtp_host'               => defined('SC_MAIL_HOST') ? SC_MAIL_HOST : '(sin definir)',
            'smtp_puerto'             => defined('SC_MAIL_PORT') ? SC_MAIL_PORT : '(por defecto 465)',
            'phpmailer_encontrado'    => scCargarPhpMailer(),
            'metodo_de_envio'         => (scSmtpConfigurado() && scCargarPhpMailer())
                                          ? 'SMTP autenticado' : 'mail() de PHP',
        ],
        'automaticos' => [
            'cron_clave_definida' => defined('SC_CRON_CLAVE') && SC_CRON_CLAVE !== '',
            'url_cron'            => scUrlBase() . '/api/cron.php?clave=...',
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
        // El mensaje de PDO trae el usuario y el nombre de la base
        // ("Access denied for user 'u123_sc'@'localhost' to database ...").
        // Se registra en el log y aquí solo se publica el código SQLSTATE.
        error_log('SC diagnostico: ' . $errorConexion);
        $codigo = preg_match('/SQLSTATE\[(\w+)\]/', $errorConexion, $m) ? $m[1] : 'desconocido';

        $r['status'] = 'error';
        $r['base_de_datos'] = [
            'conexion' => 'FALLÓ',
            'sqlstate' => $codigo,
            'pista'    => $codigo === '28000' || $codigo === 'HY000'
                ? 'Revisa SC_DB_USER / SC_DB_PASS / SC_DB_NAME en config/config.php'
                : 'El detalle quedó en el log de errores del servidor',
        ];
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
