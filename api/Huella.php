<?php
/* ══════════════════════════════════════════════════════════════
   AVBA — Control de lo emitido y lo enviado

   Un certificado y su informe se generan en momentos distintos, a veces con
   días de diferencia, y en medio Calidad puede corregir el registro. Así pasó
   con Goodtower: el certificado decía 12 eslingas y el informe 13, porque la
   decimotercera se agregó entre uno y otro, y nada lo avisó.

   Aquí se guarda, por cada documento, CON QUÉ DATOS se generó el PDF vigente
   (el que ve el cliente en el portal) y con qué datos iba el último que salió
   por correo. Al consultar se compara contra lo que hay hoy en la base y entre
   documentos del mismo registro, y se dice en palabras qué cambió:

     · el PDF vigente ya no coincide con el sistema   → reemitir
     · lo enviado por correo ya no coincide           → reenviar
     · dos documentos del mismo registro no dicen lo mismo

   No se guarda el PDF, sino un resumen de lo que imprime: cliente, fechas,
   folio, piezas, checklist… Cada documento se compara sólo en lo que imprime,
   para que corregir un dato que el certificado no muestra no lo marque como
   desactualizado.

   Lo emitido antes de que existiera este control no se sabe con qué datos se
   hizo. Se toma una "línea base" con los datos del día en que se revisa por
   primera vez: desde ahí cualquier cambio queda señalado. En maquinaria,
   además, el historial de cambios dice qué se tocó después del último envío.
   ══════════════════════════════════════════════════════════════ */

class Huella {

    /** Sube cuando cambia lo que se compara, para no confiar en huellas viejas. */
    private const VERSION = 1;

    /**
     * Lo que imprime cada documento. 'campos' => '*' es el registro completo.
     * Los nombres son los que se leen en los avisos.
     */
    private const DOCS = [
        'equipo' => [
            'certificado' => [
                'nombre' => 'Certificado',
                'campos' => ['Cliente', 'Domicilio', 'Tipo de equipo', 'Marca', 'Modelo', 'Serie',
                             'ID del equipo', 'Capacidad', 'Fecha de inspección', 'Folio', 'QR', 'Horquillas'],
            ],
            'dictamen' => ['nombre' => 'Dictamen', 'campos' => '*', 'listas' => '*'],
        ],
        'accesorio' => [
            // El certificado de la sesión no enumera piezas: imprime cuántas de
            // cada tipo resultaron aptas ("13 Eslinga Sintética"). Eso es lo que
            // se compara, y es justo lo que no cuadraba con Goodtower.
            'cert'    => ['nombre' => 'Certificado', 'campos' => ['Cliente', 'Fecha de inspección', 'Folio', 'QR'],
                          'conteo_aptos' => true],
            'informe' => ['nombre' => 'Informe', 'campos' => '*', 'listas' => '*'],
            'cumple'  => ['nombre' => 'Informe de aprobados', 'campos' => '*', 'listas' => '*', 'solo_aptos' => true],
        ],
        'personal' => [
            'DC3' => ['nombre' => 'DC-3', 'campos' => [
                'Nombre', 'CURP', 'Puesto', 'Ocupación', 'Curso', 'Duración (horas)', 'Área temática',
                'Fecha del curso', 'Empresa', 'RFC de la empresa', 'Representante legal',
                'Representante de los trabajadores', 'Instructor', 'Registro STPS del instructor']],
            'DIPLOMA' => ['nombre' => 'Diploma', 'campos' => [
                'Nombre', 'Curso', 'Duración (horas)', 'Área temática', 'Fecha del curso',
                'Capacidad', 'Folio', 'Instructor', 'QR']],
            'CERTIFICADO' => ['nombre' => 'Certificado', 'campos' => [
                'Nombre', 'CURP', 'Puesto', 'Curso', 'Duración (horas)', 'Área temática', 'Fecha del curso',
                'Empresa', 'Capacidad', 'Folio', 'Instructor', 'QR']],
            'CREDENCIAL' => ['nombre' => 'Credencial', 'campos' => [
                'Nombre', 'Curso', 'Empresa', 'Fecha del curso', 'Capacidad', 'Folio', 'QR']],
        ],
    ];

    /**
     * Documentos del mismo registro que deben decir lo mismo. El tercer valor
     * es con qué ojos se comparan (lo que imprime ese documento); null = sólo
     * los datos que imprimen los dos.
     */
    private const CRUCES = [
        'equipo'    => [['certificado', 'dictamen', 'certificado']],
        'accesorio' => [['cert', 'informe', 'cert'], ['cert', 'cumple', 'cert'], ['cumple', 'informe', 'cumple']],
        'personal'  => [['DC3', 'CERTIFICADO', null], ['DIPLOMA', 'CERTIFICADO', null],
                        ['DC3', 'DIPLOMA', null], ['CREDENCIAL', 'CERTIFICADO', null]],
    ];

    /** Campos de `equipos` que salen en sus documentos (para leer el historial). */
    private const CAMPOS_HISTORIAL_EQUIPO = [
        'cliente' => 'Cliente', 'direccion' => 'Domicilio', 'maquinaria' => 'Tipo de equipo',
        'marca' => 'Marca', 'modelo' => 'Modelo', 'serie' => 'Serie', 'id_equipo' => 'ID del equipo',
        'capacidad' => 'Capacidad', 'fecha_inspeccion' => 'Fecha de inspección',
        'hora_inspeccion' => 'Hora de inspección', 'control' => 'Folio', 'qr_codigo' => 'QR',
        'inspector' => 'Inspector', 'prueba_carga' => 'Prueba de carga', 'horquillas' => 'Horquillas',
        'evidencia_url' => 'Fotos de evidencia',
    ];

    /** Sólo para pruebas: fuentes de datos sustitutas por módulo. */
    public static array $fuentes = [];

    private static array $tablaLista = [];

    // ══════════════════════════════════════════════════════
    //  Tabla
    // ══════════════════════════════════════════════════════

