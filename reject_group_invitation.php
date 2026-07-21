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

// 拒绝邀请
$result = $group->rejectGroupInvitation($invitation_id, $user_id);

if ($result) {
    echo json_encode(['success' => true, 'message' => '邀请已拒绝']);
} else {
    echo json_encode(['success' => false, 'message' => '拒绝邀请失败']);
}
?>