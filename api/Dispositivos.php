<?php
/* ══════════════════════════════════════════════════════════════
   AVBA — Entrar con la seguridad del propio dispositivo

   Después de entrar una vez con contraseña, cada persona puede activar en
   su dispositivo el acceso con lo que ese dispositivo ya trae para
   identificar a su dueño:

     · En el navegador —computadora, tablet o celular— con una llave de
       acceso (WebAuthn / passkey): Windows Hello, Touch ID del Mac, huella
       o rostro de Android, Face ID o Touch ID de iPhone y iPad. La llave
       privada nunca sale del dispositivo; aquí sólo se guarda la pública y
       se comprueba la firma de cada acceso.

     · En la app de Android e iOS con el sensor del teléfono: la app recibe
       una credencial propia del dispositivo y la guarda en el llavero
       protegido por la biometría (Keychain / Keystore). Sin huella o
       rostro no se puede leer. Aquí se guarda sólo su huella SHA-256.

   La contraseña sigue funcionando siempre. Un acceso de dispositivo se puede
   quitar desde Administración, y deja de valer si la cuenta se desactiva.
   ══════════════════════════════════════════════════════════════ */

class Dispositivos {
    private PDO $pdo;
    private const RETO_TTL = 300;          // segundos para completar el gesto
    private const RP_NOMBRE = 'AVBA Certificaciones';

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /* ── Tablas ─────────────────────────────────────────────────────────── */
    public static function asegurarTablas(PDO $pdo): void {
        static $hecho = [];
        $k = spl_object_id($pdo);
        if (isset($hecho[$k])) return;
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS accesos_dispositivo (
               id            INT AUTO_INCREMENT PRIMARY KEY,
               usuario_id    INT          NOT NULL,
               tipo          VARCHAR(10)  NOT NULL,           -- passkey | app
               credencial_id VARCHAR(255) NOT NULL,
               llave_publica TEXT         NULL,               -- passkey: PEM
               algoritmo     INT          NULL,               -- passkey: COSE (-7 ES256, -257 RS256)
               contador      BIGINT       NOT NULL DEFAULT 0, -- passkey: signCount
               usuario_handle VARCHAR(64) NULL,
               secreto_hash  CHAR(64)     NULL,               -- app: SHA-256 del secreto
               nombre        VARCHAR(120) NOT NULL DEFAULT '',
               plataforma    VARCHAR(30)  NOT NULL DEFAULT '',
               creado        DATETIME     NOT NULL,
               ultimo_uso    DATETIME     NULL,
               activo        TINYINT(1)   NOT NULL DEFAULT 1,
               UNIQUE KEY uk_credencial (credencial_id),
               KEY idx_usuario (usuario_id)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS retos_dispositivo (
               reto       VARCHAR(64) NOT NULL PRIMARY KEY,
               tipo       VARCHAR(10) NOT NULL,               -- registro | acceso
               usuario_id INT         NULL,
               expira     DATETIME    NOT NULL
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $hecho[$k] = true;
    }

    /* ── Utilidades ─────────────────────────────────────────────────────── */
    public static function b64u(string $bin): string {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
    public static function deB64u(string $txt): string {
        $txt = strtr(trim($txt), '-_', '+/');
        $r = base64_decode($txt . str_repeat('=', (4 - strlen($txt) % 4) % 4), true);
        if ($r === false) throw new \RuntimeException('Dato mal codificado.');
        return $r;
    }

    /** El dominio de las llaves: el host por el que se entra al sistema. */
    public static function rpId(): string {
        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        return preg_replace('/:\d+$/', '', $host);
    }

    private static function origenValido(string $origen, string $rpId): bool {
        $u = parse_url($origen);
        if (!$u || empty($u['host']) || strtolower($u['host']) !== $rpId) return false;
        $esquema = strtolower($u['scheme'] ?? '');
        if ($esquema === 'https') return true;
        // Sólo para pruebas en la propia computadora
        return $esquema === 'http' && $rpId === 'localhost';
    }

    private function nuevoReto(string $tipo, ?int $usuarioId): string {
        self::asegurarTablas($this->pdo);
        $this->pdo->prepare("DELETE FROM retos_dispositivo WHERE expira < ?")->execute([date('Y-m-d H:i:s')]);
        $reto = self::b64u(random_bytes(32));
        $this->pdo->prepare("INSERT INTO retos_dispositivo (reto, tipo, usuario_id, expira) VALUES (?, ?, ?, ?)")
                  ->execute([$reto, $tipo, $usuarioId, date('Y-m-d H:i:s', time() + self::RETO_TTL)]);
        return $reto;
    }

    /** Cada reto sirve una sola vez: se borra al usarlo, valga o no. */
    private function consumirReto(string $reto, string $tipo, ?int $usuarioId): bool {
        self::asegurarTablas($this->pdo);
        $st = $this->pdo->prepare("SELECT tipo, usuario_id, expira FROM retos_dispositivo WHERE reto = ?");
        $st->execute([$reto]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return false;
        $this->pdo->prepare("DELETE FROM retos_dispositivo WHERE reto = ?")->execute([$reto]);
        if ($r['tipo'] !== $tipo || strtotime((string)$r['expira']) < time()) return false;
        if ($usuarioId !== null && (int)$r['usuario_id'] !== $usuarioId) return false;
        return true;
    }

    private static function nombreLimpio($v, string $defecto = 'Dispositivo'): string {
        $t = trim(preg_replace('/\s+/', ' ', (string)$v));
        $t = mb_substr(strip_tags($t), 0, 120);
        return $t !== '' ? $t : $defecto;
    }

    private static function handle(array $usr): string {
        return self::b64u(substr(hash('sha256', 'avba|' . ($usr['id'] ?? '') . '|' . strtolower((string)($usr['usuario'] ?? '')), true), 0, 16));
    }

    /* ── Navegador: alta de la llave de acceso ──────────────────────────── */
    public function opcionesRegistro(array $usr): array {
        self::asegurarTablas($this->pdo);
        $st = $this->pdo->prepare("SELECT credencial_id FROM accesos_dispositivo WHERE usuario_id = ? AND tipo = 'passkey' AND activo = 1");
        $st->execute([(int)$usr['id']]);
        $excluir = array_map(fn($c) => ['type' => 'public-key', 'id' => $c], $st->fetchAll(PDO::FETCH_COLUMN));

        return ['status' => 'success', 'publicKey' => [
            'challenge' => $this->nuevoReto('registro', (int)$usr['id']),
            'rp'        => ['id' => self::rpId(), 'name' => self::RP_NOMBRE],
            'user'      => [
                'id'          => self::handle($usr),
                'name'        => (string)$usr['usuario'],
                'displayName' => (string)($usr['nombre'] ?: $usr['usuario']),
            ],
            // ES256 (Apple, Android, la mayoría) y RS256 (Windows Hello)
            'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7], ['type' => 'public-key', 'alg' => -257]],
            'timeout'     => 120000,
            'attestation' => 'none',
            // La seguridad del propio equipo (huella, rostro, Windows Hello),
            // y siempre con verificación de la persona, no sólo presencia.
            'authenticatorSelection' => [
                'authenticatorAttachment' => 'platform',
                'residentKey'             => 'preferred',
                'requireResidentKey'      => false,
                'userVerification'        => 'required',
            ],
            'hints' => ['client-device'],
            'excludeCredentials' => $excluir,
        ]];
    }

    public function registrar(array $usr, array $p): array {
        try {
            $cdRaw = self::deB64u((string)($p['clientDataJSON'] ?? ''));
            $attRaw = self::deB64u((string)($p['attestationObject'] ?? ''));
            if (strlen($cdRaw) > 4096 || strlen($attRaw) > 65536) throw new \RuntimeException('Respuesta demasiado grande.');
            $cd = json_decode($cdRaw, true);
            if (!is_array($cd) || ($cd['type'] ?? '') !== 'webauthn.create') throw new \RuntimeException('Respuesta inválida.');
            if (!$this->consumirReto((string)($cd['challenge'] ?? ''), 'registro', (int)$usr['id'])) {
                throw new \RuntimeException('La solicitud venció. Vuelve a intentarlo.');
            }
            $rpId = self::rpId();
            if (!self::origenValido((string)($cd['origin'] ?? ''), $rpId)) throw new \RuntimeException('Origen no permitido.');

            $att = Cbor::decodificar($attRaw);
            $auth = is_array($att) ? ($att['authData'] ?? null) : null;
            if (!is_string($auth) || strlen($auth) < 37) throw new \RuntimeException('Respuesta incompleta.');
            if (!hash_equals(hash('sha256', $rpId, true), substr($auth, 0, 32))) throw new \RuntimeException('La llave es de otro sitio.');
            $flags = ord($auth[32]);
            if (!($flags & 0x01) || !($flags & 0x04)) throw new \RuntimeException('El dispositivo no verificó a la persona.');
            if (!($flags & 0x40)) throw new \RuntimeException('Respuesta sin llave.');
            $contador = unpack('N', substr($auth, 33, 4))[1];

            $pos = 37 + 16;                                     // + AAGUID
            $largo = unpack('n', substr($auth, $pos, 2))[1];
            $pos += 2;
            $credId = substr($auth, $pos, $largo);
            if (strlen($credId) !== $largo || $largo < 16 || $largo > 1023) throw new \RuntimeException('Identificador de llave inválido.');
            $pos += $largo;
            $cose = Cbor::decodificar($auth, $pos);
            [$pem, $alg] = self::coseAPem($cose);

            $credB64 = self::b64u($credId);
            if (!empty($p['id']) && !hash_equals($credB64, self::b64u(self::deB64u((string)$p['id'])))) {
                throw new \RuntimeException('Identificador de llave inconsistente.');
            }

            self::asegurarTablas($this->pdo);
            $ya = $this->pdo->prepare("SELECT id FROM accesos_dispositivo WHERE credencial_id = ?");
            $ya->execute([$credB64]);
            if ($ya->fetchColumn()) throw new \RuntimeException('Esta llave ya estaba registrada.');

            $nombre = self::nombreLimpio($p['nombre'] ?? '', 'Navegador');
            $this->pdo->prepare(
                "INSERT INTO accesos_dispositivo
                   (usuario_id, tipo, credencial_id, llave_publica, algoritmo, contador, usuario_handle, nombre, plataforma, creado)
                 VALUES (?, 'passkey', ?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute([(int)$usr['id'], $credB64, $pem, $alg, $contador, self::handle($usr), $nombre,
                        mb_substr((string)($p['plataforma'] ?? 'web'), 0, 30), date('Y-m-d H:i:s')]);

            return ['status' => 'success', 'credencial_id' => $credB64,
                    'message' => 'Listo: la próxima vez puedes entrar sin escribir tu contraseña.'];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => self::mensaje($e)];
        }
    }

    /* ── Navegador: entrar ──────────────────────────────────────────────── */
    public function opcionesAcceso(array $p): array {
        // Los identificadores los manda el propio navegador (los que dio de alta
        // ahí). Sin ellos, el navegador ofrece las llaves que tenga para este sitio.
        $permitir = [];
        foreach (array_slice((array)($p['credenciales'] ?? []), 0, 10) as $c) {
            if (is_string($c) && preg_match('/^[A-Za-z0-9_-]{16,400}$/', $c)) $permitir[] = ['type' => 'public-key', 'id' => $c];
        }
        return ['status' => 'success', 'publicKey' => [
            'challenge'        => $this->nuevoReto('acceso', null),
            'rpId'             => self::rpId(),
            'timeout'          => 120000,
            'userVerification' => 'required',
            'allowCredentials' => $permitir,
        ]];
    }

    /**
     * Comprueba la firma del dispositivo. El reto se consume aquí (en la base de
     * la petición, la misma que lo emitió) y la llave se busca en todas las
     * divisiones: la persona todavía no ha dicho de cuál es.
     * Devuelve ['pdo' => ..., 'usuario_id' => ..., 'division' => ...] o error.
     */
    public function verificarAcceso(array $p, array $conexiones): array {
        try {
            $credB64 = self::b64u(self::deB64u((string)($p['id'] ?? '')));
            $cdRaw   = self::deB64u((string)($p['clientDataJSON'] ?? ''));
            $authRaw = self::deB64u((string)($p['authenticatorData'] ?? ''));
            $firma   = self::deB64u((string)($p['signature'] ?? ''));
            if (strlen($cdRaw) > 4096 || strlen($authRaw) > 4096 || strlen($firma) > 1024 || strlen($authRaw) < 37) {
                throw new \RuntimeException('Respuesta inválida.');
            }
            $cd = json_decode($cdRaw, true);
            if (!is_array($cd) || ($cd['type'] ?? '') !== 'webauthn.get') throw new \RuntimeException('Respuesta inválida.');
            if (!$this->consumirReto((string)($cd['challenge'] ?? ''), 'acceso', null)) {
                throw new \RuntimeException('La solicitud venció. Vuelve a intentarlo.');
            }
            $rpId = self::rpId();
            if (!self::origenValido((string)($cd['origin'] ?? ''), $rpId)) throw new \RuntimeException('Origen no permitido.');
            if (!hash_equals(hash('sha256', $rpId, true), substr($authRaw, 0, 32))) throw new \RuntimeException('La llave es de otro sitio.');
            $flags = ord($authRaw[32]);
            if (!($flags & 0x01) || !($flags & 0x04)) throw new \RuntimeException('El dispositivo no verificó a la persona.');
            $contador = unpack('N', substr($authRaw, 33, 4))[1];

            [$pdo, $div, $c] = self::buscarCredencial($conexiones, $credB64, 'passkey');
            if (!$c) {
                return ['status' => 'error', 'revocado' => true,
                        'message' => 'Este dispositivo ya no tiene acceso. Entra con tu contraseña y vuelve a activarlo.'];
            }

            if (!empty($p['userHandle']) && !empty($c['usuario_handle'])
                && !hash_equals((string)$c['usuario_handle'], self::b64u(self::deB64u((string)$p['userHandle'])))) {
                throw new \RuntimeException('La llave no corresponde a la cuenta.');
            }
            $ok = openssl_verify($authRaw . hash('sha256', $cdRaw, true), $firma, (string)$c['llave_publica'], OPENSSL_ALGO_SHA256);
            if ($ok !== 1) throw new \RuntimeException('No se pudo comprobar la identidad.');

            // Contador: si el dispositivo lo lleva y retrocede, la llave pudo copiarse.
            if ($contador > 0 || (int)$c['contador'] > 0) {
                if ($contador <= (int)$c['contador']) throw new \RuntimeException('Llave rechazada por seguridad. Entra con tu contraseña.');
            }
            $pdo->prepare("UPDATE accesos_dispositivo SET contador = ?, ultimo_uso = ? WHERE id = ?")
                ->execute([$contador, date('Y-m-d H:i:s'), (int)$c['id']]);

            return ['status' => 'success', 'pdo' => $pdo, 'division' => $div, 'usuario_id' => (int)$c['usuario_id'],
                    'dispositivo' => (string)$c['nombre']];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => self::mensaje($e)];
        }
    }

    /* ── App: credencial del dispositivo en el llavero ──────────────────── */
    public function registrarApp(array $usr, array $p): array {
        self::asegurarTablas($this->pdo);
        $credId  = self::b64u(random_bytes(18));
        $secreto = self::b64u(random_bytes(32));
        $plat = strtolower(preg_replace('/[^a-z]/i', '', (string)($p['plataforma'] ?? '')));
        $plat = in_array($plat, ['ios', 'android'], true) ? $plat : 'app';
        $this->pdo->prepare(
            "INSERT INTO accesos_dispositivo (usuario_id, tipo, credencial_id, secreto_hash, nombre, plataforma, creado)
             VALUES (?, 'app', ?, ?, ?, ?, ?)"
        )->execute([(int)$usr['id'], $credId, hash('sha256', $secreto), self::nombreLimpio($p['nombre'] ?? '', 'Teléfono'),
                    $plat, date('Y-m-d H:i:s')]);
        return ['status' => 'success', 'credencial_id' => $credId, 'secreto' => $secreto];
    }

    public static function verificarApp(array $p, array $conexiones): array {
        $credId  = (string)($p['credencial_id'] ?? '');
        $secreto = (string)($p['secreto'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $credId) || !preg_match('/^[A-Za-z0-9_-]{32,100}$/', $secreto)) {
            return ['status' => 'error', 'message' => 'Acceso inválido.'];
        }
        [$pdo, $div, $c] = self::buscarCredencial($conexiones, $credId, 'app');
        if (!$c || !hash_equals((string)$c['secreto_hash'], hash('sha256', $secreto))) {
            return ['status' => 'error', 'revocado' => true,
                    'message' => 'Este teléfono ya no tiene acceso con huella o rostro. Entra con tu contraseña y vuelve a activarlo.'];
        }
        $pdo->prepare("UPDATE accesos_dispositivo SET ultimo_uso = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), (int)$c['id']]);
        return ['status' => 'success', 'pdo' => $pdo, 'division' => $div, 'usuario_id' => (int)$c['usuario_id'],
                'dispositivo' => (string)$c['nombre']];
    }

    /** Quien tiene el secreto puede dar de baja su propio teléfono (al desactivarlo en la app). */
    public static function olvidarApp(array $p, array $conexiones): array {
        $credId = (string)($p['credencial_id'] ?? '');
        $secreto = (string)($p['secreto'] ?? '');
        if ($credId === '' || $secreto === '') return ['status' => 'success'];
        [$pdo, , $c] = self::buscarCredencial($conexiones, $credId, 'app');
        if ($c && hash_equals((string)$c['secreto_hash'], hash('sha256', $secreto))) {
            $pdo->prepare("UPDATE accesos_dispositivo SET activo = 0 WHERE id = ?")->execute([(int)$c['id']]);
        }
        return ['status' => 'success'];
    }

    private static function buscarCredencial(array $conexiones, string $credId, string $tipo): array {
        foreach ($conexiones as $div => $pdo) {
            try {
                self::asegurarTablas($pdo);
                $st = $pdo->prepare(
                    "SELECT d.* FROM accesos_dispositivo d INNER JOIN usuarios u ON u.id = d.usuario_id
                      WHERE d.credencial_id = ? AND d.tipo = ? AND d.activo = 1 AND u.activo = 1 LIMIT 1"
                );
                $st->execute([$credId, $tipo]);
                $c = $st->fetch(PDO::FETCH_ASSOC);
                if ($c) return [$pdo, $div, $c];
            } catch (\Throwable $e) {
                error_log("[Dispositivos] división '$div': " . $e->getMessage());
            }
        }
        return [null, null, null];
    }

    /* ── Administración ─────────────────────────────────────────────────── */
    public function listar(int $usuarioId): array {
        self::asegurarTablas($this->pdo);
        $st = $this->pdo->prepare(
            "SELECT id, tipo, nombre, plataforma, creado, ultimo_uso FROM accesos_dispositivo
              WHERE usuario_id = ? AND activo = 1 ORDER BY COALESCE(ultimo_uso, creado) DESC"
        );
        $st->execute([$usuarioId]);
        return ['status' => 'success', 'dispositivos' => $st->fetchAll(PDO::FETCH_ASSOC)];
    }

    public function quitar(int $usuarioId, int $id = 0): array {
        self::asegurarTablas($this->pdo);
        if ($id > 0) {
            $this->pdo->prepare("UPDATE accesos_dispositivo SET activo = 0 WHERE id = ? AND usuario_id = ?")->execute([$id, $usuarioId]);
        } else {
            $this->pdo->prepare("UPDATE accesos_dispositivo SET activo = 0 WHERE usuario_id = ?")->execute([$usuarioId]);
        }
        return ['status' => 'success', 'message' => $id > 0 ? 'Acceso del dispositivo retirado.' : 'Se retiraron todos los accesos de dispositivo de la cuenta.'];
    }

    /** Cuántos accesos de dispositivo tiene cada cuenta (para la lista de usuarios). */
    public function conteos(): array {
        try {
            self::asegurarTablas($this->pdo);
            $r = [];
            foreach ($this->pdo->query("SELECT usuario_id, COUNT(*) n FROM accesos_dispositivo WHERE activo = 1 GROUP BY usuario_id") as $f) {
                $r[(int)$f['usuario_id']] = (int)$f['n'];
            }
            return $r;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /* ── Llave COSE → PEM ───────────────────────────────────────────────── */
    public static function coseAPem($k): array {
        if (!is_array($k)) throw new \RuntimeException('Llave inválida.');
        $kty = $k[1] ?? null;
        $alg = $k[3] ?? null;
        if ($kty === 2 && $alg === -7) {
            $x = $k[-2] ?? ''; $y = $k[-3] ?? '';
            if (($k[-1] ?? null) !== 1 || !is_string($x) || !is_string($y) || strlen($x) !== 32 || strlen($y) !== 32) {
                throw new \RuntimeException('Llave EC no soportada.');
            }
            $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $x . $y;
        } elseif ($kty === 3 && $alg === -257) {
            $n = $k[-1] ?? ''; $e = $k[-2] ?? '';
            if (!is_string($n) || !is_string($e) || strlen($n) < 256) throw new \RuntimeException('Llave RSA no soportada.');
            $rsa = self::der(0x30, self::derEntero($n) . self::derEntero($e));
            $der = self::der(0x30, self::der(0x30, hex2bin('06092a864886f70d0101010500')) . self::der(0x03, "\x00" . $rsa));
        } else {
            throw new \RuntimeException('Tipo de llave no soportado por el sistema.');
        }
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
        if (!openssl_pkey_get_public($pem)) throw new \RuntimeException('Llave inválida.');
        return [$pem, $alg];
    }
    private static function derLargo(int $l): string {
        if ($l < 128) return chr($l);
        $s = ltrim(pack('N', $l), "\0");
        return chr(0x80 | strlen($s)) . $s;
    }
    private static function der(int $tag, string $v): string { return chr($tag) . self::derLargo(strlen($v)) . $v; }
    private static function derEntero(string $b): string {
        $b = ltrim($b, "\0");
        if ($b === '') $b = "\0";
        if (ord($b[0]) & 0x80) $b = "\0" . $b;
        return self::der(0x02, $b);
    }

    private static function mensaje(\Throwable $e): string {
        if ($e instanceof \RuntimeException) return $e->getMessage();
        error_log('[Dispositivos] ' . $e->getMessage());
        return 'No se pudo completar el acceso con el dispositivo.';
    }
}

/* ══════════════════════════════════════════════════════════════
   Lector CBOR mínimo (RFC 8949): lo justo para WebAuthn — enteros, cadenas
   de bytes y de texto, arreglos, mapas, etiquetas y simples. Sin longitudes
   indefinidas, que WebAuthn no usa.
   ══════════════════════════════════════════════════════════════ */
final class Cbor {
    private string $d;
    private int $p;
    private int $n;
    private int $prof = 0;

    private function __construct(string $d, int $inicio) { $this->d = $d; $this->p = $inicio; $this->n = strlen($d); }

    /** @param int $pos posición de inicio; al volver, dónde terminó el elemento */
    public static function decodificar(string $datos, int &$pos = 0) {
        $c = new self($datos, $pos);
        $v = $c->elemento();
        $pos = $c->p;
        return $v;
    }

    private function byte(): int {
        if ($this->p >= $this->n) throw new \RuntimeException('Dato CBOR incompleto.');
        return ord($this->d[$this->p++]);
    }
    private function bytes(int $k): string {
        if ($k < 0 || $this->p + $k > $this->n) throw new \RuntimeException('Dato CBOR incompleto.');
        $s = substr($this->d, $this->p, $k);
        $this->p += $k;
        return $s;
    }
    private function largo(int $info): int {
        if ($info < 24) return $info;
        if ($info === 24) return $this->byte();
        if ($info === 25) return unpack('n', $this->bytes(2))[1];
        if ($info === 26) return unpack('N', $this->bytes(4))[1];
        if ($info === 27) {
            $v = unpack('J', $this->bytes(8))[1];
            if ($v < 0) throw new \RuntimeException('Dato CBOR fuera de rango.');
            return $v;
        }
        throw new \RuntimeException('Dato CBOR no soportado.');
    }
    private function elemento() {
        if (++$this->prof > 16) throw new \RuntimeException('Dato CBOR demasiado anidado.');
        $b = $this->byte();
        $tipo = $b >> 5;
        $info = $b & 0x1f;
        switch ($tipo) {
            case 0: $v = $this->largo($info); break;
            case 1: $v = -1 - $this->largo($info); break;
            case 2:
            case 3: $v = $this->bytes($this->largo($info)); break;
            case 4:
                $k = $this->largo($info);
                if ($k > 1000) throw new \RuntimeException('Dato CBOR demasiado grande.');
                $v = [];
                for ($i = 0; $i < $k; $i++) $v[] = $this->elemento();
                break;
            case 5:
                $k = $this->largo($info);
                if ($k > 1000) throw new \RuntimeException('Dato CBOR demasiado grande.');
                $v = [];
                for ($i = 0; $i < $k; $i++) {
                    $clave = $this->elemento();
                    if (!is_int($clave) && !is_string($clave)) throw new \RuntimeException('Clave CBOR no soportada.');
                    $v[$clave] = $this->elemento();
                }
                break;
            case 6: $this->largo($info); $v = $this->elemento(); break;
            default:
                if ($info === 20) $v = false;
                elseif ($info === 21) $v = true;
                elseif ($info === 22 || $info === 23) $v = null;
                elseif ($info === 25) { $this->bytes(2); $v = null; }
                elseif ($info === 26) { $v = unpack('G', $this->bytes(4))[1]; }
                elseif ($info === 27) { $v = unpack('E', $this->bytes(8))[1]; }
                elseif ($info < 24) $v = $info;
                elseif ($info === 24) $v = $this->byte();
                else throw new \RuntimeException('Dato CBOR no soportado.');
        }
        $this->prof--;
        return $v;
    }
}
