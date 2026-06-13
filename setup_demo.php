<?php
/**
 * AVBA Certificaciones — Demo Data Seeder
 * Crea usuario demo "CONSTRUCTORA DEMO S.A. DE C.V." con parque completo de equipos.
 *
 * USO: php setup_demo.php   (o abrir desde navegador con ?secret=avba_setup_2025)
 * ELIMINAR este archivo del servidor después de ejecutar.
 */

if (PHP_SAPI !== 'cli') {
    if (($_GET['secret'] ?? '') !== 'avba_setup_2025') {
        http_response_code(403);
        die('Acceso denegado. Agrega ?secret=avba_setup_2025 a la URL, o ejecuta desde CLI: php setup_demo.php');
    }
    header('Content-Type: text/plain; charset=utf-8');
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
$demoUsuario = 'demo@constructorademo.mx';
$demoPass    = password_hash('Demo2025!', PASSWORD_DEFAULT);
$demoNombre  = 'CONSTRUCTORA DEMO S.A. DE C.V.';
$demoIdCli   = '00001';

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
    echo "✅ Usuario creado: $demoUsuario / Demo2025! (id=$userId)\n";
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
function addPCert(PDO $pdo, int $pid, string $tipo, string $folio, string $entidad, string $emision, string $vigencia, string $notas = ''): void {
    $pdo->prepare("INSERT INTO cliente_personal_cert (personal_id,tipo_cert,folio,entidad,fecha_emision,fecha_vigencia,notas) VALUES (?,?,?,?,?,?,?)")
        ->execute([$pid, $tipo, $folio, $entidad, $emision ?: null, $vigencia ?: null, $notas]);
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

echo "\n=== RESUMEN FINAL ===\n";
echo "Usuario: $demoUsuario\n";
echo "Contraseña: Demo2025!\n";
echo "id_cliente: $demoIdCli\n";
$totalEq = $pdo->query("SELECT COUNT(*) FROM cliente_equipos WHERE id_cliente='$demoIdCli'")->fetchColumn();
$totalDocs = $pdo->query("SELECT COUNT(*) FROM cliente_equipos_docs d JOIN cliente_equipos e ON e.id=d.equipo_id WHERE e.id_cliente='$demoIdCli'")->fetchColumn();
$totalCerts = $pdo->query("SELECT COUNT(*) FROM cliente_equipos_cert c JOIN cliente_equipos e ON e.id=c.equipo_id WHERE e.id_cliente='$demoIdCli'")->fetchColumn();
$totalHoras = $pdo->query("SELECT COUNT(*) FROM cliente_equipos_horometro h JOIN cliente_equipos e ON e.id=h.equipo_id WHERE e.id_cliente='$demoIdCli'")->fetchColumn();
$totalPer  = $pdo->query("SELECT COUNT(*) FROM cliente_personal WHERE id_cliente='$demoIdCli'")->fetchColumn();
$totalPC   = $pdo->query("SELECT COUNT(*) FROM cliente_personal_cert pc JOIN cliente_personal p ON p.id=pc.personal_id WHERE p.id_cliente='$demoIdCli'")->fetchColumn();
echo "Equipos: $totalEq\n";
echo "Documentos (equipos): $totalDocs\n";
echo "Certificaciones (equipos): $totalCerts\n";
echo "Registros horómetro: $totalHoras\n";
echo "Personal: $totalPer\n";
echo "Certificaciones (personal): $totalPC\n";
echo "\n✅ Seeder completado exitosamente.\n";
echo "⚠️  ELIMINA setup_demo.php del servidor después de ejecutarlo.\n";
