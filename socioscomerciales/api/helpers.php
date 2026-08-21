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

/** Cuántos días vale un enlace de los que van dentro de un correo. */
const SC_FIRMA_TTL_DIAS = 60;

/**
 * Secreto con el que se firman los enlaces de los correos.
 *
 * Si config.php define SC_FIRMA_CLAVE, manda esa. Si no, se genera una vez y
 * se guarda en sc_meta. Se hace así para que la función salga andando sin
 * obligar a tocar el config del servidor: un secreto que hay que configurar a
 * mano es un secreto que acaba sin configurar, y entonces no habría firma.
 *
 * Cambiar el secreto invalida los enlaces ya enviados. Es justo lo que se
 * quiere si alguna vez se filtra.
 */
function scSecretoFirma(PDO $pdo): string {
    static $secreto = null;
    if ($secreto !== null) return $secreto;

    if (defined('SC_FIRMA_CLAVE') && SC_FIRMA_CLAVE !== '') {
        return $secreto = SC_FIRMA_CLAVE;
    }

    try {
        $stmt = $pdo->query("SELECT valor FROM sc_meta WHERE clave = 'firma_secreto'");
        $guardado = $stmt->fetchColumn();
        if (is_string($guardado) && $guardado !== '') return $secreto = $guardado;

        $nuevo = bin2hex(random_bytes(32));
        // INSERT IGNORE, no INSERT: si dos peticiones simultáneas llegan aquí
        // a la vez, la segunda no debe pisar el secreto de la primera — los
        // enlaces que esta ya hubiera firmado dejarían de valer.
        $pdo->prepare("INSERT IGNORE INTO sc_meta (clave, valor) VALUES ('firma_secreto', ?)")
            ->execute([$nuevo]);

        $stmt = $pdo->query("SELECT valor FROM sc_meta WHERE clave = 'firma_secreto'");
        return $secreto = (string) ($stmt->fetchColumn() ?: $nuevo);
    } catch (PDOException $e) {
        error_log('scSecretoFirma: ' . $e->getMessage());
        // Sin secreto estable no se puede firmar nada. Vale más romper el
        // enlace que emitir uno que cualquiera pueda falsificar.
        throw new RuntimeException('No se pudo preparar la firma de los enlaces.');
    }
}

