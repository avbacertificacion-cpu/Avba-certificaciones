<?php
/**
 * AVBA Certificaciones — Recomendaciones del inspector
 *
 * Lo que el inspector ve en campo y vale la pena decir, pero NO pertenece al
 * alcance de lo que se certifica: el extintor vencido junto al equipo, la
 * bitácora que no estaba en la cabina, el cable que hoy cumple pero conviene
 * cambiar antes del año. Meterlo en el dictamen sería incorrecto —no es una no
 * conformidad contra la norma evaluada— y decirlo de palabra en la obra se
 * pierde. Va aquí, con seguimiento.
 *
 * QUIÉN ESCRIBE. El inspector, y nadie más. Calidad, Certificaciones y Admin
 * pueden ajustar la REDACCIÓN —precisar un término, corregir una palabra— pero
 * no el sentido. Por eso el texto original se guarda aparte y no se toca nunca:
 * si alguien cambia el fondo del mensaje, se nota al compararlos.
 *
 * CUÁNDO LA VE EL CLIENTE. Cuando se cumplen DOS cosas: que Calidad la haya
 * aprobado y que Certificaciones haya publicado la documentación del equipo.
 * Esa segunda condición no se guarda aquí: se consulta del equipo. Si fuera una
 * bandera propia habría que acordarse de encenderla en los cuatro caminos que
 * publican un equipo, y el día que se agregue un quinto alguien se olvidaría y
 * la recomendación llegaría antes que el dictamen. Derivándola es imposible.
 */
class Recomendaciones {
    private PDO $pdo;

    /** Las pone el inspector, que es quien estuvo ahí. */
    public const PRIORIDADES = ['alta', 'media', 'sugerencia'];

    /**
     * BORRADOR   — escrita por el inspector, Calidad no la ha revisado.
     * APROBADA   — Calidad la revisó. Visible en cuanto se publique el equipo.
     * ENTERADO   — el cliente acusó recibo; aún no la atiende.
     * ATENDIDA   — el cliente dice haberla resuelto y subió evidencia.
     * CERRADA    — AVBA revisó la evidencia y la dio por buena.
     * DESCARTADA — Calidad decidió que no sale. El cliente nunca la ve.
     */
    public const ESTADOS = ['BORRADOR','APROBADA','ENTERADO','ATENDIDA','CERRADA','DESCARTADA'];

