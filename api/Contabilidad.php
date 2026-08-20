<?php
/**
 * AVBA Certificaciones — Contabilidad
 *
 * Traslada al sistema el control que se llevaba en Excel (INGRESOS, GASTOS,
 * DINERO ENTREGADO, PAGOS PENDIENTES DE REEMBOLSO, SALDOS POR PERSONAL y
 * BALANCE GENERAL), con dos diferencias de fondo:
 *
 *  1. Lo que en el Excel eran listas escritas a mano en cada hoja —y que ya se
 *     habían desincronizado entre ellas— aquí es UN catálogo configurable:
 *     categorías, formas de pago, tipos de comprobante y personal.
 *  2. El estado de reembolso no se guarda: se calcula. En el Excel dependía de
 *     una cadena de columnas auxiliares (K, L, M, N, P) que se rompía al
 *     ordenar o insertar filas.
 *
 * El criterio de reembolso es el mismo del Excel y conviene dejarlo escrito:
 * los gastos que una persona pagó de su bolsa se consideran saldados en el
 * orden en que ocurrieron, hasta agotar lo que se le ha entregado. Es una
 * liquidación PEPS: si se le entregaron $1,000 y gastó $400, $300 y $500, los
 * dos primeros quedan reembolsados y el tercero pendiente.
 */
class Contabilidad {
    private PDO $pdo;

    /** Tipos de catálogo configurables desde la interfaz. */
    public const CATALOGOS = [
        'categoria'   => 'Categorías de ingreso',
        'forma_pago'  => 'Formas de pago',
        'comprobante' => 'Tipos de comprobante',
        'entrega'     => 'Formas de entrega',
        'personal'    => 'Personal que puede pagar de su bolsa',
    ];

