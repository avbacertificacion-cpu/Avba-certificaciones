<?php
/**
 * AVBA Certificaciones — Módulo Inspector
 * Migración de Codigo.gs (guardarInspeccionDesdeWeb, obtenerMaquinaria, getChecklist)
 */

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}
require_once __DIR__ . '/ReporteInspeccion.php';

class Inspecciones {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    // ── Opciones de maquinaria ─────────────────────────────
    public function obtenerOpcionesMaquinaria(): array {
        $stmt = $this->pdo->query("SELECT nombre, clave_drive AS idPlantilla, plantilla_dict_html FROM maquinaria_tipos ORDER BY id");
        return $stmt->fetchAll();
    }

    // ── Checklist por tipo de equipo ───────────────────────
    public function obtenerChecklistPorEquipo(string $nombreEquipo): array {
        if (!$nombreEquipo) return [];

        $stmt = $this->pdo->prepare(
            "SELECT ci.tag, ci.descripcion AS pregunta, ci.seccion,
                    COALESCE(cs.nombre, ci.seccion) AS seccion_nombre,
                    COALESCE(cs.orden, 0) AS seccion_orden
             FROM checklist_items ci
             INNER JOIN maquinaria_tipos mt ON mt.id = ci.maquinaria_tipo_id
             LEFT JOIN checklist_secciones cs ON cs.maquinaria_tipo_id = mt.id
                 AND cs.codigo = ci.seccion
             WHERE mt.nombre = ?
             ORDER BY cs.orden, ci.orden"
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

            // Campos requeridos — todo en mayúsculas antes de guardar
            $cliente    = strtoupper(trim($info['cliente']    ?? ''));
            $serie      = strtoupper(trim($info['serie']      ?? ''));
            $maquinaria = strtoupper(trim($info['maquinaria'] ?? ''));

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

            // Auto-add prueba_carga column if migration_011 not applied yet
            try {
                $this->pdo->exec(
                    "ALTER TABLE equipos ADD COLUMN IF NOT EXISTS prueba_carga JSON NULL"
                );
            } catch (\Throwable $e) {}

            $pruebaCargaJson = null;
            if (!empty($payload['prueba_carga'])) {
                $pruebaCargaJson = json_encode($payload['prueba_carga'], JSON_UNESCAPED_UNICODE);
            }

            $stmt = $this->pdo->prepare(
                "INSERT INTO equipos
                 (marca_temporal, cliente, coords_inspeccion, maquinaria, marca, modelo, serie,
                  id_equipo, fecha_inspeccion, correo, control, evidencia_url, direccion,
                  capacidad, estado, envio_direccion, coordenadas_envio, inspector, prueba_carga)
                 VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDIENTE', ?, ?, ?, ?)"
            );
            $u = fn($v) => $v !== null && $v !== '' ? strtoupper(trim($v)) : null;

            $coordsInsp = trim($info['coords_insp']  ?? '');
            $coordsEnv  = trim($info['coords_envio'] ?? '');

            // Geocodificar server-side para obtener dirección legible confiable
            $dirInsp = $this->geocodificarCoordenadas($coordsInsp)
                       ?: $u($info['direccion_inspeccion_legible'] ?? '') ?: null;
            $dirEnv  = $this->geocodificarCoordenadas($coordsEnv)
                       ?: $u($info['direccion_envio_legible']      ?? '') ?: null;

            $stmt->execute([
                $cliente,
                $coordsInsp ?: null,
                $maquinaria,
                $u($info['marca']      ?? ''),
                $u($info['modelo']     ?? ''),
                $serie,
                $u($info['id_cliente'] ?? ''),
                date('Y-m-d'),
                $this->normalizarEmails($info['email_cliente'] ?? '') ?: null,
                $control,
                $urlCarpeta ?: null,
                $dirInsp,
                $u($info['capacidad']  ?? ''),
                $dirEnv,
                $coordsEnv ?: null,
                $usuarioActual,
                $pruebaCargaJson,
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

            // Generar reporte PDF de inspección
            $reporte     = ReporteInspeccion::generar($this->pdo, $equipoId, $usuarioActual);
            $reporteUrl  = $reporte['url'] ?? null;
            $reporteMsg  = ($reporte['status'] !== 'success') ? ($reporte['message'] ?? 'Error desconocido') : null;

            return [
                'status'         => 'success',
                'message'        => 'Inspección registrada correctamente.',
                'control'        => $control,
                'url'            => $urlCarpeta,
                'reporte_url'    => $reporteUrl,
                'reporte_error'  => $reporteMsg,
            ];
        } catch (Exception $e) {
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

    // ── Historial de inspecciones del inspector ────────────
    public function getMisInspecciones(string $usuario): array {
        $stmt = $this->pdo->prepare(
            "SELECT id,
                    DATE_FORMAT(marca_temporal, '%d/%m/%Y %H:%i') AS fecha_registro,
                    DATE_FORMAT(fecha_inspeccion, '%d/%m/%Y')      AS fecha_inspeccion,
                    cliente, maquinaria, marca, modelo, serie, id_equipo,
                    capacidad, direccion, correo, control, estado,
                    evidencia_url, reporte_url, certificado_url, dictamen_url,
                    motivo, qr_codigo, inspector, prueba_carga,
                    envio_direccion AS envio, coordenadas_envio
             FROM equipos
             WHERE inspector = ?
             ORDER BY marca_temporal DESC"
        );
        $stmt->execute([$usuario]);
        return $this->formatearInspecciones($stmt->fetchAll());
    }

    public function getTodasInspecciones(): array {
        $stmt = $this->pdo->query(
            "SELECT id,
                    DATE_FORMAT(marca_temporal, '%d/%m/%Y %H:%i') AS fecha_registro,
                    DATE_FORMAT(fecha_inspeccion, '%d/%m/%Y')      AS fecha_inspeccion,
                    cliente, maquinaria, marca, modelo, serie, id_equipo,
                    capacidad, direccion, correo, control, estado,
                    evidencia_url, reporte_url, certificado_url, dictamen_url,
                    motivo, qr_codigo, inspector, prueba_carga,
                    envio_direccion AS envio, coordenadas_envio
             FROM equipos
             ORDER BY marca_temporal DESC"
        );
        return $this->formatearInspecciones($stmt->fetchAll());
    }

    private function formatearInspecciones(array $rows): array {
        foreach ($rows as &$r) {
            $r['folio']     = formatoFolio($r['control'] ?? '');
            $r['qr_url']    = $r['qr_codigo'] ? urlQR($r['qr_codigo']) : '';
            $r['checklist'] = $this->getChecklistEquipo((int)$r['id']);
        }
        unset($r);
        return $rows;
    }

    private function getChecklistEquipo(int $equipoId): array {
        $stmt = $this->pdo->prepare(
            "SELECT ci.descripcion, ci.tag, ic.valor,
                    COALESCE(cs.nombre, ci.seccion) AS seccion_nombre
             FROM inspeccion_checklist ic
             INNER JOIN checklist_items ci  ON ci.tag = ic.tag
                AND ci.maquinaria_tipo_id = (
                      SELECT e.id FROM maquinaria_tipos e
                      INNER JOIN equipos eq ON eq.maquinaria = e.nombre
                      WHERE eq.id = ? LIMIT 1
                    )
             LEFT JOIN checklist_secciones cs
                    ON cs.maquinaria_tipo_id = ci.maquinaria_tipo_id
                   AND cs.codigo = ci.seccion
             WHERE ic.equipo_id = ?
             ORDER BY ci.seccion, ci.orden"
        );
        $stmt->execute([$equipoId, $equipoId]);
        return $stmt->fetchAll();
    }

    // ── Geocodificación inversa server-side ────────────────
    private function geocodificarCoordenadas(string $coords): string {
        if (!$coords || $coords === '0,0') return '';
        $parts = explode(',', $coords, 2);
        if (count($parts) !== 2) return $coords;
        [$lat, $lng] = array_map('trim', $parts);
        if (!is_numeric($lat) || !is_numeric($lng)) return $coords;

        $url = "https://nominatim.openstreetmap.org/reverse?format=json&lat={$lat}&lon={$lng}&accept-language=es";
        $ctx = stream_context_create(['http' => [
            'timeout' => 10,
            'header'  => "User-Agent: AVBA-Certificaciones/1.0 (gestion.avba.com.mx)\r\n"
                       . "Accept: application/json\r\n",
        ]]);
        $json = @file_get_contents($url, false, $ctx);
        if ($json) {
            $data = json_decode($json, true);
            if (!empty($data['display_name'])) return strtoupper($data['display_name']);
        }
        return strtoupper("{$lat}, {$lng}");
    }

    // Normaliza lista de correos separados por coma: minúsculas, sin espacios, válidos
    private function normalizarEmails(string $raw): string {
        $emails = array_filter(
            array_map('trim', explode(',', strtolower($raw))),
            fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)
        );
        return implode(',', $emails);
    }
}
