<?php
/**
 * Socios Comerciales AVBA — Perfil de empresa
 */

class ScEmpresas {
    private PDO $pdo;

    private const MIMES_IMAGEN = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function idEmpresaPorUsuario(int $usuarioId): ?int {
        $stmt = $this->pdo->prepare("SELECT id FROM sc_empresas WHERE usuario_id = ?");
        $stmt->execute([$usuarioId]);
        $id = $stmt->fetchColumn();
        return $id ? (int) $id : null;
    }

    // ── PERFIL PROPIO ────────────────────────────────────────
    public function obtenerPerfil(int $usuarioId): array {
        $stmt = $this->pdo->prepare(
            "SELECT e.id, e.nombre, e.giro, e.descripcion, e.sitio_web, e.logo_url, e.ubicacion,
                    u.correo, u.correo_verificado
             FROM sc_empresas e
             JOIN sc_usuarios u ON u.id = e.usuario_id
             WHERE e.usuario_id = ?"
        );
        $stmt->execute([$usuarioId]);
        $empresa = $stmt->fetch();

        if (!$empresa) {
            return ['status' => 'error', 'message' => 'Perfil de empresa no encontrado.'];
        }

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM sc_vacantes WHERE empresa_id = ? AND estatus = 'abierta'"
        );
        $stmt->execute([$empresa['id']]);
        $empresa['vacantes_abiertas'] = (int) $stmt->fetchColumn();

        return ['status' => 'success', 'empresa' => $empresa];
    }

    // ── PERFIL PÚBLICO ───────────────────────────────────────
    public function obtenerPerfilPublico(int $empresaId): array {
        $stmt = $this->pdo->prepare(
            "SELECT e.id, e.nombre, e.giro, e.descripcion, e.sitio_web, e.logo_url, e.ubicacion,
                    u.correo_verificado
             FROM sc_empresas e
             JOIN sc_usuarios u ON u.id = e.usuario_id
             WHERE e.id = ? AND u.activo = 1"
        );
        $stmt->execute([$empresaId]);
        $empresa = $stmt->fetch();

        if (!$empresa) {
            return ['status' => 'error', 'message' => 'Empresa no encontrada.'];
        }

        $stmt = $this->pdo->prepare(
            "SELECT id, titulo, ubicacion, modalidad, salario, creado
             FROM sc_vacantes WHERE empresa_id = ? AND estatus = 'abierta'
             ORDER BY creado DESC LIMIT 20"
        );
        $stmt->execute([$empresaId]);

        return ['status' => 'success', 'empresa' => $empresa, 'vacantes' => $stmt->fetchAll()];
    }

    // ── DIRECTORIO DE EMPRESAS ───────────────────────────────
    public function listarEmpresas(string $texto = '', array $filtros = []): array {
        $where  = ['u.activo = 1'];
        $params = [];

        if (trim($texto) !== '') {
            $where[]  = '(e.nombre LIKE ? OR e.giro LIKE ?)';
            $like     = '%' . scEscaparLike(trim($texto)) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $condiciones = implode(' AND ', $where);
        [$limite, $offset] = scPaginacion($filtros);

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM sc_empresas e
             JOIN sc_usuarios u ON u.id = e.usuario_id
             WHERE {$condiciones}"
        );
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        $stmt = $this->pdo->prepare(
            "SELECT e.id, e.nombre, e.giro, e.ubicacion, e.logo_url, u.correo_verificado,
                    (SELECT COUNT(*) FROM sc_vacantes v WHERE v.empresa_id = e.id AND v.estatus = 'abierta') AS vacantes
             FROM sc_empresas e
             JOIN sc_usuarios u ON u.id = e.usuario_id
             WHERE {$condiciones}
             ORDER BY vacantes DESC, e.nombre ASC
             LIMIT {$limite} OFFSET {$offset}"
        );
        $stmt->execute($params);
        $empresas = $stmt->fetchAll();

        return [
            'status'   => 'success',
            'empresas' => $empresas,
            'total'    => $total,
            'offset'   => $offset,
            'hay_mas'  => ($offset + count($empresas)) < $total,
        ];
    }

    // ── ACTUALIZAR DATOS ─────────────────────────────────────
    public function actualizarPerfil(int $usuarioId, array $payload): array {
        if (isset($payload['nombre']) && trim($payload['nombre']) === '') {
            return ['status' => 'error', 'message' => 'El nombre de la empresa no puede quedar vacío.'];
        }

        // Normalizar el sitio web para que el enlace funcione
        if (isset($payload['sitio_web'])) {
            $sitio = trim((string) $payload['sitio_web']);
            if ($sitio !== '') {
                if (!preg_match('#^https?://#i', $sitio)) $sitio = 'https://' . $sitio;
                // Sin validar, un valor con comillas o "javascript:" acabaría
                // en un href del perfil público.
                if (!filter_var($sitio, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $sitio)) {
                    return ['status' => 'error', 'message' => 'El sitio web no es una dirección válida.'];
                }
            }
            $payload['sitio_web'] = $sitio;
        }

        $campos = [
            'nombre' => 190, 'giro' => 190, 'descripcion' => 5000,
            'sitio_web' => 255, 'ubicacion' => 190,
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
        $this->pdo->prepare("UPDATE sc_empresas SET " . implode(', ', $sets) . " WHERE usuario_id = ?")
            ->execute($params);

        return ['status' => 'success', 'message' => 'Perfil actualizado.'];
    }

    // ── SUBIR LOGO ───────────────────────────────────────────
    public function subirLogo(int $usuarioId, array $file): array {
        try {
            $url = scGuardarArchivo($file, 'logos', self::MIMES_IMAGEN, 6 * 1024 * 1024);
        } catch (RuntimeException $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }

        $stmt = $this->pdo->prepare("SELECT logo_url FROM sc_empresas WHERE usuario_id = ?");
        $stmt->execute([$usuarioId]);
        $anterior = $stmt->fetchColumn() ?: null;

        $this->pdo->prepare("UPDATE sc_empresas SET logo_url = ? WHERE usuario_id = ?")
            ->execute([$url, $usuarioId]);
        scBorrarArchivo($anterior);

        return ['status' => 'success', 'message' => 'Logo actualizado.', 'logo_url' => $url];
    }
}
