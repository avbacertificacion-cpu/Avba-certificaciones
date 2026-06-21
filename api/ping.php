<?php
header('Content-Type: application/json');
echo json_encode([
    'status' => 'ok',
    'message' => 'API is working correctly',
    'timestamp' => date('Y-m-d H:i:s'),
    'php_version' => phpversion()
]);
?>
