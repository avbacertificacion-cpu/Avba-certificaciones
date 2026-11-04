<?php
/**
 * Preferencias configurables por empresa.
 *
 * Las columnas se crean automáticamente desde las pantallas de administración,
 * para no depender de migraciones manuales. Las lecturas son defensivas: si la
 * columna todavía no existe, se asume su valor por defecto, de modo que nada
 * se rompe en una base que aún no se ha actualizado.
 */

/** ¿Existe ya esa columna en `empresas`? (se consulta una sola vez por petición) */
function empresaTieneColumna(PDO $pdo, string $columna, bool $revisarDeNuevo = false): bool {
    static $cache = [];
    if (isset($cache[$columna]) && !$revisarDeNuevo) return $cache[$columna];
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM empresas LIKE ?");
        $st->execute([$columna]);
        $cache[$columna] = (bool) $st->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $cache[$columna] = false;
    }
    return $cache[$columna];
}

/** Crea la columna si falta. Llamar sólo desde pantallas de administración. */
function asegurarColumnaEmpresa(PDO $pdo, string $columna, string $definicion): void {
    if (empresaTieneColumna($pdo, $columna)) return;
    try {
        $pdo->exec("ALTER TABLE empresas ADD COLUMN `$columna` $definicion");
        empresaTieneColumna($pdo, $columna, true); // refrescar la caché
    } catch (Exception $e) {
        // Si no se puede crear, el sistema sigue funcionando con el valor por defecto.
    }
}

/** Lee una preferencia booleana de la empresa, con su valor por defecto si falta. */
function empresaPreferencia(PDO $pdo, $empresa_id, string $columna, bool $porDefecto): bool {
    if (!$empresa_id || !empresaTieneColumna($pdo, $columna)) return $porDefecto;
    try {
        $st = $pdo->prepare("SELECT `$columna` FROM empresas WHERE id = ?");
        $st->execute([$empresa_id]);
        $valor = $st->fetchColumn();
        return $valor === false ? $porDefecto : ((int) $valor === 1);
    } catch (Exception $e) {
        return $porDefecto;
    }
}

// ─── Alertas y vencimientos en el panel del cliente (por defecto: sí) ────────
function empresaTieneColumnaAlertas(PDO $pdo, bool $revisarDeNuevo = false): bool {
    return empresaTieneColumna($pdo, 'mostrar_alertas', $revisarDeNuevo);
}

function asegurarColumnaAlertas(PDO $pdo): void {
    asegurarColumnaEmpresa($pdo, 'mostrar_alertas', 'TINYINT(1) NOT NULL DEFAULT 1');
}

/** ¿Esta empresa muestra la sección de alertas a su cliente? Por defecto: sí. */
function empresaMuestraAlertas(PDO $pdo, $empresa_id): bool {
    return empresaPreferencia($pdo, $empresa_id, 'mostrar_alertas', true);
}

// ─── Evidencia fotográfica en el reporte (por defecto: no) ───────────────────
function empresaTieneColumnaFotos(PDO $pdo, bool $revisarDeNuevo = false): bool {
    return empresaTieneColumna($pdo, 'requiere_fotos', $revisarDeNuevo);
}

function asegurarColumnaFotos(PDO $pdo): void {
    asegurarColumnaEmpresa($pdo, 'requiere_fotos', 'TINYINT(1) NOT NULL DEFAULT 0');
}

/**
 * ¿A esta empresa se le piden fotografías al generar el reporte?
 * Es un módulo opcional, así que por defecto está apagado.
 */
function empresaRequiereFotos(PDO $pdo, $empresa_id): bool {
    return empresaPreferencia($pdo, $empresa_id, 'requiere_fotos', false);
}

// ─── Préstamos mostrados al cliente como mantenimiento (por defecto: no) ────
/**
 * Hay clientes a los que no se les dice que un extintor está en préstamo: para
 * ellos el equipo está «en mantenimiento». Con la opción encendida en su
 * planta, todo lo que ve el cliente —su listado, el reporte mensual, la página
 * que abre el QR y el panel del gerente— dice «en mantenimiento» donde el
 * sistema guarda «en préstamo». Es sólo cómo se muestra: el estado guardado no
 * cambia, y el administrador y los inspectores siguen viendo el préstamo.
 */
function asegurarColumnaPrestamo(PDO $pdo): void {
    asegurarColumnaEmpresa($pdo, 'prestamo_como_mantenimiento', 'TINYINT(1) NOT NULL DEFAULT 0');
}

function empresaTieneColumnaPrestamo(PDO $pdo, bool $revisarDeNuevo = false): bool {
    return empresaTieneColumna($pdo, 'prestamo_como_mantenimiento', $revisarDeNuevo);
}

/** ¿A esta planta se le muestran los préstamos como mantenimiento? */
function empresaPrestamoComoMantenimiento(PDO $pdo, $empresa_id): bool {
    return empresaPreferencia($pdo, $empresa_id, 'prestamo_como_mantenimiento', false);
}

/** Las plantas que tienen la opción encendida, para listados de varias. */
function empresasPrestamoComoMantenimiento(PDO $pdo): array {
    if (!empresaTieneColumnaPrestamo($pdo)) return [];
    try {
        return array_map('intval', $pdo->query("SELECT id FROM empresas WHERE prestamo_como_mantenimiento = 1")
                                         ->fetchAll(PDO::FETCH_COLUMN));
    } catch (Exception $e) {
        return [];
    }
}

/** El estado tal como lo ve el cliente. «en_mantenimiento» nunca se guarda. */
function estadoParaCliente(?string $estado, bool $prestamoComoMantenimiento): ?string {
    return ($prestamoComoMantenimiento && $estado === 'en_prestamo') ? 'en_mantenimiento' : $estado;
}

/** Nombre del estado para mostrar. */
function nombreEstado(?string $estado): string {
    return ['activo' => 'Activo', 'inactivo' => 'Inactivo', 'en_prestamo' => 'En préstamo',
            'en_mantenimiento' => 'En mantenimiento'][$estado] ?? ucfirst(str_replace('_', ' ', (string) $estado));
}

// ─── Datos fiscales del cliente (para el presupuesto) ────────────────────────
/**
 * Régimen fiscal y código postal del cliente. Sirven para precargar el
 * presupuesto: el documento guarda su propia copia, pero volver a teclearlos
 * en cada cotización del mismo cliente no tiene sentido.
 */
function asegurarColumnasFiscales(PDO $pdo): void {
    asegurarColumnaEmpresa($pdo, 'regimen_fiscal', "VARCHAR(10) DEFAULT NULL");
    asegurarColumnaEmpresa($pdo, 'cp',             "VARCHAR(10) DEFAULT NULL");
}

/** Columnas fiscales que existen hoy en `empresas`, para armar el SELECT. */
function columnasFiscalesEmpresa(PDO $pdo): array {
    $cols = [];
    foreach (['regimen_fiscal', 'cp'] as $c) {
        if (empresaTieneColumna($pdo, $c)) $cols[] = $c;
    }
    return $cols;
}
