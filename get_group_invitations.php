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

// 创建Group实例
$group = new Group($conn);

// 获取用户收到的群聊邀请
$invitations = $group->getGroupInvitations($user_id);

echo json_encode([
    'success' => true,
    'invitations' => $invitations
]);
?>
