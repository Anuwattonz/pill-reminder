<?php
require_once __DIR__ . '/../../config/api_headers.php';
require_once __DIR__ . '/../../config/db_connection.php';

setApiHeaders();
handleOptions();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$conn = getConnection();
if (!$conn) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
    exit;
}

$machine_SN = $_GET['machine_SN'] ?? null;
if (!$machine_SN) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'machine_SN required']);
    exit;
}

function timeToSeconds($time_string) {
    if (empty($time_string) || !is_string($time_string)) return 0;
    $parts = explode(':', $time_string);
    return ((int)($parts[0] ?? 0) * 3600) + ((int)($parts[1] ?? 0) * 60) + (int)($parts[2] ?? 0);
}

try {
    $stmt = $conn->prepare("SELECT machine_id FROM machinesn WHERE machine_SN = ?");
    $stmt->execute([$machine_SN]);
    $machine = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$machine) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Machine not found']);
        exit;
    }
    
    $machine_id = $machine['machine_id'];

    $stmt = $conn->prepare("SELECT connect_id, user_id FROM connect WHERE machine_id = ?");
    $stmt->execute([$machine_id]);
    $connect = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$connect) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Connection not found']);
        exit;
    }
    
    $connect_id = $connect['connect_id'];
    $user_id = $connect['user_id'];

    $stmt = $conn->prepare("SELECT app_id, pill_slot, timing, status FROM app WHERE connect_id = ? AND status = 1 ORDER BY pill_slot");
    $stmt->execute([$connect_id]);
    $active_apps = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $schedules = [];
    foreach ($active_apps as $app) {
        $app_id = $app['app_id'];
        
        $stmt = $conn->prepare("SELECT sunday, monday, tuesday, wednesday, thursday, friday, saturday FROM day_app WHERE app_id = ?");
        $stmt->execute([$app_id]);
        $day_data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$day_data) {
            $day_data = ['sunday' => 0, 'monday' => 0, 'tuesday' => 0, 'wednesday' => 0, 'thursday' => 0, 'friday' => 0, 'saturday' => 0];
        }

        $schedules[] = [
            'app_id' => (int)$app_id,
            'pill_slot' => (int)$app['pill_slot'],
            'timing' => $app['timing'],
            'status' => (int)$app['status'],
            'day_schedule' => [
                'app_id' => (int)$app_id,
                'sunday' => (int)$day_data['sunday'],
                'monday' => (int)$day_data['monday'],
                'tuesday' => (int)$day_data['tuesday'],
                'wednesday' => (int)$day_data['wednesday'],
                'thursday' => (int)$day_data['thursday'],
                'friday' => (int)$day_data['friday'],
                'saturday' => (int)$day_data['saturday']
            ]
        ];
    }

    $stmt = $conn->prepare("SELECT volume, delay, alert_offset FROM volume WHERE connect_id = ?");
    $stmt->execute([$connect_id]);
    $volume_settings = $stmt->fetch(PDO::FETCH_ASSOC);

    $volume_data = [
        'volume' => (int)($volume_settings['volume'] ?? 50),
        'delay_seconds' => timeToSeconds($volume_settings['delay'] ?? '00:30:00'),
        'alert_offset_seconds' => timeToSeconds($volume_settings['alert_offset'] ?? '00:05:00')
    ];

    echo json_encode([
        'status' => 'success',
        'message' => 'Schedule data retrieved',
        'timestamp' => time(),
        'data' => [
            'machine_id' => (int)$machine_id,
            'machine_SN' => $machine_SN,
            'connect_id' => (int)$connect_id,
            'user_id' => (int)$user_id,
            'active_schedules' => $schedules,
            'volume' => $volume_data,
            'total_active_apps' => count($schedules)
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("ESP32 Schedule API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Server error: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}