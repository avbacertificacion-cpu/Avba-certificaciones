<?php
/**
 * AVBA Certificaciones — Funciones de apoyo
 */

/**
 * Comprime y redimensiona una imagen subida para ahorrar almacenamiento.
 * Redimensiona el lado mayor a $maxW/$maxH (manteniendo proporción) y la
 * guarda como JPEG (o PNG si tiene transparencia) con la calidad indicada.
 * Requiere la extensión GD.
 *
 * La extensión de $dest es solo una sugerencia: si el contenido resulta
 * tener transparencia se fuerza a .png (y a .jpg en caso contrario) para
 * que el archivo en disco siempre coincida con sus bytes reales — de lo
 * contrario un .jpg/.webp puede terminar con bytes PNG adentro, lo que
 * rompe su MIME type al servirlo o embeberlo en un PDF.
 *
 * @param string $src    Ruta del archivo origen (tmp_name)
 * @param string $dest   Ruta destino sugerida (se ajusta la extensión real)
 * @param int    $maxW   Ancho máximo
 * @param int    $maxH   Alto máximo
 * @param int    $calidad Calidad JPEG (0-100)
 * @return string|false  Nombre de archivo final (basename, con la extensión
 *                        real) en éxito, o false si no se pudo procesar.
 */
function comprimirImagen(string $src, string $dest, int $maxW = 1280, int $maxH = 1280, int $calidad = 72) {
    if (!function_exists('imagecreatefromstring') || !function_exists('getimagesize')) return false;
    $info = @getimagesize($src);
    if (!$info) return false;
    [$w, $h] = $info;
    $type = $info[2] ?? IMAGETYPE_JPEG;

    $data = @file_get_contents($src);
    if ($data === false) return false;
    $img = @imagecreatefromstring($data);
    if (!$img) return false;

    // Escala (solo si excede el máximo)
    $ratio = min($maxW / max(1, $w), $maxH / max(1, $h), 1);
    $nw = max(1, (int)round($w * $ratio));
    $nh = max(1, (int)round($h * $ratio));

    $hasAlpha = in_array($type, [IMAGETYPE_PNG, IMAGETYPE_WEBP], true);

    if ($nw !== $w || $nh !== $h) {
        $dst = imagecreatetruecolor($nw, $nh);
        if ($hasAlpha) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefilledrectangle($dst, 0, 0, $nw, $nh, $transparent);
        }
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);
        $img = $dst;
    }

    $sinExt = preg_replace('/\.[a-zA-Z0-9]+$/', '', $dest);
    $ok = false;
    $destFinal = $dest;
    try {
        if ($hasAlpha && function_exists('imagepng')) {
            // Conserva transparencia (logos). PNG comprimido nivel 8.
            $destFinal = $sinExt . '.png';
            $ok = imagepng($img, $destFinal, 8);
        } else {
            $destFinal = $sinExt . '.jpg';
            $ok = imagejpeg($img, $destFinal, max(40, min(95, $calidad)));
        }
    } catch (\Throwable $e) {
        $ok = false;
    }
    imagedestroy($img);
    return $ok ? basename($destFinal) : false;
}

/**
 * Valida la CURP: formato + dígito verificador matemático (algoritmo RENAPO).
 * @return array ['valida' => bool, 'error' => string|null]
 */
function validarCURPCompleta(string $curp): array {
    $curp = strtoupper(trim($curp));

    if (strlen($curp) !== 18) {
        return ['valida' => false, 'error' => 'La CURP debe tener exactamente 18 caracteres.'];
    }
    if (!preg_match('/^[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z0-9]\d$/', $curp)) {
        return ['valida' => false, 'error' => 'El formato de la CURP no es válido.'];
    }

    $tabla  = '0123456789ABCDEFGHIJKLMNÑOPQRSTUVWXYZ';
    $chars  = preg_split('//u', $tabla, -1, PREG_SPLIT_NO_EMPTY);
    $mapa   = array_flip($chars);

    $curpChars = preg_split('//u', $curp, -1, PREG_SPLIT_NO_EMPTY);
    $suma = 0;
    for ($i = 0; $i < 17; $i++) {
        $val = $mapa[$curpChars[$i]] ?? -1;
        if ($val < 0) return ['valida' => false, 'error' => 'Carácter no reconocido en la CURP.'];
        $suma += $val * (18 - $i);
    }
    $digitoEsperado = (10 - ($suma % 10)) % 10;
    $digitoReal     = (int)$curpChars[17];

    if ($digitoEsperado !== $digitoReal) {
        return ['valida' => false, 'error' => "CURP inválida: dígito verificador incorrecto (esperado {$digitoEsperado})."];
    }
    return ['valida' => true, 'error' => null];
}

