<?php
// Configuración de la aplicación
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'extinguisher_management');
define('APP_NAME', 'Sistema de Gestión de Extintores');
define('APP_URL', 'http://localhost/extinguisher-system');

// Configuración de sesión
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Conectar a la base de datos
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}

// Roles disponibles
define('ROLE_ADMIN', 'administrador');
define('ROLE_INSPECTOR', 'inspector');
define('ROLE_CLIENTE', 'cliente');
