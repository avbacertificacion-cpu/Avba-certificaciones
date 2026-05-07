<?php
/**
 * AVBA Certificaciones — Módulo Admin
 * Gestión de tipos de equipo, secciones y checklist items.
 * Accesible por ADMIN y CALIDAD.
 */

class Admin {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    // ── Listar tipos de equipo con sus secciones ───────────────
    public function listarTiposEquipo(): array {
        // Auto-aplicar migration_007 si las columnas PDF no existen todavía
        $this->ensureColumnsPdf();

        $tipos = $this->pdo->query(
            "SELECT id, nombre, plantilla,
                    plantilla_cert, plantilla_dict,
                    plantilla_cert_envio, plantilla_dict_envio,
                    plantilla_cert_pdf, plantilla_dict_pdf
             FROM maquinaria_tipos ORDER BY id"
        )->fetchAll();

        foreach ($tipos as &$tipo) {
            $tipo['secciones'] = $this->pdo->prepare(
                "SELECT id, codigo, nombre, orden
                 FROM checklist_secciones
                 WHERE maquinaria_tipo_id = ?
                 ORDER BY orden"
            )->execute([$tipo['id']]) ? [] : [];

            $stmtSec = $this->pdo->prepare(
                "SELECT id, codigo, nombre, orden
                 FROM checklist_secciones
                 WHERE maquinaria_tipo_id = ? ORDER BY orden"
            );
            $stmtSec->execute([$tipo['id']]);
            $tipo['secciones'] = $stmtSec->fetchAll();

            $stmtItems = $this->pdo->prepare(
                "SELECT id, seccion, tag, descripcion, orden
                 FROM checklist_items
                 WHERE maquinaria_tipo_id = ? ORDER BY seccion, orden"
            );
            $stmtItems->execute([$tipo['id']]);
            $tipo['checklist'] = $stmtItems->fetchAll();

            $stmtNormas = $this->pdo->prepare(
                "SELECT id, norma, orden FROM maquinaria_normas
                 WHERE maquinaria_tipo_id = ? ORDER BY orden"
            );
            $stmtNormas->execute([$tipo['id']]);
            $tipo['normas'] = $stmtNormas->fetchAll();
        }
        unset($tipo);

        return ['status' => 'success', 'data' => $tipos];
    }

    // ── Crear nuevo tipo de equipo ─────────────────────────────
    public function crearTipoEquipo(array $payload): array {
        $nombre = trim($payload['nombre'] ?? '');
        if (!$nombre) return ['status' => 'error', 'message' => 'El nombre es requerido.'];

        // Verificar que no exista
        $existe = $this->pdo->prepare("SELECT id FROM maquinaria_tipos WHERE nombre = ?");
        $existe->execute([$nombre]);
        if ($existe->fetch()) return ['status' => 'error', 'message' => 'Ya existe un tipo con ese nombre.'];

        $this->pdo->prepare("INSERT INTO maquinaria_tipos (nombre) VALUES (?)")
            ->execute([$nombre]);
        $tipoId = (int) $this->pdo->lastInsertId();

        // Insertar secciones si vienen en el payload
        $secciones = $payload['secciones'] ?? [];
        foreach ($secciones as $sec) {
            $this->pdo->prepare(
                "INSERT IGNORE INTO checklist_secciones
                 (maquinaria_tipo_id, codigo, nombre, orden) VALUES (?,?,?,?)"
            )->execute([
                $tipoId,
                strtoupper(trim($sec['codigo'] ?? '')),
                trim($sec['nombre'] ?? ''),
                (int) ($sec['orden'] ?? 0)
            ]);
        }

        // Insertar items de checklist si vienen en el payload
        $items = $payload['checklist'] ?? [];
        foreach ($items as $idx => $item) {
            $tag = strtoupper(trim($item['tag'] ?? ''));
            if (!$tag) continue;
            $this->pdo->prepare(
                "INSERT IGNORE INTO checklist_items
                 (maquinaria_tipo_id, seccion, tag, descripcion, orden) VALUES (?,?,?,?,?)"
            )->execute([
                $tipoId,
                strtoupper(trim($item['seccion'] ?? '')),
                $tag,
                trim($item['descripcion'] ?? ''),
                (int) ($item['orden'] ?? $idx + 1)
            ]);
        }

        // Insertar normas (máx. 6)
        $this->guardarNormasParaTipo($tipoId, $payload['normas'] ?? []);

        return ['status' => 'success', 'message' => 'Tipo de equipo creado.', 'id' => $tipoId];
    }

