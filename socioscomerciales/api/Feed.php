<?php
/**
 * Socios Comerciales AVBA — Muro del portal
 *
 * Publicaciones con texto y/o imagen, y comentarios sobre ellas.
 *
 * El muro **es público**: cualquiera puede leerlo sin cuenta. Lo que lo hace
 * seguro es que nada se ve hasta que administración lo aprueba — sin esa
 * moderación, un muro abierto publicaría en la portada de AVBA el nombre y la
 * foto de cualquiera que se registrara, y lo que escribiera.
 *
 * Publicar y comentar sí exigen cuenta con el correo confirmado.
 */

class ScFeed {
    private PDO $pdo;

    private const MIMES_IMAGEN = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    /** Largos máximos, que son también los que valida el cliente. */
    private const MAX_TEXTO      = 3000;
    private const MAX_COMENTARIO = 1000;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Trozo de SQL que resuelve el autor.
     *
     * El nombre y la foto viven en dos tablas distintas según el tipo de
     * cuenta, así que se traen con dos LEFT JOIN y un COALESCE en vez de
     * duplicar la consulta por tipo.
     */
    private const AUTOR_SELECT = "
        u.id AS autor_id, u.tipo AS autor_tipo,
        COALESCE(p.nombre, e.nombre) AS autor_nombre,
        COALESCE(p.foto_url, e.logo_url) AS autor_foto,
        p.id AS autor_persona_id, e.id AS autor_empresa_id,
        u.correo_verificado AS autor_verificado";

    private const AUTOR_JOIN = "
        JOIN sc_usuarios u ON u.id = %s
        LEFT JOIN sc_personas p ON p.usuario_id = u.id
        LEFT JOIN sc_empresas e ON e.usuario_id = u.id";

    // ══════════════════════════════════════════════════════════
    //  LEER EL MURO
    // ══════════════════════════════════════════════════════════
    /**
     * @param array|null $usuario Sesión, o null si quien mira no tiene cuenta.
     *
     * Quien no tiene cuenta ve solo lo aprobado. Quien la tiene ve además lo
     * suyo pendiente o rechazado: si no, publicaría algo y desaparecería sin
     * explicación, y volvería a intentarlo pensando que falló.
     */
    public function listar(array $filtros, ?array $usuario = null): array {
        [$limite, $offset] = scPaginacion($filtros, 10, 30);

        $condicion = "u.activo = 1 AND pub.estado = 'aprobada'";
        $params    = [];

        if ($usuario) {
            $condicion = "u.activo = 1 AND (pub.estado = 'aprobada' OR pub.usuario_id = ?)";
            $params[]  = (int) $usuario['id'];
        }

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM sc_publicaciones pub
             JOIN sc_usuarios u ON u.id = pub.usuario_id
             WHERE {$condicion}"
        );
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        $join = sprintf(self::AUTOR_JOIN, 'pub.usuario_id');
        $stmt = $this->pdo->prepare(
            "SELECT pub.id, pub.texto, pub.imagen_url, pub.creado,
                    pub.estado, pub.moderado_motivo," . self::AUTOR_SELECT . ",
                    (SELECT COUNT(*) FROM sc_comentarios c WHERE c.publicacion_id = pub.id) AS n_comentarios
             FROM sc_publicaciones pub
             {$join}
             WHERE {$condicion}
             ORDER BY pub.creado DESC, pub.id DESC
             LIMIT {$limite} OFFSET {$offset}"
        );
        $stmt->execute($params);
        $publicaciones = $stmt->fetchAll();

        // Los dos últimos comentarios de cada publicación, en UNA consulta.
        // Una por publicación serían 10 consultas más solo para pintar la
        // primera pantalla del muro.
        if ($publicaciones) {
            $ids    = array_column($publicaciones, 'id');
            $marcas = implode(',', array_fill(0, count($ids), '?'));
            $joinC  = sprintf(self::AUTOR_JOIN, 'c.usuario_id');

            $s = $this->pdo->prepare(
                "SELECT c.id, c.publicacion_id, c.texto, c.creado," . self::AUTOR_SELECT . "
                 FROM sc_comentarios c
                 {$joinC}
                 WHERE c.publicacion_id IN ({$marcas}) AND u.activo = 1
                 ORDER BY c.creado DESC, c.id DESC"
            );
            $s->execute($ids);

            $porPublicacion = [];
            foreach ($s->fetchAll() as $c) {
                $pid = $c['publicacion_id'];
                if (!isset($porPublicacion[$pid])) $porPublicacion[$pid] = [];
                if (count($porPublicacion[$pid]) < 2) $porPublicacion[$pid][] = $c;
            }

            foreach ($publicaciones as &$pub) {
                // Se invierten para que se lean de más antiguo a más reciente,
                // que es como se lee una conversación.
                $pub['comentarios'] = array_reverse($porPublicacion[$pub['id']] ?? []);
            }
            unset($pub);
        }

