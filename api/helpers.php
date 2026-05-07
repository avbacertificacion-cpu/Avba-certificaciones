<?php
/**
 * AVBA Certificaciones — Funciones de apoyo
 */

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

    // Consecutivo global: MAX entre equipos, accesorios_sesiones y participantes_cursos
    $maxB = 0;
    foreach (['equipos', 'accesorios_sesiones', 'participantes_cursos'] as $tabla) {
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
