<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$diagnostics = [
    'timestamp' => date('Y-m-d H:i:s'),
    'php_version' => phpversion(),
    'server' => [
        'HTTP_HOST' => $_SERVER['HTTP_HOST'] ?? 'not set',
        'SCRIPT_NAME' => $_SERVER['SCRIPT_NAME'] ?? 'not set',
        'SCRIPT_FILENAME' => $_SERVER['SCRIPT_FILENAME'] ?? 'not set',
        'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? 'not set',
        'REQUEST_METHOD' => $_SERVER['REQUEST_METHOD'] ?? 'not set',
        'HTTPS' => $_SERVER['HTTPS'] ?? 'not set',
        'HTTP_X_FORWARDED_PROTO' => $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? 'not set',
    ],
    'file_checks' => [
        'config_exists' => file_exists('../config/config.php'),
        'index_exists' => file_exists('../index.php'),
        'public_validar_exists' => file_exists('../public/validar-extintor.html'),
        'validar_publico_exists' => file_exists('./validar-publico.php'),
    ],
    'rewrite_detection' => [
        'is_rewritten' => (strpos($_SERVER['REQUEST_URI'] ?? '', 'index.php') !== false),
        'query_string' => $_SERVER['QUERY_STRING'] ?? 'none',
    ]
];

// Try to detect if we're running through index.php redirect
if (isset($_SERVER['SCRIPT_FILENAME']) && strpos($_SERVER['SCRIPT_FILENAME'], 'index.php') !== false) {
    $diagnostics['rewrite_detection']['current_file'] = 'index.php (REWRITTEN)';
} else {
    $diagnostics['rewrite_detection']['current_file'] = basename($_SERVER['SCRIPT_FILENAME'] ?? 'unknown');
}

echo json_encode($diagnostics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>
