<?php
/**
 * AVBA Certificaciones — Inspección de arneses y líneas de vida
 *
 * Equipo de protección contra caídas (NOM-009-STPS-2011). Sigue el patrón
 * probado del módulo de Accesorios de Izaje —una sesión agrupa las piezas
 * inspeccionadas en una visita— con dos diferencias propias de este equipo:
 *
 *   1. CHECKLIST por pieza: cada tipo tiene sus puntos de inspección
 *      (configurables desde Calidad) y cada punto se responde C / NC / NA.
 *   2. VIGENCIA POR PIEZA: los arneses caducan por fecha de fabricación
 *      (retiro obligatorio), no por lote, así que cada pieza lleva su propia
 *      fecha de retiro y su propio certificado con QR.
 *
 * Una inspección individual es simplemente una sesión con una sola pieza, así
 * que el mismo modelo cubre el trabajo por lote y el de una pieza suelta.
 *
 * El recorrido entre departamentos es el mismo que el de grúas:
 *
 *   Inspector captura (PENDIENTE)
 *     → Calidad revisa: aprueba con folio y QR (APROBADO_CALIDAD)
 *                       o devuelve al inspector (DEVUELTO)
 *     → Certificaciones emite documentos y los publica/envía (EMITIDO)
 *                       o retorna el expediente a Calidad (RETORNADO)
 *
 * Igual que en grúas, RETORNADO conserva el QR reservado y vuelve a ser
 * aprobable, mientras que DEVUELTO regresa la captura al inspector.
 */
class Arneses {
    private PDO $pdo;

