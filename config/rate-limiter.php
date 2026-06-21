<?php

// Table existence check (cache the result)
$api_logs_exists = null;

function tableExists($tableName) {
    global $pdo, $api_logs_exists;

    if ($api_logs_exists !== null) return $api_logs_exists;

    try {
        $result = $pdo->query("SELECT 1 FROM $tableName LIMIT 1");
        $api_logs_exists = true;
    } catch (Exception $e) {
        $api_logs_exists = false;
    }
    return $api_logs_exists;
}

function checkRateLimit($ip, $endpoint, $maxRequests = 10, $timeWindow = 60) {
    global $pdo;

    if (!$pdo || !tableExists('api_logs')) {
        return true;  // Si no hay tabla, permitir sin limitar
    }

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

    if (!$pdo || !tableExists('api_logs')) {
        return;  // Si no hay tabla, no loguear
    }

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
