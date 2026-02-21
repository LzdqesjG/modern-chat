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
    require_once 'User.php';
    require_once 'Group.php';

    // 检查用户是否登录
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => '用户未登录']);
        exit;
    }

    // 检查是否是GET请求
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        echo json_encode(['success' => false, 'message' => '无效的请求方法']);
        exit;
    }

    $user_id = $_SESSION['user_id'];
    $group_id = isset($_GET['group_id']) ? intval($_GET['group_id']) : 0;

    // 检查数据库连接
    if (!$conn) {
        echo json_encode(['success' => false, 'message' => '数据库连接失败']);
        exit;
    }

    // 创建实例
    $user = new User($conn);
    $group = new Group($conn);

    // 获取用户的好友列表（只选择非敏感字段）
    $stmt = $conn->prepare("SELECT u.id, u.username, u.email, u.avatar, u.status, u.last_active, 
                                  u.is_admin, u.created_at, u.phone
                         FROM users u
                         JOIN friends f ON u.id = f.friend_id
                         WHERE f.user_id = ? AND f.status = 'accepted' AND u.id != ?");
    $stmt->execute([$user_id, $user_id]);
    $friends = $stmt->fetchAll();

    // 初始化可用好友数组
    $available_friends = $friends;

    // 如果提供了group_id，则过滤出不在群聊中的好友
    if ($group_id) {
        // 检查用户是否是群聊成员
        $stmt = $conn->prepare("SELECT id FROM group_members WHERE group_id = ? AND user_id = ?");
        $stmt->execute([$group_id, $user_id]);
        if (!$stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => '您不是该群聊成员']);
            exit;
        }

        // 获取群聊现有成员ID列表
        $stmt = $conn->prepare("SELECT user_id FROM group_members WHERE group_id = ?");
        $stmt->execute([$group_id]);
        $group_members = $stmt->fetchAll();
        $group_member_ids = array_column($group_members, 'user_id');

        // 过滤出不在群聊中的好友
        $available_friends = array_filter($friends, function($friend) use ($group_member_ids) {
            return !in_array($friend['id'], $group_member_ids);
        });
    }

    echo json_encode(['success' => true, 'friends' => array_values($available_friends)]);
} catch (Exception $e) {
    // 捕获所有异常并返回错误信息
    $error_msg = "服务器内部错误: " . $e->getMessage();
    error_log($error_msg);
    echo json_encode(['success' => false, 'message' => $error_msg]);
}
?>