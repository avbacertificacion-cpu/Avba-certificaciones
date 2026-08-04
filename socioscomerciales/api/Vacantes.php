<?php
/**
 * Socios Comerciales AVBA — Vacantes y postulaciones
 */

class ScVacantes {
    private PDO $pdo;

    private const MODALIDADES = ['presencial', 'remoto', 'hibrido'];
    private const ESTATUS_POSTULACION = ['enviada', 'en_revision', 'aceptada', 'rechazada'];

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    private function idEmpresaPorUsuario(int $usuarioId): ?int {
        $stmt = $this->pdo->prepare("SELECT id FROM sc_empresas WHERE usuario_id = ?");
        $stmt->execute([$usuarioId]);
        $id = $stmt->fetchColumn();
        return $id ? (int) $id : null;
    }

    private function idPersonaPorUsuario(int $usuarioId): ?int {
        $stmt = $this->pdo->prepare("SELECT id FROM sc_personas WHERE usuario_id = ?");
        $stmt->execute([$usuarioId]);
        $id = $stmt->fetchColumn();
        return $id ? (int) $id : null;
    }

    // ══════════════════════════════════════════════════════════
    //  VACANTES — lado empresa
    // ══════════════════════════════════════════════════════════

    public function crear(int $usuarioId, array $payload): array {
        $empresaId = $this->idEmpresaPorUsuario($usuarioId);
        if (!$empresaId) return ['status' => 'error', 'message' => 'Perfil de empresa no encontrado.'];

        $titulo = scTexto($payload['titulo'] ?? '', 190) ?? '';
        if (!$titulo) return ['status' => 'error', 'message' => 'El título de la vacante es obligatorio.'];

        $modalidad = strtolower(trim($payload['modalidad'] ?? 'presencial'));
        if (!in_array($modalidad, self::MODALIDADES, true)) $modalidad = 'presencial';

        $this->pdo->prepare(
            "INSERT INTO sc_vacantes (empresa_id, titulo, descripcion, ubicacion, modalidad, salario, estatus)
             VALUES (?, ?, ?, ?, ?, ?, 'abierta')"
        )->execute([
            $empresaId,
            $titulo,
            scTexto($payload['descripcion'] ?? null, 8000),
            scTexto($payload['ubicacion']   ?? null, 190),
            $modalidad,
            scTexto($payload['salario']     ?? null, 100),
        ]);

        return ['status' => 'success', 'message' => 'Vacante publicada.', 'id' => (int) $this->pdo->lastInsertId()];
    }

    public function actualizar(int $usuarioId, array $payload): array {
        $empresaId = $this->idEmpresaPorUsuario($usuarioId);
        if (!$empresaId) return ['status' => 'error', 'message' => 'Perfil de empresa no encontrado.'];

        $id = (int) ($payload['id'] ?? 0);
        if (!$id) return ['status' => 'error', 'message' => 'Vacante no indicada.'];

        if (isset($payload['titulo']) && trim($payload['titulo']) === '') {
            return ['status' => 'error', 'message' => 'El título no puede quedar vacío.'];
        }

        $sets   = [];
        $params = [];

        foreach (['titulo' => 190, 'descripcion' => 8000, 'ubicacion' => 190, 'salario' => 100] as $campo => $max) {
            if (!isset($payload[$campo])) continue;
            $sets[]   = "{$campo} = ?";
            $params[] = scTexto((string) $payload[$campo], $max);
        }
        if (isset($payload['modalidad'])) {
            $modalidad = strtolower(trim($payload['modalidad']));
            if (in_array($modalidad, self::MODALIDADES, true)) {
                $sets[]   = 'modalidad = ?';
                $params[] = $modalidad;
            }
        }
        if (isset($payload['estatus'])) {
            $estatus = strtolower(trim($payload['estatus']));
            if (in_array($estatus, ['abierta', 'cerrada'], true)) {
                $sets[]   = 'estatus = ?';
                $params[] = $estatus;
            }
        }

        if (empty($sets)) return ['status' => 'success', 'message' => 'Sin cambios.'];

        $params[] = $id;
        $params[] = $empresaId;

        $stmt = $this->pdo->prepare(
            "UPDATE sc_vacantes SET " . implode(', ', $sets) . " WHERE id = ? AND empresa_id = ?"
        );
        $stmt->execute($params);

        return ['status' => 'success', 'message' => 'Vacante actualizada.'];
    }

