<?php
/**
 * AVBA Certificaciones — Módulo de Autenticación
 * Migración de Auth.gs → PHP + MySQL
 */

class Auth {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    // ── LOGIN ─────────────────────────────────────────────
    public function login(array $payload): array {
        $usuario = strtolower(trim($payload['usuario'] ?? ''));
        $pass    = trim($payload['password'] ?? '');
        $ip      = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        if (!$usuario || !$pass) {
            return ['status' => 'error', 'message' => 'Usuario y contraseña requeridos.'];
        }

        // ── Rate limiting ──────────────────────────────
        $this->ensureLoginIntentosTable();
        $blk = $this->pdo->prepare(
            "SELECT intentos, bloqueado_hasta FROM login_intentos WHERE usuario = ? LIMIT 1"
        );
        $blk->execute([$usuario]);
        $intento = $blk->fetch();
        if ($intento && $intento['bloqueado_hasta'] && $intento['bloqueado_hasta'] > date('Y-m-d H:i:s')) {
            return ['status' => 'error', 'message' => 'Demasiados intentos fallidos. Espera ' . LOGIN_BLOQUEO_MIN . ' minutos e intenta de nuevo.'];
        }

        $stmt = $this->pdo->prepare(
            "SELECT id, usuario, password_hash, rol, nombre, id_cliente, activo
             FROM usuarios WHERE usuario = ?"
        );
        $stmt->execute([$usuario]);
        $row = $stmt->fetch();

        // Siempre verificar para evitar timing attacks
        $hashVerificar = $row['password_hash'] ?? '$2y$10$invalidhashpadding000000000000000000000000000000000000000';
        $valido = $row && $row['activo'] && password_verify($pass, $hashVerificar);

        // Migración silenciosa de MD5 heredado al primer login exitoso
        if (!$valido && $row && $row['activo'] && strlen($row['password_hash']) === 32
            && $row['password_hash'] === md5($pass)) {
            $nuevoHash = password_hash($pass, PASSWORD_BCRYPT);
            $this->pdo->prepare("UPDATE usuarios SET password_hash = ? WHERE id = ?")->execute([$nuevoHash, $row['id']]);
            $valido = true;
        }

        if (!$valido) {
            $this->registrarIntento($usuario, $ip, false);
            return ['status' => 'error', 'message' => 'Credenciales inválidas.'];
        }

        $this->registrarIntento($usuario, $ip, true);

        // Generar token y guardarlo
        $token   = generarToken();
        $expires = date('Y-m-d H:i:s', time() + TOKEN_TTL);
        $this->pdo->prepare(
            "UPDATE usuarios SET session_token = ?, token_expires = ?, ultimo_acceso = NOW() WHERE id = ?"
        )->execute([$token, $expires, $row['id']]);

        return [
            'status'     => 'success',
            'rol'        => $row['rol'],
            'nombre'     => $row['nombre'],
            'usuario'    => $usuario,
            'id_cliente' => $row['id_cliente'] ?? '',
            'token'      => $token,
        ];
    }

