<?php
/**
 * GET /medications/{id} - ดึงข้อมูลยา 1 รายการ
 * ไฟล์: endpoints/medications/show.php
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

// รับ medication_id จาก parameter (RESTful style)
$medication_id = isset($_GET['medication_id']) ? intval($_GET['medication_id']) : 0;

if ($medication_id <= 0) {
    ResponseHelper::validationError('ต้องระบุ medication_id ที่ถูกต้อง');
}

try {
    // ตรวจสอบว่ายาเป็นของผู้ใช้คนนี้หรือไม่
    $stmt = $conn->prepare("
        SELECT m.medication_id 
        FROM medication m
        JOIN connect c ON m.connect_id = c.connect_id
        WHERE m.medication_id = ? AND c.user_id = ?
    ");
    $stmt->execute([$medication_id, $user_id]);
    
    if ($stmt->rowCount() === 0) {
        ResponseHelper::notFound('ไม่พบยาหรือไม่มีสิทธิ์เข้าถึง');
    }

    // ดึงข้อมูลยา
    $stmt = $conn->prepare("
        SELECT 
            m.medication_id,
            m.connect_id,
            m.medication_name,
            m.medication_nickname,
            m.description,
            m.picture,
            m.dosage_form_id,
            m.unit_type_id,
            df.dosage_name as dosage_form,
            ut.unit_type_name as unit_type
        FROM medication m
        JOIN connect c ON m.connect_id = c.connect_id
        JOIN dosage_form df ON m.dosage_form_id = df.dosage_form_id
        LEFT JOIN unit_type ut ON m.unit_type_id = ut.unit_type_id
        WHERE m.medication_id = ? AND c.user_id = ?
    ");
    $stmt->execute([$medication_id, $user_id]);
    $medication = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$medication) {
        ResponseHelper::notFound('ไม่พบข้อมูลยา');
    }

    // แปลงข้อมูลให้เป็น int และเพิ่ม picture_url
    $medication['medication_id'] = (int)$medication['medication_id'];
    $medication['connect_id'] = (int)$medication['connect_id'];
    $medication['dosage_form_id'] = (int)$medication['dosage_form_id'];
    $medication['unit_type_id'] = (int)$medication['unit_type_id'];
    $medication['picture_url'] = getImageUrl($medication['picture']);

    //  เพิ่ม display_name ที่ Flutter ต้องการ
    $medication['display_name'] = !empty($medication['medication_nickname']) && $medication['medication_nickname'] !== '-'
        ? $medication['medication_nickname']
        : $medication['medication_name'];

    //  DEBUG: Log ข้อมูลจากฐานข้อมูล
    error_log("=== DEBUG MEDICATION TIMING ===");
    $debug_stmt = $conn->prepare("SELECT * FROM medication_timing WHERE medication_id = ?");
    $debug_stmt->execute([$medication_id]);
    $debug_data = $debug_stmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("Raw medication_timing data: " . json_encode($debug_data));

    //  แก้ไข: ดึงข้อมูล timing และ alias เป็น timing_name ให้ตรงกับ Flutter
    $stmt = $conn->prepare("
        SELECT 
            mt.timing_id,
            t.timing as timing_name
        FROM medication_timing mt
        JOIN timing t ON mt.timing_id = t.timing_id
        WHERE mt.medication_id = ?
        ORDER BY mt.timing_id
    ");
    $stmt->execute([$medication_id]);
    $medication_timings_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    error_log("Raw medication_timings from JOIN: " . json_encode($medication_timings_raw));

    //  กรองข้อมูลให้ unique โดย timing_id
    $medication_timings = [];
    $seen_timing_ids = [];
    
    foreach ($medication_timings_raw as $timing) {
        $timing_id = (int)$timing['timing_id'];
        
        if (!in_array($timing_id, $seen_timing_ids)) {
            $medication_timings[] = [
                'timing_id' => $timing_id,
                'timing_name' => $timing['timing_name']  // ✅ ใช้ alias timing_name จาก query
            ];
            $seen_timing_ids[] = $timing_id;
        }
    }

    //  DEBUG: Log ข้อมูลหลังกรอง
    error_log("Final medication_timings: " . json_encode($medication_timings));
    
    // เพิ่ม debug เพื่อเช็คว่ามีข้อมูลหรือไม่
    if (empty($medication_timings)) {
        error_log("WARNING: No medication timings found for medication_id: $medication_id");
        
        // ตรวจสอบว่ามีข้อมูลใน medication_timing table หรือไม่
        $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM medication_timing WHERE medication_id = ?");
        $check_stmt->execute([$medication_id]);
        $count_result = $check_stmt->fetch(PDO::FETCH_ASSOC);
        error_log("Count of medication_timing records: " . $count_result['count']);
    }

    // ตรวจสอบการใช้งานของแต่ละ timing ในระบบ reminder
    $timing_usage = [];
    foreach ($medication_timings as $timing) {
        $timing_id = $timing['timing_id'];
        
        // ค้นหา app ที่ใช้ timing นี้และมียานี้อยู่
        $stmt = $conn->prepare("
            SELECT DISTINCT
                a.app_id,
                a.timing,
                a.status
            FROM app a
            JOIN connect c ON a.connect_id = c.connect_id
            WHERE c.user_id = ? 
                AND a.pill_slot = ?
                AND a.status = 1
                AND EXISTS (
                    SELECT 1 FROM medication_link ml 
                    WHERE ml.app_id = a.app_id 
                    AND ml.medication_id = ?
                )
            ORDER BY a.app_id
        ");
        $stmt->execute([$user_id, $timing_id, $medication_id]);
        $active_apps = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // เพิ่มข้อมูลการใช้งานใน timing
        $timing_usage[$timing_id] = [
            'timing_id' => $timing_id,
            'timing_name' => $timing['timing_name'],
            'is_used' => !empty($active_apps),
            'app_count' => count($active_apps),
            'apps' => $active_apps
        ];
    }

    $response_data = [
        'medication' => $medication,
        'medication_timings' => $medication_timings,
        'timing_usage' => $timing_usage
    ];

    ResponseHelper::success($response_data, 'ดึงข้อมูลยาสำเร็จ');

} catch (Exception $e) {
    error_log("Get medication detail error: " . $e->getMessage());
    ResponseHelper::validationError($e->getMessage());
}

// ปิดการเชื่อมต่อ
if ($conn) {
    $conn = null;
}
?>