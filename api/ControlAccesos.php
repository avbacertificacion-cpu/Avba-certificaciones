<?php
/* ══════════════════════════════════════════════════════════════
   AVBA — Control de accesos

   Bitácora de entradas y salidas de un sitio con la fotografía de la
   identificación de cada persona (cualquier identificación, no sólo la INE).

     · Registrar: tipo (entrada / salida), foto, punto de control y una
       nota opcional. La hora es la del dispositivo al tomar la foto: en la
       app se puede registrar sin señal y el envío llega después.
     · Cada registro lleva un identificador del dispositivo (uid): si la app
       reenvía uno que el servidor ya tenía (se cortó la señal a medio
       envío), no se duplica.
     · El control del día: lista con hora, foto y quién registró, y los
       totales de entradas y salidas.
     · El PDF del día: las fotos de ENTRADAS y de SALIDAS, cada una con su
       etiqueta (ENTRADA 001 · 08:15:32), pensado para subirlo a Claude y
       que extraiga los números de identificación y cruce entradas y
       salidas.

   Las fotos de identificaciones son datos personales: se guardan en
   uploads/accesos/, cerrada al acceso directo, y sólo se entregan con sesión
   a través de descargar.php.
   ══════════════════════════════════════════════════════════════ */

class ControlAccesos {
    private PDO $pdo;

    /** Quién puede usar el módulo (personal de AVBA, no clientes) */
    public const ROLES = ['ADMIN', 'ADMINISTRATIVO', 'INSPECTOR', 'CALIDAD', 'CERTIFICACIONES'];

