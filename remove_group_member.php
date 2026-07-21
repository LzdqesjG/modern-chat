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
    $member_id = isset($_GET['member_id']) ? intval($_GET['member_id']) : 0;

    // 验证数据
    if (!$group_id || !$member_id) {
        echo json_encode(['success' => false, 'message' => '请选择群聊和成员']);
        exit;
    }

    // 检查数据库连接
    if (!$conn) {
        echo json_encode(['success' => false, 'message' => '数据库连接失败']);
        exit;
    }

    // 创建Group实例
    $group = new Group($conn);

    // 获取当前用户的角色
    $current_member_role = $group->getMemberRole($group_id, $user_id);
    if (!$current_member_role) {
        echo json_encode(['success' => false, 'message' => '您不是该群聊的成员']);
        exit;
    }

    // 获取要踢出的成员的角色
    $target_member_role = $group->getMemberRole($group_id, $member_id);
    if (!$target_member_role) {
        echo json_encode(['success' => false, 'message' => '目标成员不是该群聊的成员']);
        exit;
    }

    // 检查当前用户是否有权限踢出成员
    $is_owner = $current_member_role['owner_id'] == $user_id;
    $is_admin = $current_member_role['is_admin'];
    $target_is_owner = $target_member_role['owner_id'] == $member_id;
    $target_is_admin = $target_member_role['is_admin'];

    if (!$is_owner && !$is_admin) {
        echo json_encode(['success' => false, 'message' => '您没有权限踢出群聊成员']);
        exit;
    }

    if ($target_is_owner) {
        echo json_encode(['success' => false, 'message' => '不能踢出群主']);
        exit;
    }

    if (!$is_owner && $target_is_admin) {
        echo json_encode(['success' => false, 'message' => '只有群主可以踢出管理员']);
        exit;
    }

    // 踢出成员
    $stmt = $conn->prepare("DELETE FROM group_members WHERE group_id = ? AND user_id = ?");
    if ($stmt->execute([$group_id, $member_id])) {
        echo json_encode(['success' => true, 'message' => '成员已成功踢出群聊']);
    } else {
        echo json_encode(['success' => false, 'message' => '踢出成员失败']);
    }
} catch (Exception $e) {
    // 捕获所有异常并返回错误信息
    $error_msg = "服务器内部错误: " . $e->getMessage();
    error_log($error_msg);
    echo json_encode(['success' => false, 'message' => $error_msg]);
}
?>