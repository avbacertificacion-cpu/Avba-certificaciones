<?php
/**
 * AVBA Certificaciones — Módulo Accesorios de Izaje
 */

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

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
        $cliente = strtoupper(trim($payload['cliente'] ?? ''));
        $fecha   = trim($payload['fecha']   ?? '');  // DD/MM/YYYY
        $coords  = trim($payload['coordenadas'] ?? '');
        $dir     = strtoupper(trim($payload['direccion'] ?? ''));

        if (!$cliente) return ['status' => 'error', 'message' => 'El cliente es requerido.'];
        if (!$fecha)   return ['status' => 'error', 'message' => 'La fecha es requerida.'];

        // Convert DD/MM/YYYY → Y-m-d
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $fecha, $m)) {
            $fecha = "{$m[3]}-{$m[2]}-{$m[1]}";
        }

        $control = generarControl($this->pdo, $cliente);

        $this->pdo->prepare(
            "INSERT INTO accesorios_sesiones (cliente, fecha, coordenadas, direccion, usuario, control)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([$cliente, $fecha, $coords, $dir, $usuario, $control]);

        return ['status' => 'success', 'sesion_id' => (int)$this->pdo->lastInsertId(), 'control' => $control];
    }

    // ── Guardar un accesorio (multipart) ───────────────────
    public function guardarAccesorio(array $post, array $files, string $usuario): array {
        $this->ensureAccIzajeQrColumn();
        $sesionId = (int)($post['sesion_id'] ?? 0);
        if (!$sesionId) return ['status' => 'error', 'message' => 'sesion_id requerido.'];

        $chk = $this->pdo->prepare("SELECT id FROM accesorios_sesiones WHERE id = ?");
        $chk->execute([$sesionId]);
        if (!$chk->fetch()) return ['status' => 'error', 'message' => 'Sesión no encontrada.'];

        $tipoId = ($post['tipo_id'] ?? '') !== '' ? (int)$post['tipo_id'] : null;
        $estado = in_array($post['estado'] ?? '', ['CUMPLE','NO CUMPLE'])
            ? $post['estado'] : 'CUMPLE';

        $qrCodigo = trim($post['qr_codigo'] ?? '');
        if ($qrCodigo !== '' && !preg_match('/^\d{10}$/', $qrCodigo))
            return ['status' => 'error', 'message' => 'El código QR debe ser exactamente 10 dígitos.'];
        if ($qrCodigo !== '' && !$this->qrDisponible($qrCodigo))
            return ['status' => 'error', 'message' => 'Ese QR ya está en uso.'];

        // Count existing accessories in session for orden
        $cntStmt = $this->pdo->prepare("SELECT COUNT(*) FROM accesorios_izaje WHERE sesion_id = ?");
        $cntStmt->execute([$sesionId]);
        $orden = (int)$cntStmt->fetchColumn() + 1;

        $this->pdo->prepare(
            "INSERT INTO accesorios_izaje
             (sesion_id, id_accesorio, tipo_id, marca, modelo, serie, capacidad, medidas, estado, orden, qr_codigo)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $sesionId,
            strtoupper(trim($post['id_accesorio'] ?? '')),
            $tipoId,
            strtoupper(trim($post['marca']    ?? '')),
            strtoupper(trim($post['modelo']   ?? '')),
            strtoupper(trim($post['serie']    ?? '')),
            strtoupper(trim($post['capacidad']?? '')),
            strtoupper(trim($post['medidas']  ?? '')),
            $estado,
            $orden,
            $qrCodigo ?: null,
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

                    // Verificar MIME real
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mime  = finfo_file($finfo, $tmpName);
                    finfo_close($finfo);
                    $allowedMimes = ['image/jpeg','image/png','image/webp'];
                    if (!in_array($mime, $allowedMimes, true) || !getimagesize($tmpName)) continue;

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
            'qr_codigo'   => $qrCodigo ?: null,
        ];
    }

    // ── Mis sesiones de accesorios (filtradas por inspector) ──
    public function getMisSesiones(string $usuario): array {
        $this->ensureEstatusColumn('accesorios_sesiones');
        $stmt = $this->pdo->prepare(
            "SELECT s.id, s.cliente, s.control, s.estatus,
                    DATE_FORMAT(s.fecha,'%d/%m/%Y') AS fecha,
                    s.coordenadas, s.direccion, s.usuario,
                    s.informe_url, s.qr_codigo,
                    DATE_FORMAT(s.fecha_registro,'%d/%m/%Y %H:%i') AS fecha_registro,
                    COUNT(a.id)                  AS total,
                    SUM(a.estado = 'CUMPLE')     AS cumple,
                    SUM(a.estado = 'NO CUMPLE')  AS no_cumple
             FROM accesorios_sesiones s
             LEFT JOIN accesorios_izaje a ON a.sesion_id = s.id
             WHERE s.usuario = ?
             GROUP BY s.id
             ORDER BY s.fecha_registro DESC"
        );
        $stmt->execute([$usuario]);
        return ['status' => 'success', 'data' => $stmt->fetchAll()];
    }

    // ── Buscar accesorios individuales por cualquier atributo ──
    public function buscarAccesorios(string $q, string $filtroEstatus = '', string $idCliente = ''): array {
        $q = trim($q);
        if (strlen($q) < 2) return ['status' => 'success', 'data' => []];

        $like   = '%' . $q . '%';
        $params = [$like, $like, $like, $like, $like, $like, $like, $like, $like, $like];
        $where  = "(a.id_accesorio LIKE ? OR a.marca LIKE ? OR a.modelo LIKE ?
                    OR a.serie LIKE ? OR a.capacidad LIKE ? OR a.medidas LIKE ?
                    OR a.estado LIKE ? OR a.qr_codigo LIKE ?
                    OR COALESCE(t.nombre,'') LIKE ? OR s.cliente LIKE ?)";

        if ($filtroEstatus === 'APROBADO_CALIDAD') {
            $where .= " AND s.estatus IN ('APROBADO_CALIDAD','EMITIDO')";
        } elseif ($filtroEstatus) {
            $where .= " AND s.estatus = " . $this->pdo->quote($filtroEstatus);
        }

        if ($idCliente) {
            $where   .= " AND s.control LIKE ?";
            $params[] = $idCliente . '-%';
        }

        $stmt = $this->pdo->prepare(
            "SELECT a.id, a.id_accesorio, COALESCE(t.nombre,'') AS tipo_nombre,
                    a.marca, a.modelo, a.serie, a.capacidad, a.medidas, a.estado, a.qr_codigo,
                    s.id AS sesion_id, s.cliente, s.control, s.estatus,
                    DATE_FORMAT(s.fecha,'%d/%m/%Y') AS fecha,
                    s.cert_url, s.informe_url, s.informe_cumple_url, s.qr_codigo AS sesion_qr
             FROM accesorios_izaje a
             JOIN accesorios_sesiones s ON s.id = a.sesion_id
             LEFT JOIN accesorios_tipos t ON t.id = a.tipo_id
             WHERE {$where}
             ORDER BY s.fecha DESC, a.orden
             LIMIT 100"
        );
        $stmt->execute($params);
        return ['status' => 'success', 'data' => $stmt->fetchAll()];
    }

    // ── Listar sesiones con resumen de accesorios ──────────
    public function listarSesiones(string $soloEstatus = ''): array {
        $this->ensureEstatusColumn('accesorios_sesiones');
        if ($soloEstatus === 'APROBADO_CALIDAD') {
            $where = "WHERE s.estatus IN ('APROBADO_CALIDAD','EMITIDO')";
        } elseif ($soloEstatus) {
            $where = "WHERE s.estatus = " . $this->pdo->quote($soloEstatus);
        } else {
            $where = '';
        }
        $rows = $this->pdo->query(
            "SELECT s.id, s.cliente, s.control, s.estatus,
                    DATE_FORMAT(s.fecha,'%d/%m/%Y') AS fecha,
                    s.coordenadas, s.usuario,
                    DATE_FORMAT(s.fecha_registro,'%d/%m/%Y %H:%i') AS fecha_registro,
                    COUNT(a.id)                                           AS total,
                    SUM(a.estado = 'CUMPLE')                              AS cumple,
                    SUM(a.estado = 'NO CUMPLE')                           AS no_cumple
             FROM accesorios_sesiones s
             LEFT JOIN accesorios_izaje a ON a.sesion_id = s.id
             {$where}
             GROUP BY s.id
             ORDER BY s.fecha_registro DESC"
        )->fetchAll();

        return ['status' => 'success', 'data' => $rows];
    }

    // ── Detalle de una sesión con sus accesorios ───────────
    public function detalleSesion(int $id): array {
        $this->ensureAccSesionesColumns();
        $chk = $this->pdo->prepare("SELECT id, cliente, control, estatus, DATE_FORMAT(fecha,'%d/%m/%Y') AS fecha, coordenadas, direccion, usuario, qr_codigo, informe_url FROM accesorios_sesiones WHERE id = ?");
        $chk->execute([$id]);
        $sesion = $chk->fetch();
        if (!$sesion) return ['status' => 'error', 'message' => 'Sesión no encontrada.'];

        $stmt = $this->pdo->prepare(
            "SELECT a.id, a.id_accesorio, t.nombre AS tipo_nombre,
                    a.marca, a.modelo, a.serie, a.capacidad, a.medidas,
                    a.estado, a.orden, a.qr_codigo,
                    COUNT(f.id) AS total_fotos
             FROM accesorios_izaje a
             LEFT JOIN accesorios_tipos t ON t.id = a.tipo_id
             LEFT JOIN accesorios_fotos f ON f.accesorio_id = a.id
             WHERE a.sesion_id = ?
             GROUP BY a.id
             ORDER BY a.orden"
        );
        $stmt->execute([$id]);
        $accesorios = $stmt->fetchAll();

        // Cargar URLs de fotos por accesorio
        $fotoStmt = $this->pdo->prepare(
            "SELECT accesorio_id, url FROM accesorios_fotos WHERE accesorio_id IN
             (SELECT id FROM accesorios_izaje WHERE sesion_id = ?) ORDER BY orden"
        );
        $fotoStmt->execute([$id]);
        $fotoMap = [];
        foreach ($fotoStmt->fetchAll() as $f) {
            $fotoMap[$f['accesorio_id']][] = $f['url'];
        }
        foreach ($accesorios as &$acc) {
            $acc['fotos'] = $fotoMap[$acc['id']] ?? [];
        }
        unset($acc);

        $sesion['accesorios'] = $accesorios;

        return ['status' => 'success', 'data' => $sesion];
    }

    // ── Editar accesorio inspeccionado ────────────────────
    public function editarAccesorio(array $payload): array {
        $this->ensureAccIzajeQrColumn();
        $id        = (int)($payload['id']           ?? 0);
        $tipoId    = (int)($payload['tipo_id']       ?? 0) ?: null;
        $idAcc     = strtoupper(trim($payload['id_accesorio']   ?? ''));
        $marca     = strtoupper(trim($payload['marca']          ?? ''));
        $modelo    = strtoupper(trim($payload['modelo']         ?? ''));
        $serie     = strtoupper(trim($payload['serie']          ?? ''));
        $capacidad = strtoupper(trim($payload['capacidad']      ?? ''));
        $medidas   = strtoupper(trim($payload['medidas']        ?? ''));
        $estado    = trim($payload['estado']         ?? '');
        $qrCodigo  = trim($payload['qr_codigo']      ?? '');

        if ($id <= 0) return ['status' => 'error', 'message' => 'id requerido.'];

        if ($estado && !in_array($estado, ['CUMPLE','NO CUMPLE'], true))
            return ['status' => 'error', 'message' => 'Estado no válido. Use CUMPLE o NO CUMPLE.'];

        if ($qrCodigo !== '' && !preg_match('/^\d{10}$/', $qrCodigo))
            return ['status' => 'error', 'message' => 'El código QR debe ser exactamente 10 dígitos.'];

        $chk = $this->pdo->prepare("SELECT id, qr_codigo FROM accesorios_izaje WHERE id = ?");
        $chk->execute([$id]);
        $row = $chk->fetch();
        if (!$row) return ['status' => 'error', 'message' => 'Accesorio no encontrado.'];

        if ($qrCodigo !== '' && $row['qr_codigo'] !== $qrCodigo && !$this->qrDisponible($qrCodigo, $id))
            return ['status' => 'error', 'message' => 'El código QR ya está en uso en otro registro.'];

        $this->pdo->prepare(
            "UPDATE accesorios_izaje
             SET tipo_id=?, id_accesorio=?, marca=?, modelo=?, serie=?, capacidad=?, medidas=?, estado=?, qr_codigo=?
             WHERE id=?"
        )->execute([$tipoId, $idAcc, $marca, $modelo, $serie, $capacidad, $medidas, $estado, $qrCodigo ?: null, $id]);

        return ['status' => 'success', 'message' => 'Accesorio actualizado.'];
    }

    // ── Aprobar sesión → APROBADO_CALIDAD ────────────────
    public function aprobarSesion(int $id, string $usuario, string $qr): array {
        $this->ensureAccSesionesColumns();
        $chk = $this->pdo->prepare("SELECT id, cliente, control, qr_codigo FROM accesorios_sesiones WHERE id = ?");
        $chk->execute([$id]);
        $sesion = $chk->fetch();
        if (!$sesion) return ['status' => 'error', 'message' => 'Sesión no encontrada.'];

        if (!$qr) return ['status' => 'error', 'message' => 'El código QR es requerido.'];

        // Validar QR existe
        $stmtQR = $this->pdo->prepare("SELECT id, usado FROM qr_codigos WHERE identificador = ?");
        $stmtQR->execute([$qr]);
        $qrRow = $stmtQR->fetch();
        if (!$qrRow) return ['status' => 'error', 'message' => 'Código QR no válido.'];

        // Permitir reusar el QR que ya estaba asignado a esta sesión (retorno de certificaciones)
        $mismoQr = ($sesion['qr_codigo'] === $qr);
        if ($qrRow['usado'] && !$mismoQr) return ['status' => 'error', 'message' => 'Código QR ya está en uso.'];

        // Generar control si la sesión no lo tiene (registros previos a migration_009)
        if (empty($sesion['control'])) {
            $control = generarControl($this->pdo, $sesion['cliente']);
            $this->pdo->prepare("UPDATE accesorios_sesiones SET control = ? WHERE id = ?")
                ->execute([$control, $id]);
        }

        $this->pdo->prepare("UPDATE accesorios_sesiones SET estatus = 'APROBADO_CALIDAD', qr_codigo = ? WHERE id = ?")
            ->execute([$qr, $id]);

        // Marcar QR como usado (solo si no lo estaba ya por ser el mismo QR previo)
        if (!$qrRow['usado']) {
            $this->pdo->prepare("UPDATE qr_codigos SET usado = 1 WHERE id = ?")
                ->execute([$qrRow['id']]);
        }

        return ['status' => 'success', 'message' => 'Sesión aprobada y enviada a Certificaciones.'];
    }

    // ── Devolver sesión → DEVUELTO ─────────────────────
    public function devolverSesion(int $id, string $usuario): array {
        $this->ensureEstatusColumn('accesorios_sesiones');
        $chk = $this->pdo->prepare("SELECT id FROM accesorios_sesiones WHERE id = ?");
        $chk->execute([$id]);
        $row = $chk->fetch();
        if (!$row) return ['status' => 'error', 'message' => 'Sesión no encontrada.'];

        // Conservar qr_codigo para que calidad lo vea pre-cargado al re-aprobar
        $this->pdo->prepare(
            "UPDATE accesorios_sesiones SET estatus = 'DEVUELTO' WHERE id = ?"
        )->execute([$id]);
        return ['status' => 'success', 'message' => 'Sesión devuelta a Calidad.'];
    }

    // ── Emitir informe → genera PDF + EMITIDO ─────────
    public function emitirInforme(int $sesionId, string $usuario): array {
        $resultado = $this->generarInforme($sesionId, $usuario);
        if ($resultado['status'] !== 'success') return $resultado;

        $this->pdo->prepare("UPDATE accesorios_sesiones SET estatus = 'EMITIDO' WHERE id = ?")
            ->execute([$sesionId]);

        $resultado['message'] = 'Informe emitido correctamente.';
        // Save URL so portal can show the link
        $informeUrl = rtrim(SITE_URL, '/') . '/' . ltrim($resultado['url'], '/');
        $this->pdo->prepare("UPDATE accesorios_sesiones SET informe_url = ? WHERE id = ?")->execute([$informeUrl, $sesionId]);
        return $resultado;
    }

    // ── Obtener config de plantilla de fondo ──────────────
    public function obtenerPlantillaAcc(): array {
        $this->ensurePlantillaAccTable();
        $row = $this->pdo->query("SELECT plantilla_pdf, pdf_campos FROM acc_plantilla_informe WHERE id = 1")->fetch();
        return ['status' => 'success', 'data' => [
            'plantilla_pdf' => $row['plantilla_pdf'] ?? null,
            'pdf_campos'    => json_decode($row['pdf_campos'] ?? '[]', true) ?: [],
        ]];
    }

    // ── Subir plantilla PDF de fondo ──────────────────────
    public function subirPlantillaAcc(array $post, array $files): array {
        $this->ensurePlantillaAccTable();

        $file = $files['plantilla'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK)
            return ['status' => 'error', 'message' => 'No se recibió archivo o hubo un error al subirlo.'];

        $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        if ($ext !== 'pdf')
            return ['status' => 'error', 'message' => 'Solo se aceptan archivos .pdf'];

        $dir = __DIR__ . '/../uploads/plantillas/';
        if (!is_dir($dir) && !mkdir($dir, 0755, true))
            return ['status' => 'error', 'message' => 'No se pudo crear el directorio.'];

        $filename = 'acc_informe_fondo.pdf';
        if (!move_uploaded_file($file['tmp_name'], $dir . $filename))
            return ['status' => 'error', 'message' => 'Error al guardar el archivo.'];

        $this->pdo->prepare("INSERT INTO acc_plantilla_informe (id, plantilla_pdf) VALUES (1, ?)
                             ON DUPLICATE KEY UPDATE plantilla_pdf = ?")
            ->execute([$filename, $filename]);

        return ['status' => 'success', 'message' => 'Plantilla guardada.'];
    }

    // ── Eliminar plantilla de fondo ───────────────────────
    public function eliminarPlantillaAcc(): array {
        $this->ensurePlantillaAccTable();
        $this->pdo->exec("UPDATE acc_plantilla_informe SET plantilla_pdf = NULL WHERE id = 1");
        return ['status' => 'success', 'message' => 'Plantilla eliminada.'];
    }

    // ── Previsualizar informe con datos de ejemplo ────────
    public function previsualizarInformeAcc(string $usuario): array {
        if (!class_exists('Dompdf\Dompdf')) {
            return ['status' => 'error', 'message' => 'Motor PDF no disponible en el servidor.'];
        }

        $sesionDemo = [
            'id'         => 0,
            'cliente'    => 'Empresa Ejemplo S.A. de C.V.',
            'fecha'      => date('d/m/Y'),
            'direccion'  => 'Av. Industrial 1200, Col. Parque Norte, Monterrey, N.L.',
            'usuario'    => $usuario ?: 'Inspector Demo',
            'accesorios' => [
                ['id_accesorio'=>'A-001','tipo_nombre'=>'Eslinga de Banda',  'marca'=>'Certex',           'modelo'=>'EW-60',  'serie'=>'CB2024-0112','capacidad'=>'3 Ton', 'medidas'=>'60mm×4m','estado'=>'APTO'],
                ['id_accesorio'=>'A-002','tipo_nombre'=>'Grillete de Arco',  'marca'=>'Columbus McKinnon','modelo'=>'S-209',  'serie'=>'CM2023-8847','capacidad'=>'5 Ton', 'medidas'=>'5/8"',   'estado'=>'APTO'],
                ['id_accesorio'=>'A-003','tipo_nombre'=>'Eslinga de Cable',  'marca'=>'Pfeifer',          'modelo'=>'PC-14',  'serie'=>'PF2022-3341','capacidad'=>'2 Ton', 'medidas'=>'14mm×6m','estado'=>'CONDICIONADO'],
                ['id_accesorio'=>'A-004','tipo_nombre'=>'Gancho con Seguro', 'marca'=>'Crosby',           'modelo'=>'G-4163', 'serie'=>'CR2024-5521','capacidad'=>'10 Ton','medidas'=>'—',      'estado'=>'APTO'],
                ['id_accesorio'=>'A-005','tipo_nombre'=>'Cadena de Izado',   'marca'=>'Pewag',            'modelo'=>'G80 RBG','serie'=>'PW2021-0098','capacidad'=>'4 Ton', 'medidas'=>'13mm×3m','estado'=>'NO APTO'],
            ],
        ];

        $folio = 'PREVIEW-ACC';
        $html  = $this->htmlInforme($sesionDemo, $folio);

        $opts = new \Dompdf\Options();
        $opts->setIsRemoteEnabled(true);
        $opts->setIsHtml5ParserEnabled(true);

        $pdf = new \Dompdf\Dompdf($opts);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4', 'portrait');
        $pdf->render();

        $dir = __DIR__ . '/../uploads/reportes/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $nombre = 'PREVIEW_INFORME_ACC.pdf';
        file_put_contents($dir . $nombre, protegerPdf($pdf->output()));

        return ['status' => 'success', 'url' => 'uploads/reportes/' . $nombre];
    }

    // ── Obtener campos de coordenadas del certificado ─────
    public function obtenerCamposPdfAcc(): array {
        $this->ensurePlantillaAccTable();
        $row = $this->pdo->query("SELECT plantilla_pdf, pdf_campos FROM acc_plantilla_informe WHERE id = 1")->fetch();
        $campos = json_decode($row['pdf_campos'] ?? '[]', true) ?: [];
        $pdfUrl = ($row['plantilla_pdf'] ?? null)
            ? rtrim(UPLOAD_URL, '/') . '/plantillas/' . $row['plantilla_pdf']
            : null;
        return ['status' => 'success', 'pdf_campos' => $campos, 'pdf_url' => $pdfUrl];
    }

    // ── Guardar campos de coordenadas del certificado ─────
    public function guardarCamposPdfAcc(array $payload): array {
        $this->ensurePlantillaAccTable();
        $campos  = $payload['campos'] ?? [];
        $decoded = is_string($campos) ? json_decode($campos, true) : $campos;
        if (!is_array($decoded))
            return ['status' => 'error', 'message' => 'campos debe ser un array JSON.'];

        $this->pdo->prepare("UPDATE acc_plantilla_informe SET pdf_campos = ? WHERE id = 1")
            ->execute([json_encode($decoded, JSON_UNESCAPED_UNICODE)]);
        return ['status' => 'success', 'message' => 'Campos guardados.'];
    }

    // ── Previsualizar certificado con FPDI ────────────────
    public function previsualizarCertAcc(array $payload): array {
        $this->ensurePlantillaAccTable();
        $row = $this->pdo->query("SELECT plantilla_pdf, pdf_campos FROM acc_plantilla_informe WHERE id = 1")->fetch();

        $campos = $payload['campos']
            ?? (json_decode($row['pdf_campos'] ?? '[]', true) ?: []);

        $rutaTpl = $row['plantilla_pdf']
            ? __DIR__ . '/../uploads/plantillas/' . $row['plantilla_pdf']
            : null;

        if (!$rutaTpl || !file_exists($rutaTpl)) {
            return ['status' => 'error', 'message' => 'Primero sube una plantilla PDF para el certificado.'];
        }
        if (!$campos) {
            return ['status' => 'error', 'message' => 'Configura al menos un campo antes de previsualizar.'];
        }

        // Cargar FPDI desde lib/ como fallback si Composer no lo tiene
        if (!class_exists('setasign\Fpdi\Fpdi')) {
            $loader = __DIR__ . '/../lib/fpdi_loader.php';
            if (file_exists($loader)) require_once $loader;
        }
        if (!class_exists('setasign\Fpdi\Fpdi')) {
            return ['status' => 'error', 'message' => 'Librería FPDI no disponible en el servidor.'];
        }

        $dummy = [
            'id_accesorio'     => '001',
            'tipo'             => 'Eslinga de Cadena',
            'marca'            => 'CROSBY',
            'modelo'           => 'G-100',
            'serie'            => 'SC2026-0129',
            'capacidad'        => '3.25 Ton',
            'medidas'          => '1" × 3 m',
            'estado'           => 'APTO',
            'cliente'          => 'HYH CONSTRUCCIONES Y ARRENDAMIENTO DEL GOLFO S.A DE C.V.',
            'fecha_inspeccion' => '26/03/2026',
            'inspector'          => 'Ing. José Marcos González Calderón',
            'folio'              => 'AB.45180-25656-2026MX',
            'total_accesorios'   => '15 ACCESORIOS INSPECCIONADOS',
            'lugar_inspeccion'   => 'Altamira, Tamaulipas',
        ];

        try {
        $pdf = new \setasign\Fpdi\Fpdi();
        $pdf->setSourceFile($rutaTpl);
        $tplIdx = $pdf->importPage(1);
        $sz = $pdf->getTemplateSize($tplIdx);
        $pdf->AddPage($sz['width'] > $sz['height'] ? 'L' : 'P', [$sz['width'], $sz['height']]);
        $pdf->useTemplate($tplIdx);

        // Firma de muestra: primer inspector con firma registrada
        $firmaRutaPreview = '';
        try {
            $r = $this->pdo->query("SELECT firma_imagen FROM usuarios WHERE rol='INSPECTOR' AND firma_imagen IS NOT NULL LIMIT 1")->fetch();
            if (!empty($r['firma_imagen'])) $firmaRutaPreview = __DIR__ . '/../' . $r['firma_imagen'];
        } catch (\Exception $e) {}

        foreach ($campos as $c) {
            $x     = (float)($c['x']     ?? 0);
            $y     = (float)($c['y']     ?? 0);
            $ancho = (float)($c['ancho'] ?? 0);
            if ($c['campo'] === 'firma_inspector') {
                if ($firmaRutaPreview && file_exists($firmaRutaPreview)) {
                    $alto = (float)($c['alto'] ?? ($ancho ?: 20));
                    $pdf->Image($firmaRutaPreview, $x, $y, $ancho ?: 40, $alto);
                }
                continue;
            }
            if ($c['campo'] === 'qr_imagen') {
                // Usar un QR de muestra apuntando a la URL de validación
                $qrTmp = sys_get_temp_dir() . '/avba_qr_prev_acc.png';
                $qrData = qrPngBytes(rtrim(SITE_URL, '/') . '/validar.html?qr=PREVIEW');
                if ($qrData) {
                    file_put_contents($qrTmp, $qrData);
                    $alto = (float)($c['alto'] ?? ($ancho ?: 25));
                    $pdf->Image($qrTmp, $x, $y, $ancho ?: 25, $alto);
                    @unlink($qrTmp);
                }
                continue;
            }
            $val   = $dummy[$c['campo']] ?? '';
            $color = str_pad(ltrim($c['color'] ?? '000000', '#'), 6, '0', STR_PAD_LEFT);
            [$r, $g, $b] = sscanf($color, '%02x%02x%02x');
            $pdf->SetTextColor($r ?? 0, $g ?? 0, $b ?? 0);
            $pdf->SetFont($c['fuente'] ?? 'Helvetica', $c['negrita'] ? 'B' : '', $c['tamano'] ?? 11);
            pdfCell($pdf, $x, $y, $ancho, (int)($c['tamano'] ?? 11), fpdfStr((string)$val));
        }

        $dir = __DIR__ . '/../uploads/reportes/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $nombre = 'PREVIEW_CERT_ACC.pdf';
        file_put_contents($dir . $nombre, protegerPdf($pdf->Output('S')));
        return ['status' => 'success', 'url' => 'uploads/reportes/' . $nombre];

        } catch (\Exception $e) {
            $comp = fpdiMsgCompresion($e);
            return ['status' => 'error', 'message' => $comp ?? ('Error generando vista previa: ' . $e->getMessage())];
        }
    }

    private function ensureAccIzajeQrColumn(): void {
        try {
            $this->pdo->exec("ALTER TABLE accesorios_izaje ADD COLUMN IF NOT EXISTS qr_codigo VARCHAR(20) NULL");
        } catch (\Throwable $e) {}
    }

    private function qrDisponible(string $qr, int $excludeAccId = 0): bool {
        // qr_codigos pool: if marked used by someone else
        $r = $this->pdo->prepare("SELECT id FROM qr_codigos WHERE identificador = ? AND usado = 1");
        $r->execute([$qr]);
        if ($r->fetch()) return false;

        // equipos
        $r = $this->pdo->prepare("SELECT id FROM equipos WHERE qr_codigo = ? LIMIT 1");
        $r->execute([$qr]);
        if ($r->fetch()) return false;

        // participantes_cursos
        $r = $this->pdo->prepare("SELECT id FROM participantes_cursos WHERE qr_codigo = ? LIMIT 1");
        $r->execute([$qr]);
        if ($r->fetch()) return false;

        // accesorios_sesiones
        $r = $this->pdo->prepare("SELECT id FROM accesorios_sesiones WHERE qr_codigo = ? LIMIT 1");
        $r->execute([$qr]);
        if ($r->fetch()) return false;

        // accesorios_izaje (excluding self)
        $sql    = "SELECT id FROM accesorios_izaje WHERE qr_codigo = ?";
        $params = [$qr];
        if ($excludeAccId > 0) { $sql .= " AND id != ?"; $params[] = $excludeAccId; }
        $r = $this->pdo->prepare($sql);
        $r->execute($params);
        if ($r->fetch()) return false;

        return true;
    }

    public function getSiguienteQrAcc(): array {
        $this->ensureAccIzajeQrColumn();
        $max = 0;
        $queries = [
            "SELECT MAX(CAST(identificador AS UNSIGNED)) FROM qr_codigos",
            "SELECT MAX(CAST(qr_codigo AS UNSIGNED)) FROM equipos WHERE qr_codigo IS NOT NULL AND qr_codigo <> ''",
            "SELECT MAX(CAST(qr_codigo AS UNSIGNED)) FROM participantes_cursos WHERE qr_codigo IS NOT NULL AND qr_codigo <> ''",
            "SELECT MAX(CAST(qr_codigo AS UNSIGNED)) FROM accesorios_sesiones WHERE qr_codigo IS NOT NULL AND qr_codigo <> ''",
            "SELECT MAX(CAST(qr_codigo AS UNSIGNED)) FROM accesorios_izaje WHERE qr_codigo IS NOT NULL AND qr_codigo <> ''",
        ];
        foreach ($queries as $sql) {
            try {
                $val = (int)$this->pdo->query($sql)->fetchColumn();
                if ($val > $max) $max = $val;
            } catch (\Throwable $e) {}
        }
        $siguiente = str_pad((string)($max + 1), 10, '0', STR_PAD_LEFT);
        return ['status' => 'success', 'qr' => $siguiente];
    }

    public function asignarQrAccesorio(int $id, string $qr): array {
        $this->ensureAccIzajeQrColumn();
        if (!preg_match('/^\d{10}$/', $qr))
            return ['status' => 'error', 'message' => 'El código QR debe ser exactamente 10 dígitos.'];

        $chk = $this->pdo->prepare("SELECT id, qr_codigo FROM accesorios_izaje WHERE id = ?");
        $chk->execute([$id]);
        $row = $chk->fetch();
        if (!$row) return ['status' => 'error', 'message' => 'Accesorio no encontrado.'];

        if ($row['qr_codigo'] !== $qr && !$this->qrDisponible($qr, $id))
            return ['status' => 'error', 'message' => 'El código QR ya está en uso en otro registro.'];

        $this->pdo->prepare("UPDATE accesorios_izaje SET qr_codigo = ? WHERE id = ?")
            ->execute([$qr, $id]);

        return ['status' => 'success', 'message' => 'QR asignado correctamente.', 'qr' => $qr];
    }

    private function ensureAccSesionesColumns(): void {
        $needed = [
            'estatus'    => "ALTER TABLE accesorios_sesiones ADD COLUMN estatus    VARCHAR(30)  NOT NULL DEFAULT 'PENDIENTE'",
            'qr_codigo'  => "ALTER TABLE accesorios_sesiones ADD COLUMN qr_codigo  VARCHAR(20)  NULL",
            'direccion'  => "ALTER TABLE accesorios_sesiones ADD COLUMN direccion  VARCHAR(500) NULL",
            'cert_url'          => "ALTER TABLE accesorios_sesiones ADD COLUMN cert_url          VARCHAR(500) NULL",
            'informe_url'       => "ALTER TABLE accesorios_sesiones ADD COLUMN informe_url       VARCHAR(500) NULL",
            'informe_cumple_url'=> "ALTER TABLE accesorios_sesiones ADD COLUMN informe_cumple_url VARCHAR(500) NULL",
        ];
        foreach ($needed as $col => $ddl) {
            $exists = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = 'accesorios_sesiones'
                   AND COLUMN_NAME  = '{$col}'"
            )->fetchColumn();
            if (!$exists) {
                try { $this->pdo->exec($ddl); } catch (\PDOException $e) {}
            }
        }
    }

    private function ensureEstatusColumn(string $tabla): void {
        // Kept for backward compat; accesorios_sesiones now uses ensureAccSesionesColumns
        if ($tabla === 'accesorios_sesiones') {
            $this->ensureAccSesionesColumns();
            return;
        }
        try {
            $this->pdo->exec("ALTER TABLE `{$tabla}` ADD COLUMN IF NOT EXISTS estatus VARCHAR(30) NOT NULL DEFAULT 'PENDIENTE'");
        } catch (\PDOException $e) {}
    }

    private function ensurePlantillaAccTable(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS acc_plantilla_informe (
              id            TINYINT UNSIGNED NOT NULL DEFAULT 1,
              plantilla_pdf VARCHAR(500)     NULL,
              pdf_campos    JSON             NULL,
              actualizado   TIMESTAMP        DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $this->pdo->exec("INSERT IGNORE INTO acc_plantilla_informe (id) VALUES (1)");
        // Add pdf_campos column if upgrading from previous version
        try {
            $this->pdo->exec("ALTER TABLE acc_plantilla_informe ADD COLUMN IF NOT EXISTS pdf_campos JSON NULL");
        } catch (\PDOException $e) { /* column already exists */ }
    }

    // ── Generar informe PDF de una sesión ──────────────────
    public function generarInforme(int $sesionId, string $usuario): array {
        $det = $this->detalleSesion($sesionId);
        if ($det['status'] !== 'success') return $det;
        $sesion = $det['data'];

        $folio = $sesion['control']
            ? 'AB.' . $sesion['control'] . '-' . date('Y') . 'MX'
            : 'ACC-' . str_pad((string)$sesionId, 5, '0', STR_PAD_LEFT);
        $html = $this->htmlInforme($sesion, $folio);

        try {
            $url = $this->htmlToPdfMpdf($html, $folio, 'INFORME_ACC');
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => 'Error generando PDF: ' . $e->getMessage()];
        }
        return ['status' => 'success', 'url' => $url, 'folio' => $folio];
    }

    // ── Generar certificado con mPDF (una página, HTML template) ──
    public function generarCertAcc(int $sesionId, string $usuario): array {
        $det = $this->detalleSesion($sesionId);
        if ($det['status'] !== 'success') return $det;
        $sesion = $det['data'];

        $accs = $sesion['accesorios'] ?? [];
        if (!$accs)
            return ['status' => 'error', 'message' => 'Esta sesión no tiene accesorios registrados.'];

        $qrCodigo = $sesion['qr_codigo'] ?? '';
        if (!$qrCodigo)
            return ['status' => 'error', 'message' => 'La sesión no tiene código QR. Apruébala en Calidad primero.'];

        // QR base64
        $qrB64 = qrDataUri(textoQR($qrCodigo), 300, 4);

        // Agrupar accesorios por tipo y contar → "03 Grilletes, 02 Eslingas, 01 Cancamos"
        $countsByType = [];
        foreach ($accs as $a) {
            $tipo = mb_convert_case(trim($a['tipo_nombre'] ?? ''), MB_CASE_TITLE, 'UTF-8') ?: 'Accesorio';
            $countsByType[$tipo] = ($countsByType[$tipo] ?? 0) + 1;
        }
        arsort($countsByType);
        $itemsList = [];
        foreach ($countsByType as $tipo => $cnt) {
            $itemsList[] = str_pad((string)$cnt, 2, '0', STR_PAD_LEFT) . ' ' . $tipo;
        }
        $resumenItems = implode(', ', $itemsList);

        $folio = $sesion['control']
            ? 'AB.' . $sesion['control'] . '-' . date('Y') . 'MX'
            : 'ACC-' . str_pad((string)$sesionId, 5, '0', STR_PAD_LEFT);

        // Vigencia = 1 año desde la fecha de inspección
        $vigencia = '';
        $fechaStr = $sesion['fecha'] ?? '';
        if ($fechaStr) {
            $fv = \DateTime::createFromFormat('d/m/Y', $fechaStr);
            if ($fv) { $fv->modify('+1 year'); $vigencia = $fv->format('d/m/Y'); }
        }

        $noAcreditacion = defined('NO_ACREDITACION') ? NO_ACREDITACION : 'UVNMX 057';
        $e = fn($s) => htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');

        $map = [
            '{folio}'            => $e($folio),
            '{cliente}'          => $e(mb_strtoupper(trim($sesion['cliente'] ?? ''), 'UTF-8')),
            '{resumen_items}'    => $e($resumenItems),
            '{fecha_inspeccion}' => $e($fechaStr),
            '{vigencia}'         => $e($vigencia),
            '{no_acreditacion}'  => $e($noAcreditacion),
            '{qr_imagen}'        => $qrB64 ?: 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7',
        ];

        $templatePath = __DIR__ . '/../certificado_accesorios_preview.html';
        if (!file_exists($templatePath))
            return ['status' => 'error', 'message' => 'Plantilla HTML de certificado no encontrada.'];

        $html = str_replace(array_keys($map), array_values($map), file_get_contents($templatePath));

        try {
            $url = $this->htmlToPdfMpdf($html, $folio, 'CERT_ACC');
        } catch (\Throwable $ex) {
            return ['status' => 'error', 'message' => 'Error generando certificado: ' . $ex->getMessage()];
        }

        return ['status' => 'success', 'url' => $url, 'folio' => $folio];
    }

    private function htmlToPdfMpdf(string $html, string $folio, string $sufijo = 'CERT'): string {
        if (!class_exists('\\Mpdf\\Mpdf')) {
            $autoload = __DIR__ . '/../vendor/autoload.php';
            if (file_exists($autoload)) require_once $autoload;
        }
        if (!class_exists('\\Mpdf\\Mpdf'))
            throw new \RuntimeException('mPDF no disponible. Verifica vendor/autoload.php.');

        $rutaDir = UPLOAD_DIR . 'reportes/';
        if (!is_dir($rutaDir)) mkdir($rutaDir, 0755, true);

        $mpdf = new \Mpdf\Mpdf([
            'mode'          => 'utf-8',
            'format'        => 'A4',
            'margin_left'   => 0, 'margin_right'  => 0,
            'margin_top'    => 0, 'margin_bottom' => 0,
            'margin_header' => 0, 'margin_footer' => 0,
            'dpi'           => 96,
            'default_font'  => 'dejavusans',
            'tempDir'       => sys_get_temp_dir() . '/mpdf',
        ]);
        $mpdf->SetBasePath(__DIR__ . '/../');
        $mpdf->SetHTMLFooter('');

        $prevBacktrack = (int) ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', 10000000);
        $mpdf->WriteHTML($html);
        ini_set('pcre.backtrack_limit', $prevBacktrack);

        $mpdf->SetProtection(['print'], '', 'Avba@Cert2024!');

        $nombre  = $sufijo . '_AVBA_' . $folio . '_' . date('Ymd_His') . '.pdf';
        $destino = $rutaDir . $nombre;
        $mpdf->Output($destino, 'F');
        return 'uploads/reportes/' . $nombre;
    }

    // ── Emitir certificado FPDI → genera PDF + EMITIDO ──────
    public function emitirCertAcc(int $sesionId, string $usuario): array {
        $resultado = $this->generarCertAcc($sesionId, $usuario);
        if ($resultado['status'] !== 'success') return $resultado;
        $this->pdo->prepare("UPDATE accesorios_sesiones SET estatus = 'EMITIDO' WHERE id = ?")
            ->execute([$sesionId]);
        $resultado['message'] = 'Certificado emitido correctamente.';
        // Save URL so portal can show the link
        $certUrl = rtrim(SITE_URL, '/') . '/' . ltrim($resultado['url'], '/');
        $this->pdo->prepare("UPDATE accesorios_sesiones SET cert_url = ? WHERE id = ?")->execute([$certUrl, $sesionId]);
        return $resultado;
    }

    // ── Enviar certificado FPDI por correo ────────────────
    public function enviarCertAcc(int $sesionId, string $correo, string $usuario): array {
        if (!$correo || !filter_var($correo, FILTER_VALIDATE_EMAIL))
            return ['status' => 'error', 'message' => 'Correo de destino inválido.'];

        $resultado = $this->generarCertAcc($sesionId, $usuario);
        if ($resultado['status'] !== 'success') return $resultado;

        if (!class_exists('PHPMailer\PHPMailer\PHPMailer'))
            return ['status' => 'error', 'message' => 'Servicio de correo no disponible en este servidor.'];

        $det     = $this->detalleSesion($sesionId);
        $cliente = $det['data']['cliente'] ?? 'Cliente';
        $rutaArchivo = __DIR__ . '/../' . $resultado['url'];

        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            configurarMailer($mail, $this->pdo);
            $mail->addAddress($correo);
            $mail->Subject = 'Certificado de Inspección de Accesorios de Izaje — AVBA Inspections';
            $mail->isHTML(true);
            $mail->Body = plantillaCorreoHtml($this->pdo,
                "<p style=\"font-size:14px;color:#5a6072;line-height:1.7\">Estimado/a,<br><br>Adjunto encontrará el <strong>certificado de inspección de accesorios de izaje</strong> para <strong>" . htmlspecialchars($cliente) . "</strong>.</p>"
            );
            $mail->addAttachment($rutaArchivo, basename($rutaArchivo));
            $mail->send();

            // Marcar como EMITIDO y guardar URL para el portal del cliente
            $certUrl = rtrim(SITE_URL, '/') . '/' . ltrim($resultado['url'], '/');
            $this->pdo->prepare(
                "UPDATE accesorios_sesiones SET estatus = 'EMITIDO', cert_url = ? WHERE id = ?"
            )->execute([$certUrl, $sesionId]);

            return ['status' => 'success', 'message' => "Certificado enviado a {$correo}."];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => 'Error al enviar: ' . $e->getMessage()];
        }
    }

    // ── Generar informe solo con accesorios CUMPLE ─────────
    public function generarInformeCumple(int $sesionId, string $usuario): array {
        $det = $this->detalleSesion($sesionId);
        if ($det['status'] !== 'success') return $det;
        $sesion = $det['data'];

        if (!class_exists('Dompdf\Dompdf'))
            return ['status' => 'error', 'message' => 'Motor PDF no disponible en el servidor.'];

        $sesion['accesorios'] = array_values(array_filter(
            $sesion['accesorios'] ?? [],
            fn($a) => strtoupper($a['estado'] ?? '') === 'CUMPLE'
        ));

        if (!$sesion['accesorios'])
            return ['status' => 'error', 'message' => 'Esta sesión no tiene accesorios en estado CUMPLE.'];

        $folio = ($sesion['control']
            ? 'AB.' . $sesion['control'] . '-' . date('Y') . 'MX'
            : 'ACC-' . str_pad((string)$sesionId, 5, '0', STR_PAD_LEFT)) . '-APROBADOS';

        $html = $this->htmlInforme($sesion, $folio);

        try {
            $url = $this->htmlToPdfMpdf($html, $folio, 'CUMPLE_ACC');
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => 'Error generando PDF: ' . $e->getMessage()];
        }
        return ['status' => 'success', 'url' => $url, 'folio' => $folio];
    }

    // ── Enviar informe completo por correo ───────────────
    public function enviarInformeAcc(int $sesionId, string $correo, string $usuario): array {
        if (!$correo || !filter_var($correo, FILTER_VALIDATE_EMAIL))
            return ['status' => 'error', 'message' => 'Correo de destino inválido.'];

        $resultado = $this->generarInforme($sesionId, $usuario);
        if ($resultado['status'] !== 'success') return $resultado;

        if (!class_exists('PHPMailer\PHPMailer\PHPMailer'))
            return ['status' => 'error', 'message' => 'Servicio de correo no disponible en este servidor.'];

        $det     = $this->detalleSesion($sesionId);
        $cliente = $det['data']['cliente'] ?? 'Cliente';
        $rutaArchivo = __DIR__ . '/../' . $resultado['url'];

        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            configurarMailer($mail, $this->pdo);
            $mail->addAddress($correo);
            $mail->Subject = 'Informe de Integridad Operativa — AVBA Inspections';
            $mail->isHTML(true);
            $mail->Body = plantillaCorreoHtml($this->pdo,
                "<p style=\"font-size:14px;color:#5a6072;line-height:1.7\">Estimado/a,<br><br>Adjunto encontrará el <strong>informe de integridad operativa</strong> de accesorios de izaje para <strong>" . htmlspecialchars($cliente) . "</strong>.</p>"
            );
            $mail->addAttachment($rutaArchivo, basename($rutaArchivo));
            $mail->send();

            // Marcar como EMITIDO y guardar URL para el portal del cliente
            $informeUrl = rtrim(SITE_URL, '/') . '/' . ltrim($resultado['url'], '/');
            $this->pdo->prepare(
                "UPDATE accesorios_sesiones SET estatus = 'EMITIDO', informe_url = ? WHERE id = ?"
            )->execute([$informeUrl, $sesionId]);

            return ['status' => 'success', 'message' => "Informe enviado a {$correo}."];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => 'Error al enviar: ' . $e->getMessage()];
        }
    }

    // ── Enviar informe solo CUMPLE por correo ─────────────
    public function enviarInformeCumple(int $sesionId, string $correo, string $usuario): array {
        if (!$correo || !filter_var($correo, FILTER_VALIDATE_EMAIL))
            return ['status' => 'error', 'message' => 'Correo de destino inválido.'];

        $resultado = $this->generarInformeCumple($sesionId, $usuario);
        if ($resultado['status'] !== 'success') return $resultado;

        if (!class_exists('PHPMailer\PHPMailer\PHPMailer'))
            return ['status' => 'error', 'message' => 'Servicio de correo no disponible en este servidor.'];

        $det     = $this->detalleSesion($sesionId);
        $cliente = $det['data']['cliente'] ?? 'Cliente';
        $rutaArchivo = __DIR__ . '/../' . $resultado['url'];

        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            configurarMailer($mail, $this->pdo);
            $mail->addAddress($correo);
            $mail->Subject = 'Informe de Accesorios Aprobados — AVBA Inspections';
            $mail->isHTML(true);
            $mail->Body = plantillaCorreoHtml($this->pdo,
                "<p style=\"font-size:14px;color:#5a6072;line-height:1.7\">Estimado/a,<br><br>Adjunto encontrará el <strong>informe de accesorios aprobados (CUMPLE)</strong> para <strong>" . htmlspecialchars($cliente) . "</strong>.</p>"
            );
            $mail->addAttachment($rutaArchivo, basename($rutaArchivo));
            $mail->send();

            // Marcar como EMITIDO y guardar URL del informe CUMPLE (columna separada)
            $informeUrl = rtrim(SITE_URL, '/') . '/' . ltrim($resultado['url'], '/');
            $this->pdo->prepare(
                "UPDATE accesorios_sesiones SET estatus = 'EMITIDO', informe_cumple_url = ? WHERE id = ?"
            )->execute([$informeUrl, $sesionId]);

            return ['status' => 'success', 'message' => "Informe enviado a {$correo}."];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => 'Error al enviar: ' . $e->getMessage()];
        }
    }

    // ── Enviar los 3 documentos en un solo correo ──────────
    public function enviarTodoAcc(int $sesionId, string $correo, string $usuario): array {
        if (!$correo || !filter_var($correo, FILTER_VALIDATE_EMAIL))
            return ['status' => 'error', 'message' => 'Correo de destino inválido.'];

        if (!class_exists('PHPMailer\PHPMailer\PHPMailer'))
            return ['status' => 'error', 'message' => 'Servicio de correo no disponible en este servidor.'];

        // Generar los 3 documentos
        $resCert   = $this->generarCertAcc($sesionId, $usuario);
        if ($resCert['status'] !== 'success') return $resCert;

        $resInforme = $this->generarInforme($sesionId, $usuario);
        if ($resInforme['status'] !== 'success') return $resInforme;

        $resCumple  = $this->generarInformeCumple($sesionId, $usuario);
        $tieneCumple = $resCumple['status'] === 'success';

        $det     = $this->detalleSesion($sesionId);
        $cliente = $det['data']['cliente'] ?? 'Cliente';

        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            configurarMailer($mail, $this->pdo);
            $mail->addAddress($correo);
            $mail->Subject = 'Documentos de Inspección de Accesorios de Izaje — AVBA Inspections';
            $mail->isHTML(true);
            $mail->Body = plantillaCorreoHtml($this->pdo,
                "<p style=\"font-size:14px;color:#5a6072;line-height:1.7\">Estimado/a,<br><br>Adjunto encontrará los documentos de inspección de accesorios de izaje para <strong>" . htmlspecialchars($cliente) . "</strong>:</p>"
                . "<ul style=\"font-size:13px;color:#5a6072;line-height:1.9;margin:0 0 8px 18px\">"
                . "<li><strong>Certificado de Inspección</strong></li>"
                . "<li><strong>Informe de Integridad Operativa</strong></li>"
                . ($tieneCumple ? "<li><strong>Informe de Accesorios Aprobados (CUMPLE)</strong></li>" : "")
                . "</ul>"
            );
            $mail->addAttachment(__DIR__ . '/../' . $resCert['url'],    basename($resCert['url']));
            $mail->addAttachment(__DIR__ . '/../' . $resInforme['url'], basename($resInforme['url']));
            if ($tieneCumple) {
                $mail->addAttachment(__DIR__ . '/../' . $resCumple['url'], basename($resCumple['url']));
            }
            $mail->send();

            // Actualizar BD con URLs y marcar EMITIDO
            $certUrl    = rtrim(SITE_URL, '/') . '/' . ltrim($resCert['url'],    '/');
            $informeUrl = rtrim(SITE_URL, '/') . '/' . ltrim($resInforme['url'], '/');
            $this->pdo->prepare(
                "UPDATE accesorios_sesiones SET estatus = 'EMITIDO', cert_url = ?, informe_url = ? WHERE id = ?"
            )->execute([$certUrl, $informeUrl, $sesionId]);

            if ($tieneCumple) {
                $cumpleUrl = rtrim(SITE_URL, '/') . '/' . ltrim($resCumple['url'], '/');
                $this->pdo->prepare(
                    "UPDATE accesorios_sesiones SET informe_cumple_url = ? WHERE id = ?"
                )->execute([$cumpleUrl, $sesionId]);
            }

            $docs = $tieneCumple ? 3 : 2;
            return ['status' => 'success', 'message' => "{$docs} documentos enviados a {$correo}."];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => 'Error al enviar: ' . $e->getMessage()];
        }
    }

    // ── HTML del Informe de Integridad Operativa ───────────
    private function htmlInforme(array $s, string $folio): string {
        $esc = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');

        $cliente = $esc($s['cliente'] ?? '');
        $dir     = $esc($s['direccion'] ?? '');
        $fecha   = $esc($s['fecha'] ?? '');

        // Inspector: nombre completo + firma
        $nombreInspector = $s['usuario'] ?? '';
        $firmaB64        = '';
        try {
            $st = $this->pdo->prepare("SELECT nombre, firma_imagen FROM usuarios WHERE usuario = ? LIMIT 1");
            $st->execute([$s['usuario'] ?? '']);
            $row = $st->fetch();
            if (!empty($row['nombre'])) $nombreInspector = $row['nombre'];
            if (!empty($row['firma_imagen'])) {
                $firmaPath = __DIR__ . '/../' . ltrim($row['firma_imagen'], '/');
                if (file_exists($firmaPath)) {
                    $ext      = strtolower(pathinfo($firmaPath, PATHINFO_EXTENSION));
                    $mime     = $ext === 'png' ? 'image/png' : 'image/jpeg';
                    $firmaB64 = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($firmaPath));
                }
            }
        } catch (\Throwable $ignored) {}
        $usuario = $esc($nombreInspector);

        $accs     = $s['accesorios'] ?? [];
        $total    = count($accs);
        $cumple   = count(array_filter($accs, fn($a) => strtoupper($a['estado'] ?? '') === 'CUMPLE'));
        $noCumple = $total - $cumple;

        // Firma HTML
        $firmaImg = $firmaB64
            ? '<img src="' . $firmaB64 . '" style="height:48px;width:auto;margin-bottom:4px">'
            : '<div style="height:48px"></div>';

        // Filas de accesorios
        $filas = '';
        foreach ($accs as $i => $a) {
            $bg  = ($i % 2 === 0) ? '#ffffff' : '#f0f4fa';
            $est = strtoupper($a['estado'] ?? '');
            if ($est === 'CUMPLE') {
                $estBg = '#d4edba'; $estColor = '#2d5a0e'; $estLabel = 'CUMPLE';
            } else {
                $estBg = '#fad7d7'; $estColor = '#8b1a1a'; $estLabel = $est ?: 'NO CUMPLE';
            }
            $filas .= '<tr style="background:' . $bg . '">
              <td style="padding:5px 6px;border:1px solid #c8d4e8;text-align:center;font-size:8.5pt;color:#1a1a2e">' . $esc($a['id_accesorio'] ?? '') . '</td>
              <td style="padding:5px 6px;border:1px solid #c8d4e8;font-size:8.5pt;color:#1a1a2e">' . $esc($a['tipo_nombre'] ?? '') . '</td>
              <td style="padding:5px 6px;border:1px solid #c8d4e8;font-size:8.5pt;color:#1a1a2e">' . $esc($a['marca'] ?? '') . '</td>
              <td style="padding:5px 6px;border:1px solid #c8d4e8;font-size:8.5pt;color:#1a1a2e">' . $esc($a['modelo'] ?? '') . '</td>
              <td style="padding:5px 6px;border:1px solid #c8d4e8;font-size:8.5pt;color:#1a1a2e;text-align:center">' . $esc($a['serie'] ?? '') . '</td>
              <td style="padding:5px 6px;border:1px solid #c8d4e8;font-size:8.5pt;color:#1a1a2e;text-align:center">' . $esc($a['capacidad'] ?? '') . '</td>
              <td style="padding:5px 6px;border:1px solid #c8d4e8;font-size:8.5pt;color:#1a1a2e;text-align:center">' . $esc($a['medidas'] ?? '') . '</td>
              <td style="padding:5px 6px;border:1px solid #c8d4e8;text-align:center;background:' . $estBg . ';font-size:8pt;font-weight:bold;color:' . $estColor . '">' . $esc($estLabel) . '</td>
            </tr>';
        }
        if (!$filas) {
            $filas = '<tr><td colspan="8" style="padding:18px;text-align:center;color:#9299a8;font-size:9pt;border:1px solid #c8d4e8">Sin accesorios registrados en esta sesión.</td></tr>';
        }

        // Logo
        $logoPath = __DIR__ . '/../icon-192.png';
        $logoTag  = '';
        if (file_exists($logoPath)) {
            $b64     = base64_encode(file_get_contents($logoPath));
            $logoTag = '<img src="data:image/png;base64,' . $b64 . '" style="height:56px;width:auto">';
        }

        $noAcred    = defined('NO_ACREDITACION') ? $esc(NO_ACREDITACION) : '';
        $noAcredDiv = $noAcred ? '<div style="font-size:8pt;color:#185FA5;margin-top:2pt">Unidad de Inspecci&#243;n acreditada &middot; ' . $noAcred . '</div>' : '';
        $hoy        = $esc(date('d/m/Y'));
        $hoyHi      = $esc(date('d/m/Y H:i'));

        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"></head>
