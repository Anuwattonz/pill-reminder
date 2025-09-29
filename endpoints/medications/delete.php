<?php
/**
 * DELETE /medications/{id} - ลบยา
 * ไฟล์: endpoints/medications/delete.php
 * 
 * ✅ RESTful API style เหมือนหน้าอื่นในระบบ
 */

require_once __DIR__ . '/../../config/api_headers.php';
require_once __DIR__ . '/../../config/jwt_handler.php';
require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../../config/upload_config.php';
require_once __DIR__ . '/../../helpers/ResponseHelper.php';

setApiHeaders();
handleOptions();

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    ResponseHelper::error('อนุญาตเฉพาะเมธอด DELETE เท่านั้น', 405);
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

//  ดึง medication_id จาก URL path (RESTful style)
$medication_id = $_GET['medication_id'] ?? null;

if (!$medication_id || !is_numeric($medication_id)) {
    ResponseHelper::validationError('กรุณาระบุ medication ID ที่ถูกต้อง');
}

$medication_id = (int)$medication_id;

//  ดึง request body สำหรับ force_delete parameter
$input = json_decode(file_get_contents('php://input'), true);
$force_delete = isset($input['force_delete']) ? (bool)$input['force_delete'] : false;

try {
    error_log("Delete medication ID: $medication_id for user: $user_id, force: " . ($force_delete ? 'true' : 'false'));
    
    $conn->beginTransaction();
    
    //  ตรวจสอบว่ายาเป็นของผู้ใช้คนนี้หรือไม่ 
    $stmt = $conn->prepare("
        SELECT m.medication_id, m.medication_name, m.picture, c.user_id 
        FROM medication m
        JOIN connect c ON m.connect_id = c.connect_id
        WHERE m.medication_id = ? AND c.user_id = ?
    ");
    $stmt->execute([$medication_id, $user_id]);
    $medication = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$medication) {
        $conn->rollBack();
        ResponseHelper::notFound('ไม่พบยาหรือไม่มีสิทธิ์เข้าถึง');
    }
    
    error_log("Medication found: " . $medication['medication_name']);

    $reminderCheck = checkMedicationUsageInReminder($conn, $medication_id, $user_id);
    
    error_log("Has reminders: " . ($reminderCheck['hasReminders'] ? 'YES' : 'NO'));
    error_log("Reminder count: " . $reminderCheck['reminder_count']);
 
    //  ถ้ามีการใช้งานใน reminder และไม่ได้บังคับลบ
        if ($reminderCheck['hasReminders'] && !$force_delete) {
            $conn->rollBack();
            
            error_log("Sending 409 - has reminders but not force delete");
            
            // แก้ไข: ส่งกลับ 409 Conflict พร้อมข้อมูลครบถ้วน โดยใช้ custom response
            setApiHeaders();
            http_response_code(409);
            echo json_encode([
                'status' => 'error',
                'message' => 'ยานี้มีการตั้งแจ้งเตือนอยู่ ต้องการลบใช่หรือไม่?',
                'data' => [
                    'medication_id' => $medication_id,
                    'medication_name' => $medication['medication_name'],
                    'reminder_count' => $reminderCheck['reminder_count'],
                    'reminders' => array_map(function($reminder) {
                        return [
                            'app_id' => (int)$reminder['app_id'],
                            'timing' => $reminder['timing'],
                            'timing_name' => $reminder['timing_name'] ?? 'ไม่ระบุ',
                            'pill_slot' => (int)$reminder['pill_slot']
                        ];
                    }, $reminderCheck['reminders'])
                ]
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    
    error_log("Proceeding with delete...");
    
    //ถ้าบังคับลบและมี reminder  ปิด app ที่ใช้ยานี้
    if ($force_delete && $reminderCheck['hasReminders']) {
        $app_ids = array_unique(array_map(function($reminder) {
            return $reminder['app_id'];
        }, $reminderCheck['reminders']));
        
        if (!empty($app_ids)) {
            $placeholders = str_repeat('?,', count($app_ids) - 1) . '?';
            $stmt = $conn->prepare("UPDATE app SET status = 0 WHERE app_id IN ($placeholders)");
            $stmt->execute($app_ids);
            error_log("Disabled " . count($app_ids) . " reminder apps");
        }
    }
    
    // ลบข้อมูลที่เกี่ยวข้องทั้งหมด 
    
    // 1. ลบ medication_link
    $stmt = $conn->prepare("DELETE FROM medication_link WHERE medication_id = ?");
    $stmt->execute([$medication_id]);
    error_log("Deleted medication_link records");
    
    // 2. ลบ medication_timing  
    $stmt = $conn->prepare("DELETE FROM medication_timing WHERE medication_id = ?");
    $stmt->execute([$medication_id]);
    error_log("Deleted medication_timing records");
    
    // 3. ลบ medication_snapshot
    $stmt = $conn->prepare("DELETE FROM medication_snapshot WHERE medication_name = ?");
    $stmt->execute([$medication['medication_name']]);
    error_log("Deleted medication_snapshot records");
    
    // 4. ลบรูปภาพ (ถ้ามี)
    if (!empty($medication['picture'])) {
        deleteOldImage($medication['picture']);
        error_log("Deleted medication image: " . $medication['picture']);
    }
    
    // 5. ลบตัวยาหลัก
    $stmt = $conn->prepare("DELETE FROM medication WHERE medication_id = ?");
    $stmt->execute([$medication_id]);
    error_log("Deleted main medication record");
    
    $conn->commit();
    error_log("Transaction committed successfully");
    
    $response_data = [
        'medication_id' => $medication_id,
        'medication_name' => $medication['medication_name'],
   ];
    
    $message = 'ลบยาสำเร็จ';
    if ($force_delete && $reminderCheck['hasReminders']) {
        $disabled_count = count(array_unique(array_map(function($r) { return $r['app_id']; }, $reminderCheck['reminders'])));
        $message .= ' และปิดการใช้งาน ' . $disabled_count . ' การแจ้งเตือน';
    }
    
    ResponseHelper::success($response_data, $message, false);
    
} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    
    error_log("Delete medication error: " . $e->getMessage());
    error_log("Delete medication trace: " . $e->getTraceAsString());
    ResponseHelper::serverError('เกิดข้อผิดพลาดในการลบยา');
}

/**
 * ฟังก์ชันเช็คการใช้งานของยา
 */
function checkMedicationUsageInReminder($conn, $medication_id, $user_id) {
    try {
        $stmt = $conn->prepare("
            SELECT 
                a.app_id,
                a.timing,
                a.status,
                a.pill_slot,
                t.timing as timing_name,
                COUNT(ml.medication_link_id) as medication_count
            FROM app a
            JOIN connect c ON a.connect_id = c.connect_id
            LEFT JOIN medication_link ml ON a.app_id = ml.app_id AND ml.medication_id = ?
            LEFT JOIN timing t ON a.pill_slot = t.timing_id
            WHERE c.user_id = ? AND a.status = 1
            GROUP BY a.app_id, a.timing, a.status, a.pill_slot, t.timing
            HAVING medication_count > 0
            ORDER BY a.app_id
        ");
        $stmt->execute([$medication_id, $user_id]);
        $reminders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $hasReminders = count($reminders) > 0;

        return [
            'hasReminders' => $hasReminders,
            'reminder_count' => count($reminders),
            'reminders' => $reminders
        ];

    } catch (Exception $e) {
        error_log("Check medication usage error: " . $e->getMessage());
        return [
            'hasReminders' => false,
            'reminder_count' => 0,
            'reminders' => []
        ];
    }
}
?>