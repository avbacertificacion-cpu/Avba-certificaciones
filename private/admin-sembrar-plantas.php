<?php
/**
 * Siembra de datos reales de demo (solo ADMIN, un solo uso):
 *  - Crea los 14 centros de trabajo como empresas.
 *  - Precarga extintores realistas por planta (según su "sabor": corporativo,
 *    industrial o parque eólico), con historial de inspecciones de los
 *    últimos 6 meses y fechas de recarga/prueba hidrostática repartidas para
 *    que las alertas y la gráfica de mantenimiento tengan datos reales.
 *  - Crea un usuario gerente asignado a las 14 plantas.
 *
 * Es idempotente: se puede volver a abrir y confirmar sin duplicar nada — una
 * empresa que ya existe se reutiliza, y una que ya tiene extintores se salta.
 */
require_once '../config/config.php';
require_once '../config/roles-extra.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== ROLE_ADMIN) {
    header('Location: ../public/login.html'); exit;
}
$nombreAdmin = $_SESSION['nombre'];
$uid = $_SESSION['usuario_id'];

const GERENTE_USERNAME = 'gerente.corporativo';
const GERENTE_PASSWORD = 'Gerente2026!';

function centros(): array {
    return [
        ['nombre' => 'CCC Altamira III y VI',        'domicilio' => 'Boulevard de los Ríos Km 10.3, Puerto Industrial de Altamira, Col. Lomas del Real, C.P. 89600, Altamira, Tamaulipas', 'sabor' => 'industrial', 'grande' => true],
        ['nombre' => 'CCC Altamira V',                'domicilio' => 'Boulevard de los Ríos Km 11.17, Col. Lomas del Real, Altamira, Tamaulipas, CP 89600', 'sabor' => 'industrial', 'grande' => false],
        ['nombre' => 'CCC Escobedo',                  'domicilio' => 'Carretera Mty-Monclova Km 11.5, El Carmen, Nuevo León, CP 66560', 'sabor' => 'industrial', 'grande' => false],
        ['nombre' => 'CCC Tamazunchale I',            'domicilio' => 'Predio El Clérigo entre Tepetate y Cuixcuatitla, C.P. 79960, Tamazunchale, S.L.P.', 'sabor' => 'industrial', 'grande' => false],
        ['nombre' => 'CCC El Clérigo',                'domicilio' => 'Predio El Clérigo entre Tepetate y Cuixcuatitla, C.P. 79960, Tamazunchale, S.L.P.', 'sabor' => 'industrial', 'grande' => false],
        ['nombre' => 'CCC La Laguna',                 'domicilio' => 'Circuito Industrial Durango 4300, Ex Ejido Cuba, CP 35140, Gómez Palacio, Durango', 'sabor' => 'industrial', 'grande' => false],
        ['nombre' => 'CCC Dulces Nombres',            'domicilio' => 'Carretera a Dulces Nombres Km 12.5, Pesquería, Nuevo León, CP 66650', 'sabor' => 'industrial', 'grande' => false],
        ['nombre' => 'CCC Baja California III',       'domicilio' => 'Carretera Escénica Tijuana-Ensenada Km 81.2, Predio La Jovita, Col. El Sauzal, Ensenada, B.C., CP 22760', 'sabor' => 'industrial', 'grande' => false],
        ['nombre' => 'CCC Enertek',                   'domicilio' => 'Carretera Tampico-Mante Km 17.5, C.P. 89600, Altamira, Tamaulipas', 'sabor' => 'industrial', 'grande' => false],
        ['nombre' => 'Parque Eólico La Venta III',    'domicilio' => 'Carretera La Ventosa-Arriaga, tramo La Ventosa, Tapanatepec Km 98+770, CP 70120, Municipio de Santo Domingo Ingenio, Oaxaca', 'sabor' => 'eolico', 'grande' => false],
        ['nombre' => 'Corporativo Cd. de México (1)', 'domicilio' => 'Cofre de Perote 130, piso 3, Lomas de Chapultepec, Miguel Hidalgo, CP 11000, CDMX', 'sabor' => 'corporativo', 'grande' => false],
        ['nombre' => 'Corporativo Cd. de México (2)', 'domicilio' => 'Sierra Gorda 42, piso 6, Col. Lomas de Chapultepec, Miguel Hidalgo, CP 11000, CDMX', 'sabor' => 'corporativo', 'grande' => false],
        ['nombre' => 'CC Topolobampo II',             'domicilio' => 'Ejido Choacahui, aprox. 5 km al noreste de San Miguel Zapotitlán, Km 19+350 de la carretera federal No. 15 Navojoa-Los Mochis, Ahome, Sinaloa, CP 81304', 'sabor' => 'industrial', 'grande' => false],
        ['nombre' => 'CC Topolobampo III',            'domicilio' => 'Ejido Choacahui, aprox. 5 km al noreste de San Miguel Zapotitlán, Km 19+350 de la carretera federal No. 15 Navojoa-Los Mochis, Ahome, Sinaloa, CP 81304', 'sabor' => 'industrial', 'grande' => false],
    ];
}

