<?php
/**
 * AVBA Certificaciones — Módulo de Calidad
 * Migración de Codigo.gs (getDataCalidad, aprobarCalidad, actualizarCalidad)
 */

class Calidad {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    // ── Obtener registros pendientes de revisión ───────────
    public function getDataCalidad(): array {
        $stmt = $this->pdo->query(
            "SELECT id, id AS fila,
                    DATE_FORMAT(marca_temporal, '%d/%m/%Y %H:%i') AS marca_temporal,
                    cliente, coords_inspeccion, maquinaria, marca, modelo, serie,
                    id_equipo,
                    DATE_FORMAT(fecha_inspeccion, '%d/%m/%Y') AS fecha,
                    correo, control, evidencia_url AS evidencia, direccion, capacidad,
                    disponibilidad, estado, motivo, qr_codigo,
                    certificado_url AS link, dictamen_url AS dictamen, envio_direccion, coordenadas_envio,
                    reporte_url
             FROM equipos
             WHERE estado IN ('PENDIENTE', 'CONFORME', 'NO CONFORME', 'RECHAZADO', 'RETORNADO')
             ORDER BY marca_temporal DESC"
        );
        $rows = $stmt->fetchAll();

        // Agregar URL del QR
        foreach ($rows as &$r) {
            $r['qr_url'] = $r['qr_codigo'] ? urlQR($r['qr_codigo']) : '';
        }
        unset($r);

        return $rows;
    }

    // ── Aprobar inspección ─────────────────────────────────
    public function aprobarCalidad(array $payload, string $usuario): array {
        $id = (int) ($payload['id'] ?? $payload['fila'] ?? 0);
        $qr = trim($payload['qr'] ?? '');

        if (!$id) return ['status' => 'error', 'message' => 'ID de equipo requerido.'];
        if (!$qr)  return ['status' => 'error', 'message' => 'El código QR es requerido.'];

        $row = $this->obtenerEquipo($id);
        if (!$row) return ['status' => 'error', 'message' => 'Registro no encontrado.'];

        // Validar que el QR existe
        $stmtQR = $this->pdo->prepare(
            "SELECT id, usado, equipo_id FROM qr_codigos WHERE identificador = ?"
        );
        $stmtQR->execute([$qr]);
        $qrRow = $stmtQR->fetch();

        if (!$qrRow) return ['status' => 'error', 'message' => 'Código QR no válido.'];

        $qrYaEsDeEsteEquipo = $qrRow['usado'] && (int)$qrRow['equipo_id'] === $id;
        if ($qrRow['usado'] && !$qrYaEsDeEsteEquipo)
            return ['status' => 'error', 'message' => 'Código QR ya está en uso por otro registro.'];

        $estadoAnterior = $row['estado'];
        $nuevoEstado    = 'APROBADO CALIDAD';
        $qrAnterior     = $row['qr_codigo'] ?? '';

        // Si hay un QR previo diferente, liberarlo
        if ($qrAnterior && $qrAnterior !== $qr) {
            $this->pdo->prepare(
                "UPDATE qr_codigos SET usado = 0, equipo_id = NULL WHERE identificador = ?"
            )->execute([$qrAnterior]);
        }

        // Asignar QR al equipo y aprobar
        $this->pdo->prepare(
            "UPDATE equipos SET estado = ?, motivo = NULL, qr_codigo = ? WHERE id = ?"
        )->execute([$nuevoEstado, $qr, $id]);

        // Marcar QR como usado
        $this->pdo->prepare(
            "UPDATE qr_codigos SET usado = 1, equipo_id = ? WHERE id = ?"
        )->execute([$id, $qrRow['id']]);

        registrarHistorial($this->pdo, $usuario, $id, 'estado', $estadoAnterior, $nuevoEstado);
        registrarHistorial($this->pdo, $usuario, $id, 'qr_codigo', $row['qr_codigo'] ?? null, $qr);

        return ['status' => 'success', 'message' => 'Inspección aprobada y QR asignado correctamente.'];
    }

