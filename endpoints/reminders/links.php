<?php
/**
 * GET /reminders/links - ดึงข้อมูล medication links
 * ไฟล์: endpoints/reminders/links.php
*/


require_once __DIR__ . '/../../config/api_headers.php';
require_once __DIR__ . '/../../config/jwt_handler.php';
require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../../config/upload_config.php';
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

// ใช้ JWT middleware
$auth_result = checkAuth();
$user_id = $auth_result['user_id'];
$connect_id = $auth_result['connect_id'];

// ตรวจสอบ app_id
if (!isset($_GET['app_id']) || empty($_GET['app_id'])) {
    ResponseHelper::validationError('ต้องระบุ app_id');
}

$app_id = filter_var($_GET['app_id'], FILTER_VALIDATE_INT);
if ($app_id === false || $app_id <= 0) {
    ResponseHelper::validationError('app_id ไม่ถูกต้อง');
}

try {
    // เปลี่ยนการตรวจสอบ ownership ให้ง่ายขึ้น
    $stmt = $conn->prepare("
        SELECT a.app_id, a.pill_slot, a.status, a.timing
        FROM app a
        WHERE a.app_id = ? AND a.connect_id = ?
    ");
    $stmt->execute([$app_id, $connect_id]);
    $app_info = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$app_info) {
        // ส่งข้อมูลว่างกลับถ้าไม่พบ app
        $response_data = [
            'medication_links' => [],
            'app_info' => [
                'app_id' => (int)$app_id
            ]
        ];
        
        ResponseHelper::success($response_data, 'ไม่พบข้อมูล app หรือไม่มีสิทธิ์เข้าถึง');
        return;
    }

    error_log("Using UPDATED SQL query without dosage_name");
    $stmt = $conn->prepare("
        SELECT 
            ml.medication_link_id,
            ml.app_id,
            ml.medication_id,
            ml.amount,
            m.medication_name,
            m.picture,
            ut.unit_type_name
        FROM medication_link ml
        INNER JOIN medication m ON ml.medication_id = m.medication_id
        LEFT JOIN unit_type ut ON m.unit_type_id = ut.unit_type_id
        WHERE ml.app_id = ?
        ORDER BY ml.medication_link_id ASC
    ");
    $stmt->execute([$app_id]);
    $medication_links = $stmt->fetchAll(PDO::FETCH_ASSOC);

    error_log("Medication links count: " . count($medication_links));

    // เพิ่มข้อมูลเสริม
    foreach ($medication_links as &$link) {
        // แปลงข้อมูลเป็นประเภทที่ถูกต้อง
        $link['medication_link_id'] = (int)$link['medication_link_id'];
        $link['app_id'] = (int)$link['app_id'];
        $link['medication_id'] = (int)$link['medication_id'];
        $link['amount'] = (float)$link['amount'];
        
        $link['picture_url'] = getImageUrl($link['picture']);
        
        // สร้าง amount_with_unit แล้วลบ unit_type_name ออก
        $unit_type = $link['unit_type_name'] ?? 'เม็ด';
        $link['amount_with_unit'] = $link['amount'] . ' ' . $unit_type;
        
        // ลบ column ที่ไม่ต้องการออก (รวมทั้ง unit_type_name)
        unset($link['unit_type_name']);
    }

    error_log("Final response data prepared - UPDATED VERSION");

    // ส่งข้อมูลกลับ
    $response_data = [
        'medication_links' => $medication_links,
        'app_info' => [
            'app_id' => (int)$app_id,
            'pill_slot' => (int)$app_info['pill_slot'],
            'status' => (string)$app_info['status'],
            'timing' => $app_info['timing']
        ]
    ];

    $message = count($medication_links) > 0 
        ? 'ดึงข้อมูล medication links สำเร็จ (UPDATED VERSION)' 
        : 'ยังไม่มีการเพิ่มยาในช่องนี้';
        
    ResponseHelper::success($response_data, $message);

} catch (Exception $e) {
    error_log("Get medication links error: " . $e->getMessage());
    
    // ส่งข้อมูลว่างกลับแม้มี error  
    $response_data = [
        'medication_links' => [],
        'app_info' => [
            'app_id' => (int)$app_id
        ]
    ];
    
    ResponseHelper::success($response_data, 'เกิดข้อผิดพลาดในการดึงข้อมูล');
}
?>