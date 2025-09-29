<?php
/**
 * Upload Configuration (แก้ไข constants เป็น variables + เพิ่ม ESP32 Pictures Support)
 * ใช้ MAX_IMAGE_WIDTH และ MAX_IMAGE_HEIGHT จาก config
 * เพิ่มฟังก์ชันสำหรับ ESP32 pictures/ folder
 */

require_once __DIR__ . '/config_loader.php';

// โหลดการตั้งค่า
$upload_config = ConfigLoader::getUploadConfig();

define('UPLOAD_BASE_URL', $upload_config['base_url']);
define('UPLOAD_DIR_PATH', $upload_config['dir_path']);
define('MAX_UPLOAD_SIZE', $upload_config['max_size']);
define('MAX_IMAGE_WIDTH', $upload_config['max_width']);
define('MAX_IMAGE_HEIGHT', $upload_config['max_height']);

// แก้ไข: เช็คและใช้ค่าเริ่มต้นถ้าเป็น null หรือไม่ใช่ array
$ALLOWED_EXTENSIONS = is_array($upload_config['allowed_extensions']) 
    ? $upload_config['allowed_extensions'] 
    : ['jpg', 'jpeg', 'png'];

$ALLOWED_MIME_TYPES = is_array($upload_config['allowed_mime_types']) 
    ? $upload_config['allowed_mime_types'] 
    : ['image/jpeg', 'image/png'];

// เพิ่มใหม่: ESP32 Pictures Configuration
define('PICTURES_DIR_PATH', __DIR__ . '/../pictures/');

/**
 * สร้าง URL รูปภาพ (สำหรับไฟล์ใหม่ที่อัพโหลดผ่าน upload system)
 */
function getImageUrl($filename) {
    return empty($filename) ? null : UPLOAD_BASE_URL . basename($filename);
}

/**
 * สร้าง URL รูปภาพ (สำหรับไฟล์เก่าที่อยู่ใน pictures/)
 * เพิ่มใหม่สำหรับรูปภาพเก่าที่อยู่ในโฟลเดอร์ pictures/
 */
function getPictureUrl($filename) {
    if (empty($filename)) {
        return null;
    }
    
    // สร้าง base URL สำหรับ pictures/ โดยใช้ server path เดียวกัน
    $base_url = str_replace('/uploads/', '/pictures/', UPLOAD_BASE_URL);
    return $base_url . basename($filename);
}

/**
 * ใหม่: ตรวจสอบไฟล์รูปภาพสำหรับ ESP32 (ใช้การตรวจสอบจาก config)
 */
function validateESP32ImageData($base64_data, $filename = null) {
    global $ALLOWED_EXTENSIONS;
    
    // ตรวจสอบข้อมูล base64
    $binary_data = base64_decode($base64_data);
    if ($binary_data === false || strlen($binary_data) < 100) {
        return [
            'valid' => false, 
            'error' => 'ข้อมูลรูปภาพไม่ถูกต้องหรือเล็กเกินไป (ต้องมากกว่า 100 bytes)'
        ];
    }
    
    // ตรวจสอบขนาดไฟล์
    if (strlen($binary_data) > MAX_UPLOAD_SIZE) {
        $mb = number_format(MAX_UPLOAD_SIZE / 1024 / 1024, 1);
        return [
            'valid' => false, 
            'error' => "ขนาดไฟล์ต้องไม่เกิน {$mb}MB"
        ];
    }
    
    // ตรวจสอบนามสกุลไฟล์ถ้ามี
    if ($filename) {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($extension, $ALLOWED_EXTENSIONS)) {
            return [
                'valid' => false, 
                'error' => 'อนุญาตเฉพาะไฟล์: ' . implode(', ', $ALLOWED_EXTENSIONS)
            ];
        }
    }
    
    return [
        'valid' => true, 
        'binary_data' => $binary_data,
        'size_bytes' => strlen($binary_data),
        'extension' => $filename ? pathinfo($filename, PATHINFO_EXTENSION) : 'jpg'
    ];
}

/**
 * ใหม่: จัดการอัพโหลดรูปภาพจาก ESP32 ไปยัง pictures/
 */