    public static function asegurarTabla(PDO $pdo): void {
        $k = spl_object_id($pdo);
        if (!empty(self::$tablaLista[$k])) return;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS documentos_huella (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                modulo      VARCHAR(20)  NOT NULL,
                registro_id INT          NOT NULL,
                tipo_doc    VARCHAR(40)  NOT NULL,
                gen_huella  CHAR(64)     NULL,
                gen_base    CHAR(64)     NULL,
                gen_resumen MEDIUMTEXT   NULL,
                gen_at      DATETIME     NULL,
                gen_por     VARCHAR(100) NULL,
                gen_url     VARCHAR(500) NULL,
                gen_origen  VARCHAR(20)  NULL,
                env_huella  CHAR(64)     NULL,
                env_resumen MEDIUMTEXT   NULL,
                env_at      DATETIME     NULL,
                env_por     VARCHAR(100) NULL,
                env_a       VARCHAR(500) NULL,
                env_origen  VARCHAR(20)  NULL,
                env_veces   INT          NOT NULL DEFAULT 0,
                UNIQUE KEY uq_doc (modulo, registro_id, tipo_doc),
                KEY idx_gen_at (gen_at),
                KEY idx_env_at (env_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            self::$tablaLista[$k] = true;
        } catch (\Throwable $e) {
            error_log('[Huella] tabla: ' . $e->getMessage());
        }
    }

    // ══════════════════════════════════════════════════════
    //  Anotar
    // ══════════════════════════════════════════════════════

    /**
     * Anota con qué datos quedó el PDF vigente de un documento.
     *
     * $origen: 'generado' (lo hizo el sistema), 'manual' (PDF subido a mano: se
     * guarda con qué datos estaba el registro cuando se subió) o 'linea_base'.
     * $soloSiFalta: no pisa lo anotado (un PDF manual se anota al subirlo, no
     * cada vez que se adjunta).
     *
     * Nunca interrumpe a quien llama: si algo falla, el documento se emite igual.
     */
    public static function emitido(PDO $pdo, string $modulo, int $registroId, string $tipoDoc,
                                   string $usuario, string $url = '', string $origen = 'generado',
                                   bool $soloSiFalta = false, ?array $completo = null): void {
        try {
            $spec = self::spec($modulo, $tipoDoc);
            if (!$spec || $registroId <= 0) return;
            self::asegurarTabla($pdo);
            // Primera vez que se anota algo de un registro emitido antes del
            // control: sus otros documentos (el informe de hace una semana) se
            // anotan antes como línea base, para que también queden vigilados.
            $hay = $pdo->prepare("SELECT COUNT(*) FROM documentos_huella WHERE modulo = ? AND registro_id = ?");
            $hay->execute([$modulo, $registroId]);
            if ((int)$hay->fetchColumn() === 0) self::lineaBase($pdo, $modulo, [$registroId]);

            $fila = self::fila($pdo, $modulo, $registroId, $tipoDoc);
            if ($soloSiFalta && $fila && $fila['gen_resumen']) return;

            $completo ??= self::actual($pdo, $modulo, $registroId);
            if ($completo === null) return;

            $datos = [
                'gen_huella'  => self::hash(self::proyectar($completo, $spec)),
                'gen_base'    => self::hash($completo),
                'gen_resumen' => self::json($completo),
                'gen_por'     => mb_substr($usuario, 0, 100),
                'gen_url'     => mb_substr($url, 0, 500),
                'gen_origen'  => $origen,
            ];
            self::guardar($pdo, $fila, $modulo, $registroId, $tipoDoc, $datos, 'gen_at');
        } catch (\Throwable $e) {
            error_log('[Huella] emitido: ' . $e->getMessage());
        }
    }

    /**
     * Anota que el PDF vigente salió por correo: lo enviado es lo emitido. Si
     * el documento no tenía nada anotado (emitido antes de este control), se
     * toman los datos de ahora, que es lo más cercano que se sabe.
     */
    public static function enviado(PDO $pdo, string $modulo, int $registroId, string $tipoDoc,
                                   string $usuario, string $destino): void {
        try {
            $spec = self::spec($modulo, $tipoDoc);
            if (!$spec || $registroId <= 0) return;
            self::asegurarTabla($pdo);
            $fila = self::fila($pdo, $modulo, $registroId, $tipoDoc);
            if (!$fila || !$fila['gen_resumen']) {
                self::emitido($pdo, $modulo, $registroId, $tipoDoc, $usuario, '', 'linea_base');
                $fila = self::fila($pdo, $modulo, $registroId, $tipoDoc);
                if (!$fila) return;
            }
            $datos = [
                'env_huella'  => $fila['gen_huella'],
                'env_resumen' => $fila['gen_resumen'],
                'env_por'     => mb_substr($usuario, 0, 100),
                'env_a'       => mb_substr($destino, 0, 500),
                'env_origen'  => 'correo',
                'env_veces'   => (int)$fila['env_veces'] + 1,
            ];
            self::guardar($pdo, $fila, $modulo, $registroId, $tipoDoc, $datos, 'env_at');
        } catch (\Throwable $e) {
            error_log('[Huella] enviado: ' . $e->getMessage());
        }
    }

    /**
     * ¿El PDF vigente de este documento sigue coincidiendo con el sistema?
     * null si no hay nada anotado. Para decidir si hay que regenerarlo antes
     * de mandarlo o publicarlo.
     */
    public static function vigenteAlDia(PDO $pdo, string $modulo, int $registroId, string $tipoDoc): ?bool {
        try {
            $spec = self::spec($modulo, $tipoDoc);
            if (!$spec) return null;
            self::asegurarTabla($pdo);
            $fila = self::fila($pdo, $modulo, $registroId, $tipoDoc);
            if (!$fila || !$fila['gen_resumen']) return null;
            $actual = self::actual($pdo, $modulo, $registroId);
            if ($actual === null) return null;
            $gen = json_decode((string)$fila['gen_resumen'], true) ?: [];
            return !self::diferencias(self::proyectar($gen, $spec), self::proyectar($actual, $spec));
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function fila(PDO $pdo, string $modulo, int $id, string $tipo): ?array {
        $st = $pdo->prepare("SELECT * FROM documentos_huella WHERE modulo = ? AND registro_id = ? AND tipo_doc = ?");
        $st->execute([$modulo, $id, $tipo]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private static function guardar(PDO $pdo, ?array $fila, string $modulo, int $id, string $tipo,
                                    array $datos, string $colFecha): void {
        $cols = array_keys($datos);
        if ($fila) {
            $set = implode(', ', array_map(fn($c) => "$c = ?", $cols)) . ", $colFecha = NOW()";
            $pdo->prepare("UPDATE documentos_huella SET $set WHERE id = ?")
                ->execute(array_merge(array_values($datos), [(int)$fila['id']]));
        } else {
            $pdo->prepare(
                "INSERT INTO documentos_huella (modulo, registro_id, tipo_doc, " . implode(', ', $cols) . ", $colFecha)
                 VALUES (?, ?, ?, " . implode(', ', array_fill(0, count($cols), '?')) . ", NOW())"
            )->execute(array_merge([$modulo, $id, $tipo], array_values($datos)));
        }
    }

    // ══════════════════════════════════════════════════════
    //  Datos de hoy
    // ══════════════════════════════════════════════════════

    /** Resumen completo del registro tal como está hoy, o null si ya no existe. */
    public static function actual(PDO $pdo, string $modulo, int $id): ?array {
        return self::actuales($pdo, $modulo, [$id])[$id] ?? null;
    }

    /** Lo mismo para varios registros a la vez: [id => resumen]. */
    public static function actuales(PDO $pdo, string $modulo, array $ids): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return [];
        if (isset(self::$fuentes[$modulo])) return (self::$fuentes[$modulo])($pdo, $ids);
        $out = [];
        foreach (array_chunk($ids, 300) as $lote) {
            $out += match ($modulo) {
                'equipo'    => self::fuenteEquipos($pdo, $lote),
                'accesorio' => self::fuenteAccesorios($pdo, $lote),
                'personal'  => self::fuentePersonal($pdo, $lote),
                default     => [],
            };
        }
        return $out;
    }

    private static function fuenteEquipos(PDO $pdo, array $ids): array {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT * FROM equipos WHERE id IN ($in)");
        $st->execute($ids);
        $equipos = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) $equipos[(int)$e['id']] = $e;
        if (!$equipos) return [];

        // El checklist que sale en el dictamen: sólo los puntos del tipo de equipo.
        $check = [];
        try {
            $st = $pdo->prepare(
                "SELECT ic.equipo_id, ic.tag, ic.valor, ci.descripcion
                 FROM inspeccion_checklist ic
                 INNER JOIN equipos e          ON e.id = ic.equipo_id
                 INNER JOIN maquinaria_tipos mt ON mt.nombre = e.maquinaria
                 INNER JOIN checklist_items ci ON ci.tag = ic.tag AND ci.maquinaria_tipo_id = mt.id
                 WHERE ic.equipo_id IN ($in)"
            );
            $st->execute($ids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $check[(int)$r['equipo_id']][(string)$r['tag']] = [
                    '_nombre'   => self::txt($r['descripcion'] ?: $r['tag']),
                    'Resultado' => self::txt($r['valor']),
                ];
            }
        } catch (\Throwable $e) { /* sin checklist: se compara lo demás */ }

        $out = [];
        foreach ($equipos as $id => $e) {
            $lista = $check[$id] ?? [];
            ksort($lista, SORT_STRING);
            $out[$id] = [
                'campos' => [
                    'Cliente'             => self::txt($e['cliente'] ?? ''),
                    'Domicilio'           => self::txt($e['direccion'] ?? ''),
                    'Tipo de equipo'      => self::txt($e['maquinaria'] ?? ''),
                    'Marca'               => self::txt($e['marca'] ?? ''),
                    'Modelo'              => self::txt($e['modelo'] ?? ''),
                    'Serie'               => self::txt($e['serie'] ?? ''),
                    'ID del equipo'       => self::txt($e['id_equipo'] ?? ''),
                    'Capacidad'           => self::txt($e['capacidad'] ?? ''),
                    'Fecha de inspección' => self::fecha($e['fecha_inspeccion'] ?? ''),
                    'Hora de inspección'  => substr(self::txt($e['hora_inspeccion'] ?? ''), 0, 5),
                    'Folio'               => self::txt($e['control'] ?? ''),
                    'QR'                  => self::txt($e['qr_codigo'] ?? ''),
                    'Inspector'           => self::txt($e['inspector'] ?? ''),
                    'Prueba de carga'     => self::opaco($e['prueba_carga'] ?? ''),
                    'Horquillas'          => self::opaco($e['horquillas'] ?? ''),
                    'Fotos de evidencia'  => self::opaco($e['evidencia_url'] ?? ''),
                ],
                'listas' => ['Checklist' => $lista],
            ];
        }
        return $out;
    }

    private static function fuenteAccesorios(PDO $pdo, array $ids): array {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT * FROM accesorios_sesiones WHERE id IN ($in)");
        $st->execute($ids);
        $sesiones = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) $sesiones[(int)$s['id']] = $s;
        if (!$sesiones) return [];

        $st = $pdo->prepare(
            "SELECT a.*, t.nombre AS tipo_nombre,
                    (SELECT COUNT(*) FROM accesorios_fotos f WHERE f.accesorio_id = a.id) AS total_fotos
             FROM accesorios_izaje a
             LEFT JOIN accesorios_tipos t ON t.id = a.tipo_id
             WHERE a.sesion_id IN ($in)
             ORDER BY a.sesion_id, a.orden, a.id"
        );
        $st->execute($ids);
        $piezas = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $comps = array_filter(array_map('trim', explode("\n", (string)($a['componentes'] ?? ''))));
            $piezas[(int)$a['sesion_id']][(string)$a['id']] = [
                '_nombre'          => self::txt($a['id_accesorio'] ?? '') ?: ('#' . $a['id']),
                'ID del accesorio' => self::txt($a['id_accesorio'] ?? ''),
                'Tipo'             => self::txt($a['tipo_nombre'] ?? ''),
                'Marca'            => self::txt($a['marca'] ?? ''),
                'Modelo'           => self::txt($a['modelo'] ?? ''),
                'Serie'            => self::txt($a['serie'] ?? ''),
                'Capacidad'        => self::txt($a['capacidad'] ?? ''),
                'Medidas'          => self::txt($a['medidas'] ?? ''),
                'Componentes'      => self::txt(implode(' · ', $comps)),
                'Estado'           => mb_strtoupper(self::txt($a['estado'] ?? ''), 'UTF-8'),
                'QR'               => self::txt($a['qr_codigo'] ?? ''),
                'Fotos'            => (string)(int)($a['total_fotos'] ?? 0),
            ];
        }

        $out = [];
        foreach ($sesiones as $id => $s) {
            $out[$id] = [
                'campos' => [
                    'Cliente'             => self::txt($s['cliente'] ?? ''),
                    'Dirección'           => self::txt($s['direccion'] ?? ''),
                    'Fecha de inspección' => self::fecha($s['fecha'] ?? ''),
                    'Folio'               => self::txt($s['control'] ?? ''),
                    'QR'                  => self::txt($s['qr_codigo'] ?? ''),
                    'Inspector'           => self::txt(($s['inspector_usuario'] ?? '') ?: ($s['usuario'] ?? '')),
                ],
                'listas' => ['Pieza' => $piezas[$id] ?? []],
            ];
        }
        return $out;
    }

    private static function fuentePersonal(PDO $pdo, array $ids): array {
        if (!class_exists('Personal')) return [];
        $out = [];
        foreach ((new Personal($pdo))->datosHuella($ids) as $id => $p) {
            $out[(int)$id] = self::resumenParticipante($p);
        }
        return $out;
    }

    /** Resumen de un participante a partir de sus datos (los de obtenerParticipante). */
    public static function resumenParticipante(array $p): array {
        $cap = !empty($p['capacidad_na']) ? 'N/A' : self::txt($p['capacidad'] ?? '');
        return [
            'campos' => [
                'Nombre'                            => self::txt($p['nombre_completo'] ?? ''),
                'CURP'                              => self::txt($p['curp'] ?? ''),
                'Puesto'                            => self::txt($p['puesto'] ?? ''),
                'Ocupación'                         => self::txt($p['ocupacion_nombre'] ?? ''),
                'Curso'                             => self::txt($p['curso_nombre'] ?? ''),
                'Duración (horas)'                  => self::txt($p['duracion_horas'] ?? ''),
                'Área temática'                     => self::txt($p['area_tematica'] ?? ''),
                'Fecha del curso'                   => self::fecha($p['fecha_curso'] ?? ''),
                'Empresa'                           => self::txt($p['empresa_nombre'] ?? ''),
                'RFC de la empresa'                 => self::txt($p['empresa_rfc'] ?? ''),
                'Representante legal'               => self::txt($p['empresa_representante'] ?? ''),
                'Representante de los trabajadores' => self::txt($p['empresa_rep_trabajadores'] ?? ''),
                'Instructor'                        => self::txt($p['instructor_nombre'] ?? ''),
                'Registro STPS del instructor'      => self::txt($p['instructor_stps'] ?? ''),
                'Capacidad'                         => $cap,
                'Folio'                             => self::txt($p['control'] ?? ''),
                'QR'                                => self::txt($p['qr_codigo'] ?? ''),
            ],
            'listas' => [],
        ];
    }

    // ══════════════════════════════════════════════════════
    //  Comparar
    // ══════════════════════════════════════════════════════

    public static function spec(string $modulo, string $tipoDoc): ?array {
        if ($modulo === 'accesorio' && str_starts_with($tipoDoc, 'pieza:')) {
            $id = substr($tipoDoc, 6);
            if (!ctype_digit($id)) return null;
            return ['nombre' => 'Certificado de la pieza', 'campos' => ['Cliente', 'Fecha de inspección', 'Folio'],
                    'pieza' => $id];
        }
        return self::DOCS[$modulo][$tipoDoc] ?? null;
    }

    /** Lo que imprime un documento, sacado del resumen completo del registro. */
    public static function proyectar(array $completo, array $spec): array {
        $campos = $completo['campos'] ?? [];
        $listas = $completo['listas'] ?? [];
        $out    = ['campos' => [], 'listas' => []];

        if ($spec['campos'] === '*') {
            $out['campos'] = $campos;
        } else {
            foreach ($spec['campos'] as $c) $out['campos'][$c] = (string)($campos[$c] ?? '');
        }

        $piezas = $listas['Pieza'] ?? [];
        if (!empty($spec['conteo_aptos'])) {
            $out['campos']['Ítems certificados'] = self::conteoAptos($piezas);
        }
        if (isset($spec['pieza'])) {
            $k = (string)$spec['pieza'];
            $out['listas']['Pieza'] = isset($piezas[$k])
                ? [$k => array_diff_key($piezas[$k], ['Fotos' => 1])] : [];
        }
        if (($spec['listas'] ?? null) === '*') {
            foreach ($listas as $nombre => $items) {
                if ($nombre === 'Pieza' && !empty($spec['solo_aptos'])) {
                    $items = array_filter($items, fn($p) => ($p['Estado'] ?? '') === 'CUMPLE');
                }
                $out['listas'][$nombre] = $items;
            }
        }
        return $out;
    }

    /** "13 Eslinga Sintética, 15 Grillete": lo que imprime el certificado de la sesión. */
    private static function conteoAptos(array $piezas): string {
        $cuenta = [];
        foreach ($piezas as $p) {
            if (($p['Estado'] ?? '') !== 'CUMPLE') continue;
            $t = mb_convert_case($p['Tipo'] ?? '', MB_CASE_TITLE, 'UTF-8') ?: 'Accesorio';
            $cuenta[$t] = ($cuenta[$t] ?? 0) + 1;
        }
        ksort($cuenta, SORT_STRING);
        $out = [];
        foreach ($cuenta as $t => $n) $out[] = $n . ' ' . $t;
        return implode(', ', $out);
    }

    /**
     * Qué cambió de $antes a $ahora, en renglones que se entienden sin saber
     * cómo está hecha la base: "Fecha del curso: «03/11/2026» → «05/11/2026»",
     * "Se agregó la pieza 4352618007 (Eslinga Sintética, serie …)".
     */
    public static function diferencias(array $antes, array $ahora, int $max = 15): array {
        $out = [];
        $ca  = $antes['campos'] ?? [];
        $cb  = $ahora['campos'] ?? [];
        foreach (array_keys($ca + $cb) as $k) {
            $va = (string)($ca[$k] ?? '');
            $vb = (string)($cb[$k] ?? '');
            if ($va !== $vb) $out[] = self::renglon($k, $va, $vb);
        }

        $la = $antes['listas'] ?? [];
        $lb = $ahora['listas'] ?? [];
        foreach (array_keys($la + $lb) as $nombre) {
            $ia = $la[$nombre] ?? [];
            $ib = $lb[$nombre] ?? [];
            foreach ($ib as $k => $item) {
                if (isset($ia[$k])) continue;
                $out[] = $nombre === 'Pieza'
                    ? 'Se agregó la pieza ' . self::describirPieza($item)
                    : $nombre . ' · ' . ($item['_nombre'] ?? $k) . ': (vacío) → «' . self::corto(self::sinNombre($item)) . '»';
            }
            foreach ($ia as $k => $item) {
                if (isset($ib[$k])) continue;
                $out[] = $nombre === 'Pieza'
                    ? 'Se quitó la pieza ' . self::describirPieza($item)
                    : $nombre . ' · ' . ($item['_nombre'] ?? $k) . ': «' . self::corto(self::sinNombre($item)) . '» → (vacío)';
            }
            foreach ($ia as $k => $item) {
                if (!isset($ib[$k])) continue;
                $partes = [];
                foreach (array_keys($item + $ib[$k]) as $c) {
                    if (str_starts_with((string)$c, '_')) continue;
                    $va = (string)($item[$c] ?? '');
                    $vb = (string)($ib[$k][$c] ?? '');
                    if ($va !== $vb) $partes[] = self::renglon((string)$c, $va, $vb);
                }
                if (!$partes) continue;
                $titulo = $nombre === 'Pieza'
                    ? 'Pieza ' . ($ib[$k]['_nombre'] ?? $k)
                    : $nombre . ' · ' . ($ib[$k]['_nombre'] ?? $k);
                // En el checklist hay un solo dato por punto: el resultado.
                $out[] = $nombre === 'Checklist' && count($partes) === 1
                    ? $titulo . ': ' . preg_replace('/^Resultado: /u', '', $partes[0])
                    : $titulo . ' — ' . implode('; ', $partes);
            }
        }

        if (count($out) > $max) {
            $resto = count($out) - $max;
            $out   = array_slice($out, 0, $max);
            $out[] = '… y ' . $resto . ' cambio' . ($resto === 1 ? '' : 's') . ' más.';
        }
        return $out;
    }

    private static function renglon(string $campo, string $va, string $vb): string {
        if (str_starts_with($va, '#') || str_starts_with($vb, '#')) {
            if ($va === '') return $campo . ': se capturó';
            if ($vb === '') return $campo . ': se quitó';
            return $campo . ': cambió';
        }
        $f = fn($v) => $v === '' ? '(vacío)' : '«' . self::corto($v) . '»';
        return $campo . ': ' . $f($va) . ' → ' . $f($vb);
    }

    private static function describirPieza(array $p): string {
        $det = array_filter([
            mb_convert_case($p['Tipo'] ?? '', MB_CASE_TITLE, 'UTF-8'),
            ($p['Serie'] ?? '') !== '' ? 'serie ' . $p['Serie'] : '',
            $p['Estado'] ?? '',
        ]);
        return ($p['_nombre'] ?? '?') . ($det ? ' (' . implode(', ', $det) . ')' : '');
    }

    private static function sinNombre(array $item): string {
        return implode(' · ', array_filter(array_map('strval',
            array_filter($item, fn($k) => !str_starts_with((string)$k, '_'), ARRAY_FILTER_USE_KEY))));
    }

    private static function corto(string $v, int $n = 70): string {
        return mb_strlen($v) > $n ? mb_substr($v, 0, $n - 1) . '…' : $v;
    }

    // ══════════════════════════════════════════════════════
    //  Estado de un registro
    // ══════════════════════════════════════════════════════

    /**
     * La comparativa completa de un registro: por cada documento, con qué datos
     * se generó el vigente y con cuáles se envió el último correo, frente a lo
     * de hoy; y si dos documentos del mismo registro no dicen lo mismo.
     */
    public static function estado(PDO $pdo, string $modulo, int $id): array {
        if (!isset(self::DOCS[$modulo]) || $id <= 0) {
            return ['status' => 'error', 'message' => 'Registro no válido.'];
        }
        self::asegurarTabla($pdo);
        $filas = self::filas($pdo, $modulo, [$id])[$id] ?? [];
        if (!$filas) {
            // Emitido antes del control: se toma la línea base ahora.
            self::lineaBase($pdo, $modulo, [$id]);
            $filas = self::filas($pdo, $modulo, [$id])[$id] ?? [];
        }
        $actual = self::actual($pdo, $modulo, $id);
        $e = self::evaluar($pdo, $modulo, $id, $filas, $actual, true);
        return ['status' => 'success'] + $e;
    }

    /** Filas anotadas, agrupadas por registro. */
    private static function filas(PDO $pdo, string $modulo, array $ids, bool $conResumen = true): array {
        if (!$ids) return [];
        $cols = $conResumen ? '*'
            : 'id, modulo, registro_id, tipo_doc, gen_huella, gen_base, gen_at, gen_por, gen_url, gen_origen,
               env_huella, env_at, env_por, env_a, env_origen, env_veces';
        $out = [];
        foreach (array_chunk(array_values($ids), 500) as $lote) {
            $in = implode(',', array_fill(0, count($lote), '?'));
            $st = $pdo->prepare("SELECT $cols FROM documentos_huella WHERE modulo = ? AND registro_id IN ($in)");
            $st->execute(array_merge([$modulo], $lote));
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) $out[(int)$f['registro_id']][$f['tipo_doc']] = $f;
        }
        return $out;
    }

