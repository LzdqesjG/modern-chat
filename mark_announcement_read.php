<?php
// 标记公告为已读

require_once 'config.php';
check_api_access();
require_once 'db.php';
require_once 'User.php';

header('Content-Type: application/json');

try {
    // 检查用户是否登录
    session_start();
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => '用户未登录']);
        exit;
    }
    
    $user_id = $_SESSION['user_id'];
    
    // 检查请求方法
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => '无效的请求方法']);
        exit;
    }
    
    // 获取POST数据
    $data = json_decode(file_get_contents('php://input'), true);
    $announcement_id = isset($data['announcement_id']) ? (int)$data['announcement_id'] : 0;
    
    if (!$announcement_id) {
        echo json_encode(['success' => false, 'message' => '公告ID不能为空']);
        exit;
    }
    
    // 检查数据库连接
    if (!$conn) {
        echo json_encode(['success' => false, 'message' => '数据库连接失败']);
        exit;
    }
    
    // 检查公告是否存在
    $stmt = $conn->prepare("SELECT id FROM announcements WHERE id = ? AND is_active = TRUE");
    $stmt->execute([$announcement_id]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => '公告不存在或已失效']);
        exit;
    }
    
    // 标记公告为已读
    $stmt = $conn->prepare("INSERT IGNORE INTO user_announcement_read (user_id, announcement_id) VALUES (?, ?)");
    $result = $stmt->execute([$user_id, $announcement_id]);
    
    if ($result) {
        echo json_encode(['success' => true, 'message' => '公告已标记为已读']);
    } else {
        echo json_encode(['success' => false, 'message' => '标记失败']);
    }
} catch (PDOException $e) {
    error_log("标记公告为已读失败: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '标记失败']);
} catch (Exception $e) {
    error_log("标记公告为已读异常: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '标记失败']);
}
?>