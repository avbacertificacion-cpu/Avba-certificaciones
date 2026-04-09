<?php
/**
 * AVBA Certificaciones — Módulo de Certificaciones
 *
 * Flujo de documentos:
 *   1. Calidad sube plantilla .docx con etiquetas ${variable} y ${qr_imagen}
 *   2. PHP sustituye etiquetas con PhpWord TemplateProcessor (sin tocar el formato)
 *   3. Para el QR descarga la imagen y la inserta con setImageValue()
 *   4. Si LibreOffice está disponible convierte a PDF; si no, sirve el .docx
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

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

    // ── Imprimir / previsualizar documento ────────────────
    public function imprimirPDF(int $id, string $tipo): array {
        $datos = $this->obtenerDatosEquipo($id);
        if (!$datos) return ['status' => 'error', 'message' => 'Registro no encontrado.'];

        try {
            $tipoPDF  = ($tipo === 'dict') ? 'dictamen' : 'certificado';
            $rutaDocx = $this->resolverDocx($tipoPDF, $datos);

            // Intentar convertir a PDF con LibreOffice; si no, devolver .docx
            $rutaFinal   = $this->docxAPdf($rutaDocx);
            $urlFinal    = UPLOAD_URL . 'certificados/' . basename($rutaFinal);
            $esDocx      = str_ends_with($rutaFinal, '.docx');

            return [
                'status' => 'success',
                'url'    => $urlFinal,
                'docx'   => $esDocx,   // true → descarga Word, false → abre PDF
            ];
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
            $folio = $datos['control'] ?? $id;

            $rutaDocx  = $this->resolverDocx('certificado', $datos, 'envio');
            $rutaCert  = $this->docxAPdf($rutaDocx);
            $nombreCert = basename($rutaCert);
            $urlCert   = UPLOAD_URL . 'certificados/' . $nombreCert;

            $this->enviarCorreo($correo, $datos['cliente'], formatoFolio((string)$folio), 'certificado', [$rutaCert => $nombreCert]);

            $this->pdo->prepare(
                "UPDATE equipos SET certificado_url = ?, estado = 'ENVIADO', fecha_enviado = NOW() WHERE id = ?"
            )->execute([$urlCert, $id]);

            $this->registrarEnvio($datos, $nombreCert, $usuario);
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
            $folio = $datos['control'] ?? $id;

            $rutaDocxCert  = $this->resolverDocx('certificado', $datos, 'envio');
            $rutaCert      = $this->docxAPdf($rutaDocxCert);
            $nombreCert    = basename($rutaCert);
            $urlCert       = UPLOAD_URL . 'certificados/' . $nombreCert;

            $rutaDocxDict  = $this->resolverDocx('dictamen', $datos, 'envio');
            $rutaDict      = $this->docxAPdf($rutaDocxDict);
            $nombreDict    = basename($rutaDict);
            $urlDict       = UPLOAD_URL . 'certificados/' . $nombreDict;

            $this->enviarCorreo($correo, $datos['cliente'], formatoFolio((string)$folio), 'certificado y dictamen', [
                $rutaCert => $nombreCert,
                $rutaDict => $nombreDict,
            ]);

            $this->pdo->prepare(
                "UPDATE equipos
                 SET certificado_url = ?, dictamen_url = ?, estado = 'ENVIADO', fecha_enviado = NOW()
                 WHERE id = ?"
            )->execute([$urlCert, $urlDict, $id]);

            $this->registrarEnvio($datos, "{$nombreCert}, {$nombreDict}", $usuario);
            registrarHistorial($this->pdo, $usuario, $id, 'estado', $datos['estado'], 'ENVIADO');

            return ['status' => 'success', 'urlCert' => $urlCert, 'urlDict' => $urlDict];
        } catch (Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    // ── Generar y enviar solo el dictamen ─────────────────
    public function generarDictEnviar(array $payload, string $usuario): array {
        $id = (int) ($payload['id'] ?? $payload['fila'] ?? 0);
        if (!$id) return ['status' => 'error', 'message' => 'ID de equipo requerido.'];

        $datos = $this->obtenerDatosEquipo($id);
        if (!$datos) return ['status' => 'error', 'message' => 'Registro no encontrado.'];

        $correo = trim($datos['correo'] ?? '');
        if (!$correo) return ['status' => 'error', 'message' => 'El registro no tiene correo registrado.'];

        try {
            $folio = $datos['control'] ?? $id;

            $rutaDocxDict = $this->resolverDocx('dictamen', $datos, 'envio');
            $rutaDict     = $this->docxAPdf($rutaDocxDict);
            $nombreDict   = basename($rutaDict);
            $urlDict      = UPLOAD_URL . 'certificados/' . $nombreDict;

            $this->enviarCorreo($correo, $datos['cliente'], formatoFolio((string)$folio), 'dictamen', [$rutaDict => $nombreDict]);

            $this->pdo->prepare(
                "UPDATE equipos SET dictamen_url = ?, estado = 'ENVIADO', fecha_enviado = NOW() WHERE id = ?"
            )->execute([$urlDict, $id]);

            $this->registrarEnvio($datos, $nombreDict, $usuario);
            registrarHistorial($this->pdo, $usuario, $id, 'estado', $datos['estado'], 'ENVIADO');

            return ['status' => 'success', 'url' => $urlDict];
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
     * Resuelve la plantilla Word correcta y devuelve la ruta al .docx procesado.
     *
     * $tipo:     'certificado' | 'dictamen'
     * $contexto: 'impresion' | 'envio'
     *
     * Lanza RuntimeException si la plantilla no está configurada o el archivo no existe.
     */
    private function resolverDocx(string $tipo, array $datos, string $contexto = 'impresion'): string {
        $colMap = [
            'certificado_impresion' => 'plantilla_cert',
            'dictamen_impresion'    => 'plantilla_dict',
            'certificado_envio'     => 'plantilla_cert_envio',
            'dictamen_envio'        => 'plantilla_dict_envio',
        ];

        $col = $colMap["{$tipo}_{$contexto}"]
             ?? throw new \RuntimeException("Combinación de tipo/contexto no válida: {$tipo}/{$contexto}.");

        $stmt = $this->pdo->prepare("SELECT `{$col}` AS archivo FROM maquinaria_tipos WHERE nombre = ? LIMIT 1");
        $stmt->execute([$datos['maquinaria'] ?? '']);
        $row = $stmt->fetch();

        $maquinaria = $datos['maquinaria'] ?? '(desconocido)';
        $archivo    = $row['archivo'] ?? null;

        if (empty($archivo)) {
            throw new \RuntimeException(
                "No hay plantilla configurada para '{$tipo}' ({$contexto}) " .
                "en el tipo de equipo '{$maquinaria}'. " .
                "Sube el archivo .docx desde el panel de Calidad → Tipos de Equipo."
            );
        }

        $rutaPlantilla = __DIR__ . '/../uploads/plantillas/' . $archivo;
        if (!file_exists($rutaPlantilla)) {
            throw new \RuntimeException(
                "El archivo de plantilla '{$archivo}' no se encontró en el servidor. " .
                "Vuelve a subir la plantilla desde el panel de Calidad."
            );
        }

        return $this->procesarPlantillaDocx($rutaPlantilla, $datos, $tipo);
    }

    /**
     * Aplica sustitución de etiquetas sobre la plantilla .docx sin tocar el formato.
     *
     * Etiquetas de texto: ${folio} ${cliente} ${domicilio} ${maquinaria}
     *   ${marca} ${modelo} ${serie} ${id_equipo} ${capacidad}
     *   ${fecha} ${vigencia} ${qr_codigo} ${anio}
     *
     * Etiqueta de imagen QR: ${qr_imagen}  → inserta la imagen real del QR
     *
     * Para dictamen (fila de tabla a clonar):
     *   ${item_seccion} ${item_descripcion} ${item_valor}
     *
     * Devuelve la ruta al .docx procesado guardado en uploads/certificados/.
     */
    private function procesarPlantillaDocx(string $rutaPlantilla, array $datos, string $tipo): string {
        if (!class_exists('\PhpOffice\PhpWord\TemplateProcessor')) {
            require_once __DIR__ . '/../vendor/autoload.php';
        }

        $processor = new \PhpOffice\PhpWord\TemplateProcessor($rutaPlantilla);

        // Calcular vigencia (1 año desde la inspección)
        $vigencia = '';
        if (!empty($datos['fecha_inspeccion'])) {
            $fv = new DateTime($datos['fecha_inspeccion']);
            $fv->modify('+1 year');
            $vigencia = $fv->format('d/m/Y');
        }

        // ── Etiquetas de texto ────────────────────────────────
        // IMPORTANTE: PhpWord escapa XML internamente, pasar texto plano (sin htmlspecialchars).
        // qr_codigo NO se incluye aquí — se reemplaza como imagen más abajo.
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
            'anio'       => date('Y'),
        ];
        foreach ($vars as $key => $val) {
            try { $processor->setValue($key, (string)$val); } catch (\Exception $e) { /* placeholder no existe */ }
        }

        // ── QR como imagen ────────────────────────────────────
        // Soporta ${qr_codigo} y ${qr_imagen} como placeholders de imagen.
        // Si falla la descarga, cae a texto como último recurso.
        // ── QR como imagen (obligatorio — documento oficial) ─
        $qrTemp   = null;
        $qrCodigo = $datos['qr_codigo'] ?? '';
        if (!$qrCodigo) {
            throw new \RuntimeException(
                'El registro no tiene código QR asignado. ' .
                'Verifica el registro en la base de datos.'
            );
        }

        $qrUrl  = urlQR($qrCodigo);
        $qrTemp = sys_get_temp_dir() . '/avba_qr_' . md5($qrCodigo) . '.png';

        // Hasta 3 intentos con 2 s de espera entre ellos
        $qrContent = false;
        for ($intento = 1; $intento <= 3; $intento++) {
            $ctx       = stream_context_create(['http' => ['timeout' => 10]]);
            $qrContent = @file_get_contents($qrUrl, false, $ctx);
            if ($qrContent !== false) break;
            if ($intento < 3) sleep(2);
        }

        if ($qrContent === false) {
            throw new \RuntimeException(
                'No se pudo descargar la imagen del código QR después de 3 intentos. ' .
                'Verifica la conexión a internet del servidor e intenta de nuevo.'
            );
        }

        file_put_contents($qrTemp, $qrContent);
        $imgParams = ['path' => $qrTemp, 'width' => 100, 'height' => 100, 'ratio' => false];

        // Soporta ${qr_codigo} y ${qr_imagen} como placeholders en la plantilla
        try { $processor->setImageValue('qr_codigo', $imgParams); } catch (\Exception $e) {}
        try { $processor->setImageValue('qr_imagen', $imgParams); } catch (\Exception $e) {}

        // ── Checklist para dictamen (clonar filas de tabla) ───
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
                try {
                    $processor->cloneRow('item_descripcion', count($items));
                    foreach ($items as $i => $item) {
                        $n     = $i + 1;
                        $val   = $item['valor'] ?? '';
                        $label = match ($val) {
                            'C'  => 'CONFORME',
                            'NC' => 'NO CONFORME',
                            'NA' => 'N/A',
                            default => '—',
                        };
                        $processor->setValue("item_seccion#{$n}",     (string)($item['seccion_nombre'] ?? ''));
                        $processor->setValue("item_descripcion#{$n}", (string)($item['descripcion']    ?? ''));
                        $processor->setValue("item_valor#{$n}",       $label);
                    }
                } catch (\Exception $e) {
                    // No hay tabla de checklist en la plantilla
                }
            }
        }

        // ── Guardar .docx procesado ───────────────────────────
        $folio   = $datos['control'] ?? (string)($datos['id'] ?? 'doc');
        $nombre  = strtoupper($tipo) . '_AVBA_' . $folio . '.docx';
        $rutaDir = UPLOAD_DIR . 'certificados/';
        if (!is_dir($rutaDir)) mkdir($rutaDir, 0755, true);

        $destino = $rutaDir . $nombre;
        $processor->saveAs($destino);

        if ($qrTemp && file_exists($qrTemp)) @unlink($qrTemp);

        return $destino;
    }

    /**
     * Convierte un .docx a PDF usando LibreOffice si está disponible.
     * Si no, devuelve la ruta al .docx sin convertir.
     *
     * @return string  Ruta al archivo final (.pdf o .docx)
     */
    private function docxAPdf(string $rutaDocx): string {
        $dir     = dirname($rutaDocx);
        $rutaPdf = $dir . '/' . basename($rutaDocx, '.docx') . '.pdf';

        $bin = $this->encontrarLibreoffice();
        if ($bin) {
            $cmd = escapeshellarg($bin)
                 . ' --headless --convert-to pdf --outdir '
                 . escapeshellarg($dir) . ' '
                 . escapeshellarg($rutaDocx)
                 . ' 2>&1';
            exec($cmd, $output, $code);
            if ($code === 0 && file_exists($rutaPdf)) {
                return $rutaPdf;
            }
        }

        // Sin LibreOffice: devolver el .docx directamente
        return $rutaDocx;
    }

    /**
     * Busca el ejecutable de LibreOffice/soffice en el sistema.
     */
    private function encontrarLibreoffice(): ?string {
        $candidatos = [
            '/usr/bin/libreoffice',
            '/usr/bin/soffice',
            '/usr/local/bin/libreoffice',
            '/opt/libreoffice/program/soffice',
            'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
            'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe',
        ];
        foreach ($candidatos as $ruta) {
            if (@file_exists($ruta)) return $ruta;
        }
        $which = @shell_exec('which libreoffice 2>/dev/null || which soffice 2>/dev/null');
        return ($which && trim($which)) ? trim($which) : null;
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
