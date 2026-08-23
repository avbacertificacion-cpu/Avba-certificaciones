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
 * Qué se conserva: la sesión destino manda. Su folio, su QR, su fecha y su
 * lugar quedan; las demás ceden sus piezas y desaparecen. Los QR de las
 * sesiones absorbidas vuelven al banco para reutilizarse — el de cada pieza no
 * se toca, porque identifica al equipo y no a la visita.
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
     * @param int    $destinoId  la que conserva folio, QR, fecha y lugar
     * @param int[]  $origenes   las que ceden sus piezas y se eliminan
     */
    public function fusionar(int $destinoId, array $origenes, string $usuario, string $motivo = ''): array {
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

            // El QR de la sesión absorbida vuelve al banco; el de cada pieza se
            // queda con ella porque identifica al equipo, no a la visita.
            foreach ($ori as $id) {
                $qr = trim((string)($sesiones[$id]['qr_codigo'] ?? ''));
                if ($qr !== '') {
                    try {
                        $this->pdo->prepare("UPDATE qr_codigos SET usado = 0, equipo_id = NULL WHERE identificador = ?")
                            ->execute([$qr]);
                    } catch (\Throwable $e) { error_log('[FusionSesiones] liberar QR: ' . $e->getMessage()); }
                }
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
            'status'    => 'success',
            'movidos'   => count($items),
            'absorbidas'=> count($ori),
            'detalle'   => $detalle,
            // Si el destino ya estaba emitido, sus documentos se quedaron sin
            // las piezas nuevas hasta que Certificaciones vuelva a emitir.
            'reemitir'  => $destinoPublicado || (bool)$publicadas,
            'message'   => count($items) . ' pieza(s) movidas desde ' . count($ori) . ' inspección(es). '
                         . 'Todo quedó en el folio ' . ($sesiones[$destinoId]['control'] ?: "#$destinoId") . '.',
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

    /** Compara nombres de cliente sin acentos, mayúsculas ni espacios de más. */
    private function normalizar(string $s): string {
        $s = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s)), 'UTF-8');
        return strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
    }
}
