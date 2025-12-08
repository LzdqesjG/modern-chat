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
    require_once 'Message.php';
require_once 'FileUpload.php';
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

    $user_id = $_SESSION['user_id'];
    $chat_type = isset($_POST['chat_type']) ? $_POST['chat_type'] : 'friend'; // 'friend' 或 'group'
    $selected_id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $friend_id = isset($_POST['friend_id']) ? intval($_POST['friend_id']) : 0;
    $message_text = isset($_POST['message']) ? trim($_POST['message']) : '';

    // 验证数据
    if (($chat_type === 'friend' && !$friend_id) || ($chat_type === 'group' && !$selected_id)) {
        echo json_encode(['success' => false, 'message' => '请选择聊天对象']);
        exit;
    }

    // 检查数据库连接
    if (!$conn) {
        echo json_encode(['success' => false, 'message' => '数据库连接失败']);
        exit;
    }

    // 创建实例
    $message = new Message($conn);
    $fileUpload = new FileUpload($conn);
    $group = new Group($conn);

    // 添加调试信息
    error_log("Send Message Request: user_id=$user_id, chat_type=$chat_type, selected_id=$selected_id");
    error_log("Message Text: '$message_text'");

    // 处理文件上传
    $file_result = null;

    // 检查是否有文件上传
    if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        error_log("File Info: " . print_r($_FILES['file'], true));
        
        // 调用文件上传方法
        $file_result = $fileUpload->upload($_FILES['file'], $user_id);
        error_log("File Upload Result: " . print_r($file_result, true));
    }

    // 检查消息内容是否包含HTML标签
        function containsHtmlTags($text) {
            return preg_match('/<[^>]*>/', $text);
        }
        
        // 发送消息
        if ($chat_type === 'friend') {
            // 好友消息
            if ($file_result && $file_result['success']) {
                // 发送文件消息
                $result = $message->sendFileMessage(
                    $user_id,
                    $friend_id,
                    $file_result['file_path'],
                    $file_result['file_name'],
                    $file_result['file_size']
                );
                error_log("Send File Message Result: " . print_r($result, true));
            } else if ($message_text) {
                // 检查消息是否包含HTML标签
                if (containsHtmlTags($message_text)) {
                    echo json_encode(['success' => false, 'message' => '消息中不能包含HTML标签']);
                    exit;
                }
                // 发送文本消息
                $result = $message->sendTextMessage($user_id, $friend_id, $message_text);
                error_log("Send Text Message Result: " . print_r($result, true));
            } else {
                echo json_encode(['success' => false, 'message' => '请输入消息内容或选择文件']);
                exit;
            }
        } else {
            // 群聊消息
            if ($file_result && $file_result['success']) {
                // 发送文件消息
                $file_info = [
                    'file_path' => $file_result['file_path'],
                    'file_name' => $file_result['file_name'],
                    'file_size' => $file_result['file_size'],
                    'file_type' => $file_result['file_type']
                ];
                $result = $group->sendGroupMessage($selected_id, $user_id, '', $file_info);
                error_log("Send Group File Message Result: " . print_r($result, true));
            } else if ($message_text) {
                // 检查消息是否包含HTML标签
                if (containsHtmlTags($message_text)) {
                    echo json_encode(['success' => false, 'message' => '消息中不能包含HTML标签']);
                    exit;
                }
                // 发送文本消息
                $result = $group->sendGroupMessage($selected_id, $user_id, $message_text);
                error_log("Send Group Text Message Result: " . print_r($result, true));
            } else {
                echo json_encode(['success' => false, 'message' => '请输入消息内容或选择文件']);
                exit;
            }
        }

    if ($result['success']) {
        // 获取完整的消息信息
        if ($chat_type === 'friend') {
            // 获取好友消息
            $stmt = $conn->prepare("SELECT * FROM messages WHERE id = ?");
            $stmt->execute([$result['message_id']]);
        } else {
            // 获取群聊消息
            $stmt = $conn->prepare("SELECT gm.*, u.username, u.avatar FROM group_messages gm JOIN users u ON gm.sender_id = u.id WHERE gm.id = ?");
            $stmt->execute([$result['message_id']]);
        }
        $sent_message = $stmt->fetch();
        
        error_log("Sent Message: " . print_r($sent_message, true));
        
        echo json_encode([
            'success' => true,
            'message' => $sent_message
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => $result['message']]);
    }
} catch (Exception $e) {
    // 捕获所有异常并返回错误信息
    $error_msg = "服务器内部错误: " . $e->getMessage();
    error_log($error_msg);
    echo json_encode(['success' => false, 'message' => $error_msg]);
}