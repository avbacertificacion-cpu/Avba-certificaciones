<?php
/**
 * Socios Comerciales AVBA — API Principal
 * Punto de entrada único para todas las peticiones del frontend.
 *
 * GET  /api/?action=XXX   → acciones de lectura
 * POST /api/               → escritura (JSON body, o multipart/form-data para archivos)
 *
 * Sistema 100% aislado del API de certificaciones (/api en la raíz del repo).
 */

// ── Bootstrap ─────────────────────────────────────────────
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Personas.php';
require_once __DIR__ . '/Empresas.php';
require_once __DIR__ . '/Vacantes.php';
require_once __DIR__ . '/Admin.php';
require_once __DIR__ . '/diagnostico.php';

// Nunca mostrar avisos de PHP en la respuesta: romperían el JSON
ini_set('display_errors', '0');

// Aislar la cookie de sesión de la del sistema de certificaciones.
// Se fija aquí y no solo en .user.ini porque este host no aplica ese
// archivo (el diagnóstico muestra los límites globales, no los nuestros).
// Hoy la autenticación es por token, pero esto deja el aislamiento
// garantizado si en el futuro se usan sesiones PHP.
ini_set('session.cookie_path', '/socioscomerciales');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', '1');

// ── Headers ───────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');

// El API solo lo consume el portal. Con "*" cualquier sitio podía llamarlo
// y leer las respuestas públicas; ahora solo se devuelve la cabecera cuando
// el origen es uno de los nuestros.
$origen = $_SERVER['HTTP_ORIGIN'] ?? '';
foreach (SC_HOSTS_PERMITIDOS as $hostOk) {
    if ($origen === 'https://' . $hostOk || $origen === 'http://' . $hostOk) {
        header('Access-Control-Allow-Origin: ' . $origen);
        header('Vary: Origin');
        break;
    }
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Token');

// Las respuestas llevan perfiles, correos y CV: que ningún intermediario ni
// el navegador las guarde en disco.
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Cualquier error fatal debe salir como JSON, no como una página HTML de error
// (si no, el frontend recibe HTML y res.json() falla en silencio).
set_exception_handler(function (Throwable $e) {
    error_log('SC API: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) http_response_code(500);

    // Las RuntimeException las lanzamos nosotros con texto pensado para el
    // usuario (p. ej. fallos de esquema); el resto se reporta en genérico
    // para no filtrar detalles internos.
    $mensaje = $e instanceof RuntimeException
        ? $e->getMessage()
        : 'Error interno del servidor.';

    echo json_encode([
        'status'  => 'error',
        'message' => $mensaje,
        'ayuda'   => 'Si el problema persiste, revisa el log de errores del servidor.',
    ], JSON_UNESCAPED_UNICODE);
});
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['status' => 'error', 'message' => 'Error interno del servidor.'], JSON_UNESCAPED_UNICODE);
    }
});

