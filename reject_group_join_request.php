<?php
// 检查用户是否登录
require_once 'config.php';
check_api_access();
require_once 'db.php';
require_once 'Group.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => '用户未登录']);
    exit;
}

$user_id = $_SESSION['user_id'];
$request_id = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
$group_id = isset($_POST['group_id']) ? (int)$_POST['group_id'] : 0;

if (!$request_id || !$group_id) {
    echo json_encode(['success' => false, 'message' => '参数无效']);
    exit;
}

// 创建Group实例
$group = new Group($conn);

// 检查用户是否是群管理员或群主
$member_role = $group->getMemberRole($group_id, $user_id);
if (!$member_role) {
    echo json_encode(['success' => false, 'message' => '您不是该群聊的成员']);
    exit;
}

$is_admin_or_owner = $user_id == $member_role['owner_id'] || $member_role['is_admin'];
if (!$is_admin_or_owner) {
    echo json_encode(['success' => false, 'message' => '您没有权限拒绝入群申请']);
    exit;
}

// 拒绝入群申请
$result = $group->rejectJoinRequest($request_id, $group_id);

if ($result) {
    echo json_encode(['success' => true, 'message' => '入群申请已拒绝']);
} else {
    echo json_encode(['success' => false, 'message' => '拒绝入群申请失败']);
}
?>