<?php
/**
 * AVBA Certificaciones — Dictamen de no conformidad
 *
 * Cuando un equipo NO pasa la inspección, el cliente se queda sin documento:
 * no hay certificado que emitir y el dictamen de conformidad no aplica. Lo
 * único que recibía era un correo diciendo que no cumplió. Esto le entrega un
 * documento formal con QUÉ se encontró, CON QUÉ FOTO se sustenta y QUÉ hay que
 * corregir.
 *
 * QUIÉN LO EMITE. Calidad, que es quien dictamina la no conformidad. Y sólo
 * eso: Calidad no puede publicar certificados ni dictámenes de conformidad
 * —eso es de Certificaciones—, pero sí lo que ella misma dictamina.
 *
 * NO ES UN CERTIFICADO AL REVÉS. El documento dice que el equipo NO está apto
 * y por qué. Que llegue al portal junto a los equipos certificados obliga a
 * que se distinga a primera vista: un cliente no puede confundir uno con otro.
 */
class NoConformidades {
    private PDO $pdo;

    /** Qué tan grave es el hallazgo. Ordena el documento y el color. */
    public const GRAVEDADES = ['critica', 'mayor', 'menor'];

    public const GRAVEDAD_NOMBRE = [
        'critica' => 'Crítica',
        'mayor'   => 'Mayor',
        'menor'   => 'Menor',
    ];