    public function eliminar(int $usuarioId, int $id): array {
        $empresaId = $this->idEmpresaPorUsuario($usuarioId);
        if (!$empresaId) return ['status' => 'error', 'message' => 'Perfil de empresa no encontrado.'];

        $stmt = $this->pdo->prepare("DELETE FROM sc_vacantes WHERE id = ? AND empresa_id = ?");
        $stmt->execute([$id, $empresaId]);

        if ($stmt->rowCount() === 0) {
            return ['status' => 'error', 'message' => 'No se encontró la vacante.'];
        }
        return ['status' => 'success', 'message' => 'Vacante eliminada.'];
    }

    /** Vacantes de la empresa con el conteo de postulaciones. */
    public function listarMisVacantes(int $usuarioId): array {
        $empresaId = $this->idEmpresaPorUsuario($usuarioId);
        if (!$empresaId) return ['status' => 'error', 'message' => 'Perfil de empresa no encontrado.'];

        $stmt = $this->pdo->prepare(
            "SELECT v.id, v.titulo, v.descripcion, v.ubicacion, v.modalidad, v.salario, v.estatus, v.creado,
                    (SELECT COUNT(*) FROM sc_postulaciones p WHERE p.vacante_id = v.id) AS postulaciones,
                    (SELECT COUNT(*) FROM sc_postulaciones p WHERE p.vacante_id = v.id AND p.estatus = 'enviada') AS nuevas
             FROM sc_vacantes v
             WHERE v.empresa_id = ?
             ORDER BY v.creado DESC
             LIMIT 300"
        );
        $stmt->execute([$empresaId]);

        return ['status' => 'success', 'vacantes' => $stmt->fetchAll()];
    }

    // ══════════════════════════════════════════════════════════
    //  VACANTES — lado candidato / público
    // ══════════════════════════════════════════════════════════

    public function buscar(array $filtros, ?int $personaId = null): array {
        $texto     = trim($filtros['texto']     ?? '');
        $ubicacion = trim($filtros['ubicacion'] ?? '');
        $modalidad = strtolower(trim($filtros['modalidad'] ?? ''));

        $where  = ["v.estatus = 'abierta'", 'u.activo = 1'];
        $params = [];

        if ($texto !== '') {
            $where[] = '(v.titulo LIKE ? OR v.descripcion LIKE ? OR e.nombre LIKE ?)';
            $like    = '%' . scEscaparLike($texto) . '%';
            array_push($params, $like, $like, $like);
        }
        if ($ubicacion !== '') {
            $where[]  = 'v.ubicacion LIKE ?';
            $params[] = '%' . scEscaparLike($ubicacion) . '%';
        }
        if (in_array($modalidad, self::MODALIDADES, true)) {
            $where[]  = 'v.modalidad = ?';
            $params[] = $modalidad;
        }

        $condiciones = implode(' AND ', $where);
        [$limite, $offset] = scPaginacion($filtros);

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM sc_vacantes v
             JOIN sc_empresas e ON e.id = v.empresa_id
             JOIN sc_usuarios u ON u.id = e.usuario_id
             WHERE {$condiciones}"
        );
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        $sql = "SELECT v.id, v.titulo, v.descripcion, v.ubicacion, v.modalidad, v.salario, v.creado,
                       e.id AS empresa_id, e.nombre AS empresa, e.logo_url, e.giro,
                       u.correo_verificado AS empresa_verificada
                FROM sc_vacantes v
                JOIN sc_empresas e ON e.id = v.empresa_id
                JOIN sc_usuarios u ON u.id = e.usuario_id
                WHERE {$condiciones}
                ORDER BY v.creado DESC
                LIMIT {$limite} OFFSET {$offset}";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $vacantes = $stmt->fetchAll();

