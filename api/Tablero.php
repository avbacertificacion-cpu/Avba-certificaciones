<?php
/* ══════════════════════════════════════════════════════════════
   AVBA Control de Accesos — Tablero (perfil del cliente)

   Lo que pasó en la planta en un periodo (desde / hasta, hasta 3 meses):
   cuántos entraron y salieron, cuántas personas distintas, cuánta gente
   hubo dentro a lo largo del tiempo, cuánto tiempo se quedan, quiénes
   acumulan más horas, las incidencias y quién está dentro ahora.

     TABLERO        los datos para las gráficas de tablero.html
     TABLERO_EXCEL  el mismo periodo en un Excel (.xlsx) con todo el detalle
     TABLERO_PDF    un reporte en PDF con las gráficas (las manda la pantalla)

   Lo ven el cliente (perfil CLIENTE, sólo esto), el administrador de la
   planta y Administración AVBA. Los archivos se guardan en
   uploads/accesos/reportes/ (protegida) y se bajan con descargar.php.

   Cómo se cuenta:
     · Entradas y salidas: cada lectura del periodo (también las de sólo foto).
     · Personas: IDs distintos (sin ceros a la izquierda: 00287 = 287).
     · Visitas: la entrada de una persona junta con su salida siguiente
       (ControlAccesos::emparejar). Una visita de más de 24 h no cuenta en
       los tiempos (es una salida que no se registró).
     · Personas dentro: cada entrada cuenta hasta su salida; si no hay
       salida, hasta ahora si entró en las últimas 48 h (como «En planta»),
       o 16 h si después volvió a entrar o es de antes.
   ══════════════════════════════════════════════════════════════ */

require_once __DIR__ . '/lib/xlsx.php';

class Tablero {
    private PDO $pdo;

    /** Periodo más largo que se puede pedir (unos 3 meses). */
    public const DIAS_MAX = 93;
    /** Una visita más larga que esto no cuenta en los tiempos (salida no registrada). */
    public const MAX_MIN_VISITA = 24 * 60;
    /** Rangos del tiempo dentro por visita, en minutos (los turnos de la planta son de 12 h). */
    public const PERMANENCIA = [
        [0, 480, 'Menos de 8 h'], [480, 660, '8 a 11 h'], [660, 780, '11 a 13 h'],
        [780, 900, '13 a 15 h'], [900, self::MAX_MIN_VISITA, 'Más de 15 h'],
    ];
    /** Hasta cuántos días se muestra la gente dentro hora por hora (más: el máximo de cada día). */
    public const DIAS_POR_HORA = 7;
    /** Movimientos que caben en el PDF; con más, el detalle va sólo en el Excel. */
    public const MOVS_PDF = 400;
    private const DIAS_CORTOS = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /** Cliente, administrador de la planta y Administración AVBA (el guardia no). */
    public static function puede(?array $usr): bool {
        return in_array((string)($usr['rol'] ?? ''), ['ADMIN', 'PLANTA', 'CLIENTE'], true);
    }

    /**
     * El periodo pedido: desde y hasta (YYYY-MM-DD), por omisión los últimos 7 días.
     * @return array{0:string,1:string}|string  el periodo o el mensaje de error
     */
    public static function periodo(array $p) {
        $hoy = date('Y-m-d');
        $valida = function ($f): bool {
            return is_string($f) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $f, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
        };
        $hasta = $valida($p['hasta'] ?? null) ? (string)$p['hasta'] : $hoy;
        $desde = $valida($p['desde'] ?? null) ? (string)$p['desde'] : self::masDias($hasta, -6);
        if ($desde > $hasta) [$desde, $hasta] = [$hasta, $desde];
        if ($hasta > $hoy) $hasta = $hoy;
        if ($desde > $hasta) $desde = $hasta;
        if (self::diasEntre($desde, $hasta) + 1 > self::DIAS_MAX) {
            return 'El periodo puede ser de hasta ' . self::DIAS_MAX . ' días (unos 3 meses). Para más tiempo, descarga un Excel por cada periodo.';
        }
        return [$desde, $hasta];
    }
    private static function masDias(string $f, int $n): string { return date('Y-m-d', strtotime($f . ' 12:00:00 ' . ($n >= 0 ? '+' : '') . $n . ' days')); }
    private static function diasEntre(string $a, string $b): int { return (int)round((strtotime($b . ' 12:00:00') - strtotime($a . ' 12:00:00')) / 86400); }

    /* ── Cálculo ─────────────────────────────────────────────────────────── */

