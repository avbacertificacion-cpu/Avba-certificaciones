<?php
/**
 * Socios Comerciales AVBA — Funciones de apoyo
 */

const SC_TOKEN_TTL  = 60 * 60 * 24 * 30; // Sesión: 30 días
const SC_VERIF_TTL  = 60 * 60 * 48;      // Enlace de verificación: 48 horas
const SC_RESET_TTL  = 60 * 60 * 2;       // Enlace de restablecer contraseña: 2 horas

// Remitente de los correos del portal (se puede sobrescribir en config.php)
if (!defined('SC_MAIL_FROM'))        define('SC_MAIL_FROM', 'no-reply@avba.com.mx');
if (!defined('SC_MAIL_FROM_NOMBRE')) define('SC_MAIL_FROM_NOMBRE', 'AVBA Socios Comerciales');

/**
 * Genera un token seguro de 64 caracteres hex.
 */
function scGenerarToken(): string {
    return bin2hex(random_bytes(32));
}

/**
 * URL base pública del portal (sin barra final), p. ej.
 * https://gestion.avba.com.mx/socioscomerciales
 */
function scUrlBase(): string {
    if (defined('SC_URL_BASE') && SC_URL_BASE) return rtrim(SC_URL_BASE, '/');

    $esHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    $esquema = $esHttps ? 'https' : 'http';
    $host    = $_SERVER['HTTP_HOST'] ?? 'gestion.avba.com.mx';

    // /socioscomerciales/api/index.php → /socioscomerciales
    $dirApi = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/socioscomerciales/api/index.php'));
    $base   = rtrim(dirname($dirApi), '/');

    return "{$esquema}://{$host}{$base}";
}

/**
 * Valida el token de sesión enviado en cada request.
 * Devuelve el usuario (sc_usuarios) o null si no es válido.
 */
function scValidarToken(PDO $pdo, ?string $token): ?array {
    if (!$token) return null;

    $stmt = $pdo->prepare(
        "SELECT id, tipo, correo, activo, correo_verificado
         FROM sc_usuarios
         WHERE session_token = ? AND activo = 1 AND token_expires > NOW()"
    );
    $stmt->execute([$token]);
    return $stmt->fetch() ?: null;
}

/**
 * Devuelve una respuesta JSON y termina la ejecución.
 */
function scRespuesta(array $data, int $httpCode = 200): void {
    http_response_code($httpCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Valida un correo electrónico.
 */
function scEsCorreoValido(string $correo): bool {
    return (bool) filter_var($correo, FILTER_VALIDATE_EMAIL);
}

/**
 * Traduce los códigos de error de subida de PHP a mensajes útiles.
 * Sin esto, un archivo más grande que upload_max_filesize falla en silencio.
 */
function scMensajeErrorSubida(int $codigo): string {
    switch ($codigo) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            $lim = ini_get('upload_max_filesize');
            return "El archivo supera el máximo que acepta el servidor ({$lim}).";
        case UPLOAD_ERR_PARTIAL:
            return 'La subida se interrumpió. Intenta de nuevo.';
        case UPLOAD_ERR_NO_FILE:
            return 'No se recibió ningún archivo.';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'El servidor no tiene carpeta temporal para subidas.';
        case UPLOAD_ERR_CANT_WRITE:
            return 'El servidor no pudo escribir el archivo en disco.';
        case UPLOAD_ERR_EXTENSION:
            return 'Una extensión de PHP bloqueó la subida.';
        default:
            return 'Error desconocido al subir el archivo.';
    }
}

/**
 * Detecta el MIME real del archivo.
 * Usa finfo si está disponible; si no (algunos hostings no cargan la
 * extensión fileinfo), cae a getimagesize para imágenes y a la firma
 * del archivo para PDF. Sin este respaldo la subida moría con error 500.
 */
function scDetectarMime(string $ruta): ?string {
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($ruta);
        if ($mime) return $mime;
    }

    $info = @getimagesize($ruta);
    if ($info && !empty($info['mime'])) return $info['mime'];

    $fh = @fopen($ruta, 'rb');
    if ($fh) {
        $cabecera = fread($fh, 5);
        fclose($fh);
        if ($cabecera === '%PDF-') return 'application/pdf';
    }

    return null;
}

/**
 * Guarda un archivo subido ($_FILES[campo]) validando tipo y tamaño.
 * Devuelve la URL relativa (uploads/carpeta/archivo.ext) o lanza RuntimeException
 * con un mensaje que se puede mostrar al usuario.
 */
