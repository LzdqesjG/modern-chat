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
if (!$data || !isset($data['new_name'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '请求参数错误']);
    exit;
}

require_once 'db.php';
require_once 'User.php';

$user = new User($conn);
$user_id = $_SESSION['user_id'];
$new_name = trim($data['new_name']);

// 检查名称长度
$user_name_max = getConfig('user_name_max', 12);
if (strlen($new_name) > $user_name_max) {
    echo json_encode(['success' => false, 'message' => "名称长度不能超过{$user_name_max}个字符"]);
    exit;
}

// 检查名称是否为空
if (empty($new_name)) {
    echo json_encode(['success' => false, 'message' => '名称不能为空']);
    exit;
}

// 检查名称是否与其他用户冲突
$stmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
$stmt->execute([$new_name, $user_id]);
if ($stmt->rowCount() > 0) {
    echo json_encode(['success' => false, 'message' => '名称不能与其他用户重名']);
    exit;
}

// 检查名称是否包含违禁词
// 这里可以根据需要添加违禁词检查逻辑
$forbidden_words = ['管理员', 'admin', '系统', 'system'];
foreach ($forbidden_words as $word) {
    if (stripos($new_name, $word) !== false) {
        echo json_encode(['success' => false, 'message' => '名称包含违禁词']);
        exit;
    }
}

// 更新名称
try {
    $stmt = $conn->prepare("UPDATE users SET username = ? WHERE id = ?");
    $stmt->execute([$new_name, $user_id]);
    
    // 更新会话中的用户名
    $_SESSION['username'] = $new_name;
    
    echo json_encode(['success' => true, 'message' => '名称修改成功']);
} catch (PDOException $e) {
    error_log("Update Name Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '名称修改失败，请稍后重试']);
}
