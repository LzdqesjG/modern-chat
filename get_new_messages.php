<?php
// 禁用错误显示，只记录到日志
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
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
    require_once 'Message.php';

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
    $friend_id = isset($_GET['friend_id']) ? intval($_GET['friend_id']) : 0;
    $last_message_id = isset($_GET['last_message_id']) ? intval($_GET['last_message_id']) : 0;

    // 验证数据
    if (!$friend_id) {
        echo json_encode(['success' => false, 'message' => '请选择好友']);
        exit;
    }

    // 检查数据库连接
    if (!$conn) {
        echo json_encode(['success' => false, 'message' => '数据库连接失败']);
        exit;
    }

    // 创建Message实例
    $message = new Message($conn);

    // 验证用户是否有权限访问这些消息
    // 检查是否是好友关系
    $friend = new Friend($conn);
    if (!$friend->isFriend($user_id, $friend_id)) {
        echo json_encode(['success' => false, 'message' => '您不是该用户的好友，无权查看消息']);
        exit;
    }

    // 获取新消息
    $stmt = $conn->prepare(
        "SELECT m.*, u.username as sender_username, u.avatar 
         FROM messages m 
         JOIN users u ON m.sender_id = u.id
         WHERE ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?)) 
         AND m.id > ? 
         ORDER BY m.created_at ASC"
    );
    $stmt->execute([$user_id, $friend_id, $friend_id, $user_id, $last_message_id]);
    $new_messages = $stmt->fetchAll();

    // 更新消息状态为已读
    if (!empty($new_messages)) {
        $message_ids = array_column($new_messages, 'id');
        $message->markAsRead($message_ids);
    }

    // 检查好友聊天是否被免打扰
    $is_muted = false;
    $stmt = $conn->prepare("SELECT is_muted FROM chat_settings WHERE user_id = ? AND chat_type = 'friend' AND chat_id = ?");
    $stmt->execute([$user_id, $friend_id]);
    $setting = $stmt->fetch();
    if ($setting) {
        $is_muted = $setting['is_muted'];
    }

    echo json_encode([
        'success' => true,
        'messages' => $new_messages,
        'is_muted' => $is_muted
    ]);
} catch (Exception $e) {
    // 捕获所有异常并返回错误信息
    $error_msg = "服务器内部错误: " . $e->getMessage();
    error_log($error_msg);
    echo json_encode(['success' => false, 'message' => $error_msg]);
}
?>