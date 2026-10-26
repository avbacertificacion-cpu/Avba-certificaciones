<?php
header('Content-Type: application/json');
echo json_encode([
    'status' => 'ok',
    'message' => 'API is working correctly',
    'timestamp' => date('Y-m-d H:i:s'),
    // Sin versión de PHP: es pública, y saber qué versión corre el servidor
    // sólo le sirve a quien busca vulnerabilidades conocidas de esa versión.
]);
?>
