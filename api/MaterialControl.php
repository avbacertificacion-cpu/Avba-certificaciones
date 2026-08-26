<?php
/**
 * AVBA Certificaciones — Control de material en planta
 *
 * Cuando entramos a una planta con material propio —equipo de prueba, dinamó-
 * metros, eslingas, herramienta— la planta emite un vale de ingreso. Ese papel
 * es lo único que acredita que el material es nuestro: sin él no sale por la
 * puerta. Aquí se guarda el vale, lo que se metió y, cuando se retira, el vale
 * de salida.
 *
 * Lo que de verdad importa del módulo no es archivar papeles sino saber qué
 * sigue dentro de una planta y desde cuándo: eso es lo que se olvida y lo que
 * acaba costando material.
 */
class MaterialControl {

    /** Un vale abierto es material que todavía está en la planta. */
    private const ESTADOS = ['DENTRO', 'RETIRADO'];

    /** Tipos de documento que acompañan a un vale. */
    private const TIPOS_DOC = ['vale_entrada', 'vale_salida', 'remision', 'foto', 'identificacion', 'otro'];

    /** Extensiones aceptadas: fotos del vale tomadas en la puerta, o el PDF. */
    private const EXT_OK = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

    private const MAX_BYTES = 10 * 1024 * 1024;

    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->migrate();
    }

    private function migrate(): void {
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS material_vales (
                  id             INT AUTO_INCREMENT PRIMARY KEY,
                  planta         VARCHAR(200) NOT NULL,
                  ubicacion      VARCHAR(200) NULL,
                  folio          VARCHAR(80)  NULL,
                  fecha_ingreso  DATE         NULL,
                  responsable    VARCHAR(200) NULL,
                  material       TEXT         NULL,
                  estado         VARCHAR(20)  NOT NULL DEFAULT 'DENTRO',
                  fecha_salida   DATE         NULL,
                  folio_salida   VARCHAR(80)  NULL,
                  notas          TEXT         NULL,
                  usuario        VARCHAR(80)  NULL,
                  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS material_vale_doc (
                  id          INT AUTO_INCREMENT PRIMARY KEY,
                  vale_id     INT          NOT NULL,
                  tipo_doc    VARCHAR(40)  NOT NULL DEFAULT 'otro',
                  nombre      VARCHAR(200) NOT NULL,
                  archivo_url VARCHAR(500) NULL,
                  notas       TEXT         NULL,
                  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  KEY idx_v (vale_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\Throwable $e) {
            // Nunca debe tumbar el arranque de la API: todas las clases se
            // construyen antes de enrutar, incluso para el login.
            error_log('[MaterialControl] migrate: ' . $e->getMessage());
        }
    }

    // ── Vales ────────────────────────────────────────────────

    /**
     * @param string $estado  'DENTRO', 'RETIRADO' o '' para todos
     */
    public function listar(string $estado = '', string $q = ''): array {
        $where = [];
        $params = [];
        $estado = strtoupper(trim($estado));
        if (in_array($estado, self::ESTADOS, true)) {
            $where[] = 'v.estado = ?';
            $params[] = $estado;
        }
        $q = trim($q);
        if ($q !== '') {
            $where[] = '(v.planta LIKE ? OR v.folio LIKE ? OR v.responsable LIKE ? OR v.material LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like);
        }
        $sql = "SELECT v.id, v.planta, v.ubicacion, v.folio, v.responsable, v.material,
                       v.estado, v.folio_salida, v.notas,
                       DATE_FORMAT(v.fecha_ingreso,'%d/%m/%Y') AS fecha_ingreso,
                       DATE_FORMAT(v.fecha_salida,'%d/%m/%Y')  AS fecha_salida,
                       v.fecha_ingreso AS fecha_ingreso_iso,
                       v.fecha_salida  AS fecha_salida_iso,
                       DATEDIFF(CURDATE(), v.fecha_ingreso) AS dias_dentro,
                       COUNT(d.id) AS total_docs,
                       COALESCE(SUM(d.tipo_doc = 'vale_entrada'),0) AS docs_entrada,
                       COALESCE(SUM(d.tipo_doc = 'vale_salida'),0)  AS docs_salida
                FROM material_vales v
                LEFT JOIN material_vale_doc d ON d.vale_id = v.id";
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        // Lo que sigue dentro va primero, y de eso lo más viejo arriba: es el
        // material que lleva más tiempo fuera de casa.
        $sql .= " GROUP BY v.id
                  ORDER BY (v.estado = 'DENTRO') DESC,
                           CASE WHEN v.estado = 'DENTRO' THEN v.fecha_ingreso END ASC,
                           v.fecha_ingreso DESC, v.id DESC";

        try {
            $st = $this->pdo->prepare($sql);
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('[MaterialControl] listar: ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'No se pudo leer el control de material.'];
        }

        $vales = array_map(function ($r) {
            $dentro = ($r['estado'] ?? '') === 'DENTRO';
            return [
                'id'            => (int)$r['id'],
                'planta'        => (string)$r['planta'],
                'ubicacion'     => (string)($r['ubicacion'] ?? ''),
                'folio'         => (string)($r['folio'] ?? ''),
                'responsable'   => (string)($r['responsable'] ?? ''),
                'material'      => (string)($r['material'] ?? ''),
                'piezas'        => $this->contarPiezas((string)($r['material'] ?? '')),
                'estado'        => (string)$r['estado'],
                'fecha_ingreso' => (string)($r['fecha_ingreso'] ?? ''),
                'fecha_salida'  => (string)($r['fecha_salida'] ?? ''),
                'fecha_ingreso_iso' => (string)($r['fecha_ingreso_iso'] ?? ''),
                'fecha_salida_iso'  => (string)($r['fecha_salida_iso'] ?? ''),
                'folio_salida'  => (string)($r['folio_salida'] ?? ''),
                'notas'         => (string)($r['notas'] ?? ''),
                'dias_dentro'   => $dentro && $r['dias_dentro'] !== null ? (int)$r['dias_dentro'] : null,
                'total_docs'    => (int)$r['total_docs'],
                'docs_entrada'  => (int)$r['docs_entrada'],
                'docs_salida'   => (int)$r['docs_salida'],
                // Un vale sin el papel escaneado no sirve para sacar nada.
                'sin_respaldo'  => (int)$r['docs_entrada'] === 0,
            ];
        }, $rows);

        $dentro = array_filter($vales, fn($v) => $v['estado'] === 'DENTRO');
        return [
            'status' => 'success',
            'vales'  => $vales,
            'resumen' => [
                'dentro'       => count($dentro),
                'retirados'    => count($vales) - count($dentro),
                'sin_respaldo' => count(array_filter($dentro, fn($v) => $v['sin_respaldo'])),
                'plantas'      => count(array_unique(array_map(fn($v) => mb_strtoupper($v['planta']), $dentro))),
            ],
        ];
    }

    public function detalle(int $id): array {
        $s = $this->pdo->prepare(
            "SELECT id, planta, ubicacion, folio, responsable, material, estado,
                    folio_salida, notas, usuario,
                    fecha_ingreso AS fecha_ingreso_iso, fecha_salida AS fecha_salida_iso,
                    DATE_FORMAT(fecha_ingreso,'%d/%m/%Y') AS fecha_ingreso,
                    DATE_FORMAT(fecha_salida,'%d/%m/%Y')  AS fecha_salida
             FROM material_vales WHERE id = ?"
        );
        $s->execute([$id]);
        $v = $s->fetch(PDO::FETCH_ASSOC);
        if (!$v) return ['status' => 'error', 'message' => 'Ese registro ya no existe.'];

        $d = $this->pdo->prepare(
            "SELECT id, tipo_doc, nombre, archivo_url, notas,
                    DATE_FORMAT(created_at,'%d/%m/%Y') AS fecha_subida
             FROM material_vale_doc WHERE vale_id = ? ORDER BY id"
        );
        $d->execute([$id]);
        $docs = $d->fetchAll(PDO::FETCH_ASSOC);
        foreach ($docs as &$doc) {
            $doc['archivo_url'] = $doc['archivo_url']
                ? rtrim(SITE_URL, '/') . '/' . ltrim((string)$doc['archivo_url'], '/')
                : '';
        }
        unset($doc);

        $v['piezas'] = $this->contarPiezas((string)($v['material'] ?? ''));
        return ['status' => 'success', 'vale' => $v, 'docs' => $docs];
    }

    public function guardar(array $p, string $usuario = ''): array {
        $planta = trim((string)($p['planta'] ?? ''));
        if ($planta === '') return ['status' => 'error', 'message' => 'Indica la planta o el cliente.'];

        $ingreso = $this->fechaIso((string)($p['fecha_ingreso'] ?? ''));
        if (($p['fecha_ingreso'] ?? '') !== '' && $ingreso === null)
            return ['status' => 'error', 'message' => 'La fecha de ingreso no es válida.'];

        $estado = strtoupper(trim((string)($p['estado'] ?? 'DENTRO')));
        if (!in_array($estado, self::ESTADOS, true)) $estado = 'DENTRO';

        $salida = $this->fechaIso((string)($p['fecha_salida'] ?? ''));
        if (($p['fecha_salida'] ?? '') !== '' && $salida === null)
            return ['status' => 'error', 'message' => 'La fecha de salida no es válida.'];

        // Un material no puede salir antes de entrar: casi siempre es un dedazo
        // en el año y deja el conteo de días en negativo.
        if ($ingreso && $salida && $salida < $ingreso)
            return ['status' => 'error', 'message' => 'La salida no puede ser anterior al ingreso.'];

        // Mientras siga dentro no hay salida que registrar.
        if ($estado === 'DENTRO') { $salida = null; $p['folio_salida'] = ''; }

        $campos = [
            'planta'        => mb_strtoupper($planta),
            'ubicacion'     => trim((string)($p['ubicacion'] ?? '')),
            'folio'         => trim((string)($p['folio'] ?? '')),
            'fecha_ingreso' => $ingreso,
            'responsable'   => trim((string)($p['responsable'] ?? '')),
            'material'      => $this->normalizarMaterial((string)($p['material'] ?? '')),
            'estado'        => $estado,
            'fecha_salida'  => $salida,
            'folio_salida'  => trim((string)($p['folio_salida'] ?? '')),
            'notas'         => trim((string)($p['notas'] ?? '')),
        ];

        $id = (int)($p['id'] ?? 0);
        try {
            if ($id) {
                if (!$this->existe($id)) return ['status' => 'error', 'message' => 'Ese registro ya no existe.'];
                $sets = implode(',', array_map(fn($c) => "$c=?", array_keys($campos)));
                $this->pdo->prepare("UPDATE material_vales SET $sets WHERE id=?")
                    ->execute([...array_values($campos), $id]);
            } else {
                $campos['usuario'] = $usuario;
                $cols = implode(',', array_keys($campos));
                $ph   = implode(',', array_fill(0, count($campos), '?'));
                $this->pdo->prepare("INSERT INTO material_vales ($cols) VALUES ($ph)")
                    ->execute(array_values($campos));
                $id = (int)$this->pdo->lastInsertId();
            }
        } catch (\Throwable $e) {
            error_log('[MaterialControl] guardar: ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'No se pudo guardar el registro.'];
        }

        return ['status' => 'success', 'id' => $id, 'message' => 'Registro guardado.'];
    }

    /** Cierra el vale: el material salió de la planta. */
    public function retirar(array $p): array {
        $id = (int)($p['id'] ?? 0);
        $v = $this->cargar($id);
        if (!$v) return ['status' => 'error', 'message' => 'Ese registro ya no existe.'];
        if ($v['estado'] === 'RETIRADO') return ['status' => 'error', 'message' => 'Ese material ya estaba retirado.'];

        $salida = $this->fechaIso((string)($p['fecha_salida'] ?? '')) ?? date('Y-m-d');
        if (!empty($v['fecha_ingreso']) && $salida < $v['fecha_ingreso'])
            return ['status' => 'error', 'message' => 'La salida no puede ser anterior al ingreso.'];

        $this->pdo->prepare(
            "UPDATE material_vales SET estado='RETIRADO', fecha_salida=?, folio_salida=? WHERE id=?"
        )->execute([$salida, trim((string)($p['folio_salida'] ?? '')), $id]);

        // El vale de salida es el comprobante de que el material regresó; si no
        // se sube, el expediente queda cojo y conviene decirlo ahora.
        $docs = $this->pdo->prepare("SELECT COUNT(*) FROM material_vale_doc WHERE vale_id=? AND tipo_doc='vale_salida'");
        $docs->execute([$id]);
        $falta = (int)$docs->fetchColumn() === 0;

        return [
            'status'  => 'success',
            'falta_vale_salida' => $falta,
            'message' => $falta
                ? 'Material retirado. Falta subir el vale de salida.'
                : 'Material retirado.',
        ];
    }

    /** Reabre un vale cerrado por error. */
    public function reabrir(int $id): array {
        if (!$this->existe($id)) return ['status' => 'error', 'message' => 'Ese registro ya no existe.'];
        $this->pdo->prepare("UPDATE material_vales SET estado='DENTRO', fecha_salida=NULL, folio_salida='' WHERE id=?")
            ->execute([$id]);
        return ['status' => 'success', 'message' => 'El material vuelve a figurar dentro de la planta.'];
    }

    public function eliminar(int $id, string $usuario = '', string $motivo = ''): array {
        $v = $this->cargar($id);
        if (!$v) return ['status' => 'error', 'message' => 'Ese registro ya no existe.'];

        $s = $this->pdo->prepare("SELECT archivo_url FROM material_vale_doc WHERE vale_id=?");
        $s->execute([$id]);
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $u) $this->borrarArchivo((string)$u);

        $this->pdo->prepare("DELETE FROM material_vale_doc WHERE vale_id=?")->execute([$id]);
        $this->pdo->prepare("DELETE FROM material_vales WHERE id=?")->execute([$id]);

        if (function_exists('registrarEliminacion')) {
            registrarEliminacion(
                $this->pdo, $usuario ?: 'sistema', "material#$id",
                'Vale de material ' . ($v['folio'] ?: 's/folio') . ' — ' . $v['planta']
                    . ' — estado ' . $v['estado'],
                $motivo
            );
        }
        return ['status' => 'success', 'message' => 'Registro eliminado.'];
    }

    // ── Documentos ───────────────────────────────────────────

    public function subirDoc(array $post, array $files): array {
        $valeId = (int)($post['vale_id'] ?? 0);
        if (!$this->existe($valeId)) return ['status' => 'error', 'message' => 'Ese registro ya no existe.'];

        $tipo = trim((string)($post['tipo_doc'] ?? 'otro'));
        if (!in_array($tipo, self::TIPOS_DOC, true)) $tipo = 'otro';

        $nombre = trim((string)($post['nombre'] ?? ''));
        if ($nombre === '') $nombre = $this->etiquetaTipo($tipo);

        $archivo = $files['archivo'] ?? [];
        if (empty($archivo['tmp_name']))
            return ['status' => 'error', 'message' => 'Elige el archivo del documento.'];
        if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK)
            return ['status' => 'error', 'message' => 'El archivo no llegó completo. Vuelve a intentarlo.'];

        $ext = strtolower(pathinfo((string)($archivo['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, self::EXT_OK, true))
            return ['status' => 'error', 'message' => 'Sólo se aceptan imágenes o PDF.'];
        if (($archivo['size'] ?? 0) > self::MAX_BYTES)
            return ['status' => 'error', 'message' => 'El archivo no debe pasar de 10 MB.'];

        $rel = "avba/material/$valeId";
        $dir = rtrim(UPLOAD_DIR, '/') . "/$rel/";
        if (!is_dir($dir) && !@mkdir($dir, 0755, true))
            return ['status' => 'error', 'message' => 'No se pudo preparar la carpeta del expediente.'];

        $base = $tipo . '_' . date('YmdHis') . '_' . random_int(100, 999);
        if ($ext === 'pdf') {
            $fn = "$base.pdf";
            if (!$this->guardarSubida($archivo['tmp_name'], $dir . $fn))
                return ['status' => 'error', 'message' => 'No se pudo guardar el archivo.'];
        } else {
            // El vale suele venir de una foto en la puerta: se comprime para
            // que el expediente no pese de más, pero si falla se guarda tal cual.
            $fn = "$base.jpg";
            $real = comprimirImagen($archivo['tmp_name'], $dir . $fn, 1600, 1600, 75);
            if ($real) {
                $fn = $real;
            } else {
                $fn = "$base.$ext";
                if (!$this->guardarSubida($archivo['tmp_name'], $dir . $fn))
                    return ['status' => 'error', 'message' => 'No se pudo guardar el archivo.'];
            }
        }

        // La ruta guardada es la pública, con su prefijo: es la que el navegador
        // pide. Para tocar el archivo se vuelve a traducir en rutaAbsoluta().
        $url = "uploads/$rel/$fn";
        $this->pdo->prepare(
            "INSERT INTO material_vale_doc (vale_id, tipo_doc, nombre, archivo_url, notas) VALUES (?,?,?,?,?)"
        )->execute([$valeId, $tipo, $nombre, $url, trim((string)($post['notas'] ?? ''))]);

        return [
            'status'  => 'success',
            'id'      => (int)$this->pdo->lastInsertId(),
            'url'     => rtrim(SITE_URL, '/') . '/' . $url,
            'message' => 'Documento subido.',
        ];
    }

    public function eliminarDoc(int $id): array {
        $s = $this->pdo->prepare("SELECT archivo_url FROM material_vale_doc WHERE id=?");
        $s->execute([$id]);
        $u = $s->fetchColumn();
        if ($u === false) return ['status' => 'error', 'message' => 'Ese documento ya no existe.'];
        $this->borrarArchivo((string)$u);
        $this->pdo->prepare("DELETE FROM material_vale_doc WHERE id=?")->execute([$id]);
        return ['status' => 'success', 'message' => 'Documento eliminado.'];
    }

    // ── Apoyo ────────────────────────────────────────────────

    private function existe(int $id): bool {
        if ($id <= 0) return false;
        $s = $this->pdo->prepare("SELECT id FROM material_vales WHERE id=?");
        $s->execute([$id]);
        return (bool)$s->fetch();
    }

    private function cargar(int $id): ?array {
        if ($id <= 0) return null;
        $s = $this->pdo->prepare("SELECT id, planta, folio, estado, fecha_ingreso FROM material_vales WHERE id=?");
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function borrarArchivo(string $url): void {
        $real = $this->rutaAbsoluta($url);
        if ($real !== null && is_file($real)) @unlink($real);
    }

    /**
     * Traduce la ruta pública guardada ("uploads/avba/material/…") a la ruta en
     * disco. Devuelve null si el resultado se sale del área de subidas: el
     * nombre lo pone el servidor, pero un '..' colado en la base apuntaría a
     * cualquier archivo del sitio.
     */
    private function rutaAbsoluta(string $url): ?string {
        $url = ltrim(trim($url), '/');
        if ($url === '') return null;
        $base = realpath(rtrim(UPLOAD_DIR, '/'));
        if (!$base) return null;
        // UPLOAD_DIR ya es la carpeta de subidas; la ruta pública la nombra otra
        // vez al principio y sumarlas daría uploads/uploads/…
        if (str_starts_with($url, 'uploads/')) $url = substr($url, 8);
        $real = realpath($base . '/' . $url);
        return ($real && str_starts_with($real, $base . DIRECTORY_SEPARATOR)) ? $real : null;
    }

    private function guardarSubida(string $tmp, string $destino): bool {
        return is_uploaded_file($tmp)
            ? move_uploaded_file($tmp, $destino)
            : (bool)@rename($tmp, $destino);   // en pruebas no hay subida real
    }

    /** Una pieza por renglón, sin renglones vacíos ni espacios de más. */
    private function normalizarMaterial(string $txt): string {
        $lineas = preg_split('/\r\n|\r|\n/', $txt) ?: [];
        $lineas = array_values(array_filter(array_map('trim', $lineas), fn($l) => $l !== ''));
        return implode("\n", $lineas);
    }

    private function contarPiezas(string $material): int {
        return $material === '' ? 0 : count(preg_split('/\n/', $material) ?: []);
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

    private function etiquetaTipo(string $tipo): string {
        return [
            'vale_entrada'   => 'Vale de entrada',
            'vale_salida'    => 'Vale de salida',
            'remision'       => 'Remisión',
            'foto'           => 'Foto del material',
            'identificacion' => 'Identificación',
        ][$tipo] ?? 'Documento';
    }
}
