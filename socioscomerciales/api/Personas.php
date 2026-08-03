<?php
/**
 * Socios Comerciales AVBA — Perfil de persona (candidato)
 */

class ScPersonas {
    private PDO $pdo;

    /** Tipos de imagen aceptados para la foto de perfil. */
    private const MIMES_IMAGEN = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    private function idPersonaPorUsuario(int $usuarioId): ?int {
        $stmt = $this->pdo->prepare("SELECT id FROM sc_personas WHERE usuario_id = ?");
        $stmt->execute([$usuarioId]);
        $id = $stmt->fetchColumn();
        return $id ? (int) $id : null;
    }

    // ── PERFIL PROPIO ────────────────────────────────────────
    public function obtenerPerfil(int $usuarioId): array {
        $stmt = $this->pdo->prepare(
            "SELECT p.id, p.nombre, p.curp, p.headline, p.ubicacion, p.resumen,
                    p.cv_url, p.foto_url, p.telefono, u.correo, u.correo_verificado
             FROM sc_personas p
             JOIN sc_usuarios u ON u.id = p.usuario_id
             WHERE p.usuario_id = ?"
        );
        $stmt->execute([$usuarioId]);
        $persona = $stmt->fetch();

        if (!$persona) {
            return ['status' => 'error', 'message' => 'Perfil de persona no encontrado.'];
        }

        return array_merge(
            ['status' => 'success', 'persona' => $persona],
            $this->seccionesDe((int) $persona['id'])
        );
    }

    // ── PERFIL PÚBLICO (visible para empresas) ───────────────
    public function obtenerPerfilPublico(int $personaId): array {
        $stmt = $this->pdo->prepare(
            "SELECT p.id, p.nombre, p.headline, p.ubicacion, p.resumen,
                    p.cv_url, p.foto_url, u.correo, u.correo_verificado
             FROM sc_personas p
             JOIN sc_usuarios u ON u.id = p.usuario_id
             WHERE p.id = ? AND u.activo = 1"
        );
        $stmt->execute([$personaId]);
        $persona = $stmt->fetch();

        if (!$persona) {
            return ['status' => 'error', 'message' => 'Candidato no encontrado.'];
        }

        return array_merge(
            ['status' => 'success', 'persona' => $persona],
            $this->seccionesDe($personaId)
        );
    }

    /** Experiencia + educación + habilidades de una persona. */
    private function seccionesDe(int $personaId): array {
        $stmt = $this->pdo->prepare(
            "SELECT id, empresa, puesto, desde, hasta, actual, descripcion
             FROM sc_experiencia WHERE persona_id = ?
             ORDER BY actual DESC, desde DESC, id DESC"
        );
        $stmt->execute([$personaId]);
        $experiencia = $stmt->fetchAll();

        $stmt = $this->pdo->prepare(
            "SELECT id, institucion, titulo, anio_inicio, anio_fin
             FROM sc_educacion WHERE persona_id = ? ORDER BY anio_fin DESC, id DESC"
        );
        $stmt->execute([$personaId]);
        $educacion = $stmt->fetchAll();

        $stmt = $this->pdo->prepare(
            "SELECT id, habilidad FROM sc_habilidades WHERE persona_id = ? ORDER BY id"
        );
        $stmt->execute([$personaId]);
        $habilidades = $stmt->fetchAll();

        return [
            'experiencia' => $experiencia,
            'educacion'   => $educacion,
            'habilidades' => $habilidades,
        ];
    }

    // ── BUSCAR CANDIDATOS (para empresas) ────────────────────
    public function buscarCandidatos(array $filtros): array {
        $texto     = trim($filtros['texto']     ?? '');
        $ubicacion = trim($filtros['ubicacion'] ?? '');
        $habilidad = trim($filtros['habilidad'] ?? '');
        $conCV     = !empty($filtros['con_cv']);

        $where  = ['u.activo = 1'];
        $params = [];

        if ($texto !== '') {
            $where[] = '(p.nombre LIKE ? OR p.headline LIKE ? OR p.resumen LIKE ?)';
            $like    = '%' . $texto . '%';
            array_push($params, $like, $like, $like);
        }
        if ($ubicacion !== '') {
            $where[]  = 'p.ubicacion LIKE ?';
            $params[] = '%' . $ubicacion . '%';
        }
        if ($habilidad !== '') {
            $where[]  = 'EXISTS (SELECT 1 FROM sc_habilidades h WHERE h.persona_id = p.id AND h.habilidad LIKE ?)';
            $params[] = '%' . $habilidad . '%';
        }
        if ($conCV) {
            $where[] = "p.cv_url IS NOT NULL AND p.cv_url <> ''";
        }

        $sql = "SELECT p.id, p.nombre, p.headline, p.ubicacion, p.foto_url,
                       p.cv_url, u.correo_verificado
                FROM sc_personas p
                JOIN sc_usuarios u ON u.id = p.usuario_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY u.correo_verificado DESC, p.id DESC
                LIMIT 60";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $candidatos = $stmt->fetchAll();

        // Adjuntar hasta 6 habilidades por candidato para mostrarlas en la tarjeta
        foreach ($candidatos as &$c) {
            $s = $this->pdo->prepare("SELECT habilidad FROM sc_habilidades WHERE persona_id = ? ORDER BY id LIMIT 6");
            $s->execute([$c['id']]);
            $c['habilidades'] = $s->fetchAll(PDO::FETCH_COLUMN);
        }
        unset($c);

        return ['status' => 'success', 'candidatos' => $candidatos, 'total' => count($candidatos)];
    }

