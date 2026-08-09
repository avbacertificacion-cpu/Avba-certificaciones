<?php
/**
 * AVBA Certificaciones — Módulo de Autenticación
 * Migración de Auth.gs → PHP + MySQL
 */

class Auth {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->ensureFirmaColumn();
        $this->ensureRolAdministrativo();
    }

    /**
     * La columna usuarios.rol es un ENUM que originalmente no incluía
     * 'ADMINISTRATIVO'. Sin este valor, MySQL rechaza el INSERT/UPDATE al
     * crear un usuario con ese rol (aunque el backend ya lo acepte), lo que
     * el panel mostraba como "rol no reconocido". Se amplía el ENUM una sola
     * vez (solo si falta el valor).
     */
    private function ensureRolAdministrativo(): void {
        try {
            $col = $this->pdo->query("SHOW COLUMNS FROM usuarios LIKE 'rol'")->fetch(PDO::FETCH_ASSOC);
            $tipo = $col['Type'] ?? '';
            if ($tipo && stripos($tipo, 'ADMINISTRATIVO') === false) {
                $this->pdo->exec(
                    "ALTER TABLE usuarios MODIFY `rol`
                     ENUM('ADMIN','INSPECTOR','CALIDAD','CERTIFICACIONES','CLIENTE','ADMINISTRATIVO') NOT NULL"
                );
            }
        } catch (\Throwable $e) {
            error_log('[Auth] ensureRolAdministrativo: ' . $e->getMessage());
        }
    }

    /** Roles válidos (constante de config + roles nuevos garantizados). */
    private function rolesValidos(): array {
        $base = defined('ROLES_VALIDOS') ? ROLES_VALIDOS : ['ADMIN','INSPECTOR','CALIDAD','CERTIFICACIONES','CLIENTE'];
        return array_values(array_unique(array_merge($base, ['ADMINISTRATIVO'])));
    }

    private function ensureFirmaColumn(): void {
        try {
            $this->pdo->exec("ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS firma_imagen VARCHAR(500) NULL");
        } catch (\PDOException $e) {}
    }

    // ── TABLAS PARTICIPANTE ────────────────────────────────
    private function ensureParticipanteTables(): void {
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS curso_sesiones_acceso (
                  id            INT AUTO_INCREMENT PRIMARY KEY,
                  sesion_nombre VARCHAR(200) NOT NULL,
                  identificador VARCHAR(100) NOT NULL,
                  password_hash VARCHAR(255) NOT NULL,
                  inspector_id  INT NOT NULL,
                  fecha_curso   DATE NULL,
                  activa        TINYINT UNSIGNED NOT NULL DEFAULT 1,
                  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  UNIQUE KEY uk_ident (identificador)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            // Añadir fecha_curso si la tabla ya existía sin esa columna
            try { $this->pdo->exec("ALTER TABLE curso_sesiones_acceso ADD COLUMN IF NOT EXISTS fecha_curso DATE NULL"); } catch (\PDOException $e) {}

            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS sesion_acceso_participantes (
                  sesion_acceso_id INT NOT NULL,
                  participante_id  INT NOT NULL,
                  PRIMARY KEY (sesion_acceso_id, participante_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS sesion_acceso_tokens (
                  token            CHAR(64) NOT NULL PRIMARY KEY,
                  sesion_acceso_id INT NOT NULL,
                  expires_at       DATETIME NOT NULL,
                  created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            // Cursos disponibles en la sesión
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS sesion_cursos (
                  sesion_acceso_id INT NOT NULL,
                  curso_id         INT NOT NULL,
                  PRIMARY KEY (sesion_acceso_id, curso_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            // Empresas configuradas en la sesión
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS sesion_empresas (
                  id               INT AUTO_INCREMENT PRIMARY KEY,
                  sesion_acceso_id INT NOT NULL,
                  nombre_empresa   VARCHAR(300) NOT NULL,
                  primera_parte    VARCHAR(10)  NULL,
                  rfc              VARCHAR(20)  NULL,
                  representante    VARCHAR(200) NULL,
                  direccion        VARCHAR(500) NULL,
                  KEY idx_sa (sesion_acceso_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\PDOException $e) {}
    }

    // ── CREAR SESIÓN DE ACCESO PARA PARTICIPANTES ──────────
    public function crearSesionAcceso(array $payload, int $inspectorId): array {
        $this->ensureParticipanteTables();

        $nombre    = trim($payload['sesion_nombre'] ?? '');
        $password  = trim($payload['password'] ?? '');
        $fecha     = trim($payload['fecha_curso'] ?? '');
        $cursoIds  = array_filter(array_map('intval', $payload['curso_ids']  ?? []), fn($v) => $v > 0);
        $empresas  = $payload['empresas'] ?? [];

        if (!$nombre || !$password) {
            return ['status' => 'error', 'message' => 'Nombre de sesión y contraseña son obligatorios.'];
        }

        // Generar identificador único tipo CUR-XXXXXX
        $identificador = '';
        for ($i = 0; $i < 10; $i++) {
            $candidato = 'CUR-' . strtoupper(substr(base_convert(bin2hex(random_bytes(3)), 16, 36), 0, 6));
            $chk = $this->pdo->prepare("SELECT id FROM curso_sesiones_acceso WHERE identificador = ?");
            $chk->execute([$candidato]);
            if (!$chk->fetch()) { $identificador = $candidato; break; }
        }
        if (!$identificador) return ['status' => 'error', 'message' => 'No se pudo generar identificador.'];

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $this->pdo->prepare(
            "INSERT INTO curso_sesiones_acceso (sesion_nombre, identificador, password_hash, inspector_id, fecha_curso)
             VALUES (?, ?, ?, ?, ?)"
        )->execute([$nombre, $identificador, $hash, $inspectorId, $fecha ?: null]);

        $sesionId = (int)$this->pdo->lastInsertId();

        // Registrar cursos habilitados
        if ($cursoIds) {
            $insCurso = $this->pdo->prepare(
                "INSERT IGNORE INTO sesion_cursos (sesion_acceso_id, curso_id) VALUES (?, ?)"
            );
            foreach ($cursoIds as $cId) $insCurso->execute([$sesionId, $cId]);
        }

        // Registrar empresas configuradas
        if ($empresas) {
            $insEmp = $this->pdo->prepare(
                "INSERT INTO sesion_empresas (sesion_acceso_id, nombre_empresa, primera_parte, rfc, representante, direccion)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            foreach ($empresas as $emp) {
                $insEmp->execute([
                    $sesionId,
                    trim($emp['nombre_empresa'] ?? ''),
                    trim($emp['primera_parte']  ?? '') ?: null,
                    trim($emp['rfc']            ?? '') ?: null,
                    trim($emp['representante']  ?? '') ?: null,
                    trim($emp['direccion']      ?? '') ?: null,
                ]);
            }
        }

        return ['status' => 'success', 'id' => $sesionId, 'identificador' => $identificador];
    }

    // ── AGREGAR EMPRESA A SESIÓN ABIERTA ──────────────────
    public function agregarEmpresaSesion(array $payload, int $inspectorId): array {
        $this->ensureParticipanteTables();
        $sesionId = (int)($payload['sesion_id'] ?? 0);
        $nombre   = trim($payload['nombre_empresa'] ?? '');
        if (!$sesionId || !$nombre) return ['status' => 'error', 'message' => 'Datos incompletos.'];

        // Verificar que la sesión pertenece al inspector y está activa
        $stmt = $this->pdo->prepare(
            "SELECT id FROM curso_sesiones_acceso WHERE id = ? AND inspector_id = ? AND activa = 1"
        );
        $stmt->execute([$sesionId, $inspectorId]);
        if (!$stmt->fetch()) return ['status' => 'error', 'message' => 'Sesión no encontrada o cerrada.'];

        $this->pdo->prepare(
            "INSERT INTO sesion_empresas (sesion_acceso_id, nombre_empresa, primera_parte, rfc, representante, direccion)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([
            $sesionId,
            $nombre,
            trim($payload['primera_parte'] ?? '') ?: null,
            trim($payload['rfc']           ?? '') ?: null,
            trim($payload['representante'] ?? '') ?: null,
            trim($payload['direccion']     ?? '') ?: null,
        ]);

        $id = (int)$this->pdo->lastInsertId();
        return ['status' => 'success', 'id' => $id, 'message' => 'Empresa agregada.'];
    }

    // ── INFO DE SESIÓN PARA PARTICIPANTE ──────────────────
    public function getSesionInfo(int $sesionId): array {
        $this->ensureParticipanteTables();

        $stmt = $this->pdo->prepare(
            "SELECT sesion_nombre, fecha_curso FROM curso_sesiones_acceso WHERE id = ? AND activa = 1"
        );
        $stmt->execute([$sesionId]);
        $sesion = $stmt->fetch();
        if (!$sesion) return ['status' => 'error', 'message' => 'Sesión no encontrada o cerrada.'];

        // Cursos habilitados
        $cursos = $this->pdo->prepare(
            "SELECT c.id, c.nombre FROM sesion_cursos sc
             JOIN cursos c ON c.id = sc.curso_id
             WHERE sc.sesion_acceso_id = ? ORDER BY c.nombre"
        );
        $cursos->execute([$sesionId]);

        // Empresas configuradas
        $empresas = $this->pdo->prepare(
            "SELECT id, nombre_empresa, primera_parte, rfc FROM sesion_empresas
             WHERE sesion_acceso_id = ? ORDER BY nombre_empresa"
        );
        $empresas->execute([$sesionId]);

        return [
            'status'        => 'success',
            'sesion_nombre' => $sesion['sesion_nombre'],
            'fecha_curso'   => $sesion['fecha_curso'],
            'cursos'        => $cursos->fetchAll(),
            'empresas'      => $empresas->fetchAll(),
        ];
    }

    // ── CERRAR SESIÓN DE ACCESO ────────────────────────────
    public function cerrarSesionAcceso(int $sesionId, int $inspectorId): array {
        $this->ensureParticipanteTables();
        $stmt = $this->pdo->prepare(
            "UPDATE curso_sesiones_acceso SET activa = 0 WHERE id = ? AND inspector_id = ?"
        );
        $stmt->execute([$sesionId, $inspectorId]);
        if ($stmt->rowCount() === 0) return ['status' => 'error', 'message' => 'Sesión no encontrada.'];
        return ['status' => 'success', 'message' => 'Acceso cerrado.'];
    }

    // ── LOGIN DE PARTICIPANTE (desde curso_sesiones_acceso) ─
    public function loginParticipante(string $identificador, string $password): ?array {
        $this->ensureParticipanteTables();
        $ident = strtoupper(trim($identificador));

        $stmt = $this->pdo->prepare(
            "SELECT id, sesion_nombre, password_hash FROM curso_sesiones_acceso WHERE identificador = ? AND activa = 1"
        );
        $stmt->execute([$ident]);
        $sesion = $stmt->fetch();

        if (!$sesion || !password_verify($password, $sesion['password_hash'])) return null;

        $token   = generarToken();
        $expires = date('Y-m-d H:i:s', time() + 7200);
        $this->pdo->prepare(
            "INSERT INTO sesion_acceso_tokens (token, sesion_acceso_id, expires_at) VALUES (?, ?, ?)"
        )->execute([$token, $sesion['id'], $expires]);

        return [
            'status'    => 'success',
            'rol'       => 'PARTICIPANTE',
            'nombre'    => $sesion['sesion_nombre'],
            'usuario'   => $ident,
            'sesion_id' => $sesion['id'],
            'token'     => $token,
        ];
    }

    // ── VALIDAR TOKEN DE PARTICIPANTE ─────────────────────
    public function validarTokenParticipante(string $token): ?array {
        $this->ensureParticipanteTables();
        $stmt = $this->pdo->prepare(
            "SELECT t.sesion_acceso_id, a.sesion_nombre, a.activa
             FROM sesion_acceso_tokens t
             JOIN curso_sesiones_acceso a ON a.id = t.sesion_acceso_id
             WHERE t.token = ? AND t.expires_at > NOW()"
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        if (!$row || !$row['activa']) return null;
        return [
            'id'            => (int)$row['sesion_acceso_id'],
            'rol'           => 'PARTICIPANTE',
            'sesion_nombre' => $row['sesion_nombre'],
        ];
    }

    // ── OBTENER PARTICIPANTES EN SESIÓN ───────────────────
    public function getParticipantesEnSesion(int $sesionId): array {
        $this->ensureParticipanteTables();
        $stmt = $this->pdo->prepare(
            "SELECT p.id, p.nombre_completo, p.curp, p.foto_documentacion_url,
                    p.empresa_nombre, c.nombre AS curso_nombre
             FROM sesion_acceso_participantes s
             JOIN participantes_cursos p ON p.id = s.participante_id
             LEFT JOIN cursos c ON c.id = p.curso_id
             WHERE s.sesion_acceso_id = ?
             ORDER BY p.nombre_completo"
        );
        $stmt->execute([$sesionId]);
        return $stmt->fetchAll();
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
            // La constante se lee con defined(), igual que en registrarIntento():
            // si falta en la configuración del servidor, esta línea tumbaba el
            // inicio de sesión justo de quien ya estaba bloqueado.
            $restan = max(1, (int)ceil((strtotime($intento['bloqueado_hasta']) - time()) / 60));
            return ['status' => 'error', 'message' =>
                "Demasiados intentos fallidos. Espera $restan minuto(s) e intenta de nuevo."];
        }

        try {
            $stmt = $this->pdo->prepare(
                "SELECT id, usuario, password_hash, rol, nombre, id_cliente, activo,
                        usuario_padre_id, permiso_sub, permiso_mantenimiento, permiso_rh
                 FROM usuarios WHERE usuario = ?"
            );
            $stmt->execute([$usuario]);
            $row = $stmt->fetch();
        } catch (\PDOException $e) {
            // Compatibilidad: columnas de sub-usuario aún no migradas
            $stmt = $this->pdo->prepare(
                "SELECT id, usuario, password_hash, rol, nombre, id_cliente, activo
                 FROM usuarios WHERE usuario = ?"
            );
            $stmt->execute([$usuario]);
            $row = $stmt->fetch();
        }

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
            // Si no existe usuario regular, intentar como sesión de participante
            if (!$row) {
                $partResult = $this->loginParticipante($usuario, $pass);
                if ($partResult) return $partResult;
            }
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

        $esSub = !empty($row['usuario_padre_id']);
        return [
            'status'        => 'success',
            'rol'           => $row['rol'],
            'nombre'        => $row['nombre'],
            'usuario'       => $usuario,
            'id_cliente'    => $row['id_cliente'] ?? '',
            'token'         => $token,
            'es_subusuario' => $esSub,
            'permiso_sub'   => $esSub ? ($row['permiso_sub'] ?? 'gestion') : null,
            'permiso_mantenimiento' => $esSub ? (int)($row['permiso_mantenimiento'] ?? 0) : 1,
            // Cuenta principal: siempre ve RH. Sub-usuario: solo si se le dio el permiso.
            'permiso_rh'    => $esSub ? (int)($row['permiso_rh'] ?? 0) : 1,
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
        if (!in_array($rol, $this->rolesValidos(), true)) {
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

        $registroStps = trim($payload['registro_stps'] ?? '');
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $this->pdo->prepare(
            "INSERT INTO usuarios (usuario, password_hash, rol, nombre, id_cliente, activo, registro_stps)
             VALUES (?, ?, ?, ?, ?, 1, ?)"
        )->execute([$usuario, $hash, $rol, $nombre, $idCliente ?: null, $registroStps ?: null]);

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
            if (!in_array($rol, $this->rolesValidos(), true)) return ['status' => 'error', 'message' => 'Rol inválido.'];
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
        if (array_key_exists('registro_stps', $payload)) {
            $sets[] = 'registro_stps = ?';
            $params[] = trim($payload['registro_stps']) ?: null;
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
                    DATE_FORMAT(ultimo_acceso, '%d/%m/%Y %H:%i') AS ultimo_acceso,
                    firma_imagen,
                    COALESCE(registro_stps,'') AS registro_stps
             FROM usuarios ORDER BY id"
        );
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['firma_url'] = ($r['firma_imagen'] ?? null)
                ? rtrim(SITE_URL, '/') . '/' . ltrim($r['firma_imagen'], '/')
                : null;
        }
        return $rows;
    }

    // ── SUBIR FIRMA ────────────────────────────────────────
    public function subirFirma(int $usuarioId, array $file): array {
        $stmt = $this->pdo->prepare("SELECT id, firma_imagen FROM usuarios WHERE id = ?");
        $stmt->execute([$usuarioId]);
        $u = $stmt->fetch();
        if (!$u) return ['status' => 'error', 'message' => 'Usuario no encontrado.'];

        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return ['status' => 'error', 'message' => 'Archivo inválido.'];
        }

        $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        if (!in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true)) {
            return ['status' => 'error', 'message' => 'Formato no permitido. Use PNG, JPG, GIF o WEBP.'];
        }

        // Eliminar firma anterior si existe
        if ($u['firma_imagen']) {
            $old = __DIR__ . '/../' . $u['firma_imagen'];
            if (file_exists($old)) @unlink($old);
        }

        $dir = __DIR__ . '/../uploads/firmas/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $nombre  = 'firma_' . $usuarioId . '.' . $ext;
        $destino = $dir . $nombre;

        if (!move_uploaded_file($file['tmp_name'], $destino)) {
            return ['status' => 'error', 'message' => 'Error al guardar la firma.'];
        }

        $ruta = 'uploads/firmas/' . $nombre;
        $this->pdo->prepare("UPDATE usuarios SET firma_imagen = ? WHERE id = ?")->execute([$ruta, $usuarioId]);

        return [
            'status'    => 'success',
            'message'   => 'Firma actualizada.',
            'firma_url' => rtrim(SITE_URL, '/') . '/' . $ruta,
        ];
    }

    // ── ELIMINAR FIRMA ─────────────────────────────────────
    public function eliminarFirma(int $usuarioId): array {
        $stmt = $this->pdo->prepare("SELECT firma_imagen FROM usuarios WHERE id = ?");
        $stmt->execute([$usuarioId]);
        $u = $stmt->fetch();
        if (!$u) return ['status' => 'error', 'message' => 'Usuario no encontrado.'];

        if ($u['firma_imagen']) {
            $path = __DIR__ . '/../' . $u['firma_imagen'];
            if (file_exists($path)) @unlink($path);
            $this->pdo->prepare("UPDATE usuarios SET firma_imagen = NULL WHERE id = ?")->execute([$usuarioId]);
        }
        return ['status' => 'success', 'message' => 'Firma eliminada.'];
    }

    // ── ELIMINAR USUARIO ──────────────────────────────────
    public function eliminarUsuario(int $id, string $usuarioActual): array {
        $stmt = $this->pdo->prepare("SELECT id, usuario, firma_imagen FROM usuarios WHERE id = ?");
        $stmt->execute([$id]);
        $u = $stmt->fetch();
        if (!$u) return ['status' => 'error', 'message' => 'Usuario no encontrado.'];
        if ($u['usuario'] === $usuarioActual)
            return ['status' => 'error', 'message' => 'No puedes eliminar tu propia cuenta.'];

        if ($u['firma_imagen']) {
            $path = __DIR__ . '/../' . $u['firma_imagen'];
            if (file_exists($path)) @unlink($path);
        }

        $this->pdo->prepare("DELETE FROM usuarios WHERE id = ?")->execute([$id]);
        return ['status' => 'success', 'message' => 'Usuario eliminado.'];
    }

    // ── PORTAL CLIENTE ─────────────────────────────────────
    /**
     * @param ?array $certIds  null = cuenta principal (sin restricción).
     *                         Array de equipos.id = sub-usuario; solo verá esas
     *                         certificaciones y NO los demás módulos (accesorios,
     *                         personal de cursos, PND), que no son asignables.
     */
    public function obtenerDatosCliente(string $idCliente, ?array $certIds = null): array {
        $idCliente = trim($idCliente);
        if (!$idCliente) return ['status' => 'error', 'message' => 'id_cliente requerido.'];

        // Normalizar a 5 dígitos con ceros (el control se almacena como 00042-00001)
        if (ctype_digit($idCliente)) {
            $idCliente = str_pad($idCliente, 5, '0', STR_PAD_LEFT);
        }

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

        $certIdsInt = $certIds === null ? null : array_map('intval', $certIds);
        foreach ($rows as $r) {
            // Sub-usuario: solo certificaciones asignadas
            if ($certIdsInt !== null && !in_array((int)$r['id'], $certIdsInt, true)) continue;
            if (!$nombreCliente) $nombreCliente = $r['cliente'];
            $fechaParaVigencia = null;
            if ($r['fecha']) {
                $parts = explode('/', $r['fecha']);
                if (count($parts) === 3) $fechaParaVigencia = "{$parts[2]}-{$parts[1]}-{$parts[0]}";
            }
            $vigencia = calcularVigencia($fechaParaVigencia);
            $equipos[] = [
                'id'          => (int)$r['id'],
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
                        s.qr_codigo, s.cert_url, s.informe_url, s.informe_cumple_url,
                        COUNT(a.id)             AS total,
                        SUM(a.estado='CUMPLE')  AS cumple,
                        SUM(a.estado!='CUMPLE') AS no_cumple
                 FROM accesorios_sesiones s
                 LEFT JOIN accesorios_izaje a ON a.sesion_id = s.id
                 WHERE s.control LIKE ? AND s.estatus = 'EMITIDO'
                 GROUP BY s.id, s.qr_codigo, s.cert_url, s.informe_url, s.informe_cumple_url
                 ORDER BY s.fecha DESC"
            );
            $stmt->execute([$like]);
            foreach ($stmt->fetchAll() as $r) {
                if (!$nombreCliente) $nombreCliente = $r['cliente'];
                $accesorios[] = [
                    'id'                 => (int)$r['id'],
                    'folio'              => $r['control'],
                    'fecha'              => $r['fecha'],
                    'total'              => (int)$r['total'],
                    'cumple'             => (int)$r['cumple'],
                    'no_cumple'          => (int)$r['no_cumple'],
                    'qr_url'             => $r['qr_codigo'] ? urlQR($r['qr_codigo']) : '',
                    'cert_url'           => $r['cert_url'] ?? '',
                    'informe_url'        => $r['informe_url'] ?? '',
                    'informe_cumple_url' => $r['informe_cumple_url'] ?? '',
                ];
            }
        } catch (\PDOException $e) { /* tabla o columna aún no existe */ }

        // ── Arneses y líneas de vida ──────────────────────
        // Cada pieza lleva su propio certificado, así que se devuelven las
        // piezas además del dictamen que ampara la inspección completa.
        $arneses = [];
        try {
            $stmt = $this->pdo->prepare(
                "SELECT s.id, s.cliente, s.control, s.qr_codigo, s.dictamen_url,
                        DATE_FORMAT(s.fecha, '%d/%m/%Y') AS fecha,
                        COUNT(i.id)                       AS total,
                        SUM(i.resultado = 'APTO')         AS aptos,
                        SUM(i.resultado = 'NO APTO')      AS no_aptos
                 FROM arneses_sesiones s
                 LEFT JOIN arneses_items i ON i.sesion_id = s.id
                 WHERE s.control LIKE ? AND s.estatus = 'EMITIDO'
                 GROUP BY s.id, s.cliente, s.control, s.qr_codigo, s.dictamen_url, s.fecha
                 ORDER BY s.fecha DESC"
            );
            $stmt->execute([$like]);
            $sesionesArn = $stmt->fetchAll();

            $stPiezas = $this->pdo->prepare(
                "SELECT i.id, i.id_arnes, i.marca, i.modelo, i.serie, i.resultado,
                        i.qr_codigo, i.cert_url,
                        DATE_FORMAT(i.vigencia,'%d/%m/%Y')     AS vigencia,
                        DATE_FORMAT(i.fecha_retiro,'%d/%m/%Y') AS retiro,
                        COALESCE(t.nombre,'') AS tipo_nombre
                 FROM arneses_items i
                 LEFT JOIN arneses_tipos t ON t.id = i.tipo_id
                 WHERE i.sesion_id = ? ORDER BY i.orden, i.id"
            );
            foreach ($sesionesArn as $r) {
                if (!$nombreCliente) $nombreCliente = $r['cliente'];
                $stPiezas->execute([$r['id']]);
                $piezas = array_map(fn($p) => [
                    'id'        => (int)$p['id'],
                    'tipo'      => $p['tipo_nombre'],
                    'id_arnes'  => $p['id_arnes'] ?? '',
                    'marca'     => trim(($p['marca'] ?? '') . ' ' . ($p['modelo'] ?? '')),
                    'serie'     => $p['serie'] ?? '',
                    'resultado' => $p['resultado'],
                    'vigencia'  => $p['vigencia'] ?? '',
                    'retiro'    => $p['retiro'] ?? '',
                    'cert_url'  => $p['cert_url'] ?? '',
                    'qr_url'    => $p['qr_codigo'] ? urlQR($p['qr_codigo']) : '',
                ], $stPiezas->fetchAll());

                $arneses[] = [
                    'id'           => (int)$r['id'],
                    'folio'        => $r['control'],
                    'fecha'        => $r['fecha'],
                    'total'        => (int)$r['total'],
                    'aptos'        => (int)$r['aptos'],
                    'no_aptos'     => (int)$r['no_aptos'],
                    'qr_url'       => $r['qr_codigo'] ? urlQR($r['qr_codigo']) : '',
                    'dictamen_url' => $r['dictamen_url'] ?? '',
                    'piezas'       => $piezas,
                ];
            }
        } catch (\PDOException $e) { /* el módulo aún no se ha usado */ }

        // ── Personal (cursos) ─────────────────────────────
        $personal = [];
        try {
            $stmt = $this->pdo->prepare(
                "SELECT p.id, p.nombre_completo, p.control, p.empresa_nombre, p.qr_codigo,
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
                          p.qr_codigo, p.fecha_curso, c.nombre
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
                    'qr_url'          => $r['qr_codigo'] ? urlQR($r['qr_codigo']) : '',
                    'url_certificado' => $r['url_certificado'],
                    'url_diploma'     => $r['url_diploma'],
                    'url_dc3'         => $r['url_dc3'],
                ];
            }
        } catch (\PDOException $e) { /* tabla aún no existe */ }

        // ── PND (Pruebas No Destructivas) ────────────────────
        $pnd = [];
        try {
            $stmt = $this->pdo->prepare(
                "SELECT id, control, cliente,
                        DATE_FORMAT(fecha_inspeccion, '%d/%m/%Y') AS fecha,
                        componente, identificacion, resultado, reporte_url
                 FROM pnd_inspecciones
                 WHERE control LIKE ? AND estado = 'APROBADO'
                 ORDER BY fecha_inspeccion DESC"
            );
            $stmt->execute([$like]);
            foreach ($stmt->fetchAll() as $r) {
                if (!$nombreCliente) $nombreCliente = $r['cliente'];
                $pnd[] = [
                    'folio'        => $r['control'],
                    'componente'   => $r['componente'],
                    'identificacion' => $r['identificacion'],
                    'fecha'        => $r['fecha'],
                    'resultado'    => $r['resultado'],
                    'reporte_url'  => $r['reporte_url'] ?? '',
                ];
            }
        } catch (\PDOException $e) { /* tabla aún no existe */ }

        // Sub-usuario: los módulos no asignables se ocultan por completo
        if ($certIds !== null) {
            $accesorios = [];
            $personal   = [];
            $pnd        = [];
            $arneses    = [];
        }

        return [
            'status'         => 'success',
            'nombre_cliente' => $nombreCliente,
            'equipos'        => $equipos,
            'accesorios'     => $accesorios,
            'arneses'        => $arneses,
            'personal'       => $personal,
            'pnd'            => $pnd,
        ];
    }
}