<body style="font-family:DejaVu Sans,Arial,sans-serif;font-size:10pt;color:#1a1a2e;margin:0;padding:22px 28px;background:#fff">

<!-- ══════════════════════════════════════════════════
     ENCABEZADO
══════════════════════════════════════════════════ -->
<table style="width:100%;border-collapse:collapse;margin-bottom:0">
  <tr>
    <td style="width:80px;vertical-align:middle;padding-right:12px">{$logoTag}</td>
    <td style="vertical-align:middle">
      <div style="font-size:15pt;font-weight:bold;color:#0C2D6B;letter-spacing:0.02em">Informe de Integridad Operativa</div>
      <div style="font-size:8.5pt;color:#5a6072;margin-top:3px">Evaluación de accesorios de izaje — AVBA Inspections, Certifications and Maintenance S.A.S. de C.V.</div>
      {$noAcredDiv}
    </td>
    <td style="width:148px;vertical-align:top;text-align:right">
      <table style="border-collapse:collapse;width:100%">
        <tr><td style="background:#0C2D6B;color:#fff;padding:6px 10px;font-size:8pt;font-weight:bold;text-align:center">{$folio}</td></tr>
        <tr><td style="background:#e8eef8;color:#5a6072;padding:4px 10px;font-size:7.5pt;text-align:center">Fecha: {$hoy}</td></tr>
      </table>
    </td>
  </tr>
