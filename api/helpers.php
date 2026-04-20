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
 * Devuelve una respuesta JSON y termina la ejecución.
 */
function respuesta(array $data, int $httpCode = 200): void {
    http_response_code($httpCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
