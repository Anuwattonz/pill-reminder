<?php
require_once __DIR__ . '/../../config/api_headers.php';
require_once __DIR__ . '/../../config/jwt_handler.php';
require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../../helpers/ResponseHelper.php';

setApiHeaders();
handleOptions();

if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    ResponseHelper::error('อนุญาตเฉพาะเมธอด PUT เท่านั้น', 405);
}

$conn = getConnection();
if (!$conn) {
    ResponseHelper::serverError('การเชื่อมต่อฐานข้อมูลล้มเหลว');
}

// ตรวจสอบ JWT และดึง user_id, connect_id
$auth_result = checkAuth();
$user_id = $auth_result['user_id'];
$connect_id = $auth_result['connect_id'];


$app_id = isset($_GET['app_id']) ? (int)$_GET['app_id'] : 0;
if ($app_id <= 0) {
    ResponseHelper::validationError('ต้องระบุ app_id ใน URL');
}

// ฟังก์ชันตรวจสอบ ownership
function verifyAppOwnership($conn, $app_id, $user_id) {
    $stmt = $conn->prepare("
        SELECT a.app_id
        FROM app a
        JOIN connect c ON a.connect_id = c.connect_id
        WHERE a.app_id = ? AND c.user_id = ?
    ");
    $stmt->execute([$app_id, $user_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
}

if (!verifyAppOwnership($conn, $app_id, $user_id)) {
    ResponseHelper::error('ไม่มีสิทธิ์เข้าถึง app นี้', 403);
}

// รับ JSON body
$input = file_get_contents('php://input');
$data = json_decode($input, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    ResponseHelper::validationError('ข้อมูล JSON ไม่ถูกต้อง');
}

try {
    $conn->beginTransaction();

    // ตรวจสอบความครบถ้วนของข้อมูล
    $has_medications = isset($data['medications']) && !empty($data['medications']);
    $has_selected_days = false;
    if (isset($data['days'])) {
        $days = $data['days'];
        $has_selected_days = (
            (!empty($days['sunday'])) ||
            (!empty($days['monday'])) ||
            (!empty($days['tuesday'])) ||
            (!empty($days['wednesday'])) ||
            (!empty($days['thursday'])) ||
            (!empty($days['friday'])) ||
            (!empty($days['saturday']))
        );
    }

    $status = ($has_medications && $has_selected_days) ? 1 : 0;

    // อัพเดต timing, pill_slot และ status
    if (isset($data['timing'])) {
        $timing = trim($data['timing']);
        $timing_id = isset($data['timing_id']) ? (int)$data['timing_id'] : null;

        $stmt = $conn->prepare("UPDATE app SET timing = ?, status = ? WHERE app_id = ?");
        $stmt->execute([$timing, $status, $app_id]);

        if ($timing_id && $timing_id > 0) {
            $stmt = $conn->prepare("UPDATE app SET pill_slot = ? WHERE app_id = ?");
            $stmt->execute([$timing_id, $app_id]);
        }
    } else {
        // ถ้าไม่มี timing ให้อัพเดต status เท่านั้น
        $stmt = $conn->prepare("UPDATE app SET status = ? WHERE app_id = ?");
        $stmt->execute([$status, $app_id]);
    }

    // อัพเดต day_app ตาราง
    if (isset($data['days'])) {
        $days = $data['days'];
        $day_values = [
            isset($days['sunday']) && $days['sunday'] ? 1 : 0,
            isset($days['monday']) && $days['monday'] ? 1 : 0,
            isset($days['tuesday']) && $days['tuesday'] ? 1 : 0,
            isset($days['wednesday']) && $days['wednesday'] ? 1 : 0,
            isset($days['thursday']) && $days['thursday'] ? 1 : 0,
            isset($days['friday']) && $days['friday'] ? 1 : 0,
            isset($days['saturday']) && $days['saturday'] ? 1 : 0,
            $app_id
        ];

        // เช็คว่ามีข้อมูลอยู่หรือไม่
        $check = $conn->prepare("SELECT day_app_id FROM day_app WHERE app_id = ?");
        $check->execute([$app_id]);
        if ($check->rowCount() > 0) {
            $stmt = $conn->prepare("
                UPDATE day_app SET 
                    sunday = ?, monday = ?, tuesday = ?, wednesday = ?,
                    thursday = ?, friday = ?, saturday = ?
                WHERE app_id = ?
            ");
            $stmt->execute($day_values);
        } else {
            $stmt = $conn->prepare("
                INSERT INTO day_app (sunday, monday, tuesday, wednesday, thursday, friday, saturday, app_id) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute($day_values);
        }
    }

    // อัพเดต medication_link
    if (isset($data['medications'])) {
        $medications = $data['medications'];

        // ลบข้อมูลเดิมที่เกี่ยวข้องกับ app และ user
        $delete_stmt = $conn->prepare("
            DELETE ml FROM medication_link ml
            JOIN medication m ON ml.medication_id = m.medication_id
            JOIN connect c ON m.connect_id = c.connect_id
            WHERE ml.app_id = ? AND c.user_id = ?
        ");
        $delete_stmt->execute([$app_id, $user_id]);

        // ดึง pill_slot ปัจจุบัน
        $app_stmt = $conn->prepare("SELECT pill_slot FROM app WHERE app_id = ?");
        $app_stmt->execute([$app_id]);
        $app_data = $app_stmt->fetch(PDO::FETCH_ASSOC);
        $timing_id = $app_data ? (int)$app_data['pill_slot'] : null;

        foreach ($medications as $medication) {
            if (isset($medication['medication_id'], $medication['amount'])) {
                $medication_id = (int)$medication['medication_id'];

                $amount = (float)$medication['amount']; 
                
                if ($medication_id > 0 && $amount > 0) {
                    $verify_query = "
                        SELECT m.medication_id 
                        FROM medication m
                        JOIN connect c ON m.connect_id = c.connect_id
                        WHERE m.medication_id = ? AND c.user_id = ?
                    ";
                    $verify_params = [$medication_id, $user_id];
                    if ($timing_id && $timing_id > 0) {
                        $verify_query .= " AND EXISTS (
                            SELECT 1 FROM medication_timing mt 
                            WHERE mt.medication_id = m.medication_id AND mt.timing_id = ?
                        )";
                        $verify_params[] = $timing_id;
                    }
                    $verify_stmt = $conn->prepare($verify_query);
                    $verify_stmt->execute($verify_params);
                    if ($verify_stmt->rowCount() > 0) {
                        $insert_stmt = $conn->prepare("INSERT INTO medication_link (app_id, medication_id, amount) VALUES (?, ?, ?)");
                        $insert_stmt->execute([$app_id, $medication_id, $amount]);
                    }
                }
            }
        }
    }

    $conn->commit();

    $status_message = $status === 1 ? 'เปิดใช้งาน' : 'ปิดใช้งาน (ข้อมูลไม่ครบถ้วน)';

    $response_data = [
        'app_id' => $app_id,
        'app_status' => $status,
        'app_status_message' => $status_message
    ];

    ResponseHelper::success($response_data, 'บันทึกการตั้งค่าทั้งหมดสำเร็จ');

} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log("Update app settings error: " . $e->getMessage());
    ResponseHelper::serverError('เกิดข้อผิดพลาดในการบันทึกการตั้งค่า');
}