<?php
/**
 * GET /medications/{id}/edit - ดึงข้อมูลยาสำหรับแก้ไข
 * ไฟล์: endpoints/medications/edit.php
 * 
 * แปลงจาก: get_medication_edit.php เป็น RESTful API
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


$auth_result = checkAuth();
$user_id = $auth_result['user_id'];


$medication_id = isset($_GET['medication_id']) ? intval($_GET['medication_id']) : 0;

if ($medication_id <= 0) {
    ResponseHelper::validationError('กรุณาระบุ medication_id ที่ถูกต้อง');
}

try {
    // ดึงข้อมูลยาหลัก (รวม unit_type_id)
    $stmt = $conn->prepare("
        SELECT 
            m.*,
            df.dosage_name as dosage_form,
            ut.unit_type_name as unit_type,
            c.user_id
        FROM medication m
        LEFT JOIN dosage_form df ON m.dosage_form_id = df.dosage_form_id
        LEFT JOIN unit_type ut ON m.unit_type_id = ut.unit_type_id
        LEFT JOIN connect c ON m.connect_id = c.connect_id
        WHERE m.medication_id = ? AND c.user_id = ?
    ");

    $stmt->execute([$medication_id, $user_id]);
    $medication = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$medication) {
        ResponseHelper::notFound('ไม่พบข้อมูลยา');
    }

    // ใช้ฟังก์ชันจาก upload_config.php แทนการ hardcode URL
    $medication['picture_url'] = getImageUrl($medication['picture']);

    // แปลง medication fields เป็น integer
    $medication['medication_id'] = (int)$medication['medication_id'];
    $medication['connect_id'] = (int)$medication['connect_id'];
    $medication['dosage_form_id'] = (int)$medication['dosage_form_id'];
    $medication['unit_type_id'] = (int)$medication['unit_type_id'];

    $medication['display_name'] = !empty($medication['medication_nickname']) && $medication['medication_nickname'] !== '-'
        ? $medication['medication_nickname']
        : $medication['medication_name'];

    // ดึงรูปแบบยาทั้งหมดที่มี
    $available_dosage_forms = [];
    $dosage_stmt = $conn->prepare("
        SELECT dosage_form_id, dosage_name 
        FROM dosage_form 
        ORDER BY dosage_form_id
    ");
    $dosage_stmt->execute();
    $available_dosage_forms = $dosage_stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($available_dosage_forms as &$form) {
        $form['dosage_form_id'] = (int)$form['dosage_form_id'];
    }

    // ดึงหน่วยยาทั้งหมด (ไม่จำกัดเฉพาะ dosage_form_id ปัจจุบัน)
    $all_unit_types = [];
    $all_unit_types_stmt = $conn->prepare("
        SELECT unit_type_id, unit_type_name, dosage_form_id
        FROM unit_type 
        ORDER BY dosage_form_id, unit_type_id
    ");
    $all_unit_types_stmt->execute();
    $all_unit_types = $all_unit_types_stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($all_unit_types as &$unit) {
        $unit['unit_type_id'] = (int)$unit['unit_type_id'];
        $unit['dosage_form_id'] = (int)$unit['dosage_form_id'];
    }

    // ดึงหน่วยยาตาม dosage_form_id ปัจจุบัน
    $available_unit_types = [];
    if (!empty($medication['dosage_form_id'])) {
        $unit_types_stmt = $conn->prepare("
            SELECT unit_type_id, unit_type_name, dosage_form_id
            FROM unit_type 
            WHERE dosage_form_id = ?
            ORDER BY unit_type_id
        ");
        $unit_types_stmt->execute([$medication['dosage_form_id']]);
        $available_unit_types = $unit_types_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($available_unit_types as &$unit) {
            $unit['unit_type_id'] = (int)$unit['unit_type_id'];
            $unit['dosage_form_id'] = (int)$unit['dosage_form_id'];
        }
    }

    $response_data = [
        'medication' => $medication,
        'available_dosage_forms' => $available_dosage_forms,
        'available_unit_types' => $available_unit_types,
        'all_unit_types' => $all_unit_types
    ];

    ResponseHelper::success($response_data, 'โหลดข้อมูลยาสำเร็จ');

} catch (Exception $e) {
    error_log("Get medication edit error: " . $e->getMessage());
    ResponseHelper::serverError('เกิดข้อผิดพลาดในการดึงข้อมูล');
}
?>