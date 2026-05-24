<?php
/**
 * AVBA Certificaciones — Módulo PND (Pruebas No Destructivas: Líquidos Penetrantes)
 * Gestión completa de inspecciones PT: registro, consulta, actualización y reporte PDF.
 */

class Pnd {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->ensureTable();
    }

    // ── Crear tabla si no existe ───────────────────────────
    public function ensureTable(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS pnd_inspecciones (
                id                    INT AUTO_INCREMENT PRIMARY KEY,
                control               VARCHAR(50),
                marca_temporal        DATETIME DEFAULT NOW(),
                inspector             VARCHAR(150),
                cliente               VARCHAR(300),
                correo                VARCHAR(300),
                planta                TEXT,
                fecha_inspeccion      DATE,
                componente            TEXT,
                identificacion        VARCHAR(200),
                material              VARCHAR(200),
                proceso               VARCHAR(200),
                longitud_area         VARCHAR(200),
                condicion_superficie  VARCHAR(200),
                norma                 VARCHAR(300),
                criterio_aceptacion   VARCHAR(300),
                tecnica               VARCHAR(50),
                tipo_penetrante       VARCHAR(50),
                temperatura           VARCHAR(20),
                penetrante_producto   VARCHAR(300),
                removedor_producto    VARCHAR(300),
                revelador_producto    VARCHAR(300),
                tipo_revelador        VARCHAR(50),
                tiempo_penetracion    INT,
                tiempo_revelado       INT,
                condicion_iluminacion VARCHAR(200),
                indicaciones          JSON,
                sin_indicaciones      TINYINT(1) DEFAULT 0,
                resultado             VARCHAR(50),
                observaciones         TEXT,
                evidencia_url         VARCHAR(500),
                reporte_url           VARCHAR(500),
                estado                VARCHAR(50) DEFAULT 'PENDIENTE',
                motivo                TEXT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    // ── Registrar nueva inspección PND ─────────────────────
    public function nueva(array $payload, string $usuario): array {
        try {
            $cliente = strtoupper(trim($payload['cliente'] ?? 'CLIENTE'));
            $control = generarControl($this->pdo, $cliente);
            $fotos       = $payload['fotos'] ?? [];
            $evidenciaUrl = '';
            if (!empty($fotos)) {
                $evidenciaUrl = $this->guardarFotos($fotos, $control);
            }

            $fechaInsp = '';
            if (!empty($payload['fecha_inspeccion'])) {
                try {
                    $fechaInsp = (new DateTime($payload['fecha_inspeccion']))->format('Y-m-d');
                } catch (\Exception $e) {
                    $fechaInsp = $payload['fecha_inspeccion'];
                }
            }

            $stmt = $this->pdo->prepare("
                INSERT INTO pnd_inspecciones (
                    control, marca_temporal, inspector, cliente, correo, planta,
                    fecha_inspeccion, componente, identificacion, material,
                    proceso, longitud_area, condicion_superficie, norma,
                    criterio_aceptacion, tecnica, tipo_penetrante, temperatura,
                    penetrante_producto, removedor_producto, revelador_producto,
                    tipo_revelador, tiempo_penetracion, tiempo_revelado,
                    condicion_iluminacion, indicaciones, sin_indicaciones,
                    resultado, observaciones, evidencia_url, estado
                ) VALUES (
                    ?, NOW(), ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?, 'PENDIENTE'
                )
            ");

            $stmt->execute([
                $control,
                $usuario,
                $payload['cliente']               ?? null,
                $payload['correo']                ?? null,
                $payload['planta']                ?? null,
                $fechaInsp                        ?: null,
                $payload['componente']            ?? null,
                $payload['identificacion']        ?? null,
                $payload['material']              ?? null,
                $payload['proceso']               ?? null,
                $payload['longitud_area']         ?? null,
                $payload['condicion_superficie']  ?? null,
                $payload['norma']                 ?? null,
                $payload['criterio_aceptacion']   ?? null,
                $payload['tecnica']               ?? null,
                $payload['tipo_penetrante']       ?? null,
                $payload['temperatura']           ?? null,
                $payload['penetrante_producto']   ?? null,
                $payload['removedor_producto']    ?? null,
                $payload['revelador_producto']    ?? null,
                $payload['tipo_revelador']        ?? null,
                isset($payload['tiempo_penetracion']) ? (int)$payload['tiempo_penetracion'] : null,
                isset($payload['tiempo_revelado'])    ? (int)$payload['tiempo_revelado']    : null,
                $payload['condicion_iluminacion'] ?? null,
                json_encode($payload['indicaciones'] ?? [], JSON_UNESCAPED_UNICODE),
                (int)($payload['sin_indicaciones'] ?? 0),
                $payload['resultado']             ?? null,
                $payload['observaciones']         ?? null,
                $evidenciaUrl                     ?: null,
            ]);

            return [
                'status'  => 'success',
                'control' => $control,
                'message' => 'PND registrada correctamente.',
            ];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    // ── Obtener todos los registros (panel Calidad) ────────
    public function getDataCalidad(): array {
        $stmt = $this->pdo->query("
            SELECT *,
                   DATE_FORMAT(marca_temporal,   '%d/%m/%Y %H:%i') AS marca_temporal_fmt,
                   DATE_FORMAT(fecha_inspeccion, '%d/%m/%Y')        AS fecha_inspeccion_fmt
            FROM pnd_inspecciones
            ORDER BY marca_temporal DESC
        ");
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['indicaciones'] = !empty($r['indicaciones'])
                ? (json_decode($r['indicaciones'], true) ?? [])
                : [];
        }
        unset($r);
        return $rows;
    }

    // ── Obtener inspecciones del inspector autenticado ─────
    public function getMisInspecciones(string $usuario): array {
        $stmt = $this->pdo->prepare("
            SELECT *,
                   DATE_FORMAT(marca_temporal,   '%d/%m/%Y %H:%i') AS marca_temporal_fmt,
                   DATE_FORMAT(fecha_inspeccion, '%d/%m/%Y')        AS fecha_inspeccion_fmt
            FROM pnd_inspecciones
            WHERE inspector = ?
            ORDER BY marca_temporal DESC
        ");
        $stmt->execute([$usuario]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['indicaciones'] = !empty($r['indicaciones'])
                ? (json_decode($r['indicaciones'], true) ?? [])
                : [];
        }
        unset($r);
        return $rows;
    }

    // ── Actualizar campos de una inspección ───────────────
    public function actualizar(array $payload, string $usuario): array {
        $id = (int)($payload['id'] ?? $payload['fila'] ?? 0);
        if (!$id) return ['status' => 'error', 'message' => 'ID de inspección requerido.'];

        $stmt = $this->pdo->prepare("SELECT id FROM pnd_inspecciones WHERE id = ?");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) return ['status' => 'error', 'message' => 'Registro no encontrado.'];

        $editables = [
            'cliente', 'planta', 'correo', 'componente', 'identificacion', 'material',
            'proceso', 'longitud_area', 'condicion_superficie', 'norma', 'criterio_aceptacion',
            'tecnica', 'tipo_penetrante', 'temperatura', 'penetrante_producto',
            'removedor_producto', 'revelador_producto', 'tipo_revelador',
            'tiempo_penetracion', 'tiempo_revelado', 'condicion_iluminacion',
            'resultado', 'observaciones',
        ];

        $sets   = [];
        $params = [];

        foreach ($editables as $campo) {
            if (array_key_exists($campo, $payload)) {
                $sets[]   = "`{$campo}` = ?";
                $params[] = $payload[$campo] === '' ? null : $payload[$campo];
            }
        }

        if (array_key_exists('indicaciones', $payload)) {
            $sets[]   = '`indicaciones` = ?';
            $params[] = json_encode($payload['indicaciones'], JSON_UNESCAPED_UNICODE);
        }

        if (array_key_exists('sin_indicaciones', $payload)) {
            $sets[]   = '`sin_indicaciones` = ?';
            $params[] = (int)$payload['sin_indicaciones'];
        }

        if (empty($sets)) return ['status' => 'success', 'message' => 'Sin cambios.'];

        $params[] = $id;
        $this->pdo->prepare(
            "UPDATE pnd_inspecciones SET " . implode(', ', $sets) . " WHERE id = ?"
        )->execute($params);

        return ['status' => 'success', 'message' => 'Inspección actualizada correctamente.'];
    }

    // ── Generar reporte PDF ────────────────────────────────
    public function generarReporte(int $id): array {
        try {
            if (!class_exists('Mpdf\Mpdf')) {
                $autoload = __DIR__ . '/../vendor/autoload.php';
                if (!file_exists($autoload)) {
                    return ['status' => 'error', 'message' => 'Composer vendor no encontrado.'];
                }
                require_once $autoload;
            }

            $stmt = $this->pdo->prepare("SELECT * FROM pnd_inspecciones WHERE id = ?");
            $stmt->execute([$id]);
            $d = $stmt->fetch();
            if (!$d) return ['status' => 'error', 'message' => 'Registro PND no encontrado.'];

            $d['indicaciones'] = !empty($d['indicaciones'])
                ? (json_decode($d['indicaciones'], true) ?? [])
                : [];

            $html = $this->buildReporteHtml($d);

            $mpdf = new \Mpdf\Mpdf([
                'mode'        => 'utf-8',
                'format'      => 'A4',
                'orientation' => 'P',
                'margin_top'  => 0,
                'margin_right'=> 0,
                'margin_bottom'=> 0,
                'margin_left' => 0,
                'margin_header'=> 0,
                'margin_footer'=> 0,
            ]);

            $mpdf->SetTitle('Reporte PND - ' . ($d['control'] ?? $id));
            $mpdf->WriteHTML($html);

            $carpeta = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . 'certificados' . DIRECTORY_SEPARATOR;
            if (!is_dir($carpeta) && !mkdir($carpeta, 0755, true)) {
                return ['status' => 'error', 'message' => "No se pudo crear carpeta: {$carpeta}"];
            }

            $control = preg_replace('/[^a-zA-Z0-9\-_]/', '_', $d['control'] ?? (string)$id);
            $archivo = "PND_{$control}.pdf";
            $path    = $carpeta . $archivo;

            $mpdf->SetProtection(['print'], '', 'Avba@Cert2024!');
            $mpdf->Output($path, 'F');

            $url = rtrim(UPLOAD_URL, '/') . '/certificados/' . basename($path);

            $this->pdo->prepare(
                "UPDATE pnd_inspecciones SET reporte_url = ? WHERE id = ?"
            )->execute([$url, $id]);

            return ['status' => 'success', 'url' => $url];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    // ── Aprobar inspección ─────────────────────────────────
    public function aprobar(array $payload, string $usuario): array {
        $id = (int)($payload['id'] ?? $payload['fila'] ?? 0);
        if (!$id) return ['status' => 'error', 'message' => 'ID de inspección requerido.'];

        $this->pdo->prepare(
            "UPDATE pnd_inspecciones SET estado = 'APROBADO', motivo = NULL WHERE id = ?"
        )->execute([$id]);

        return ['status' => 'success', 'message' => 'Inspección PND aprobada correctamente.'];
    }

    // ── Rechazar inspección ────────────────────────────────
    public function rechazar(array $payload, string $usuario): array {
        $id     = (int)($payload['id'] ?? $payload['fila'] ?? 0);
        $motivo = trim($payload['motivo'] ?? '');
        if (!$id) return ['status' => 'error', 'message' => 'ID de inspección requerido.'];

        $this->pdo->prepare(
            "UPDATE pnd_inspecciones SET estado = 'RECHAZADO', motivo = ? WHERE id = ?"
        )->execute([$motivo ?: null, $id]);

        return ['status' => 'success', 'message' => 'Inspección PND rechazada.'];
    }

    // ── Guardar fotos en disco ─────────────────────────────
    private function guardarFotos(array $fotos, string $control): string {
        $dirName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $control);
        $rutaDir = rtrim(UPLOAD_DIR, '/') . '/pnd/' . $dirName . '/';
        $urlDir  = rtrim(UPLOAD_URL, '/') . '/pnd/' . $dirName . '/';

        if (!is_dir($rutaDir)) {
            mkdir($rutaDir, 0755, true);
        }

        foreach ($fotos as $i => $foto) {
            $base64 = $foto['base64']   ?? '';
            $mime   = $foto['mimeType'] ?? 'image/jpeg';

            $base64 = preg_replace('/^data:[^;]+;base64,/', '', $base64);
            $base64 = str_replace(["\n", "\r", ' '], '', $base64);

            $bytes = base64_decode($base64);
            if ($bytes === false) continue;

            $ext  = ($mime === 'image/png') ? 'png' : 'jpg';
            $file = $rutaDir . 'Evidencia_' . ($i + 1) . '_' . $dirName . '.' . $ext;
            file_put_contents($file, $bytes);
        }

        return $urlDir;
    }

    // ── Construir HTML del reporte PDF ─────────────────────
    private function buildReporteHtml(array $d): string {
        $h = fn(mixed $v): string => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');

        // Formatear fecha de inspección
        $fechaFmt = '';
        if (!empty($d['fecha_inspeccion'])) {
            try {
                $fechaFmt = (new DateTime($d['fecha_inspeccion']))->format('d/m/Y');
            } catch (\Exception $e) {
                $fechaFmt = $d['fecha_inspeccion'];
            }
        }

        $control        = $h($d['control'] ?? '');
        $inspector      = $h($d['inspector'] ?? '');
        $cliente        = $h($d['cliente'] ?? '');
        $correo         = $h($d['correo'] ?? '');
        $planta         = $h($d['planta'] ?? '');
        $componente     = $h($d['componente'] ?? '');
        $identificacion = $h($d['identificacion'] ?? '');
        $material       = $h($d['material'] ?? '');
        $proceso        = $h($d['proceso'] ?? '');
        $longitudArea   = $h($d['longitud_area'] ?? '');
        $condSuperficie = $h($d['condicion_superficie'] ?? '');
        $norma          = $h($d['norma'] ?? '');
        $criterio       = $h($d['criterio_aceptacion'] ?? '');
        $tecnica        = $h($d['tecnica'] ?? '');
        $tipoPenetrante = $h($d['tipo_penetrante'] ?? '');
        $temperatura    = $h($d['temperatura'] ?? '');
        $penetranteProd = $h($d['penetrante_producto'] ?? '');
        $removedorProd  = $h($d['removedor_producto'] ?? '');
        $reveladorProd  = $h($d['revelador_producto'] ?? '');
        $tipoRevelador  = $h($d['tipo_revelador'] ?? '');
        $tiempoPen      = $h($d['tiempo_penetracion'] ?? '');
        $tiempoRev      = $h($d['tiempo_revelado'] ?? '');
        $condIlum       = $h($d['condicion_iluminacion'] ?? '');
        $resultado      = strtoupper(trim($d['resultado'] ?? ''));
        $observaciones  = $h($d['observaciones'] ?? '');
        $sinIndicaciones= (int)($d['sin_indicaciones'] ?? 0);
        $indicaciones   = is_array($d['indicaciones']) ? $d['indicaciones'] : [];
        $fechaGeneracion= date('d/m/Y H:i');

        // ── SECCIÓN: Indicaciones ──────────────────────────
        if ($sinIndicaciones) {
            $indicacionesHtml = '
            <tr>
                <td colspan="6" style="text-align:center;padding:14px 10px;font-size:12px;
                    color:#15803d;font-weight:bold;background:#f0fdf4;
                    border:1px solid #bbf7d0;border-radius:4px;">
                    Sin indicaciones relevantes detectadas
                </td>
            </tr>';
        } elseif (!empty($indicaciones)) {
            $indicacionesHtml = '';
            foreach ($indicaciones as $idx => $ind) {
                $num         = $h($ind['numero']    ?? ($idx + 1));
                $ubicacion   = $h($ind['ubicacion'] ?? '');
                $tipo        = $h($ind['tipo']       ?? '');
                $dimension   = $h($ind['dimension']  ?? '');
                $relevante   = $h($ind['relevante']  ?? '');
                $disposicion = strtoupper(trim($ind['disposicion'] ?? ''));
                $dispColor   = (str_contains($disposicion, 'RECHAZ')) ? '#b91c1c' : '#15803d';
                $dispBg      = (str_contains($disposicion, 'RECHAZ')) ? '#fef2f2' : '#f0fdf4';
                $dispText    = $disposicion ?: '—';
                $indicacionesHtml .= "
                <tr style=\"background:" . ($idx % 2 === 0 ? '#ffffff' : '#f8fafc') . ";\">
                    <td style=\"text-align:center;padding:7px 6px;font-size:11px;border-bottom:1px solid #e2e8f0;\">{$num}</td>
                    <td style=\"padding:7px 8px;font-size:11px;border-bottom:1px solid #e2e8f0;\">{$ubicacion}</td>
                    <td style=\"padding:7px 8px;font-size:11px;border-bottom:1px solid #e2e8f0;\">{$tipo}</td>
                    <td style=\"text-align:center;padding:7px 8px;font-size:11px;border-bottom:1px solid #e2e8f0;\">{$dimension}</td>
                    <td style=\"text-align:center;padding:7px 8px;font-size:11px;border-bottom:1px solid #e2e8f0;\">{$relevante}</td>
                    <td style=\"text-align:center;padding:7px 4px;font-size:10px;font-weight:bold;
                        border-bottom:1px solid #e2e8f0;\">
                        <span style=\"color:{$dispColor};background:{$dispBg};padding:3px 8px;
                            border-radius:10px;border:1px solid {$dispColor};\">{$h($dispText)}</span>
                    </td>
                </tr>";
            }
        } else {
            $indicacionesHtml = '
            <tr>
                <td colspan="6" style="text-align:center;padding:12px;font-size:11px;
                    color:#6b7280;font-style:italic;">
                    No se registraron indicaciones
                </td>
            </tr>';
        }

        // ── SECCIÓN: Resultado ─────────────────────────────
        if ($resultado === 'CONFORME') {
            $resultadoHtml = '
            <div style="display:inline-block;background:#15803d;color:#ffffff;
                font-size:18px;font-weight:bold;padding:14px 36px;
                border-radius:8px;letter-spacing:1px;">
                &#10004; CONFORME
            </div>';
        } elseif ($resultado === 'NO CONFORME' || str_contains($resultado, 'NO')) {
            $resultadoHtml = '
            <div style="display:inline-block;background:#b91c1c;color:#ffffff;
                font-size:18px;font-weight:bold;padding:14px 36px;
                border-radius:8px;letter-spacing:1px;">
                &#10006; NO CONFORME
            </div>';
        } else {
            $resText = $resultado ?: 'PENDIENTE';
            $resultadoHtml = '
            <div style="display:inline-block;background:#374151;color:#ffffff;
                font-size:18px;font-weight:bold;padding:14px 36px;
                border-radius:8px;letter-spacing:1px;">
                ' . $h($resText) . '
            </div>';
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: Arial, Helvetica, sans-serif;
    font-size: 11px;
    color: #1e293b;
    background: #ffffff;
    margin: 0;
    padding: 0;
  }
  .page-wrap {
    width: 100%;
    padding: 0;
  }
  /* HEADER */
  .header {
    background: #1a2e4a;
    padding: 18px 24px;
    display: table;
    width: 100%;
  }
  .header-left {
    display: table-cell;
    vertical-align: middle;
    width: 32%;
  }
  .header-center {
    display: table-cell;
    vertical-align: middle;
    text-align: center;
    width: 36%;
  }
  .header-right {
    display: table-cell;
    vertical-align: middle;
    text-align: right;
    width: 32%;
  }
  .header-company {
    color: #ffffff;
    font-size: 17px;
    font-weight: bold;
    letter-spacing: 1px;
  }
  .header-subtitle {
    color: #94a3b8;
    font-size: 9px;
    margin-top: 3px;
    letter-spacing: 0.5px;
  }
  .header-title {
    color: #ffffff;
    font-size: 13px;
    font-weight: bold;
    letter-spacing: 0.5px;
  }
  .header-method {
    color: #4ECDC4;
    font-size: 10px;
    font-weight: bold;
    margin-top: 4px;
    letter-spacing: 0.5px;
  }
  .header-folio-label {
    color: #94a3b8;
    font-size: 9px;
    text-transform: uppercase;
    letter-spacing: 1px;
  }
  .header-folio {
    color: #4ECDC4;
    font-size: 22px;
    font-weight: bold;
    letter-spacing: 2px;
    display: block;
  }
  /* INFO STRIP */
  .info-strip {
    background: #2d4a6e;
    padding: 7px 24px;
    color: #ffffff;
    font-size: 10px;
    letter-spacing: 0.3px;
  }
  .info-strip span {
    margin-right: 20px;
  }
  /* SECTIONS */
  .section {
    margin: 14px 20px 0 20px;
  }
  .section-title {
    background: #2d4a6e;
    border-left: 5px solid #4ECDC4;
    color: #ffffff;
    font-size: 11px;
    font-weight: bold;
    padding: 7px 14px;
    letter-spacing: 0.4px;
    text-transform: uppercase;
  }
  .section-body {
    border: 1px solid #cbd5e1;
    border-top: none;
    padding: 12px 14px;
    background: #ffffff;
  }
  /* GRID 2 COLS */
  .grid-2 {
    display: table;
    width: 100%;
    border-collapse: collapse;
  }
  .grid-2 .col {
    display: table-cell;
    width: 50%;
    padding: 5px 8px 5px 0;
    vertical-align: top;
  }
  .grid-2 .col:last-child {
    padding-right: 0;
    padding-left: 8px;
    border-left: 1px solid #e2e8f0;
  }
  .field-label {
    font-size: 9px;
    color: #64748b;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 2px;
  }
  .field-value {
    font-size: 11px;
    color: #1e293b;
    border-bottom: 1px solid #e2e8f0;
    padding-bottom: 4px;
    min-height: 17px;
  }
  .field-value-full {
    font-size: 11px;
    color: #1e293b;
    border-bottom: 1px solid #e2e8f0;
    padding-bottom: 4px;
    min-height: 17px;
    margin-bottom: 8px;
  }
  /* TABLE STYLES */
  .data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 11px;
  }
  .data-table th {
    background: #1a2e4a;
    color: #ffffff;
    padding: 8px 10px;
    text-align: left;
    font-size: 10px;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.4px;
  }
  .data-table td {
    padding: 7px 10px;
    border-bottom: 1px solid #e2e8f0;
    vertical-align: middle;
  }
  .data-table tr:nth-child(even) td {
    background: #f8fafc;
  }
  /* RESULT BOX */
  .result-box {
    text-align: center;
    padding: 18px 10px 14px 10px;
  }
  .obs-label {
    font-size: 9px;
    color: #64748b;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-top: 14px;
    margin-bottom: 4px;
  }
  .obs-text {
    font-size: 11px;
    color: #374151;
    padding: 8px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 4px;
    min-height: 30px;
  }
  /* FIRMAS */
  .firmas-wrap {
    display: table;
    width: 100%;
    border-collapse: collapse;
  }
  .firma-col {
    display: table-cell;
    width: 50%;
    padding: 18px 20px 14px 20px;
    text-align: center;
    vertical-align: bottom;
  }
  .firma-col:first-child {
    border-right: 1px solid #e2e8f0;
  }
  .firma-line {
    border-top: 2px solid #1a2e4a;
    margin: 10px auto 6px auto;
    width: 80%;
  }
  .firma-name {
    font-size: 12px;
    font-weight: bold;
    color: #1a2e4a;
  }
  .firma-cert {
    font-size: 9px;
    color: #4ECDC4;
    margin-top: 2px;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }
  .firma-role {
    font-size: 10px;
    color: #64748b;
    margin-top: 3px;
  }
  /* FOOTER */
  .footer {
    background: #1a2e4a;
    color: #94a3b8;
    font-size: 9px;
    padding: 8px 24px;
    margin-top: 18px;
    text-align: center;
    letter-spacing: 0.3px;
  }
  .footer strong {
    color: #4ECDC4;
  }
</style>
</head>
<body>
<div class="page-wrap">

  <!-- ═══ HEADER ═══ -->
  <div class="header">
    <div class="header-left">
      <div class="header-company">AVBA</div>
      <div class="header-subtitle">Certificaciones e Inspección Industrial</div>
    </div>
    <div class="header-center">
      <div class="header-title">REPORTE DE PRUEBA NO DESTRUCTIVA</div>
      <div class="header-method">MÉTODO: LÍQUIDOS PENETRANTES (LP)</div>
    </div>
    <div class="header-right">
      <span class="header-folio-label">Folio</span>
      <span class="header-folio">{$control}</span>
    </div>
  </div>

  <!-- ═══ INFO STRIP ═══ -->
  <div class="info-strip">
    <span><strong>Inspector:</strong> {$inspector}</span>
    <span><strong>Fecha:</strong> {$h($fechaFmt)}</span>
    <span><strong>Cert:</strong> ASNT SNT-TC-1A</span>
  </div>

  <!-- ═══ 1. DATOS GENERALES ═══ -->
  <div class="section">
    <div class="section-title">1. Datos Generales</div>
    <div class="section-body">
      <div class="grid-2">
        <div class="col">
          <div class="field-label">Cliente / Empresa</div>
          <div class="field-value">{$cliente}</div>
          <div style="height:8px;"></div>
          <div class="field-label">Planta / Lugar</div>
          <div class="field-value">{$planta}</div>
        </div>
        <div class="col">
          <div class="field-label">Fecha de Inspección</div>
          <div class="field-value">{$h($fechaFmt)}</div>
          <div style="height:8px;"></div>
          <div class="field-label">Correo de Contacto</div>
          <div class="field-value">{$correo}</div>
        </div>
      </div>
    </div>
  </div>

  <!-- ═══ 2. COMPONENTE INSPECCIONADO ═══ -->
  <div class="section">
    <div class="section-title">2. Componente Inspeccionado</div>
    <div class="section-body">
      <div class="field-label">Descripción del Componente</div>
      <div class="field-value-full">{$componente}</div>
      <div class="grid-2">
        <div class="col">
          <div class="field-label">Identificación / TAG</div>
          <div class="field-value">{$identificacion}</div>
          <div style="height:8px;"></div>
          <div class="field-label">Proceso</div>
          <div class="field-value">{$proceso}</div>
        </div>
        <div class="col">
          <div class="field-label">Material</div>
          <div class="field-value">{$material}</div>
          <div style="height:8px;"></div>
          <div class="field-label">Longitud / Área de Inspección</div>
          <div class="field-value">{$longitudArea}</div>
        </div>
      </div>
      <div style="height:8px;"></div>
      <div class="field-label">Condición de Superficie</div>
      <div class="field-value">{$condSuperficie}</div>
    </div>
  </div>

  <!-- ═══ 3. PARÁMETROS DEL ENSAYO ═══ -->
  <div class="section">
    <div class="section-title">3. Parámetros del Ensayo</div>
    <div class="section-body">
      <div class="grid-2">
        <div class="col">
          <div class="field-label">Norma de Procedimiento</div>
          <div class="field-value">{$norma}</div>
          <div style="height:8px;"></div>
          <div class="field-label">Técnica</div>
          <div class="field-value">{$tecnica}</div>
          <div style="height:8px;"></div>
          <div class="field-label">Temperatura Superficial</div>
          <div class="field-value">{$temperatura} °C</div>
        </div>
        <div class="col">
          <div class="field-label">Criterio de Aceptación</div>
          <div class="field-value">{$criterio}</div>
          <div style="height:8px;"></div>
          <div class="field-label">Tipo de Penetrante</div>
          <div class="field-value">{$tipoPenetrante}</div>
          <div style="height:8px;"></div>
          <div class="field-label">Condiciones de Iluminación</div>
          <div class="field-value">{$condIlum}</div>
        </div>
      </div>
    </div>
  </div>

  <!-- ═══ 4. PRODUCTOS Y TIEMPOS ═══ -->
  <div class="section">
    <div class="section-title">4. Productos Utilizados y Tiempos</div>
    <div class="section-body">
      <table class="data-table">
        <thead>
          <tr>
            <th style="width:28%;">Elemento</th>
            <th style="width:47%;">Producto</th>
            <th style="width:25%;text-align:center;">Tiempo</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td><strong>Penetrante</strong></td>
            <td>{$penetranteProd}</td>
            <td style="text-align:center;color:#1a2e4a;font-weight:bold;">
              Penetración: {$tiempoPen} min
            </td>
          </tr>
          <tr style="background:#f8fafc;">
            <td><strong>Removedor</strong></td>
            <td>{$removedorProd}</td>
            <td style="text-align:center;color:#6b7280;">—</td>
          </tr>
          <tr>
            <td><strong>Revelador</strong><br>
              <span style="font-size:9px;color:#64748b;">{$tipoRevelador}</span>
            </td>
            <td>{$reveladorProd}</td>
            <td style="text-align:center;color:#1a2e4a;font-weight:bold;">
              Revelado: {$tiempoRev} min
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ═══ 5. INDICACIONES ═══ -->
  <div class="section">
    <div class="section-title">5. Indicaciones Encontradas</div>
    <div class="section-body" style="padding:10px 14px;">
      <table class="data-table">
        <thead>
          <tr>
            <th style="width:6%;text-align:center;">N°</th>
            <th style="width:22%;">Ubicación</th>
            <th style="width:18%;">Tipo</th>
            <th style="width:15%;text-align:center;">Dimensión</th>
            <th style="width:18%;text-align:center;">¿Relevante?</th>
            <th style="width:21%;text-align:center;">Disposición</th>
          </tr>
        </thead>
        <tbody>
          {$indicacionesHtml}
        </tbody>
      </table>
    </div>
  </div>

  <!-- ═══ 6. RESULTADO ═══ -->
  <div class="section">
    <div class="section-title">6. Resultado de la Inspección</div>
    <div class="section-body">
      <div class="result-box">
        {$resultadoHtml}
      </div>
      <div class="obs-label">Observaciones</div>
      <div class="obs-text">{$observaciones}</div>
    </div>
  </div>

  <!-- ═══ 7. FIRMAS ═══ -->
  <div class="section">
    <div class="section-title">7. Firmas</div>
    <div class="section-body" style="padding:0;">
      <div class="firmas-wrap">
        <div class="firma-col">
          <div style="min-height:40px;"></div>
          <div class="firma-line"></div>
          <div class="firma-name">{$inspector}</div>
          <div class="firma-cert">Cert: ASNT SNT-TC-1A</div>
          <div class="firma-role">Inspector PND</div>
        </div>
        <div class="firma-col">
          <div style="min-height:40px;"></div>
          <div class="firma-line"></div>
          <div class="firma-name">&nbsp;</div>
          <div class="firma-cert">&nbsp;</div>
          <div class="firma-role">Calidad AVBA</div>
        </div>
      </div>
    </div>
  </div>

  <!-- ═══ FOOTER ═══ -->
  <div class="footer">
    <strong>AVBA Certificaciones e Inspección Industrial</strong> &nbsp;|&nbsp;
    Este reporte es confidencial y de uso exclusivo del cliente indicado. Queda prohibida su reproducción parcial o total sin autorización escrita.
    &nbsp;|&nbsp; Generado: {$h($fechaGeneracion)}
  </div>

</div>
</body>
</html>
HTML;
    }
}
