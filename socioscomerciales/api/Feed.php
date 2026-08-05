<?php
/**
 * Socios Comerciales AVBA — Muro del portal
 *
 * Publicaciones con texto y/o imagen, y comentarios sobre ellas.
 *
 * El muro **no es público**: leerlo exige sesión con el correo confirmado,
 * igual que el resto de pantallas que muestran datos de otras cuentas. En el
 * aviso de privacidad se dice que el perfil de un candidato lo ven "las
 * cuentas registradas en el portal", y un muro abierto a internet con nombres
 * y fotos contradiría eso.
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
    public function listar(array $filtros): array {
        [$limite, $offset] = scPaginacion($filtros, 10, 30);

        $total = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM sc_publicaciones pub
             JOIN sc_usuarios u ON u.id = pub.usuario_id
             WHERE u.activo = 1"
        )->fetchColumn();

        $join = sprintf(self::AUTOR_JOIN, 'pub.usuario_id');
        $stmt = $this->pdo->query(
            "SELECT pub.id, pub.texto, pub.imagen_url, pub.creado," . self::AUTOR_SELECT . ",
                    (SELECT COUNT(*) FROM sc_comentarios c WHERE c.publicacion_id = pub.id) AS n_comentarios
             FROM sc_publicaciones pub
             {$join}
             WHERE u.activo = 1
             ORDER BY pub.creado DESC, pub.id DESC
             LIMIT {$limite} OFFSET {$offset}"
        );
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
    public function comentarios(int $publicacionId): array {
        if ($publicacionId <= 0) {
            return ['status' => 'error', 'message' => 'Publicación no indicada.'];
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
            'message' => 'Publicación creada.',
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

        // La publicación debe existir y ser de una cuenta activa
        $stmt = $this->pdo->prepare(
            "SELECT pub.id FROM sc_publicaciones pub
             JOIN sc_usuarios u ON u.id = pub.usuario_id
             WHERE pub.id = ? AND u.activo = 1"
        );
        $stmt->execute([$publicacionId]);
        if (!$stmt->fetchColumn()) {
            return ['status' => 'error', 'message' => 'Esa publicación ya no está disponible.'];
        }

        $this->pdo->prepare(
            "INSERT INTO sc_comentarios (publicacion_id, usuario_id, texto) VALUES (?, ?, ?)"
        )->execute([$publicacionId, $usuarioId, $texto]);

        return ['status' => 'success', 'message' => 'Comentario publicado.'];
    }

    // ══════════════════════════════════════════════════════════
    //  BORRAR
    // ══════════════════════════════════════════════════════════

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