    /** Trae los resúmenes de unas filas ya leídas sin ellos. */
    private static function conResumen(PDO $pdo, array $filas): array {
        $falta = array_filter($filas, fn($f) => !array_key_exists('gen_resumen', $f));
        if (!$falta) return $filas;
        $ids = array_values(array_map(fn($f) => (int)$f['id'], $falta));
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $st  = $pdo->prepare("SELECT id, gen_resumen, env_resumen FROM documentos_huella WHERE id IN ($in)");
        $st->execute($ids);
        $res = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $res[(int)$r['id']] = $r;
        foreach ($filas as $t => $f) {
            if (isset($res[(int)$f['id']])) $filas[$t] += $res[(int)$f['id']];
        }
        return $filas;
    }

    /**
     * El corazón de la comparación. Con $detalle en falso sirve para recorrer
     * muchos registros: compara primero las huellas y sólo abre los resúmenes
     * de lo que no cuadra.
     */
    private static function evaluar(PDO $pdo, string $modulo, int $id, array $filas, ?array $actual, bool $detalle): array {
        $docs   = [];
        $alerta = false;

        // ¿Hace falta abrir los resúmenes? Sí, si algo no cuadra por huella.
        $necesita = $detalle;
        if (!$necesita && $actual !== null) {
            $bases = [];
            foreach ($filas as $tipo => $f) {
                $spec = self::spec($modulo, $tipo);
                if (!$spec) continue;
                $h = self::hash(self::proyectar($actual, $spec));
                if (($f['gen_huella'] && $f['gen_huella'] !== $h) || ($f['env_huella'] && $f['env_huella'] !== $h)) {
                    $necesita = true; break;
                }
                if ($f['gen_base'] && !str_starts_with($tipo, 'pieza:')) $bases[$f['gen_base']] = true;
            }
            if (count($bases) > 1) $necesita = true;   // documentos hechos con datos distintos
        }
        if ($necesita) $filas = self::conResumen($pdo, $filas);

        $gens = [];
        foreach ($filas as $tipo => $f) {
            $spec = self::spec($modulo, $tipo);
            if (!$spec) continue;
            $pa = $actual !== null ? self::proyectar($actual, $spec) : null;
            $hActual = $pa !== null ? self::hash($pa) : null;
            $d = [
                'tipo_doc' => $tipo,
                'nombre'   => $spec['nombre'],
                'pieza'    => isset($spec['pieza']) ? (($actual['listas']['Pieza'][$spec['pieza']]['_nombre'] ?? null) ?: '#' . $spec['pieza']) : null,
                'emitido'  => null,
                'enviado'  => null,
                'alerta'   => false,
            ];
            if ($f['gen_at']) {
                $cambios = [];
                if ($pa !== null && $f['gen_huella'] !== $hActual) {
                    $gen = $necesita ? (json_decode((string)($f['gen_resumen'] ?? ''), true) ?: null) : null;
                    $cambios = $gen ? self::diferencias(self::proyectar($gen, $spec), $pa) : [];
                }
                if ($necesita && !empty($f['gen_resumen'])) $gens[$tipo] = json_decode((string)$f['gen_resumen'], true) ?: null;
                $d['emitido'] = [
                    'at'      => self::fechaHora($f['gen_at']),
                    'at_iso'  => (string)$f['gen_at'],
                    'por'     => (string)($f['gen_por'] ?? ''),
                    'origen'  => (string)($f['gen_origen'] ?? 'generado'),
                    'url'     => (string)($f['gen_url'] ?? ''),
                    'al_dia'  => !$cambios,
                    'cambios' => $cambios,
                ];
            }
            if ($f['env_at']) {
                $cambios = [];
                if ($pa !== null && $f['env_huella'] !== $hActual) {
                    $env = $necesita ? (json_decode((string)($f['env_resumen'] ?? ''), true) ?: null) : null;
                    $cambios = $env ? self::diferencias(self::proyectar($env, $spec), $pa) : [];
                }
                $d['enviado'] = [
                    'at'      => self::fechaHora($f['env_at']),
                    'at_iso'  => (string)$f['env_at'],
                    'por'     => (string)($f['env_por'] ?? ''),
                    'a'       => (string)($f['env_a'] ?? ''),
                    'origen'  => (string)($f['env_origen'] ?? 'correo'),
                    'veces'   => (int)($f['env_veces'] ?? 0),
                    'al_dia'  => !$cambios,
                    'cambios' => $cambios,
                    // Lo que el cliente recibió por correo, ¿es lo mismo que ve en el portal?
                    'igual_al_vigente' => $f['gen_huella'] ? $f['env_huella'] === $f['gen_huella'] : null,
                ];
            }
            $d['alerta'] = ($d['emitido'] && !$d['emitido']['al_dia']) || ($d['enviado'] && !$d['enviado']['al_dia']);
            $alerta = $alerta || $d['alerta'];
            $docs[$tipo] = $d;
        }

        // Documentos del mismo registro que no dicen lo mismo.
        $cruces = [];
        foreach (self::CRUCES[$modulo] ?? [] as [$a, $b, $via]) {
            if (empty($gens[$a]) || empty($gens[$b])) continue;
            $spec = $via !== null ? self::spec($modulo, $via) : self::specComun($modulo, $a, $b);
            $dif  = self::diferencias(self::proyectar($gens[$a], $spec), self::proyectar($gens[$b], $spec), 10);
            if (!$dif) continue;
            $cruces[] = [
                'a' => self::DOCS[$modulo][$a]['nombre'], 'a_at' => $docs[$a]['emitido']['at'] ?? '',
                'b' => self::DOCS[$modulo][$b]['nombre'], 'b_at' => $docs[$b]['emitido']['at'] ?? '',
                // Leído "de lo que dice A a lo que dice B".
                'cambios' => $dif,
            ];
        }
        $alerta = $alerta || (bool)$cruces;

        // Maquinaria emitida antes de este control: lo que el historial dice
        // que se cambió después del último envío y antes de la línea base.
        $previos = [];
        if ($modulo === 'equipo') {
            $base = null;
            foreach ($filas as $f) {
                if (($f['gen_origen'] ?? '') === 'linea_base' && $f['gen_at']) $base = max($base ?? '', (string)$f['gen_at']);
            }
            if ($base !== null) $previos = self::cambiosPreviosEquipo($pdo, $id, $base);
            $alerta = $alerta || (bool)$previos;
        }

        return [
            'modulo'      => $modulo,
            'registro_id' => $id,
            'titulo'      => self::titulo($modulo, $actual),
            'existe'      => $actual !== null,
            'alerta'      => $actual !== null && $alerta,
            'docs'        => array_values($docs),
            'cruces'      => $cruces,
            'previos'     => $previos,
        ];
    }

