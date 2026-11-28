<?php
/* ══════════════════════════════════════════════════════════════
   AVBA Control de Accesos — Personal (la base de IDs y nombres) y permisos

   La base del personal: cada persona con el ID de su credencial (la llave; se
   compara sin ceros a la izquierda: 00287 = 287), su nombre completo, empresa
   y cargo. Se carga desde un Excel y se mantiene en personal.html
   (Administración AVBA y administrador de la planta: agregar, cambiar o
   quitar IDs).

   Al leer una credencial en la caseta:
     · si el ID está en la base, se guarda su nombre correcto (no el que leyó
       la cámara) y el guardia ve sus permisos;
     · si no está, se registra igual y se avisa («no está en la base»).

   Permisos especiales de un ID (personal_permisos): resguardo de herramienta,
   permiso de celular, de computadora u otro. Llevan la foto del pase o del
   resguardo y/o los datos del equipo, y una vigencia opcional. Los agrega
   Administración (personal.html) o el guardia en la caseta (con la foto del
   pase físico, también sin señal: va por la cola del teléfono). La próxima vez
   que se lee ese ID, la caseta muestra que tiene el permiso.

   El teléfono de la caseta guarda una copia de la base (PADRON_CAJA) para
   funcionar sin señal; ca_ajustes.padron_version le dice cuándo cambió.
   ══════════════════════════════════════════════════════════════ */

require_once __DIR__ . '/lib/xlsx_lector.php';
require_once __DIR__ . '/lib/xlsx.php';

class Personal {
    private PDO $pdo;

