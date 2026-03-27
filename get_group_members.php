<?php
// 启用错误报告以便调试
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 设置错误日志
ini_set('error_log', 'error.log');

// 开始会话
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    require_once 'config.php';
    require_once 'db.php';
    require_once 'Group.php';
    
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
    $group_id = isset($_GET['group_id']) ? intval($_GET['group_id']) : 0;
    
    // 验证数据
    if ($group_id <= 0) {
        echo json_encode(['success' => false, 'message' => '无效的群聊ID']);
        exit;
    }
    
    // 检查数据库连接
    if (!$conn) {
        echo json_encode(['success' => false, 'message' => '数据库连接失败']);
        exit;
    }
    
    // 创建Group实例
    $group = new Group($conn);
    
    // 修复越权漏洞：检查用户是否是该群聊成员
    if (!$group->isUserInGroup($group_id, $user_id)) {
        echo json_encode(['success' => false, 'message' => '您不是该群聊成员，无权查看成员列表 ❌']);
        exit;
    }
    
    // 获取群聊信息
    $stmt = $conn->prepare("SELECT owner_id, all_user_group FROM groups WHERE id = ?");
    $stmt->execute([$group_id]);
    $group_info = $stmt->fetch();
    
    // 获取群聊成员列表
    $members = $group->getGroupMembers($group_id);
    
    // 获取当前用户在群中的角色
    $current_user_role = 'member';
    if ($group_info['owner_id'] == $user_id) {
        $current_user_role = 'Master';
    } else {
        // 检查是否是管理员
        foreach ($members as $member) {
            if (isset($member['id']) && $member['id'] == $user_id) {
                if (isset($member['role'])) {
                    $current_user_role = $member['role'];
                } elseif (isset($member['is_admin']) && $member['is_admin']) {
                    $current_user_role = 'admin';
                }
                break;
            }
        }
    }
    
    // 处理成员数据，只返回需要的字段
    $result = [];
    foreach ($members as $member) {
        if (isset($member['id'])) {
            // 检查是否是好友
            $stmt = $conn->prepare("SELECT status FROM friends 
                                   WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)");
            $stmt->execute([$user_id, $member['id'], $member['id'], $user_id]);
            $friendship = $stmt->fetch();
            $friendship_status = $friendship ? $friendship['status'] : 'none';
            
            $result[] = [
                'id' => $member['id'],
                'username' => $member['username'] ?? '',
                'nickname' => $member['nickname'] ?? '',
                'avatar' => $member['avatar'] ?? '',
                'role' => $member['role'] ?? 'member',
                'status' => $member['status'] ?? '',
                'last_active' => $member['last_active'] ?? '',
                'friendship_status' => $friendship_status
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'members' => $result,
        'group_owner_id' => $group_info['owner_id'],
        'all_user_group' => $group_info['all_user_group'],
        'current_user_id' => $user_id,
        'current_user_role' => $current_user_role
    ]);
} catch (Exception $e) {
    // 捕获所有异常并返回错误信息
    $error_msg = "服务器内部错误: " . $e->getMessage();
    error_log($error_msg);
    echo json_encode(['success' => false, 'message' => $error_msg]);
}