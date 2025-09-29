<?php
/**
 * POST /medications/{id} - อัปเดตข้อมูลยา
 * ไฟล์: endpoints/medications/update.php
 * 
 * แปลงจาก: post_medication_update.php เป็น RESTful API
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ResponseHelper::error('อนุญาตเฉพาะเมธอด POST เท่านั้น', 405);
}

// ตรวจสอบ JWT
$auth = checkAuth();
$user_id = $auth['user_id'];

// ดึง medication_id จาก POST
if (empty($_GET['medication_id']) || !is_numeric($_GET['medication_id'])) {
    ResponseHelper::validationError('ID ไม่ถูกต้อง');
}
$medication_id = (int) $_GET['medication_id'];

// ตรวจสอบค่าที่จำเป็น
$required = ['medication_name', 'dosage_form_id', 'unit_type_id'];
foreach ($required as $key) {
    if (empty($_POST[$key])) {
        ResponseHelper::validationError("กรุณาระบุ $key");
    }
}

$medication_name = trim($_POST['medication_name']);
$medication_nickname = trim($_POST['medication_nickname'] ?? '');
$description = trim($_POST['description'] ?? '');
$dosage_form_id = (int) $_POST['dosage_form_id'];
$unit_type_id = (int) $_POST['unit_type_id'];

// ตรวจสอบ compatibility ของ dosage_form และ unit_type
$stmt = $conn->prepare("
    SELECT ut.unit_type_id 
    FROM dosage_form df
    LEFT JOIN unit_type ut ON df.dosage_form_id = ut.dosage_form_id AND ut.unit_type_id = ?
    WHERE df.dosage_form_id = ?
");
$stmt->execute([$unit_type_id, $dosage_form_id]);
if (!$stmt->fetch()) {
    ResponseHelper::validationError('รูปแบบยาและหน่วยยาไม่ตรงกัน');
}

// ตรวจสอบว่ายานี้เป็นของผู้ใช้
$stmt = $conn->prepare("
    SELECT m.picture
    FROM medication m
    JOIN connect c ON m.connect_id = c.connect_id
    WHERE m.medication_id = ? AND c.user_id = ?
");
$stmt->execute([$medication_id, $user_id]);
$med = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$med) {
    ResponseHelper::notFound('ไม่พบยาหรือไม่มีสิทธิ์เข้าถึง');
}

$old_picture = $med['picture'];
$picture_filename = $old_picture;

try {
    $conn->beginTransaction();
    
    // ถ้ามีการอัปโหลดภาพ
    if (!empty($_FILES['picture']) && $_FILES['picture']['error'] === UPLOAD_ERR_OK) {
        $upload = handleImageUpload($_FILES['picture'], 'medication', $old_picture);
        if (!$upload['success']) {
            $conn->rollBack();
            ResponseHelper::validationError($upload['error']);
        }
        $picture_filename = $upload['filename'];
    }
    
    $stmt = $conn->prepare("
        UPDATE medication SET 
            medication_name = ?,
            medication_nickname = ?,
            description = ?,
            picture = ?,
            dosage_form_id = ?,
            unit_type_id = ?
        WHERE medication_id = ?
    ");
    $stmt->execute([
        $medication_name, $medication_nickname, $description,
        $picture_filename, $dosage_form_id, $unit_type_id, $medication_id
    ]);
    
    $conn->commit();
    
    ResponseHelper::success([
        'medication_id' => $medication_id,
        'medication_name' => $medication_name,
        'medication_nickname' => $medication_nickname,
        'description' => $description,
        'dosage_form_id' => $dosage_form_id,
        'unit_type_id' => $unit_type_id,
        'picture_filename' => $picture_filename,
        'picture_url' => getImageUrl($picture_filename, true),
    ], 'อัปเดตข้อมูลยาสำเร็จ');
    
} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    if ($picture_filename !== $old_picture) {
        deleteOldImage($picture_filename);
    }
    error_log('Update medication error: ' . $e->getMessage());
    ResponseHelper::serverError('เกิดข้อผิดพลาดในการอัปเดต');
}
?>