<?php
// 获取封禁记录
header('Content-Type: application/json');

require_once 'config.php';
check_api_access();
require_once 'db.php';
require_once 'User.php';

// 确保会话已启动
if (!isset($_SESSION)) {
    session_start();
}

// 检查用户是否登录
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => '无法获取，你没有权限！']);
    exit;
}

// 获取当前用户信息
$user = new User($conn);
$current_user = $user->getUserById($_SESSION['user_id']);

// 检查用户是否是管理员，或者用户名是Admin且邮箱以admin@开头
if (!$current_user['is_admin'] && !($current_user['username'] === 'Admin' && strpos($current_user['email'], 'admin@') === 0)) {
    echo json_encode(['success' => false, 'message' => '无法获取，你没有权限！']);
    exit;
}

$type = isset($_GET['type']) ? $_GET['type'] : '';
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (empty($type) || $id <= 0) {
    echo json_encode(['success' => false, 'message' => '参数错误']);
    exit;
}

try {
    $records = [];
    
    if ($type === 'user') {
        // 获取用户封禁记录
        $stmt = $conn->prepare("SELECT b.id, b.user_id, b.banned_by, u1.username as banned_by_name, b.reason, b.ban_start, b.ban_end, b.status, bl.action, bl.action_time 
                             FROM bans b 
                             LEFT JOIN ban_logs bl ON b.id = bl.ban_id 
                             LEFT JOIN users u1 ON b.banned_by = u1.id 
                             WHERE b.user_id = ? 
                             ORDER BY bl.action_time DESC");
        $stmt->execute([$id]);
        $user_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($user_records as $record) {
            $records[] = [
                'action' => $record['action'],
                'reason' => $record['reason'],
                'banned_by' => $record['banned_by_name'] || '系统',
                'ban_start' => $record['ban_start'],
                'ban_end' => $record['ban_end'],
                'action_time' => $record['action_time']
            ];
        }
    } elseif ($type === 'group') {
        // 获取群聊封禁记录
        $stmt = $conn->prepare("SELECT gb.id, gb.group_id, gb.banned_by, u1.username as banned_by_name, gb.reason, gb.ban_start, gb.ban_end, gb.status, gbl.action, gbl.action_time 
                             FROM group_bans gb 
                             LEFT JOIN group_ban_logs gbl ON gb.id = gbl.ban_id 
                             LEFT JOIN users u1 ON gb.banned_by = u1.id 
                             WHERE gb.group_id = ? 
                             ORDER BY gbl.action_time DESC");
        $stmt->execute([$id]);
        $group_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($group_records as $record) {
            $records[] = [
                'action' => $record['action'],
                'reason' => $record['reason'],
                'banned_by' => $record['banned_by_name'] || '系统',
                'ban_start' => $record['ban_start'],
                'ban_end' => $record['ban_end'],
                'action_time' => $record['action_time']
            ];
        }
    } else {
        echo json_encode(['success' => false, 'message' => '无效的类型']);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'records' => $records
    ]);
} catch (PDOException $e) {
    error_log('Get ban records error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '获取封禁记录失败']);
}
?>