/** base64 apto para URL: sin +, sin / y sin relleno. */
function scBase64Url(string $bin): string {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function scBase64UrlDecode(string $texto): string {
    return (string) base64_decode(strtr($texto, '-_', '+/'), true);
}

/**
 * Arma el token de un enlace de correo.
 *
 * Lleva a quién va dirigido, qué se le pregunta, qué respuesta representa y
 * hasta cuándo vale, todo firmado. Así una persona puede contestar desde el
 * correo sin escribir la contraseña, y aun así nadie puede contestar por
 * ella ni cambiar el número de usuario del enlace que le llegó.
 */
function scFirmarEnlace(PDO $pdo, int $usuarioId, string $tipo, string $valor, ?int $ttlDias = null): string {
    $carga = scBase64Url(json_encode([
        'u' => $usuarioId,
        't' => $tipo,
        'v' => $valor,
        'x' => time() + (($ttlDias ?? SC_FIRMA_TTL_DIAS) * 86400),
    ], JSON_UNESCAPED_UNICODE));

    $firma = scBase64Url(hash_hmac('sha256', $carga, scSecretoFirma($pdo), true));

    return $carga . '.' . $firma;
}

/**
 * Comprueba un token y devuelve su contenido, o null si no es de fiar.
 * Devuelve ['caducado' => true] cuando la firma es buena pero pasó la fecha,
 * para poder decírselo a la persona en vez de soltarle un error genérico.
 */
function scVerificarEnlace(PDO $pdo, ?string $token): ?array {
    if (!is_string($token) || $token === '') return null;

    $partes = explode('.', $token);
    if (count($partes) !== 2) return null;

    [$carga, $firma] = $partes;

    $esperada = scBase64Url(hash_hmac('sha256', $carga, scSecretoFirma($pdo), true));
    if (!hash_equals($esperada, $firma)) return null;

    $datos = json_decode(scBase64UrlDecode($carga), true);
    if (!is_array($datos) || !isset($datos['u'], $datos['t'], $datos['v'], $datos['x'])) return null;

    if ((int) $datos['x'] < time()) {
        return ['caducado' => true, 'usuario_id' => (int) $datos['u']];
    }

    return [
        'caducado'   => false,
        'usuario_id' => (int) $datos['u'],
        'tipo'       => (string) $datos['t'],
        'valor'      => (string) $datos['v'],
    ];
}

/** URL completa de un enlace de respuesta, lista para meter en el correo. */
function scUrlRespuesta(PDO $pdo, int $usuarioId, string $tipo, string $valor): string {
    return scUrlBase() . '/r.php?t=' . scFirmarEnlace($pdo, $usuarioId, $tipo, $valor);
}

/**
 * Folio de seguimiento de una cuenta.
 *
 * Es el número de usuario con formato, no un dato nuevo: así no hay una
 * secuencia más que mantener y el folio se puede calcular en cualquier sitio
 * sin consultar nada.
 */
function scFolio(int $usuarioId): string {
    return 'SC-' . str_pad((string) $usuarioId, 6, '0', STR_PAD_LEFT);
}

/** Al revés: del folio al número de usuario. 0 si el folio no tiene forma. */
function scFolioAId(string $folio): int {
    if (!preg_match('/^\s*(?:SC-)?0*(\d{1,9})\s*$/i', $folio, $m)) return 0;
    return (int) $m[1];
}

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

// ══════════════════════════════════════════════════════════════
//  CIFRADO DE LA CONTRASEÑA DEL BUZÓN
//
//  La contraseña del SMTP no se puede guardar como un hash: el servidor
//  tiene que poder leerla para autenticarse. Lo que sí se puede es que un
//  volcado de la base —una inyección, una copia de seguridad que se
//  escapa— no la entregue en claro. Por eso el texto cifrado vive en la
//  base y la llave vive en un archivo, fuera de ella.
//
//  Contra alguien que ya tiene acceso al servidor esto no protege, y no
//  pretende hacerlo: quien puede leer el archivo puede descifrarla.
// ══════════════════════════════════════════════════════════════

/**
 * Llave de cifrado. De SC_CLAVE_CIFRADO si está definida; si no, de un
 * archivo que se crea solo la primera vez.
 *
 * El archivo va en config/, que no se versiona y que el despliegue no pisa
 * porque no existe en el repositorio. Si se pierde, las contraseñas
 * guardadas dejan de poder leerse y hay que volver a escribirlas — que es
 * exactamente lo que debe pasar.
 */
function scClaveCifrado(): string {
    static $clave = null;
    if ($clave !== null) return $clave;

    if (defined('SC_CLAVE_CIFRADO') && SC_CLAVE_CIFRADO !== '') {
        return $clave = hash('sha256', SC_CLAVE_CIFRADO, true);
    }

    $ruta = __DIR__ . '/../config/.clave-correo';

    if (is_file($ruta)) {
        $guardada = trim((string) @file_get_contents($ruta));
        if ($guardada !== '') return $clave = hash('sha256', $guardada, true);
    }

    $nueva = bin2hex(random_bytes(32));
    if (@file_put_contents($ruta, $nueva, LOCK_EX) === false) {
        throw new RuntimeException('No se pudo crear la llave de cifrado en config/.');
    }
    @chmod($ruta, 0600);

    return $clave = hash('sha256', $nueva, true);
}

/** Cifra un texto. Devuelve base64 de iv + etiqueta + criptograma. */
function scCifrar(string $texto): string {
    if ($texto === '') return '';

    $iv  = random_bytes(12);                  // GCM usa 96 bits
    $tag = '';
    $cripto = openssl_encrypt($texto, 'aes-256-gcm', scClaveCifrado(),
                              OPENSSL_RAW_DATA, $iv, $tag);

    if ($cripto === false) throw new RuntimeException('No se pudo cifrar la contraseña.');

    return base64_encode($iv . $tag . $cripto);
}

/** Descifra lo que produjo scCifrar. Cadena vacía si no se puede. */
function scDescifrar(string $blob): string {
    if ($blob === '') return '';

    $crudo = base64_decode($blob, true);
    if ($crudo === false || strlen($crudo) < 29) return '';

    $iv     = substr($crudo, 0, 12);
    $tag    = substr($crudo, 12, 16);
    $cripto = substr($crudo, 28);

    // GCM comprueba la etiqueta: si la llave cambió o alguien tocó el
    // texto cifrado, esto devuelve false en vez de basura.
    $texto = openssl_decrypt($cripto, 'aes-256-gcm', scClaveCifrado(),
                             OPENSSL_RAW_DATA, $iv, $tag);

    return $texto === false ? '' : $texto;
}

// ══════════════════════════════════════════════════════════════
//  AJUSTES DEL CORREO SALIENTE
// ══════════════════════════════════════════════════════════════

/** Valores del correo saliente que se pueden guardar desde el panel. */
const SC_CLAVES_SMTP = ['host', 'usuario', 'password', 'puerto', 'seguridad', 'remitente', 'remitente_nombre'];

/**
 * Ajustes del correo saliente, ya resueltos.
 *
 * Manda lo que se haya guardado desde el panel; lo que falte se completa
 * con las constantes de config.php. Se hace en este orden para que quien
 * ya tenía el config lleno siga funcionando sin tocar nada, y para que
 * cambiar algo desde el panel no obligue a entrar por FTP.
 */
function scAjustesCorreo(bool $recargar = false): array {
    static $cache = null;
    if ($cache !== null && !$recargar) return $cache;

    // Punto de partida: config.php
    $ajustes = [
        'host'             => defined('SC_MAIL_HOST') ? (string) SC_MAIL_HOST : '',
        'usuario'          => defined('SC_MAIL_USER') ? (string) SC_MAIL_USER : '',
        'password'         => defined('SC_MAIL_PASS') ? (string) SC_MAIL_PASS : '',
        'puerto'           => defined('SC_MAIL_PORT') ? (int) SC_MAIL_PORT : 465,
        'seguridad'        => 'auto',
        'remitente'        => SC_MAIL_FROM,
        'remitente_nombre' => SC_MAIL_FROM_NOMBRE,
        'origen'           => 'config',
    ];

    // Encima, lo guardado desde el panel
    try {
        if (function_exists('scDB')) {
            $stmt = scDB()->query("SELECT clave, valor FROM sc_meta WHERE clave LIKE 'smtp\\_%'");
            foreach ($stmt->fetchAll() as $fila) {
                $campo = substr($fila['clave'], 5);
                if (!in_array($campo, SC_CLAVES_SMTP, true)) continue;

                $valor = $campo === 'password' ? scDescifrar($fila['valor']) : $fila['valor'];
                if ($valor === '') continue;

                $ajustes[$campo] = $campo === 'puerto' ? (int) $valor : $valor;
                $ajustes['origen'] = 'panel';
            }
        }
    } catch (Throwable $e) {
        // Si la base no responde, se sigue con lo de config.php: mejor
        // mandar el correo con la configuración vieja que no mandarlo.
        error_log('scAjustesCorreo: ' . $e->getMessage());
    }

    return $cache = $ajustes;
}

/**
 * ¿Está configurado el envío por SMTP?
 *
 * El sistema de certificaciones manda sus correos con PHPMailer por SMTP
 * autenticado, no con mail(). Un correo autenticado con SPF/DKIM del dominio
 * llega a la bandeja de entrada; uno de mail() acaba en spam con frecuencia.
 * Aquí se usa el mismo servidor: las credenciales se escriben en el panel de
 * administración, o se copian a las constantes SC_MAIL_* de config.php.
 */
function scSmtpConfigurado(): bool {
    $a = scAjustesCorreo();
    return $a['host'] !== '' && $a['usuario'] !== '' && $a['password'] !== '';
}

/**
 * Carga PHPMailer si está disponible.
 *
 * La librería la instala Composer en la raíz del hosting para el sistema de
 * certificaciones (`public_html/vendor/`). Se reutiliza en vez de duplicarla:
 * es solo lectura de una dependencia, no se toca nada de ese sistema. Si
 * alguien instala Composer dentro de socioscomerciales, esa copia tiene
 * preferencia.
 */
function scCargarPhpMailer(): bool {
    if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) return true;

    foreach ([__DIR__ . '/../vendor/autoload.php',
              __DIR__ . '/../../vendor/autoload.php'] as $ruta) {
        if (is_file($ruta)) {
            require_once $ruta;
            if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) return true;
        }
    }
    return false;
}

