<?php
/*
 * Roles y permisos. El servidor decide siempre; la interfaz sólo oculta
 * los botones que el rol no puede usar.
 *
 * Capacidades:
 *   ver_todas      ve todas las obras (si no, sólo las asignadas)
 *   obras          crea y edita obras, asigna personal
 *   subir          sube documentos y nuevas versiones
 *   presupuestos   crea y edita presupuestos
 *   reportes       crea reportes fotográficos
 *   aprobar        cambia el estado de los documentos (aprobado, obsoleto…)
 *   eliminar       elimina documentos, presupuestos y reportes
 *   compartir      comparte documentos con otros usuarios
 *   actividad      ve la bitácora de todo el sistema
 *   archivo        consulta el archivo muerto
 *   archivo_admin  aprueba requisiciones y carga archivos al archivo muerto
 *   usuarios       administra usuarios y roles
 */
declare(strict_types=1);

const ROLES = [
    'admin' => [
        'nombre' => 'Administrador',
        'desc'   => 'Todo el sistema, incluidos usuarios y configuración.',
        'caps'   => ['ver_todas', 'obras', 'subir', 'presupuestos', 'reportes', 'aprobar', 'eliminar', 'compartir', 'actividad', 'archivo', 'archivo_admin', 'usuarios'],
    ],
    'direccion' => [
        'nombre' => 'Dirección',
        'desc'   => 'Ve todas las obras, aprueba y consulta la bitácora.',
        'caps'   => ['ver_todas', 'obras', 'subir', 'presupuestos', 'reportes', 'aprobar', 'eliminar', 'compartir', 'actividad', 'archivo', 'archivo_admin'],
    ],
    'coordinador' => [
        'nombre' => 'Coordinador',
        'desc'   => 'Gestiona obras y personal, aprueba documentos y requisiciones.',
        'caps'   => ['ver_todas', 'obras', 'subir', 'presupuestos', 'reportes', 'aprobar', 'eliminar', 'compartir', 'actividad', 'archivo', 'archivo_admin'],
    ],
    'residente' => [
        'nombre' => 'Residente de obra',
        'desc'   => 'Sube y actualiza documentos, presupuestos y reportes en sus obras.',
        'caps'   => ['subir', 'presupuestos', 'reportes', 'compartir', 'archivo'],
    ],
    'supervisor' => [
        'nombre' => 'Supervisor / Calidad',
        'desc'   => 'Revisa y aprueba documentos y sube reportes en sus obras.',
        'caps'   => ['subir', 'reportes', 'aprobar', 'compartir', 'archivo'],
    ],
    'administracion' => [
        'nombre' => 'Administración y compras',
        'desc'   => 'Ve todas las obras; gestiona presupuestos, contratos y estimaciones.',
        'caps'   => ['ver_todas', 'subir', 'presupuestos', 'compartir', 'archivo'],
    ],
    'consulta' => [
        'nombre' => 'Consulta',
        'desc'   => 'Sólo lectura de las obras asignadas.',
        'caps'   => ['archivo'],
    ],
];

const CARPETAS = ['contrato', 'proyecto', 'permisos', 'estimaciones', 'bitacora', 'calidad', 'seguridad', 'informes', 'cierre'];
const ESTADOS_OBRA = ['Planeación', 'En ejecución', 'Suspendida', 'Terminada'];
const ESTADOS_DOC = ['Borrador', 'En revisión', 'Aprobado', 'Obsoleto'];
const ESTADOS_PRES = ['Borrador', 'Enviado al cliente', 'Aprobado', 'Rechazado'];

// ── Usuario de la sesión ──────────────────────────────────────────────
function usuarioActual(bool $recargar = false): ?array
{
    static $u = false;
    if ($u === false || $recargar) {
        $u = null;
        $id = (int) ($_SESSION['usuario_id'] ?? 0);
        if ($id > 0) {
            $f = fila('SELECT id, nombre, email, rol, puesto, activo FROM usuarios WHERE id = ?', [$id]);
            if ($f && (int) $f['activo'] === 1 && isset(ROLES[$f['rol']])) {
                $f['id'] = (int) $f['id'];
                $f['obras'] = array_map('intval', array_column(filas('SELECT obra_id FROM usuario_obra WHERE usuario_id = ?', [$id]), 'obra_id'));
                $u = $f;
            }
        }
    }
    return $u;
}

function requerirUsuario(): array
{
    $u = usuarioActual();
    if (!$u) fallar('Tu sesión terminó. Vuelve a iniciar sesión.', 401);
    return $u;
}

function puede(string $cap): bool
{
    $u = usuarioActual();
    return $u !== null && in_array($cap, ROLES[$u['rol']]['caps'], true);
}

function exigir(string $cap): void
{
    if (!puede($cap)) fallar('No tienes permiso para esta acción.', 403);
}

function puedeVerObra(int $obraId): bool
{
    $u = usuarioActual();
    if (!$u) return false;
    return puede('ver_todas') || in_array($obraId, $u['obras'], true);
}

function exigirObra(int $obraId): array
{
    $o = fila('SELECT * FROM obras WHERE id = ?', [$obraId]);
    if (!$o || !puedeVerObra($obraId)) fallar('No encontramos esta obra o no tienes acceso.', 404);
    return $o;
}

/** Permiso del usuario sobre un documento compartido con él ('ver', 'editar' o null). */
function permisoCompartido(int $docId): ?string
{
    $u = usuarioActual();
    if (!$u) return null;
    $p = valor('SELECT permiso FROM documento_compartidos WHERE documento_id = ? AND usuario_id = ?', [$docId, $u['id']]);
    return $p === null ? null : (string) $p;
}

function puedeVerDocumento(array $doc): bool
{
    return puedeVerObra((int) $doc['obra_id']) || permisoCompartido((int) $doc['id']) !== null;
}

/** ¿Puede subir una nueva versión de este documento? */
function puedeVersionar(array $doc): bool
{
    if (puede('subir') && puedeVerObra((int) $doc['obra_id'])) return true;
    return permisoCompartido((int) $doc['id']) === 'editar';
}

function exigirDocumento(int $docId): array
{
    $d = fila('SELECT * FROM documentos WHERE id = ?', [$docId]);
    if (!$d || !puedeVerDocumento($d)) fallar('No encontramos este documento o no tienes acceso.', 404);
    return $d;
}

// ── Bitácora ──────────────────────────────────────────────────────────
function registrar(string $accion, ?int $obraId = null, string $detalle = ''): void
{
    $u = usuarioActual();
    q('INSERT INTO actividad (usuario_id, accion, obra_id, detalle) VALUES (?, ?, ?, ?)', [
        $u['id'] ?? null, mb_substr($accion, 0, 200), $obraId ?: null, mb_substr($detalle, 0, 500),
    ]);
}
