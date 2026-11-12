<?php
/**
 * AVBA — Reporte de inspección completo (checklist)
 *
 * Lo que el inspector capturó en campo, entero y en un PDF: datos del equipo,
 * TODOS los puntos del checklist de su tipo de equipo —también los que se
 * quedaron sin respuesta, para que se note—, la prueba de carga tal como se
 * capturó, las horquillas, las recomendaciones que dejó y la evidencia
 * fotográfica completa.
 *
 * Es un registro de campo: no es certificado ni dictamen, y así lo dice. Se
 * genera al pedirlo, con los datos de ese momento, así que refleja las
 * correcciones que haya hecho Calidad.
 */
class ReporteChecklist {

    public function __construct(private PDO $pdo) {}

    /** ['status'=>'success','url'=>…] o error. */
    public function generar(int $equipoId, array $usr): array {
        $st = $this->pdo->prepare("SELECT * FROM equipos WHERE id = ?");
        $st->execute([$equipoId]);
        $eq = $st->fetch(PDO::FETCH_ASSOC);
        if (!$eq) return ['status' => 'error', 'message' => 'Inspección no encontrada.'];

        // El inspector ve las suyas; Admin y Calidad, todas.
        $esSuya = strcasecmp((string)($eq['inspector'] ?? ''), (string)($usr['usuario'] ?? '')) === 0;
        if (!$esSuya && !in_array($usr['rol'] ?? '', ['ADMIN', 'CALIDAD'], true)) {
            return ['status' => 'error', 'message' => 'Esta inspección no es tuya.'];
        }

        try {
            $html = $this->html($eq);
            $url  = $this->pdf($html, (string)($eq['control'] ?: $equipoId));
        } catch (\Throwable $ex) {
            error_log('[ReporteChecklist] ' . $ex->getMessage());
            return ['status' => 'error', 'message' => 'No se pudo generar el reporte: ' . $ex->getMessage()];
        }
        return ['status' => 'success', 'url' => $url];
    }