</table>

<!-- Barra azul divisora -->
<table style="width:100%;border-collapse:collapse;margin:10px 0 14px">
  <tr>
    <td style="background:#0C2D6B;height:4px;padding:0;font-size:1pt">&nbsp;</td>
    <td style="background:#C89520;height:4px;width:40px;padding:0;font-size:1pt">&nbsp;</td>
  </tr>
</table>

<!-- ══════════════════════════════════════════════════
     DATOS GENERALES
══════════════════════════════════════════════════ -->
<table style="width:100%;border-collapse:collapse;margin-bottom:14px">
  <tr>
    <td style="background:#0C2D6B;color:#fff;font-size:8pt;font-weight:bold;padding:4px 10px;letter-spacing:0.05em" colspan="4">DATOS GENERALES</td>
  </tr>
  <tr>
    <td style="width:18%;background:#e8eef8;padding:4px 8px;border:1px solid #c8d4e8;font-size:7.5pt;font-weight:bold;color:#0C2D6B">Cliente</td>
    <td style="width:46%;background:#f7f9fd;padding:4px 8px;border:1px solid #c8d4e8;font-size:9pt;color:#1a1a2e">{$cliente}</td>
    <td style="width:14%;background:#e8eef8;padding:4px 8px;border:1px solid #c8d4e8;font-size:7.5pt;font-weight:bold;color:#0C2D6B">Fecha</td>
    <td style="width:22%;background:#f7f9fd;padding:4px 8px;border:1px solid #c8d4e8;font-size:9pt;color:#1a1a2e">{$fecha}</td>
  </tr>
  <tr>
    <td style="background:#e8eef8;padding:4px 8px;border:1px solid #c8d4e8;font-size:7.5pt;font-weight:bold;color:#0C2D6B">Domicilio</td>
    <td style="background:#f7f9fd;padding:4px 8px;border:1px solid #c8d4e8;font-size:9pt;color:#1a1a2e">{$dir}</td>
    <td style="background:#e8eef8;padding:4px 8px;border:1px solid #c8d4e8;font-size:7.5pt;font-weight:bold;color:#0C2D6B">Inspector</td>
    <td style="background:#f7f9fd;padding:4px 8px;border:1px solid #c8d4e8;font-size:9pt;color:#1a1a2e">{$usuario}</td>
  </tr>
