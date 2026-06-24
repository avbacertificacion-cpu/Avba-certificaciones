<?php
/**
 * AVBA Certificaciones — Gestión de Personal del Cliente
 * Permite al cliente registrar su plantilla y llevar control de:
 *   - Datos generales (nombre, puesto, departamento, etc.)
 *   - Certificaciones y capacitaciones
 *   - Documentos (licencias, antidopings, exámenes médicos, etc.)
 */
class ClientePersonal {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->migrate();
    }

    private function migrate(): void {
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS cliente_personal (
                  id              INT AUTO_INCREMENT PRIMARY KEY,
                  id_cliente      VARCHAR(20)  NOT NULL,
                  nombre          VARCHAR(200) NOT NULL,
                  puesto          VARCHAR(100) NULL,
                  departamento    VARCHAR(100) NULL,
                  numero_empleado VARCHAR(50)  NULL,
                  fecha_ingreso   DATE         NULL,
                  telefono        VARCHAR(30)  NULL,
                  notas           TEXT         NULL,
                  estado          VARCHAR(30)  NOT NULL DEFAULT 'Activo',
                  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  KEY idx_cli (id_cliente)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS cliente_personal_cert (
                  id             INT AUTO_INCREMENT PRIMARY KEY,
                  personal_id    INT          NOT NULL,
                  tipo_cert      VARCHAR(150) NOT NULL,
                  folio          VARCHAR(100) NULL,
                  entidad        VARCHAR(150) NULL,
                  fecha_emision  DATE         NULL,
                  fecha_vigencia DATE         NULL,
                  archivo_url    VARCHAR(500) NULL,
                  notas          TEXT         NULL,
                  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  KEY idx_per (personal_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS cliente_personal_docs (
                  id          INT AUTO_INCREMENT PRIMARY KEY,
                  personal_id INT          NOT NULL,
                  tipo_doc    VARCHAR(50)  NOT NULL DEFAULT 'otro',
                  nombre      VARCHAR(200) NOT NULL,
                  archivo_url VARCHAR(500) NULL,
                  vigencia    DATE         NULL,
                  notas       TEXT         NULL,
                  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  KEY idx_per (personal_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS cliente_notif_prefs (
                  id               INT AUTO_INCREMENT PRIMARY KEY,
                  id_cliente       VARCHAR(20) NOT NULL,
                  notif_email      VARCHAR(200) NULL,
                  notif_whatsapp   VARCHAR(30)  NULL,
                  notif_browser    TINYINT(1)   NOT NULL DEFAULT 0,
                  dias_anticip     INT          NOT NULL DEFAULT 30,
                  KEY idx_cli (id_cliente)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\PDOException $e) {
            error_log('[ClientePersonal] migrate: ' . $e->getMessage());
        }
    }

    private function norm(string $id): string {
        $id = trim($id);
        return (ctype_digit($id)) ? str_pad($id, 5, '0', STR_PAD_LEFT) : $id;
    }

    private function ownPersonal(int $id, string $idCliente): bool {
        $s = $this->pdo->prepare("SELECT id FROM cliente_personal WHERE id=? AND id_cliente=?");
        $s->execute([$id, $idCliente]);
        return (bool)$s->fetch();
    }

    /**
     * Fragmento SQL para restringir a los ids asignados a un sub-usuario.
     * $soloIds === null → sin restricción; [] → sin acceso.
     * @return array{0:string,1:array} [fragmentoSQL, params]
     */
    private function restro(?array $soloIds, string $colId): array {
        if ($soloIds === null) return ['', []];
        if (!$soloIds)         return [" AND 1=0", []];
        $in = implode(',', array_fill(0, count($soloIds), '?'));
        return [" AND $colId IN ($in)", array_map('intval', $soloIds)];
    }

    // ════════════════════════════════════════════════════════
    //  PERSONAL
    // ════════════════════════════════════════════════════════

    public function listar(string $idCliente, ?array $soloIds = null): array {
        $idCliente = $this->norm($idCliente);
        if (!$idCliente) return ['status' => 'error', 'message' => 'id_cliente requerido.'];

        [$frag, $fragParams] = $this->restro($soloIds, 'p.id');
        $stmt = $this->pdo->prepare("
            SELECT p.id, p.nombre, p.puesto, p.departamento, p.numero_empleado,
                   DATE_FORMAT(p.fecha_ingreso,'%d/%m/%Y') AS fecha_ingreso,
                   p.telefono, p.notas, p.estado,
                   DATE_FORMAT(p.created_at,'%d/%m/%Y') AS fecha_registro,
                   COUNT(DISTINCT d.id)  AS total_docs,
                   COUNT(DISTINCT c.id)  AS total_certs,
                   COALESCE(SUM(c.fecha_vigencia >= CURDATE()), 0) AS certs_vigentes,
                   COALESCE(SUM(c.fecha_vigencia < CURDATE() AND c.fecha_vigencia IS NOT NULL), 0) AS certs_vencidas,
                   COALESCE(SUM(c.fecha_vigencia >= CURDATE() AND c.fecha_vigencia <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)), 0) AS certs_por_vencer
            FROM cliente_personal p
            LEFT JOIN cliente_personal_docs d ON d.personal_id = p.id
            LEFT JOIN cliente_personal_cert c ON c.personal_id = p.id
            WHERE p.id_cliente = ?$frag
            GROUP BY p.id, p.nombre, p.puesto, p.departamento, p.numero_empleado,
                     p.fecha_ingreso, p.telefono, p.notas, p.estado, p.created_at
            ORDER BY p.nombre
        ");
        $stmt->execute(array_merge([$idCliente], $fragParams));

        return ['status' => 'success', 'personal' => array_map(fn($r) => [
            'id'              => (int)$r['id'],
            'nombre'          => $r['nombre'],
            'puesto'          => $r['puesto']          ?? '',
            'departamento'    => $r['departamento']    ?? '',
            'numero_empleado' => $r['numero_empleado'] ?? '',
            'fecha_ingreso'   => $r['fecha_ingreso']   ?? '',
            'telefono'        => $r['telefono']        ?? '',
            'notas'           => $r['notas']           ?? '',
            'estado'          => $r['estado']          ?? 'Activo',
            'fecha_registro'  => $r['fecha_registro'],
            'total_docs'      => (int)$r['total_docs'],
            'total_certs'     => (int)$r['total_certs'],
            'certs_vigentes'  => (int)$r['certs_vigentes'],
            'certs_vencidas'  => (int)$r['certs_vencidas'],
            'certs_por_vencer'=> (int)$r['certs_por_vencer'],
        ], $stmt->fetchAll())];
    }

    public function guardar(array $data, string $idCliente): array {
        $idCliente = $this->norm($idCliente);
        $nombre    = trim($data['nombre']          ?? '');
        if (!$nombre) return ['status' => 'error', 'message' => 'El nombre es requerido.'];

        $puesto    = trim($data['puesto']          ?? '');
        $dpto      = trim($data['departamento']    ?? '');
        $numEmp    = trim($data['numero_empleado'] ?? '');
        $tel       = trim($data['telefono']        ?? '');
        $notas     = trim($data['notas']           ?? '');
        $estado    = trim($data['estado']          ?? 'Activo');
        $ingreso   = trim($data['fecha_ingreso']   ?? '');
        $ingresoDb = $ingreso ? date('Y-m-d', strtotime(str_replace('/', '-', $ingreso))) : null;
        $id        = (int)($data['id'] ?? 0);

        if ($id) {
            if (!$this->ownPersonal($id, $idCliente)) return ['status' => 'error', 'message' => 'Empleado no encontrado.'];
            $this->pdo->prepare(
                "UPDATE cliente_personal SET nombre=?,puesto=?,departamento=?,numero_empleado=?,fecha_ingreso=?,telefono=?,notas=?,estado=? WHERE id=?"
            )->execute([$nombre, $puesto, $dpto, $numEmp, $ingresoDb, $tel, $notas, $estado, $id]);
        } else {
            $this->pdo->prepare(
                "INSERT INTO cliente_personal (id_cliente,nombre,puesto,departamento,numero_empleado,fecha_ingreso,telefono,notas,estado)
                 VALUES (?,?,?,?,?,?,?,?,?)"
            )->execute([$idCliente, $nombre, $puesto, $dpto, $numEmp, $ingresoDb, $tel, $notas, $estado]);
            $id = (int)$this->pdo->lastInsertId();
        }
        return ['status' => 'success', 'id' => $id];
    }

    public function eliminar(int $id, string $idCliente): array {
        $idCliente = $this->norm($idCliente);
        if (!$this->ownPersonal($id, $idCliente)) return ['status' => 'error', 'message' => 'Empleado no encontrado.'];

        foreach (['cliente_personal_docs', 'cliente_personal_cert'] as $tabla) {
            $s = $this->pdo->prepare("SELECT archivo_url FROM $tabla WHERE personal_id=?");
            $s->execute([$id]);
            foreach ($s->fetchAll() as $row) {
                if (!empty($row['archivo_url'])) {
                    $f = rtrim(UPLOAD_DIR, '/') . '/' . ltrim($row['archivo_url'], '/');
                    if (file_exists($f)) @unlink($f);
                }
            }
        }
        foreach (['cliente_personal_docs', 'cliente_personal_cert'] as $t) {
            $this->pdo->prepare("DELETE FROM $t WHERE personal_id=?")->execute([$id]);
        }
        $this->pdo->prepare("DELETE FROM cliente_personal WHERE id=?")->execute([$id]);
        return ['status' => 'success'];
    }

    // ════════════════════════════════════════════════════════
    //  DETALLE COMPLETO
    // ════════════════════════════════════════════════════════

    public function detalle(int $id, string $idCliente, ?array $soloIds = null): array {
        $idCliente = $this->norm($idCliente);
        if ($soloIds !== null && !in_array($id, array_map('intval', $soloIds), true)) {
            return ['status' => 'error', 'message' => 'No autorizado para ver este empleado.'];
        }
        $s = $this->pdo->prepare("SELECT * FROM cliente_personal WHERE id=? AND id_cliente=?");
        $s->execute([$id, $idCliente]);
        $per = $s->fetch();
        if (!$per) return ['status' => 'error', 'message' => 'Empleado no encontrado.'];

        $docs = $this->pdo->prepare("
            SELECT id, tipo_doc, nombre, archivo_url,
                   DATE_FORMAT(vigencia,'%d/%m/%Y')   AS vigencia,
                   DATEDIFF(vigencia, CURDATE())       AS dias_vig,
                   (vigencia IS NOT NULL AND vigencia < CURDATE()) AS vencido,
                   notas,
                   DATE_FORMAT(created_at,'%d/%m/%Y') AS fecha_subida
            FROM cliente_personal_docs WHERE personal_id=? ORDER BY tipo_doc, nombre
        ");
        $docs->execute([$id]);

        $certs = $this->pdo->prepare("
            SELECT id, tipo_cert, folio, entidad,
                   DATE_FORMAT(fecha_emision ,'%d/%m/%Y') AS fecha_emision,
                   DATE_FORMAT(fecha_vigencia,'%d/%m/%Y') AS fecha_vigencia,
                   DATEDIFF(fecha_vigencia, CURDATE())     AS dias_vig,
                   (fecha_vigencia IS NOT NULL AND fecha_vigencia < CURDATE()) AS vencida,
                   archivo_url, notas
            FROM cliente_personal_cert WHERE personal_id=? ORDER BY fecha_vigencia DESC
        ");
        $certs->execute([$id]);

        return [
            'status'          => 'success',
            'personal'        => [
                'id'              => (int)$per['id'],
                'nombre'          => $per['nombre'],
                'puesto'          => $per['puesto']          ?? '',
                'departamento'    => $per['departamento']    ?? '',
                'numero_empleado' => $per['numero_empleado'] ?? '',
                'fecha_ingreso'   => $per['fecha_ingreso']   ? date('d/m/Y', strtotime($per['fecha_ingreso'])) : '',
                'telefono'        => $per['telefono']        ?? '',
                'notas'           => $per['notas']           ?? '',
                'estado'          => $per['estado']          ?? 'Activo',
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
            'certificaciones' => array_map(fn($r) => [
                'id'             => (int)$r['id'],
                'tipo_cert'      => $r['tipo_cert'],
                'folio'          => $r['folio']   ?? '',
                'entidad'        => $r['entidad'] ?? '',
                'fecha_emision'  => $r['fecha_emision']  ?? '',
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
        $idCliente  = $this->norm($idCliente);
        $personalId = (int)($post['personal_id'] ?? 0);
        $nombre     = trim($post['nombre']       ?? '');
        $tipoDoc    = trim($post['tipo_doc']     ?? 'otro');
        $vigencia   = trim($post['vigencia']     ?? '');
        $notas      = trim($post['notas']        ?? '');

        if (!$personalId || !$nombre) return ['status' => 'error', 'message' => 'personal_id y nombre son requeridos.'];
        if (!$this->ownPersonal($personalId, $idCliente)) return ['status' => 'error', 'message' => 'Empleado no encontrado.'];

        $archivoUrl = null;
        if (!empty($files['archivo']['tmp_name'])) {
            $ext = strtolower(pathinfo($files['archivo']['name'] ?? '', PATHINFO_EXTENSION));
            if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'docx', 'xlsx'])) {
                return ['status' => 'error', 'message' => "Tipo de archivo no permitido (.$ext)."];
            }
            $dir = rtrim(UPLOAD_DIR, '/') . "/personal_cliente/docs/$personalId/";
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $fn = date('YmdHis') . '_' . preg_replace('/[^a-z0-9_\-]/i', '_', pathinfo($files['archivo']['name'], PATHINFO_FILENAME)) . ".$ext";
            if (!move_uploaded_file($files['archivo']['tmp_name'], $dir . $fn)) {
                return ['status' => 'error', 'message' => 'Error al guardar el archivo.'];
            }
            $archivoUrl = "uploads/personal_cliente/docs/$personalId/$fn";
        }

        $vigDb = $vigencia ? date('Y-m-d', strtotime(str_replace('/', '-', $vigencia))) : null;
        $this->pdo->prepare(
            "INSERT INTO cliente_personal_docs (personal_id,tipo_doc,nombre,archivo_url,vigencia,notas) VALUES (?,?,?,?,?,?)"
        )->execute([$personalId, $tipoDoc, $nombre, $archivoUrl, $vigDb, $notas]);

        return ['status' => 'success'];
    }

    public function eliminarDoc(int $id, string $idCliente): array {
        $idCliente = $this->norm($idCliente);
        $s = $this->pdo->prepare("
            SELECT d.id, d.archivo_url FROM cliente_personal_docs d
            JOIN cliente_personal p ON p.id = d.personal_id
            WHERE d.id=? AND p.id_cliente=?
        ");
        $s->execute([$id, $idCliente]);
        $doc = $s->fetch();
        if (!$doc) return ['status' => 'error', 'message' => 'Documento no encontrado.'];

        if ($doc['archivo_url']) {
            $f = rtrim(UPLOAD_DIR, '/') . '/' . ltrim($doc['archivo_url'], '/');
            if (file_exists($f)) @unlink($f);
        }
        $this->pdo->prepare("DELETE FROM cliente_personal_docs WHERE id=?")->execute([$id]);
        return ['status' => 'success'];
    }

    public function editarDoc(array $post, array $files, string $idCliente): array {
        $idCliente = $this->norm($idCliente);
        $id        = (int)($post['id']       ?? 0);
        $nombre    = trim($post['nombre']    ?? '');
        $tipoDoc   = trim($post['tipo_doc']  ?? 'otro');
        $vigencia  = trim($post['vigencia']  ?? '');
        $notas     = trim($post['notas']     ?? '');

        if (!$id || !$nombre) return ['status' => 'error', 'message' => 'id y nombre requeridos.'];

        // Verify ownership
        $s = $this->pdo->prepare("
            SELECT d.id, d.archivo_url FROM cliente_personal_docs d
            JOIN cliente_personal p ON p.id = d.personal_id
            WHERE d.id=? AND p.id_cliente=?
        ");
        $s->execute([$id, $idCliente]);
        $doc = $s->fetch();
        if (!$doc) return ['status' => 'error', 'message' => 'Documento no encontrado.'];

        $archivoUrl = $doc['archivo_url'];
        if (!empty($files['archivo']['tmp_name'])) {
            $ext = strtolower(pathinfo($files['archivo']['name'] ?? '', PATHINFO_EXTENSION));
            if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'docx', 'xlsx'])) {
                return ['status' => 'error', 'message' => "Tipo de archivo no permitido (.$ext)."];
            }
            // Get personal_id for path
            $sp = $this->pdo->prepare("SELECT personal_id FROM cliente_personal_docs WHERE id=?");
            $sp->execute([$id]);
            $personalId = (int)$sp->fetchColumn();
            $dir = rtrim(UPLOAD_DIR, '/') . "/personal_cliente/docs/$personalId/";
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $fn = date('YmdHis') . '_' . preg_replace('/[^a-z0-9_\-]/i', '_', pathinfo($files['archivo']['name'], PATHINFO_FILENAME)) . ".$ext";
            if (!move_uploaded_file($files['archivo']['tmp_name'], $dir . $fn)) {
                return ['status' => 'error', 'message' => 'Error al guardar el archivo.'];
            }
            // Delete old file
            if ($archivoUrl) {
                $old = rtrim(UPLOAD_DIR, '/') . '/' . ltrim($archivoUrl, '/');
                if (file_exists($old)) @unlink($old);
            }
            $archivoUrl = "uploads/personal_cliente/docs/$personalId/$fn";
        }

        $vigDb = $vigencia ? date('Y-m-d', strtotime(str_replace('/', '-', $vigencia))) : null;
        $this->pdo->prepare(
            "UPDATE cliente_personal_docs SET nombre=?, tipo_doc=?, vigencia=?, notas=?, archivo_url=? WHERE id=?"
        )->execute([$nombre, $tipoDoc, $vigDb, $notas, $archivoUrl, $id]);

        return ['status' => 'success'];
    }

    // ════════════════════════════════════════════════════════
    //  CERTIFICACIONES
    // ════════════════════════════════════════════════════════

    public function guardarCert(array $post, array $files, string $idCliente): array {
        $idCliente     = $this->norm($idCliente);
        $personalId    = (int)($post['personal_id']    ?? 0);
        $tipoCert      = trim($post['tipo_cert']       ?? '');
        $folio         = trim($post['folio']           ?? '');
        $entidad       = trim($post['entidad']         ?? '');
        $fechaEmision  = trim($post['fecha_emision']   ?? '');
        $fechaVigencia = trim($post['fecha_vigencia']  ?? '');
        $notas         = trim($post['notas']           ?? '');
        $id            = (int)($post['id']             ?? 0);

        if (!$personalId || !$tipoCert) return ['status' => 'error', 'message' => 'personal_id y tipo_cert son requeridos.'];
        if (!$this->ownPersonal($personalId, $idCliente)) return ['status' => 'error', 'message' => 'Empleado no encontrado.'];

        $archivoUrl = null;
        if (!empty($files['archivo']['tmp_name'])) {
            $ext = strtolower(pathinfo($files['archivo']['name'] ?? '', PATHINFO_EXTENSION));
            if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'])) {
                return ['status' => 'error', 'message' => "Extensión .$ext no permitida."];
            }
            $dir = rtrim(UPLOAD_DIR, '/') . "/personal_cliente/certs/$personalId/";
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $fn = date('YmdHis') . "_cert.$ext";
            if (!move_uploaded_file($files['archivo']['tmp_name'], $dir . $fn)) {
                return ['status' => 'error', 'message' => 'Error al guardar el archivo.'];
            }
            $archivoUrl = "uploads/personal_cliente/certs/$personalId/$fn";
        }

        $emDb  = $fechaEmision  ? date('Y-m-d', strtotime(str_replace('/', '-', $fechaEmision)))  : null;
        $vigDb = $fechaVigencia ? date('Y-m-d', strtotime(str_replace('/', '-', $fechaVigencia))) : null;

        if ($id) {
            $s2 = $this->pdo->prepare("
                SELECT c.id, c.archivo_url FROM cliente_personal_cert c
                JOIN cliente_personal p ON p.id = c.personal_id
                WHERE c.id=? AND p.id_cliente=?
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
                "UPDATE cliente_personal_cert SET tipo_cert=?,folio=?,entidad=?,fecha_emision=?,fecha_vigencia=?,archivo_url=?,notas=? WHERE id=?"
            )->execute([$tipoCert, $folio, $entidad, $emDb, $vigDb, $urlSave, $notas, $id]);
        } else {
            $this->pdo->prepare(
                "INSERT INTO cliente_personal_cert (personal_id,tipo_cert,folio,entidad,fecha_emision,fecha_vigencia,archivo_url,notas)
                 VALUES (?,?,?,?,?,?,?,?)"
            )->execute([$personalId, $tipoCert, $folio, $entidad, $emDb, $vigDb, $archivoUrl, $notas]);
            $id = (int)$this->pdo->lastInsertId();
        }
        return ['status' => 'success', 'id' => $id];
    }

    public function eliminarCert(int $id, string $idCliente): array {
        $idCliente = $this->norm($idCliente);
        $s = $this->pdo->prepare("
            SELECT c.id, c.archivo_url FROM cliente_personal_cert c
            JOIN cliente_personal p ON p.id = c.personal_id
            WHERE c.id=? AND p.id_cliente=?
        ");
        $s->execute([$id, $idCliente]);
        $cert = $s->fetch();
        if (!$cert) return ['status' => 'error', 'message' => 'Certificación no encontrada.'];

        if ($cert['archivo_url']) {
            $f = rtrim(UPLOAD_DIR, '/') . '/' . ltrim($cert['archivo_url'], '/');
            if (file_exists($f)) @unlink($f);
        }
        $this->pdo->prepare("DELETE FROM cliente_personal_cert WHERE id=?")->execute([$id]);
        return ['status' => 'success'];
    }

    // ════════════════════════════════════════════════════════
    //  DASHBOARD
    // ════════════════════════════════════════════════════════

    public function dashboard(string $idCliente, ?array $soloIds = null): array {
        $idCliente = $this->norm($idCliente);
        if (!$idCliente) return ['status' => 'error', 'message' => 'id_cliente requerido.'];

        [$frag, $fragParams] = $this->restro($soloIds, 'id');
        $s = $this->pdo->prepare("SELECT id FROM cliente_personal WHERE id_cliente=?$frag");
        $s->execute(array_merge([$idCliente], $fragParams));
        $ids   = array_column($s->fetchAll(), 'id');
        $total = count($ids);

        if (!$total) {
            return ['status' => 'success', 'total_personal' => 0, 'activos' => 0, 'bajas' => 0,
                    'certs' => ['vigentes' => 0, 'por_vencer' => 0, 'vencidas' => 0, 'sin_fecha' => 0],
                    'por_departamento' => [], 'por_puesto' => [], 'proximas_certs' => [], 'docs_por_vencer' => []];
        }

        $in = implode(',', array_fill(0, $total, '?'));

        $cStmt = $this->pdo->prepare("
            SELECT
                SUM(fecha_vigencia IS NULL)                                                    AS sin_fecha,
                SUM(fecha_vigencia >= CURDATE() AND DATEDIFF(fecha_vigencia,CURDATE()) > 30)   AS vigentes,
                SUM(fecha_vigencia >= CURDATE() AND DATEDIFF(fecha_vigencia,CURDATE()) <= 30)  AS por_vencer,
                SUM(fecha_vigencia < CURDATE())                                                AS vencidas
            FROM cliente_personal_cert WHERE personal_id IN ($in)
        ");
        $cStmt->execute($ids);
        $cs = $cStmt->fetch();

        $stStmt = $this->pdo->prepare(
            "SELECT COALESCE(NULLIF(TRIM(estado),''),'Activo') AS estado, COUNT(*) AS total
             FROM cliente_personal WHERE id IN ($in) GROUP BY estado"
        );
        $stStmt->execute($ids);
        $estados = $stStmt->fetchAll();
        $activos = 0; $bajas = 0;
        foreach ($estados as $e) {
            if ($e['estado'] === 'Activo') $activos = (int)$e['total'];
            elseif ($e['estado'] === 'Baja') $bajas = (int)$e['total'];
        }

        $dptStmt = $this->pdo->prepare(
            "SELECT COALESCE(NULLIF(TRIM(departamento),''),'Sin departamento') AS departamento, COUNT(*) AS total
             FROM cliente_personal WHERE id IN ($in) GROUP BY departamento ORDER BY total DESC LIMIT 8"
        );
        $dptStmt->execute($ids);

        $ptoStmt = $this->pdo->prepare(
            "SELECT COALESCE(NULLIF(TRIM(puesto),''),'Sin puesto') AS puesto, COUNT(*) AS total
             FROM cliente_personal WHERE id IN ($in) GROUP BY puesto ORDER BY total DESC LIMIT 8"
        );
        $ptoStmt->execute($ids);

        $proxStmt = $this->pdo->prepare("
            SELECT p.nombre AS personal, c.tipo_cert,
                   DATE_FORMAT(c.fecha_vigencia,'%d/%m/%Y') AS fecha_vigencia,
                   DATEDIFF(c.fecha_vigencia, CURDATE()) AS dias_vig
            FROM cliente_personal_cert c
            JOIN cliente_personal p ON p.id = c.personal_id
            WHERE c.personal_id IN ($in)
              AND c.fecha_vigencia >= CURDATE()
              AND c.fecha_vigencia <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)
            ORDER BY c.fecha_vigencia ASC
        ");
        $proxStmt->execute($ids);

        $docsVStmt = $this->pdo->prepare("
            SELECT p.nombre AS personal, d.nombre, d.tipo_doc,
                   DATE_FORMAT(d.vigencia,'%d/%m/%Y') AS vigencia,
                   DATEDIFF(d.vigencia, CURDATE()) AS dias_vig
            FROM cliente_personal_docs d
            JOIN cliente_personal p ON p.id = d.personal_id
            WHERE d.personal_id IN ($in)
              AND d.vigencia >= CURDATE()
              AND d.vigencia <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
            ORDER BY d.vigencia ASC
        ");
        $docsVStmt->execute($ids);

        return [
            'status'         => 'success',
            'total_personal' => $total,
            'activos'        => $activos,
            'bajas'          => $bajas,
            'certs'          => [
                'vigentes'   => (int)($cs['vigentes']   ?? 0),
                'por_vencer' => (int)($cs['por_vencer'] ?? 0),
                'vencidas'   => (int)($cs['vencidas']   ?? 0),
                'sin_fecha'  => (int)($cs['sin_fecha']  ?? 0),
            ],
            'por_departamento' => array_map(fn($r) => ['departamento' => $r['departamento'], 'total' => (int)$r['total']], $dptStmt->fetchAll()),
            'por_puesto'       => array_map(fn($r) => ['puesto' => $r['puesto'], 'total' => (int)$r['total']], $ptoStmt->fetchAll()),
            'proximas_certs'   => array_map(fn($r) => [
                'personal'       => $r['personal'],
                'tipo_cert'      => $r['tipo_cert'],
                'fecha_vigencia' => $r['fecha_vigencia'],
                'dias_vig'       => (int)$r['dias_vig'],
            ], $proxStmt->fetchAll()),
            'docs_por_vencer'  => array_map(fn($r) => [
                'personal'  => $r['personal'],
                'nombre'    => $r['nombre'],
                'tipo_doc'  => $r['tipo_doc'],
                'vigencia'  => $r['vigencia'],
                'dias_vig'  => (int)$r['dias_vig'],
            ], $docsVStmt->fetchAll()),
        ];
    }

    public function getPrefsNotif(string $idCliente): array {
        $idCliente = $this->norm($idCliente);
        $s = $this->pdo->prepare("SELECT notif_email, notif_whatsapp, notif_browser, dias_anticip FROM cliente_notif_prefs WHERE id_cliente=? LIMIT 1");
        $s->execute([$idCliente]);
        $row = $s->fetch() ?: ['notif_email'=>'','notif_whatsapp'=>'','notif_browser'=>0,'dias_anticip'=>30];
        return ['status'=>'success','data'=>$row];
    }

    public function guardarPrefsNotif(array $payload, string $idCliente): array {
        $idCliente = $this->norm($idCliente);
        $email     = trim($payload['notif_email']    ?? '');
        $wa        = trim($payload['notif_whatsapp'] ?? '');
        $browser   = (int)(bool)($payload['notif_browser'] ?? 0);
        $dias      = max(1, min(90, (int)($payload['dias_anticip'] ?? 30)));
        $s = $this->pdo->prepare("SELECT id FROM cliente_notif_prefs WHERE id_cliente=?");
        $s->execute([$idCliente]);
        if ($s->fetch()) {
            $this->pdo->prepare("UPDATE cliente_notif_prefs SET notif_email=?,notif_whatsapp=?,notif_browser=?,dias_anticip=? WHERE id_cliente=?")->execute([$email,$wa,$browser,$dias,$idCliente]);
        } else {
            $this->pdo->prepare("INSERT INTO cliente_notif_prefs (id_cliente,notif_email,notif_whatsapp,notif_browser,dias_anticip) VALUES (?,?,?,?,?)")->execute([$idCliente,$email,$wa,$browser,$dias]);
        }
        return ['status'=>'success'];
    }

    public function getAlertasVencimiento(string $idCliente): array {
        $idCliente = $this->norm($idCliente);
        // Get dias_anticip from prefs (default 30)
        $sp = $this->pdo->prepare("SELECT dias_anticip FROM cliente_notif_prefs WHERE id_cliente=? LIMIT 1");
        $sp->execute([$idCliente]);
        $dias = (int)(($sp->fetch()['dias_anticip'] ?? 30));

        $stmt = $this->pdo->prepare("
            SELECT d.id, d.nombre, d.tipo_doc, d.vigencia,
                   DATEDIFF(d.vigencia, CURDATE()) AS dias_restantes,
                   p.nombre AS empleado, p.id AS personal_id
            FROM cliente_personal_docs d
            JOIN cliente_personal p ON p.id = d.personal_id
            WHERE p.id_cliente = ?
              AND d.vigencia IS NOT NULL
              AND d.vigencia <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
            ORDER BY d.vigencia ASC
            LIMIT 50
        ");
        $stmt->execute([$idCliente, $dias]);
        return ['status'=>'success','data'=>$stmt->fetchAll(),'dias_anticip'=>$dias];
    }
}
