<?php
/**
 * Preferencias configurables por empresa.
 *
 * La columna `empresas.mostrar_alertas` se crea automáticamente desde las
 * pantallas de administración, para no depender de migraciones manuales.
 * Las lecturas son defensivas: si la columna todavía no existe, se asume
 * el valor por defecto (mostrar alertas), de modo que nada se rompe.
 */

/** ¿Existe ya la columna? (se consulta una sola vez por petición) */
function empresaTieneColumnaAlertas(PDO $pdo, bool $revisarDeNuevo = false): bool {
    static $existe = null;
    if ($existe !== null && !$revisarDeNuevo) return $existe;
    try {
        $col = $pdo->query("SHOW COLUMNS FROM empresas LIKE 'mostrar_alertas'")->fetch(PDO::FETCH_ASSOC);
        $existe = (bool) $col;
    } catch (Exception $e) {
        $existe = false;
    }
    return $existe;
}

/** Crea la columna si falta. Llamar sólo desde pantallas de administración. */
function asegurarColumnaAlertas(PDO $pdo): void {
    if (empresaTieneColumnaAlertas($pdo)) return;
    try {
        $pdo->exec("ALTER TABLE empresas ADD COLUMN mostrar_alertas TINYINT(1) NOT NULL DEFAULT 1");
        empresaTieneColumnaAlertas($pdo, true); // refrescar la caché
    } catch (Exception $e) {
        // Si no se puede crear, el sistema sigue funcionando con el valor por defecto.
    }
}

/** ¿Esta empresa muestra la sección de alertas a su cliente? Por defecto: sí. */
function empresaMuestraAlertas(PDO $pdo, $empresa_id): bool {
    if (!$empresa_id || !empresaTieneColumnaAlertas($pdo)) return true;
    try {
        $st = $pdo->prepare("SELECT mostrar_alertas FROM empresas WHERE id = ?");
        $st->execute([$empresa_id]);
        $valor = $st->fetchColumn();
        return $valor === false ? true : ((int) $valor === 1);
    } catch (Exception $e) {
        return true;
    }
}
