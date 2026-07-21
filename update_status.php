<?php
require_once 'config.php';
check_api_access();
require_once 'db.php';
require_once 'User.php';

// 检查用户是否登录
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => '用户未登录']);
    exit;
}

$user_id = $_SESSION['user_id'];
$status = isset($_POST['status']) ? $_POST['status'] : 'online';

// 验证状态值
$allowed_statuses = ['online', 'offline', 'away'];
if (!in_array($status, $allowed_statuses)) {
    echo json_encode(['success' => false, 'message' => '无效的状态值']);
    exit;
}

// 创建User实例
$user = new User($conn);

// 更新状态
$result = $user->updateStatus($user_id, $status);

if ($result) {
    echo json_encode(['success' => true, 'message' => '状态更新成功']);
} else {
    echo json_encode(['success' => false, 'message' => '状态更新失败']);
}