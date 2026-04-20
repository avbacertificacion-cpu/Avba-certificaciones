<?php
/**
 * AVBA Certificaciones — Módulo Validación QR
 */

class ValidarQR {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Acepta:
     *   - Código QR de 10 dígitos:    "1000000023"
     *   - Número de control:           "12345-67890"  (ya extraído del formato AB.NNNNN-NNNNN-2026MX)
     * Busca en los tres módulos: equipos → accesorios → personal.
     */
    public function validarQR(string $qrBuscado): array {
        $qrBuscado = trim($qrBuscado);
        if (!$qrBuscado) return ['status' => 'ok', 'existe' => false];

        $esFolio = (bool) preg_match('/^\d{5}-\d{5}$/', $qrBuscado);

        $result = $this->buscarEquipo($qrBuscado, $esFolio);
        if ($result) return $result;

        $result = $this->buscarAccesorio($qrBuscado, $esFolio);
        if ($result) return $result;

        $result = $this->buscarPersonal($qrBuscado, $esFolio);
        if ($result) return $result;

        return ['status' => 'ok', 'existe' => false];
    }

    // ── Maquinaria / Equipo ────────────────────────────────
    private function buscarEquipo(string $q, bool $esFolio): ?array {
        if ($esFolio) {
            $stmt = $this->pdo->prepare(
                "SELECT maquinaria, marca, modelo, serie, capacidad, fecha_inspeccion
                 FROM equipos WHERE control = ? LIMIT 1"
            );
            $stmt->execute([$q]);
        } else {
            $stmt = $this->pdo->prepare(
                "SELECT maquinaria, marca, modelo, serie, capacidad, fecha_inspeccion
                 FROM equipos WHERE qr_codigo = ? LIMIT 1"
            );
            $stmt->execute([$q]);
        }
        $row = $stmt->fetch();
        if (!$row) return null;

        $v = calcularVigencia($row['fecha_inspeccion']);
        return [
            'status'  => 'ok',
            'existe'  => true,
            'modulo'  => 'equipo',
            'vigente' => $v['vigente'],
            'dias'    => $v['dias'],
            'datos'   => [
                'titulo'      => 'Certificado de Maquinaria',
                'maquinaria'  => $row['maquinaria'],
                'marca'       => $row['marca'],
                'modelo'      => $row['modelo'],
                'serie'       => $row['serie'],
                'capacidad'   => $row['capacidad'],
                'fecha'       => $row['fecha_inspeccion']
                    ? (new DateTime($row['fecha_inspeccion']))->format('d/m/Y') : '',
                'vencimiento' => $v['vencimiento'],
            ],
        ];
    }

    // ── Accesorios de Izaje ────────────────────────────────
    private function buscarAccesorio(string $q, bool $esFolio): ?array {
        try {
            if ($esFolio) {
                $stmt = $this->pdo->prepare(
                    "SELECT s.id, s.cliente, s.fecha, s.estatus
                     FROM accesorios_sesiones s
                     WHERE s.control = ? AND s.estatus IN ('APROBADO_CALIDAD','EMITIDO') LIMIT 1"
                );
                $stmt->execute([$q]);
            } else {
                $stmt = $this->pdo->prepare(
                    "SELECT s.id, s.cliente, s.fecha, s.estatus
                     FROM accesorios_sesiones s
                     WHERE s.qr_codigo = ? AND s.estatus IN ('APROBADO_CALIDAD','EMITIDO') LIMIT 1"
                );
                $stmt->execute([$q]);
            }
            $sesion = $stmt->fetch();
            if (!$sesion) return null;

            // Obtener primer accesorio de la sesión para mostrar detalles
            $acc = $this->pdo->prepare(
                "SELECT i.tipo_nombre, i.marca, i.modelo, i.serie, i.capacidad
                 FROM accesorios_izaje i
                 WHERE i.sesion_id = ? ORDER BY i.id LIMIT 1"
            );
            $acc->execute([$sesion['id']]);
            $accRow = $acc->fetch();

            // Contar accesorios de la sesión
            $cntStmt = $this->pdo->prepare("SELECT COUNT(*) FROM accesorios_izaje WHERE sesion_id = ?");
            $cntStmt->execute([$sesion['id']]);
            $total = (int) $cntStmt->fetchColumn();

            $v = calcularVigencia($sesion['fecha']);
            return [
                'status'  => 'ok',
                'existe'  => true,
                'modulo'  => 'accesorio',
                'vigente' => $v['vigente'],
                'dias'    => $v['dias'],
                'datos'   => [
                    'titulo'     => 'Certificado de Accesorio de Izaje',
                    'tipo'       => $accRow['tipo_nombre'] ?? 'Accesorio de Izaje',
                    'marca'      => $accRow['marca']       ?? '',
                    'modelo'     => $accRow['modelo']      ?? '',
                    'serie'      => $accRow['serie']       ?? '',
                    'capacidad'  => $accRow['capacidad']   ?? '',
                    'cliente'    => $sesion['cliente']     ?? '',
                    'fecha'      => $sesion['fecha']
                        ? (new DateTime($sesion['fecha']))->format('d/m/Y') : '',
                    'vencimiento'=> $v['vencimiento'],
                ],
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ── Personal / Capacitación ────────────────────────────
    private function buscarPersonal(string $q, bool $esFolio): ?array {
        try {
            if ($esFolio) {
                $stmt = $this->pdo->prepare(
                    "SELECT p.nombre_completo, p.curp, p.empresa_nombre, p.fecha_curso,
                            c.nombre AS curso_nombre, p.estatus
                     FROM participantes_cursos p
                     LEFT JOIN cursos c ON c.id = p.curso_id
                     WHERE p.control = ? AND p.estatus IN ('APROBADO_CALIDAD','EMITIDO') LIMIT 1"
                );
                $stmt->execute([$q]);
            } else {
                $stmt = $this->pdo->prepare(
                    "SELECT p.nombre_completo, p.curp, p.empresa_nombre, p.fecha_curso,
                            c.nombre AS curso_nombre, p.estatus
                     FROM participantes_cursos p
                     LEFT JOIN cursos c ON c.id = p.curso_id
                     WHERE p.qr_codigo = ? AND p.estatus IN ('APROBADO_CALIDAD','EMITIDO') LIMIT 1"
                );
                $stmt->execute([$q]);
            }
            $row = $stmt->fetch();
            if (!$row) return null;

            $v = calcularVigencia($row['fecha_curso']);
            return [
                'status'  => 'ok',
                'existe'  => true,
                'modulo'  => 'personal',
                'vigente' => $v['vigente'],
                'dias'    => $v['dias'],
                'datos'   => [
                    'titulo'      => 'Constancia de Capacitación',
                    'nombre'      => $row['nombre_completo'],
                    'curp'        => $row['curp'],
                    'curso'       => $row['curso_nombre'] ?? '—',
                    'fecha'       => $row['fecha_curso']
                        ? (new DateTime($row['fecha_curso']))->format('d/m/Y') : '',
                    'vencimiento' => $v['vencimiento'],
                ],
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }
}
