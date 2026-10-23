<?php
/**
 * AVBA Certificaciones — Módulo Inspector
 * Migración de Codigo.gs (guardarInspeccionDesdeWeb, obtenerMaquinaria, getChecklist)
 */

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}
require_once __DIR__ . '/ReporteInspeccion.php';

class Inspecciones {
    private PDO $pdo;

    /**
     * Inspección preparada de antemano para un inspector: ya trae los datos del
     * equipo y las fotos, pero le falta lo que sólo él puede poner —ubicación,
     * checklist y prueba de carga— y mandarla.
     *
     * Mientras siga así no existe para nadie más: las consultas de Calidad,
     * Certificaciones, auditorías y portal del cliente nombran los estatus que
     * sí ven, y BORRADOR no es ninguno. La única que la muestra es la del
     * historial del propio inspector.
     */
    private const BORRADOR = 'BORRADOR';

    /**
     * Precarga única, pedida por la dirección: la grúa hidráulica sobre camión
     * que inspeccionó Michel, con lo que se alcanza a leer en las fotos de su
     * placa de datos y de su tabla de capacidades.
     *
     * Las fotos viajan en el repositorio y se dejan tal cual: cuando él envía
     * la inspección, el guardado de siempre las sube a su carpeta y este
     * borrador se borra, de modo que lo que queda registrado es una inspección
     * normal, sin rastro de haber venido precargada.
     */
    private const PRECARGAS = [self::PRECARGA_GRUA_AC18, self::PRECARGA_GRUA_TC4792];

    private const PRECARGA_GRUA_AC18 = [
        'clave'      => 'precarga_grua_ac18_michel',
        'busca'      => 'MICHEL',
        'fotos'      => 'assets/precarga/grua_ac18_70/',
        'maquinaria' => 'GRÚA HIDRÁULICA SOBRE CAMIÓN',
        'cliente'    => 'FALOMIR',
        'marca'      => 'ALTEC',
        'modelo'     => 'AC18-70B',
        'serie'      => '0507EP0120',
        'id_equipo'  => 'CF-1676-A',
        'capacidad'  => '18 TON (36,000 LB)',
        'nota'       => 'Datos tomados de la placa y de la tabla de capacidades: '
                      . 'Altec AC18‑70B, serie 0507EP0120, 18 ton (36,000 lb), pluma de 70 ft. '
                      . 'Presiones máximas de placa: winche 2,800 PSI · pluma 3,500 PSI · giro 1,500 PSI. '
                      . 'Montada en Peterbilt, placas CF‑1676‑A, unidad 03. Cumple ASME B30.5 y trae LMAP. '
                      . 'Confirma el cliente y la ubicación, levanta el checklist y la prueba de carga.',
    ];

