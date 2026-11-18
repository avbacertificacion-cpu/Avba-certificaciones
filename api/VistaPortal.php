<?php
/* ══════════════════════════════════════════════════════════════
   AVBA — Vista del portal de un cliente, desde Administración

   Para ver exactamente lo que tiene un cliente en su portal, el
   administrador abre el portal "como" esa cuenta, sin conocer su contraseña
   y sin cerrarle la sesión:

     · Se crea un token aparte (prefijo «vista.»), en su propia tabla, que
       entra como la cuenta del cliente: mismos datos, mismas restricciones
       si es un sub-usuario. La sesión del cliente no se toca, porque su
       token vive en usuarios.session_token y éste no.
     · Es de SÓLO CONSULTA, y lo hace cumplir el servidor (index.php,
       VistaPortal::permitida): cualquier acción que no sea de lectura se
       rechaza con 403, venga de la pantalla o de quien sea.
     · Dura una hora.
     · Cada vista queda anotada: quién, qué cuenta, cuándo y desde dónde.
   ══════════════════════════════════════════════════════════════ */

class VistaPortal {
    public const PREFIJO  = 'vista.';
    private const DURACION = 3600;   // segundos

    /** Lo que el portal sólo consulta. Todo lo demás se bloquea en una vista. */
    private const LECTURA_EXACTA = [
        'OBTENER_DATOS_CLIENTE', 'RH_SALUD', 'RH_POLITICAS', 'RH_INCIDENCIAS', 'SOLCERT_MIS',
        // Arman un PDF para verlo o descargarlo; no cambian nada del expediente
        'HOJA_RECOMENDACIONES', 'IMPRIMIR_EXPEDIENTE',
    ];
    private const LECTURA_PREFIJOS = ['GET_', 'LISTAR_', 'DETALLE_', 'BUSCAR_'];

    public static function asegurarTabla(PDO $pdo): void {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS sesiones_vista (
               id         INT AUTO_INCREMENT PRIMARY KEY,
               token      VARCHAR(90)  NOT NULL,
               usuario_id INT          NOT NULL,
               admin      VARCHAR(100) NOT NULL,
               ip         VARCHAR(45)  NULL,
               creado     DATETIME     NOT NULL,
               expira     DATETIME     NOT NULL,
               UNIQUE KEY uk_token (token),
               KEY idx_usuario (usuario_id)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    /** ¿Esta acción se puede hacer dentro de una vista de administrador? */
    public static function permitida(string $accion): bool {
        $a = strtoupper(trim($accion));
        if ($a === '') return true;
        if (in_array($a, self::LECTURA_EXACTA, true)) return true;
        foreach (self::LECTURA_PREFIJOS as $p) {
            if (str_starts_with($a, $p)) return true;
        }
        return false;
    }

    /**
     * Crea la vista del portal de una cuenta de cliente.
     * Devuelve la sesión que el portal necesita para arrancar como esa cuenta.
     */
    public static function crear(PDO $pdo, int $usuarioId, string $admin): array {
        if ($usuarioId <= 0) return ['status' => 'error', 'message' => 'Cuenta no indicada.'];
        $st = $pdo->prepare(
            "SELECT id, usuario, nombre, rol, id_cliente, activo, usuario_padre_id, permiso_sub
             FROM usuarios WHERE id = ? LIMIT 1"
        );
        try { $st->execute([$usuarioId]); }
        catch (\Throwable $e) {
            // Instalación sin columnas de sub-usuario
            $st = $pdo->prepare("SELECT id, usuario, nombre, rol, id_cliente, activo FROM usuarios WHERE id = ? LIMIT 1");
            $st->execute([$usuarioId]);
        }
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if (!$u) return ['status' => 'error', 'message' => 'La cuenta no existe.'];
        if (strtoupper((string)$u['rol']) !== 'CLIENTE') {
            return ['status' => 'error', 'message' => 'Sólo se puede ver el portal de cuentas de cliente.'];
        }
        if ((int)$u['activo'] !== 1) {
            return ['status' => 'error', 'message' => 'La cuenta está desactivada: el cliente no puede entrar a su portal.'];
        }

        self::asegurarTabla($pdo);
        $token  = self::PREFIJO . bin2hex(random_bytes(24));
        $ahora  = time();
        $expira = date('Y-m-d H:i:s', $ahora + self::DURACION);
        $pdo->prepare(
            "INSERT INTO sesiones_vista (token, usuario_id, admin, ip, creado, expira) VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([$token, (int)$u['id'], $admin, mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                    date('Y-m-d H:i:s', $ahora), $expira]);

        return [
            'status' => 'success',
            'sesion' => [
                'usuario'       => $u['usuario'],
                'nombre'        => $u['nombre'],
                'rol'           => 'CLIENTE',
                'roles'         => ['CLIENTE'],
                'id_cliente'    => $u['id_cliente'],
                'token'         => $token,
                'es_subusuario' => !empty($u['usuario_padre_id']),
                'permiso_sub'   => $u['permiso_sub'] ?? null,
                'ts'            => $ahora * 1000,
                'vista'         => ['admin' => $admin, 'expira' => $expira],
            ],
        ];
    }

    /**
     * La cuenta del cliente a la que da acceso un token de vista vigente, con
     * 'vista_admin'; null si el token no es de vista o ya no vale.
     */
    public static function usuarioDeToken(PDO $pdo, string $token): ?array {
        if (!str_starts_with($token, self::PREFIJO)) return null;
        try {
            $st = $pdo->prepare(
                "SELECT u.id, u.usuario, u.rol, u.nombre, u.id_cliente, u.usuario_padre_id, u.permiso_sub,
                        u.permiso_mantenimiento, u.permiso_rh, u.roles_extra, u.rol_activo, v.admin AS vista_admin
                 FROM sesiones_vista v
                 INNER JOIN usuarios u ON u.id = v.usuario_id
                 WHERE v.token = ? AND v.expira > NOW() AND u.activo = 1 AND u.rol = 'CLIENTE'"
            );
            $st->execute([$token]);
            $u = $st->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return null;   // sin tabla todavía: no hay vistas
        }
        return $u ?: null;
    }
}
