<?php
/**
 * AVBA Certificaciones — Auditorías trimestrales de Calidad
 *
 * Genera reportes de auditoría (uno consolidado por trimestre) sobre el estado
 * de vigencias de los EQUIPOS y el PERSONAL certificados por AVBA, con
 * hallazgos, calificación y responsable que firma. Manual + recordatorio del
 * trimestre en curso.
 *
 * Vigencia: equipos = fecha_inspeccion + 1 año; personal = fecha_curso + 1 año.
 */
class Auditorias {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->migrate();
    }

    private function migrate(): void {
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS auditorias (
                  id            INT AUTO_INCREMENT PRIMARY KEY,
                  anio          SMALLINT NOT NULL,
                  trimestre     TINYINT  NOT NULL,
                  periodo       VARCHAR(30) NULL,
                  resultado     VARCHAR(40) NULL,
                  observaciones TEXT NULL,
                  hallazgos     TEXT NULL,
                  stats         TEXT NULL,
                  responsable   VARCHAR(200) NULL,
                  pdf_url       VARCHAR(500) NULL,
                  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  UNIQUE KEY uniq_periodo (anio, trimestre)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\PDOException $e) {
            error_log('[Auditorias] migrate: ' . $e->getMessage());
        }
    }

    private function trimLabel(int $t): string {
        return ['1' => 'Ene–Mar', '2' => 'Abr–Jun', '3' => 'Jul–Sep', '4' => 'Oct–Dic'][(string)$t] ?? "T$t";
    }

    /** Calcula el estado de vigencias de equipos y personal certificados. */
    public function computeVigencias(): array {
        $eq = ['total' => 0, 'vigentes' => 0, 'por_vencer' => 0, 'vencidos' => 0, 'sin_fecha' => 0];
        $pe = ['total' => 0, 'vigentes' => 0, 'por_vencer' => 0, 'vencidos' => 0, 'sin_fecha' => 0];
        $venc = ['equipos' => [], 'personal' => []]; // detalle de vencidos/por vencer

        // Equipos certificados (ENVIADO)
        try {
            $rows = $this->pdo->query("
                SELECT maquinaria, marca, serie, control, cliente, fecha_inspeccion,
                       DATE_ADD(fecha_inspeccion, INTERVAL 1 YEAR) AS vence,
                       DATEDIFF(DATE_ADD(fecha_inspeccion, INTERVAL 1 YEAR), CURDATE()) AS dias
                FROM equipos WHERE estado = 'ENVIADO'
            ")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $eq['total']++;
                if (empty($r['fecha_inspeccion'])) { $eq['sin_fecha']++; continue; }
                $d = (int)$r['dias'];
                if ($d < 0)       { $eq['vencidos']++;   $venc['equipos'][] = $this->detItem($r, 'equipo', $d); }
                elseif ($d <= 30) { $eq['por_vencer']++; $venc['equipos'][] = $this->detItem($r, 'equipo', $d); }
                else              { $eq['vigentes']++; }
            }
        } catch (\PDOException $e) {}

        // Personal certificado (con fecha de curso)
        try {
            $rows = $this->pdo->query("
                SELECT nombre_completo, empresa_nombre, control, fecha_curso,
                       DATEDIFF(DATE_ADD(fecha_curso, INTERVAL 1 YEAR), CURDATE()) AS dias
                FROM personal
                WHERE fecha_curso IS NOT NULL AND fecha_curso <> '' AND fecha_curso <> '0000-00-00'
            ")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $pe['total']++;
                $d = (int)$r['dias'];
                if ($d < 0)       { $pe['vencidos']++;   $venc['personal'][] = $this->detItem($r, 'personal', $d); }
                elseif ($d <= 30) { $pe['por_vencer']++; $venc['personal'][] = $this->detItem($r, 'personal', $d); }
                else              { $pe['vigentes']++; }
            }
        } catch (\PDOException $e) {}

        return ['equipos' => $eq, 'personal' => $pe, 'detalle' => $venc];
    }

    private function detItem(array $r, string $tipo, int $dias): array {
        if ($tipo === 'equipo') {
            $nombre = trim(($r['maquinaria'] ?? '') . (!empty($r['serie']) ? ' · S/N ' . $r['serie'] : ''));
            $ref    = $r['control'] ?? '';
            $due    = $r['cliente'] ?? '';
        } else {
            $nombre = $r['nombre_completo'] ?? '';
            $ref    = $r['control'] ?? '';
            $due    = $r['empresa_nombre'] ?? '';
        }
        return ['nombre' => $nombre, 'ref' => $ref, 'para' => $due,
                'dias' => $dias, 'estado' => $dias < 0 ? 'Vencido' : 'Por vencer'];
    }

    /** Preview para el modal: vigencias actuales + sugerencia de resultado. */
    public function preview(int $anio, int $trimestre): array {
        $v = $this->computeVigencias();
        $vencidos = $v['equipos']['vencidos'] + $v['personal']['vencidos'];
        $porVencer = $v['equipos']['por_vencer'] + $v['personal']['por_vencer'];
        $sugerido = $vencidos > 0 ? 'No satisfactoria' : ($porVencer > 0 ? 'Satisfactoria con observaciones' : 'Satisfactoria');
        return ['status' => 'success', 'anio' => $anio, 'trimestre' => $trimestre,
                'periodo' => $this->trimLabel($trimestre), 'vigencias' => $v, 'resultado_sugerido' => $sugerido];
    }

    /** Trimestres del año que aún no tienen auditoría (para el recordatorio). */
    public function pendientes(int $anio): array {
        $done = [];
        try {
            $s = $this->pdo->prepare("SELECT trimestre FROM auditorias WHERE anio = ?");
            $s->execute([$anio]);
            $done = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
        } catch (\PDOException $e) {}
        return array_values(array_diff([1,2,3,4], $done));
    }

    public function listar(): array {
        try {
            $rows = $this->pdo->query("
                SELECT id, anio, trimestre, periodo, resultado, responsable, pdf_url,
                       DATE_FORMAT(created_at,'%d/%m/%Y') AS fecha
                FROM auditorias ORDER BY anio DESC, trimestre DESC
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) { $rows = []; }
        foreach ($rows as &$r) { $r['pdf_url'] = $r['pdf_url'] ? (rtrim(SITE_URL,'/').'/'.ltrim($r['pdf_url'],'/')) : ''; }
        return ['status' => 'success', 'auditorias' => $rows, 'pendientes' => $this->pendientes((int)date('Y')), 'trimestre_actual' => (int)ceil((int)date('n')/3)];
    }

    public function detalle(int $id): array {
        $s = $this->pdo->prepare("SELECT * FROM auditorias WHERE id = ?");
        $s->execute([$id]);
        $a = $s->fetch(PDO::FETCH_ASSOC);
        if (!$a) return ['status' => 'error', 'message' => 'Auditoría no encontrada.'];
        $a['hallazgos'] = json_decode($a['hallazgos'] ?? '[]', true) ?: [];
        $a['stats']     = json_decode($a['stats'] ?? '{}', true) ?: [];
        $a['pdf_url']   = $a['pdf_url'] ? (rtrim(SITE_URL,'/').'/'.ltrim($a['pdf_url'],'/')) : '';
        return ['status' => 'success', 'auditoria' => $a];
    }

    public function crear(array $data, array $usr): array {
        $anio = (int)($data['anio'] ?? date('Y'));
        $trim = (int)($data['trimestre'] ?? 0);
        if ($trim < 1 || $trim > 4) return ['status' => 'error', 'message' => 'Trimestre inválido.'];

        // Único por periodo
        $ex = $this->pdo->prepare("SELECT id FROM auditorias WHERE anio=? AND trimestre=?");
        $ex->execute([$anio, $trim]);
        if ($ex->fetch()) return ['status' => 'error', 'message' => "Ya existe la auditoría de {$this->trimLabel($trim)} {$anio}."];

        $resultado = trim($data['resultado'] ?? 'Satisfactoria');
        $obs = trim($data['observaciones'] ?? '');
        $hallazgos = $data['hallazgos'] ?? [];
        if (is_string($hallazgos)) $hallazgos = json_decode($hallazgos, true) ?: [];
        $hallazgos = array_values(array_filter($hallazgos, fn($h) => trim($h['descripcion'] ?? '') !== ''));

        $v = $this->computeVigencias();
        $resp = $usr['nombre'] ?: ($usr['usuario'] ?? 'Calidad');

        $this->pdo->prepare(
            "INSERT INTO auditorias (anio,trimestre,periodo,resultado,observaciones,hallazgos,stats,responsable)
             VALUES (?,?,?,?,?,?,?,?)"
        )->execute([$anio, $trim, $this->trimLabel($trim), $resultado, $obs,
                    json_encode($hallazgos, JSON_UNESCAPED_UNICODE), json_encode($v, JSON_UNESCAPED_UNICODE), $resp]);
        $id = (int)$this->pdo->lastInsertId();

        $pdf = $this->pdfAuditoria($id);
        if ($pdf) $this->pdo->prepare("UPDATE auditorias SET pdf_url=? WHERE id=?")->execute([$pdf, $id]);

        return ['status' => 'success', 'id' => $id, 'pdf_url' => $pdf ? (rtrim(SITE_URL,'/').'/'.ltrim($pdf,'/')) : ''];
    }

    public function eliminar(int $id): array {
        $this->pdo->prepare("DELETE FROM auditorias WHERE id=?")->execute([$id]);
        return ['status' => 'success'];
    }

    // ── PDF ──────────────────────────────────────────────────
    private function assetB64(string $rel): string {
        $path = dirname(__DIR__) . '/assets/' . ltrim($rel, '/');
        if (!is_file($path)) return '';
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = in_array($ext, ['jpg','jpeg']) ? 'image/jpeg' : 'image/png';
        return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($path));
    }

    private function pdfAuditoria(int $id): ?string {
        try {
            $s = $this->pdo->prepare("SELECT * FROM auditorias WHERE id=?");
            $s->execute([$id]);
            $a = $s->fetch(PDO::FETCH_ASSOC);
            if (!$a) return null;
            $v = json_decode($a['stats'] ?? '{}', true) ?: [];
            $hallazgos = json_decode($a['hallazgos'] ?? '[]', true) ?: [];
            $esc = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');

            if (!class_exists('\\Mpdf\\Mpdf')) {
                $autoload = __DIR__ . '/../vendor/autoload.php';
                if (file_exists($autoload)) require_once $autoload;
            }
            if (!class_exists('\\Mpdf\\Mpdf')) return null;

            $logo = $this->assetB64('logos/avba.png');
            $logoHtml = $logo ? '<img src="' . $logo . '" style="max-height:50px">' : '<strong>AVBA</strong>';

            $eq = $v['equipos'] ?? []; $pe = $v['personal'] ?? [];
            $tabla = function($t, $s2) use ($esc) {
                return '<table style="width:100%;border-collapse:collapse;font-size:9.5pt;margin:4px 0 10px">
                    <tr><th style="background:#0d2244;color:#fff;padding:5px;text-align:left">' . $t . '</th>
                    <th style="background:#0d2244;color:#fff;padding:5px">Total</th>
                    <th style="background:#166534;color:#fff;padding:5px">Vigentes</th>
                    <th style="background:#92400e;color:#fff;padding:5px">Por vencer</th>
                    <th style="background:#991b1b;color:#fff;padding:5px">Vencidos</th></tr>
                    <tr><td style="padding:5px;border:1px solid #e3e8f0">Certificaciones</td>
                    <td style="padding:5px;border:1px solid #e3e8f0;text-align:center">' . (int)($s2['total']??0) . '</td>
                    <td style="padding:5px;border:1px solid #e3e8f0;text-align:center">' . (int)($s2['vigentes']??0) . '</td>
                    <td style="padding:5px;border:1px solid #e3e8f0;text-align:center">' . (int)($s2['por_vencer']??0) . '</td>
                    <td style="padding:5px;border:1px solid #e3e8f0;text-align:center">' . (int)($s2['vencidos']??0) . '</td></tr></table>';
            };

            $detFilas = '';
            foreach (array_merge($v['detalle']['equipos'] ?? [], $v['detalle']['personal'] ?? []) as $d) {
                $col = $d['dias'] < 0 ? '#991b1b' : '#92400e';
                $detFilas .= '<tr><td style="padding:4px;border-bottom:1px solid #eee">' . $esc($d['nombre']) . '</td>'
                    . '<td style="padding:4px;border-bottom:1px solid #eee">' . $esc($d['ref']) . '</td>'
                    . '<td style="padding:4px;border-bottom:1px solid #eee">' . $esc($d['para']) . '</td>'
                    . '<td style="padding:4px;border-bottom:1px solid #eee;color:' . $col . ';font-weight:bold">' . $esc($d['estado']) . ' (' . (int)$d['dias'] . 'd)</td></tr>';
            }
            $detBloque = $detFilas
                ? '<div style="font-weight:bold;color:#0d2244;margin:10px 0 4px">Detalle de vencidos / por vencer</div>
                   <table style="width:100%;border-collapse:collapse;font-size:8.5pt"><tr style="color:#667"><th style="text-align:left;padding:4px">Elemento</th><th style="text-align:left;padding:4px">Folio</th><th style="text-align:left;padding:4px">Cliente/Empresa</th><th style="text-align:left;padding:4px">Estado</th></tr>' . $detFilas . '</table>'
                : '<p style="font-size:9.5pt;color:#166534">Sin certificaciones vencidas ni por vencer en el periodo. ✓</p>';

            $hallFilas = '';
            foreach ($hallazgos as $i => $h) {
                $hallFilas .= '<tr><td style="padding:4px;border-bottom:1px solid #eee;text-align:center">' . ($i+1) . '</td>'
                    . '<td style="padding:4px;border-bottom:1px solid #eee">' . $esc($h['categoria'] ?? '') . '</td>'
                    . '<td style="padding:4px;border-bottom:1px solid #eee">' . $esc($h['descripcion'] ?? '') . '</td>'
                    . '<td style="padding:4px;border-bottom:1px solid #eee">' . $esc($h['severidad'] ?? '') . '</td></tr>';
            }
            $hallBloque = $hallFilas
                ? '<table style="width:100%;border-collapse:collapse;font-size:9pt"><tr style="color:#667"><th style="padding:4px">#</th><th style="text-align:left;padding:4px">Categoría</th><th style="text-align:left;padding:4px">Hallazgo</th><th style="text-align:left;padding:4px">Severidad</th></tr>' . $hallFilas . '</table>'
                : '<p style="font-size:9.5pt;color:#666">Sin hallazgos registrados.</p>';

            $resColor = stripos($a['resultado'],'no satisf') !== false ? '#991b1b' : (stripos($a['resultado'],'observ') !== false ? '#92400e' : '#166534');

            $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
                body{font-family:DejaVu Sans,Arial,sans-serif;font-size:10pt;color:#1a2233}
                h1{font-size:15pt;color:#0d2244;margin:0}
                .sec{font-weight:bold;color:#0d2244;font-size:11pt;border-bottom:1px solid #cdd5e6;padding-bottom:3px;margin:14px 0 6px}
                .box{border:1px solid #e3e8f0;border-radius:6px;padding:8px 12px;background:#f8fafc;font-size:9.5pt;white-space:pre-wrap}
                </style></head><body>'
                . '<table style="width:100%;border-bottom:2px solid #0d2244;margin-bottom:10px"><tr>'
                . '<td>' . $logoHtml . '</td><td style="text-align:right"><div style="font-size:11pt;font-weight:bold;color:#0d2244">AVBA Inspections, Certifications and Maintenance</div><div style="font-size:8.5pt;color:#555">Reporte de Auditoría de Calidad</div></td></tr></table>'
                . '<h1>Auditoría Trimestral — ' . $esc($a['periodo']) . ' ' . (int)$a['anio'] . '</h1>'
                . '<div style="font-size:9.5pt;color:#444;margin:4px 0 8px">Fecha de emisión: ' . date('d/m/Y')
                . ' &nbsp;·&nbsp; Resultado: <strong style="color:' . $resColor . '">' . $esc($a['resultado']) . '</strong></div>'
                . '<div class="sec">Equipos certificados</div>' . $tabla('Equipos', $eq)
                . '<div class="sec">Personal certificado</div>' . $tabla('Personal', $pe)
                . '<div class="sec">Vigencias por atender</div>' . $detBloque
                . '<div class="sec">Hallazgos</div>' . $hallBloque
                . ($a['observaciones'] ? '<div class="sec">Observaciones</div><div class="box">' . $esc($a['observaciones']) . '</div>' : '')
                . '<table style="width:100%;margin-top:40px"><tr><td style="text-align:center">'
                . '<div style="border-top:1px solid #333;width:70%;margin:0 auto;padding-top:4px;font-size:9.5pt"><strong>' . $esc($a['responsable']) . '</strong><br>Responsable de Calidad · AVBA</div>'
                . '</td></tr></table>'
                . '<div style="position:fixed;bottom:4mm;left:0;right:0;text-align:center;font-size:7.5pt;color:#999">Documento emitido a través del sistema de AVBA Inspección · www.avba.com.mx</div>'
                . '</body></html>';

            $mpdf = new \Mpdf\Mpdf(['mode'=>'utf-8','format'=>'Letter','margin_left'=>12,'margin_right'=>12,'margin_top'=>12,'margin_bottom'=>14,'tempDir'=>sys_get_temp_dir().'/mpdf']);
            $css=''; $body=$html;
            if (preg_match('/<style[^>]*>(.*?)<\/style>/is',$html,$m)) $css=$m[1];
            if (preg_match('/<body[^>]*>(.*)<\/body>/is',$html,$mb)) $body=$mb[1];
            if ($css!=='') $mpdf->WriteHTML($css, \Mpdf\HTMLParserMode::HEADER_CSS);
            $mpdf->WriteHTML($body, \Mpdf\HTMLParserMode::HTML_BODY);
            $dir = rtrim(UPLOAD_DIR,'/') . '/auditorias/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $nombre = 'Auditoria_' . (int)$a['anio'] . '_T' . (int)$a['trimestre'] . '.pdf';
            $mpdf->Output($dir . $nombre, 'F');
            return 'auditorias/' . $nombre;
        } catch (\Throwable $e) {
            error_log('[Auditorias] pdf: ' . $e->getMessage());
            return null;
        }
    }
}
