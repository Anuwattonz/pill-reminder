<?php
/**
 * POST /auth/reset-password - รีเซ็ตรหัสผ่าน
 * ไฟล์: endpoints/auth/reset-password.php
 * 
 * ใช้หลังจากที่ verify OTP สำเร็จแล้ว
 */

require_once __DIR__ . '/../../config/api_headers.php';
require_once __DIR__ . '/../../config/db_connection.php';
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

// รับข้อมูล JSON
$data = json_decode(file_get_contents('php://input'), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    ResponseHelper::validationError('ข้อมูล JSON ไม่ถูกต้อง');
}

// ตรวจสอบข้อมูลที่จำเป็น
$required_fields = ['email', 'new_password'];
foreach ($required_fields as $field) {
    if (!isset($data[$field]) || !is_string($data[$field]) || empty(trim($data[$field]))) {
        ResponseHelper::validationError("ต้องระบุ $field");
    }
}

$email = trim($data['email']);
$new_password = $data['new_password'];

// ตรวจสอบรูปแบบอีเมล
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    ResponseHelper::validationError('รูปแบบอีเมลไม่ถูกต้อง');
}

// ตรวจสอบความยาวรหัสผ่าน (เหมือน register.php)
if (strlen($new_password) < 6) {
    ResponseHelper::validationError('รหัสผ่านต้องมีความยาวอย่างน้อย 6 ตัวอักษร');
}

try {
    $conn->beginTransaction();
    
    // ตรวจสอบว่าอีเมลมีอยู่ในระบบหรือไม่
    $stmt = $conn->prepare("SELECT user_id, user FROM user WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        $conn->rollBack();
        ResponseHelper::error('ไม่พบอีเมลในระบบ', 404);
    }
    
    $user_id = $user['user_id'];
    $username = $user['user'];
    
    // ตรวจสอบว่ามี OTP ที่ยังไม่หมดอายุสำหรับอีเมลนี้
    $stmt = $conn->prepare("
        SELECT user_otp_id, otp_code 
        FROM user_otp 
        WHERE user_id = ? AND expires_at > NOW()
        ORDER BY created_at DESC 
        LIMIT 1
    ");
    $stmt->execute([$user_id]);
    $otp_record = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$otp_record) {
        $conn->rollBack();
        ResponseHelper::error('OTP หมดอายุหรือไม่ถูกต้อง กรุณาขอ OTP ใหม่ก่อนรีเซ็ตรหัสผ่าน', 401);
    }
    
    // เข้ารหัสรหัสผ่านใหม่ (เหมือน register.php)
    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
    
    // อัพเดทรหัสผ่านในฐานข้อมูล
    $stmt = $conn->prepare("UPDATE user SET password = ? WHERE user_id = ?");
    $stmt->execute([$hashed_password, $user_id]);
    
    //  ลบ OTP หลังจากเปลี่ยนรหัสผ่านสำเร็จ
    $stmt = $conn->prepare("DELETE FROM user_otp WHERE user_id = ?");
    $stmt->execute([$user_id]);
    
    $conn->commit();
    
    ResponseHelper::success([
        'user_id' => (int)$user_id,
        'username' => $username,
        'email' => $email,
        'updated_at' => date('Y-m-d H:i:s'),
        'message' => 'รีเซ็ตรหัสผ่านสำเร็จ OTP ถูกลบออกจากระบบแล้ว'
    ], 'รีเซ็ตรหัสผ่านสำเร็จ กรุณาเข้าสู่ระบบด้วยรหัสผ่านใหม่');
    
} catch (PDOException $e) {
    $conn->rollBack();
    error_log("Reset Password DB Error: " . $e->getMessage());
    ResponseHelper::serverError('เกิดข้อผิดพลาดในการรีเซ็ตรหัสผ่าน');
} catch (Exception $e) {
    $conn->rollBack();
    error_log("Reset Password Error: " . $e->getMessage());
    ResponseHelper::serverError('เกิดข้อผิดพลาดในการรีเซ็ตรหัสผ่าน');
}
?>