/**
 * Genera un token seguro de 64 caracteres hex.
 */
function generarToken(): string {
    return bin2hex(random_bytes(32));
}

/**
 * Prefijo reservado para los folios sin empresa. Los clientes reales arrancan
 * en 45155, así que 00000 no colisiona con ninguno.
 */
const CONTROL_PREFIJO_SIN_EMPRESA = '00000';

/**
 * Formatea el folio para mostrar en documentos.
 * "24568-45698" → "AB.24568-45698-2026MX"
 * La BD guarda el valor sin formato.
 */
function formatoFolio(string $control): string {
    if (!$control) return '';
    return 'AB.' . $control . '-' . date('Y') . 'MX';
}

/**
 * Genera el folio de control (NNNNN-NNNNN).
 * Crea el cliente si no existe.
 */
function generarControl(PDO $pdo, string $nombreCliente): string {
    $nombreCliente = trim($nombreCliente);

    // Buscar cliente existente (case-insensitive)
    $stmt = $pdo->prepare("SELECT primera_parte FROM clientes WHERE UPPER(TRIM(nombre_cliente)) = UPPER(TRIM(?))");
    $stmt->execute([$nombreCliente]);
    $row = $stmt->fetch();

    if ($row) {
        $numA = str_pad($row['primera_parte'], 5, '0', STR_PAD_LEFT);
    } else {
        // Obtener el siguiente ID global de cliente
        $stmt = $pdo->query("SELECT MAX(CAST(primera_parte AS UNSIGNED)) AS max_id FROM clientes");
        $r = $stmt->fetch();
        $newId = max(($r['max_id'] ?? 45154), 45154) + 1;
        $numA  = str_pad($newId, 5, '0', STR_PAD_LEFT);

        $ins = $pdo->prepare("INSERT INTO clientes (nombre_cliente, primera_parte) VALUES (?, ?)");
        $ins->execute([$nombreCliente, $newId]);
    }

    return "{$numA}-" . consecutivoControl($pdo);
}

/**
 * Segunda mitad del folio: consecutivo global, el máximo entre todas las
 * tablas que llevan número de control.
 */
function consecutivoControl(PDO $pdo): string {
    $maxB = 0;
    foreach (['equipos', 'accesorios_sesiones', 'participantes_cursos', 'pnd_inspecciones'] as $tabla) {
        try {
            $r = $pdo->query(
                "SELECT MAX(CAST(SUBSTRING_INDEX(control,'-',-1) AS UNSIGNED)) AS max_b
                 FROM `{$tabla}` WHERE control LIKE '%-%'"
            )->fetch();
            $maxB = max($maxB, (int)($r['max_b'] ?? 0));
        } catch (\PDOException $e) { /* tabla o columna aún no existe */ }
    }
    return str_pad($maxB + 1, 5, '0', STR_PAD_LEFT);
}

/**
 * Folio para un registro que no pertenece a ninguna empresa (por ejemplo, una
 * persona que toma un curso por su cuenta). Usa un prefijo reservado y NO da
 * de alta un cliente: si no, cada participante independiente acabaría en el
 * catálogo de empresas y saldría en los autocompletados de "nombre de la
 * empresa".
 */
function controlSinCliente(PDO $pdo): string {
    return CONTROL_PREFIJO_SIN_EMPRESA . '-' . consecutivoControl($pdo);
}

/**
 * Series de códigos QR por tipo de registro: el primer dígito identifica a qué
 * corresponde la etiqueta. Los equipos (maquinaria, accesorios de izaje y
 * equipo contra caídas) usan la serie 4; el personal de cursos, la 7.
 */
const QR_PREFIJO_EQUIPO   = '4';
const QR_PREFIJO_PERSONAL = '7';

/** ¿El código pertenece a la serie indicada? */
function qrEsDeSerie(string $qr, string $prefijo): bool {
    $qr = trim($qr);
    return $qr !== '' && $qr[0] === $prefijo;
}

/** Nombre legible de una serie, para los mensajes de error. */
function qrNombreSerie(string $prefijo): string {
    return $prefijo === QR_PREFIJO_PERSONAL ? 'personal' : 'equipo';
}

/**
 * Siguiente código libre de una serie. Primero se reaprovecha el más bajo sin
 * usar del banco; si la serie está agotada, se continúa a partir del mayor
 * emitido. Devuelve siempre 10 dígitos empezando por el prefijo.
 */
