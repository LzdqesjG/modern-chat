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

    $user_id = $_SESSION['user_id'];
    $group_id = isset($_GET['group_id']) ? intval($_GET['group_id']) : 0;
    $member_id = isset($_GET['member_id']) ? intval($_GET['member_id']) : 0;
    $is_admin = isset($_GET['is_admin']) ? intval($_GET['is_admin']) : 0;

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

    // 检查当前用户是否是群主
    $is_owner = $current_member_role['owner_id'] == $user_id;
    if (!$is_owner) {
        echo json_encode(['success' => false, 'message' => '只有群主可以设置管理员']);
        exit;
    }

    // 获取要设置的成员的角色
    $target_member_role = $group->getMemberRole($group_id, $member_id);
    if (!$target_member_role) {
        echo json_encode(['success' => false, 'message' => '目标成员不是该群聊的成员']);
        exit;
    }

    // 检查目标成员是否是群主
    $target_is_owner = $target_member_role['owner_id'] == $member_id;
    if ($target_is_owner) {
        echo json_encode(['success' => false, 'message' => '群主不能被设置为管理员']);
        exit;
    }

    // 设置或取消管理员
    $result = $group->setAdmin($group_id, $member_id, $user_id, $is_admin);
    if ($result['success']) {
        $action = $is_admin ? '设置为管理员' : '取消管理员';
        echo json_encode(['success' => true, 'message' => "成员已成功$action"]);
    } else {
        echo json_encode(['success' => false, 'message' => $result['message']]);
    }
} catch (Exception $e) {
    // 捕获所有异常并返回错误信息
    $error_msg = "服务器内部错误: " . $e->getMessage();
    error_log($error_msg);
    echo json_encode(['success' => false, 'message' => $error_msg]);
}
?>