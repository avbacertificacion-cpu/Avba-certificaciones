<?php
/*
 * Archivo muerto: catálogo, descargas y requisiciones de recuperación.
 *
 * Modo 'inmediato' (Propuesta A): los archivos se descargan al momento.
 * Modo 'frio' (Propuesta B): para descargar se hace una requisición; un
 * coordinador la aprueba; el sistema pide al almacenamiento en frío que
 * "descongele" cada archivo (12 h estándar / 48 h económica) y, cuando todos
 * están listos, avisa y habilita la descarga durante N días.
 *
 * Los archivos se ubican por su ruta:
 *   archivo-muerto/AÑO/CLAVE - Nombre de la obra/Carpeta/archivo.ext
 * Se pueden subir desde el sistema o directamente al bucket (por ejemplo con
 * rclone) y luego "Sincronizar catálogo" los registra.
 */
declare(strict_types=1);

function rutasArchivoMuerto(): array
{
    return [
        'archivo/obras'       => ['GET', 'rutaAmObras'],
        'archivo/catalogo'    => ['GET', 'rutaAmCatalogo'],
        'archivo/descargar'   => ['GET', 'rutaAmDescargar'],
        'archivo/solicitar'   => ['POST', 'rutaAmSolicitar'],
        'archivo/aprobar'     => ['POST', 'rutaAmAprobar'],
        'archivo/rechazar'    => ['POST', 'rutaAmRechazar'],
        'archivo/registrar'   => ['POST', 'rutaAmRegistrar'],
        'archivo/sincronizar' => ['POST', 'rutaAmSincronizar'],
    ];
}

function modoFrio(): bool
{
    return conf('archivo_muerto.modo', 'inmediato') === 'frio';
}

function prefijoArchivo(): string
{
    return rtrim((string) conf('archivo_muerto.prefijo', 'archivo-muerto/'), '/') . '/';
}

/** Interpreta la ruta de un archivo del archivo muerto. */
function interpretarClave(string $clave): ?array
{
    $prefijo = prefijoArchivo();
    if (!str_starts_with($clave, $prefijo)) return null;
    $partes = array_values(array_filter(explode('/', substr($clave, strlen($prefijo))), fn($p) => $p !== ''));
    if (!$partes) return null;
    $nombre = array_pop($partes);
    $anio = isset($partes[0]) && preg_match('/^(19|20)\d{2}$/', $partes[0]) ? (int) array_shift($partes) : null;
    $obra = $partes[0] ?? 'Sin obra';
    $carpeta = count($partes) > 1 ? implode(' / ', array_slice($partes, 1)) : 'General';
    [$obraClave, $obraNombre] = str_contains($obra, ' - ') ? array_map('trim', explode(' - ', $obra, 2)) : [$obra, ''];
    return [
        'anio' => $anio, 'obra_clave' => mb_substr($obraClave, 0, 80), 'obra_nombre' => mb_substr($obraNombre, 0, 255),
        'carpeta' => mb_substr($carpeta, 0, 120), 'nombre' => mb_substr($nombre, 0, 255), 'ext' => extension($nombre),
    ];
}

function registrarEnCatalogo(string $clave, int $tamano, ?string $fecha): bool
{
    $info = interpretarClave($clave);
    if (!$info) return false;
    $hash = sha1($clave);
    if (valor('SELECT id FROM am_archivos WHERE clave_hash = ?', [$hash])) {
        q('UPDATE am_archivos SET tamano = ? WHERE clave_hash = ?', [$tamano, $hash]);
        return false;
    }
    q('INSERT INTO am_archivos (clave_almacen, clave_hash, anio, obra_clave, obra_nombre, carpeta, nombre, ext, tamano, fecha) VALUES (?,?,?,?,?,?,?,?,?,?)', [
        $clave, $hash, $info['anio'], $info['obra_clave'], $info['obra_nombre'], $info['carpeta'], $info['nombre'], $info['ext'], $tamano, $fecha,
    ]);
    return true;
}

