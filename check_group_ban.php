<?php
// 检查群聊是否被封禁
header('Content-Type: application/json');

require_once 'config.php';
check_api_access();
require_once 'db.php';

$group_id = isset($_GET['group_id']) ? intval($_GET['group_id']) : 0;

if ($group_id <= 0) {
    echo json_encode(['banned' => false]);
    exit;
}

try {
    // 检查群聊是否存在
    $stmt = $conn->prepare("SELECT name FROM groups WHERE id = ?");
    $stmt->execute([$group_id]);
    $group = $stmt->fetch();
    
    if (!$group) {
        echo json_encode(['banned' => false]);
        exit;
    }
    
    // 检查群聊是否被封禁
        $stmt = $conn->prepare("SELECT id, reason, ban_end FROM group_bans WHERE group_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$group_id]);
        $ban_info = $stmt->fetch();
        
        if ($ban_info) {
            // 检查封禁是否已过期
            if ($ban_info['ban_end'] && strtotime($ban_info['ban_end']) < time()) {
                // 更新封禁状态为过期
                $stmt = $conn->prepare("UPDATE group_bans SET status = 'expired' WHERE id = ?");
                $stmt->execute([$ban_info['id']]);
                
                // 插入过期日志
                $stmt = $conn->prepare("INSERT INTO group_ban_logs (ban_id, action, action_by) VALUES (?, 'expire', NULL)");
                $stmt->execute([$ban_info['id']]);
                
                echo json_encode(['banned' => false]);
            } else {
                echo json_encode([
                    'banned' => true,
                    'group_name' => $group['name'],
                    'reason' => $ban_info['reason'],
                    'ban_end' => $ban_info['ban_end']
                ]);
            }
        } else {
            echo json_encode(['banned' => false]);
        }
} catch (PDOException $e) {
    error_log('Check group ban error: ' . $e->getMessage());
    echo json_encode(['banned' => false]);
}
?>