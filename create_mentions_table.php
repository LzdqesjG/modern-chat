<?php
// 启用错误报告以便调试
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 设置错误日志
ini_set('error_log', 'error.log');

// 开始会话
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    require_once 'db.php';
    
    // 创建mentions表来存储@提醒
    $sql = "CREATE TABLE IF NOT EXISTS mentions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        message_id INT NOT NULL,
        message_type ENUM('friend', 'group') NOT NULL,
        mentioned_user_id INT NOT NULL,
        sender_id INT NOT NULL,
        is_read BOOLEAN DEFAULT FALSE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (mentioned_user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
    )";
    
    $conn->exec($sql);
    error_log("Created mentions table or already exists");
    
    // 创建unread_messages表来存储未读消息计数
    $sql = "CREATE TABLE IF NOT EXISTS unread_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        chat_type ENUM('friend', 'group') NOT NULL,
        chat_id INT NOT NULL,
        count INT DEFAULT 0,
        last_message_id INT DEFAULT 0,
        UNIQUE KEY unique_chat (user_id, chat_type, chat_id),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )";
    
    $conn->exec($sql);
    error_log("Created unread_messages table or already exists");
    
    echo "Tables created successfully!";
    
} catch (Exception $e) {
    // 捕获所有异常并返回错误信息
    $error_msg = "服务器内部错误: " . $e->getMessage();
    error_log($error_msg);
    echo $error_msg;
}
?>