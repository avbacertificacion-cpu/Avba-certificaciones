<?php
/**
 * AVBA Certificaciones — Impresión combinada de expedientes del cliente
 *
 * Permite armar UN SOLO PDF con los documentos que el usuario elija de una
 * persona o de un equipo, para poder imprimir el expediente de una sentada en
 * vez de abrir archivo por archivo.
 *
 * Cómo se arma el PDF:
 *   - PDFs  → se importan sus páginas con FPDI (llega con mPDF).
 *   - Imágenes → se colocan a página completa, ajustadas a la hoja.
 *
 * Sobre los PDFs que no se pueden importar: FPDI no abre documentos cifrados
 * (por ejemplo un certificado emitido por AVBA, que sale protegido contra
 * edición). En ese caso NO se cancela todo el trabajo: se inserta una hoja
 * indicando que ese documento debe imprimirse por separado y se continúa con
 * el resto, y además se devuelve la lista de omitidos para avisar al usuario.
 */
class ClienteImpresion {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    private function norm(string $id): string {
        $id = trim($id);
        return (ctype_digit($id)) ? str_pad($id, 5, '0', STR_PAD_LEFT) : $id;
    }

    /** Verifica que la persona/equipo pertenezca al cliente. */
    private function pertenece(string $tipo, int $id, string $idCliente): bool {
        $tabla = $tipo === 'personal' ? 'cliente_personal' : 'cliente_equipos';
        $s = $this->pdo->prepare("SELECT id FROM `$tabla` WHERE id = ? AND id_cliente = ?");
        $s->execute([$id, $idCliente]);
        return (bool)$s->fetch();
    }

    /**
     * Documentos disponibles para imprimir de una persona o equipo.
     * Devuelve una clave compuesta ("doc:12" / "cert:3") para que el frontend
     * marque exactamente lo que quiere sin ambigüedad entre las dos tablas.
     */
    public function listarDocumentos(string $tipo, int $id, string $idCliente): array {
        $idCliente = $this->norm($idCliente);
        $tipo = $tipo === 'personal' ? 'personal' : 'equipo';
        if (!$this->pertenece($tipo, $id, $idCliente)) {
            return ['status' => 'error', 'message' => 'Registro no encontrado.'];
        }

        $tDocs = $tipo === 'personal' ? 'cliente_personal_docs' : 'cliente_equipos_docs';
        $tCert = $tipo === 'personal' ? 'cliente_personal_cert' : 'cliente_equipos_cert';
        $fk    = $tipo === 'personal' ? 'personal_id' : 'equipo_id';
        $campoCert = $tipo === 'personal' ? 'tipo_cert' : 'tipo_cert';

        $out = [];

        $d = $this->pdo->prepare(
            "SELECT id, tipo_doc, nombre, archivo_url, DATE_FORMAT(vigencia,'%d/%m/%Y') AS vigencia
             FROM `$tDocs` WHERE $fk = ? AND archivo_url IS NOT NULL AND archivo_url <> ''
             ORDER BY tipo_doc, nombre"
        );
        $d->execute([$id]);
        foreach ($d->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'clave'    => 'doc:' . (int)$r['id'],
                'grupo'    => 'Documentos',
                'nombre'   => $r['nombre'],
                'detalle'  => trim(($r['tipo_doc'] ?? '') . ($r['vigencia'] ? ' · Vence ' . $r['vigencia'] : '')),
                'formato'  => $this->formatoDe($r['archivo_url']),
            ];
        }

