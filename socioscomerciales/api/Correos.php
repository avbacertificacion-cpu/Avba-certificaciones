<?php
/**
 * Socios Comerciales AVBA — Correos masivos desde administración
 *
 * Permite escribir un mensaje una vez y mandárselo a todas las cuentas
 * registradas, personalizado con el nombre de cada quien.
 *
 * El envío va **por lotes**. Con `mail()` cada correo tarda decenas de
 * milisegundos: mandar quinientos en una sola petición agotaría el tiempo de
 * ejecución a la mitad, dejando a unos avisados y a otros no, y sin manera de
 * saber por dónde se quedó. Por eso cada campaña se guarda en `sc_envios` con
 * su avance (`ultimo_id`) y el cliente va pidiendo lotes hasta terminar; si el
 * navegador se cierra, se retoma exactamente donde iba.
 */

require_once __DIR__ . '/Interaccion.php';

class ScCorreos {
    private PDO $pdo;

    /** Cuántos correos salen en cada petición. */
    private const LOTE = 25;

    private const MAX_ASUNTO = 190;
    private const MAX_CUERPO = 5000;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    // ══════════════════════════════════════════════════════════
    //  DESTINATARIOS
    // ══════════════════════════════════════════════════════════

    /**
     * Condición SQL de a quién alcanza la campaña.
     * Devuelve [condicion, params].
     */
    private function filtro(string $destinatarios, bool $soloVerificados, int $desdeId = 0): array {
        $where  = ['u.activo = 1'];
        $params = [];

        if ($destinatarios === 'persona' || $destinatarios === 'empresa') {
            $where[]  = 'u.tipo = ?';
            $params[] = $destinatarios;
        }
        if ($soloVerificados) {
            $where[] = 'u.correo_verificado = 1';
        }
        if ($desdeId > 0) {
            // El avance va por id y no por OFFSET: si alguien se registra a
            // mitad del envío, un OFFSET desplazaría la lista y saltaría o
            // repetiría destinatarios.
            $where[]  = 'u.id > ?';
            $params[] = $desdeId;
        }

        return [implode(' AND ', $where), $params];
    }

    /** Cuántos recibirían la campaña con estos filtros, y algunos ejemplos. */
    public function destinatarios(array $filtros): array {
        $tipo = strtolower(trim($filtros['destinatarios'] ?? 'todos'));
        if (!in_array($tipo, ['todos', 'persona', 'empresa'], true)) $tipo = 'todos';

        $soloVerificados = ($filtros['solo_verificados'] ?? '1') !== '0';

        [$condicion, $params] = $this->filtro($tipo, $soloVerificados);

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM sc_usuarios u WHERE {$condicion}");
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        $stmt = $this->pdo->prepare(
            "SELECT u.correo, COALESCE(p.nombre, e.nombre) AS nombre, u.tipo
             FROM sc_usuarios u
             LEFT JOIN sc_personas p ON p.usuario_id = u.id
             LEFT JOIN sc_empresas e ON e.usuario_id = u.id
             WHERE {$condicion}
             ORDER BY u.id ASC LIMIT 5"
        );
        $stmt->execute($params);

        // Cuántos quedarían fuera por no haber confirmado el correo: es el dato
        // que hace falta para decidir si conviene incluirlos o no.
        $sinVerificar = 0;
        if ($soloVerificados) {
            [$cond2, $par2] = $this->filtro($tipo, false);
            $s = $this->pdo->prepare(
                "SELECT COUNT(*) FROM sc_usuarios u WHERE {$cond2} AND u.correo_verificado = 0"
            );
            $s->execute($par2);
            $sinVerificar = (int) $s->fetchColumn();
        }

        return [
            'status'        => 'success',
            'total'         => $total,
            'sin_verificar' => $sinVerificar,
            'ejemplos'      => $stmt->fetchAll(),
        ];
    }

    // ══════════════════════════════════════════════════════════
    //  REDACCIÓN
    // ══════════════════════════════════════════════════════════

