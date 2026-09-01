<?php
/**
 * Utilidades de documentos adjuntos compartidas entre módulos.
 * La API vive en api/documentos.php; aquí solo va lo que otras pantallas necesitan.
 */

/**
 * Borra los documentos de un registro (filas y archivos) al eliminarlo,
 * para que no queden archivos huérfanos ocupando espacio en el servidor.
 */
function borrarDocumentosDe(PDO $pdo, string $modulo, int $registro_id): void {
    if ($registro_id <= 0) return;
    $dir = __DIR__ . '/../uploads/documentos/';
    try {
        $stmt = $pdo->prepare("SELECT id, archivo FROM documentos WHERE modulo = ? AND registro_id = ?");
        $stmt->execute([$modulo, $registro_id]);
        $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$docs) return;
        $pdo->prepare("DELETE FROM documentos WHERE modulo = ? AND registro_id = ?")->execute([$modulo, $registro_id]);
        foreach ($docs as $d) {
            $ruta = $dir . basename($d['archivo']);
            if (is_file($ruta)) @unlink($ruta);
        }
    } catch (Exception $e) {
        // Si la tabla aún no existe no hay nada que limpiar
    }
}
