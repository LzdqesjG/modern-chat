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
if (!$data || !isset($data['new_email'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '请求参数错误']);
    exit;
}

require_once 'db.php';
require_once 'User.php';

$user = new User($conn);
$user_id = $_SESSION['user_id'];
$new_email = trim($data['new_email']);

// 检查邮箱格式
$email_regex = '/^[^\s@]+@[^\s@]+\.[^\s@]+$/';
if (!preg_match($email_regex, $new_email)) {
    echo json_encode(['success' => false, 'message' => '请输入有效的邮箱格式']);
    exit;
}

// 检查邮箱是否与其他用户冲突
$stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
$stmt->execute([$new_email, $user_id]);
if ($stmt->rowCount() > 0) {
    echo json_encode(['success' => false, 'message' => '该邮箱已被其他用户使用']);
    exit;
}

// 更新邮箱
try {
    $stmt = $conn->prepare("UPDATE users SET email = ? WHERE id = ?");
    $stmt->execute([$new_email, $user_id]);
    echo json_encode(['success' => true, 'message' => '邮箱修改成功']);
} catch (PDOException $e) {
    error_log("Update Email Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '邮箱修改失败，请稍后重试']);
}
