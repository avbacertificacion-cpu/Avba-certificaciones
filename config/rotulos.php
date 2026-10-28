<?php
/**
 * Puntos para rótulos: dónde va cada rótulo dentro de una planta.
 *
 * Cada punto guarda su posición GPS —con la precisión que dio el teléfono,
 * porque dentro de una nave puede errar por decenas de metros—, fotos de
 * referencia del lugar y comentarios (numeración, altura, lo que haga falta)
 * para que cualquiera que vaya a colocarlo sepa exactamente dónde es.
 *
 * Lo usan la pantalla private/rotulos.php, su API y la hoja imprimible.
 */

/** Las tres tablas. Se crean solas la primera vez que se usa el módulo. */
function asegurarTablasRotulos(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS rotulos_puntos (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            empresa_id   INT NOT NULL,
            numero       VARCHAR(40)  DEFAULT NULL,
            rotulo       VARCHAR(120) DEFAULT NULL,
            ubicacion    VARCHAR(255) DEFAULT NULL,
            lat          DECIMAL(10,7) DEFAULT NULL,
            lng          DECIMAL(10,7) DEFAULT NULL,
            precision_m  DECIMAL(8,1)  DEFAULT NULL,
            gps_at       DATETIME      DEFAULT NULL,
            estado       VARCHAR(20) NOT NULL DEFAULT 'pendiente',
            colocado_at  DATETIME DEFAULT NULL,
            colocado_por INT DEFAULT NULL,
            cliente_uuid VARCHAR(40) DEFAULT NULL,
            creado_por   INT DEFAULT NULL,
            created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_rot_empresa (empresa_id),
            UNIQUE KEY uk_rot_uuid (cliente_uuid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS rotulos_fotos (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            punto_id     INT NOT NULL,
            etapa        VARCHAR(20) NOT NULL DEFAULT 'referencia',
            archivo      VARCHAR(255) NOT NULL,
            mini         VARCHAR(255) DEFAULT NULL,
            ancho        INT DEFAULT NULL,
            alto         INT DEFAULT NULL,
            tamano       INT NOT NULL DEFAULT 0,
            cliente_uuid VARCHAR(40) DEFAULT NULL,
            subido_por   INT DEFAULT NULL,
            created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY idx_rotfoto_punto (punto_id),
            UNIQUE KEY uk_rotfoto_uuid (cliente_uuid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS rotulos_comentarios (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            punto_id     INT NOT NULL,
            texto        TEXT NOT NULL,
            cliente_uuid VARCHAR(40) DEFAULT NULL,
            usuario_id   INT DEFAULT NULL,
            created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY idx_rotcom_punto (punto_id),
            UNIQUE KEY uk_rotcom_uuid (cliente_uuid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

/** La carpeta de las fotos, blindada: sólo se entregan por api/rotulos.php. */
function carpetaRotulos(): string {
    $dir = __DIR__ . '/../uploads/rotulos/';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (!is_file($dir . '.htaccess')) {
        @file_put_contents($dir . '.htaccess',
            "# Las fotos solo se entregan por api/rotulos.php (valida la sesión)\n" .
            "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n" .
            "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }
    if (!is_file($dir . 'index.html')) @file_put_contents($dir . 'index.html', '');
    return $dir;
}

/**
 * Guarda una foto ya validada como JPEG y su miniatura para las listas.
 *
 * El teléfono la manda reducida (1600 px), pero aquí se vuelve a codificar:
 * así se tira cualquier cosa que no sea la imagen misma y la miniatura de
 * 360 px hace que la lista de una planta no baje decenas de megas por datos.
 * Sin GD en el servidor se guarda tal cual y la lista usa la foto completa.
 *
 * @return array{archivo:string, mini:?string, ancho:int, alto:int, tamano:int}
 */
function guardarFotoRotulo(string $tmp, string $dir, int $puntoId): array {
    $info = @getimagesize($tmp);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        throw new RuntimeException('El archivo no es una foto (se aceptan JPG, PNG o WEBP).');
    }
    $base   = 'p' . $puntoId . '_' . bin2hex(random_bytes(10));
    $ancho  = (int) $info[0];
    $alto   = (int) $info[1];
    $mini   = null;

    $img = function_exists('imagecreatefromstring') ? @imagecreatefromstring((string) file_get_contents($tmp)) : false;
    if ($img) {
        // Si llegó más grande de lo pedido (una subida sin reducir), se reduce aquí
        $max = 2000;
        if (max($ancho, $alto) > $max) {
            $k = $max / max($ancho, $alto);
            $img2 = imagescale($img, (int) round($ancho * $k), (int) round($alto * $k));
            if ($img2) { imagedestroy($img); $img = $img2; $ancho = imagesx($img); $alto = imagesy($img); }
        }
        $archivo = $base . '.jpg';
        if (!imagejpeg($img, $dir . $archivo, 84)) throw new RuntimeException('No se pudo guardar la foto en el servidor.');
        $k = 360 / max($ancho, $alto);
        if ($k < 1) {
            $m = imagescale($img, (int) round($ancho * $k), (int) round($alto * $k));
            if ($m && imagejpeg($m, $dir . $base . '_m.jpg', 78)) $mini = $base . '_m.jpg';
            if ($m) imagedestroy($m);
        }
        imagedestroy($img);
    } else {
        $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2]];
        $archivo = $base . '.' . $ext;
        if (!@copy($tmp, $dir . $archivo)) throw new RuntimeException('No se pudo guardar la foto en el servidor.');
    }
    return ['archivo' => $archivo, 'mini' => $mini, 'ancho' => $ancho, 'alto' => $alto,
            'tamano' => (int) filesize($dir . $archivo)];
}

/** Borra los archivos de unas fotos (las filas las borra quien llama). */
function borrarArchivosFotos(array $fotos, string $dir): void {
    foreach ($fotos as $f) {
        foreach ([$f['archivo'] ?? null, $f['mini'] ?? null] as $a) {
            if ($a && is_file($dir . basename($a))) @unlink($dir . basename($a));
        }
    }
}

/** Enlace para abrir el punto en Google Maps (navega hasta ahí desde el teléfono). */
function enlaceMapa($lat, $lng): ?string {
    if ($lat === null || $lng === null) return null;
    return sprintf('https://www.google.com/maps/search/?api=1&query=%.7F,%.7F', (float) $lat, (float) $lng);
}
