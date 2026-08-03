<?php
/**
 * Socios Comerciales AVBA — Panel de administración
 *
 * Consulta y gestión de las cuentas del portal: candidatos y empresas, su
 * información, y el bloqueo o la eliminación de cualquiera de ellas.
 *
 * Quién es administrador lo decide `scEsAdmin()` contra la lista SC_ADMINS de
 * config/config.php, que solo existe en el servidor. El router ya comprueba
 * el permiso antes de llegar aquí (scSesionAdmin), pero los métodos que
 * destruyen datos vuelven a comprobar las reglas propias: no tocarse a uno
 * mismo y no tocar a otro administrador.
 */

class ScAdmin {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    // ══════════════════════════════════════════════════════════
    //  RESUMEN
    // ══════════════════════════════════════════════════════════
    public function resumen(): array {
        $stmt = $this->pdo->query(
            "SELECT
               (SELECT COUNT(*) FROM sc_usuarios WHERE tipo = 'persona') AS candidatos,
               (SELECT COUNT(*) FROM sc_usuarios WHERE tipo = 'empresa') AS empresas,
               (SELECT COUNT(*) FROM sc_usuarios WHERE activo = 0)       AS bloqueados,
               (SELECT COUNT(*) FROM sc_usuarios WHERE correo_verificado = 0 AND activo = 1) AS sin_verificar,
               (SELECT COUNT(*) FROM sc_vacantes WHERE estatus = 'abierta') AS vacantes_abiertas,
               (SELECT COUNT(*) FROM sc_postulaciones)                   AS postulaciones,
               (SELECT COUNT(*) FROM sc_usuarios WHERE creado > DATE_SUB(NOW(), INTERVAL 7 DAY)) AS altas_semana"
        );
        $metricas = $stmt->fetch() ?: [];

        // Últimos movimientos de administración, para no perder de vista qué
        // se ha hecho ni quién lo hizo.
        $stmt = $this->pdo->query(
            "SELECT accion, admin_correo, usuario_correo, detalle, fecha
             FROM sc_admin_log ORDER BY fecha DESC LIMIT 10"
        );

        return [
            'status'    => 'success',
            'metricas'  => $metricas,
            'bitacora'  => $stmt->fetchAll(),
        ];
    }

    // ══════════════════════════════════════════════════════════
    //  LISTADO DE CUENTAS
    // ══════════════════════════════════════════════════════════
    public function listarUsuarios(array $filtros): array {
        $tipo   = strtolower(trim($filtros['tipo']   ?? ''));
        $estado = strtolower(trim($filtros['estado'] ?? ''));
        $texto  = trim($filtros['texto'] ?? '');

        $where  = ['1 = 1'];
        $params = [];

        if ($tipo === 'persona' || $tipo === 'empresa') {
            $where[]  = 'u.tipo = ?';
            $params[] = $tipo;
        }

        // El nombre vive en dos tablas distintas según el tipo de cuenta, así
        // que se resuelve con COALESCE sobre los dos LEFT JOIN.
        if ($texto !== '') {
            $where[] = '(u.correo LIKE ? OR p.nombre LIKE ? OR e.nombre LIKE ?)';
            $like    = '%' . scEscaparLike($texto) . '%';
            array_push($params, $like, $like, $like);
        }

        switch ($estado) {
            case 'bloqueados':    $where[] = 'u.activo = 0'; break;
            case 'sin_verificar': $where[] = 'u.activo = 1 AND u.correo_verificado = 0'; break;
            case 'activos':       $where[] = 'u.activo = 1'; break;
        }

        $condiciones = implode(' AND ', $where);
        $joins = "FROM sc_usuarios u
                  LEFT JOIN sc_personas p ON p.usuario_id = u.id
                  LEFT JOIN sc_empresas e ON e.usuario_id = u.id";

        [$limite, $offset] = scPaginacion($filtros, 30, 100);

        $stmt = $this->pdo->prepare("SELECT COUNT(*) {$joins} WHERE {$condiciones}");
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        $stmt = $this->pdo->prepare(
            "SELECT u.id, u.tipo, u.correo, u.correo_verificado, u.activo,
                    u.creado, u.ultimo_acceso, u.bloqueado_motivo, u.bloqueado_fecha,
                    u.terminos_version, u.terminos_aceptados,
                    COALESCE(p.nombre, e.nombre) AS nombre,
                    p.id AS persona_id, p.headline, p.ubicacion, p.foto_url, p.cv_url,
                    e.id AS empresa_id, e.giro, e.logo_url,
                    (SELECT COUNT(*) FROM sc_vacantes v WHERE v.empresa_id = e.id) AS vacantes,
                    (SELECT COUNT(*) FROM sc_postulaciones po WHERE po.persona_id = p.id) AS postulaciones
             {$joins}
             WHERE {$condiciones}
             ORDER BY u.creado DESC
             LIMIT {$limite} OFFSET {$offset}"
        );
        $stmt->execute($params);
        $usuarios = $stmt->fetchAll();

        // El permiso de administrador vive en config.php, no en la base, así
        // que se marca aquí: la interfaz debe poder ocultar los botones de
        // bloquear y eliminar sobre otro administrador.
        foreach ($usuarios as &$u) {
            $u['es_admin'] = scEsAdmin($u['correo']) ? 1 : 0;
        }
        unset($u);

        return [
            'status'   => 'success',
            'usuarios' => $usuarios,
            'total'    => $total,
            'offset'   => $offset,
            'hay_mas'  => ($offset + count($usuarios)) < $total,
        ];
    }