    // ── Editar tipo de equipo (nombre + normas) ────────────────
    public function editarTipoEquipo(array $payload): array {
        $id     = (int) ($payload['tipo_id'] ?? 0);
        $nombre = trim($payload['nombre'] ?? '');

        if (!$id || !$nombre)
            return ['status' => 'error', 'message' => 'tipo_id y nombre son requeridos.'];

        // Evitar duplicados
        $dup = $this->pdo->prepare("SELECT id FROM maquinaria_tipos WHERE nombre = ? AND id != ?");
        $dup->execute([$nombre, $id]);
        if ($dup->fetch())
            return ['status' => 'error', 'message' => 'Ya existe otro tipo con ese nombre.'];

        $this->pdo->prepare("UPDATE maquinaria_tipos SET nombre = ? WHERE id = ?")
            ->execute([$nombre, $id]);

        // Actualizar normas si vienen en el payload
        if (array_key_exists('normas', $payload)) {
            $this->guardarNormasParaTipo($id, $payload['normas']);
        }

        return ['status' => 'success', 'message' => 'Tipo de equipo actualizado.'];
    }

    // ── Guardar normas de un tipo (reemplaza todas) ────────────
    private function guardarNormasParaTipo(int $tipoId, array $normas): void {
        $this->pdo->prepare("DELETE FROM maquinaria_normas WHERE maquinaria_tipo_id = ?")
            ->execute([$tipoId]);

        $normasFiltradas = array_slice(
            array_filter(array_map('trim', $normas), fn($n) => $n !== ''),
            0, 6
        );
        $stmt = $this->pdo->prepare(
            "INSERT INTO maquinaria_normas (maquinaria_tipo_id, norma, orden) VALUES (?, ?, ?)"
        );
        foreach ($normasFiltradas as $orden => $norma) {
            $stmt->execute([$tipoId, $norma, $orden + 1]);
        }
    }

    // ── Eliminar tipo de equipo (con secciones e ítems) ────────
    public function eliminarTipoEquipo(array $payload): array {
        $id = (int) ($payload['tipo_id'] ?? 0);
        if (!$id) return ['status' => 'error', 'message' => 'tipo_id requerido.'];

        $this->pdo->prepare("DELETE FROM maquinaria_normas    WHERE maquinaria_tipo_id = ?")->execute([$id]);
        $this->pdo->prepare("DELETE FROM checklist_items      WHERE maquinaria_tipo_id = ?")->execute([$id]);
        $this->pdo->prepare("DELETE FROM checklist_secciones  WHERE maquinaria_tipo_id = ?")->execute([$id]);
        $this->pdo->prepare("DELETE FROM maquinaria_tipos     WHERE id = ?")->execute([$id]);

        return ['status' => 'success', 'message' => 'Tipo de equipo eliminado.'];
    }

    // ── Agregar sección a un tipo existente ────────────────────
    public function agregarSeccion(array $payload): array {
        $tipoId = (int) ($payload['tipo_id'] ?? 0);
        $codigo = strtoupper(trim($payload['codigo'] ?? ''));
        $nombre = trim($payload['nombre'] ?? '');
        $orden  = (int) ($payload['orden'] ?? 0);

        if (!$tipoId || !$codigo || !$nombre)
            return ['status' => 'error', 'message' => 'tipo_id, codigo y nombre son requeridos.'];

        $this->pdo->prepare(
            "INSERT INTO checklist_secciones (maquinaria_tipo_id, codigo, nombre, orden)
             VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), orden = VALUES(orden)"
        )->execute([$tipoId, $codigo, $nombre, $orden]);