    /** Resultado de la inspección de una pieza. */
    private const RESULTADOS = ['APTO', 'CONDICIONADO', 'NO APTO'];

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->migrate();
    }

    // ════════════════════════════════════════════════════════
    //  ESQUEMA
    // ════════════════════════════════════════════════════════

    private function migrate(): void {
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS arneses_tipos (
                  id       INT AUTO_INCREMENT PRIMARY KEY,
                  nombre   VARCHAR(200) NOT NULL,
                  familia  VARCHAR(20)  NOT NULL DEFAULT 'arnes',
                  activo   TINYINT(1)   NOT NULL DEFAULT 1,
                  creado   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS arneses_checklist (
                  id          INT AUTO_INCREMENT PRIMARY KEY,
                  tipo_id     INT NOT NULL,
                  tag         VARCHAR(20)  NOT NULL,
                  descripcion VARCHAR(250) NOT NULL,
                  orden       INT NOT NULL DEFAULT 0,
                  activo      TINYINT(1) NOT NULL DEFAULT 1,
                  UNIQUE KEY uk_tipo_tag (tipo_id, tag),
                  KEY idx_tipo (tipo_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS arneses_sesiones (
                  id            INT AUTO_INCREMENT PRIMARY KEY,
                  cliente       VARCHAR(300) NOT NULL,
                  fecha         DATE NULL,
                  coordenadas   VARCHAR(100) NULL,
                  direccion     TEXT NULL,
                  usuario       VARCHAR(100) NULL,
                  control       VARCHAR(30)  NULL,
                  estatus       VARCHAR(30)  NOT NULL DEFAULT 'PENDIENTE',
                  qr_codigo     VARCHAR(20)  NULL,
                  dictamen_url  VARCHAR(500) NULL,
                  motivo        TEXT NULL,
                  fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  KEY idx_estatus (estatus),
                  KEY idx_control (control)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS arneses_items (
                  id            INT AUTO_INCREMENT PRIMARY KEY,
                  sesion_id     INT NOT NULL,
                  tipo_id       INT NULL,
                  id_arnes      VARCHAR(100) NULL,
                  marca         VARCHAR(200) NULL,
                  modelo        VARCHAR(200) NULL,
                  serie         VARCHAR(200) NULL,
                  talla         VARCHAR(50)  NULL,
                  norma         VARCHAR(400) NULL,
                  longitud      VARCHAR(80)  NULL,
                  fecha_fabricacion DATE NULL,
                  fecha_retiro      DATE NULL,
                  vigencia          DATE NULL,
                  resultado     VARCHAR(20) NOT NULL DEFAULT 'APTO',
                  observaciones TEXT NULL,
                  qr_codigo     VARCHAR(20)  NULL,
                  cert_url      VARCHAR(500) NULL,
                  orden         INT NOT NULL DEFAULT 0,
                  fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  KEY idx_sesion (sesion_id),
                  KEY idx_qr (qr_codigo),
                  KEY idx_serie (serie)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS arneses_item_checklist (
                  id      INT AUTO_INCREMENT PRIMARY KEY,
                  item_id INT NOT NULL,
                  tag     VARCHAR(20) NOT NULL,
                  valor   ENUM('C','NC','NA') NULL,
                  UNIQUE KEY uk_item_tag (item_id, tag),
                  KEY idx_item (item_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS arneses_fotos (
                  id      INT AUTO_INCREMENT PRIMARY KEY,
                  item_id INT NOT NULL,
                  url     VARCHAR(500) NOT NULL,
                  orden   TINYINT NOT NULL DEFAULT 0,
                  KEY idx_item (item_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            // Al pasar la norma a seleccion multiple hace falta mas espacio
            try { $this->pdo->exec("ALTER TABLE arneses_items MODIFY norma VARCHAR(400) NULL"); } catch (\Throwable $e) {}

            // Columnas que alinean el flujo con el de grúas (envío por correo,
            // sustitución manual de documentos e inspector firmante).
            foreach ([
                "ALTER TABLE arneses_sesiones ADD COLUMN correo VARCHAR(300) NULL",
                "ALTER TABLE arneses_sesiones ADD COLUMN inspector_firma VARCHAR(150) NULL",
                "ALTER TABLE arneses_sesiones ADD COLUMN dictamen_manual_url VARCHAR(500) NULL",
                "ALTER TABLE arneses_sesiones ADD COLUMN fecha_enviado DATETIME NULL",
                "ALTER TABLE arneses_items ADD COLUMN cert_manual_url VARCHAR(500) NULL",
            ] as $sql) {
                try { $this->pdo->exec($sql); } catch (\Throwable $e) {}
            }
            $this->seedCatalogo();
        } catch (\PDOException $e) {
            error_log('[Arneses] migrate: ' . $e->getMessage());
        }
    }

    /**
     * Carga por única vez los tipos y sus puntos de inspección, basados en la
     * NOM-009-STPS-2011. Calidad puede editarlos después; este seed solo corre
     * cuando el catálogo está vacío, así que no pisa cambios posteriores.
     */
    private function seedCatalogo(): void {
        $hay = (int)$this->pdo->query("SELECT COUNT(*) FROM arneses_tipos")->fetchColumn();
        if ($hay > 0) return;

        $catalogo = [
            ['Arnés de cuerpo completo', 'arnes', [
                ['CINTAS',  'Cintas y bandas — sin cortes, deshilachado, abrasión, quemaduras ni decoloración'],
                ['COSTURA', 'Costuras — sin hilos rotos, sueltos o deshilachados'],
                ['HERRAJE', 'Herrajes, hebillas y ajustadores — sin deformación, fisuras, corrosión ni bordes filosos'],
                ['ARGOLLA', 'Argolla dorsal D — sin deformación ni desgaste, con libre movimiento'],
                ['ETIQUETA','Etiquetas legibles — marca, modelo, número de serie, fecha de fabricación y norma'],
                ['IMPACTO', 'Indicador de impacto sin activar'],
                ['VIDAUTIL','Dentro de la vida útil indicada por el fabricante'],
            ]],
            ['Línea de vida vertical', 'linea', [
                ['CUERPO',  'Cuerda, cinta o cable — sin cortes, deshilachado, torceduras, nudos ni corrosión'],
                ['ABSORB',  'Absorbedor de energía sin desplegar y con cubierta íntegra'],
                ['GANCHOS', 'Mosquetones y ganchos — seguro automático funcional, sin deformación ni corrosión'],
                ['TERMIN',  'Terminales y ojales — guardacabos, prensas y costuras en buen estado'],
                ['ETIQUETA','Etiquetas legibles y completas'],
                ['COMPAT',  'Longitud y compatibilidad adecuadas al sistema y al punto de anclaje'],
            ]],
            ['Línea de vida horizontal', 'linea', [
                ['CUERPO',  'Cable o cinta — sin cortes, deshilachado, torceduras ni corrosión'],
                ['TENSION', 'Tensión y flecha dentro de lo especificado por el fabricante'],
                ['ANCLAJE', 'Anclajes extremos e intermedios — firmes, sin deformación ni corrosión'],
                ['GANCHOS', 'Conectores y mosquetones — seguro funcional, sin deformación'],
                ['TERMIN',  'Terminales, prensas y tensores en buen estado'],
                ['ETIQUETA','Etiquetas y capacidad de usuarios legibles'],
            ]],
            ['Conector de anclaje / eslinga', 'linea', [
                ['CUERPO',  'Cinta o cuerda — sin cortes, deshilachado, quemaduras ni abrasión'],
                ['COSTURA', 'Costuras — sin hilos rotos o sueltos'],
                ['GANCHOS', 'Ganchos y mosquetones — seguro automático funcional, sin deformación'],
                ['ETIQUETA','Etiquetas legibles — capacidad, norma y fecha de fabricación'],
                ['VIDAUTIL','Dentro de la vida útil indicada por el fabricante'],
            ]],
            ['Absorbedor de energía', 'linea', [
                ['ABSORB',  'Sin desplegar, con cubierta y costuras íntegras'],
                ['GANCHOS', 'Conectores — seguro funcional, sin deformación ni corrosión'],
                ['ETIQUETA','Etiquetas legibles — norma, capacidad y fecha de fabricación'],
                ['VIDAUTIL','Dentro de la vida útil indicada por el fabricante'],
            ]],
        ];

        $insTipo = $this->pdo->prepare("INSERT INTO arneses_tipos (nombre, familia, activo) VALUES (?,?,1)");
        $insChk  = $this->pdo->prepare("INSERT INTO arneses_checklist (tipo_id, tag, descripcion, orden) VALUES (?,?,?,?)");
        foreach ($catalogo as [$nombre, $familia, $puntos]) {
            $insTipo->execute([$nombre, $familia]);
            $tipoId = (int)$this->pdo->lastInsertId();
            $orden = 1;
            foreach ($puntos as [$tag, $desc]) {
                $insChk->execute([$tipoId, $tag, $desc, $orden++]);
            }
        }
    }

    // ════════════════════════════════════════════════════════
    //  CATÁLOGO DE TIPOS Y CHECKLIST (Calidad)
    // ════════════════════════════════════════════════════════

    public function listarTipos(bool $soloActivos = true): array {
        $sql = "SELECT id, nombre, familia, activo FROM arneses_tipos"
             . ($soloActivos ? " WHERE activo = 1" : "")
             . " ORDER BY familia, nombre";
        $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['activo'] = (int)$r['activo']; }
        unset($r);
        return ['status' => 'success', 'tipos' => $rows];
    }

    /** Puntos de inspección de un tipo (los que verá el inspector). */
    public function listarChecklist(int $tipoId): array {
        $s = $this->pdo->prepare(
            "SELECT id, tag, descripcion, orden FROM arneses_checklist
             WHERE tipo_id = ? AND activo = 1 ORDER BY orden, id"
        );
        $s->execute([$tipoId]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['orden'] = (int)$r['orden']; }
        unset($r);
        return ['status' => 'success', 'checklist' => $rows];
    }

    public function guardarTipo(array $p): array {
        $id      = (int)($p['id'] ?? 0);
        $nombre  = trim((string)($p['nombre'] ?? ''));
        $familia = in_array($p['familia'] ?? '', ['arnes', 'linea'], true) ? $p['familia'] : 'arnes';
        $activo  = isset($p['activo']) ? (int)(bool)$p['activo'] : 1;
        if ($nombre === '') return ['status' => 'error', 'message' => 'El nombre del tipo es obligatorio.'];

        if ($id) {
            $this->pdo->prepare("UPDATE arneses_tipos SET nombre=?, familia=?, activo=? WHERE id=?")
                ->execute([$nombre, $familia, $activo, $id]);
            return ['status' => 'success', 'message' => 'Tipo actualizado.', 'id' => $id];
        }
        $this->pdo->prepare("INSERT INTO arneses_tipos (nombre, familia, activo) VALUES (?,?,?)")
            ->execute([$nombre, $familia, $activo]);
        return ['status' => 'success', 'message' => 'Tipo creado.', 'id' => (int)$this->pdo->lastInsertId()];
    }

    public function guardarPuntoChecklist(array $p): array {
        $id      = (int)($p['id'] ?? 0);
        $tipoId  = (int)($p['tipo_id'] ?? 0);
        $tag     = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '', (string)($p['tag'] ?? '')));
        $desc    = trim((string)($p['descripcion'] ?? ''));
        $orden   = (int)($p['orden'] ?? 0);
        if ($desc === '') return ['status' => 'error', 'message' => 'La descripción del punto es obligatoria.'];

        if ($id) {
            $this->pdo->prepare("UPDATE arneses_checklist SET descripcion=?, orden=? WHERE id=?")
                ->execute([$desc, $orden, $id]);
            return ['status' => 'success', 'message' => 'Punto actualizado.'];
        }
        if (!$tipoId || $tag === '') return ['status' => 'error', 'message' => 'Tipo y clave del punto son obligatorios.'];
        try {
            $this->pdo->prepare("INSERT INTO arneses_checklist (tipo_id, tag, descripcion, orden) VALUES (?,?,?,?)")
                ->execute([$tipoId, $tag, $desc, $orden]);
        } catch (\PDOException $e) {
            return ['status' => 'error', 'message' => 'Ya existe un punto con esa clave en este tipo.'];
        }
        return ['status' => 'success', 'message' => 'Punto agregado.'];
    }

    public function eliminarPuntoChecklist(int $id): array {
        $this->pdo->prepare("DELETE FROM arneses_checklist WHERE id = ?")->execute([$id]);
        return ['status' => 'success', 'message' => 'Punto eliminado.'];
    }

    // ════════════════════════════════════════════════════════
    //  INSPECCIÓN (Inspector)
    // ════════════════════════════════════════════════════════

    public function crearSesion(array $p, string $usuario): array {
        $cliente = trim((string)($p['cliente'] ?? ''));
        if ($cliente === '') return ['status' => 'error', 'message' => 'El cliente es obligatorio.'];
        $fecha = trim((string)($p['fecha'] ?? '')) ?: date('Y-m-d');

        $this->pdo->prepare("
            INSERT INTO arneses_sesiones (cliente, fecha, coordenadas, direccion, correo, usuario, estatus)
            VALUES (?,?,?,?,?,?, 'PENDIENTE')
        ")->execute([
            mb_strtoupper($cliente), date('Y-m-d', strtotime(str_replace('/', '-', $fecha))),
            trim((string)($p['coordenadas'] ?? '')) ?: null,
            trim((string)($p['direccion'] ?? '')) ?: null,
            trim((string)($p['correo'] ?? '')) ?: null,
            $usuario,
        ]);
        return ['status' => 'success', 'sesion_id' => (int)$this->pdo->lastInsertId()];
    }

    /**
     * Guarda una pieza con su checklist y fotos. $post viene de un FormData
     * (multipart) porque puede traer imágenes.
     */
    public function guardarItem(array $post, array $files): array {
        $sesionId = (int)($post['sesion_id'] ?? 0);
        if (!$sesionId) return ['status' => 'error', 'message' => 'Sesión no indicada.'];

        $chk = $this->pdo->prepare("SELECT id, estatus FROM arneses_sesiones WHERE id = ?");
        $chk->execute([$sesionId]);
        $ses = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$ses) return ['status' => 'error', 'message' => 'Sesión no encontrada.'];
        if (!in_array($ses['estatus'], ['PENDIENTE', 'DEVUELTO'], true)) {
            return ['status' => 'error', 'message' => 'La sesión ya fue aprobada; no admite cambios.'];
        }

        $tipoId = (int)($post['tipo_id'] ?? 0);
        if (!$tipoId) return ['status' => 'error', 'message' => 'Selecciona el tipo de equipo.'];

        $resultado = strtoupper(trim((string)($post['resultado'] ?? 'APTO')));
        if (!in_array($resultado, self::RESULTADOS, true)) $resultado = 'APTO';

        $fFab    = $this->fecha($post['fecha_fabricacion'] ?? '');
        $fRetiro = $this->fecha($post['fecha_retiro'] ?? '');
        // Si no se indica retiro pero sí fabricación, se asume la regla común
        // de 5 años de vida útil para equipo textil contra caídas.
        if (!$fRetiro && $fFab) $fRetiro = date('Y-m-d', strtotime($fFab . ' +5 years'));

        // Vigencia del certificado: un año, o el retiro del equipo si cae antes.
        $vigencia = date('Y-m-d', strtotime('+1 year'));
        if ($fRetiro && strtotime($fRetiro) < strtotime($vigencia)) $vigencia = $fRetiro;

        // El QR es opcional al capturar, pero si viene no puede estar ocupado
        $qrItem = trim((string)($post['qr_codigo'] ?? ''));
        if ($qrItem !== '' && !$this->qrDisponible($qrItem)) {
            return ['status' => 'error', 'message' => "El código QR $qrItem ya está asignado a otro registro."];
        }

        $up = fn($k) => mb_strtoupper(trim((string)($post[$k] ?? ''))) ?: null;

        $orden = (int)$this->pdo->query("SELECT COALESCE(MAX(orden),0) FROM arneses_items WHERE sesion_id = " . $sesionId)->fetchColumn();

        $this->pdo->prepare("
            INSERT INTO arneses_items
              (sesion_id, tipo_id, id_arnes, marca, modelo, serie, talla, norma, longitud,
               fecha_fabricacion, fecha_retiro, vigencia, resultado, observaciones, qr_codigo, orden)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ")->execute([
            $sesionId, $tipoId, $up('id_arnes'), $up('marca'), $up('modelo'), $up('serie'),
            $up('talla'), $up('norma'), $up('longitud'),
            $fFab, $fRetiro, $vigencia, $resultado,
            trim((string)($post['observaciones'] ?? '')) ?: null,
            $qrItem ?: null,
            $orden + 1,
        ]);
        $itemId = (int)$this->pdo->lastInsertId();

        // Checklist: llega como JSON {"TAG":"C"|"NC"|"NA"}
        $chkRaw = $post['checklist'] ?? '';
        $valores = is_string($chkRaw) ? json_decode($chkRaw, true) : (is_array($chkRaw) ? $chkRaw : []);
        if (is_array($valores) && $valores) {
            $ins = $this->pdo->prepare("INSERT INTO arneses_item_checklist (item_id, tag, valor) VALUES (?,?,?)");
            foreach ($valores as $tag => $val) {
                $val = strtoupper((string)$val);
                if (!in_array($val, ['C', 'NC', 'NA'], true)) continue;
                $tagLimpio = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '', (string)$tag));
                if ($tagLimpio === '') continue;
                try { $ins->execute([$itemId, $tagLimpio, $val]); } catch (\PDOException $e) { /* duplicado */ }
            }
        }

        $this->guardarFotos($itemId, $sesionId, $files);

        return ['status' => 'success', 'item_id' => $itemId];
    }

    private function guardarFotos(int $itemId, int $sesionId, array $files): void {
        if (empty($files['fotos']) || empty($files['fotos']['tmp_name'])) return;
        $dir = UPLOAD_DIR . 'arneses/' . $sesionId . '/';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);

        $nombres = (array)$files['fotos']['name'];
        $tmps    = (array)$files['fotos']['tmp_name'];
        $ins = $this->pdo->prepare("INSERT INTO arneses_fotos (item_id, url, orden) VALUES (?,?,?)");
        $n = 0;
        foreach ($tmps as $i => $tmp) {
            if ($n >= 6 || !$tmp || !is_uploaded_file($tmp)) continue;
            $ext = strtolower(pathinfo($nombres[$i] ?? '', PATHINFO_EXTENSION)) ?: 'jpg';
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) continue;
            $n++;
            $destino = $dir . 'arn_' . $itemId . '_' . $n . '.' . $ext;
            $final = function_exists('comprimirImagen') ? comprimirImagen($tmp, $destino) : null;
            if (!$final) { if (!@move_uploaded_file($tmp, $destino)) continue; $final = basename($destino); }
            $ins->execute([$itemId, 'uploads/arneses/' . $sesionId . '/' . $final, $n]);
        }
    }

    /**
     * Un código QR solo puede pertenecer a un registro en todo el sistema, así
     * que se revisa el banco y todas las tablas que consumen QR. $excluirItem
     * permite reasignar el mismo QR a la pieza que ya lo tenía.
     */
    private function qrDisponible(string $qr, int $excluirItem = 0): bool {
        $r = $this->pdo->prepare("SELECT id FROM qr_codigos WHERE identificador = ? AND usado = 1");
        $r->execute([$qr]);
        if ($r->fetch()) return false;

        foreach (['equipos', 'participantes_cursos', 'accesorios_sesiones', 'accesorios_izaje', 'arneses_sesiones'] as $tabla) {
            try {
                $r = $this->pdo->prepare("SELECT id FROM `$tabla` WHERE qr_codigo = ? LIMIT 1");
                $r->execute([$qr]);
                if ($r->fetch()) return false;
            } catch (\PDOException $e) { /* tabla aún no existe */ }
        }

        $sql = "SELECT id FROM arneses_items WHERE qr_codigo = ?";
        $params = [$qr];
        if ($excluirItem > 0) { $sql .= " AND id != ?"; $params[] = $excluirItem; }
        $r = $this->pdo->prepare($sql);
        $r->execute($params);
        return !$r->fetch();
    }

    /** Siguiente QR libre del banco, para sugerirlo en la captura. */
    public function siguienteQr(): array {
        $r = $this->pdo->query("SELECT identificador FROM qr_codigos WHERE usado = 0 ORDER BY CAST(identificador AS UNSIGNED) LIMIT 50");
        foreach ($r->fetchAll(PDO::FETCH_COLUMN) as $qr) {
            if ($this->qrDisponible((string)$qr)) return ['status' => 'success', 'qr' => $qr];
        }
        return ['status' => 'error', 'message' => 'No hay códigos QR disponibles en el banco.'];
    }

    private function fecha($v): ?string {
        $v = trim((string)$v);
        if ($v === '') return null;
        $t = strtotime(str_replace('/', '-', $v));
        return $t ? date('Y-m-d', $t) : null;
    }

    // ════════════════════════════════════════════════════════
    //  CONSULTA
    // ════════════════════════════════════════════════════════

    public function misSesiones(string $usuario): array {
        $s = $this->pdo->prepare("
            SELECT s.id, s.cliente, DATE_FORMAT(s.fecha,'%d/%m/%Y') AS fecha, s.control, s.estatus,
                   (SELECT COUNT(*) FROM arneses_items i WHERE i.sesion_id = s.id) AS total
            FROM arneses_sesiones s WHERE s.usuario = ?
            ORDER BY s.id DESC LIMIT 100
        ");
        $s->execute([$usuario]);
        return ['status' => 'success', 'sesiones' => $s->fetchAll(PDO::FETCH_ASSOC)];
    }

    public function listarSesiones(string $estatus = ''): array {
        $sql = "SELECT s.id, s.cliente, DATE_FORMAT(s.fecha,'%d/%m/%Y') AS fecha, s.direccion,
                       s.control, s.estatus, s.usuario, s.qr_codigo, s.dictamen_url,
                       s.dictamen_manual_url, s.correo, s.inspector_firma, s.motivo,
                       DATE_FORMAT(s.fecha_enviado,'%d/%m/%Y %H:%i') AS fecha_enviado,
                       (SELECT COUNT(*) FROM arneses_items i WHERE i.sesion_id = s.id) AS total,
                       (SELECT COUNT(*) FROM arneses_items i WHERE i.sesion_id = s.id AND i.resultado = 'APTO') AS aptos,
                       (SELECT COUNT(*) FROM arneses_items i WHERE i.sesion_id = s.id AND i.resultado = 'NO APTO') AS no_aptos
                FROM arneses_sesiones s";
        $params = [];
        if ($estatus !== '') { $sql .= " WHERE s.estatus = ?"; $params[] = $estatus; }
        $sql .= " ORDER BY s.id DESC LIMIT 300";
        $s = $this->pdo->prepare($sql);
        $s->execute($params);
        return ['status' => 'success', 'sesiones' => $s->fetchAll(PDO::FETCH_ASSOC)];
    }

    public function detalleSesion(int $id): array {
        $s = $this->pdo->prepare("
            SELECT s.*, DATE_FORMAT(s.fecha,'%d/%m/%Y') AS fecha_fmt
            FROM arneses_sesiones s WHERE s.id = ?
        ");
        $s->execute([$id]);
        $ses = $s->fetch(PDO::FETCH_ASSOC);
        if (!$ses) return ['status' => 'error', 'message' => 'Sesión no encontrada.'];

        $it = $this->pdo->prepare("
            SELECT i.*, COALESCE(t.nombre,'') AS tipo_nombre,
                   DATE_FORMAT(i.fecha_fabricacion,'%d/%m/%Y') AS fabricacion_fmt,
                   DATE_FORMAT(i.fecha_retiro,'%d/%m/%Y')      AS retiro_fmt,
                   DATE_FORMAT(i.vigencia,'%d/%m/%Y')          AS vigencia_fmt
            FROM arneses_items i
            LEFT JOIN arneses_tipos t ON t.id = i.tipo_id
            WHERE i.sesion_id = ? ORDER BY i.orden, i.id
        ");
        $it->execute([$id]);
        $items = $it->fetchAll(PDO::FETCH_ASSOC);

        if ($items) {
            $ids = array_column($items, 'id');
            $in  = implode(',', array_fill(0, count($ids), '?'));

            $ck = $this->pdo->prepare("
                SELECT c.item_id, c.tag, c.valor, COALESCE(d.descripcion, c.tag) AS descripcion
                FROM arneses_item_checklist c
                LEFT JOIN arneses_items i  ON i.id = c.item_id
                LEFT JOIN arneses_checklist d ON d.tipo_id = i.tipo_id AND d.tag = c.tag
                WHERE c.item_id IN ($in) ORDER BY d.orden
            ");
            $ck->execute($ids);
            $porItem = [];
            foreach ($ck->fetchAll(PDO::FETCH_ASSOC) as $r) $porItem[(int)$r['item_id']][] = $r;

            $ft = $this->pdo->prepare("SELECT item_id, url FROM arneses_fotos WHERE item_id IN ($in) ORDER BY orden");
            $ft->execute($ids);
            $fotosPorItem = [];
            foreach ($ft->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $fotosPorItem[(int)$r['item_id']][] = rtrim(SITE_URL, '/') . '/' . ltrim($r['url'], '/');
            }

            foreach ($items as &$i) {
                $i['id'] = (int)$i['id'];
                $i['checklist'] = $porItem[$i['id']] ?? [];
                $i['fotos']     = $fotosPorItem[$i['id']] ?? [];
            }
            unset($i);
        }

        $ses['id'] = (int)$ses['id'];
        return ['status' => 'success', 'sesion' => $ses, 'items' => $items];
    }

    // ════════════════════════════════════════════════════════
    //  CALIDAD
    // ════════════════════════════════════════════════════════

    /** Corrige los datos de una pieza (antes de aprobar). */
    public function editarItem(array $p): array {
        $id = (int)($p['id'] ?? 0);
        if (!$id) return ['status' => 'error', 'message' => 'Pieza no indicada.'];

        $campos = [];
        $vals   = [];
        foreach (['id_arnes','marca','modelo','serie','talla','norma','longitud'] as $c) {
            if (array_key_exists($c, $p)) { $campos[] = "$c = ?"; $vals[] = mb_strtoupper(trim((string)$p[$c])) ?: null; }
        }
        if (array_key_exists('observaciones', $p)) { $campos[] = "observaciones = ?"; $vals[] = trim((string)$p['observaciones']) ?: null; }
        if (array_key_exists('tipo_id', $p) && (int)$p['tipo_id']) { $campos[] = "tipo_id = ?"; $vals[] = (int)$p['tipo_id']; }
        if (array_key_exists('resultado', $p)) {
            $r = strtoupper(trim((string)$p['resultado']));
            if (in_array($r, self::RESULTADOS, true)) { $campos[] = "resultado = ?"; $vals[] = $r; }
        }
        foreach (['fecha_fabricacion','fecha_retiro','vigencia'] as $c) {
            if (array_key_exists($c, $p)) { $campos[] = "$c = ?"; $vals[] = $this->fecha($p[$c]); }
        }
        if (array_key_exists('qr_codigo', $p)) {
            $q = trim((string)$p['qr_codigo']);
            if ($q !== '' && !$this->qrDisponible($q, $id)) {
                return ['status' => 'error', 'message' => "El código QR $q ya está asignado a otro registro."];
            }
            $campos[] = "qr_codigo = ?"; $vals[] = $q ?: null;
        }
        if (!$campos) return ['status' => 'success', 'message' => 'Sin cambios.'];

        $vals[] = $id;
        $this->pdo->prepare("UPDATE arneses_items SET " . implode(', ', $campos) . " WHERE id = ?")->execute($vals);
        return ['status' => 'success', 'message' => 'Pieza actualizada.'];
    }

    /**
     * Aprueba la sesión: asigna el folio y el QR de la sesión, y verifica que
     * cada pieza tenga su QR (porque cada una lleva certificado propio).
     */
    public function aprobarSesion(array $p, string $usuario): array {
        $id = (int)($p['id'] ?? 0);
        $qr = trim((string)($p['qr'] ?? ''));
        if (!$id) return ['status' => 'error', 'message' => 'Sesión no indicada.'];
        if ($qr === '') return ['status' => 'error', 'message' => 'Indica el código QR de la sesión.'];

        $s = $this->pdo->prepare("SELECT * FROM arneses_sesiones WHERE id = ?");
        $s->execute([$id]);
        $ses = $s->fetch(PDO::FETCH_ASSOC);
        if (!$ses) return ['status' => 'error', 'message' => 'Sesión no encontrada.'];
        // RETORNADO entra aquí igual que en grúas: Certificaciones lo regresó a
        // Calidad, conserva su QR y vuelve a ser aprobable sin recapturar.
        if (!in_array($ses['estatus'], ['PENDIENTE', 'DEVUELTO', 'RETORNADO', ''], true)) {
            return ['status' => 'error', 'message' => 'Esta sesión ya fue aprobada.'];
        }

        $n = (int)$this->pdo->query("SELECT COUNT(*) FROM arneses_items WHERE sesion_id = " . $id)->fetchColumn();
        if ($n === 0) return ['status' => 'error', 'message' => 'La sesión no tiene piezas inspeccionadas.'];

        // Cada pieza necesita QR propio: de ahí cuelga su certificado individual
        $sin = (int)$this->pdo->query(
            "SELECT COUNT(*) FROM arneses_items WHERE sesion_id = " . $id . " AND (qr_codigo IS NULL OR qr_codigo = '')"
        )->fetchColumn();
        if ($sin > 0) {
            return ['status' => 'error', 'message' => "Faltan códigos QR: $sin pieza(s) sin QR asignado. Cada pieza lleva su propio certificado."];
        }

        if (!$this->qrDisponible($qr)) {
            return ['status' => 'error', 'message' => 'Ese código QR ya está asignado a otro registro.'];
        }

        $control = $ses['control'] ?: generarControl($this->pdo, $ses['cliente']);
        $this->pdo->prepare(
            "UPDATE arneses_sesiones SET estatus='APROBADO_CALIDAD', qr_codigo=?, control=?, motivo=NULL WHERE id=?"
        )->execute([$qr, $control, $id]);

        if (function_exists('qrRegistrarUsado')) qrRegistrarUsado($this->pdo, $qr, null);
        $this->historial($usuario, $id, $ses['estatus'], 'APROBADO_CALIDAD');

        return ['status' => 'success', 'message' => 'Sesión aprobada y enviada a Certificaciones.', 'control' => $control];
    }

    public function devolverSesion(array $p, string $usuario): array {
        $id     = (int)($p['id'] ?? 0);
        $motivo = trim((string)($p['motivo'] ?? ''));
        if (!$id) return ['status' => 'error', 'message' => 'Sesión no indicada.'];
        $ant = $this->pdo->prepare("SELECT estatus FROM arneses_sesiones WHERE id = ?");
        $ant->execute([$id]);
        $anterior = (string)($ant->fetchColumn() ?: '');
        $this->pdo->prepare("UPDATE arneses_sesiones SET estatus='DEVUELTO', motivo=? WHERE id=?")
            ->execute([$motivo ?: null, $id]);
        $this->historial($usuario, $id, $anterior, 'DEVUELTO');
        return ['status' => 'success', 'message' => 'Sesión devuelta para corrección.'];
    }

    /**
     * Certificaciones regresa el expediente a Calidad. Equivale a
     * `rechazarACertificacion` en grúas: borra los documentos emitidos para que
     * no queden visibles en el portal del cliente, pero CONSERVA el QR
     * reservado, de modo que al re-aprobar aparece precargado.
     */
    public function retornarSesion(array $p, string $usuario): array {
        $id     = (int)($p['id'] ?? 0);
        $motivo = trim((string)($p['motivo'] ?? ''));
        if (!$id) return ['status' => 'error', 'message' => 'Sesión no indicada.'];

        $s = $this->pdo->prepare("SELECT estatus FROM arneses_sesiones WHERE id = ?");
        $s->execute([$id]);
        $anterior = $s->fetchColumn();
        if ($anterior === false) return ['status' => 'error', 'message' => 'Sesión no encontrada.'];

        $this->pdo->prepare(
            "UPDATE arneses_sesiones
             SET estatus='RETORNADO', dictamen_url=NULL, dictamen_manual_url=NULL,
                 fecha_enviado=NULL, motivo=?
             WHERE id=?"
        )->execute([$motivo ?: null, $id]);
        $this->pdo->prepare("UPDATE arneses_items SET cert_url=NULL, cert_manual_url=NULL WHERE sesion_id=?")
            ->execute([$id]);

        $this->historial($usuario, $id, (string)$anterior, 'RETORNADO');
        return ['status' => 'success', 'message' => 'Expediente retornado a Calidad.'];
    }

    /**
     * Calidad corrige el encabezado de la sesión: cliente, fecha, lugar, correo
     * de envío e inspector que firma (mismo criterio que en grúas, donde Calidad
     * puede cambiar el inspector firmante de cada reporte).
     */
    public function guardarDatosSesion(array $p, string $usuario): array {
        $id = (int)($p['id'] ?? 0);
        if (!$id) return ['status' => 'error', 'message' => 'Sesión no indicada.'];

        $mapa = [
            'cliente'         => fn($v) => mb_strtoupper(trim((string)$v)) ?: null,
            'direccion'       => fn($v) => trim((string)$v) ?: null,
            'correo'          => fn($v) => trim((string)$v) ?: null,
            'inspector_firma' => fn($v) => trim((string)$v) ?: null,
            'fecha'           => fn($v) => $this->fecha($v),
        ];

        $campos = []; $vals = [];
        foreach ($mapa as $campo => $norm) {
            if (!array_key_exists($campo, $p)) continue;
            $campos[] = "$campo = ?";
            $vals[]   = $norm($p[$campo]);
        }
        if (!$campos) return ['status' => 'success', 'message' => 'Sin cambios.'];

        $correo = trim((string)($p['correo'] ?? ''));
        if ($correo !== '') {
            foreach (array_filter(array_map('trim', explode(',', $correo))) as $addr) {
                if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                    return ['status' => 'error', 'message' => "Correo inválido: $addr"];
                }
            }
        }

        $vals[] = $id;
        $this->pdo->prepare("UPDATE arneses_sesiones SET " . implode(', ', $campos) . " WHERE id = ?")->execute($vals);
        $this->historial($usuario, $id, null, 'datos actualizados');
        return ['status' => 'success', 'message' => 'Datos de la inspección actualizados.'];
    }

    /**
     * Anota el cambio en historial_general. `equipo_id` queda en NULL porque esa
     * columna referencia la tabla `equipos` (grúas) y un id de sesión de arneses
     * apuntaría a un equipo ajeno; la referencia va en el nombre del campo.
     */
    private function historial(string $usuario, int $sesionId, ?string $anterior, ?string $nuevo): void {
        if (!function_exists('registrarHistorial')) return;
        try {
            registrarHistorial($this->pdo, $usuario, null, "arnes#$sesionId.estatus", $anterior ?: null, $nuevo);
        } catch (\Throwable $e) {
            error_log('[Arneses] historial: ' . $e->getMessage());
        }
    }

    // ════════════════════════════════════════════════════════
    //  DOCUMENTOS
    // ════════════════════════════════════════════════════════

    /** HTML → PDF con mPDF, guardado en uploads/reportes/. */
    private function htmlToPdf(string $html, string $folio, string $sufijo, string $pieDoc = ''): string {
        if (!class_exists('\\Mpdf\\Mpdf')) {
            $autoload = __DIR__ . '/../vendor/autoload.php';
            if (file_exists($autoload)) require_once $autoload;
        }
        if (!class_exists('\\Mpdf\\Mpdf')) throw new \RuntimeException('mPDF no disponible.');

        $dir = UPLOAD_DIR . 'reportes/';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);

        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8', 'format' => 'A4',
            'margin_left' => 13, 'margin_right' => 13, 'margin_top' => 12, 'margin_bottom' => 16,
            'margin_footer' => 7,
            'default_font' => 'dejavusans',
            'tempDir' => sys_get_temp_dir() . '/mpdf',
        ]);
        $mpdf->SetBasePath(__DIR__ . '/../');

        // Pie con folio y paginación en todas las hojas: un documento de varias
        // páginas necesita que cada hoja se pueda identificar por sí sola.
        if ($pieDoc !== '') {
            $mpdf->SetHTMLFooter(
                '<table width="100%" style="border-top:1px solid #dde5f0;font-size:6.8pt;color:#7a8494;padding-top:3px">
                   <tr>
                     <td width="60%">' . $pieDoc . '</td>
                     <td width="40%" style="text-align:right">Página {PAGENO} de {nbpg}</td>
                   </tr>
                 </table>'
            );
        }

        $prev = (int)ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', 10000000);
        $mpdf->WriteHTML($html);
        ini_set('pcre.backtrack_limit', $prev);
        $mpdf->SetProtection(['print'], '', 'Avba@Cert2024!');

        $nombre = $sufijo . '_AVBA_' . preg_replace('/[^A-Za-z0-9._-]/', '', $folio) . '_' . date('Ymd_His') . '.pdf';
        $mpdf->Output($dir . $nombre, 'F');   // mPDF: (nombre, destino)
        if (!is_file($dir . $nombre)) {
            throw new \RuntimeException('El PDF no se pudo guardar en el servidor (revisa permisos de uploads/reportes).');
        }
        return 'uploads/reportes/' . $nombre;
    }

    private function esc($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }

    /** Ruta relativa de un activo de marca si existe (mPDF resuelve sobre la raíz). */
    private function activo(string $rel): string {
        return is_file(__DIR__ . '/../' . $rel) ? $rel : '';
    }

    /**
     * Hoja de estilos común de los documentos de arneses. Reproduce el lenguaje
     * visual de los certificados de maquinaria (banda azul, barra de título,
     * etiquetas de sección) para que el cliente reciba documentos consistentes.
     * mPDF no soporta flexbox: toda la maquetación va con tablas.
     */
    private function estilosDoc(): string {
        // Sin regla @page: define los márgenes en el constructor de mPDF. Una
        // @page en la hoja de estilos desplaza el pie de página HTML y lo deja
        // fuera de la hoja.
        return '<style>
  body { font-family: dejavusans; font-size: 9pt; color: #1a1a2e; margin: 0; }
  h1,h2,h3,p { margin: 0; }

  .hdr        { border-bottom: 3px solid #185FA5; padding: 0 2px 6px; }
  .hdr-t      { font-size: 13.5pt; font-weight: bold; color: #0C447C; }
  .hdr-s      { font-size: 7.5pt; color: #5a6072; }
  .hdr-r      { text-align: right; font-size: 7.5pt; color: #5a6072; line-height: 1.55; }
  .title-bar  { background: #0C447C; text-align: center; padding: 7px 0; }
  .title-bar h2 { color: #fff; font-size: 12.5pt; font-weight: bold; letter-spacing: 2.5px; }
  .title-bar .sub { color: #a8c6e8; font-size: 7.5pt; letter-spacing: .5px; margin-top: 2px; }
  .folio-bar  { background: #E6F1FB; text-align: center; padding: 5px 0;
                border-bottom: 2px solid #185FA5; }
  .folio-bar span { font-size: 10.5pt; color: #0C447C; font-weight: bold; letter-spacing: 1px; }

  .slabel     { background: #185FA5; color: #fff; font-size: 7.5pt; font-weight: bold;
                letter-spacing: 1.2px; padding: 4px 10px; margin: 11px 0 0; }
  .dt         { width: 100%; border-collapse: collapse; }
  .dt td      { padding: 3.5px 9px; border-bottom: 1px solid #dde5f0; font-size: 8.5pt; }
  .dt .lbl    { width: 22%; font-weight: bold; color: #185FA5; background: #f5f8fd; }
  .dt .val    { color: #1a1a2e; }

  .grid       { width: 100%; border-collapse: collapse; font-size: 7.8pt; }
  .grid th    { background: #0C447C; color: #fff; padding: 5px 4px; font-size: 6.8pt; }
  .grid td    { padding: 3px 4px; border-bottom: 1px solid #dde5f0; }
  .grid .alt  { background: #f7fafd; }
  .juntos     { page-break-inside: avoid; }

  .kpi        { width: 100%; border-collapse: separate; border-spacing: 6px 0; margin-top: 8px; }
  .kpi td     { text-align: center; padding: 9px 4px; border-radius: 5px; }
  .kpi .n     { font-size: 16pt; font-weight: bold; }
  .kpi .l     { font-size: 7pt; letter-spacing: .8px; }

  .nota       { font-size: 7.8pt; color: #5a6072; line-height: 1.55; }
  .legal      { margin-top: 6px; padding: 5px 10px; background: #f5f8fd;
                border-top: 1px solid #dde5f0; font-size: 7pt; color: #7a8494;
                text-align: center; line-height: 1.5; }
  .sign-t     { font-size: 8pt; font-weight: bold; }
  .sign-s     { font-size: 7pt; color: #5a6072; }
</style>';
    }

    /**
     * Línea de firma. mPDF no dibuja el borde de un div vacío, así que la línea
     * se traza con la celda de una tabla.
     */
    private function lineaFirma(): string {
        return '<table width="82%" style="margin:0 auto 3px"><tr>
                  <td style="border-top:1px solid #333;font-size:1pt">&nbsp;</td>
                </tr></table>';
    }

    /** Banda superior, barra de título y folio: encabezado común. */
    private function encabezado(string $titulo, string $folio, string $subtitulo = ''): string {
        // Membrete en blanco: el logotipo es azul oscuro y sobre la banda azul
        // quedaba ilegible.
        $logo  = $this->activo('assets/logos/avba.png');
        $img   = $logo
            ? '<img src="' . $logo . '" style="height:34px">'
            : '<div class="hdr-t">AVBA Inspections</div>';
        $acred = defined('NO_ACREDITACION') ? (string)NO_ACREDITACION : '';
        $sub   = $subtitulo ?: 'Equipo de protección personal contra caídas';

        return '
        <div class="hdr">
          <table width="100%"><tr>
            <td width="34%" style="vertical-align:middle">' . $img . '</td>
            <td width="66%" class="hdr-r">
              <strong style="color:#0C447C;font-size:8.5pt">AVBA Inspections, Certifications &amp; Maintenance S.A.S. de C.V.</strong><br>'
              . ($acred ? 'Unidad de Inspección ' . $this->esc($acred) . ' · ' : '')
              . 'Altamira, Tamaulipas · México<br>gestion.avba.com.mx
            </td>
          </tr></table>
        </div>
        <div class="title-bar">
          <h2>' . $this->esc($titulo) . '</h2>
          <div class="sub">' . $this->esc($sub) . '</div>
        </div>
        <div class="folio-bar"><span>Folio: ' . $this->esc(formatoFolio($folio)) . '</span></div>';
    }

    /** Franja de acreditaciones al pie de los documentos. */
    private function franjaAcreditaciones(): string {
        $imgs = '';
        foreach ([['assets/logos/stps.png', 22], ['assets/logos/iso.png', 22], ['assets/logos/asme.png', 22]] as [$rel, $h]) {
            $ok = $this->activo($rel);
            if ($ok) $imgs .= '<td style="text-align:center"><img src="' . $ok . '" style="height:' . $h . 'px"></td>';
        }
        if ($imgs === '') return '';
        return '<table width="100%" style="margin-top:8px"><tr>' . $imgs . '</tr></table>';
    }

    /**
     * Consolida las normas capturadas (selección múltiple, separadas por coma o
     * salto de línea) en una lista sin repeticiones.
     */
    private function normasDe(array $items): array {
        $out = [];
        foreach ($items as $i) {
            foreach (preg_split('/[,\n;]+/', (string)($i['norma'] ?? '')) as $n) {
                $n = trim($n);
                if ($n !== '' && !in_array($n, $out, true)) $out[] = $n;
            }
        }
        if (!$out) $out[] = 'NOM-009-STPS-2011';
        return $out;
    }

    /** Fotos de una pieza como rutas relativas (mPDF no descarga por HTTP). */
    private function fotosDe(int $itemId): array {
        $s = $this->pdo->prepare("SELECT url FROM arneses_fotos WHERE item_id = ? ORDER BY orden LIMIT 6");
        $s->execute([$itemId]);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $u) {
            $rel = ltrim(str_replace(rtrim(SITE_URL, '/'), '', (string)$u), '/');
            if (is_file(__DIR__ . '/../' . $rel)) $out[] = $rel;
        }
        return $out;
    }

    /** Color y fondo asociados a un resultado de inspección. */
    private function colorRes(string $r): array {
        if ($r === 'APTO')    return ['#1b7a3d', '#e8f5e9'];
        if ($r === 'NO APTO') return ['#b91c1c', '#fdecea'];
        return ['#92400e', '#fff8e1'];
    }

    /**
     * DICTAMEN TÉCNICO del lote: documento completo que ampara la inspección —
     * alcance, marco normativo, metodología, relación de equipos, hallazgos y
     * conclusiones — con el mismo lenguaje visual que los certificados de
     * maquinaria.
     */
    public function generarDictamen(int $sesionId): array {
        $det = $this->detalleSesion($sesionId);
        if ($det['status'] !== 'success') return $det;
        $ses = $det['sesion']; $items = $det['items'];
        if (!$items) return ['status' => 'error', 'message' => 'La inspección no tiene piezas.'];
        if (empty($ses['control'])) return ['status' => 'error', 'message' => 'La inspección aún no tiene folio; debe aprobarse en Calidad.'];

        $total  = count($items);
        $aptos  = count(array_filter($items, fn($i) => $i['resultado'] === 'APTO'));
        $noApt  = count(array_filter($items, fn($i) => $i['resultado'] === 'NO APTO'));
        $cond   = $total - $aptos - $noApt;
        $pct    = $total ? (int)round($aptos * 100 / $total) : 0;

        // Dictamen global: basta una pieza no apta para que el lote quede
        // condicionado; el resto se sigue amparando individualmente.
        if ($noApt > 0)      { $veredicto = 'CON EQUIPO FUERA DE SERVICIO'; $vColor = '#b91c1c'; $vFondo = '#fdecea'; }
        elseif ($cond > 0)   { $veredicto = 'APTO CON CONDICIONES';         $vColor = '#92400e'; $vFondo = '#fff8e1'; }
        else                 { $veredicto = 'APTO PARA SERVICIO';           $vColor = '#1b7a3d'; $vFondo = '#e8f5e9'; }

        // ── Relación de equipos ──────────────────────────────
        $filas = '';
        foreach ($items as $n => $i) {
            [$c] = $this->colorRes($i['resultado']);
            $alt = $n % 2 ? ' class="alt"' : '';
            $filas .= '<tr' . $alt . '>
                <td style="text-align:center">' . ($n + 1) . '</td>
                <td>' . $this->esc($i['tipo_nombre']) . '</td>
                <td>' . $this->esc(trim(($i['marca'] ?? '') . ' ' . ($i['modelo'] ?? '')) ?: '—') . '</td>
                <td>' . $this->esc($i['serie'] ?: '—') . '</td>
                <td style="text-align:center">' . $this->esc($i['id_arnes'] ?: '—') . '</td>
                <td style="text-align:center">' . $this->esc($i['fabricacion_fmt'] ?: '—') . '</td>
                <td style="text-align:center">' . $this->esc($i['retiro_fmt'] ?: '—') . '</td>
                <td style="text-align:center;color:' . $c . ';font-weight:bold;font-size:7pt;white-space:nowrap">' . $this->esc($i['resultado']) . '</td>
              </tr>';
        }

        // ── Hallazgos: puntos no conformes y observaciones ───
        $hallazgos = '';
        foreach ($items as $n => $i) {
            $nc = array_values(array_filter($i['checklist'] ?? [], fn($c) => ($c['valor'] ?? '') === 'NC'));
            if (!$nc && empty($i['observaciones'])) continue;
            [$c] = $this->colorRes($i['resultado']);
            $hallazgos .= '<div style="border-left:3px solid ' . $c . ';background:#f9fafb;padding:7px 10px;margin-top:7px">
                <div style="font-size:8.5pt;font-weight:bold">Equipo ' . ($n + 1) . ' — '
                . $this->esc($i['tipo_nombre']) . ($i['serie'] ? ' · Serie ' . $this->esc($i['serie']) : '')
                . ' <span style="color:' . $c . '">(' . $this->esc($i['resultado']) . ')</span></div>';
            foreach ($nc as $c2) {
                $hallazgos .= '<div class="nota" style="margin-top:3px">• No conforme: ' . $this->esc($c2['descripcion']) . '</div>';
            }
            if (!empty($i['observaciones'])) {
                $hallazgos .= '<div class="nota" style="margin-top:3px;font-style:italic">Observaciones: '
                            . $this->esc($i['observaciones']) . '</div>';
            }
            $hallazgos .= '</div>';
        }
        if ($hallazgos === '') {
            $hallazgos = '<div class="nota" style="margin-top:7px">No se detectaron no conformidades ni observaciones en los equipos inspeccionados.</div>';
        }

        // ── Marco normativo ──────────────────────────────────
        $normas = '';
        foreach ($this->normasDe($items) as $nm) {
            $normas .= '<tr><td width="4%" style="border:none;padding:2px 0">•</td>
                        <td style="border:none;padding:2px 0;font-size:8pt">' . $this->esc($nm) . '</td></tr>';
        }

        $qrImg = !empty($ses['qr_codigo'])
            ? '<img src="' . qrDataUri(textoQR($ses['qr_codigo']), 260, 3) . '" style="width:78px">'
              . '<div style="font-size:6.5pt;color:#5a6072;margin-top:2px">Valida este dictamen</div>'
            : '';
        $inspector = $this->esc($ses['inspector_firma'] ?: $ses['usuario'] ?: '');
        $sello     = $this->activo('assets/sellos/sello.png');

        $html = '<html><head><meta charset="UTF-8">' . $this->estilosDoc() . '</head><body>'
          . $this->encabezado('DICTAMEN TÉCNICO DE INSPECCIÓN', $ses['control'],
                              'Equipo de protección personal contra caídas')

          . '<div class="slabel">DATOS DEL CLIENTE Y DE LA INSPECCIÓN</div>
             <table class="dt">
               <tr><td class="lbl">CLIENTE</td><td class="val">' . $this->esc($ses['cliente']) . '</td>
                   <td rowspan="4" width="20%" style="text-align:center;background:#fff">' . $qrImg . '</td></tr>
               <tr><td class="lbl">LUGAR DE INSPECCIÓN</td><td class="val">' . $this->esc($ses['direccion'] ?: '—') . '</td></tr>
               <tr><td class="lbl">FECHA DE INSPECCIÓN</td><td class="val">' . $this->esc($ses['fecha_fmt']) . '</td></tr>
               <tr><td class="lbl">INSPECTOR RESPONSABLE</td><td class="val">' . $inspector . '</td></tr>
             </table>

             <div class="slabel">RESUMEN DEL DICTAMEN</div>
             <table class="kpi">
               <tr>
                 <td style="background:#eef2f7"><div class="n" style="color:#0C447C">' . $total . '</div><div class="l">EQUIPOS</div></td>
                 <td style="background:#e8f5e9"><div class="n" style="color:#1b7a3d">' . $aptos . '</div><div class="l">APTOS</div></td>
                 <td style="background:#fff8e1"><div class="n" style="color:#92400e">' . $cond . '</div><div class="l">CONDICIONADOS</div></td>
                 <td style="background:#fdecea"><div class="n" style="color:#b91c1c">' . $noApt . '</div><div class="l">NO APTOS</div></td>
               </tr>
             </table>
             <table width="100%" style="margin-top:9px;border-collapse:collapse">
               <tr>
                 <td width="26%" style="font-size:7.5pt;color:#5a6072">Índice de conformidad</td>
                 <td width="58%">
                   <table width="100%" style="border-collapse:collapse;background:#e6eaf0">
                     <tr><td width="' . max($pct, 1) . '%" style="background:#1b7a3d;height:9px"></td>
                         <td width="' . (100 - $pct) . '%"></td></tr>
                   </table>
                 </td>
                 <td width="16%" style="text-align:right;font-size:10pt;font-weight:bold;color:#1b7a3d">' . $pct . '%</td>
               </tr>
             </table>
             <div style="background:' . $vFondo . ';border:2px solid ' . $vColor . ';border-radius:5px;text-align:center;padding:9px;margin-top:10px">
               <div style="font-size:7pt;color:#5a6072;letter-spacing:1px">DICTAMEN GENERAL DEL LOTE</div>
               <div style="font-size:14pt;font-weight:bold;color:' . $vColor . '">' . $veredicto . '</div>
             </div>

             <div class="slabel">OBJETIVO Y ALCANCE</div>
             <div class="nota" style="padding:7px 2px">
               Verificar el estado físico y funcional del equipo de protección personal contra
               caídas relacionado en este dictamen, a fin de determinar si es apto para
               continuar en servicio. El alcance comprende la inspección visual y funcional
               de los ' . $total . ' equipo(s) listados, en las condiciones y el lugar
               indicados. No comprende ensayos destructivos, pruebas de carga dinámica ni
               equipos no relacionados en este documento.
             </div>

             <div class="slabel">MARCO NORMATIVO APLICADO</div>
             <table width="100%" style="margin:5px 0 0"><tbody>' . $normas . '</tbody></table>

             <div class="slabel">METODOLOGÍA Y CRITERIOS DE ACEPTACIÓN</div>
             <div class="nota" style="padding:7px 2px">
               Cada equipo se sometió a una lista de verificación específica por tipo, en la que
               cada punto se califica como <strong>C</strong> (conforme), <strong>NC</strong>
               (no conforme) o <strong>NA</strong> (no aplica). Los criterios de dictamen son:
             </div>
             <table class="grid" style="margin-top:4px">
               <tr><th width="22%">RESULTADO</th><th>CRITERIO</th></tr>
               <tr><td style="color:#1b7a3d;font-weight:bold;text-align:center">APTO</td>
                   <td>Sin puntos no conformes. El equipo puede permanecer en servicio hasta su fecha de retiro.</td></tr>
               <tr class="alt"><td style="color:#92400e;font-weight:bold;text-align:center">CONDICIONADO</td>
                   <td>Presenta desviaciones menores que no comprometen la resistencia. Requiere corrección y seguimiento.</td></tr>
               <tr><td style="color:#b91c1c;font-weight:bold;text-align:center">NO APTO</td>
                   <td>Presenta daño, deterioro o vencimiento. Debe retirarse de servicio y destruirse para impedir su reutilización.</td></tr>
             </table>

             <div class="juntos">
             <div class="slabel">RELACIÓN DE EQUIPOS INSPECCIONADOS</div>
             <table class="grid" style="margin-top:4px">
               <thead><tr>
                 <th width="4%">#</th><th width="20%">TIPO DE EQUIPO</th><th width="17%">MARCA / MODELO</th>
                 <th width="15%">No. SERIE</th><th width="10%">ID</th><th width="10%">FABRIC.</th>
                 <th width="10%">RETIRO</th><th width="15%">RESULTADO</th>
               </tr></thead>
               <tbody>' . $filas . '</tbody>
             </table>
             </div>

             <div class="slabel">HALLAZGOS Y NO CONFORMIDADES</div>'
             . $hallazgos

             . '<div class="slabel">CONCLUSIONES Y RECOMENDACIONES</div>
             <div class="nota" style="padding:7px 2px">
               De los ' . $total . ' equipo(s) inspeccionados, <strong>' . $aptos . '</strong> resultaron
               aptos para continuar en servicio, <strong>' . $cond . '</strong> quedaron condicionados y
               <strong>' . $noApt . '</strong> resultaron no aptos.'
               . ($noApt > 0
                  ? ' Los equipos dictaminados como NO APTOS deben retirarse de servicio de forma
                     inmediata y destruirse para impedir su reutilización; no se emite certificado para ellos.'
                  : '')
               . ($cond > 0
                  ? ' Los equipos CONDICIONADOS pueden usarse una vez atendidas las observaciones
                     señaladas, bajo responsabilidad del usuario.'
                  : '') . '
               Se recomienda mantener la inspección previa al uso por parte del usuario en cada
               jornada, la inspección documentada por persona competente al menos cada doce meses,
               y el resguardo del equipo en lugar seco, ventilado y libre de químicos, bordes
               filosos y radiación solar directa. Cada equipo apto cuenta con certificado
               individual y código QR de validación.
             </div>

             <div class="juntos">
             <table width="100%" style="margin-top:26px">
               <tr>
                 <td width="33%" style="text-align:center">'
                   . ($sello ? '<img src="' . $sello . '" style="height:52px"><br>' : '<div style="height:52px"></div>')
                   . '' . $this->lineaFirma() . '
                      <div class="sign-t">' . ($inspector ?: 'Inspector responsable') . '</div>
                      <div class="sign-s">Inspector · AVBA Inspections</div></td>
                 <td width="33%" style="text-align:center">
                      <div style="height:52px"></div>
                      ' . $this->lineaFirma() . '
                      <div class="sign-t">Unidad de Inspección</div>
                      <div class="sign-s">Revisión y aprobación técnica</div></td>
                 <td width="34%" style="text-align:center">
                      <div style="height:52px"></div>
                      ' . $this->lineaFirma() . '
                      <div class="sign-t">Por el cliente</div>
                      <div class="sign-s">Nombre, firma y fecha</div></td>
               </tr>
             </table>'
             . $this->franjaAcreditaciones()
             . '<div class="legal">
                  Este dictamen ampara únicamente los equipos relacionados y las condiciones
                  observadas al momento de la inspección. Pierde validez si el equipo sufre un
                  evento de caída, alteración, reparación no autorizada o daño, o al llegar su
                  fecha de retiro obligatorio, lo que ocurra primero.
                  Folio <strong>' . $this->esc(formatoFolio($ses['control'])) . '</strong> · '
                  . date('Y') . ' AVBA Inspections, Certifications and Maintenance S.A.S. de C.V.
                </div></div>
             </body></html>';

        $pie = 'Dictamen técnico ' . $this->esc(formatoFolio($ses['control']))
             . ' · ' . $this->esc($ses['cliente']);
        $url = $this->htmlToPdf($html, $ses['control'], 'DICT_ARN', $pie);
        $abs = rtrim(SITE_URL, '/') . '/' . $url;
        $this->pdo->prepare("UPDATE arneses_sesiones SET dictamen_url = ? WHERE id = ?")->execute([$abs, $sesionId]);
        return ['status' => 'success', 'url' => $abs];
    }

    /** CERTIFICADO individual de una pieza (con su QR de validación). */
    public function generarCertificado(int $itemId): array {
        $s = $this->pdo->prepare("
            SELECT i.*, COALESCE(t.nombre,'') AS tipo_nombre,
                   DATE_FORMAT(i.fecha_fabricacion,'%d/%m/%Y') AS fabricacion_fmt,
                   DATE_FORMAT(i.fecha_retiro,'%d/%m/%Y')      AS retiro_fmt,
                   DATE_FORMAT(i.vigencia,'%d/%m/%Y')          AS vigencia_fmt,
                   se.cliente, se.control, se.estatus, se.direccion,
                   DATE_FORMAT(se.fecha,'%d/%m/%Y') AS fecha_fmt
            FROM arneses_items i
            LEFT JOIN arneses_tipos t ON t.id = i.tipo_id
            LEFT JOIN arneses_sesiones se ON se.id = i.sesion_id
            WHERE i.id = ?
        ");
        $s->execute([$itemId]);
        $it = $s->fetch(PDO::FETCH_ASSOC);
        if (!$it) return ['status' => 'error', 'message' => 'Pieza no encontrada.'];
        if (empty($it['control'])) return ['status' => 'error', 'message' => 'La inspección aún no tiene folio; debe aprobarse en Calidad.'];
        if ($it['resultado'] === 'NO APTO') {
            return ['status' => 'error', 'message' => 'No se emite certificado para equipo NO APTO; debe retirarse de servicio.'];
        }
        if (empty($it['qr_codigo'])) return ['status' => 'error', 'message' => 'La pieza no tiene código QR asignado.'];

        // Firmante e identificación del expediente
        $sIns = $this->pdo->prepare("SELECT inspector_firma, usuario FROM arneses_sesiones WHERE id = ?");
        $sIns->execute([(int)$it['sesion_id']]);
        $rIns = $sIns->fetch(PDO::FETCH_ASSOC) ?: [];
        $inspector = $this->esc($rIns['inspector_firma'] ?: ($rIns['usuario'] ?? ''));

        // Puntos de inspección verificados: es lo que sustenta el resultado.
        $ck = $this->pdo->prepare("
            SELECT c.tag, c.valor, COALESCE(d.descripcion, c.tag) AS descripcion
            FROM arneses_item_checklist c
            LEFT JOIN arneses_checklist d ON d.tipo_id = ? AND d.tag = c.tag
            WHERE c.item_id = ? ORDER BY d.orden, c.id
        ");
        $ck->execute([(int)$it['tipo_id'], $itemId]);
        $puntos = $ck->fetchAll(PDO::FETCH_ASSOC);

        $filasCk = '';
        foreach ($puntos as $n => $p) {
            $v  = $p['valor'] ?: 'NA';
            $cc = $v === 'C' ? '#1b7a3d' : ($v === 'NC' ? '#b91c1c' : '#7a8494');
            $tx = $v === 'C' ? 'CONFORME' : ($v === 'NC' ? 'NO CONFORME' : 'NO APLICA');
            $filasCk .= '<tr' . ($n % 2 ? ' class="alt"' : '') . '>
                <td width="6%" style="text-align:center">' . ($n + 1) . '</td>
                <td>' . $this->esc($p['descripcion']) . '</td>
                <td width="20%" style="text-align:center;color:' . $cc . ';font-weight:bold">' . $tx . '</td>
              </tr>';
        }
        $bloqueCk = $filasCk
            ? '<div class="slabel">PUNTOS DE INSPECCIÓN VERIFICADOS</div>
               <table class="grid" style="margin-top:4px">
                 <thead><tr><th width="6%">#</th><th>PUNTO DE INSPECCIÓN</th><th width="20%">RESULTADO</th></tr></thead>
                 <tbody>' . $filasCk . '</tbody>
               </table>'
            : '';

        // Evidencia fotográfica
        $fotos = $this->fotosDe($itemId);
        $bloqueFotos = '';
        if ($fotos) {
            $celdas = '';
            foreach (array_slice($fotos, 0, 3) as $f) {
                $celdas .= '<td width="33%" style="text-align:center;padding:4px">
                              <img src="' . $this->esc($f) . '" style="width:150px">
                            </td>';
            }
            $bloqueFotos = '<div class="slabel">EVIDENCIA FOTOGRÁFICA</div>
                            <table width="100%" style="margin-top:4px"><tr>' . $celdas . '</tr></table>';
        }

        // Normas aplicables (selección múltiple), en una sola celda del cuadro
        // de identificación para no gastar una sección entera del certificado.
        $normas = implode('<br>', array_map(fn($n) => $this->esc($n), $this->normasDe([$it])));

        [$rColor, $rFondo] = $this->colorRes((string)$it['resultado']);
        $leyenda = $it['resultado'] === 'APTO'
            ? 'El equipo cumple los criterios de aceptación y es apto para continuar en servicio.'
            : 'El equipo puede usarse una vez atendidas las observaciones señaladas, bajo responsabilidad del usuario.';

        $qrImg = '<img src="' . qrDataUri(textoQR($it['qr_codigo']), 320, 3) . '" style="width:96px">';
        $sello = $this->activo('assets/sellos/sello.png');
        $folio = $it['control'] . '-' . str_pad((string)$itemId, 4, '0', STR_PAD_LEFT);

        $html = '<html><head><meta charset="UTF-8">' . $this->estilosDoc() . '</head><body>'
          . $this->encabezado('CERTIFICADO DE INSPECCIÓN', $folio,
                              'Equipo de protección personal contra caídas')

          . '<div class="nota" style="text-align:center;padding:6px 18px 0">
               AVBA Inspections, como unidad de inspección, hace constar que el equipo descrito a
               continuación fue inspeccionado en sus componentes y funciones conforme a la
               normatividad aplicable, y que a la fecha de emisión presenta el resultado indicado.
             </div>

             <div class="slabel">DATOS DEL CLIENTE E IDENTIFICACIÓN DEL EQUIPO</div>
             <table class="dt">
               <tr><td class="lbl">CLIENTE / EMPRESA</td><td class="val" colspan="3">' . $this->esc($it['cliente']) . '</td></tr>
               <tr><td class="lbl">LUGAR DE INSPECCIÓN</td><td class="val" colspan="3">' . $this->esc($it['direccion'] ?: '—') . '</td></tr>
               <tr><td class="lbl">TIPO DE EQUIPO</td><td class="val">' . $this->esc($it['tipo_nombre'] ?: '—') . '</td>
                   <td class="lbl">MARCA</td><td class="val">' . $this->esc($it['marca'] ?: '—') . '</td></tr>
               <tr><td class="lbl">MODELO</td><td class="val">' . $this->esc($it['modelo'] ?: '—') . '</td>
                   <td class="lbl">No. DE SERIE</td><td class="val">' . $this->esc($it['serie'] ?: '—') . '</td></tr>
               <tr><td class="lbl">ID / ETIQUETA</td><td class="val">' . $this->esc($it['id_arnes'] ?: '—') . '</td>
                   <td class="lbl">TALLA</td><td class="val">' . $this->esc($it['talla'] ?: '—') . '</td></tr>
               <tr><td class="lbl">LONGITUD</td><td class="val">' . $this->esc($it['longitud'] ?: '—') . '</td>
                   <td class="lbl">FABRICACIÓN</td><td class="val">' . $this->esc($it['fabricacion_fmt'] ?: '—') . '</td></tr>
               <tr><td class="lbl">FECHA DE INSPECCIÓN</td><td class="val">' . $this->esc($it['fecha_fmt'] ?: '—') . '</td>
                   <td class="lbl">RETIRO OBLIGATORIO</td><td class="val">' . $this->esc($it['retiro_fmt'] ?: '—') . '</td></tr>
               <tr><td class="lbl">NORMAS APLICABLES</td>
                   <td class="val" colspan="3">' . $normas . '</td></tr>
             </table>

             <div class="slabel">RESULTADO DE LA INSPECCIÓN</div>
             <table width="100%" style="margin-top:5px;border-collapse:separate;border-spacing:6px 0">
               <tr>
                 <td width="46%" style="background:' . $rFondo . ';border:2px solid ' . $rColor . ';border-radius:5px;text-align:center;padding:5px">
                   <div style="font-size:13.5pt;font-weight:bold;color:' . $rColor . '">' . $this->esc($it['resultado']) . '</div>
                   <div style="font-size:7pt;color:#5a6072;letter-spacing:.8px">RESULTADO DE LA INSPECCIÓN</div>
                 </td>
                 <td width="30%" style="background:#E6F1FB;border:2px solid #185FA5;border-radius:5px;text-align:center;padding:5px">
                   <div style="font-size:11.5pt;font-weight:bold;color:#0C447C">' . $this->esc($it['vigencia_fmt'] ?: '—') . '</div>
                   <div style="font-size:7pt;color:#5a6072;letter-spacing:.8px">VIGENCIA DEL CERTIFICADO</div>
                 </td>
                 <td width="24%" style="text-align:center">' . $qrImg . '
                   <div style="font-size:6.5pt;color:#5a6072">Escanea para validar<br>gestion.avba.com.mx</div>
                 </td>
               </tr>
             </table>
             <div class="nota" style="padding:5px 2px">' . $leyenda . '</div>'

             . $bloqueCk
             . (!empty($it['observaciones'])
                ? '<div style="font-size:8pt;background:#fff8e1;border-left:3px solid #f59e0b;padding:8px;margin-top:9px">
                     <strong>Observaciones del inspector:</strong> ' . $this->esc($it['observaciones']) . '</div>'
                : '')
             . $bloqueFotos

             . '<div class="nota" style="padding:6px 2px 0">
               <strong style="color:#0C447C">Uso y conservación:</strong> el usuario debe inspeccionar
               visualmente el equipo antes de cada uso. Consérvese en lugar seco y ventilado, alejado de
               fuentes de calor, químicos, bordes filosos y radiación solar directa. Retírese de servicio
               de inmediato ante cualquier evento de caída, daño, alteración o reparación no autorizada,
               y al llegar la fecha de retiro obligatorio del fabricante.
             </div>

             <div class="juntos">
             <table width="100%" style="margin-top:6px">
               <tr>
                 <td width="50%" style="text-align:center">'
                   . ($sello ? '<img src="' . $sello . '" style="height:36px"><br>' : '<div style="height:36px"></div>')
                   . '' . $this->lineaFirma() . '
                      <div class="sign-t">' . ($inspector ?: 'Inspector responsable') . '</div>
                      <div class="sign-s">Inspector · AVBA Inspections</div></td>
                 <td width="50%" style="text-align:center;vertical-align:bottom">
                      <div style="height:36px"></div>
                      ' . $this->lineaFirma() . '
                      <div class="sign-t">Unidad de Inspección</div>
                      <div class="sign-s">Revisión y aprobación técnica</div></td>
               </tr>
             </table>'
             // Sin franja de acreditaciones: el membrete ya declara la unidad de
             // inspección y así el certificado cierra en una sola hoja.
             . '<div class="legal">
                  Este certificado ampara únicamente el equipo identificado y las condiciones
                  observadas al momento de la inspección. Su autenticidad puede verificarse
                  escaneando el código QR. Folio <strong>' . $this->esc(formatoFolio($folio)) . '</strong> · '
                  . date('Y') . ' AVBA Inspections, Certifications and Maintenance S.A.S. de C.V.
                </div></div>
             </body></html>';

        $pie = 'Certificado ' . $this->esc(formatoFolio($folio)) . ' · ' . $this->esc($it['cliente']);
        $url = $this->htmlToPdf($html, $folio, 'CERT_ARN', $pie);
        $abs = rtrim(SITE_URL, '/') . '/' . $url;
        $this->pdo->prepare("UPDATE arneses_items SET cert_url = ? WHERE id = ?")->execute([$abs, $itemId]);
        return ['status' => 'success', 'url' => $abs];
    }

    /**
     * Reemplaza manualmente el dictamen de la sesión o el certificado de una
     * pieza. Igual que en grúas, el archivo subido sustituye al generado tanto
     * en el portal del cliente como en el correo de envío.
     * $p['tipo']: 'dict' (usa $p['id'] = sesión) | 'cert' (usa $p['item_id']).
     */
    public function subirDocumentoManual(array $p, array $file): array {
        $tipo = ($p['tipo'] ?? '') === 'cert' ? 'cert' : 'dict';

        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return ['status' => 'error', 'message' => 'Selecciona un archivo PDF válido.'];
        }
        if (strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION)) !== 'pdf') {
            return ['status' => 'error', 'message' => 'El documento debe ser un PDF.'];
        }
        if (($file['size'] ?? 0) > 20 * 1024 * 1024) {
            return ['status' => 'error', 'message' => 'El PDF no debe superar 20 MB.'];
        }

        if ($tipo === 'cert') {
            $itemId = (int)($p['item_id'] ?? 0);
            if (!$itemId) return ['status' => 'error', 'message' => 'Pieza no indicada.'];
            $s = $this->pdo->prepare(
                "SELECT i.id, i.serie, se.control FROM arneses_items i
                 JOIN arneses_sesiones se ON se.id = i.sesion_id WHERE i.id = ?"
            );
            $s->execute([$itemId]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            if (!$row) return ['status' => 'error', 'message' => 'Pieza no encontrada.'];
            $folio = ($row['control'] ?: 'SF') . '-' . str_pad((string)$itemId, 4, '0', STR_PAD_LEFT);
            $sufijo = 'CERT_ARN';
        } else {
            $sesionId = (int)($p['id'] ?? 0);
            if (!$sesionId) return ['status' => 'error', 'message' => 'Sesión no indicada.'];
            $s = $this->pdo->prepare("SELECT control FROM arneses_sesiones WHERE id = ?");
            $s->execute([$sesionId]);
            $control = $s->fetchColumn();
            if ($control === false) return ['status' => 'error', 'message' => 'Sesión no encontrada.'];
            $folio  = $control ?: ('S' . $sesionId);
            $sufijo = 'DICT_ARN';
        }

        $dir = UPLOAD_DIR . 'reportes/';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $nombre  = $sufijo . '_MANUAL_' . preg_replace('/[^A-Za-z0-9._-]/', '', (string)$folio) . '.pdf';
        $destino = $dir . $nombre;
        if (!move_uploaded_file($file['tmp_name'], $destino)) {
            return ['status' => 'error', 'message' => 'No se pudo guardar el PDF.'];
        }

        $abs = rtrim(SITE_URL, '/') . '/uploads/reportes/' . $nombre;
        if ($tipo === 'cert') {
            $this->pdo->prepare("UPDATE arneses_items SET cert_manual_url = ? WHERE id = ?")
                ->execute([$abs, $itemId]);
            $msg = 'Certificado reemplazado correctamente.';
        } else {
            $this->pdo->prepare("UPDATE arneses_sesiones SET dictamen_manual_url = ? WHERE id = ?")
                ->execute([$abs, $sesionId]);
            $msg = 'Dictamen reemplazado correctamente.';
        }

        return ['status' => 'success', 'message' => $msg, 'url' => $abs];
    }

    /** Ruta local de un PDF a partir de su URL absoluta, o '' si no existe. */
    private function rutaLocal(?string $url): string {
        $url = trim((string)$url);
        if ($url === '') return '';
        $rel = ltrim(str_replace(rtrim(SITE_URL, '/'), '', $url), '/');
        $abs = __DIR__ . '/../' . $rel;
        return is_file($abs) ? $abs : '';
    }

    /**
     * Emite todo: dictamen del lote + certificado de cada pieza apta, y publica
     * el expediente en el portal del cliente (estatus EMITIDO). Si
     * $p['enviar'] viene activo, además manda el correo con los adjuntos,
     * igual que "Generar y enviar" en grúas.
     *
     * Los documentos sustituidos manualmente NO se regeneran: se respetan tal
     * cual, tanto para el portal como para el correo.
     */
    public function emitirSesion(array $p, string $usuario): array {
        $sesionId = (int)($p['id'] ?? 0);
        if (!$sesionId) return ['status' => 'error', 'message' => 'Sesión no indicada.'];

        $det = $this->detalleSesion($sesionId);
        if ($det['status'] !== 'success') return $det;
        $ses = $det['sesion'];

        $enviar = !empty($p['enviar']);
        $correo = trim((string)($ses['correo'] ?? ''));
        if ($enviar && $correo === '') {
            return ['status' => 'error', 'message' => 'La inspección no tiene correo registrado. Agrégalo en los datos de la sesión.'];
        }

        // ── Dictamen del lote (manual si lo hay) ──────────────
        $dictUrl = trim((string)($ses['dictamen_manual_url'] ?? ''));
        if ($dictUrl === '') {
            $dict = $this->generarDictamen($sesionId);
            if ($dict['status'] !== 'success') return $dict;
            $dictUrl = $dict['url'];
        } else {
            $this->pdo->prepare("UPDATE arneses_sesiones SET dictamen_url = ? WHERE id = ?")
                ->execute([$dictUrl, $sesionId]);
        }

        // ── Certificado por pieza (manual si lo hay) ──────────
        $ok = 0; $errores = []; $adjuntos = [];
        foreach ($det['items'] as $i) {
            if ($i['resultado'] === 'NO APTO') continue;
            $manual = trim((string)($i['cert_manual_url'] ?? ''));
            if ($manual !== '') {
                $this->pdo->prepare("UPDATE arneses_items SET cert_url = ? WHERE id = ?")
                    ->execute([$manual, (int)$i['id']]);
                $ok++;
                $certUrl = $manual;
            } else {
                $r = $this->generarCertificado((int)$i['id']);
                if ($r['status'] !== 'success') {
                    $errores[] = ($i['serie'] ?: ('#' . $i['id'])) . ': ' . $r['message'];
                    continue;
                }
                $ok++;
                $certUrl = $r['url'];
            }
            if ($enviar) {
                $ruta = $this->rutaLocal($certUrl);
                if ($ruta !== '') $adjuntos[$ruta] = basename($ruta);
            }
        }

        $this->pdo->prepare("UPDATE arneses_sesiones SET estatus='EMITIDO' WHERE id=?")->execute([$sesionId]);
        $this->historial($usuario, $sesionId, $ses['estatus'] ?? null, 'EMITIDO');

        $mensaje = "Dictamen emitido y $ok certificado(s) generado(s). Ya están disponibles en el portal del cliente.";

        if ($enviar) {
            $rutaDict = $this->rutaLocal($dictUrl);
            if ($rutaDict !== '') $adjuntos = [$rutaDict => basename($rutaDict)] + $adjuntos;
            try {
                $this->enviarCorreo($correo, (string)$ses['cliente'], (string)($ses['control'] ?: $sesionId), $adjuntos);
                $this->pdo->prepare("UPDATE arneses_sesiones SET fecha_enviado = NOW() WHERE id = ?")
                    ->execute([$sesionId]);
                $this->registrarEnvio($ses, implode(', ', $adjuntos), $usuario);
                $mensaje = "Documentos enviados a $correo y publicados en el portal del cliente.";
            } catch (\Throwable $e) {
                // Los documentos ya quedaron publicados; sólo falló el correo.
                $errores[] = 'No se pudo enviar el correo: ' . $e->getMessage();
                $mensaje  .= ' El envío por correo falló.';
            }
        }

        return [
            'status'   => 'success',
            'message'  => $mensaje,
            'dictamen' => $dictUrl,
            'errores'  => $errores,
        ];
    }

    /** Correo al cliente con el dictamen y los certificados adjuntos. */
    private function enviarCorreo(string $to, string $cliente, string $folio, array $adjuntos): void {
        if (!class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
            $autoload = __DIR__ . '/../vendor/autoload.php';
            if (file_exists($autoload)) require_once $autoload;
        }
        if (!class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
            throw new \RuntimeException('PHPMailer no disponible.');
        }

        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        configurarMailer($mail, $this->pdo);

        $hay = false;
        foreach (array_filter(array_map('trim', explode(',', $to))) as $addr) {
            if (filter_var($addr, FILTER_VALIDATE_EMAIL)) { $mail->addAddress($addr); $hay = true; }
        }
        if (!$hay) throw new \RuntimeException('No hay destinatarios válidos.');

        $folioFmt = function_exists('formatoFolio') ? formatoFolio($folio) : $folio;
        $mail->Subject = "Inspección de equipo contra caídas AVBA — Folio {$folioFmt}";
        $mail->isHTML(true);

        $n = max(0, count($adjuntos) - 1);
        $cuerpo = '
      <p style="font-size:15px;color:#1a1a2e;margin:0 0 12px">Estimado(a) cliente <strong>'
        . htmlspecialchars($cliente) . '</strong>,</p>
      <p style="font-size:14px;color:#5a6072;line-height:1.7;margin:0 0 20px">
        Adjuntamos el <strong>dictamen técnico de inspección</strong> de su equipo de
        protección contra caídas, junto con ' . $n . ' certificado(s) individual(es),
        emitidos conforme a la NOM-009-STPS-2011.
      </p>
      <div style="background:#E6F1FB;border-radius:8px;padding:14px 18px;margin-bottom:20px">
        <p style="font-size:13px;color:#0C447C;margin:0"><strong>Folio:</strong> ' . htmlspecialchars($folioFmt) . '</p>
        <p style="font-size:12px;color:#1B2A6B;margin:6px 0 0">
          Cada pieza conserva su vigencia hasta su fecha de retiro, siempre que no
          sufra un evento de caída, alteración o daño.
        </p>
      </div>';
        $mail->Body = function_exists('plantillaCorreoHtml')
            ? plantillaCorreoHtml($this->pdo, $cuerpo)
            : $cuerpo;

        foreach ($adjuntos as $ruta => $nombre) $mail->addAttachment($ruta, $nombre);
        $mail->send();
    }

    /** Deja constancia del envío en historico_envios, como en grúas. */
    private function registrarEnvio(array $ses, string $archivos, string $usuario): void {
        try {
            $this->pdo->prepare(
                "INSERT INTO historico_envios (cliente, control, correo, archivo, usuario, equipo_id)
                 VALUES (?, ?, ?, ?, ?, NULL)"
            )->execute([
                $ses['cliente'] ?? null,
                $ses['control'] ?? null,
                $ses['correo']  ?? null,
                $archivos,
                $usuario,
            ]);
        } catch (\Throwable $e) {
            error_log('[Arneses] registrarEnvio: ' . $e->getMessage());
        }
    }

    public function eliminarSesion(int $id): array {
        if (!$id) return ['status' => 'error', 'message' => 'Sesión no indicada.'];

        // Los QR vuelven al banco para poder reasignarlos (igual que en Calidad
        // al eliminar un equipo); si no, se perderían al borrar la sesión.
        $libera = function (?string $qr): void {
            $qr = trim((string)$qr);
            if ($qr === '') return;
            try {
                $this->pdo->prepare("UPDATE qr_codigos SET usado = 0, equipo_id = NULL WHERE identificador = ?")
                    ->execute([$qr]);
            } catch (\Throwable $e) { error_log('[Arneses] liberar QR: ' . $e->getMessage()); }
        };

        $s = $this->pdo->prepare("SELECT qr_codigo FROM arneses_sesiones WHERE id = ?");
        $s->execute([$id]);
        $libera($s->fetchColumn() ?: null);

        $items = $this->pdo->prepare("SELECT id, qr_codigo FROM arneses_items WHERE sesion_id = ?");
        $items->execute([$id]);
        foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $it) {
            $libera($it['qr_codigo']);
            $this->pdo->prepare("DELETE FROM arneses_item_checklist WHERE item_id = ?")->execute([$it['id']]);
            $this->pdo->prepare("DELETE FROM arneses_fotos WHERE item_id = ?")->execute([$it['id']]);
        }
        $this->pdo->prepare("DELETE FROM arneses_items WHERE sesion_id = ?")->execute([$id]);
        $this->pdo->prepare("DELETE FROM arneses_sesiones WHERE id = ?")->execute([$id]);
        return ['status' => 'success', 'message' => 'Sesión eliminada.'];
    }
}
