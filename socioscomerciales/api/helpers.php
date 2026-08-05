<?php
/**
 * Socios Comerciales AVBA — Funciones de apoyo
 */

// La sesión dura 7 días, pero se renueva sola mientras se use (ver
// scValidarToken): quien entra a diario no vuelve a escribir la contraseña y
// un token robado caduca en una semana, no en un mes.
const SC_TOKEN_TTL     = 60 * 60 * 24 * 7; // Sesión: 7 días
const SC_TOKEN_RENUEVA = 60 * 60 * 24;     // Se estira si le queda menos de 1 día
const SC_VERIF_TTL     = 60 * 60 * 48;     // Enlace de verificación: 48 horas
const SC_RESET_TTL     = 60 * 60 * 2;      // Enlace de restablecer contraseña: 2 horas

/** Largo mínimo de contraseña, en cliente y en servidor. */
const SC_PASSWORD_MIN = 8;

/**
 * Versión vigente de los términos y del aviso de privacidad.
 *
 * Se guarda junto con la fecha en cada alta. Si el texto cambia hay que
 * subir esta constante: así se sabe quién aceptó qué y a quién habría que
 * volver a preguntarle, en vez de tener un "sí" suelto sin contexto.
 * Formato de fecha, que es lo que aparece al pie de terminos.html.
 */
const SC_TERMINOS_VERSION = '2026-08-05';

/**
 * Quién firma el aviso de "documentos en revisión" que se manda a los
 * postulantes. Se deja en una constante porque es texto de cara al candidato
 * y cambiarlo no debería obligar a tocar la lógica de envío.
 */
const SC_REVISOR = 'AVBA Inspections, Certifications and Maintenance';

/** Máximo de postulantes a los que se avisa de una sola vez. */
const SC_MAX_AVISOS = 50;

/**
 * Coste de bcrypt, fijado a propósito en vez de usar el de por defecto:
 * PHP lo subió de 10 a 12 entre versiones, así que sin fijarlo el mismo
 * código produce hashes de distinta dureza según el servidor — y el hash de
 * relleno de abajo dejaría de tardar lo mismo que uno real.
 */
const SC_BCRYPT_COSTE = 11;

/**
 * Hash bcrypt VÁLIDO de una cadena aleatoria que nadie conoce.
 *
 * Se compara contra él cuando el correo no existe, para que entrar con un
 * usuario inexistente tarde lo mismo que con uno real. El relleno anterior no
 * era un hash bcrypt bien formado: password_verify lo rechazaba de inmediato
 * (66 ms frente a 263 ms), así que el tiempo de respuesta delataba qué
 * correos están registrados. Si se cambia SC_BCRYPT_COSTE hay que regenerar
 * esta constante con el mismo coste.
 */
const SC_HASH_RELLENO = '$2y$11$lcAQapTbrPGi2hDAEi/OwO8yxp0FOpGp3aw/u752c3uwLeRDZjERy';

/**
 * ¿Este correo es de administración?
 *
 * La lista vive en config/config.php (SC_ADMINS), NO en la base de datos, y
 * es a propósito: config.php solo existe en el servidor y no se versiona, así
 * que ni un INSERT malicioso ni una fuga de la base convierten a nadie en
 * administrador. Para dar o quitar el permiso se edita ese archivo.
 *
 * hash_equals no aporta nada aquí (el correo del usuario ya se conoce), pero
 * la comparación se hace en minúsculas y sin espacios para que un correo bien
 * escrito nunca se quede fuera por un detalle de formato.
 */
function scEsAdmin(?string $correo): bool {
    if (!$correo || !defined('SC_ADMINS') || !is_array(SC_ADMINS)) return false;

    $buscado = strtolower(trim($correo));
    foreach (SC_ADMINS as $admin) {
        if (strtolower(trim((string) $admin)) === $buscado) return true;
    }
    return false;
}