    /**
     * Entrega el CV de un candidato validando la sesión.
     * Los PDF ya no se sirven como archivos estáticos: contienen datos
     * personales (teléfono, domicilio, historial) y su URL, una vez conocida,
     * funcionaba para siempre y para cualquiera.
     *
     * Reglas: el candidato solo puede abrir el suyo; una empresa con sesión
     * puede abrir el de cualquier candidato, que es justo la función de la
     * bolsa de trabajo. Para restringirlo aún más a quienes se postularon a
     * sus vacantes, basta activar la comprobación marcada abajo.
     */
    public function entregarCV(array $usuario, int $personaId): void {
        $autorizado = false;

        if ($usuario['tipo'] === 'empresa') {
            $autorizado = true;

            // — Restricción opcional: solo candidatos que se postularon —
            // $s = $this->pdo->prepare(
            //     "SELECT 1 FROM sc_postulaciones p
            //      JOIN sc_vacantes v ON v.id = p.vacante_id
            //      JOIN sc_empresas e ON e.id = v.empresa_id
            //      WHERE p.persona_id = ? AND e.usuario_id = ? LIMIT 1");
            // $s->execute([$personaId, $usuario['id']]);
            // $autorizado = (bool) $s->fetchColumn();
        } else {
            $s = $this->pdo->prepare("SELECT id FROM sc_personas WHERE usuario_id = ?");
            $s->execute([$usuario['id']]);
            $autorizado = ((int) $s->fetchColumn() === $personaId);
        }

        if (!$autorizado) {
            scRespuesta(['status' => 'error', 'message' => 'No autorizado para ver este CV.'], 403);
        }

        $stmt = $this->pdo->prepare(
            "SELECT p.cv_url, p.nombre FROM sc_personas p
             JOIN sc_usuarios u ON u.id = p.usuario_id
             WHERE p.id = ? AND u.activo = 1"
        );
        $stmt->execute([$personaId]);
        $fila = $stmt->fetch();

        if (!$fila || !$fila['cv_url']) {
            scRespuesta(['status' => 'error', 'message' => 'Este candidato no tiene CV cargado.'], 404);
        }

        // La ruta sale de la base, pero se comprueba igual: solo dentro de
        // uploads/cv/ y sin saltos de directorio.
        $rel = $fila['cv_url'];
        if (strpos($rel, 'uploads/cv/') !== 0 || strpos($rel, '..') !== false) {
            scRespuesta(['status' => 'error', 'message' => 'Archivo no válido.'], 400);
        }

        $ruta = realpath(__DIR__ . '/../' . $rel);
        $base = realpath(__DIR__ . '/../uploads/cv');
        if (!$ruta || !$base || strpos($ruta, $base) !== 0 || !is_file($ruta)) {
            scRespuesta(['status' => 'error', 'message' => 'El archivo ya no está disponible.'], 404);
        }

        $nombreArchivo = 'CV - ' . preg_replace('/[^\p{L}\p{N} _-]/u', '', $fila['nombre']) . '.pdf';

        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/pdf');
        header('Content-Length: ' . filesize($ruta));
        header('Content-Disposition: inline; filename="' . $nombreArchivo . '"');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        readfile($ruta);
        exit;
    }

    // ── ACTUALIZAR DATOS BÁSICOS ─────────────────────────────
    public function actualizarPerfil(int $usuarioId, array $payload): array {
        if (isset($payload['nombre']) && trim($payload['nombre']) === '') {
            return ['status' => 'error', 'message' => 'El nombre no puede quedar vacío.'];
        }

        // Largo real de cada columna: pasarse provoca el error 1406 de
        // MariaDB en modo estricto, que el usuario vería como un 500.
        $campos = [
            'nombre' => 190, 'curp' => 18, 'headline' => 190,
            'ubicacion' => 190, 'resumen' => 5000, 'telefono' => 30,
        ];
        $sets   = [];
        $params = [];

        foreach ($campos as $campo => $max) {
            if (!isset($payload[$campo])) continue;
            $sets[]   = "{$campo} = ?";
            $params[] = scTexto((string) $payload[$campo], $max);
        }

        if (empty($sets)) {
            return ['status' => 'success', 'message' => 'Sin cambios.'];
        }

        $params[] = $usuarioId;
        $this->pdo->prepare("UPDATE sc_personas SET " . implode(', ', $sets) . " WHERE usuario_id = ?")
            ->execute($params);

        return ['status' => 'success', 'message' => 'Perfil actualizado.'];
    }