    public const TIPOS = [
        'HERRAMIENTA' => 'Resguardo de herramienta',
        'CELULAR'     => 'Permiso de celular',
        'COMPUTADORA' => 'Permiso de computadora',
        'OTRO'        => 'Otro permiso',
    ];
    /** Quien agregó un permiso lo puede quitar en este tiempo (como los registros). */
    private const HORAS_PARA_QUITAR = 18;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        self::asegurarTablas($pdo);
    }

    /** Administración AVBA y el administrador de la planta manejan la base y los permisos. */
    public static function puedeAdministrar(?array $usr): bool {
        return ControlAccesos::esGestor($usr);
    }

    /* ── Tablas ──────────────────────────────────────────────────────────── */
    public static function asegurarTablas(PDO $pdo): void {
        static $hecho = [];
        $k = spl_object_id($pdo);
        if (isset($hecho[$k])) return;
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS personal (
               id              INT AUTO_INCREMENT PRIMARY KEY,
               clave           VARCHAR(32)  NOT NULL,
               codigo          VARCHAR(32)  NOT NULL,
               nombre          VARCHAR(150) NOT NULL,
               empresa         VARCHAR(150) NOT NULL DEFAULT '',
               cargo           VARCHAR(150) NOT NULL DEFAULT '',
               creado          DATETIME     NOT NULL,
               actualizado     DATETIME     NOT NULL,
               actualizado_por VARCHAR(150) NOT NULL DEFAULT '',
               UNIQUE KEY uk_clave (clave),
               KEY idx_nombre (nombre)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS personal_permisos (
               id             INT AUTO_INCREMENT PRIMARY KEY,
               uid            VARCHAR(64)  NOT NULL,
               clave          VARCHAR(32)  NOT NULL,
               tipos          VARCHAR(80)  NOT NULL,
               detalle        VARCHAR(500) NOT NULL DEFAULT '',
               vigencia       DATE         NULL,
               foto           VARCHAR(255) NOT NULL DEFAULT '',
               foto_mini      VARCHAR(255) NOT NULL DEFAULT '',
               origen         VARCHAR(12)  NOT NULL DEFAULT 'admin',
               creado         DATETIME     NOT NULL,
               usuario        VARCHAR(50)  NOT NULL DEFAULT '',
               usuario_nombre VARCHAR(150) NOT NULL DEFAULT '',
               activo         TINYINT(1)   NOT NULL DEFAULT 1,
               actualizado    DATETIME     NOT NULL,
               quitado_por    VARCHAR(150) NOT NULL DEFAULT '',
               UNIQUE KEY uk_uid (uid),
               KEY idx_clave (clave, activo)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS ca_ajustes (
               clave       VARCHAR(50)  NOT NULL PRIMARY KEY,
               valor       VARCHAR(255) NOT NULL DEFAULT '',
               actualizado DATETIME     NOT NULL
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $hecho[$k] = true;
    }

    /** Cada cambio en la base o en los permisos sube la versión: la caseta sabe que debe actualizar su copia. */
    /** Marca que cambió la base (personas) o sólo los permisos: los teléfonos bajan sólo lo que cambió. */
    private function tocarVersion(string $que = 'padron_version'): void {
        $this->pdo->prepare("INSERT INTO ca_ajustes (clave, valor, actualizado) VALUES (?, '1', NOW())
                             ON DUPLICATE KEY UPDATE valor = CAST(valor AS UNSIGNED) + 1, actualizado = NOW()")->execute([$que]);
        self::$nombres = [];
    }
    /** La versión de la copia del teléfono: «personas.permisos» (p. ej. «12.40»). */
    public static function versionActual(PDO $pdo): string {
        $v = ['padron_version' => '0', 'permisos_version' => '0'];
        try {
            foreach ($pdo->query("SELECT clave, valor FROM ca_ajustes WHERE clave IN ('padron_version', 'permisos_version')")->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $x) $v[$k] = (string)$x;
        } catch (\Throwable $e) { }
        return $v['padron_version'] . '.' . $v['permisos_version'];
    }
    private function version(): string { return self::versionActual($this->pdo); }

    /* ── Utilidades ──────────────────────────────────────────────────────── */
    /** El ID como se escribe: mayúsculas, números, letras y guion. */
    public static function limpiarId($v): string {
        return strtoupper(substr((string)preg_replace('/[^0-9A-Za-z-]/', '', trim((string)$v)), 0, 32));
    }
    /** Cómo se muestra el ID: un número corto lleva ceros a la izquierda hasta 5 dígitos (como en la credencial). */
    public static function idMostrado(string $id): string {
        return ctype_digit($id) && strlen($id) < 5 ? str_pad($id, 5, '0', STR_PAD_LEFT) : $id;
    }

    private static array $nombres = [];
    /** El nombre de un ID en la base del personal, o '' si no está. */
    public static function nombreDe(PDO $pdo, string $codigo): string {
        $clave = ControlAccesos::claveId(self::limpiarId($codigo));
        if ($clave === '') return '';
        if (!array_key_exists($clave, self::$nombres)) {
            try {
                self::asegurarTablas($pdo);
                $st = $pdo->prepare("SELECT nombre FROM personal WHERE clave = ?");
                $st->execute([$clave]);
                self::$nombres[$clave] = (string)($st->fetchColumn() ?: '');
            } catch (\Throwable $e) {
                error_log('[Personal] nombreDe: ' . $e->getMessage());
                self::$nombres[$clave] = '';
            }
        }
        return self::$nombres[$clave];
    }

    private static function tiposDe($v): array {
        $l = is_array($v) ? $v : explode(',', (string)$v);
        $out = [];
        foreach ($l as $t) { $t = strtoupper(trim((string)$t)); if (isset(self::TIPOS[$t]) && !in_array($t, $out, true)) $out[] = $t; }
        return $out;
    }
    private static function fechaValida($v): ?string {
        $v = trim((string)$v);
        if ($v === '' || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return null;
        return $v;
    }

    /** Un permiso como lo ven las pantallas. */
    private function filaPermiso(array $f, ?array $usr): array {
        $tipos = self::tiposDe($f['tipos']);
        $propio = ($f['usuario'] ?? '') === ($usr['usuario'] ?? null) && strtotime((string)$f['creado']) >= time() - self::HORAS_PARA_QUITAR * 3600;
        return [
            'id' => (int)$f['id'], 'uid' => (string)$f['uid'], 'clave' => (string)$f['clave'],
            'tipos' => $tipos, 'nombres' => array_map(fn($t) => self::TIPOS[$t], $tipos),
            'detalle' => (string)$f['detalle'], 'vigencia' => $f['vigencia'] ? (string)$f['vigencia'] : '',
            'vencido' => $f['vigencia'] && (string)$f['vigencia'] < date('Y-m-d'),
            'con_foto' => (string)$f['foto'] !== '', 'origen' => (string)$f['origen'],
            'creado' => (string)$f['creado'], 'por' => (string)$f['usuario_nombre'],
            'activo' => (bool)$f['activo'], 'quitado_por' => (string)($f['quitado_por'] ?? ''),
            'puede_quitar' => (bool)$f['activo'] && (self::puedeAdministrar($usr) || $propio),
        ];
    }
    /** Los permisos vigentes (activos) de varias claves: [clave => [permisos]]. */
    private function permisosDe(array $claves, ?array $usr): array {
        $claves = array_values(array_unique(array_filter($claves, fn($c) => $c !== '')));
        if (!$claves) return [];
        $out = [];
        foreach (array_chunk($claves, 500) as $parte) {
            $st = $this->pdo->prepare("SELECT * FROM personal_permisos WHERE activo = 1 AND clave IN (" . implode(',', array_fill(0, count($parte), '?')) . ") ORDER BY creado DESC, id DESC");
            $st->execute($parte);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) $out[$f['clave']][] = $this->filaPermiso($f, $usr);
        }
        return $out;
    }

    /* ── Consultar ───────────────────────────────────────────────────────── */

    /** Buscar en la base (por ID o por nombre, empresa o cargo); hasta 200 a la vez. */
    public function listar(array $p, array $usr): array {
        if (!self::puedeAdministrar($usr)) return self::sinPermiso();
        $q = trim((string)($p['q'] ?? ''));
        $filtro = (string)($p['filtro'] ?? '');                      // '' | 'permisos'
        $limite = max(1, min(200, (int)($p['limite'] ?? 100)));
        $desde = max(0, (int)($p['desde'] ?? 0));
        $donde = []; $args = [];
        if ($q !== '') {
            $id = self::limpiarId($q);
            $partes = array_filter(explode(' ', textoEstandar($q)));
            $nom = [];
            foreach ($partes as $pt) { $nom[] = "CONCAT(nombre, ' ', empresa, ' ', cargo) LIKE ?"; $args[] = '%' . $pt . '%'; }
            $cond = $nom ? '(' . implode(' AND ', $nom) . ')' : '0';
            if ($id !== '' && preg_match('/\d/', $id)) {
                $cond .= ' OR clave LIKE ?';
                $args[] = ControlAccesos::claveId($id) . '%';
            }
            $donde[] = '(' . $cond . ')';
        }
        if ($filtro === 'permisos') $donde[] = 'clave IN (SELECT clave FROM personal_permisos WHERE activo = 1)';
        $w = $donde ? 'WHERE ' . implode(' AND ', $donde) : '';
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM personal $w");
        $st->execute($args);
        $total = (int)$st->fetchColumn();
        $orden = ($q !== '' && preg_match('/^\d+$/', self::limpiarId($q))) ? 'LENGTH(clave), clave' : 'nombre, clave';
        $st = $this->pdo->prepare("SELECT * FROM personal $w ORDER BY $orden LIMIT $limite OFFSET $desde");
        $st->execute($args);
        $filas = $st->fetchAll(PDO::FETCH_ASSOC);
        $perm = $this->permisosDe(array_column($filas, 'clave'), $usr);
        return ['status' => 'success', 'total' => $total, 'desde' => $desde,
                'total_base' => (int)$this->pdo->query("SELECT COUNT(*) FROM personal")->fetchColumn(),
                'con_permisos' => (int)$this->pdo->query("SELECT COUNT(DISTINCT clave) FROM personal_permisos WHERE activo = 1")->fetchColumn(),
                'personas' => array_map(fn($f) => self::filaPersona($f) + ['permisos' => $perm[$f['clave']] ?? []], $filas),
                'tipos' => self::TIPOS];
    }
    private static function filaPersona(array $f): array {
        return ['id' => (int)$f['id'], 'clave' => (string)$f['clave'], 'codigo' => (string)$f['codigo'], 'nombre' => (string)$f['nombre'],
                'empresa' => (string)$f['empresa'], 'cargo' => (string)$f['cargo'],
                'actualizado' => (string)$f['actualizado'], 'actualizado_por' => (string)$f['actualizado_por']];
    }

    /** Una persona (por ID) con sus permisos, también los quitados (historial). */
    public function ficha(array $p, array $usr): array {
        if (!self::puedeAdministrar($usr)) return self::sinPermiso();
        $clave = ControlAccesos::claveId(self::limpiarId($p['codigo'] ?? ''));
        if ($clave === '') return ['status' => 'error', 'message' => 'Escribe el ID.'];
        $st = $this->pdo->prepare("SELECT * FROM personal WHERE clave = ?");
        $st->execute([$clave]);
        $f = $st->fetch(PDO::FETCH_ASSOC);
        $st = $this->pdo->prepare("SELECT * FROM personal_permisos WHERE clave = ? ORDER BY activo DESC, creado DESC, id DESC LIMIT 100");
        $st->execute([$clave]);
        return ['status' => 'success', 'en_base' => (bool)$f, 'persona' => $f ? self::filaPersona($f) : null, 'clave' => $clave,
                'permisos' => array_map(fn($x) => $this->filaPermiso($x, $usr), $st->fetchAll(PDO::FETCH_ASSOC)), 'tipos' => self::TIPOS];
    }

    /**
     * Copia de la base para el teléfono de la caseta (funciona sin señal). Con la versión que ya tiene,
     * sólo responde si cambió.
     */
    public function caja(array $p, array $usr): array {
        $v = $this->version();
        $tiene = (string)($p['version'] ?? '');
        if ($tiene === $v && $v !== '0.0') return ['status' => 'success', 'version' => $v, 'sin_cambios' => true];
        $permisos = [];
        foreach ($this->pdo->query("SELECT * FROM personal_permisos WHERE activo = 1 ORDER BY creado DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $x = $this->filaPermiso($f, $usr);
            unset($x['clave'], $x['activo'], $x['quitado_por'], $x['nombres']);
            $permisos[$f['clave']][] = $x;
        }
        // Si la base (personas) es la misma que ya tiene el teléfono y sólo cambiaron los permisos, van sólo los
        // permisos (pocos datos): la base completa se baja sólo cuando se carga el Excel o se cambia una persona
        if (strpos($tiene, '.') !== false && explode('.', $tiene)[0] === explode('.', $v)[0]) {
            return ['status' => 'success', 'version' => $v, 'solo_permisos' => true, 'permisos' => $permisos ?: new \stdClass(), 'tipos' => self::TIPOS];
        }
        $personas = [];
        foreach ($this->pdo->query("SELECT clave, codigo, nombre, empresa FROM personal")->fetchAll(PDO::FETCH_NUM) as [$c, $cod, $n, $e]) {
            $personas[$c] = [$cod, $n, $e];
        }
        return ['status' => 'success', 'version' => $v, 'total' => count($personas),
                'personas' => $personas ?: new \stdClass(), 'permisos' => $permisos ?: new \stdClass(), 'tipos' => self::TIPOS];
    }

    /** IDs que se leyeron en la caseta y no están en la base (últimos días): para agregarlos o detectar lecturas malas. */
    public function faltantes(array $p, array $usr): array {
        if (!self::puedeAdministrar($usr)) return self::sinPermiso();
        $dias = max(1, min(90, (int)($p['dias'] ?? 30)));
        if (!ControlAccesos::conTarjeta($this->pdo)) return ['status' => 'success', 'ids' => [], 'dias' => $dias];
        $st = $this->pdo->prepare("SELECT codigo, nombre, capturado FROM accesos_registros WHERE codigo <> '' AND fecha >= ? AND "
                                  . Omitidos::fuera($this->pdo) . " ORDER BY capturado");
        $st->execute([date('Y-m-d', time() - $dias * 86400)]);
        $porClave = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) $porClave[ControlAccesos::claveId((string)$f['codigo'])][] = $f;
        if (!$porClave) return ['status' => 'success', 'ids' => [], 'dias' => $dias];
        $en = [];
        foreach (array_chunk(array_map('strval', array_keys($porClave)), 500) as $parte) {
            $st = $this->pdo->prepare("SELECT clave FROM personal WHERE clave IN (" . implode(',', array_fill(0, count($parte), '?')) . ")");
            $st->execute($parte);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) $en[$c] = true;
        }
        $out = [];
        foreach ($porClave as $clave => $regs) {
            if (isset($en[(string)$clave])) continue;
            $out[] = ['codigo' => self::idMostrado(self::limpiarId((string)$regs[count($regs) - 1]['codigo'])), 'clave' => (string)$clave,
                      'nombre' => ControlAccesos::nombreMasLeido(array_column($regs, 'nombre')),
                      'lecturas' => count($regs), 'ultima' => (string)$regs[count($regs) - 1]['capturado']];
        }
        usort($out, fn($a, $b) => strcmp($b['ultima'], $a['ultima']));
        return ['status' => 'success', 'ids' => array_slice($out, 0, 300), 'total' => count($out), 'dias' => $dias];
    }

    /* ── Cambiar ─────────────────────────────────────────────────────────── */

    /** Agregar (id = 0) o cambiar una persona: ID, nombre, empresa y cargo. Cambiar el ID se lleva sus permisos. */
    public function guardar(array $p, array $usr): array {
        if (!self::puedeAdministrar($usr)) return self::sinPermiso();
        $id = (int)($p['id'] ?? 0);
        $codigo = self::idMostrado(self::limpiarId($p['codigo'] ?? ''));
        $clave = ControlAccesos::claveId($codigo);
        $nombre = mb_substr(textoEstandar($p['nombre'] ?? ''), 0, 150);
        $empresa = mb_substr(textoEstandar($p['empresa'] ?? ''), 0, 150);
        $cargo = mb_substr(textoEstandar($p['cargo'] ?? ''), 0, 150);
        if ($clave === '') return ['status' => 'error', 'message' => 'Escribe el ID de la credencial.'];
        if ($nombre === '') return ['status' => 'error', 'message' => 'Escribe el nombre completo.'];
        $st = $this->pdo->prepare("SELECT * FROM personal WHERE clave = ?");
        $st->execute([$clave]);
        $otro = $st->fetch(PDO::FETCH_ASSOC);
        if ($otro && (int)$otro['id'] !== $id) {
            return ['status' => 'error', 'message' => 'El ID ' . $otro['codigo'] . ' ya es de ' . $otro['nombre'] . '.'];
        }
        $quien = mb_substr((string)($usr['nombre'] ?? $usr['usuario'] ?? ''), 0, 150);
        if ($id === 0) {
            $this->pdo->prepare("INSERT INTO personal (clave, codigo, nombre, empresa, cargo, creado, actualizado, actualizado_por) VALUES (?, ?, ?, ?, ?, NOW(), NOW(), ?)")
                ->execute([$clave, $codigo, $nombre, $empresa, $cargo, $quien]);
            $id = (int)$this->pdo->lastInsertId();
            $msg = 'Se agregó ' . $nombre . ' (ID ' . $codigo . ').';
        } else {
            $st = $this->pdo->prepare("SELECT * FROM personal WHERE id = ?");
            $st->execute([$id]);
            $antes = $st->fetch(PDO::FETCH_ASSOC);
            if (!$antes) return ['status' => 'error', 'message' => 'Esa persona ya no está en la base.'];
            $this->pdo->beginTransaction();
            try {
                $this->pdo->prepare("UPDATE personal SET clave = ?, codigo = ?, nombre = ?, empresa = ?, cargo = ?, actualizado = NOW(), actualizado_por = ? WHERE id = ?")
                    ->execute([$clave, $codigo, $nombre, $empresa, $cargo, $quien, $id]);
                if ($antes['clave'] !== $clave) {                       // cambió el ID: sus permisos van con ella
                    $this->pdo->prepare("UPDATE personal_permisos SET clave = ?, actualizado = NOW() WHERE clave = ?")->execute([$clave, $antes['clave']]);
                }
                $this->pdo->commit();
            } catch (\Throwable $e) { $this->pdo->rollBack(); throw $e; }
            $msg = 'Se guardaron los cambios de ' . $nombre . ($antes['clave'] !== $clave ? ' (su ID cambió de ' . $antes['codigo'] . ' a ' . $codigo . ')' : '') . '.';
        }
        $this->tocarVersion();
        return ['status' => 'success', 'id' => $id, 'codigo' => $codigo, 'message' => $msg];
    }

    /** Quitar a una persona de la base. Sus permisos se dan de baja (quedan en el historial). */
    public function eliminar(array $p, array $usr): array {
        if (!self::puedeAdministrar($usr)) return self::sinPermiso();
        $st = $this->pdo->prepare("SELECT * FROM personal WHERE id = ?");
        $st->execute([(int)($p['id'] ?? 0)]);
        $f = $st->fetch(PDO::FETCH_ASSOC);
        if (!$f) return ['status' => 'success', 'message' => 'Esa persona ya no estaba en la base.'];
        $quien = mb_substr((string)($usr['nombre'] ?? $usr['usuario'] ?? ''), 0, 150);
        $this->pdo->prepare("DELETE FROM personal WHERE id = ?")->execute([(int)$f['id']]);
        $st = $this->pdo->prepare("UPDATE personal_permisos SET activo = 0, actualizado = NOW(), quitado_por = ? WHERE clave = ? AND activo = 1");
        $st->execute([$quien, $f['clave']]);
        $this->tocarVersion();
        return ['status' => 'success', 'message' => 'Se quitó de la base a ' . $f['nombre'] . ' (ID ' . $f['codigo'] . ')' . ($st->rowCount() ? ' y sus permisos' : '') . '.'];
    }

    /**
     * Agregar un permiso a un ID: desde la caseta (con la foto del pase o del resguardo; llega por la cola del
     * teléfono, un reenvío no lo duplica) o desde personal.html (foto y/o datos del equipo).
     */
    public function permisoAgregar(array $p, array $archivo, array $usr): array {
        $caseta = ControlAccesos::esCaseta($usr);
        if (!$caseta && !self::puedeAdministrar($usr)) return self::sinPermiso();
        $uid = (string)($p['uid'] ?? '');
        if (!preg_match('/^[A-Za-z0-9-]{8,64}$/', $uid)) $uid = 'pm-' . bin2hex(random_bytes(12));
        $st = $this->pdo->prepare("SELECT id FROM personal_permisos WHERE uid = ?");
        $st->execute([$uid]);
        if ($ya = $st->fetchColumn()) return ['status' => 'success', 'id' => (int)$ya, 'duplicado' => true];

        $codigo = self::limpiarId($p['codigo'] ?? '');
        $clave = ControlAccesos::claveId($codigo);
        if ($clave === '') return ['status' => 'error', 'message' => 'Falta el ID de la persona.'];
        $tipos = self::tiposDe($p['tipos'] ?? '');
        if (!$tipos) return ['status' => 'error', 'message' => 'Marca qué permiso es: herramienta, celular, computadora u otro.'];
        $detalle = mb_substr(trim(preg_replace('/\s+/', ' ', (string)($p['detalle'] ?? ''))), 0, 500);
        if (in_array('OTRO', $tipos, true) && $detalle === '') return ['status' => 'error', 'message' => 'Para «Otro permiso» escribe de qué es.'];
        $vigencia = self::fechaValida($p['vigencia'] ?? '');
        if (trim((string)($p['vigencia'] ?? '')) !== '' && !$vigencia) return ['status' => 'error', 'message' => 'La fecha de vigencia no es válida.'];
        $conFoto = !empty($archivo['tmp_name']) && (int)($archivo['error'] ?? 1) === UPLOAD_ERR_OK;
        if ($caseta && !$conFoto) return ['status' => 'error', 'message' => 'Toma la foto del pase o del resguardo.'];
        if (!$conFoto && $detalle === '') return ['status' => 'error', 'message' => 'Sube la foto del pase o escribe los datos del equipo o de la herramienta.'];
        if ($conFoto && ($mal = ControlAccesos::fotoInvalida($archivo))) return ['status' => 'error', 'message' => $mal];
        $foto = ''; $mini = '';
        if ($conFoto) {
            $g = ControlAccesos::guardarFoto($archivo, $uid, date('Y-m-d'), 'permisos');
            if (!$g) return ['status' => 'error', 'message' => 'No se pudo guardar la foto en el servidor.'];
            [$foto, $mini] = $g;
        }
        try {
            $this->pdo->prepare(
                "INSERT INTO personal_permisos (uid, clave, tipos, detalle, vigencia, foto, foto_mini, origen, creado, usuario, usuario_nombre, activo, actualizado)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, 1, NOW())")
                ->execute([$uid, $clave, implode(',', $tipos), $detalle, $vigencia, $foto, $mini, $caseta ? 'caseta' : 'admin',
                           mb_substr((string)($usr['usuario'] ?? ''), 0, 50), mb_substr((string)($usr['nombre'] ?? ''), 0, 150)]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {                             // el mismo envío llegó dos veces a la vez
                $st->execute([$uid]);
                if ($ya = $st->fetchColumn()) return ['status' => 'success', 'id' => (int)$ya, 'duplicado' => true];
            }
            throw $e;
        }
        $id = (int)$this->pdo->lastInsertId();
        $this->tocarVersion('permisos_version');
        $nombres = implode(' y ', array_map(fn($t) => mb_strtolower(self::TIPOS[$t]), $tipos));
        return ['status' => 'success', 'id' => $id, 'message' => ucfirst($nombres) . (count($tipos) > 1 ? ' guardados' : ' guardado') . ' para el ID ' . self::idMostrado($codigo) . '.'];
    }

    /** Quitar un permiso (Administración, o quien lo agregó en las primeras horas). Queda en el historial. */
    public function permisoQuitar(array $p, array $usr): array {
        $st = $this->pdo->prepare("SELECT * FROM personal_permisos WHERE id = ?");
        $st->execute([(int)($p['id'] ?? 0)]);
        $f = $st->fetch(PDO::FETCH_ASSOC);
        if (!$f || !$f['activo']) return ['status' => 'success', 'message' => 'Ese permiso ya no estaba activo.'];
        if (!$this->filaPermiso($f, $usr)['puede_quitar']) {
            return ['status' => 'error', 'message' => 'Sólo Administración (o quien lo agregó, en las primeras ' . self::HORAS_PARA_QUITAR . ' horas) puede quitar un permiso.'];
        }
        $this->pdo->prepare("UPDATE personal_permisos SET activo = 0, actualizado = NOW(), quitado_por = ? WHERE id = ?")
            ->execute([mb_substr((string)($usr['nombre'] ?? $usr['usuario'] ?? ''), 0, 150), (int)$f['id']]);
        $this->tocarVersion('permisos_version');
        return ['status' => 'success', 'message' => 'Se quitó el permiso.'];
    }

    /** La foto del pase o resguardo (la ven Administración, la planta y los guardias; el cliente no). */
    public function rutaFotoPermiso(int $id, bool $mini, ?array $usr): ?string {
        if (!$usr || ControlAccesos::esCliente($usr)) return null;
        $st = $this->pdo->prepare("SELECT foto, foto_mini FROM personal_permisos WHERE id = ?");
        $st->execute([$id]);
        $f = $st->fetch(PDO::FETCH_ASSOC);
        if (!$f) return null;
        $rel = ($mini && $f['foto_mini'] !== '') ? $f['foto_mini'] : $f['foto'];
        if ($rel === '' || strpos($rel, '..') !== false) return null;
        $abs = ControlAccesos::carpeta() . $rel;
        return is_file($abs) ? $abs : null;
    }

    /* ── Cargar un Excel ─────────────────────────────────────────────────── */

    /** Qué columna es qué, por su título (ID, nombre o nombres y apellidos, empresa, cargo). */
    private static function adivinarColumnas(array $titulos): array {
        $mapa = ['id' => -1, 'nombre' => -1, 'paterno' => -1, 'materno' => -1, 'empresa' => -1, 'cargo' => -1];
        foreach ($titulos as $i => $t) {
            $t = ' ' . trim((string)preg_replace('/[^A-Z0-9Ñ ]+/u', ' ', textoEstandar($t))) . ' ';
            if (preg_match('/ (NSS|SEGURO|IMSS|CURP|RFC|TELEFONO|CELULAR|CORREO|SANGRE|ALERGIA|FECHA|EDAD) /', $t)) continue;
            if ($mapa['id'] < 0 && preg_match('/ (ID|IDS|CREDENCIAL|GAFETE|FOLIO|NO|NUM|NUMERO|N|CLAVE) /', $t)) { $mapa['id'] = $i; continue; }
            if ($mapa['paterno'] < 0 && preg_match('/ (PATERNO|APELLIDO PATERNO|PRIMER APELLIDO|1ER APELLIDO) /', $t)) { $mapa['paterno'] = $i; continue; }
            if ($mapa['materno'] < 0 && preg_match('/ (MATERNO|APELLIDO MATERNO|SEGUNDO APELLIDO|2DO APELLIDO) /', $t)) { $mapa['materno'] = $i; continue; }
            if ($mapa['paterno'] < 0 && preg_match('/ APELLIDOS? /', $t)) { $mapa['paterno'] = $i; continue; }
            if ($mapa['nombre'] < 0 && preg_match('/ (NOMBRE|NOMBRES|NOMBRE COMPLETO|TRABAJADOR|EMPLEADO|PERSONA|COLABORADOR) /', $t)) { $mapa['nombre'] = $i; continue; }
            if ($mapa['empresa'] < 0 && preg_match('/ (EMPRESA|COMPANIA|COMPAÑIA|CONTRATISTA|RAZON SOCIAL|PROVEEDOR) /', $t)) { $mapa['empresa'] = $i; continue; }
            if ($mapa['cargo'] < 0 && preg_match('/ (CARGO|PUESTO|CATEGORIA|OFICIO|ESPECIALIDAD|FUNCION) /', $t)) { $mapa['cargo'] = $i; continue; }
        }
        return $mapa;
    }

    /** Dos nombres de la misma persona (una corrección: «LUIS MORA» → «LUIS ALBERTO MORA»): comparten alguna palabra de 3 letras o más. */
    private static function mismaPersona(string $a, string $b): bool {
        $palabras = fn(string $x) => array_filter(preg_split('/[^A-Z0-9Ñ]+/u', textoEstandar($x)) ?: [], fn($w) => mb_strlen($w) >= 3);
        return (bool)array_intersect($palabras($a), $palabras($b));
    }

    /* ── Cargar permisos desde Excel ────────────────────────────────────────
       Para las listas de equipos ya autorizados (p. ej. «Registro de ingreso de equipos electrónicos»): un
       renglón por equipo con el ID de la persona, su nombre (para revisar que el ID sea de ella), tipo de equipo,
       marca, modelo y número de serie; vigencia y referencia (folio, quién autorizó) son opcionales. Cada renglón
       es un permiso. modo=revisar sólo dice qué va a pasar; modo=aplicar los guarda. No se repite un equipo que
       la persona ya tiene (mismo número de serie, o la misma descripción si no tiene serie). */
    private static function columnasPermisos(array $titulos): array {
        $m = ['id' => -1, 'nombre' => -1, 'tipo' => -1, 'marca' => -1, 'modelo' => -1, 'serie' => -1, 'vigencia' => -1, 'referencia' => -1];
        foreach ($titulos as $i => $t) {
            $t = ' ' . trim((string)preg_replace('/[^A-Z0-9Ñ ]+/u', ' ', textoEstandar($t))) . ' ';
            $prueba = [
                'serie'      => '/ (SERIE|NS|N S|SERIAL|IMEI) /',
                'tipo'       => '/ (TIPO|EQUIPO|DISPOSITIVO|TIPO DE EQUIPO) /',
                'marca'      => '/ MARCA /',
                'modelo'     => '/ MODELO /',
                'vigencia'   => '/ (VIGENCIA|VENCE|VENCIMIENTO|HASTA) /',
                'referencia' => '/ (REFERENCIA|AUTORIZACION|AUTORIZO|FOLIO|OFICIO|VOBO|OBSERVACIONES|NOTA) /',
                'nombre'     => '/ NOMBRE /',
                'id'         => '/ (ID|CREDENCIAL|GAFETE|CLAVE) /',
            ];
            foreach ($prueba as $k => $re) if ($m[$k] < 0 && preg_match($re, $t)) { $m[$k] = $i; break; }
        }
        return $m;
    }
    /** Del tipo de equipo escrito en la lista a los tipos de permiso */
    public static function tipoDeEquipo(string $t): string {
        $t = ' ' . trim((string)preg_replace('/[^A-Z0-9 ]+/', ' ', strtoupper(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t) ?: $t))) . ' ';
        if (preg_match('/ (CELULAR|TELEFONO|PHONE|IPHONE|SMARTPHONE|MOVIL) /', $t)) return 'CELULAR';
        if (preg_match('/ (LAPTOP|LAP TOP|COMPUTADORA|COMPUTO|PC|NOTEBOOK|MACBOOK) /', $t)) return 'COMPUTADORA';
        if (preg_match('/ (HERRAMIENTA|TALADRO|ESMERIL|LLAVE|PINZA|MULTIMETRO) /', $t)) return 'HERRAMIENTA';
        return 'OTRO';                                                     // tablet, pantalla, monitor, teclado…
    }
    private static function fechaDeExcel(string $v): ?string {
        $v = trim($v);
        if ($v === '') return null;
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) return self::fechaValida(substr($v, 0, 10));
        if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{2,4})$#', $v, $m)) {
            $a = (int)$m[3] < 100 ? 2000 + (int)$m[3] : (int)$m[3];
            return checkdate((int)$m[2], (int)$m[1], $a) ? sprintf('%04d-%02d-%02d', $a, (int)$m[2], (int)$m[1]) : null;
        }
        if (is_numeric($v) && (float)$v > 30000 && (float)$v < 80000) return gmdate('Y-m-d', (int)round(((float)$v - 25569) * 86400));   // fecha de Excel
        return null;
    }
    private static function sinSerie(string $s): bool {
        return (bool)preg_match('/^(|NA|N A|N\/A|S\/N|SN|SIN NUMERO|SIN SERIE|SIN N|NINGUNO|-+|0)$/', textoEstandar($s));
    }

    public function importarPermisos(array $p, array $archivo, array $usr): array {
        if (!self::puedeAdministrar($usr)) return self::sinPermiso();
        if (empty($archivo['tmp_name']) || (int)($archivo['error'] ?? 1) !== UPLOAD_ERR_OK) return ['status' => 'error', 'message' => 'Elige el archivo de Excel.'];
        $l = LectorTabla::leer((string)$archivo['tmp_name'], (string)($archivo['name'] ?? ''));
        if (!$l['ok']) return ['status' => 'error', 'message' => $l['message']];
        $numeros = array_keys(array_filter($l['renglones'], fn($r) => implode('', $r) !== ''));
        $ren = array_values(array_filter($l['renglones'], fn($r) => implode('', $r) !== ''));
        if (!$ren) return ['status' => 'error', 'message' => 'El archivo está vacío.'];
        $fila = -1; $mapa = null;
        for ($i = 0; $i < min(10, count($ren)); $i++) {
            $m = self::columnasPermisos($ren[$i]);
            if ($m['id'] >= 0 && $m['tipo'] >= 0) { $fila = $i; $mapa = $m; break; }
        }
        if ($fila < 0) return ['status' => 'error', 'message' => 'No encontré las columnas. El Excel debe tener títulos como: ID, Nombre, Tipo de equipo, Marca, Modelo, Número de serie (Vigencia y Referencia, opcionales).'];
        $col = fn(array $r, int $i) => $i >= 0 ? trim((string)preg_replace('/\s+/u', ' ', (string)($r[$i] ?? ''))) : '';

        $personas = [];
        foreach ($this->pdo->query("SELECT clave, codigo, nombre FROM personal")->fetchAll(PDO::FETCH_ASSOC) as $f) $personas[$f['clave']] = $f;
        $tiene = [];                                                       // clave → [series y descripciones que ya tiene]
        foreach ($this->pdo->query("SELECT clave, detalle FROM personal_permisos WHERE activo = 1")->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $tiene[$f['clave']][] = textoEstandar($f['detalle']);
        }
        $nuevos = []; $problemas = []; $ya = 0; $repetidos = 0; $vistos = [];
        foreach (array_slice($ren, $fila + 1) as $n => $r) {
            $num = $numeros[$fila + 1 + $n] ?? $fila + $n + 2;
            $id = self::limpiarId($col($r, $mapa['id']));
            $nombre = $col($r, $mapa['nombre']);
            $tipoTxt = $col($r, $mapa['tipo']);
            if ($id === '' && $tipoTxt === '' && $nombre === '') continue;
            $clave = ControlAccesos::claveId($id);
            if ($id === '' || !preg_match('/\d/', $id)) { $problemas[] = ['renglon' => $num, 'motivo' => 'Sin ID', 'texto' => $nombre]; continue; }
            $p0 = $personas[$clave] ?? null;
            if (!$p0) { $problemas[] = ['renglon' => $num, 'motivo' => 'El ID ' . self::idMostrado($id) . ' no está en la base', 'texto' => $nombre]; continue; }
            if ($nombre !== '' && !self::mismaPersona($p0['nombre'], $nombre)) {
                $problemas[] = ['renglon' => $num, 'motivo' => 'El nombre no coincide con el del ID ' . $p0['codigo'] . ' (' . $p0['nombre'] . ')', 'texto' => $nombre]; continue;
            }
            if ($tipoTxt === '') { $problemas[] = ['renglon' => $num, 'motivo' => 'Sin tipo de equipo', 'texto' => $nombre]; continue; }
            $vigTxt = $col($r, $mapa['vigencia']);
            $vigencia = self::fechaDeExcel($vigTxt);
            if ($vigTxt !== '' && !$vigencia) { $problemas[] = ['renglon' => $num, 'motivo' => 'La vigencia no es una fecha', 'texto' => $vigTxt]; continue; }
            $serie = $col($r, $mapa['serie']);
            $equipo = trim(implode(' ', array_unique(array_filter([$tipoTxt, $col($r, $mapa['marca']), $col($r, $mapa['modelo'])], fn($x) => $x !== '' && !self::sinSerie($x)))));
            $detalle = $equipo . ' · ' . (self::sinSerie($serie) ? 'Sin número de serie' : 'Serie ' . $serie);
            $ref = $col($r, $mapa['referencia']);
            if ($ref !== '') $detalle .= ' · ' . $ref;
            $detalle = mb_substr($detalle, 0, 500);
            // ¿Ya lo tiene? Por número de serie, o por la descripción si no tiene serie
            $huella = self::sinSerie($serie) ? textoEstandar($equipo . ' · Sin número de serie') : 'SERIE ' . textoEstandar($serie);
            if (isset($vistos[$clave . '|' . $huella])) { $repetidos++; continue; }
            $vistos[$clave . '|' . $huella] = 1;
            if (array_filter($tiene[$clave] ?? [], fn($d) => str_contains($d, $huella))) { $ya++; continue; }
            $nuevos[] = ['renglon' => $num, 'clave' => $clave, 'codigo' => $p0['codigo'], 'nombre' => $p0['nombre'], 'tipo' => self::tipoDeEquipo($tipoTxt),
                         'detalle' => $detalle, 'vigencia' => $vigencia];
        }
        $info = ['status' => 'success', 'renglon_titulos' => $numeros[$fila] ?? $fila + 1, 'permisos' => count($nuevos),
                 'personas' => count(array_unique(array_column($nuevos, 'clave'))), 'ya_tienen' => $ya, 'repetidos' => $repetidos,
                 'problemas' => array_slice($problemas, 0, 80), 'total_problemas' => count($problemas),
                 'muestra' => array_map(fn($x) => array_diff_key($x, ['clave' => 1]), array_slice($nuevos, 0, 200))];
        if (($p['modo'] ?? 'revisar') !== 'aplicar') return $info;
        if (!$nuevos) return ['status' => 'error', 'message' => 'No hay permisos nuevos que guardar.'] + $info;
        $ins = $this->pdo->prepare("INSERT INTO personal_permisos (uid, clave, tipos, detalle, vigencia, foto, foto_mini, origen, creado, usuario, usuario_nombre, activo, actualizado)
                                    VALUES (?, ?, ?, ?, ?, '', '', 'excel', NOW(), ?, ?, 1, NOW())");
        $this->pdo->beginTransaction();
        try {
            foreach ($nuevos as $x) $ins->execute(['px-' . bin2hex(random_bytes(12)), $x['clave'], $x['tipo'], $x['detalle'], $x['vigencia'],
                                                    mb_substr((string)($usr['usuario'] ?? ''), 0, 50), mb_substr((string)($usr['nombre'] ?? ''), 0, 150)]);
            $this->pdo->commit();
        } catch (\Throwable $e) { $this->pdo->rollBack(); throw $e; }
        $this->tocarVersion('permisos_version');
        return ['aplicado' => true, 'message' => 'Listo: ' . count($nuevos) . ' permisos guardados para ' . $info['personas'] . ' personas. Los teléfonos de la caseta los reciben en menos de un minuto.'] + $info;
    }

    /**
     * Cargar el Excel (o CSV) del personal. modo=revisar: dice qué columnas encontró y qué va a pasar (nuevos,
     * con cambios, iguales, renglones con problema), sin cambiar nada. modo=aplicar: lo guarda (agrega los nuevos
     * y actualiza a los que cambian; no quita a nadie). mapa (opcional): qué columna es qué, si se corrigió a mano.
     */
    public function importar(array $p, array $archivo, array $usr): array {
        if (!self::puedeAdministrar($usr)) return self::sinPermiso();
        if (empty($archivo['tmp_name']) || (int)($archivo['error'] ?? 1) !== UPLOAD_ERR_OK) return ['status' => 'error', 'message' => 'Elige el archivo de Excel.'];
        if (function_exists('set_time_limit')) @set_time_limit(180);
        $l = LectorTabla::leer((string)$archivo['tmp_name'], (string)($archivo['name'] ?? ''));
        if (!$l['ok']) return ['status' => 'error', 'message' => $l['message']];
        $ren = array_values(array_filter($l['renglones'], fn($r) => implode('', $r) !== ''));
        $numeros = array_values(array_keys(array_filter($l['renglones'], fn($r) => implode('', $r) !== '')));   // renglón de Excel de cada uno
        if (!$ren) return ['status' => 'error', 'message' => 'El archivo está vacío.'];
        // El renglón de títulos: el primero (de los primeros 10) donde se reconoce el ID y el nombre
        $fila = 0; $mapa = self::adivinarColumnas($ren[0]);
        for ($i = 0; $i < min(10, count($ren)); $i++) {
            $m = self::adivinarColumnas($ren[$i]);
            if ($m['id'] >= 0 && ($m['nombre'] >= 0 || $m['paterno'] >= 0)) { $fila = $i; $mapa = $m; break; }
        }
        $titulos = $ren[$fila];
        $pedido = is_array($p['mapa'] ?? null) ? $p['mapa'] : (is_string($p['mapa'] ?? null) ? json_decode((string)$p['mapa'], true) : null);
        if (is_array($pedido)) {
            foreach (array_keys($mapa) as $k) if (isset($pedido[$k]) && is_numeric($pedido[$k]) && (int)$pedido[$k] < max(count($titulos), 200)) $mapa[$k] = (int)$pedido[$k];
            if (isset($pedido['fila']) && is_numeric($pedido['fila'])) { $fila = max(0, min(count($ren) - 1, (int)$pedido['fila'])); $titulos = $ren[$fila]; }
        }
        $base = ['status' => 'success', 'titulos' => $titulos, 'fila_titulos' => $fila, 'renglon_titulos' => $numeros[$fila] ?? $fila + 1, 'mapa' => $mapa];
        if ($mapa['id'] < 0 || ($mapa['nombre'] < 0 && $mapa['paterno'] < 0)) {
            return ['status' => 'error', 'necesita_columnas' => true, 'message' => 'No encontré qué columna es el ID y cuál el nombre. Elígelas abajo.'] + $base;
        }
        $datos = array_slice($ren, $fila + 1);
        if (count($datos) > LectorTabla::MAX_RENGLONES) return ['status' => 'error', 'message' => 'El archivo tiene más de ' . LectorTabla::MAX_RENGLONES . ' renglones.'];

        $col = fn(array $r, int $i) => $i >= 0 ? trim((string)($r[$i] ?? '')) : '';
        $nuevos = []; $problemas = []; $repetidos = 0;
        $choques = [];                                                    // ID repetido con OTRA persona: clave → renglones
        foreach ($datos as $n => $r) {
            $num = $numeros[$fila + 1 + $n] ?? $fila + $n + 2;            // renglón como lo ve Excel
            $id = self::limpiarId($col($r, $mapa['id']));
            $nombre = trim(implode(' ', array_filter([$col($r, $mapa['nombre']), $col($r, $mapa['paterno']), $col($r, $mapa['materno'])])));
            $nombre = mb_substr(textoEstandar($nombre), 0, 150);
            if ($id === '' && $nombre === '') continue;
            if ($id === '') { $problemas[] = ['renglon' => $num, 'motivo' => 'Sin ID', 'texto' => $nombre]; continue; }
            if ($nombre === '') { $problemas[] = ['renglon' => $num, 'motivo' => 'Sin nombre', 'texto' => $id]; continue; }
            // El ID de la credencial es un número: un nombre o un texto en la columna del ID es un error del archivo
            if (!preg_match('/\d/', $id)) {
                $problemas[] = ['renglon' => $num, 'motivo' => 'El ID no es un número', 'texto' => $col($r, $mapa['id']) . ' · ' . $nombre];
                continue;
            }
            $clave = ControlAccesos::claveId($id);
            if (isset($nuevos[$clave])) {
                // El mismo ID otra vez: si es la misma persona (comparten alguna palabra del nombre) queda el último
                // renglón; si es otra persona, no se sabe de quién es el ID y no se carga ninguno de los dos
                if (!self::mismaPersona($nuevos[$clave]['nombre'], $nombre)) {
                    $choques[$clave] = ($choques[$clave] ?? [['renglon' => $nuevos[$clave]['renglon'], 'nombre' => $nuevos[$clave]['nombre']]]);
                    $choques[$clave][] = ['renglon' => $num, 'nombre' => $nombre];
                    continue;
                }
                $repetidos++;
            }
            $nuevos[$clave] = ['clave' => $clave, 'codigo' => self::idMostrado($id), 'nombre' => $nombre,
                               'empresa' => mb_substr(textoEstandar($col($r, $mapa['empresa'])), 0, 150),
                               'cargo' => mb_substr(textoEstandar($col($r, $mapa['cargo'])), 0, 150), 'renglon' => $num];
        }
        foreach ($choques as $clave => $filas) {
            $codigo = $nuevos[$clave]['codigo'];
            unset($nuevos[$clave]);
            foreach ($filas as $f) {
                $otros = implode(' y ', array_map(fn($o) => (string)$o['renglon'], array_filter($filas, fn($o) => $o['renglon'] !== $f['renglon'])));
                $problemas[] = ['renglon' => $f['renglon'], 'motivo' => 'ID ' . $codigo . ' repetido con otra persona (renglón ' . $otros . ')', 'texto' => $f['nombre']];
            }
        }
        usort($problemas, fn($a, $b) => $a['renglon'] <=> $b['renglon']);
        // Qué pasaría con cada uno
        $existen = [];
        foreach ($this->pdo->query("SELECT clave, codigo, nombre, empresa, cargo FROM personal")->fetchAll(PDO::FETCH_ASSOC) as $f) $existen[$f['clave']] = $f;
        $cuenta = ['nuevos' => 0, 'cambian' => 0, 'iguales' => 0];
        $cambios = [];
        foreach ($nuevos as $c => $x) {
            $e = $existen[$c] ?? null;
            if (!$e) { $cuenta['nuevos']++; continue; }
            $igual = $e['nombre'] === $x['nombre'] && $e['codigo'] === $x['codigo']
                  && ($x['empresa'] === '' || $e['empresa'] === $x['empresa']) && ($x['cargo'] === '' || $e['cargo'] === $x['cargo']);
            if ($igual) { $cuenta['iguales']++; continue; }
            $cuenta['cambian']++;
            if (count($cambios) < 30) {
                $que = [];
                if ($e['codigo'] !== $x['codigo']) $que[] = 'ID: ' . $e['codigo'] . ' → ' . $x['codigo'];
                foreach (['nombre' => 'Nombre', 'empresa' => 'Empresa', 'cargo' => 'Cargo'] as $k => $t) {
                    if ($x[$k] !== '' && $e[$k] !== $x[$k]) $que[] = $t . ': ' . ($e[$k] !== '' ? $e[$k] : '(vacío)') . ' → ' . $x[$k];
                }
                $cambios[] = ['codigo' => $x['codigo'], 'nombre' => $x['nombre'], 'que' => implode(' · ', $que)];
            }
        }
        $info = $base + ['renglones' => count($nuevos), 'repetidos' => $repetidos, 'problemas' => array_slice($problemas, 0, 50),
                         'total_problemas' => count($problemas), 'cuenta' => $cuenta, 'cambios' => $cambios,
                         'muestra' => array_slice(array_values(array_map(fn($x) => array_diff_key($x, ['clave' => 1]), $nuevos)), 0, 8),
                         'en_base' => count($existen)];
        if (($p['modo'] ?? 'revisar') !== 'aplicar') return $info + ['aplicado' => false];
        if (!$nuevos) return ['status' => 'error', 'message' => 'No hay renglones con ID y nombre para guardar.'] + $info;

        $quien = mb_substr((string)($usr['nombre'] ?? $usr['usuario'] ?? ''), 0, 150);
        $ins = $this->pdo->prepare(
            "INSERT INTO personal (clave, codigo, nombre, empresa, cargo, creado, actualizado, actualizado_por) VALUES (?, ?, ?, ?, ?, NOW(), NOW(), ?)
             ON DUPLICATE KEY UPDATE codigo = VALUES(codigo), nombre = VALUES(nombre),
               empresa = IF(VALUES(empresa) = '', empresa, VALUES(empresa)), cargo = IF(VALUES(cargo) = '', cargo, VALUES(cargo)),
               actualizado = NOW(), actualizado_por = VALUES(actualizado_por)");
        $this->pdo->beginTransaction();
        try {
            foreach ($nuevos as $c => $x) {
                $e = $existen[$c] ?? null;
                if ($e && $e['nombre'] === $x['nombre'] && $e['codigo'] === $x['codigo']
                    && ($x['empresa'] === '' || $e['empresa'] === $x['empresa']) && ($x['cargo'] === '' || $e['cargo'] === $x['cargo'])) continue;
                $ins->execute([$c, $x['codigo'], $x['nombre'], $x['empresa'], $x['cargo'], $quien]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) { $this->pdo->rollBack(); throw $e; }
        $this->tocarVersion();
        return ['aplicado' => true, 'message' => 'Base actualizada: ' . $cuenta['nuevos'] . ' nuevos, ' . $cuenta['cambian'] . ' con cambios y '
                . $cuenta['iguales'] . ' sin cambios.' . (count($problemas) ? ' ' . count($problemas) . (count($problemas) === 1 ? ' renglón no se cargó' : ' renglones no se cargaron') . ' (sin ID o sin nombre).' : '')] + $info;
    }

    /** La base completa con sus permisos, en Excel. */
    public function excel(array $usr): array {
        if (!self::puedeAdministrar($usr)) return self::sinPermiso();
        $filas = $this->pdo->query("SELECT * FROM personal ORDER BY nombre, clave")->fetchAll(PDO::FETCH_ASSOC);
        $perm = $this->permisosDe(array_column($filas, 'clave'), $usr);
        $todos = $this->pdo->query("SELECT * FROM personal_permisos WHERE activo = 1 ORDER BY creado DESC")->fetchAll(PDO::FETCH_ASSOC);
        $nombres = array_column($filas, 'nombre', 'clave');
        $titulo = ['Control de accesos · Base del personal', 'Generada el ' . date('d/m/Y H:i') . ' por ' . ($usr['nombre'] ?? $usr['usuario'] ?? '')];
        $x = new XlsxSimple();
        $permTxt = fn(string $c): string => implode(', ', array_values(array_unique(array_merge([], ...array_map(fn($q) => $q['nombres'], $perm[$c] ?? [])))));
        $x->hoja('Personal', ['ID', 'Nombre', 'Empresa', 'Cargo', 'Permisos'],
            array_map(fn($f) => [$f['codigo'], $f['nombre'], $f['empresa'], $f['cargo'], $permTxt((string)$f['clave'])], $filas),
            [9, 38, 30, 24, 40], ['texto', 'texto', 'texto', 'texto', 'texto'], $titulo);
        $x->hoja('Permisos', ['ID', 'Nombre', 'Permiso', 'Datos del equipo o herramienta', 'Vigente hasta', 'Agregado', 'Por', 'Desde'],
            array_map(function ($f) use ($nombres) {
                $q = $this->filaPermiso($f, null);
                return [self::idMostrado((string)$f['clave']), $nombres[$f['clave']] ?? 'No está en la base', implode(', ', $q['nombres']), $q['detalle'],
                        $q['vigencia'], $q['creado'], $q['por'], $q['origen'] === 'caseta' ? 'Caseta' : ($q['origen'] === 'excel' ? 'Lista de Excel' : 'Administración')];
            }, $todos),
            [9, 34, 30, 40, 13, 17, 22, 14], ['texto', 'texto', 'texto', 'texto', 'fecha', 'fechahora', 'texto', 'texto'], $titulo);
        $archivo = 'PERSONAL_' . date('Y-m-d') . '_' . bin2hex(random_bytes(6)) . '.xlsx';
        if (!$x->guardar(Tablero::carpetaReportes() . $archivo)) return ['status' => 'error', 'message' => 'No se pudo guardar el Excel en el servidor.'];
        return ['status' => 'success', 'archivo' => $archivo, 'url' => 'descargar.php?tipo=personal&f=' . rawurlencode($archivo), 'personas' => count($filas)];
    }
    public static function rutaExcel(string $archivo): ?string {
        if (!preg_match('/^PERSONAL_\d{4}-\d{2}-\d{2}_[a-f0-9]{12}\.xlsx$/', $archivo)) return null;
        $abs = ControlAccesos::carpeta() . 'reportes/' . $archivo;
        return is_file($abs) ? $abs : null;
    }

    private static function sinPermiso(): array {
        return ['status' => 'error', 'message' => 'Sólo Administración y el administrador de la planta manejan la base del personal.'];
    }
}
