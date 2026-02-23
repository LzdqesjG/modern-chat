<?php
// 开始会话
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';
require_once 'db.php';
require_once 'User.php';

// 检查请求方法
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 获取请求数据
    $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    
    // 检查是否提供了token
    if (!isset($data['token']) || empty($data['token'])) {
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '请提供退出登录token']);
        exit;
    }
    
    // 检查用户是否登录
    if (!isset($_SESSION['user_id'])) {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => '未登录或会话已过期']);
        exit;
    }
    
    $user_id = $_SESSION['user_id'];
    $token = $data['token'];
    
    // 创建User实例
    $user = new User($conn);
    
    // 验证token
    if (!$user->verifyLogoutToken($user_id, $token)) {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => '无效的退出登录token']);
        exit;
    }
    
    // 更新用户状态为离线
    $user->updateStatus($user_id, 'offline');
    
    // 只销毁当前会话，不影响其他用户
    session_unset();
    session_destroy();
    
    // 返回JSON响应
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => '退出登录成功']);
    exit;
} else {
    // 非POST请求，直接销毁会话并重定向
    if (isset($_SESSION['user_id'])) {
        $user_id = $_SESSION['user_id'];
        $user = new User($conn);
        $user->updateStatus($user_id, 'offline');
    }
    
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit;
}