/**
 * Envía un correo del portal. Devuelve true si el servidor lo aceptó.
 * Nunca lanza excepción: si el correo falla, el registro debe continuar.
 *
 * Usa SMTP si está configurado; si no, cae a mail() para no dejar el portal
 * sin correos mientras se termina de configurar.
 */
function scEnviarCorreo(string $para, string $asunto, string $html): bool {
    if (scSmtpConfigurado() && scCargarPhpMailer()) {
        if (scEnviarPorSmtp($para, $asunto, $html)) return true;
        // Si el SMTP falla se intenta con mail() antes de darlo por perdido:
        // más vale que llegue por el camino malo que que no llegue.
        error_log('scEnviarCorreo: SMTP falló, se intenta con mail()');
    }

    $ajustes   = scAjustesCorreo();
    $remitente = $ajustes['remitente'];
    $nombre    = $ajustes['remitente_nombre'];

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
 * Cómo se cifra la conexión.
 *
 * En «auto» se deduce del puerto, que es lo que acierta casi siempre: 465
 * es SMTPS (TLS desde el saludo) y 587 es STARTTLS. Se deja elegir a mano
 * porque algunos servidores usan puertos raros y entonces adivinar falla.
 */
function scCifradoSmtp(string $seguridad, int $puerto): string {
    $ssl   = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
    $tls   = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;

    switch ($seguridad) {
        case 'ssl':     return $ssl;
        case 'tls':     return $tls;
        case 'ninguna': return '';
        default:        return $puerto === 465 ? $ssl : $tls;
    }
}

/**
 * Envío por SMTP con PHPMailer.
 *
 * La conexión se reutiliza entre llamadas (SMTPKeepAlive): en un envío masivo,
 * abrir y cerrar la sesión SMTP en cada correo multiplica por varias veces el
 * tiempo total y algunos servidores lo toman por abuso.
 */
function scEnviarPorSmtp(string $para, string $asunto, string $html): bool {
    static $mail  = null;
    static $huella = '';

    $ajustes = scAjustesCorreo();

    // Si los ajustes cambiaron desde el panel, la conexión guardada ya no
    // vale: seguiría autenticada contra el servidor viejo.
    $actual = md5(serialize($ajustes));
    if ($actual !== $huella) { $mail = null; $huella = $actual; }

    try {
        if ($mail === null) {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = $ajustes['host'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $ajustes['usuario'];
            $mail->Password   = $ajustes['password'];
            $mail->Port       = (int) $ajustes['puerto'];
            $mail->CharSet    = 'UTF-8';
            $mail->SMTPKeepAlive = true;
            $mail->SMTPSecure = scCifradoSmtp($ajustes['seguridad'], (int) $ajustes['puerto']);

            $mail->setFrom($ajustes['remitente'], $ajustes['remitente_nombre']);
            $mail->isHTML(true);
        }

        $mail->clearAllRecipients();
        $mail->addAddress($para);
        $mail->Subject = $asunto;
        $mail->Body    = $html;

        // Versión en texto plano. No es un adorno: un correo que solo lleva
        // HTML puntúa peor en los filtros antispam, y es lo único que ven los
        // clientes con las imágenes y el HTML desactivados.
        $mail->AltBody = $mail->html2text($html, false);

        return $mail->send();
    } catch (Throwable $e) {
        error_log('scEnviarPorSmtp: ' . $e->getMessage());
        // Una conexión rota no se arregla sola: se descarta para que el
        // siguiente intento abra una nueva.
        $mail = null;
        return false;
    }
}

// ══════════════════════════════════════════════════════════════
//  BLOQUES DE CORREO
//
//  Todo va en tablas con estilos en línea. No es descuido: Outlook sigue
//  maquetando con el motor de Word, que ignora flexbox, grid y casi
//  cualquier hoja de estilo, así que lo que aquí parece anticuado es lo
//  único que se ve igual en todas partes.
// ══════════════════════════════════════════════════════════════

/**
 * Fecha en castellano, sin depender de la configuración regional.
 *
 * strftime está obsoleto desde PHP 8.1 e IntlDateFormatter necesita la
 * extensión intl, que en hosting compartido no siempre está. Con dos
 * arreglos se acaba el problema.
 */
function scFechaLargaEs(string $fecha): string {
    $dias  = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
    $meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio',
              'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    $t = strtotime($fecha);
    if ($t === false) return $fecha;

    return $dias[(int) date('w', $t)] . ' ' . (int) date('j', $t) . ' de '
         . $meses[(int) date('n', $t) - 1] . ' a las ' . date('H:i', $t);
}

/** Versión corta, para botones donde no cabe la larga. */
function scFechaCortaEs(string $fecha): string {
    $dias = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];
    $t = strtotime($fecha);
    if ($t === false) return $fecha;

    return $dias[(int) date('w', $t)] . ' ' . (int) date('j', $t) . ' · ' . date('H:i', $t);
}

/** Una sección del correo con su rótulo y un separador arriba. */
function scCorreoSeccion(string $rotulo, string $titulo, string $contenido, string $pie = ''): string {
    return '
    <tr><td style="padding:6px 32px 0">
      <div style="border-top:1px solid #DBE3EE;padding-top:22px">
        <div style="font-size:11px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:#8792A8;padding-bottom:6px">'
          . htmlspecialchars($rotulo) . '</div>'
        . ($titulo !== '' ? '<div style="font-size:16.5px;font-weight:700;color:#1A2A44;padding-bottom:14px">'
            . htmlspecialchars($titulo) . '</div>' : '')
        . $contenido
        . ($pie !== '' ? '<div style="font-size:12.5px;color:#8792A8;padding-top:10px">' . htmlspecialchars($pie) . '</div>' : '')
      . '</div>
    </td></tr>';
}

/**
 * Botones de respuesta, uno por renglón.
 * Cada uno es un enlace normal: se contesta desde el propio correo.
 */
function scCorreoBotones(array $opciones): string {
    $filas = '';
    foreach ($opciones as $o) {
        $filas .= '
        <tr><td style="padding-bottom:8px">
          <a href="' . htmlspecialchars($o['url'], ENT_QUOTES) . '"
             style="display:block;border:1.5px solid #DBE3EE;border-radius:8px;padding:12px 16px;
                    font-size:14.5px;font-weight:600;color:#1A2A44;text-decoration:none;background:#ffffff">'
            . htmlspecialchars($o['texto']) . '</a>
        </td></tr>';
    }
    return '<table width="100%" cellpadding="0" cellspacing="0">' . $filas . '</table>';
}

/** Opciones en línea, para cuando se pueden marcar varias. */
function scCorreoChips(array $opciones): string {
    $chips = '';
    foreach ($opciones as $o) {
        $chips .= '<a href="' . htmlspecialchars($o['url'], ENT_QUOTES) . '"
             style="display:inline-block;border:1.5px solid #DBE3EE;border-radius:18px;
                    padding:7px 13px;margin:0 5px 7px 0;font-size:13px;font-weight:600;
                    color:#1A2A44;text-decoration:none;background:#ffffff">'
            . htmlspecialchars($o['texto']) . '</a> ';
    }
    return '<div>' . $chips . '</div>';
}

/** Barra de avance del perfil y lo que falta. */
function scCorreoAvance(int $porcentaje, array $faltantes, string $urlPerfil): string {
    $porcentaje = max(0, min(100, $porcentaje));

    // La barra son dos tablas anidadas porque un div con width en % dentro de
    // otro div no sobrevive a Outlook.
    $barra = '
    <table width="100%" cellpadding="0" cellspacing="0" style="background:#F4F7FB;border-radius:6px;height:10px">
      <tr><td>
        <table width="' . $porcentaje . '%" cellpadding="0" cellspacing="0" style="background:#185FA5;border-radius:6px;height:10px">
          <tr><td style="font-size:0;line-height:10px">&nbsp;</td></tr>
        </table>
      </td></tr>
    </table>
    <div style="font-size:12.5px;font-weight:700;color:#185FA5;padding-top:7px">' . $porcentaje . ' % completo</div>';

    $lista = '';
    foreach (array_slice($faltantes, 0, 3) as $f) {
        $lista .= '<div style="font-size:13.5px;color:#566079;padding:3px 0 3px 14px">• '
                . htmlspecialchars($f['texto']) . '</div>';
    }

    return $barra
         . '<div style="padding-top:10px">' . $lista . '</div>'
         . '<div style="padding-top:12px">'
         . '<a href="' . htmlspecialchars($urlPerfil, ENT_QUOTES) . '"
              style="display:inline-block;background:#185FA5;color:#ffffff;text-decoration:none;
                     font-weight:700;font-size:14px;padding:10px 20px;border-radius:8px">Completar mi perfil</a>'
         . '</div>';
}

/** Tarjetas de vacantes abiertas. */
function scCorreoVacantes(array $vacantes, string $urlBase): string {
    $modos = ['presencial' => 'Presencial', 'remoto' => 'Remoto', 'hibrido' => 'Híbrido'];
    $filas = '';

    foreach ($vacantes as $v) {
        $meta = array_filter([
            $v['empresa'] ?? '',
            $v['ubicacion'] ?? '',
            $modos[$v['modalidad'] ?? ''] ?? '',
            $v['salario'] ?? '',
        ]);

        $filas .= '
        <tr><td style="padding-bottom:9px">
          <a href="' . htmlspecialchars($urlBase . '/vacantes.html?id=' . (int) $v['id'], ENT_QUOTES) . '"
             style="display:block;border:1px solid #DBE3EE;border-radius:10px;padding:13px 15px;text-decoration:none;background:#ffffff">
            <div style="font-size:14.5px;font-weight:700;color:#185FA5;padding-bottom:3px">'
              . htmlspecialchars($v['titulo']) . '</div>
            <div style="font-size:12.5px;color:#566079">' . htmlspecialchars(implode(' · ', $meta)) . '</div>
          </a>
        </td></tr>';
    }

    return '<table width="100%" cellpadding="0" cellspacing="0">' . $filas . '</table>';
}

/** Botón verde de WhatsApp con el mensaje ya escrito. */
function scCorreoWhatsApp(string $numero, string $nombre, string $folio): string {
    $numero = preg_replace('/\D+/', '', $numero);
    if ($numero === '') return '';

    $texto = "Hola, soy {$nombre} (folio {$folio}) y escribo por mi registro en Socios Comerciales AVBA.";
    $url   = 'https://wa.me/' . $numero . '?text=' . rawurlencode($texto);

    return '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '"
        style="display:inline-block;background:#25D366;color:#ffffff;text-decoration:none;
               font-weight:700;font-size:14.5px;padding:12px 22px;border-radius:8px">
        Escribirnos por WhatsApp</a>';
}

/**
 * Plantilla HTML de correo con la identidad AVBA.
 *
 * $bloquesHtml son las secciones extra (preguntas, avance, vacantes...) que
 * van después del mensaje y del botón principal.
 */
function scPlantillaCorreo(string $titulo, string $cuerpoHtml, string $textoBoton = '', string $urlBoton = '', string $bloquesHtml = ''): string {
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
    . $boton
    . $bloquesHtml
    . ($bloquesHtml !== '' ? '<tr><td style="height:26px;font-size:0">&nbsp;</td></tr>' : '') .
    '<tr><td style="padding:20px 32px;background:#F4F7FB;font-size:11.5px;color:#8792a8">
      AVBA Inspections, Certifications and Maintenance S.A.S. de C.V. — avba.com.mx<br>
      Si no solicitaste este correo, puedes ignorarlo.
    </td></tr>
  </table>
</td></tr></table></body></html>';
}
