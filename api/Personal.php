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
                       p.curso_id,
                       c.nombre AS curso_nombre, c.duracion_horas,
                       o.nombre AS ocupacion_nombre,
                       p.foto_documentacion_url, p.foto_persona_url,
                       p.empresa_nombre, p.fecha_registro,
                       p.usuario_registro,
                       COALESCE(ui.nombre, p.usuario_registro, '') AS instructor_nombre,
                       COALESCE((SELECT cl.correo_contacto FROM clientes cl WHERE cl.nombre_cliente = p.empresa_nombre COLLATE utf8mb4_general_ci AND cl.correo_contacto IS NOT NULL AND cl.correo_contacto <> '' LIMIT 1),'') AS empresa_correo
                FROM participantes_cursos p
                LEFT JOIN cursos c ON c.id = p.curso_id
                LEFT JOIN ocupaciones_especificas o ON o.id = p.ocupacion_id
                LEFT JOIN usuarios ui ON ui.usuario COLLATE utf8mb4_general_ci = p.usuario_registro
                WHERE " . implode(' AND ', $where) . "
                ORDER BY p.fecha_registro DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function obtenerParticipante(int $id): ?array {
        $this->ensureMigration013Columns();
        $this->ensureTextoCertificadoColumn();
        $stmt = $this->pdo->prepare(
            "SELECT p.*,
                    c.nombre AS curso_nombre, c.duracion_horas, c.area_tematica,
                    COALESCE(c.texto_certificado,'') AS texto_certificado,
                    o.nombre AS ocupacion_nombre,
                    u.nombre AS instructor_nombre,
                    u.registro_stps AS instructor_stps,
                    u.firma_imagen AS instructor_firma_imagen,
                    cl.id AS cliente_id,
                    cl.logo AS empresa_logo_b64,
                    COALESCE(
                        cl.representante COLLATE utf8mb4_general_ci,
                        p.empresa_representante
                    ) AS empresa_representante,
                    cl.representante_trabajadores AS empresa_rep_trabajadores
             FROM participantes_cursos p
             LEFT JOIN cursos c ON c.id = p.curso_id
             LEFT JOIN ocupaciones_especificas o ON o.id = p.ocupacion_id
             LEFT JOIN usuarios u ON u.usuario COLLATE utf8mb4_general_ci = p.usuario_registro
             LEFT JOIN clientes cl ON UPPER(TRIM(cl.nombre_cliente COLLATE utf8mb4_general_ci)) = UPPER(TRIM(p.empresa_nombre))
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

        // Verificación de identidad con IA (semáforo para Calidad), si ya se corrió
        $row['verificacion_ia'] = null;
        try {
            $stmtV = $this->pdo->prepare(
                "SELECT resultado, nombre_doc, curp_doc, detalle,
                        DATE_FORMAT(created_at,'%d/%m/%Y %H:%i') AS fecha
                 FROM participantes_verificacion WHERE participante_id = ?"
            );
            $stmtV->execute([$id]);
            $row['verificacion_ia'] = $stmtV->fetch() ?: null;
        } catch (\Throwable $e) { /* la tabla se crea al primer uso del módulo */ }

        // Ocupaciones específicas de la empresa (para DC-3)
        $row['ocupaciones_empresa'] = [];
        if (!empty($row['cliente_id'])) {
            $this->ensureClientesOcupacionesTable();
            $stmtOcup = $this->pdo->prepare(
                "SELECT oe.nombre FROM clientes_ocupaciones co
                 JOIN ocupaciones_especificas oe ON oe.id = co.ocupacion_id
                 WHERE co.cliente_id = ? ORDER BY co.orden"
            );
            $stmtOcup->execute([$row['cliente_id']]);
            $row['ocupaciones_empresa'] = array_column($stmtOcup->fetchAll(), 'nombre');
        }

        return $row;
    }

    /**
     * Participantes ya registrados de una empresa, para reutilizarlos en un
     * curso nuevo sin volver a capturarlos ni pedirles otra vez sus datos.
     *
     * Se agrupa por CURP (o por nombre, si no tiene) y se toma el registro más
     * reciente de cada persona, que es el que trae los datos y las fotos al
     * día. Se excluyen los que ya están en el curso/fecha indicados, para no
     * ofrecer duplicados dentro de la misma sesión.
     */
    public function participantesDeEmpresa(string $empresa, int $cursoId = 0, string $fechaCurso = ''): array {
        $empresa = trim($empresa);
        if ($empresa === '') return ['status' => 'error', 'message' => 'Indica la empresa.'];

        try {
            // Un renglón por persona: el de su curso más reciente. Se resuelve
            // con una subconsulta en lugar de GROUP BY porque agrupar mientras
            // se seleccionan columnas sin agregar es un error bajo el modo
            // ONLY_FULL_GROUP_BY de MySQL (activo por omisión desde 5.7), y ahí
            // la lista de personal llegaba vacía.
            $sql = "SELECT p.id AS ultimo_id,
                           p.nombre_completo, p.curp, p.puesto, p.ocupacion_id,
                           p.capacidad, p.capacidad_na, p.telefono,
                           p.foto_documentacion_url, p.foto_persona_url,
                           p.empresa_nombre, p.empresa_rfc, p.empresa_representante, p.empresa_direccion,
                           DATE_FORMAT(p.fecha_curso,'%d/%m/%Y') AS ultimo_curso,
                           (SELECT COUNT(*) FROM participantes_cursos q
                             WHERE TRIM(q.empresa_nombre) = TRIM(p.empresa_nombre)
                               AND COALESCE(NULLIF(q.curp,''), q.nombre_completo)
                                 = COALESCE(NULLIF(p.curp,''), p.nombre_completo)) AS cursos_tomados
                    FROM participantes_cursos p
                    WHERE TRIM(p.empresa_nombre) = ?
                      AND p.id = (SELECT MAX(r.id) FROM participantes_cursos r
                                   WHERE TRIM(r.empresa_nombre) = TRIM(p.empresa_nombre)
                                     AND COALESCE(NULLIF(r.curp,''), r.nombre_completo)
                                       = COALESCE(NULLIF(p.curp,''), p.nombre_completo))
                    ORDER BY p.nombre_completo
                    LIMIT 500";
            $st = $this->pdo->prepare($sql);
            $st->execute([$empresa]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log('[Personal] participantesDeEmpresa: ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'No se pudo consultar el personal de la empresa.'];
        }

        // Quitar los que ya están en esta misma sesión de curso
        $yaEnSesion = [];
        if ($cursoId && $fechaCurso !== '') {
            try {
                $f = date('Y-m-d', strtotime(str_replace('/', '-', $fechaCurso)));
                $st2 = $this->pdo->prepare(
                    "SELECT COALESCE(NULLIF(curp,''), nombre_completo) AS clave
                     FROM participantes_cursos
                     WHERE curso_id = ? AND fecha_curso = ? AND TRIM(empresa_nombre) = ?"
                );
                $st2->execute([$cursoId, $f, $empresa]);
                $yaEnSesion = array_flip($st2->fetchAll(PDO::FETCH_COLUMN));
            } catch (\PDOException $e) { /* sin filtro si algo falla */ }
        }

        $out = [];
        foreach ($rows as $r) {
            $clave = ($r['curp'] ?? '') !== '' ? $r['curp'] : $r['nombre_completo'];
            if (isset($yaEnSesion[$clave])) continue;
            $out[] = [
                'id'              => (int)$r['ultimo_id'],
                'nombre_completo' => $r['nombre_completo'],
                'curp'            => $r['curp'] ?? '',
                'puesto'          => $r['puesto'] ?? '',
                'ocupacion_id'    => $r['ocupacion_id'] ?? '',
                'capacidad'       => $r['capacidad'] ?? '',
                'capacidad_na'    => $r['capacidad_na'] ?? '',
                'telefono'        => $r['telefono'] ?? '',
                'foto_documentacion_url' => $r['foto_documentacion_url'] ?? '',
                'foto_persona_url'       => $r['foto_persona_url'] ?? '',
                'empresa_nombre'         => $r['empresa_nombre'] ?? '',
                'empresa_rfc'            => $r['empresa_rfc'] ?? '',
                'empresa_representante'  => $r['empresa_representante'] ?? '',
                'empresa_direccion'      => $r['empresa_direccion'] ?? '',
                'cursos_tomados'  => (int)$r['cursos_tomados'],
                'ultimo_curso'    => $r['ultimo_curso'] ?? '',
            ];
        }
        return ['status' => 'success', 'participantes' => $out];
    }

    public function guardarParticipante(array $payload, array $files, string $usuario): array {
        $this->ensureParticipanteColumns();
        $id      = (int)($payload['id'] ?? 0);
        $cursoId = (int)($payload['curso_id'] ?? 0);

        if (!$cursoId)
            return ['status' => 'error', 'message' => 'Selecciona un curso.'];

        // La fecha tiene que quedar en un formato que la columna DATE acepte:
        // si no se puede interpretar, se avisa en vez de dejar que el INSERT
        // falle con un error interno.
        $fechaCurso = $this->parseFecha(trim($payload['fecha_curso'] ?? ''));
        if (!$fechaCurso)
            return ['status' => 'error', 'message' => 'La fecha del curso es obligatoria y debe ser válida (dd/mm/aaaa).'];

        if (!trim($payload['nombre_completo'] ?? ''))
            return ['status' => 'error', 'message' => 'El nombre del participante es obligatorio.'];

        // El curso tiene que existir: es llave foránea.
        $ck = $this->pdo->prepare("SELECT id FROM cursos WHERE id = ?");
        $ck->execute([$cursoId]);
        if (!$ck->fetch())
            return ['status' => 'error', 'message' => 'El curso indicado ya no existe.'];

        // Normalizar CURP si se proporcionó. Se guarda como cadena vacía, no
        // como NULL: la columna nació NOT NULL y hay instalaciones donde sigue
        // siéndolo.
        $curpRaw = strtoupper(trim($payload['curp'] ?? ''));
        $curp    = $curpRaw;
        if ($curp !== '') {
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
            'fecha_curso'           => $fechaCurso,
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

        // Vincular a la sesión de acceso si el registro se hizo desde una sesión
        // abierta del inspector. Sin esta liga el participante existe pero no
        // aparece en la lista de la sesión, que se arma desde la tabla puente
        // (es la misma liga que crea el auto-registro por QR).
        $sesionId = (int)($payload['sesion_acceso_id'] ?? 0);
        if ($sesionId && $id) {
            try {
                $this->pdo->prepare(
                    "INSERT IGNORE INTO sesion_acceso_participantes (sesion_acceso_id, participante_id) VALUES (?,?)"
                )->execute([$sesionId, $id]);
            } catch (\Throwable $e) {
                error_log('[guardarParticipante] vincular sesión: ' . $e->getMessage());
            }
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

        if (!$qr) {
            // Auto-asignar el siguiente QR disponible del banco
            $qrRow = $this->pdo->query(
                "SELECT id, identificador FROM qr_codigos WHERE usado = 0 ORDER BY CAST(identificador AS UNSIGNED) LIMIT 1"
            )->fetch();
            if (!$qrRow) return ['status' => 'error', 'message' => 'Sin QR disponibles. Genera un lote en Códigos QR o captura el código manualmente.'];
            $qr = $qrRow['identificador'];
        } else {
            // Código capturado manualmente: puede no existir en el banco de
            // códigos pre-generados. Sólo se impide reutilizar uno ya asignado
            // a OTRO registro (se permite reusar el mismo del participante).
            $curr = $this->pdo->prepare("SELECT qr_codigo FROM participantes_cursos WHERE id = ?");
            $curr->execute([$id]);
            $mismoQr = (trim((string)$curr->fetchColumn()) === $qr);
            $stmtQR = $this->pdo->prepare("SELECT usado FROM qr_codigos WHERE identificador = ?");
            $stmtQR->execute([$qr]);
            $qrRow = $stmtQR->fetch();
            if ($qrRow && $qrRow['usado'] && !$mismoQr)
                return ['status' => 'error', 'message' => 'Ese código QR ya está en uso por otro registro.'];
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

        // Registrar el código como usado (lo inserta en el banco si no existía)
        qrRegistrarUsado($this->pdo, $qr);

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

        // Registrar el correo en el directorio para autocompletado futuro
        directorioCorreosRegistrar($this->pdo, [$correo], $empresa ?: null);

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
                // Si se subió un PDF manualmente para este participante, ese se envía.
                $urlDoc = $this->docManualPersonal((int)$p['id'], $t);
                if ($urlDoc === '') {
                    $res = $this->generarDocumento((int)$p['id'], $t, $usuario);
                    $urlDoc = ($res['status'] === 'success') ? ($res['url'] ?? '') : '';
                }
                if ($urlDoc !== '') {
                    $ruta = realpath(str_replace(UPLOAD_URL, UPLOAD_DIR, $urlDoc));
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
                if (array_key_exists('empresa_rep_trabajadores', $payload)) {
                    $cSets[]   = 'representante_trabajadores = ?';
                    $cParams[] = $payload['empresa_rep_trabajadores'] === '' ? null : trim($payload['empresa_rep_trabajadores']);
                }
                if (!empty($payload['empresa_logo'])) {
                    $logoVal = trim($payload['empresa_logo']);
                    // Accept base64 data URI (image/png, image/jpeg, etc.)
                    if (str_starts_with($logoVal, 'data:image/')) {
                        $cSets[]   = 'logo = ?';
                        $cParams[] = $logoVal;
                    }
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
        // Cambio de curso: validar que exista en el catálogo
        if (array_key_exists('curso_id', $payload)) {
            $cursoId = (int)($payload['curso_id'] ?? 0);
            if ($cursoId <= 0) return ['status' => 'error', 'message' => 'Selecciona un curso válido.'];
            $chkC = $this->pdo->prepare("SELECT id FROM cursos WHERE id = ?");
            $chkC->execute([$cursoId]);
            if (!$chkC->fetch()) return ['status' => 'error', 'message' => 'El curso seleccionado no existe.'];
            $sets[]   = "`curso_id` = ?";
            $params[] = $cursoId;
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
    public function emitirDocumentoPersonal(int $id, string $tipo, string|array $correoDestino, string $usuario, bool $enviarCredenciales = false): array {
        $this->ensureEstatusColumn();
        $resultado = $this->generarDocumento($id, $tipo, $usuario);
        if ($resultado['status'] !== 'success') return $resultado;

        // Marcar como emitido
        $this->pdo->prepare("UPDATE participantes_cursos SET estatus = 'EMITIDO' WHERE id = ?")
            ->execute([$id]);

        // Normalise destination list
        $p     = $this->obtenerParticipante($id);
        $lista = is_array($correoDestino) ? $correoDestino : [$correoDestino];
        $lista = array_values(array_unique(array_filter(array_map('trim', $lista))));
        if (!$lista) $lista = [trim($p['correo'] ?? '')];
        $lista = array_filter($lista, fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL));

        if ($lista) {
            $credenciales = [];
            if ($enviarCredenciales && $p) {
                $credenciales = $this->gestionarCredencialesParticipante($p, $lista[0]);
            }
            $this->enviarDocumento($id, $tipo, $lista, $usuario, $credenciales);
        }

        return $resultado;
    }

    /**
     * Genera (si hace falta) los 3 documentos del participante — DC-3, Diploma
     * y Certificado — y los envía TODOS en un solo correo con sus adjuntos.
     * Registra el/los correo(s) en el directorio para autocompletado futuro.
     */
    public function enviarTodosDocumentosPersonal(int $id, string|array $correoDestino, string $usuario, bool $enviarCredenciales = false): array {
        $this->ensureEstatusColumn();
        $p = $this->obtenerParticipante($id);
        if (!$p) return ['status' => 'error', 'message' => 'Participante no encontrado.'];

        // Normalizar destinatarios (cae al correo del participante si no se indica)
        $lista = is_array($correoDestino) ? $correoDestino : [$correoDestino];
        $lista = array_values(array_unique(array_filter(array_map('trim', $lista))));
        if (!$lista) $lista = [trim($p['correo'] ?? '')];
        $lista = array_values(array_filter($lista, fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
        if (!$lista) return ['status' => 'error', 'message' => 'Indica un correo de destino válido.'];

        if (!class_exists('PHPMailer\PHPMailer\PHPMailer'))
            return ['status' => 'error', 'message' => 'Servicio de correo no disponible en este servidor.'];

        // Generar cada documento y reunir sus rutas. Se incluye la Credencial
        // (los documentos que se "ven"); las versiones de impresión son el
        // mismo PDF, no un adjunto aparte.
        $tipos     = ['dc3' => 'Constancia DC-3', 'diploma' => 'Diploma', 'certificado' => 'Certificado', 'credencial' => 'Credencial'];
        $adjuntos  = [];
        $faltantes = [];
        foreach ($tipos as $tipo => $label) {
            // Si se subió un PDF manualmente, ese se envía; si no, se genera.
            $urlDoc = $this->docManualPersonal($id, $tipo);
            if ($urlDoc === '') {
                $gen = $this->generarDocumento($id, $tipo, $usuario);
                if ($gen['status'] !== 'success' || empty($gen['url'])) { $faltantes[] = $label; continue; }
                $urlDoc = $gen['url'];
            }
            $ruta  = str_replace(UPLOAD_URL, UPLOAD_DIR, $urlDoc);
            $real  = realpath($ruta);
            $rup   = realpath(UPLOAD_DIR);
            if ($real && $rup && strncmp($real, $rup, strlen($rup)) === 0) {
                $adjuntos[] = ['ruta' => $ruta, 'nombre' => basename($ruta)];
            } else {
                $faltantes[] = $label;
            }
        }
        if (!$adjuntos)
            return ['status' => 'error', 'message' => 'No se pudo generar ningún documento para enviar.'];

        // Marcar como emitido
        $this->pdo->prepare("UPDATE participantes_cursos SET estatus = 'EMITIDO' WHERE id = ?")->execute([$id]);

        // Credenciales opcionales (una sola vez, al primer destinatario)
        $credenciales = [];
        if ($enviarCredenciales) {
            $credenciales = $this->gestionarCredencialesParticipante($p, $lista[0]);
        }

        $nombre = $p['nombre_completo'] ?? 'Participante';
        try {
            $mail = new PHPMailer(true);
            configurarMailer($mail, $this->pdo);
            foreach ($lista as $e) $mail->addAddress($e);
            $mail->Subject = 'Documentos de Capacitación — AVBA Inspections';
            $mail->isHTML(true);
            $mail->Body    = $this->plantillaCorreoPersonal($nombre, 'DC-3, Diploma, Certificado y Credencial', $p['curso_nombre'] ?? '', $credenciales);
            foreach ($adjuntos as $a) $mail->addAttachment($a['ruta'], $a['nombre']);
            $mail->send();
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => 'Error al enviar correo: ' . $e->getMessage()];
        }

        // Registrar los correos en el directorio para consultas futuras
        directorioCorreosRegistrar($this->pdo, $lista, $nombre);

        $enviados = implode(', ', $lista);
        $msg = count($adjuntos) . ' documento(s) enviados a ' . $enviados . '.';
        if ($faltantes) $msg .= ' No se pudo incluir: ' . implode(', ', $faltantes) . '.';
        return ['status' => 'success', 'message' => $msg];
    }

    private function ensureMigration013Columns(): void {
        try {
            $cols = $this->pdo->query("SHOW COLUMNS FROM usuarios LIKE 'registro_stps'")->fetchAll();
            if (empty($cols)) {
                $this->pdo->exec("ALTER TABLE usuarios ADD COLUMN registro_stps VARCHAR(100) NULL");
            }
        } catch (\Throwable $e) {}
        try {
            $cols = $this->pdo->query("SHOW COLUMNS FROM clientes LIKE 'logo'")->fetchAll();
            if (empty($cols)) {
                $this->pdo->exec("ALTER TABLE clientes ADD COLUMN logo LONGTEXT NULL");
            }
        } catch (\Throwable $e) {}
        try {
            $cols = $this->pdo->query("SHOW COLUMNS FROM clientes LIKE 'representante_trabajadores'")->fetchAll();
            if (empty($cols)) {
                $this->pdo->exec("ALTER TABLE clientes ADD COLUMN representante_trabajadores VARCHAR(300) NULL");
            }
        } catch (\Throwable $e) {}
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

        // La CURP es opcional en el sistema (hay participantes que no la
        // traen), pero la columna nació NOT NULL: relajarla evita que el
        // registro reviente con un error interno.
        try {
            $this->pdo->exec("ALTER TABLE participantes_cursos MODIFY curp VARCHAR(18) NULL");
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

    private function ensureClientesOcupacionesTable(): void {
        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS clientes_ocupaciones (
                cliente_id   INT NOT NULL,
                ocupacion_id INT NOT NULL,
                orden        TINYINT NOT NULL DEFAULT 1,
                PRIMARY KEY (cliente_id, ocupacion_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (\Throwable $e) {}
    }

    // ══════════════════════════════════════════════════════
    //  CATÁLOGO DE EMPRESAS (para DC-3)
    // ══════════════════════════════════════════════════════

    public function listarEmpresas(): array {
        $this->ensureMigration013Columns();
        try {
            $this->pdo->exec("ALTER TABLE clientes
                ADD COLUMN IF NOT EXISTS rfc              VARCHAR(20)  DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS representante    VARCHAR(200) DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS direccion        VARCHAR(300) DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS correo_contacto  VARCHAR(200) DEFAULT NULL");
        } catch (\Throwable $e) {}

        $this->ensureClientesOcupacionesTable();

        $stmt = $this->pdo->query(
            "SELECT id, nombre_cliente, primera_parte,
                    COALESCE(rfc,'') AS rfc,
                    COALESCE(representante,'') AS representante,
                    COALESCE(representante_trabajadores,'') AS representante_trabajadores,
                    COALESCE(direccion,'') AS direccion,
                    COALESCE(correo_contacto,'') AS correo_contacto,
                    (logo IS NOT NULL AND logo <> '') AS tiene_logo
             FROM clientes ORDER BY nombre_cliente"
        );
        $rows = $stmt->fetchAll();

        if (!empty($rows)) {
            $ids = implode(',', array_map('intval', array_column($rows, 'id')));
            try {
                $ocStmt = $this->pdo->query(
                    "SELECT co.cliente_id, o.id AS ocupacion_id, o.nombre AS ocupacion_nombre
                     FROM clientes_ocupaciones co
                     JOIN ocupaciones_especificas o ON o.id = co.ocupacion_id
                     WHERE co.cliente_id IN ({$ids})
                     ORDER BY co.cliente_id, co.orden"
                );
                $ocMap = [];
                foreach ($ocStmt->fetchAll() as $oc) {
                    $ocMap[$oc['cliente_id']][] = [
                        'id'     => (int)$oc['ocupacion_id'],
                        'nombre' => $oc['ocupacion_nombre'],
                    ];
                }
            } catch (\Throwable $e) { $ocMap = []; }
            foreach ($rows as &$row) {
                $row['ocupaciones'] = $ocMap[$row['id']] ?? [];
            }
            unset($row);
        }
        return $rows;
    }

    public function guardarEmpresaDC3(array $payload): array {
        $this->ensureMigration013Columns();
        try {
            $this->pdo->exec("ALTER TABLE clientes
                ADD COLUMN IF NOT EXISTS rfc              VARCHAR(20)  DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS representante    VARCHAR(200) DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS direccion        VARCHAR(300) DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS correo_contacto  VARCHAR(200) DEFAULT NULL");
        } catch (\Throwable $e) {}

        $id      = (int)($payload['id'] ?? 0);
        $nombre  = strtoupper(trim($payload['nombre_cliente'] ?? ''));
        if (!$nombre) return ['status' => 'error', 'message' => 'El nombre de la empresa es obligatorio.'];

        $rfc     = strtoupper(preg_replace('/[^A-Z0-9&Ñ]/iu', '', $payload['rfc'] ?? '')) ?: null;
        $rep     = strtoupper(trim($payload['representante'] ?? '')) ?: null;
        $repTrab = strtoupper(trim($payload['representante_trabajadores'] ?? '')) ?: null;
        $dir     = strtoupper(trim($payload['direccion'] ?? '')) ?: null;
        $correo  = strtolower(trim($payload['correo_contacto'] ?? '')) ?: null;

        $logo = null;
        if (!empty($payload['logo']) && str_starts_with(trim($payload['logo']), 'data:image/')) {
            $logo = trim($payload['logo']);
        }

        if ($id) {
            // Assign primera_parte if the existing record has none
            $existing = $this->pdo->prepare("SELECT primera_parte FROM clientes WHERE id=?");
            $existing->execute([$id]);
            $exRow = $existing->fetch();
            $sets = 'nombre_cliente=?, rfc=?, representante=?, representante_trabajadores=?, direccion=?, correo_contacto=?';
            $vals = [$nombre, $rfc, $rep, $repTrab, $dir, $correo];
            if (empty($exRow['primera_parte'])) {
                $maxRow  = $this->pdo->query("SELECT MAX(CAST(primera_parte AS UNSIGNED)) AS max_id FROM clientes")->fetch();
                $primera = max((int)($maxRow['max_id'] ?? 45154), 45154) + 1;
                $sets .= ', primera_parte=?';
                $vals[] = $primera;
            }
            if ($logo !== null) { $sets .= ', logo=?'; $vals[] = $logo; }
            $vals[] = $id;
            $this->pdo->prepare("UPDATE clientes SET {$sets} WHERE id=?")->execute($vals);
        } else {
            $chk = $this->pdo->prepare("SELECT id FROM clientes WHERE UPPER(TRIM(nombre_cliente)) = UPPER(TRIM(?))");
            $chk->execute([$nombre]);
            if ($chk->fetch()) {
                return ['status' => 'error', 'message' => 'Ya existe una empresa con ese nombre.'];
            }
            $maxRow  = $this->pdo->query("SELECT MAX(CAST(primera_parte AS UNSIGNED)) AS max_id FROM clientes")->fetch();
            $primera = max((int)($maxRow['max_id'] ?? 45154), 45154) + 1;

            $this->pdo->prepare(
                "INSERT INTO clientes (nombre_cliente, primera_parte, rfc, representante,
                 representante_trabajadores, direccion, correo_contacto, logo) VALUES (?,?,?,?,?,?,?,?)"
            )->execute([$nombre, $primera, $rfc, $rep, $repTrab, $dir, $correo, $logo]);
            $id = (int)$this->pdo->lastInsertId();
        }

        // Sync occupations (up to 5)
        $this->ensureClientesOcupacionesTable();
        $rawIds  = $payload['ocupacion_ids'] ?? [];
        $ocupIds = array_slice(
            array_values(array_filter(
                array_map('intval', is_array($rawIds) ? $rawIds : []),
                fn($v) => $v > 0
            )),
            0, 5
        );
        try {
            $this->pdo->prepare("DELETE FROM clientes_ocupaciones WHERE cliente_id=?")->execute([$id]);
            foreach ($ocupIds as $ord => $oid) {
                $this->pdo->prepare(
                    "INSERT IGNORE INTO clientes_ocupaciones (cliente_id, ocupacion_id, orden) VALUES (?,?,?)"
                )->execute([$id, $oid, $ord + 1]);
            }
        } catch (\Throwable $e) {}

        return ['status' => 'success', 'id' => $id];
    }

    public function eliminarEmpresaDC3(int $id): array {
        $chk = $this->pdo->prepare("SELECT nombre_cliente FROM clientes WHERE id=?");
        $chk->execute([$id]);
        $row = $chk->fetch();
        if (!$row) return ['status' => 'error', 'message' => 'Empresa no encontrada.'];

        $nombre = $row['nombre_cliente'];
        foreach ([
            "SELECT COUNT(*) FROM equipos WHERE empresa_nombre = ? COLLATE utf8mb4_general_ci",
            "SELECT COUNT(*) FROM participantes_cursos WHERE empresa_nombre = ? COLLATE utf8mb4_general_ci",
        ] as $sql) {
            try {
                $s = $this->pdo->prepare($sql);
                $s->execute([$nombre]);
                if ($s->fetchColumn() > 0)
                    return ['status' => 'error', 'message' => 'No se puede eliminar: la empresa tiene registros vinculados.'];
            } catch (\Throwable $e) {}
        }

        $this->pdo->prepare("DELETE FROM clientes WHERE id=?")->execute([$id]);
        return ['status' => 'success'];
    }

    public function listarUsuariosInstructor(): array {
        $stmt = $this->pdo->query(
            "SELECT usuario, COALESCE(nombre, usuario) AS nombre, rol
             FROM usuarios WHERE rol = 'INSPECTOR' ORDER BY nombre"
        );
        return ['status' => 'success', 'data' => $stmt->fetchAll()];
    }

    public function cambiarInstructorSesion(array $payload): array {
        $ids = array_values(array_filter(
            array_map('intval', $payload['ids'] ?? []),
            fn($v) => $v > 0
        ));
        $instructor = trim($payload['instructor'] ?? '');
        if (empty($ids))  return ['status' => 'error', 'message' => 'No hay participantes seleccionados.'];
        if (!$instructor) return ['status' => 'error', 'message' => 'Selecciona un instructor.'];

        $chk = $this->pdo->prepare("SELECT usuario FROM usuarios WHERE usuario = ?");
        $chk->execute([$instructor]);
        if (!$chk->fetch()) return ['status' => 'error', 'message' => 'El instructor seleccionado no existe.'];

        $ph = implode(',', array_fill(0, count($ids), '?'));
        $this->pdo->prepare(
            "UPDATE participantes_cursos SET usuario_registro=? WHERE id IN ({$ph})"
        )->execute([$instructor, ...$ids]);

        return ['status' => 'success', 'message' => count($ids) . ' participante(s) actualizados.'];
    }

    // ── Cambiar el curso de todos los participantes de una sesión ──
    public function cambiarCursoSesion(array $payload): array {
        $ids = array_values(array_filter(
            array_map('intval', $payload['ids'] ?? []),
            fn($v) => $v > 0
        ));
        $cursoId = (int)($payload['curso_id'] ?? 0);
        if (empty($ids))    return ['status' => 'error', 'message' => 'No hay participantes en la sesión.'];
        if ($cursoId <= 0)  return ['status' => 'error', 'message' => 'Selecciona un curso válido.'];

        $chk = $this->pdo->prepare("SELECT id FROM cursos WHERE id = ?");
        $chk->execute([$cursoId]);
        if (!$chk->fetch()) return ['status' => 'error', 'message' => 'El curso seleccionado no existe.'];

        $ph = implode(',', array_fill(0, count($ids), '?'));
        $this->pdo->prepare(
            "UPDATE participantes_cursos SET curso_id=? WHERE id IN ({$ph})"
        )->execute([$cursoId, ...$ids]);

        return ['status' => 'success', 'message' => 'Curso actualizado en ' . count($ids) . ' participante(s).'];
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

    public function generarDocumento(int $id, string $tipo, string $usuario, bool $sinSellos = false): array {
        try {
            $p = $this->obtenerParticipante($id);
        } catch (\Throwable $e) {
            error_log('[AVBA] obtenerParticipante error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            return ['status' => 'error', 'message' => 'Error al cargar datos del participante: ' . $e->getMessage()];
        }
        if (!$p) return ['status' => 'error', 'message' => 'Participante no encontrado.'];

        $tiposValidos = ['dc3', 'diploma', 'certificado', 'credencial'];
        if (!in_array($tipo, $tiposValidos, true))
            return ['status' => 'error', 'message' => 'Tipo de documento no valido.'];

        // Si hay un PDF reemplazado manualmente para este documento, ese manda
        $manual = $this->docManualPersonal($id, $tipo);
        if ($manual !== '') return ['status' => 'success', 'url' => $manual];

        if (!class_exists('Dompdf\Dompdf')) {
            return ['status' => 'error', 'message' => 'Dompdf no disponible. Instala las dependencias del proyecto.'];
        }

        // QR generado internamente (sin red) para incrustarlo en el PDF
        $qrB64 = '';
        if (!empty($p['qr_codigo']) && defined('SITE_URL')) {
            $qrB64 = qrDataUri(textoQR($p['qr_codigo']), 120, 2);
        }

        try {
            $html = match($tipo) {
                'dc3'         => $this->htmlDC3($p, $qrB64),
                'certificado' => $this->htmlCertificado($p, $qrB64, $sinSellos),
                'credencial'  => $this->htmlCredencial($p, $qrB64),
                default       => $this->htmlDiploma($p, $qrB64),
            };
            $folio = 'PART-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
            if ($tipo === 'diploma') {
                $url = $this->htmlToPdfMpdf($html, $folio, 'DIPLOMA', 'A4-L');
            } elseif ($tipo === 'certificado') {
                $url = $this->htmlToPdfMpdf($html, $folio, 'CERT', 'A4');
            } elseif ($tipo === 'credencial') {
                $url = $this->htmlToPdfMpdf($html, $folio, 'CRED', [86, 133]);
            } else {
                $url = $this->htmlToPdfMpdf($html, $folio, 'DC3', 'Letter');
            }
        } catch (\Throwable $e) {
            error_log('[AVBA] generarDocumento error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            return ['status' => 'error', 'message' => 'Error generando documento: ' . $e->getMessage()];
        }

        $this->pdo->prepare(
            "INSERT INTO participantes_documentos (participante_id, tipo_doc, url, generado_por)
             VALUES (?, ?, ?, ?)"
        )->execute([$id, strtoupper($tipo), $url, $usuario]);

        return ['status' => 'success', 'url' => $url];
    }

    // ── Reemplazo manual de documentos de personal ────────────
    private function ensureDocManualTablePersonal(): void {
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS participantes_doc_manual (
                  id              INT AUTO_INCREMENT PRIMARY KEY,
                  participante_id INT NOT NULL,
                  tipo            VARCHAR(20) NOT NULL,
                  archivo_url     VARCHAR(300) NOT NULL,
                  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  UNIQUE KEY uniq_part_tipo (participante_id, tipo)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\Throwable $e) { /* ya existe */ }
    }

    /** Devuelve la URL del PDF manual de un documento del participante, o ''. */
    private function docManualPersonal(int $id, string $tipo): string {
        try {
            $this->ensureDocManualTablePersonal();
            $s = $this->pdo->prepare("SELECT archivo_url FROM participantes_doc_manual WHERE participante_id=? AND tipo=?");
            $s->execute([$id, strtolower($tipo)]);
            $rel = (string)($s->fetchColumn() ?: '');
        } catch (\Throwable $e) { return ''; }
        if ($rel === '') return '';
        $abs = __DIR__ . '/../' . ltrim($rel, '/');
        return is_file($abs) ? $rel : '';
    }

    /**
     * Sustituye manualmente un documento del participante (DC-3, diploma,
     * certificado o credencial) con un PDF subido. El archivo manual pasa a ser
     * el que se ve, se imprime, se emite y ve el cliente.
     */
    public function subirDocumentoManualPersonal(array $payload, array $file, string $usuario): array {
        $id   = (int)($payload['id'] ?? 0);
        $tipo = strtolower(trim((string)($payload['tipo'] ?? '')));
        $tiposValidos = ['dc3', 'diploma', 'certificado', 'credencial'];
        if (!$id) return ['status' => 'error', 'message' => 'ID de participante requerido.'];
        if (!in_array($tipo, $tiposValidos, true)) return ['status' => 'error', 'message' => 'Tipo de documento no válido.'];

        $p = $this->obtenerParticipante($id);
        if (!$p) return ['status' => 'error', 'message' => 'Participante no encontrado.'];

        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return ['status' => 'error', 'message' => 'Selecciona un archivo PDF válido.'];
        }
        $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        if ($ext !== 'pdf') return ['status' => 'error', 'message' => 'El documento debe ser un PDF.'];
        if (($file['size'] ?? 0) > 20 * 1024 * 1024) return ['status' => 'error', 'message' => 'El PDF no debe superar 20 MB.'];

        $dir = UPLOAD_DIR . 'personal/docs/';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $nombre  = strtoupper($tipo) . '_MANUAL_PART' . str_pad((string)$id, 5, '0', STR_PAD_LEFT) . '.pdf';
        $destino = $dir . $nombre;
        if (!move_uploaded_file($file['tmp_name'], $destino)) {
            return ['status' => 'error', 'message' => 'No se pudo guardar el PDF.'];
        }

        $rel = 'uploads/personal/docs/' . $nombre;
        $this->ensureDocManualTablePersonal();
        $this->pdo->prepare("
            INSERT INTO participantes_doc_manual (participante_id, tipo, archivo_url)
            VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE archivo_url=VALUES(archivo_url)
        ")->execute([$id, $tipo, $rel]);

        // Reflejarlo en la lista de documentos del participante
        $this->pdo->prepare(
            "INSERT INTO participantes_documentos (participante_id, tipo_doc, url, generado_por)
             VALUES (?, ?, ?, ?)"
        )->execute([$id, strtoupper($tipo), $rel, $usuario]);

        return ['status' => 'success', 'message' => ucfirst($tipo) . ' reemplazado correctamente.', 'url' => $rel];
    }

    public function generarCredencialesLote(array $payload, string $usuario): array {
        $ids = array_filter(array_map('intval', $payload['ids'] ?? []), fn($v) => $v > 0);
        if (!$ids) return ['status' => 'error', 'message' => 'Sin participantes.'];

        $bodyParts = [];
        foreach ($ids as $id) {
            $p = $this->obtenerParticipante($id);
            if (!$p) continue;
            if (!in_array($p['estatus'] ?? '', ['APROBADO_CALIDAD', 'EMITIDO'], true)) continue;

            $qrB64 = '';
            if (!empty($p['qr_codigo']) && defined('SITE_URL')) {
                $qrB64 = qrDataUri(textoQR($p['qr_codigo']), 120, 2);
            }
            $bodyParts[] = $this->htmlCredencialBody($p, $qrB64);
        }

        if (!$bodyParts) return ['status' => 'error', 'message' => 'Sin participantes aprobados en la selección.'];

        $body  = implode("\n<pagebreak />\n", $bodyParts);
        $html  = $this->wrapCredencialHtml($body);
        $folio = 'LOTE-' . date('YmdHis');
        try {
            $url = $this->htmlToPdfMpdf($html, $folio, 'CRED', [86, 133]);
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => 'Error generando PDF: ' . $e->getMessage()];
        }

        return ['status' => 'success', 'url' => $url];
    }

    // ── Generar credencial física individual (anverso + reverso) ─────────
    public function generarCredencial(int $id, string $usuario): array {
        $p = $this->obtenerParticipante($id);
        if (!$p) return ['status' => 'error', 'message' => 'Participante no encontrado.'];

        if (empty($p['qr_codigo'])) {
            return ['status' => 'error', 'message' => 'El participante no tiene código QR. Apruébalo en Calidad primero.'];
        }

        $qrB64 = '';
        if (defined('SITE_URL')) {
            $qrB64 = qrDataUri(textoQR($p['qr_codigo']), 200, 2);
        }

        try {
            $html  = $this->htmlCredencial($p, $qrB64);
            $folio = 'CRED-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
            $url   = $this->htmlToPdfMpdf($html, $folio, 'CRED', [86, 133]);
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => 'Error generando credencial: ' . $e->getMessage()];
        }

        try {
            $this->pdo->exec("ALTER TABLE participantes_cursos ADD COLUMN IF NOT EXISTS credencial_url VARCHAR(500) NULL");
        } catch (\Throwable $ignored) {}
        $this->pdo->prepare("UPDATE participantes_cursos SET credencial_url = ? WHERE id = ?")
                  ->execute([$url, $id]);

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

    public function enviarDocumento(int $id, string $tipo, string|array $correoDestino, string $usuario, array $credenciales = []): array {
        $p = $this->obtenerParticipante($id);
        if (!$p) return ['status' => 'error', 'message' => 'Participante no encontrado.'];

        // Normalise to array, falling back to participant's own email
        $lista = is_array($correoDestino) ? $correoDestino : [$correoDestino];
        $lista = array_values(array_unique(array_filter(array_map('trim', $lista))));
        if (!$lista) $lista = [trim($p['correo'] ?? '')];
        $lista = array_filter($lista, fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL));
        if (!$lista)
            return ['status' => 'error', 'message' => 'Correo de destino inválido o no registrado.'];

        // Si hay un PDF reemplazado manualmente, ese es el que se envía.
        // Si no, se toma el último documento generado de ese tipo.
        $urlDoc = $this->docManualPersonal($id, $tipo);
        if ($urlDoc === '') {
            $stmt = $this->pdo->prepare(
                "SELECT url FROM participantes_documentos
                 WHERE participante_id = ? AND tipo_doc = ?
                 ORDER BY fecha_generacion DESC LIMIT 1"
            );
            $stmt->execute([$id, strtoupper($tipo)]);
            $doc = $stmt->fetch();
            if (!$doc) return ['status' => 'error', 'message' => 'Genera el documento primero antes de enviarlo.'];
            $urlDoc = $doc['url'];
        }

        // Convertir URL a ruta local y validar path traversal
        $rutaArchivo = str_replace(UPLOAD_URL, UPLOAD_DIR, $urlDoc);
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
            foreach ($lista as $e) $mail->addAddress($e);
            $mail->Subject    = "{$tipoLabel} de Capacitación — AVBA Inspections";
            $mail->isHTML(true);
            $mail->Body       = $this->plantillaCorreoPersonal($nombre, $tipoLabel, $p['curso_nombre'] ?? '', $credenciales);
            $mail->addAttachment($rutaArchivo, basename($rutaArchivo));
            $mail->send();

            $enviados = implode(', ', $lista);
            return ['status' => 'success', 'message' => "Documento enviado a {$enviados}."];
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

    // Elimina acentos y caracteres especiales para compatibilidad con Dompdf
    private function sinAcentos(string $s): string {
        return str_replace(
            ['á','é','í','ó','ú','ü','ñ','Á','É','Í','Ó','Ú','Ü','Ñ','¿','¡'],
            ['a','e','i','o','u','u','n','A','E','I','O','U','U','N','',''],
            $s
        );
    }

    // Carga un asset como data URI base64 para incrustar en HTML/Dompdf
    private function assetB64(string $rel): string {
        $path = dirname(__DIR__) . '/assets/' . ltrim($rel, '/');
        if (!file_exists($path)) return '';
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = match($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'gif'         => 'image/gif',
            default       => 'image/png',
        };
        return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($path));
    }

    private function assetB64OrPath(string $path): string {
        if (!file_exists($path)) return '';
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = match($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'gif'         => 'image/gif',
            default       => 'image/png',
        };
        return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($path));
    }

    /**
     * Crop image to target ratio then resize to target px dimensions.
     * Crops from top-center so face stays visible in portrait photos.
     */
    private function cropAndResizeB64(string $base64, float $wMm, float $hMm, int $dstW, int $dstH, bool $keepPng = false): string {
        if (!function_exists('imagecreatetruecolor')) return $base64;
        if (!preg_match('/^data:(image\/\w+);base64,(.+)$/s', $base64, $m)) return $base64;
        $src = imagecreatefromstring(base64_decode($m[2]));
        if (!$src) return $base64;

        $sw = imagesx($src); $sh = imagesy($src);
        $targetRatio = $wMm / $hMm;   // e.g. 30/35 = 0.857
        $srcRatio    = $sw / $sh;

        if ($srcRatio > $targetRatio) {
            // Image too wide — crop sides
            $cropH = $sh;
            $cropW = (int)round($sh * $targetRatio);
            $cropX = (int)round(($sw - $cropW) / 2);
            $cropY = 0;
        } else {
            // Image too tall — crop bottom (keep top = face)
            $cropW = $sw;
            $cropH = (int)round($sw / $targetRatio);
            $cropX = 0;
            $cropY = 0;
        }

        $dst = imagecreatetruecolor($dstW, $dstH);
        if ($keepPng) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        }
        imagecopyresampled($dst, $src, 0, 0, $cropX, $cropY, $dstW, $dstH, $cropW, $cropH);
        imagedestroy($src);

        ob_start();
        if ($keepPng) { imagepng($dst, null, 6); }
        else          { imagejpeg($dst, null, 85); }
        $data = ob_get_clean();
        imagedestroy($dst);

        return 'data:' . ($keepPng ? 'image/png' : 'image/jpeg') . ';base64,' . base64_encode($data);
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

    private function htmlDC3(array $p, string $qrB64 = ''): string {
        $esc = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');

        // ── Datos ──────────────────────────────────────────────────────────
        $up = fn($s) => mb_strtoupper(trim((string)$s), 'UTF-8');
        $nombre    = $up($p['nombre_completo'] ?? '');
        $curp      = strtoupper(trim($p['curp'] ?? ''));
        $puesto    = $up($p['puesto'] ?? '');
        $empresa   = $up($p['empresa_nombre'] ?? '');
        $rfcRaw    = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $p['empresa_rfc'] ?? ''));
        $patron    = $up($p['empresa_representante'] ?? '');
        $repTrab   = $up($p['empresa_rep_trabajadores'] ?? '');
        $curso     = $up($p['curso_nombre'] ?? '');
        $horasVal  = (float)($p['duracion_horas'] ?? 0);
        $horas     = $horasVal > 0 ? (string)(int)$horasVal : trim($p['duracion_horas'] ?? '');
        $area      = trim($p['area_tematica'] ?? '');
        $fechaYmd  = $p['fecha_curso'] ?? null;

        // Ocupaciones específicas de la empresa
        $ocupEmpresa = $p['ocupaciones_empresa'] ?? [];
        // Fallback: ocupación individual del participante
        if (empty($ocupEmpresa) && !empty($p['ocupacion_nombre'])) {
            $ocupEmpresa = [trim($p['ocupacion_nombre'])];
        }
        $ocupacionHtml = implode('<br>', array_map(fn($o) => $esc($o), $ocupEmpresa));

        // Calcular fecha fin: 8 h/día máximo
        $fechaFinYmd = $fechaYmd;
        if ($fechaYmd && $horasVal > 0) {
            $diasExtra = max(0, (int)ceil($horasVal / 8) - 1);
            $fechaFinYmd = date('Y-m-d', strtotime($fechaYmd . " +{$diasExtra} days"));
        }

        // Instructor
        $instrNombre = trim($p['instructor_nombre'] ?? '');
        $instrStps   = trim($p['instructor_stps'] ?? '');
        $instrFirmaPath = trim($p['instructor_firma_imagen'] ?? '');

        // Agente: muestra el contenido exacto del registro_stps; si no hay, el nombre del instructor
        $agente = $instrStps
            ? strtoupper($instrStps)
            : ($instrNombre ? $up($instrNombre) : 'AVBA CERTIFICACIONES');

        // RFC con guiones para mostrar (LLLL-YYMMDD-XXX)
        $rfcFmt = $rfcRaw;
        if (preg_match('/^([A-Z]{3,4})(\d{6})([A-Z0-9]{2,3})$/', $rfcRaw, $m)) {
            $rfcFmt = $m[1] . '-' . $m[2] . '-' . $m[3];
        }

        // ── Assets ──────────────────────────────────────────────────────────
        $logoB64  = $this->assetB64('logos/avba.png');
        $selloB64 = $this->assetB64('sellos/sello.png');

        // Firma del instructor: usa la propia si existe, sino la del director
        $instrFirmaB64 = '';
        if ($instrFirmaPath) {
            $absPath = dirname(__DIR__) . '/' . ltrim($instrFirmaPath, '/');
            if (file_exists($absPath)) {
                $ext  = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
                $mime = in_array($ext, ['jpg','jpeg']) ? 'image/jpeg' : 'image/png';
                $instrFirmaB64 = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($absPath));
            }
        }
        if (!$instrFirmaB64) {
            $instrFirmaB64 = $this->assetB64('logos/firma_director.png');
        }

        $logoHdr   = $logoB64
            ? "<img src=\"{$logoB64}\" style=\"width:104px;height:48px;display:block;margin:0 auto;\" alt=\"AVBA\">"
            : '<div style="font-size:8pt;font-weight:bold;color:#1B2A6B;text-align:center">AVBA<br>CERT.</div>';
        $qrHdr     = $qrB64
            ? "<img src=\"{$qrB64}\" style=\"width:68px;height:68px;\" alt=\"QR\">"
            : '<div style="font-size:6pt;color:#aaa;text-align:center">QR</div>';

        $logoEmpB64  = $p['empresa_logo_b64'] ?? '';
        $logoEmpHtml = $logoEmpB64
            ? "<img src=\"{$logoEmpB64}\" style=\"max-width:90px;max-height:45px;display:block;margin:0 auto;\" alt=\"Empresa\">"
            : '';
        $firmaHtml = $instrFirmaB64
            ? "<img src=\"{$instrFirmaB64}\" style=\"height:44px;max-width:130px;display:block;margin:0 auto 2px;\" alt=\"Firma\">"
            : '<div style="height:44px;"></div>';
        $selloHtml = $selloB64
            ? "<img src=\"{$selloB64}\" style=\"width:58px;height:52px;display:block;margin:0 auto;\" alt=\"Sello\">"
            : '';

        // ── Generadores de cajas de caracteres ─────────────────────────────
        $cellStyle = 'width:15px;height:23px;border:1.5px solid #333;text-align:center;'
                   . 'font-size:9pt;font-weight:bold;padding:0;vertical-align:middle';

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

        // Campo de fecha completo: prefijo (De/a) + etiquetas Año/Mes/Día
        // perfectamente alineadas sobre los grupos de cajas (4+2+2).
        $dateField = function(string $pre, ?string $ymd) use ($boxes): string {
            [$y, $mo, $d] = ['    ', '  ', '  '];
            if ($ymd && preg_match('/(\d{4})-(\d{2})-(\d{2})/', $ymd, $mt)) {
                [$y, $mo, $d] = [$mt[1], $mt[2], $mt[3]];
            }
            $hdr = 'font-size:6.5pt;color:#555;text-align:center;padding:0 0 2px;letter-spacing:.5px';
            $gap = '<td style="width:7px"></td>';
            return '<table style="border-collapse:collapse;margin:0 auto">'
                 . '<tr>'
                 .   '<td rowspan="2" style="font-size:9pt;font-weight:bold;color:#1B2A6B;'
                 .     'padding-right:8px;vertical-align:middle;text-align:right;width:18px">' . $pre . '</td>'
                 .   '<td colspan="4" style="' . $hdr . '">Año</td>'
                 .   '<td></td>'
                 .   '<td colspan="2" style="' . $hdr . '">Mes</td>'
                 .   '<td></td>'
                 .   '<td colspan="2" style="' . $hdr . '">Día</td>'
                 . '</tr>'
                 . '<tr>'
                 .   $boxes($y) . $gap . $boxes($mo) . $gap . $boxes($d)
                 . '</tr></table>';
        };

        // ── Estilo base mPDF ───────────────────────────────────────────────
        $css = <<<'CSS'
@page { margin: 8mm 10mm; }
* { margin:0; padding:0; }
body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 9.5pt; color: #111; }
.doc { width:100%; border-collapse:collapse; border:2px solid #1B2A6B; }
.doc td, .doc th { border:1px solid #8090b8; padding:0; vertical-align:top; }
.doc table td, .doc table th { border:none; padding:0; }
.sec-hdr td { background:#1B2A6B; color:#fff; text-align:center; font-weight:bold;
               font-size:9pt; letter-spacing:1.5px; padding:6px; border-color:#1B2A6B; }
.lbl { font-size:7pt; color:#555; padding:6px 14px 2px; }
.val { font-weight:bold; font-size:10pt; padding:3px 14px 7px; }
.val-inline { padding:6px 14px; }
.instruct { font-size:7.5pt; line-height:1.6; padding:9px 16px; }
.sig-line { border-top:1px solid #333; margin:0 6px 4px; }
.sig-name { font-size:8pt; font-weight:bold; line-height:1.2; word-wrap:break-word;
            overflow-wrap:break-word; word-break:break-word; }
.sig-role { font-size:7.5pt; color:#1B2A6B; font-weight:bold; margin-top:2px; }
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

        // Tamaño de fuente de las firmas según largo del nombre (evita romper el formato)
        $sigSize = function(string $n): string {
            $len = mb_strlen(trim($n));
            if ($len <= 26) return '8pt';
            if ($len <= 38) return '7.5pt';
            if ($len <= 50) return '7pt';
            return '6.5pt';
        };
        $instrDisplay = $instrNombre ?: 'Ing. Jose Marcos Gonzalez Calderon';

        $anverso = <<<HTML
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">
<style>{$css}</style></head><body>

<!-- TÍTULO -->
<table class="doc" style="margin-bottom:0">
  <tr>
    <td style="width:15%;padding:6px;border-right:1px solid #8090b8;vertical-align:middle;text-align:center">
      {$logoHdr}
      <div style="font-size:5pt;color:#1B2A6B;margin-top:3px;font-weight:bold;line-height:1.3;white-space:nowrap">AVBA CERTIFICACIONES</div>
    </td>
    <td style="text-align:center;padding:16px 8px;border-right:1px solid #8090b8;vertical-align:middle">
      <div style="font-size:14pt;font-weight:bold;color:#1B2A6B;letter-spacing:1px">FORMATO DC-3</div>
      <div style="font-size:9.5pt;font-weight:bold;margin-top:3px">CONSTANCIA DE COMPETENCIAS O DE HABILIDADES LABORALES</div>
    </td>
    <td style="width:14%;padding:6px;text-align:center;vertical-align:middle">
      {$logoEmpHtml}
      {$qrHdr}
    </td>
  </tr>
</table>

<!-- DATOS DEL TRABAJADOR -->
<table class="doc" style="margin-top:0;border-top:none">
  <tr class="sec-hdr"><td colspan="2"><b>DATOS DEL TRABAJADOR</b></td></tr>

  <!-- Nombre -->
  <tr>
    <td colspan="2" style="padding:0">
      <div class="lbl">Nombre (Anotar apellido paterno, apellido materno y nombre(s))</div>
      <div class="val">{$esc($nombre)}</div>
    </td>
  </tr>

  <!-- CURP + Ocupación -->
  <tr>
    <td style="width:54%;border-right:1px solid #8090b8;padding:0">
      <div class="lbl">Clave Unica de Registro de Poblacion</div>
      <div class="val-inline">{$boxRow($curpPad)}</div>
    </td>
    <td style="padding:0">
      <div class="lbl">Ocupacion especifica (Catalogo Nacional de Ocupaciones) <sup>1/</sup></div>
      <div class="val" style="font-size:9.5pt;line-height:1.5">{$ocupacionHtml}</div>
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
<table class="doc" style="margin-top:0;border-top:none">
  <tr class="sec-hdr"><td colspan="2"><b>DATOS DE LA EMPRESA</b></td></tr>

  <tr>
    <td colspan="2" style="padding:0">
      <div class="lbl">Nombre o razon social (En caso de persona fisica, anotar apellido paterno, apellido materno y nombre(s))</div>
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
<table class="doc" style="margin-top:0;border-top:none">
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
    <td style="width:26%;border-right:1px solid #8090b8;padding:8px 10px;vertical-align:middle;text-align:center">
      <div class="lbl" style="text-align:center;padding:0 0 3px">Duración en horas</div>
      <div style="font-size:15pt;font-weight:bold;color:#1B2A6B;line-height:1.05">{$esc($horas)}</div>
      <div style="font-size:7.5pt;font-weight:bold;color:#555;letter-spacing:1px">HORAS</div>
    </td>
    <td colspan="2" style="padding:8px 14px;vertical-align:middle">
      <div class="lbl" style="padding:0 0 7px;text-align:center">Periodo de ejecución</div>
      <table style="border-collapse:collapse;margin:0 auto">
        <tr>
          <td style="padding:0 20px;vertical-align:middle">{$dateField('De', $fechaYmd)}</td>
          <td style="padding:0 20px;vertical-align:middle;border-left:1px solid #cdd5e6">{$dateField('a', $fechaFinYmd)}</td>
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
<table class="doc" style="margin-top:0;border-top:none">
  <tr>
    <td colspan="3" style="padding:8px 12px;font-size:8pt;text-align:center;background:#f0f3fa;line-height:1.5">
      Los datos se asientan en esta constancia bajo protesta de decir verdad, apercibidos de la responsabilidad en que incurre todo
      <b>aquel que no se conduce con verdad.</b>
    </td>
  </tr>
  <tr>
    <!-- Col 1: Instructor — firma + sello -->
    <td style="width:33%;border-right:1px solid #8090b8;border-top:none;text-align:center;padding:10px 10px 13px;vertical-align:bottom">
      <table style="border-collapse:collapse;margin:0 auto 4px"><tr>
        <td style="padding:0 6px;vertical-align:bottom">{$firmaHtml}</td>
        <td style="padding:0 6px;vertical-align:bottom">{$selloHtml}</td>
      </tr></table>
      <div class="sig-line"></div>
      <div class="sig-name" style="font-size:{$sigSize($instrDisplay)}">{$esc($instrDisplay)}</div>
      <div class="sig-role">Instructor o tutor</div>
    </td>
    <!-- Col 2: Patron -->
    <td style="width:34%;border-right:1px solid #8090b8;border-top:none;text-align:center;padding:10px 10px 13px;vertical-align:bottom">
      <div style="height:60px"></div>
      <div class="sig-line"></div>
      <div class="sig-name" style="font-size:{$sigSize($patron)}">{$esc($patron)}</div>
      <div class="sig-role">Patron o representante legal <sup>4/</sup></div>
    </td>
    <!-- Col 3: Representante de trabajadores -->
    <td style="width:33%;border-top:none;text-align:center;padding:10px 10px 13px;vertical-align:bottom">
      <div style="height:60px"></div>
      <div class="sig-line"></div>
      <div class="sig-name" style="font-size:{$sigSize($repTrab)}">{$esc($repTrab)}</div>
      <div class="sig-role">Representante de los trabajadores <sup>5/</sup></div>
    </td>
  </tr>
</table>

<!-- INSTRUCCIONES -->
<table class="doc" style="margin-top:0;border-top:none">
  <tr>
    <td class="instruct">
      <b>INSTRUCCIONES</b><br>
      &nbsp;- Llenar a maquina o con letra de molde.<br>
      &nbsp;- Debera entregarse al trabajador dentro de los veinte dias habiles siguientes al termino del curso de capacitacion aprobado.<br>
      <sup>1/</sup> Las areas y subareas ocupacionales del Catalogo Nacional de Ocupaciones se encuentran disponibles en el reverso de este formato y en la pagina www.stps.gob.mx<br>
      <sup>2/</sup> Las areas tematicas de los cursos se encuentran disponibles en el reverso de este formato y en la pagina www.stps.gob.mx<br>
      <sup>3/</sup> Cursos impartidos por el area competente de la Secretaria del Trabajo y Prevision Social.<br>
      <sup>4/</sup> Para empresas con menos de 51 trabajadores. Para empresas con mas de 50 trabajadores firmaria el representante del patron ante la Comision mixta de capacitacion, adiestramiento y productividad.<br>
      <sup>5/</sup> Solo para empresas con mas de 50 trabajadores.<br>
      * Dato no obligatorio.
    </td>
  </tr>
</table>

<!-- PIE: razón social y contacto -->
<table class="doc" style="margin-top:0;border-top:none">
  <tr>
    <td style="padding:11px 14px;text-align:center;background:#1B2A6B;border-color:#1B2A6B">
      <div style="font-size:8.5pt;font-weight:bold;color:#fff;letter-spacing:.5px">AVBA INSPECTIONS, CERTIFICATIONS AND MAINTENANCE S.A.S. DE C.V.</div>
      <div style="font-size:7.5pt;color:#cdd6ef;margin-top:4px">www.avba.com.mx &nbsp;&nbsp;·&nbsp;&nbsp; certificaciones@avba.com.mx</div>
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
  <div style="background:#1B2A6B;color:#fff;padding:4px 6px;margin-bottom:6px;font-size:7pt;font-weight:bold;letter-spacing:0.5px;">
    AVBA CERTIFICACIONES &nbsp;·&nbsp; FORMATO DC-3 — REVERSO
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

    // ── CREDENCIAL PVC ──────────────────────────────────────────────────────
    private function wrapCredencialHtml(string $body): string {
        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family: DejaVu Sans, Arial, sans-serif; background:transparent; color:#fff; }
</style>
</head>
<body>
{$body}
</body>
</html>
HTML;
    }

    private function htmlCredencial(array $p, string $qrB64 = ''): string {
        return $this->wrapCredencialHtml($this->htmlCredencialBody($p, $qrB64));
    }

    private function fotoBase64WithExif(string $fotoPath): string {
        if (!file_exists($fotoPath)) return '';
        $ext = strtolower(pathinfo($fotoPath, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg']) && function_exists('exif_read_data') && function_exists('imagecreatefromjpeg')) {
            $exif = @exif_read_data($fotoPath);
            $orientation = $exif['Orientation'] ?? 1;
            if ($orientation > 1) {
                $img = @imagecreatefromjpeg($fotoPath);
                if ($img) {
                    switch ($orientation) {
                        case 3: $img = imagerotate($img, 180, 0); break;
                        case 6: $img = imagerotate($img, -90, 0); break;
                        case 8: $img = imagerotate($img, 90, 0); break;
                    }
                    ob_start();
                    imagejpeg($img, null, 92);
                    $data = ob_get_clean();
                    imagedestroy($img);
                    return 'data:image/jpeg;base64,' . base64_encode($data);
                }
            }
        }
        $mime = ($ext === 'png') ? 'image/png' : 'image/jpeg';
        return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($fotoPath));
    }

    /**
     * Resize a base64 image to max dimensions (maintains aspect ratio).
     * PNG backgrounds at 1054x1492 become ~6MB base64; at display size (325x503) they're ~0.5MB.
     */
    private function resizeImageB64(string $base64, int $maxW, int $maxH, bool $keepPng = false): string {
        if (!function_exists('imagecreatetruecolor')) return $base64;
        if (!preg_match('/^data:(image\/\w+);base64,(.+)$/s', $base64, $m)) return $base64;
        $raw = base64_decode($m[2]);
        $src = imagecreatefromstring($raw);
        if (!$src) return $base64;

        $sw = imagesx($src); $sh = imagesy($src);
        if ($sw <= $maxW && $sh <= $maxH) { imagedestroy($src); return $base64; }

        $scale = min($maxW / $sw, $maxH / $sh);
        $dw = (int)round($sw * $scale);
        $dh = (int)round($sh * $scale);

        $dst = imagecreatetruecolor($dw, $dh);
        if ($keepPng) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $t = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefill($dst, 0, 0, $t);
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $dw, $dh, $sw, $sh);
        imagedestroy($src);

        ob_start();
        if ($keepPng) { imagepng($dst, null, 6); }
        else          { imagejpeg($dst, null, 82); }
        $data = ob_get_clean();
        imagedestroy($dst);

        $mime = $keepPng ? 'image/png' : 'image/jpeg';
        return 'data:' . $mime . ';base64,' . base64_encode($data);
    }

    /**
     * Apply rounded corners to a base64 image using PHP GD.
     * mPDF ignores border-radius on position:absolute elements, so we bake it into the image.
     */
    private function addRoundedCorners(string $base64, int $radiusPx = 22): string {
        if (!function_exists('imagecreatetruecolor')) return $base64;
        if (!preg_match('/^data:(image\/\w+);base64,(.+)$/s', $base64, $m)) return $base64;
        $raw = base64_decode($m[2]);
        $src = imagecreatefromstring($raw);
        if (!$src) return $base64;

        $w = imagesx($src);
        $h = imagesy($src);
        $r = min($radiusPx, (int)(min($w, $h) * 0.15)); // cap at 15% of smallest side

        $dst = imagecreatetruecolor($w, $h);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $trans = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefill($dst, 0, 0, $trans);
        // Copy source onto transparent canvas (blend ON so non-alpha sources work)
        imagealphablending($dst, true);
        imagecopy($dst, $src, 0, 0, 0, 0, $w, $h);
        // Must turn blending OFF before writing transparent pixels or they blend in
        imagealphablending($dst, false);

        // Cut the four corners: mark pixels outside the arc as transparent
        for ($x = 0; $x < $r; $x++) {
            for ($y = 0; $y < $r; $y++) {
                if (($r-$x-1)*($r-$x-1) + ($r-$y-1)*($r-$y-1) > $r*$r) {
                    imagesetpixel($dst, $x,       $y,       $trans); // top-left
                    imagesetpixel($dst, $w-1-$x,  $y,       $trans); // top-right
                    imagesetpixel($dst, $x,       $h-1-$y,  $trans); // bottom-left
                    imagesetpixel($dst, $w-1-$x,  $h-1-$y,  $trans); // bottom-right
                }
            }
        }

        imagesavealpha($dst, true);
        ob_start();
        imagepng($dst);
        $png = ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);
        return 'data:image/png;base64,' . base64_encode($png);
    }

    private function tintFirmaB64(string $base64, int $r = 10, int $g = 26, int $b = 50): string {
        if (!function_exists('imagecreatetruecolor')) return $base64;
        if (!preg_match('/^data:(image\/\w+);base64,(.+)$/s', $base64, $m)) return $base64;
        $src = imagecreatefromstring(base64_decode($m[2]));
        if (!$src) return $base64;
        imagepalettetotruecolor($src);
        imagesavealpha($src, true);
        $w = imagesx($src); $h = imagesy($src);
        $dst = imagecreatetruecolor($w, $h);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $trans = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefill($dst, 0, 0, $trans);
        for ($px = 0; $px < $w; $px++) {
            for ($py = 0; $py < $h; $py++) {
                $c = imagecolorat($src, $px, $py);
                $a = ($c >> 24) & 0x7F;
                if ($a < 120) {
                    imagesetpixel($dst, $px, $py, imagecolorallocatealpha($dst, $r, $g, $b, $a));
                }
            }
        }
        ob_start(); imagepng($dst); $png = ob_get_clean();
        imagedestroy($src); imagedestroy($dst);
        return 'data:image/png;base64,' . base64_encode($png);
    }

    private function processPhotoBg(string $fotoPath): string {
        $script = dirname(__DIR__) . '/tools/remove_bg.py';
        if (!file_exists($script) || !file_exists($fotoPath)) return '';
        $cacheKey = md5($fotoPath . '|' . filemtime($fotoPath));
        $outPath  = sys_get_temp_dir() . '/cred_photo_' . $cacheKey . '.png';
        if (!file_exists($outPath)) {
            $py  = escapeshellarg('python3');
            $scr = escapeshellarg($script);
            $inp = escapeshellarg($fotoPath);
            $out = escapeshellarg($outPath);
            $cmd = "$py $scr $inp $out navy";
            $result = '';
            if (function_exists('exec')) {
                exec("$cmd 2>&1", $lines);
                $result = implode("\n", $lines);
            } elseif (function_exists('shell_exec')) {
                $result = (string)shell_exec("$cmd 2>&1");
            } elseif (function_exists('proc_open')) {
                $proc = proc_open($cmd, [1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
                if (is_resource($proc)) {
                    $result = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
                    proc_close($proc);
                }
            }
            if (!str_contains($result, 'OK:') || !file_exists($outPath)) return '';
        }
        return 'data:image/png;base64,' . base64_encode(file_get_contents($outPath));
    }

    private function htmlCredencialBody(array $p, string $qrB64 = ''): string {
        $esc = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
        $up  = fn($s) => mb_strtoupper(trim((string)$s), 'UTF-8');

        // ── Datos ─────────────────────────────────────────────────────────
        $nombre = $esc($up($p['nombre_completo'] ?? ''));
        $empresa = $esc($up($p['empresa_nombre'] ?? ''));

        // Font-size adaptativo para el nombre según longitud (la zona tiene 37mm ≈ 11 chars a 9pt por línea)
        $nombreLen   = mb_strlen(strip_tags($nombre), 'UTF-8');
        $nombreFSize = match(true) {
            $nombreLen <= 22 => '9pt',
            $nombreLen <= 28 => '7.5pt',
            $nombreLen <= 34 => '6.5pt',
            default          => '5.8pt',
        };
        $nombreTop = match(true) {
            $nombreLen <= 22 => '57.5mm',
            $nombreLen <= 28 => '57mm',
            default          => '56.5mm',
        };

        // Texto del curso igual que en los diplomas: texto_certificado con {capacidad}
        $capVal    = ($p['capacidad_na'] ?? false) ? 'N/A' : trim($p['capacidad'] ?? '');
        $tbase     = trim($p['texto_certificado'] ?? '');
        $cursoCompleto = $esc($up($tbase
            ? str_ireplace('{capacidad}', $capVal, $tbase)
            : ($p['curso_nombre'] ?? '')));

        $folio = $esc($p['control']
            ? 'AB.' . $p['control'] . '-' . date('Y') . 'MX'
            : 'PART-' . str_pad((string)($p['id'] ?? 0), 5, '0', STR_PAD_LEFT));

        $fechaCert = $vigencia = $fechaVig = '';
        if (!empty($p['fecha_curso'])) {
            $ts        = strtotime($p['fecha_curso']);
            $fechaCert = date('d/m/Y', $ts);
            $vigencia  = date('d/m/Y', strtotime('+1 year', $ts));
            $fechaVig  = $fechaCert . ' - ' . $vigencia;
        }

        $dirNombre = 'ING. JOSE MARCOS GONZALEZ CALDERON';
        $dirTitulo = 'DIRECTOR GENERAL &middot; AVBA INSPECTIONS';

        // ── Assets (fondos a resolución original para calidad de impresión) ──
        $anversoB64 = $this->assetB64('credencial_anverso.png');
        $reversoB64 = $this->assetB64('credencial_reverso.png');
        $logoB64    = $this->assetB64('logos/avba.png');

        // Firma: director general siempre — tintada a azul oscuro (firma_director.png es tinta blanca)
        $firmaB64 = $this->assetB64('logos/firma_director.png');
        $firmaTintadaB64 = $firmaB64 ? $this->tintFirmaB64($firmaB64, 10, 26, 50) : '';

        // ── Foto: remoción de fondo, recorte proporcional (6:7), esquinas redondeadas ──
        $fotoB64 = '';
        if (!empty($p['foto_persona_url'])) {
            $uploadUrl = defined('UPLOAD_URL') ? rtrim(UPLOAD_URL, '/') : '';
            $uploadDir = defined('UPLOAD_DIR') ? rtrim(UPLOAD_DIR, '/') : '';
            $fotoPath  = ($uploadUrl && $uploadDir)
                ? $uploadDir . '/' . ltrim(str_replace($uploadUrl, '', $p['foto_persona_url']), '/')
                : dirname(__DIR__) . '/' . ltrim($p['foto_persona_url'], '/');
            if (file_exists($fotoPath)) {
                $bgRemoved = $this->processPhotoBg($fotoPath);
                $raw       = $bgRemoved ?: $this->fotoBase64WithExif($fotoPath);
                // Recortar proporcionalmente al marco: 29.7×35mm → 224×264px (2×)
                $fotoB64 = $this->cropAndResizeB64($raw, 29.7, 35, 224, 264, (bool)$bgRemoved);
            }
        }

        // ── Fragmentos HTML ────────────────────────────────────────────────
        $bgAnverso = $anversoB64
            ? "<img src=\"{$anversoB64}\" style=\"width:86mm;height:133mm;display:block;\">"
            : "<div style=\"width:86mm;height:133mm;background:#051226;\"></div>";

        $bgReverso = $reversoB64
            ? "<img src=\"{$reversoB64}\" style=\"width:86mm;height:133mm;display:block;\">"
            : "<div style=\"width:86mm;height:133mm;background:#051226;\"></div>";

        $escudoHtml = $logoB64
            ? "<img src=\"{$logoB64}\" style=\"width:9mm;height:auto;display:block;\">"
            : '';

        $firmaHtml = ($firmaTintadaB64 ?: $firmaB64)
            ? "<img src=\"" . ($firmaTintadaB64 ?: $firmaB64) . "\" style=\"width:28mm;height:12mm;display:block;margin:0 auto;\">"
            : '';

        // Foto con esquinas redondeadas horneadas en la imagen via GD
        $fotoHtml = $fotoB64
            ? "<img src=\"{$this->addRoundedCorners($fotoB64, 18)}\" style=\"width:29.7mm;height:35mm;display:block;\">"
            : '';

        // QR con esquinas redondeadas
        $qrHtml = $qrB64
            ? "<img src=\"{$this->addRoundedCorners($qrB64, 18)}\" style=\"width:27mm;height:25mm;display:block;\">"
            : '';

        $anverso = <<<HTML
<div style="position:absolute;left:0mm;top:0mm;width:86mm;height:133mm;">{$bgAnverso}</div>
<div style="position:absolute;left:6mm;top:55.9mm;width:29.7mm;height:35mm;overflow:hidden;">{$fotoHtml}</div>
<div style="position:absolute;left:9mm;top:83.5mm;width:8mm;">{$escudoHtml}</div>
<div style="position:absolute;left:6mm;top:52mm;width:38mm;color:#a8ccf0;font-size:5.5pt;font-weight:bold;letter-spacing:0.4px;">{$folio}</div>
<div style="position:absolute;left:47mm;top:{$nombreTop};width:37mm;color:#ffffff;font-size:{$nombreFSize};font-weight:bold;line-height:1.25;word-wrap:break-word;word-break:break-word;overflow-wrap:break-word;letter-spacing:0.2px;">{$nombre}</div>
<div style="position:absolute;left:47mm;top:67mm;width:37mm;color:#c8e0f8;font-size:6pt;line-height:1.35;word-wrap:break-word;">{$cursoCompleto}</div>
<div style="position:absolute;left:47mm;top:79mm;width:37mm;color:#c8e0f8;font-size:6.5pt;font-weight:bold;letter-spacing:0.3px;">{$fechaVig}</div>
<div style="position:absolute;left:47mm;top:90mm;width:37mm;color:#96bce0;font-size:6pt;line-height:1.3;word-wrap:break-word;">{$empresa}</div>
<div style="position:absolute;left:43mm;top:103mm;width:41mm;text-align:center;">
  {$firmaHtml}
  <div style="font-size:5.5pt;font-weight:bold;color:#0a1a32;margin-top:0.5mm;letter-spacing:0.2px;">{$dirNombre}</div>
  <div style="font-size:4pt;color:#1a3050;margin-top:0.3mm;letter-spacing:0.2px;">{$dirTitulo}</div>
</div>
HTML;

        $reverso = <<<HTML
<div style="position:absolute;left:0mm;top:0mm;width:86mm;height:133mm;">{$bgReverso}</div>
<div style="position:absolute;left:29mm;top:63mm;width:27mm;height:25mm;">{$qrHtml}</div>
HTML;

        return $anverso . "\n<pagebreak />\n" . $reverso;
    }

    private function htmlDiploma(array $p, string $qrB64 = ''): string {
        $esc = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
        $up  = fn($s) => mb_strtoupper(trim((string)$s), 'UTF-8');

        $nombre    = $esc($up($p['nombre_completo'] ?? ''));
        $horas     = (float)($p['duracion_horas'] ?? 0);
        $horasStr  = $horas > 0 ? number_format($horas, 0) . ' horas' : '';
        $area      = $esc(trim($p['area_tematica'] ?? ''));
        $folio     = $esc($p['control']
            ? 'AB.' . $p['control'] . '-' . date('Y') . 'MX'
            : 'PART-' . str_pad((string)$p['id'], 5, '0', STR_PAD_LEFT));
        $emision   = $esc(date('d/m/Y'));

        $capVal    = $p['capacidad_na'] ? 'N/A' : trim($p['capacidad'] ?? '');
        $tbase     = trim($p['texto_certificado'] ?? '');
        $textoCert = $esc($up($tbase
            ? str_ireplace('{capacidad}', $capVal, $tbase)
            : ($p['curso_nombre'] ?? '')));

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

        $instrNombre    = trim($p['instructor_nombre'] ?? '');
        // Diploma: firma siempre del Director General
        $firmaB64  = $this->assetB64('logos/firma_director.png');
        $logoB64   = $this->assetB64('logos/avba.png');
        $selloB64  = $this->assetB64('sellos/sello.png');

        $logoHtml  = $logoB64
            ? "<img src=\"{$logoB64}\" style=\"width:110px;height:52px;display:block;\" alt=\"AVBA\">"
            : '<span style="font-size:11pt;font-weight:bold;color:#C9A84C;">AVBA</span>';
        // Firma blanca con fondo transparente — visible directamente sobre el fondo azul marino
        $firmaHtml = $firmaB64
            ? "<img src=\"{$firmaB64}\" style=\"width:130px;height:62px;display:block;margin:0 auto 3px;\" alt=\"Firma\">"
            : '<div style="height:65px;"></div>';
        $selloHtml = $selloB64
            ? "<img src=\"{$selloB64}\" style=\"width:68px;height:60px;display:block;margin:0 auto;\" alt=\"Sello\">"
            : '';
        $qrHtml = $qrB64
            ? "<img src=\"{$qrB64}\" style=\"width:60px;height:60px;display:block;margin:0 auto;\" alt=\"QR\">"
            : '';

        $infoParts = array_filter([$area, $horasStr ? "Duración: {$horasStr}" : '', $fechaEsc]);
        $infoLine  = implode(' &nbsp;·&nbsp; ', $infoParts);

        $qrSelloCell = "<td style=\"vertical-align:middle;padding:0 5px;\">{$selloHtml}</td>"
                     . ($qrHtml ? "<td style=\"vertical-align:middle;padding:0 5px;\">{$qrHtml}</td>" : '');

        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
@page { margin: 5mm; }
* { margin: 0; padding: 0; }
html, body { height: 100%; font-family: DejaVu Sans, Arial, sans-serif; margin: 0; }
table table td { border: none; }
</style>
</head>
<body>

<!-- OUTER FRAME -->
<table style="width:100%;border-collapse:collapse;border:3px double #C9A84C;background:#FFFDF5;">

  <!-- ① HEADER AZUL -->
  <tr>
    <td style="background:#0D1B4B;padding:4mm 10mm;border-bottom:3px solid #C9A84C;">
      <table style="width:100%;border-collapse:collapse;"><tr>
        <td style="width:120px;vertical-align:middle;">{$logoHtml}</td>
        <td style="text-align:center;vertical-align:middle;">
          <div style="font-size:13pt;font-weight:bold;color:#C9A84C;letter-spacing:5px;">AVBA CERTIFICACIONES</div>
          <div style="font-size:6pt;color:#7A94C4;letter-spacing:2px;margin-top:1.5mm;">INSPECCIÓN INDUSTRIAL &nbsp;·&nbsp; CAPACITACIÓN ESPECIALIZADA</div>
        </td>
        <td style="width:120px;text-align:right;vertical-align:middle;">
          <span style="font-size:6pt;color:#5A6888;font-style:italic;">avba.com.mx</span>
        </td>
      </tr></table>
    </td>
  </tr>

  <!-- ② LÍNEA DORADA SUPERIOR -->
  <tr>
    <td style="background:#FFFDF5;padding:4mm 16mm 0;">
      <table style="width:100%;border-collapse:collapse;"><tr>
        <td style="border-bottom:1.5px solid #C9A84C;height:1px;"></td>
        <td style="width:18px;text-align:center;font-size:10pt;color:#C9A84C;padding:0 4px;">&#9670;</td>
        <td style="border-bottom:1.5px solid #C9A84C;height:1px;"></td>
      </tr></table>
    </td>
  </tr>

  <!-- ③ CONTENIDO PRINCIPAL (altura natural por espaciado, sin height fijo) -->
  <tr>
    <td style="text-align:center;padding:9mm 20mm;background:#FFFDF5;vertical-align:middle;">

      <div style="font-family:DejaVu Serif,Georgia,serif;font-size:60pt;font-weight:bold;
                  color:#B8882A;letter-spacing:16px;line-height:1;margin-bottom:9mm;">DIPLOMA</div>

      <table style="margin:0 auto 9mm;border-collapse:collapse;width:220px;"><tr>
        <td style="border-bottom:1.5px solid #C9A84C;height:1px;"></td>
        <td style="width:14px;text-align:center;font-size:8pt;color:#C9A84C;padding:0 3px;">&#9670;</td>
        <td style="border-bottom:1.5px solid #C9A84C;height:1px;"></td>
      </tr></table>

      <div style="font-size:10pt;color:#999;font-style:italic;margin-bottom:9mm;">Se hace constar que</div>

      <div style="font-family:DejaVu Serif,Georgia,serif;font-size:30pt;font-style:italic;
                  color:#0D1B4B;line-height:1.2;margin-bottom:9mm;">{$nombre}</div>

      <div style="width:70%;height:1.5px;background:#C9A84C;margin:0 auto 9mm;"></div>

      <div style="font-size:10pt;color:#555;margin-bottom:9mm;">Ha concluido satisfactoriamente el programa de capacitación:</div>

      <table style="margin:0 auto 9mm;border-collapse:collapse;"><tr>
        <td style="font-size:14pt;font-weight:bold;color:#0D1B4B;text-transform:uppercase;
                   letter-spacing:1px;padding:3.5mm 35px;
                   border-top:3px solid #C9A84C;border-bottom:3px solid #C9A84C;">{$textoCert}</td>
      </tr></table>

      <div style="font-size:8.5pt;color:#777;margin-bottom:9mm;">{$infoLine}</div>

      <div style="font-size:8.5pt;color:#666;line-height:1.85;padding:7mm 20mm;
                  border-top:1px solid #E0D5B0;border-bottom:1px solid #E0D5B0;">
        En reconocimiento a su dedicación, esfuerzo y participación activa durante el programa
        de capacitación, y en cumplimiento de los criterios de evaluación establecidos, se expide
        el presente <strong style="color:#0D1B4B;">DIPLOMA</strong> como constancia oficial de
        competencia técnica adquirida en materia de seguridad industrial.
      </div>

    </td>
  </tr>

  <!-- ④ LÍNEA DORADA INFERIOR -->
  <tr>
    <td style="background:#FFFDF5;padding:0 16mm 3mm;">
      <table style="width:100%;border-collapse:collapse;"><tr>
        <td style="border-bottom:1.5px solid #C9A84C;height:1px;"></td>
        <td style="width:18px;text-align:center;font-size:10pt;color:#C9A84C;padding:0 4px;">&#9670;</td>
        <td style="border-bottom:1.5px solid #C9A84C;height:1px;"></td>
      </tr></table>
    </td>
  </tr>

  <!-- ⑤ FOOTER AZUL — solo Director General + sello/QR centrados -->
  <tr>
    <td style="background:#0D1B4B;padding:4mm 12mm;border-top:3px solid #C9A84C;">
      <table style="width:100%;border-collapse:collapse;"><tr>

        <!-- Firma Director General (izquierda) -->
        <td style="width:40%;text-align:center;padding:0 8mm;vertical-align:bottom;">
          {$firmaHtml}
          <div style="width:90%;height:1.5px;background:#C9A84C;margin:0 auto 2mm;"></div>
          <div style="font-size:8.5pt;font-weight:bold;color:#C9A84C;">ING. JOSÉ MARCOS GONZÁLEZ CALDERÓN</div>
          <div style="font-size:6pt;color:#7A94C4;margin-top:1mm;">Director General &nbsp;·&nbsp; AVBA Certificaciones</div>
        </td>

        <!-- Sello + QR + folio (centro-derecha) -->
        <td style="width:60%;text-align:center;padding:0 8mm;vertical-align:middle;">
          <table style="margin:0 auto;border-collapse:collapse;"><tr>
            {$qrSelloCell}
          </tr></table>
          <div style="font-size:6pt;color:#5A6888;margin-top:2mm;letter-spacing:0.3px;">
            {$folio} &nbsp;·&nbsp; Emisión: {$emision}
          </div>
        </td>

      </tr></table>
    </td>
  </tr>

</table>
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

    private function htmlToPdfMpdf(string $html, string $folio, string $sufijo = 'CERT', $format = 'A4'): string {
        if (!class_exists('\\Mpdf\\Mpdf')) {
            $autoload = __DIR__ . '/../vendor/autoload.php';
            if (file_exists($autoload)) require_once $autoload;
        }
        if (!class_exists('\\Mpdf\\Mpdf')) {
            throw new \RuntimeException('mPDF no disponible. Verifica vendor/autoload.php.');
        }

        $rutaDir = UPLOAD_DIR . 'personal/docs/';
        if (!is_dir($rutaDir)) mkdir($rutaDir, 0755, true);

        $config = [
            'mode'          => 'utf-8',
            'format'        => $format,
            'margin_left'   => 0, 'margin_right'  => 0,
            'margin_top'    => 0, 'margin_bottom' => 0,
            'margin_header' => 0, 'margin_footer' => 0,
            'dpi'           => 96,
            'default_font'  => 'dejavusans',
            'tempDir'       => sys_get_temp_dir() . '/mpdf',
        ];

        $mpdf = new \Mpdf\Mpdf($config);
        $mpdf->SetBasePath(__DIR__ . '/../');
        $mpdf->SetHTMLFooter('');

        $prevBacktrack = (int) ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', 200000000);

        // Use split mode (HEADER_CSS + HTML_BODY) — same pattern as Certificaciones.php.
        // WriteHTML($fullHtml) with a complete document ignores @page margins in mPDF,
        // producing hundreds of blank pages. The split mode processes them correctly.
        $css      = '';
        $bodyHtml = $html;
        if (preg_match('/<style[^>]*>(.*?)<\/style>/is', $html, $mStyle)) {
            $css = $mStyle[1];
        }
        if (preg_match('/<body[^>]*>(.*)<\/body>/is', $html, $mBody)) {
            $bodyHtml = $mBody[1];
        }

        if ($css !== '') $mpdf->WriteHTML($css, \Mpdf\HTMLParserMode::HEADER_CSS);
        $mpdf->WriteHTML($bodyHtml, \Mpdf\HTMLParserMode::HTML_BODY);

        ini_set('pcre.backtrack_limit', $prevBacktrack);

        $mpdf->SetProtection(['print'], '', 'Avba@Cert2024!');

        $nombre  = $sufijo . '_AVBA_' . $folio . '_' . date('Ymd_His') . '.pdf';
        $destino = $rutaDir . $nombre;
        $mpdf->Output($destino, 'F');
        return UPLOAD_URL . 'personal/docs/' . $nombre;
    }

    private function htmlCertificado(array $p, string $qrB64 = '', bool $sinSellos = false): string {
        $templatePath = __DIR__ . '/../certificado_personal_preview.html';
        if (file_exists($templatePath)) {
            return $this->renderPersonalCertTemplate($templatePath, $p, $qrB64, $sinSellos);
        }
        // Fallback inline HTML (should never be needed if template file is present)
        $e = fn($s) => htmlspecialchars($this->sinAcentos((string)($s ?? '')), ENT_QUOTES, 'UTF-8');
        $folio = $e($p['control']
            ? 'AB.' . $p['control'] . '-' . date('Y') . 'MX'
            : 'PART-' . str_pad((string)$p['id'], 5, '0', STR_PAD_LEFT));
        $nombre  = $e(mb_strtoupper(trim($p['nombre_completo'] ?? ''), 'UTF-8'));
        $empresa = $e(mb_strtoupper(trim($p['empresa_nombre'] ?? ''), 'UTF-8'));
        $curso   = $e(mb_strtoupper(trim($p['curso_nombre'] ?? ''), 'UTF-8'));
        return "<!DOCTYPE html><html><body><h2>Certificado: {$folio}</h2><p>{$nombre}</p><p>{$empresa}</p><p>{$curso}</p></body></html>";
    }

    private function renderPersonalCertTemplate(string $path, array $p, string $qrB64, bool $sinSellos = false): string {
        $e = fn($s) => htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');

        $folio = $e($p['control']
            ? 'AB.' . $p['control'] . '-' . date('Y') . 'MX'
            : 'PART-' . str_pad((string)$p['id'], 5, '0', STR_PAD_LEFT));

        $capVal     = $p['capacidad_na'] ? 'N/A' : trim($p['capacidad'] ?? '');
        $tbase      = trim($p['texto_certificado'] ?? '');
        $acreditado = $e(mb_strtoupper(
            $tbase ? str_ireplace('{capacidad}', $capVal, $tbase) : ($p['curso_nombre'] ?? ''),
            'UTF-8'
        ));

        $noAcreditacion = defined('NO_ACREDITACION') ? NO_ACREDITACION : 'UVNMX 057';

        // Blank 1×1 GIF as QR placeholder (avoids broken-image icon)
        $qrPlaceholder = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

        $map = [
            '{folio}'              => $folio,
            '{participante}'       => $e(mb_strtoupper(trim($p['nombre_completo'] ?? ''), 'UTF-8')),
            '{curp}'               => $e(strtoupper(trim($p['curp'] ?? ''))),
            '{puesto}'             => $e(mb_strtoupper(trim($p['puesto'] ?? ''), 'UTF-8')),
            '{empresa}'            => $e(mb_strtoupper(trim($p['empresa_nombre'] ?? ''), 'UTF-8')),
            '{curso}'              => $e(mb_strtoupper(trim($p['curso_nombre'] ?? ''), 'UTF-8')),
            '{area_tematica}'      => $e(trim($p['area_tematica'] ?? '')),
            '{duracion_horas}'     => $e((string)($p['duracion_horas'] ?? '')),
            '{instructor}'         => $e(trim($p['instructor_nombre'] ?? '') ?: 'Ing. Jose Marcos Gonzalez Calderon'),
            '{fecha_capacitacion}' => $e(!empty($p['fecha_curso']) ? date('d/m/Y', strtotime($p['fecha_curso'])) : ''),
            '{acreditado_como}'    => $acreditado,
            '{no_acreditacion}'    => $e($noAcreditacion),
            '{qr_imagen}'          => $qrB64 ?: $qrPlaceholder,
        ];

        $html = file_get_contents($path);
        $html = str_replace(array_keys($map), array_values($map), $html);
        if ($sinSellos) {
            $html = preg_replace('/<img[^>]*class=["\'][^"\']*avba-sello[^"\']*["\'][^>]*\/?>/i', '', $html);
            $html = str_replace('</head>', '<style>.avba-sello{display:none!important;}</style></head>', $html);
        }
        return $html;
    }

}
