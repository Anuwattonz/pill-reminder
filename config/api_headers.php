<?php
/**
 * API Headers (ใช้ CORS settings จาก .env)
 */

require_once __DIR__ . '/config_loader.php';

/**
 * ตั้งค่า headers สำหรับ API
 */
function setApiHeaders() {
    header('Content-Type: application/json; charset=UTF-8');
    
    // CORS settings จาก .env
    $allowed_origins   = ConfigLoader::get('ALLOWED_ORIGINS', '*');
    $allow_credentials = ConfigLoader::get('CORS_ALLOW_CREDENTIALS', 'true') === 'true';
    $allowed_methods   = ConfigLoader::get('CORS_ALLOW_METHODS', 'GET,POST,PUT,DELETE,OPTIONS');
    $allowed_headers   = ConfigLoader::get('CORS_ALLOW_HEADERS', 'Content-Type,Authorization,X-Requested-With');
    
    header("Access-Control-Allow-Origin: $allowed_origins");
    header("Access-Control-Allow-Methods: $allowed_methods");
    header("Access-Control-Allow-Headers: $allowed_headers");
    
    if ($allow_credentials) {
        header('Access-Control-Allow-Credentials: true');
    }
    
    header('Cache-Control: no-cache');
}

/**
 * จัดการ OPTIONS request
 */
function handleOptions() {
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        setApiHeaders();
        http_response_code(200);
        exit();
    }
}

/**
 * ส่ง JSON response
 */
function sendJsonResponse($data, $status_code = 200) {
    setApiHeaders();
    http_response_code($status_code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit();
}

/**
 * ส่ง success response
 */
function sendSuccess($data = null, $message = 'success') {
    $response = [
        'status' => 'success',
        'message' => $message
    ];
    
    if ($data !== null) {
        $response['data'] = $data;
    }
    
    sendJsonResponse($response, 200);
}

/**
 * ส่ง error response  
 */
function sendError($message, $status_code = 400) {
    $response = [
        'status' => 'error',
        'message' => $message
    ];
    
    sendJsonResponse($response, $status_code);
}
?>
