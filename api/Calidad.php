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
                    certificado_url AS link, dictamen_url AS dictamen,
                    envio_direccion AS envio, coordenadas_envio,
                    reporte_url, prueba_carga, inspector
             FROM equipos
             WHERE estado IN ('PENDIENTE', 'CONFORME', 'NO CONFORME', 'RECHAZADO', 'RETORNADO')
             ORDER BY marca_temporal DESC"
        );
        $rows = $stmt->fetchAll();

        // Agregar URL del QR y decodificar prueba_carga
        foreach ($rows as &$r) {
            $r['qr_url'] = $r['qr_codigo'] ? urlQR($r['qr_codigo']) : '';
            $r['prueba_carga'] = !empty($r['prueba_carga'])
                ? (json_decode($r['prueba_carga'], true) ?? null)
                : null;
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

        // El código lo captura Calidad libremente: NO tiene que existir en el
        // banco de códigos pre-generados. Sólo se impide reutilizar uno que ya
        // esté asignado a OTRO registro.
        $stmtQR = $this->pdo->prepare(
            "SELECT id, usado, equipo_id FROM qr_codigos WHERE identificador = ?"
        );
        $stmtQR->execute([$qr]);
        $qrRow = $stmtQR->fetch();

        $qrYaEsDeEsteEquipo = $qrRow && $qrRow['usado'] && (int)$qrRow['equipo_id'] === $id;
        if ($qrRow && $qrRow['usado'] && !$qrYaEsDeEsteEquipo)
            return ['status' => 'error', 'message' => 'Ese código QR ya está en uso por otro registro.'];

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

        // Registrar el código como usado (lo inserta en el banco si no existía)
        qrRegistrarUsado($this->pdo, $qr, $id);

        registrarHistorial($this->pdo, $usuario, $id, 'estado', $estadoAnterior, $nuevoEstado);
        registrarHistorial($this->pdo, $usuario, $id, 'qr_codigo', $row['qr_codigo'] ?? null, $qr);

        return ['status' => 'success', 'message' => 'Inspección aprobada y QR asignado correctamente.'];
    }

    // ── Cambiar el inspector que firma la inspección ───────
    public function cambiarInspectorEquipo(array $payload, string $usuario): array {
        $id        = (int) ($payload['id'] ?? $payload['fila'] ?? 0);
        $inspector = trim((string)($payload['inspector'] ?? ''));
        if (!$id) return ['status' => 'error', 'message' => 'ID de equipo requerido.'];
        if ($inspector === '') return ['status' => 'error', 'message' => 'Selecciona un inspector.'];

        $row = $this->obtenerEquipo($id);
        if (!$row) return ['status' => 'error', 'message' => 'Registro no encontrado.'];

        // Validar que el inspector exista y tenga rol INSPECTOR
        $chk = $this->pdo->prepare("SELECT nombre FROM usuarios WHERE usuario = ? AND rol = 'INSPECTOR' LIMIT 1");
        $chk->execute([$inspector]);
        $insp = $chk->fetch();
        if (!$insp) return ['status' => 'error', 'message' => 'El inspector seleccionado no es válido.'];

        $anterior = $row['inspector'] ?? '';
        $this->pdo->prepare("UPDATE equipos SET inspector = ? WHERE id = ?")->execute([$inspector, $id]);
        registrarHistorial($this->pdo, $usuario, $id, 'inspector', $anterior, $inspector);

        return ['status' => 'success', 'message' => 'Inspector que firma actualizado: ' . ($insp['nombre'] ?? $inspector) . '.'];
    }

    // ── Actualizar datos en calidad ────────────────────────
    public function actualizarCalidad(array $payload, string $usuario): array {
        $id = (int) ($payload['id'] ?? $payload['fila'] ?? 0);
        if (!$id) return ['status' => 'error', 'message' => 'ID de equipo requerido.'];

        $row = $this->obtenerEquipo($id);
        if (!$row) return ['status' => 'error', 'message' => 'Registro no encontrado.'];

        $campos  = ['cliente','maquinaria','marca','modelo','serie','capacidad','correo','id_equipo','estado','motivo','direccion'];
        $sets    = [];
        $params  = [];

        foreach ($campos as $campo) {
            if (array_key_exists($campo, $payload)) {
                $sets[]  = "`{$campo}` = ?";
                $params[] = $payload[$campo] === '' ? null : $payload[$campo];
                registrarHistorial($this->pdo, $usuario, $id, $campo, $row[$campo] ?? null, $payload[$campo]);
            }
        }

        // Fecha de inspección: el frontend envía 'fecha' en dd/mm/aaaa (o aaaa-mm-dd)
        if (array_key_exists('fecha', $payload) && trim((string)$payload['fecha']) !== '') {
            $fechaRaw = trim((string)$payload['fecha']);
            $fechaDb  = null;
            if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $fechaRaw, $m)) {
                $fechaDb = "{$m[3]}-{$m[2]}-{$m[1]}";
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaRaw)) {
                $fechaDb = $fechaRaw;
            }
            if ($fechaDb === null) {
                return ['status' => 'error', 'message' => 'Formato de fecha inválido. Usa dd/mm/aaaa.'];
            }
            $sets[]   = '`fecha_inspeccion` = ?';
            $params[] = $fechaDb;
            registrarHistorial($this->pdo, $usuario, $id, 'fecha_inspeccion', $row['fecha_inspeccion'] ?? null, $fechaDb);
        }

        // QR manual
        if (!empty($payload['qr_codigo'])) {
            $sets[]  = '`qr_codigo` = ?';
            $params[] = $payload['qr_codigo'];
            registrarHistorial($this->pdo, $usuario, $id, 'qr_codigo', $row['qr_codigo'] ?? null, $payload['qr_codigo']);
        }

        // Prueba de carga: se guardan TODOS los campos que capture cada
        // plantilla (no solo grúa). Ángulo/altura se recalculan solos cuando
        // hay radio y pluma. Los campos de captura única (CML, etc.) llegan en
        // '__header'. Los campos calculados que reporta el cliente se ignoran
        // (se recalculan aquí) para no confiar en valores manipulables.
        if (!empty($payload['prueba_carga_updates']) && is_array($payload['prueba_carga_updates'])) {
            $updates = $payload['prueba_carga_updates'];
            if (!empty($updates['plantilla'])) {
                $pc = !empty($row['prueba_carga'])
                    ? (json_decode($row['prueba_carga'], true) ?? [])
                    : [];
                $pc['plantilla'] = $updates['plantilla'];

                // Campos de captura única (no por fila): CML, tipo de plataforma, etc.
                if (!empty($updates['__header']) && is_array($updates['__header'])) {
                    foreach ($updates['__header'] as $hk => $hv) {
                        if ($hk === '' ) continue;
                        $pc[$hk] = is_scalar($hv) ? (string)$hv : $hv;
                    }
                }

                foreach ($updates as $rowKey => $fields) {
                    if ($rowKey === 'plantilla' || $rowKey === '__header' || !is_array($fields)) continue;
                    if (!isset($pc[$rowKey]) || !is_array($pc[$rowKey])) $pc[$rowKey] = [];

                    // Guardar todos los campos enviados
                    foreach ($fields as $f => $v) {
                        if ($f === 'angulo' || $f === 'altura') continue; // se recalculan abajo
                        $pc[$rowKey][$f] = is_scalar($v) ? (string)$v : $v;
                    }

                    // Recalcular ángulo/altura de grúa cuando aplican (radio y pluma)
                    $radio = (float)($pc[$rowKey]['radio'] ?? 0);
                    $pluma = (float)($pc[$rowKey]['pluma'] ?? 0);
                    if ($radio > 0 && $pluma > 0 && $radio <= $pluma) {
                        $pc[$rowKey]['angulo'] = (string)round(acos($radio / $pluma) * 180 / M_PI, 1);
                        $pc[$rowKey]['altura'] = (string)round(sqrt($pluma * $pluma - $radio * $radio), 2);
                    } else {
                        // Sin geometría de grúa (no hay radio+pluma): ángulo/altura
                        // se capturan a mano (telehandler, montacargas, ptem…) —
                        // respetar lo que envió el cliente.
                        foreach (['angulo', 'altura'] as $f) {
                            if (array_key_exists($f, $fields)) {
                                $pc[$rowKey][$f] = is_scalar($fields[$f]) ? (string)$fields[$f] : $fields[$f];
                            }
                        }
                    }
                }

                $pcJson = json_encode($pc, JSON_UNESCAPED_UNICODE);
                $sets[]   = '`prueba_carga` = ?';
                $params[]  = $pcJson;
                registrarHistorial($this->pdo, $usuario, $id, 'prueba_carga', $row['prueba_carga'] ?? null, $pcJson);
            }
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

        // Tope de seguridad por lote (evita intentar generar millones de golpe)
        $MAX_LOTE = 100000;

        $hasta = trim($payload['hasta'] ?? '');
        if (!preg_match('/^\d{10}$/', $hasta)) {
            return ['status' => 'error', 'message' => 'El número final debe tener exactamente 10 dígitos.'];
        }

        $ultimoRaw = $this->pdo->query(
            "SELECT MAX(CAST(identificador AS UNSIGNED)) FROM qr_codigos"
        )->fetchColumn();
        $ultimo   = $ultimoRaw ? (int) $ultimoRaw : 0;
        $hastaInt = (int) $hasta;

        // Número inicial: por defecto continúa la serie desde el último
        // registrado; opcionalmente se puede iniciar una serie nueva (por
        // ejemplo con otro prefijo, "5xxxxxxxxx") indicando un "desde".
        $desdeRaw = trim($payload['desde'] ?? '');
        if ($desdeRaw !== '') {
            if (!preg_match('/^\d{10}$/', $desdeRaw)) {
                return ['status' => 'error', 'message' => 'El número inicial debe tener exactamente 10 dígitos.'];
            }
            $desde = (int) $desdeRaw;
        } else {
            $desde = $ultimo + 1;
        }

        if ($hastaInt < $desde) {
            return ['status' => 'error', 'message' => "El número final ({$hastaInt}) debe ser mayor o igual que el inicial ({$desde})."];
        }

        $cantidad = $hastaInt - $desde + 1;
        if ($cantidad > $MAX_LOTE) {
            return ['status' => 'error', 'message' =>
                'El rango solicitado genera ' . number_format($cantidad) . ' códigos, y el máximo por lote es ' .
                number_format($MAX_LOTE) . '. Usa el campo "Iniciar en" para arrancar una serie nueva (p. ej. 5000000000) y define un rango más pequeño.'];
        }

        $stmt = $this->pdo->prepare(
            "INSERT IGNORE INTO qr_codigos (identificador, usado) VALUES (?, 0)"
        );
        $this->pdo->beginTransaction();
        try {
            $insertados = 0;
            for ($n = $desde; $n <= $hastaInt; $n++) {
                $stmt->execute([str_pad((string) $n, 10, '0', STR_PAD_LEFT)]);
                $insertados += $stmt->rowCount();
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            return ['status' => 'error', 'message' => 'Error al insertar códigos: ' . $e->getMessage()];
        }

        $omitidos = $cantidad - $insertados;
        $msg = number_format($insertados) . ' código' . ($insertados !== 1 ? 's' : '') . ' generado' . ($insertados !== 1 ? 's' : '') . ' correctamente.';
        if ($omitidos > 0) $msg .= ' (' . number_format($omitidos) . ' ya existían y se omitieron.)';

        return ['status' => 'success', 'message' => $msg, 'cantidad' => $insertados];
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

    // ── Eliminar equipo y liberar QR ───────────────────────
    public function eliminarEquipo(int $id): array {
        $row = $this->pdo->prepare("SELECT qr_codigo FROM equipos WHERE id = ?");
        $row->execute([$id]);
        $equipo = $row->fetch();
        if (!$equipo) return ['status' => 'error', 'message' => 'Registro no encontrado.'];

        try {
            if (!empty($equipo['qr_codigo'])) {
                $this->pdo->prepare(
                    "UPDATE qr_codigos SET usado = 0, equipo_id = NULL WHERE identificador = ?"
                )->execute([$equipo['qr_codigo']]);
            }
            // inspeccion_checklist tiene ON DELETE CASCADE → se borra automáticamente
            // historial_general tiene ON DELETE SET NULL → no bloquea el DELETE
            $this->pdo->prepare("DELETE FROM equipos WHERE id = ?")->execute([$id]);
            return ['status' => 'success', 'message' => 'Registro eliminado correctamente.'];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
}
