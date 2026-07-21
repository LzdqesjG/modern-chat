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
$group_id = isset($_GET['group_id']) ? (int)$_GET['group_id'] : 0;

if (!$group_id) {
    echo json_encode(['success' => false, 'message' => '群聊ID无效']);
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
    echo json_encode(['success' => false, 'message' => '您没有权限查看入群申请']);
    exit;
}

// 获取入群申请
$join_requests = $group->getJoinRequests($group_id);

echo json_encode([
    'success' => true,
    'join_requests' => $join_requests
]);
?>
