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
        // Garantizar columnas de workflow y datos antes de consultarlas
        $this->ensureEstatusColumn();
        $this->ensureParticipanteColumns();
        try { $this->pdo->exec("ALTER TABLE participantes_cursos ADD COLUMN IF NOT EXISTS control VARCHAR(30) NULL"); } catch (\Throwable $e) {}
        try { $this->pdo->exec("ALTER TABLE clientes ADD COLUMN IF NOT EXISTS correo_contacto VARCHAR(200) DEFAULT NULL"); } catch (\Throwable $e) {}

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
                       p.control, p.estatus, p.fecha_curso,
                       c.nombre AS curso_nombre, c.duracion_horas,
                       o.nombre AS ocupacion_nombre,
                       p.foto_documentacion_url, p.foto_persona_url,
                       p.empresa_nombre, p.fecha_registro,
                       COALESCE((SELECT cl.correo_contacto FROM clientes cl WHERE cl.nombre_cliente = p.empresa_nombre COLLATE utf8mb4_general_ci AND cl.correo_contacto IS NOT NULL AND cl.correo_contacto <> '' LIMIT 1),'') AS empresa_correo
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
                    COALESCE(c.texto_certificado,'') AS texto_certificado,
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
        $this->ensureParticipanteColumns();
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

    // ── Aprobar múltiples participantes en batch ──────────
    public function aprobarSeleccionados(array $ids, string $usuario): array {
        $aprobados = 0;
        $errores   = [];
        foreach ($ids as $id) {
            $res = $this->aprobarParticipante((int)$id, $usuario);
            if ($res['status'] === 'success') {
                $aprobados++;
            } else {
                $errores[] = "ID {$id}: " . ($res['message'] ?? 'Error');
            }
        }
        if ($aprobados === 0) {
            return ['status' => 'error', 'message' => implode('; ', $errores) ?: 'No se aprobó ningún participante.'];
        }
        $msg = "{$aprobados} participante(s) aprobados y enviados a Certificaciones.";
        if ($errores) $msg .= ' Sin QR disponible para: ' . implode(', ', $errores);
        return ['status' => 'success', 'message' => $msg, 'aprobados' => $aprobados];
    }

    // ── Enviar sesión completa con todos los documentos ───
    public function enviarSesionPersonal(array $payload, string $usuario): array {
        $cursoNombre  = trim($payload['curso_nombre']  ?? '');
        $fechaCurso   = trim($payload['fecha_curso']   ?? '');
        $empresa      = trim($payload['empresa']       ?? '');
        $tipo         = trim($payload['tipo']          ?? 'todo');
        $correo       = trim($payload['correo']        ?? '');
        $enviarCreds  = !empty($payload['enviar_credenciales']);

        if (!$correo || !filter_var($correo, FILTER_VALIDATE_EMAIL))
            return ['status' => 'error', 'message' => 'Correo de envío inválido.'];

        // Garantizar columna correo_contacto en clientes
        try { $this->pdo->exec("ALTER TABLE clientes ADD COLUMN IF NOT EXISTS correo_contacto VARCHAR(200) DEFAULT NULL"); } catch (\Throwable $e) {}

        // Obtener participantes de la sesión aprobados o emitidos
        $params = [$cursoNombre, $fechaCurso];
        if ($empresa !== '') {
            $empCond  = "AND p.empresa_nombre = ?";
            $params[] = $empresa;
        } else {
            $empCond  = "AND (p.empresa_nombre IS NULL OR p.empresa_nombre = '')";
        }
        $stmt = $this->pdo->prepare(
            "SELECT p.* FROM participantes_cursos p
             LEFT JOIN cursos c ON c.id = p.curso_id
             WHERE c.nombre = ? AND p.fecha_curso = ? {$empCond}
             AND p.estatus IN ('APROBADO_CALIDAD','EMITIDO')
             ORDER BY p.nombre_completo"
        );
        $stmt->execute($params);
        $participantes = $stmt->fetchAll();
        if (empty($participantes))
            return ['status' => 'error', 'message' => 'Sin participantes aprobados en esta sesión.'];

        // Determinar tipos de documentos a generar
        $tipos = match($tipo) {
            'dc3'          => ['dc3'],
            'diploma'      => ['diploma'],
            'certificado'  => ['certificado'],
            default        => ['dc3', 'diploma', 'certificado'],
        };

        // Generar documentos y recopilar adjuntos
        $adjuntos = [];
        $errores  = [];
        foreach ($participantes as $p) {
            foreach ($tipos as $t) {
                $res = $this->generarDocumento((int)$p['id'], $t, $usuario);
                if ($res['status'] === 'success' && !empty($res['url'])) {
                    $ruta = realpath(str_replace(UPLOAD_URL, UPLOAD_DIR, $res['url']));
                    if ($ruta) {
                        $safe  = preg_replace('/[^A-Za-z0-9_\-]/', '_', $p['nombre_completo'] ?? 'doc');
                        $adjuntos[] = ['path' => $ruta, 'name' => strtoupper($t) . '_' . $safe . '.pdf'];
                    }
                } else {
                    $errores[] = ($p['nombre_completo'] ?? 'N/A') . ' · ' . $t;
                }
            }
        }

        if (empty($adjuntos))
            return ['status' => 'error', 'message' => 'No se pudo generar ningún documento.' . ($errores ? ' Errores: ' . implode(', ', $errores) : '')];

        // Marcar todos como EMITIDO
        $ids = array_map(fn($p) => (int)$p['id'], $participantes);
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $this->pdo->prepare("UPDATE participantes_cursos SET estatus = 'EMITIDO' WHERE id IN ({$ph})")
                  ->execute($ids);

        // Guardar correo en la empresa del catálogo
        if ($empresa !== '') {
            try {
                $stmt = $this->pdo->prepare("SELECT id FROM clientes WHERE nombre_cliente COLLATE utf8mb4_general_ci = ? LIMIT 1");
                $stmt->execute([$empresa]);
                $cli = $stmt->fetch();
                if ($cli) {
                    $this->pdo->prepare("UPDATE clientes SET correo_contacto = ? WHERE id = ?")
                              ->execute([$correo, $cli['id']]);
                }
            } catch (\Throwable $e) {}
        }

        // Gestionar credenciales si se solicitó
        $credenciales = [];
        if ($enviarCreds && !empty($participantes)) {
            try {
                $credenciales = $this->gestionarCredencialesParticipante($participantes[0], $correo);
            } catch (\Throwable $e) {}
        }

        // Enviar correo
        if (!class_exists('PHPMailer\PHPMailer\PHPMailer'))
            return ['status' => 'error', 'message' => 'Servicio de correo no disponible.'];

        $tipoLabel = ['dc3' => 'Constancias DC-3', 'diploma' => 'Diplomas', 'certificado' => 'Certificados', 'todo' => 'Documentos de capacitación'][$tipo] ?? 'Documentos';
        try {
            $mail = new PHPMailer(true);
            configurarMailer($mail, $this->pdo);
            $mail->addAddress($correo);
            $mail->Subject = "{$tipoLabel} — {$cursoNombre} — AVBA Inspections";
            $mail->isHTML(true);
            $mail->Body    = $this->plantillaCorreoSesion($cursoNombre, $fechaCurso, $empresa, count($participantes), $tipoLabel, $credenciales);
            foreach ($adjuntos as $adj) {
                $mail->addAttachment($adj['path'], $adj['name']);
            }
            $mail->send();
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => 'Error al enviar correo: ' . $e->getMessage()];
        }

        $msg = "Correo enviado a {$correo} con " . count($participantes) . " participante(s).";
        if ($errores) $msg .= ' Sin documento para: ' . implode(', ', array_slice($errores, 0, 3));
        return ['status' => 'success', 'message' => $msg, 'count' => count($participantes)];
    }

    private function plantillaCorreoSesion(string $curso, string $fecha, string $empresa, int $count, string $tipoLabel, array $credenciales = []): string {
        $esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $empresaHtml = $empresa ? " de <strong>{$esc($empresa)}</strong>" : '';
        $fechaHtml   = $fecha   ? " del día <strong>{$esc($fecha)}</strong>"  : '';
        $cuerpo = "
      <p style='font-size:15px;color:#1a1a2e;margin:0 0 12px'>
        Estimado(a) equipo{$empresaHtml},
      </p>
      <p style='font-size:14px;color:#5a6072;line-height:1.7;margin:0 0 8px'>
        Adjuntamos los <strong>{$esc($tipoLabel)}</strong> correspondientes al curso
        <strong>{$esc($curso)}</strong>{$fechaHtml}
        para <strong>{$count} participante(s)</strong>.
      </p>";

        if (!empty($credenciales)) {
            $usuario   = $esc($credenciales['usuario'] ?? '');
            $password  = $esc($credenciales['password'] ?? '');
            $portalUrl = rtrim(defined('SITE_URL') ? SITE_URL : '', '/') . '/portal-cliente.html';
            $accion    = ($credenciales['es_nuevo'] ?? true)
                ? 'Se ha creado su cuenta en el portal AVBA'
                : 'Se ha actualizado su contraseña del portal AVBA';
            $cuerpo .= "
      <div style='margin-top:20px;padding:16px 18px;background:#F0F7ED;border-left:4px solid #2e7d32;border-radius:6px'>
        <p style='font-weight:700;color:#1b5e20;margin:0 0 10px;font-size:14px'>{$esc($accion)}</p>
        <table style='border-collapse:collapse'>
          <tr>
            <td style='padding:3px 14px 3px 0;color:#5a6072;font-size:13px'>Usuario:</td>
            <td style='font-family:monospace;font-weight:700;color:#1a1a2e;font-size:14px'>{$usuario}</td>
          </tr>
          <tr>
            <td style='padding:3px 14px 3px 0;color:#5a6072;font-size:13px'>Contraseña:</td>
            <td style='font-family:monospace;font-weight:700;color:#1a1a2e;font-size:14px'>{$password}</td>
          </tr>
        </table>
        <p style='margin:10px 0 0;font-size:12px;color:#5a6072'>Accede en:
          <a href='{$portalUrl}' style='color:#185FA5'>{$portalUrl}</a>
        </p>
      </div>";
        }
        return plantillaCorreoHtml($this->pdo, $cuerpo);
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
                "SELECT id FROM clientes WHERE UPPER(TRIM(nombre_cliente COLLATE utf8mb4_general_ci)) = UPPER(TRIM(?))"
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

    // ── Actualizar información personal básica ────────────
    public function actualizarInfoParticipante(array $payload, string $usuario): array {
        $id = (int)($payload['id'] ?? 0);
        if (!$id) return ['status' => 'error', 'message' => 'ID requerido.'];

        $chk = $this->pdo->prepare("SELECT id FROM participantes_cursos WHERE id = ?");
        $chk->execute([$id]);
        if (!$chk->fetch()) return ['status' => 'error', 'message' => 'Participante no encontrado.'];

        $allowed = ['nombre_completo', 'curp', 'puesto', 'telefono', 'correo', 'capacidad'];
        $sets    = [];
        $params  = [];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $payload)) {
                $val = trim((string)($payload[$field] ?? ''));
                if ($field === 'curp' && $val !== '') {
                    $val = strtoupper($val);
                    $check = validarCURPCompleta($val);
                    if (!$check['valida']) return ['status' => 'error', 'message' => $check['error']];
                }
                if ($field === 'correo' && $val !== '') {
                    $val = strtolower($val);
                }
                $sets[]   = "`{$field}` = ?";
                $params[] = $val === '' ? null : $val;
            }
        }
        // capacidad_na es booleano, manejo separado
        if (array_key_exists('capacidad_na', $payload)) {
            $sets[]   = "`capacidad_na` = ?";
            $params[] = $payload['capacidad_na'] ? 1 : 0;
        }
        if (empty($sets)) return ['status' => 'success', 'message' => 'Sin cambios.'];

        $params[] = $id;
        $this->pdo->prepare("UPDATE participantes_cursos SET " . implode(', ', $sets) . " WHERE id = ?")
                  ->execute($params);

        return ['status' => 'success', 'message' => 'Información personal actualizada.'];
    }

    // ── Emitir documento y marcar como EMITIDO ─────────────
    public function emitirDocumentoPersonal(int $id, string $tipo, string $correoDestino, string $usuario, bool $enviarCredenciales = false): array {
        $this->ensureEstatusColumn();
        $resultado = $this->generarDocumento($id, $tipo, $usuario);
        if ($resultado['status'] !== 'success') return $resultado;

        // Marcar como emitido
        $this->pdo->prepare("UPDATE participantes_cursos SET estatus = 'EMITIDO' WHERE id = ?")
            ->execute([$id]);

        // Enviar correo con o sin credenciales
        $p      = $this->obtenerParticipante($id);
        $correo = trim($correoDestino) ?: trim($p['correo'] ?? '');
        if ($correo && filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $credenciales = [];
            if ($enviarCredenciales && $p) {
                $credenciales = $this->gestionarCredencialesParticipante($p, $correo);
            }
            $this->enviarDocumento($id, $tipo, $correo, $usuario, $credenciales);
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

    private function ensureParticipanteColumns(): void {
        try {
            $this->pdo->exec("ALTER TABLE participantes_cursos
                ADD COLUMN IF NOT EXISTS empresa_rfc           VARCHAR(20)  DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS empresa_representante VARCHAR(200) DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS empresa_direccion     VARCHAR(300) DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS capacidad             VARCHAR(100) DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS capacidad_na          TINYINT(1)   NOT NULL DEFAULT 0,
                ADD COLUMN IF NOT EXISTS correo                VARCHAR(200) DEFAULT NULL");
        } catch (\Throwable $e) {}
    }

    // ══════════════════════════════════════════════════════
    //  CURSOS
    // ══════════════════════════════════════════════════════

    public function listarCursos(): array {
        $this->ensureTextoCertificadoColumn();
        $stmt = $this->pdo->query(
            "SELECT id, nombre, duracion_horas, area_tematica, activo,
                    COALESCE(texto_certificado,'') AS texto_certificado,
                    DATE_FORMAT(fecha_creacion,'%d/%m/%Y') AS fecha_creacion
             FROM cursos ORDER BY nombre"
        );
        return $stmt->fetchAll();
    }

    public function guardarCurso(array $payload, string $usuario): array {
        $this->ensureTextoCertificadoColumn();
        $id = (int)($payload['id'] ?? 0);

        if (!trim($payload['nombre'] ?? ''))
            return ['status' => 'error', 'message' => 'El nombre del curso es obligatorio.'];
        if (empty($payload['duracion_horas']) || (float)$payload['duracion_horas'] <= 0)
            return ['status' => 'error', 'message' => 'La duración en horas debe ser mayor a 0.'];
        if (!trim($payload['area_tematica'] ?? ''))
            return ['status' => 'error', 'message' => 'El área temática es obligatoria.'];

        $textoCert = trim($payload['texto_certificado'] ?? '') ?: null;

        if ($id) {
            $this->pdo->prepare(
                "UPDATE cursos SET nombre=?, duracion_horas=?, area_tematica=?, activo=?, texto_certificado=? WHERE id=?"
            )->execute([
                trim($payload['nombre']),
                (float)$payload['duracion_horas'],
                trim($payload['area_tematica']),
                isset($payload['activo']) ? (int)(bool)$payload['activo'] : 1,
                $textoCert,
                $id,
            ]);
        } else {
            $this->pdo->prepare(
                "INSERT INTO cursos (nombre, duracion_horas, area_tematica, texto_certificado, creado_por) VALUES (?,?,?,?,?)"
            )->execute([
                trim($payload['nombre']),
                (float)$payload['duracion_horas'],
                trim($payload['area_tematica']),
                $textoCert,
                $usuario,
            ]);
            $id = (int)$this->pdo->lastInsertId();
        }

        return ['status' => 'success', 'id' => $id];
    }

    private function ensureTextoCertificadoColumn(): void {
        try {
            $cols = $this->pdo->query("SHOW COLUMNS FROM cursos LIKE 'texto_certificado'")->fetchAll();
            if (empty($cols)) {
                $this->pdo->exec("ALTER TABLE cursos ADD COLUMN texto_certificado VARCHAR(500) NULL AFTER area_tematica");
            }
        } catch (\Throwable $e) {}
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

        if (!class_exists('Dompdf\Dompdf')) {
            return ['status' => 'error', 'message' => 'Dompdf no disponible. Instala las dependencias del proyecto.'];
        }

        $html        = match($tipo) {
            'dc3'         => $this->htmlDC3($p),
            'certificado' => $this->htmlCertificado($p),
            default       => $this->htmlDiploma($p),
        };
        $folio       = 'PART-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
        $orientation = ($tipo === 'diploma') ? 'landscape' : 'portrait';
        $url         = $this->htmlAPdf($html, $folio, $tipo, $orientation);

        $this->pdo->prepare(
            "INSERT INTO participantes_documentos (participante_id, tipo_doc, url, generado_por)
             VALUES (?, ?, ?, ?)"
        )->execute([$id, strtoupper($tipo), $url, $usuario]);

        return ['status' => 'success', 'url' => $url];
    }

    // ── Gestionar credenciales portal para participante ──────
    private function gestionarCredencialesParticipante(array $p, string $correo): array {
        // Determinar id_cliente: empresa_nombre tiene prioridad, luego primera parte del control
        $idCliente = '';
        if (!empty($p['empresa_nombre'])) {
            $stmt = $this->pdo->prepare("SELECT primera_parte FROM clientes WHERE nombre_cliente COLLATE utf8mb4_general_ci = ? LIMIT 1");
            $stmt->execute([$p['empresa_nombre']]);
            $row = $stmt->fetch();
            if ($row) {
                $idCliente = str_pad((string)($row['primera_parte'] ?? ''), 5, '0', STR_PAD_LEFT);
            }
        }
        if (!$idCliente && !empty($p['control'])) {
            $parts     = explode('-', $p['control']);
            $idCliente = str_pad($parts[0], 5, '0', STR_PAD_LEFT);
        }
        if (!$idCliente) {
            $idCliente = str_pad((string)($p['id'] ?? 0), 5, '0', STR_PAD_LEFT);
        }

        // Generar contraseña aleatoria
        $chars    = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789@#$!';
        $password = '';
        for ($i = 0; $i < 10; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }

        // Verificar usuario existente
        $stmt = $this->pdo->prepare(
            "SELECT id, usuario FROM usuarios WHERE id_cliente = ? AND rol = 'CLIENTE' LIMIT 1"
        );
        $stmt->execute([$idCliente]);
        $existing = $stmt->fetch();

        if ($existing) {
            $this->pdo->prepare("UPDATE usuarios SET password_hash = ? WHERE id = ?")
                      ->execute([password_hash($password, PASSWORD_DEFAULT), $existing['id']]);
            return ['usuario' => $existing['usuario'], 'password' => $password, 'es_nuevo' => false];
        }

        // Crear nuevo usuario — garantizar unicidad del nombre de usuario
        $usuario = $idCliente;
        $counter = 0;
        while (true) {
            $chk = $this->pdo->prepare("SELECT id FROM usuarios WHERE usuario = ?");
            $chk->execute([$usuario]);
            if (!$chk->fetch()) break;
            $counter++;
            $usuario = $idCliente . '_' . $counter;
        }

        $nombreCuenta = !empty($p['empresa_nombre']) ? $p['empresa_nombre'] : ($p['nombre_completo'] ?? '');
        $this->pdo->prepare(
            "INSERT INTO usuarios (usuario, password_hash, rol, id_cliente, nombre, correo, activo)
             VALUES (?, ?, 'CLIENTE', ?, ?, ?, 1)"
        )->execute([$usuario, password_hash($password, PASSWORD_DEFAULT), $idCliente, $nombreCuenta, $correo]);

        return ['usuario' => $usuario, 'password' => $password, 'es_nuevo' => true];
    }

    public function enviarDocumento(int $id, string $tipo, string $correoDestino, string $usuario, array $credenciales = []): array {
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
            $mail->Body       = $this->plantillaCorreoPersonal($nombre, $tipoLabel, $p['curso_nombre'] ?? '', $credenciales);
            $mail->addAttachment($rutaArchivo, basename($rutaArchivo));
            $mail->send();

            return ['status' => 'success', 'message' => "Documento enviado a {$correo}."];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => 'Error al enviar correo: ' . $e->getMessage()];
        }
    }

    private function plantillaCorreoPersonal(string $nombre, string $tipoLabel, string $curso, array $credenciales = []): string {
        $esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $cuerpo = "
      <p style=\"font-size:15px;color:#1a1a2e;margin:0 0 12px\">Estimado(a) <strong>{$esc($nombre)}</strong>,</p>
      <p style=\"font-size:14px;color:#5a6072;line-height:1.7;margin:0 0 20px\">
        Adjuntamos su <strong>{$esc($tipoLabel)}</strong>
        del curso <strong>{$esc($curso)}</strong>.
      </p>";

        if (!empty($credenciales)) {
            $usuario   = $esc($credenciales['usuario'] ?? '');
            $password  = $esc($credenciales['password'] ?? '');
            $portalUrl = rtrim(defined('SITE_URL') ? SITE_URL : '', '/') . '/portal-cliente.html';
            $accion    = ($credenciales['es_nuevo'] ?? true)
                ? 'Se ha creado su cuenta en el portal AVBA'
                : 'Se ha actualizado su contraseña del portal AVBA';
            $cuerpo .= "
      <div style=\"margin-top:20px;padding:16px 18px;background:#F0F7ED;border-left:4px solid #2e7d32;border-radius:6px\">
        <p style=\"font-weight:700;color:#1b5e20;margin:0 0 10px;font-size:14px\">{$esc($accion)}</p>
        <table style=\"border-collapse:collapse\">
          <tr>
            <td style=\"padding:3px 14px 3px 0;color:#5a6072;font-size:13px\">Usuario:</td>
            <td style=\"font-family:monospace;font-weight:700;color:#1a1a2e;font-size:14px\">{$usuario}</td>
          </tr>
          <tr>
            <td style=\"padding:3px 14px 3px 0;color:#5a6072;font-size:13px\">Contraseña:</td>
            <td style=\"font-family:monospace;font-weight:700;color:#1a1a2e;font-size:14px\">{$password}</td>
          </tr>
        </table>
        <p style=\"margin:10px 0 0;font-size:12px;color:#5a6072\">Accede en:
          <a href=\"{$portalUrl}\" style=\"color:#185FA5\">{$portalUrl}</a>
        </p>
      </div>";
        }

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

    private function htmlAPdf(string $html, string $folio, string $tipo, string $orientation = 'portrait'): string {
        $opts = new Options();
        $opts->set('isRemoteEnabled', false);
        $opts->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($opts);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', $orientation);
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
.sec-hdr td { background:#1B2A6B; color:#fff; text-align:center; font-weight:bold;
               font-size:8pt; letter-spacing:0.5px; padding:3px 4px; border-color:#1B2A6B; }
.lbl { font-size:7pt; color:#444; padding:3px 5px 1px; }
.val { font-weight:bold; font-size:8.5pt; padding:2px 5px 4px; }
.val-inline { padding:3px 5px; }
.sig-cell { text-align:center; vertical-align:bottom; padding:6px 10px; }
.sig-name { font-weight:bold; font-size:7.5pt; border-top:1px solid #000;
            padding-top:3px; margin-top:44px; }
.sig-sub  { font-size:7pt; color:#444; }
.instruct { font-size:7pt; line-height:1.55; padding:5px 6px; }
.page2 { page-break-before: always; }
.rev-hdr { text-align:center; font-weight:bold; font-size:8pt; color:#1B2A6B;
           border-bottom:1.5px solid #1B2A6B; padding:4px 0 6px; margin-bottom:8px; }
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
      <div style="font-size:7pt;font-weight:bold;color:#1B2A6B;text-align:center">AVBA<br>CERTIFICACIONES</div>
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
    <div style="font-size:7pt;font-weight:bold;color:#1B2A6B">AVBA CERTIFICACIONES</div>
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

        $nombre  = $esc(strtoupper(trim($p['nombre_completo'] ?? '')));
        $horas   = $esc($p['duracion_horas'] ?? '');
        $area    = $esc(trim($p['area_tematica'] ?? ''));
        $folio   = $esc(
            $p['control']
                ? 'AB.' . $p['control'] . '-' . date('Y') . 'MX'
                : 'PART-' . str_pad((string)$p['id'], 5, '0', STR_PAD_LEFT)
        );
        $emision = $esc(date('d/m/Y'));

        // Resolver texto del certificado (con capacidad)
        $capVal  = $p['capacidad_na'] ? 'N/A' : trim($p['capacidad'] ?? '');
        $tbase   = trim($p['texto_certificado'] ?? '');
        $textoCert = $esc(strtoupper($tbase
            ? str_replace('{capacidad}', $capVal, $tbase)
            : ($p['curso_nombre'] ?? '')));

        // Fecha del curso en español
        $fecha = '';
        if (!empty($p['fecha_curso'])) {
            $fecha = date('d \d\e F \d\e Y', strtotime($p['fecha_curso']));
            foreach (['January'=>'enero','February'=>'febrero','March'=>'marzo','April'=>'abril',
                      'May'=>'mayo','June'=>'junio','July'=>'julio','August'=>'agosto',
                      'September'=>'septiembre','October'=>'octubre','November'=>'noviembre','December'=>'diciembre']
                     as $en => $es) {
                $fecha = str_replace($en, $es, $fecha);
            }
        }
        $fechaEsc = $esc($fecha);

        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
  font-family: 'DejaVu Serif', Georgia, serif;
  background: #F9F6EE;
  width: 277mm;
}
.page {
  width: 272mm;
  min-height: 185mm;
  margin: 3mm;
  background: #F9F6EE;
  border: 5px solid #B8882A;
  position: relative;
}
.inner {
  position: absolute;
  top: 7px; left: 7px; right: 7px; bottom: 7px;
  border: 1px solid #1B2A6B;
}
.corner {
  position: absolute;
  width: 22px; height: 22px;
  border-color: #B8882A; border-style: solid;
}
.tl { top: 14px;    left: 14px;    border-width: 2px 0 0 2px; }
.tr { top: 14px;    right: 14px;   border-width: 2px 2px 0 0; }
.bl { bottom: 14px; left: 14px;    border-width: 0 0 2px 2px; }
.br { bottom: 14px; right: 14px;   border-width: 0 2px 2px 0; }
/* Header */
.hdr {
  background: #0D1B4B;
  padding: 13px 44px 11px;
  text-align: center;
}
.hdr-logo {
  font-family: 'DejaVu Sans', Arial, sans-serif;
  font-size: 15pt; font-weight: bold;
  color: #C9A84C; letter-spacing: 7px;
  margin-bottom: 2px;
}
.hdr-sub {
  font-family: 'DejaVu Sans', Arial, sans-serif;
  font-size: 6.5pt; color: #7A94C4;
  letter-spacing: 2.5px; text-transform: uppercase;
}
/* Body */
.body { padding: 14px 52px 12px; text-align: center; }
.dip-title {
  font-family: 'DejaVu Sans', Arial, sans-serif;
  font-size: 20pt; font-weight: bold;
  color: #B8882A; letter-spacing: 9px;
  text-transform: uppercase; margin-bottom: 2px;
}
.dip-sub {
  font-family: 'DejaVu Sans', Arial, sans-serif;
  font-size: 7.5pt; color: #888; letter-spacing: 0.5px;
  font-style: italic; margin-bottom: 10px;
}
.gold-bar { width: 64px; height: 2px; background: #C9A84C; margin: 0 auto 10px; }
.se-otorga {
  font-family: 'DejaVu Sans', Arial, sans-serif;
  font-size: 8.5pt; color: #555; margin-bottom: 5px;
}
.nombre {
  font-size: 23pt; font-style: italic;
  color: #0D1B4B; line-height: 1.2; margin-bottom: 3px;
}
.nombre-line { width: 180px; height: 1.5px; background: #C9A84C; margin: 3px auto 10px; }
.ha-completado {
  font-family: 'DejaVu Sans', Arial, sans-serif;
  font-size: 8pt; color: #555; margin-bottom: 6px;
}
.texto-cert {
  font-family: 'DejaVu Sans', Arial, sans-serif;
  font-size: 12pt; font-weight: bold;
  color: #0D1B4B; letter-spacing: 0.5px;
  text-transform: uppercase; margin-bottom: 5px;
  padding: 6px 24px;
  border-top: 1px solid #C9A84C;
  border-bottom: 1px solid #C9A84C;
}
.curso-meta {
  font-family: 'DejaVu Sans', Arial, sans-serif;
  font-size: 7pt; color: #999; margin-bottom: 3px;
}
.fecha-txt {
  font-family: 'DejaVu Sans', Arial, sans-serif;
  font-size: 8pt; color: #555; margin-bottom: 13px;
}
table.firmas { width: 72%; margin: 0 auto; border-collapse: collapse; }
td.firma-td { text-align: center; padding: 0 32px; vertical-align: bottom; }
.firma-esp { height: 38px; }
.firma-raya {
  border-top: 1px solid #0D1B4B;
  padding-top: 3px;
}
.firma-cargo {
  font-family: 'DejaVu Sans', Arial, sans-serif;
  font-size: 7.5pt; font-weight: bold; color: #333;
}
.firma-inst {
  font-family: 'DejaVu Sans', Arial, sans-serif;
  font-size: 6.5pt; color: #AAA; margin-top: 1px;
}
.folio-pie {
  font-family: 'DejaVu Mono', Courier, monospace;
  font-size: 5.5pt; color: #CCC; margin-top: 10px;
  letter-spacing: 1px;
}
</style>
</head>
<body>
<div class="page">
  <div class="inner"></div>
  <div class="corner tl"></div>
  <div class="corner tr"></div>
  <div class="corner bl"></div>
  <div class="corner br"></div>

  <div class="hdr">
    <div class="hdr-logo">AVBA CERTIFICACIONES</div>
    <div class="hdr-sub">Inspección Industrial &amp; Capacitación Especializada</div>
  </div>

  <div class="body">
    <div class="dip-title">Diploma</div>
    <div class="dip-sub">Otorga el presente reconocimiento a:</div>
    <div class="gold-bar"></div>

    <div class="se-otorga">Se hace constar que</div>
    <div class="nombre">{$nombre}</div>
    <div class="nombre-line"></div>

    <div class="ha-completado">Ha concluido satisfactoriamente el programa de capacitación:</div>
    <div class="texto-cert">{$textoCert}</div>
    <div class="curso-meta">Área: {$area} &nbsp;&bull;&nbsp; Duración: {$horas} horas</div>
    <div class="fecha-txt">Celebrado el {$fechaEsc}</div>

    <table class="firmas">
      <tr>
        <td class="firma-td">
          <div class="firma-esp"></div>
          <div class="firma-raya">
            <div class="firma-cargo">Director de Capacitación</div>
            <div class="firma-inst">AVBA Certificaciones</div>
          </div>
        </td>
        <td class="firma-td">
          <div class="firma-esp"></div>
          <div class="firma-raya">
            <div class="firma-cargo">Instructor del Curso</div>
            <div class="firma-inst">AVBA Certificaciones</div>
          </div>
        </td>
      </tr>
    </table>

    <div class="folio-pie">AVBA Certificaciones &nbsp;&bull;&nbsp; {$folio} &nbsp;&bull;&nbsp; Emisión: {$emision}</div>
  </div>
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
        $this->ensureParticipanteColumns();

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

        // Texto de capacidad para el certificado (ej: "OPERADOR DE GRUA PUENTE CON CAPACIDAD DE 5 TON")
        $capacidadVal  = $p['capacidad_na'] ? 'N/A' : ($p['capacidad'] ?? '');
        $textoCertBase = trim($p['texto_certificado'] ?? '');
        $textoCert     = $textoCertBase
            ? $esc(str_replace('{capacidad}', $capacidadVal, $textoCertBase))
            : '';
        $bloqueCert    = $textoCert
            ? "<div class=\"cert-capacidad\">{$textoCert}</div>"
            : '';

        return <<<HTML
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">
<style>
  body { font-family: DejaVu Sans, Arial, sans-serif; margin:0; padding:0; background:#fff; }
  .cert { border: 4px double #185FA5; margin: 30px; padding: 40px 50px; text-align: center; min-height: 680px; position: relative; }
  .cert-logo { font-size:28pt; font-weight:bold; color:#1B2A6B; letter-spacing:3px; margin-bottom:4px; }
  .cert-sub { font-size:10pt; color:#666; margin-bottom:30px; }
  .cert-title { font-size:22pt; font-weight:bold; color:#1B2A6B; text-transform:uppercase; letter-spacing:2px; margin-bottom:6px; }
  .cert-sub2 { font-size:10pt; color:#555; margin-bottom:24px; }
  .cert-nombre { font-size:24pt; color:#1a1a2e; font-weight:bold; border-bottom:2px solid #185FA5; display:inline-block; padding-bottom:6px; margin:10px 0 20px; }
  .cert-text { font-size:12pt; color:#444; line-height:1.8; margin-bottom:8px; }
  .cert-curso { font-size:14pt; color:#1B2A6B; font-weight:bold; margin:6px 0; }
  .cert-capacidad { font-size:13pt; color:#1B2A6B; font-weight:700; text-transform:uppercase; letter-spacing:1px; margin:12px 0 4px; border:1.5px solid #185FA5; border-radius:6px; display:inline-block; padding:6px 20px; }
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
  {$bloqueCert}
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
