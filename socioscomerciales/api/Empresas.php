<?php
/**
 * Socios Comerciales AVBA — Perfil de empresa
 */

class ScEmpresas {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    // ── OBTENER PERFIL ───────────────────────────────────────
    public function obtenerPerfil(int $usuarioId): array {
        $stmt = $this->pdo->prepare(
            "SELECT e.id, e.nombre, e.giro, e.descripcion, e.sitio_web, e.logo_url, e.ubicacion, u.correo
             FROM sc_empresas e
             JOIN sc_usuarios u ON u.id = e.usuario_id
             WHERE e.usuario_id = ?"
        );
        $stmt->execute([$usuarioId]);
        $empresa = $stmt->fetch();

        if (!$empresa) {
            return ['status' => 'error', 'message' => 'Perfil de empresa no encontrado.'];
        }

        return ['status' => 'success', 'empresa' => $empresa];
    }

    // ── ACTUALIZAR DATOS ─────────────────────────────────────
    public function actualizarPerfil(int $usuarioId, array $payload): array {
        $campos = ['nombre', 'giro', 'descripcion', 'sitio_web', 'ubicacion'];
        $sets   = [];
        $params = [];

        foreach ($campos as $campo) {
            if (isset($payload[$campo])) {
                $sets[]   = "{$campo} = ?";
                $params[] = trim((string) $payload[$campo]) ?: null;
            }
        }

        if (empty($sets)) {
            return ['status' => 'success', 'message' => 'Sin cambios.'];
        }

        $params[] = $usuarioId;
        $sql = "UPDATE sc_empresas SET " . implode(', ', $sets) . " WHERE usuario_id = ?";
        $this->pdo->prepare($sql)->execute($params);

        return ['status' => 'success', 'message' => 'Perfil actualizado.'];
    }

    // ── SUBIR LOGO ────────────────────────────────────────────
    public function subirLogo(int $usuarioId, array $file): array {
        $mimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        try {
            $url = scGuardarArchivo($file, 'logos', $mimes, 4 * 1024 * 1024);
        } catch (RuntimeException $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }

        $this->pdo->prepare("UPDATE sc_empresas SET logo_url = ? WHERE usuario_id = ?")
            ->execute([$url, $usuarioId]);

        return ['status' => 'success', 'message' => 'Logo actualizado.', 'logo_url' => $url];
    }
}