/** Hash de contraseña con el coste fijado por el portal. */
function scHashPassword(string $password): string {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => SC_BCRYPT_COSTE]);
}

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
 * Dominios de los que nos fiamos para construir enlaces.
 * La cabecera Host la controla por completo quien hace la petición: si se
 * usara tal cual, bastaría enviar `Host: evil.tld` junto a SOLICITAR_RESET
 * para que la víctima recibiera un correo legítimo de AVBA cuyo enlace,
 * con un token de restablecimiento válido, apunta al servidor del atacante.
 */
const SC_HOSTS_PERMITIDOS = ['gestion.avba.com.mx', 'www.gestion.avba.com.mx'];

/** URL usada cuando el Host no es de fiar y no hay SC_URL_BASE en config. */
const SC_URL_BASE_RESPALDO = 'https://gestion.avba.com.mx/socioscomerciales';

/**
 * URL base pública del portal (sin barra final), p. ej.
 * https://gestion.avba.com.mx/socioscomerciales
 * Nunca se construye a partir de un Host no reconocido.
 */
function scUrlBase(): string {
    if (defined('SC_URL_BASE') && SC_URL_BASE) return rtrim(SC_URL_BASE, '/');

    // Quitar el puerto antes de comparar (ej. "host:443")
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if (($pos = strpos($host, ':')) !== false) $host = substr($host, 0, $pos);

    if (!in_array($host, SC_HOSTS_PERMITIDOS, true)) {
        return SC_URL_BASE_RESPALDO;
    }

    $esHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    $esquema = $esHttps ? 'https' : 'http';

    // /socioscomerciales/api/index.php → /socioscomerciales
    $dirApi = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/socioscomerciales/api/index.php'));
    $base   = rtrim(dirname($dirApi), '/');

    return "{$esquema}://{$host}{$base}";
}

/**
 * Recorta un texto al largo de su columna y quita caracteres de control.
 * Sin esto, un valor demasiado largo provoca el error 1406 de MariaDB en
 * modo estricto y el usuario ve un 500 en vez de un mensaje claro.
 */
function scTexto(?string $valor, int $max): ?string {
    if ($valor === null) return null;
    $v = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $valor) ?? '');
    if ($v === '') return null;
    return mb_substr($v, 0, $max);
}

/** IP del cliente, para contar intentos. */
function scIpCliente(): string {
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

/**
 * Límite de intentos por ventana de tiempo.
 * Devuelve true si la acción está permitida y la registra; false si se pasó.
 * Sin esto, LOGIN admite miles de contraseñas por minuto y SOLICITAR_RESET
 * permite inundar de correos el buzón de cualquier usuario.
 */
function scLimite(PDO $pdo, string $tipo, string $clave, int $max, int $ventanaSeg): bool {
    try {
        $desde = date('Y-m-d H:i:s', time() - $ventanaSeg);

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM sc_intentos WHERE tipo = ? AND clave = ? AND ts > ?"
        );
        $stmt->execute([$tipo, $clave, $desde]);

        if ((int) $stmt->fetchColumn() >= $max) return false;

        $pdo->prepare("INSERT INTO sc_intentos (tipo, clave) VALUES (?, ?)")
            ->execute([$tipo, mb_substr($clave, 0, 190)]);

        // Limpieza ocasional para que la tabla no crezca sin fin
        if (random_int(1, 50) === 1) {
            $pdo->prepare("DELETE FROM sc_intentos WHERE ts < ?")
                ->execute([date('Y-m-d H:i:s', time() - 86400)]);
        }
        return true;
    } catch (PDOException $e) {
        // Falla en abierto a propósito: si sc_intentos no existe todavía
        // (primer arranque, migración a medias) preferimos un portal que
        // funcione a uno que rechace a todo el mundo. Queda en el log con
        // una marca clara porque, mientras dure, no hay freno a la fuerza
        // bruta y hay que enterarse.
        error_log('SC ALERTA: scLimite deshabilitado, sin proteccion de fuerza bruta — ' . $e->getMessage());
        return true;
    }
}

/**
 * Olvida los intentos de una clave. Se llama al entrar bien: si no, los
 * ingresos correctos cuentan igual que los fallidos y ocho entradas
 * legítimas en 15 minutos bloqueaban la cuenta de su propio dueño.
 */