    /** Estados en los que el cliente puede verla, si su equipo está publicado. */
    private const VISIBLES = ['APROBADA','ENTERADO','ATENDIDA','CERRADA'];

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->migrate();
    }

    private function migrate(): void {
        $tablas = [
            "CREATE TABLE IF NOT EXISTS recomendaciones (
               id             INT AUTO_INCREMENT PRIMARY KEY,
               origen         VARCHAR(20)  NOT NULL DEFAULT 'equipo',
               origen_id      INT          NOT NULL,
               texto          TEXT         NOT NULL,
               texto_original TEXT         NOT NULL,
               prioridad      VARCHAR(12)  NOT NULL DEFAULT 'media',
               foto_url       VARCHAR(500) NULL,
               estado         VARCHAR(20)  NOT NULL DEFAULT 'BORRADOR',
               creada_por     VARCHAR(120) NOT NULL,
               capturada_por  VARCHAR(40)  NULL,
               aprobada_por   VARCHAR(120) NULL,
               aprobada_at    DATETIME     NULL,
               cerrada_por    VARCHAR(120) NULL,
               cerrada_at     DATETIME     NULL,
               orden          INT          NOT NULL DEFAULT 0,
               created_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
               INDEX idx_origen (origen, origen_id),
               INDEX idx_estado (estado)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            // El hilo: el acuse del cliente, su reporte de atención y lo que
            // AVBA conteste. Es una tabla y no tres columnas porque el cliente
            // puede acusar primero y atender después, y lo primero no se pisa.
            "CREATE TABLE IF NOT EXISTS recomendaciones_respuesta (
               id              INT AUTO_INCREMENT PRIMARY KEY,
               recomendacion_id INT         NOT NULL,
               tipo            VARCHAR(20)  NOT NULL,
               comentario      TEXT         NULL,
               usuario         VARCHAR(160) NOT NULL,
               es_cliente      TINYINT(1)   NOT NULL DEFAULT 0,
               created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
               INDEX idx_rec (recomendacion_id)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS recomendaciones_evidencia (
               id              INT AUTO_INCREMENT PRIMARY KEY,
               recomendacion_id INT         NOT NULL,
               respuesta_id    INT          NULL,
               url             VARCHAR(500) NOT NULL,
               nombre          VARCHAR(200) NULL,
               subida_por      VARCHAR(160) NULL,
               created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
               INDEX idx_rec (recomendacion_id)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            // Quién cambió qué. Tres roles pueden editar lo que escribió el
            // inspector: sin esto, su redacción se podría alterar sin rastro, y
            // en una unidad acreditada eso no se sostiene.
            "CREATE TABLE IF NOT EXISTS recomendaciones_historial (
               id              INT AUTO_INCREMENT PRIMARY KEY,
               recomendacion_id INT         NOT NULL,
               usuario         VARCHAR(160) NOT NULL,
               campo           VARCHAR(40)  NOT NULL,
               valor_anterior  TEXT         NULL,
               valor_nuevo     TEXT         NULL,
               created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
               INDEX idx_rec (recomendacion_id)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];
        foreach ($tablas as $sql) {
            try { $this->pdo->exec($sql); }
            catch (\Throwable $e) { error_log('[Recomendaciones] migrate: ' . $e->getMessage()); }
        }
        // Llegó después: las tablas pueden existir ya sin ella.
        try {
            if (!columnaExiste($this->pdo, 'recomendaciones', 'capturada_por')) {
                $this->pdo->exec("ALTER TABLE recomendaciones ADD COLUMN capturada_por VARCHAR(40) NULL");
            }
        } catch (\Throwable $e) {
            error_log('[Recomendaciones] columna capturada_por: ' . $e->getMessage());
        }
    }

    private function anotar(int $recId, string $usuario, string $campo, ?string $antes, ?string $despues): void {
        try {
            $this->pdo->prepare(
                "INSERT INTO recomendaciones_historial
                   (recomendacion_id, usuario, campo, valor_anterior, valor_nuevo)
                 VALUES (?,?,?,?,?)"
            )->execute([$recId, $usuario, $campo, $antes, $despues]);
        } catch (\Throwable $e) {
            error_log('[Recomendaciones] historial: ' . $e->getMessage());
        }
    }

    private static function limpiarPrioridad(?string $p): string {
        $p = strtolower(trim((string)$p));
        return in_array($p, self::PRIORIDADES, true) ? $p : 'media';
    }

    // ── Alta: sólo el inspector ───────────────────────────

    /**
     * Guarda las recomendaciones que vienen con una inspección recién
     * capturada. Lo que no traiga texto se ignora en silencio: el formulario
     * deja renglones vacíos y eso no es un error que deba frenar el guardado
     * de la inspección entera.
     */
    public function guardarDeInspeccion(int $equipoId, array $lista, string $usuario): int {
        if (!$equipoId || !$lista) return 0;
        $n = 0;
        foreach (array_values($lista) as $i => $r) {
            $texto = trim((string)($r['texto'] ?? ''));
            if ($texto === '') continue;
            try {
                $this->crear('equipo', $equipoId, $texto,
                             self::limpiarPrioridad($r['prioridad'] ?? ''), $usuario, $i);
                $n++;
            } catch (\Throwable $e) {
                // Una recomendación que falle no puede tirar la inspección.
                error_log('[Recomendaciones] alta desde inspección: ' . $e->getMessage());
            }
        }
        return $n;
    }

    /**
     * @param string $capturadaPor Rol de quien la teclea, cuando no es el
     *        inspector. Una inspección vieja —o una que se hizo antes de que
     *        existiera este apartado— no tiene forma de recibir la
     *        recomendación por el camino normal, así que Calidad puede
     *        capturarla; pero el documento tiene que decir quién la escribió,
     *        porque no es lo mismo que la dicte quien estuvo en el equipo.
     */
    public function crear(string $origen, int $origenId, string $texto, string $prioridad,
                          string $usuario, int $orden = 0, string $capturadaPor = ''): array {
        $texto = trim($texto);
        if ($texto === '')  return ['status' => 'error', 'message' => 'Escribe la recomendación.'];
        if (!$origenId)     return ['status' => 'error', 'message' => 'Falta el equipo.'];

        $this->pdo->prepare(
            "INSERT INTO recomendaciones
               (origen, origen_id, texto, texto_original, prioridad, creada_por, capturada_por, orden)
             VALUES (?,?,?,?,?,?,?,?)"
        )->execute([$origen, $origenId, $texto, $texto, self::limpiarPrioridad($prioridad),
                    $usuario, $capturadaPor ?: null, $orden]);

        $id = (int)$this->pdo->lastInsertId();
        $this->anotar($id, $usuario, 'alta', null,
                      $texto . ($capturadaPor ? "\n[capturada desde $capturadaPor]" : ''));
        return ['status' => 'success', 'id' => $id];
    }

    public function obtener(int $id): ?array {
        $st = $this->pdo->prepare("SELECT * FROM recomendaciones WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // ── Revisión: Calidad, Certificaciones, Admin ─────────

    /**
     * Ajusta la redacción. El texto original NO se toca: Calidad precisa un
     * término o corrige una palabra, pero el sentido es el del inspector, y
     * para poder comprobarlo hay que conservar lo que escribió.
     */
    public function editar(int $id, array $campos, string $usuario): array {
        $r = $this->obtener($id);
        if (!$r) return ['status' => 'error', 'message' => 'Recomendación no encontrada.'];
        if ($r['estado'] === 'DESCARTADA')
            return ['status' => 'error', 'message' => 'Esta recomendación fue descartada.'];

        $sets = [];
        $vals = [];

        if (array_key_exists('texto', $campos)) {
            $nuevo = trim((string)$campos['texto']);
            if ($nuevo === '') return ['status' => 'error', 'message' => 'El texto no puede quedar vacío.'];
            if ($nuevo !== $r['texto']) {
                $sets[] = 'texto = ?'; $vals[] = $nuevo;
                $this->anotar($id, $usuario, 'texto', $r['texto'], $nuevo);
            }
        }
        if (array_key_exists('prioridad', $campos)) {
            $nueva = self::limpiarPrioridad($campos['prioridad']);
            if ($nueva !== $r['prioridad']) {
                $sets[] = 'prioridad = ?'; $vals[] = $nueva;
                $this->anotar($id, $usuario, 'prioridad', $r['prioridad'], $nueva);
            }
        }
        if (!$sets) return ['status' => 'success', 'message' => 'Sin cambios.', 'id' => $id];

        $vals[] = $id;
        $this->pdo->prepare("UPDATE recomendaciones SET " . implode(', ', $sets) . " WHERE id = ?")
                  ->execute($vals);
        return ['status' => 'success', 'id' => $id, 'message' => 'Recomendación actualizada.'];
    }

    public function aprobar(int $id, string $usuario): array {
        $r = $this->obtener($id);
        if (!$r) return ['status' => 'error', 'message' => 'Recomendación no encontrada.'];
        if (in_array($r['estado'], self::VISIBLES, true))
            return ['status' => 'success', 'id' => $id, 'message' => 'Ya estaba aprobada.'];

        $this->pdo->prepare(
            "UPDATE recomendaciones SET estado='APROBADA', aprobada_por=?, aprobada_at=NOW() WHERE id=?"
        )->execute([$usuario, $id]);
        $this->anotar($id, $usuario, 'estado', $r['estado'], 'APROBADA');

        // No se avisa a nadie aquí: la recomendación viaja con la
        // documentación, y la documentación la publica Certificaciones.
        return ['status' => 'success', 'id' => $id,
                'message' => 'Aprobada. Llegará al cliente cuando Certificaciones publique la documentación.'];
    }

    public function descartar(int $id, string $usuario, string $motivo = ''): array {
        $r = $this->obtener($id);
        if (!$r) return ['status' => 'error', 'message' => 'Recomendación no encontrada.'];
        $this->pdo->prepare("UPDATE recomendaciones SET estado='DESCARTADA' WHERE id=?")->execute([$id]);
        $this->anotar($id, $usuario, 'estado', $r['estado'], 'DESCARTADA' . ($motivo ? ': ' . $motivo : ''));
        return ['status' => 'success', 'id' => $id, 'message' => 'Recomendación descartada. El cliente no la verá.'];
    }

    // ── Consulta ──────────────────────────────────────────

    /** Todas las de un equipo, para las pantallas de AVBA. */
    public function listar(string $origen, int $origenId, bool $incluirDescartadas = true): array {
        $sql = "SELECT * FROM recomendaciones WHERE origen = ? AND origen_id = ?";
        if (!$incluirDescartadas) $sql .= " AND estado <> 'DESCARTADA'";
        $sql .= " ORDER BY FIELD(prioridad,'alta','media','sugerencia'), orden, id";
        $st = $this->pdo->prepare($sql);
        $st->execute([$origen, $origenId]);
        $filas = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($filas as &$f) {
            $f['respuestas'] = $this->respuestasDe((int)$f['id']);
            $f['editada']    = trim((string)$f['texto']) !== trim((string)$f['texto_original']);
        }
        return $filas;
    }

    private function respuestasDe(int $recId): array {
        $st = $this->pdo->prepare(
            "SELECT id, tipo, comentario, usuario, es_cliente, created_at
             FROM recomendaciones_respuesta WHERE recomendacion_id = ? ORDER BY id"
        );
        $st->execute([$recId]);
        $resp = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $ev = $this->pdo->prepare(
            "SELECT respuesta_id, url, nombre FROM recomendaciones_evidencia
             WHERE recomendacion_id = ? ORDER BY id"
        );
        $ev->execute([$recId]);
        $porResp = [];
        foreach ($ev->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $porResp[(int)$e['respuesta_id']][] = ['url' => $e['url'], 'nombre' => $e['nombre']];
        }
        foreach ($resp as &$r) $r['evidencias'] = $porResp[(int)$r['id']] ?? [];
        return $resp;
    }

    // ── El cliente ────────────────────────────────────────

    /**
     * Lo que el cliente puede ver, agrupado por equipo.
     *
     * Las DOS condiciones van en el mismo WHERE y no hay forma de saltarse
     * ninguna: la recomendación tiene que estar aprobada Y el equipo publicado.
     * Esto último se lee del equipo, no de una bandera propia, así que vale
     * para los cuatro caminos que publican documentación y para los que se
     * agreguen después.
     */
    public function paraCliente(string $idCliente): array {
        $idCliente = trim($idCliente);
        if ($idCliente === '') return [];
        if (ctype_digit($idCliente)) $idCliente = str_pad($idCliente, 5, '0', STR_PAD_LEFT);

        $marcadores = implode(',', array_fill(0, count(self::VISIBLES), '?'));
        try {
            $st = $this->pdo->prepare(
                "SELECT r.* FROM recomendaciones r
                 JOIN equipos e ON e.id = r.origen_id AND r.origen = 'equipo'
                 WHERE e.control LIKE ?
                   AND (e.publicado = 1 OR e.estado = 'ENVIADO')
                   AND r.estado IN ($marcadores)
                 ORDER BY r.origen_id,
                          FIELD(r.prioridad,'alta','media','sugerencia'), r.orden, r.id"
            );
            $st->execute(array_merge([$idCliente . '-%'], self::VISIBLES));
            $filas = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            // El módulo puede no haberse usado todavía: el portal no se cae.
            error_log('[Recomendaciones] paraCliente: ' . $e->getMessage());
            return [];
        }

        $porEquipo = [];
        foreach ($filas as $f) {
            $porEquipo[(int)$f['origen_id']][] = [
                'id'         => (int)$f['id'],
                'texto'      => $f['texto'],
                'prioridad'  => $f['prioridad'],
                'estado'     => $f['estado'],
                'foto_url'   => $f['foto_url'] ?? '',
                // Al cliente no se le dice quién la redactó ni si Calidad la
                // ajustó: eso es cocina interna. Se le dice qué y cuándo.
                'fecha'      => $f['created_at'] ? date('d/m/Y', strtotime($f['created_at'])) : '',
                'respuestas' => $this->respuestasDe((int)$f['id']),
            ];
        }
        return $porEquipo;
    }

    /**
     * Comprueba que esa recomendación es de un equipo de ese cliente Y que ya
     * está publicada. Sin esto, cualquier cliente podría responder la
     * recomendación de otro con sólo adivinar el id.
     */
    private function esDelCliente(int $id, string $idCliente): ?array {
        $idCliente = trim($idCliente);
        if ($idCliente === '') return null;
        if (ctype_digit($idCliente)) $idCliente = str_pad($idCliente, 5, '0', STR_PAD_LEFT);

        $marcadores = implode(',', array_fill(0, count(self::VISIBLES), '?'));
        $st = $this->pdo->prepare(
            "SELECT r.* FROM recomendaciones r
             JOIN equipos e ON e.id = r.origen_id AND r.origen = 'equipo'
             WHERE r.id = ? AND e.control LIKE ?
               AND (e.publicado = 1 OR e.estado = 'ENVIADO')
               AND r.estado IN ($marcadores)"
        );
        $st->execute(array_merge([$id, $idCliente . '-%'], self::VISIBLES));
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * El cliente acusa recibo o reporta que ya la atendió.
     *
     * Son dos cosas distintas a propósito: "estoy de acuerdo" no es "ya lo
     * hice", y el acuse por sí solo ya vale —deja constancia de que se le dijo
     * y cuándo, aunque el trabajo no se haya hecho todavía.
     *
     * @param string $tipo 'enterado' | 'atendida'
     */
    public function responderCliente(int $id, string $tipo, string $comentario,
                                     array $files, string $idCliente, string $nombre): array {
        $r = $this->esDelCliente($id, $idCliente);
        if (!$r) return ['status' => 'error', 'message' => 'Recomendación no encontrada.'];

        $tipo = $tipo === 'atendida' ? 'atendida' : 'enterado';
        if ($r['estado'] === 'CERRADA')
            return ['status' => 'error', 'message' => 'Esta recomendación ya fue cerrada por AVBA.'];
        if ($tipo === 'enterado' && $r['estado'] !== 'APROBADA')
            return ['status' => 'error', 'message' => 'Ya habías respondido a esta recomendación.'];

        $comentario = mb_substr(trim($comentario), 0, 2000);
        $nombre     = mb_substr(trim($nombre) ?: 'Cliente', 0, 160);

        $this->pdo->prepare(
            "INSERT INTO recomendaciones_respuesta
               (recomendacion_id, tipo, comentario, usuario, es_cliente)
             VALUES (?,?,?,?,1)"
        )->execute([$id, $tipo, $comentario ?: null, $nombre]);
        $respId = (int)$this->pdo->lastInsertId();

        $nFotos = $this->guardarEvidencia($files, $id, $respId, $idCliente, $nombre);

        $nuevo = $tipo === 'atendida' ? 'ATENDIDA' : 'ENTERADO';
        $this->pdo->prepare("UPDATE recomendaciones SET estado = ? WHERE id = ?")->execute([$nuevo, $id]);
        $this->anotar($id, $nombre . ' (cliente)', 'estado', $r['estado'], $nuevo);

        return ['status' => 'success', 'id' => $id, 'estado' => $nuevo, 'evidencias' => $nFotos,
                'message' => $tipo === 'atendida'
                    ? 'Gracias. AVBA revisará la evidencia y cerrará la recomendación.'
                    : 'Acuse registrado.'];
    }

    /** @return int cuántos archivos quedaron guardados */
    private function guardarEvidencia(array $files, int $recId, int $respId,
                                      string $idCliente, string $usuario): int {
        if (empty($files['evidencia']) || empty($files['evidencia']['tmp_name'])) return 0;

        $dir = rtrim(UPLOAD_DIR, '/') . "/recomendaciones/$recId/";
        if (!is_dir($dir)) @mkdir($dir, 0755, true);

        $nombres = (array)$files['evidencia']['name'];
        $ins = $this->pdo->prepare(
            "INSERT INTO recomendaciones_evidencia
               (recomendacion_id, respuesta_id, url, nombre, subida_por) VALUES (?,?,?,?,?)"
        );
        $n = 0;
        foreach ($nombres as $i => $orig) {
            if ($n >= 6) break;
            $tmp = $files['evidencia']['tmp_name'][$i] ?? '';
            if (!$tmp || ($files['evidencia']['error'][$i] ?? 1) !== 0) continue;

            $ext = strtolower(pathinfo((string)$orig, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png','webp','pdf'], true)) continue;

            $fn   = 'ev_' . $respId . '_' . ($i + 1) . '_' . date('His');
            $dest = $dir . $fn . ($ext === 'pdf' ? '.pdf' : '.jpg');

            if ($ext === 'pdf') {
                if (!move_uploaded_file($tmp, $dest)) continue;
            } else {
                $real = function_exists('comprimirImagen')
                    ? comprimirImagen($tmp, $dest, 1280, 1280, 72) : null;
                if (!$real && !move_uploaded_file($tmp, $dest)) continue;
                if ($real) $dest = $dir . $real;
            }

            $url = rtrim(UPLOAD_URL, '/') . '/recomendaciones/' . $recId . '/' . basename($dest);
            $ins->execute([$recId, $respId, $url, mb_substr((string)$orig, 0, 200), $usuario]);
            $n++;
        }
        return $n;
    }

    // ── Cierre: Calidad, Certificaciones, Admin ───────────

    public function cerrar(int $id, string $usuario, string $comentario = ''): array {
        $r = $this->obtener($id);
        if (!$r) return ['status' => 'error', 'message' => 'Recomendación no encontrada.'];
        if ($r['estado'] === 'CERRADA')
            return ['status' => 'success', 'id' => $id, 'message' => 'Ya estaba cerrada.'];

        if (trim($comentario) !== '') $this->respuestaAvba($id, 'avba', $comentario, $usuario);
        $this->pdo->prepare(
            "UPDATE recomendaciones SET estado='CERRADA', cerrada_por=?, cerrada_at=NOW() WHERE id=?"
        )->execute([$usuario, $id]);
        $this->anotar($id, $usuario, 'estado', $r['estado'], 'CERRADA');
        return ['status' => 'success', 'id' => $id, 'message' => 'Recomendación cerrada.'];
    }

    /**
     * Devuelve la recomendación al cliente porque la evidencia no alcanzó.
     * El motivo es obligatorio: reabrir sin decir qué falta deja al cliente
     * adivinando, y es la manera más rápida de que deje de contestar.
     */
    public function reabrir(int $id, string $usuario, string $motivo): array {
        $r = $this->obtener($id);
        if (!$r) return ['status' => 'error', 'message' => 'Recomendación no encontrada.'];
        $motivo = trim($motivo);
        if ($motivo === '')
            return ['status' => 'error', 'message' => 'Explica qué falta para poder reabrirla.'];

        $this->respuestaAvba($id, 'avba', $motivo, $usuario);
        $this->pdo->prepare(
            "UPDATE recomendaciones SET estado='APROBADA', cerrada_por=NULL, cerrada_at=NULL WHERE id=?"
        )->execute([$id]);
        $this->anotar($id, $usuario, 'estado', $r['estado'], 'APROBADA (reabierta): ' . $motivo);
        return ['status' => 'success', 'id' => $id, 'message' => 'Devuelta al cliente con tu comentario.'];
    }

    private function respuestaAvba(int $id, string $tipo, string $comentario, string $usuario): void {
        $this->pdo->prepare(
            "INSERT INTO recomendaciones_respuesta
               (recomendacion_id, tipo, comentario, usuario, es_cliente) VALUES (?,?,?,?,0)"
        )->execute([$id, $tipo, mb_substr(trim($comentario), 0, 2000), $usuario]);
    }

    // ── La hoja en PDF ────────────────────────────────────

    /**
     * La hoja de recomendaciones de un equipo.
     *
     * No es el dictamen ni lo sustituye, y el documento lo dice en su propio
     * encabezado: quien lo reciba suelto tiene que poder saber qué es. Incluye
     * el estado y lo que el cliente contestó, así que descargarla en diciembre
     * sirve de constancia de seguimiento, no sólo de lo que se recomendó.
     */
    public function hojaPdf(int $equipoId, string $idCliente = ''): array {
        $eq = $this->equipoDe($equipoId, $idCliente);
        if (!$eq) return ['status' => 'error', 'message' => 'Equipo no encontrado.'];

        $recs = array_values(array_filter(
            $this->listar('equipo', $equipoId, false),
            fn($r) => in_array($r['estado'], self::VISIBLES, true)
        ));
        if (!$recs) return ['status' => 'error', 'message' => 'Este equipo no tiene recomendaciones publicadas.'];

        $e = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
        $rotulo = ['alta' => 'PRIORIDAD ALTA', 'media' => 'PRIORIDAD MEDIA', 'sugerencia' => 'SUGERENCIA'];
        $color  = ['alta' => '#A32D2D', 'media' => '#854F0B', 'sugerencia' => '#185FA5'];
        $estado = ['APROBADA' => 'Sin atender', 'ENTERADO' => 'Enterado, pendiente',
                   'ATENDIDA' => 'Atendida, en revisión', 'CERRADA' => 'Cerrada'];

        $filas = '';
        foreach ($recs as $i => $r) {
            $hilo = '';
            foreach ($r['respuestas'] as $x) {
                $quien = ((int)$x['es_cliente'] === 1 ? 'Cliente' : 'AVBA') . ' · ' . $e($x['usuario']);
                $nEv   = count($x['evidencias'] ?? []);
                $hilo .= '<div style="margin-top:3mm;padding:2.5mm 3mm;background:#f4f7fb;border-radius:2mm">'
                       . '<div style="font-size:7.5pt;font-weight:bold;color:#5a6072">' . $quien . '</div>'
                       . ($x['comentario'] ? '<div style="font-size:9pt">' . $e($x['comentario']) . '</div>' : '')
                       . ($nEv ? '<div style="font-size:7.5pt;color:#9299a8">' . $nEv
                               . ' archivo(s) de evidencia en el portal</div>' : '')
                       . '</div>';
            }
            $filas .= '<div style="border:0.4mm solid #dfe5ef;border-left:1.2mm solid '
                   . ($color[$r['prioridad']] ?? '#185FA5') . ';border-radius:2mm;padding:3.5mm 4mm;margin-bottom:3.5mm">'
                   . '<table width="100%"><tr>'
                   . '<td style="font-size:7.5pt;font-weight:bold;color:' . ($color[$r['prioridad']] ?? '#185FA5') . '">'
                   . ($rotulo[$r['prioridad']] ?? '') . '</td>'
                   . '<td align="right" style="font-size:7.5pt;color:#5a6072">'
                   . ($estado[$r['estado']] ?? '') . '</td></tr></table>'
                   . '<div style="font-size:10pt;line-height:1.5;margin-top:1.5mm">' . $e($r['texto']) . '</div>'
                   . $hilo . '</div>';
        }

        $html = '<html><head><meta charset="utf-8"><style>
            body{font-family:dejavusans;color:#1a1a2e;}
            /* Fondo claro: el logo de AVBA es azul marino y sobre la banda
               oscura se perdía. */
            .hdr{padding:7mm 8mm 4mm;border-bottom:1.5mm solid #0C447C;}
            .hdr h1{font-size:15pt;margin:0;color:#0C447C;}
            .hdr p{font-size:8.5pt;margin:1mm 0 0;color:#5a6072;}
            .cuerpo{padding:6mm 8mm;}
            .aviso{background:#E6F1FB;border:0.3mm solid #bcd9f2;border-radius:2mm;
                   padding:3mm 4mm;font-size:8.5pt;color:#0b3c6b;margin-bottom:5mm;line-height:1.45;}
            .datos{font-size:9pt;color:#5a6072;margin-bottom:5mm;}
          </style></head><body>
          <div class="hdr">
            <table width="100%"><tr>
              <td width="22%"><img src="assets/logos/avba.png" style="width:30mm"></td>
              <td align="right">
                <h1>Recomendaciones del inspector</h1>
                <p>AVBA Inspections, Certifications and Maintenance SAS. de C.V.</p>
              </td>
            </tr></table>
          </div>
          <div class="cuerpo">
            <div class="aviso"><b>Este documento no es un certificado ni un dictamen.</b><br>
              Las recomendaciones que contiene <b>no forman parte del alcance de la
              certificación</b>: son apuntes del inspector durante la visita. El resultado
              del equipo es el que indica su dictamen de inspección.</div>
            <div class="datos">
              <b>' . $e($eq['cliente']) . '</b><br>
              ' . $e($eq['maquinaria']) . ($eq['serie'] ? ' · S/N ' . $e($eq['serie']) : '') . '<br>
              Folio AB.' . $e($eq['control']) . ' · Inspección del ' . $e($eq['fecha_fmt'] ?? '') . '
            </div>' . $filas . '
            <div style="font-size:7.5pt;color:#9299a8;margin-top:4mm">
              Hoja generada el ' . date('d/m/Y') . '. El estado de cada recomendación y su
              evidencia se consultan en el portal del cliente.</div>
          </div></body></html>';

        try {
            $url = $this->aPdf($html, 'AB.' . ($eq['control'] ?: $equipoId));
        } catch (\Throwable $ex) {
            return ['status' => 'error', 'message' => 'No se pudo generar la hoja: ' . $ex->getMessage()];
        }
        return ['status' => 'success', 'url' => rtrim(SITE_URL, '/') . '/' . ltrim($url, '/')];
    }

    /** El equipo, comprobando que sea del cliente cuando lo pide un cliente. */
    private function equipoDe(int $equipoId, string $idCliente): ?array {
        $sql = "SELECT id, cliente, control, maquinaria, serie,
                       DATE_FORMAT(fecha_inspeccion,'%d/%m/%Y') AS fecha_fmt
                FROM equipos WHERE id = ?";
        $par = [$equipoId];
        if ($idCliente !== '') {
            if (ctype_digit($idCliente)) $idCliente = str_pad($idCliente, 5, '0', STR_PAD_LEFT);
            $sql .= " AND control LIKE ? AND (publicado = 1 OR estado = 'ENVIADO')";
            $par[] = $idCliente . '-%';
        }
        $st = $this->pdo->prepare($sql);
        $st->execute($par);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function aPdf(string $html, string $folio): string {
        if (!class_exists('\\Mpdf\\Mpdf')) {
            $autoload = __DIR__ . '/../vendor/autoload.php';
            if (file_exists($autoload)) require_once $autoload;
        }
        if (!class_exists('\\Mpdf\\Mpdf'))
            throw new \RuntimeException('mPDF no disponible.');

        $dir = UPLOAD_DIR . 'reportes/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8', 'format' => 'A4',
            'margin_left' => 0, 'margin_right' => 0,
            'margin_top' => 0, 'margin_bottom' => 10,
            'default_font' => 'dejavusans',
            'tempDir' => sys_get_temp_dir() . '/mpdf',
        ]);
        $mpdf->SetBasePath(__DIR__ . '/../');
        $mpdf->WriteHTML($html);
        $nombre = 'RECOM_AVBA_' . $folio . '_' . date('Ymd_His') . '.pdf';
        $mpdf->Output($dir . $nombre, 'F');
        return 'uploads/reportes/' . $nombre;
    }

    public function historial(int $id): array {
        $st = $this->pdo->prepare(
            "SELECT usuario, campo, valor_anterior, valor_nuevo, created_at
             FROM recomendaciones_historial WHERE recomendacion_id = ? ORDER BY id"
        );
        $st->execute([$id]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
