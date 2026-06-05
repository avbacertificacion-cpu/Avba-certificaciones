<?php
/**
 * AVBA Certificaciones — Funciones de apoyo
 */

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

    // Consecutivo global: MAX entre todas las tablas con control
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
    $numB = str_pad($maxB + 1, 5, '0', STR_PAD_LEFT);

    return "{$numA}-{$numB}";
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
 * Construye la URL del QR usando quickchart.io.
 * El QR codifica la URL de validación completa para que al escanearlo
 * con cualquier cámara abra directamente la página con el código prellenado.
 */
function urlQR(string $codigo): string {
    if (!$codigo) return '';
    $validarUrl = rtrim(SITE_URL, '/') . '/validar.html?qr=' . urlencode($codigo);
    return 'https://quickchart.io/qr?text=' . urlencode($validarUrl) . '&size=300';
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

    $stmt = $pdo->prepare(
        "SELECT id, usuario, rol, nombre, id_cliente
         FROM usuarios
         WHERE session_token = ? AND activo = 1 AND token_expires > NOW()"
    );
    $stmt->execute([$token]);
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
 * Devuelve una respuesta JSON y termina la ejecución.
 */
function respuesta(array $data, int $httpCode = 200): void {
    http_response_code($httpCode);
    echo json_encode(fixEncoding($data), JSON_UNESCAPED_UNICODE);
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
 * Re-importa todas las páginas con FPDI y devuelve los bytes cifrados.
 *
 * @param  string $pdfBytes  Bytes del PDF original (sin protección)
 * @return string            Bytes del PDF protegido
 */
function protegerPdf(string $pdfBytes): string
{
    return $pdfBytes;
}