</table>

<!-- ══════════════════════════════════════════════════
     RESUMEN ESTADÍSTICO
══════════════════════════════════════════════════ -->
<table style="width:100%;border-collapse:collapse;margin-bottom:16px">
  <tr>
    <td style="background:#0C2D6B;color:#fff;font-size:8pt;font-weight:bold;padding:4px 10px;letter-spacing:0.05em" colspan="3">RESUMEN</td>
  </tr>
  <tr>
    <td style="width:33%;padding:0 4px 0 0">
      <table style="width:100%;border-collapse:collapse">
        <tr>
          <td style="background:#dce9f8;border:2px solid #185FA5;padding:10px 8px;text-align:center">
            <div style="font-size:22pt;font-weight:bold;color:#0C2D6B;line-height:1">{$total}</div>
            <div style="font-size:8pt;color:#185FA5;margin-top:4px;font-weight:bold">TOTAL INSPECCIONADOS</div>
          </td>
        </tr>
      </table>
    </td>
    <td style="width:33%;padding:0 4px">
      <table style="width:100%;border-collapse:collapse">
        <tr>
          <td style="background:#d4edba;border:2px solid #3B6D11;padding:10px 8px;text-align:center">
            <div style="font-size:22pt;font-weight:bold;color:#2d5a0e;line-height:1">{$cumple}</div>
            <div style="font-size:8pt;color:#3B6D11;margin-top:4px;font-weight:bold">CUMPLEN</div>
          </td>
        </tr>
      </table>
    </td>
    <td style="width:33%;padding:0 0 0 4px">
      <table style="width:100%;border-collapse:collapse">
        <tr>
          <td style="background:#fad7d7;border:2px solid #A32D2D;padding:10px 8px;text-align:center">
            <div style="font-size:22pt;font-weight:bold;color:#8b1a1a;line-height:1">{$noCumple}</div>
            <div style="font-size:8pt;color:#A32D2D;margin-top:4px;font-weight:bold">NO CUMPLEN</div>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>

