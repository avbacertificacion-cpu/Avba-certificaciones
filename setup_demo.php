<?php
/**
 * AVBA Certificaciones — Demo Data Seeder
 * Crea el perfil demo "ArcelorMittal" con parque completo de
 * equipos, personal, proveedores, catálogo de materiales, solicitudes de
 * material (en distintos estados) y reportes de mantenimiento con fotos y
 * PDF reales — para que el portal de cliente se vea completamente activo.
 *
 * USO: php setup_demo.php   (o abrir desde navegador con ?secret=avba_setup_2025)
 * Es seguro volver a ejecutarlo: limpia los datos demo previos antes de
 * recrearlos (usuarios/equipos/proveedores/etc. de otros clientes no se tocan).
 * ELIMINAR este archivo del servidor después de ejecutar.
 */

// Las secciones de proveedores/materiales/solicitudes/mantenimiento generan
// varios PDF reales (mPDF) e imágenes en una sola corrida — en hosting
// compartido eso puede superar el límite por defecto de tiempo de ejecución
// o memoria y cortar el script a medias sin ningún error visible (con
// display_errors apagado, como debe estar en producción). Se relajan ambos
// límites para este script en particular; si el hosting no permite
// sobreescribirlos (algunos los bloquean), estas líneas simplemente no
// tienen efecto y no rompen nada.
@set_time_limit(0);
@ini_set('max_execution_time', '0');
@ini_set('memory_limit', '512M');
@ignore_user_abort(true);

// Producción normalmente corre con display_errors apagado, así que un error
// fatal (clase no encontrada, columna inexistente, etc.) simplemente corta
// la salida sin explicación. Este shutdown handler lo imprime igual,
// SOLO en este script de diagnóstico/seeder — no toca la config del sitio.
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        echo "\n\n❌❌❌ ERROR FATAL — el script se detuvo aquí:\n";
        echo "Mensaje: {$err['message']}\n";
        echo "Archivo: {$err['file']}:{$err['line']}\n";
    }
});

if (PHP_SAPI !== 'cli') {
    if (($_GET['secret'] ?? '') !== 'avba_setup_2025') {
        http_response_code(403);
        die('Acceso denegado. Agrega ?secret=avba_setup_2025 a la URL, o ejecuta desde CLI: php setup_demo.php');
    }
    header('Content-Type: text/plain; charset=utf-8');
    // Enviar cada echo al navegador de inmediato en vez de acumularlo en el
    // buffer — así, si algo llegara a fallar a medio camino, se alcanza a
    // ver hasta dónde llegó en vez de recibir una página en blanco.
    while (ob_get_level() > 0) { ob_end_flush(); }
    ob_implicit_flush(true);
}

$cfgFile = __DIR__ . '/config/config.php';
if (!file_exists($cfgFile)) {
    die("❌ No se encontró config/config.php.\n   Copia config/config.example.php → config/config.php y rellena tus credenciales.\n");
}
require_once $cfgFile;

// ── Conexión ──────────────────────────────────────────────────────────────────
$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Exception $e) {
    die("❌ DB error: " . $e->getMessage() . "\n");
}

echo "=== AVBA Demo Seeder ===\n\n";

// ── 1. Crear usuario demo ─────────────────────────────────────────────────────
$demoUsuario = 'Demo';
$demoPass    = password_hash('Demo2026', PASSWORD_DEFAULT);
$demoNombre  = 'ArcelorMittal';
$demoIdCli   = '00001';

// Migrar usuario anterior si aún existe con el nombre viejo
$pdo->prepare("UPDATE usuarios SET usuario=? WHERE usuario='demo@constructorademo.mx'")->execute([$demoUsuario]);

$existing = $pdo->prepare("SELECT id FROM usuarios WHERE usuario = ?");
$existing->execute([$demoUsuario]);
$existingRow = $existing->fetch();

if ($existingRow) {
    echo "ℹ  Usuario ya existe (id={$existingRow['id']}), actualizando...\n";
    $pdo->prepare("UPDATE usuarios SET password_hash=?, nombre=?, id_cliente=?, rol='CLIENTE', activo=1 WHERE usuario=?")
        ->execute([$demoPass, $demoNombre, $demoIdCli, $demoUsuario]);
    $userId = $existingRow['id'];
} else {
    $pdo->prepare("INSERT INTO usuarios (usuario, password_hash, rol, nombre, id_cliente, activo) VALUES (?,?,?,?,?,1)")
        ->execute([$demoUsuario, $demoPass, 'CLIENTE', $demoNombre, $demoIdCli]);
    $userId = $pdo->lastInsertId();
    echo "✅ Usuario creado: $demoUsuario / Demo2026 (id=$userId)\n";
}

// ── 2. Asegurar tablas cliente_equipos ────────────────────────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS cliente_equipos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_cliente VARCHAR(20) NOT NULL,
        nombre VARCHAR(200) NOT NULL,
        tipo VARCHAR(100),
        marca VARCHAR(100),
        modelo VARCHAR(100),
        serie VARCHAR(100),
        capacidad VARCHAR(50),
        anio YEAR,
        notas TEXT,
        estado VARCHAR(50) DEFAULT 'Activo',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )
");
$pdo->exec("ALTER TABLE cliente_equipos ADD COLUMN IF NOT EXISTS estado VARCHAR(50) NULL DEFAULT 'Activo'");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS cliente_equipos_docs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        equipo_id INT NOT NULL,
        tipo_doc VARCHAR(50),
        nombre VARCHAR(200) NOT NULL,
        archivo_url VARCHAR(500),
        vigencia DATE,
        notas TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )
