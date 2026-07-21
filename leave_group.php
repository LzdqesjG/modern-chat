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
    $group_id = isset($_GET['group_id']) ? intval($_GET['group_id']) : 0;

    // 验证数据
    if (!$group_id) {
        echo json_encode(['success' => false, 'message' => '请选择群聊']);
        exit;
    }

    // 检查数据库连接
    if (!$conn) {
        echo json_encode(['success' => false, 'message' => '数据库连接失败']);
        exit;
    }

    // 创建Group实例
    $group = new Group($conn);

    // 获取群聊信息
    $group_info = $group->getGroupInfo($group_id);
    if (!$group_info) {
        echo json_encode(['success' => false, 'message' => '群聊不存在']);
        exit;
    }

    // 检查是否是群主
    if ($group_info['owner_id'] == $user_id) {
        echo json_encode(['success' => false, 'message' => '群主不能直接退出群聊，请先转让群主']);
        exit;
    }

    // 退出群聊
    $result = $group->leaveGroup($group_id, $user_id);
    if ($result) {
        echo json_encode(['success' => true, 'message' => '已成功退出群聊']);
    } else {
        echo json_encode(['success' => false, 'message' => '退出群聊失败']);
    }
} catch (Exception $e) {
    // 捕获所有异常并返回错误信息
    $error_msg = "服务器内部错误: " . $e->getMessage();
    error_log($error_msg);
    echo json_encode(['success' => false, 'message' => $error_msg]);
}
?>