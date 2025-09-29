<?php
/**
 * POST /medications - สร้างยาใหม่
 * ไฟล์: endpoints/medications/create.php
 * 
 */

require_once __DIR__ . '/../../config/api_headers.php';
require_once __DIR__ . '/../../config/jwt_handler.php';
require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../../config/upload_config.php';
require_once __DIR__ . '/../../helpers/ResponseHelper.php';

setApiHeaders();
handleOptions();

$conn = getConnection();

if (!$conn) {
    ResponseHelper::serverError('การเชื่อมต่อฐานข้อมูลล้มเหลว');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ResponseHelper::error('อนุญาตเฉพาะเมธอด POST เท่านั้น', 405);
}

// ใช้ JWT handler แทน middleware
$auth_result = checkAuth();
$user_id = $auth_result['user_id'];
$connect_id = $auth_result['connect_id'];

// ตรวจสอบข้อมูลจาก JWT
if ($user_id <= 0 || $connect_id <= 0) {
    ResponseHelper::validationError('ข้อมูลใน JWT ไม่ถูกต้อง');
}

// ✅ ดึงข้อมูลจาก POST request (รองรับทั้ง form data และ JSON)
$hasImage = isset($_FILES['picture']) && $_FILES['picture']['error'] === UPLOAD_ERR_OK;

if ($hasImage) {
    // มีรูปภาพ - ใช้ $_POST
    $data = $_POST;
} else {
    // ไม่มีรูปภาพ - ใช้ JSON
    $json_data = json_decode(file_get_contents('php://input'), true);
    if ($json_data) {
        $data = $json_data;
    } else {
        $data = $_POST; // fallback
    }
}

$medication_name = isset($data['medication_name']) ? trim($data['medication_name']) : '';
$medication_nickname = isset($data['medication_nickname']) && !empty(trim($data['medication_nickname'])) ? trim($data['medication_nickname']) : '-';
$description = isset($data['description']) && !empty(trim($data['description'])) ? trim($data['description']) : '-';
$dosage_form_id = isset($data['dosage_form_id']) ? intval($data['dosage_form_id']) : 0;
$unit_type_id = isset($data['unit_type_id']) ? intval($data['unit_type_id']) : 0;

// ✅ จัดการ timing_ids (รองรับทั้ง string และ array)
$timing_ids = '';
if (isset($data['timing_ids'])) {
    if (is_array($data['timing_ids'])) {
        $timing_ids = implode(',', $data['timing_ids']);
    } else {
        $timing_ids = trim($data['timing_ids']);
    }
}

// ตรวจสอบข้อมูลที่จำเป็น
if (empty($medication_name)) {
    ResponseHelper::validationError('กรุณาระบุชื่อยา');
}

if ($dosage_form_id <= 0) {
    ResponseHelper::validationError('กรุณาเลือกรูปแบบยา');
}

if ($unit_type_id <= 0) {
    ResponseHelper::validationError('กรุณาเลือกหน่วยยา');
}

if (empty($timing_ids)) {
    ResponseHelper::validationError('กรุณาเลือกเวลาการกินยา');
}

try {
    // เริ่มต้น transaction
    $conn->beginTransaction();
    
    // ตรวจสอบว่า dosage_form_id มีอยู่จริง
    $stmt = $conn->prepare("SELECT dosage_form_id, dosage_name FROM dosage_form WHERE dosage_form_id = ?");
    $stmt->execute([$dosage_form_id]);
    $dosage_form_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$dosage_form_data) {
        $conn->rollBack();
        ResponseHelper::validationError('รูปแบบยาที่เลือกไม่มีอยู่ในระบบ');
    }
    
    // ตรวจสอบว่า unit_type_id มีอยู่จริงและตรงกับ dosage_form_id
    $stmt = $conn->prepare("SELECT unit_type_id, unit_type_name, dosage_form_id FROM unit_type WHERE unit_type_id = ?");
    $stmt->execute([$unit_type_id]);
    $unit_type_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$unit_type_data) {
        $conn->rollBack();
        ResponseHelper::validationError('หน่วยยาที่เลือกไม่มีอยู่ในระบบ');
    }
    
    // ตรวจสอบความสัมพันธ์ระหว่าง dosage_form_id และ unit_type_id
    if ($unit_type_data['dosage_form_id'] != $dosage_form_id) {
        $conn->rollBack();
        ResponseHelper::validationError('หน่วยยาที่เลือกไม่ตรงกับรูปแบบยา');
    }
    
    // ตรวจสอบว่า connect_id นี้เป็นของ user นี้จริงหรือไม่
    $stmt = $conn->prepare("SELECT user_id, machine_id FROM connect WHERE connect_id = ? AND user_id = ?");
    $stmt->execute([$connect_id, $user_id]);

    $connect = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$connect) {
        $conn->rollBack();
        ResponseHelper::error('ไม่มีสิทธิ์เข้าถึงการเชื่อมต่อนี้', 403);
    }

    // จัดการไฟล์รูปภาพ โดยใช้ฟังก์ชันจาก upload_config.php
    $picture_filename = null;
    if ($hasImage) {
        $upload_result = handleImageUpload($_FILES['picture'], 'medication');
        
        if (!$upload_result['success']) {
            $conn->rollBack();
            ResponseHelper::validationError($upload_result['error']);
        }
        
        $picture_filename = $upload_result['filename'];
    }
    
    // บันทึกข้อมูลยา
    $stmt = $conn->prepare("
        INSERT INTO medication (
            connect_id, 
            medication_name, 
            medication_nickname, 
            description, 
            dosage_form_id,
            unit_type_id,
            picture
        ) VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $connect_id,
        $medication_name,
        $medication_nickname,
        $description,
        $dosage_form_id,
        $unit_type_id,
        $picture_filename
    ]);
    $medication_id = $conn->lastInsertId();
    
    // บันทึกข้อมูล timing
    $timing_id_array = array_filter(array_map('intval', explode(',', $timing_ids)));
    
    foreach ($timing_id_array as $timing_id) {
        $check_timing = $conn->prepare("SELECT timing_id, timing FROM timing WHERE timing_id = ?");
        $check_timing->execute([$timing_id]);
        $timing_info = $check_timing->fetch(PDO::FETCH_ASSOC);
        
        if ($timing_info) {
            $stmt_timing = $conn->prepare("
                INSERT INTO medication_timing (medication_id, timing_id) 
                VALUES (?, ?)
            ");
            $stmt_timing->execute([$medication_id, $timing_id]);
        }
    }
    
    // commit transaction
    $conn->commit();
    
    $response_data = [
        'medication_id' => (int)$medication_id,
        'medication_name' => $medication_name,
        'medication_nickname' => $medication_nickname,
        'description' => $description,
        'dosage_form_id' => $dosage_form_id,
        'unit_type_id' => $unit_type_id,
        'picture_filename' => $picture_filename,
        'picture_url' => getImageUrl($picture_filename),
        'display_name' => $medication_nickname !== '-' ? $medication_nickname : $medication_name,
        'timing_ids' => $timing_id_array
       
    ];

    ResponseHelper::success($response_data, 'สร้างข้อมูลยาสำเร็จ');

} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    
    // ใช้ฟังก์ชันจาก upload_config.php สำหรับลบไฟล์เมื่อเกิดข้อผิดพลาด
    if (isset($picture_filename) && $picture_filename) {
        deleteOldImage($picture_filename);
    }
    
    error_log("Create medication error: " . $e->getMessage());
    ResponseHelper::serverError('เกิดข้อผิดพลาดในการประมวลผล');
}
?>