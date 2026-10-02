<?php
/**
 * AVBA Certificaciones — Control Administrativo interno
 *
 * Expediente del personal propio de AVBA: inspectores, supervisores y demás
 * empleados. Guarda sus documentos escaneados con vigencia y, además, EMITE los
 * suyos: constancia DC-3, diploma, certificado, credencial y certificaciones
 * internas con fecha de vencimiento.
 *
 * Los documentos no se generan aquí: se reutilizan los mismos generadores que
 * emiten a los participantes de cursos de clientes (Personal.php). Un empleado
 * al que se le va a emitir queda enlazado a un registro de participante, de modo
 * que su DC-3 sale con el mismo formato, el mismo folio y el mismo QR validable
 * que cualquier otra. Desde ahí caben los dos caminos: emitir en el momento
 * desde Administrativo, o mandarlo a la cola de Calidad para que lo revise y lo
 * emita Certificaciones.
 *
 * Para una DC-3 la STPS exige datos que el alta no pedía —CURP y ocupación
 * específica del trabajador, y los de AVBA como patrón—, así que el alta los
 * pide y el expediente avisa en pantalla qué le falta a cada persona para poder
 * emitirle cada documento.
 */
class AvbaAdmin {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->migrate();
    }

    private function migrate(): void {
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS avba_personal (
                  id             INT AUTO_INCREMENT PRIMARY KEY,
                  nombre         VARCHAR(200) NOT NULL,
                  puesto         VARCHAR(120) NULL,
                  tipo           VARCHAR(40)  NULL,
                  num_empleado   VARCHAR(50)  NULL,
                  telefono       VARCHAR(40)  NULL,
                  correo         VARCHAR(150) NULL,
                  fecha_ingreso  DATE         NULL,
                  estado         VARCHAR(30)  NOT NULL DEFAULT 'Activo',
                  notas          TEXT         NULL,
                  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $this->migrarColumnasPersonal();
            $this->migrarConfig();
            $this->migrarCertificaciones();
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS avba_personal_doc (
                  id          INT AUTO_INCREMENT PRIMARY KEY,
                  personal_id INT          NOT NULL,
                  tipo_doc    VARCHAR(60)  NOT NULL DEFAULT 'otro',
                  nombre      VARCHAR(200) NOT NULL,
                  archivo_url VARCHAR(500) NULL,
                  vigencia    DATE         NULL,
                  notas       TEXT         NULL,
                  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  KEY idx_p (personal_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\PDOException $e) {
            error_log('[AvbaAdmin] migrate: ' . $e->getMessage());
        }
    }

    /**
     * Columnas que el alta no pedía y que los documentos sí necesitan.
     *
     * Se comprueba information_schema en vez de usar ADD COLUMN IF NOT EXISTS,
     * que sólo existe en MariaDB: en MySQL 8 el fallo dejaría sin abrir toda la
     * pantalla de Administrativo.
     */
    private function migrarColumnasPersonal(): void {
        $cols = [
            // Trabajador: la DC-3 los exige.
            'curp'                => "VARCHAR(18)  NULL",
            'ocupacion_id'        => "INT          NULL",
            // Identificación y credencial.
            'rfc'                 => "VARCHAR(13)  NULL",
            'fecha_nacimiento'    => "DATE         NULL",
            'escolaridad'         => "VARCHAR(80)  NULL",
            'foto_url'            => "VARCHAR(500) NULL",
            'foto_doc_url'        => "VARCHAR(500) NULL",
            'vigencia_credencial' => "DATE         NULL",
            // Cuenta del sistema: de ahí sale su firma cuando instruye un curso.
            'cuenta_usuario'      => "VARCHAR(50)  NULL",
        ];
        foreach ($cols as $col => $tipo) {
            try {
                if (!$this->hayColumna('avba_personal', $col)) {
                    $this->pdo->exec("ALTER TABLE avba_personal ADD COLUMN `$col` $tipo");
                }
                $this->recienCreadas["avba_personal.$col"] = true;
            } catch (\Throwable $e) {
                error_log("[AvbaAdmin] columna $col: " . $e->getMessage());
            }
        }
    }

    /** Datos de AVBA como patrón: van en la DC-3 de cada empleado. */
    private function migrarConfig(): void {
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS avba_config (
                  clave VARCHAR(60)  NOT NULL PRIMARY KEY,
                  valor TEXT         NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\Throwable $e) { error_log('[AvbaAdmin] avba_config: ' . $e->getMessage()); }
    }

    /**
     * Certificaciones internas: lo que acredita a la persona para su puesto
     * —inspector nivel II, operador— con su folio y su fecha de vencimiento.
     * Son distintas de los documentos escaneados: éstas las emite AVBA.
     */
    private function migrarCertificaciones(): void {
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS avba_personal_cert (
                  id            INT AUTO_INCREMENT PRIMARY KEY,
                  personal_id   INT          NOT NULL,
                  nombre        VARCHAR(200) NOT NULL,
                  norma         VARCHAR(200) NULL,
                  nivel         VARCHAR(80)  NULL,
                  folio         VARCHAR(60)  NULL,
                  otorgada_por  VARCHAR(200) NULL,
                  fecha_emision DATE         NULL,
                  fecha_vence   DATE         NULL,
                  url           VARCHAR(500) NULL,
                  notas         TEXT         NULL,
                  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  INDEX idx_personal (personal_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\Throwable $e) { error_log('[AvbaAdmin] avba_personal_cert: ' . $e->getMessage()); }
    }

    /**
     * Columnas que esta instancia sabe que existen porque las acaba de crear.
     *
     * columnaExiste() recuerda su respuesta durante la petición, y la primera
     * petición después de desplegar pregunta ANTES de la migración: sin esto,
     * esa primera alta guardaría sin los campos nuevos y nadie se enteraría.
     */
    private array $recienCreadas = [];

    private function hayColumna(string $tabla, string $col): bool {
        if (isset($this->recienCreadas["$tabla.$col"])) return true;
        if (function_exists('columnaExiste')) return columnaExiste($this->pdo, $tabla, $col);
        // Sin el ayudante compartido se consulta igual: devolver false haría
        // reintentar el ALTER en cada petición.
        try {
            $st = $this->pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
            );
            $st->execute([$tabla, $col]);
            return ((int)$st->fetchColumn()) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Lo que la DC-3 pide del patrón. AVBA no está en el catálogo de clientes
     * —no es cliente de sí misma— así que sus datos viven aparte, y se capturan
     * una vez en lugar de repetirse en cada empleado.
     */
    public const CAMPOS_PATRON = [
        'razon_social'      => 'razón social de AVBA',
        'rfc'               => 'RFC de AVBA',
        'representante'     => 'representante legal',
        'rep_trabajadores'  => 'representante de los trabajadores',
    ];

    /** Campos del patrón que no son obligatorios para emitir. */
    public const CAMPOS_PATRON_OPC = [
        'registro_stps' => 'registro STPS como agente capacitador',
        'domicilio'     => 'domicilio fiscal',
    ];

    public function config(): array {
        $out = array_fill_keys(
            array_merge(array_keys(self::CAMPOS_PATRON), array_keys(self::CAMPOS_PATRON_OPC)), ''
        );
        try {
            foreach ($this->pdo->query("SELECT clave, valor FROM avba_config")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (array_key_exists($r['clave'], $out)) $out[$r['clave']] = (string)($r['valor'] ?? '');
            }
        } catch (\Throwable $e) { /* sin tabla, todo vacío: la pantalla lo avisa */ }
        return $out;
    }

    public function guardarConfig(array $data): array {
        $validas = array_merge(array_keys(self::CAMPOS_PATRON), array_keys(self::CAMPOS_PATRON_OPC));
        $st = $this->pdo->prepare(
            "INSERT INTO avba_config (clave, valor) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE valor = VALUES(valor)"
        );
        foreach ($validas as $clave) {
            if (!array_key_exists($clave, $data)) continue;
            $valor = trim((string)$data[$clave]);
            if ($clave === 'rfc') $valor = strtoupper(preg_replace('/[^A-Z0-9&Ñ]/iu', '', $valor));
            elseif ($clave !== 'registro_stps') $valor = mb_strtoupper($valor, 'UTF-8');
            $st->execute([$clave, $valor]);
        }
        return ['status' => 'success', 'config' => $this->config()];
    }

    private function own(int $id): bool {
        $s = $this->pdo->prepare("SELECT id FROM avba_personal WHERE id=?");
        $s->execute([$id]);
        return (bool)$s->fetch();
    }

    // ── PERSONAL ─────────────────────────────────────────────
    public function listarPersonal(): array {
        // Columnas nuevas: si una migración no corrió, la lista tiene que seguir
        // cargando y simplemente marcar esos datos como faltantes.
        $curp = $this->hayColumna('avba_personal', 'curp')         ? 'p.curp'         : "''";
        $ocup = $this->hayColumna('avba_personal', 'ocupacion_id') ? 'p.ocupacion_id' : 'NULL';
        $stmt = $this->pdo->query("
            SELECT p.id, p.nombre, p.puesto, p.tipo, p.num_empleado, p.telefono, p.correo,
                   DATE_FORMAT(p.fecha_ingreso,'%d/%m/%Y') AS fecha_ingreso, p.estado, p.notas,
                   {$curp} AS curp, {$ocup} AS ocupacion_id,
                   COUNT(d.id) AS total_docs,
                   COALESCE(SUM(d.vigencia IS NOT NULL AND d.vigencia >= CURDATE()),0)                                        AS vigentes,
                   COALESCE(SUM(d.vigencia IS NOT NULL AND d.vigencia >= CURDATE() AND d.vigencia <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)),0) AS por_vencer,
                   COALESCE(SUM(d.vigencia IS NOT NULL AND d.vigencia < CURDATE()),0)                                         AS vencidos
            FROM avba_personal p
            LEFT JOIN avba_personal_doc d ON d.personal_id = p.id
            GROUP BY p.id
            ORDER BY p.nombre
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return ['status' => 'success', 'personal' => array_map(fn($r) => [
            'id' => (int)$r['id'], 'nombre' => $r['nombre'], 'puesto' => $r['puesto'] ?? '',
            'tipo' => $r['tipo'] ?? '', 'num_empleado' => $r['num_empleado'] ?? '',
            'telefono' => $r['telefono'] ?? '', 'correo' => $r['correo'] ?? '',
            'fecha_ingreso' => $r['fecha_ingreso'], 'estado' => $r['estado'] ?? 'Activo', 'notas' => $r['notas'] ?? '',
            'total_docs' => (int)$r['total_docs'], 'vigentes' => (int)$r['vigentes'],
            'por_vencer' => (int)$r['por_vencer'], 'vencidos' => (int)$r['vencidos'],
            // Qué le falta para poder emitirle. Va en la lista y no sólo en el
            // detalle: la pregunta "¿está todo el personal bien dado de alta?"
            // se tiene que poder contestar de un vistazo.
            'faltan'     => $this->faltanPersona($r),
        ], $rows)];
    }

    /**
     * Los datos de la persona que hacen falta para emitirle documentos.
     * Sólo mira el expediente, no la configuración de AVBA: eso es una sola vez
     * y se avisa aparte, no una vez por empleado.
     *
     * @return string[]
     */
    private function faltanPersona(array $r): array {
        $falta = [];
        if (trim((string)($r['curp'] ?? '')) === '')         $falta[] = 'CURP';
        if (trim((string)($r['ocupacion_id'] ?? '')) === '') $falta[] = 'ocupación específica';
        if (trim((string)($r['puesto'] ?? '')) === '')       $falta[] = 'puesto';
        return $falta;
    }

    /** Normaliza una fecha dd/mm/aaaa o aaaa-mm-dd a aaaa-mm-dd; null si no es válida. */
    private function fecha(?string $v): ?string {
        $v = trim((string)$v);
        if ($v === '') return null;
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y'] as $f) {
            $d = \DateTime::createFromFormat($f, $v);
            if ($d && $d->format($f) === $v) return $d->format('Y-m-d');
        }
        $t = strtotime(str_replace('/', '-', $v));
        return $t ? date('Y-m-d', $t) : null;
    }

    public function guardarPersonal(array $data): array {
        $nombre = mb_strtoupper(trim($data['nombre'] ?? ''), 'UTF-8');
        if (!$nombre) return ['status' => 'error', 'message' => 'El nombre es requerido.'];
        $id = (int)($data['id'] ?? 0);

        $correo = strtolower(trim($data['correo'] ?? ''));
        if ($correo && !filter_var($correo, FILTER_VALIDATE_EMAIL))
            return ['status' => 'error', 'message' => 'El correo no es válido.'];

        // La CURP se valida al capturarla, no al emitir: descubrir que está mal
        // cuando ya se va a imprimir la DC-3 obliga a rehacer el documento.
        $curp = strtoupper(trim($data['curp'] ?? ''));
        if ($curp !== '' && function_exists('validarCURPCompleta')) {
            $chk = validarCURPCompleta($curp);
            if (empty($chk['valida'])) return ['status' => 'error', 'message' => 'CURP: ' . $chk['error']];
        }

        $rfc = strtoupper(preg_replace('/[^A-Z0-9&Ñ]/iu', '', $data['rfc'] ?? ''));
        if ($rfc !== '' && !preg_match('/^[A-ZÑ&]{4}\d{6}[A-Z0-9]{3}$/', $rfc))
            return ['status' => 'error', 'message' => 'El RFC debe tener 13 caracteres (4 letras, 6 dígitos de fecha y 3 de homoclave).'];

        $ocupacionId = (int)($data['ocupacion_id'] ?? 0) ?: null;
        if ($ocupacionId) {
            $ck = $this->pdo->prepare("SELECT id FROM ocupaciones_especificas WHERE id = ?");
            $ck->execute([$ocupacionId]);
            if (!$ck->fetch()) return ['status' => 'error', 'message' => 'La ocupación específica indicada ya no existe.'];
        }

        $campos = [
            'nombre'              => $nombre,
            'puesto'              => mb_strtoupper(trim($data['puesto'] ?? ''), 'UTF-8') ?: null,
            'tipo'                => trim($data['tipo'] ?? '') ?: null,
            'num_empleado'        => trim($data['num_empleado'] ?? '') ?: null,
            'telefono'            => trim($data['telefono'] ?? '') ?: null,
            'correo'              => $correo ?: null,
            'fecha_ingreso'       => $this->fecha($data['fecha_ingreso'] ?? ''),
            'estado'              => trim($data['estado'] ?? 'Activo') ?: 'Activo',
            'notas'               => trim($data['notas'] ?? '') ?: null,
            'curp'                => $curp ?: null,
            'ocupacion_id'        => $ocupacionId,
            'rfc'                 => $rfc ?: null,
            'fecha_nacimiento'    => $this->fecha($data['fecha_nacimiento'] ?? ''),
            'escolaridad'         => trim($data['escolaridad'] ?? '') ?: null,
            'vigencia_credencial' => $this->fecha($data['vigencia_credencial'] ?? ''),
            'cuenta_usuario'      => strtolower(trim($data['cuenta_usuario'] ?? '')) ?: null,
        ];
        // Sólo se escriben las columnas que la base realmente tiene: si una
        // migración no corrió, se guarda lo demás en vez de fallar entero.
        foreach (array_keys($campos) as $c) {
            if (!in_array($c, ['nombre','puesto','tipo','num_empleado','telefono','correo','fecha_ingreso','estado','notas'], true)
                && !$this->hayColumna('avba_personal', $c)) {
                unset($campos[$c]);
            }
        }

        if ($id) {
            if (!$this->own($id)) return ['status' => 'error', 'message' => 'Empleado no encontrado.'];
            $sets = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($campos)));
            $vals = array_values($campos);
            $vals[] = $id;
            $this->pdo->prepare("UPDATE avba_personal SET $sets WHERE id = ?")->execute($vals);
        } else {
            $cols = implode(', ', array_map(fn($c) => "`$c`", array_keys($campos)));
            $ph   = implode(', ', array_fill(0, count($campos), '?'));
            $this->pdo->prepare("INSERT INTO avba_personal ($cols) VALUES ($ph)")->execute(array_values($campos));
            $id = (int)$this->pdo->lastInsertId();
        }
        return ['status' => 'success', 'id' => $id, 'faltantes' => $this->faltantes($id)];
    }

    /**
     * Qué le falta a esta persona para poder emitirle cada documento.
     *
     * Es el punto de la pantalla: que nadie descubra a la hora de imprimir que
     * la DC-3 no se puede hacer porque falta la CURP.
     *
     * Se exige SÓLO lo que cada generador usa de verdad. Pedir de más es tan
     * molesto como pedir de menos: bloquearía una emisión legítima por un dato
     * que el documento ni siquiera imprime.
     *   · DC-3        — la más exigente: la firma el patrón y la revisa la STPS.
     *   · Certificado — nombre, CURP y la empresa que lo acredita.
     *   · Diploma     — nombre y curso, que siempre están.
     *   · Credencial  — la fotografía; la vigencia la calcula de la fecha del curso.
     *
     * @return array<string,array{puede:bool,falta:string[]}>
     */
    public function faltantes(int $id): array {
        $p = $this->personal($id);
        if (!$p) return [];

        $vacio = fn(string $c) => trim((string)($p[$c] ?? '')) === '';
        $cfg   = $this->config();

        $sinPatron = [];
        foreach (self::CAMPOS_PATRON as $clave => $etiqueta) {
            if (trim((string)($cfg[$clave] ?? '')) === '') $sinPatron[$clave] = $etiqueta;
        }

        $dc3 = [];
        if ($vacio('curp'))         $dc3[] = 'CURP';
        if ($vacio('ocupacion_id')) $dc3[] = 'ocupación específica';
        if ($vacio('puesto'))       $dc3[] = 'puesto';
        $dc3 = array_merge($dc3, array_values($sinPatron));

        $cert = [];
        if ($vacio('curp')) $cert[] = 'CURP';
        if (isset($sinPatron['razon_social'])) $cert[] = $sinPatron['razon_social'];

        $cred = [];
        if ($vacio('foto_url')) $cred[] = 'fotografía';

        $out = [];
        foreach (['dc3' => $dc3, 'certificado' => $cert, 'diploma' => [], 'credencial' => $cred] as $doc => $falta) {
            $out[$doc] = ['puede' => !$falta, 'falta' => array_values(array_unique($falta))];
        }
        return $out;
    }

    public function eliminarPersonal(int $id): array {
        if (!$this->own($id)) return ['status' => 'error', 'message' => 'Empleado no encontrado.'];
        $s = $this->pdo->prepare("SELECT archivo_url FROM avba_personal_doc WHERE personal_id=?");
        $s->execute([$id]);
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $u) {
            if ($u) { $f = rtrim(UPLOAD_DIR,'/').'/'.ltrim($u,'/'); if (is_file($f)) @unlink($f); }
        }
        $this->pdo->prepare("DELETE FROM avba_personal_doc WHERE personal_id=?")->execute([$id]);
        $this->pdo->prepare("DELETE FROM avba_personal WHERE id=?")->execute([$id]);
        return ['status' => 'success'];
    }

    public function detallePersonal(int $id): array {
        $s = $this->pdo->prepare("SELECT * FROM avba_personal WHERE id=?");
        $s->execute([$id]);
        $p = $s->fetch(PDO::FETCH_ASSOC);
        if (!$p) return ['status' => 'error', 'message' => 'Empleado no encontrado.'];
        $d = $this->pdo->prepare("
            SELECT id, tipo_doc, nombre, archivo_url,
                   DATE_FORMAT(vigencia,'%d/%m/%Y') AS vigencia,
                   DATEDIFF(vigencia, CURDATE()) AS dias,
                   (vigencia IS NOT NULL AND vigencia < CURDATE()) AS vencido,
                   notas, DATE_FORMAT(created_at,'%d/%m/%Y') AS fecha_subida
            FROM avba_personal_doc WHERE personal_id=? ORDER BY tipo_doc, nombre
        ");
        $d->execute([$id]);
        $docs = $d->fetchAll(PDO::FETCH_ASSOC);
        foreach ($docs as &$doc) { $doc['archivo_url'] = $doc['archivo_url'] ? (rtrim(SITE_URL,'/').'/'.ltrim($doc['archivo_url'],'/')) : ''; }
        unset($doc);

        foreach (['foto_url', 'foto_doc_url'] as $f) {
            if (!empty($p[$f])) $p[$f] = rtrim(SITE_URL, '/') . '/' . ltrim((string)$p[$f], '/');
        }
        // Las fechas, en el formato que el formulario espera.
        foreach (['fecha_ingreso', 'fecha_nacimiento', 'vigencia_credencial'] as $f) {
            if (!empty($p[$f])) $p[$f] = substr((string)$p[$f], 0, 10);
        }
        $p['ocupacion_nombre'] = '';
        if (!empty($p['ocupacion_id'])) {
            try {
                $o = $this->pdo->prepare("SELECT nombre FROM ocupaciones_especificas WHERE id = ?");
                $o->execute([$p['ocupacion_id']]);
                $p['ocupacion_nombre'] = (string)($o->fetchColumn() ?: '');
            } catch (\Throwable $e) { /* catálogo no disponible */ }
        }

        return [
            'status'          => 'success',
            'personal'        => $p,
            'docs'            => $docs,
            'faltantes'       => $this->faltantes($id),
            'capacitaciones'  => $this->listarCapacitaciones($id)['capacitaciones'] ?? [],
            'certificaciones' => $this->listarCertificaciones($id)['certificaciones'] ?? [],
            'config'          => $this->config(),
        ];
    }

    /** Fotografía del empleado: la de la credencial o la de su identificación. */
    public function subirFotoPersonal(array $post, array $files): array {
        $pid  = (int)($post['personal_id'] ?? 0);
        $cual = ($post['cual'] ?? 'foto') === 'documento' ? 'foto_doc_url' : 'foto_url';
        if (!$this->own($pid)) return ['status' => 'error', 'message' => 'Empleado no encontrado.'];
        if (!$this->hayColumna('avba_personal', $cual))
            return ['status' => 'error', 'message' => 'La base todavía no tiene esa columna. Vuelve a entrar al módulo.'];
        if (empty($files['archivo']['tmp_name']))
            return ['status' => 'error', 'message' => 'No se recibió ninguna imagen.'];

        $ext = strtolower(pathinfo($files['archivo']['name'] ?? '', PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true))
            return ['status' => 'error', 'message' => 'La fotografía debe ser JPG, PNG o WEBP.'];
        if (($files['archivo']['size'] ?? 0) > 10 * 1024 * 1024)
            return ['status' => 'error', 'message' => 'La imagen no debe superar 10 MB.'];

        $dir = rtrim(UPLOAD_DIR, '/') . "/avba/personal/$pid/";
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $base = ($cual === 'foto_url' ? 'foto_' : 'ident_') . date('YmdHis');
        $fn   = $base . '.jpg';
        $real = function_exists('comprimirImagen') ? comprimirImagen($files['archivo']['tmp_name'], $dir . $fn, 1200, 1200, 82) : null;
        if ($real) {
            $fn = $real;
        } else {
            $fn = "$base.$ext";
            if (!move_uploaded_file($files['archivo']['tmp_name'], $dir . $fn))
                return ['status' => 'error', 'message' => 'No se pudo guardar la imagen.'];
        }
        $url = "uploads/avba/personal/$pid/$fn";
        $this->pdo->prepare("UPDATE avba_personal SET `$cual` = ? WHERE id = ?")->execute([$url, $pid]);
        return ['status' => 'success', 'url' => rtrim(SITE_URL, '/') . '/' . $url, 'faltantes' => $this->faltantes($pid)];
    }

    public function subirDocPersonal(array $post, array $files): array {
        $pid = (int)($post['personal_id'] ?? 0);
        if (!$this->own($pid)) return ['status' => 'error', 'message' => 'Empleado no encontrado.'];
        $nombre = trim($post['nombre'] ?? '');
        if (!$nombre) return ['status' => 'error', 'message' => 'El nombre del documento es requerido.'];
        $tipo = trim($post['tipo_doc'] ?? 'otro');
        $vig  = trim($post['vigencia'] ?? '');
        $vigDb = $vig ? date('Y-m-d', strtotime(str_replace('/', '-', $vig))) : null;
        $notas = trim($post['notas'] ?? '');

        $url = null;
        if (!empty($files['archivo']['tmp_name'])) {
            $ext = strtolower(pathinfo($files['archivo']['name'] ?? '', PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png','webp','pdf']))
                return ['status' => 'error', 'message' => 'Solo se permiten imágenes o PDF.'];
            if (($files['archivo']['size'] ?? 0) > 10 * 1024 * 1024)
                return ['status' => 'error', 'message' => 'El archivo no debe superar 10 MB.'];
            $dir = rtrim(UPLOAD_DIR,'/') . "/avba/personal/$pid/";
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            if ($ext === 'pdf') {
                $fn = 'doc_' . date('YmdHis') . '.pdf';
                if (!move_uploaded_file($files['archivo']['tmp_name'], $dir.$fn)) return ['status'=>'error','message'=>'No se pudo guardar.'];
            } else {
                $fn = 'doc_' . date('YmdHis') . '.jpg';
                $real = comprimirImagen($files['archivo']['tmp_name'], $dir.$fn, 1600, 1600, 75);
                if ($real) {
                    $fn = $real;
                } else {
                    $fn = 'doc_' . date('YmdHis') . ".$ext";
                    if (!move_uploaded_file($files['archivo']['tmp_name'], $dir.$fn)) return ['status'=>'error','message'=>'No se pudo guardar.'];
                }
            }
            $url = "uploads/avba/personal/$pid/$fn";
        }

        $id = (int)($post['id'] ?? 0);
        if ($id) {
            $sets = ['tipo_doc=?','nombre=?','vigencia=?','notas=?']; $params = [$tipo,$nombre,$vigDb,$notas];
            if ($url !== null) { $sets[] = 'archivo_url=?'; $params[] = $url; }
            $params[] = $id; $params[] = $pid;
            $this->pdo->prepare("UPDATE avba_personal_doc SET ".implode(',',$sets)." WHERE id=? AND personal_id=?")->execute($params);
        } else {
            $this->pdo->prepare("INSERT INTO avba_personal_doc (personal_id,tipo_doc,nombre,archivo_url,vigencia,notas) VALUES (?,?,?,?,?,?)")
                      ->execute([$pid,$tipo,$nombre,$url,$vigDb,$notas]);
            $id = (int)$this->pdo->lastInsertId();
        }
        return ['status' => 'success', 'id' => $id];
    }

    // ══════════════════════════════════════════════════════════
    //  CAPACITACIONES DEL PERSONAL PROPIO
    //
    //  Cada capacitación de un empleado se guarda como un registro de
    //  participante, el mismo que usan los cursos de clientes. No es un atajo:
    //  es lo que hace que su DC-3 salga con el formato, el folio y el QR de
    //  siempre, sin un segundo generador que mantener y que con el tiempo se
    //  separaría del primero. Lo que cambia es de dónde salen los datos del
    //  patrón —de la configuración de AVBA— y que desde aquí se puede emitir
    //  en el momento o mandarlo a la cola de Calidad.
    // ══════════════════════════════════════════════════════════

    /** Los documentos que este módulo sabe emitir. */
    public const DOCS = ['dc3', 'diploma', 'certificado', 'credencial'];

    private function personal(int $id): ?array {
        $s = $this->pdo->prepare("SELECT * FROM avba_personal WHERE id = ?");
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function listarCapacitaciones(int $personalId): array {
        if (!$this->own($personalId)) return ['status' => 'error', 'message' => 'Empleado no encontrado.'];
        if (!$this->hayColumna('participantes_cursos', 'avba_personal_id')) {
            return ['status' => 'success', 'capacitaciones' => []];
        }
        $s = $this->pdo->prepare(
            "SELECT p.id, p.curso_id, c.nombre AS curso, c.duracion_horas, c.area_tematica,
                    DATE_FORMAT(p.fecha_curso,'%d/%m/%Y') AS fecha,
                    p.fecha_curso AS fecha_iso, p.control, p.estatus,
                    (SELECT GROUP_CONCAT(d.tipo_doc) FROM participantes_documentos d
                      WHERE d.participante_id = p.id) AS emitidos
             FROM participantes_cursos p
             LEFT JOIN cursos c ON c.id = p.curso_id
             WHERE p.avba_personal_id = ?
             ORDER BY p.fecha_curso DESC, p.id DESC"
        );
        $s->execute([$personalId]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['emitidos'] = array_values(array_filter(explode(',', (string)($r['emitidos'] ?? ''))));
        }
        return ['status' => 'success', 'capacitaciones' => $rows];
    }

    /**
     * Da de alta —o actualiza— una capacitación del empleado.
     *
     * Los datos personales y los del patrón se copian aquí desde el expediente
     * y la configuración: así el documento refleja lo que era cierto el día del
     * curso, y cambiar mañana el puesto de la persona no reescribe una DC-3 ya
     * emitida.
     */
    public function guardarCapacitacion(array $data, string $usuario): array {
        $pid = (int)($data['personal_id'] ?? 0);
        $per = $this->personal($pid);
        if (!$per) return ['status' => 'error', 'message' => 'Empleado no encontrado.'];
        if (!$this->hayColumna('participantes_cursos', 'avba_personal_id')) {
            return ['status' => 'error', 'message' =>
                'La base todavía no tiene la columna que enlaza al personal de AVBA con sus capacitaciones. '
              . 'Vuelve a entrar al módulo para que se cree.'];
        }

        $cursoId = (int)($data['curso_id'] ?? 0);
        if (!$cursoId) return ['status' => 'error', 'message' => 'Selecciona el curso.'];
        $ck = $this->pdo->prepare("SELECT id FROM cursos WHERE id = ?");
        $ck->execute([$cursoId]);
        if (!$ck->fetch()) return ['status' => 'error', 'message' => 'El curso indicado ya no existe.'];

        $fecha = $this->fecha($data['fecha_curso'] ?? '');
        if (!$fecha) return ['status' => 'error', 'message' => 'La fecha del curso es obligatoria (dd/mm/aaaa).'];

        $cfg = $this->config();
        $campos = [
            'nombre_completo'       => $per['nombre'],
            'curp'                  => (string)($per['curp'] ?? ''),
            'puesto'                => $per['puesto'],
            'ocupacion_id'          => $per['ocupacion_id'] ?? null,
            'telefono'              => $per['telefono'],
            'correo'                => $per['correo'],
            'empresa_nombre'        => $cfg['razon_social'],
            'empresa_rfc'           => $cfg['rfc'],
            'empresa_direccion'     => $cfg['domicilio'],
            'empresa_representante' => $cfg['representante'],
            'curso_id'              => $cursoId,
            'fecha_curso'           => $fecha,
            'avba_personal_id'      => $pid,
            'foto_persona_url'      => $per['foto_url'] ?? null,
            'foto_documentacion_url'=> $per['foto_doc_url'] ?? null,
        ];
        if ($this->hayColumna('participantes_cursos', 'empresa_rep_trabajadores')) {
            $campos['empresa_rep_trabajadores'] = $cfg['rep_trabajadores'];
        }
        // El instructor firma por su cuenta de usuario: así la DC-3 lleva su
        // firma y su registro STPS, igual que en los cursos de clientes.
        $instructor = strtolower(trim((string)($data['instructor'] ?? ''))) ?: $usuario;
        $campos['usuario_registro'] = $instructor;

        $id = (int)($data['id'] ?? 0);
        if ($id) {
            $chk = $this->pdo->prepare("SELECT id FROM participantes_cursos WHERE id = ? AND avba_personal_id = ?");
            $chk->execute([$id, $pid]);
            if (!$chk->fetch()) return ['status' => 'error', 'message' => 'Esa capacitación no es de este empleado.'];
            $sets = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($campos)));
            $vals = array_values($campos); $vals[] = $id;
            $this->pdo->prepare("UPDATE participantes_cursos SET $sets WHERE id = ?")->execute($vals);
        } else {
            $cols = implode(', ', array_map(fn($c) => "`$c`", array_keys($campos)));
            $ph   = implode(', ', array_fill(0, count($campos), '?'));
            $this->pdo->prepare("INSERT INTO participantes_cursos ($cols) VALUES ($ph)")
                      ->execute(array_values($campos));
            $id = (int)$this->pdo->lastInsertId();
        }
        return ['status' => 'success', 'id' => $id];
    }

    public function eliminarCapacitacion(int $id, int $personalId): array {
        $chk = $this->pdo->prepare("SELECT id FROM participantes_cursos WHERE id = ? AND avba_personal_id = ?");
        $chk->execute([$id, $personalId]);
        if (!$chk->fetch()) return ['status' => 'error', 'message' => 'Capacitación no encontrada.'];
        $this->pdo->prepare("DELETE FROM participantes_documentos WHERE participante_id = ?")->execute([$id]);
        $this->pdo->prepare("DELETE FROM participantes_cursos WHERE id = ?")->execute([$id]);
        return ['status' => 'success'];
    }

    /**
     * Camino 1: emitir aquí mismo, sin pasar por Calidad.
     *
     * Antes de llamar al generador se comprueba qué falta, para que el error
     * diga "falta la CURP" y no un fallo del PDF a medio armar.
     */
    public function emitirDocumento(int $capacitacionId, int $personalId, string $tipo, string $usuario, Personal $personal): array {
        $tipo = strtolower(trim($tipo));
        if (!in_array($tipo, self::DOCS, true)) return ['status' => 'error', 'message' => 'Documento no válido.'];

        $chk = $this->pdo->prepare("SELECT id FROM participantes_cursos WHERE id = ? AND avba_personal_id = ?");
        $chk->execute([$capacitacionId, $personalId]);
        if (!$chk->fetch()) return ['status' => 'error', 'message' => 'Esa capacitación no es de este empleado.'];

        $falta = $this->faltantes($personalId)[$tipo] ?? ['puede' => true, 'falta' => []];
        if (!$falta['puede']) {
            return ['status' => 'error', 'message' =>
                'Faltan datos para emitir este documento: ' . implode(', ', $falta['falta']) . '.'];
        }
        return $personal->generarDocumento($capacitacionId, $tipo, $usuario);
    }

    /**
     * Camino 2: mandarlo a revisión. El registro entra a la cola de Calidad
     * como cualquier participante y lo emite Certificaciones.
     */
    public function enviarACalidad(int $capacitacionId, int $personalId): array {
        $chk = $this->pdo->prepare("SELECT id, estatus FROM participantes_cursos WHERE id = ? AND avba_personal_id = ?");
        $chk->execute([$capacitacionId, $personalId]);
        $row = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$row) return ['status' => 'error', 'message' => 'Esa capacitación no es de este empleado.'];
        if (in_array((string)$row['estatus'], ['APROBADO_CALIDAD', 'EMITIDO'], true)) {
            return ['status' => 'error', 'message' => 'Esta capacitación ya pasó por Calidad.'];
        }
        $this->pdo->prepare("UPDATE participantes_cursos SET estatus = 'PENDIENTE' WHERE id = ?")
                  ->execute([$capacitacionId]);
        return ['status' => 'success', 'message' => 'Enviado a Calidad para revisión.'];
    }

    // ══════════════════════════════════════════════════════════
    //  CERTIFICACIONES INTERNAS
    //  Lo que acredita a la persona para su puesto y caduca: inspector nivel
    //  II, operador de grúa, etc. Llevan folio y fecha de vencimiento propios.
    // ══════════════════════════════════════════════════════════

    public function listarCertificaciones(int $personalId): array {
        if (!$this->own($personalId)) return ['status' => 'error', 'message' => 'Empleado no encontrado.'];
        $s = $this->pdo->prepare(
            "SELECT id, nombre, norma, nivel, folio, otorgada_por,
                    DATE_FORMAT(fecha_emision,'%d/%m/%Y') AS emision,
                    DATE_FORMAT(fecha_vence,'%d/%m/%Y')   AS vence,
                    fecha_vence AS vence_iso,
                    DATEDIFF(fecha_vence, CURDATE())      AS dias,
                    url, notas
             FROM avba_personal_cert WHERE personal_id = ?
             ORDER BY fecha_vence IS NULL, fecha_vence, nombre"
        );
        $s->execute([$personalId]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $dias = $r['dias'];
            $r['estado'] = $dias === null ? 'sin_vigencia'
                         : ($dias < 0 ? 'vencida' : ($dias <= 30 ? 'por_vencer' : 'vigente'));
            if ($r['url']) $r['url'] = rtrim(SITE_URL, '/') . '/' . ltrim($r['url'], '/');
        }
        return ['status' => 'success', 'certificaciones' => $rows];
    }

    public function guardarCertificacion(array $data): array {
        $pid = (int)($data['personal_id'] ?? 0);
        if (!$this->own($pid)) return ['status' => 'error', 'message' => 'Empleado no encontrado.'];
        $nombre = mb_strtoupper(trim($data['nombre'] ?? ''), 'UTF-8');
        if (!$nombre) return ['status' => 'error', 'message' => 'El nombre de la certificación es obligatorio.'];

        $emision = $this->fecha($data['fecha_emision'] ?? '');
        $vence   = $this->fecha($data['fecha_vence'] ?? '');
        if ($emision && $vence && $vence < $emision) {
            return ['status' => 'error', 'message' => 'La fecha de vencimiento no puede ser anterior a la de emisión.'];
        }

        $campos = [
            'personal_id'   => $pid,
            'nombre'        => $nombre,
            'norma'         => mb_strtoupper(trim($data['norma'] ?? ''), 'UTF-8') ?: null,
            'nivel'         => trim($data['nivel'] ?? '') ?: null,
            'folio'         => trim($data['folio'] ?? '') ?: null,
            'otorgada_por'  => mb_strtoupper(trim($data['otorgada_por'] ?? ''), 'UTF-8') ?: null,
            'fecha_emision' => $emision,
            'fecha_vence'   => $vence,
            'notas'         => trim($data['notas'] ?? '') ?: null,
        ];

        $id = (int)($data['id'] ?? 0);
        if ($id) {
            $chk = $this->pdo->prepare("SELECT id FROM avba_personal_cert WHERE id = ? AND personal_id = ?");
            $chk->execute([$id, $pid]);
            if (!$chk->fetch()) return ['status' => 'error', 'message' => 'Certificación no encontrada.'];
            unset($campos['personal_id']);
            $sets = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($campos)));
            $vals = array_values($campos); $vals[] = $id;
            $this->pdo->prepare("UPDATE avba_personal_cert SET $sets WHERE id = ?")->execute($vals);
        } else {
            $cols = implode(', ', array_map(fn($c) => "`$c`", array_keys($campos)));
            $ph   = implode(', ', array_fill(0, count($campos), '?'));
            $this->pdo->prepare("INSERT INTO avba_personal_cert ($cols) VALUES ($ph)")
                      ->execute(array_values($campos));
            $id = (int)$this->pdo->lastInsertId();
        }
        return ['status' => 'success', 'id' => $id];
    }

    public function eliminarCertificacion(int $id): array {
        $s = $this->pdo->prepare("SELECT url FROM avba_personal_cert WHERE id = ?");
        $s->execute([$id]);
        $u = $s->fetchColumn();
        if ($u === false) return ['status' => 'error', 'message' => 'Certificación no encontrada.'];
        if ($u) { $f = rtrim(UPLOAD_DIR, '/') . '/' . ltrim((string)$u, '/'); if (is_file($f)) @unlink($f); }
        $this->pdo->prepare("DELETE FROM avba_personal_cert WHERE id = ?")->execute([$id]);
        return ['status' => 'success'];
    }

    public function eliminarDocPersonal(int $id): array {
        $s = $this->pdo->prepare("SELECT archivo_url FROM avba_personal_doc WHERE id=?");
        $s->execute([$id]);
        $u = $s->fetchColumn();
        if ($u === false) return ['status' => 'error', 'message' => 'Documento no encontrado.'];
        if ($u) { $f = rtrim(UPLOAD_DIR,'/').'/'.ltrim($u,'/'); if (is_file($f)) @unlink($f); }
        $this->pdo->prepare("DELETE FROM avba_personal_doc WHERE id=?")->execute([$id]);
        return ['status' => 'success'];
    }
}
