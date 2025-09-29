<?php
/**
 * PUT /medications/{id}/timings - อัพเดทเวลากินยา
 * ไฟล์: endpoints/medications/timings.php
 */

require_once __DIR__ . '/../../config/api_headers.php';
require_once __DIR__ . '/../../config/jwt_handler.php';
require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../../helpers/ResponseHelper.php';

setApiHeaders();
handleOptions();

$conn = getConnection();

if (!$conn) {
    ResponseHelper::serverError('การเชื่อมต่อฐานข้อมูลล้มเหลว');
}

if ($_SERVER['REQUEST_METHOD'] !== 'PUT' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    ResponseHelper::error('อนุญาตเฉพาะเมธอด PUT หรือ POST เท่านั้น', 405);
}

// ใช้ JWT handler แทน middleware
$auth_result = checkAuth();
$user_id = $auth_result['user_id'];
$connect_id = $auth_result['connect_id'];

// ✅ ดึง medication_id จาก URL parameter (RESTful style)
$medication_id = isset($_GET['medication_id']) ? intval($_GET['medication_id']) : 0;

if ($medication_id <= 0) {
    ResponseHelper::validationError('ต้องระบุ medication_id ที่ถูกต้อง');
}

$data = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE || !isset($data['timing_ids'])) {
    ResponseHelper::validationError('ข้อมูลไม่ครบถ้วนหรือ JSON ไม่ถูกต้อง');
}

$timing_ids = $data['timing_ids'];

// ตรวจสอบว่า timing_ids เป็น array และไม่ว่าง
if (!is_array($timing_ids) || empty($timing_ids)) {
    ResponseHelper::validationError('ต้องระบุ timing_ids เป็น array และไม่ว่าง');
}

// ตรวจสอบว่า timing_ids ทุกตัวเป็นตัวเลขและอยู่ในช่วง 1-7
$valid_timing_ids = [];
foreach ($timing_ids as $timing_id) {
    $timing_id = filter_var($timing_id, FILTER_VALIDATE_INT);
    if ($timing_id !== false && $timing_id >= 1 && $timing_id <= 7) {
        $valid_timing_ids[] = $timing_id;
    }
}

if (empty($valid_timing_ids)) {
    ResponseHelper::validationError('timing_ids ต้องอยู่ในช่วง 1-7 เท่านั้น');
}

// เอา timing_id ที่ซ้ำออก
$valid_timing_ids = array_unique($valid_timing_ids);

try {
    // เริ่มต้น transaction
    $conn->beginTransaction();
    
    // ตรวจสอบว่ายามีอยู่จริงหรือไม่ และตรวจสอบสิทธิ์
    $stmt = $conn->prepare("
        SELECT m.medication_id, m.connect_id, c.user_id 
        FROM medication m 
        LEFT JOIN connect c ON m.connect_id = c.connect_id 
        WHERE m.medication_id = ? AND c.user_id = ?
    ");
    $stmt->execute([$medication_id, $user_id]);
    
    if ($stmt->rowCount() === 0) {
        $conn->rollBack();
        ResponseHelper::notFound('ไม่พบยาที่ต้องการแก้ไข หรือไม่มีสิทธิ์เข้าถึง');
    }
    
    $medication_info = $stmt->fetch(PDO::FETCH_ASSOC);
    $med_connect_id = $medication_info['connect_id'];
    
    // ตรวจสอบว่า connect_id ตรงกับใน JWT หรือไม่
    if ($med_connect_id != $connect_id) {
        $conn->rollBack();
        ResponseHelper::error('ไม่มีสิทธิ์เข้าถึงการเชื่อมต่อนี้', 403);
    }
    
    // ลบ medication_timing เดิมทั้งหมด
    $stmt = $conn->prepare("DELETE FROM medication_timing WHERE medication_id = ?");
    $stmt->execute([$medication_id]);
    
    // เพิ่ม medication_timing ใหม่
    $new_medication_timings = [];
    
    foreach ($valid_timing_ids as $timing_id) {
        // ตรวจสอบว่า timing_id มีอยู่ใน table timing
        $check_timing = $conn->prepare("SELECT timing_id, timing FROM timing WHERE timing_id = ?");
        $check_timing->execute([$timing_id]);
        
        if ($check_timing->rowCount() > 0) {
            $timing_info = $check_timing->fetch(PDO::FETCH_ASSOC);
            
            // เพิ่ม medication_timing ใหม่
            $stmt_timing = $conn->prepare("
                INSERT INTO medication_timing (medication_id, timing_id) 
                VALUES (?, ?)
            ");
            $stmt_timing->execute([$medication_id, $timing_id]);
            
            $medication_timing_id = $conn->lastInsertId();
            $new_medication_timings[] = [
                'medication_timing_id' => (int)$medication_timing_id,
                'timing_id' => (int)$timing_id,
                'timing_name' => $timing_info['timing'] 
            ];
        }
    }
    

    $conn->commit();
    
    $response_data = [
        'medication_id' => (int)$medication_id,
        'medication_timings' => $new_medication_timings,
        'updated_timing_ids' => $valid_timing_ids,
        'timing_count' => count($new_medication_timings)
    ];

    ResponseHelper::success($response_data, 'อัปเดตเวลารับประทานยาสำเร็จ');
    
} catch (Exception $e) {
    // rollback transaction หากเกิดข้อผิดพลาด
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    
    error_log("Update medication timings error: " . $e->getMessage());
    ResponseHelper::serverError('เกิดข้อผิดพลาดในการประมวลผล');
}
?>