<!-- ══════════════════════════════════════════════════
     OBSERVACIONES
══════════════════════════════════════════════════ -->
<table style="width:100%;border-collapse:collapse;margin-bottom:14px">
  <tr>
    <td style="background:#f4f7fb;border-left:4px solid #185FA5;padding:9px 14px;font-size:9pt;color:#3a3a50;font-style:italic;line-height:1.65">
      El presente informe integra los resultados de la inspección visual, dimensional y funcional de los accesorios de izaje indicados.
      Los criterios de evaluación se fundamentan en las normas aplicables. Los accesorios clasificados como
      <strong style="color:#2d5a0e">CUMPLE</strong> se encuentran en condiciones seguras de operación;
      los clasificados como <strong style="color:#8b1a1a">NO CUMPLE</strong> presentan deficiencias que impiden su uso y requieren atención inmediata.
    </td>
  </tr>
</table>

<!-- ══════════════════════════════════════════════════
     TABLA DE ACCESORIOS
══════════════════════════════════════════════════ -->
<table style="width:100%;border-collapse:collapse;margin-bottom:4px">
  <tr>
    <td style="background:#0C2D6B;color:#fff;font-size:8pt;font-weight:bold;padding:4px 10px;letter-spacing:0.05em">REGISTRO DE ACCESORIOS INSPECCIONADOS</td>
  </tr>
