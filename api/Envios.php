<?php
/**
 * AVBA Certificaciones — Bitácora de envíos por correo
 *
 * La tabla `historico_envios` lleva años guardando a quién se le mandó qué, y
 * en todo el proyecto no había un solo SELECT contra ella: el dato estaba,
 * pero no había dónde verlo. Esto lo consulta y unifica lo que se registra.
 *
 * Lo que se guarda es QUÉ SE INTENTÓ ENVIAR, y ahora también si salió o si
 * falló y por qué. Que el correo salga del servidor no garantiza que llegue
 * —puede rebotar después—, así que la bitácora responde "lo mandamos este día
 * a esta dirección", no "lo leyeron".
 */
class Envios {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->migrate();
    }

    private function migrate(): void {
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS historico_envios (
                  id          INT AUTO_INCREMENT PRIMARY KEY,
                  fecha_envio DATETIME     DEFAULT CURRENT_TIMESTAMP,
                  cliente     VARCHAR(150) DEFAULT NULL,
                  control     VARCHAR(20)  DEFAULT NULL,
                  correo      VARCHAR(150) DEFAULT NULL,
                  archivo     VARCHAR(255) DEFAULT NULL,
                  usuario     VARCHAR(50)  DEFAULT NULL,
                  equipo_id   INT          DEFAULT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\Throwable $e) {
            error_log('[Envios] migrate: ' . $e->getMessage());
        }
        // Columnas que llegan ahora. Van una por una con information_schema
        // porque ADD COLUMN IF NOT EXISTS sólo existe en MariaDB y en MySQL un
        // ALTER múltiple fallaría entero.
        $nuevas = [
            'modulo'   => "ALTER TABLE historico_envios ADD COLUMN modulo VARCHAR(20) NULL",
            'registro_id' => "ALTER TABLE historico_envios ADD COLUMN registro_id INT NULL",
            'asunto'   => "ALTER TABLE historico_envios ADD COLUMN asunto VARCHAR(255) NULL",
            'ok'       => "ALTER TABLE historico_envios ADD COLUMN ok TINYINT(1) NOT NULL DEFAULT 1",
            'error'    => "ALTER TABLE historico_envios ADD COLUMN error VARCHAR(500) NULL",
        ];
        foreach ($nuevas as $col => $ddl) {
            try {
                if (!columnaExiste($this->pdo, 'historico_envios', $col)) $this->pdo->exec($ddl);
            } catch (\Throwable $e) {
                error_log('[Envios] columna ' . $col . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * Anota un envío. La llaman todos los módulos que mandan correo.
     *
     * Es estática y se traga sus propios errores a propósito: la bitácora no
     * puede ser el motivo de que falle un envío que ya salió. Si no se puede
     * registrar, se deja constancia en el log del servidor y se sigue.
     *
     * @param array $d cliente, control, correo, archivo, usuario, modulo,
     *                 registro_id, asunto, ok, error
     */
    public static function anotar(PDO $pdo, array $d): void {
        try {
            $cols = ['cliente','control','correo','archivo','usuario','equipo_id'];
            $vals = [
                $d['cliente'] ?? null, $d['control'] ?? null,
                // Varios destinatarios caben en una línea: es como se mandan.
                mb_substr(trim((string)($d['correo'] ?? '')), 0, 150),
                mb_substr((string)($d['archivo'] ?? ''), 0, 255),
                mb_substr((string)($d['usuario'] ?? ''), 0, 50),
                isset($d['equipo_id']) ? (int)$d['equipo_id'] : null,
            ];
            // Las columnas nuevas pueden no existir si la migración no corrió:
            // en ese caso se guarda lo de siempre antes que perder el registro.
            foreach (['modulo','registro_id','asunto','ok','error'] as $c) {
                if (!array_key_exists($c, $d)) continue;
                if (!columnaExiste($pdo, 'historico_envios', $c)) continue;
                $cols[] = $c;
                $vals[] = $c === 'ok' ? (int)(bool)$d[$c]
                        : ($c === 'registro_id' ? (int)$d[$c] : mb_substr((string)$d[$c], 0, 500));
            }
            $pdo->prepare(
                "INSERT INTO historico_envios (" . implode(',', $cols) . ")
                 VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")"
            )->execute($vals);
        } catch (\Throwable $e) {
            error_log('[Envios] anotar: ' . $e->getMessage());
        }
    }

    /** Lo mismo, para un envío que falló. */
    public static function anotarFallo(PDO $pdo, array $d, string $motivo): void {
        self::anotar($pdo, $d + ['ok' => 0, 'error' => $motivo]);
    }

    private const MODULOS = [
        'equipo'      => 'Maquinaria',
        'accesorio'   => 'Accesorios',
        'arnes'       => 'Arneses',
        'personal'    => 'Personal',
        'pnd'         => 'PND',
        'presupuesto' => 'Presupuestos',
    ];

    /**
     * La bitácora, filtrable. Se pagina porque esta tabla sólo crece: sin
     * límite, abrir la pantalla con años de envíos se traería todo de golpe.
     */
    public function listar(array $f = []): array {
        $where  = [];
        $params = [];

        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '') {
            // Una sola caja para cliente, correo, folio y archivo: quien busca
            // aquí suele tener un dato suelto, no saber en qué campo está.
            $where[] = "(cliente LIKE ? OR correo LIKE ? OR control LIKE ? OR archivo LIKE ?)";
            array_push($params, "%$q%", "%$q%", "%$q%", "%$q%");
        }
        if (!empty($f['desde'])) { $where[] = "fecha_envio >= ?"; $params[] = $f['desde'] . ' 00:00:00'; }
        if (!empty($f['hasta'])) { $where[] = "fecha_envio <= ?"; $params[] = $f['hasta'] . ' 23:59:59'; }
        if (!empty($f['usuario'])) { $where[] = "usuario = ?"; $params[] = $f['usuario']; }

        $hayModulo = columnaExiste($this->pdo, 'historico_envios', 'modulo');
        if (!empty($f['modulo']) && $hayModulo) { $where[] = "modulo = ?"; $params[] = $f['modulo']; }

        $hayOk = columnaExiste($this->pdo, 'historico_envios', 'ok');
        if (isset($f['ok']) && $f['ok'] !== '' && $hayOk) { $where[] = "ok = ?"; $params[] = (int)$f['ok']; }

        $sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $lim = max(1, min(500, (int)($f['limite'] ?? 200)));
        $off = max(0, (int)($f['desplazamiento'] ?? 0));

        try {
            $tot = $this->pdo->prepare("SELECT COUNT(*) FROM historico_envios$sql");
            $tot->execute($params);
            $total = (int)$tot->fetchColumn();

            $cols = "id, DATE_FORMAT(fecha_envio,'%d/%m/%Y %H:%i') AS fecha, cliente, control,
                     correo, archivo, usuario"
                  . ($hayModulo ? ", modulo, registro_id" : ", NULL AS modulo, NULL AS registro_id")
                  . ($hayOk     ? ", ok, error"           : ", 1 AS ok, NULL AS error");

            $st = $this->pdo->prepare(
                "SELECT $cols FROM historico_envios$sql ORDER BY fecha_envio DESC, id DESC LIMIT $lim OFFSET $off"
            );
            $st->execute($params);
            $filas = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            error_log('[Envios] listar: ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'No se pudo leer la bitácora de envíos.'];
        }

        foreach ($filas as &$r) {
            $r['ok'] = (int)$r['ok'];
            $r['modulo_label'] = self::MODULOS[$r['modulo'] ?? ''] ?? ($r['modulo'] ?: '—');
        }
        return ['status' => 'success', 'data' => $filas, 'total' => $total,
                'desplazamiento' => $off, 'limite' => $lim];
    }

    /** Los envíos de un registro, para la pestaña de historial de su detalle. */
    public function deRegistro(string $modulo, int $registroId): array {
        try {
            $hayModulo = columnaExiste($this->pdo, 'historico_envios', 'modulo');
            $hayOk     = columnaExiste($this->pdo, 'historico_envios', 'ok');
            // Los envíos viejos sólo tienen equipo_id; los nuevos, modulo +
            // registro_id. Se buscan por las dos vías para no perder historia.
            $cond = $hayModulo ? "(equipo_id = ? OR (modulo = ? AND registro_id = ?))" : "equipo_id = ?";
            $par  = $hayModulo ? [$registroId, $modulo, $registroId] : [$registroId];

            $cols = "id, DATE_FORMAT(fecha_envio,'%d/%m/%Y %H:%i') AS fecha, correo, archivo, usuario"
                  . ($hayOk ? ", ok, error" : ", 1 AS ok, NULL AS error");
            $st = $this->pdo->prepare(
                "SELECT $cols FROM historico_envios WHERE $cond ORDER BY fecha_envio DESC, id DESC LIMIT 50"
            );
            $st->execute($par);
            $filas = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($filas as &$r) $r['ok'] = (int)$r['ok'];
            return ['status' => 'success', 'data' => $filas];
        } catch (\Throwable $e) {
            error_log('[Envios] deRegistro: ' . $e->getMessage());
            return ['status' => 'success', 'data' => []];
        }
    }

    /** Quiénes han enviado, para el filtro por usuario. */
    public function usuarios(): array {
        try {
            $st = $this->pdo->query(
                "SELECT DISTINCT usuario FROM historico_envios
                 WHERE usuario IS NOT NULL AND usuario <> '' ORDER BY usuario"
            );
            return array_column($st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'usuario');
        } catch (\Throwable $e) {
            return [];
        }
    }
}
