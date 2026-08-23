<?php
/**
 * AVBA Certificaciones — Fusión de sesiones de inspección
 *
 * Un mismo cliente puede acabar con varias sesiones abiertas: el inspector
 * volvió otro día, o fue otra persona. Para el cliente eso es una sola visita y
 * espera un solo expediente, así que Calidad necesita poder juntarlas.
 *
 * Vive aparte porque arneses y accesorios comparten exactamente el mismo
 * problema y la misma solución; sólo cambian los nombres de las tablas. Con una
 * copia por módulo, el primer arreglo se aplicaría en uno y se olvidaría en el
 * otro.
 *
 * Qué se conserva: la sesión destino manda. Su folio y su lugar quedan; las
 * demás ceden sus piezas y desaparecen. De cada pieza no se toca nada — ni sus
 * datos, ni su evidencia, ni el QR que le puso el inspector — porque ese código
 * identifica al equipo y no a la visita.
 *
 * Qué cambia solo, sin preguntar: el QR de la sesión, que lo asigna el sistema.
 * Si el destino no tenía, hereda el de una absorbida en vez de quedarse sin
 * código; los que sobran vuelven al banco.
 *
 * Qué se pide: la fecha. Las sesiones que se juntan son de días distintos y el
 * expediente resultante necesita una sola, que es decisión de Calidad.
 */
class FusionSesiones {

    /** Estatus en los que el expediente ya llegó al cliente. */
    private const PUBLICADOS = ['EMITIDO', 'RETORNADO'];

    private PDO $pdo;
    private array $cfg;

    /**
     * @param array $cfg  tabla_sesion, tabla_item, col_sesion, etiqueta, ref
     */
    public function __construct(PDO $pdo, array $cfg) {
        $this->pdo = $pdo;
        $this->cfg = $cfg;
    }