</table>
<table style="width:100%;border-collapse:collapse;margin-bottom:12px">
  <thead>
    <tr style="background:#185FA5">
      <th style="color:#fff;padding:6px 6px;font-size:8pt;border:1px solid #0C447C;text-align:center;width:8%">ID</th>
      <th style="color:#fff;padding:6px 6px;font-size:8pt;border:1px solid #0C447C;text-align:left;width:15%">Tipo</th>
      <th style="color:#fff;padding:6px 6px;font-size:8pt;border:1px solid #0C447C;text-align:left;width:12%">Marca</th>
      <th style="color:#fff;padding:6px 6px;font-size:8pt;border:1px solid #0C447C;text-align:left;width:11%">Modelo</th>
      <th style="color:#fff;padding:6px 6px;font-size:8pt;border:1px solid #0C447C;text-align:center;width:14%">No. Serie</th>
      <th style="color:#fff;padding:6px 6px;font-size:8pt;border:1px solid #0C447C;text-align:center;width:12%">Capacidad</th>
      <th style="color:#fff;padding:6px 6px;font-size:8pt;border:1px solid #0C447C;text-align:center;width:12%">Medidas</th>
      <th style="color:#fff;padding:6px 6px;font-size:8pt;border:1px solid #0C447C;text-align:center;width:16%">Estado</th>
    </tr>
  </thead>
  <tbody>
    {$filas}
  </tbody>
