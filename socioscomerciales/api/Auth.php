<?php
/**
 * Socios Comerciales AVBA — Autenticación
 * Registro, login y verificación de correo para personas y empresas.
 */

class ScAuth {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    // ── REGISTRO ─────────────────────────────────────────────
    public function registrar(string $tipo, array $payload): array {
        $correo   = strtolower(trim($payload['correo']   ?? ''));
        $password = (string) ($payload['password'] ?? '');
        $nombre   = trim($payload['nombre']   ?? '');

        $etiqueta = $tipo === 'empresa' ? 'Nombre de la empresa' : 'Nombre completo';

        if (!$nombre)   return ['status' => 'error', 'message' => "{$etiqueta} es obligatorio."];
        if (!$correo)   return ['status' => 'error', 'message' => 'El correo es obligatorio.'];
        if (!$password) return ['status' => 'error', 'message' => 'La contraseña es obligatoria.'];

        if (!scEsCorreoValido($correo)) {
            return ['status' => 'error', 'message' => 'El correo electrónico no tiene un formato válido.'];
        }
        if (strlen($password) < SC_PASSWORD_MIN) {
            return ['status' => 'error', 'message' => 'La contraseña debe tener al menos ' . SC_PASSWORD_MIN . ' caracteres.'];
        }

        // El alta manda un correo y crea una fila: sin freno sirve para
        // inundar buzones ajenos (y para que el dominio acabe marcado como
        // spam), y para averiguar a lo bruto qué correos ya están dados de
        // alta preguntando uno por uno.
        if (!scLimite($this->pdo, 'registro_ip', scIpCliente(), 5, 3600)) {
            return ['status' => 'error', 'message' => 'Demasiadas cuentas creadas desde esta conexión. Espera una hora.'];
        }
        if (!scLimite($this->pdo, 'registro', $correo, 3, 3600)) {
            return ['status' => 'error', 'message' => 'Demasiados intentos con este correo. Espera una hora.'];
        }

        $stmt = $this->pdo->prepare("SELECT id FROM sc_usuarios WHERE correo = ?");
        $stmt->execute([$correo]);
        if ($stmt->fetch()) {
            return ['status' => 'error', 'message' => 'Ya existe una cuenta con ese correo.'];
        }

        $hash        = scHashPassword($password);
        $verifToken  = scGenerarToken();
        $verifExpira = date('Y-m-d H:i:s', time() + SC_VERIF_TTL);

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                "INSERT INTO sc_usuarios (tipo, correo, password_hash, activo, correo_verificado, verif_token, verif_expira)
                 VALUES (?, ?, ?, 1, 0, ?, ?)"
            )->execute([$tipo, $correo, $hash, $verifToken, $verifExpira]);

            $usuarioId = (int) $this->pdo->lastInsertId();

            $tabla = $tipo === 'empresa' ? 'sc_empresas' : 'sc_personas';
            $this->pdo->prepare("INSERT INTO {$tabla} (usuario_id, nombre) VALUES (?, ?)")
                ->execute([$usuarioId, $nombre]);

            $this->pdo->commit();
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log('ScAuth::registrar: ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'No se pudo completar el registro. Intenta de nuevo.'];
        }

        $correoEnviado = $this->enviarCorreoVerificacion($correo, $nombre, $verifToken);

