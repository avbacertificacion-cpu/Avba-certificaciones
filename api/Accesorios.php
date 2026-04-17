<?php
/**
 * AVBA Certificaciones — Módulo Accesorios de Izaje
 */

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

class Accesorios {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    // ── Catálogo de tipos (público autenticado) ────────────
    public function listarTipos(): array {
        $rows = $this->pdo->query(
            "SELECT id, nombre FROM accesorios_tipos WHERE activo = 1 ORDER BY nombre"
        )->fetchAll();
        return ['status' => 'success', 'data' => $rows];
    }

    // ── Catálogo de tipos (admin — incluye inactivos) ──────
    public function listarTiposAdmin(): array {
        $rows = $this->pdo->query(
            "SELECT id, nombre, activo, fecha_creacion FROM accesorios_tipos ORDER BY nombre"
        )->fetchAll();
        return ['status' => 'success', 'data' => $rows];
    }

    // ── Crear tipo ─────────────────────────────────────────
    public function crearTipo(array $payload): array {
        $nombre = trim($payload['nombre'] ?? '');
        if (!$nombre) return ['status' => 'error', 'message' => 'El nombre es requerido.'];

        $dup = $this->pdo->prepare("SELECT id FROM accesorios_tipos WHERE nombre = ?");
        $dup->execute([$nombre]);
        if ($dup->fetch()) return ['status' => 'error', 'message' => 'Ya existe un tipo con ese nombre.'];

        $this->pdo->prepare("INSERT INTO accesorios_tipos (nombre) VALUES (?)")->execute([$nombre]);
        return ['status' => 'success', 'message' => 'Tipo creado.', 'id' => (int)$this->pdo->lastInsertId()];
    }

    // ── Editar tipo ────────────────────────────────────────
    public function editarTipo(array $payload): array {
        $id     = (int)($payload['id'] ?? 0);
        $nombre = trim($payload['nombre'] ?? '');
        if (!$id || !$nombre) return ['status' => 'error', 'message' => 'id y nombre son requeridos.'];

        $dup = $this->pdo->prepare("SELECT id FROM accesorios_tipos WHERE nombre = ? AND id != ?");
        $dup->execute([$nombre, $id]);
        if ($dup->fetch()) return ['status' => 'error', 'message' => 'Ya existe otro tipo con ese nombre.'];

        $activo = isset($payload['activo']) ? (int)$payload['activo'] : 1;
        $this->pdo->prepare("UPDATE accesorios_tipos SET nombre = ?, activo = ? WHERE id = ?")
            ->execute([$nombre, $activo, $id]);

        return ['status' => 'success', 'message' => 'Tipo actualizado.'];
    }

    // ── Eliminar tipo ──────────────────────────────────────
    public function eliminarTipo(array $payload): array {
        $id = (int)($payload['id'] ?? 0);
        if (!$id) return ['status' => 'error', 'message' => 'id requerido.'];
        $this->pdo->prepare("DELETE FROM accesorios_tipos WHERE id = ?")->execute([$id]);
        return ['status' => 'success', 'message' => 'Tipo eliminado.'];
    }

