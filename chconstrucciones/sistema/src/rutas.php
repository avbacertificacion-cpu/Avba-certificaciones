<?php
/*
 * Rutas de la API: sesión, estado inicial, obras, documentos (con versiones
 * y compartir), presupuestos, reportes fotográficos, usuarios y archivos.
 * Cada función recibe el JSON de entrada y devuelve un arreglo de respuesta.
 */
declare(strict_types=1);

function rutasApi(): array
{
    return [
        'auth/estado'          => ['GET', 'rutaAuthEstado', true],
        'auth/entrar'          => ['POST', 'rutaAuthEntrar', true],
        'auth/salir'           => ['POST', 'rutaAuthSalir'],
        'auth/clave'           => ['POST', 'rutaAuthClave'],
        'estado'               => ['GET', 'rutaEstado'],
        'obras/guardar'        => ['POST', 'rutaObraGuardar'],
        'obras/equipo'         => ['POST', 'rutaObraEquipo'],
        'documentos/crear'     => ['POST', 'rutaDocCrear'],
        'documentos/version'   => ['POST', 'rutaDocVersion'],
        'documentos/editar'    => ['POST', 'rutaDocEditar'],
        'documentos/estado'    => ['POST', 'rutaDocEstado'],
        'documentos/eliminar'  => ['POST', 'rutaDocEliminar'],
        'documentos/compartir' => ['POST', 'rutaDocCompartir'],
        'presupuestos/guardar' => ['POST', 'rutaPresGuardar'],
        'presupuestos/eliminar'=> ['POST', 'rutaPresEliminar'],
        'reportes/crear'       => ['POST', 'rutaRepCrear'],
        'reportes/eliminar'    => ['POST', 'rutaRepEliminar'],
        'usuarios/guardar'     => ['POST', 'rutaUsuarioGuardar'],
        'archivos/iniciar'     => ['POST', 'rutaArchivoIniciar'],
        'archivos/recibir'     => ['PUT', 'rutaArchivoRecibir'],
        'archivos/confirmar'   => ['POST', 'rutaArchivoConfirmar'],
        'archivos/descargar'   => ['GET', 'rutaArchivoDescargar'],
        'sistema/diagnostico'  => ['GET', 'rutaDiagnostico'],
        'sistema/cors'         => ['POST', 'rutaConfigurarCors'],
    ] + rutasArchivoMuerto();
}

// ── Utilidades de consulta ────────────────────────────────────────────
/** Marcadores para "IN (...)"; con lista vacía devuelve una condición falsa. */
function enLista(array $ids): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    return $ids ? ['(' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids] : ['(NULL)', []];
}

function idStr($v): ?string
{
    return $v === null ? null : (string) $v;
}

function etiquetasLista(string $s): array
{
    return array_values(array_filter(array_map('trim', explode(',', $s)), fn($t) => $t !== ''));
}

function etiquetasTexto($v): string
{
    $lista = is_array($v) ? $v : explode(',', (string) $v);
    $lista = array_slice(array_values(array_unique(array_filter(array_map(fn($t) => texto($t, 40), $lista)))), 0, 15);
    return implode(',', $lista);
}

// ════════════════════════════════════════════════════════════════════
// Sesión
// ════════════════════════════════════════════════════════════════════
function datosSesion(): array
{
    $u = usuarioActual();
    $roles = [];
    foreach (ROLES as $k => $r) $roles[$k] = ['nombre' => $r['nombre'], 'desc' => $r['desc'], 'caps' => $r['caps']];
    return [
        'instalado' => true,
        'sesion'    => $u !== null,
        'csrf'      => $_SESSION['csrf'],
        'usuario'   => $u ? [
            'id' => (string) $u['id'], 'nombre' => $u['nombre'], 'email' => $u['email'], 'rol' => $u['rol'],
            'puesto' => $u['puesto'], 'obras' => array_map('strval', $u['obras']),
            'permisos' => ROLES[$u['rol']]['caps'],
        ] : null,
        'roles'  => $roles,
        'config' => [
            'nombre'         => conf('app.nombre', 'CH Arquitectura y Construcción'),
            'maxMb'          => (int) conf('app.max_mb', 2048),
            'archivoModo'    => conf('archivo_muerto.modo', 'inmediato') === 'frio' ? 'frio' : 'inmediato',
            'diasDisponible' => (int) conf('archivo_muerto.dias_disponible', 7),
            'gbIncluidos'    => (int) conf('archivo_muerto.gb_incluidos', 100),
        ],
    ];
}

function rutaAuthEstado(): array
{
    return datosSesion();
}

function rutaAuthEntrar(array $e): array
{
    $email = strtolower(texto($e['email'] ?? '', 190));
    $clave = (string) ($e['password'] ?? '');
    $ip = ipCliente();
    $hace = date('Y-m-d H:i:s', time() - 900);
    $porEmail = (int) valor('SELECT COUNT(*) FROM intentos_login WHERE email = ? AND creado > ?', [$email, $hace]);
    $porIp = (int) valor('SELECT COUNT(*) FROM intentos_login WHERE ip = ? AND creado > ?', [$ip, $hace]);
    if ($porEmail >= 8 || $porIp >= 25) fallar('Demasiados intentos. Espera 15 minutos e intenta de nuevo.', 429);

    $u = fila('SELECT id, password_hash, activo FROM usuarios WHERE email = ?', [$email]);
    if (!$u || !password_verify($clave, $u['password_hash'])) {
        q('INSERT INTO intentos_login (email, ip) VALUES (?, ?)', [$email, $ip]);
        fallar('Correo o contraseña incorrectos.', 401);
    }
    if ((int) $u['activo'] !== 1) fallar('Este usuario está desactivado. Consulta al administrador.', 403);
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        q('UPDATE usuarios SET password_hash = ? WHERE id = ?', [password_hash($clave, PASSWORD_DEFAULT), $u['id']]);
    }
    session_regenerate_id(true);
    $_SESSION['usuario_id'] = (int) $u['id'];
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    q('UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = ?', [$u['id']]);
    q('DELETE FROM intentos_login WHERE email = ?', [$email]);
    usuarioActual(true);
    return datosSesion();
}

