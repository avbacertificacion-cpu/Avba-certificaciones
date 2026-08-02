<?php
/**
 * Socios Comerciales AVBA — Perfil de persona (candidato)
 */

class ScPersonas {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    private function idPersonaPorUsuario(int $usuarioId): ?int {
        $stmt = $this->pdo->prepare("SELECT id FROM sc_personas WHERE usuario_id = ?");
        $stmt->execute([$usuarioId]);
        $id = $stmt->fetchColumn();
        return $id ? (int) $id : null;
    }

    // ── OBTENER PERFIL COMPLETO ─────────────────────────────
    public function obtenerPerfil(int $usuarioId): array {
        $stmt = $this->pdo->prepare(
            "SELECT p.id, p.nombre, p.curp, p.headline, p.ubicacion, p.resumen,
                    p.cv_url, p.foto_url, p.telefono, u.correo
             FROM sc_personas p
             JOIN sc_usuarios u ON u.id = p.usuario_id
             WHERE p.usuario_id = ?"
        );
        $stmt->execute([$usuarioId]);
        $persona = $stmt->fetch();

        if (!$persona) {
            return ['status' => 'error', 'message' => 'Perfil de persona no encontrado.'];
        }

        $personaId = (int) $persona['id'];

        $stmt = $this->pdo->prepare(
            "SELECT id, empresa, puesto, desde, hasta, actual, descripcion
             FROM sc_experiencia WHERE persona_id = ? ORDER BY actual DESC, desde DESC"
        );
        $stmt->execute([$personaId]);
        $experiencia = $stmt->fetchAll();

        $stmt = $this->pdo->prepare(
            "SELECT id, institucion, titulo, anio_inicio, anio_fin
             FROM sc_educacion WHERE persona_id = ? ORDER BY anio_fin DESC"
        );
        $stmt->execute([$personaId]);
        $educacion = $stmt->fetchAll();

        $stmt = $this->pdo->prepare(
            "SELECT id, habilidad FROM sc_habilidades WHERE persona_id = ? ORDER BY id"
        );
        $stmt->execute([$personaId]);
        $habilidades = $stmt->fetchAll();

        return [
            'status'      => 'success',
            'persona'     => $persona,
            'experiencia' => $experiencia,
            'educacion'   => $educacion,
            'habilidades' => $habilidades,
        ];
    }

    // ── ACTUALIZAR DATOS BÁSICOS ────────────────────────────
    public function actualizarPerfil(int $usuarioId, array $payload): array {
        $campos = ['nombre', 'curp', 'headline', 'ubicacion', 'resumen', 'telefono'];
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
        $sql = "UPDATE sc_personas SET " . implode(', ', $sets) . " WHERE usuario_id = ?";
        $this->pdo->prepare($sql)->execute($params);

        return ['status' => 'success', 'message' => 'Perfil actualizado.'];
    }

    // ── SUBIR CV (PDF) ──────────────────────────────────────
    public function subirCV(int $usuarioId, array $file): array {
        try {
            $url = scGuardarArchivo($file, 'cv', ['application/pdf' => 'pdf'], 8 * 1024 * 1024);
        } catch (RuntimeException $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }

        $this->pdo->prepare("UPDATE sc_personas SET cv_url = ? WHERE usuario_id = ?")
            ->execute([$url, $usuarioId]);

        return ['status' => 'success', 'message' => 'CV actualizado.', 'cv_url' => $url];
    }

    // ── SUBIR FOTO ───────────────────────────────────────────
    public function subirFoto(int $usuarioId, array $file): array {
        $mimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        try {
            $url = scGuardarArchivo($file, 'fotos', $mimes, 4 * 1024 * 1024);
        } catch (RuntimeException $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }

        $this->pdo->prepare("UPDATE sc_personas SET foto_url = ? WHERE usuario_id = ?")
            ->execute([$url, $usuarioId]);

        return ['status' => 'success', 'message' => 'Foto actualizada.', 'foto_url' => $url];
    }

