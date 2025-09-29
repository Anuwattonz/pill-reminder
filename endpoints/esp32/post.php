<?php
require_once __DIR__ . '/../../config/api_headers.php';
require_once __DIR__ . '/../../config/db_connection.php';

setApiHeaders();
handleOptions();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$conn = getConnection();
if (!$conn) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
    exit;
}

function convertDayToThai($englishDay) {
    $dayMap = [
        'Sunday' => 'วันอาทิตย์', 'Monday' => 'วันจันทร์', 'Tuesday' => 'วันอังคาร',
        'Wednesday' => 'วันพุธ', 'Thursday' => 'วันพฤหัสบดี', 'Friday' => 'วันศุกร์', 'Saturday' => 'วันเสาร์'
    ];
    return $dayMap[$englishDay] ?? $englishDay;
}

function convertToFraction($decimal) {
    $decimal = (float)$decimal;
    
    if ($decimal >= 1.0) {
        return ($decimal == floor($decimal)) ? (string)(int)$decimal : (string)$decimal;
    }
    
    if ($decimal == 0.5) return '1/2';
    if ($decimal == 0.25) return '1/4';
    return (string)$decimal;
}

function saveImage($base64_data, $slot_number, $filename = null) {
    if (empty($base64_data)) return null;
    
    if (strpos($base64_data, 'data:image') === 0) {
        $base64_data = substr($base64_data, strpos($base64_data, ',') + 1);
    }
    
    $image_data = base64_decode($base64_data);
    if ($image_data === false) return null;
    
    if (!$filename) {
        $filename = "pill_slot{$slot_number}_" . date('YmdHis') . ".jpg";
    }
    
    $dir = __DIR__ . '/../../pictures/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    
    $file_path = $dir . $filename;
    return (file_put_contents($file_path, $image_data) !== false) ? $filename : null;
}