function handleESP32ImageUpload($base64_data, $slot_number, $custom_filename = null) {
    // ตรวจสอบว่า directory มีอยู่จริง
    if (!is_dir(PICTURES_DIR_PATH)) {
        return [
            'success' => false,
            'filename' => null,
            'error' => 'ไม่พบโฟลเดอร์ pictures/',
            'url' => null
        ];
    }
    
    // สร้างชื่อไฟล์
    if ($custom_filename && !empty(trim($custom_filename))) {
        $image_filename = basename(trim($custom_filename)); // ป้องกัน path traversal
    } else {
        $timestamp = date('Ymd_His');
        $image_filename = 'pill_slot' . $slot_number . '_' . $timestamp . '.jpg';
    }
    
    // ตรวจสอบข้อมูลรูปภาพ
    $validation = validateESP32ImageData($base64_data, $image_filename);
    if (!$validation['valid']) {
        return [
            'success' => false,
            'filename' => null,
            'error' => $validation['error'],
            'url' => null
        ];
    }
    
    // บันทึกไฟล์
    $image_path = PICTURES_DIR_PATH . $image_filename;
    $bytes_written = file_put_contents($image_path, $validation['binary_data']);
    
    if ($bytes_written !== false && $bytes_written > 100) {
        chmod($image_path, 0644);
        
        return [
            'success' => true,
            'filename' => $image_filename,
            'error' => null,
            'url' => getPictureUrl($image_filename),
            'size_bytes' => $bytes_written,
            'path' => 'pictures/' . $image_filename,
            'validation_passed' => true
        ];
    }
    
    return [
        'success' => false,
        'filename' => null,
        'error' => 'การบันทึกไฟล์ล้มเหลว',
        'url' => null
    ];
}

/**
 * ใหม่: ลบรูปภาพใน pictures/
 */
function deletePictureImage($filename) {
    if (empty($filename)) {
        return true;
    }
    
    $filename = basename($filename);
    $file_path = PICTURES_DIR_PATH . $filename;
    
    if (file_exists($file_path)) {
        return unlink($file_path);
    }
    
    return true;
}

/**
 * ลบไฟล์รูปภาพเก่า (uploads/)
 */
function deleteOldImage($filename) {
    if (empty($filename)) {
        return true;
    }
    
    $filename = basename($filename);
    $file_path = UPLOAD_DIR_PATH . $filename;
    
    if (file_exists($file_path)) {
        return unlink($file_path);
    }
    
    return true;
}

/**
 * ปรับขนาดรูปภาพ (ใช้ MAX_IMAGE_WIDTH และ MAX_IMAGE_HEIGHT)
 */
