<?php
/**
 * AVBA Certificaciones — Módulo de Certificaciones
 *
 * Flujo de documentos:
 *   1. Se obtienen los datos del equipo de la BD
 *   2. Se genera un PDF profesional con dompdf (HTML/CSS → PDF, sin dependencias externas)
 *   3. Para el QR se descarga la imagen y se embebe como base64 en el HTML
 *   4. Si hay plantilla Word configurada, también se genera el .docx para descarga
 */

// Cargar Composer autoload solo si está disponible (no requerido para PDF con coordenadas)
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

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
                    evidencia_url AS evidencia, direccion, capacidad, estado, motivo,
                    qr_codigo, certificado_url AS link, dictamen_url AS dictamen,
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
            $tipoPDF = ($tipo === 'dict') ? 'dictamen' : 'certificado';
            $rutaPdf = $this->resolverPdf($tipoPDF, $datos);
            $urlPdf  = UPLOAD_URL . 'certificados/' . basename($rutaPdf);

            return ['status' => 'success', 'url' => $urlPdf, 'docx' => false];
        } catch (Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    // ── Descargar plantilla Word procesada (.docx) ─────────
    public function descargarDocx(int $id, string $tipo): array {
        $datos = $this->obtenerDatosEquipo($id);
        if (!$datos) return ['status' => 'error', 'message' => 'Registro no encontrado.'];

        try {
            $tipoPDF  = ($tipo === 'dict') ? 'dictamen' : 'certificado';
            $rutaDocx = $this->resolverDocx($tipoPDF, $datos);
            $urlDocx  = UPLOAD_URL . 'certificados/' . basename($rutaDocx);

            return ['status' => 'success', 'url' => $urlDocx, 'docx' => true];
        } catch (Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    // ── Generar PDF del documento ─────────────────────────────
    // Estrategia 1: Plantilla PDF + coordenadas (calidad perfecta, PHP puro)
    // Estrategia 2: Microservicio VPS (LibreOffice + qpdf, plantilla Word)
    // Estrategia 3: Fallback PHPWord → mPDF (PHP puro, plantilla Word)
    public function generarPdfDesdeWord(int $id, string $tipo): array {
        // Intentar primero con plantilla PDF si existe
        $resultado = $this->generarPdfDesdeTemplatePdf($id, $tipo);
        if ($resultado['status'] === 'success') return $resultado;

        // Si no hay plantilla PDF configurada y tampoco vendor/, no podemos continuar
        if (!file_exists(__DIR__ . '/../vendor/autoload.php')) {
            return ['status' => 'error',
                    'message' => 'No hay plantilla PDF configurada para este tipo de equipo. '
                               . 'Súbela desde Calidad → Tipos de Equipo → Plantillas PDF.'];
        }

        // Si no hay plantilla PDF configurada, continuar con Word (requiere vendor/)
        $datos = $this->obtenerDatosEquipo($id);
        if (!$datos) return ['status' => 'error', 'message' => 'Registro no encontrado.'];

        try {
            $tipoPDF     = ($tipo === 'dict') ? 'dictamen' : 'certificado';
            $rutaDocx    = $this->resolverDocx($tipoPDF, $datos);
            $rutaDir     = UPLOAD_DIR . 'certificados/';
            $baseName    = pathinfo($rutaDocx, PATHINFO_FILENAME);
            $rutaPdfProt = $rutaDir . $baseName . '_prot.pdf';
            $ownerPass   = 'AVBA' . strtoupper(bin2hex(random_bytes(8)));

            if (file_exists($rutaPdfProt)) @unlink($rutaPdfProt);

            // ── Estrategia 1: microservicio ───────────────────
            $serviceUrl = defined('CONVERT_SERVICE_URL') ? CONVERT_SERVICE_URL : '';
            $serviceKey = defined('CONVERT_SERVICE_KEY') ? CONVERT_SERVICE_KEY : '';

            if ($serviceUrl) {
                $this->convertirViaServicio($rutaDocx, $rutaPdfProt, $serviceUrl, $serviceKey, $ownerPass);
                if (file_exists($rutaPdfProt)) {
                    return ['status' => 'success', 'url' => UPLOAD_URL . 'certificados/' . basename($rutaPdfProt)];
                }
            }

            // ── Estrategia 2: PHPWord → mPDF (fallback) ──────
            $phpWord = \PhpOffice\PhpWord\IOFactory::load($rutaDocx);
            $tmpHtml = sys_get_temp_dir() . '/avba_' . uniqid() . '.html';
            \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'HTML')->save($tmpHtml);
            $html = (string) file_get_contents($tmpHtml);
            @unlink($tmpHtml);

            $mpdfTmp = sys_get_temp_dir() . '/mpdf_' . uniqid();
            if (!is_dir($mpdfTmp)) mkdir($mpdfTmp, 0755, true);

            $mpdf = new \Mpdf\Mpdf([
                'tempDir'       => $mpdfTmp,
                'margin_left'   => 15, 'margin_right'  => 15,
                'margin_top'    => 16, 'margin_bottom' => 16,
                'default_font'  => 'dejavusans',
            ]);
            $mpdf->SetProtection(['print'], '', $ownerPass);
            $mpdf->WriteHTML($html);
            $mpdf->Output($rutaPdfProt, 'F');

            return ['status' => 'success', 'url' => UPLOAD_URL . 'certificados/' . basename($rutaPdfProt)];

        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Envía el .docx al microservicio VPS y guarda el PDF protegido devuelto.
     */
    private function convertirViaServicio(
        string $rutaDocx,
        string $rutaPdfProt,
        string $url,
        string $apiKey,
        string $ownerPass
    ): void {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('cURL no está disponible en este servidor.');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_POSTFIELDS     => [
                'file'       => new \CURLFile(
                                    $rutaDocx,
                                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                                    basename($rutaDocx)
                                ),
                'api_key'    => $apiKey,
                'owner_pass' => $ownerPass,
            ],
        ]);

        $response    = curl_exec($ch);
        $httpCode    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $curlError   = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new \RuntimeException('Error de conexión con el microservicio: ' . $curlError);
        }
        if ($httpCode !== 200) {
            $err = @json_decode((string)$response, true);
            throw new \RuntimeException('Microservicio error ' . $httpCode . ': ' . ($err['error'] ?? $response));
        }
        if (strpos($contentType, 'application/pdf') === false) {
            throw new \RuntimeException('Respuesta inesperada del microservicio (no es PDF).');
        }

        $rutaDir = dirname($rutaPdfProt);
        if (!is_dir($rutaDir)) mkdir($rutaDir, 0755, true);
        file_put_contents($rutaPdfProt, $response);
    }

    /**
     * Ejecuta un comando de shell usando el método disponible en el servidor.
     * Prueba proc_open → exec → shell_exec → system en orden.
     *
     * @return array{0: string[], 1: int}  [líneas de salida, código de salida]
     */
    private function runCmd(string $cmd): array
    {
        // proc_open: más robusto, normalmente habilitado aunque exec esté deshabilitado
        if (function_exists('proc_open')) {
            $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $proc = @proc_open($cmd, $desc, $pipes);
            if (is_resource($proc)) {
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $code = proc_close($proc);
                $out  = array_filter(explode("\n", trim($stdout . ' ' . $stderr)));
                return [array_values($out), $code];
            }
        }

        if (function_exists('exec')) {
            $out = []; $code = 0;
            exec($cmd . ' 2>&1', $out, $code);
            return [$out, $code];
        }

        if (function_exists('shell_exec')) {
            $raw  = (string) shell_exec($cmd . ' 2>&1');
            return [array_filter(explode("\n", trim($raw))), 0];
        }

        if (function_exists('system')) {
            ob_start();
            system($cmd . ' 2>&1', $code);
            $raw = (string) ob_get_clean();
            return [array_filter(explode("\n", trim($raw))), $code];
        }

        throw new \RuntimeException(
            'El servidor tiene deshabilitadas todas las funciones de ejecución de shell '
            . '(proc_open, exec, shell_exec, system). '
            . 'Habilita al menos proc_open en php.ini para poder convertir documentos.'
        );
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

            $rutaCert   = $this->resolverPdf('certificado', $datos);
            $nombreCert = basename($rutaCert);
            $urlCert    = UPLOAD_URL . 'certificados/' . $nombreCert;

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

            $rutaCert   = $this->resolverPdf('certificado', $datos);
            $nombreCert = basename($rutaCert);
            $urlCert    = UPLOAD_URL . 'certificados/' . $nombreCert;

            $rutaDict   = $this->resolverPdf('dictamen', $datos);
            $nombreDict = basename($rutaDict);
            $urlDict    = UPLOAD_URL . 'certificados/' . $nombreDict;

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

            $rutaDict   = $this->resolverPdf('dictamen', $datos);
            $nombreDict = basename($rutaDict);
            $urlDict    = UPLOAD_URL . 'certificados/' . $nombreDict;

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
             SET estado = 'RETORNADO', certificado_url = NULL, dictamen_url = NULL, fecha_enviado = NULL
             WHERE id = ?"
        )->execute([$id]);

        registrarHistorial($this->pdo, $usuario, $id, 'estado', $datos['estado'], 'RETORNADO');

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

    // ── Generar PDF desde plantilla PDF + coordenadas ────────
    // Carga la plantilla PDF como fondo, superpone los campos
    // del equipo en las coordenadas configuradas y protege el
    // resultado (solo impresión habilitada).
    public function generarPdfDesdeTemplatePdf(int $id, string $tipo): array {
        $datos = $this->obtenerDatosEquipo($id);
        if (!$datos) return ['status' => 'error', 'message' => 'Registro no encontrado.'];

        // Obtener plantilla PDF y campos configurados
        $stmt = $this->pdo->prepare(
            "SELECT plantilla_cert_pdf, plantilla_dict_pdf,
                    cert_pdf_campos, dict_pdf_campos
             FROM maquinaria_tipos WHERE nombre = ? LIMIT 1"
        );
        $stmt->execute([$datos['maquinaria'] ?? '']);
        $row = $stmt->fetch();

        $colPdf    = ($tipo === 'dict') ? 'plantilla_dict_pdf'  : 'plantilla_cert_pdf';
        $colCampos = ($tipo === 'dict') ? 'dict_pdf_campos'      : 'cert_pdf_campos';

        $archivoTpl = $row[$colPdf]    ?? null;
        $camposJson = $row[$colCampos] ?? null;

        if (empty($archivoTpl)) {
            return ['status' => 'error', 'message' =>
                'No hay plantilla PDF configurada para este tipo de equipo. '
                . 'Súbela desde Calidad → Tipos de Equipo.'];
        }

        $rutaTpl = __DIR__ . '/../uploads/plantillas/' . $archivoTpl;
        if (!file_exists($rutaTpl)) {
            return ['status' => 'error', 'message' =>
                "Archivo de plantilla PDF '{$archivoTpl}' no encontrado. "
                . 'Vuelve a subirlo desde Calidad.'];
        }

        $campos = json_decode($camposJson ?: '[]', true) ?: [];

        try {
            // Cargar FPDF + FPDI (no requiere Composer, archivos incluidos en lib/)
            require_once __DIR__ . '/../lib/fpdi_loader.php';

            $rutaDir = UPLOAD_DIR . 'certificados/';
            if (!is_dir($rutaDir)) mkdir($rutaDir, 0755, true);

            $folio   = $datos['control'] ?? (string)($datos['id'] ?? 'doc');
            $sufijo  = strtoupper($tipo === 'dict' ? 'DICT' : 'CERT');
            $rutaPdf = $rutaDir . $sufijo . '_PDF_' . $folio . '.pdf';

            // Detectar dimensiones de la primera página del template
            $fpiDim = new \setasign\Fpdi\Fpdi();
            $fpiDim->setSourceFile($rutaTpl);
            $tplIdx = $fpiDim->importPage(1);
            $size   = $fpiDim->getTemplateSize($tplIdx);
            $w      = $size['width'];
            $h      = $size['height'];
            $orient = ($w > $h) ? 'L' : 'P';
            unset($fpiDim);

            // Crear nuevo PDF con las mismas dimensiones
            $pdf = new \setasign\Fpdi\Fpdi($orient, 'mm', [$w, $h]);
            $pdf->SetAutoPageBreak(false);
            $pdf->SetMargins(0, 0, 0);
            $pdf->SetCreator('AVBA Certificaciones');

            // Número de páginas del template
            $pdf->setSourceFile($rutaTpl);
            $totalPaginas = $pdf->setSourceFile($rutaTpl);

            for ($p = 1; $p <= $totalPaginas; $p++) {
                $tpl = $pdf->importPage($p);
                $sz  = $pdf->getTemplateSize($tpl);
                $pdf->AddPage(($sz['width'] > $sz['height']) ? 'L' : 'P', [$sz['width'], $sz['height']]);
                // Fondo: página del PDF original
                $pdf->useTemplate($tpl, 0, 0, $sz['width'], $sz['height']);

                // Campos que van en esta página
                $valoresResueltos = $this->resolverValoresCampos($datos);

                foreach ($campos as $campo) {
                    $nombreCampo = $campo['campo'] ?? '';
                    $pagCampo    = (int)($campo['pagina'] ?? 1);
                    if (!$nombreCampo || $pagCampo !== $p) continue;

                    $x      = (float)($campo['x']      ?? 0);
                    $y      = (float)($campo['y']      ?? 0);
                    $tamano = (int)  ($campo['tamano'] ?? 10);
                    $negrita= !empty($campo['negrita']) ? 'B' : '';
                    $ancho  = (float)($campo['ancho']  ?? 0);
                    $color  = str_pad($campo['color'] ?? '000000', 6, '0', STR_PAD_LEFT);

                    // Mapeo de nombre de fuente FPDF
                    $fuenteMap = ['Helvetica' => 'Helvetica', 'Times' => 'Times', 'Courier' => 'Courier'];
                    $fuente = $fuenteMap[$campo['fuente'] ?? ''] ?? 'Helvetica';

                    // Color de texto (hex → RGB)
                    [$r, $g, $b] = sscanf($color, '%02x%02x%02x');
                    $pdf->SetTextColor($r ?? 0, $g ?? 0, $b ?? 0);

                    if ($nombreCampo === 'qr_imagen') {
                        $qrCodigo = $valoresResueltos['qr_codigo'] ?? '';
                        if ($qrCodigo) {
                            $alto  = (float)($campo['alto'] ?? $ancho ?: 25);
                            $qrUrl = 'https://quickchart.io/qr?text=' . urlencode($qrCodigo) . '&size=300';
                            $qrTmp = sys_get_temp_dir() . '/avba_qr_' . uniqid() . '.png';
                            $qrData = @file_get_contents($qrUrl);
                            if ($qrData) {
                                file_put_contents($qrTmp, $qrData);
                                $pdf->Image($qrTmp, $x, $y, $ancho ?: 25, $alto);
                                @unlink($qrTmp);
                            }
                        }
                    } elseif ($nombreCampo === 'firma_inspector') {
                        $firmaRuta = $valoresResueltos['firma_ruta'] ?? '';
                        if ($firmaRuta && file_exists($firmaRuta)) {
                            $alto = (float)($campo['alto'] ?? ($ancho ?: 20));
                            $pdf->Image($firmaRuta, $x, $y, $ancho ?: 40, $alto);
                        }
                    } else {
                        $valor = (string)($valoresResueltos[$nombreCampo] ?? '');
                        if ($valor === '') continue;

                        $pdf->SetFont($fuente, $negrita, $tamano);
                        pdfCell($pdf, $x, $y, $ancho, $tamano, fpdfStr($valor));
                    }
                }
            }

            $pdf->Output('F', $rutaPdf);

            return ['status' => 'success', 'url' => UPLOAD_URL . 'certificados/' . basename($rutaPdf)];

        } catch (\Exception $e) {
            $comp = fpdiMsgCompresion($e);
            return ['status' => 'error', 'message' => $comp ?? ('Error generando PDF: ' . $e->getMessage())];
        }
    }

    /** Devuelve todos los valores resueltos para los campos de la plantilla. */
    private function resolverValoresCampos(array $datos): array {
        $fecha   = $datos['fecha_inspeccion'] ?? null;
        $fechaFmt= $fecha ? (new \DateTime($fecha))->format('d/m/Y') : '';
        $vigencia= '';
        if ($fecha) {
            $v = new \DateTime($fecha);
            $v->modify('+1 year');
            $vigencia = $v->format('d/m/Y');
        }

        // Buscar firma del inspector (o cualquier inspector disponible para preview)
        $firmaRuta = '';
        $inspectorUsuario = $datos['inspector'] ?? '';
        try {
            if ($inspectorUsuario) {
                $st = $this->pdo->prepare("SELECT firma_imagen FROM usuarios WHERE usuario = ? LIMIT 1");
                $st->execute([$inspectorUsuario]);
                $row = $st->fetch();
            } else {
                $row = $this->pdo->query(
                    "SELECT firma_imagen FROM usuarios WHERE rol = 'INSPECTOR' AND firma_imagen IS NOT NULL LIMIT 1"
                )->fetch();
            }
            if (!empty($row['firma_imagen'])) {
                $firmaRuta = __DIR__ . '/../' . $row['firma_imagen'];
            }
        } catch (\Exception $e) {}

        return [
            'folio'      => $datos['control']     ? 'AB.' . $datos['control'] . '-' . date('Y') . 'MX' : '',
            'cliente'    => $datos['cliente']     ?? '',
            'domicilio'  => $datos['direccion']   ?? '',
            'maquinaria' => $datos['maquinaria']  ?? '',
            'marca'      => $datos['marca']       ?? '',
            'modelo'     => $datos['modelo']      ?? '',
            'serie'      => $datos['serie']       ?? '',
            'id_equipo'  => $datos['id_equipo']   ?? '',
            'capacidad'  => $datos['capacidad']   ?? '',
            'fecha'      => $fechaFmt,
            'vigencia'   => $vigencia,
            'anio'       => date('Y'),
            'qr_codigo'  => $datos['qr_codigo']   ?? '',
            'firma_ruta' => $firmaRuta,
        ];
    }

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
            if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
                require_once __DIR__ . '/../vendor/autoload.php';
            } else {
                throw new \RuntimeException('PHPWord no disponible (vendor/ no instalado).');
            }
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

    // ══════════════════════════════════════════════════════
    //  GENERACIÓN PDF con dompdf (sin dependencias externas)
    // ══════════════════════════════════════════════════════

    /**
     * Genera el PDF final a partir de los datos del equipo.
     * No requiere plantilla Word — usa una plantilla HTML/CSS mantenida en código.
     */
    private function resolverPdf(string $tipo, array $datos): string {
        $qrB64 = $this->descargarQrB64($datos['qr_codigo'] ?? '');

        if ($tipo === 'dictamen') {
            $items = $this->obtenerChecklistEquipo((int)($datos['id'] ?? 0), $datos['maquinaria'] ?? '');
            $html  = $this->htmlDictamen($datos, $qrB64, $items);
        } else {
            $html = $this->htmlCertificado($datos, $qrB64);
        }

        return $this->htmlAPdf($html, $datos['control'] ?? (string)($datos['id'] ?? 'doc'), $tipo);
    }

    /**
     * Descarga la imagen QR y la devuelve como cadena base64 para embeber en HTML.
     * Realiza hasta 3 intentos; lanza excepción si falla.
     */
    private function descargarQrB64(string $qrCodigo): string {
        if (!$qrCodigo) {
            throw new \RuntimeException(
                'El registro no tiene código QR asignado. Verifica el registro en la base de datos.'
            );
        }
        $qrUrl   = urlQR($qrCodigo);
        $content = false;
        for ($i = 1; $i <= 3; $i++) {
            $ctx     = stream_context_create(['http' => ['timeout' => 10]]);
            $content = @file_get_contents($qrUrl, false, $ctx);
            if ($content !== false) break;
            if ($i < 3) sleep(2);
        }
        if ($content === false) {
            throw new \RuntimeException(
                'No se pudo descargar el código QR después de 3 intentos. ' .
                'Verifica la conexión a internet del servidor e intenta de nuevo.'
            );
        }
        return 'data:image/png;base64,' . base64_encode($content);
    }

    /** Obtiene los ítems del checklist para el dictamen. */
    private function obtenerChecklistEquipo(int $equipoId, string $maquinaria): array {
        $stmt = $this->pdo->prepare(
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
        $stmt->execute([$maquinaria, $equipoId]);
        return $stmt->fetchAll();
    }

    /**
     * Convierte HTML a PDF con dompdf y guarda el archivo.
     * @return string  Ruta absoluta al .pdf generado
     */
    private function htmlAPdf(string $html, string $folio, string $tipo): string {
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);   // imágenes como base64
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isFontSubsettingEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $nombre  = strtoupper($tipo) . '_AVBA_' . $folio . '.pdf';
        $rutaDir = UPLOAD_DIR . 'certificados/';
        if (!is_dir($rutaDir)) mkdir($rutaDir, 0755, true);

        $destino = $rutaDir . $nombre;
        file_put_contents($destino, $dompdf->output());
        return $destino;
    }

    // ── Plantilla HTML: Certificado de Inspección ─────────
    private function htmlCertificado(array $d, string $qrB64): string {
        $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

        $vigencia = '';
        if (!empty($d['fecha_inspeccion'])) {
            $fv = new \DateTime($d['fecha_inspeccion']);
            $fv->modify('+1 year');
            $vigencia = $fv->format('d/m/Y');
        }

        $folio     = $e(formatoFolio($d['control'] ?? ''));
        $cliente   = $e($d['cliente']    ?? '');
        $maq       = $e($d['maquinaria'] ?? '');
        $marca     = $e($d['marca']      ?? '');
        $modelo    = $e($d['modelo']     ?? '');
        $serie     = $e($d['serie']      ?? '');
        $idEquipo  = $e($d['id_equipo']  ?? '');
        $capacidad = $e($d['capacidad']  ?? '');
        $domicilio = $e($d['direccion']  ?? '');
        $fecha     = $e($d['fecha_fmt']  ?? '');
        $vig       = $e($vigencia);
        $anio      = date('Y');

        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
  @page { margin: 12mm 14mm 12mm 14mm; }
  * { box-sizing: border-box; }
  body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 9.5pt; color: #1a1a2e; margin: 0; }
  .header { background: #185FA5; color: white; padding: 10px 18px 10px; }
  .header-table { width: 100%; border-collapse: collapse; }
  .header-table td { padding: 0; vertical-align: middle; }
  .header-title { font-size: 15pt; font-weight: bold; color: white; margin: 0; }
  .header-sub { font-size: 8pt; color: rgba(255,255,255,0.82); margin: 2px 0 0; }
  .header-right { text-align: right; font-size: 8pt; color: rgba(255,255,255,0.75); }
  .title-bar { background: #0C447C; text-align: center; padding: 8px 0; }
  .title-bar h2 { color: white; font-size: 13pt; letter-spacing: 3px; margin: 0; }
  .folio-bar { background: #E6F1FB; text-align: center; padding: 5px 0; border-bottom: 2px solid #185FA5; }
  .folio-bar span { font-size: 11pt; color: #0C447C; font-weight: bold; letter-spacing: 1px; }
  .section-label { background: #185FA5; color: white; font-size: 8pt; font-weight: bold;
                   letter-spacing: 1px; padding: 4px 10px; margin: 10px 0 0; }
  .data-table { width: 100%; border-collapse: collapse; margin: 0; }
  .data-table td { padding: 5px 10px; border-bottom: 1px solid #dde5f0; font-size: 9pt; }
  .data-table .lbl { width: 32%; font-weight: bold; color: #185FA5; background: #f5f8fd; }
  .data-table .val { color: #1a1a2e; }
  .bottom-table { width: 100%; border-collapse: collapse; margin-top: 14px; }
  .bottom-table td { vertical-align: middle; padding: 0 6px; }
  .qr-box { text-align: center; width: 115px; }
  .qr-box img { width: 100px; height: 100px; border: 1px solid #dde5f0; padding: 2px; }
  .qr-box .qr-label { font-size: 7pt; color: #5a6072; margin-top: 3px; }
  .valid-box { text-align: center; padding: 0 10px; }
  .valid-badge { border: 2px solid #185FA5; border-radius: 6px; padding: 6px 14px; display: inline-block; }
  .valid-badge .vb-title { font-size: 8pt; color: #185FA5; font-weight: bold; letter-spacing: 1px; }
  .valid-badge .vb-date  { font-size: 10pt; color: #0C447C; font-weight: bold; margin-top: 2px; }
  .valid-badge .vb-sub   { font-size: 7.5pt; color: #5a6072; margin-top: 1px; }
  .sign-box { text-align: center; width: 160px; }
  .sign-line { border-top: 1px solid #333; width: 140px; margin: 0 auto 4px; }
  .sign-title { font-size: 8pt; color: #1a1a2e; font-weight: bold; }
  .sign-sub   { font-size: 7.5pt; color: #5a6072; }
  .legal { margin-top: 12px; padding: 8px 10px; background: #f5f8fd;
           border-top: 1px solid #dde5f0; font-size: 7.5pt; color: #7a8494; text-align: center; }
  .seal { border: 3px double #185FA5; border-radius: 50%; width: 80px; height: 80px;
          margin: 0 auto; text-align: center; padding-top: 10px; }
  .seal .seal-text { font-size: 6.5pt; color: #185FA5; font-weight: bold; line-height: 1.4; }
</style>
</head>
<body>

<div class="header">
  <table class="header-table"><tr>
    <td>
      <div class="header-title">AVBA Inspections</div>
      <div class="header-sub">Certifications &amp; Maintenance S.A.S. de C.V.</div>
    </td>
    <td class="header-right">
      ISO 9001 | Inspección y Certificación<br>avba.com.mx
    </td>
  </tr></table>
</div>

<div class="title-bar"><h2>CERTIFICADO DE INSPECCIÓN</h2></div>
<div class="folio-bar"><span>Folio: {$folio}</span></div>

<div class="section-label">DATOS DEL CLIENTE</div>
<table class="data-table">
  <tr><td class="lbl">CLIENTE / EMPRESA</td><td class="val">{$cliente}</td></tr>
  <tr><td class="lbl">DOMICILIO</td><td class="val">{$domicilio}</td></tr>
</table>

<div class="section-label">DATOS DEL EQUIPO</div>
<table class="data-table">
  <tr>
    <td class="lbl">TIPO DE EQUIPO</td><td class="val">{$maq}</td>
    <td class="lbl">MARCA</td><td class="val">{$marca}</td>
  </tr>
  <tr>
    <td class="lbl">MODELO</td><td class="val">{$modelo}</td>
    <td class="lbl">No. SERIE</td><td class="val">{$serie}</td>
  </tr>
  <tr>
    <td class="lbl">No. ECONÓMICO</td><td class="val">{$idEquipo}</td>
    <td class="lbl">CAPACIDAD</td><td class="val">{$capacidad}</td>
  </tr>
</table>

<div class="section-label">RESULTADO DE INSPECCIÓN</div>
<table class="data-table">
  <tr>
    <td class="lbl">FECHA DE INSPECCIÓN</td><td class="val">{$fecha}</td>
    <td class="lbl">VIGENCIA</td><td class="val">{$vig}</td>
  </tr>
  <tr>
    <td class="lbl">RESULTADO</td>
    <td class="val" colspan="3"><strong style="color:#2e7d32">&#10003; CONFORME — Equipo apto para operación</strong></td>
  </tr>
</table>

<table class="bottom-table">
  <tr>
    <td class="qr-box">
      <img src="{$qrB64}" alt="QR">
      <div class="qr-label">Escanea para validar</div>
    </td>
    <td class="valid-box">
      <div class="valid-badge">
        <div class="vb-title">VIGENCIA DEL CERTIFICADO</div>
        <div class="vb-date">{$vig}</div>
        <div class="vb-sub">Válido 1 año desde la inspección</div>
      </div>
    </td>
    <td style="text-align:center; padding: 0 10px;">
      <div class="seal">
        <div class="seal-text">AVBA<br>CERT.<br>{$anio}</div>
      </div>
    </td>
    <td class="sign-box">
      <div style="height:40px;"></div>
      <div class="sign-line"></div>
      <div class="sign-title">Inspector Certificado</div>
      <div class="sign-sub">AVBA Inspections</div>
    </td>
  </tr>
</table>

<div class="legal">
  Este certificado acredita que el equipo descrito ha sido inspeccionado conforme a las normas técnicas aplicables
  y cumple con los criterios de seguridad vigentes. Folio: <strong>{$folio}</strong> — {$anio} AVBA Inspections, Certifications and Maintenance S.A.S. de C.V.
</div>

</body>
</html>
HTML;
    }

    // ── Plantilla HTML: Dictamen de Inspección ─────────────
    private function htmlDictamen(array $d, string $qrB64, array $items): string {
        $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

        $vigencia = '';
        if (!empty($d['fecha_inspeccion'])) {
            $fv = new \DateTime($d['fecha_inspeccion']);
            $fv->modify('+1 year');
            $vigencia = $fv->format('d/m/Y');
        }

        $folio     = $e(formatoFolio($d['control'] ?? ''));
        $cliente   = $e($d['cliente']    ?? '');
        $maq       = $e($d['maquinaria'] ?? '');
        $marca     = $e($d['marca']      ?? '');
        $modelo    = $e($d['modelo']     ?? '');
        $serie     = $e($d['serie']      ?? '');
        $idEquipo  = $e($d['id_equipo']  ?? '');
        $capacidad = $e($d['capacidad']  ?? '');
        $domicilio = $e($d['direccion']  ?? '');
        $fecha     = $e($d['fecha_fmt']  ?? '');
        $vig       = $e($vigencia);
        $anio      = date('Y');

        // Construir filas del checklist
        $checkRows  = '';
        $totalC = $totalNC = $totalNA = 0;
        $seccionActual = '';

        foreach ($items as $item) {
            $sec = $e($item['seccion_nombre'] ?? '');
            $desc = $e($item['descripcion'] ?? '');
            $val  = $item['valor'] ?? '';

            if ($val === 'C')  { $totalC++;  $label = '&#10003;'; $color = '#2e7d32'; $bg = '#f1f8f1'; }
            elseif ($val === 'NC') { $totalNC++; $label = '&#10007;'; $color = '#c62828'; $bg = '#fdf3f3'; }
            else               { $totalNA++;  $label = 'N/A';     $color = '#5a6072'; $bg = '#f9fafb'; }

            // Fila de sección si cambia
            if ($sec !== $seccionActual) {
                $checkRows .= "<tr><td colspan=\"3\" style=\"background:#0C447C;color:white;font-weight:bold;"
                            . "font-size:8pt;padding:4px 8px;letter-spacing:1px;\">{$sec}</td></tr>";
                $seccionActual = $sec;
            }

            $checkRows .= "<tr style=\"background:{$bg}\">"
                       . "<td style=\"padding:4px 8px;font-size:8.5pt;border-bottom:1px solid #e8edf4;width:68%\">{$desc}</td>"
                       . "<td style=\"padding:4px 8px;text-align:center;font-size:9pt;font-weight:bold;"
                       . "color:{$color};border-bottom:1px solid #e8edf4;width:32%\">{$label} {$e($val === 'C' ? 'CONFORME' : ($val === 'NC' ? 'NO CONFORME' : 'N/A'))}</td>"
                       . "</tr>\n";
        }

        // Resumen
        $total = count($items);
        $pct   = $total > 0 ? round(($totalC / $total) * 100) : 0;
        $resultColor = $totalNC === 0 ? '#2e7d32' : '#c62828';
        $resultText  = $totalNC === 0 ? '&#10003; APROBADO — Equipo conforme' : '&#10007; OBSERVACIONES — Ver detalle';

        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
  @page { margin: 10mm 12mm 10mm 12mm; }
  * { box-sizing: border-box; }
  body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 9pt; color: #1a1a2e; margin: 0; }
  .header { background: #185FA5; color: white; padding: 9px 16px; }
  .header-table { width: 100%; border-collapse: collapse; }
  .header-table td { padding: 0; vertical-align: middle; }
  .header-title { font-size: 14pt; font-weight: bold; color: white; margin: 0; }
  .header-sub { font-size: 8pt; color: rgba(255,255,255,0.82); }
  .header-right { text-align: right; font-size: 8pt; color: rgba(255,255,255,0.75); }
  .title-bar { background: #0C447C; text-align: center; padding: 7px 0; }
  .title-bar h2 { color: white; font-size: 12pt; letter-spacing: 3px; margin: 0; }
  .folio-bar { background: #E6F1FB; text-align: center; padding: 4px 0; border-bottom: 2px solid #185FA5; }
  .folio-bar span { font-size: 10pt; color: #0C447C; font-weight: bold; }
  .info-table { width: 100%; border-collapse: collapse; margin: 6px 0; }
  .info-table td { padding: 4px 8px; border-bottom: 1px solid #dde5f0; font-size: 8.5pt; }
  .info-table .lbl { width: 20%; font-weight: bold; color: #185FA5; background: #f5f8fd; }
  .section-label { background: #185FA5; color: white; font-size: 8pt; font-weight: bold;
                   letter-spacing: 1px; padding: 4px 10px; margin: 8px 0 0; }
  .checklist-table { width: 100%; border-collapse: collapse; }
  .summary-table { width: 100%; border-collapse: collapse; margin-top: 8px; }
  .summary-table td { padding: 5px 10px; text-align: center; font-size: 8.5pt; font-weight: bold; }
  .bottom-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
  .bottom-table td { vertical-align: middle; padding: 0 6px; }
  .qr-box { text-align: center; width: 105px; }
  .qr-box img { width: 90px; height: 90px; border: 1px solid #dde5f0; }
  .qr-box .qr-label { font-size: 7pt; color: #5a6072; margin-top: 2px; }
  .sign-box { text-align: center; width: 140px; }
  .sign-line { border-top: 1px solid #333; width: 120px; margin: 0 auto 3px; }
  .legal { margin-top: 8px; padding: 6px 10px; background: #f5f8fd;
           border-top: 1px solid #dde5f0; font-size: 7.5pt; color: #7a8494; text-align: center; }
</style>
</head>
<body>

<div class="header">
  <table class="header-table"><tr>
    <td>
      <div class="header-title">AVBA Inspections</div>
      <div class="header-sub">Certifications &amp; Maintenance S.A.S. de C.V. — avba.com.mx</div>
    </td>
    <td class="header-right">ISO 9001 | Dictamen de Inspección<br>{$anio}</td>
  </tr></table>
</div>

<div class="title-bar"><h2>DICTAMEN DE INSPECCIÓN</h2></div>
<div class="folio-bar"><span>Folio: {$folio}</span></div>

<table class="info-table">
  <tr>
    <td class="lbl">CLIENTE</td><td>{$cliente}</td>
    <td class="lbl">DOMICILIO</td><td>{$domicilio}</td>
  </tr>
  <tr>
    <td class="lbl">EQUIPO</td><td>{$maq} — {$marca} {$modelo}</td>
    <td class="lbl">No. SERIE</td><td>{$serie}</td>
  </tr>
  <tr>
    <td class="lbl">No. ECONÓMICO</td><td>{$idEquipo}</td>
    <td class="lbl">CAPACIDAD</td><td>{$capacidad}</td>
  </tr>
  <tr>
    <td class="lbl">FECHA INSP.</td><td>{$fecha}</td>
    <td class="lbl">VIGENCIA</td><td>{$vig}</td>
  </tr>
</table>

<div class="section-label">RESULTADOS DEL CHECKLIST DE INSPECCIÓN</div>
<table class="checklist-table">
  {$checkRows}
</table>

<table class="summary-table">
  <tr>
    <td style="background:#e8f5e9;color:#2e7d32;border:1px solid #c8e6c9">
      CONFORMES: {$totalC}
    </td>
    <td style="background:#ffebee;color:#c62828;border:1px solid #ffcdd2">
      NO CONFORMES: {$totalNC}
    </td>
    <td style="background:#f5f5f5;color:#5a6072;border:1px solid #e0e0e0">
      N/A: {$totalNA}
    </td>
    <td style="background:#e3f2fd;color:#1565c0;border:1px solid #bbdefb">
      TOTAL: {$total} ({$pct}% conforme)
    </td>
    <td style="background:#f9fafb;font-size:9pt;border:1px solid #dde5f0;color:{$resultColor}">
      {$resultText}
    </td>
  </tr>
</table>

<table class="bottom-table">
  <tr>
    <td class="qr-box">
      <img src="{$qrB64}" alt="QR">
      <div class="qr-label">Escanea para validar</div>
    </td>
    <td style="text-align:center;padding:0 12px;font-size:8.5pt;color:#5a6072;">
      El presente dictamen es resultado de la inspección técnica realizada<br>
      conforme a las normas y estándares de seguridad aplicables.<br><br>
      <strong>Folio:</strong> {$folio}<br>
      <strong>Vigencia:</strong> {$vig}
    </td>
    <td class="sign-box">
      <div style="height:35px;"></div>
      <div class="sign-line"></div>
      <div style="font-size:8pt;font-weight:bold;">Inspector Certificado</div>
      <div style="font-size:7.5pt;color:#5a6072;">AVBA Inspections</div>
    </td>
  </tr>
</table>

<div class="legal">
  AVBA Inspections, Certifications and Maintenance S.A.S. de C.V. — Este dictamen certifica los resultados de
  la inspección técnica del equipo referenciado. Folio: <strong>{$folio}</strong> — {$anio}
</div>

</body>
</html>
HTML;
    }

    /**
     * Envía correo con PHPMailer.
     * $adjuntos = ['/ruta/archivo.pdf' => 'nombre.pdf']
     */
    private function enviarCorreo(string $to, string $cliente, string $folio, string $tipoDocs, array $adjuntos): void {
        $mail = new PHPMailer(true);

        configurarMailer($mail, $this->pdo);
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
        $cuerpo = "
      <p style=\"font-size:15px;color:#1a1a2e;margin:0 0 12px\">Estimado(a) cliente <strong>" . htmlspecialchars($cliente) . "</strong>,</p>
      <p style=\"font-size:14px;color:#5a6072;line-height:1.7;margin:0 0 20px\">
        Adjuntamos su <strong>{$tipoDocs}</strong> de inspección con folio
        <strong style=\"color:#185FA5\">{$folio}</strong>,
        el cual acredita que el equipo inspeccionado cumple con los criterios técnicos y de seguridad aplicables.
      </p>
      <div style=\"background:#E6F1FB;border-radius:8px;padding:14px 18px;margin-bottom:20px\">
        <p style=\"font-size:13px;color:#0C447C;margin:0\"><strong>Folio:</strong> {$folio}</p>
        <p style=\"font-size:12px;color:#185FA5;margin:6px 0 0\">Vigencia: 1 año a partir de la fecha de emisión</p>
      </div>";
        return plantillaCorreoHtml($this->pdo, $cuerpo);
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

    // ── Vista previa de plantilla personal con datos de ejemplo ─
    public function previsualizarPdfPersonal(string $tipo, array $campos): array {
        $tiposValidos = ['diploma', 'certificado', 'dc3'];
        if (!in_array($tipo, $tiposValidos, true))
            return ['status' => 'error', 'message' => 'tipo debe ser diploma, certificado o dc3.'];

        // Obtener plantilla desde plantillas_personal
        try {
            $stmt = $this->pdo->prepare(
                "SELECT plantilla_pdf FROM plantillas_personal WHERE tipo = ? LIMIT 1"
            );
            $stmt->execute([$tipo]);
            $row = $stmt->fetch();
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => 'Tabla plantillas_personal no encontrada. Guarda primero la configuración.'];
        }

        $archivoTpl = $row['plantilla_pdf'] ?? null;
        if (empty($archivoTpl))
            return ['status' => 'error', 'message' => 'No hay plantilla PDF configurada para este tipo. Súbela primero.'];

        $rutaTpl = __DIR__ . '/../uploads/plantillas/' . $archivoTpl;
        if (!file_exists($rutaTpl))
            return ['status' => 'error', 'message' => "Plantilla '{$archivoTpl}' no encontrada. Vuelve a subirla."];

        // Datos de muestra para la vista previa (coinciden con el picker)
        $datos = [
            'id'                    => 1,
            'nombre_completo'       => 'Juan Carlos Pérez López',
            'curp'                  => 'PELJ850315HDFRZN04',
            'puesto'                => 'Operador de Grúa',
            'ocupacion_nombre'      => 'Operador de Maquinaria Pesada',
            'empresa_nombre'        => 'HYH CONSTRUCCIONES Y ARRENDAMIENTO DEL GOLFO S.A DE C.V.',
            'empresa_rfc'           => 'HCA850315AB1',
            'empresa_direccion'     => 'CALLE VIALIDAD PERÍMETRO DUPORT, ALTAMIRA, TAMAULIPAS',
            'empresa_representante' => 'Lic. María García Sánchez',
            'curso_nombre'          => 'Operación Segura de Grúas Industriales',
            'area_tematica'         => 'Seguridad e Higiene Industrial',
            'duracion_horas'        => '16',
            'fecha_curso'           => '2026-03-26',
            'capacidad'             => 'Operar grúas industriales de manera segura',
            'capacidad_na'          => 0,
        ];

        try {
            require_once __DIR__ . '/../lib/fpdi_loader.php';

            $rutaDir = UPLOAD_DIR . 'personal/docs/';
            if (!is_dir($rutaDir)) mkdir($rutaDir, 0755, true);

            $rufaPdf = $rutaDir . 'PREVIEW_' . strtoupper($tipo) . '.pdf';

            $fpiDim = new \setasign\Fpdi\Fpdi();
            $fpiDim->setSourceFile($rutaTpl);
            $tplIdx = $fpiDim->importPage(1);
            $size   = $fpiDim->getTemplateSize($tplIdx);
            $orient = ($size['width'] > $size['height']) ? 'L' : 'P';
            unset($fpiDim);

            $pdf = new \setasign\Fpdi\Fpdi($orient, 'mm', [$size['width'], $size['height']]);
            $pdf->SetAutoPageBreak(false);
            $pdf->SetMargins(0, 0, 0);
            $totalPaginas = $pdf->setSourceFile($rutaTpl);

            // Resolver valores de campos personales
            $fechaFmt = date('d/m/Y');
            $valores  = [
                'nombre_completo'       => $datos['nombre_completo'],
                'curp'                  => $datos['curp'],
                'puesto'                => $datos['puesto'],
                'ocupacion'             => $datos['ocupacion_nombre'],
                'empresa_nombre'        => $datos['empresa_nombre'],
                'empresa_rfc'           => $datos['empresa_rfc'],
                'empresa_direccion'     => $datos['empresa_direccion'],
                'empresa_representante' => $datos['empresa_representante'],
                'curso_nombre'          => $datos['curso_nombre'],
                'area_tematica'         => $datos['area_tematica'],
                'duracion_horas'        => $datos['duracion_horas'],
                'fecha_curso'           => $fechaFmt,
                'capacidad'             => $datos['capacidad'],
                'folio'                 => 'PART-00001',
                'anio'                  => date('Y'),
            ];

            for ($pg = 1; $pg <= $totalPaginas; $pg++) {
                $tpl = $pdf->importPage($pg);
                $sz  = $pdf->getTemplateSize($tpl);
                $pdf->AddPage(($sz['width'] > $sz['height']) ? 'L' : 'P', [$sz['width'], $sz['height']]);
                $pdf->useTemplate($tpl, 0, 0, $sz['width'], $sz['height']);

                foreach ($campos as $campo) {
                    $nombreCampo = $campo['campo'] ?? '';
                    $pagCampo    = (int)($campo['pagina'] ?? 1);
                    if (!$nombreCampo || $pagCampo !== $pg) continue;

                    $x       = (float)($campo['x']      ?? 0);
                    $y       = (float)($campo['y']      ?? 0);
                    $tamano  = (int)  ($campo['tamano'] ?? 10);
                    $negrita = !empty($campo['negrita']) ? 'B' : '';
                    $ancho   = (float)($campo['ancho']  ?? 0);
                    $color   = str_pad($campo['color'] ?? '000000', 6, '0', STR_PAD_LEFT);
                    $fuente  = ['Helvetica'=>'Helvetica','Times'=>'Times','Courier'=>'Courier'][$campo['fuente']??''] ?? 'Helvetica';

                    [$r, $g, $b] = sscanf($color, '%02x%02x%02x');
                    $pdf->SetTextColor($r ?? 0, $g ?? 0, $b ?? 0);

                    if ($nombreCampo === 'firma_inspector') {
                        // Personal docs: skip firma silenciosamente
                        continue;
                    }
                    $valor = (string)($valores[$nombreCampo] ?? '');
                    if ($valor === '') continue;

                    $pdf->SetFont($fuente, $negrita, $tamano);
                    pdfCell($pdf, $x, $y, $ancho, $tamano, fpdfStr($valor));
                }
            }

            $pdf->Output('F', $rufaPdf);
            return ['status' => 'success', 'url' => UPLOAD_URL . 'personal/docs/' . basename($rufaPdf)];

        } catch (\Exception $e) {
            $comp = fpdiMsgCompresion($e);
            return ['status' => 'error', 'message' => $comp ?? ('Error generando vista previa: ' . $e->getMessage())];
        }
    }

    // ── Vista previa de plantilla PDF con datos reales ──────
    public function previsualizarPdf(int $tipoId, string $docTipo, array $campos): array {
        $stmt = $this->pdo->prepare(
            "SELECT nombre, plantilla_cert_pdf, plantilla_dict_pdf FROM maquinaria_tipos WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$tipoId]);
        $tipo = $stmt->fetch();
        if (!$tipo) return ['status' => 'error', 'message' => 'Tipo de equipo no encontrado.'];

        $colPdf     = ($docTipo === 'dict') ? 'plantilla_dict_pdf' : 'plantilla_cert_pdf';
        $archivoTpl = $tipo[$colPdf] ?? null;
        if (empty($archivoTpl)) {
            return ['status' => 'error', 'message' => 'No hay plantilla PDF configurada para este tipo. Súbela primero desde Calidad.'];
        }

        $rutaTpl = __DIR__ . '/../uploads/plantillas/' . $archivoTpl;
        if (!file_exists($rutaTpl)) {
            return ['status' => 'error', 'message' => "Plantilla '{$archivoTpl}' no encontrada en el servidor. Vuelve a subirla."];
        }

        // Siempre usar datos de muestra en la previsualización
        $datos = [
            'control'          => '45180-25656',
            'cliente'          => 'HYH CONSTRUCCIONES Y ARRENDAMIENTO DEL GOLFO S.A DE C.V.',
            'direccion'        => 'CALLE VIALIDAD PERÍMETRO DUPORT, FRAC. LOS OLIVOS, ALTAMIRA, TAMAULIPAS, 89603, MÉXICO',
            'maquinaria'       => 'GRUA HIDRAULICA MONTADA SOBRE CAMION',
            'marca'            => 'MANITEX',
            'modelo'           => '1770C',
            'serie'            => '129476',
            'id_equipo'        => 'H&H 01',
            'capacidad'        => '34,000 LBS / 15,422 KG',
            'fecha_inspeccion' => '2026-03-26',
            'qr_codigo'        => '0000000001',
        ];

        try {
            require_once __DIR__ . '/../lib/fpdi_loader.php';

            $rutaDir = UPLOAD_DIR . 'certificados/';
            if (!is_dir($rutaDir)) mkdir($rutaDir, 0755, true);

            $sufijo  = strtoupper($docTipo === 'dict' ? 'DICT' : 'CERT');
            $rutaPdf = $rutaDir . 'PREVIEW_' . $sufijo . '_tipo' . $tipoId . '.pdf';

            $fpiDim = new \setasign\Fpdi\Fpdi();
            $fpiDim->setSourceFile($rutaTpl);
            $tplIdx = $fpiDim->importPage(1);
            $size   = $fpiDim->getTemplateSize($tplIdx);
            $orient = ($size['width'] > $size['height']) ? 'L' : 'P';
            unset($fpiDim);

            $pdf = new \setasign\Fpdi\Fpdi($orient, 'mm', [$size['width'], $size['height']]);
            $pdf->SetAutoPageBreak(false);
            $pdf->SetMargins(0, 0, 0);
            $totalPaginas     = $pdf->setSourceFile($rutaTpl);
            $valoresResueltos = $this->resolverValoresCampos($datos);

            for ($p = 1; $p <= $totalPaginas; $p++) {
                $tpl = $pdf->importPage($p);
                $sz  = $pdf->getTemplateSize($tpl);
                $pdf->AddPage(($sz['width'] > $sz['height']) ? 'L' : 'P', [$sz['width'], $sz['height']]);
                $pdf->useTemplate($tpl, 0, 0, $sz['width'], $sz['height']);

                foreach ($campos as $campo) {
                    $nombreCampo = $campo['campo'] ?? '';
                    $pagCampo    = (int)($campo['pagina'] ?? 1);
                    if (!$nombreCampo || $pagCampo !== $p) continue;

                    $x       = (float)($campo['x']      ?? 0);
                    $y       = (float)($campo['y']      ?? 0);
                    $tamano  = (int)  ($campo['tamano'] ?? 10);
                    $negrita = !empty($campo['negrita']) ? 'B' : '';
                    $ancho   = (float)($campo['ancho']  ?? 0);
                    $color   = str_pad($campo['color'] ?? '000000', 6, '0', STR_PAD_LEFT);
                    $fuente  = ['Helvetica' => 'Helvetica', 'Times' => 'Times', 'Courier' => 'Courier'][$campo['fuente'] ?? ''] ?? 'Helvetica';

                    [$r, $g, $b] = sscanf($color, '%02x%02x%02x');
                    $pdf->SetTextColor($r ?? 0, $g ?? 0, $b ?? 0);

                    if ($nombreCampo === 'qr_imagen') {
                        $qrCodigo = $valoresResueltos['qr_codigo'] ?? '';
                        if ($qrCodigo) {
                            $alto   = (float)($campo['alto'] ?? $ancho ?: 25);
                            $qrUrl  = 'https://quickchart.io/qr?text=' . urlencode($qrCodigo) . '&size=300';
                            $qrTmp  = sys_get_temp_dir() . '/avba_qr_prev_' . uniqid() . '.png';
                            $qrData = @file_get_contents($qrUrl);
                            if ($qrData) {
                                file_put_contents($qrTmp, $qrData);
                                $pdf->Image($qrTmp, $x, $y, $ancho ?: 25, $alto);
                                @unlink($qrTmp);
                            }
                        }
                    } elseif ($nombreCampo === 'firma_inspector') {
                        $firmaRuta = $valoresResueltos['firma_ruta'] ?? '';
                        if ($firmaRuta && file_exists($firmaRuta)) {
                            $alto = (float)($campo['alto'] ?? ($ancho ?: 20));
                            $pdf->Image($firmaRuta, $x, $y, $ancho ?: 40, $alto);
                        }
                    } else {
                        $valor = (string)($valoresResueltos[$nombreCampo] ?? '');
                        if ($valor === '') continue;
                        $pdf->SetFont($fuente, $negrita, $tamano);
                        pdfCell($pdf, $x, $y, $ancho, $tamano, fpdfStr($valor));
                    }
                }
            }

            $pdf->Output('F', $rutaPdf);
            return ['status' => 'success', 'url' => UPLOAD_URL . 'certificados/' . basename($rutaPdf)];

        } catch (\Exception $e) {
            $comp = fpdiMsgCompresion($e);
            return ['status' => 'error', 'message' => $comp ?? ('Error generando vista previa: ' . $e->getMessage())];
        }
    }
}
