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

    // 检查是否是POST请求
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => '无效的请求方法']);
        exit;
    }

    $user_id = $_SESSION['user_id'];
    $group_id = isset($_POST['group_id']) ? intval($_POST['group_id']) : 0;
    $friend_ids = isset($_POST['friend_ids']) ? explode(',', $_POST['friend_ids']) : [];

    // 验证数据
    if (!$group_id) {
        echo json_encode(['success' => false, 'message' => '请选择群聊']);
        exit;
    }

    if (empty($friend_ids)) {
        echo json_encode(['success' => false, 'message' => '请选择至少一位好友']);
        exit;
    }

    // 检查数据库连接
    if (!$conn) {
        echo json_encode(['success' => false, 'message' => '数据库连接失败']);
        exit;
    }

    // 创建Group实例
    $group = new Group($conn);

    // 检查用户是否是群聊管理员或群主
    $stmt = $conn->prepare("SELECT gm.is_admin, g.owner_id FROM group_members gm
                         JOIN `groups` g ON gm.group_id = g.id
                         WHERE gm.group_id = ? AND gm.user_id = ?");
    $stmt->execute([$group_id, $user_id]);
    $member_info = $stmt->fetch();

    if (!$member_info) {
        echo json_encode(['success' => false, 'message' => '您不是该群聊成员']);
        exit;
    }

    if (!$member_info['is_admin'] && $member_info['owner_id'] != $user_id) {
        echo json_encode(['success' => false, 'message' => '您没有权限添加成员到该群聊']);
        exit;
    }

    // 检查群聊现有成员数量
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM group_members WHERE group_id = ?");
    $stmt->execute([$group_id]);
    $current_count = $stmt->fetch()['count'];

    // 计算添加后的总成员数
    $total_count = $current_count + count($friend_ids);
    if ($total_count > 2000) {
        echo json_encode(['success' => false, 'message' => '群聊成员数量已达上限（2000人）']);
        exit;
    }

    // 验证 friend_ids 是否都是当前用户的好友
    require_once 'Friend.php';
    $friend = new Friend($conn);
    
    foreach ($friend_ids as $friend_id) {
        $friend_id = intval($friend_id);
        
        // 跳过自己
        if ($friend_id == $user_id) {
            continue;
        }
        
        // 检查是否为好友关系
        if (!$friend->areFriends($user_id, $friend_id)) {
            echo json_encode(['success' => false, 'message' => '只能添加好友到群组']);
            exit;
        }
    }

    // 开始事务
    $conn->beginTransaction();

    $added_count = 0;
    foreach ($friend_ids as $friend_id) {
        $friend_id = intval($friend_id);
        
        // 检查好友是否已经是群聊成员
        $stmt = $conn->prepare("SELECT id FROM group_members WHERE group_id = ? AND user_id = ?");
        $stmt->execute([$group_id, $friend_id]);
        if (!$stmt->fetch()) {
            // 添加好友到群聊
            $stmt = $conn->prepare("INSERT INTO group_members (group_id, user_id, is_admin) VALUES (?, ?, 0)");
            if ($stmt->execute([$group_id, $friend_id])) {
                $added_count++;
            }
        }
    }

    // 提交事务
    $conn->commit();

    echo json_encode(['success' => true, 'message' => '添加成员成功', 'added_count' => $added_count]);
} catch (Exception $e) {
    // 回滚事务
    $conn->rollBack();
    
    // 捕获所有异常并返回错误信息
    $error_msg = "服务器内部错误: " . $e->getMessage();
    error_log($error_msg);
    echo json_encode(['success' => false, 'message' => $error_msg]);
}
?>