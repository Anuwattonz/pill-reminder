<?php
/**
 * GET /reminders - ดึงข้อมูล reminder ทั้งหมด
 * ไฟล์: endpoints/reminders/index.php

 */

require_once __DIR__ . '/../../config/api_headers.php';
require_once __DIR__ . '/../../config/jwt_handler.php';
require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../../helpers/ResponseHelper.php';

setApiHeaders();
handleOptions();

$conn = getConnection();

if (!$conn) {
    ResponseHelper::serverError('การเชื่อมต่อฐานข้อมูลล้มเหลว');
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ResponseHelper::error('อนุญาตเฉพาะเมธอด GET เท่านั้น', 405);
}


$auth_result = checkAuth();
$user_id = $auth_result['user_id'];
$connect_id = $auth_result['connect_id'];

// ตรวจสอบข้อมูลจาก JWT
if ($user_id <= 0 || $connect_id <= 0) {
    ResponseHelper::validationError('ข้อมูลใน JWT ไม่ถูกต้อง');
}

try {
    // เริ่มต้น transaction
    $conn->beginTransaction();
    
    // ตรวจสอบว่า connect_id นี้เป็นของ user นี้จริงหรือไม่
    $stmt = $conn->prepare("
        SELECT connect_id 
        FROM connect
        WHERE connect_id = ? AND user_id = ?
    ");
    $stmt->execute([$connect_id, $user_id]);

    $connect = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$connect) {
        $conn->rollBack();
        ResponseHelper::error('connect_id ไม่ถูกต้องหรือไม่ตรงกับผู้ใช้', 403);
    }

    // ดึงข้อมูลจาก app พร้อม pill_slot
    $stmt = $conn->prepare("
        SELECT a.app_id, a.status, a.timing, a.pill_slot
        FROM app a
        WHERE a.connect_id = ?
        ORDER BY a.pill_slot
    ");
    $stmt->execute([$connect_id]);
    $apps = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ดึงข้อมูลวันของแต่ละ app และ medication_links ของแต่ละ app
    foreach ($apps as &$app) {
        // แปลง ID เป็น integer
        $app['app_id'] = (int)$app['app_id'];
        $app['pill_slot'] = (int)$app['pill_slot'];
        
        // ดึงวันในสัปดาห์
        $stmt = $conn->prepare("
            SELECT sunday, monday, tuesday, wednesday, thursday, friday, saturday 
            FROM day_app 
            WHERE app_id = ?
        ");
        $stmt->execute([$app['app_id']]);
        $days_result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // กำหนดค่าเริ่มต้นถ้าไม่มีข้อมูล
        if (!$days_result) {
            $app['days'] = [
                'sunday' => '0',
                'monday' => '0', 
                'tuesday' => '0',
                'wednesday' => '0',
                'thursday' => '0',
                'friday' => '0',
                'saturday' => '0'
            ];
        } else {
            $app['days'] = $days_result;
        }

        // แก้ไข: ดึงแค่ medication_link_id อย่างเดียว 
        $stmt = $conn->prepare("
            SELECT ml.medication_link_id
            FROM medication_link ml
            WHERE ml.app_id = ?
        ");
        $stmt->execute([$app['app_id']]);
        $medication_links = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // แปลง medication_link_id เป็น integer
        foreach ($medication_links as &$link) {
            $link['medication_link_id'] = (int)$link['medication_link_id'];
        }
        
        $app['medication_links'] = $medication_links;
    }

    // commit transaction
    $conn->commit();

    $response_data = [
        'connect_id' => (int)$connect_id,
        'apps' => $apps
    ];

    ResponseHelper::success($response_data, 'โหลดข้อมูล reminder สำเร็จ');

} catch (Exception $e) {
    // rollback transaction หากเกิดข้อผิดพลาด
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    
    error_log("Get reminder error: " . $e->getMessage());
    ResponseHelper::serverError('เกิดข้อผิดพลาดในการประมวลผล');
}
?>