        $c = $this->pdo->prepare(
            "SELECT id, $campoCert AS titulo, folio, archivo_url,
                    DATE_FORMAT(fecha_vigencia,'%d/%m/%Y') AS vigencia
             FROM `$tCert` WHERE $fk = ? AND archivo_url IS NOT NULL AND archivo_url <> ''
             ORDER BY fecha_vigencia DESC"
        );
        $c->execute([$id]);
        foreach ($c->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'clave'   => 'cert:' . (int)$r['id'],
                'grupo'   => 'Certificaciones',
                'nombre'  => $r['titulo'],
                'detalle' => trim(($r['folio'] ? 'Folio ' . $r['folio'] : '') . ($r['vigencia'] ? ' · Vence ' . $r['vigencia'] : '')),
                'formato' => $this->formatoDe($r['archivo_url']),
            ];
        }

        return ['status' => 'success', 'documentos' => $out];
    }

    private function formatoDe(string $url): string {
        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: $url, PATHINFO_EXTENSION));
        if ($ext === 'pdf') return 'pdf';
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) return 'imagen';
        return $ext ?: 'otro';
    }

    /** Convierte la URL guardada en una ruta local segura dentro de uploads/. */
    private function rutaLocal(string $url): ?string {
        $rel = preg_replace('#^https?://[^/]+/#i', '', trim($url));
        $rel = ltrim($rel, '/');
        if ($rel === '' || strpos($rel, '..') !== false) return null;
        $abs  = realpath(dirname(__DIR__) . '/' . $rel);
        $base = realpath(rtrim(UPLOAD_DIR, '/'));
        if (!$abs || !$base || strncmp($abs, $base, strlen($base)) !== 0) return null;
        return is_file($abs) ? $abs : null;
    }

    /**
     * Arma el PDF combinado con los documentos seleccionados.
     * @param array $claves ["doc:12","cert:3", ...] en el orden a imprimir
     */
    public function generarCombinado(string $tipo, int $id, array $claves, string $idCliente): array {
        $idCliente = $this->norm($idCliente);
        $tipo = $tipo === 'personal' ? 'personal' : 'equipo';
        if (!$this->pertenece($tipo, $id, $idCliente)) {
            return ['status' => 'error', 'message' => 'Registro no encontrado.'];
        }
        if (!$claves) return ['status' => 'error', 'message' => 'Selecciona al menos un documento.'];

        // Resolver las rutas de lo seleccionado, respetando el orden recibido
        $archivos = [];
        foreach ($claves as $clave) {
            [$k, $docId] = array_pad(explode(':', (string)$clave, 2), 2, null);
            $docId = (int)$docId;
            if (!$docId || !in_array($k, ['doc', 'cert'], true)) continue;

            if ($k === 'doc') {
                $tabla = $tipo === 'personal' ? 'cliente_personal_docs' : 'cliente_equipos_docs';
                $campoNombre = 'nombre';
            } else {
                $tabla = $tipo === 'personal' ? 'cliente_personal_cert' : 'cliente_equipos_cert';
                $campoNombre = 'tipo_cert';
            }
            $fk = $tipo === 'personal' ? 'personal_id' : 'equipo_id';

            $s = $this->pdo->prepare("SELECT $campoNombre AS nombre, archivo_url FROM `$tabla` WHERE id = ? AND $fk = ?");
            $s->execute([$docId, $id]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            if (!$r || empty($r['archivo_url'])) continue;

            $ruta = $this->rutaLocal($r['archivo_url']);
            if (!$ruta) continue;
            $archivos[] = ['nombre' => $r['nombre'], 'ruta' => $ruta, 'formato' => $this->formatoDe($r['archivo_url'])];
        }
        if (!$archivos) return ['status' => 'error', 'message' => 'No se encontró ninguno de los documentos seleccionados.'];

        if (!class_exists('\\setasign\\Fpdi\\Fpdi')) {
            $autoload = __DIR__ . '/../vendor/autoload.php';
            if (file_exists($autoload)) require_once $autoload;
        }
        if (!class_exists('\\setasign\\Fpdi\\Fpdi')) {
            return ['status' => 'error', 'message' => 'No está disponible la librería para combinar PDFs en el servidor.'];
        }

        $pdf = new \setasign\Fpdi\Fpdi();
        $pdf->SetCreator('AVBA Certificaciones');
        $pdf->SetAutoPageBreak(false);

        $omitidos = [];
        $paginas  = 0;

        foreach ($archivos as $a) {
            if ($a['formato'] === 'pdf') {
                try {
                    $n = $pdf->setSourceFile($a['ruta']);
                    for ($p = 1; $p <= $n; $p++) {
                        $tpl  = $pdf->importPage($p);
                        $spec = $pdf->getTemplateSize($tpl);
                        $pdf->AddPage($spec['width'] > $spec['height'] ? 'L' : 'P', [$spec['width'], $spec['height']]);
                        $pdf->useTemplate($tpl);
                        $paginas++;
                    }
                } catch (\Throwable $e) {
                    // Típicamente un PDF cifrado (certificado protegido). Se deja
                    // constancia en el documento y se sigue con los demás.
                    $omitidos[] = $a['nombre'];
                    $pdf->AddPage('P', 'A4');
                    $pdf->SetFont('Helvetica', 'B', 13);
                    $pdf->SetXY(20, 90);
                    $pdf->MultiCell(170, 8, $this->latin('Documento protegido'), 0, 'C');
                    $pdf->SetFont('Helvetica', '', 11);
                    $pdf->MultiCell(170, 7,
                        $this->latin('"' . $a['nombre'] . '" está protegido y no se puede combinar. '
                        . 'Ábrelo e imprímelo por separado desde el expediente.'), 0, 'C');
                    $paginas++;
                }
            } elseif ($a['formato'] === 'imagen') {
                $info = @getimagesize($a['ruta']);
                if (!$info) { $omitidos[] = $a['nombre']; continue; }
                [$w, $h] = $info;
                $horizontal = $w > $h;
                $pdf->AddPage($horizontal ? 'L' : 'P', 'A4');
                // Ajustar a la hoja conservando la proporción, con margen de 10 mm
                $hojaW = $horizontal ? 297 : 210;
                $hojaH = $horizontal ? 210 : 297;
                $escala = min(($hojaW - 20) / $w, ($hojaH - 20) / $h);
                $dw = $w * $escala; $dh = $h * $escala;
                $pdf->Image($a['ruta'], ($hojaW - $dw) / 2, ($hojaH - $dh) / 2, $dw, $dh);
                $paginas++;
            } else {
                $omitidos[] = $a['nombre'];
            }
        }

        if ($paginas === 0) {
            return ['status' => 'error', 'message' => 'Ninguno de los documentos seleccionados se pudo combinar.'];
        }

        $dir = rtrim(UPLOAD_DIR, '/') . '/impresiones/';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['status' => 'error', 'message' => 'No se pudo crear la carpeta de impresiones en el servidor.'];
        }
        // El id_cliente va en el nombre para que descargar.php pueda comprobar
        // que quien lo pide es de la misma empresa.
        $nombre = 'EXPEDIENTE_' . $idCliente . '_' . strtoupper($tipo) . '_' . $id . '_' . date('Ymd_His') . '.pdf';
        $destino = $dir . $nombre;

        // OJO: FPDI/FPDF recibe (destino, nombre) — al revés que mPDF, que usa
        // (nombre, destino). Invertirlos deja el archivo sin escribir.
        $pdf->Output('F', $destino);

        // No devolver una URL si el archivo no quedó escrito: el usuario veria
        // un 404 al abrirlo y parecería que el sistema funcionó.
        if (!is_file($destino) || filesize($destino) < 100) {
            error_log('[ClienteImpresion] no se escribió el PDF en ' . $destino);
            return ['status' => 'error', 'message' => 'El PDF no se pudo guardar en el servidor. Revisa los permisos de la carpeta uploads/impresiones.'];
        }

        // Se entrega por descargar.php y no como archivo estático: en este
        // hosting Apache no sirve los archivos recién creados bajo uploads/
        // (ya ocurrió con el informe de inspecciones y se resolvió igual).
        return [
            'status'   => 'success',
            'archivo'  => $nombre,
            'paginas'  => $paginas,
            'omitidos' => $omitidos,
        ];
    }

    /** FPDF trabaja en ISO-8859-1; se convierte para no perder los acentos. */
    private function latin(string $t): string {
        $c = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $t);
        return $c === false ? $t : $c;
    }
}
