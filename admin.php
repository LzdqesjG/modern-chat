<?php
// 启用错误报告以便调试
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 设置错误日志
ini_set('error_log', 'error.log');

// 确保会话已启动
if (!isset($_SESSION)) {
    session_start();
}

// 检查用户是否是管理员
require_once 'config.php';
require_once 'db.php';

// 检查用户是否登录
// if (!isset($_SESSION['user_id'])) {
//     header('Location: login.php');
//     exit;
// }

// 确保必要字段存在

try {
    // 检查users表是否有is_admin字段
    $stmt = $conn->prepare("SHOW COLUMNS FROM users LIKE 'is_admin'");
    $stmt->execute();
    $column_exists = $stmt->fetch();
    
    if (!$column_exists) {
        // 添加is_admin字段
        $conn->exec("ALTER TABLE users ADD COLUMN is_admin BOOLEAN DEFAULT FALSE AFTER status");
        error_log("Added is_admin column to users table");
    }
    
    // 检查users表是否有is_deleted字段
    $stmt = $conn->prepare("SHOW COLUMNS FROM users LIKE 'is_deleted'");
    $stmt->execute();
    $deleted_column_exists = $stmt->fetch();
    
    if (!$deleted_column_exists) {
        // 添加is_deleted字段
        $conn->exec("ALTER TABLE users ADD COLUMN is_deleted BOOLEAN DEFAULT FALSE AFTER is_admin");
        error_log("Added is_deleted column to users table");
    }
    
    // 检查users表是否有agreed_to_terms字段
    $stmt = $conn->prepare("SHOW COLUMNS FROM users LIKE 'agreed_to_terms'");
    $stmt->execute();
    $terms_column_exists = $stmt->fetch();
    
    if (!$terms_column_exists) {
        // 添加agreed_to_terms字段，记录用户是否同意协议
        $conn->exec("ALTER TABLE users ADD COLUMN agreed_to_terms BOOLEAN DEFAULT FALSE AFTER is_deleted");
        error_log("Added agreed_to_terms column to users table");
    }
    
    // 将第一个用户设置为管理员
    $conn->exec("UPDATE users SET is_admin = TRUE WHERE id = 1");
    error_log("Set first user as admin");
    
    // 将管理员用户设置为已同意协议
    $conn->exec("UPDATE users SET agreed_to_terms = TRUE WHERE is_admin = TRUE");
    error_log("Set admin users as agreed to terms");
} catch (PDOException $e) {
    error_log("Admin setup error: " . $e->getMessage());
    echo "<div style='background: #ff4757; color: white; padding: 10px; border-radius: 5px; margin-bottom: 20px;'>";
    echo "数据库初始化错误：" . $e->getMessage();
    echo "</div>";
}

require_once 'User.php';
require_once 'Group.php';
require_once 'Message.php';

// 创建实例
$user = new User($conn);
$group = new Group($conn);
$message = new Message($conn);

// 获取当前用户信息
$current_user = $user->getUserById($_SESSION['user_id']);

// 检查用户是否是管理员，或者用户名是Admin且邮箱以admin@开头
if (!$current_user['is_admin'] && !($current_user['username'] === 'Admin' && strpos($current_user['email'], 'admin@') === 0)) {
    header('Location: login.php?error=权限不足，请先登录管理员账号。');
    exit;
}