        $sesion = $this->emitirSesion($usuarioId, $tipo, $correo);
        $sesion['correo_verificado'] = 0;
        $sesion['correo_enviado']    = $correoEnviado;
        return $sesion;
    }

    // ── LOGIN ────────────────────────────────────────────────
    public function login(array $payload): array {
        $correo   = strtolower(trim($payload['correo']   ?? ''));
        $password = (string) ($payload['password'] ?? '');

        if (!$correo || !$password) {
            return ['status' => 'error', 'message' => 'Correo y contraseña requeridos.'];
        }

        // Sin este freno se pueden probar miles de contraseñas por minuto.
        // Se limita por correo y por IP: lo primero protege a una cuenta
        // concreta, lo segundo evita el barrido de muchas cuentas.
        $mensajeLimite = ['status' => 'error', 'message' => 'Demasiados intentos. Espera unos minutos e inténtalo de nuevo.'];
        // El límite por IP es alto a propósito: una empresa entera puede salir
        // a internet por una sola IP pública y 30 intentos cada 15 min dejaba
        // fuera a toda la oficina por culpa de un compañero despistado.
        if (!scLimite($this->pdo, 'login', $correo, 8, 900))             return $mensajeLimite;
        if (!scLimite($this->pdo, 'login_ip', scIpCliente(), 150, 900))  return $mensajeLimite;

        $stmt = $this->pdo->prepare(
            "SELECT id, tipo, password_hash, activo, correo_verificado FROM sc_usuarios WHERE correo = ?"
        );
        $stmt->execute([$correo]);
        $row = $stmt->fetch();

        // Mensaje genérico para no revelar qué correos están registrados.
        // Si el usuario no existe se compara igual contra un hash de relleno,
        // para que el tiempo de respuesta no delate su existencia.
        if (!$row) {
            password_verify($password, SC_HASH_RELLENO);
            return ['status' => 'error', 'message' => 'Correo o contraseña incorrectos.'];
        }
        if (!password_verify($password, $row['password_hash'])) {
            return ['status' => 'error', 'message' => 'Correo o contraseña incorrectos.'];
        }
        if (!$row['activo']) {
            return ['status' => 'error', 'message' => 'Esta cuenta está desactivada.'];
        }

        // Entró bien: se olvidan los intentos para que su propia actividad
        // legítima no acabe bloqueándole la cuenta.
        scLimpiarIntentos($this->pdo, 'login', $correo);

        // Si el hash viene de un coste antiguo, se rehace ahora que tenemos
        // la contraseña en claro: así todas las cuentas acaban igual de duras
        // y el relleno de tiempos sigue siendo indistinguible.
        if (password_needs_rehash($row['password_hash'], PASSWORD_BCRYPT, ['cost' => SC_BCRYPT_COSTE])) {
            $this->pdo->prepare("UPDATE sc_usuarios SET password_hash = ? WHERE id = ?")
                ->execute([scHashPassword($password), $row['id']]);
        }

        $sesion = $this->emitirSesion((int) $row['id'], $row['tipo'], $correo);
        $sesion['correo_verificado'] = (int) $row['correo_verificado'];
        return $sesion;
    }

    // ── LOGOUT ───────────────────────────────────────────────
    /** Cierra solo el dispositivo actual; los demás siguen dentro. */
    public function logout(int $usuarioId, ?string $token): array {
        if ($token) {
            $this->pdo->prepare("DELETE FROM sc_sesiones WHERE usuario_id = ? AND token = ?")
                ->execute([$usuarioId, $token]);
        }
        return ['status' => 'success', 'message' => 'Sesión cerrada.'];
    }

    /** Lista las sesiones abiertas, marcando la que hace la petición. */
    public function listarSesiones(int $usuarioId, ?string $token): array {
        $stmt = $this->pdo->prepare(
            "SELECT id, creado, expira, ultimo_uso, agente, (token = ?) AS actual
             FROM sc_sesiones
             WHERE usuario_id = ? AND expira > NOW()
             ORDER BY (token = ?) DESC, ultimo_uso DESC, creado DESC"
        );
        $stmt->execute([$token, $usuarioId, $token]);

        return ['status' => 'success', 'sesiones' => $stmt->fetchAll()];
    }

    /** Cierra todas las sesiones menos la actual. */
    public function cerrarOtrasSesiones(int $usuarioId, ?string $token): array {
        $stmt = $this->pdo->prepare(
            "DELETE FROM sc_sesiones WHERE usuario_id = ? AND token <> ?"
        );
        $stmt->execute([$usuarioId, (string) $token]);
        $n = $stmt->rowCount();

        return [
            'status'  => 'success',
            'message' => $n === 0 ? 'No había otras sesiones abiertas.'
                       : ($n === 1 ? 'Se cerró 1 sesión.' : "Se cerraron {$n} sesiones."),
        ];
    }

    // ── VERIFICAR CORREO (desde el enlace del correo) ─────────
    public function verificarCorreo(string $token): array {
        if (!$token) {
            return ['status' => 'error', 'message' => 'Enlace de verificación inválido.'];
        }

        $stmt = $this->pdo->prepare(
            "SELECT id, correo, correo_verificado, verif_expira FROM sc_usuarios WHERE verif_token = ?"
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch();

        if (!$row) {
            return ['status' => 'error', 'message' => 'Este enlace ya no es válido. Solicita uno nuevo desde tu perfil.'];
        }
        if ((int) $row['correo_verificado'] === 1) {
            return ['status' => 'success', 'message' => 'Tu correo ya estaba verificado.', 'ya_estaba' => true];
        }
        if ($row['verif_expira'] && strtotime($row['verif_expira']) < time()) {
            return ['status' => 'error', 'message' => 'El enlace expiró. Solicita uno nuevo desde tu perfil.'];
        }

        $this->pdo->prepare(
            "UPDATE sc_usuarios SET correo_verificado = 1, verif_token = NULL, verif_expira = NULL WHERE id = ?"
        )->execute([$row['id']]);

        return ['status' => 'success', 'message' => '¡Listo! Tu correo quedó verificado.'];
    }

    // ── REENVIAR VERIFICACIÓN ────────────────────────────────
    public function reenviarVerificacion(int $usuarioId): array {
        $stmt = $this->pdo->prepare("SELECT tipo, correo, correo_verificado FROM sc_usuarios WHERE id = ?");
        $stmt->execute([$usuarioId]);
        $row = $stmt->fetch();

        if (!$row) return ['status' => 'error', 'message' => 'Usuario no encontrado.'];
        if ((int) $row['correo_verificado'] === 1) {
            return ['status' => 'success', 'message' => 'Tu correo ya está verificado.'];
        }

        // Cada llamada dispara un mail(): con sesión abierta se podía pulsar
        // "Reenviar" sin descanso y quemar la reputación del dominio.
        if (!scLimite($this->pdo, 'verif', $row['correo'], 4, 3600)) {
            return ['status' => 'error', 'message' => 'Ya te enviamos varios enlaces. Revisa tu bandeja y la carpeta de spam; puedes pedir otro en una hora.'];
        }

        $verifToken  = scGenerarToken();
        $verifExpira = date('Y-m-d H:i:s', time() + SC_VERIF_TTL);
        $this->pdo->prepare("UPDATE sc_usuarios SET verif_token = ?, verif_expira = ? WHERE id = ?")
            ->execute([$verifToken, $verifExpira, $usuarioId]);

        $tabla  = $row['tipo'] === 'empresa' ? 'sc_empresas' : 'sc_personas';
        $stmt   = $this->pdo->prepare("SELECT nombre FROM {$tabla} WHERE usuario_id = ?");
        $stmt->execute([$usuarioId]);
        $nombre = $stmt->fetchColumn() ?: '';

        if (!$this->enviarCorreoVerificacion($row['correo'], $nombre, $verifToken)) {
            return ['status' => 'error', 'message' => 'No se pudo enviar el correo. Inténtalo más tarde.'];
        }

        return ['status' => 'success', 'message' => 'Te enviamos un nuevo enlace de verificación.'];
    }

    // ── SOLICITAR RESTABLECER CONTRASEÑA ─────────────────────
    public function solicitarReset(array $payload): array {
        $correo = strtolower(trim($payload['correo'] ?? ''));

        // Respuesta siempre idéntica: si variara, revelaría qué correos
        // están registrados en el portal.
        $generica = [
            'status'  => 'success',
            'message' => 'Si el correo está registrado, te enviamos un enlace para restablecer tu contraseña.',
        ];

        if (!$correo || !scEsCorreoValido($correo)) return $generica;

        // Sin límite, cualquiera puede inundar el buzón de un usuario con
        // correos de recuperación y, de paso, invalidar una y otra vez el
        // enlace que la víctima legítima intenta usar.
        if (!scLimite($this->pdo, 'reset', $correo, 3, 900))           return $generica;
        if (!scLimite($this->pdo, 'reset_ip', scIpCliente(), 15, 900)) return $generica;

        $stmt = $this->pdo->prepare("SELECT id, tipo, activo FROM sc_usuarios WHERE correo = ?");
        $stmt->execute([$correo]);
        $row = $stmt->fetch();

        if (!$row || !$row['activo']) return $generica;

        $token  = scGenerarToken();
        $expira = date('Y-m-d H:i:s', time() + SC_RESET_TTL);
        $this->pdo->prepare("UPDATE sc_usuarios SET reset_token = ?, reset_expira = ? WHERE id = ?")
            ->execute([$token, $expira, $row['id']]);

        $tabla = $row['tipo'] === 'empresa' ? 'sc_empresas' : 'sc_personas';
        $s = $this->pdo->prepare("SELECT nombre FROM {$tabla} WHERE usuario_id = ?");
        $s->execute([$row['id']]);
        $nombre = $s->fetchColumn() ?: '';

        $url = scUrlBase() . '/recuperar.html?t=' . urlencode($token);
        $saludo = $nombre ? 'Hola ' . htmlspecialchars($nombre) . ',' : 'Hola,';
        $cuerpo = "<p>{$saludo}</p>
            <p>Recibimos una solicitud para restablecer la contraseña de tu cuenta en
            <strong>Socios Comerciales AVBA</strong>. Usa el botón de abajo para elegir una nueva.</p>
            <p style=\"font-size:12.5px;color:#8792a8\">El enlace expira en 2 horas.
            Si no fuiste tú, ignora este correo: tu contraseña no cambiará.</p>";

        scEnviarCorreo(
            $correo,
            'Restablece tu contraseña — Socios Comerciales AVBA',
            scPlantillaCorreo('Restablece tu contraseña', $cuerpo, 'Elegir nueva contraseña', $url)
        );

        return $generica;
    }

    // ── RESTABLECER CON EL TOKEN DEL CORREO ──────────────────
    public function restablecerPassword(array $payload): array {
        $token    = trim($payload['token']    ?? '');
        $password = (string) ($payload['password'] ?? '');

        if (!$token) return ['status' => 'error', 'message' => 'Enlace inválido.'];
        if (strlen($password) < SC_PASSWORD_MIN) {
            return ['status' => 'error', 'message' => 'La contraseña debe tener al menos ' . SC_PASSWORD_MIN . ' caracteres.'];
        }

        $stmt = $this->pdo->prepare(
            "SELECT id, reset_expira FROM sc_usuarios WHERE reset_token = ? AND activo = 1"
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch();

        if (!$row) {
            return ['status' => 'error', 'message' => 'Este enlace ya no es válido. Solicita uno nuevo.'];
        }
        if ($row['reset_expira'] && strtotime($row['reset_expira']) < time()) {
            return ['status' => 'error', 'message' => 'El enlace expiró. Solicita uno nuevo.'];
        }

        // Cambiar la contraseña cierra las sesiones abiertas: si alguien más
        // tenía acceso, queda fuera.
        $this->pdo->prepare(
            "UPDATE sc_usuarios
             SET password_hash = ?, reset_token = NULL, reset_expira = NULL,
                 session_token = NULL, token_expires = NULL
             WHERE id = ?"
        )->execute([scHashPassword($password), $row['id']]);

        $this->pdo->prepare("DELETE FROM sc_sesiones WHERE usuario_id = ?")->execute([$row['id']]);

        return ['status' => 'success', 'message' => 'Tu contraseña quedó actualizada. Ya puedes iniciar sesión.'];
    }

    // ── CAMBIAR CONTRASEÑA (con sesión iniciada) ─────────────
    public function cambiarPassword(int $usuarioId, array $payload): array {
        $actual = (string) ($payload['actual'] ?? '');
        $nueva  = (string) ($payload['nueva']  ?? '');

        if (!$actual) return ['status' => 'error', 'message' => 'Escribe tu contraseña actual.'];
        if (strlen($nueva) < SC_PASSWORD_MIN) {
            return ['status' => 'error', 'message' => 'La nueva contraseña debe tener al menos ' . SC_PASSWORD_MIN . ' caracteres.'];
        }
        if ($actual === $nueva) return ['status' => 'error', 'message' => 'La nueva contraseña debe ser distinta de la actual.'];

        $stmt = $this->pdo->prepare("SELECT password_hash, tipo, correo FROM sc_usuarios WHERE id = ?");
        $stmt->execute([$usuarioId]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($actual, $row['password_hash'])) {
            return ['status' => 'error', 'message' => 'La contraseña actual no es correcta.'];
        }

        // Cambiar la contraseña debe expulsar cualquier otra sesión: si alguien
        // robó el token, esta es justo la acción con la que la víctima espera
        // recuperar el control. También se anula cualquier enlace de
        // restablecimiento pendiente.
        $this->pdo->prepare(
            "UPDATE sc_usuarios
             SET password_hash = ?, session_token = NULL, token_expires = NULL,
                 reset_token = NULL, reset_expira = NULL
             WHERE id = ?"
        )->execute([scHashPassword($nueva), $usuarioId]);

        $this->pdo->prepare("DELETE FROM sc_sesiones WHERE usuario_id = ?")->execute([$usuarioId]);

        // Se emite una sesión nueva para que quien hizo el cambio siga dentro
        $sesion = $this->emitirSesion($usuarioId, $row['tipo'], $row['correo']);

        return [
            'status'  => 'success',
            'message' => 'Contraseña actualizada. Se cerraron las demás sesiones.',
            'token'   => $sesion['token'],
        ];
    }

    // ══════════════════════════════════════════════════════════
    //  DATOS PERSONALES: exportar y borrar la cuenta
    //  El portal guarda CV, teléfono, CURP e historial laboral. En México
    //  la LFPDPPP reconoce los derechos ARCO, así que el titular tiene que
    //  poder llevarse sus datos y borrarlos sin pedírselo a nadie.
    // ══════════════════════════════════════════════════════════

    /** Devuelve todo lo que el portal guarda de esta cuenta, en JSON. */
    public function exportarDatos(int $usuarioId): array {
        $stmt = $this->pdo->prepare(
            "SELECT id, tipo, correo, correo_verificado, activo, creado, ultimo_acceso
             FROM sc_usuarios WHERE id = ?"
        );
        $stmt->execute([$usuarioId]);
        $usuario = $stmt->fetch();
        if (!$usuario) return ['status' => 'error', 'message' => 'Cuenta no encontrada.'];

        $datos = ['cuenta' => $usuario];

        if ($usuario['tipo'] === 'persona') {
            $stmt = $this->pdo->prepare("SELECT * FROM sc_personas WHERE usuario_id = ?");
            $stmt->execute([$usuarioId]);
            $persona = $stmt->fetch() ?: [];
            $datos['perfil'] = $persona;

            if (!empty($persona['id'])) {
                foreach (['experiencia' => 'sc_experiencia',
                          'educacion'   => 'sc_educacion',
                          'habilidades' => 'sc_habilidades'] as $clave => $tabla) {
                    $s = $this->pdo->prepare("SELECT * FROM {$tabla} WHERE persona_id = ?");
                    $s->execute([$persona['id']]);
                    $datos[$clave] = $s->fetchAll();
                }

                $s = $this->pdo->prepare(
                    "SELECT p.fecha, p.estatus, p.mensaje, v.titulo AS vacante, e.nombre AS empresa
                     FROM sc_postulaciones p
                     JOIN sc_vacantes v ON v.id = p.vacante_id
                     JOIN sc_empresas e ON e.id = v.empresa_id
                     WHERE p.persona_id = ? ORDER BY p.fecha DESC"
                );
                $s->execute([$persona['id']]);
                $datos['postulaciones'] = $s->fetchAll();

                // Quién ha consultado su CV: es su dato, tiene derecho a saberlo
                $s = $this->pdo->prepare(
                    "SELECT a.fecha, e.nombre AS empresa
                     FROM sc_cv_accesos a
                     LEFT JOIN sc_empresas e ON e.usuario_id = a.usuario_id
                     WHERE a.persona_id = ? ORDER BY a.fecha DESC LIMIT 500"
                );
                $s->execute([$persona['id']]);
                $datos['consultas_a_mi_cv'] = $s->fetchAll();
            }
        } else {
            $stmt = $this->pdo->prepare("SELECT * FROM sc_empresas WHERE usuario_id = ?");
            $stmt->execute([$usuarioId]);
            $empresa = $stmt->fetch() ?: [];
            $datos['perfil'] = $empresa;

            if (!empty($empresa['id'])) {
                $s = $this->pdo->prepare("SELECT * FROM sc_vacantes WHERE empresa_id = ? ORDER BY creado DESC");
                $s->execute([$empresa['id']]);
                $datos['vacantes'] = $s->fetchAll();
            }
        }

        $stmt = $this->pdo->prepare(
            "SELECT creado, expira, ultimo_uso, agente FROM sc_sesiones WHERE usuario_id = ?"
        );
        $stmt->execute([$usuarioId]);
        $datos['sesiones'] = $stmt->fetchAll();

        return ['status' => 'success', 'generado' => date('c'), 'datos' => $datos];
    }

    /**
     * Borra la cuenta y todo lo que cuelga de ella.
     *
     * Exige la contraseña actual: un token robado no debe bastar para
     * destruir la cuenta de nadie. Las filas hijas se van solas por las
     * claves foráneas ON DELETE CASCADE, pero los archivos en disco no,
     * así que se recogen y se borran antes.
     */
    public function eliminarCuenta(int $usuarioId, array $payload): array {
        $password = (string) ($payload['password'] ?? '');
        if ($password === '') {
            return ['status' => 'error', 'message' => 'Escribe tu contraseña para confirmar el borrado.'];
        }

        $stmt = $this->pdo->prepare("SELECT tipo, password_hash FROM sc_usuarios WHERE id = ?");
        $stmt->execute([$usuarioId]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($password, $row['password_hash'])) {
            return ['status' => 'error', 'message' => 'La contraseña no es correcta.'];
        }

        $archivos = [];
        if ($row['tipo'] === 'persona') {
            $s = $this->pdo->prepare("SELECT cv_url, foto_url FROM sc_personas WHERE usuario_id = ?");
            $s->execute([$usuarioId]);
            $p = $s->fetch() ?: [];
            $archivos = [$p['cv_url'] ?? null, $p['foto_url'] ?? null];
        } else {
            $s = $this->pdo->prepare("SELECT logo_url FROM sc_empresas WHERE usuario_id = ?");
            $s->execute([$usuarioId]);
            $archivos = [$s->fetchColumn() ?: null];
        }

        try {
            $this->pdo->prepare("DELETE FROM sc_usuarios WHERE id = ?")->execute([$usuarioId]);
        } catch (PDOException $e) {
            error_log('ScAuth::eliminarCuenta: ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'No se pudo eliminar la cuenta. Escríbenos para ayudarte.'];
        }

        foreach ($archivos as $a) scBorrarArchivo($a);

        return ['status' => 'success', 'message' => 'Tu cuenta y todos tus datos fueron eliminados.'];
    }

    // ── Helpers internos ─────────────────────────────────────
    private function enviarCorreoVerificacion(string $correo, string $nombre, string $token): bool {
        $url = scUrlBase() . '/api/index.php?action=VERIFICAR_CORREO&t=' . urlencode($token);

        $saludo = $nombre ? 'Hola ' . htmlspecialchars($nombre) . ',' : 'Hola,';
        $cuerpo = "<p>{$saludo}</p>
            <p>Gracias por crear tu cuenta en <strong>Socios Comerciales AVBA</strong>.
            Para activarla por completo, confirma que este correo es tuyo con el botón de abajo.</p>
            <p style=\"font-size:12.5px;color:#8792a8\">El enlace estará disponible 48 horas.</p>";

        $html = scPlantillaCorreo('Confirma tu correo electrónico', $cuerpo, 'Verificar mi correo', $url);

        return scEnviarCorreo($correo, 'Confirma tu correo — Socios Comerciales AVBA', $html);
    }

    private function emitirSesion(int $usuarioId, string $tipo, string $correo): array {
        $token   = scGenerarToken();
        $expires = date('Y-m-d H:i:s', time() + SC_TOKEN_TTL);

        $this->pdo->prepare(
            "INSERT INTO sc_sesiones (usuario_id, token, expira, ultimo_uso, ip, agente)
             VALUES (?, ?, ?, NOW(), ?, ?)"
        )->execute([$usuarioId, $token, $expires, scIpCliente(), scDispositivo()]);

        $this->pdo->prepare("UPDATE sc_usuarios SET ultimo_acceso = NOW() WHERE id = ?")
            ->execute([$usuarioId]);

        // Purga ocasional de sesiones caducadas para que la tabla no crezca
        if (random_int(1, 40) === 1) {
            $this->pdo->exec("DELETE FROM sc_sesiones WHERE expira < NOW()");
        }

        $tabla = $tipo === 'empresa' ? 'sc_empresas' : 'sc_personas';
        $stmt  = $this->pdo->prepare("SELECT nombre FROM {$tabla} WHERE usuario_id = ?");
        $stmt->execute([$usuarioId]);
        $nombre = $stmt->fetchColumn() ?: '';

        return [
            'status'     => 'success',
            'usuario_id' => $usuarioId,
            'tipo'       => $tipo,
            'correo'     => $correo,
            'nombre'     => $nombre,
            'token'      => $token,
        ];
    }
}