    // ══════════════════════════════════════════════════════════
    //  FICHA COMPLETA DE UNA CUENTA
    // ══════════════════════════════════════════════════════════
    public function verUsuario(int $usuarioId): array {
        $stmt = $this->pdo->prepare(
            "SELECT u.id, u.tipo, u.correo, u.correo_verificado, u.activo, u.creado,
                    u.ultimo_acceso, u.bloqueado_motivo, u.bloqueado_fecha,
                    u.terminos_version, u.terminos_aceptados,
                    COALESCE(p.nombre, e.nombre) AS nombre
             FROM sc_usuarios u
             LEFT JOIN sc_personas p ON p.usuario_id = u.id
             LEFT JOIN sc_empresas e ON e.usuario_id = u.id
             WHERE u.id = ?"
        );
        $stmt->execute([$usuarioId]);
        $usuario = $stmt->fetch();

        if (!$usuario) return ['status' => 'error', 'message' => 'La cuenta no existe.'];

        $usuario['es_admin'] = scEsAdmin($usuario['correo']) ? 1 : 0;
        $detalle = ['status' => 'success', 'usuario' => $usuario];

        if ($usuario['tipo'] === 'persona') {
            $stmt = $this->pdo->prepare("SELECT * FROM sc_personas WHERE usuario_id = ?");
            $stmt->execute([$usuarioId]);
            $persona = $stmt->fetch() ?: [];
            $detalle['perfil'] = $persona;

            if (!empty($persona['id'])) {
                $pid = (int) $persona['id'];

                foreach (['experiencia' => 'sc_experiencia',
                          'educacion'   => 'sc_educacion',
                          'habilidades' => 'sc_habilidades'] as $clave => $tabla) {
                    $s = $this->pdo->prepare("SELECT * FROM {$tabla} WHERE persona_id = ? ORDER BY id");
                    $s->execute([$pid]);
                    $detalle[$clave] = $s->fetchAll();
                }

                $s = $this->pdo->prepare(
                    "SELECT po.fecha, po.estatus, v.titulo AS vacante, e.nombre AS empresa
                     FROM sc_postulaciones po
                     JOIN sc_vacantes v ON v.id = po.vacante_id
                     JOIN sc_empresas e ON e.id = v.empresa_id
                     WHERE po.persona_id = ? ORDER BY po.fecha DESC LIMIT 100"
                );
                $s->execute([$pid]);
                $detalle['postulaciones'] = $s->fetchAll();

                $s = $this->pdo->prepare(
                    "SELECT a.fecha, COALESCE(e.nombre, u.correo) AS quien
                     FROM sc_cv_accesos a
                     LEFT JOIN sc_usuarios u ON u.id = a.usuario_id
                     LEFT JOIN sc_empresas e ON e.usuario_id = a.usuario_id
                     WHERE a.persona_id = ? ORDER BY a.fecha DESC LIMIT 50"
                );
                $s->execute([$pid]);
                $detalle['accesos_cv'] = $s->fetchAll();
            }
        } else {
            $stmt = $this->pdo->prepare("SELECT * FROM sc_empresas WHERE usuario_id = ?");
            $stmt->execute([$usuarioId]);
            $empresa = $stmt->fetch() ?: [];
            $detalle['perfil'] = $empresa;

            if (!empty($empresa['id'])) {
                $s = $this->pdo->prepare(
                    "SELECT v.id, v.titulo, v.ubicacion, v.modalidad, v.estatus, v.creado,
                            (SELECT COUNT(*) FROM sc_postulaciones po WHERE po.vacante_id = v.id) AS postulaciones
                     FROM sc_vacantes v WHERE v.empresa_id = ?
                     ORDER BY v.creado DESC LIMIT 100"
                );
                $s->execute([(int) $empresa['id']]);
                $detalle['vacantes'] = $s->fetchAll();
            }
        }

        $stmt = $this->pdo->prepare(
            "SELECT creado, expira, ultimo_uso, agente, ip
             FROM sc_sesiones WHERE usuario_id = ? AND expira > NOW()
             ORDER BY ultimo_uso DESC"
        );
        $stmt->execute([$usuarioId]);
        $detalle['sesiones'] = $stmt->fetchAll();

        $stmt = $this->pdo->prepare(
            "SELECT accion, admin_correo, detalle, fecha FROM sc_admin_log
             WHERE usuario_id = ? ORDER BY fecha DESC LIMIT 20"
        );
        $stmt->execute([$usuarioId]);
        $detalle['bitacora'] = $stmt->fetchAll();

        return $detalle;
    }

