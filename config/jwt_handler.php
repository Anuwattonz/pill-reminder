<?php
/**
 * Unified JWT Handler
 * ✅ รวม jwt_settings, jwt_verify, jwt_refresh, jwt_middleware เป็นไฟล์เดียว
 */

require_once __DIR__ . '/config_loader.php';
require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;

class JWTHandler {
    private static $config = null;
    
    /**
     * โหลดการตั้งค่า JWT
     */
    private static function getConfig() {
        if (self::$config === null) {
            $jwt_config = ConfigLoader::getJwtConfig();
            
            self::$config = [
                'secret' => $jwt_config['secret'],        // ต้องแน่ใจว่า .env มี JWT_SECRET
                'algorithm' => 'HS256',
                'expiration' => (int)$jwt_config['expiration'],           // JWT_EXPIRATION ต้องมีใน .env
                'refresh_expiration' => (int)$jwt_config['refresh_expiration'], // REFRESH_EXPIRATION ต้องมีใน .env
                'leeway' => (int)$jwt_config['leeway'],                   // JWT_LEEWAY ต้องมีใน .env
                'issuer' => $jwt_config['issuer'],                         // JWT_ISSUER ถ้ามี
                'audience' => $jwt_config['audience']    
            ];
        }
        
        return self::$config;
    }
    
    /**
     * สร้าง Secret Key
     */
    private static function generateSecretKey() {
        $secret_file = __DIR__ . '/.jwt_secret';
        
        if (file_exists($secret_file) && is_readable($secret_file)) {
            $secret = trim(file_get_contents($secret_file));
            if (strlen($secret) >= 32) {
                return $secret;
            }
        }
        
        $secret = bin2hex(random_bytes(32));
        
        if (is_writable(dirname($secret_file))) {
            file_put_contents($secret_file, $secret);
            chmod($secret_file, 0600);
        }
        
        return $secret;
    }
    
    /**
     * สร้าง JWT Token
     */
    public static function createToken($user_id, $connect_id = null) {
        $config = self::getConfig();
        $current_time = time();
        
        $payload = [
            'iss' => $config['issuer'],
            'aud' => $config['audience'],
            'iat' => $current_time,
            'exp' => $current_time + $config['expiration'],
            'user_id' => (int)$user_id,
            'has_connection' => !empty($connect_id)
        ];
        
        if ($connect_id) {
            $payload['connect_id'] = (int)$connect_id;
        }
        
        return JWT::encode($payload, $config['secret'], $config['algorithm']);
    }
    
    /**
     * สร้าง Refresh Token
     */
    public static function createRefreshToken($user_id, $connect_id = null) {
        $config = self::getConfig();
        $current_time = time();
        
        $payload = [
            'iss' => $config['issuer'],
            'aud' => $config['audience'],
            'iat' => $current_time,
            'exp' => $current_time + $config['refresh_expiration'],
            'user_id' => (int)$user_id,
            'type' => 'refresh'
        ];
        
        if ($connect_id) {
            $payload['connect_id'] = (int)$connect_id;
        }
        
        return JWT::encode($payload, $config['secret'], $config['algorithm']);
    }
    
    /**
     * ตรวจสอบ JWT Token
     */
    public static function verifyToken($token = null) {
        $config = self::getConfig();
        
        if (!$token) {
            $token = self::getTokenFromHeader();
        }
        
        if (!$token) {
            return [
                'success' => false,
                'error' => 'ไม่พบ token',
                'code' => 401
            ];
        }
        
        try {
            $decoded = JWT::decode($token, new Key($config['secret'], $config['algorithm']));
            $payload = (array)$decoded;
            
            return [
                'success' => true,
                'payload' => $payload,
                'user_id' => (int)$payload['user_id'],
                'connect_id' => isset($payload['connect_id']) ? (int)$payload['connect_id'] : 0
            ];
            
        } catch (ExpiredException $e) {
            return [
                'success' => false,
                'error' => 'Token หมดอายุ',
                'code' => 401,
                'expired' => true
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Token ไม่ถูกต้อง',
                'code' => 401
            ];
        }
    }
    
