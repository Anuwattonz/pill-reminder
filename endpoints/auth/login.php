<?php
/**
 * POST /auth/login - เข้าสู่ระบบ (Basic Security)
 * ไฟล์: endpoints/auth/login.php
 */

require_once __DIR__ . '/../../config/api_headers.php';
require_once __DIR__ . '/../../config/jwt_handler.php';
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

$data = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE || 
    !isset($data['email']) || !isset($data['password'])) {
    ResponseHelper::validationError('ข้อมูลไม่ครบถ้วนหรือ JSON ไม่ถูกต้อง');
}

$email = trim($data['email']);
$password = trim($data['password']);

// ตรวจสอบข้อมูลพื้นฐาน
if (empty($password)) {
    ResponseHelper::validationError('กรุณาใส่รหัสผ่าน');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    ResponseHelper::validationError('รูปแบบอีเมลไม่ถูกต้อง');
}

try {
    $conn->beginTransaction();
    
    // ค้นหาจาก email เท่านั้น
    $stmt = $conn->prepare("SELECT user_id, user, password FROM user WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);

    if ($stmt->rowCount() === 0) {
        $conn->rollBack();
        ResponseHelper::error('อีเมลหรือรหัสผ่านไม่ถูกต้อง', 401);
    }

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!password_verify($password, $user['password'])) {
        $conn->rollBack();
        ResponseHelper::error('อีเมลหรือรหัสผ่านไม่ถูกต้อง', 401);
    }

    unset($user['password']);

    // ตรวจสอบว่ามี connect ไหม
    $stmt = $conn->prepare("SELECT connect_id FROM connect WHERE user_id = ?");
    $stmt->execute([$user['user_id']]);
    $connections = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $has_connection = count($connections) > 0;
    $connect_id = $has_connection ? $connections[0]['connect_id'] : null;

    // สร้าง JWT Token 
    $jwt_token = JWTHandler::createToken($user['user_id'], $connect_id);
    $refresh_token = JWTHandler::createRefreshToken($user['user_id'], $connect_id);
    
    $conn->commit();

    // ดึงการตั้งค่า expiration
    $config = ConfigLoader::getJwtConfig();
    $jwt_expiration = $config['expiration'];

    $response_data = [
        'token' => $jwt_token,
        'refresh_token' => $refresh_token,
        'user' => $user,
        'has_connection' => $has_connection,
        'expires_in' => $jwt_expiration
    ];

    ResponseHelper::success($response_data, 'เข้าสู่ระบบสำเร็จ', false);

} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    
    error_log("Login error: " . $e->getMessage());
    ResponseHelper::serverError('เกิดข้อผิดพลาดในการเข้าสู่ระบบ');
}
?>