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
    $chat_id = isset($_POST['chat_id']) ? intval($_POST['chat_id']) : 0;

    // 验证数据
    if (!in_array($chat_type, ['friend', 'group']) || !$chat_id) {
        echo json_encode(['success' => false, 'message' => '无效的聊天类型或ID']);
        exit;
    }

    // 检查数据库连接
    if (!$conn) {
        echo json_encode(['success' => false, 'message' => '数据库连接失败']);
        exit;
    }

    // 检查当前免打扰状态
    $stmt = $conn->prepare("SELECT is_muted FROM chat_settings WHERE user_id = ? AND chat_type = ? AND chat_id = ?");
    $stmt->execute([$user_id, $chat_type, $chat_id]);
    $setting = $stmt->fetch();

    $is_muted = $setting ? !$setting['is_muted'] : true;

    if ($setting) {
        // 更新现有设置
        $stmt = $conn->prepare("UPDATE chat_settings SET is_muted = ? WHERE user_id = ? AND chat_type = ? AND chat_id = ?");
        $stmt->execute([$is_muted, $user_id, $chat_type, $chat_id]);
    } else {
        // 创建新设置
        $stmt = $conn->prepare("INSERT INTO chat_settings (user_id, chat_type, chat_id, is_muted) VALUES (?, ?, ?, ?)");
        $stmt->execute([$user_id, $chat_type, $chat_id, $is_muted]);
    }

    echo json_encode([
        'success' => true, 
        'message' => $is_muted ? '已开启免打扰' : '已关闭免打扰',
        'is_muted' => $is_muted
    ]);
} catch (Exception $e) {
    // 捕获所有异常并返回错误信息
    $error_msg = "服务器内部错误: " . $e->getMessage();
    error_log($error_msg);
    echo json_encode(['success' => false, 'message' => $error_msg]);
}
?>