const TIPOS_ESTANDAR = [
    ['nombre' => 'PQS',            'descripcion' => 'Polvo Químico Seco'],
    ['nombre' => 'CO2',            'descripcion' => 'Dióxido de Carbono'],
    ['nombre' => 'Agua a Presión', 'descripcion' => 'Agua a presión (Clase A)'],
    ['nombre' => 'Espuma AFFF',    'descripcion' => 'Espuma formadora de película acuosa'],
];

const SECCIONES = [
    'corporativo' => ['Recepción', 'Piso 3', 'Piso 6', 'Cuarto de servidores', 'Cocineta', 'Sala de juntas'],
    'industrial'  => ['Área de Proceso', 'Subestación Eléctrica', 'Almacén General', 'Comedor', 'Taller de Mantenimiento', 'Sala de Control', 'Patio de Tanques', 'Oficinas Administrativas'],
    'eolico'      => ['Subestación', 'Casa de Control', 'Almacén de Refacciones', 'Oficinas', 'Base de Aerogenerador'],
];

const DETALLES_UBICACION = [
    'Junto a la puerta principal', 'Pasillo central', 'Cerca del tablero eléctrico',
    'Junto a la salida de emergencia', 'Área de trabajo', 'Pasillo de acceso',
    'Junto a la escalera', 'Cerca del extintor de respaldo', 'A la entrada del área',
];

const CAPACIDADES = [
    'corporativo' => [4.5, 6, 9],
    'industrial'  => [4.5, 9, 12, 25, 50],
    'eolico'      => [4.5, 9, 12, 25],
];

/** Rango de cantidad de extintores según el sabor y tamaño de la planta. */
function rangoExtintores(string $sabor, bool $grande): array {
    if ($sabor === 'corporativo') return [6, 10];
    if ($sabor === 'eolico')      return [15, 25];
    return $grande ? [55, 70] : [35, 50];
}

function elegir(array $a) { return $a[array_rand($a)]; }

/** Asegura que existan tipos de extintor activos; si no hay ninguno, crea los 4 estándar. */
function asegurarTipos(PDO $pdo): array {
    $tipos = $pdo->query("SELECT id, nombre FROM tipos_extintores WHERE estado = 'activo'")->fetchAll(PDO::FETCH_ASSOC);
    if ($tipos) return $tipos;

    $ins = $pdo->prepare("INSERT INTO tipos_extintores (nombre, descripcion, estado) VALUES (?,?,'activo')");
    foreach (TIPOS_ESTANDAR as $t) $ins->execute([$t['nombre'], $t['descripcion']]);
    return $pdo->query("SELECT id, nombre FROM tipos_extintores WHERE estado = 'activo'")->fetchAll(PDO::FETCH_ASSOC);
}

/** De la lista de tipos disponibles, prioriza los que coincidan con esos nombres; si ninguno coincide, usa todos. */
function filtrarTipos(array $tipos, array $nombresPreferidos): array {
    $f = array_values(array_filter($tipos, function ($t) use ($nombresPreferidos) {
        foreach ($nombresPreferidos as $n) if (stripos($t['nombre'], $n) !== false) return true;
        return false;
    }));
    return $f ?: $tipos;
}

