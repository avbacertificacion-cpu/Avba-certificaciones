<?php
/**
 * AVBA Certificaciones — Servicios directos
 *
 * Expediente aparte, con su propio control de calidad y su propia gente. Quien
 * entra aquí no entra al sistema de siempre y al revés; la separación se aplica
 * en el enrutador (exigirModulo en api/index.php).
 *
 * Vive en sus propias tablas a propósito. Compartir la de equipos habría
 * ahorrado código, pero entonces cada una de las casi cuarenta consultas del
 * sistema principal tendría que acordarse de excluir estos registros: bastaría
 * olvidar una para que un expediente apareciera donde no debe, y en silencio.
 * Con tablas propias el aislamiento es la conducta por omisión.
 *
 * Lo que sí se comparte, porque debe ser idéntico:
 *   · el banco de códigos QR, para que dos certificados nunca lleven el mismo;
 *   · la validación pública, porque un certificado se valida sin importar de
 *     qué expediente salió;
 *   · las plantillas del documento, vía Certificaciones::pdfDesdeDatos().
 *
 * Aquí una sola persona captura, revisa y emite. Por eso cada paso queda
 * firmado en la bitácora con quién y cuándo: es lo único que permite reconstruir
 * después qué pasó, y sin eso esto sería una libreta y no un control de calidad.
 */
class ServiciosDirectos {

    /** Captura → revisión → emisión. Sólo se avanza de uno en uno. */
    private const ESTADOS = ['CAPTURA', 'REVISADO', 'EMITIDO'];