    /** Todos los puntos del tipo de equipo, con la respuesta de esta inspección si la hay. */
    private function checklist(array $eq): array {
        $st = $this->pdo->prepare(
            "SELECT ci.descripcion, COALESCE(ic.valor, '') AS valor,
                    COALESCE(cs.nombre, ci.seccion) AS seccion_nombre
             FROM checklist_items ci
             INNER JOIN maquinaria_tipos mt ON mt.id = ci.maquinaria_tipo_id AND mt.nombre = ?
             LEFT JOIN inspeccion_checklist ic ON ic.tag = ci.tag AND ic.equipo_id = ?
             LEFT JOIN checklist_secciones cs ON cs.maquinaria_tipo_id = mt.id AND cs.codigo = ci.seccion
             ORDER BY ci.seccion, ci.orden"
        );
        $st->execute([(string)($eq['maquinaria'] ?? ''), (int)$eq['id']]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    private function inspector(string $usuario): array {
        if ($usuario === '') return ['', '', ''];
        $st = $this->pdo->prepare("SELECT id, nombre, firma_imagen FROM usuarios WHERE usuario = ? LIMIT 1");
        $st->execute([$usuario]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if (!$u) return [$usuario, '', ''];
        $firma = '';
        $ruta = $u['firma_imagen'] ? __DIR__ . '/../' . ltrim((string)$u['firma_imagen'], '/') : '';
        if ($ruta && is_file($ruta)) $firma = $ruta;
        return [(string)($u['nombre'] ?: $usuario), (string)$u['id'], $firma];
    }

    private function fotos(string $url): array {
        if ($url === '') return [];
        $dir = rtrim(str_replace(rtrim(UPLOAD_URL, '/'), rtrim(UPLOAD_DIR, '/'), rtrim($url, '/')), '/') . '/';
        if (!is_dir($dir)) return [];
        $f = glob($dir . '*.{jpg,jpeg,png,JPG,JPEG,PNG,webp,WEBP}', GLOB_BRACE) ?: [];
        sort($f);
        return $f;
    }

    private function recomendaciones(int $id): array {
        if (!class_exists('Recomendaciones')) {
            $f = __DIR__ . '/Recomendaciones.php';
            if (!is_file($f)) return [];
            require_once $f;
        }
        try {
            return (new Recomendaciones($this->pdo))->listar('equipo', $id, false);
        } catch (\Throwable $ex) {
            return [];
        }
    }

    private function html(array $eq): string {
        $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        [$nomInsp, $numInsp, $firma] = $this->inspector((string)($eq['inspector'] ?? ''));
        $folio = $e(formatoFolio((string)($eq['control'] ?? '')));
        $fecha = '';
        if (!empty($eq['fecha_inspeccion'])) {
            try { $fecha = (new \DateTime($eq['fecha_inspeccion']))->format('d/m/Y'); } catch (\Throwable $x) {}
        }
        $logo = __DIR__ . '/../assets/logos/avba.png';
        $tipo = $e(textoEstandar((string)($eq['maquinaria'] ?? '')));

        $dato = fn($k, $v, $w = '') => '<td' . ($w ? " style=\"width:$w\"" : '') . '><div class="ax-k">' . $k . '</div><div class="ax-v">' . ($v !== '' ? $v : '—') . '</div></td>';
        $h = fn($es, $en) => '<div class="ax-h">' . $es . ' <span>· ' . $en . '</span></div>';

        $o  = '<table class="d-hdr"><tr><td class="d-logo"><img src="' . $logo . '" style="height:12mm;width:auto;"></td>'
            . '<td class="d-emp"><div class="d-emp-n">AVBA INSPECTIONS</div><div class="d-emp-s">Certifications and Maintenance S.A.S. de C.V.</div>'
            . '<div class="d-emp-a">Unidad de Inspección Tipo A · Acreditada ante la EMA</div></td>'
            . '<td class="d-doc"><div class="d-doc-t">Reporte de Inspección</div><div class="d-doc-e">Registro de campo · checklist completo</div>'
            . '<div class="d-doc-f"><span>FOLIO <b>' . $folio . '</b></span></div></td></tr></table><div class="d-regla"></div>';

        $o .= '<table class="d-hero"><tr><td class="d-hero-txt"><div class="d-hero-t">REPORTE DE INSPECCIÓN</div>'
            . '<div class="d-hero-s">' . $tipo . '</div>'
            . '<div class="d-hero-e"><b>' . $e(($eq['marca'] ?? '') . ' ' . ($eq['modelo'] ?? '')) . '</b> · No. de serie <b>' . $e($eq['serie'] ?? '') . '</b> · Inspección <b>' . $e($fecha) . '</b></div></td>'
            . '<td class="d-hero-folio"><table width="100%" style="border-collapse:collapse;"><tr><td class="d-folio">'
            . '<div class="d-folio-l">Inspector</div><div class="d-folio-v" style="font-size:9pt">' . $e($nomInsp ?: '—') . '</div>'
            . '<div class="d-folio-x">' . ($numInsp !== '' ? 'Inspector No. ' . $e($numInsp) : 'Estado: ' . $e($eq['estado'] ?? '')) . '</div></td></tr></table></td></tr></table>';

        $o .= '<div class="ax-nota" style="margin-top:2.4mm">Registro de lo capturado en campo por el inspector. <b>No es un certificado ni un dictamen</b>: '
            . 'el resultado oficial de la inspección es el que emite AVBA en esos documentos.</div>';

        $o .= $h('Datos de la inspección', 'Inspection data');
        $o .= '<table class="ax-ficha2">'
            . '<tr>' . $dato('Cliente', $e($eq['cliente'] ?? ''), '60%') . $dato('Estado', $e($eq['estado'] ?? '')) . '</tr>'
            . '<tr><td colspan="2"><div class="ax-k">Domicilio / lugar de inspección</div><div class="ax-v">' . ($e($eq['direccion'] ?? '') ?: '—') . '</div></td></tr></table>'
            . '<table class="ax-ficha2"><tr>' . $dato('Tipo de equipo', $tipo, '30%') . $dato('Marca', $e($eq['marca'] ?? '')) . $dato('Modelo', $e($eq['modelo'] ?? ''))
            . $dato('No. de serie', $e($eq['serie'] ?? '')) . '</tr><tr>' . $dato('Capacidad', $e($eq['capacidad'] ?? '')) . $dato('No. identificación', $e($eq['id_equipo'] ?? ''))
            . $dato('Fecha de inspección', $e($fecha)) . $dato('Folio', $folio) . '</tr></table>';

        // Checklist completo
        $items = $this->checklist($eq);
        $o .= DictamenAnexos::checklist($items);
        $sinResp = count(array_filter($items, fn($i) => trim((string)$i['valor']) === ''));
        if ($sinResp > 0) {
            $o .= '<div class="ax-nota">' . $sinResp . ' punto(s) del checklist quedaron sin respuesta en la captura (marcados con —).</div>';
        }

        // Prueba de carga tal como se capturó
        $pc = json_decode((string)($eq['prueba_carga'] ?? ''), true) ?: [];
        unset($pc['plantilla']);
        $filas = array_filter($pc, 'is_array');
        $cabecera = array_filter($pc, fn($v) => !is_array($v) && trim((string)$v) !== '');
        $hay = false;
        foreach ($filas as $f) foreach ($f as $v) if (!is_array($v) && trim((string)$v) !== '') $hay = true;
        if ($hay) {
            $nom = ['peso' => 'Peso (TON)', 'radio' => 'Radio (m)', 'pluma' => 'Pluma (m)', 'angulo' => 'Ángulo (°)', 'altura' => 'Altura (m)',
                    'resultado' => 'Resultado', 'duracion' => 'Duración (min)', 'deformacion' => 'Deformación', 'inclin' => 'Inclinación',
                    'extension' => 'Extensión (m)', 'flecha' => 'Flecha (mm)', 'luz' => 'Claro (m)', 'cml' => 'CML', 'prueba' => 'Carga de prueba',
                    'capacidad' => 'Capacidad', 'tipo_plataforma' => 'Tipo'];
            $etq = ['sin' => 'Sin carga', 'con' => 'Con carga', 'trabajo' => 'Carga de trabajo', 'sobrecarga' => 'Sobrecarga', 'estatica' => 'Estática (125%)',
                    'prueba_estatica' => 'Estática', 'princ_sin' => 'Gancho principal · sin carga', 'princ_con' => 'Gancho principal · con carga',
                    'aux_sin' => 'Gancho auxiliar · sin carga', 'aux_con' => 'Gancho auxiliar · con carga'];
            $cols = [];
            foreach ($filas as $f) foreach ($f as $k => $v) if (!is_array($v) && !in_array($k, $cols, true)) $cols[] = $k;
            $o .= $h('Prueba de carga capturada', 'Load test');
            if ($cabecera) {
                $o .= '<div class="ax-p" style="margin-bottom:1.4mm">';
                foreach ($cabecera as $k => $v) {
                    $txt = $k === 'altura_total' ? 'Altura total bajo gancho (m)' : ($nom[$k] ?? ucfirst(str_replace('_', ' ', (string)$k)));
                    $o .= '<b>' . $e($txt) . ':</b> ' . $e($v) . ' &nbsp; ';
                }
                $o .= '</div>';
            }
            $o .= '<table class="ax-tab"><thead><tr><th>Prueba</th>';
            foreach ($cols as $c) $o .= '<th>' . $e($nom[$c] ?? ucfirst((string)$c)) . '</th>';
            $o .= '</tr></thead><tbody>';
            foreach ($filas as $k => $f) {
                $o .= '<tr><td class="ax-cl">' . $e($etq[$k] ?? ucfirst((string)$k)) . '</td>';
                foreach ($cols as $c) {
                    $v = is_array($f[$c] ?? null) ? '' : trim((string)($f[$c] ?? ''));
                    $o .= '<td>' . ($v !== '' ? $e($v) : '<span style="color:#9aa5b4">—</span>') . '</td>';
                }
                $o .= '</tr>';
            }
            $o .= '</tbody></table>';
        }

        // Horquillas
        $hq = json_decode((string)($eq['horquillas'] ?? ''), true) ?: [];
        if (array_filter($hq, fn($v) => !is_array($v) && trim((string)$v) !== '')) {
            $o .= $h('Horquillas', 'Forks');
            $o .= '<table class="ax-ficha2"><tr>' . $dato('Marca', $e($hq['marca'] ?? '')) . $dato('No. de serie', $e($hq['serie'] ?? ''))
                . $dato('Capacidad', $e($hq['capacidad'] ?? '')) . $dato('Centro de gravedad', $e($hq['centro_gravedad'] ?? '')) . '</tr></table>';
        }

        // Recomendaciones del inspector
        $recs = $this->recomendaciones((int)$eq['id']);
        if ($recs) {
            $o .= $h('Recomendaciones del inspector', 'Inspector recommendations');
            $o .= '<table class="ax-tab"><thead><tr><th style="width:16%">Prioridad</th><th>Recomendación</th><th style="width:16%">Estado</th></tr></thead><tbody>';
            foreach ($recs as $r) {
                $o .= '<tr><td class="ax-cl">' . $e(ucfirst((string)($r['prioridad'] ?? ''))) . '</td><td>' . $e($r['texto'] ?? '') . '</td><td>' . $e(ucfirst(strtolower((string)($r['estado'] ?? '')))) . '</td></tr>';
            }
            $o .= '</tbody></table>';
        }

        // Evidencia fotográfica completa
        $fotos = $this->fotos((string)($eq['evidencia_url'] ?? ''));
        if ($fotos) {
            $o .= '<pagebreak />' . $h('Evidencia fotográfica', count($fotos) . ' fotografías');
            $o .= '<table class="rc-fotos">';
            foreach (array_chunk($fotos, 3) as $fila) {
                $o .= '<tr>';
                foreach ($fila as $f) $o .= '<td><img src="' . $e($f) . '" style="max-height:44mm;max-width:60mm;"></td>';
                for ($i = count($fila); $i < 3; $i++) $o .= '<td class="rc-vacia"></td>';
                $o .= '</tr>';
            }
            $o .= '</table>';
        }

        // Firma
        $o .= '<table class="rc-firma" style="page-break-inside:avoid;">'
            . '<tr><td class="rc-f-l"><div class="ax-k">Elaboró · inspector</div></td></tr>'
            . '<tr><td class="rc-f-i">' . ($firma ? '<img src="' . $e($firma) . '">' : '') . '</td></tr>'
            . '<tr><td class="rc-f-n"><div class="rc-firma-n">' . $e($nomInsp ?: '—') . '</div>'
            . ($numInsp !== '' ? '<div class="ax-k" style="text-transform:none">Inspector No. ' . $e($numInsp) . '</div>' : '')
            . '</td></tr></table>';

        $pie = '<htmlpagefooter name="pieRep"><table class="d-pie"><tr>'
            . '<td><b>AVBA Inspections, Certifications and Maintenance S.A.S. de C.V.</b><br>Reporte de inspección · registro de campo · no sustituye al certificado ni al dictamen</td>'
            . '<td class="d-pie-d">Folio <span class="d-pie-f">' . $folio . '</span><br>Página {PAGENO} de {nbpg}</td></tr></table></htmlpagefooter>';

        return '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Reporte de inspección ' . $folio . '</title>'
            . self::CSS . DictamenAnexos::CSS . '</head><body>' . $pie . $o . '</body></html>';
    }

    private function pdf(string $html, string $control): string {
        if (!class_exists('\\Mpdf\\Mpdf')) require_once __DIR__ . '/../vendor/autoload.php';
        $dir = rtrim(UPLOAD_DIR, '/') . '/reportes/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8', 'format' => [215, 279],
            'margin_left' => 11, 'margin_right' => 11, 'margin_top' => 8, 'margin_bottom' => 15, 'margin_footer' => 4,
            'default_font' => 'dejavusans', 'tempDir' => sys_get_temp_dir() . '/mpdf',
        ] + (method_exists('Certificaciones', 'fuentesAvba') ? Certificaciones::fuentesAvba() : []));
        $mpdf->SetBasePath(__DIR__ . '/../');
        $mpdf->useSubstitutions = true;
        $prev = (int)ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '10000000');
        $mpdf->WriteHTML($html);
        ini_set('pcre.backtrack_limit', (string)$prev);
        $nombre = 'Checklist_AVBA_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $control) . '.pdf';
        $mpdf->Output($dir . $nombre, 'F');
        // Evita que el navegador muestre una versión anterior del mismo archivo.
        return rtrim(UPLOAD_URL, '/') . '/reportes/' . $nombre . '?v=' . time();
    }

