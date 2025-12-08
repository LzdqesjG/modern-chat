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
    require_once 'db.php';
    
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
    $chat_type = isset($_POST['chat_type']) ? $_POST['chat_type'] : 'friend';
    $chat_id = isset($_POST['chat_id']) ? intval($_POST['chat_id']) : 0;
    
    if (!$chat_id) {
        echo json_encode(['success' => false, 'message' => '请提供聊天ID']);
        exit;
    }
    
    // 清除未读消息计数
    $stmt = $conn->prepare("DELETE FROM unread_messages WHERE user_id = ? AND chat_type = ? AND chat_id = ?");
    $stmt->execute([$user_id, $chat_type, $chat_id]);
    
    // 更新消息状态为已读
    if ($chat_type === 'friend') {
        $stmt = $conn->prepare("UPDATE messages SET status = 'read' WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)");
        $stmt->execute([$chat_id, $user_id, $user_id, $chat_id]);
    } else {
        // 群聊消息不需要更新状态，因为群聊消息没有已读状态
    }
    
    echo json_encode(['success' => true, 'message' => '消息已标记为已读']);
    
} catch (Exception $e) {
    // 捕获所有异常并返回错误信息
    $error_msg = "服务器内部错误: " . $e->getMessage();
    error_log($error_msg);
    echo json_encode(['success' => false, 'message' => $error_msg]);
}
?>