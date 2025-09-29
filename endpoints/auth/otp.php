<?php
/**
 * POST /auth/otp - จัดการ OTP (สร้าง และ ตรวจสอบ)
 * ไฟล์: endpoints/auth/otp.php
 * 
 * Support actions:
 * - generate: สร้าง OTP ใหม่
 * - verify: ตรวจสอบ OTP
 * 
 * แก้ไข: เพิ่มการเข้ารหัส OTP
 */

require_once __DIR__ . '/../../config/api_headers.php';
require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../../helpers/ResponseHelper.php';

setApiHeaders();
handleOptions();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ResponseHelper::error('อนุญาตเฉพาะเมธอด POST เท่านั้น', 405);
}

$conn = getConnection();
if (!$conn) {
    ResponseHelper::serverError('การเชื่อมต่อฐานข้อมูลล้มเหลว');
}

// รับข้อมูล JSON
$data = json_decode(file_get_contents('php://input'), true);

if (json_last_error() !== JSON_ERROR_NONE || !isset($data['action'])) {
    ResponseHelper::validationError('ข้อมูล JSON ไม่ถูกต้องหรือไม่มี action');
}

$action = $data['action'];

try {
    switch ($action) {
        case 'generate':
            handleGenerateOTP($conn, $data);
            break;
            
        case 'verify':
            handleVerifyOTP($conn, $data);
            break;
            
        default:
            ResponseHelper::validationError('action ไม่ถูกต้อง (ใช้ generate หรือ verify)');
            break;
    }
} catch (Exception $e) {
    error_log("OTP API Error: " . $e->getMessage());
    ResponseHelper::serverError('เกิดข้อผิดพลาดในการประมวลผล OTP');
}

/**
 * สร้าง OTP ใหม่
 */
