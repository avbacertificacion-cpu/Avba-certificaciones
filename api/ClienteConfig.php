<?php
/**
 * AVBA Certificaciones — Configuración / Perfil del Cliente
 * Datos a nivel de cliente (id_cliente) usados en sus formatos y envíos:
 *   - Logo (para membrete de reportes)
 *   - Razón social y RFC
 *   - Correo de envío propio (SMTP) para mandar solicitudes a proveedores
 *
 * La contraseña SMTP nunca se devuelve al frontend; solo se indica si existe.
 */
class ClienteConfig {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->migrate();
    }

    private function migrate(): void {
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS cliente_config (
                  id_cliente    VARCHAR(20) NOT NULL PRIMARY KEY,
                  logo_url      VARCHAR(500) NULL,
                  razon_social  VARCHAR(300) NULL,
                  rfc           VARCHAR(20)  NULL,
                  mail_from     VARCHAR(200) NULL,
                  mail_from_name VARCHAR(200) NULL,
                  mail_host     VARCHAR(200) NULL,
                  mail_port     SMALLINT     NULL DEFAULT 465,
                  mail_secure   VARCHAR(10)  NULL DEFAULT 'ssl',
                  mail_user     VARCHAR(200) NULL,
                  mail_pass     VARCHAR(500) NULL,
                  updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\PDOException $e) {
            error_log('[ClienteConfig] migrate: ' . $e->getMessage());
        }
    }

    private function norm(string $id): string {
        $id = trim($id);
        return (ctype_digit($id)) ? str_pad($id, 5, '0', STR_PAD_LEFT) : $id;
    }

    /** Devuelve la configuración cruda (incluye mail_pass) para uso interno del mailer. */
    public function obtenerRaw(string $idCliente): array {
        $idCliente = $this->norm($idCliente);
        $s = $this->pdo->prepare("SELECT * FROM cliente_config WHERE id_cliente = ?");
        $s->execute([$idCliente]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /** Versión segura para el frontend: sin la contraseña, con flag de si existe. */
    public function obtener(string $idCliente): array {
        $row = $this->obtenerRaw($idCliente);
        $logo = $row['logo_url'] ?? '';
        return [
            'status'        => 'success',
            'logo_url'      => $logo ? (rtrim(SITE_URL, '/') . '/' . ltrim($logo, '/')) : '',
            'razon_social'  => $row['razon_social'] ?? '',
            'rfc'           => $row['rfc'] ?? '',
            'mail_from'     => $row['mail_from'] ?? '',
            'mail_from_name'=> $row['mail_from_name'] ?? '',
            'mail_host'     => $row['mail_host'] ?? '',
            'mail_port'     => (int)($row['mail_port'] ?? 465),
            'mail_secure'   => $row['mail_secure'] ?? 'ssl',
            'mail_user'     => $row['mail_user'] ?? '',
            'mail_pass_set' => !empty($row['mail_pass']),
        ];
    }

    private function ensureRow(string $idCliente): void {
        $this->pdo->prepare("INSERT IGNORE INTO cliente_config (id_cliente) VALUES (?)")->execute([$idCliente]);
    }

    /** Guarda datos generales + correo de envío. La foto/logo va por separado (multipart). */
    public function guardar(array $data, array $files, string $idCliente): array {
        $idCliente = $this->norm($idCliente);
        if (!$idCliente) return ['status' => 'error', 'message' => 'id_cliente requerido.'];
        $this->ensureRow($idCliente);

        $razon = trim($data['razon_social'] ?? '');
        $rfc   = strtoupper(trim($data['rfc'] ?? ''));
        $from  = trim($data['mail_from'] ?? '');
        $fromN = trim($data['mail_from_name'] ?? '');
        $host  = trim($data['mail_host'] ?? '');
        $port  = (int)($data['mail_port'] ?? 465) ?: 465;
        $sec   = in_array(($data['mail_secure'] ?? 'ssl'), ['ssl','tls'], true) ? $data['mail_secure'] : 'ssl';
        $user  = trim($data['mail_user'] ?? '');
        $pass  = (string)($data['mail_pass'] ?? '');

        if ($from && !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            return ['status' => 'error', 'message' => 'El correo de envío no tiene un formato válido.'];
        }

        // Logo (opcional)
        $logoUrl = null;
        if (!empty($files['logo']['tmp_name'])) {
            $ext = strtolower(pathinfo($files['logo']['name'] ?? '', PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png','webp'])) {
                return ['status' => 'error', 'message' => 'El logo debe ser JPG, PNG o WebP.'];
            }
            if (($files['logo']['size'] ?? 0) > 5 * 1024 * 1024) {
                return ['status' => 'error', 'message' => 'El logo no debe superar 5 MB.'];
            }
            $dir = rtrim(UPLOAD_DIR, '/') . "/clientes/$idCliente/";
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $fn  = 'logo_' . date('YmdHis') . ".$ext";
            $dest = $dir . $fn;
            // Comprimir/redimensionar con GD si está disponible
            if (!comprimirImagen($files['logo']['tmp_name'], $dest, 600, 600, 85)) {
                move_uploaded_file($files['logo']['tmp_name'], $dest);
            }
            // Borrar logo anterior
            $prev = $this->obtenerRaw($idCliente)['logo_url'] ?? '';
            if ($prev) { $pf = rtrim(UPLOAD_DIR,'/').'/'.ltrim($prev,'/'); if (file_exists($pf)) @unlink($pf); }
            $logoUrl = "uploads/clientes/$idCliente/$fn";
        }

        $sets = ['razon_social=?','rfc=?','mail_from=?','mail_from_name=?','mail_host=?','mail_port=?','mail_secure=?','mail_user=?'];
        $params = [$razon, $rfc, $from, $fromN, $host, $port, $sec, $user];
        if ($pass !== '') { $sets[] = 'mail_pass=?'; $params[] = $pass; }
        if ($logoUrl !== null) { $sets[] = 'logo_url=?'; $params[] = $logoUrl; }
        $params[] = $idCliente;

        $this->pdo->prepare("UPDATE cliente_config SET " . implode(',', $sets) . " WHERE id_cliente=?")->execute($params);
        return ['status' => 'success', 'message' => 'Configuración guardada.'];
    }

    public function eliminarLogo(string $idCliente): array {
        $idCliente = $this->norm($idCliente);
        $prev = $this->obtenerRaw($idCliente)['logo_url'] ?? '';
        if ($prev) { $pf = rtrim(UPLOAD_DIR,'/').'/'.ltrim($prev,'/'); if (file_exists($pf)) @unlink($pf); }
        $this->pdo->prepare("UPDATE cliente_config SET logo_url=NULL WHERE id_cliente=?")->execute([$idCliente]);
        return ['status' => 'success'];
    }
}
