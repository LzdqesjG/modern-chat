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
    $new_owner_id = isset($_GET['new_owner_id']) ? intval($_GET['new_owner_id']) : 0;

    // 验证数据
    if (!$group_id || !$new_owner_id) {
        echo json_encode(['success' => false, 'message' => '请选择群聊和新群主']);
        exit;
    }
    
    // 禁止转让给自己
    if ($new_owner_id == $user_id) {
        echo json_encode(['success' => false, 'message' => '不能将群聊转让给自己']);
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

    // 检查是否是当前群主
    if ($group_info['owner_id'] != $user_id) {
        echo json_encode(['success' => false, 'message' => '只有群主可以转让群主']);
        exit;
    }

    // 检查新群主是否是群成员
    $member_role = $group->getMemberRole($group_id, $new_owner_id);
    if (!$member_role) {
        echo json_encode(['success' => false, 'message' => '新群主不是该群聊的成员']);
        exit;
    }

    // 转让群主
    $result = $group->transferOwnership($group_id, $user_id, $new_owner_id);
    if ($result) {
        echo json_encode(['success' => true, 'message' => '群主已成功转让']);
    } else {
        echo json_encode(['success' => false, 'message' => '转让群主失败']);
    }
} catch (Exception $e) {
    // 捕获所有异常并返回错误信息
    $error_msg = "服务器内部错误: " . $e->getMessage();
    error_log($error_msg);
    echo json_encode(['success' => false, 'message' => $error_msg]);
}
?>