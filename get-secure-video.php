<?php
// 安全视频访问脚本
// 生成带有一次性 Token 的视频 URL

require_once 'config.php';
require_once 'db.php';

// 检查用户是否登录
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => '未登录']);
    exit;
}

// 生成唯一的 Token
function generateToken() {
    return bin2hex(random_bytes(32));
}

// 验证 Token
function validateToken($token) {
    global $conn;
    
    try {
        $stmt = $conn->prepare("SELECT * FROM video_tokens WHERE token = ? AND expires_at > NOW() AND used = 0");
        $stmt->execute([$token]);
        $token_data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($token_data) {
            // 标记 Token 为已使用
            $stmt = $conn->prepare("UPDATE video_tokens SET used = 1 WHERE id = ?");
            $stmt->execute([$token_data['id']]);
            return $token_data;
        }
        return false;
    } catch (PDOException $e) {
        error_log("Token validation error: " . $e->getMessage());
        return false;
    }
}

// 创建视频 Token 表（如果不存在）
function createVideoTokensTable() {
    global $conn;
    
    try {
        $sql = "
        CREATE TABLE IF NOT EXISTS video_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token VARCHAR(64) NOT NULL UNIQUE,
            file_path VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            expires_at TIMESTAMP NOT NULL,
            used TINYINT(1) DEFAULT 0,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ";
        $conn->exec($sql);
    } catch (PDOException $e) {
        error_log("Create video tokens table error: " . $e->getMessage());
    }
}

// 清理过期的 Token
function cleanupExpiredTokens() {
    global $conn;
    
    try {
        $stmt = $conn->prepare("DELETE FROM video_tokens WHERE expires_at < NOW()");
        $stmt->execute();
    } catch (PDOException $e) {
        error_log("Cleanup expired tokens error: " . $e->getMessage());
    }
}

// 初始化
createVideoTokensTable();
cleanupExpiredTokens();

// 处理不同的请求类型
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // 生成带有 Token 的视频 URL
    if (isset($_GET['action']) && $_GET['action'] === 'get_token' && isset($_GET['file_path'])) {
        $file_path = $_GET['file_path'];
        $user_id = $_SESSION['user_id'];
        
        // 生成 Token
        $token = generateToken();
        
        // 设置过期时间（5分钟）
        $expires_at = date('Y-m-d H:i:s', time() + 300);
        
        try {
            // 存储 Token
            $stmt = $conn->prepare("INSERT INTO video_tokens (user_id, token, file_path, expires_at) VALUES (?, ?, ?, ?)");
            $stmt->execute([$user_id, $token, $file_path, $expires_at]);
            
            // 返回带有 Token 的 URL
            $secure_url = "get-secure-video.php?token={$token}";
            echo json_encode(['url' => $secure_url]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => '生成 Token 失败']);
        }
    } 
    // 生成带有 Token 的下载 URL（10分钟有效期）
    elseif (isset($_GET['action']) && $_GET['action'] === 'get_download_token' && isset($_GET['file_path'])) {
        $file_path = $_GET['file_path'];
        $user_id = $_SESSION['user_id'];
        
        // 生成 Token
        $token = generateToken();
        
        // 设置过期时间（10分钟）
        $expires_at = date('Y-m-d H:i:s', time() + 600);
        
        try {
            // 存储 Token
            $stmt = $conn->prepare("INSERT INTO video_tokens (user_id, token, file_path, expires_at) VALUES (?, ?, ?, ?)");
            $stmt->execute([$user_id, $token, $file_path, $expires_at]);
            
            // 返回带有 Token 的下载 URL
            $download_url = "get-secure-video.php?token={$token}&download=1";
            echo json_encode(['url' => $download_url]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => '生成下载 Token 失败']);
        }
    }
    // 验证 Token 并提供视频或下载
    elseif (isset($_GET['token'])) {
        $token = $_GET['token'];
        $is_download = isset($_GET['download']) && $_GET['download'] == 1;
        $token_data = validateToken($token);
        
        if ($token_data) {
            $file_path = $token_data['file_path'];
            
            // 检查文件是否存在
            if (file_exists($file_path)) {
                // 获取文件信息
                $file_info = pathinfo($file_path);
                $file_name = $file_info['basename'];
                $file_size = filesize($file_path);
                
                // 设置响应头
                header('Content-Description: File Transfer');
                header('Content-Type: video/' . $file_info['extension']);
                
                // 根据是否是下载请求设置不同的 Content-Disposition
                if ($is_download) {
                    header('Content-Disposition: attachment; filename="' . $file_name . '"');
                } else {
                    header('Content-Disposition: inline; filename="' . $file_name . '"');
                }
                
                header('Content-Transfer-Encoding: binary');
                header('Expires: 0');
                header('Cache-Control: must-revalidate');
                header('Pragma: public');
                header('Content-Length: ' . $file_size);
                
                // 输出文件内容
                readfile($file_path);
                exit;
            } else {
                http_response_code(404);
                echo json_encode(['error' => '文件不存在']);
            }
        } else {
            http_response_code(403);
            echo json_encode(['error' => '无效的 Token']);
        }
    } else {
        http_response_code(400);
        echo json_encode(['error' => '参数错误']);
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => '方法不允许']);
}
?>