    // ══════════════════════════════════════════════════════════
    //  BLOQUEAR / DESBLOQUEAR
    // ══════════════════════════════════════════════════════════

    /**
     * Bloquea una cuenta: deja de poder entrar y desaparece de los listados,
     * porque todas las consultas del portal filtran por `u.activo = 1`.
     * Es reversible y no borra nada.
     */
    public function bloquear(array $admin, int $usuarioId, array $payload): array {
        $objetivo = $this->comprobarObjetivo($admin, $usuarioId, 'bloquear');
        if (isset($objetivo['status'])) return $objetivo;

        if ((int) $objetivo['activo'] === 0) {
            return ['status' => 'success', 'message' => 'Esa cuenta ya estaba bloqueada.'];
        }

        $motivo = scTexto($payload['motivo'] ?? null, 255);

        $this->pdo->prepare(
            "UPDATE sc_usuarios
             SET activo = 0, bloqueado_motivo = ?, bloqueado_fecha = NOW(), bloqueado_por = ?
             WHERE id = ?"
        )->execute([$motivo, (int) $admin['id'], $usuarioId]);

        // Sin esto, quien ya tuviera sesión abierta seguiría dentro hasta que
        // el token caducara: el bloqueo tiene que surtir efecto ahora.
        $this->pdo->prepare("DELETE FROM sc_sesiones WHERE usuario_id = ?")->execute([$usuarioId]);

        $this->registrar($admin, 'bloquear', $usuarioId, $objetivo['correo'], $motivo);

        return ['status' => 'success', 'message' => 'Cuenta bloqueada y sesiones cerradas.'];
    }

    public function desbloquear(array $admin, int $usuarioId): array {
        $objetivo = $this->comprobarObjetivo($admin, $usuarioId, 'desbloquear');
        if (isset($objetivo['status'])) return $objetivo;

        if ((int) $objetivo['activo'] === 1) {
            return ['status' => 'success', 'message' => 'Esa cuenta ya estaba activa.'];
        }

        $this->pdo->prepare(
            "UPDATE sc_usuarios
             SET activo = 1, bloqueado_motivo = NULL, bloqueado_fecha = NULL, bloqueado_por = NULL
             WHERE id = ?"
        )->execute([$usuarioId]);

        $this->registrar($admin, 'desbloquear', $usuarioId, $objetivo['correo'], null);

        return ['status' => 'success', 'message' => 'Cuenta reactivada.'];
    }

    // ══════════════════════════════════════════════════════════
    //  ELIMINAR
    // ══════════════════════════════════════════════════════════

