<?php
/**
 * AVBA Certificaciones — Módulo de RH / Asistencia del cliente
 *
 * Fase 1: sobre el padrón existente "Mi Personal" (cliente_personal), cada
 * cliente define sus POLÍTICAS (faltas permitidas y su ventana, actas máximas,
 * retardos que equivalen a una falta…) y registra INCIDENCIAS (faltas,
 * retardos, actas administrativas, permisos, incapacidades). Con eso el
 * sistema calcula la SALUD LABORAL de cada empleado (semáforo + alertas).
 *
 * Multi-cliente: todo va acotado por id_cliente y respeta el alcance de
 * sub-usuarios sobre el módulo de personal.
 */
class ClienteRH {
    private PDO $pdo;

    private const TIPOS = ['falta', 'retardo', 'acta', 'permiso', 'incapacidad', 'vacaciones'];

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->migrate();
    }

    private function migrate(): void {
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS cliente_rh_politicas (
                  id_cliente          VARCHAR(20) PRIMARY KEY,
                  faltas_max          INT NOT NULL DEFAULT 1,
                  faltas_ventana      VARCHAR(20) NOT NULL DEFAULT 'semana',
                  faltas_ventana_dias INT NOT NULL DEFAULT 7,
                  actas_max           INT NOT NULL DEFAULT 3,
                  retardos_por_falta  INT NOT NULL DEFAULT 3,
                  tolerancia_min      INT NOT NULL DEFAULT 10,
                  updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS cliente_rh_incidencias (
                  id          INT AUTO_INCREMENT PRIMARY KEY,
                  id_cliente  VARCHAR(20) NOT NULL,
                  empleado_id INT NOT NULL,
                  tipo        VARCHAR(20) NOT NULL,
                  justificada TINYINT NOT NULL DEFAULT 0,
                  fecha       DATE NOT NULL,
                  notas       TEXT NULL,
                  archivo_url VARCHAR(300) NULL,
                  created_by  VARCHAR(120) NULL,
                  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  KEY idx_cli (id_cliente),
                  KEY idx_emp (empleado_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\PDOException $e) {
            error_log('[ClienteRH] migrate: ' . $e->getMessage());
        }
    }

    private function norm(string $id): string {
        $id = trim($id);
        return (ctype_digit($id)) ? str_pad($id, 5, '0', STR_PAD_LEFT) : $id;
    }

    // ── Políticas ─────────────────────────────────────────────
    public function obtenerPoliticas(string $idCliente): array {
        $idCliente = $this->norm($idCliente);
        $s = $this->pdo->prepare("SELECT * FROM cliente_rh_politicas WHERE id_cliente=?");
        $s->execute([$idCliente]);
        $p = $s->fetch(PDO::FETCH_ASSOC);
        if (!$p) {
            $p = ['faltas_max' => 1, 'faltas_ventana' => 'semana', 'faltas_ventana_dias' => 7,
                  'actas_max' => 3, 'retardos_por_falta' => 3, 'tolerancia_min' => 10];
        }
        return [
            'status'   => 'success',
            'politicas' => [
                'faltas_max'          => (int)$p['faltas_max'],
                'faltas_ventana'      => $p['faltas_ventana'],
                'faltas_ventana_dias' => (int)$p['faltas_ventana_dias'],
                'actas_max'           => (int)$p['actas_max'],
                'retardos_por_falta'  => (int)$p['retardos_por_falta'],
                'tolerancia_min'      => (int)$p['tolerancia_min'],
            ],
        ];
    }

    public function guardarPoliticas(string $idCliente, array $data): array {
        $idCliente = $this->norm($idCliente);
        $ventana = in_array($data['faltas_ventana'] ?? '', ['semana', 'movil', 'quincena', 'mes'], true)
            ? $data['faltas_ventana'] : 'semana';
        $faltasMax = max(0, (int)($data['faltas_max'] ?? 1));
        $ventDias  = max(1, min(60, (int)($data['faltas_ventana_dias'] ?? 7)));
        $actasMax  = max(1, (int)($data['actas_max'] ?? 3));
        $retXfalta = max(1, (int)($data['retardos_por_falta'] ?? 3));
        $tol       = max(0, min(120, (int)($data['tolerancia_min'] ?? 10)));

        $this->pdo->prepare("
            INSERT INTO cliente_rh_politicas
              (id_cliente, faltas_max, faltas_ventana, faltas_ventana_dias, actas_max, retardos_por_falta, tolerancia_min)
            VALUES (?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE faltas_max=VALUES(faltas_max), faltas_ventana=VALUES(faltas_ventana),
              faltas_ventana_dias=VALUES(faltas_ventana_dias), actas_max=VALUES(actas_max),
              retardos_por_falta=VALUES(retardos_por_falta), tolerancia_min=VALUES(tolerancia_min)
        ")->execute([$idCliente, $faltasMax, $ventana, $ventDias, $actasMax, $retXfalta, $tol]);

        return ['status' => 'success', 'message' => 'Políticas guardadas.'];
    }

    /** Devuelve [desde, hasta, etiqueta] de la ventana de faltas según políticas. */
    private function rangoVentana(array $pol): array {
        $hoy = date('Y-m-d');
        switch ($pol['faltas_ventana']) {
            case 'movil':
                $dias = (int)$pol['faltas_ventana_dias'];
                return [date('Y-m-d', strtotime("-" . ($dias - 1) . " day")), $hoy, "últimos {$dias} días"];
            case 'quincena':
                $d = (int)date('j');
                return $d <= 15
                    ? [date('Y-m-01'), $hoy, 'quincena actual']
                    : [date('Y-m-16'), $hoy, 'quincena actual'];
            case 'mes':
                return [date('Y-m-01'), $hoy, 'mes actual'];
            case 'semana':
            default:
                // Lunes de la semana actual
                $lunes = date('Y-m-d', strtotime('monday this week'));
                return [$lunes, $hoy, 'semana actual'];
        }
    }

    // ── Incidencias ───────────────────────────────────────────
    public function listarIncidencias(string $idCliente, array $filtros = [], ?array $soloIds = null): array {
        $idCliente = $this->norm($idCliente);
        $where = ['i.id_cliente = ?'];
        $params = [$idCliente];
        if (!empty($filtros['empleado_id'])) { $where[] = 'i.empleado_id = ?'; $params[] = (int)$filtros['empleado_id']; }
        if (!empty($filtros['tipo']))        { $where[] = 'i.tipo = ?';        $params[] = $filtros['tipo']; }
        if ($soloIds !== null) {
            if (!$soloIds) return ['status' => 'success', 'data' => []];
            $in = implode(',', array_fill(0, count($soloIds), '?'));
            $where[] = "i.empleado_id IN ($in)";
            $params = array_merge($params, array_map('intval', $soloIds));
        }
        $sql = "SELECT i.id, i.empleado_id, i.tipo, i.justificada,
                       DATE_FORMAT(i.fecha,'%d/%m/%Y') AS fecha, i.notas, i.archivo_url,
                       p.nombre AS empleado
                FROM cliente_rh_incidencias i
                LEFT JOIN cliente_personal p ON p.id = i.empleado_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY i.fecha DESC, i.id DESC LIMIT 300";
        $s = $this->pdo->prepare($sql);
        $s->execute($params);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id']; $r['empleado_id'] = (int)$r['empleado_id'];
            $r['justificada'] = (int)$r['justificada'];
            $r['archivo_url'] = $r['archivo_url'] ? (rtrim(SITE_URL, '/') . '/' . ltrim($r['archivo_url'], '/')) : '';
        }
        unset($r);
        return ['status' => 'success', 'data' => $rows];
    }

    public function guardarIncidencia(string $idCliente, array $data, string $usuario = ''): array {
        $idCliente = $this->norm($idCliente);
        $empId = (int)($data['empleado_id'] ?? 0);
        $tipo  = strtolower(trim((string)($data['tipo'] ?? '')));
        $fecha = trim((string)($data['fecha'] ?? ''));
        if (!$empId) return ['status' => 'error', 'message' => 'Selecciona un empleado.'];
        if (!in_array($tipo, self::TIPOS, true)) return ['status' => 'error', 'message' => 'Tipo de incidencia no válido.'];
        if ($fecha === '') return ['status' => 'error', 'message' => 'Indica la fecha.'];

        // Validar que el empleado pertenezca al cliente
        $chk = $this->pdo->prepare("SELECT id FROM cliente_personal WHERE id=? AND id_cliente=?");
        $chk->execute([$empId, $idCliente]);
        if (!$chk->fetch()) return ['status' => 'error', 'message' => 'Empleado no encontrado.'];

        $fechaDb = date('Y-m-d', strtotime(str_replace('/', '-', $fecha)));
        $justif  = !empty($data['justificada']) ? 1 : 0;
        $notas   = trim((string)($data['notas'] ?? '')) ?: null;

        $this->pdo->prepare("
            INSERT INTO cliente_rh_incidencias (id_cliente, empleado_id, tipo, justificada, fecha, notas, created_by)
            VALUES (?,?,?,?,?,?,?)
        ")->execute([$idCliente, $empId, $tipo, $justif, $fechaDb, $notas, $usuario ?: null]);

        return ['status' => 'success', 'message' => 'Incidencia registrada.'];
    }

    public function eliminarIncidencia(int $id, string $idCliente): array {
        $idCliente = $this->norm($idCliente);
        $this->pdo->prepare("DELETE FROM cliente_rh_incidencias WHERE id=? AND id_cliente=?")->execute([$id, $idCliente]);
        return ['status' => 'success', 'message' => 'Incidencia eliminada.'];
    }

    // ── Salud laboral ─────────────────────────────────────────
    public function saludLaboral(string $idCliente, ?array $soloIds = null): array {
        $idCliente = $this->norm($idCliente);
        $pol = $this->obtenerPoliticas($idCliente)['politicas'];
        [$desde, $hasta, $etiquetaVent] = $this->rangoVentana($pol);

        // Empleados (del padrón Mi Personal)
        $wEmp = ['id_cliente = ?']; $pEmp = [$idCliente];
        if ($soloIds !== null) {
            if (!$soloIds) return ['status' => 'success', 'ventana' => $etiquetaVent, 'politicas' => $pol, 'empleados' => [], 'resumen' => ['sana'=>0,'observacion'=>0,'riesgo'=>0]];
            $in = implode(',', array_fill(0, count($soloIds), '?'));
            $wEmp[] = "id IN ($in)"; $pEmp = array_merge($pEmp, array_map('intval', $soloIds));
        }
        $sEmp = $this->pdo->prepare("SELECT id, nombre, puesto, departamento, estado FROM cliente_personal WHERE " . implode(' AND ', $wEmp) . " ORDER BY nombre");
        $sEmp->execute($pEmp);
        $empleados = $sEmp->fetchAll(PDO::FETCH_ASSOC);
        if (!$empleados) {
            return ['status' => 'success', 'ventana' => $etiquetaVent, 'politicas' => $pol, 'empleados' => [], 'resumen' => ['sana'=>0,'observacion'=>0,'riesgo'=>0]];
        }
        $ids = array_column($empleados, 'id');
        $in  = implode(',', array_fill(0, count($ids), '?'));

        // Conteos en ventana (faltas injustificadas y retardos) por empleado
        $ventStmt = $this->pdo->prepare("
            SELECT empleado_id,
                   SUM(tipo='falta' AND justificada=0) AS faltas_inj,
                   SUM(tipo='falta' AND justificada=1) AS faltas_just,
                   SUM(tipo='retardo')                 AS retardos
            FROM cliente_rh_incidencias
            WHERE id_cliente=? AND empleado_id IN ($in) AND fecha BETWEEN ? AND ?
            GROUP BY empleado_id
        ");
        $ventStmt->execute(array_merge([$idCliente], $ids, [$desde, $hasta]));
        $ventBy = [];
        foreach ($ventStmt->fetchAll(PDO::FETCH_ASSOC) as $r) $ventBy[(int)$r['empleado_id']] = $r;

        // Actas acumuladas (histórico) e incapacidades/permisos vigentes por empleado
        $totStmt = $this->pdo->prepare("
            SELECT empleado_id,
                   SUM(tipo='acta') AS actas,
                   SUM(tipo IN ('incapacidad','permiso','vacaciones') AND fecha >= CURDATE() - INTERVAL 30 DAY) AS licencias
            FROM cliente_rh_incidencias
            WHERE id_cliente=? AND empleado_id IN ($in)
            GROUP BY empleado_id
        ");
        $totStmt->execute(array_merge([$idCliente], $ids));
        $totBy = [];
        foreach ($totStmt->fetchAll(PDO::FETCH_ASSOC) as $r) $totBy[(int)$r['empleado_id']] = $r;

        $resumen = ['sana' => 0, 'observacion' => 0, 'riesgo' => 0];
        $out = [];
        foreach ($empleados as $e) {
            $id = (int)$e['id'];
            $v = $ventBy[$id] ?? [];
            $t = $totBy[$id] ?? [];
            $faltasInj = (int)($v['faltas_inj'] ?? 0);
            $retardos  = (int)($v['retardos'] ?? 0);
            $actas     = (int)($t['actas'] ?? 0);
            $licencias = (int)($t['licencias'] ?? 0);
            // Retardos que se convierten en faltas
            $faltasEfect = $faltasInj + intdiv($retardos, max(1, $pol['retardos_por_falta']));

            $estado = 'sana'; $motivos = [];
            if ($faltasEfect > $pol['faltas_max'] || $actas >= $pol['actas_max']) {
                $estado = 'riesgo';
            } elseif ($faltasEfect >= $pol['faltas_max'] || $actas >= max(1, $pol['actas_max'] - 1) || $retardos > 0 || $licencias > 0) {
                $estado = 'observacion';
            }
            if ($faltasInj > 0)  $motivos[] = "$faltasInj falta" . ($faltasInj != 1 ? 's' : '') . " injustificada" . ($faltasInj != 1 ? 's' : '') . " ($etiquetaVent)";
            if ($retardos > 0)   $motivos[] = "$retardos retardo" . ($retardos != 1 ? 's' : '') . " ($etiquetaVent)";
            if ($actas > 0)      $motivos[] = "$actas/{$pol['actas_max']} actas administrativas";
            if ($licencias > 0)  $motivos[] = "$licencias licencia" . ($licencias != 1 ? 's' : '') . " reciente" . ($licencias != 1 ? 's' : '');

            $resumen[$estado]++;
            $out[] = [
                'id'           => $id,
                'nombre'       => $e['nombre'],
                'puesto'       => $e['puesto'] ?? '',
                'departamento' => $e['departamento'] ?? '',
                'estado_lab'   => $estado,
                'faltas'       => $faltasInj,
                'faltas_just'  => (int)($v['faltas_just'] ?? 0),
                'retardos'     => $retardos,
                'faltas_efect' => $faltasEfect,
                'actas'        => $actas,
                'licencias'    => $licencias,
                'motivos'      => $motivos,
            ];
        }
        // Ordenar: riesgo → observación → sana
        $rank = ['riesgo' => 0, 'observacion' => 1, 'sana' => 2];
        usort($out, fn($a, $b) => ($rank[$a['estado_lab']] <=> $rank[$b['estado_lab']]) ?: strcmp($a['nombre'], $b['nombre']));

        return [
            'status'    => 'success',
            'ventana'   => $etiquetaVent,
            'politicas' => $pol,
            'resumen'   => $resumen,
            'empleados' => $out,
        ];
    }
}