function scGuardarArchivo(array $file, string $carpeta, array $mimesPermitidos, int $maxBytes): string {
    if (empty($file) || !isset($file['error'])) {
        // Suele pasar cuando el cuerpo del POST excede post_max_size:
        // PHP descarta $_POST y $_FILES por completo.
        throw new RuntimeException('No llegó el archivo al servidor. Puede que sea demasiado grande.');
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException(scMensajeErrorSubida((int) $file['error']));
    }
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('El archivo recibido no es válido.');
    }
    if ($file['size'] <= 0) {
        throw new RuntimeException('El archivo está vacío.');
    }
    if ($file['size'] > $maxBytes) {
        $mb = round($maxBytes / 1048576, 1);
        throw new RuntimeException("El archivo excede el máximo permitido ({$mb} MB).");
    }

    $mime = scDetectarMime($file['tmp_name']);
    if ($mime === null) {
        throw new RuntimeException('No se pudo determinar el tipo de archivo.');
    }
    if (!isset($mimesPermitidos[$mime])) {
        $permitidos = implode(', ', array_values($mimesPermitidos));
        throw new RuntimeException("Tipo de archivo no permitido. Se aceptan: {$permitidos}.");
    }
    $ext = $mimesPermitidos[$mime];

    $dir = __DIR__ . '/../uploads/' . $carpeta . '/';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('No se pudo crear la carpeta de destino en el servidor.');
    }
    if (!is_writable($dir)) {
        throw new RuntimeException('La carpeta de destino no tiene permisos de escritura.');
    }

    $nombre  = bin2hex(random_bytes(16)) . '.' . $ext;
    $destino = $dir . $nombre;

    if (!move_uploaded_file($file['tmp_name'], $destino)) {
        throw new RuntimeException('No se pudo guardar el archivo en el servidor.');
    }
    @chmod($destino, 0644);

    return 'uploads/' . $carpeta . '/' . $nombre;
}

/**
 * Borra un archivo subido previamente (para no acumular huérfanos al reemplazar).
 * Solo actúa sobre rutas dentro de uploads/.
 */
function scBorrarArchivo(?string $urlRelativa): void {
    if (!$urlRelativa || strpos($urlRelativa, 'uploads/') !== 0) return;
    if (strpos($urlRelativa, '..') !== false) return;

    $ruta = __DIR__ . '/../' . $urlRelativa;
    if (is_file($ruta)) @unlink($ruta);
}

/**
 * Envía un correo del portal. Devuelve true si el servidor lo aceptó.
 * Nunca lanza excepción: si el correo falla, el registro debe continuar.
 */
function scEnviarCorreo(string $para, string $asunto, string $html): bool {
    $remitente = SC_MAIL_FROM;
    $nombre    = SC_MAIL_FROM_NOMBRE;

    $cabeceras = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . mb_encode_mimeheader($nombre, 'UTF-8') . " <{$remitente}>",
        "Reply-To: {$remitente}",
        'X-Mailer: AVBA-SociosComerciales',
    ];

    $asuntoCodificado = mb_encode_mimeheader($asunto, 'UTF-8');

    try {
        return @mail($para, $asuntoCodificado, $html, implode("\r\n", $cabeceras), '-f' . $remitente);
    } catch (Throwable $e) {
        error_log('scEnviarCorreo: ' . $e->getMessage());
        return false;
    }
}

/**
 * Plantilla HTML de correo con la identidad AVBA.
 */
function scPlantillaCorreo(string $titulo, string $cuerpoHtml, string $textoBoton = '', string $urlBoton = ''): string {
    $boton = '';
    if ($textoBoton && $urlBoton) {
        $boton = '
        <tr><td style="padding:8px 32px 28px">
          <a href="' . htmlspecialchars($urlBoton, ENT_QUOTES) . '"
             style="display:inline-block;background:#185FA5;color:#ffffff;text-decoration:none;
                    font-weight:700;font-size:15px;padding:13px 28px;border-radius:10px">'
            . htmlspecialchars($textoBoton) . '</a>
        </td></tr>';
    }

    // Muchos clientes de correo bloquean imágenes remotas: el texto alternativo
    // deja el nombre de la marca visible aunque el logo no cargue.
    $logo = scUrlBase() . '/assets/avba-logo.png';

    return '<!DOCTYPE html><html lang="es"><body style="margin:0;padding:0;background:#F4F7FB">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#F4F7FB;padding:32px 12px">
<tr><td align="center">
  <table width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;
         border-radius:16px;overflow:hidden;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif">
    <tr><td style="background-color:#ffffff;padding:24px 32px 16px;border-bottom:3px solid #185FA5">
      <img src="' . htmlspecialchars($logo, ENT_QUOTES) . '" alt="AVBA"
           width="140" style="display:block;border:0;height:auto;max-width:140px;
           color:#0C447C;font-size:22px;font-weight:800">
      <div style="font-size:11.5px;color:#8792a8;margin-top:8px;letter-spacing:.4px">SOCIOS COMERCIALES</div>
    </td></tr>
    <tr><td style="padding:30px 32px 8px">
      <h1 style="margin:0 0 12px;font-size:20px;color:#1A2A44">' . htmlspecialchars($titulo) . '</h1>
      <div style="font-size:14px;line-height:1.6;color:#566079">' . $cuerpoHtml . '</div>
    </td></tr>'
    . $boton .
    '<tr><td style="padding:20px 32px;background:#F4F7FB;font-size:11.5px;color:#8792a8">
      AVBA Inspections, Certifications and Maintenance S.A.S. de C.V. — avba.com.mx<br>
      Si no solicitaste este correo, puedes ignorarlo.
    </td></tr>
  </table>
</td></tr></table></body></html>';
}
