<?php
/**
 * Qué extintores están vencidos o por vencer.
 *
 * Como config/pendientes.php, la definición vive aquí porque dos cosas la
 * usan —la pantalla de Vencimientos y el PDF que se descarga de ella— y las
 * dos tienen que dar los mismos números.
 *
 * Qué vence:
 *  - El servicio anual: 12 meses después del último servicio del equipo,
 *    que es lo más reciente entre la fecha de recarga de su ficha y la
 *    devolución de su último paso por el taller (recarga, mantenimiento o
 *    cualquier otro motivo, salvo garantía, que es una reparación y no un
 *    servicio). Un mantenimiento cuenta aunque al registrarlo no se haya
 *    marcado «actualiza la recarga».
 *  - La prueba hidrostática, en fecha_ph tal como está capturada: el panel
 *    del administrador, el del cliente y el del gerente ya la tratan como la
 *    fecha en que vence.
 *
 * Los tres cortes:
 *  - vencidos: la fecha ya pasó;
 *  - 15 días:  vence de hoy a 15 días;
 *  - 30 días:  vence de hoy a 30 días (incluye los de 15).
 *
 * Un extintor sale una sola vez aunque le venzan las dos cosas, con cada
 * vencimiento anotado. Los que están en el taller se devuelven marcados:
 * ya se están atendiendo.
 */
require_once __DIR__ . '/taller.php';

const VENCE_RANGOS = [
    'vencidos' => 'Vencidos',
    '15'       => 'Próximos 15 días',
    '30'       => 'Próximos 30 días',
];
const VENCE_CONCEPTOS = [
    'todos'   => 'Todo: servicio anual y prueba hidrostática',
    'recarga' => 'Recarga o mantenimiento (12 meses)',
    'ph'      => 'Prueba hidrostática',
];

function rangoPedido(?string $v): string {
    $v = (string) $v;
    return array_key_exists($v, VENCE_RANGOS) ? $v : '30';
}

function conceptoPedido(?string $v): string {
    $v = (string) $v;
    return array_key_exists($v, VENCE_CONCEPTOS) ? $v : 'todos';
}

/** ¿Este vencimiento, a tantos días, cae en el corte pedido? */
function enRango(int $dias, string $rango): bool {
    if ($rango === 'vencidos') return $dias < 0;
    return $dias >= 0 && $dias <= (int) $rango;
}

/** Días de $desde a $hasta: negativo si $hasta ya pasó. */
function diasEntre(string $desde, string $hasta): int {
    $d = (new DateTime($desde))->diff(new DateTime($hasta));
    return $d->invert ? -$d->days : $d->days;
}

/**
 * El último servicio de cada extintor que pasó por el taller: la devolución
 * más reciente que ya ocurrió, sin contar garantías. extintor_id => [fecha, motivo].
 */
