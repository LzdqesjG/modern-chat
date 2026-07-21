<?php
require_once 'security_check.php';
// 禁用错误显示，只记录到日�?
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
ini_set('error_log', 'error.log');

// 设置响应头为JSON
header('Content-Type: application/json; charset=utf-8');

// 开始会�?
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
    $group_id = isset($_POST['group_id']) ? intval($_POST['group_id']) : 0;

    // 验证数据
    if (!$group_id) {
        echo json_encode(['success' => false, 'message' => '群聊ID无效']);
        exit;
    }

    // 检查数据库连接
    if (!$conn) {
        echo json_encode(['success' => false, 'message' => '数据库连接失败']);
        exit;
    }

    // 创建Group实例
    $group = new Group($conn);

    // 发送入群申请
    $result = $group->sendJoinRequest($group_id, $user_id);

    if ($result) {
        echo json_encode(['success' => true, 'message' => '入群申请已发送，等待管理员审核']);
    } else {
        // 可能是已经加入，或者已经申请过
        if ($group->isUserInGroup($group_id, $user_id)) {
            echo json_encode(['success' => false, 'message' => '您已经是该群成员']);
        } else {
            echo json_encode(['success' => false, 'message' => '申请发送失败或已申请过']);
        }
    }

} catch (Exception $e) {
    // 捕获所有异常并返回错误信息
    $error_msg = "服务器内部错误: " . $e->getMessage();
    error_log($error_msg);
    echo json_encode(['success' => false, 'message' => $error_msg]);
}
?>