</table>

<!-- Leyenda -->
<table style="width:100%;border-collapse:collapse;margin-bottom:20px">
  <tr>
    <td style="font-size:7.5pt;color:#5a6072;padding:4px 0">
      <strong>Leyenda:</strong>&nbsp;
      <span style="background:#d4edba;color:#2d5a0e;font-weight:bold;padding:1px 6px;border:1px solid #3B6D11">&nbsp;CUMPLE&nbsp;</span>
      &nbsp;Accesorio en condiciones seguras de operación.&nbsp;&nbsp;&nbsp;
      <span style="background:#fad7d7;color:#8b1a1a;font-weight:bold;padding:1px 6px;border:1px solid #A32D2D">&nbsp;NO CUMPLE&nbsp;</span>
      &nbsp;Deficiencias que impiden su uso seguro.
    </td>
  </tr>
</table>

<!-- ══════════════════════════════════════════════════
     FIRMA
══════════════════════════════════════════════════ -->
<table style="width:100%;border-collapse:collapse;margin-bottom:16px">
  <tr>
    <td style="width:50%;padding-right:16px;vertical-align:bottom;text-align:center">
      <table style="width:100%;border-collapse:collapse">
        <tr>
          <td style="text-align:center;padding-bottom:4px">{$firmaImg}</td>
        </tr>
        <tr>
          <td style="border-top:1.5px solid #1a1a2e;padding-top:5px;text-align:center;font-size:8.5pt;color:#1a1a2e;font-weight:bold">{$usuario}</td>
        </tr>
        <tr>
          <td style="text-align:center;font-size:7.5pt;color:#5a6072;padding-top:2px">Inspector responsable</td>
        </tr>
      </table>
    </td>
    <td style="width:50%;padding-left:16px;vertical-align:bottom;text-align:center">
    </td>
  </tr>