function ultimosServiciosTaller(PDO $pdo, string $hoy, int $empresaId): array {
    if (!tablaTallerExiste($pdo)) return [];
    try {
        $filtro = $empresaId ? ' AND e.empresa_id = ?' : '';
        $st = $pdo->prepare("
            SELECT m.extintor_id, m.fecha_devolucion, t.nombre AS motivo
            FROM extintor_mantenimientos m
            JOIN extintores e              ON e.id = m.extintor_id
            LEFT JOIN mantenimiento_tipos t ON t.id = m.tipo_id
            WHERE m.fecha_devolucion IS NOT NULL AND m.fecha_devolucion <= ?
              AND (t.nombre IS NULL OR t.nombre NOT LIKE '%GARANT%') $filtro
            ORDER BY m.fecha_devolucion ASC, m.id ASC
        ");
        $st->execute($empresaId ? [$hoy, $empresaId] : [$hoy]);
        $ultimo = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {   // en orden: se queda el más reciente
            $ultimo[$r['extintor_id']] = ['fecha' => $r['fecha_devolucion'], 'motivo' => $r['motivo']];
        }
        return $ultimo;
    } catch (Exception $e) {
        return [];   // sin el taller, el servicio anual se cuenta con la ficha
    }
}

/**
 * Cada vencimiento hasta 30 días adelante —incluidos todos los ya pasados—,
 * de los extintores activos, uno por fila: un extintor puede traer dos.
 */
function vencimientosHasta30(PDO $pdo, string $concepto, int $empresaId): array {
    // «Hoy» lo dice la base, como en los paneles, para que todos cuenten igual
    $hoy    = (string) $pdo->query("SELECT CURDATE()")->fetchColumn();
    $filtro = $empresaId ? ' AND e.empresa_id = ?' : '';
    $st = $pdo->prepare("
        SELECT e.id, e.fecha_recarga, e.fecha_ph
        FROM extintores e
        WHERE e.estado <> 'inactivo' $filtro
    ");
    $st->execute($empresaId ? [$empresaId] : []);
    $extintores = $st->fetchAll(PDO::FETCH_ASSOC);
    $taller = $concepto !== 'ph' ? ultimosServiciosTaller($pdo, $hoy, $empresaId) : [];

    // Una fecha en ceros o de antes de 1900 es un dato mal capturado, no un vencimiento
    $valida = fn($f) => $f && $f > '1900-01-01';

    $salida = [];
    foreach ($extintores as $e) {
        if ($concepto !== 'ph') {
            $base = null;
            if ($valida($e['fecha_recarga'])) {
                $base = ['fecha' => $e['fecha_recarga'], 'nombre' => 'Recarga'];
            }
            $t = $taller[$e['id']] ?? null;
            if ($t && (!$base || $t['fecha'] > $base['fecha'])) {
                $motivo = mb_strtolower(trim((string) $t['motivo']), 'UTF-8');
                $base = ['fecha' => $t['fecha'], 'nombre' => $motivo !== '' ? mb_strtoupper(mb_substr($motivo, 0, 1)) . mb_substr($motivo, 1) : 'Mantenimiento'];
            }
            if ($base) {
                $vence = (new DateTime($base['fecha']))->modify('+12 months')->format('Y-m-d');
                $dias  = diasEntre($hoy, $vence);
                if ($dias <= 30) {
                    $salida[] = ['extintor_id' => $e['id'], 'concepto' => 'recarga', 'nombre' => $base['nombre'],
                                 'desde' => $base['fecha'], 'fecha' => $vence, 'dias' => $dias];
                }
            }
        }
        if ($concepto !== 'recarga' && $valida($e['fecha_ph'])) {
            $dias = diasEntre($hoy, $e['fecha_ph']);
            if ($dias <= 30) {
                $salida[] = ['extintor_id' => $e['id'], 'concepto' => 'ph', 'nombre' => 'Prueba hidrostática',
                             'desde' => null, 'fecha' => $e['fecha_ph'], 'dias' => $dias];
            }
        }
    }
    usort($salida, fn($a, $b) => $a['dias'] <=> $b['dias']);
    return $salida;
}

/** Cuántos extintores caen en cada corte; lo que muestra el panel. */
function conteoPorVencer(PDO $pdo, string $concepto = 'todos', int $empresaId = 0): array {
    $conteo = array_fill_keys(array_keys(VENCE_RANGOS), []);
    foreach (vencimientosHasta30($pdo, $concepto, $empresaId) as $v) {
        foreach (array_keys(VENCE_RANGOS) as $r) {
            if (enRango($v['dias'], (string) $r)) $conteo[$r][$v['extintor_id']] = true;
        }
    }
    return array_map('count', $conteo);
}

/**
 * Los extintores del corte pedido, con sus datos, ordenados por planta y,
 * dentro de cada planta, del más urgente al menos urgente. Además, cuántos
 * caen en cada uno de los tres cortes, para poder decirlo sin otra consulta.
 */
function extintoresPorVencer(PDO $pdo, string $rango, string $concepto, int $empresaId = 0): array {
    $todos = vencimientosHasta30($pdo, $concepto, $empresaId);

    $conteo = array_fill_keys(array_keys(VENCE_RANGOS), []);
    $vencen = [];   // extintor_id => [vencimientos del corte pedido]
    foreach ($todos as $v) {
        $id = $v['extintor_id'];
        foreach (array_keys(VENCE_RANGOS) as $r) {
            if (enRango($v['dias'], (string) $r)) $conteo[$r][$id] = true;
        }
        if (enRango($v['dias'], $rango)) {
            unset($v['extintor_id']);
            $vencen[$id][] = $v;
        }
    }
    $conteo = array_map('count', $conteo);

    $filas = [];
    if ($vencen) {
        $ids    = array_keys($vencen);
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("
            SELECT e.id, e.codigo_manual, e.codigo_qr, e.empresa_id, e.seccion, e.ubicacion,
                   e.capacidad, e.estado, e.fecha_recarga, e.fecha_ph,
                   te.nombre AS tipo_nombre, emp.nombre AS empresa_nombre
            FROM extintores e
            LEFT JOIN tipos_extintores te ON te.id = e.tipo
            JOIN empresas emp             ON emp.id = e.empresa_id
            WHERE e.id IN ($marcas)
        ");
        $st->execute($ids);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $f['vencimientos'] = $vencen[$f['id']];   // ya vienen del más próximo al más lejano
            $f['dias']         = $f['vencimientos'][0]['dias'];
            $filas[] = $f;
        }
        usort($filas, fn($a, $b) =>
            [$a['empresa_nombre'], $a['dias'], $a['codigo_manual']] <=> [$b['empresa_nombre'], $b['dias'], $b['codigo_manual']]);
        $filas = marcarEnTaller($pdo, $filas);
    }

    $enTaller = count(array_filter($filas, fn($f) => !empty($f['taller_desde'])));
    return ['filas' => $filas, 'conteo' => $conteo, 'en_taller' => $enTaller];
}

/** «vence en 12 días», «vence hoy», «venció hace 3 días». */
function textoDias(int $dias): string {
    if ($dias === 0)  return 'vence hoy';
    if ($dias === 1)  return 'vence mañana';
    if ($dias > 0)    return "vence en $dias días";
    if ($dias === -1) return 'venció ayer';
    return 'venció hace ' . (-$dias) . ' días';
}