function siguienteQrSerie(PDO $pdo, string $prefijo): string {
    $like = $prefijo . '%';

    try {
        $st = $pdo->prepare(
            "SELECT identificador FROM qr_codigos
             WHERE usado = 0 AND identificador LIKE ? AND LENGTH(identificador) = 10
             ORDER BY CAST(identificador AS UNSIGNED) LIMIT 1"
        );
        $st->execute([$like]);
        $libre = $st->fetchColumn();
        if ($libre) return (string)$libre;
    } catch (\Throwable $e) { /* el banco aún no existe */ }

    // Serie sin códigos libres: continuar desde el mayor ya emitido en ella.
    $max = (int)($prefijo . '000000000');
    $fuentes = [
        ['qr_codigos',           'identificador'],
        ['equipos',              'qr_codigo'],
        ['participantes_cursos', 'qr_codigo'],
        ['accesorios_sesiones',  'qr_codigo'],
        ['accesorios_izaje',     'qr_codigo'],
        ['arneses_sesiones',     'qr_codigo'],
        ['arneses_items',        'qr_codigo'],
    ];
    foreach ($fuentes as [$tabla, $col]) {
        try {
            $st = $pdo->prepare(
                "SELECT MAX(CAST(`$col` AS UNSIGNED)) FROM `$tabla`
                 WHERE `$col` LIKE ? AND LENGTH(`$col`) = 10"
            );
            $st->execute([$like]);
            $max = max($max, (int)$st->fetchColumn());
        } catch (\Throwable $e) { /* tabla o columna aún no existe */ }
    }
    return str_pad((string)($max + 1), 10, '0', STR_PAD_LEFT);
}

/**
 * Genera un código QR de 10 dígitos desde el catálogo o uno nuevo.
 */
function generarCodigoQR(PDO $pdo): string {
    $stmt = $pdo->query("SELECT identificador FROM qr_codigos WHERE usado = 0 LIMIT 1");
    $row  = $stmt->fetch();
    if ($row) {
        $pdo->prepare("UPDATE qr_codigos SET usado = 1 WHERE identificador = ?")->execute([$row['identificador']]);
        return $row['identificador'];
    }
    // Si no hay en catálogo, generar uno nuevo
    return str_pad((string) random_int(1000000000, 9999999999), 10, '0', STR_PAD_LEFT);
}

/**
 * Genera un código QR en PNG con un generador interno (sin depender de un
 * servicio externo como quickchart.io) y devuelve los bytes del archivo.
 * $sizeTotal es el ancho/alto total deseado en px; se calcula el tamaño por
 * módulo del QR para acercarse a esa medida (mínimo 2 px/módulo, para que
 * siga siendo legible).
 */
function qrPngBytes(string $texto, int $sizeTotal = 300, int $margin = 8): string {
    if ($texto === '') return '';
    if (!class_exists('QRCode')) {
        require_once __DIR__ . '/lib/qrcode.php';
    }
    try {
        $qr = QRCode::getMinimumQRCode($texto, QR_ERROR_CORRECT_LEVEL_M);
        $moduleCount = $qr->getModuleCount();
        $px  = max(2, (int)round(($sizeTotal - $margin * 2) / max(1, $moduleCount)));
        $img = $qr->createImage($px, $margin);
        ob_start();
        imagepng($img);
        $bytes = (string)ob_get_clean();
        imagedestroy($img);
        return $bytes;
    } catch (\Throwable $e) {
        error_log('[qrPngBytes] ' . $e->getMessage());
        return '';
    }
}

/** Igual que qrPngBytes() pero como data URI, lista para <img src="..."> o para incrustar en un PDF sin descargas por HTTP. */
function qrDataUri(string $texto, int $sizeTotal = 300, int $margin = 8): string {
    $bytes = qrPngBytes($texto, $sizeTotal, $margin);
    return $bytes !== '' ? ('data:image/png;base64,' . base64_encode($bytes)) : '';
}

/** Texto que codifica el QR de validación de un folio/código de certificado. */
function textoQR(string $codigo): string {
    if (!$codigo) return '';
    return rtrim(SITE_URL, '/') . '/validar.html?qr=' . urlencode($codigo);
}

/** Bytes PNG del QR de validación de un folio/código — para incrustar directo en PDFs (sin red). */
function qrCodigoPngBytes(string $codigo, int $sizeTotal = 300, int $margin = 8): string {
    return qrPngBytes(textoQR($codigo), $sizeTotal, $margin);
}

/**
 * Construye la URL del QR usando nuestro generador interno (qr.php), para
 * usarse en <img src> del lado del navegador. Antes apuntaba a quickchart.io
 * (servicio externo) — se quitó esa dependencia para no depender de la
 * disponibilidad ni de la red de un tercero.
 */
function urlQR(string $codigo): string {
    $texto = textoQR($codigo);
    if (!$texto) return '';
    return rtrim(SITE_URL, '/') . '/qr.php?text=' . urlencode($texto) . '&size=300';
}

