<?php
require_once 'config.php';

class Database {
    private $host;
    private $dbname;
    private $username;
    private $password;
    private $conn;
    
    public function __construct() {
        $this->host = DB_HOST;
        $this->dbname = DB_NAME;
        $this->username = DB_USER;
        $this->password = DB_PASS;
        $this->conn = null;
    }
    
    public function connect() {
        $max_retries = 5;
        $retry_interval = 2; // 秒
        
        for ($i = 1; $i <= $max_retries; $i++) {
            try {
                $this->conn = new PDO(
                    "mysql:host={$this->host};dbname={$this->dbname};charset=utf8mb4",
                    $this->username,
                    $this->password,
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false
                    ]
                );
                // error_log("Database connected successfully on attempt $i");
                return $this->conn;
            } catch(PDOException $e) {
                error_log("Connection attempt $i failed: " . $e->getMessage());
                
                if ($i < $max_retries) {
                    error_log("Retrying in $retry_interval seconds...");
                    sleep((int)$retry_interval);
                    $retry_interval *= 1.5; // 指数退避
                } else {
                    error_log("All $max_retries connection attempts failed");
                    // return null; // 不要返回null，抛出异常
                    throw new Exception("Database connection failed after $max_retries attempts: " . $e->getMessage());
                }
            }
        }
    }
    
    public function disconnect() {
        $this->conn = null;
    }
    
    public function getConnection() {
        return $this->conn;
    }
}

// 创建数据库实例
$db = new Database();
try {
    $conn = $db->connect();
} catch (Exception $e) {
    error_log("Global database connection error: " . $e->getMessage());
    // 这里可以选择停止脚本执行，或者让后续代码处理（例如register_process.php中的try-catch）
    // 为了兼容现有代码，我们让$conn为null，但记录错误
    $conn = null;
}

// 数据库连接失败时不输出任何内容，API文件会处理连接错误
// 移除die语句，避免返回HTML错误