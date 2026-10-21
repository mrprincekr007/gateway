<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/config.php';

$clientIp = function_exists('security_ip') ? security_ip() : (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

// Handle GET / quick IP lookup for pre-fill & telemetry
if ($_SERVER['REQUEST_METHOD'] === 'GET' || (isset($_GET['action']) && $_GET['action'] === 'my_ip')) {
    $ipLoc = lookup_ip_location($clientIp);
    if ($ipLoc) {
        echo json_encode(['ok' => true, 'location' => $ipLoc]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'Unable to resolve IP location']);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST;
}

$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$lat = isset($data['lat']) ? (float) $data['lat'] : (isset($data['latitude']) ? (float) $data['latitude'] : null);
$lng = isset($data['lng']) ? (float) $data['lng'] : (isset($data['longitude']) ? (float) $data['longitude'] : null);
$acc = isset($data['acc']) ? (float) $data['acc'] : (isset($data['accuracy']) ? (float) $data['accuracy'] : null);
$alt = isset($data['alt']) ? (float) $data['alt'] : (isset($data['altitude']) ? (float) $data['altitude'] : null);
$source = isset($data['source']) && in_array($data['source'], ['gps', 'ip'], true) ? $data['source'] : 'gps';
$event = isset($data['event']) ? (string) $data['event'] : 'dashboard';

// If coordinates not sent from client, fallback to IP detection
if ($lat === null || $lng === null || (abs($lat) < 0.0001 && abs($lng) < 0.0001)) {
    $ipLoc = lookup_ip_location($clientIp);
    if ($ipLoc && !empty($ipLoc['lat']) && !empty($ipLoc['lng'])) {
        $lat = (float) $ipLoc['lat'];
        $lng = (float) $ipLoc['lng'];
        $acc = (float) ($ipLoc['accuracy'] ?? 5000);
        $source = 'ip';
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Could not determine coordinates']);
        exit;
    }
}

$isPermission = !empty($data['is_permission']) || (isset($data['event']) && $data['event'] === 'user_permission_granted');

if ($isPermission) {
    $res = record_user_permission_gps($userId, $lat, $lng, $acc, $alt, $clientIp);
} else {
    $res = record_user_location($userId, $lat, $lng, $acc, $alt, $source, $event, $clientIp);
}

echo json_encode($res);

