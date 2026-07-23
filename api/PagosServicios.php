<?php
/**
 * AVBA Certificaciones — Control de vencimientos y pagos de servicios
 *
 * Lleva el control de pagos recurrentes de la operación: planes de renta de
 * teléfonos, internet, luz, agua, gas, software, seguros, etc. Registra el
 * próximo vencimiento de cada servicio, avisa cuando está por vencer o vencido
 * y, al marcar como pagado, adelanta automáticamente la fecha al siguiente
 * periodo según su periodicidad.
 *
 * Sólo lo usan roles administrativos (ADMIN / ADMINISTRATIVO).
 */
class PagosServicios {
    private PDO $pdo;

    /** Periodicidades válidas → meses a sumar al marcar pagado. */
    private const PERIODOS = [
        'mensual'    => 1,
        'bimestral'  => 2,
        'trimestral' => 3,
        'semestral'  => 6,
        'anual'      => 12,
        'unico'      => 0,
    ];

    private const CATEGORIAS = [
        'telefono', 'internet', 'luz', 'agua', 'gas',
        'renta', 'software', 'seguro', 'nomina', 'otro',
    ];

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->migrate();
    }

    private function migrate(): void {
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS servicios_pagos (
                  id                INT AUTO_INCREMENT PRIMARY KEY,
                  concepto          VARCHAR(150) NOT NULL,
                  categoria         VARCHAR(40)  NOT NULL DEFAULT 'otro',
                  proveedor         VARCHAR(120) NULL,
                  referencia        VARCHAR(120) NULL,
                  monto             DECIMAL(10,2) NULL,
                  periodicidad      VARCHAR(20)  NOT NULL DEFAULT 'mensual',
                  fecha_vencimiento DATE NOT NULL,
                  recordatorio_dias INT NOT NULL DEFAULT 5,
                  ultimo_pago       DATE NULL,
                  estatus           VARCHAR(20)  NOT NULL DEFAULT 'pendiente',
                  notas             TEXT NULL,
                  activo            TINYINT NOT NULL DEFAULT 1,
                  created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\PDOException $e) {
            error_log('[PagosServicios] migrate: ' . $e->getMessage());
        }
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS servicios_pagos_docs (
                  id          INT AUTO_INCREMENT PRIMARY KEY,
                  servicio_id INT NOT NULL,
                  nombre      VARCHAR(200) NOT NULL,
                  archivo_url VARCHAR(300) NOT NULL,
                  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  INDEX idx_servicio (servicio_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\PDOException $e) {
            error_log('[PagosServicios] migrate docs: ' . $e->getMessage());
        }
    }

    private function urlAbs(string $rel): string {
        if ($rel === '') return '';
        if (preg_match('#^https?://#i', $rel)) return $rel;
        return rtrim(SITE_URL, '/') . '/' . ltrim($rel, '/');
    }

    private function normCategoria(string $c): string {
        $c = strtolower(trim($c));
        return in_array($c, self::CATEGORIAS, true) ? $c : 'otro';
    }

    private function normPeriodicidad(string $p): string {
        $p = strtolower(trim($p));
        return array_key_exists($p, self::PERIODOS) ? $p : 'mensual';
    }

    /** Lista todos los servicios activos con su estado de vencimiento calculado. */
    public function listar(): array {
        try {
            $rows = $this->pdo->query(
                "SELECT *, DATEDIFF(fecha_vencimiento, CURDATE()) AS dias_restantes
                 FROM servicios_pagos
                 WHERE activo = 1
                 ORDER BY fecha_vencimiento ASC, concepto ASC"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log('[PagosServicios] listar: ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'No se pudieron cargar los servicios.'];
        }

        $resumen = ['total' => 0, 'vencidos' => 0, 'por_vencer' => 0, 'al_dia' => 0, 'monto_mes' => 0.0];
        foreach ($rows as &$r) {
            $dias = (int)$r['dias_restantes'];
            $rec  = (int)$r['recordatorio_dias'];
            if ($r['estatus'] === 'pagado' && $r['periodicidad'] === 'unico') {
                $r['estado_venc'] = 'pagado';
            } elseif ($dias < 0) {
                $r['estado_venc'] = 'vencido';   $resumen['vencidos']++;
            } elseif ($dias <= $rec) {
                $r['estado_venc'] = 'por_vencer'; $resumen['por_vencer']++;
            } else {
                $r['estado_venc'] = 'al_dia';     $resumen['al_dia']++;
            }
            $resumen['total']++;
            // Monto mensualizado aproximado (para el resumen)
            $meses = self::PERIODOS[$r['periodicidad']] ?? 1;
            if ($meses > 0 && $r['monto'] !== null) {
                $resumen['monto_mes'] += (float)$r['monto'] / $meses;
            }
        }
        unset($r);
        $resumen['monto_mes'] = round($resumen['monto_mes'], 2);

        return ['status' => 'success', 'data' => $rows, 'resumen' => $resumen];
    }

    /** Crea o actualiza un servicio. */
    public function guardar(array $payload): array {
        $id       = (int)($payload['id'] ?? 0);
        $concepto = trim((string)($payload['concepto'] ?? ''));
        $fechaV   = trim((string)($payload['fecha_vencimiento'] ?? ''));

        if ($concepto === '') return ['status' => 'error', 'message' => 'El concepto es obligatorio.'];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaV) || !strtotime($fechaV)) {
            return ['status' => 'error', 'message' => 'La fecha de vencimiento no es válida.'];
        }

        $categoria    = $this->normCategoria((string)($payload['categoria'] ?? 'otro'));
        $periodicidad = $this->normPeriodicidad((string)($payload['periodicidad'] ?? 'mensual'));
        $proveedor    = trim((string)($payload['proveedor'] ?? '')) ?: null;
        $referencia   = trim((string)($payload['referencia'] ?? '')) ?: null;
        $notas        = trim((string)($payload['notas'] ?? '')) ?: null;
        $montoRaw     = $payload['monto'] ?? '';
        $monto        = ($montoRaw === '' || $montoRaw === null) ? null : round((float)$montoRaw, 2);
        $recDias      = max(0, min(60, (int)($payload['recordatorio_dias'] ?? 5)));

        try {
            if ($id > 0) {
                $this->pdo->prepare(
                    "UPDATE servicios_pagos SET
                        concepto=?, categoria=?, proveedor=?, referencia=?, monto=?,
                        periodicidad=?, fecha_vencimiento=?, recordatorio_dias=?, notas=?
                     WHERE id=?"
                )->execute([$concepto, $categoria, $proveedor, $referencia, $monto,
                            $periodicidad, $fechaV, $recDias, $notas, $id]);
                return ['status' => 'success', 'message' => 'Servicio actualizado.', 'id' => $id];
            }

            $this->pdo->prepare(
                "INSERT INTO servicios_pagos
                    (concepto, categoria, proveedor, referencia, monto,
                     periodicidad, fecha_vencimiento, recordatorio_dias, notas)
                 VALUES (?,?,?,?,?,?,?,?,?)"
            )->execute([$concepto, $categoria, $proveedor, $referencia, $monto,
                        $periodicidad, $fechaV, $recDias, $notas]);
            return ['status' => 'success', 'message' => 'Servicio agregado.', 'id' => (int)$this->pdo->lastInsertId()];
        } catch (\PDOException $e) {
            error_log('[PagosServicios] guardar: ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'No se pudo guardar el servicio.'];
        }
    }

    /** Marca un servicio como pagado y adelanta al siguiente periodo. */
    public function marcarPagado(int $id): array {
        if ($id <= 0) return ['status' => 'error', 'message' => 'ID inválido.'];
        $stmt = $this->pdo->prepare("SELECT * FROM servicios_pagos WHERE id=? AND activo=1");
        $stmt->execute([$id]);
        $s = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$s) return ['status' => 'error', 'message' => 'Servicio no encontrado.'];

        $meses = self::PERIODOS[$s['periodicidad']] ?? 1;

        if ($meses <= 0) {
            // Pago único: se marca pagado y no se reprograma
            $this->pdo->prepare(
                "UPDATE servicios_pagos SET ultimo_pago=CURDATE(), estatus='pagado' WHERE id=?"
            )->execute([$id]);
            return ['status' => 'success', 'message' => 'Registrado como pagado.'];
        }

        // Adelantar la fecha de vencimiento sumando el periodo hasta que quede a futuro
        try {
            $venc = new DateTime($s['fecha_vencimiento']);
            $hoy  = new DateTime('today');
            do {
                $venc->modify("+{$meses} month");
            } while ($venc <= $hoy);
            $nueva = $venc->format('Y-m-d');
        } catch (\Exception $e) {
            $nueva = date('Y-m-d', strtotime($s['fecha_vencimiento'] . " +{$meses} month"));
        }

        $this->pdo->prepare(
            "UPDATE servicios_pagos
             SET ultimo_pago=CURDATE(), fecha_vencimiento=?, estatus='pendiente'
             WHERE id=?"
        )->execute([$nueva, $id]);

        return ['status' => 'success', 'message' => 'Pago registrado. Próximo vencimiento: ' . $nueva, 'fecha_vencimiento' => $nueva];
    }

    public function eliminar(int $id): array {
        if ($id <= 0) return ['status' => 'error', 'message' => 'ID inválido.'];
        $this->pdo->prepare("UPDATE servicios_pagos SET activo=0 WHERE id=?")->execute([$id]);
        return ['status' => 'success', 'message' => 'Servicio eliminado.'];
    }

    // ── Documentos por servicio ───────────────────────────────
    public function listarDocs(int $servicioId): array {
        if ($servicioId <= 0) return ['status' => 'error', 'message' => 'ID inválido.'];
        $stmt = $this->pdo->prepare(
            "SELECT id, nombre, archivo_url, DATE_FORMAT(created_at,'%d/%m/%Y') AS fecha
             FROM servicios_pagos_docs WHERE servicio_id = ? ORDER BY id DESC"
        );
        $stmt->execute([$servicioId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) { $r['url'] = $this->urlAbs($r['archivo_url']); $r['id'] = (int)$r['id']; }
        unset($r);
        return ['status' => 'success', 'data' => $rows];
    }

    public function subirDoc(int $servicioId, array $file, string $nombre): array {
        if ($servicioId <= 0) return ['status' => 'error', 'message' => 'Servicio inválido.'];
        $chk = $this->pdo->prepare("SELECT id FROM servicios_pagos WHERE id = ?");
        $chk->execute([$servicioId]);
        if (!$chk->fetch()) return ['status' => 'error', 'message' => 'Servicio no encontrado.'];

        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return ['status' => 'error', 'message' => 'Selecciona un archivo válido.'];
        }
        $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        if (!in_array($ext, ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'docx'], true)) {
            return ['status' => 'error', 'message' => 'Formato no permitido. Usa PDF, imagen o DOCX.'];
        }
        if (($file['size'] ?? 0) > 15 * 1024 * 1024) {
            return ['status' => 'error', 'message' => 'El archivo no debe superar 15 MB.'];
        }

        $dir = __DIR__ . '/../uploads/servicios/';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $fname   = 'serv_' . $servicioId . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
        $destino = $dir . $fname;
        if (!move_uploaded_file($file['tmp_name'], $destino)) {
            return ['status' => 'error', 'message' => 'No se pudo guardar el documento.'];
        }

        $label = trim($nombre) ?: ($file['name'] ?? $fname);
        $rel   = 'uploads/servicios/' . $fname;
        $this->pdo->prepare(
            "INSERT INTO servicios_pagos_docs (servicio_id, nombre, archivo_url) VALUES (?,?,?)"
        )->execute([$servicioId, mb_substr($label, 0, 200), $rel]);

        return ['status' => 'success', 'message' => 'Documento agregado.', 'id' => (int)$this->pdo->lastInsertId()];
    }

    public function eliminarDoc(int $docId): array {
        if ($docId <= 0) return ['status' => 'error', 'message' => 'ID inválido.'];
        $stmt = $this->pdo->prepare("SELECT archivo_url FROM servicios_pagos_docs WHERE id = ?");
        $stmt->execute([$docId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return ['status' => 'error', 'message' => 'Documento no encontrado.'];

        $this->pdo->prepare("DELETE FROM servicios_pagos_docs WHERE id = ?")->execute([$docId]);
        $abs = __DIR__ . '/../' . $row['archivo_url'];
        if (is_file($abs)) @unlink($abs);
        return ['status' => 'success', 'message' => 'Documento eliminado.'];
    }
}