        return ['status' => 'success', 'message' => 'Sección guardada.'];
    }

    // ── Editar nombre de sección ───────────────────────────────
    public function editarSeccion(array $payload): array {
        $id     = (int) ($payload['seccion_id'] ?? 0);
        $nombre = trim($payload['nombre'] ?? '');

        if (!$id || !$nombre)
            return ['status' => 'error', 'message' => 'seccion_id y nombre son requeridos.'];

        $this->pdo->prepare("UPDATE checklist_secciones SET nombre = ? WHERE id = ?")
            ->execute([$nombre, $id]);

        return ['status' => 'success', 'message' => 'Sección actualizada.'];
    }

    // ── Eliminar sección y sus ítems ───────────────────────────
    public function eliminarSeccion(array $payload): array {
        $id = (int) ($payload['seccion_id'] ?? 0);
        if (!$id) return ['status' => 'error', 'message' => 'seccion_id requerido.'];

        // Obtener codigo y maquinaria_tipo_id para eliminar también sus ítems
        $stmt = $this->pdo->prepare(
            "SELECT maquinaria_tipo_id, codigo FROM checklist_secciones WHERE id = ?"
        );
        $stmt->execute([$id]);
        $sec = $stmt->fetch();

        if (!$sec) return ['status' => 'error', 'message' => 'Sección no encontrada.'];

        // Eliminar ítems de esa sección
        $this->pdo->prepare(
            "DELETE FROM checklist_items WHERE maquinaria_tipo_id = ? AND seccion = ?"
        )->execute([$sec['maquinaria_tipo_id'], $sec['codigo']]);

        // Eliminar la sección
        $this->pdo->prepare("DELETE FROM checklist_secciones WHERE id = ?")->execute([$id]);

        return ['status' => 'success', 'message' => 'Sección e ítems eliminados.'];
    }

    // ── Agregar/editar item del checklist ──────────────────────
    public function guardarItemChecklist(array $payload): array {
        $tipoId = (int) ($payload['tipo_id'] ?? 0);
        $seccion = strtoupper(trim($payload['seccion'] ?? ''));
        $tag     = strtoupper(trim($payload['tag'] ?? ''));
        $desc    = trim($payload['descripcion'] ?? '');
        $orden   = (int) ($payload['orden'] ?? 0);

        if (!$tipoId || !$seccion || !$tag || !$desc)
            return ['status' => 'error', 'message' => 'tipo_id, seccion, tag y descripcion son requeridos.'];

        $this->pdo->prepare(
            "INSERT INTO checklist_items
             (maquinaria_tipo_id, seccion, tag, descripcion, orden)
             VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion), orden = VALUES(orden)"
        )->execute([$tipoId, $seccion, $tag, $desc, $orden]);

        return ['status' => 'success', 'message' => 'Item guardado.'];
    }

    // ── Eliminar item del checklist ────────────────────────────
    public function eliminarItemChecklist(array $payload): array {
        $id = (int) ($payload['item_id'] ?? 0);
        if (!$id) return ['status' => 'error', 'message' => 'item_id requerido.'];

        $this->pdo->prepare("DELETE FROM checklist_items WHERE id = ?")->execute([$id]);
        return ['status' => 'success', 'message' => 'Item eliminado.'];
    }

    // ── Subir plantilla Word para certificado o dictamen ──────
    /**
     * Recibe un archivo .docx vía multipart/form-data.
     * Parámetros POST: tipo_id (int), doc_tipo ('cert'|'dict')
     * Parámetro FILE:  plantilla (el archivo .docx)
     *
     * Etiquetas disponibles en la plantilla Word:
     *   ${folio}         → AB.XXXXX-XXXXX-2026MX
     *   ${cliente}       → Nombre del cliente
     *   ${domicilio}     → Dirección
     *   ${maquinaria}    → Tipo de equipo
     *   ${marca}         → Marca
     *   ${modelo}        → Modelo
     *   ${serie}         → Número de serie
     *   ${id_equipo}     → ID del equipo
     *   ${capacidad}     → Capacidad
     *   ${fecha}         → Fecha de inspección (dd/mm/yyyy)
     *   ${vigencia}      → Fecha de vencimiento (dd/mm/yyyy)
     *   ${qr_codigo}     → Código QR (texto)
     *   ${anio}          → Año actual
     *
     *   Para dictamen — fila de tabla a clonar:
     *   ${item_seccion}      → Nombre de sección
     *   ${item_descripcion}  → Descripción del ítem
     *   ${item_valor}        → CONFORME / NO CONFORME / N/A
     */
    public function subirPlantilla(array $post, array $files): array {
        $tipoId  = (int)($post['tipo_id']  ?? 0);
        $docTipo = trim($post['doc_tipo']  ?? '');

        if (!$tipoId || !in_array($docTipo, ['cert', 'dict', 'cert_envio', 'dict_envio'], true))
            return ['status' => 'error', 'message' => 'tipo_id y doc_tipo (cert|dict|cert_envio|dict_envio) son requeridos.'];

        // Verificar que el tipo existe
        $chk = $this->pdo->prepare("SELECT id FROM maquinaria_tipos WHERE id = ?");
        $chk->execute([$tipoId]);
        if (!$chk->fetch())
            return ['status' => 'error', 'message' => 'Tipo de equipo no encontrado.'];

        $file = $files['plantilla'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK)
            return ['status' => 'error', 'message' => 'No se recibió archivo o hubo un error al subirlo.'];

        $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        if ($ext !== 'docx')
            return ['status' => 'error', 'message' => 'Solo se aceptan archivos .docx'];

        $colMap = [
            'cert'       => 'plantilla_cert',
            'dict'       => 'plantilla_dict',
            'cert_envio' => 'plantilla_cert_envio',
            'dict_envio' => 'plantilla_dict_envio',
        ];
        if (!array_key_exists($docTipo, $colMap))
            return ['status' => 'error', 'message' => 'doc_tipo inválido. Usa: cert, dict, cert_envio, dict_envio'];

        $dir = __DIR__ . '/../uploads/plantillas/';
        if (!is_dir($dir) && !mkdir($dir, 0755, true))
            return ['status' => 'error', 'message' => 'No se pudo crear el directorio de plantillas.'];

        $filename = "tipo_{$tipoId}_{$docTipo}.docx";
        $destPath = $dir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destPath))
            return ['status' => 'error', 'message' => 'Error al guardar el archivo en el servidor.'];

        $col = $colMap[$docTipo];
        $this->pdo->prepare("UPDATE maquinaria_tipos SET `{$col}` = ? WHERE id = ?")
            ->execute([$filename, $tipoId]);

        return ['status' => 'success', 'message' => 'Plantilla guardada correctamente.', 'archivo' => $filename];
    }

    // ── Auto-migración: agrega columnas PDF si no existen ─────
    private function ensureColumnsPdf(): void {
        // Verifica y agrega cada columna individualmente.
        // Compatible con MySQL 5.x (no tiene ADD COLUMN IF NOT EXISTS)
        // y con MySQL 8.x.
        $needed = [
            'plantilla_cert_pdf' => "ALTER TABLE maquinaria_tipos ADD COLUMN plantilla_cert_pdf VARCHAR(500) NULL AFTER plantilla_cert",
            'plantilla_dict_pdf' => "ALTER TABLE maquinaria_tipos ADD COLUMN plantilla_dict_pdf VARCHAR(500) NULL AFTER plantilla_dict",
            'cert_pdf_campos'    => "ALTER TABLE maquinaria_tipos ADD COLUMN cert_pdf_campos    JSON          NULL",
            'dict_pdf_campos'    => "ALTER TABLE maquinaria_tipos ADD COLUMN dict_pdf_campos    JSON          NULL",
        ];

        foreach ($needed as $col => $ddl) {
            $exists = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = 'maquinaria_tipos'
                   AND COLUMN_NAME  = '{$col}'"
            )->fetchColumn();
            if (!$exists) {
                try { $this->pdo->exec($ddl); } catch (\PDOException $e) { /* ignore */ }
            }
        }
    }

    public function subirPlantillaPdf(array $post, array $files): array {
        // Garantizar que las columnas existen antes de actualizar
        $this->ensureColumnsPdf();
        $tipoId  = (int)($post['tipo_id']  ?? 0);
        $docTipo = trim($post['doc_tipo']  ?? ''); // cert_pdf | dict_pdf

        if (!$tipoId || !in_array($docTipo, ['cert_pdf', 'dict_pdf'], true))
            return ['status' => 'error', 'message' => 'tipo_id y doc_tipo (cert_pdf|dict_pdf) son requeridos.'];

        $chk = $this->pdo->prepare("SELECT id FROM maquinaria_tipos WHERE id = ?");
        $chk->execute([$tipoId]);
        if (!$chk->fetch())
            return ['status' => 'error', 'message' => 'Tipo de equipo no encontrado.'];

        $file = $files['plantilla'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK)
            return ['status' => 'error', 'message' => 'No se recibió archivo o hubo un error al subirlo.'];

        $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        if ($ext !== 'pdf')
            return ['status' => 'error', 'message' => 'Solo se aceptan archivos .pdf'];

        $dir = __DIR__ . '/../uploads/plantillas/';
        if (!is_dir($dir) && !mkdir($dir, 0755, true))
            return ['status' => 'error', 'message' => 'No se pudo crear el directorio de plantillas.'];

        $colMap = ['cert_pdf' => 'plantilla_cert_pdf', 'dict_pdf' => 'plantilla_dict_pdf'];
        $col    = $colMap[$docTipo];

        // Borrar el archivo anterior si existe
        $anterior = $this->pdo->prepare("SELECT `{$col}` FROM maquinaria_tipos WHERE id = ?");
        $anterior->execute([$tipoId]);
        $oldFile = ($anterior->fetchColumn()) ?: '';
        if ($oldFile && file_exists($dir . $oldFile)) {
            @unlink($dir . $oldFile);
        }

        // Nombre único que preserva el nombre original + timestamp
        $baseName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($file['name'] ?? 'plantilla', PATHINFO_FILENAME));
        $filename = "tipo_{$tipoId}_{$docTipo}_{$baseName}_" . time() . ".pdf";

        if (!move_uploaded_file($file['tmp_name'], $dir . $filename))
            return ['status' => 'error', 'message' => 'Error al guardar el archivo en el servidor.'];

        try {
            $this->pdo->prepare("UPDATE maquinaria_tipos SET `{$col}` = ? WHERE id = ?")
                ->execute([$filename, $tipoId]);
        } catch (\PDOException $e) {
            return ['status' => 'error', 'message' => 'Error al guardar en la base de datos: ' . $e->getMessage()];
        }

        return ['status' => 'success', 'message' => 'Plantilla PDF guardada.', 'archivo' => $filename];
    }

    // ── Guardar campos / coordenadas del PDF ──────────────────
    public function guardarCamposPdf(array $payload): array {
        $tipoId  = (int)($payload['tipo_id']  ?? 0);
        $docTipo = trim($payload['doc_tipo']  ?? ''); // cert | dict
        $campos  = $payload['campos'] ?? '[]';

        if (!$tipoId || !in_array($docTipo, ['cert', 'dict'], true))
            return ['status' => 'error', 'message' => 'tipo_id y doc_tipo (cert|dict) requeridos.'];

        // Validar JSON
        $decoded = is_string($campos) ? json_decode($campos, true) : $campos;
        if (!is_array($decoded))
            return ['status' => 'error', 'message' => 'El parámetro campos debe ser un array JSON.'];

        $colMap = ['cert' => 'cert_pdf_campos', 'dict' => 'dict_pdf_campos'];
        $col    = $colMap[$docTipo];
        $json   = json_encode($decoded, JSON_UNESCAPED_UNICODE);

        $this->pdo->prepare("UPDATE maquinaria_tipos SET `{$col}` = ? WHERE id = ?")
            ->execute([$json, $tipoId]);

        return ['status' => 'success', 'message' => 'Campos PDF guardados.'];
    }

    // ── Obtener campos PDF de un tipo de equipo ───────────────
    public function obtenerCamposPdf(int $tipoId): array {
        $stmt = $this->pdo->prepare(
            "SELECT plantilla_cert_pdf, plantilla_dict_pdf,
                    cert_pdf_campos, dict_pdf_campos
             FROM maquinaria_tipos WHERE id = ?"
        );
        $stmt->execute([$tipoId]);
        $row = $stmt->fetch();
        if (!$row) return ['status' => 'error', 'message' => 'Tipo no encontrado.'];

        $base = rtrim(UPLOAD_URL, '/') . '/plantillas/';
        return [
            'status'             => 'success',
            'plantilla_cert_pdf' => $row['plantilla_cert_pdf'] ?? null,
            'plantilla_dict_pdf' => $row['plantilla_dict_pdf'] ?? null,
            'cert_pdf_url'       => $row['plantilla_cert_pdf'] ? $base . $row['plantilla_cert_pdf'] : null,
            'dict_pdf_url'       => $row['plantilla_dict_pdf'] ? $base . $row['plantilla_dict_pdf'] : null,
            'cert_pdf_campos'    => json_decode($row['cert_pdf_campos'] ?? '[]', true) ?: [],
            'dict_pdf_campos'    => json_decode($row['dict_pdf_campos'] ?? '[]', true) ?: [],
        ];
    }

    // ── Regenerar reporte de inspección ───────────────────────
    public function regenerarReporte(array $payload, string $usuario = ''): array {
        $equipoId = (int) ($payload['equipo_id'] ?? 0);
        if (!$equipoId) return ['status' => 'error', 'message' => 'equipo_id requerido.'];

        require_once __DIR__ . '/ReporteInspeccion.php';
        return ReporteInspeccion::generar($this->pdo, $equipoId, $usuario);
    }

    // ══════════════════════════════════════════════════════
    //  PLANTILLAS PDF — PERSONAL / CAPACITACIÓN
    // ══════════════════════════════════════════════════════

    private function ensurePlantillasPersonal(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS plantillas_personal (
              tipo          ENUM('diploma','certificado','dc3') NOT NULL,
              plantilla_pdf VARCHAR(500)  NULL,
              pdf_campos    JSON          NULL,
              actualizado   TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (tipo)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $this->pdo->exec("INSERT IGNORE INTO plantillas_personal (tipo) VALUES ('diploma'),('certificado'),('dc3')");
    }

    public function listarPlantillasPersonal(): array {
        $this->ensurePlantillasPersonal();
        $stmt = $this->pdo->query(
            "SELECT tipo, plantilla_pdf, pdf_campos
             FROM plantillas_personal
             ORDER BY FIELD(tipo,'diploma','certificado','dc3')"
        );
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['pdf_campos'] = json_decode($r['pdf_campos'] ?? '[]', true) ?: [];
        }
        unset($r);
        return ['status' => 'success', 'data' => $rows];
    }

    public function subirPlantillaPdfPersonal(array $post, array $files): array {
        $this->ensurePlantillasPersonal();
        $tipo = trim($post['tipo'] ?? '');
        if (!in_array($tipo, ['diploma','certificado','dc3'], true))
            return ['status' => 'error', 'message' => 'tipo debe ser diploma, certificado o dc3.'];

        $file = $files['plantilla'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK)
            return ['status' => 'error', 'message' => 'No se recibió archivo o hubo un error al subirlo.'];

        $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        if ($ext !== 'pdf')
            return ['status' => 'error', 'message' => 'Solo se aceptan archivos .pdf'];

        $dir = __DIR__ . '/../uploads/plantillas/';
        if (!is_dir($dir) && !mkdir($dir, 0755, true))
            return ['status' => 'error', 'message' => 'No se pudo crear el directorio de plantillas.'];

        $filename = "personal_{$tipo}.pdf";
        if (!move_uploaded_file($file['tmp_name'], $dir . $filename))
            return ['status' => 'error', 'message' => 'Error al guardar el archivo en el servidor.'];

        $this->pdo->prepare("UPDATE plantillas_personal SET plantilla_pdf = ? WHERE tipo = ?")
            ->execute([$filename, $tipo]);

        return ['status' => 'success', 'message' => 'Plantilla PDF guardada.', 'archivo' => $filename];
    }

    public function guardarCamposPdfPersonal(array $payload): array {
        $this->ensurePlantillasPersonal();
        $tipo   = trim($payload['tipo']   ?? '');
        $campos = $payload['campos'] ?? [];

        if (!in_array($tipo, ['diploma','certificado','dc3'], true))
            return ['status' => 'error', 'message' => 'tipo debe ser diploma, certificado o dc3.'];

        $decoded = is_string($campos) ? json_decode($campos, true) : $campos;
        if (!is_array($decoded))
            return ['status' => 'error', 'message' => 'campos debe ser un array JSON.'];

        $this->pdo->prepare("UPDATE plantillas_personal SET pdf_campos = ? WHERE tipo = ?")
            ->execute([json_encode($decoded, JSON_UNESCAPED_UNICODE), $tipo]);

        return ['status' => 'success', 'message' => 'Campos PDF guardados.'];
    }

    public function obtenerCamposPdfPersonal(string $tipo): array {
        $this->ensurePlantillasPersonal();
        if (!in_array($tipo, ['diploma','certificado','dc3'], true))
            return ['status' => 'error', 'message' => 'tipo inválido.'];

        $stmt = $this->pdo->prepare("SELECT plantilla_pdf, pdf_campos FROM plantillas_personal WHERE tipo = ?");
        $stmt->execute([$tipo]);
        $row = $stmt->fetch();
        if (!$row) return ['status' => 'error', 'message' => 'Tipo no encontrado.'];

        $pdfUrl = ($row['plantilla_pdf'] ?? null)
            ? rtrim(UPLOAD_URL, '/') . '/plantillas/' . $row['plantilla_pdf']
            : null;
        return [
            'status'        => 'success',
            'plantilla_pdf' => $row['plantilla_pdf'] ?? null,
            'pdf_campos'    => json_decode($row['pdf_campos'] ?? '[]', true) ?: [],
            'pdf_url'       => $pdfUrl,
        ];
    }
}
