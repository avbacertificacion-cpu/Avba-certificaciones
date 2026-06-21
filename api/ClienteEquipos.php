<?php
/**
 * AVBA Certificaciones — Gestión de Equipos del Cliente
 * Permite al cliente registrar sus propios equipos y llevar control de:
 *   - Documentos (bitácora, seguro, tablas de carga, etc.)
 *   - Horómetro (registro de horas trabajadas)
 *   - Certificaciones vigentes
 */
class ClienteEquipos {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->migrate();
    }

    // ── Migración automática ─────────────────────────────────
    private function migrate(): void {
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS cliente_equipos (
                  id         INT AUTO_INCREMENT PRIMARY KEY,
                  id_cliente VARCHAR(20)  NOT NULL,
                  nombre     VARCHAR(200) NOT NULL,
                  tipo       VARCHAR(100) NULL,
                  marca      VARCHAR(100) NULL,
                  modelo     VARCHAR(100) NULL,
                  serie      VARCHAR(100) NULL,
                  capacidad  VARCHAR(100) NULL,
                  anio       SMALLINT     NULL,
                  notas      TEXT         NULL,
                  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  KEY idx_cli (id_cliente)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS cliente_equipos_docs (
                  id          INT AUTO_INCREMENT PRIMARY KEY,
                  equipo_id   INT          NOT NULL,
                  tipo_doc    VARCHAR(50)  NOT NULL DEFAULT 'otro',
                  nombre      VARCHAR(200) NOT NULL,
                  archivo_url VARCHAR(500) NULL,
                  vigencia    DATE         NULL,
                  notas       TEXT         NULL,
                  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  KEY idx_eq (equipo_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS cliente_equipos_horometro (
                  id        INT AUTO_INCREMENT PRIMARY KEY,
                  equipo_id INT           NOT NULL,
                  horas     DECIMAL(10,1) NOT NULL,
                  fecha     DATE          NOT NULL,
                  notas     TEXT          NULL,
                  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  KEY idx_eq (equipo_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS cliente_equipos_cert (
                  id             INT AUTO_INCREMENT PRIMARY KEY,
                  equipo_id      INT          NOT NULL,
                  tipo_cert      VARCHAR(100) NOT NULL,
                  folio          VARCHAR(100) NULL,
                  fecha_emision  DATE         NULL,
                  fecha_vigencia DATE         NULL,
                  archivo_url    VARCHAR(500) NULL,
                  notas          TEXT         NULL,
                  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  KEY idx_eq (equipo_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            try { $this->pdo->exec("ALTER TABLE cliente_equipos ADD COLUMN IF NOT EXISTS estado VARCHAR(50) NULL DEFAULT 'Activo'"); } catch (\PDOException $e) {}
            try { $this->pdo->exec("ALTER TABLE cliente_equipos ADD COLUMN IF NOT EXISTS foto_url VARCHAR(500) NULL"); } catch (\PDOException $e) {}
        } catch (\PDOException $e) {
            error_log('[ClienteEquipos] migrate: ' . $e->getMessage());
        }
    }

    private function norm(string $id): string {
        $id = trim($id);
        return (ctype_digit($id)) ? str_pad($id, 5, '0', STR_PAD_LEFT) : $id;
    }

    private function ownEquipo(int $id, string $idCliente): bool {
        $s = $this->pdo->prepare("SELECT id FROM cliente_equipos WHERE id=? AND id_cliente=?");
        $s->execute([$id, $idCliente]);
        return (bool)$s->fetch();
    }

    // ════════════════════════════════════════════════════════
    //  EQUIPOS
    // ════════════════════════════════════════════════════════

    public function listar(string $idCliente): array {
        $idCliente = $this->norm($idCliente);
        if (!$idCliente) return ['status' => 'error', 'message' => 'id_cliente requerido.'];

        $stmt = $this->pdo->prepare("
            SELECT e.id, e.nombre, e.tipo, e.marca, e.modelo, e.serie,
                   e.capacidad, e.anio, e.notas, e.estado, e.foto_url,
                   DATE_FORMAT(e.created_at,'%d/%m/%Y') AS fecha_registro,
                   COUNT(DISTINCT d.id)  AS total_docs,
                   COALESCE(hm.horas, 0) AS horas_actuales,
                   COUNT(DISTINCT c.id)  AS total_certs,
                   COALESCE(SUM(c.fecha_vigencia >= CURDATE()), 0)                                                                          AS certs_vigentes,
                   COALESCE(SUM(c.fecha_vigencia < CURDATE() AND c.fecha_vigencia IS NOT NULL), 0)                                          AS certs_vencidas,
                   COALESCE(SUM(c.fecha_vigencia >= CURDATE() AND c.fecha_vigencia <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)), 0)              AS certs_por_vencer
            FROM cliente_equipos e
            LEFT JOIN cliente_equipos_docs d ON d.equipo_id = e.id
            LEFT JOIN (
                SELECT equipo_id, MAX(horas) AS horas
                FROM cliente_equipos_horometro GROUP BY equipo_id
            ) hm ON hm.equipo_id = e.id
            LEFT JOIN cliente_equipos_cert c ON c.equipo_id = e.id
            WHERE e.id_cliente = ?
            GROUP BY e.id, e.nombre, e.tipo, e.marca, e.modelo, e.serie,
                     e.capacidad, e.anio, e.notas, e.estado, e.created_at, hm.horas
            ORDER BY e.nombre
        ");
        $stmt->execute([$idCliente]);

        return ['status' => 'success', 'equipos' => array_map(fn($r) => [
            'id'             => (int)$r['id'],
            'nombre'         => $r['nombre'],
            'tipo'           => $r['tipo'] ?? '',
            'marca'          => $r['marca'] ?? '',
            'modelo'         => $r['modelo'] ?? '',
            'serie'          => $r['serie'] ?? '',
            'capacidad'      => $r['capacidad'] ?? '',
            'anio'           => $r['anio'] ? (int)$r['anio'] : null,
            'notas'          => $r['notas'] ?? '',
            'estado'         => $r['estado'] ?? 'Activo',
            'foto_url'       => $r['foto_url'] ? (rtrim(SITE_URL, '/') . '/' . ltrim($r['foto_url'], '/')) : '',
            'fecha_registro' => $r['fecha_registro'],
            'total_docs'     => (int)$r['total_docs'],
            'horas_actuales' => (float)$r['horas_actuales'],
            'total_certs'    => (int)$r['total_certs'],
            'certs_vigentes'   => (int)$r['certs_vigentes'],
            'certs_vencidas'   => (int)$r['certs_vencidas'],
            'certs_por_vencer' => (int)$r['certs_por_vencer'],
        ], $stmt->fetchAll())];
    }

    public function guardar(array $data, array $files, string $idCliente): array {
        $idCliente = $this->norm($idCliente);
        $nombre    = trim($data['nombre']    ?? '');
        if (!$nombre) return ['status' => 'error', 'message' => 'El nombre del equipo es requerido.'];

        $tipo      = trim($data['tipo']      ?? '');
        $marca     = trim($data['marca']     ?? '');
        $modelo    = trim($data['modelo']    ?? '');
        $serie     = trim($data['serie']     ?? '');
        $capacidad = trim($data['capacidad'] ?? '');
        $anio      = (isset($data['anio']) && ctype_digit((string)$data['anio'])) ? (int)$data['anio'] : null;
        $notas     = trim($data['notas']     ?? '');
        $estado    = trim($data['estado']    ?? 'Activo');
        $id        = (int)($data['id']       ?? 0);

        // Manejo de foto
        $fotoUrl = null;
        $fotoNueva = false;
        if (!empty($files['foto']['tmp_name'])) {
            $ext = strtolower(pathinfo($files['foto']['name'] ?? '', PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                return ['status' => 'error', 'message' => "Solo se permiten imágenes JPG, PNG o WebP."];
            }
            if ($files['foto']['size'] > 5 * 1024 * 1024) {
                return ['status' => 'error', 'message' => 'La foto no debe superar 5 MB.'];
            }
            $fotoNueva = true;
        }

        if ($id) {
            if (!$this->ownEquipo($id, $idCliente)) return ['status' => 'error', 'message' => 'Equipo no encontrado.'];

            // Obtener foto actual para posible reemplazo/eliminación
            $sq = $this->pdo->prepare("SELECT foto_url FROM cliente_equipos WHERE id=?");
            $sq->execute([$id]);
            $fotoActual = $sq->fetchColumn() ?: '';

            if ($fotoNueva) {
                // Borrar foto anterior
                if ($fotoActual) {
                    $f = rtrim(UPLOAD_DIR, '/') . '/' . ltrim($fotoActual, '/');
                    if (file_exists($f)) @unlink($f);
                }
                $dir = rtrim(UPLOAD_DIR, '/') . "/equipos_cliente/fotos/$id/";
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $ext = strtolower(pathinfo($files['foto']['name'], PATHINFO_EXTENSION));
                $fn  = 'foto_' . date('YmdHis') . ".$ext";
                move_uploaded_file($files['foto']['tmp_name'], $dir . $fn);
                $fotoUrl = "uploads/equipos_cliente/fotos/$id/$fn";
                $this->pdo->prepare(
                    "UPDATE cliente_equipos SET nombre=?,tipo=?,marca=?,modelo=?,serie=?,capacidad=?,anio=?,notas=?,estado=?,foto_url=? WHERE id=?"
                )->execute([$nombre, $tipo, $marca, $modelo, $serie, $capacidad, $anio, $notas, $estado, $fotoUrl, $id]);
            } elseif (isset($data['foto_actual']) && $data['foto_actual'] === '') {
                // Usuario quitó la foto
                if ($fotoActual) {
                    $f = rtrim(UPLOAD_DIR, '/') . '/' . ltrim($fotoActual, '/');
                    if (file_exists($f)) @unlink($f);
                }
                $this->pdo->prepare(
                    "UPDATE cliente_equipos SET nombre=?,tipo=?,marca=?,modelo=?,serie=?,capacidad=?,anio=?,notas=?,estado=?,foto_url=NULL WHERE id=?"
                )->execute([$nombre, $tipo, $marca, $modelo, $serie, $capacidad, $anio, $notas, $estado, $id]);
            } else {
                $this->pdo->prepare(
                    "UPDATE cliente_equipos SET nombre=?,tipo=?,marca=?,modelo=?,serie=?,capacidad=?,anio=?,notas=?,estado=? WHERE id=?"
                )->execute([$nombre, $tipo, $marca, $modelo, $serie, $capacidad, $anio, $notas, $estado, $id]);
            }
        } else {
            $this->pdo->prepare(
                "INSERT INTO cliente_equipos (id_cliente,nombre,tipo,marca,modelo,serie,capacidad,anio,notas,estado)
                 VALUES (?,?,?,?,?,?,?,?,?,?)"
            )->execute([$idCliente, $nombre, $tipo, $marca, $modelo, $serie, $capacidad, $anio, $notas, $estado]);
            $id = (int)$this->pdo->lastInsertId();

            if ($fotoNueva) {
                $dir = rtrim(UPLOAD_DIR, '/') . "/equipos_cliente/fotos/$id/";
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $ext = strtolower(pathinfo($files['foto']['name'], PATHINFO_EXTENSION));
                $fn  = 'foto_' . date('YmdHis') . ".$ext";
                move_uploaded_file($files['foto']['tmp_name'], $dir . $fn);
                $fotoUrl = "uploads/equipos_cliente/fotos/$id/$fn";
                $this->pdo->prepare("UPDATE cliente_equipos SET foto_url=? WHERE id=?")->execute([$fotoUrl, $id]);
            }
        }
        return ['status' => 'success', 'id' => $id];
    }

    public function eliminar(int $id, string $idCliente): array {
        $idCliente = $this->norm($idCliente);
        if (!$this->ownEquipo($id, $idCliente)) return ['status' => 'error', 'message' => 'Equipo no encontrado.'];

        // Borrar foto del equipo
        $sf = $this->pdo->prepare("SELECT foto_url FROM cliente_equipos WHERE id=?");
        $sf->execute([$id]);
        $fotoUrl = $sf->fetchColumn();
        if ($fotoUrl) {
            $f = rtrim(UPLOAD_DIR, '/') . '/' . ltrim($fotoUrl, '/');
            if (file_exists($f)) @unlink($f);
        }

        foreach (['cliente_equipos_docs', 'cliente_equipos_cert'] as $tabla) {
            $s = $this->pdo->prepare("SELECT archivo_url FROM $tabla WHERE equipo_id=?");
            $s->execute([$id]);
            foreach ($s->fetchAll() as $row) {
                if (!empty($row['archivo_url'])) {
                    $f = rtrim(UPLOAD_DIR, '/') . '/' . ltrim($row['archivo_url'], '/');
                    if (file_exists($f)) @unlink($f);
                }
            }
        }
        foreach (['cliente_equipos_docs', 'cliente_equipos_horometro', 'cliente_equipos_cert'] as $t) {
            $this->pdo->prepare("DELETE FROM $t WHERE equipo_id=?")->execute([$id]);
        }
        $this->pdo->prepare("DELETE FROM cliente_equipos WHERE id=?")->execute([$id]);
        return ['status' => 'success'];
    }

    // ════════════════════════════════════════════════════════
    //  DETALLE COMPLETO
    // ════════════════════════════════════════════════════════

    public function detalle(int $id, string $idCliente): array {
        $idCliente = $this->norm($idCliente);
        $s = $this->pdo->prepare("SELECT * FROM cliente_equipos WHERE id=? AND id_cliente=?");
        $s->execute([$id, $idCliente]);
        $eq = $s->fetch();
        if (!$eq) return ['status' => 'error', 'message' => 'Equipo no encontrado.'];

        $docs = $this->pdo->prepare("
            SELECT id, tipo_doc, nombre, archivo_url,
                   DATE_FORMAT(vigencia,'%d/%m/%Y')    AS vigencia,
                   DATEDIFF(vigencia, CURDATE())        AS dias_vig,
                   (vigencia IS NOT NULL AND vigencia < CURDATE()) AS vencido,
                   notas,
                   DATE_FORMAT(created_at,'%d/%m/%Y')  AS fecha_subida
            FROM cliente_equipos_docs WHERE equipo_id=? ORDER BY tipo_doc, nombre
        ");
        $docs->execute([$id]);

        $hor = $this->pdo->prepare("
            SELECT id, horas, DATE_FORMAT(fecha,'%d/%m/%Y') AS fecha, notas
            FROM cliente_equipos_horometro WHERE equipo_id=? ORDER BY fecha DESC, id DESC
        ");
        $hor->execute([$id]);

        $certs = $this->pdo->prepare("
            SELECT id, tipo_cert, folio,
                   DATE_FORMAT(fecha_emision ,'%d/%m/%Y') AS fecha_emision,
                   DATE_FORMAT(fecha_vigencia,'%d/%m/%Y') AS fecha_vigencia,
                   DATEDIFF(fecha_vigencia, CURDATE())     AS dias_vig,
                   (fecha_vigencia IS NOT NULL AND fecha_vigencia < CURDATE()) AS vencida,
                   archivo_url, notas
            FROM cliente_equipos_cert WHERE equipo_id=? ORDER BY fecha_vigencia DESC
        ");
        $certs->execute([$id]);

        return [
            'status'          => 'success',
            'equipo'          => [
                'id'       => (int)$eq['id'],
                'nombre'   => $eq['nombre'],
                'tipo'     => $eq['tipo'] ?? '',
                'marca'    => $eq['marca'] ?? '',
                'modelo'   => $eq['modelo'] ?? '',
                'serie'    => $eq['serie'] ?? '',
                'capacidad'=> $eq['capacidad'] ?? '',
                'anio'     => $eq['anio'] ? (int)$eq['anio'] : null,
                'notas'    => $eq['notas'] ?? '',
                'estado'   => $eq['estado'] ?? 'Activo',
                'foto_url' => $eq['foto_url'] ? (rtrim(SITE_URL, '/') . '/' . ltrim($eq['foto_url'], '/')) : '',
            ],
            'documentos'      => array_map(fn($r) => [
                'id'          => (int)$r['id'],
                'tipo_doc'    => $r['tipo_doc'],
                'nombre'      => $r['nombre'],
                'archivo_url' => $r['archivo_url'] ? (rtrim(SITE_URL, '/') . '/' . ltrim($r['archivo_url'], '/')) : '',
                'vigencia'    => $r['vigencia'] ?? '',
                'dias_vig'    => $r['dias_vig'] !== null ? (int)$r['dias_vig'] : null,
                'vencido'     => (bool)$r['vencido'],
                'notas'       => $r['notas'] ?? '',
                'fecha_subida'=> $r['fecha_subida'],
            ], $docs->fetchAll()),
            'horometro'       => array_map(fn($r) => [
                'id'    => (int)$r['id'],
                'horas' => (float)$r['horas'],
                'fecha' => $r['fecha'],
                'notas' => $r['notas'] ?? '',
            ], $hor->fetchAll()),
            'certificaciones' => array_map(fn($r) => [
                'id'             => (int)$r['id'],
                'tipo_cert'      => $r['tipo_cert'],
                'folio'          => $r['folio'] ?? '',
                'fecha_emision'  => $r['fecha_emision'] ?? '',
                'fecha_vigencia' => $r['fecha_vigencia'] ?? '',
                'dias_vig'       => $r['dias_vig'] !== null ? (int)$r['dias_vig'] : null,
                'vencida'        => (bool)$r['vencida'],
                'archivo_url'    => $r['archivo_url'] ? (rtrim(SITE_URL, '/') . '/' . ltrim($r['archivo_url'], '/')) : '',
                'notas'          => $r['notas'] ?? '',
            ], $certs->fetchAll()),
        ];
    }

    // ════════════════════════════════════════════════════════
    //  DOCUMENTOS
    // ════════════════════════════════════════════════════════

    public function subirDoc(array $post, array $files, string $idCliente): array {
        $idCliente = $this->norm($idCliente);
        $equipoId  = (int)($post['equipo_id'] ?? 0);
        $nombre    = trim($post['nombre']     ?? '');
        $tipoDoc   = trim($post['tipo_doc']   ?? 'otro');
        $vigencia  = trim($post['vigencia']   ?? '');
        $notas     = trim($post['notas']      ?? '');

        if (!$equipoId || !$nombre) return ['status' => 'error', 'message' => 'equipo_id y nombre son requeridos.'];
        if (!$this->ownEquipo($equipoId, $idCliente)) return ['status' => 'error', 'message' => 'Equipo no encontrado.'];

        $archivoUrl = null;
        if (!empty($files['archivo']['tmp_name'])) {
            $ext = strtolower(pathinfo($files['archivo']['name'] ?? '', PATHINFO_EXTENSION));
            if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'docx', 'xlsx'])) {
                return ['status' => 'error', 'message' => "Tipo de archivo no permitido (.$ext)."];
            }
            $dir = rtrim(UPLOAD_DIR, '/') . "/equipos_cliente/docs/$equipoId/";
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $fn = date('YmdHis') . '_' . preg_replace('/[^a-z0-9_\-]/i', '_', pathinfo($files['archivo']['name'], PATHINFO_FILENAME)) . ".$ext";
            if (!move_uploaded_file($files['archivo']['tmp_name'], $dir . $fn)) {
                return ['status' => 'error', 'message' => 'Error al guardar el archivo.'];
            }
            $archivoUrl = "uploads/equipos_cliente/docs/$equipoId/$fn";
        }

        $vigDb = $vigencia ? date('Y-m-d', strtotime(str_replace('/', '-', $vigencia))) : null;
        $this->pdo->prepare(
            "INSERT INTO cliente_equipos_docs (equipo_id,tipo_doc,nombre,archivo_url,vigencia,notas) VALUES (?,?,?,?,?,?)"
        )->execute([$equipoId, $tipoDoc, $nombre, $archivoUrl, $vigDb, $notas]);

        return ['status' => 'success'];
    }

    public function eliminarDoc(int $id, string $idCliente): array {
        $idCliente = $this->norm($idCliente);
        $s = $this->pdo->prepare("
            SELECT d.id, d.archivo_url FROM cliente_equipos_docs d
            JOIN cliente_equipos e ON e.id = d.equipo_id
            WHERE d.id=? AND e.id_cliente=?
        ");
        $s->execute([$id, $idCliente]);
        $doc = $s->fetch();
        if (!$doc) return ['status' => 'error', 'message' => 'Documento no encontrado.'];

        if ($doc['archivo_url']) {
            $f = rtrim(UPLOAD_DIR, '/') . '/' . ltrim($doc['archivo_url'], '/');
            if (file_exists($f)) @unlink($f);
        }
        $this->pdo->prepare("DELETE FROM cliente_equipos_docs WHERE id=?")->execute([$id]);
        return ['status' => 'success'];
    }

    // ════════════════════════════════════════════════════════
    //  HORÓMETRO
    // ════════════════════════════════════════════════════════

    public function registrarHora(array $data, string $idCliente): array {
        $idCliente = $this->norm($idCliente);
        $equipoId  = (int)($data['equipo_id'] ?? 0);
        $horas     = (float)($data['horas']   ?? 0);
        $fecha     = trim($data['fecha']       ?? '');
        $notas     = trim($data['notas']       ?? '');

        if (!$equipoId || $horas <= 0 || !$fecha) {
            return ['status' => 'error', 'message' => 'equipo_id, horas y fecha son requeridos.'];
        }
        if (!$this->ownEquipo($equipoId, $idCliente)) return ['status' => 'error', 'message' => 'Equipo no encontrado.'];

        $fechaDb = date('Y-m-d', strtotime(str_replace('/', '-', $fecha)));
        $this->pdo->prepare(
            "INSERT INTO cliente_equipos_horometro (equipo_id,horas,fecha,notas) VALUES (?,?,?,?)"
        )->execute([$equipoId, $horas, $fechaDb, $notas]);
        return ['status' => 'success'];
    }

    public function eliminarHora(int $id, string $idCliente): array {
        $idCliente = $this->norm($idCliente);
        $s = $this->pdo->prepare("
            SELECT h.id FROM cliente_equipos_horometro h
            JOIN cliente_equipos e ON e.id = h.equipo_id
            WHERE h.id=? AND e.id_cliente=?
        ");
        $s->execute([$id, $idCliente]);
        if (!$s->fetch()) return ['status' => 'error', 'message' => 'Registro no encontrado.'];
        $this->pdo->prepare("DELETE FROM cliente_equipos_horometro WHERE id=?")->execute([$id]);
        return ['status' => 'success'];
    }

    // ════════════════════════════════════════════════════════
    //  CERTIFICACIONES
    // ════════════════════════════════════════════════════════

    public function guardarCert(array $post, array $files, string $idCliente): array {
        $idCliente     = $this->norm($idCliente);
        $equipoId      = (int)($post['equipo_id']     ?? 0);
        $tipoCert      = trim($post['tipo_cert']      ?? '');
        $folio         = trim($post['folio']          ?? '');
        $fechaEmision  = trim($post['fecha_emision']  ?? '');
        $fechaVigencia = trim($post['fecha_vigencia'] ?? '');
        $notas         = trim($post['notas']          ?? '');
        $id            = (int)($post['id']            ?? 0);

        if (!$equipoId || !$tipoCert) return ['status' => 'error', 'message' => 'equipo_id y tipo_cert son requeridos.'];
        if (!$this->ownEquipo($equipoId, $idCliente)) return ['status' => 'error', 'message' => 'Equipo no encontrado.'];

        $archivoUrl = null;
        if (!empty($files['archivo']['tmp_name'])) {
            $ext = strtolower(pathinfo($files['archivo']['name'] ?? '', PATHINFO_EXTENSION));
            if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'])) {
                return ['status' => 'error', 'message' => "Extensión .$ext no permitida."];
            }
            $dir = rtrim(UPLOAD_DIR, '/') . "/equipos_cliente/certs/$equipoId/";
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $fn = date('YmdHis') . "_cert.$ext";
            if (!move_uploaded_file($files['archivo']['tmp_name'], $dir . $fn)) {
                return ['status' => 'error', 'message' => 'Error al guardar el archivo.'];
            }
            $archivoUrl = "uploads/equipos_cliente/certs/$equipoId/$fn";
        }

        $emDb  = $fechaEmision  ? date('Y-m-d', strtotime(str_replace('/', '-', $fechaEmision)))  : null;
        $vigDb = $fechaVigencia ? date('Y-m-d', strtotime(str_replace('/', '-', $fechaVigencia))) : null;

        if ($id) {
            $s2 = $this->pdo->prepare("
                SELECT c.id, c.archivo_url FROM cliente_equipos_cert c
                JOIN cliente_equipos e ON e.id = c.equipo_id
                WHERE c.id=? AND e.id_cliente=?
            ");
            $s2->execute([$id, $idCliente]);
            $ex = $s2->fetch();
            if (!$ex) return ['status' => 'error', 'message' => 'Certificación no encontrada.'];

            if ($archivoUrl && $ex['archivo_url']) {
                $f = rtrim(UPLOAD_DIR, '/') . '/' . ltrim($ex['archivo_url'], '/');
                if (file_exists($f)) @unlink($f);
            }
            $urlSave = $archivoUrl ?? $ex['archivo_url'];
            $this->pdo->prepare(
                "UPDATE cliente_equipos_cert SET tipo_cert=?,folio=?,fecha_emision=?,fecha_vigencia=?,archivo_url=?,notas=? WHERE id=?"
            )->execute([$tipoCert, $folio, $emDb, $vigDb, $urlSave, $notas, $id]);
        } else {
            $this->pdo->prepare(
                "INSERT INTO cliente_equipos_cert (equipo_id,tipo_cert,folio,fecha_emision,fecha_vigencia,archivo_url,notas)
                 VALUES (?,?,?,?,?,?,?)"
            )->execute([$equipoId, $tipoCert, $folio, $emDb, $vigDb, $archivoUrl, $notas]);
            $id = (int)$this->pdo->lastInsertId();
        }
        return ['status' => 'success', 'id' => $id];
    }

    public function eliminarCert(int $id, string $idCliente): array {
        $idCliente = $this->norm($idCliente);
        $s = $this->pdo->prepare("
            SELECT c.id, c.archivo_url FROM cliente_equipos_cert c
            JOIN cliente_equipos e ON e.id = c.equipo_id
            WHERE c.id=? AND e.id_cliente=?
        ");
        $s->execute([$id, $idCliente]);
        $cert = $s->fetch();
        if (!$cert) return ['status' => 'error', 'message' => 'Certificación no encontrada.'];

        if ($cert['archivo_url']) {
            $f = rtrim(UPLOAD_DIR, '/') . '/' . ltrim($cert['archivo_url'], '/');
            if (file_exists($f)) @unlink($f);
        }
        $this->pdo->prepare("DELETE FROM cliente_equipos_cert WHERE id=?")->execute([$id]);
        return ['status' => 'success'];
    }

    // ════════════════════════════════════════════════════════
    //  DASHBOARD / ESTADÍSTICAS
    // ════════════════════════════════════════════════════════

    public function dashboard(string $idCliente): array {
        $idCliente = $this->norm($idCliente);
        if (!$idCliente) return ['status' => 'error', 'message' => 'id_cliente requerido.'];

        $s = $this->pdo->prepare("SELECT id, nombre FROM cliente_equipos WHERE id_cliente=?");
        $s->execute([$idCliente]);
        $equipos = $s->fetchAll();
        $total   = count($equipos);

        if (!$total) {
            return ['status' => 'success', 'total_equipos' => 0,
                    'certs'         => ['vigentes' => 0, 'por_vencer' => 0, 'vencidas' => 0, 'sin_fecha' => 0],
                    'docs_por_tipo' => [],
                    'horas_equipos' => []];
        }

        $ids = array_column($equipos, 'id');
        $in  = implode(',', array_fill(0, count($ids), '?'));

        $cStmt = $this->pdo->prepare("
            SELECT
                SUM(fecha_vigencia IS NULL)                                                    AS sin_fecha,
                SUM(fecha_vigencia >= CURDATE() AND DATEDIFF(fecha_vigencia,CURDATE()) > 30)   AS vigentes,
                SUM(fecha_vigencia >= CURDATE() AND DATEDIFF(fecha_vigencia,CURDATE()) <= 30)  AS por_vencer,
                SUM(fecha_vigencia < CURDATE())                                                AS vencidas
            FROM cliente_equipos_cert WHERE equipo_id IN ($in)
        ");
        $cStmt->execute($ids);
        $cs = $cStmt->fetch();

        $dStmt = $this->pdo->prepare("
            SELECT tipo_doc, COUNT(*) AS total,
                   SUM(vigencia IS NOT NULL AND vigencia < CURDATE()) AS vencidos
            FROM cliente_equipos_docs WHERE equipo_id IN ($in) GROUP BY tipo_doc
        ");
        $dStmt->execute($ids);

        $hStmt = $this->pdo->prepare("
            SELECT e.nombre, COALESCE(MAX(h.horas), 0) AS horas
            FROM cliente_equipos e
            LEFT JOIN cliente_equipos_horometro h ON h.equipo_id = e.id
            WHERE e.id IN ($in) GROUP BY e.id, e.nombre ORDER BY horas DESC LIMIT 10
        ");
        $hStmt->execute($ids);

        // Equipment by type
        $tipoStmt = $this->pdo->prepare(
            "SELECT COALESCE(NULLIF(TRIM(tipo),''),'Sin tipo') AS tipo, COUNT(*) AS total
             FROM cliente_equipos WHERE id_cliente=? GROUP BY tipo ORDER BY total DESC"
        );
        $tipoStmt->execute([$idCliente]);

        // Equipment by estado
        $estadoStmt = $this->pdo->prepare(
            "SELECT COALESCE(NULLIF(TRIM(estado),''),'Activo') AS estado, COUNT(*) AS total
             FROM cliente_equipos WHERE id_cliente=? GROUP BY estado ORDER BY total DESC"
        );
        $estadoStmt->execute([$idCliente]);

        // Próximas certificaciones venciendo en 90 días
        $proxStmt = $this->pdo->prepare("
            SELECT e.nombre AS equipo, c.tipo_cert,
                   DATE_FORMAT(c.fecha_vigencia,'%d/%m/%Y') AS fecha_vigencia,
                   DATEDIFF(c.fecha_vigencia, CURDATE()) AS dias_vig
            FROM cliente_equipos_cert c
            JOIN cliente_equipos e ON e.id = c.equipo_id
            WHERE c.equipo_id IN ($in)
              AND c.fecha_vigencia >= CURDATE()
              AND c.fecha_vigencia <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)
            ORDER BY c.fecha_vigencia ASC
        ");
        $proxStmt->execute($ids);

        // Documentos por vencer en 30 días
        $docsVStmt = $this->pdo->prepare("
            SELECT e.nombre AS equipo, d.nombre, d.tipo_doc,
                   DATE_FORMAT(d.vigencia,'%d/%m/%Y') AS vigencia,
                   DATEDIFF(d.vigencia, CURDATE()) AS dias_vig
            FROM cliente_equipos_docs d
            JOIN cliente_equipos e ON e.id = d.equipo_id
            WHERE d.equipo_id IN ($in)
              AND d.vigencia >= CURDATE()
              AND d.vigencia <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
            ORDER BY d.vigencia ASC
        ");
        $docsVStmt->execute($ids);

        return [
            'status'        => 'success',
            'total_equipos' => $total,
            'certs'         => [
                'vigentes'   => (int)($cs['vigentes']   ?? 0),
                'por_vencer' => (int)($cs['por_vencer'] ?? 0),
                'vencidas'   => (int)($cs['vencidas']   ?? 0),
                'sin_fecha'  => (int)($cs['sin_fecha']  ?? 0),
            ],
            'docs_por_tipo' => array_map(fn($d) => [
                'tipo'     => $d['tipo_doc'],
                'total'    => (int)$d['total'],
                'vencidos' => (int)$d['vencidos'],
            ], $dStmt->fetchAll()),
            'horas_equipos'     => array_map(fn($h) => [
                'nombre' => $h['nombre'],
                'horas'  => (float)$h['horas'],
            ], $hStmt->fetchAll()),
            'equipos_por_tipo'  => array_map(fn($r) => [
                'tipo'  => $r['tipo'],
                'total' => (int)$r['total'],
            ], $tipoStmt->fetchAll()),
            'equipos_por_estado' => array_map(fn($r) => [
                'estado' => $r['estado'],
                'total'  => (int)$r['total'],
            ], $estadoStmt->fetchAll()),
            'proximas_certs'    => array_map(fn($r) => [
                'equipo'         => $r['equipo'],
                'tipo_cert'      => $r['tipo_cert'],
                'fecha_vigencia' => $r['fecha_vigencia'],
                'dias_vig'       => (int)$r['dias_vig'],
            ], $proxStmt->fetchAll()),
            'docs_por_vencer'   => array_map(fn($r) => [
                'equipo'   => $r['equipo'],
                'nombre'   => $r['nombre'],
                'tipo_doc' => $r['tipo_doc'],
                'vigencia' => $r['vigencia'],
                'dias_vig' => (int)$r['dias_vig'],
            ], $docsVStmt->fetchAll()),
        ];
    }
}
