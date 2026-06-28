<?php
/**
 * AVBA Certificaciones — Catálogo de Materiales del Cliente
 * Refacciones / suministros que el mecánico puede elegir al crear una
 * solicitud de material. El mecánico también puede escribir ítems libres.
 */
class ClienteMateriales {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->migrate();
    }

    private function migrate(): void {
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS cliente_materiales (
                  id          INT AUTO_INCREMENT PRIMARY KEY,
                  id_cliente  VARCHAR(20)  NOT NULL,
                  nombre      VARCHAR(200) NOT NULL,
                  num_parte   VARCHAR(80)  NULL,
                  unidad      VARCHAR(40)  NULL,
                  categoria   VARCHAR(100) NULL,
                  notas       TEXT         NULL,
                  activo      TINYINT(1)   NOT NULL DEFAULT 1,
                  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  KEY idx_cli (id_cliente)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\PDOException $e) {
            error_log('[ClienteMateriales] migrate: ' . $e->getMessage());
        }
    }

    private function norm(string $id): string {
        $id = trim($id);
        return (ctype_digit($id)) ? str_pad($id, 5, '0', STR_PAD_LEFT) : $id;
    }

    private function own(int $id, string $idCliente): bool {
        $s = $this->pdo->prepare("SELECT id FROM cliente_materiales WHERE id=? AND id_cliente=?");
        $s->execute([$id, $idCliente]);
        return (bool)$s->fetch();
    }

    public function listar(string $idCliente): array {
        $idCliente = $this->norm($idCliente);
        if (!$idCliente) return ['status' => 'error', 'message' => 'id_cliente requerido.'];

        $stmt = $this->pdo->prepare("
            SELECT id, nombre, num_parte, unidad, categoria, notas, activo
            FROM cliente_materiales
            WHERE id_cliente = ?
            ORDER BY categoria, nombre
        ");
        $stmt->execute([$idCliente]);
        return ['status' => 'success', 'materiales' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
    }

    public function guardar(array $data, string $idCliente): array {
        $idCliente = $this->norm($idCliente);
        if (!$idCliente) return ['status' => 'error', 'message' => 'id_cliente requerido.'];

        $nombre = trim($data['nombre'] ?? '');
        if (!$nombre) return ['status' => 'error', 'message' => 'El nombre del material es requerido.'];

        $numParte = trim($data['num_parte'] ?? '');
        $unidad   = trim($data['unidad']    ?? '');
        $cat      = trim($data['categoria'] ?? '');
        $notas    = trim($data['notas']     ?? '');
        $activo   = isset($data['activo']) ? (int)(bool)$data['activo'] : 1;
        $id       = (int)($data['id'] ?? 0);

        if ($id) {
            if (!$this->own($id, $idCliente)) return ['status' => 'error', 'message' => 'Material no encontrado.'];
            $this->pdo->prepare(
                "UPDATE cliente_materiales SET nombre=?, num_parte=?, unidad=?, categoria=?, notas=?, activo=? WHERE id=?"
            )->execute([$nombre, $numParte, $unidad, $cat, $notas, $activo, $id]);
        } else {
            $this->pdo->prepare(
                "INSERT INTO cliente_materiales (id_cliente, nombre, num_parte, unidad, categoria, notas, activo)
                 VALUES (?,?,?,?,?,?,?)"
            )->execute([$idCliente, $nombre, $numParte, $unidad, $cat, $notas, $activo]);
            $id = (int)$this->pdo->lastInsertId();
        }
        return ['status' => 'success', 'id' => $id];
    }

    public function eliminar(int $id, string $idCliente): array {
        $idCliente = $this->norm($idCliente);
        if (!$this->own($id, $idCliente)) return ['status' => 'error', 'message' => 'Material no encontrado.'];
        $this->pdo->prepare("DELETE FROM cliente_materiales WHERE id=?")->execute([$id]);
        return ['status' => 'success'];
    }
}
