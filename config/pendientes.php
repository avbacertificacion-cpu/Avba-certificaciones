<?php
/**
 * Qué falta por inspeccionar en un mes.
 *
 * La definición vive aquí, y no dentro de la pantalla, porque dos cosas la
 * usan: el listado de «Faltan por inspeccionar» y el PDF que se descarga de
 * ese mismo listado. Si cada uno armara su propia consulta, tarde o temprano
 * dirían números distintos y no habría forma de saber cuál creer.
 *
 * Qué cuenta como pendiente: un extintor que no está dado de baja y que no
 * tiene ninguna inspección registrada en ese mes. Los que están en el taller
 * se devuelven igual —se deben, y el total tiene que cuadrar— pero marcados,
 * porque no se pueden inspeccionar mientras no regresen.
 */
require_once __DIR__ . '/taller.php';

/** Convierte 'AAAA-MM' en [mes, año]; si no sirve, el mes en curso. */
function mesPedido(?string $texto): array {
    if ($texto && preg_match('/^(\d{4})-(\d{2})$/', trim($texto), $m)) {
        $mes = (int) $m[2];
        if ($mes >= 1 && $mes <= 12) return [$mes, (int) $m[1]];
    }
    return [(int) date('n'), (int) date('Y')];
}

/** Los extintores sin inspección en ese mes, ordenados por planta y número. */
function extintoresPendientes(PDO $pdo, int $mes, int $anio, int $empresaId = 0): array {
    $filtro = $empresaId ? ' AND e.empresa_id = ?' : '';
    $params = [$mes, $anio];
    if ($empresaId) $params[] = $empresaId;

    $stmt = $pdo->prepare("
        SELECT e.id, e.codigo_manual, e.codigo_qr, e.empresa_id, e.seccion, e.ubicacion,
               e.capacidad, e.estado, te.nombre AS tipo_nombre, emp.nombre AS empresa_nombre,
               (SELECT MAX(x.fecha) FROM inspecciones x WHERE x.extintor_id = e.id) AS ultima_inspeccion
        FROM extintores e
        JOIN tipos_extintores te ON te.id = e.tipo
        JOIN empresas emp        ON emp.id = e.empresa_id
        LEFT JOIN inspecciones i ON i.extintor_id = e.id
                                AND MONTH(i.fecha) = ? AND YEAR(i.fecha) = ?
        WHERE e.estado <> 'inactivo' AND i.id IS NULL $filtro
        GROUP BY e.id
        ORDER BY emp.nombre ASC,
                 CAST(REPLACE(REPLACE(UPPER(e.codigo_manual), 'EXT-', ''), '-', '') AS UNSIGNED) ASC
    ");
    $stmt->execute($params);
    $faltan = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Los que están en el taller se devuelven igual, pero marcados
    return marcarEnTaller($pdo, $faltan);
}

/** Cuántos son, de cuántos, para poder decir «faltan 37 de 241». */
function resumenPendientes(PDO $pdo, array $faltan, int $empresaId = 0): array {
    $params = [];
    $sql = "SELECT COUNT(*) FROM extintores e WHERE e.estado <> 'inactivo'";
    if ($empresaId) { $sql .= ' AND e.empresa_id = ?'; $params[] = $empresaId; }
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $total = (int) $st->fetchColumn();

    $enTaller = count(array_filter($faltan, fn($f) => !empty($f['taller_desde'])));
    return ['total' => $total, 'faltan' => count($faltan),
            'inspeccionados' => $total - count($faltan), 'en_taller' => $enTaller];
}

/** Agrupados por planta, que es como se reparte el trabajo. */
function pendientesPorPlanta(array $faltan): array {
    $porPlanta = [];
    foreach ($faltan as $f) $porPlanta[$f['empresa_nombre']][] = $f;
    return $porPlanta;
}