function handleGenerateOTP($conn, $data) {

    $otp_config = ConfigLoader::getOtpConfig();
    
    if (!$otp_config['enabled']) {
        ResponseHelper::error('ระบบ OTP ถูกปิดใช้งาน', 503);
    }
    
    // ตรวจสอบข้อมูลที่จำเป็น
    if (!isset($data['email']) || !is_string($data['email'])) {
        ResponseHelper::validationError('ต้องระบุอีเมล');
    }
    
    $email = trim($data['email']);
    $max_attempts = $otp_config['max_attempts'];
    // ตรวจสอบรูปแบบอีเมล
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        ResponseHelper::validationError('รูปแบบอีเมลไม่ถูกต้อง');
    }
    
    $conn->beginTransaction();
    
    try {
        // ตรวจสอบว่าอีเมลมีอยู่ในระบบหรือไม่
        $stmt = $conn->prepare("SELECT user_id, user FROM user WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            $conn->rollBack();
            ResponseHelper::error('ไม่พบอีเมลในระบบ', 404);
        }
        
        $user_id = $user['user_id'];
        $username = $user['user'];
        
        // ตรวจสอบ Rate Limiting
        $rate_limit_minutes = $otp_config['rate_limit_minutes'];
        $stmt = $conn->prepare("
            SELECT COUNT(*) as count 
            FROM user_otp 
            WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
        ");
        $stmt->execute([$user_id, $rate_limit_minutes]);
        $recent_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        if ($recent_count > 0) {
            $conn->rollBack();
            ResponseHelper::error("กรุณารอ $rate_limit_minutes นาที ก่อนขอ OTP ใหม่", 429);
        }
        
        // ลบ OTP เก่าที่ยังไม่หมดอายุ (ถ้ามี)
        $stmt = $conn->prepare("DELETE FROM user_otp WHERE user_id = ?");
        $stmt->execute([$user_id]);
        
        // สร้าง OTP แบบสุ่ม ตามความยาวที่กำหนดใน config
        $otp_length = $otp_config['length'];
        $max_value = pow(10, $otp_length) - 1;
        $otp_code = str_pad(random_int(0, $max_value), $otp_length, '0', STR_PAD_LEFT);
        
        // เข้ารหัส OTP ก่อนบันทึกลงฐานข้อมูล
        $otp_hash = password_hash($otp_code, PASSWORD_DEFAULT);
        
        // กำหนดเวลาหมดอายุตาม config
        $expiry_minutes = $otp_config['expiry_minutes'];
        $expires_at = date('Y-m-d H:i:s', time() + ($expiry_minutes * 60));
        
        // บันทึก OTP ที่เข้ารหัสแล้วลงฐานข้อมูล
        $stmt = $conn->prepare("
            INSERT INTO user_otp (user_id, otp_code, expires_at, failed_attempts) 
            VALUES (?, ?, ?, 0)
        ");
        $stmt->execute([$user_id, $otp_hash, $expires_at]);
        
        $conn->commit();
        
        // ส่ง OTP ผ่าน Email (ถ้าเปิดใช้งาน) - ส่ง OTP จริงไม่ใช่ hash
        $email_sent = false;
        if ($otp_config['email_enabled']) {
            $email_sent = sendOTPEmail($email, $otp_code, $username, $expiry_minutes);
        }
        
        // เตรียม response
        $response_data = [
            'message' => 'สร้าง OTP สำเร็จ',
            'email' => $email,
            'expires_in' => $expiry_minutes * 60, // วินาที
            'email_sent' => $email_sent,
            'max_attempts' => $max_attempts 
        ];
        
        
        $message = $email_sent ? 
            'สร้าง OTP สำเร็จ กรุณาตรวจสอบอีเมล' : 
            'สร้าง OTP สำเร็จ (ไม่สามารถส่งอีเมลได้)';
            
        ResponseHelper::success($response_data, $message);
        
    } catch (PDOException $e) {
        $conn->rollBack();
        error_log("OTP Generate DB Error: " . $e->getMessage());
        ResponseHelper::serverError('เกิดข้อผิดพลาดในการสร้าง OTP');
    }
}

/**
 * ตรวจสอบ OTP
 */
function handleVerifyOTP($conn, $data) {
    // ดึงการตั้งค่า OTP จาก .env
    $otp_config = ConfigLoader::getOtpConfig();
    
    if (!$otp_config['enabled']) {
        ResponseHelper::error('ระบบ OTP ถูกปิดใช้งาน', 503);
    }
    
    // ตรวจสอบข้อมูลที่จำเป็น
    if (!isset($data['email']) || !isset($data['otp_code']) ||
        !is_string($data['email']) || !is_string($data['otp_code'])) {
        ResponseHelper::validationError('ต้องระบุอีเมลและรหัส OTP');
    }
    
    $email = trim($data['email']);
    $otp_code = trim($data['otp_code']);
    $otp_length = $otp_config['length'];
    $max_attempts = $otp_config['max_attempts'];
    
    // ตรวจสอบรูปแบบ OTP ตามความยาวที่กำหนด
    $pattern = '/^\d{' . $otp_length . '}$/';
    if (!preg_match($pattern, $otp_code)) {
        ResponseHelper::validationError("รหัส OTP ต้องเป็นตัวเลข $otp_length หลัก");
    }
    
    $conn->beginTransaction();
    
    try {
        // ค้นหา OTP hash พร้อมดู failed_attempts
        $stmt = $conn->prepare("
            SELECT uo.user_otp_id, uo.user_id, uo.otp_code, uo.failed_attempts, u.user, u.email 
            FROM user_otp uo
            JOIN user u ON uo.user_id = u.user_id
            WHERE u.email = ? AND uo.expires_at > NOW()
            ORDER BY uo.created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$email]);
        $otp_record = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$otp_record) {
            $conn->rollBack();
            ResponseHelper::error('ไม่พบ OTP ที่ใช้งานได้สำหรับอีเมลนี้', 404);
        }
        
        // เช็คว่าใส่ผิดเกิน MAX_ATTEMPTS หรือยัง
        if ($otp_record['failed_attempts'] >= $max_attempts) {
            $conn->rollBack();
            ResponseHelper::error(
                "ใส่รหัส OTP ผิดเกิน $max_attempts ครั้งแล้ว กรุณาขอ OTP ใหม่", 
                429
            );
        }
        
        // ตรวจสอบ OTP code โดยเปรียบเทียบกับ hash
        if (!password_verify($otp_code, $otp_record['otp_code'])) {
            // OTP ผิด - เพิ่ม failed_attempts
            $new_attempts = $otp_record['failed_attempts'] + 1;
            $stmt = $conn->prepare("
                UPDATE user_otp 
                SET failed_attempts = ? 
                WHERE user_otp_id = ?
            ");
            $stmt->execute([$new_attempts, $otp_record['user_otp_id']]);
            
            $conn->commit();
            
            $remaining = $max_attempts - $new_attempts;
            
            if ($remaining > 0) {
                ResponseHelper::error(
                    "รหัส OTP ไม่ถูกต้อง (เหลือ $remaining ครั้ง)", 
                    401
                );
                return;
            } else {
                ResponseHelper::error(
                    "ใส่รหัส OTP ผิดครบ $max_attempts ครั้งแล้ว กรุณาขอ OTP ใหม่", 
                    429
                );
                
            }
        }
            
        ResponseHelper::success([
            'user_id' => (int)$otp_record['user_id'],
            'username' => $otp_record['user'],
            'email' => $otp_record['email'],
            'verified_at' => date('Y-m-d H:i:s'),
            'message' => 'ตรวจสอบ OTP สำเร็จ สามารถรีเซ็ตรหัสผ่านได้แล้ว'
        ], 'ตรวจสอบ OTP สำเร็จ');
        
    } catch (PDOException $e) {
        $conn->rollBack();
        error_log("OTP Verify DB Error: " . $e->getMessage());
        ResponseHelper::serverError('เกิดข้อผิดพลาดในการตรวจสอบ OTP');
    }
}

