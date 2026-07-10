<?php
/**
 * AVBA Certificaciones — Control Administrativo interno
 * Gestión documental del personal interno de AVBA (empleados) con vigencias.
 * (Fase 1: personal. Vehículos y expediente por cliente en fases siguientes.)
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

    private function own(int $id): bool {
        $s = $this->pdo->prepare("SELECT id FROM avba_personal WHERE id=?");
        $s->execute([$id]);
        return (bool)$s->fetch();
    }

    // ── PERSONAL ─────────────────────────────────────────────
    public function listarPersonal(): array {
        $stmt = $this->pdo->query("
            SELECT p.id, p.nombre, p.puesto, p.tipo, p.num_empleado, p.telefono, p.correo,
                   DATE_FORMAT(p.fecha_ingreso,'%d/%m/%Y') AS fecha_ingreso, p.estado, p.notas,
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
        ], $rows)];
    }

    public function guardarPersonal(array $data): array {
        $nombre = trim($data['nombre'] ?? '');
        if (!$nombre) return ['status' => 'error', 'message' => 'El nombre es requerido.'];
        $puesto = trim($data['puesto'] ?? '');
        $tipo   = trim($data['tipo'] ?? '');
        $numEmp = trim($data['num_empleado'] ?? '');
        $tel    = trim($data['telefono'] ?? '');
        $correo = trim($data['correo'] ?? '');
        $ingreso = trim($data['fecha_ingreso'] ?? '');
        $ingresoDb = $ingreso ? date('Y-m-d', strtotime(str_replace('/', '-', $ingreso))) : null;
        $estado = trim($data['estado'] ?? 'Activo');
        $notas  = trim($data['notas'] ?? '');
        $id     = (int)($data['id'] ?? 0);

        if ($correo && !filter_var($correo, FILTER_VALIDATE_EMAIL))
            return ['status' => 'error', 'message' => 'El correo no es válido.'];

        if ($id) {
            if (!$this->own($id)) return ['status' => 'error', 'message' => 'Empleado no encontrado.'];
            $this->pdo->prepare("UPDATE avba_personal SET nombre=?,puesto=?,tipo=?,num_empleado=?,telefono=?,correo=?,fecha_ingreso=?,estado=?,notas=? WHERE id=?")
                      ->execute([$nombre,$puesto,$tipo,$numEmp,$tel,$correo,$ingresoDb,$estado,$notas,$id]);
        } else {
            $this->pdo->prepare("INSERT INTO avba_personal (nombre,puesto,tipo,num_empleado,telefono,correo,fecha_ingreso,estado,notas) VALUES (?,?,?,?,?,?,?,?,?)")
                      ->execute([$nombre,$puesto,$tipo,$numEmp,$tel,$correo,$ingresoDb,$estado,$notas]);
            $id = (int)$this->pdo->lastInsertId();
        }
        return ['status' => 'success', 'id' => $id];
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
        return ['status' => 'success', 'personal' => $p, 'docs' => $docs];
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