    private function ensureLoginIntentosTable(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS login_intentos (
              id              INT AUTO_INCREMENT PRIMARY KEY,
              usuario         VARCHAR(50) NOT NULL,
              intentos        TINYINT UNSIGNED NOT NULL DEFAULT 0,
              bloqueado_hasta DATETIME NULL,
              ultima_vez      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY uk_usuario (usuario)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    private function registrarIntento(string $usuario, string $ip, bool $exitoso): void {
        if ($exitoso) {
            $this->pdo->prepare("DELETE FROM login_intentos WHERE usuario = ?")->execute([$usuario]);
            return;
        }
        $maxIntentos = defined('LOGIN_MAX_INTENTOS') ? LOGIN_MAX_INTENTOS : 5;
        $bloqueoMin  = defined('LOGIN_BLOQUEO_MIN')  ? LOGIN_BLOQUEO_MIN  : 15;

        $this->pdo->prepare("
            INSERT INTO login_intentos (usuario, intentos, bloqueado_hasta)
            VALUES (?, 1, NULL)
            ON DUPLICATE KEY UPDATE
              intentos        = intentos + 1,
              bloqueado_hasta = IF(intentos + 1 >= ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), NULL)
        ")->execute([$usuario, $maxIntentos, $bloqueoMin]);
    }

    // ── CREAR USUARIO ──────────────────────────────────────
    public function crearUsuario(array $payload): array {
        $usuario    = strtolower(trim($payload['usuario']    ?? ''));
        $password   = trim($payload['password']  ?? '');
        $rol        = strtoupper(trim($payload['rol']       ?? ''));
        $nombre     = trim($payload['nombre']    ?? '');
        $idCliente  = trim($payload['id_cliente'] ?? '');

        if (!$usuario || !$password || !$rol || !$nombre) {
            return ['status' => 'error', 'message' => 'Todos los campos son obligatorios.'];
        }
        if (!in_array($rol, ROLES_VALIDOS, true)) {
            return ['status' => 'error', 'message' => 'Rol inválido.'];
        }
        if ($rol === 'CLIENTE' && !$idCliente) {
            return ['status' => 'error', 'message' => 'id_cliente obligatorio para rol CLIENTE.'];
        }

        // Verificar duplicado
        $stmt = $this->pdo->prepare("SELECT id FROM usuarios WHERE usuario = ?");
        $stmt->execute([$usuario]);
        if ($stmt->fetch()) {
            return ['status' => 'error', 'message' => "El usuario '{$usuario}' ya existe."];
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $this->pdo->prepare(
            "INSERT INTO usuarios (usuario, password_hash, rol, nombre, id_cliente, activo)
             VALUES (?, ?, ?, ?, ?, 1)"
        )->execute([$usuario, $hash, $rol, $nombre, $idCliente ?: null]);

        return ['status' => 'success', 'message' => 'Usuario creado correctamente.'];
    }

    // ── EDITAR USUARIO ─────────────────────────────────────
    public function editarUsuario(array $payload, string $usuarioEditor): array {
        // Aceptar búsqueda por id o por usuario
        if (!empty($payload['id'])) {
            $stmt = $this->pdo->prepare("SELECT id FROM usuarios WHERE id = ?");
            $stmt->execute([(int)$payload['id']]);
        } else {
            $usuario = strtolower(trim($payload['usuario'] ?? ''));
            if (!$usuario) return ['status' => 'error', 'message' => 'id o usuario requerido.'];
            $stmt = $this->pdo->prepare("SELECT id FROM usuarios WHERE usuario = ?");
            $stmt->execute([$usuario]);
        }
        $row = $stmt->fetch();
        if (!$row) return ['status' => 'error', 'message' => 'Usuario no encontrado.'];

        $sets   = [];
        $params = [];

        if (isset($payload['nombre'])) {
            $sets[] = 'nombre = ?';
            $params[] = trim($payload['nombre']);
        }
        if (isset($payload['rol'])) {
            $rol = strtoupper(trim($payload['rol']));
            if (!in_array($rol, ROLES_VALIDOS, true)) return ['status' => 'error', 'message' => 'Rol inválido.'];
            $sets[] = 'rol = ?';
            $params[] = $rol;
        }
        if (isset($payload['id_cliente'])) {
            $sets[] = 'id_cliente = ?';
            $params[] = $payload['id_cliente'] ?: null;
        }
        if (isset($payload['activo'])) {
            $sets[] = 'activo = ?';
            $params[] = $payload['activo'] ? 1 : 0;
        }
        if (!empty($payload['password'])) {
            $sets[] = 'password_hash = ?';
            $params[] = password_hash($payload['password'], PASSWORD_BCRYPT);
        }

        if (empty($sets)) return ['status' => 'success', 'message' => 'Sin cambios.'];

        $params[] = $row['id'];
        $sql = "UPDATE usuarios SET " . implode(', ', $sets) . " WHERE id = ?";
        $this->pdo->prepare($sql)->execute($params);

        return ['status' => 'success', 'message' => 'Usuario actualizado.'];
    }

    // ── DESACTIVAR USUARIO ─────────────────────────────────
    public function desactivarUsuario(array $payload): array {
        if (!empty($payload['id'])) {
            $stmt = $this->pdo->prepare("UPDATE usuarios SET activo = 0 WHERE id = ?");
            $stmt->execute([(int)$payload['id']]);
        } else {
            $usuario = strtolower(trim($payload['usuario'] ?? ''));
            if (!$usuario) return ['status' => 'error', 'message' => 'id o usuario requerido.'];
            $stmt = $this->pdo->prepare("UPDATE usuarios SET activo = 0 WHERE usuario = ?");
            $stmt->execute([$usuario]);
        }
        if ($stmt->rowCount() === 0) return ['status' => 'error', 'message' => 'Usuario no encontrado.'];
        return ['status' => 'success', 'message' => 'Usuario desactivado.'];
    }

    // ── LISTAR USUARIOS ────────────────────────────────────
    public function listarUsuarios(): array {
        $stmt = $this->pdo->query(
            "SELECT id, usuario, rol, nombre, id_cliente, activo,
                    DATE_FORMAT(fecha_alta, '%d/%m/%Y %H:%i') AS fecha_alta,
                    DATE_FORMAT(ultimo_acceso, '%d/%m/%Y %H:%i') AS ultimo_acceso
             FROM usuarios ORDER BY id"
        );
        return $stmt->fetchAll();
    }

    // ── PORTAL CLIENTE ─────────────────────────────────────
    public function obtenerDatosCliente(string $idCliente): array {
        $idCliente = trim($idCliente);
        if (!$idCliente) return ['status' => 'error', 'message' => 'id_cliente requerido.'];

        $like = $idCliente . '-%';

        // ── Equipos ──────────────────────────────────────
        $stmt = $this->pdo->prepare(
            "SELECT id, cliente, maquinaria, marca, modelo, serie, id_equipo,
                    DATE_FORMAT(fecha_inspeccion, '%d/%m/%Y') AS fecha,
                    control, estado, qr_codigo, certificado_url, dictamen_url
             FROM equipos
             WHERE control LIKE ? AND estado = 'ENVIADO'
             ORDER BY fecha_inspeccion DESC"
        );
        $stmt->execute([$like]);
        $rows = $stmt->fetchAll();

        $equipos = [];
        $nombreCliente = '';

        foreach ($rows as $r) {
            if (!$nombreCliente) $nombreCliente = $r['cliente'];
            $fechaParaVigencia = null;
            if ($r['fecha']) {
                $parts = explode('/', $r['fecha']);
                if (count($parts) === 3) $fechaParaVigencia = "{$parts[2]}-{$parts[1]}-{$parts[0]}";
            }
            $vigencia = calcularVigencia($fechaParaVigencia);
            $equipos[] = [
                'folio'       => $r['control'],
                'maquinaria'  => $r['maquinaria'],
                'marca'       => $r['marca'],
                'modelo'      => $r['modelo'],
                'serie'       => $r['serie'],
                'id_equipo'   => $r['id_equipo'],
                'fecha'       => $r['fecha'],
                'vencimiento' => $vigencia['vencimiento'],
                'dias'        => $vigencia['dias'],
                'vigente'     => $vigencia['vigente'],
                'link_cert'   => $r['certificado_url'],
                'link_dict'   => $r['dictamen_url'],
                'qr_url'      => $r['qr_codigo'] ? urlQR($r['qr_codigo']) : '',
            ];
        }

        // ── Accesorios ────────────────────────────────────
        $accesorios = [];
        try {
            $stmt = $this->pdo->prepare(
                "SELECT s.id, s.cliente, s.control,
                        DATE_FORMAT(s.fecha, '%d/%m/%Y') AS fecha,
                        COUNT(a.id)            AS total,
                        SUM(a.estado='CUMPLE') AS cumple,
                        SUM(a.estado!='CUMPLE') AS no_cumple
                 FROM accesorios_sesiones s
                 LEFT JOIN accesorios_izaje a ON a.sesion_id = s.id
                 WHERE s.control LIKE ? AND s.estatus = 'EMITIDO'
                 GROUP BY s.id
                 ORDER BY s.fecha DESC"
            );
            $stmt->execute([$like]);
            foreach ($stmt->fetchAll() as $r) {
                if (!$nombreCliente) $nombreCliente = $r['cliente'];
                $accesorios[] = [
                    'id'       => (int)$r['id'],
                    'folio'    => $r['control'],
                    'fecha'    => $r['fecha'],
                    'total'    => (int)$r['total'],
                    'cumple'   => (int)$r['cumple'],
                    'no_cumple'=> (int)$r['no_cumple'],
                ];
            }
        } catch (\PDOException $e) { /* tabla o columna aún no existe */ }

        // ── Personal (cursos) ─────────────────────────────
        $personal = [];
        try {
            $stmt = $this->pdo->prepare(
                "SELECT p.id, p.nombre_completo, p.control, p.empresa_nombre,
                        DATE_FORMAT(p.fecha_curso, '%d/%m/%Y') AS fecha_curso,
                        c.nombre AS curso_nombre,
                        MAX(CASE WHEN pd.tipo_doc='CERTIFICADO' THEN pd.url END) AS url_certificado,
                        MAX(CASE WHEN pd.tipo_doc='DIPLOMA'     THEN pd.url END) AS url_diploma,
                        MAX(CASE WHEN pd.tipo_doc='DC3'         THEN pd.url END) AS url_dc3
                 FROM participantes_cursos p
                 LEFT JOIN cursos c ON c.id = p.curso_id
                 LEFT JOIN participantes_documentos pd ON pd.participante_id = p.id
                 WHERE p.control LIKE ? AND p.estatus = 'EMITIDO'
                 GROUP BY p.id, p.nombre_completo, p.control, p.empresa_nombre,
                          p.fecha_curso, c.nombre
                 ORDER BY p.fecha_curso DESC"
            );
            $stmt->execute([$like]);
            foreach ($stmt->fetchAll() as $r) {
                if (!$nombreCliente && $r['empresa_nombre']) $nombreCliente = $r['empresa_nombre'];
                $personal[] = [
                    'id'              => (int)$r['id'],
                    'nombre'          => $r['nombre_completo'],
                    'folio'           => $r['control'],
                    'curso'           => $r['curso_nombre'],
                    'empresa'         => $r['empresa_nombre'],
                    'fecha_curso'     => $r['fecha_curso'],
                    'url_certificado' => $r['url_certificado'],
                    'url_diploma'     => $r['url_diploma'],
                    'url_dc3'         => $r['url_dc3'],
                ];
            }
        } catch (\PDOException $e) { /* tabla aún no existe */ }

        return [
            'status'         => 'success',
            'nombre_cliente' => $nombreCliente,
            'equipos'        => $equipos,
            'accesorios'     => $accesorios,
            'personal'       => $personal,
        ];
    }
}