function rutaAuthSalir(): array
{
    $_SESSION = [];
    session_regenerate_id(true);
    return [];
}

function rutaAuthClave(array $e): array
{
    $u = requerirUsuario();
    $hash = (string) valor('SELECT password_hash FROM usuarios WHERE id = ?', [$u['id']]);
    if (!password_verify((string) ($e['actual'] ?? ''), $hash)) fallar('La contraseña actual no es correcta.');
    $nueva = (string) ($e['nueva'] ?? '');
    if (mb_strlen($nueva) < 8) fallar('La nueva contraseña debe tener al menos 8 caracteres.');
    q('UPDATE usuarios SET password_hash = ? WHERE id = ?', [password_hash($nueva, PASSWORD_DEFAULT), $u['id']]);
    registrar('cambió su contraseña');
    return [];
}

// ════════════════════════════════════════════════════════════════════
// Estado inicial: todo lo que el usuario puede ver, en el formato de la interfaz
// ════════════════════════════════════════════════════════════════════
function rutaEstado(): array
{
    $u = requerirUsuario();
    $verTodas = puede('ver_todas');

    // Documentos compartidos conmigo (aunque no esté en su obra).
    $compartidosConmigo = filas('SELECT documento_id, permiso FROM documento_compartidos WHERE usuario_id = ?', [$u['id']]);
    $idsCompartidos = array_map('intval', array_column($compartidosConmigo, 'documento_id'));
    $permisoComp = array_column($compartidosConmigo, 'permiso', 'documento_id');

    if ($verTodas) {
        $obras = filas('SELECT * FROM obras ORDER BY clave');
        $docs = filas('SELECT * FROM documentos ORDER BY id');
    } else {
        [$inO, $pO] = enLista($u['obras']);
        [$inD, $pD] = enLista($idsCompartidos);
        $docs = filas("SELECT * FROM documentos WHERE obra_id IN $inO OR id IN $inD ORDER BY id", array_merge($pO, $pD));
        $idsObra = array_unique(array_merge($u['obras'], array_map('intval', array_column($docs, 'obra_id'))));
        [$inV, $pV] = enLista($idsObra);
        $obras = filas("SELECT * FROM obras WHERE id IN $inV ORDER BY clave", $pV);
    }
    $obrasPropias = $verTodas ? array_map('intval', array_column($obras, 'id')) : $u['obras'];
    [$inP, $pP] = enLista($obrasPropias);
    $idsDocs = array_map('intval', array_column($docs, 'id'));
    [$inDocs, $pDocs] = enLista($idsDocs);

    $versiones = filas("SELECT v.*, a.nombre_original, a.tamano, a.tipo_mime FROM documento_versiones v
                        JOIN archivos a ON a.id = v.archivo_id WHERE v.documento_id IN $inDocs ORDER BY v.documento_id, v.version", $pDocs);
    $porDoc = [];
    foreach ($versiones as $v) {
        $porDoc[$v['documento_id']][] = [
            'v' => (int) $v['version'], 'fileId' => (string) $v['archivo_id'], 'fecha' => iso($v['creado']),
            'usuarioId' => idStr($v['usuario_id']), 'nombreArchivo' => $v['nombre_original'],
            'tamano' => (int) $v['tamano'], 'tipo' => $v['tipo_mime'], 'nota' => $v['nota'],
        ];
    }
    $compartidos = [];
    foreach (filas("SELECT documento_id, usuario_id, permiso FROM documento_compartidos WHERE documento_id IN $inDocs", $pDocs) as $c) {
        $compartidos[$c['documento_id']][] = ['usuarioId' => (string) $c['usuario_id'], 'permiso' => $c['permiso']];
    }

    $obrasProp = array_flip($obrasPropias);
    $salida = [
        'obras' => array_map(fn($o) => [
            'id' => (string) $o['id'], 'clave' => $o['clave'], 'nombre' => $o['nombre'], 'cliente' => $o['cliente'],
            'ubicacion' => $o['ubicacion'], 'tipo' => $o['tipo'], 'estado' => $o['estado'],
            'residenteId' => idStr($o['residente_id']) ?? '', 'inicio' => $o['inicio'] ?? '', 'fin' => $o['fin'] ?? '',
            'monto' => (float) $o['monto'], 'avance' => (int) $o['avance'], 'descripcion' => $o['descripcion'] ?? '',
            'soloCompartido' => !isset($obrasProp[(int) $o['id']]),
        ], $obras),
        'documentos' => array_map(fn($d) => [
            'id' => (string) $d['id'], 'obraId' => (string) $d['obra_id'], 'carpeta' => $d['carpeta'], 'nombre' => $d['nombre'],
            'ext' => $d['ext'] ?: 'arch', 'estado' => $d['estado'], 'vence' => $d['vence'] ?? '',
            'etiquetas' => etiquetasLista($d['etiquetas']), 'descripcion' => $d['descripcion'] ?? '',
            'creado' => iso($d['creado']), 'usuarioId' => idStr($d['creado_por']),
            'versiones' => $porDoc[$d['id']] ?? [], 'compartidos' => $compartidos[$d['id']] ?? [],
            'miPermiso' => $permisoComp[$d['id']] ?? null,
        ], array_filter($docs, fn($d) => isset($porDoc[$d['id']]))),
        'presupuestos' => array_map(fn($p) => [
            'id' => (string) $p['id'], 'obraId' => (string) $p['obra_id'], 'folio' => $p['folio'], 'concepto' => $p['concepto'],
            'monto' => (float) $p['monto'], 'estado' => $p['estado'], 'fecha' => $p['fecha'] ?? '',
            'usuarioId' => idStr($p['usuario_id']), 'fileId' => idStr($p['archivo_id']),
            'nombreArchivo' => $p['nombre_original'] ?? '', 'notas' => $p['notas'] ?? '',
        ], filas("SELECT p.*, a.nombre_original FROM presupuestos p LEFT JOIN archivos a ON a.id = p.archivo_id WHERE p.obra_id IN $inP ORDER BY p.fecha DESC, p.id DESC", $pP)),
        'reportes' => [],
        'usuarios' => [],
        'actividad' => [],
    ];
    $salida['documentos'] = array_values($salida['documentos']);

    $reportes = filas("SELECT * FROM reportes WHERE obra_id IN $inP ORDER BY fecha DESC, id DESC", $pP);
    [$inR, $pR] = enLista(array_column($reportes, 'id'));
    $fotos = [];
    foreach (filas("SELECT * FROM reporte_fotos WHERE reporte_id IN $inR ORDER BY reporte_id, orden, id", $pR) as $f) {
        $fotos[$f['reporte_id']][] = ['fileId' => (string) $f['archivo_id'], 'pie' => $f['pie']];
    }
    foreach ($reportes as $r) {
        $salida['reportes'][] = [
            'id' => (string) $r['id'], 'obraId' => (string) $r['obra_id'], 'titulo' => $r['titulo'], 'fecha' => $r['fecha'],
            'periodo' => $r['periodo'], 'descripcion' => $r['descripcion'] ?? '', 'usuarioId' => idStr($r['usuario_id']),
            'fotos' => $fotos[$r['id']] ?? [],
        ];
    }

    // Personas: todos ven el nombre y el puesto (para compartir y para el equipo de obra).
    $asignaciones = [];
    foreach (filas('SELECT usuario_id, obra_id FROM usuario_obra') as $a) $asignaciones[$a['usuario_id']][] = (string) $a['obra_id'];
    $verCorreos = puede('usuarios') || puede('obras');
    foreach (filas('SELECT id, nombre, email, rol, puesto, activo FROM usuarios ORDER BY nombre') as $p) {
        $salida['usuarios'][] = [
            'id' => (string) $p['id'], 'nombre' => $p['nombre'], 'email' => $verCorreos || (int) $p['id'] === $u['id'] ? $p['email'] : '',
            'rol' => $p['rol'], 'puesto' => $p['puesto'], 'activo' => (int) $p['activo'] === 1,
            'obras' => $asignaciones[$p['id']] ?? [],
        ];
    }

    if (puede('actividad')) {
        $act = filas('SELECT * FROM actividad ORDER BY id DESC LIMIT 300');
    } else {
        $act = filas("SELECT * FROM actividad WHERE obra_id IN $inP OR usuario_id = ? ORDER BY id DESC LIMIT 200", array_merge($pP, [$u['id']]));
    }
    foreach ($act as $a) {
        $salida['actividad'][] = [
            'id' => (string) $a['id'], 'fecha' => iso($a['creado']), 'usuarioId' => idStr($a['usuario_id']),
            'accion' => $a['accion'], 'obraId' => idStr($a['obra_id']) ?? '', 'detalle' => $a['detalle'],
        ];
    }
    $salida['requisiciones'] = requisicionesVisibles();
    return $salida;
}

// ════════════════════════════════════════════════════════════════════
// Obras
// ════════════════════════════════════════════════════════════════════
function rutaObraGuardar(array $e): array
{
    exigir('obras');
    $id = (int) ($e['id'] ?? 0);
    $d = [
        'clave' => texto($e['clave'] ?? '', 40), 'nombre' => texto($e['nombre'] ?? '', 200),
        'cliente' => texto($e['cliente'] ?? '', 200), 'ubicacion' => texto($e['ubicacion'] ?? '', 255),
        'tipo' => texto($e['tipo'] ?? '', 60), 'estado' => texto($e['estado'] ?? 'Planeación', 30),
        'residente_id' => (int) ($e['residenteId'] ?? 0) ?: null,
        'inicio' => fechaValida($e['inicio'] ?? ''), 'fin' => fechaValida($e['fin'] ?? ''),
        'monto' => max(0, (float) ($e['monto'] ?? 0)), 'avance' => max(0, min(100, (int) ($e['avance'] ?? 0))),
        'descripcion' => texto($e['descripcion'] ?? '', 4000),
    ];
    if ($d['nombre'] === '') fallar('Escribe el nombre de la obra.');
    if ($d['cliente'] === '') fallar('Escribe el cliente.');
    if ($d['clave'] === '') fallar('La clave no puede quedar vacía.');
    if (!in_array($d['estado'], ESTADOS_OBRA, true)) fallar('Estado de obra no válido.');
    if ($d['inicio'] && $d['fin'] && $d['fin'] < $d['inicio']) fallar('La fecha de término es anterior a la de inicio.');
    if (valor('SELECT id FROM obras WHERE clave = ? AND id <> ?', [$d['clave'], $id])) fallar('Ya existe otra obra con esa clave.');
    if ($d['residente_id'] && !valor('SELECT id FROM usuarios WHERE id = ? AND activo = 1', [$d['residente_id']])) fallar('El residente elegido no existe.');

    if ($id) {
        if (!fila('SELECT id FROM obras WHERE id = ?', [$id])) fallar('La obra no existe.', 404);
        q('UPDATE obras SET clave=?, nombre=?, cliente=?, ubicacion=?, tipo=?, estado=?, residente_id=?, inicio=?, fin=?, monto=?, avance=?, descripcion=? WHERE id=?',
            array_merge(array_values($d), [$id]));
        registrar('actualizó los datos de la obra', $id, $d['clave']);
    } else {
        q('INSERT INTO obras (clave, nombre, cliente, ubicacion, tipo, estado, residente_id, inicio, fin, monto, avance, descripcion) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
            array_values($d));
        $id = ultimoId();
        registrar('creó la obra', $id, $d['clave'] . ' · ' . $d['nombre']);
    }
    // El residente responsable debe poder ver su obra.
    if ($d['residente_id']) q('INSERT IGNORE INTO usuario_obra (usuario_id, obra_id) VALUES (?, ?)', [$d['residente_id'], $id]);
    return ['id' => (string) $id];
}

function rutaObraEquipo(array $e): array
{
    exigir('obras');
    $obraId = (int) ($e['obraId'] ?? 0);
    $obra = exigirObra($obraId);
    $marcados = array_map('intval', (array) ($e['usuarios'] ?? []));
    $rolesAsignables = array_keys(array_filter(ROLES, fn($r) => !in_array('ver_todas', $r['caps'], true)));
    $marcas = implode(',', array_fill(0, count($rolesAsignables), '?'));
    $candidatos = filas("SELECT id, nombre FROM usuarios WHERE rol IN ($marcas)", $rolesAsignables);
    foreach ($candidatos as $c) {
        $cid = (int) $c['id'];
        $tenia = (bool) valor('SELECT 1 FROM usuario_obra WHERE usuario_id = ? AND obra_id = ?', [$cid, $obraId]);
        $quiere = in_array($cid, $marcados, true);
        if ($quiere && !$tenia) {
            q('INSERT INTO usuario_obra (usuario_id, obra_id) VALUES (?, ?)', [$cid, $obraId]);
            registrar("asignó a {$c['nombre']} a la obra", $obraId);
        } elseif (!$quiere && $tenia) {
            q('DELETE FROM usuario_obra WHERE usuario_id = ? AND obra_id = ?', [$cid, $obraId]);
            registrar("quitó a {$c['nombre']} de la obra", $obraId);
        }
    }
    return [];
}

// ════════════════════════════════════════════════════════════════════
// Archivos (subida en dos pasos: iniciar → subir directo → confirmar)
// ════════════════════════════════════════════════════════════════════
function segmentoRuta(string $s, int $max = 120): string
{
    $s = preg_replace('#[/\\\\\x00-\x1F\x7F]+#u', '-', trim($s)) ?? '';
    $s = trim($s, " .-");
    return mb_substr($s !== '' ? $s : 'sin-nombre', 0, $max);
}

function rutaArchivoIniciar(array $e): array
{
    $u = requerirUsuario();
    $nombre = texto($e['nombre'] ?? '', 255);
    $mime = texto($e['tipo'] ?? '', 120) ?: 'application/octet-stream';
    if (!preg_match('#^[\w.+-]+/[\w.+-]+$#', $mime)) $mime = 'application/octet-stream';
    $tamano = (int) ($e['tamano'] ?? 0);
    $destino = ($e['destino'] ?? 'trabajo') === 'archivo' ? 'archivo' : 'trabajo';
    $maxBytes = (int) conf('app.max_mb', 2048) * 1048576;
    if ($nombre === '') fallar('El archivo no tiene nombre.');
    if ($tamano <= 0) fallar('El archivo está vacío.');
    if ($tamano > $maxBytes) fallar('El archivo pasa del máximo permitido (' . conf('app.max_mb', 2048) . ' MB).', 413);

    if ($destino === 'archivo') {
        exigir('archivo_admin');
        $anio = (int) ($e['anio'] ?? 0);
        $obra = segmentoRuta(texto($e['obra'] ?? '', 200));
        $carpeta = segmentoRuta(texto($e['carpeta'] ?? 'General', 120));
        if ($anio < 1950 || $anio > 2100) fallar('Indica el año de la obra.');
        $clave = rtrim((string) conf('archivo_muerto.prefijo', 'archivo-muerto/'), '/') . "/$anio/$obra/$carpeta/" . segmentoRuta($nombre, 200);
        $almacen = almacenArchivo();
    } else {
        $puedeAlgo = puede('subir') || puede('presupuestos') || puede('reportes')
            || valor("SELECT 1 FROM documento_compartidos WHERE usuario_id = ? AND permiso = 'editar' LIMIT 1", [$u['id']]);
        if (!$puedeAlgo) fallar('No tienes permiso para subir archivos.', 403);
        $clave = 'trabajo/' . date('Y/m') . '/' . bin2hex(random_bytes(8)) . '-' . nombreSeguro($nombre);
        $almacen = almacenTrabajo();
    }
    q('INSERT INTO archivos (clave_almacen, nombre_original, tipo_mime, tamano, destino, subido_por) VALUES (?, ?, ?, ?, ?, ?)',
        [$clave, $nombre, $mime, $tamano, $destino, $u['id']]);
    $id = ultimoId();
    return ['id' => (string) $id] + $almacen->urlSubida($clave, $mime, $id);
}

function rutaArchivoRecibir(): array
{
    $u = requerirUsuario();
    $id = (int) ($_GET['id'] ?? 0);
    $a = fila("SELECT * FROM archivos WHERE id = ? AND estado = 'pendiente'", [$id]);
    if (!$a || (int) $a['subido_por'] !== $u['id'] || !hash_equals(tokenSubida($id, $a['clave_almacen']), (string) ($_GET['t'] ?? ''))) {
        fallar('Subida no válida o vencida.', 403);
    }
    $almacen = $a['destino'] === 'archivo' ? almacenArchivo() : almacenTrabajo();
    if (!$almacen instanceof AlmacenLocal) fallar('Este almacenamiento recibe los archivos directamente en la nube.', 400);
    $almacen->recibir($a['clave_almacen'], (int) $a['tamano']);
    return [];
}

function rutaArchivoConfirmar(array $e): array
{
    $u = requerirUsuario();
    $id = (int) ($e['id'] ?? 0);
    $a = fila("SELECT * FROM archivos WHERE id = ? AND estado = 'pendiente'", [$id]);
    if (!$a || (int) $a['subido_por'] !== $u['id']) fallar('Archivo no encontrado.', 404);
    $almacen = $a['destino'] === 'archivo' ? almacenArchivo() : almacenTrabajo();
    $tam = $almacen->tamano($a['clave_almacen']);
    if ($tam === null) fallar('El archivo no llegó al almacenamiento. Intenta subirlo de nuevo.');
    if ($tam > (int) conf('app.max_mb', 2048) * 1048576) {
        $almacen->borrar($a['clave_almacen']);
        fallar('El archivo pasa del máximo permitido.', 413);
    }
    q("UPDATE archivos SET estado = 'listo', tamano = ? WHERE id = ?", [$tam, $id]);
    return ['id' => (string) $id, 'tamano' => $tam];
}

/** Toma un archivo recién subido por el usuario para unirlo a un documento, presupuesto o foto. */
function tomarArchivo(int $id, string $destino = 'trabajo'): array
{
    $u = usuarioActual();
    $a = fila("SELECT * FROM archivos WHERE id = ? AND estado = 'listo' AND vinculado = 0 AND destino = ?", [$id, $destino]);
    if (!$a || (int) $a['subido_por'] !== $u['id']) fallar('El archivo no está listo. Vuelve a subirlo.');
    q('UPDATE archivos SET vinculado = 1 WHERE id = ?', [$id]);
    return $a;
}

function borrarArchivo(?int $id): void
{
    if (!$id) return;
    $a = fila('SELECT * FROM archivos WHERE id = ?', [$id]);
    if (!$a) return;
    try {
        ($a['destino'] === 'archivo' ? almacenArchivo() : almacenTrabajo())->borrar($a['clave_almacen']);
    } catch (Throwable $ex) {
        error_log('[CHC] No se pudo borrar del almacenamiento: ' . $ex->getMessage());
    }
    q('DELETE FROM archivos WHERE id = ?', [$id]);
}

function rutaArchivoDescargar(): array
{
    $u = requerirUsuario();
    $id = (int) ($_GET['id'] ?? 0);
    $adjunto = ($_GET['modo'] ?? 'descargar') !== 'ver';
    $a = fila("SELECT * FROM archivos WHERE id = ? AND estado = 'listo' AND destino = 'trabajo'", [$id]);
    if (!$a) fallar('Archivo no encontrado.', 404);

    $permitido = false;
    $docVersion = fila('SELECT d.* FROM documento_versiones v JOIN documentos d ON d.id = v.documento_id WHERE v.archivo_id = ?', [$id]);
    if ($docVersion && puedeVerDocumento($docVersion)) {
        $permitido = true;
        if ($adjunto) registrar('descargó el documento', (int) $docVersion['obra_id'], $docVersion['nombre']);
    }
    if (!$permitido && ($p = fila('SELECT obra_id FROM presupuestos WHERE archivo_id = ?', [$id]))) $permitido = puedeVerObra((int) $p['obra_id']);
    if (!$permitido && ($r = fila('SELECT r.obra_id FROM reporte_fotos f JOIN reportes r ON r.id = f.reporte_id WHERE f.archivo_id = ?', [$id]))) $permitido = puedeVerObra((int) $r['obra_id']);
    if (!$permitido && (int) $a['vinculado'] === 0 && (int) $a['subido_por'] === $u['id']) $permitido = true;
    if (!$permitido) fallar('No tienes acceso a este archivo.', 403);

    almacenTrabajo()->entregar($a['clave_almacen'], $a['nombre_original'], $a['tipo_mime'], $adjunto);
}

// ════════════════════════════════════════════════════════════════════
// Documentos
// ════════════════════════════════════════════════════════════════════
function estadosPermitidos(): array
{
    return puede('aprobar') ? ['Borrador', 'En revisión', 'Aprobado'] : ['Borrador', 'En revisión'];
}

function rutaDocCrear(array $e): array
{
    $u = requerirUsuario();
    exigir('subir');
    $obraId = (int) ($e['obraId'] ?? 0);
    exigirObra($obraId);
    $carpeta = (string) ($e['carpeta'] ?? '');
    if (!in_array($carpeta, CARPETAS, true)) fallar('Elige una carpeta válida.');
    $estado = (string) ($e['estado'] ?? 'En revisión');
    if (!in_array($estado, estadosPermitidos(), true)) $estado = 'En revisión';
    $archivo = tomarArchivo((int) ($e['archivoId'] ?? 0));
    $nombre = texto($e['nombre'] ?? '', 255);
    if ($nombre === '') $nombre = texto(str_replace('_', ' ', pathinfo($archivo['nombre_original'], PATHINFO_FILENAME)), 255) ?: $archivo['nombre_original'];

    q('INSERT INTO documentos (obra_id, carpeta, nombre, ext, estado, vence, etiquetas, descripcion, creado_por) VALUES (?,?,?,?,?,?,?,?,?)', [
        $obraId, $carpeta, $nombre, extension($archivo['nombre_original']), $estado, fechaValida($e['vence'] ?? ''),
        etiquetasTexto($e['etiquetas'] ?? []), texto($e['descripcion'] ?? '', 4000), $u['id'],
    ]);
    $docId = ultimoId();
    q("INSERT INTO documento_versiones (documento_id, version, archivo_id, nota, usuario_id) VALUES (?, 1, ?, 'Versión inicial', ?)", [$docId, $archivo['id'], $u['id']]);
    registrar('subió el documento', $obraId, $nombre);
    return ['id' => (string) $docId];
}

function rutaDocVersion(array $e): array
{
    $u = requerirUsuario();
    $doc = exigirDocumento((int) ($e['documentoId'] ?? 0));
    if (!puedeVersionar($doc)) fallar('No tienes permiso para actualizar este documento.', 403);
    $archivo = tomarArchivo((int) ($e['archivoId'] ?? 0));
    $v = (int) valor('SELECT MAX(version) FROM documento_versiones WHERE documento_id = ?', [$doc['id']]) + 1;
    q('INSERT INTO documento_versiones (documento_id, version, archivo_id, nota, usuario_id) VALUES (?, ?, ?, ?, ?)',
        [$doc['id'], $v, $archivo['id'], texto($e['nota'] ?? '', 500), $u['id']]);
    // Una versión nueva vuelve a revisión, salvo que la suba quien puede aprobar.
    $estado = puede('aprobar') && puedeVerObra((int) $doc['obra_id']) ? $doc['estado'] : 'En revisión';
    q('UPDATE documentos SET ext = ?, estado = ?, actualizado = NOW() WHERE id = ?', [extension($archivo['nombre_original']), $estado, $doc['id']]);
    registrar('subió una nueva versión de', (int) $doc['obra_id'], "{$doc['nombre']} (v$v)");
    return ['version' => $v];
}

function rutaDocEditar(array $e): array
{
    $u = requerirUsuario();
    $doc = exigirDocumento((int) ($e['id'] ?? 0));
    $puedeEditar = (puede('aprobar') && puedeVerObra((int) $doc['obra_id']))
        || ((int) $doc['creado_por'] === $u['id'] && puedeVersionar($doc));
    if (!$puedeEditar) fallar('No tienes permiso para editar este documento.', 403);
    $nombre = texto($e['nombre'] ?? '', 255);
    $carpeta = (string) ($e['carpeta'] ?? $doc['carpeta']);
    if ($nombre === '') fallar('El nombre no puede quedar vacío.');
    if (!in_array($carpeta, CARPETAS, true)) fallar('Carpeta no válida.');
    q('UPDATE documentos SET nombre = ?, carpeta = ?, vence = ?, etiquetas = ?, descripcion = ?, actualizado = NOW() WHERE id = ?', [
        $nombre, $carpeta, fechaValida($e['vence'] ?? ''), etiquetasTexto($e['etiquetas'] ?? []), texto($e['descripcion'] ?? '', 4000), $doc['id'],
    ]);
    registrar('editó los datos del documento', (int) $doc['obra_id'], $nombre);
    return [];
}

function rutaDocEstado(array $e): array
{
    exigir('aprobar');
    $doc = exigirDocumento((int) ($e['id'] ?? 0));
    if (!puedeVerObra((int) $doc['obra_id'])) fallar('No tienes permiso en esta obra.', 403);
    $estado = (string) ($e['estado'] ?? '');
    if (!in_array($estado, ESTADOS_DOC, true)) fallar('Estado no válido.');
    q('UPDATE documentos SET estado = ?, actualizado = NOW() WHERE id = ?', [$estado, $doc['id']]);
    registrar("cambió a «{$estado}» el documento", (int) $doc['obra_id'], $doc['nombre']);
    return [];
}

function rutaDocEliminar(array $e): array
{
    exigir('eliminar');
    $doc = exigirDocumento((int) ($e['id'] ?? 0));
    if (!puedeVerObra((int) $doc['obra_id'])) fallar('No tienes permiso en esta obra.', 403);
    $archivos = array_column(filas('SELECT archivo_id FROM documento_versiones WHERE documento_id = ?', [$doc['id']]), 'archivo_id');
    q('DELETE FROM documentos WHERE id = ?', [$doc['id']]); // las versiones se borran en cascada
    foreach ($archivos as $aid) borrarArchivo((int) $aid);
    registrar('eliminó el documento', (int) $doc['obra_id'], $doc['nombre']);
    return [];
}

function rutaDocCompartir(array $e): array
{
    $u = requerirUsuario();
    exigir('compartir');
    $doc = exigirDocumento((int) ($e['id'] ?? 0));
    // Sólo comparte quien trabaja en la obra (no quien lo recibió compartido).
    if (!puedeVerObra((int) $doc['obra_id'])) fallar('Sólo el personal de la obra puede compartir este documento.', 403);
    $nuevos = [];
    foreach ((array) ($e['usuarios'] ?? []) as $item) {
        $uid = (int) ($item['id'] ?? 0);
        $permiso = ($item['permiso'] ?? 'ver') === 'editar' ? 'editar' : 'ver';
        if ($uid && $uid !== $u['id']) $nuevos[$uid] = $permiso;
    }
    $actuales = array_column(filas('SELECT usuario_id, permiso FROM documento_compartidos WHERE documento_id = ?', [$doc['id']]), 'permiso', 'usuario_id');
    $avisar = [];
    foreach ($nuevos as $uid => $permiso) {
        $destino = fila('SELECT id, nombre, email FROM usuarios WHERE id = ? AND activo = 1', [$uid]);
        if (!$destino) continue;
        if (!isset($actuales[$uid])) {
            q('INSERT INTO documento_compartidos (documento_id, usuario_id, permiso, compartido_por) VALUES (?, ?, ?, ?)', [$doc['id'], $uid, $permiso, $u['id']]);
            $avisar[] = $destino;
        } elseif ($actuales[$uid] !== $permiso) {
            q('UPDATE documento_compartidos SET permiso = ? WHERE documento_id = ? AND usuario_id = ?', [$permiso, $doc['id'], $uid]);
        }
    }
    foreach (array_keys($actuales) as $uid) {
        if (!isset($nuevos[(int) $uid])) q('DELETE FROM documento_compartidos WHERE documento_id = ? AND usuario_id = ?', [$doc['id'], $uid]);
    }
    if ($avisar) {
        registrar('compartió el documento', (int) $doc['obra_id'], $doc['nombre'] . ' con ' . implode(', ', array_column($avisar, 'nombre')));
        foreach ($avisar as $p) {
            enviarCorreo($p['email'], 'Te compartieron un documento: ' . $doc['nombre'],
                "{$u['nombre']} compartió contigo el documento «{$doc['nombre']}». Lo encuentras en «Compartidos conmigo».");
        }
    }
    return [];
}

// ════════════════════════════════════════════════════════════════════
// Presupuestos
// ════════════════════════════════════════════════════════════════════
function rutaPresGuardar(array $e): array
{
    $u = requerirUsuario();
    exigir('presupuestos');
    $obraId = (int) ($e['obraId'] ?? 0);
    exigirObra($obraId);
    $id = (int) ($e['id'] ?? 0);
    $existente = $id ? fila('SELECT * FROM presupuestos WHERE id = ? AND obra_id = ?', [$id, $obraId]) : null;
    if ($id && !$existente) fallar('El presupuesto no existe.', 404);
    if ($existente && (int) $existente['usuario_id'] !== $u['id'] && !puede('ver_todas')) fallar('Sólo quien lo elaboró o un coordinador puede editarlo.', 403);

    $concepto = texto($e['concepto'] ?? '', 255);
    $monto = (float) ($e['monto'] ?? 0);
    $estado = (string) ($e['estado'] ?? 'Borrador');
    if ($concepto === '') fallar('Escribe el concepto.');
    if ($monto <= 0) fallar('Escribe un monto mayor a cero.');
    if (!in_array($estado, ESTADOS_PRES, true)) fallar('Estado no válido.');
    $puedeCerrar = puede('aprobar') || puede('ver_todas');
    if (!$puedeCerrar && in_array($estado, ['Aprobado', 'Rechazado'], true) && (!$existente || $existente['estado'] !== $estado)) {
        fallar('Sólo un coordinador puede marcarlo como aprobado o rechazado.', 403);
    }
    $folio = texto($e['folio'] ?? '', 40);
    if ($folio === '') {
        $n = (int) valor("SELECT MAX(CAST(SUBSTRING(folio, 5) AS UNSIGNED)) FROM presupuestos WHERE obra_id = ? AND folio LIKE 'PRE-%'", [$obraId]);
        $folio = 'PRE-' . str_pad((string) ($n + 1), 3, '0', STR_PAD_LEFT);
    }
    $archivoId = !empty($e['archivoId']) ? (int) tomarArchivo((int) $e['archivoId'])['id'] : null;
    $datos = [$folio, $concepto, $monto, $estado, fechaValida($e['fecha'] ?? '') ?? date('Y-m-d'), texto($e['notas'] ?? '', 4000)];

    if ($existente) {
        q('UPDATE presupuestos SET folio=?, concepto=?, monto=?, estado=?, fecha=?, notas=? WHERE id=?', array_merge($datos, [$id]));
        if ($archivoId) {
            q('UPDATE presupuestos SET archivo_id = ? WHERE id = ?', [$archivoId, $id]);
            borrarArchivo($existente['archivo_id'] ? (int) $existente['archivo_id'] : null);
        }
        $accion = $existente['estado'] !== $estado ? "cambió a «{$estado}» el presupuesto" : 'editó el presupuesto';
        registrar($accion, $obraId, "$folio · $concepto");
    } else {
        q('INSERT INTO presupuestos (folio, concepto, monto, estado, fecha, notas, obra_id, archivo_id, usuario_id) VALUES (?,?,?,?,?,?,?,?,?)',
            array_merge($datos, [$obraId, $archivoId, $u['id']]));
        $id = ultimoId();
        registrar('creó el presupuesto', $obraId, "$folio · $concepto");
    }
    return ['id' => (string) $id];
}

function rutaPresEliminar(array $e): array
{
    exigir('eliminar');
    $p = fila('SELECT * FROM presupuestos WHERE id = ?', [(int) ($e['id'] ?? 0)]);
    if (!$p) fallar('El presupuesto no existe.', 404);
    exigirObra((int) $p['obra_id']);
    q('DELETE FROM presupuestos WHERE id = ?', [$p['id']]);
    borrarArchivo($p['archivo_id'] ? (int) $p['archivo_id'] : null);
    registrar('eliminó el presupuesto', (int) $p['obra_id'], "{$p['folio']} · {$p['concepto']}");
    return [];
}

// ════════════════════════════════════════════════════════════════════
// Reportes fotográficos
// ════════════════════════════════════════════════════════════════════
function rutaRepCrear(array $e): array
{
    $u = requerirUsuario();
    exigir('reportes');
    $obraId = (int) ($e['obraId'] ?? 0);
    exigirObra($obraId);
    $titulo = texto($e['titulo'] ?? '', 200);
    $fotos = array_slice((array) ($e['fotos'] ?? []), 0, 40);
    if ($titulo === '') fallar('Escribe un título.');
    if (!$fotos) fallar('Agrega al menos una foto.');
    q('INSERT INTO reportes (obra_id, titulo, fecha, periodo, descripcion, usuario_id) VALUES (?, ?, ?, ?, ?, ?)', [
        $obraId, $titulo, fechaValida($e['fecha'] ?? '') ?? date('Y-m-d'), texto($e['periodo'] ?? '', 80), texto($e['descripcion'] ?? '', 4000), $u['id'],
    ]);
    $repId = ultimoId();
    foreach (array_values($fotos) as $i => $f) {
        $a = tomarArchivo((int) ($f['archivoId'] ?? 0));
        if (!str_starts_with($a['tipo_mime'], 'image/')) fallar('Sólo se aceptan imágenes en el reporte fotográfico.');
        q('INSERT INTO reporte_fotos (reporte_id, archivo_id, pie, orden) VALUES (?, ?, ?, ?)', [$repId, $a['id'], texto($f['pie'] ?? '', 500), $i]);
    }
    registrar('publicó el reporte fotográfico', $obraId, $titulo);
    return ['id' => (string) $repId];
}

function rutaRepEliminar(array $e): array
{
    $u = requerirUsuario();
    $r = fila('SELECT * FROM reportes WHERE id = ?', [(int) ($e['id'] ?? 0)]);
    if (!$r) fallar('El reporte no existe.', 404);
    exigirObra((int) $r['obra_id']);
    if (!puede('eliminar') && (int) $r['usuario_id'] !== $u['id']) fallar('No tienes permiso para eliminar este reporte.', 403);
    $archivos = array_column(filas('SELECT archivo_id FROM reporte_fotos WHERE reporte_id = ?', [$r['id']]), 'archivo_id');
    q('DELETE FROM reportes WHERE id = ?', [$r['id']]);
    foreach ($archivos as $aid) borrarArchivo((int) $aid);
    registrar('eliminó el reporte fotográfico', (int) $r['obra_id'], $r['titulo']);
    return [];
}

// ════════════════════════════════════════════════════════════════════
// Usuarios
// ════════════════════════════════════════════════════════════════════
function rutaUsuarioGuardar(array $e): array
{
    $yo = requerirUsuario();
    exigir('usuarios');
    $id = (int) ($e['id'] ?? 0);
    $esYo = $id === $yo['id'];
    $existente = $id ? fila('SELECT * FROM usuarios WHERE id = ?', [$id]) : null;
    if ($id && !$existente) fallar('El usuario no existe.', 404);

    $nombre = texto($e['nombre'] ?? '', 120);
    $email = strtolower(texto($e['email'] ?? '', 190));
    $puesto = texto($e['puesto'] ?? '', 120);
    $rol = $esYo ? $yo['rol'] : (string) ($e['rol'] ?? 'residente');
    $activo = $esYo ? 1 : (!empty($e['activo']) ? 1 : 0);
    $clave = (string) ($e['password'] ?? '');
    if ($nombre === '') fallar('Escribe el nombre.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fallar('Escribe un correo válido.');
    if (!isset(ROLES[$rol])) fallar('Rol no válido.');
    if (valor('SELECT id FROM usuarios WHERE email = ? AND id <> ?', [$email, $id])) fallar('Ya hay un usuario con ese correo.');
    if (!$id && mb_strlen($clave) < 8) fallar('La contraseña debe tener al menos 8 caracteres.');
    if ($id && $clave !== '' && mb_strlen($clave) < 8) fallar('La contraseña debe tener al menos 8 caracteres.');

    if ($existente) {
        q('UPDATE usuarios SET nombre=?, email=?, puesto=?, rol=?, activo=? WHERE id=?', [$nombre, $email, $puesto, $rol, $activo, $id]);
        if ($clave !== '') q('UPDATE usuarios SET password_hash = ? WHERE id = ?', [password_hash($clave, PASSWORD_DEFAULT), $id]);
        registrar('actualizó al usuario', null, $nombre);
    } else {
        q('INSERT INTO usuarios (nombre, email, puesto, rol, activo, password_hash) VALUES (?,?,?,?,?,?)',
            [$nombre, $email, $puesto, $rol, $activo, password_hash($clave, PASSWORD_DEFAULT)]);
        $id = ultimoId();
        registrar('dio de alta al usuario', null, $nombre);
    }
    // Obras asignadas (sólo cuenta para los roles que no ven todas).
    q('DELETE FROM usuario_obra WHERE usuario_id = ?', [$id]);
    if (!in_array('ver_todas', ROLES[$rol]['caps'], true)) {
        foreach (array_unique(array_map('intval', (array) ($e['obras'] ?? []))) as $oid) {
            if (valor('SELECT id FROM obras WHERE id = ?', [$oid])) q('INSERT INTO usuario_obra (usuario_id, obra_id) VALUES (?, ?)', [$id, $oid]);
        }
    }
    return ['id' => (string) $id];
}

// ════════════════════════════════════════════════════════════════════
// Diagnóstico (sólo administrador): prueba el almacenamiento real
// ════════════════════════════════════════════════════════════════════
function rutaDiagnostico(): array
{
    exigir('usuarios');
    $resultado = [];
    foreach (['trabajo' => 'almacenTrabajo', 'archivo' => 'almacenArchivo'] as $nombre => $fabrica) {
        $driver = $nombre === 'trabajo' ? conf('almacen.driver', 'local') : conf('archivo_muerto.driver', 'local');
        $r = ['driver' => $driver, 'ok' => false, 'detalle' => ''];
        try {
            $a = $fabrica();
            $clave = 'diagnostico/prueba-' . bin2hex(random_bytes(4)) . '.txt';
            $a->poner($clave, 'prueba ' . date('c'), 'text/plain');
            $tam = $a->tamano($clave);
            $a->borrar($clave);
            $r['ok'] = $tam !== null && $tam > 0;
            $r['detalle'] = $r['ok'] ? 'Se guardó, se leyó y se borró un archivo de prueba.' : 'El archivo de prueba no apareció.';
        } catch (Throwable $ex) {
            $r['detalle'] = $ex->getMessage();
        }
        $resultado[$nombre] = $r;
    }
    return [
        'almacen' => $resultado,
        'php' => [
            'version' => PHP_VERSION,
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            'memory_limit' => ini_get('memory_limit'),
        ],
        'archivoModo' => conf('archivo_muerto.modo', 'inmediato'),
        'correo' => (bool) conf('correo.activo'),
    ];
}

/**
 * Regla CORS: autoriza a este sitio para subir archivos directo a la nube.
 * Se usa una vez al configurar el almacenamiento (botón en Diagnóstico).
 */
function rutaConfigurarCors(): array
{
    exigir('usuarios');
    $origenes = [];
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $host = preg_replace('/[^a-z0-9.:-]/i', '', (string) ($_SERVER['HTTP_HOST'] ?? '')) ?? '';
    if ($host !== '') $origenes[] = ($https ? 'https' : 'http') . '://' . $host;
    $url = parse_url((string) conf('correo.url_sistema', ''));
    if (!empty($url['scheme']) && !empty($url['host'])) $origenes[] = $url['scheme'] . '://' . $url['host'] . (isset($url['port']) ? ':' . $url['port'] : '');
    $origenes = array_values(array_unique($origenes));
    if (!$origenes) fallar('No se pudo saber la dirección de este sitio.');

    $resultado = [];
    $hechos = [];
    foreach (['trabajo' => 'almacen', 'archivo' => 'archivo_muerto'] as $nombre => $seccion) {
        if (conf("$seccion.driver", 'local') !== 's3') {
            $resultado[$nombre] = ['ok' => true, 'detalle' => 'Almacenamiento local: no necesita esta regla.'];
            continue;
        }
        $bucket = conf("$seccion.s3.endpoint") . '|' . conf("$seccion.s3.bucket");
        try {
            if (!isset($hechos[$bucket])) ($nombre === 'trabajo' ? almacenTrabajo() : almacenArchivo())->configurarCors($origenes);
            $hechos[$bucket] = true;
            $resultado[$nombre] = ['ok' => true, 'detalle' => 'Listo: se aceptan subidas desde ' . implode(' y ', $origenes) . '.'];
        } catch (Throwable $ex) {
            $resultado[$nombre] = ['ok' => false, 'detalle' => $ex->getMessage()];
        }
    }
    registrar('configuró las subidas directas del almacenamiento', null, implode(', ', $origenes));
    return ['almacen' => $resultado, 'origenes' => $origenes];
}
