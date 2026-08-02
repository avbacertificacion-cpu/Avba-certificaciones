<?php
/**
 * Socios Comerciales AVBA — Autenticación
 * Registro y login para personas (candidatos) y empresas.
 */

class ScAuth {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    // ── REGISTRO PERSONA ────────────────────────────────────
    public function registrarPersona(array $payload): array {
        $correo   = strtolower(trim($payload['correo']   ?? ''));
        $password = trim($payload['password'] ?? '');
        $nombre   = trim($payload['nombre']   ?? '');

        if (!$correo || !$password || !$nombre) {
            return ['status' => 'error', 'message' => 'Nombre, correo y contraseña son obligatorios.'];
        }
        if (!scEsCorreoValido($correo)) {
            return ['status' => 'error', 'message' => 'Correo electrónico inválido.'];
        }
        if (strlen($password) < 6) {
            return ['status' => 'error', 'message' => 'La contraseña debe tener al menos 6 caracteres.'];
        }

        $stmt = $this->pdo->prepare("SELECT id FROM sc_usuarios WHERE correo = ?");
        $stmt->execute([$correo]);
        if ($stmt->fetch()) {
            return ['status' => 'error', 'message' => 'Ya existe una cuenta con ese correo.'];
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                "INSERT INTO sc_usuarios (tipo, correo, password_hash, activo) VALUES ('persona', ?, ?, 1)"
            )->execute([$correo, $hash]);
            $usuarioId = (int) $this->pdo->lastInsertId();

            $this->pdo->prepare(
                "INSERT INTO sc_personas (usuario_id, nombre) VALUES (?, ?)"
            )->execute([$usuarioId, $nombre]);

            $this->pdo->commit();
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['status' => 'error', 'message' => 'No se pudo completar el registro.'];
        }

        return $this->emitirSesion($usuarioId, 'persona', $correo);
    }

    // ── REGISTRO EMPRESA ────────────────────────────────────
    public function registrarEmpresa(array $payload): array {
        $correo   = strtolower(trim($payload['correo']   ?? ''));
        $password = trim($payload['password'] ?? '');
        $nombre   = trim($payload['nombre']   ?? '');

        if (!$correo || !$password || !$nombre) {
            return ['status' => 'error', 'message' => 'Nombre de empresa, correo y contraseña son obligatorios.'];
        }
        if (!scEsCorreoValido($correo)) {
            return ['status' => 'error', 'message' => 'Correo electrónico inválido.'];
        }
        if (strlen($password) < 6) {
            return ['status' => 'error', 'message' => 'La contraseña debe tener al menos 6 caracteres.'];
        }

        $stmt = $this->pdo->prepare("SELECT id FROM sc_usuarios WHERE correo = ?");
        $stmt->execute([$correo]);
        if ($stmt->fetch()) {
            return ['status' => 'error', 'message' => 'Ya existe una cuenta con ese correo.'];
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                "INSERT INTO sc_usuarios (tipo, correo, password_hash, activo) VALUES ('empresa', ?, ?, 1)"
            )->execute([$correo, $hash]);
            $usuarioId = (int) $this->pdo->lastInsertId();

            $this->pdo->prepare(
                "INSERT INTO sc_empresas (usuario_id, nombre) VALUES (?, ?)"
            )->execute([$usuarioId, $nombre]);

            $this->pdo->commit();
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['status' => 'error', 'message' => 'No se pudo completar el registro.'];
        }

        return $this->emitirSesion($usuarioId, 'empresa', $correo);
    }

    // ── LOGIN ────────────────────────────────────────────────
    public function login(array $payload): array {
        $correo   = strtolower(trim($payload['correo']   ?? ''));
        $password = trim($payload['password'] ?? '');

        if (!$correo || !$password) {
            return ['status' => 'error', 'message' => 'Correo y contraseña requeridos.'];
        }

        $stmt = $this->pdo->prepare(
            "SELECT id, tipo, password_hash, activo FROM sc_usuarios WHERE correo = ?"
        );
        $stmt->execute([$correo]);
        $row = $stmt->fetch();

        if (!$row) {
            return ['status' => 'error', 'message' => 'No existe una cuenta con ese correo.'];
        }
        if (!$row['activo']) {
            return ['status' => 'error', 'message' => 'Esta cuenta está desactivada.'];
        }
        if (!password_verify($password, $row['password_hash'])) {
            return ['status' => 'error', 'message' => 'Contraseña incorrecta.'];
        }

        return $this->emitirSesion((int) $row['id'], $row['tipo'], $correo);
    }

    // ── LOGOUT ───────────────────────────────────────────────
    public function logout(int $usuarioId): array {
        $this->pdo->prepare(
            "UPDATE sc_usuarios SET session_token = NULL, token_expires = NULL WHERE id = ?"
        )->execute([$usuarioId]);
        return ['status' => 'success', 'message' => 'Sesión cerrada.'];
    }

    // ── Helper interno: genera token + devuelve datos de sesión ────
    private function emitirSesion(int $usuarioId, string $tipo, string $correo): array {
        $token   = scGenerarToken();
        $expires = date('Y-m-d H:i:s', time() + SC_TOKEN_TTL);

        $this->pdo->prepare(
            "UPDATE sc_usuarios SET session_token = ?, token_expires = ?, ultimo_acceso = NOW() WHERE id = ?"
        )->execute([$token, $expires, $usuarioId]);

        $nombre = '';
        if ($tipo === 'persona') {
            $stmt = $this->pdo->prepare("SELECT nombre FROM sc_personas WHERE usuario_id = ?");
            $stmt->execute([$usuarioId]);
            $nombre = $stmt->fetchColumn() ?: '';
        } else {
            $stmt = $this->pdo->prepare("SELECT nombre FROM sc_empresas WHERE usuario_id = ?");
            $stmt->execute([$usuarioId]);
            $nombre = $stmt->fetchColumn() ?: '';
        }

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
