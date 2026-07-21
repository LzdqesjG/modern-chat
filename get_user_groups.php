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

$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : $_SESSION['user_id'];

// 创建Group实例
$group = new Group($conn);

// 获取用户的群聊列表
$groups = $group->getUserGroups($user_id);

// 处理群聊数据
$groups_data = [];
foreach ($groups as $group_item) {
    $groups_data[] = [
        'id' => $group_item['id'],
        'name' => $group_item['name'],
        'member_count' => $group_item['member_count'],
        'owner_id' => $group_item['owner_id'],
        'created_at' => $group_item['created_at']
    ];
}

echo json_encode([
    'success' => true,
    'groups' => $groups_data
]);
?>