// El diagnóstico corre ANTES de conectar: así puede reportar también los
// fallos de conexión y de migración, que abortarían el arranque normal.
if (($_GET['action'] ?? '') === 'DIAGNOSTICO') {
    // La clave es OBLIGATORIA. Antes era opcional y, como no venía en
    // config.sample.php, en la práctica nadie la ponía: cualquiera podía
    // leer versión de PHP, extensiones, versión de MariaDB y qué tablas
    // existen. Eso es material de reconocimiento y no debe estar abierto.
    if (!defined('SC_DIAG_CLAVE') || SC_DIAG_CLAVE === '') {
        scRespuesta([
            'status'  => 'error',
            'message' => 'El diagnóstico está desactivado. Define SC_DIAG_CLAVE en config/config.php para poder usarlo.',
        ], 403);
    }
    // hash_equals evita distinguir la clave por tiempo de respuesta
    if (!hash_equals(SC_DIAG_CLAVE, (string) ($_GET['clave'] ?? ''))) {
        scRespuesta(['status' => 'error', 'message' => 'No autorizado.'], 403);
    }
    echo json_encode(scDiagnostico(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ── PDO + módulos ─────────────────────────────────────────
$pdo      = scDB();
$auth     = new ScAuth($pdo);
$personas = new ScPersonas($pdo);
$empresas = new ScEmpresas($pdo);
$vacantes = new ScVacantes($pdo);
$admin    = new ScAdmin($pdo);

// ── Extraer token (Authorization: Bearer, X-Token, body o query) ──
$token = null;
$cabecera = $_SERVER['HTTP_AUTHORIZATION']
    ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
    ?? $_SERVER['HTTP_X_TOKEN']
    ?? '';
if (preg_match('/Bearer\s+(.+)/i', $cabecera, $m)) {
    $token = trim($m[1]);
} elseif ($cabecera !== '') {
    $token = trim($cabecera);
}

$method      = $_SERVER['REQUEST_METHOD'];
$tipoContenido = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
$esMultipart = $method === 'POST' && stripos($tipoContenido, 'multipart/form-data') === 0;
$action      = '';
$payload     = [];

// El token NUNCA se acepta por query string: acabaría en los logs de acceso
// del host, en el historial del navegador y en la cabecera Referer.
if ($method === 'GET') {
    $action = $_GET['action'] ?? '';
    $payload = $_GET;
} elseif ($esMultipart) {
    $action  = $_POST['action'] ?? '';
    $payload = $_POST;
    if (!$token && isset($_POST['token'])) $token = $_POST['token'];

    // El host acepta hasta 1.5 GB por petición, así que sin este corte PHP
    // recibiría y escribiría en disco el archivo entero antes de que el
    // código pudiera rechazarlo por tamaño.
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 12 * 1024 * 1024) {
        scRespuesta([
            'status'  => 'error',
            'message' => 'El archivo es demasiado grande. El máximo son 8 MB para el CV y 6 MB para imágenes.',
        ], 413);
    }

    // Si el cuerpo superó post_max_size, PHP descarta $_POST y $_FILES enteros
    if (empty($_POST) && empty($_FILES)) {
        scRespuesta([
            'status'  => 'error',
            'message' => 'El archivo es demasiado grande para el servidor (límite: '
                         . ini_get('post_max_size') . ').',
        ], 413);
    }
} else {
    $raw  = file_get_contents('php://input');
    $body = $raw ? (json_decode($raw, true) ?? []) : [];
    if (!is_array($body)) $body = [];
    $action  = $body['action']  ?? '';
    $payload = $body['payload'] ?? $body;
    if (!$token && isset($body['token'])) $token = $body['token'];
}

// Si llega "payload":"texto" o ?texto[]=a, los trim() posteriores lanzarían
// TypeError y el usuario vería un 500 en lugar de un error entendible.
if (!is_array($payload)) $payload = [];
foreach ($payload as $clave => $valor) {
    if (!is_scalar($valor) && $valor !== null) unset($payload[$clave]);
}
if (!is_string($action)) $action = '';

/** Exige sesión válida; corta con 401 si no la hay. */
function scSesion(PDO $pdo, ?string $token): array {
    $usr = scValidarToken($pdo, $token);
    if (!$usr) scRespuesta(['status' => 'error', 'message' => 'Tu sesión expiró. Inicia sesión de nuevo.'], 401);
    return $usr;
}

/** Exige sesión de un tipo concreto (persona o empresa). */
function scSesionTipo(PDO $pdo, ?string $token, string $tipo): array {
    $usr = scSesion($pdo, $token);
    if ($usr['tipo'] !== $tipo) {
        scRespuesta(['status' => 'error', 'message' => 'Tu tipo de cuenta no tiene acceso a esta acción.'], 403);
    }
    return $usr;
}

/** True si la cuenta ya confirmó su correo. */
function scEstaVerificado(array $usr): bool {
    return (int) ($usr['correo_verificado'] ?? 0) === 1;
}

/**
 * Exige sesión CON el correo ya confirmado.
 *
 * Se aplica a todo lo que escribe en la base y a todo lo que muestra datos de
 * otras cuentas. Una cuenta sin verificar queda reducida a: entrar, ver y
 * completar su propio panel en modo lectura, reenviar el enlace, cambiar su
 * contraseña y salir. Así una dirección de correo inventada no sirve para
 * publicar vacantes, postularse ni pasear por los perfiles del portal.
 *
 * El `codigo` permite al frontend distinguir este 403 del de tipo de cuenta y
 * pintar el aviso de "confirma tu correo" en lugar de un error genérico.
 */
function scSesionVerificada(PDO $pdo, ?string $token, ?string $tipo = null): array {
    $usr = $tipo === null ? scSesion($pdo, $token) : scSesionTipo($pdo, $token, $tipo);
    if (!scEstaVerificado($usr)) {
        scRespuesta([
            'status'  => 'error',
            'codigo'  => 'CORREO_NO_VERIFICADO',
            'message' => 'Confirma tu correo electrónico para usar esta función. '
                       . 'Te enviamos un enlace al registrarte; puedes pedir uno nuevo desde tu panel.',
        ], 403);
    }
    return $usr;
}

/**
 * Exige sesión de administración.
 *
 * No pide el correo verificado a propósito: quién es administrador lo dice
 * SC_ADMINS en config.php, un archivo que solo toca quien tiene el servidor,
 * y esa es garantía suficiente. Exigirlo además arriesgaría dejar el portal
 * sin administración si el correo de verificación nunca llega.
 */
function scSesionAdmin(PDO $pdo, ?string $token): array {
    $usr = scSesion($pdo, $token);
    if (empty($usr['es_admin'])) {
        scRespuesta(['status' => 'error', 'message' => 'No tienes permisos de administración.'], 403);
    }
    return $usr;
}

/** Id de la ficha de candidato de un usuario, o 0 si no tiene. */
function scIdPersona(PDO $pdo, int $usuarioId): int {
    $s = $pdo->prepare("SELECT id FROM sc_personas WHERE usuario_id = ?");
    $s->execute([$usuarioId]);
    return (int) $s->fetchColumn();
}

// ══════════════════════════════════════════════════════════
//  RUTAS GET
// ══════════════════════════════════════════════════════════
if ($method === 'GET') {
    switch ($action) {

        // Verificación de correo: llega desde el enlace del correo, así que
        // responde con una redirección al HTML, no con JSON.
        case 'VERIFICAR_CORREO':
            $res = $auth->verificarCorreo(trim($_GET['t'] ?? ''));
            $ok  = $res['status'] === 'success' ? '1' : '0';
            header('Location: ' . scUrlBase() . '/verificar.html?ok=' . $ok
                   . '&msg=' . urlencode($res['message']), true, 302);
            exit;

        // Devuelve el PDF, no JSON: valida sesión y luego escribe el archivo.
        // Sin el correo confirmado solo se puede abrir el CV propio.
        case 'DESCARGAR_CV':
            $usr       = scSesion($pdo, $token);
            $idPersona = (int) ($_GET['id'] ?? 0);
            if (!scEstaVerificado($usr) && empty($usr['es_admin'])
                && scIdPersona($pdo, (int) $usr['id']) !== $idPersona) {
                scRespuesta([
                    'status'  => 'error',
                    'codigo'  => 'CORREO_NO_VERIFICADO',
                    'message' => 'Confirma tu correo electrónico para consultar el CV de un candidato.',
                ], 403);
            }
            $personas->entregarCV($usr, $idPersona);
            exit;

        case 'GET_PERFIL_PERSONA':
            $usr = scSesionTipo($pdo, $token, 'persona');
            scRespuesta($personas->obtenerPerfil((int) $usr['id']));

        case 'GET_PERFIL_EMPRESA':
            $usr = scSesionTipo($pdo, $token, 'empresa');
            scRespuesta($empresas->obtenerPerfil((int) $usr['id']));

        case 'GET_INICIO':
            $usr = scSesion($pdo, $token);
            scRespuesta($vacantes->resumenInicio($usr));

        case 'MIS_SESIONES':
            $usr = scSesion($pdo, $token);
            scRespuesta($auth->listarSesiones((int) $usr['id'], $token));

        case 'EXPORTAR_DATOS':
            $usr = scSesion($pdo, $token);
            scRespuesta($auth->exportarDatos((int) $usr['id']));

        // ── Perfiles públicos ────────────────────────────────
        // El perfil de un candidato incluye su correo y su CV: solo las
        // empresas deben verlo, no cualquier cuenta con sesión.
        case 'GET_PERSONA_PUBLICA':
            $usr = scSesion($pdo, $token);
            $idPersona = (int) ($_GET['id'] ?? 0);
            if ($usr['tipo'] !== 'empresa') {
                // Un candidato solo puede abrir su propio perfil público
                if (scIdPersona($pdo, (int) $usr['id']) !== $idPersona) {
                    scRespuesta(['status' => 'error', 'message' => 'No autorizado.'], 403);
                }
            } else {
                // Ver la ficha de otra persona exige el correo confirmado
                scSesionVerificada($pdo, $token);
            }
            scRespuesta($personas->obtenerPerfilPublico($idPersona));

        case 'GET_EMPRESA_PUBLICA':
            scSesionVerificada($pdo, $token);
            scRespuesta($empresas->obtenerPerfilPublico((int) ($_GET['id'] ?? 0)));

        case 'LISTAR_EMPRESAS':
            scSesionVerificada($pdo, $token);
            scRespuesta($empresas->listarEmpresas($_GET['texto'] ?? '', $payload));

        // ── Vacantes ─────────────────────────────────────────
        // La bolsa de trabajo sí queda visible sin verificar: una vacante es
        // un anuncio público de la empresa, no la ficha de una persona. Lo que
        // no se puede sin confirmar el correo es postularse.
        case 'BUSCAR_VACANTES':
            $usr       = scValidarToken($pdo, $token);
            $personaId = ($usr && $usr['tipo'] === 'persona')
                ? (scIdPersona($pdo, (int) $usr['id']) ?: null) : null;
            scRespuesta($vacantes->buscar($payload, $personaId));

        case 'GET_VACANTE':
            $usr       = scValidarToken($pdo, $token);
            $personaId = ($usr && $usr['tipo'] === 'persona')
                ? (scIdPersona($pdo, (int) $usr['id']) ?: null) : null;
            scRespuesta($vacantes->obtener((int) ($_GET['id'] ?? 0), $personaId));

        case 'LISTAR_MIS_VACANTES':
            $usr = scSesionTipo($pdo, $token, 'empresa');
            scRespuesta($vacantes->listarMisVacantes((int) $usr['id']));

        // ── Postulaciones ────────────────────────────────────
        case 'MIS_POSTULACIONES':
            $usr = scSesionTipo($pdo, $token, 'persona');
            scRespuesta($vacantes->misPostulaciones((int) $usr['id']));

        // Muestra los datos de quienes se postularon: exige correo confirmado
        case 'POSTULACIONES_VACANTE':
            $usr = scSesionVerificada($pdo, $token, 'empresa');
            scRespuesta($vacantes->postulacionesDeVacante((int) $usr['id'], (int) ($_GET['vacante_id'] ?? 0)));

        // ── Administración ───────────────────────────────────
        case 'ADMIN_RESUMEN':
            scSesionAdmin($pdo, $token);
            scRespuesta($admin->resumen());

        case 'ADMIN_LISTAR_USUARIOS':
            scSesionAdmin($pdo, $token);
            scRespuesta($admin->listarUsuarios($payload));

        case 'ADMIN_VER_USUARIO':
            scSesionAdmin($pdo, $token);
            scRespuesta($admin->verUsuario((int) ($_GET['id'] ?? 0)));

        case 'ADMIN_BITACORA':
            scSesionAdmin($pdo, $token);
            scRespuesta($admin->bitacora($payload));

        // ── Candidatos ───────────────────────────────────────
        case 'BUSCAR_CANDIDATOS':
            scSesionVerificada($pdo, $token, 'empresa');
            scRespuesta($personas->buscarCandidatos($payload));

        default:
            scRespuesta(['status' => 'error', 'message' => "Acción GET desconocida: {$action}"], 400);
    }
}

// ══════════════════════════════════════════════════════════
//  RUTAS POST
// ══════════════════════════════════════════════════════════
if ($method === 'POST') {
    switch ($action) {

        // ── Auth ─────────────────────────────────────────
        // Estas acciones NO exigen el correo confirmado a propósito: son
        // justo las que necesita quien todavía no lo confirmó. Si el correo
        // no llegó, el usuario debe poder entrar, pedir otro enlace, cambiar
        // su contraseña y salir; bloquearlas dejaría la cuenta inservible.
        case 'REGISTRO_PERSONA':
            scRespuesta($auth->registrar('persona', $payload));

        case 'REGISTRO_EMPRESA':
            scRespuesta($auth->registrar('empresa', $payload));

        case 'LOGIN':
            scRespuesta($auth->login($payload));

        case 'LOGOUT':
            $usr = scSesion($pdo, $token);
            scRespuesta($auth->logout((int) $usr['id'], $token));

        // Ver y cerrar los dispositivos con sesión abierta. No exige correo
        // verificado: es justo lo que necesita quien sospecha de un acceso.
        case 'CERRAR_OTRAS_SESIONES':
            $usr = scSesion($pdo, $token);
            scRespuesta($auth->cerrarOtrasSesiones((int) $usr['id'], $token));

        // Derechos ARCO: llevarse los datos o borrar la cuenta. Tampoco se
        // bloquean sin verificar — cancelar debe poder hacerlo cualquiera.
        case 'ELIMINAR_CUENTA':
            $usr = scSesion($pdo, $token);
            scRespuesta($auth->eliminarCuenta((int) $usr['id'], $payload));

        case 'REENVIAR_VERIFICACION':
            $usr = scSesion($pdo, $token);
            scRespuesta($auth->reenviarVerificacion((int) $usr['id']));

        // ── Contraseña ───────────────────────────────────
        case 'SOLICITAR_RESET':
            scRespuesta($auth->solicitarReset($payload));

        case 'RESTABLECER_PASSWORD':
            scRespuesta($auth->restablecerPassword($payload));

        case 'CAMBIAR_PASSWORD':
            $usr = scSesion($pdo, $token);
            scRespuesta($auth->cambiarPassword((int) $usr['id'], $payload));

        // ── Perfil persona ─────────────────────────────────
        case 'ACTUALIZAR_PERFIL_PERSONA':
            $usr = scSesionVerificada($pdo, $token, 'persona');
            scRespuesta($personas->actualizarPerfil((int) $usr['id'], $payload));

        case 'SUBIR_CV':
            $usr = scSesionVerificada($pdo, $token, 'persona');
            scRespuesta($personas->subirCV((int) $usr['id'], $_FILES['archivo'] ?? []));

        case 'SUBIR_FOTO':
            $usr = scSesionVerificada($pdo, $token, 'persona');
            scRespuesta($personas->subirFoto((int) $usr['id'], $_FILES['archivo'] ?? []));

        case 'AGREGAR_EXPERIENCIA':
            $usr = scSesionVerificada($pdo, $token, 'persona');
            scRespuesta($personas->agregarExperiencia((int) $usr['id'], $payload));

        case 'ACTUALIZAR_EXPERIENCIA':
            $usr = scSesionVerificada($pdo, $token, 'persona');
            scRespuesta($personas->actualizarExperiencia((int) $usr['id'], $payload));

        case 'ELIMINAR_EXPERIENCIA':
            $usr = scSesionVerificada($pdo, $token, 'persona');
            scRespuesta($personas->eliminarExperiencia((int) $usr['id'], (int) ($payload['id'] ?? 0)));

        case 'AGREGAR_EDUCACION':
            $usr = scSesionVerificada($pdo, $token, 'persona');
            scRespuesta($personas->agregarEducacion((int) $usr['id'], $payload));

        case 'ACTUALIZAR_EDUCACION':
            $usr = scSesionVerificada($pdo, $token, 'persona');
            scRespuesta($personas->actualizarEducacion((int) $usr['id'], $payload));

        case 'ELIMINAR_EDUCACION':
            $usr = scSesionVerificada($pdo, $token, 'persona');
            scRespuesta($personas->eliminarEducacion((int) $usr['id'], (int) ($payload['id'] ?? 0)));

        case 'AGREGAR_HABILIDAD':
            $usr = scSesionVerificada($pdo, $token, 'persona');
            scRespuesta($personas->agregarHabilidad((int) $usr['id'], $payload));

        case 'ELIMINAR_HABILIDAD':
            $usr = scSesionVerificada($pdo, $token, 'persona');
            scRespuesta($personas->eliminarHabilidad((int) $usr['id'], (int) ($payload['id'] ?? 0)));

        // ── Perfil empresa ──────────────────────────────────
        case 'ACTUALIZAR_PERFIL_EMPRESA':
            $usr = scSesionVerificada($pdo, $token, 'empresa');
            scRespuesta($empresas->actualizarPerfil((int) $usr['id'], $payload));

        case 'SUBIR_LOGO':
            $usr = scSesionVerificada($pdo, $token, 'empresa');
            scRespuesta($empresas->subirLogo((int) $usr['id'], $_FILES['archivo'] ?? []));

        // ── Vacantes ────────────────────────────────────────
        case 'CREAR_VACANTE':
            $usr = scSesionVerificada($pdo, $token, 'empresa');
            scRespuesta($vacantes->crear((int) $usr['id'], $payload));

        case 'ACTUALIZAR_VACANTE':
            $usr = scSesionVerificada($pdo, $token, 'empresa');
            scRespuesta($vacantes->actualizar((int) $usr['id'], $payload));

        case 'ELIMINAR_VACANTE':
            $usr = scSesionVerificada($pdo, $token, 'empresa');
            scRespuesta($vacantes->eliminar((int) $usr['id'], (int) ($payload['id'] ?? 0)));

        // ── Postulaciones ───────────────────────────────────
        case 'POSTULAR':
            $usr = scSesionVerificada($pdo, $token, 'persona');
            scRespuesta($vacantes->postular((int) $usr['id'], $payload));

        case 'CAMBIAR_ESTATUS_POSTULACION':
            $usr = scSesionVerificada($pdo, $token, 'empresa');
            scRespuesta($vacantes->cambiarEstatusPostulacion((int) $usr['id'], $payload));

        // ── Administración ──────────────────────────────────
        case 'ADMIN_BLOQUEAR':
            $usr = scSesionAdmin($pdo, $token);
            scRespuesta($admin->bloquear($usr, (int) ($payload['id'] ?? 0), $payload));

        case 'ADMIN_DESBLOQUEAR':
            $usr = scSesionAdmin($pdo, $token);
            scRespuesta($admin->desbloquear($usr, (int) ($payload['id'] ?? 0)));

        case 'ADMIN_ELIMINAR':
            $usr = scSesionAdmin($pdo, $token);
            scRespuesta($admin->eliminar($usr, (int) ($payload['id'] ?? 0), $payload));

        default:
            scRespuesta(['status' => 'error', 'message' => "Acción POST desconocida: {$action}"], 400);
    }
}

// Método no soportado
scRespuesta(['status' => 'error', 'message' => 'Método no soportado.'], 405);
