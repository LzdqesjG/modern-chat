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
    require_once 'config.php';
    require_once 'db.php';
    require_once 'Group.php';

    // 检查用户是否登录
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => '用户未登录']);
        exit;
    }

    // 检查是否是POST请求
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => '无效的请求方法']);
        exit;
    }

    // 获取请求数据
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) {
        echo json_encode(['success' => false, 'message' => '无效的请求数据']);
        exit;
    }

    $user_id = $_SESSION['user_id'];
    $group_name = isset($data['name']) ? trim($data['name']) : '';
    $member_ids = isset($data['member_ids']) ? $data['member_ids'] : [];

    // 验证数据
    if (!$group_name) {
        echo json_encode(['success' => false, 'message' => '请输入群聊名称']);
        exit;
    }

    if (empty($member_ids)) {
        echo json_encode(['success' => false, 'message' => '请选择至少一个好友']);
        exit;
    }

    // 检查数据库连接
    if (!$conn) {
        echo json_encode(['success' => false, 'message' => '数据库连接失败']);
        exit;
    }

    // 验证 member_ids 是否都是当前用户的好友
    require_once 'Friend.php';
    $friend = new Friend($conn);
    
    foreach ($member_ids as $member_id) {
        // 检查是否为有效用户ID
        if (!is_int($member_id) && !is_numeric($member_id)) {
            echo json_encode(['success' => false, 'message' => '无效的成员ID']);
            exit;
        }
        
        // 跳过自己
        if ($member_id == $user_id) {
            continue;
        }
        
        // 检查是否为好友关系
        if (!$friend->areFriends($user_id, $member_id)) {
            echo json_encode(['success' => false, 'message' => '只能添加好友到群组']);
            exit;
        }
    }

    // 创建Group实例
    $group = new Group($conn);

    // 创建群聊
    $group_id = $group->createGroup($user_id, $group_name, $member_ids);

    if ($group_id) {
        echo json_encode(['success' => true, 'message' => '群聊创建成功', 'group_id' => $group_id]);
    } else {
        echo json_encode(['success' => false, 'message' => '群聊创建失败']);
    }
} catch (Exception $e) {
    // 捕获所有异常并返回错误信息
    $error_msg = "服务器内部错误: " . $e->getMessage();
    error_log($error_msg);
    echo json_encode(['success' => false, 'message' => $error_msg]);
}
?>