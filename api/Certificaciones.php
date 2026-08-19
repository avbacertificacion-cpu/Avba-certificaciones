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
        $this->ensureColumnasManual();
    }

    /** Columnas para el reemplazo manual del PDF (certificado/dictamen). */
    private function ensureColumnasManual(): void {
        foreach (['cert_manual_url', 'dict_manual_url'] as $col) {
            try {
                $this->pdo->exec("ALTER TABLE equipos ADD COLUMN IF NOT EXISTS {$col} VARCHAR(500) NULL");
            } catch (\Throwable $e) { /* columna ya existe o motor sin IF NOT EXISTS */ }
        }
    }

    /**
     * Reemplaza manualmente el PDF del certificado o del dictamen con un
     * archivo subido por Certificaciones. El archivo pasa a ser el que ve el
     * cliente y el que se adjunta al enviar. $tipo: 'cert' | 'dict'.
     */
    public function subirDocumentoManual(array $payload, array $file): array {
        $id   = (int)($payload['id'] ?? $payload['fila'] ?? 0);
        $tipo = ($payload['tipo'] ?? '') === 'dict' ? 'dict' : 'cert';
        if (!$id) return ['status' => 'error', 'message' => 'ID de equipo requerido.'];

        $datos = $this->obtenerDatosEquipo($id);
        if (!$datos) return ['status' => 'error', 'message' => 'Registro no encontrado.'];

        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return ['status' => 'error', 'message' => 'Selecciona un archivo PDF válido.'];
        }
        $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        if ($ext !== 'pdf') return ['status' => 'error', 'message' => 'El documento debe ser un PDF.'];
        if (($file['size'] ?? 0) > 20 * 1024 * 1024) {
            return ['status' => 'error', 'message' => 'El PDF no debe superar 20 MB.'];
        }

        $folio  = $datos['control'] ?? (string)$id;
        $sufijo = $tipo === 'dict' ? 'DICT' : 'CERT';
        $dir    = UPLOAD_DIR . 'certificados/';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        // Nombre estable por folio para el archivo manual
        $nombre  = $sufijo . '_MANUAL_' . preg_replace('/[^A-Za-z0-9._-]/', '', (string)$folio) . '.pdf';
        $destino = $dir . $nombre;
        if (!move_uploaded_file($file['tmp_name'], $destino)) {
            return ['status' => 'error', 'message' => 'No se pudo guardar el PDF.'];
        }

        $rel     = 'uploads/certificados/' . $nombre;
        $urlAbs  = UPLOAD_URL . 'certificados/' . $nombre;
        $colMan  = $tipo === 'dict' ? 'dict_manual_url' : 'cert_manual_url';
        $colPub  = $tipo === 'dict' ? 'dictamen_url'    : 'certificado_url';

        // Guardar el override y actualizar la URL pública (la que ve el cliente).
        // Si el registro aún no se ha enviado, sólo se guarda el override.
        if ($datos['estado'] === 'ENVIADO') {
            $this->pdo->prepare("UPDATE equipos SET {$colMan} = ?, {$colPub} = ? WHERE id = ?")
                ->execute([$rel, $urlAbs, $id]);
        } else {
            $this->pdo->prepare("UPDATE equipos SET {$colMan} = ? WHERE id = ?")
                ->execute([$rel, $id]);
        }

        return [
            'status'  => 'success',
            'message' => ($tipo === 'dict' ? 'Dictamen' : 'Certificado') . ' reemplazado correctamente.',
            'url'     => $urlAbs,
        ];
    }

    /** Devuelve la ruta local del PDF manual si existe para ese tipo, o ''. */
    private function pdfManual(int $id, string $tipo): string {
        $col = $tipo === 'dict' ? 'dict_manual_url' : 'cert_manual_url';
        try {
            $stmt = $this->pdo->prepare("SELECT {$col} AS rel FROM equipos WHERE id = ?");
            $stmt->execute([$id]);
            $rel = (string)($stmt->fetchColumn() ?: '');
        } catch (\Throwable $e) { return ''; }
        if ($rel === '') return '';
        $abs = __DIR__ . '/../' . ltrim($rel, '/');
        return is_file($abs) ? $abs : '';
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
    public function imprimirPDF(int $id, string $tipo, bool $sinSellos = false): array {
        $datos = $this->obtenerDatosEquipo($id);
        if (!$datos) return ['status' => 'error', 'message' => 'Registro no encontrado.'];

        try {
            $tipoPDF = ($tipo === 'dict') ? 'dictamen' : 'certificado';
            $rutaPdf = $this->resolverPdf($tipoPDF, $datos, $sinSellos);
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
    // Estrategia 2: Plantilla HTML (certificado o dictamen) con mPDF
    // Estrategia 3: Microservicio VPS (LibreOffice + qpdf, plantilla Word)
    // Estrategia 4: Fallback PHPWord → mPDF (PHP puro, plantilla Word)
    public function generarPdfDesdeWord(int $id, string $tipo): array {
        // Si hay un PDF reemplazado manualmente, ese manda
        $manual = $this->pdfManual($id, $tipo === 'dict' ? 'dict' : 'cert');
        if ($manual !== '') {
            return ['status' => 'success', 'url' => UPLOAD_URL . 'certificados/' . basename($manual)];
        }
        // Intentar primero con plantilla PDF si existe
        $resultado = $this->generarPdfDesdeTemplatePdf($id, $tipo);
        if ($resultado['status'] === 'success') return $resultado;

        $datos = $this->obtenerDatosEquipo($id);
        if (!$datos) return ['status' => 'error', 'message' => 'Registro no encontrado.'];

        // Para dictámenes: usar plantilla HTML configurada o htmlDictamen() — NO Word
        if ($tipo === 'dict') {
            try {
                $rutaPdf = $this->resolverPdf('dictamen', $datos);
                return ['status' => 'success', 'url' => UPLOAD_URL . 'certificados/' . basename($rutaPdf)];
            } catch (\Exception $e) {
                return ['status' => 'error', 'message' => $e->getMessage()];
            }
        }

        // Para certificados: plantilla HTML + mPDF (única estrategia — sin Word)
        if (!class_exists('\\Mpdf\\Mpdf') && file_exists(__DIR__ . '/../vendor/autoload.php')) {
            require_once __DIR__ . '/../vendor/autoload.php';
        }

        // QR como base64; si falla la descarga se genera sin imagen QR
        try {
            $qrB64 = $this->descargarQrB64($datos['qr_codigo'] ?? '');
        } catch (\Exception $qrEx) {
            $qrB64 = '';
        }

        try {
            $html    = $this->renderCertTemplate($datos, $qrB64);
            $folio   = $datos['control'] ?? (string)$id;
            $rutaPdf = $this->htmlToPdfMpdf($html, $folio, 'CERT');
            return ['status' => 'success', 'url' => UPLOAD_URL . 'certificados/' . basename($rutaPdf)];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => 'Error al generar el certificado: ' . $e->getMessage()];
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

    // ── Generar y enviar certificado ───────────────────────
    public function generarCertEnviar(array $payload, string $usuario): array {
        ensurePublicado($this->pdo);
        $id = (int) ($payload['id'] ?? $payload['fila'] ?? 0);
        if (!$id) return ['status' => 'error', 'message' => 'ID de equipo requerido.'];

        $datos = $this->obtenerDatosEquipo($id);
        if (!$datos) return ['status' => 'error', 'message' => 'Registro no encontrado.'];

        $correo = trim($datos['correo'] ?? '');
        if (!$correo) return ['status' => 'error', 'message' => 'El registro no tiene correo registrado.'];

        try {
            $folio = $datos['control'] ?? $id;

            $rutaCert   = $this->resolverPdfEnvio('certificado', $id, $datos);
            $nombreCert = basename($rutaCert);
            $urlCert    = UPLOAD_URL . 'certificados/' . $nombreCert;

            $credenciales = [];
            if (!empty($payload['enviar_credenciales'])) {
                $credenciales = $this->gestionarCredencialesCliente($datos['cliente'], $correo);
            }

            $this->enviarCorreo($correo, $datos['cliente'], formatoFolio((string)$folio), 'certificado', [$rutaCert => $nombreCert], $credenciales);

            $this->pdo->prepare(
                "UPDATE equipos SET certificado_url = ?, estado = 'ENVIADO', publicado = 1, fecha_enviado = NOW() WHERE id = ?"
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
        ensurePublicado($this->pdo);
        $id = (int) ($payload['id'] ?? $payload['fila'] ?? 0);
        if (!$id) return ['status' => 'error', 'message' => 'ID de equipo requerido.'];

        $datos = $this->obtenerDatosEquipo($id);
        if (!$datos) return ['status' => 'error', 'message' => 'Registro no encontrado.'];

        $correo = trim($datos['correo'] ?? '');
        if (!$correo) return ['status' => 'error', 'message' => 'El registro no tiene correo registrado.'];

        try {
            $folio = $datos['control'] ?? $id;

            $rutaCert   = $this->resolverPdfEnvio('certificado', $id, $datos);
            $nombreCert = basename($rutaCert);
            $urlCert    = UPLOAD_URL . 'certificados/' . $nombreCert;

            $rutaDict   = $this->resolverPdfEnvio('dictamen', $id, $datos);
            $nombreDict = basename($rutaDict);
            $urlDict    = UPLOAD_URL . 'certificados/' . $nombreDict;

            $credenciales = [];
            if (!empty($payload['enviar_credenciales'])) {
                $credenciales = $this->gestionarCredencialesCliente($datos['cliente'], $correo);
            }

            $this->enviarCorreo($correo, $datos['cliente'], formatoFolio((string)$folio), 'certificado y dictamen', [
                $rutaCert => $nombreCert,
                $rutaDict => $nombreDict,
            ], $credenciales);

            $this->pdo->prepare(
                "UPDATE equipos
                 SET certificado_url = ?, dictamen_url = ?, estado = 'ENVIADO', publicado = 1, fecha_enviado = NOW()
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
        ensurePublicado($this->pdo);
        $id = (int) ($payload['id'] ?? $payload['fila'] ?? 0);
        if (!$id) return ['status' => 'error', 'message' => 'ID de equipo requerido.'];

        $datos = $this->obtenerDatosEquipo($id);
        if (!$datos) return ['status' => 'error', 'message' => 'Registro no encontrado.'];

        $correo = trim($datos['correo'] ?? '');
        if (!$correo) return ['status' => 'error', 'message' => 'El registro no tiene correo registrado.'];

        try {
            $folio = $datos['control'] ?? $id;

            $rutaDict   = $this->resolverPdfEnvio('dictamen', $id, $datos);
            $nombreDict = basename($rutaDict);
            $urlDict    = UPLOAD_URL . 'certificados/' . $nombreDict;

            $credenciales = [];
            if (!empty($payload['enviar_credenciales'])) {
                $credenciales = $this->gestionarCredencialesCliente($datos['cliente'], $correo);
            }

            $this->enviarCorreo($correo, $datos['cliente'], formatoFolio((string)$folio), 'dictamen', [$rutaDict => $nombreDict], $credenciales);

            $this->pdo->prepare(
                "UPDATE equipos SET dictamen_url = ?, estado = 'ENVIADO', publicado = 1, fecha_enviado = NOW() WHERE id = ?"
            )->execute([$urlDict, $id]);

            $this->registrarEnvio($datos, $nombreDict, $usuario);
            registrarHistorial($this->pdo, $usuario, $id, 'estado', $datos['estado'], 'ENVIADO');

            return ['status' => 'success', 'url' => $urlDict];
        } catch (Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Habilita los documentos en el PORTAL DEL CLIENTE sin enviar correo.
     * Genera el certificado y/o el dictamen, guarda sus URLs y marca el
     * registro como ENVIADO (que es la condición para que el equipo y sus
     * documentos aparezcan en "Mis Equipos" del portal). No manda email.
     * $tipo: 'cert' | 'dict' | 'todo' (por defecto 'todo').
     */
    public function publicarPortalCliente(array $payload, string $usuario): array {
        ensurePublicado($this->pdo);
        $id = (int) ($payload['id'] ?? $payload['fila'] ?? 0);
        if (!$id) return ['status' => 'error', 'message' => 'ID de equipo requerido.'];

        $datos = $this->obtenerDatosEquipo($id);
        if (!$datos) return ['status' => 'error', 'message' => 'Registro no encontrado.'];

        $tipo = in_array(($payload['tipo'] ?? 'todo'), ['cert','dict','todo'], true) ? $payload['tipo'] : 'todo';

        $sets   = [];
        $params = [];
        $hechos = [];

        if ($tipo === 'cert' || $tipo === 'todo') {
            try {
                $ruta = $this->resolverPdfEnvio('certificado', $id, $datos);
                $sets[]   = 'certificado_url = ?';
                $params[] = UPLOAD_URL . 'certificados/' . basename($ruta);
                $hechos[] = 'certificado';
            } catch (\Throwable $e) {
                if ($tipo === 'cert') return ['status' => 'error', 'message' => 'No se pudo generar el certificado: ' . $e->getMessage()];
                error_log('[publicarPortalCliente] cert: ' . $e->getMessage());
            }
        }
        if ($tipo === 'dict' || $tipo === 'todo') {
            try {
                $ruta = $this->resolverPdfEnvio('dictamen', $id, $datos);
                $sets[]   = 'dictamen_url = ?';
                $params[] = UPLOAD_URL . 'certificados/' . basename($ruta);
                $hechos[] = 'dictamen';
            } catch (\Throwable $e) {
                if ($tipo === 'dict') return ['status' => 'error', 'message' => 'No se pudo generar el dictamen: ' . $e->getMessage()];
                error_log('[publicarPortalCliente] dict: ' . $e->getMessage());
            }
        }

        if (!$sets) return ['status' => 'error', 'message' => 'No se pudo generar ningún documento para publicar.'];

        $sets[] = "estado = 'ENVIADO'";
        $sets[] = "publicado = 1";
        $sets[] = 'fecha_enviado = NOW()';
        $params[] = $id;
        $this->pdo->prepare("UPDATE equipos SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);

        if (($datos['estado'] ?? '') !== 'ENVIADO') {
            registrarHistorial($this->pdo, $usuario, $id, 'estado', $datos['estado'] ?? null, 'ENVIADO');
        }

        return ['status' => 'success', 'message' => 'Documentos habilitados en el portal del cliente (' . implode(' + ', $hechos) . ').'];
    }

    // ── Rechazar a calidad ─────────────────────────────────
    public function rechazarACertificacion(array $payload, string $usuario): array {
        $id = (int) ($payload['id'] ?? $payload['fila'] ?? 0);
        if (!$id) return ['status' => 'error', 'message' => 'ID de equipo requerido.'];

        $datos = $this->obtenerDatosEquipo($id);
        if (!$datos) return ['status' => 'error', 'message' => 'Registro no encontrado.'];

        // Se CONSERVA el QR asignado (no se libera ni se borra del equipo): al
        // volver a Calidad y presionar "Aprobar registro", ese mismo QR aparece
        // precargado, con opción de mantenerlo o cambiarlo. El QR sigue
        // reservado para este equipo (qr_codigos.usado = 1, equipo_id = id).
        // Los documentos NO se borran ni se despublica el registro: el cliente
        // conserva en su portal lo que ya recibió mientras Calidad corrige. Se
        // sustituirán solos cuando Certificaciones vuelva a emitir.
        $this->pdo->prepare(
            "UPDATE equipos SET estado = 'RETORNADO' WHERE id = ?"
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
                    $color  = str_pad(ltrim($campo['color'] ?? '000000', '#'), 6, '0', STR_PAD_LEFT);

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
                            $qrTmp = sys_get_temp_dir() . '/avba_qr_' . uniqid() . '.png';
                            $qrData = qrCodigoPngBytes($qrCodigo);
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

            file_put_contents($rutaPdf, protegerPdf($pdf->Output('S')));

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

        $qrTemp    = sys_get_temp_dir() . '/avba_qr_' . md5($qrCodigo) . '.png';
        $qrContent = qrCodigoPngBytes($qrCodigo);

        if ($qrContent === '') {
            throw new \RuntimeException('No se pudo generar la imagen del código QR.');
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
    private function resolverPdf(string $tipo, array $datos, bool $sinSellos = false): string {
        // Asegurar que mPDF esté disponible
        if (!class_exists('\\Mpdf\\Mpdf') && file_exists(__DIR__ . '/../vendor/autoload.php')) {
            require_once __DIR__ . '/../vendor/autoload.php';
        }

        // QR como base64; si falla se continúa sin él
        try {
            $qrB64 = $this->descargarQrB64($datos['qr_codigo'] ?? '');
        } catch (\Exception $qrEx) {
            $qrB64 = '';
        }

        if ($tipo === 'dictamen') {
            $items         = $this->obtenerChecklistEquipo((int)($datos['id'] ?? 0), $datos['maquinaria'] ?? '');
            $plantillaHtml = $this->obtenerPlantillaDictHtml($datos['maquinaria'] ?? '');
            $sufijo        = $sinSellos ? 'PRINT_DICT' : 'DICT';

            if ($plantillaHtml) {
                $html = $this->renderDictamenHtmlTemplate($plantillaHtml, $datos, $qrB64, $items, $sinSellos);
                return $this->htmlToPdfMpdf($html, $datos['control'] ?? (string)($datos['id'] ?? 'doc'), $sufijo);
            }

            // Fallback: dictamen programático
            $html = $this->htmlDictamen($datos, $qrB64, $items);
            return $this->htmlAPdf($html, $datos['control'] ?? (string)($datos['id'] ?? 'doc'), $tipo);
        }

        // Certificado: usar plantilla HTML + mPDF
        $sufijo = $sinSellos ? 'PRINT_CERT' : 'CERT';
        $html   = $this->renderCertTemplate($datos, $qrB64, $sinSellos);
        return $this->htmlToPdfMpdf($html, $datos['control'] ?? (string)($datos['id'] ?? 'doc'), $sufijo);
    }

    /** Lee el archivo de plantilla HTML para dictamen configurado en maquinaria_tipos. */
    private function obtenerPlantillaDictHtml(string $maquinaria): ?string {
        if (!$maquinaria) return null;
        $stmt = $this->pdo->prepare(
            "SELECT plantilla_dict_html FROM maquinaria_tipos WHERE nombre = ? LIMIT 1"
        );
        $stmt->execute([$maquinaria]);
        $row = $stmt->fetch();
        return !empty($row['plantilla_dict_html']) ? $row['plantilla_dict_html'] : null;
    }

    /** Construye HTML de tabla de prueba de carga a partir de array JSON. */
    private function buildPruebaCargaHtml(?array $datos): string {
        if (empty($datos)) return '';
        $plantilla = $datos['plantilla'] ?? '';
        unset($datos['plantilla']);
        if (empty($datos)) return '';

        $html = "<table style='width:100%;border-collapse:collapse;font-size:10px;margin:12px 0'>\n"
              . "<thead><tr style='background:#0B2545;color:#fff'>\n"
              . "<th style='padding:5px;border:1px solid #999;text-align:left'>Tipo de Prueba</th>\n";

        $primeraFila = reset($datos);
        if (is_array($primeraFila)) {
            foreach ($primeraFila as $col => $val) {
                $html .= "<th style='padding:5px;border:1px solid #999;text-align:center'>{$col}</th>\n";
            }
        }
        $html .= "</tr></thead><tbody>\n";

        $bg = 0;
        foreach ($datos as $tipoFila => $cols) {
            if (!is_array($cols)) continue;
            $bgColor = ($bg++ % 2 === 0) ? '#f9fafb' : '#fff';
            $html .= "<tr style='background:{$bgColor}'>\n"
                   . "<td style='padding:5px;border:1px solid #ddd;font-weight:600'>{$tipoFila}</td>\n";
            foreach ($cols as $valor) {
                $html .= "<td style='padding:5px;border:1px solid #ddd;text-align:center'>" . htmlspecialchars($valor, ENT_QUOTES, 'UTF-8') . "</td>\n";
            }
            $html .= "</tr>\n";
        }
        $html .= "</tbody></table>";
        return $html;
    }

    /**
     * Carga la plantilla HTML de dictamen y sustituye tokens {campo} con los datos reales.
     * Tokens soportados: {folio} {cliente} {domicilio} {tipo_maquinaria} {capacidad}
     *   {marca} {modelo} {no_serie} {no_identificacion} {fecha_inspeccion} {vigencia}
     *   {qr_imagen} {checklist_rows} {prueba_carga} {numero_inspector}
     *   {{normas_acreditadas}} {{normas_referencia}}
     */
    private function renderDictamenHtmlTemplate(string $archivo, array $d, string $qrB64, array $items, bool $sinSellos = false): string {
        $templatePath = __DIR__ . '/../' . $archivo;
        if (!file_exists($templatePath)) {
            return $this->htmlDictamen($d, $qrB64, $items);
        }

        // El HTML con fotos e imágenes base64 supera el límite de retroceso por defecto (1M).
        // Se sube antes de cualquier preg_replace para que ninguna transformación devuelva null.
        $prevBacktrack = (int) ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', 10000000);

        $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

        $vigencia = '';
        if (!empty($d['fecha_inspeccion'])) {
            try {
                $fv = new \DateTime($d['fecha_inspeccion']);
                $fv->modify('+1 year');
                $vigencia = $fv->format('d/m/Y');
            } catch (\Exception $ex) {}
        }

        // Construir normas desde maquinaria_tipos
        [$normasAcredHtml, $normasRefHtml] = $this->obtenerNormasHtml($d['maquinaria'] ?? '');

        $folio = $e(formatoFolio($d['control'] ?? ''));

        // Obtener número, nombre y firma del inspector
        $numeroInspector  = '';
        $nombreInspector  = '';
        $firmaInspectorImg = '';
        if (!empty($d['inspector'])) {
            $stmt = $this->pdo->prepare("SELECT id, nombre, firma_imagen FROM usuarios WHERE usuario = ? LIMIT 1");
            $stmt->execute([$d['inspector']]);
            $usr = $stmt->fetch();
            if ($usr) {
                $numeroInspector = (string)$usr['id'];
                $nombreInspector = $usr['nombre'] ?? '';
                $firmaUrl = $usr['firma_imagen'] ?? '';
                // Fallback: si el inspector no tiene firma_imagen en DB, intentar por nombre normalizado
                if (!$firmaUrl) {
                    $normNombre = $this->normalizarTexto($nombreInspector ?: $d['inspector']);
                    if (strpos($normNombre, 'jose marcos') !== false || strpos($normNombre, 'marcos gonzalez') !== false) {
                        $firmaUrl = 'uploads/firmas/firma_jose_marcos.jpg';
                    }
                }
                if ($firmaUrl) {
                    $localPath = __DIR__ . '/../' . ltrim($firmaUrl, '/');
                    if (file_exists($localPath)) {
                        $ext  = strtolower(pathinfo($localPath, PATHINFO_EXTENSION));
                        $mime = $ext === 'png' ? 'image/png' : 'image/jpeg';
                        $b64  = base64_encode(file_get_contents($localPath));
                        $firmaInspectorImg = "<img src='data:{$mime};base64,{$b64}' style='max-width:120px;max-height:50px;object-fit:contain;'>";
                    }
                }
            }
        }

        // Obtener datos del responsable (Aprobado Por)
        $firmaResponsableImg = '';
        $nombreResponsable   = 'ING. YOSELIN MARTINEZ JUAREZ';
        $cargoResponsable    = 'Director Técnico Sustituto';
        $stmtR = $this->pdo->prepare("SELECT nombre, firma_imagen FROM usuarios WHERE nombre LIKE ? LIMIT 1");
        $stmtR->execute(['%Yoselin%']);
        $resp = $stmtR->fetch();
        if ($resp) {
            $nombreResponsable = strtoupper($resp['nombre']);
            $firmaUrlR = $resp['firma_imagen'] ?? '';
            if (!$firmaUrlR) $firmaUrlR = 'uploads/firmas/firma_yoselin.jpg';
        } else {
            $firmaUrlR = 'uploads/firmas/firma_yoselin.jpg';
        }
        $localPathR = __DIR__ . '/../' . ltrim($firmaUrlR, '/');
        if (file_exists($localPathR)) {
            $extR  = strtolower(pathinfo($localPathR, PATHINFO_EXTENSION));
            $mimeR = $extR === 'png' ? 'image/png' : 'image/jpeg';
            $b64R  = base64_encode(file_get_contents($localPathR));
            $firmaResponsableImg = "<img src='data:{$mimeR};base64,{$b64R}' style='max-width:120px;max-height:50px;object-fit:contain;'>";
        }

        // Construir HTML de prueba de carga si existen datos
        $pruebaCargaHtml = '';
        if (!empty($d['prueba_carga'])) {
            $pruebaCargaHtml = $this->buildPruebaCargaHtml(json_decode($d['prueba_carga'], true));
        }

        $horquillas = !empty($d['horquillas']) ? (json_decode($d['horquillas'], true) ?? []) : [];

        $map = [
            '{folio}'             => $folio,
            '{cliente}'           => $e($d['cliente']    ?? ''),
            '{domicilio}'         => $e($d['direccion']  ?? ''),
            '{tipo_maquinaria}'   => $e($d['maquinaria'] ?? ''),
            '{capacidad}'         => $e($d['capacidad']  ?? ''),
            '{marca}'             => $e($d['marca']       ?? ''),
            '{modelo}'            => $e($d['modelo']      ?? ''),
            '{no_serie}'          => $e($d['serie']       ?? ''),
            '{no_identificacion}' => $e($d['id_equipo']  ?? ''),
            '{fecha_inspeccion}'  => $e($d['fecha_fmt']  ?? ''),
            '{vigencia}'          => $e($vigencia),
            '{qr_imagen}'         => $qrB64,
            '{checklist_rows}'    => $this->buildDictamenChecklistRows($items),
            '{prueba_carga}'      => $pruebaCargaHtml,
            '{numero_inspector}'   => $e($numeroInspector),
            '{nombre_inspector}'   => $e($nombreInspector),
            '{firma_inspector_img}'  => $sinSellos ? '' : $firmaInspectorImg,
            '{firma_responsable_img}' => $sinSellos ? '' : $firmaResponsableImg,
            '{nombre_responsable}'   => $e($nombreResponsable),
            '{cargo_responsable}'    => $e($cargoResponsable),
            '{horquilla_marca}'      => $e($horquillas['marca']           ?? ''),
            '{horquilla_serie}'      => $e($horquillas['serie']           ?? ''),
            '{horquilla_capacidad}'  => $e($horquillas['capacidad']       ?? ''),
            '{horquilla_cg}'         => $e($horquillas['centro_gravedad'] ?? ''),
            '{{normas_acreditadas}}' => $normasAcredHtml,
            '{{normas_referencia}}'  => $normasRefHtml,
        ];

        $html = file_get_contents($templatePath);
        $html = str_replace(array_keys($map), array_values($map), $html);

        // Inyectar fotos reales sobre los divs foto-placeholder
        $html = $this->inyectarFotosDictamen($html, $d['evidencia_url'] ?? '');

        // Inyectar datos de prueba de carga en la tabla pc-table
        if (!empty($d['prueba_carga'])) {
            $pc = json_decode($d['prueba_carga'], true) ?? [];
            $html = $this->inyectarPruebaCargaDictamen($html, $pc, $d);
        }

        // Ajuste para mPDF:
        // 0. Reparar tabla .hdr sin cerrar: algunas plantillas dejan el <td class="hdr-logos">
        //    y la tabla .hdr abiertos antes de .gold-bar (los navegadores lo toleran, pero
        //    mPDF no flushea la tabla y genera el PDF en blanco). Solo se aplica si las
        //    etiquetas <table> están desbalanceadas, de modo que es idempotente.
        if (substr_count($html, '<table') !== substr_count($html, '</table>')) {
            $html = preg_replace(
                '#(<td class="hdr-logos"[\s\S]*?</table>)(\s*)(<div class="gold-bar">)#',
                '$1</td></tr></table>$2$3',
                $html
            );
        }

        // 1. Eliminar @import de Google Fonts (mPDF intenta la petición HTTP y falla, dejando PDF en blanco)
        $html = preg_replace('/@import\s+url\([^)]*\)\s*;?/i', '', $html);

        // 2. Corregir propiedades CSS no soportadas: overflow:hidden recorta a altura 0,
        //    min-height se maneja diferente, box-shadow/text-shadow no existen en mPDF
        $override = '<style>
body { background:#fff!important; }
.page {
    box-shadow:none!important;
    margin:0!important;
    overflow:visible!important;
    min-height:0!important;
    width:100%!important;
}
* { text-shadow:none!important; }
</style>';
        $result = str_replace('</head>', $override . '</head>', $html);

        ini_set('pcre.backtrack_limit', $prevBacktrack);
        return $result;
    }

    /**
     * Extrae las columnas de datos del <thead class="pc-thead-row"> de la plantilla
     * y las traduce al id de campo del JSON (peso, radio, altura, resultado, …).
     * Se omite la primera columna ("Prueba"). Devuelve [] si no encuentra la tabla.
     */
    private function columnasPcTabla(string $html): array {
        if (!preg_match('/<thead>\s*<tr class="pc-thead-row">(.*?)<\/tr>\s*<\/thead>/s', $html, $m)) {
            return [];
        }
        if (!preg_match_all('/<th\b[^>]*>(.*?)<\/th>/s', $m[1], $ths) || empty($ths[1])) {
            return [];
        }
        $campos = [];
        foreach (array_slice($ths[1], 1) as $thHtml) {   // omitir la columna "Prueba"
            // Quitar el subtítulo (<span class="pc-thead-sub">…</span>) y etiquetas
            $txt = preg_replace('/<span class="pc-thead-sub">.*?<\/span>/s', '', $thHtml);
            $txt = $this->normalizarTexto(strip_tags($txt));
            $campos[] = $this->pcCampoDeEncabezado($txt);
        }
        return $campos;
    }

    /** Mapea el texto (normalizado) de un encabezado de columna a su id de campo del JSON. */
    private function pcCampoDeEncabezado(string $t): string {
        if (strpos($t, 'resultado') !== false || strpos($t, 'result') !== false) return 'resultado';
        if (strpos($t, 'carga de prueba') !== false)                              return 'prueba';
        if (strpos($t, 'cml') !== false)                                          return 'cml';
        if (strpos($t, 'deforma') !== false)                                      return 'deformacion';
        if (strpos($t, 'duracion') !== false)                                     return 'duracion';
        if (strpos($t, 'inclin') !== false)                                       return 'inclin';
        if (strpos($t, 'extensi') !== false)                                      return 'extension';
        if (strpos($t, 'peso') !== false)                                         return 'peso';
        if (strpos($t, 'pluma') !== false || strpos($t, 'longitud') !== false)    return 'pluma';
        if (strpos($t, 'angulo') !== false)                                       return 'angulo';
        if (strpos($t, 'radio') !== false)                                        return 'radio';
        if (strpos($t, 'altura') !== false)                                       return 'altura';
        return '';
    }

    /**
     * Inyecta los datos del JSON prueba_carga en el header y tbody de pc-table,
     * y corrige el texto hardcodeado "Equipo Inspeccionado: ...".
     */
    private function inyectarPruebaCargaDictamen(string $html, array $pc, array $d): string {
        $e   = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        unset($pc['plantilla']);

        // 1. Corregir celda "Equipo Inspeccionado" — puede ser hardcoded en algunas plantillas
        $equipoStr = $e(($d['maquinaria'] ?? '') . ' · ' . ($d['marca'] ?? '') . ' ' . ($d['modelo'] ?? ''));
        $html = preg_replace(
            '/<div class="pc-equip-val">Equipo Inspeccionado:[^<]*<\/div>/',
            '<div class="pc-equip-val">' . $equipoStr . '</div>',
            $html
        );

        // 2. Inyectar peso de prueba en el header (primer valor "peso" del primer row del JSON)
        $pesoPrueba = '';
        foreach ($pc as $rowData) {
            if (is_array($rowData) && isset($rowData['peso']) && $rowData['peso'] !== '') {
                $pesoPrueba = $e($rowData['peso']);
                break;
            }
        }
        if ($pesoPrueba) {
            // Reemplazar la celda Peso de Prueba del header (pc-header-strip)
            $html = preg_replace(
                '/(<div class="pc-equip-lbl">Peso de Prueba<\/div><div class="pc-equip-val">)[^<]*(<\/div>)/',
                '${1}' . $pesoPrueba . '${2}',
                $html
            );
        }

        // 3. Reemplazar el <tbody> de la pc-table con los datos reales del JSON.
        //    Se generan EXACTAMENTE las columnas que define el <thead> de la plantilla
        //    (mapeando cada encabezado a su campo del JSON). Así nunca se desbordan
        //    celdas fuera del margen aunque el JSON traiga campos extra (p. ej.
        //    tipo_plataforma) o en distinto orden.
        $filas = array_filter(
            $pc,
            fn($v, $k) => is_array($v) && $k !== 'plantilla',
            ARRAY_FILTER_USE_BOTH
        );
        if (!empty($filas)) {
            // 3a. Extraer las columnas de datos del <thead> (se omite la 1ª, "Prueba").
            $colsCampos = $this->columnasPcTabla($html);

            $rowLabels = [
                'sin' => 'Sin Carga', 'con' => 'Con Carga',
                'trabajo' => 'Carga de Trabajo', 'sobrecarga' => 'Prueba de Sobrecarga',
            ];
            $tbodyHtml = "<tbody>\n";
            $rowIndex  = 0;
            foreach ($filas as $tipoFila => $datos) {
                $rowCls = $rowIndex === 0 ? 'pc-row-sin' : 'pc-row-con';
                $chk = $rowIndex === 0
                    ? '<svg style="width:12px;height:12px;display:inline-block;vertical-align:middle;margin-right:2px;" viewBox="0 0 12 12"><rect width="12" height="12" rx="1" fill="#f0f4f8" stroke="#cdd8e3" stroke-width="1.2"/></svg>'
                    : '<svg style="width:12px;height:12px;display:inline-block;vertical-align:middle;margin-right:2px;" viewBox="0 0 12 12"><rect width="12" height="12" rx="1" fill="#fff8e1" stroke="#f0c040" stroke-width="1.5"/></svg>';
                $label = $rowLabels[$tipoFila] ?? ucfirst((string)$tipoFila);

                $rowRadio = (float)($datos['radio'] ?? 0);
                $rowPluma = (float)($datos['pluma'] ?? 0);

                $tbodyHtml .= "<tr class=\"{$rowCls}\">\n";
                $tbodyHtml .= "<td class=\"pc-td-desc\"><span class=\"pc-test-badge\">{$chk} " . $e($label) . "</span></td>\n";

                // Una celda por columna del thead — sin celdas de más.
                $campos = !empty($colsCampos) ? $colsCampos : ['peso', 'radio', 'altura', 'resultado'];
                foreach ($campos as $campo) {
                    $valor = trim((string)($datos[$campo] ?? ''));
                    // Campos calculados a partir de radio/pluma cuando el inspector no los capturó
                    if ($campo === 'angulo' && $valor === '' && $rowRadio > 0 && $rowPluma >= $rowRadio) {
                        $valor = number_format(rad2deg(acos($rowRadio / $rowPluma)), 1);
                    } elseif ($campo === 'altura' && $valor === '' && $rowPluma > 0) {
                        $valor = number_format(sqrt(max(0, $rowPluma * $rowPluma - $rowRadio * $rowRadio)), 2);
                    }
                    if ($campo === 'resultado') {
                        if ($valor === '') {
                            $tbodyHtml .= "<td class=\"pc-td\"><span style=\"color:#94a3b8;font-weight:700\">NA</span></td>\n";
                        } else {
                            $ok = in_array(strtoupper($valor), ['CONFORME', 'OK', 'CUMPLE'], true);
                            $badge = $ok
                                ? '<span class="pc-ok-badge">✔ ' . $e($valor) . '</span>'
                                : '<span style="color:#c62828;font-weight:700">✘ ' . $e($valor) . '</span>';
                            $tbodyHtml .= "<td class=\"pc-td\">{$badge}</td>\n";
                        }
                    } else {
                        $val = $valor !== ''
                            ? $e($valor)
                            : '<span style="color:#94a3b8;font-style:italic">NA</span>';
                        $tbodyHtml .= "<td class=\"pc-td\">{$val}</td>\n";
                    }
                }
                $tbodyHtml .= "</tr>\n";
                $rowIndex++;
            }
            $tbodyHtml .= "</tbody>";

            // Reemplazar el tbody completo dentro de pc-table
            $html = preg_replace(
                '/(<table\s+class="pc-table"[^>]*>.*?<\/thead>)\s*<tbody>.*?<\/tbody>/s',
                '$1' . "\n" . $tbodyHtml,
                $html
            );
        }

        // 4. Inyectar datos del diagrama de grúa (tokens {pc_crane_svg}, {pc_diag_*}, {pc_obs_*})
        if (strpos($html, '{pc_crane_svg}') !== false) {
            // Usar la fila "con" carga; si no existe, usar la primera disponible
            $diagRow = $pc['con'] ?? $pc['sin'] ?? reset($pc);
            if (is_array($diagRow)) {
                $dRadio  = (float) ($diagRow['radio']  ?? 0);
                $dPluma  = (float) ($diagRow['pluma']  ?? 0);
                $dAltura = (float) ($diagRow['altura'] ?? 0);
                $dAngulo = $dRadio > 0 && $dPluma > 0 && $dRadio <= $dPluma
                    ? round(rad2deg(acos($dRadio / $dPluma)), 1)
                    : (float) ($diagRow['angulo'] ?? 0);

                $html = str_replace('{pc_crane_svg}',    $this->buildCraneSvg($dRadio, $dPluma, $dAltura, $dAngulo), $html);
                $html = str_replace('{pc_diag_radio}',   number_format($dRadio,  1), $html);
                $html = str_replace('{pc_diag_pluma}',   number_format($dPluma,  1), $html);
                $html = str_replace('{pc_diag_angulo}',  number_format($dAngulo, 1), $html);
                $html = str_replace('{pc_diag_altura}',  number_format($dAltura, 2), $html);
                $html = str_replace('{pc_obs_radio}',    number_format($dRadio,  1), $html);
                $html = str_replace('{pc_obs_pluma}',    number_format($dPluma,  1), $html);
                $html = str_replace('{pc_obs_angulo}',   number_format($dAngulo, 1), $html);
                $html = str_replace('{pc_obs_altura}',   number_format($dAltura, 2), $html);
            }
        }

        // 5. Inyectar datos del diagrama de plataforma (tokens {pc_plat_svg}, {pc_plat_*}, {pc_obs_*})
        if (strpos($html, '{pc_plat_svg}') !== false) {
            // Fila de medición: preferir "con carga"; tomar el valor de la otra fila si falta.
            $rowCon = is_array($pc['con'] ?? null) ? $pc['con'] : [];
            $rowSin = is_array($pc['sin'] ?? null) ? $pc['sin'] : [];
            $valOf  = function (string $campo) use ($rowCon, $rowSin): float {
                $v = trim((string)($rowCon[$campo] ?? ''));
                if ($v === '') $v = trim((string)($rowSin[$campo] ?? ''));
                return (float)$v;
            };
            $pRadio  = $valOf('radio');
            $pAltura = $valOf('altura');

            // tipo_plataforma: primero a nivel raíz (selector único), luego por fila.
            $tipoRaw = $pc['tipo_plataforma']
                ?? ($rowCon['tipo_plataforma'] ?? ($rowSin['tipo_plataforma'] ?? 'tijera'));
            $tipo    = strtolower((string)$tipoRaw);
            $isBoom  = strpos($tipo, 'articulad') !== false || strpos($tipo, 'boom') !== false;
            $svgHtml = $isBoom
                ? $this->buildBoomSvg($pRadio, $pAltura)
                : $this->buildPlatformSvg($pRadio, $pAltura);

            $html = str_replace('{pc_plat_svg}', $svgHtml, $html);

            // Celdas del diagrama: con valor -> "7.6" + unidad "m"; sin valor -> "NA" (se elide la unidad)
            $html = ($pRadio > 0)
                ? str_replace('{pc_plat_radio}',  number_format($pRadio, 1),  $html)
                : str_replace('{pc_plat_radio}<span class="unit">m</span>', '<span style="color:#94a3b8">NA</span>', $html);
            $html = ($pAltura > 0)
                ? str_replace('{pc_plat_altura}', number_format($pAltura, 1), $html)
                : str_replace('{pc_plat_altura}<span class="unit">m</span>', '<span style="color:#94a3b8">NA</span>', $html);

            // Texto de observaciones: con valor -> "7.6 m"; sin valor -> "NA" (se elide la unidad)
            $html = ($pRadio > 0)
                ? str_replace('{pc_obs_radio}',  number_format($pRadio, 1),  $html)
                : str_replace('{pc_obs_radio} m',  'NA', $html);
            $html = ($pAltura > 0)
                ? str_replace('{pc_obs_altura}', number_format($pAltura, 1), $html)
                : str_replace('{pc_obs_altura} m', 'NA', $html);
        }

        // 6. Elementos de izaje: CML es la única propiedad capturada del elemento
        //    (constante, una sola vez — no por fila). La Carga de Prueba (2×CML)
        //    NO se le pide al inspector: se deriva directamente duplicando el CML
        //    (siempre es el doble, por definición de la prueba de sobrecarga).
        //    Duración y deformación se toman de la prueba de sobrecarga (o de
        //    trabajo si falta). Sin cálculos geométricos: las cargas se aplican en
        //    vertical, sin ángulo de trabajo ni radio de operación.
        if (strpos($html, '{pc_izaje_cml}') !== false) {
            $rowSobre = is_array($pc['sobrecarga'] ?? null) ? $pc['sobrecarga'] : [];
            $rowTrab  = is_array($pc['trabajo']    ?? null) ? $pc['trabajo']    : [];

            // CML: valor único a nivel raíz (formato actual). Compatibilidad con
            // registros guardados antes de este cambio, cuando CML/Carga de Prueba
            // se pedían repetidos dentro de cada fila.
            $cml = trim((string)($pc['cml'] ?? ''));
            if ($cml === '') {
                $cml = trim((string)($rowSobre['cml'] ?? '')) ?: trim((string)($rowTrab['cml'] ?? ''));
            }
            $prueba = ($cml !== '' && is_numeric($cml)) ? (string)((float)$cml * 2) : '';
            if ($prueba === '') {
                // Registros antiguos: usar la carga de prueba tal como se guardó.
                $prueba = trim((string)($rowSobre['prueba'] ?? '')) ?: trim((string)($rowTrab['prueba'] ?? ''));
            }
            $duracion    = trim((string)($rowSobre['duracion']    ?? '')) ?: trim((string)($rowTrab['duracion']    ?? ''));
            $deformacion = trim((string)($rowSobre['deformacion'] ?? '')) ?: trim((string)($rowTrab['deformacion'] ?? ''));

            $html = str_replace('{pc_izaje_cml}',         $cml    !== '' ? number_format((float)$cml, 2)    : 'NA', $html);
            $html = str_replace('{pc_izaje_prueba}',      $prueba !== '' ? number_format((float)$prueba, 2) : 'NA', $html);
            $html = str_replace('{pc_izaje_duracion}',    $duracion    !== '' ? $e($duracion)    : 'NA', $html);
            $html = str_replace('{pc_izaje_deformacion}', $deformacion !== '' ? $e($deformacion) : 'NA', $html);
        }

        return $html;
    }

    /** Genera el SVG de diagrama de grúa con coordenadas calculadas a partir de los valores reales. */
    private function buildCraneSvg(float $radio, float $pluma, float $altura, float $angulo): string {
        $pivX = 48; $pivY = 108; $gndY = 123;

        // Escala para que el diagrama quepa en el viewport (180x140)
        $availW = 152 - $pivX;
        $availH = $pivY - 20;
        $scale  = $radio > 0 && $altura > 0
            ? min($availW / $radio, $availH / max($altura, 0.1))
            : 8.0;
        $scale  = max(min($scale, 13.0), 3.5);

        $tipX = round($pivX + $radio * $scale, 1);
        $tipY = round($pivY - $altura * $scale, 1);

        // Ángulo del arco (radio 18 px desde la base del pivote)
        $arcR    = 18;
        $arcSX   = $pivX + $arcR;
        $arcSY   = $gndY;
        $radAng  = deg2rad($angulo);
        $arcEX   = round($pivX + $arcR * cos($radAng), 1);
        $arcEY   = round($pivY  - $arcR * sin($radAng), 1);

        // Esquina del ángulo recto
        $cL = $tipX - 6;
        $cT = $gndY  - 6;

        // Posiciones de etiquetas
        $lbRadX = round(($pivX + $tipX) / 2);   $lbRadY = $gndY + 10;
        $lbAltX = min($tipX + 28, 172);          $lbAltY = round(($tipY + $gndY) / 2 + 4);
        $lbAngX = $pivX + 22;                    $lbAngY = $pivY - 5;

        // Path auxiliar en defs (para textPath de pluma)
        $defSX = $pivX - 6; $defSY = $pivY - 6;

        $rF = number_format($radio,  1);
        $pF = number_format($pluma,  1);
        $hF = number_format($altura, 2);
        $aF = number_format($angulo, 1);

        return <<<SVG
<svg viewBox="0 0 180 140" xmlns="http://www.w3.org/2000/svg" style="width:163px;height:127px;display:block">
  <defs><path id="boom-path2" d="M {$defSX},{$defSY} L {$tipX},{$tipY}"/></defs>
  <line x1="2" y1="{$gndY}" x2="178" y2="{$gndY}" stroke="#cdd8e3" stroke-width="2.5"/>
  <rect x="2" y="{$gndY}" width="176" height="10" fill="#e8edf3"/>
  <ellipse cx="34" cy="{$gndY}" rx="8" ry="5" fill="#2d3748"/>
  <ellipse cx="34" cy="{$gndY}" rx="5" ry="3" fill="#4a5568"/>
  <ellipse cx="54" cy="{$gndY}" rx="8" ry="5" fill="#2d3748"/>
  <ellipse cx="54" cy="{$gndY}" rx="5" ry="3" fill="#4a5568"/>
  <rect x="26" y="108" width="36" height="14" rx="2" fill="#134074"/>
  <rect x="26" y="94" width="16" height="14" rx="2" fill="#1e5fa8"/>
  <rect x="28" y="96" width="5" height="5" rx="1" fill="#90cdf4" opacity=".7"/>
  <rect x="35" y="96" width="5" height="5" rx="1" fill="#90cdf4" opacity=".7"/>
  <rect x="56" y="100" width="8" height="8" rx="1" fill="#0B2545"/>
  <circle cx="{$pivX}" cy="{$pivY}" r="5" fill="#C49A28"/>
  <circle cx="{$pivX}" cy="{$pivY}" r="2.5" fill="#fff"/>
  <line x1="{$pivX}" y1="{$pivY}" x2="{$tipX}" y2="{$tipY}" stroke="#C49A28" stroke-width="3.8" stroke-linecap="round"/>
  <line x1="{$pivX}" y1="{$pivY}" x2="{$tipX}" y2="{$tipY}" stroke="#e8c547" stroke-width="1.2" stroke-linecap="round" opacity=".6"/>
  <line x1="{$pivX}" y1="{$gndY}" x2="{$tipX}" y2="{$gndY}" stroke="#1e5fa8" stroke-width="2" stroke-dasharray="5,3"/>
  <line x1="{$tipX}" y1="{$gndY}" x2="{$tipX}" y2="{$tipY}" stroke="#1a7a4a" stroke-width="2" stroke-dasharray="5,3"/>
  <polyline points="{$cL},{$gndY} {$cL},{$cT} {$tipX},{$cT}" fill="none" stroke="#1e5fa8" stroke-width="1.3"/>
  <path d="M {$arcSX},{$arcSY} A {$arcR},{$arcR} 0 0,0 {$arcEX},{$arcEY}" fill="#C49A28" opacity=".15"/>
  <path d="M {$arcSX},{$arcSY} A {$arcR},{$arcR} 0 0,0 {$arcEX},{$arcEY}" fill="none" stroke="#C49A28" stroke-width="1.6"/>
  <rect x="{$lbRadX}" y="{$lbRadY}" width="52" height="12" rx="3" fill="#dbeafe" transform="translate(-26,0)"/>
  <text x="{$lbRadX}" y="{$lbRadY}" dy="9" text-anchor="middle" font-family="Inter,Arial,sans-serif" font-size="7.5" font-weight="700" fill="#1e5fa8">Radio = {$rF} m</text>
  <rect x="{$lbAltX}" y="{$lbAltY}" width="48" height="14" rx="3" fill="#dcfce7" transform="translate(-24,-9)"/>
  <text x="{$lbAltX}" y="{$lbAltY}" text-anchor="middle" font-family="Inter,Arial,sans-serif" font-size="7.5" font-weight="700" fill="#1a7a4a">h = {$hF} m</text>
  <text text-anchor="middle" font-family="Inter,Arial,sans-serif" font-size="7.5" font-weight="700" fill="#7a5500"><textPath href="#boom-path2" startOffset="45%">Pluma {$pF} m</textPath></text>
  <rect x="{$lbAngX}" y="{$lbAngY}" width="44" height="14" rx="3" fill="#fef3c7" transform="translate(-22,-10)"/>
  <text x="{$lbAngX}" y="{$lbAngY}" text-anchor="middle" font-family="Inter,Arial,sans-serif" font-size="8.5" font-weight="800" fill="#92400e">&#945; = {$aF}&#176;</text>
</svg>
SVG;
    }

    /** Genera el SVG de diagrama de plataforma (MEWP/PTEM) — vista lateral de tijera/brazo con altura y radio. */
    private function buildPlatformSvg(float $radio, float $altura): string {
        $rF = $radio  > 0 ? number_format($radio,  1) . ' m' : 'NA';
        $hF = $altura > 0 ? number_format($altura, 1) . ' m' : 'NA';

        // Layout constants – viewBox 0 0 180 140
        $gndY    = 118;
        $baseTop = $gndY - 9;
        $maxScH  = $baseTop - 26;
        $scale   = $altura > 0 ? min($maxScH / $altura, 13.0) : 8.0;
        $scale   = max($scale, 3.5);
        $platTop = (int) round($baseTop - $altura * $scale);
        $platBot = $platTop + 7;

        $midX  = 72;  $halfW = 26;
        $lx    = $midX - $halfW;
        $rx    = $midX + $halfW;

        $sciY     = (int) round(($baseTop + $platBot) / 2);
        $railTop  = $platTop - 15;          // guard rail top bar y
        $hArrX    = $rx + 24;               // height arrow x
        $hLblY    = (int) round(($gndY + $platTop) / 2);
        $rLblY    = $gndY + 14;             // radius label y

        // Arrow head for height (pointing up): triangle tip at $platTop, base 8px below
        $ahTip = $platTop;
        $ahBL  = $platTop + 6;
        $ahLX  = $hArrX - 4;
        $ahRX  = $hArrX + 4;

        // Arrow head for radius (pointing right): tip at $rx+4
        $arTipX = $rx + 4;
        $arBaseX = $rx - 4;
        $arTY   = $rLblY - 3;
        $arBY   = $rLblY + 3;

        return <<<SVG
<svg viewBox="0 0 180 140" xmlns="http://www.w3.org/2000/svg" style="width:163px;height:127px;display:block">
  <line x1="2" y1="{$gndY}" x2="178" y2="{$gndY}" stroke="#cdd8e3" stroke-width="2.5"/>
  <rect x="2" y="{$gndY}" width="176" height="10" fill="#e8edf3"/>
  <ellipse cx="{$lx}" cy="{$gndY}" rx="9" ry="5" fill="#2d3748"/>
  <ellipse cx="{$lx}" cy="{$gndY}" rx="5" ry="3" fill="#4a5568"/>
  <ellipse cx="{$rx}" cy="{$gndY}" rx="9" ry="5" fill="#2d3748"/>
  <ellipse cx="{$rx}" cy="{$gndY}" rx="5" ry="3" fill="#4a5568"/>
  <rect x="{$lx}" y="{$baseTop}" width="52" height="9" rx="2" fill="#134074"/>
  <line x1="{$lx}" y1="{$baseTop}" x2="{$rx}" y2="{$platBot}" stroke="#1e5fa8" stroke-width="3" stroke-linecap="round"/>
  <line x1="{$rx}" y1="{$baseTop}" x2="{$lx}" y2="{$platBot}" stroke="#1e5fa8" stroke-width="3" stroke-linecap="round"/>
  <circle cx="{$midX}" cy="{$sciY}" r="4.5" fill="#C49A28"/>
  <circle cx="{$midX}" cy="{$sciY}" r="2" fill="#fff"/>
  <rect x="{$lx}" y="{$platTop}" width="52" height="7" rx="1" fill="#0B2545"/>
  <rect x="{$lx}" y="{$railTop}" width="3" height="15" rx="1" fill="#2d3748"/>
  <rect x="{$rx}" y="{$railTop}" width="3" height="15" rx="1" fill="#2d3748"/>
  <rect x="{$midX}" y="{$railTop}" width="3" height="15" rx="1" fill="#2d3748" opacity=".7"/>
  <line x1="{$lx}" y1="{$railTop}" x2="{$rx}" y2="{$railTop}" stroke="#2d3748" stroke-width="2.5"/>
  <line x1="{$hArrX}" y1="{$gndY}" x2="{$hArrX}" y2="{$ahBL}" stroke="#1a7a4a" stroke-width="1.8" stroke-dasharray="5,3"/>
  <polygon points="{$hArrX},{$ahTip} {$ahLX},{$ahBL} {$ahRX},{$ahBL}" fill="#1a7a4a"/>
  <rect x="{$hArrX}" y="{$hLblY}" width="40" height="14" rx="3" fill="#dcfce7" transform="translate(4,-7)"/>
  <text x="{$hArrX}" y="{$hLblY}" dy="5" dx="6" font-family="Arial,sans-serif" font-size="7.5" font-weight="700" fill="#1a7a4a">h = {$hF}</text>
  <line x1="{$midX}" y1="{$rLblY}" x2="{$arBaseX}" y2="{$rLblY}" stroke="#1e5fa8" stroke-width="1.8" stroke-dasharray="5,3"/>
  <polygon points="{$arTipX},{$rLblY} {$arBaseX},{$arTY} {$arBaseX},{$arBY}" fill="#1e5fa8"/>
  <rect x="{$midX}" y="{$rLblY}" width="44" height="14" rx="3" fill="#dbeafe" transform="translate(-22,-7)"/>
  <text x="{$midX}" y="{$rLblY}" dy="5" dx="-20" font-family="Arial,sans-serif" font-size="7.5" font-weight="700" fill="#1e5fa8">r = {$rF}</text>
  <text x="90" y="137" text-anchor="middle" font-family="Arial,sans-serif" font-size="7" fill="#6b7280">Plataforma de Tijera / Scissor</text>
</svg>
SVG;
    }

    /** Genera el SVG de diagrama de plataforma articulada (boom/articulado) — vista lateral. */
    private function buildBoomSvg(float $radio, float $altura): string {
        $rF = $radio  > 0 ? number_format($radio,  1) . ' m' : 'NA';
        $hF = $altura > 0 ? number_format($altura, 1) . ' m' : 'NA';

        // viewBox 0 0 180 140
        $gndY   = 118;
        $baseW  = 38; $baseH = 10;
        $baseX  = 14; $baseY = $gndY - $baseH;

        // Pivot point (turntable center)
        $pivX = $baseX + $baseW / 2;
        $pivY = $baseY;

        // Scale: available horizontal = 180 - 20 = 160; available vertical = pivY - 20
        $availW = 160 - $pivX;
        $availH = $pivY - 22;
        $scale  = ($radio > 0 && $altura > 0)
            ? min($availW / $radio, $availH / $altura)
            : 2.8;
        $scale  = max(1.2, min($scale, 5.5));

        $tipX = round($pivX + $radio  * $scale);
        $tipY = round($pivY - $altura * $scale);

        // Clamp tip to stay within viewBox
        if ($tipX > 174) { $s = 174 / ($pivX + $radio * $scale); $tipX = 174; $tipY = round($pivY - $altura * $scale * $s); }
        if ($tipY < 18)  { $s = ($pivY - 18) / ($altura * $scale); $tipY = 18; $tipX = round($pivX + $radio * $scale * $s); }

        // Boom arm: lower boom from pivot up to mid, then upper boom to tip
        $midX = round($pivX + ($tipX - $pivX) * 0.55);
        $midY = round($pivY - ($pivY - $tipY) * 0.40);

        // Basket (platform) at tip
        $bskW = 14; $bskH = 10;
        $bskX = $tipX - $bskW / 2;
        $bskY = $tipY - $bskH;

        // Vertical dashed line for height — directly below basket tip
        $vArX  = (int) min($tipX, 170);

        // Horizontal dashed arrow for radio (below ground)
        $rLblY   = $gndY + 14;
        $midLblX = round(($pivX + $tipX) / 2);

        // Wheels
        $wR = 5;
        $w1X = $baseX + 8; $w2X = $baseX + $baseW - 8;

        // Cabin/turntable box
        $cabW = 12; $cabH = 8;
        $cabX = $pivX - $cabW / 2; $cabY = $pivY - $cabH;

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 180 140" style="width:163px;height:127px;display:block">
  <!-- Ground line -->
  <line x1="0" y1="{$gndY}" x2="180" y2="{$gndY}" stroke="#6b7280" stroke-width="1.5"/>
  <!-- Base chassis -->
  <rect x="{$baseX}" y="{$baseY}" width="{$baseW}" height="{$baseH}" rx="2" fill="#374151" stroke="#111827" stroke-width="0.8"/>
  <!-- Wheels -->
  <circle cx="{$w1X}" cy="{$gndY}" r="{$wR}" fill="#1f2937" stroke="#374151" stroke-width="0.8"/>
  <circle cx="{$w2X}" cy="{$gndY}" r="{$wR}" fill="#1f2937" stroke="#374151" stroke-width="0.8"/>
  <!-- Cabin / turntable -->
  <rect x="{$cabX}" y="{$cabY}" width="{$cabW}" height="{$cabH}" rx="2" fill="#4b5563" stroke="#111827" stroke-width="0.7"/>
  <!-- Lower boom arm -->
  <line x1="{$pivX}" y1="{$pivY}" x2="{$midX}" y2="{$midY}" stroke="#1e5fa8" stroke-width="4" stroke-linecap="round"/>
  <!-- Upper boom arm -->
  <line x1="{$midX}" y1="{$midY}" x2="{$tipX}" y2="{$tipY}" stroke="#1e5fa8" stroke-width="3" stroke-linecap="round"/>
  <!-- Pivot circle -->
  <circle cx="{$pivX}" cy="{$pivY}" r="4" fill="#fbbf24" stroke="#92400e" stroke-width="1"/>
  <!-- Basket platform -->
  <rect x="{$bskX}" y="{$bskY}" width="{$bskW}" height="{$bskH}" rx="2" fill="#dbeafe" stroke="#1e5fa8" stroke-width="1.2"/>
  <!-- Worker icon in basket -->
  <circle cx="{$tipX}" cy="{$bskY}" r="3.5" fill="#fbbf24" stroke="#92400e" stroke-width="0.8"/>
  <!-- Vertical dashed line for height -->
  <line x1="{$vArX}" y1="{$tipY}" x2="{$vArX}" y2="{$gndY}" stroke="#374151" stroke-width="1" stroke-dasharray="4,3"/>
  <!-- Height label -->
  <rect x="{$vArX}" y="{$tipY}" width="36" height="14" rx="3" fill="#f0fdf4" stroke="#16a34a" stroke-width="0.8" transform="translate(-18,2)"/>
  <text x="{$vArX}" y="{$tipY}" dy="12" text-anchor="middle" font-family="Arial,sans-serif" font-size="8" font-weight="700" fill="#166534">h = {$hF}</text>
  <!-- Horizontal dashed line for radius -->
  <line x1="{$pivX}" y1="{$rLblY}" x2="{$tipX}" y2="{$rLblY}" stroke="#1e5fa8" stroke-width="1" stroke-dasharray="4,3"/>
  <!-- Radius label -->
  <rect x="{$midLblX}" y="{$rLblY}" width="40" height="14" rx="3" fill="#dbeafe" stroke="#1e5fa8" stroke-width="0.8" transform="translate(-20,-7)"/>
  <text x="{$midLblX}" y="{$rLblY}" dy="5" dx="-18" font-family="Arial,sans-serif" font-size="7.5" font-weight="700" fill="#1e5fa8">r = {$rF}</text>
  <!-- Type label -->
  <text x="90" y="134" text-anchor="middle" font-family="Arial,sans-serif" font-size="7" fill="#6b7280">Plataforma Articulada / Boom</text>
</svg>
SVG;
    }

    /** Normaliza texto: minúsculas, sin acentos ni ñ, sin espacios extremos. */
    private function normalizarTexto(string $s): string {
        $s = mb_strtolower(trim($s), 'UTF-8');
        return strtr($s, [
            'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n',
            'Á'=>'a','É'=>'e','Í'=>'i','Ó'=>'o','Ú'=>'u','Ü'=>'u','Ñ'=>'n',
        ]);
    }

    /** Reemplaza cada div.foto-placeholder en el HTML del dictamen con la imagen real en base64. */
    private function inyectarFotosDictamen(string $html, string $evidenciaUrl): string {
        if (!$evidenciaUrl) return $html;

        // Resolver ruta local
        $localPath = rtrim(str_replace(
            rtrim(UPLOAD_URL, '/'),
            rtrim(UPLOAD_DIR, '/'),
            rtrim($evidenciaUrl, '/')
        ), '/') . '/';

        if (!is_dir($localPath)) return $html;

        $archivos = glob($localPath . '*.{jpg,jpeg,png,JPG,JPEG,PNG,webp,WEBP}', GLOB_BRACE);
        if (empty($archivos)) return $html;

        sort($archivos);
        $archivos = array_slice($archivos, 0, 9);

        // Convertir todas las fotos a base64
        $fotos = [];
        foreach ($archivos as $path) {
            if (!file_exists($path)) continue;
            $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $mime = in_array($ext, ['png']) ? 'image/png' : (in_array($ext, ['webp']) ? 'image/webp' : 'image/jpeg');
            $fotos[] = ['mime' => $mime, 'b64' => base64_encode(file_get_contents($path))];
        }
        if (empty($fotos)) return $html;

        $total = count($fotos);

        // Siempre reconstruir como cuadrícula general (sin sub-títulos ni descripciones)
        $filas = '';
        foreach (array_chunk($fotos, 3) as $fila) {
            $filas .= '<tr>';
            foreach ($fila as $f) {
                $filas .= '<td class="foto-card" style="padding:4px;text-align:center;vertical-align:middle;">'
                        . "<img src=\"data:{$f['mime']};base64,{$f['b64']}\" "
                        . 'style="max-width:100%;max-height:120px;object-fit:contain;display:block;margin:0 auto;">'
                        . '</td>';
            }
            // Rellenar celdas vacías en la última fila
            $vacias = 3 - count($fila);
            for ($i = 0; $i < $vacias; $i++) {
                $filas .= '<td class="foto-card" style="background:#f9fafb;"></td>';
            }
            $filas .= '</tr>';
        }

        $nuevaSeccion = '<table width="100%" cellpadding="0" cellspacing="0" '
                      . 'style="border-collapse:collapse;border:1px solid #cdd8e3;">'
                      . $filas . '</table>';

        // Reemplazar el bloque de fotos usando strpos (sin regex sobre HTML gigante con base64)
        // Busca desde <div class="foto-section-title"> hasta el </table> de foto-grid-ganchos
        $marcaInicio = '<div class="foto-section-title"';
        $marcaCierre = 'class="foto-grid-ganchos"';
        $posInicio   = strpos($html, $marcaInicio);
        if ($posInicio !== false) {
            $posCierre = strpos($html, $marcaCierre, $posInicio);
            if ($posCierre !== false) {
                // Avanzar hasta el </table> después del marcador
                $posTablaFin = strpos($html, '</table>', $posCierre);
                if ($posTablaFin !== false) {
                    $html = substr($html, 0, $posInicio)
                          . $nuevaSeccion
                          . substr($html, $posTablaFin + 8); // 8 = strlen('</table>')
                }
            }
        }

        // Actualizar el contador de evidencias en el título de sección
        $html = str_replace(
            '9 Evidencias visuales de la inspección',
            "{$total} Evidencias visuales de la inspección",
            $html
        );

        return $html;
    }

    /** Genera el HTML de las filas del checklist para inyectar en plantillas de dictamen. */
    private function buildDictamenChecklistRows(array $items): string {
        if (empty($items)) return '<tr><td colspan="2" style="padding:6px 12px;font-size:8pt;color:#64748b">Sin ítems registrados</td></tr>';

        $rows = '';
        $seccionActual = '';
        foreach ($items as $item) {
            $sec  = htmlspecialchars($item['seccion_nombre'] ?? '', ENT_QUOTES, 'UTF-8');
            $desc = htmlspecialchars($item['descripcion']   ?? '', ENT_QUOTES, 'UTF-8');
            $val  = $item['valor'] ?? '';

            if ($val === 'C')        { $label = '✓ CONFORME';     $color = '#1a7a4a'; $bg = '#e6f7ef'; }
            elseif ($val === 'NC')   { $label = '✗ NO CONFORME';  $color = '#c62828'; $bg = '#fdf3f3'; }
            else                     { $label = 'N/A';             $color = '#64748b'; $bg = '#f9fafb'; }

            if ($sec !== $seccionActual) {
                $rows .= "<tr><td colspan=\"2\" style=\"background:#0B2545;color:#fff;font-weight:700;"
                       . "font-size:7.5pt;padding:3px 10px;letter-spacing:.5px;\">{$sec}</td></tr>\n";
                $seccionActual = $sec;
            }
            $rows .= "<tr style=\"background:{$bg}\">"
                   . "<td style=\"padding:3px 10px;font-size:8pt;border-bottom:1px solid #e8edf4;width:70%\">{$desc}</td>"
                   . "<td style=\"padding:3px 10px;font-size:8pt;font-weight:700;color:{$color};"
                   . "border-bottom:1px solid #e8edf4;text-align:center\">{$label}</td>"
                   . "</tr>\n";
        }
        return $rows;
    }

    /**
     * Quita del certificado el bloque de horquillas cuando el equipo no las
     * tiene capturadas.
     *
     * La plantilla es la misma para toda la maquinaria, así que el bloque va
     * delimitado por comentarios y se recorta entero: dejarlo con los campos
     * vacíos pondría un apartado "HORQUILLAS" en blanco en el certificado de
     * una grúa, que no las lleva.
     */
    private function recortarBloqueHorquillas(string $html, array $d): string {
        $horq = !empty($d['horquillas']) ? (json_decode($d['horquillas'], true) ?? []) : [];
        $hay  = false;
        foreach (['capacidad', 'centro_gravedad', 'serie', 'marca'] as $c) {
            if (trim((string)($horq[$c] ?? '')) !== '') { $hay = true; break; }
        }
        if ($hay) {
            // Se conserva el bloque, pero sin las marcas: no tienen por qué
            // viajar dentro del documento que recibe el cliente.
            return str_replace(['<!--BLOQUE_HORQUILLAS-->', '<!--/BLOQUE_HORQUILLAS-->'], '', $html);
        }

        return preg_replace(
            '/<!--BLOQUE_HORQUILLAS-->.*?<!--\/BLOQUE_HORQUILLAS-->/s', '', $html
        ) ?? $html;
    }

    /** Devuelve [html_normas_acreditadas, html_normas_referencia] para el tipo de maquinaria. */
    private function obtenerNormasHtml(string $maquinaria): array {
        if (!$maquinaria) return ['—', '—'];
        $stmt = $this->pdo->prepare(
            "SELECT mn.norma, mn.tipo
             FROM maquinaria_normas mn
             INNER JOIN maquinaria_tipos t ON t.id = mn.maquinaria_tipo_id
             WHERE t.nombre = ? ORDER BY mn.tipo, mn.norma"
        );
        $stmt->execute([$maquinaria]);
        $rows = $stmt->fetchAll();

        $acred = array_filter($rows, fn($r) => ($r['tipo'] ?? 'acreditada') !== 'referencia');
        $ref   = array_filter($rows, fn($r) => ($r['tipo'] ?? '') === 'referencia');

        $fmt = fn($arr) => implode(' · ', array_map(
            fn($r) => htmlspecialchars($r['norma'], ENT_QUOTES, 'UTF-8'),
            $arr
        )) ?: '—';

        return [$fmt($acred), $fmt($ref)];
    }

    /**
     * Carga la plantilla de certificado correcta, sustituye los tokens {campo} y retorna HTML listo para mPDF.
     * El tipo de plantilla se resuelve así (mayor a menor prioridad):
     *   1. $d['plantilla_tipo']  — pasado explícitamente por el llamador
     *   2. $_SESSION['plantilla_tipo'] — selección guardada por Calidad
     *   3. 'equipos' — fallback por defecto
     */
    private function renderCertTemplate(array $d, string $qrB64, bool $sinSellos = false): string {
        $registros    = require __DIR__ . '/../config/plantillas.php';
        $tipoPlantilla = $d['plantilla_tipo']
            ?? ($_SESSION['plantilla_tipo'] ?? null)
            ?? 'equipos';
        if (!isset($registros[$tipoPlantilla])) $tipoPlantilla = 'equipos';

        $templatePath = __DIR__ . '/../' . $registros[$tipoPlantilla]['archivo'];
        if (!file_exists($templatePath)) {
            return $this->htmlCertificado($d, $qrB64);
        }

        $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

        $vigencia = '';
        if (!empty($d['fecha_inspeccion'])) {
            try {
                $fv = new \DateTime($d['fecha_inspeccion']);
                $fv->modify('+1 year');
                $vigencia = $fv->format('d/m/Y');
            } catch (\Exception $ex) {}
        }

        $noAcreditacion = defined('NO_ACREDITACION') ? NO_ACREDITACION : 'UVNMX 057';

        // Mapa común + campos específicos por tipo
        $map = [
            '{folio}'           => $e(formatoFolio($d['control'] ?? '')),
            '{no_acreditacion}' => $e($noAcreditacion),
            '{qr_imagen}'       => $qrB64,
        ];

        if ($tipoPlantilla === 'personal') {
            $map += [
                '{participante}'  => $e($d['participante']  ?? $d['nombre']     ?? ''),
                '{puesto}'        => $e($d['puesto']         ?? $d['cargo']      ?? ''),
                '{curp}'          => $e($d['curp']           ?? ''),
                '{fecha_emision}' => $e($d['fecha_fmt']      ?? ''),
                '{programa}'      => $e($d['programa']       ?? $d['curso']      ?? ''),
                '{empresa}'       => $e($d['empresa']        ?? $d['cliente']    ?? ''),
            ];
        } elseif ($tipoPlantilla === 'accesorios') {
            $map += [
                '{cliente}'          => $e($d['cliente']       ?? ''),
                '{domicilio}'        => $e($d['direccion']     ?? ''),
                '{resumen_items}'    => $e($d['resumen_items'] ?? $d['descripcion'] ?? ''),
                '{fecha_inspeccion}' => $e($d['fecha_fmt']     ?? ''),
                '{vigencia}'         => $e($vigencia),
            ];
        } else { // equipos (default)
            $map += [
                '{cliente}'           => $e($d['cliente']    ?? ''),
                '{domicilio}'         => $e($d['direccion']  ?? ''),
                '{tipo_maquinaria}'   => $e($d['maquinaria'] ?? ''),
                '{capacidad}'         => $e($d['capacidad']  ?? ''),
                '{marca}'             => $e($d['marca']       ?? ''),
                '{modelo}'            => $e($d['modelo']      ?? ''),
                '{no_serie}'          => $e($d['serie']       ?? ''),
                '{no_identificacion}' => $e($d['id_equipo']  ?? ''),
                '{fecha_inspeccion}'  => $e($d['fecha_fmt']  ?? ''),
                '{vigencia}'          => $e($vigencia),
            ];

            // Normas del alcance: el certificado debe decir bajo qué se
            // inspeccionó, igual que ya lo hace el dictamen.
            [$normasAcred, $normasRef] = $this->obtenerNormasHtml($d['maquinaria'] ?? '');
            $map['{{normas_acreditadas}}'] = $normasAcred;
            $map['{{normas_referencia}}']  = $normasRef;

            // Horquillas: sólo las llevan montacargas y telehandlers, así que
            // sus campos se suman aquí y el bloque se recorta más abajo cuando
            // el equipo no tiene ninguno capturado.
            $horq = !empty($d['horquillas']) ? (json_decode($d['horquillas'], true) ?? []) : [];
            $map['{horquilla_marca}']     = $e($horq['marca']           ?? '');
            $map['{horquilla_serie}']     = $e($horq['serie']           ?? '');
            $map['{horquilla_capacidad}'] = $e($horq['capacidad']       ?? '');
            $map['{horquilla_cg}']        = $e($horq['centro_gravedad'] ?? '');
        }

        $html = file_get_contents($templatePath);
        $html = $this->recortarBloqueHorquillas($html, $d);
        $html = str_replace(array_keys($map), array_values($map), $html);

        if ($sinSellos) {
            $html = preg_replace('/<img[^>]*class=["\'][^"\']*avba-sello[^"\']*["\'][^>]*\/?>/i', '', $html);
            $html = str_replace('</head>', '<style>.avba-sello{display:none!important;}</style></head>', $html);
        }

        return $html;
    }

    /**
     * Convierte HTML a PDF con mPDF (para plantillas CSS avanzadas como certificado_preview.html).
     * @return string  Ruta absoluta al .pdf generado
     */
    private function htmlToPdfMpdf(string $html, string $folio, string $sufijo = 'CERT'): string {
        if (!class_exists('\\Mpdf\\Mpdf')) {
            $autoload = __DIR__ . '/../vendor/autoload.php';
            if (file_exists($autoload)) require_once $autoload;
        }
        if (!class_exists('\\Mpdf\\Mpdf')) {
            throw new \RuntimeException('mPDF no disponible. Verifica vendor/autoload.php.');
        }

        $rutaDir = UPLOAD_DIR . 'certificados/';
        if (!is_dir($rutaDir)) mkdir($rutaDir, 0755, true);

        $config = [
            'mode'          => 'utf-8',
            'format'        => [215, 279],
            'margin_left'   => 0, 'margin_right'  => 0,
            'margin_top'    => 0, 'margin_bottom' => 0,
            'margin_header' => 0, 'margin_footer' => 0,
            'dpi'           => 96,
            'default_font'  => 'dejavusans',
            'tempDir'       => sys_get_temp_dir() . '/mpdf',
        ];

        $mpdf = new \Mpdf\Mpdf($config);
        $mpdf->SetBasePath(__DIR__ . '/../');
        $mpdf->SetHTMLFooter('');
        $mpdf->use_kwt          = false;
        $mpdf->useSubstitutions = true;

        // Elevar pcre.backtrack_limit: el HTML incluye imágenes base64 que superan el límite por defecto (1M)
        $prevBacktrack = (int) ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', 10000000);

        // Escribir el HTML completo de una sola llamada — mismo método que generar_dictamen_web.php
        // (dividir CSS+body por separado dejaba bodyHtml vacío en plantillas complejas)
        $mpdf->WriteHTML($html);

        ini_set('pcre.backtrack_limit', $prevBacktrack);

        $mpdf->SetProtection(['print'], '', 'Avba@Cert2024!');

        $nombre  = $sufijo . '_AVBA_' . $folio . '.pdf';
        $destino = $rutaDir . $nombre;
        $mpdf->Output($destino, 'F');
        return $destino;
    }

    /**
     * Para envío: intenta primero plantilla PDF configurada; si no existe, genera desde HTML.
     * $tipo acepta 'certificado'|'cert' para certificado, 'dictamen'|'dict' para dictamen.
     * Devuelve la ruta local al PDF generado.
     */
    private function resolverPdfEnvio(string $tipo, int $id, array $datos): string {
        $esDict = in_array($tipo, ['dict', 'dictamen'], true);

        // Si hay un PDF reemplazado manualmente, adjuntar ese en lugar de regenerar
        $manual = $this->pdfManual($id, $esDict ? 'dict' : 'cert');
        if ($manual !== '') return $manual;

        $tipoTemplate = $esDict ? 'dict'      : 'certificado';
        $tipoHtml     = $esDict ? 'dictamen'  : 'certificado';
        $sufijo       = $esDict ? 'DICT'      : 'CERT';

        $resultado = $this->generarPdfDesdeTemplatePdf($id, $tipoTemplate);
        if ($resultado['status'] === 'success') {
            $folio = $datos['control'] ?? (string)$id;
            return UPLOAD_DIR . 'certificados/' . $sufijo . '_PDF_' . $folio . '.pdf';
        }
        return $this->resolverPdf($tipoHtml, $datos);
    }

    /**
     * Genera la imagen QR internamente (sin red) y la devuelve como cadena
     * base64 para embeber en HTML.
     */
    private function descargarQrB64(string $qrCodigo): string {
        if (!$qrCodigo) {
            throw new \RuntimeException(
                'El registro no tiene código QR asignado. Verifica el registro en la base de datos.'
            );
        }
        $b64 = qrDataUri(textoQR($qrCodigo));
        if ($b64 === '') {
            throw new \RuntimeException('No se pudo generar el código QR.');
        }
        return $b64;
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
        file_put_contents($destino, protegerPdf($dompdf->output()));
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
    private function gestionarCredencialesCliente(string $clienteNombre, string $correo): array {
        // Buscar primera_parte del cliente y cero-padear a 5 dígitos (igual que el control)
        $stmt = $this->pdo->prepare(
            "SELECT primera_parte FROM clientes WHERE nombre_cliente = ? LIMIT 1"
        );
        $stmt->execute([$clienteNombre]);
        $clienteRow = $stmt->fetch();
        $idCliente  = $clienteRow
            ? str_pad((string)($clienteRow['primera_parte'] ?? ''), 5, '0', STR_PAD_LEFT)
            : '';

        // Generar contraseña segura aleatoria
        $chars    = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        $password = '';
        for ($i = 0; $i < 10; $i++) $password .= $chars[random_int(0, strlen($chars) - 1)];
        $hash = password_hash($password, PASSWORD_BCRYPT);

        // Si ya existe un usuario CLIENTE para este cliente, actualizar su contraseña
        if ($idCliente) {
            $stmt = $this->pdo->prepare(
                "SELECT id, usuario FROM usuarios WHERE rol = 'CLIENTE' AND id_cliente = ? LIMIT 1"
            );
            $stmt->execute([$idCliente]);
            $existing = $stmt->fetch();

            if ($existing) {
                $this->pdo->prepare("UPDATE usuarios SET password_hash = ? WHERE id = ?")
                    ->execute([$hash, $existing['id']]);
                return ['usuario' => $existing['usuario'], 'password' => $password, 'es_nuevo' => false];
            }
        }

        // Username = primera_parte con cero-padding (ej. 00042); fallback a random si no hay id
        $usuario = $idCliente ?: ('cli' . str_pad((string)random_int(1, 99999), 5, '0', STR_PAD_LEFT));

        // Garantizar unicidad del username
        $raiz   = $usuario;
        $sufijo = 1;
        while (true) {
            $chk = $this->pdo->prepare("SELECT id FROM usuarios WHERE usuario = ? LIMIT 1");
            $chk->execute([$usuario]);
            if (!$chk->fetch()) break;
            $usuario = $raiz . $sufijo++;
        }

        $this->pdo->prepare(
            "INSERT INTO usuarios (usuario, password_hash, rol, nombre, id_cliente, activo)
             VALUES (?, ?, 'CLIENTE', ?, ?, 1)"
        )->execute([$usuario, $hash, strtoupper($clienteNombre), $idCliente ?: null]);

        return ['usuario' => $usuario, 'password' => $password, 'es_nuevo' => true];
    }

    private function enviarCorreo(string $to, string $cliente, string $folio, string $tipoDocs, array $adjuntos, array $credenciales = []): void {
        $mail = new PHPMailer(true);

        $cfg = getSmtpConfig($this->pdo);
        configurarMailer($mail, $this->pdo);

        // Soporta múltiples destinatarios separados por coma
        foreach (array_filter(array_map('trim', explode(',', $to))) as $addr) {
            if (filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                $mail->addAddress($addr);
            }
        }

        $asunto = trim($cfg['asunto_cert'] ?? '');
        if ($asunto) {
            $asunto = str_replace(['{folio}', '{cliente}'], [$folio, $cliente], $asunto);
        } else {
            $asunto = "Certificado de Inspección AVBA — Folio {$folio}";
        }
        $mail->Subject = $asunto;
        $mail->isHTML(true);
        $mail->Body    = $this->plantillaCorreo($cliente, $folio, $tipoDocs, $credenciales);

        foreach ($adjuntos as $ruta => $nombre) {
            $mail->addAttachment($ruta, $nombre);
        }

        $mail->send();
    }

    private function plantillaCorreo(string $cliente, string $folio, string $tipoDocs, array $credenciales = []): string {
        $cfg         = getSmtpConfig($this->pdo);
        $introConfig = trim($cfg['cuerpo_intro'] ?? '');

        if ($introConfig) {
            $introEscaped = htmlspecialchars($introConfig);
            $introEscaped = str_replace(
                ['{folio}', '{cliente}', '{tipoDocs}'],
                [$folio, htmlspecialchars($cliente), $tipoDocs],
                $introEscaped
            );
            $introHtml = "<p style=\"font-size:14px;color:#5a6072;line-height:1.7;margin:0 0 20px\">"
                       . nl2br($introEscaped) . "</p>";
        } else {
            $introHtml = "<p style=\"font-size:14px;color:#5a6072;line-height:1.7;margin:0 0 20px\">
        Adjuntamos su <strong>{$tipoDocs}</strong> de inspección con folio
        <strong style=\"color:#1B2A6B\">{$folio}</strong>,
        el cual acredita que el equipo inspeccionado cumple con los criterios técnicos y de seguridad aplicables.
      </p>";
        }

        // Bloque de credenciales de acceso al portal
        $credsHtml = '';
        if (!empty($credenciales)) {
            $portalUrl  = rtrim(defined('SITE_URL') ? SITE_URL : '', '/') . '/portal-cliente.html';
            $esNuevo    = $credenciales['es_nuevo'] ?? false;
            $textoTitulo = $esNuevo
                ? 'Se ha creado su cuenta de acceso al portal de clientes AVBA:'
                : 'Sus credenciales de acceso al portal de clientes AVBA:';
            $usr = htmlspecialchars($credenciales['usuario'] ?? '');
            $pwd = htmlspecialchars($credenciales['password'] ?? '');
            $url = htmlspecialchars($portalUrl);
            $credsHtml = "
      <div style=\"margin-top:20px;padding:16px 18px;background:#F0F7ED;border-left:4px solid #2e7d32;border-radius:4px\">
        <p style=\"font-size:13px;color:#1b5e20;font-weight:bold;margin:0 0 10px\">{$textoTitulo}</p>
        <p style=\"font-size:13px;color:#333;margin:4px 0\"><strong>Usuario:</strong> {$usr}</p>
        <p style=\"font-size:13px;color:#333;margin:4px 0\"><strong>Contraseña:</strong> {$pwd}</p>
        <p style=\"font-size:13px;color:#555;margin:12px 0 0\">Acceda al portal en: <a href=\"{$url}\" style=\"color:#1B2A6B\">{$url}</a></p>
      </div>";
        }

        $cuerpo = "
      <p style=\"font-size:15px;color:#1a1a2e;margin:0 0 12px\">Estimado(a) cliente <strong>" . htmlspecialchars($cliente) . "</strong>,</p>
      {$introHtml}
      <div style=\"background:#E6F1FB;border-radius:8px;padding:14px 18px;margin-bottom:20px\">
        <p style=\"font-size:13px;color:#0C447C;margin:0\"><strong>Folio:</strong> {$folio}</p>
        <p style=\"font-size:12px;color:#1B2A6B;margin:6px 0 0\">Vigencia: 1 año a partir de la fecha de emisión</p>
      </div>
      {$credsHtml}";
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
                    $color   = str_pad(ltrim($campo['color'] ?? '000000', '#'), 6, '0', STR_PAD_LEFT);
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

            file_put_contents($rufaPdf, protegerPdf($pdf->Output('S')));
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
                    $color   = str_pad(ltrim($campo['color'] ?? '000000', '#'), 6, '0', STR_PAD_LEFT);
                    $fuente  = ['Helvetica' => 'Helvetica', 'Times' => 'Times', 'Courier' => 'Courier'][$campo['fuente'] ?? ''] ?? 'Helvetica';

                    [$r, $g, $b] = sscanf($color, '%02x%02x%02x');
                    $pdf->SetTextColor($r ?? 0, $g ?? 0, $b ?? 0);

                    if ($nombreCampo === 'qr_imagen') {
                        $qrCodigo = $valoresResueltos['qr_codigo'] ?? '';
                        if ($qrCodigo) {
                            $alto   = (float)($campo['alto'] ?? $ancho ?: 25);
                            $qrTmp  = sys_get_temp_dir() . '/avba_qr_prev_' . uniqid() . '.png';
                            $qrData = qrCodigoPngBytes($qrCodigo);
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

            file_put_contents($rutaPdf, protegerPdf($pdf->Output('S')));
            return ['status' => 'success', 'url' => UPLOAD_URL . 'certificados/' . basename($rutaPdf)];

        } catch (\Exception $e) {
            $comp = fpdiMsgCompresion($e);
            return ['status' => 'error', 'message' => $comp ?? ('Error generando vista previa: ' . $e->getMessage())];
        }
    }
}
