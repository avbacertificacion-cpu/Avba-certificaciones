<?php
/**
 * Qué extintores están vencidos o por vencer.
 *
 * Como config/pendientes.php, la definición vive aquí porque dos cosas la
 * usan —la pantalla de Vencimientos y el PDF que se descarga de ella— y las
 * dos tienen que dar los mismos números.
 *
 * Qué vence, con las reglas que ya usa el resto del sistema:
 *  - La recarga, un año después de fecha_recarga. Es la misma cuenta de la
 *    gráfica «a mantenimiento» del panel.
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
    'todos'   => 'Recarga y prueba hidrostática',
    'recarga' => 'Recarga anual',
    'ph'      => 'Prueba hidrostática',
];
const VENCE_NOMBRE = ['recarga' => 'Recarga', 'ph' => 'Prueba hidrostática'];

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

/**
 * Cada vencimiento hasta 30 días adelante —incluidos todos los ya pasados—,
 * de los extintores activos, uno por fila: un extintor puede traer dos.
 */
function vencimientosHasta30(PDO $pdo, string $concepto, int $empresaId): array {
    $filtro = $empresaId ? ' AND e.empresa_id = ?' : '';
    // Una fecha en ceros o de antes de 1900 es un dato mal capturado, no un vencimiento
    $partes = [];
    $params = [];
    if ($concepto !== 'ph') {
        $partes[] = "SELECT e.id AS extintor_id, 'recarga' AS concepto,
                            DATE_ADD(e.fecha_recarga, INTERVAL 1 YEAR) AS vence
                     FROM extintores e
                     WHERE e.estado <> 'inactivo' AND e.fecha_recarga > '1900-01-01' $filtro";
        if ($empresaId) $params[] = $empresaId;
    }
    if ($concepto !== 'recarga') {
        $partes[] = "SELECT e.id AS extintor_id, 'ph' AS concepto, e.fecha_ph AS vence
                     FROM extintores e
                     WHERE e.estado <> 'inactivo' AND e.fecha_ph > '1900-01-01' $filtro";
        if ($empresaId) $params[] = $empresaId;
    }

    $st = $pdo->prepare("
        SELECT v.extintor_id, v.concepto, v.vence, DATEDIFF(v.vence, CURDATE()) AS dias
        FROM (" . implode(' UNION ALL ', $partes) . ") v
        WHERE v.vence <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        ORDER BY v.vence ASC
    ");
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
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
        $dias = (int) $v['dias'];
        foreach (array_keys(VENCE_RANGOS) as $r) {
            if (enRango($dias, (string) $r)) $conteo[$r][$v['extintor_id']] = true;
        }
        if (enRango($dias, $rango)) {
            $vencen[$v['extintor_id']][] = ['concepto' => $v['concepto'],
                                            'nombre'   => VENCE_NOMBRE[$v['concepto']],
                                            'fecha'    => $v['vence'],
                                            'dias'     => $dias];
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