/**
 * ส่ง OTP ผ่าน Email พร้อม Template แบบครบถ้วน
 */
function sendOTPEmail($email, $otp_code, $username = null, $expiry_minutes = 15) {
    // ตรวจสอบการตั้งค่า Email
    $email_config = ConfigLoader::getEmailConfig();
    if (!$email_config['enabled']) {
        error_log("Email service is disabled");
        return false;
    }
    
    // ตรวจสอบว่าติดตั้ง PHPMailer แล้วหรือยัง
    $vendor_path = __DIR__ . '/../../vendor/autoload.php';
    if (!file_exists($vendor_path)) {
        error_log("PHPMailer not installed. Using basic mail() function instead.");
        return sendOTPEmailBasic($email, $otp_code, $username, $expiry_minutes);
    }
    
    try {
        require_once $vendor_path;
        
        // ตรวจสอบว่า PHPMailer class มีอยู่จริง
        if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            error_log("PHPMailer class not found. Using basic mail() function instead.");
            return sendOTPEmailBasic($email, $otp_code, $username, $expiry_minutes);
        }
        
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        
        // Server settings จาก .env
        $mail->isSMTP();
        $mail->Host       = $email_config['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $email_config['username'];
        $mail->Password   = $email_config['password'];
        $mail->Port       = $email_config['port'];
        
        // Encryption
        if ($email_config['encryption'] === 'ssl') {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        }
        
        // Debug mode
        if ($email_config['debug']) {
            $mail->SMTPDebug = \PHPMailer\PHPMailer\SMTP::DEBUG_SERVER;
        }
        
        // Timeout และ charset
        $mail->Timeout = $email_config['timeout'];
        $mail->CharSet = 'UTF-8';
        
        // ผู้ส่งและผู้รับ
        $mail->setFrom($email_config['from_email'], $email_config['from_name']);
        $mail->addAddress($email);
        
        // เนื้อหาอีเมล
        $mail->isHTML(true);
        $mail->Subject = 'รหัส OTP สำหรับยืนยันตัวตน - CareReminder';
        
        $displayName = $username ? "คุณ $username" : "ผู้ใช้";
        
        // HTML Template
        $mail->Body = getOTPEmailHTML($displayName, $otp_code, $expiry_minutes);
        
        // Text Version
        $mail->AltBody = getOTPEmailText($displayName, $otp_code, $expiry_minutes);
        
        $result = $mail->send();
        
        if ($result) {
            error_log("OTP email sent successfully to: $email");
        } else {
            error_log("Failed to send OTP email to: $email");
        }
        
        return $result;
        
    } catch (Exception $e) {
        error_log("PHPMailer failed: " . $e->getMessage() . ". Trying basic mail() function.");
        return sendOTPEmailBasic($email, $otp_code, $username, $expiry_minutes);
    }
}

/**
 * ส่ง OTP ผ่าน Email แบบพื้นฐาน (fallback)
 */
function sendOTPEmailBasic($email, $otp_code, $username = null, $expiry_minutes = 15) {
    $displayName = $username ? "คุณ $username" : "ผู้ใช้";
    
    $subject = 'รหัส OTP สำหรับยืนยันตัวตน - CareReminder';
    
    // HTML Email
    $message = getOTPEmailHTML($displayName, $otp_code, $expiry_minutes);
    
    // Headers
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: CareReminder <noreply@localhost>" . "\r\n";
    
    // ส่งอีเมล
    $result = mail($email, $subject, $message, $headers);
    
    if ($result) {
        error_log("OTP email sent successfully (basic mail) to: $email");
    } else {
        error_log("Failed to send OTP email (basic mail) to: $email");
    }
    
    return $result;
}