    /**
     * Convierte el texto que escribió administración en el HTML del correo.
     *
     * El cuerpo lo escribe una persona de AVBA, pero se escapa igual: un
     * pegado desde Word puede traer caracteres que rompan el HTML, y de paso
     * queda claro que aquí nunca se inyecta marcado.
     */
    private function cuerpoHtml(string $plantilla, string $nombre): string {
        $texto = str_replace('{nombre}', $nombre, $plantilla);
        return nl2br(htmlspecialchars($texto, ENT_QUOTES, 'UTF-8'));
    }

    /** El asunto también admite {nombre}. */
    private function asuntoFinal(string $plantilla, string $nombre): string {
        return str_replace('{nombre}', $nombre, $plantilla);
    }

    private function validar(array $payload): array {
        $asunto = scTexto($payload['asunto'] ?? null, self::MAX_ASUNTO);
        $cuerpo = scTexto($payload['cuerpo'] ?? null, self::MAX_CUERPO);

        if (!$asunto) return ['error' => 'Escribe el asunto del correo.'];
        if (!$cuerpo) return ['error' => 'Escribe el mensaje.'];

        $tipo = strtolower(trim($payload['destinatarios'] ?? 'todos'));
        if (!in_array($tipo, ['todos', 'persona', 'empresa'], true)) $tipo = 'todos';

        return [
            'asunto'           => $asunto,
            'cuerpo'           => $cuerpo,
            'destinatarios'    => $tipo,
            'solo_verificados' => ($payload['solo_verificados'] ?? '1') !== '0'
                                  && ($payload['solo_verificados'] ?? true) !== false,
            'bloques'          => self::limpiarLista($payload['bloques'] ?? '', array_keys(self::BLOQUES)),
            'preguntas'        => self::limpiarLista($payload['preguntas'] ?? '', array_keys(ScInteraccion::PREGUNTAS)),
        ];
    }

    /**
     * Deja solo las claves conocidas de una lista separada por comas.
     *
     * Estas claves acaban decidiendo qué se le enseña a la gente, así que se
     * comparan contra el catálogo en vez de confiar en lo que llegue: un
     * valor inventado no debe llegar nunca a la base ni al correo.
     */
    private static function limpiarLista($valor, array $permitidos): array {
        if (is_string($valor)) $valor = explode(',', $valor);
        if (!is_array($valor)) return [];

        $limpio = [];
        foreach ($valor as $v) {
            $v = trim((string) $v);
            if ($v !== '' && in_array($v, $permitidos, true) && !in_array($v, $limpio, true)) {
                $limpio[] = $v;
            }
        }
        return $limpio;
    }

    /**
     * Manda una sola copia a quien está redactando, para que vea cómo llega
     * antes de escribirle a todo el padrón. Sin esto, la única forma de
     * comprobar el formato sería mandárselo a cientos de personas.
     */
    public function prueba(array $admin, array $payload): array {
        $datos = $this->validar($payload);
        if (isset($datos['error'])) return ['status' => 'error', 'message' => $datos['error']];

        $nombre = $this->nombreDe((int) $admin['id']) ?: 'Nombre de ejemplo';

        $ok = $this->enviarUno(
            $admin['correo'],
            $this->asuntoFinal($datos['asunto'], $nombre),
            $this->cuerpoHtml($datos['cuerpo'], $nombre),
            // La prueba se arma con los datos del propio administrador: así
            // ve los bloques con contenido de verdad y no con un ejemplo.
            $this->bloquesPara((int) $admin['id'], $datos['bloques'], $datos['preguntas'])
        );

        return $ok
            ? ['status' => 'success', 'message' => 'Correo de prueba enviado a ' . $admin['correo'] . '.']
            : ['status' => 'error', 'message' => 'No se pudo enviar el correo de prueba.'];
    }

    // ══════════════════════════════════════════════════════════
    //  CAMPAÑA
    // ══════════════════════════════════════════════════════════

