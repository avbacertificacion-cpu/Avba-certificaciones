<?php
/**
 * AVBA Certificaciones — Módulo Accesorios de Izaje
 */
class Accesorios {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    // ── Catálogo de tipos (público autenticado) ────────────
    public function listarTipos(): array {
        $rows = $this->pdo->query(
            "SELECT id, nombre FROM accesorios_tipos WHERE activo = 1 ORDER BY nombre"
        )->fetchAll();
        return ['status' => 'success', 'data' => $rows];
    }

    // ── Catálogo de tipos (admin — incluye inactivos) ──────
    public function listarTiposAdmin(): array {
        $rows = $this->pdo->query(
            "SELECT id, nombre, activo, fecha_creacion FROM accesorios_tipos ORDER BY nombre"
        )->fetchAll();
        return ['status' => 'success', 'data' => $rows];
    }

    // ── Crear tipo ─────────────────────────────────────────
    public function crearTipo(array $payload): array {
        $nombre = trim($payload['nombre'] ?? '');
        if (!$nombre) return ['status' => 'error', 'message' => 'El nombre es requerido.'];

        $dup = $this->pdo->prepare("SELECT id FROM accesorios_tipos WHERE nombre = ?");
        $dup->execute([$nombre]);
        if ($dup->fetch()) return ['status' => 'error', 'message' => 'Ya existe un tipo con ese nombre.'];

        $this->pdo->prepare("INSERT INTO accesorios_tipos (nombre) VALUES (?)")->execute([$nombre]);
        return ['status' => 'success', 'message' => 'Tipo creado.', 'id' => (int)$this->pdo->lastInsertId()];
    }

    // ── Editar tipo ────────────────────────────────────────
    public function editarTipo(array $payload): array {
        $id     = (int)($payload['id'] ?? 0);
        $nombre = trim($payload['nombre'] ?? '');
        if (!$id || !$nombre) return ['status' => 'error', 'message' => 'id y nombre son requeridos.'];

        $dup = $this->pdo->prepare("SELECT id FROM accesorios_tipos WHERE nombre = ? AND id != ?");
        $dup->execute([$nombre, $id]);
        if ($dup->fetch()) return ['status' => 'error', 'message' => 'Ya existe otro tipo con ese nombre.'];

        $activo = isset($payload['activo']) ? (int)$payload['activo'] : 1;
        $this->pdo->prepare("UPDATE accesorios_tipos SET nombre = ?, activo = ? WHERE id = ?")
            ->execute([$nombre, $activo, $id]);

        return ['status' => 'success', 'message' => 'Tipo actualizado.'];
    }

    // ── Eliminar tipo ──────────────────────────────────────
    public function eliminarTipo(array $payload): array {
        $id = (int)($payload['id'] ?? 0);
        if (!$id) return ['status' => 'error', 'message' => 'id requerido.'];
        $this->pdo->prepare("DELETE FROM accesorios_tipos WHERE id = ?")->execute([$id]);
        return ['status' => 'success', 'message' => 'Tipo eliminado.'];
    }

