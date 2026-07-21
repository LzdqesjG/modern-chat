<?php
// 检查系统维护模式
require_once 'config.php';
check_api_access();
if (getConfig('System_Maintenance', 0) == 1) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => '系统维护中，请稍后重试']);
    exit;
}

// 检查用户是否登录
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '请先登录']);
    exit;
}

// 检查请求方法
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '方法不允许']);
    exit;
}

// 获取请求数据
$data = json_decode(file_get_contents('php://input'), true);
if (!$data || !isset($data['old_password']) || !isset($data['new_password'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '请求参数错误']);
    exit;
}

require_once 'db.php';
require_once 'User.php';

$user = new User($conn);
$user_id = $_SESSION['user_id'];

// 获取当前用户信息
$current_user = $user->getUserById($user_id);
if (!$current_user) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => '用户不存在']);
    exit;
}

// 验证原密码
if (!password_verify($data['old_password'], $current_user['password'])) {
    echo json_encode(['success' => false, 'message' => '原密码不正确']);
    exit;
}

// 哈希新密码
$hashed_password = password_hash($data['new_password'], PASSWORD_DEFAULT, ['cost' => 12]);

// 更新密码
try {
    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
    $stmt->execute([$hashed_password, $user_id]);
    echo json_encode(['success' => true, 'message' => '密码修改成功']);
} catch (PDOException $e) {
    error_log("Update Password Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '密码修改失败，请稍后重试']);
}
