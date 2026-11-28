<?php
/* ══════════════════════════════════════════════════════════════
   AVBA Control de Accesos — cuentas y sesiones

   Cuentas PROPIAS de esta plataforma (no son las del Sistema de Gestión),
   con los tres perfiles de la maqueta («Quién ve qué»):

     ADMIN    Administración AVBA: todo, incluidas las cuentas.
     PLANTA   Administrador de accesos de la planta: registros, En planta,
              PDF y dar salida.
     ACCESOS  Guardia de caseta: sólo registra entradas y salidas en el acceso,
              en su turno (día o noche), desde cualquier teléfono. Una cuenta de
              guardia puede ser de un EQUIPO FIJO (modo E: sólo entradas; S: sólo
              salidas): lee las credenciales sin botones y va por el turno del reloj.
     CLIENTE  El cliente (la planta): sólo consulta el tablero con gráficas y
              descarga el Excel y el PDF. No registra ni cambia nada.

   Tomado del Sistema de Gestión (api/Auth.php de la rama principal):
   contraseña con password_hash, una sesión por equipo (sesiones_usuario) y
   la espera que crece con los intentos fallidos.
   ══════════════════════════════════════════════════════════════ */

class Auth {
    private PDO $pdo;

    public const ROLES = ['ADMIN', 'PLANTA', 'ACCESOS', 'CLIENTE', 'MEDICO'];
    public const NOMBRES_ROL = [
        'ADMIN'   => 'Administración AVBA',
        'PLANTA'  => 'Administrador de accesos de la planta',
        'ACCESOS' => 'Guardia de caseta',
        'CLIENTE' => 'Cliente (tablero y reportes)',
        'MEDICO'  => 'Servicio médico',
    ];
    private const MIN_CONTRASENA = 8;
    private const MAX_INTENTOS = 5;
    private const OLVIDO_MIN = 15;          // minutos sin fallos y la cuenta vuelve a empezar

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        self::asegurarTablas($pdo);
    }

    /* ── Tablas (se crean solas al primer uso; ver sql/esquema-inicial.sql) ── */
    public static function asegurarTablas(PDO $pdo): void {
        static $hecho = [];
        $k = spl_object_id($pdo);
        if (isset($hecho[$k])) return;
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS usuarios (
               id             INT AUTO_INCREMENT PRIMARY KEY,
               usuario        VARCHAR(50)  NOT NULL UNIQUE,
               password_hash  VARCHAR(255) NOT NULL,
               rol            VARCHAR(30)  NOT NULL,
               nombre         VARCHAR(150) NOT NULL,
               activo         TINYINT(1)   NOT NULL DEFAULT 1,
               fecha_alta     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
               ultimo_acceso  DATETIME     NULL
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS sesiones_usuario (
               token       VARCHAR(100) NOT NULL PRIMARY KEY,
               usuario_id  INT          NOT NULL,
               origen      VARCHAR(20)  NOT NULL DEFAULT '',
               dispositivo VARCHAR(120) NOT NULL DEFAULT '',
               ip          VARCHAR(45)  NULL,
               rol_activo  VARCHAR(30)  NULL,
               creado      DATETIME     NOT NULL,
               expira      DATETIME     NOT NULL,
               KEY idx_usuario (usuario_id),
               KEY idx_expira (expira)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS login_intentos (
               id              INT AUTO_INCREMENT PRIMARY KEY,
               usuario         VARCHAR(50) NOT NULL,
               intentos        TINYINT UNSIGNED NOT NULL DEFAULT 0,
               bloqueado_hasta DATETIME NULL,
               ultima_vez      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
               UNIQUE KEY uk_usuario (usuario)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        // Turno de las cuentas de guardia (D | N), en usuarios.turno
        if (class_exists('ControlAccesos')) { ControlAccesos::asegurarTurnoCuentas($pdo); ControlAccesos::asegurarModoCuentas($pdo); }
        // Llave de subida: una por cada inicio de sesión (ver llaveNueva)
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS ca_llaves_envio (
               llave       CHAR(64)    NOT NULL PRIMARY KEY,
               usuario_id  INT         NOT NULL,
               creado      DATETIME    NOT NULL,
               ultimo_uso  DATETIME    NULL,
               KEY idx_usuario (usuario_id),
               KEY idx_creado (creado)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $hecho[$k] = true;
    }

    /** El modo de una cuenta de equipo fijo ('E' | 'S' | ''), tolerando que la columna no exista. */
    private function modoDe(int $id): string {
        try {
            $st = $this->pdo->prepare("SELECT modo FROM usuarios WHERE id = ?");
            $st->execute([$id]);
            $m = strtoupper((string)$st->fetchColumn());
            return isset(ControlAccesos::MODOS[$m]) ? $m : '';
        } catch (\Throwable $e) { return ''; }
    }

    /** El turno guardado de una cuenta ('D' | 'N' | ''), tolerando que la columna no exista. */
    private function turnoDe(int $id): string {
        try {
            $st = $this->pdo->prepare("SELECT turno FROM usuarios WHERE id = ?");
            $st->execute([$id]);
            $t = (string)$st->fetchColumn();
            return in_array($t, ['D', 'N'], true) ? $t : '';
        } catch (\Throwable $e) { return ''; }
    }

    /* ── Entrar ─────────────────────────────────────────────────────────── */
    public function login(array $p): array {
        $usuario = strtolower(trim((string)($p['usuario'] ?? '')));
        $pass = (string)($p['password'] ?? '');
        if ($usuario === '' || $pass === '') return ['status' => 'error', 'message' => 'Escribe tu usuario y tu contraseña.'];
        if (strlen($usuario) > 50) return ['status' => 'error', 'message' => 'Usuario o contraseña incorrectos.'];

        $st = $this->pdo->prepare("SELECT intentos, bloqueado_hasta FROM login_intentos WHERE usuario = ?");
        $st->execute([$usuario]);
        $int = $st->fetch(PDO::FETCH_ASSOC);
        if ($int && $int['bloqueado_hasta'] && strtotime((string)$int['bloqueado_hasta']) > time()) {
            $restan = max(1, strtotime((string)$int['bloqueado_hasta']) - time());
            return ['status' => 'error', 'espera' => $restan, 'message' => 'Demasiados intentos fallidos. ' . self::textoEspera($restan) . '.'];
        }

        $st = $this->pdo->prepare("SELECT id, usuario, password_hash, rol, nombre, activo FROM usuarios WHERE usuario = ?");
        $st->execute([$usuario]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        // Siempre se verifica (aunque no exista la cuenta) para no delatar cuáles existen por el tiempo
        $hash = $row['password_hash'] ?? password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT);   // en hexadecimal: bcrypt no acepta el carácter nulo
        $valido = password_verify($pass, $hash) && $row && (int)$row['activo'] === 1;
        if (!$valido) {
            $espera = $this->anotarFallo($usuario);
            if ($espera > 0) return ['status' => 'error', 'espera' => $espera, 'message' => 'Demasiados intentos fallidos. ' . self::textoEspera($espera) . '.'];
            return ['status' => 'error', 'message' => 'Usuario o contraseña incorrectos.'];
        }
        $this->pdo->prepare("DELETE FROM login_intentos WHERE usuario = ?")->execute([$usuario]);
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $this->pdo->prepare("UPDATE usuarios SET password_hash = ? WHERE id = ?")->execute([password_hash($pass, PASSWORD_DEFAULT), $row['id']]);
        }
        return $this->abrirSesion($row, 'contraseña', (string)($p['dispositivo_desc'] ?? ''));
    }

    /** Abre una sesión para este equipo y regresa lo que la pantalla guarda (ca_sesion). */
    private function abrirSesion(array $row, string $origen, string $dispositivo): array {
        $token = generarToken();
        $ahora = date('Y-m-d H:i:s');
        $ttl = defined('TOKEN_TTL') ? (int)TOKEN_TTL : 43200;
        $expira = date('Y-m-d H:i:s', time() + max(600, $ttl));
        $this->pdo->prepare("DELETE FROM sesiones_usuario WHERE expira < ?")->execute([$ahora]);
        $this->pdo->prepare(
            "INSERT INTO sesiones_usuario (token, usuario_id, origen, dispositivo, ip, rol_activo, creado, expira) VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([$token, (int)$row['id'], $origen, mb_substr(trim(strip_tags($dispositivo)), 0, 120),
                    mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), (string)$row['rol'], $ahora, $expira]);
        $this->pdo->prepare("UPDATE usuarios SET ultimo_acceso = ? WHERE id = ?")->execute([$ahora, (int)$row['id']]);
        return ['status' => 'success', 'token' => $token, 'usuario' => (string)$row['usuario'],
                'nombre' => (string)$row['nombre'], 'rol' => (string)$row['rol'], 'expira' => $expira,
                'turno' => $row['rol'] === 'ACCESOS' ? $this->turnoDe((int)$row['id']) : '',
                'modo' => $row['rol'] === 'ACCESOS' ? $this->modoDe((int)$row['id']) : '',
                // El cliente sólo consulta: no captura nada que deba subirse después
                'llave_envio' => $row['rol'] === 'CLIENTE' ? '' : self::llaveNueva($this->pdo, (int)$row['id'])];
    }

    /* ── Llave de subida ─────────────────────────────────────────────────
       Lo que un teléfono guarda sin señal debe llegar al servidor aunque la sesión de quien lo
       capturó ya se haya cerrado o vencido, o aunque en el teléfono haya entrado otra cuenta. Al
       iniciar sesión el teléfono recibe una llave (sólo la conoce ese teléfono; aquí se guarda su
       huella) y cada registro la lleva consigo: con ella el registro se sube a nombre de la cuenta
       que lo capturó, con su turno, y sólo sirve para subir registros (ver api/index.php). Dura
       DIAS_LLAVE días y no se borra al cerrar la sesión. */
    public const DIAS_LLAVE = 30;

    public static function llaveNueva(PDO $pdo, int $usuarioId): string {
        $llave = 'L' . bin2hex(random_bytes(24));
        $ahora = date('Y-m-d H:i:s');
        $pdo->prepare("DELETE FROM ca_llaves_envio WHERE creado < ?")->execute([date('Y-m-d H:i:s', time() - self::DIAS_LLAVE * 86400)]);
        $pdo->prepare("INSERT INTO ca_llaves_envio (llave, usuario_id, creado) VALUES (?, ?, ?)")->execute([hash('sha256', $llave), $usuarioId, $ahora]);
        return $llave;
    }

    /** La cuenta (activa) de una llave de subida, como validarToken; null si no existe o ya venció. */
    public static function usuarioDeLlave(PDO $pdo, string $llave): ?array {
        $llave = trim($llave);
        if (!preg_match('/^L[0-9a-f]{48}$/', $llave)) return null;
        self::asegurarTablas($pdo);
        $h = hash('sha256', $llave);
        foreach (['u.turno, u.modo', 'u.turno, NULL AS modo', 'NULL AS turno, NULL AS modo'] as $col) {
            try {
                $st = $pdo->prepare("SELECT u.id, u.usuario, u.rol, u.nombre, $col FROM ca_llaves_envio l INNER JOIN usuarios u ON u.id = l.usuario_id
                                      WHERE l.llave = ? AND l.creado >= ? AND u.activo = 1");
                $st->execute([$h, date('Y-m-d H:i:s', time() - self::DIAS_LLAVE * 86400)]);
                $u = $st->fetch(PDO::FETCH_ASSOC);
                if (!$u) return null;
                $pdo->prepare("UPDATE ca_llaves_envio SET ultimo_uso = ? WHERE llave = ?")->execute([date('Y-m-d H:i:s'), $h]);
                return $u;
            } catch (\Throwable $e) { /* base vieja sin la columna: se intenta sin ella */ }
        }
        return null;
    }

    public function cerrarSesion(?string $token): array {
        if ($token) $this->pdo->prepare("DELETE FROM sesiones_usuario WHERE token = ?")->execute([$token]);
        return ['status' => 'success', 'message' => 'Sesión cerrada.'];
    }

    private static function cerrarSesionesDe(PDO $pdo, int $id, ?string $menos = null): void {
        if ($menos) $pdo->prepare("DELETE FROM sesiones_usuario WHERE usuario_id = ? AND token <> ?")->execute([$id, $menos]);
        else $pdo->prepare("DELETE FROM sesiones_usuario WHERE usuario_id = ?")->execute([$id]);
    }

    /* La espera crece: los primeros bloqueos cuestan segundos y sólo quien
       insiste llega a los minutos (10 s · 30 s · 2 min · 5 min · 15 min). */
    private static function segundosDeBloqueo(int $intentos): int {
        $ronda = max(1, $intentos - self::MAX_INTENTOS + 1);
        $escalera = [10, 30, 120, 300, 900];
        return $escalera[min($ronda, count($escalera)) - 1];
    }
    private static function textoEspera(int $s): string {
        if ($s < 60) return "Espera $s segundo" . ($s === 1 ? '' : 's');
        $m = (int)ceil($s / 60);
        return "Espera $m minuto" . ($m === 1 ? '' : 's');
    }
    private function anotarFallo(string $usuario): int {
        $this->pdo->prepare(
            "DELETE FROM login_intentos WHERE usuario = ? AND (bloqueado_hasta IS NULL OR bloqueado_hasta < NOW())
                AND ultima_vez < DATE_SUB(NOW(), INTERVAL " . self::OLVIDO_MIN . " MINUTE)"
        )->execute([$usuario]);
        $st = $this->pdo->prepare("SELECT intentos FROM login_intentos WHERE usuario = ?");
        $st->execute([$usuario]);
        $n = (int)($st->fetchColumn() ?: 0) + 1;
        $seg = $n >= self::MAX_INTENTOS ? self::segundosDeBloqueo($n) : 0;
        $this->pdo->prepare(
            "INSERT INTO login_intentos (usuario, intentos, bloqueado_hasta) VALUES (?, 1, IF(? > 0, DATE_ADD(NOW(), INTERVAL ? SECOND), NULL))
             ON DUPLICATE KEY UPDATE intentos = LEAST(intentos + 1, 250), bloqueado_hasta = IF(? > 0, DATE_ADD(NOW(), INTERVAL ? SECOND), NULL)"
        )->execute([$usuario, $seg, $seg, $seg, $seg]);
        return $seg;
    }

    /* ── Primera cuenta de Administración ───────────────────────────────────
       Sólo funciona mientras no hay ninguna cuenta y con la clave de
       instalación que se escribió en config/config.php (CLAVE_INSTALACION):
       así nadie más puede adelantarse a crearla al publicar. */
    public function estadoInstalacion(): array {
        $hay = (int)$this->pdo->query("SELECT COUNT(*) FROM usuarios")->fetchColumn();
        return ['status' => 'success', 'instalar' => $hay === 0,
                // La de la plantilla no vale: el repositorio es público
                'con_clave' => defined('CLAVE_INSTALACION') && strlen((string)CLAVE_INSTALACION) >= 8
                               && CLAVE_INSTALACION !== 'cambia-esta-clave'];
    }

    public function instalar(array $p): array {
        $est = $this->estadoInstalacion();
        if (!$est['instalar']) return ['status' => 'error', 'message' => 'La plataforma ya tiene cuentas: entra con la tuya.'];
        if (!$est['con_clave']) return ['status' => 'error', 'message' => 'Falta una clave de instalación propia (CLAVE_INSTALACION, no la de la plantilla) en config/config.php del servidor.'];
        if (!hash_equals((string)CLAVE_INSTALACION, (string)($p['clave'] ?? ''))) {
            $this->anotarFallo('__instalacion__');
            return ['status' => 'error', 'message' => 'La clave de instalación no coincide con la de config/config.php.'];
        }
        $r = $this->crear(['usuario' => $p['usuario'] ?? '', 'nombre' => $p['nombre'] ?? '', 'password' => $p['password'] ?? '', 'rol' => 'ADMIN']);
        if ($r['status'] !== 'success') return $r;
        $st = $this->pdo->prepare("SELECT id, usuario, rol, nombre FROM usuarios WHERE id = ?");
        $st->execute([$r['id']]);
        return $this->abrirSesion($st->fetch(PDO::FETCH_ASSOC), 'instalacion', (string)($p['dispositivo_desc'] ?? '')) + ['instalada' => true];
    }

    /* ── Cuentas (sólo ADMIN) ───────────────────────────────────────────── */
    private static function usuarioValido(string $u): bool {
        return (bool)preg_match('/^[a-z0-9][a-z0-9._-]{2,49}$/', $u);
    }

    public function listar(): array {
        $conTurno = ControlAccesos::asegurarTurnoCuentas($this->pdo);
        $conModo = ControlAccesos::asegurarModoCuentas($this->pdo);
        $filas = $this->pdo->query(
            "SELECT id, usuario, rol, nombre, activo, fecha_alta, ultimo_acceso" . ($conTurno ? ", turno" : ", NULL AS turno") . ($conModo ? ", modo" : ", NULL AS modo") . "
               FROM usuarios ORDER BY FIELD(rol, 'ADMIN', 'PLANTA', 'CLIENTE', 'MEDICO', 'ACCESOS'), FIELD(turno, 'D', 'N'), usuario"
        )->fetchAll(PDO::FETCH_ASSOC);
        $sesiones = [];
        foreach ($this->pdo->query("SELECT usuario_id, COUNT(*) n FROM sesiones_usuario WHERE expira > NOW() GROUP BY usuario_id")->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $sesiones[(int)$f['usuario_id']] = (int)$f['n'];
        }
        return ['status' => 'success', 'roles' => self::NOMBRES_ROL, 'cuentas' => array_map(fn($f) => [
            'id' => (int)$f['id'], 'usuario' => $f['usuario'], 'rol' => $f['rol'], 'nombre' => $f['nombre'],
            'activo' => (bool)$f['activo'], 'fecha_alta' => $f['fecha_alta'], 'ultimo_acceso' => $f['ultimo_acceso'],
            'turno' => $f['rol'] === 'ACCESOS' && in_array((string)$f['turno'], ['D', 'N'], true) ? (string)$f['turno'] : '',
            'modo' => $f['rol'] === 'ACCESOS' && in_array((string)$f['modo'], ['E', 'S'], true) ? (string)$f['modo'] : '',
            'sesiones' => $sesiones[(int)$f['id']] ?? 0,
        ], $filas)];
    }

    public function crear(array $p): array {
        $usuario = strtolower(trim((string)($p['usuario'] ?? '')));
        $nombre = mb_substr(textoEstandar($p['nombre'] ?? ''), 0, 150);
        $rol = strtoupper(trim((string)($p['rol'] ?? '')));
        $pass = (string)($p['password'] ?? '');
        if (!self::usuarioValido($usuario)) return ['status' => 'error', 'message' => 'El usuario lleva de 3 a 50 letras minúsculas, números, punto, guion o guion bajo (sin espacios ni acentos).'];
        if ($nombre === '') return ['status' => 'error', 'message' => 'Escribe el nombre de la cuenta.'];
        if (!in_array($rol, self::ROLES, true)) return ['status' => 'error', 'message' => 'Elige el perfil de la cuenta.'];
        if (mb_strlen($pass) < self::MIN_CONTRASENA) return ['status' => 'error', 'message' => 'La contraseña debe tener al menos ' . self::MIN_CONTRASENA . ' caracteres.'];
        // Guardia de caseta: su turno (día o noche) decide el turno de todo lo que registra.
        // Equipo fijo (sólo entradas o sólo salidas): sin turno, va por el reloj.
        $modo = $rol === 'ACCESOS' ? strtoupper(trim((string)($p['modo'] ?? ''))) : '';
        if ($modo !== '' && !isset(ControlAccesos::MODOS[$modo])) return ['status' => 'error', 'message' => 'Elige cómo registra la cuenta.'];
        $turno = strtoupper(trim((string)($p['turno'] ?? '')));
        if ($rol === 'ACCESOS' && $modo === '' && !in_array($turno, ['D', 'N'], true)) return ['status' => 'error', 'message' => 'Elige el turno del guardia: día o noche.'];
        if ($rol !== 'ACCESOS' || $modo !== '') $turno = '';
        $st = $this->pdo->prepare("SELECT id FROM usuarios WHERE usuario = ?");
        $st->execute([$usuario]);
        if ($st->fetchColumn()) return ['status' => 'error', 'message' => "Ya existe la cuenta «{$usuario}»."];
        $this->pdo->prepare("INSERT INTO usuarios (usuario, password_hash, rol, nombre, activo) VALUES (?, ?, ?, ?, 1)")
                  ->execute([$usuario, password_hash($pass, PASSWORD_DEFAULT), $rol, $nombre]);
        $id = (int)$this->pdo->lastInsertId();
        $msg = "Cuenta «{$usuario}» creada.";
        if ($turno !== '') {
            if (!ControlAccesos::asegurarTurnoCuentas($this->pdo)) return ['status' => 'success', 'id' => $id, 'message' => $msg . ' El servidor no pudo guardar su turno: avisa a AVBA.'];
            $this->pdo->prepare("UPDATE usuarios SET turno = ? WHERE id = ?")->execute([$turno, $id]);
            $msg .= ' ' . ucfirst(ControlAccesos::TURNOS[$turno]) . '.';
        }
        if ($modo !== '') {
            if (!ControlAccesos::asegurarModoCuentas($this->pdo)) return ['status' => 'success', 'id' => $id, 'message' => $msg . ' El servidor no pudo guardar su modo: avisa a AVBA.'];
            $this->pdo->prepare("UPDATE usuarios SET modo = ? WHERE id = ?")->execute([$modo, $id]);
            $msg .= ' Equipo fijo de ' . ControlAccesos::MODOS[$modo] . 's.';
        }
        return ['status' => 'success', 'id' => $id, 'message' => $msg];
    }

    /** Cambia nombre, perfil, contraseña o si está activa. No deja la plataforma sin Administración. */
    public function editar(array $p, array $yo): array {
        $id = (int)($p['id'] ?? 0);
        $st = $this->pdo->prepare("SELECT id, usuario, rol, nombre, activo FROM usuarios WHERE id = ?");
        $st->execute([$id]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!$c) return ['status' => 'error', 'message' => 'Esa cuenta no existe.'];
        $sets = []; $args = []; $cerrar = false;

        if (array_key_exists('nombre', $p)) {
            $n = mb_substr(textoEstandar($p['nombre']), 0, 150);
            if ($n === '') return ['status' => 'error', 'message' => 'El nombre no puede quedar vacío.'];
            $sets[] = 'nombre = ?'; $args[] = $n;
        }
        $rol = $c['rol'];
        if (array_key_exists('rol', $p)) {
            $rol = strtoupper(trim((string)$p['rol']));
            if (!in_array($rol, self::ROLES, true)) return ['status' => 'error', 'message' => 'Elige el perfil de la cuenta.'];
            if ($rol !== $c['rol']) { $sets[] = 'rol = ?'; $args[] = $rol; $cerrar = true; }
        }
        $activo = (int)$c['activo'];
        if (array_key_exists('activo', $p)) {
            $activo = !empty($p['activo']) ? 1 : 0;
            if ($activo !== (int)$c['activo']) { $sets[] = 'activo = ?'; $args[] = $activo; if (!$activo) $cerrar = true; }
        }
        if (!empty($p['password'])) {
            if (mb_strlen((string)$p['password']) < self::MIN_CONTRASENA) return ['status' => 'error', 'message' => 'La contraseña debe tener al menos ' . self::MIN_CONTRASENA . ' caracteres.'];
            $sets[] = 'password_hash = ?'; $args[] = password_hash((string)$p['password'], PASSWORD_DEFAULT); $cerrar = true;
        }
        // Modo (equipo fijo de entradas o de salidas) y turno del guardia
        $modoAntes = $this->modoDe($id);
        $modo = $rol === 'ACCESOS' ? $modoAntes : '';
        if ($rol === 'ACCESOS' && array_key_exists('modo', $p)) {
            $modo = strtoupper(trim((string)$p['modo']));
            if ($modo !== '' && !isset(ControlAccesos::MODOS[$modo])) return ['status' => 'error', 'message' => 'Elige cómo registra la cuenta.'];
        }
        if ($modo !== $modoAntes) {
            if (!ControlAccesos::asegurarModoCuentas($this->pdo)) return ['status' => 'error', 'message' => 'El servidor no pudo guardar el modo: avisa a AVBA.'];
            $sets[] = 'modo = ?'; $args[] = $modo !== '' ? $modo : null;
        }
        $turnoAntes = $this->turnoDe($id);
        $turno = $rol === 'ACCESOS' ? $turnoAntes : '';
        if ($rol === 'ACCESOS' && $modo === '' && array_key_exists('turno', $p)) {
            $turno = strtoupper(trim((string)$p['turno']));
            if (!in_array($turno, ['D', 'N'], true)) return ['status' => 'error', 'message' => 'Elige el turno del guardia: día o noche.'];
        }
        if ($modo !== '') $turno = '';                                   // equipo fijo: turno del reloj
        if ($turno !== $turnoAntes) {
            if (!ControlAccesos::asegurarTurnoCuentas($this->pdo)) return ['status' => 'error', 'message' => 'El servidor no pudo guardar el turno: avisa a AVBA.'];
            $sets[] = 'turno = ?'; $args[] = $turno !== '' ? $turno : null;
        }
        if (!$sets) return ['status' => 'success', 'message' => 'Sin cambios.'];

        $esYo = (int)$c['id'] === (int)($yo['id'] ?? 0);
        if ($esYo && ($rol !== 'ADMIN' || !$activo)) return ['status' => 'error', 'message' => 'No puedes quitarte el perfil de Administración ni desactivar tu propia cuenta.'];
        if ($c['rol'] === 'ADMIN' && ($rol !== 'ADMIN' || !$activo)) {
            $n = (int)$this->pdo->query("SELECT COUNT(*) FROM usuarios WHERE rol = 'ADMIN' AND activo = 1")->fetchColumn();
            if ($n <= 1) return ['status' => 'error', 'message' => 'Tiene que quedar al menos una cuenta de Administración activa.'];
        }

        $args[] = $id;
        $this->pdo->prepare("UPDATE usuarios SET " . implode(', ', $sets) . " WHERE id = ?")->execute($args);
        // Con contraseña nueva, perfil nuevo o desactivada: se cierran sus sesiones abiertas
        // (las mías, menos la de este equipo).
        if ($cerrar) self::cerrarSesionesDe($this->pdo, $id, $esYo ? ($yo['token'] ?? null) : null);
        if (!empty($p['password'])) $this->pdo->prepare("DELETE FROM login_intentos WHERE usuario = ?")->execute([$c['usuario']]);
        return ['status' => 'success', 'message' => "Cuenta «{$c['usuario']}» actualizada."
                . ($modo !== $modoAntes ? ($modo !== '' ? ' Ahora es un equipo fijo de ' . ControlAccesos::MODOS[$modo] . 's.' : ' Ahora registra con botones.') : '')
                . ($turno !== $turnoAntes && $turno !== '' ? ' Ahora es del ' . ControlAccesos::TURNOS[$turno] . '.' : '')];
    }

    /** Cualquier cuenta cambia su propia contraseña (con la actual). */
    public function cambiarMiContrasena(array $p, array $yo): array {
        $st = $this->pdo->prepare("SELECT password_hash FROM usuarios WHERE id = ?");
        $st->execute([(int)$yo['id']]);
        if (!password_verify((string)($p['actual'] ?? ''), (string)$st->fetchColumn())) return ['status' => 'error', 'message' => 'La contraseña actual no es correcta.'];
        $nueva = (string)($p['nueva'] ?? '');
        if (mb_strlen($nueva) < self::MIN_CONTRASENA) return ['status' => 'error', 'message' => 'La contraseña nueva debe tener al menos ' . self::MIN_CONTRASENA . ' caracteres.'];
        $this->pdo->prepare("UPDATE usuarios SET password_hash = ? WHERE id = ?")->execute([password_hash($nueva, PASSWORD_DEFAULT), (int)$yo['id']]);
        self::cerrarSesionesDe($this->pdo, (int)$yo['id'], $yo['token'] ?? null);
        return ['status' => 'success', 'message' => 'Contraseña cambiada. Las sesiones de tus otros equipos se cerraron.'];
    }
}