    /** Registra la campaña y devuelve su id y a cuántos alcanzará. */
    public function crear(array $admin, array $payload): array {
        $datos = $this->validar($payload);
        if (isset($datos['error'])) return ['status' => 'error', 'message' => $datos['error']];

        // Dos campañas por hora: escribirle al padrón entero no es algo que se
        // haga a menudo, y un envío repetido por error es difícil de deshacer.
        if (!scLimite($this->pdo, 'campana', (string) $admin['id'], 2, 3600)) {
            return [
                'status'  => 'error',
                'message' => 'Ya lanzaste dos envíos masivos en la última hora. Espera antes de lanzar otro.',
            ];
        }

        [$condicion, $params] = $this->filtro($datos['destinatarios'], $datos['solo_verificados']);
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM sc_usuarios u WHERE {$condicion}");
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        if ($total === 0) {
            return ['status' => 'error', 'message' => 'Con esos filtros no hay ningún destinatario.'];
        }

        $this->pdo->prepare(
            "INSERT INTO sc_envios
                (admin_id, admin_correo, asunto, cuerpo, destinatarios, solo_verificados, total, bloques, preguntas)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            (int) $admin['id'], $admin['correo'], $datos['asunto'], $datos['cuerpo'],
            $datos['destinatarios'], $datos['solo_verificados'] ? 1 : 0, $total,
            implode(',', $datos['bloques']), implode(',', $datos['preguntas']),
        ]);

        $id = (int) $this->pdo->lastInsertId();

        try {
            $this->pdo->prepare(
                "INSERT INTO sc_admin_log
                    (admin_id, admin_correo, accion, usuario_id, usuario_correo, detalle, ip)
                 VALUES (?, ?, 'correo_masivo', NULL, NULL, ?, ?)"
            )->execute([
                (int) $admin['id'], $admin['correo'],
                mb_substr("Envío #{$id} a {$total} cuentas — " . $datos['asunto'], 0, 255),
                scIpCliente(),
            ]);
        } catch (PDOException $e) {
            error_log('ScCorreos::crear bitacora: ' . $e->getMessage());
        }

