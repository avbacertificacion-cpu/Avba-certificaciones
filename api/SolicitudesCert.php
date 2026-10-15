<?php
/**
 * AVBA Certificaciones — Solicitudes de certificación del cliente
 *
 * El cliente ya tiene en su portal su personal y sus equipos, con su
 * documentación. Lo que faltaba era que pudiera decir "certifícame éstos".
 * Hasta ahora la cadena arrancaba en el inspector, que creaba el registro desde
 * campo: el cliente no tenía por dónde pedirlo.
 *
 * La solicitud llega a Calidad, que es quien ya revisa documentación y aprueba
 * el dictamen. El inspector la ve cuando Calidad la programa, de modo que
 * recibe trabajo ya filtrado y con papeles verificados, en vez de enterarse en
 * planta de que falta un documento.
 *
 * Una sola bandeja para personal y equipos: el ciclo de vida es el mismo y
 * Calidad revisa en un solo lugar.
 */
class SolicitudesCert {
    private PDO $pdo;

    /** Lo que puede pedirse. 'personal' incluye cursos y certificación de operador. */
    public const TIPOS = ['equipo', 'personal'];

    /**
     * Estados de la solicitud.
     *
     * No hay paso por presupuesto: son clientes establecidos que ya manejan los
     * costos, así que la solicitud entra directo a revisión.
     */
    public const ESTADOS = [
        'ENVIADA'     => 'Enviada',
        'EN_REVISION' => 'En revisión',
        'PROGRAMADA'  => 'Programada',
        'ATENDIDA'    => 'Atendida',
        'RECHAZADA'   => 'Rechazada',
        'CANCELADA'   => 'Cancelada',
    ];

