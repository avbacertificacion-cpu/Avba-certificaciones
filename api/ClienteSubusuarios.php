<?php
/**
 * AVBA Certificaciones — Sub-usuarios del Cliente
 *
 * Permite a la cuenta principal de un cliente (usuario_padre_id IS NULL)
 * crear sub-usuarios que comparten su id_cliente pero solo ven los recursos
 * (equipos, personal, certificaciones) que se les asignen, con permiso de
 * solo-lectura ('lectura') o de gestión ('gestion').
 */
class ClienteSubusuarios {
    private PDO $pdo;

    /** Tipos de recurso asignables a un sub-usuario. */
    private const TIPOS = ['equipo', 'personal', 'certificacion'];

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->migrate();
    }

    private function migrate(): void {
        try {
            $cols = $this->pdo->query("SHOW COLUMNS FROM usuarios")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('usuario_padre_id', $cols, true)) {
                $this->pdo->exec("ALTER TABLE usuarios ADD COLUMN usuario_padre_id INT NULL DEFAULT NULL");
            }
            if (!in_array('permiso_sub', $cols, true)) {
                $this->pdo->exec("ALTER TABLE usuarios ADD COLUMN permiso_sub VARCHAR(10) NOT NULL DEFAULT 'gestion'");
            }
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS cliente_subusuario_acceso (
                  id          INT AUTO_INCREMENT PRIMARY KEY,
                  usuario_id  INT         NOT NULL,
                  tipo        VARCHAR(20) NOT NULL,
                  recurso_id  INT         NOT NULL,
                  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  UNIQUE KEY uniq_acceso (usuario_id, tipo, recurso_id),
                  KEY idx_usuario (usuario_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\PDOException $e) {
            error_log('[ClienteSubusuarios] migrate: ' . $e->getMessage());
        }
    }

    private function norm(string $id): string {
        $id = trim($id);
        return (ctype_digit($id)) ? str_pad($id, 5, '0', STR_PAD_LEFT) : $id;
    }

    /** Verifica que $subId sea sub-usuario del padre indicado. Devuelve la fila o null. */
    private function subDePadre(int $subId, int $padreId): ?array {
        $s = $this->pdo->prepare(
            "SELECT id, usuario, nombre, id_cliente, activo, permiso_sub, usuario_padre_id
             FROM usuarios WHERE id = ? AND usuario_padre_id = ?"
        );
        $s->execute([$subId, $padreId]);
        return $s->fetch() ?: null;
    }

    /** Recursos asignados a un sub-usuario, agrupados por tipo. */
    public function accesosDe(int $usuarioId): array {
        $out = ['equipo' => [], 'personal' => [], 'certificacion' => []];
        $s = $this->pdo->prepare("SELECT tipo, recurso_id FROM cliente_subusuario_acceso WHERE usuario_id = ?");
        $s->execute([$usuarioId]);
        foreach ($s->fetchAll() as $r) {
            $t = $r['tipo'];
            if (isset($out[$t])) $out[$t][] = (int)$r['recurso_id'];
        }
        return $out;
    }

    // ════════════════════════════════════════════════════════
    //  GESTIÓN DE SUB-USUARIOS (solo cuenta principal)
    // ════════════════════════════════════════════════════════

    public function listar(int $padreId): array {
        $s = $this->pdo->prepare(
            "SELECT u.id, u.usuario, u.nombre, u.activo, u.permiso_sub,
                    DATE_FORMAT(u.ultimo_acceso, '%d/%m/%Y %H:%i') AS ultimo_acceso,
                    (SELECT COUNT(*) FROM cliente_subusuario_acceso a WHERE a.usuario_id = u.id AND a.tipo='equipo')        AS n_equipos,
                    (SELECT COUNT(*) FROM cliente_subusuario_acceso a WHERE a.usuario_id = u.id AND a.tipo='personal')      AS n_personal,
                    (SELECT COUNT(*) FROM cliente_subusuario_acceso a WHERE a.usuario_id = u.id AND a.tipo='certificacion') AS n_cert
             FROM usuarios u
             WHERE u.usuario_padre_id = ?
             ORDER BY u.nombre"
        );
        $s->execute([$padreId]);
        $rows = $s->fetchAll();
        return ['status' => 'success', 'subusuarios' => array_map(fn($r) => [
            'id'            => (int)$r['id'],
            'usuario'       => $r['usuario'],
            'nombre'        => $r['nombre'],
            'activo'        => (int)$r['activo'],
            'permiso'       => $r['permiso_sub'],
            'ultimo_acceso' => $r['ultimo_acceso'] ?: '—',
            'n_equipos'     => (int)$r['n_equipos'],
            'n_personal'    => (int)$r['n_personal'],
            'n_cert'        => (int)$r['n_cert'],
        ], $rows)];
    }

    public function crear(array $payload, array $padre): array {
        $usuario  = strtolower(trim($payload['usuario']  ?? ''));
        $password = trim($payload['password'] ?? '');
        $nombre   = trim($payload['nombre']   ?? '');
        $permiso  = ($payload['permiso'] ?? 'gestion') === 'lectura' ? 'lectura' : 'gestion';

        if (!$usuario || !$password || !$nombre) {
            return ['status' => 'error', 'message' => 'Usuario, contraseña y nombre son obligatorios.'];
        }
        if (strlen($password) < 6) {
            return ['status' => 'error', 'message' => 'La contraseña debe tener al menos 6 caracteres.'];
        }
        if (!filter_var($usuario, FILTER_VALIDATE_EMAIL) && !preg_match('/^[a-z0-9._-]{3,}$/', $usuario)) {
            return ['status' => 'error', 'message' => 'Usuario inválido (usa un correo o un identificador simple).'];
        }

        $dup = $this->pdo->prepare("SELECT id FROM usuarios WHERE usuario = ?");
        $dup->execute([$usuario]);
        if ($dup->fetch()) {
            return ['status' => 'error', 'message' => "El usuario '{$usuario}' ya existe."];
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $this->pdo->prepare(
            "INSERT INTO usuarios (usuario, password_hash, rol, nombre, id_cliente, activo, usuario_padre_id, permiso_sub)
             VALUES (?, ?, 'CLIENTE', ?, ?, 1, ?, ?)"
        )->execute([$usuario, $hash, $nombre, $padre['id_cliente'] ?: null, (int)$padre['id'], $permiso]);

        return ['status' => 'success', 'message' => 'Sub-usuario creado.', 'id' => (int)$this->pdo->lastInsertId()];
    }

    public function actualizar(array $payload, array $padre): array {
        $subId = (int)($payload['id'] ?? 0);
        $sub   = $this->subDePadre($subId, (int)$padre['id']);
        if (!$sub) return ['status' => 'error', 'message' => 'Sub-usuario no encontrado.'];

        $nombre  = trim($payload['nombre'] ?? $sub['nombre']);
        $permiso = ($payload['permiso'] ?? $sub['permiso_sub']) === 'lectura' ? 'lectura' : 'gestion';
        $activo  = isset($payload['activo']) ? (int)(bool)$payload['activo'] : (int)$sub['activo'];

        $this->pdo->prepare(
            "UPDATE usuarios SET nombre = ?, permiso_sub = ?, activo = ? WHERE id = ? AND usuario_padre_id = ?"
        )->execute([$nombre, $permiso, $activo, $subId, (int)$padre['id']]);

        $password = trim($payload['password'] ?? '');
        if ($password !== '') {
            if (strlen($password) < 6) {
                return ['status' => 'error', 'message' => 'La nueva contraseña debe tener al menos 6 caracteres.'];
            }
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $this->pdo->prepare("UPDATE usuarios SET password_hash = ?, session_token = NULL WHERE id = ?")
                      ->execute([$hash, $subId]);
        }

        return ['status' => 'success', 'message' => 'Sub-usuario actualizado.'];
    }

    public function eliminar(int $subId, array $padre): array {
        $sub = $this->subDePadre($subId, (int)$padre['id']);
        if (!$sub) return ['status' => 'error', 'message' => 'Sub-usuario no encontrado.'];

        $this->pdo->prepare("DELETE FROM cliente_subusuario_acceso WHERE usuario_id = ?")->execute([$subId]);
        $this->pdo->prepare("DELETE FROM usuarios WHERE id = ? AND usuario_padre_id = ?")->execute([$subId, (int)$padre['id']]);

        return ['status' => 'success', 'message' => 'Sub-usuario eliminado.'];
    }

    // ════════════════════════════════════════════════════════
    //  ASIGNACIÓN DE RECURSOS
    // ════════════════════════════════════════════════════════

    /** Devuelve los ids válidos para el cliente de un tipo dado (filtro de seguridad). */
    private function idsValidos(string $tipo, string $idCliente, array $ids): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return [];
        $ph = implode(',', array_fill(0, count($ids), '?'));

        if ($tipo === 'equipo') {
            $sql = "SELECT id FROM cliente_equipos WHERE id_cliente = ? AND id IN ($ph)";
            $params = array_merge([$idCliente], $ids);
        } elseif ($tipo === 'personal') {
            $sql = "SELECT id FROM cliente_personal WHERE id_cliente = ? AND id IN ($ph)";
            $params = array_merge([$idCliente], $ids);
        } else { // certificacion (tabla equipos legacy, ligada por control 'idc-...')
            $sql = "SELECT id FROM equipos WHERE control LIKE ? AND id IN ($ph)";
            $params = array_merge([$idCliente . '-%'], $ids);
        }
        $s = $this->pdo->prepare($sql);
        $s->execute($params);
        return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Reemplaza por completo los accesos de un tipo para un sub-usuario. */
    public function asignar(array $payload, array $padre): array {
        $subId = (int)($payload['usuario_id'] ?? 0);
        $tipo  = trim($payload['tipo'] ?? '');
        if (!in_array($tipo, self::TIPOS, true)) {
            return ['status' => 'error', 'message' => 'Tipo de recurso inválido.'];
        }
        $sub = $this->subDePadre($subId, (int)$padre['id']);
        if (!$sub) return ['status' => 'error', 'message' => 'Sub-usuario no encontrado.'];

        $idsSolicitados = $payload['ids'] ?? [];
        if (!is_array($idsSolicitados)) $idsSolicitados = [];
        $idsOk = $this->idsValidos($tipo, $this->norm((string)$padre['id_cliente']), $idsSolicitados);

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("DELETE FROM cliente_subusuario_acceso WHERE usuario_id = ? AND tipo = ?")
                      ->execute([$subId, $tipo]);
            if ($idsOk) {
                $ins = $this->pdo->prepare(
                    "INSERT INTO cliente_subusuario_acceso (usuario_id, tipo, recurso_id) VALUES (?, ?, ?)"
                );
                foreach ($idsOk as $rid) $ins->execute([$subId, $tipo, $rid]);
            }
            $this->pdo->commit();
        } catch (\PDOException $e) {
            $this->pdo->rollBack();
            error_log('[ClienteSubusuarios] asignar: ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'No se pudo guardar la asignación.'];
        }

        return ['status' => 'success', 'message' => 'Asignación guardada.', 'asignados' => count($idsOk)];
    }

    /** Accesos actuales de un sub-usuario (para precargar la UI de asignación). */
    public function obtenerAccesos(int $subId, array $padre): array {
        $sub = $this->subDePadre($subId, (int)$padre['id']);
        if (!$sub) return ['status' => 'error', 'message' => 'Sub-usuario no encontrado.'];
        return ['status' => 'success', 'accesos' => $this->accesosDe($subId)];
    }

    /** Catálogo de recursos asignables del cliente (para la UI). */
    public function recursosAsignables(string $idCliente): array {
        $idc = $this->norm($idCliente);

        $eq = $this->pdo->prepare(
            "SELECT id, nombre, tipo, serie FROM cliente_equipos WHERE id_cliente = ? ORDER BY nombre"
        );
        $eq->execute([$idc]);
        $equipos = array_map(fn($r) => [
            'id'    => (int)$r['id'],
            'label' => $r['nombre'] . ($r['serie'] ? ' · ' . $r['serie'] : ''),
            'sub'   => $r['tipo'] ?? '',
        ], $eq->fetchAll());

        $pe = $this->pdo->prepare(
            "SELECT id, nombre, puesto FROM cliente_personal WHERE id_cliente = ? ORDER BY nombre"
        );
        $pe->execute([$idc]);
        $personal = array_map(fn($r) => [
            'id'    => (int)$r['id'],
            'label' => $r['nombre'],
            'sub'   => $r['puesto'] ?? '',
        ], $pe->fetchAll());

        $ce = $this->pdo->prepare(
            "SELECT id, maquinaria, marca, serie, control
             FROM equipos WHERE control LIKE ? AND estado = 'ENVIADO'
             ORDER BY fecha_inspeccion DESC"
        );
        $ce->execute([$idc . '-%']);
        $certs = array_map(fn($r) => [
            'id'    => (int)$r['id'],
            'label' => trim(($r['maquinaria'] ?: 'Equipo') . ($r['marca'] ? ' ' . $r['marca'] : '') . ($r['serie'] ? ' · ' . $r['serie'] : '')),
            'sub'   => $r['control'] ?? '',
        ], $ce->fetchAll());

        return ['status' => 'success', 'equipo' => $equipos, 'personal' => $personal, 'certificacion' => $certs];
    }
}
