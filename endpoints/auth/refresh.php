<?php
/**
 * POST /auth/refresh - รีเฟรช JWT token
 * ไฟล์: endpoints/auth/refresh.php
 * 
 * แปลงจาก: post_refresh.php
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

// รับข้อมูล JSON
$data = json_decode(file_get_contents('php://input'), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    ResponseHelper::validationError('ข้อมูล JSON ไม่ถูกต้อง');
}

if (!isset($data['refresh_token']) || empty($data['refresh_token'])) {
    ResponseHelper::validationError('กรุณาระบุ refresh token');
}

$refresh_token = $data['refresh_token'];

try {
    // ใช้ JWTHandler::refreshToken() ของระบบเดิม
    $refresh_result = JWTHandler::refreshToken($refresh_token);
    
    if (!$refresh_result['success']) {
        ResponseHelper::unauthorized($refresh_result['error']);
    }
    
    //  แก้ไข: ส่ง response ในรูปแบบที่ Flutter คาดหวัง
    $response_data = [
        'auto_login_status' => 'token_refreshed',
        'new_token' => $refresh_result['token'],
        'user' => $refresh_result['user_data']['user'],
        'has_connection' => $refresh_result['user_data']['has_connection'],
        'connect_id' => $refresh_result['user_data']['connect_id'] ?? '0',
        'connections' => $refresh_result['user_data']['connections'] ?? [],
        'expires_in' => $refresh_result['expires_in']
    ];
    
    ResponseHelper::success($response_data, 'รีเฟรช token สำเร็จ', false);
    
} catch (Exception $e) {
    error_log("Refresh token error: " . $e->getMessage());
    ResponseHelper::unauthorized('ไม่สามารถรีเฟรช token ได้');
}
?>