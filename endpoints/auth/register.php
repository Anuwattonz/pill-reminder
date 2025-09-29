<?php
/**
 * POST /auth/register - สมัครสมาชิก
 * ไฟล์: endpoints/auth/register.php
 * 
 * 
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

// ฟังก์ชันตรวจสอบอีเมล
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

// รับข้อมูล JSON
$data = json_decode(file_get_contents('php://input'), true);

// ตรวจสอบข้อมูลพื้นฐาน
if (
    !isset($data['user']) ||
    !isset($data['email']) ||
    !isset($data['password']) ||
    !is_string($data['user']) ||
    !is_string($data['email']) ||
    !is_string($data['password'])
) {
    ResponseHelper::validationError('ต้องระบุชื่อผู้ใช้ อีเมล และรหัสผ่าน');
}

$username = trim($data['user']);
$email = trim($data['email']);
$password = trim($data['password']);

// ตรวจสอบชื่อผู้ใช้
if (strlen($username) < 3 || strlen($username) > 50) {
    ResponseHelper::validationError('ชื่อผู้ใช้ต้องมีความยาวระหว่าง 3 ถึง 50 ตัวอักษร');
}

// ทำความสะอาด username (ป้องกัน XSS)
$cleaned_username = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
$cleaned_username = strip_tags($cleaned_username);
$cleaned_username = preg_replace('/[<>"\']/', '', $cleaned_username);

if (empty(trim($cleaned_username))) {
    ResponseHelper::validationError('ชื่อผู้ใช้มีอักขระที่ไม่ถูกต้อง');
}

// ตรวจสอบอีเมล
if (!validateEmail($email)) {
    ResponseHelper::validationError('รูปแบบอีเมลไม่ถูกต้อง');
}

// ตรวจสอบรหัสผ่าน
if (empty($password)) {
    ResponseHelper::validationError('กรุณาใส่รหัสผ่าน');
}

if (strlen($password) < 6) {
    ResponseHelper::validationError('รหัสผ่านต้องมีความยาวอย่างน้อย 6 ตัวอักษร');
}

if (ctype_space($password)) {
    ResponseHelper::validationError('รหัสผ่านไม่สามารถเป็นช่องว่างทั้งหมดได้');
}

try {
    $conn->beginTransaction();

    // ตรวจสอบอีเมลซ้ำ
    $stmt = $conn->prepare("SELECT user_id FROM user WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->rowCount() > 0) {
        $conn->rollBack();
        ResponseHelper::validationError('อีเมลนี้ถูกใช้ไปแล้ว');
    }

    // เข้ารหัสรหัสผ่านและเพิ่มข้อมูล
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT INTO user (user, email, password) VALUES (?, ?, ?)");
    $stmt->execute([$cleaned_username, $email, $hashedPassword]);

    $user_id = $conn->lastInsertId();
    $conn->commit();

    $response_data = [
        'user_id' => (int)$user_id,
        'username' => $cleaned_username,
        'email' => $email,
        'message' => 'ลงทะเบียนสำเร็จ กรุณาเข้าสู่ระบบ'
    ];

    ResponseHelper::success($response_data, 'ลงทะเบียนสำเร็จ', false);

} catch (PDOException $e) {
    $conn->rollBack();
    error_log("DB Error (Register): " . $e->getMessage());
    ResponseHelper::serverError('เกิดข้อผิดพลาดในการลงทะเบียน');
} catch (Exception $e) {
    $conn->rollBack();
    error_log("Register Error: " . $e->getMessage());
    ResponseHelper::serverError('เกิดข้อผิดพลาดในการลงทะเบียน');
}
?>