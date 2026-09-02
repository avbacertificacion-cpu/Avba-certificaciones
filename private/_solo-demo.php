<?php
/**
 * Pantalla que se muestra cuando se intenta sembrar datos de ejemplo desde el
 * sistema real. No es un error: es el aviso de que esa herramienta vive en la
 * otra instalación, la de demostración, con su propia base de datos.
 *
 * La incluye admin-sembrar-plantas.php; espera $nombreAdmin.
 */
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sembrar plantas · sólo en demostración</title>
<style>
    *{margin:0;padding:0;box-sizing:border-box}
    body{font-family:'Segoe UI',system-ui,sans-serif;background:#eef2fb;color:#1a2138}
    .navbar{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;padding:16px 26px;
            display:flex;justify-content:space-between;align-items:center}
    .navbar a{color:#fff;text-decoration:none;font-size:13px;opacity:.9}
    .container{max-width:760px;margin:0 auto;padding:34px 20px}
    .card{background:#fff;border-radius:14px;padding:26px;box-shadow:0 4px 14px rgba(30,41,59,.08)}
    h2{font-size:21px;margin-bottom:10px}
    p{font-size:14px;line-height:1.65;color:#475569;margin-bottom:12px}
    code{background:#f1f5fb;padding:2px 7px;border-radius:5px;font-size:13px}
    .nota{background:#fef3c7;color:#92400e;border-radius:10px;padding:14px 16px;font-size:13px;line-height:1.6;margin:16px 0}
    .btn{display:inline-block;background:#667eea;color:#fff;text-decoration:none;padding:11px 20px;
         border-radius:8px;font-weight:700;font-size:14px;margin-top:6px}
    ol{margin:0 0 14px 20px;font-size:14px;line-height:1.8;color:#475569}
</style>
</head>
<body>
<div class="navbar">
    <a href="admin-dashboard.php">← Panel Admin</a>
    <span style="font-size:13px">👤 <?= htmlspecialchars($nombreAdmin) ?></span>
</div>

<div class="container">
    <div class="card">
        <h2>🔒 Esta herramienta vive en el sistema de demostración</h2>
        <p>
            Sembrar las 14 plantas de ejemplo está bloqueado aquí a propósito. Los datos de
            demostración mezclados con los reales falsean los tableros, los porcentajes de
            inspección y los reportes que se le entregan al cliente.
        </p>

        <div class="nota">
            La demo y el sistema real son <b>dos instalaciones separadas, con dos bases de datos
            distintas</b>. Nada de lo que se cargue en una aparece en la otra.
        </div>

        <p><b>Para habilitar la siembra en la instalación de demostración</b>, agrega esta línea
        a su <code>config/config.php</code> (sólo al de la demo, nunca al del sistema real):</p>
        <ol>
            <li><code>define('MODO_DEMO', true);</code></li>
            <li>Vuelve a abrir esta pantalla desde la demo y siembra las plantas ahí.</li>
        </ol>

        <p>Si en <b>este</b> sistema ya quedaron plantas de ejemplo de una carga anterior,
        puedes retirarlas sin tocar la información real:</p>
        <a class="btn" href="admin-quitar-demo.php">🧹 Revisar y quitar datos de demostración</a>
    </div>
</div>
</body>
</html>
