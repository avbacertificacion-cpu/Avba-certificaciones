<?php
/**
 * AVBA Certificaciones — Módulo de Certificaciones
 * Migración de Certificaciones.gs → PHP
 *
 * NOTA SOBRE PDFs:
 * Los PDFs se generan con DOMPDF a partir de plantillas HTML en /templates/.
 * Los documentos finales se guardan en /uploads/certificados/.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use Dompdf\Dompdf;
use Dompdf\Options;

class Certificaciones {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    // ── Panel: datos para certificaciones ─────────────────
    public function obtenerDataCertificaciones(): array {
        $stmt = $this->pdo->query(
            "SELECT id, id AS fila,
                    DATE_FORMAT(marca_temporal, '%d/%m/%Y %H:%i') AS marca_temporal,
                    cliente, maquinaria, marca, modelo, serie, id_equipo,
                    DATE_FORMAT(fecha_inspeccion, '%d/%m/%Y') AS fecha,
                    correo, control,
                    DATE_FORMAT(fecha_enviado, '%d/%m/%Y %H:%i') AS enviado,
                    evidencia_url, direccion, capacidad, estado, motivo,
                    qr_codigo, certificado_url, dictamen_url,
                    envio_direccion, coordenadas_envio
             FROM equipos
             WHERE estado IN ('APROBADO CALIDAD', 'ENVIADO')
             ORDER BY marca_temporal DESC"
        );
        $rows = $stmt->fetchAll();

        foreach ($rows as &$r) {
            $r['qr_url'] = $r['qr_codigo'] ? urlQR($r['qr_codigo']) : '';
        }
        unset($r);

        return $rows;
    }

    // ── Imprimir PDF (preview) ─────────────────────────────
    public function imprimirPDF(int $id, string $tipo): array {
        $datos = $this->obtenerDatosEquipo($id);
        if (!$datos) return ['status' => 'error', 'message' => 'Registro no encontrado.'];

        try {
            $tipoPDF   = ($tipo === 'dict') ? 'dictamen' : 'certificado';
            $nombrePDF = strtoupper($tipoPDF) . '_' . ($datos['control'] ?? $id) . '_preview.pdf';
            $rutaPDF   = UPLOAD_DIR . 'certificados/' . $nombrePDF;
            $urlPDF    = UPLOAD_URL . 'certificados/' . $nombrePDF;

            if (!is_dir(UPLOAD_DIR . 'certificados/')) {
                mkdir(UPLOAD_DIR . 'certificados/', 0755, true);
            }

            $html = $this->resolverHTML($tipoPDF, $datos);
            $this->htmlAPDF($html, $rutaPDF);

            return ['status' => 'success', 'url' => $urlPDF];
        } catch (Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    // ── Generar y enviar certificado ───────────────────────
    public function generarCertEnviar(array $payload, string $usuario): array {
        $id = (int) ($payload['id'] ?? $payload['fila'] ?? 0);
        if (!$id) return ['status' => 'error', 'message' => 'ID de equipo requerido.'];

        $datos = $this->obtenerDatosEquipo($id);
        if (!$datos) return ['status' => 'error', 'message' => 'Registro no encontrado.'];

        $correo = trim($datos['correo'] ?? '');
        if (!$correo) return ['status' => 'error', 'message' => 'El registro no tiene correo registrado.'];

        try {
            $folio   = $datos['control'] ?? $id;
            $rutaDir = UPLOAD_DIR . 'certificados/';
            if (!is_dir($rutaDir)) mkdir($rutaDir, 0755, true);

            // Generar PDF del certificado
            $archivoCert = "Certificado_AVBA_{$folio}.pdf";
            $rutaCert    = $rutaDir . $archivoCert;
            $urlCert     = UPLOAD_URL . "certificados/{$archivoCert}";

            $html = $this->resolverHTML('certificado', $datos, 'envio');
            $this->htmlAPDF($html, $rutaCert);

            // Enviar correo
            $this->enviarCorreo($correo, $datos['cliente'], $folio, 'certificado', [$rutaCert => $archivoCert]);

            // Actualizar BD
            $this->pdo->prepare(
                "UPDATE equipos SET certificado_url = ?, estado = 'ENVIADO', fecha_enviado = NOW() WHERE id = ?"
            )->execute([$urlCert, $id]);

            // Registrar en histórico
            $this->registrarEnvio($datos, $archivoCert, $usuario);
            registrarHistorial($this->pdo, $usuario, $id, 'estado', $datos['estado'], 'ENVIADO');

            return ['status' => 'success', 'url' => $urlCert];
        } catch (Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    // ── Generar y enviar certificado + dictamen ────────────
    public function generarTodoEnviar(array $payload, string $usuario): array {
        $id = (int) ($payload['id'] ?? $payload['fila'] ?? 0);
        if (!$id) return ['status' => 'error', 'message' => 'ID de equipo requerido.'];

        $datos = $this->obtenerDatosEquipo($id);
        if (!$datos) return ['status' => 'error', 'message' => 'Registro no encontrado.'];

        $correo = trim($datos['correo'] ?? '');
        if (!$correo) return ['status' => 'error', 'message' => 'El registro no tiene correo registrado.'];

        try {
            $folio   = $datos['control'] ?? $id;
            $rutaDir = UPLOAD_DIR . 'certificados/';
            if (!is_dir($rutaDir)) mkdir($rutaDir, 0755, true);

            // Certificado
            $archivoCert = "Certificado_AVBA_{$folio}.pdf";
            $rutaCert    = $rutaDir . $archivoCert;
            $urlCert     = UPLOAD_URL . "certificados/{$archivoCert}";
            $html = $this->resolverHTML('certificado', $datos, 'envio');
            $this->htmlAPDF($html, $rutaCert);

            // Dictamen
            $archivoDict = "Dictamen_AVBA_{$folio}.pdf";
            $rutaDict    = $rutaDir . $archivoDict;
            $urlDict     = UPLOAD_URL . "certificados/{$archivoDict}";
            $html = $this->resolverHTML('dictamen', $datos, 'envio');
            $this->htmlAPDF($html, $rutaDict);

            // Enviar correo con ambos adjuntos
            $this->enviarCorreo($correo, $datos['cliente'], $folio, 'certificado y dictamen', [
                $rutaCert => $archivoCert,
                $rutaDict => $archivoDict,
            ]);

            // Actualizar BD
            $this->pdo->prepare(
                "UPDATE equipos
                 SET certificado_url = ?, dictamen_url = ?, estado = 'ENVIADO', fecha_enviado = NOW()
                 WHERE id = ?"
            )->execute([$urlCert, $urlDict, $id]);

            $this->registrarEnvio($datos, "{$archivoCert}, {$archivoDict}", $usuario);
            registrarHistorial($this->pdo, $usuario, $id, 'estado', $datos['estado'], 'ENVIADO');

            return ['status' => 'success', 'urlCert' => $urlCert, 'urlDict' => $urlDict];
        } catch (Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    // ── Rechazar a calidad ─────────────────────────────────
    public function rechazarACertificacion(array $payload, string $usuario): array {
        $id = (int) ($payload['id'] ?? $payload['fila'] ?? 0);
        if (!$id) return ['status' => 'error', 'message' => 'ID de equipo requerido.'];

        $datos = $this->obtenerDatosEquipo($id);
        if (!$datos) return ['status' => 'error', 'message' => 'Registro no encontrado.'];

        $this->pdo->prepare(
            "UPDATE equipos
             SET estado = 'PENDIENTE', certificado_url = NULL, dictamen_url = NULL, fecha_enviado = NULL
             WHERE id = ?"
        )->execute([$id]);

        registrarHistorial($this->pdo, $usuario, $id, 'estado', $datos['estado'], 'PENDIENTE');

        return ['status' => 'success'];
    }

    // ── Guardar dirección de envío ─────────────────────────
    public function guardarEnvioCert(array $payload): array {
        $id = (int) ($payload['id'] ?? $payload['fila'] ?? 0);
        if (!$id) return ['status' => 'error', 'message' => 'ID de equipo requerido.'];

        $sets   = [];
        $params = [];

        if (isset($payload['envio'])) {
            $sets[]  = 'envio_direccion = ?';
            $params[] = $payload['envio'];
        }
        if (isset($payload['coordenadas'])) {
            $sets[]  = 'coordenadas_envio = ?';
            $params[] = $payload['coordenadas'];
        }

        if (empty($sets)) return ['status' => 'success'];

        $params[] = $id;
        $this->pdo->prepare("UPDATE equipos SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);

        return ['status' => 'success'];
    }

    // ══════════════════════════════════════════════════════
    //  MÉTODOS PRIVADOS
    // ══════════════════════════════════════════════════════

    private function obtenerDatosEquipo(int $id): ?array {
        $stmt = $this->pdo->prepare("SELECT * FROM equipos WHERE id = ?");
        $stmt->execute([$id]);
        $datos = $stmt->fetch();
        if (!$datos) return null;

        // Formatear fecha
        if ($datos['fecha_inspeccion']) {
            $datos['fecha_fmt'] = (new DateTime($datos['fecha_inspeccion']))->format('d/m/Y');
        } else {
            $datos['fecha_fmt'] = '';
        }
        $datos['qr_url'] = $datos['qr_codigo'] ? urlQR($datos['qr_codigo']) : '';

        return $datos;
    }

    /**
     * Decide si usar plantilla Word o la plantilla HTML embebida.
     *
     * $tipo:    'certificado' | 'dictamen'
     * $contexto: 'impresion' (default) | 'envio'
     *
     * Prioridad de plantilla:
     *   1. Plantilla específica para el contexto (cert_envio / dict_envio)
     *   2. Plantilla genérica del tipo (cert / dict)
     *   3. Plantilla HTML embebida (fallback)
     */
    private function resolverHTML(string $tipo, array $datos, string $contexto = 'impresion'): string {
        $esDict  = ($tipo === 'dictamen');
        $colPrim = $esDict
            ? ($contexto === 'envio' ? 'plantilla_dict_envio' : 'plantilla_dict')
            : ($contexto === 'envio' ? 'plantilla_cert_envio' : 'plantilla_cert');
        $colFall = $esDict ? 'plantilla_dict' : 'plantilla_cert';

        $stmt = $this->pdo->prepare(
            "SELECT plantilla_cert, plantilla_dict, plantilla_cert_envio, plantilla_dict_envio
             FROM maquinaria_tipos WHERE nombre = ? LIMIT 1"
        );
        $stmt->execute([$datos['maquinaria'] ?? '']);
        $row = $stmt->fetch();

        // Intentar plantilla específica de contexto, luego la genérica
        foreach ([$colPrim, $colFall] as $col) {
            $archivo = $row[$col] ?? null;
            if (!empty($archivo)) {
                $rutaDocx = __DIR__ . '/../uploads/plantillas/' . $archivo;
                if (file_exists($rutaDocx)) {
                    return $this->generarDesdePlantillaWord($rutaDocx, $datos, $tipo);
                }
            }
        }

        // Fallback: plantilla HTML embebida
        return $this->renderizarPlantilla($tipo, $datos);
    }

    /**
     * Procesa una plantilla .docx con PhpWord TemplateProcessor,
     * reemplaza etiquetas ${variable} y convierte a HTML para DOMPDF.
     *
     * Etiquetas soportadas: ${folio}, ${cliente}, ${domicilio}, ${maquinaria},
     * ${marca}, ${modelo}, ${serie}, ${id_equipo}, ${capacidad}, ${fecha},
     * ${vigencia}, ${qr_codigo}, ${anio}
     *
     * Para dictamen (fila de tabla a clonar):
     * ${item_seccion}, ${item_descripcion}, ${item_valor}
     */
    private function generarDesdePlantillaWord(string $rutaDocx, array $datos, string $tipo): string {
        if (!class_exists('\PhpOffice\PhpWord\TemplateProcessor')) {
            require_once __DIR__ . '/../vendor/autoload.php';
        }

        $processor = new \PhpOffice\PhpWord\TemplateProcessor($rutaDocx);

        // Calcular vigencia
        $vigencia = '';
        if (!empty($datos['fecha_inspeccion'])) {
            $fv = new DateTime($datos['fecha_inspeccion']);
            $fv->modify('+1 year');
            $vigencia = $fv->format('d/m/Y');
        }

        // Variables simples
        $vars = [
            'folio'      => formatoFolio($datos['control']   ?? ''),
            'cliente'    => $datos['cliente']    ?? '',
            'domicilio'  => $datos['direccion']  ?? '',
            'maquinaria' => $datos['maquinaria'] ?? '',
            'marca'      => $datos['marca']      ?? '',
            'modelo'     => $datos['modelo']     ?? '',
            'serie'      => $datos['serie']      ?? '',
            'id_equipo'  => $datos['id_equipo']  ?? '',
            'capacidad'  => $datos['capacidad']  ?? '',
            'fecha'      => $datos['fecha_fmt']  ?? '',
            'vigencia'   => $vigencia,
            'qr_codigo'  => $datos['qr_codigo']  ?? '',
            'anio'       => date('Y'),
        ];
        foreach ($vars as $key => $val) {
            $processor->setValue($key, htmlspecialchars((string)$val));
        }

        // Para dictamen: clonar filas de checklist
        if ($tipo === 'dictamen') {
            $stmtItems = $this->pdo->prepare(
                "SELECT ci.descripcion, ic.valor,
                        COALESCE(cs.nombre, ci.seccion) AS seccion_nombre
                 FROM inspeccion_checklist ic
                 INNER JOIN checklist_items ci
                        ON ci.tag = ic.tag
                       AND ci.maquinaria_tipo_id = (
                             SELECT id FROM maquinaria_tipos WHERE nombre = ? LIMIT 1
                           )
                 LEFT JOIN checklist_secciones cs
                        ON cs.maquinaria_tipo_id = ci.maquinaria_tipo_id
                       AND cs.codigo = ci.seccion
                 WHERE ic.equipo_id = ?
                 ORDER BY ci.seccion, ci.orden"
            );
            $stmtItems->execute([$datos['maquinaria'] ?? '', $datos['id']]);
            $items = $stmtItems->fetchAll();

            if (!empty($items)) {
                $processor->cloneRow('item_descripcion', count($items));
                foreach ($items as $i => $item) {
                    $n   = $i + 1;
                    $val = $item['valor'] ?? '';
                    $label = match ($val) {
                        'C'  => 'CONFORME',
                        'NC' => 'NO CONFORME',
                        'NA' => 'N/A',
                        default => '—',
                    };
                    $processor->setValue("item_seccion#{$n}",      htmlspecialchars($item['seccion_nombre'] ?? ''));
                    $processor->setValue("item_descripcion#{$n}",  htmlspecialchars($item['descripcion']    ?? ''));
                    $processor->setValue("item_valor#{$n}",        $label);
                }
            }
        }

        // Guardar .docx procesado en temporal
        $tempDocx = sys_get_temp_dir() . '/avba_' . uniqid() . '.docx';
        $processor->saveAs($tempDocx);

        // Convertir .docx → HTML → usar DOMPDF
        $phpWord  = \PhpOffice\PhpWord\IOFactory::load($tempDocx);
        $tempHtml = sys_get_temp_dir() . '/avba_' . uniqid() . '.html';
        \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'HTML')->save($tempHtml);
        $html = file_get_contents($tempHtml);

        @unlink($tempDocx);
        @unlink($tempHtml);

        return $html ?: $this->renderizarPlantilla($tipo, $datos);
    }

    /**
     * Renderiza la plantilla HTML del certificado o dictamen con los datos del equipo.
     */
    private function renderizarPlantilla(string $tipo, array $datos): string {
        $folio      = htmlspecialchars(formatoFolio($datos['control'] ?? ''));
        $cliente    = htmlspecialchars($datos['cliente']      ?? '');
        $domicilio  = htmlspecialchars($datos['direccion']    ?? '');
        $maquinaria = htmlspecialchars($datos['maquinaria']   ?? '');
        $marca      = htmlspecialchars($datos['marca']        ?? '');
        $modelo     = htmlspecialchars($datos['modelo']       ?? '');
        $serie      = htmlspecialchars($datos['serie']        ?? '');
        $idEquipo   = htmlspecialchars($datos['id_equipo']    ?? '');
        $fecha      = htmlspecialchars($datos['fecha_fmt']    ?? '');
        $capacidad  = htmlspecialchars($datos['capacidad']    ?? '');
        $qrUrl      = $datos['qr_url'] ?? '';

        // Cargar el checklist de este equipo para el dictamen
        $checklistHtml = '';
        if ($tipo === 'dictamen') {
            $stmt = $this->pdo->prepare(
                "SELECT ci.seccion, ci.descripcion, ic.valor
                 FROM inspeccion_checklist ic
                 INNER JOIN checklist_items ci ON ci.tag = ic.tag
                     AND ci.maquinaria_tipo_id = (SELECT id FROM maquinaria_tipos WHERE nombre = ? LIMIT 1)
                 WHERE ic.equipo_id = ?
                 ORDER BY ci.orden"
            );
            $stmt->execute([$datos['maquinaria'] ?? '', $datos['id']]);
            $items = $stmt->fetchAll();

            $seccionActual = '';
            foreach ($items as $item) {
                if ($item['seccion'] !== $seccionActual) {
                    if ($seccionActual !== '') $checklistHtml .= '</table>';
                    $seccionActual = $item['seccion'];
                    $checklistHtml .= "<h4 style='margin:8px 0 4px;color:#185FA5'>Sección {$seccionActual}</h4>";
                    $checklistHtml .= '<table style="width:100%;border-collapse:collapse;font-size:10px">';
                    $checklistHtml .= '<tr style="background:#E6F1FB"><th style="text-align:left;padding:4px">Elemento</th><th style="padding:4px">Resultado</th></tr>';
                }
                $val   = $item['valor'] ?? '—';
                $color = ($val === 'C') ? '#2e7d32' : (($val === 'NC') ? '#c62828' : '#555');
                $label = ($val === 'C') ? 'CONFORME' : (($val === 'NC') ? 'NO CONFORME' : ($val === 'NA' ? 'N/A' : '—'));
                $checklistHtml .= "<tr style='border-bottom:1px solid #eee'>
                    <td style='padding:3px 4px'>" . htmlspecialchars($item['descripcion']) . "</td>
                    <td style='text-align:center;color:{$color};font-weight:bold;padding:3px'>{$label}</td>
                </tr>";
            }
            if ($seccionActual !== '') $checklistHtml .= '</table>';
        }

        $qrImg = $qrUrl
            ? "<img src='{$qrUrl}' style='width:90px;height:90px' alt='QR'>"
            : '';

        $titulo = ($tipo === 'dictamen') ? 'DICTAMEN DE INSPECCIÓN' : 'CERTIFICADO DE INSPECCIÓN';

        return "<!DOCTYPE html>
<html lang='es'>
<head><meta charset='UTF-8'>
<style>
  body { font-family: Arial, sans-serif; font-size: 11px; color: #1a1a2e; margin: 20px; }
  .header { background: #185FA5; color: white; padding: 16px; text-align: center; border-radius: 6px; }
  .header h1 { margin: 0; font-size: 18px; }
  .header p  { margin: 4px 0 0; font-size: 12px; opacity: 0.8; }
  .folio-box { background: #E6F1FB; border-left: 4px solid #185FA5; padding: 10px 14px; margin: 14px 0; }
  table.datos { width: 100%; border-collapse: collapse; margin: 10px 0; }
  table.datos td { padding: 5px 8px; border: 1px solid #ddd; }
  table.datos td:first-child { font-weight: bold; width: 35%; background: #f5f9ff; }
  .qr-block { text-align: center; margin: 12px 0; }
  .footer { margin-top: 20px; border-top: 1px solid #ddd; padding-top: 8px; text-align: center; font-size: 10px; color: #888; }
  .firma-box { border-top: 1px solid #333; width: 200px; margin: 30px auto 0; text-align: center; padding-top: 4px; font-size: 10px; }
</style>
</head>
<body>
<div class='header'>
  <h1>AVBA Inspections</h1>
  <p>Certifications and Maintenance</p>
</div>

<div class='folio-box'>
  <strong style='font-size:13px;color:#0C447C'>{$titulo}</strong><br>
  <span style='font-size:12px'>Folio: <strong style='color:#185FA5'>{$folio}</strong></span>
</div>

<table class='datos'>
  <tr><td>Cliente</td><td>{$cliente}</td></tr>
  <tr><td>Domicilio</td><td>{$domicilio}</td></tr>
  <tr><td>Maquinaria</td><td>{$maquinaria}</td></tr>
  <tr><td>Marca</td><td>{$marca}</td></tr>
  <tr><td>Modelo</td><td>{$modelo}</td></tr>
  <tr><td>Serie</td><td>{$serie}</td></tr>
  <tr><td>ID Equipo</td><td>{$idEquipo}</td></tr>
  <tr><td>Capacidad</td><td>{$capacidad}</td></tr>
  <tr><td>Fecha de inspección</td><td>{$fecha}</td></tr>
  <tr><td>Folio de control</td><td>{$folio}</td></tr>
</table>

{$checklistHtml}

<div class='qr-block'>
  {$qrImg}
  <br><small style='color:#555'>Código de verificación: {$datos['qr_codigo']}</small>
</div>

<div class='firma-box'>
  Firma del Inspector<br>AVBA Inspections
</div>

<div class='footer'>
  AVBA Inspections, Certifications and Maintenance S.A.S. de C.V. &nbsp;|&nbsp;
  Vigencia: 1 año a partir de la fecha de emisión &nbsp;|&nbsp;
  avba.com.mx
</div>
</body></html>";
    }

    /**
     * Convierte HTML a PDF usando Dompdf y lo guarda en $rutaDestino.
     */
    private function htmlAPDF(string $html, string $rutaDestino): void {
        $options = new Options();
        $options->set('defaultFont', 'Arial');
        $options->set('isRemoteEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('Letter', 'portrait');
        $dompdf->render();

        file_put_contents($rutaDestino, $dompdf->output());
    }

    /**
     * Envía correo con PHPMailer.
     * $adjuntos = ['/ruta/archivo.pdf' => 'nombre.pdf']
     */
    private function enviarCorreo(string $to, string $cliente, string $folio, string $tipoDocs, array $adjuntos): void {
        $mail = new PHPMailer(true);

        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USER;
        $mail->Password   = MAIL_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = MAIL_PORT;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($to);
        $mail->Subject = "Certificado de Inspección AVBA — Folio {$folio}";
        $mail->isHTML(true);
        $mail->Body    = $this->plantillaCorreo($cliente, $folio, $tipoDocs);

        foreach ($adjuntos as $ruta => $nombre) {
            $mail->addAttachment($ruta, $nombre);
        }

        $mail->send();
    }

    private function plantillaCorreo(string $cliente, string $folio, string $tipoDocs): string {
        return "<!DOCTYPE html>
<html>
<body style=\"font-family:'Segoe UI',sans-serif;background:#f4f7fb;margin:0;padding:20px\">
<div style=\"max-width:560px;margin:auto;background:white;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.08)\">
  <div style=\"background:#185FA5;padding:24px;text-align:center\">
    <h1 style=\"color:white;font-size:20px;margin:0\">AVBA Inspections</h1>
    <p style=\"color:rgba(255,255,255,0.75);font-size:13px;margin:6px 0 0\">Certificaciones y Mantenimiento</p>
  </div>
  <div style=\"padding:28px 32px\">
    <p style=\"font-size:15px;color:#1a1a2e;margin:0 0 12px\">Estimado(a) cliente <strong>{$cliente}</strong>,</p>
    <p style=\"font-size:14px;color:#5a6072;line-height:1.7;margin:0 0 20px\">
      Adjuntamos su <strong>{$tipoDocs}</strong> de inspección con folio
      <strong style=\"color:#185FA5\">{$folio}</strong>,
      el cual acredita que el equipo inspeccionado cumple con los criterios técnicos y de seguridad aplicables.
    </p>
    <div style=\"background:#E6F1FB;border-radius:8px;padding:14px 18px;margin-bottom:20px\">
      <p style=\"font-size:13px;color:#0C447C;margin:0\"><strong>Folio:</strong> {$folio}</p>
      <p style=\"font-size:12px;color:#185FA5;margin:6px 0 0\">Vigencia: 1 año a partir de la fecha de emisión</p>
    </div>
  </div>
  <div style=\"background:#f4f7fb;padding:16px 32px;border-top:1px solid #dfe5ef;text-align:center\">
    <p style=\"font-size:12px;color:#9299a8;margin:0\">
      AVBA Inspections, Certifications and Maintenance S.A.S. de C.V.<br>
      <a href=\"https://avba.com.mx\" style=\"color:#185FA5\">avba.com.mx</a>
    </p>
  </div>
</div>
</body>
</html>";
    }

    private function registrarEnvio(array $datos, string $archivo, string $usuario): void {
        $this->pdo->prepare(
            "INSERT INTO historico_envios (cliente, control, correo, archivo, usuario, equipo_id)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([
            $datos['cliente']  ?? null,
            $datos['control']  ?? null,
            $datos['correo']   ?? null,
            $archivo,
            $usuario,
            $datos['id']       ?? null,
        ]);
    }
}