        return [
            'status'         => 'success',
            'publicaciones'  => $publicaciones,
            'total'          => $total,
            'offset'         => $offset,
            'hay_mas'        => ($offset + count($publicaciones)) < $total,
        ];
    }

    /** Todos los comentarios de una publicación. */
    public function comentarios(int $publicacionId, ?array $usuario = null): array {
        if ($publicacionId <= 0) {
            return ['status' => 'error', 'message' => 'Publicación no indicada.'];
        }

        // Sin esto se podrían leer los comentarios de una publicación que
        // todavía no ha pasado por moderación, pidiéndola por su id.
        if (!$this->publicacionVisible($publicacionId, $usuario)) {
            return ['status' => 'error', 'message' => 'Esa publicación no está disponible.'];
        }

        $join = sprintf(self::AUTOR_JOIN, 'c.usuario_id');
        $stmt = $this->pdo->prepare(
            "SELECT c.id, c.publicacion_id, c.texto, c.creado," . self::AUTOR_SELECT . "
             FROM sc_comentarios c
             {$join}
             WHERE c.publicacion_id = ? AND u.activo = 1
             ORDER BY c.creado ASC, c.id ASC
             LIMIT 200"
        );
        $stmt->execute([$publicacionId]);

        return ['status' => 'success', 'comentarios' => $stmt->fetchAll()];
    }

    /** ¿Puede este visitante ver esta publicación? */
    private function publicacionVisible(int $publicacionId, ?array $usuario): bool {
        $stmt = $this->pdo->prepare(
            "SELECT pub.estado, pub.usuario_id
             FROM sc_publicaciones pub
             JOIN sc_usuarios u ON u.id = pub.usuario_id
             WHERE pub.id = ? AND u.activo = 1"
        );
        $stmt->execute([$publicacionId]);
        $pub = $stmt->fetch();

        if (!$pub) return false;
        if ($pub['estado'] === 'aprobada') return true;

        // Su autor y administración ven también lo pendiente
        return $usuario && (
            !empty($usuario['es_admin']) || (int) $pub['usuario_id'] === (int) $usuario['id']
        );
    }

    // ══════════════════════════════════════════════════════════
    //  PUBLICAR
    // ══════════════════════════════════════════════════════════

    /**
     * Crea una publicación. El texto y la imagen son ambos opcionales, pero
     * hace falta al menos uno: una publicación vacía no dice nada.
     *
     * Llega como multipart, así que $payload viene de $_POST y todo es texto.
     */
    public function publicar(int $usuarioId, array $payload, array $file): array {
        $texto = scTexto($payload['texto'] ?? null, self::MAX_TEXTO);
        $hayArchivo = !empty($file) && isset($file['error']) && $file['error'] !== UPLOAD_ERR_NO_FILE;

        if (!$texto && !$hayArchivo) {
            return ['status' => 'error', 'message' => 'Escribe algo o adjunta una fotografía.'];
        }

        // Un muro sin freno es un tablón de spam en cuestión de horas.
        if (!scLimite($this->pdo, 'publicar', (string) $usuarioId, 10, 3600)) {
            return [
                'status'  => 'error',
                'message' => 'Has publicado varias veces en la última hora. Espera un poco antes de publicar más.',
            ];
        }

        $imagen = null;
        if ($hayArchivo) {
            try {
                // scGuardarArchivo redibuja la imagen con GD: se va el EXIF,
                // que en una foto de teléfono lleva la ubicación exacta.
                $imagen = scGuardarArchivo($file, 'feed', self::MIMES_IMAGEN, 6 * 1024 * 1024);
            } catch (RuntimeException $e) {
                return ['status' => 'error', 'message' => $e->getMessage()];
            }
        }

        $this->pdo->prepare(
            "INSERT INTO sc_publicaciones (usuario_id, texto, imagen_url) VALUES (?, ?, ?)"
        )->execute([$usuarioId, $texto, $imagen]);

        return [
            'status'  => 'success',
            'message' => 'Publicación enviada. Aparecerá en el muro en cuanto AVBA la revise.',
            'id'      => (int) $this->pdo->lastInsertId(),
        ];
    }

    public function comentar(int $usuarioId, array $payload): array {
        $publicacionId = (int) ($payload['publicacion_id'] ?? 0);
        $texto = scTexto($payload['texto'] ?? null, self::MAX_COMENTARIO);

        if (!$publicacionId) return ['status' => 'error', 'message' => 'Publicación no indicada.'];
        if (!$texto)         return ['status' => 'error', 'message' => 'Escribe tu comentario.'];

        if (!scLimite($this->pdo, 'comentar', (string) $usuarioId, 40, 3600)) {
            return ['status' => 'error', 'message' => 'Has comentado muchas veces en la última hora. Espera un poco.'];
        }

        // Solo se comenta lo que ya pasó por moderación: comentar algo
        // pendiente dejaría respuestas colgando de una publicación que
        // quizá nunca llegue a verse.
        $stmt = $this->pdo->prepare(
            "SELECT pub.id FROM sc_publicaciones pub
             JOIN sc_usuarios u ON u.id = pub.usuario_id
             WHERE pub.id = ? AND u.activo = 1 AND pub.estado = 'aprobada'"
        );
        $stmt->execute([$publicacionId]);
        if (!$stmt->fetchColumn()) {
            return ['status' => 'error', 'message' => 'Esa publicación no está disponible para comentar.'];
        }

        $this->pdo->prepare(
            "INSERT INTO sc_comentarios (publicacion_id, usuario_id, texto) VALUES (?, ?, ?)"
        )->execute([$publicacionId, $usuarioId, $texto]);

        return ['status' => 'success', 'message' => 'Comentario publicado.'];
    }

    // ══════════════════════════════════════════════════════════
    //  BORRAR
    // ══════════════════════════════════════════════════════════

    // ══════════════════════════════════════════════════════════
    //  MODERACIÓN
    // ══════════════════════════════════════════════════════════

    /** Publicaciones a la espera de revisión, las más antiguas primero. */
    public function pendientes(array $filtros): array {
        $estado = strtolower(trim($filtros['estado'] ?? 'pendiente'));
        if (!in_array($estado, ['pendiente', 'aprobada', 'rechazada'], true)) {
            $estado = 'pendiente';
        }

        [$limite, $offset] = scPaginacion($filtros, 20, 60);

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM sc_publicaciones pub
             JOIN sc_usuarios u ON u.id = pub.usuario_id
             WHERE pub.estado = ?"
        );
        $stmt->execute([$estado]);
        $total = (int) $stmt->fetchColumn();

        $join = sprintf(self::AUTOR_JOIN, 'pub.usuario_id');
        $stmt = $this->pdo->prepare(
            "SELECT pub.id, pub.texto, pub.imagen_url, pub.creado,
                    pub.estado, pub.moderado_motivo, pub.moderado_fecha," . self::AUTOR_SELECT . ",
                    u.correo AS autor_correo
             FROM sc_publicaciones pub
             {$join}
             WHERE pub.estado = ?
             ORDER BY pub.creado " . ($estado === 'pendiente' ? 'ASC' : 'DESC') . ", pub.id ASC
             LIMIT {$limite} OFFSET {$offset}"
        );
        $stmt->execute([$estado]);
        $publicaciones = $stmt->fetchAll();

        return [
            'status'        => 'success',
            'publicaciones' => $publicaciones,
            'total'         => $total,
            'offset'        => $offset,
            'hay_mas'       => ($offset + count($publicaciones)) < $total,
        ];
    }

    /** Cuántas esperan revisión, para la insignia del panel. */
    public function contarPendientes(): int {
        try {
            return (int) $this->pdo->query(
                "SELECT COUNT(*) FROM sc_publicaciones WHERE estado = 'pendiente'"
            )->fetchColumn();
        } catch (PDOException $e) {
            return 0;
        }
    }

    /**
     * Aprueba o rechaza una publicación.
     *
     * Rechazar no borra: el autor sigue viendo la suya con el motivo, así
     * sabe por qué no salió. Para que desaparezca del todo hay que borrarla.
     */
    public function moderar(array $admin, int $id, array $payload): array {
        $decision = strtolower(trim($payload['decision'] ?? ''));
        if (!in_array($decision, ['aprobar', 'rechazar'], true)) {
            return ['status' => 'error', 'message' => 'Decisión no válida.'];
        }
        if ($id <= 0) return ['status' => 'error', 'message' => 'Publicación no indicada.'];

        $stmt = $this->pdo->prepare(
            "SELECT pub.id, pub.estado, u.correo
             FROM sc_publicaciones pub
             JOIN sc_usuarios u ON u.id = pub.usuario_id
             WHERE pub.id = ?"
        );
        $stmt->execute([$id]);
        $pub = $stmt->fetch();
        if (!$pub) return ['status' => 'error', 'message' => 'Esa publicación ya no existe.'];

        $estado = $decision === 'aprobar' ? 'aprobada' : 'rechazada';
        $motivo = $decision === 'rechazar' ? scTexto($payload['motivo'] ?? null, 255) : null;

        $this->pdo->prepare(
            "UPDATE sc_publicaciones
             SET estado = ?, moderado_por = ?, moderado_fecha = NOW(), moderado_motivo = ?
             WHERE id = ?"
        )->execute([$estado, (int) $admin['id'], $motivo, $id]);

        try {
            $this->pdo->prepare(
                "INSERT INTO sc_admin_log
                    (admin_id, admin_correo, accion, usuario_id, usuario_correo, detalle, ip)
                 VALUES (?, ?, ?, NULL, ?, ?, ?)"
            )->execute([
                (int) $admin['id'], $admin['correo'], 'muro_' . $decision,
                $pub['correo'], 'Publicación #' . $id . ($motivo ? ' — ' . $motivo : ''),
                scIpCliente(),
            ]);
        } catch (PDOException $e) {
            error_log('ScFeed::moderar bitacora: ' . $e->getMessage());
        }

        return [
            'status'  => 'success',
            'message' => $decision === 'aprobar'
                ? 'Publicación aprobada: ya se ve en el muro.'
                : 'Publicación rechazada. Su autor verá el motivo.',
        ];
    }

    /**
     * Borra una publicación. Puede hacerlo su autor o administración.
     *
     * El WHERE lleva el usuario_id salvo para administración: así el intento
     * de borrar lo de otro no borra nada en vez de necesitar una comprobación
     * aparte que se pueda olvidar.
     */
    public function eliminarPublicacion(array $usuario, int $id): array {
        if ($id <= 0) return ['status' => 'error', 'message' => 'Publicación no indicada.'];

        $esAdmin = !empty($usuario['es_admin']);

        $stmt = $this->pdo->prepare(
            "SELECT id, usuario_id, imagen_url FROM sc_publicaciones WHERE id = ?"
        );
        $stmt->execute([$id]);
        $pub = $stmt->fetch();

        if (!$pub) return ['status' => 'error', 'message' => 'Esa publicación ya no existe.'];
        if (!$esAdmin && (int) $pub['usuario_id'] !== (int) $usuario['id']) {
            return ['status' => 'error', 'message' => 'Solo puedes borrar tus propias publicaciones.'];
        }

        $this->pdo->prepare("DELETE FROM sc_publicaciones WHERE id = ?")->execute([$id]);

        // La cascada se lleva los comentarios, pero el archivo no
        scBorrarArchivo($pub['imagen_url']);

        return ['status' => 'success', 'message' => 'Publicación eliminada.'];
    }

    /**
     * Borra un comentario. Además del autor y de administración, puede
     * borrarlo el dueño de la publicación: si alguien le deja algo ofensivo
     * en su propio muro, no debería tener que esperar a que lo moderen.
     */
    public function eliminarComentario(array $usuario, int $id): array {
        if ($id <= 0) return ['status' => 'error', 'message' => 'Comentario no indicado.'];

        $stmt = $this->pdo->prepare(
            "SELECT c.id, c.usuario_id, pub.usuario_id AS dueno_publicacion
             FROM sc_comentarios c
             JOIN sc_publicaciones pub ON pub.id = c.publicacion_id
             WHERE c.id = ?"
        );
        $stmt->execute([$id]);
        $com = $stmt->fetch();

        if (!$com) return ['status' => 'error', 'message' => 'Ese comentario ya no existe.'];

        $yo = (int) $usuario['id'];
        $puede = !empty($usuario['es_admin'])
              || (int) $com['usuario_id'] === $yo
              || (int) $com['dueno_publicacion'] === $yo;

        if (!$puede) {
            return ['status' => 'error', 'message' => 'No puedes borrar ese comentario.'];
        }

        $this->pdo->prepare("DELETE FROM sc_comentarios WHERE id = ?")->execute([$id]);

        return ['status' => 'success', 'message' => 'Comentario eliminado.'];
    }
}