    private const EXT_FOTO = ['jpg', 'jpeg', 'png', 'webp'];
    private const MAX_FOTO = 10 * 1024 * 1024;
    private const MAX_FOTOS_POR_SERVICIO = 12;

    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->migrate();
    }

    private function migrate(): void {
        try {
            // Los nombres de columna son los mismos que usa la plantilla del
            // certificado, para poder reutilizarla tal cual.
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS sd_servicios (
                  id               INT AUTO_INCREMENT PRIMARY KEY,
                  control          VARCHAR(30)  NULL,
                  cliente          VARCHAR(200) NOT NULL,
                  direccion        VARCHAR(300) NULL,
                  maquinaria       VARCHAR(120) NULL,
                  marca            VARCHAR(120) NULL,
                  modelo           VARCHAR(120) NULL,
                  serie            VARCHAR(120) NULL,
                  capacidad        VARCHAR(80)  NULL,
                  id_equipo        VARCHAR(80)  NULL,
                  horquillas       TEXT         NULL,
                  fecha_inspeccion DATE         NULL,
                  resultado        VARCHAR(20)  NOT NULL DEFAULT 'CUMPLE',
                  observaciones    TEXT         NULL,
                  checklist        TEXT         NULL,
                  qr_codigo        VARCHAR(20)  NULL,
                  estado           VARCHAR(20)  NOT NULL DEFAULT 'CAPTURA',
                  cert_url         VARCHAR(500) NULL,
                  usuario          VARCHAR(80)  NULL,
                  fecha_emision    DATETIME     NULL,
                  created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  UNIQUE KEY uq_sd_qr (qr_codigo)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS sd_fotos (
                  id          INT AUTO_INCREMENT PRIMARY KEY,
                  servicio_id INT          NOT NULL,
                  url         VARCHAR(500) NOT NULL,
                  orden       INT          NOT NULL DEFAULT 1,
                  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  KEY idx_sd_foto (servicio_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS sd_bitacora (
                  id          INT AUTO_INCREMENT PRIMARY KEY,
                  servicio_id INT          NOT NULL,
                  usuario     VARCHAR(80)  NULL,
                  papel       VARCHAR(30)  NULL,
                  accion      VARCHAR(60)  NOT NULL,
                  detalle     TEXT         NULL,
                  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  KEY idx_sd_bit (servicio_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\Throwable $e) {
            // Nunca debe tumbar el arranque: todas las clases se construyen
            // antes de enrutar, incluso para el login.
            error_log('[ServiciosDirectos] migrate: ' . $e->getMessage());
        }
    }

    // ── Consulta ─────────────────────────────────────────────

    public function listar(string $estado = '', string $q = ''): array {
        $where = [];
        $params = [];
        $estado = strtoupper(trim($estado));
        if (in_array($estado, self::ESTADOS, true)) { $where[] = 's.estado = ?'; $params[] = $estado; }
        $q = trim($q);
        if ($q !== '') {
            $where[] = '(s.cliente LIKE ? OR s.control LIKE ? OR s.serie LIKE ? OR s.qr_codigo LIKE ? OR s.maquinaria LIKE ?)';
            $like = "%$q%";
            array_push($params, $like, $like, $like, $like, $like);
        }
        $sql = "SELECT s.id, s.control, s.cliente, s.maquinaria, s.marca, s.modelo, s.serie,
                       s.capacidad, s.id_equipo, s.resultado, s.estado, s.qr_codigo, s.cert_url,
                       s.usuario, s.direccion, s.observaciones,
                       DATE_FORMAT(s.fecha_inspeccion,'%d/%m/%Y') AS fecha,
                       s.fecha_inspeccion AS fecha_iso,
                       DATE_FORMAT(s.fecha_emision,'%d/%m/%Y %H:%i') AS emitido_el,
                       COUNT(f.id) AS total_fotos
                FROM sd_servicios s
                LEFT JOIN sd_fotos f ON f.servicio_id = s.id";
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= " GROUP BY s.id ORDER BY s.fecha_inspeccion DESC, s.id DESC";

        try {
            $st = $this->pdo->prepare($sql);
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('[ServiciosDirectos] listar: ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'No se pudo leer el expediente.'];
        }

        $servicios = array_map(fn($r) => [
            'id'          => (int)$r['id'],
            'control'     => (string)($r['control'] ?? ''),
            'cliente'     => (string)$r['cliente'],
            'direccion'   => (string)($r['direccion'] ?? ''),
            'maquinaria'  => (string)($r['maquinaria'] ?? ''),
            'marca'       => (string)($r['marca'] ?? ''),
            'modelo'      => (string)($r['modelo'] ?? ''),
            'serie'       => (string)($r['serie'] ?? ''),
            'capacidad'   => (string)($r['capacidad'] ?? ''),
            'id_equipo'   => (string)($r['id_equipo'] ?? ''),
            'resultado'   => (string)$r['resultado'],
            'estado'      => (string)$r['estado'],
            'qr_codigo'   => (string)($r['qr_codigo'] ?? ''),
            'cert_url'    => (string)($r['cert_url'] ?? ''),
            'usuario'     => (string)($r['usuario'] ?? ''),
            'observaciones' => (string)($r['observaciones'] ?? ''),
            'fecha'       => (string)($r['fecha'] ?? ''),
            'fecha_iso'   => (string)($r['fecha_iso'] ?? ''),
            'emitido_el'  => (string)($r['emitido_el'] ?? ''),
            'total_fotos' => (int)$r['total_fotos'],
        ], $rows);

        $cuenta = fn(string $e) => count(array_filter($servicios, fn($s) => $s['estado'] === $e));
        return [
            'status'    => 'success',
            'servicios' => $servicios,
            'resumen'   => [
                'captura'  => $cuenta('CAPTURA'),
                'revisado' => $cuenta('REVISADO'),
                'emitido'  => $cuenta('EMITIDO'),
                'no_cumple'=> count(array_filter($servicios, fn($s) => $s['resultado'] === 'NO CUMPLE')),
            ],
        ];
    }

    public function detalle(int $id): array {
        $fila = $this->cargar($id);
        if (!$fila) return ['status' => 'error', 'message' => 'Ese servicio ya no existe.'];

        $f = $this->pdo->prepare("SELECT id, url, orden FROM sd_fotos WHERE servicio_id = ? ORDER BY orden, id");
        $f->execute([$id]);
        $fotos = array_map(fn($x) => [
            'id'  => (int)$x['id'],
            'url' => rtrim(SITE_URL, '/') . '/' . ltrim((string)$x['url'], '/'),
        ], $f->fetchAll(PDO::FETCH_ASSOC));

        $b = $this->pdo->prepare(
            "SELECT usuario, papel, accion, detalle, DATE_FORMAT(created_at,'%d/%m/%Y %H:%i') AS cuando
             FROM sd_bitacora WHERE servicio_id = ? ORDER BY id DESC"
        );
        $b->execute([$id]);

        $fila['checklist'] = $fila['checklist'] ? (json_decode((string)$fila['checklist'], true) ?: []) : [];
        $fila['fecha']     = $fila['fecha_inspeccion']
            ? date('d/m/Y', strtotime((string)$fila['fecha_inspeccion'])) : '';
        $fila['cert_url']  = $fila['cert_url']
            ? rtrim(SITE_URL, '/') . '/' . ltrim((string)$fila['cert_url'], '/') : '';

        return [
            'status'   => 'success',
            'servicio' => $fila,
            'fotos'    => $fotos,
            'bitacora' => $b->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    /** Una placa libre del banco compartido, para que ningún código se repita. */
    public function siguienteQr(): array {
        $qr = function_exists('siguienteQrAccesorio') ? siguienteQrAccesorio($this->pdo) : '';
        if ($qr === '') {
            return ['status' => 'error', 'message' =>
                'No hay placas libres en el banco de códigos. Carga el lote nuevo o captura el código a mano.'];
        }
        return ['status' => 'success', 'qr' => $qr];
    }

    // ── Captura ──────────────────────────────────────────────

    public function guardar(array $p, string $usuario): array {
        $cliente = trim((string)($p['cliente'] ?? ''));
        if ($cliente === '') return ['status' => 'error', 'message' => 'Indica el cliente.'];

        $id  = (int)($p['id'] ?? 0);
        $fila = $id ? $this->cargar($id) : null;
        if ($id && !$fila) return ['status' => 'error', 'message' => 'Ese servicio ya no existe.'];
        // Un expediente ya emitido no se edita: el documento en manos del
        // cliente dejaría de corresponder con lo guardado.
        if ($fila && $fila['estado'] === 'EMITIDO')
            return ['status' => 'error', 'message' => 'Ya está emitido. Regrésalo a revisión para poder cambiarlo.'];

        $fecha = $this->fechaIso((string)($p['fecha_inspeccion'] ?? ''));
        if (($p['fecha_inspeccion'] ?? '') !== '' && $fecha === null)
            return ['status' => 'error', 'message' => 'La fecha de inspección no es válida.'];

        $resultado = strtoupper(trim((string)($p['resultado'] ?? 'CUMPLE')));
        if (!in_array($resultado, ['CUMPLE', 'NO CUMPLE'], true)) $resultado = 'CUMPLE';

        $qr = trim((string)($p['qr_codigo'] ?? ''));
        if ($qr !== '') {
            if (!qrFormatoValido($qr)) return ['status' => 'error', 'message' => qrMensajeFormato()];
            if (!$this->qrLibre($qr, $id)) return ['status' => 'error', 'message' => 'Ese código QR ya está en uso.'];
        }

        $campos = [
            'cliente'          => mb_strtoupper($cliente),
            'direccion'        => trim((string)($p['direccion'] ?? '')),
            'maquinaria'       => trim((string)($p['maquinaria'] ?? '')),
            'marca'            => mb_strtoupper(trim((string)($p['marca'] ?? ''))),
            'modelo'           => mb_strtoupper(trim((string)($p['modelo'] ?? ''))),
            'serie'            => mb_strtoupper(trim((string)($p['serie'] ?? ''))),
            'capacidad'        => trim((string)($p['capacidad'] ?? '')),
            'id_equipo'        => mb_strtoupper(trim((string)($p['id_equipo'] ?? ''))),
            'fecha_inspeccion' => $fecha,
            'resultado'        => $resultado,
            'observaciones'    => trim((string)($p['observaciones'] ?? '')),
            'checklist'        => $this->normalizarChecklist($p['checklist'] ?? []),
            'qr_codigo'        => $qr !== '' ? $qr : null,
        ];

        try {
            if ($id) {
                $sets = implode(',', array_map(fn($c) => "$c=?", array_keys($campos)));
                $this->pdo->prepare("UPDATE sd_servicios SET $sets WHERE id=?")
                    ->execute([...array_values($campos), $id]);
                $this->anotar($id, $usuario, 'INSPECTOR', 'Editó la captura');
            } else {
                $campos['usuario'] = $usuario;
                $campos['estado']  = 'CAPTURA';
                $campos['control'] = $this->siguienteControl();
                $cols = implode(',', array_keys($campos));
                $ph   = implode(',', array_fill(0, count($campos), '?'));
                $this->pdo->prepare("INSERT INTO sd_servicios ($cols) VALUES ($ph)")
                    ->execute(array_values($campos));
                $id = (int)$this->pdo->lastInsertId();
                $this->anotar($id, $usuario, 'INSPECTOR', 'Capturó el servicio', $campos['cliente']);
            }
            $this->ocuparQr($qr, (string)($fila['qr_codigo'] ?? ''));
        } catch (\Throwable $e) {
            error_log('[ServiciosDirectos] guardar: ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'No se pudo guardar el servicio.'];
        }

        return ['status' => 'success', 'id' => $id, 'message' => 'Servicio guardado.'];
    }

    public function eliminar(int $id, string $usuario, string $motivo = ''): array {
        $fila = $this->cargar($id);
        if (!$fila) return ['status' => 'error', 'message' => 'Ese servicio ya no existe.'];

        foreach ($this->urlsFotos($id) as $u) $this->borrarArchivo($u);
        $this->liberarQr((string)($fila['qr_codigo'] ?? ''));

        $this->pdo->prepare("DELETE FROM sd_fotos WHERE servicio_id=?")->execute([$id]);
        $this->pdo->prepare("DELETE FROM sd_bitacora WHERE servicio_id=?")->execute([$id]);
        $this->pdo->prepare("DELETE FROM sd_servicios WHERE id=?")->execute([$id]);

        if (function_exists('registrarEliminacion')) {
            registrarEliminacion(
                $this->pdo, $usuario ?: 'sistema', "sd#$id",
                'Servicio directo ' . ($fila['control'] ?: 's/folio') . ' — ' . $fila['cliente']
                    . ' — estado ' . $fila['estado'],
                $motivo
            );
        }
        return ['status' => 'success', 'message' => 'Servicio eliminado.'];
    }

    // ── Flujo ────────────────────────────────────────────────

    /**
     * Avanza un paso. Aquí la misma persona captura, revisa y emite, así que
     * lo único que queda de la separación de funciones es el rastro: cada paso
     * anota quién lo dio, con qué papel y cuándo.
     */
    public function avanzar(int $id, string $usuario, string $nota = ''): array {
        $fila = $this->cargar($id);
        if (!$fila) return ['status' => 'error', 'message' => 'Ese servicio ya no existe.'];

        if ($fila['estado'] === 'CAPTURA') {
            $faltan = $this->loQueFalta($fila);
            if ($faltan) return ['status' => 'error', 'message' => 'Falta ' . implode(', ', $faltan) . '.'];
            $this->pdo->prepare("UPDATE sd_servicios SET estado='REVISADO' WHERE id=?")->execute([$id]);
            $this->anotar($id, $usuario, 'CALIDAD', 'Revisó y aprobó', $nota);
            return ['status' => 'success', 'estado' => 'REVISADO', 'message' => 'Revisado. Ya se puede emitir.'];
        }
        if ($fila['estado'] === 'REVISADO') return $this->emitir($id, $usuario);
        return ['status' => 'error', 'message' => 'Ya está emitido.'];
    }

    /** Devuelve el expediente al paso anterior, dejando constancia. */
    public function regresar(int $id, string $usuario, string $motivo = ''): array {
        $fila = $this->cargar($id);
        if (!$fila) return ['status' => 'error', 'message' => 'Ese servicio ya no existe.'];
        if ($fila['estado'] === 'CAPTURA') return ['status' => 'error', 'message' => 'Ya está en captura.'];

        $motivo = trim($motivo);
        if ($motivo === '')
            return ['status' => 'error', 'requiere_motivo' => true, 'message' => 'Indica por qué se regresa.'];

        $nuevo = $fila['estado'] === 'EMITIDO' ? 'REVISADO' : 'CAPTURA';
        $this->pdo->prepare("UPDATE sd_servicios SET estado=? WHERE id=?")->execute([$nuevo, $id]);
        $this->anotar($id, $usuario, 'CALIDAD', "Regresó a $nuevo", $motivo);
        return ['status' => 'success', 'estado' => $nuevo, 'message' => "Regresado a $nuevo."];
    }

    /**
     * Emite el certificado. Usa las plantillas del sistema de siempre: el
     * documento debe ser el mismo, salga del expediente que salga.
     */
    public function emitir(int $id, string $usuario): array {
        $fila = $this->cargar($id);
        if (!$fila) return ['status' => 'error', 'message' => 'Ese servicio ya no existe.'];
        if ($fila['estado'] === 'CAPTURA')
            return ['status' => 'error', 'message' => 'Primero hay que revisarlo.'];

        $faltan = $this->loQueFalta($fila);
        if ($faltan) return ['status' => 'error', 'message' => 'Falta ' . implode(', ', $faltan) . '.'];

        try {
            $datos = $fila;
            $datos['fecha_fmt']      = $fila['fecha_inspeccion'] ? date('d/m/Y', strtotime((string)$fila['fecha_inspeccion'])) : '';
            $datos['plantilla_tipo'] = 'equipos';

            // Se carga aquí y no arriba: este módulo también se instancia desde
            // la validación pública, donde no hace falta el generador.
            if (!class_exists('Certificaciones')) require_once __DIR__ . '/Certificaciones.php';
            $cert = new Certificaciones($this->pdo);
            $ruta = $cert->pdfDesdeDatos($datos, 'certificado');
            $url  = 'uploads/' . basename($ruta);
            // El generador deja el archivo donde guarda los del sistema; se
            // conserva su ruta relativa tal como la publica el resto.
            $rel  = $this->relativoDeSitio($ruta) ?: $url;
        } catch (\Throwable $e) {
            error_log('[ServiciosDirectos] emitir: ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'No se pudo generar el certificado: ' . $e->getMessage()];
        }

        $this->pdo->prepare("UPDATE sd_servicios SET estado='EMITIDO', cert_url=?, fecha_emision=NOW() WHERE id=?")
            ->execute([$rel, $id]);
        $this->anotar($id, $usuario, 'CERTIFICACIONES', 'Emitió el certificado', $fila['control'] ?: '');

        return [
            'status'  => 'success',
            'estado'  => 'EMITIDO',
            'url'     => rtrim(SITE_URL, '/') . '/' . ltrim($rel, '/'),
            'message' => 'Certificado emitido.',
        ];
    }

    // ── Fotos ────────────────────────────────────────────────

    public function subirFoto(array $post, array $files): array {
        $id = (int)($post['servicio_id'] ?? 0);
        $fila = $this->cargar($id);
        if (!$fila) return ['status' => 'error', 'message' => 'Ese servicio ya no existe.'];
        if ($fila['estado'] === 'EMITIDO')
            return ['status' => 'error', 'message' => 'Ya está emitido; regrésalo a revisión para cambiar la evidencia.'];

        $cnt = $this->pdo->prepare("SELECT COUNT(*) FROM sd_fotos WHERE servicio_id=?");
        $cnt->execute([$id]);
        if ((int)$cnt->fetchColumn() >= self::MAX_FOTOS_POR_SERVICIO)
            return ['status' => 'error', 'message' => 'Ya tiene el máximo de fotos.'];

        $a = $files['archivo'] ?? [];
        if (empty($a['tmp_name'])) return ['status' => 'error', 'message' => 'Elige la foto.'];
        if (($a['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK)
            return ['status' => 'error', 'message' => 'La foto no llegó completa. Vuelve a intentarlo.'];

        $ext = strtolower(pathinfo((string)($a['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, self::EXT_FOTO, true)) return ['status' => 'error', 'message' => 'Sólo se aceptan imágenes.'];
        if (($a['size'] ?? 0) > self::MAX_FOTO) return ['status' => 'error', 'message' => 'La foto no debe pasar de 10 MB.'];

        $rel = "sd/$id";
        $dir = rtrim(UPLOAD_DIR, '/') . "/$rel/";
        if (!is_dir($dir) && !@mkdir($dir, 0755, true))
            return ['status' => 'error', 'message' => 'No se pudo preparar la carpeta del expediente.'];

        $base = 'sd_' . $id . '_' . date('YmdHis') . '_' . random_int(100, 999);
        $fn   = "$base.jpg";
        $real = comprimirImagen($a['tmp_name'], $dir . $fn, 1600, 1600, 75);
        if ($real) {
            $fn = $real;
        } else {
            $fn = "$base.$ext";
            if (!$this->guardarSubida($a['tmp_name'], $dir . $fn))
                return ['status' => 'error', 'message' => 'No se pudo guardar la foto.'];
        }

        $ord = $this->pdo->prepare("SELECT COALESCE(MAX(orden),0)+1 FROM sd_fotos WHERE servicio_id=?");
        $ord->execute([$id]);
        $url = "uploads/$rel/$fn";
        $this->pdo->prepare("INSERT INTO sd_fotos (servicio_id, url, orden) VALUES (?,?,?)")
            ->execute([$id, $url, (int)$ord->fetchColumn()]);

        return [
            'status' => 'success',
            'id'     => (int)$this->pdo->lastInsertId(),
            'url'    => rtrim(SITE_URL, '/') . '/' . $url,
            'message'=> 'Foto agregada.',
        ];
    }

    public function eliminarFoto(int $id): array {
        $s = $this->pdo->prepare("SELECT url FROM sd_fotos WHERE id=?");
        $s->execute([$id]);
        $u = $s->fetchColumn();
        if ($u === false) return ['status' => 'error', 'message' => 'Esa foto ya no existe.'];
        $this->borrarArchivo((string)$u);
        $this->pdo->prepare("DELETE FROM sd_fotos WHERE id=?")->execute([$id]);
        return ['status' => 'success', 'message' => 'Foto eliminada.'];
    }

    // ── Validación pública ───────────────────────────────────

    /**
     * Un certificado se valida sin importar de qué expediente salió: quien
     * escanea la placa no tiene por qué saber cómo se facturó el servicio, ni
     * le sirve de nada. Devuelve los mismos campos que el resto de módulos.
     */
    public function buscarPorQr(string $qr, bool $esFolio = false): ?array {
        $qr = trim($qr);
        if ($qr === '') return null;
        // El folio de este expediente no tiene la forma del principal, así que
        // la decisión se toma aquí y no se hereda de quien llama.
        if (preg_match('/^SD-\d{4}-\d+$/i', $qr)) $esFolio = true;
        try {
            $col = $esFolio ? 'control' : 'qr_codigo';
            $st  = $this->pdo->prepare(
                "SELECT maquinaria, marca, modelo, serie, capacidad, resultado, cliente,
                        fecha_inspeccion, estado
                 FROM sd_servicios WHERE $col = ? AND estado = 'EMITIDO' LIMIT 1"
            );
            $st->execute([$qr]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            // El módulo puede no estar instalado todavía; la validación del
            // resto no debe caerse por eso.
            return null;
        }
        if (!$r) return null;

        $v = calcularVigencia($r['fecha_inspeccion']);
        return [
            'status'  => 'ok',
            'existe'  => true,
            'modulo'  => 'equipo',
            'vigente' => $v['vigente'],
            'dias'    => $v['dias'],
            'datos'   => [
                'titulo'      => 'Certificado de Maquinaria',
                'maquinaria'  => $r['maquinaria'],
                'marca'       => $r['marca'],
                'modelo'      => $r['modelo'],
                'serie'       => $r['serie'],
                'capacidad'   => $r['capacidad'],
                'resultado'   => $r['resultado'],
                'fecha'       => $r['fecha_inspeccion'],
            ],
        ];
    }

    // ── Apoyo ────────────────────────────────────────────────

    private function cargar(int $id): ?array {
        if ($id <= 0) return null;
        $s = $this->pdo->prepare("SELECT * FROM sd_servicios WHERE id=?");
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Lo que impide dar por bueno un expediente. */
    private function loQueFalta(array $f): array {
        $faltan = [];
        if (trim((string)($f['maquinaria'] ?? '')) === '')       $faltan[] = 'el tipo de equipo';
        if (trim((string)($f['serie'] ?? '')) === '')            $faltan[] = 'el número de serie';
        if (empty($f['fecha_inspeccion']))                        $faltan[] = 'la fecha de inspección';
        if (trim((string)($f['qr_codigo'] ?? '')) === '')        $faltan[] = 'el código QR';
        return $faltan;
    }

    /** Folio propio, con su prefijo, para no chocar con el del sistema. */
    private function siguienteControl(): string {
        try {
            $n = (int)$this->pdo->query("SELECT COUNT(*) FROM sd_servicios")->fetchColumn() + 1;
        } catch (\Throwable $e) { $n = 1; }
        return 'SD-' . date('Y') . '-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
    }

    private function anotar(int $id, string $usuario, string $papel, string $accion, string $detalle = ''): void {
        try {
            $this->pdo->prepare(
                "INSERT INTO sd_bitacora (servicio_id, usuario, papel, accion, detalle) VALUES (?,?,?,?,?)"
            )->execute([$id, $usuario, $papel, $accion, $detalle !== '' ? $detalle : null]);
        } catch (\Throwable $e) {
            error_log('[ServiciosDirectos] bitacora: ' . $e->getMessage());
        }
    }

    /**
     * El banco de códigos es el mismo del sistema principal a propósito: dos
     * certificados con la misma placa darían dos resultados distintos al
     * escanearla.
     */
    private function qrLibre(string $qr, int $excluirId = 0): bool {
        $st = $this->pdo->prepare("SELECT id FROM sd_servicios WHERE qr_codigo = ? AND id <> ? LIMIT 1");
        $st->execute([$qr, $excluirId]);
        if ($st->fetch()) return false;

        foreach ([
            ['qr_codigos', 'identificador', 'usado = 1'],
            ['equipos', 'qr_codigo', '1=1'],
            ['participantes_cursos', 'qr_codigo', '1=1'],
            ['accesorios_sesiones', 'qr_codigo', '1=1'],
            ['accesorios_izaje', 'qr_codigo', '1=1'],
            ['arneses_sesiones', 'qr_codigo', '1=1'],
            ['arneses_items', 'qr_codigo', '1=1'],
        ] as [$tabla, $col, $extra]) {
            try {
                $q = $this->pdo->prepare("SELECT id FROM `$tabla` WHERE `$col` = ? AND $extra LIMIT 1");
                $q->execute([$qr]);
                if ($q->fetch()) return false;
            } catch (\Throwable $e) { /* ese módulo aún no existe */ }
        }
        return true;
    }

    private function ocuparQr(string $nuevo, string $anterior = ''): void {
        $nuevo = trim($nuevo); $anterior = trim($anterior);
        if ($anterior !== '' && $anterior !== $nuevo) $this->liberarQr($anterior);
        if ($nuevo !== '' && function_exists('qrRegistrarUsado')) qrRegistrarUsado($this->pdo, $nuevo);
    }

    private function liberarQr(string $qr): void {
        if (trim($qr) === '') return;
        try {
            $this->pdo->prepare("UPDATE qr_codigos SET usado = 0, equipo_id = NULL WHERE identificador = ?")
                ->execute([$qr]);
        } catch (\Throwable $e) { error_log('[ServiciosDirectos] liberar QR: ' . $e->getMessage()); }
    }

    private function urlsFotos(int $id): array {
        $s = $this->pdo->prepare("SELECT url FROM sd_fotos WHERE servicio_id=?");
        $s->execute([$id]);
        return array_map('strval', $s->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    private function borrarArchivo(string $url): void {
        $real = $this->rutaAbsoluta($url);
        if ($real !== null && is_file($real)) @unlink($real);
    }

    /** Igual que en control de material: la ruta pública trae su prefijo. */
    private function rutaAbsoluta(string $url): ?string {
        $url = ltrim(trim($url), '/');
        if ($url === '') return null;
        $base = realpath(rtrim(UPLOAD_DIR, '/'));
        if (!$base) return null;
        if (str_starts_with($url, 'uploads/')) $url = substr($url, 8);
        $real = realpath($base . '/' . $url);
        return ($real && str_starts_with($real, $base . DIRECTORY_SEPARATOR)) ? $real : null;
    }

    /** Ruta del PDF relativa a la raíz del sitio, para poder publicarla. */
    private function relativoDeSitio(string $absoluta): ?string {
        $raiz = realpath(dirname(__DIR__));
        $real = realpath($absoluta);
        if (!$raiz || !$real || !str_starts_with($real, $raiz . DIRECTORY_SEPARATOR)) return null;
        return str_replace('\\', '/', substr($real, strlen($raiz) + 1));
    }

    private function guardarSubida(string $tmp, string $destino): bool {
        return is_uploaded_file($tmp) ? move_uploaded_file($tmp, $destino) : (bool)@rename($tmp, $destino);
    }

    /** El checklist se guarda como {tag: valor}, sin renglones vacíos. */
    private function normalizarChecklist($cl): ?string {
        if (is_string($cl)) $cl = json_decode($cl, true) ?: [];
        if (!is_array($cl) || !$cl) return null;
        $limpio = [];
        foreach ($cl as $k => $v) {
            $k = trim((string)$k);
            if ($k === '') continue;
            $limpio[$k] = is_scalar($v) ? trim((string)$v) : '';
        }
        return $limpio ? json_encode($limpio, JSON_UNESCAPED_UNICODE) : null;
    }

    /** Acepta aaaa-mm-dd y dd/mm/aaaa; null si la fecha no existe. */
    private function fechaIso(string $v): ?string {
        $v = trim($v);
        if ($v === '') return null;
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m))
            return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $v : null;
        if (preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{4})$#', $v, $m))
            return checkdate((int)$m[2], (int)$m[1], (int)$m[3])
                ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : null;
        return null;
    }
}