    /**
     * Segunda grúa de Falomir: la Simon-RO sobre Peterbilt, unidad 04.
     *
     * Ésta sí trae la prueba de carga precargada, con dos renglones tomados de
     * su propia tabla de capacidades:
     *
     *   Sin carga — la configuración máxima: pluma de 92 ft (28.04 m) a 80 ft
     *   (24.38 m) de radio, que es hasta donde llega la tabla.
     *
     *   Con carga — 71 ft (21.64 m) de pluma a 50 ft (15.24 m) de radio. Ahí la
     *   tabla marca 45° y 3,725 lb, así que 1.5 t de prueba son el 88.8% de lo
     *   permitido: carga redonda, fácil de armar, y dentro del 80–90% pedido.
     *
     * Los campos van en metros porque así los pide la pantalla; el ángulo y la
     * altura los recalcula el sistema a partir del radio y la pluma.
     */
    private const PRECARGA_GRUA_TC4792 = [
        'clave'      => 'precarga_grua_tc4792_michel',
        'busca'      => 'MICHEL',
        'fotos'      => 'assets/precarga/grua_tc4792/',
        'maquinaria' => 'GRÚA HIDRÁULICA SOBRE CAMIÓN',
        'cliente'    => 'FALOMIR',
        'marca'      => 'SIMON-RO',
        'modelo'     => 'TC-4792',
        'serie'      => '4900297431',
        'id_equipo'  => '04',
        'capacidad'  => '47,000 LB (21.3 TON)',
        'prueba_carga' => [
            'plantilla' => 'dictamen_preview.html',
            'sin' => ['peso' => '0',   'radio' => '24.38', 'pluma' => '28.04',
                      'angulo' => '29.6', 'altura' => '13.85', 'resultado' => ''],
            'con' => ['peso' => '1.5', 'radio' => '15.24', 'pluma' => '21.64',
                      'angulo' => '45.2', 'altura' => '15.36', 'resultado' => ''],
        ],
        'nota'       => 'Datos de la placa y de la tabla de capacidades: Simon‑RO TC‑4792, '
                      . 'serie 4900297431, capacidad máxima 47,000 lb (21.3 t) a 5 ft de radio con pluma de 29 ft. '
                      . 'Pluma de 29 a 92 ft. Montada en Peterbilt, unidad 04. '
                      . 'La prueba de carga ya viene llena: SIN CARGA en la configuración máxima '
                      . '—pluma 92 ft (28.04 m) a 80 ft (24.38 m) de radio— y CON CARGA a 71 ft '
                      . '(21.64 m) de pluma y 50 ft (15.24 m) de radio, donde la tabla marca 45° y 3,725 lb: '
                      . 'las 1.5 t de prueba son el 88.8% de ese valor. Los campos van en metros. '
                      . 'Coteja los números contra la tabla del equipo antes de la maniobra y marca tú el resultado.',
    ];

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->precargarInspecciones();
    }

    /**
     * Deja la inspección preparada, una sola vez por base.
     *
     * Si la cuenta de Michel no aparece —o hay más de una que podría ser— o el
     * tipo de equipo todavía no está sembrado, no hace nada y no se marca: en
     * la primera petición de una instalación nueva esta clase se construye
     * antes que Admin, así que el tipo aún no existe y conviene reintentarlo en
     * la siguiente.
     */
    private function precargarInspecciones(): void {
        foreach (self::PRECARGAS as $cfg) $this->precargarUna($cfg);
    }

    private function precargarUna(array $cfg): void {
        try {
            $this->pdo->exec(
                "CREATE TABLE IF NOT EXISTS maquinaria_tipos_seed (
                   clave    VARCHAR(40) NOT NULL PRIMARY KEY,
                   aplicado TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            $ya = $this->pdo->prepare("SELECT 1 FROM maquinaria_tipos_seed WHERE clave = ?");
            $ya->execute([$cfg['clave']]);
            if ($ya->fetch()) return;

            $usuario = cuentaInspectorPorNombre($this->pdo, $cfg['busca']);
            if ($usuario === null) return;

            $t = $this->pdo->prepare("SELECT 1 FROM maquinaria_tipos WHERE UPPER(TRIM(nombre)) = ? LIMIT 1");
            $t->execute([$cfg['maquinaria']]);
            if (!$t->fetchColumn()) return;

            if (!is_dir(__DIR__ . '/../' . $cfg['fotos'])) {
                error_log('[Inspecciones] precarga: no están las fotos en ' . $cfg['fotos']);
                return;
            }

            $this->ensureNotaBorrador();
            $cols = "cliente, maquinaria, marca, modelo, serie, id_equipo, capacidad,
                     fecha_inspeccion, evidencia_url, estado, inspector";
            $vals = "?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?";
            $args = [
                $cfg['cliente'], $cfg['maquinaria'], $cfg['marca'], $cfg['modelo'], $cfg['serie'],
                $cfg['id_equipo'], $cfg['capacidad'], date('Y-m-d'), $cfg['fotos'], self::BORRADOR, $usuario,
            ];
            if (!empty($cfg['prueba_carga'])) {
                try { $this->pdo->exec("ALTER TABLE equipos ADD COLUMN IF NOT EXISTS prueba_carga JSON NULL"); }
                catch (\Throwable $e) { /* ya existe o el motor no lo admite */ }
                $cols .= ", prueba_carga";
                $vals .= ", ?";
                $args[] = json_encode($cfg['prueba_carga'], JSON_UNESCAPED_UNICODE);
            }
            if ($this->tieneNotaBorrador()) {
                $cols .= ", nota_borrador";
                $vals .= ", ?";
                $args[] = $cfg['nota'];
            }
            $this->pdo->prepare("INSERT INTO equipos ($cols) VALUES ($vals)")->execute($args);

            $this->pdo->prepare("INSERT INTO maquinaria_tipos_seed (clave) VALUES (?)")->execute([$cfg['clave']]);
            error_log('[Inspecciones] precarga ' . $cfg['clave'] . ': inspección preparada para ' . $usuario);
        } catch (\Throwable $e) {
            error_log('[Inspecciones] precarga ' . ($cfg['clave'] ?? '?') . ': ' . $e->getMessage());
        }
    }

    /** Nota que la precarga le deja al inspector. Sólo la usan los borradores. */
    private function ensureNotaBorrador(): void {
        try {
            if (!columnaExiste($this->pdo, 'equipos', 'nota_borrador')) {
                $this->pdo->exec("ALTER TABLE equipos ADD COLUMN nota_borrador TEXT NULL");
            }
        } catch (\Throwable $e) {
            error_log('[Inspecciones] columna nota_borrador: ' . $e->getMessage());
        }
    }

    private function tieneNotaBorrador(): bool {
        try { return columnaExiste($this->pdo, 'equipos', 'nota_borrador'); }
        catch (\Throwable $e) { return false; }
    }

    /**
     * La inspección preparada que espera a este inspector, con sus fotos, o
     * null si no tiene ninguna. Se entregan las URL de las fotos, no su
     * contenido: el navegador las baja como cualquier imagen.
     */
    public function inspeccionPreparada(string $usuario): array {
        $nota = $this->tieneNotaBorrador() ? 'nota_borrador AS nota' : "'' AS nota";
        try {
            $st = $this->pdo->prepare(
                "SELECT id, cliente, maquinaria, marca, modelo, serie, id_equipo, capacidad,
                        correo, direccion, coords_inspeccion, evidencia_url, prueba_carga, {$nota},
                        DATE_FORMAT(fecha_inspeccion, '%d/%m/%Y') AS fecha
                 FROM equipos
                 WHERE inspector = ? AND estado = ?
                 ORDER BY id LIMIT 1"
            );
            $st->execute([$usuario, self::BORRADOR]);
            $fila = $st->fetch();
        } catch (\Throwable $e) {
            error_log('[Inspecciones] inspeccionPreparada: ' . $e->getMessage());
            return ['status' => 'success', 'data' => null];
        }
        if (!$fila) return ['status' => 'success', 'data' => null];

        $fila['fotos'] = $this->fotosDeCarpeta((string)$fila['evidencia_url']);
        $fila['prueba_carga'] = !empty($fila['prueba_carga'])
            ? (json_decode((string)$fila['prueba_carga'], true) ?: null) : null;

        // Cuántas esperan en total: con más de una, el aviso lo dice y el
        // inspector no se queda pensando que sólo había ésta.
        try {
            $c = $this->pdo->prepare("SELECT COUNT(*) FROM equipos WHERE inspector = ? AND estado = ?");
            $c->execute([$usuario, self::BORRADOR]);
            $pendientes = (int)$c->fetchColumn();
        } catch (\Throwable $e) { $pendientes = 1; }

        return ['status' => 'success', 'data' => $fila, 'pendientes' => $pendientes];
    }

    /** URL de las imágenes que hay en una carpeta de evidencia, en orden. */
    private function fotosDeCarpeta(string $url): array {
        $url = trim($url);
        if ($url === '') return [];
        $dir = str_starts_with($url, UPLOAD_URL)
            ? UPLOAD_DIR . substr($url, strlen(UPLOAD_URL))
            : __DIR__ . '/../' . $url;
        $dir = rtrim($dir, '/') . '/';
        if (!is_dir($dir)) return [];

        $fotos = [];
        foreach (glob($dir . '*.{jpg,jpeg,png,webp,JPG,JPEG,PNG,WEBP}', GLOB_BRACE) ?: [] as $ruta) {
            $fotos[] = rtrim($url, '/') . '/' . basename($ruta);
        }
        sort($fotos);
        return $fotos;
    }

    /**
     * Da por enviada la inspección preparada: una vez guardada como inspección
     * normal —con su folio y sus fotos en su propia carpeta— el borrador ya no
     * tiene nada que aportar. Sólo se borra el del propio inspector.
     */
    private function cerrarBorrador(int $id, string $usuario): void {
        if ($id <= 0) return;
        try {
            $this->pdo->prepare(
                "DELETE FROM equipos WHERE id = ? AND inspector = ? AND estado = ?"
            )->execute([$id, $usuario, self::BORRADOR]);
        } catch (\Throwable $e) {
            error_log('[Inspecciones] cerrarBorrador: ' . $e->getMessage());
        }
    }

    // ── Opciones de maquinaria ─────────────────────────────
    public function obtenerOpcionesMaquinaria(): array {
        $stmt = $this->pdo->query("SELECT nombre, clave_drive AS idPlantilla, plantilla_dict_html FROM maquinaria_tipos ORDER BY id");
        return $stmt->fetchAll();
    }

    // ── Checklist por tipo de equipo ───────────────────────
    public function obtenerChecklistPorEquipo(string $nombreEquipo): array {
        if (!$nombreEquipo) return [];

        $stmt = $this->pdo->prepare(
            "SELECT ci.tag, ci.descripcion AS pregunta, ci.seccion,
                    COALESCE(cs.nombre, ci.seccion) AS seccion_nombre,
                    COALESCE(cs.orden, 0) AS seccion_orden
             FROM checklist_items ci
             INNER JOIN maquinaria_tipos mt ON mt.id = ci.maquinaria_tipo_id
             LEFT JOIN checklist_secciones cs ON cs.maquinaria_tipo_id = mt.id
                 AND cs.codigo = ci.seccion
             WHERE mt.nombre = ?
             ORDER BY cs.orden, ci.orden"
        );
        $stmt->execute([trim($nombreEquipo)]);
        return $stmt->fetchAll();
    }

    // ── Guardar inspección desde la web ───────────────────
    public function guardarInspeccion(array $payload, string $usuarioActual): array {
        try {
            $info      = $payload['info']      ?? $payload;
            $checklist = $payload['checklist'] ?? [];
            $fotos     = $payload['fotos']     ?? [];

            // Campos requeridos — todo en mayúsculas antes de guardar
            $cliente    = strtoupper(trim($info['cliente']    ?? ''));
            $serie      = strtoupper(trim($info['serie']      ?? ''));
            $maquinaria = strtoupper(trim($info['maquinaria'] ?? ''));

            if (!$cliente || !$serie || !$maquinaria) {
                return ['status' => 'error', 'message' => 'cliente, serie y maquinaria son requeridos.'];
            }

            // Generar folio de control
            $control = generarControl($this->pdo, $cliente);

            // Subir fotos al servidor
            $urlCarpeta = '';
            if (!empty($fotos)) {
                $urlCarpeta = $this->guardarFotos($fotos, $cliente, $serie);
            }

            // Auto-add prueba_carga column if migration_011 not applied yet
            try {
                $this->pdo->exec(
                    "ALTER TABLE equipos ADD COLUMN IF NOT EXISTS prueba_carga JSON NULL"
                );
            } catch (\Throwable $e) {}

            // Auto-add horquillas column
            try {
                $this->pdo->exec(
                    "ALTER TABLE equipos ADD COLUMN IF NOT EXISTS horquillas JSON NULL"
                );
            } catch (\Throwable $e) {}

            $pruebaCargaJson = null;
            if (!empty($payload['prueba_carga'])) {
                $pc = $payload['prueba_carga'];
                // Recalcular ángulo server-side: arccos(radio/pluma) para cada fila
                foreach ($pc as $key => &$row) {
                    if (!is_array($row)) continue;
                    $r = (float)($row['radio'] ?? 0);
                    $l = (float)($row['pluma'] ?? 0);
                    if ($r > 0 && $l >= $r) {
                        $row['angulo'] = round(rad2deg(acos($r / $l)), 1);
                        $row['altura'] = round(sqrt(max(0, $l * $l - $r * $r)), 2);
                    }
                }
                unset($row);
                $pruebaCargaJson = json_encode($pc, JSON_UNESCAPED_UNICODE);
            }

            $horquillasJson = null;
            if (!empty($payload['horquillas'])) {
                $h = $payload['horquillas'];
                $horquillasJson = json_encode([
                    'marca'          => strtoupper(trim($h['marca']          ?? '')),
                    'serie'          => strtoupper(trim($h['serie']          ?? '')),
                    'capacidad'      => strtoupper(trim($h['capacidad']      ?? '')),
                    'centro_gravedad' => strtoupper(trim($h['centro_gravedad'] ?? '')),
                ], JSON_UNESCAPED_UNICODE);
            }

            $stmt = $this->pdo->prepare(
                "INSERT INTO equipos
                 (marca_temporal, cliente, coords_inspeccion, maquinaria, marca, modelo, serie,
                  id_equipo, fecha_inspeccion, correo, control, evidencia_url, direccion,
                  capacidad, estado, envio_direccion, coordenadas_envio, inspector, prueba_carga,
                  horquillas)
                 VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDIENTE', ?, ?, ?, ?, ?)"
            );
            $u = fn($v) => $v !== null && $v !== '' ? strtoupper(trim($v)) : null;

            $coordsInsp = trim($info['coords_insp']  ?? '');
            $coordsEnv  = trim($info['coords_envio'] ?? '');

            // Si el inspector seleccionó del buscador, usar ese texto directamente.
            // Solo reverse-geocodificar cuando el pin fue arrastrado sin buscar.
            $dirInspManual = strtoupper(trim($info['dir_insp_manual']  ?? ''));
            $dirEnvManual  = strtoupper(trim($info['dir_envio_manual'] ?? ''));

            $dirInsp = $dirInspManual ?: ($this->geocodificarCoordenadas($coordsInsp) ?: null);
            $dirEnv  = $dirEnvManual  ?: ($this->geocodificarCoordenadas($coordsEnv)  ?: null);

            $stmt->execute([
                $cliente,
                $coordsInsp ?: null,
                $maquinaria,
                $u($info['marca']      ?? ''),
                $u($info['modelo']     ?? ''),
                $serie,
                $u($info['id_cliente'] ?? ''),
                date('Y-m-d'),
                $this->normalizarEmails($info['email_cliente'] ?? '') ?: null,
                $control,
                $urlCarpeta ?: null,
                $dirInsp,
                $u($info['capacidad']  ?? ''),
                $dirEnv,
                $coordsEnv ?: null,
                $usuarioActual,
                $pruebaCargaJson,
                $horquillasJson,
            ]);

            $equipoId = (int) $this->pdo->lastInsertId();

            // Guardar respuestas del checklist
            if (!empty($checklist)) {
                $ins = $this->pdo->prepare(
                    "INSERT INTO inspeccion_checklist (equipo_id, tag, valor)
                     VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE valor = VALUES(valor)"
                );
                foreach ($checklist as $tag => $valor) {
                    $v = strtoupper(trim($valor));
                    if (!in_array($v, ['C', 'NC', 'NA'], true)) $v = null;
                    $ins->execute([$equipoId, $tag, $v]);
                }
            }

            // Recomendaciones del inspector: lo que vio en campo y no
            // pertenece al alcance de lo que se certifica. No salen en el
            // reporte ni en el dictamen; llegan al cliente cuando
            // Certificaciones publica la documentación.
            if (!empty($payload['recomendaciones']) && class_exists('Recomendaciones')) {
                try {
                    (new Recomendaciones($this->pdo))->guardarDeInspeccion(
                        $equipoId, (array)$payload['recomendaciones'], $usuarioActual
                    );
                } catch (\Throwable $e) {
                    // Una recomendación que falle no puede tirar la inspección:
                    // el inspector está en campo y lo importante es el registro.
                    error_log('[Inspecciones] recomendaciones: ' . $e->getMessage());
                }
            }

            // Historial
            registrarHistorial($this->pdo, $usuarioActual, $equipoId, 'estado', null, 'PENDIENTE', 'INSERT');

            // La inspección preparada ya cumplió: lo guardado es una inspección
            // normal, con su folio y sus fotos. Se borra después de insertar,
            // para que un fallo a medias no deje al inspector sin las dos.
            $this->cerrarBorrador((int)($payload['borrador_id'] ?? 0), $usuarioActual);

            // Generar reporte PDF de inspección
            $reporte     = ReporteInspeccion::generar($this->pdo, $equipoId, $usuarioActual);
            $reporteUrl  = $reporte['url'] ?? null;
            $reporteMsg  = ($reporte['status'] !== 'success') ? ($reporte['message'] ?? 'Error desconocido') : null;

            return [
                'status'         => 'success',
                'message'        => 'Inspección registrada correctamente.',
                'control'        => $control,
                'url'            => $urlCarpeta,
                'reporte_url'    => $reporteUrl,
                'reporte_error'  => $reporteMsg,
            ];
        } catch (Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    // ── Guardar fotos en el servidor ───────────────────────
    private function guardarFotos(array $fotos, string $cliente, string $serie): string {
        $fechaHoy   = date('Y-m-d');
        $nombreDir  = preg_replace('/[^a-zA-Z0-9_\-]/', '_', "{$fechaHoy}_{$cliente}_{$serie}");
        $rutaDir    = UPLOAD_DIR . $nombreDir . '/';
        $urlDir     = UPLOAD_URL . $nombreDir . '/';

        if (!is_dir($rutaDir)) {
            mkdir($rutaDir, 0755, true);
        }

        foreach ($fotos as $i => $foto) {
            $base64 = $foto['base64'] ?? '';
            $mime   = $foto['mimeType'] ?? 'image/jpeg';

            // Limpiar prefijo data:image/...;base64,
            $base64 = preg_replace('/^data:[^;]+;base64,/', '', $base64);
            $base64 = str_replace(["\n", "\r", ' '], '', $base64);

            $bytes = base64_decode($base64);
            if ($bytes === false) continue;

            $ext  = ($mime === 'image/png') ? 'png' : 'jpg';
            $file = $rutaDir . "Evidencia_" . ($i + 1) . "_" . preg_replace('/[^a-zA-Z0-9]/', '_', $serie) . ".{$ext}";
            file_put_contents($file, $bytes);
        }

        return $urlDir;
    }

    // ── Historial de inspecciones del inspector ────────────
    public function getMisInspecciones(string $usuario): array {
        $stmt = $this->pdo->prepare(
            "SELECT id,
                    DATE_FORMAT(marca_temporal, '%d/%m/%Y %H:%i') AS fecha_registro,
                    DATE_FORMAT(fecha_inspeccion, '%d/%m/%Y')      AS fecha_inspeccion,
                    cliente, maquinaria, marca, modelo, serie, id_equipo,
                    capacidad, direccion, correo, control, estado,
                    evidencia_url, reporte_url, certificado_url, dictamen_url,
                    motivo, qr_codigo, inspector, prueba_carga,
                    envio_direccion AS envio, coordenadas_envio
             FROM equipos
             WHERE inspector = ?
             ORDER BY marca_temporal DESC"
        );
        $stmt->execute([$usuario]);
        return $this->formatearInspecciones($stmt->fetchAll());
    }

    public function getTodasInspecciones(): array {
        $stmt = $this->pdo->query(
            "SELECT id,
                    DATE_FORMAT(marca_temporal, '%d/%m/%Y %H:%i') AS fecha_registro,
                    DATE_FORMAT(fecha_inspeccion, '%d/%m/%Y')      AS fecha_inspeccion,
                    cliente, maquinaria, marca, modelo, serie, id_equipo,
                    capacidad, direccion, correo, control, estado,
                    evidencia_url, reporte_url, certificado_url, dictamen_url,
                    motivo, qr_codigo, inspector, prueba_carga,
                    envio_direccion AS envio, coordenadas_envio
             FROM equipos
             ORDER BY marca_temporal DESC"
        );
        return $this->formatearInspecciones($stmt->fetchAll());
    }

    private function formatearInspecciones(array $rows): array {
        foreach ($rows as &$r) {
            $r['folio']     = formatoFolio($r['control'] ?? '');
            $r['qr_url']    = $r['qr_codigo'] ? urlQR($r['qr_codigo']) : '';
            $r['checklist'] = $this->getChecklistEquipo((int)$r['id']);
        }
        unset($r);
        return $rows;
    }

    private function getChecklistEquipo(int $equipoId): array {
        $stmt = $this->pdo->prepare(
            "SELECT ci.descripcion, ci.tag, ic.valor,
                    COALESCE(cs.nombre, ci.seccion) AS seccion_nombre
             FROM inspeccion_checklist ic
             INNER JOIN checklist_items ci  ON ci.tag = ic.tag
                AND ci.maquinaria_tipo_id = (
                      SELECT e.id FROM maquinaria_tipos e
                      INNER JOIN equipos eq ON eq.maquinaria = e.nombre
                      WHERE eq.id = ? LIMIT 1
                    )
             LEFT JOIN checklist_secciones cs
                    ON cs.maquinaria_tipo_id = ci.maquinaria_tipo_id
                   AND cs.codigo = ci.seccion
             WHERE ic.equipo_id = ?
             ORDER BY ci.seccion, ci.orden"
        );
        $stmt->execute([$equipoId, $equipoId]);
        return $stmt->fetchAll();
    }

    // ── Geocodificación inversa server-side ────────────────
    private function geocodificarCoordenadas(string $coords): string {
        if (!$coords || $coords === '0,0') return '';
        $parts = explode(',', $coords, 2);
        if (count($parts) !== 2) return $coords;
        [$lat, $lng] = array_map('trim', $parts);
        if (!is_numeric($lat) || !is_numeric($lng)) return $coords;

        $url = "https://nominatim.openstreetmap.org/reverse?format=json&lat={$lat}&lon={$lng}&accept-language=es";
        $ctx = stream_context_create(['http' => [
            'timeout' => 10,
            'header'  => "User-Agent: AVBA-Certificaciones/1.0 (gestion.avba.com.mx)\r\n"
                       . "Accept: application/json\r\n",
        ]]);
        $json = @file_get_contents($url, false, $ctx);
        if ($json) {
            $data = json_decode($json, true);
            if (!empty($data['display_name'])) return strtoupper($data['display_name']);
        }
        return strtoupper("{$lat}, {$lng}");
    }

    // Normaliza lista de correos separados por coma: minúsculas, sin espacios, válidos
    private function normalizarEmails(string $raw): string {
        $emails = array_filter(
            array_map('trim', explode(',', strtolower($raw))),
            fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)
        );
        return implode(',', $emails);
    }
}