    private const MAX_FOTOS = 4;      // por hallazgo

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->migrate();
    }

    private function migrate(): void {
        $tablas = [
            "CREATE TABLE IF NOT EXISTS no_conformidades (
               id          INT AUTO_INCREMENT PRIMARY KEY,
               equipo_id   INT          NOT NULL,
               codigo      VARCHAR(12)  NOT NULL,
               gravedad    VARCHAR(10)  NOT NULL DEFAULT 'mayor',
               punto       VARCHAR(20)  NULL,
               descripcion TEXT         NOT NULL,
               accion      TEXT         NULL,
               creada_por  VARCHAR(120) NOT NULL,
               created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
               INDEX idx_eq (equipo_id)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS no_conformidades_foto (
               id         INT AUTO_INCREMENT PRIMARY KEY,
               nc_id      INT          NOT NULL,
               url        VARCHAR(500) NOT NULL,
               pie        VARCHAR(200) NULL,
               orden      INT          NOT NULL DEFAULT 0,
               created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
               INDEX idx_nc (nc_id)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];
        foreach ($tablas as $sql) {
            try { $this->pdo->exec($sql); }
            catch (\Throwable $e) { error_log('[NoConformidades] migrate: ' . $e->getMessage()); }
        }
        // Dónde queda el PDF y si está publicado. Van en equipos porque es el
        // equipo el que se publica, igual que su certificado y su dictamen.
        foreach ([
            'dictamen_nc_url' => "ALTER TABLE equipos ADD COLUMN dictamen_nc_url VARCHAR(500) NULL",
            'nc_publicado'    => "ALTER TABLE equipos ADD COLUMN nc_publicado TINYINT(1) NOT NULL DEFAULT 0",
            'nc_publicado_at' => "ALTER TABLE equipos ADD COLUMN nc_publicado_at DATETIME NULL",
        ] as $col => $ddl) {
            try {
                if (!columnaExiste($this->pdo, 'equipos', $col)) $this->pdo->exec($ddl);
            } catch (\Throwable $e) {
                error_log('[NoConformidades] columna ' . $col . ': ' . $e->getMessage());
            }
        }
    }

    // ── Reglas ────────────────────────────────────────────

    /**
     * Sólo se dictamina la no conformidad de un equipo que está NO CONFORME.
     *
     * Es la regla que mantiene a Calidad dentro de lo suyo: puede publicar lo
     * que ella dictamina, no documentos de un equipo aprobado.
     */
    private function equipoNoConforme(int $id): ?array {
        $st = $this->pdo->prepare(
            "SELECT id, cliente, control, maquinaria, marca, modelo, serie, id_equipo,
                    capacidad, estado, motivo, direccion, inspector,
                    DATE_FORMAT(fecha_inspeccion,'%d/%m/%Y') AS fecha_fmt
             FROM equipos WHERE id = ?"
        );
        $st->execute([$id]);
        $eq = $st->fetch(PDO::FETCH_ASSOC);
        if (!$eq) return null;
        return $eq['estado'] === 'NO CONFORME' ? $eq : null;
    }

    private static function limpiarGravedad(?string $g): string {
        $g = strtolower(trim((string)$g));
        return in_array($g, self::GRAVEDADES, true) ? $g : 'mayor';
    }

    // ── Hallazgos ─────────────────────────────────────────

    public function listar(int $equipoId): array {
        try {
            $st = $this->pdo->prepare(
                "SELECT * FROM no_conformidades WHERE equipo_id = ?
                 ORDER BY FIELD(gravedad,'critica','mayor','menor'), id"
            );
            $st->execute([$equipoId]);
            $filas = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            error_log('[NoConformidades] listar: ' . $e->getMessage());
            return ['status' => 'success', 'data' => []];
        }
        foreach ($filas as &$f) $f['fotos'] = $this->fotosDe((int)$f['id']);
        return ['status' => 'success', 'data' => $filas];
    }

    private function fotosDe(int $ncId): array {
        try {
            $st = $this->pdo->prepare(
                "SELECT id, url, pie FROM no_conformidades_foto WHERE nc_id = ? ORDER BY orden, id"
            );
            $st->execute([$ncId]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) { return []; }
    }

    /**
     * Agrega un hallazgo, con sus fotos si vienen.
     *
     * La descripción es obligatoria y la acción requerida también: un hallazgo
     * sin decir qué hay que hacer deja al cliente con un problema y sin salida,
     * y es la diferencia entre un documento útil y una queja.
     */
    public function agregar(array $p, array $files, string $usuario): array {
        $equipoId = (int)($p['equipo_id'] ?? 0);
        $eq = $this->equipoNoConforme($equipoId);
        if (!$eq) return ['status' => 'error', 'message' =>
            'El equipo no existe o no está marcado como NO CONFORME. '
          . 'Primero recházalo en Calidad indicando el motivo.'];

        $desc = trim((string)($p['descripcion'] ?? ''));
        if ($desc === '') return ['status' => 'error', 'message' => 'Describe el hallazgo.'];
        $accion = trim((string)($p['accion'] ?? ''));
        if ($accion === '') return ['status' => 'error', 'message' =>
            'Indica qué debe corregirse. Un hallazgo sin acción deja al cliente sin saber qué hacer.'];

        // El código se asigna solo: NC-01, NC-02… Es lo que se cita después.
        $n = $this->pdo->prepare("SELECT COUNT(*) FROM no_conformidades WHERE equipo_id = ?");
        $n->execute([$equipoId]);
        $codigo = 'NC-' . str_pad((string)((int)$n->fetchColumn() + 1), 2, '0', STR_PAD_LEFT);

        $this->pdo->prepare(
            "INSERT INTO no_conformidades
               (equipo_id, codigo, gravedad, punto, descripcion, accion, creada_por)
             VALUES (?,?,?,?,?,?,?)"
        )->execute([
            $equipoId, $codigo, self::limpiarGravedad($p['gravedad'] ?? ''),
            mb_substr(trim((string)($p['punto'] ?? '')), 0, 20) ?: null,
            $desc, $accion, $usuario,
        ]);
        $ncId = (int)$this->pdo->lastInsertId();

        $nFotos = $this->guardarFotos($files, $ncId, $equipoId, (array)($p['pies'] ?? []));
        registrarHistorial($this->pdo, $usuario, $equipoId, 'no_conformidad', null,
                           $codigo . ': ' . mb_substr($desc, 0, 160), 'INSERT');

        return ['status' => 'success', 'id' => $ncId, 'codigo' => $codigo, 'fotos' => $nFotos,
                'message' => $codigo . ' registrada' . ($nFotos ? " con $nFotos foto(s)." : '.')];
    }

    public function editar(int $id, array $p, string $usuario): array {
        $nc = $this->obtener($id);
        if (!$nc) return ['status' => 'error', 'message' => 'Hallazgo no encontrado.'];

        $sets = []; $vals = [];
        foreach (['descripcion', 'accion'] as $campo) {
            if (!array_key_exists($campo, $p)) continue;
            $v = trim((string)$p[$campo]);
            if ($v === '') return ['status' => 'error', 'message' => 'Ni la descripción ni la acción pueden quedar vacías.'];
            $sets[] = "$campo = ?"; $vals[] = $v;
        }
        if (array_key_exists('gravedad', $p)) { $sets[] = 'gravedad = ?'; $vals[] = self::limpiarGravedad($p['gravedad']); }
        if (array_key_exists('punto', $p))    { $sets[] = 'punto = ?';    $vals[] = mb_substr(trim((string)$p['punto']), 0, 20) ?: null; }
        if (!$sets) return ['status' => 'success', 'message' => 'Sin cambios.'];

        $vals[] = $id;
        $this->pdo->prepare("UPDATE no_conformidades SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);
        registrarHistorial($this->pdo, $usuario, (int)$nc['equipo_id'], 'no_conformidad',
                           $nc['descripcion'], $p['descripcion'] ?? $nc['descripcion']);
        return ['status' => 'success', 'message' => 'Hallazgo actualizado.'];
    }

    /**
     * Borra un hallazgo y sus fotos.
     *
     * Se permite sólo mientras el dictamen no se haya publicado: una vez que
     * el cliente lo tiene, quitar un hallazgo cambia un documento que ya está
     * en su poder. Entonces se corrige y se reemite, que deja rastro.
     */
    public function eliminar(int $id, string $usuario): array {
        $nc = $this->obtener($id);
        if (!$nc) return ['status' => 'error', 'message' => 'Hallazgo no encontrado.'];

        $pub = $this->pdo->prepare("SELECT nc_publicado FROM equipos WHERE id = ?");
        $pub->execute([(int)$nc['equipo_id']]);
        if ((int)$pub->fetchColumn() === 1)
            return ['status' => 'error', 'message' =>
                'El dictamen ya está publicado. Corrige el hallazgo y vuelve a emitirlo, '
              . 'en vez de borrarlo: el cliente ya tiene el documento anterior.'];

        foreach ($this->fotosDe($id) as $f) $this->borrarArchivo((string)$f['url']);
        $this->pdo->prepare("DELETE FROM no_conformidades_foto WHERE nc_id = ?")->execute([$id]);
        $this->pdo->prepare("DELETE FROM no_conformidades WHERE id = ?")->execute([$id]);
        registrarHistorial($this->pdo, $usuario, (int)$nc['equipo_id'], 'no_conformidad',
                           $nc['codigo'] . ': ' . $nc['descripcion'], null, 'DELETE');
        return ['status' => 'success', 'message' => $nc['codigo'] . ' eliminada.'];
    }

    public function obtener(int $id): ?array {
        $st = $this->pdo->prepare("SELECT * FROM no_conformidades WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // ── Fotos ─────────────────────────────────────────────

    private function guardarFotos(array $files, int $ncId, int $equipoId, array $pies): int {
        $entrada = $files['fotos'] ?? null;
        if (!$entrada || empty($entrada['tmp_name'])) return 0;

        $dir = rtrim(UPLOAD_DIR, '/') . "/no_conformidades/$equipoId/";
        if (!is_dir($dir)) @mkdir($dir, 0755, true);

        $nombres = (array)$entrada['name'];
        $ins = $this->pdo->prepare(
            "INSERT INTO no_conformidades_foto (nc_id, url, pie, orden) VALUES (?,?,?,?)"
        );
        $n = 0;
        foreach ($nombres as $i => $orig) {
            if ($n >= self::MAX_FOTOS) break;
            $tmp = $entrada['tmp_name'][$i] ?? '';
            if (!$tmp || ($entrada['error'][$i] ?? 1) !== 0) continue;
            $ext = strtolower(pathinfo((string)$orig, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png','webp'], true)) continue;

            $nombre = 'nc' . $ncId . '_' . ($i + 1) . '_' . date('His') . '.jpg';
            $destino = $dir . $nombre;
            // Se comprimen: son fotos de campo y acaban en un PDF. Sin esto un
            // dictamen de seis hallazgos pesa más que el resto del expediente.
            $real = function_exists('comprimirImagen')
                ? comprimirImagen($tmp, $destino, 1400, 1400, 72) : null;
            if ($real) $nombre = $real;
            elseif (!move_uploaded_file($tmp, $destino)) continue;

            $ins->execute([$ncId,
                rtrim(UPLOAD_URL, '/') . '/no_conformidades/' . $equipoId . '/' . $nombre,
                mb_substr(trim((string)($pies[$i] ?? '')), 0, 200) ?: null, $i]);
            $n++;
        }
        return $n;
    }

    public function eliminarFoto(int $fotoId, string $usuario): array {
        $st = $this->pdo->prepare(
            "SELECT f.*, n.equipo_id FROM no_conformidades_foto f
             JOIN no_conformidades n ON n.id = f.nc_id WHERE f.id = ?"
        );
        $st->execute([$fotoId]);
        $f = $st->fetch(PDO::FETCH_ASSOC);
        if (!$f) return ['status' => 'error', 'message' => 'Foto no encontrada.'];

        $this->borrarArchivo((string)$f['url']);
        $this->pdo->prepare("DELETE FROM no_conformidades_foto WHERE id = ?")->execute([$fotoId]);
        return ['status' => 'success', 'message' => 'Foto eliminada.'];
    }

    // ── El dictamen ───────────────────────────────────────

    /**
     * Arma el dictamen de no conformidad en PDF.
     *
     * Exige al menos un hallazgo: un dictamen que dice "no conforme" sin
     * decir qué se encontró no le sirve a nadie y no se puede atender.
     */
    public function emitir(int $equipoId, string $usuario): array {
        $eq = $this->equipoNoConforme($equipoId);
        if (!$eq) return ['status' => 'error', 'message' =>
            'El equipo no está marcado como NO CONFORME.'];

        $hallazgos = $this->listar($equipoId)['data'];
        if (!$hallazgos) return ['status' => 'error', 'message' =>
            'Registra al menos un hallazgo antes de emitir el dictamen. '
          . 'Un documento que dice "no conforme" sin decir qué se encontró no se puede atender.'];

        $plantilla = __DIR__ . '/../dictamen_no_conformidad.html';
        if (!file_exists($plantilla))
            return ['status' => 'error', 'message' => 'Falta la plantilla del dictamen.'];

        $e = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
        $cuenta = ['critica' => 0, 'mayor' => 0, 'menor' => 0];
        foreach ($hallazgos as $h) $cuenta[$h['gravedad']] = ($cuenta[$h['gravedad']] ?? 0) + 1;

        $folio = $eq['control'] ? 'AB.' . $eq['control'] . '-NC-' . date('Y') . 'MX'
                                : 'NC-' . str_pad((string)$equipoId, 5, '0', STR_PAD_LEFT);

        $map = [
            '{folio}'             => $e($folio),
            '{cliente}'           => $e($eq['cliente']),
            '{domicilio}'         => $e($eq['direccion']),
            '{tipo_maquinaria}'   => $e($eq['maquinaria']),
            '{marca}'             => $e($eq['marca']),
            '{modelo}'            => $e($eq['modelo']),
            '{no_serie}'          => $e($eq['serie']),
            '{no_identificacion}' => $e($eq['id_equipo']),
            '{capacidad}'         => $e($eq['capacidad']),
            '{fecha_inspeccion}'  => $e($eq['fecha_fmt']),
            '{fecha_emision}'     => date('d/m/Y'),
            '{nc_total}'          => (string)count($hallazgos),
            '{nc_criticas}'       => (string)$cuenta['critica'],
            '{nc_mayores}'        => (string)$cuenta['mayor'],
            '{nc_menores}'        => (string)$cuenta['menor'],
            '{nc_motivo}'         => $e(trim((string)$eq['motivo'])) ?: 'No se registró un motivo general.',
            '{nc_hallazgos}'      => $this->bloqueHallazgos($hallazgos),
            '{normas_acreditadas}'=> $e($this->normas($equipoId, 'acreditada')),
            '{normas_referencia}' => $e($this->normas($equipoId, 'referencia')),
            '{nombre_inspector}'  => $e($eq['inspector']),
            '{numero_inspector}'  => '',
            '{firma_inspector_img}'   => '',
            '{nombre_responsable}'    => $e(defined('NOMBRE_RESPONSABLE') ? NOMBRE_RESPONSABLE : ''),
            '{cargo_responsable}'     => 'Responsable de Calidad',
            '{firma_responsable_img}' => '',
        ];
        $html = str_replace(array_keys($map), array_values($map),
                            file_get_contents($plantilla));

        try {
            $url = $this->aPdf($html, $folio);
        } catch (\Throwable $ex) {
            return ['status' => 'error', 'message' => 'No se pudo generar: ' . $ex->getMessage()];
        }

        $abs = rtrim(SITE_URL, '/') . '/' . ltrim($url, '/');
        $this->pdo->prepare("UPDATE equipos SET dictamen_nc_url = ? WHERE id = ?")
                  ->execute([$abs, $equipoId]);
        registrarHistorial($this->pdo, $usuario, $equipoId, 'dictamen_nc', null, $folio);

        return ['status' => 'success', 'url' => $abs, 'folio' => $folio,
                'message' => 'Dictamen ' . $folio . ' generado con ' . count($hallazgos) . ' hallazgo(s).'];
    }

    /** Un bloque por hallazgo, con su gravedad, su acción y sus fotos. */
    private function bloqueHallazgos(array $hallazgos): string {
        $e = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
        $out = '';
        foreach ($hallazgos as $h) {
            $g = $h['gravedad'];
            $fotos = '';
            if ($h['fotos']) {
                // Dos por renglón: más pequeñas no se distingue el hallazgo,
                // que es justo lo que la foto tiene que demostrar.
                $celdas = '';
                foreach ($h['fotos'] as $i => $f) {
                    if ($i > 0 && $i % 2 === 0) $celdas .= '</tr><tr>';
                    $celdas .= '<td class="nc-foto-cell" style="width:50%">'
                             . '<img src="' . $e($f['url']) . '">'
                             . ($f['pie'] ? '<div class="nc-foto-pie">' . $e($f['pie']) . '</div>' : '')
                             . '</td>';
                }
                $fotos = '<table class="nc-fotos" width="100%" cellpadding="0" cellspacing="0"><tr>'
                       . $celdas . '</tr></table>';
            }
            $out .= '<div class="nc-item">'
                  . '<div class="nc-head">'
                  . '<span class="nc-cod">' . $e($h['codigo']) . '</span>&nbsp; '
                  . '<span class="nc-grav nc-g-' . $e($g) . '">' . $e(self::GRAVEDAD_NOMBRE[$g] ?? $g) . '</span>'
                  . ($h['punto'] ? '&nbsp; <span class="nc-punto">Punto ' . $e($h['punto']) . '</span>' : '')
                  . '</div>'
                  . '<div class="nc-cuerpo">'
                  . '<div class="nc-lbl">Hallazgo</div>'
                  . '<div class="nc-desc">' . $e($h['descripcion']) . '</div>'
                  . '<div class="nc-accion"><strong>Acción requerida:</strong> ' . $e($h['accion']) . '</div>'
                  . $fotos
                  . '</div></div>';
        }
        return $out;
    }

    /** Las normas del tipo de equipo, para el pie del resultado. */
    private function normas(int $equipoId, string $tipo): string {
        try {
            $st = $this->pdo->prepare(
                "SELECT n.norma FROM maquinaria_normas n
                 JOIN maquinaria_tipos t ON t.id = n.maquinaria_tipo_id
                 JOIN equipos e ON UPPER(TRIM(e.maquinaria)) = UPPER(TRIM(t.nombre))
                 WHERE e.id = ? AND n.tipo = ? ORDER BY n.orden"
            );
            $st->execute([$equipoId, $tipo]);
            return implode(' · ', array_column($st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'norma'));
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function aPdf(string $html, string $folio): string {
        if (!class_exists('\\Mpdf\\Mpdf')) {
            $autoload = __DIR__ . '/../vendor/autoload.php';
            if (file_exists($autoload)) require_once $autoload;
        }
        if (!class_exists('\\Mpdf\\Mpdf')) throw new \RuntimeException('mPDF no disponible.');

        $dir = rtrim(UPLOAD_DIR, '/') . '/certificados/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8', 'format' => [215, 279],
            'margin_left' => 0, 'margin_right' => 0, 'margin_top' => 0, 'margin_bottom' => 0,
            'default_font' => 'dejavusans',
            'tempDir' => sys_get_temp_dir() . '/mpdf',
        ]);
        $mpdf->SetBasePath(__DIR__ . '/../');
        $prev = (int)ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', 10000000);
        $mpdf->WriteHTML($html);
        ini_set('pcre.backtrack_limit', (string)$prev);

        $nombre = 'DICTAMEN_NC_' . preg_replace('/[^A-Za-z0-9._-]/', '', $folio)
                . '_' . date('Ymd_His') . '.pdf';
        $mpdf->Output($dir . $nombre, 'F');
        return 'uploads/certificados/' . $nombre;
    }

    // ── Publicación ───────────────────────────────────────

    /**
     * Deja el dictamen disponible en el portal del cliente.
     *
     * Es lo ÚNICO que Calidad publica. No hay aquí ningún camino para
     * publicar un certificado o un dictamen de conformidad: esos son de
     * Certificaciones y se emiten sobre equipos aprobados, que por definición
     * no pasan por esta clase.
     */
    public function publicar(int $equipoId, string $usuario): array {
        $eq = $this->equipoNoConforme($equipoId);
        if (!$eq) return ['status' => 'error', 'message' =>
            'Sólo se publica el dictamen de un equipo NO CONFORME.'];

        $st = $this->pdo->prepare("SELECT dictamen_nc_url FROM equipos WHERE id = ?");
        $st->execute([$equipoId]);
        $url = trim((string)$st->fetchColumn());
        if ($url === '') return ['status' => 'error', 'message' =>
            'Primero genera el dictamen; todavía no existe el documento que publicar.'];

        $this->pdo->prepare(
            "UPDATE equipos SET nc_publicado = 1, nc_publicado_at = NOW() WHERE id = ?"
        )->execute([$equipoId]);
        registrarHistorial($this->pdo, $usuario, $equipoId, 'nc_publicado', '0', '1');

        return ['status' => 'success', 'url' => $url,
                'message' => 'El dictamen ya está en el portal del cliente. No se envió correo.'];
    }

    /** Lo retira del portal. El documento no se borra: deja de entregarse. */
    public function retirar(int $equipoId, string $usuario): array {
        $this->pdo->prepare(
            "UPDATE equipos SET nc_publicado = 0 WHERE id = ?"
        )->execute([$equipoId]);
        registrarHistorial($this->pdo, $usuario, $equipoId, 'nc_publicado', '1', '0');
        return ['status' => 'success', 'message' => 'Retirado del portal del cliente.'];
    }

    public function estado(int $equipoId): array {
        try {
            $st = $this->pdo->prepare(
                "SELECT estado, dictamen_nc_url, nc_publicado,
                        DATE_FORMAT(nc_publicado_at,'%d/%m/%Y %H:%i') AS publicado_at
                 FROM equipos WHERE id = ?"
            );
            $st->execute([$equipoId]);
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) { $r = []; }

        return ['status' => 'success', 'data' => [
            'no_conforme' => ($r['estado'] ?? '') === 'NO CONFORME',
            'url'         => $r['dictamen_nc_url'] ?? '',
            'publicado'   => (int)($r['nc_publicado'] ?? 0) === 1,
            'publicado_at'=> $r['publicado_at'] ?? '',
        ]];
    }

    /** Borra el archivo del disco, si está dentro de nuestra carpeta. */
    private function borrarArchivo(string $url): void {
        $base = rtrim(UPLOAD_URL, '/') . '/no_conformidades/';
        if (strpos($url, $base) !== 0) return;      // no es nuestro: no se toca
        $rel = substr($url, strlen($base));
        if (strpos($rel, '..') !== false) return;
        $ruta = rtrim(UPLOAD_DIR, '/') . '/no_conformidades/' . $rel;
        if (is_file($ruta)) @unlink($ruta);
    }
}
