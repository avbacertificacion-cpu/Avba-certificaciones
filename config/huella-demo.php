<?php
/**
 * La huella que deja en la base la siembra de plantas de demostración.
 *
 * Existe porque quitar la demostración NO es lo mismo que borrar las plantas:
 * varios de esos centros son clientes de verdad que ya tenían extintores,
 * usuarios, reportes y cotizaciones antes de que se sembrara nada. La siembra
 * se limitó a agregarles extintores de ejemplo (y su historial de
 * inspecciones); todo lo demás ya estaba y debe quedarse.
 *
 * Reconocer lo sembrado se hace de dos maneras, en este orden:
 *
 *  1. Por registro. Desde que existe este archivo, el sembrador anota en la
 *     tabla `demo_siembra` el rango exacto de filas que insertó. Es la vía
 *     exacta: no hay que adivinar nada.
 *
 *  2. Por indicios, para las siembras hechas antes de que se llevara ese
 *     registro. Un extintor de ejemplo se reconoce por cómo lo escribió el
 *     sembrador y no una persona: sin código QR, sin observaciones, con
 *     código EXT-###, con una capacidad de la lista de su tipo de centro y,
 *     sobre todo, con la ubicación formada exactamente como
 *     «SECCIÓN — DETALLE» a partir de las listas de config/plantas-demo.php.
 *     Que las cuatro cosas coincidan a la vez en una fila capturada a mano es
 *     prácticamente imposible.
 *
 * Nada de lo que el sembrador nunca creó —reportes, fotos, cotizaciones,
 * documentos, usuarios de cliente, tipos de extintor— se toca desde aquí.
 */
require_once __DIR__ . '/plantas-demo.php';

