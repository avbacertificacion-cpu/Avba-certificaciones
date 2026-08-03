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
        if (strlen($password) < 6) {
            return ['status' => 'error', 'message' => 'La contraseña debe tener al menos 6 caracteres.'];
        }

        $stmt = $this->pdo->prepare("SELECT id FROM sc_usuarios WHERE correo = ?");
        $stmt->execute([$correo]);
        if ($stmt->fetch()) {
            return ['status' => 'error', 'message' => 'Ya existe una cuenta con ese correo.'];
        }

        $hash        = password_hash($password, PASSWORD_BCRYPT);
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
        if (!scLimite($this->pdo, 'login', $correo, 8, 900))            return $mensajeLimite;
        if (!scLimite($this->pdo, 'login_ip', scIpCliente(), 30, 900))  return $mensajeLimite;

        $stmt = $this->pdo->prepare(
            "SELECT id, tipo, password_hash, activo, correo_verificado FROM sc_usuarios WHERE correo = ?"
        );
        $stmt->execute([$correo]);
        $row = $stmt->fetch();

        // Mensaje genérico para no revelar qué correos están registrados.
        // Si el usuario no existe se compara igual contra un hash de relleno,
        // para que el tiempo de respuesta no delate su existencia.
        if (!$row) {
            password_verify($password, '$2y$10$usuarioInexistenteRellenoParaIgualarTiempos.aaaaaaaaaaaaaaaaaaaaaa');
            return ['status' => 'error', 'message' => 'Correo o contraseña incorrectos.'];
        }
        if (!password_verify($password, $row['password_hash'])) {
            return ['status' => 'error', 'message' => 'Correo o contraseña incorrectos.'];
        }
        if (!$row['activo']) {
            return ['status' => 'error', 'message' => 'Esta cuenta está desactivada.'];
        }

        $sesion = $this->emitirSesion((int) $row['id'], $row['tipo'], $correo);
        $sesion['correo_verificado'] = (int) $row['correo_verificado'];
        return $sesion;
    }

    // ── LOGOUT ───────────────────────────────────────────────
    public function logout(int $usuarioId): array {
        $this->pdo->prepare(
            "UPDATE sc_usuarios SET session_token = NULL, token_expires = NULL WHERE id = ?"
        )->execute([$usuarioId]);
        return ['status' => 'success', 'message' => 'Sesión cerrada.'];
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

        if (!$token)                return ['status' => 'error', 'message' => 'Enlace inválido.'];
        if (strlen($password) < 6)  return ['status' => 'error', 'message' => 'La contraseña debe tener al menos 6 caracteres.'];

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
        )->execute([password_hash($password, PASSWORD_BCRYPT), $row['id']]);

        return ['status' => 'success', 'message' => 'Tu contraseña quedó actualizada. Ya puedes iniciar sesión.'];
    }

    // ── CAMBIAR CONTRASEÑA (con sesión iniciada) ─────────────
    public function cambiarPassword(int $usuarioId, array $payload): array {
        $actual = (string) ($payload['actual'] ?? '');
        $nueva  = (string) ($payload['nueva']  ?? '');

        if (!$actual)             return ['status' => 'error', 'message' => 'Escribe tu contraseña actual.'];
        if (strlen($nueva) < 6)   return ['status' => 'error', 'message' => 'La nueva contraseña debe tener al menos 6 caracteres.'];
        if ($actual === $nueva)   return ['status' => 'error', 'message' => 'La nueva contraseña debe ser distinta de la actual.'];

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
        )->execute([password_hash($nueva, PASSWORD_BCRYPT), $usuarioId]);

        // Se emite una sesión nueva para que quien hizo el cambio siga dentro
        $sesion = $this->emitirSesion($usuarioId, $row['tipo'], $row['correo']);

        return [
            'status'  => 'success',
            'message' => 'Contraseña actualizada. Se cerraron las demás sesiones.',
            'token'   => $sesion['token'],
        ];
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
            "UPDATE sc_usuarios SET session_token = ?, token_expires = ?, ultimo_acceso = NOW() WHERE id = ?"
        )->execute([$token, $expires, $usuarioId]);

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
