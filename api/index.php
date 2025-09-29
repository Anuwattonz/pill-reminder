<?php
/**
 * Main RESTful Router (Updated with ESP32 endpoints)
 * ไฟล์: api/index.php
 * 
 * จัดการ routing สำหรับ RESTful API ทั้งหมด
 * 
 */


require_once '../config/api_headers.php';
require_once '../config/config_loader.php';
// Set headers
setApiHeaders();
handleOptions();

// Get request info
$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];

// Parse URL
$path = parse_url($uri, PHP_URL_PATH);
$path = urldecode($path);

// Remove base path
$system_config = ConfigLoader::getSystemConfig();
$basePath = $system_config['api_base_path'];
if (strpos($path, $basePath) === 0) {
    $path = substr($path, strlen($basePath));
}

$path = rtrim($path, '/');
if (empty($path)) {
    $path = '/';
}

// Split into segments
$segments = explode('/', trim($path, '/'));

try {
    // ✅ ถ้าเป็น root path ให้ส่ง empty response (ทางผ่าน)
    if (empty($segments[0]) || $path === '/') {
        http_response_code(200);
        exit();
    }

    // Main routing logic
    switch ($segments[0]) {
        
        case 'auth':
            handleAuthRoutes($segments, $method);
            break;

        // Medication routes
        case 'medications':
            handleMedicationRoutes($segments, $method);
            break;

        // Reminder routes
        case 'reminders':
            handleReminderRoutes($segments, $method);
            break;

        // History routes
        case 'history':
            handleHistoryRoutes($segments, $method);
            break;

        // Settings routes
        case 'settings':
            handleSettingsRoutes($segments, $method);
            break;

        // Device routes
        case 'devices':
            handleDeviceRoutes($segments, $method);
            break;

        // Dosage forms routes
        case 'dosage-forms':
            handleDosageFormRoutes($segments, $method);
            break;

        // ✅ ESP32 routes (ไม่ต้องเช็ค JWT)
        case 'esp32':
            handleESP32Routes($segments, $method);
            break;

        default:
            sendError('Endpoint not found', 404);
            break;
    }
    
} catch (Exception $e) {
    error_log("Router Error: " . $e->getMessage());
    sendError('Internal server error', 500);
}

/**
 * Handle Authentication Routes
 */
function handleAuthRoutes($segments, $method) {
    $action = $segments[1] ?? '';
    
    switch ($action) {
        case 'login':
            if ($method === 'POST') {
                require_once __DIR__ . '/../endpoints/auth/login.php';
            } else {
                sendError('Method not allowed', 405);
            }
            break;
            
        case 'register':
            if ($method === 'POST') {
                require_once __DIR__ . '/../endpoints/auth/register.php';
            } else {
                sendError('Method not allowed', 405);
            }
            break;
            
        case 'refresh':
            if ($method === 'POST') {
                require_once __DIR__ . '/../endpoints/auth/refresh.php';
            } else {
                sendError('Method not allowed', 405);
            }
            break;
        case 'otp':  
            if ($method === 'POST') {
                require_once __DIR__ . '/../endpoints/auth/otp.php';
            } else {
                sendError('Method not allowed', 405);
            }
            break;  
        case 'reset-password': 
            if ($method === 'POST') {
                require_once __DIR__ . '/../endpoints/auth/reset-password.php';
            } else {
                sendError('Method not allowed', 405);
            }
            break;    
        default:
            sendError('Auth endpoint not found', 404);
            break;
    }
}

/**
 * Handle Medication Routes
 */
function handleMedicationRoutes($segments, $method) {
    $id = $segments[1] ?? null;
    $action = $segments[2] ?? null;
    
    // Resource with ID and action
    if ($id && $action) {
        $_GET['medication_id'] = $id; // Pass ID to endpoint
        
        switch ($action) {
            case 'edit':
                if ($method === 'GET') {
                    require_once __DIR__ . '/../endpoints/medications/edit.php';
                } else {
                    sendError('Method not allowed', 405);
                }
                break;
                
            case 'timings':
                if ($method === 'PUT' || $method === 'POST') {
                    require_once __DIR__ . '/../endpoints/medications/timings.php';
                } else {
                    sendError('Method not allowed', 405);
                }
                break;
                
            default:
                sendError('Medication action not found', 404);
                break;
        }
    }
    // Resource with ID
    elseif ($id && is_numeric($id)) {
        $_GET['medication_id'] = $id; // Pass ID to endpoint
        
        switch ($method) {
            case 'GET':
                require_once __DIR__ . '/../endpoints/medications/show.php';
                break;
            case 'POST':
                require_once __DIR__ . '/../endpoints/medications/update.php';
                break;
            case 'DELETE':
                require_once __DIR__ . '/../endpoints/medications/delete.php';
                break;
            default:
                sendError('Method not allowed', 405);
                break;
        }
    }
    // Collection
    else {
        switch ($method) {
            case 'GET':
                require_once __DIR__ . '/../endpoints/medications/index.php';
                break;
            case 'POST':
                require_once __DIR__ . '/../endpoints/medications/create.php';
                break;
            default:
                sendError('Method not allowed', 405);
                break;
        }
    }
}

