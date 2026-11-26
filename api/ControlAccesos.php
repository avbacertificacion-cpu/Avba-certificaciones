<?php
/* ══════════════════════════════════════════════════════════════
   AVBA — Control de accesos

   Bitácora de entradas y salidas de un sitio con la fotografía de la
   identificación de cada persona (cualquier identificación, no sólo la INE)
   o con la TARJETA de identificación leída en el teléfono de la caseta.

     · Con la app AVBA Caseta (mobile/caseta) el guardia lee la tarjeta: el
       teléfono reconoce el nombre y el código sin internet y el registro
       llega sin foto, sólo con nombre, código, tipo, hora y punto de control.
     · El PDF de datos junta la entrada y la salida de cada persona por su
       código: nombre, código, hora de entrada y hora de salida.

     · Registrar: tipo (entrada / salida), foto, punto de control y una
       nota opcional. La hora es la del dispositivo al tomar la foto: en la
       app se puede registrar sin señal y el envío llega después.
     · Cada registro lleva un identificador del dispositivo (uid): si la app
       reenvía uno que el servidor ya tenía (se cortó la señal a medio
       envío), no se duplica.
     · El control por TURNO (día 06:30–18:30, noche 18:30–06:30): lista con
       hora, foto y quién registró, y los totales de entradas y salidas.
     · El PDF del turno: las fotos de ENTRADAS y de SALIDAS, cada una con su
       etiqueta (ENTRADA 001 · 24/11 06:15:32), pensado para subirlo a Claude y
       que extraiga los números de identificación y cruce entradas y
       salidas.

   Las fotos de identificaciones son datos personales: se guardan en
   uploads/accesos/, cerrada al acceso directo, y sólo se entregan con sesión
   a través de descargar.php.
   ══════════════════════════════════════════════════════════════ */

class ControlAccesos {
    private PDO $pdo;

    /** Los tres perfiles de la plataforma (ver Auth.php): Administración AVBA,
     *  administrador de accesos de la planta y guardia de caseta. */
    public const ROLES = ['ADMIN', 'PLANTA', 'ACCESOS'];