    /**
     * Borra una cuenta y todo lo que cuelga de ella. Es irreversible.
     *
     * Exige la contraseña del administrador: un token robado no debe bastar
     * para vaciar el portal. La bitácora guarda copia del correo borrado,
     * porque la fila del usuario deja de existir.
     */
    public function eliminar(array $admin, int $usuarioId, array $payload): array {
        $objetivo = $this->comprobarObjetivo($admin, $usuarioId, 'eliminar');
        if (isset($objetivo['status'])) return $objetivo;

        $password = (string) ($payload['password'] ?? '');
        if ($password === '') {
            return ['status' => 'error', 'message' => 'Escribe tu contraseña para confirmar la eliminación.'];
        }

        $stmt = $this->pdo->prepare("SELECT password_hash FROM sc_usuarios WHERE id = ?");
        $stmt->execute([(int) $admin['id']]);
        $hash = $stmt->fetchColumn();

        if (!$hash || !password_verify($password, $hash)) {
            return ['status' => 'error', 'message' => 'Tu contraseña no es correcta.'];
        }

        // Los archivos en disco no los borra la cascada de claves foráneas
        $archivos = [];
        if ($objetivo['tipo'] === 'persona') {
            $s = $this->pdo->prepare("SELECT cv_url, foto_url FROM sc_personas WHERE usuario_id = ?");
            $s->execute([$usuarioId]);
            $p = $s->fetch() ?: [];
            $archivos = [$p['cv_url'] ?? null, $p['foto_url'] ?? null];
        } else {
            $s = $this->pdo->prepare("SELECT logo_url FROM sc_empresas WHERE usuario_id = ?");
            $s->execute([$usuarioId]);
            $archivos = [$s->fetchColumn() ?: null];
        }

        $motivo = scTexto($payload['motivo'] ?? null, 255);

        try {
            $this->pdo->prepare("DELETE FROM sc_usuarios WHERE id = ?")->execute([$usuarioId]);
        } catch (PDOException $e) {
            error_log('ScAdmin::eliminar: ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'No se pudo eliminar la cuenta.'];
        }

        foreach ($archivos as $a) scBorrarArchivo($a);

        $this->registrar($admin, 'eliminar', $usuarioId, $objetivo['correo'], $motivo);

        return ['status' => 'success', 'message' => 'Cuenta eliminada junto con todos sus datos.'];
    }

    // ══════════════════════════════════════════════════════════
    //  BITÁCORA
    // ══════════════════════════════════════════════════════════
    public function bitacora(array $filtros): array {
        [$limite, $offset] = scPaginacion($filtros, 50, 200);

        $total = (int) $this->pdo->query("SELECT COUNT(*) FROM sc_admin_log")->fetchColumn();

        $stmt = $this->pdo->query(
            "SELECT id, accion, admin_correo, usuario_id, usuario_correo, detalle, fecha, ip
             FROM sc_admin_log ORDER BY fecha DESC LIMIT {$limite} OFFSET {$offset}"
        );
        $filas = $stmt->fetchAll();

        return [
            'status'   => 'success',
            'bitacora' => $filas,
            'total'    => $total,
            'offset'   => $offset,
            'hay_mas'  => ($offset + count($filas)) < $total,
        ];
    }

    // ── Helpers internos ─────────────────────────────────────

    /**
     * Reglas comunes de las acciones destructivas. Devuelve la fila del
     * usuario objetivo, o un array con 'status' => 'error' si no procede.
     *
     * Las dos prohibiciones existen para lo mismo: que no se pueda dejar el
     * portal sin administración. Un administrador que se bloquea a sí mismo,
     * o que bloquea al otro, ya no puede entrar a deshacerlo — habría que
     * arreglarlo a mano en la base de datos.
     */
    private function comprobarObjetivo(array $admin, int $usuarioId, string $accion) {
        if ($usuarioId <= 0) {
            return ['status' => 'error', 'message' => 'Cuenta no indicada.'];
        }
        if ($usuarioId === (int) $admin['id']) {
            return ['status' => 'error', 'message' => "No puedes {$accion} tu propia cuenta."];
        }

        $stmt = $this->pdo->prepare("SELECT id, tipo, correo, activo FROM sc_usuarios WHERE id = ?");
        $stmt->execute([$usuarioId]);
        $objetivo = $stmt->fetch();

        if (!$objetivo) {
            return ['status' => 'error', 'message' => 'La cuenta no existe.'];
        }
        if (scEsAdmin($objetivo['correo'])) {
            return [
                'status'  => 'error',
                'message' => 'Esa cuenta es de administración. Para quitarle el permiso, '
                           . 'edita SC_ADMINS en config/config.php.',
            ];
        }

        return $objetivo;
    }

    /** Deja constancia de la acción. Nunca debe tumbar la operación. */
    private function registrar(array $admin, string $accion, ?int $usuarioId,
                               ?string $usuarioCorreo, ?string $detalle): void {
        try {
            $this->pdo->prepare(
                "INSERT INTO sc_admin_log
                    (admin_id, admin_correo, accion, usuario_id, usuario_correo, detalle, ip)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            )->execute([
                (int) $admin['id'], $admin['correo'], $accion,
                $usuarioId, $usuarioCorreo, $detalle, scIpCliente(),
            ]);
        } catch (PDOException $e) {
            error_log('ScAdmin::registrar: ' . $e->getMessage());
        }
    }
}