    // ── SUBIR CV (PDF) ───────────────────────────────────────
    public function subirCV(int $usuarioId, array $file): array {
        try {
            $url = scGuardarArchivo($file, 'cv', ['application/pdf' => 'pdf'], 8 * 1024 * 1024);
        } catch (RuntimeException $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }

        $anterior = $this->valorActual($usuarioId, 'cv_url');
        $this->pdo->prepare("UPDATE sc_personas SET cv_url = ? WHERE usuario_id = ?")
            ->execute([$url, $usuarioId]);
        scBorrarArchivo($anterior);

        return ['status' => 'success', 'message' => 'CV actualizado.', 'cv_url' => $url];
    }

    // ── SUBIR FOTO ───────────────────────────────────────────
    public function subirFoto(int $usuarioId, array $file): array {
        try {
            $url = scGuardarArchivo($file, 'fotos', self::MIMES_IMAGEN, 6 * 1024 * 1024);
        } catch (RuntimeException $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }

        $anterior = $this->valorActual($usuarioId, 'foto_url');
        $this->pdo->prepare("UPDATE sc_personas SET foto_url = ? WHERE usuario_id = ?")
            ->execute([$url, $usuarioId]);
        scBorrarArchivo($anterior);

        return ['status' => 'success', 'message' => 'Foto actualizada.', 'foto_url' => $url];
    }

    private function valorActual(int $usuarioId, string $columna): ?string {
        $stmt = $this->pdo->prepare("SELECT {$columna} FROM sc_personas WHERE usuario_id = ?");
        $stmt->execute([$usuarioId]);
        return $stmt->fetchColumn() ?: null;
    }

    // ── EXPERIENCIA ──────────────────────────────────────────
    public function agregarExperiencia(int $usuarioId, array $payload): array {
        $personaId = $this->idPersonaPorUsuario($usuarioId);
        if (!$personaId) return ['status' => 'error', 'message' => 'Perfil no encontrado.'];

        $empresa = scTexto($payload['empresa'] ?? '', 190) ?? '';
        $puesto  = scTexto($payload['puesto']  ?? '', 190) ?? '';
        if (!$empresa || !$puesto) {
            return ['status' => 'error', 'message' => 'Empresa y puesto son obligatorios.'];
        }

        $actual = !empty($payload['actual']) && $payload['actual'] !== 'false' ? 1 : 0;

        $this->pdo->prepare(
            "INSERT INTO sc_experiencia (persona_id, empresa, puesto, desde, hasta, actual, descripcion)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $personaId, $empresa, $puesto,
            scFecha($payload['desde'] ?? null),
            $actual ? null : scFecha($payload['hasta'] ?? null),
            $actual,
            scTexto($payload['descripcion'] ?? null, 5000),
        ]);