/** Tabla donde el sembrador anota lo que insertó. */
function huellaAsegurarTabla(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS demo_siembra (
            id             INT AUTO_INCREMENT PRIMARY KEY,
            empresa_id     INT NOT NULL,
            empresa_nombre VARCHAR(200) NOT NULL,
            empresa_creada TINYINT(1) NOT NULL DEFAULT 0,
            ext_min        INT DEFAULT NULL,
            ext_max        INT DEFAULT NULL,
            extintores     INT NOT NULL DEFAULT 0,
            inspecciones   INT NOT NULL DEFAULT 0,
            sembrado_por   INT DEFAULT NULL,
            sembrado_en    DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY idx_demo_empresa (empresa_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function huellaHayTabla(PDO $pdo): bool {
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        $st = $pdo->prepare("SHOW TABLES LIKE ?");
        $st->execute(['demo_siembra']);
        $cache = (bool) $st->fetchColumn();
    } catch (Exception $e) { $cache = false; }
    return $cache;
}

/** El sembrador anota aquí lo que acaba de insertar para esa planta. */
function huellaRegistrar(PDO $pdo, array $d): void {
    $pdo->prepare("
        INSERT INTO demo_siembra
            (empresa_id, empresa_nombre, empresa_creada, ext_min, ext_max, extintores, inspecciones, sembrado_por)
        VALUES (?,?,?,?,?,?,?,?)
    ")->execute([
        $d['empresa_id'], $d['empresa_nombre'], $d['empresa_creada'] ? 1 : 0,
        $d['ext_min'], $d['ext_max'], $d['extintores'], $d['inspecciones'], $d['sembrado_por'],
    ]);
}

/**
 * Condición SQL que reconoce un extintor sembrado por cómo está escrito.
 * Devuelve [fragmento, parámetros] para pegar en un WHERE sobre `extintores x`.
 */
function huellaCondicion(array $centro): array {
    $secciones   = array_map('mb_strtoupper', SECCIONES[$centro['sabor']]);
    $detalles    = array_map('mb_strtoupper', DETALLES_UBICACION);
    $capacidades = array_map('strval', CAPACIDADES[$centro['sabor']]);

    $marcas = fn(array $a) => implode(',', array_fill(0, count($a), '?'));

    // La ubicación tiene que ser exactamente la sección, el separador y uno de
    // los detalles de la lista: así se escribe sola y no a mano.
    $sql = "x.codigo_qr IS NULL
            AND x.observaciones IS NULL
            AND x.codigo_manual LIKE 'EXT-%'
            AND x.capacidad IN ({$marcas($capacidades)})
            AND x.ubicacion = CONCAT(x.seccion, ' — ', SUBSTRING_INDEX(x.ubicacion, ' — ', -1))
            AND SUBSTRING_INDEX(x.ubicacion, ' — ', -1) IN ({$marcas($detalles)})";
    $params = array_merge($capacidades, $detalles);

    // En los parques eólicos la sección lleva el número de aerogenerador
    $sql .= ($centro['sabor'] === 'eolico')
        ? " AND (x.seccion IN ({$marcas($secciones)}) OR x.seccion REGEXP '^BASE DE AEROGENERADOR [0-9]+$')"
        : " AND x.seccion IN ({$marcas($secciones)})";
    $params = array_merge($params, $secciones);

    return [$sql, $params];
}

function huellaContar(PDO $pdo, string $sql, array $params): int {
    try { $st = $pdo->prepare($sql); $st->execute($params); return (int) $st->fetchColumn(); }
    catch (Exception $e) { return 0; }
}

function huellaHayTablaSuelta(PDO $pdo, string $tabla): bool {
    static $cache = [];
    if (isset($cache[$tabla])) return $cache[$tabla];
    try {
        $st = $pdo->prepare("SHOW TABLES LIKE ?");
        $st->execute([$tabla]);
        $cache[$tabla] = (bool) $st->fetchColumn();
    } catch (Exception $e) { $cache[$tabla] = false; }
    return $cache[$tabla];
}

/**
 * Qué dejó la siembra en esa planta y qué había ya.
 *
 * Devuelve, además de los ids de los extintores sembrados, el recuento de todo
 * lo que NO se va a tocar, que es lo que el administrador necesita ver antes
 * de confirmar.
 */
function huellaDeEmpresa(PDO $pdo, array $centro, int $empresaId): array {
    [$cond, $params] = huellaCondicion($centro);

    // ── Vía exacta: lo que el sembrador dejó anotado ──
    $registro = null;
    if (huellaHayTabla($pdo)) {
        $st = $pdo->prepare("SELECT * FROM demo_siembra WHERE empresa_id = ? ORDER BY id");
        $st->execute([$empresaId]);
        $filas = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($filas) $registro = $filas;
    }

    if ($registro) {
        // Dentro del rango anotado, y además con la huella: si alguien capturó
        // un extintor de verdad justo en medio, se queda donde está.
        $trozos = []; $p = [];
        foreach ($registro as $r) {
            $trozos[] = "(x.id BETWEEN ? AND ?)";
            $p[] = $r['ext_min']; $p[] = $r['ext_max'];
        }
        $sqlIds = "SELECT x.id FROM extintores x
                   WHERE x.empresa_id = ? AND (" . implode(' OR ', $trozos) . ") AND ($cond)";
        $paramsIds = array_merge([$empresaId], $p, $params);
        $origen = 'registro';
        $empresaCreada = (bool) array_sum(array_column($registro, 'empresa_creada'));
        $sembradoEn = $registro[0]['sembrado_en'];
    } else {
        $sqlIds = "SELECT x.id FROM extintores x WHERE x.empresa_id = ? AND ($cond)";
        $paramsIds = array_merge([$empresaId], $params);
        $origen = 'indicios';
        $empresaCreada = null;   // sin registro no se puede saber; se deduce del resto
        $sembradoEn = null;
    }

    try {
        $st = $pdo->prepare($sqlIds);
        $st->execute($paramsIds);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (Exception $e) { $ids = []; }

    $total = huellaContar($pdo, "SELECT COUNT(*) FROM extintores WHERE empresa_id = ?", [$empresaId]);

    $inspecciones = 0;
    if ($ids) {
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $inspecciones = huellaContar($pdo, "SELECT COUNT(*) FROM inspecciones WHERE extintor_id IN ($marcas)", $ids);
    }

    // Lo que ya estaba y no se toca
    $propios = [
        'extintores'   => $total - count($ids),
        'usuarios'     => huellaContar($pdo, "SELECT COUNT(*) FROM usuarios WHERE empresa_id = ?", [$empresaId]),
        'reportes'     => huellaContar($pdo, "SELECT COUNT(*) FROM reportes_mensuales WHERE empresa_id = ?", [$empresaId]),
        'cotizaciones' => huellaHayTablaSuelta($pdo, 'cotizaciones')
            ? huellaContar($pdo, "SELECT COUNT(*) FROM cotizaciones WHERE empresa_id = ?", [$empresaId]) : 0,
    ];
    // Inspecciones de los extintores que se quedan
    $propios['inspecciones'] = huellaContar($pdo,
        "SELECT COUNT(*) FROM inspecciones WHERE extintor_id IN (SELECT id FROM extintores WHERE empresa_id = ?)",
        [$empresaId]) - $inspecciones;

    // La planta sólo se da de baja si al quitar lo sembrado no queda nada suyo
    $quedaAlgo = array_sum($propios) > 0;

    return [
        'origen'         => $origen,
        'sembrado_en'    => $sembradoEn,
        'empresa_creada' => $empresaCreada,
        'ids'            => $ids,
        'extintores'     => count($ids),
        'inspecciones'   => $inspecciones,
        'propios'        => $propios,
        'borrar_empresa' => !$quedaAlgo && count($ids) > 0,
    ];
}
