<?php
require_once 'config.php';
require_once 'db.php';
check_api_access();

// 检查用户是否登录
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => '用户未登录']);
    exit;
}

$username = isset($_GET['username']) ? trim($_GET['username']) : '';

if (empty($username)) {
    echo json_encode(['success' => false, 'message' => '请输入用户名']);
    exit;
}

// 查询用户ID
$stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
$stmt->execute([$username]);
$user = $stmt->fetch();

if ($user) {
    echo json_encode(['success' => true, 'user_id' => $user['id']]);
} else {
    echo json_encode(['success' => false, 'message' => '未找到该用户']);
}

exit;