<?php
// 开始会话
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    require_once 'config.php';
    require_once 'db.php';
    
    // 检查用户是否登录
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => '用户未登录']);
        exit;
    }
    
    // 检查是否是GET请求
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        echo json_encode(['success' => false, 'message' => '无效的请求方法']);
        exit;
    }
    
    $user_id = $_SESSION['user_id'];
    $friend_id = isset($_GET['friend_id']) ? intval($_GET['friend_id']) : 0;
    
    // 验证数据
    if ($friend_id <= 0) {
        echo json_encode(['success' => false, 'message' => '无效的用户ID']);
        exit;
    }
    
    // 检查是否是自己
    if ($user_id == $friend_id) {
        echo json_encode(['success' => true, 'is_friend' => false]);
        exit;
    }
    
    // 查询好友关系
    $stmt = $conn->prepare("SELECT * FROM friends 
                           WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)");
    $stmt->execute([$user_id, $friend_id, $friend_id, $user_id]);
    $friendship = $stmt->fetch();
    
    // 检查是否是好友关系
    $is_friend = ($friendship !== false && $friendship['status'] === 'accepted');
    
    echo json_encode([
        'success' => true,
        'is_friend' => $is_friend
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => '服务器内部错误']);
}
?>