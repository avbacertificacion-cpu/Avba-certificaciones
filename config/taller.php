<?php
/**
 * El taller, compartido.
 *
 * Las tablas del módulo de mantenimiento y las piezas para dar de alta un
 * movimiento viven aquí porque dos sitios las necesitan: la pantalla de
 * Mantenimiento de Extintores (api/mantenimientos.php) y el guardado de una
 * inspección (api/inspecciones.php), que al marcar PO mete el extintor al
 * taller sin que haya que capturarlo dos veces.
 *
 * Estaban dentro de api/mantenimientos.php, que empieza negando el paso a
 * quien no sea administrador; un inspector no puede incluir ese archivo. Aquí
 * no hay control de acceso: cada API pone el suyo antes de llamar.
 */

function columnaExiste(PDO $pdo, string $tabla, string $columna): bool {
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `$tabla` LIKE ?");
        $st->execute([$columna]);
        return (bool) $st->fetch();
    } catch (Exception $e) { return false; }
}

function asegurarTablas($pdo) {
    // ── Catálogo de motivos ──
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS mantenimiento_tipos (
                id                INT AUTO_INCREMENT PRIMARY KEY,
                nombre            VARCHAR(80) NOT NULL,
                actualiza_recarga TINYINT(1) NOT NULL DEFAULT 0,
                orden             INT NOT NULL DEFAULT 0,
                estado            VARCHAR(20) NOT NULL DEFAULT 'activo',
                created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_tipo_nombre (nombre)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        // Los tres de siempre, sólo si el catálogo está vacío: a partir de ahí
        // manda lo que el usuario tenga dado de alta.
        $hay = (int) $pdo->query("SELECT COUNT(*) FROM mantenimiento_tipos")->fetchColumn();
        if ($hay === 0) {
            $ins = $pdo->prepare("INSERT INTO mantenimiento_tipos (nombre, actualiza_recarga, orden) VALUES (?,?,?)");
            $ins->execute(['MANTENIMIENTO', 0, 1]);
            $ins->execute(['RECARGA',       1, 2]);
            $ins->execute(['GARANTÍA',      0, 3]);
        }
    } catch (Exception $e) { /* las acciones reportarán el error */ }

    // ── Movimientos ──
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS extintor_mantenimientos (
                id               INT AUTO_INCREMENT PRIMARY KEY,
                extintor_id      INT NOT NULL,
                tipo_id          INT DEFAULT NULL,
                fecha_entrada    DATE NOT NULL,
                fecha_devolucion DATE DEFAULT NULL,
                realizado_por    VARCHAR(150) DEFAULT NULL,
                notas            TEXT DEFAULT NULL,
                creado_por       INT DEFAULT NULL,
                created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
                KEY idx_mant_extintor (extintor_id),
                KEY idx_mant_entrada (fecha_entrada)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) { /* las acciones reportarán el error */ }

    // ── De la versión anterior: se hablaba de salir de planta y volver ──
    try {
        if (columnaExiste($pdo, 'extintor_mantenimientos', 'fecha_salida')) {
            $pdo->exec("ALTER TABLE extintor_mantenimientos CHANGE fecha_salida fecha_entrada DATE NOT NULL");
        }
        if (columnaExiste($pdo, 'extintor_mantenimientos', 'fecha_retorno')) {
            $pdo->exec("ALTER TABLE extintor_mantenimientos CHANGE fecha_retorno fecha_devolucion DATE DEFAULT NULL");
        }
        if (!columnaExiste($pdo, 'extintor_mantenimientos', 'tipo_id')) {
            $pdo->exec("ALTER TABLE extintor_mantenimientos ADD COLUMN tipo_id INT DEFAULT NULL");
        }
        // El motivo era texto fijo; ahora apunta al catálogo
        if (columnaExiste($pdo, 'extintor_mantenimientos', 'tipo')) {
            // Si algún movimiento antiguo traía un motivo que no está en el
            // catálogo, se da de alta: ningún registro debe quedarse sin decir
            // por qué entró el extintor.
            $pdo->exec("
                INSERT IGNORE INTO mantenimiento_tipos (nombre, actualiza_recarga, orden)
                SELECT DISTINCT UPPER(m.tipo), 0,
                       (SELECT COALESCE(MAX(orden),0) FROM mantenimiento_tipos) + 1
                FROM extintor_mantenimientos m
                WHERE m.tipo IS NOT NULL AND m.tipo <> ''
                  AND NOT EXISTS (SELECT 1 FROM mantenimiento_tipos t WHERE t.nombre = UPPER(m.tipo))
            ");
            $pdo->exec("
                UPDATE extintor_mantenimientos m
                JOIN mantenimiento_tipos t ON t.nombre = UPPER(m.tipo)
                SET m.tipo_id = t.id
                WHERE m.tipo_id IS NULL AND m.tipo IS NOT NULL
            ");
            // Ya migrada, la columna vieja deja de ser obligatoria. Si siguiera
            // siendo NOT NULL sin valor por defecto, cualquier alta nueva
            // fallaría en las bases que vienen de la versión anterior. Se
            // conserva —sin usarse— para no perder el texto original.
            $pdo->exec("ALTER TABLE extintor_mantenimientos MODIFY tipo VARCHAR(20) NULL DEFAULT NULL");
        }
    } catch (Exception $e) { /* si la base no deja renombrar, las acciones lo dirán */ }

    // ── Quiénes han hecho mantenimientos ──
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS mantenimiento_responsables (
                id         INT AUTO_INCREMENT PRIMARY KEY,
                nombre     VARCHAR(150) NOT NULL,
                veces      INT NOT NULL DEFAULT 0,
                ultima_vez DATE DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_responsable (nombre)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $pdo->exec("
            INSERT IGNORE INTO mantenimiento_responsables (nombre, veces, ultima_vez)
            SELECT realizado_por, COUNT(*), MAX(fecha_entrada)
            FROM extintor_mantenimientos
            WHERE realizado_por IS NOT NULL AND realizado_por <> ''
            GROUP BY realizado_por
        ");
    } catch (Exception $e) { /* sin esto, sólo faltan las sugerencias */ }
}

/**
 * Apunta a quien hizo el trabajo, o suma una vez más si ya estaba.
 * El cotejamiento de la base ignora mayúsculas y acentos, así que
 * "Michel Ábalos" y "MICHEL ABALOS" son la misma persona.
 */
function recordarResponsable(PDO $pdo, string $nombre, ?string $fecha): void {
    $nombre = trim($nombre);
    if ($nombre === '') return;
    try {
        $st = $pdo->prepare("
            INSERT INTO mantenimiento_responsables (nombre, veces, ultima_vez)
            VALUES (?, 1, ?)
            ON DUPLICATE KEY UPDATE
                veces = veces + 1,
                ultima_vez = GREATEST(COALESCE(ultima_vez, '1900-01-01'), COALESCE(VALUES(ultima_vez), '1900-01-01'))
        ");
        $st->execute([mb_substr($nombre, 0, 150), $fecha]);
    } catch (Exception $e) { /* no vale la pena romper el guardado por esto */ }
}

function fechaValida($v): ?string {
    $v = trim((string) $v);
    if ($v === '') return null;
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : null;
}

/** ¿Ya existe el registro del taller? Si nunca se ha usado, no hay nada que cerrar. */
function tablaTallerExiste(PDO $pdo): bool {
    try {
        $st = $pdo->prepare("SHOW TABLES LIKE ?");
        $st->execute(['extintor_mantenimientos']);
        return (bool) $st->fetchColumn();
    } catch (Exception $e) { return false; }
}
