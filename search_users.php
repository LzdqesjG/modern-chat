<?php
// 开始会话
session_start();
require_once 'config.php';
check_api_access();
require_once 'db.php';
require_once 'User.php';

// 检查用户是否登录
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => '用户未登录']);
    exit;
}

$user_id = $_SESSION['user_id'];
$search_term = isset($_GET['q']) ? trim($_GET['q']) : '';

if (empty($search_term)) {
    echo json_encode(['success' => false, 'message' => '请输入搜索关键词']);
    exit;
}

// 禁止传递包含特定字符和文件路径
$forbidden_chars = ['@', '#', '^', '.', ',', '<', '>', '\\', '/'];
$forbidden_path = __DIR__ . '\search_users.php';

// 检查是否包含禁止的字符
foreach ($forbidden_chars as $char) {
    if (strpos($search_term, $char) !== false) {
        echo json_encode(['success' => false, 'message' => '搜索关键词包含禁止的字符']);
        exit;
    }
}

// 检查是否包含禁止的文件路径
if (strpos($search_term, $forbidden_path) !== false) {
    echo json_encode(['success' => false, 'message' => '搜索关键词包含禁止的文件路径']);
    exit;
}

// 创建User实例
$user = new User($conn);

// 搜索用户
$user_results = $user->searchUsers($search_term, $user_id);

// 获取用户与搜索结果的关系状态
foreach ($user_results as $key => $result) {
    // 检查是否已经是好友
    $stmt = $conn->prepare(
        "SELECT status FROM friends 
         WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)"
    );
    $stmt->execute([$user_id, $result['id'], $result['id'], $user_id]);
    $friendship = $stmt->fetch();
    
    if ($friendship) {
        $user_results[$key]['friendship_status'] = $friendship['status'];
    } else {
        $user_results[$key]['friendship_status'] = 'none';
    }
    
    // 获取用户状态
    $stmt = $conn->prepare("SELECT status FROM users WHERE id = ?");
    $stmt->execute([$result['id']]);
    $user_status = $stmt->fetch();
    $user_results[$key]['status'] = $user_status['status'] ?? 'offline';
}

// 搜索当前用户加入的群聊
$group_results = [];
$stmt = $conn->prepare(
    "SELECT g.id, g.name, COUNT(gm.id) as member_count 
     FROM groups g 
     JOIN group_members gm ON g.id = gm.group_id 
     WHERE gm.user_id = ? AND g.name LIKE ? 
     GROUP BY g.id, g.name 
     ORDER BY g.name ASC"
);
$stmt->execute([$user_id, "%$search_term%"]);
$group_results = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'users' => $user_results,
    'groups' => $group_results
]);