    /**
     * Junta varias sesiones en una.
     *
     * @param int    $destinoId  la que conserva el folio y el lugar
     * @param int[]  $origenes   las que ceden sus piezas y se eliminan
     * @param array  $opciones   ['fecha' => la del expediente ya fusionado]
     */
    public function fusionar(
        int $destinoId, array $origenes, string $usuario, string $motivo = '', array $opciones = []
    ): array {
        $t   = $this->cfg;
        $ori = array_values(array_unique(array_filter(
            array_map('intval', $origenes),
            fn($v) => $v > 0 && $v !== $destinoId
        )));

        if (!$destinoId) return ['status' => 'error', 'message' => 'Indica la inspección que conserva el folio.'];
        if (!$ori)       return ['status' => 'error', 'message' => 'Selecciona al menos otra inspección para fusionar.'];

        $sesiones = $this->cargar(array_merge([$destinoId], $ori));
        if (!isset($sesiones[$destinoId])) {
            return ['status' => 'error', 'message' => 'La inspección de destino no existe.'];
        }
        foreach ($ori as $id) {
            if (!isset($sesiones[$id])) return ['status' => 'error', 'message' => "La inspección #$id ya no existe."];
        }

        // Mismo cliente: es la única condición. La fecha y el inspector pueden
        // diferir, que es justo el caso que motiva la fusión.
        $clienteDestino = $this->normalizar((string)$sesiones[$destinoId]['cliente']);
        foreach ($ori as $id) {
            if ($this->normalizar((string)$sesiones[$id]['cliente']) !== $clienteDestino) {
                return ['status' => 'error', 'message' =>
                    'Sólo se pueden fusionar inspecciones del mismo cliente. "'
                    . $sesiones[$id]['cliente'] . '" no coincide con "' . $sesiones[$destinoId]['cliente'] . '".'];
            }
        }

        // Una sesión ya entregada desaparece del portal del cliente al
        // absorberse: eso exige constancia, igual que una baja.
        $publicadas = [];
        foreach ($ori as $id) {
            if (in_array((string)$sesiones[$id]['estatus'], self::PUBLICADOS, true)) {
                $publicadas[] = $sesiones[$id]['control'] ?: "#$id";
            }
        }
        $motivo = trim($motivo);
        if ($publicadas && $motivo === '') {
            return ['status' => 'error', 'requiere_motivo' => true, 'message' =>
                'Entre las seleccionadas hay expedientes ya emitidos al cliente (' . implode(', ', $publicadas)
                . '). Su folio dejará de existir. Indica el motivo para continuar.'];
        }

        // La fecha se valida antes de abrir la transacción: si viene mal, más
        // vale decirlo que dejar el expediente con una fecha inventada.
        $fechaCruda = trim((string)($opciones['fecha'] ?? ''));
        $fecha      = $fechaCruda === '' ? '' : $this->fechaIso($fechaCruda);
        if ($fechaCruda !== '' && $fecha === '') {
            return ['status' => 'error', 'message' => 'La fecha no es válida. Usa el formato dd/mm/aaaa.'];
        }

        $ph = implode(',', array_fill(0, count($ori), '?'));
        $this->pdo->beginTransaction();
        try {
            // Las piezas se renumeran a continuación de las que ya tenía el
            // destino, para que el dictamen las liste en un orden estable.
            $orden = (int)$this->pdo->query(
                "SELECT COALESCE(MAX(orden),0) FROM `{$t['tabla_item']}` WHERE `{$t['col_sesion']}` = " . $destinoId
            )->fetchColumn();

            $st = $this->pdo->prepare(
                "SELECT id FROM `{$t['tabla_item']}` WHERE `{$t['col_sesion']}` IN ($ph)
                 ORDER BY `{$t['col_sesion']}`, orden, id"
            );
            $st->execute($ori);
            $items = $st->fetchAll(PDO::FETCH_COLUMN);

            $upd = $this->pdo->prepare(
                "UPDATE `{$t['tabla_item']}` SET `{$t['col_sesion']}` = ?, orden = ? WHERE id = ?"
            );
            foreach ($items as $itemId) $upd->execute([$destinoId, ++$orden, (int)$itemId]);

            // La evidencia vive en una carpeta por sesión. Si se queda en la de
            // la sesión absorbida, las fotos apuntan a un expediente que ya no
            // existe y cualquier limpieza posterior se las lleva.
            $fotosMovidas = $this->moverFotos($items, $destinoId);

            // El QR de la sesión lo asigna el sistema, así que se resuelve solo.
            // Si el destino no tenía y una absorbida sí, lo hereda: es una placa
            // ya impresa y pegada, y deja el expediente sin código si se tira.
            $qrDestino  = trim((string)($sesiones[$destinoId]['qr_codigo'] ?? ''));
            $qrHeredado = '';
            $liberados  = [];
            foreach ($ori as $id) {
                $qr = trim((string)($sesiones[$id]['qr_codigo'] ?? ''));
                if ($qr === '') continue;
                if ($qrDestino === '' && $qrHeredado === '') { $qrHeredado = $qr; continue; }
                $liberados[] = $qr;
            }
            if ($qrHeredado !== '') {
                $this->pdo->prepare("UPDATE `{$t['tabla_sesion']}` SET qr_codigo = ? WHERE id = ?")
                    ->execute([$qrHeredado, $destinoId]);
            }
            foreach ($liberados as $qr) {
                try {
                    $this->pdo->prepare("UPDATE qr_codigos SET usado = 0, equipo_id = NULL WHERE identificador = ?")
                        ->execute([$qr]);
                } catch (\Throwable $e) { error_log('[FusionSesiones] liberar QR: ' . $e->getMessage()); }
            }

            // La fecha del expediente ya fusionado, la que indicó Calidad.
            if ($fecha !== '') {
                $this->pdo->prepare("UPDATE `{$t['tabla_sesion']}` SET fecha = ? WHERE id = ?")
                    ->execute([$fecha, $destinoId]);
            }

            $this->pdo->prepare("DELETE FROM `{$t['tabla_sesion']}` WHERE id IN ($ph)")->execute($ori);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            error_log('[FusionSesiones] ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'No se pudo completar la fusión; no se movió nada.'];
        }

        $detalle = array_map(
            fn($id) => ($sesiones[$id]['control'] ?: "#$id") . ' (' . ($sesiones[$id]['fecha'] ?: 'sin fecha') . ')',
            $ori
        );
        if (function_exists('registrarEliminacion')) {
            registrarEliminacion(
                $this->pdo, $usuario ?: 'sistema', $t['ref'] . '#' . $destinoId . '.fusion',
                'Fusionadas en ' . ($sesiones[$destinoId]['control'] ?: "#$destinoId") . ' — '
                    . $sesiones[$destinoId]['cliente'] . ' — ' . count($items) . ' pieza(s) de: '
                    . implode(', ', $detalle),
                $motivo
            );
        }

        $destinoPublicado = in_array((string)$sesiones[$destinoId]['estatus'], self::PUBLICADOS, true);
        return [
            'status'     => 'success',
            'movidos'    => count($items),
            'absorbidas' => count($ori),
            'detalle'    => $detalle,
            'fecha'      => $fecha,
            'fotos'      => $fotosMovidas,
            'qr_sesion'  => $qrDestino ?: $qrHeredado,
            'qr_heredado'=> $qrHeredado,
            'qr_liberados' => $liberados,
            // Si el destino ya estaba emitido, sus documentos se quedaron sin
            // las piezas nuevas hasta que Certificaciones vuelva a emitir.
            'reemitir'  => $destinoPublicado || (bool)$publicadas,
            'message'   => count($items) . ' pieza(s) movidas desde ' . count($ori) . ' inspección(es). '
                         . 'Todo quedó en el folio ' . ($sesiones[$destinoId]['control'] ?: "#$destinoId") . '.'
                         . ($qrHeredado !== '' ? ' El expediente tomó el código de sesión ' . $qrHeredado . '.' : '')
                         . ($liberados ? ' ' . count($liberados) . ' código(s) de sesión volvieron al banco.' : ''),
        ];
    }

    /** Sesiones indexadas por id. */
    private function cargar(array $ids): array {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $this->pdo->prepare(
            "SELECT id, cliente, control, estatus, qr_codigo, fecha FROM `{$this->cfg['tabla_sesion']}` WHERE id IN ($ph)"
        );
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['id']] = $r;
        return $out;
    }

