<?php
/**
 * Database Connection (Simplified Version)
 */

require_once __DIR__ . '/config_loader.php';

/**
 * สร้างการเชื่อมต่อฐานข้อมูล
 */
function getConnection() {
    static $connection = null;
    
    if ($connection !== null) {
        return $connection;
    }
    
    $config = ConfigLoader::getDatabaseConfig();
    
    try {
        $dsn = "mysql:host={$config['host']};dbname={$config['name']};charset=utf8mb4";
        
        $connection = new PDO($dsn, $config['user'], $config['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        
        return $connection;
        
    } catch(PDOException $e) {
        error_log("Database connection failed: " . $e->getMessage());
        return null;
    }
}
?>