    /** Lo que el cliente todavía puede cancelar. Una vez programada, ya se movió gente. */
    public const CANCELABLES = ['ENVIADA', 'EN_REVISION'];

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->migrate();
    }

    private function migrate(): void {
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS cliente_solicitudes_cert (
                  id              INT AUTO_INCREMENT PRIMARY KEY,
                  folio           VARCHAR(30)  NOT NULL,
                  id_cliente      VARCHAR(20)  NOT NULL,
                  tipo            VARCHAR(20)  NOT NULL,
                  estado          VARCHAR(20)  NOT NULL DEFAULT 'ENVIADA',
                  servicio        VARCHAR(200) NULL,
                  fecha_deseada   DATE         NULL,
                  sede            VARCHAR(300) NULL,
                  contacto_nombre VARCHAR(150) NULL,
                  contacto_tel    VARCHAR(40)  NULL,
                  notas           TEXT         NULL,
                  solicitado_por  VARCHAR(150) NULL,
                  revisado_por    VARCHAR(50)  NULL,
                  programada_para DATE         NULL,
                  inspector       VARCHAR(150) NULL,
                  motivo          TEXT         NULL,
                  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  updated_at      TIMESTAMP NULL,
                  KEY idx_cli (id_cliente),
                  KEY idx_estado (estado)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            /* Los renglones guardan COPIA de lo pedido, no sólo el id de origen.
               Si el cliente da de baja al operador o borra el equipo después de
               solicitar, la solicitud tiene que seguir diciendo qué se pidió:
               es el respaldo de un servicio que quizá ya se prestó. */
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS cliente_solicitudes_cert_item (
                  id           INT AUTO_INCREMENT PRIMARY KEY,
                  solicitud_id INT          NOT NULL,
                  origen_id    INT          NULL,
                  nombre       VARCHAR(250) NOT NULL,
                  detalle      VARCHAR(400) NULL,
                  curp         VARCHAR(20)  NULL,
                  estado       VARCHAR(20)  NOT NULL DEFAULT 'PENDIENTE',
                  avba_ref_id  INT          NULL,
                  notas        TEXT         NULL,
                  KEY idx_sol (solicitud_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\Throwable $e) {
            error_log('[SolicitudesCert] migrate: ' . $e->getMessage());
        }
    }

    private function norm(string $id): string {
        $id = trim($id);
        return ctype_digit($id) ? str_pad($id, 5, '0', STR_PAD_LEFT) : $id;
    }

    /** Folio por cliente: SC-00123-0004. Secuencial por cliente, como el de materiales. */
    private function siguienteFolio(string $idCliente): string {
        try {
            $s = $this->pdo->prepare("SELECT COUNT(*) FROM cliente_solicitudes_cert WHERE id_cliente = ?");
            $s->execute([$idCliente]);
            $n = (int)$s->fetchColumn() + 1;
        } catch (\Throwable $e) { $n = 1; }
        return 'SC-' . $idCliente . '-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
    }

    // ════════════════════════════════════════════════════════
    //  LADO DEL CLIENTE
    // ════════════════════════════════════════════════════════

    /**
     * Crea una solicitud con uno o varios renglones.
     *
     * Van varios en una sola porque así se pide en la práctica —"certifícame
     * estos seis montacargas" es una visita, no seis— y cada renglón lleva su
     * propio estado, de modo que uno pueda resultar conforme y otro no sin
     * enredar el seguimiento.
     */
    public function crear(array $payload, string $idCliente, string $quien): array {
        $idCliente = $this->norm($idCliente);
        if (!$idCliente) return ['status' => 'error', 'message' => 'No se pudo identificar la empresa.'];

        $tipo = strtolower(trim((string)($payload['tipo'] ?? '')));
        if (!in_array($tipo, self::TIPOS, true)) {
            return ['status' => 'error', 'message' => 'Indica si la solicitud es de personal o de equipos.'];
        }

        $ids = $payload['ids'] ?? [];
        if (!is_array($ids)) $ids = [];
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return ['status' => 'error', 'message' => 'Selecciona al menos un registro.'];
        if (count($ids) > 50) return ['status' => 'error', 'message' => 'Máximo 50 por solicitud. Divídela en varias.'];

        // Sólo lo que es suyo: un id de otra empresa no entra aunque se inyecte.
        $filas = $this->leerOrigen($tipo, $ids, $idCliente);
        if (!$filas) return ['status' => 'error', 'message' => 'No se encontraron esos registros en tu empresa.'];

        $curps = is_array($payload['curps'] ?? null) ? $payload['curps'] : [];

        $folio = $this->siguienteFolio($idCliente);
        try {
            $this->pdo->prepare(
                "INSERT INTO cliente_solicitudes_cert
                   (folio, id_cliente, tipo, estado, servicio, fecha_deseada, sede,
                    contacto_nombre, contacto_tel, notas, solicitado_por)
                 VALUES (?,?,?,'ENVIADA',?,?,?,?,?,?,?)"
            )->execute([
                $folio, $idCliente, $tipo,
                mb_substr(trim((string)($payload['servicio'] ?? '')), 0, 200) ?: null,
                $this->fecha($payload['fecha_deseada'] ?? ''),
                mb_substr(trim((string)($payload['sede'] ?? '')), 0, 300) ?: null,
                mb_substr(trim((string)($payload['contacto_nombre'] ?? '')), 0, 150) ?: null,
                mb_substr(trim((string)($payload['contacto_tel'] ?? '')), 0, 40) ?: null,
                mb_substr(trim((string)($payload['notas'] ?? '')), 0, 2000) ?: null,
                mb_substr($quien, 0, 150) ?: null,
            ]);
            $solId = (int)$this->pdo->lastInsertId();

            $ins = $this->pdo->prepare(
                "INSERT INTO cliente_solicitudes_cert_item (solicitud_id, origen_id, nombre, detalle, curp)
                 VALUES (?,?,?,?,?)"
            );
            foreach ($filas as $f) {
                $curp = strtoupper(trim((string)($curps[$f['id']] ?? '')));
                $ins->execute([
                    $solId, (int)$f['id'],
                    mb_substr($f['nombre'], 0, 250),
                    mb_substr($f['detalle'], 0, 400) ?: null,
                    $curp !== '' ? mb_substr($curp, 0, 20) : null,
                ]);
            }
        } catch (\Throwable $e) {
            error_log('[SolicitudesCert] crear: ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'No se pudo registrar la solicitud.'];
        }

        return ['status' => 'success', 'folio' => $folio, 'id' => $solId,
                'message' => 'Solicitud ' . $folio . ' enviada. Calidad la revisará.'];
    }

    /**
     * Nombre y detalle de cada registro, comprobando que sea del cliente.
     *
     * @return array<int,array{id:int,nombre:string,detalle:string}>
     */
    private function leerOrigen(string $tipo, array $ids, string $idCliente): array {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $sql = $tipo === 'personal'
            ? "SELECT id, nombre,
                      CONCAT_WS(' · ', NULLIF(puesto,''), NULLIF(numero_empleado,'')) AS detalle
               FROM cliente_personal WHERE id_cliente = ? AND id IN ($in)"
            : "SELECT id, nombre,
                      CONCAT_WS(' · ', NULLIF(tipo,''), NULLIF(marca,''), NULLIF(modelo,''),
                                NULLIF(serie,''), NULLIF(capacidad,'')) AS detalle
               FROM cliente_equipos WHERE id_cliente = ? AND id IN ($in)";
        try {
            $st = $this->pdo->prepare($sql);
            $st->execute(array_merge([$idCliente], $ids));
            return array_map(fn($r) => [
                'id'      => (int)$r['id'],
                'nombre'  => (string)$r['nombre'],
                'detalle' => (string)($r['detalle'] ?? ''),
            ], $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
        } catch (\Throwable $e) {
            error_log('[SolicitudesCert] leerOrigen: ' . $e->getMessage());
            return [];
        }
    }

    private function fecha($v): ?string {
        $v = trim((string)$v);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    }

    /** Las solicitudes de una empresa, con sus renglones. */
    public function misSolicitudes(string $idCliente): array {
        $idCliente = $this->norm($idCliente);
        if (!$idCliente) return ['status' => 'error', 'message' => 'No se pudo identificar la empresa.'];
        return ['status' => 'success', 'data' => $this->listado('s.id_cliente = ?', [$idCliente])];
    }

    /** El cliente retira una solicitud que todavía no se programó. */
    public function cancelar(int $id, string $idCliente): array {
        $idCliente = $this->norm($idCliente);
        $st = $this->pdo->prepare("SELECT estado, folio FROM cliente_solicitudes_cert WHERE id = ? AND id_cliente = ?");
        $st->execute([$id, $idCliente]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return ['status' => 'error', 'message' => 'Solicitud no encontrada.'];

        if (!in_array($row['estado'], self::CANCELABLES, true)) {
            return ['status' => 'error', 'message' =>
                'La solicitud ya está ' . (self::ESTADOS[$row['estado']] ?? $row['estado'])
                . '. Comunícate con Calidad para cancelarla.'];
        }
        $this->pdo->prepare("UPDATE cliente_solicitudes_cert SET estado='CANCELADA', updated_at=NOW() WHERE id = ?")
            ->execute([$id]);
        return ['status' => 'success', 'message' => 'Solicitud ' . $row['folio'] . ' cancelada.'];
    }

    // ════════════════════════════════════════════════════════
    //  LADO DE CALIDAD
    // ════════════════════════════════════════════════════════

    /** La bandeja. Por omisión, lo que está abierto. */
    public function bandeja(array $filtros = []): array {
        $where  = [];
        $params = [];

        $estado = strtoupper(trim((string)($filtros['estado'] ?? '')));
        if ($estado === 'ABIERTAS' || $estado === '') {
            $where[] = "s.estado IN ('ENVIADA','EN_REVISION','PROGRAMADA')";
        } elseif ($estado !== 'TODAS') {
            if (!isset(self::ESTADOS[$estado])) return ['status' => 'error', 'message' => 'Estado desconocido.'];
            $where[] = 's.estado = ?';
            $params[] = $estado;
        }

        $tipo = strtolower(trim((string)($filtros['tipo'] ?? '')));
        if (in_array($tipo, self::TIPOS, true)) { $where[] = 's.tipo = ?'; $params[] = $tipo; }

        $cli = trim((string)($filtros['cliente'] ?? ''));
        if ($cli !== '') { $where[] = 's.id_cliente LIKE ?'; $params[] = '%' . $cli . '%'; }

        return ['status' => 'success',
                'data'   => $this->listado($where ? implode(' AND ', $where) : '1=1', $params)];
    }

    private function listado(string $where, array $params): array {
        try {
            $st = $this->pdo->prepare("
                SELECT s.id, s.folio, s.id_cliente, s.tipo, s.estado, s.servicio,
                       DATE_FORMAT(s.fecha_deseada,'%d/%m/%Y')   AS fecha_deseada,
                       DATE_FORMAT(s.programada_para,'%d/%m/%Y') AS programada_para,
                       s.sede, s.contacto_nombre, s.contacto_tel, s.notas,
                       s.solicitado_por, s.revisado_por, s.inspector, s.motivo,
                       DATE_FORMAT(s.created_at,'%d/%m/%Y %H:%i') AS fecha,
                       COALESCE(c.nombre_cliente, s.id_cliente)   AS cliente
                FROM cliente_solicitudes_cert s
                LEFT JOIN clientes c ON c.primera_parte = s.id_cliente
                WHERE $where
                ORDER BY FIELD(s.estado,'ENVIADA','EN_REVISION','PROGRAMADA','ATENDIDA','RECHAZADA','CANCELADA'),
                         s.created_at DESC
                LIMIT 300
            ");
            $st->execute($params);
            $filas = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            error_log('[SolicitudesCert] listado: ' . $e->getMessage());
            return [];
        }
        if (!$filas) return [];

        $ids = array_column($filas, 'id');
        $in  = implode(',', array_fill(0, count($ids), '?'));
        try {
            $it = $this->pdo->prepare(
                "SELECT id, solicitud_id, origen_id, nombre, detalle, curp, estado, avba_ref_id, notas
                 FROM cliente_solicitudes_cert_item WHERE solicitud_id IN ($in) ORDER BY id"
            );
            $it->execute($ids);
            $porSol = [];
            foreach ($it->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $porSol[(int)$r['solicitud_id']][] = $r;
            }
        } catch (\Throwable $e) { $porSol = []; }

        foreach ($filas as &$f) {
            $f['items']        = $porSol[(int)$f['id']] ?? [];
            $f['total_items']  = count($f['items']);
            $f['estado_texto'] = self::ESTADOS[$f['estado']] ?? $f['estado'];
        }
        return $filas;
    }

    /**
     * Calidad mueve la solicitud de estado.
     *
     * Las transiciones están acotadas: una solicitud atendida, rechazada o
     * cancelada ya no se mueve, y rechazar exige decir por qué —el cliente
     * recibe ese texto y "no procede" a secas no le sirve de nada.
     */
    public function actualizar(array $payload, string $quien): array {
        $id = (int)($payload['id'] ?? 0);
        if (!$id) return ['status' => 'error', 'message' => 'Falta la solicitud.'];

        $nuevo = strtoupper(trim((string)($payload['estado'] ?? '')));
        if (!isset(self::ESTADOS[$nuevo])) return ['status' => 'error', 'message' => 'Estado desconocido.'];

        $st = $this->pdo->prepare("SELECT estado, folio FROM cliente_solicitudes_cert WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return ['status' => 'error', 'message' => 'Solicitud no encontrada.'];

        $permitido = [
            'ENVIADA'     => ['EN_REVISION', 'PROGRAMADA', 'RECHAZADA'],
            'EN_REVISION' => ['PROGRAMADA', 'RECHAZADA', 'ENVIADA'],
            'PROGRAMADA'  => ['ATENDIDA', 'RECHAZADA', 'EN_REVISION'],
        ];
        if (!in_array($nuevo, $permitido[$row['estado']] ?? [], true)) {
            return ['status' => 'error', 'message' =>
                'No se puede pasar de ' . (self::ESTADOS[$row['estado']] ?? $row['estado'])
                . ' a ' . self::ESTADOS[$nuevo] . '.'];
        }

        $motivo = trim((string)($payload['motivo'] ?? ''));
        if ($nuevo === 'RECHAZADA' && $motivo === '') {
            return ['status' => 'error', 'message' => 'Escribe por qué se rechaza: el cliente recibe ese texto.'];
        }

        $sets   = ['estado = ?', 'revisado_por = ?', 'updated_at = NOW()'];
        $params = [$nuevo, mb_substr($quien, 0, 50)];

        if ($nuevo === 'PROGRAMADA') {
            $sets[]   = 'programada_para = ?';
            $params[] = $this->fecha($payload['programada_para'] ?? '');
            $sets[]   = 'inspector = ?';
            $params[] = mb_substr(trim((string)($payload['inspector'] ?? '')), 0, 150) ?: null;
        }
        if ($motivo !== '') { $sets[] = 'motivo = ?'; $params[] = mb_substr($motivo, 0, 2000); }

        $params[] = $id;
        $this->pdo->prepare("UPDATE cliente_solicitudes_cert SET " . implode(', ', $sets) . " WHERE id = ?")
            ->execute($params);

        return ['status' => 'success', 'message' =>
            'Solicitud ' . $row['folio'] . ': ' . self::ESTADOS[$nuevo] . '.'];
    }

    /**
     * Lo que el cliente ya subió de cada registro pedido.
     *
     * Es la razón de ser de la bandeja: Calidad ve la documentación antes de
     * programar, en vez de que el inspector se entere en planta de que falta.
     */
    public function documentos(int $solicitudId): array {
        $st = $this->pdo->prepare("SELECT tipo, id_cliente FROM cliente_solicitudes_cert WHERE id = ?");
        $st->execute([$solicitudId]);
        $sol = $st->fetch(PDO::FETCH_ASSOC);
        if (!$sol) return ['status' => 'error', 'message' => 'Solicitud no encontrada.'];

        $it = $this->pdo->prepare(
            "SELECT id, origen_id, nombre, detalle, curp FROM cliente_solicitudes_cert_item
             WHERE solicitud_id = ? ORDER BY id"
        );
        $it->execute([$solicitudId]);
        $items = $it->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $tabla = $sol['tipo'] === 'personal' ? 'cliente_personal_docs' : 'cliente_equipos_docs';
        $col   = $sol['tipo'] === 'personal' ? 'personal_id'           : 'equipo_id';

        foreach ($items as &$i) {
            $i['documentos'] = [];
            if (!$i['origen_id']) continue;
            try {
                $d = $this->pdo->prepare(
                    "SELECT tipo_doc, nombre, archivo_url,
                            DATE_FORMAT(vigencia,'%d/%m/%Y') AS vigencia,
                            (vigencia IS NOT NULL AND vigencia < CURDATE()) AS vencido
                     FROM $tabla WHERE $col = ? ORDER BY tipo_doc, nombre"
                );
                $d->execute([(int)$i['origen_id']]);
                $i['documentos'] = $d->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (\Throwable $e) { /* el registro pudo borrarse: se deja sin documentos */ }
        }
        return ['status' => 'success', 'tipo' => $sol['tipo'], 'items' => $items];
    }
}
