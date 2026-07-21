<?php
// 获取最新的系统公告

require_once 'config.php';
check_api_access();
require_once 'db.php';
require_once 'User.php';

header('Content-Type: application/json');

try {
    // 检查用户是否登录
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
    
    // 检查数据库连接
    if (!$conn) {
        echo json_encode(['success' => false, 'message' => '数据库连接失败']);
        exit;
    }
    
    // 获取最新的活跃公告
    $stmt = $conn->prepare("SELECT a.*, u.username as admin_name FROM announcements a 
                         JOIN users u ON a.admin_id = u.id 
                         WHERE a.is_active = TRUE 
                         ORDER BY a.created_at DESC 
                         LIMIT 1");
    $stmt->execute();
    $announcement = $stmt->fetch();
    
    if (!$announcement) {
        echo json_encode(['success' => true, 'has_new_announcement' => false]);
        exit;
    }
    
    $has_read = false;
    
    // 检查用户是否已经读过该公告
    if ($user_id) {
        $stmt = $conn->prepare("SELECT id FROM user_announcement_read WHERE user_id = ? AND announcement_id = ?");
        $stmt->execute([$user_id, $announcement['id']]);
        $has_read = $stmt->fetch() !== false;
    }
    
    // 输出公告信息
    echo json_encode([
        'success' => true,
        'has_new_announcement' => true,
        'announcement' => [
            'id' => $announcement['id'],
            'title' => $announcement['title'],
            'content' => $announcement['content'],
            'created_at' => $announcement['created_at'],
            'admin_name' => $announcement['admin_name']
        ],
        'has_read' => $has_read
    ]);
} catch (PDOException $e) {
    error_log("获取公告失败: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '获取公告失败']);
} catch (Exception $e) {
    error_log("获取公告异常: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '获取公告失败']);
}
?>