    /**
     * Lleva los archivos de evidencia a la carpeta del expediente destino y
     * corrige las rutas guardadas. Lo que no se pueda mover se deja donde está
     * con su ruta intacta: una foto en la carpeta vieja se sigue viendo; una
     * ruta corregida sin archivo detrás, no.
     */
    private function moverFotos(array $items, int $destinoId): int {
        $t = $this->cfg;
        if (!$items || empty($t['tabla_foto']) || empty($t['col_foto_item']) || empty($t['dir_fotos'])) return 0;

        $base = dirname(__DIR__) . '/';
        $dir  = $t['dir_fotos'] . $destinoId . '/';
        $ph   = implode(',', array_fill(0, count($items), '?'));
        $movidas = 0;
        try {
            $q = $this->pdo->prepare(
                "SELECT id, url FROM `{$t['tabla_foto']}` WHERE `{$t['col_foto_item']}` IN ($ph)"
            );
            $q->execute($items);
            $fotos = $q->fetchAll(PDO::FETCH_ASSOC);
            if (!$fotos) return 0;
            if (!is_dir($base . $dir) && !@mkdir($base . $dir, 0755, true)) return 0;

            $upd = $this->pdo->prepare("UPDATE `{$t['tabla_foto']}` SET url = ? WHERE id = ?");
            foreach ($fotos as $f) {
                $url = ltrim((string)$f['url'], '/');
                if ($url === '' || str_starts_with($url, $dir)) continue;   // ya está en su sitio
                $src = $base . $url;
                if (!is_file($src)) continue;

                // Dos sesiones pueden traer un archivo con el mismo nombre.
                $nombre = basename($url);
                $destino = $dir . $nombre;
                $n = 1;
                while (is_file($base . $destino)) {
                    $ext = pathinfo($nombre, PATHINFO_EXTENSION);
                    $destino = $dir . pathinfo($nombre, PATHINFO_FILENAME) . '_' . (++$n) . ($ext ? '.' . $ext : '');
                }
                if (!@rename($src, $base . $destino)) continue;
                $upd->execute([$destino, (int)$f['id']]);
                $movidas++;
            }
        } catch (\Throwable $e) {
            error_log('[FusionSesiones] mover fotos: ' . $e->getMessage());
        }
        return $movidas;
    }

    /** Acepta dd/mm/aaaa y aaaa-mm-dd; devuelve '' si la fecha no existe. */
    private function fechaIso(string $v): string {
        $v = trim($v);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) {
            return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $v : '';
        }
        if (preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{4})$#', $v, $m)) {
            return checkdate((int)$m[2], (int)$m[1], (int)$m[3])
                ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : '';
        }
        return '';
    }

    /** Compara nombres de cliente sin acentos, mayúsculas ni espacios de más. */
    private function normalizar(string $s): string {
        $s = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s)), 'UTF-8');
        return strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
    }
}