    // ── Actualizar datos en calidad ────────────────────────
    public function actualizarCalidad(array $payload, string $usuario): array {
        $id = (int) ($payload['id'] ?? $payload['fila'] ?? 0);
        if (!$id) return ['status' => 'error', 'message' => 'ID de equipo requerido.'];

        $row = $this->obtenerEquipo($id);
        if (!$row) return ['status' => 'error', 'message' => 'Registro no encontrado.'];

        $campos  = ['cliente','maquinaria','marca','modelo','serie','capacidad','correo','id_equipo','estado','motivo'];
        $sets    = [];
        $params  = [];

        foreach ($campos as $campo) {
            if (array_key_exists($campo, $payload)) {
                $sets[]  = "`{$campo}` = ?";
                $params[] = $payload[$campo] === '' ? null : $payload[$campo];
                registrarHistorial($this->pdo, $usuario, $id, $campo, $row[$campo] ?? null, $payload[$campo]);
            }
        }

        // QR manual
        if (!empty($payload['qr_codigo'])) {
            $sets[]  = '`qr_codigo` = ?';
            $params[] = $payload['qr_codigo'];
            registrarHistorial($this->pdo, $usuario, $id, 'qr_codigo', $row['qr_codigo'] ?? null, $payload['qr_codigo']);
        }

        if (empty($sets)) return ['status' => 'success', 'message' => 'Sin cambios.'];

        $params[] = $id;
        $this->pdo->prepare("UPDATE equipos SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);

        return ['status' => 'success', 'message' => 'Datos actualizados correctamente.'];
    }

    // ── Rechazar inspección ────────────────────────────────
    public function rechazarCalidad(array $payload, string $usuario): array {
        $id     = (int) ($payload['id'] ?? $payload['fila'] ?? 0);
        $motivo = trim($payload['motivo'] ?? '');

        if (!$id) return ['status' => 'error', 'message' => 'ID de equipo requerido.'];

        $row = $this->obtenerEquipo($id);
        if (!$row) return ['status' => 'error', 'message' => 'Registro no encontrado.'];

        $this->pdo->prepare(
            "UPDATE equipos SET estado = 'NO CONFORME', motivo = ? WHERE id = ?"
        )->execute([$motivo ?: null, $id]);

        registrarHistorial($this->pdo, $usuario, $id, 'estado', $row['estado'], 'NO CONFORME');

        return ['status' => 'success', 'message' => 'Inspección rechazada.'];
    }

    // ── Auxiliar: obtener registro por id ──────────────────
    private function obtenerEquipo(int $id): ?array {
        $stmt = $this->pdo->prepare("SELECT * FROM equipos WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    // ── Listar info de códigos QR ──────────────────────────
    public function listarQrInfo(string $filtro = 'todos'): array {
        $this->ensureQrTable();

        $total      = (int) $this->pdo->query("SELECT COUNT(*) FROM qr_codigos")->fetchColumn();
        $usados     = (int) $this->pdo->query("SELECT COUNT(*) FROM qr_codigos WHERE usado = 1")->fetchColumn();
        $disponibles = $total - $usados;
        $ultimo     = $this->pdo->query("SELECT MAX(CAST(identificador AS UNSIGNED)) FROM qr_codigos")->fetchColumn();

        $where = match($filtro) {
            'disponibles' => 'WHERE usado = 0',
            'usados'      => 'WHERE usado = 1',
            default       => '',
        };
        $codigos = $this->pdo->query(
            "SELECT identificador, usado FROM qr_codigos {$where}
             ORDER BY CAST(identificador AS UNSIGNED) DESC LIMIT 200"
        )->fetchAll();

        return [
            'status'      => 'success',
            'total'       => $total,
            'usados'      => $usados,
            'disponibles' => $disponibles,
            'ultimo'      => $ultimo ?: null,
            'codigos'     => $codigos,
        ];
    }

    // ── Generar lote de códigos QR consecutivos ────────────
    public function generarQrLote(array $payload): array {
        $this->ensureQrTable();

        $hasta = trim($payload['hasta'] ?? '');
        if (!preg_match('/^\d{10}$/', $hasta)) {
            return ['status' => 'error', 'message' => 'El número debe tener exactamente 10 dígitos.'];
        }

        $ultimoRaw = $this->pdo->query(
            "SELECT MAX(CAST(identificador AS UNSIGNED)) FROM qr_codigos"
        )->fetchColumn();
        $ultimo = $ultimoRaw ? (int) $ultimoRaw : 0;
        $hastaInt = (int) $hasta;

        if ($hastaInt <= $ultimo) {
            return ['status' => 'error', 'message' => "El número debe ser mayor que el último registrado ({$ultimo})."];
        }

        $desde = $ultimo + 1;
        $cantidad = $hastaInt - $ultimo;

        $stmt = $this->pdo->prepare(
            "INSERT IGNORE INTO qr_codigos (identificador, usado) VALUES (?, 0)"
        );
        $this->pdo->beginTransaction();
        try {
            for ($n = $desde; $n <= $hastaInt; $n++) {
                $stmt->execute([str_pad((string) $n, 10, '0', STR_PAD_LEFT)]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            return ['status' => 'error', 'message' => 'Error al insertar códigos: ' . $e->getMessage()];
        }

        return ['status' => 'success', 'message' => "{$cantidad} códigos generados correctamente.", 'cantidad' => $cantidad];
    }

    private function ensureQrTable(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS qr_codigos (
                id            INT AUTO_INCREMENT PRIMARY KEY,
                identificador VARCHAR(20) NOT NULL UNIQUE,
                usado         TINYINT(1)  DEFAULT 0,
                equipo_id     INT         DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
}