    private const DIAS = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
    private const MESES = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto',
                           'septiembre', 'octubre', 'noviembre', 'diciembre'];

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public static function permitido(?array $usr): bool {
        return $usr && in_array((string)$usr['rol'], self::ROLES, true);
    }

    /** Administración AVBA o administrador de accesos de la planta: ven todo,
     *  dan salida y liberan equipos. Sólo ADMIN asigna puntos y crea cuentas. */
    public static function esGestor(?array $usr): bool {
        return in_array((string)($usr['rol'] ?? ''), ['ADMIN', 'PLANTA'], true);
    }

    /** Puntos de control fijos de la planta. Cada uno tiene su propia cuenta de
     *  caseta y trabaja en un solo equipo (ver accesos_puntos). */
    public const PUNTOS = ['PUNTO DE CONTROL 1', 'PUNTO DE CONTROL 2', 'PUNTO DE CONTROL 3', 'PUNTO DE CONTROL 4'];

    public static function nombrePunto(string $p): string {
        return preg_match('/^PUNTO DE CONTROL (\d+)$/', $p, $m) ? 'Punto de control ' . $m[1] : $p;
    }

    /**
     * Cuenta de caseta (rol ACCESOS): sólo registra. Ve únicamente sus
     * propios registros de hoy (para corregir un error) y no genera el PDF.
     */
    public static function esCaseta(?array $usr): bool {
        return ($usr['rol'] ?? '') === 'ACCESOS';
    }

    /* ── Tabla y carpeta ────────────────────────────────────────────────── */
    public static function asegurarTabla(PDO $pdo): void {
        static $hecho = [];
        $k = spl_object_id($pdo);
        if (isset($hecho[$k])) return;
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS accesos_registros (
               id             INT AUTO_INCREMENT PRIMARY KEY,
               uid            VARCHAR(64)  NOT NULL,
               tipo           VARCHAR(10)  NOT NULL,
               fecha          DATE         NOT NULL,
               capturado      DATETIME     NOT NULL,
               recibido       DATETIME     NOT NULL,
               lugar          VARCHAR(120) NOT NULL DEFAULT '',
               nota           VARCHAR(255) NOT NULL DEFAULT '',
               foto           VARCHAR(255) NOT NULL DEFAULT '',
               foto_mini      VARCHAR(255) NOT NULL DEFAULT '',
               usuario        VARCHAR(50)  NOT NULL DEFAULT '',
               usuario_nombre VARCHAR(150) NOT NULL DEFAULT '',
               turno_fecha    DATE         NULL,
               turno          CHAR(1)      NULL,
               nombre         VARCHAR(150) NOT NULL DEFAULT '',
               codigo         VARCHAR(32)  NOT NULL DEFAULT '',
               origen         VARCHAR(12)  NOT NULL DEFAULT 'foto',
               UNIQUE KEY uk_uid (uid),
               KEY idx_fecha (fecha),
               KEY idx_turno (turno_fecha, turno),
               KEY idx_codigo (codigo)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        // Tablas creadas antes de los turnos: se agregan las columnas y se
        // calcula el turno de lo que ya estaba registrado (una sola vez).
        try {
            $pdo->query("SELECT turno, turno_fecha FROM accesos_registros LIMIT 1");
        } catch (\Throwable $e) {
            try { $pdo->exec("ALTER TABLE accesos_registros ADD COLUMN turno_fecha DATE NULL"); } catch (\Throwable $e2) {}
            try { $pdo->exec("ALTER TABLE accesos_registros ADD COLUMN turno CHAR(1) NULL"); } catch (\Throwable $e2) {}
            try { $pdo->exec("CREATE INDEX idx_turno ON accesos_registros (turno_fecha, turno)"); } catch (\Throwable $e2) {}
            try {
                $up = $pdo->prepare("UPDATE accesos_registros SET turno_fecha = ?, turno = ? WHERE id = ?");
                foreach ($pdo->query("SELECT id, tipo, capturado FROM accesos_registros WHERE turno IS NULL")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    [$tf, $t] = self::turnoDe((string)$r['tipo'], (string)$r['capturado']);
                    $up->execute([$tf, $t, (int)$r['id']]);
                }
            } catch (\Throwable $e2) {
                error_log('[ControlAccesos] turnos: ' . $e2->getMessage());
            }
        }
        // Tablas creadas antes de la lectura de tarjetas: nombre, código y origen
        // (foto | tarjeta | manual). Lo ya registrado queda como «foto».
        try {
            $pdo->query("SELECT codigo, nombre, origen FROM accesos_registros LIMIT 1");
        } catch (\Throwable $e) {
            try { $pdo->exec("ALTER TABLE accesos_registros ADD COLUMN nombre VARCHAR(150) NOT NULL DEFAULT ''"); } catch (\Throwable $e2) {}
            try { $pdo->exec("ALTER TABLE accesos_registros ADD COLUMN codigo VARCHAR(32) NOT NULL DEFAULT ''"); } catch (\Throwable $e2) {}
            try { $pdo->exec("ALTER TABLE accesos_registros ADD COLUMN origen VARCHAR(12) NOT NULL DEFAULT 'foto'"); } catch (\Throwable $e2) {}
            try { $pdo->exec("CREATE INDEX idx_codigo ON accesos_registros (codigo)"); } catch (\Throwable $e2) {}
        }
        $hecho[$k] = true;
    }

    /* ── Puntos de control: una cuenta y un solo equipo por punto ──────────
       accesos_puntos guarda, para cada uno de los 4 puntos fijos, la cuenta de
       caseta asignada (la asigna Administración) y el equipo ligado: el primero
       que entra con esa cuenta. Otro equipo con la misma cuenta queda fuera
       hasta que Administración libere el punto. El identificador del equipo lo
       genera la pantalla y lo guarda en el propio teléfono o navegador. */
    private static array $puntosListos = [];
    public static function asegurarPuntos(PDO $pdo): bool {
        $k = spl_object_id($pdo);
        if (isset(self::$puntosListos[$k])) return self::$puntosListos[$k];
        try {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS accesos_puntos (
                   punto            VARCHAR(40)  NOT NULL PRIMARY KEY,
                   usuario          VARCHAR(50)  NULL,
                   dispositivo      VARCHAR(80)  NULL,
                   dispositivo_desc VARCHAR(150) NOT NULL DEFAULT '',
                   ligado           DATETIME     NULL,
                   ultimo_uso       DATETIME     NULL,
                   UNIQUE KEY uk_usuario (usuario)
                 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            $ins = $pdo->prepare("INSERT IGNORE INTO accesos_puntos (punto) VALUES (?)");
            foreach (self::PUNTOS as $p) $ins->execute([$p]);
            self::$puntosListos[$k] = true;
        } catch (\Throwable $e) {
            error_log('[ControlAccesos] puntos de control: ' . $e->getMessage());
            self::$puntosListos[$k] = false;
        }
        return self::$puntosListos[$k];
    }

    private static function equipoValido($v): string {
        $v = is_scalar($v) ? (string)$v : '';
        return preg_match('/^[A-Za-z0-9-]{8,80}$/', $v) ? $v : '';
    }

    /**
     * Revisa que una cuenta de caseta tenga su punto y que use su equipo. El
     * primer equipo que llega queda ligado. Regresa ['ok' => true, 'punto']
     * o ['ok' => false, 'error' => respuesta para la pantalla].
     */
    private function equipoDeCaseta(array $p, array $usr): array {
        if (!self::esCaseta($usr) || !self::asegurarPuntos($this->pdo)) return ['ok' => true, 'punto' => ''];
        $st = $this->pdo->prepare("SELECT * FROM accesos_puntos WHERE usuario = ?");
        $st->execute([(string)($usr['usuario'] ?? '')]);
        $f = $st->fetch(PDO::FETCH_ASSOC);
        if (!$f) {
            return ['ok' => false, 'error' => ['status' => 'error', 'codigo' => 'SIN_PUNTO',
                    'message' => 'Esta cuenta no tiene punto de control asignado. Pide a Administración que te asigne uno.']];
        }
        $eq = self::equipoValido($p['dispositivo'] ?? '');
        if ($eq === '') {
            return ['ok' => false, 'error' => ['status' => 'error', 'codigo' => 'SIN_EQUIPO',
                    'message' => 'No se pudo identificar este equipo. Cierra y vuelve a abrir la pantalla.']];
        }
        if ((string)$f['dispositivo'] === '') {
            $desc = mb_substr(trim((string)preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string)($p['dispositivo_desc'] ?? ''))), 0, 150);
            $up = $this->pdo->prepare("UPDATE accesos_puntos SET dispositivo = ?, dispositivo_desc = ?, ligado = ?, ultimo_uso = ?
                                        WHERE punto = ? AND usuario = ? AND (dispositivo IS NULL OR dispositivo = '')");
            $ahora = date('Y-m-d H:i:s');
            $up->execute([$eq, $desc, $ahora, $ahora, $f['punto'], $f['usuario']]);
            if ($up->rowCount() === 0) {                     // otro equipo se ligó al mismo tiempo
                $st->execute([(string)$usr['usuario']]);
                $f = $st->fetch(PDO::FETCH_ASSOC) ?: $f;
            } else {
                $f['dispositivo'] = $eq;
            }
        }
        if ((string)$f['dispositivo'] !== $eq) {
            return ['ok' => false, 'error' => ['status' => 'error', 'codigo' => 'OTRO_EQUIPO', 'punto' => $f['punto'],
                    'message' => 'El ' . self::nombrePunto($f['punto']) . ' ya está en otro equipo'
                               . ($f['dispositivo_desc'] !== '' ? ' (' . $f['dispositivo_desc'] . ')' : '')
                               . '. Para usarlo en éste, el administrador de accesos debe liberarlo en Control de accesos → Puntos de control.']];
        }
        if (!$f['ultimo_uso'] || strtotime((string)$f['ultimo_uso']) < time() - 60) {
            $this->pdo->prepare("UPDATE accesos_puntos SET ultimo_uso = ? WHERE punto = ?")->execute([date('Y-m-d H:i:s'), $f['punto']]);
        }
        return ['ok' => true, 'punto' => (string)$f['punto']];
    }

    /** La cuenta de caseta pregunta su punto al entrar (y liga su equipo) */
    public function miPunto(array $p, array $usr): array {
        if (!self::esCaseta($usr)) return ['status' => 'success', 'punto' => ''];
        $v = $this->equipoDeCaseta($p, $usr);
        if (!$v['ok']) return $v['error'];
        return ['status' => 'success', 'punto' => $v['punto'], 'nombre_punto' => self::nombrePunto($v['punto'])];
    }

    /** Administración: los 4 puntos con su cuenta y su equipo, y las cuentas de caseta */
    public function puntos(array $usr): array {
        if (self::esCaseta($usr)) return ['status' => 'error', 'message' => 'Esta cuenta sólo registra entradas y salidas.'];
        if (!self::asegurarPuntos($this->pdo)) return ['status' => 'error', 'message' => 'No se pudieron preparar los puntos de control en el servidor.'];
        $filas = $this->pdo->query("SELECT * FROM accesos_puntos")->fetchAll(PDO::FETCH_ASSOC);
        $porPunto = [];
        foreach ($filas as $f) $porPunto[$f['punto']] = $f;
        $cuentas = [];
        try {
            $cuentas = $this->pdo->query("SELECT usuario, nombre, activo FROM usuarios WHERE rol = 'ACCESOS' ORDER BY usuario")->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {}
        $nombres = [];
        foreach ($cuentas as $c) $nombres[$c['usuario']] = $c['nombre'];
        $out = [];
        foreach (self::PUNTOS as $pt) {
            $f = $porPunto[$pt] ?? [];
            $out[] = [
                'punto' => $pt, 'nombre' => self::nombrePunto($pt),
                'usuario' => (string)($f['usuario'] ?? ''), 'usuario_nombre' => (string)($nombres[$f['usuario'] ?? ''] ?? ''),
                'dispositivo' => (string)($f['dispositivo'] ?? '') !== '', 'dispositivo_desc' => (string)($f['dispositivo_desc'] ?? ''),
                'ligado' => $f['ligado'] ?? null, 'ultimo_uso' => $f['ultimo_uso'] ?? null,
            ];
        }
        return ['status' => 'success', 'puntos' => $out,
                'cuentas' => array_map(fn($c) => ['usuario' => $c['usuario'], 'nombre' => $c['nombre'], 'activo' => (bool)$c['activo']], $cuentas),
                'puede_editar' => ($usr['rol'] ?? '') === 'ADMIN', 'puede_liberar' => self::esGestor($usr)];
    }

    /** Administración: qué cuenta de caseta trabaja en cada punto ('' = ninguna) */
    public function asignarPunto(array $p, array $usr): array {
        if (($usr['rol'] ?? '') !== 'ADMIN') return ['status' => 'error', 'message' => 'Sólo Administración asigna los puntos de control.'];
        if (!self::asegurarPuntos($this->pdo)) return ['status' => 'error', 'message' => 'No se pudieron preparar los puntos de control en el servidor.'];
        $punto = textoEstandar($p['punto'] ?? '');
        if (!in_array($punto, self::PUNTOS, true)) return ['status' => 'error', 'message' => 'Ese punto de control no existe.'];
        $cuenta = trim((string)($p['usuario'] ?? ''));
        if ($cuenta !== '') {
            $st = $this->pdo->prepare("SELECT usuario FROM usuarios WHERE usuario = ? AND rol = 'ACCESOS'");
            $st->execute([$cuenta]);
            $cuenta = (string)$st->fetchColumn();
            if ($cuenta === '') return ['status' => 'error', 'message' => 'Esa cuenta no existe o no es de control de accesos.'];
        }
        $this->pdo->beginTransaction();
        try {
            if ($cuenta !== '') {
                // Una cuenta sólo puede estar en un punto: se quita de donde estuviera
                $this->pdo->prepare("UPDATE accesos_puntos SET usuario = NULL, dispositivo = NULL, dispositivo_desc = '', ligado = NULL
                                     WHERE usuario = ? AND punto <> ?")->execute([$cuenta, $punto]);
            }
            $actual = $this->pdo->prepare("SELECT usuario FROM accesos_puntos WHERE punto = ?");
            $actual->execute([$punto]);
            if ((string)$actual->fetchColumn() !== $cuenta) {   // cuenta nueva: el equipo se vuelve a ligar
                $this->pdo->prepare("UPDATE accesos_puntos SET usuario = ?, dispositivo = NULL, dispositivo_desc = '', ligado = NULL WHERE punto = ?")
                          ->execute([$cuenta !== '' ? $cuenta : null, $punto]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return ['status' => 'success', 'message' => self::nombrePunto($punto) . ': ' . ($cuenta !== '' ? 'cuenta ' . $cuenta . '.' : 'sin cuenta.')];
    }

    /** Administración: libera el equipo de un punto (el siguiente que entre queda ligado) */
    public function liberarEquipo(array $p, array $usr): array {
        if (!self::esGestor($usr)) return ['status' => 'error', 'message' => 'Sólo el administrador de accesos libera los equipos.'];
        if (!self::asegurarPuntos($this->pdo)) return ['status' => 'error', 'message' => 'No se pudieron preparar los puntos de control en el servidor.'];
        $punto = textoEstandar($p['punto'] ?? '');
        if (!in_array($punto, self::PUNTOS, true)) return ['status' => 'error', 'message' => 'Ese punto de control no existe.'];
        $this->pdo->prepare("UPDATE accesos_puntos SET dispositivo = NULL, dispositivo_desc = '', ligado = NULL WHERE punto = ?")->execute([$punto]);
        return ['status' => 'success', 'message' => self::nombrePunto($punto) . ' liberado: el próximo equipo que entre con su cuenta queda ligado.'];
    }

    /** ¿La tabla ya tiene nombre, código y origen? Si la migración no se pudo
     *  aplicar, el módulo sigue trabajando como antes (sólo con fotos). */
    private static array $conTarjeta = [];
    public static function conTarjeta(PDO $pdo): bool {
        self::asegurarTabla($pdo);
        $k = spl_object_id($pdo);
        if (!isset(self::$conTarjeta[$k])) {
            try { $pdo->query("SELECT codigo, nombre, origen FROM accesos_registros LIMIT 1"); self::$conTarjeta[$k] = true; }
            catch (\Throwable $e) { self::$conTarjeta[$k] = false; error_log('[ControlAccesos] sin columnas de tarjeta: ' . $e->getMessage()); }
        }
        return self::$conTarjeta[$k];
    }

    /* ── Turnos ─────────────────────────────────────────────────────────────
       Día: 06:30 a 18:30. Noche: 18:30 a 06:30 del día siguiente (la fecha de
       un turno de noche es la del día en que empieza).

       En el cambio de turno salen unos y entran otros por la misma caseta, a
       la misma hora. Por eso el turno no se decide sólo por el reloj:
         · una ENTRADA cuenta para el turno que empieza: desde 2 horas antes
           de su inicio (de 04:30 a 16:30 es el de día);
         · una SALIDA cuenta para el turno que termina: hasta 2 horas después
           de su fin (de 08:30 a 20:30 es el de día).
       Así las entradas de la mañana y las salidas de la tarde del personal de
       día quedan en el mismo turno, y las de la noche con su mañana.
       Además, la SALIDA de quien tiene su ENTRADA abierta (últimas 16 h) va al
       turno de esa entrada (turnoDeEntradaAbierta): el reloj sólo decide cuando
       no hay entrada con qué juntarla. */
    public const CAMBIO_DIA   = 390;    // 06:30, en minutos
    public const CAMBIO_NOCHE = 1110;   // 18:30
    public const MARGEN       = 120;    // 2 horas

    /** @return array{0:string,1:string} [fecha del turno, 'D' | 'N'] */
    public static function turnoDe(string $tipo, string $capturado): array {
        $d = substr($capturado, 0, 10);
        $m = (int)substr($capturado, 11, 2) * 60 + (int)substr($capturado, 14, 2);
        if ($tipo === 'salida') { $ini = self::CAMBIO_DIA + self::MARGEN; $fin = self::CAMBIO_NOCHE + self::MARGEN; }
        else                    { $ini = self::CAMBIO_DIA - self::MARGEN; $fin = self::CAMBIO_NOCHE - self::MARGEN; }
        if ($m >= $ini && $m < $fin) return [$d, 'D'];
        if ($m >= $fin) return [$d, 'N'];
        return [date('Y-m-d', strtotime($d . ' 12:00:00 -1 day')), 'N'];
    }

    /** '2026-11-24-D' → ['2026-11-24', 'D'], o null si no es una clave de turno */
    private static function claveTurno($v): ?array {
        return preg_match('/^(\d{4}-\d{2}-\d{2})-([DN])$/', (string)$v, $m) ? [$m[1], $m[2]] : null;
    }

    public static function nombreTurno(string $fecha, string $t): string {
        if ($t === 'D') return 'Turno de día · ' . self::fechaLarga($fecha) . ' (06:30 a 18:30)';
        $sig = date('Y-m-d', strtotime($fecha . ' 12:00:00 +1 day'));
        return 'Turno de noche · ' . self::fechaLarga($fecha) . ' 18:30 al ' . self::fechaLarga($sig) . ' 06:30';
    }

    /** uploads/accesos/ de la plataforma. Una ruta relativa en config.php (p. ej. 'uploads/') se toma
     *  desde la carpeta de la plataforma: así el registro (api/) y descargar.php (raíz) usan la misma. */
    public static function carpeta(): string {
        $base = rtrim((string)UPLOAD_DIR, '/\\');
        if ($base === '' || !preg_match('#^(/|[A-Za-z]:[/\\\\])#', $base)) $base = dirname(__DIR__) . '/' . ($base !== '' ? preg_replace('#^\./#', '', $base) : 'uploads');
        return $base . '/accesos/';
    }

    /** La carpeta no se sirve directo: sólo por descargar.php, con sesión. */
    private static function protegerCarpeta(): void {
        $base = self::carpeta();
        if (!is_dir($base)) @mkdir($base, 0755, true);
        $ht = $base . '.htaccess';
        if (!is_file($ht)) {
            @file_put_contents($ht,
                "# Fotos de identificaciones: datos personales. Sólo por descargar.php, con sesión.\n" .
                "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n" .
                "<IfModule !mod_authz_core.c>\n  Order Allow,Deny\n  Deny from all\n</IfModule>\n");
        }
        if (!is_file($base . 'index.html')) @file_put_contents($base . 'index.html', '');
    }

    /* ── Registrar ──────────────────────────────────────────────────────── */
    public function registrar(array $p, array $archivo, array $usr): array {
        self::asegurarTabla($this->pdo);
        $tipo = strtolower(trim((string)($p['tipo'] ?? '')));
        if (!in_array($tipo, ['entrada', 'salida'], true)) return ['status' => 'error', 'message' => 'Indica si es entrada o salida.'];
        $uid = (string)($p['uid'] ?? '');
        if (!preg_match('/^[A-Za-z0-9-]{8,64}$/', $uid)) return ['status' => 'error', 'message' => 'Registro sin identificador.'];

        // Reenvío de algo que ya llegó: se responde con lo que ya existe.
        $ya = $this->pdo->prepare("SELECT id FROM accesos_registros WHERE uid = ?");
        $ya->execute([$uid]);
        if ($idYa = $ya->fetchColumn()) return ['status' => 'success', 'id' => (int)$idYa, 'duplicado' => true];

        // Tarjeta leída en el teléfono: nombre y código, sin foto
        $codigo = strtoupper(substr((string)preg_replace('/[^0-9A-Za-z-]/', '', (string)($p['codigo'] ?? '')), 0, 32));
        $persona = $codigo !== '' ? mb_substr(textoEstandar($p['persona'] ?? ''), 0, 150) : '';
        $origenPedido = (string)($p['origen'] ?? '');
        $origen = $codigo === '' ? 'foto'
                : ($origenPedido === 'manual' ? 'manual'
                : ($origenPedido === 'admin' && self::esGestor($usr) ? 'admin' : 'tarjeta'));
        $conFoto = !empty($archivo['tmp_name']) && (int)($archivo['error'] ?? 1) === UPLOAD_ERR_OK;
        if (!$conFoto && $codigo === '') {
            return ['status' => 'error', 'message' => 'Falta la fotografía de la identificación o el código de la tarjeta.'];
        }
        // La cuenta de caseta registra en SU punto de control y desde SU equipo
        $eq = $this->equipoDeCaseta($p, $usr);
        if (!$eq['ok']) return $eq['error'];
        $tarjeta = self::conTarjeta($this->pdo);
        if ($codigo !== '' && !$tarjeta && !$conFoto) {
            return ['status' => 'error', 'message' => 'El servidor todavía no puede guardar tarjetas: avisa a AVBA.'];
        }
        if ($conFoto && ($mal = self::fotoInvalida($archivo))) return ['status' => 'error', 'message' => $mal];

        // La hora es la del dispositivo al tomar la foto o leer la tarjeta (puede llegar después, sin señal)
        $cap = (string)($p['capturado'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $cap) || strtotime($cap) === false) $cap = date('Y-m-d H:i:s');
        $fecha = substr($cap, 0, 10);

        $foto = ''; $mini = '';
        if ($conFoto) {
            $g = self::guardarFoto($archivo, $uid, $fecha);
            if (!$g) return ['status' => 'error', 'message' => 'No se pudo guardar la foto en el servidor.'];
            [$foto, $mini] = $g;
        }

        try {
            [$turnoFecha, $turno] = self::turnoDe($tipo, $cap);
            // La SALIDA de alguien que tiene su ENTRADA abierta va al turno de esa entrada:
            // entrar a las 17:57 (cuenta para la noche) y salir a las 17:58 no debe partirse en dos turnos.
            if ($tipo === 'salida' && $codigo !== '' && $tarjeta && ($ab = $this->turnoDeEntradaAbierta($codigo, $cap))) {
                [$turnoFecha, $turno] = $ab;
            }
            $valores = [
                $uid, $tipo, $fecha, $cap, date('Y-m-d H:i:s'),
                mb_substr($eq['punto'] ?: (textoEstandar($p['lugar'] ?? '') ?: 'SIN PUNTO DE CONTROL'), 0, 120),
                mb_substr(textoEstandar($p['nota'] ?? ''), 0, 255),
                $foto, $mini,
                mb_substr((string)($usr['usuario'] ?? ''), 0, 50), mb_substr((string)($usr['nombre'] ?? ''), 0, 150),
                $turnoFecha, $turno,
            ];
            if ($tarjeta) {
                $this->pdo->prepare(
                    "INSERT INTO accesos_registros (uid, tipo, fecha, capturado, recibido, lugar, nota, foto, foto_mini, usuario, usuario_nombre, turno_fecha, turno, nombre, codigo, origen)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                )->execute(array_merge($valores, [$persona, $codigo, $origen]));
            } else {
                $this->pdo->prepare(
                    "INSERT INTO accesos_registros (uid, tipo, fecha, capturado, recibido, lugar, nota, foto, foto_mini, usuario, usuario_nombre, turno_fecha, turno)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                )->execute($valores);
            }
        } catch (\PDOException $e) {
            // Dos envíos simultáneos del mismo registro: gana el primero
            $ya->execute([$uid]);
            if ($idYa = $ya->fetchColumn()) return ['status' => 'success', 'id' => (int)$idYa, 'duplicado' => true];
            throw $e;
        }
        return ['status' => 'success', 'id' => (int)$this->pdo->lastInsertId(), 'turno' => "$turnoFecha-$turno",
                'message' => ($tipo === 'entrada' ? 'Entrada' : 'Salida') . ' registrada a las ' . substr($cap, 11, 5) . '.'
                             . ($persona !== '' ? ' ' . $persona . '.' : '')];
    }

    /** Mensaje si el archivo subido no es una foto aceptable, o '' si sí lo es. */
    private static function fotoInvalida(array $archivo): string {
        if ((int)($archivo['size'] ?? 0) > 15 * 1048576) return 'La foto pesa demasiado (máximo 15 MB).';
        $info = @getimagesize($archivo['tmp_name']);
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) return 'El archivo no es una foto válida (JPG, PNG o WEBP).';
        return '';
    }

    /**
     * Guarda la foto (y su miniatura) en uploads/accesos/fotos/AAAA/MM/.
     * Regresa [ruta de la foto, ruta de la miniatura] dentro de la carpeta, o null.
     * Miles de fotos al día: 1400 px es de sobra para leer el número de una
     * credencial que llena la foto, y cada una queda en ~150 KB.
     */
    private static function guardarFoto(array $archivo, string $uid, string $fecha): ?array {
        self::protegerCarpeta();
        $sub = 'fotos/' . substr($fecha, 0, 4) . '/' . substr($fecha, 5, 2) . '/';
        $dir = self::carpeta() . $sub;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return null;
        $foto = comprimirImagen($archivo['tmp_name'], $dir . $uid . '.jpg', 1400, 1400, 76);
        $mini = comprimirImagen($archivo['tmp_name'], $dir . $uid . '_m.jpg', 300, 300, 68);
        if (!$foto) {
            // Sin GD: se guarda tal cual llegó
            $info = @getimagesize($archivo['tmp_name']);
            $ext = ($info[2] ?? 0) === IMAGETYPE_PNG ? 'png' : (($info[2] ?? 0) === IMAGETYPE_WEBP ? 'webp' : 'jpg');
            if (!@move_uploaded_file($archivo['tmp_name'], $dir . $uid . '.' . $ext)
                && !@copy($archivo['tmp_name'], $dir . $uid . '.' . $ext)) return null;
            $foto = $uid . '.' . $ext;
        }
        return [$sub . $foto, $mini ? $sub . $mini : ''];
    }

    /** Horas hacia atrás en que una ENTRADA sin salida todavía «arrastra» a su turno la salida de esa persona. */
    public const HORAS_JORNADA_MAX = 16;

    /**
     * El turno de la entrada abierta de un ID (su última lectura antes de esta salida, de las
     * últimas HORAS_JORNADA_MAX horas, es una ENTRADA), o null si no tiene una.
     * Se compara sin ceros a la izquierda: «00287» = «287».
     */
    private function turnoDeEntradaAbierta(string $codigo, string $cap): ?array {
        $clave = self::claveId($codigo);
        $desde = date('Y-m-d H:i:s', strtotime($cap) - self::HORAS_JORNADA_MAX * 3600);
        $st = $this->pdo->prepare(
            "SELECT tipo, codigo, turno_fecha, turno FROM accesos_registros
              WHERE codigo <> '' AND (codigo = ? OR TRIM(LEADING '0' FROM codigo) = ?) AND capturado <= ? AND capturado >= ?
              ORDER BY capturado DESC, id DESC LIMIT 10"
        );
        $st->execute([$codigo, $clave, $cap, $desde]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
            if (self::claveId((string)$f['codigo']) !== $clave) continue;
            return $f['tipo'] === 'entrada' && $f['turno_fecha'] ? [(string)$f['turno_fecha'], (string)$f['turno']] : null;
        }
        return null;
    }

    /* ── Control del día ────────────────────────────────────────────────── */
    private function registrosDelTurno(string $fecha, string $t, string $lugar = ''): array {
        self::asegurarTabla($this->pdo);
        $cols = self::conTarjeta($this->pdo) ? 'nombre, codigo, origen' : "'' AS nombre, '' AS codigo, 'foto' AS origen";
        $sql = "SELECT id, uid, tipo, capturado, lugar, nota, foto, foto_mini, usuario, usuario_nombre, $cols
                  FROM accesos_registros WHERE turno_fecha = ? AND turno = ?";
        $args = [$fecha, $t];
        if ($lugar !== '') { $sql .= " AND lugar = ?"; $args[] = $lugar; }
        $st = $this->pdo->prepare($sql . " ORDER BY capturado, id");
        $st->execute($args);
        $filas = $st->fetchAll(PDO::FETCH_ASSOC);
        // Numeración por tipo en orden de hora: la misma del PDF
        $num = ['entrada' => 0, 'salida' => 0];
        foreach ($filas as &$f) { $f['num'] = ++$num[$f['tipo']]; }
        return $filas;
    }

    /** Un registro propio se puede borrar durante 18 horas (sin depender de zonas horarias) */
    private const HORAS_PARA_BORRAR = 18;
    private static function reciente(string $recibido): bool {
        $t = strtotime($recibido);
        return $t !== false && $t >= time() - self::HORAS_PARA_BORRAR * 3600;
    }

    /**
     * El control por turno: totales y los registros más recientes (de N en N).
     * La pantalla pide los turnos que muestra: en curso son uno o dos (en el
     * cambio de turno, el que entra y el que sale); en el historial, uno.
     * El número de cada registro (ENTRADA 015) es su lugar entre los de su
     * tipo en su turno, igual que en el PDF.
     */
    public function listar(array $p, array $usr): array {
        self::asegurarTabla($this->pdo);
        $caseta = self::esCaseta($usr);
        $lugar = $caseta ? '' : textoEstandar($p['lugar'] ?? '');
        $limite = max(10, min(200, (int)($p['limite'] ?? 60)));
        $eq = $this->equipoDeCaseta($p, $usr);
        if (!$eq['ok']) return $eq['error'];

        $turnos = [];
        foreach (array_slice(explode(',', (string)($p['turnos'] ?? '')), 0, 2) as $c) {
            if (($t = self::claveTurno(trim($c))) && !in_array($t, $turnos)) $turnos[] = $t;
        }
        if (!$turnos) {                                   // sin turnos: los que están en curso según el servidor
            $ahora = date('Y-m-d H:i:s');
            foreach (['entrada', 'salida'] as $tp) { $t = self::turnoDe($tp, $ahora); if (!in_array($t, $turnos)) $turnos[] = $t; }
        }
        if ($caseta) {
            // La caseta sólo ve los turnos de ahora (con un día de margen por la zona horaria)
            $hoy = strtotime(date('Y-m-d') . ' 12:00:00');
            $turnos = array_values(array_filter($turnos, fn($t) => abs(strtotime($t[0] . ' 12:00:00') - $hoy) <= 2 * 86400));
            if (!$turnos) return ['status' => 'success', 'registros' => [], 'totales' => (object)[], 'lugares' => [], 'mas' => false, 'incidencias' => []];
        }

        $condT = implode(' OR ', array_fill(0, count($turnos), '(turno_fecha = ? AND turno = ?)'));
        $args = array_merge(...array_map(fn($t) => [$t[0], $t[1]], $turnos));
        $donde = "($condT)";
        if ($lugar !== '') { $donde .= " AND lugar = ?"; $args[] = $lugar; }
        if ($caseta) { $donde .= " AND usuario = ?"; $args[] = (string)$usr['usuario']; }

        $tot = [];
        foreach ($turnos as $t) $tot[$t[0] . '-' . $t[1]] = ['entrada' => 0, 'salida' => 0];
        $st = $this->pdo->prepare("SELECT turno_fecha, turno, tipo, COUNT(*) AS n FROM accesos_registros WHERE $donde GROUP BY turno_fecha, turno, tipo");
        $st->execute($args);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $kt = $r['turno_fecha'] . '-' . $r['turno'];
            if (isset($tot[$kt][$r['tipo']])) $tot[$kt][$r['tipo']] = (int)$r['n'];
        }

        $lugares = [];
        if (!$caseta) {
            $st = $this->pdo->prepare("SELECT DISTINCT lugar FROM accesos_registros WHERE ($condT) ORDER BY lugar");
            $st->execute(array_merge(...array_map(fn($t) => [$t[0], $t[1]], $turnos)));
            $lugares = $st->fetchAll(PDO::FETCH_COLUMN);
        }

        // Página: lo anterior al último que ya tiene la pantalla
        $antesCap = (string)($p['antes_cap'] ?? '');
        $antesId = (int)($p['antes_id'] ?? 0);
        $pag = '';
        $argsPag = $args;
        if ($antesId > 0 && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $antesCap)) {
            $pag = " AND (a.capturado < ? OR (a.capturado = ? AND a.id < ?))";
            array_push($argsPag, $antesCap, $antesCap, $antesId);
        }
        $num = "(SELECT COUNT(*) FROM accesos_registros b WHERE b.turno_fecha = a.turno_fecha AND b.turno = a.turno AND b.tipo = a.tipo"
             . ($lugar !== '' ? " AND b.lugar = a.lugar" : '')
             . ($caseta ? " AND b.usuario = a.usuario" : '')
             . " AND (b.capturado < a.capturado OR (b.capturado = a.capturado AND b.id <= a.id)))";
        $dondeA = preg_replace('/\b(turno_fecha|turno|lugar|usuario)\b/', 'a.$1', $donde);
        $st = $this->pdo->prepare(
            "SELECT a.id, a.uid, a.tipo, a.capturado, a.recibido, a.lugar, a.nota, a.usuario, a.usuario_nombre,
                    a.turno_fecha, a.turno, " . (self::conTarjeta($this->pdo) ? "a.nombre AS persona, a.codigo, a.origen" : "'' AS persona, '' AS codigo, 'foto' AS origen") . ",
                    (a.foto <> '') AS con_foto, $num AS num
               FROM accesos_registros a
              WHERE $dondeA$pag
              ORDER BY a.capturado DESC, a.id DESC LIMIT " . ($limite + 1)
        );
        $st->execute($argsPag);
        $filas = $st->fetchAll(PDO::FETCH_ASSOC);
        $hayMas = count($filas) > $limite;
        $filas = array_slice($filas, 0, $limite);

        $esAdmin = ($usr['rol'] ?? '') === 'ADMIN';
        $out = [];
        foreach ($filas as $f) {
            $out[] = [
                'id' => (int)$f['id'], 'uid' => $f['uid'], 'tipo' => $f['tipo'], 'num' => (int)$f['num'],
                'turno' => $f['turno_fecha'] . '-' . $f['turno'],
                'capturado' => $f['capturado'], 'hora' => substr((string)$f['capturado'], 11, 8),
                'lugar' => $f['lugar'], 'nota' => $f['nota'],
                'usuario' => $f['usuario'], 'nombre' => $f['usuario_nombre'] ?: $f['usuario'],
                'persona' => (string)$f['persona'], 'codigo' => (string)$f['codigo'], 'origen' => (string)$f['origen'],
                'con_foto' => (bool)$f['con_foto'],
                'puede_borrar' => $esAdmin || ($f['usuario'] === ($usr['usuario'] ?? null) && self::reciente((string)$f['recibido'])),
            ];
        }
        return ['status' => 'success', 'turnos' => array_keys($tot), 'registros' => $out, 'totales' => $tot,
                'lugares' => $lugares, 'mas' => $hayMas, 'punto' => $eq['punto'],
                'incidencias' => $this->incidenciasDeTurnos($turnos, $lugar, $usr)];
    }

    /** ¿Ya llegaron al servidor? (la pantalla pregunta por lo que tiene en cola) */
    public function existen(array $uids): array {
        self::asegurarTabla($this->pdo);
        $uids = array_values(array_filter(array_slice($uids, 0, 500), fn($u) => is_string($u) && preg_match('/^[A-Za-z0-9-]{8,64}$/', $u)));
        if (!$uids) return ['status' => 'success', 'existen' => []];
        $marcas = implode(',', array_fill(0, count($uids), '?'));
        $st = $this->pdo->prepare("SELECT uid, id FROM accesos_registros WHERE uid IN ($marcas)");
        $st->execute($uids);
        $r = $st->fetchAll(PDO::FETCH_KEY_PAIR);
        // Las incidencias van por la misma cola del teléfono
        self::asegurarIncidencias($this->pdo);
        $st = $this->pdo->prepare("SELECT uid, id FROM accesos_incidencias WHERE uid IN ($marcas)");
        $st->execute($uids);
        $r += $st->fetchAll(PDO::FETCH_KEY_PAIR);
        return ['status' => 'success', 'existen' => $r ?: (object)[]];
    }

    /** Puntos de control usados en los últimos 60 días (para elegir rápido) */
    public function lugares(): array {
        self::asegurarTabla($this->pdo);
        $st = $this->pdo->prepare(
            "SELECT lugar FROM accesos_registros WHERE fecha >= ? GROUP BY lugar ORDER BY MAX(capturado) DESC LIMIT 20"
        );
        $st->execute([date('Y-m-d', strtotime('-60 days'))]);
        return ['status' => 'success', 'lugares' => array_values(array_unique(array_merge(self::PUNTOS, $st->fetchAll(PDO::FETCH_COLUMN))))];
    }

    public function eliminar(int $id, array $usr): array {
        self::asegurarTabla($this->pdo);
        $st = $this->pdo->prepare("SELECT * FROM accesos_registros WHERE id = ?");
        $st->execute([$id]);
        $f = $st->fetch(PDO::FETCH_ASSOC);
        if (!$f) return ['status' => 'success', 'message' => 'El registro ya no existía.'];
        $esAdmin = ($usr['rol'] ?? '') === 'ADMIN';
        if (!$esAdmin && !($f['usuario'] === ($usr['usuario'] ?? null) && self::reciente((string)$f['recibido']))) {
            return ['status' => 'error', 'message' => 'Sólo quien lo registró (en las primeras ' . self::HORAS_PARA_BORRAR . ' horas) o Administración puede eliminarlo.'];
        }
        foreach (['foto', 'foto_mini'] as $c) {
            if ($f[$c] !== '' && strpos($f[$c], '..') === false) @unlink(self::carpeta() . $f[$c]);
        }
        $this->pdo->prepare("DELETE FROM accesos_registros WHERE id = ?")->execute([$id]);
        return ['status' => 'success', 'message' => 'Registro eliminado.'];
    }

    /** Ruta en disco de la foto de un registro (para descargar.php) */
    public function rutaFoto(int $id, bool $mini, ?array $usr = null): ?string {
        self::asegurarTabla($this->pdo);
        $st = $this->pdo->prepare("SELECT foto, foto_mini, usuario FROM accesos_registros WHERE id = ?");
        $st->execute([$id]);
        $f = $st->fetch(PDO::FETCH_ASSOC);
        if (!$f) return null;
        if (self::esCaseta($usr) && $f['usuario'] !== ($usr['usuario'] ?? null)) return null;   // sólo las suyas
        $rel = ($mini && $f['foto_mini'] !== '') ? $f['foto_mini'] : $f['foto'];
        if ($rel === '' || strpos($rel, '..') !== false) return null;
        $abs = self::carpeta() . $rel;
        return is_file($abs) ? $abs : null;
    }

    /* ── Incidencias ─────────────────────────────────────────────────────────
       El botón INCIDENCIA de la caseta: el guardia lee la credencial (como en
       una entrada) y reporta qué pasó con esa persona. Van en su propia tabla
       para no mover los contadores, «En planta» ni los PDF de entradas y
       salidas. Llegan por la misma cola del teléfono (también sin señal) y con
       la foto de la credencial como evidencia. */
    public const INCIDENCIAS = [
        'ALCOHOLIMETRO' => 'Alcoholímetro positivo',
        'CONDUCTA'      => 'Mala conducta',
        'PERSONA'       => 'Problema con la persona',
        'OTRO'          => 'Otro',
    ];

    public static function asegurarIncidencias(PDO $pdo): void {
        static $hecho = [];
        $k = spl_object_id($pdo);
        if (isset($hecho[$k])) return;
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS accesos_incidencias (
               id             INT AUTO_INCREMENT PRIMARY KEY,
               uid            VARCHAR(64)  NOT NULL,
               tipo           VARCHAR(20)  NOT NULL,
               detalle        VARCHAR(500) NOT NULL DEFAULT '',
               fecha          DATE         NOT NULL,
               capturado      DATETIME     NOT NULL,
               recibido       DATETIME     NOT NULL,
               turno_fecha    DATE         NOT NULL,
               turno          CHAR(1)      NOT NULL,
               lugar          VARCHAR(120) NOT NULL DEFAULT '',
               codigo         VARCHAR(32)  NOT NULL DEFAULT '',
               nombre         VARCHAR(150) NOT NULL DEFAULT '',
               origen         VARCHAR(12)  NOT NULL DEFAULT 'tarjeta',
               foto           VARCHAR(255) NOT NULL DEFAULT '',
               foto_mini      VARCHAR(255) NOT NULL DEFAULT '',
               usuario        VARCHAR(50)  NOT NULL DEFAULT '',
               usuario_nombre VARCHAR(150) NOT NULL DEFAULT '',
               UNIQUE KEY uk_uid (uid),
               KEY idx_turno (turno_fecha, turno),
               KEY idx_codigo (codigo),
               KEY idx_fecha (fecha)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $hecho[$k] = true;
    }

    /** El turno de una incidencia es el del reloj (día 06:30–18:30, noche 18:30–06:30), sin margen. */
    public static function turnoReloj(string $capturado): array {
        $d = substr($capturado, 0, 10);
        $m = (int)substr($capturado, 11, 2) * 60 + (int)substr($capturado, 14, 2);
        if ($m >= self::CAMBIO_DIA && $m < self::CAMBIO_NOCHE) return [$d, 'D'];
        if ($m >= self::CAMBIO_NOCHE) return [$d, 'N'];
        return [date('Y-m-d', strtotime($d . ' 12:00:00 -1 day')), 'N'];
    }

    public function registrarIncidencia(array $p, array $archivo, array $usr): array {
        self::asegurarIncidencias($this->pdo);
        $uid = (string)($p['uid'] ?? '');
        if (!preg_match('/^[A-Za-z0-9-]{8,64}$/', $uid)) return ['status' => 'error', 'message' => 'Incidencia sin identificador.'];
        $ya = $this->pdo->prepare("SELECT id FROM accesos_incidencias WHERE uid = ?");
        $ya->execute([$uid]);
        if ($idYa = $ya->fetchColumn()) return ['status' => 'success', 'id' => (int)$idYa, 'duplicado' => true];

        $tipo = strtoupper(trim((string)($p['incidencia'] ?? '')));
        if (!isset(self::INCIDENCIAS[$tipo])) return ['status' => 'error', 'message' => 'Elige qué tipo de incidencia es.'];
        $detalle = mb_substr(trim((string)preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', ' ', (string)($p['detalle'] ?? ''))), 0, 500);
        if ($tipo === 'OTRO' && $detalle === '') return ['status' => 'error', 'message' => 'Describe qué pasó.'];
        $codigo = strtoupper(substr((string)preg_replace('/[^0-9A-Za-z-]/', '', (string)($p['codigo'] ?? '')), 0, 32));
        $persona = mb_substr(textoEstandar($p['persona'] ?? ''), 0, 150);
        $conFoto = !empty($archivo['tmp_name']) && (int)($archivo['error'] ?? 1) === UPLOAD_ERR_OK;
        if ($codigo === '' && !$conFoto && $persona === '') {
            return ['status' => 'error', 'message' => 'Falta saber quién es: lee su credencial, escribe su ID o su nombre.'];
        }
        $eq = $this->equipoDeCaseta($p, $usr);
        if (!$eq['ok']) return $eq['error'];
        if ($conFoto && ($mal = self::fotoInvalida($archivo))) return ['status' => 'error', 'message' => $mal];

        $cap = (string)($p['capturado'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $cap) || strtotime($cap) === false) $cap = date('Y-m-d H:i:s');
        $fecha = substr($cap, 0, 10);
        $foto = ''; $mini = '';
        if ($conFoto) {
            $g = self::guardarFoto($archivo, 'inc-' . $uid, $fecha);
            if (!$g) return ['status' => 'error', 'message' => 'No se pudo guardar la foto en el servidor.'];
            [$foto, $mini] = $g;
        }
        $origenPedido = (string)($p['origen'] ?? '');
        $origen = $codigo === '' ? 'sin_id' : ($origenPedido === 'manual' ? 'manual' : 'tarjeta');
        [$tf, $t] = self::turnoReloj($cap);
        try {
            $this->pdo->prepare(
                "INSERT INTO accesos_incidencias (uid, tipo, detalle, fecha, capturado, recibido, turno_fecha, turno, lugar, codigo, nombre, origen, foto, foto_mini, usuario, usuario_nombre)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute([$uid, $tipo, $detalle, $fecha, $cap, date('Y-m-d H:i:s'), $tf, $t,
                        mb_substr($eq['punto'] ?: (textoEstandar($p['lugar'] ?? '') ?: 'SIN PUNTO DE CONTROL'), 0, 120),
                        $codigo, $persona, $origen, $foto, $mini,
                        mb_substr((string)($usr['usuario'] ?? ''), 0, 50), mb_substr((string)($usr['nombre'] ?? ''), 0, 150)]);
        } catch (\PDOException $e) {
            $ya->execute([$uid]);
            if ($idYa = $ya->fetchColumn()) return ['status' => 'success', 'id' => (int)$idYa, 'duplicado' => true];
            throw $e;
        }
        return ['status' => 'success', 'id' => (int)$this->pdo->lastInsertId(),
                'message' => 'Incidencia registrada: ' . self::INCIDENCIAS[$tipo] . ($persona !== '' ? ' · ' . $persona : ($codigo !== '' ? ' · ID ' . $codigo : '')) . '.'];
    }

    /** Una incidencia como la ve la pantalla */
    private function filaIncidencia(array $f, ?array $usr): array {
        $esAdmin = ($usr['rol'] ?? '') === 'ADMIN';
        return [
            'id' => (int)$f['id'], 'uid' => $f['uid'], 'tipo' => $f['tipo'], 'tipo_nombre' => self::INCIDENCIAS[$f['tipo']] ?? $f['tipo'],
            'detalle' => (string)$f['detalle'], 'capturado' => $f['capturado'], 'hora' => substr((string)$f['capturado'], 11, 8),
            'turno' => $f['turno_fecha'] . '-' . $f['turno'], 'lugar' => $f['lugar'], 'codigo' => (string)$f['codigo'],
            'persona' => (string)$f['nombre'], 'origen' => (string)$f['origen'], 'con_foto' => (string)$f['foto'] !== '',
            'usuario' => $f['usuario'], 'nombre' => $f['usuario_nombre'] ?: $f['usuario'],
            'puede_borrar' => $esAdmin || ($f['usuario'] === ($usr['usuario'] ?? null) && self::reciente((string)$f['recibido'])),
        ];
    }

    /** Incidencias de los turnos que muestra la pantalla (la caseta, sólo las suyas) */
    private function incidenciasDeTurnos(array $turnos, string $lugar, array $usr): array {
        self::asegurarIncidencias($this->pdo);
        $condT = implode(' OR ', array_fill(0, count($turnos), '(turno_fecha = ? AND turno = ?)'));
        $args = array_merge(...array_map(fn($t) => [$t[0], $t[1]], $turnos));
        $sql = "SELECT * FROM accesos_incidencias WHERE ($condT)";
        if ($lugar !== '') { $sql .= " AND lugar = ?"; $args[] = $lugar; }
        if (self::esCaseta($usr)) { $sql .= " AND usuario = ?"; $args[] = (string)$usr['usuario']; }
        $st = $this->pdo->prepare($sql . " ORDER BY capturado DESC, id DESC LIMIT 200");
        $st->execute($args);
        return array_map(fn($f) => $this->filaIncidencia($f, $usr), $st->fetchAll(PDO::FETCH_ASSOC));
    }

    public function eliminarIncidencia(int $id, array $usr): array {
        self::asegurarIncidencias($this->pdo);
        $st = $this->pdo->prepare("SELECT * FROM accesos_incidencias WHERE id = ?");
        $st->execute([$id]);
        $f = $st->fetch(PDO::FETCH_ASSOC);
        if (!$f) return ['status' => 'success', 'message' => 'La incidencia ya no existía.'];
        if (!$this->filaIncidencia($f, $usr)['puede_borrar']) {
            return ['status' => 'error', 'message' => 'Sólo quien la registró (en las primeras ' . self::HORAS_PARA_BORRAR . ' horas) o Administración puede eliminarla.'];
        }
        foreach (['foto', 'foto_mini'] as $c) {
            if ($f[$c] !== '' && strpos($f[$c], '..') === false) @unlink(self::carpeta() . $f[$c]);
        }
        $this->pdo->prepare("DELETE FROM accesos_incidencias WHERE id = ?")->execute([$id]);
        return ['status' => 'success', 'message' => 'Incidencia eliminada.'];
    }

    /** Ruta en disco de la foto de una incidencia (para descargar.php) */
    public function rutaFotoIncidencia(int $id, bool $mini, ?array $usr = null): ?string {
        self::asegurarIncidencias($this->pdo);
        $st = $this->pdo->prepare("SELECT foto, foto_mini, usuario FROM accesos_incidencias WHERE id = ?");
        $st->execute([$id]);
        $f = $st->fetch(PDO::FETCH_ASSOC);
        if (!$f) return null;
        if (self::esCaseta($usr) && $f['usuario'] !== ($usr['usuario'] ?? null)) return null;   // sólo las suyas
        $rel = ($mini && $f['foto_mini'] !== '') ? $f['foto_mini'] : $f['foto'];
        if ($rel === '' || strpos($rel, '..') !== false) return null;
        $abs = self::carpeta() . $rel;
        return is_file($abs) ? $abs : null;
    }

    /* ── PDF del turno ────────────────────────────────────────────────────── */
    public static function rutaPdf(string $archivo): ?string {
        if (!preg_match('/^ACCESOS_\d{4}-\d{2}-\d{2}(-[DN])?_[A-Z0-9-]+_(ambos|entrada|salida|datos)(_p\d+)?_\d+\.pdf$/', $archivo)) return null;
        $abs = self::carpeta() . 'pdf/' . $archivo;
        return is_file($abs) ? $abs : null;
    }

    private static function fechaLarga(string $f): string {
        $t = strtotime($f);
        return self::DIAS[(int)date('w', $t)] . ' ' . (int)date('j', $t) . ' de ' . self::MESES[(int)date('n', $t)] . ' de ' . date('Y', $t);
    }
    private static function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

    /**
     * Fotos por PDF. Con 150 (unas 38 hojas y 12 a 15 MB) cada parte queda
     * dentro de lo que Claude analiza de un archivo (100 hojas, 30 MB) y el
     * servidor la arma sin pasar su tiempo límite. Un día de planta con miles
     * de registros sale en varias partes.
     */
    public const FOTOS_POR_PDF = 150;

    public function generarPdf(array $p, array $usr): array {
        if (self::esCaseta($usr)) return ['status' => 'error', 'message' => 'Esta cuenta sólo registra entradas y salidas.'];
        $tk = self::claveTurno($p['turno'] ?? '');
        if (!$tk) return ['status' => 'error', 'message' => 'Elige el turno.'];
        [$fecha, $t] = $tk;
        $lugar = textoEstandar($p['lugar'] ?? '');
        $incluir = in_array($p['incluir'] ?? '', ['entrada', 'salida'], true) ? $p['incluir'] : 'ambos';
        // Las tarjetas leídas en el teléfono no traen foto: van en el PDF de datos
        $filas = array_values(array_filter($this->registrosDelTurno($fecha, $t, $lugar), fn($f) => $f['foto'] !== ''));
        $totEnt = count(array_filter($filas, fn($f) => $f['tipo'] === 'entrada'));
        $totSal = count($filas) - $totEnt;
        if ($incluir !== 'ambos') $filas = array_values(array_filter($filas, fn($f) => $f['tipo'] === $incluir));
        if (!$filas) return ['status' => 'error', 'message' => 'No hay fotos de ese turno para incluir en el PDF. Los registros con tarjeta están en el PDF de datos.'];

        // Primero todas las entradas y luego las salidas, en partes de FOTOS_POR_PDF
        usort($filas, fn($a, $b) => [$a['tipo'] === 'salida', $a['capturado'], $a['id']] <=> [$b['tipo'] === 'salida', $b['capturado'], $b['id']]);
        $partes = (int)ceil(count($filas) / self::FOTOS_POR_PDF);
        $parte = max(1, min($partes, (int)($p['parte'] ?? 1)));
        $trozo = array_slice($filas, ($parte - 1) * self::FOTOS_POR_PDF, self::FOTOS_POR_PDF);

        if (!class_exists('Mpdf\Mpdf')) {
            $autoload = __DIR__ . '/../vendor/autoload.php';
            if (!is_file($autoload)) return ['status' => 'error', 'message' => 'No está instalado el generador de PDF.'];
            require_once $autoload;
        }
        if (function_exists('set_time_limit')) @set_time_limit(240);
        self::protegerCarpeta();
        $tmp = self::carpeta() . 'tmp/' . bin2hex(random_bytes(6)) . '/';
        @mkdir($tmp, 0755, true);
        @mkdir(self::carpeta() . 'pdf', 0755, true);

        try {
            $ent = array_values(array_filter($trozo, fn($f) => $f['tipo'] === 'entrada'));
            $sal = array_values(array_filter($trozo, fn($f) => $f['tipo'] === 'salida'));
            $info = ['parte' => $parte, 'partes' => $partes, 'tot_ent' => $totEnt, 'tot_sal' => $totSal, 'turno' => $t];
            $html = $this->htmlPdf($fecha, $lugar, $incluir, $ent, $sal, $usr, $tmp, $info);

            $mpdf = new \Mpdf\Mpdf([
                'mode' => 'utf-8', 'format' => 'A4', 'orientation' => 'P',
                'margin_top' => 10, 'margin_bottom' => 14, 'margin_left' => 10, 'margin_right' => 10,
                'margin_header' => 0, 'margin_footer' => 6,
                // Una pareja de fotos que no cabe pasa completa a la hoja
                // siguiente, en vez de encogerse.
                'shrink_tables_to_fit' => 0,
            ]);
            $nomT = ($t === 'D' ? 'turno de día' : 'turno de noche') . ' ' . date('d/m/Y', strtotime($fecha));
            $mpdf->SetTitle('Control de accesos ' . $nomT . ($lugar !== '' ? ' - ' . $lugar : '') . ($partes > 1 ? " - parte $parte de $partes" : ''));
            $mpdf->SetAuthor('AVBA Control de Accesos');
            $mpdf->SetFooter('Control de accesos · ' . $nomT . ($partes > 1 ? " · parte $parte de $partes" : '') . '||Página {PAGENO} de {nbpg}');
            $mpdf->WriteHTML($html);

            $slug = $lugar === '' ? 'TODOS' : trim(preg_replace('/[^A-Z0-9]+/', '-', $lugar), '-');
            $slug = substr($slug !== '' ? $slug : 'PUNTO', 0, 40);
            $archivo = "ACCESOS_{$fecha}-{$t}_{$slug}_{$incluir}_p{$parte}_" . time() . '.pdf';
            $mpdf->Output(self::carpeta() . 'pdf/' . $archivo, 'F');
            $paginas = (int)$mpdf->page;
        } finally {
            foreach (glob($tmp . '*') ?: [] as $f) @unlink($f);
            @rmdir($tmp);
        }
        $bytes = (int)@filesize(self::carpeta() . 'pdf/' . $archivo);
        return ['status' => 'success', 'archivo' => $archivo, 'url' => 'descargar.php?tipo=accesos_pdf&f=' . rawurlencode($archivo),
                'paginas' => $paginas, 'bytes' => $bytes, 'entradas' => count($ent), 'salidas' => count($sal),
                'parte' => $parte, 'partes' => $partes, 'total' => count($filas)];
    }

    /* ── PDF de datos: nombre, código, hora de entrada y de salida ─────────
       Junta cada ENTRADA con la siguiente SALIDA del mismo código, en orden de
       hora. Una entrada sin salida (o una salida sin entrada) queda en su
       propio renglón, marcada. Los registros con foto y sin código no se
       pueden juntar: sólo se cuentan (están en el PDF con fotos). */
    /* ── El ID de la credencial: la llave de cada persona ─────────────────────
       El número del recuadro azul es único para cada persona. Se compara sin los
       ceros de la izquierda: «00287» (leído de la credencial) y «287» (escrito a
       mano) son la misma persona. */
    public static function claveId(string $c): string {
        $c = strtoupper(trim($c));
        if ($c !== '' && ctype_digit($c)) { $c = ltrim($c, '0'); return $c === '' ? '0' : $c; }
        return $c;
    }

    /** El nombre más leído. Una lectura parcial («JUAN CARLOS», sin el segundo renglón) cuenta a favor del
     *  nombre completo que empieza igual («JUAN CARLOS PEREZ LOPEZ»); en empate gana el más completo y luego
     *  el primero leído. */
    public static function nombreMasLeido(array $nombres): string {
        $cuenta = []; $primero = [];
        foreach (array_values($nombres) as $i => $n) {
            $n = trim((string)$n);
            if ($n === '') continue;
            $cuenta[$n] = ($cuenta[$n] ?? 0) + 1;
            if (!isset($primero[$n])) $primero[$n] = $i;
        }
        $mejor = ''; $veces = 0; $orden = PHP_INT_MAX;
        foreach ($cuenta as $n => $c) {
            $n = (string)$n;
            foreach ($cuenta as $m => $cm) if (str_starts_with($n, (string)$m . ' ')) $c += $cm;
            if ($c > $veces || ($c === $veces && (strlen($n) > strlen($mejor) || (strlen($n) === strlen($mejor) && $primero[$n] < $orden)))) {
                $mejor = $n; $veces = $c; $orden = $primero[$n];
            }
        }
        return $mejor;
    }

    /** Cómo se muestra el ID: la forma más leída (con sus ceros, «00287»); en empate, la más larga. */
    private static function idMostrado(array $codigos): string {
        $cuenta = [];
        foreach ($codigos as $c) { $c = (string)$c; if ($c !== '') $cuenta[$c] = ($cuenta[$c] ?? 0) + 1; }
        $mejor = ''; $v = 0;
        foreach ($cuenta as $c => $n) { $c = (string)$c; if ($n > $v || ($n === $v && strlen($c) > strlen($mejor))) { $mejor = $c; $v = $n; } }
        return $mejor;
    }

    /** Junta la entrada y la salida de cada persona (por su ID), en orden. */
    public static function emparejar(array $filas): array {
        $porId = [];
        foreach ($filas as $f) {
            if (($f['codigo'] ?? '') === '') continue;
            $porId[self::claveId((string)$f['codigo'])][] = $f;
        }
        $out = [];
        foreach ($porId as $regs) {
            usort($regs, fn($a, $b) => [$a['capturado'], (int)$a['id']] <=> [$b['capturado'], (int)$b['id']]);
            $nombre = self::nombreMasLeido(array_column($regs, 'nombre'));
            $id = self::idMostrado(array_column($regs, 'codigo'));
            $conFoto = fn(?array $r): bool => $r !== null && (!empty($r['con_foto']) || (string)($r['foto'] ?? '') !== '');
            $renglon = function (?array $e, ?array $s) use ($nombre, $id, $conFoto): array {
                $min = ($e && $s) ? (int)round((strtotime($s['capturado']) - strtotime($e['capturado'])) / 60) : null;
                return ['codigo' => $id, 'nombre' => $nombre,
                        'entrada' => $e ? (string)$e['capturado'] : '', 'salida' => $s ? (string)$s['capturado'] : '',
                        'minutos' => $min, 'lugar' => (string)($e['lugar'] ?? $s['lugar'] ?? ''),
                        'lugar_salida' => (string)($s['lugar'] ?? ''),
                        'origen_salida' => (string)($s['origen'] ?? ''),
                        // Foto de la credencial (evidencia) de cada lado
                        'id_entrada' => $e ? (int)$e['id'] : null, 'foto_entrada' => $conFoto($e),
                        'id_salida' => $s ? (int)$s['id'] : null, 'foto_salida' => $conFoto($s)];
            };
            $abierta = null;
            foreach ($regs as $r) {
                if ($r['tipo'] === 'entrada') {
                    if ($abierta) $out[] = $renglon($abierta, null);          // entró otra vez sin salida
                    $abierta = $r;
                } else {
                    $out[] = $renglon($abierta, $r);                          // con o sin entrada
                    $abierta = null;
                }
            }
            if ($abierta) $out[] = $renglon($abierta, null);
        }
        usort($out, fn($a, $b) => [($a['entrada'] ?: $a['salida']), $a['codigo']] <=> [($b['entrada'] ?: $b['salida']), $b['codigo']]);
        return $out;
    }

    /* ── Administración: quién está en planta y los movimientos de cada ID ──── */

    /** Horas hacia atrás que se revisan para saber quién sigue dentro. */
    public const HORAS_EN_PLANTA = 48;
    /** Más de esto dentro: probablemente salió sin pasar su credencial. */
    public const MINUTOS_REVISAR = 13 * 60;

    /**
     * Quiénes están en planta: cada ID cuya última lectura (de las últimas 48 h, en cualquier
     * punto) es una ENTRADA. Si ya registró su salida, aunque sea por otro punto, no aparece.
     * Con «lugar», sólo los que entraron por ese punto.
     */
    public function enPlanta(array $p, array $usr): array {
        if (self::esCaseta($usr)) return ['status' => 'error', 'message' => 'Esta cuenta sólo registra entradas y salidas.'];
        self::asegurarTabla($this->pdo);
        $ahora = time();
        $base = ['status' => 'success', 'personas' => [], 'total' => 0, 'horas' => self::HORAS_EN_PLANTA,
                 'ahora' => date('Y-m-d H:i:s', $ahora)];
        if (!self::conTarjeta($this->pdo)) return $base;
        $desde = date('Y-m-d H:i:s', $ahora - self::HORAS_EN_PLANTA * 3600);
        $st = $this->pdo->prepare(
            "SELECT id, tipo, capturado, lugar, nombre, codigo, origen, usuario_nombre, (foto <> '') AS con_foto
               FROM accesos_registros
              WHERE codigo <> '' AND fecha >= ? AND capturado >= ?
              ORDER BY capturado, id"
        );
        $st->execute([substr($desde, 0, 10), $desde]);
        $porId = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) $porId[self::claveId((string)$f['codigo'])][] = $f;
        $lugar = textoEstandar($p['lugar'] ?? '');
        $out = [];
        foreach ($porId as $regs) {
            $ult = $regs[count($regs) - 1];
            if ($ult['tipo'] !== 'entrada') continue;                     // ya salió
            if ($lugar !== '' && $ult['lugar'] !== $lugar) continue;       // entró por otro punto
            $min = max(0, (int)floor(($ahora - strtotime((string)$ult['capturado'])) / 60));
            $out[] = [
                'id' => self::idMostrado(array_column($regs, 'codigo')),
                'nombre' => self::nombreMasLeido(array_column($regs, 'nombre')),
                'entrada' => (string)$ult['capturado'], 'lugar' => (string)$ult['lugar'],
                'minutos' => $min, 'revisar' => $min > self::MINUTOS_REVISAR,
                'caseta' => (string)$ult['usuario_nombre'], 'origen' => (string)$ult['origen'],
                'registro' => (int)$ult['id'], 'con_foto' => (bool)$ult['con_foto'],
            ];
        }
        usort($out, fn($a, $b) => strcmp($b['entrada'], $a['entrada']));    // las entradas más recientes primero
        return ['personas' => $out, 'total' => count($out)] + $base;
    }

    /** Los movimientos de un ID (por omisión, de los últimos 30 días): cada entrada con su salida. */
    public function persona(array $p, array $usr): array {
        if (self::esCaseta($usr)) return ['status' => 'error', 'message' => 'Esta cuenta sólo registra entradas y salidas.'];
        self::asegurarTabla($this->pdo);
        $raw = strtoupper(substr((string)preg_replace('/[^0-9A-Za-z-]/', '', (string)($p['id'] ?? '')), 0, 32));
        if ($raw === '') return ['status' => 'error', 'message' => 'Escribe el número del ID.'];
        $dias = max(1, min(180, (int)($p['dias'] ?? 30)));
        $clave = self::claveId($raw);
        // Sus incidencias (mismo ID, con o sin ceros a la izquierda)
        self::asegurarIncidencias($this->pdo);
        $st = $this->pdo->prepare(
            "SELECT * FROM accesos_incidencias WHERE fecha >= ? AND codigo <> '' AND (codigo = ? OR TRIM(LEADING '0' FROM codigo) = ?)
              ORDER BY capturado DESC, id DESC LIMIT 100");
        $st->execute([date('Y-m-d', time() - $dias * 86400), $raw, $clave]);
        $incidencias = array_values(array_map(fn($f) => $this->filaIncidencia($f, $usr),
            array_filter($st->fetchAll(PDO::FETCH_ASSOC), fn($f) => self::claveId((string)$f['codigo']) === $clave)));
        $base = ['status' => 'success', 'id' => $raw, 'nombre' => '', 'movimientos' => [], 'en_planta' => false,
                 'dias' => $dias, 'registros' => 0, 'incidencias' => $incidencias];
        if (!self::conTarjeta($this->pdo)) return $base;
        $st = $this->pdo->prepare(
            "SELECT id, uid, tipo, capturado, lugar, nombre, codigo, origen, usuario_nombre, (foto <> '') AS con_foto
               FROM accesos_registros
              WHERE fecha >= ? AND codigo <> '' AND (codigo = ? OR TRIM(LEADING '0' FROM codigo) = ?)
              ORDER BY capturado, id"
        );
        $st->execute([date('Y-m-d', time() - $dias * 86400), $raw, $clave]);
        $filas = array_values(array_filter($st->fetchAll(PDO::FETCH_ASSOC), fn($f) => self::claveId((string)$f['codigo']) === $clave));
        if (!$filas) return ['nombre' => (string)($incidencias[0]['persona'] ?? '')] + $base;   // sólo incidencias
        $movs = self::emparejar($filas);
        usort($movs, fn($a, $b) => strcmp(($b['entrada'] ?: $b['salida']), ($a['entrada'] ?: $a['salida'])));   // lo más reciente primero
        $ult = $filas[count($filas) - 1];
        return ['id' => self::idMostrado(array_column($filas, 'codigo')), 'nombre' => self::nombreMasLeido(array_column($filas, 'nombre')),
                'movimientos' => $movs, 'registros' => count($filas),
                'en_planta' => $ult['tipo'] === 'entrada' && strtotime((string)$ult['capturado']) >= time() - self::HORAS_EN_PLANTA * 3600] + $base;
    }

    /** Administración registra la salida de alguien que salió sin pasar su credencial (queda marcada). */
    public function darSalida(array $p, array $usr): array {
        if (!self::esGestor($usr)) return ['status' => 'error', 'message' => 'Sólo el administrador de accesos registra salidas desde aquí.'];
        $per = $this->persona(['id' => $p['id'] ?? '', 'dias' => 3], $usr);
        if (($per['status'] ?? '') !== 'success') return $per;
        if (empty($per['en_planta'])) return ['status' => 'error', 'message' => 'Ese ID ya no aparece en planta.'];
        $r = $this->registrar([
            'uid' => 'adm-' . bin2hex(random_bytes(12)), 'tipo' => 'salida', 'capturado' => date('Y-m-d H:i:s'),
            'lugar' => 'ADMINISTRACION', 'nota' => 'Salida registrada por ' . (($usr['rol'] ?? '') === 'ADMIN' ? 'Administración' : 'el administrador de accesos'),
            'codigo' => $per['id'], 'persona' => $per['nombre'], 'origen' => 'admin',
        ], [], $usr);
        if (($r['status'] ?? '') === 'success') {
            $r['message'] = 'Salida registrada: ' . ($per['nombre'] !== '' ? $per['nombre'] : 'ID ' . $per['id']) . ' ya no aparece en planta.';
        }
        return $r;
    }

    /** Reglas para leer la credencial: la app las pide al abrir y se ajustan aquí sin reinstalarla. */
    public static function reglasTarjeta(): array {
        $j = @file_get_contents(__DIR__ . '/accesos-tarjeta.json');
        $r = is_string($j) ? json_decode($j, true) : null;
        return ['status' => 'success', 'reglas' => is_array($r) ? $r : new \stdClass()];
    }

    public function generarPdfDatos(array $p, array $usr): array {
        if (self::esCaseta($usr)) return ['status' => 'error', 'message' => 'Esta cuenta sólo registra entradas y salidas.'];
        $tk = self::claveTurno($p['turno'] ?? '');
        if (!$tk) return ['status' => 'error', 'message' => 'Elige el turno.'];
        [$fecha, $t] = $tk;
        $lugar = textoEstandar($p['lugar'] ?? '');
        $filas = $this->registrosDelTurno($fecha, $t, $lugar);
        $renglones = self::emparejar($filas);
        $sinCodigo = count(array_filter($filas, fn($f) => ($f['codigo'] ?? '') === ''));
        if (!$renglones) {
            return ['status' => 'error', 'message' => $filas
                ? 'Ese turno sólo tiene registros con foto (sin código de tarjeta): usa el PDF con fotos.'
                : 'No hay registros de ese turno.'];
        }

        if (!class_exists('Mpdf\Mpdf')) {
            $autoload = __DIR__ . '/../vendor/autoload.php';
            if (!is_file($autoload)) return ['status' => 'error', 'message' => 'No está instalado el generador de PDF.'];
            require_once $autoload;
        }
        if (function_exists('set_time_limit')) @set_time_limit(120);
        self::protegerCarpeta();
        @mkdir(self::carpeta() . 'pdf', 0755, true);

        $conFotoSinCodigo = $sinCodigo;
        $personas = count(array_unique(array_map(fn($r) => $r['codigo'], $renglones)));
        $info = [
            'turno' => $t, 'personas' => $personas,
            'entradas' => count(array_filter($renglones, fn($r) => $r['entrada'] !== '')),
            'salidas' => count(array_filter($renglones, fn($r) => $r['salida'] !== '')),
            'sin_salida' => count(array_filter($renglones, fn($r) => $r['entrada'] !== '' && $r['salida'] === '')),
            'sin_entrada' => count(array_filter($renglones, fn($r) => $r['entrada'] === '')),
            'sin_codigo' => $conFotoSinCodigo,
        ];
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8', 'format' => 'A4', 'orientation' => 'P',
            'margin_top' => 10, 'margin_bottom' => 14, 'margin_left' => 10, 'margin_right' => 10,
            'margin_header' => 0, 'margin_footer' => 6,
        ]);
        $nomT = ($t === 'D' ? 'turno de día' : 'turno de noche') . ' ' . date('d/m/Y', strtotime($fecha));
        $mpdf->SetTitle('Entradas y salidas ' . $nomT . ($lugar !== '' ? ' - ' . $lugar : ''));
        $mpdf->SetAuthor('AVBA Control de Accesos');
        $mpdf->SetFooter('Control de accesos · entradas y salidas · ' . $nomT . '||Página {PAGENO} de {nbpg}');
        $mpdf->WriteHTML($this->htmlPdfDatos($fecha, $lugar, $renglones, $usr, $info));

        $slug = $lugar === '' ? 'TODOS' : trim(preg_replace('/[^A-Z0-9]+/', '-', $lugar), '-');
        $slug = substr($slug !== '' ? $slug : 'PUNTO', 0, 40);
        $archivo = "ACCESOS_{$fecha}-{$t}_{$slug}_datos_" . time() . '.pdf';
        $mpdf->Output(self::carpeta() . 'pdf/' . $archivo, 'F');
        return ['status' => 'success', 'archivo' => $archivo, 'url' => 'descargar.php?tipo=accesos_pdf&f=' . rawurlencode($archivo),
                'paginas' => (int)$mpdf->page, 'bytes' => (int)@filesize(self::carpeta() . 'pdf/' . $archivo),
                'renglones' => count($renglones)] + $info;
    }

    private static function horaCorta(string $cap, string $fechaTurno): string {
        if ($cap === '') return '';
        $dia = substr($cap, 0, 10) !== $fechaTurno ? substr($cap, 8, 2) . '/' . substr($cap, 5, 2) . ' ' : '';
        return $dia . substr($cap, 11, 5);
    }

    private function htmlPdfDatos(string $fecha, string $lugar, array $renglones, array $usr, array $info): string {
        $logo = realpath(__DIR__ . '/../assets/logos/avba.png');
        $conLugar = $lugar === '';
        $h = '<table width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse"><tr>'
           . '<td style="width:42mm;vertical-align:middle">' . ($logo ? '<img src="' . self::h($logo) . '" style="width:38mm">' : '') . '</td>'
           . '<td style="vertical-align:middle;padding-left:4mm"><div style="font-size:17pt;font-weight:bold;color:#0C447C">CONTROL DE ACCESOS</div>'
           . '<div style="font-size:11pt;color:#1a1a2e">Entradas y salidas · ' . self::h(self::nombreTurno($fecha, $info['turno'])) . '</div></td></tr></table>'
           . '<table width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin-top:3mm;font-size:9.5pt">'
           . '<tr><td style="background:#f3f6fa;padding:2mm 3mm;width:36%"><b>Punto de control:</b> ' . self::h($lugar !== '' ? $lugar : 'Todos') . '</td>'
           . '<td style="background:#f3f6fa;padding:2mm 3mm"><b>Personas:</b> ' . number_format($info['personas']) . '</td>'
           . '<td style="background:#f3f6fa;padding:2mm 3mm"><b>Entradas:</b> ' . number_format($info['entradas']) . '</td>'
           . '<td style="background:#f3f6fa;padding:2mm 3mm"><b>Salidas:</b> ' . number_format($info['salidas']) . '</td>'
           . '<td style="background:#f3f6fa;padding:2mm 3mm"><b>Generado:</b> ' . date('d/m/Y H:i') . '</td></tr>'
           . '<tr><td colspan="5" style="padding:2mm 3mm;color:#5a6072;font-size:8.5pt">Nombre e ID leídos de la credencial de acceso en el teléfono de la caseta. '
           . 'La entrada y la salida de cada persona se juntan por su ID (el número del recuadro azul). Generado por ' . self::h($usr['nombre'] ?? $usr['usuario'] ?? '') . '.</td></tr></table>';
        $th = 'background:#0C447C;color:#fff;font-weight:bold;padding:2mm 2mm;text-align:left;font-size:9pt';
        $h .= '<table width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin-top:4mm;font-size:9.5pt" repeat_header="1">'
            . '<thead><tr><th style="' . $th . ';width:7mm">#</th><th style="' . $th . '">Nombre</th><th style="' . $th . '">ID</th>'
            . '<th style="' . $th . '">Entrada</th><th style="' . $th . '">Salida</th><th style="' . $th . '">Tiempo dentro</th>'
            . ($conLugar ? '<th style="' . $th . '">Punto de control</th>' : '') . '</tr></thead><tbody>';
        foreach ($renglones as $i => $r) {
            $fondo = $i % 2 ? '#ffffff' : '#f6f8fb';
            $td = 'padding:1.8mm 2mm;border-bottom:0.2mm solid #dfe5ef;background:' . $fondo;
            $tiempo = $r['minutos'] !== null ? intdiv(max(0, $r['minutos']), 60) . ' h ' . str_pad((string)(max(0, $r['minutos']) % 60), 2, '0', STR_PAD_LEFT) . ' min' : '';
            $h .= '<tr><td style="' . $td . ';color:#5a6072">' . ($i + 1) . '</td>'
                . '<td style="' . $td . '">' . ($r['nombre'] !== '' ? self::h($r['nombre']) : '<span style="color:#8b91a0">Sin nombre leído</span>') . '</td>'
                . '<td style="' . $td . ';font-weight:bold">' . self::h($r['codigo']) . '</td>'
                . '<td style="' . $td . '">' . ($r['entrada'] !== '' ? self::horaCorta($r['entrada'], $fecha) : '<span style="color:#A32D2D">Sin entrada</span>') . '</td>'
                . '<td style="' . $td . '">' . ($r['salida'] !== '' ? self::horaCorta($r['salida'], $fecha) : '<span style="color:#8A5A00">Sin salida</span>') . '</td>'
                . '<td style="' . $td . '">' . $tiempo . '</td>'
                . ($conLugar ? '<td style="' . $td . ';font-size:8.5pt">' . self::h($r['lugar']) . '</td>' : '') . '</tr>';
        }
        $h .= '</tbody></table>';
        $notas = [];
        if ($info['sin_salida']) $notas[] = number_format($info['sin_salida']) . ' entrada' . ($info['sin_salida'] === 1 ? '' : 's') . ' sin salida registrada en este turno';
        if ($info['sin_entrada']) $notas[] = number_format($info['sin_entrada']) . ' salida' . ($info['sin_entrada'] === 1 ? '' : 's') . ' sin entrada registrada en este turno';
        if ($info['sin_codigo']) $notas[] = number_format($info['sin_codigo']) . ' registro' . ($info['sin_codigo'] === 1 ? '' : 's') . ' con foto y sin código (están en el PDF con fotos)';
        if ($notas) $h .= '<p style="font-size:8.5pt;color:#5a6072;margin-top:3mm">' . self::h(implode(' · ', $notas)) . '.</p>';
        return $h;
    }

    /** Copia ligera de la foto para el PDF y su tamaño para caber en la celda */
    private function fotoParaPdf(array $f, string $tmp, float $anchoMm, float $altoMm): array {
        $src = self::carpeta() . $f['foto'];
        if ($f['foto'] === '' || !is_file($src)) return ['', 0, 0];
        $dest = comprimirImagen($src, $tmp . $f['uid'] . '.jpg', 1300, 1300, 72);
        $ruta = $dest ? $tmp . $dest : $src;
        $info = @getimagesize($ruta);
        if (!$info) return ['', 0, 0];
        $k = min($anchoMm / max(1, $info[0]), $altoMm / max(1, $info[1]));
        return [$ruta, round($info[0] * $k, 1), round($info[1] * $k, 1)];
    }

    private function seccionPdf(string $titulo, string $color, string $etiqueta, array $filas, string $tmp, bool $conLugar, int $totalTipo = 0): string {
        $rango = count($filas) . ' registro' . (count($filas) === 1 ? '' : 's');
        if ($filas && $totalTipo > count($filas)) {
            $rango = 'del ' . $filas[0]['num'] . ' al ' . $filas[count($filas) - 1]['num'] . ' de ' . number_format($totalTipo);
        }
        $h = '<table width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin-top:4mm"><tr>'
           . '<td style="background:' . $color . ';color:#fff;font-weight:bold;font-size:13pt;padding:3mm 4mm">' . self::h($titulo)
           . ' &nbsp;·&nbsp; ' . $rango . '</td></tr></table>';
        if (!$filas) return $h . '<p style="font-size:10pt;color:#5a6072">Sin registros.</p>';
        foreach (array_chunk($filas, 2) as $par) {
            $h .= '<table width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;page-break-inside:avoid;margin-top:3mm"><tr>';
            foreach ([0, 1] as $i) {
                $f = $par[$i] ?? null;
                if (!$f) { $h .= '<td width="50%"></td>'; continue; }
                $lbl = '<b style="color:' . $color . '">' . $etiqueta . ' ' . str_pad((string)$f['num'], 3, '0', STR_PAD_LEFT) . '</b>'
                     . ' &nbsp;·&nbsp; ' . substr((string)$f['capturado'], 8, 2) . '/' . substr((string)$f['capturado'], 5, 2) . ' ' . substr((string)$f['capturado'], 11, 8)
                     . ($conLugar ? ' &nbsp;·&nbsp; ' . self::h($f['lugar']) : '');
                $cred = (string)($f['codigo'] ?? '') !== ''
                      ? '<br><span style="font-size:9.5pt"><b>ID ' . self::h($f['codigo']) . '</b>'
                        . ((string)($f['nombre'] ?? '') !== '' ? ' &nbsp;·&nbsp; ' . self::h($f['nombre']) : '') . '</span>'
                      : '';
                $h .= '<td width="50%" style="padding:0 2mm;vertical-align:top;font-size:10pt">' . $lbl . $cred
                    . ($f['nota'] !== '' ? '<br><span style="font-size:8.5pt;color:#5a6072">' . self::h($f['nota']) . '</span>' : '') . '</td>';
            }
            // El alto del renglón es el de su foto más alta: las credenciales
            // (horizontales) ocupan la mitad y caben más por página.
            $fotos = array_map(fn($f) => $f ? $this->fotoParaPdf($f, $tmp, 88, 104) : ['', 0, 0], [$par[0] ?? null, $par[1] ?? null]);
            $alto = max(20, max($fotos[0][2], $fotos[1][2]) + 2);
            $h .= '</tr><tr>';
            foreach ([0, 1] as $i) {
                if (!isset($par[$i])) { $h .= '<td width="50%"></td>'; continue; }
                [$ruta, $w, $alt] = $fotos[$i];
                $h .= '<td width="50%" style="padding:1mm 2mm 0;vertical-align:top;height:' . $alto . 'mm;text-align:center">'
                    . ($ruta !== '' ? '<img src="' . self::h($ruta) . '" style="width:' . $w . 'mm;height:' . $alt . 'mm;border:0.3mm solid #c9d2de">'
                                    : '<span style="color:#A32D2D;font-size:9pt">Foto no disponible</span>') . '</td>';
            }
            $h .= '</tr></table>';
        }
        return $h;
    }

    private function htmlPdf(string $fecha, string $lugar, string $incluir, array $ent, array $sal, array $usr, string $tmp, array $info): string {
        $logo = realpath(__DIR__ . '/../assets/logos/avba.png');
        $conLugar = $lugar === '';
        $h = '<table width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse"><tr>'
           . '<td style="width:42mm;vertical-align:middle">' . ($logo ? '<img src="' . self::h($logo) . '" style="width:38mm">' : '') . '</td>'
           . '<td style="vertical-align:middle;padding-left:4mm"><div style="font-size:17pt;font-weight:bold;color:#0C447C">CONTROL DE ACCESOS</div>'
           . '<div style="font-size:11pt;color:#1a1a2e">' . self::h(self::nombreTurno($fecha, $info['turno'])) . '</div></td></tr></table>'
           . '<table width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin-top:3mm;font-size:9.5pt">'
           . '<tr><td style="background:#f3f6fa;padding:2mm 3mm;width:44%"><b>Punto de control:</b> ' . self::h($lugar !== '' ? $lugar : 'Todos') . '</td>'
           . '<td style="background:#f3f6fa;padding:2mm 3mm;width:15%"><b>Entradas:</b> ' . number_format($info['tot_ent']) . '</td>'
           . '<td style="background:#f3f6fa;padding:2mm 3mm;width:15%"><b>Salidas:</b> ' . number_format($info['tot_sal']) . '</td>'
           . '<td style="background:#f3f6fa;padding:2mm 3mm"><b>Generado:</b> ' . date('d/m/Y H:i') . '</td></tr>'
           . '<tr><td colspan="4" style="padding:2mm 3mm;color:#5a6072;font-size:8.5pt">Cada fotografía lleva su etiqueta: tipo (ENTRADA o SALIDA), número consecutivo, fecha y hora de registro'
           . ($conLugar ? ' y punto de control' : '') . '. Generado por ' . self::h($usr['nombre'] ?? $usr['usuario'] ?? '') . '.'
           . ($info['partes'] > 1 ? ' <b style="color:#0C447C">Parte ' . $info['parte'] . ' de ' . $info['partes'] . '.</b>' : '')
           . '</td></tr></table>';
        if ($ent) $h .= $this->seccionPdf('ENTRADAS', '#2F6B1E', 'ENTRADA', $ent, $tmp, $conLugar, $info['tot_ent']);
        if ($ent && $sal) $h .= '<pagebreak />';
        if ($sal) $h .= $this->seccionPdf('SALIDAS', '#A32D2D', 'SALIDA', $sal, $tmp, $conLugar, $info['tot_sal']);
        return $h;
    }
}
