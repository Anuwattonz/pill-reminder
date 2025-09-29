<?php
/**
 * GET /history/summary - ดึงสรุปสถิติการกินยา
 * ไฟล์: endpoints/history/summary.php
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

// ตรวจสอบ JWT
$auth_result = checkAuth();
$user_id = $auth_result['user_id'];
$connect_id = $auth_result['connect_id'];

if (!$connect_id) {
    ResponseHelper::error('ต้องเชื่อมต่อกับอุปกรณ์ก่อน', 403);
}

// ดึง parameters สำหรับกรองข้อมูล (optional)
$period = $_GET['period'] ?? 'all'; // all, month, week
$start_date = $_GET['start_date'] ?? null;
$end_date = $_GET['end_date'] ?? null;

try {
    // เงื่อนไขวันที่
    $date_condition = '';
    $date_params = [];
    
    if ($start_date && $end_date) {
        $date_condition = ' AND DATE(rm.time) BETWEEN ? AND ?';
        $date_params = [$start_date, $end_date];
    } elseif ($period === 'month') {
        $date_condition = ' AND rm.time >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
    } elseif ($period === 'week') {
        $date_condition = ' AND rm.time >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
    }
    
    $params = array_merge([$connect_id], $date_params);
    
    // 1. สรุปสถิติรวม
    $summary_stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_reminders,
            SUM(CASE WHEN status = 'taken' THEN 1 ELSE 0 END) as taken_count,
            SUM(CASE WHEN status != 'taken' THEN 1 ELSE 0 END) as missed_count,
            ROUND(
                SUM(CASE WHEN status = 'taken' THEN 1 ELSE 0 END) * 100.0 / 
                NULLIF(COUNT(*), 0), 2
            ) as compliance_rate
        FROM reminder_medication rm
        WHERE rm.connect_id = ?" . $date_condition
    );
    $summary_stmt->execute($params);
    $summary = $summary_stmt->fetch(PDO::FETCH_ASSOC);
    
    $summary['total_reminders'] = (int)$summary['total_reminders'];
    $summary['taken_count'] = (int)$summary['taken_count'];
    $summary['missed_count'] = (int)$summary['missed_count'];
    $summary['compliance_rate'] = (float)$summary['compliance_rate'];
    
    // 2. สถิติแยกตามมื้อ (Timing Breakdown)
    $timing_stmt = $conn->prepare("
        SELECT 
            t.timing_id,
            ANY_VALUE(t.timing) as timing,
            COUNT(rm.reminder_medical_id) as total,
            SUM(CASE WHEN rm.status = 'taken' THEN 1 ELSE 0 END) as taken,
            SUM(CASE WHEN rm.status != 'taken' THEN 1 ELSE 0 END) as missed,
            ROUND(
                SUM(CASE WHEN rm.status = 'taken' THEN 1 ELSE 0 END) * 100.0 / 
                NULLIF(COUNT(rm.reminder_medical_id), 0), 2
            ) as compliance_rate
        FROM reminder_medication rm
        JOIN timing t ON rm.timing_id = t.timing_id
        WHERE rm.connect_id = ?" . $date_condition . "
        GROUP BY t.timing_id
        ORDER BY t.timing_id
    ");
    $timing_stmt->execute($params);
    $timing_breakdown = $timing_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($timing_breakdown as &$timing) {
        $timing['timing_id'] = (int)$timing['timing_id'];
        $timing['total'] = (int)$timing['total'];
        $timing['taken'] = (int)$timing['taken'];
        $timing['missed'] = (int)$timing['missed'];
        $timing['compliance_rate'] = (float)$timing['compliance_rate'];
    }
    
    // 3. สถิติรายวัน (Daily Trend) → คำนวณและส่งออกเฉพาะถ้า period != all
    $weekly_trend = [];
    if ($period !== 'all') {
        $daily_stmt = $conn->prepare("
            SELECT 
                DATE(rm.time) as date,
                ANY_VALUE(
                    CASE 
                        WHEN DAYOFWEEK(DATE(rm.time)) = 1 THEN 'วันอาทิตย์'
                        WHEN DAYOFWEEK(DATE(rm.time)) = 2 THEN 'วันจันทร์'
                        WHEN DAYOFWEEK(DATE(rm.time)) = 3 THEN 'วันอังคาร'
                        WHEN DAYOFWEEK(DATE(rm.time)) = 4 THEN 'วันพุธ'
                        WHEN DAYOFWEEK(DATE(rm.time)) = 5 THEN 'วันพฤหัสบดี'
                        WHEN DAYOFWEEK(DATE(rm.time)) = 6 THEN 'วันศุกร์'
                        WHEN DAYOFWEEK(DATE(rm.time)) = 7 THEN 'วันเสาร์'
                    END
                ) as day_name,
                COUNT(rm.reminder_medical_id) as total,
                SUM(CASE WHEN rm.status = 'taken' THEN 1 ELSE 0 END) as taken,
                ROUND(
                    SUM(CASE WHEN rm.status = 'taken' THEN 1 ELSE 0 END) * 100.0 / 
                    NULLIF(COUNT(rm.reminder_medical_id), 0), 2
                ) as compliance_rate
            FROM reminder_medication rm
            WHERE rm.connect_id = ?" . $date_condition . "
            GROUP BY DATE(rm.time)
            ORDER BY date DESC
        ");
        $daily_stmt->execute($params);
        $weekly_trend = $daily_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($weekly_trend as &$day) {
            $day['total'] = (int)$day['total'];
            $day['taken'] = (int)$day['taken'];
            $day['compliance_rate'] = (float)$day['compliance_rate'];
        }
    }
    
    // 4. ยาที่กินบ่อยที่สุด (Top Medications)
    $medication_stmt = $conn->prepare("
        SELECT 
            ANY_VALUE(ms.medication_name) as medication_name,
            COUNT(rm.reminder_medical_id) as total_times,
            SUM(CASE WHEN rm.status = 'taken' THEN 1 ELSE 0 END) as taken_times,
            ROUND(
                SUM(CASE WHEN rm.status = 'taken' THEN 1 ELSE 0 END) * 100.0 / 
                NULLIF(COUNT(rm.reminder_medical_id), 0), 2
            ) as compliance_rate
        FROM reminder_medication rm
        JOIN medication_snapshot ms ON rm.reminder_medical_id = ms.reminder_medical_id
        WHERE rm.connect_id = ?" . $date_condition . "
        GROUP BY ms.medication_name
        ORDER BY total_times DESC, compliance_rate DESC
        LIMIT 5
    ");
    $medication_stmt->execute($params);
    $top_medications = $medication_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($top_medications as &$med) {
        $med['total_times'] = (int)$med['total_times'];
        $med['taken_times'] = (int)$med['taken_times'];
        $med['compliance_rate'] = (float)$med['compliance_rate'];
    }
    
    // ส่ง response
    $response = [
        'summary' => $summary,
        'timing_breakdown' => $timing_breakdown,
        'top_medications' => $top_medications
    ];
    
    if ($period !== 'all') {
        $response['weekly_trend'] = $weekly_trend;
    }
    
    ResponseHelper::success($response, 'ดึงสรุปสถิติการกินยาสำเร็จ');
    
} catch (Exception $e) {
    error_log("Error in summary.php: " . $e->getMessage());
    ResponseHelper::serverError('เกิดข้อผิดพลาดในการดึงข้อมูล');
}
?>