    private static function specComun(string $modulo, string $a, string $b): array {
        $ca = self::DOCS[$modulo][$a]['campos'];
        $cb = self::DOCS[$modulo][$b]['campos'];
        return ['nombre' => '', 'campos' => array_values(array_intersect($ca, $cb))];
    }

    private static function cambiosPreviosEquipo(PDO $pdo, int $id, string $base): array {
        try {
            $st = $pdo->prepare("SELECT fecha_enviado FROM equipos WHERE id = ?");
            $st->execute([$id]);
            $env = (string)($st->fetchColumn() ?: '');
            if ($env === '') return [];
            $campos = array_keys(self::CAMPOS_HISTORIAL_EQUIPO);
            $in = implode(',', array_fill(0, count($campos), '?'));
            $st = $pdo->prepare(
                "SELECT campo, valor_anterior, valor_nuevo, usuario, fecha_hora
                 FROM historial_general
                 WHERE equipo_id = ? AND campo IN ($in) AND fecha_hora > ? AND fecha_hora <= ?
                 ORDER BY fecha_hora"
            );
            $st->execute(array_merge([$id], $campos, [$env, $base]));
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $h) {
                $c = self::CAMPOS_HISTORIAL_EQUIPO[$h['campo']] ?? $h['campo'];
                $va = (string)($h['valor_anterior'] ?? '');
                $vb = (string)($h['valor_nuevo'] ?? '');
                if (in_array($h['campo'], ['prueba_carga', 'horquillas', 'evidencia_url'], true)) {
                    $va = $va !== '' ? '#' : ''; $vb = $vb !== '' ? '#' : '';
                    if ($va === $vb) $va = '#a';
                }
                if ($h['campo'] === 'fecha_inspeccion') { $va = self::fecha($va); $vb = self::fecha($vb); }
                $out[] = self::renglon($c, self::txt($va), self::txt($vb))
                       . ' (' . self::fechaHora($h['fecha_hora']) . ($h['usuario'] ? ', ' . $h['usuario'] : '') . ')';
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private static function titulo(string $modulo, ?array $a): string {
        if ($a === null) return 'Registro eliminado';
        $c = $a['campos'] ?? [];
        $folio = ($c['Folio'] ?? '') !== '' ? 'Folio ' . $c['Folio'] : 'Sin folio';
        return match ($modulo) {
            'equipo'    => $folio . ' · ' . ($c['Cliente'] ?? '') . ' · ' . trim(($c['Tipo de equipo'] ?? '') . ' ' . ($c['Marca'] ?? '') . ' ' . ($c['Modelo'] ?? '')),
            'accesorio' => $folio . ' · ' . ($c['Cliente'] ?? '') . ' · ' . count($a['listas']['Pieza'] ?? []) . ' accesorio(s)',
            'personal'  => ($c['Nombre'] ?? '') . ' · ' . ($c['Curso'] ?? '') . (($c['Fecha del curso'] ?? '') !== '' ? ' · ' . $c['Fecha del curso'] : ''),
            default     => $folio,
        };
    }

    // ══════════════════════════════════════════════════════
    //  Todos los avisos
    // ══════════════════════════════════════════════════════

    /**
     * Los registros cuyos documentos ya no coinciden, de los tres módulos.
     * Recorre lo emitido o enviado en el último año; antes toma la línea base
     * de lo emitido antes de que existiera este control.
     */
    public static function alertas(PDO $pdo, int $dias = 400): array {
        self::asegurarTabla($pdo);
        $inicio = microtime(true);
        $base = 0;
        foreach (array_keys(self::DOCS) as $m) {
            // Con tiempo acotado: si hay mucho atrasado, se termina en la siguiente consulta.
            if (microtime(true) - $inicio > 12) break;
            $base += self::lineaBase($pdo, $m, null, $dias);
        }

        $desde  = date('Y-m-d H:i:s', strtotime('-' . $dias . ' days'));
        $avisos = [];
        $revisados = 0;
        foreach (array_keys(self::DOCS) as $m) {
            $st = $pdo->prepare(
                "SELECT DISTINCT registro_id FROM documentos_huella
                 WHERE modulo = ? AND (gen_at >= ? OR env_at >= ?)"
            );
            $st->execute([$m, $desde, $desde]);
            $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            foreach (array_chunk($ids, 300) as $lote) {
                $filas    = self::filas($pdo, $m, $lote, false);
                $actuales = self::actuales($pdo, $m, $lote);
                foreach ($lote as $id) {
                    if (empty($filas[$id])) continue;
                    $revisados++;
                    $e = self::evaluar($pdo, $m, $id, $filas[$id], $actuales[$id] ?? null, false);
                    if (!$e['alerta']) continue;
                    $avisos[] = self::avisoCorto($e);
                }
            }
        }
        usort($avisos, fn($a, $b) => strcmp($b['ultimo'], $a['ultimo']));

        return [
            'status'     => 'success',
            'alertas'    => $avisos,
            'revisados'  => $revisados,
            'linea_base' => $base,
        ];
    }

    /** Lo que se pinta en la lista de avisos. */
    private static function avisoCorto(array $e): array {
        $renglones = [];
        $ultimo    = '';
        foreach ($e['cruces'] as $c) {
            $renglones[] = $c['a'] . ' y ' . strtolower($c['b']) . ' no dicen lo mismo: ' . ($c['cambios'][0] ?? '');
        }
        $piezas = 0;
        foreach ($e['docs'] as $d) {
            $ultimo = max($ultimo, $d['emitido']['at_iso'] ?? '', $d['enviado']['at_iso'] ?? '');
            if (!$d['alerta']) continue;
            if ($d['pieza'] !== null) { $piezas++; continue; }
            if ($d['enviado'] && !$d['enviado']['al_dia']) {
                $renglones[] = $d['nombre'] . ' enviado el ' . $d['enviado']['at'] . ' ya no coincide: ' . ($d['enviado']['cambios'][0] ?? '');
            } elseif ($d['emitido'] && !$d['emitido']['al_dia']) {
                $renglones[] = $d['nombre'] . ($d['emitido']['origen'] === 'manual' ? ' (PDF subido a mano)' : '')
                             . ' ya no coincide: ' . ($d['emitido']['cambios'][0] ?? '');
            }
        }
        if ($piezas) $renglones[] = $piezas . ' certificado(s) por pieza ya no coinciden.';
        if ($e['previos']) $renglones[] = 'Cambios después del último envío: ' . $e['previos'][0];
        return [
            'modulo'      => $e['modulo'],
            'registro_id' => $e['registro_id'],
            'titulo'      => $e['titulo'],
            'renglones'   => array_slice($renglones, 0, 4),
            'ultimo'      => $ultimo,
        ];
    }

    // ══════════════════════════════════════════════════════
    //  Línea base de lo emitido antes del control
    // ══════════════════════════════════════════════════════

    /**
     * Para lo ya emitido que no tiene nada anotado: se anota con los datos de
     * hoy, marcado como línea base. Si el historial de envíos dice que salió
     * por correo, también se anota el envío (con su fecha real).
     *
     * $ids null = todos los emitidos de los últimos $dias, hasta 300 por vuelta.
     * Devuelve cuántos registros se anotaron.
     */
    public static function lineaBase(PDO $pdo, string $modulo, ?array $ids = null, int $dias = 400): int {
        try {
            self::asegurarTabla($pdo);
            $cand = self::candidatos($pdo, $modulo, $ids, date('Y-m-d', strtotime('-' . $dias . ' days')));
            if (!$cand) return 0;
            $actuales = self::actuales($pdo, $modulo, array_keys($cand));
            $envios   = self::enviosPrevios($pdo, $modulo, array_keys($cand));
            $n = 0;
            foreach ($cand as $id => $docs) {
                $completo = $actuales[$id] ?? null;
                if ($completo === null) continue;
                foreach ($docs as $tipo => $url) {
                    $spec = self::spec($modulo, $tipo);
                    if (!$spec) continue;
                    $h = self::hash(self::proyectar($completo, $spec));
                    $r = self::json($completo);
                    $env = $envios[$id] ?? null;
                    if ($env && !self::archivoIncluye($modulo, $tipo, (string)$env['archivo'])) $env = null;
                    $pdo->prepare(
                        "INSERT INTO documentos_huella
                           (modulo, registro_id, tipo_doc, gen_huella, gen_base, gen_resumen, gen_at, gen_por, gen_url, gen_origen,
                            env_huella, env_resumen, env_at, env_por, env_a, env_origen, env_veces)
                         VALUES (?, ?, ?, ?, ?, ?, NOW(), '', ?, 'linea_base', ?, ?, ?, ?, ?, ?, ?)"
                    )->execute([
                        $modulo, $id, $tipo, $h, self::hash($completo), $r, mb_substr((string)$url, 0, 500),
                        $env ? $h : null, $env ? $r : null, $env['fecha'] ?? null,
                        $env ? mb_substr((string)$env['usuario'], 0, 100) : null,
                        $env ? mb_substr((string)$env['correo'], 0, 500) : null,
                        $env ? 'linea_base' : null, $env ? 1 : 0,
                    ]);
                }
                $n++;
            }
            return $n;
        } catch (\Throwable $e) {
            error_log('[Huella] linea base ' . $modulo . ': ' . $e->getMessage());
            return 0;
        }
    }

    /** [registro => [tipo_doc => url]] de lo emitido sin anotar. */
    private static function candidatos(PDO $pdo, string $modulo, ?array $ids, string $desde): array {
        $sinFila = "NOT EXISTS (SELECT 1 FROM documentos_huella h WHERE h.modulo = '$modulo' AND h.registro_id = x.id)";
        $filtroIds = '';
        $params = [];
        if ($ids !== null) {
            $ids = array_values(array_filter(array_map('intval', $ids)));
            if (!$ids) return [];
            $filtroIds = ' AND x.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        }
        $out = [];

        if ($modulo === 'equipo') {
            $sql = "SELECT x.id, x.certificado_url, x.dictamen_url FROM equipos x
                    WHERE x.estado = 'ENVIADO' AND $sinFila $filtroIds";
            if ($ids === null) { $sql .= " AND x.fecha_enviado >= ?"; }
            $st = $pdo->prepare($sql . ' ORDER BY x.id DESC LIMIT 300');
            $st->execute($ids === null ? [$desde] : $ids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $d = [];
                if (trim((string)$r['certificado_url']) !== '') $d['certificado'] = $r['certificado_url'];
                if (trim((string)$r['dictamen_url']) !== '')    $d['dictamen']    = $r['dictamen_url'];
                if ($d) $out[(int)$r['id']] = $d;
            }
        } elseif ($modulo === 'accesorio') {
            $fechaCol = columnaExiste($pdo, 'accesorios_sesiones', 'fecha_enviado') ? 'COALESCE(x.fecha_enviado, x.fecha)' : 'x.fecha';
            $sql = "SELECT x.* FROM accesorios_sesiones x WHERE x.estatus = 'EMITIDO' AND $sinFila $filtroIds";
            if ($ids === null) { $sql .= " AND $fechaCol >= ?"; }
            $st = $pdo->prepare($sql . ' ORDER BY x.id DESC LIMIT 300');
            $st->execute($ids === null ? [$desde] : $ids);
            $sesiones = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($sesiones as $r) {
                $d = [];
                $cert = trim((string)(($r['cert_manual_url'] ?? '') ?: ($r['cert_url'] ?? '')));
                $inf  = trim((string)(($r['informe_manual_url'] ?? '') ?: ($r['informe_url'] ?? '')));
                $cum  = trim((string)($r['informe_cumple_url'] ?? ''));
                if ($cert !== '') $d['cert'] = $cert;
                if ($inf !== '')  $d['informe'] = $inf;
                if ($cum !== '')  $d['cumple'] = $cum;
                if ($d) $out[(int)$r['id']] = $d;
            }
            // Certificados por pieza
            if ($out && columnaExiste($pdo, 'accesorios_izaje', 'cert_url')) {
                $in = implode(',', array_fill(0, count($out), '?'));
                $st = $pdo->prepare("SELECT id, sesion_id, cert_url FROM accesorios_izaje
                                     WHERE sesion_id IN ($in) AND cert_url IS NOT NULL AND cert_url <> ''");
                $st->execute(array_keys($out));
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
                    $out[(int)$p['sesion_id']]['pieza:' . (int)$p['id']] = $p['cert_url'];
                }
            }
        } elseif ($modulo === 'personal') {
            $sql = "SELECT x.id FROM participantes_cursos x WHERE x.estatus = 'EMITIDO' AND $sinFila $filtroIds";
            if ($ids === null) { $sql .= " AND x.fecha_curso >= ?"; }
            $st = $pdo->prepare($sql . ' ORDER BY x.id DESC LIMIT 300');
            $st->execute($ids === null ? [$desde] : $ids);
            $pids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            if ($pids) {
                $in = implode(',', array_fill(0, count($pids), '?'));
                $st = $pdo->prepare("SELECT participante_id, tipo_doc, MAX(url) AS url FROM participantes_documentos
                                     WHERE participante_id IN ($in) GROUP BY participante_id, tipo_doc");
                $st->execute($pids);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $t = strtoupper((string)$r['tipo_doc']);
                    if (isset(self::DOCS['personal'][$t])) $out[(int)$r['participante_id']][$t] = $r['url'];
                }
            }
        }
        return $out;
    }

    /** El último correo de cada registro según la bitácora de envíos. */
    private static function enviosPrevios(PDO $pdo, string $modulo, array $ids): array {
        if (!$ids) return [];
        try {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $ok = columnaExiste($pdo, 'historico_envios', 'ok') ? ' AND (ok = 1 OR ok IS NULL)' : '';
            if (columnaExiste($pdo, 'historico_envios', 'registro_id')) {
                $cond   = $modulo === 'equipo'
                    ? "((modulo = 'equipo' AND registro_id IN ($in)) OR equipo_id IN ($in))"
                    : "(modulo = ? AND registro_id IN ($in))";
                $params = $modulo === 'equipo' ? array_merge($ids, $ids) : array_merge([$modulo], $ids);
                $col    = $modulo === 'equipo' ? 'COALESCE(registro_id, equipo_id)' : 'registro_id';
            } elseif ($modulo === 'equipo') {
                $cond = "equipo_id IN ($in)"; $params = $ids; $col = 'equipo_id';
            } else {
                return [];
            }
            $st = $pdo->prepare("SELECT $col AS rid, fecha_envio AS fecha, correo, usuario, archivo
                                 FROM historico_envios WHERE $cond $ok ORDER BY fecha_envio");
            $st->execute($params);
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['rid']] = $r;   // queda el último
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** ¿Ese correo llevaba este documento? Por el nombre del archivo adjunto. */
    private static function archivoIncluye(string $modulo, string $tipo, string $archivo): bool {
        $a = strtoupper($archivo);
        if ($a === '') return true;
        return match ($modulo) {
            'equipo'    => $tipo === 'dictamen' ? str_contains($a, 'DICT') : str_contains($a, 'CERT'),
            'accesorio' => match (true) {
                $tipo === 'cert'    => str_contains($a, 'CERT_ACC'),
                $tipo === 'cumple'  => str_contains($a, 'CUMPLE'),
                $tipo === 'informe' => str_contains($a, 'INFORME_ACC'),
                default             => false,
            },
            'personal'  => match ($tipo) {
                'DC3'         => str_contains($a, 'DC3'),
                'DIPLOMA'     => str_contains($a, 'DIPLOMA'),
                'CREDENCIAL'  => str_contains($a, 'CRED'),
                default       => (bool)preg_match('/(^|[^A-Z])CERT/', $a),
            },
            default => false,
        };
    }

    // ══════════════════════════════════════════════════════
    //  Utilidades
    // ══════════════════════════════════════════════════════

    private static function txt($v): string {
        return trim(preg_replace('/\s+/u', ' ', (string)($v ?? '')) ?? '');
    }

    /** Fechas siempre como se imprimen: d/m/Y. */
    private static function fecha($v): string {
        $v = trim((string)($v ?? ''));
        if ($v === '' || str_starts_with($v, '0000')) return '';
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m)) return "$m[3]/$m[2]/$m[1]";
        return $v;
    }

    private static function fechaHora($v): string {
        $t = strtotime((string)$v);
        return $t ? date('d/m/Y H:i', $t) : '';
    }

    /**
     * Datos que no se leen en un renglón (la prueba de carga, las fotos): se
     * guarda una huella corta y el aviso dice sólo que cambiaron.
     */
    private static function opaco($v): string {
        $v = trim((string)($v ?? ''));
        if ($v === '' || $v === 'null' || $v === '[]' || $v === '{}') return '';
        $j = json_decode($v, true);
        if (is_array($j)) {
            if (!$j) return '';
            $v = json_encode(self::ordenar($j), JSON_UNESCAPED_UNICODE);
        }
        return '#' . substr(sha1($v), 0, 12);
    }

    private static function ordenar($v) {
        if (!is_array($v)) return $v;
        if (array_keys($v) !== range(0, count($v) - 1)) ksort($v, SORT_STRING);
        return array_map([self::class, 'ordenar'], $v);
    }

    /** Huella de un resumen; lo que empieza con _ es sólo para leer y no cuenta. */
    public static function hash(array $r): string {
        return hash('sha256', self::VERSION . '|' . json_encode(self::sinEtiquetas(self::ordenar($r)), JSON_UNESCAPED_UNICODE));
    }

    private static function sinEtiquetas($v) {
        if (!is_array($v)) return $v;
        $out = [];
        foreach ($v as $k => $x) {
            if (is_string($k) && str_starts_with($k, '_')) continue;
            $out[$k] = self::sinEtiquetas($x);
        }
        return $out;
    }

    private static function json(array $r): string {
        return json_encode($r, JSON_UNESCAPED_UNICODE);
    }
}
