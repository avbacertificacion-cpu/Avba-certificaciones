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
        $tipos = $this->pdo->query(
            "SELECT id, nombre, plantilla,
                    plantilla_cert, plantilla_dict,
                    plantilla_cert_envio, plantilla_dict_envio
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

        return ['status' => 'success', 'message' => 'Tipo de equipo creado.', 'id' => $tipoId];
    }

    // ── Editar nombre de tipo de equipo ────────────────────────
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

        return ['status' => 'success', 'message' => 'Tipo de equipo actualizado.'];
    }

    // ── Eliminar tipo de equipo (con secciones e ítems) ────────
    public function eliminarTipoEquipo(array $payload): array {
        $id = (int) ($payload['tipo_id'] ?? 0);
        if (!$id) return ['status' => 'error', 'message' => 'tipo_id requerido.'];

        // checklist_items y checklist_secciones tienen FK con ON DELETE CASCADE
        // hacia maquinaria_tipos, por lo que se eliminan automáticamente.
        // Si no hay FK cascade, los eliminamos explícitamente:
        $this->pdo->prepare("DELETE FROM checklist_items    WHERE maquinaria_tipo_id = ?")->execute([$id]);
        $this->pdo->prepare("DELETE FROM checklist_secciones WHERE maquinaria_tipo_id = ?")->execute([$id]);
        $this->pdo->prepare("DELETE FROM maquinaria_tipos   WHERE id = ?")->execute([$id]);

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

    // ── Regenerar reporte de inspección ───────────────────────
    public function regenerarReporte(array $payload, string $usuario = ''): array {
        $equipoId = (int) ($payload['equipo_id'] ?? 0);
        if (!$equipoId) return ['status' => 'error', 'message' => 'equipo_id requerido.'];

        require_once __DIR__ . '/ReporteInspeccion.php';
        return ReporteInspeccion::generar($this->pdo, $equipoId, $usuario);
    }
}