        return [
            'status'  => 'success',
            'id'      => $id,
            'total'   => $total,
            'lote'    => self::LOTE,
            'message' => "Envío preparado para {$total} cuentas.",
        ];
    }

    /**
     * Manda el siguiente lote de una campaña y actualiza su avance.
     *
     * Devuelve siempre cuántos van y cuántos faltan, para que la pantalla
     * pueda mostrar el progreso y retomar si se interrumpió.
     */
    public function lote(array $admin, int $envioId): array {
        $stmt = $this->pdo->prepare("SELECT * FROM sc_envios WHERE id = ?");
        $stmt->execute([$envioId]);
        $envio = $stmt->fetch();

        if (!$envio) return ['status' => 'error', 'message' => 'Ese envío no existe.'];
        if ($envio['estado'] !== 'en_curso') {
            return [
                'status'    => 'success',
                'terminado' => true,
                'enviados'  => (int) $envio['enviados'],
                'fallidos'  => (int) $envio['fallidos'],
                'total'     => (int) $envio['total'],
                'message'   => 'Este envío ya había terminado.',
            ];
        }

        [$condicion, $params] = $this->filtro(
            $envio['destinatarios'],
            (int) $envio['solo_verificados'] === 1,
            (int) $envio['ultimo_id']
        );

        $stmt = $this->pdo->prepare(
            "SELECT u.id, u.correo, COALESCE(p.nombre, e.nombre) AS nombre
             FROM sc_usuarios u
             LEFT JOIN sc_personas p ON p.usuario_id = u.id
             LEFT JOIN sc_empresas e ON e.usuario_id = u.id
             WHERE {$condicion}
             ORDER BY u.id ASC
             LIMIT " . self::LOTE
        );
        $stmt->execute($params);
        $cuentas = $stmt->fetchAll();

        if (!$cuentas) {
            $this->cerrar($envioId);
            return [
                'status'    => 'success',
                'terminado' => true,
                'enviados'  => (int) $envio['enviados'],
                'fallidos'  => (int) $envio['fallidos'],
                'total'     => (int) $envio['total'],
                'message'   => 'Envío terminado.',
            ];
        }

        @set_time_limit(120);

        $enviados = 0;
        $fallidos = 0;
        $ultimoId = (int) $envio['ultimo_id'];

        $bloques   = array_filter(explode(',', (string) ($envio['bloques'] ?? '')));
        $preguntas = array_filter(explode(',', (string) ($envio['preguntas'] ?? '')));

        foreach ($cuentas as $c) {
            $nombre = $this->nombreParaSaludo($c['nombre'] ?? null, $c['correo']);

            $ok = $this->enviarUno(
                $c['correo'],
                $this->asuntoFinal($envio['asunto'], $nombre),
                $this->cuerpoHtml($envio['cuerpo'], $nombre),
                $this->bloquesPara((int) $c['id'], $bloques, $preguntas)
            );

            $ok ? $enviados++ : $fallidos++;

            // El avance se guarda aunque el correo falle: reintentar el lote
            // entero volvería a escribir a quienes sí lo recibieron.
            $ultimoId = (int) $c['id'];
        }

        $this->pdo->prepare(
            "UPDATE sc_envios
             SET enviados = enviados + ?, fallidos = fallidos + ?, ultimo_id = ?
             WHERE id = ?"
        )->execute([$enviados, $fallidos, $ultimoId, $envioId]);

        // ¿Queda alguien después de este lote?
        [$cond2, $par2] = $this->filtro(
            $envio['destinatarios'],
            (int) $envio['solo_verificados'] === 1,
            $ultimoId
        );
        $s = $this->pdo->prepare("SELECT COUNT(*) FROM sc_usuarios u WHERE {$cond2}");
        $s->execute($par2);
        $restantes = (int) $s->fetchColumn();

        if ($restantes === 0) $this->cerrar($envioId);

        $acumEnviados = (int) $envio['enviados'] + $enviados;
        $acumFallidos = (int) $envio['fallidos'] + $fallidos;

        return [
            'status'    => 'success',
            'terminado' => $restantes === 0,
            'enviados'  => $acumEnviados,
            'fallidos'  => $acumFallidos,
            'restantes' => $restantes,
            'total'     => (int) $envio['total'],
            'message'   => $restantes === 0
                ? "Envío terminado: {$acumEnviados} correos enviados"
                  . ($acumFallidos ? ", {$acumFallidos} fallidos." : '.')
                : "Van {$acumEnviados} de " . (int) $envio['total'] . '.',
        ];
    }

    private function cerrar(int $envioId): void {
        $this->pdo->prepare(
            "UPDATE sc_envios SET estado = 'terminado', terminado = NOW() WHERE id = ?"
        )->execute([$envioId]);
    }

    /** Envíos anteriores, incluido alguno que se quedara a medias. */
    public function historial(): array {
        $stmt = $this->pdo->query(
            "SELECT id, admin_correo, asunto, destinatarios, solo_verificados,
                    total, enviados, fallidos, estado, creado, terminado
             FROM sc_envios ORDER BY creado DESC LIMIT 30"
        );

        return ['status' => 'success', 'envios' => $stmt->fetchAll()];
    }

    // ══════════════════════════════════════════════════════════
    //  CORREO AUTOMÁTICO A LA SEMANA DEL REGISTRO
    // ══════════════════════════════════════════════════════════

    /** Días que deben pasar desde el alta antes de mandar el automático. */
    private const DIAS_AUTOMATICO = 7;

    /** Cuántos manda como mucho cada pasada. */
    private const LOTE_AUTOMATICO = 20;

    private const AUTO_ASUNTO_DEFECTO = 'Tu información ha sido procesada';
    private const AUTO_CUERPO_DEFECTO =
        "Estimado {nombre}:\n\n" .
        "Te informamos que tu información ha sido procesada y has pasado el primer filtro " .
        "de nuestro proceso de selección.\n\n" .
        "Nos pondremos en contacto contigo para darte los siguientes pasos. Mientras tanto, " .
        "te recomendamos mantener tu perfil actualizado en el portal.\n\n" .
        "Gracias por tu interés.";

    private function meta(string $clave, ?string $porDefecto = null): ?string {
        try {
            $stmt = $this->pdo->prepare("SELECT valor FROM sc_meta WHERE clave = ?");
            $stmt->execute([$clave]);
            $valor = $stmt->fetchColumn();
            return $valor === false ? $porDefecto : $valor;
        } catch (PDOException $e) {
            return $porDefecto;
        }
    }

    /** Una lista guardada en sc_meta; si nunca se guardó, la de por defecto. */
    private function listaMeta(string $clave, array $porDefecto): array {
        $guardado = $this->meta($clave, null);
        if ($guardado === null) return $porDefecto;          // nunca configurado
        return array_values(array_filter(explode(',', $guardado)));  // '' = todo apagado
    }

    private function guardarMeta(string $clave, string $valor): void {
        $this->pdo->prepare(
            "INSERT INTO sc_meta (clave, valor) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE valor = VALUES(valor)"
        )->execute([$clave, $valor]);
    }

    /** Configuración actual del automático, más cuántos lo esperan. */
    public function autoConfig(): array {
        $stmt = $this->pdo->prepare(
            "SELECT
               (SELECT COUNT(*) FROM sc_usuarios
                 WHERE activo = 1 AND auto_semana_enviado IS NULL
                   AND creado <= DATE_SUB(NOW(), INTERVAL ? DAY)) AS listos,
               (SELECT COUNT(*) FROM sc_usuarios
                 WHERE activo = 1 AND auto_semana_enviado IS NULL
                   AND creado > DATE_SUB(NOW(), INTERVAL ? DAY)) AS esperando,
               (SELECT COUNT(*) FROM sc_usuarios WHERE auto_semana_enviado IS NOT NULL) AS ya_enviados"
        );
        $stmt->execute([self::DIAS_AUTOMATICO, self::DIAS_AUTOMATICO]);
        $conteos = $stmt->fetch() ?: [];

        return [
            'status'    => 'success',
            'activo'    => $this->meta('auto_semana_activo', '0') === '1',
            'asunto'    => $this->meta('auto_semana_asunto', self::AUTO_ASUNTO_DEFECTO),
            'cuerpo'    => $this->meta('auto_semana_cuerpo', self::AUTO_CUERPO_DEFECTO),
            'bloques'   => $this->listaMeta('auto_semana_bloques', self::BLOQUES_POR_DEFECTO),
            'preguntas' => $this->listaMeta('auto_semana_preguntas', self::PREGUNTAS_POR_DEFECTO),
            'catalogo_bloques'   => self::BLOQUES,
            'catalogo_preguntas' => array_map(fn($p) => $p['titulo'], ScInteraccion::PREGUNTAS),
            'whatsapp'  => defined('SC_WHATSAPP') && SC_WHATSAPP !== '',
            'dias'      => self::DIAS_AUTOMATICO,
            'listos'    => (int) ($conteos['listos'] ?? 0),
            'esperando' => (int) ($conteos['esperando'] ?? 0),
            'ya_enviados' => (int) ($conteos['ya_enviados'] ?? 0),
        ];
    }

    public function autoGuardar(array $admin, array $payload): array {
        $datos = $this->validar($payload);
        if (isset($datos['error'])) return ['status' => 'error', 'message' => $datos['error']];

        $activo = !empty($payload['activo']) && $payload['activo'] !== 'false' && $payload['activo'] !== '0';

        $this->guardarMeta('auto_semana_asunto', $datos['asunto']);
        $this->guardarMeta('auto_semana_cuerpo', $datos['cuerpo']);
        $this->guardarMeta('auto_semana_activo', $activo ? '1' : '0');
        $this->guardarMeta('auto_semana_bloques', implode(',', $datos['bloques']));
        $this->guardarMeta('auto_semana_preguntas', implode(',', $datos['preguntas']));

        try {
            $this->pdo->prepare(
                "INSERT INTO sc_admin_log
                    (admin_id, admin_correo, accion, usuario_id, usuario_correo, detalle, ip)
                 VALUES (?, ?, 'correo_auto', NULL, NULL, ?, ?)"
            )->execute([
                (int) $admin['id'], $admin['correo'],
                $activo ? 'Automático de la semana ACTIVADO' : 'Automático de la semana desactivado',
                scIpCliente(),
            ]);
        } catch (PDOException $e) {
            error_log('ScCorreos::autoGuardar bitacora: ' . $e->getMessage());
        }

        return [
            'status'  => 'success',
            'message' => $activo
                ? 'Correo automático activado. Se enviará a los siete días de cada registro.'
                : 'Correo automático desactivado.',
        ];
    }

    /**
     * Manda los automáticos que ya tocan. Devuelve cuántos salieron.
     *
     * Lo llaman dos sitios: el cron del hosting (api/cron.php) y, de rebote,
     * el propio tráfico del portal. Puede haber varias pasadas a la vez, así
     * que cada cuenta se **reserva antes de enviar** con un UPDATE
     * condicional: si otra pasada ya la tomó, rowCount() es 0 y se salta. Sin
     * eso, dos peticiones simultáneas mandarían el correo por duplicado.
     *
     * Reservar antes de enviar significa que un fallo de SMTP deja a esa
     * cuenta sin correo en vez de reintentarlo. Es la opción menos mala:
     * reintentar en bucle es lo que acaba mandando el mismo correo diez veces.
     */
    public function procesarAutomaticos(int $max = self::LOTE_AUTOMATICO): array {
        if ($this->meta('auto_semana_activo', '0') !== '1') {
            return ['status' => 'success', 'enviados' => 0, 'motivo' => 'desactivado'];
        }

        $asunto    = $this->meta('auto_semana_asunto', self::AUTO_ASUNTO_DEFECTO);
        $cuerpo    = $this->meta('auto_semana_cuerpo', self::AUTO_CUERPO_DEFECTO);
        $bloques   = $this->listaMeta('auto_semana_bloques', self::BLOQUES_POR_DEFECTO);
        $preguntas = $this->listaMeta('auto_semana_preguntas', self::PREGUNTAS_POR_DEFECTO);

        $max = max(1, min($max, 100));

        $stmt = $this->pdo->prepare(
            "SELECT u.id, u.correo, COALESCE(p.nombre, e.nombre) AS nombre
             FROM sc_usuarios u
             LEFT JOIN sc_personas p ON p.usuario_id = u.id
             LEFT JOIN sc_empresas e ON e.usuario_id = u.id
             WHERE u.activo = 1
               AND u.auto_semana_enviado IS NULL
               AND u.creado <= DATE_SUB(NOW(), INTERVAL ? DAY)
             ORDER BY u.creado ASC
             LIMIT {$max}"
        );
        $stmt->execute([self::DIAS_AUTOMATICO]);
        $cuentas = $stmt->fetchAll();

        if (!$cuentas) return ['status' => 'success', 'enviados' => 0];

        // Reservar y mover de etapa van en el mismo UPDATE: el correo le dice
        // a la persona que pasó el primer filtro, así que la página de
        // seguimiento tiene que decirle lo mismo. Solo se mueve si sigue en
        // 'nuevo' o 'en_revision'; a quien ya está en entrevista o aprobado
        // no se le hace retroceder.
        $reservar = $this->pdo->prepare(
            // estatus_fecha va ANTES que estatus: MySQL evalúa las
            // asignaciones de izquierda a derecha usando el valor ya
            // actualizado, así que si se pusiera después leería el estatus
            // nuevo y la condición nunca se cumpliría.
            "UPDATE sc_usuarios
                SET auto_semana_enviado = NOW(),
                    estatus_fecha = IF(estatus IN ('nuevo','en_revision'), NOW(), estatus_fecha),
                    estatus = IF(estatus IN ('nuevo','en_revision'), 'primer_filtro', estatus)
              WHERE id = ? AND auto_semana_enviado IS NULL"
        );

        $enviados = 0;
        $fallidos = 0;

        foreach ($cuentas as $c) {
            $reservar->execute([$c['id']]);
            if ($reservar->rowCount() !== 1) continue;   // otra pasada se la llevó

            $nombre = $this->nombreParaSaludo($c['nombre'] ?? null, $c['correo']);

            $ok = $this->enviarUno(
                $c['correo'],
                $this->asuntoFinal($asunto, $nombre),
                $this->cuerpoHtml($cuerpo, $nombre),
                $this->bloquesPara((int) $c['id'], $bloques, $preguntas)
            );

            $ok ? $enviados++ : $fallidos++;
            if (!$ok) error_log('Automático de la semana falló para ' . $c['correo']);
        }

        return ['status' => 'success', 'enviados' => $enviados, 'fallidos' => $fallidos];
    }

    // ── Helpers internos ─────────────────────────────────────

    /**
     * Nombre con el que se saluda a alguien.
     *
     * Toda cuenta tiene nombre porque el registro lo exige, pero si la ficha
     * de perfil faltara el saludo quedaría como "Estimado Hola:". Se recurre
     * a la parte del correo antes de la arroba, que siempre dice algo.
     */
    private function nombreParaSaludo(?string $nombre, string $correo): string {
        $nombre = trim((string) $nombre);
        if ($nombre !== '') return $nombre;

        // strstr devuelve '' si el correo empieza por arroba; ese '' no debe
        // caer al correo entero o el saludo acabaría siendo "Estimado @X Com".
        $local = strstr($correo, '@', true);
        if ($local === false) $local = $correo;
        $local = trim(str_replace(['.', '_', '-'], ' ', $local));

        return $local !== '' ? mb_convert_case($local, MB_CASE_TITLE, 'UTF-8') : 'cliente';
    }

    private function nombreDe(int $usuarioId): ?string {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(p.nombre, e.nombre)
             FROM sc_usuarios u
             LEFT JOIN sc_personas p ON p.usuario_id = u.id
             LEFT JOIN sc_empresas e ON e.usuario_id = u.id
             WHERE u.id = ?"
        );
        $stmt->execute([$usuarioId]);
        return $stmt->fetchColumn() ?: null;
    }

    /**
     * Manda un correo ya armado.
     *
     * El título grande del correo es el asunto, no un rótulo fijo: repetir
     * "Socios Comerciales AVBA" debajo del rótulo de la cabecera gastaba el
     * renglón más visible del mensaje en decir algo que ya estaba dicho.
     */
    private function enviarUno(string $para, string $asunto, string $cuerpoHtml, string $bloques = ''): bool {
        try {
            return scEnviarCorreo(
                $para,
                $asunto,
                scPlantillaCorreo($asunto, $cuerpoHtml,
                    'Entrar al portal', scUrlBase() . '/inicio.html', $bloques)
            );
        } catch (Throwable $e) {
            error_log('ScCorreos::enviarUno: ' . $e->getMessage());
            return false;
        }
    }

    // ══════════════════════════════════════════════════════════
    //  BLOQUES QUE PIDEN ALGO
    // ══════════════════════════════════════════════════════════

    /** Bloques disponibles, con el nombre que ve el administrador. */
    public const BLOQUES = [
        'pregunta'  => 'Una pregunta con botones de respuesta',
        'avance'    => 'Qué le falta a su perfil',
        'vacantes'  => 'Vacantes abiertas que le encajan',
        'agenda'    => 'Horarios de entrevista para apartar',
        'whatsapp'  => 'Botón de WhatsApp',
        'folio'     => 'Su folio y el enlace de seguimiento',
        'referido'  => 'Invitar a un colega',
    ];

    /** Qué preguntas entran en el sorteo del bloque «pregunta». */
    public const PREGUNTAS_POR_DEFECTO = ['interes', 'disponibilidad', 'certificacion'];
    public const BLOQUES_POR_DEFECTO   = ['pregunta', 'avance', 'whatsapp'];

    /**
     * Arma las secciones extra de un correo para una persona concreta.
     *
     * Del bloque «pregunta» sale UNA sola: la primera de la lista que esa
     * persona no haya contestado. Un correo con cuatro preguntas es un
     * formulario disfrazado y no lo contesta nadie; con una sola, quien
     * pulsa aterriza en r.php y allí sigue la conversación.
     */
    public function bloquesPara(int $usuarioId, array $bloques, array $preguntas): string {
        if (!$bloques) return '';

        $inter = new ScInteraccion($this->pdo);
        $html  = '';

        try {
            if (in_array('pregunta', $bloques, true)) {
                $html .= $this->bloquePregunta($inter, $usuarioId, $preguntas);
            }
            if (in_array('avance', $bloques, true)) {
                $avance = $inter->avancePerfil($usuarioId);
                if (!$avance['completo']) {
                    $html .= scCorreoSeccion('Tu perfil', 'Te falta poco para completarlo',
                        scCorreoAvance($avance['porcentaje'], $avance['faltantes'], scUrlBase() . '/inicio.html'));
                }
            }
            if (in_array('vacantes', $bloques, true)) {
                $vacantes = $inter->vacantesPara($usuarioId, 3);
                if ($vacantes) {
                    $html .= scCorreoSeccion('Vacantes abiertas',
                        count($vacantes) === 1 ? 'Una vacante para tu perfil' : 'Vacantes para tu perfil',
                        scCorreoVacantes($vacantes, scUrlBase()));
                }
            }
            if (in_array('agenda', $bloques, true)) {
                $html .= $this->bloqueAgenda($inter, $usuarioId);
            }
            if (in_array('folio', $bloques, true)) {
                $html .= scCorreoSeccion('Seguimiento', 'Tu folio es ' . scFolio($usuarioId),
                    scCorreoBotones([[
                        'texto' => 'Ver el estado de mi solicitud',
                        'url'   => scUrlBase() . '/estado.html?folio=' . scFolio($usuarioId),
                    ]]),
                    'Guarda este folio: con él puedes consultar tu avance cuando quieras.');
            }
            if (in_array('referido', $bloques, true)) {
                $html .= scCorreoSeccion('Recomienda a alguien', '¿Conoces a alguien del oficio?',
                    scCorreoBotones([[
                        'texto' => 'Pasarle la invitación',
                        'url'   => scUrlBase() . '/registro.html?ref=' . scFolio($usuarioId),
                    ]]),
                    'Buscamos técnicos e inspectores con certificaciones vigentes.');
            }
            if (in_array('whatsapp', $bloques, true) && defined('SC_WHATSAPP') && SC_WHATSAPP !== '') {
                $nombre = $this->nombreParaSaludo($this->nombreDe($usuarioId), '');
                $boton  = scCorreoWhatsApp(SC_WHATSAPP, $nombre, scFolio($usuarioId));
                if ($boton !== '') {
                    $html .= scCorreoSeccion('¿Prefieres WhatsApp?', 'Escríbenos por ahí', $boton,
                        'Contestamos en horario de oficina.');
                }
            }
        } catch (Throwable $e) {
            // Un bloque roto no puede impedir que salga el correo: el mensaje
            // principal es lo que hay que entregar sí o sí.
            error_log('ScCorreos::bloquesPara: ' . $e->getMessage());
        }

        return $html;
    }

    private function bloquePregunta(ScInteraccion $inter, int $usuarioId, array $preguntas): string {
        $contestadas = $inter->respuestasDe($usuarioId);

        foreach ($preguntas as $tipo) {
            if (isset($contestadas[$tipo])) continue;
            $pregunta = ScInteraccion::PREGUNTAS[$tipo] ?? null;
            if (!$pregunta) continue;

            $opciones = [];
            foreach ($pregunta['opciones'] as $valor => $etiqueta) {
                $opciones[] = [
                    'texto' => $etiqueta,
                    'url'   => scUrlRespuesta($this->pdo, $usuarioId, $tipo, $valor),
                ];
            }

            return scCorreoSeccion(
                'Una pregunta rápida',
                $pregunta['titulo'],
                empty($pregunta['multiple']) ? scCorreoBotones($opciones) : scCorreoChips($opciones),
                empty($pregunta['multiple'])
                    ? 'Un clic y listo, no hace falta entrar al portal.'
                    : 'Marca las que tengas, una por una.'
            );
        }

        return '';
    }

    private function bloqueAgenda(ScInteraccion $inter, int $usuarioId): string {
        $suya = $inter->franjaDe($usuarioId);
        if ($suya) {
            return scCorreoSeccion('Tu entrevista', scFechaLargaEs($suya['inicio']),
                '<div style="font-size:13.5px;color:#566079">' . (int) $suya['minutos'] . ' minutos</div>',
                'Si necesitas cambiarla, escríbenos.');
        }

        $libres = $inter->franjasLibres(4);
        if (!$libres) return '';

        $opciones = [];
        foreach ($libres as $f) {
            $opciones[] = [
                'texto' => scFechaLargaEs($f['inicio']),
                'url'   => scUrlRespuesta($this->pdo, $usuarioId, 'franja', (string) $f['id']),
            ];
        }

        return scCorreoSeccion('Entrevista', 'Escoge cuándo te llamamos',
            scCorreoBotones($opciones), 'Se aparta al instante con un clic.');
    }
}
