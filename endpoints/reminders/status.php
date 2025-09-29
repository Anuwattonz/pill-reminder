<?php
/**
 * PUT /reminders/status - อัพเดทสถานะ reminder
 * ไฟล์: endpoints/reminders/status.php

 */

require_once __DIR__ . '/../../config/api_headers.php';
require_once __DIR__ . '/../../config/jwt_handler.php';
require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../../helpers/ResponseHelper.php';

setApiHeaders();
handleOptions();

if ($_SERVER['REQUEST_METHOD'] !== 'PUT' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    ResponseHelper::error('อนุญาตเฉพาะเมธอด PUT หรือ POST เท่านั้น', 405);
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

// รับข้อมูล JSON
$data = json_decode(file_get_contents('php://input'), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    ResponseHelper::validationError('ข้อมูล JSON ไม่ถูกต้อง');
}

// ตรวจสอบข้อมูลที่จำเป็น
if (!isset($data['pill_slot']) || !isset($data['status'])) {
    ResponseHelper::validationError('ต้องระบุ pill_slot และ status');
}

$pill_slot = (int)$data['pill_slot'];
$new_status = $data['status'];

// ตรวจสอบค่า pill_slot
if ($pill_slot < 1 || $pill_slot > 7) {
    ResponseHelper::validationError('pill_slot ต้องอยู่ระหว่าง 1-7');
}

// ตรวจสอบค่า status
if (!in_array($new_status, [0, 1, '0', '1', false, true])) {
    ResponseHelper::validationError('status ต้องเป็น 0 หรือ 1 เท่านั้น');
}

// แปลงเป็น integer
$new_status = $new_status ? 1 : 0;

try {
    $conn->beginTransaction();
    
    // ตรวจสอบว่า app slot นี้เป็นของผู้ใช้คนนี้หรือไม่
    $stmt = $conn->prepare("
        SELECT a.app_id, a.status as current_status
        FROM app a
        JOIN connect c ON a.connect_id = c.connect_id
        WHERE a.pill_slot = ? AND c.connect_id = ? AND c.user_id = ?
    ");
    $stmt->execute([$pill_slot, $connect_id, $user_id]);
    $app = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$app) {
        $conn->rollBack();
        ResponseHelper::notFound('ไม่พบ app slot ที่ระบุหรือไม่มีสิทธิ์เข้าถึง');
    }
    
    $app_id = $app['app_id'];
    $current_status = (int)$app['current_status'];
    
    // ถ้าต้องการเปิดใช้งาน (status = 1) ต้องตรวจสอบเงื่อนไข
    if ($new_status === 1) {
        // 1. ตรวจสอบว่ามียาหรือไม่
        $stmt = $conn->prepare("SELECT COUNT(*) as med_count FROM medication_link WHERE app_id = ?");
        $stmt->execute([$app_id]);
        $med_result = $stmt->fetch(PDO::FETCH_ASSOC);
        $has_medication = $med_result['med_count'] > 0;
        
        // 2. ตรวจสอบว่ามีวันที่เปิดใช้งานหรือไม่
        $stmt = $conn->prepare("
            SELECT sunday, monday, tuesday, wednesday, thursday, friday, saturday 
            FROM day_app 
            WHERE app_id = ?
        ");
        $stmt->execute([$app_id]);
        $days = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $has_active_day = false;
        if ($days) {
            $day_columns = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
            foreach ($day_columns as $day) {
                if ($days[$day] == '1') {
                    $has_active_day = true;
                    break;
                }
            }
        }
        
        // ถ้าไม่ผ่านเงื่อนไข ไม่อนุญาตให้เปิด
        if (!$has_medication || !$has_active_day) {
            $conn->rollBack();
            
            $error_reasons = [];
            if (!$has_medication) $error_reasons[] = 'ยังไม่มีข้อมูลยาในช่องนี้';
            if (!$has_active_day) $error_reasons[] = 'ยังไม่ได้ตั้งวันที่ใช้งาน';
            
            ResponseHelper::validationError(
                'ไม่สามารถเปิดใช้งานได้: ' . implode(' และ ', $error_reasons),
                [
                    'can_activate' => false,
                    'requirements' => [
                        'has_medication' => $has_medication,
                        'has_active_day' => $has_active_day
                    ],
                    'pill_slot' => $pill_slot,
                    'current_status' => $current_status
                ]
            );
        }
    }
    
    // อัพเดทสถานะ
    $stmt = $conn->prepare("UPDATE app SET status = ? WHERE app_id = ?");
    $stmt->execute([$new_status, $app_id]);
    
    if ($stmt->rowCount() === 0) {
        $conn->rollBack();
        ResponseHelper::serverError('ไม่สามารถอัพเดทสถานะได้');
    }
    
    $conn->commit();
    
    // เพิ่มข้อมูลสถานะ
    $status_text = [
        0 => 'ปิดใช้งาน',
        1 => 'เปิดใช้งาน'
    ];
    
    $response_data = [
        'app_id' => $app_id,
        'pill_slot' => $pill_slot,
        'new_status' => $new_status,
        'status_text' => $status_text[$new_status],
        'status_changed' => $current_status !== $new_status
    ];
    
    ResponseHelper::success($response_data, 'อัพเดทสถานะ reminder สำเร็จ', false);
    
} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    
    error_log("Update app status error: " . $e->getMessage());
    ResponseHelper::serverError('เกิดข้อผิดพลาดในการอัพเดทสถานะ');
}
?>