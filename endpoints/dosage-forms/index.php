<?php
require_once __DIR__ . '/../../config/api_headers.php';
require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../../config/jwt_handler.php';
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

$auth_result = checkAuth();
if (!$auth_result['success']) {
    ResponseHelper::unauthorized($auth_result['error']);
}

$user_id = $auth_result['user_id'] ?? null;
$connect_id = $auth_result['connect_id'] ?? null;

if (!$user_id) {
    ResponseHelper::unauthorized('ไม่พบข้อมูลผู้ใช้ใน token');
}

if (!$connect_id) {
    ResponseHelper::error('ต้องเชื่อมต่อกับอุปกรณ์ก่อน', 403);
}

try {
    // ดึงข้อมูล dosage forms
    $stmt = $conn->prepare("SELECT dosage_form_id, dosage_name FROM dosage_form ORDER BY dosage_form_id ASC");
    $stmt->execute();
    $dosageForms = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($dosageForms)) {
        ResponseHelper::success([], 'ไม่พบข้อมูลรูปแบบยา');
    }

    // ดึงข้อมูล unit types
    $stmtUnit = $conn->prepare("SELECT unit_type_id, unit_type_name, dosage_form_id FROM unit_type ORDER BY unit_type_id ASC");
    $stmtUnit->execute();
    $unitTypes = $stmtUnit->fetchAll(PDO::FETCH_ASSOC);

    // 🧪 DEBUG: log ดูว่าดึงได้จริงไหม
    error_log("DEBUG: unitTypes count = " . count($unitTypes));

    // รวม unit types ตาม dosage_form_id
    $unitsGrouped = [];
    foreach ($unitTypes as $unit) {
        $dfId = $unit['dosage_form_id'];
        if (!isset($unitsGrouped[$dfId])) {
            $unitsGrouped[$dfId] = [];
        }
        $unitsGrouped[$dfId][] = [
            'unit_type_id' => (int)$unit['unit_type_id'],
            'unit_type_name' => $unit['unit_type_name'],
        ];
    }

    // ผูก unit_types เข้ากับ dosage_form
    foreach ($dosageForms as &$form) {
        $dfId = $form['dosage_form_id'];
        $form['dosage_form_id'] = (int)$dfId;
        $form['dosage_name'] = $form['dosage_name'];
        $form['unit_types'] = $unitsGrouped[$dfId] ?? [];
    }

    ResponseHelper::success($dosageForms, 'ดึงรายการรูปแบบยาและหน่วยยาสำเร็จ');

} catch (Exception $e) {
    error_log("Get dosage forms error: " . $e->getMessage());
    ResponseHelper::serverError('เกิดข้อผิดพลาดในการดึงข้อมูล');
}