function scLimpiarIntentos(PDO $pdo, string $tipo, string $clave): void {
    try {
        $pdo->prepare("DELETE FROM sc_intentos WHERE tipo = ? AND clave = ?")
            ->execute([$tipo, mb_substr($clave, 0, 190)]);
    } catch (PDOException $e) {
        error_log('scLimpiarIntentos: ' . $e->getMessage());
    }
}

/**
 * Escapa los comodines de LIKE.
 *
 * Sin esto, buscar "%" o "_" no busca esos caracteres: los interpreta como
 * comodines, así que "_" devuelve todo el catálogo y obliga a MariaDB a
 * recorrer la tabla entera. El backslash se escapa primero o rompería a los
 * otros dos.
 */
function scEscaparLike(string $texto): string {
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $texto);
}

/**
 * Convierte el `offset`/`limite` que llega del cliente en valores sanos.
 * Devuelve [limite, offset]; el límite nunca pasa de $maxLimite.
 */
function scPaginacion(array $filtros, int $porDefecto = 24, int $maxLimite = 60): array {
    $limite = (int) ($filtros['limite'] ?? $porDefecto);
    if ($limite < 1)          $limite = $porDefecto;
    if ($limite > $maxLimite) $limite = $maxLimite;

    $offset = (int) ($filtros['offset'] ?? 0);
    if ($offset < 0)      $offset = 0;
    if ($offset > 100000) $offset = 100000;   // tope duro, evita escaneos absurdos

    return [$limite, $offset];
}

/**
 * Convierte "12,7,12,x,9" en [12, 7, 9].
 *
 * Las selecciones múltiples llegan como texto y no como array porque el
 * router descarta del payload todo lo que no sea escalar (ver index.php): un
 * ?ids[]=... o un JSON con array haría fallar los trim() posteriores.
 * Devuelve enteros positivos, sin repetidos y como mucho $max.
 */
function scListaIds($valor, int $max = 100): array {
    if (is_array($valor)) $partes = $valor;
    else                  $partes = explode(',', (string) $valor);

    $ids = [];
    foreach ($partes as $parte) {
        $n = (int) trim((string) $parte);
        if ($n > 0 && !in_array($n, $ids, true)) $ids[] = $n;
        if (count($ids) >= $max) break;
    }
    return $ids;
}

/** Valida una fecha ISO (YYYY-MM-DD); devuelve null si no lo es. */
function scFecha(?string $valor): ?string {
    if (!$valor) return null;
    $d = DateTime::createFromFormat('Y-m-d', trim($valor));
    return ($d && $d->format('Y-m-d') === trim($valor)) ? $d->format('Y-m-d') : null;
}

/**
 * Valida el token de sesión enviado en cada request.
 * Devuelve el usuario (sc_usuarios) o null si no es válido.
 *
 * Las sesiones viven en sc_sesiones, una fila por dispositivo. Antes había
 * una sola columna session_token en sc_usuarios, así que entrar desde el
 * teléfono cerraba la sesión de la computadora sin avisar.
 *
 * Mientras el token se use se estira su caducidad (SC_TOKEN_RENUEVA): quien
 * entra a diario nunca vuelve a escribir la contraseña, pero un token robado
 * y guardado en un cajón caduca igual a los 7 días.
 */
function scValidarToken(PDO $pdo, ?string $token): ?array {
    if (!$token) return null;

    $stmt = $pdo->prepare(
        "SELECT u.id, u.tipo, u.correo, u.activo, u.correo_verificado,
                s.id AS sesion_id, s.expira
         FROM sc_sesiones s
         JOIN sc_usuarios u ON u.id = s.usuario_id
         WHERE s.token = ? AND u.activo = 1 AND s.expira > NOW()"
    );
    $stmt->execute([$token]);
    $fila = $stmt->fetch();
    if (!$fila) return null;

    if (strtotime($fila['expira']) - time() < SC_TOKEN_RENUEVA) {
        try {
            $pdo->prepare("UPDATE sc_sesiones SET expira = ?, ultimo_uso = NOW() WHERE id = ?")
                ->execute([date('Y-m-d H:i:s', time() + SC_TOKEN_TTL), $fila['sesion_id']]);
        } catch (PDOException $e) {
            error_log('scValidarToken renovar: ' . $e->getMessage());
        }
    }

    unset($fila['expira']);
    $fila['es_admin'] = scEsAdmin($fila['correo']) ? 1 : 0;
    return $fila;
}

