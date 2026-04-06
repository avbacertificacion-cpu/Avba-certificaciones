<?php
/**
 * AVBA Certificaciones — Módulo Validación QR
 * Migración de ValidarQR.gs → PHP
 */

class ValidarQR {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Valida un código QR (10 dígitos) o un folio (NNNNN-NNNNN).
     * Replica exactamente la lógica de validarQR() en ValidarQR.gs
     */
    public function validarQR(string $qrBuscado): array {
        $qrBuscado = trim($qrBuscado);
        if (!$qrBuscado) return ['status' => 'ok', 'existe' => false];

        // Determinar tipo de búsqueda
        $esFolio = (bool) preg_match('/^\d{5}-\d{5}$/', $qrBuscado);

        if ($esFolio) {
            $stmt = $this->pdo->prepare(
                "SELECT id, maquinaria, marca, modelo, serie, capacidad, fecha_inspeccion, estado
                 FROM equipos WHERE control = ? LIMIT 1"
            );
            $stmt->execute([$qrBuscado]);
        } else {
            // Búsqueda por código QR (columna qr_codigo)
            $stmt = $this->pdo->prepare(
                "SELECT id, maquinaria, marca, modelo, serie, capacidad, fecha_inspeccion, estado
                 FROM equipos WHERE qr_codigo = ? OR qr_codigo LIKE ? LIMIT 1"
            );
            $stmt->execute([$qrBuscado, '%' . $qrBuscado . '%']);
        }

        $row = $stmt->fetch();
        if (!$row) return ['status' => 'ok', 'existe' => false];

        // Calcular vigencia (1 año desde fecha_inspeccion)
        $vigencia = calcularVigencia($row['fecha_inspeccion']);

        return [
            'status'  => 'ok',
            'existe'  => true,
            'vigente' => $vigencia['vigente'],
            'dias'    => $vigencia['dias'],
            'datos'   => [
                'maquinaria'  => $row['maquinaria'],
                'marca'       => $row['marca'],
                'modelo'      => $row['modelo'],
                'serie'       => $row['serie'],
                'capacidad'   => $row['capacidad'],
                'fecha'       => $row['fecha_inspeccion']
                    ? (new DateTime($row['fecha_inspeccion']))->format('d/m/Y')
                    : '',
                'vencimiento' => $vigencia['vencimiento'],
            ],
        ];
    }
}
