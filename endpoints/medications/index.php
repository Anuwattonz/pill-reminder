<?php
/**
 * GET /medications - ดึงรายการยาทั้งหมด
 * ไฟล์: endpoints/medications/index.php

 */

require_once __DIR__ . '/../../config/api_headers.php';
require_once __DIR__ . '/../../config/jwt_handler.php';
require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../../config/upload_config.php';
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

// ใช้ JWT handler แทน middleware
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
    $stmt = $conn->prepare("SELECT connect_id FROM connect WHERE connect_id = ? AND user_id = ?");
    $stmt->execute([$connect_id, $user_id]);

    $connect = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$connect) {
        $conn->rollBack();
        ResponseHelper::error('connect_id ไม่ถูกต้องหรือไม่ตรงกับผู้ใช้', 403);
    }

    //  ดึงรายการยาพร้อมข้อมูลเพิ่มเติม (dosage_form และ unit_type)
    $stmt = $conn->prepare("
        SELECT 
            m.medication_id,
            m.connect_id,
            m.medication_nickname,
            m.medication_name,
            m.description,
            m.picture,
            df.dosage_name as dosage_form,
            df.dosage_form_id,
            ut.unit_type_name as unit_type,
            ut.unit_type_id
        FROM medication m
        LEFT JOIN dosage_form df ON m.dosage_form_id = df.dosage_form_id
        LEFT JOIN unit_type ut ON m.unit_type_id = ut.unit_type_id
        WHERE m.connect_id = ?
        ORDER BY m.medication_id DESC
    ");
    $stmt->execute([$connect_id]);
    $medications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // เพิ่มข้อมูลเพิ่มเติมสำหรับแต่ละยา
    foreach ($medications as &$medication) {
        // แปลง ID เป็น integer
        $medication['medication_id'] = (int)$medication['medication_id'];
        $medication['connect_id'] = (int)$medication['connect_id'];
        $medication['dosage_form_id'] = (int)$medication['dosage_form_id'];
        $medication['unit_type_id'] = (int)$medication['unit_type_id'];

        // ใช้ฟังก์ชันจาก upload_config.php
        $medication['picture_url'] = getImageUrl($medication['picture']);
        
        // จัดการค่า null/empty
        $medication['medication_nickname'] = $medication['medication_nickname'] ?: '-';
        $medication['description'] = $medication['description'] ?: '-';
        $medication['dosage_form'] = $medication['dosage_form'] ?: 'ไม่ระบุ';
        $medication['unit_type'] = $medication['unit_type'] ?: 'เม็ด';
        
        $medication['display_name'] = !empty($medication['medication_nickname']) && $medication['medication_nickname'] !== '-'
            ? $medication['medication_nickname']
            : $medication['medication_name'];
    }

    // commit transaction
    $conn->commit();

    $message = count($medications) > 0 ? 'โหลดข้อมูลยาสำเร็จ' : 'ยังไม่มีข้อมูลยา';
    
    header('Content-Type: application/json');
    
    $response = [
        'status' => 'success',
        'message' => $message,
        'data' => $medications,  
        'total' => count($medications),
    ];

    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    // rollback transaction หากเกิดข้อผิดพลาด
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    
    error_log("Get medications error: " . $e->getMessage());
    ResponseHelper::serverError('เกิดข้อผิดพลาดในการประมวลผล');
}
?>