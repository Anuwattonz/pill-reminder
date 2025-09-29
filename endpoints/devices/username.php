<?php
/**
 * PUT /devices/username - เปลี่ยนชื่อผู้ใช้ (Basic Security)
 * ไฟล์: endpoints/devices/username.php
 * 
 * แก้ไขแล้ว: เพิ่ม validation พื้นฐาน
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
$input = file_get_contents('php://input');
if (empty($input)) {
    ResponseHelper::validationError('ไม่พบข้อมูลในคำร้อง');
}

$data = json_decode($input, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    ResponseHelper::validationError('ข้อมูล JSON ไม่ถูกต้อง: ' . json_last_error_msg());
}

$new_username = isset($data['username']) ? trim($data['username']) : '';

// ตรวจสอบข้อมูลพื้นฐาน
if (empty($new_username)) {
    ResponseHelper::validationError('กรุณาใส่ชื่อผู้ใช้');
}

if (strlen($new_username) < 2 || strlen($new_username) > 50) {
    ResponseHelper::validationError('ชื่อผู้ใช้ต้องมีความยาวระหว่าง 2-50 ตัวอักษร');
}

// ทำความสะอาด username (ป้องกัน XSS)
$cleaned_username = htmlspecialchars($new_username, ENT_QUOTES, 'UTF-8');
$cleaned_username = strip_tags($cleaned_username);

if (empty(trim($cleaned_username))) {
    ResponseHelper::validationError('ชื่อผู้ใช้มีอักขระที่ไม่ถูกต้อง');
}

try {
    // ตรวจสอบว่า user_id มีอยู่จริงหรือไม่
    $stmt = $conn->prepare("SELECT user_id, user FROM user WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        ResponseHelper::notFound('ไม่พบผู้ใช้ในระบบ');
    }
    
    $conn->beginTransaction();
    
    // ใช้ username ที่ทำความสะอาดแล้ว
    $stmt = $conn->prepare("UPDATE user SET user = ? WHERE user_id = ?");
    $success = $stmt->execute([$cleaned_username, $user_id]);
    
    if (!$success) {
        $conn->rollBack();
        ResponseHelper::serverError('ไม่สามารถอัพเดทชื่อผู้ใช้ได้');
    }
    
    $conn->commit();
    
    ResponseHelper::success([], 'อัพเดทชื่อผู้ใช้เรียบร้อยแล้ว', false);
    
} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    
    error_log("Update username error: " . $e->getMessage());
    ResponseHelper::serverError('เกิดข้อผิดพลาดในการอัพเดทชื่อผู้ใช้');
}
?>