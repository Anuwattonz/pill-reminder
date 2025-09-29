<?php
/**
 * Configuration Loader (Enhanced with new functions)
 * ✅ เพิ่ม functions สำหรับจัดการ array และ int values
 */

class ConfigLoader {
    private static $loaded = false;
    
    /**
     * โหลดการตั้งค่าจากไฟล์ .env
     */
    public static function load() {
        if (self::$loaded) {
            return;
        }
        
        // โหลดไฟล์ .env ถ้ามี
        $env_file = __DIR__ . '/../.env';
        if (file_exists($env_file)) {
            self::loadEnvFile($env_file);
        }
        date_default_timezone_set('Asia/Bangkok');
        // ตั้งค่าเริ่มต้น
        self::setDefaults();
        
        self::$loaded = true;
    }
    
    /**
     * โหลดไฟล์ .env
     */
    private static function loadEnvFile($file) {
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            // ข้าม comment lines
            if (strpos(trim($line), '#') === 0) {
                continue;
            }
            
            // แยก key=value
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                
                // เอา quotes ออก
                $value = trim($value, '"\'');
                
                // ตั้งค่า environment variable
                $_ENV[$key] = $value;
                putenv("$key=$value");
            }
        }
    }
    
    /**
     * ตั้งค่าเริ่มต้น (เฉพาะค่าที่จำเป็นต้องมี)
     */
    private static function setDefaults() {
        // ตั้งค่าเฉพาะค่าที่จำเป็นจริงๆ ถ้าไม่มีใน .env
        $minimal_defaults = [
            'APP_ENV' => 'development'
        ];
        
        foreach ($minimal_defaults as $key => $default_value) {
            if (!isset($_ENV[$key])) {
                $_ENV[$key] = $default_value;
                putenv("$key=$default_value");
            }
        }
        
        // ตรวจสอบว่ามีค่าสำคัญใน .env หรือไม่
        self::validateRequiredConfig();
    }
    
    /**
     * ตรวจสอบการตั้งค่าที่จำเป็น
     */
    private static function validateRequiredConfig() {
        $required_keys = [
            'JWT_SECRET',
            'DB_HOST', 
            'DB_NAME',
            'UPLOAD_BASE_URL',
            'UPLOAD_DIR_PATH'
        ];
        
        $missing_keys = [];
        foreach ($required_keys as $key) {
            if (empty($_ENV[$key])) {
                $missing_keys[] = $key;
            }
        }
        
        if (!empty($missing_keys)) {
            $error_msg = "Missing required configuration in .env file: " . implode(', ', $missing_keys);
            error_log($error_msg);
            
            if (self::isDevelopment()) {
                throw new Exception($error_msg);
            }
        }
    }
    
    /**
     * ดึงค่า config
     */
    public static function get($key, $default = null) {
        self::load();
        $value = $_ENV[$key] ?? $default;
        
        // Log warning ถ้าใช้ default value ใน development
        if ($value === $default && $default !== null && self::isDevelopment()) {
            error_log("Config Warning: Using default value for '$key' = '$default'");
        }
        
        return $value;
    }
    
    /**
     * ✅ ใหม่: ดึงค่า config เป็น integer
     */
    public static function getInt($key, $default = 0) {
        return (int)self::get($key, $default);
    }
    
    /**
     * ✅ ใหม่: ดึงค่า config เป็น array (แยกด้วย comma)
     */
    public static function getArray($key, $default = []) {
        $value = self::get($key, null);
        if ($value === null) {
            return $default;
        }
        
        // แยกด้วย comma และ trim แต่ละตัว
        $array = array_map('trim', explode(',', $value));
        return array_filter($array); // เอาตัวว่างออก
    }
    
    /**
     * ✅ ใหม่: ดึงค่า config เป็น boolean
     */
    public static function getBool($key, $default = false) {
        $value = self::get($key, $default);
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower($value), ['true', '1', 'yes', 'on']);
    }
    
    /**
     * ตรวจสอบว่าเป็น production environment หรือไม่
     */
    public static function isProduction() {
        return self::get('APP_ENV') === 'production';
    }
    
    /**
     * ตรวจสอบว่าเป็น development environment หรือไม่
     */
    public static function isDevelopment() {
        return self::get('APP_ENV') === 'development';
    }
    
    /**
     * สร้าง JWT Secret ใหม่ (method ที่ขาดหายไป)
     */
    public static function generateJwtSecret() {
        $secret = bin2hex(random_bytes(32)); // สร้าง secret 64 ตัวอักษร
        
        // พยายามบันทึกลงไฟล์ .env (อัพเดท)
        $env_file = __DIR__ . '/../.env';
        if (file_exists($env_file) && is_writable($env_file)) {
            $content = file_get_contents($env_file);
            
            // แทนที่ JWT_SECRET เดิม
            if (preg_match('/^JWT_SECRET=.*$/m', $content)) {
                $content = preg_replace('/^JWT_SECRET=.*$/m', "JWT_SECRET=$secret", $content);
            } else {
                // เพิ่มใหม่ถ้าไม่มี
                $content .= "\nJWT_SECRET=$secret\n";
            }
            
            file_put_contents($env_file, $content);
        }
        
        // อัพเดท environment variable
        $_ENV['JWT_SECRET'] = $secret;
        putenv("JWT_SECRET=$secret");
        
        return $secret;
    }
    
    /**
     * ดึงการตั้งค่า database
     */
    public static function getDatabaseConfig() {
        self::load();
        
        return [
            'host' => self::get('DB_HOST'),
            'name' => self::get('DB_NAME'),
            'user' => self::get('DB_USER'),
            'pass' => self::get('DB_PASS'),
            'charset' => self::get('DB_CHARSET', 'utf8mb4'),
            'timezone' => self::get('DB_TIMEZONE', '+07:00')
        ];
    }
    
    /**
     * ดึงการตั้งค่า JWT
     */
    public static function getJwtConfig() {
        self::load();
        
        return [
            'secret' => self::get('JWT_SECRET'),
            'expiration' => (int)self::get('JWT_EXPIRATION', 1800),
            'refresh_expiration' => (int)self::get('REFRESH_EXPIRATION', 604800),
            'leeway' => (int)self::get('JWT_LEEWAY', 60),
            'issuer' => self::get('JWT_ISSUER', 'pill-reminder-system'),
            'audience' => self::get('JWT_AUDIENCE', 'pill-reminder-app')
        ];
    }
    
    /**
     * ดึงการตั้งค่า upload
     */
    public static function getUploadConfig() {
        self::load();
        
        return [
            'base_url' => self::get('UPLOAD_BASE_URL'),
            'dir_path' => self::get('UPLOAD_DIR_PATH'),
            'max_size' => (int)self::get('MAX_UPLOAD_SIZE', 2097152),
            'max_width' => (int)self::get('MAX_IMAGE_WIDTH', 2048),
            'max_height' => (int)self::get('MAX_IMAGE_HEIGHT', 2048),
            'allowed_extensions' => self::getArray('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png']),
            'allowed_mime_types' => self::getArray('ALLOWED_MIME_TYPES', ['image/jpeg', 'image/png'])
        ];
    }
    
    /**
     * ✅ ใหม่: ดึงการตั้งค่าระบบ
     */
    public static function getSystemConfig() {
        self::load();
        
        return [
            'api_base_path' => self::get('API_BASE_PATH', '/pill-reminder/api'),
            'default_volume' => self::getInt('DEFAULT_VOLUME', 50),
            'default_delay' => self::get('DEFAULT_DELAY', '00:00:30'),
            'default_alert_offset' => self::get('DEFAULT_ALERT_OFFSET', '00:00:10'),
            'default_app_status' => self::getInt('DEFAULT_APP_STATUS', 0),
            'pill_slot_min' => self::getInt('PILL_SLOT_MIN', 1),
            'pill_slot_max' => self::getInt('PILL_SLOT_MAX', 7),
            'timing_id_min' => self::getInt('TIMING_ID_MIN', 1),
            'timing_id_max' => self::getInt('TIMING_ID_MAX', 7)
        ];
    }
    /**
     * ดึงการตั้งค่า Email SMTP
     */
    public static function getEmailConfig() {
        self::load();
        
        return [
            'enabled' => self::getBool('EMAIL_ENABLED', false),
            'host' => self::get('SMTP_HOST', 'smtp.gmail.com'),
            'port' => self::getInt('SMTP_PORT', 587),
            'username' => self::get('SMTP_USERNAME'),
            'password' => self::get('SMTP_PASSWORD'),
            'encryption' => self::get('SMTP_ENCRYPTION', 'tls'),
            'from_email' => self::get('SMTP_FROM_EMAIL'),
            'from_name' => self::get('SMTP_FROM_NAME', 'Pill Reminder System'),
            'timeout' => self::getInt('EMAIL_TIMEOUT', 30),
            'debug' => self::getBool('EMAIL_DEBUG', false)
        ];
    }
    /**
     * ดึงการตั้งค่า OTP
     */
    public static function getOtpConfig() {
        self::load();
        
        return [
            'enabled' => self::getBool('OTP_ENABLED', true),
            'length' => self::getInt('OTP_LENGTH', 6),
            'expiry_minutes' => self::getInt('OTP_EXPIRY_MINUTES', 15),
            'email_enabled' => self::getBool('OTP_EMAIL_ENABLED', true),
            'rate_limit_minutes' => self::getInt('OTP_RATE_LIMIT_MINUTES', 1), // ป้องกันสร้าง OTP บ่อยเกินไป
            'max_attempts' => self::getInt('OTP_MAX_ATTEMPTS', 5) // จำนวนครั้งที่ลองผิดได้
        ];
    }
}

// โหลดการตั้งค่าเมื่อมีการ include ไฟล์นี้
ConfigLoader::load();
?>