        // Marcar a cuáles ya se postuló el candidato
        if ($personaId && $vacantes) {
            $stmt = $this->pdo->prepare("SELECT vacante_id FROM sc_postulaciones WHERE persona_id = ?");
            $stmt->execute([$personaId]);
            $postuladas = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));

            foreach ($vacantes as &$v) {
                $v['ya_postulado'] = isset($postuladas[$v['id']]);
            }
            unset($v);
        }

        return [
            'status'   => 'success',
            'vacantes' => $vacantes,
            'total'    => $total,
            'offset'   => $offset,
            'hay_mas'  => ($offset + count($vacantes)) < $total,
        ];
    }

    public function obtener(int $id, ?int $personaId = null): array {
        $stmt = $this->pdo->prepare(
            "SELECT v.id, v.titulo, v.descripcion, v.ubicacion, v.modalidad, v.salario, v.estatus, v.creado,
                    e.id AS empresa_id, e.nombre AS empresa, e.logo_url, e.giro, e.sitio_web,
                    u.correo_verificado AS empresa_verificada
             FROM sc_vacantes v
             JOIN sc_empresas e ON e.id = v.empresa_id
             JOIN sc_usuarios u ON u.id = e.usuario_id
             WHERE v.id = ? AND u.activo = 1"
        );
        $stmt->execute([$id]);
        $vacante = $stmt->fetch();

        if (!$vacante) return ['status' => 'error', 'message' => 'Vacante no encontrada.'];

        if ($personaId) {
            $stmt = $this->pdo->prepare("SELECT estatus FROM sc_postulaciones WHERE vacante_id = ? AND persona_id = ?");
            $stmt->execute([$id, $personaId]);
            $vacante['mi_postulacion'] = $stmt->fetchColumn() ?: null;
        }

        return ['status' => 'success', 'vacante' => $vacante];
    }

    // ══════════════════════════════════════════════════════════
    //  POSTULACIONES
    // ══════════════════════════════════════════════════════════

    public function postular(int $usuarioId, array $payload): array {
        $personaId = $this->idPersonaPorUsuario($usuarioId);
        if (!$personaId) return ['status' => 'error', 'message' => 'Perfil de candidato no encontrado.'];

        $vacanteId = (int) ($payload['vacante_id'] ?? 0);
        if (!$vacanteId) return ['status' => 'error', 'message' => 'Vacante no indicada.'];

        // La empresa debe seguir activa: sin el JOIN se podía uno postular a
        // la vacante de una cuenta ya dada de baja, y ese aviso no llegaba
        // a nadie.
        $stmt = $this->pdo->prepare(
            "SELECT v.estatus FROM sc_vacantes v
             JOIN sc_empresas e ON e.id = v.empresa_id
             JOIN sc_usuarios u ON u.id = e.usuario_id
             WHERE v.id = ? AND u.activo = 1"
        );
        $stmt->execute([$vacanteId]);
        $estatus = $stmt->fetchColumn();

        if (!$estatus)               return ['status' => 'error', 'message' => 'La vacante no existe.'];
        if ($estatus !== 'abierta')  return ['status' => 'error', 'message' => 'Esta vacante ya está cerrada.'];

        $stmt = $this->pdo->prepare("SELECT id FROM sc_postulaciones WHERE vacante_id = ? AND persona_id = ?");
        $stmt->execute([$vacanteId, $personaId]);
        if ($stmt->fetch()) {
            return ['status' => 'error', 'message' => 'Ya te postulaste a esta vacante.'];
        }

        // Dos clics seguidos podían colarse entre la comprobación de arriba y
        // el INSERT; el índice único de la tabla lo corta, pero hay que
        // traducir ese error a un mensaje entendible en vez de un 500.
        try {
            $this->pdo->prepare(
                "INSERT INTO sc_postulaciones (vacante_id, persona_id, mensaje, estatus) VALUES (?, ?, ?, 'enviada')"
            )->execute([$vacanteId, $personaId, scTexto($payload['mensaje'] ?? null, 2000)]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                return ['status' => 'error', 'message' => 'Ya te postulaste a esta vacante.'];
            }
            throw $e;
        }

        $this->avisarEmpresaNuevaPostulacion($vacanteId, $personaId);

        return ['status' => 'success', 'message' => '¡Postulación enviada!'];
    }

    /** Postulaciones del candidato con los datos de la vacante. */
    public function misPostulaciones(int $usuarioId): array {
        $personaId = $this->idPersonaPorUsuario($usuarioId);
        if (!$personaId) return ['status' => 'error', 'message' => 'Perfil de candidato no encontrado.'];

        $stmt = $this->pdo->prepare(
            "SELECT p.id, p.estatus, p.fecha, p.mensaje,
                    v.id AS vacante_id, v.titulo, v.ubicacion, v.modalidad, v.salario, v.estatus AS vacante_estatus,
                    e.id AS empresa_id, e.nombre AS empresa, e.logo_url
             FROM sc_postulaciones p
             JOIN sc_vacantes v ON v.id = p.vacante_id
             JOIN sc_empresas e ON e.id = v.empresa_id
             WHERE p.persona_id = ?
             ORDER BY p.fecha DESC
             LIMIT 300"
        );
        $stmt->execute([$personaId]);

        return ['status' => 'success', 'postulaciones' => $stmt->fetchAll()];
    }

    /** Postulaciones recibidas en una vacante de la empresa. */
    public function postulacionesDeVacante(int $usuarioId, int $vacanteId): array {
        $empresaId = $this->idEmpresaPorUsuario($usuarioId);
        if (!$empresaId) return ['status' => 'error', 'message' => 'Perfil de empresa no encontrado.'];

        // Comprobar que la vacante sea de esta empresa
        $stmt = $this->pdo->prepare("SELECT titulo FROM sc_vacantes WHERE id = ? AND empresa_id = ?");
        $stmt->execute([$vacanteId, $empresaId]);
        $titulo = $stmt->fetchColumn();
        if (!$titulo) return ['status' => 'error', 'message' => 'Vacante no encontrada.'];

        $stmt = $this->pdo->prepare(
            "SELECT p.id, p.estatus, p.fecha, p.mensaje,
                    per.id AS persona_id, per.nombre, per.headline, per.ubicacion,
                    per.foto_url, per.cv_url, u.correo, u.correo_verificado
             FROM sc_postulaciones p
             JOIN sc_personas per ON per.id = p.persona_id
             JOIN sc_usuarios u   ON u.id = per.usuario_id
             WHERE p.vacante_id = ?
             ORDER BY FIELD(p.estatus,'enviada','en_revision','aceptada','rechazada'), p.fecha DESC
             LIMIT 300"
        );
        $stmt->execute([$vacanteId]);

        return [
            'status'        => 'success',
            'vacante'       => ['id' => $vacanteId, 'titulo' => $titulo],
            'postulaciones' => $stmt->fetchAll(),
        ];
    }

    public function cambiarEstatusPostulacion(int $usuarioId, array $payload): array {
        $empresaId = $this->idEmpresaPorUsuario($usuarioId);
        if (!$empresaId) return ['status' => 'error', 'message' => 'Perfil de empresa no encontrado.'];

        $id      = (int) ($payload['id'] ?? 0);
        $estatus = strtolower(trim($payload['estatus'] ?? ''));

        if (!$id) return ['status' => 'error', 'message' => 'Postulación no indicada.'];
        if (!in_array($estatus, self::ESTATUS_POSTULACION, true)) {
            return ['status' => 'error', 'message' => 'Estatus no válido.'];
        }

        // Solo si la postulación pertenece a una vacante de esta empresa
        $stmt = $this->pdo->prepare(
            "UPDATE sc_postulaciones p
             JOIN sc_vacantes v ON v.id = p.vacante_id
             SET p.estatus = ?
             WHERE p.id = ? AND v.empresa_id = ?"
        );
        $stmt->execute([$estatus, $id, $empresaId]);

        if ($stmt->rowCount() === 0) {
            return ['status' => 'success', 'message' => 'Sin cambios.'];
        }

        $this->avisarCandidatoCambioEstatus($id, $estatus);

        return ['status' => 'success', 'message' => 'Estatus actualizado.'];
    }

    /**
     * Avisa por correo a varios postulantes de que sus documentos están en
     * revisión, y opcionalmente los marca como "en revisión".
     *
     * Solo alcanza a postulaciones de vacantes de esta empresa: la consulta
     * ya filtra por empresa_id, así que meter el id de la postulación de otra
     * empresa simplemente no devuelve fila y se cuenta como omitida.
     *
     * Es la única acción del portal que manda muchos correos de golpe, así
     * que va con tope (SC_MAX_AVISOS) y con límite por hora: un envío masivo
     * repetido acabaría con el dominio marcado como spam.
     */
    public function avisarRevision(int $usuarioId, array $payload): array {
        $empresaId = $this->idEmpresaPorUsuario($usuarioId);
        if (!$empresaId) return ['status' => 'error', 'message' => 'Perfil de empresa no encontrado.'];

        // Se leen sin tope para poder avisar si venían de más, en vez de
        // recortar en silencio y dejar a unos cuantos sin correo.
        $pedidos = scListaIds($payload['ids'] ?? '', 500);
        if (!$pedidos) {
            return ['status' => 'error', 'message' => 'No seleccionaste a ningún candidato.'];
        }
        if (count($pedidos) > SC_MAX_AVISOS) {
            return [
                'status'  => 'error',
                'message' => 'Selecciona como máximo ' . SC_MAX_AVISOS . ' candidatos por envío. '
                           . 'Enviar más de golpe hace que los correos acaben marcados como spam.',
            ];
        }
        $ids = $pedidos;

        if (!scLimite($this->pdo, 'avisos', (string) $usuarioId, 6, 3600)) {
            return [
                'status'  => 'error',
                'message' => 'Has enviado varios avisos masivos en la última hora. Espera un poco antes de mandar más.',
            ];
        }

        $marcar = !empty($payload['marcar_revision']) && $payload['marcar_revision'] !== 'false';
        $nota   = scTexto($payload['nota'] ?? null, 1000);

        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT po.id, po.estatus, per.nombre, u.correo, v.titulo, e.nombre AS empresa
             FROM sc_postulaciones po
             JOIN sc_vacantes v  ON v.id = po.vacante_id
             JOIN sc_empresas e  ON e.id = v.empresa_id
             JOIN sc_personas per ON per.id = po.persona_id
             JOIN sc_usuarios u  ON u.id = per.usuario_id
             WHERE po.id IN ({$marcas}) AND v.empresa_id = ? AND u.activo = 1"
        );
        $stmt->execute(array_merge($ids, [$empresaId]));
        $postulaciones = $stmt->fetchAll();

        if (!$postulaciones) {
            return ['status' => 'error', 'message' => 'Ninguna de las postulaciones seleccionadas es de tus vacantes.'];
        }

        // Enviar correo es lento; sin esto un lote grande podía cortarse a la
        // mitad y dejar a unos avisados y a otros no, sin saber a quiénes.
        @set_time_limit(120);

        $enviados = 0;
        $fallidos = [];

        foreach ($postulaciones as $po) {
            if ($this->enviarAvisoRevision($po, $nota)) $enviados++;
            else $fallidos[] = $po['nombre'];
        }

        // El estatus se cambia después del envío y solo sobre lo que seguía
        // sin revisar: marcar antes dejaría a alguien "en revisión" sin que le
        // hubiera llegado nada.
        $marcados = 0;
        if ($marcar) {
            $idsOk  = array_column($postulaciones, 'id');
            $marcas = implode(',', array_fill(0, count($idsOk), '?'));
            $upd = $this->pdo->prepare(
                "UPDATE sc_postulaciones SET estatus = 'en_revision'
                 WHERE id IN ({$marcas}) AND estatus = 'enviada'"
            );
            $upd->execute($idsOk);
            $marcados = $upd->rowCount();
        }

        $omitidos = count($ids) - count($postulaciones);

        $partes = [$enviados === 1 ? 'Se avisó a 1 candidato.' : "Se avisó a {$enviados} candidatos."];
        if ($marcados)         $partes[] = $marcados === 1 ? '1 pasó a "en revisión".' : "{$marcados} pasaron a \"en revisión\".";
        if ($fallidos)         $partes[] = 'No salió el correo de: ' . implode(', ', array_slice($fallidos, 0, 5)) . '.';
        if ($omitidos > 0)     $partes[] = "{$omitidos} se omitieron por no corresponder a tus vacantes.";

        return [
            'status'   => $enviados > 0 ? 'success' : 'error',
            'message'  => implode(' ', $partes),
            'enviados' => $enviados,
            'marcados' => $marcados,
            'fallidos' => count($fallidos),
        ];
    }

    /** Correo de "tus documentos están en revisión" para un postulante. */
    private function enviarAvisoRevision(array $po, ?string $nota): bool {
        $cuerpo = '<p>Hola ' . htmlspecialchars($po['nombre']) . ',</p>
            <p>Te confirmamos que recibimos tu postulación a
            <strong>' . htmlspecialchars($po['titulo']) . '</strong> y que
            <strong>tus documentos están siendo revisados por '
            . htmlspecialchars(SC_REVISOR) . '</strong>.</p>
            <p>No necesitas hacer nada por ahora. Te escribiremos en cuanto haya
            una respuesta sobre tu proceso.</p>';

        // La nota la escribe la empresa: se escapa y se respetan los saltos de
        // línea, para que no pueda meter HTML en un correo que firma AVBA.
        if ($nota) {
            $cuerpo .= '<p style="background:#F4F7FB;border-left:3px solid #185FA5;padding:12px 14px;'
                     . 'border-radius:6px;white-space:pre-line">'
                     . nl2br(htmlspecialchars($nota)) . '</p>';
        }

        $cuerpo .= '<p style="font-size:12.5px;color:#8792a8">Postulación enviada a '
                 . htmlspecialchars($po['empresa']) . ' a través de Socios Comerciales AVBA.</p>';

        $html = scPlantillaCorreo(
            'Tus documentos están en revisión',
            $cuerpo,
            'Ver mis postulaciones',
            scUrlBase() . '/mis-postulaciones.html'
        );

        try {
            return scEnviarCorreo(
                $po['correo'],
                'Tus documentos están en revisión — ' . $po['titulo'],
                $html
            );
        } catch (Throwable $e) {
            error_log('enviarAvisoRevision: ' . $e->getMessage());
            return false;
        }
    }

    // ══════════════════════════════════════════════════════════
    //  Resumen para la pantalla de inicio
    // ══════════════════════════════════════════════════════════
    public function resumenInicio(array $usuario): array {
        $usuarioId  = (int) $usuario['id'];
        $verificado = (int) ($usuario['correo_verificado'] ?? 0) === 1;
        // Se devuelve para que una sesión abierta antes de que el correo
        // entrara en SC_ADMINS descubra el permiso sin volver a entrar.
        $esAdmin    = (int) ($usuario['es_admin'] ?? 0);

        if ($usuario['tipo'] === 'empresa') {
            $empresaId = $this->idEmpresaPorUsuario($usuarioId);
            if (!$empresaId) return ['status' => 'error', 'message' => 'Perfil no encontrado.'];

            $stmt = $this->pdo->prepare(
                "SELECT
                   (SELECT COUNT(*) FROM sc_vacantes WHERE empresa_id = ? AND estatus = 'abierta') AS vacantes_abiertas,
                   (SELECT COUNT(*) FROM sc_postulaciones p JOIN sc_vacantes v ON v.id = p.vacante_id
                     WHERE v.empresa_id = ?) AS postulaciones_total,
                   (SELECT COUNT(*) FROM sc_postulaciones p JOIN sc_vacantes v ON v.id = p.vacante_id
                     WHERE v.empresa_id = ? AND p.estatus = 'enviada') AS postulaciones_nuevas,
                   (SELECT COUNT(*) FROM sc_personas) AS candidatos_totales"
            );
            $stmt->execute([$empresaId, $empresaId, $empresaId]);
            $metricas = $stmt->fetch();

            $stmt = $this->pdo->prepare(
                "SELECT p.id, p.fecha, p.estatus, per.id AS persona_id, per.nombre, per.headline, per.foto_url,
                        v.id AS vacante_id, v.titulo
                 FROM sc_postulaciones p
                 JOIN sc_vacantes v ON v.id = p.vacante_id
                 JOIN sc_personas per ON per.id = p.persona_id
                 WHERE v.empresa_id = ?
                 ORDER BY p.fecha DESC LIMIT 5"
            );
            $stmt->execute([$empresaId]);

            return [
                'status'            => 'success',
                'correo_verificado' => $verificado ? 1 : 0,
                'es_admin'          => $esAdmin,
                'metricas'          => $metricas,
                // El panel muestra nombre y foto de quienes se postularon: son
                // datos de otras personas, así que sin el correo confirmado se
                // devuelven los contadores pero no la lista.
                'recientes'         => $verificado ? $stmt->fetchAll() : [],
            ];
        }

        // Candidato
        $personaId = $this->idPersonaPorUsuario($usuarioId);
        if (!$personaId) return ['status' => 'error', 'message' => 'Perfil no encontrado.'];

        $stmt = $this->pdo->prepare(
            "SELECT
               (SELECT COUNT(*) FROM sc_postulaciones WHERE persona_id = ?) AS mis_postulaciones,
               (SELECT COUNT(*) FROM sc_postulaciones WHERE persona_id = ? AND estatus = 'aceptada') AS aceptadas,
               (SELECT COUNT(*) FROM sc_vacantes v JOIN sc_empresas e ON e.id = v.empresa_id
                 WHERE v.estatus = 'abierta') AS vacantes_abiertas,
               (SELECT COUNT(*) FROM sc_empresas) AS empresas_totales"
        );
        $stmt->execute([$personaId, $personaId]);
        $metricas = $stmt->fetch();

        // Vacantes sugeridas: abiertas a las que todavía no se postula
        $stmtSug = $this->pdo->prepare(
            "SELECT v.id, v.titulo, v.ubicacion, v.modalidad, v.salario, v.creado,
                    e.id AS empresa_id, e.nombre AS empresa, e.logo_url
             FROM sc_vacantes v
             JOIN sc_empresas e ON e.id = v.empresa_id
             JOIN sc_usuarios u ON u.id = e.usuario_id
             WHERE v.estatus = 'abierta' AND u.activo = 1
               AND v.id NOT IN (SELECT vacante_id FROM sc_postulaciones WHERE persona_id = ?)
             ORDER BY v.creado DESC LIMIT 5"
        );
        $stmtSug->execute([$personaId]);
        $sugeridas = $stmtSug->fetchAll();

        $metricas['perfil_completo'] = $this->completitudPerfil($personaId);

        return [
            'status'            => 'success',
            'correo_verificado' => $verificado ? 1 : 0,
            'es_admin'          => $esAdmin,
            'metricas'          => $metricas,
            'sugeridas'         => $sugeridas,
        ];
    }

    /** Porcentaje de completitud del perfil del candidato (0-100). */
    private function completitudPerfil(int $personaId): int {
        $stmt = $this->pdo->prepare(
            "SELECT headline, ubicacion, resumen, cv_url, foto_url, telefono FROM sc_personas WHERE id = ?"
        );
        $stmt->execute([$personaId]);
        $p = $stmt->fetch() ?: [];

        $llenos = 0;
        foreach (['headline', 'ubicacion', 'resumen', 'cv_url', 'foto_url', 'telefono'] as $campo) {
            if (!empty($p[$campo])) $llenos++;
        }

        foreach (['sc_habilidades', 'sc_experiencia'] as $tabla) {
            $s = $this->pdo->prepare("SELECT COUNT(*) FROM {$tabla} WHERE persona_id = ?");
            $s->execute([$personaId]);
            if ((int) $s->fetchColumn() > 0) $llenos++;
        }

        return (int) round($llenos / 8 * 100);
    }

    // ── Avisos por correo ────────────────────────────────────
    private function avisarEmpresaNuevaPostulacion(int $vacanteId, int $personaId): void {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT u.correo, v.titulo, e.nombre AS empresa, per.nombre AS candidato, per.headline
                 FROM sc_vacantes v
                 JOIN sc_empresas e ON e.id = v.empresa_id
                 JOIN sc_usuarios u ON u.id = e.usuario_id
                 JOIN sc_personas per ON per.id = ?
                 WHERE v.id = ?"
            );
            $stmt->execute([$personaId, $vacanteId]);
            $d = $stmt->fetch();
            if (!$d) return;

            $cuerpo = '<p>Hola ' . htmlspecialchars($d['empresa']) . ',</p>
                <p><strong>' . htmlspecialchars($d['candidato']) . '</strong>'
                . ($d['headline'] ? ' (' . htmlspecialchars($d['headline']) . ')' : '') .
                ' se postuló a tu vacante <strong>' . htmlspecialchars($d['titulo']) . '</strong>.</p>';

            $html = scPlantillaCorreo(
                'Nueva postulación recibida',
                $cuerpo,
                'Ver postulaciones',
                scUrlBase() . '/mis-vacantes.html'
            );

            scEnviarCorreo($d['correo'], 'Nueva postulación — ' . $d['titulo'], $html);
        } catch (Throwable $e) {
            error_log('avisarEmpresaNuevaPostulacion: ' . $e->getMessage());
        }
    }

    private function avisarCandidatoCambioEstatus(int $postulacionId, string $estatus): void {
        if (!in_array($estatus, ['aceptada', 'rechazada', 'en_revision'], true)) return;

        try {
            $stmt = $this->pdo->prepare(
                "SELECT u.correo, per.nombre, v.titulo, e.nombre AS empresa
                 FROM sc_postulaciones p
                 JOIN sc_personas per ON per.id = p.persona_id
                 JOIN sc_usuarios u   ON u.id = per.usuario_id
                 JOIN sc_vacantes v   ON v.id = p.vacante_id
                 JOIN sc_empresas e   ON e.id = v.empresa_id
                 WHERE p.id = ?"
            );
            $stmt->execute([$postulacionId]);
            $d = $stmt->fetch();
            if (!$d) return;

            $textos = [
                'en_revision' => 'está siendo revisada por',
                'aceptada'    => '¡fue aceptada por',
                'rechazada'   => 'no avanzó en el proceso de',
            ];
            $frase = $textos[$estatus];
            $cierre = $estatus === 'aceptada' ? '!' : '.';

            $cuerpo = '<p>Hola ' . htmlspecialchars($d['nombre']) . ',</p>
                <p>Tu postulación a <strong>' . htmlspecialchars($d['titulo']) . '</strong> '
                . $frase . ' <strong>' . htmlspecialchars($d['empresa']) . '</strong>' . $cierre . '</p>';

            $html = scPlantillaCorreo(
                'Actualización de tu postulación',
                $cuerpo,
                'Ver mis postulaciones',
                scUrlBase() . '/mis-postulaciones.html'
            );

            scEnviarCorreo($d['correo'], 'Actualización de tu postulación — ' . $d['titulo'], $html);
        } catch (Throwable $e) {
            error_log('avisarCandidatoCambioEstatus: ' . $e->getMessage());
        }
    }
}
