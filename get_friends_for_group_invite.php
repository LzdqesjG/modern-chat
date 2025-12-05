<?php
// 检查用户是否登录
require_once 'config.php';
require_once 'db.php';
require_once 'Friend.php';
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

// 创建实例
$friend = new Friend($conn);
$group = new Group($conn);

// 检查用户是否是群成员
if (!$group->isUserInGroup($group_id, $user_id)) {
    echo json_encode(['success' => false, 'message' => '您不是该群聊的成员']);
    exit;
}

// 获取用户的所有好友
$friends = $friend->getFriends($user_id);
$friends_for_invite = [];

foreach ($friends as $friend_item) {
    // 检查好友是否已经在群聊中
    $in_group = $group->isUserInGroup($group_id, $friend_item['id']);
    
    $friends_for_invite[] = [
        'id' => $friend_item['id'],
        'username' => $friend_item['username'],
        'avatar' => $friend_item['avatar'],
        'status' => $friend_item['status'],
        'in_group' => $in_group
    ];
}

echo json_encode([
    'success' => true,
    'friends' => $friends_for_invite
]);
?>
