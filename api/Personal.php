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

        if (!trim($payload['nombre_completo'] ?? ''))
            return ['status' => 'error', 'message' => 'El nombre completo es obligatorio.'];
        if (!trim($payload['curp'] ?? ''))
            return ['status' => 'error', 'message' => 'La CURP es obligatoria.'];
        if (!$cursoId)
            return ['status' => 'error', 'message' => 'Selecciona un curso.'];
        if (!trim($payload['fecha_curso'] ?? ''))
            return ['status' => 'error', 'message' => 'La fecha del curso es obligatoria.'];

        // Normalizar CURP
        $curp = strtoupper(trim($payload['curp']));
        if (!preg_match('/^[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z0-9]\d$/', $curp)) {
            return ['status' => 'error', 'message' => 'El formato de la CURP no es válido.'];
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

        $campos = [
            'nombre_completo'       => trim($payload['nombre_completo']),
            'curp'                  => $curp,
            'puesto'                => trim($payload['puesto'] ?? '') ?: null,
            'ocupacion_id'          => ($payload['ocupacion_id'] ?? 0) ?: null,
            'capacidad'             => trim($payload['capacidad'] ?? '') ?: null,
            'capacidad_na'          => !empty($payload['capacidad_na']) ? 1 : 0,
            'telefono'              => trim($payload['telefono'] ?? '') ?: null,
            'correo'                => trim($payload['correo'] ?? '') ?: null,
            'empresa_nombre'        => trim($payload['empresa_nombre'] ?? '') ?: null,
            'empresa_rfc'           => strtoupper(trim($payload['empresa_rfc'] ?? '')) ?: null,
            'empresa_direccion'     => trim($payload['empresa_direccion'] ?? '') ?: null,
            'empresa_representante' => trim($payload['empresa_representante'] ?? '') ?: null,
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

        return ['status' => 'success', 'id' => $id];
    }

    public function eliminarParticipante(int $id): array {
        $this->pdo->prepare("DELETE FROM participantes_cursos WHERE id = ?")->execute([$id]);
        return ['status' => 'success'];
    }

    // ── Aprobar participante → APROBADO_CALIDAD ────────────
    public function aprobarParticipante(int $id, string $usuario): array {
        $this->ensureEstatusColumn();
        $chk = $this->pdo->prepare("SELECT id, nombre_completo, empresa_nombre, control FROM participantes_cursos WHERE id = ?");
        $chk->execute([$id]);
        $p = $chk->fetch();
        if (!$p) return ['status' => 'error', 'message' => 'Participante no encontrado.'];

        // Generar control si el participante no lo tiene (registros previos a migration_009)
        if (empty($p['control'])) {
            $clienteNombre = $p['empresa_nombre'] ?: $p['nombre_completo'];
            $control = generarControl($this->pdo, $clienteNombre);
            $this->pdo->prepare("UPDATE participantes_cursos SET control = ? WHERE id = ?")
                ->execute([$control, $id]);
        }

        $this->pdo->prepare("UPDATE participantes_cursos SET estatus = 'APROBADO_CALIDAD' WHERE id = ?")
            ->execute([$id]);
        return ['status' => 'success', 'message' => 'Participante aprobado y enviado a Certificaciones.'];
    }

    // ── Devolver participante → DEVUELTO ──────────────────
    public function devolverParticipante(int $id, string $usuario): array {
        $this->ensureEstatusColumn();
        $chk = $this->pdo->prepare("SELECT id FROM participantes_cursos WHERE id = ?");
        $chk->execute([$id]);
        if (!$chk->fetch()) return ['status' => 'error', 'message' => 'Participante no encontrado.'];

        $this->pdo->prepare("UPDATE participantes_cursos SET estatus = 'DEVUELTO' WHERE id = ?")
            ->execute([$id]);
        return ['status' => 'success', 'message' => 'Participante devuelto a Calidad.'];
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

        // Convertir URL a ruta local
        $rutaArchivo = str_replace(UPLOAD_URL, UPLOAD_DIR, $doc['url']);
        if (!file_exists($rutaArchivo))
            return ['status' => 'error', 'message' => 'El archivo del documento no se encontró en el servidor.'];

        if (!class_exists('PHPMailer\PHPMailer\PHPMailer'))
            return ['status' => 'error', 'message' => 'Servicio de correo no disponible en este servidor.'];

        $tipoLabel = ['diploma' => 'Diploma', 'certificado' => 'Certificado', 'dc3' => 'Constancia DC-3'][$tipo] ?? ucfirst($tipo);
        $nombre    = $p['nombre_completo'] ?? 'Participante';

        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = MAIL_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = MAIL_USER;
            $mail->Password   = MAIL_PASS;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port       = MAIL_PORT;
            $mail->CharSet    = 'UTF-8';
            $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
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
                $color   = str_pad($campo['color'] ?? '000000', 6, '0', STR_PAD_LEFT);
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
        return "<!DOCTYPE html><html><body style=\"font-family:'Segoe UI',sans-serif;background:#f4f7fb;margin:0;padding:20px\">
<div style=\"max-width:540px;margin:auto;background:white;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.08)\">
  <div style=\"background:#185FA5;padding:24px;text-align:center\">
    <h1 style=\"color:white;font-size:20px;margin:0\">AVBA Inspections</h1>
    <p style=\"color:rgba(255,255,255,0.75);font-size:13px;margin:6px 0 0\">Capacitación y Certificación</p>
  </div>
  <div style=\"padding:28px 32px\">
    <p style=\"font-size:15px;color:#1a1a2e;margin:0 0 12px\">Estimado(a) <strong>{$nombre}</strong>,</p>
    <p style=\"font-size:14px;color:#5a6072;line-height:1.7;margin:0 0 20px\">
      Adjuntamos su <strong>{$tipoLabel}</strong> del curso <strong>{$curso}</strong>,
      emitido por AVBA Inspections, Certifications and Maintenance S.A.S. de C.V.
    </p>
  </div>
  <div style=\"background:#f4f7fb;padding:16px 32px;border-top:1px solid #dfe5ef;text-align:center\">
    <p style=\"font-size:12px;color:#9299a8;margin:0\">
      AVBA Inspections — <a href=\"https://avba.com.mx\" style=\"color:#185FA5\">avba.com.mx</a>
    </p>
  </div>
</div></body></html>";
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
        file_put_contents($dir . $nombre, $dompdf->output());

        return UPLOAD_URL . 'personal/docs/' . $nombre;
    }

    private function htmlDC3(array $p): string {
        $esc = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');

        $nombre   = $esc($p['nombre_completo']);
        $curp     = $esc($p['curp']);
        $curso    = $esc($p['curso_nombre']);
        $horas    = $esc($p['duracion_horas']);
        $area     = $esc($p['area_tematica'] ?? '');
        $puesto   = $esc($p['puesto'] ?? '');
        $empresa  = $esc($p['empresa_nombre'] ?? 'Sin especificar');
        $rfc      = $esc($p['empresa_rfc'] ?? '');
        $rep      = $esc($p['empresa_representante'] ?? '');
        $dir      = $esc($p['empresa_direccion'] ?? '');
        $ocupacion = $esc($p['ocupacion_nombre'] ?? '');
        $capacidad = $esc($p['capacidad'] ?? ($p['capacidad_na'] ? 'N/A' : ''));
        $fecha    = $p['fecha_curso'] ? date('d \d\e F \d\e Y', strtotime($p['fecha_curso'])) : '';

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
  body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 10pt; color: #1a1a2e; margin: 0; padding: 28px 36px; }
  .page-border { border: 3px solid #185FA5; border-radius: 4px; padding: 24px; min-height: 750px; }
  .hdr { text-align: center; border-bottom: 2px solid #185FA5; padding-bottom: 14px; margin-bottom: 18px; }
  .hdr-logo { font-size: 22pt; font-weight: bold; color: #185FA5; letter-spacing: 2px; }
  .hdr-sub { font-size: 10pt; color: #185FA5; margin-top: 2px; }
  .dc3-title { text-align: center; font-size: 15pt; font-weight: bold; color: #185FA5; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 1px; }
  .dc3-subtitle { text-align: center; font-size: 11pt; color: #444; margin-bottom: 20px; }
  .section-title { font-size: 8pt; font-weight: bold; color: #185FA5; text-transform: uppercase; letter-spacing: 0.08em; border-bottom: 1px solid #185FA5; padding-bottom: 3px; margin: 14px 0 8px; }
  table.data { width: 100%; border-collapse: collapse; }
  table.data td { padding: 5px 8px; border-bottom: 1px solid #dfe5ef; font-size: 9.5pt; vertical-align: top; }
  table.data td.lbl { font-weight: bold; color: #5a6072; width: 38%; font-size: 9pt; }
  table.data td.val { color: #1a1a2e; }
  .folio-box { float: right; border: 1px solid #185FA5; padding: 4px 12px; border-radius: 6px; font-size: 8.5pt; color: #185FA5; font-weight: bold; }
  .firma-section { margin-top: 40px; }
  table.firmas { width: 100%; border-collapse: collapse; }
  table.firmas td { text-align: center; padding: 0 20px; vertical-align: bottom; }
  .firma-line { border-top: 1px solid #333; margin-top: 50px; padding-top: 5px; font-size: 8.5pt; color: #444; }
  .nota { margin-top: 20px; font-size: 8pt; color: #888; text-align: center; border-top: 1px solid #dfe5ef; padding-top: 8px; }
  .stps-ref { font-size: 7.5pt; color: #aaa; text-align: center; margin-top: 4px; }
  .clearfix::after { content: ''; display: table; clear: both; }
</style>
</head>
<body>
<div class="page-border">

<div class="hdr">
  <div class="clearfix">
    <div class="folio-box">FOLIO: {$esc($p['id'] ? 'DC3-' . str_pad($p['id'], 5, '0', STR_PAD_LEFT) : '')}</div>
  </div>
  <div class="hdr-logo">AVBA</div>
  <div class="hdr-sub">CERTIFICACIONES</div>
</div>

<div class="dc3-title">Constancia de Habilidades Laborales</div>
<div class="dc3-subtitle">(DC-3)</div>

<div class="section-title">Datos del Trabajador</div>
<table class="data">
  <tr><td class="lbl">Nombre completo:</td><td class="val">{$nombre}</td></tr>
  <tr><td class="lbl">CURP:</td><td class="val">{$curp}</td></tr>
  <tr><td class="lbl">Puesto / Función:</td><td class="val">{$puesto}</td></tr>
  <tr><td class="lbl">Ocupación específica:</td><td class="val">{$ocupacion}</td></tr>
</table>

<div class="section-title">Datos de la Empresa</div>
<table class="data">
  <tr><td class="lbl">Razón social:</td><td class="val">{$empresa}</td></tr>
  <tr><td class="lbl">RFC:</td><td class="val">{$rfc}</td></tr>
  <tr><td class="lbl">Representante:</td><td class="val">{$rep}</td></tr>
  <tr><td class="lbl">Domicilio:</td><td class="val">{$dir}</td></tr>
</table>

<div class="section-title">Datos de la Capacitación</div>
<table class="data">
  <tr><td class="lbl">Nombre del curso:</td><td class="val">{$curso}</td></tr>
  <tr><td class="lbl">Área temática:</td><td class="val">{$area}</td></tr>
  <tr><td class="lbl">Duración:</td><td class="val">{$horas} horas</td></tr>
  <tr><td class="lbl">Fecha de realización:</td><td class="val">{$fecha}</td></tr>
  <tr><td class="lbl">Capacidad adquirida:</td><td class="val">{$capacidad}</td></tr>
</table>

<div class="firma-section">
  <table class="firmas">
    <tr>
      <td><div class="firma-line">Instructor / Capacitador</div></td>
      <td><div class="firma-line">Representante de la Empresa</div></td>
      <td><div class="firma-line">Trabajador</div></td>
    </tr>
  </table>
</div>

<div class="nota">Este documento ampara la capacitación impartida y acredita las habilidades laborales obtenidas.</div>
<div class="stps-ref">Generado por AVBA Certificaciones — Fecha de emisión: {$esc(date('d/m/Y'))}</div>

</div>
</body>
</html>
HTML;
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
