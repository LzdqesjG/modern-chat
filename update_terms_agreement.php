<?php
// 确保会话已启动
if (!isset($_SESSION)) {
    session_start();
}

// 检查用户是否登录
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => '用户未登录']);
    exit;
}

// 检查请求方法
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => '无效的请求方法']);
    exit;
}

require_once 'db.php';

// 确保必要字段存在

try {
    // 检查users表是否有is_deleted字段
    $stmt = $conn->prepare("SHOW COLUMNS FROM users LIKE 'is_deleted'");
    $stmt->execute();
    $deleted_column_exists = $stmt->fetch();
    
    if (!$deleted_column_exists) {
        // 添加is_deleted字段
        $conn->exec("ALTER TABLE users ADD COLUMN is_deleted BOOLEAN DEFAULT FALSE AFTER is_admin");
        error_log("Added is_deleted column to users table");
    }
    
    // 检查users表是否有agreed_to_terms字段
    $stmt = $conn->prepare("SHOW COLUMNS FROM users LIKE 'agreed_to_terms'");
    $stmt->execute();
    $terms_column_exists = $stmt->fetch();
    
    if (!$terms_column_exists) {
        // 添加agreed_to_terms字段，记录用户是否同意协议
        $conn->exec("ALTER TABLE users ADD COLUMN agreed_to_terms BOOLEAN DEFAULT FALSE AFTER is_deleted");
        error_log("Added agreed_to_terms column to users table");
        
        // 将管理员用户设置为已同意协议
        $conn->exec("UPDATE users SET agreed_to_terms = TRUE WHERE is_admin = TRUE");
        error_log("Set admin users as agreed to terms");
    }
} catch (PDOException $e) {
    error_log("Field setup error: " . $e->getMessage());
}

require_once 'User.php';

// 创建User实例
$user = new User($conn);
$user_id = $_SESSION['user_id'];
$agreed = isset($_POST['agreed']) && $_POST['agreed'] === 'true';

// 更新协议同意状态
$result = $user->updateTermsAgreement($user_id, $agreed);

if ($result) {
    echo json_encode(['success' => true, 'message' => '协议同意状态已更新']);
} else {
    echo json_encode(['success' => false, 'message' => '更新协议同意状态失败']);
}
?>