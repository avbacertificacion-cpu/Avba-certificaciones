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
            gerente_id     INT DEFAULT NULL,
            gerente_asignado TINYINT(1) NOT NULL DEFAULT 0,
            gerente_creado   TINYINT(1) NOT NULL DEFAULT 0,
            tipos_creados  TINYINT(1) NOT NULL DEFAULT 0,
            sembrado_por   INT DEFAULT NULL,
            sembrado_en    DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY idx_demo_empresa (empresa_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    huellaAlDia($pdo);
}

/** Columnas que se agregaron después: la tabla puede venir de una versión previa. */
function huellaAlDia(PDO $pdo): void {
    foreach (['gerente_id' => 'INT DEFAULT NULL',
              'gerente_asignado' => "TINYINT(1) NOT NULL DEFAULT 0",
              'gerente_creado' => "TINYINT(1) NOT NULL DEFAULT 0",
              'tipos_creados' => "TINYINT(1) NOT NULL DEFAULT 0"] as $col => $tipo) {
        try {
            $st = $pdo->prepare("SHOW COLUMNS FROM demo_siembra LIKE ?");
            $st->execute([$col]);
            if (!$st->fetchColumn()) $pdo->exec("ALTER TABLE demo_siembra ADD COLUMN $col $tipo");
        } catch (Exception $e) { /* si no se puede, el resto sigue funcionando */ }
    }
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
            (empresa_id, empresa_nombre, empresa_creada, ext_min, ext_max, extintores, inspecciones,
             gerente_id, gerente_asignado, gerente_creado, tipos_creados, sembrado_por)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
    ")->execute([
        $d['empresa_id'], $d['empresa_nombre'], $d['empresa_creada'] ? 1 : 0,
        $d['ext_min'] ?: null, $d['ext_max'] ?: null, $d['extintores'], $d['inspecciones'],
        $d['gerente_id'] ?: null, !empty($d['gerente_asignado']) ? 1 : 0,
        !empty($d['gerente_creado']) ? 1 : 0,
        !empty($d['tipos_creados']) ? 1 : 0, $d['sembrado_por'],
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
/**
 * Los tipos de extintor que dio de alta la siembra.
 *
 * Sólo cuenta lo que quedó anotado. El nombre no sirve como prueba: «PQS» o
 * «CO2» los tiene cualquier catálogo, y que un tipo se quede sin usarse
 * después de la limpieza no lo vuelve de la demostración — puede llevar ahí
 * desde antes. Sin registro que diga que la siembra los creó, no se toca
 * ninguno.
 *
 * Cada uno viene con `en_uso`: mientras los extintores sembrados sigan en pie
 * todos estarán en uso, y sólo quedan libres al quitarlos. Por eso quien
 * borra vuelve a preguntar después, con `$soloLibres`, y se lleva únicamente
 * los que para entonces no use nadie.
 */
function huellaTiposSembrados(PDO $pdo, bool $soloLibres = false): array {
    if (!huellaHayTabla($pdo)) return [];
    try {
        huellaAlDia($pdo);
        $losCreo = (int) $pdo->query("SELECT COUNT(*) FROM demo_siembra WHERE tipos_creados = 1")->fetchColumn();
        if (!$losCreo) return [];

        $nombres = array_map(fn($t) => mb_strtoupper($t['nombre']), TIPOS_ESTANDAR);
        $marcas  = implode(',', array_fill(0, count($nombres), '?'));
        $st = $pdo->prepare("
            SELECT t.id, t.nombre,
                   EXISTS (SELECT 1 FROM extintores x WHERE x.tipo = t.id) AS en_uso
            FROM tipos_extintores t
            WHERE t.nombre IN ($marcas)
            ORDER BY t.id
        ");
        $st->execute($nombres);
        $tipos = $st->fetchAll(PDO::FETCH_ASSOC);
        return $soloLibres ? array_values(array_filter($tipos, fn($t) => !$t['en_uso'])) : $tipos;
    } catch (Exception $e) { return []; }
}

/**
 * ¿El usuario gerente lo creó la siembra?
 *
 * Devuelve true sólo si quedó anotado que lo creó ella. Si ya existía —o si
 * no hay registro— se devuelve false y la pantalla no ofrece borrarlo: un
 * usuario que no creamos no es nuestro para quitarlo.
 */
function huellaGerenteEsNuestro(PDO $pdo): bool {
    if (!huellaHayTabla($pdo)) return false;
    try {
        huellaAlDia($pdo);
        return (bool) $pdo->query("SELECT COUNT(*) FROM demo_siembra WHERE gerente_creado = 1")->fetchColumn();
    } catch (Exception $e) { return false; }
}

function huellaDeEmpresa(PDO $pdo, array $centro, int $empresaId): array {
    [$cond, $params] = huellaCondicion($centro);

    // ── Vía exacta: lo que el sembrador dejó anotado ──
    $registro = null;
    if (huellaHayTabla($pdo)) {
        huellaAlDia($pdo);
        $st = $pdo->prepare("SELECT * FROM demo_siembra WHERE empresa_id = ? ORDER BY id");
        $st->execute([$empresaId]);
        $filas = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($filas) $registro = $filas;
    }

    $origen        = 'indicios';
    $empresaCreada = null;   // sin registro no se puede saber; se deduce del resto
    $sembradoEn    = null;
    $gerenteId     = null;
    $tiposCreados  = false;
    $rangos        = [];

    if ($registro) {
        $origen        = 'registro';
        $empresaCreada = (bool) array_sum(array_column($registro, 'empresa_creada'));
        $sembradoEn    = $registro[0]['sembrado_en'];
        $tiposCreados  = (bool) array_sum(array_column($registro, 'tipos_creados'));
        foreach ($registro as $r) {
            // El gerente sólo cuenta si la asignación la creó la siembra
            if (!empty($r['gerente_asignado']) && $r['gerente_id']) $gerenteId = (int) $r['gerente_id'];
            if ($r['ext_min'] && $r['ext_max']) $rangos[] = [(int) $r['ext_min'], (int) $r['ext_max']];
        }
    }

    if ($registro && $rangos) {
        // Dentro del rango anotado, y además con la huella: si alguien capturó
        // un extintor de verdad justo en medio, se queda donde está.
        $trozos = []; $p = [];
        foreach ($rangos as [$min, $max]) { $trozos[] = "(x.id BETWEEN ? AND ?)"; $p[] = $min; $p[] = $max; }
        $sqlIds    = "SELECT x.id FROM extintores x
                      WHERE x.empresa_id = ? AND (" . implode(' OR ', $trozos) . ") AND ($cond)";
        $paramsIds = array_merge([$empresaId], $p, $params);
    } elseif ($registro) {
        $sqlIds = null;   // esa siembra no insertó extintores aquí
        $paramsIds = [];
    } else {
        $sqlIds    = "SELECT x.id FROM extintores x WHERE x.empresa_id = ? AND ($cond)";
        $paramsIds = array_merge([$empresaId], $params);
    }

    $ids = [];
    if ($sqlIds) {
        try {
            $st = $pdo->prepare($sqlIds);
            $st->execute($paramsIds);
            $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        } catch (Exception $e) { $ids = []; }
    }

    // ── Un extintor sembrado que ya pasó por el taller es tuyo ──
    // Si lo diste de alta en mantenimiento es porque lo estás usando como un
    // extintor de verdad, aunque lo haya escrito la siembra. Ese no se toca, y
    // se dice aparte para que se vea por qué se quedó.
    $adoptados = 0;
    if ($ids && huellaHayTablaSuelta($pdo, 'extintor_mantenimientos')) {
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        try {
            $st = $pdo->prepare("SELECT DISTINCT extintor_id FROM extintor_mantenimientos WHERE extintor_id IN ($marcas)");
            $st->execute($ids);
            $enTaller = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            if ($enTaller) {
                $ids = array_values(array_diff($ids, $enTaller));
                $adoptados = count($enTaller);
            }
        } catch (Exception $e) { /* sin la tabla, no hay nada que excluir */ }
    }

    $total = huellaContar($pdo, "SELECT COUNT(*) FROM extintores WHERE empresa_id = ?", [$empresaId]);

    $inspecciones = 0;
    if ($ids) {
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $inspecciones = huellaContar($pdo, "SELECT COUNT(*) FROM inspecciones WHERE extintor_id IN ($marcas)", $ids);
    }

    // ── Lo que ya estaba y no se toca ──
    // Cuenta todo lo que puede colgar de una empresa: si algo de esto existe,
    // la empresa no se da de baja aunque se le quiten los extintores sembrados.
    $propios = ['extintores' => $total - count($ids)];
    foreach ([
        'usuarios'     => "SELECT COUNT(*) FROM usuarios WHERE empresa_id = ?",
        'reportes'     => "SELECT COUNT(*) FROM reportes_mensuales WHERE empresa_id = ?",
    ] as $clave => $sql) {
        $propios[$clave] = huellaContar($pdo, $sql, [$empresaId]);
    }
    foreach ([
        'cotizaciones' => ['cotizaciones', "SELECT COUNT(*) FROM cotizaciones WHERE empresa_id = ?"],
        'plantillas'   => ['plantillas_inspeccion', "SELECT COUNT(*) FROM plantillas_inspeccion WHERE empresa_id = ?"],
        'reportes_ins' => ['reportes_inspeccion', "SELECT COUNT(*) FROM reportes_inspeccion WHERE empresa_id = ?"],
    ] as $clave => [$tabla, $sql]) {
        $propios[$clave] = huellaHayTablaSuelta($pdo, $tabla) ? huellaContar($pdo, $sql, [$empresaId]) : 0;
    }
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
        'adoptados'      => $adoptados,
        'gerente_id'     => $gerenteId,
        'tipos_creados'  => $tiposCreados,
        'propios'        => $propios,
        'borrar_empresa' => !$quedaAlgo && count($ids) > 0,
    ];
}
