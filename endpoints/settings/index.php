<?php
/**
 * GET /settings - ดึงการตั้งค่า
 * ไฟล์: endpoints/settings/index.php
 */

require_once __DIR__ . '/../../config/api_headers.php';
require_once __DIR__ . '/../../config/jwt_handler.php';
require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../../helpers/ResponseHelper.php';

setApiHeaders();
handleOptions();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ResponseHelper::error('อนุญาตเฉพาะเมธอด GET เท่านั้น', 405);
}

$conn = getConnection();
if (!$conn) {
    ResponseHelper::serverError('การเชื่อมต่อฐานข้อมูลล้มเหลว');
}

// ตรวจสอบ JWT
$auth_result = checkAuth();
$user_id = $auth_result['user_id'];
$connect_id = $auth_result['connect_id'];

if (!$connect_id) {
    ResponseHelper::error('ต้องเชื่อมต่อกับอุปกรณ์ก่อน', 403);
}

try {
    // ดึงข้อมูลผู้ใช้และการเชื่อมต่อ
    $sql = "
        SELECT 
            u.user_id,
            u.user,
            u.email,
            c.connect_id,
            m.machine_SN,
            v.volume,
            v.delay,
            v.alert_offset
        FROM user u
        LEFT JOIN connect c ON u.user_id = c.user_id
        LEFT JOIN machinesn m ON c.machine_id = m.machine_id
        LEFT JOIN volume v ON c.connect_id = v.connect_id
        WHERE u.user_id = ? AND c.connect_id = ?
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$user_id, $connect_id]);
    $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user_data) {
        ResponseHelper::notFound('ไม่พบข้อมูลผู้ใช้');
    }

    $settings = [
        'settings' => [
            'volume' => (int)$user_data['volume'],
            'delay' => _convertTimeToSeconds($user_data['delay']),
            'alert_offset' => _convertTimeToSeconds($user_data['alert_offset'])
        ],
        'username' => $user_data['user']
    ];
    ResponseHelper::success($settings, 'ดึงการตั้งค่าสำเร็จ', false);
    
} catch (Exception $e) {
    error_log("Get settings error: " . $e->getMessage());
    ResponseHelper::serverError('เกิดข้อผิดพลาดในการดึงการตั้งค่า');
}


function _convertTimeToSeconds($timeString) {
    if (empty($timeString)) {
        return 0;
    }
    
    $parts = explode(':', $timeString);
    if (count($parts) !== 3) {
        return 0;
    }
    
    $hours = (int)$parts[0];
    $minutes = (int)$parts[1];
    $seconds = (int)$parts[2];
    
    return ($hours * 3600) + ($minutes * 60) + $seconds;
}
?>