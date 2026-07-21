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
$invitation_id = isset($_POST['invitation_id']) ? (int)$_POST['invitation_id'] : 0;

if (!$invitation_id) {
    echo json_encode(['success' => false, 'message' => '邀请ID无效']);
    exit;
}

// 创建Group实例
$group = new Group($conn);

// 接受邀请
$result = $group->acceptGroupInvitation($invitation_id, $user_id);

echo json_encode($result);
?>