/**
 * Registra en el banco (qr_codigos) un código QR capturado manualmente por
 * Calidad y lo marca como usado. Si el código no existía en el banco, lo
 * inserta; de esta forma el código captado queda registrado (y no se vuelve a
 * asignar a otro registro) SIN exigir que haya sido pre-generado.
 *
 * @param int|null $equipoId  ID del equipo que lo usa (para el catálogo de equipos).
 */
function qrRegistrarUsado(PDO $pdo, string $qr, ?int $equipoId = null): void {
    $qr = trim($qr);
    if ($qr === '') return;
    try {
        $pdo->prepare(
            "INSERT INTO qr_codigos (identificador, usado, equipo_id)
             VALUES (?, 1, ?)
             ON DUPLICATE KEY UPDATE usado = 1, equipo_id = VALUES(equipo_id)"
        )->execute([$qr, $equipoId]);
    } catch (\Throwable $e) {
        error_log('[qrRegistrarUsado] ' . $e->getMessage());
    }
}

/**
 * Formatea una fecha a dd/MM/yyyy.
 */
function formatFecha(?string $fecha): string {
    if (!$fecha) return '';
    try {
        $d = new DateTime($fecha);
        return $d->format('d/m/Y');
    } catch (Exception $e) {
        return $fecha;
    }
}

/**
 * Calcula días restantes hasta la fecha de vencimiento (1 año desde $fechaCert).
 * Devuelve [dias, vencimiento_str, vigente]
 */
function calcularVigencia(?string $fechaCert): array {
    if (!$fechaCert) return ['dias' => null, 'vencimiento' => '', 'vigente' => false];
    try {
        $f   = new DateTime($fechaCert);
        $venc = clone $f;
        $venc->modify('+1 year');
        $hoy = new DateTime('today');
        $diff = (int) $hoy->diff($venc)->format('%r%a');
        return [
            'dias'       => $diff,
            'vencimiento'=> $venc->format('d/m/Y'),
            'vigente'    => $diff >= 0,
        ];
    } catch (Exception $e) {
        return ['dias' => null, 'vencimiento' => '', 'vigente' => false];
    }
}

/**
 * Valida el token de sesión enviado en cada request.
 * Busca en: Authorization header o campo "token" del body/query.
 */
function validarToken(PDO $pdo, ?string $token): ?array {
    if (!$token) return null;

    try {
        $stmt = $pdo->prepare(
            "SELECT id, usuario, rol, nombre, id_cliente, usuario_padre_id, permiso_sub, permiso_mantenimiento, permiso_rh
             FROM usuarios
             WHERE session_token = ? AND activo = 1 AND token_expires > NOW()"
        );
        $stmt->execute([$token]);
    } catch (\PDOException $e) {
        // Compatibilidad: columnas de sub-usuario aún no migradas
        $stmt = $pdo->prepare(
            "SELECT id, usuario, rol, nombre, id_cliente
             FROM usuarios
             WHERE session_token = ? AND activo = 1 AND token_expires > NOW()"
        );
        $stmt->execute([$token]);
    }
    return $stmt->fetch() ?: null;
}

/**
 * Registra un cambio en el historial_general.
 */
