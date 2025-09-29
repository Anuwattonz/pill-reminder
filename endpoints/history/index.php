<?php
/**
 * GET /history - ดึงรายการประวัติการทานยา (แบบ pagination)
 * ✅ แก้ไข SQL syntax สำหรับ MariaDB
 */

require_once __DIR__ . '/../../config/api_headers.php';
require_once __DIR__ . '/../../config/jwt_handler.php';
require_once __DIR__ . '/../../config/db_connection.php';
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

// ใช้ JWT handler
$auth_result = checkAuth();
$user_id = $auth_result['user_id'];
$connect_id = $auth_result['connect_id'];

if (!$connect_id) {
    ResponseHelper::error('ต้องเชื่อมต่อกับอุปกรณ์ก่อน', 403);
}

// เพิ่ม pagination parameters
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = isset($_GET['limit']) ? max(1, min(50, (int)$_GET['limit'])) : 10;
$offset = ($page - 1) * $limit;

try {
    // นับจำนวนรายการทั้งหมดก่อน
    $count_stmt = $conn->prepare("
        SELECT COUNT(*) as total
        FROM reminder_medication rm
        WHERE rm.connect_id = ?
    ");
    $count_stmt->execute([$connect_id]);
    $total_items = (int)$count_stmt->fetchColumn();
    $total_pages = ceil($total_items / $limit);
    
    // ✅ แก้ไข SQL สำหรับ MariaDB - ใช้ค่าตัวเลขโดยตรง
    $sql = "
        SELECT 
            rm.reminder_medical_id,
            rm.day,
            rm.time,
            rm.receive_time,
            rm.status,
            rm.timing_id,
            t.timing as timing_name
        FROM reminder_medication rm
        LEFT JOIN timing t ON rm.timing_id = t.timing_id
        WHERE rm.connect_id = ?
        ORDER BY 
            rm.time DESC,
            rm.reminder_medical_id DESC
        LIMIT $limit OFFSET $offset
    ";
    
    // ✅ ส่งแค่ connect_id เป็น parameter
    $stmt = $conn->prepare($sql);
    $stmt->execute([$connect_id]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // จัดรูปแบบข้อมูลง่ายๆ สำหรับแสดงในรายการ
    foreach ($history as &$item) {
        // แปลง ID เป็น integer
        $item['reminder_medical_id'] = (int)$item['reminder_medical_id'];
        
        // แสดงชื่อมื้อ
        $item['timing_name'] = $item['timing_name'] ?: 'ไม่ระบุมื้อ';
        
        // แสดงวัน
        $item['day'] = $item['day'] ?: 'ทุกวัน';
        
        // เพิ่มข้อมูลวันที่สำหรับแสดงผล
        if ($item['time']) {
            $timestamp = strtotime($item['time']);
            $item['formatted_date'] = date('j M Y', $timestamp);
            $item['formatted_time'] = date('H:i', $timestamp);
            $item['formatted_datetime'] = date('j M Y เวลา H:i น.', $timestamp);
            $item['display_datetime'] = $item['time'];
        }
    }

    $response_data = [
        'history' => $history,
        'pagination' => [
            'current_page' => $page,
            'total_pages' => $total_pages,
            'total_items' => $total_items,
            'items_per_page' => $limit,
            'has_next' => $page < $total_pages,
            'has_prev' => $page > 1
        ]
    ];

    ResponseHelper::success($response_data, 'ดึงประวัติการแจ้งเตือนสำเร็จ', false);

} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    ResponseHelper::serverError('เกิดข้อผิดพลาดในการดึงข้อมูล: ' . $e->getMessage());
} catch (Exception $e) {
    error_log("General error: " . $e->getMessage());
    ResponseHelper::serverError('เกิดข้อผิดพลาดในการประมวลผล: ' . $e->getMessage());
}
?>