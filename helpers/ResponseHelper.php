<?php
/**
 * ResponseHelper - ปรับปรุงให้รองรับ data field ใน error response
 * ไฟล์: helpers/ResponseHelper.php
 */

require_once __DIR__ . '/../config/api_headers.php';

class ResponseHelper {
    
    /**
     * ส่ง Success Response
     */
    public static function success($data = null, $message = 'สำเร็จ', $cleanData = true) {
        if ($cleanData && $data !== null) {
            $data = self::cleanData($data);
        }
        
        sendSuccess($data, $message);
    }
    
    /**
     * ส่ง Error Response (รองรับ data field)
     * 
     * @param string $message ข้อความ error
     * @param int $statusCode HTTP status code
     * @param mixed $data ข้อมูลเพิ่มเติม (optional)
     */
    public static function error($message, $statusCode = 400, $data = null) {
        if ($data !== null) {
            //ใช้ sendError ที่รองรับ data field
            self::sendErrorWithData($message, $statusCode, $data);
        } else {
            // ใช้ sendError ของระบบเดิม
            sendError($message, $statusCode);
        }
    }
    
    /**
     * ส่ง Error Response พร้อม data field
     */
    private static function sendErrorWithData($message, $statusCode, $data) {
        setApiHeaders();
        http_response_code($statusCode);
        
        $response = [
            'status' => 'error',
            'message' => $message,
            'data' => $data
        ];
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit();
    }
    
    /**
     * ส่ง Validation Error
     */
    public static function validationError($message) {
        sendError($message, 422);
    }
    
    /**
     * ส่ง Unauthorized Error
     */
    public static function unauthorized($message = 'ไม่มีสิทธิ์เข้าถึง') {
        sendError($message, 401);
    }
    
    /**
     * ส่ง Not Found Error
     */
    public static function notFound($message = 'ไม่พบข้อมูล') {
        sendError($message, 404);
    }
    
    /**
     * ส่ง Server Error
     */
    public static function serverError($message = 'เกิดข้อผิดพลาดในระบบ') {
        sendError($message, 500);
    }
    
    // ... ฟังก์ชันอื่น ๆ เหมือนเดิม
    
    private static function cleanData($data) {
        if ($data === null || $data === false || empty($data)) {
            return $data;
        }
        
        $fieldsToRemove = ['user_id', 'connect_id'];
        
        if (is_array($data)) {
            if (isset($data[0]) && is_array($data[0])) {
                return array_map(function($item) use ($fieldsToRemove) {
                    return self::removeFields($item, $fieldsToRemove);
                }, $data);
            } else {
                return self::removeFields($data, $fieldsToRemove);
            }
        }
        
        return $data;
    }
    
    private static function removeFields($item, $fieldsToRemove) {
        if (!is_array($item)) {
            return $item;
        }
        
        foreach ($fieldsToRemove as $field) {
            unset($item[$field]);
        }
        
        return $item;
    }
}
?>