    /** Valores iniciales, tomados de las listas del Excel. */
    private const SEED = [
        'categoria'   => ['Servicio', 'Inspección', 'Consultoría', 'Certificación', 'Mantenimiento', 'Otro'],
        'forma_pago'  => ['Efectivo', 'Tarjeta', 'Transferencia'],
        'comprobante' => ['Factura', 'Ticket', 'Captura de pantalla', 'Mensaje', 'Sin comprobante'],
        'entrega'     => ['Efectivo', 'Transferencia', 'Débito', 'Crédito', 'Tarjeta', 'A plan'],
        'personal'    => ['Marcos', 'Michel', 'Yoselin', 'Victoria', 'Extintores'],
    ];

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->migrate();
    }

    // ════════════════════════════════════════════════════════
    //  ESQUEMA
    // ════════════════════════════════════════════════════════

    private function migrate(): void {
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS conta_periodos (
                  id          INT AUTO_INCREMENT PRIMARY KEY,
                  nombre      VARCHAR(120) NOT NULL,
                  fecha_ini   DATE NULL,
                  fecha_fin   DATE NULL,
                  cerrado     TINYINT(1) NOT NULL DEFAULT 0,
                  creado      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS conta_catalogo (
                  id      INT AUTO_INCREMENT PRIMARY KEY,
                  tipo    VARCHAR(20)  NOT NULL,
                  valor   VARCHAR(120) NOT NULL,
                  orden   INT NOT NULL DEFAULT 0,
                  activo  TINYINT(1) NOT NULL DEFAULT 1,
                  UNIQUE KEY uk_tipo_valor (tipo, valor),
                  KEY idx_tipo (tipo)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS conta_ingresos (
                  id          INT AUTO_INCREMENT PRIMARY KEY,
                  periodo_id  INT NOT NULL,
                  fecha       DATE NOT NULL,
                  concepto    VARCHAR(300) NOT NULL,
                  categoria   VARCHAR(120) NULL,
                  cliente     VARCHAR(200) NULL,
                  responsable VARCHAR(120) NULL,
                  forma_pago  VARCHAR(120) NULL,
                  notas       TEXT NULL,
                  monto       DECIMAL(14,2) NOT NULL DEFAULT 0,
                  creado      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  KEY idx_periodo (periodo_id),
                  KEY idx_fecha (fecha)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS conta_gastos (
                  id          INT AUTO_INCREMENT PRIMARY KEY,
                  periodo_id  INT NOT NULL,
                  fecha       DATE NOT NULL,
                  concepto    VARCHAR(300) NOT NULL,
                  pagador     VARCHAR(120) NULL,
                  forma_pago  VARCHAR(120) NULL,
                  comprobante VARCHAR(120) NULL,
                  notas       TEXT NULL,
                  monto       DECIMAL(14,2) NOT NULL DEFAULT 0,
                  fecha_reembolso DATE NULL,
                  nota_reembolso  VARCHAR(300) NULL,
                  creado      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  KEY idx_periodo (periodo_id),
                  KEY idx_pagador (pagador),
                  KEY idx_fecha (fecha)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS conta_entregas (
                  id          INT AUTO_INCREMENT PRIMARY KEY,
                  periodo_id  INT NOT NULL,
                  fecha       DATE NOT NULL,
                  persona     VARCHAR(120) NOT NULL,
                  monto       DECIMAL(14,2) NOT NULL DEFAULT 0,
                  forma       VARCHAR(120) NULL,
                  notas       TEXT NULL,
                  creado      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  KEY idx_periodo (periodo_id),
                  KEY idx_persona (persona)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $this->seedCatalogo();
            $this->seedPeriodo();
        } catch (\Throwable $e) {
            // Se atrapa Throwable: esta clase se construye en todas las
            // peticiones y un fallo de migración no puede tumbar el login.
            error_log('[Contabilidad] migrate: ' . $e->getMessage());
        }
    }

    /** Carga los valores iniciales una sola vez por tipo. */
    private function seedCatalogo(): void {
        try {
            $n = (int)$this->pdo->query("SELECT COUNT(*) FROM conta_catalogo")->fetchColumn();
            if ($n > 0) return;
            $ins = $this->pdo->prepare(
                "INSERT INTO conta_catalogo (tipo, valor, orden) VALUES (?,?,?)"
            );
            foreach (self::SEED as $tipo => $valores) {
                foreach ($valores as $i => $v) {
                    try { $ins->execute([$tipo, $v, $i + 1]); } catch (\Throwable $e) {}
                }
            }
        } catch (\Throwable $e) {
            error_log('[Contabilidad] seedCatalogo: ' . $e->getMessage());
        }
    }

    /** Sin periodos no se puede capturar nada: se crea uno con el año en curso. */
    private function seedPeriodo(): void {
        try {
            $n = (int)$this->pdo->query("SELECT COUNT(*) FROM conta_periodos")->fetchColumn();
            if ($n > 0) return;
            $anio = (int)date('Y');
            $this->pdo->prepare(
                "INSERT INTO conta_periodos (nombre, fecha_ini, fecha_fin) VALUES (?,?,?)"
            )->execute(["Ejercicio $anio", "$anio-01-01", "$anio-12-31"]);
        } catch (\Throwable $e) {
            error_log('[Contabilidad] seedPeriodo: ' . $e->getMessage());
        }
    }

    // ════════════════════════════════════════════════════════
    //  PERIODOS Y CATÁLOGOS
    // ════════════════════════════════════════════════════════

    public function periodos(): array {
        $rows = $this->pdo->query(
            "SELECT id, nombre, fecha_ini, fecha_fin, cerrado FROM conta_periodos ORDER BY fecha_ini DESC, id DESC"
        )->fetchAll();
        return ['status' => 'success', 'data' => $rows];
    }

    public function guardarPeriodo(array $p): array {
        $id     = (int)($p['id'] ?? 0);
        $nombre = trim((string)($p['nombre'] ?? ''));
        if ($nombre === '') return ['status' => 'error', 'message' => 'El nombre del periodo es obligatorio.'];
        $ini = $this->fecha($p['fecha_ini'] ?? null);
        $fin = $this->fecha($p['fecha_fin'] ?? null);
        if ($ini && $fin && $ini > $fin) {
            return ['status' => 'error', 'message' => 'La fecha inicial no puede ser posterior a la final.'];
        }
        $cerrado = !empty($p['cerrado']) ? 1 : 0;

        if ($id) {
            $this->pdo->prepare(
                "UPDATE conta_periodos SET nombre=?, fecha_ini=?, fecha_fin=?, cerrado=? WHERE id=?"
            )->execute([$nombre, $ini, $fin, $cerrado, $id]);
        } else {
            $this->pdo->prepare(
                "INSERT INTO conta_periodos (nombre, fecha_ini, fecha_fin, cerrado) VALUES (?,?,?,?)"
            )->execute([$nombre, $ini, $fin, $cerrado]);
            $id = (int)$this->pdo->lastInsertId();
        }
        return ['status' => 'success', 'id' => $id, 'message' => 'Periodo guardado.'];
    }

    public function eliminarPeriodo(int $id): array {
        foreach (['conta_ingresos', 'conta_gastos', 'conta_entregas'] as $t) {
            $n = $this->pdo->prepare("SELECT COUNT(*) FROM `$t` WHERE periodo_id = ?");
            $n->execute([$id]);
            if ((int)$n->fetchColumn() > 0) {
                return ['status' => 'error', 'message' =>
                    'El periodo tiene movimientos registrados. Bórralos primero o ciérralo en vez de eliminarlo.'];
            }
        }
        $this->pdo->prepare("DELETE FROM conta_periodos WHERE id = ?")->execute([$id]);
        return ['status' => 'success', 'message' => 'Periodo eliminado.'];
    }

    /** Catálogo completo, agrupado por tipo, para armar los desplegables. */
    public function catalogo(bool $soloActivos = true): array {
        $sql  = "SELECT id, tipo, valor, orden, activo FROM conta_catalogo";
        if ($soloActivos) $sql .= " WHERE activo = 1";
        $sql .= " ORDER BY tipo, orden, valor";
        $out = array_fill_keys(array_keys(self::CATALOGOS), []);
        foreach ($this->pdo->query($sql)->fetchAll() as $r) {
            $out[$r['tipo']][] = $r;
        }
        return ['status' => 'success', 'data' => $out, 'tipos' => self::CATALOGOS];
    }

    public function guardarCatalogo(array $p): array {
        $id    = (int)($p['id'] ?? 0);
        $tipo  = trim((string)($p['tipo'] ?? ''));
        $valor = trim((string)($p['valor'] ?? ''));
        if (!isset(self::CATALOGOS[$tipo])) return ['status' => 'error', 'message' => 'Tipo de catálogo no válido.'];
        if ($valor === '') return ['status' => 'error', 'message' => 'El valor es obligatorio.'];
        if (mb_strlen($valor) > 120) return ['status' => 'error', 'message' => 'El valor no puede exceder 120 caracteres.'];

        $dup = $this->pdo->prepare("SELECT id FROM conta_catalogo WHERE tipo=? AND valor=? AND id<>?");
        $dup->execute([$tipo, $valor, $id]);
        if ($dup->fetch()) return ['status' => 'error', 'message' => 'Ese valor ya existe en el catálogo.'];

        $orden  = (int)($p['orden'] ?? 0);
        $activo = array_key_exists('activo', $p) ? (!empty($p['activo']) ? 1 : 0) : 1;

        if ($id) {
            // Renombrar un valor arrastra los movimientos que lo usan: si no,
            // quedarían apuntando a una etiqueta que ya no existe y saldrían
            // fuera de los totales por categoría o por persona.
            $ant = $this->pdo->prepare("SELECT tipo, valor FROM conta_catalogo WHERE id=?");
            $ant->execute([$id]);
            $prev = $ant->fetch();
            $this->pdo->prepare("UPDATE conta_catalogo SET valor=?, orden=?, activo=? WHERE id=?")
                      ->execute([$valor, $orden, $activo, $id]);
            if ($prev && $prev['valor'] !== $valor) $this->propagarRenombre($tipo, $prev['valor'], $valor);
        } else {
            $this->pdo->prepare("INSERT INTO conta_catalogo (tipo, valor, orden, activo) VALUES (?,?,?,?)")
                      ->execute([$tipo, $valor, $orden, $activo]);
            $id = (int)$this->pdo->lastInsertId();
        }
        return ['status' => 'success', 'id' => $id, 'message' => 'Catálogo actualizado.'];
    }

    /** Al renombrar un valor del catálogo, se corrigen los movimientos que lo usaban. */
    private function propagarRenombre(string $tipo, string $antes, string $ahora): void {
        $mapa = [
            'categoria'   => [['conta_ingresos', 'categoria']],
            'forma_pago'  => [['conta_ingresos', 'forma_pago'], ['conta_gastos', 'forma_pago']],
            'comprobante' => [['conta_gastos', 'comprobante']],
            'entrega'     => [['conta_entregas', 'forma']],
            'personal'    => [['conta_gastos', 'pagador'], ['conta_entregas', 'persona']],
        ];
        foreach ($mapa[$tipo] ?? [] as [$tabla, $col]) {
            try {
                $this->pdo->prepare("UPDATE `$tabla` SET `$col` = ? WHERE `$col` = ?")->execute([$ahora, $antes]);
            } catch (\Throwable $e) {
                error_log("[Contabilidad] propagarRenombre $tabla.$col: " . $e->getMessage());
            }
        }
    }

    public function eliminarCatalogo(int $id): array {
        $st = $this->pdo->prepare("SELECT tipo, valor FROM conta_catalogo WHERE id=?");
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) return ['status' => 'error', 'message' => 'Valor no encontrado.'];

        // Si ya se usó, se desactiva en vez de borrarse: los movimientos
        // históricos conservan la etiqueta con la que se capturaron.
        if ($this->catalogoEnUso($row['tipo'], $row['valor'])) {
            $this->pdo->prepare("UPDATE conta_catalogo SET activo = 0 WHERE id = ?")->execute([$id]);
            return ['status' => 'success', 'desactivado' => true, 'message' =>
                'El valor ya está usado en movimientos, así que se desactivó: deja de ofrecerse al capturar pero los registros anteriores lo conservan.'];
        }
        $this->pdo->prepare("DELETE FROM conta_catalogo WHERE id=?")->execute([$id]);
        return ['status' => 'success', 'message' => 'Valor eliminado.'];
    }

    private function catalogoEnUso(string $tipo, string $valor): bool {
        $mapa = [
            'categoria'   => [['conta_ingresos', 'categoria']],
            'forma_pago'  => [['conta_ingresos', 'forma_pago'], ['conta_gastos', 'forma_pago']],
            'comprobante' => [['conta_gastos', 'comprobante']],
            'entrega'     => [['conta_entregas', 'forma']],
            'personal'    => [['conta_gastos', 'pagador'], ['conta_entregas', 'persona']],
        ];
        foreach ($mapa[$tipo] ?? [] as [$tabla, $col]) {
            try {
                $st = $this->pdo->prepare("SELECT 1 FROM `$tabla` WHERE `$col` = ? LIMIT 1");
                $st->execute([$valor]);
                if ($st->fetch()) return true;
            } catch (\Throwable $e) {}
        }
        return false;
    }

    // ════════════════════════════════════════════════════════
    //  MOVIMIENTOS
    // ════════════════════════════════════════════════════════

    /** Definición de cada tipo de movimiento: tabla, campos de texto y etiqueta. */
    private const MOVIMIENTOS = [
        'ingreso' => [
            'tabla'  => 'conta_ingresos',
            'campos' => ['concepto', 'categoria', 'cliente', 'responsable', 'forma_pago', 'notas'],
            'label'  => 'Ingreso',
        ],
        'gasto' => [
            'tabla'  => 'conta_gastos',
            'campos' => ['concepto', 'pagador', 'forma_pago', 'comprobante', 'notas', 'nota_reembolso'],
            'label'  => 'Gasto',
        ],
        'entrega' => [
            'tabla'  => 'conta_entregas',
            'campos' => ['persona', 'forma', 'notas'],
            'label'  => 'Entrega de dinero',
        ],
    ];

    public function listarMovimientos(string $tipo, int $periodoId, string $busca = ''): array {
        if (!isset(self::MOVIMIENTOS[$tipo])) return ['status' => 'error', 'message' => 'Tipo no válido.'];
        $def = self::MOVIMIENTOS[$tipo];

        $sql    = "SELECT * FROM `{$def['tabla']}` WHERE periodo_id = ?";
        $params = [$periodoId];
        $busca  = trim($busca);
        if ($busca !== '') {
            $like  = '%' . $busca . '%';
            $cond  = [];
            foreach ($def['campos'] as $c) { $cond[] = "`$c` LIKE ?"; $params[] = $like; }
            $sql  .= ' AND (' . implode(' OR ', $cond) . ')';
        }
        $sql .= " ORDER BY fecha, id";

        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll();

        $total = 0.0;
        foreach ($rows as &$r) { $r['monto'] = (float)$r['monto']; $total += $r['monto']; }
        unset($r);

        return ['status' => 'success', 'data' => $rows, 'total' => round($total, 2)];
    }

    public function guardarMovimiento(string $tipo, array $p): array {
        if (!isset(self::MOVIMIENTOS[$tipo])) return ['status' => 'error', 'message' => 'Tipo no válido.'];
        $def = self::MOVIMIENTOS[$tipo];

        $id        = (int)($p['id'] ?? 0);
        $periodoId = (int)($p['periodo_id'] ?? 0);
        $fecha     = $this->fecha($p['fecha'] ?? null);
        $monto     = $this->monto($p['monto'] ?? null);

        if (!$periodoId) return ['status' => 'error', 'message' => 'Indica el periodo.'];
        if (!$fecha)     return ['status' => 'error', 'message' => 'La fecha es obligatoria.'];
        if ($monto === null) return ['status' => 'error', 'message' => 'El monto debe ser un número.'];
        if ($monto < 0)  return ['status' => 'error', 'message' => 'El monto no puede ser negativo. Registra una salida como gasto, no como ingreso en negativo.'];

        $obligatorio = $tipo === 'entrega' ? 'persona' : 'concepto';
        if (trim((string)($p[$obligatorio] ?? '')) === '') {
            return ['status' => 'error', 'message' => $tipo === 'entrega'
                ? 'Indica a quién se le entregó el dinero.'
                : 'El concepto es obligatorio.'];
        }
        if ($this->periodoCerrado($periodoId)) {
            return ['status' => 'error', 'message' => 'El periodo está cerrado. Ábrelo para poder capturar en él.'];
        }

        $cols = ['periodo_id', 'fecha', 'monto'];
        $vals = [$periodoId, $fecha, $monto];
        foreach ($def['campos'] as $c) {
            $cols[] = $c;
            $vals[] = trim((string)($p[$c] ?? '')) ?: null;
        }
        if ($tipo === 'gasto') {
            $cols[] = 'fecha_reembolso';
            $vals[] = $this->fecha($p['fecha_reembolso'] ?? null);
        }

        if ($id) {
            $sets = implode(', ', array_map(fn($c) => "`$c` = ?", $cols));
            $vals[] = $id;
            $this->pdo->prepare("UPDATE `{$def['tabla']}` SET $sets WHERE id = ?")->execute($vals);
        } else {
            $ph = implode(',', array_fill(0, count($cols), '?'));
            $this->pdo->prepare(
                "INSERT INTO `{$def['tabla']}` (" . implode(',', array_map(fn($c) => "`$c`", $cols)) . ") VALUES ($ph)"
            )->execute($vals);
            $id = (int)$this->pdo->lastInsertId();
        }
        return ['status' => 'success', 'id' => $id, 'message' => $def['label'] . ' guardado.'];
    }

    public function eliminarMovimiento(string $tipo, int $id): array {
        if (!isset(self::MOVIMIENTOS[$tipo])) return ['status' => 'error', 'message' => 'Tipo no válido.'];
        $tabla = self::MOVIMIENTOS[$tipo]['tabla'];
        $st = $this->pdo->prepare("SELECT periodo_id FROM `$tabla` WHERE id = ?");
        $st->execute([$id]);
        $per = $st->fetchColumn();
        if ($per === false) return ['status' => 'error', 'message' => 'Registro no encontrado.'];
        if ($this->periodoCerrado((int)$per)) {
            return ['status' => 'error', 'message' => 'El periodo está cerrado.'];
        }
        $this->pdo->prepare("DELETE FROM `$tabla` WHERE id = ?")->execute([$id]);
        return ['status' => 'success', 'message' => 'Registro eliminado.'];
    }

    private function periodoCerrado(int $id): bool {
        $st = $this->pdo->prepare("SELECT cerrado FROM conta_periodos WHERE id = ?");
        $st->execute([$id]);
        return (bool)$st->fetchColumn();
    }

    /** Normaliza una fecha a Y-m-d, o null si no es válida. */
    private function fecha($v): ?string {
        $v = trim((string)$v);
        if ($v === '') return null;
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v)) return $v;
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $v, $m)) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        $t = strtotime($v);
        return $t ? date('Y-m-d', $t) : null;
    }

    /** Convierte a número aceptando "$1,234.56"; null si no es un número. */
    private function monto($v): ?float {
        if (is_int($v) || is_float($v)) return round((float)$v, 2);
        $v = trim((string)$v);
        if ($v === '') return null;
        $v = str_replace([',', '$', ' '], '', $v);
        return is_numeric($v) ? round((float)$v, 2) : null;
    }

    // ════════════════════════════════════════════════════════
    //  REEMBOLSOS, SALDOS Y BALANCE
    // ════════════════════════════════════════════════════════

    /**
     * Estado de reembolso de cada gasto pagado por el personal.
     *
     * Mismo criterio que traía el Excel: los gastos de una persona se saldan en
     * el orden en que ocurrieron, hasta agotar lo que se le entregó. Aquí se
     * calcula de una pasada en vez de encadenar columnas auxiliares, así que
     * ordenar o insertar un movimiento no puede descuadrarlo.
     *
     * Sólo entran los gastos cuyo pagador está en el catálogo de personal: un
     * gasto pagado por la empresa no es un reembolso pendiente de nadie.
     */
    public function reembolsos(int $periodoId): array {
        $personal = $this->personalActivo();
        if (!$personal) {
            return ['status' => 'success', 'data' => [], 'totales' => $this->totalesVacios(), 'por_persona' => []];
        }

        $entregado = $this->entregadoPorPersona($periodoId);

        $ph = implode(',', array_fill(0, count($personal), '?'));
        $st = $this->pdo->prepare(
            "SELECT id, fecha, concepto, pagador, monto, comprobante, fecha_reembolso, nota_reembolso
             FROM conta_gastos
             WHERE periodo_id = ? AND pagador IN ($ph)
             ORDER BY fecha, id"
        );
        $st->execute([$periodoId, ...$personal]);

        $acumulado = [];
        $filas     = [];
        foreach ($st->fetchAll() as $g) {
            $persona = (string)$g['pagador'];
            $monto   = (float)$g['monto'];
            $previo  = $acumulado[$persona] ?? 0.0;
            $acum    = $previo + $monto;
            $acumulado[$persona] = $acum;

            $cubierto = $entregado[$persona] ?? 0.0;
            // Se compara el acumulado, no el gasto suelto: un gasto queda
            // saldado sólo si todo lo anterior de esa persona también lo está.
            $estado = ($acum <= $cubierto + 0.005) ? 'Reembolsado' : 'Pendiente';

            $filas[] = [
                'id'              => (int)$g['id'],
                'fecha'           => $g['fecha'],
                'concepto'        => $g['concepto'],
                'persona'         => $persona,
                'monto'           => round($monto, 2),
                'comprobante'     => $g['comprobante'],
                'estado'          => $estado,
                'acumulado'       => round($acum, 2),
                'entregado'       => round($cubierto, 2),
                'fecha_reembolso' => $g['fecha_reembolso'],
                'nota_reembolso'  => $g['nota_reembolso'],
            ];
        }

        $tot = ['pagado' => 0.0, 'pendiente' => 0.0, 'reembolsado' => 0.0];
        $porPersona = [];
        foreach ($filas as $f) {
            $p = $f['persona'];
            $porPersona[$p] ??= ['persona' => $p, 'pagado' => 0.0, 'pendiente' => 0.0, 'reembolsado' => 0.0];
            $tot['pagado'] += $f['monto'];
            $porPersona[$p]['pagado'] += $f['monto'];
            $k = $f['estado'] === 'Reembolsado' ? 'reembolsado' : 'pendiente';
            $tot[$k] += $f['monto'];
            $porPersona[$p][$k] += $f['monto'];
        }

        return [
            'status'      => 'success',
            'data'        => $filas,
            'totales'     => array_map(fn($v) => round($v, 2), $tot),
            'por_persona' => array_values(array_map(
                fn($r) => array_map(fn($v) => is_float($v) ? round($v, 2) : $v, $r),
                $porPersona
            )),
        ];
    }

    /**
     * Saldo de cada persona: lo entregado contra lo gastado.
     *
     * Saldo positivo significa que le sobra dinero de la empresa y debe
     * regresarlo; negativo, que puso de su bolsa y hay que reembolsarle.
     */
    public function saldos(int $periodoId): array {
        $personal  = $this->personalActivo();
        $entregado = $this->entregadoPorPersona($periodoId);
        $gastado   = $this->gastadoPorPersona($periodoId);
        $reemb     = $this->reembolsos($periodoId);

        $pend = $reem = [];
        foreach ($reemb['por_persona'] as $r) {
            $pend[$r['persona']] = $r['pendiente'];
            $reem[$r['persona']] = $r['reembolsado'];
        }

        // Se listan también las personas que ya no están en el catálogo pero
        // tienen movimientos: si no, su saldo desaparecería del reporte.
        $todas = array_values(array_unique(array_merge(
            $personal, array_keys($entregado), array_keys($gastado)
        )));
        sort($todas, SORT_NATURAL | SORT_FLAG_CASE);

        $filas = [];
        $tot   = ['entregado' => 0.0, 'gastado' => 0.0, 'neto' => 0.0,
                  'regresar' => 0.0, 'reembolsar' => 0.0, 'pendiente' => 0.0, 'reembolsado' => 0.0];

        foreach ($todas as $p) {
            $e = round($entregado[$p] ?? 0.0, 2);
            $g = round($gastado[$p]   ?? 0.0, 2);
            $n = round($e - $g, 2);
            $fila = [
                'persona'     => $p,
                'en_catalogo' => in_array($p, $personal, true),
                'entregado'   => $e,
                'gastado'     => $g,
                'neto'        => $n,
                'regresar'    => max($n, 0.0),
                'reembolsar'  => max(-$n, 0.0),
                'pendiente'   => round($pend[$p] ?? 0.0, 2),
                'reembolsado' => round($reem[$p] ?? 0.0, 2),
            ];
            foreach ($tot as $k => $_) $tot[$k] += $fila[$k];
            $filas[] = $fila;
        }

        return ['status' => 'success', 'data' => $filas,
                'totales' => array_map(fn($v) => round($v, 2), $tot)];
    }

    /** Balance del periodo: ingresos, gastos, utilidad y margen. */
    public function balance(int $periodoId): array {
        $ing = $this->sumaPeriodo('conta_ingresos', $periodoId);
        $gas = $this->sumaPeriodo('conta_gastos',   $periodoId);
        $ent = $this->sumaPeriodo('conta_entregas', $periodoId);
        $utilidad = round($ing - $gas, 2);

        $per = $this->pdo->prepare("SELECT nombre, fecha_ini, fecha_fin, cerrado FROM conta_periodos WHERE id = ?");
        $per->execute([$periodoId]);

        $reemb = $this->reembolsos($periodoId);

        return [
            'status'   => 'success',
            'periodo'  => $per->fetch() ?: null,
            'ingresos' => round($ing, 2),
            'gastos'   => round($gas, 2),
            'entregado'=> round($ent, 2),
            'utilidad' => $utilidad,
            // Sin ingresos el margen no está definido: se devuelve null y la
            // interfaz muestra un guion, en vez de un 0% que se leería como
            // "no hubo utilidad".
            'margen'   => $ing > 0 ? round($utilidad / $ing, 4) : null,
            'por_reembolsar' => $reemb['totales']['pendiente'],
            'por_categoria'  => $this->agrupado('conta_ingresos', 'categoria', $periodoId),
            'por_pagador'    => $this->agrupado('conta_gastos',   'pagador',   $periodoId),
            'por_mes'        => $this->porMes($periodoId),
        ];
    }

    private function totalesVacios(): array {
        return ['pagado' => 0.0, 'pendiente' => 0.0, 'reembolsado' => 0.0];
    }

    private function personalActivo(): array {
        $st = $this->pdo->prepare("SELECT valor FROM conta_catalogo WHERE tipo='personal' AND activo=1 ORDER BY orden, valor");
        $st->execute();
        return $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    private function entregadoPorPersona(int $periodoId): array {
        $st = $this->pdo->prepare(
            "SELECT persona, SUM(monto) AS t FROM conta_entregas WHERE periodo_id = ? GROUP BY persona"
        );
        $st->execute([$periodoId]);
        $out = [];
        foreach ($st->fetchAll() as $r) $out[(string)$r['persona']] = (float)$r['t'];
        return $out;
    }

    private function gastadoPorPersona(int $periodoId): array {
        $st = $this->pdo->prepare(
            "SELECT pagador, SUM(monto) AS t FROM conta_gastos WHERE periodo_id = ? AND pagador IS NOT NULL AND pagador <> '' GROUP BY pagador"
        );
        $st->execute([$periodoId]);
        $out = [];
        foreach ($st->fetchAll() as $r) $out[(string)$r['pagador']] = (float)$r['t'];
        return $out;
    }

    private function sumaPeriodo(string $tabla, int $periodoId): float {
        try {
            $st = $this->pdo->prepare("SELECT COALESCE(SUM(monto),0) FROM `$tabla` WHERE periodo_id = ?");
            $st->execute([$periodoId]);
            return (float)$st->fetchColumn();
        } catch (\Throwable $e) { return 0.0; }
    }

    private function agrupado(string $tabla, string $col, int $periodoId): array {
        try {
            $st = $this->pdo->prepare(
                "SELECT COALESCE(NULLIF(TRIM(`$col`),''),'Sin clasificar') AS etiqueta,
                        SUM(monto) AS total, COUNT(*) AS n
                 FROM `$tabla` WHERE periodo_id = ?
                 GROUP BY etiqueta ORDER BY total DESC"
            );
            $st->execute([$periodoId]);
            return array_map(
                fn($r) => ['etiqueta' => $r['etiqueta'], 'total' => round((float)$r['total'], 2), 'n' => (int)$r['n']],
                $st->fetchAll()
            );
        } catch (\Throwable $e) { return []; }
    }

    /** Ingresos y gastos mes a mes, para ver la evolución del periodo. */
    private function porMes(int $periodoId): array {
        $meses = [];
        foreach ([['conta_ingresos', 'ingresos'], ['conta_gastos', 'gastos']] as [$tabla, $clave]) {
            try {
                $st = $this->pdo->prepare(
                    "SELECT DATE_FORMAT(fecha, '%Y-%m') AS mes, SUM(monto) AS total
                     FROM `$tabla` WHERE periodo_id = ? GROUP BY mes ORDER BY mes"
                );
                $st->execute([$periodoId]);
                foreach ($st->fetchAll() as $r) {
                    $m = (string)$r['mes'];
                    $meses[$m] ??= ['mes' => $m, 'ingresos' => 0.0, 'gastos' => 0.0];
                    $meses[$m][$clave] = round((float)$r['total'], 2);
                }
            } catch (\Throwable $e) {}
        }
        ksort($meses);
        foreach ($meses as &$m) $m['utilidad'] = round($m['ingresos'] - $m['gastos'], 2);
        unset($m);
        return array_values($meses);
    }
}
