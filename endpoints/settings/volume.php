<?php
/**
 * PUT /settings/volume - อัพเดทการตั้งค่าเสียง
 * ไฟล์: endpoints/settings/volume.php
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

// ฟังก์ชันแปลงเวลา ให้รับได้ทั้ง int (วินาที) หรือ string "HH:MM:SS"
function parseTimeToString($time) {
    if (is_numeric($time)) {
        $time = (int)$time;
        if ($time < 0) return false;
        $hours = floor($time / 3600);
        $minutes = floor(($time % 3600) / 60);
        $seconds = $time % 60;
        return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
    }
    if (is_string($time) && preg_match('/^([0-1]?[0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/', $time)) {
        return $time;
    }
    return false;
}

// รับข้อมูล JSON
$data = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    ResponseHelper::validationError('ข้อมูล JSON ไม่ถูกต้อง');
}

try {
    //  ดึงข้อมูลปัจจุบันและตรวจสอบว่ามีข้อมูลอยู่หรือไม่
    $stmt = $conn->prepare("SELECT volume, delay, alert_offset FROM volume WHERE connect_id = ?");
    $stmt->execute([$connect_id]);
    $current_settings = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$current_settings) {
        ResponseHelper::notFound('ไม่พบข้อมูลการตั้งค่า กรุณาติดต่อผู้ดูแลระบบ');
    }

    // ตรวจสอบข้อมูลที่จำเป็นทั้งหมด
    if (!isset($data['volume']) || !isset($data['delay']) || !isset($data['alert_offset'])) {
        ResponseHelper::validationError('ต้องส่งข้อมูล volume, delay และ alert_offset ครบถ้วน');
    }

    // ตรวจสอบ volume
    $volume = (int)$data['volume'];
    if ($volume < 0 || $volume > 100) {
        ResponseHelper::validationError('ระดับเสียงต้องอยู่ระหว่าง 0-100');
    }

    // ตรวจสอบ delay
    $delay = parseTimeToString($data['delay']);
    if ($delay === false) {
        ResponseHelper::validationError('รูปแบบ delay ไม่ถูกต้อง (ต้องเป็น HH:MM:SS หรือจำนวนวินาที)');
    }

    // ตรวจสอบ alert_offset
    $alert_offset = parseTimeToString($data['alert_offset']);
    if ($alert_offset === false) {
        ResponseHelper::validationError('รูปแบบ alert_offset ไม่ถูกต้อง (ต้องเป็น HH:MM:SS หรือจำนวนวินาที)');
    }

    // เช็คว่าค่าใหม่เหมือนค่าเดิมหรือไม่
    $current_volume = (int)$current_settings['volume'];
    $current_delay = $current_settings['delay'];
    $current_alert_offset = $current_settings['alert_offset'];

    if ($volume === $current_volume && $delay === $current_delay && $alert_offset === $current_alert_offset) {
        //  ค่าเหมือนเดิม ไม่ต้องส่ง data กลับ
        ResponseHelper::success([], 'ข้อมูลไม่มีการเปลี่ยนแปลง', false);
        return;
    }

    //  อัพเดทข้อมูลทั้งหมดในครั้งเดียว
    $sql = "UPDATE volume SET volume = ?, delay = ?, alert_offset = ? WHERE connect_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$volume, $delay, $alert_offset, $connect_id]);

    // ดึงข้อมูลที่อัพเดทแล้ว
    $stmt = $conn->prepare("SELECT volume, delay, alert_offset FROM volume WHERE connect_id = ?");
    $stmt->execute([$connect_id]);
    $updated_settings = $stmt->fetch(PDO::FETCH_ASSOC);

    $response_data = [
        'volume_settings' => [
            'volume' => (int)$updated_settings['volume'],
            'delay' => $updated_settings['delay'],
            'alert_offset' => $updated_settings['alert_offset']
        ],
    ];

    ResponseHelper::success($response_data, 'อัพเดทการตั้งค่าเสียงสำเร็จ', false);

} catch (Exception $e) {
    error_log("Update volume settings error: " . $e->getMessage());
    ResponseHelper::serverError('เกิดข้อผิดพลาดในการอัพเดทการตั้งค่าเสียง');
}