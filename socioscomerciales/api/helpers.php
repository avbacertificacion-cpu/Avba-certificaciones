<?php
/**
 * Socios Comerciales AVBA — Funciones de apoyo
 */

const SC_TOKEN_TTL = 60 * 60 * 24 * 30; // 30 días

/**
 * Genera un token de sesión seguro de 64 caracteres hex.
 */
function scGenerarToken(): string {
    return bin2hex(random_bytes(32));
}

/**
 * Valida el token de sesión enviado en cada request.
 * Devuelve el usuario (sc_usuarios) o null si no es válido.
 */
function scValidarToken(PDO $pdo, ?string $token): ?array {
    if (!$token) return null;

    $stmt = $pdo->prepare(
        "SELECT id, tipo, correo, activo
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
 * Guarda un archivo subido ($_FILES[campo]) validando tipo y tamaño.
 * Devuelve la URL relativa (uploads/carpeta/archivo.ext) o lanza excepción.
 */
function scGuardarArchivo(array $file, string $carpeta, array $mimesPermitidos, int $maxBytes): string {
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Error al subir el archivo.');
    }
    if ($file['size'] > $maxBytes) {
        throw new RuntimeException('El archivo excede el tamaño máximo permitido.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!isset($mimesPermitidos[$mime])) {
        throw new RuntimeException('Tipo de archivo no permitido.');
    }
    $ext = $mimesPermitidos[$mime];

    $dir = __DIR__ . '/../uploads/' . $carpeta . '/';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $nombre = bin2hex(random_bytes(16)) . '.' . $ext;
    $destino = $dir . $nombre;

    if (!move_uploaded_file($file['tmp_name'], $destino)) {
        throw new RuntimeException('No se pudo guardar el archivo.');
    }

    return 'uploads/' . $carpeta . '/' . $nombre;
}