    /** Todo lo del periodo, con el detalle (movimientos y personas) para el Excel y el PDF. */
    private function calcular(string $desde, string $hasta, array $usr): array {
        ControlAccesos::asegurarTabla($this->pdo);
        ControlAccesos::asegurarIncidencias($this->pdo);
        $ahora = time();
        $tIni = strtotime($desde . ' 00:00:00');
        $tFin = strtotime($hasta . ' 23:59:59');
        $ini = date('Y-m-d H:i:s', $tIni);
        $fin = date('Y-m-d H:i:s', $tFin);
        // Con margen a los lados, para juntar entradas y salidas que cruzan el principio o el fin
        $margen = ControlAccesos::HORAS_JORNADA_MAX * 3600;
        $a = date('Y-m-d H:i:s', $tIni - $margen);
        $b = date('Y-m-d H:i:s', $tFin + $margen);
        $cols = ControlAccesos::conTarjeta($this->pdo) ? 'nombre, codigo, origen' : "'' AS nombre, '' AS codigo, 'foto' AS origen";
        $st = $this->pdo->prepare(
            "SELECT id, tipo, capturado, turno, lugar, $cols, (foto <> '') AS con_foto
               FROM accesos_registros
              WHERE fecha BETWEEN ? AND ? AND capturado BETWEEN ? AND ? AND " . Omitidos::fuera($this->pdo) . "
              ORDER BY capturado, id");
        $st->execute([substr($a, 0, 10), substr($b, 0, 10), $a, $b]);
        $filas = $st->fetchAll(PDO::FETCH_ASSOC);

        // Por día y por hora
        $dias = [];
        for ($d = $desde; $d <= $hasta; $d = self::masDias($d, 1)) {
            $dias[$d] = ['fecha' => $d, 'entradas' => 0, 'salidas' => 0, 'personas' => 0, 'ent_dia' => 0, 'ent_noche' => 0,
                         'max_dentro' => 0, 'incidencias' => 0];
        }
        $idsDia = [];
        $porHora = array_fill(0, 24, ['entradas' => 0, 'salidas' => 0]);
        $k = ['entradas' => 0, 'salidas' => 0, 'ent_dia' => 0, 'ent_noche' => 0, 'sin_id' => 0];
        $ids = [];
        foreach ($filas as $f) {
            $cap = (string)$f['capturado'];
            if ($cap < $ini || $cap > $fin) continue;
            $d = substr($cap, 0, 10);
            $tipo = $f['tipo'] === 'salida' ? 'salidas' : 'entradas';
            $k[$tipo]++;
            $dias[$d][$tipo]++;
            $porHora[(int)substr($cap, 11, 2)][$tipo]++;
            if ($tipo === 'entradas' && ($f['turno'] === 'D' || $f['turno'] === 'N')) {
                $t = $f['turno'] === 'D' ? 'ent_dia' : 'ent_noche';
                $k[$t]++;
                $dias[$d][$t]++;
            }
            if ((string)$f['codigo'] === '') { $k['sin_id']++; continue; }
            $id = ControlAccesos::claveId((string)$f['codigo']);
            $ids[$id] = true;
            $idsDia[$d][$id] = true;
        }
        foreach ($idsDia as $d => $s) $dias[$d]['personas'] = count($s);

        // Quién está dentro ahora (lo mismo que «En planta»)
        $enPlanta = (new ControlAccesos($this->pdo))->enPlanta([], $usr);
        $dentro = array_map(fn($x) => [
            'id' => $x['id'], 'nombre' => $x['nombre'], 'entrada' => $x['entrada'],
            'minutos' => $x['minutos'], 'revisar' => $x['revisar'],
        ], $enPlanta['personas'] ?? []);
        $idsDentro = [];
        foreach ($dentro as $x) $idsDentro[ControlAccesos::claveId((string)$x['id'])] = true;

        // Visitas: la entrada de cada persona con su salida
        $movs = [];
        $personas = [];
        $perm = array_fill(0, count(self::PERMANENCIA), 0);
        $minTotal = 0; $visitas = 0; $sinSalida = 0; $largas = 0;
        foreach (ControlAccesos::emparejar($filas) as $m) {
            $ref = $m['entrada'] !== '' ? $m['entrada'] : $m['salida'];
            if ($ref < $ini || $ref > $fin) continue;
            $clave = ControlAccesos::claveId((string)$m['codigo']);
            $completa = $m['entrada'] !== '' && $m['salida'] !== '' && $m['minutos'] !== null && $m['minutos'] >= 0;
            $valida = $completa && $m['minutos'] <= self::MAX_MIN_VISITA;
            if ($completa && !$valida) $largas++;
            $obs = '';
            if ($m['entrada'] === '') $obs = 'Sin entrada registrada';
            elseif ($m['salida'] === '') $obs = isset($idsDentro[$clave]) && $this->esUltimaEntrada($filas, $clave, $m['entrada']) ? 'Dentro de la planta' : 'Sin salida registrada';
            elseif (!$valida) $obs = 'Más de 24 h: revisar la salida';
            if ($obs === 'Sin salida registrada') $sinSalida++;            // quien sigue dentro no cuenta
            $movs[] = ['id' => $m['codigo'], 'nombre' => $m['nombre'], 'entrada' => $m['entrada'], 'salida' => $m['salida'],
                       'minutos' => $completa ? (int)$m['minutos'] : null, 'observacion' => $obs];
            if (!isset($personas[$clave])) {
                $personas[$clave] = ['id' => $m['codigo'], 'nombre' => $m['nombre'], 'visitas' => 0, 'dias' => [],
                                     'minutos' => 0, 'completas' => 0, 'ultima' => ''];
            }
            $p = &$personas[$clave];
            if ($m['entrada'] !== '') { $p['visitas']++; $p['dias'][substr($m['entrada'], 0, 10)] = true; }
            $p['ultima'] = max($p['ultima'], $m['salida'] !== '' ? $m['salida'] : $m['entrada']);
            if ($valida) {
                $p['minutos'] += (int)$m['minutos']; $p['completas']++;
                $minTotal += (int)$m['minutos']; $visitas++;
                foreach (self::PERMANENCIA as $i => [$de, $a2]) {
                    if ($m['minutos'] >= $de && ($m['minutos'] < $a2 || $i === count(self::PERMANENCIA) - 1)) { $perm[$i]++; break; }
                }
            }
            unset($p);
        }
        foreach ($personas as &$p) { $p['dias'] = count($p['dias']); }
        unset($p);
        $personas = array_values($personas);
        usort($personas, fn($x, $y) => [$y['minutos'], $y['visitas'], $x['nombre']] <=> [$x['minutos'], $x['visitas'], $y['nombre']]);

        // Gente dentro a lo largo del periodo
        $tHasta = min($tFin + 1, $ahora);
        $intervalos = $this->intervalos($filas, $ahora);
        $limitesDia = [];
        foreach (array_keys($dias) as $d) $limitesDia[] = strtotime($d . ' 00:00:00');
        $maxDia = self::maximos($intervalos, $limitesDia, $tHasta);
        $i = 0;
        foreach (array_keys($dias) as $d) { $dias[$d]['max_dentro'] = $maxDia[$i] ?? 0; $i++; }
        $nDias = count($dias);
        if ($nDias <= self::DIAS_POR_HORA) {
            $limitesHora = [];
            for ($t = $tIni; $t < $tHasta; $t += 3600) $limitesHora[] = $t;
            $max = self::maximos($intervalos, $limitesHora, $tHasta);
            $ocupacion = ['nivel' => 'hora', 'puntos' => array_map(fn($t, $n) => ['t' => date('Y-m-d H:00', $t), 'n' => $n], $limitesHora, $max)];
        } else {
            $ocupacion = ['nivel' => 'dia', 'puntos' => array_values(array_map(fn($x) => ['t' => $x['fecha'], 'n' => $x['max_dentro']],
                array_filter($dias, fn($x) => strtotime($x['fecha'] . ' 00:00:00') < $tHasta)))];
        }

        // Incidencias del periodo
        $st = $this->pdo->prepare(
            "SELECT id, tipo, detalle, capturado, codigo, nombre, usuario_nombre
               FROM accesos_incidencias
              WHERE fecha BETWEEN ? AND ? AND capturado BETWEEN ? AND ? AND " . Omitidos::fuera($this->pdo) . "
              ORDER BY capturado DESC, id DESC");
        $st->execute([$desde, $hasta, $ini, $fin]);
        $incidencias = [];
        $porTipo = array_fill_keys(array_keys(ControlAccesos::INCIDENCIAS), 0);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $tipo = isset(ControlAccesos::INCIDENCIAS[$f['tipo']]) ? (string)$f['tipo'] : 'OTRO';
            $porTipo[$tipo]++;
            $d = substr((string)$f['capturado'], 0, 10);
            if (isset($dias[$d])) $dias[$d]['incidencias']++;
            $incidencias[] = ['capturado' => (string)$f['capturado'], 'tipo' => $tipo, 'nombre_tipo' => ControlAccesos::INCIDENCIAS[$tipo],
                              'id' => (string)$f['codigo'], 'nombre' => (string)$f['nombre'], 'detalle' => (string)$f['detalle'],
                              'reporto' => (string)$f['usuario_nombre']];
        }

        return [
            'desde' => $desde, 'hasta' => $hasta, 'dias' => $nDias, 'generado' => date('Y-m-d H:i:s', $ahora),
            'kpis' => [
                'dentro' => count($dentro), 'dentro_revisar' => count(array_filter($dentro, fn($x) => $x['revisar'])),
                'entradas' => $k['entradas'], 'salidas' => $k['salidas'], 'personas' => count($ids),
                'entradas_dia' => $k['ent_dia'], 'entradas_noche' => $k['ent_noche'],
                'visitas' => $visitas, 'minutos' => $minTotal, 'promedio_min' => $visitas ? (int)round($minTotal / $visitas) : 0,
                'sin_salida' => $sinSalida, 'largas' => $largas, 'sin_id' => $k['sin_id'],
                'incidencias' => count($incidencias),
            ],
            'por_dia' => array_values($dias),
            'por_hora' => array_map(fn($h, $x) => ['hora' => $h] + $x, array_keys($porHora), $porHora),
            'ocupacion' => $ocupacion,
            'permanencia' => array_map(fn($r, $n) => ['rango' => $r[2], 'n' => $n], self::PERMANENCIA, $perm),
            'incidencias_tipo' => array_map(fn($t) => ['tipo' => $t, 'nombre' => ControlAccesos::INCIDENCIAS[$t], 'n' => $porTipo[$t]], array_keys($porTipo)),
            'personas' => $personas,
            'movimientos' => $movs,
            'incidencias' => $incidencias,
            'en_planta' => $dentro,
        ];
    }

    /** ¿Esa entrada es la última lectura de esa persona? (entonces sigue dentro) */
    private function esUltimaEntrada(array $filas, string $clave, string $entrada): bool {
        for ($i = count($filas) - 1; $i >= 0; $i--) {
            if ((string)$filas[$i]['codigo'] !== '' && ControlAccesos::claveId((string)$filas[$i]['codigo']) === $clave) {
                return $filas[$i]['tipo'] === 'entrada' && (string)$filas[$i]['capturado'] === $entrada;
            }
        }
        return false;
    }

    /**
     * Cuánto tiempo estuvo dentro cada quien: [inicio, fin] por cada entrada. Sin salida, hasta
     * ahora si es su última lectura y entró en las últimas 48 h (como «En planta»); si no, 16 h
     * (o hasta su siguiente entrada, si es antes).
     * @return array<int,array{0:int,1:int}>
     */
    private function intervalos(array $filas, int $ahora): array {
        $porId = [];
        foreach ($filas as $f) {
            if ((string)$f['codigo'] === '') continue;
            $porId[ControlAccesos::claveId((string)$f['codigo'])][] = $f;
        }
        $jornada = ControlAccesos::HORAS_JORNADA_MAX * 3600;
        $out = [];
        foreach ($porId as $regs) {
            $n = count($regs);
            for ($i = 0; $i < $n; $i++) {
                if ($regs[$i]['tipo'] !== 'entrada') continue;
                $ini = strtotime((string)$regs[$i]['capturado']);
                if ($i + 1 < $n) {
                    $sig = strtotime((string)$regs[$i + 1]['capturado']);
                    $fin = $regs[$i + 1]['tipo'] === 'salida' ? $sig : min($sig, $ini + $jornada);
                } else {
                    $fin = $ini >= $ahora - ControlAccesos::HORAS_EN_PLANTA * 3600 ? $ahora : $ini + $jornada;
                }
                if ($fin > $ini) $out[] = [$ini, $fin];
            }
        }
        return $out;
    }

    /**
     * El máximo de personas dentro en cada tramo. $limites: el inicio de cada tramo (en orden);
     * el último termina en $fin.
     * @return int[]
     */
    private static function maximos(array $intervalos, array $limites, int $fin): array {
        if (!$limites) return [];
        $ev = [];
        foreach ($intervalos as [$a, $b]) {
            $a = max($a, $limites[0]); $b = min($b, $fin);
            if ($a >= $b) continue;
            $ev[] = [$a, 1]; $ev[] = [$b, -1];
        }
        usort($ev, fn($x, $y) => [$x[0], $x[1]] <=> [$y[0], $y[1]]);     // a la misma hora, primero las salidas
        $out = []; $c = 0; $i = 0; $n = count($ev);
        foreach ($limites as $j => $desde) {
            $hasta = $limites[$j + 1] ?? $fin;
            while ($i < $n && $ev[$i][0] <= $desde) { $c += $ev[$i][1]; $i++; }
            $max = $c;
            while ($i < $n && $ev[$i][0] < $hasta) { $c += $ev[$i][1]; $i++; if ($c > $max) $max = $c; }
            $out[] = $max;
        }
        return $out;
    }

    /* ── Acciones ────────────────────────────────────────────────────────── */

    /** Los datos para las gráficas de la pantalla. */
    public function datos(array $p, array $usr): array {
        $per = self::periodo($p);
        if (is_string($per)) return ['status' => 'error', 'message' => $per];
        $c = $this->calcular($per[0], $per[1], $usr);
        return ['status' => 'success',
                'top_personas' => array_slice(array_values(array_filter($c['personas'], fn($q) => $q['minutos'] > 0)), 0, 10),
                'incidencias' => array_slice($c['incidencias'], 0, 100),
                'movimientos_total' => count($c['movimientos']),
                'dias_max' => self::DIAS_MAX]
             + array_diff_key($c, ['personas' => 1, 'movimientos' => 1, 'incidencias' => 1]);
    }

    /** Excel con todo el detalle del periodo. */
    public function excel(array $p, array $usr): array {
        $per = self::periodo($p);
        if (is_string($per)) return ['status' => 'error', 'message' => $per];
        if (function_exists('set_time_limit')) @set_time_limit(120);
        $c = $this->calcular($per[0], $per[1], $usr);
        $k = $c['kpis'];
        $titulo = ['Control de accesos · Tablero', 'Periodo: ' . self::periodoTexto($c), self::generadoTexto($c, $usr)];

        $x = new XlsxSimple();
        $x->resumen('Resumen', $titulo, [
            ['Dentro de la planta ahora', $k['dentro'], 'entero'],
            ['   de ellos, con más de 13 h dentro (revisar)', $k['dentro_revisar'], 'entero'],
            ['Entradas', $k['entradas'], 'entero'],
            ['   del turno de día', $k['entradas_dia'], 'entero'],
            ['   del turno de noche', $k['entradas_noche'], 'entero'],
            ['Salidas', $k['salidas'], 'entero'],
            ['Personas distintas (por ID)', $k['personas'], 'entero'],
            ['Visitas completas (entrada y salida)', $k['visitas'], 'entero'],
            ['Horas en planta (suma de las visitas)', round($k['minutos'] / 60, 1), 'decimal'],
            ['Tiempo promedio por visita', self::hm($k['promedio_min']), 'texto'],
            ['Entradas sin salida registrada', $k['sin_salida'], 'entero'],
            ['Registros sin ID (sólo foto)', $k['sin_id'], 'entero'],
            ['Incidencias', $k['incidencias'], 'entero'],
        ]);
        $x->hoja('Por día', ['Fecha', 'Día', 'Entradas', 'Salidas', 'Personas', 'Entradas turno de día', 'Entradas turno de noche', 'Máximo de personas dentro', 'Incidencias'],
            array_map(fn($d) => [$d['fecha'], self::DIAS_CORTOS[(int)date('w', strtotime($d['fecha'] . ' 12:00:00'))], $d['entradas'], $d['salidas'], $d['personas'],
                                 $d['ent_dia'], $d['ent_noche'], $d['max_dentro'], $d['incidencias']], $c['por_dia']),
            [12, 6, 10, 10, 10, 12, 12, 14, 11], ['fecha', 'texto', 'entero', 'entero', 'entero', 'entero', 'entero', 'entero', 'entero'], $titulo);
        $x->hoja('Movimientos', ['ID', 'Nombre', 'Entrada', 'Salida', 'Tiempo dentro', 'Horas', 'Observación'],
            array_map(fn($m) => [$m['id'], $m['nombre'] !== '' ? $m['nombre'] : 'Sin nombre leído', $m['entrada'], $m['salida'],
                                 $m['minutos'] !== null ? self::hm($m['minutos']) : '', $m['minutos'] !== null ? round($m['minutos'] / 60, 2) : '',
                                 $m['observacion']], $c['movimientos']),
            [9, 38, 17, 17, 14, 8, 30], ['texto', 'texto', 'fechahora', 'fechahora', 'texto', 'decimal', 'texto'], $titulo);
        $x->hoja('Por persona', ['ID', 'Nombre', 'Visitas', 'Días con entrada', 'Horas en planta', 'Promedio por visita', 'Última lectura'],
            array_map(fn($q) => [$q['id'], $q['nombre'] !== '' ? $q['nombre'] : 'Sin nombre leído', $q['visitas'], $q['dias'],
                                 round($q['minutos'] / 60, 1), $q['completas'] ? self::hm((int)round($q['minutos'] / $q['completas'])) : '',
                                 $q['ultima']], $c['personas']),
            [9, 38, 9, 10, 10, 13, 17], ['texto', 'texto', 'entero', 'entero', 'decimal', 'texto', 'fechahora'], $titulo);
        $x->hoja('Por hora', ['Hora', 'Entradas', 'Salidas'],
            array_map(fn($h) => [sprintf('%02d:00 a %02d:59', $h['hora'], $h['hora']), $h['entradas'], $h['salidas']], $c['por_hora']),
            [16, 10, 10], ['texto', 'entero', 'entero'], $titulo);
        $x->hoja('Tiempo dentro', ['Tiempo dentro por visita', 'Visitas'],
            array_map(fn($r) => [$r['rango'], $r['n']], $c['permanencia']), [24, 10], ['texto', 'entero'], $titulo);
        $x->hoja('Incidencias', ['Fecha y hora', 'Tipo', 'ID', 'Nombre', 'Qué pasó', 'Reportó'],
            array_map(fn($i) => [$i['capturado'], $i['nombre_tipo'], $i['id'], $i['nombre'], $i['detalle'], $i['reporto']], $c['incidencias']),
            [17, 24, 9, 34, 50, 22], ['fechahora', 'texto', 'texto', 'texto', 'texto', 'texto'], $titulo);
        $x->hoja('En planta ahora', ['ID', 'Nombre', 'Entrada', 'Tiempo dentro', 'Revisar'],
            array_map(fn($d) => [$d['id'], $d['nombre'] !== '' ? $d['nombre'] : 'Sin nombre leído', $d['entrada'], self::hm((int)$d['minutos']),
                                 $d['revisar'] ? 'Más de 13 h: ¿salió sin registrar?' : ''], $c['en_planta']),
            [9, 38, 17, 14, 34], ['texto', 'texto', 'fechahora', 'texto', 'texto'], $titulo);

        $archivo = self::nombreArchivo($c, 'xlsx');
        if (!$x->guardar(self::carpetaReportes() . $archivo)) return ['status' => 'error', 'message' => 'No se pudo guardar el Excel en el servidor.'];
        return ['status' => 'success', 'archivo' => $archivo, 'url' => 'descargar.php?tipo=reporte&f=' . rawurlencode($archivo),
                'movimientos' => count($c['movimientos']), 'bytes' => (int)@filesize(self::carpetaReportes() . $archivo)];
    }

    /**
     * Reporte en PDF. La pantalla manda las gráficas como imágenes PNG (graficas: [{titulo, imagen}]),
     * dibujadas igual que en el tablero; sin ellas el PDF sale sólo con los datos.
     */
    public function pdf(array $p, array $usr): array {
        $per = self::periodo($p);
        if (is_string($per)) return ['status' => 'error', 'message' => $per];
        if (!class_exists('Mpdf\Mpdf')) {
            $autoload = __DIR__ . '/../vendor/autoload.php';
            if (!is_file($autoload)) return ['status' => 'error', 'message' => 'No está instalado el generador de PDF.'];
            require_once $autoload;
        }
        if (function_exists('set_time_limit')) @set_time_limit(120);
        $c = $this->calcular($per[0], $per[1], $usr);
        $dir = self::carpetaReportes();
        $tmp = $dir . 'tmp-' . bin2hex(random_bytes(6)) . '/';
        @mkdir($tmp, 0755, true);
        $graficas = [];
        foreach (array_slice(is_array($p['graficas'] ?? null) ? $p['graficas'] : [], 0, 8) as $i => $g) {
            $png = self::pngValido(is_array($g) ? (string)($g['imagen'] ?? '') : '');
            if ($png === null) continue;
            $ruta = $tmp . 'g' . $i . '.png';
            if (@file_put_contents($ruta, $png) === false) continue;
            $graficas[] = ['titulo' => mb_substr(trim((string)($g['titulo'] ?? '')), 0, 90), 'sub' => mb_substr(trim((string)($g['sub'] ?? '')), 0, 120), 'ruta' => $ruta];
        }
        try {
            $mpdf = new \Mpdf\Mpdf([
                'mode' => 'utf-8', 'format' => 'A4', 'orientation' => 'P',
                'margin_top' => 10, 'margin_bottom' => 14, 'margin_left' => 10, 'margin_right' => 10,
                'margin_header' => 0, 'margin_footer' => 6,
            ]);
            $mpdf->SetTitle('Control de accesos · ' . self::periodoTexto($c));
            $mpdf->SetAuthor('AVBA Control de Accesos');
            $mpdf->SetFooter('Control de accesos|' . self::periodoTexto($c) . '|Página {PAGENO} de {nbpg}');
            $mpdf->WriteHTML($this->htmlPdf($c, $graficas, $usr));
            $archivo = self::nombreArchivo($c, 'pdf');
            $mpdf->Output($dir . $archivo, 'F');
            $paginas = (int)$mpdf->page;
        } finally {
            self::borrarCarpeta($tmp);
        }
        return ['status' => 'success', 'archivo' => $archivo, 'url' => 'descargar.php?tipo=reporte&f=' . rawurlencode($archivo),
                'paginas' => $paginas, 'graficas' => count($graficas), 'bytes' => (int)@filesize($dir . $archivo)];
    }

    /* ── PDF ─────────────────────────────────────────────────────────────── */

    private function htmlPdf(array $c, array $graficas, array $usr): string {
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $n = fn($v) => number_format((float)$v, 0, '.', ',');
        $k = $c['kpis'];
        $logo = realpath(__DIR__ . '/../assets/logos/avba.png');
        $html = '<style>body{font-family:sans-serif;color:#1a1a2e;font-size:9.5pt}'
              . 'h2{font-size:12pt;color:#0C447C;margin:6mm 0 2mm}'
              . 'table.t{border-collapse:collapse;width:100%;font-size:8.8pt}'
              . 'table.t th{background:#0C447C;color:#fff;font-weight:bold;padding:1.6mm 2mm;text-align:left}'
              . 'table.t td{padding:1.4mm 2mm;border-bottom:0.2mm solid #dfe5ef}'
              . 'table.t td.n,table.t th.n{text-align:right}'
              . '.nota{font-size:8.3pt;color:#5a6072}</style>';
        $html .= '<table width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse"><tr>'
               . '<td style="width:42mm;vertical-align:middle">' . ($logo ? '<img src="' . $h($logo) . '" style="width:38mm">' : '') . '</td>'
               . '<td style="vertical-align:middle;padding-left:4mm"><div style="font-size:17pt;font-weight:bold;color:#0C447C">CONTROL DE ACCESOS</div>'
               . '<div style="font-size:11pt">Tablero · ' . $h(self::periodoTexto($c)) . '</div>'
               . '<div class="nota">' . $h(self::generadoTexto($c, $usr)) . '</div></td></tr></table>';

        // Cifras principales
        $caja = fn($titulo, $valor, $sub) => '<td style="width:33.3%;background:#eef3f9;padding:2.5mm 3mm;border:1.5mm solid #ffffff;vertical-align:top">'
            . '<div style="font-size:8.5pt;color:#5a6072">' . $h($titulo) . '</div>'
            . '<div style="font-size:17pt;font-weight:bold;color:#0B2545">' . $h($valor) . '</div>'
            . '<div style="font-size:8pt;color:#5a6072">' . $h($sub) . '</div></td>';
        $html .= '<table width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin-top:4mm"><tr>'
               . $caja('Dentro de la planta ahora', $n($k['dentro']), $k['dentro_revisar'] ? $k['dentro_revisar'] . ' con más de 13 h dentro' : 'Al ' . self::fechaHora($c['generado']))
               . $caja('Entradas', $n($k['entradas']), 'Turno de día ' . $n($k['entradas_dia']) . ' · noche ' . $n($k['entradas_noche']))
               . $caja('Salidas', $n($k['salidas']), $k['sin_salida'] ? $n($k['sin_salida']) . ($k['sin_salida'] === 1 ? ' entrada' : ' entradas') . ' sin salida registrada' : 'En el periodo')
               . '</tr><tr>'
               . $caja('Personas distintas', $n($k['personas']), 'Por su ID de credencial')
               . $caja('Horas en planta', $n(round($k['minutos'] / 60)), 'Promedio por visita: ' . self::hm($k['promedio_min']))
               . $caja('Incidencias', $n($k['incidencias']), 'Reportadas en la caseta')
               . '</tr></table>';

        // Gráficas, de dos en dos
        if ($graficas) {
            $html .= '<h2>Gráficas</h2><table width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse">';
            foreach (array_chunk($graficas, 2) as $par) {
                $html .= '<tr>';
                foreach ($par as $g) {
                    $html .= '<td style="width:50%;vertical-align:top;padding:1.5mm 1.5mm 4mm">'
                           . '<div style="font-size:9.5pt;font-weight:bold;color:#0C447C">' . $h($g['titulo']) . '</div>'
                           . ($g['sub'] !== '' ? '<div class="nota">' . $h($g['sub']) . '</div>' : '')
                           . '<img src="' . $h($g['ruta']) . '" style="width:92mm;margin-top:1.5mm"></td>';
                }
                if (count($par) === 1) $html .= '<td style="width:50%"></td>';
                $html .= '</tr>';
            }
            $html .= '</table>';
        }

        // Por día
        $html .= '<h2>Por día</h2><table class="t" repeat_header="1"><thead><tr><th>Fecha</th><th class="n">Entradas</th><th class="n">Salidas</th>'
               . '<th class="n">Personas</th><th class="n">Turno de día</th><th class="n">Turno de noche</th><th class="n">Máx. dentro</th><th class="n">Incidencias</th></tr></thead><tbody>';
        foreach ($c['por_dia'] as $d) {
            $html .= '<tr><td>' . $h(self::DIAS_CORTOS[(int)date('w', strtotime($d['fecha'] . ' 12:00:00'))] . ' ' . self::fecha($d['fecha'])) . '</td>'
                   . '<td class="n">' . $n($d['entradas']) . '</td><td class="n">' . $n($d['salidas']) . '</td><td class="n">' . $n($d['personas']) . '</td>'
                   . '<td class="n">' . $n($d['ent_dia']) . '</td><td class="n">' . $n($d['ent_noche']) . '</td>'
                   . '<td class="n">' . $n($d['max_dentro']) . '</td><td class="n">' . $n($d['incidencias']) . '</td></tr>';
        }
        $html .= '</tbody></table><p class="nota">Turno de día y de noche: entradas de cada turno. Máx. dentro: el mayor número de personas dentro de la planta a la vez ese día.</p>';

        // Personas con más horas
        $top = array_slice(array_filter($c['personas'], fn($q) => $q['minutos'] > 0), 0, 25);
        if ($top) {
            $html .= '<h2>Personas con más horas en planta</h2><table class="t" repeat_header="1"><thead><tr><th>#</th><th>ID</th><th>Nombre</th>'
                   . '<th class="n">Visitas</th><th class="n">Días</th><th class="n">Horas</th><th class="n">Promedio por visita</th></tr></thead><tbody>';
            foreach ($top as $i => $q) {
                $html .= '<tr><td style="color:#5a6072">' . ($i + 1) . '</td><td><b>' . $h($q['id']) . '</b></td><td>' . $h($q['nombre'] !== '' ? $q['nombre'] : 'Sin nombre leído') . '</td>'
                       . '<td class="n">' . $n($q['visitas']) . '</td><td class="n">' . $n($q['dias']) . '</td><td class="n">' . number_format($q['minutos'] / 60, 1) . '</td>'
                       . '<td class="n">' . $h($q['completas'] ? self::hm((int)round($q['minutos'] / $q['completas'])) : '') . '</td></tr>';
            }
            $html .= '</tbody></table>';
        }

        // Incidencias
        $html .= '<h2>Incidencias</h2>';
        if (!$c['incidencias']) {
            $html .= '<p class="nota">No se reportaron incidencias en el periodo.</p>';
        } else {
            $html .= '<table class="t" repeat_header="1"><thead><tr><th>Fecha</th><th>Tipo</th><th>ID</th><th>Nombre</th><th>Qué pasó</th></tr></thead><tbody>';
            foreach (array_slice($c['incidencias'], 0, 300) as $i) {
                $html .= '<tr><td>' . $h(self::fechaHora($i['capturado'])) . '</td><td>' . $h($i['nombre_tipo']) . '</td><td><b>' . $h($i['id']) . '</b></td>'
                       . '<td>' . $h($i['nombre']) . '</td><td>' . $h($i['detalle']) . '</td></tr>';
            }
            $html .= '</tbody></table>';
        }

        // Dentro ahora
        $html .= '<h2>Dentro de la planta ahora</h2>';
        if (!$c['en_planta']) {
            $html .= '<p class="nota">Nadie aparece dentro de la planta al ' . $h(self::fechaHora($c['generado'])) . '.</p>';
        } else {
            $html .= '<table class="t" repeat_header="1"><thead><tr><th>ID</th><th>Nombre</th><th>Entrada</th><th class="n">Tiempo dentro</th></tr></thead><tbody>';
            foreach ($c['en_planta'] as $d) {
                $html .= '<tr><td><b>' . $h($d['id']) . '</b></td><td>' . $h($d['nombre'] !== '' ? $d['nombre'] : 'Sin nombre leído') . '</td>'
                       . '<td>' . $h(self::fechaHora($d['entrada'])) . '</td><td class="n"' . ($d['revisar'] ? ' style="color:#A32D2D;font-weight:bold"' : '') . '>'
                       . $h(self::hm((int)$d['minutos'])) . '</td></tr>';
            }
            $html .= '</tbody></table>';
        }

        // Movimientos
        $total = count($c['movimientos']);
        $html .= '<h2>Movimientos</h2>';
        if (!$total) {
            $html .= '<p class="nota">No hay entradas ni salidas con ID en el periodo.</p>';
        } elseif ($total > self::MOVS_PDF) {
            $html .= '<p class="nota">El periodo tiene ' . $n($total) . ' movimientos (entrada y salida de cada persona): el detalle completo está en el Excel.</p>';
        } else {
            $html .= '<table class="t" repeat_header="1"><thead><tr><th>ID</th><th>Nombre</th><th>Entrada</th><th>Salida</th><th class="n">Tiempo dentro</th></tr></thead><tbody>';
            foreach ($c['movimientos'] as $m) {
                $html .= '<tr><td><b>' . $h($m['id']) . '</b></td><td>' . $h($m['nombre'] !== '' ? $m['nombre'] : 'Sin nombre leído') . '</td>'
                       . '<td>' . ($m['entrada'] !== '' ? $h(self::fechaHora($m['entrada'])) : '<span style="color:#A32D2D">Sin entrada</span>') . '</td>'
                       . '<td>' . ($m['salida'] !== '' ? $h(self::fechaHora($m['salida'])) : '<span style="color:#8A5A00">' . $h($m['observacion'] === 'Dentro de la planta' ? 'Dentro' : 'Sin salida') . '</span>') . '</td>'
                       . '<td class="n">' . ($m['minutos'] !== null ? $h(self::hm($m['minutos'])) : '') . '</td></tr>';
            }
            $html .= '</tbody></table>';
        }
        $html .= '<p class="nota" style="margin-top:4mm">Cada persona se identifica por el ID de su credencial (el número del recuadro azul). '
               . 'Una visita es la entrada de una persona junto con su salida. Las visitas de más de 24 h no cuentan en las horas: son salidas que no se registraron.</p>';
        return $html;
    }

    /** La imagen PNG de una gráfica (data:image/png;base64,…), revisada. */
    private static function pngValido(string $s): ?string {
        if (!str_starts_with($s, 'data:image/png;base64,') || strlen($s) > 4_000_000) return null;
        $png = base64_decode(substr($s, 22), true);
        if ($png === false || strlen($png) < 100) return null;
        $info = @getimagesizefromstring($png);
        if (!$info || ($info[2] ?? 0) !== IMAGETYPE_PNG || $info[0] > 4000 || $info[1] > 4000) return null;
        return $png;
    }

    /* ── Archivos ────────────────────────────────────────────────────────── */

    /** uploads/accesos/reportes/ (protegida); se borran los de más de un día. */
    public static function carpetaReportes(): string {
        ControlAccesos::protegerCarpeta();
        $dir = ControlAccesos::carpeta() . 'reportes/';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        foreach (array_merge(glob($dir . 'TABLERO_*') ?: [], glob($dir . 'PERSONAL_*') ?: []) as $f) if (@filemtime($f) < time() - 86400) @unlink($f);
        foreach (glob($dir . 'tmp-*', GLOB_ONLYDIR) ?: [] as $d) if (@filemtime($d) < time() - 3600) self::borrarCarpeta($d . '/');
        return $dir;
    }
    public static function rutaReporte(string $archivo): ?string {
        if (!preg_match('/^TABLERO_\d{4}-\d{2}-\d{2}_\d{4}-\d{2}-\d{2}_[a-f0-9]{12}\.(xlsx|pdf)$/', $archivo)) return null;
        $abs = ControlAccesos::carpeta() . 'reportes/' . $archivo;
        return is_file($abs) ? $abs : null;
    }
    private static function nombreArchivo(array $c, string $ext): string {
        return 'TABLERO_' . $c['desde'] . '_' . $c['hasta'] . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    }
    private static function borrarCarpeta(string $dir): void {
        if (!is_dir($dir)) return;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        @rmdir($dir);
    }

    /* ── Textos ──────────────────────────────────────────────────────────── */
    private static function fecha(string $f): string { return substr($f, 8, 2) . '/' . substr($f, 5, 2) . '/' . substr($f, 0, 4); }
    private static function fechaHora(string $f): string { return $f === '' ? '' : self::fecha($f) . ' ' . substr($f, 11, 5); }
    private static function periodoTexto(array $c): string {
        return $c['desde'] === $c['hasta'] ? 'del ' . self::fecha($c['desde']) : 'del ' . self::fecha($c['desde']) . ' al ' . self::fecha($c['hasta']);
    }
    private static function generadoTexto(array $c, array $usr): string {
        return 'Generado el ' . self::fechaHora($c['generado']) . ' por ' . ($usr['nombre'] ?? $usr['usuario'] ?? '');
    }
    /** 485 → «8 h 05 min» */
    public static function hm(int $min): string {
        $min = max(0, $min);
        return intdiv($min, 60) . ' h ' . str_pad((string)($min % 60), 2, '0', STR_PAD_LEFT) . ' min';
    }
}
