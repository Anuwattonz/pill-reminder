<?php
require_once '../config/api_headers.php';
require_once '../config/jwt_handler.php';
require_once '../config/db_connection.php';
require_once '../config/upload_config.php';
require_once '../helpers/ResponseHelper.php';

setApiHeaders();
handleOptions();

// ตรวจสอบ method
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ResponseHelper::error('อนุญาตเฉพาะเมธอด GET เท่านั้น', 405);
}

// เชื่อมต่อฐานข้อมูล
$conn = getConnection();
if (!$conn) {
    ResponseHelper::serverError('การเชื่อมต่อฐานข้อมูลล้มเหลว');
}

// ตรวจสอบ auth
$auth = checkAuth();
$user_id = $auth['user_id'];
$connect_id = $auth['connect_id'];

// ตรวจสอบ app_id
$app_id = isset($_GET['app_id']) ? intval($_GET['app_id']) : 0;
if ($app_id <= 0) {
    ResponseHelper::validationError('ต้องระบุ app_id ที่ถูกต้อง');
}

try {
    // 1. ดึงข้อมูล app และตรวจสอบสิทธิ์
    $stmt = $conn->prepare("
        SELECT a.app_id, a.connect_id, a.pill_slot, a.timing, a.status
        FROM app a
        JOIN connect c ON a.connect_id = c.connect_id
        WHERE a.app_id = ? AND c.user_id = ?
    ");
    $stmt->execute([$app_id, $user_id]);
    $app_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$app_data) {
        ResponseHelper::notFound('ไม่พบ app หรือไม่มีสิทธิ์เข้าถึง');
    }

    // แปลงเป็น int
    $app_data['app_id'] = (int)$app_data['app_id'];
    $app_data['connect_id'] = (int)$app_data['connect_id'];
    $app_data['pill_slot'] = (int)$app_data['pill_slot'];

    // 2. ดึง timing options
    $stmt = $conn->prepare("SELECT timing_id, timing FROM timing WHERE timing_id = ?");
    $stmt->execute([$app_data['pill_slot']]);
    $timing_options = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($timing_options as &$timing) {
        $timing['timing_id'] = (int)$timing['timing_id'];
    }

    // 3. ดึงการตั้งค่าวัน (หรือสร้างใหม่ถ้าไม่มี)
    $stmt = $conn->prepare("
        SELECT sunday, monday, tuesday, wednesday, thursday, friday, saturday 
        FROM day_app WHERE app_id = ?
    ");
    $stmt->execute([$app_id]);
    $day_settings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$day_settings) {
        // สร้างใหม่
        $stmt = $conn->prepare("
            INSERT INTO day_app (app_id, sunday, monday, tuesday, wednesday, thursday, friday, saturday) 
            VALUES (?, 0, 0, 0, 0, 0, 0, 0)
        ");
        $stmt->execute([$app_id]);
        $day_settings = [
            'sunday' => 0, 'monday' => 0, 'tuesday' => 0, 'wednesday' => 0,
            'thursday' => 0, 'friday' => 0, 'saturday' => 0
        ];
    } else {
        // แปลงเป็น int
        foreach ($day_settings as $key => $value) {
            $day_settings[$key] = (int)$value;
        }
    }

    // 4. ดึงยาที่อยู่ในช่องนี้
    $stmt = $conn->prepare("
        SELECT ml.medication_link_id, ml.app_id, ml.medication_id, ml.amount, 
               m.medication_name, m.picture, ut.unit_type_name AS unit_type
        FROM medication_link ml 
        JOIN medication m ON ml.medication_id = m.medication_id 
        LEFT JOIN unit_type ut ON m.unit_type_id = ut.unit_type_id 
        WHERE ml.app_id = ?
    ");
    $stmt->execute([$app_id]);
    $medication_links = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($medication_links as &$link) {
        $link['medication_link_id'] = (int)$link['medication_link_id'];
        $link['app_id'] = (int)$link['app_id'];
        $link['medication_id'] = (int)$link['medication_id'];
        $link['amount'] = (float)$link['amount']; 
        $link['picture_url'] = getImageUrl($link['picture']);
    }

    // 5. ดึงยาที่เพิ่มได้
    $timing_id = $app_data['pill_slot'];
    $stmt = $conn->prepare("
        SELECT DISTINCT m.medication_id, m.connect_id, m.medication_name, 
               m.picture, ut.unit_type_name AS unit_type
        FROM medication m 
        JOIN medication_timing mt ON m.medication_id = mt.medication_id 
        LEFT JOIN unit_type ut ON m.unit_type_id = ut.unit_type_id 
        WHERE m.connect_id = ? AND mt.timing_id = ?
    ");
    $stmt->execute([$connect_id, $timing_id]);
    $available_medications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($available_medications as &$medication) {
        $medication['medication_id'] = (int)$medication['medication_id'];
        $medication['connect_id'] = (int)$medication['connect_id'];
        $medication['picture_url'] = getImageUrl($medication['picture']);
    }

    // ส่งข้อมูลกลับ
    ResponseHelper::success([
        'app_data' => $app_data,
        'timing_options' => $timing_options,
        'day_settings' => $day_settings,
        'medication_links' => $medication_links,
        'available_medications' => $available_medications
    ], 'โหลดข้อมูลสำเร็จ');

} catch (Exception $e) {
    error_log("Get reminder slot error: " . $e->getMessage());
    ResponseHelper::error($e->getMessage(), 400);
}