");
$pdo->exec("
    CREATE TABLE IF NOT EXISTS cliente_equipos_horometro (
        id INT AUTO_INCREMENT PRIMARY KEY,
        equipo_id INT NOT NULL,
        horas DECIMAL(10,1) NOT NULL,
        fecha DATE NOT NULL,
        notas TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )
");
$pdo->exec("
    CREATE TABLE IF NOT EXISTS cliente_equipos_cert (
        id INT AUTO_INCREMENT PRIMARY KEY,
        equipo_id INT NOT NULL,
        tipo_cert VARCHAR(200) NOT NULL,
        folio VARCHAR(100),
        fecha_emision DATE,
        fecha_vigencia DATE,
        archivo_url VARCHAR(500),
        notas TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )
");

echo "✅ Tablas verificadas\n";

// ── 3. Limpiar equipos anteriores del demo ────────────────────────────────────
$existingEq = $pdo->prepare("SELECT id FROM cliente_equipos WHERE id_cliente=?");
$existingEq->execute([$demoIdCli]);
foreach ($existingEq->fetchAll() as $eq) {
    $pdo->prepare("DELETE FROM cliente_equipos_docs WHERE equipo_id=?")->execute([$eq['id']]);
    $pdo->prepare("DELETE FROM cliente_equipos_horometro WHERE equipo_id=?")->execute([$eq['id']]);
    $pdo->prepare("DELETE FROM cliente_equipos_cert WHERE equipo_id=?")->execute([$eq['id']]);
}
$pdo->prepare("DELETE FROM cliente_equipos WHERE id_cliente=?")->execute([$demoIdCli]);
echo "🗑  Equipos anteriores eliminados\n";

// ── Helpers ───────────────────────────────────────────────────────────────────
function insertEquipo(PDO $pdo, string $idCli, array $d): int {
    $pdo->prepare("INSERT INTO cliente_equipos (id_cliente,nombre,tipo,marca,modelo,serie,capacidad,anio,notas,estado) VALUES(?,?,?,?,?,?,?,?,?,?)")
        ->execute([$idCli,$d['nombre'],$d['tipo'],$d['marca'],$d['modelo'],$d['serie'],$d['capacidad'],$d['anio'],$d['notas'],$d['estado']]);
    return (int)$pdo->lastInsertId();
}
function addDoc(PDO $pdo, int $eqId, string $tipo, string $nombre, ?string $vig, string $notas='') {
    $pdo->prepare("INSERT INTO cliente_equipos_docs (equipo_id,tipo_doc,nombre,archivo_url,vigencia,notas) VALUES(?,?,?,NULL,?,?)")
        ->execute([$eqId,$tipo,$nombre,$vig,$notas]);
}
function addHora(PDO $pdo, int $eqId, float $horas, string $fecha, string $notas='') {
    $pdo->prepare("INSERT INTO cliente_equipos_horometro (equipo_id,horas,fecha,notas) VALUES(?,?,?,?)")
        ->execute([$eqId,$horas,$fecha,$notas]);
}
function addCert(PDO $pdo, int $eqId, string $tipo, string $folio, ?string $emision, ?string $vigencia, string $notas='') {
    $pdo->prepare("INSERT INTO cliente_equipos_cert (equipo_id,tipo_cert,folio,fecha_emision,fecha_vigencia,notas) VALUES(?,?,?,?,?,?)")
        ->execute([$eqId,$tipo,$folio,$emision,$vigencia,$notas]);
}
function dateAdd(string $base, string $modify): string {
    $d = new DateTime($base); $d->modify($modify); return $d->format('Y-m-d');
}
$today = date('Y-m-d');
$y = date('Y');

// ────────────────────────────────────────────────────────────────────────────
// EQUIPOS DEMO
// ────────────────────────────────────────────────────────────────────────────
$eqIds = []; // slug => id, para referenciar los equipos desde mantenimiento/solicitudes

// 1 — Grúa Torre Liebherr 160 EC-B ─────────────────────────────────────────
$id = insertEquipo($pdo, $demoIdCli, [
    'nombre'   => 'Grúa Torre Liebherr 160 EC-B',
    'tipo'     => 'Grúa Torre',
    'marca'    => 'Liebherr',
    'modelo'   => '160 EC-B',
    'serie'    => 'LHR-2019-4821',
    'capacidad'=> '8 ton',
    'anio'     => 2019,
    'notas'    => 'Grúa torre principal de obra, operando en proyecto norte. Requiere inspección semestral.',
    'estado'   => 'Activo',
]);
addDoc($pdo,$id,'bitacora','Bitácora de operación 2025',dateAdd($today,'+8 months'),'Registro diario de operaciones');
addDoc($pdo,$id,'seguro','Seguro de responsabilidad civil 2025',dateAdd($today,'+7 months'),'Póliza No. RC-2025-04821');
addDoc($pdo,$id,'tabla_carga','Tabla de cargas radios/plumas',null,'Tablas originales fabricante Liebherr');
addDoc($pdo,$id,'manual','Manual de operación y mantenimiento',null,'Rev. 4 — Español');
addDoc($pdo,$id,'otro','Registro de montaje y desmontaje',dateAdd($today,'+11 months'),'Autorización STPS montaje en sitio norte');
addHora($pdo,$id,1240.5,'2025-01-15','Inicio de conteo anual');
addHora($pdo,$id,2180.0,'2025-03-01','Post mantenimiento mayor 2000h');
addHora($pdo,$id,3450.5,'2025-05-15','Revisión trimestral');
addHora($pdo,$id,4820.0,$today,'Lectura actual');
addCert($pdo,$id,'Certificado de Inspección Anual','AB.2024-GT-001',dateAdd($today,'-8 months'),dateAdd($today,'+4 months'),'Inspección conforme NMX-B-482');
addCert($pdo,$id,'Certificado del Operador — Grúas Torre','AVBA-OP-2024-112',dateAdd($today,'-6 months'),dateAdd($today,'+6 months'),'Operador: Juan Carlos Méndez');
addCert($pdo,$id,'Verificación de Estructura Metálica','EST-2024-087',dateAdd($today,'-3 months'),dateAdd($today,'+9 months'),'Sin fisuras detectadas');
addCert($pdo,$id,'Permiso de Operación Municipal','PERM-MUN-2025-003',dateAdd($today,'-1 months'),dateAdd($today,'+20 days'),'⚠️ Por renovar — próximo a vencer');
$eqIds['grua_torre'] = $id;
echo "✅ Grúa Torre Liebherr\n";

// 2 — Grúa Móvil Grove GMK5150L ────────────────────────────────────────────
$id = insertEquipo($pdo, $demoIdCli, [
    'nombre'   => 'Grúa Móvil Grove GMK5150L',
    'tipo'     => 'Grúa Móvil',
    'marca'    => 'Grove',
    'modelo'   => 'GMK5150L',
    'serie'    => 'GRV-GMK5-18-0934',
    'capacidad'=> '150 ton',
    'anio'     => 2018,
    'notas'    => 'Grúa todo terreno de 150 ton. Disponible para maniobras de gran envergadura.',
    'estado'   => 'Activo',
]);
addDoc($pdo,$id,'bitacora','Bitácora de operaciones 2024-2025',dateAdd($today,'+5 months'));
addDoc($pdo,$id,'seguro','Póliza de seguro amplio 2025',dateAdd($today,'+3 months'),'Aseguradora: Mapfre México');
addDoc($pdo,$id,'tabla_carga','Tabla de capacidades GMK5150L',null,'Múltiples configuraciones de pluma');
addDoc($pdo,$id,'tabla_carga','Plan de izaje — Proyecto Sur',null,'Cálculo de radio y ángulos aprobado');
addDoc($pdo,$id,'manual','Manual de configuración y montaje',null,'Incluye outriggers y superlift');
addDoc($pdo,$id,'otro','Certificado de pesaje (tara)',dateAdd($today,'+14 months'),'Báscula certificada');
addHora($pdo,$id,8200.0,'2024-12-01','Cierre de año');
addHora($pdo,$id,9100.5,'2025-02-15','Post overhaul motor');
addHora($pdo,$id,9850.0,'2025-04-01','Revisión pre-maniobra proyecto');
addHora($pdo,$id,10420.5,$today,'Lectura actual');
addCert($pdo,$id,'Certificado de Inspección Anual — Grúa Móvil','AB.2025-GM-007','2025-01-10',dateAdd($today,'+9 months'),'Inspección completa conforme');
addCert($pdo,$id,'Certificado del Operador','AVBA-OP-2024-089','2024-08-20',dateAdd($today,'+15 days'),'⚠️ Vence muy pronto — operador: Roberto Silva');
addCert($pdo,$id,'Revisión Técnica Vehicular','RTV-2025-GM-001','2025-03-01',dateAdd($today,'+8 months'),'Grúa en óptimas condiciones');
addCert($pdo,$id,'Permiso de Circulación Especial','PERM-SCT-2025-117','2025-02-01',dateAdd($today,'+25 days'),'Para transporte en carretera federal');
$eqIds['grua_movil'] = $id;
echo "✅ Grúa Móvil Grove GMK5150L\n";

// 3 — Grúa Viajera Demag KBK-II ────────────────────────────────────────────
$id = insertEquipo($pdo, $demoIdCli, [
    'nombre'   => 'Grúa Viajera Demag KBK-II 5t',
    'tipo'     => 'Grúa Viajera',
    'marca'    => 'Demag',
    'modelo'   => 'KBK-II',
    'serie'    => 'DEM-KBK-2016-0551',
    'capacidad'=> '5 ton',
    'anio'     => 2016,
    'notas'    => 'Instalada en taller de producción Nave A. En mantenimiento preventivo programado.',
    'estado'   => 'Mantenimiento',
]);
addDoc($pdo,$id,'bitacora','Bitácora de mantenimiento 2025',dateAdd($today,'+6 months'),'Registro de intervenciones');
addDoc($pdo,$id,'seguro','Seguro de maquinaria fija',dateAdd($today,'+8 months'));
addDoc($pdo,$id,'otro','Orden de mantenimiento MP-2025-034',null,'Cambio de rodamientos y lubricación general');
addDoc($pdo,$id,'tabla_carga','Tabla de carga KBK-II',null,'Capacidad según radio de operación');
addHora($pdo,$id,4200.0,'2024-11-30');
addHora($pdo,$id,4350.0,'2025-01-31','Antes de entrar a mantenimiento');
addCert($pdo,$id,'Certificado de Inspección Anual','AB.2024-GV-003','2024-06-15',dateAdd($today,'-45 days'),'❌ VENCIDA — requiere nueva inspección');
addCert($pdo,$id,'Certificado del Operador (Polipasto)','AVBA-OP-2024-055','2024-05-01',dateAdd($today,'+7 months'));
$eqIds['grua_viajera'] = $id;
echo "✅ Grúa Viajera Demag KBK-II\n";

// 4 — PTEM Genie Z-62/40 ───────────────────────────────────────────────────
$id = insertEquipo($pdo, $demoIdCli, [
    'nombre'   => 'PTEM Genie Z-62/40',
    'tipo'     => 'Plataforma de Trabajo de Elevación Móvil',
    'marca'    => 'Genie',
    'modelo'   => 'Z-62/40',
    'serie'    => 'GEN-Z62-2020-8871',
    'capacidad'=> '230 kg',
    'anio'     => 2020,
    'notas'    => 'Plataforma articulada de 18.9m de altura. En buen estado.',
    'estado'   => 'Activo',
]);
addDoc($pdo,$id,'bitacora','Bitácora de operación PTEM 2025',dateAdd($today,'+9 months'));
addDoc($pdo,$id,'seguro','Seguro responsabilidad civil',dateAdd($today,'+4 months'),'Incluye daños a terceros');
addDoc($pdo,$id,'manual','Manual de operación Z-62/40',null,'En español, incluye check-list pre-operación');
addDoc($pdo,$id,'tabla_carga','Diagrama de capacidades y radios',null,'Para superficies horizontales y máx. 5% pendiente');
addDoc($pdo,$id,'otro','Protocolo de rescate en altura',null,'Procedimiento interno aprobado');
addHora($pdo,$id,620.0,'2024-12-31');
addHora($pdo,$id,890.5,'2025-03-31');
addHora($pdo,$id,1105.0,$today,'Lectura actual');
addCert($pdo,$id,'Certificado de Inspección PTEM','AB.2025-PT-001','2025-01-15',dateAdd($today,'+10 months'),'Conforme NOM-009-STPS');
addCert($pdo,$id,'Certificado del Operador en Altura','AVBA-OP-2025-021','2025-02-01',dateAdd($today,'+11 months'),'Operador: Luis Alberto García');
addCert($pdo,$id,'Verificación de Arneses y EPP','EPP-2025-PTEM-007','2025-04-01',dateAdd($today,'+10 months'),'10 arneses revisados y aprobados');
$eqIds['ptem_genie'] = $id;
echo "✅ PTEM Genie Z-62/40\n";

// 5 — PTEM JLG 600AJ ────────────────────────────────────────────────────────
$id = insertEquipo($pdo, $demoIdCli, [
    'nombre'   => 'PTEM JLG 600AJ',
    'tipo'     => 'Plataforma de Trabajo de Elevación Móvil',
    'marca'    => 'JLG',
    'modelo'   => '600AJ',
    'serie'    => 'JLG-600AJ-2021-3342',
    'capacidad'=> '227 kg',
    'anio'     => 2021,
    'notas'    => 'En inspección técnica semestral. Regresa a operación en 5 días.',
    'estado'   => 'Inspección',
]);
addDoc($pdo,$id,'bitacora','Bitácora de operación 2025',dateAdd($today,'+10 months'));
addDoc($pdo,$id,'seguro','Seguro amplio 2025',dateAdd($today,'+6 months'));
addDoc($pdo,$id,'otro','Reporte de inspección técnica S1-2025',null,'En proceso — pendiente firma del inspector');
addHora($pdo,$id,410.0,'2025-01-15');
addHora($pdo,$id,680.0,'2025-04-30');
addHora($pdo,$id,780.5,$today,'Pre-inspección');
addCert($pdo,$id,'Certificado de Inspección PTEM','AB.2024-PT-008','2024-06-20',dateAdd($today,'-30 days'),'❌ VENCIDA — inspección en proceso');
addCert($pdo,$id,'Certificado del Operador','AVBA-OP-2025-033','2025-03-01',dateAdd($today,'+9 months'));
$eqIds['ptem_jlg'] = $id;
echo "✅ PTEM JLG 600AJ\n";

// 6 — Montacargas Toyota 8FBE20 ────────────────────────────────────────────
$id = insertEquipo($pdo, $demoIdCli, [
    'nombre'   => 'Montacargas Toyota 8FBE20',
    'tipo'     => 'Montacargas',
    'marca'    => 'Toyota',
    'modelo'   => '8FBE20',
    'serie'    => 'TOY-8FBE-2017-7723',
    'capacidad'=> '2,000 kg',
    'anio'     => 2017,
    'notas'    => 'Montacargas eléctrico para almacén principal. Cargador de baterías incluido.',
    'estado'   => 'Activo',
]);
addDoc($pdo,$id,'bitacora','Registro diario de operaciones',dateAdd($today,'+3 months'));
addDoc($pdo,$id,'seguro','Seguro de responsabilidad civil',dateAdd($today,'+5 months'));
addDoc($pdo,$id,'manual','Manual de operador Toyota',null);
addDoc($pdo,$id,'otro','Programa de mantenimiento PM baterías',null,'Revisión mensual de celdas');
addDoc($pdo,$id,'tabla_carga','Diagrama de capacidades',null);
addHora($pdo,$id,5800.0,'2024-11-30');
addHora($pdo,$id,6200.0,'2025-01-31');
addHora($pdo,$id,6650.0,'2025-03-31');
addHora($pdo,$id,7050.5,$today);
addCert($pdo,$id,'Certificado de Inspección Anual — Montacargas','AB.2025-MF-002','2025-02-10',dateAdd($today,'+8 months'),'Conforme NOM-006-STPS');
addCert($pdo,$id,'Certificado del Operador de Montacargas','AVBA-OP-2024-101','2024-11-01',dateAdd($today,'+5 months'));
addCert($pdo,$id,'Revisión de Mástil y Horquillas','MAST-2025-001','2025-01-15',dateAdd($today,'+7 months'),'Sin deformaciones ni grietas');
$eqIds['montacargas'] = $id;
echo "✅ Montacargas Toyota 8FBE20\n";

// 7 — Camión Grúa Terex AC 100 ─────────────────────────────────────────────
$id = insertEquipo($pdo, $demoIdCli, [
    'nombre'   => 'Camión Grúa Terex AC 100-4L',
    'tipo'     => 'Grúa Móvil',
    'marca'    => 'Terex',
    'modelo'   => 'AC 100-4L',
    'serie'    => 'TEX-AC100-2015-0219',
    'capacidad'=> '100 ton',
    'anio'     => 2015,
    'notas'    => 'Grúa de 100 ton de capacidad. Certificación próxima a vencer — agendar inspección.',
    'estado'   => 'Activo',
]);
addDoc($pdo,$id,'bitacora','Bitácora anual 2025',dateAdd($today,'+6 months'));
addDoc($pdo,$id,'seguro','Póliza de daños y responsabilidad civil',dateAdd($today,'+12 days'),'⚠️ Renovar póliza urgente');
addDoc($pdo,$id,'tabla_carga','Tablas de carga AC 100-4L',null,'Pluma principal + fly jib');
addDoc($pdo,$id,'manual','Manual de mantenimiento Terex',null);
addDoc($pdo,$id,'otro','Licencia federal de transporte','2025-12-31');
addHora($pdo,$id,14200.0,'2024-10-31');
addHora($pdo,$id,14800.0,'2025-01-31');
addHora($pdo,$id,15350.0,$today,'Turno regular');
addCert($pdo,$id,'Certificado de Inspección Anual — Grúa Móvil','AB.2024-GM-012','2024-05-20',dateAdd($today,'-60 days'),'❌ VENCIDA — requiere inspección urgente');
addCert($pdo,$id,'Certificado del Operador','AVBA-OP-2024-044','2024-04-01',dateAdd($today,'+8 months'),'Operador: Pedro Ramírez López');
addCert($pdo,$id,'Revisión Técnica Vehicular','RTV-2024-003','2024-07-01',dateAdd($today,'+22 days'),'⚠️ Por vencer');
$eqIds['camion_grua'] = $id;
echo "✅ Camión Grúa Terex AC 100\n";

// 8 — Camión Kenworth T680 ──────────────────────────────────────────────────
$id = insertEquipo($pdo, $demoIdCli, [
    'nombre'   => 'Camión Kenworth T680',
    'tipo'     => 'Camión',
    'marca'    => 'Kenworth',
    'modelo'   => 'T680',
    'serie'    => 'KW-T680-2022-0814',
    'capacidad'=> '18 ton',
    'anio'     => 2022,
    'notas'    => 'Camión de traslado de equipos. Caja seca 53 pies. Buen estado general.',
    'estado'   => 'Activo',
]);
addDoc($pdo,$id,'bitacora','Bitácora de operaciones y combustible',dateAdd($today,'+8 months'));
addDoc($pdo,$id,'seguro','Seguro amplio tractocamión 2025',dateAdd($today,'+9 months'));
addDoc($pdo,$id,'otro','Licencia federal de autotransporte',dateAdd($today,'+18 months'));
addDoc($pdo,$id,'otro','Verificación emisiones contaminantes',dateAdd($today,'+5 months'));
addDoc($pdo,$id,'manual','Manual de operador T680',null);
addHora($pdo,$id,120000.0,'2024-12-31','Km recorridos (×10)');
addHora($pdo,$id,135000.0,'2025-03-31','Km acumulados');
addHora($pdo,$id,148500.0,$today,'Lectura odómetro actual');
addCert($pdo,$id,'Verificación Vehicular','VERIF-2025-KW-001','2025-01-20',dateAdd($today,'+11 months'));
addCert($pdo,$id,'Licencia de Operador Clase E','LIC-FED-2024-0234','2024-09-01',dateAdd($today,'+15 months'),'Operador: Marco Antonio Torres');
$eqIds['camion_kw'] = $id;
echo "✅ Camión Kenworth T680\n";

// 9 — Retroexcavadora Caterpillar 323 ──────────────────────────────────────
$id = insertEquipo($pdo, $demoIdCli, [
    'nombre'   => 'Retroexcavadora Caterpillar 323',
    'tipo'     => 'Retroexcavadora',
    'marca'    => 'Caterpillar',
    'modelo'   => '323',
    'serie'    => 'CAT-323-2020-KWX',
    'capacidad'=> '22 ton',
    'anio'     => 2020,
    'notas'    => 'Retroexcavadora hidráulica 23t. Equipada con cucharón de 1.2m³ y martillo.',
    'estado'   => 'Activo',
]);
addDoc($pdo,$id,'bitacora','Bitácora de operaciones obra',dateAdd($today,'+7 months'));
addDoc($pdo,$id,'seguro','Seguro de maquinaria y responsabilidad',dateAdd($today,'+6 months'));
addDoc($pdo,$id,'manual','Manual de mantenimiento CAT 323',null);
addDoc($pdo,$id,'otro','Programa de lubricación y filtros',null,'Según plan Caterpillar PM');
addHora($pdo,$id,3200.0,'2024-11-30');
addHora($pdo,$id,3800.0,'2025-02-28');
addHora($pdo,$id,4320.5,'2025-05-31');
addHora($pdo,$id,4680.0,$today);
addCert($pdo,$id,'Certificado de Operador de Maquinaria Pesada','AVBA-OP-2025-044','2025-03-01',dateAdd($today,'+9 months'),'Operador: Alejandro Fuentes');
addCert($pdo,$id,'Revisión de Estructura y Sistemas Hidráulicos','HID-2025-CAT-001','2025-01-10',dateAdd($today,'+7 months'),'Sin fugas, presiones correctas');
$eqIds['retro'] = $id;
echo "✅ Retroexcavadora CAT 323\n";

// 10 — Grúa Puente 10t ─────────────────────────────────────────────────────
$id = insertEquipo($pdo, $demoIdCli, [
    'nombre'   => 'Grúa Puente 10t — Nave Industrial B',
    'tipo'     => 'Grúa Puente',
    'marca'    => 'Abus',
    'modelo'   => 'ZLK 10-18',
    'serie'    => 'ABUS-ZLK-2014-NB',
    'capacidad'=> '10 ton',
    'anio'     => 2014,
    'notas'    => 'Instalada en Nave B, tramo de 18m. Requiere revisión de carrilería. Fuera de servicio temporalmente.',
    'estado'   => 'Fuera de servicio',
]);
addDoc($pdo,$id,'bitacora','Historial de intervenciones',null,'Registro desde instalación 2014');
addDoc($pdo,$id,'seguro','Seguro maquinaria fija instalada',dateAdd($today,'+3 months'));
addDoc($pdo,$id,'otro','Dictamen de revisión carrilería',null,'Detectadas deformaciones — en corrección');
addDoc($pdo,$id,'otro','Cotización reparación estructural',null,'Aprobada por gerencia — en ejecución');
addHora($pdo,$id,2100.0,'2024-06-30');
addHora($pdo,$id,2250.0,'2024-12-31');
addCert($pdo,$id,'Certificado de Inspección Anual — Grúa Puente','AB.2024-GP-001','2024-03-10',dateAdd($today,'-90 days'),'❌ VENCIDA — equipo fuera de servicio');
addCert($pdo,$id,'Verificación de Estructura Metálica','EST-2023-GP-001','2023-10-01',dateAdd($today,'-180 days'),'❌ VENCIDA — pendiente reparación');
$eqIds['grua_puente'] = $id;
echo "✅ Grúa Puente Abus 10t\n";

// 11 — Camioneta F-350 ─────────────────────────────────────────────────────
$id = insertEquipo($pdo, $demoIdCli, [
    'nombre'   => 'Camioneta Ford F-350 4x4',
    'tipo'     => 'Camión',
    'marca'    => 'Ford',
    'modelo'   => 'F-350 XLT',
    'serie'    => 'FORD-F350-2023-0091',
    'capacidad'=> '1.5 ton',
    'anio'     => 2023,
    'notas'    => 'Vehículo de servicio para supervisión y traslado de personal.',
    'estado'   => 'Activo',
]);
addDoc($pdo,$id,'seguro','Seguro amplio Ford F-350',dateAdd($today,'+14 months'));
addDoc($pdo,$id,'otro','Verificación vehicular 2025',dateAdd($today,'+17 months'));
addDoc($pdo,$id,'bitacora','Bitácora de combustible y servicios',dateAdd($today,'+10 months'));
addHora($pdo,$id,42000.0,'2025-03-31','Km acumulados');
addHora($pdo,$id,48500.0,$today,'Km actuales');
addCert($pdo,$id,'Verificación Vehicular (tenencia)',dateAdd($today,'+3 months'),'VV-F350-2025',null,dateAdd($today,'+14 months'));
$eqIds['f350'] = $id;
echo "✅ Camioneta Ford F-350\n";

// 12 — Conjunto de Accesorios de Izaje ─────────────────────────────────────
$id = insertEquipo($pdo, $demoIdCli, [
    'nombre'   => 'Set Accesorios de Izaje — Juego 01',
    'tipo'     => 'Accesorios de Izaje',
    'marca'    => 'Varios',
    'modelo'   => 'Mixto',
    'serie'    => 'ACC-SET-01',
    'capacidad'=> 'Hasta 20 ton (según accesorio)',
    'anio'     => 2021,
    'notas'    => 'Conjunto: eslingas de cable, grilletes, ganchos y polipastos. Almacenados en rack de seguridad.',
    'estado'   => 'Activo',
]);
addDoc($pdo,$id,'bitacora','Registro de inspección pre-uso',dateAdd($today,'+4 months'),'Revisión mensual obligatoria');
addDoc($pdo,$id,'otro','Inventario detallado de accesorios',null,'12 eslingas, 24 grilletes, 8 polipastos');
addDoc($pdo,$id,'tabla_carga','Tablas de carga por accesorio',null,'WLL según norma EN-818');
addDoc($pdo,$id,'manual','Procedimiento de inspección y uso',null,'Basado en ASME B30.9');
addCert($pdo,$id,'Certificación de Accesorios de Izaje — Lote A','AB.2025-ACC-001','2025-02-15',dateAdd($today,'+8 months'),'Eslingas 1t-10t — conforme');
addCert($pdo,$id,'Certificación de Accesorios de Izaje — Lote B','AB.2025-ACC-002','2025-02-15',dateAdd($today,'+8 months'),'Grilletes y ganchos — conforme');
addCert($pdo,$id,'Recertificación de Polipastos','AB.2024-POL-003','2024-09-01',dateAdd($today,'+18 days'),'⚠️ Por vencer pronto');
$eqIds['accesorios'] = $id;
echo "✅ Accesorios de Izaje\n";

// ══════════════════════════════════════════════════════════════════════════
// 3. PERSONAL DEMO
// ══════════════════════════════════════════════════════════════════════════

// Ensure tables
$pdo->exec("
    CREATE TABLE IF NOT EXISTS cliente_personal (
      id INT AUTO_INCREMENT PRIMARY KEY,
      id_cliente VARCHAR(20) NOT NULL,
      nombre VARCHAR(200) NOT NULL,
      puesto VARCHAR(100),
      departamento VARCHAR(100),
      numero_empleado VARCHAR(50),
      fecha_ingreso DATE,
      telefono VARCHAR(30),
      notas TEXT,
      estado VARCHAR(30) NOT NULL DEFAULT 'Activo',
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$pdo->exec("
    CREATE TABLE IF NOT EXISTS cliente_personal_cert (
      id INT AUTO_INCREMENT PRIMARY KEY,
      personal_id INT NOT NULL,
      tipo_cert VARCHAR(150) NOT NULL,
      folio VARCHAR(100),
      entidad VARCHAR(150),
      fecha_emision DATE,
      fecha_vigencia DATE,
      archivo_url VARCHAR(500),
      notas TEXT,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$pdo->exec("
    CREATE TABLE IF NOT EXISTS cliente_personal_docs (
      id INT AUTO_INCREMENT PRIMARY KEY,
      personal_id INT NOT NULL,
      tipo_doc VARCHAR(50) NOT NULL DEFAULT 'otro',
      nombre VARCHAR(200) NOT NULL,
      archivo_url VARCHAR(500),
      vigencia DATE,
      notas TEXT,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
echo "✅ Tablas de personal verificadas\n";

// Limpiar personal previo
$pdo->exec("
    DELETE cpd FROM cliente_personal_docs cpd
    JOIN cliente_personal cp ON cp.id = cpd.personal_id
    WHERE cp.id_cliente = '$demoIdCli'
");
$pdo->exec("
    DELETE cpc FROM cliente_personal_cert cpc
    JOIN cliente_personal cp ON cp.id = cpc.personal_id
    WHERE cp.id_cliente = '$demoIdCli'
");
$pdo->exec("DELETE FROM cliente_personal WHERE id_cliente = '$demoIdCli'");
echo "✅ Personal previo eliminado\n\n";

// ── Helpers Personal ─────────────────────────────────────────────────────
function addPers(PDO $pdo, string $idCli, string $nombre, string $puesto, string $dpto, string $numEmp, string $ingreso, string $tel, string $notas, string $estado = 'Activo'): int {
    $pdo->prepare("
        INSERT INTO cliente_personal (id_cliente,nombre,puesto,departamento,numero_empleado,fecha_ingreso,telefono,notas,estado)
        VALUES (?,?,?,?,?,?,?,?,?)
    ")->execute([$idCli, $nombre, $puesto, $dpto, $numEmp, $ingreso, $tel, $notas, $estado]);
    return (int)$pdo->lastInsertId();
}
function addPCert(PDO $pdo, int $pid, string $tipo, ?string $folio, string $entidad, ?string $emision, ?string $vigencia, string $notas = ''): void {
    $pdo->prepare("INSERT INTO cliente_personal_cert (personal_id,tipo_cert,folio,entidad,fecha_emision,fecha_vigencia,notas) VALUES (?,?,?,?,?,?,?)")
        ->execute([$pid, $tipo, $folio ?: null, $entidad, $emision ?: null, $vigencia ?: null, $notas]);
}
function addPDoc(PDO $pdo, int $pid, string $tipo, string $nombre, ?string $vigencia, string $notas = ''): void {
    $pdo->prepare("INSERT INTO cliente_personal_docs (personal_id,tipo_doc,nombre,vigencia,notas) VALUES (?,?,?,?,?)")
        ->execute([$pid, $tipo, $nombre, $vigencia ?: null, $notas]);
}

echo "=== CREANDO PERSONAL ===\n";

// 1. Operador de Grúa Torre — certificaciones vigentes
$id = addPers($pdo,$demoIdCli,'Carlos Ramírez Fuentes','Operador de Grúa Torre','Operaciones','EMP-001','2019-03-15','55 1234 5001','Operador Sr. con 7 años de exp.');
addPCert($pdo,$id,'Operador de Grúa Torre — NOM-006-STPS','AB.2025-OGT-001','AVBA Certificaciones','2025-01-10',dateAdd($today,'+7 months'),'Grúa Torre >100t');
addPCert($pdo,$id,'Manejo Seguro de Cargas','AB.2024-MSC-022','AVBA Certificaciones','2024-06-20',dateAdd($today,'+2 months'),'');
addPCert($pdo,$id,'Seguridad en Alturas','STPS-2024-SA-089','STPS','2024-09-01',dateAdd($today,'+3 months'),'Nivel III');
addPDoc($pdo,$id,'licencia','Licencia Federal de Grúas Cat. G',dateAdd($today,'+14 months'),'Tipo G — Vigente');
addPDoc($pdo,$id,'antidoping','Antidoping negativo — 2025',dateAdd($today,'+6 months'),'Lab. Médico Central');
addPDoc($pdo,$id,'medico','Examen médico ocupacional',dateAdd($today,'+4 months'),'Apto para trabajos en altura');
echo "✅ Carlos Ramírez (Operador Grúa Torre)\n";

// 2. Operador de Grúa Móvil — cert por vencer
$id = addPers($pdo,$demoIdCli,'Miguel Ángel Torres López','Operador de Grúa Móvil','Operaciones','EMP-002','2020-07-01','55 1234 5002','Operador de grúa all-terrain');
addPCert($pdo,$id,'Operador de Grúa Móvil — NOM-006-STPS','AB.2025-OGM-004','AVBA Certificaciones','2025-02-15',dateAdd($today,'+25 days'),'⚠️ Renovar pronto');
addPCert($pdo,$id,'Señalero de Grúa','AB.2025-SG-009','AVBA Certificaciones','2025-01-20',dateAdd($today,'+6 months'),'');
addPCert($pdo,$id,'Primeros Auxilios','IMSS-2024-PA-112','IMSS','2024-05-10',dateAdd($today,'+5 months'),'');
addPDoc($pdo,$id,'licencia','Licencia Federal Cat. G',dateAdd($today,'+8 months'),'');
addPDoc($pdo,$id,'antidoping','Antidoping 2025',dateAdd($today,'+4 months'),'');
echo "✅ Miguel Torres (Operador Grúa Móvil)\n";

// 3. Rigger / Aparejador — certs por vencer próximas
$id = addPers($pdo,$demoIdCli,'José Luis Hernández Cruz','Rigger / Aparejador','Operaciones','EMP-003','2018-11-05','55 1234 5003','');
addPCert($pdo,$id,'Rigger Nivel II — ASME B30.9','AB.2024-RIG-018','AVBA Certificaciones','2024-12-01',dateAdd($today,'+18 days'),'Renovar antes del vencimiento');
addPCert($pdo,$id,'Inspección de Accesorios de Izaje','AB.2025-IAI-033','AVBA Certificaciones','2025-03-01',dateAdd($today,'+9 months'),'');
addPDoc($pdo,$id,'antidoping','Antidoping 2025',dateAdd($today,'+3 months'),'');
addPDoc($pdo,$id,'medico','Examen médico pre-empleo',null,'Sin vigencia definida');
echo "✅ José Hernández (Rigger)\n";

// 4. Inspector de Seguridad — todo vigente
$id = addPers($pdo,$demoIdCli,'Fernanda Gómez Villanueva','Inspector HSE','Seguridad','EMP-004','2021-04-12','55 1234 5004','Coordinadora HSE en obra');
addPCert($pdo,$id,'Coordinador de Seguridad IMSS','IMSS-2025-CSO-077','IMSS','2025-01-05',dateAdd($today,'+11 months'),'');
addPCert($pdo,$id,'NOM-031-STPS: Construcción','STPS-2024-031-112','STPS','2024-08-20',dateAdd($today,'+8 months'),'');
addPCert($pdo,$id,'Auditor Interno ISO 45001','DNV-2024-AI-003','DNV GL','2024-10-10',dateAdd($today,'+10 months'),'');
addPDoc($pdo,$id,'licencia','Cédula profesional Ing. Industrial',null,'Sin vigencia');
addPDoc($pdo,$id,'medico','Examen médico anual',dateAdd($today,'+6 months'),'Apta');
addPDoc($pdo,$id,'constancia','Constancia STPS NOM-035',dateAdd($today,'+12 months'),'');
echo "✅ Fernanda Gómez (Inspector HSE)\n";

// 5. Técnico de Mantenimiento
$id = addPers($pdo,$demoIdCli,'Roberto Díaz Morales','Técnico Mecánico','Mantenimiento','EMP-005','2022-01-18','55 1234 5005','Especialista en grúas y montacargas');
addPCert($pdo,$id,'Mantenimiento de Grúas Industriales','AVBA-2025-MTG-011','AVBA Certificaciones','2025-02-01',dateAdd($today,'+8 months'),'');
addPCert($pdo,$id,'Electricidad Industrial Básica','INA-2024-EIB-234','INEA','2024-07-15',dateAdd($today,'+7 months'),'');
addPDoc($pdo,$id,'antidoping','Antidoping anual',dateAdd($today,'+3 months'),'');
addPDoc($pdo,$id,'constancia','Constancia capacitación mantenimiento preventivo',dateAdd($today,'+12 months'),'');
echo "✅ Roberto Díaz (Técnico Mantenimiento)\n";

// 6. Supervisor de Operaciones
$id = addPers($pdo,$demoIdCli,'Laura Sánchez Pedraza','Supervisora de Operaciones','Operaciones','EMP-006','2017-06-08','55 1234 5006','9 años de experiencia en izaje');
addPCert($pdo,$id,'Planificación de Izajes Complejos','AB.2025-PIC-002','AVBA Certificaciones','2025-01-25',dateAdd($today,'+9 months'),'Izajes críticos >100t');
addPCert($pdo,$id,'Supervisor de Obra STPS','STPS-2024-SUP-033','STPS','2024-11-01',dateAdd($today,'+11 months'),'');
addPCert($pdo,$id,'Manejo de Materiales Peligrosos','PEMEX-2024-MMP-007','PEMEX Capacitación','2024-08-10',dateAdd($today,'+2 months'),'Clase C y D');
addPDoc($pdo,$id,'licencia','Licencia de conducir Tipo C',dateAdd($today,'+20 months'),'');
addPDoc($pdo,$id,'medico','Examen médico anual',dateAdd($today,'+2 months'),'⚠️ Por renovar');
echo "✅ Laura Sánchez (Supervisora Operaciones)\n";

// 7. Operador de Montacargas — cert VENCIDA
$id = addPers($pdo,$demoIdCli,'Ernesto Vega Castillo','Operador de Montacargas','Operaciones','EMP-007','2023-03-20','55 1234 5007','');
addPCert($pdo,$id,'Operador de Montacargas — NOM-006','AB.2023-OMC-007','AVBA Certificaciones','2023-09-15',dateAdd($today,'-2 months'),'⚠️ VENCIDA — requiere renovación urgente');
addPCert($pdo,$id,'Primeros Auxilios Básico','IMSS-2024-PAB-045','IMSS','2024-03-10',dateAdd($today,'+4 months'),'');
addPDoc($pdo,$id,'antidoping','Antidoping 2024',dateAdd($today,'+2 months'),'');
echo "✅ Ernesto Vega (Operador Montacargas — cert vencida)\n";

// 8. Ingeniero de Proyectos
$id = addPers($pdo,$demoIdCli,'Alejandro Moreno Ruiz','Ingeniero de Proyectos','Ingeniería','EMP-008','2020-09-01','55 1234 5008','Diseño de maniobras de izaje');
addPCert($pdo,$id,'Diseño de Maniobras de Izaje','AB.2025-DMI-003','AVBA Certificaciones','2025-02-20',dateAdd($today,'+14 months'),'');
addPCert($pdo,$id,'AutoCAD Avanzado',null,'Autodesk','2024-04-01',null,'Sin vigencia');
addPCert($pdo,$id,'Cálculo Estructural AISC','AISC-2024-CE-018','AISC','2024-06-15',dateAdd($today,'+6 months'),'');
addPDoc($pdo,$id,'licencia','Cédula profesional Ing. Civil',null,'Sin vigencia');
addPDoc($pdo,$id,'constancia','Certificación BIM Nivel 2',null,'Sin vigencia');
echo "✅ Alejandro Moreno (Ingeniero Proyectos)\n";

// 9. Chofer de Camión Grúa
$id = addPers($pdo,$demoIdCli,'Héctor Ramírez Soto','Operador de Camión Grúa','Operaciones','EMP-009','2021-11-10','55 1234 5009','');
addPCert($pdo,$id,'Operador Camión Grúa Articulado','AB.2024-CGA-015','AVBA Certificaciones','2024-10-20',dateAdd($today,'+4 months'),'');
addPCert($pdo,$id,'Manejo Defensivo Avanzado','ALD-2024-MDA-003','Aldetrans','2024-05-01',dateAdd($today,'+5 months'),'');
addPDoc($pdo,$id,'licencia','Licencia federal tipo E',dateAdd($today,'+18 months'),'Vigente');
addPDoc($pdo,$id,'antidoping','Antidoping semestral',dateAdd($today,'+2 months'),'');
addPDoc($pdo,$id,'medico','Examen médico SCTS',dateAdd($today,'+9 months'),'Apto');
echo "✅ Héctor Ramírez (Chofer Camión Grúa)\n";

// 10. Coordinador HSE — BAJA
$id = addPers($pdo,$demoIdCli,'Patricia Flores Mendoza','Coordinadora HSE','Seguridad','EMP-010','2016-02-14','55 1234 5010','Baja por renuncia — 15/05/2025','Baja');
addPCert($pdo,$id,'Coordinador HSE Nivel Senior','AB.2024-HSE-001','AVBA Certificaciones','2024-01-10',dateAdd($today,'+1 month'),'Empleada de baja');
addPDoc($pdo,$id,'constancia','Carta de recomendación',null,'');
echo "✅ Patricia Flores (HSE — Baja)\n";

// ══════════════════════════════════════════════════════════════════════════
// 4. PROVEEDORES, MATERIALES, CONFIGURACIÓN Y MANTENIMIENTO
// ══════════════════════════════════════════════════════════════════════════
require_once __DIR__ . '/api/helpers.php';
require_once __DIR__ . '/api/ClienteProveedores.php';
require_once __DIR__ . '/api/ClienteMateriales.php';
require_once __DIR__ . '/api/ClienteConfig.php';
require_once __DIR__ . '/api/ClienteMantenimiento.php';

$cliProv = new ClienteProveedores($pdo);
$cliMat  = new ClienteMateriales($pdo);
$cliCfg  = new ClienteConfig($pdo);
$cliMant = new ClienteMantenimiento($pdo);

// Limpiar datos previos del demo en estas tablas para poder re-ejecutar el
// seeder sin duplicar (mismo criterio que el resto del script).
$pdo->prepare("DELETE FROM cliente_proveedores WHERE id_cliente=?")->execute([$demoIdCli]);
$pdo->prepare("DELETE FROM cliente_materiales WHERE id_cliente=?")->execute([$demoIdCli]);
foreach ($pdo->query("SELECT id FROM cliente_sol_material WHERE id_cliente='$demoIdCli'")->fetchAll() as $s) {
    $pdo->prepare("DELETE FROM cliente_sol_material_item WHERE sol_id=?")->execute([$s['id']]);
    $pdo->prepare("DELETE FROM cliente_sol_envio WHERE sol_id=?")->execute([$s['id']]);
}
$pdo->prepare("DELETE FROM cliente_sol_material WHERE id_cliente=?")->execute([$demoIdCli]);
foreach ($pdo->query("SELECT id FROM cliente_mantenimiento WHERE id_cliente='$demoIdCli'")->fetchAll() as $m) {
    $pdo->prepare("DELETE FROM cliente_mantenimiento_foto WHERE mant_id=?")->execute([$m['id']]);
    $pdo->prepare("DELETE FROM cliente_mantenimiento_sol WHERE mant_id=?")->execute([$m['id']]);
}
$pdo->prepare("DELETE FROM cliente_mantenimiento WHERE id_cliente=?")->execute([$demoIdCli]);
echo "🗑  Proveedores/materiales/solicitudes/mantenimiento previos del demo eliminados\n";

// ── Generador de fotos de muestra (GD) ──────────────────────────────────────
// No hay fotografías reales que subir en un seeder, así que se generan
// imágenes sencillas con GD (silueta de equipo + etiqueta ANTES/DESPUÉS) para
// que la galería y los PDF de evidencia no queden vacíos ni rotos.
function generarFotoDemo(string $path, string $equipo, string $etapa, string $detalle): bool {
    if (!function_exists('imagecreatetruecolor')) return false;
    $w = 900; $h = 620;
    $img = imagecreatetruecolor($w, $h);
    $despues = ($etapa === 'despues');
    for ($y = 0; $y < $h; $y++) {
        $t = $y / $h;
        if ($despues) { $r = 28 + (int)(35*$t); $g = 68 + (int)(55*$t); $b = 40 + (int)(28*$t); }
        else          { $r = 72 + (int)(48*$t); $g = 56 + (int)(34*$t); $b = 40 + (int)(20*$t); }
        imageline($img, 0, $y, $w, $y, imagecolorallocate($img, $r, $g, $b));
    }
    $metal  = imagecolorallocate($img, 55, 58, 66);
    $metalD = imagecolorallocate($img, 28, 30, 36);
    imagefilledrectangle($img, 180, 260, 720, 430, $metal);
    imagefilledrectangle($img, 260, 195, 640, 265, $metal);
    imagefilledellipse($img, 260, 430, 110, 110, $metalD);
    imagefilledellipse($img, 640, 430, 110, 110, $metalD);
    imagefilledellipse($img, 260, 430, 46, 46, imagecolorallocate($img, 92, 94, 100));
    imagefilledellipse($img, 640, 430, 46, 46, imagecolorallocate($img, 92, 94, 100));
    $franja = $despues ? imagecolorallocate($img, 32, 130, 60) : imagecolorallocate($img, 176, 120, 20);
    imagefilledrectangle($img, 0, $h-56, $w, $h, $franja);
    $white = imagecolorallocate($img, 255, 255, 255);
    imagestring($img, 5, 24, 20, mb_strtoupper($equipo), $white);
    imagestring($img, 4, 24, 44, $detalle, imagecolorallocate($img, 225, 225, 225));
    imagestring($img, 5, 24, $h-42, 'EVIDENCIA — ' . ($despues ? 'DESPUÉS' : 'ANTES'), $white);
    imagestring($img, 3, $w-280, $h-30, 'AVBA Demo · foto de muestra', $white);
    $ok = imagejpeg($img, $path, 82);
    imagedestroy($img);
    return $ok;
}

/** Arma un arreglo $_FILES-compatible con fotos generadas y fija $_POST['fotos_etapa']
 *  (guardarFotos() lo lee directo de $_POST, no como parámetro). */
function fotosDemoParaMant(array $items): array {
    $tmpDir = sys_get_temp_dir();
    $name = []; $tmp = []; $err = []; $size = [];
    foreach ($items as $it) {
        $path = $tmpDir . '/avba_demo_foto_' . uniqid('', true) . '.jpg';
        generarFotoDemo($path, $it['equipo'], $it['etapa'], $it['detalle']);
        $name[] = 'foto.jpg'; $tmp[] = $path; $err[] = 0; $size[] = @filesize($path) ?: 0;
    }
    $_POST['fotos_etapa'] = array_column($items, 'etapa');
    return ['tmp' => $tmp, 'files' => ['fotos' => ['name'=>$name,'tmp_name'=>$tmp,'error'=>$err,'size'=>$size]]];
}
function limpiarTmp(array $rutas): void { foreach ($rutas as $t) { @unlink($t); } }

function obtenerItemIds(PDO $pdo, int $solId): array {
    $s = $pdo->prepare("SELECT id FROM cliente_sol_material_item WHERE sol_id=? ORDER BY id");
    $s->execute([$solId]);
    return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
}

// ── PROVEEDORES ──────────────────────────────────────────────────────────
echo "\n=== CREANDO PROVEEDORES ===\n";
$provIds = [];
$proveedoresDemo = [
    ['slug'=>'hidraulica',  'nombre'=>'Hidráulica y Mangueras de México S.A. de C.V.', 'giro'=>'Mangueras, sellos y sistemas hidráulicos', 'rfc'=>'HMM950214A21', 'contacto'=>'Ing. Patricia Solano', 'telefono'=>'81 8123 4501', 'correo'=>'ventas@hidraulicamx-demo.com', 'sitio_web'=>'hidraulicamx-demo.com', 'direccion'=>'Av. Industrias del Poniente 1245', 'ciudad'=>'Monterrey', 'estado_ubic'=>'Nuevo León', 'notas'=>'Entrega en 24-48h, buen precio en mangueras a la medida.'],
    ['slug'=>'refaccionaria','nombre'=>'Refaccionaria Industrial del Norte',            'giro'=>'Refacciones y partes industriales',       'rfc'=>'RIN870630B45', 'contacto'=>'Sr. Arturo Beltrán',   'telefono'=>'81 8234 7789', 'correo'=>'compras@rin-demo.mx',       'sitio_web'=>'rin-demo.mx',        'direccion'=>'Blvd. Díaz Ordaz 890',              'ciudad'=>'San Nicolás de los Garza','estado_ubic'=>'Nuevo León', 'notas'=>'Proveedor principal de refacciones generales.'],
    ['slug'=>'llantas',     'nombre'=>'Llantera Industrial Monterrey',                  'giro'=>'Llantas y rines para equipo pesado',      'rfc'=>'LIM101122C88', 'contacto'=>'Sr. Daniel Ochoa',     'telefono'=>'81 8345 1290', 'correo'=>'daniel.ochoa@llantasmty-demo.com', 'sitio_web'=>'llantasmty-demo.com','direccion'=>'Carretera a Laredo Km 12',          'ciudad'=>'Apodaca',            'estado_ubic'=>'Nuevo León', 'notas'=>'Maneja llantas OTR y de camión de línea.'],
    ['slug'=>'lubricantes', 'nombre'=>'Lubricantes y Aceites del Bajío S.A. de C.V.',   'giro'=>'Aceites, grasas y lubricantes industriales','rfc'=>'LAB050912D67','contacto'=>'Lic. Mónica Reyes',   'telefono'=>'477 712 4456', 'correo'=>'monica.reyes@lubribajio-demo.com', 'sitio_web'=>'lubribajio-demo.com','direccion'=>'Parque Industrial El Bajío, Nave 7','ciudad'=>'León',              'estado_ubic'=>'Guanajuato', 'notas'=>'Distribuidor autorizado, entrega foránea 3-5 días.'],
    ['slug'=>'electrico',   'nombre'=>'Eléctrico Industrial Torres',                    'giro'=>'Componentes eléctricos y control',        'rfc'=>'EIT160305E12', 'contacto'=>'Ing. Sergio Torres',   'telefono'=>'81 8456 9012', 'correo'=>'sergio@electricotorres-demo.mx', 'sitio_web'=>'electricotorres-demo.mx','direccion'=>'Calle Morones Prieto 456','ciudad'=>'Monterrey',      'estado_ubic'=>'Nuevo León', 'notas'=>'Baterías, contactores y tableros de control.'],
    ['slug'=>'rodamientos', 'nombre'=>'Rodamientos y Transmisiones S.A. de C.V.',       'giro'=>'Rodamientos, bandas y cadenas',           'rfc'=>'RTS991103F34', 'contacto'=>'Sr. Iván Camacho',     'telefono'=>'81 8567 3345', 'correo'=>'ivan.camacho@rodatrans-demo.com', 'sitio_web'=>'rodatrans-demo.com','direccion'=>'Av. Fundidora 2100',                'ciudad'=>'Monterrey',          'estado_ubic'=>'Nuevo León', 'notas'=>'Stock amplio de rodamientos para grúas y montacargas.'],
];
foreach ($proveedoresDemo as $p) {
    $r = $cliProv->guardar($p, $demoIdCli);
    $provIds[$p['slug']] = $r['id'] ?? 0;
}
echo "✅ " . count($provIds) . " proveedores registrados\n";

// ── MATERIALES (catálogo) ───────────────────────────────────────────────
echo "\n=== CREANDO CATÁLOGO DE MATERIALES ===\n";
$materialesDemo = [
    ['nombre'=>'Manguera hidráulica 1/2" SAE 100R2', 'num_parte'=>'MH-100R2-12',  'unidad'=>'m',   'categoria'=>'Hidráulica'],
    ['nombre'=>'Sello kit cilindro hidráulico principal', 'num_parte'=>'SK-CIL-450', 'unidad'=>'kit', 'categoria'=>'Hidráulica'],
    ['nombre'=>'Aceite hidráulico ISO 68',            'num_parte'=>'AH-ISO68-208', 'unidad'=>'L',   'categoria'=>'Lubricantes'],
    ['nombre'=>'Aceite de motor diésel 15W40',        'num_parte'=>'AM-15W40-208', 'unidad'=>'L',   'categoria'=>'Lubricantes'],
    ['nombre'=>'Grasa multiusos EP-2',                'num_parte'=>'GR-EP2-16',    'unidad'=>'kg',  'categoria'=>'Lubricantes'],
    ['nombre'=>'Filtro de aceite hidráulico',         'num_parte'=>'FLT-HID-220',  'unidad'=>'pza', 'categoria'=>'Filtros'],
    ['nombre'=>'Filtro de aire motor',                'num_parte'=>'FLT-AIR-330',  'unidad'=>'pza', 'categoria'=>'Filtros'],
    ['nombre'=>'Filtro de combustible diésel',        'num_parte'=>'FLT-COMB-118', 'unidad'=>'pza', 'categoria'=>'Filtros'],
    ['nombre'=>'Llanta OTR 12.00-24',                 'num_parte'=>'LL-OTR-1200',  'unidad'=>'pza', 'categoria'=>'Llantas y rodamiento'],
    ['nombre'=>'Rodamiento cónico mástil montacargas', 'num_parte'=>'ROD-MAST-77', 'unidad'=>'pza', 'categoria'=>'Llantas y rodamiento'],
    ['nombre'=>'Rodamiento de carrilería grúa puente', 'num_parte'=>'ROD-CARR-45', 'unidad'=>'pza', 'categoria'=>'Llantas y rodamiento'],
    ['nombre'=>'Cable de acero 6x19 IPS 1/2"',        'num_parte'=>'CAB-6X19-12',  'unidad'=>'m',   'categoria'=>'Izaje'],
    ['nombre'=>'Batería industrial 12V 150Ah',        'num_parte'=>'BAT-12V-150',  'unidad'=>'pza', 'categoria'=>'Eléctrico'],
    ['nombre'=>'Contactor de control 40A',            'num_parte'=>'CTR-40A',      'unidad'=>'pza', 'categoria'=>'Eléctrico'],
    ['nombre'=>'Banda de transmisión industrial',     'num_parte'=>'BND-TR-210',   'unidad'=>'pza', 'categoria'=>'Transmisión'],
    ['nombre'=>'Balatas de freno (juego)',            'num_parte'=>'BAL-FRE-090',  'unidad'=>'juego','categoria'=>'Frenos'],
    ['nombre'=>'Guantes de trabajo reforzados',       'num_parte'=>'EPP-GT-010',   'unidad'=>'par', 'categoria'=>'Consumibles'],
    ['nombre'=>'Trapo industrial absorbente',         'num_parte'=>'CONS-TRAP-01', 'unidad'=>'kg',  'categoria'=>'Consumibles'],
];
foreach ($materialesDemo as $m) { $cliMat->guardar($m, $demoIdCli); }
echo "✅ " . count($materialesDemo) . " materiales en catálogo\n";

// ── CONFIGURACIÓN DEL CLIENTE ────────────────────────────────────────────
$cliCfg->guardar([
    'razon_social' => $demoNombre,
    'rfc'          => 'AMM180523LK9',   // RFC ficticio, solo para el demo
], [], $demoIdCli);
echo "✅ Configuración del cliente (razón social/RFC)\n";

// ── SUB-USUARIO MECÁNICO (demo) ──────────────────────────────────────────
// Para poder demostrar el flujo real de "el mecánico solicita/reporta,
// la cuenta principal procesa" — no solo la vista de la cuenta principal.
require_once __DIR__ . '/api/ClienteSubusuarios.php';
$cliSubU = new ClienteSubusuarios($pdo);
$pdo->prepare("DELETE FROM usuarios WHERE usuario='mecanico.demo'")->execute();
$padreDemo = ['id' => (int)$userId, 'id_cliente' => $demoIdCli];
$rSub = $cliSubU->crear([
    'usuario'  => 'mecanico.demo',
    'password' => 'Mecanico2026',
    'nombre'   => 'Roberto Díaz Morales',
    'permiso'  => 'gestion',
    'permiso_mantenimiento' => 1,
], $padreDemo);
$mecanicoSubId = (int)($rSub['id'] ?? 0);
if ($mecanicoSubId) {
    $cliSubU->asignar(['usuario_id' => $mecanicoSubId, 'tipo' => 'equipo', 'ids' => array_values($eqIds)], $padreDemo);
    echo "✅ Sub-usuario mecánico: mecanico.demo / Mecanico2026 (acceso a todos los equipos demo)\n";
} else {
    echo "⚠️  No se pudo crear el sub-usuario mecánico: " . ($rSub['message'] ?? 'error desconocido') . "\n";
}

// ── SOLICITUDES DE MATERIAL ──────────────────────────────────────────────
echo "\n=== CREANDO SOLICITUDES DE MATERIAL ===\n";
$usrRoberto = ['id' => $mecanicoSubId, 'nombre' => 'Roberto Díaz Morales'];
$usrJose    = ['id' => $mecanicoSubId, 'nombre' => 'José Luis Hernández Cruz'];
$solIds = [];

// 1 — Grúa Torre: mangueras + filtro — se asigna y se envía (queda "enviada")
$r = $cliMant->crearSolicitud([
    'equipo_origen' => 'cliente', 'equipo_ref_id' => $eqIds['grua_torre'],
    'fecha_requerida' => dateAdd($today, '+5 days'),
    'notas' => 'Urge para mantenimiento preventivo programado del viernes.',
    'items' => [
        ['descripcion' => 'Manguera hidráulica 1/2" SAE 100R2', 'cantidad' => 6, 'unidad' => 'm', 'num_parte' => 'MH-100R2-12'],
        ['descripcion' => 'Filtro de aceite hidráulico',        'cantidad' => 2, 'unidad' => 'pza','num_parte' => 'FLT-HID-220'],
    ],
], $demoIdCli, $usrRoberto);
$solIds['grua_torre'] = $r['id'] ?? 0;
echo "✅ Solicitud SM — Grúa Torre (folio {$r['folio']})\n";

// 2 — Montacargas: rodamiento + llanta — queda "parcial" (un ítem sin asignar)
$r = $cliMant->crearSolicitud([
    'equipo_origen' => 'cliente', 'equipo_ref_id' => $eqIds['montacargas'],
    'fecha_requerida' => dateAdd($today, '+10 days'),
    'notas' => 'Ruido detectado en mástil durante inspección diaria.',
    'items' => [
        ['descripcion' => 'Rodamiento cónico mástil montacargas', 'cantidad' => 2, 'unidad' => 'pza', 'num_parte' => 'ROD-MAST-77'],
        ['descripcion' => 'Llanta sólida delantera (par)',        'cantidad' => 1, 'unidad' => 'juego'],
    ],
], $demoIdCli, $usrRoberto);
$solIds['montacargas'] = $r['id'] ?? 0;
echo "✅ Solicitud SM — Montacargas (folio {$r['folio']})\n";

// 3 — Retroexcavadora: aceite + filtros — abierta (sin procesar aún)
$r = $cliMant->crearSolicitud([
    'equipo_origen' => 'cliente', 'equipo_ref_id' => $eqIds['retro'],
    'fecha_requerida' => dateAdd($today, '+7 days'),
    'notas' => 'Para servicio de las 4,500 horas.',
    'items' => [
        ['descripcion' => 'Aceite hidráulico ISO 68',    'cantidad' => 60, 'unidad' => 'L',  'num_parte' => 'AH-ISO68-208'],
        ['descripcion' => 'Filtro de aceite hidráulico', 'cantidad' => 3,  'unidad' => 'pza', 'num_parte' => 'FLT-HID-220'],
        ['descripcion' => 'Filtro de aire motor',        'cantidad' => 2,  'unidad' => 'pza', 'num_parte' => 'FLT-AIR-330'],
    ],
], $demoIdCli, $usrRoberto);
$solIds['retro'] = $r['id'] ?? 0;
echo "✅ Solicitud SM — Retroexcavadora (folio {$r['folio']})\n";

// 4 — Camión Grúa Terex: llantas + balatas — abierta
$r = $cliMant->crearSolicitud([
    'equipo_origen' => 'cliente', 'equipo_ref_id' => $eqIds['camion_grua'],
    'fecha_requerida' => dateAdd($today, '+15 days'),
    'notas' => 'Programar antes de la inspección anual pendiente.',
    'items' => [
        ['descripcion' => 'Llanta OTR 12.00-24',        'cantidad' => 4, 'unidad' => 'pza',  'num_parte' => 'LL-OTR-1200'],
        ['descripcion' => 'Balatas de freno (juego)',   'cantidad' => 1, 'unidad' => 'juego', 'num_parte' => 'BAL-FRE-090'],
    ],
], $demoIdCli, $usrJose);
$solIds['camion_grua'] = $r['id'] ?? 0;
echo "✅ Solicitud SM — Camión Grúa Terex (folio {$r['folio']})\n";

// 5 — PTEM JLG: batería — se asigna y se envía (queda "enviada")
$r = $cliMant->crearSolicitud([
    'equipo_origen' => 'cliente', 'equipo_ref_id' => $eqIds['ptem_jlg'],
    'fecha_requerida' => dateAdd($today, '+3 days'),
    'notas' => 'Batería no retiene carga, equipo detenido en inspección técnica.',
    'items' => [
        ['descripcion' => 'Batería industrial 12V 150Ah', 'cantidad' => 4, 'unidad' => 'pza', 'num_parte' => 'BAT-12V-150'],
        ['descripcion' => 'Contactor de control 40A',      'cantidad' => 1, 'unidad' => 'pza', 'num_parte' => 'CTR-40A'],
    ],
], $demoIdCli, $usrRoberto);
$solIds['ptem_jlg'] = $r['id'] ?? 0;
echo "✅ Solicitud SM — PTEM JLG 600AJ (folio {$r['folio']})\n";

// 6 — Grúa Puente: rodamientos + cable — queda "parcial"
$r = $cliMant->crearSolicitud([
    'equipo_origen' => 'cliente', 'equipo_ref_id' => $eqIds['grua_puente'],
    'fecha_requerida' => dateAdd($today, '+8 days'),
    'notas' => 'Parte de la corrección estructural en curso (equipo fuera de servicio).',
    'items' => [
        ['descripcion' => 'Rodamiento de carrilería grúa puente', 'cantidad' => 4, 'unidad' => 'pza', 'num_parte' => 'ROD-CARR-45'],
        ['descripcion' => 'Cable de acero 6x19 IPS 1/2"',         'cantidad' => 20, 'unidad' => 'm',  'num_parte' => 'CAB-6X19-12'],
    ],
], $demoIdCli, $usrJose);
$solIds['grua_puente'] = $r['id'] ?? 0;
echo "✅ Solicitud SM — Grúa Puente (folio {$r['folio']})\n";

// Asignar proveedores y generar envíos para dejar estados realistas
// (enviada / parcial / abierta, tal como se vería en un cliente activo)
function asignarYEnviar(ClienteMantenimiento $cliMant, PDO $pdo, int $solId, string $idCli, array $asign, bool $generarEnvio) {
    if (!$solId) return;
    $ids = obtenerItemIds($pdo, $solId);
    $asignaciones = [];
    foreach ($asign as $idx => $provId) { if (isset($ids[$idx])) $asignaciones[$ids[$idx]] = $provId; }
    if ($asignaciones) $cliMant->asignarProveedores($solId, $asignaciones, $idCli);
    if ($generarEnvio) $cliMant->generarEnvios($solId, $idCli);
}
asignarYEnviar($cliMant, $pdo, $solIds['grua_torre'],   $demoIdCli, [0 => $provIds['hidraulica'],   1 => $provIds['hidraulica']],   true);  // enviada
asignarYEnviar($cliMant, $pdo, $solIds['montacargas'],  $demoIdCli, [0 => $provIds['rodamientos']],                                  true);  // parcial (ítem 1 sin asignar)
asignarYEnviar($cliMant, $pdo, $solIds['ptem_jlg'],     $demoIdCli, [0 => $provIds['electrico'], 1 => $provIds['electrico']],        true);  // enviada
asignarYEnviar($cliMant, $pdo, $solIds['grua_puente'],  $demoIdCli, [0 => $provIds['rodamientos']],                                  true);  // parcial (ítem 1 sin asignar)
// retro y camion_grua se quedan "abierta" a propósito, para mostrar ese estado también.
echo "✅ Asignaciones y envíos a proveedores generados (con PDF real si mPDF está disponible)\n";

// ── REPORTES DE MANTENIMIENTO (énfasis del demo) ────────────────────────
echo "\n=== CREANDO REPORTES DE MANTENIMIENTO ===\n";

function crearMantDemo(ClienteMantenimiento $cliMant, string $idCli, array $usr, array $data, array $fotos): array {
    $f = fotosDemoParaMant($fotos);
    $r = $cliMant->crearMantenimiento($data, $f['files'], $idCli, $usr);
    limpiarTmp($f['tmp']);
    return $r;
}

// 1 — Grúa Torre: preventivo, ligado a la solicitud de mangueras
$r = crearMantDemo($cliMant, $demoIdCli, $usrRoberto, [
    'equipo_origen' => 'cliente', 'equipo_ref_id' => $eqIds['grua_torre'],
    'fecha' => dateAdd($today, '-2 days'), 'tipo_mant' => 'Preventivo',
    'trabajo' => "Mantenimiento preventivo programado de 2000h. Cambio de mangueras hidráulicas del sistema de pluma (desgaste por abrasión detectado en inspección previa), reemplazo de filtro de aceite hidráulico y purga completa del circuito. Se revisaron conexiones, torque de pernos de anclaje y sistema de frenos de giro.",
    'material_usado' => "6 m de manguera hidráulica 1/2\" SAE 100R2, 2 filtros de aceite hidráulico, 40 L de aceite ISO 68 de repuesto para purga.",
    'observaciones' => "Equipo operando de forma normal después del servicio. Sin fugas detectadas tras prueba de presión. Se recomienda dar seguimiento a la póliza de seguro que vence en los próximos meses.",
    'solicitudes' => [$solIds['grua_torre']],
], [
    ['equipo'=>'Grúa Torre Liebherr', 'etapa'=>'antes',   'detalle'=>'Manguera con desgaste visible'],
    ['equipo'=>'Grúa Torre Liebherr', 'etapa'=>'antes',   'detalle'=>'Filtro de aceite hidráulico saturado'],
    ['equipo'=>'Grúa Torre Liebherr', 'etapa'=>'despues', 'detalle'=>'Mangueras y filtro reemplazados'],
]);
echo "✅ Reporte — Grúa Torre (folio {$r['folio']})\n";

// 2 — Montacargas: correctivo, ligado a solicitud de rodamientos
$r = crearMantDemo($cliMant, $demoIdCli, $usrRoberto, [
    'equipo_origen' => 'cliente', 'equipo_ref_id' => $eqIds['montacargas'],
    'fecha' => dateAdd($today, '-6 days'), 'tipo_mant' => 'Correctivo',
    'trabajo' => "Se atendió reporte de ruido anormal en el mástil durante elevación de carga. Al desarmar se encontró rodamiento cónico superior con desgaste avanzado y falta de lubricación. Se sustituyeron ambos rodamientos del mástil, se relubricó el conjunto completo y se ajustaron cadenas de elevación.",
    'material_usado' => "2 rodamientos cónicos de mástil, grasa multiusos EP-2 (1.5 kg).",
    'observaciones' => "Ruido eliminado por completo. Se realizaron 15 ciclos de prueba con carga nominal sin anomalías. Equipo reincorporado a operación en almacén principal.",
    'solicitudes' => [$solIds['montacargas']],
], [
    ['equipo'=>'Montacargas Toyota', 'etapa'=>'antes',   'detalle'=>'Rodamiento con desgaste avanzado'],
    ['equipo'=>'Montacargas Toyota', 'etapa'=>'antes',   'detalle'=>'Mástil desarmado para inspección'],
    ['equipo'=>'Montacargas Toyota', 'etapa'=>'despues', 'detalle'=>'Rodamientos nuevos instalados'],
    ['equipo'=>'Montacargas Toyota', 'etapa'=>'despues', 'detalle'=>'Prueba de carga sin anomalías'],
]);
echo "✅ Reporte — Montacargas (folio {$r['folio']})\n";

// 3 — Grúa Viajera Demag: correctivo (coincide con "Mantenimiento" en Mis Equipos)
$r = crearMantDemo($cliMant, $demoIdCli, $usrJose, [
    'equipo_origen' => 'cliente', 'equipo_ref_id' => $eqIds['grua_viajera'],
    'fecha' => dateAdd($today, '-1 days'), 'tipo_mant' => 'Correctivo',
    'trabajo' => "Cambio de rodamientos del carro viajero conforme a orden de mantenimiento MP-2025-034. Se retiró polipasto, se sustituyeron rodamientos de las ruedas del carro y se realizó lubricación general de la vía KBK-II.",
    'material_usado' => "Rodamientos de carro viajero, grasa multiusos EP-2.",
    'observaciones' => "Trabajo en proceso de cierre — pendiente prueba final de desplazamiento a lo largo de toda la vía antes de reincorporar el equipo a producción.",
    'solicitudes' => [],
], [
    ['equipo'=>'Grúa Viajera Demag', 'etapa'=>'antes',   'detalle'=>'Carro viajero desmontado'],
    ['equipo'=>'Grúa Viajera Demag', 'etapa'=>'despues', 'detalle'=>'Rodamientos nuevos y vía lubricada'],
]);
echo "✅ Reporte — Grúa Viajera Demag (folio {$r['folio']})\n";

// 4 — PTEM Genie: preventivo de rutina
$r = crearMantDemo($cliMant, $demoIdCli, $usrRoberto, [
    'equipo_origen' => 'cliente', 'equipo_ref_id' => $eqIds['ptem_genie'],
    'fecha' => dateAdd($today, '-10 days'), 'tipo_mant' => 'Preventivo',
    'trabajo' => "Servicio programado de 250 horas. Revisión de niveles de aceite hidráulico, inspección visual de mangueras y cilindros, prueba de funciones de emergencia (descenso manual, paro de emergencia) y verificación de sensores de nivel/inclinación.",
    'material_usado' => "Sin refacciones — solo insumos de taller (trapo, desengrasante).",
    'observaciones' => "Equipo en óptimas condiciones. Checklist pre-operación firmado sin observaciones. Próximo servicio programado en 250h adicionales.",
    'solicitudes' => [],
], [
    ['equipo'=>'PTEM Genie Z-62/40', 'etapa'=>'antes',   'detalle'=>'Inspección de mangueras y cilindros'],
    ['equipo'=>'PTEM Genie Z-62/40', 'etapa'=>'despues', 'detalle'=>'Servicio completado, checklist OK'],
]);
echo "✅ Reporte — PTEM Genie (folio {$r['folio']})\n";

// 5 — Retroexcavadora: preventivo, ligado a la solicitud de aceite/filtros
$r = crearMantDemo($cliMant, $demoIdCli, $usrRoberto, [
    'equipo_origen' => 'cliente', 'equipo_ref_id' => $eqIds['retro'],
    'fecha' => dateAdd($today, '-3 days'), 'tipo_mant' => 'Preventivo',
    'trabajo' => "Servicio de las 4,500 horas: cambio de aceite de motor y sistema hidráulico, sustitución de filtros de aceite, aire y combustible, engrasado general de pines y bujes del brazo/cucharón.",
    'material_usado' => "60 L de aceite hidráulico ISO 68, 3 filtros de aceite hidráulico, 2 filtros de aire, grasa multiusos.",
    'observaciones' => "Servicio completado sin contratiempos. Se detectó ligero desgaste en buje del cucharón — se dará seguimiento en próximo servicio, aún dentro de tolerancia.",
    'solicitudes' => [$solIds['retro']],
], [
    ['equipo'=>'Retroexcavadora CAT 323', 'etapa'=>'antes',   'detalle'=>'Cambio de aceite y filtros'],
    ['equipo'=>'Retroexcavadora CAT 323', 'etapa'=>'despues', 'detalle'=>'Servicio de 4500h completado'],
]);
echo "✅ Reporte — Retroexcavadora CAT 323 (folio {$r['folio']})\n";

// 6 — Camión Kenworth: preventivo mayor
$r = crearMantDemo($cliMant, $demoIdCli, $usrJose, [
    'equipo_origen' => 'cliente', 'equipo_ref_id' => $eqIds['camion_kw'],
    'fecha' => dateAdd($today, '-14 days'), 'tipo_mant' => 'Preventivo',
    'trabajo' => "Servicio mayor de 15,000 km. Cambio de aceite y filtros de motor, revisión y ajuste de frenos, inspección de suspensión neumática y rotación de llantas.",
    'material_usado' => "Aceite de motor diésel 15W40, filtros de aceite/aire/combustible, balatas de freno (juego delantero).",
    'observaciones' => "Vehículo en buen estado general. Se recomienda dar seguimiento a la verificación de emisiones próxima a vencer.",
    'solicitudes' => [],
], [
    ['equipo'=>'Camión Kenworth T680', 'etapa'=>'antes',   'detalle'=>'Revisión de frenos y suspensión'],
    ['equipo'=>'Camión Kenworth T680', 'etapa'=>'despues', 'detalle'=>'Servicio mayor completado'],
]);
echo "✅ Reporte — Camión Kenworth T680 (folio {$r['folio']})\n";

// 7 — Grúa Puente: correctivo mayor (estructura), ligado a solicitud de rodamientos/cable
$r = crearMantDemo($cliMant, $demoIdCli, $usrJose, [
    'equipo_origen' => 'cliente', 'equipo_ref_id' => $eqIds['grua_puente'],
    'fecha' => dateAdd($today, '-4 days'), 'tipo_mant' => 'Correctivo',
    'trabajo' => "Corrección estructural de la carrilería conforme a dictamen de revisión (deformaciones detectadas). Se realizó enderezado de tramo afectado, sustitución de rodamientos de carrilería y cambio de cable de acero principal por desgaste superficial.",
    'material_usado' => "4 rodamientos de carrilería, 20 m de cable de acero 6x19 IPS 1/2\".",
    'observaciones' => "Trabajo en ejecución conforme a cotización aprobada por gerencia. Equipo permanece fuera de servicio hasta concluir la verificación estructural final y obtener nuevo certificado de inspección.",
    'solicitudes' => [$solIds['grua_puente']],
], [
    ['equipo'=>'Grúa Puente Abus 10t', 'etapa'=>'antes',   'detalle'=>'Deformación detectada en carrilería'],
    ['equipo'=>'Grúa Puente Abus 10t', 'etapa'=>'antes',   'detalle'=>'Cable de acero con desgaste superficial'],
    ['equipo'=>'Grúa Puente Abus 10t', 'etapa'=>'despues', 'detalle'=>'Carrilería enderezada, rodamientos nuevos'],
    ['equipo'=>'Grúa Puente Abus 10t', 'etapa'=>'despues', 'detalle'=>'Cable de acero reemplazado'],
]);
echo "✅ Reporte — Grúa Puente (folio {$r['folio']})\n";

// 8 — PTEM JLG: correctivo eléctrico, ligado a solicitud de batería
$r = crearMantDemo($cliMant, $demoIdCli, $usrRoberto, [
    'equipo_origen' => 'cliente', 'equipo_ref_id' => $eqIds['ptem_jlg'],
    'fecha' => dateAdd($today, '-1 days'), 'tipo_mant' => 'Correctivo',
    'trabajo' => "Sustitución del banco de baterías (4 unidades de 12V) por pérdida de capacidad de carga detectada en inspección técnica semestral. Se revisó y calibró el sistema de carga a bordo y se verificó continuidad del arnés eléctrico principal.",
    'material_usado' => "4 baterías industriales 12V 150Ah, 1 contactor de control 40A.",
    'observaciones' => "Equipo recupera autonomía completa de operación. Se libera para continuar con la inspección técnica semestral pendiente.",
    'solicitudes' => [$solIds['ptem_jlg']],
], [
    ['equipo'=>'PTEM JLG 600AJ', 'etapa'=>'antes',   'detalle'=>'Banco de baterías con baja capacidad'],
    ['equipo'=>'PTEM JLG 600AJ', 'etapa'=>'despues', 'detalle'=>'Baterías nuevas instaladas y calibradas'],
]);
echo "✅ Reporte — PTEM JLG 600AJ (folio {$r['folio']})\n";

// 9 — Camión Grúa Terex: predictivo
$r = crearMantDemo($cliMant, $demoIdCli, $usrJose, [
    'equipo_origen' => 'cliente', 'equipo_ref_id' => $eqIds['camion_grua'],
    'fecha' => dateAdd($today, '-8 days'), 'tipo_mant' => 'Predictivo',
    'trabajo' => "Estudio predictivo previo a la inspección anual: termografía del tablero eléctrico y motores, análisis de vibración en tambores de izaje y muestreo de aceite hidráulico para análisis en laboratorio.",
    'material_usado' => "Sin refacciones — solo insumos de muestreo (frascos sellados, etiquetas).",
    'observaciones' => "Resultados dentro de parámetros normales, sin puntos calientes ni vibración anómala. Se adjuntará el informe de laboratorio del análisis de aceite en cuanto esté disponible.",
    'solicitudes' => [],
], [
    ['equipo'=>'Camión Grúa Terex AC 100', 'etapa'=>'antes',   'detalle'=>'Termografía de tablero eléctrico'],
    ['equipo'=>'Camión Grúa Terex AC 100', 'etapa'=>'despues', 'detalle'=>'Muestreo de aceite hidráulico'],
]);
echo "✅ Reporte — Camión Grúa Terex (folio {$r['folio']})\n";

// ────────────────────────────────────────────────────────────────────────────
//  FUNCIONES NUEVAS — plan de mantenimiento, doble motor, RH y vencimientos
// ────────────────────────────────────────────────────────────────────────────
// Los módulos crean sus tablas solos la primera vez que se abren, pero el demo
// puede correr antes de que alguien entre a esas pantallas: se aseguran aquí.
echo "\n=== FUNCIONES NUEVAS ===\n";

$pdo->exec("
    CREATE TABLE IF NOT EXISTS cliente_equipos_plan_mant (
      equipo_id       INT PRIMARY KEY,
      intervalo_horas INT NULL,
      intervalo_dias  INT NULL,
      base_horas      DECIMAL(10,1) NULL,
      base_fecha      DATE NULL,
      activo          TINYINT NOT NULL DEFAULT 1,
      updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$pdo->exec("
    CREATE TABLE IF NOT EXISTS cliente_rh_politicas (
      id_cliente          VARCHAR(20) PRIMARY KEY,
      faltas_max          INT NOT NULL DEFAULT 1,
      faltas_ventana      VARCHAR(20) NOT NULL DEFAULT 'semana',
      faltas_ventana_dias INT NOT NULL DEFAULT 7,
      actas_max           INT NOT NULL DEFAULT 3,
      retardos_por_falta  INT NOT NULL DEFAULT 3,
      tolerancia_min      INT NOT NULL DEFAULT 10,
      updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$pdo->exec("
    CREATE TABLE IF NOT EXISTS cliente_rh_incidencias (
      id          INT AUTO_INCREMENT PRIMARY KEY,
      id_cliente  VARCHAR(20) NOT NULL,
      empleado_id INT NOT NULL,
      tipo        VARCHAR(20) NOT NULL,
      justificada TINYINT NOT NULL DEFAULT 0,
      fecha       DATE NOT NULL,
      notas       TEXT NULL,
      archivo_url VARCHAR(300) NULL,
      created_by  VARCHAR(120) NULL,
      created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      KEY idx_cli (id_cliente), KEY idx_emp (empleado_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$pdo->exec("
    CREATE TABLE IF NOT EXISTS servicios_pagos (
      id                INT AUTO_INCREMENT PRIMARY KEY,
      concepto          VARCHAR(150) NOT NULL,
      categoria         VARCHAR(40)  NOT NULL DEFAULT 'otro',
      proveedor         VARCHAR(120) NULL,
      referencia        VARCHAR(120) NULL,
      monto             DECIMAL(10,2) NULL,
      periodicidad      VARCHAR(20)  NOT NULL DEFAULT 'mensual',
      fecha_vencimiento DATE NOT NULL,
      recordatorio_dias INT NOT NULL DEFAULT 5,
      ultimo_pago       DATE NULL,
      estatus           VARCHAR(20)  NOT NULL DEFAULT 'pendiente',
      notas             TEXT NULL,
      activo            TINYINT NOT NULL DEFAULT 1,
      created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$pdo->exec("
    CREATE TABLE IF NOT EXISTS anuncios_validacion (
      id          INT AUTO_INCREMENT PRIMARY KEY,
      titulo      VARCHAR(150) NULL,
      imagen_url  VARCHAR(300) NOT NULL,
      enlace      VARCHAR(500) NULL,
      activo      TINYINT NOT NULL DEFAULT 1,
      orden       INT NOT NULL DEFAULT 0,
      created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
try { $pdo->exec("ALTER TABLE cliente_equipos ADD COLUMN IF NOT EXISTS dos_motores TINYINT NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}
try { $pdo->exec("ALTER TABLE cliente_equipos_horometro ADD COLUMN IF NOT EXISTS motor TINYINT NOT NULL DEFAULT 1"); } catch (\Throwable $e) {}

// Limpiar lo que sembró una corrida anterior (equipos y personal se recrean,
// así que sus planes/incidencias quedarían huérfanos)
$pdo->exec("DELETE FROM cliente_equipos_plan_mant WHERE equipo_id NOT IN (SELECT id FROM cliente_equipos)");
$pdo->prepare("DELETE FROM cliente_rh_incidencias WHERE id_cliente=?")->execute([$demoIdCli]);
$pdo->exec("DELETE FROM servicios_pagos WHERE notas LIKE '%[demo]%'");
$pdo->exec("DELETE FROM anuncios_validacion WHERE titulo LIKE '%(demo)%'");

// ── A. Grúa con DOS MOTORES (chasis + superestructura) ──────────────────────
// El camión grúa lleva motor de traslado y motor de la grúa: cada uno con su
// propio horómetro. Las lecturas ya sembradas quedan como Motor 1.
$pdo->prepare("UPDATE cliente_equipos SET dos_motores=1 WHERE id=?")->execute([$eqIds['camion_grua']]);
$addHoraMotor = function (int $eqId, float $horas, string $fecha, int $motor, string $notas = '') use ($pdo) {
    $pdo->prepare("INSERT INTO cliente_equipos_horometro (equipo_id,horas,fecha,notas,motor) VALUES(?,?,?,?,?)")
        ->execute([$eqId, $horas, $fecha, $notas, $motor]);
};
$addHoraMotor($eqIds['camion_grua'], 2180, dateAdd($today,'-95 days'), 2, 'Motor de superestructura — lectura trimestral');
$addHoraMotor($eqIds['camion_grua'], 2265, dateAdd($today,'-62 days'), 2, 'Motor de superestructura');
$addHoraMotor($eqIds['camion_grua'], 2341, dateAdd($today,'-30 days'), 2, 'Motor de superestructura');
$addHoraMotor($eqIds['camion_grua'], 2402, dateAdd($today,'-6 days'),  2, 'Motor de superestructura — previo a inspección anual');
echo "✅ Camión Grúa Terex marcado con dos motores (+4 lecturas de Motor 2)\n";

// ── B. PLANES DE MANTENIMIENTO PREVENTIVO (horómetro + fecha) ───────────────
// Se calcula la base a partir de las horas ya registradas para que el semáforo
// del portal quede con casos reales: vencidos, por vencer y al día.
$planMant = function (int $eqId, int $intHoras, int $intDias, string $estado) use ($pdo, $today) {
    $st = $pdo->prepare("SELECT COALESCE(MAX(horas),0) FROM cliente_equipos_horometro WHERE equipo_id=?");
    $st->execute([$eqId]);
    $horasAct = (float)$st->fetchColumn();
    switch ($estado) {
        case 'vencido':                                   // ya se pasó
            $baseHoras = max(0, $horasAct - $intHoras - 45);
            $baseFecha = dateAdd($today, '-' . ($intDias + 6) . ' days');
            break;
        case 'por_vencer':                                // quedan pocas horas/días
            $baseHoras = max(0, $horasAct - $intHoras + 8);
            $baseFecha = dateAdd($today, '-' . max(1, $intDias - 5) . ' days');
            break;
        default:                                          // al día
            $baseHoras = max(0, $horasAct - (int)round($intHoras * 0.25));
            $baseFecha = dateAdd($today, '-' . (int)round($intDias * 0.25) . ' days');
    }
    $pdo->prepare("
        INSERT INTO cliente_equipos_plan_mant (equipo_id,intervalo_horas,intervalo_dias,base_horas,base_fecha,activo)
        VALUES (?,?,?,?,?,1)
        ON DUPLICATE KEY UPDATE intervalo_horas=VALUES(intervalo_horas), intervalo_dias=VALUES(intervalo_dias),
          base_horas=VALUES(base_horas), base_fecha=VALUES(base_fecha), activo=1
    ")->execute([$eqId, $intHoras, $intDias, $baseHoras, $baseFecha]);
};
$planMant($eqIds['grua_torre'],   500, 180, 'al_dia');
$planMant($eqIds['grua_movil'],   250,  90, 'por_vencer');
$planMant($eqIds['montacargas'],  200,  60, 'vencido');
$planMant($eqIds['camion_grua'],  250,  90, 'por_vencer');
$planMant($eqIds['retro'],        300, 120, 'al_dia');
$planMant($eqIds['ptem_jlg'],     150,  90, 'vencido');
$planMant($eqIds['grua_puente'],  400, 180, 'al_dia');
$planMant($eqIds['ptem_genie'],   150,  90, 'por_vencer');
echo "✅ Planes de mantenimiento preventivo en 8 equipos (2 vencidos, 3 por vencer, 3 al día)\n";

// ── C. RH / ASISTENCIA — políticas e incidencias ────────────────────────────
// Ventana móvil de 15 días para mostrar el parámetro configurable de RH.
$pdo->prepare("
    INSERT INTO cliente_rh_politicas
      (id_cliente, faltas_max, faltas_ventana, faltas_ventana_dias, actas_max, retardos_por_falta, tolerancia_min)
    VALUES (?,?,?,?,?,?,?)
    ON DUPLICATE KEY UPDATE faltas_max=VALUES(faltas_max), faltas_ventana=VALUES(faltas_ventana),
      faltas_ventana_dias=VALUES(faltas_ventana_dias), actas_max=VALUES(actas_max),
      retardos_por_falta=VALUES(retardos_por_falta), tolerancia_min=VALUES(tolerancia_min)
")->execute([$demoIdCli, 1, 'movil', 15, 3, 3, 10]);

$perId = function (string $numEmp) use ($pdo, $demoIdCli): int {
    $s = $pdo->prepare("SELECT id FROM cliente_personal WHERE id_cliente=? AND numero_empleado=?");
    $s->execute([$demoIdCli, $numEmp]);
    return (int)($s->fetchColumn() ?: 0);
};
$addInc = function (int $empId, string $tipo, string $fecha, int $justif, string $notas) use ($pdo, $demoIdCli) {
    if (!$empId) return;
    $pdo->prepare("
        INSERT INTO cliente_rh_incidencias (id_cliente, empleado_id, tipo, justificada, fecha, notas, created_by)
        VALUES (?,?,?,?,?,?,?)
    ")->execute([$demoIdCli, $empId, $tipo, $justif, $fecha, $notas, 'RH Demo']);
};

// Ernesto Vega (montacargas) — 2 faltas injustificadas + 1 acta → EN RIESGO
$e = $perId('EMP-007');
$addInc($e, 'falta',   dateAdd($today,'-9 days'),  0, 'No se presentó ni avisó al supervisor.');
$addInc($e, 'falta',   dateAdd($today,'-3 days'),  0, 'Inasistencia sin justificar.');
$addInc($e, 'acta',    dateAdd($today,'-4 days'),  0, 'Acta administrativa por inasistencias reiteradas.');
$addInc($e, 'retardo', dateAdd($today,'-11 days'), 0, 'Ingreso 25 min después de la hora de entrada.');

// José Hernández (rigger) — 2 actas acumuladas → EN OBSERVACIÓN
$e = $perId('EMP-003');
$addInc($e, 'acta',    dateAdd($today,'-40 days'), 0, 'Acta por incumplimiento del procedimiento de amarre.');
$addInc($e, 'acta',    dateAdd($today,'-12 days'), 0, 'Acta por uso incompleto de EPP en maniobra.');
$addInc($e, 'retardo', dateAdd($today,'-6 days'),  0, 'Retardo de 15 min.');

// Miguel Torres (grúa móvil) — 3 retardos = 1 falta efectiva → EN OBSERVACIÓN
$e = $perId('EMP-002');
$addInc($e, 'retardo', dateAdd($today,'-13 days'), 0, 'Retardo de 12 min.');
$addInc($e, 'retardo', dateAdd($today,'-8 days'),  0, 'Retardo de 20 min por tráfico.');
$addInc($e, 'retardo', dateAdd($today,'-2 days'),  0, 'Retardo de 18 min.');

// Roberto Díaz (mecánico) — incapacidad vigente → EN OBSERVACIÓN
$e = $perId('EMP-005');
$addInc($e, 'incapacidad', dateAdd($today,'-5 days'), 1, 'Incapacidad IMSS por 3 días — lumbalgia.');

// Carlos Ramírez — falta justificada y vacaciones (no penalizan como injustificadas)
$e = $perId('EMP-001');
$addInc($e, 'falta',      dateAdd($today,'-7 days'),  1, 'Falta justificada con comprobante médico.');
$addInc($e, 'vacaciones', dateAdd($today,'-45 days'), 1, 'Periodo vacacional autorizado (5 días).');

// Fernanda Gómez — permiso autorizado
$addInc($perId('EMP-004'), 'permiso', dateAdd($today,'-20 days'), 1, 'Permiso de un día por trámite personal.');
$totalInc = $pdo->query("SELECT COUNT(*) FROM cliente_rh_incidencias WHERE id_cliente='$demoIdCli'")->fetchColumn();
echo "✅ RH: políticas (ventana móvil 15 días) + $totalInc incidencias — semáforo con casos en riesgo, observación y sanos\n";

// ── D. VENCIMIENTOS Y PAGOS DE SERVICIOS (panel de administración) ──────────
$addServicio = function (string $concepto, string $cat, string $prov, string $ref, $monto, string $per, string $venc, int $rec, string $notas) use ($pdo) {
    $pdo->prepare("
        INSERT INTO servicios_pagos (concepto,categoria,proveedor,referencia,monto,periodicidad,fecha_vencimiento,recordatorio_dias,notas,activo)
        VALUES (?,?,?,?,?,?,?,?,?,1)
    ")->execute([$concepto, $cat, $prov, $ref, $monto, $per, $venc, $rec, $notas . ' [demo]']);
};
$addServicio('Teléfono — Dirección',        'telefono', 'Telcel',      '5512340001', 649.00,  'mensual',    dateAdd($today,'-4 days'),  5, 'Plan empresarial con datos ilimitados.');
$addServicio('Teléfono — Inspectores (4)',  'telefono', 'AT&T',        '5512340002', 1996.00, 'mensual',    dateAdd($today,'+3 days'),  5, 'Cuatro líneas para personal de campo.');
$addServicio('Internet — Oficina central',  'internet', 'Totalplay',   'TP-884512',  1299.00, 'mensual',    dateAdd($today,'+2 days'),  5, 'Enlace dedicado 200 Mbps simétrico.');
$addServicio('Luz — Oficina y taller',      'luz',      'CFE',         '9204581173', 4820.50, 'bimestral',  dateAdd($today,'+21 days'), 7, 'Medidor del taller de mantenimiento.');
$addServicio('Agua — Taller',               'agua',     'SACMEX',      'AG-55210',   680.00,  'bimestral',  dateAdd($today,'+34 days'), 7, '');
$addServicio('Renta — Oficina central',     'renta',    'Inmobiliaria Solano', 'CONT-2024-11', 28500.00, 'mensual', dateAdd($today,'+6 days'), 8, 'Contrato vigente hasta noviembre.');
$addServicio('Hosting y dominio',           'software', 'Hostinger',   'AVBA-HOST',  3480.00, 'anual',      dateAdd($today,'+95 days'), 15, 'gestion.avba.com.mx y avba.com.mx.');
$addServicio('Seguro de responsabilidad civil','seguro', 'GNP Seguros', 'POL-778120', 42300.00,'anual',     dateAdd($today,'+140 days'),20, 'Cobertura para maniobras de izaje.');
$addServicio('Software de facturación',     'software', 'CONTPAQi',    'LIC-2026-08',6900.00, 'anual',      dateAdd($today,'-12 days'), 10, 'Licencia por renovar.');
echo "✅ Vencimientos: 9 servicios (2 vencidos, 3 por vencer, resto al día)\n";

// ── E. PUBLICIDAD EN LA PÁGINA DE VALIDACIÓN ───────────────────────────────
// Banner generado con GD (no hay artes reales que subir en un seeder).
$dirAnun = __DIR__ . '/uploads/anuncios/';
if (!is_dir($dirAnun)) @mkdir($dirAnun, 0755, true);
$bannerOk = false;
if (function_exists('imagecreatetruecolor')) {
    $w = 1200; $h = 800;
    $img = imagecreatetruecolor($w, $h);
    for ($y = 0; $y < $h; $y++) {                       // degradado azul AVBA
        $t = $y / $h;
        imageline($img, 0, $y, $w, $y, imagecolorallocate($img,
            11 + (int)(7*$t), 26 + (int)(32*$t), 51 + (int)(56*$t)));
    }
    $blanco = imagecolorallocate($img, 255, 255, 255);
    $acento = imagecolorallocate($img, 77, 163, 255);
    imagefilledrectangle($img, 0, 0, 14, $h, $acento);
    imagestring($img, 5, 60, 250, 'AVBA INSPECTIONS', $acento);
    imagestring($img, 5, 60, 300, 'PRUEBAS NO DESTRUCTIVAS', $blanco);
    imagestring($img, 5, 60, 330, 'INSPECCION Y CERTIFICACION DE EQUIPOS', $blanco);
    imagestring($img, 4, 60, 400, 'Mayor confiabilidad para tus equipos', imagecolorallocate($img,200,215,235));
    imagestring($img, 4, 60, 470, 'Cobertura en todo Mexico  ·  avba.com.mx', $acento);
    imagestring($img, 3, 60, $h-60, 'Banner de muestra (demo)', imagecolorallocate($img,120,140,170));
    $bannerOk = imagepng($img, $dirAnun . 'anuncio_demo.png');
    imagedestroy($img);
}
if ($bannerOk) {
    $pdo->prepare("INSERT INTO anuncios_validacion (titulo, imagen_url, enlace, activo, orden) VALUES (?,?,?,1,1)")
        ->execute(['Pruebas No Destructivas (demo)', 'uploads/anuncios/anuncio_demo.png', 'https://avba.com.mx']);
    echo "✅ Publicidad: banner de muestra activo en la página de validación\n";
} else {
    echo "ℹ  Publicidad: no se generó el banner (GD no disponible)\n";
}

echo "\n=== RESUMEN FINAL ===\n";
echo "Usuario: $demoUsuario\n";
echo "Contraseña: Demo2026\n";
echo "id_cliente: $demoIdCli\n";
$totalEq = $pdo->query("SELECT COUNT(*) FROM cliente_equipos WHERE id_cliente='$demoIdCli'")->fetchColumn();
$totalDocs = $pdo->query("SELECT COUNT(*) FROM cliente_equipos_docs d JOIN cliente_equipos e ON e.id=d.equipo_id WHERE e.id_cliente='$demoIdCli'")->fetchColumn();
$totalCerts = $pdo->query("SELECT COUNT(*) FROM cliente_equipos_cert c JOIN cliente_equipos e ON e.id=c.equipo_id WHERE e.id_cliente='$demoIdCli'")->fetchColumn();
$totalHoras = $pdo->query("SELECT COUNT(*) FROM cliente_equipos_horometro h JOIN cliente_equipos e ON e.id=h.equipo_id WHERE e.id_cliente='$demoIdCli'")->fetchColumn();
$totalPer  = $pdo->query("SELECT COUNT(*) FROM cliente_personal WHERE id_cliente='$demoIdCli'")->fetchColumn();
$totalPC   = $pdo->query("SELECT COUNT(*) FROM cliente_personal_cert pc JOIN cliente_personal p ON p.id=pc.personal_id WHERE p.id_cliente='$demoIdCli'")->fetchColumn();
$totalProv = $pdo->query("SELECT COUNT(*) FROM cliente_proveedores WHERE id_cliente='$demoIdCli'")->fetchColumn();
$totalMat  = $pdo->query("SELECT COUNT(*) FROM cliente_materiales WHERE id_cliente='$demoIdCli'")->fetchColumn();
$totalSol  = $pdo->query("SELECT COUNT(*) FROM cliente_sol_material WHERE id_cliente='$demoIdCli'")->fetchColumn();
$totalEnv  = $pdo->query("SELECT COUNT(*) FROM cliente_sol_envio WHERE id_cliente='$demoIdCli'")->fetchColumn();
$totalMantenim = $pdo->query("SELECT COUNT(*) FROM cliente_mantenimiento WHERE id_cliente='$demoIdCli'")->fetchColumn();
$totalFotosMant = $pdo->query("SELECT COUNT(*) FROM cliente_mantenimiento_foto f JOIN cliente_mantenimiento m ON m.id=f.mant_id WHERE m.id_cliente='$demoIdCli'")->fetchColumn();
echo "Equipos: $totalEq\n";
echo "Documentos (equipos): $totalDocs\n";
echo "Certificaciones (equipos): $totalCerts\n";
echo "Registros horómetro: $totalHoras\n";
echo "Personal: $totalPer\n";
echo "Certificaciones (personal): $totalPC\n";
echo "Proveedores: $totalProv\n";
echo "Materiales en catálogo: $totalMat\n";
echo "Solicitudes de material: $totalSol\n";
echo "Envíos a proveedores generados: $totalEnv\n";
echo "Reportes de mantenimiento: $totalMantenim\n";
echo "Fotos de evidencia (mantenimiento): $totalFotosMant\n";
$totalPlan = $pdo->query("SELECT COUNT(*) FROM cliente_equipos_plan_mant p JOIN cliente_equipos e ON e.id=p.equipo_id WHERE e.id_cliente='$demoIdCli'")->fetchColumn();
$totalM2   = $pdo->query("SELECT COUNT(*) FROM cliente_equipos_horometro h JOIN cliente_equipos e ON e.id=h.equipo_id WHERE e.id_cliente='$demoIdCli' AND h.motor=2")->fetchColumn();
$totalRHi  = $pdo->query("SELECT COUNT(*) FROM cliente_rh_incidencias WHERE id_cliente='$demoIdCli'")->fetchColumn();
$totalServ = $pdo->query("SELECT COUNT(*) FROM servicios_pagos WHERE notas LIKE '%[demo]%'")->fetchColumn();
$totalAnun = $pdo->query("SELECT COUNT(*) FROM anuncios_validacion WHERE titulo LIKE '%(demo)%'")->fetchColumn();
echo "Planes de mantenimiento preventivo: $totalPlan\n";
echo "Lecturas de horómetro Motor 2 (grúa de 2 motores): $totalM2\n";
echo "Incidencias de RH: $totalRHi\n";
echo "Servicios en Vencimientos (admin): $totalServ\n";
echo "Anuncios en validación: $totalAnun\n";
echo "Sub-usuario mecánico: " . ($mecanicoSubId ? "mecanico.demo / Mecanico2026 (id=$mecanicoSubId)" : "❌ no se creó, revisa el mensaje de arriba") . "\n";
echo "\n✅ Seeder completado exitosamente.\n";
echo "⚠️  ELIMINA setup_demo.php del servidor después de ejecutarlo.\n";