        return ['status' => 'success', 'message' => 'Experiencia agregada.'];
    }

    public function actualizarExperiencia(int $usuarioId, array $payload): array {
        $personaId = $this->idPersonaPorUsuario($usuarioId);
        if (!$personaId) return ['status' => 'error', 'message' => 'Perfil no encontrado.'];

        $id = (int) ($payload['id'] ?? 0);
        if (!$id) return ['status' => 'error', 'message' => 'Experiencia no indicada.'];

        $empresa = scTexto($payload['empresa'] ?? '', 190) ?? '';
        $puesto  = scTexto($payload['puesto']  ?? '', 190) ?? '';
        if (!$empresa || !$puesto) {
            return ['status' => 'error', 'message' => 'Empresa y puesto son obligatorios.'];
        }

        $actual = !empty($payload['actual']) && $payload['actual'] !== 'false' ? 1 : 0;

        $stmt = $this->pdo->prepare(
            "UPDATE sc_experiencia
             SET empresa = ?, puesto = ?, desde = ?, hasta = ?, actual = ?, descripcion = ?
             WHERE id = ? AND persona_id = ?"
        );
        $stmt->execute([
            $empresa, $puesto,
            scFecha($payload['desde'] ?? null),
            $actual ? null : scFecha($payload['hasta'] ?? null),
            $actual,
            scTexto($payload['descripcion'] ?? null, 5000),
            $id, $personaId,
        ]);

        return ['status' => 'success', 'message' => 'Experiencia actualizada.'];
    }

    public function eliminarExperiencia(int $usuarioId, int $id): array {
        return $this->eliminarDe('sc_experiencia', $usuarioId, $id, 'Experiencia eliminada.');
    }

    // ── EDUCACIÓN ────────────────────────────────────────────
    public function agregarEducacion(int $usuarioId, array $payload): array {
        $personaId = $this->idPersonaPorUsuario($usuarioId);
        if (!$personaId) return ['status' => 'error', 'message' => 'Perfil no encontrado.'];

        $institucion = scTexto($payload['institucion'] ?? '', 190) ?? '';
        $titulo      = scTexto($payload['titulo']      ?? '', 190) ?? '';
        if (!$institucion || !$titulo) {
            return ['status' => 'error', 'message' => 'Institución y título son obligatorios.'];
        }

        $this->pdo->prepare(
            "INSERT INTO sc_educacion (persona_id, institucion, titulo, anio_inicio, anio_fin)
             VALUES (?, ?, ?, ?, ?)"
        )->execute([
            $personaId, $institucion, $titulo,
            !empty($payload['anio_inicio']) ? (int) $payload['anio_inicio'] : null,
            !empty($payload['anio_fin'])    ? (int) $payload['anio_fin']    : null,
        ]);

        return ['status' => 'success', 'message' => 'Educación agregada.'];
    }

    public function actualizarEducacion(int $usuarioId, array $payload): array {
        $personaId = $this->idPersonaPorUsuario($usuarioId);
        if (!$personaId) return ['status' => 'error', 'message' => 'Perfil no encontrado.'];

        $id = (int) ($payload['id'] ?? 0);
        if (!$id) return ['status' => 'error', 'message' => 'Educación no indicada.'];

        $institucion = scTexto($payload['institucion'] ?? '', 190) ?? '';
        $titulo      = scTexto($payload['titulo']      ?? '', 190) ?? '';
        if (!$institucion || !$titulo) {
            return ['status' => 'error', 'message' => 'Institución y título son obligatorios.'];
        }

        $stmt = $this->pdo->prepare(
            "UPDATE sc_educacion SET institucion = ?, titulo = ?, anio_inicio = ?, anio_fin = ?
             WHERE id = ? AND persona_id = ?"
        );
        $stmt->execute([
            $institucion, $titulo,
            !empty($payload['anio_inicio']) ? (int) $payload['anio_inicio'] : null,
            !empty($payload['anio_fin'])    ? (int) $payload['anio_fin']    : null,
            $id, $personaId,
        ]);

        return ['status' => 'success', 'message' => 'Educación actualizada.'];
    }

    public function eliminarEducacion(int $usuarioId, int $id): array {
        return $this->eliminarDe('sc_educacion', $usuarioId, $id, 'Educación eliminada.');
    }

    // ── HABILIDADES ──────────────────────────────────────────
    public function agregarHabilidad(int $usuarioId, array $payload): array {
        $personaId = $this->idPersonaPorUsuario($usuarioId);
        if (!$personaId) return ['status' => 'error', 'message' => 'Perfil no encontrado.'];

        $habilidad = trim($payload['habilidad'] ?? '');
        if (!$habilidad) return ['status' => 'error', 'message' => 'La habilidad no puede estar vacía.'];
        if (mb_strlen($habilidad) > 120) $habilidad = mb_substr($habilidad, 0, 120);

        // Evitar duplicados exactos
        $stmt = $this->pdo->prepare("SELECT id FROM sc_habilidades WHERE persona_id = ? AND habilidad = ?");
        $stmt->execute([$personaId, $habilidad]);
        if ($stmt->fetch()) {
            return ['status' => 'error', 'message' => 'Esa habilidad ya está en tu perfil.'];
        }

        $this->pdo->prepare("INSERT INTO sc_habilidades (persona_id, habilidad) VALUES (?, ?)")
            ->execute([$personaId, $habilidad]);

        return ['status' => 'success', 'message' => 'Habilidad agregada.'];
    }

    public function eliminarHabilidad(int $usuarioId, int $id): array {
        return $this->eliminarDe('sc_habilidades', $usuarioId, $id, 'Habilidad eliminada.');
    }

    /** Borra una fila de una sección comprobando que sea del dueño del perfil. */
    private function eliminarDe(string $tabla, int $usuarioId, int $id, string $mensaje): array {
        $personaId = $this->idPersonaPorUsuario($usuarioId);
        if (!$personaId) return ['status' => 'error', 'message' => 'Perfil no encontrado.'];

        $stmt = $this->pdo->prepare("DELETE FROM {$tabla} WHERE id = ? AND persona_id = ?");
        $stmt->execute([$id, $personaId]);

        if ($stmt->rowCount() === 0) {
            return ['status' => 'error', 'message' => 'No se encontró el elemento a eliminar.'];
        }
        return ['status' => 'success', 'message' => $mensaje];
    }
}