    // ── EXPERIENCIA ──────────────────────────────────────────
    public function agregarExperiencia(int $usuarioId, array $payload): array {
        $personaId = $this->idPersonaPorUsuario($usuarioId);
        if (!$personaId) return ['status' => 'error', 'message' => 'Perfil no encontrado.'];

        $empresa = trim($payload['empresa'] ?? '');
        $puesto  = trim($payload['puesto']  ?? '');
        if (!$empresa || !$puesto) {
            return ['status' => 'error', 'message' => 'Empresa y puesto son obligatorios.'];
        }

        $actual = !empty($payload['actual']) ? 1 : 0;
        $this->pdo->prepare(
            "INSERT INTO sc_experiencia (persona_id, empresa, puesto, desde, hasta, actual, descripcion)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $personaId, $empresa, $puesto,
            $payload['desde'] ?: null,
            $actual ? null : ($payload['hasta'] ?: null),
            $actual,
            trim($payload['descripcion'] ?? '') ?: null,
        ]);

        return ['status' => 'success', 'message' => 'Experiencia agregada.', 'id' => (int) $this->pdo->lastInsertId()];
    }

    public function eliminarExperiencia(int $usuarioId, int $id): array {
        $personaId = $this->idPersonaPorUsuario($usuarioId);
        if (!$personaId) return ['status' => 'error', 'message' => 'Perfil no encontrado.'];

        $this->pdo->prepare("DELETE FROM sc_experiencia WHERE id = ? AND persona_id = ?")
            ->execute([$id, $personaId]);

        return ['status' => 'success', 'message' => 'Experiencia eliminada.'];
    }

    // ── EDUCACIÓN ────────────────────────────────────────────
    public function agregarEducacion(int $usuarioId, array $payload): array {
        $personaId = $this->idPersonaPorUsuario($usuarioId);
        if (!$personaId) return ['status' => 'error', 'message' => 'Perfil no encontrado.'];

        $institucion = trim($payload['institucion'] ?? '');
        $titulo      = trim($payload['titulo']      ?? '');
        if (!$institucion || !$titulo) {
            return ['status' => 'error', 'message' => 'Institución y título son obligatorios.'];
        }

        $this->pdo->prepare(
            "INSERT INTO sc_educacion (persona_id, institucion, titulo, anio_inicio, anio_fin)
             VALUES (?, ?, ?, ?, ?)"
        )->execute([
            $personaId, $institucion, $titulo,
            $payload['anio_inicio'] ?: null,
            $payload['anio_fin'] ?: null,
        ]);

        return ['status' => 'success', 'message' => 'Educación agregada.', 'id' => (int) $this->pdo->lastInsertId()];
    }

    public function eliminarEducacion(int $usuarioId, int $id): array {
        $personaId = $this->idPersonaPorUsuario($usuarioId);
        if (!$personaId) return ['status' => 'error', 'message' => 'Perfil no encontrado.'];

        $this->pdo->prepare("DELETE FROM sc_educacion WHERE id = ? AND persona_id = ?")
            ->execute([$id, $personaId]);

        return ['status' => 'success', 'message' => 'Educación eliminada.'];
    }

    // ── HABILIDADES ──────────────────────────────────────────
    public function agregarHabilidad(int $usuarioId, array $payload): array {
        $personaId = $this->idPersonaPorUsuario($usuarioId);
        if (!$personaId) return ['status' => 'error', 'message' => 'Perfil no encontrado.'];

        $habilidad = trim($payload['habilidad'] ?? '');
        if (!$habilidad) return ['status' => 'error', 'message' => 'La habilidad no puede estar vacía.'];

        $this->pdo->prepare("INSERT INTO sc_habilidades (persona_id, habilidad) VALUES (?, ?)")
            ->execute([$personaId, $habilidad]);

        return ['status' => 'success', 'message' => 'Habilidad agregada.', 'id' => (int) $this->pdo->lastInsertId()];
    }

    public function eliminarHabilidad(int $usuarioId, int $id): array {
        $personaId = $this->idPersonaPorUsuario($usuarioId);
        if (!$personaId) return ['status' => 'error', 'message' => 'Perfil no encontrado.'];

        $this->pdo->prepare("DELETE FROM sc_habilidades WHERE id = ? AND persona_id = ?")
            ->execute([$id, $personaId]);

        return ['status' => 'success', 'message' => 'Habilidad eliminada.'];
    }
}
