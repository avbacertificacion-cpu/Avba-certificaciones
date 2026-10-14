<?php
/*
 * Tareas diarias del sistema. Se ejecuta con un "Cron job" de Hostinger, una vez al día:
 *
 *     /usr/bin/php /home/USUARIO/public_html/cron/diario.php
 *
 * (La ruta exacta la muestra Hostinger en Avanzado → Cron jobs.)
 *
 * Qué hace:
 *   1. Avisa por correo de los documentos que vencen en 30, 7 y 1 día, hoy, o que vencieron ayer.
 *   2. Revisa las requisiciones del archivo muerto: marca las que ya se recuperaron
 *      (y avisa) y cierra las que ya pasaron su plazo de descarga.
 *   3. Borra archivos que se subieron pero nunca se usaron (subidas canceladas).
 *   4. Limpia los registros viejos de intentos de inicio de sesión.
 *
 * Sólo funciona desde la línea de comandos: desde el navegador no hace nada.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/nucleo.php';
require __DIR__ . '/../src/permisos.php';
require __DIR__ . '/../src/almacen.php';
require __DIR__ . '/../src/correo.php';
require __DIR__ . '/../src/rutas.php';
require __DIR__ . '/../src/rutas_archivo.php';

if (!instalado()) {
    fwrite(STDERR, "El sistema no está instalado todavía.\n");
    exit(1);
}
arranque();

function bitacora(string $texto): void
{
    echo '[' . date('Y-m-d H:i:s') . "] $texto\n";
}

// ── 1. Vencimientos ───────────────────────────────────────────────────
// Cada persona recibe un solo correo con todos sus documentos por vencer.
function avisarVencimientos(): void
{
    $hitos = [30 => 'vence en 30 días', 7 => 'vence en 7 días', 1 => 'vence mañana', 0 => 'vence HOY', -1 => 'venció ayer'];
    $fechas = [];
    foreach (array_keys($hitos) as $dias) $fechas[date('Y-m-d', strtotime("$dias days"))] = $dias;
    [$in, $p] = [implode(',', array_fill(0, count($fechas), '?')), array_keys($fechas)];
    $docs = filas("SELECT d.id, d.nombre, d.vence, d.obra_id, o.clave, o.nombre AS obra, o.residente_id
                   FROM documentos d JOIN obras o ON o.id = d.obra_id
                   WHERE d.estado <> 'Obsoleto' AND d.vence IN ($in) ORDER BY d.vence", $p);
    if (!$docs) {
        bitacora('Vencimientos: ninguno hoy.');
        return;
    }
    // Quién se entera: el personal asignado a la obra y quienes coordinan todas las obras.
    $coordinan = array_keys(array_filter(ROLES, fn($r) => in_array('ver_todas', $r['caps'], true) && in_array('aprobar', $r['caps'], true)));
    $marcas = implode(',', array_fill(0, count($coordinan), '?'));
    $generales = array_column(filas("SELECT email FROM usuarios WHERE activo = 1 AND rol IN ($marcas)", $coordinan), 'email');

    $porPersona = [];
    foreach ($docs as $d) {
        $dias = $fechas[$d['vence']];
        $linea = "• {$d['nombre']} — {$d['clave']} · {$d['obra']}: {$hitos[$dias]} (" . date('d/m/Y', strtotime($d['vence'])) . ')';
        $equipo = array_column(filas('SELECT u.email FROM usuarios u JOIN usuario_obra uo ON uo.usuario_id = u.id
                                      WHERE uo.obra_id = ? AND u.activo = 1', [$d['obra_id']]), 'email');
        foreach (array_unique(array_merge($equipo, $generales)) as $email) $porPersona[$email][] = $linea;
    }
    $enviados = 0;
    foreach ($porPersona as $email => $lineas) {
        $texto = "Estos documentos necesitan atención (renovar, actualizar o marcar como obsoletos):\n\n" . implode("\n", $lineas);
        if (enviarCorreo($email, 'Documentos por vencer (' . count($lineas) . ')', $texto)) $enviados++;
    }
    bitacora('Vencimientos: ' . count($docs) . ' documento(s); ' . $enviados . ' correo(s) enviados' . (conf('correo.activo') ? '.' : ' (el correo está desactivado en config.php).'));
}

// ── 2. Requisiciones del archivo muerto ───────────────────────────────
function revisarRequisiciones(): void
{
    $lista = filas("SELECT * FROM am_requisiciones WHERE estado IN ('restaurando', 'disponible')");
    $listas = 0;
    $vencidas = 0;
    foreach ($lista as $r) {
        try {
            $nuevo = revisarRequisicion($r, true);
            if ($r['estado'] === 'restaurando' && $nuevo['estado'] === 'disponible') $listas++;
            if ($nuevo['estado'] === 'expirada') $vencidas++;
        } catch (Throwable $e) {
            bitacora("Requisición {$r['folio']}: no se pudo revisar ({$e->getMessage()}).");
        }
    }
    bitacora('Archivo muerto: ' . count($lista) . " requisición(es) revisadas, $listas lista(s), $vencidas expirada(s).");
}

// ── 3. Subidas que nunca se usaron ────────────────────────────────────
function limpiarHuerfanos(): void
{
    $huerfanos = filas('SELECT * FROM archivos WHERE vinculado = 0 AND creado < NOW() - INTERVAL 1 DAY LIMIT 2000');
    $borrados = 0;
    foreach ($huerfanos as $a) {
        // Si el mismo archivo ya está en el catálogo del archivo muerto, no se toca el almacenamiento.
        if ($a['destino'] === 'archivo' && valor('SELECT id FROM am_archivos WHERE clave_hash = ?', [sha1($a['clave_almacen'])])) {
            q('DELETE FROM archivos WHERE id = ?', [$a['id']]);
        } else {
            borrarArchivo((int) $a['id']);
        }
        $borrados++;
    }
    // Restos de subidas interrumpidas en el almacenamiento local.
    $restos = 0;
    foreach ([conf('almacen.driver') === 'local' ? conf('almacen.local_ruta') : null,
              conf('archivo_muerto.driver') === 'local' ? conf('archivo_muerto.local_ruta') : null] as $ruta) {
        if (!$ruta || !is_dir((string) $ruta)) continue;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator((string) $ruta, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.subiendo') && $f->getMTime() < time() - 86400) {
                @unlink($f->getPathname());
                $restos++;
            }
        }
    }
    bitacora("Limpieza: $borrados archivo(s) sin usar borrados" . ($restos ? ", $restos resto(s) de subidas interrumpidas." : '.'));
}

// ── 4. Intentos de inicio de sesión ───────────────────────────────────
function limpiarIntentos(): void
{
    $n = q('DELETE FROM intentos_login WHERE creado < NOW() - INTERVAL 1 DAY')->rowCount();
    bitacora("Inicio de sesión: $n intento(s) viejos borrados.");
}

$errores = 0;
foreach (['avisarVencimientos', 'revisarRequisiciones', 'limpiarHuerfanos', 'limpiarIntentos'] as $tarea) {
    try {
        $tarea();
    } catch (Throwable $e) {
        $errores++;
        bitacora("ERROR en $tarea: " . $e->getMessage());
    }
}
exit($errores ? 1 : 0);
