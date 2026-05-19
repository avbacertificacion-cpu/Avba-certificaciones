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
            strtoupper(trim($post['id_accesorio'] ?? '')),
            $tipoId,
            strtoupper(trim($post['marca']    ?? '')),
            strtoupper(trim($post['modelo']   ?? '')),
            strtoupper(trim($post['serie']    ?? '')),
            strtoupper(trim($post['capacidad']?? '')),
            strtoupper(trim($post['medidas']  ?? '')),
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
        $id        = (int)($payload['id']           ?? 0);
        $tipoId    = (int)($payload['tipo_id']       ?? 0) ?: null;
        $idAcc     = strtoupper(trim($payload['id_accesorio']   ?? ''));
        $marca     = strtoupper(trim($payload['marca']          ?? ''));
        $modelo    = strtoupper(trim($payload['modelo']         ?? ''));
        $serie     = strtoupper(trim($payload['serie']          ?? ''));
        $capacidad = strtoupper(trim($payload['capacidad']      ?? ''));
        $medidas   = strtoupper(trim($payload['medidas']        ?? ''));
        $estado    = trim($payload['estado']         ?? '');

        if ($id <= 0) return ['status' => 'error', 'message' => 'id requerido.'];

        if ($estado && !in_array($estado, ['CUMPLE','NO CUMPLE'], true))
            return ['status' => 'error', 'message' => 'Estado no válido. Use CUMPLE o NO CUMPLE.'];

        $chk = $this->pdo->prepare("SELECT id FROM accesorios_izaje WHERE id = ?");
        $chk->execute([$id]);
        if (!$chk->fetch()) return ['status' => 'error', 'message' => 'Accesorio no encontrado.'];

        $this->pdo->prepare(
            "UPDATE accesorios_izaje
             SET tipo_id=?, id_accesorio=?, marca=?, modelo=?, serie=?, capacidad=?, medidas=?, estado=?
             WHERE id=?"
        )->execute([$tipoId, $idAcc, $marca, $modelo, $serie, $capacidad, $medidas, $estado, $id]);

        return ['status' => 'success', 'message' => 'Accesorio actualizado.'];
    }

    // ── Aprobar sesión → APROBADO_CALIDAD ────────────────
    public function aprobarSesion(int $id, string $usuario, string $qr): array {
        $this->ensureAccSesionesColumns();
        $chk = $this->pdo->prepare("SELECT id, cliente, control FROM accesorios_sesiones WHERE id = ?");
        $chk->execute([$id]);
        $sesion = $chk->fetch();
        if (!$sesion) return ['status' => 'error', 'message' => 'Sesión no encontrada.'];

        if (!$qr) return ['status' => 'error', 'message' => 'El código QR es requerido.'];

        // Validar QR existe y no está usado
        $stmtQR = $this->pdo->prepare("SELECT id, usado FROM qr_codigos WHERE identificador = ?");
        $stmtQR->execute([$qr]);
        $qrRow = $stmtQR->fetch();
        if (!$qrRow)        return ['status' => 'error', 'message' => 'Código QR no válido.'];
        if ($qrRow['usado']) return ['status' => 'error', 'message' => 'Código QR ya está en uso.'];

        // Generar control si la sesión no lo tiene (registros previos a migration_009)
        if (empty($sesion['control'])) {
            $control = generarControl($this->pdo, $sesion['cliente']);
            $this->pdo->prepare("UPDATE accesorios_sesiones SET control = ? WHERE id = ?")
                ->execute([$control, $id]);
        }

        $this->pdo->prepare("UPDATE accesorios_sesiones SET estatus = 'APROBADO_CALIDAD', qr_codigo = ? WHERE id = ?")
            ->execute([$qr, $id]);

        // Marcar QR como usado
        $this->pdo->prepare("UPDATE qr_codigos SET usado = 1 WHERE id = ?")
            ->execute([$qrRow['id']]);

        return ['status' => 'success', 'message' => 'Sesión aprobada y enviada a Certificaciones.'];
    }

    // ── Devolver sesión → DEVUELTO ─────────────────────
    public function devolverSesion(int $id, string $usuario): array {
        $this->ensureEstatusColumn('accesorios_sesiones');
        $chk = $this->pdo->prepare("SELECT id, qr_codigo FROM accesorios_sesiones WHERE id = ?");
        $chk->execute([$id]);
        $row = $chk->fetch();
        if (!$row) return ['status' => 'error', 'message' => 'Sesión no encontrada.'];

        // Liberar QR para que pueda ser reasignado en calidad
        $qrAnterior = $row['qr_codigo'] ?? '';
        if ($qrAnterior) {
            $this->pdo->prepare(
                "UPDATE qr_codigos SET usado = 0, equipo_id = NULL WHERE identificador = ?"
            )->execute([$qrAnterior]);
        }

        $this->pdo->prepare(
            "UPDATE accesorios_sesiones SET estatus = 'DEVUELTO', qr_codigo = NULL WHERE id = ?"
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
        $informeUrl = rtrim(UPLOAD_URL, '/') . '/' . ltrim($resultado['url'], '/');
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
        file_put_contents($dir . $nombre, $pdf->output());

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
                $qrUrl = 'https://quickchart.io/qr?text=' . urlencode(rtrim(SITE_URL, '/') . '/validar.html?qr=PREVIEW') . '&size=300';
                $qrTmp = sys_get_temp_dir() . '/avba_qr_prev_acc.png';
                $qrData = @file_get_contents($qrUrl);
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
        $pdf->Output('F', $dir . $nombre);
        return ['status' => 'success', 'url' => 'uploads/reportes/' . $nombre];

        } catch (\Exception $e) {
            $comp = fpdiMsgCompresion($e);
            return ['status' => 'error', 'message' => $comp ?? ('Error generando vista previa: ' . $e->getMessage())];
        }
    }

    private function ensureAccSesionesColumns(): void {
        $needed = [
            'estatus'    => "ALTER TABLE accesorios_sesiones ADD COLUMN estatus    VARCHAR(30)  NOT NULL DEFAULT 'PENDIENTE'",
            'qr_codigo'  => "ALTER TABLE accesorios_sesiones ADD COLUMN qr_codigo  VARCHAR(20)  NULL",
            'direccion'  => "ALTER TABLE accesorios_sesiones ADD COLUMN direccion  VARCHAR(500) NULL",
            'cert_url'   => "ALTER TABLE accesorios_sesiones ADD COLUMN cert_url    VARCHAR(500) NULL",
            'informe_url' => "ALTER TABLE accesorios_sesiones ADD COLUMN informe_url VARCHAR(500) NULL",
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

        if (!class_exists('Dompdf\Dompdf')) {
            return ['status' => 'error', 'message' => 'Motor PDF no disponible en el servidor.'];
        }

        $folio = $sesion['control']
            ? 'AB.' . $sesion['control'] . '-' . date('Y') . 'MX'
            : 'ACC-' . str_pad((string)$sesionId, 5, '0', STR_PAD_LEFT);
        $html  = $this->htmlInforme($sesion, $folio);

        $opts = new \Dompdf\Options();
        $opts->setIsRemoteEnabled(true);
        $opts->setIsHtml5ParserEnabled(true);
        $opts->setDefaultMediaType('print');

        $pdf = new \Dompdf\Dompdf($opts);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4', 'portrait');
        $pdf->render();

        $dir = __DIR__ . '/../uploads/reportes/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $nombre  = $folio . '_' . date('Ymd_His') . '.pdf';
        $rutaAbs = $dir . $nombre;
        file_put_contents($rutaAbs, $pdf->output());

        return [
            'status' => 'success',
            'url'    => 'uploads/reportes/' . $nombre,
            'folio'  => $folio,
        ];
    }

    // ── Generar certificado FPDI (una página por accesorio) ──
    public function generarCertAcc(int $sesionId, string $usuario): array {
        $this->ensurePlantillaAccTable();
        $row = $this->pdo->query("SELECT plantilla_pdf, pdf_campos FROM acc_plantilla_informe WHERE id = 1")->fetch();
        $rutaTpl = $row['plantilla_pdf']
            ? __DIR__ . '/../uploads/plantillas/' . $row['plantilla_pdf']
            : null;

        if (!$rutaTpl || !file_exists($rutaTpl))
            return ['status' => 'error', 'message' => 'No hay plantilla PDF configurada para el certificado.'];

        $campos = json_decode($row['pdf_campos'] ?? '[]', true) ?: [];
        if (!$campos)
            return ['status' => 'error', 'message' => 'La plantilla no tiene campos configurados. Configúralos en Calidad.'];

        if (!class_exists('setasign\Fpdi\Fpdi')) {
            $loader = __DIR__ . '/../lib/fpdi_loader.php';
            if (file_exists($loader)) require_once $loader;
        }
        if (!class_exists('setasign\Fpdi\Fpdi'))
            return ['status' => 'error', 'message' => 'Librería FPDI no disponible en el servidor.'];

        $det = $this->detalleSesion($sesionId);
        if ($det['status'] !== 'success') return $det;
        $sesion = $det['data'];

        $accs = $sesion['accesorios'] ?? [];
        if (!$accs)
            return ['status' => 'error', 'message' => 'Esta sesión no tiene accesorios registrados.'];

        $folio = $sesion['control']
            ? 'AB.' . $sesion['control'] . '-' . date('Y') . 'MX'
            : 'ACC-' . str_pad((string)$sesionId, 5, '0', STR_PAD_LEFT);

        // Detect page size from template
        $fpiDim = new \setasign\Fpdi\Fpdi();
        $fpiDim->setSourceFile($rutaTpl);
        $tplIdx = $fpiDim->importPage(1);
        $sz     = $fpiDim->getTemplateSize($tplIdx);
        $orient = ($sz['width'] > $sz['height']) ? 'L' : 'P';
        unset($fpiDim);

        $pdf = new \setasign\Fpdi\Fpdi($orient, 'mm', [$sz['width'], $sz['height']]);
        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->setSourceFile($rutaTpl);

        foreach ($accs as $i => $a) {
            $tpl = $pdf->importPage(1);
            $tSz = $pdf->getTemplateSize($tpl);
            $pdf->AddPage(($tSz['width'] > $tSz['height']) ? 'L' : 'P', [$tSz['width'], $tSz['height']]);
            $pdf->useTemplate($tpl);

            $valores = [
                'id_accesorio'     => $a['id_accesorio'] ?? '',
                'tipo'             => $a['tipo_nombre']  ?? '',
                'marca'            => $a['marca']        ?? '',
                'modelo'           => $a['modelo']       ?? '',
                'serie'            => $a['serie']        ?? '',
                'capacidad'        => $a['capacidad']    ?? '',
                'medidas'          => $a['medidas']      ?? '',
                'estado'           => $a['estado']       ?? '',
                'cliente'          => $sesion['cliente'] ?? '',
                'fecha_inspeccion' => $sesion['fecha']   ?? '',
                'inspector'        => $sesion['usuario'] ?? '',
                'folio'            => $folio . '-' . str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT),
                'total_accesorios' => count($accs) . ' ACCESORIOS INSPECCIONADOS',
                'lugar_inspeccion' => $sesion['direccion'] ?? '',
            ];

            // Firma del inspector
            $firmaRuta = '';
            $inspectorUsuario = $sesion['usuario'] ?? '';
            if ($inspectorUsuario) {
                try {
                    $st = $this->pdo->prepare("SELECT firma_imagen FROM usuarios WHERE usuario = ? LIMIT 1");
                    $st->execute([$inspectorUsuario]);
                    $row = $st->fetch();
                    if (!empty($row['firma_imagen'])) $firmaRuta = __DIR__ . '/../' . $row['firma_imagen'];
                } catch (\Exception $e) {}
            }

            $qrCodigo = $sesion['qr_codigo'] ?? '';

            foreach ($campos as $c) {
                $x     = (float)($c['x']     ?? 0);
                $y     = (float)($c['y']     ?? 0);
                $ancho = (float)($c['ancho'] ?? 0);
                if ($c['campo'] === 'firma_inspector') {
                    if ($firmaRuta && file_exists($firmaRuta)) {
                        $alto = (float)($c['alto'] ?? ($ancho ?: 20));
                        $pdf->Image($firmaRuta, $x, $y, $ancho ?: 40, $alto);
                    }
                    continue;
                }
                if ($c['campo'] === 'qr_imagen') {
                    if ($qrCodigo) {
                        $qrUrl = 'https://quickchart.io/qr?text=' . urlencode(rtrim(SITE_URL, '/') . '/validar.html?qr=' . urlencode($qrCodigo)) . '&size=300';
                        $qrTmp = sys_get_temp_dir() . '/avba_qr_acc_' . uniqid() . '.png';
                        $qrData = @file_get_contents($qrUrl);
                        if ($qrData) {
                            file_put_contents($qrTmp, $qrData);
                            $alto = (float)($c['alto'] ?? ($ancho ?: 25));
                            $pdf->Image($qrTmp, $x, $y, $ancho ?: 25, $alto);
                            @unlink($qrTmp);
                        }
                    }
                    continue;
                }
                $val   = $valores[$c['campo']] ?? '';
                $color = str_pad(ltrim($c['color'] ?? '000000', '#'), 6, '0', STR_PAD_LEFT);
                [$r, $g, $b] = sscanf($color, '%02x%02x%02x');
                $pdf->SetTextColor($r ?? 0, $g ?? 0, $b ?? 0);
                $pdf->SetFont($c['fuente'] ?? 'Helvetica', ($c['negrita'] ?? false) ? 'B' : '', $c['tamano'] ?? 11);
                pdfCell($pdf, $x, $y, $ancho, (int)($c['tamano'] ?? 11), fpdfStr((string)$val));
            }
        }

        $dir = __DIR__ . '/../uploads/reportes/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $nombre = $folio . '_CERT_' . date('Ymd_His') . '.pdf';
        $pdf->Output('F', $dir . $nombre);

        return ['status' => 'success', 'url' => 'uploads/reportes/' . $nombre, 'folio' => $folio];
    }

    // ── Emitir certificado FPDI → genera PDF + EMITIDO ──────
    public function emitirCertAcc(int $sesionId, string $usuario): array {
        $resultado = $this->generarCertAcc($sesionId, $usuario);
        if ($resultado['status'] !== 'success') return $resultado;
        $this->pdo->prepare("UPDATE accesorios_sesiones SET estatus = 'EMITIDO' WHERE id = ?")
            ->execute([$sesionId]);
        $resultado['message'] = 'Certificado emitido correctamente.';
        // Save URL so portal can show the link
        $certUrl = rtrim(UPLOAD_URL, '/') . '/' . ltrim($resultado['url'], '/');
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

        $opts = new \Dompdf\Options();
        $opts->setIsRemoteEnabled(true);
        $opts->setIsHtml5ParserEnabled(true);

        $pdf = new \Dompdf\Dompdf($opts);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4', 'portrait');
        $pdf->render();

        $dir = __DIR__ . '/../uploads/reportes/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $nombre = $folio . '_' . date('Ymd_His') . '.pdf';
        file_put_contents($dir . $nombre, $pdf->output());

        return ['status' => 'success', 'url' => 'uploads/reportes/' . $nombre, 'folio' => $folio];
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
            return ['status' => 'success', 'message' => "Informe enviado a {$correo}."];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => 'Error al enviar: ' . $e->getMessage()];
        }
    }

    // ── HTML del Informe de Integridad Operativa ───────────
    private function htmlInforme(array $s, string $folio): string {
        $esc = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');

        $cliente = $esc($s['cliente']);
        $dir     = $esc($s['direccion'] ?? '');
        $fecha   = $esc($s['fecha']);
        $usuario = $esc($s['usuario']);
        $accs    = $s['accesorios'] ?? [];

        // ── Resumen de estados (solo CUMPLE / NO CUMPLE) ──
        $total    = count($accs);
        $cumple   = count(array_filter($accs, fn($a) => strtoupper($a['estado']) === 'CUMPLE'));
        $noCumple = $total - $cumple;

        // ── Filas de la tabla ─────────────────────────────
        $filas = '';
        foreach ($accs as $i => $a) {
            $bg  = ($i % 2 === 0) ? '#ffffff' : '#f4f7fb';
            $est = strtoupper($a['estado'] ?? '');
            if ($est === 'CUMPLE') {
                $estBg = '#EAF3DE'; $estColor = '#3B6D11'; $estBd = '#3B6D11';
            } else {
                $estBg = '#FCEBEB'; $estColor = '#A32D2D'; $estBd = '#A32D2D';
            }
            $filas .= '
            <tr style="background:' . $bg . '">
              <td style="padding:6px 8px;border:1px solid #dfe5ef;text-align:center;font-size:9pt">' . $esc($a['id_accesorio']) . '</td>
              <td style="padding:6px 8px;border:1px solid #dfe5ef;font-size:9pt">' . $esc($a['tipo_nombre'] ?? '') . '</td>
              <td style="padding:6px 8px;border:1px solid #dfe5ef;font-size:9pt">' . $esc($a['marca'] ?? '') . '</td>
              <td style="padding:6px 8px;border:1px solid #dfe5ef;font-size:9pt">' . $esc($a['modelo'] ?? '') . '</td>
              <td style="padding:6px 8px;border:1px solid #dfe5ef;font-size:9pt;font-family:monospace">' . $esc($a['serie'] ?? '') . '</td>
              <td style="padding:6px 8px;border:1px solid #dfe5ef;text-align:center;font-size:9pt">' . $esc($a['capacidad'] ?? '') . '</td>
              <td style="padding:6px 8px;border:1px solid #dfe5ef;text-align:center;font-size:9pt">' . $esc($a['medidas'] ?? '') . '</td>
              <td style="padding:4px 6px;border:1px solid #dfe5ef;text-align:center">
                <span style="display:inline-block;padding:3px 8px;border-radius:10px;
                             background:' . $estBg . ';color:' . $estColor . ';
                             border:1px solid ' . $estBd . ';font-size:8pt;font-weight:bold;white-space:nowrap">
                  ' . $esc($a['estado'] ?? '') . '
                </span>
              </td>
            </tr>';
        }

        if (!$filas) {
            $filas = '<tr><td colspan="8" style="padding:20px;text-align:center;color:#9299a8;font-size:9pt;border:1px solid #dfe5ef">Sin accesorios registrados en esta sesión.</td></tr>';
        }

        // ── Logo AVBA (base64 para Dompdf) ─────────────────
        $logoPath = __DIR__ . '/../icon-192.png';
        $logoTag  = '';
        if (file_exists($logoPath)) {
            $b64     = base64_encode(file_get_contents($logoPath));
            $logoTag = '<img src="data:image/png;base64,' . $b64 . '" style="height:52px;width:auto">';
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 10pt; color: #1a1a2e; background: #fff; padding: 28px 32px; }
  .page-break { page-break-before: always; }
  .divider { border: none; border-top: 3px solid #185FA5; margin: 14px 0; }
  .info-table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
  .info-cell { padding: 7px 12px; border: 1px solid #ccd9ee; background: #E6F1FB; vertical-align: top; }
  .info-label { font-size: 8.5pt; font-weight: bold; color: #0C447C; display: block; margin-bottom: 2px; }
  .info-value { font-size: 10pt; color: #1a1a2e; }
  .intro-text { font-size: 9.5pt; color: #3a3a50; line-height: 1.7; text-align: justify;
                font-style: italic; margin: 14px 0 18px; padding: 12px 16px;
                border-left: 4px solid #185FA5; background: #f4f7fb; }
  .acc-table { width: 100%; border-collapse: collapse; margin-top: 8px; }
  .acc-table th { background: #185FA5; color: #ffffff; padding: 7px 8px;
                  font-size: 8.5pt; font-weight: bold; text-align: center;
                  border: 1px solid #0C447C; }
  .acc-table th.left { text-align: left; }
  .badge { display: inline-block; padding: 2px 8px; border-radius: 8px;
           font-size: 8pt; font-weight: bold; border: 1px solid; margin-right: 8px; }
  .firma-table { width: 100%; border-collapse: collapse; margin-top: 40px; }
  .firma-cell { text-align: center; padding: 0 24px; vertical-align: bottom; width: 50%; }
  .firma-line { border-top: 1.5px solid #1a1a2e; padding-top: 6px; margin-top: 52px; font-size: 8.5pt; color: #5a6072; }
  .firma-sub { font-size: 7.5pt; color: #9299a8; margin-top: 2px; }
  .footer-strip { margin-top: 24px; padding-top: 8px; border-top: 1px solid #dfe5ef;
                  display: table; width: 100%; }
  .footer-l { display: table-cell; font-size: 7.5pt; color: #9299a8; vertical-align: middle; }
  .footer-r { display: table-cell; font-size: 7.5pt; color: #9299a8; text-align: right; vertical-align: middle; }
</style>
</head>
<body>

<!-- ── ENCABEZADO ── -->
<table style="width:100%;border-collapse:collapse;margin-bottom:18px">
  <tr>
    <td style="width:140px;vertical-align:middle">{$logoTag}</td>
    <td style="vertical-align:middle;text-align:center;padding:0 12px">
      <div style="font-size:17pt;font-weight:bold;color:#1a1a2e;margin-bottom:4px">Informe de Integridad Operativa</div>
      <div style="font-size:9pt;color:#5a6072;font-style:italic">Evaluación de la condición, seguridad y confiabilidad de accesorios de izaje</div>
    </td>
    <td style="width:170px;vertical-align:top;text-align:right">
      <div style="background:#185FA5;color:#fff;padding:5px 10px;border-radius:5px;font-size:8pt;font-weight:bold;white-space:nowrap;display:inline-block">{$esc($folio)}</div>
      <div style="font-size:7.5pt;color:#9299a8;margin-top:4px">{$esc(date('d/m/Y'))}</div>
    </td>
  </tr>
</table>
<hr class="divider">

<!-- ── DATOS GENERALES ── -->
<table class="info-table">
  <tr>
    <td class="info-cell" style="width:60%">
      <span class="info-label">Cliente</span>
      <span class="info-value">{$cliente}</span>
    </td>
    <td style="width:8px"></td>
    <td class="info-cell" style="width:40%">
      <span class="info-label">Fecha de inspección</span>
      <span class="info-value">{$fecha}</span>
    </td>
  </tr>
  <tr><td colspan="3" style="height:5px"></td></tr>
  <tr>
    <td class="info-cell" style="width:60%">
      <span class="info-label">Domicilio</span>
      <span class="info-value">{$dir}</span>
    </td>
    <td style="width:8px"></td>
    <td class="info-cell" style="width:40%">
      <span class="info-label">Inspector</span>
      <span class="info-value">{$usuario}</span>
    </td>
  </tr>
</table>

<!-- ── TEXTO INTRODUCTORIO ── -->
<div class="intro-text">
  El presente informe integra una evaluación de los accesorios inspeccionados,
  permitiendo identificar niveles de criticidad y priorizar acciones correctivas de manera eficiente.
  Los resultados aquí descritos corresponden al estado físico y operativo observado en la fecha indicada.
</div>

<!-- ── RESUMEN ── -->
<table style="width:100%;border-collapse:collapse;margin-top:18px">
  <tr>
    <td style="width:33%;padding-right:8px">
      <div style="padding:10px 14px;border-radius:6px;text-align:center;background:#E6F1FB;border:1px solid #185FA5">
        <div style="font-size:20pt;font-weight:bold;color:#185FA5">{$total}</div>
        <div style="font-size:8pt;color:#5a6072;margin-top:2px">Total accesorios</div>
      </div>
    </td>
    <td style="width:33%;padding-right:8px">
      <div style="padding:10px 14px;border-radius:6px;text-align:center;background:#EAF3DE;border:1px solid #3B6D11">
        <div style="font-size:20pt;font-weight:bold;color:#3B6D11">{$cumple}</div>
        <div style="font-size:8pt;color:#3B6D11;margin-top:2px">Cumplen</div>
      </div>
    </td>
    <td style="width:33%">
      <div style="padding:10px 14px;border-radius:6px;text-align:center;background:#FCEBEB;border:1px solid #A32D2D">
        <div style="font-size:20pt;font-weight:bold;color:#A32D2D">{$noCumple}</div>
        <div style="font-size:8pt;color:#A32D2D;margin-top:2px">No cumplen</div>
      </div>
    </td>
  </tr>
</table>

<!-- ── TABLA DE ACCESORIOS ── -->
<div style="font-size:10pt;font-weight:bold;color:#185FA5;margin:18px 0 6px;
            text-transform:uppercase;letter-spacing:.05em;border-bottom:2px solid #185FA5;
            padding-bottom:4px">
  Registro de accesorios inspeccionados
</div>
<table class="acc-table">
  <thead>
    <tr>
      <th style="width:8%">ID</th>
      <th class="left" style="width:16%">Tipo</th>
      <th class="left" style="width:12%">Marca</th>
      <th class="left" style="width:12%">Modelo</th>
      <th style="width:14%">No. Serie</th>
      <th style="width:12%">Capacidad</th>
      <th style="width:12%">Medidas</th>
      <th style="width:14%">Estado</th>
    </tr>
  </thead>
  <tbody>
    {$filas}
  </tbody>
</table>

<!-- ── LEYENDA ── -->
<div style="margin-top:14px;font-size:8pt;color:#5a6072">
  <strong>Leyenda: </strong>
  <span class="badge" style="background:#EAF3DE;color:#3B6D11;border-color:#3B6D11">CUMPLE</span>
  El accesorio se encuentra en condiciones seguras de operación. &nbsp;
  <span class="badge" style="background:#FCEBEB;color:#A32D2D;border-color:#A32D2D">NO CUMPLE</span>
  Fuera de servicio o con deficiencias que impiden su uso.
</div>

<!-- ── FIRMAS ── -->
<table class="firma-table">
  <tr>
    <td class="firma-cell">
      <div class="firma-line">Inspector responsable</div>
      <div class="firma-sub">{$usuario}</div>
    </td>
    <td class="firma-cell">
      <div class="firma-line">Recibió / Vo. Bo.</div>
      <div class="firma-sub">Representante del cliente</div>
    </td>
  </tr>
</table>

<!-- ── PIE DE PÁGINA ── -->
<div class="footer-strip">
  <div class="footer-l">AVBA Inspections, Certifications and Maintenance S.A.S. de C.V. &nbsp;·&nbsp; Generado el {$esc(date('d/m/Y H:i'))}</div>
  <div class="footer-r">Folio: {$esc($folio)}</div>
</div>

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