function resizeImageIfNeeded($source_path, $destination_path) {
    $image_info = getimagesize($source_path);
    if (!$image_info) {
        return false;
    }
    
    $width = $image_info[0];
    $height = $image_info[1];
    $mime_type = $image_info['mime'];
    
    // ถ้ารูปภาพเล็กกว่าขนาดที่กำหนด ไม่ต้อง resize
    if ($width <= MAX_IMAGE_WIDTH && $height <= MAX_IMAGE_HEIGHT) {
        return copy($source_path, $destination_path);
    }
    
    // คำนวณขนาดใหม่โดยรักษาอัตราส่วน
    $ratio = min(MAX_IMAGE_WIDTH / $width, MAX_IMAGE_HEIGHT / $height);
    $new_width = (int)($width * $ratio);
    $new_height = (int)($height * $ratio);
    
    // สร้างรูปภาพ
    switch ($mime_type) {
        case 'image/jpeg':
            $source_image = imagecreatefromjpeg($source_path);
            break;
        case 'image/png':
            $source_image = imagecreatefrompng($source_path);
            break;
        default:
            return false;
    }
    
    if (!$source_image) {
        return false;
    }
    
    $new_image = imagecreatetruecolor($new_width, $new_height);
    
    // รักษาความโปร่งใสสำหรับ PNG
    if ($mime_type === 'image/png') {
        imagealphablending($new_image, false);
        imagesavealpha($new_image, true);
        $transparent = imagecolorallocatealpha($new_image, 255, 255, 255, 127);
        imagefill($new_image, 0, 0, $transparent);
    }
    
    imagecopyresampled($new_image, $source_image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
    
    // บันทึกรูปภาพใหม่
    switch ($mime_type) {
        case 'image/jpeg':
            $result = imagejpeg($new_image, $destination_path, 85);
            break;
        case 'image/png':
            $result = imagepng($new_image, $destination_path, 6);
            break;
        default:
            $result = false;
    }
    
    imagedestroy($source_image);
    imagedestroy($new_image);
    
    return $result;
}

/**
 * ตรวจสอบไฟล์รูปภาพ (uploads/)
 */
function validateImageFile($file) {
    // แก้ไข: ใช้ global variables และเช็คว่าเป็น array
    global $ALLOWED_EXTENSIONS, $ALLOWED_MIME_TYPES;
    
    // เพิ่ม: เช็คว่าตัวแปรเป็น array หรือไม่ ถ้าไม่ใช่ให้ใช้ค่าเริ่มต้น
    if (!is_array($ALLOWED_EXTENSIONS)) {
        $ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png'];
    }
    
    if (!is_array($ALLOWED_MIME_TYPES)) {
        $ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png'];
    }
    
    // ตรวจสอบ error
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['valid' => false, 'error' => 'เกิดข้อผิดพลาดในการอัพโหลด'];
    }
    
    // ตรวจสอบขนาด
    if ($file['size'] > MAX_UPLOAD_SIZE) {
        $mb = number_format(MAX_UPLOAD_SIZE / 1024 / 1024, 1);
        return ['valid' => false, 'error' => "ขนาดไฟล์ต้องไม่เกิน {$mb}MB"];
    }
    
    // ตรวจสอบประเภทไฟล์
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime_type = $finfo->file($file['tmp_name']);
    
    // แก้ไข: ใช้ตัวแปรแทน constant และเช็คว่าเป็น array
    if (!in_array($mime_type, $ALLOWED_MIME_TYPES)) {
        return ['valid' => false, 'error' => 'อนุญาตเฉพาะไฟล์ JPG และ PNG'];
    }
    
    // ตรวจสอบนามสกุล
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    // แก้ไข: ใช้ตัวแปรแทน constant และเช็คว่าเป็น array
    if (!in_array($extension, $ALLOWED_EXTENSIONS)) {
        return ['valid' => false, 'error' => 'อนุญาตเฉพาะไฟล์ jpg, jpeg, png'];
    }
    
    return ['valid' => true, 'extension' => $extension];
}

/**
 * อัพโหลดรูปภาพ (ปรับขนาดอัตโนมัติ) - สำหรับ uploads/
 */
function handleImageUpload($file, $prefix = 'image', $old_filename = null) {
    $validation = validateImageFile($file);
    if (!$validation['valid']) {
        return [
            'success' => false,
            'filename' => null,
            'error' => $validation['error'],
            'url' => null
        ];
    }
    
    // ตรวจสอบว่า upload directory มีอยู่จริง
    if (!is_dir(UPLOAD_DIR_PATH)) {
        return [
            'success' => false,
            'filename' => null,
            'error' => 'ไม่พบโฟลเดอร์อัพโหลด',
            'url' => null
        ];
    }
    
    // สร้างชื่อไฟล์ใหม่
    $filename = $prefix . '_' . time() . '_' . uniqid() . '.' . $validation['extension'];
    $upload_path = UPLOAD_DIR_PATH . $filename;
    
    // ใช้ resizeImageIfNeeded แทน move_uploaded_file
    if (resizeImageIfNeeded($file['tmp_name'], $upload_path)) {
        chmod($upload_path, 0644);
        
        // ลบไฟล์เก่าถ้ามี
        if ($old_filename && $old_filename !== $filename) {
            deleteOldImage($old_filename);
        }
        
        return [
            'success' => true,
            'filename' => $filename,
            'error' => null,
            'url' => getImageUrl($filename)
        ];
    }
    
    return [
        'success' => false,
        'filename' => null,
        'error' => 'การอัพโหลดไฟล์ล้มเหลว',
        'url' => null
    ];
}