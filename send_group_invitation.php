<?php
// 检查用户是否登录
require_once 'config.php';
require_once 'db.php';
require_once 'Group.php';
require_once 'Friend.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => '用户未登录']);
    exit;
}

$user_id = $_SESSION['user_id'];
$group_id = isset($_POST['group_id']) ? (int)$_POST['group_id'] : 0;
$friend_id = isset($_POST['friend_id']) ? (int)$_POST['friend_id'] : 0;

if (!$group_id || !$friend_id) {
    echo json_encode(['success' => false, 'message' => '参数无效']);
    exit;
}

// 创建Group实例
$group = new Group($conn);

// 检查用户是否是群成员
if (!$group->isUserInGroup($group_id, $user_id)) {
    echo json_encode(['success' => false, 'message' => '您不是该群聊的成员']);
    exit;
}

// 检查是否是好友关系
$friend = new Friend($conn);
if (!$friend->isFriend($user_id, $friend_id)) {
    echo json_encode(['success' => false, 'message' => '只能邀请好友加入群聊']);
    exit;
}

// 发送邀请
$result = $group->inviteFriendToGroup($group_id, $user_id, $friend_id);

if ($result) {
    echo json_encode(['success' => true, 'message' => '邀请已发送']);
} else {
    echo json_encode(['success' => false, 'message' => '发送邀请失败']);
}
?>
