<?php
/**
 * GET /history/{reminder_medical_id} - ดึงรายละเอียดประวัติการทานยา (Secure)
 * ไฟล์: endpoints/history/detail.php
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

// ดึง history_id จาก $_GET ที่ Router ส่งมา
$reminder_medical_id = isset($_GET['history_id']) ? intval($_GET['history_id']) : 0;

if ($reminder_medical_id <= 0) {
    ResponseHelper::validationError('history_id ไม่ถูกต้องหรือไม่ระบุ');
}

// ตรวจสอบ JWT
$auth_result = checkAuth();
$user_id = $auth_result['user_id'];
$connect_id = $auth_result['connect_id'];

if (!$connect_id) {
    ResponseHelper::error('ต้องเชื่อมต่อกับอุปกรณ์ก่อน', 403);
}

try {
    $conn->beginTransaction();

    // ดึงข้อมูล reminder_medication พร้อมตรวจสอบ ownership
    $stmt = $conn->prepare("
        SELECT 
            rm.reminder_medical_id,
            rm.connect_id,
            rm.day,
            rm.receive_time,
            rm.time,
            rm.status,
            rm.timing_id,
            rm.picture,
            t.timing as timing_name
        FROM reminder_medication rm
        LEFT JOIN timing t ON rm.timing_id = t.timing_id
        JOIN connect c ON rm.connect_id = c.connect_id
        WHERE rm.reminder_medical_id = ? AND c.user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$reminder_medical_id, $user_id]);
    $reminder = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$reminder) {
        $conn->rollBack();
        ResponseHelper::error('ไม่พบข้อมูลประวัติการแจ้งเตือนหรือไม่มีสิทธิ์เข้าถึง', 404);
    }

    // เพิ่ม picture_url
    $reminder['picture_url'] = getPictureUrl($reminder['picture']);

    // แปลง ID เป็น integer
    $reminder['reminder_medical_id'] = (int)$reminder['reminder_medical_id'];
    $reminder['connect_id'] = (int)$reminder['connect_id'];
    $reminder['timing_id'] = $reminder['timing_id'] ? (int)$reminder['timing_id'] : null;

    // ดึง medication_snapshot (ไม่ต้อง JOIN ซ้ำซ้อน เพราะได้เช็ค ownership แล้ว)
    $stmt = $conn->prepare("
        SELECT 
            medication_snapshot_id,
            medication_name,
            amount_taken
        FROM medication_snapshot
        WHERE reminder_medical_id = ?
        ORDER BY medication_snapshot_id
    ");
    $stmt->execute([$reminder_medical_id]);
    $medications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // แปลงข้อมูล
    foreach ($medications as &$medication) {
        $medication['medication_snapshot_id'] = (int)$medication['medication_snapshot_id'];
        $medication['amount_taken'] = $medication['amount_taken'] ? (string)$medication['amount_taken'] : '0';
    }

    $conn->commit();

    // ส่ง response
    $response_data = [
        'history_detail' => $reminder,
        'medications' => $medications
    ];

    ResponseHelper::success($response_data, 'ดึงรายละเอียดประวัติการแจ้งเตือนสำเร็จ', false);

} catch (PDOException $e) {
    $conn->rollBack();
    error_log("Database error: " . $e->getMessage());
    ResponseHelper::serverError('เกิดข้อผิดพลาดในการดึงข้อมูล');
} catch (Exception $e) {
    $conn->rollBack();
    error_log("General error: " . $e->getMessage());
    ResponseHelper::serverError('เกิดข้อผิดพลาดในการประมวลผล');
}
?>