// 直接获取所有群聊，不依赖Group类的getAllGroups()方法
try {
    $stmt = $conn->prepare("SELECT g.*, 
                                        u1.username as creator_username, 
                                        u2.username as owner_username,
                                        (SELECT COUNT(*) FROM group_members WHERE group_id = g.id) as member_count
                                 FROM groups g
                                 JOIN users u1 ON g.creator_id = u1.id
                                 JOIN users u2 ON g.owner_id = u2.id
                                 ORDER BY g.created_at DESC");
    $stmt->execute();
    $all_groups = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Get All Groups Error: " . $e->getMessage());
    $all_groups = [];
}

// 直接获取所有用户，不依赖User类的getAllUsers()方法
try {
    $stmt = $conn->prepare("SELECT * FROM users ORDER BY created_at DESC");
    $stmt->execute();
    $all_users = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Get All Users Error: " . $e->getMessage());
    $all_users = [];
}

// 直接获取所有群聊消息，不依赖Group类的getAllGroupMessages()方法
try {
    $stmt = $conn->prepare("SELECT gm.*, 
                                        u.username as sender_username,
                                        g.name as group_name
                                 FROM group_messages gm
                                 JOIN users u ON gm.sender_id = u.id
                                 JOIN groups g ON gm.group_id = g.id
                                 ORDER BY gm.created_at DESC
                                 LIMIT 1000"); // 限制1000条消息
    $stmt->execute();
    $all_group_messages = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Get All Group Messages Error: " . $e->getMessage());
    $all_group_messages = [];
}

// 直接获取所有好友消息，不依赖Message类的getAllFriendMessages()方法
try {
    $stmt = $conn->prepare("SELECT m.*, 
                                        u1.username as sender_username, 
                                        u2.username as receiver_username
                                 FROM messages m
                                 JOIN users u1 ON m.sender_id = u1.id
                                 JOIN users u2 ON m.receiver_id = u2.id
                                 ORDER BY m.created_at DESC
                                 LIMIT 1000"); // 限制1000条消息
    $stmt->execute();
    $all_friend_messages = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Get All Friend Messages Error: " . $e->getMessage());
    $all_friend_messages = [];
}

// 解散群聊 - 已合并到下面的统一处理逻辑中

// 验证管理员密码
function validateAdminPassword($password, $current_user, $conn) {
    // 获取当前管理员的密码哈希
    $sql = "SELECT password FROM users WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$current_user['id']]);
    $user = $stmt->fetch();
    
    if ($user && password_verify($password, $user['password'])) {
        return true;
    }
    return false;
}

// 处理管理员密码验证请求（AJAX）
if (isset($_POST['action']) && $_POST['action'] === 'validate_admin_password') {
    header('Content-Type: application/json');
    
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $isValid = validateAdminPassword($password, $current_user, $conn);
    
    echo json_encode(['valid' => $isValid]);
    exit;
}

// 处理所有需要密码验证的操作
if (isset($_POST['action']) && in_array($_POST['action'], [
    'clear_all_messages', 
    'clear_all_files', 
    'clear_all_scan_login',
    'clear_scan_login_expired',
    'clear_scan_login_all',
    'delete_group',
    'deactivate_user',
    'delete_user',
    'change_password',
    'change_username',
    'ban_user',
    'lift_ban'
])) {
    $action = $_POST['action'];
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    
    // 验证管理员密码
    if (!validateAdminPassword($password, $current_user, $conn)) {
        header('Location: admin.php?error=密码错误，操作失败');
        exit;
    }
    
    try {
        switch ($action) {
            case 'clear_all_messages':
                // 清除所有聊天记录
                $conn->beginTransaction();
                
                // 清除好友消息
                $stmt = $conn->prepare("DELETE FROM messages");
                $stmt->execute();
                
                // 清除群聊消息
                $stmt = $conn->prepare("DELETE FROM group_messages");
                $stmt->execute();
                
                $conn->commit();
                header('Location: admin.php?success=已成功清除所有聊天记录');
                break;
                
            case 'clear_all_files':
                // 清除所有文件记录
                $conn->beginTransaction();
                
                // 清除消息中的文件记录
                // 清除files表中的所有记录
                $stmt = $conn->prepare("DELETE FROM files");
                $stmt->execute();
                
                $conn->commit();
                header('Location: admin.php?success=已成功清除所有文件记录');
                break;
                
            case 'clear_all_scan_login':
            case 'clear_scan_login_all':
                // 清除所有扫码登录数据
                $stmt = $conn->prepare("DELETE FROM scan_login");
                $stmt->execute();
                header('Location: admin.php?success=已成功清除所有扫码登录数据');
                break;
                
            case 'clear_scan_login_expired':
                // 清除过期的扫码登录数据
                $stmt = $conn->prepare("DELETE FROM scan_login WHERE expire_at < NOW() OR status IN ('expired', 'success')");
                $stmt->execute();
                header('Location: admin.php?success=已成功清除过期的扫码登录数据');
                break;
                
            case 'delete_group':
                // 解散群聊
                $group_id = intval($_POST['group_id']);
                $result = $group->deleteGroup($group_id, $current_user['id']);
                if ($result) {
                    header('Location: admin.php?success=群聊已成功解散');
                } else {
                    header('Location: admin.php?error=群聊解散失败');
                }
                break;
                
            case 'deactivate_user':
                // 注销用户（添加is_deleted字段或使用其他方式标记）
                $user_id = intval($_POST['user_id']);
                
                // 防止管理员操作自己
                if ($user_id === $current_user['id']) {
                    header('Location: admin.php?error=不能操作自己的账户');
                    exit;
                }
                
                // 检查users表是否有is_deleted字段
                $stmt = $conn->prepare("SHOW COLUMNS FROM users LIKE 'is_deleted'");
                $stmt->execute();
                $column_exists = $stmt->fetch();
                
                if ($column_exists) {
                    // 如果有is_deleted字段，使用该字段标记
                    $stmt = $conn->prepare("UPDATE users SET is_deleted = TRUE WHERE id = ?");
                    $stmt->execute([$user_id]);
                } else {
                    // 否则，使用avatar字段存储特殊值来标记删除
                    $stmt = $conn->prepare("UPDATE users SET avatar = 'deleted_user' WHERE id = ?");
                    $stmt->execute([$user_id]);
                }
                header('Location: admin.php?success=用户已成功注销');
                break;
                
            case 'delete_user':
                // 强制删除用户
                $user_id = intval($_POST['user_id']);
                
                // 防止管理员删除自己
                if ($user_id === $current_user['id']) {
                    header('Location: admin.php?error=不能操作自己的账户');
                    exit;
                }
                
                $conn->beginTransaction();
                
                // 删除用户相关数据
                // 先检查表是否存在，存在则删除
                
                // 检查messages表
                $stmt = $conn->prepare("SHOW TABLES LIKE 'messages'");
                $stmt->execute();
                if ($stmt->fetch()) {
                    $stmt = $conn->prepare("DELETE FROM messages WHERE sender_id = ? OR receiver_id = ?");
                    $stmt->execute([$user_id, $user_id]);
                }
                
                // 检查group_messages表
                $stmt = $conn->prepare("SHOW TABLES LIKE 'group_messages'");
                $stmt->execute();
                if ($stmt->fetch()) {
                    $stmt = $conn->prepare("DELETE FROM group_messages WHERE sender_id = ?");
                    $stmt->execute([$user_id]);
                }
                
                // 检查group_members表
                $stmt = $conn->prepare("SHOW TABLES LIKE 'group_members'");
                $stmt->execute();
                if ($stmt->fetch()) {
                    $stmt = $conn->prepare("DELETE FROM group_members WHERE user_id = ?");
                    $stmt->execute([$user_id]);
                }
                
                // 检查friends表（好友请求和好友关系）
                $stmt = $conn->prepare("SHOW TABLES LIKE 'friends'");
                $stmt->execute();
                if ($stmt->fetch()) {
                    $stmt = $conn->prepare("DELETE FROM friends WHERE user_id = ? OR friend_id = ?");
                    $stmt->execute([$user_id, $user_id]);
                }
                
                // 检查sessions表
                $stmt = $conn->prepare("SHOW TABLES LIKE 'sessions'");
                $stmt->execute();
                if ($stmt->fetch()) {
                    $stmt = $conn->prepare("DELETE FROM sessions WHERE user_id = ? OR friend_id = ?");
                    $stmt->execute([$user_id, $user_id]);
                }
                
                // 最后删除用户
                $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$user_id]);
                
                $conn->commit();
                header('Location: admin.php?success=用户已成功删除');
                break;
                
            case 'change_password':
                // 修改用户密码
                $user_id = intval($_POST['user_id']);
                $new_password = $_POST['new_password'];
                
                // 检查用户是否是管理员，禁止修改管理员密码
                $stmt = $conn->prepare("SELECT is_admin FROM users WHERE id = ?");
                $stmt->execute([$user_id]);
                $user = $stmt->fetch();
                if ($user && $user['is_admin']) {
                    header('Location: admin.php?error=不能修改管理员密码');
                    exit;
                }
                
                // 检查密码复杂度
                $complexity = 0;
                if (preg_match('/[a-z]/', $new_password)) $complexity++;
                if (preg_match('/[A-Z]/', $new_password)) $complexity++;
                if (preg_match('/\d/', $new_password)) $complexity++;
                if (preg_match('/[^a-zA-Z0-9]/', $new_password)) $complexity++;
                
                if ($complexity < 2) {
                    header('Location: admin.php?error=密码不符合安全要求，请包含至少2种字符类型（大小写字母、数字、特殊符号）');
                    exit;
                }
                
                // 更新用户密码
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->execute([$hashed_password, $user_id]);
                
                header('Location: admin.php?success=用户密码已成功修改');
                break;
                
        case 'change_username':
                // 修改用户名称
                $user_id = intval($_POST['user_id']);
                $new_username = trim($_POST['new_username']);
                
                // 获取用户名最大长度配置
                $user_name_max = getUserNameMaxLength();
                
                // 验证用户名
                if (strlen($new_username) < 3 || strlen($new_username) > $user_name_max) {
                    header('Location: admin.php?error=用户名长度必须在3-{$user_name_max}个字符之间');
                    exit;
                }
                
                // 检查用户名是否已被使用
                $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
                $stmt->execute([$new_username, $user_id]);
                if ($stmt->rowCount() > 0) {
                    header('Location: admin.php?error=用户名已被使用');
                    exit;
                }
                
                // 更新用户名称
                $stmt = $conn->prepare("UPDATE users SET username = ? WHERE id = ?");
                $stmt->execute([$new_username, $user_id]);
                
                header('Location: admin.php?success=用户名称已成功修改');
                break;
                
        case 'ban_user':
                // 封禁用户
                $user_id = intval($_POST['user_id']);
                $reason = trim($_POST['ban_reason']);
                $ban_duration = intval($_POST['ban_duration']);
                
                // 验证参数
                if (empty($reason)) {
                    header('Location: admin.php?error=请输入封禁理由');
                    exit;
                }
                
                if ($ban_duration <= 0) {
                    header('Location: admin.php?error=封禁时长必须大于0');
                    exit;
                }
                
                // 检查用户是否是管理员，禁止封禁管理员
                $stmt = $conn->prepare("SELECT is_admin FROM users WHERE id = ?");
                $stmt->execute([$user_id]);
                $user = $stmt->fetch();
                if ($user && $user['is_admin']) {
                    header('Location: admin.php?error=不能封禁管理员');
                    exit;
                }
                
                // 封禁用户
                $user = new User($conn);
                $success = $user->banUser($user_id, $current_user['id'], $reason, $ban_duration);
                
                if ($success) {
                    header('Location: admin.php?success=用户已成功封禁');
                } else {
                    header('Location: admin.php?error=封禁失败，用户可能已经被封禁');
                }
                break;
                
        case 'lift_ban':
                // 解除封禁
                $user_id = intval($_POST['user_id']);
                
                // 解除封禁
                $user = new User($conn);
                $success = $user->liftBan($user_id, $current_user['id']);
                
                if ($success) {
                    header('Location: admin.php?success=用户已成功解除封禁');
                } else {
                    header('Location: admin.php?error=解除封禁失败，用户可能未被封禁');
                }
                break;
                
            case 'approve_password_request':
                // 通过忘记密码申请
                $request_id = intval($_POST['request_id']);
                
                try {
                    $conn->beginTransaction();
                    
                    // 获取申请信息
                    $stmt = $conn->prepare("SELECT * FROM forget_password_requests WHERE id = ? AND status = 'pending'");
                    $stmt->execute([$request_id]);
                    $request = $stmt->fetch();
                    
                    if (!$request) {
                        header('Location: admin.php?error=申请不存在或已处理');
                        exit;
                    }
                    
                    // 更新用户密码
                // 调试：记录密码更新
                error_log("Updating password for user: " . $request['username']);
                error_log("Hashed password: " . $request['new_password']);
                $stmt = $conn->prepare("UPDATE users SET password = ? WHERE username = ?");
                $stmt->execute([$request['new_password'], $request['username']]);
                // 调试：记录更新结果
                error_log("Password update rows affected: " . $stmt->rowCount());
                    
                    // 更新申请状态
                    $stmt = $conn->prepare("UPDATE forget_password_requests SET status = 'approved', approved_at = NOW(), admin_id = ? WHERE id = ?");
                    $stmt->execute([$current_user['id'], $request_id]);
                    
                    $conn->commit();
                    header('Location: admin.php?success=忘记密码申请已通过，用户密码已更新');
                } catch (PDOException $e) {
                    $conn->rollBack();
                    error_log("Approve password request error: " . $e->getMessage());
                    header('Location: admin.php?error=处理申请失败：' . $e->getMessage());
                }
                break;
                
            case 'reject_password_request':
                // 拒绝忘记密码申请
                $request_id = intval($_POST['request_id']);
                
                try {
                    // 更新申请状态
                    $stmt = $conn->prepare("UPDATE forget_password_requests SET status = 'rejected', approved_at = NOW(), admin_id = ? WHERE id = ? AND status = 'pending'");
                    $stmt->execute([$current_user['id'], $request_id]);
                    
                    if ($stmt->rowCount() === 0) {
                        header('Location: admin.php?error=申请不存在或已处理');
                        exit;
                    }
                    
                    header('Location: admin.php?success=忘记密码申请已拒绝');
                } catch (PDOException $e) {
                    error_log("Reject password request error: " . $e->getMessage());
                    header('Location: admin.php?error=处理申请失败：' . $e->getMessage());
                }
                break;
        }
        exit;
    } catch (PDOException $e) {
        if (isset($conn)) {
            $conn->rollBack();
        }
        error_log("Action error for action {$action}: " . $e->getMessage());
        header('Location: admin.php?error=操作失败：' . $e->getMessage());
        exit;
    }
}

// 处理用户管理操作 - 已合并到上面的统一处理逻辑中
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理页面 - Modern Chat</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f5;
            color: #333;
        }
        
        .container {
            max-width: 114514px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .header {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header h1 {
            font-size: 24px;
            color: #667eea;
        }
        
        .header .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .header .avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
        }
        
        .header .username {
            font-weight: 600;
        }
        
        .header .logout-btn {
            padding: 8px 16px;
            background: #ff4757;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            transition: background-color 0.2s;
        }
        
        .header .logout-btn:hover {
            background: #ff3742;
        }
        
        .section {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }
        
        .section h2 {
            font-size: 20px;
            margin-bottom: 20px;
            color: #333;
            border-bottom: 2px solid #667eea;
            padding-bottom: 10px;
        }
        
        .section h3 {
            font-size: 16px;
            margin-bottom: 15px;
            color: #666;
        }
        
        .groups-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }
        
        .group-item {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .group-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        .group-item h4 {
            font-size: 16px;
            margin-bottom: 10px;
            color: #667eea;
        }
        
        .group-item p {
            font-size: 14px;
            margin-bottom: 8px;
            color: #666;
        }
        
        .group-item .members {
            margin-top: 10px;
            font-size: 13px;
            color: #888;
        }
        
        .delete-group-btn {
            margin-top: 10px;
            padding: 6px 12px;
            background: #ff4757;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            transition: background-color 0.2s;
        }
        
        .delete-group-btn:hover {
            background: #ff3742;
        }
        
        .messages-container {
            max-height: 400px;
            overflow-y: auto;
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-top: 15px;
        }
        
        .message {
            background: white;
            padding: 10px;
            border-radius: 8px;
            margin-bottom: 10px;
            border: 1px solid #e0e0e0;
        }
        
        .message-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 5px;
        }
        
        .message-sender {
            font-weight: 600;
            color: #667eea;
        }
        
        .message-time {
            font-size: 12px;
            color: #888;
        }
        
        .message-content {
            font-size: 14px;
            color: #333;
        }
        
        .message-file {
            margin-top: 5px;
            font-size: 13px;
            color: #666;
        }
        
        .message-file a {
            color: #667eea;
            text-decoration: none;
        }
        
        .message-file a:hover {
            text-decoration: underline;
        }
        
        .success-message {
            background: #d4edda;
            color: #155724;
            padding: 10px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        
        .error-message {
            background: #f8d7da;
            color: #721c24;
            padding: 10px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        
        .tabs {
            display: flex;
            margin-bottom: 20px;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .tab {
            padding: 10px 20px;
            background: transparent;
            border: none;
            border-bottom: 2px solid transparent;
            cursor: pointer;
            font-size: 16px;
            color: #666;
            transition: all 0.2s;
        }
        
        .tab.active {
            border-bottom-color: #667eea;
            color: #667eea;
            font-weight: 600;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        /* 状态样式 */
        .status-pending {
            background: #e3f2fd;
            color: #1976d2;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .status-approved {
            background: #e8f5e8;
            color: #388e3c;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .status-rejected {
            background: #ffebee;
            color: #d32f2f;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .search-input {
            width: 100%;
            padding: 10px;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            font-size: 14px;
            margin-bottom: 20px;
        }
        
        .search-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 2px rgba(102, 126, 234, 0.1);
        }
        
        /* 系统设置样式 */
        .settings-container {
            max-width: 800px;
        }
        
        .settings-list {
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .setting-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 20px;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .setting-item:last-child {
            border-bottom: none;
        }
        
        .setting-info {
            flex: 1;
        }
        
        .setting-info label {
            display: block;
            font-weight: 600;
            margin-bottom: 5px;
            color: #333;
        }
        
        .setting-description {
            font-size: 12px;
            color: #666;
            margin: 0;
        }
        
        /* 切换开关样式 */
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
        }
        
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 24px;
        }
        
        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        
        input:checked + .toggle-slider {
            background-color: #667eea;
        }
        
        input:focus + .toggle-slider {
            box-shadow: 0 0 1px #667eea;
        }
        
        input:checked + .toggle-slider:before {
            transform: translateX(26px);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>管理页面</h1>
            <div class="user-info">
                <div class="avatar">
                    <?php echo substr($current_user['username'], 0, 2); ?>
                </div>
                <span class="username"><?php echo $current_user['username']; ?></span>
                <span>(管理员)</span>
                <a href="chat.php" class="logout-btn">返回聊天</a>
            </div>
        </div>

        <?php if (isset($_GET['success'])): ?>
            <div class="success-message"><?php echo $_GET['success']; ?></div>
        <?php endif; ?>
        <?php if (isset($_GET['error'])): ?>
            <div class="error-message"><?php echo $_GET['error']; ?></div>
        <?php endif; ?>

        <div class="section">
            <h2>管理功能</h2>
            <div class="tabs">
                <button class="tab active" onclick="openTab(event, 'groups')">群聊管理</button>
                <button class="tab" onclick="openTab(event, 'group_messages')">群聊消息</button>
                <button class="tab" onclick="openTab(event, 'friend_messages')">好友消息</button>
                <button class="tab" onclick="openTab(event, 'users')">用户管理</button>
                <button class="tab" onclick="openTab(event, 'scan_login')">扫码登录管理</button>
                <button class="tab" onclick="openTab(event, 'clear_data')">清除数据</button>
                <button class="tab" onclick="openTab(event, 'feedback')">反馈管理</button>
                <button class="tab" onclick="openTab(event, 'forget_password')">忘记密码审核</button>
                <button class="tab" onclick="openTab(event, 'system_settings')">系统设置</button>
            </div>

            <!-- 群聊管理 -->
            <div id="groups" class="tab-content active">
                <h3>所有群聊</h3>
                <div class="groups-list">
                    <?php foreach ($all_groups as $group_item): ?>
                        <div class="group-item">
                            <h4><?php echo $group_item['name']; ?></h4>
                            <p>创建者: <?php echo $group_item['creator_username']; ?></p>
                            <p>群主: <?php echo $group_item['owner_username']; ?></p>
                            <p class="members">成员数量: <?php echo $group_item['member_count']; ?></p>
                            <p>创建时间: <?php echo $group_item['created_at']; ?></p>
                            <button onclick="showClearDataModal('delete_group', <?php echo $group_item['id']; ?>)" class="delete-group-btn">解散群聊</button>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- 群聊消息 -->
            <div id="group_messages" class="tab-content">
                <h3>所有群聊消息</h3>
                <div class="messages-container">
                    <?php foreach ($all_group_messages as $msg): ?>
                        <div class="message">
                            <div class="message-header">
                                <span class="message-sender">
                                    <?php echo $msg['sender_username']; ?> (群聊: <?php echo $msg['group_name']; ?>)
                                </span>
                                <span class="message-time"><?php echo $msg['created_at']; ?></span>
                            </div>
                            <div class="message-content">
                                <?php if ($msg['content']): ?>
                                    <?php echo $msg['content']; ?>
                                <?php endif; ?>
                                <?php if ($msg['file_path']): ?>
                                    <div class="message-file">
                                        <a href="<?php echo $msg['file_path']; ?>" target="_blank">
                                            📎 <?php echo $msg['file_name']; ?>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- 好友消息 -->
            <div id="friend_messages" class="tab-content">
                <h3>所有好友消息</h3>
                <div class="messages-container">
                    <?php foreach ($all_friend_messages as $msg): ?>
                        <div class="message">
                            <div class="message-header">
                                <span class="message-sender">
                                    <?php echo $msg['sender_username']; ?> → <?php echo $msg['receiver_username']; ?>
                                </span>
                                <span class="message-time"><?php echo $msg['created_at']; ?></span>
                            </div>
                            <div class="message-content">
                                <?php if ($msg['content']): ?>
                                    <?php echo $msg['content']; ?>
                                <?php endif; ?>
                                <?php if ($msg['file_path']): ?>
                                    <div class="message-file">
                                        <a href="<?php echo $msg['file_path']; ?>" target="_blank">
                                            📎 <?php echo $msg['file_name']; ?>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- 用户管理 -->
            <div id="users" class="tab-content">
                <h3>所有用户</h3>
                <div class="groups-list">
                    <?php foreach ($all_users as $user_item): ?>
                        <div class="group-item">
                            <h4><?php echo $user_item['username']; ?></h4>
                            <p>邮箱: <?php echo $user_item['email']; ?></p>
                            <p>状态: <?php echo $user_item['status']; ?></p>
                            <p>角色: <?php echo $user_item['is_admin'] ? '管理员' : '普通用户'; ?></p>
                            <p>注册时间: <?php echo $user_item['created_at']; ?></p>
                            <p>最后活跃: <?php echo $user_item['last_active']; ?></p>
                            <!-- 检查用户封禁状态 -->
                            <?php 
                            $ban_info = $user->isBanned($user_item['id']);
                            if ($ban_info):
                            ?>
                                <div style="margin-top: 10px; padding: 8px; background: #ffebee; color: #d32f2f; border-radius: 4px; font-size: 12px;">
                                    已封禁 - 截止时间: <?php echo $ban_info['expires_at']; ?><br>
                                    原因: <?php echo $ban_info['reason']; ?>
                                </div>
                            <?php endif; ?>
                            <div style="margin-top: 10px; display: flex; gap: 8px; flex-wrap: wrap;">
                                <?php if ($user_item['id'] !== $current_user['id'] && !$user_item['is_admin']): ?>
                                    <button onclick="showClearDataModal('deactivate_user', <?php echo $user_item['id']; ?>)" style="padding: 6px 12px; background: #ffa726; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 12px;">注销用户</button>
                                    <button onclick="showClearDataModal('delete_user', <?php echo $user_item['id']; ?>)" style="padding: 6px 12px; background: #ef5350; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 12px;">强制删除</button>
                                    <button onclick="showChangePasswordModal(<?php echo $user_item['id']; ?>, '<?php echo $user_item['username']; ?>')" style="padding: 6px 12px; background: #667eea; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 12px;">修改密码</button>
                                    <button onclick="showChangeUsernameModal(<?php echo $user_item['id']; ?>, '<?php echo $user_item['username']; ?>')" style="padding: 6px 12px; background: #4caf50; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 12px;">修改名称</button>
                                    <?php if ($ban_info): ?>
                                        <button onclick="showLiftBanModal(<?php echo $user_item['id']; ?>, '<?php echo $user_item['username']; ?>')" style="padding: 6px 12px; background: #81c784; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 12px;">解除封禁</button>
                                    <?php else: ?>
                                        <button onclick="showBanUserModal(<?php echo $user_item['id']; ?>, '<?php echo $user_item['username']; ?>')" style="padding: 6px 12px; background: #e57373; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 12px;">封禁用户</button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- 扫码登录数据管理 -->
            <div id="scan_login" class="tab-content">
                <h3>扫码登录数据管理</h3>
                <div class="group-item">
                    <h4>扫码登录数据清理</h4>
                    <p>扫码登录数据会在PC端登录成功后自动清理，但您也可以手动清理过期数据或所有数据。</p>
                    <div style="margin-top: 20px; display: flex; gap: 15px;">
                        <!-- 删除过期的扫码登录数据 -->
                        <button onclick="showClearDataModal('clear_scan_login_expired')" style="padding: 10px 20px; background: #4caf50; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 500;">删除过期数据</button>
                        
                        <!-- 删除所有扫码登录数据 -->
                        <button onclick="showClearDataModal('clear_scan_login_all')" style="padding: 10px 20px; background: #f44336; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 500;">删除所有数据</button>
                    </div>
                </div>
            </div>
            
            <!-- 清除数据 -->
            <div id="clear_data" class="tab-content">
                <h3>清除数据</h3>
                <div class="group-item">
                    <h4>清除全部聊天记录</h4>
                    <p>清除所有群聊和好友的聊天记录，此操作不可恢复！</p>
                    <button onclick="showClearDataModal('clear_all_messages')" style="margin-top: 10px; padding: 10px 20px; background: #f44336; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 500;">清除全部聊天记录</button>
                </div>
                
                <div class="group-item" style="margin-top: 20px;">
                    <h4>清除全部文件记录</h4>
                    <p>清除所有上传的文件记录，此操作不可恢复！</p>
                    <button onclick="showClearDataModal('clear_all_files')" style="margin-top: 10px; padding: 10px 20px; background: #f44336; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 500;">清除全部文件记录</button>
                </div>
                
                <div class="group-item" style="margin-top: 20px;">
                    <h4>清除扫码登录数据</h4>
                    <p>清除所有扫码登录相关数据，包括过期和未过期的数据！</p>
                    <button onclick="showClearDataModal('clear_all_scan_login')" style="margin-top: 10px; padding: 10px 20px; background: #f44336; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 500;">清除扫码登录数据</button>
                </div>
            </div>
            
            <!-- 反馈管理 -->
            <div id="feedback" class="tab-content">
                <h3>用户反馈</h3>
                <div style="margin-bottom: 20px;">
                    <button onclick="window.location.href='feedback-2.php'" style="padding: 10px 20px; background: #667eea; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 500;">
                        查看所有反馈
                    </button>
                </div>
            </div>
            
            <!-- 忘记密码审核 -->
            <div id="forget_password" class="tab-content">
                <h3>忘记密码审核</h3>
                <div class="groups-list">
                    <?php
                    // 查询所有忘记密码申请
                    try {
                        // 调试：检查SQL查询
                        $stmt = $conn->prepare("SELECT * FROM forget_password_requests ORDER BY created_at DESC");
                        $stmt->execute();
                        $requests = $stmt->fetchAll();
                        // 调试：记录查询结果数量
                        error_log("Forget password requests found: " . count($requests));
                        error_log("SQL Query: SELECT * FROM forget_password_requests ORDER BY created_at DESC");
                        
                        if (empty($requests)) {
                            echo '<p style="text-align: center; color: #666; margin: 20px 0;">没有待处理的忘记密码申请</p>';
                        } else {
                            foreach ($requests as $request) {
                                $status_class = '';
                                switch ($request['status']) {
                                    case 'pending':
                                        $status_class = 'status-pending';
                                        break;
                                    case 'approved':
                                        $status_class = 'status-approved';
                                        break;
                                    case 'rejected':
                                        $status_class = 'status-rejected';
                                        break;
                                }
                                
                                echo '<div class="group-item">';
                                echo '<h4>用户: ' . htmlspecialchars($request['username']) . '</h4>';
                                echo '<p>邮箱: ' . htmlspecialchars($request['email']) . '</p>';
                                echo '<p>申请时间: ' . $request['created_at'] . '</p>';
                                echo '<p>状态: <span class="' . $status_class . '">' . 
                                    ($request['status'] == 'pending' ? '待处理' : 
                                     ($request['status'] == 'approved' ? '已通过' : '已拒绝')) . '</span></p>';
                                if ($request['approved_at']) {
                                    echo '<p>处理时间: ' . $request['approved_at'] . '</p>';
                                }
                                
                                // 只显示待处理申请的审核按钮
                                if ($request['status'] == 'pending') {
                                    echo '<div style="margin-top: 10px; display: flex; gap: 8px; flex-wrap: wrap;">';
                                    echo '<button onclick="showApprovePasswordModal(' . $request['id'] . ', \'' . htmlspecialchars($request['username']) . '\')" style="padding: 6px 12px; background: #4caf50; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 12px;">通过</button>';
                                    echo '<button onclick="showRejectPasswordModal(' . $request['id'] . ', \'' . htmlspecialchars($request['username']) . '\')" style="padding: 6px 12px; background: #f44336; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 12px;">拒绝</button>';
                                    echo '</div>';
                                }
                                echo '</div>';
                            }
                        }
                    } catch (PDOException $e) {
                        error_log("Get forget password requests error: " . $e->getMessage());
                        echo '<p style="text-align: center; color: #ff4757; margin: 20px 0;">查询忘记密码申请失败</p>';
                    }
                    ?>
                </div>
            </div>
            
            <!-- 系统设置 -->
            <div id="system_settings" class="tab-content">
                <h3>系统设置</h3>
                <div class="settings-container">
                    <?php
                    // 读取配置文件
                    $config_file = 'config/config.json';
                    $config_data = json_decode(file_get_contents($config_file), true);
                    
                    // 处理表单提交
                    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_settings') {
                        // 更新配置
                        $updated_config = [];
                        
                        // 遍历配置项，更新值
                        foreach ($config_data as $key => $value) {
                            if (isset($_POST[$key])) {
                                $new_value = $_POST[$key];
                                // 根据原始值类型转换新值
                                if (is_bool($value)) {
                                    $updated_config[$key] = $new_value === 'true';
                                } elseif (is_int($value)) {
                                    $updated_config[$key] = intval($new_value);
                                } else {
                                    $updated_config[$key] = $new_value;
                                }
                            } else {
                                // 如果是布尔值且未提交，设置为false
                                if (is_bool($value)) {
                                    $updated_config[$key] = false;
                                } else {
                                    $updated_config[$key] = $value;
                                }
                            }
                        }
                        
                        // 保存更新后的配置
                        file_put_contents($config_file, json_encode($updated_config, JSON_PRETTY_PRINT));
                        
                        // 显示成功消息
                        echo '<div style="background: #4CAF50; color: white; padding: 10px; border-radius: 5px; margin-bottom: 20px;">';
                        echo '设置已更新，请管理员重启网站服务后生效';
                        echo '</div>';
                        
                        // 重新加载配置
                        $config_data = $updated_config;
                    }
                    ?>
                    
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="update_settings">
                        
                        <div class="settings-list">
                            <?php foreach ($config_data as $key => $value): ?>
                                <div class="setting-item">
                                    <div class="setting-info">
                                        <label for="<?php echo $key; ?>">
                                            <?php 
                                            // 将配置键转换为更友好的名称
                                            $friendly_name = str_replace('_', ' ', $key);
                                            $friendly_name = ucwords($friendly_name);
                                            echo $friendly_name;
                                            ?>
                                        </label>
                                        <p class="setting-description"><?php 
                                            // 添加配置项描述
                                            switch ($key) {
                                                case 'Create_a_group_chat_for_all_members':
                                                    echo '是否为新用户自动创建全员群聊';
                                                    break;
                                                case 'Restrict_registration':
                                                    echo '是否启用IP注册限制';
                                                    break;
                                                case 'Restrict_registration_ip':
                                                    echo '每个IP地址允许注册的最大账号数';
                                                    break;
                                                case 'ban_system':
                                                    echo '是否启用封禁系统';
                                                    break;
                                                case 'user_name_max':
                                                    echo '用户名最大长度限制';
                                                    break;
                                                case 'upload_files_max':
                                                    echo '最大允许上传文件大小（MB）';
                                                    break;
                                                default:
                                                    echo '';
                                            }
                                            ?></p>
                                    </div>
                                    
                                    <div class="setting-value">
                                        <?php if (is_bool($value)): ?>
                                            <!-- 布尔值使用复选框 -->
                                            <label class="toggle-switch">
                                                <input type="checkbox" name="<?php echo $key; ?>" value="true" <?php echo $value ? 'checked' : ''; ?>>
                                                <span class="toggle-slider"></span>
                                            </label>
                                        <?php else: ?>
                                            <!-- 其他类型使用输入框 -->
                                            <input type="text" name="<?php echo $key; ?>" value="<?php echo $value; ?>" 
                                                style="padding: 8px; border: 1px solid #ddd; border-radius: 4px; width: 100px;">
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <button type="submit" class="btn" style="margin-top: 20px; padding: 10px 20px; background: #667eea; color: white; border: none; border-radius: 4px; cursor: pointer;">
                            保存设置
                        </button>
                    </form>
                    
                    <div style="background: #ff9800; color: white; padding: 10px; border-radius: 5px; margin-top: 20px;">
                        <strong>注意：</strong>修改设置前请确保不会影响用户的前提下重启网站服务才能生效
                    </div>
                </div>
            </div>
        </div>
        
        <!-- 清除数据确认弹窗 -->
        <div id="clear-data-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); z-index: 3000; flex-direction: column; align-items: center; justify-content: center;">
            <div style="background: white; padding: 25px; border-radius: 12px; width: 90%; max-width: 500px;">
                <h3 style="margin-bottom: 20px; color: #333; text-align: center;">确认清除数据</h3>
                <p id="clear-data-message" style="margin-bottom: 20px; color: #666; text-align: center;"></p>
                
                <!-- 密码验证 -->
                <div style="margin-bottom: 20px;">
                    <label for="admin-password" style="display: block; margin-bottom: 8px; color: #333; font-weight: 500;">请输入管理员密码：</label>
                    <input type="password" id="admin-password" placeholder="输入密码" style="width: 100%; padding: 12px; border: 1px solid #e0e0e0; border-radius: 8px; font-size: 14px; outline: none; transition: border-color 0.2s;">
                    <p id="password-error" style="margin-top: 8px; color: #ff4757; font-size: 12px; display: none;">密码错误，请重试</p>
                </div>
                
                <div style="display: flex; gap: 15px; justify-content: center; align-items: center;">
                    <button id="cancel-clear-btn" style="padding: 12px 25px; background: #f5f5f5; color: #333; border: none; border-radius: 8px; cursor: pointer; font-weight: 500; flex: 1; font-size: 14px; transition: background-color 0.2s;">取消</button>
                    <button id="confirm-clear-btn" style="padding: 12px 25px; background: #f44336; color: white; border: none; border-radius: 8px; cursor: not-allowed; opacity: 0.6; font-weight: 500; flex: 1; font-size: 14px; transition: all 0.2s;">
                        确定 (<span id="countdown">4</span>s)
                    </button>
                </div>
            </div>
        </div>
        
        <!-- 通过忘记密码申请弹窗 -->
        <div id="approve-password-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); z-index: 3000; flex-direction: column; align-items: center; justify-content: center;">
            <div style="background: white; padding: 25px; border-radius: 12px; width: 90%; max-width: 500px;">
                <h3 style="margin-bottom: 20px; color: #333; text-align: center;">通过忘记密码申请</h3>
                <p id="approve-password-username" style="margin-bottom: 20px; color: #666; text-align: center;"></p>
                
                <div style="margin-bottom: 20px;">
                    <label for="admin-password-approve" style="display: block; margin-bottom: 8px; color: #333; font-weight: 500;">请输入管理员密码：</label>
                    <input type="password" id="admin-password-approve" placeholder="输入管理员密码" style="width: 100%; padding: 12px; border: 1px solid #e0e0e0; border-radius: 8px; font-size: 14px; outline: none; transition: border-color 0.2s;">
                    <p id="admin-password-error-approve" style="margin-top: 8px; color: #ff4757; font-size: 12px; display: none;">密码错误，请重试</p>
                </div>
                
                <div style="display: flex; gap: 15px; justify-content: center; align-items: center;">
                    <button id="cancel-approve-btn" style="padding: 12px 25px; background: #f5f5f5; color: #333; border: none; border-radius: 8px; cursor: pointer; font-weight: 500; flex: 1; font-size: 14px; transition: background-color 0.2s;">取消</button>
                    <button id="confirm-approve-btn" style="padding: 12px 25px; background: #4caf50; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 500; flex: 1; font-size: 14px; transition: all 0.2s;">确定</button>
                </div>
            </div>
        </div>
        
        <!-- 拒绝忘记密码申请弹窗 -->
        <div id="reject-password-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); z-index: 3000; flex-direction: column; align-items: center; justify-content: center;">
            <div style="background: white; padding: 25px; border-radius: 12px; width: 90%; max-width: 500px;">
                <h3 style="margin-bottom: 20px; color: #333; text-align: center;">拒绝忘记密码申请</h3>
                <p id="reject-password-username" style="margin-bottom: 20px; color: #666; text-align: center;"></p>
                <p style="margin-bottom: 20px; color: #333; text-align: center;">确定要拒绝该用户的忘记密码申请吗？</p>
                
                <div style="display: flex; gap: 15px; justify-content: center; align-items: center;">
                    <button id="cancel-reject-btn" style="padding: 12px 25px; background: #f5f5f5; color: #333; border: none; border-radius: 8px; cursor: pointer; font-weight: 500; flex: 1; font-size: 14px; transition: background-color 0.2s;">取消</button>
                    <button id="confirm-reject-btn" style="padding: 12px 25px; background: #f44336; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 500; flex: 1; font-size: 14px; transition: all 0.2s;">确定</button>
                </div>
            </div>
        </div>
        
        <!-- 修改密码弹窗 -->
        <div id="change-password-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); z-index: 3000; flex-direction: column; align-items: center; justify-content: center;">
            <div style="background: white; padding: 25px; border-radius: 12px; width: 90%; max-width: 500px;">
                <h3 style="margin-bottom: 20px; color: #333; text-align: center;">修改用户密码</h3>
                <p id="change-password-username" style="margin-bottom: 20px; color: #666; text-align: center;"></p>
                
                <div style="margin-bottom: 20px;">
                    <label for="new-password" style="display: block; margin-bottom: 8px; color: #333; font-weight: 500;">新密码：</label>
                    <input type="password" id="new-password" placeholder="输入新密码" style="width: 100%; padding: 12px; border: 1px solid #e0e0e0; border-radius: 8px; font-size: 14px; outline: none; transition: border-color 0.2s;">
                    <p id="password-requirements" style="margin-top: 8px; color: #888; font-size: 12px;">密码必须包含大小写字母、数字、特殊符号中的至少2种</p>
                    <p id="password-error" style="margin-top: 8px; color: #ff4757; font-size: 12px; display: none;"></p>
                </div>
                
                <!-- 密码验证 -->
                <div style="margin-bottom: 20px;">
                    <label for="admin-password-change" style="display: block; margin-bottom: 8px; color: #333; font-weight: 500;">管理员密码：</label>
                    <input type="password" id="admin-password-change" placeholder="输入管理员密码" style="width: 100%; padding: 12px; border: 1px solid #e0e0e0; border-radius: 8px; font-size: 14px; outline: none; transition: border-color 0.2s;">
                    <p id="admin-password-error-change" style="margin-top: 8px; color: #ff4757; font-size: 12px; display: none;">密码错误，请重试</p>
                </div>
                
                <div style="display: flex; gap: 15px; justify-content: center; align-items: center;">
                    <button id="cancel-change-password-btn" style="padding: 12px 25px; background: #f5f5f5; color: #333; border: none; border-radius: 8px; cursor: pointer; font-weight: 500; flex: 1; font-size: 14px; transition: background-color 0.2s;">取消</button>
                    <button id="confirm-change-password-btn" style="padding: 12px 25px; background: #667eea; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 500; flex: 1; font-size: 14px; transition: all 0.2s;">确定</button>
                </div>
            </div>
        </div>
        
        <!-- 修改用户名称弹窗 -->
        <div id="change-username-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); z-index: 3000; flex-direction: column; align-items: center; justify-content: center;">
            <div style="background: white; padding: 25px; border-radius: 12px; width: 90%; max-width: 500px;">
                <h3 style="margin-bottom: 20px; color: #333; text-align: center;">修改用户名称</h3>
                <p id="change-username-current" style="margin-bottom: 20px; color: #666; text-align: center;"></p>
                
                <div style="margin-bottom: 20px;">
                    <label for="new-username" style="display: block; margin-bottom: 8px; color: #333; font-weight: 500;">新名称：</label>
                    <input type="text" id="new-username" placeholder="输入新名称" style="width: 100%; padding: 12px; border: 1px solid #e0e0e0; border-radius: 8px; font-size: 14px; outline: none; transition: border-color 0.2s;">
                    <p id="username-error" style="margin-top: 8px; color: #ff4757; font-size: 12px; display: none;"></p>
                    <p id="username-requirements" style="margin-top: 8px; color: #888; font-size: 12px;">名称长度必须在3-<?php echo getUserNameMaxLength(); ?>个字符之间</p>
                </div>
                
                <!-- 密码验证 -->
                <div style="margin-bottom: 20px;">
                    <label for="admin-password-username" style="display: block; margin-bottom: 8px; color: #333; font-weight: 500;">管理员密码：</label>
                    <input type="password" id="admin-password-username" placeholder="输入管理员密码" style="width: 100%; padding: 12px; border: 1px solid #e0e0e0; border-radius: 8px; font-size: 14px; outline: none; transition: border-color 0.2s;">
                    <p id="admin-password-error-username" style="margin-top: 8px; color: #ff4757; font-size: 12px; display: none;">密码错误，请重试</p>
                </div>
                
                <div style="display: flex; gap: 15px; justify-content: center; align-items: center;">
                    <button id="cancel-change-username-btn" style="padding: 12px 25px; background: #f5f5f5; color: #333; border: none; border-radius: 8px; cursor: pointer; font-weight: 500; flex: 1; font-size: 14px; transition: background-color 0.2s;">取消</button>
                    <button id="confirm-change-username-btn" style="padding: 12px 25px; background: #4caf50; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 500; flex: 1; font-size: 14px; transition: all 0.2s;">确定</button>
                </div>
            </div>
        </div>
        
        <!-- 封禁用户弹窗 -->
        <div id="ban-user-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); z-index: 3000; flex-direction: column; align-items: center; justify-content: center;">
            <div style="background: white; padding: 25px; border-radius: 12px; width: 90%; max-width: 500px;">
                <h3 style="margin-bottom: 20px; color: #333; text-align: center;">封禁用户</h3>
                <p id="ban-user-username" style="margin-bottom: 20px; color: #666; text-align: center;"></p>
                
                <div style="margin-bottom: 20px;">
                    <label for="ban-reason" style="display: block; margin-bottom: 8px; color: #333; font-weight: 500;">封禁理由：</label>
                    <textarea id="ban-reason" placeholder="请输入封禁理由" style="width: 100%; padding: 12px; border: 1px solid #e0e0e0; border-radius: 8px; font-size: 14px; outline: none; transition: border-color 0.2s; resize: vertical; min-height: 100px;"></textarea>
                    <p id="ban-reason-error" style="margin-top: 8px; color: #ff4757; font-size: 12px; display: none;"></p>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <label for="ban-duration" style="display: block; margin-bottom: 8px; color: #333; font-weight: 500;">封禁时长（小时）：</label>
                    <input type="number" id="ban-duration" placeholder="输入封禁时长（小时）" min="1" style="width: 100%; padding: 12px; border: 1px solid #e0e0e0; border-radius: 8px; font-size: 14px; outline: none; transition: border-color 0.2s;">
                    <p id="ban-duration-error" style="margin-top: 8px; color: #ff4757; font-size: 12px; display: none;"></p>
                </div>
                
                <!-- 密码验证 -->
                <div style="margin-bottom: 20px;">
                    <label for="admin-password-ban" style="display: block; margin-bottom: 8px; color: #333; font-weight: 500;">请输入管理员密码：</label>
                    <input type="password" id="admin-password-ban" placeholder="输入密码" style="width: 100%; padding: 12px; border: 1px solid #e0e0e0; border-radius: 8px; font-size: 14px; outline: none; transition: border-color 0.2s;">
                    <p id="admin-password-error-ban" style="margin-top: 8px; color: #ff4757; font-size: 12px; display: none;">密码错误，请重试</p>
                </div>
                
                <div style="display: flex; gap: 15px; justify-content: center; align-items: center;">
                    <button id="cancel-ban-btn" style="padding: 12px 25px; background: #f5f5f5; color: #333; border: none; border-radius: 8px; cursor: pointer; font-weight: 500; flex: 1; font-size: 14px; transition: background-color 0.2s;">取消</button>
                    <button id="confirm-ban-btn" style="padding: 12px 25px; background: #e57373; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 500; flex: 1; font-size: 14px; transition: all 0.2s;">确定</button>
                </div>
            </div>
        </div>
        
        <!-- 解除封禁弹窗 -->
        <div id="lift-ban-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); z-index: 3000; flex-direction: column; align-items: center; justify-content: center;">
            <div style="background: white; padding: 25px; border-radius: 12px; width: 90%; max-width: 500px;">
                <h3 style="margin-bottom: 20px; color: #333; text-align: center;">解除封禁</h3>
                <p id="lift-ban-username" style="margin-bottom: 20px; color: #666; text-align: center;"></p>
                <p style="margin-bottom: 20px; color: #333; text-align: center;">确定要解除该用户的封禁吗？</p>
                
                <!-- 密码验证 -->
                <div style="margin-bottom: 20px;">
                    <label for="admin-password-lift-ban" style="display: block; margin-bottom: 8px; color: #333; font-weight: 500;">请输入管理员密码：</label>
                    <input type="password" id="admin-password-lift-ban" placeholder="输入密码" style="width: 100%; padding: 12px; border: 1px solid #e0e0e0; border-radius: 8px; font-size: 14px; outline: none; transition: border-color 0.2s;">
                    <p id="admin-password-error-lift-ban" style="margin-top: 8px; color: #ff4757; font-size: 12px; display: none;">密码错误，请重试</p>
                </div>
                
                <div style="display: flex; gap: 15px; justify-content: center; align-items: center;">
                    <button id="cancel-lift-ban-btn" style="padding: 12px 25px; background: #f5f5f5; color: #333; border: none; border-radius: 8px; cursor: pointer; font-weight: 500; flex: 1; font-size: 14px; transition: background-color 0.2s;">取消</button>
                    <button id="confirm-lift-ban-btn" style="padding: 12px 25px; background: #81c784; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 500; flex: 1; font-size: 14px; transition: all 0.2s;">确定</button>
                </div>
            </div>
        </div>
        
        <!-- 操作结果弹窗 -->
        <div id="result-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); z-index: 3000; flex-direction: column; align-items: center; justify-content: center;">
            <div style="background: white; padding: 25px; border-radius: 12px; width: 90%; max-width: 400px; text-align: center;">
                <div id="result-icon" style="font-size: 48px; margin-bottom: 15px;"></div>
                <h3 id="result-title" style="margin-bottom: 10px; color: #333;"></h3>
                <p id="result-message" style="margin-bottom: 20px; color: #666; font-size: 14px;"></p>
                <button onclick="closeResultModal()" style="padding: 12px 25px; background: #667eea; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 500; font-size: 14px; transition: background-color 0.2s;">确定</button>
            </div>
        </div>
    </div>

    <script>
        function openTab(evt, tabName) {
            // 关闭所有标签页
            var i, tabcontent, tablinks;
            tabcontent = document.getElementsByClassName("tab-content");
            for (i = 0; i < tabcontent.length; i++) {
                tabcontent[i].style.display = "none";
            }
            tablinks = document.getElementsByClassName("tab");
            for (i = 0; i < tablinks.length; i++) {
                tablinks[i].className = tablinks[i].className.replace(" active", "");
            }
            // 打开当前标签页
            document.getElementById(tabName).style.display = "block";
            evt.currentTarget.className += " active";
        }
        
        // 清除数据相关变量
        let currentClearAction = '';
        let currentActionId = '';
        let countdownInterval = null;
        let countdownTime = 4;
        
        // 显示清除数据确认弹窗
        function showClearDataModal(action, id = '') {
            currentClearAction = action;
            currentActionId = id;
            
            // 设置确认消息
            const messageEl = document.getElementById('clear-data-message');
            switch(action) {
                case 'clear_all_messages':
                    messageEl.textContent = '确定要清除所有聊天记录吗？此操作不可恢复！';
                    break;
                case 'clear_all_files':
                    messageEl.textContent = '确定要清除所有文件记录吗？此操作不可恢复！';
                    break;
                case 'clear_all_scan_login':
                    messageEl.textContent = '确定要清除所有扫码登录数据吗？此操作不可恢复！';
                    break;
                case 'clear_scan_login_expired':
                    messageEl.textContent = '确定要清除过期的扫码登录数据吗？';
                    break;
                case 'clear_scan_login_all':
                    messageEl.textContent = '确定要清除所有扫码登录数据吗？此操作不可恢复！';
                    break;
                case 'delete_group':
                    messageEl.textContent = '确定要解散这个群聊吗？此操作不可恢复！';
                    break;
                case 'deactivate_user':
                    messageEl.textContent = '确定要注销这个用户吗？用户将不允许登录。';
                    break;
                case 'delete_user':
                    messageEl.textContent = '确定要强制删除这个用户吗？此操作不可恢复！';
                    break;
            }
            
            // 重置密码输入和错误提示
            document.getElementById('admin-password').value = '';
            document.getElementById('password-error').style.display = 'none';
            
            // 重置倒计时
            resetCountdown();
            
            // 显示弹窗
            document.getElementById('clear-data-modal').style.display = 'flex';
            
            // 开始倒计时
            startCountdown();
            
            // 添加事件监听器
            document.getElementById('cancel-clear-btn').addEventListener('click', closeClearDataModal);
            document.getElementById('confirm-clear-btn').addEventListener('click', handleConfirmClear);
            document.getElementById('admin-password').addEventListener('input', handlePasswordInput);
        }
        
        // 关闭清除数据确认弹窗
        function closeClearDataModal() {
            document.getElementById('clear-data-modal').style.display = 'none';
            
            // 清除倒计时
            if (countdownInterval) {
                clearInterval(countdownInterval);
                countdownInterval = null;
            }
            
            // 移除事件监听器
            document.getElementById('cancel-clear-btn').removeEventListener('click', closeClearDataModal);
            document.getElementById('confirm-clear-btn').removeEventListener('click', handleConfirmClear);
            document.getElementById('admin-password').removeEventListener('input', handlePasswordInput);
        }
        
        // 重置倒计时
        function resetCountdown() {
            countdownTime = 3;
            const countdownEl = document.getElementById('countdown');
            countdownEl.textContent = countdownTime;
            
            const confirmBtn = document.getElementById('confirm-clear-btn');
            confirmBtn.disabled = true;
            confirmBtn.style.cursor = 'not-allowed';
            confirmBtn.style.opacity = '0.6';
        }
        
        // 开始倒计时
        function startCountdown() {
            if (countdownInterval) {
                clearInterval(countdownInterval);
            }
            
            countdownInterval = setInterval(() => {
                countdownTime--;
                const countdownEl = document.getElementById('countdown');
                countdownEl.textContent = countdownTime;
                
                if (countdownTime <= 0) {
                    clearInterval(countdownInterval);
                    countdownInterval = null;
                    
                    const confirmBtn = document.getElementById('confirm-clear-btn');
                    confirmBtn.disabled = false;
                    confirmBtn.style.cursor = 'pointer';
                    confirmBtn.style.opacity = '1';
                    confirmBtn.textContent = '确定';
                }
            }, 1000);
        }
        
        // 处理密码输入
        function handlePasswordInput() {
            // 隐藏密码错误提示
            document.getElementById('password-error').style.display = 'none';
        }
        
        // 处理确认清除
        function handleConfirmClear() {
            const password = document.getElementById('admin-password').value;
            if (!password) {
                document.getElementById('password-error').textContent = '请输入密码';
                document.getElementById('password-error').style.display = 'block';
                return;
            }
            
            // 验证密码
            validatePassword(password).then(isValid => {
                if (isValid) {
                    // 密码正确，执行清除操作
                    executeClearAction();
                } else {
                    // 密码错误，显示错误提示
                    document.getElementById('password-error').style.display = 'block';
                }
            });
        }
        
        // 验证管理员密码
        async function validatePassword(password) {
            try {
                const response = await fetch('admin.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        'action': 'validate_admin_password',
                        'password': password
                    })
                });
                
                const result = await response.json();
                return result.valid;
            } catch (error) {
                console.error('验证密码失败:', error);
                return false;
            }
        }
        
        // 执行清除操作
        function executeClearAction() {
            const password = document.getElementById('admin-password').value;
            
            // 创建表单
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            
            // 添加表单字段
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = currentClearAction;
            form.appendChild(actionInput);
            
            const passwordInput = document.createElement('input');
            passwordInput.type = 'hidden';
            passwordInput.name = 'password';
            passwordInput.value = password;
            form.appendChild(passwordInput);
            
            // 添加ID字段（如果需要）
            if (currentActionId !== '') {
                const idInput = document.createElement('input');
                idInput.type = 'hidden';
                // 根据操作类型设置不同的ID字段名
                switch(currentClearAction) {
                    case 'delete_group':
                        idInput.name = 'group_id';
                        break;
                    case 'deactivate_user':
                    case 'delete_user':
                        idInput.name = 'user_id';
                        break;
                }
                idInput.value = currentActionId;
                form.appendChild(idInput);
            }
            
            // 添加到页面并提交
            document.body.appendChild(form);
            form.submit();
            
            // 关闭弹窗
            closeClearDataModal();
        }
        
        // 显示操作结果弹窗
        function showResultModal(success, title, message) {
            const modal = document.getElementById('result-modal');
            const iconEl = document.getElementById('result-icon');
            const titleEl = document.getElementById('result-title');
            const messageEl = document.getElementById('result-message');
            
            // 设置图标
            iconEl.textContent = success ? '✅' : '❌';
            
            // 设置标题和消息
            titleEl.textContent = title;
            messageEl.textContent = message;
            
            // 显示弹窗
            modal.style.display = 'flex';
        }
        
        // 关闭操作结果弹窗
        function closeResultModal() {
            document.getElementById('result-modal').style.display = 'none';
        }
        
        // 封禁用户相关变量
        let currentBanUserId = '';
        
        // 显示封禁用户弹窗
        function showBanUserModal(userId, username) {
            currentBanUserId = userId;
            
            // 设置用户名
            const usernameEl = document.getElementById('ban-user-username');
            usernameEl.textContent = `用户: ${username}`;
            
            // 重置输入字段和错误提示
            document.getElementById('ban-reason').value = '';
            document.getElementById('ban-duration').value = '';
            document.getElementById('admin-password-ban').value = '';
            document.getElementById('ban-reason-error').style.display = 'none';
            document.getElementById('ban-duration-error').style.display = 'none';
            document.getElementById('admin-password-error-ban').style.display = 'none';
            
            // 显示弹窗
            document.getElementById('ban-user-modal').style.display = 'flex';
            
            // 添加事件监听器
            document.getElementById('cancel-ban-btn').addEventListener('click', closeBanUserModal);
            document.getElementById('confirm-ban-btn').addEventListener('click', handleBanUser);
        }
        
        // 关闭封禁用户弹窗
        function closeBanUserModal() {
            document.getElementById('ban-user-modal').style.display = 'none';
            
            // 移除事件监听器
            document.getElementById('cancel-ban-btn').removeEventListener('click', closeBanUserModal);
            document.getElementById('confirm-ban-btn').removeEventListener('click', handleBanUser);
        }
        
        // 处理封禁用户
        async function handleBanUser() {
            const reason = document.getElementById('ban-reason').value.trim();
            const duration = parseInt(document.getElementById('ban-duration').value);
            const adminPassword = document.getElementById('admin-password-ban').value;
            
            // 验证输入
            if (!reason) {
                document.getElementById('ban-reason-error').textContent = '请输入封禁理由';
                document.getElementById('ban-reason-error').style.display = 'block';
                return;
            }
            
            if (!duration || duration <= 0) {
                document.getElementById('ban-duration-error').textContent = '请输入有效的封禁时长';
                document.getElementById('ban-duration-error').style.display = 'block';
                return;
            }
            
            if (!adminPassword) {
                document.getElementById('admin-password-error-ban').textContent = '请输入管理员密码';
                document.getElementById('admin-password-error-ban').style.display = 'block';
                return;
            }
            
            // 验证管理员密码
            const isValid = await validatePassword(adminPassword);
            if (isValid) {
                // 密码正确，执行封禁操作
                executeBanUser(reason, duration, adminPassword);
            } else {
                // 密码错误，显示错误提示
                document.getElementById('admin-password-error-ban').style.display = 'block';
            }
        }
        
        // 执行封禁用户操作
        function executeBanUser(reason, duration, adminPassword) {
            // 创建表单
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            
            // 添加表单字段
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'ban_user';
            form.appendChild(actionInput);
            
            const userIdInput = document.createElement('input');
            userIdInput.type = 'hidden';
            userIdInput.name = 'user_id';
            userIdInput.value = currentBanUserId;
            form.appendChild(userIdInput);
            
            const reasonInput = document.createElement('input');
            reasonInput.type = 'hidden';
            reasonInput.name = 'ban_reason';
            reasonInput.value = reason;
            form.appendChild(reasonInput);
            
            const durationInput = document.createElement('input');
            durationInput.type = 'hidden';
            durationInput.name = 'ban_duration';
            durationInput.value = duration;
            form.appendChild(durationInput);
            
            const passwordInput = document.createElement('input');
            passwordInput.type = 'hidden';
            passwordInput.name = 'password';
            passwordInput.value = adminPassword;
            form.appendChild(passwordInput);
            
            // 添加到页面并提交
            document.body.appendChild(form);
            form.submit();
            
            // 关闭弹窗
            closeBanUserModal();
        }
        
        // 解除封禁相关变量
        let currentLiftBanUserId = '';
        
        // 显示解除封禁弹窗
        function showLiftBanModal(userId, username) {
            currentLiftBanUserId = userId;
            
            // 设置用户名
            const usernameEl = document.getElementById('lift-ban-username');
            usernameEl.textContent = `用户: ${username}`;
            
            // 重置输入字段和错误提示
            document.getElementById('admin-password-lift-ban').value = '';
            document.getElementById('admin-password-error-lift-ban').style.display = 'none';
            
            // 显示弹窗
            document.getElementById('lift-ban-modal').style.display = 'flex';
            
            // 添加事件监听器
            document.getElementById('cancel-lift-ban-btn').addEventListener('click', closeLiftBanModal);
            document.getElementById('confirm-lift-ban-btn').addEventListener('click', handleLiftBan);
        }
        
        // 关闭解除封禁弹窗
        function closeLiftBanModal() {
            document.getElementById('lift-ban-modal').style.display = 'none';
            
            // 移除事件监听器
            document.getElementById('cancel-lift-ban-btn').removeEventListener('click', closeLiftBanModal);
            document.getElementById('confirm-lift-ban-btn').removeEventListener('click', handleLiftBan);
        }
        
        // 处理解除封禁
        async function handleLiftBan() {
            const adminPassword = document.getElementById('admin-password-lift-ban').value;
            
            if (!adminPassword) {
                document.getElementById('admin-password-error-lift-ban').textContent = '请输入管理员密码';
                document.getElementById('admin-password-error-lift-ban').style.display = 'block';
                return;
            }
            
            // 验证管理员密码
            const isValid = await validatePassword(adminPassword);
            if (isValid) {
                // 密码正确，执行解除封禁操作
                executeLiftBan(adminPassword);
            } else {
                // 密码错误，显示错误提示
                document.getElementById('admin-password-error-lift-ban').style.display = 'block';
            }
        }
        
        // 执行解除封禁操作
        function executeLiftBan(adminPassword) {
            // 创建表单
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            
            // 添加表单字段
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'lift_ban';
            form.appendChild(actionInput);
            
            const userIdInput = document.createElement('input');
            userIdInput.type = 'hidden';
            userIdInput.name = 'user_id';
            userIdInput.value = currentLiftBanUserId;
            form.appendChild(userIdInput);
            
            const passwordInput = document.createElement('input');
            passwordInput.type = 'hidden';
            passwordInput.name = 'password';
            passwordInput.value = adminPassword;
            form.appendChild(passwordInput);
            
            // 添加到页面并提交
            document.body.appendChild(form);
            form.submit();
            
            // 关闭弹窗
            closeLiftBanModal();
        }
        
        // 修改密码相关变量
        let currentUserId = '';
        
        // 显示修改密码弹窗
        function showChangePasswordModal(userId, username) {
            currentUserId = userId;
            
            // 设置用户名
            const usernameEl = document.getElementById('change-password-username');
            usernameEl.textContent = `用户: ${username}`;
            
            // 重置输入字段和错误提示
            document.getElementById('new-password').value = '';
            document.getElementById('admin-password-change').value = '';
            document.getElementById('password-error').style.display = 'none';
            document.getElementById('admin-password-error-change').style.display = 'none';
            
            // 显示弹窗
            document.getElementById('change-password-modal').style.display = 'flex';
            
            // 添加事件监听器
            document.getElementById('cancel-change-password-btn').addEventListener('click', closeChangePasswordModal);
            document.getElementById('confirm-change-password-btn').addEventListener('click', handleChangePassword);
            document.getElementById('new-password').addEventListener('input', handlePasswordInputChange);
        }
        
        // 关闭修改密码弹窗
        function closeChangePasswordModal() {
            document.getElementById('change-password-modal').style.display = 'none';
            
            // 移除事件监听器
            document.getElementById('cancel-change-password-btn').removeEventListener('click', closeChangePasswordModal);
            document.getElementById('confirm-change-password-btn').removeEventListener('click', handleChangePassword);
            document.getElementById('new-password').removeEventListener('input', handlePasswordInputChange);
        }
        
        // 处理密码输入变化
        function handlePasswordInputChange() {
            // 隐藏密码错误提示
            document.getElementById('password-error').style.display = 'none';
        }
        
        // 检查密码复杂度
        function checkPasswordComplexity(password) {
            let complexity = 0;
            
            // 检查是否包含小写字母
            if (/[a-z]/.test(password)) complexity++;
            
            // 检查是否包含大写字母
            if (/[A-Z]/.test(password)) complexity++;
            
            // 检查是否包含数字
            if (/\d/.test(password)) complexity++;
            
            // 检查是否包含特殊符号
            if (/[^a-zA-Z0-9]/.test(password)) complexity++;
            
            return complexity >= 2;
        }
        
        // 处理修改密码
        async function handleChangePassword() {
            const newPassword = document.getElementById('new-password').value;
            const adminPassword = document.getElementById('admin-password-change').value;
            
            // 检查密码复杂度
            if (!newPassword) {
                document.getElementById('password-error').textContent = '请输入新密码';
                document.getElementById('password-error').style.display = 'block';
                return;
            }
            
            if (!checkPasswordComplexity(newPassword)) {
                document.getElementById('password-error').textContent = '密码不符合安全要求，请包含至少2种字符类型（大小写字母、数字、特殊符号）';
                document.getElementById('password-error').style.display = 'block';
                return;
            }
            
            if (!adminPassword) {
                document.getElementById('admin-password-error-change').textContent = '请输入管理员密码';
                document.getElementById('admin-password-error-change').style.display = 'block';
                return;
            }
            
            // 验证管理员密码
            const isValid = await validatePassword(adminPassword);
            if (isValid) {
                // 密码正确，执行修改密码操作
                executeChangePassword(newPassword);
            } else {
                // 密码错误，显示错误提示
                document.getElementById('admin-password-error-change').style.display = 'block';
            }
        }
        
        // 执行修改密码操作
        function executeChangePassword(newPassword) {
            const adminPassword = document.getElementById('admin-password-change').value;
            
            // 创建表单
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            
            // 添加表单字段
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'change_password';
            form.appendChild(actionInput);
            
            const userIdInput = document.createElement('input');
            userIdInput.type = 'hidden';
            userIdInput.name = 'user_id';
            userIdInput.value = currentUserId;
            form.appendChild(userIdInput);
            
            const newPasswordInput = document.createElement('input');
            newPasswordInput.type = 'hidden';
            newPasswordInput.name = 'new_password';
            newPasswordInput.value = newPassword;
            form.appendChild(newPasswordInput);
            
            const passwordInput = document.createElement('input');
            passwordInput.type = 'hidden';
            passwordInput.name = 'password';
            passwordInput.value = adminPassword;
            form.appendChild(passwordInput);
            
            // 添加到页面并提交
            document.body.appendChild(form);
            form.submit();
            
            // 关闭弹窗
            closeChangePasswordModal();
        }
        
        // 忘记密码申请相关变量
        let currentRequestId = '';
        
        // 显示通过忘记密码申请弹窗
        function showApprovePasswordModal(requestId, username) {
            currentRequestId = requestId;
            
            // 设置用户名
            const usernameEl = document.getElementById('approve-password-username');
            usernameEl.textContent = `用户: ${username}`;
            
            // 重置输入字段和错误提示
            document.getElementById('admin-password-approve').value = '';
            document.getElementById('admin-password-error-approve').style.display = 'none';
            
            // 显示弹窗
            document.getElementById('approve-password-modal').style.display = 'flex';
            
            // 添加事件监听器
            document.getElementById('cancel-approve-btn').addEventListener('click', closeApprovePasswordModal);
            document.getElementById('confirm-approve-btn').addEventListener('click', handleApprovePassword);
        }
        
        // 关闭通过忘记密码申请弹窗
        function closeApprovePasswordModal() {
            document.getElementById('approve-password-modal').style.display = 'none';
            
            // 移除事件监听器
            document.getElementById('cancel-approve-btn').removeEventListener('click', closeApprovePasswordModal);
            document.getElementById('confirm-approve-btn').removeEventListener('click', handleApprovePassword);
        }
        
        // 处理通过忘记密码申请
        async function handleApprovePassword() {
            const adminPassword = document.getElementById('admin-password-approve').value;
            
            if (!adminPassword) {
                document.getElementById('admin-password-error-approve').textContent = '请输入管理员密码';
                document.getElementById('admin-password-error-approve').style.display = 'block';
                return;
            }
            
            // 验证管理员密码
            const isValid = await validatePassword(adminPassword);
            if (isValid) {
                // 密码正确，执行通过操作
                executeApprovePassword(adminPassword);
            } else {
                // 密码错误，显示错误提示
                document.getElementById('admin-password-error-approve').style.display = 'block';
            }
        }
        
        // 执行通过忘记密码申请操作
        function executeApprovePassword(adminPassword) {
            // 创建表单
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            
            // 添加表单字段
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'approve_password_request';
            form.appendChild(actionInput);
            
            const requestIdInput = document.createElement('input');
            requestIdInput.type = 'hidden';
            requestIdInput.name = 'request_id';
            requestIdInput.value = currentRequestId;
            form.appendChild(requestIdInput);
            
            const passwordInput = document.createElement('input');
            passwordInput.type = 'hidden';
            passwordInput.name = 'password';
            passwordInput.value = adminPassword;
            form.appendChild(passwordInput);
            
            // 添加到页面并提交
            document.body.appendChild(form);
            form.submit();
            
            // 关闭弹窗
            closeApprovePasswordModal();
        }
        
        // 显示拒绝忘记密码申请弹窗
        function showRejectPasswordModal(requestId, username) {
            currentRequestId = requestId;
            
            // 设置用户名
            const usernameEl = document.getElementById('reject-password-username');
            usernameEl.textContent = `用户: ${username}`;
            
            // 显示弹窗
            document.getElementById('reject-password-modal').style.display = 'flex';
            
            // 添加事件监听器
            document.getElementById('cancel-reject-btn').addEventListener('click', closeRejectPasswordModal);
            document.getElementById('confirm-reject-btn').addEventListener('click', executeRejectPassword);
        }
        
        // 关闭拒绝忘记密码申请弹窗
        function closeRejectPasswordModal() {
            document.getElementById('reject-password-modal').style.display = 'none';
            
            // 移除事件监听器
            document.getElementById('cancel-reject-btn').removeEventListener('click', closeRejectPasswordModal);
            document.getElementById('confirm-reject-btn').removeEventListener('click', executeRejectPassword);
        }
        
        // 执行拒绝忘记密码申请操作
        function executeRejectPassword() {
            // 创建表单
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            
            // 添加表单字段
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'reject_password_request';
            form.appendChild(actionInput);
            
            const requestIdInput = document.createElement('input');
            requestIdInput.type = 'hidden';
            requestIdInput.name = 'request_id';
            requestIdInput.value = currentRequestId;
            form.appendChild(requestIdInput);
            
            // 添加到页面并提交
            document.body.appendChild(form);
            form.submit();
            
            // 关闭弹窗
            closeRejectPasswordModal();
        }
        
        // 修改用户名称相关变量
        let currentUserIdChange = '';
        let currentUsername = '';
        
        // 显示修改用户名称弹窗
        function showChangeUsernameModal(userId, username) {
            currentUserIdChange = userId;
            currentUsername = username;
            
            // 设置当前用户名
            const currentUsernameEl = document.getElementById('change-username-current');
            currentUsernameEl.textContent = `当前名称: ${username}`;
            
            // 重置输入字段和错误提示
            document.getElementById('new-username').value = '';
            document.getElementById('admin-password-username').value = '';
            document.getElementById('username-error').style.display = 'none';
            document.getElementById('admin-password-error-username').style.display = 'none';
            
            // 显示弹窗
            document.getElementById('change-username-modal').style.display = 'flex';
            
            // 添加事件监听器
            document.getElementById('cancel-change-username-btn').addEventListener('click', closeChangeUsernameModal);
            document.getElementById('confirm-change-username-btn').addEventListener('click', handleChangeUsername);
        }
        
        // 关闭修改用户名称弹窗
        function closeChangeUsernameModal() {
            document.getElementById('change-username-modal').style.display = 'none';
            
            // 移除事件监听器
            document.getElementById('cancel-change-username-btn').removeEventListener('click', closeChangeUsernameModal);
            document.getElementById('confirm-change-username-btn').removeEventListener('click', handleChangeUsername);
        }
        
        // 处理修改用户名称
        async function handleChangeUsername() {
            const newUsername = document.getElementById('new-username').value.trim();
            const adminPassword = document.getElementById('admin-password-username').value;
            
            // 验证新名称
            if (!newUsername) {
                document.getElementById('username-error').textContent = '请输入新名称';
                document.getElementById('username-error').style.display = 'block';
                return;
            }
            
            if (newUsername === currentUsername) {
                document.getElementById('username-error').textContent = '新名称与当前名称相同';
                document.getElementById('username-error').style.display = 'block';
                return;
            }
            
            // 检查名称长度
            const maxLength = <?php echo getUserNameMaxLength(); ?>;
            if (newUsername.length < 3 || newUsername.length > maxLength) {
                document.getElementById('username-error').textContent = `名称长度必须在3-${maxLength}个字符之间`;
                document.getElementById('username-error').style.display = 'block';
                return;
            }
            
            if (!adminPassword) {
                document.getElementById('admin-password-error-username').textContent = '请输入管理员密码';
                document.getElementById('admin-password-error-username').style.display = 'block';
                return;
            }
            
            // 验证管理员密码
            const isValid = await validatePassword(adminPassword);
            if (isValid) {
                // 密码正确，执行修改名称操作
                executeChangeUsername(newUsername, adminPassword);
            } else {
                // 密码错误，显示错误提示
                document.getElementById('admin-password-error-username').style.display = 'block';
            }
        }
        
        // 执行修改用户名称操作
        function executeChangeUsername(newUsername, adminPassword) {
            // 创建表单
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            
            // 添加表单字段
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'change_username';
            form.appendChild(actionInput);
            
            const userIdInput = document.createElement('input');
            userIdInput.type = 'hidden';
            userIdInput.name = 'user_id';
            userIdInput.value = currentUserIdChange;
            form.appendChild(userIdInput);
            
            const newUsernameInput = document.createElement('input');
            newUsernameInput.type = 'hidden';
            newUsernameInput.name = 'new_username';
            newUsernameInput.value = newUsername;
            form.appendChild(newUsernameInput);
            
            const passwordInput = document.createElement('input');
            passwordInput.type = 'hidden';
            passwordInput.name = 'password';
            passwordInput.value = adminPassword;
            form.appendChild(passwordInput);
            
            // 添加到页面并提交
            document.body.appendChild(form);
            form.submit();
            
            // 关闭弹窗
            closeChangeUsernameModal();
        }
    </script>
</body>
</html>
