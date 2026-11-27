<?php
/**
 * Días omitidos: lo que se registró en pruebas no se mezcla con lo real.
 *
 * No se borra nada. Un periodo omitido (un día completo o unas horas, por la hora en que se
 * leyó la credencial) se deja fuera de todo lo que se cuenta: el tablero y sus descargas, los
 * registros por turno y su PDF, «En planta», los movimientos por ID, las incidencias y la lista
 * de IDs leídos que no están en la base. Se puede volver a contar (queda el historial).
 *
 * Lo que NO se filtra: ver si un registro ya llegó (por su uid, para la cola sin señal) y abrir
 * un registro por su número (fotos).
 *
 * Sólo Administración AVBA (acciones OMITIDOS_LISTAR, OMITIR, OMITIDO_QUITAR).
 */
final class Omitidos {
    private PDO $pdo;
    private static array $rangos = [];          // periodos vigentes por conexión (se leen una vez por petición)
    private const DIAS_LISTA = 60;              // días con registros que se muestran para elegir

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public static function asegurar(PDO $pdo): void {
        static $hecho = [];
        $k = spl_object_id($pdo);
        if (isset($hecho[$k])) return;
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS accesos_omitidos (
               id             INT AUTO_INCREMENT PRIMARY KEY,
               desde          DATETIME     NOT NULL,
               hasta          DATETIME     NOT NULL,
               motivo         VARCHAR(150) NOT NULL DEFAULT '',
               usuario        VARCHAR(50)  NOT NULL DEFAULT '',
               usuario_nombre VARCHAR(150) NOT NULL DEFAULT '',
               creado         DATETIME     NOT NULL,
               activo         TINYINT(1)   NOT NULL DEFAULT 1,
               quitado_por    VARCHAR(150) NOT NULL DEFAULT '',
               quitado        DATETIME     NULL,
               KEY idx_activo (activo, desde)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $hecho[$k] = true;
    }

    /** Los periodos omitidos vigentes: [[desde, hasta], …]. Si algo falla, ninguno (lo demás sigue funcionando). */
    public static function rangos(PDO $pdo): array {
        $k = spl_object_id($pdo);
        if (!isset(self::$rangos[$k])) {
            try {
                self::asegurar($pdo);
                self::$rangos[$k] = $pdo->query("SELECT desde, hasta FROM accesos_omitidos WHERE activo = 1 ORDER BY desde")->fetchAll(PDO::FETCH_NUM);
            } catch (\Throwable $e) {
                error_log('[omitidos] ' . $e->getMessage());
                self::$rangos[$k] = [];
            }
        }
        return self::$rangos[$k];
    }

    /**
     * Condición SQL «no cae en un periodo omitido» para la columna con la hora de la lectura
     * (p. ej. «capturado» o «a.capturado»). Sin periodos omitidos: «1=1».
     */
    public static function fuera(PDO $pdo, string $col = 'capturado'): string {
        $r = self::rangos($pdo);
        return $r ? 'NOT (' . self::dentro($pdo, $col, $r) . ')' : '1=1';
    }
    private static function dentro(PDO $pdo, string $col, array $r): string {
        if (!$r) return '0';
        return '(' . implode(' OR ', array_map(fn($x) => "$col BETWEEN " . $pdo->quote((string)$x[0]) . ' AND ' . $pdo->quote((string)$x[1]), $r)) . ')';
    }

    private static function soloAdmin(array $usr): ?array {
        return ($usr['rol'] ?? '') === 'ADMIN' ? null
            : ['status' => 'error', 'message' => 'Sólo Administración AVBA omite días.'];
    }

    /** Lo que hay en un periodo: entradas, salidas, incidencias, personas y con qué cuentas se registró. */
    private function contar(string $desde, string $hasta): array {
        ControlAccesos::asegurarTabla($this->pdo);
        ControlAccesos::asegurarIncidencias($this->pdo);
        $dia = [substr($desde, 0, 10), substr($hasta, 0, 10)];
        $cod = ControlAccesos::conTarjeta($this->pdo) ? "COUNT(DISTINCT NULLIF(TRIM(LEADING '0' FROM codigo), ''))" : '0';
        $st = $this->pdo->prepare(
            "SELECT SUM(tipo = 'entrada') AS e, SUM(tipo = 'salida') AS s, $cod AS p,
                    GROUP_CONCAT(DISTINCT NULLIF(usuario_nombre, '') ORDER BY usuario_nombre SEPARATOR ', ') AS cuentas
               FROM accesos_registros WHERE fecha BETWEEN ? AND ? AND capturado BETWEEN ? AND ?");
        $st->execute([$dia[0], $dia[1], $desde, $hasta]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM accesos_incidencias WHERE fecha BETWEEN ? AND ? AND capturado BETWEEN ? AND ?");
        $st->execute([$dia[0], $dia[1], $desde, $hasta]);
        return ['entradas' => (int)($r['e'] ?? 0), 'salidas' => (int)($r['s'] ?? 0), 'personas' => (int)($r['p'] ?? 0),
                'incidencias' => (int)$st->fetchColumn(), 'cuentas' => (string)($r['cuentas'] ?? '')];
    }

    /** Los días con registros (para elegir cuáles fueron de prueba), lo omitido vigente y lo que se volvió a contar. */
    public function listar(array $usr): array {
        if ($e = self::soloAdmin($usr)) return $e;
        self::asegurar($this->pdo);
        ControlAccesos::asegurarTabla($this->pdo);
        ControlAccesos::asegurarIncidencias($this->pdo);
        $r = self::rangos($this->pdo);
        $desde = date('Y-m-d', strtotime('-' . self::DIAS_LISTA . ' days'));
        $om = self::dentro($this->pdo, 'capturado', $r);
        $st = $this->pdo->prepare(
            "SELECT fecha, SUM(tipo = 'entrada') AS e, SUM(tipo = 'salida') AS s, SUM($om) AS om,
                    MIN(capturado) AS primero, MAX(capturado) AS ultimo,
                    GROUP_CONCAT(DISTINCT NULLIF(usuario_nombre, '') ORDER BY usuario_nombre SEPARATOR ', ') AS cuentas
               FROM accesos_registros WHERE fecha >= ? GROUP BY fecha ORDER BY fecha DESC");
        $st->execute([$desde]);
        $dias = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $dias[$f['fecha']] = ['fecha' => $f['fecha'], 'entradas' => (int)$f['e'], 'salidas' => (int)$f['s'], 'incidencias' => 0,
                                  'omitidos' => (int)$f['om'], 'inc_omitidas' => 0, 'primero' => substr((string)$f['primero'], 11, 5),
                                  'ultimo' => substr((string)$f['ultimo'], 11, 5), 'cuentas' => (string)$f['cuentas']];
        }
        $st = $this->pdo->prepare("SELECT fecha, COUNT(*) AS n, SUM($om) AS om FROM accesos_incidencias WHERE fecha >= ? GROUP BY fecha");
        $st->execute([$desde]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
            if (!isset($dias[$f['fecha']])) $dias[$f['fecha']] = ['fecha' => $f['fecha'], 'entradas' => 0, 'salidas' => 0, 'omitidos' => 0,
                                                                  'primero' => '', 'ultimo' => '', 'cuentas' => ''];
            $dias[$f['fecha']]['incidencias'] = (int)$f['n'];
            $dias[$f['fecha']]['inc_omitidas'] = (int)$f['om'];
        }
        krsort($dias);

        $omitidos = [];
        foreach ($this->pdo->query("SELECT * FROM accesos_omitidos WHERE activo = 1 ORDER BY desde DESC")->fetchAll(PDO::FETCH_ASSOC) as $o)
            $omitidos[] = self::fila($o) + $this->contar((string)$o['desde'], (string)$o['hasta']);
        $historial = array_map([self::class, 'fila'],
            $this->pdo->query("SELECT * FROM accesos_omitidos WHERE activo = 0 ORDER BY quitado DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC));
        return ['status' => 'success', 'dias' => array_values($dias), 'omitidos' => $omitidos, 'historial' => $historial,
                'hoy' => date('Y-m-d'), 'ahora' => date('H:i')];
    }

    private static function fila(array $o): array {
        return ['id' => (int)$o['id'], 'desde' => (string)$o['desde'], 'hasta' => (string)$o['hasta'],
                'dia_completo' => substr((string)$o['desde'], 11) === '00:00:00' && substr((string)$o['hasta'], 11) === '23:59:59'
                                  && substr((string)$o['desde'], 0, 10) === substr((string)$o['hasta'], 0, 10),
                'motivo' => (string)$o['motivo'], 'por' => (string)$o['usuario_nombre'], 'creado' => (string)$o['creado'],
                'quitado_por' => (string)$o['quitado_por'], 'quitado' => (string)($o['quitado'] ?? '')];
    }

    /**
     * Omitir un día (o unas horas de ese día). Con «aplicar» falso sólo dice cuánto se dejaría de
     * contar. Hoy se omite hasta ahora: lo que se registre después sí cuenta. Días que no han
     * pasado no se omiten.
     */
    public function omitir(array $p, array $usr): array {
        if ($e = self::soloAdmin($usr)) return $e;
        self::asegurar($this->pdo);
        $fecha = (string)($p['fecha'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || !checkdate((int)substr($fecha, 5, 2), (int)substr($fecha, 8, 2), (int)substr($fecha, 0, 4)))
            return ['status' => 'error', 'message' => 'Elige el día.'];
        $hoy = date('Y-m-d');
        if ($fecha > $hoy) return ['status' => 'error', 'message' => 'Ese día todavía no pasa: sólo se omiten días con registros.'];
        $hd = (string)($p['desde'] ?? '');
        $hh = (string)($p['hasta'] ?? '');
        $hora = fn($h) => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $h);
        if (($hd !== '' && !$hora($hd)) || ($hh !== '' && !$hora($hh))) return ['status' => 'error', 'message' => 'Revisa las horas (por ejemplo 08:00).'];
        $desde = $fecha . ' ' . ($hd !== '' ? $hd . ':00' : '00:00:00');
        $hasta = $fecha . ' ' . ($hh !== '' ? $hh . ':59' : '23:59:59');
        $hastaAhora = false;
        if ($fecha === $hoy && $hasta > date('Y-m-d H:i:s')) { $hasta = date('Y-m-d H:i:s'); $hastaAhora = true; }   // sólo lo que ya pasó
        if ($desde >= $hasta) return ['status' => 'error', 'message' => 'La hora de inicio debe ser antes que la de fin.'];
        foreach (self::rangos($this->pdo) as [$a, $b])
            if ($a <= $desde && $b >= $hasta) return ['status' => 'error', 'message' => 'Ese periodo ya está omitido.'];

        $cuenta = $this->contar($desde, $hasta);
        $base = ['desde' => $desde, 'hasta' => $hasta, 'hasta_ahora' => $hastaAhora] + $cuenta;
        if ($cuenta['entradas'] + $cuenta['salidas'] + $cuenta['incidencias'] === 0)
            return ['status' => 'error', 'message' => 'En ese periodo no hay registros: no hay nada que omitir.'] + $base;
        if (empty($p['aplicar'])) return ['status' => 'success', 'revision' => true] + $base;

        $motivo = trim((string)preg_replace('/\s+/u', ' ', (string)($p['motivo'] ?? '')));
        $motivo = mb_substr($motivo !== '' ? $motivo : 'Pruebas', 0, 150);
        $this->pdo->prepare("INSERT INTO accesos_omitidos (desde, hasta, motivo, usuario, usuario_nombre, creado) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$desde, $hasta, $motivo, (string)$usr['usuario'], (string)($usr['nombre'] ?? ''), date('Y-m-d H:i:s')]);
        unset(self::$rangos[spl_object_id($this->pdo)]);
        $n = $cuenta['entradas'] + $cuenta['salidas'];
        return ['status' => 'success', 'id' => (int)$this->pdo->lastInsertId(),
                'message' => 'Listo: ' . $n . ' registro' . ($n === 1 ? '' : 's')
                    . ($cuenta['incidencias'] ? ' y ' . $cuenta['incidencias'] . ' incidencia' . ($cuenta['incidencias'] === 1 ? '' : 's') : '')
                    . ' ya no cuenta' . ($n + $cuenta['incidencias'] === 1 ? '' : 'n') . ' en el tablero, los turnos, el PDF ni En planta.'] + $base;
    }

    /** Volver a contar un periodo omitido (queda en el historial). */
    public function quitar(array $p, array $usr): array {
        if ($e = self::soloAdmin($usr)) return $e;
        self::asegurar($this->pdo);
        $st = $this->pdo->prepare("UPDATE accesos_omitidos SET activo = 0, quitado_por = ?, quitado = ? WHERE id = ? AND activo = 1");
        $st->execute([(string)($usr['nombre'] ?? $usr['usuario']), date('Y-m-d H:i:s'), (int)($p['id'] ?? 0)]);
        if (!$st->rowCount()) return ['status' => 'error', 'message' => 'Ese periodo ya no está omitido.'];
        unset(self::$rangos[spl_object_id($this->pdo)]);
        return ['status' => 'success', 'message' => 'Listo: esos registros vuelven a contar.'];
    }
}
