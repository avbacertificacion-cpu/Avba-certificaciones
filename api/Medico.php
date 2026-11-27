<?php
/**
 * Servicio médico: toma de tensión arterial.
 *
 * El perfil MEDICO (una cuenta por médico, enfermera o paramédico) identifica a la persona
 * escaneando su credencial o escribiendo su ID; con la base del personal se llenan solos el
 * nombre, la compañía y el cargo. Registra la tensión arterial (sistólica/diastólica), la
 * frecuencia cardiaca y observaciones; la hora de la toma y quién la tomó se guardan solos.
 * Apto / No apto según los límites de api/medico.json (NOM-030: 100/60 a 139/89 mmHg); quien
 * toma puede marcar otro resultado si escribe por qué. Varias tomas de la misma persona el
 * mismo día se numeran (1.ª, 2.ª…); cuenta el resultado de la última.
 *
 * Funciona sin señal: la pantalla (medico.html) guarda las tomas en el teléfono y las manda
 * después; cada una lleva su uid y no se duplica.
 *
 * Tablero (ADMIN, PLANTA y CLIENTE): cifras, tomas por día, clasificación, compañías, no aptos
 * y la bitácora, con Excel y PDF (como la bitácora en papel). Los periodos omitidos (días de
 * prueba, Omitidos.php) tampoco cuentan aquí.
 */
final class Medico {
    private PDO $pdo;
    public const ROL = 'MEDICO';
    /** Clasificación de la NOM-030-SSA2-2009 (abajo del mínimo permitido: presión baja), en orden. */
    public const CATEGORIAS = [
        'BAJA'   => 'Presión baja',
        'OPTIMA' => 'Óptima',
        'NORMAL' => 'Normal',
        'ALTA'   => 'Normal alta',
        'HTA1'   => 'Hipertensión grado 1',
        'HTA2'   => 'Hipertensión grado 2',
        'HTA3'   => 'Hipertensión grado 3',
    ];
    private const POR_DEFECTO = ['titulo' => 'Bitácora de toma de tensión arterial', 'subtitulo' => '', 'norma' => 'NOM-030-SSA2-2009',
                                 'maxSistolica' => 139, 'maxDiastolica' => 89, 'minSistolica' => 100, 'minDiastolica' => 60,
                                 'frecuenciaMin' => 60, 'frecuenciaMax' => 100];
    private const BITACORA_PANTALLA = 300;      // renglones de la bitácora en el tablero (todos van en el Excel y el PDF)

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public static function esMedico(?array $usr): bool { return ($usr['rol'] ?? '') === self::ROL; }
    /** Quién toma: el servicio médico y Administración AVBA. */
    public static function puedeTomar(?array $usr): bool { return in_array((string)($usr['rol'] ?? ''), [self::ROL, 'ADMIN'], true); }

    /** Las reglas (api/medico.json) con sus valores por omisión. */
    public static function reglas(): array {
        static $r = null;
        if ($r !== null) return $r;
        $j = @file_get_contents(__DIR__ . '/medico.json');
        $g = is_string($j) ? json_decode($j, true) : null;
        $r = self::POR_DEFECTO;
        if (is_array($g)) foreach ($r as $k => $v) if (isset($g[$k]) && (is_int($v) ? is_numeric($g[$k]) : is_string($g[$k]))) $r[$k] = is_int($v) ? (int)$g[$k] : trim($g[$k]);
        return $r;
    }
    public static function reglasPublicas(): array {
        return ['status' => 'success', 'reglas' => self::reglas(), 'categorias' => self::CATEGORIAS];
    }

