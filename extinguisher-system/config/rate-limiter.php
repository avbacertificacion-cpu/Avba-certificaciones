<?php

function checkRateLimit($ip, $endpoint, $maxRequests = 10, $timeWindow = 60) {
    global $pdo;

    if (!$pdo) return true;  // Si no hay conexión, permitir

    try {
        $now = time();
        $timeThreshold = $now - $timeWindow;

        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count
            FROM api_logs
            WHERE ip = ? AND endpoint = ? AND created_at > FROM_UNIXTIME(?)
        ");
        $stmt->execute([$ip, $endpoint, $timeThreshold]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $requestCount = $result['count'] ?? 0;

        if ($requestCount >= $maxRequests) {
            return false;
        }

        $stmt = $pdo->prepare("
            INSERT INTO api_logs (endpoint, ip, status_code)
            VALUES (?, ?, 200)
        ");
        $stmt->execute([$endpoint, $ip]);

        return true;
    } catch (Exception $e) {
        error_log("Rate limit check error: " . $e->getMessage());
        return true;  // Permitir si hay error
    }
}

function logApiAccess($ip, $endpoint, $statusCode, $details = null) {
    global $pdo;

    if (!$pdo) return;

    try {
        $stmt = $pdo->prepare("
            INSERT INTO api_logs (endpoint, ip, status_code, details)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$endpoint, $ip, $statusCode, $details]);
    } catch (Exception $e) {
        error_log("API access log error: " . $e->getMessage());
    }
}

function getClientIP() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    } else {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
?>