function tiposPorSabor(array $tipos, string $sabor): array {
    if ($sabor === 'corporativo') return filtrarTipos($tipos, ['PQS', 'CO2']);
    return $tipos; // industrial y eólico usan la mezcla completa
}

/** Fecha de recarga: 85% reciente (extintor al corriente), 15% vencida hace 12-18 meses (dispara "a mantenimiento"). */
function fechaRecarga(): string {
    $diasAtras = (mt_rand(1, 100) <= 15) ? mt_rand(370, 540) : mt_rand(0, 330);
    return date('Y-m-d', strtotime("-$diasAtras days"));
}

/** Próxima prueba hidrostática: 10% vencida, 10% próxima a vencer (15 días), resto a futuro. */
function fechaPH(): string {
    $r = mt_rand(1, 100);
    if ($r <= 10) return date('Y-m-d', strtotime('-' . mt_rand(1, 120) . ' days'));
    if ($r <= 20) return date('Y-m-d', strtotime('+' . mt_rand(1, 15) . ' days'));
    return date('Y-m-d', strtotime('+' . mt_rand(180, 1460) . ' days'));
}

// ═══════════════════════════════════════════════════════════════════════════
$resultados = null;
$gerenteInfo = null;
$errorGeneral = null;

$inspectores = $pdo->query("SELECT id, nombre FROM usuarios WHERE rol = 'inspector' AND estado = 'activo' ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

// Estado actual de cada centro, para la previsualización
$preview = [];
foreach (centros() as $c) {
    $stmt = $pdo->prepare("SELECT id FROM empresas WHERE nombre = ?");
    $stmt->execute([$c['nombre']]);
    $empresaId = $stmt->fetchColumn();
    $tieneExtintores = false;
    if ($empresaId) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM extintores WHERE empresa_id = ?");
        $stmt->execute([$empresaId]);
        $tieneExtintores = $stmt->fetchColumn() > 0;
    }
    $preview[] = $c + ['empresa_id' => $empresaId ?: null, 'tiene_extintores' => $tieneExtintores];
}
$gerenteYaExiste = (bool) $pdo->query("SELECT id FROM usuarios WHERE username = '" . GERENTE_USERNAME . "'")->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'sembrar') {
    $inspectorId = intval($_POST['inspector_id'] ?? 0);
    $inspectorValido = in_array($inspectorId, array_column($inspectores, 'id'));

    if (!$inspectores) {
        $errorGeneral = 'No hay ningún inspector activo en el sistema. Crea uno primero en Gestionar Usuarios.';
    } elseif (!$inspectorValido) {
        $errorGeneral = 'Elige a qué inspector se le atribuye el historial sembrado.';
    } else {
        // La tabla de asignaciones se crea (si falta) ANTES de abrir la transacción:
        // un CREATE TABLE en MySQL hace commit implícito y rompería la transacción
        // si se ejecutara a la mitad.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS gerente_empresas (
                id INT AUTO_INCREMENT PRIMARY KEY, gerente_id INT NOT NULL, empresa_id INT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_gerente_empresa (gerente_id, empresa_id),
                KEY idx_gerente (gerente_id), KEY idx_empresa (empresa_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        try {
            $pdo->beginTransaction();

            $tipos = asegurarTipos($pdo);
            $resultados = [];
            $empresaIds = [];

            foreach (centros() as $c) {
                $stmt = $pdo->prepare("SELECT id FROM empresas WHERE nombre = ?");
                $stmt->execute([$c['nombre']]);
                $empresaId = $stmt->fetchColumn();

                if (!$empresaId) {
                    $pdo->prepare("INSERT INTO empresas (nombre, domicilio, estado) VALUES (?,?,'activo')")
                        ->execute([$c['nombre'], $c['domicilio']]);
                    $empresaId = $pdo->lastInsertId();
                }
                $empresaIds[] = $empresaId;

                $stmt = $pdo->prepare("SELECT COUNT(*) FROM extintores WHERE empresa_id = ?");
                $stmt->execute([$empresaId]);
                $yaTiene = $stmt->fetchColumn() > 0;

                $creados = 0;
                if (!$yaTiene) {
                    $tiposDisponibles = tiposPorSabor($tipos, $c['sabor']);
                    $secciones        = SECCIONES[$c['sabor']];
                    $capacidades      = CAPACIDADES[$c['sabor']];
                    [$min, $max]      = rangoExtintores($c['sabor'], $c['grande']);
                    $cantidad         = mt_rand($min, $max);

                    $insExt = $pdo->prepare("
                        INSERT INTO extintores
                            (codigo_qr, codigo_manual, empresa_id, seccion, ubicacion, tipo, capacidad,
                             fecha_recarga, fecha_ph, estado, observaciones, creado_por)
                        VALUES (NULL,?,?,?,?,?,?,?,?,?,NULL,?)
                    ");
                    $insInsp = $pdo->prepare("
                        INSERT INTO inspecciones
                            (extintor_id, inspector_id, fecha, hora, ser, mg, po, ph, sg, ps, ob, dan, pin, fn, gb, rv, observaciones)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NULL)
                    ");

                    for ($n = 1; $n <= $cantidad; $n++) {
                        $codigoManual = 'EXT-' . str_pad($n, 3, '0', STR_PAD_LEFT);
                        $seccion = elegir($secciones);
                        if ($c['sabor'] === 'eolico' && $seccion === 'Base de Aerogenerador') {
                            $seccion .= ' ' . mt_rand(1, 6);
                        }
                        $ubicacion = $seccion . ' — ' . elegir(DETALLES_UBICACION);
                        $tipo = elegir($tiposDisponibles);
                        $estado = (mt_rand(1, 100) <= 5) ? 'en_prestamo' : 'activo';

                        $insExt->execute([
                            $codigoManual, $empresaId, $seccion, $ubicacion, $tipo['id'], elegir($capacidades),
                            fechaRecarga(), fechaPH(), $estado, $uid,
                        ]);
                        $extintorId = $pdo->lastInsertId();

                        // Historial de los últimos 6 meses (incluye el mes actual), un registro por mes.
                        // Se ancla al mes con el mismo cálculo que ya usa private/gerente-empresa.php,
                        // y en el mes actual el día nunca rebasa hoy (para no generar fechas futuras).
                        for ($i = 5; $i >= 0; $i--) {
                            $f = strtotime("-$i months");
                            $m = (int) date('n', $f);
                            $a = (int) date('Y', $f);
                            $diasEnMes = (int) date('t', mktime(0, 0, 0, $m, 1, $a));
                            $diaMax = ($i === 0) ? max(1, (int) date('j')) : $diasEnMes;
                            $fecha = sprintf('%04d-%02d-%02d', $a, $m, mt_rand(1, $diaMax));
                            $hora  = sprintf('%02d:%02d:00', mt_rand(8, 16), mt_rand(0, 59));
                            $campos = ['ser', 'mg', 'po', 'ph', 'sg', 'ps', 'ob', 'dan', 'pin', 'fn', 'gb', 'rv'];
                            $vals = [];
                            foreach ($campos as $campo) {
                                $vals[$campo] = (mt_rand(1, 100) <= 5) ? 'NC' : 'OK';
                            }
                            // Fuerza "sin presión" en un subconjunto, para que la gráfica de mantenimiento tenga datos
                            if (mt_rand(1, 100) <= 8) $vals['ps'] = 'NC';

                            $insInsp->execute([
                                $extintorId, $inspectorId, $fecha, $hora,
                                $vals['ser'], $vals['mg'], $vals['po'], $vals['ph'], $vals['sg'], $vals['ps'],
                                $vals['ob'], $vals['dan'], $vals['pin'], $vals['fn'], $vals['gb'], $vals['rv'],
                            ]);
                        }
                        $creados++;
                    }
                }

                $resultados[] = [
                    'nombre'     => $c['nombre'],
                    'estado'     => $yaTiene ? 'ya tenía extintores (se dejó igual)' : 'sembrada',
                    'creados'    => $creados,
                    'empresa_id' => $empresaId,
                ];
            }

            // Usuario gerente, asignado a las 14 plantas
            $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE username = ?");
            $stmt->execute([GERENTE_USERNAME]);
            $gerenteId = $stmt->fetchColumn();
            $gerenteNuevo = !$gerenteId;

            if (!$gerenteId) {
                $pdo->prepare("
                    INSERT INTO usuarios (nombre, username, email, password, rol, empresa_id, estado)
                    VALUES (?,?,NULL,?,'gerente',NULL,'activo')
                ")->execute([
                    'Gerente Corporativo AVBA', GERENTE_USERNAME,
                    password_hash(GERENTE_PASSWORD, PASSWORD_BCRYPT),
                ]);
                $gerenteId = $pdo->lastInsertId();
            }

            $pdo->prepare("DELETE FROM gerente_empresas WHERE gerente_id = ?")->execute([$gerenteId]);
            $insGe = $pdo->prepare("INSERT INTO gerente_empresas (gerente_id, empresa_id) VALUES (?,?)");
            foreach ($empresaIds as $eid) $insGe->execute([$gerenteId, $eid]);

            $stmt = $pdo->prepare("INSERT INTO auditoria (usuario_id,accion,tabla,registro_id,ip) VALUES (?,?,?,?,?)");
            $stmt->execute([$uid, 'Sembrar 14 plantas de demo + gerente corporativo', 'empresas', null, $_SERVER['REMOTE_ADDR'] ?? null]);

            $pdo->commit();

            $gerenteInfo = ['username' => GERENTE_USERNAME, 'password' => $gerenteNuevo ? GERENTE_PASSWORD : null, 'nuevo' => $gerenteNuevo];
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errorGeneral = 'No se pudo completar la siembra: ' . $e->getMessage();
            $resultados = null;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sembrar Plantas de Demo</title>
<style>
    *{margin:0;padding:0;box-sizing:border-box}
    body{font-family:'Segoe UI',system-ui,sans-serif;background:#eef2fb;color:#1a2138}
    .navbar{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;padding:16px 26px;
            display:flex;justify-content:space-between;align-items:center;box-shadow:0 4px 16px rgba(102,126,234,.2)}
    .navbar a{color:#fff;text-decoration:none;font-size:13px;opacity:.9}.navbar a:hover{opacity:1;text-decoration:underline}
    .container{max-width:1000px;margin:0 auto;padding:26px 20px}
    h2{font-size:24px;color:#1e293b}
    .sub{color:#64748b;font-size:13px;margin-bottom:20px}
    .card{background:#fff;border-radius:14px;padding:20px;box-shadow:0 4px 14px rgba(30,41,59,.08);margin-bottom:20px}
    table{width:100%;border-collapse:collapse}
    thead{background:#f1f5fb}
    th{padding:10px 9px;text-align:left;font-size:11px;color:#475569;font-weight:700;text-transform:uppercase}
    td{padding:10px 9px;font-size:13px;border-bottom:1px solid #f1f5f9}
    .badge{padding:4px 10px;border-radius:20px;font-size:11px;font-weight:700;display:inline-block}
    .b-crear{background:#fef3c7;color:#92400e}.b-existe{background:#e2e8f0;color:#475569}
    .b-ok{background:#d1fae5;color:#047857}
    .btn{padding:11px 20px;border:none;border-radius:8px;font-weight:700;cursor:pointer;font-size:14px}
    .btn-primary{background:#667eea;color:#fff}.btn-primary:hover{background:#5568d3}
    .btn-primary:disabled{background:#a5b4fc;cursor:not-allowed}
    .fg{margin-bottom:16px}
    .fg label{display:block;font-size:12px;font-weight:700;color:#475569;margin-bottom:6px}
    .fg select{width:100%;max-width:420px;padding:10px;border:2px solid #e0e0ff;border-radius:8px;font-size:14px}
    .alerta{padding:14px 16px;border-radius:10px;font-size:13px;margin-bottom:18px}
    .alerta.err{background:#fee2e2;color:#b91c1c}
    .alerta.warn{background:#fef3c7;color:#92400e}
    .alerta.ok{background:#d1fae5;color:#047857}
    .creds{background:#111827;color:#f9fafb;border-radius:10px;padding:16px;font-family:ui-monospace,monospace;font-size:14px;margin-top:10px}
    .creds b{color:#facc15}
</style>
</head>
<body>
<div class="navbar">
    <a href="admin-dashboard.php">← Panel Admin</a>
    <span style="font-size:13px">👤 <?= htmlspecialchars($nombreAdmin) ?></span>
</div>

<div class="container">
    <h2>🌱 Sembrar Plantas de Demo</h2>
    <div class="sub">Crea los 14 centros de trabajo como empresas, con extintores precargados, historial de inspecciones y un gerente corporativo asignado a las 14. Es seguro volver a ejecutarla: lo que ya existe se respeta.</div>

    <?php if ($errorGeneral): ?>
        <div class="alerta err">⚠️ <?= htmlspecialchars($errorGeneral) ?></div>
    <?php endif; ?>

    <?php if ($resultados): ?>
        <div class="card">
            <h3 style="margin-bottom:12px">✓ Resultado</h3>
            <table>
                <thead><tr><th>Planta</th><th>Estado</th><th>Extintores creados</th></tr></thead>
                <tbody>
                <?php foreach ($resultados as $r): ?>
                    <tr>
                        <td><?= htmlspecialchars($r['nombre']) ?></td>
                        <td><span class="badge <?= $r['creados'] > 0 ? 'b-ok' : 'b-existe' ?>"><?= htmlspecialchars($r['estado']) ?></span></td>
                        <td><?= $r['creados'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($gerenteInfo): ?>
                <div class="creds">
                    Usuario gerente: <b><?= htmlspecialchars($gerenteInfo['username']) ?></b><br>
                    <?php if ($gerenteInfo['nuevo']): ?>
                        Contraseña temporal: <b><?= htmlspecialchars($gerenteInfo['password']) ?></b><br>
                        <span style="color:#9ca3af">Cámbiala luego desde Gestionar Usuarios.</span>
                    <?php else: ?>
                        <span style="color:#9ca3af">Ya existía — se reasignaron las 14 plantas, la contraseña no cambió.</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <a class="btn btn-primary" href="admin-sembrar-plantas.php">Ver previsualización de nuevo</a>

    <?php else: ?>
        <div class="card">
            <table>
                <thead><tr><th>Centro de trabajo</th><th>Domicilio</th><th>Estado</th></tr></thead>
                <tbody>
                <?php foreach ($preview as $p): ?>
                    <tr>
                        <td style="font-weight:600"><?= htmlspecialchars($p['nombre']) ?></td>
                        <td style="color:#64748b"><?= htmlspecialchars($p['domicilio']) ?></td>
                        <td>
                            <?php if (!$p['empresa_id']): ?>
                                <span class="badge b-crear">se creará</span>
                            <?php elseif (!$p['tiene_extintores']): ?>
                                <span class="badge b-crear">existe, se le agregarán extintores</span>
                            <?php else: ?>
                                <span class="badge b-existe">ya existe con extintores</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="card">
            <?php if (!$inspectores): ?>
                <div class="alerta warn">No hay ningún inspector activo todavía. Crea uno en <a href="admin-usuarios.php">Gestionar Usuarios</a> antes de sembrar el historial de inspecciones.</div>
            <?php endif; ?>
            <?php if ($gerenteYaExiste): ?>
                <div class="alerta ok">El usuario gerente (<?= htmlspecialchars(GERENTE_USERNAME) ?>) ya existe — se le reasignarán las 14 plantas sin tocar su contraseña.</div>
            <?php endif; ?>

            <form method="post">
                <input type="hidden" name="accion" value="sembrar">
                <div class="fg">
                    <label>¿A qué inspector se le atribuye el historial de inspecciones sembrado?</label>
                    <select name="inspector_id" <?= $inspectores ? '' : 'disabled' ?>>
                        <?php foreach ($inspectores as $i): ?>
                            <option value="<?= $i['id'] ?>"><?= htmlspecialchars($i['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary" <?= $inspectores ? '' : 'disabled' ?>>Confirmar y sembrar</button>
            </form>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