    public static function asegurar(PDO $pdo): void {
        static $hecho = [];
        $k = spl_object_id($pdo);
        if (isset($hecho[$k])) return;
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS medico_tomas (
               id             INT AUTO_INCREMENT PRIMARY KEY,
               uid            VARCHAR(64)  NOT NULL,
               fecha          DATE         NOT NULL,
               capturado      DATETIME     NOT NULL,
               recibido       DATETIME     NOT NULL,
               codigo         VARCHAR(32)  NOT NULL DEFAULT '',
               clave          VARCHAR(32)  NOT NULL DEFAULT '',
               nombre         VARCHAR(150) NOT NULL DEFAULT '',
               empresa        VARCHAR(150) NOT NULL DEFAULT '',
               cargo          VARCHAR(150) NOT NULL DEFAULT '',
               en_base        TINYINT(1)   NOT NULL DEFAULT 0,
               sistolica      SMALLINT     NOT NULL,
               diastolica     SMALLINT     NOT NULL,
               frecuencia     SMALLINT     NULL,
               categoria      VARCHAR(10)  NOT NULL DEFAULT '',
               calculado      CHAR(1)      NOT NULL DEFAULT 'A',
               resultado      CHAR(1)      NOT NULL DEFAULT 'A',
               toma           TINYINT      NOT NULL DEFAULT 1,
               observaciones  VARCHAR(500) NOT NULL DEFAULT '',
               origen         VARCHAR(12)  NOT NULL DEFAULT 'manual',
               usuario        VARCHAR(50)  NOT NULL DEFAULT '',
               usuario_nombre VARCHAR(150) NOT NULL DEFAULT '',
               UNIQUE KEY uk_uid (uid),
               KEY idx_fecha (fecha),
               KEY idx_clave (clave, fecha)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $hecho[$k] = true;
    }

    /* ── Clasificación ──────────────────────────────────────────────────── */

    /** La categoría de la NOM-030 (la peor de sistólica y diastólica). */
    public static function categoria(int $s, int $d): string {
        $r = self::reglas();
        if ($s < $r['minSistolica'] || $d < $r['minDiastolica']) return 'BAJA';
        if ($s >= 180 || $d >= 110) return 'HTA3';
        if ($s >= 160 || $d >= 100) return 'HTA2';
        if ($s >= 140 || $d >= 90) return 'HTA1';
        if ($s >= 130 || $d >= 85) return 'ALTA';
        if ($s >= 120 || $d >= 80) return 'NORMAL';
        return 'OPTIMA';
    }
    /** 'A' (apto) si está entre los límites permitidos; si no, 'N'. */
    public static function aptitud(int $s, int $d): string {
        $r = self::reglas();
        return $s >= $r['minSistolica'] && $s <= $r['maxSistolica'] && $d >= $r['minDiastolica'] && $d <= $r['maxDiastolica'] ? 'A' : 'N';
    }

    /* ── Registrar (con la cola sin señal) ──────────────────────────────── */

    public function registrar(array $p, array $usr): array {
        if (!self::puedeTomar($usr)) return ['status' => 'error', 'message' => 'Sólo el servicio médico registra tomas.'];
        self::asegurar($this->pdo);
        $uid = (string)($p['uid'] ?? '');
        if (!preg_match('/^[A-Za-z0-9-]{8,64}$/', $uid)) return ['status' => 'error', 'message' => 'Falta el identificador de la toma.'];
        $ya = $this->pdo->prepare("SELECT id, toma, resultado FROM medico_tomas WHERE uid = ?");
        $ya->execute([$uid]);
        if ($f = $ya->fetch(PDO::FETCH_ASSOC))                          // ya había llegado (la cola la reenvió)
            return ['status' => 'success', 'id' => (int)$f['id'], 'toma' => (int)$f['toma'], 'resultado' => $f['resultado'], 'repetida' => true, 'message' => 'La toma ya estaba guardada.'];

        $s = (int)($p['sistolica'] ?? 0); $d = (int)($p['diastolica'] ?? 0);
        $fc = isset($p['frecuencia']) && $p['frecuencia'] !== '' && $p['frecuencia'] !== null ? (int)$p['frecuencia'] : null;
        if ($s < 50 || $s > 300 || $d < 30 || $d > 200) return ['status' => 'error', 'message' => 'Revisa la tensión arterial: sistólica entre 50 y 300 y diastólica entre 30 y 200 mmHg.'];
        if ($s <= $d) return ['status' => 'error', 'message' => 'La sistólica (el número de arriba) debe ser mayor que la diastólica.'];
        if ($fc !== null && ($fc < 20 || $fc > 250)) return ['status' => 'error', 'message' => 'Revisa la frecuencia cardiaca (entre 20 y 250 lpm).'];

        $codigo = strtoupper(substr((string)preg_replace('/[^0-9A-Za-z-]/', '', (string)($p['codigo'] ?? '')), 0, 32));
        $clave = $codigo !== '' ? ControlAccesos::claveId($codigo) : '';
        $nombre = mb_substr(textoEstandar($p['nombre'] ?? ''), 0, 150);
        $empresa = mb_substr(textoEstandar($p['empresa'] ?? ''), 0, 150);
        $cargo = '';
        $enBase = 0;
        if ($clave !== '') {                                             // los datos de la base mandan
            Personal::asegurarTablas($this->pdo);
            $st = $this->pdo->prepare("SELECT codigo, nombre, empresa, cargo FROM personal WHERE clave = ?");
            $st->execute([$clave]);
            if ($b = $st->fetch(PDO::FETCH_ASSOC)) {
                $codigo = (string)$b['codigo']; $nombre = (string)$b['nombre'];
                $empresa = (string)$b['empresa'] !== '' ? (string)$b['empresa'] : $empresa; $cargo = (string)$b['cargo']; $enBase = 1;
            }
        }
        if ($clave === '' && $nombre === '') return ['status' => 'error', 'message' => 'Escanea la credencial, escribe el ID o, si no tiene credencial, escribe su nombre.'];

        $calc = self::aptitud($s, $d);
        $res = strtoupper((string)($p['resultado'] ?? ''));
        if (!in_array($res, ['A', 'N'], true)) $res = $calc;
        $obs = mb_substr(trim((string)preg_replace('/\s+/u', ' ', (string)($p['observaciones'] ?? ''))), 0, 500);
        if ($res !== $calc && $obs === '')
            return ['status' => 'error', 'message' => 'Marcaste ' . ($res === 'A' ? 'APTO' : 'NO APTO') . ' aunque la presión ' . ($calc === 'A' ? 'está' : 'no está') . ' en el rango permitido: escribe por qué en Observaciones.'];

        $cap = self::horaDeToma((string)($p['capturado'] ?? ''));
        $fecha = substr($cap, 0, 10);
        // Qué toma del día es para esta persona (1.ª, 2.ª…)
        $st = $clave !== ''
            ? $this->pdo->prepare("SELECT COUNT(*) FROM medico_tomas WHERE fecha = ? AND clave = ? AND capturado < ?")
            : $this->pdo->prepare("SELECT COUNT(*) FROM medico_tomas WHERE fecha = ? AND clave = '' AND nombre = ? AND capturado < ?");
        $st->execute([$fecha, $clave !== '' ? $clave : $nombre, $cap]);
        $toma = min(99, (int)$st->fetchColumn() + 1);
        $origen = in_array($p['origen'] ?? '', ['tarjeta', 'manual'], true) ? (string)$p['origen'] : 'manual';
        $cat = self::categoria($s, $d);
        try {
            $this->pdo->prepare(
                "INSERT INTO medico_tomas (uid, fecha, capturado, recibido, codigo, clave, nombre, empresa, cargo, en_base, sistolica, diastolica, frecuencia,
                                           categoria, calculado, resultado, toma, observaciones, origen, usuario, usuario_nombre)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$uid, $fecha, $cap, date('Y-m-d H:i:s'), $codigo, $clave, $nombre, $empresa, $cargo, $enBase, $s, $d, $fc,
                           $cat, $calc, $res, $toma, $obs, $origen, (string)$usr['usuario'], (string)($usr['nombre'] ?? '')]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') return ['status' => 'success', 'repetida' => true, 'message' => 'La toma ya estaba guardada.'];
            throw $e;
        }
        return ['status' => 'success', 'id' => (int)$this->pdo->lastInsertId(), 'toma' => $toma, 'resultado' => $res, 'calculado' => $calc,
                'categoria' => $cat, 'nombre_categoria' => self::CATEGORIAS[$cat], 'nombre' => $nombre, 'empresa' => $empresa, 'en_base' => (bool)$enBase,
                'message' => ($nombre !== '' ? $nombre : 'ID ' . $codigo) . ': ' . $s . '/' . $d . ' mmHg · ' . ($res === 'A' ? 'APTO' : 'NO APTO')
                             . ($toma > 1 ? ' (' . $toma . '.ª toma del día)' : '') . '.'];
    }