function registrarHistorial(PDO $pdo, string $usuario, ?int $equipoId, string $campo, ?string $anterior, ?string $nuevo, string $accion = 'UPDATE'): void {
    $stmt = $pdo->prepare(
        "INSERT INTO historial_general (usuario, equipo_id, campo, valor_anterior, valor_nuevo, accion)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([$usuario, $equipoId, $campo, $anterior, $nuevo, $accion]);
}

/**
 * Corrige UTF-8 doblemente codificado que ocurre cuando datos se insertaron
 * con una conexión latin1 hacia columnas utf8/utf8mb4.
 * Ejemplo: "JuÃ¡rez" → "Juárez"
 * Si el string ya es UTF-8 correcto, lo deja intacto.
 */
function fixEncoding(mixed $v): mixed {
    if (is_string($v)) {
        // Solo strings con bytes multi-byte (≥ 0x80)
        if (!preg_match('/[\x80-\xff]/', $v)) return $v;
        // Convertir cada carácter UTF-8 a su equivalente ISO-8859-1
        $decoded = mb_convert_encoding($v, 'ISO-8859-1', 'UTF-8');
        // Si el resultado es diferente Y es UTF-8 válido, era doble-codificado
        if ($decoded !== $v && mb_check_encoding($decoded, 'UTF-8')) {
            return $decoded;
        }
        return $v;
    }
    if (is_array($v)) {
        foreach ($v as $k => $item) {
            $v[$k] = fixEncoding($item);
        }
        return $v;
    }
    return $v;
}

/**
 * Detecta el error de compresión de FPDI y devuelve un mensaje amigable.
 * Retorna null si el error es de otro tipo.
 */
function fpdiMsgCompresion(\Exception $e): ?string {
    if (str_contains($e->getMessage(), 'compression technique')) {
        return 'La plantilla PDF usa compresión no compatible (PDF 1.5+). '
             . 'Re-guárdala como PDF 1.4: en Adobe Acrobat → Guardar como → '
             . 'Compatibilidad: Acrobat 5 (PDF 1.4); o usa LibreOffice '
             . '→ Exportar como PDF → Versión PDF 1.4.';
    }
    return null;
}

/**
 * Convierte un string UTF-8 a ISO-8859-1 para FPDF (que es Latin-1).
 * Aplica fixEncoding primero por si el dato viene doble-codificado de la BD.
 */
function fpdfStr(string $s): string {
    return mb_convert_encoding(fixEncoding($s), 'ISO-8859-1', 'UTF-8');
}

/**
 * Escribe texto en el PDF con ajuste de línea automático si no cabe en el ancho disponible.
 * Llama a SetXY internamente; SetFont debe haberse llamado antes.
 *
 * @param mixed $pdf   Instancia de FPDF/FPDI
 * @param float $x     Posición X en mm
 * @param float $y     Posición Y en mm
 * @param float $ancho Ancho de la celda (0 = resto de la página)
 * @param int   $tamano Tamaño de fuente en puntos (para calcular alto de línea)
 * @param string $text Texto ya convertido a ISO-8859-1 (usar fpdfStr() antes)
 */
function pdfCell($pdf, float $x, float $y, float $ancho, int $tamano, string $text): void {
    $avail = $ancho > 0 ? $ancho : ($pdf->GetPageWidth() - $x - 5);
    $pdf->SetXY($x, $y);
    if ($pdf->GetStringWidth($text) > $avail + 0.5) {
        $lineH = max(3.5, round($tamano * 0.4, 1));
        $pdf->MultiCell($avail, $lineH, $text, 0, '');
    } else {
        $pdf->Cell($ancho ?: 0, 0, $text, 0, 0, '');
    }
}

/**
 * Convierte URLs http:// a protocol-relative // para evitar mixed-content
 * cuando el frontend se sirve sobre HTTPS pero UPLOAD_URL usa HTTP.
 */
function normalizarUrlsRespuesta(array $data): array {
    foreach ($data as $k => $v) {
        if (is_string($v) && str_starts_with($v, 'http://')) {
            $key = (string)$k;
            if ($key === 'url' || str_ends_with($key, '_url') || str_ends_with($key, 'Url')
                || str_ends_with($key, '_pdf') || str_ends_with($key, '_imagen')
                || str_ends_with($key, '_img') || str_ends_with($key, 'Path')) {
                $data[$k] = '//' . substr($v, 7);
            }
        } elseif (is_array($v)) {
            $data[$k] = normalizarUrlsRespuesta($v);
        }
    }
    return $data;
}

/**
 * Devuelve una respuesta JSON y termina la ejecución.
 */
function respuesta(array $data, int $httpCode = 200): void {
    http_response_code($httpCode);
    echo json_encode(fixEncoding(normalizarUrlsRespuesta($data)), JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Lee la configuración SMTP desde la BD (tabla avba_smtp_config).
 * Si no existe o está vacía, cae en las constantes de config.php.
 */
function getSmtpConfig(PDO $pdo): array {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS avba_smtp_config (
            id          TINYINT UNSIGNED NOT NULL DEFAULT 1,
            host        VARCHAR(200)     NULL,
            port        SMALLINT         NOT NULL DEFAULT 465,
            encryption  VARCHAR(10)      NOT NULL DEFAULT 'ssl',
            username    VARCHAR(200)     NULL,
            password    VARCHAR(500)     NULL,
            from_email  VARCHAR(200)     NULL,
            from_name   VARCHAR(200)     NULL DEFAULT 'AVBA Certificaciones',
            firma_nombre  VARCHAR(300) NULL DEFAULT 'AVBA Inspections, Certifications and Maintenance S.A.S. de C.V.',
            firma_web     VARCHAR(200) NULL DEFAULT 'avba.com.mx',
            firma_extra   VARCHAR(500) NULL,
            asunto_cert   VARCHAR(300) NULL,
            cuerpo_intro  TEXT NULL,
            firma_html    TEXT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        foreach (['firma_nombre' => "ALTER TABLE avba_smtp_config ADD COLUMN firma_nombre VARCHAR(300) NULL DEFAULT 'AVBA Inspections, Certifications and Maintenance S.A.S. de C.V.'",
                  'firma_web'    => "ALTER TABLE avba_smtp_config ADD COLUMN firma_web    VARCHAR(200) NULL DEFAULT 'avba.com.mx'",
                  'firma_extra'  => "ALTER TABLE avba_smtp_config ADD COLUMN firma_extra  VARCHAR(500) NULL",
                  'asunto_cert'  => "ALTER TABLE avba_smtp_config ADD COLUMN asunto_cert  VARCHAR(300) NULL",
                  'cuerpo_intro' => "ALTER TABLE avba_smtp_config ADD COLUMN cuerpo_intro TEXT NULL",
                  'firma_html'   => "ALTER TABLE avba_smtp_config ADD COLUMN firma_html   TEXT NULL"] as $col => $ddl) {
            $exists = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'avba_smtp_config' AND COLUMN_NAME = '{$col}'")->fetchColumn();
            if (!$exists) { try { $pdo->exec($ddl); } catch (\PDOException $e) {} }
        }
        $pdo->exec("INSERT IGNORE INTO avba_smtp_config (id) VALUES (1)");
        $row = $pdo->query("SELECT * FROM avba_smtp_config WHERE id = 1")->fetch();
        if ($row && !empty($row['host']) && !empty($row['username'])) {
            return (array) $row;
        }
    } catch (\PDOException $e) {}

    return [
        'host'       => defined('MAIL_HOST')      ? MAIL_HOST      : '',
        'port'       => defined('MAIL_PORT')       ? (int)MAIL_PORT : 465,
        'encryption' => 'ssl',
        'username'   => defined('MAIL_USER')       ? MAIL_USER      : '',
        'password'   => defined('MAIL_PASS')       ? MAIL_PASS      : '',
        'from_email' => defined('MAIL_FROM')       ? MAIL_FROM      : '',
        'from_name'  => defined('MAIL_FROM_NAME')  ? MAIL_FROM_NAME : 'AVBA Certificaciones',
    ];
}

/**
 * Configura un objeto PHPMailer con los datos de getSmtpConfig().
 * Aplica host, puerto, autenticación, cifrado y remitente.
 */
function configurarMailer(object $mail, PDO $pdo): void {
    $cfg = getSmtpConfig($pdo);
    $mail->isSMTP();
    $mail->Host     = $cfg['host'];
    $mail->SMTPAuth = true;
    $mail->Username = $cfg['username'];
    $mail->Password = $cfg['password'];
    $mail->Port     = (int)($cfg['port'] ?? 465);
    $mail->CharSet  = 'UTF-8';
    $enc = strtolower($cfg['encryption'] ?? 'ssl');
    if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        $mail->SMTPSecure = ($enc === 'tls')
            ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS
            : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
    } else {
        $mail->SMTPSecure = ($enc === 'tls') ? 'tls' : 'ssl';
    }
    $fromEmail = !empty($cfg['from_email']) ? $cfg['from_email'] : $cfg['username'];
    $fromName  = !empty($cfg['from_name'])  ? $cfg['from_name']  : 'AVBA Certificaciones';
    $mail->setFrom($fromEmail, $fromName);
}

/**
 * Configura PHPMailer con el correo de envío PROPIO del cliente (cliente_config).
 * Si el cliente tiene SMTP completo (host+user+pass), envía desde su servidor.
 * Si no, cae al SMTP de AVBA pero pone Reply-To al correo del cliente.
 * @param array $cc  Fila de cliente_config (obtenerRaw)
 * @return string    Descripción del modo usado (para logs)
 */
function configurarMailerCliente(object $mail, PDO $pdo, array $cc): string {
    $from  = trim($cc['mail_from'] ?? '');
    $fromN = trim($cc['mail_from_name'] ?? '') ?: ($from ?: 'Cliente AVBA');
    $host  = trim($cc['mail_host'] ?? '');
    $user  = trim($cc['mail_user'] ?? '');
    $pass  = (string)($cc['mail_pass'] ?? '');

    if ($host && $user && $pass !== '') {
        $mail->isSMTP();
        $mail->Host     = $host;
        $mail->SMTPAuth = true;
        $mail->Username = $user;
        $mail->Password = $pass;
        $mail->Port     = (int)($cc['mail_port'] ?? 465) ?: 465;
        $mail->CharSet  = 'UTF-8';
        $enc = strtolower($cc['mail_secure'] ?? 'ssl');
        if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            $mail->SMTPSecure = ($enc === 'tls')
                ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS
                : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = ($enc === 'tls') ? 'tls' : 'ssl';
        }
        $mail->setFrom($from ?: $user, $fromN);
        return 'cliente';
    }

    // Respaldo: SMTP de AVBA, con Reply-To al correo del cliente si lo definió
    configurarMailer($mail, $pdo);
    if ($from) {
        try { $mail->addReplyTo($from, $fromN); } catch (\Throwable $e) {}
    }
    return 'avba-fallback';
}

/**
 * Genera el HTML de correo estándar con la firma configurada en BD.
 * $cuerpo debe ser HTML interno (párrafos, etc.)
 */
function plantillaCorreoHtml(PDO $pdo, string $cuerpo): string {
    $cfg      = getSmtpConfig($pdo);
    $empresa  = htmlspecialchars($cfg['firma_nombre'] ?? 'AVBA Inspections, Certifications and Maintenance S.A.S. de C.V.');
    $webRaw   = trim($cfg['firma_web'] ?? 'avba.com.mx');
    $webUrl   = (strpos($webRaw, 'http') === 0) ? $webRaw : 'https://' . $webRaw;
    $webLabel = htmlspecialchars($webRaw);
    $extra    = trim($cfg['firma_extra'] ?? '');
    $extraHtml = $extra ? '<br>' . nl2br(htmlspecialchars($extra)) : '';

    $firmaHtml   = trim($cfg['firma_html'] ?? '');
    $footerInner = $firmaHtml
        ? $firmaHtml
        : "<p style=\"font-size:12px;color:#9299a8;margin:0\">{$empresa}<br><a href=\"{$webUrl}\" style=\"color:#185FA5\">{$webLabel}</a>{$extraHtml}</p>";

    return "<!DOCTYPE html>
<html>
<body style=\"font-family:'Segoe UI',sans-serif;background:#f4f7fb;margin:0;padding:20px\">
<div style=\"max-width:560px;margin:auto;background:white;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.08)\">
  <div style=\"background:#185FA5;padding:24px;text-align:center\">
    <h1 style=\"color:white;font-size:20px;margin:0\">{$empresa}</h1>
  </div>
  <div style=\"padding:28px 32px\">{$cuerpo}</div>
  <div style=\"background:#f4f7fb;padding:16px 32px;border-top:1px solid #dfe5ef;text-align:center\">
    {$footerInner}
  </div>
</div>
</body>
</html>";
}

/**
 * Protege un PDF aplicando cifrado RC4 128-bit (solo lectura e impresión).
 * Re-importa todas las páginas con FpdiProtected y devuelve los bytes cifrados.
 *
 * @param  string $pdfBytes  Bytes del PDF original (sin protección)
 * @return string            Bytes del PDF protegido (o los originales si falla)
 */
function protegerPdf(string $pdfBytes): string
{
    if (!$pdfBytes) return $pdfBytes;

    $loader = __DIR__ . '/../lib/FpdiProtected.php';
    if (!class_exists('FpdiProtected', false) && file_exists($loader)) {
        require_once $loader;
    }
    if (!class_exists('FpdiProtected', false)) {
        return $pdfBytes;
    }

    $tmpIn = tempnam(sys_get_temp_dir(), 'avba_prot_');
    file_put_contents($tmpIn, $pdfBytes);

    try {
        $pdf = new FpdiProtected();
        $pageCount = $pdf->setSourceFile($tmpIn);

        for ($i = 1; $i <= $pageCount; $i++) {
            $tpl = $pdf->importPage($i);
            $sz  = $pdf->getTemplateSize($tpl);
            $pdf->AddPage(($sz['width'] > $sz['height']) ? 'L' : 'P', [$sz['width'], $sz['height']]);
            $pdf->useTemplate($tpl, 0, 0, $sz['width'], $sz['height']);
        }

        // Solo proteger contra modificaciones; permitir apertura y copia libre
        $pdf->SetProtection(['print', 'print-hi', 'copy', 'modify', 'annot-forms', 'fill-forms', 'extract', 'assemble'], '', 'Avba@Cert2024!');
        $result = $pdf->Output('', 'S');
        @unlink($tmpIn);
        return $result;
    } catch (\Throwable $e) {
        @unlink($tmpIn);
        return $pdfBytes;
    }
}

/* ══════════════════════════════════════════════════════════════════════
   DIRECTORIO DE CORREOS — autocompletado y registro para consultas futuras
   Reúne los correos usados en toda la plataforma (participantes de cursos,
   contactos de clientes, correos de equipos certificados) más un catálogo
   propio que va creciendo cada vez que se envía algo a un correo nuevo.
══════════════════════════════════════════════════════════════════════ */

function directorioCorreosMigrar(PDO $pdo): void {
    static $hecho = false;
    if ($hecho) return;
    $hecho = true;
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS directorio_correos (
              id         INT AUTO_INCREMENT PRIMARY KEY,
              correo     VARCHAR(200) NOT NULL,
              nombre     VARCHAR(200) NULL,
              usos       INT NOT NULL DEFAULT 1,
              updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY uniq_correo (correo)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (\Throwable $e) {
        error_log('[directorioCorreosMigrar] ' . $e->getMessage());
    }
}

/** Normaliza un correo: minúsculas, sin espacios. Devuelve '' si no es válido. */
function normalizarCorreo(string $correo): string {
    $correo = strtolower(trim($correo));
    return filter_var($correo, FILTER_VALIDATE_EMAIL) ? $correo : '';
}

/**
 * Registra uno o varios correos en el directorio (upsert). Se llama al enviar
 * documentos, para que un correo nuevo quede disponible en el autocompletado
 * de la próxima vez. $nombre es opcional (a quién pertenece el correo).
 */
function directorioCorreosRegistrar(PDO $pdo, array $correos, ?string $nombre = null): void {
    directorioCorreosMigrar($pdo);
    $nombre = $nombre !== null ? trim($nombre) : null;
    try {
        $st = $pdo->prepare(
            "INSERT INTO directorio_correos (correo, nombre, usos) VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE usos = usos + 1, nombre = COALESCE(VALUES(nombre), nombre)"
        );
        foreach ($correos as $c) {
            $c = normalizarCorreo((string)$c);
            if ($c === '') continue;
            $st->execute([$c, ($nombre !== null && $nombre !== '') ? $nombre : null]);
        }
    } catch (\Throwable $e) {
        error_log('[directorioCorreosRegistrar] ' . $e->getMessage());
    }
}

/**
 * Busca correos que coincidan con $q en todas las fuentes de la plataforma.
 * Devuelve [ ['correo'=>..., 'nombre'=>...], ... ] deduplicado, priorizando
 * el directorio propio (por número de usos) y limitado a $limit resultados.
 */
function directorioCorreosBuscar(PDO $pdo, string $q, int $limit = 10): array {
    directorioCorreosMigrar($pdo);
    $q     = strtolower(trim($q));
    $limit = max(1, min(25, $limit));
    if (strlen($q) < 2) return [];
    $like  = '%' . str_replace(['%','_'], ['\%','\_'], $q) . '%';

    $out = [];   // correo => nombre (primero que gane)
    $push = function(?string $correo, ?string $nombre) use (&$out, $q) {
        $correo = strtolower(trim((string)$correo));
        if ($correo === '' || !str_contains($correo, $q)) return;
        if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) return;
        if (!array_key_exists($correo, $out)) {
            $out[$correo] = ($nombre !== null && trim($nombre) !== '') ? trim($nombre) : '';
        } elseif ($out[$correo] === '' && $nombre !== null && trim($nombre) !== '') {
            $out[$correo] = trim($nombre);
        }
    };

    // 1) Directorio propio (prioridad por usos)
    try {
        $st = $pdo->prepare("SELECT correo, nombre FROM directorio_correos WHERE correo LIKE ? ORDER BY usos DESC, updated_at DESC LIMIT 50");
        $st->execute([$like]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $push($r['correo'], $r['nombre']);
    } catch (\Throwable $e) {}

    // 2) Participantes de cursos
    try {
        $st = $pdo->prepare("SELECT DISTINCT correo, nombre_completo FROM participantes_cursos WHERE correo LIKE ? LIMIT 50");
        $st->execute([$like]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            foreach (explode(',', (string)$r['correo']) as $c) $push($c, $r['nombre_completo'] ?? '');
        }
    } catch (\Throwable $e) {}

    // 3) Contactos de clientes
    try {
        $st = $pdo->prepare("SELECT DISTINCT correo_contacto, nombre_cliente FROM clientes WHERE correo_contacto LIKE ? LIMIT 50");
        $st->execute([$like]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            foreach (explode(',', (string)$r['correo_contacto']) as $c) $push($c, $r['nombre_cliente'] ?? '');
        }
    } catch (\Throwable $e) {}

    // 4) Correos de equipos certificados
    try {
        $st = $pdo->prepare("SELECT DISTINCT correo, cliente FROM equipos WHERE correo LIKE ? LIMIT 50");
        $st->execute([$like]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            foreach (explode(',', (string)$r['correo']) as $c) $push($c, $r['cliente'] ?? '');
        }
    } catch (\Throwable $e) {}

    $res = [];
    foreach ($out as $correo => $nombre) {
        $res[] = ['correo' => $correo, 'nombre' => $nombre];
        if (count($res) >= $limit) break;
    }
    return $res;
}
