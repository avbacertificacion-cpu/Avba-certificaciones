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
            // Para códigos de 10 dígitos: buscar primero por QR individual del accesorio
            if (!$esFolio) {
                $stmt = $this->pdo->prepare(
                    "SELECT i.id, i.id_accesorio, COALESCE(t.nombre,'') AS tipo_nombre,
                            i.marca, i.modelo, i.serie, i.capacidad, i.medidas, i.estado,
                            s.cliente, s.fecha, s.estatus
                     FROM accesorios_izaje i
                     JOIN accesorios_sesiones s ON s.id = i.sesion_id
                     LEFT JOIN accesorios_tipos t ON t.id = i.tipo_id
                     WHERE i.qr_codigo = ? AND s.estatus IN ('APROBADO_CALIDAD','EMITIDO') LIMIT 1"
                );
                $stmt->execute([$q]);
                $acc = $stmt->fetch();
                if ($acc) {
                    $v = calcularVigencia($acc['fecha']);
                    return [
                        'status'  => 'ok',
                        'existe'  => true,
                        'modulo'  => 'accesorio',
                        'vigente' => $v['vigente'],
                        'dias'    => $v['dias'],
                        'datos'   => [
                            'titulo'       => 'Accesorio de Izaje',
                            'tipo'         => $acc['tipo_nombre'] ?: 'Accesorio de Izaje',
                            'id_accesorio' => $acc['id_accesorio'],
                            'marca'        => $acc['marca'],
                            'modelo'       => $acc['modelo'],
                            'serie'        => $acc['serie'],
                            'capacidad'    => $acc['capacidad'],
                            'medidas'      => $acc['medidas'],
                            'estado'       => $acc['estado'],
                            'cliente'      => $acc['cliente'],
                            'fecha'        => $acc['fecha']
                                ? (new DateTime($acc['fecha']))->format('d/m/Y') : '',
                            'vencimiento'  => $v['vencimiento'],
                        ],
                    ];
                }
            }

            // Fallback: buscar por QR o folio de sesión
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

            // Traer TODOS los accesorios de la sesión (no sólo el primero)
            $acc = $this->pdo->prepare(
                "SELECT COALESCE(t.nombre,'') AS tipo_nombre, i.id_accesorio,
                        i.marca, i.modelo, i.serie, i.capacidad, i.medidas, i.estado
                 FROM accesorios_izaje i
                 LEFT JOIN accesorios_tipos t ON t.id = i.tipo_id
                 WHERE i.sesion_id = ? ORDER BY i.id"
            );
            $acc->execute([$sesion['id']]);
            $rows = $acc->fetchAll(PDO::FETCH_ASSOC);

            $items = array_map(fn($r) => [
                'tipo'         => $r['tipo_nombre'] ?: 'Accesorio de Izaje',
                'id_accesorio' => $r['id_accesorio'] ?? '',
                'marca'        => $r['marca']        ?? '',
                'modelo'       => $r['modelo']       ?? '',
                'serie'        => $r['serie']        ?? '',
                'capacidad'    => $r['capacidad']    ?? '',
                'medidas'      => $r['medidas']      ?? '',
                'estado'       => $r['estado']       ?? '',
            ], $rows);

            $total   = count($items);
            $primero = $items[0] ?? [];

            $v = calcularVigencia($sesion['fecha']);
            return [
                'status'  => 'ok',
                'existe'  => true,
                'modulo'  => 'accesorio',
                'vigente' => $v['vigente'],
                'dias'    => $v['dias'],
                'datos'   => [
                    'titulo'      => 'Certificado de Accesorios de Izaje',
                    'tipo'        => $primero['tipo']      ?? 'Accesorio de Izaje',
                    'marca'       => $primero['marca']     ?? '',
                    'modelo'      => $primero['modelo']    ?? '',
                    'serie'       => $primero['serie']     ?? '',
                    'capacidad'   => $primero['capacidad'] ?? '',
                    'cliente'     => $sesion['cliente']    ?? '',
                    'total'       => $total,
                    'items'       => $items,
                    'fecha'       => $sesion['fecha']
                        ? (new DateTime($sesion['fecha']))->format('d/m/Y') : '',
                    'vencimiento' => $v['vencimiento'],
                ],
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ── Personal / Capacitación ────────────────────────────
    private function buscarPersonal(string $q, bool $esFolio): ?array {
        try {
            $cols = "p.nombre_completo, p.curp, p.empresa_nombre, p.fecha_curso, p.estatus,
                     p.capacidad, p.capacidad_na,
                     c.nombre AS curso_nombre, COALESCE(c.texto_certificado,'') AS texto_certificado";
            if ($esFolio) {
                $stmt = $this->pdo->prepare(
                    "SELECT {$cols}
                     FROM participantes_cursos p
                     LEFT JOIN cursos c ON c.id = p.curso_id
                     WHERE p.control = ? AND p.estatus IN ('APROBADO_CALIDAD','EMITIDO') LIMIT 1"
                );
                $stmt->execute([$q]);
            } else {
                $stmt = $this->pdo->prepare(
                    "SELECT {$cols}
                     FROM participantes_cursos p
                     LEFT JOIN cursos c ON c.id = p.curso_id
                     WHERE p.qr_codigo = ? AND p.estatus IN ('APROBADO_CALIDAD','EMITIDO') LIMIT 1"
                );
                $stmt->execute([$q]);
            }
            $row = $stmt->fetch();
            if (!$row) return null;

            $capVal = $row['capacidad_na'] ? 'N/A' : trim($row['capacidad'] ?? '');
            $tbase  = trim($row['texto_certificado'] ?? '');
            $curso  = $tbase
                ? mb_strtoupper(str_ireplace('{capacidad}', $capVal, $tbase), 'UTF-8')
                : mb_strtoupper(trim($row['curso_nombre'] ?? ''), 'UTF-8');

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
                    'curso'       => $curso ?: '—',
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