/** Descripción corta del dispositivo, para que el usuario reconozca sus sesiones. */
function scDispositivo(): string {
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($ua === '') return 'Dispositivo desconocido';

    $so = 'Otro';
    foreach (['Android' => 'Android', 'iPhone' => 'iPhone', 'iPad' => 'iPad',
              'Windows' => 'Windows', 'Macintosh' => 'Mac', 'Linux' => 'Linux'] as $aguja => $nombre) {
        if (stripos($ua, $aguja) !== false) { $so = $nombre; break; }
    }

    $nav = 'navegador';
    foreach (['Edg' => 'Edge', 'OPR' => 'Opera', 'Chrome' => 'Chrome',
              'Safari' => 'Safari', 'Firefox' => 'Firefox'] as $aguja => $nombre) {
        if (stripos($ua, $aguja) !== false) { $nav = $nombre; break; }
    }

    return mb_substr("{$nav} en {$so}", 0, 60);
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

    if (strpos($mime, 'image/') === 0) {
        scReprocesarImagen($destino, $mime);
    }

    return 'uploads/' . $carpeta . '/' . $nombre;
}

/**
 * Redibuja una imagen subida para quedarse solo con los píxeles.
 *
 * Dos motivos. Uno de privacidad: las fotos de un teléfono llevan EXIF con
 * la geolocalización exacta de donde se tomaron, y esa foto se publica en el
 * perfil. Otro de seguridad: un archivo puede ser imagen válida y a la vez
 * llevar código incrustado; al redibujarlo solo sobreviven los píxeles.
 *
 * Si GD no está o algo falla, se deja el archivo original: ya pasó la
 * validación de MIME y la carpeta no ejecuta nada, así que no vale la pena
 * rechazar la subida por esto.
 */
function scReprocesarImagen(string $ruta, string $mime): void {
    if (!function_exists('imagecreatefromstring')) return;

    try {
        $datos = @file_get_contents($ruta);
        if ($datos === false) return;

        $img = @imagecreatefromstring($datos);
        if (!$img) return;

        // Los GIF animados perderían la animación al redibujarlos; se dejan
        // como están (el EXIF no existe en GIF y el riesgo ya está acotado).
        if ($mime === 'image/gif' && substr_count($datos, "\x00\x21\xF9\x04") > 1) {
            imagedestroy($img);
            return;
        }

        $ancho = imagesx($img);
        $alto  = imagesy($img);

        // Tope de 1600 px: una foto de móvil de 4000 px no aporta nada en un
        // avatar y multiplica el disco y el tiempo de carga.
        $max = 1600;
        if ($ancho > $max || $alto > $max) {
            $escala = $max / max($ancho, $alto);
            $nuevo  = imagescale($img, (int) round($ancho * $escala), (int) round($alto * $escala));
            if ($nuevo) { imagedestroy($img); $img = $nuevo; }
        }

        $tmp = $ruta . '.tmp';
        $ok  = false;
        switch ($mime) {
            case 'image/jpeg': $ok = imagejpeg($img, $tmp, 86); break;
            case 'image/png':
                imagesavealpha($img, true);
                $ok = imagepng($img, $tmp, 6);
                break;
            case 'image/webp': $ok = function_exists('imagewebp') && imagewebp($img, $tmp, 86); break;
            case 'image/gif':  $ok = imagegif($img, $tmp); break;
        }
        imagedestroy($img);

        if ($ok && is_file($tmp) && filesize($tmp) > 0) {
            @rename($tmp, $ruta);
            @chmod($ruta, 0644);
        } elseif (is_file($tmp)) {
            @unlink($tmp);
        }
    } catch (Throwable $e) {
        error_log('scReprocesarImagen: ' . $e->getMessage());
    }
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
