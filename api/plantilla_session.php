<?php
/**
 * api/plantilla_session.php
 * GET  → {"tipo":"equipos"} — devuelve plantilla activa en sesión
 */
session_start();
header('Content-Type: application/json');
echo json_encode(['tipo' => $_SESSION['plantilla_tipo'] ?? 'equipos']);