    private const CSS = <<<'CSS'
<style>
@page { footer:html_pieRep; }
body { font-family:'avbainter','Inter',Arial,sans-serif; font-size:8.4pt; color:#1f2d3d; }
.d-hdr { width:100%; border-collapse:collapse; }
.d-hdr td { vertical-align:middle; padding:0; }
.d-hdr .d-logo { width:26mm; padding-right:3mm; }
.d-hdr .d-logo img { max-height:12mm; max-width:24mm; }
.d-hdr .d-emp { border-left:1.2mm solid #C9A84C; padding-left:4mm; }
.d-emp-n { font-family:'avbamont','Montserrat',sans-serif; font-weight:900; font-size:10pt; color:#0B2545; }
.d-emp-s { font-size:7pt; color:#5a6779; margin-top:.6mm; }
.d-emp-a { font-size:7pt; color:#a8852c; font-weight:bold; margin-top:.4mm; }
.d-hdr .d-doc { text-align:right; width:74mm; }
.d-doc-t { font-family:'avbamont','Montserrat',sans-serif; font-weight:900; font-size:10.5pt; color:#0B2545; text-transform:uppercase; }
.d-doc-e { font-size:7pt; color:#5a6779; font-style:italic; margin-top:.4mm; }
.d-doc-f { margin-top:1.4mm; font-size:7.6pt; } .d-doc-f b { color:#0B2545; }
.d-regla { height:0; border-top:.6mm solid #0B2545; margin:2mm 0 2.6mm; }
.d-hero { width:100%; border-collapse:collapse; background:#0B2545; border-bottom:1.2mm solid #C9A84C; }
.d-hero td { vertical-align:middle; }
.d-hero .d-hero-txt { padding:2.8mm 5mm; border-left:1.6mm solid #C9A84C; }
.d-hero-t { font-family:'avbamont','Montserrat',sans-serif; font-weight:900; font-size:14.5pt; color:#ffffff; }
.d-hero-s { font-family:'avbamont','Montserrat',sans-serif; font-weight:800; font-size:9pt; color:#e3c97a; margin-top:1mm; }
.d-hero-e { font-size:7.8pt; color:#c9d6e6; margin-top:.8mm; } .d-hero-e b { color:#ffffff; }
.d-hero .d-hero-folio { width:56mm; padding:2.4mm 4mm 2.4mm 0; }
.d-folio { background:#ffffff; padding:2.2mm 3mm; text-align:center; }
.d-folio-l { font-size:6.5pt; font-weight:bold; color:#5a6779; letter-spacing:.8px; text-transform:uppercase; }
.d-folio-v { font-family:'avbamont','Montserrat',sans-serif; font-weight:900; color:#0B2545; margin-top:.8mm; }
.d-folio-x { font-size:7pt; color:#a8852c; font-weight:bold; margin-top:1mm; }
.ax-ficha2 { width:100%; border-collapse:collapse; }
.ax-ficha2 td { border:.3mm solid #dfe6ee; padding:1.2mm 2.6mm; vertical-align:top; }
.rc-fotos { width:100%; border-collapse:collapse; }
.rc-fotos td { width:33.3%; border:.3mm solid #dfe6ee; padding:1.2mm; text-align:center; vertical-align:middle; height:46mm; }
.rc-fotos img { max-width:100%; max-height:44mm; }
.rc-firma { width:80mm; border-collapse:collapse; margin:5mm auto 0; }
.rc-firma td { text-align:center; }
.rc-firma td.rc-f-i { height:17mm; vertical-align:bottom; } .rc-firma td.rc-f-i img { max-height:16mm; max-width:50mm; }
.rc-firma td.rc-f-n { border-top:.4mm solid #C9A84C; padding-top:1.4mm; }
.rc-firma-n { font-family:'avbamont','Montserrat',sans-serif; font-weight:800; font-size:8.6pt; color:#0B2545; }
.d-pie { width:100%; border-collapse:collapse; border-top:.4mm solid #C9A84C; }
.d-pie td { padding-top:1.6mm; font-size:6.6pt; color:#6b7a8f; vertical-align:top; }
.d-pie .d-pie-d { text-align:right; width:56mm; } .d-pie b { color:#0B2545; } .d-pie .d-pie-f { color:#a8852c; font-weight:bold; }
</style>
CSS;
}