function getMedicationsByAppId($conn, $app_id) {
    $stmt = $conn->prepare("
        SELECT m.medication_name, ut.unit_type_name, ml.amount
        FROM medication_link ml
        LEFT JOIN medication m ON ml.medication_id = m.medication_id
        LEFT JOIN unit_type ut ON m.unit_type_id = ut.unit_type_id
        WHERE ml.app_id = ?
    ");
    $stmt->execute([$app_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function createMedicationSnapshots($conn, $reminder_id, $medications, $slot_number) {
    $snapshots = [];
    
    if (empty($medications)) {
        $med_name = "Slot {$slot_number} Medicine";
        $amount = "1";
        
        $stmt = $conn->prepare("INSERT INTO medication_snapshot (reminder_medical_id, medication_name, amount_taken) VALUES (?, ?, ?)");
        if ($stmt->execute([$reminder_id, $med_name, $amount])) {
            $snapshots[] = ['snapshot_id' => $conn->lastInsertId(), 'medication_name' => $med_name, 'amount_taken' => $amount];
        }
    } else {
        foreach ($medications as $med) {
            $med_name = $med['medication_name'] ?: "Unknown Medicine";
            $amount_value = $med['amount'] ?: 1;
            $fraction = convertToFraction($amount_value);
            $unit = $med['unit_type_name'] ?: '';
            $amount = $unit ? "{$fraction} {$unit}" : $fraction;
            
            $stmt = $conn->prepare("INSERT INTO medication_snapshot (reminder_medical_id, medication_name, amount_taken) VALUES (?, ?, ?)");
            if ($stmt->execute([$reminder_id, $med_name, $amount])) {
                $snapshots[] = ['snapshot_id' => $conn->lastInsertId(), 'medication_name' => $med_name, 'amount_taken' => $amount];
            }
        }
    }
    
    return $snapshots;
}

try {
    $input = file_get_contents('php://input');
    if (!$input) throw new Exception('No JSON input received');
    
    $data = json_decode($input, true);
    if (!$data) throw new Exception('Invalid JSON: ' . json_last_error_msg());
    
    $machine_SN = $data['machine_SN'] ?? '';
    $app_id = (int)($data['app_id'] ?? 0);
    $slot_number = (int)($data['slot_number'] ?? 0);
    $timing = $data['timing'] ?? '00:00:00';
    
    if (empty($machine_SN) || $app_id <= 0 || $slot_number < 1 || $slot_number > 7) {
        throw new Exception('Invalid required parameters');
    }
    
    $stmt = $conn->prepare("
        SELECT c.connect_id, c.user_id, m.machine_id 
        FROM connect c
        JOIN machinesn m ON c.machine_id = m.machine_id
        WHERE m.machine_SN = ?
    ");
    $stmt->execute([$machine_SN]);
    $connection_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$connection_data) {
        throw new Exception('Machine not found or not connected to any user');
    }
    
    $connect_id = $connection_data['connect_id'];
    $user_id = $connection_data['user_id'];
    $machine_id = $connection_data['machine_id'];
    
    $is_missed_dose = $data['missed_dose'] ?? false;
    $missed_reason = $data['timeout_reason'] ?? '';
    
    $status = ($is_missed_dose || (isset($data['medication_taken']) && $data['medication_taken'] === false)) ? 'missed' : 'taken';
    
    $today = date('Y-m-d');
    $now = date('Y-m-d H:i:s');
    
    // สลับการเก็บ: time เก็บเวลาที่ตั้งไว้, receive_time เก็บเวลาที่เกิดขึ้นจริง
    $scheduled_time = !empty($data['receive_time']) ? $data['receive_time'] : "{$today} {$timing}";
    $actual_time = !empty($data['actual_time']) ? $data['actual_time'] : $now;
    $day_english = !empty($data['day']) ? $data['day'] : date('l');
    $day_thai = convertDayToThai($day_english);
    
    $picture_path = null;
    if (!$is_missed_dose && isset($data['save_image']) && $data['save_image'] === true && !empty($data['image_data'])) {
        $custom_filename = $data['image_filename'] ?? null;
        $picture_path = saveImage($data['image_data'], $slot_number, $custom_filename);
    }
    
    $timing_id = $slot_number;
    $stmt = $conn->prepare("INSERT INTO reminder_medication (connect_id, day, receive_time, time, status, timing_id, picture) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $result = $stmt->execute([$connect_id, $day_thai, $actual_time, $scheduled_time, $status, $timing_id, $picture_path]);
    
    if (!$result) throw new Exception('Failed to insert reminder record');
    
    $reminder_id = $conn->lastInsertId();
    $medications = getMedicationsByAppId($conn, $app_id);
    $snapshots = createMedicationSnapshots($conn, $reminder_id, $medications, $slot_number);
    
    $delay_minutes = 0;
    if (!empty($actual_time) && !empty($scheduled_time)) {
        $delay_seconds = strtotime($actual_time) - strtotime($scheduled_time);
        $delay_minutes = max(0, round($delay_seconds / 60));
    }
    
    $response = [
        'status' => 'success',
        'message' => $is_missed_dose ? 'Missed dose recorded with medications' : 'Medication record saved',
        'timestamp' => date('Y-m-d H:i:s'),
        'data' => [
            'reminder_medical_id' => (int)$reminder_id,
            'connect_id' => $connect_id,
            'slot_number' => $slot_number,
            'timing' => $timing,
            'day' => $day_thai,
            'medication_status' => $status,
            'has_picture' => ($picture_path !== null),
            'delay_minutes' => $delay_minutes,
            'record_type' => $is_missed_dose ? 'MISSED_DOSE' : 'MEDICATION_TAKEN'
        ]
    ];
    
    if ($picture_path) {
        $response['image_saved'] = true;
        $response['image_filename'] = $picture_path;
        $response['image_url'] = "/pill-reminder/pictures/{$picture_path}";
    } else {
        $response['image_saved'] = false;
    }
    
    if (!empty($snapshots)) {
        $response['medications'] = $snapshots;
        $response['total_medications'] = count($snapshots);
    }
    
    if ($is_missed_dose) {
        $response['data']['missed_dose_info'] = [
            'detected' => true,
            'reason' => $missed_reason,
            'timeout_duration' => $delay_minutes . ' minutes',
            'medications_missed' => count($snapshots)
        ];
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("ESP32 Record API Error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
}