    // ── Crear sesión (cliente + ubicación + fecha) ─────────
    public function crearSesion(array $payload, string $usuario): array {
        $cliente = trim($payload['cliente'] ?? '');
        $fecha   = trim($payload['fecha']   ?? '');  // DD/MM/YYYY
        $coords  = trim($payload['coordenadas'] ?? '');
        $dir     = trim($payload['direccion']   ?? '');

        if (!$cliente) return ['status' => 'error', 'message' => 'El cliente es requerido.'];
        if (!$fecha)   return ['status' => 'error', 'message' => 'La fecha es requerida.'];

        // Convert DD/MM/YYYY → Y-m-d
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $fecha, $m)) {
            $fecha = "{$m[3]}-{$m[2]}-{$m[1]}";
        }

        $this->pdo->prepare(
            "INSERT INTO accesorios_sesiones (cliente, fecha, coordenadas, direccion, usuario)
             VALUES (?, ?, ?, ?, ?)"
        )->execute([$cliente, $fecha, $coords, $dir, $usuario]);

        return ['status' => 'success', 'sesion_id' => (int)$this->pdo->lastInsertId()];
    }

    // ── Guardar un accesorio (multipart) ───────────────────
    public function guardarAccesorio(array $post, array $files, string $usuario): array {
        $sesionId = (int)($post['sesion_id'] ?? 0);
        if (!$sesionId) return ['status' => 'error', 'message' => 'sesion_id requerido.'];

        $chk = $this->pdo->prepare("SELECT id FROM accesorios_sesiones WHERE id = ?");
        $chk->execute([$sesionId]);
        if (!$chk->fetch()) return ['status' => 'error', 'message' => 'Sesión no encontrada.'];

        $tipoId = ($post['tipo_id'] ?? '') !== '' ? (int)$post['tipo_id'] : null;
        $estado = in_array($post['estado'] ?? '', ['CUMPLE','NO CUMPLE'])
            ? $post['estado'] : 'CUMPLE';

        // Count existing accessories in session for orden
        $cntStmt = $this->pdo->prepare("SELECT COUNT(*) FROM accesorios_izaje WHERE sesion_id = ?");
        $cntStmt->execute([$sesionId]);
        $orden = (int)$cntStmt->fetchColumn() + 1;

        $this->pdo->prepare(
            "INSERT INTO accesorios_izaje
             (sesion_id, id_accesorio, tipo_id, marca, modelo, serie, capacidad, medidas, estado, orden)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $sesionId,
            trim($post['id_accesorio'] ?? ''),
            $tipoId,
            trim($post['marca']    ?? ''),
            trim($post['modelo']   ?? ''),
            trim($post['serie']    ?? ''),
            trim($post['capacidad']?? ''),
            trim($post['medidas']  ?? ''),
            $estado,
            $orden,
        ]);

        $accesorioId = (int)$this->pdo->lastInsertId();

        // Handle photos (max 6, multipart files['fotos'])
        $fotosArr = $files['fotos'] ?? [];
        if (!empty($fotosArr['tmp_name'])) {
            if (!is_array($fotosArr['tmp_name'])) {
                // Single file — normalize
                $fotosArr = [
                    'tmp_name' => [$fotosArr['tmp_name']],
                    'name'     => [$fotosArr['name']],
                    'error'    => [$fotosArr['error']],
                ];
            }

            $uploadDir = __DIR__ . '/../uploads/accesorios/' . $sesionId . '/';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
                // Photos directory could not be created — skip silently, data is saved
            } else {
                $fotoOrden = 1;
                foreach ($fotosArr['tmp_name'] as $i => $tmpName) {
                    if (($fotosArr['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
                    if ($fotoOrden > 6) break;

                    $ext = strtolower(pathinfo($fotosArr['name'][$i] ?? '', PATHINFO_EXTENSION));
                    if (!in_array($ext, ['jpg','jpeg','png','webp','heic'])) continue;

                    $fname = "acc_{$accesorioId}_{$fotoOrden}.{$ext}";
                    if (move_uploaded_file($tmpName, $uploadDir . $fname)) {
                        $url = "uploads/accesorios/{$sesionId}/{$fname}";
                        $this->pdo->prepare(
                            "INSERT INTO accesorios_fotos (accesorio_id, url, orden) VALUES (?, ?, ?)"
                        )->execute([$accesorioId, $url, $fotoOrden]);
                        $fotoOrden++;
                    }
                }
            }
        }

        // Return tipo nombre for display in the list
        $tipoNombre = '';
        if ($tipoId) {
            $tn = $this->pdo->prepare("SELECT nombre FROM accesorios_tipos WHERE id = ?");
            $tn->execute([$tipoId]);
            $tipoNombre = (string)($tn->fetchColumn() ?: '');
        }

        return [
            'status'      => 'success',
            'message'     => 'Accesorio guardado.',
            'id'          => $accesorioId,
            'tipo_nombre' => $tipoNombre,
        ];
    }

    // ── Listar sesiones con resumen de accesorios ──────────
    public function listarSesiones(): array {
        $rows = $this->pdo->query(
            "SELECT s.id, s.cliente,
                    DATE_FORMAT(s.fecha,'%d/%m/%Y') AS fecha,
                    s.coordenadas, s.usuario,
                    DATE_FORMAT(s.fecha_registro,'%d/%m/%Y %H:%i') AS fecha_registro,
                    COUNT(a.id)                                           AS total,
                    SUM(a.estado = 'CUMPLE')                              AS cumple,
                    SUM(a.estado = 'NO CUMPLE')                           AS no_cumple
             FROM accesorios_sesiones s
             LEFT JOIN accesorios_izaje a ON a.sesion_id = s.id
             GROUP BY s.id
             ORDER BY s.fecha_registro DESC"
        )->fetchAll();

        return ['status' => 'success', 'data' => $rows];
    }

    // ── Detalle de una sesión con sus accesorios ───────────
    public function detalleSesion(int $id): array {
        $chk = $this->pdo->prepare("SELECT id, cliente, DATE_FORMAT(fecha,'%d/%m/%Y') AS fecha, coordenadas, usuario FROM accesorios_sesiones WHERE id = ?");
        $chk->execute([$id]);
        $sesion = $chk->fetch();
        if (!$sesion) return ['status' => 'error', 'message' => 'Sesión no encontrada.'];

        $stmt = $this->pdo->prepare(
            "SELECT a.id, a.id_accesorio, t.nombre AS tipo_nombre,
                    a.marca, a.modelo, a.serie, a.capacidad, a.medidas,
                    a.estado, a.orden,
                    COUNT(f.id) AS total_fotos
             FROM accesorios_izaje a
             LEFT JOIN accesorios_tipos t ON t.id = a.tipo_id
             LEFT JOIN accesorios_fotos f ON f.accesorio_id = a.id
             WHERE a.sesion_id = ?
             GROUP BY a.id
             ORDER BY a.orden"
        );
        $stmt->execute([$id]);
        $sesion['accesorios'] = $stmt->fetchAll();

        return ['status' => 'success', 'data' => $sesion];
    }
}