    // ── Crear sesión (cliente + ubicación + fecha) ─────────
    public function crearSesion(array $payload, string $usuario): array {
        $cliente = trim($payload['cliente'] ?? '');
        $fecha   = trim($payload['fecha']   ?? '');  // DD/MM/YYYY
        $coords  = trim($payload['coordenadas'] ?? '');
        $dir     = trim($payload['direccion']   ?? '');

        if (!$cliente) return ['status' => 'error', 'message' => 'El cliente es requerido.'];
        if (!$fecha)   return ['status' => 'error', 'message' => 'La fecha es requerida.'];

        // Convert DD/MM/YYYY → Y-m-d
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $fecha, $m)) {
            $fecha = "{$m[3]}-{$m[2]}-{$m[1]}";
        }

        $this->pdo->prepare(
            "INSERT INTO accesorios_sesiones (cliente, fecha, coordenadas, direccion, usuario)
             VALUES (?, ?, ?, ?, ?)"
        )->execute([$cliente, $fecha, $coords, $dir, $usuario]);

        return ['status' => 'success', 'sesion_id' => (int)$this->pdo->lastInsertId()];
    }

    // ── Guardar un accesorio (multipart) ───────────────────
    public function guardarAccesorio(array $post, array $files, string $usuario): array {
        $sesionId = (int)($post['sesion_id'] ?? 0);
        if (!$sesionId) return ['status' => 'error', 'message' => 'sesion_id requerido.'];

        $chk = $this->pdo->prepare("SELECT id FROM accesorios_sesiones WHERE id = ?");
        $chk->execute([$sesionId]);
        if (!$chk->fetch()) return ['status' => 'error', 'message' => 'Sesión no encontrada.'];

        $tipoId = ($post['tipo_id'] ?? '') !== '' ? (int)$post['tipo_id'] : null;
        $estado = in_array($post['estado'] ?? '', ['CUMPLE','NO CUMPLE'])
            ? $post['estado'] : 'CUMPLE';

        // Count existing accessories in session for orden
        $cntStmt = $this->pdo->prepare("SELECT COUNT(*) FROM accesorios_izaje WHERE sesion_id = ?");
        $cntStmt->execute([$sesionId]);
        $orden = (int)$cntStmt->fetchColumn() + 1;

        $this->pdo->prepare(
            "INSERT INTO accesorios_izaje
             (sesion_id, id_accesorio, tipo_id, marca, modelo, serie, capacidad, medidas, estado, orden)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $sesionId,
            trim($post['id_accesorio'] ?? ''),
            $tipoId,
            trim($post['marca']    ?? ''),
            trim($post['modelo']   ?? ''),
            trim($post['serie']    ?? ''),
            trim($post['capacidad']?? ''),
            trim($post['medidas']  ?? ''),
            $estado,
            $orden,
        ]);

        $accesorioId = (int)$this->pdo->lastInsertId();

        // Handle photos (max 6, multipart files['fotos'])
        $fotosArr = $files['fotos'] ?? [];
        if (!empty($fotosArr['tmp_name'])) {
            if (!is_array($fotosArr['tmp_name'])) {
                // Single file — normalize
                $fotosArr = [
                    'tmp_name' => [$fotosArr['tmp_name']],
                    'name'     => [$fotosArr['name']],
                    'error'    => [$fotosArr['error']],
                ];
            }

            $uploadDir = __DIR__ . '/../uploads/accesorios/' . $sesionId . '/';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
                // Photos directory could not be created — skip silently, data is saved
            } else {
                $fotoOrden = 1;
                foreach ($fotosArr['tmp_name'] as $i => $tmpName) {
                    if (($fotosArr['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
                    if ($fotoOrden > 6) break;

                    $ext = strtolower(pathinfo($fotosArr['name'][$i] ?? '', PATHINFO_EXTENSION));
                    if (!in_array($ext, ['jpg','jpeg','png','webp','heic'])) continue;

                    $fname = "acc_{$accesorioId}_{$fotoOrden}.{$ext}";
                    if (move_uploaded_file($tmpName, $uploadDir . $fname)) {
                        $url = "uploads/accesorios/{$sesionId}/{$fname}";
                        $this->pdo->prepare(
                            "INSERT INTO accesorios_fotos (accesorio_id, url, orden) VALUES (?, ?, ?)"
                        )->execute([$accesorioId, $url, $fotoOrden]);
                        $fotoOrden++;
                    }
                }
            }
        }

        // Return tipo nombre for display in the list
        $tipoNombre = '';
        if ($tipoId) {
            $tn = $this->pdo->prepare("SELECT nombre FROM accesorios_tipos WHERE id = ?");
            $tn->execute([$tipoId]);
            $tipoNombre = (string)($tn->fetchColumn() ?: '');
        }

        return [
            'status'      => 'success',
            'message'     => 'Accesorio guardado.',
            'id'          => $accesorioId,
            'tipo_nombre' => $tipoNombre,
        ];
    }

    // ── Listar sesiones con resumen de accesorios ──────────
    public function listarSesiones(): array {
        $rows = $this->pdo->query(
            "SELECT s.id, s.cliente,
                    DATE_FORMAT(s.fecha,'%d/%m/%Y') AS fecha,
                    s.coordenadas, s.usuario,
                    DATE_FORMAT(s.fecha_registro,'%d/%m/%Y %H:%i') AS fecha_registro,
                    COUNT(a.id)                                           AS total,
                    SUM(a.estado = 'CUMPLE')                              AS cumple,
                    SUM(a.estado = 'NO CUMPLE')                           AS no_cumple
             FROM accesorios_sesiones s
             LEFT JOIN accesorios_izaje a ON a.sesion_id = s.id
             GROUP BY s.id
             ORDER BY s.fecha_registro DESC"
        )->fetchAll();

        return ['status' => 'success', 'data' => $rows];
    }

    // ── Detalle de una sesión con sus accesorios ───────────
    public function detalleSesion(int $id): array {
        $chk = $this->pdo->prepare("SELECT id, cliente, DATE_FORMAT(fecha,'%d/%m/%Y') AS fecha, coordenadas, direccion, usuario FROM accesorios_sesiones WHERE id = ?");
        $chk->execute([$id]);
        $sesion = $chk->fetch();
        if (!$sesion) return ['status' => 'error', 'message' => 'Sesión no encontrada.'];

        $stmt = $this->pdo->prepare(
            "SELECT a.id, a.id_accesorio, t.nombre AS tipo_nombre,
                    a.marca, a.modelo, a.serie, a.capacidad, a.medidas,
                    a.estado, a.orden,
                    COUNT(f.id) AS total_fotos
             FROM accesorios_izaje a
             LEFT JOIN accesorios_tipos t ON t.id = a.tipo_id
             LEFT JOIN accesorios_fotos f ON f.accesorio_id = a.id
             WHERE a.sesion_id = ?
             GROUP BY a.id
             ORDER BY a.orden"
        );
        $stmt->execute([$id]);
        $sesion['accesorios'] = $stmt->fetchAll();

        return ['status' => 'success', 'data' => $sesion];
    }

    // ── Generar informe PDF de una sesión ──────────────────
    public function generarInforme(int $sesionId, string $usuario): array {
        $det = $this->detalleSesion($sesionId);
        if ($det['status'] !== 'success') return $det;
        $sesion = $det['data'];

        if (!class_exists('Dompdf\Dompdf')) {
            return ['status' => 'error', 'message' => 'Motor PDF no disponible en el servidor.'];
        }

        $folio = 'ACC-' . str_pad((string)$sesionId, 5, '0', STR_PAD_LEFT);
        $html  = $this->htmlInforme($sesion, $folio);

        $opts = new \Dompdf\Options();
        $opts->setIsRemoteEnabled(true);
        $opts->setIsHtml5ParserEnabled(true);
        $opts->setDefaultMediaType('print');

        $pdf = new \Dompdf\Dompdf($opts);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4', 'portrait');
        $pdf->render();

        $dir = __DIR__ . '/../uploads/reportes/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $nombre  = $folio . '_' . date('Ymd_His') . '.pdf';
        $rutaAbs = $dir . $nombre;
        file_put_contents($rutaAbs, $pdf->output());

        return [
            'status' => 'success',
            'url'    => 'uploads/reportes/' . $nombre,
            'folio'  => $folio,
        ];
    }

    // ── HTML del Informe de Integridad Operativa ───────────
    private function htmlInforme(array $s, string $folio): string {
        $esc = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');

        $cliente = $esc($s['cliente']);
        $dir     = $esc($s['direccion'] ?? '');
        $fecha   = $esc($s['fecha']);
        $usuario = $esc($s['usuario']);
        $accs    = $s['accesorios'] ?? [];

        // ── Resumen de estados ────────────────────────────
        $total   = count($accs);
        $aptos   = count(array_filter($accs, fn($a) => strtoupper($a['estado']) === 'APTO'));
        $noAptos = count(array_filter($accs, fn($a) => strtoupper($a['estado']) === 'NO APTO'));
        $cond    = $total - $aptos - $noAptos;

        // ── Filas de la tabla ─────────────────────────────
        $filas = '';
        foreach ($accs as $i => $a) {
            $bg    = ($i % 2 === 0) ? '#ffffff' : '#f4f7fb';
            $est   = strtoupper($a['estado'] ?? '');
            if ($est === 'APTO') {
                $estBg  = '#EAF3DE'; $estColor = '#3B6D11'; $estBd = '#3B6D11';
            } elseif ($est === 'NO APTO') {
                $estBg  = '#FCEBEB'; $estColor = '#A32D2D'; $estBd = '#A32D2D';
            } else {
                $estBg  = '#FAEEDA'; $estColor = '#854F0B'; $estBd = '#854F0B';
            }
            $filas .= '
            <tr style="background:' . $bg . '">
              <td style="padding:6px 8px;border:1px solid #dfe5ef;text-align:center;font-size:9pt">' . $esc($a['id_accesorio']) . '</td>
              <td style="padding:6px 8px;border:1px solid #dfe5ef;font-size:9pt">' . $esc($a['tipo_nombre'] ?? '') . '</td>
              <td style="padding:6px 8px;border:1px solid #dfe5ef;font-size:9pt">' . $esc($a['marca'] ?? '') . '</td>
              <td style="padding:6px 8px;border:1px solid #dfe5ef;font-size:9pt">' . $esc($a['modelo'] ?? '') . '</td>
              <td style="padding:6px 8px;border:1px solid #dfe5ef;font-size:9pt;font-family:monospace">' . $esc($a['serie'] ?? '') . '</td>
              <td style="padding:6px 8px;border:1px solid #dfe5ef;text-align:center;font-size:9pt">' . $esc($a['capacidad'] ?? '') . '</td>
              <td style="padding:6px 8px;border:1px solid #dfe5ef;text-align:center;font-size:9pt">' . $esc($a['medidas'] ?? '') . '</td>
              <td style="padding:4px 6px;border:1px solid #dfe5ef;text-align:center">
                <span style="display:inline-block;padding:3px 8px;border-radius:10px;
                             background:' . $estBg . ';color:' . $estColor . ';
                             border:1px solid ' . $estBd . ';font-size:8pt;font-weight:bold">
                  ' . $esc($a['estado'] ?? '') . '
                </span>
              </td>
            </tr>';
        }

        if (!$filas) {
            $filas = '<tr><td colspan="8" style="padding:20px;text-align:center;color:#9299a8;font-size:9pt;border:1px solid #dfe5ef">Sin accesorios registrados en esta sesión.</td></tr>';
        }

        // ── SVG logo AVBA ─────────────────────────────────
        $logo = '<svg xmlns="http://www.w3.org/2000/svg" width="110" height="72" viewBox="0 0 110 72">
          <polygon points="18,2 28,18 8,18"  fill="#185FA5"/>
          <polygon points="32,2 42,18 22,18" fill="#3B9BC8"/>
          <polygon points="46,2 56,18 36,18" fill="#3B6D11"/>
          <text x="4"  y="38" font-family="Arial,sans-serif" font-weight="bold" font-size="22" fill="#185FA5">AVBA</text>
          <text x="4"  y="52" font-family="Arial,sans-serif" font-size="8.5" fill="#0C447C" letter-spacing="2">INSPECTIONS</text>
        </svg>';

        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 10pt; color: #1a1a2e; background: #fff; padding: 28px 32px; }
  .page-break { page-break-before: always; }
  .header-table { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
  .divider { border: none; border-top: 3px solid #185FA5; margin: 14px 0; }
  .divider-thin { border: none; border-top: 1px solid #dfe5ef; margin: 10px 0; }
  .doc-title { font-size: 17pt; font-weight: bold; color: #1a1a2e; text-align: center; margin-bottom: 4px; }
  .doc-sub { font-size: 9pt; color: #5a6072; text-align: center; font-style: italic; margin-bottom: 14px; }
  .info-table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
  .info-cell { padding: 7px 12px; border: 1px solid #ccd9ee; background: #E6F1FB; vertical-align: top; }
  .info-label { font-size: 8.5pt; font-weight: bold; color: #0C447C; display: block; margin-bottom: 2px; }
  .info-value { font-size: 10pt; color: #1a1a2e; }
  .intro-text { font-size: 9.5pt; color: #3a3a50; line-height: 1.7; text-align: justify;
                font-style: italic; margin: 14px 0 18px; padding: 12px 16px;
                border-left: 4px solid #185FA5; background: #f4f7fb; }
  .acc-table { width: 100%; border-collapse: collapse; margin-top: 8px; }
  .acc-table th { background: #185FA5; color: #ffffff; padding: 7px 8px;
                  font-size: 8.5pt; font-weight: bold; text-align: center;
                  border: 1px solid #0C447C; }
  .acc-table th.left { text-align: left; }
  .summary-table { width: 100%; border-collapse: collapse; margin-top: 18px; }
  .summary-cell { padding: 10px 14px; border-radius: 6px; text-align: center; border: 1px solid; }
  .sum-n { font-size: 18pt; font-weight: bold; }
  .sum-l { font-size: 8pt; margin-top: 2px; }
  .legend-row { margin-top: 14px; font-size: 8pt; color: #5a6072; }
  .badge { display: inline-block; padding: 2px 8px; border-radius: 8px;
           font-size: 8pt; font-weight: bold; border: 1px solid; margin-right: 8px; }
  .firma-table { width: 100%; border-collapse: collapse; margin-top: 40px; }
  .firma-cell { text-align: center; padding: 0 24px; vertical-align: bottom; width: 50%; }
  .firma-line { border-top: 1.5px solid #1a1a2e; padding-top: 6px; margin-top: 52px; font-size: 8.5pt; color: #5a6072; }
  .firma-sub { font-size: 7.5pt; color: #9299a8; margin-top: 2px; }
  .footer-strip { margin-top: 24px; padding-top: 8px; border-top: 1px solid #dfe5ef;
                  display: table; width: 100%; }
  .footer-l { display: table-cell; font-size: 7.5pt; color: #9299a8; vertical-align: middle; }
  .footer-r { display: table-cell; font-size: 7.5pt; color: #9299a8; text-align: right; vertical-align: middle; }
</style>
</head>
<body>

<!-- ── ENCABEZADO ── -->
<table class="header-table">
  <tr>
    <td style="width:130px;vertical-align:middle">{$logo}</td>
    <td style="vertical-align:middle;text-align:center">
      <div class="doc-title">Informe de Integridad Operativa</div>
      <div class="doc-sub">Documento técnico que evalúa la condición, seguridad y confiabilidad de los accesorios de izaje</div>
    </td>
    <td style="width:100px;vertical-align:top;text-align:right">
      <div style="background:#185FA5;color:#fff;padding:5px 10px;border-radius:5px;font-size:8pt;font-weight:bold">{$esc($folio)}</div>
      <div style="font-size:7.5pt;color:#9299a8;margin-top:4px">{$esc(date('d/m/Y'))}</div>
    </td>
  </tr>
</table>
<hr class="divider">

<!-- ── DATOS GENERALES ── -->
<table class="info-table">
  <tr>
    <td class="info-cell" style="width:60%">
      <span class="info-label">Cliente</span>
      <span class="info-value">{$cliente}</span>
    </td>
    <td style="width:8px"></td>
    <td class="info-cell" style="width:40%">
      <span class="info-label">Fecha de inspección</span>
      <span class="info-value">{$fecha}</span>
    </td>
  </tr>
  <tr><td colspan="3" style="height:5px"></td></tr>
  <tr>
    <td class="info-cell" style="width:60%">
      <span class="info-label">Domicilio</span>
      <span class="info-value">{$dir}</span>
    </td>
    <td style="width:8px"></td>
    <td class="info-cell" style="width:40%">
      <span class="info-label">Inspector</span>
      <span class="info-value">{$usuario}</span>
    </td>
  </tr>
</table>

<!-- ── TEXTO INTRODUCTORIO ── -->
<div class="intro-text">
  El presente informe integra una evaluación de los accesorios inspeccionados,
  permitiendo identificar niveles de criticidad y priorizar acciones correctivas de manera eficiente.
  Los resultados aquí descritos corresponden al estado físico y operativo observado en la fecha indicada.
</div>

<!-- ── RESUMEN ── -->
<table class="summary-table">
  <tr>
    <td style="width:25%;padding-right:6px">
      <div style="padding:10px 14px;border-radius:6px;text-align:center;background:#E6F1FB;border:1px solid #185FA5">
        <div style="font-size:20pt;font-weight:bold;color:#185FA5">{$total}</div>
        <div style="font-size:8pt;color:#5a6072;margin-top:2px">Total accesorios</div>
      </div>
    </td>
    <td style="width:25%;padding-right:6px">
      <div style="padding:10px 14px;border-radius:6px;text-align:center;background:#EAF3DE;border:1px solid #3B6D11">
        <div style="font-size:20pt;font-weight:bold;color:#3B6D11">{$aptos}</div>
        <div style="font-size:8pt;color:#3B6D11;margin-top:2px">Aptos</div>
      </div>
    </td>
    <td style="width:25%;padding-right:6px">
      <div style="padding:10px 14px;border-radius:6px;text-align:center;background:#FAEEDA;border:1px solid #854F0B">
        <div style="font-size:20pt;font-weight:bold;color:#854F0B">{$cond}</div>
        <div style="font-size:8pt;color:#854F0B;margin-top:2px">Condicionados</div>
      </div>
    </td>
    <td style="width:25%">
      <div style="padding:10px 14px;border-radius:6px;text-align:center;background:#FCEBEB;border:1px solid #A32D2D">
        <div style="font-size:20pt;font-weight:bold;color:#A32D2D">{$noAptos}</div>
        <div style="font-size:8pt;color:#A32D2D;margin-top:2px">No aptos</div>
      </div>
    </td>
  </tr>
</table>

<!-- ── TABLA DE ACCESORIOS ── -->
<div style="font-size:10pt;font-weight:bold;color:#185FA5;margin:18px 0 6px;
            text-transform:uppercase;letter-spacing:.05em;border-bottom:2px solid #185FA5;
            padding-bottom:4px">
  Registro de accesorios inspeccionados
</div>
<table class="acc-table">
  <thead>
    <tr>
      <th style="width:8%">ID</th>
      <th class="left" style="width:16%">Tipo</th>
      <th class="left" style="width:12%">Marca</th>
      <th class="left" style="width:12%">Modelo</th>
      <th style="width:14%">No. Serie</th>
      <th style="width:12%">Capacidad</th>
      <th style="width:12%">Medidas</th>
      <th style="width:14%">Estado</th>
    </tr>
  </thead>
  <tbody>
    {$filas}
  </tbody>
</table>

<!-- ── LEYENDA ── -->
<div class="legend-row">
  <strong>Leyenda: </strong>
  <span class="badge" style="background:#EAF3DE;color:#3B6D11;border-color:#3B6D11">APTO</span>
  El accesorio se encuentra en condiciones seguras de operación. &nbsp;
  <span class="badge" style="background:#FAEEDA;color:#854F0B;border-color:#854F0B">CONDICIONADO</span>
  Requiere atención o seguimiento. &nbsp;
  <span class="badge" style="background:#FCEBEB;color:#A32D2D;border-color:#A32D2D">NO APTO</span>
  Fuera de servicio inmediato.
</div>

<!-- ── FIRMAS ── -->
<table class="firma-table">
  <tr>
    <td class="firma-cell">
      <div class="firma-line">Inspector responsable</div>
      <div class="firma-sub">{$usuario}</div>
    </td>
    <td class="firma-cell">
      <div class="firma-line">Recibió / Vo. Bo.</div>
      <div class="firma-sub">Representante del cliente</div>
    </td>
  </tr>
</table>

<!-- ── PIE DE PÁGINA ── -->
<div class="footer-strip">
  <div class="footer-l">AVBA Inspections, Certifications and Maintenance S.A.S. de C.V. &nbsp;·&nbsp; Informe generado el {$esc(date('d/m/Y H:i'))}</div>
  <div class="footer-r">Folio: {$esc($folio)}</div>
</div>

</body>
</html>
HTML;
    }
}