    /** La hora de la toma que manda el teléfono (sin señal puede llegar después); si no es creíble, la del servidor. */
    private static function horaDeToma(string $c): string {
        $ahora = time();
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $c) && ($t = strtotime($c)) !== false
            && $t <= $ahora + 15 * 60 && $t >= $ahora - 7 * 86400) return date('Y-m-d H:i:s', $t);
        return date('Y-m-d H:i:s', $ahora);
    }

    /** Qué tomas de la cola del teléfono ya llegaron: {uid: id}. */
    public function existen(array $uids): array {
        self::asegurar($this->pdo);
        $uids = array_values(array_filter(array_slice($uids, 0, 500), fn($u) => is_string($u) && preg_match('/^[A-Za-z0-9-]{8,64}$/', $u)));
        if (!$uids) return ['status' => 'success', 'existen' => (object)[]];
        $st = $this->pdo->prepare("SELECT uid, id FROM medico_tomas WHERE uid IN (" . implode(',', array_fill(0, count($uids), '?')) . ")");
        $st->execute($uids);
        return ['status' => 'success', 'existen' => $st->fetchAll(PDO::FETCH_KEY_PAIR) ?: (object)[]];
    }

    /* ── Consultas del servicio médico ──────────────────────────────────── */

    private static function fila(array $f): array {
        return ['id' => (int)$f['id'], 'uid' => (string)$f['uid'], 'capturado' => (string)$f['capturado'], 'codigo' => (string)$f['codigo'],
                'nombre' => (string)$f['nombre'], 'empresa' => (string)$f['empresa'], 'cargo' => (string)$f['cargo'], 'en_base' => (bool)$f['en_base'],
                'sistolica' => (int)$f['sistolica'], 'diastolica' => (int)$f['diastolica'], 'frecuencia' => $f['frecuencia'] === null ? null : (int)$f['frecuencia'],
                'categoria' => (string)$f['categoria'], 'nombre_categoria' => self::CATEGORIAS[$f['categoria']] ?? '', 'resultado' => (string)$f['resultado'],
                'calculado' => (string)$f['calculado'], 'toma' => (int)$f['toma'], 'observaciones' => (string)$f['observaciones'],
                'origen' => (string)$f['origen'], 'tomo' => (string)$f['usuario_nombre']];
    }

    /** Las tomas de un día (por omisión, hoy) de todo el servicio médico, las más recientes primero. */
    public function listar(array $p, array $usr): array {
        if (!self::puedeTomar($usr)) return ['status' => 'error', 'message' => 'Sólo el servicio médico consulta las tomas.'];
        self::asegurar($this->pdo);
        $f = (string)($p['fecha'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f)) $f = date('Y-m-d');
        $st = $this->pdo->prepare("SELECT * FROM medico_tomas WHERE fecha = ? AND " . Omitidos::fuera($this->pdo) . " ORDER BY capturado DESC, id DESC LIMIT 500");
        $st->execute([$f]);
        $tomas = array_map([self::class, 'fila'], $st->fetchAll(PDO::FETCH_ASSOC));
        return ['status' => 'success', 'fecha' => $f, 'tomas' => $tomas, 'reglas' => self::reglas()];
    }

    /** Las tomas de una persona (últimos 30 días), para verlas al identificarla. */
    public function persona(array $p, array $usr): array {
        if (!self::puedeTomar($usr)) return ['status' => 'error', 'message' => 'Sólo el servicio médico consulta las tomas.'];
        self::asegurar($this->pdo);
        $clave = ControlAccesos::claveId(strtoupper(substr((string)preg_replace('/[^0-9A-Za-z-]/', '', (string)($p['id'] ?? '')), 0, 32)));
        if ($clave === '') return ['status' => 'error', 'message' => 'Escribe el ID.'];
        $st = $this->pdo->prepare("SELECT * FROM medico_tomas WHERE clave = ? AND fecha >= ? AND " . Omitidos::fuera($this->pdo) . " ORDER BY capturado DESC, id DESC LIMIT 60");
        $st->execute([$clave, date('Y-m-d', strtotime('-30 days'))]);
        return ['status' => 'success', 'tomas' => array_map([self::class, 'fila'], $st->fetchAll(PDO::FETCH_ASSOC))];
    }

    /* ── Tablero ─────────────────────────────────────────────────────────── */

    /** Todo lo del periodo. Cada persona cuenta una vez por día, con el resultado de su última toma. */
    private function calcular(string $desde, string $hasta): array {
        self::asegurar($this->pdo);
        $st = $this->pdo->prepare("SELECT * FROM medico_tomas WHERE fecha BETWEEN ? AND ? AND " . Omitidos::fuera($this->pdo) . " ORDER BY capturado, id");
        $st->execute([$desde, $hasta]);
        $filas = array_map([self::class, 'fila'], $st->fetchAll(PDO::FETCH_ASSOC));
        $persona = fn($f) => $f['codigo'] !== '' ? 'I' . ControlAccesos::claveId($f['codigo']) : 'N' . mb_strtoupper($f['nombre']);

        $dias = [];
        for ($d = $desde; $d <= $hasta; $d = Tablero::masDias($d, 1)) $dias[$d] = ['fecha' => $d, 'tomas' => 0, 'evaluados' => 0, 'aptos' => 0, 'no_aptos' => 0];
        $grupos = [];                                       // persona y día → sus tomas en orden
        foreach ($filas as $i => $f) {
            $filas[$i]['n'] = $i + 1;                        // N.º de la bitácora
            $dia = substr($f['capturado'], 0, 10);
            if (isset($dias[$dia])) $dias[$dia]['tomas']++;
            $grupos[$dia . '|' . $persona($f)][] = $i;
        }
        $cat = array_fill_keys(array_keys(self::CATEGORIAS), 0);
        $emp = []; $noAptos = []; $personas = []; $recuperados = 0; $retomas = 0;
        foreach ($grupos as $k => $idx) {
            $ult = $filas[end($idx)]; $pri = $filas[$idx[0]];
            $dia = substr($k, 0, 10);
            $personas[substr($k, 11)] = true;
            if (count($idx) > 1) $retomas++;
            if ($pri['resultado'] === 'N' && $ult['resultado'] === 'A') $recuperados++;
            if (isset($dias[$dia])) { $dias[$dia]['evaluados']++; $dias[$dia][$ult['resultado'] === 'A' ? 'aptos' : 'no_aptos']++; }
            if (isset($cat[$ult['categoria']])) $cat[$ult['categoria']]++;
            $e = $ult['empresa'] !== '' ? $ult['empresa'] : 'Sin compañía';
            if (!isset($emp[$e])) $emp[$e] = ['empresa' => $e, 'evaluados' => 0, 'no_aptos' => 0];
            $emp[$e]['evaluados']++;
            if ($ult['resultado'] === 'N') { $emp[$e]['no_aptos']++; $noAptos[] = $ult + ['tomas_dia' => count($idx)]; }
        }
        usort($emp, fn($a, $b) => [$b['no_aptos'], $b['evaluados'], $a['empresa']] <=> [$a['no_aptos'], $a['evaluados'], $b['empresa']]);
        usort($noAptos, fn($a, $b) => strcmp($b['capturado'], $a['capturado']));
        $evaluados = count($grupos);
        $no = count($noAptos);
        return [
            'desde' => $desde, 'hasta' => $hasta, 'dias' => count($dias), 'generado' => date('Y-m-d H:i:s'), 'reglas' => self::reglas(),
            'kpis' => ['tomas' => count($filas), 'evaluados' => $evaluados, 'personas' => count($personas), 'aptos' => $evaluados - $no, 'no_aptos' => $no,
                       'pct_no_apto' => $evaluados ? round($no / $evaluados * 100, 1) : 0, 'retomas' => $retomas, 'recuperados' => $recuperados,
                       'sin_base' => count(array_filter($filas, fn($f) => !$f['en_base']))],
            'por_dia' => array_values($dias),
            'clasificacion' => array_map(fn($k) => ['categoria' => $k, 'nombre' => self::CATEGORIAS[$k], 'n' => $cat[$k]], array_keys(self::CATEGORIAS)),
            'por_empresa' => $emp, 'no_aptos' => $noAptos, 'bitacora' => array_reverse($filas),
        ];
    }

    public function tablero(array $p, array $usr): array {
        $per = Tablero::periodo($p);
        if (is_string($per)) return ['status' => 'error', 'message' => $per];
        $c = $this->calcular($per[0], $per[1]);
        $total = count($c['bitacora']);
        $c['bitacora'] = array_slice($c['bitacora'], 0, self::BITACORA_PANTALLA);
        $c['por_empresa'] = array_slice($c['por_empresa'], 0, 12);
        return ['status' => 'success', 'bitacora_total' => $total] + $c;
    }

    /* ── Excel y PDF ─────────────────────────────────────────────────────── */

    private static function ta(array $f): string { return $f['sistolica'] . '/' . $f['diastolica']; }
    private static function periodoTexto(array $c): string {
        return $c['desde'] === $c['hasta'] ? 'del ' . Tablero::fecha($c['desde']) : 'del ' . Tablero::fecha($c['desde']) . ' al ' . Tablero::fecha($c['hasta']);
    }
    private static function limitesTexto(array $r): string {
        return 'Valores máximos permitidos ' . $r['maxSistolica'] . '/' . $r['maxDiastolica'] . ' mmHg · mínimos ' . $r['minSistolica'] . '/' . $r['minDiastolica'] . ' mmHg (' . $r['norma'] . ')';
    }
    private static function archivo(array $c, string $ext): string {
        return 'MEDICO_' . $c['desde'] . '_' . $c['hasta'] . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    }

    public function excel(array $p, array $usr): array {
        $per = Tablero::periodo($p);
        if (is_string($per)) return ['status' => 'error', 'message' => $per];
        if (function_exists('set_time_limit')) @set_time_limit(120);
        $c = $this->calcular($per[0], $per[1]);
        $r = $c['reglas']; $k = $c['kpis'];
        $titulo = ['Servicio médico · ' . $r['titulo'] . ($r['subtitulo'] !== '' ? ' · ' . $r['subtitulo'] : ''), 'Periodo: ' . self::periodoTexto($c),
                   'Generado el ' . Tablero::fechaHora($c['generado']) . ' por ' . ($usr['nombre'] ?? $usr['usuario'] ?? '')];
        $x = new XlsxSimple();
        $x->resumen('Resumen', $titulo, [
            ['Tomas de tensión arterial', $k['tomas'], 'entero'],
            ['Personas evaluadas (cada persona una vez por día)', $k['evaluados'], 'entero'],
            ['Personas distintas', $k['personas'], 'entero'],
            ['Aptos (última toma del día)', $k['aptos'], 'entero'],
            ['No aptos (última toma del día)', $k['no_aptos'], 'entero'],
            ['Porcentaje de no aptos', $k['pct_no_apto'] . ' %', 'texto'],
            ['Personas con más de una toma el mismo día', $k['retomas'], 'entero'],
            ['   de ellas, no aptas en la 1.ª toma y aptas al final', $k['recuperados'], 'entero'],
            ['Límites', self::limitesTexto($r), 'texto'],
        ]);
        $x->hoja('Bitácora', ['N.º', 'Fecha y hora', 'ID', 'Nombre del trabajador', 'Compañía', 'Cargo', 'Tensión arterial (mmHg)', 'Sistólica', 'Diastólica',
                              'Frecuencia cardiaca (lpm)', 'Clasificación', 'Apto', 'No apto', 'Toma del día', 'Observaciones', 'Tomó'],
            array_map(fn($f) => [$f['n'], $f['capturado'], $f['codigo'], $f['nombre'], $f['empresa'], $f['cargo'], self::ta($f), $f['sistolica'], $f['diastolica'],
                                 $f['frecuencia'] ?? '', $f['nombre_categoria'], $f['resultado'] === 'A' ? 'X' : '', $f['resultado'] === 'N' ? 'X' : '',
                                 $f['toma'], $f['observaciones'], $f['tomo']], array_reverse($c['bitacora'])),
            [6, 17, 9, 36, 26, 22, 12, 10, 10, 12, 20, 7, 8, 9, 40, 22],
            ['entero', 'fechahora', 'texto', 'texto', 'texto', 'texto', 'texto', 'entero', 'entero', 'entero', 'texto', 'texto', 'texto', 'entero', 'texto', 'texto'], $titulo);
        $x->hoja('No aptos', ['Fecha y hora', 'ID', 'Nombre', 'Compañía', 'Tensión arterial (mmHg)', 'Frecuencia cardiaca (lpm)', 'Clasificación', 'Tomas ese día', 'Observaciones', 'Tomó'],
            array_map(fn($f) => [$f['capturado'], $f['codigo'], $f['nombre'], $f['empresa'], self::ta($f), $f['frecuencia'] ?? '', $f['nombre_categoria'],
                                 $f['tomas_dia'], $f['observaciones'], $f['tomo']], $c['no_aptos']),
            [17, 9, 36, 26, 12, 12, 20, 9, 40, 22], ['fechahora', 'texto', 'texto', 'texto', 'texto', 'entero', 'texto', 'entero', 'texto', 'texto'], $titulo);
        $x->hoja('Por día', ['Fecha', 'Tomas', 'Personas evaluadas', 'Aptos', 'No aptos'],
            array_map(fn($d) => [$d['fecha'], $d['tomas'], $d['evaluados'], $d['aptos'], $d['no_aptos']], $c['por_dia']),
            [12, 10, 12, 10, 10], ['fecha', 'entero', 'entero', 'entero', 'entero'], $titulo);
        $x->hoja('Por compañía', ['Compañía', 'Personas evaluadas', 'No aptos', '% no aptos'],
            array_map(fn($e) => [$e['empresa'], $e['evaluados'], $e['no_aptos'], $e['evaluados'] ? round($e['no_aptos'] / $e['evaluados'] * 100, 1) : 0], $c['por_empresa']),
            [36, 12, 10, 10], ['texto', 'entero', 'entero', 'decimal'], $titulo);
        $x->hoja('Clasificación', ['Clasificación (' . $r['norma'] . ')', 'Personas evaluadas'],
            array_map(fn($q) => [$q['nombre'], $q['n']], $c['clasificacion']), [28, 12], ['texto', 'entero'], $titulo);
        $nombre = self::archivo($c, 'xlsx');
        if (!$x->guardar(Tablero::carpetaReportes() . $nombre)) return ['status' => 'error', 'message' => 'No se pudo guardar el Excel en el servidor.'];
        return ['status' => 'success', 'archivo' => $nombre, 'url' => 'descargar.php?tipo=reporte&f=' . rawurlencode($nombre), 'tomas' => $k['tomas']];
    }

    /** La bitácora en PDF (hoja horizontal, como la de papel), con las cifras y las gráficas que manda la pantalla. */
    public function pdf(array $p, array $usr): array {
        $per = Tablero::periodo($p);
        if (is_string($per)) return ['status' => 'error', 'message' => $per];
        if (!class_exists('Mpdf\Mpdf')) {
            $autoload = __DIR__ . '/../vendor/autoload.php';
            if (!is_file($autoload)) return ['status' => 'error', 'message' => 'No está instalado el generador de PDF.'];
            require_once $autoload;
        }
        if (function_exists('set_time_limit')) @set_time_limit(120);
        $c = $this->calcular($per[0], $per[1]);
        $dir = Tablero::carpetaReportes();
        $tmp = $dir . 'tmp-' . bin2hex(random_bytes(6)) . '/';
        @mkdir($tmp, 0755, true);
        $graficas = [];
        foreach (array_slice(is_array($p['graficas'] ?? null) ? $p['graficas'] : [], 0, 6) as $i => $g) {
            $png = Tablero::pngValido(is_array($g) ? (string)($g['imagen'] ?? '') : '');
            if ($png === null || @file_put_contents($tmp . 'g' . $i . '.png', $png) === false) continue;
            $graficas[] = ['titulo' => mb_substr(trim((string)($g['titulo'] ?? '')), 0, 90), 'sub' => mb_substr(trim((string)($g['sub'] ?? '')), 0, 120), 'ruta' => $tmp . 'g' . $i . '.png'];
        }
        try {
            $mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'orientation' => 'L', 'margin_top' => 10, 'margin_bottom' => 16,
                                    'margin_left' => 10, 'margin_right' => 10, 'margin_header' => 0, 'margin_footer' => 6]);
            $mpdf->SetTitle('Servicio médico · ' . self::periodoTexto($c));
            $mpdf->SetAuthor('AVBA Control de Accesos');
            $mpdf->SetHTMLFooter('<table width="100%" style="font-size:7.5pt;color:#5a6072;border-top:0.2mm solid #c9d3e2"><tr><td style="padding-top:1mm">'
                . htmlspecialchars(self::limitesTexto($c['reglas']), ENT_QUOTES, 'UTF-8') . '</td><td style="text-align:right;padding-top:1mm">Página {PAGENO} de {nbpg}</td></tr></table>');
            $mpdf->WriteHTML($this->htmlPdf($c, $graficas, $usr));
            $nombre = self::archivo($c, 'pdf');
            $mpdf->Output($dir . $nombre, 'F');
            $paginas = (int)$mpdf->page;
        } finally {
            Tablero::borrarCarpeta($tmp);
        }
        return ['status' => 'success', 'archivo' => $nombre, 'url' => 'descargar.php?tipo=reporte&f=' . rawurlencode($nombre), 'paginas' => $paginas];
    }

    private function htmlPdf(array $c, array $graficas, array $usr): string {
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $n = fn($v) => number_format((float)$v, 0, '.', ',');
        $k = $c['kpis']; $r = $c['reglas'];
        $logo = realpath(__DIR__ . '/../assets/logos/avba.png');
        $html = '<style>body{font-family:sans-serif;color:#1a1a2e;font-size:9pt}'
              . 'h2{font-size:11.5pt;color:#0C447C;margin:5mm 0 2mm}'
              . 'table.t{border-collapse:collapse;width:100%;font-size:8.3pt}'
              . 'table.t th{background:#0C447C;color:#fff;font-weight:bold;padding:1.6mm 1.6mm;text-align:left;vertical-align:bottom}'
              . 'table.t td{padding:1.3mm 1.6mm;border-bottom:0.2mm solid #c9d3e2;vertical-align:top}'
              . 'table.t td.c,table.t th.c{text-align:center}'
              . 'table.t tr.no td{background:#FCEBEB}'
              . '.x{font-weight:bold;font-size:10pt}'
              . '.nota{font-size:8pt;color:#5a6072}</style>';
        $html .= '<table width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse"><tr>'
               . '<td style="width:38mm;vertical-align:middle">' . ($logo ? '<img src="' . $h($logo) . '" style="width:34mm">' : '') . '</td>'
               . '<td style="vertical-align:middle;padding-left:4mm"><div style="font-size:9pt;color:#5a6072;font-weight:bold">SERVICIO MÉDICO</div>'
               . '<div style="font-size:15pt;font-weight:bold;color:#0B2545">' . $h(mb_strtoupper($r['titulo'])) . '</div>'
               . '<div style="font-size:9.5pt;color:#5a6072">' . ($r['subtitulo'] !== '' ? $h($r['subtitulo']) . ' · ' : '') . 'Periodo ' . $h(self::periodoTexto($c)) . '</div></td>'
               . '<td style="text-align:right;vertical-align:middle" class="nota">Generado el ' . $h(Tablero::fechaHora($c['generado'])) . '<br>por ' . $h($usr['nombre'] ?? $usr['usuario'] ?? '') . '</td>'
               . '</tr></table>';
        $caja = fn($l, $v, $s) => '<td style="width:20%;padding:2.5mm 3mm;border:0.3mm solid #dfe5ef;vertical-align:top"><div style="font-size:8pt;color:#5a6072;font-weight:bold">' . $h($l)
                                . '</div><div style="font-size:17pt;font-weight:bold;color:#0B2545">' . $v . '</div><div style="font-size:7.8pt;color:#5a6072">' . $h($s) . '</div></td>';
        $html .= '<table width="100%" cellspacing="2mm" style="margin-top:4mm"><tr>'
               . $caja('Tomas', $n($k['tomas']), $n($k['personas']) . ' personas distintas')
               . $caja('Personas evaluadas', $n($k['evaluados']), 'Cada persona una vez por día')
               . $caja('Aptos', $n($k['aptos']), 'Con su última toma del día')
               . $caja('No aptos', $n($k['no_aptos']), $k['pct_no_apto'] . ' % de los evaluados')
               . $caja('Más de una toma', $n($k['retomas']), $n($k['recuperados']) . ($k['recuperados'] === 1 ? ' apto' : ' aptos') . ' en la siguiente toma')
               . '</tr></table>';
        if ($graficas) {
            $html .= '<table width="100%" cellspacing="0" cellpadding="0" style="margin-top:3mm"><tr>';
            foreach ($graficas as $i => $g) {
                if ($i && $i % 2 === 0) $html .= '</tr><tr>';
                $html .= '<td style="width:50%;padding:2mm;vertical-align:top"><div style="font-weight:bold;color:#0C447C;font-size:10pt">' . $h($g['titulo'])
                       . '</div><div class="nota">' . $h($g['sub']) . '</div><img src="' . $h($g['ruta']) . '" style="width:128mm"></td>';
            }
            $html .= '</tr></table>';
        }
        if ($c['no_aptos']) {
            $html .= '<h2>No aptos del periodo (' . $n(count($c['no_aptos'])) . ')</h2><table class="t" repeat_header="1"><thead><tr><th>Fecha y hora</th><th>ID</th><th>Nombre del trabajador</th>'
                   . '<th>Compañía</th><th class="c">T. A. (mmHg)</th><th class="c">F. C. (lpm)</th><th>Clasificación</th><th>Observaciones</th><th>Tomó</th></tr></thead><tbody>';
            foreach ($c['no_aptos'] as $f)
                $html .= '<tr><td>' . $h(Tablero::fechaHora($f['capturado'])) . '</td><td>' . $h($f['codigo']) . '</td><td>' . $h($f['nombre']) . '</td><td>' . $h($f['empresa'])
                       . '</td><td class="c">' . $h(self::ta($f)) . '</td><td class="c">' . $h($f['frecuencia'] ?? '') . '</td><td>' . $h($f['nombre_categoria'])
                       . ($f['tomas_dia'] > 1 ? ' (' . $f['tomas_dia'] . ' tomas)' : '') . '</td><td>' . $h($f['observaciones']) . '</td><td>' . $h($f['tomo']) . '</td></tr>';
            $html .= '</tbody></table>';
        }
        // La bitácora, como la de papel
        $html .= '<pagebreak /><h2 style="margin-top:0">Bitácora de toma de tensión arterial · ' . $h(self::periodoTexto($c)) . '</h2>';
        if (!$c['bitacora']) return $html . '<p class="nota">No hay tomas en este periodo.</p>';
        $html .= '<table class="t" repeat_header="1"><thead><tr><th class="c">N.º</th><th>Fecha</th><th>Hora</th><th>ID</th><th>Nombre del trabajador</th><th>Compañía</th>'
               . '<th class="c">Tensión arterial (mmHg)</th><th class="c">Frecuencia cardiaca (lpm)</th><th class="c">Apto (X)</th><th class="c">No apto (X)</th><th>Observaciones</th><th>Tomó</th></tr></thead><tbody>';
        foreach (array_reverse($c['bitacora']) as $f) {
            $obs = trim(($f['toma'] > 1 ? $f['toma'] . '.ª toma. ' : '') . $f['observaciones']);
            $html .= '<tr' . ($f['resultado'] === 'N' ? ' class="no"' : '') . '><td class="c">' . $f['n'] . '</td><td>' . $h(Tablero::fecha(substr($f['capturado'], 0, 10))) . '</td><td>'
                   . $h(substr($f['capturado'], 11, 5)) . '</td><td>' . $h($f['codigo']) . '</td><td>' . $h($f['nombre']) . '</td><td>' . $h($f['empresa']) . '</td><td class="c">'
                   . $h(self::ta($f)) . '</td><td class="c">' . $h($f['frecuencia'] ?? '') . '</td><td class="c x">' . ($f['resultado'] === 'A' ? 'X' : '') . '</td><td class="c x">'
                   . ($f['resultado'] === 'N' ? 'X' : '') . '</td><td>' . $h($obs) . '</td><td>' . $h($f['tomo']) . '</td></tr>';
        }
        return $html . '</tbody></table>';
    }
}
