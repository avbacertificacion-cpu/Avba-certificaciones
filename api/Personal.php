<?php
/**
 * AVBA Certificaciones — Módulo Personal / Cursos
 *
 * Gestión de participantes en cursos de capacitación.
 * Emisión de documentos: DC-3, Diplomas, Certificados.
 * Catálogos: cursos y ocupaciones específicas (gestionados por Calidad).
 */

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

use Dompdf\Dompdf;
use Dompdf\Options;
use PHPMailer\PHPMailer\PHPMailer;

class Personal {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    // ══════════════════════════════════════════════════════
    //  PARTICIPANTES
    // ══════════════════════════════════════════════════════

    public function listarParticipantes(array $filtros = []): array {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filtros['curso_id'])) {
            $where[]  = 'p.curso_id = ?';
            $params[] = (int)$filtros['curso_id'];
        }
        if (!empty($filtros['buscar'])) {
            $where[]  = '(p.nombre_completo LIKE ? OR p.curp LIKE ?)';
            $params[] = '%' . $filtros['buscar'] . '%';
            $params[] = '%' . $filtros['buscar'] . '%';
        }
        if (!empty($filtros['estatus'])) {
            if ($filtros['estatus'] === 'APROBADO_CALIDAD') {
                $where[]  = "p.estatus IN ('APROBADO_CALIDAD','EMITIDO')";
            } else {
                $where[]  = 'p.estatus = ?';
                $params[] = $filtros['estatus'];
            }
        }
        if (!empty($filtros['inspector'])) {
            $where[]  = 'p.usuario_registro = ?';
            $params[] = $filtros['inspector'];
        }

        $sql = "SELECT p.id, p.nombre_completo, p.curp, p.puesto,
                       p.telefono, p.correo, p.capacidad, p.capacidad_na,
                       p.control, p.estatus, p.fecha_curso, p.estado,
                       c.nombre AS curso_nombre, c.duracion_horas,
                       o.nombre AS ocupacion_nombre,
                       p.foto_documentacion_url, p.foto_persona_url,
                       p.empresa_nombre, p.fecha_registro
                FROM participantes_cursos p
                LEFT JOIN cursos c ON c.id = p.curso_id
                LEFT JOIN ocupaciones_especificas o ON o.id = p.ocupacion_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY p.fecha_registro DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function obtenerParticipante(int $id): ?array {
        $stmt = $this->pdo->prepare(
            "SELECT p.*,
                    c.nombre AS curso_nombre, c.duracion_horas, c.area_tematica,
                    o.nombre AS ocupacion_nombre
             FROM participantes_cursos p
             LEFT JOIN cursos c ON c.id = p.curso_id
             LEFT JOIN ocupaciones_especificas o ON o.id = p.ocupacion_id
             WHERE p.id = ?"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) return null;

        // Documentos generados
        $stmt2 = $this->pdo->prepare(
            "SELECT tipo_doc, url, DATE_FORMAT(fecha_generacion,'%d/%m/%Y') AS fecha
             FROM participantes_documentos WHERE participante_id = ? ORDER BY fecha_generacion DESC"
        );
        $stmt2->execute([$id]);
        $row['documentos'] = $stmt2->fetchAll();

        return $row;
    }

    public function guardarParticipante(array $payload, array $files, string $usuario): array {
        $id      = (int)($payload['id'] ?? 0);
        $cursoId = (int)($payload['curso_id'] ?? 0);

        if (!$cursoId)
            return ['status' => 'error', 'message' => 'Selecciona un curso.'];
        if (!trim($payload['fecha_curso'] ?? ''))
            return ['status' => 'error', 'message' => 'La fecha del curso es obligatoria.'];

        // Normalizar CURP si se proporcionó
        $curpRaw = strtoupper(trim($payload['curp'] ?? ''));
        $curp    = $curpRaw ?: null;
        if ($curp) {
            $curpCheck = validarCURPCompleta($curp);
            if (!$curpCheck['valida']) return ['status' => 'error', 'message' => $curpCheck['error']];
        }

        // Subir fotografías si se enviaron
        $fotoDocUrl    = $payload['foto_documentacion_url']    ?? null;
        $fotoPersonaUrl = $payload['foto_persona_url']         ?? null;

        if (!empty($files['foto_documentacion']['tmp_name'])) {
            $r = $this->subirFoto($files['foto_documentacion'], 'personal/docs');
            if ($r['status'] !== 'success')
                return ['status' => 'error', 'message' => 'Error al subir foto de documentación: ' . $r['message']];
            $fotoDocUrl = $r['url'];
        }
        if (!empty($files['foto_persona']['tmp_name'])) {
            $r = $this->subirFoto($files['foto_persona'], 'personal/fotos');
            if ($r['status'] !== 'success')
                return ['status' => 'error', 'message' => 'Error al subir foto de persona: ' . $r['message']];
            $fotoPersonaUrl = $r['url'];
        }

        $u = fn($v) => strtoupper(trim($v ?? '')) ?: null;

        $campos = [
            'nombre_completo'       => $u($payload['nombre_completo']    ?? ''),
            'curp'                  => $curp,
            'puesto'                => $u($payload['puesto']             ?? ''),
            'ocupacion_id'          => ($payload['ocupacion_id'] ?? 0) ?: null,
            'capacidad'             => $u($payload['capacidad']          ?? ''),
            'capacidad_na'          => !empty($payload['capacidad_na']) ? 1 : 0,
            'telefono'              => trim($payload['telefono'] ?? '') ?: null,
            'correo'                => strtolower(trim($payload['correo'] ?? '')) ?: null,
            'empresa_nombre'        => $u($payload['empresa_nombre']        ?? ''),
            'empresa_rfc'           => strtoupper(trim($payload['empresa_rfc'] ?? '')) ?: null,
            'empresa_direccion'     => $u($payload['empresa_direccion']     ?? ''),
            'empresa_representante' => $u($payload['empresa_representante'] ?? ''),
            'curso_id'              => $cursoId,
            'fecha_curso'           => $this->parseFecha($payload['fecha_curso'] ?? ''),
            'foto_documentacion_url'=> $fotoDocUrl,
            'foto_persona_url'      => $fotoPersonaUrl,
        ];

        if ($id) {
            // Actualizar
            $sets   = array_map(fn($k) => "`{$k}` = ?", array_keys($campos));
            $values = array_values($campos);
            $values[] = $id;
            $this->pdo->prepare("UPDATE participantes_cursos SET " . implode(', ', $sets) . " WHERE id = ?")
                      ->execute($values);
        } else {
            // Insertar — asignar número de control
            $clienteNombre = $campos['empresa_nombre'] ?? $campos['nombre_completo'];
            $campos['control']          = generarControl($this->pdo, $clienteNombre);
            $campos['usuario_registro'] = $usuario;
            $cols   = implode(', ', array_map(fn($k) => "`{$k}`", array_keys($campos)));
            $marks  = implode(', ', array_fill(0, count($campos), '?'));
            $this->pdo->prepare("INSERT INTO participantes_cursos ({$cols}) VALUES ({$marks})")
                      ->execute(array_values($campos));
            $id = (int)$this->pdo->lastInsertId();
        }

        $control = $campos['control'] ?? null;
        if (!$control) {
            $rowCtrl = $this->pdo->prepare("SELECT control FROM participantes_cursos WHERE id = ?");
            $rowCtrl->execute([$id]);
            $control = $rowCtrl->fetchColumn() ?: null;
        }
        return ['status' => 'success', 'id' => $id, 'control' => $control];
    }

    public function eliminarParticipante(int $id): array {
        $row = $this->pdo->prepare("SELECT qr_codigo FROM participantes_cursos WHERE id = ?");
        $row->execute([$id]);
        $p = $row->fetch();
        if (!empty($p['qr_codigo'])) {
            $this->pdo->prepare(
                "UPDATE qr_codigos SET usado = 0, equipo_id = NULL WHERE identificador = ?"
            )->execute([$p['qr_codigo']]);
        }
        $this->pdo->prepare("DELETE FROM participantes_cursos WHERE id = ?")->execute([$id]);
        return ['status' => 'success', 'message' => 'Participante eliminado correctamente.'];
    }

    // ── Aprobar participante → APROBADO_CALIDAD ────────────
    public function aprobarParticipante(int $id, string $usuario, string $qr = ''): array {
        $this->ensureEstatusColumn();
        // Garantizar columna qr_codigo
        try {
            $this->pdo->exec("ALTER TABLE participantes_cursos ADD COLUMN IF NOT EXISTS qr_codigo VARCHAR(20) NULL");
        } catch (\Throwable $e) {}

        $chk = $this->pdo->prepare("SELECT id, nombre_completo, empresa_nombre, control FROM participantes_cursos WHERE id = ?");
        $chk->execute([$id]);
        $p = $chk->fetch();
        if (!$p) return ['status' => 'error', 'message' => 'Participante no encontrado.'];

        // Auto-asignar el siguiente QR disponible
        if (!$qr) {
            $qrRow = $this->pdo->query(
                "SELECT id, identificador FROM qr_codigos WHERE usado = 0 ORDER BY CAST(identificador AS UNSIGNED) LIMIT 1"
            )->fetch();
            if (!$qrRow) return ['status' => 'error', 'message' => 'Sin QR disponibles. Genera un lote en Códigos QR.'];
            $qr = $qrRow['identificador'];
        } else {
            $stmtQR = $this->pdo->prepare("SELECT id, usado FROM qr_codigos WHERE identificador = ?");
            $stmtQR->execute([$qr]);
            $qrRow = $stmtQR->fetch();
            if (!$qrRow)         return ['status' => 'error', 'message' => 'Código QR no válido.'];
            if ($qrRow['usado']) return ['status' => 'error', 'message' => 'Código QR ya está en uso.'];
        }

        // Generar control si el participante no lo tiene (registros previos a migration_009)
        if (empty($p['control'])) {
            $clienteNombre = $p['empresa_nombre'] ?: $p['nombre_completo'];
            $control = generarControl($this->pdo, $clienteNombre);
            $this->pdo->prepare("UPDATE participantes_cursos SET control = ? WHERE id = ?")
                ->execute([$control, $id]);
        }

        $this->pdo->prepare("UPDATE participantes_cursos SET estatus = 'APROBADO_CALIDAD', qr_codigo = ? WHERE id = ?")
            ->execute([$qr, $id]);

        // Marcar QR como usado
        $this->pdo->prepare("UPDATE qr_codigos SET usado = 1 WHERE id = ?")
            ->execute([$qrRow['id']]);

        return [
            'status'  => 'success',
            'message' => 'Participante aprobado y QR asignado automáticamente.',
            'qr'      => $qr,
        ];
    }

    // ── Devolver participante → DEVUELTO ──────────────────
    public function devolverParticipante(int $id, string $usuario): array {
        $this->ensureEstatusColumn();
        $this->ensureQrColumn();
        $chk = $this->pdo->prepare("SELECT id, qr_codigo FROM participantes_cursos WHERE id = ?");
        $chk->execute([$id]);
        $row = $chk->fetch();
        if (!$row) return ['status' => 'error', 'message' => 'Participante no encontrado.'];

        // Liberar QR para que pueda ser reasignado en calidad
        $qrAnterior = $row['qr_codigo'] ?? '';
        if ($qrAnterior) {
            $this->pdo->prepare(
                "UPDATE qr_codigos SET usado = 0, equipo_id = NULL WHERE identificador = ?"
            )->execute([$qrAnterior]);
        }

        $this->pdo->prepare(
            "UPDATE participantes_cursos SET estatus = 'DEVUELTO', qr_codigo = NULL WHERE id = ?"
        )->execute([$id]);
        return ['status' => 'success', 'message' => 'Participante devuelto a Calidad.'];
    }

    // ── Actualizar datos de empresa (para DC3) ────────────────
    public function actualizarEmpresa(array $payload, string $usuario): array {
        $id = (int)($payload['id'] ?? 0);
        if (!$id) return ['status' => 'error', 'message' => 'ID requerido.'];

        $chk = $this->pdo->prepare("SELECT id, empresa_nombre FROM participantes_cursos WHERE id = ?");
        $chk->execute([$id]);
        $part = $chk->fetch();
        if (!$part) return ['status' => 'error', 'message' => 'Participante no encontrado.'];

        // Garantizar columnas en clientes
        try {
            $this->pdo->exec("ALTER TABLE clientes
                ADD COLUMN IF NOT EXISTS rfc           VARCHAR(20)  DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS representante VARCHAR(200) DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS direccion     VARCHAR(300) DEFAULT NULL");
        } catch (\Throwable $e) {}

        $campos = ['empresa_nombre', 'empresa_rfc', 'empresa_representante', 'empresa_direccion'];
        $sets   = [];
        $params = [];
        foreach ($campos as $c) {
            if (array_key_exists($c, $payload)) {
                $sets[]   = "`{$c}` = ?";
                $params[] = $payload[$c] === '' ? null : trim($payload[$c]);
            }
        }
        if (empty($sets)) return ['status' => 'success', 'message' => 'Sin cambios.'];

        $params[] = $id;
        $this->pdo->prepare("UPDATE participantes_cursos SET " . implode(', ', $sets) . " WHERE id = ?")
            ->execute($params);

        // Persistir en catálogo de clientes
        $nombreEmpresa = $payload['empresa_nombre'] ?? $part['empresa_nombre'] ?? '';
        if ($nombreEmpresa) {
            $clienteRow = $this->pdo->prepare(
                "SELECT id FROM clientes WHERE UPPER(TRIM(nombre_cliente)) = UPPER(TRIM(?))"
            );
            $clienteRow->execute([$nombreEmpresa]);
            $cliente = $clienteRow->fetch();
            if ($cliente) {
                $cSets   = [];
                $cParams = [];
                if (array_key_exists('empresa_rfc', $payload)) {
                    $cSets[]   = 'rfc = ?';
                    $cParams[] = $payload['empresa_rfc'] === '' ? null : trim($payload['empresa_rfc']);
                }
                if (array_key_exists('empresa_representante', $payload)) {
                    $cSets[]   = 'representante = ?';
                    $cParams[] = $payload['empresa_representante'] === '' ? null : trim($payload['empresa_representante']);
                }
                if (array_key_exists('empresa_direccion', $payload)) {
                    $cSets[]   = 'direccion = ?';
                    $cParams[] = $payload['empresa_direccion'] === '' ? null : trim($payload['empresa_direccion']);
                }
                if (!empty($cSets)) {
                    $cParams[] = $cliente['id'];
                    $this->pdo->prepare("UPDATE clientes SET " . implode(', ', $cSets) . " WHERE id = ?")
                        ->execute($cParams);
                }
            }
        }

        return ['status' => 'success', 'message' => 'Datos de empresa actualizados.'];
    }

    // ── Actualizar nombre y CURP extraídos por OCR ────────────
    public function actualizarDatosOCR(array $payload): array {
        $id     = (int)($payload['id'] ?? 0);
        $nombre = trim($payload['nombre_completo'] ?? '');
        $curp   = strtoupper(trim($payload['curp'] ?? ''));

        if (!$id) return ['status' => 'error', 'message' => 'ID requerido.'];
        if (!$nombre && !$curp) return ['status' => 'error', 'message' => 'Se requiere al menos nombre o CURP.'];

        if ($curp) {
            $curpCheck = validarCURPCompleta($curp);
            if (!$curpCheck['valida']) return ['status' => 'error', 'message' => $curpCheck['error']];
        }

        $sets = []; $vals = [];
        if ($nombre) { $sets[] = 'nombre_completo = ?'; $vals[] = $nombre; }
        if ($curp)   { $sets[] = 'curp = ?';            $vals[] = $curp;   }
        $vals[] = $id;

        $this->pdo->prepare("UPDATE participantes_cursos SET " . implode(', ', $sets) . " WHERE id = ?")
                  ->execute($vals);

        return ['status' => 'success'];
    }

    // ── Emitir documento y marcar como EMITIDO ─────────────
    public function emitirDocumentoPersonal(int $id, string $tipo, string $correoDestino, string $usuario): array {
        $this->ensureEstatusColumn();
        $resultado = $this->generarDocumento($id, $tipo, $usuario);
        if ($resultado['status'] !== 'success') return $resultado;

        // Marcar como emitido
        $this->pdo->prepare("UPDATE participantes_cursos SET estatus = 'EMITIDO' WHERE id = ?")
            ->execute([$id]);

        // Enviar correo si se proporcionó dirección
        if ($correoDestino && filter_var($correoDestino, FILTER_VALIDATE_EMAIL)) {
            $this->enviarDocumento($id, $tipo, $correoDestino, $usuario);
        }

        return $resultado;
    }

    private function ensureEstatusColumn(): void {
        try {
            $this->pdo->exec("ALTER TABLE participantes_cursos ADD COLUMN IF NOT EXISTS estatus VARCHAR(30) NOT NULL DEFAULT 'PENDIENTE'");
        } catch (\PDOException $e) { /* column already exists */ }
    }

    private function ensureQrColumn(): void {
        try {
            $this->pdo->exec("ALTER TABLE participantes_cursos ADD COLUMN IF NOT EXISTS qr_codigo VARCHAR(20) NULL");
        } catch (\PDOException $e) { /* column already exists */ }
    }

    // ══════════════════════════════════════════════════════
    //  CURSOS
    // ══════════════════════════════════════════════════════

    public function listarCursos(): array {
        $stmt = $this->pdo->query(
            "SELECT id, nombre, duracion_horas, area_tematica, activo,
                    DATE_FORMAT(fecha_creacion,'%d/%m/%Y') AS fecha_creacion
             FROM cursos ORDER BY nombre"
        );
        return $stmt->fetchAll();
    }

    public function guardarCurso(array $payload, string $usuario): array {
        $id = (int)($payload['id'] ?? 0);

        if (!trim($payload['nombre'] ?? ''))
            return ['status' => 'error', 'message' => 'El nombre del curso es obligatorio.'];
        if (empty($payload['duracion_horas']) || (float)$payload['duracion_horas'] <= 0)
            return ['status' => 'error', 'message' => 'La duración en horas debe ser mayor a 0.'];
        if (!trim($payload['area_tematica'] ?? ''))
            return ['status' => 'error', 'message' => 'El área temática es obligatoria.'];

        if ($id) {
            $this->pdo->prepare(
                "UPDATE cursos SET nombre=?, duracion_horas=?, area_tematica=?, activo=? WHERE id=?"
            )->execute([
                trim($payload['nombre']),
                (float)$payload['duracion_horas'],
                trim($payload['area_tematica']),
                isset($payload['activo']) ? (int)(bool)$payload['activo'] : 1,
                $id,
            ]);
        } else {
            $this->pdo->prepare(
                "INSERT INTO cursos (nombre, duracion_horas, area_tematica, creado_por) VALUES (?,?,?,?)"
            )->execute([
                trim($payload['nombre']),
                (float)$payload['duracion_horas'],
                trim($payload['area_tematica']),
                $usuario,
            ]);
            $id = (int)$this->pdo->lastInsertId();
        }

        return ['status' => 'success', 'id' => $id];
    }

    public function eliminarCurso(int $id): array {
        // Verificar que no tenga participantes
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM participantes_cursos WHERE curso_id = ?");
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() > 0)
            return ['status' => 'error', 'message' => 'No se puede eliminar: el curso tiene participantes registrados.'];

        $this->pdo->prepare("DELETE FROM cursos WHERE id = ?")->execute([$id]);
        return ['status' => 'success'];
    }

    // ══════════════════════════════════════════════════════
    //  OCUPACIONES ESPECÍFICAS
    // ══════════════════════════════════════════════════════

    public function listarOcupaciones(): array {
        $stmt = $this->pdo->query(
            "SELECT id, nombre, activo FROM ocupaciones_especificas WHERE activo = 1 ORDER BY nombre"
        );
        return $stmt->fetchAll();
    }

    public function listarOcupacionesAdmin(): array {
        $stmt = $this->pdo->query(
            "SELECT id, nombre, activo FROM ocupaciones_especificas ORDER BY nombre"
        );
        return $stmt->fetchAll();
    }

    public function guardarOcupacion(array $payload): array {
        $id = (int)($payload['id'] ?? 0);

        if (!trim($payload['nombre'] ?? ''))
            return ['status' => 'error', 'message' => 'El nombre de la ocupación es obligatorio.'];

        if ($id) {
            $this->pdo->prepare(
                "UPDATE ocupaciones_especificas SET nombre=?, activo=? WHERE id=?"
            )->execute([
                trim($payload['nombre']),
                isset($payload['activo']) ? (int)(bool)$payload['activo'] : 1,
                $id,
            ]);
        } else {
            $this->pdo->prepare(
                "INSERT INTO ocupaciones_especificas (nombre) VALUES (?)"
            )->execute([trim($payload['nombre'])]);
            $id = (int)$this->pdo->lastInsertId();
        }

        return ['status' => 'success', 'id' => $id];
    }

    public function eliminarOcupacion(int $id): array {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM participantes_cursos WHERE ocupacion_id = ?"
        );
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() > 0)
            return ['status' => 'error', 'message' => 'No se puede eliminar: hay participantes con esta ocupación.'];

        $this->pdo->prepare("DELETE FROM ocupaciones_especificas WHERE id = ?")->execute([$id]);
        return ['status' => 'success'];
    }

    // ══════════════════════════════════════════════════════
    //  GENERACIÓN DE DOCUMENTOS
    // ══════════════════════════════════════════════════════

    public function generarDocumento(int $id, string $tipo, string $usuario): array {
        $p = $this->obtenerParticipante($id);
        if (!$p) return ['status' => 'error', 'message' => 'Participante no encontrado.'];

        $tiposValidos = ['dc3', 'diploma', 'certificado'];
        if (!in_array($tipo, $tiposValidos, true))
            return ['status' => 'error', 'message' => 'Tipo de documento no válido.'];

        // Intentar usar plantilla PDF configurada en la BD
        try {
            $stmt = $this->pdo->prepare(
                "SELECT plantilla_pdf, pdf_campos FROM plantillas_personal WHERE tipo = ? LIMIT 1"
            );
            $stmt->execute([$tipo]);
            $tpl = $stmt->fetch();
            if ($tpl && !empty($tpl['plantilla_pdf'])) {
                $rutaTpl = __DIR__ . '/../uploads/plantillas/' . $tpl['plantilla_pdf'];
                if (file_exists($rutaTpl)) {
                    $camposTpl = json_decode($tpl['pdf_campos'] ?? '[]', true) ?: [];
                    return $this->generarConTemplatePdf($p, $tipo, $usuario, $rutaTpl, $camposTpl);
                }
            }
        } catch (\Exception $e) {
            // tabla aún no existe o error puntual → continúa con fallback
        }

        // Fallback: generar con Dompdf (requiere vendor/)
        if (!class_exists('Dompdf\Dompdf')) {
            return ['status' => 'error',
                    'message' => 'No hay plantilla PDF configurada. Súbela desde Calidad → Personal → Plantillas PDF.'];
        }

        $html  = match($tipo) {
            'dc3'         => $this->htmlDC3($p),
            'certificado' => $this->htmlCertificado($p),
            default       => $this->htmlDiploma($p),
        };
        $folio = 'PART-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
        $url   = $this->htmlAPdf($html, $folio, $tipo);

        $this->pdo->prepare(
            "INSERT INTO participantes_documentos (participante_id, tipo_doc, url, generado_por)
             VALUES (?, ?, ?, ?)"
        )->execute([$id, strtoupper($tipo), $url, $usuario]);

        return ['status' => 'success', 'url' => $url];
    }

    public function enviarDocumento(int $id, string $tipo, string $correoDestino, string $usuario): array {
        $p = $this->obtenerParticipante($id);
        if (!$p) return ['status' => 'error', 'message' => 'Participante no encontrado.'];

        $correo = trim($correoDestino) ?: trim($p['correo'] ?? '');
        if (!$correo || !filter_var($correo, FILTER_VALIDATE_EMAIL))
            return ['status' => 'error', 'message' => 'Correo de destino inválido o no registrado.'];

        // Buscar último documento generado de ese tipo
        $stmt = $this->pdo->prepare(
            "SELECT url FROM participantes_documentos
             WHERE participante_id = ? AND tipo_doc = ?
             ORDER BY fecha_generacion DESC LIMIT 1"
        );
        $stmt->execute([$id, strtoupper($tipo)]);
        $doc = $stmt->fetch();
        if (!$doc) return ['status' => 'error', 'message' => 'Genera el documento primero antes de enviarlo.'];

        // Convertir URL a ruta local y validar path traversal
        $rutaArchivo = str_replace(UPLOAD_URL, UPLOAD_DIR, $doc['url']);
        $realArchivo = realpath($rutaArchivo);
        $realUpload  = realpath(UPLOAD_DIR);
        if (!$realArchivo || !$realUpload || strncmp($realArchivo, $realUpload, strlen($realUpload)) !== 0) {
            return ['status' => 'error', 'message' => 'El archivo del documento no se encontró en el servidor.'];
        }

        if (!class_exists('PHPMailer\PHPMailer\PHPMailer'))
            return ['status' => 'error', 'message' => 'Servicio de correo no disponible en este servidor.'];

        $tipoLabel = ['diploma' => 'Diploma', 'certificado' => 'Certificado', 'dc3' => 'Constancia DC-3'][$tipo] ?? ucfirst($tipo);
        $nombre    = $p['nombre_completo'] ?? 'Participante';

        try {
            $mail = new PHPMailer(true);
            configurarMailer($mail, $this->pdo);
            $mail->addAddress($correo);
            $mail->Subject    = "{$tipoLabel} de Capacitación — AVBA Inspections";
            $mail->isHTML(true);
            $mail->Body       = $this->plantillaCorreoPersonal($nombre, $tipoLabel, $p['curso_nombre'] ?? '');
            $mail->addAttachment($rutaArchivo, basename($rutaArchivo));
            $mail->send();

            return ['status' => 'success', 'message' => "Documento enviado a {$correo}."];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => 'Error al enviar correo: ' . $e->getMessage()];
        }
    }

    private function generarConTemplatePdf(array $p, string $tipo, string $usuario, string $rutaTpl, array $campos): array {
        require_once __DIR__ . '/../lib/fpdi_loader.php';

        $folio   = 'PART-' . str_pad((string)$p['id'], 5, '0', STR_PAD_LEFT);
        $dir     = UPLOAD_DIR . 'personal/docs/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $nombre  = strtoupper($tipo) . '_PDF_' . $folio . '_' . date('Ymd_His') . '.pdf';
        $rutaPdf = $dir . $nombre;

        $fpiDim = new \setasign\Fpdi\Fpdi();
        $fpiDim->setSourceFile($rutaTpl);
        $tplIdx = $fpiDim->importPage(1);
        $size   = $fpiDim->getTemplateSize($tplIdx);
        $orient = ($size['width'] > $size['height']) ? 'L' : 'P';
        unset($fpiDim);

        $pdf = new \setasign\Fpdi\Fpdi($orient, 'mm', [$size['width'], $size['height']]);
        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(0, 0, 0);
        $totalPaginas     = $pdf->setSourceFile($rutaTpl);
        $valoresResueltos = $this->resolverValoresCamposPersonal($p);

        for ($pg = 1; $pg <= $totalPaginas; $pg++) {
            $tpl = $pdf->importPage($pg);
            $sz  = $pdf->getTemplateSize($tpl);
            $pdf->AddPage(($sz['width'] > $sz['height']) ? 'L' : 'P', [$sz['width'], $sz['height']]);
            $pdf->useTemplate($tpl, 0, 0, $sz['width'], $sz['height']);

            foreach ($campos as $campo) {
                $nombreCampo = $campo['campo'] ?? '';
                $pagCampo    = (int)($campo['pagina'] ?? 1);
                if (!$nombreCampo || $pagCampo !== $pg) continue;

                $x       = (float)($campo['x']      ?? 0);
                $y       = (float)($campo['y']      ?? 0);
                $tamano  = (int)  ($campo['tamano'] ?? 10);
                $negrita = !empty($campo['negrita']) ? 'B' : '';
                $ancho   = (float)($campo['ancho']  ?? 0);
                $color   = str_pad(ltrim($campo['color'] ?? '000000', '#'), 6, '0', STR_PAD_LEFT);
                $fuente  = ['Helvetica'=>'Helvetica','Times'=>'Times','Courier'=>'Courier'][$campo['fuente']??''] ?? 'Helvetica';

                [$r, $g, $b] = sscanf($color, '%02x%02x%02x');
                $pdf->SetTextColor($r ?? 0, $g ?? 0, $b ?? 0);

                $valor = (string)($valoresResueltos[$nombreCampo] ?? '');
                if ($valor === '') continue;

                $pdf->SetFont($fuente, $negrita, $tamano);
                $pdf->SetXY($x, $y);
                $pdf->Cell($ancho ?: 0, 0, $valor, 0, 0, '');
            }
        }

        $pdf->Output('F', $rutaPdf);
        $url = UPLOAD_URL . 'personal/docs/' . $nombre;

        $this->pdo->prepare(
            "INSERT INTO participantes_documentos (participante_id, tipo_doc, url, generado_por)
             VALUES (?, ?, ?, ?)"
        )->execute([$p['id'], strtoupper($tipo), $url, $usuario]);

        return ['status' => 'success', 'url' => $url];
    }

    private function resolverValoresCamposPersonal(array $p): array {
        $fechaCurso = $p['fecha_curso'] ?? null;
        $fechaFmt   = $fechaCurso ? (new \DateTime($fechaCurso))->format('d/m/Y') : '';
        $folio      = $p['control']
            ? 'AB.' . $p['control'] . '-' . date('Y') . 'MX'
            : 'PART-' . str_pad((string)$p['id'], 5, '0', STR_PAD_LEFT);

        return [
            'nombre_completo'       => $p['nombre_completo']       ?? '',
            'curp'                  => $p['curp']                  ?? '',
            'puesto'                => $p['puesto']                ?? '',
            'ocupacion'             => $p['ocupacion_nombre']      ?? '',
            'empresa_nombre'        => $p['empresa_nombre']        ?? '',
            'empresa_rfc'           => $p['empresa_rfc']           ?? '',
            'empresa_direccion'     => $p['empresa_direccion']     ?? '',
            'empresa_representante' => $p['empresa_representante'] ?? '',
            'curso_nombre'          => $p['curso_nombre']          ?? '',
            'area_tematica'         => $p['area_tematica']         ?? '',
            'duracion_horas'        => (string)($p['duracion_horas'] ?? ''),
            'fecha_curso'           => $fechaFmt,
            'capacidad'             => $p['capacidad_na'] ? 'N/A' : ($p['capacidad'] ?? ''),
            'folio'                 => $folio,
            'anio'                  => date('Y'),
        ];
    }

    private function plantillaCorreoPersonal(string $nombre, string $tipoLabel, string $curso): string {
        $cuerpo = "
      <p style=\"font-size:15px;color:#1a1a2e;margin:0 0 12px\">Estimado(a) <strong>" . htmlspecialchars($nombre) . "</strong>,</p>
      <p style=\"font-size:14px;color:#5a6072;line-height:1.7;margin:0 0 20px\">
        Adjuntamos su <strong>" . htmlspecialchars($tipoLabel) . "</strong>
        del curso <strong>" . htmlspecialchars($curso) . "</strong>.
      </p>";
        return plantillaCorreoHtml($this->pdo, $cuerpo);
    }

    // ══════════════════════════════════════════════════════
    //  HELPERS PRIVADOS
    // ══════════════════════════════════════════════════════

    private function subirFoto(array $file, string $subdir): array {
        $exts    = ['jpg','jpeg','png','webp'];
        $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $exts))
            return ['status' => 'error', 'message' => 'Solo se permiten imágenes JPG o PNG.'];
        if ($file['size'] > 8 * 1024 * 1024)
            return ['status' => 'error', 'message' => 'La imagen no debe superar 8 MB.'];

        // Verificar MIME real del archivo (no solo la extensión)
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if (!in_array($mime, $allowedMimes, true))
            return ['status' => 'error', 'message' => 'Tipo de archivo no permitido.'];
        if (!getimagesize($file['tmp_name']))
            return ['status' => 'error', 'message' => 'El archivo no es una imagen válida.'];

        $dir = UPLOAD_DIR . $subdir . '/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $nombre = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $dir . $nombre))
            return ['status' => 'error', 'message' => 'No se pudo guardar la imagen.'];

        return ['status' => 'success', 'url' => UPLOAD_URL . $subdir . '/' . $nombre];
    }

    private function parseFecha(string $fecha): ?string {
        if (!$fecha) return null;
        // Acepta dd/mm/yyyy o yyyy-mm-dd
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $fecha, $m))
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha))
            return $fecha;
        return null;
    }

    private function htmlAPdf(string $html, string $folio, string $tipo): string {
        $opts = new Options();
        $opts->set('isRemoteEnabled', false);
        $opts->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($opts);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $dir = UPLOAD_DIR . 'personal/docs/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $nombre = strtoupper($tipo) . '_' . $folio . '_' . date('Ymd_His') . '.pdf';
        file_put_contents($dir . $nombre, protegerPdf($dompdf->output()));

        return UPLOAD_URL . 'personal/docs/' . $nombre;
    }

    private function htmlDC3(array $p): string {
        $esc = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');

        // ── Datos ──────────────────────────────────────────────────────────
        $nombre   = strtoupper(trim($p['nombre_completo'] ?? ''));
        $curp     = strtoupper(trim($p['curp'] ?? ''));
        $puesto   = strtoupper(trim($p['puesto'] ?? ''));
        $ocupacion = trim($p['ocupacion_nombre'] ?? '');
        $empresa  = strtoupper(trim($p['empresa_nombre'] ?? ''));
        $rfcRaw   = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $p['empresa_rfc'] ?? ''));
        $agente   = strtoupper(trim($p['empresa_representante'] ?? ''));
        $patron   = strtoupper(trim($p['empresa_representante'] ?? ''));
        $curso    = strtoupper(trim($p['curso_nombre'] ?? ''));
        $horas    = trim($p['duracion_horas'] ?? '');
        $area     = trim($p['area_tematica'] ?? '');
        $fechaYmd = $p['fecha_curso'] ?? null;   // YYYY-MM-DD

        // RFC con guiones para mostrar (LLLL-YYMMDD-XXX)
        $rfcFmt = $rfcRaw;
        if (preg_match('/^([A-Z]{3,4})(\d{6})([A-Z0-9]{2,3})$/', $rfcRaw, $m)) {
            $rfcFmt = $m[1] . '-' . $m[2] . '-' . $m[3];
        }

        // ── Generadores de cajas de caracteres ─────────────────────────────
        $cellStyle = 'width:13px;height:17px;border:1px solid #333;text-align:center;'
                   . 'font-size:7.5pt;font-weight:bold;padding:0;vertical-align:middle';

        $boxes = function(string $str, int $pad = 0) use ($cellStyle, $esc): string {
            if ($pad) $str = str_pad($str, $pad);
            $out = '';
            foreach (str_split($str) as $c) {
                $out .= '<td style="' . $cellStyle . '">'
                      . ($c !== ' ' ? $esc($c) : '&nbsp;') . '</td>';
            }
            return $out;
        };

        $boxRow = function(string $str, int $pad = 0) use ($boxes): string {
            return '<table style="border-collapse:collapse"><tr>' . $boxes($str, $pad) . '</tr></table>';
        };

        // Cajas para fecha (YYYY-MM-DD → 4+2+2 celdas separadas por espacio)
        $dateBoxes = function(?string $ymd) use ($boxes): string {
            [$y, $mo, $d] = ['    ', '  ', '  '];
            if ($ymd && preg_match('/(\d{4})-(\d{2})-(\d{2})/', $ymd, $mt)) {
                [$y, $mo, $d] = [$mt[1], $mt[2], $mt[3]];
            }
            return '<table style="border-collapse:collapse"><tr>'
                 . $boxes($y) . '<td style="width:4px"></td>'
                 . $boxes($mo) . '<td style="width:4px"></td>'
                 . $boxes($d)
                 . '</tr></table>';
        };

        // ── Estilo base Dompdf ─────────────────────────────────────────────
        $css = <<<'CSS'
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family: Arial, sans-serif; font-size: 8.5pt; color: #000; margin: 14px 18px; }
.doc { width:100%; border-collapse:collapse; border:1.5px solid #000; }
.doc td, .doc th { border:1px solid #555; padding:0; vertical-align:top; }
.sec-hdr td { background:#000; color:#fff; text-align:center; font-weight:bold;
               font-size:8pt; letter-spacing:0.5px; padding:3px 4px; border-color:#000; }
.lbl { font-size:7pt; color:#444; padding:3px 5px 1px; }
.val { font-weight:bold; font-size:8.5pt; padding:2px 5px 4px; }
.val-inline { padding:3px 5px; }
.sig-cell { text-align:center; vertical-align:bottom; padding:6px 10px; }
.sig-name { font-weight:bold; font-size:7.5pt; border-top:1px solid #000;
            padding-top:3px; margin-top:44px; }
.sig-sub  { font-size:7pt; color:#444; }
.instruct { font-size:7pt; line-height:1.55; padding:5px 6px; }
.page2 { page-break-before: always; }
.rev-hdr { text-align:center; font-weight:bold; font-size:8pt;
           border-bottom:1px solid #000; padding:4px 0 6px; margin-bottom:8px; }
.rev-tbl { width:100%; border-collapse:collapse; font-size:7pt; }
.rev-tbl th { font-weight:bold; border-bottom:1.5px solid #000; padding:2px 4px; text-align:left; }
.rev-tbl td { padding:1.5px 4px; vertical-align:top; }
.rev-tbl tr:nth-child(even) td { background:#f5f5f5; }
.dc3-label { text-align:right; font-size:8pt; font-weight:bold; margin-top:8px; }
CSS;

        // ── ANVERSO ────────────────────────────────────────────────────────
        $curpPad  = str_pad($curp, 18);
        $rfcPad   = str_pad($rfcFmt, 14);
        $horasStr = $esc($horas) . ' HORAS';

        // Header columns: Año Mes Día
        $hdrFechas = '<table style="border-collapse:collapse"><tr>'
            . '<td style="width:52px;text-align:center;font-size:6.5pt;padding:0 1px">Año</td>'
            . '<td style="width:4px"></td>'
            . '<td style="width:26px;text-align:center;font-size:6.5pt;padding:0 1px">Mes</td>'
            . '<td style="width:4px"></td>'
            . '<td style="width:26px;text-align:center;font-size:6.5pt;padding:0 1px">Día</td>'
            . '</tr></table>';

        $anverso = <<<HTML
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">
<style>{$css}</style></head><body>

<!-- TÍTULO -->
<table class="doc" style="margin-bottom:0">
  <tr>
    <td style="width:14%;padding:6px 8px;border-right:1px solid #555;vertical-align:middle">
      <div style="font-size:7pt;font-weight:bold;color:#185FA5;text-align:center">AVBA<br>CERTIFICACIONES</div>
    </td>
    <td style="text-align:center;padding:8px 6px;border-right:1px solid #555">
      <div style="font-size:11pt;font-weight:bold">FORMATO DC-3</div>
      <div style="font-size:9pt;font-weight:bold">CONSTANCIA DE COMPETENCIAS O DE HABILIDADES LABORALES</div>
    </td>
    <td style="width:10%;padding:6px;text-align:center;vertical-align:middle;font-size:7pt;color:#999">
      QR
    </td>
  </tr>
</table>

<!-- DATOS DEL TRABAJADOR -->
<table class="doc" style="margin-top:-1px">
  <tr class="sec-hdr"><td colspan="2"><b>DATOS DEL TRABAJADOR</b></td></tr>

  <!-- Nombre -->
  <tr>
    <td colspan="2" style="padding:0">
      <div class="lbl">Nombre (Anotar apellido paterno, apellido materno y nombre (s))</div>
      <div class="val">{$esc($nombre)}</div>
    </td>
  </tr>

  <!-- CURP + Ocupación -->
  <tr>
    <td style="width:55%;border-right:1px solid #555;padding:0">
      <div class="lbl">Clave Única de Registro de Población</div>
      <div class="val-inline">{$boxRow($curpPad)}</div>
    </td>
    <td style="padding:0">
      <div class="lbl">Ocupación específica (Catálogo Nacional de Ocupaciones) <sup>1/</sup></div>
      <div class="val">{$esc($ocupacion)}</div>
    </td>
  </tr>

  <!-- Puesto -->
  <tr>
    <td colspan="2" style="padding:0">
      <div class="lbl">Puesto*</div>
      <div class="val">{$esc($puesto)}</div>
    </td>
  </tr>
</table>

<!-- DATOS DE LA EMPRESA -->
<table class="doc" style="margin-top:-1px">
  <tr class="sec-hdr"><td colspan="2"><b>DATOS DE LA EMPRESA</b></td></tr>

  <tr>
    <td colspan="2" style="padding:0">
      <div class="lbl">Nombre o razón social (En caso de persona física, anotar apellido paterno, apellido materno y nombre(s))</div>
      <div class="val">{$esc($empresa)}</div>
    </td>
  </tr>

  <tr>
    <td colspan="2" style="padding:0">
      <div class="lbl">Registro Federal de Contribuyentes con homoclave (SHCP)</div>
      <div class="val-inline">{$boxRow($rfcPad)}</div>
    </td>
  </tr>
</table>

<!-- DATOS DEL PROGRAMA -->
<table class="doc" style="margin-top:-1px">
  <tr class="sec-hdr"><td colspan="3"><b>DATOS DEL PROGRAMA DE CAPACITACIÓN, ADIESTRAMIENTO Y PRODUCTIVIDAD</b></td></tr>

  <!-- Curso -->
  <tr>
    <td colspan="3" style="padding:0">
      <div class="lbl">Nombre del curso</div>
      <div class="val">{$esc($curso)}</div>
    </td>
  </tr>

  <!-- Duración + Período -->
  <tr>
    <td style="width:22%;border-right:1px solid #555;padding:0">
      <div class="lbl">Duración en horas</div>
      <div class="val">{$horasStr}</div>
    </td>
    <td colspan="2" style="padding:4px 6px">
      <table style="border-collapse:collapse;width:100%">
        <tr>
          <td style="font-size:7.5pt;white-space:nowrap;padding-right:6px;vertical-align:top">
            Periodo de<br>ejecución:
          </td>
          <td>
            <table style="border-collapse:collapse">
              <tr>
                <td style="font-size:7pt;padding-right:4px">De</td>
                <td>{$hdrFechas}</td>
                <td style="width:12px"></td>
                <td style="font-size:7pt;padding-right:4px">a</td>
                <td>{$hdrFechas}</td>
              </tr>
              <tr>
                <td></td>
                <td>{$dateBoxes($fechaYmd)}</td>
                <td></td>
                <td></td>
                <td>{$dateBoxes($fechaYmd)}</td>
              </tr>
            </table>
          </td>
        </tr>
      </table>
    </td>
  </tr>

  <!-- Área temática -->
  <tr>
    <td colspan="3" style="padding:0">
      <div class="lbl">Área temática del curso <sup>2/</sup></div>
      <div class="val">{$esc($area)}</div>
    </td>
  </tr>

  <!-- Agente capacitador -->
  <tr>
    <td colspan="3" style="padding:0">
      <div class="lbl">Nombre del agente capacitador o STPS <sup>3/</sup></div>
      <div class="val">{$esc($agente)}</div>
    </td>
  </tr>
</table>

<!-- FIRMAS -->
<table class="doc" style="margin-top:-1px">
  <tr>
    <td colspan="3" style="padding:5px 8px;font-size:7.5pt;text-align:center;background:#f9f9f9">
      Los datos se asientan en esta constancia bajo protesta de decir verdad, apercibidos de la responsabilidad en que incurre todo
      <br><b>aquel que no se conduce con verdad.</b>
    </td>
  </tr>
  <tr>
    <td class="sig-cell" style="width:33%;border-right:1px solid #555">
      <div class="sig-name">{$esc($agente)}</div>
      <div class="sig-sub">Nombre y firma</div>
      <div style="font-size:7pt;color:#555;margin-top:2px">Instructor o tutor</div>
    </td>
    <td class="sig-cell" style="width:33%;border-right:1px solid #555">
      <div class="sig-name">{$esc($patron)}</div>
      <div class="sig-sub">Nombre y firma</div>
      <div style="font-size:7pt;color:#555;margin-top:2px">Patrón o representante legal <sup>4/</sup></div>
    </td>
    <td class="sig-cell" style="width:34%">
      <div class="sig-name">&nbsp;</div>
      <div class="sig-sub">Nombre y firma</div>
      <div style="font-size:7pt;color:#555;margin-top:2px">Representante de los trabajadores <sup>5/</sup></div>
    </td>
  </tr>
</table>

<!-- INSTRUCCIONES -->
<table class="doc" style="margin-top:-1px">
  <tr>
    <td class="instruct">
      <b>INSTRUCCIONES</b><br>
      &nbsp;- Llenar a máquina o con letra de molde.<br>
      &nbsp;- Deberá entregarse al trabajador dentro de los veinte días hábiles siguientes al término del curso de capacitación aprobado.<br>
      <sup>1/</sup> Las áreas y subáreas ocupacionales del Catálogo Nacional de Ocupaciones se encuentran disponibles en el reverso de este formato y en la página www.stps.gob.mx<br>
      <sup>2/</sup> Las áreas temáticas de los cursos se encuentran disponibles en el reverso de este formato y en la página www.stps.gob.mx<br>
      <sup>3/</sup> Cursos impartidos por el área competente de la Secretaría del Trabajo y Previsión Social.<br>
      <sup>4/</sup> Para empresas con menos de 51 trabajadores. Para empresas con más de 50 trabajadores firmaría el representante del patrón ante la Comisión mixta de capacitación, adiestramiento y productividad.<br>
      <sup>5/</sup> Solo para empresas con más de 50 trabajadores.<br>
      * Dato no obligatorio.
    </td>
  </tr>
</table>

<div class="dc3-label">DC-3<br>ANVERSO</div>
HTML;

        // ── REVERSO (CNO catalog) ──────────────────────────────────────────
        $cnoLeft = [
            ['01','Cultivo, crianza y aprovechamiento'],['01.1','Agricultura y silvicultura'],
            ['01.2','Ganadería'],['01.3','Pesca y acuacultura'],
            ['02','Extracción y suministro'],['02.1','Exploración'],['02.2','Extracción'],
            ['02.3','Refinación y beneficio'],['02.4','Provisión de energía'],['02.5','Provisión de agua'],
            ['03','Construcción'],['03.1','Planeación y dirección de obras'],
            ['03.2','Edificación y urbanización'],['03.3','Acabado'],
            ['03.4','Instalación y mantenimiento'],
            ['04','Tecnología'],['04.1','Mecánica'],['04.2','Electricidad'],['04.3','Electrónica'],
            ['04.4','Informática'],['04.5','Telecomunicaciones'],['04.6','Procesos industriales'],
            ['05','Procesamiento y fabricación'],['05.1','Minerales no metálicos'],['05.2','Metales'],
            ['05.3','Alimentos y bebidas'],['05.4','Textiles y prendas de vestir'],
            ['05.5','Materia orgánica'],['05.6','Productos químicos'],
            ['05.7','Productos metálicos y de hule y plástico'],
            ['05.8','Productos eléctricos y electrónicos'],['05.9','Productos impresos'],
        ];
        $cnoRight = [
            ['06','Transporte'],['06.1','Ferroviario'],['06.2','Autotransporte'],['06.3','Aéreo'],
            ['06.4','Marítimo y fluvial'],['06.5','Servicios de apoyo'],
            ['07','Provisión de bienes y servicios'],['07.1','Comercio'],
            ['07.2','Alimentación y hospedaje'],['07.3','Turismo'],['07.4','Deporte y esparcimiento'],
            ['07.5','Servicios personales'],
            ['07.6','Reparación de artículos de uso doméstico y personal'],
            ['07.7','Limpieza'],['07.8','Servicio postal y mensajería'],
            ['08','Gestión y soporte administrativo'],['08.1','Bolsa, banca y seguros'],
            ['08.2','Administración'],['08.3','Servicios legales'],
            ['09','Salud y protección social'],['09.1','Servicios médicos'],
            ['09.2','Inspección sanitaria y del medio ambiente'],['09.3','Seguridad social'],
            ['09.4','Protección de bienes y/o personas'],
            ['10','Comunicación'],['10.1','Publicación'],['10.2','Radio, cine, televisión y teatro'],
            ['10.3','Interpretación artística'],['10.4','Traducción e interpretación lingüística'],
            ['10.5','Publicidad, propaganda y relaciones públicas'],
            ['11','Desarrollo y extensión del conocimiento'],
            ['11.1','Investigación'],['11.2','Enseñanza'],['11.3','Difusión cultural'],
        ];

        $cnoRows = '';
        $maxRows = max(count($cnoLeft), count($cnoRight));
        for ($i = 0; $i < $maxRows; $i++) {
            $lv = $cnoLeft[$i]  ?? ['',''];
            $rv = $cnoRight[$i] ?? ['',''];
            $boldL = strlen($lv[0]) <= 2 ? 'font-weight:bold' : '';
            $boldR = strlen($rv[0]) <= 2 ? 'font-weight:bold' : '';
            $cnoRows .= '<tr>'
                . "<td style=\"width:8%;padding:1px 3px;{$boldL}\">{$esc($lv[0])}</td>"
                . "<td style=\"width:42%;padding:1px 3px;{$boldL}\">{$esc($lv[1])}</td>"
                . "<td style=\"width:8%;padding:1px 3px;{$boldR}\">{$esc($rv[0])}</td>"
                . "<td style=\"width:42%;padding:1px 3px;{$boldR}\">{$esc($rv[1])}</td>"
                . '</tr>';
        }

        $temasRows = '';
        $temas = [
            ['1000','Producción general'],['2000','Servicios'],
            ['3000','Administración, contabilidad y economía'],['4000','Comercialización'],
            ['5000','Mantenimiento y reparación'],['6000','Seguridad'],
            ['7000','Desarrollo personal y familiar'],
            ['8000','Uso de tecnologías de la información y comunicación'],
            ['9000','Participación social'],
        ];
        $half = (int)ceil(count($temas) / 2);
        for ($i = 0; $i < $half; $i++) {
            $l = $temas[$i];
            $r = $temas[$i + $half] ?? ['',''];
            $temasRows .= '<tr>'
                . "<td style=\"width:8%;padding:1px 3px;font-weight:bold\">{$esc($l[0])}</td>"
                . "<td style=\"width:42%;padding:1px 3px\">{$esc($l[1])}</td>"
                . "<td style=\"width:8%;padding:1px 3px;font-weight:bold\">{$esc($r[0])}</td>"
                . "<td style=\"width:42%;padding:1px 3px\">{$esc($r[1])}</td>"
                . '</tr>';
        }

        $reverso = <<<HTML
<div class="page2">
  <div style="display:flex;justify-content:space-between;margin-bottom:6px">
    <div style="font-size:7pt;font-weight:bold;color:#185FA5">AVBA CERTIFICACIONES</div>
  </div>

  <div class="rev-hdr">CLAVES Y DENOMINACIONES DE ÁREAS Y SUBÁREAS DEL CATÁLOGO NACIONAL DE OCUPACIONES</div>
  <table class="rev-tbl">
    <thead>
      <tr>
        <th>CLAVE DEL ÁREA/SUBÁREA</th><th>DENOMINACIÓN</th>
        <th>CLAVE DEL ÁREA/SUBÁREA</th><th>DENOMINACIÓN</th>
      </tr>
    </thead>
    <tbody>{$cnoRows}</tbody>
  </table>

  <div class="rev-hdr" style="margin-top:10px">CLAVES Y DENOMINACIONES DEL CATÁLOGO DE ÁREAS TEMÁTICAS DE LOS CURSOS</div>
  <table class="rev-tbl">
    <thead>
      <tr>
        <th>CLAVE DEL ÁREA</th><th>DENOMINACIÓN</th>
        <th>CLAVE DEL ÁREA</th><th>DENOMINACIÓN</th>
      </tr>
    </thead>
    <tbody>{$temasRows}</tbody>
  </table>

  <div class="dc3-label">DC-3<br>REVERSO</div>
</div>
HTML;

        return $anverso . $reverso . '</body></html>';
    }

    private function htmlDiploma(array $p): string {
        $esc = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');

        $nombre  = $esc($p['nombre_completo']);
        $curso   = $esc($p['curso_nombre']);
        $horas   = $esc($p['duracion_horas']);
        $area    = $esc($p['area_tematica'] ?? '');
        $fecha   = $p['fecha_curso'] ? date('d \d\e F \d\e Y', strtotime($p['fecha_curso'])) : '';

        $mesesES = ['January'=>'enero','February'=>'febrero','March'=>'marzo','April'=>'abril',
                    'May'=>'mayo','June'=>'junio','July'=>'julio','August'=>'agosto',
                    'September'=>'septiembre','October'=>'octubre','November'=>'noviembre','December'=>'diciembre'];
        foreach ($mesesES as $en => $es) {
            $fecha = str_replace($en, $es, $fecha);
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
  body { font-family: DejaVu Sans, Arial, sans-serif; margin: 0; padding: 0; background: #fff; }
  .diploma {
    border: 6px double #185FA5;
    margin: 24px;
    padding: 36px 48px;
    min-height: 680px;
    text-align: center;
    position: relative;
  }
  .dip-corner {
    position: absolute; width: 30px; height: 30px;
    border-color: #C9A84C; border-style: solid;
  }
  .dip-corner.tl { top: 8px; left: 8px;  border-width: 3px 0 0 3px; }
  .dip-corner.tr { top: 8px; right: 8px; border-width: 3px 3px 0 0; }
  .dip-corner.bl { bottom: 8px; left: 8px;  border-width: 0 0 3px 3px; }
  .dip-corner.br { bottom: 8px; right: 8px; border-width: 0 3px 3px 0; }
  .org-name { font-size: 26pt; font-weight: bold; color: #185FA5; letter-spacing: 4px; margin-bottom: 2px; }
  .org-sub  { font-size: 10pt; color: #5a6072; letter-spacing: 2px; margin-bottom: 28px; }
  .dip-title { font-size: 17pt; font-weight: bold; color: #C9A84C; text-transform: uppercase; letter-spacing: 2px; margin-bottom: 22px; }
  .otorga { font-size: 10pt; color: #5a6072; margin-bottom: 10px; letter-spacing: .5px; }
  .dip-nombre { font-size: 24pt; color: #1a1a2e; font-style: italic; margin: 6px 0 24px; border-bottom: 1.5px solid #C9A84C; display: inline-block; padding-bottom: 4px; }
  .por-completar { font-size: 10pt; color: #5a6072; margin-bottom: 8px; }
  .dip-curso { font-size: 15pt; font-weight: bold; color: #185FA5; margin-bottom: 8px; }
  .dip-meta { font-size: 9pt; color: #888; margin-bottom: 28px; }
  .dip-fecha { font-size: 10pt; color: #5a6072; margin-top: 10px; margin-bottom: 36px; }
  table.firmas { width: 100%; border-collapse: collapse; margin-top: 20px; }
  table.firmas td { text-align: center; padding: 0 24px; vertical-align: bottom; }
  .firma-line { border-top: 1px solid #888; margin-top: 52px; padding-top: 6px; font-size: 9pt; color: #555; }
  .folio { font-size: 7.5pt; color: #bbb; margin-top: 18px; }
</style>
</head>
<body>
<div class="diploma">
  <div class="dip-corner tl"></div>
  <div class="dip-corner tr"></div>
  <div class="dip-corner bl"></div>
  <div class="dip-corner br"></div>

  <div class="org-name">AVBA</div>
  <div class="org-sub">CERTIFICACIONES</div>

  <div class="dip-title">Diploma de Participación</div>

  <div class="otorga">Se otorga el presente diploma a:</div>
  <div class="dip-nombre">{$nombre}</div>

  <div class="por-completar">Por haber concluido satisfactoriamente el curso:</div>
  <div class="dip-curso">{$curso}</div>
  <div class="dip-meta">Área temática: {$area} &nbsp;|&nbsp; Duración: {$horas} horas</div>

  <div class="dip-fecha">Realizado el {$fecha}</div>

  <table class="firmas">
    <tr>
      <td><div class="firma-line">Director de Capacitación</div></td>
      <td><div class="firma-line">Instructor del Curso</div></td>
    </tr>
  </table>

  <div class="folio">AVBA Certificaciones — Emisión: {$esc(date('d/m/Y'))}</div>
</div>
</body>
</html>
HTML;
    }

    // ── AUTOREGISTRO COMPLETO POR PARTICIPANTE ────────────
    public function participanteRegistrar(array $payload, array $files, int $sesionId): array {
        $nombre    = trim($payload['nombre_completo'] ?? '');
        $curp      = strtoupper(trim($payload['curp'] ?? ''));
        $puesto    = trim($payload['puesto'] ?? '');
        $cursoId   = (int)($payload['curso_id'] ?? 0);
        $empresaId = (int)($payload['sesion_empresa_id'] ?? 0); // ID en sesion_empresas

        if (!$nombre)   return ['status' => 'error', 'message' => 'El nombre completo es obligatorio.'];
        if (!$cursoId)  return ['status' => 'error', 'message' => 'Selecciona el curso que te corresponde.'];

        // Validar CURP si se proporcionó
        if ($curp) {
            $curpCheck = validarCURPCompleta($curp);
            if (!$curpCheck['valida']) return ['status' => 'error', 'message' => $curpCheck['error']];
        }

        // Verificar que el curso pertenece a esta sesión
        $stmtC = $this->pdo->prepare(
            "SELECT 1 FROM sesion_cursos WHERE sesion_acceso_id = ? AND curso_id = ?"
        );
        $stmtC->execute([$sesionId, $cursoId]);
        if (!$stmtC->fetch()) return ['status' => 'error', 'message' => 'El curso seleccionado no pertenece a esta sesión.'];

        // Obtener datos de la sesión
        $stmtS = $this->pdo->prepare(
            "SELECT sesion_nombre, fecha_curso FROM curso_sesiones_acceso WHERE id = ? AND activa = 1"
        );
        $stmtS->execute([$sesionId]);
        $sesion = $stmtS->fetch();
        if (!$sesion) return ['status' => 'error', 'message' => 'Sesión no encontrada o cerrada.'];

        // Obtener datos de empresa desde sesion_empresas
        $empresaNombre = '';
        $empresaRfc    = '';
        $empresaRep    = '';
        $empresaDir    = '';
        $primeraParte  = null;
        if ($empresaId) {
            $stmtE = $this->pdo->prepare(
                "SELECT nombre_empresa, rfc, representante, direccion, primera_parte
                 FROM sesion_empresas WHERE id = ? AND sesion_acceso_id = ?"
            );
            $stmtE->execute([$empresaId, $sesionId]);
            $emp = $stmtE->fetch();
            if ($emp) {
                $empresaNombre = $emp['nombre_empresa'];
                $empresaRfc    = $emp['rfc']           ?? '';
                $empresaRep    = $emp['representante']  ?? '';
                $empresaDir    = $emp['direccion']      ?? '';
                $primeraParte  = $emp['primera_parte']  ?? null;
            }
        }

        // Generar control de folio
        $control = generarControl($this->pdo, $empresaNombre ?: $nombre);

        // Subir foto de medio cuerpo (requerida)
        $fotoPersonaUrl = null;
        if (!empty($files['foto_persona']['tmp_name']) && is_uploaded_file($files['foto_persona']['tmp_name'])) {
            $res = $this->subirFoto($files['foto_persona'], 'participantes/persona');
            if ($res['status'] !== 'success') return $res;
            $fotoPersonaUrl = $res['url'];
        }

        // Subir documentos (múltiples: imágenes o PDF)
        $fotoDocUrl = null;
        $docsUrls   = [];
        $docKeys    = array_filter(array_keys($files), fn($k) => str_starts_with($k, 'documento_'));
        foreach ($docKeys as $key) {
            $file = $files[$key];
            if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) continue;
            $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
            if ($ext === 'pdf') {
                $res = $this->subirArchivoPdf($file, 'participantes/documentos');
            } else {
                $res = $this->subirFoto($file, 'participantes/documentos');
            }
            if ($res['status'] === 'success') $docsUrls[] = $res['url'];
        }
        if ($docsUrls) $fotoDocUrl = $docsUrls[0]; // primer doc como foto_documentacion_url

        // Insertar registro en participantes_cursos
        $this->ensureEstatusColumn();
        $this->ensureQrColumn();

        $stmt = $this->pdo->prepare(
            "INSERT INTO participantes_cursos
             (nombre_completo, curp, puesto, curso_id, fecha_curso, control,
              empresa_nombre, empresa_rfc, empresa_representante, empresa_direccion,
              foto_persona_url, foto_documentacion_url, estatus, usuario_registro)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'PENDIENTE','PARTICIPANTE')"
        );
        $stmt->execute([
            $nombre,
            $curp    ?: null,
            $puesto  ?: null,
            $cursoId,
            $sesion['fecha_curso'],
            $control,
            $empresaNombre ?: null,
            $empresaRfc    ?: null,
            $empresaRep    ?: null,
            $empresaDir    ?: null,
            $fotoPersonaUrl,
            $fotoDocUrl,
        ]);
        $participanteId = (int)$this->pdo->lastInsertId();

        // Vincular a la sesión
        $this->pdo->prepare(
            "INSERT IGNORE INTO sesion_acceso_participantes (sesion_acceso_id, participante_id) VALUES (?,?)"
        )->execute([$sesionId, $participanteId]);

        // Actualizar cliente si viene de empresa existente
        if ($primeraParte) {
            try {
                $this->pdo->prepare(
                    "INSERT IGNORE INTO clientes (nombre_cliente, primera_parte) VALUES (?,?)"
                )->execute([$empresaNombre, $primeraParte]);
            } catch (\PDOException $e) {}
        }

        return ['status' => 'success', 'message' => 'Registro guardado correctamente.', 'id' => $participanteId, 'control' => $control];
    }

    // ── SUBIR PDF ─────────────────────────────────────────
    private function subirArchivoPdf(array $file, string $subdir): array {
        if (($file['size'] ?? 0) > 20 * 1024 * 1024)
            return ['status' => 'error', 'message' => 'El archivo no debe superar 20 MB.'];

        $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        if ($ext !== 'pdf') return ['status' => 'error', 'message' => 'Solo se permiten archivos PDF.'];

        // Verificar MIME real (no confiar solo en la extensión del cliente)
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if ($mime !== 'application/pdf')
            return ['status' => 'error', 'message' => 'El archivo no es un PDF válido.'];

        // Verificar que empieza con la firma de PDF (%PDF-)
        $fh = fopen($file['tmp_name'], 'rb');
        $header = fread($fh, 5);
        fclose($fh);
        if ($header !== '%PDF-')
            return ['status' => 'error', 'message' => 'El archivo no es un PDF válido.'];

        $dir = rtrim(UPLOAD_DIR, '/') . '/' . $subdir . '/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $nombre  = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.pdf';
        $destino = $dir . $nombre;
        if (!move_uploaded_file($file['tmp_name'], $destino)) {
            return ['status' => 'error', 'message' => 'Error al guardar el archivo.'];
        }
        $url = rtrim(UPLOAD_URL, '/') . '/' . $subdir . '/' . $nombre;
        return ['status' => 'success', 'url' => $url];
    }

    // ── AUTOGUARDADO POR PARTICIPANTE ─────────────────────
    public function participanteAutoGuardar(array $payload, int $sesionId): array {
        $participanteId = (int)($payload['participante_id'] ?? 0);
        if (!$participanteId) return ['status' => 'error', 'message' => 'participante_id requerido.'];

        // Verificar que el participante pertenece a esta sesión
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM sesion_acceso_participantes WHERE sesion_acceso_id = ? AND participante_id = ?"
        );
        $stmt->execute([$sesionId, $participanteId]);
        if (!$stmt->fetch()) return ['status' => 'error', 'message' => 'Participante no pertenece a esta sesión.'];

        $sets   = [];
        $params = [];

        if (!empty($payload['nombre_completo'])) {
            $sets[]   = 'nombre_completo = ?';
            $params[] = trim($payload['nombre_completo']);
        }
        if (!empty($payload['curp'])) {
            $curp      = strtoupper(trim($payload['curp']));
            $curpCheck = validarCURPCompleta($curp);
            if (!$curpCheck['valida']) return ['status' => 'error', 'message' => $curpCheck['error']];
            $sets[]   = 'curp = ?';
            $params[] = $curp;
        }

        if (empty($sets)) return ['status' => 'success', 'message' => 'Sin cambios.'];

        $params[] = $participanteId;
        $this->pdo->prepare(
            "UPDATE participantes_cursos SET " . implode(', ', $sets) . " WHERE id = ?"
        )->execute($params);

        return ['status' => 'success', 'message' => 'Datos guardados correctamente.'];
    }

    private function htmlCertificado(array $p): string {
        $esc    = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
        $nombre = $esc($p['nombre_completo']);
        $curp   = $esc($p['curp']);
        $curso  = $esc($p['curso_nombre']);
        $horas  = $esc($p['duracion_horas']);
        $area   = $esc($p['area_tematica'] ?? '');
        $folio  = 'PART-' . str_pad((string)$p['id'], 5, '0', STR_PAD_LEFT);
        $fecha  = $p['fecha_curso'] ? date('d/m/Y', strtotime($p['fecha_curso'])) : date('d/m/Y');

        return <<<HTML
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">
<style>
  body { font-family: DejaVu Sans, Arial, sans-serif; margin:0; padding:0; background:#fff; }
  .cert { border: 4px double #185FA5; margin: 30px; padding: 40px 50px; text-align: center; min-height: 680px; position: relative; }
  .cert-logo { font-size:28pt; font-weight:bold; color:#185FA5; letter-spacing:3px; margin-bottom:4px; }
  .cert-sub { font-size:10pt; color:#666; margin-bottom:30px; }
  .cert-title { font-size:22pt; font-weight:bold; color:#185FA5; text-transform:uppercase; letter-spacing:2px; margin-bottom:6px; }
  .cert-sub2 { font-size:10pt; color:#555; margin-bottom:24px; }
  .cert-nombre { font-size:24pt; color:#1a1a2e; font-weight:bold; border-bottom:2px solid #185FA5; display:inline-block; padding-bottom:6px; margin:10px 0 20px; }
  .cert-text { font-size:12pt; color:#444; line-height:1.8; margin-bottom:8px; }
  .cert-curso { font-size:14pt; color:#185FA5; font-weight:bold; margin:6px 0; }
  .cert-detalles { font-size:10pt; color:#666; margin:16px 0 30px; }
  .firmas { width:80%; margin:40px auto 0; border-collapse:collapse; }
  .firmas td { text-align:center; padding:0 30px; vertical-align:bottom; }
  .firma-line { border-top:1px solid #333; padding-top:6px; font-size:9pt; color:#555; }
  .folio { position:absolute; bottom:20px; right:30px; font-size:8pt; color:#aaa; }
</style></head><body>
<div class="cert">
  <div class="cert-logo">AVBA</div>
  <div class="cert-sub">Inspections, Certifications and Maintenance S.A.S. de C.V.</div>
  <div class="cert-title">Certificado de Capacitación</div>
  <div class="cert-sub2">Otorga el presente certificado a:</div>
  <div class="cert-nombre">{$nombre}</div>
  <div class="cert-text">CURP: <strong>{$curp}</strong></div>
  <div class="cert-text">Por haber completado satisfactoriamente el curso:</div>
  <div class="cert-curso">{$curso}</div>
  <div class="cert-detalles">Área temática: {$area} &nbsp;·&nbsp; Duración: {$horas} horas &nbsp;·&nbsp; Fecha: {$fecha}</div>
  <table class="firmas">
    <tr>
      <td><div class="firma-line">Director de Capacitación</div></td>
      <td><div class="firma-line">Instructor del Curso</div></td>
    </tr>
  </table>
  <div class="folio">Folio: {$esc($folio)} — Emitido: {$esc(date('d/m/Y'))}</div>
</div>
</body></html>
HTML;
    }
}
