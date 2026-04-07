<?php
/**
 * AVBA Certificaciones — Script de instalación
 *
 * Ejecutar UNA SOLA VEZ en el servidor:
 *   https://tudominio.com/install.php
 *
 * Luego ELIMINAR este archivo del servidor por seguridad.
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

$pdo = Database::getConnection();

echo "<pre style='font-family:monospace;font-size:13px;padding:20px'>";
echo "═══════════════════════════════════════\n";
echo " AVBA Certificaciones — Instalación\n";
echo "═══════════════════════════════════════\n\n";

// 1. Ejecutar schema.sql
$sql = file_get_contents(__DIR__ . '/sql/schema.sql');

// Eliminar comentarios de línea (-- ...) antes de dividir
$sql = preg_replace('/--[^\n]*\n/', "\n", $sql);

// Dividir en sentencias individuales y ejecutar
$sentencias = array_filter(array_map('trim', explode(';', $sql)));

$ok  = 0;
$err = 0;

foreach ($sentencias as $sentencia) {
    if (empty($sentencia)) continue;
    try {
        $pdo->exec($sentencia);
        $ok++;
    } catch (PDOException $e) {
        // Ignorar errores de "ya existe" (IF NOT EXISTS / INSERT IGNORE)
        if (strpos($e->getMessage(), 'already exists') === false &&
            strpos($e->getMessage(), 'Duplicate entry') === false) {
            echo "⚠️  " . $e->getMessage() . "\n";
            $err++;
        }
    }
}

echo "✅ Esquema ejecutado: {$ok} sentencias OK, {$err} errores\n\n";

// 2. Crear usuario admin por defecto
$usuarios = [
    ['admin',      'avba2026',  'ADMIN',          'Administrador AVBA',      null],
    ['yoselinmj',  'avba2026',  'CALIDAD',         'Yoselin Martinez Juarez', null],
];

echo "Creando usuarios por defecto:\n";
foreach ($usuarios as [$usr, $pass, $rol, $nombre, $idCliente]) {
    $hash = password_hash($pass, PASSWORD_BCRYPT);
    try {
        $stmt = $pdo->prepare(
            "INSERT IGNORE INTO usuarios (usuario, password_hash, rol, nombre, id_cliente, activo)
             VALUES (?, ?, ?, ?, ?, 1)"
        );
        $stmt->execute([$usr, $hash, $rol, $nombre, $idCliente]);
        if ($stmt->rowCount() > 0) {
            echo "  ✅ Creado: {$usr} / {$pass}  [{$rol}]\n";
        } else {
            echo "  ℹ️  Ya existe: {$usr}\n";
        }
    } catch (PDOException $e) {
        echo "  ❌ Error al crear {$usr}: " . $e->getMessage() . "\n";
    }
}

// 3. Crear carpetas necesarias
echo "\nCreando carpetas de uploads:\n";
$dirs = [
    __DIR__ . '/uploads/',
    __DIR__ . '/uploads/certificados/',
    __DIR__ . '/uploads/evidencias/',
];
foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
        echo "  ✅ Creada: {$dir}\n";
    } else {
        echo "  ℹ️  Ya existe: {$dir}\n";
    }
}

// 4. Verificar dependencias de Composer
echo "\nVerificando Composer:\n";
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    echo "  ✅ vendor/autoload.php encontrado\n";
} else {
    echo "  ⚠️  vendor/ no encontrado — ejecuta: composer install\n";
}

echo "\n═══════════════════════════════════════\n";
echo " Instalación completada.\n";
echo " ⚠️  ELIMINA este archivo del servidor!\n";
echo " rm install.php\n";
echo "═══════════════════════════════════════\n";
echo "</pre>";