/**
 * สร้าง HTML Template สำหรับ OTP Email
 */
function getOTPEmailHTML($displayName, $otp_code, $expiry_minutes) {
    return "
    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; background: #f8f9fa;'>
        <!-- Header -->
        <div style='background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px 20px; text-align: center;'>
            <h1 style='color: white; margin: 0; font-size: 28px; font-weight: bold;'>CareReminder</h1>
            <p style='color: rgba(255,255,255,0.9); margin: 10px 0 0 0; font-size: 16px;'>ระบบแจ้งเตือนการรับประทานยา</p>
        </div>
        
        <!-- Main Content -->
        <div style='padding: 40px 30px; background: white; margin: 0 20px;'>
            <h2 style='color: #333; margin-bottom: 25px; font-size: 24px; text-align: center;'>รหัส OTP ของคุณ</h2>
            
            <p style='color: #666; font-size: 18px; line-height: 1.6; text-align: center; margin-bottom: 30px;'>
                สวัสดี <strong>$displayName</strong><br>
                กรุณาใช้รหัส OTP ด้านล่างเพื่อยืนยันตัวตน:
            </p>
            
            <!-- OTP Code Box -->
            <div style='text-align: center; margin: 40px 0;'>
                <div style='display: inline-block; background: linear-gradient(135deg, #007bff 0%, #0056b3 100%); 
                           color: white; padding: 20px 40px; font-size: 32px; font-weight: bold; 
                           letter-spacing: 8px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,123,255,0.3);'>
                    $otp_code
                </div>
            </div>
            
            <!-- Warning Box -->
            <div style='background: #fff3cd; border-left: 4px solid #ffc107; padding: 20px; 
                       border-radius: 8px; margin: 30px 0;'>
                <p style='margin: 0; color: #856404; font-size: 16px;'>
                    <strong>หมายเหตุสำคัญ:</strong><br>
                    • รหัสนี้จะหมดอายุใน <strong>$expiry_minutes นาที</strong><br>
                    • สามารถใช้ได้เพียง <strong>1 ครั้งเท่านั้น</strong><br>
                    • ห้ามแชร์รหัสนี้กับผู้อื่น
                </p>
            </div>
            
            <!-- Security Notice -->
            <div style='background: #f8f9fa; border: 1px solid #dee2e6; padding: 20px; 
                       border-radius: 8px; margin: 20px 0;'>
                <h3 style='color: #495057; margin: 0 0 10px 0; font-size: 16px;'>ข้อมูลความปลอดภัย</h3>
                <ul style='color: #6c757d; font-size: 14px; margin: 0; padding-left: 20px; line-height: 1.5;'>
                    <li>หากคุณไม่ได้ขอรหัสนี้ กรุณาเพิกเฉยต่ออีเมลนี้</li>
                    <li>ระบบจะลบรหัสนี้อัตโนมัติหลังจากใช้งานเสร็จ</li>
                    <li>ติดต่อเราได้หากพบปัญหาการใช้งาน</li>
                </ul>
            </div>
        </div>
        
        <!-- Footer -->
        <div style='background: #6c757d; padding: 25px 20px; text-align: center; margin: 0 20px;'>
            <p style='color: #fff; margin: 0; font-size: 14px;'>
                © 2025 CareReminder System - All rights reserved.<br>
                <span style='color: #adb5bd;'>ระบบแจ้งเตือนการรับประทานยาอัจฉริยะ</span>
            </p>
        </div>
        
        <!-- Bottom Spacing -->
        <div style='height: 20px;'></div>
    </div>";
}

/**
 * สร้าง Text Version สำหรับ OTP Email
 */
function getOTPEmailText($displayName, $otp_code, $expiry_minutes) {
    return "
CareReminder
=======================

รหัส OTP สำหรับยืนยันตัวตน

สวัสดี $displayName

รหัส OTP ของคุณคือ: $otp_code

ข้อมูลสำคัญ:
• รหัสนี้จะหมดอายุใน $expiry_minutes นาที
• สามารถใช้ได้เพียง 1 ครั้งเท่านั้น
• ห้ามแชร์รหัสนี้กับผู้อื่น

ความปลอดภัย:
หากคุณไม่ได้ขอรหัสนี้ กรุณาเพิกเฉยต่ออีเมลนี้
ระบบจะลบรหัสนี้อัตโนมัติหลังจากใช้งานเสร็จ

© 2025 CareReminder
ระบบแจ้งเตือนการรับประทานยา
";
}
?>