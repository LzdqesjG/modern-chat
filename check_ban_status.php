<?php
require_once 'config.php';
check_api_access();
require_once 'db.php';
require_once 'User.php';

// 检查用户是否登录
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['banned' => false]);
    exit;
}

// 创建User实例
$user = new User($conn);
$user_id = $_SESSION['user_id'];

// 检查用户是否被封禁
$ban_info = $user->isBanned($user_id);

if ($ban_info) {
    echo json_encode([
        'banned' => true,
        'reason' => $ban_info['reason'],
        'expires_at' => $ban_info['expires_at']
    ]);
} else {
    echo json_encode(['banned' => false]);
}
?>