<?php
/**
 * AVBA Certificaciones — Envío de documentos desde el portal del cliente
 *
 * El cliente elige qué documentos mandar y a qué correo; el envío sale por el
 * SMTP de AVBA configurado en el perfil principal, no por una cuenta suya.
 *
 * Seguridad: NUNCA se acepta una ruta de archivo desde el navegador. El cliente
 * manda pares (tipo, id) y aquí se resuelve el documento contra la base,
 * comprobando siempre que pertenezca a su empresa. De lo contrario bastaría con
 * cambiar una URL para hacerse enviar documentos de otro cliente.
 */

class ClienteEnvios {
    private PDO $pdo;

    /** Tope de adjuntos y de peso total, para no reventar el buzón de destino. */
    private const MAX_ADJUNTOS = 40;
    private const MAX_BYTES    = 18 * 1024 * 1024;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * @param array $p    correo, docs[] => ['tipo'=>..., 'id'=>...], mensaje
     * @param array $usr  usuario autenticado (aporta el id_cliente)
     */
    public function enviar(array $p, array $usr): array {
        $idCliente = trim((string)($usr['id_cliente'] ?? ''));
        if ($idCliente === '') return ['status' => 'error', 'message' => 'Tu cuenta no tiene empresa asociada.'];
        if (ctype_digit($idCliente)) $idCliente = str_pad($idCliente, 5, '0', STR_PAD_LEFT);

        // ── Destinatarios ────────────────────────────────
        $destinos = [];
        foreach (preg_split('/[,;\s]+/', (string)($p['correo'] ?? '')) as $c) {
            $c = trim($c);
            if ($c === '') continue;
            if (!filter_var($c, FILTER_VALIDATE_EMAIL)) {
                return ['status' => 'error', 'message' => "Correo inválido: $c"];
            }
            $destinos[] = $c;
        }
        if (!$destinos)          return ['status' => 'error', 'message' => 'Indica al menos un correo de destino.'];
        if (count($destinos) > 5) return ['status' => 'error', 'message' => 'Máximo 5 destinatarios por envío.'];

        $docs = $p['docs'] ?? [];
        if (!is_array($docs) || !$docs) return ['status' => 'error', 'message' => 'No se indicó ningún documento.'];

        // ── Resolver documentos, siempre contra la base ──
        $adjuntos = [];   // ruta absoluta => nombre visible
        $omitidos = [];
        $bytes    = 0;
        foreach ($docs as $d) {
            $tipo = (string)($d['tipo'] ?? '');
            $id   = (int)($d['id'] ?? 0);
            if (!$tipo || !$id) continue;

            foreach ($this->resolver($tipo, $id, $idCliente) as $nombre => $rel) {
                $abs = $this->rutaLocal($rel);
                if ($abs === '') { $omitidos[] = $nombre; continue; }
                if (isset($adjuntos[$abs]))   continue;               // ya incluido
                if (count($adjuntos) >= self::MAX_ADJUNTOS) {
                    $omitidos[] = $nombre; continue;
                }
                $tam = filesize($abs) ?: 0;
                if ($bytes + $tam > self::MAX_BYTES) { $omitidos[] = $nombre; continue; }
                $adjuntos[$abs] = $this->nombreArchivo($nombre, $abs);
                $bytes += $tam;
            }
        }

        if (!$adjuntos) {
            return ['status' => 'error', 'message' =>
                'No se encontró ningún documento disponible para enviar. '
                . 'Puede que aún no se haya emitido o que ya no esté en el servidor.'];
        }

        // ── Enviar ───────────────────────────────────────
        try {
            $this->enviarCorreo($destinos, $adjuntos,
                (string)($usr['nombre'] ?? ''), trim((string)($p['mensaje'] ?? '')));
        } catch (\Throwable $e) {
            error_log('[ClienteEnvios] ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'No se pudo enviar el correo: ' . $e->getMessage()];
        }

        $this->registrar($usr, $destinos, $adjuntos);

        $msg = count($adjuntos) . ' documento(s) enviado(s) a ' . implode(', ', $destinos) . '.';
        if ($omitidos) $msg .= ' No se pudieron adjuntar: ' . implode(', ', array_slice($omitidos, 0, 5))
                             . (count($omitidos) > 5 ? '…' : '') . '.';

        return ['status' => 'success', 'message' => $msg,
                'enviados' => count($adjuntos), 'omitidos' => count($omitidos)];
    }

    /**
     * Documentos de un elemento, ya comprobado que es del cliente.
     * Devuelve [nombre visible => ruta relativa o URL guardada].
     */
    private function resolver(string $tipo, int $id, string $idCliente): array {
        $like = $idCliente . '-%';
        $out  = [];

        try {
            switch ($tipo) {

                case 'equipo':      // certificado + dictamen de una máquina
                    $s = $this->pdo->prepare(
                        "SELECT control, maquinaria, serie, certificado_url, dictamen_url
                         FROM equipos WHERE id = ? AND control LIKE ? AND estado = 'ENVIADO'"
                    );
                    $s->execute([$id, $like]);
                    if ($r = $s->fetch(PDO::FETCH_ASSOC)) {
                        $base = $this->etiqueta($r['control'], $r['maquinaria'] ?: 'Equipo', $r['serie']);
                        if ($r['certificado_url']) $out["Certificado $base"] = $r['certificado_url'];
                        if ($r['dictamen_url'])    $out["Dictamen $base"]    = $r['dictamen_url'];
                    }
                    break;

                case 'accesorio':   // sesión de accesorios de izaje
                    $s = $this->pdo->prepare(
                        "SELECT control, cert_url, informe_url, informe_cumple_url
                         FROM accesorios_sesiones WHERE id = ? AND control LIKE ?"
                    );
                    $s->execute([$id, $like]);
                    if ($r = $s->fetch(PDO::FETCH_ASSOC)) {
                        $base = $this->etiqueta($r['control'], 'Accesorios');
                        if ($r['cert_url'])           $out["Certificado $base"]     = $r['cert_url'];
                        if ($r['informe_url'])        $out["Reporte $base"]         = $r['informe_url'];
                        if ($r['informe_cumple_url']) $out["Reporte CUMPLE $base"]  = $r['informe_cumple_url'];
                    }
                    break;

                case 'arnes':       // dictamen del lote + certificado de cada pieza
                    $s = $this->pdo->prepare(
                        "SELECT id, control, dictamen_url FROM arneses_sesiones
                         WHERE id = ? AND control LIKE ? AND estatus = 'EMITIDO'"
                    );
                    $s->execute([$id, $like]);
                    if ($r = $s->fetch(PDO::FETCH_ASSOC)) {
                        $base = $this->etiqueta($r['control'], 'Arneses');
                        if ($r['dictamen_url']) $out["Dictamen $base"] = $r['dictamen_url'];
                        $pz = $this->pdo->prepare(
                            "SELECT serie, cert_url FROM arneses_items
                             WHERE sesion_id = ? AND cert_url IS NOT NULL AND cert_url <> '' ORDER BY orden, id"
                        );
                        $pz->execute([$id]);
                        foreach ($pz->fetchAll(PDO::FETCH_ASSOC) as $n => $q) {
                            $out['Certificado ' . ($q['serie'] ?: ('pieza ' . ($n + 1)))] = $q['cert_url'];
                        }
                    }
                    break;

                case 'arnes_pieza': // certificado de una pieza suelta
                    $s = $this->pdo->prepare(
                        "SELECT i.serie, i.cert_url, se.control
                         FROM arneses_items i JOIN arneses_sesiones se ON se.id = i.sesion_id
                         WHERE i.id = ? AND se.control LIKE ?"
                    );
                    $s->execute([$id, $like]);
                    if (($r = $s->fetch(PDO::FETCH_ASSOC)) && $r['cert_url']) {
                        $out['Certificado ' . ($r['serie'] ?: $r['control'])] = $r['cert_url'];
                    }
                    break;

                case 'personal':    // constancias de un participante
                    $s = $this->pdo->prepare(
                        "SELECT control, nombre_completo FROM participantes_cursos
                         WHERE id = ? AND control LIKE ?"
                    );
                    $s->execute([$id, $like]);
                    if ($r = $s->fetch(PDO::FETCH_ASSOC)) {
                        $quien = $r['nombre_completo'] ?: $r['control'];
                        $dc = $this->pdo->prepare(
                            "SELECT tipo_doc, url FROM participantes_documentos
                             WHERE participante_id = ? ORDER BY fecha_generacion DESC"
                        );
                        $dc->execute([$id]);
                        $vistos = [];
                        foreach ($dc->fetchAll(PDO::FETCH_ASSOC) as $q) {
                            $t = strtoupper((string)$q['tipo_doc']);
                            if (isset($vistos[$t])) continue;   // sólo el más reciente de cada tipo
                            $vistos[$t] = true;
                            $out["$t $quien"] = $q['url'];
                        }
                    }
                    break;

                case 'pnd':         // reporte de prueba no destructiva
                    $s = $this->pdo->prepare(
                        "SELECT control, componente, reporte_url FROM pnd_inspecciones
                         WHERE id = ? AND control LIKE ?"
                    );
                    $s->execute([$id, $like]);
                    if (($r = $s->fetch(PDO::FETCH_ASSOC)) && $r['reporte_url']) {
                        $out['Reporte PND ' . $this->etiqueta($r['control'], $r['componente'] ?: '')] = $r['reporte_url'];
                    }
                    break;

                // ── Documentos que sube el propio cliente ──
                case 'doc_equipo':
                    $out = $this->docPropio('cliente_equipos_docs', 'equipo_id', 'cliente_equipos', $id, $idCliente, 'nombre');
                    break;
                case 'cert_equipo':
                    $out = $this->docPropio('cliente_equipos_cert', 'equipo_id', 'cliente_equipos', $id, $idCliente, 'tipo_cert');
                    break;
                case 'doc_personal':
                    $out = $this->docPropio('cliente_personal_docs', 'personal_id', 'cliente_personal', $id, $idCliente, 'nombre');
                    break;
                case 'cert_personal':
                    $out = $this->docPropio('cliente_personal_cert', 'personal_id', 'cliente_personal', $id, $idCliente, 'tipo_cert');
                    break;
            }
        } catch (\PDOException $e) {
            error_log('[ClienteEnvios] resolver ' . $tipo . ': ' . $e->getMessage());
        }

        return $out;
    }

    /**
     * Documento cargado por el cliente. Se comprueba que su equipo o persona
     * pertenezcan a la empresa de quien pide el envío.
     */
    private function docPropio(string $tabla, string $fk, string $tablaPadre,
                               int $id, string $idCliente, string $campoNombre): array {
        $s = $this->pdo->prepare(
            "SELECT d.`$campoNombre` AS nombre, d.archivo_url
             FROM `$tabla` d
             JOIN `$tablaPadre` p ON p.id = d.`$fk`
             WHERE d.id = ? AND p.id_cliente = ?"
        );
        $s->execute([$id, $idCliente]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        if (!$r || empty($r['archivo_url'])) return [];
        return [(string)($r['nombre'] ?: 'Documento') => $r['archivo_url']];
    }

    /** "AB.00042-00017-2026MX · Grúa · S/N 123" */
    private function etiqueta(?string $control, string $que, ?string $serie = null): string {
        $partes = [];
        if ($control) $partes[] = function_exists('formatoFolio') ? formatoFolio($control) : $control;
        if ($que !== '') $partes[] = $que;
        if ($serie)      $partes[] = 'S/N ' . $serie;
        return implode(' · ', $partes);
    }

    /** Ruta local de un documento a partir de la URL o ruta guardada. */
    private function rutaLocal(?string $url): string {
        $url = trim((string)$url);
        if ($url === '') return '';
        $rel = ltrim(str_replace(rtrim(SITE_URL, '/'), '', $url), '/');
        $rel = ltrim(parse_url($rel, PHP_URL_PATH) ?: $rel, '/');
        // Nunca salir de la carpeta del proyecto
        if (strpos($rel, '..') !== false) return '';
        $abs = realpath(__DIR__ . '/../' . $rel);
        $raiz = realpath(__DIR__ . '/..');
        if ($abs === false || $raiz === false || strpos($abs, $raiz) !== 0) return '';
        return is_file($abs) ? $abs : '';
    }

    /** Nombre legible para el adjunto, conservando su extensión real. */
    private function nombreArchivo(string $etiqueta, string $abs): string {
        $ext    = strtolower(pathinfo($abs, PATHINFO_EXTENSION)) ?: 'pdf';
        $limpio = preg_replace('/[^\p{L}\p{N} ._-]+/u', '', $etiqueta);
        $limpio = trim(preg_replace('/\s+/', ' ', (string)$limpio));
        if ($limpio === '') $limpio = 'Documento';
        return mb_substr($limpio, 0, 90) . '.' . $ext;
    }

    protected function enviarCorreo(array $destinos, array $adjuntos, string $cliente, string $mensaje): void {
        if (!class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
            $autoload = __DIR__ . '/../vendor/autoload.php';
            if (file_exists($autoload)) require_once $autoload;
        }
        if (!class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
            throw new \RuntimeException('PHPMailer no disponible.');
        }

        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        configurarMailer($mail, $this->pdo);            // SMTP de AVBA del perfil principal
        foreach ($destinos as $d) $mail->addAddress($d);

        $n = count($adjuntos);
        $mail->Subject = 'Documentos de inspección AVBA' . ($cliente ? ' — ' . $cliente : '');
        $mail->isHTML(true);

        $lista = '';
        foreach ($adjuntos as $nombre) {
            $lista .= '<li style="font-size:13px;color:#5a6072;margin-bottom:3px">'
                    . htmlspecialchars($nombre) . '</li>';
        }
        $nota = $mensaje !== ''
            ? '<div style="margin:16px 0;padding:12px 14px;background:#f5f8fd;border-left:3px solid #185FA5;border-radius:4px">
                 <p style="font-size:13px;color:#1a1a2e;margin:0;white-space:pre-line">'
                 . htmlspecialchars($mensaje) . '</p></div>'
            : '';

        $cuerpo = '
      <p style="font-size:15px;color:#1a1a2e;margin:0 0 12px">Estimado(a),</p>
      <p style="font-size:14px;color:#5a6072;line-height:1.7;margin:0 0 16px">'
        . ($cliente ? '<strong>' . htmlspecialchars($cliente) . '</strong> te comparte ' : 'Se te comparten ')
        . $n . ' documento(s) de inspección emitidos por AVBA Inspections.</p>'
        . $nota .
      '<ul style="margin:0 0 16px;padding-left:20px">' . $lista . '</ul>
      <p style="font-size:12px;color:#7a8494;margin:0">
        Cada documento puede verificarse escaneando el código QR que lleva impreso.
      </p>';

        $mail->Body = function_exists('plantillaCorreoHtml')
            ? plantillaCorreoHtml($this->pdo, $cuerpo)
            : $cuerpo;

        foreach ($adjuntos as $ruta => $nombre) $mail->addAttachment($ruta, $nombre);
        $mail->send();
    }

    /** Deja constancia del envío, para saber quién mandó qué y a dónde. */
    protected function registrar(array $usr, array $destinos, array $adjuntos): void {
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS cliente_envios (
                  id          INT AUTO_INCREMENT PRIMARY KEY,
                  id_cliente  VARCHAR(10)  NULL,
                  usuario     VARCHAR(100) NULL,
                  destinos    VARCHAR(500) NULL,
                  documentos  TEXT         NULL,
                  total       INT          NOT NULL DEFAULT 0,
                  fecha       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  KEY idx_cli (id_cliente)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $this->pdo->prepare(
                "INSERT INTO cliente_envios (id_cliente, usuario, destinos, documentos, total)
                 VALUES (?,?,?,?,?)"
            )->execute([
                $usr['id_cliente'] ?? null,
                $usr['usuario']    ?? null,
                mb_substr(implode(', ', $destinos), 0, 500),
                mb_substr(implode(' | ', array_values($adjuntos)), 0, 60000),
                count($adjuntos),
            ]);
        } catch (\Throwable $e) {
            error_log('[ClienteEnvios] registrar: ' . $e->getMessage());
        }
    }
}
