<?php
/**
 * Herramienta de reparación (solo ADMIN):
 *  1) Convierte la columna usuarios.rol de ENUM a VARCHAR (para aceptar 'gerente'
 *     y roles futuros). Es idempotente: si ya es VARCHAR, no hace daño.
 *  2) Permite corregir el rol de cualquier usuario (p.ej. el que quedó vacío).
 *
 * Úsala una vez y listo. No requiere phpMyAdmin.
 */
require_once '../config/config.php';
require_once '../config/roles-extra.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== ROLE_ADMIN) {
    header('Location: ../public/login.html'); exit;
}

$mensajes = [];

// ── 1) Asegurar que la columna rol sea VARCHAR ───────────────────────────────
try {
    $col = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'rol'")->fetch(PDO::FETCH_ASSOC);
    $tipoActual = $col['Type'] ?? '';
    if (stripos($tipoActual, 'varchar') !== false) {
        $mensajes[] = ['ok', "La columna 'rol' ya es de texto ($tipoActual). No se necesitó cambio."];
    } else {
        $pdo->exec("ALTER TABLE usuarios MODIFY COLUMN rol VARCHAR(30) NOT NULL");
        $mensajes[] = ['ok', "Columna 'rol' convertida de <b>$tipoActual</b> a VARCHAR(30). Ahora acepta 'gerente'."];
    }
} catch (Exception $e) {
    $mensajes[] = ['err', "No se pudo ajustar la columna: " . htmlspecialchars($e->getMessage())];
}

// ── 2) Corregir el rol de un usuario (POST) ──────────────────────────────────
$rolesValidos = ['administrador','gerente','inspector','cliente'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uid_fix = intval($_POST['usuario_id'] ?? 0);
    $nuevo   = trim($_POST['nuevo_rol'] ?? '');
    if (!$uid_fix || !in_array($nuevo, $rolesValidos, true)) {
        $mensajes[] = ['err', "Datos inválidos para corregir el rol."];
    } else {
        try {
            $st = $pdo->prepare("UPDATE usuarios SET rol = ? WHERE id = ?");
            $st->execute([$nuevo, $uid_fix]);
            $mensajes[] = ['ok', "Rol actualizado a <b>$nuevo</b> correctamente."];
        } catch (Exception $e) {
            $mensajes[] = ['err', "No se pudo actualizar: " . htmlspecialchars($e->getMessage())];
        }
    }
}

// ── Lista de usuarios ────────────────────────────────────────────────────────
$usuarios = $pdo->query("SELECT id, nombre, username, rol, estado FROM usuarios ORDER BY rol, nombre")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reparar roles</title>
<style>
    *{margin:0;padding:0;box-sizing:border-box}
    body{font-family:'Segoe UI',sans-serif;background:#f0f4ff;color:#1a2138;padding:24px}
    .wrap{max-width:820px;margin:0 auto}
    h1{font-size:22px;color:#1e293b;margin-bottom:6px}
    .sub{color:#64748b;font-size:13px;margin-bottom:20px}
    a.back{color:#4338ca;text-decoration:none;font-size:13px}
    .msg{padding:12px 14px;border-radius:8px;margin-bottom:10px;font-size:14px}
    .msg.ok{background:#dcfce7;color:#166534}
    .msg.err{background:#fee2e2;color:#991b1b}
    .card{background:#fff;border-radius:12px;padding:20px;box-shadow:0 4px 14px rgba(0,0,0,.06);margin-top:16px}
    table{width:100%;border-collapse:collapse}
    th,td{padding:10px;font-size:13px;text-align:left;border-bottom:1px solid #eef1f7}
    th{color:#475569;font-size:12px}
    .empty-rol{color:#dc2626;font-weight:700}
    select,button{padding:8px 10px;border-radius:7px;border:1.5px solid #d7ddec;font-size:13px}
    button{background:#4338ca;color:#fff;border:none;font-weight:700;cursor:pointer}
    button:hover{background:#3730a3}
    form{display:flex;gap:8px;align-items:center}
</style>
</head>
<body>
<div class="wrap">
    <a class="back" href="admin-dashboard.php">← Panel Admin</a>
    <h1 style="margin-top:10px">🛠️ Reparar roles de usuario</h1>
    <div class="sub">Corrige la columna de rol y asigna el rol correcto a cualquier usuario, sin phpMyAdmin.</div>

    <?php foreach ($mensajes as $m): ?>
        <div class="msg <?= $m[0] ?>"><?= $m[1] ?></div>
    <?php endforeach; ?>

    <div class="card">
        <table>
            <thead><tr><th>Nombre</th><th>Usuario</th><th>Rol actual</th><th>Estado</th><th>Corregir rol</th></tr></thead>
            <tbody>
            <?php foreach ($usuarios as $u): ?>
                <tr>
                    <td><?= htmlspecialchars($u['nombre']) ?></td>
                    <td style="font-family:monospace"><?= htmlspecialchars($u['username']) ?></td>
                    <td><?= $u['rol'] === '' ? '<span class="empty-rol">(vacío)</span>' : htmlspecialchars($u['rol']) ?></td>
                    <td><?= htmlspecialchars($u['estado']) ?></td>
                    <td>
                        <form method="post">
                            <input type="hidden" name="usuario_id" value="<?= (int)$u['id'] ?>">
                            <select name="nuevo_rol">
                                <?php foreach ($rolesValidos as $r): ?>
                                    <option value="<?= $r ?>" <?= $u['rol'] === $r ? 'selected' : '' ?>><?= $r ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit">Guardar</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <p style="color:#94a3b8;font-size:12px;margin-top:16px">
        Ya puedes cerrar esta página. El cambio de columna es permanente; los futuros gerentes se crearán sin problema.
    </p>
</div>
</body>
</html>
