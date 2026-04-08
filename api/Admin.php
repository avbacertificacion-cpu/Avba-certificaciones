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
            "SELECT id, nombre, plantilla FROM maquinaria_tipos ORDER BY id"
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

    // ── Regenerar reporte de inspección ───────────────────────
    public function regenerarReporte(array $payload): array {
        $equipoId = (int) ($payload['equipo_id'] ?? 0);
        if (!$equipoId) return ['status' => 'error', 'message' => 'equipo_id requerido.'];

        require_once __DIR__ . '/ReporteInspeccion.php';
        return ReporteInspeccion::generar($this->pdo, $equipoId);
    }
}