</table>

<!-- ══════════════════════════════════════════════════
     PIE DE PÁGINA
══════════════════════════════════════════════════ -->
<table style="width:100%;border-collapse:collapse;border-top:2px solid #0C2D6B;margin-top:8px">
  <tr>
    <td style="padding-top:6px;font-size:7pt;color:#9299a8;vertical-align:middle">
      AVBA Inspections, Certifications and Maintenance S.A.S. de C.V.&nbsp;&nbsp;·&nbsp;&nbsp;Generado: {$hoyHi}
    </td>
    <td style="padding-top:6px;font-size:7pt;color:#9299a8;text-align:right;vertical-align:middle">
      Folio: <strong>{$folio}</strong>
    </td>
  </tr>
</table>

</body>
</html>
HTML;
    }

    // ── Eliminar sesión de accesorios y liberar QR ─────────
    public function eliminarSesionAcc(int $id): array {
        $row = $this->pdo->prepare("SELECT qr_codigo FROM accesorios_sesiones WHERE id = ?");
        $row->execute([$id]);
        $sesion = $row->fetch();
        if (!$sesion) return ['status' => 'error', 'message' => 'Sesión no encontrada.'];

        try {
            if (!empty($sesion['qr_codigo'])) {
                $this->pdo->prepare(
                    "UPDATE qr_codigos SET usado = 0, equipo_id = NULL WHERE identificador = ?"
                )->execute([$sesion['qr_codigo']]);
            }
            // Eliminar accesorios relacionados primero (FK sin CASCADE)
            $this->pdo->prepare("DELETE FROM accesorios_izaje WHERE sesion_id = ?")->execute([$id]);
            $this->pdo->prepare("DELETE FROM accesorios_sesiones WHERE id = ?")->execute([$id]);
            return ['status' => 'success', 'message' => 'Sesión eliminada correctamente.'];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
}

