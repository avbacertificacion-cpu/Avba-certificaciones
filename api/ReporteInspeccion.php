<?php
/**
 * AVBA Certificaciones — Generador de Reporte de Inspección
 * Genera el PDF del formato de inspección (fotos + checklist)
 * replicando el formato Word oficial.
 */

use Dompdf\Dompdf;
use Dompdf\Options;

class ReporteInspeccion {

    /**
     * Genera el PDF del reporte de inspección y guarda la URL en equipos.
     * Retorna ['status'=>'success','url'=>'...'] o ['status'=>'error','message'=>'...']
     */
    public static function generar(PDO $pdo, int $equipoId, string $usuarioLogin = ''): array {
        try {
            // Verificar que autoload esté disponible
            if (!class_exists('Dompdf\Dompdf')) {
                $autoload = __DIR__ . '/../vendor/autoload.php';
                if (!file_exists($autoload)) {
                    return ['status' => 'error', 'message' => 'Composer vendor no encontrado. Ejecuta composer install.'];
                }
                require_once $autoload;
            }

            // Obtener nombre completo del inspector
            $nombreInspector = $usuarioLogin;
            if ($usuarioLogin) {
                $stmtU = $pdo->prepare("SELECT nombre FROM usuarios WHERE usuario = ?");
                $stmtU->execute([$usuarioLogin]);
                $u = $stmtU->fetch();
                if ($u && $u['nombre']) $nombreInspector = $u['nombre'];
            }
            // Cargar datos del equipo
            $stmt = $pdo->prepare(
                "SELECT e.*,
                        DATE_FORMAT(e.fecha_inspeccion, '%d/%m/%Y') AS fecha_fmt
                 FROM equipos e WHERE e.id = ?"
            );
            $stmt->execute([$equipoId]);
            $equipo = $stmt->fetch();
            if (!$equipo) return ['status' => 'error', 'message' => 'Equipo no encontrado.'];

            // Cargar checklist con nombres de sección
            $stmt2 = $pdo->prepare(
                "SELECT ci.seccion, ci.tag, ci.descripcion, ci.orden,
                        COALESCE(ic.valor, '') AS valor,
                        COALESCE(cs.nombre, ci.seccion) AS seccion_nombre
                 FROM checklist_items ci
                 INNER JOIN maquinaria_tipos mt ON mt.id = ci.maquinaria_tipo_id
                     AND mt.nombre = ?
                 LEFT JOIN inspeccion_checklist ic ON ic.tag = ci.tag
                     AND ic.equipo_id = ?
                 LEFT JOIN checklist_secciones cs ON cs.maquinaria_tipo_id = mt.id
                     AND cs.codigo = ci.seccion
                 ORDER BY ci.seccion, ci.orden"
            );
            $stmt2->execute([$equipo['maquinaria'] ?? '', $equipoId]);
            $checklistItems = $stmt2->fetchAll();

            // Agrupar checklist por sección
            $checklist = [];
            foreach ($checklistItems as $item) {
                $sec = $item['seccion'];
                if (!isset($checklist[$sec])) {
                    $checklist[$sec] = [
                        'nombre' => $item['seccion_nombre'],
                        'items'  => []
                    ];
                }
                $checklist[$sec]['items'][] = $item;
            }

            // Obtener fotos (máximo 6)
            $fotos = self::obtenerFotos($equipo['evidencia_url'] ?? '');

            // Construir HTML
            $html = self::buildHtml($equipo, $checklist, $fotos, $nombreInspector);

            // Generar PDF con DOMPDF
            if (!class_exists('Dompdf\Dompdf')) {
                require_once __DIR__ . '/../vendor/autoload.php';
            }

            $opts = new Options();
            $opts->set('isRemoteEnabled', false);
            $opts->set('isHtml5ParserEnabled', true);
            $opts->set('defaultFont', 'Arial');

            $dompdf = new Dompdf($opts);
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('Letter', 'portrait');
            $dompdf->render();

            // Guardar PDF
            $carpeta = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . 'reportes' . DIRECTORY_SEPARATOR;
            if (!is_dir($carpeta) && !mkdir($carpeta, 0755, true)) {
                return ['status' => 'error', 'message' => "No se pudo crear carpeta: {$carpeta}"];
            }

            $control  = preg_replace('/[^a-zA-Z0-9\-_]/', '_', $equipo['control'] ?? $equipoId);
            $archivo  = "Reporte_AVBA_{$control}.pdf";
            $rutaPDF  = $carpeta . $archivo;
            $urlPDF   = rtrim(UPLOAD_URL, '/') . '/reportes/' . $archivo;

            $bytes = $dompdf->output();
            if (!$bytes) return ['status' => 'error', 'message' => 'DOMPDF no generó contenido.'];
            if (file_put_contents($rutaPDF, $bytes) === false) {
                return ['status' => 'error', 'message' => "No se pudo escribir el archivo: {$rutaPDF}"];
            }

            // Guardar URL en equipos
            $pdo->prepare("UPDATE equipos SET reporte_url = ? WHERE id = ?")
                ->execute([$urlPDF, $equipoId]);

            return ['status' => 'success', 'url' => $urlPDF];

        } catch (Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    // ── Obtener rutas locales de fotos ────────────────────────
    private static function obtenerFotos(string $evidenciaUrl): array {
        if (!$evidenciaUrl) return [];

        // Convertir URL a ruta local
        $localPath = str_replace(
            rtrim(UPLOAD_URL, '/'),
            rtrim(UPLOAD_DIR, '/'),
            rtrim($evidenciaUrl, '/')
        ) . '/';

        if (!is_dir($localPath)) return [];

        $archivos = glob($localPath . '*.{jpg,jpeg,png,JPG,JPEG,PNG}', GLOB_BRACE);
        if (!$archivos) return [];

        sort($archivos);
        return array_slice($archivos, 0, 9);
    }

    // ── Construir HTML del reporte ────────────────────────────
    private static function buildHtml(array $eq, array $checklist, array $fotos, string $inspector = ''): string {

        $gdDisponible = extension_loaded('gd');

        // Logo en base64 (solo si GD está disponible)
        $logoTag = '';
        if ($gdDisponible) {
            $logoPath = __DIR__ . '/../icon-192.png';
            if (file_exists($logoPath)) {
                $b64     = base64_encode(file_get_contents($logoPath));
                $logoTag = "<img src='data:image/png;base64,{$b64}' style='width:60px;height:60px;object-fit:contain'>";
            }
        } else {
            $logoTag = "<div style='width:60px;height:60px;background:#185FA5;color:white;font-size:10px;font-weight:bold;display:flex;align-items:center;justify-content:center;border-radius:8px'>AVBA</div>";
        }

        $cliente    = htmlspecialchars($eq['cliente']          ?? '');
        $domicilio  = htmlspecialchars($eq['direccion']        ?? '');
        $maquinaria = htmlspecialchars($eq['maquinaria']       ?? '');
        $marca      = htmlspecialchars($eq['marca']            ?? '');
        $modelo     = htmlspecialchars($eq['modelo']           ?? '');
        $serie      = htmlspecialchars($eq['serie']            ?? '');
        $idEquipo   = htmlspecialchars($eq['id_equipo']        ?? '');
        $fecha      = htmlspecialchars($eq['fecha_fmt']        ?? '');
        $capacidad  = htmlspecialchars($eq['capacidad']        ?? '');
        $control    = htmlspecialchars($eq['control']          ?? '');

        // ── Grilla de fotos (3x3, solo celdas con foto) ──────────
        $fotosHtml  = '';
        $totalFotos = min(9, count($fotos));
        for ($i = 0; $i < 9; $i++) {
            $tienesFoto = $gdDisponible && isset($fotos[$i]) && file_exists($fotos[$i]);
            if ($tienesFoto) {
                $b64  = base64_encode(file_get_contents($fotos[$i]));
                $ext  = strtolower(pathinfo($fotos[$i], PATHINFO_EXTENSION));
                $mime = ($ext === 'png') ? 'image/png' : 'image/jpeg';
                $fotosHtml .= "<td style='border:1px solid #ccc;width:33%;height:140px;text-align:center;vertical-align:middle;padding:3px'>
                    <img src='data:{$mime};base64,{$b64}' style='max-width:100%;max-height:132px;object-fit:contain'>
                </td>";
            } else {
                // Celda vacía sin texto
                $fotosHtml .= "<td style='border:1px solid #eee;width:33%;height:140px;background:#fafafa'></td>";
            }
            if ($i === 2 || $i === 5) $fotosHtml .= '</tr><tr>';
        }

        // ── Checklist por secciones ───────────────────────────
        $checklistHtml = '';
        $secNum = 0;
        foreach ($checklist as $secCodigo => $secData) {
            $secNum++;
            $nombre = htmlspecialchars($secData['nombre']);
            $items  = $secData['items'];
            $total  = count($items);
            $mitad  = (int) ceil($total / 2);
            $izq    = array_slice($items, 0, $mitad);
            $der    = array_slice($items, $mitad);

            $checklistHtml .= "
            <tr>
              <td colspan='4' style='background:#d0d8e8;font-weight:bold;font-size:10px;
                  padding:4px 6px;text-align:center;border:1px solid #999'>
                {$secNum}. &nbsp; {$nombre}
              </td>
            </tr>
            <tr style='background:#e8ecf4;font-size:9px'>
              <td style='border:1px solid #bbb;padding:3px 5px;font-weight:bold'>ITEM</td>
              <td style='border:1px solid #bbb;padding:3px;width:50px;font-weight:bold;text-align:center'></td>
              <td style='border:1px solid #bbb;padding:3px 5px;font-weight:bold'>ITEM</td>
              <td style='border:1px solid #bbb;padding:3px;width:50px;font-weight:bold;text-align:center'></td>
            </tr>";

            $maxRows = max(count($izq), count($der));
            for ($r = 0; $r < $maxRows; $r++) {
                $itemIzq = $izq[$r] ?? null;
                $itemDer = $der[$r] ?? null;

                $numIzq  = $itemIzq ? ($r + 1) . '. ' . htmlspecialchars($itemIzq['descripcion']) : '';
                $valIzq  = $itemIzq ? self::valorLabel($itemIzq['valor']) : '';
                $numDer  = $itemDer ? ($mitad + $r + 1) . '. ' . htmlspecialchars($itemDer['descripcion']) : '';
                $valDer  = $itemDer ? self::valorLabel($itemDer['valor']) : '';

                $colorIzq = $itemIzq ? self::valorColor($itemIzq['valor']) : '#000';
                $colorDer = $itemDer ? self::valorColor($itemDer['valor']) : '#000';

                $checklistHtml .= "
                <tr style='font-size:9px'>
                  <td style='border:1px solid #ddd;padding:2px 5px'>{$numIzq}</td>
                  <td style='border:1px solid #ddd;padding:2px;text-align:center;font-weight:bold;color:{$colorIzq}'>{$valIzq}</td>
                  <td style='border:1px solid #ddd;padding:2px 5px'>{$numDer}</td>
                  <td style='border:1px solid #ddd;padding:2px;text-align:center;font-weight:bold;color:{$colorDer}'>{$valDer}</td>
                </tr>";
            }
        }

        return "<!DOCTYPE html>
<html>
<head>
<meta charset='UTF-8'>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: Arial, sans-serif; font-size: 10px; color: #1a1a1a; }
  .page-break { page-break-before: always; }
  table { border-collapse: collapse; width: 100%; }
</style>
</head>
<body>

<!-- ══ PÁGINA 1: Encabezado + Datos + Fotos ══ -->
<table style='width:100%;margin-bottom:6px'>
  <tr>
    <td style='text-align:center;vertical-align:middle'>
      <div style='font-size:16px;font-weight:bold;text-transform:uppercase'>Formato de Inspección</div>
      <div style='font-size:9px;margin-top:3px'>AVBA INSPECTIONS, CERTIFICATIONS AND MAINTENANCE, S.A.S de C.V</div>
    </td>
    <td style='width:70px;text-align:right;vertical-align:top'>
      {$logoTag}
    </td>
  </tr>
</table>

<!-- Datos del equipo -->
<table style='width:100%;border-collapse:collapse;margin-bottom:8px;font-size:9px'>
  <tr>
    <td colspan='4' style='background:#c8d0dc;font-weight:bold;padding:3px 6px;border:1px solid #999'>
      Cliente: <span style='font-weight:normal'>{$cliente}</span>
    </td>
  </tr>
  <tr>
    <td colspan='4' style='padding:3px 6px;border:1px solid #ccc'>
      <span style='font-size:8px;color:#555'>Address</span><br>
      Domicilio: {$domicilio}
    </td>
  </tr>
  <tr>
    <td colspan='4' style='padding:3px 6px;border:1px solid #ccc'>
      <span style='font-size:8px;color:#555'>Machinery</span><br>
      Maquinaria: {$maquinaria} &nbsp;&nbsp; Capacidad: {$capacidad}
    </td>
  </tr>
  <tr>
    <td style='padding:3px 6px;border:1px solid #ccc;width:33%'>
      <span style='font-size:8px;color:#555'>Manufacturer</span><br>
      Marca: {$marca}
    </td>
    <td style='padding:3px 6px;border:1px solid #ccc;width:33%'>
      <span style='font-size:8px;color:#555'>Model</span><br>
      Modelo: {$modelo}
    </td>
    <td colspan='2' style='padding:3px 6px;border:1px solid #ccc'>
      <span style='font-size:8px;color:#555'>Serie Number</span><br>
      No. Serie: {$serie}
    </td>
  </tr>
  <tr>
    <td colspan='2' style='padding:3px 6px;border:1px solid #ccc'>
      <span style='font-size:8px;color:#555'>Id Number</span><br>
      No. Identificación: {$idEquipo}
    </td>
    <td colspan='2' style='padding:3px 6px;border:1px solid #ccc'>
      <span style='font-size:8px;color:#555'>Issue Date</span><br>
      Fecha de inspección: {$fecha}
    </td>
  </tr>
  <tr>
    <td colspan='2' style='padding:3px 6px;border:1px solid #ccc'>
      Folio de control: <strong>{$control}</strong>
    </td>
    <td colspan='2' style='padding:3px 6px;border:1px solid #ccc'>
      Elaborado por: <strong>" . htmlspecialchars($inspector) . "</strong>
    </td>
  </tr>
</table>

<!-- Reporte fotográfico -->
<div style='text-align:center;font-size:13px;font-weight:bold;
    text-decoration:underline;margin-bottom:6px'>
  REPORTE FOTOGRÁFICO
</div>

<table style='width:100%;border-collapse:collapse;margin-bottom:8px'>
  <tr>{$fotosHtml}</tr>
</table>

<!-- ══ PÁGINA 2+: INSPECCIÓN (Checklist) ══ -->
<div class='page-break'></div>

<table style='width:100%;margin-bottom:6px'>
  <tr>
    <td style='text-align:center;vertical-align:middle'>
      <div style='font-size:16px;font-weight:bold;text-transform:uppercase'>Formato de Inspección</div>
      <div style='font-size:9px;margin-top:3px'>AVBA INSPECTIONS, CERTIFICATIONS AND MAINTENANCE, S.A.S de C.V</div>
    </td>
    <td style='width:70px;text-align:right;vertical-align:top'>
      {$logoTag}
    </td>
  </tr>
</table>

<table style='width:100%;border-collapse:collapse;margin-bottom:4px'>
  <tr>
    <td colspan='4' style='background:#c8d0dc;font-weight:bold;font-size:11px;
        padding:5px;text-align:center;border:1px solid #999'>
      INSPECCIÓN
    </td>
  </tr>
  {$checklistHtml}
</table>

</body>
</html>";
    }

    private static function valorLabel(string $val): string {
        return match($val) {
            'C'  => 'C',
            'NC' => 'NC',
            'NA' => 'N/A',
            default => ''
        };
    }

    private static function valorColor(string $val): string {
        return match($val) {
            'C'  => '#2e7d32',
            'NC' => '#c62828',
            'NA' => '#555555',
            default => '#000000'
        };
    }
}
