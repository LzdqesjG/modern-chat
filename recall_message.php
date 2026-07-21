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
    check_api_access();
    require_once 'db.php';
    require_once 'Message.php';
    require_once 'Group.php';
    require_once 'Friend.php';

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

    $user_id = $_SESSION['user_id'];
    $chat_type = isset($_POST['chat_type']) ? $_POST['chat_type'] : '';
    $message_id = isset($_POST['message_id']) ? intval($_POST['message_id']) : 0;
    $friend_id = isset($_POST['friend_id']) ? intval($_POST['friend_id']) : 0;
    $group_id = isset($_POST['group_id']) ? intval($_POST['group_id']) : 0;

    // 验证数据
    if (!$chat_type || !$message_id) {
        echo json_encode(['success' => false, 'message' => '缺少必要参数']);
        exit;
    }

    // 检查数据库连接
    if (!$conn) {
        echo json_encode(['success' => false, 'message' => '数据库连接失败']);
        exit;
    }

    $result = [];

    if ($chat_type === 'friend') {
        // 验证好友ID参数
        if (!$friend_id) {
            echo json_encode(['success' => false, 'message' => '缺少好友ID参数']);
            exit;
        }
        
        // 验证用户与好友的关系
        $friend = new Friend($conn);
        if (!$friend->areFriends($user_id, $friend_id)) {
            echo json_encode(['success' => false, 'message' => '您与该用户不是好友关系']);
            exit;
        }
        
        // 验证消息是否属于该好友会话
        $message = new Message($conn);
        if (!$message->isMessageInFriendChat($message_id, $user_id, $friend_id)) {
            echo json_encode(['success' => false, 'message' => '消息不属于该好友会话']);
            exit;
        }
        
        // 撤回好友消息
        $result = $message->recallMessage($message_id, $user_id);
    } elseif ($chat_type === 'group') {
        // 验证群聊ID参数
        if (!$group_id) {
            echo json_encode(['success' => false, 'message' => '缺少群聊ID参数']);
            exit;
        }
        
        // 验证用户是否是群成员
        $group = new Group($conn);
        if (!$group->isUserInGroup($group_id, $user_id)) {
            echo json_encode(['success' => false, 'message' => '您不是该群聊成员']);
            exit;
        }
        
        // 验证消息是否属于该群聊
        if (!$group->isMessageInGroup($message_id, $group_id)) {
            echo json_encode(['success' => false, 'message' => '消息不属于该群聊']);
            exit;
        }
        
        // 撤回群聊消息
        $result = $group->recallGroupMessage($message_id, $user_id);
    } else {
        $result = ['success' => false, 'message' => '无效的聊天类型'];
    }

    echo json_encode($result);
} catch (Exception $e) {
    // 捕获所有异常并返回错误信息
    $error_msg = "服务器内部错误: " . $e->getMessage();
    error_log($error_msg);
    echo json_encode(['success' => false, 'message' => $error_msg]);
}
?>