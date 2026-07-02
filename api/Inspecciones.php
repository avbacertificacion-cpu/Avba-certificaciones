<?php
/**
 * AVBA Certificaciones — Módulo Inspector
 * Migración de Codigo.gs (guardarInspeccionDesdeWeb, obtenerMaquinaria, getChecklist)
 */

class Inspecciones {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    // ── Opciones de maquinaria ─────────────────────────────
    public function obtenerOpcionesMaquinaria(): array {
        $stmt = $this->pdo->query("SELECT nombre, clave_drive AS idPlantilla FROM maquinaria_tipos ORDER BY id");
        return $stmt->fetchAll();
    }

    // ── Checklist por tipo de equipo ───────────────────────
    public function obtenerChecklistPorEquipo(string $nombreEquipo): array {
        if (!$nombreEquipo) return [];

        $stmt = $this->pdo->prepare(
            "SELECT ci.tag, ci.descripcion AS pregunta, ci.seccion
             FROM checklist_items ci
             INNER JOIN maquinaria_tipos mt ON mt.id = ci.maquinaria_tipo_id
             WHERE mt.nombre = ?
             ORDER BY ci.orden"
        );
        $stmt->execute([trim($nombreEquipo)]);
        return $stmt->fetchAll();
    }

    // ── Guardar inspección desde la web ───────────────────
    public function guardarInspeccion(array $payload, string $usuarioActual): array {
        try {
            $info      = $payload['info']      ?? $payload;
            $checklist = $payload['checklist'] ?? [];
            $fotos     = $payload['fotos']     ?? [];

            // Campos requeridos
            $cliente  = trim($info['cliente']   ?? '');
            $serie    = trim($info['serie']     ?? '');
            $maquinaria = trim($info['maquinaria'] ?? '');

            if (!$cliente || !$serie || !$maquinaria) {
                return ['status' => 'error', 'message' => 'cliente, serie y maquinaria son requeridos.'];
            }

            // Generar folio de control
            $control = generarControl($this->pdo, $cliente);

            // Subir fotos al servidor
            $urlCarpeta = '';
            if (!empty($fotos)) {
                $urlCarpeta = $this->guardarFotos($fotos, $cliente, $serie);
            }

            // Insertar registro principal en equipos
            $stmt = $this->pdo->prepare(
                "INSERT INTO equipos
                 (marca_temporal, cliente, coords_inspeccion, maquinaria, marca, modelo, serie,
                  id_equipo, fecha_inspeccion, correo, control, evidencia_url, direccion,
                  capacidad, estado, envio_direccion, coordenadas_envio)
                 VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDIENTE', ?, ?)"
            );
            $stmt->execute([
                $cliente,
                $info['coords_insp']                   ?? null,
                $maquinaria,
                $info['marca']                         ?? null,
                $info['modelo']                        ?? null,
                $serie,
                $info['id_cliente']                    ?? null,
                date('Y-m-d'),
                $info['email_cliente']                 ?? null,
                $control,
                $urlCarpeta ?: null,
                $info['direccion_inspeccion_legible']  ?? null,
                $info['capacidad']                     ?? null,
                $info['direccion_envio_legible']       ?? null,
                $info['coords_envio']                  ?? null,
            ]);

            $equipoId = (int) $this->pdo->lastInsertId();

            // Guardar respuestas del checklist
            if (!empty($checklist)) {
                $ins = $this->pdo->prepare(
                    "INSERT INTO inspeccion_checklist (equipo_id, tag, valor)
                     VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE valor = VALUES(valor)"
                );
                foreach ($checklist as $tag => $valor) {
                    $v = strtoupper(trim($valor));
                    if (!in_array($v, ['C', 'NC', 'NA'], true)) $v = null;
                    $ins->execute([$equipoId, $tag, $v]);
                }
            }

            // Historial
            registrarHistorial($this->pdo, $usuarioActual, $equipoId, 'estado', null, 'PENDIENTE', 'INSERT');

            return [
                'status'   => 'success',
                'message'  => 'Inspección registrada correctamente.',
                'control'  => $control,
                'url'      => $urlCarpeta,
            ];
        } catch (Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    // ── Listar inspecciones (para ajuste de fechas, ADMIN) ─
    // Filtra opcionalmente por rango de fecha_inspeccion (AAAA-MM-DD).
    public function listarInspecciones(string $desde = '', string $hasta = ''): array {
        $where  = [];
        $params = [];
        if ($desde !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) {
            $where[]  = 'fecha_inspeccion >= ?';
            $params[] = $desde;
        }
        if ($hasta !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
            $where[]  = 'fecha_inspeccion <= ?';
            $params[] = $hasta;
        }

        $sql = "SELECT id, control, cliente, maquinaria, marca, serie,
                       fecha_inspeccion AS fecha_iso,
                       DATE_FORMAT(fecha_inspeccion, '%d/%m/%Y') AS fecha,
                       estado
                FROM equipos";
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY fecha_inspeccion DESC, id DESC LIMIT 1000';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return ['status' => 'success', 'data' => $stmt->fetchAll()];
    }

    // ── Editar fecha de inspección de una o varias (ADMIN) ─
    public function editarFechaInspeccion(array $payload, string $usuario): array {
        $ids = $payload['ids'] ?? [];
        if (!is_array($ids)) $ids = [$ids];
        $ids = array_values(array_filter(array_map('intval', $ids), fn($x) => $x > 0));

        $nueva = trim($payload['nueva_fecha'] ?? '');

        if (empty($ids)) {
            return ['status' => 'error', 'message' => 'No se seleccionaron inspecciones.'];
        }
        // Validar fecha destino real (AAAA-MM-DD)
        $d = DateTime::createFromFormat('Y-m-d', $nueva);
        if (!$d || $d->format('Y-m-d') !== $nueva) {
            return ['status' => 'error', 'message' => 'Fecha destino inválida (formato AAAA-MM-DD).'];
        }

        try {
            $this->pdo->beginTransaction();
            $sel = $this->pdo->prepare("SELECT fecha_inspeccion FROM equipos WHERE id = ?");
            $upd = $this->pdo->prepare("UPDATE equipos SET fecha_inspeccion = ? WHERE id = ?");

            $cambiados = 0;
            foreach ($ids as $id) {
                $sel->execute([$id]);
                $row = $sel->fetch();
                if (!$row) continue;
                $anterior = $row['fecha_inspeccion'];
                if ($anterior === $nueva) continue;
                $upd->execute([$nueva, $id]);
                registrarHistorial($this->pdo, $usuario, $id, 'fecha_inspeccion', $anterior, $nueva, 'UPDATE');
                $cambiados++;
            }
            $this->pdo->commit();

            return [
                'status'    => 'success',
                'message'   => "Fecha actualizada en {$cambiados} inspección(es).",
                'cambiados' => $cambiados
            ];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    // ── Guardar fotos en el servidor ───────────────────────
    private function guardarFotos(array $fotos, string $cliente, string $serie): string {
        $fechaHoy   = date('Y-m-d');
        $nombreDir  = preg_replace('/[^a-zA-Z0-9_\-]/', '_', "{$fechaHoy}_{$cliente}_{$serie}");
        $rutaDir    = UPLOAD_DIR . $nombreDir . '/';
        $urlDir     = UPLOAD_URL . $nombreDir . '/';

        if (!is_dir($rutaDir)) {
            mkdir($rutaDir, 0755, true);
        }

        foreach ($fotos as $i => $foto) {
            $base64 = $foto['base64'] ?? '';
            $mime   = $foto['mimeType'] ?? 'image/jpeg';

            // Limpiar prefijo data:image/...;base64,
            $base64 = preg_replace('/^data:[^;]+;base64,/', '', $base64);
            $base64 = str_replace(["\n", "\r", ' '], '', $base64);

            $bytes = base64_decode($base64);
            if ($bytes === false) continue;

            $ext  = ($mime === 'image/png') ? 'png' : 'jpg';
            $file = $rutaDir . "Evidencia_" . ($i + 1) . "_" . preg_replace('/[^a-zA-Z0-9]/', '_', $serie) . ".{$ext}";
            file_put_contents($file, $bytes);
        }

        return $urlDir;
    }
}