    private const DIAS = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
    private const MESES = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto',
                           'septiembre', 'octubre', 'noviembre', 'diciembre'];

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public static function permitido(?array $usr): bool {
        return $usr && empty($usr['vista_admin']) && in_array((string)$usr['rol'], self::ROLES, true);
    }

    /* ── Tabla y carpeta ────────────────────────────────────────────────── */
    public static function asegurarTabla(PDO $pdo): void {
        static $hecho = [];
        $k = spl_object_id($pdo);
        if (isset($hecho[$k])) return;
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS accesos_registros (
               id             INT AUTO_INCREMENT PRIMARY KEY,
               uid            VARCHAR(64)  NOT NULL,
               tipo           VARCHAR(10)  NOT NULL,
               fecha          DATE         NOT NULL,
               capturado      DATETIME     NOT NULL,
               recibido       DATETIME     NOT NULL,
               lugar          VARCHAR(120) NOT NULL DEFAULT '',
               nota           VARCHAR(255) NOT NULL DEFAULT '',
               foto           VARCHAR(255) NOT NULL DEFAULT '',
               foto_mini      VARCHAR(255) NOT NULL DEFAULT '',
               usuario        VARCHAR(50)  NOT NULL DEFAULT '',
               usuario_nombre VARCHAR(150) NOT NULL DEFAULT '',
               UNIQUE KEY uk_uid (uid),
               KEY idx_fecha (fecha)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $hecho[$k] = true;
    }

    public static function carpeta(): string {
        return rtrim(UPLOAD_DIR, '/\\') . '/accesos/';
    }

    /** La carpeta no se sirve directo: sólo por descargar.php, con sesión. */
    private static function protegerCarpeta(): void {
        $base = self::carpeta();
        if (!is_dir($base)) @mkdir($base, 0755, true);
        $ht = $base . '.htaccess';
        if (!is_file($ht)) {
            @file_put_contents($ht,
                "# Fotos de identificaciones: datos personales. Sólo por descargar.php, con sesión.\n" .
                "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n" .
                "<IfModule !mod_authz_core.c>\n  Order Allow,Deny\n  Deny from all\n</IfModule>\n");
        }
        if (!is_file($base . 'index.html')) @file_put_contents($base . 'index.html', '');
    }

    /* ── Registrar ──────────────────────────────────────────────────────── */
    public function registrar(array $p, array $archivo, array $usr): array {
        self::asegurarTabla($this->pdo);
        $tipo = strtolower(trim((string)($p['tipo'] ?? '')));
        if (!in_array($tipo, ['entrada', 'salida'], true)) return ['status' => 'error', 'message' => 'Indica si es entrada o salida.'];
        $uid = (string)($p['uid'] ?? '');
        if (!preg_match('/^[A-Za-z0-9-]{8,64}$/', $uid)) return ['status' => 'error', 'message' => 'Registro sin identificador.'];

        // Reenvío de algo que ya llegó: se responde con lo que ya existe.
        $ya = $this->pdo->prepare("SELECT id FROM accesos_registros WHERE uid = ?");
        $ya->execute([$uid]);
        if ($idYa = $ya->fetchColumn()) return ['status' => 'success', 'id' => (int)$idYa, 'duplicado' => true];

        if (empty($archivo['tmp_name']) || (int)($archivo['error'] ?? 1) !== UPLOAD_ERR_OK) {
            return ['status' => 'error', 'message' => 'Falta la fotografía de la identificación.'];
        }
        if ((int)($archivo['size'] ?? 0) > 15 * 1048576) return ['status' => 'error', 'message' => 'La foto pesa demasiado (máximo 15 MB).'];
        $info = @getimagesize($archivo['tmp_name']);
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            return ['status' => 'error', 'message' => 'El archivo no es una foto válida (JPG, PNG o WEBP).'];
        }

        // La hora es la del dispositivo al tomar la foto (puede llegar después, sin señal)
        $cap = (string)($p['capturado'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $cap) || strtotime($cap) === false) $cap = date('Y-m-d H:i:s');
        $fecha = substr($cap, 0, 10);

        self::protegerCarpeta();
        $sub = 'fotos/' . substr($fecha, 0, 4) . '/' . substr($fecha, 5, 2) . '/';
        $dir = self::carpeta() . $sub;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return ['status' => 'error', 'message' => 'No se pudo guardar la foto en el servidor.'];

        // Miles de fotos al día: 1400 px es de sobra para leer el número de
        // una credencial que llena la foto, y cada una queda en ~150 KB.
        $foto = comprimirImagen($archivo['tmp_name'], $dir . $uid . '.jpg', 1400, 1400, 76);
        $mini = comprimirImagen($archivo['tmp_name'], $dir . $uid . '_m.jpg', 300, 300, 68);
        if (!$foto) {
            // Sin GD: se guarda tal cual llegó
            $ext = $info[2] === IMAGETYPE_PNG ? 'png' : ($info[2] === IMAGETYPE_WEBP ? 'webp' : 'jpg');
            if (!@move_uploaded_file($archivo['tmp_name'], $dir . $uid . '.' . $ext)
                && !@copy($archivo['tmp_name'], $dir . $uid . '.' . $ext)) {
                return ['status' => 'error', 'message' => 'No se pudo guardar la foto en el servidor.'];
            }
            $foto = $uid . '.' . $ext;
        }

        try {
            $this->pdo->prepare(
                "INSERT INTO accesos_registros (uid, tipo, fecha, capturado, recibido, lugar, nota, foto, foto_mini, usuario, usuario_nombre)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute([
                $uid, $tipo, $fecha, $cap, date('Y-m-d H:i:s'),
                mb_substr(textoEstandar($p['lugar'] ?? '') ?: 'SIN PUNTO DE CONTROL', 0, 120),
                mb_substr(textoEstandar($p['nota'] ?? ''), 0, 255),
                $sub . $foto, $mini ? $sub . $mini : '',
                mb_substr((string)($usr['usuario'] ?? ''), 0, 50), mb_substr((string)($usr['nombre'] ?? ''), 0, 150),
            ]);
        } catch (\PDOException $e) {
            // Dos envíos simultáneos del mismo registro: gana el primero
            $ya->execute([$uid]);
            if ($idYa = $ya->fetchColumn()) return ['status' => 'success', 'id' => (int)$idYa, 'duplicado' => true];
            throw $e;
        }
        return ['status' => 'success', 'id' => (int)$this->pdo->lastInsertId(),
                'message' => ($tipo === 'entrada' ? 'Entrada' : 'Salida') . ' registrada a las ' . substr($cap, 11, 5) . '.'];
    }

    /* ── Control del día ────────────────────────────────────────────────── */
    private function registrosDelDia(string $fecha, string $lugar = ''): array {
        self::asegurarTabla($this->pdo);
        $sql = "SELECT id, uid, tipo, capturado, lugar, nota, foto, foto_mini, usuario, usuario_nombre
                  FROM accesos_registros WHERE fecha = ?";
        $args = [$fecha];
        if ($lugar !== '') { $sql .= " AND lugar = ?"; $args[] = $lugar; }
        $st = $this->pdo->prepare($sql . " ORDER BY capturado, id");
        $st->execute($args);
        $filas = $st->fetchAll(PDO::FETCH_ASSOC);
        // Numeración por tipo en orden de hora: la misma del PDF
        $num = ['entrada' => 0, 'salida' => 0];
        foreach ($filas as &$f) { $f['num'] = ++$num[$f['tipo']]; }
        return $filas;
    }

    private static function fechaValida($v): string {
        $v = (string)$v;
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : date('Y-m-d');
    }

    /**
     * El control del día: totales y los registros más recientes (de N en N).
     * En el pico de una planta son miles al día: no se manda todo cada vez.
     * El número de cada registro (ENTRADA 015) es su lugar entre los de su
     * tipo del día, igual que en el PDF.
     */
    public function listar(array $p, array $usr): array {
        self::asegurarTabla($this->pdo);
        $fecha = self::fechaValida($p['fecha'] ?? '');
        $lugar = textoEstandar($p['lugar'] ?? '');
        $limite = max(10, min(200, (int)($p['limite'] ?? 60)));

        $donde = "fecha = ?" . ($lugar !== '' ? " AND lugar = ?" : '');
        $args = $lugar !== '' ? [$fecha, $lugar] : [$fecha];

        $tot = ['entrada' => 0, 'salida' => 0];
        $st = $this->pdo->prepare("SELECT tipo, COUNT(*) AS n FROM accesos_registros WHERE $donde GROUP BY tipo");
        $st->execute($args);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) { if (isset($tot[$t['tipo']])) $tot[$t['tipo']] = (int)$t['n']; }

        $st = $this->pdo->prepare("SELECT DISTINCT lugar FROM accesos_registros WHERE fecha = ? ORDER BY lugar");
        $st->execute([$fecha]);
        $lugares = $st->fetchAll(PDO::FETCH_COLUMN);

        // Página: lo anterior al último que ya tiene la pantalla
        $antesCap = (string)($p['antes_cap'] ?? '');
        $antesId = (int)($p['antes_id'] ?? 0);
        $pag = '';
        $argsPag = $args;
        if ($antesId > 0 && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $antesCap)) {
            $pag = " AND (a.capturado < ? OR (a.capturado = ? AND a.id < ?))";
            array_push($argsPag, $antesCap, $antesCap, $antesId);
        }
        $num = "(SELECT COUNT(*) FROM accesos_registros b WHERE b.fecha = a.fecha AND b.tipo = a.tipo"
             . ($lugar !== '' ? " AND b.lugar = a.lugar" : '')
             . " AND (b.capturado < a.capturado OR (b.capturado = a.capturado AND b.id <= a.id)))";
        $st = $this->pdo->prepare(
            "SELECT a.id, a.uid, a.tipo, a.capturado, a.lugar, a.nota, a.usuario, a.usuario_nombre, $num AS num
               FROM accesos_registros a
              WHERE " . str_replace(['fecha', 'lugar'], ['a.fecha', 'a.lugar'], $donde) . "$pag
              ORDER BY a.capturado DESC, a.id DESC LIMIT " . ($limite + 1)
        );
        $st->execute($argsPag);
        $filas = $st->fetchAll(PDO::FETCH_ASSOC);
        $hayMas = count($filas) > $limite;
        $filas = array_slice($filas, 0, $limite);

        $esAdmin = ($usr['rol'] ?? '') === 'ADMIN';
        $hoy = date('Y-m-d');
        $out = [];
        foreach ($filas as $f) {
            $out[] = [
                'id' => (int)$f['id'], 'uid' => $f['uid'], 'tipo' => $f['tipo'], 'num' => (int)$f['num'],
                'capturado' => $f['capturado'], 'hora' => substr((string)$f['capturado'], 11, 8),
                'lugar' => $f['lugar'], 'nota' => $f['nota'],
                'usuario' => $f['usuario'], 'nombre' => $f['usuario_nombre'] ?: $f['usuario'],
                'puede_borrar' => $esAdmin || ($f['usuario'] === ($usr['usuario'] ?? null) && $fecha === $hoy),
            ];
        }
        return ['status' => 'success', 'fecha' => $fecha, 'registros' => $out, 'totales' => $tot,
                'lugares' => $lugares, 'mas' => $hayMas];
    }

    /** ¿Ya llegaron al servidor? (la pantalla pregunta por lo que tiene en cola) */
    public function existen(array $uids): array {
        self::asegurarTabla($this->pdo);
        $uids = array_values(array_filter(array_slice($uids, 0, 500), fn($u) => is_string($u) && preg_match('/^[A-Za-z0-9-]{8,64}$/', $u)));
        if (!$uids) return ['status' => 'success', 'existen' => []];
        $st = $this->pdo->prepare("SELECT uid, id FROM accesos_registros WHERE uid IN (" . implode(',', array_fill(0, count($uids), '?')) . ")");
        $st->execute($uids);
        return ['status' => 'success', 'existen' => $st->fetchAll(PDO::FETCH_KEY_PAIR)];
    }

    /** Puntos de control usados en los últimos 60 días (para elegir rápido) */
    public function lugares(): array {
        self::asegurarTabla($this->pdo);
        $st = $this->pdo->prepare(
            "SELECT lugar FROM accesos_registros WHERE fecha >= ? GROUP BY lugar ORDER BY MAX(capturado) DESC LIMIT 20"
        );
        $st->execute([date('Y-m-d', strtotime('-60 days'))]);
        return ['status' => 'success', 'lugares' => $st->fetchAll(PDO::FETCH_COLUMN)];
    }

    public function eliminar(int $id, array $usr): array {
        self::asegurarTabla($this->pdo);
        $st = $this->pdo->prepare("SELECT * FROM accesos_registros WHERE id = ?");
        $st->execute([$id]);
        $f = $st->fetch(PDO::FETCH_ASSOC);
        if (!$f) return ['status' => 'success', 'message' => 'El registro ya no existía.'];
        $esAdmin = ($usr['rol'] ?? '') === 'ADMIN';
        if (!$esAdmin && !($f['usuario'] === ($usr['usuario'] ?? null) && $f['fecha'] === date('Y-m-d'))) {
            return ['status' => 'error', 'message' => 'Sólo quien lo registró (el mismo día) o Administración puede eliminarlo.'];
        }
        foreach (['foto', 'foto_mini'] as $c) {
            if ($f[$c] !== '' && strpos($f[$c], '..') === false) @unlink(self::carpeta() . $f[$c]);
        }
        $this->pdo->prepare("DELETE FROM accesos_registros WHERE id = ?")->execute([$id]);
        return ['status' => 'success', 'message' => 'Registro eliminado.'];
    }

    /** Ruta en disco de la foto de un registro (para descargar.php) */
    public function rutaFoto(int $id, bool $mini): ?string {
        self::asegurarTabla($this->pdo);
        $st = $this->pdo->prepare("SELECT foto, foto_mini FROM accesos_registros WHERE id = ?");
        $st->execute([$id]);
        $f = $st->fetch(PDO::FETCH_ASSOC);
        if (!$f) return null;
        $rel = ($mini && $f['foto_mini'] !== '') ? $f['foto_mini'] : $f['foto'];
        if ($rel === '' || strpos($rel, '..') !== false) return null;
        $abs = self::carpeta() . $rel;
        return is_file($abs) ? $abs : null;
    }

    /* ── PDF del día ────────────────────────────────────────────────────── */
    public static function rutaPdf(string $archivo): ?string {
        if (!preg_match('/^ACCESOS_\d{4}-\d{2}-\d{2}_[A-Z0-9-]+_(ambos|entrada|salida)(_p\d+)?_\d+\.pdf$/', $archivo)) return null;
        $abs = self::carpeta() . 'pdf/' . $archivo;
        return is_file($abs) ? $abs : null;
    }

    private static function fechaLarga(string $f): string {
        $t = strtotime($f);
        return self::DIAS[(int)date('w', $t)] . ' ' . (int)date('j', $t) . ' de ' . self::MESES[(int)date('n', $t)] . ' de ' . date('Y', $t);
    }
    private static function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

    /**
     * Fotos por PDF. Con 150 (unas 38 hojas y 12 a 15 MB) cada parte queda
     * dentro de lo que Claude analiza de un archivo (100 hojas, 30 MB) y el
     * servidor la arma sin pasar su tiempo límite. Un día de planta con miles
     * de registros sale en varias partes.
     */
    public const FOTOS_POR_PDF = 150;

    public function generarPdf(array $p, array $usr): array {
        $fecha = self::fechaValida($p['fecha'] ?? '');
        $lugar = textoEstandar($p['lugar'] ?? '');
        $incluir = in_array($p['incluir'] ?? '', ['entrada', 'salida'], true) ? $p['incluir'] : 'ambos';
        $filas = $this->registrosDelDia($fecha, $lugar);
        $totEnt = count(array_filter($filas, fn($f) => $f['tipo'] === 'entrada'));
        $totSal = count($filas) - $totEnt;
        if ($incluir !== 'ambos') $filas = array_values(array_filter($filas, fn($f) => $f['tipo'] === $incluir));
        if (!$filas) return ['status' => 'error', 'message' => 'No hay registros de ese día para incluir en el PDF.'];

        // Primero todas las entradas y luego las salidas, en partes de FOTOS_POR_PDF
        usort($filas, fn($a, $b) => [$a['tipo'] === 'salida', $a['capturado'], $a['id']] <=> [$b['tipo'] === 'salida', $b['capturado'], $b['id']]);
        $partes = (int)ceil(count($filas) / self::FOTOS_POR_PDF);
        $parte = max(1, min($partes, (int)($p['parte'] ?? 1)));
        $trozo = array_slice($filas, ($parte - 1) * self::FOTOS_POR_PDF, self::FOTOS_POR_PDF);

        if (!class_exists('Mpdf\Mpdf')) {
            $autoload = __DIR__ . '/../vendor/autoload.php';
            if (!is_file($autoload)) return ['status' => 'error', 'message' => 'No está instalado el generador de PDF.'];
            require_once $autoload;
        }
        if (function_exists('set_time_limit')) @set_time_limit(240);
        self::protegerCarpeta();
        $tmp = self::carpeta() . 'tmp/' . bin2hex(random_bytes(6)) . '/';
        @mkdir($tmp, 0755, true);
        @mkdir(self::carpeta() . 'pdf', 0755, true);

        try {
            $ent = array_values(array_filter($trozo, fn($f) => $f['tipo'] === 'entrada'));
            $sal = array_values(array_filter($trozo, fn($f) => $f['tipo'] === 'salida'));
            $info = ['parte' => $parte, 'partes' => $partes, 'tot_ent' => $totEnt, 'tot_sal' => $totSal];
            $html = $this->htmlPdf($fecha, $lugar, $incluir, $ent, $sal, $usr, $tmp, $info);

            $mpdf = new \Mpdf\Mpdf([
                'mode' => 'utf-8', 'format' => 'A4', 'orientation' => 'P',
                'margin_top' => 10, 'margin_bottom' => 14, 'margin_left' => 10, 'margin_right' => 10,
                'margin_header' => 0, 'margin_footer' => 6,
                // Una pareja de fotos que no cabe pasa completa a la hoja
                // siguiente, en vez de encogerse.
                'shrink_tables_to_fit' => 0,
            ]);
            $mpdf->SetTitle('Control de accesos ' . $fecha . ($lugar !== '' ? ' - ' . $lugar : '') . ($partes > 1 ? " - parte $parte de $partes" : ''));
            $mpdf->SetAuthor('AVBA Certificaciones');
            $mpdf->SetFooter('Control de accesos · ' . date('d/m/Y', strtotime($fecha)) . ($partes > 1 ? " · parte $parte de $partes" : '') . '||Página {PAGENO} de {nbpg}');
            $mpdf->WriteHTML($html);

            $slug = $lugar === '' ? 'TODOS' : trim(preg_replace('/[^A-Z0-9]+/', '-', $lugar), '-');
            $slug = substr($slug !== '' ? $slug : 'PUNTO', 0, 40);
            $archivo = "ACCESOS_{$fecha}_{$slug}_{$incluir}_p{$parte}_" . time() . '.pdf';
            $mpdf->Output(self::carpeta() . 'pdf/' . $archivo, 'F');
            $paginas = (int)$mpdf->page;
        } finally {
            foreach (glob($tmp . '*') ?: [] as $f) @unlink($f);
            @rmdir($tmp);
        }
        $bytes = (int)@filesize(self::carpeta() . 'pdf/' . $archivo);
        return ['status' => 'success', 'archivo' => $archivo, 'url' => 'descargar.php?tipo=accesos_pdf&f=' . rawurlencode($archivo),
                'paginas' => $paginas, 'bytes' => $bytes, 'entradas' => count($ent), 'salidas' => count($sal),
                'parte' => $parte, 'partes' => $partes, 'total' => count($filas)];
    }

    /** Copia ligera de la foto para el PDF y su tamaño para caber en la celda */
    private function fotoParaPdf(array $f, string $tmp, float $anchoMm, float $altoMm): array {
        $src = self::carpeta() . $f['foto'];
        if ($f['foto'] === '' || !is_file($src)) return ['', 0, 0];
        $dest = comprimirImagen($src, $tmp . $f['uid'] . '.jpg', 1300, 1300, 72);
        $ruta = $dest ? $tmp . $dest : $src;
        $info = @getimagesize($ruta);
        if (!$info) return ['', 0, 0];
        $k = min($anchoMm / max(1, $info[0]), $altoMm / max(1, $info[1]));
        return [$ruta, round($info[0] * $k, 1), round($info[1] * $k, 1)];
    }

    private function seccionPdf(string $titulo, string $color, string $etiqueta, array $filas, string $tmp, bool $conLugar, int $totalTipo = 0): string {
        $rango = count($filas) . ' registro' . (count($filas) === 1 ? '' : 's');
        if ($filas && $totalTipo > count($filas)) {
            $rango = 'del ' . $filas[0]['num'] . ' al ' . $filas[count($filas) - 1]['num'] . ' de ' . number_format($totalTipo);
        }
        $h = '<table width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin-top:4mm"><tr>'
           . '<td style="background:' . $color . ';color:#fff;font-weight:bold;font-size:13pt;padding:3mm 4mm">' . self::h($titulo)
           . ' &nbsp;·&nbsp; ' . $rango . '</td></tr></table>';
        if (!$filas) return $h . '<p style="font-size:10pt;color:#5a6072">Sin registros.</p>';
        foreach (array_chunk($filas, 2) as $par) {
            $h .= '<table width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;page-break-inside:avoid;margin-top:3mm"><tr>';
            foreach ([0, 1] as $i) {
                $f = $par[$i] ?? null;
                if (!$f) { $h .= '<td width="50%"></td>'; continue; }
                $lbl = '<b style="color:' . $color . '">' . $etiqueta . ' ' . str_pad((string)$f['num'], 3, '0', STR_PAD_LEFT) . '</b>'
                     . ' &nbsp;·&nbsp; ' . substr((string)$f['capturado'], 11, 8)
                     . ($conLugar ? ' &nbsp;·&nbsp; ' . self::h($f['lugar']) : '');
                $h .= '<td width="50%" style="padding:0 2mm;vertical-align:top;font-size:10pt">' . $lbl
                    . ($f['nota'] !== '' ? '<br><span style="font-size:8.5pt;color:#5a6072">' . self::h($f['nota']) . '</span>' : '') . '</td>';
            }
            // El alto del renglón es el de su foto más alta: las credenciales
            // (horizontales) ocupan la mitad y caben más por página.
            $fotos = array_map(fn($f) => $f ? $this->fotoParaPdf($f, $tmp, 88, 104) : ['', 0, 0], [$par[0] ?? null, $par[1] ?? null]);
            $alto = max(20, max($fotos[0][2], $fotos[1][2]) + 2);
            $h .= '</tr><tr>';
            foreach ([0, 1] as $i) {
                if (!isset($par[$i])) { $h .= '<td width="50%"></td>'; continue; }
                [$ruta, $w, $alt] = $fotos[$i];
                $h .= '<td width="50%" style="padding:1mm 2mm 0;vertical-align:top;height:' . $alto . 'mm;text-align:center">'
                    . ($ruta !== '' ? '<img src="' . self::h($ruta) . '" style="width:' . $w . 'mm;height:' . $alt . 'mm;border:0.3mm solid #c9d2de">'
                                    : '<span style="color:#A32D2D;font-size:9pt">Foto no disponible</span>') . '</td>';
            }
            $h .= '</tr></table>';
        }
        return $h;
    }

    private function htmlPdf(string $fecha, string $lugar, string $incluir, array $ent, array $sal, array $usr, string $tmp, array $info): string {
        $logo = realpath(__DIR__ . '/../assets/logos/avba.png');
        $conLugar = $lugar === '';
        $h = '<table width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse"><tr>'
           . '<td style="width:42mm;vertical-align:middle">' . ($logo ? '<img src="' . self::h($logo) . '" style="width:38mm">' : '') . '</td>'
           . '<td style="vertical-align:middle;padding-left:4mm"><div style="font-size:17pt;font-weight:bold;color:#0C447C">CONTROL DE ACCESOS</div>'
           . '<div style="font-size:11pt;color:#1a1a2e">' . self::h(ucfirst(self::fechaLarga($fecha))) . '</div></td></tr></table>'
           . '<table width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin-top:3mm;font-size:9.5pt">'
           . '<tr><td style="background:#f3f6fa;padding:2mm 3mm;width:44%"><b>Punto de control:</b> ' . self::h($lugar !== '' ? $lugar : 'Todos') . '</td>'
           . '<td style="background:#f3f6fa;padding:2mm 3mm;width:15%"><b>Entradas:</b> ' . number_format($info['tot_ent']) . '</td>'
           . '<td style="background:#f3f6fa;padding:2mm 3mm;width:15%"><b>Salidas:</b> ' . number_format($info['tot_sal']) . '</td>'
           . '<td style="background:#f3f6fa;padding:2mm 3mm"><b>Generado:</b> ' . date('d/m/Y H:i') . '</td></tr>'
           . '<tr><td colspan="4" style="padding:2mm 3mm;color:#5a6072;font-size:8.5pt">Cada fotografía lleva su etiqueta: tipo (ENTRADA o SALIDA), número consecutivo y hora de registro'
           . ($conLugar ? ' y punto de control' : '') . '. Generado por ' . self::h($usr['nombre'] ?? $usr['usuario'] ?? '') . '.'
           . ($info['partes'] > 1 ? ' <b style="color:#0C447C">Parte ' . $info['parte'] . ' de ' . $info['partes'] . '.</b>' : '')
           . '</td></tr></table>';
        if ($ent) $h .= $this->seccionPdf('ENTRADAS', '#2F6B1E', 'ENTRADA', $ent, $tmp, $conLugar, $info['tot_ent']);
        if ($ent && $sal) $h .= '<pagebreak />';
        if ($sal) $h .= $this->seccionPdf('SALIDAS', '#A32D2D', 'SALIDA', $sal, $tmp, $conLugar, $info['tot_sal']);
        return $h;
    }
}