function filaCatalogo(array $a): array
{
    return [
        'id' => (string) $a['id'], 'anio' => $a['anio'] !== null ? (int) $a['anio'] : null, 'obraClave' => $a['obra_clave'],
        'obraNombre' => $a['obra_nombre'], 'carpeta' => $a['carpeta'], 'nombre' => $a['nombre'], 'ext' => $a['ext'] ?: 'arch',
        'tamano' => (int) $a['tamano'], 'fecha' => $a['fecha'] ?? '',
    ];
}

// ── Catálogo ──────────────────────────────────────────────────────────
function rutaAmObras(): array
{
    exigir('archivo');
    $obras = filas('SELECT anio, obra_clave, obra_nombre, COUNT(*) AS archivos, SUM(tamano) AS tamano
                    FROM am_archivos GROUP BY anio, obra_clave, obra_nombre ORDER BY anio DESC, obra_clave');
    $total = fila('SELECT COUNT(*) AS archivos, COALESCE(SUM(tamano), 0) AS tamano FROM am_archivos');
    return [
        'obras' => array_map(fn($o) => [
            'anio' => $o['anio'] !== null ? (int) $o['anio'] : null, 'clave' => $o['obra_clave'], 'nombre' => $o['obra_nombre'],
            'archivos' => (int) $o['archivos'], 'tamano' => (int) $o['tamano'],
        ], $obras),
        'total' => ['archivos' => (int) $total['archivos'], 'tamano' => (int) $total['tamano']],
        'modo' => modoFrio() ? 'frio' : 'inmediato',
    ];
}

function rutaAmCatalogo(): array
{
    exigir('archivo');
    $where = [];
    $p = [];
    $q = texto($_GET['q'] ?? '', 120);
    if ($q !== '') {
        foreach (preg_split('/\s+/', $q) as $palabra) {
            $where[] = '(nombre LIKE ? OR obra_clave LIKE ? OR obra_nombre LIKE ? OR carpeta LIKE ?)';
            $like = '%' . addcslashes($palabra, '%_\\') . '%';
            array_push($p, $like, $like, $like, $like);
        }
    }
    if (isset($_GET['obra']) && $_GET['obra'] !== '') {
        $where[] = 'obra_clave = ?';
        $p[] = texto($_GET['obra'], 80);
        if (isset($_GET['anio']) && $_GET['anio'] !== '') {
            $where[] = 'anio = ?';
            $p[] = (int) $_GET['anio'];
        }
    }
    if (isset($_GET['carpeta']) && $_GET['carpeta'] !== '') {
        $where[] = 'carpeta = ?';
        $p[] = texto($_GET['carpeta'], 120);
    }
    $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $pagina = max(1, (int) ($_GET['pagina'] ?? 1));
    $porPagina = 200;
    $total = (int) valor("SELECT COUNT(*) FROM am_archivos $sqlWhere", $p);
    $items = filas("SELECT * FROM am_archivos $sqlWhere ORDER BY anio DESC, obra_clave, carpeta, nombre LIMIT $porPagina OFFSET " . (($pagina - 1) * $porPagina), $p);
    $carpetas = [];
    if (!empty($_GET['obra'])) {
        $carpetas = array_column(filas('SELECT DISTINCT carpeta FROM am_archivos WHERE obra_clave = ? ORDER BY carpeta', [texto($_GET['obra'], 80)]), 'carpeta');
    }
    return ['items' => array_map('filaCatalogo', $items), 'total' => $total, 'pagina' => $pagina, 'paginas' => (int) ceil($total / $porPagina), 'carpetas' => $carpetas];
}

function rutaAmDescargar(): array
{
    $u = requerirUsuario();
    exigir('archivo');
    $a = fila('SELECT * FROM am_archivos WHERE id = ?', [(int) ($_GET['id'] ?? 0)]);
    if (!$a) fallar('Archivo no encontrado.', 404);
    if (modoFrio()) {
        $req = fila("SELECT r.* FROM am_requisiciones r JOIN am_requisicion_archivos ra ON ra.requisicion_id = r.id
                     WHERE ra.am_archivo_id = ? AND r.usuario_id = ? AND r.estado IN ('restaurando','disponible') ORDER BY r.id DESC LIMIT 1", [$a['id'], $u['id']]);
        if ($req) $req = revisarRequisicion($req, true);
        if (!$req || $req['estado'] !== 'disponible') fallar('Este archivo está en almacenamiento en frío. Haz una requisición para recuperarlo.', 409);
    }
    registrar('descargó del archivo muerto', null, $a['obra_clave'] . ' · ' . $a['nombre']);
    $mime = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'mp4' => 'video/mp4'][$a['ext']] ?? 'application/octet-stream';
    almacenArchivo()->entregar($a['clave_almacen'], $a['nombre'], $mime, ($_GET['modo'] ?? '') !== 'ver');
}

// ── Requisiciones ─────────────────────────────────────────────────────
function rutaAmSolicitar(array $e): array
{
    $u = requerirUsuario();
    exigir('archivo');
    if (!modoFrio()) fallar('En este sistema el archivo muerto se descarga directamente; no hace falta requisición.');
    $ids = array_slice(array_values(array_unique(array_map('intval', (array) ($e['archivos'] ?? [])))), 0, 5000);
    $motivo = texto($e['motivo'] ?? '', 500);
    $prioridad = ($e['prioridad'] ?? '') === 'economica' ? 'economica' : 'estandar';
    if (!$ids) fallar('Elige al menos un archivo.');
    if ($motivo === '') fallar('Escribe el motivo de la solicitud.');
    [$in, $p] = enLista($ids);
    if ((int) valor("SELECT COUNT(*) FROM am_archivos WHERE id IN $in", $p) !== count($ids)) fallar('Algunos archivos ya no existen en el catálogo.');

    $n = (int) valor('SELECT COALESCE(MAX(id), 0) FROM am_requisiciones') + 1;
    $folio = 'REQ-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    q('INSERT INTO am_requisiciones (folio, usuario_id, prioridad, motivo) VALUES (?, ?, ?, ?)', [$folio, $u['id'], $prioridad, $motivo]);
    $reqId = ultimoId();
    foreach ($ids as $id) q('INSERT INTO am_requisicion_archivos (requisicion_id, am_archivo_id) VALUES (?, ?)', [$reqId, $id]);
    registrar('solicitó del archivo muerto', null, "$folio · " . count($ids) . ' archivo(s)');

    if (puede('archivo_admin')) {
        iniciarRestauracion($reqId);
    } else {
        foreach (correosConCapacidad('archivo_admin') as $correo) {
            enviarCorreo($correo, "Requisición $folio por aprobar", "{$u['nombre']} solicitó recuperar " . count($ids) . " archivo(s) del archivo muerto.\nMotivo: $motivo");
        }
    }
    return ['id' => (string) $reqId, 'folio' => $folio];
}

function rutaAmAprobar(array $e): array
{
    exigir('archivo_admin');
    $r = fila("SELECT * FROM am_requisiciones WHERE id = ? AND estado = 'pendiente'", [(int) ($e['id'] ?? 0)]);
    if (!$r) fallar('La requisición no existe o ya fue atendida.', 404);
    iniciarRestauracion((int) $r['id']);
    registrar('aprobó la requisición del archivo muerto', null, $r['folio']);
    return [];
}

function rutaAmRechazar(array $e): array
{
    $u = requerirUsuario();
    exigir('archivo_admin');
    $r = fila("SELECT * FROM am_requisiciones WHERE id = ? AND estado = 'pendiente'", [(int) ($e['id'] ?? 0)]);
    if (!$r) fallar('La requisición no existe o ya fue atendida.', 404);
    $nota = texto($e['nota'] ?? '', 500);
    if ($nota === '') fallar('Escribe el motivo del rechazo.');
    q("UPDATE am_requisiciones SET estado = 'rechazada', nota = ?, aprobada_por = ?, aprobada = NOW() WHERE id = ?", [$nota, $u['id'], $r['id']]);
    registrar('rechazó la requisición del archivo muerto', null, $r['folio']);
    $solicitante = fila('SELECT email FROM usuarios WHERE id = ?', [$r['usuario_id']]);
    if ($solicitante) enviarCorreo($solicitante['email'], "Requisición {$r['folio']} rechazada", "Tu requisición fue rechazada.\nMotivo: $nota");
    return [];
}

function iniciarRestauracion(int $reqId): void
{
    $u = usuarioActual();
    $r = fila('SELECT * FROM am_requisiciones WHERE id = ?', [$reqId]);
    $dias = max(1, (int) conf('archivo_muerto.dias_disponible', 7));
    $almacen = almacenArchivo();
    $items = filas('SELECT a.clave_almacen FROM am_requisicion_archivos ra JOIN am_archivos a ON a.id = ra.am_archivo_id WHERE ra.requisicion_id = ?', [$reqId]);
    foreach ($items as $i) $almacen->restaurar($i['clave_almacen'], $dias, $r['prioridad']);

    if ($almacen instanceof AlmacenLocal) {
        // Sin almacenamiento en frío real: se simula la espera (sólo pruebas).
        $sim = (array) conf('archivo_muerto.simular_minutos', [1, 2]);
        $minutos = (int) ($r['prioridad'] === 'economica' ? ($sim[1] ?? 2) : ($sim[0] ?? 1));
        $listo = date('Y-m-d H:i:s', time() + $minutos * 60);
    } else {
        $listo = date('Y-m-d H:i:s', time() + ($r['prioridad'] === 'economica' ? 48 : 12) * 3600);
    }
    q("UPDATE am_requisiciones SET estado = 'restaurando', aprobada_por = ?, aprobada = NOW(), listo_estimado = ? WHERE id = ?", [$u['id'] ?? null, $listo, $reqId]);
}

/** Revisa en el almacenamiento si una requisición ya está lista o venció. */
function revisarRequisicion(array $r, bool $forzar = false): array
{
    $ahora = time();
    if ($r['estado'] === 'disponible' && $r['expira'] && strtotime($r['expira']) < $ahora) {
        q("UPDATE am_requisiciones SET estado = 'expirada' WHERE id = ?", [$r['id']]);
        $r['estado'] = 'expirada';
        return $r;
    }
    if ($r['estado'] !== 'restaurando') return $r;
    if (!$forzar && $r['ultima_revision'] && $ahora - strtotime($r['ultima_revision']) < 300) return $r;

    $almacen = almacenArchivo();
    $listo = false;
    if ($almacen instanceof AlmacenLocal) {
        $listo = $r['listo_estimado'] && strtotime($r['listo_estimado']) <= $ahora;
    } else {
        $pendientes = filas('SELECT ra.am_archivo_id, a.clave_almacen FROM am_requisicion_archivos ra JOIN am_archivos a ON a.id = ra.am_archivo_id
                             WHERE ra.requisicion_id = ? AND ra.restaurado = 0 LIMIT 200', [$r['id']]);
        foreach ($pendientes as $p) {
            if ($almacen->estadoRestauracion($p['clave_almacen']) === 'listo') {
                q('UPDATE am_requisicion_archivos SET restaurado = 1 WHERE requisicion_id = ? AND am_archivo_id = ?', [$r['id'], $p['am_archivo_id']]);
            }
        }
        $listo = (int) valor('SELECT COUNT(*) FROM am_requisicion_archivos WHERE requisicion_id = ? AND restaurado = 0', [$r['id']]) === 0;
    }
    q('UPDATE am_requisiciones SET ultima_revision = NOW() WHERE id = ?', [$r['id']]);
    if ($listo) {
        $expira = date('Y-m-d H:i:s', $ahora + max(1, (int) conf('archivo_muerto.dias_disponible', 7)) * 86400);
        q("UPDATE am_requisiciones SET estado = 'disponible', disponible = NOW(), expira = ? WHERE id = ?", [$expira, $r['id']]);
        $r['estado'] = 'disponible';
        $r['disponible'] = ahora();
        $r['expira'] = $expira;
        if (!(int) $r['aviso_enviado']) {
            $s = fila('SELECT email FROM usuarios WHERE id = ?', [$r['usuario_id']]);
            if ($s) enviarCorreo($s['email'], "Requisición {$r['folio']} lista para descargar",
                "Los archivos de tu requisición {$r['folio']} ya se recuperaron y puedes descargarlos hasta el " . date('d/m/Y', strtotime($expira)) . '.');
            q('UPDATE am_requisiciones SET aviso_enviado = 1 WHERE id = ?', [$r['id']]);
        }
    }
    return $r;
}

/** Requisiciones para el estado inicial: todas (quien aprueba) o las propias. */
function requisicionesVisibles(): array
{
    if (!puede('archivo') || !modoFrio()) return [];
    $u = usuarioActual();
    $lista = puede('archivo_admin')
        ? filas('SELECT * FROM am_requisiciones ORDER BY id DESC LIMIT 200')
        : filas('SELECT * FROM am_requisiciones WHERE usuario_id = ? ORDER BY id DESC LIMIT 200', [$u['id']]);
    $revisadas = 0;
    $salida = [];
    foreach ($lista as $r) {
        if ($r['estado'] === 'restaurando' || $r['estado'] === 'disponible') {
            try {
                if ($revisadas++ < 10) $r = revisarRequisicion($r);
            } catch (Throwable $ex) {
                error_log('[CHC] Revisión de requisición: ' . $ex->getMessage());
            }
        }
        $items = filas('SELECT a.* FROM am_requisicion_archivos ra JOIN am_archivos a ON a.id = ra.am_archivo_id WHERE ra.requisicion_id = ? ORDER BY a.nombre', [$r['id']]);
        $salida[] = [
            'id' => (string) $r['id'], 'folio' => $r['folio'], 'usuarioId' => idStr($r['usuario_id']), 'prioridad' => $r['prioridad'],
            'motivo' => $r['motivo'], 'estado' => $r['estado'], 'nota' => $r['nota'],
            'solicitada' => iso($r['solicitada']), 'aprobada' => iso($r['aprobada']), 'aprobadaPor' => idStr($r['aprobada_por']),
            'listoEstimado' => iso($r['listo_estimado']), 'disponible' => iso($r['disponible']), 'expira' => iso($r['expira']),
            'archivos' => array_map('filaCatalogo', $items),
        ];
    }
    return $salida;
}

// ── Carga y sincronización (administración del archivo) ──────────────
function rutaAmRegistrar(array $e): array
{
    exigir('archivo_admin');
    $u = usuarioActual();
    $nuevos = 0;
    foreach (array_map('intval', (array) ($e['archivos'] ?? [])) as $id) {
        $a = fila("SELECT * FROM archivos WHERE id = ? AND estado = 'listo' AND destino = 'archivo' AND subido_por = ?", [$id, $u['id']]);
        if (!$a) continue;
        if (registrarEnCatalogo($a['clave_almacen'], (int) $a['tamano'], date('Y-m-d'))) $nuevos++;
        q('DELETE FROM archivos WHERE id = ?', [$id]); // ya vive en el catálogo
    }
    if ($nuevos) registrar('cargó archivos al archivo muerto', null, "$nuevos archivo(s)");
    return ['nuevos' => $nuevos];
}

function rutaAmSincronizar(array $e): array
{
    exigir('archivo_admin');
    $pagina = almacenArchivo()->listar(prefijoArchivo(), !empty($e['siguiente']) ? (string) $e['siguiente'] : null);
    $nuevos = 0;
    foreach ($pagina['objetos'] as $o) {
        if (registrarEnCatalogo($o['clave'], (int) $o['tamano'], $o['fecha'] ?: null)) $nuevos++;
    }
    if (!$pagina['siguiente'] && $nuevos) registrar('sincronizó el catálogo del archivo muerto', null, "$nuevos archivo(s) nuevos");
    return ['revisados' => count($pagina['objetos']), 'nuevos' => $nuevos, 'siguiente' => $pagina['siguiente']];
}
