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
require_once 'User.php';

// 创建User实例
$user = new User($conn);
$user_id = $_SESSION['user_id'];

// 注销账号
$result = $user->deleteUser($user_id);

if ($result) {
    // 销毁会话
    session_destroy();
    echo json_encode(['success' => true, 'message' => '账号已注销']);
} else {
    echo json_encode(['success' => false, 'message' => '注销账号失败']);
}
?>