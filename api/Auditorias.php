<?php
/**
 * AVBA Certificaciones — Auditorías trimestrales de Calidad
 *
 * Genera reportes de auditoría (uno consolidado por trimestre) sobre el estado
 * de vigencias de los EQUIPOS y el PERSONAL certificados por AVBA, con
 * hallazgos, calificación y responsable que firma. Manual + recordatorio del
 * trimestre en curso.
 *
 * Vigencia: equipos = fecha_inspeccion + 1 año; personal = fecha_curso + 1 año.
 */
class Auditorias {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->migrate();
    }

    private function migrate(): void {
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS auditorias (
                  id            INT AUTO_INCREMENT PRIMARY KEY,
                  anio          SMALLINT NOT NULL,
                  trimestre     TINYINT  NOT NULL,
                  periodo       VARCHAR(30) NULL,
                  resultado     VARCHAR(40) NULL,
                  observaciones TEXT NULL,
                  hallazgos     TEXT NULL,
                  stats         TEXT NULL,
                  responsable   VARCHAR(200) NULL,
                  pdf_url       VARCHAR(500) NULL,
                  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  UNIQUE KEY uniq_periodo (anio, trimestre)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\PDOException $e) {
            error_log('[Auditorias] migrate: ' . $e->getMessage());
        }
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS informes_inspecciones (
                  id          INT AUTO_INCREMENT PRIMARY KEY,
                  desde       DATE NULL,
                  hasta       DATE NULL,
                  anio        INT  NULL,
                  trimestre   TINYINT NULL,
                  total       INT  NOT NULL DEFAULT 0,
                  responsable VARCHAR(200) NULL,
                  archivo     VARCHAR(300) NOT NULL,
                  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\PDOException $e) {
            error_log('[Auditorias] migrate informes: ' . $e->getMessage());
        }

        // Bases que ya tenían el módulo antes del trimestre. Cada una en su
        // propio try: en MariaDB vieja IF NOT EXISTS no existe y la sentencia
        // falla; queremos que falle sola, sin arrastrar a las demás.
        foreach (['anio INT NULL', 'trimestre TINYINT NULL'] as $col) {
            try { $this->pdo->exec("ALTER TABLE informes_inspecciones ADD COLUMN IF NOT EXISTS $col"); }
            catch (\Throwable $e) { /* ya existe, o el motor no lo soporta */ }
        }

        /* Qué llevó cada informe, renglón por renglón.
           Sin esto el historial dice cuántos equipos se reportaron, pero no
           cuáles, y entonces no hay forma de saber si una inspección quedó sin
           declarar o se declaró dos veces en trimestres distintos. Ante la ema
           eso no se puede contestar con la memoria de nadie. */
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS informes_inspecciones_detalle (
                  id         INT AUTO_INCREMENT PRIMARY KEY,
                  informe_id INT NOT NULL,
                  equipo_id  INT NOT NULL,
                  control    VARCHAR(20) NULL,
                  UNIQUE KEY uk_informe_equipo (informe_id, equipo_id),
                  KEY idx_equipo (equipo_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\PDOException $e) {
            error_log('[Auditorias] migrate detalle informes: ' . $e->getMessage());
        }
    }

    private function trimLabel(int $t): string {
        return ['1' => 'Ene–Mar', '2' => 'Abr–Jun', '3' => 'Jul–Sep', '4' => 'Oct–Dic'][(string)$t] ?? "T$t";
    }

    /** Calcula el estado de vigencias de equipos y personal certificados. */
    public function computeVigencias(): array {
        $eq = ['total' => 0, 'vigentes' => 0, 'por_vencer' => 0, 'vencidos' => 0, 'sin_fecha' => 0];
        $pe = ['total' => 0, 'vigentes' => 0, 'por_vencer' => 0, 'vencidos' => 0, 'sin_fecha' => 0];
        $venc = ['equipos' => [], 'personal' => []]; // detalle de vencidos/por vencer

        // Equipos certificados (ENVIADO)
        try {
            $rows = $this->pdo->query("
                SELECT maquinaria, marca, serie, control, cliente, fecha_inspeccion,
                       DATE_ADD(fecha_inspeccion, INTERVAL 1 YEAR) AS vence,
                       DATEDIFF(DATE_ADD(fecha_inspeccion, INTERVAL 1 YEAR), CURDATE()) AS dias
                FROM equipos WHERE estado = 'ENVIADO'
            ")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $eq['total']++;
                if (empty($r['fecha_inspeccion'])) { $eq['sin_fecha']++; continue; }
                $d = (int)$r['dias'];
                if ($d < 0)       { $eq['vencidos']++;   $venc['equipos'][] = $this->detItem($r, 'equipo', $d); }
                elseif ($d <= 30) { $eq['por_vencer']++; $venc['equipos'][] = $this->detItem($r, 'equipo', $d); }
                else              { $eq['vigentes']++; }
            }
        } catch (\PDOException $e) {}

        // Personal certificado (con fecha de curso)
        try {
            $rows = $this->pdo->query("
                SELECT nombre_completo, empresa_nombre, control, fecha_curso,
                       DATEDIFF(DATE_ADD(fecha_curso, INTERVAL 1 YEAR), CURDATE()) AS dias
                FROM personal
                WHERE fecha_curso IS NOT NULL AND fecha_curso <> '' AND fecha_curso <> '0000-00-00'
            ")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $pe['total']++;
                $d = (int)$r['dias'];
                if ($d < 0)       { $pe['vencidos']++;   $venc['personal'][] = $this->detItem($r, 'personal', $d); }
                elseif ($d <= 30) { $pe['por_vencer']++; $venc['personal'][] = $this->detItem($r, 'personal', $d); }
                else              { $pe['vigentes']++; }
            }
        } catch (\PDOException $e) {}

        return ['equipos' => $eq, 'personal' => $pe, 'detalle' => $venc];
    }

    private function detItem(array $r, string $tipo, int $dias): array {
        if ($tipo === 'equipo') {
            $nombre = trim(($r['maquinaria'] ?? '') . (!empty($r['serie']) ? ' · S/N ' . $r['serie'] : ''));
            $ref    = $r['control'] ?? '';
            $due    = $r['cliente'] ?? '';
        } else {
            $nombre = $r['nombre_completo'] ?? '';
            $ref    = $r['control'] ?? '';
            $due    = $r['empresa_nombre'] ?? '';
        }
        return ['nombre' => $nombre, 'ref' => $ref, 'para' => $due,
                'dias' => $dias, 'estado' => $dias < 0 ? 'Vencido' : 'Por vencer'];
    }

    /** Preview para el modal: vigencias actuales + sugerencia de resultado. */
    public function preview(int $anio, int $trimestre): array {
        $v = $this->computeVigencias();
        $vencidos = $v['equipos']['vencidos'] + $v['personal']['vencidos'];
        $porVencer = $v['equipos']['por_vencer'] + $v['personal']['por_vencer'];
        $sugerido = $vencidos > 0 ? 'No satisfactoria' : ($porVencer > 0 ? 'Satisfactoria con observaciones' : 'Satisfactoria');
        return ['status' => 'success', 'anio' => $anio, 'trimestre' => $trimestre,
                'periodo' => $this->trimLabel($trimestre), 'vigencias' => $v, 'resultado_sugerido' => $sugerido];
    }

    /** Trimestres del año que aún no tienen auditoría (para el recordatorio). */
    public function pendientes(int $anio): array {
        $done = [];
        try {
            $s = $this->pdo->prepare("SELECT trimestre FROM auditorias WHERE anio = ?");
            $s->execute([$anio]);
            $done = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
        } catch (\PDOException $e) {}
        return array_values(array_diff([1,2,3,4], $done));
    }

    public function listar(): array {
        try {
            $rows = $this->pdo->query("
                SELECT id, anio, trimestre, periodo, resultado, responsable, pdf_url,
                       DATE_FORMAT(created_at,'%d/%m/%Y') AS fecha
                FROM auditorias ORDER BY anio DESC, trimestre DESC
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) { $rows = []; }
        foreach ($rows as &$r) { $r['pdf_url'] = $r['pdf_url'] ? (rtrim(SITE_URL,'/').'/'.ltrim($r['pdf_url'],'/')) : ''; }
        return ['status' => 'success', 'auditorias' => $rows, 'pendientes' => $this->pendientes((int)date('Y')), 'trimestre_actual' => (int)ceil((int)date('n')/3)];
    }

    public function detalle(int $id): array {
        $s = $this->pdo->prepare("SELECT * FROM auditorias WHERE id = ?");
        $s->execute([$id]);
        $a = $s->fetch(PDO::FETCH_ASSOC);
        if (!$a) return ['status' => 'error', 'message' => 'Auditoría no encontrada.'];
        $a['hallazgos'] = json_decode($a['hallazgos'] ?? '[]', true) ?: [];
        $a['stats']     = json_decode($a['stats'] ?? '{}', true) ?: [];
        $a['pdf_url']   = $a['pdf_url'] ? (rtrim(SITE_URL,'/').'/'.ltrim($a['pdf_url'],'/')) : '';
        return ['status' => 'success', 'auditoria' => $a];
    }

    public function crear(array $data, array $usr): array {
        $anio = (int)($data['anio'] ?? date('Y'));
        $trim = (int)($data['trimestre'] ?? 0);
        if ($trim < 1 || $trim > 4) return ['status' => 'error', 'message' => 'Trimestre inválido.'];

        // Único por periodo
        $ex = $this->pdo->prepare("SELECT id FROM auditorias WHERE anio=? AND trimestre=?");
        $ex->execute([$anio, $trim]);
        if ($ex->fetch()) return ['status' => 'error', 'message' => "Ya existe la auditoría de {$this->trimLabel($trim)} {$anio}."];

        $resultado = trim($data['resultado'] ?? 'Satisfactoria');
        $obs = trim($data['observaciones'] ?? '');
        $hallazgos = $data['hallazgos'] ?? [];
        if (is_string($hallazgos)) $hallazgos = json_decode($hallazgos, true) ?: [];
        $hallazgos = array_values(array_filter($hallazgos, fn($h) => trim($h['descripcion'] ?? '') !== ''));

        $v = $this->computeVigencias();
        $resp = $usr['nombre'] ?: ($usr['usuario'] ?? 'Calidad');

        $this->pdo->prepare(
            "INSERT INTO auditorias (anio,trimestre,periodo,resultado,observaciones,hallazgos,stats,responsable)
             VALUES (?,?,?,?,?,?,?,?)"
        )->execute([$anio, $trim, $this->trimLabel($trim), $resultado, $obs,
                    json_encode($hallazgos, JSON_UNESCAPED_UNICODE), json_encode($v, JSON_UNESCAPED_UNICODE), $resp]);
        $id = (int)$this->pdo->lastInsertId();

        $pdf = $this->pdfAuditoria($id);
        if ($pdf) $this->pdo->prepare("UPDATE auditorias SET pdf_url=? WHERE id=?")->execute([$pdf, $id]);

        return ['status' => 'success', 'id' => $id, 'pdf_url' => $pdf ? (rtrim(SITE_URL,'/').'/'.ltrim($pdf,'/')) : ''];
    }

    public function eliminar(int $id): array {
        $this->pdo->prepare("DELETE FROM auditorias WHERE id=?")->execute([$id]);
        return ['status' => 'success'];
    }

    // ════════════════════════════════════════════════════════
    //  INFORME DE INSPECCIONES (Excel — plantilla UI_INFORME_001)
    // ════════════════════════════════════════════════════════

    /**
     * El alcance acreditado que se le reporta a la ema.
     *
     * AVBA inspecciona más cosas de las que tiene acreditadas, y a la ema sólo
     * le corresponden las grúas montadas sobre camión y sobre neumáticos. Meter
     * de más es reportar fuera de alcance; meter de menos es omitir trabajo que
     * sí hay que declarar. Por eso el informe filtra, y de paso dice qué dejó
     * fuera: una omisión silenciosa es lo peor que puede pasarle a un entregable
     * ante la autoridad.
     *
     * Se compara por nombre porque equipos.maquinaria guarda el nombre del tipo,
     * no su id. Cada patrón se busca dentro del nombre ya normalizado, así que
     * 'CAMION' encuentra "GRÚA HIDRÁULICA SOBRE CAMIÓN" y 'NEUMATICO' encuentra
     * tanto "SOBRE NEUMÁTICOS" como "SOBRE NEUMÁTICO".
     */
    private const ALCANCE_EMA = ['CAMION', 'NEUMATICO'];

    /**
     * Sin acentos, sin mayúsculas y sin espacios de más.
     *
     * Los nombres de los tipos los escribe una persona en el panel: "Grúa sobre
     * neumáticos", "GRUA SOBRE NEUMATICOS" y "grúa  sobre  neumáticos" son el
     * mismo tipo y las tres tienen que entrar al informe.
     */
    private static function normalizar(string $s): string {
        $s = mb_strtoupper(trim($s), 'UTF-8');
        $s = strtr($s, ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N']);
        return preg_replace('/\s+/', ' ', $s);
    }

    /**
     * ¿Este tipo de equipo va en el informe de la ema?
     *
     * Tiene que ser una grúa Y estar montada sobre camión o sobre neumáticos.
     * Se exigen las dos cosas para que un día que alguien dé de alta, por
     * ejemplo, un montacargas sobre neumáticos, no se cuele al informe.
     */
    private static function enAlcanceEma(string $maquinaria): bool {
        $n = self::normalizar($maquinaria);
        if (strpos($n, 'GRUA') === false) return false;
        foreach (self::ALCANCE_EMA as $patron) {
            if (strpos($n, $patron) !== false) return true;
        }
        return false;
    }

    /** Normaliza una fecha de la pantalla al formato de la base. */
    private static function fechaDb(string $f, string $siVacia): string {
        return $f !== '' ? date('Y-m-d', strtotime(str_replace('/', '-', $f))) : $siVacia;
    }

    /**
     * Las inspecciones cerradas de un periodo, con su tipo de equipo.
     *
     * La usan el listado de la pantalla y la generación del Excel, a propósito:
     * si cada uno armara su consulta, un día enseñarían cosas distintas y el
     * archivo entregado no sería el que se revisó antes de entregarlo.
     */
    private function inspeccionesDelPeriodo(string $desdeDb, string $hastaDb): array {
        /* La hora capturada manda; si no hay, la marca del registro, como
           siempre. Se comprueba que la columna exista porque en una base donde
           la migración todavía no corrió, nombrarla rompería la consulta entera
           —y con ella la pantalla— en vez de dar una hora menos exacta. */
        $horaCol = columnaExiste($this->pdo, 'equipos', 'hora_inspeccion')
            ? 'COALESCE(e.hora_inspeccion, e.marca_temporal)'
            : 'e.marca_temporal';

        $s = $this->pdo->prepare("
            SELECT e.id AS equipo_id, e.id_equipo, e.cliente, e.direccion, e.control,
                   e.estado, e.maquinaria, e.marca, e.modelo, e.serie,
                   COALESCE(NULLIF(u.nombre,''), e.inspector) AS inspector,
                   DATE_FORMAT(e.fecha_inspeccion,'%d/%m/%Y') AS fecha,
                   TIME_FORMAT({$horaCol},'%H:%i') AS hora
            FROM equipos e
            LEFT JOIN usuarios u ON u.usuario COLLATE utf8mb4_general_ci = e.inspector
            WHERE e.fecha_inspeccion BETWEEN ? AND ?
              AND e.estado IN ('CONFORME','NO CONFORME','ENVIADO','APROBADO CALIDAD','RETORNADO','RECHAZADO')
            ORDER BY e.fecha_inspeccion ASC, e.id ASC
        ");
        $s->execute([$desdeDb, $hastaDb]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Separa lo del alcance acreditado de lo que no, contando por tipo. */
    private function partirPorAlcance(array $rows): array {
        $dentro = []; $incluidos = []; $omitidos = [];
        foreach ($rows as $r) {
            $tipo  = trim((string)($r['maquinaria'] ?? ''));
            $clave = $tipo !== '' ? $tipo : '(sin tipo)';
            if (self::enAlcanceEma($tipo)) {
                $dentro[] = $r;
                $incluidos[$clave] = ($incluidos[$clave] ?? 0) + 1;
            } else {
                $omitidos[$clave] = ($omitidos[$clave] ?? 0) + 1;
            }
        }
        arsort($incluidos); arsort($omitidos);
        return ['dentro' => $dentro, 'incluidos' => $incluidos, 'omitidos' => $omitidos];
    }

    /**
     * En qué informes anteriores salió ya cada equipo.
     *
     * Se consulta para poder avisarlo en el listado. Repetir un equipo en dos
     * trimestres no está prohibido —una grúa se puede inspeccionar dos veces al
     * año—, pero repetir la MISMA inspección sí es un error, y es de los que no
     * se notan al revisar el Excel.
     *
     * @return array equipo_id => [['anio'=>, 'trimestre'=>, 'generado'=>], …]
     */
    private function reportadosAntes(array $equipoIds): array {
        if (!$equipoIds) return [];
        $hueco = implode(',', array_fill(0, count($equipoIds), '?'));
        try {
            $s = $this->pdo->prepare("
                SELECT d.equipo_id, i.id AS informe_id, i.anio, i.trimestre,
                       DATE_FORMAT(i.created_at,'%d/%m/%Y') AS generado
                FROM informes_inspecciones_detalle d
                INNER JOIN informes_inspecciones i ON i.id = d.informe_id
                WHERE d.equipo_id IN ($hueco)
                ORDER BY i.id ASC
            ");
            $s->execute(array_values($equipoIds));
        } catch (\PDOException $e) {
            error_log('[Auditorias] reportadosAntes: ' . $e->getMessage());
            return [];
        }
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['equipo_id']][] = [
                'informe_id' => (int)$r['informe_id'],
                'anio'       => $r['anio'] !== null ? (int)$r['anio'] : null,
                'trimestre'  => $r['trimestre'] !== null ? (int)$r['trimestre'] : null,
                'generado'   => (string)$r['generado'],
            ];
        }
        return $out;
    }

    /**
     * Si el rango es exactamente un trimestre, cuál. Si no, nulo.
     *
     * Se guarda para que el historial pueda decir "T3 2026" en vez de dos
     * fechas, que es como lo pide la ema y como lo piensa quien lo entrega.
     */
    private static function trimestreDe(string $desdeDb, string $hastaDb): array {
        $d = strtotime($desdeDb); $h = strtotime($hastaDb);
        if (!$d || !$h) return [null, null];
        $anio = (int)date('Y', $d);
        if ((int)date('Y', $h) !== $anio) return [null, null];
        $mi = (int)date('n', $d); $mf = (int)date('n', $h);
        if ((int)date('j', $d) !== 1 || $mi % 3 !== 1) return [null, null];
        if ($mf !== $mi + 2 || (int)date('j', $h) !== (int)date('t', $h)) return [null, null];
        return [$anio, intdiv($mi, 3) + 1];
    }

    /**
     * Las grúas del alcance que caen en el periodo, para elegir cuáles entran.
     *
     * Enseñarlas antes de generar el archivo no es un lujo: el informe va a la
     * autoridad y quien lo firma tiene que ver qué va dentro, no enterarse al
     * abrir el Excel.
     */
    public function equiposAlcance(string $desde, string $hasta): array {
        try {
            return $this->equiposAlcanceInterno($desde, $hasta);
        } catch (\Throwable $e) {
            // Cualquier tropiezo aquí tiene que salir como JSON con su causa.
            // Si se deja escapar, PHP contesta una página de error y la pantalla
            // sólo puede decir "algo falló", que no se arregla con nada.
            error_log('[Auditorias] equiposAlcance: ' . $e->getMessage()
                    . ' en ' . $e->getFile() . ':' . $e->getLine());
            return ['status' => 'error',
                'message' => 'No se pudo armar la lista de grúas: ' . $e->getMessage()];
        }
    }

    private function equiposAlcanceInterno(string $desde, string $hasta): array {
        $desdeDb = self::fechaDb($desde, date('Y-01-01'));
        $hastaDb = self::fechaDb($hasta, date('Y-m-d'));

        $rows = $this->inspeccionesDelPeriodo($desdeDb, $hastaDb);
        $p = $this->partirPorAlcance($rows);
        $ya = $this->reportadosAntes(array_column($p['dentro'], 'equipo_id'));

        $equipos = [];
        foreach ($p['dentro'] as $r) {
            $id = (int)$r['equipo_id'];
            $dictamen  = self::folioDictamen($r);
            $inspector = (string)($r['inspector'] ?? '');
            $equipos[] = [
                'equipo_id'  => $id,
                'id_equipo'  => (string)($r['id_equipo'] ?? ''),
                'cliente'    => (string)($r['cliente'] ?? ''),
                'maquinaria' => (string)($r['maquinaria'] ?? ''),
                'marca'      => trim(((string)($r['marca'] ?? '')) . ' ' . ((string)($r['modelo'] ?? ''))),
                'serie'      => (string)($r['serie'] ?? ''),
                'fecha'      => (string)($r['fecha'] ?? ''),
                'hora'       => (string)($r['hora'] ?? ''),
                'solicitud'  => self::numeroSolicitud($r),
                'dictamen'   => $dictamen,
                'resultado'  => self::resultadoDe($r),
                'inspector'  => $inspector,
                'reportado'  => $ya[$id] ?? [],
                'faltas'     => self::faltasParaEma($r, $dictamen, $inspector),
            ];
        }

        [$anio, $trimestre] = self::trimestreDe($desdeDb, $hastaDb);
        $previos = $this->informePrevio($desdeDb, $hastaDb, $anio, $trimestre);

        return ['status' => 'success', 'equipos' => $equipos,
                'incluidos' => $p['incluidos'], 'omitidos' => $p['omitidos'],
                'anio' => $anio, 'trimestre' => $trimestre,
                // Para avisar que generar otro reemplaza al que ya está.
                'previo' => $previos ? [
                    'total'    => (int)$previos[0]['total'],
                    'generado' => (string)$previos[0]['generado'],
                    'cuantos'  => count($previos),
                ] : null];
    }

    /**
     * El número con el que AVBA registró el servicio: columna B.
     *
     * Es el folio de control —cliente más consecutivo, 45155-1024—, que es lo
     * que identifica la solicitud de inspección. Antes aquí iba id_equipo, que
     * es el número económico del equipo: identifica la grúa, no el servicio, y
     * dos inspecciones de la misma grúa en el año habrían salido con el mismo
     * "número de solicitud".
     */
    private static function numeroSolicitud(array $r): string {
        return trim((string)($r['control'] ?? ''));
    }

    /**
     * El nombre con el que se descarga el informe, en la nomenclatura de la ema.
     *
     * Su plantilla es UI_INFORME_001 y el número corresponde al trimestre, así
     * que el de Jul–Sep se entrega como UI_INFORME_003.xlsx.
     *
     * No es el nombre con el que se guarda en el servidor: ahí hace falta uno
     * único, porque el T3 de 2027 se llamaría igual que el de 2026 y lo
     * pisaría. El archivo guardado lleva periodo y marca de tiempo; éste es
     * sólo el de la descarga.
     *
     * Para un rango libre devuelve cadena vacía: esa nomenclatura es de las
     * entregas trimestrales, y ponerle un número de trimestre a un rango que no
     * lo es sería decir que es algo que no es.
     */
    public static function nombreEma(?int $anio, ?int $trimestre): string {
        if ($anio === null || $trimestre === null) return '';
        return 'UI_INFORME_' . str_pad((string)$trimestre, 3, '0', STR_PAD_LEFT) . '.xlsx';
    }

    /** Folio del dictamen: AB.<control>-<año>MX */
    private static function folioDictamen(array $r): string {
        $ctrl = trim((string)($r['control'] ?? ''));
        if ($ctrl === '') return '';
        $anio = substr((string)($r['fecha'] ?? ''), -4);
        if (!preg_match('/^\d{4}$/', $anio)) $anio = date('Y');
        return 'AB.' . $ctrl . '-' . $anio . 'MX';
    }

    /**
     * Lo que el portal de la ema rechazaría de este renglón.
     *
     * Su validador exige la fecha como dd/mm/aaaa, la hora como hh:mm y el
     * nombre completo del inspector acreditado. Descubrirlo al subir el archivo
     * significa corregir en el Excel a mano y volver a empezar; aquí se ve antes
     * de generarlo, cuando todavía se puede arreglar en el sistema.
     *
     * @return string[] Vacío si el renglón va bien.
     */
    private static function faltasParaEma(array $r, string $dictamen, string $inspector): array {
        $faltas = [];
        if (!preg_match('/^\d{2}\/\d{2}\/\d{4}$/', (string)($r['fecha'] ?? ''))) {
            $faltas[] = 'sin fecha de inspección';
        }
        if (!preg_match('/^\d{2}:\d{2}$/', (string)($r['hora'] ?? ''))) {
            $faltas[] = 'sin hora de inicio';
        }
        if (trim($inspector) === '')  $faltas[] = 'sin nombre del inspector';
        if (trim($dictamen) === '')   $faltas[] = 'sin número de dictamen';
        if (self::numeroSolicitud($r) === '') $faltas[] = 'sin número de solicitud';
        if (trim((string)($r['cliente'] ?? '')) === '')   $faltas[] = 'sin razón social';
        if (trim((string)($r['direccion'] ?? '')) === '') $faltas[] = 'sin domicilio';
        return $faltas;
    }

    /** El resultado, normalizado a los dos valores que acepta el formato. */
    private static function resultadoDe(array $r): string {
        $estado = strtoupper(trim((string)($r['estado'] ?? '')));
        return in_array($estado, ['NO CONFORME', 'RECHAZADO'], true) ? 'NO CONFORME' : 'CONFORME';
    }

    /**
     * Genera el Excel de informe de inspecciones (formato UI_INFORME_001)
     * lleno con las inspecciones en el rango de fechas indicado.
     *
     * El archivo lleva exactamente los equipos que se eligieron, ni uno más.
     * La lista es obligatoria a propósito: generar "todo lo del periodo" sin que
     * nadie lo haya mirado es justo la confusión que no puede haber en un
     * documento que se entrega a la autoridad. Para incluirlos todos está el
     * botón de marcar todas, que es una decisión tomada y no una omisión.
     *
     * De lo elegido, además, sólo pasa lo que de verdad está en el periodo y en
     * el alcance acreditado. El formato de la plantilla no se toca: se copia el
     * archivo tal cual y sólo se le agregan renglones, así que lo que se
     * descarga es el mismo formato que pide la ema.
     *
     * @param int[] $soloIds Equipos elegidos en la pantalla. Obligatorio.
     */
    public function generarInforme(string $desde, string $hasta, string $responsable,
                                   array $soloIds = []): array {
        try {
            return $this->generarInformeInterno($desde, $hasta, $responsable, $soloIds);
        } catch (\Throwable $e) {
            error_log('[Auditorias] generarInforme: ' . $e->getMessage()
                    . ' en ' . $e->getFile() . ':' . $e->getLine());
            return ['status' => 'error',
                'message' => 'No se pudo generar el informe: ' . $e->getMessage()];
        }
    }

    private function generarInformeInterno(string $desde, string $hasta, string $responsable,
                                           array $soloIds): array {
        if (!$soloIds) {
            return ['status' => 'error',
                'message' => 'Elige en la pantalla cuáles grúas van en el informe. '
                           . 'Oprime "Ver equipos", marca las que correspondan y vuelve a generar.'];
        }
        $desdeDb = self::fechaDb($desde, date('Y-01-01'));
        $hastaDb = self::fechaDb($hasta, date('Y-m-d'));

        $rows = $this->inspeccionesDelPeriodo($desdeDb, $hastaDb);
        if (!$rows) return ['status' => 'error', 'message' => 'No hay inspecciones en el rango seleccionado.'];

        $p = $this->partirPorAlcance($rows);
        $incluidos = $p['incluidos'];
        $omitidos  = $p['omitidos'];
        $enAlcance = $p['dentro'];

        if (!$enAlcance) {
            $fuera = [];
            foreach ($omitidos as $t => $n) $fuera[] = $t . ' (' . $n . ')';
            return ['status' => 'error',
                'message' => 'En ese periodo no hay inspecciones del alcance acreditado '
                           . '(grúas sobre camión o sobre neumáticos).'
                           . ($fuera ? ' Sí hay, fuera de alcance: ' . implode(', ', $fuera) . '.' : ''),
                'omitidos' => $omitidos];
        }

        // La elección de la pantalla, filtrada contra lo que de verdad está en el
        // periodo y en el alcance: un id que llegue de fuera de esa lista no
        // entra, venga de donde venga.
        $pedidos  = array_flip(array_map('intval', $soloIds));
        $elegidos = array_values(array_filter($enAlcance,
            fn($r) => isset($pedidos[(int)$r['equipo_id']])));
        if (!$elegidos) {
            return ['status' => 'error',
                'message' => 'Ninguna de las grúas elegidas está en el periodo y en el alcance. '
                           . 'Vuelve a oprimir "Ver equipos" y marca de nuevo.'];
        }

        // Se recuenta sobre lo elegido, no sobre todo el periodo: el resumen
        // tiene que describir el archivo que salió.
        $incluidos = [];
        foreach ($elegidos as $r) {
            $t = trim((string)($r['maquinaria'] ?? '')) ?: '(sin tipo)';
            $incluidos[$t] = ($incluidos[$t] ?? 0) + 1;
        }
        arsort($incluidos);
        $enAlcance = $elegidos;

        // Mapear cada inspección al orden de columnas de la plantilla (A..J)
        $data = [];
        foreach ($enAlcance as $r) {
            $data[] = [
                $responsable,                    // A Nombre de la persona responsable de la información
                self::numeroSolicitud($r),       // B Número de solicitud
                (string)($r['cliente'] ?? ''),   // C Razón social
                (string)($r['direccion'] ?? ''), // D Domicilio de la inspección
                (string)($r['fecha'] ?? ''),     // E Fecha de inspección
                (string)($r['hora'] ?? ''),      // F Hora de inicio
                self::folioDictamen($r),         // G Número de dictamen
                self::resultadoDe($r),           // H Resultado de la inspección
                (string)($r['inspector'] ?? ''), // I Inspector(es)
                '',                              // J Persona(s) de apoyo (manual)
            ];
        }

        $plantilla = dirname(__DIR__) . '/assets/plantillas/UI_INFORME_001.xlsx';
        if (!is_file($plantilla)) return ['status' => 'error', 'message' => 'Plantilla no encontrada en el servidor.'];

        $dir = rtrim(UPLOAD_DIR, '/') . '/auditorias/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        [$anioT, $trimestre] = self::trimestreDe($desdeDb, $hastaDb);

        // El periodo va en el nombre: en la carpeta de descargas, "T3_2026"
        // dice de un vistazo cuál es, y "20261028_143022" no dice nada.
        $etiqueta = ($anioT !== null && $trimestre !== null)
            ? 'T' . $trimestre . '_' . $anioT
            : str_replace('-', '', $desdeDb) . '_a_' . str_replace('-', '', $hastaDb);
        $nombre  = 'Informe_Inspecciones_' . $etiqueta . '_' . date('Ymd_His') . '.xlsx';
        $destAbs = $dir . $nombre;

        if (!$this->llenarPlantillaXlsx($plantilla, $destAbs, $data)) {
            return ['status' => 'error', 'message' => 'No se pudo generar el archivo Excel.'];
        }

        /* De cada periodo se guarda un solo informe: el que se entrega. Los
           anteriores se borran aquí, con su archivo y su detalle, y nótese que
           es DESPUÉS de haber escrito el nuevo: si la generación hubiera
           fallado, lo que había sigue en su lugar. Quedarse sin ninguno la
           víspera de la entrega sería peor que conservar el viejo. */
        $reemplazados = $this->borrarInformesPrevios(
            $this->informePrevio($desdeDb, $hastaDb, $anioT, $trimestre), $dir, $destAbs);

        // Registrar el informe en el historial, con su trimestre y con el
        // detalle de qué equipos llevó: sin el detalle no hay forma de saber
        // después si una inspección quedó sin declarar.
        $informeId = 0;
        try {
            $this->pdo->prepare(
                "INSERT INTO informes_inspecciones (desde, hasta, anio, trimestre, total, responsable, archivo)
                 VALUES (?,?,?,?,?,?,?)"
            )->execute([$desdeDb, $hastaDb, $anioT, $trimestre, count($data),
                        $responsable, 'uploads/auditorias/' . $nombre]);
            $informeId = (int)$this->pdo->lastInsertId();
        } catch (\PDOException $e) {
            error_log('[Auditorias] registrar informe: ' . $e->getMessage());
        }

        if ($informeId > 0) {
            try {
                $d = $this->pdo->prepare(
                    "INSERT IGNORE INTO informes_inspecciones_detalle (informe_id, equipo_id, control)
                     VALUES (?,?,?)"
                );
                foreach ($enAlcance as $r) {
                    $d->execute([$informeId, (int)$r['equipo_id'], (string)($r['control'] ?? '')]);
                }
            } catch (\PDOException $e) {
                // El archivo ya está generado y registrado; perder el detalle
                // empobrece el historial pero no invalida el informe.
                error_log('[Auditorias] detalle del informe: ' . $e->getMessage());
            }
        }

        // Se devuelve el id (para descargar por navegación vía descargar.php,
        // fiable en móvil) y también el contenido en base64 como respaldo.
        $bytes = @file_get_contents($destAbs);
        return [
            'status'    => 'success',
            'total'     => count($data),
            'id'        => $informeId,
            // Dos nombres distintos, dichos por separado para que no se confundan:
            // con cuál se entrega, y cuál es el archivo guardado en el servidor.
            'filename'  => self::nombreEma($anioT, $trimestre) ?: $nombre,
            'archivo'   => $nombre,
            'anio'      => $anioT,
            'trimestre' => $trimestre,
            'reemplazados' => $reemplazados,
            'incluidos' => $incluidos,
            'omitidos'  => $omitidos,
            'b64'       => $bytes !== false ? base64_encode($bytes) : '',
        ];
    }

    /**
     * El informe que ya existe para este periodo, si lo hay.
     *
     * De cada trimestre se guarda uno solo: el que se entrega. Tener dos
     * versiones del mismo periodo es la forma más fácil de subir la que no era.
     *
     * Se busca por trimestre cuando el rango es uno; para un rango libre, por
     * sus fechas exactas, que es lo que lo identifica.
     */
    private function informePrevio(string $desdeDb, string $hastaDb, ?int $anio, ?int $trimestre): array {
        try {
            if ($anio !== null && $trimestre !== null) {
                $s = $this->pdo->prepare(
                    "SELECT id, archivo, total, DATE_FORMAT(created_at,'%d/%m/%Y %H:%i') AS generado
                       FROM informes_inspecciones WHERE anio = ? AND trimestre = ? ORDER BY id ASC");
                $s->execute([$anio, $trimestre]);
            } else {
                $s = $this->pdo->prepare(
                    "SELECT id, archivo, total, DATE_FORMAT(created_at,'%d/%m/%Y %H:%i') AS generado
                       FROM informes_inspecciones
                      WHERE desde = ? AND hasta = ? AND anio IS NULL ORDER BY id ASC");
                $s->execute([$desdeDb, $hastaDb]);
            }
            return $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log('[Auditorias] informePrevio: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Borra los informes anteriores de un periodo, con su archivo y su detalle.
     *
     * Se llama DESPUÉS de haber escrito el archivo nuevo, nunca antes: si algo
     * falla al generarlo, lo que había se queda. Es preferible tener el informe
     * viejo a quedarse sin ninguno la víspera de la entrega.
     *
     * El nombre del archivo se reconstruye con basename sobre la carpeta de
     * informes, así que un valor manipulado en la base no puede hacer que se
     * borre algo fuera de ahí.
     *
     * $recienEscrito no se borra nunca. Dos informes del mismo periodo
     * generados dentro del mismo segundo caen en el mismo nombre de archivo:
     * el segundo sobrescribe al primero —que es lo correcto, el contenido ya es
     * el nuevo— y sin esta guarda, borrar "el anterior" se llevaría el archivo
     * recién hecho y el informe quedaría registrado sin nada que descargar.
     */
    private function borrarInformesPrevios(array $previos, string $dir, string $recienEscrito = ''): int {
        $borrados = 0;
        foreach ($previos as $p) {
            $id = (int)$p['id'];
            $archivo = $dir . basename((string)($p['archivo'] ?? ''));
            if ($archivo !== $recienEscrito && is_file($archivo) && !@unlink($archivo)) {
                error_log('[Auditorias] no se pudo borrar ' . $archivo);
            }
            try {
                $this->pdo->prepare("DELETE FROM informes_inspecciones_detalle WHERE informe_id = ?")->execute([$id]);
                $this->pdo->prepare("DELETE FROM informes_inspecciones WHERE id = ?")->execute([$id]);
                $borrados++;
            } catch (\PDOException $e) {
                error_log('[Auditorias] borrar informe ' . $id . ': ' . $e->getMessage());
            }
        }
        return $borrados;
    }

    /**
     * El historial de informes entregados.
     *
     * Cada uno con su trimestre, para poder leerlo como lo pide la ema —"T3
     * 2026"— y no como dos fechas sueltas.
     */
    public function listarInformes(): array {
        try {
            $rows = $this->pdo->query(
                "SELECT id, total, archivo, anio, trimestre,
                        DATE_FORMAT(desde,'%d/%m/%Y') AS desde,
                        DATE_FORMAT(hasta,'%d/%m/%Y') AS hasta,
                        DATE_FORMAT(created_at,'%d/%m/%Y %H:%i') AS generado
                 FROM informes_inspecciones
                 ORDER BY id DESC LIMIT 100"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return ['status' => 'success', 'data' => []];
        }
        foreach ($rows as &$r) {
            $r['periodo'] = ($r['anio'] && $r['trimestre'])
                ? ('T' . (int)$r['trimestre'] . ' ' . (int)$r['anio'] . ' · ' . self::MESES_TRI[(int)$r['trimestre']])
                : ($r['desde'] . ' – ' . $r['hasta']);
        }
        unset($r);
        return ['status' => 'success', 'data' => $rows];
    }

    /** Los meses de cada trimestre, para rotular el historial. */
    private const MESES_TRI = [1 => 'Ene–Mar', 2 => 'Abr–Jun', 3 => 'Jul–Sep', 4 => 'Oct–Dic'];

    /**
     * Qué equipos llevó un informe ya entregado.
     *
     * Es la pregunta que no se podía contestar antes: el historial decía cuántos
     * eran, no cuáles. Ante la ema, "creo que sí lo reportamos" no es respuesta.
     */
    public function detalleInforme(int $id): array {
        if ($id <= 0) return ['status' => 'error', 'message' => 'Informe no válido.'];
        try {
            $s = $this->pdo->prepare("
                SELECT d.equipo_id, d.control,
                       e.id_equipo, e.cliente, e.maquinaria, e.marca, e.modelo, e.serie,
                       DATE_FORMAT(e.fecha_inspeccion,'%d/%m/%Y') AS fecha
                FROM informes_inspecciones_detalle d
                LEFT JOIN equipos e ON e.id = d.equipo_id
                WHERE d.informe_id = ?
                ORDER BY e.fecha_inspeccion ASC, d.id ASC
            ");
            $s->execute([$id]);
            $filas = $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log('[Auditorias] detalleInforme: ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'No se pudo leer el detalle del informe.'];
        }
        foreach ($filas as &$f) {
            $f['equipo'] = trim(((string)($f['marca'] ?? '')) . ' ' . ((string)($f['modelo'] ?? '')));
            // Un informe de hace dos años puede apuntar a un equipo que ya se
            // borró: se dice, en vez de enseñar un renglón en blanco.
            if ($f['id_equipo'] === null) $f['id_equipo'] = '(el equipo ya no está en el sistema)';
        }
        unset($f);
        return ['status' => 'success', 'data' => $filas];
    }

    /** Devuelve un informe guardado en base64 para descargarlo de nuevo. */
    public function descargarInforme(int $id): array {
        $stmt = $this->pdo->prepare("SELECT archivo, anio, trimestre FROM informes_inspecciones WHERE id = ?");
        $stmt->execute([$id]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $rel  = (string)($fila['archivo'] ?? '');
        if ($rel === '') return ['status' => 'error', 'message' => 'Informe no encontrado.'];

        $abs = dirname(__DIR__) . '/' . ltrim($rel, '/');
        if (!is_file($abs)) return ['status' => 'error', 'message' => 'El archivo ya no está disponible en el servidor.'];

        $bytes = @file_get_contents($abs);
        if ($bytes === false) return ['status' => 'error', 'message' => 'No se pudo leer el archivo.'];

        $ema = self::nombreEma(
            isset($fila['anio']) && $fila['anio'] !== null ? (int)$fila['anio'] : null,
            isset($fila['trimestre']) && $fila['trimestre'] !== null ? (int)$fila['trimestre'] : null);

        return [
            'status'   => 'success',
            'filename' => $ema ?: basename($abs),
            'b64'      => base64_encode($bytes),
        ];
    }

    /** Inyecta los renglones de datos en la plantilla xlsx (preserva estilos). */
    private function llenarPlantillaXlsx(string $plantilla, string $dest, array $data): bool {
        if (!class_exists('ZipArchive')) return false;
        if (!@copy($plantilla, $dest)) return false;

        $zip = new \ZipArchive();
        if ($zip->open($dest) !== true) return false;
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($sheet === false) { $zip->close(); return false; }

        $esc = function($v) {
            return str_replace(['&','<','>','"',"'"], ['&amp;','&lt;','&gt;','&quot;','&apos;'], (string)$v);
        };
        $rowsXml = '';
        $r = 2;
        foreach ($data as $fila) {
            $rowsXml .= '<row r="' . $r . '" spans="1:10">';
            $col = 'A';
            foreach ($fila as $val) {
                $rowsXml .= '<c r="' . $col . $r . '" t="inlineStr"><is><t xml:space="preserve">'
                          . $esc($val) . '</t></is></c>';
                $col++;
            }
            $rowsXml .= '</row>';
            $r++;
        }

        $sheet = str_replace('</sheetData>', $rowsXml . '</sheetData>', $sheet);
        $sheet = preg_replace('/<dimension ref="[^"]*"\/>/', '<dimension ref="A1:J' . ($r - 1) . '"/>', $sheet);

        $zip->deleteName('xl/worksheets/sheet1.xml');
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        return $zip->close();
    }

    // ── PDF ──────────────────────────────────────────────────
    private function assetB64(string $rel): string {
        $path = dirname(__DIR__) . '/assets/' . ltrim($rel, '/');
        if (!is_file($path)) return '';
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = in_array($ext, ['jpg','jpeg']) ? 'image/jpeg' : 'image/png';
        return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($path));
    }

    private function pdfAuditoria(int $id): ?string {
        try {
            $s = $this->pdo->prepare("SELECT * FROM auditorias WHERE id=?");
            $s->execute([$id]);
            $a = $s->fetch(PDO::FETCH_ASSOC);
            if (!$a) return null;
            $v = json_decode($a['stats'] ?? '{}', true) ?: [];
            $hallazgos = json_decode($a['hallazgos'] ?? '[]', true) ?: [];
            $esc = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');

            if (!class_exists('\\Mpdf\\Mpdf')) {
                $autoload = __DIR__ . '/../vendor/autoload.php';
                if (file_exists($autoload)) require_once $autoload;
            }
            if (!class_exists('\\Mpdf\\Mpdf')) return null;

            $logo = $this->assetB64('logos/avba.png');
            $logoHtml = $logo ? '<img src="' . $logo . '" style="max-height:50px">' : '<strong>AVBA</strong>';

            $eq = $v['equipos'] ?? []; $pe = $v['personal'] ?? [];
            $tabla = function($t, $s2) use ($esc) {
                return '<table style="width:100%;border-collapse:collapse;font-size:9.5pt;margin:4px 0 10px">
                    <tr><th style="background:#0d2244;color:#fff;padding:5px;text-align:left">' . $t . '</th>
                    <th style="background:#0d2244;color:#fff;padding:5px">Total</th>
                    <th style="background:#166534;color:#fff;padding:5px">Vigentes</th>
                    <th style="background:#92400e;color:#fff;padding:5px">Por vencer</th>
                    <th style="background:#991b1b;color:#fff;padding:5px">Vencidos</th></tr>
                    <tr><td style="padding:5px;border:1px solid #e3e8f0">Certificaciones</td>
                    <td style="padding:5px;border:1px solid #e3e8f0;text-align:center">' . (int)($s2['total']??0) . '</td>
                    <td style="padding:5px;border:1px solid #e3e8f0;text-align:center">' . (int)($s2['vigentes']??0) . '</td>
                    <td style="padding:5px;border:1px solid #e3e8f0;text-align:center">' . (int)($s2['por_vencer']??0) . '</td>
                    <td style="padding:5px;border:1px solid #e3e8f0;text-align:center">' . (int)($s2['vencidos']??0) . '</td></tr></table>';
            };

            $detFilas = '';
            foreach (array_merge($v['detalle']['equipos'] ?? [], $v['detalle']['personal'] ?? []) as $d) {
                $col = $d['dias'] < 0 ? '#991b1b' : '#92400e';
                $detFilas .= '<tr><td style="padding:4px;border-bottom:1px solid #eee">' . $esc($d['nombre']) . '</td>'
                    . '<td style="padding:4px;border-bottom:1px solid #eee">' . $esc($d['ref']) . '</td>'
                    . '<td style="padding:4px;border-bottom:1px solid #eee">' . $esc($d['para']) . '</td>'
                    . '<td style="padding:4px;border-bottom:1px solid #eee;color:' . $col . ';font-weight:bold">' . $esc($d['estado']) . ' (' . (int)$d['dias'] . 'd)</td></tr>';
            }
            $detBloque = $detFilas
                ? '<div style="font-weight:bold;color:#0d2244;margin:10px 0 4px">Detalle de vencidos / por vencer</div>
                   <table style="width:100%;border-collapse:collapse;font-size:8.5pt"><tr style="color:#667"><th style="text-align:left;padding:4px">Elemento</th><th style="text-align:left;padding:4px">Folio</th><th style="text-align:left;padding:4px">Cliente/Empresa</th><th style="text-align:left;padding:4px">Estado</th></tr>' . $detFilas . '</table>'
                : '<p style="font-size:9.5pt;color:#166534">Sin certificaciones vencidas ni por vencer en el periodo. ✓</p>';

            $hallFilas = '';
            foreach ($hallazgos as $i => $h) {
                $hallFilas .= '<tr><td style="padding:4px;border-bottom:1px solid #eee;text-align:center">' . ($i+1) . '</td>'
                    . '<td style="padding:4px;border-bottom:1px solid #eee">' . $esc($h['categoria'] ?? '') . '</td>'
                    . '<td style="padding:4px;border-bottom:1px solid #eee">' . $esc($h['descripcion'] ?? '') . '</td>'
                    . '<td style="padding:4px;border-bottom:1px solid #eee">' . $esc($h['severidad'] ?? '') . '</td></tr>';
            }
            $hallBloque = $hallFilas
                ? '<table style="width:100%;border-collapse:collapse;font-size:9pt"><tr style="color:#667"><th style="padding:4px">#</th><th style="text-align:left;padding:4px">Categoría</th><th style="text-align:left;padding:4px">Hallazgo</th><th style="text-align:left;padding:4px">Severidad</th></tr>' . $hallFilas . '</table>'
                : '<p style="font-size:9.5pt;color:#666">Sin hallazgos registrados.</p>';

            $resColor = stripos($a['resultado'],'no satisf') !== false ? '#991b1b' : (stripos($a['resultado'],'observ') !== false ? '#92400e' : '#166534');

            $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
                body{font-family:DejaVu Sans,Arial,sans-serif;font-size:10pt;color:#1a2233}
                h1{font-size:15pt;color:#0d2244;margin:0}
                .sec{font-weight:bold;color:#0d2244;font-size:11pt;border-bottom:1px solid #cdd5e6;padding-bottom:3px;margin:14px 0 6px}
                .box{border:1px solid #e3e8f0;border-radius:6px;padding:8px 12px;background:#f8fafc;font-size:9.5pt;white-space:pre-wrap}
                </style></head><body>'
                . '<table style="width:100%;border-bottom:2px solid #0d2244;margin-bottom:10px"><tr>'
                . '<td>' . $logoHtml . '</td><td style="text-align:right"><div style="font-size:11pt;font-weight:bold;color:#0d2244">AVBA Inspections, Certifications and Maintenance</div><div style="font-size:8.5pt;color:#555">Reporte de Auditoría de Calidad</div></td></tr></table>'
                . '<h1>Auditoría Trimestral — ' . $esc($a['periodo']) . ' ' . (int)$a['anio'] . '</h1>'
                . '<div style="font-size:9.5pt;color:#444;margin:4px 0 8px">Fecha de emisión: ' . date('d/m/Y')
                . ' &nbsp;·&nbsp; Resultado: <strong style="color:' . $resColor . '">' . $esc($a['resultado']) . '</strong></div>'
                . '<div class="sec">Equipos certificados</div>' . $tabla('Equipos', $eq)
                . '<div class="sec">Personal certificado</div>' . $tabla('Personal', $pe)
                . '<div class="sec">Vigencias por atender</div>' . $detBloque
                . '<div class="sec">Hallazgos</div>' . $hallBloque
                . ($a['observaciones'] ? '<div class="sec">Observaciones</div><div class="box">' . $esc($a['observaciones']) . '</div>' : '')
                . '<table style="width:100%;margin-top:40px"><tr><td style="text-align:center">'
                . '<div style="border-top:1px solid #333;width:70%;margin:0 auto;padding-top:4px;font-size:9.5pt"><strong>' . $esc($a['responsable']) . '</strong><br>Responsable de Calidad · AVBA</div>'
                . '</td></tr></table>'
                . '<div style="position:fixed;bottom:4mm;left:0;right:0;text-align:center;font-size:7.5pt;color:#999">Documento emitido a través del sistema de AVBA Inspección · www.avba.com.mx</div>'
                . '</body></html>';

            $mpdf = new \Mpdf\Mpdf(['mode'=>'utf-8','format'=>'Letter','margin_left'=>12,'margin_right'=>12,'margin_top'=>12,'margin_bottom'=>14,'tempDir'=>sys_get_temp_dir().'/mpdf']);
            $css=''; $body=$html;
            if (preg_match('/<style[^>]*>(.*?)<\/style>/is',$html,$m)) $css=$m[1];
            if (preg_match('/<body[^>]*>(.*)<\/body>/is',$html,$mb)) $body=$mb[1];
            if ($css!=='') $mpdf->WriteHTML($css, \Mpdf\HTMLParserMode::HEADER_CSS);
            $mpdf->WriteHTML($body, \Mpdf\HTMLParserMode::HTML_BODY);
            $dir = rtrim(UPLOAD_DIR,'/') . '/auditorias/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $nombre = 'Auditoria_' . (int)$a['anio'] . '_T' . (int)$a['trimestre'] . '.pdf';
            $mpdf->Output($dir . $nombre, 'F');
            return 'auditorias/' . $nombre;
        } catch (\Throwable $e) {
            error_log('[Auditorias] pdf: ' . $e->getMessage());
            return null;
        }
    }
}