    /**
     * Refresh Token
     */
    public static function refreshToken($refresh_token = null) {
        $config = self::getConfig();
        
        if (!$refresh_token) {
            $refresh_token = self::getTokenFromHeader();
        }
        
        if (!$refresh_token) {
            return [
                'success' => false,
                'error' => 'ไม่พบ refresh token',
                'code' => 401
            ];
        }
        
        try {
            // อนุญาต token ที่หมดอายุไป 1 วัน
            $original_leeway = JWT::$leeway;
            JWT::$leeway = 86400; // 1 วัน
            
            $decoded = JWT::decode($refresh_token, new Key($config['secret'], $config['algorithm']));
            JWT::$leeway = $original_leeway;
            
            $user_id = $decoded->user_id;
            
            // ตรวจสอบว่า user ยังมีอยู่ในระบบ
            $conn = getConnection();
            if (!$conn) {
                return ['success' => false, 'error' => 'Database connection failed', 'code' => 500];
            }
            
            $stmt = $conn->prepare("SELECT user_id, user FROM user WHERE user_id = ?");
            $stmt->execute([$user_id]);
            
            if ($stmt->rowCount() === 0) {
                return ['success' => false, 'error' => 'ไม่พบผู้ใช้', 'code' => 404];
            }
            
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            $user['user_id'] = (int)$user['user_id'];
            
            // ดึงข้อมูล connection
            $stmt = $conn->prepare("
                SELECT connect_id 
                FROM connect 
                WHERE user_id = ?
            ");
            $stmt->execute([$user_id]);
            $connections = $stmt->fetchAll(PDO::FETCH_ASSOC);          
            $has_connection = count($connections) > 0;
            $connect_id = $has_connection ? $connections[0]['connect_id'] : 0;
            
            // สร้าง token ใหม่
            $new_token = self::createToken($user_id, $connect_id);
            
            return [
                'success' => true,
                'token' => $new_token,
                'user_data' => [
                    'user' => $user,
                    'has_connection' => $has_connection,
                    'connect_id' => $connect_id
                ],
                'expires_in' => $config['expiration']
            ];
            
        } catch (Exception $e) {
            JWT::$leeway = $original_leeway ?? 0;
            return [
                'success' => false,
                'error' => 'ไม่สามารถ refresh token ได้',
                'code' => 401
            ];
        }
    }
    
    /**
     * ดึง token จาก header
     */
    private static function getTokenFromHeader() {
        $headers = getallheaders();
        
        if (is_array($headers)) {
            foreach ($headers as $key => $value) {
                if (strtolower($key) === 'authorization') {
                    if (preg_match('/Bearer\s+(.*)$/i', $value, $matches)) {
                        return trim($matches[1]);
                    }
                }
            }
        }
        
        return null;
    }
    
    /**
     * Middleware - ตรวจสอบ Authentication
     */
    public static function requireAuth() {
        $verify_result = self::verifyToken();
        
        if ($verify_result['success']) {
            return [
                'success' => true,
                'user_id' => $verify_result['user_id'],
                'connect_id' => $verify_result['connect_id'],
                'payload' => $verify_result['payload']
            ];
        }
        
        // ถ้า token หมดอายุ ลอง refresh
        if (isset($verify_result['expired']) && $verify_result['expired']) {
            $refresh_result = self::refreshToken();
            
            if ($refresh_result['success']) {
                // ส่ง token ใหม่ใน header
                header('X-New-Token: ' . $refresh_result['token']);
                
                return [
                    'success' => true,
                    'user_id' => $refresh_result['user_data']['user']['user_id'],
                    'connect_id' => $refresh_result['user_data']['connect_id'],
                    'token_refreshed' => true,
                    'new_token' => $refresh_result['token']
                ];
            }
        }
        
        // Authentication failed
        self::sendErrorResponse($verify_result['error'], $verify_result['code']);
    }
    
    /**
     * ส่ง Error Response
     */
    private static function sendErrorResponse($message, $code = 401) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        
        echo json_encode([
            'status' => 'error',
            'message' => $message,
            'timestamp' => date('c')
        ], JSON_UNESCAPED_UNICODE);
        
        exit;
    }
    
    /**
     * ดึงการตั้งค่าสำหรับ debug (development only)
     */
    public static function getDebugInfo() {
        if (!ConfigLoader::isDevelopment()) {
            return null;
        }
        
        $config = self::getConfig();
        
        return [
            'secret_length' => strlen($config['secret']),
            'access_token_minutes' => round($config['expiration'] / 60, 2),
            'refresh_token_days' => round($config['refresh_expiration'] / 86400, 2),
            'leeway_seconds' => $config['leeway']
        ];
    }
}

// ==========================================
// Helper Functions (เพื่อความ backward compatible)
// ==========================================

/**
 * ตรวจสอบ Authentication (สำหรับใช้ในไฟล์เดิม)
 */
function checkAuth() {
    return JWTHandler::requireAuth();
}

/**
 * ส่ง Error Response (สำหรับใช้ในไฟล์เดิม)
 */
function sendErrorResponse($message, $code = 500, $details = []) {
    $response = [
        'status' => 'error',
        'message' => $message,
        'timestamp' => date('c')
    ];
    
    if (ConfigLoader::isDevelopment() && !empty($details)) {
        $response['debug'] = $details;
    }
    
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * เพิ่มฟังก์ชัน refreshJWTToken สำหรับ backward compatible
 */
function refreshJWTToken() {
    return JWTHandler::refreshToken();
}

/**
 * ตรวจสอบการตั้งค่าเมื่อโหลดไฟล์
 */
if (ConfigLoader::isDevelopment()) {
    $debug_info = JWTHandler::getDebugInfo();
    if ($debug_info) {
        error_log("JWT Handler loaded: " . json_encode($debug_info));
    }
}
?>