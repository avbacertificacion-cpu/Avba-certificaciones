<?php
/**
 * AVBA Certificaciones — Gestión de Proveedores del Cliente
 * Permite al cliente registrar su directorio de proveedores con:
 *   - Datos generales (nombre/razón social, giro, RFC)
 *   - Contacto (persona, teléfono, correo, sitio web)
 *   - Ubicación (dirección, ciudad, estado)
 *   - Notas y estado
 */
class ClienteProveedores {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->migrate();
    }

    private function migrate(): void {
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS cliente_proveedores (
                  id              INT AUTO_INCREMENT PRIMARY KEY,
                  id_cliente      VARCHAR(20)  NOT NULL,
                  nombre          VARCHAR(200) NOT NULL,
                  giro            VARCHAR(150) NULL,
                  rfc             VARCHAR(20)  NULL,
                  contacto        VARCHAR(150) NULL,
                  telefono        VARCHAR(40)  NULL,
                  correo          VARCHAR(150) NULL,
                  sitio_web       VARCHAR(200) NULL,
                  direccion       VARCHAR(300) NULL,
                  ciudad          VARCHAR(120) NULL,
                  estado_ubic     VARCHAR(120) NULL,
                  notas           TEXT         NULL,
                  estado          VARCHAR(30)  NOT NULL DEFAULT 'Activo',
                  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  KEY idx_cli (id_cliente)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\PDOException $e) {
            error_log('[ClienteProveedores] migrate: ' . $e->getMessage());
        }
    }

    private function norm(string $id): string {
        $id = trim($id);
        return (ctype_digit($id)) ? str_pad($id, 5, '0', STR_PAD_LEFT) : $id;
    }

    private function own(int $id, string $idCliente): bool {
        $s = $this->pdo->prepare("SELECT id FROM cliente_proveedores WHERE id=? AND id_cliente=?");
        $s->execute([$id, $idCliente]);
        return (bool)$s->fetch();
    }

    // ════════════════════════════════════════════════════════
    //  LISTAR
    // ════════════════════════════════════════════════════════
    public function listar(string $idCliente): array {
        $idCliente = $this->norm($idCliente);
        if (!$idCliente) return ['status' => 'error', 'message' => 'id_cliente requerido.'];

        $stmt = $this->pdo->prepare("
            SELECT id, nombre, giro, rfc, contacto, telefono, correo, sitio_web,
                   direccion, ciudad, estado_ubic, notas, estado,
                   DATE_FORMAT(created_at,'%d/%m/%Y') AS fecha_registro
            FROM cliente_proveedores
            WHERE id_cliente = ?
            ORDER BY nombre
        ");
        $stmt->execute([$idCliente]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $activos = 0;
        foreach ($rows as $r) { if (($r['estado'] ?? '') === 'Activo') $activos++; }

        return [
            'status'      => 'success',
            'proveedores' => $rows,
            'total'       => count($rows),
            'activos'     => $activos,
        ];
    }

    // ════════════════════════════════════════════════════════
    //  GUARDAR (crear / editar)
    // ════════════════════════════════════════════════════════
    public function guardar(array $data, string $idCliente): array {
        $idCliente = $this->norm($idCliente);
        if (!$idCliente) return ['status' => 'error', 'message' => 'id_cliente requerido.'];

        $nombre = trim($data['nombre'] ?? '');
        if (!$nombre) return ['status' => 'error', 'message' => 'El nombre o razón social es requerido.'];

        $giro      = trim($data['giro']        ?? '');
        $rfc       = strtoupper(trim($data['rfc'] ?? ''));
        $contacto  = trim($data['contacto']    ?? '');
        $telefono  = trim($data['telefono']    ?? '');
        $correo    = trim($data['correo']      ?? '');
        $sitio     = trim($data['sitio_web']   ?? '');
        $direccion = trim($data['direccion']   ?? '');
        $ciudad    = trim($data['ciudad']      ?? '');
        $estUbic   = trim($data['estado_ubic'] ?? '');
        $notas     = trim($data['notas']       ?? '');
        $estado    = trim($data['estado']      ?? 'Activo');
        $id        = (int)($data['id'] ?? 0);

        if ($correo && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            return ['status' => 'error', 'message' => 'El correo no tiene un formato válido.'];
        }

        if ($id) {
            if (!$this->own($id, $idCliente)) return ['status' => 'error', 'message' => 'Proveedor no encontrado.'];
            $this->pdo->prepare(
                "UPDATE cliente_proveedores SET
                    nombre=?, giro=?, rfc=?, contacto=?, telefono=?, correo=?, sitio_web=?,
                    direccion=?, ciudad=?, estado_ubic=?, notas=?, estado=?
                 WHERE id=?"
            )->execute([$nombre, $giro, $rfc, $contacto, $telefono, $correo, $sitio,
                        $direccion, $ciudad, $estUbic, $notas, $estado, $id]);
        } else {
            $this->pdo->prepare(
                "INSERT INTO cliente_proveedores
                    (id_cliente, nombre, giro, rfc, contacto, telefono, correo, sitio_web,
                     direccion, ciudad, estado_ubic, notas, estado)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
            )->execute([$idCliente, $nombre, $giro, $rfc, $contacto, $telefono, $correo, $sitio,
                        $direccion, $ciudad, $estUbic, $notas, $estado]);
            $id = (int)$this->pdo->lastInsertId();
        }
        return ['status' => 'success', 'id' => $id];
    }

    // ════════════════════════════════════════════════════════
    //  ELIMINAR
    // ════════════════════════════════════════════════════════
    public function eliminar(int $id, string $idCliente): array {
        $idCliente = $this->norm($idCliente);
        if (!$this->own($id, $idCliente)) return ['status' => 'error', 'message' => 'Proveedor no encontrado.'];
        $this->pdo->prepare("DELETE FROM cliente_proveedores WHERE id=?")->execute([$id]);
        return ['status' => 'success'];
    }
}