/**
 * Handle Reminder Routes
 */
function handleReminderRoutes($segments, $method) {
    $action = $segments[1] ?? '';
    $id = $segments[2] ?? null;
    
    switch ($action) {
        case '':
            if ($method === 'GET') {
                require_once __DIR__ . '/../endpoints/reminders/index.php';
            } else {
                sendError('Method not allowed', 405);
            }
            break;
            
        case 'slots':
            if ($method === 'GET') {
                require_once __DIR__ . '/../endpoints/reminders/slots.php';
            } else {
                sendError('Method not allowed', 405);
            }
            break;
            
        case 'update':
            if ($method === 'PUT') {
                require_once __DIR__ . '/../endpoints/reminders/update.php';
            } else {
                sendError('Method not allowed', 405);
            }
            break;
            
        case 'links':
            if ($method === 'GET') {
                require_once __DIR__ . '/../endpoints/reminders/links.php';
            } else {
                sendError('Method not allowed', 405);
            }
            break;
            
        case 'status':
            if ($method === 'PUT' || $method === 'POST') {
                require_once __DIR__ . '/../endpoints/reminders/status.php';
            } else {
                sendError('Method not allowed', 405);
            }
            break;
            
        default:
            sendError('Reminder endpoint not found', 404);
            break;
    }
}

/**
 * Handle History Routes
 */
function handleHistoryRoutes($segments, $method) {
    $id = $segments[1] ?? null;
    $action = $segments[1] ?? null;
    
    // ✅ เพิ่ม summary route
    if ($action === 'summary') {
        // GET /history/summary - ดึงสรุปสถิติการกินยา
        if ($method === 'GET') {
            require_once __DIR__ . '/../endpoints/history/summary.php';
        } else {
            sendError('Method not allowed', 405);
        }
        return;
    }
    
    if ($id && is_numeric($id)) {
        // GET /history/{id} - ดึงรายละเอียดประวัติ
        $_GET['history_id'] = $id;
        if ($method === 'GET') {
            require_once __DIR__ . '/../endpoints/history/detail.php';
        } else {
            sendError('Method not allowed', 405);
        }
    } else {
        // GET /history - ดึงรายการประวัติ
        if ($method === 'GET') {
            require_once __DIR__ . '/../endpoints/history/index.php';
        } else {
            sendError('Method not allowed', 405);
        }
    }
}

/**
 * Handle Settings Routes
 */
function handleSettingsRoutes($segments, $method) {
    $action = $segments[1] ?? '';
    
    switch ($action) {
        case '':
            if ($method === 'GET') {
                require_once __DIR__ . '/../endpoints/settings/index.php';
            } else {
                sendError('Method not allowed', 405);
            }
            break;
            
        case 'volume':
            if ($method === 'PUT' || $method === 'POST') {
                require_once __DIR__ . '/../endpoints/settings/volume.php';
            } else {
                sendError('Method not allowed', 405);
            }
            break;
            
        default:
            sendError('Settings endpoint not found', 404);
            break;
    }
}

/**
 * Handle Device Routes
 */
function handleDeviceRoutes($segments, $method) {
    $action = $segments[1] ?? '';
    
    switch ($action) {
        case 'connect':
            if ($method === 'POST') {
                require_once __DIR__ . '/../endpoints/devices/connect.php';
            } else {
                sendError('Method not allowed', 405);
            }
            break;
            
        case 'username':
            if ($method === 'PUT' || $method === 'POST') {
                require_once __DIR__ . '/../endpoints/devices/username.php';
            } else {
                sendError('Method not allowed', 405);
            }
            break;
            
        default:
            sendError('Device endpoint not found', 404);
            break;
    }
}

/**
 * Handle Dosage Form Routes
 */
function handleDosageFormRoutes($segments, $method) {
    if ($method === 'GET') {
        require_once '../endpoints/dosage-forms/index.php';
    } else {
        sendError('Method not allowed', 405);
    }
}

/**
 * ✅ Handle ESP32 Routes (ไม่ต้องเช็ค JWT)
 */
function handleESP32Routes($segments, $method) {
    $action = $segments[1] ?? '';
    
    switch ($action) {
        case 'schedule':
            // GET /esp32/schedule - ดึงตารางเวลาแจ้งเตือนสำหรับ ESP32
            if ($method === 'GET') {
                require_once __DIR__ . '/../endpoints/esp32/get.php';
            } else {
                sendError('Method not allowed', 405);
            }
            break;
            
        case 'record':
            // POST /esp32/record - บันทึกข้อมูลการรับประทานยาจาก ESP32
            if ($method === 'POST') {
                require_once __DIR__ . '/../endpoints/esp32/post.php';
            } else {
                sendError('Method not allowed', 405);
            }
            break;
            
        default:
            sendError('ESP32 endpoint not found', 404);
            break;
    }
}

/**
 * Helper function to validate numeric ID
 */
function isValidId($id) {
    return is_numeric($id) && $id > 0;
}