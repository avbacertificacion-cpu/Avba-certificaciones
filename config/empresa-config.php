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
