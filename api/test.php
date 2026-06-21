<?php
// Simple test endpoint to verify API is being served directly, not rewritten to index.php
header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'status' => 'ok',
    'message' => 'API endpoint is responding correctly (not rewritten to index.php)',
    'script' => basename(__FILE__),
    'path' => __FILE__,
    'timestamp' => date('Y-m-d H:i:s'),
    'server' => $_SERVER['HTTP_HOST'] ?? 'unknown'
]);
?>
