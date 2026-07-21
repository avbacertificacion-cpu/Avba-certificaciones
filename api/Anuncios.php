<?php
/**
 * AVBA Certificaciones — Publicidad en la página de validación
 *
 * Administra los banners promocionales (por ejemplo "Pruebas No Destructivas")
 * que se muestran, rotando, en la página pública de validación de certificados
 * (validar.html). Los banners se suben desde el panel de administrador.
 */
class Anuncios {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->migrate();
    }

    private function migrate(): void {
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS anuncios_validacion (
                  id          INT AUTO_INCREMENT PRIMARY KEY,
                  titulo      VARCHAR(150) NULL,
                  imagen_url  VARCHAR(300) NOT NULL,
                  enlace      VARCHAR(500) NULL,
                  activo      TINYINT NOT NULL DEFAULT 1,
                  orden       INT NOT NULL DEFAULT 0,
                  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\PDOException $e) {
            error_log('[Anuncios] migrate: ' . $e->getMessage());
        }
    }

    private function urlAbs(string $rel): string {
        if ($rel === '') return '';
        if (preg_match('#^https?://#i', $rel)) return $rel;
        return rtrim(SITE_URL, '/') . '/' . ltrim($rel, '/');
    }

    /** Lista pública (sólo activos) para la página de validación. */
    public function listarPublico(): array {
        try {
            $rows = $this->pdo->query(
                "SELECT id, titulo, imagen_url, enlace
                 FROM anuncios_validacion
                 WHERE activo = 1
                 ORDER BY orden ASC, id ASC"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return ['status' => 'success', 'anuncios' => []];
        }
        $out = array_map(fn($r) => [
            'id'     => (int)$r['id'],
            'titulo' => $r['titulo'] ?? '',
            'imagen' => $this->urlAbs($r['imagen_url']),
            'enlace' => $r['enlace'] ?? '',
        ], $rows);
        return ['status' => 'success', 'anuncios' => $out];
    }

    /** Lista completa (admin). */
    public function listarAdmin(): array {
        $rows = $this->pdo->query(
            "SELECT id, titulo, imagen_url, enlace, activo, orden, created_at
             FROM anuncios_validacion
             ORDER BY orden ASC, id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['imagen'] = $this->urlAbs($r['imagen_url']);
            $r['activo'] = (int)$r['activo'];
        }
        unset($r);
        return ['status' => 'success', 'data' => $rows];
    }

    /** Sube un nuevo banner. */
    public function subir(array $file, string $titulo, string $enlace): array {
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return ['status' => 'error', 'message' => 'Selecciona una imagen válida.'];
        }
        $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        if (!in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true)) {
            return ['status' => 'error', 'message' => 'Formato no permitido. Usa PNG, JPG, GIF o WEBP.'];
        }
        if (($file['size'] ?? 0) > 8 * 1024 * 1024) {
            return ['status' => 'error', 'message' => 'La imagen no debe superar 8 MB.'];
        }

        $enlace = trim($enlace);
        if ($enlace !== '' && !preg_match('#^https?://#i', $enlace)) {
            $enlace = 'https://' . $enlace;
        }

        $dir = __DIR__ . '/../uploads/anuncios/';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $nombre  = 'anuncio_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
        $destino = $dir . $nombre;

        if (!move_uploaded_file($file['tmp_name'], $destino)) {
            return ['status' => 'error', 'message' => 'No se pudo guardar la imagen.'];
        }

        $rel = 'uploads/anuncios/' . $nombre;
        // orden = siguiente al máximo
        $maxOrden = (int)$this->pdo->query("SELECT COALESCE(MAX(orden),0) FROM anuncios_validacion")->fetchColumn();
        $this->pdo->prepare(
            "INSERT INTO anuncios_validacion (titulo, imagen_url, enlace, activo, orden)
             VALUES (?,?,?,1,?)"
        )->execute([trim($titulo) ?: null, $rel, $enlace ?: null, $maxOrden + 1]);

        return ['status' => 'success', 'message' => 'Anuncio agregado.', 'id' => (int)$this->pdo->lastInsertId()];
    }

    /** Activa/desactiva o edita datos de un anuncio. */
    public function actualizar(array $payload): array {
        $id = (int)($payload['id'] ?? 0);
        if ($id <= 0) return ['status' => 'error', 'message' => 'ID inválido.'];
        $chk = $this->pdo->prepare("SELECT id FROM anuncios_validacion WHERE id = ?");
        $chk->execute([$id]);
        if (!$chk->fetch()) return ['status' => 'error', 'message' => 'Anuncio no encontrado.'];

        $sets = []; $params = [];
        if (array_key_exists('activo', $payload)) { $sets[] = 'activo = ?'; $params[] = $payload['activo'] ? 1 : 0; }
        if (array_key_exists('titulo', $payload)) { $sets[] = 'titulo = ?'; $params[] = trim((string)$payload['titulo']) ?: null; }
        if (array_key_exists('enlace', $payload)) {
            $e = trim((string)$payload['enlace']);
            if ($e !== '' && !preg_match('#^https?://#i', $e)) $e = 'https://' . $e;
            $sets[] = 'enlace = ?'; $params[] = $e ?: null;
        }
        if (array_key_exists('orden', $payload)) { $sets[] = 'orden = ?'; $params[] = (int)$payload['orden']; }
        if (!$sets) return ['status' => 'success', 'message' => 'Sin cambios.'];

        $params[] = $id;
        $this->pdo->prepare("UPDATE anuncios_validacion SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
        return ['status' => 'success', 'message' => 'Anuncio actualizado.'];
    }

    public function eliminar(int $id): array {
        if ($id <= 0) return ['status' => 'error', 'message' => 'ID inválido.'];
        $stmt = $this->pdo->prepare("SELECT imagen_url FROM anuncios_validacion WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return ['status' => 'error', 'message' => 'Anuncio no encontrado.'];

        $this->pdo->prepare("DELETE FROM anuncios_validacion WHERE id = ?")->execute([$id]);
        $abs = __DIR__ . '/../' . $row['imagen_url'];
        if (is_file($abs)) @unlink($abs);
        return ['status' => 'success', 'message' => 'Anuncio eliminado.'];
    }
}
