<?php
/**
 * POST /devices/connect - เชื่อมต่อกับอุปกรณ์
 * ไฟล์: endpoints/devices/connect.php
 * 
 *
 */

require_once __DIR__ . '/../../config/api_headers.php';
require_once __DIR__ . '/../../config/jwt_handler.php';
require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../../config/config_loader.php'; 
require_once __DIR__ . '/../../helpers/ResponseHelper.php';

setApiHeaders();
handleOptions();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ResponseHelper::error('อนุญาตเฉพาะเมธอด POST เท่านั้น', 405);
}

$conn = getConnection();
if (!$conn) {
    ResponseHelper::serverError('การเชื่อมต่อฐานข้อมูลล้มเหลว');
}

// ตรวจสอบ JWT
$auth_result = checkAuth();
$user_id = $auth_result['user_id'];

// ✅ แก้ไข: ใช้ config แทน constants
$system_config = ConfigLoader::getSystemConfig();
$DEFAULT_VOLUME = $system_config['default_volume'];
$DEFAULT_DELAY = $system_config['default_delay'];
$DEFAULT_ALERT_OFFSET = $system_config['default_alert_offset'];
$DEFAULT_APP_STATUS = $system_config['default_app_status'];

$data = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE || !isset($data['machine_sn'])) {
    ResponseHelper::validationError('ข้อมูลไม่ครบถ้วนหรือ JSON ไม่ถูกต้อง');
}

$machineSn = trim($data['machine_sn']);
if ($machineSn === '') {
    ResponseHelper::validationError('ต้องระบุ machine_sn');
}

try {
    $conn->beginTransaction();

    // ตรวจสอบเครื่องจาก Serial Number
    $stmt = $conn->prepare("SELECT machine_id FROM machinesn WHERE machine_SN = ? LIMIT 1");
    $stmt->execute([$machineSn]);

    if ($stmt->rowCount() === 0) {
        $conn->rollBack();
        ResponseHelper::notFound('ไม่พบเครื่องที่มี Serial Number นี้ในระบบ');
    }

    $machineId = (int)$stmt->fetchColumn();

    // ตรวจสอบว่าเครื่องนี้ถูกเชื่อมต่อแล้วหรือไม่
    $stmt = $conn->prepare("SELECT connect_id, user_id FROM connect WHERE machine_id = ? LIMIT 1");
    $stmt->execute([$machineId]);

    if ($stmt->rowCount() > 0) {
        $existingConnection = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ((int)$existingConnection['user_id'] === $user_id) {
            $conn->rollBack();
            ResponseHelper::error('คุณได้เชื่อมต่อกับเครื่องนี้แล้ว', 409);
        } else {
            $conn->rollBack();
            ResponseHelper::error('เครื่องนี้ถูกเชื่อมต่อกับผู้ใช้อื่นแล้ว', 409);
        }
    }

    // ตรวจสอบว่าผู้ใช้เชื่อมต่อกับเครื่องอื่นอยู่หรือไม่
    $stmt = $conn->prepare("SELECT connect_id FROM connect WHERE user_id = ? LIMIT 1");
    $stmt->execute([$user_id]);

    if ($stmt->rowCount() > 0) {
        $conn->rollBack();
        ResponseHelper::error('คุณได้เชื่อมต่อกับเครื่องอื่นแล้ว กรุณาตัดการเชื่อมต่อก่อน', 409);
    }

    // สร้างการเชื่อมต่อใหม่
    $stmt = $conn->prepare("INSERT INTO connect (user_id, machine_id) VALUES (?, ?)");
    $stmt->execute([$user_id, $machineId]);
    $connectId = (int)$conn->lastInsertId();

    $stmt = $conn->prepare("
        INSERT INTO volume (volume, delay, alert_offset, connect_id) 
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$DEFAULT_VOLUME, $DEFAULT_DELAY, $DEFAULT_ALERT_OFFSET, $connectId]);

    // สร้าง app slots เริ่มต้น (7 slots)
    $defaultTimes = [
        '07:00:00', '08:00:00', '12:00:00', '18:00:00', 
        '19:00:00', '20:00:00', '21:00:00'
    ];

    $stmt = $conn->prepare("
        INSERT INTO app (pill_slot, connect_id, status, timing) 
        VALUES (?, ?, ?, ?)
    ");

    for ($i = 1; $i <= 7; $i++) {
        $timing = $defaultTimes[$i - 1] ?? '12:00:00';
        // ✅ แก้ไข: ใช้ตัวแปรแทน constant
        $stmt->execute([$i, $connectId, $DEFAULT_APP_STATUS, $timing]);
        $appId = $conn->lastInsertId();

        // สร้าง day_app เริ่มต้น (ปิดทุกวัน)
        $dayStmt = $conn->prepare("
            INSERT INTO day_app 
            (app_id, sunday, monday, tuesday, wednesday, thursday, friday, saturday) 
            VALUES (?, 0, 0, 0, 0, 0, 0, 0)
        ");
        $dayStmt->execute([$appId]);
    }

    $conn->commit();

    // ดึงข้อมูลการเชื่อมต่อที่สร้างแล้ว
    $stmt = $conn->prepare("
        SELECT 
            c.connect_id,
            u.user as username,
            m.machine_SN,
            v.volume,
            v.delay,
            v.alert_offset
        FROM connect c
        JOIN user u ON c.user_id = u.user_id
        JOIN machinesn m ON c.machine_id = m.machine_id
        JOIN volume v ON c.connect_id = v.connect_id
        WHERE c.connect_id = ?
    ");
    $stmt->execute([$connectId]);
    $connection_info = $stmt->fetch(PDO::FETCH_ASSOC);

    // สร้าง JWT token ใหม่ที่มี connect_id ใช้ระบบเดิม
    $new_access_token = JWTHandler::createToken($user_id, $connectId);
    $new_refresh_token = JWTHandler::createRefreshToken($user_id, $connectId);

    // ดึงการตั้งค่า expiration
    $config = ConfigLoader::getJwtConfig();
    $jwt_expiration = $config['expiration'];

    $response_data = [
        'token' => $new_access_token,
        'refresh_token' => $new_refresh_token,
        'expires_in' => $jwt_expiration,
        'connection' => [
            'machine_serial' => $connection_info['machine_SN'],
            'connected_at' => date('Y-m-d H:i:s'),
            'settings' => [
                'volume' => (int)$connection_info['volume'],
                'delay' => $connection_info['delay'],
                'alert_offset' => $connection_info['alert_offset']
            ]
        ]
    ];

    ResponseHelper::success($response_data, 'เชื่อมต่อกับอุปกรณ์สำเร็จ', false);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }

    error_log("Device connection error: " . $e->getMessage());
    ResponseHelper::serverError('เกิดข้อผิดพลาดในการเชื่อมต่ออุปกรณ์');
}
?>