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
 * Estados de la sesión: PENDIENTE → APROBADO_CALIDAD → EMITIDO, con DEVUELTO
 * como rama lateral (vuelve a ser aprobable).
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
                  norma         VARCHAR(120) NULL,
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
            INSERT INTO arneses_sesiones (cliente, fecha, coordenadas, direccion, usuario, estatus)
            VALUES (?,?,?,?,?, 'PENDIENTE')
        ")->execute([
            mb_strtoupper($cliente), date('Y-m-d', strtotime(str_replace('/', '-', $fecha))),
            trim((string)($p['coordenadas'] ?? '')) ?: null,
            trim((string)($p['direccion'] ?? '')) ?: null,
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
        if (!in_array($ses['estatus'], ['PENDIENTE', 'DEVUELTO', ''], true)) {
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

        return ['status' => 'success', 'message' => 'Sesión aprobada y enviada a Certificaciones.', 'control' => $control];
    }

    public function devolverSesion(array $p, string $usuario): array {
        $id     = (int)($p['id'] ?? 0);
        $motivo = trim((string)($p['motivo'] ?? ''));
        if (!$id) return ['status' => 'error', 'message' => 'Sesión no indicada.'];
        $this->pdo->prepare("UPDATE arneses_sesiones SET estatus='DEVUELTO', motivo=? WHERE id=?")
            ->execute([$motivo ?: null, $id]);
        return ['status' => 'success', 'message' => 'Sesión devuelta para corrección.'];
    }

    public function eliminarSesion(int $id): array {
        if (!$id) return ['status' => 'error', 'message' => 'Sesión no indicada.'];
        $items = $this->pdo->prepare("SELECT id FROM arneses_items WHERE sesion_id = ?");
        $items->execute([$id]);
        foreach ($items->fetchAll(PDO::FETCH_COLUMN) as $itemId) {
            $this->pdo->prepare("DELETE FROM arneses_item_checklist WHERE item_id = ?")->execute([$itemId]);
            $this->pdo->prepare("DELETE FROM arneses_fotos WHERE item_id = ?")->execute([$itemId]);
        }
        $this->pdo->prepare("DELETE FROM arneses_items WHERE sesion_id = ?")->execute([$id]);
        $this->pdo->prepare("DELETE FROM arneses_sesiones WHERE id = ?")->execute([$id]);
        return ['status' => 'success', 'message' => 'Sesión eliminada.'];
    }
}
