<?php
require_once __DIR__ . '/install_check.php';
// 检查系统维护模式
require_once 'config.php';
if (getConfig('System_Maintenance', 0) == 1) {
    $maintenance_page = getConfig('System_Maintenance_page', 'cloudflare_error.html');
    include 'Maintenance/' . $maintenance_page;
    exit;
}

// 检查用户是否登录
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'db.php';
require_once 'User.php';
require_once 'Friend.php';
require_once 'Message.php';
require_once 'Group.php';

// 检查并创建群聊相关数据表
function createGroupTables() {
    global $conn;
    
    $create_tables_sql = "
    -- 创建群聊表
    CREATE TABLE IF NOT EXISTS groups (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        creator_id INT NOT NULL,
        owner_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (creator_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    
    -- 创建群聊成员表
    CREATE TABLE IF NOT EXISTS group_members (
        id INT AUTO_INCREMENT PRIMARY KEY,
        group_id INT NOT NULL,
        user_id INT NOT NULL,
        is_admin BOOLEAN DEFAULT FALSE,
        joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY unique_group_user (group_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    
    -- 创建群聊消息表
    CREATE TABLE IF NOT EXISTS group_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        group_id INT NOT NULL,
        sender_id INT NOT NULL,
        content TEXT,
        file_path VARCHAR(255),
        file_name VARCHAR(255),
        file_size INT,
        file_type VARCHAR(50),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE,
        FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    
    -- 创建聊天设置表
    CREATE TABLE IF NOT EXISTS chat_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        chat_type ENUM('friend', 'group') NOT NULL,
        chat_id INT NOT NULL,
        is_muted BOOLEAN DEFAULT FALSE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_user_chat (user_id, chat_type, chat_id),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    
    try {
        if ($conn) {
            $conn->exec($create_tables_sql);
            
            // 添加缺失的file_type列（兼容性处理）
            $stmt = $conn->prepare("SHOW COLUMNS FROM messages LIKE 'file_type'");
            $stmt->execute();
            if (!$stmt->fetch()) {
                $conn->exec("ALTER TABLE messages ADD COLUMN file_type VARCHAR(50) NULL");
            }
            
            $stmt = $conn->prepare("SHOW COLUMNS FROM group_messages LIKE 'file_type'");
            $stmt->execute();
            if (!$stmt->fetch()) {
                $conn->exec("ALTER TABLE group_messages ADD COLUMN file_type VARCHAR(50) NULL");
            }
        }
        error_log("群聊相关数据表创建成功");
    } catch (PDOException $e) {
        error_log("创建群聊数据表失败：" . $e->getMessage());
    }
}


function isMobileDevice() {
    $userAgent = $_SERVER['HTTP_USER_AGENT'];
    $mobileAgents = array('Android', 'iPhone', 'iPad', 'iPod', 'BlackBerry', 'Windows Phone', 'Mobile', 'Opera Mini', 'Fennec', 'IEMobile');
    foreach ($mobileAgents as $agent) {
        if (stripos($userAgent, $agent) !== false) {
            return true;
        }
    }
    return false;
}

// 如果不是手机设备，跳转到桌面端聊天页面
if (!isMobileDevice()) {
    header('Location: chat.php');
    exit;
}

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// 调用函数创建数据表
createGroupTables();

// 检查并添加密保相关字段到users表
try {
    // 检查has_security_question字段
    $stmt = $conn->prepare("SHOW COLUMNS FROM users LIKE 'has_security_question'");
    $stmt->execute();
    $column_exists = $stmt->fetch();
    
    if (!$column_exists) {
        // 添加密保相关字段
        $conn->exec("ALTER TABLE users ADD COLUMN has_security_question BOOLEAN DEFAULT FALSE AFTER is_deleted");
        $conn->exec("ALTER TABLE users ADD COLUMN security_question VARCHAR(255) DEFAULT NULL AFTER has_security_question");
        $conn->exec("ALTER TABLE users ADD COLUMN security_answer VARCHAR(255) DEFAULT NULL AFTER security_question");
        error_log("Added security question columns to users table");
    }
} catch (PDOException $e) {
    error_log("Error checking/adding security question columns: " . $e->getMessage());
}

// 检查是否启用了全员群聊功能，如果启用了，确保全员群聊存在并包含所有用户
$create_all_group = getConfig('Create_a_group_chat_for_all_members', false);
if ($create_all_group) {
    // 检查是否需要添加all_user_group字段
    try {
        $stmt = $conn->prepare("SHOW COLUMNS FROM groups LIKE 'all_user_group'");
        $stmt->execute();
        $column_exists = $stmt->fetch();
        
        if (!$column_exists) {
            // 添加all_user_group字段
            $conn->exec("ALTER TABLE groups ADD COLUMN all_user_group INT DEFAULT 0 AFTER owner_id");
            error_log("Added all_user_group column to groups table");
        }
    } catch (PDOException $e) {
        error_log("Error checking/adding all_user_group column: " . $e->getMessage());
    }
    
    $group = new Group($conn);
    $group->ensureAllUserGroups($_SESSION['user_id']);
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];

// 创建实例
$user = new User($conn);
$friend = new Friend($conn);
$message = new Message($conn);
$group = new Group($conn);

// 处理密保设置
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'set_security_question') {
    $security_question = isset($_POST['security_question']) ? trim($_POST['security_question']) : '';
    $security_answer = isset($_POST['security_answer']) ? trim($_POST['security_answer']) : '';
    
    if (!empty($security_question) && !empty($security_answer)) {
        try {
            // 加密答案
            $hashed_answer = password_hash($security_answer, PASSWORD_DEFAULT);
            
            // 更新用户密保信息
            $stmt = $conn->prepare("UPDATE users SET has_security_question = TRUE, security_question = ?, security_answer = ? WHERE id = ?");
            $stmt->execute([$security_question, $hashed_answer, $user_id]);
            
            // 重新获取用户信息
            $current_user = $user->getUserById($user_id);
        } catch (PDOException $e) {
            error_log("Error setting security question: " . $e->getMessage());
        }
    }
}

// 获取当前用户信息
$current_user = $user->getUserById($user_id);

// 检查用户是否需要设置密保
$need_security_question = false;
if (isset($current_user['has_security_question']) && !$current_user['has_security_question']) {
    $need_security_question = true;
}

// 获取好友列表
$friends = $friend->getFriends($user_id);

// 获取群聊列表
$groups = $group->getUserGroups($user_id);

// 获取待处理的好友请求
$pending_requests = $friend->getPendingRequests($user_id);
$pending_requests_count = count($pending_requests);

// 获取未读消息计数
$unread_counts = [];
try {
    // 确保unread_messages表存在
    $stmt = $conn->prepare("SHOW TABLES LIKE 'unread_messages'");
    $stmt->execute();
    if ($stmt->fetch()) {
        $stmt = $conn->prepare("SELECT * FROM unread_messages WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $unread_records = $stmt->fetchAll();
        
        foreach ($unread_records as $record) {
            $key = $record['chat_type'] . '_' . $record['chat_id'];
            $unread_counts[$key] = $record['count'];
        }
    }
} catch (PDOException $e) {
    error_log("Get unread counts error: " . $e->getMessage());
}

// 获取当前选中的聊天对象
$chat_type = isset($_GET['chat_type']) ? $_GET['chat_type'] : 'friend'; // 'friend' 或 'group'
$selected_id = isset($_GET['id']) ? $_GET['id'] : null;
$selected_friend = null;
$selected_group = null;

// 初始化变量
$selected_friend_id = null;

// 如果没有选中的聊天对象，不自动选择
if (!$selected_id) {
    // 手机端保持在列表页
} else {
    // 有选中的聊天对象，获取详细信息
    if ($chat_type === 'friend') {
        $selected_friend = $user->getUserById($selected_id);
        $selected_friend_id = $selected_id;
    } elseif ($chat_type === 'group') {
        $selected_group = $group->getGroupInfo($selected_id);
    }
}

// 获取聊天记录
$chat_history = [];
if ($chat_type === 'friend' && $selected_id) {
    $chat_history = $message->getChatHistory($user_id, $selected_id);
} elseif ($chat_type === 'group' && $selected_id) {
    $chat_history = $group->getGroupMessages($selected_id, $user_id);
}

// 更新用户状态为在线
$user->updateStatus($user_id, 'online');

// 检查用户是否被封禁
$ban_info = $user->isBanned($user_id);

// 检查用户是否同意协议
$agreed_to_terms = $user->hasAgreedToTerms($user_id);

// 获取用户IP地址
$user_ip = $_SERVER['REMOTE_ADDR'];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>手机端 - Modern Chat</title>
    <link rel="icon" href="aconvert.ico" type="image/x-icon">
    <style>
        :root {
            /* 浅色模式变量 */
            --bg-color: #f2f2f2;
            --text-color: #333333;
            --text-secondary: #666666;
            --text-desc: #999999;
            --border-color: rgba(234, 234, 234, 0.5);
            --panel-bg: rgba(255, 255, 255, 0.95);
            --sidebar-bg: rgba(247, 247, 247, 0.9);
            --message-sent-bg: #95ec69;
            --message-received-bg: rgba(255, 255, 255, 0.9);
            --hover-bg: rgba(245, 245, 245, 0.8);
            --modal-bg: rgba(255, 255, 255, 0.98);
            --input-bg: rgba(255, 255, 255, 0.9);
            --shadow-color: rgba(0,0,0,0.1);
            --primary-color: #667eea;
            --danger-color: #ff4d4f;
            --header-bg: rgba(255, 255, 255, 0.9);
        }

        [data-theme="dark"] {
            /* 深色模式变量 */
            --bg-color: #1a1b1e;
            --text-color: #e1e1e6;
            --text-secondary: #a1a1aa;
            --text-desc: #71717a;
            --border-color: rgba(44, 46, 51, 0.5);
            --panel-bg: rgba(37, 38, 43, 0.95);
            --sidebar-bg: rgba(32, 33, 36, 0.9);
            --message-sent-bg: rgba(55, 65, 81, 0.9);
            --message-received-bg: rgba(44, 46, 51, 0.9);
            --hover-bg: rgba(44, 46, 51, 0.8);
            --modal-bg: rgba(37, 38, 43, 0.98);
            --input-bg: rgba(44, 46, 51, 0.9);
            --shadow-color: rgba(0,0,0,0.5);
            --primary-color: #5c7cfa;
            --danger-color: #ff6b6b;
            --header-bg: rgba(32, 33, 36, 0.9);
        }

        body {
            background-color: var(--bg-color);
            color: var(--text-color);
            transition: background-color 0.3s, color 0.3s;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Microsoft YaHei', Tahoma, Geneva, Verdana, sans-serif;
            background: url('https://bing.biturl.top/?resolution=1920&format=image&index=0&mkt=zh-CN') no-repeat center center fixed;
            background-size: cover;
            height: 100vh;
            overflow: hidden;
            color: var(--text-color);
            transition: color 0.3s;
        }
        
        /* 主容器 */
        .chat-container {
            display: flex;
            height: 100vh;
            background: transparent;
            width: 100vw;
            overflow: hidden;
        }
        
        /* 左侧边栏 - 微信风格 */
        .sidebar {
            width: 100%;
            background: transparent;
            display: flex;
            flex-direction: column;
            flex: 1;
            max-width: none;
            backdrop-filter: blur(10px);
        }
        
        /* 左侧边栏顶部 - 用户信息 */
        .sidebar-header {
            height: 75px;
            background: var(--sidebar-bg);
            display: flex;
            align-items: center;
            padding: 10px 15px;
        }

        /* 三条横杠菜单按钮 */
        .menu-toggle-btn {
            background: none;
            border: none;
            font-size: 20px;
            cursor: pointer;
            margin-right: 15px;
            color: #666;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.2s ease;
        }

        .menu-toggle-btn:hover {
            background: #e5e5e5;
            color: #12b7f5;
        }

        /* 菜单面板 */
        .menu-panel {
            position: fixed;
            top: 0;
            left: -100%;
            width: 80%;
            max-width: 300px;
            height: 100vh;
            background: var(--modal-bg);
            box-shadow: 2px 0 10px var(--shadow-color);
            transition: left 0.3s ease;
            z-index: 1000;
            overflow-y: auto;
        }

        .menu-panel.open {
            left: 0;
        }

        .menu-header {
            padding: 20px;
            background: var(--sidebar-bg);
            color: var(--text-color);
            text-align: center;
            border-bottom: 1px solid var(--border-color);
        }

        .menu-avatar {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 24px;
            margin: 0 auto 15px;
        }

        .menu-username {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 5px;
            color: var(--text-color);
        }

        .menu-email {
            font-size: 13px;
            margin-bottom: 5px;
            color: var(--text-secondary);
        }

        .menu-ip {
            font-size: 11px;
            color: var(--text-desc);
        }

        .menu-items {
            padding: 15px;
        }

        .menu-item {
            display: block;
            width: 100%;
            padding: 12px;
            margin-bottom: 8px;
            background: var(--panel-bg);
            color: var(--text-color);
            border: 1px solid var(--border-color);
            border-radius: 4px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            text-align: left;
            text-decoration: none;
        }

        .menu-item:hover {
            background: var(--hover-bg);
            border-color: var(--primary-color);
            color: var(--primary-color);
        }

        .menu-item-danger {
            background: #fff;
            color: #ff4757;
            border-color: #ff4757;
        }

        .menu-item-danger:hover {
            background: #fff5f5;
            border-color: #ff4757;
            color: #ff4757;
        }

        /* 遮罩层 */
        .overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
            z-index: 999;
        }

        .overlay.open {
            opacity: 1;
            visibility: visible;
        }
        
        .user-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            margin-right: 10px;
        }
        
        .user-info {
            flex: 1;
        }
        
        .user-name {
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 2px;
            /* 如果不是好友或未加入群聊，隐藏名称 */
            display: <?php 
                if ($chat_type === 'friend') {
                    echo ($selected_friend_id && !$friend->isFriend($user_id, $selected_friend_id)) ? 'none' : 'block';
                } elseif ($chat_type === 'group') {
                    echo ($selected_id && !$group->isUserInGroup($selected_id, $user_id)) ? 'none' : 'block';
                } else {
                    echo 'block';
                }
            ?>;
        }
        
        .user-ip {
            font-size: 11px;
            color: #999;
            /* 如果不是好友或未加入群聊，隐藏IP/在线状态 */
            display: <?php 
                if ($chat_type === 'friend') {
                    echo ($selected_friend_id && !$friend->isFriend($user_id, $selected_friend_id)) ? 'none' : 'block';
                } elseif ($chat_type === 'group') {
                    echo ($selected_id && !$group->isUserInGroup($selected_id, $user_id)) ? 'none' : 'block';
                } else {
                    echo 'block';
                }
            ?>;
        }
        
        /* 搜索栏 */
        .search-bar {
            padding: 10px 15px;
            background: var(--sidebar-bg);
        }
        
        .search-input {
            width: 100%;
            padding: 8px 12px;
            border: none;
            border-radius: 18px;
            font-size: 13px;
            background: var(--input-bg);
            outline: none;
            transition: all 0.3s ease;
            color: var(--text-color);
        }
        
        .search-input:focus {
            background: var(--hover-bg);
            box-shadow: none;
        }
        
        /* 聊天列表 */
        .chat-list {
            flex: 1;
            overflow-y: auto;
        }
        
        /* 聊天列表项 */
        .chat-item {
            display: flex;
            align-items: center;
            padding: 20px 15px;
            padding-right: 60px; /* 为右侧菜单按钮留出足够空间 */
            cursor: pointer;
            transition: background-color 0.2s ease;
            position: relative;
            height: auto;
            min-height: 80px;
            background: var(--panel-bg);
            border-bottom: 1px solid var(--border-color);
            overflow: visible !important;
        }
        
        /* 聊天项菜单容器 */
        .chat-item-menu {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            display: flex;
            align-items: center;
        }
        
        /* 聊天项菜单按钮 */
        .chat-item-menu-btn {
            width: 36px;
            height: 36px;
            border: none;
            background: transparent;
            color: #999;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            transition: all 0.2s ease;
            opacity: 1;
        }
        
        /* 聊天项菜单按钮悬停效果 */
        .chat-item-menu-btn:hover {
            background: #f0f0f0;
            color: #12b7f5;
        }
        
        /* 聊天项菜单按钮点击效果 */
        .chat-item-menu-btn:active {
            background: #e0e0e0;
        }
        
        /* 好友菜单样式 */
        .friend-menu {
            position: absolute;
            top: 100%;
            right: 0;
            background: var(--modal-bg);
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
            z-index: 99999;
            min-width: 120px;
            margin-top: 5px;
            overflow: visible;
            border: 1px solid var(--border-color);
        }
        
        /* 确保聊天列表不会裁剪菜单 */
        .chat-list {
            overflow-y: auto;
            overflow-x: visible;
        }
        
        /* 好友菜单项样式 */
        .friend-menu-item {
            display: block;
            width: 100%;
            padding: 12px 15px;
            border: none;
            background: transparent;
            cursor: pointer;
            text-align: left;
            font-size: 14px;
            color: var(--text-color);
            transition: background-color 0.2s;
            border-radius: 8px;
        }
        
        /* 好友菜单项悬停效果 */
        .friend-menu-item:hover {
            background-color: var(--hover-bg);
        }
        
        .chat-item:hover {
            background-color: var(--hover-bg);
        }
        
        .chat-item.active {
            background-color: var(--hover-bg);
        }
        
        .chat-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 18px;
            margin-right: 16px;
        }
        
        .chat-avatar.group {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }
        
        .chat-info {
            flex: 1;
            min-width: 0;
        }
        
        .chat-name {
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            color: var(--text-color);
        }
        
        .chat-last-message {
            font-size: 12px;
            color: var(--text-desc);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .chat-time {
            font-size: 11px;
            color: var(--text-desc);
            margin-left: 8px;
        }
        
        .unread-count {
            background: #ff4d4f;
            color: white;
            font-size: 10px;
            font-weight: 600;
            padding: 2px 6px;
            border-radius: 10px;
            margin-left: 8px;
            min-width: 18px;
            text-align: center;
        }
        
        /* 左侧边栏底部 - 功能图标 */
        .sidebar-footer {
            height: 60px;
            background: var(--sidebar-bg);
            border-top: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-around;
            padding: 0 15px;
        }
        
        .footer-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            cursor: pointer;
            color: var(--text-secondary);
            transition: all 0.2s ease;
        }
        
        .footer-icon:hover {
            background-color: var(--hover-bg);
            color: var(--primary-color);
        }
        
        /* 聊天区域 */
        .chat-area {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: var(--bg-color);
            width: 100%;
            height: 100vh;
        }
        
        /* 聊天区域顶部 - 对方信息 */
        .chat-header {
            height: 60px;
            background: var(--header-bg);
            display: flex;
            align-items: center;
            padding: 0 15px;
        }
        
        .chat-header-info {
            flex: 1;
        }
        
        .chat-header-name {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 2px;
            /* 如果不是好友或未加入群聊，隐藏聊天对象名称 */
            display: <?php 
                if ($chat_type === 'friend') {
                    echo ($selected_friend_id && !$friend->isFriend($user_id, $selected_friend_id)) ? 'none' : 'block';
                } elseif ($chat_type === 'group') {
                    echo ($selected_id && !$group->isUserInGroup($selected_id, $user_id)) ? 'none' : 'block';
                } else {
                    echo 'block';
                }
            ?>;
        }
        
        .chat-header-status {
            font-size: 12px;
            color: var(--text-desc);
            /* 如果不是好友或未加入群聊，隐藏聊天对象状态 */
            display: <?php 
                if ($chat_type === 'friend') {
                    echo ($selected_friend_id && !$friend->isFriend($user_id, $selected_friend_id)) ? 'none' : 'block';
                } elseif ($chat_type === 'group') {
                    echo ($selected_id && !$group->isUserInGroup($selected_id, $user_id)) ? 'none' : 'block';
                } else {
                    echo 'block';
                }
            ?>;
        }
        
        /* 消息容器 */
        .messages-container {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
            background: var(--bg-color);
            min-height: 0;
            height: calc(100% - 120px);
            max-height: calc(100% - 120px);
            overflow-x: hidden;
        }
        
        /* 消息气泡 */
        .message {
            display: flex;
            margin-bottom: 15px;
            animation: messageSlide 0.3s ease-out;
            align-items: flex-end;
        }
        
        .message-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 14px;
        }
        
        .message.sent .message-avatar {
            margin: 0 0 0 8px;
        }
        
        .message.received .message-avatar {
            margin: 0 8px 0 0;
        }
        
        @keyframes messageSlide {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .message.sent {
            display: flex;
            justify-content: flex-end;
            flex-direction: row;
            margin-bottom: 15px;
        }
        
        .message.received {
            display: flex;
            justify-content: flex-start;
            flex-direction: row;
            margin-bottom: 15px;
        }
        
        .message-content {
            max-width: 70%;
            padding: 10px 15px;
            border-radius: 18px;
            font-size: 14px;
            line-height: 1.4;
            word-wrap: break-word;
        }
        
        .message.sent .message-content {
            background: var(--message-sent-bg);
            color: var(--text-color);
            border-bottom-right-radius: 4px;
        }
        
        .message.received .message-content {
            background: var(--message-received-bg);
            color: var(--text-color);
            border-bottom-left-radius: 4px;
        }
        
        .message-time {
            font-size: 11px;
            color: var(--text-desc);
            margin-top: 4px;
            text-align: right;
        }
        
        /* 输入区域 */
        .input-area {
            background: var(--header-bg);
            padding: 15px;
            display: block !important;
            visibility: visible !important;
        }
        
        .input-container {
            display: flex;
            align-items: flex-end;
            gap: 10px;
            background: var(--input-bg);
            padding: 10px 15px;
            border-radius: 20px;
            box-shadow: 0 1px 3px var(--shadow-color);
            width: 100%;
        }
        
        .input-wrapper {
            flex: 1;
        }
        
        #message-input {
            width: 100%;
            border: none;
            background: transparent;
            font-size: 14px;
            resize: none;
            outline: none;
            max-height: 120px;
            overflow-y: auto;
            color: var(--text-color);
        }
        
        .input-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .btn-icon {
            width: 40px;
            height: 40px;
            border: none;
            background: transparent;
            color: #666;
            border-radius: 50%;
            cursor: pointer;
            display: flex !important;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            transition: all 0.2s ease;
            visibility: visible !important;
        }
        
        .btn-icon:hover {
            background: #e5e5e5;
            color: #12b7f5;
        }
        
        /* 滚动条样式 - 微信风格 */
        ::-webkit-scrollbar {
            width: 6px;
        }
        
        ::-webkit-scrollbar-track {
            background: #f1f1f1;
        }
        
        ::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 3px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: #a1a1a1;
        }
        
        /* 模态框样式 */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 5000;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        
        .modal-content {
            background: var(--modal-bg);
            color: var(--text-color);
            border-radius: 8px;
            padding: 20px;
            max-width: 90%;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        /* 聊天类型切换 */
        .chat-type-tabs {
            display: flex;
            background: #f6f6f6;
            border-bottom: 1px solid #eaeaea;
        }
        
        .chat-type-tab {
            flex: 1;
            padding: 12px;
            border: none;
            background: transparent;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            color: #666;
            transition: all 0.2s ease;
        }
        
        .chat-type-tab.active {
            color: #12b7f5;
            background: #fff;
            border-bottom: 2px solid #12b7f5;
        }
        
        /* 好友申请和创建群聊按钮 */
        .action-buttons {
            background: #f6f6f6;
            border-bottom: 1px solid #eaeaea;
            padding: 10px 15px;
        }
        
        .action-btn {
            width: 100%;
            padding: 10px;
            margin-bottom: 8px;
            border: 1px solid #eaeaea;
            border-radius: 4px;
            background: #fff;
            cursor: pointer;
            font-size: 14px;
            color: #333;
            transition: all 0.2s ease;
        }
        
        .action-btn:hover {
            background: #f5f5f5;
            border-color: #12b7f5;
        }
        
        /* 状态指示器 */
        .status-indicator {
            position: absolute;
            bottom: 0;
            right: 0;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            border: 2px solid white;
        }
        
        .status-indicator.online {
            background: #4caf50;
        }
        
        .status-indicator.offline {
            background: #ffa502;
        }
        
        /* 消息媒体样式 */
        .message-media {
            margin-bottom: 10px;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        
        /* 图片样式 */
        .message-image {
            max-width: 300px;
            max-height: 300px;
            border-radius: 8px;
            cursor: pointer;
            transition: transform 0.2s ease;
        }
        
        .message-image:hover {
            transform: scale(1.05);
        }
        
        /* 音频播放器样式 */
        .custom-audio-player {
            display: flex;
            align-items: center;
            background: var(--input-bg);
            border-radius: 20px;
            padding: 20px 25px;
            max-width: 100%;
            width: 100%;
            position: relative;
            z-index: 2000;
            box-shadow: 0 6px 24px rgba(0, 0, 0, 0.15);
            transition: all 0.2s ease;
            height: auto;
            min-height: 110px;
            overflow: visible;
            border: 2px solid var(--border-color);
            color: var(--text-color);
        }
        
        /* 音频播放器头像 */
        .audio-sender-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            margin-right: 20px;
            object-fit: cover;
            border: 3px solid #12b7f5;
        }
        
        .message.sent .custom-audio-player {
            background: rgba(18, 183, 245, 0.1);
            border-color: rgba(18, 183, 245, 0.3);
        }
        
        .audio-element {
            display: none;
        }
        
        .audio-play-btn {
            width: 55px;
            height: 55px;
            border: none;
            background: linear-gradient(135deg, #12b7f5 0%, #00a2e8 100%);
            color: white;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 25px;
            transition: all 0.2s ease;
            z-index: 2001;
            position: relative;
            box-shadow: 0 5px 18px rgba(18, 183, 245, 0.5);
        }
        
        .audio-play-btn:hover {
            background: linear-gradient(135deg, #00a2e8 0%, #008cba 100%);
            transform: scale(1.15);
            box-shadow: 0 8px 24px rgba(18, 183, 245, 0.6);
        }
        
        .audio-play-btn:active {
            transform: scale(0.95);
        }
        
        .audio-play-btn.playing {
            background: linear-gradient(135deg, #ff4d4f 0%, #ff3333 100%);
            box-shadow: 0 5px 18px rgba(255, 77, 79, 0.5);
        }
        
        .audio-play-btn::before {
            content: '▶';
            font-size: 22px;
            margin-left: 5px;
            font-weight: bold;
        }
        
        .audio-play-btn.playing::before {
            content: '⏸';
            margin-left: 0;
            font-size: 20px;
        }
        
        .audio-progress-container {
            flex: 1;
            margin: 0 25px;
            position: relative;
            z-index: 2001;
        }
        
        .audio-progress-bar {
            width: 100%;
            height: 4px;
            background: rgba(0, 0, 0, 0.1);
            border-radius: 2px;
            cursor: pointer;
            overflow: visible;
            position: relative;
            z-index: 2002;
            pointer-events: all;
            transition: all 0.2s ease;
            margin-top: 0; /* 移除垂直居中微调 */
        }
        
        .audio-progress {
            height: 100%;
            background: linear-gradient(90deg, #12b7f5 0%, #00a2e8 100%);
            border-radius: 2px;
            transition: width 0.1s ease;
            position: relative;
            z-index: 2003;
        }
        
        .audio-progress::after {
            content: '';
            position: absolute;
            right: -5px;
            top: 50%;
            transform: translateY(-50%);
            width: 10px;
            height: 10px;
            background: white;
            border-radius: 50%;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.2);
            z-index: 2004;
            transition: all 0.2s ease;
            cursor: pointer;
            border: 2px solid #12b7f5;
        }
        
        .audio-progress-bar:hover {
            height: 6px;
        }
        
        .audio-progress-bar:hover .audio-progress::after {
            transform: translateY(-50%) scale(1.1);
        }
        
        .audio-time {
            font-size: 11px;
            color: #666;
            min-width: 32px;
            text-align: center;
            margin-left: 8px;
            flex-shrink: 0;
        }
        
        .message.sent .audio-time {
            color: rgba(255, 255, 255, 0.8);
        }
        
        /* 文件消息样式 */
        .message-file {
            position: relative;
            background: #f0f0f0;
            border-radius: 8px;
            padding: 12px;
            display: flex;
            align-items: center;
            gap: 12px;
            cursor: pointer;
        }
        
        .message.sent .message-file {
            background: rgba(158, 234, 106, 0.2);
        }
        
        .file-icon {
            font-size: 24px;
        }
        
        .file-info {
            flex: 1;
        }
        
        .file-info h4 {
            margin: 0;
            font-size: 14px;
            font-weight: 500;
            word-break: break-all;
        }
        
        .file-info p {
            margin: 2px 0 0 0;
            font-size: 12px;
            color: #666;
        }
        
        /* 视频播放器样式 */
        .video-container {
            max-width: 300px;
            border-radius: 8px;
            overflow: hidden;
            background: #000;
        }
        
        .video-element {
            width: 100%;
            height: auto;
            cursor: pointer;
        }

        /* 视频播放弹窗样式 */
        .video-player-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.9);
            z-index: 4000;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .video-player-modal.visible {
            display: flex;
        }

        .video-player-content {
            background: white;
            border-radius: 12px;
            width: 90%;
            max-width: 1000px;
            max-height: 80vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .video-player-header {
            padding: 15px 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .video-player-title {
            margin: 0;
            font-size: 16px;
            font-weight: 600;
        }

        .video-player-close {
            background: none;
            border: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
            padding: 0;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: background 0.2s ease;
        }

        .video-player-close:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .video-player-body {
            flex: 1;
            display: flex;
            flex-direction: column;
            padding: 20px;
            background: #000;
        }

        .video-player-iframe {
            flex: 1;
            width: 100%;
            border: none;
            border-radius: 8px;
        }

        .custom-video-player {
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
            background: #000;
            position: relative;
        }

        .custom-video-element {
            flex: 1;
            width: 100%;
            height: 100%;
            object-fit: contain;
            background: #000;
            min-height: 400px;
        }
        
        .custom-video-player {
            display: flex;
            flex-direction: column;
            background: #000;
            position: relative;
            flex: 1;
            height: 100%;
        }
        
        .video-player-body {
            flex: 1;
            display: flex;
            background: #000;
            overflow: hidden;
        }

        .video-controls {
            background: rgba(0, 0, 0, 0.8);
            color: white;
            padding: 15px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .video-progress-container {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .video-progress-bar {
            flex: 1;
            height: 8px;
            background: #333;
            border-radius: 4px;
            cursor: pointer;
            overflow: hidden;
        }

        .video-progress {
            height: 100%;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
            border-radius: 4px;
            transition: width 0.1s linear;
        }

        .video-time {
            font-size: 14px;
            color: #fff;
            min-width: 120px;
            text-align: center;
        }

        .video-controls-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .video-main-controls {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .video-control-btn {
            background: none;
            border: none;
            color: white;
            cursor: pointer;
            font-size: 20px;
            padding: 8px;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s ease;
        }

        .video-control-btn:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .video-volume-control {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .volume-slider {
            width: 100px;
            cursor: pointer;
        }

        /* 修复下载按钮被覆盖问题 */
        .download-control-btn {
            z-index: 2000;
            position: relative;
        }

        .file-action-item {
            z-index: 2000;
            position: relative;
        }
        
        /* 确保媒体操作按钮显示在最上层 */
        .media-actions {
            z-index: 4000;
        }
        
        .media-action-btn {
            z-index: 4000;
        }
        
        /* 确保文件操作菜单显示在最上层 */
        .file-actions-menu {
            z-index: 5000;
        }
        
        /* 图片查看器样式 */
        .image-viewer {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.9);
            z-index: 10002;
            justify-content: center;
            align-items: center;
            flex-direction: column;
        }
        
        .image-viewer.active {
            display: flex;
        }
        
        .image-viewer-content {
            position: relative;
            max-width: 90%;
            max-height: 90%;
        }
        
        .image-viewer-image {
            max-width: 100%;
            max-height: 100vh;
            transition: transform 0.1s ease;
            cursor: grab;
        }
        
        .image-viewer-image:active {
            cursor: grabbing;
        }
        
        .image-viewer-controls {
            position: absolute;
            top: 20px;
            right: 20px;
            background: rgba(0, 0, 0, 0.5);
            padding: 10px;
            border-radius: 8px;
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .image-viewer-btn {
            background: rgba(255, 255, 255, 0.8);
            border: none;
            color: #333;
            padding: 8px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.2s ease;
        }
        
        .image-viewer-btn:hover {
            background: white;
        }
        
        .image-viewer-close {
            position: absolute;
            top: 20px;
            right: 20px;
            background: rgba(255, 255, 255, 0.8);
            border: none;
            color: #333;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }
        
        .image-viewer-close:hover {
            background: white;
        }
        
        .zoom-level {
            color: white;
            font-size: 14px;
            margin-right: 10px;
        }

        /* 下载面板样式 - 改为中间弹窗 */
        .download-panel {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 600px;
            max-height: 80vh;
            background: white;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
            z-index: 3000;
            display: flex;
            flex-direction: column;
            display: none;
        }

        .download-panel.visible {
            display: flex;
        }

        .download-panel-header {
            padding: 15px 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-radius: 12px 12px 0 0;
        }

        .download-panel-header h3 {
            margin: 0;
            font-size: 16px;
            font-weight: 600;
        }

        .download-panel-controls {
            display: flex;
            gap: 10px;
        }

        .download-panel-btn {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            padding: 6px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.2s ease;
        }

        .download-panel-btn:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .download-panel-content {
            flex: 1;
            overflow-y: auto;
            padding: 10px;
        }

        .download-task {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 10px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .download-task-header {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .download-file-icon {
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }

        .download-file-info {
            flex: 1;
        }

        .download-file-name {
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 2px;
            word-break: break-all;
        }

        .download-file-meta {
            font-size: 12px;
            color: #666;
        }

        .download-progress-container {
            width: 100%;
            height: 6px;
            background: #e9ecef;
            border-radius: 3px;
            overflow: hidden;
        }

        .download-progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
            border-radius: 3px;
            transition: width 0.3s ease;
        }

        .download-progress-info {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            color: #666;
        }

        .download-controls {
            display: flex;
            gap: 8px;
            justify-content: flex-end;
        }

        .download-control-btn {
            background: #e9ecef;
            border: none;
            padding: 6px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.2s ease;
        }

        .download-control-btn:hover {
            background: #dee2e6;
        }

        .download-control-btn.primary {
            background: #667eea;
            color: white;
        }

        .download-control-btn.primary:hover {
            background: #5a6fd8;
        }

        .download-control-btn.danger {
            background: #ff4d4f;
            color: white;
        }

        .download-control-btn.danger:hover {
            background: #e63946;
        }

        /* 文件操作菜单 */
        .file-actions-menu {
            position: absolute;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.15);
            padding: 8px 0;
            z-index: 5000;
            min-width: 120px;
        }

        .file-action-item {
            padding: 8px 16px;
            cursor: pointer;
            font-size: 14px;
            color: #333;
            background: none;
            border: none;
            width: 100%;
            text-align: left;
            transition: background-color 0.2s ease;
        }

        .file-action-item:hover {
            background-color: #f5f5f5;
        }

        /* 媒体消息操作按钮 */
        .media-actions {
            position: absolute;
            top: 10px;
            right: 10px;
            opacity: 0;
            transition: opacity 0.2s ease;
            display: flex;
            gap: 5px;
        }

        .message-media:hover .media-actions {
            opacity: 1;
        }

        .media-action-btn {
            background: rgba(0, 0, 0, 0.6);
            border: none;
            color: white;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            transition: all 0.2s ease;
        }

        .media-action-btn:hover {
            background: rgba(0, 0, 0, 0.8);
        }
    </style>
</head>
<body>
    <!-- 页面顶部视频缓存进度条 -->
    <div id="top-video-cache-status" style="
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        background: rgba(0, 0, 0, 0.9);
        color: white;
        padding: 15px 20px;
        border-radius: 0 0 10px 10px;
        font-size: 16px;
        z-index: 10000;
        display: none;
        text-align: center;
    ">
        <div style="margin-bottom: 10px;">
            <div class="cache-icon"></div>
            <span>正在缓存视频</span>
        </div>
        <div style="margin-bottom: 8px;">
            <span id="top-cache-file-name"></span>
        </div>
        <div id="top-cache-percentage" style="font-size: 24px; font-weight: bold; margin-bottom: 8px;">0%</div>
        <div style="width: 100%; height: 6px; background: #333; border-radius: 3px; overflow: hidden; margin-bottom: 8px;">
            <div id="top-cache-progress-bar" style="height: 100%; background: linear-gradient(90deg, #667eea 0%, #764ba2 100%); border-radius: 3px; width: 0%; transition: width 0.3s ease;"></div>
        </div>
        <div>
            <span id="top-cache-speed">0 KB/s</span> | <span id="top-cache-size">0 MB</span> / <span id="top-cache-total-size">0 MB</span>
        </div>
    </div>
    
    <!-- 封禁提示弹窗 -->
    <div id="ban-notification-modal" class="modal">
        <div class="modal-content">
            <h2 style="color: #d32f2f; margin-bottom: 20px; font-size: 24px;">账号已被封禁</h2>
            <p style="color: #666; margin-bottom: 15px; font-size: 16px;">您的账号已被封禁，即将退出登录</p>
            <p id="ban-reason" style="color: #333; margin-bottom: 20px; font-weight: 500;"></p>
            <p id="ban-countdown" style="color: #d32f2f; font-size: 36px; font-weight: bold; margin-bottom: 20px;">10</p>
            <p style="color: #999; font-size: 14px;">如有疑问请联系管理员</p>
        </div>
    </div>
    
    <!-- 协议同意提示弹窗 -->
    <div id="terms-agreement-modal" class="modal">
        <div class="modal-content">
            <h2 style="color: #333; margin-bottom: 20px; font-size: 24px; text-align: center;">用户协议</h2>
            <div style="max-height: 400px; overflow-y: auto; margin-bottom: 20px; padding: 20px; background: #f8f9fa; border-radius: 8px;">
                <p style="color: #666; line-height: 1.8; font-size: 16px;">
                    <strong>请严格遵守当地法律法规，若出现违规发言或违规文件一经发现将对您的账号进行封禁（最低1天）无上限。</strong>
                    <br><br>
                    作为Modern Chat的用户，您需要遵守以下规则：
                    <br><br>
                    1. 不得发布违反国家法律法规的内容
                    <br>
                    2. 不得发布暴力、色情、恐怖等不良信息
                    <br>
                    3. 不得发布侵犯他人隐私的内容
                    <br>
                    4. 不得发布虚假信息或谣言
                    <br>
                    5. 不得恶意攻击其他用户
                    <br>
                    6. 不得发布垃圾广告
                    <br>
                    7. 不得发送违规文件
                    <br>
                    8. 我们将会将图片、视频、语音、文件存储到您的本地设备，以便您能够快速访问和使用这些内容。
                    <br><br>
                    违反上述规则的用户，管理员有权对其账号进行封禁处理，封禁时长根据违规情节轻重而定，最低1天，无上限。
                    <br><br>
                    请您自觉遵守以上规则，共同维护良好的聊天环境。
                </p>
            </div>
            <div style="display: flex; gap: 15px; justify-content: center; margin-top: 20px;">
                <button id="agree-terms-btn" style="padding: 12px 40px; background: #4CAF50; color: white; border: none; border-radius: 8px; cursor: pointer; font-size: 16px; font-weight: 600; transition: background-color 0.3s;">
                    同意
                </button>
                <button id="disagree-terms-btn" style="padding: 12px 40px; background: #f44336; color: white; border: none; border-radius: 8px; cursor: pointer; font-size: 16px; font-weight: 600; transition: background-color 0.3s;">
                    不同意并注销账号
                </button>
            </div>
        </div>
    </div>
    
    <!-- 好友申请列表弹窗 -->
    <div id="friend-requests-modal" class="modal">
        <div class="modal-content">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 style="color: #333; font-size: 20px; font-weight: 600;">申请列表</h2>
                <button onclick="closeFriendRequestsModal()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #666;">×</button>
            </div>
            <div id="friend-requests-list">
                <!-- 好友申请列表将通过JavaScript动态加载 -->
                <p style="text-align: center; color: #666; padding: 20px;">加载中...</p>
            </div>
            <div style="margin-top: 20px; text-align: center;">
                <button onclick="closeFriendRequestsModal()" style="padding: 10px 20px; background: #f5f5f5; color: #333; border: 1px solid #ddd; border-radius: 6px; cursor: pointer; font-size: 14px;">关闭</button>
            </div>
        </div>
    </div>
    
    <!-- 群聊邀请通知 -->
    <div id="group-invitation-notifications" style="position: fixed; top: 80px; right: 20px; z-index: 1000;"></div>
    
    <!-- 设置弹窗 -->
    <div id="settings-modal" class="modal" style="display: none;">
        <div class="modal-content" style="width: 90%; max-height: 80vh; overflow-y: auto; background: var(--modal-bg); color: var(--text-color);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 1px solid var(--border-color);">
                <h2 style="color: var(--text-color); font-size: 18px; font-weight: 600;">设置</h2>
                <button onclick="closeSettingsModal()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: var(--text-secondary);">×</button>
            </div>
            <div class="settings-content">
                <!-- 设置项：使用弹窗显示链接 -->
                <div class="setting-item" style="display: flex; justify-content: space-between; align-items: center; padding: 15px 0; border-bottom: 1px solid var(--border-color);">
                    <div>
                        <div style="font-size: 14px; font-weight: 600; color: var(--text-color);">使用弹窗显示链接</div>
                        <div style="font-size: 12px; color: var(--text-secondary); margin-top: 2px;">点击链接时使用弹窗显示</div>
                    </div>
                    <label class="switch">
                        <input type="checkbox" id="setting-link-popup" checked>
                        <span class="slider"></span>
                    </label>
                </div>
                

                
                <!-- 设置项：更多设置 -->
                <div class="setting-item" style="display: flex; justify-content: space-between; align-items: center; padding: 15px 0; border-bottom: 1px solid var(--border-color);">
                    <div>
                        <div style="font-size: 14px; font-weight: 600; color: var(--text-color);">更多设置</div>
                        <div style="font-size: 12px; color: var(--text-secondary); margin-top: 2px;">修改个人信息</div>
                    </div>
                    <button onclick="showMoreSettings()" style="
                        padding: 8px 16px;
                        background: var(--primary-color);
                        color: white;
                        border: none;
                        border-radius: 6px;
                        cursor: pointer;
                        font-size: 13px;
                        transition: background-color 0.2s;
                    ">查看</button>
                </div>
                
                <!-- 设置项：管理缓存 -->
                <div class="setting-item" style="display: flex; justify-content: space-between; align-items: center; padding: 15px 0; border-bottom: 1px solid var(--border-color);">
                    <div>
                        <div style="font-size: 14px; font-weight: 600; color: var(--text-color);">管理已缓存文件</div>
                        <div style="font-size: 12px; color: var(--text-secondary); margin-top: 2px;">查看和管理已缓存的文件</div>
                    </div>
                    <button onclick="showCacheViewer()" style="
                        padding: 8px 16px;
                        background: var(--primary-color);
                        color: white;
                        border: none;
                        border-radius: 6px;
                        cursor: pointer;
                        font-size: 13px;
                        transition: background-color 0.2s;
                    ">查看</button>
                </div>
                
                <!-- 设置项：清除缓存 -->
                <div class="setting-item" style="display: flex; justify-content: space-between; align-items: center; padding: 15px 0; border-bottom: 1px solid var(--border-color);">
                    <div>
                        <div style="font-size: 14px; font-weight: 600; color: var(--text-color);">清除文件缓存</div>
                        <div style="font-size: 12px; color: var(--text-secondary); margin-top: 2px;">清除所有本地存储的文件数据，此操作不可恢复</div>
                    </div>
                    <button onclick="clearFileCache()" style="
                        padding: 8px 16px;
                        background: var(--danger-color);
                        color: white;
                        border: none;
                        border-radius: 6px;
                        cursor: pointer;
                        font-size: 13px;
                        transition: background-color 0.2s;
                    ">清除</button>
                </div>
                
                <!-- 设置项：密保设置 -->
                <div class="setting-item" style="display: flex; justify-content: space-between; align-items: center; padding: 15px 0;">
                    <div>
                        <div style="font-size: 14px; font-weight: 600; color: var(--text-color);">密保设置</div>
                        <div style="font-size: 12px; color: var(--text-secondary); margin-top: 2px;">设置密保问题和答案，用于账号安全</div>
                    </div>
                    <button onclick="showSecurityQuestionModal()" style="
                        padding: 8px 16px;
                        background: var(--primary-color);
                        color: white;
                        border: none;
                        border-radius: 6px;
                        cursor: pointer;
                        font-size: 13px;
                        transition: background-color 0.2s;
                    ">设置</button>
                </div>
                
                <!-- 管理员入口 -->
                <?php if (isset($current_user['role']) && $current_user['role'] === 'admin'): ?>
                <div style="padding: 20px; background: var(--panel-bg); border-radius: 8px; margin-bottom: 20px; transition: background-color 0.3s;">
                    <h3 style="color: var(--text-color); font-size: 16px; font-weight: 600; margin-bottom: 15px;">系统管理</h3>
                    <div style="display: flex; align-items: center; justify-content: space-between;">
                        <div>
                            <div style="font-size: 14px; font-weight: 500; color: var(--text-color);">管理后台</div>
                            <div style="font-size: 12px; color: var(--text-secondary);">进入系统管理控制台</div>
                        </div>
                        <button onclick="window.open('admin.php', '_blank')" style="
                            padding: 8px 16px;
                            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                            color: white;
                            border: none;
                            border-radius: 6px;
                            cursor: pointer;
                            font-size: 13px;
                            font-weight: 500;
                            box-shadow: 0 2px 6px rgba(102, 126, 234, 0.3);
                            transition: all 0.2s;
                        ">进入</button>
                    </div>
                </div>
                <?php endif; ?>

                <!-- 外观设置 -->
                <div class="setting-item" style="display: flex; justify-content: space-between; align-items: center; padding: 15px 0;">
                    <div>
                        <div style="font-size: 14px; font-weight: 600; color: var(--text-color);">深色模式</div>
                        <div style="font-size: 12px; color: var(--text-secondary); margin-top: 2px;">切换界面颜色为深色风格</div>
                    </div>
                    <label class="switch" style="position: relative; display: inline-block; width: 52px; height: 32px;">
                        <input type="checkbox" id="dark-mode-toggle" onchange="toggleTheme(this.checked)" style="opacity: 0; width: 0; height: 0; position: absolute; z-index: -1;">
                        <span class="slider"></span>
                    </label>
                </div>
                
                <!-- 设置项：退出登录 -->
                <div class="setting-item" style="display: flex; justify-content: space-between; align-items: center; padding: 15px 0; border-top: 1px solid var(--border-color); margin-top: 10px;">
                    <div>
                        <div style="font-size: 14px; font-weight: 600; color: var(--text-color);">退出登录</div>
                        <div style="font-size: 12px; color: var(--text-secondary); margin-top: 2px;">退出当前账号，返回登录页面</div>
                    </div>
                    <button onclick="logout()" style="
                        padding: 8px 16px;
                        background: #ff4d4f;
                        color: white;
                        border: none;
                        border-radius: 6px;
                        cursor: pointer;
                        font-size: 13px;
                        transition: background-color 0.2s;
                    ">退出</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- 密保设置弹窗 -->
    <div id="security-question-modal" class="modal" style="display: none;">
        <div class="modal-content" style="width: 400px; background: var(--modal-bg); color: var(--text-color);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 1px solid var(--border-color);">
                <h2 style="color: var(--text-color); font-size: 18px; font-weight: 600;">密保设置</h2>
                <button id="security-question-close" onclick="closeSecurityQuestionModal()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: var(--text-secondary);">×</button>
            </div>
            <form id="security-question-form" method="POST" action="">
                <input type="hidden" name="action" value="set_security_question">
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px; font-weight: 600; color: var(--text-color);">请设置密保问题</label>
                    <input type="text" name="security_question" placeholder="例如：您的出生地是哪里？" required style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 4px; font-size: 14px; background: var(--input-bg); color: var(--text-color);">
                </div>
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px; font-weight: 600; color: var(--text-color);">答案</label>
                    <input type="text" name="security_answer" placeholder="请输入答案" required style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 4px; font-size: 14px; background: var(--input-bg); color: var(--text-color);">
                </div>
                <div style="margin-top: 20px;">
                    <button type="submit" style="width: 100%; padding: 12px; background: var(--primary-color); color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 600; transition: background-color 0.2s;">
                        确定
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- 更多设置弹窗 -->
    <div id="more-settings-modal" class="modal" style="display: none;">
        <div class="modal-content" style="width: 90%; max-width: 500px; background: var(--modal-bg); color: var(--text-color);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 1px solid var(--border-color);">
                <h2 style="color: var(--text-color); font-size: 18px; font-weight: 600;">更多设置</h2>
                <button onclick="closeMoreSettingsModal()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: var(--text-secondary);">×</button>
            </div>
            <div class="more-settings-content">
                <!-- 用户信息部分 -->
                <div style="display: flex; align-items: flex-start; padding: 15px; background: var(--input-bg); border-radius: 8px; margin-bottom: 15px;">
                    <!-- 左侧头像 -->
                    <div style="margin-right: 15px; text-align: center;">
                        <?php if (isset($current_user['avatar']) && $current_user['avatar'] && $current_user['avatar'] !== 'deleted_user'): ?>
                            <img src="<?php echo $current_user['avatar']; ?>" alt="用户头像" style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover;">
                        <?php else: ?>
                            <div style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; font-size: 16px;">
                                <?php echo substr($username, 0, 1); ?>
                            </div>
                        <?php endif; ?>
                        <button onclick="showChangeAvatarModal()" style="
                            margin-top: 8px;
                            padding: 4px 8px;
                            background: var(--primary-color);
                            color: white;
                            border: none;
                            border-radius: 4px;
                            cursor: pointer;
                            font-size: 11px;
                        ">修改头像</button>
                    </div>
                    
                    <!-- 右侧用户信息 -->
                    <div style="flex: 1;">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                            <div style="font-size: 16px; font-weight: 600; color: var(--text-color);"><?php echo htmlspecialchars($username); ?></div>
                            <button onclick="showChangeNameModal()" style="
                                padding: 4px 10px;
                                background: var(--primary-color);
                                color: white;
                                border: none;
                                border-radius: 4px;
                                cursor: pointer;
                                font-size: 12px;
                            ">修改名称</button>
                        </div>
                        <div style="display: flex; align-items: center; justify-content: space-between;">
                            <div style="font-size: 13px; color: var(--text-secondary); word-break: break-all; margin-right: 5px;"><?php echo htmlspecialchars($current_user['email']); ?></div>
                            <button onclick="showChangeEmailModal()" style="
                                padding: 4px 10px;
                                background: var(--primary-color);
                                color: white;
                                border: none;
                                border-radius: 4px;
                                cursor: pointer;
                                font-size: 12px;
                            ">修改邮箱</button>
                        </div>
                    </div>
                </div>
                
                <!-- 密码修改部分 -->
                <div style="padding: 15px; background: var(--input-bg); border-radius: 8px; margin-bottom: 15px;">
                    <div style="display: flex; align-items: center; justify-content: space-between;">
                        <div style="font-size: 14px; color: var(--text-color);">密码安全</div>
                        <button onclick="showChangePasswordModal()" style="
                            padding: 6px 12px;
                            background: var(--danger-color);
                            color: white;
                            border: none;
                            border-radius: 6px;
                            cursor: pointer;
                            font-size: 13px;
                        ">修改密码</button>
                    </div>
                </div>

                <!-- 手机号绑定部分 -->
                <?php if (getConfig('phone_sms', false)): ?>
                <div style="padding: 15px; background: var(--input-bg); border-radius: 8px; margin-bottom: 15px;">
                    <div style="display: flex; align-items: center; justify-content: space-between;">
                        <div>
                            <div style="font-size: 14px; font-weight: 500; color: var(--text-color);">
                                <?php echo !empty($current_user['phone']) ? '修改绑定手机' : '绑定手机号'; ?>
                            </div>
                            <div style="font-size: 12px; color: var(--text-secondary);" id="security-phone-text">
                                <?php echo !empty($current_user['phone']) ? substr($current_user['phone'], 0, 3) . '****' . substr($current_user['phone'], -4) : '未绑定'; ?>
                            </div>
                        </div>
                        <button onclick="showPhoneBindModal()" style="
                            padding: 6px 12px;
                            background: var(--primary-color);
                            color: white;
                            border: none;
                            border-radius: 6px;
                            cursor: pointer;
                            font-size: 13px;
                        ">
                            <?php echo !empty($current_user['phone']) ? '修改' : '绑定'; ?>
                        </button>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
                


                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- 修改头像弹窗 -->
    <div id="change-avatar-modal" class="modal" style="display: none;">
        <div class="modal-content" style="width: 400px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 1px solid #eaeaea;">
                <h2 style="color: #333; font-size: 20px; font-weight: 600;">修改头像</h2>
                <button onclick="closeChangeAvatarModal()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #666;">×</button>
            </div>
            <div class="change-avatar-content" style="padding: 0 20px 20px;">
                <div style="text-align: center; margin-bottom: 20px;">
                    <div style="display: inline-block; margin-bottom: 15px;">
                        <div id="avatar-preview" style="
                            width: 120px;
                            height: 120px;
                            border-radius: 50%;
                            background: #f0f0f0;
                            border: 2px dashed #ccc;
                            display: flex;
                            align-items: center;
                            justify-content: center;
                            overflow: hidden;
                            margin: 0 auto;
                        ">
                            <?php if (isset($current_user['avatar']) && $current_user['avatar'] && $current_user['avatar'] !== 'deleted_user'): ?>
                                <img src="<?php echo $current_user['avatar']; ?>" alt="当前头像" style="width: 100%; height: 100%; object-fit: cover;">
                            <?php else: ?>
                                <span style="color: #666; font-size: 14px;">点击选择头像</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div style="font-size: 12px; color: #999; margin-bottom: 15px;">建议使用32×32像素的图片，支持JPG、PNG格式</div>
                    
                    <input type="file" id="avatar-file" name="avatar" accept="image/*" style="display: none;">
                    <button type="button" onclick="document.getElementById('avatar-file').click()" style="
                        padding: 10px 20px;
                        background: #667eea;
                        color: white;
                        border: none;
                        border-radius: 6px;
                        cursor: pointer;
                        font-size: 14px;
                        transition: background-color 0.2s;
                        margin-bottom: 15px;
                    ">选择图片</button>
                </div>
                <div style="display: flex; justify-content: flex-end; gap: 10px;">
                    <button onclick="closeChangeAvatarModal()" style="
                        padding: 10px 20px;
                        background: #f5f5f5;
                        color: #333;
                        border: 1px solid #ddd;
                        border-radius: 6px;
                        cursor: pointer;
                        font-size: 14px;
                        transition: background-color 0.2s;
                    ">取消</button>
                    <button onclick="changeAvatar()" style="
                        padding: 10px 20px;
                        background: #667eea;
                        color: white;
                        border: none;
                        border-radius: 6px;
                        cursor: pointer;
                        font-size: 14px;
                        transition: background-color 0.2s;
                    ">确定</button>
                </div>
            </div>
        </div>
    </div>

    <!-- 修改密码弹窗 -->
    <div id="change-password-modal" class="modal" style="display: none;">
        <div class="modal-content" style="width: 400px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 1px solid #eaeaea;">
                <h2 style="color: #333; font-size: 20px; font-weight: 600;">修改密码</h2>
                <button onclick="closeChangePasswordModal()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #666;">×</button>
            </div>
            <div class="change-password-content" style="padding: 0 20px 20px;">
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500; color: #333;">请输入原密码</label>
                    <input type="password" id="old-password" style="
                        width: 100%;
                        padding: 10px;
                        border: 1px solid #ddd;
                        border-radius: 6px;
                        font-size: 14px;
                        box-sizing: border-box;
                    " placeholder="请输入原密码">
                </div>
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500; color: #333;">请输入新密码</label>
                    <input type="password" id="new-password" style="
                        width: 100%;
                        padding: 10px;
                        border: 1px solid #ddd;
                        border-radius: 6px;
                        font-size: 14px;
                        box-sizing: border-box;
                    " placeholder="请输入新密码">
                </div>
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500; color: #333;">请二次输入新密码</label>
                    <input type="password" id="confirm-password" style="
                        width: 100%;
                        padding: 10px;
                        border: 1px solid #ddd;
                        border-radius: 6px;
                        font-size: 14px;
                        box-sizing: border-box;
                    " placeholder="请再次输入新密码">
                </div>
                <div style="display: flex; justify-content: flex-end; gap: 10px;">
                    <button onclick="closeChangePasswordModal()" style="
                        padding: 10px 20px;
                        background: #f5f5f5;
                        color: #333;
                        border: 1px solid #ddd;
                        border-radius: 6px;
                        cursor: pointer;
                        font-size: 14px;
                        transition: background-color 0.2s;
                    ">取消</button>
                    <button onclick="changePassword()" style="
                        padding: 10px 20px;
                        background: #667eea;
                        color: white;
                        border: none;
                        border-radius: 6px;
                        cursor: pointer;
                        font-size: 14px;
                        transition: background-color 0.2s;
                    ">确定</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- 修改名称弹窗 -->
    <div id="change-name-modal" class="modal" style="display: none;">
        <div class="modal-content" style="width: 400px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 1px solid #eaeaea;">
                <h2 style="color: #333; font-size: 20px; font-weight: 600;">修改名称</h2>
                <button onclick="closeChangeNameModal()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #666;">×</button>
            </div>
            <div class="change-name-content" style="padding: 0 20px 20px;">
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500; color: #333;">请输入要修改的名称</label>
                    <input type="text" id="new-name" value="<?php echo htmlspecialchars($username); ?>" style="
                        width: 100%;
                        padding: 10px;
                        border: 1px solid #ddd;
                        border-radius: 6px;
                        font-size: 14px;
                        box-sizing: border-box;
                    " placeholder="请输入新名称">
                </div>
                <div style="display: flex; justify-content: flex-end; gap: 10px;">
                    <button onclick="closeChangeNameModal()" style="
                        padding: 10px 20px;
                        background: #f5f5f5;
                        color: #333;
                        border: 1px solid #ddd;
                        border-radius: 6px;
                        cursor: pointer;
                        font-size: 14px;
                        transition: background-color 0.2s;
                    ">取消</button>
                    <button onclick="changeName()" style="
                        padding: 10px 20px;
                        background: #667eea;
                        color: white;
                        border: none;
                        border-radius: 6px;
                        cursor: pointer;
                        font-size: 14px;
                        transition: background-color 0.2s;
                    ">确定</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- 修改邮箱弹窗 -->
    <div id="change-email-modal" class="modal" style="display: none;">
        <div class="modal-content" style="width: 400px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 1px solid #eaeaea;">
                <h2 style="color: #333; font-size: 20px; font-weight: 600;">修改邮箱</h2>
                <button onclick="closeChangeEmailModal()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #666;">×</button>
            </div>
            <div class="change-email-content" style="padding: 0 20px 20px;">
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500; color: #333;">请输入要修改的邮箱</label>
                    <input type="email" id="new-email" value="<?php echo htmlspecialchars($current_user['email']); ?>" style="
                        width: 100%;
                        padding: 10px;
                        border: 1px solid #ddd;
                        border-radius: 6px;
                        font-size: 14px;
                        box-sizing: border-box;
                    " placeholder="请输入新邮箱">
                </div>
                <div style="display: flex; justify-content: flex-end; gap: 10px;">
                    <button onclick="closeChangeEmailModal()" style="
                        padding: 10px 20px;
                        background: #f5f5f5;
                        color: #333;
                        border: 1px solid #ddd;
                        border-radius: 6px;
                        cursor: pointer;
                        font-size: 14px;
                        transition: background-color 0.2s;
                    ">取消</button>
                    <button onclick="changeEmail()" style="
                        padding: 10px 20px;
                        background: #667eea;
                        color: white;
                        border: none;
                        border-radius: 6px;
                        cursor: pointer;
                        font-size: 14px;
                        transition: background-color 0.2s;
                    ">确定</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- 手机号绑定弹窗 -->
    <div id="phone-bind-modal" class="modal" style="display: none; z-index: 10000;">
        <div class="modal-content" style="width: 90%; max-width: 400px; background: var(--modal-bg, #fff); color: var(--text-color, #333);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 1px solid var(--border-color, #eaeaea);">
                <h2 style="color: var(--text-color, #333); font-size: 18px; font-weight: 600;" id="phone-bind-title">绑定手机号</h2>
                <button onclick="closePhoneBindModal()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: var(--text-secondary, #666);">×</button>
            </div>
            <div style="padding: 0 20px 20px;">
                <div class="form-group" style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px; font-size: 14px; color: var(--text-secondary, #666);">手机号</label>
                    <input type="tel" id="bind-phone-input" placeholder="请输入手机号" style="
                        width: 100%;
                        padding: 10px;
                        border: 1px solid var(--border-color, #ddd);
                        border-radius: 4px;
                        background: var(--input-bg, #fff);
                        color: var(--text-color, #333);
                        font-size: 14px;
                        box-sizing: border-box;
                    ">
                </div>
                
                <!-- 极验验证码容器 -->
                <div class="form-group" style="margin-bottom: 15px;">
                    <div id="bind-phone-captcha"></div>
                </div>
                
                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 5px; font-size: 14px; color: var(--text-secondary, #666);">验证码</label>
                    <div style="display: flex; gap: 10px;">
                        <input type="text" id="bind-sms-code" placeholder="6位验证码" maxlength="6" style="
                            flex: 1;
                            padding: 10px;
                            border: 1px solid var(--border-color, #ddd);
                            border-radius: 4px;
                            background: var(--input-bg, #fff);
                            color: var(--text-color, #333);
                            font-size: 14px;
                            box-sizing: border-box;
                        ">
                        <button id="get-bind-code-btn" disabled style="
                            padding: 0 15px;
                            background: #ccc;
                            color: white;
                            border: none;
                            border-radius: 4px;
                            cursor: not-allowed;
                            font-size: 13px;
                            white-space: nowrap;
                        ">获取验证码</button>
                    </div>
                </div>
                
                <button onclick="submitPhoneBind()" style="
                    width: 100%;
                    padding: 12px;
                    background: var(--primary-color, #667eea);
                    color: white;
                    border: none;
                    border-radius: 6px;
                    cursor: pointer;
                    font-size: 14px;
                    font-weight: 500;
                    transition: background-color 0.2s;
                ">确定</button>
            </div>
        </div>
    </div>
    
    <!-- 缓存查看弹窗 -->
    <div id="cache-viewer-modal" class="modal" style="display: none;">
        <div class="modal-content" style="width: 350px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; padding-bottom: 6px; border-bottom: 1px solid #eaeaea;">
                <h2 style="color: #333; font-size: 16px; font-weight: 600;">缓存</h2>
                <button onclick="closeCacheViewer()" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #666;">×</button>
            </div>
            
            <div id="cache-stats" style="margin-bottom: 12px;">
                <!-- 缓存统计信息将通过JavaScript动态加载 -->
                <p style="text-align: center; color: #666; font-size: 12px;">加载缓存信息中...</p>
            </div>
            
            <div style="display: flex; justify-content: space-between; gap: 8px;">
                <button onclick="closeCacheViewer()" style="
                    flex: 1;
                    padding: 8px 12px;
                    background: #f5f5f5;
                    color: #333;
                    border: 1px solid #ddd;
                    border-radius: 4px;
                    cursor: pointer;
                    font-size: 13px;
                ">取消</button>
                <button onclick="showClearCacheConfirm()" style="
                    flex: 1;
                    padding: 8px 12px;
                    background: #ff4d4f;
                    color: white;
                    border: none;
                    border-radius: 4px;
                    cursor: pointer;
                    font-size: 13px;
                ">清空</button>
            </div>
        </div>
    </div>
    
    <!-- 清空缓存确认弹窗 -->
    <div id="clear-cache-confirm-modal" class="modal" style="display: none;">
        <div class="modal-content" style="width: 400px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 1px solid #eaeaea;">
                <h2 style="color: #333; font-size: 20px; font-weight: 600;">清空缓存？</h2>
                <button onclick="closeClearCacheConfirm()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #666;">×</button>
            </div>
            
            <div id="clear-cache-info" style="margin-bottom: 20px;">
                <p>你将要清除缓存的全部文件（包括图片 视频 音频 文件）总大小为：<strong id="clear-cache-size">0 B</strong></p>
                <p>确定要清除吗？</p>
            </div>
            
            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button onclick="closeClearCacheConfirm()" style="
                    padding: 10px 20px;
                    background: #f5f5f5;
                    color: #333;
                    border: 1px solid #ddd;
                    border-radius: 6px;
                    cursor: pointer;
                    font-size: 14px;
                ">取消</button>
                <button onclick="clearCache()" style="
                    padding: 10px 20px;
                    background: #ff4d4f;
                    color: white;
                    border: none;
                    border-radius: 6px;
                    cursor: pointer;
                    font-size: 14px;
                ">确定</button>
            </div>
        </div>
    </div>
    
    <!-- 开关样式 -->
    <style>
        .switch {
            position: relative;
            display: inline-block;
            width: 52px;
            height: 32px;
        }
        
        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #e9e9ea;
            transition: .3s;
            border-radius: 32px;
        }
        
        .slider:before {
            position: absolute;
            content: "";
            height: 28px;
            width: 28px;
            left: 2px;
            bottom: 2px;
            background-color: white;
            transition: .3s cubic-bezier(0.4, 0.0, 0.2, 1);
            border-radius: 50%;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        input:checked + .slider {
            background-color: #0095ff;
        }
        
        input:checked + .slider:before {
            transform: translateX(20px);
        }
    </style>
    
    <!-- 封禁提示弹窗 -->
    <div id="ban-notification-modal" class="modal">
        <div class="modal-content">
            <h2 style="color: #d32f2f; margin-bottom: 20px; font-size: 24px;">账号已被封禁</h2>
            <p style="color: #666; margin-bottom: 15px; font-size: 16px;">您的账号已被封禁，即将退出登录</p>
            <p id="ban-reason" style="color: #333; margin-bottom: 20px; font-weight: 500;"></p>
            <p id="ban-countdown" style="color: #d32f2f; font-size: 36px; font-weight: bold; margin-bottom: 20px;">10</p>
            <p style="color: #999; font-size: 14px;">如有疑问请联系管理员</p>
        </div>
    </div>
    
    <!-- 协议同意提示弹窗 -->
    <div id="terms-agreement-modal" class="modal">
        <div class="modal-content">
            <h2 style="color: #333; margin-bottom: 20px; font-size: 24px; text-align: center;">用户协议</h2>
            <div style="max-height: 400px; overflow-y: auto; margin-bottom: 20px; padding: 20px; background: #f8f9fa; border-radius: 8px;">
                <p style="color: #666; line-height: 1.8; font-size: 16px;">
                    <strong>请严格遵守当地法律法规，若出现违规发言或违规文件一经发现将对您的账号进行封禁（最低1天）无上限。</strong>
                    <br><br>
                    作为Modern Chat的用户，您需要遵守以下规则：
                    <br><br>
                    1. 不得发布违反国家法律法规的内容
                    <br>
                    2. 不得发布暴力、色情、恐怖等不良信息
                    <br>
                    3. 不得发布侵犯他人隐私的内容
                    <br>
                    4. 不得发布虚假信息或谣言
                    <br>
                    5. 不得恶意攻击其他用户
                    <br>
                    6. 不得发布垃圾广告
                    <br>
                    7. 不得发送违规文件
                    <br>
                    8. 我们将会将图片、视频、语音、文件存储到您的本地设备，以便您能够快速访问和使用这些内容。
                    <br><br>
                    违反上述规则的用户，管理员有权对其账号进行封禁处理，封禁时长根据违规情节轻重而定，最低1天，无上限。
                    <br><br>
                    请您自觉遵守以上规则，共同维护良好的聊天环境。
                </p>
            </div>
            <div style="display: flex; gap: 15px; justify-content: center; margin-top: 20px;">
                <button id="agree-terms-btn" style="padding: 12px 40px; background: #4CAF50; color: white; border: none; border-radius: 8px; cursor: pointer; font-size: 16px; font-weight: 600; transition: background-color 0.3s;">
                    同意
                </button>
                <button id="disagree-terms-btn" style="padding: 12px 40px; background: #f44336; color: white; border: none; border-radius: 8px; cursor: pointer; font-size: 16px; font-weight: 600; transition: background-color 0.3s;">
                    不同意并注销账号
                </button>
            </div>
        </div>
    </div>
    
    <!-- 好友申请列表弹窗 -->
    <div id="friend-requests-modal" class="modal">
        <div class="modal-content">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 style="color: #333; font-size: 20px; font-weight: 600;">申请列表</h2>
                <button onclick="closeFriendRequestsModal()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #666;">×</button>
            </div>
            <div id="friend-requests-list">
                <!-- 好友申请列表将通过JavaScript动态加载 -->
                <p style="text-align: center; color: #666; padding: 20px;">加载中...</p>
            </div>
            <div style="margin-top: 20px; text-align: center;">
                <button onclick="closeFriendRequestsModal()" style="padding: 10px 20px; background: #f5f5f5; color: #333; border: 1px solid #ddd; border-radius: 6px; cursor: pointer; font-size: 14px;">关闭</button>
            </div>
        </div>
    </div>
    
    <!-- 群聊封禁提示弹窗 -->
    <div id="group-ban-modal" class="modal" style="display: none;">
        <div class="modal-content" style="background: var(--modal-bg); color: var(--text-color);">
            <h2 style="color: var(--danger-color); margin-bottom: 20px; font-size: 24px;">群聊已被封禁</h2>
            <div id="group-ban-info" style="color: var(--text-secondary); margin-bottom: 25px; font-size: 14px;">
                <!-- 群聊封禁信息将通过JavaScript动态加载 -->
            </div>
            <button onclick="document.getElementById('group-ban-modal').style.display = 'none'" style="
                padding: 12px 30px;
                background: var(--primary-color);
                color: white;
                border: none;
                border-radius: 8px;
                cursor: pointer;
                font-weight: 500;
                font-size: 14px;
                transition: background-color 0.2s;
            ">
                关闭
            </button>
        </div>
    </div>
    
    <!-- 建立群聊弹窗 -->
    <div id="create-group-modal" class="modal" style="display: none;">
        <div class="modal-content">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 style="color: #333; font-size: 20px; font-weight: 600;">建立群聊</h2>
                <button onclick="document.getElementById('create-group-modal').style.display = 'none'" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #666;">×</button>
            </div>
            <div style="margin-bottom: 20px;">
                <label for="group-name" style="display: block; margin-bottom: 5px; color: #333; font-weight: 500;">群聊名称</label>
                <input type="text" id="group-name" placeholder="请输入群聊名称" style="
                    width: 100%;
                    padding: 10px;
                    border: 1px solid #ddd;
                    border-radius: 6px;
                    font-size: 14px;
                    margin-bottom: 20px;
                ">
            </div>
            
            <div style="margin-bottom: 20px;">
                <h3 style="color: #333; font-size: 16px; font-weight: 600; margin-bottom: 10px;">选择好友</h3>
                <div id="select-friends-container" style="
                    max-height: 300px;
                    overflow-y: auto;
                    border: 1px solid #ddd;
                    border-radius: 6px;
                    padding: 10px;
                    background: white;
                ">
                    <!-- 好友选择列表将通过JavaScript动态生成 -->
                </div>
            </div>
            
            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button onclick="document.getElementById('create-group-modal').style.display = 'none'" style="
                    padding: 10px 20px;
                    background: #f5f5f5;
                    color: #333;
                    border: 1px solid #ddd;
                    border-radius: 6px;
                    cursor: pointer;
                    font-size: 14px;
                ">取消</button>
                <button onclick="createGroup()" style="
                    padding: 10px 20px;
                    background: #667eea;
                    color: white;
                    border: none;
                    border-radius: 6px;
                    cursor: pointer;
                    font-size: 14px;
                ">创建</button>
            </div>
        </div>
    </div>
    
    <!-- 视频播放弹窗 -->
    <div class="video-player-modal" id="video-player-modal">
        <div class="video-player-content">
            <div class="video-player-header">
                <h2 class="video-player-title" id="video-player-title">视频播放</h2>
                <button class="video-player-close" onclick="closeVideoPlayer()">×</button>
            </div>
            <div class="video-player-body">
                <div class="custom-video-player">
                    <!-- 动态缓存图标样式 -->
                    <style>
                        @keyframes spin {
                            0% { transform: rotate(0deg); }
                            100% { transform: rotate(360deg); }
                        }
                        
                        .cache-icon {
                            display: inline-block;
                            width: 20px;
                            height: 20px;
                            border: 2px solid rgba(255, 255, 255, 0.3);
                            border-top-color: #fff;
                            border-radius: 50%;
                            animation: spin 1s linear infinite;
                            margin-right: 8px;
                            vertical-align: middle;
                        }
                    </style>
                    
                    <!-- 视频缓存状态显示 -->
                    <div class="video-cache-status" id="video-cache-status" style="
                        position: absolute;
                        top: 10px;
                        right: 10px;
                        background: rgba(0, 0, 0, 0.9);
                        color: white;
                        padding: 15px 20px;
                        border-radius: 10px;
                        font-size: 16px;
                        z-index: 2000;
                        display: none;
                        text-align: center;
                        min-width: 250px;
                        pointer-events: auto;
                    ">
                        <div style="margin-bottom: 10px;">
                            <div class="cache-icon"></div>
                            <span>正在缓存</span>
                        </div>
                        <div style="margin-bottom: 8px;">
                            <span id="cache-file-name"></span>
                        </div>
                        <div id="cache-percentage" style="font-size: 24px; font-weight: bold; margin-bottom: 8px;">0%</div>
                        <div>
                            <span id="cache-speed">0 KB/s</span> | <span id="cache-size">0 MB</span> / <span id="cache-total-size">0 MB</span>
                        </div>
                    </div>
                    
                    <!-- 视频元素，隐藏默认controls -->
                    <video id="custom-video-element" class="custom-video-element" controlsList="nodownload"></video>
                    
                    <!-- 视频控件 -->
                    <div class="video-controls">
                        <div class="video-progress-container">
                            <span class="video-time current-time">0:00</span>
                            <div class="video-progress-bar" id="video-progress-bar">
                                <div class="video-progress" id="video-progress"></div>
                            </div>
                            <span class="video-time total-time">0:00</span>
                        </div>
                        <div class="video-controls-row">
                            <div class="video-main-controls">
                                <button class="video-control-btn" id="video-play-btn" title="播放/暂停">▶</button>

                                <button class="video-control-btn" id="video-fullscreen-btn" title="放大/缩小" onclick="toggleVideoFullscreen()">⛶</button>
                            </div>
                            <div class="video-volume-control">
                                <button class="video-control-btn" id="video-mute-btn" title="静音">🔊</button>
                                <input type="range" class="volume-slider" id="volume-slider" min="0" max="1" step="0.01" value="1">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- 入群申请弹窗 -->
    <div id="join-requests-modal" class="modal" style="display: none;">
        <div class="modal-content" style="width: 500px; max-width: 90%; border-radius: 12px; overflow: hidden; box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);">
            <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; display: flex; justify-content: space-between; align-items: center;">
                <h2 style="margin: 0; font-size: 18px; font-weight: 600;">入群申请</h2>
                <button onclick="closeJoinRequestsModal()" style="background: none; border: none; color: white; font-size: 24px; cursor: pointer; padding: 0; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; transition: all 0.2s ease;">×</button>
            </div>
            <div style="padding: 20px;">
                <div id="join-requests-list" style="max-height: 400px; overflow-y: auto;">
                    <p style="text-align: center; color: #666; margin: 20px 0;">加载中...</p>
                </div>
            </div>
            <div style="padding: 15px 20px; background: #f8f9fa; border-top: 1px solid #e9ecef; display: flex; justify-content: flex-end;">
                <button onclick="closeJoinRequestsModal()" style="padding: 10px 20px; background: #f5f5f5; color: #333; border: 1px solid #ddd; border-radius: 6px; cursor: pointer; font-size: 14px; margin-right: 10px; transition: all 0.2s ease;">关闭</button>
            </div>
        </div>
    </div>
    
    <!-- 群聊成员弹窗 -->
    <div id="group-members-modal" class="modal" style="display: none;">
        <div class="modal-content" style="background: var(--modal-bg); color: var(--text-color);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 style="color: var(--text-color); font-size: 20px; font-weight: 600;">群聊成员</h2>
                <button onclick="closeGroupMembersModal()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: var(--text-secondary);">×</button>
            </div>
            <div id="group-members-list" style="max-height: 400px; overflow-y: auto; padding: 10px;">
                <!-- 群聊成员列表将通过JavaScript动态加载 -->
                <p style="text-align: center; color: var(--text-secondary);">加载中...</p>
            </div>
        </div>
    </div>

        </div>
    </div>
    
    <!-- 反馈弹窗 -->
    <div id="feedback-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); z-index: 2000; justify-content: center; align-items: center;">
        <div style="background: white; border-radius: 12px; width: 90%; max-width: 500px; overflow: hidden; display: flex; flex-direction: column;">
            <!-- 弹窗头部 -->
            <div style="padding: 20px; background: #12b7f5; color: white; display: flex; justify-content: space-between; align-items: center;">
                <h3 style="margin: 0; font-size: 18px;">反馈问题</h3>
                <button onclick="closeFeedbackModal()" style="background: none; border: none; color: white; font-size: 24px; cursor: pointer; padding: 0; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center;">×</button>
            </div>
            
            <!-- 弹窗内容 -->
            <div style="padding: 20px; overflow-y: auto; flex: 1;">
                <form id="feedback-form" enctype="multipart/form-data">
                    <div style="margin-bottom: 20px;">
                        <label for="feedback-content" style="display: block; margin-bottom: 8px; font-weight: 600; color: #333;">问题描述</label>
                        <textarea id="feedback-content" name="content" placeholder="请详细描述您遇到的问题" rows="5" style="width: 100%; padding: 12px; border: 1px solid #eaeaea; border-radius: 8px; font-size: 14px; resize: vertical; outline: none;" required></textarea>
                    </div>
                    <div style="margin-bottom: 20px;">
                        <label for="feedback-image" style="display: block; margin-bottom: 8px; font-weight: 600; color: #333;">添加图片（可选）</label>
                        <input type="file" id="feedback-image" name="image" accept="image/*" style="width: 100%; padding: 10px; border: 1px solid #eaeaea; border-radius: 8px; font-size: 14px;">
                        <p style="font-size: 12px; color: #666; margin-top: 5px;">支持JPG、PNG、GIF格式，最大5MB</p>
                    </div>
                    <div style="display: flex; gap: 10px; justify-content: flex-end;">
                        <button type="button" onclick="closeFeedbackModal()" style="padding: 10px 20px; background: #f5f5f5; border: none; border-radius: 6px; cursor: pointer; font-size: 14px;">取消</button>
                        <button type="submit" style="padding: 10px 20px; background: #12b7f5; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px;">提交反馈</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- 添加好友窗口 -->
    <div id="add-friend-modal" class="modal" style="display: none;">
        <div class="modal-content" style="width: 500px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 1px solid #eaeaea;">
                <h2 style="color: #333; font-size: 20px; font-weight: 600;">添加</h2>
                <button onclick="closeAddFriendWindow()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #666;">×</button>
            </div>
            
            <!-- 选项卡 -->
            <div style="display: flex; margin-bottom: 20px; border-bottom: 1px solid #eaeaea;">
                <button id="search-tab" class="add-friend-tab active" onclick="switchAddFriendTab('search')" style="flex: 1; padding: 12px; border: none; background: transparent; cursor: pointer; font-size: 14px; font-weight: 600; color: #12b7f5; border-bottom: 2px solid #12b7f5;">搜索用户</button>
                <button id="requests-tab" class="add-friend-tab" onclick="switchAddFriendTab('requests')" style="flex: 1; padding: 12px; border: none; background: transparent; cursor: pointer; font-size: 14px; font-weight: 600; color: #666;">申请列表 <?php if ($pending_requests_count > 0): ?><span id="friend-request-count" style="background: #ff4757; color: white; border-radius: 10px; padding: 2px 8px; font-size: 12px; margin-left: 5px;"><?php echo $pending_requests_count; ?></span><?php endif; ?></button>
            </div>
            
            <!-- 搜索用户内容 -->
            <div id="search-content" class="add-friend-content" style="display: block;">
                <div style="margin-bottom: 15px;">
                    <input type="text" id="search-user-input" placeholder="输入用户名或邮箱搜索" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px;">
                </div>
                <div style="margin-bottom: 15px;">
                    <button id="search-user-button" onclick="searchUser()" style="width: 100%; padding: 10px; background: #12b7f5; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 500; transition: background-color 0.2s;">搜索</button>
                </div>
                <div id="search-results" style="max-height: 300px; overflow-y: auto;">
                    <p style="text-align: center; color: #666; padding: 20px;">请输入用户名或邮箱进行搜索</p>
                </div>
            </div>
            
            <!-- 申请列表内容 -->
            <div id="requests-content" class="add-friend-content" style="display: none;">
                <div id="friend-requests-list" style="max-height: 350px; overflow-y: auto;">
                    <!-- 申请列表将通过JavaScript动态加载 -->
                    <p style="text-align: center; color: #666; padding: 20px;">加载中...</p>
                </div>
            </div>
            

        </div>
    </div>
    
    <!-- 主聊天容器 -->
    <div class="chat-container" style="<?php echo ($selected_id ? 'flex-direction: column;' : ''); ?>">
        <!-- 左侧边栏 -->
        <div class="sidebar" style="<?php echo ($selected_id ? 'display: none;' : ''); ?>">
            <!-- 顶部用户信息 -->
            <div class="sidebar-header">
                <button class="menu-toggle-btn" onclick="toggleMenu()">
                    ☰
                </button>
                <script>
                    // 切换菜单
                    function toggleMenu() {
                        const menuPanel = document.getElementById('menu-panel');
                        const overlay = document.getElementById('overlay');
                        if (menuPanel && overlay) {
                            menuPanel.classList.toggle('open');
                            overlay.classList.toggle('open');
                        }
                    }
                    // 邀请好友加入群聊
        function inviteFriendsToGroup(groupId) {
            // 创建并显示邀请好友弹窗
            const modal = document.createElement('div');
            modal.id = 'invite-friends-modal';
            modal.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.5);
                display: flex;
                justify-content: center;
                align-items: center;
                z-index: 1000;
            `;

            const modalContent = document.createElement('div');
            modalContent.style.cssText = `
                background: var(--modal-bg);
                border-radius: 12px;
                width: 90%;
                max-width: 500px;
                max-height: 80vh;
                overflow: hidden;
                color: var(--text-color);
            `;

            // 弹窗标题
            const modalHeader = document.createElement('div');
            modalHeader.style.cssText = `
                padding: 20px;
                border-bottom: 1px solid var(--border-color);
                display: flex;
                justify-content: space-between;
                align-items: center;
            `;
            modalHeader.innerHTML = `
                <h3 style="margin: 0; font-size: 18px; font-weight: 600;">邀请好友加入群聊</h3>
                <button onclick="document.getElementById('invite-friends-modal').remove()" style="
                    background: none;
                    border: none;
                    font-size: 24px;
                    cursor: pointer;
                    color: var(--text-color);
                    padding: 0;
                    width: 30px;
                    height: 30px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                ">×</button>
            `;
            modalContent.appendChild(modalHeader);

            // 弹窗内容
            const modalBody = document.createElement('div');
            modalBody.style.cssText = `
                padding: 20px;
                overflow-y: auto;
                max-height: calc(80vh - 120px);
            `;
            modalBody.innerHTML = '<div style="text-align: center; padding: 20px; color: var(--text-desc);">加载好友列表中...</div>';
            modalContent.appendChild(modalBody);

            modal.appendChild(modalContent);
            document.body.appendChild(modal);

            // 加载好友列表
            fetch(`get_friends_for_group_invite.php?group_id=${groupId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        let friendsHTML = '';
                        if (data.friends.length > 0) {
                            data.friends.forEach(friend => {
                                friendsHTML += `
                                    <div style="
                                        display: flex;
                                        justify-content: space-between;
                                        align-items: center;
                                        padding: 12px;
                                        border-bottom: 1px solid var(--border-color);
                                    ">
                                        <div style="display: flex; align-items: center; gap: 12px;">
                                            <div style="
                                                width: 40px;
                                                height: 40px;
                                                border-radius: 50%;
                                                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                                                display: flex;
                                                align-items: center;
                                                justify-content: center;
                                                color: white;
                                                font-weight: 600;
                                                font-size: 16px;
                                                position: relative;
                                            ">
                                                ${friend.username.substring(0, 2)}
                                                <div style="
                                                    position: absolute;
                                                    bottom: 2px;
                                                    right: 2px;
                                                    width: 12px;
                                                    height: 12px;
                                                    border-radius: 50%;
                                                    border: 2px solid white;
                                                    background: ${friend.status === 'online' ? '#4caf50' : '#ffa502'};
                                                "></div>
                                            </div>
                                            <div>
                                                <h4 style="margin: 0 0 4px 0; font-size: 14px; font-weight: 600; color: var(--text-color);">${friend.username}</h4>
                                                <p style="margin: 0; font-size: 12px; color: var(--text-desc);">${friend.status === 'online' ? '在线' : '离线'}</p>
                                            </div>
                                        </div>
                                        <div>
                                            ${friend.in_group ? 
                                                '<span style="color: var(--text-desc); font-size: 14px; padding: 6px 12px; background: var(--hover-bg); border-radius: 16px;">用户已存在</span>' : 
                                                `<button onclick="sendGroupInvitation(${groupId}, ${friend.id})" style="
                                                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                                                    color: white;
                                                    border: none;
                                                    border-radius: 16px;
                                                    padding: 6px 16px;
                                                    font-size: 14px;
                                                    font-weight: 600;
                                                    cursor: pointer;
                                                    transition: all 0.2s;
                                                ">邀请</button>`
                                            }
                                        </div>
                                    </div>
                                `;
                            });
                        } else {
                            friendsHTML = '<div style="text-align: center; padding: 20px; color: var(--text-desc);">没有可用的好友可以邀请</div>';
                        }
                        modalBody.innerHTML = friendsHTML;
                    } else {
                        modalBody.innerHTML = `<div style="text-align: center; padding: 20px; color: #ff4757;">${data.message}</div>`;
                    }
                })
                .catch(error => {
                    modalBody.innerHTML = '<div style="text-align: center; padding: 20px; color: #ff4757;">加载好友列表失败</div>';
                    console.error('加载好友列表失败:', error);
                });
        }

        // 发送群聊邀请
        function sendGroupInvitation(groupId, friendId) {
            fetch('send_group_invitation.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `group_id=${groupId}&friend_id=${friendId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('邀请已发送');
                    // 重新加载邀请好友弹窗
                    document.getElementById('invite-friends-modal').remove();
                    inviteFriendsToGroup(groupId);
                } else {
                    alert(data.message);
                }
            })
            .catch(error => {
                console.error('发送邀请失败:', error);
                alert('发送邀请失败，请稍后重试');
            });
        }
    </script>
                <div class="user-avatar">
                    <?php if (!empty($current_user['avatar'])): ?>
                        <img src="<?php echo $current_user['avatar']; ?>" alt="<?php echo $username; ?>" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                    <?php else: ?>
                        <?php echo substr($username, 0, 2); ?>
                    <?php endif; ?>
                </div>
                <div class="user-info">
                    <div class="user-name"><?php echo htmlspecialchars($username); ?></div>
                    <div class="user-ip">IP: <?php echo $user_ip; ?></div>
                    <div class="user-ip">当前在线人数：<?php echo $user->getOnlineUserCount(); ?></div>
                </div>
            </div>
            
            <!-- 搜索栏 -->
            <div class="search-bar">
                <input type="text" placeholder="搜索好友或群聊..." id="search-input" class="search-input">
            </div>
            
            <!-- 搜索结果区域 -->
            <div id="main-search-results" style="display: none; padding: 15px; background: white; border-bottom: 1px solid #eaeaea; max-height: 300px; overflow-y: auto; position: absolute; width: 100%; z-index: 1000;">
                <p style="color: #666; font-size: 14px; margin-bottom: 10px;">输入用户名或群聊名称进行搜索</p>
            </div>
            

            
            <!-- 合并的聊天列表 -->
            <div class="chat-list" id="combined-chat-list">
                <!-- 好友列表 -->
                <?php foreach ($friends as $friend_item): ?>
                    <?php 
                        $friend_id = $friend_item['friend_id'] ?? $friend_item['id'] ?? 0;
                        $friend_unread_key = 'friend_' . $friend_id;
                        $friend_unread_count = isset($unread_counts[$friend_unread_key]) ? $unread_counts[$friend_unread_key] : 0;
                        $is_active = $chat_type === 'friend' && $selected_id == $friend_id;
                    ?>
                    <div class="chat-item <?php echo $is_active ? 'active' : ''; ?>" data-friend-id="<?php echo $friend_id; ?>" data-chat-type="friend">
                        <div class="chat-avatar" style="position: relative;">
                            <?php 
                                $is_default_avatar = !empty($friend_item['avatar']) && (strpos($friend_item['avatar'], 'default_avatar.png') !== false || $friend_item['avatar'] === 'default_avatar.png');
                            ?>
                            <?php if (!empty($friend_item['avatar']) && !$is_default_avatar && $friend_item['avatar'] !== 'deleted_user'): ?>
                                <img src="<?php echo $friend_item['avatar']; ?>" alt="<?php echo $friend_item['username']; ?>" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;" onerror="this.onerror=null; this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($friend_item['username']); ?>&background=random';">
                            <?php else: ?>
                                <?php echo substr($friend_item['username'], 0, 2); ?>
                            <?php endif; ?>
                            <div class="status-indicator <?php echo $friend_item['status']; ?>"></div>
                        </div>
                        <div class="chat-info">
                            <div class="chat-name"><?php echo htmlspecialchars($friend_item['username']); ?></div>
                            <div class="chat-last-message"><?php echo $friend_item['status'] == 'online' ? '在线' : '离线'; ?></div>
                        </div>
                        <?php if ($friend_unread_count > 0): ?>
                            <div class="unread-count"><?php echo $friend_unread_count > 99 ? '99+' : $friend_unread_count; ?></div>
                        <?php endif; ?>
                        <!-- 三个点菜单 -->
                        <div class="chat-item-menu">
                            <button class="chat-item-menu-btn" onclick="toggleFriendMenu(event, <?php echo $friend_id; ?>, '<?php echo htmlspecialchars($friend_item['username'], ENT_QUOTES); ?>')">
                                ⋮
                            </button>
                            <!-- 好友菜单 -->
                            <div class="friend-menu" id="friend-menu-<?php echo $friend_id; ?>" style="display: none; position: absolute; top: 100%; right: 0; background: var(--modal-bg); border-radius: 8px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.3); z-index: 99999; min-width: 120px; margin-top: 5px; border: 1px solid var(--border-color);">
                                <button class="friend-menu-item" onclick="deleteFriend(<?php echo $friend_id; ?>, '<?php echo htmlspecialchars($friend_item['username'], ENT_QUOTES); ?>')" style="color: #ff4757;">删除好友</button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <!-- 群聊列表 -->
                <?php foreach ($groups as $group_item): ?>
                    <?php 
                        $group_unread_key = 'group_' . $group_item['id'];
                        $group_unread_count = isset($unread_counts[$group_unread_key]) ? $unread_counts[$group_unread_key] : 0;
                        $is_active = $chat_type === 'group' && $selected_id == $group_item['id'];
                        
                        // 检查是否有@提及
                        $has_mention = false;
                        try {
                            $stmt = $conn->prepare("SELECT has_mention FROM chat_settings WHERE user_id = ? AND chat_type = 'group' AND chat_id = ? AND has_mention = TRUE");
                            $stmt->execute([$user_id, $group_item['id']]);
                            $has_mention = $stmt->fetch() !== false;
                        } catch (PDOException $e) {
                            // 表不存在或查询失败，忽略
                        }
                    ?>
                    <div class="chat-item <?php echo $is_active ? 'active' : ''; ?>" data-group-id="<?php echo $group_item['id']; ?>" data-chat-type="group">
                        <div class="chat-avatar group">
                            👥
                        </div>
                        <div class="chat-info">
                            <div class="chat-name">
                                <?php echo htmlspecialchars($group_item['name']); ?>
                                <?php if ($has_mention): ?>
                                    <span class="mention-badge">[有人@你]</span>
                                <?php endif; ?>
                            </div>
                            <div class="chat-last-message">
                                <?php if ($group_item['all_user_group'] == 1): ?>
                                    世界大厅
                                <?php else: ?>
                                    <?php echo ($group->getGroupMembers($group_item['id']) ? count($group->getGroupMembers($group_item['id'])) : 0) . ' 成员'; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if ($group_unread_count > 0): ?>
                            <div class="unread-count"><?php echo $group_unread_count > 99 ? '99+' : $group_unread_count; ?></div>
                        <?php endif; ?>
                        <!-- 三个点菜单 -->
                        <div class="chat-item-menu">
                            <button class="chat-item-menu-btn" onclick="toggleGroupMenu(event, <?php echo $group_item['id']; ?>, '<?php echo htmlspecialchars($group_item['name'], ENT_QUOTES); ?>')">
                                ⋮
                            </button>
                            <!-- 群聊菜单 -->
                            <div class="friend-menu" id="group-menu-<?php echo $group_item['id']; ?>" style="display: none; position: absolute; top: 100%; right: 0; background: var(--modal-bg); border-radius: 8px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.3); z-index: 99999; min-width: 150px; margin-top: 5px; border: 1px solid var(--border-color);">
                                <button class="friend-menu-item" onclick="showGroupMembers(<?php echo $group_item['id']; ?>)" style="color: var(--text-color);">查看成员</button>
                                <button class="friend-menu-item" onclick="inviteFriendsToGroup(<?php echo $group_item['id']; ?>)" style="color: var(--text-color);">邀请好友</button>
                                <?php 
                                    // 检查用户是否是群主或管理员
                                    $is_admin = false;
                                    if ($group_item['owner_id'] == $user_id) {
                                        $is_admin = true;
                                    } else {
                                        // 检查是否是管理员
                                        $group_members = $group->getGroupMembers($group_item['id']);
                                        foreach ($group_members as $member) {
                                            if ($member['user_id'] == $user_id && $member['is_admin']) {
                                                $is_admin = true;
                                                break;
                                            }
                                        }
                                    }
                                ?>
                                <?php if ($is_admin && $group_item['all_user_group'] != 1): ?>
                                    <button class="friend-menu-item" onclick="showJoinRequests(<?php echo $group_item['id']; ?>)">入群申请</button>
                                <?php endif; ?>
                                <?php if ($group_item['owner_id'] == $user_id): ?>
                                    <?php if ($group_item['all_user_group'] != 1): ?>
                                        <button class="friend-menu-item" onclick="transferGroupOwnership(<?php echo $group_item['id']; ?>)">转让群主</button>
                                    <?php endif; ?>
                                    <button class="friend-menu-item" onclick="deleteGroup(<?php echo $group_item['id']; ?>)">解散群聊</button>
                                <?php else: ?>
                                    <?php if ($group_item['all_user_group'] != 1): ?>
                                        <button class="friend-menu-item" onclick="leaveGroup(<?php echo $group_item['id']; ?>)">退出群聊</button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            

        </div>
        
        <!-- 聊天区域 -->
        <div class="chat-area" style="<?php echo (!$selected_id ? 'display: none;' : ''); ?>">
                <!-- 聊天区域顶部 -->
                <div class="chat-header">
                    <button class="btn-icon" onclick="showChatList()" title="返回主页面" style="margin-right: 10px; color: #666; background: transparent; border: none; font-size: 20px; cursor: pointer; display: flex; align-items: center; justify-content: center;">←</button>
                    <?php if ($chat_type === 'friend' && $selected_friend): ?>
                        <div class="chat-avatar" style="position: relative; margin-right: 12px;">
                            <?php if (!empty($selected_friend['avatar'])): ?>
                                <img src="<?php echo $selected_friend['avatar']; ?>" alt="<?php echo $selected_friend['username']; ?>" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                            <?php else: ?>
                                <?php echo substr($selected_friend['username'], 0, 2); ?>
                            <?php endif; ?>
                            <div class="status-indicator <?php echo $selected_friend['status']; ?>"></div>
                        </div>
                        <div class="chat-header-info">
                            <div class="chat-header-name"><?php echo $selected_friend['username']; ?></div>
                            <div class="chat-header-status"><?php echo $selected_friend['status'] == 'online' ? '在线' : '离线'; ?></div>
                        </div>
                    <?php elseif ($chat_type === 'group' && $selected_group): ?>
                        <div class="chat-avatar group" style="margin-right: 12px;">
                            👥
                        </div>
                        <div class="chat-header-info">
                            <div class="chat-header-name"><?php echo $selected_group['name']; ?></div>
                            <div class="chat-header-status">
                                <?php 
                                    if ($selected_group['all_user_group'] == 1) {
                                        $stmt = $conn->prepare("SELECT COUNT(*) as total_users FROM users");
                                        $stmt->execute();
                                        $total_users = $stmt->fetch()['total_users'];
                                        echo $total_users . ' 成员';
                                    } else {
                                        echo ($group->getGroupMembers($selected_group['id']) ? count($group->getGroupMembers($selected_group['id'])) : 0) . ' 成员';
                                    }
                                ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="chat-avatar" style="margin-right: 12px;">
                            👤
                        </div>
                        <div class="chat-header-info">
                            <div class="chat-header-name">选择聊天对象</div>
                            <div class="chat-header-status">请从左侧选择好友或群聊</div>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- 消息容器 -->
                <div class="messages-container" id="messages-container">
                    <!-- 初始聊天记录 -->
                    <?php foreach ($chat_history as $msg): ?>
                        <?php $is_sent = $msg['sender_id'] == $user_id; ?>
                        <!-- 计算消息发送时间和当前时间的差值，用于撤回功能 -->
                        <?php 
                            $msg_time = strtotime($msg['created_at']);
                            $now = time();
                            $time_diff_minutes = ($now - $msg_time) / 60;
                            // 使用 <= 2，与 JavaScript 保持一致，允许刚好 2 分钟时撤回
                            $is_within_2_minutes = $time_diff_minutes <= 2;
                        ?>
                        <div class="message <?php echo $is_sent ? 'sent' : 'received'; ?>" 
                            data-message-id="<?php echo $msg['id']; ?>" 
                            data-chat-type="<?php echo $chat_type; ?>" 
                            data-chat-id="<?php echo $selected_id; ?>" 
                            data-message-time="<?php echo $msg_time * 1000; ?>">
                            <?php if ($is_sent): ?>
                                <!-- 发送者的消息，内容在左，头像在右 -->
                                <div class="message-content">
                                    <?php 
                                        $file_path = isset($msg['file_path']) ? $msg['file_path'] : '';
                                        $file_name = isset($msg['file_name']) ? $msg['file_name'] : '';
                                        $file_size = isset($msg['file_size']) ? $msg['file_size'] : 0;
                                        $file_type = isset($msg['type']) ? $msg['type'] : '';
                                        
                                        // 检测文件的实际类型
                                        $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                                        $image_exts = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
                                        $audio_exts = ['mp3', 'wav', 'ogg', 'aac', 'wma', 'm4a', 'webm'];
                                        $video_exts = ['mp4', 'avi', 'mov', 'wmv', 'flv'];
                                        
                                        if (in_array($ext, $image_exts)) {
                                            // 图片类型
                                            echo "<div class='message-media'>";
                                            echo "<img src='".htmlspecialchars($file_path)."' alt='".htmlspecialchars($file_name)."' class='message-image' data-file-name='".htmlspecialchars($file_name)."' data-file-type='image' data-file-path='".htmlspecialchars($file_path)."'>";
                                            echo "</div>";
                                        } elseif (in_array($ext, $audio_exts)) {
                                            // 音频类型
                                            echo "<div class='message-media'>";
                                            echo "<div class='custom-audio-player'>";
                                            echo "<audio src='".htmlspecialchars($file_path)."' class='audio-element' data-file-name='".htmlspecialchars($file_name)."' data-file-type='audio' data-file-path='".htmlspecialchars($file_path)."'></audio>";
                                            echo "<button class='audio-play-btn' title='播放'></button>";
                                            echo "<div class='audio-progress-container'>";
                                            echo "<div class='audio-progress-bar'>";
                                            echo "<div class='audio-progress'></div>";
                                            echo "</div>";
                                            echo "</div>";
                                            echo "<span class='audio-time current-time'>0:00</span>";
                                            echo "<span class='audio-duration'>0:00</span>";
                                            echo "</div>";
                                            echo "</div>";
                                        } elseif (in_array($ext, $video_exts)) {
                                            // 视频类型
                                            echo "<div class='message-media'>";
                                            echo "<div class='video-container' style='position: relative;'>";
                                            echo "<div style='background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); width: 100%; height: 150px; border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center; color: white; font-size: 24px;' data-file-name='".htmlspecialchars($file_name)."' data-file-path='".htmlspecialchars($file_path)."' class='video-thumbnail' onclick='playCompressedVideo(\'".htmlspecialchars($file_path)."\', \'".htmlspecialchars($file_name)."\')'>🎥</div>";
                                            echo "<button class='download-original-btn' style='position: absolute; bottom: 10px; right: 10px; background: rgba(0, 0, 0, 0.7); color: white; border: none; padding: 8px 12px; border-radius: 5px; cursor: pointer; font-size: 14px;' onclick='event.stopPropagation(); downloadOriginalVideo(\'".htmlspecialchars($file_path)."\', \'".htmlspecialchars($file_name)."\')'>原画质下载</button>";
                                            echo "</div>";
                                            echo "</div>";
                                        } elseif (isset($msg['type']) && $msg['type'] == 'file') {
                                            // 其他文件类型
                                        ?>
                                            <div class="message-file" onclick="addDownloadTask('<?php echo htmlspecialchars($file_name, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($file_path, ENT_QUOTES); ?>', <?php echo $file_size; ?>, 'file')" style="position: relative; background: #f0f0f0; border-radius: 8px; padding: 12px; display: flex; align-items: center; gap: 12px; cursor: pointer;">
                                                <div class="message-file-link" data-file-name="<?php echo htmlspecialchars($file_name); ?>" data-file-size="<?php echo $file_size; ?>" data-file-type="file" data-file-path="<?php echo htmlspecialchars($file_path); ?>" style="display: flex; align-items: center; gap: 12px; text-decoration: none; color: inherit; flex: 1;">
                                                    <span class="file-icon" style="font-size: 24px;">📁</span>
                                                    <div class="file-info" style="flex: 1;">
                                                        <h4 style="margin: 0; font-size: 14px; font-weight: 500;"><?php echo htmlspecialchars($file_name); ?></h4>
                                                        <p style="margin: 2px 0 0 0; font-size: 12px; color: #666;"><?php echo round($file_size / 1024, 2); ?> KB</p>
                                                    </div>
                                                </div>
                                                <button onclick="event.stopPropagation(); addDownloadTask('<?php echo htmlspecialchars($file_name, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($file_path, ENT_QUOTES); ?>', <?php echo $file_size; ?>, 'file')" style="background: #667eea; color: white; border: none; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 12px; transition: all 0.2s ease;">下载</button>
                                            </div>
                                        <?php 
                                        } else {
                                            // 文本消息，检测并转换链接
                                            $content = $msg['content'];
                                            // 严格的HTML净化：移除所有HTML标签，只保留纯文本
                                            $content = strip_tags($content);
                                            // 再次进行HTML转义，确保绝对安全
                                            $content = htmlspecialchars($content);
                                            // 仅允许链接转换，不允许其他HTML
                                            $pattern = '/(https?:\/\/[^\s]+)/';
                                            $replacement = '<a href="#" onclick="event.preventDefault(); handleLinkClick(\'$1\')" style="color: #12b7f5; text-decoration: underline;">$1</a>';
                                            $content_with_links = preg_replace($pattern, $replacement, $content);
                                            echo "<div class='message-text'>{$content_with_links}</div>";
                                        }
                                    ?>
                                    <div class="message-time"><?php echo date('Y年m月d日 H:i', strtotime($msg['created_at'])); ?></div>
                                    <?php if ($is_within_2_minutes): ?>
                                        <div class='message-actions' style='position: relative;'>
                                            <button class='message-action-btn' onclick='toggleMessageActions(this)' style='width: 28px; height: 28px; font-size: 18px; background: rgba(0,0,0,0.1); border: none; border-radius: 50%; color: #333; cursor: pointer; display: flex; align-items: center; justify-content: center; opacity: 1; transition: all 0.2s ease;'>...</button>
                                            <div class='message-action-menu' style='display: none; position: absolute; top: 100%; right: 0; background: white; border-radius: 8px; box-shadow: 0 2px 12px rgba(0,0,0,0.15); padding: 8px 0; z-index: 5000; min-width: 100px;'>
                                                <button class='message-action-item' onclick='recallMessage(this, "<?php echo $msg['id']; ?>", "<?php echo $chat_type; ?>", "<?php echo $selected_id; ?>")' style='width: 100%; text-align: left; padding: 8px 16px; border: none; background: transparent; cursor: pointer; transition: all 0.2s ease; color: #333;'>撤回消息</button>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="message-avatar">
                                    <?php if (!empty($current_user['avatar'])): ?>
                                        <img src="<?php echo $current_user['avatar']; ?>" alt="<?php echo $username; ?>" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                                    <?php else: ?>
                                        <?php echo substr($username, 0, 2); ?>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <!-- 接收者的消息，头像在左，内容在右 -->
                                <div class="message-avatar">
                                    <?php if (isset($msg['avatar']) && !empty($msg['avatar'])): ?>
                                        <img src="<?php echo $msg['avatar']; ?>" alt="<?php echo $msg['sender_username']; ?>" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                                    <?php else: ?>
                                        <?php echo substr($msg['sender_username'], 0, 2); ?>
                                    <?php endif; ?>
                                </div>
                                <div class="message-content">
                                    <?php 
                                        $file_path = isset($msg['file_path']) ? $msg['file_path'] : '';
                                        $file_name = isset($msg['file_name']) ? $msg['file_name'] : '';
                                        $file_size = isset($msg['file_size']) ? $msg['file_size'] : 0;
                                        $file_type = isset($msg['type']) ? $msg['type'] : '';
                                        
                                        // 检测文件的实际类型
                                        $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                                        $image_exts = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
                                        $audio_exts = ['mp3', 'wav', 'ogg', 'aac', 'wma', 'm4a', 'webm'];
                                        $video_exts = ['mp4', 'avi', 'mov', 'wmv', 'flv'];
                                        
                                        if (in_array($ext, $image_exts)) {
                                            // 图片类型
                                            echo "<div class='message-media'>";
                                            echo "<img src='".htmlspecialchars($file_path)."' alt='".htmlspecialchars($file_name)."' class='message-image'>";
                                            echo "</div>";
                                        } elseif (in_array($ext, $audio_exts)) {
                                            // 音频类型
                                            echo "<div class='message-media'>";
                                            echo "<div class='custom-audio-player'>";
                                            echo "<audio src='{$file_path}' class='audio-element' data-file-name='{$file_name}' data-file-type='audio' data-file-path='{$file_path}'></audio>";
                                            echo "<button class='audio-play-btn' title='播放'></button>";
                                            echo "<div class='audio-progress-container'>";
                                            echo "<div class='audio-progress-bar'>";
                                            echo "<div class='audio-progress'></div>";
                                            echo "</div>";
                                            echo "</div>";
                                            echo "<span class='audio-time current-time'>0:00</span>";
                                            echo "<span class='audio-duration'>0:00</span>";
                                            echo "</div>";
                                            echo "</div>";
                                        } elseif (in_array($ext, $video_exts)) {
                                            // 视频类型
                                            echo "<div class='message-media'>";
                                            echo "<div class='video-container' style='position: relative;'>";
                                            echo "<div style='background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); width: 100%; height: 150px; border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center; color: white; font-size: 24px;' data-file-name='{$file_name}' data-file-path='{$file_path}' class='video-thumbnail' onclick='playCompressedVideo(\'{$file_path}\', \'{$file_name}\')'>🎥</div>";
                                            echo "<button class='download-original-btn' style='position: absolute; bottom: 10px; right: 10px; background: rgba(0, 0, 0, 0.7); color: white; border: none; padding: 8px 12px; border-radius: 5px; cursor: pointer; font-size: 14px;' onclick='event.stopPropagation(); downloadOriginalVideo(\'{$file_path}\', \'{$file_name}\')'>原画质下载</button>";
                                            echo "</div>";
                                            echo "</div>";
                                        } elseif (isset($msg['type']) && $msg['type'] == 'file') {
                                            // 其他文件类型
                                        ?>
                                            <div class="message-file" onclick="addDownloadTask('<?php echo htmlspecialchars($file_name, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($file_path, ENT_QUOTES); ?>', <?php echo $file_size; ?>, 'file')" style="position: relative; background: #f0f0f0; border-radius: 8px; padding: 12px; display: flex; align-items: center; gap: 12px; cursor: pointer;">
                                                <div class="message-file-link" data-file-name="<?php echo htmlspecialchars($file_name); ?>" data-file-size="<?php echo $file_size; ?>" data-file-type="file" data-file-path="<?php echo htmlspecialchars($file_path); ?>" style="display: flex; align-items: center; gap: 12px; text-decoration: none; color: inherit; flex: 1;">
                                                    <span class="file-icon" style="font-size: 24px;">📁</span>
                                                    <div class="file-info" style="flex: 1;">
                                                        <h4 style="margin: 0; font-size: 14px; font-weight: 500;"><?php echo htmlspecialchars($file_name); ?></h4>
                                                        <p style="margin: 2px 0 0 0; font-size: 12px; color: #666;"><?php echo round($file_size / 1024, 2); ?> KB</p>
                                                    </div>
                                                </div>
                                                <button onclick="event.stopPropagation(); addDownloadTask('<?php echo htmlspecialchars($file_name, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($file_path, ENT_QUOTES); ?>', <?php echo $file_size; ?>, 'file')" style="background: #667eea; color: white; border: none; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 12px; transition: all 0.2s ease;">下载</button>
                                            </div>
                                        <?php 
                                        } else {
                                            // 文本消息，检测并转换链接
                                            $content = $msg['content'];
                                            // 严格的HTML净化：移除所有HTML标签，只保留纯文本
                                            $content = strip_tags($content);
                                            // 再次进行HTML转义，确保绝对安全
                                            $content = htmlspecialchars($content);
                                            // 仅允许链接转换，不允许其他HTML
                                            $pattern = '/(https?:\/\/[^\s]+)/';
                                            $replacement = '<a href="#" onclick="event.preventDefault(); handleLinkClick(\'$1\')" style="color: #12b7f5; text-decoration: underline;">$1</a>';
                                            $content_with_links = preg_replace($pattern, $replacement, $content);
                                            echo "<div class='message-text'>{$content_with_links}</div>";
                                        }
                                    ?>
                                    <div class="message-time"><?php echo date('Y年m月d日 H:i', strtotime($msg['created_at'])); ?></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- 输入区域 -->
                <div class="input-area">
                    <!-- @提及用户列表 -->
                    <div id="mention-list" class="mention-list" style="
                        display: none;
                        position: absolute;
                        bottom: 80px;
                        left: 20px;
                        background: white;
                        border-radius: 8px;
                        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                        max-height: 200px;
                        overflow-y: auto;
                        z-index: 1000;
                        min-width: 200px;
                    "></div>
                    
                    <div class="input-container">
                        <div class="input-wrapper">
                            <textarea id="message-input" placeholder="输入消息..." rows="1" style="font-family: 'Microsoft YaHei', Tahoma, Geneva, Verdana, sans-serif; font-size: 14px; line-height: 1.5;"></textarea>
                        </div>
                        <div class="input-actions">
                            <button class="btn-icon" id="record-btn" onclick="toggleRecording()" title="录音 (按Q键开始/结束)" 
                                    style="color: #666; transition: all 0.2s ease;">🎤</button>
                            <button class="btn-icon" id="file-input-btn" title="发送文件">📎</button>
                            <input type="file" id="file-input" style="display: none;">

                            <button class="btn-icon" id="send-btn" title="发送消息">➤</button>
                        </div>
                    </div>
                    
                    <!-- @提及样式 -->
                    <style>
                        .mention-item {
                            padding: 10px 15px;
                            cursor: pointer;
                            display: flex;
                            align-items: center;
                            gap: 10px;
                            transition: background-color 0.2s ease;
                        }
                        
                        .mention-item:hover {
                            background-color: #f5f5f5;
                        }
                        
                        .mention-item.active {
                            background-color: #e6f7ff;
                        }
                        
                        .mention-avatar {
                            width: 32px;
                            height: 32px;
                            border-radius: 50%;
                            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                            display: flex;
                            align-items: center;
                            justify-content: center;
                            color: white;
                            font-weight: 600;
                            font-size: 14px;
                        }
                        
                        .mention-info {
                            flex: 1;
                        }
                        
                        .mention-username {
                            font-weight: 600;
                            font-size: 14px;
                        }
                        
                        .mention-nickname {
                            font-size: 12px;
                            color: #999;
                        }
                        
                        .mention-all {
                            color: #ff4d4f;
                            font-weight: 600;
                        }
                        
                        .message-text .mention {
                            color: #12b7f5;
                            font-weight: 600;
                        }
                        
                        .mention-badge {
                            background: #ff4d4f;
                            color: white;
                            font-size: 10px;
                            padding: 2px 6px;
                            border-radius: 10px;
                            margin-left: 5px;
                        }
                    </style>
                </div>
        </div>
    </div>
    
    <!-- 扫码登录模态框 -->
    <div class="modal" id="scan-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.9); z-index: 2000; flex-direction: column; align-items: center; justify-content: center;">
        <div style="position: relative; width: 100%; max-width: 400px;">
            <button onclick="closeScanModal()" style="position: absolute; top: -40px; right: 0; background: rgba(0, 0, 0, 0.5); color: white; border: none; border-radius: 50%; width: 30px; height: 30px; font-size: 20px; cursor: pointer; display: flex; align-items: center; justify-content: center;">
                ×
            </button>
            <video id="qr-video" style="width: 100%; height: auto; border-radius: 8px;" playsinline></video>
            <div id="scan-hint" style="color: white; text-align: center; margin-top: 20px; font-size: 16px;">请将二维码对准相机</div>
        </div>
    </div>
    
    <!-- 登录确认模态框 -->
    <div class="modal" id="confirm-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); z-index: 2000; flex-direction: column; align-items: center; justify-content: center;">
        <div style="background: white; padding: 20px; border-radius: 12px; width: 90%; max-width: 400px; text-align: center;">
            <h3 style="margin-bottom: 15px; color: #333;">确认登录</h3>
            <p id="confirm-message" style="margin-bottom: 20px; color: #666; font-size: 14px;"></p>
            <div style="display: flex; gap: 10px; justify-content: center;">
                <button onclick="rejectLogin()" style="padding: 10px 20px; background: #f5f5f5; color: #333; border: none; border-radius: 8px; cursor: pointer; font-weight: 500; flex: 1;">取消</button>
                <button onclick="confirmLogin()" style="padding: 10px 20px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 500; flex: 1;">确认</button>
            </div>
        </div>
    </div>
    
    <!-- 登录成功提示 -->
    <div class="modal" id="success-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); z-index: 2000; flex-direction: column; align-items: center; justify-content: center;">
        <div style="background: white; padding: 20px; border-radius: 12px; width: 90%; max-width: 300px; text-align: center;">
            <div style="font-size: 48px; margin-bottom: 15px;">✅</div>
            <h3 style="margin-bottom: 10px; color: #333;">登录成功</h3>
            <p style="margin-bottom: 20px; color: #666; font-size: 14px;">已成功在PC端登录</p>
            <button onclick="closeSuccessModal()" style="padding: 10px 20px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 500;">确定</button>
        </div>
    </div>
    
    <!-- 初始聊天记录数据 -->
    <script>
        // 扫码登录功能
        let scannerStream = null;
        let currentScanUrl = '';
        let currentQid = '';
        let currentIpAddress = '';
        
        // 显示扫码登录模态框
        function showScanLoginModal() {
            const modal = document.getElementById('scan-modal');
            modal.style.display = 'flex';
            initScanner();
        }
        
        // 关闭扫码登录模态框
        function closeScanModal() {
            const modal = document.getElementById('scan-modal');
            modal.style.display = 'none';
            stopScanner();
        }
        
        // 初始化扫码器
        async function initScanner() {
            try {
                // 请求相机权限，使用后置相机
                const stream = await navigator.mediaDevices.getUserMedia({
                    video: {
                        facingMode: 'environment',
                        width: { ideal: 1280 },
                        height: { ideal: 720 },
                        focusMode: 'continuous',
                        exposureMode: 'continuous'
                    }
                });
                
                scannerStream = stream;
                const video = document.getElementById('qr-video');
                video.srcObject = stream;
                await video.play();
                
                // 立即开始扫描
                startScanning(video);
            } catch (error) {
                console.error('相机访问失败:', error);
                const hint = document.getElementById('scan-hint');
                hint.textContent = '相机访问失败，请检查权限设置';
                hint.style.color = '#ff4757';
            }
        }
        
        // 停止扫码器
        function stopScanner() {
            if (scannerStream) {
                scannerStream.getTracks().forEach(track => track.stop());
                scannerStream = null;
            }
        }
        
        // 开始扫描
        function startScanning(video) {
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');
            
            // 设置扫码提示
            const hint = document.getElementById('scan-hint');
            hint.textContent = '正在扫描二维码...';
            hint.style.color = '#4caf50';
            
            function scanFrame() {
                if (video.readyState === video.HAVE_ENOUGH_DATA) {
                    // 确保canvas尺寸与视频尺寸匹配
                    canvas.width = video.videoWidth;
                    canvas.height = video.videoHeight;
                    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                    
                    try {
                        // 获取图像数据
                        const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
                        
                        // 检查jsQR库是否已加载
                        if (typeof jsQR === 'undefined') {
                            // jsQR库未加载，显示错误
                            hint.textContent = '二维码库加载中...';
                            hint.style.color = '#ff9800';
                            // 继续扫描
                            requestAnimationFrame(scanFrame);
                            return;
                        }
                        
                        // 使用jsQR库解码二维码
                        const code = jsQR(imageData.data, imageData.width, imageData.height, {
                            inversionAttempts: 'both'
                        });
                        
                        if (code) {
                            // 扫描成功，更新提示
                            hint.textContent = '扫描成功！';
                            hint.style.color = '#4caf50';
                            // 处理扫描结果
                            handleScanResult(code.data);
                        } else {
                            // 继续扫描
                            requestAnimationFrame(scanFrame);
                        }
                    } catch (error) {
                        console.error('扫描错误:', error);
                        // 继续扫描
                        requestAnimationFrame(scanFrame);
                    }
                } else {
                    requestAnimationFrame(scanFrame);
                }
            }
            
            // 开始扫描循环
            requestAnimationFrame(scanFrame);
        }
        
        // 处理扫描结果
        function handleScanResult(result) {
            if (!result) return;
            
            console.log('扫描到的二维码内容:', result);
            
            // 检查是否是本站的扫码登录链接
            const domain = window.location.host;
            console.log('当前域名:', domain);
            
            if (result.includes(domain) && result.includes('scan_login.php')) {
                // 解析URL获取qid
                try {
                    const url = new URL(result);
                    const qid = url.searchParams.get('qid');
                    
                    console.log('解析到的qid:', qid);
                    
                    if (qid) {
                        currentScanUrl = result;
                        currentQid = qid;
                        
                        // 扫描后立即更新状态为scanned
                        fetch('scan_login.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: new URLSearchParams({
                                'qid': qid,
                                'action': 'scan',
                                'source': 'mobilechat.php'
                            })
                        }).catch(error => {
                            console.error('更新扫描状态失败:', error);
                        });
                        
                        // 显示确认登录对话框
                        console.log('显示确认登录对话框');
                        showConfirmModal();
                        
                        // 停止扫描
                        console.log('停止扫描');
                        closeScanModal();
                    } else {
                        console.log('未解析到qid');
                    }
                } catch (error) {
                    console.error('URL解析错误:', error);
                    alert('二维码格式错误，请扫描正确的登录二维码');
                }
            } else {
                console.log('不是本站的扫码登录链接');
                alert('不是本站的扫码登录链接');
            }
        }
        
        // 显示确认登录模态框
        function showConfirmModal() {
            const modal = document.getElementById('confirm-modal');
            const message = document.getElementById('confirm-message');
            const confirmBtn = modal.querySelector('button[onclick="confirmLogin()"]');
            
            // 设置倒计时初始值
            let countdown = 6;
            
            // 禁用确认按钮
            confirmBtn.disabled = true;
            confirmBtn.style.opacity = '0.5';
            confirmBtn.style.cursor = 'not-allowed';
            
            // 显示加载中状态
            message.innerHTML = `确定要在PC网页端登录吗？<br><br>正在获取登录IP地址...<br><br><small>请等待 ${countdown} 秒后点击确认</small>`;
            modal.style.display = 'flex';
            
            // 从服务器获取扫码登录的IP地址
            fetch(`get_scan_ip.php?qid=${currentQid}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        currentIpAddress = data.ip_address;
                        message.innerHTML = `确定要在PC网页端登录吗？<br><br>登录IP地址: <strong>${currentIpAddress}</strong><br><br><small>请等待 ${countdown} 秒后点击确认</small>`;
                    } else {
                        currentIpAddress = '获取IP失败';
                        message.innerHTML = `确定要在PC网页端登录吗？<br><br>登录IP地址: <strong>${currentIpAddress}</strong><br><br><small>请等待 ${countdown} 秒后点击确认</small>`;
                    }
                })
                .catch(error => {
                    console.error('获取IP地址失败:', error);
                    currentIpAddress = '获取IP失败';
                    message.innerHTML = `确定要在PC网页端登录吗？<br><br>登录IP地址: <strong>${currentIpAddress}</strong><br><br><small>请等待 ${countdown} 秒后点击确认</small>`;
                });
            
            // 倒计时功能
            const countdownInterval = setInterval(() => {
                countdown--;
                message.innerHTML = `确定要在PC网页端登录吗？<br><br>登录IP地址: <strong>${currentIpAddress}</strong><br><br><small>请等待 ${countdown} 秒后点击确认</small>`;
                
                if (countdown <= 0) {
                    clearInterval(countdownInterval);
                    // 启用确认按钮
                    confirmBtn.disabled = false;
                    confirmBtn.style.opacity = '1';
                    confirmBtn.style.cursor = 'pointer';
                    message.innerHTML = `确定要在PC网页端登录吗？<br><br>登录IP地址: <strong>${currentIpAddress}</strong>`;
                }
            }, 1000);
        }
        
        // 确认登录
        function confirmLogin() {
            const modal = document.getElementById('confirm-modal');
            modal.style.display = 'none';
            
            // 发送登录请求
            sendLoginRequest();
        }
        
        // 拒绝登录
        function rejectLogin() {
            const modal = document.getElementById('confirm-modal');
            modal.style.display = 'none';
            
            // 发送拒绝登录请求，更新状态为rejected
            fetch('scan_login.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    'qid': currentQid,
                    'action': 'reject',
                    'source': 'mobilechat.php'
                })
            }).then(response => response.json())
              .then(result => {
                  console.log('拒绝登录结果:', result);
              })
              .catch(error => {
                  console.error('发送拒绝登录请求失败:', error);
              });
        }
        
        // 发送登录请求
        async function sendLoginRequest() {
            try {
                const response = await fetch('scan_login.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        'qid': currentQid,
                        'user': '<?php echo $username; ?>',
                        'source': 'mobilechat.php'
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    // 显示登录成功提示
                    showSuccessModal();
                } else {
                    alert(result.message || '登录失败');
                }
            } catch (error) {
                console.error('发送登录请求失败:', error);
                alert('登录失败，请稍后重试');
            }
        }
        
        // 关闭成功提示
        function closeSuccessModal() {
            const modal = document.getElementById('success-modal');
            modal.style.display = 'none';
        }
        
        // 加载jsQR库
        function loadJsQR() {
            if (typeof jsQR !== 'undefined') {
                return Promise.resolve();
            }
            
            return new Promise((resolve, reject) => {
                const script = document.createElement('script');
                script.src = './js/jsQR.min.js';
                script.onload = resolve;
                script.onerror = reject;
                document.head.appendChild(script);
            });
        }
        
        // 页面加载完成后加载jsQR库并初始化@功能
        document.addEventListener('DOMContentLoaded', () => {
            loadJsQR().catch(error => {
                console.error('加载jsQR库失败:', error);
            });
            
            // 初始化@提及功能
            initMentionFeature();
        });
        // 检查群聊是否被封禁
        let isGroupBanned = false;
        
        // @提及功能相关变量
        let mentionListVisible = false;
        let currentMentions = [];
        let selectedMentionIndex = -1;
        let groupMembers = [];
        
        // 获取群聊成员列表
        async function getGroupMembers(groupId) {
            try {
                const response = await fetch(`get_group_members.php?group_id=${groupId}`);
                const data = await response.json();
                if (data.success) {
                    return data.members;
                }
                return [];
            } catch (error) {
                console.error('获取群成员失败:', error);
                return [];
            }
        }
        
        // 初始化群聊成员
        async function initGroupMembers() {
            const chatType = '<?php echo $chat_type; ?>';
            const groupId = '<?php echo $selected_id; ?>';
            
            if (chatType === 'group') {
                groupMembers = await getGroupMembers(groupId);
            }
        }
        
        // 初始化@提及功能
        function initMentionFeature() {
            const input = document.getElementById('message-input');
            const mentionList = document.getElementById('mention-list');
            
            if (!input || !mentionList) return;
            
            // 初始化群成员
            initGroupMembers();
            
            // 输入事件监听
            input.addEventListener('input', handleMentionInput);
            
            // 按键事件监听
            input.addEventListener('keydown', handleMentionKeydown);
            
            // 点击外部关闭提及列表
            document.addEventListener('click', (e) => {
                if (!input.contains(e.target) && !mentionList.contains(e.target)) {
                    hideMentionList();
                }
            });
        }
        
        // 处理输入事件，检测@符号
        function handleMentionInput(e) {
            const input = e.target;
            const cursorPos = input.selectionStart;
            const text = input.value;
            
            // 查找@符号的位置
            const atIndex = text.lastIndexOf('@', cursorPos - 1);
            
            // 检查@符号是否在有效位置
            if (atIndex !== -1) {
                // 检查@符号后是否有空格或其他分隔符
                const nextChar = text.charAt(atIndex + 1);
                if (!nextChar || nextChar.match(/\s|$/) || nextChar === '@') {
                    // 显示提及列表
                    showMentionList(input, atIndex + 1);
                }
            } else {
                // 没有@符号，隐藏提及列表
                hideMentionList();
            }
        }
        
        // 显示提及列表
        function showMentionList(input, startIndex) {
            const mentionList = document.getElementById('mention-list');
            const chatType = '<?php echo $chat_type; ?>';
            
            // 只有群聊才显示提及列表
            if (chatType !== 'group') {
                hideMentionList();
                return;
            }
            
            // 准备成员数据，添加"全体成员"作为第一个选项
            const mentionOptions = [
                { id: 'all', username: '全体成员', is_all: true }
            ];
            
            // 添加群成员
            groupMembers.forEach(member => {
                mentionOptions.push({
                    id: member.id,
                    username: member.username,
                    nickname: member.nickname || '',
                    avatar: member.avatar
                });
            });
            
            // 渲染提及列表
            mentionList.innerHTML = mentionOptions.map((member, index) => {
                const isAll = member.is_all;
                return `
                    <div class="mention-item" data-id="${member.id}" data-username="${member.username}" data-is-all="${isAll}">
                        <div class="mention-avatar">
                            ${isAll ? '👥' : member.avatar ? `<img src="${member.avatar}" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">` : member.username.charAt(0).toUpperCase()}
                        </div>
                        <div class="mention-info">
                            <div class="mention-username ${isAll ? 'mention-all' : ''}">${member.username}</div>
                            ${member.nickname ? `<div class="mention-nickname">${member.nickname}</div>` : ''}
                        </div>
                    </div>
                `;
            }).join('');
            
            // 显示列表
            mentionList.style.display = 'block';
            mentionListVisible = true;
            selectedMentionIndex = -1;
            
            // 添加点击事件
            mentionList.querySelectorAll('.mention-item').forEach(item => {
                item.addEventListener('click', () => {
                    selectMention(item, input, startIndex);
                });
            });
        }
        
        // 隐藏提及列表
        function hideMentionList() {
            const mentionList = document.getElementById('mention-list');
            mentionList.style.display = 'none';
            mentionListVisible = false;
            selectedMentionIndex = -1;
        }
        
        // 处理按键事件
        function handleMentionKeydown(e) {
            const mentionList = document.getElementById('mention-list');
            const items = mentionList.querySelectorAll('.mention-item');
            
            if (!mentionListVisible) return;
            
            switch (e.key) {
                case 'ArrowUp':
                    e.preventDefault();
                    selectedMentionIndex = Math.max(0, selectedMentionIndex - 1);
                    updateSelectedMention(items);
                    break;
                case 'ArrowDown':
                    e.preventDefault();
                    selectedMentionIndex = Math.min(items.length - 1, selectedMentionIndex + 1);
                    updateSelectedMention(items);
                    break;
                case 'Enter':
                    e.preventDefault();
                    if (selectedMentionIndex >= 0 && selectedMentionIndex < items.length) {
                        const input = document.getElementById('message-input');
                        const cursorPos = input.selectionStart;
                        const atIndex = input.value.lastIndexOf('@', cursorPos - 1);
                        selectMention(items[selectedMentionIndex], input, atIndex + 1);
                    }
                    break;
                case 'Escape':
                    hideMentionList();
                    break;
            }
        }
        
        // 更新选中的提及项
        function updateSelectedMention(items) {
            items.forEach((item, index) => {
                if (index === selectedMentionIndex) {
                    item.classList.add('active');
                    item.scrollIntoView({ block: 'nearest' });
                } else {
                    item.classList.remove('active');
                }
            });
        }
        
        // 选择提及项
        function selectMention(item, input, startIndex) {
            const username = item.dataset.username;
            const isAll = item.dataset.isAll === 'true';
            const mentionText = isAll ? `@全体成员 ` : `@${username} `;
            
            const value = input.value;
            const cursorPos = input.selectionStart;
            
            // 替换@符号及其后的内容为选中的用户名
            const newValue = value.substring(0, startIndex - 1) + mentionText + value.substring(cursorPos);
            input.value = newValue;
            
            // 设置光标位置
            const newCursorPos = startIndex - 1 + mentionText.length;
            input.setSelectionRange(newCursorPos, newCursorPos);
            input.focus();
            
            // 隐藏提及列表
            hideMentionList();
        }
        
        // 切换聊天时重新初始化群成员并重置@提及标记
        function switchChat(chatType, chatId) {
            if (chatType === 'group') {
                initGroupMembers();
                
                // 重置@提及标记
                fetch(`reset_mention.php?chat_type=group&chat_id=${chatId}`)
                    .catch(error => {
                        console.error('重置@提及标记失败:', error);
                    });
            }
        }
        
        // 启用所有群聊操作
        function enableGroupOperations() {
            const inputArea = document.querySelector('.input-area');
            if (inputArea) {
                inputArea.style.display = 'block';
            }
        }
        
        function checkGroupBanStatus(groupId) {
            return fetch(`check_group_ban.php?group_id=${groupId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.banned) {
                        isGroupBanned = true;
                        showGroupBanModal(data.group_name, data.reason, data.ban_end);
                        disableGroupOperations();
                    } else {
                        isGroupBanned = false;
                        enableGroupOperations();
                    }
                    return data.banned;
                })
                .catch(error => {
                    console.error('检查群聊封禁状态失败:', error);
                    // 出错时默认启用群聊操作
                    enableGroupOperations();
                    return false;
                });
        }
        
        // 显示群聊封禁弹窗
        function showGroupBanModal(groupName, reason, banEnd) {
            const modal = document.createElement('div');
            modal.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.5);
                z-index: 10000;
                display: flex;
                align-items: center;
                justify-content: center;
            `;
            
            const modalContent = document.createElement('div');
            modalContent.style.cssText = `
                background: white;
                padding: 30px;
                border-radius: 12px;
                width: 90%;
                max-width: 400px;
                text-align: center;
                box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            `;
            
            modalContent.innerHTML = `
                <div style="font-size: 64px; margin-bottom: 20px; color: #ff4757;">🚫</div>
                <h3 style="margin-bottom: 15px; color: #333; font-size: 18px;">群聊已被封禁</h3>
                <div style="margin-bottom: 25px; color: #666; font-size: 14px;">
                    <p>此群 <strong>${groupName}</strong> 已被封禁</p>
                    <p style="margin: 10px 0;">原因：${reason}</p>
                    <p>预计解封时长：${banEnd ? new Date(banEnd).toLocaleString() : '永久'}</p>
                    <p style="color: #ff4757; margin-top: 15px;">群聊被封禁期间，无法使用任何群聊功能</p>
                </div>
                <button onclick="document.body.removeChild(modal); window.location.href='chat.php'" style="
                    padding: 12px 30px;
                    background: #667eea;
                    color: white;
                    border: none;
                    border-radius: 8px;
                    cursor: pointer;
                    font-weight: 500;
                    font-size: 14px;
                    transition: background-color 0.2s;
                ">确定</button>
            `;
            
            modal.appendChild(modalContent);
            document.body.appendChild(modal);
        }
        
        // 禁用所有群聊操作
        function disableGroupOperations() {
            const inputArea = document.querySelector('.input-area');
            if (inputArea) {
                inputArea.style.display = 'none';
            }
        }
        
        // 显示聊天列表（主界面）
        function showChatList() {
            window.location.href = 'mobilechat.php';
        }
        
        // 聊天类型切换
        document.querySelectorAll('.chat-type-tab').forEach(tab => {
            tab.addEventListener('click', function() {
                const chatType = this.dataset.chatType;
                
                // 更新标签状态
                document.querySelectorAll('.chat-type-tab').forEach(t => t.classList.remove('active'));
                this.classList.add('active');
                
                // 切换聊天列表
                document.getElementById('friends-list').style.display = chatType === 'friend' ? 'block' : 'none';
                document.getElementById('groups-list').style.display = chatType === 'group' ? 'block' : 'none';
                
                // 重新加载页面
                window.location.href = `mobilechat.php?chat_type=${chatType}`;
            });
        });
        
        // 等待DOM加载完成后绑定事件
        document.addEventListener('DOMContentLoaded', function() {
            // 聊天列表点击事件
            document.querySelectorAll('.chat-item[data-friend-id], .chat-item[data-group-id]').forEach(item => {
                item.addEventListener('click', function(e) {
                    // 如果点击的是菜单按钮或菜单选项，不触发聊天切换
                    if (e.target.closest('.chat-item-menu-btn') || e.target.closest('.friend-menu-item')) {
                        return;
                    }
                    
                    if (this.dataset.friendId) {
                        const friendId = this.dataset.friendId;
                        window.location.href = `mobilechat.php?chat_type=friend&id=${friendId}`;
                    } else if (this.dataset.groupId) {
                        const groupId = this.dataset.groupId;
                        window.location.href = `mobilechat.php?chat_type=group&id=${groupId}`;
                    }
                });
            });
            
            // 为好友菜单选项添加点击事件冒泡阻止
            document.addEventListener('click', function(e) {
                if (e.target.classList.contains('friend-menu-item')) {
                    e.stopPropagation();
                }
            });
        });
        
        // 好友菜单功能
        function toggleFriendMenu(event, friendId, friendName) {
            event.stopPropagation();
            
            // 关闭所有其他菜单，并重置所有chat-item的z-index
            document.querySelectorAll('.friend-menu').forEach(menu => {
                menu.style.display = 'none';
            });
            document.querySelectorAll('.chat-item').forEach(item => {
                item.style.zIndex = '';
            });
            
            // 切换当前菜单
            const menu = document.getElementById(`friend-menu-${friendId}`);
            if (menu) {
                const isOpening = menu.style.display !== 'block';
                menu.style.display = isOpening ? 'block' : 'none';
                
                // 如果是打开菜单，提高当前项的z-index
                if (isOpening) {
                    const chatItem = menu.closest('.chat-item');
                    if (chatItem) {
                        chatItem.style.zIndex = '1000';
                    }
                }
            }
        }
        
        // 群聊菜单功能
        function toggleGroupMenu(event, groupId, groupName) {
            event.stopPropagation();
            
            // 关闭所有其他菜单，并重置所有chat-item的z-index
            document.querySelectorAll('.friend-menu').forEach(menu => {
                menu.style.display = 'none';
            });
            document.querySelectorAll('.chat-item').forEach(item => {
                item.style.zIndex = '';
            });
            
            // 切换当前菜单
            const menu = document.getElementById(`group-menu-${groupId}`);
            if (menu) {
                const isOpening = menu.style.display !== 'block';
                menu.style.display = isOpening ? 'block' : 'none';
                
                // 如果是打开菜单，提高当前项的z-index
                if (isOpening) {
                    const chatItem = menu.closest('.chat-item');
                    if (chatItem) {
                        chatItem.style.zIndex = '1000';
                    }
                }
            }
        }
        
        // 删除好友功能
        function deleteFriend(friendId, friendName) {
            // 创建更美观的确认对话框
            const modal = document.createElement('div');
            modal.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.5);
                z-index: 10000;
                display: flex;
                align-items: center;
                justify-content: center;
                font-family: 'Microsoft YaHei', Tahoma, Geneva, Verdana, sans-serif;
            `;
            
            const modalContent = document.createElement('div');
            modalContent.style.cssText = `
                background: var(--modal-bg);
                color: var(--text-color);
                border-radius: 8px;
                padding: 25px;
                width: 90%;
                max-width: 400px;
                box-shadow: 0 4px 20px var(--shadow-color);
            `;
            
            // 标题
            const title = document.createElement('h3');
            title.style.cssText = `
                margin-bottom: 20px;
                color: var(--text-color);
                font-size: 18px;
                font-weight: 600;
                text-align: center;
            `;
            title.textContent = '删除好友';
            
            // 内容
            const content = document.createElement('div');
            content.style.cssText = `
                margin-bottom: 25px;
                color: var(--text-secondary);
                font-size: 14px;
                text-align: center;
                line-height: 1.6;
            `;
            content.innerHTML = `
                <p>确定要删除好友 <strong>${friendName}</strong> 吗？</p>
                <p style="margin-top: 15px; color: var(--text-desc); font-size: 12px;">删除后将无法恢复，请谨慎操作</p>
            `;
            
            // 按钮容器
            const buttonContainer = document.createElement('div');
            buttonContainer.style.cssText = `
                display: flex;
                gap: 15px;
                justify-content: center;
            `;
            
            // 取消按钮
            const cancelBtn = document.createElement('button');
            cancelBtn.style.cssText = `
                flex: 1;
                padding: 12px;
                background: var(--bg-color);
                color: var(--text-color);
                border: 1px solid var(--border-color);
                border-radius: 6px;
                cursor: pointer;
                font-size: 14px;
                font-weight: 500;
                transition: all 0.2s ease;
            `;
            cancelBtn.textContent = '取消';
            cancelBtn.addEventListener('click', () => {
                document.body.removeChild(modal);
            });
            
            // 确定删除按钮
            const confirmBtn = document.createElement('button');
            confirmBtn.style.cssText = `
                flex: 1;
                padding: 12px;
                background: #ff4757;
                color: white;
                border: none;
                border-radius: 6px;
                cursor: pointer;
                font-size: 14px;
                font-weight: 500;
                transition: all 0.2s ease;
                z-index: 99999;
                position: relative;
            `;
            confirmBtn.textContent = '确定删除';
            confirmBtn.addEventListener('click', () => {
                // 发送删除好友请求
                fetch(`delete_friend.php?friend_id=${friendId}`, {
                    method: 'POST',
                    credentials: 'include'
                })
                .then(response => response.json())
                .then(data => {
                    document.body.removeChild(modal);
                    
                    if (data.success) {
                        // 使用局部刷新而不是整个页面刷新
                        showNotification('好友删除成功', 'success');
                        
                        // 移除好友列表项
                        const friendItem = document.querySelector(`[data-friend-id="${friendId}"]`);
                        if (friendItem) {
                            friendItem.remove();
                        }
                        
                        // 如果当前正在与该好友聊天，切换到第一个好友或显示提示
                        if (window.location.search.includes(`id=${friendId}`) && window.location.search.includes('chat_type=friend')) {
                            // 获取第一个好友项
                            const firstFriendItem = document.querySelector('[data-friend-id]:not([data-friend-id="${friendId}"])');
                            if (firstFriendItem) {
                                const firstFriendId = firstFriendItem.getAttribute('data-friend-id');
                                window.location.href = `?chat_type=friend&id=${firstFriendId}`;
                            } else {
                                // 没有其他好友，显示提示
                                window.location.href = '?';
                            }
                        }
                    } else {
                        showNotification('删除好友失败：' + data.message, 'error');
                    }
                })
                .catch(error => {
                    document.body.removeChild(modal);
                    console.error('删除好友失败:', error);
                    showNotification('删除好友失败，请稍后重试', 'error');
                });
            });
            
            // 组装弹窗
            buttonContainer.appendChild(cancelBtn);
            buttonContainer.appendChild(confirmBtn);
            modalContent.appendChild(title);
            modalContent.appendChild(content);
            modalContent.appendChild(buttonContainer);
            modal.appendChild(modalContent);
            
            // 添加到页面
            document.body.appendChild(modal);
        }
        
        // 显示通知函数
        function showNotification(message, type = 'success') {
            const notification = document.createElement('div');
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: ${type === 'success' ? '#4caf50' : '#ff4757'};
                color: white;
                padding: 15px 20px;
                border-radius: 8px;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
                z-index: 10001;
                font-family: 'Microsoft YaHei', Tahoma, Geneva, Verdana, sans-serif;
                font-size: 14px;
                transition: all 0.3s ease;
            `;
            notification.textContent = message;
            
            document.body.appendChild(notification);
            
            // 3秒后自动消失
            setTimeout(() => {
                notification.style.opacity = '0';
                notification.style.transform = 'translateX(100%)';
                setTimeout(() => {
                    document.body.removeChild(notification);
                }, 300);
            }, 3000);
        }
        
        // 点击页面其他地方关闭菜单
        document.addEventListener('click', function() {
            document.querySelectorAll('.friend-menu').forEach(menu => {
                menu.style.display = 'none';
            });
        });
        
        // 设置弹窗功能
        function openSettingsModal() {
            // 加载设置
            loadSettings();
            // 显示设置弹窗
            document.getElementById('settings-modal').style.display = 'flex';
        }
        
        function closeSettingsModal() {
            // 保存设置
            saveSettings();
            // 关闭设置弹窗
            document.getElementById('settings-modal').style.display = 'none';
        }
        
        // 退出登录函数
        function logout() {
            if (confirm('确定要退出登录吗？')) {
                // 发送退出登录请求到服务器
                fetch('logout.php', {
                    method: 'POST',
                    credentials: 'include'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // 只清除设置数据，不清除文件数据
                        localStorage.removeItem('setting-link-popup');
                        // 重定向到登录页面
                        window.location.href = 'login.php';
                    } else {
                        // 退出失败，显示错误信息
                        showNotification(data.message || '退出登录失败', 'error');
                    }
                })
                .catch(error => {
                    console.error('退出登录请求失败:', error);
                    // 即使请求失败，也尝试直接跳转
                    localStorage.removeItem('setting-link-popup');
                    window.location.href = 'login.php';
                });
            }
        }
        
        // 加载设置
        async function loadSettings() {
            try {
                // 检查indexedDBManager是否存在
                if (typeof indexedDBManager === 'undefined' || !indexedDBManager.getSettings) {
                    throw new Error('IndexedDB manager not available');
                }

                // 从IndexedDB加载设置
                const settings = await indexedDBManager.getSettings();
                const linkPopup = settings['setting-link-popup'] !== false;
                const musicPlayer = settings['setting-music-player'] !== false;
                const musicMode = settings['setting-music-mode'] || 'random';
                
                // 设置开关状态
                const linkPopupEl = document.getElementById('setting-link-popup');
                if (linkPopupEl) linkPopupEl.checked = linkPopup;
                
                const musicPlayerEl = document.getElementById('setting-music-player');
                if (musicPlayerEl) musicPlayerEl.checked = musicPlayer;
                
                const musicModeEl = document.getElementById('setting-music-mode');
                if (musicModeEl) musicModeEl.value = musicMode;
            } catch (error) {
                console.error('加载设置失败:', error);
                // 从localStorage迁移设置到IndexedDB
                const linkPopup = localStorage.getItem('setting-link-popup') === 'false' ? false : true;
                const musicPlayer = localStorage.getItem('setting-music-player') === 'false' ? false : true;
                const musicMode = localStorage.getItem('setting-music-mode') || 'random';
                
                // 设置开关状态
                const linkPopupEl = document.getElementById('setting-link-popup');
                if (linkPopupEl) linkPopupEl.checked = linkPopup;
                
                const musicPlayerEl = document.getElementById('setting-music-player');
                if (musicPlayerEl) musicPlayerEl.checked = musicPlayer;
                
                const musicModeEl = document.getElementById('setting-music-mode');
                if (musicModeEl) musicModeEl.value = musicMode;
                
                // 保存到IndexedDB
                if (typeof saveSettings === 'function') {
                    await saveSettings();
                }
            }
        }
        
        // 保存设置
        async function saveSettings() {
            try {
                // 获取开关状态
                const linkPopup = document.getElementById('setting-link-popup').checked;
                const musicPlayer = document.getElementById('setting-music-player').checked;
                const musicMode = document.getElementById('setting-music-mode').value;
                
                // 保存到IndexedDB
                await indexedDBManager.saveSettings({
                    'setting-link-popup': linkPopup,
                    'setting-music-player': musicPlayer,
                    'setting-music-mode': musicMode
                });
                
                // 应用设置
                applySettings();
            } catch (error) {
                console.error('保存设置失败:', error);
                // 降级到localStorage
                localStorage.setItem('setting-link-popup', linkPopup);
                localStorage.setItem('setting-music-player', musicPlayer);
                localStorage.setItem('setting-music-mode', musicMode);
            }
        }
        
        // 清除文件缓存
        function clearFileCache() {
            if (confirm('确定要清除所有文件缓存吗？此操作不可恢复。')) {
                try {
                    // 获取文件索引
                    const fileIndex = JSON.parse(localStorage.getItem('fileIndex') || '[]');
                    
                    // 清除所有文件数据
                    fileIndex.forEach(fileId => {
                        localStorage.removeItem(fileId);
                    });
                    
                    // 清除文件索引
                    localStorage.removeItem('fileIndex');
                    
                    // 显示清除成功通知
                    showNotification('文件缓存已成功清除', 'success');
                } catch (error) {
                    console.error('清除文件缓存失败:', error);
                    showNotification('清除文件缓存失败，请稍后重试', 'error');
                }
            }
        }
        
        // 应用设置
        function applySettings() {
            // 这里可以添加应用设置的逻辑
            const linkPopup = localStorage.getItem('setting-link-popup') === 'true';
            const musicPlayer = localStorage.getItem('setting-music-player') === 'true';
            const musicMode = localStorage.getItem('setting-music-mode') || 'random';
            
            console.log('应用设置:', {
                linkPopup,
                musicPlayer,
                musicMode
            });
        }
        
        // 显示缓存查看器
        function showCacheViewer() {
            const modal = document.getElementById('cache-viewer-modal');
            modal.style.display = 'flex';
            
            // 加载缓存统计信息
            loadCacheStats();
        }
        
        // 关闭缓存查看器
        function closeCacheViewer() {
            const modal = document.getElementById('cache-viewer-modal');
            modal.style.display = 'none';
        }
        
        // 加载缓存统计信息
        async function loadCacheStats() {
            const statsContainer = document.getElementById('cache-stats');
            if (!statsContainer) return;
            
            // 解析缓存信息
            const cacheInfo = await parseCacheCookies();
            
            // 生成统计HTML
            let statsHtml = `
                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 6px;">
                    <div style="background: #f0f8ff; padding: 6px 10px; border-radius: 4px; border-left: 2px solid #12b7f5; font-size: 12px;">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span style="color: #12b7f5; font-weight: 600;">音频</span>
                            <span style="font-weight: 600;">${cacheInfo.audio.count}</span>
                        </div>
                        <div style="font-size: 10px; color: #666; margin-top: 2px;">${formatFileSize(cacheInfo.audio.size)}</div>
                    </div>
                    
                    <div style="background: #f0fff4; padding: 6px 10px; border-radius: 4px; border-left: 2px solid #52c41a; font-size: 12px;">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span style="color: #52c41a; font-weight: 600;">视频</span>
                            <span style="font-weight: 600;">${cacheInfo.video.count}</span>
                        </div>
                        <div style="font-size: 10px; color: #666; margin-top: 2px;">${formatFileSize(cacheInfo.video.size)}</div>
                    </div>
                    
                    <div style="background: #fffbe6; padding: 6px 10px; border-radius: 4px; border-left: 2px solid #faad14; font-size: 12px;">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span style="color: #faad14; font-weight: 600;">图片</span>
                            <span style="font-weight: 600;">${cacheInfo.image.count}</span>
                        </div>
                        <div style="font-size: 10px; color: #666; margin-top: 2px;">${formatFileSize(cacheInfo.image.size)}</div>
                    </div>
                    
                    <div style="background: #fff2f0; padding: 6px 10px; border-radius: 4px; border-left: 2px solid #ff4d4f; font-size: 12px;">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span style="color: #ff4d4f; font-weight: 600;">其他</span>
                            <span style="font-weight: 600;">${cacheInfo.file.count}</span>
                        </div>
                        <div style="font-size: 10px; color: #666; margin-top: 2px;">${formatFileSize(cacheInfo.file.size)}</div>
                    </div>
                </div>
                <div style="margin-top: 8px; padding: 8px; background: #fafafa; border-radius: 4px; text-align: center; font-size: 13px;">
                    <div style="display: flex; justify-content: center; align-items: center; gap: 8px;">
                        <span style="font-weight: 600; color: #333;">总计: ${cacheInfo.total.count}</span>
                        <span style="font-size: 11px; color: #666;">${formatFileSize(cacheInfo.total.size)}</span>
                    </div>
                </div>
            `;
            
            statsContainer.innerHTML = statsHtml;
        }
        
        // 解析缓存信息（同时考虑localStorage和cookie）
        async function parseCacheCookies() {
            const cacheInfo = {
                audio: { count: 0, size: 0 },
                video: { count: 0, size: 0 },
                image: { count: 0, size: 0 },
                file: { count: 0, size: 0 },
                total: { count: 0, size: 0 }
            };
            
            try {
                // 只使用IndexedDBManager获取缓存统计信息，不再处理localStorage和cookie中的旧数据
                // 这样可以避免重复计算和统计错误
                const stats = await indexedDBManager.getCacheStats();
                
                // 更新缓存统计信息
                cacheInfo.audio.count = stats.byType.audio;
                cacheInfo.video.count = stats.byType.video;
                cacheInfo.image.count = stats.byType.image;
                cacheInfo.file.count = stats.byType.file;
                cacheInfo.total.count = stats.totalFiles;
                cacheInfo.total.size = stats.totalSize;
                
                // 更新每种类型的大小
                cacheInfo.audio.size = stats.byTypeSize?.audio || 0;
                cacheInfo.video.size = stats.byTypeSize?.video || 0;
                cacheInfo.image.size = stats.byTypeSize?.image || 0;
                cacheInfo.file.size = stats.byTypeSize?.file || 0;
            } catch (error) {
                console.error('获取缓存统计失败:', error);
            }
            
            return cacheInfo;
        }
        
        // 格式化文件大小
        function formatFileSize(bytes) {
            if (bytes === 0) return '0 B';
            
            const k = 1024;
            const sizes = ['B', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }
        
        // 显示清空缓存确认弹窗
        async function showClearCacheConfirm() {
            const modal = document.getElementById('clear-cache-confirm-modal');
            const cacheSizeElement = document.getElementById('clear-cache-size');
            
            // 获取缓存总大小
            const cacheInfo = await parseCacheCookies();
            cacheSizeElement.textContent = formatFileSize(cacheInfo.total.size);
            
            modal.style.display = 'flex';
        }
        
        // 关闭清空缓存确认弹窗
        function closeClearCacheConfirm() {
            const modal = document.getElementById('clear-cache-confirm-modal');
            modal.style.display = 'none';
        }
        
        // 清空缓存
        async function clearCache() {
            // 1. 使用IndexedDBManager清除所有缓存
            try {
                await indexedDBManager.clearAllCache();
            } catch (e) {
                console.error('清除缓存失败:', e);
            }
            
            // 2. 清除所有缓存相关的cookie
            const cookies = document.cookie.split(';');
            
            cookies.forEach(cookie => {
                const cookieTrimmed = cookie.trim();
                // 检查是否是缓存相关的cookie（支持多种前缀）
                if (cookieTrimmed.startsWith('file_') || 
                    cookieTrimmed.startsWith('video_') || 
                    cookieTrimmed.startsWith('audio_') || 
                    cookieTrimmed.startsWith('Picture_') ||
                    cookieTrimmed.startsWith('Video_') ||
                    cookieTrimmed.startsWith('Audio_')) {
                    // 这是一个缓存文件的cookie，删除它
                    const cookieName = cookieTrimmed.split('=')[0];
                    document.cookie = `${cookieName}=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;`;
                }
            });
            
            // 关闭确认弹窗
            closeClearCacheConfirm();
            
            // 关闭缓存查看器
            closeCacheViewer();
            
            // 显示成功消息
            showNotification('缓存已清空', 'success');
        }
        
        // 初始化设置
        applySettings();
        
        // 文件上传和消息发送功能 - 只在聊天界面可用
        function initChatInput() {
            const fileInputBtn = document.getElementById('file-input-btn');
            const fileInput = document.getElementById('file-input');
            const sendBtn = document.getElementById('send-btn');
            const messageInput = document.getElementById('message-input');
            
            // 文件上传按钮
            if (fileInputBtn) {
                fileInputBtn.addEventListener('click', function() {
                    if (fileInput) {
                        fileInput.click();
                    } else {
                        console.error('File input element not found');
                    }
                });
            }
            
            // 文件选择事件处理
            if (fileInput) {
                fileInput.addEventListener('change', function(e) {
                    const file = e.target.files[0];
                    if (file) {
                        sendFile(file);
                    }
                });
            }
            
            // 发送按钮点击事件
            if (sendBtn) {
                sendBtn.addEventListener('click', sendMessage);
            }
            
            // 回车键发送消息
            if (messageInput) {
                messageInput.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        sendMessage();
                    }
                });
            }
        }
        
        // 初始化聊天输入功能
        initChatInput();
        
        // 链接检测函数
        function isLink(url) {
            const urlRegex = /^(https?:\/\/)?([\da-z.-]+)\.([a-z.]{2,6})([/\w .-]*)*\/?$/;
            return urlRegex.test(url);
        }
        
        // 创建链接弹窗（使用iframe）
        function createLinkPopup(url) {
            // 创建弹窗容器
            const popup = document.createElement('div');
            popup.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.8);
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 2000;
            `;
            
            // 创建弹窗内容
            const popupContent = document.createElement('div');
            popupContent.style.cssText = `
                background: white;
                border-radius: 12px;
                width: 90%;
                max-width: 1000px;
                height: 80%;
                max-height: 800px;
                display: flex;
                flex-direction: column;
                overflow: hidden;
                box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
                resize: both;
                min-width: 300px;
                min-height: 200px;
                position: absolute;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                cursor: move;
            `;
            
            // 创建弹窗头部
            const popupHeader = document.createElement('div');
            popupHeader.style.cssText = `
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 12px 16px;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                cursor: move;
                user-select: none;
            `;
            
            // 实现弹窗拖拽功能
            let isDragging = false;
            let startX, startY, startLeft, startTop;
            
            // 鼠标按下事件
            popupHeader.onmousedown = function(e) {
                isDragging = true;
                startX = e.clientX;
                startY = e.clientY;
                
                // 获取当前弹窗位置
                const rect = popupContent.getBoundingClientRect();
                startLeft = rect.left;
                startTop = rect.top;
                
                // 添加鼠标移动和释放事件监听
                document.addEventListener('mousemove', drag);
                document.addEventListener('mouseup', stopDrag);
                
                // 防止选中文本
                e.preventDefault();
            };
            
            // 拖拽事件处理函数
            function drag(e) {
                if (!isDragging) return;
                
                // 计算移动距离
                const dx = e.clientX - startX;
                const dy = e.clientY - startY;
                
                // 设置新位置
                popupContent.style.left = (startLeft + dx) + 'px';
                popupContent.style.top = (startTop + dy) + 'px';
                
                // 移除transform，因为我们现在使用left和top定位
                popupContent.style.transform = 'none';
            }
            
            // 停止拖拽事件
            function stopDrag() {
                isDragging = false;
                // 移除事件监听
                document.removeEventListener('mousemove', drag);
                document.removeEventListener('mouseup', stopDrag);
            }
            
            const popupTitle = document.createElement('h3');
            popupTitle.style.cssText = `
                margin: 0;
                font-size: 16px;
                font-weight: 600;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            `;
            popupTitle.textContent = url;
            
            const closeBtn = document.createElement('button');
            closeBtn.style.cssText = `
                background: none;
                border: none;
                color: white;
                font-size: 24px;
                cursor: pointer;
                padding: 0;
                width: 32px;
                height: 32px;
                display: flex;
                align-items: center;
                justify-content: center;
                border-radius: 50%;
                transition: background 0.2s ease;
                cursor: pointer;
            `;
            closeBtn.innerHTML = '×';
            closeBtn.onclick = () => {
                document.body.removeChild(popup);
            };
            
            closeBtn.onmouseover = () => {
                closeBtn.style.background = 'rgba(255, 255, 255, 0.2)';
            };
            
            closeBtn.onmouseout = () => {
                closeBtn.style.background = 'none';
            };
            
            popupHeader.appendChild(popupTitle);
            popupHeader.appendChild(closeBtn);
            
            // 创建iframe（不设置sandbox属性，允许携带cookie）
            const iframe = document.createElement('iframe');
            iframe.src = url;
            iframe.style.cssText = `
                flex: 1;
                border: none;
                width: 100%;
                height: calc(100% - 48px); /* 减去头部高度 */
                min-height: 0;
                cursor: default;
            `;
            
            // 组装弹窗
            popupContent.appendChild(popupHeader);
            popupContent.appendChild(iframe);
            popup.appendChild(popupContent);
            
            // 添加到页面
            document.body.appendChild(popup);
            
            return true;
        }
        
        // 显示反馈模态框
        function showFeedbackModal() {
            document.getElementById('feedback-modal').style.display = 'flex';
        }
        
        // 关闭反馈模态框
        function closeFeedbackModal() {
            document.getElementById('feedback-modal').style.display = 'none';
            // 重置表单
            document.getElementById('feedback-form').reset();
        }
        
        // 处理反馈表单提交
        document.getElementById('feedback-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const formData = new FormData(e.target);
            formData.append('action', 'submit_feedback');
            
            try {
                const response = await fetch('feedback-2.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert('反馈提交成功，感谢您的反馈！');
                    closeFeedbackModal();
                } else {
                    alert(result.message || '提交失败，请稍后重试');
                }
            } catch (error) {
                console.error('提交反馈错误:', error);
                alert('网络错误，请稍后重试');
            }
        });
        
        // 处理链接点击事件
        function handleLinkClick(link) {
            // 检查设置是否开启了使用弹窗显示链接
            const linkPopup = localStorage.getItem('setting-link-popup') === 'false' ? false : true;
            
            if (linkPopup) {
                // 开启了弹窗显示链接，使用iframe弹窗
                createLinkPopup(link);
            } else {
                // 未开启弹窗显示链接，显示安全警告
                showSecurityWarning(link);
            }
        }
        
        // 切换消息操作菜单显示/隐藏
        function toggleMessageActions(button) {
            const actionMenu = button.nextElementSibling;
            // 关闭所有其他打开的菜单
            document.querySelectorAll('.message-action-menu').forEach(menu => {
                if (menu !== actionMenu) {
                    menu.style.display = 'none';
                }
            });
            // 切换当前菜单
            actionMenu.style.display = actionMenu.style.display === 'block' ? 'none' : 'block';
        }
        
        // 点击其他区域关闭消息操作菜单
        document.addEventListener('click', function(event) {
            if (!event.target.closest('.message-actions')) {
                document.querySelectorAll('.message-action-menu').forEach(menu => {
                    menu.style.display = 'none';
                });
            }
        });
        
        // 撤回消息
        function recallMessage(btn, messageId, chatType, chatId) {
            // 如果传入的是临时ID，尝试从DOM元素获取最新的真实ID
            if (messageId.startsWith('temp_') || messageId.startsWith('msg_')) {
                const messageElement = btn.closest('.message');
                if (messageElement && messageElement.dataset.messageId) {
                    // 如果DOM上的ID已经更新（不再是临时ID），则使用DOM上的ID
                    if (messageElement.dataset.messageId !== messageId) {
                        messageId = messageElement.dataset.messageId;
                    }
                }
            }
            
            if (!confirm('确定要撤回这条消息吗？')) {
                return;
            }

            console.log('调用撤回消息函数:', {btn, messageId, chatType, chatId});
            
            // 获取消息元素
            const messageElement = btn.closest('.message');
            if (!messageElement) {
                console.error('未找到消息元素');
                showNotification('未找到消息元素', 'error');
                return;
            }
            
            // 检查时间 (可选，服务器也会检查)
            if (messageElement.dataset.messageTime) {
                const messageTime = parseInt(messageElement.dataset.messageTime);
                const now = Date.now();
                const timeDiff = (now - messageTime) / 1000 / 60; // 转换为分钟
                if (timeDiff > 2) {
                    showNotification('消息已超过2分钟，无法撤回', 'error');
                    return;
                }
            }
            
            // 发送撤回请求到服务器
            const formData = new URLSearchParams();
            formData.append('action', 'recall');
            formData.append('message_id', messageId);
            formData.append('chat_type', chatType);
            if (chatType === 'friend') {
                formData.append('friend_id', chatId);
            } else {
                formData.append('id', chatId);
            }
            
            console.log('发送撤回请求:', formData.toString());
            fetch('recall_message.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                console.log('撤回请求响应:', data);
                if (data.success) {
                    // 撤回成功，更新消息显示为撤回状态
                    // 保留头像部分，只替换内容
                    const avatarHtml = messageElement.querySelector('.message-avatar').outerHTML;
                    messageElement.innerHTML = `
                        <div class='message-content'>
                            <div class='message-text' style='color: #999; font-style: italic;'>[消息已撤回]</div>
                        </div>
                        ${avatarHtml}
                    `;
                    showNotification('消息已撤回', 'success');
                } else {
                    showNotification('消息撤回失败: ' + (data.message || '未知错误'), 'error');
                }
            })
            .catch(error => {
                console.error('撤回消息失败:', error);
                showNotification('网络错误，消息撤回失败', 'error');
            });
        }
        
        // 显示安全警告
        function showSecurityWarning(link) {
            // 创建安全警告弹窗
            const modal = document.createElement('div');
            modal.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.5);
                z-index: 10000;
                display: flex;
                align-items: center;
                justify-content: center;
            `;
            
            const modalContent = document.createElement('div');
            modalContent.style.cssText = `
                background: white;
                padding: 30px;
                border-radius: 12px;
                width: 90%;
                max-width: 500px;
                text-align: center;
                box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            `;
            
            // 标题
            const title = document.createElement('h3');
            title.style.cssText = `
                margin-bottom: 20px;
                color: #ff4757;
                font-size: 18px;
            `;
            title.textContent = '安全警告';
            
            // 内容
            const content = document.createElement('div');
            content.style.cssText = `
                margin-bottom: 25px;
                color: #666;
                font-size: 14px;
                line-height: 1.6;
                text-align: left;
            `;
            
            // 截断过长的链接
            const truncatedLink = truncateLink(link, 50);
            
            content.innerHTML = `
                <p>您访问的链接未知，请仔细辨别后再访问</p>
                <p style="margin-top: 15px; font-weight: 600;">您将要访问：</p>
                <p style="background: #f5f5f5; padding: 10px; border-radius: 6px; word-break: break-all;">${truncatedLink}</p>
            `;
            
            // 按钮容器
            const buttonContainer = document.createElement('div');
            buttonContainer.style.cssText = `
                display: flex;
                gap: 15px;
                justify-content: center;
                margin-top: 25px;
            `;
            
            // 取消按钮
            const cancelBtn = document.createElement('button');
            cancelBtn.style.cssText = `
                padding: 10px 25px;
                background: #f5f5f5;
                color: #333;
                border: 1px solid #ddd;
                border-radius: 6px;
                cursor: pointer;
                font-size: 14px;
                transition: all 0.2s ease;
            `;
            cancelBtn.textContent = '取消';
            cancelBtn.addEventListener('click', () => {
                document.body.removeChild(modal);
            });
            
            // 继续访问按钮
            const continueBtn = document.createElement('button');
            continueBtn.style.cssText = `
                padding: 10px 25px;
                background: #12b7f5;
                color: white;
                border: none;
                border-radius: 6px;
                cursor: pointer;
                font-size: 14px;
                transition: all 0.2s ease;
            `;
            continueBtn.textContent = '继续访问';
            continueBtn.addEventListener('click', () => {
                window.open(link, '_blank');
                document.body.removeChild(modal);
            });
            
            // 组装弹窗
            buttonContainer.appendChild(cancelBtn);
            buttonContainer.appendChild(continueBtn);
            modalContent.appendChild(title);
            modalContent.appendChild(content);
            modalContent.appendChild(buttonContainer);
            modal.appendChild(modalContent);
            
            // 添加到页面
            document.body.appendChild(modal);
        }
        
        // 截断过长的链接
        function truncateLink(link, maxLength) {
            if (link.length <= maxLength) {
                return link;
            }
            const halfLength = Math.floor(maxLength / 2);
            return link.substring(0, halfLength) + '...' + link.substring(link.length - halfLength);
        }
        
        // IndexedDB管理类，用于统一管理所有类型的缓存
        class IndexedDBManager {
            constructor() {
                this.dbName = 'chatfile';
                this.dbVersion = 2;
                this.db = null;
                this.stores = {
                    files: 'files',
                    settings: 'settings',
                    cache: 'cache'
                };
            }
            
            // 打开数据库
            openDB() {
                return new Promise((resolve, reject) => {
                    if (this.db) {
                        resolve(this.db);
                        return;
                    }
                    
                    const request = indexedDB.open(this.dbName, this.dbVersion);
                    
                    request.onerror = (event) => {
                        reject('IndexedDB打开失败: ' + event.target.error.message);
                    };
                    
                    request.onsuccess = (event) => {
                        this.db = event.target.result;
                        resolve(this.db);
                    };
                    
                    request.onupgradeneeded = (event) => {
                        const db = event.target.result;
                        
                        // 创建文件存储对象
                        if (!db.objectStoreNames.contains(this.stores.files)) {
                            const filesStore = db.createObjectStore(this.stores.files, { keyPath: 'id' });
                            filesStore.createIndex('type', 'type', { unique: false });
                            filesStore.createIndex('uploadedAt', 'uploadedAt', { unique: false });
                            filesStore.createIndex('size', 'size', { unique: false });
                        }
                        
                        // 创建设置存储对象
                        if (!db.objectStoreNames.contains(this.stores.settings)) {
                            const settingsStore = db.createObjectStore(this.stores.settings, { keyPath: 'key' });
                        }
                        
                        // 创建通用缓存存储对象
                        if (!db.objectStoreNames.contains(this.stores.cache)) {
                            const cacheStore = db.createObjectStore(this.stores.cache, { keyPath: 'key' });
                            cacheStore.createIndex('type', 'type', { unique: false });
                            cacheStore.createIndex('timestamp', 'timestamp', { unique: false });
                        }
                    };
                });
            }
            
            // 保存文件到IndexedDB
            saveFile(fileData) {
                return new Promise((resolve, reject) => {
                    this.openDB().then(db => {
                        const transaction = db.transaction([this.stores.files], 'readwrite');
                        const objectStore = transaction.objectStore(this.stores.files);
                        
                        const request = objectStore.put(fileData);
                        
                        request.onerror = (event) => {
                            reject('文件保存失败: ' + event.target.error.message);
                        };
                        
                        transaction.oncomplete = () => {
                            resolve(fileData.id);
                        };
                    }).catch(error => {
                        reject(error);
                    });
                });
            }
            
            // 从IndexedDB获取文件
            getFile(fileId) {
                return new Promise((resolve, reject) => {
                    this.openDB().then(db => {
                        const transaction = db.transaction([this.stores.files], 'readonly');
                        const objectStore = transaction.objectStore(this.stores.files);
                        
                        const request = objectStore.get(fileId);
                        
                        request.onerror = (event) => {
                            reject('文件读取失败: ' + event.target.error.message);
                        };
                        
                        request.onsuccess = () => {
                            resolve(request.result);
                        };
                    }).catch(error => {
                        reject(error);
                    });
                });
            }
            
            // 从IndexedDB删除文件
            deleteFile(fileId) {
                return new Promise((resolve, reject) => {
                    this.openDB().then(db => {
                        const transaction = db.transaction([this.stores.files], 'readwrite');
                        const objectStore = transaction.objectStore(this.stores.files);
                        
                        const request = objectStore.delete(fileId);
                        
                        request.onerror = (event) => {
                            reject('文件删除失败: ' + event.target.error.message);
                        };
                        
                        transaction.oncomplete = () => {
                            resolve(true);
                        };
                    }).catch(error => {
                        reject(error);
                    });
                });
            }
            
            // 获取所有文件
            getAllFiles() {
                return new Promise((resolve, reject) => {
                    this.openDB().then(db => {
                        const transaction = db.transaction([this.stores.files], 'readonly');
                        const objectStore = transaction.objectStore(this.stores.files);
                        const files = [];
                        
                        objectStore.openCursor().onsuccess = (event) => {
                            const cursor = event.target.result;
                            if (cursor) {
                                files.push(cursor.value);
                                cursor.continue();
                            } else {
                                resolve(files);
                            }
                        };
                    }).catch(error => {
                        reject(error);
                    });
                });
            }
            
            // 保存设置到IndexedDB
            saveSetting(key, value) {
                return new Promise((resolve, reject) => {
                    this.openDB().then(db => {
                        const transaction = db.transaction([this.stores.settings], 'readwrite');
                        const objectStore = transaction.objectStore(this.stores.settings);
                        
                        const settingData = {
                            key: key,
                            value: value,
                            timestamp: new Date().toISOString()
                        };
                        
                        const request = objectStore.put(settingData);
                        
                        request.onerror = (event) => {
                            reject('设置保存失败: ' + event.target.error.message);
                        };
                        
                        transaction.oncomplete = () => {
                            resolve(true);
                        };
                    }).catch(error => {
                        reject(error);
                    });
                });
            }
            
            // 从IndexedDB获取设置
            getSetting(key, defaultValue = null) {
                return new Promise((resolve, reject) => {
                    this.openDB().then(db => {
                        const transaction = db.transaction([this.stores.settings], 'readonly');
                        const objectStore = transaction.objectStore(this.stores.settings);
                        
                        const request = objectStore.get(key);
                        
                        request.onerror = (event) => {
                            reject('设置读取失败: ' + event.target.error.message);
                        };
                        
                        request.onsuccess = () => {
                            resolve(request.result ? request.result.value : defaultValue);
                        };
                    }).catch(error => {
                        reject(error);
                    });
                });
            }
            
            // 保存通用缓存到IndexedDB
            saveCache(key, value, type = 'general') {
                return new Promise((resolve, reject) => {
                    this.openDB().then(db => {
                        const transaction = db.transaction([this.stores.cache], 'readwrite');
                        const objectStore = transaction.objectStore(this.stores.cache);
                        
                        const cacheData = {
                            key: key,
                            value: value,
                            type: type,
                            timestamp: new Date().toISOString()
                        };
                        
                        const request = objectStore.put(cacheData);
                        
                        request.onerror = (event) => {
                            reject('缓存保存失败: ' + event.target.error.message);
                        };
                        
                        transaction.oncomplete = () => {
                            resolve(true);
                        };
                    }).catch(error => {
                        reject(error);
                    });
                });
            }
            
            // 从IndexedDB获取通用缓存
            getCache(key) {
                return new Promise((resolve, reject) => {
                    this.openDB().then(db => {
                        const transaction = db.transaction([this.stores.cache], 'readonly');
                        const objectStore = transaction.objectStore(this.stores.cache);
                        
                        const request = objectStore.get(key);
                        
                        request.onerror = (event) => {
                            reject('缓存读取失败: ' + event.target.error.message);
                        };
                        
                        request.onsuccess = () => {
                            resolve(request.result ? request.result.value : null);
                        };
                    }).catch(error => {
                        reject(error);
                    });
                });
            }
            
            // 删除通用缓存
            deleteCache(key) {
                return new Promise((resolve, reject) => {
                    this.openDB().then(db => {
                        const transaction = db.transaction([this.stores.cache], 'readwrite');
                        const objectStore = transaction.objectStore(this.stores.cache);
                        
                        const request = objectStore.delete(key);
                        
                        request.onerror = (event) => {
                            reject('缓存删除失败: ' + event.target.error.message);
                        };
                        
                        transaction.oncomplete = () => {
                            resolve(true);
                        };
                    }).catch(error => {
                        reject(error);
                    });
                });
            }
            
            // 迁移数据从localStorage到IndexedDB
            migrateFromLocalStorage() {
                return new Promise((resolve, reject) => {
                    try {
                        // 迁移设置
                        const settingKeys = ['setting-link-popup', 'setting-music-player'];
                        const migrationPromises = [];
                        
                        settingKeys.forEach(key => {
                            const value = localStorage.getItem(key);
                            if (value !== null) {
                                // 转换布尔值
                                let parsedValue = value;
                                if (value === 'true' || value === 'false') {
                                    parsedValue = value === 'true';
                                }
                                migrationPromises.push(this.saveSetting(key, parsedValue));
                            }
                        });
                        
                        // 迁移旧版文件数据
                        const localStorageKeys = Object.keys(localStorage);
                        localStorageKeys.forEach(key => {
                            if (key.startsWith('File_') || key.startsWith('Picture_') || key.startsWith('Video_') || key.startsWith('Audio_')) {
                                const fileData = localStorage.getItem(key);
                                if (fileData) {
                                    try {
                                        const parsedData = JSON.parse(fileData);
                                        if (parsedData.id && parsedData.name && parsedData.data) {
                                            migrationPromises.push(this.saveFile(parsedData));
                                        }
                                    } catch (e) {
                                        // 忽略无效数据
                                    }
                                }
                            }
                        });
                        
                        // 迁移fileIndex
                        const fileIndex = localStorage.getItem('fileIndex');
                        if (fileIndex) {
                            try {
                                const parsedIndex = JSON.parse(fileIndex);
                                migrationPromises.push(this.saveCache('fileIndex', parsedIndex, 'system'));
                            } catch (e) {
                                // 忽略无效数据
                            }
                        }
                        
                        Promise.all(migrationPromises).then(() => {
                            // 迁移完成后清除localStorage中的数据
                            settingKeys.forEach(key => localStorage.removeItem(key));
                            localStorageKeys.forEach(key => {
                                if (key.startsWith('File_') || key.startsWith('Picture_') || key.startsWith('Video_') || key.startsWith('Audio_')) {
                                    localStorage.removeItem(key);
                                }
                            });
                            localStorage.removeItem('fileIndex');
                            resolve(true);
                        }).catch(error => {
                            reject('迁移失败: ' + error.message);
                        });
                    } catch (error) {
                        reject('迁移过程中发生错误: ' + error.message);
                    }
                });
            }
            
            // 获取缓存统计信息
            getCacheStats() {
                return new Promise((resolve, reject) => {
                    this.getAllFiles().then(files => {
                        let totalSize = 0;
                        const stats = {
                            totalFiles: files.length,
                            totalSize: 0,
                            byType: {
                                image: 0,
                                video: 0,
                                audio: 0,
                                file: 0
                            },
                            byTypeSize: {
                                image: 0,
                                video: 0,
                                audio: 0,
                                file: 0
                            }
                        };
                        
                        files.forEach(file => {
                            totalSize += file.size;
                            if (file.type.startsWith('image/')) {
                                stats.byType.image++;
                                stats.byTypeSize.image += file.size;
                            } else if (file.type.startsWith('video/')) {
                                stats.byType.video++;
                                stats.byTypeSize.video += file.size;
                            } else if (file.type.startsWith('audio/')) {
                                stats.byType.audio++;
                                stats.byTypeSize.audio += file.size;
                            } else {
                                stats.byType.file++;
                                stats.byTypeSize.file += file.size;
                            }
                        });
                        
                        stats.totalSize = totalSize;
                        resolve(stats);
                    }).catch(error => {
                        reject('获取缓存统计失败: ' + error.message);
                    });
                });
            }
            
            // 清除所有缓存
            clearAllCache() {
                return new Promise((resolve, reject) => {
                    this.openDB().then(db => {
                        const storesToClear = [this.stores.files, this.stores.cache];
                        const clearPromises = [];
                        
                        storesToClear.forEach(storeName => {
                            const transaction = db.transaction([storeName], 'readwrite');
                            const objectStore = transaction.objectStore(storeName);
                            const request = objectStore.clear();
                            
                            clearPromises.push(new Promise((res, rej) => {
                                request.onerror = (event) => {
                                    rej('清除' + storeName + '失败: ' + event.target.error.message);
                                };
                                
                                transaction.oncomplete = () => {
                                    res(true);
                                };
                            }));
                        });
                        
                        Promise.all(clearPromises).then(() => {
                            resolve(true);
                        }).catch(error => {
                            reject('清除缓存失败: ' + error.message);
                        });
                    }).catch(error => {
                        reject('清除缓存过程中发生错误: ' + error.message);
                    });
                });
            }
        }
        
        // 初始化IndexedDB管理器实例
        const indexedDBManager = new IndexedDBManager();
        
        // 页面加载时执行数据迁移
        window.addEventListener('load', () => {
            indexedDBManager.migrateFromLocalStorage().catch(error => {
                console.log('localStorage迁移失败:', error);
            });
        });
        
        // localStorage空间管理函数
        function getLocalStorageAvailableSpace() {
            // 尝试存储不同大小的数据，找出可用空间
            try {
                const testKey = '__test_storage_space__';
                let testData = '';
                const chunkSize = 1024 * 1024; // 1MB chunks
                let maxChunks = 100;
                let chunksWritten = 0;
                
                // 先删除可能存在的测试数据
                localStorage.removeItem(testKey);
                
                // 逐步增加数据大小，直到失败
                while (chunksWritten < maxChunks) {
                    try {
                        testData += 'x'.repeat(chunkSize);
                        localStorage.setItem(testKey, testData);
                        chunksWritten++;
                    } catch (e) {
                        break;
                    }
                }
                
                // 计算可用空间（MB）
                const availableSpace = chunksWritten * chunkSize;
                
                // 清理测试数据
                localStorage.removeItem(testKey);
                
                return availableSpace;
            } catch (error) {
                // 保守估计，返回1MB
                return 1024 * 1024;
            }
        }
        
        // 清理旧文件以释放空间
        function cleanupOldFiles(requiredSpace) {
            try {
                // 获取文件索引
                const fileIndex = JSON.parse(localStorage.getItem('fileIndex') || '[]');
                if (fileIndex.length === 0) {
                    return false;
                }
                
                // 获取所有文件的详细信息并按上传时间排序
                const files = [];
                for (const fileId of fileIndex) {
                    const fileData = localStorage.getItem(fileId);
                    if (fileData) {
                        try {
                            const parsedData = JSON.parse(fileData);
                            files.push({
                                id: fileId,
                                size: parsedData.size,
                                uploadedAt: new Date(parsedData.uploadedAt)
                            });
                        } catch (e) {
                            // 忽略无效文件
                        }
                    }
                }
                
                // 按上传时间排序，最旧的文件排在前面
                files.sort((a, b) => a.uploadedAt - b.uploadedAt);
                
                // 计算当前已用空间
                let currentSize = 0;
                for (const file of files) {
                    currentSize += file.size;
                }
                
                // 计算需要清理的空间
                let cleanedSize = 0;
                const filesToRemove = [];
                
                // 从最旧的文件开始清理，直到有足够空间
                for (const file of files) {
                    if (cleanedSize >= requiredSpace) {
                        break;
                    }
                    
                    filesToRemove.push(file.id);
                    cleanedSize += file.size;
                }
                
                // 执行清理
                for (const fileId of filesToRemove) {
                    localStorage.removeItem(fileId);
                    // 从索引中移除
                    const index = fileIndex.indexOf(fileId);
                    if (index > -1) {
                        fileIndex.splice(index, 1);
                    }
                }
                
                // 更新索引
                localStorage.setItem('fileIndex', JSON.stringify(fileIndex));
                
                return true;
            } catch (error) {
                return false;
            }
        }
        
        // 发送文件函数
        // localStorage文件存储管理
        function saveFileToCache(file) {
            return new Promise((resolve, reject) => {
                const reader = new FileReader();
                reader.onload = (e) => {
                    try {
                        // 根据文件类型设置不同的前缀
                        let prefix = 'File_';
                        if (file.type.startsWith('image/')) {
                            prefix = 'Picture_';
                        } else if (file.type.startsWith('video/')) {
                            prefix = 'Video_';
                        } else if (file.type.startsWith('audio/')) {
                            prefix = 'Audio_';
                        }
                        
                        // 生成唯一文件ID，格式为：前缀 + 原始文件名 + 时间戳 + 随机字符串
                        const timestamp = Date.now();
                        const randomStr = Math.random().toString(36).substring(2, 11);
                        const fileId = prefix + file.name + '_' + timestamp + '_' + randomStr;
                        
                        // 准备文件数据
                        const fileData = {
                            id: fileId,
                            name: file.name,
                            size: file.size,
                            type: file.type,
                            data: e.target.result,
                            uploadedAt: new Date().toISOString()
                        };
                        
                        // 将文件数据存储到IndexedDB
                        indexedDBManager.saveFile(fileData)
                            .then(() => {
                                resolve(fileId);
                            })
                            .catch(error => {
                                reject('文件存储失败：' + error.message);
                            });
                    } catch (error) {
                        reject('文件存储失败：' + error.message);
                    }
                };
                reader.onerror = () => {
                    reject('文件读取失败');
                };
                reader.readAsDataURL(file);
            });
        }
        
        function getFileFromCache(fileId) {
            return new Promise((resolve, reject) => {
                indexedDBManager.getFile(fileId)
                    .then(fileData => {
                        resolve(fileData);
                    })
                    .catch(error => {
                        console.error('从IndexedDB获取文件失败：', error);
                        resolve(null);
                    });
            });
        }
        
        // 优化视频：降低分辨率、码率、帧率
        async function optimizeVideo(videoFile) {
            return new Promise((resolve, reject) => {
                const video = document.createElement('video');
                video.src = URL.createObjectURL(videoFile);
                video.preload = 'metadata';
                
                video.onloadedmetadata = async () => {
                    // 设置优化参数
                    const maxWidth = 854; // 降低分辨率到854px宽
                    const maxHeight = 480; // 降低分辨率到480px高
                    const frameRate = 15; // 降低帧率到15fps
                    const bitrate = 500000; // 降低码率到500kbps
                    
                    // 计算缩放比例
                    let width = video.videoWidth;
                    let height = video.videoHeight;
                    if (width > maxWidth || height > maxHeight) {
                        const aspectRatio = width / height;
                        if (aspectRatio > 1) {
                            width = maxWidth;
                            height = Math.round(maxWidth / aspectRatio);
                        } else {
                            height = maxHeight;
                            width = Math.round(maxHeight * aspectRatio);
                        }
                    }
                    
                    // 创建canvas用于视频帧绘制
                    const canvas = document.createElement('canvas');
                    canvas.width = width;
                    canvas.height = height;
                    const ctx = canvas.getContext('2d');
                    
                    // 创建MediaRecorder
                    const stream = canvas.captureStream(frameRate);
                    const recorder = new MediaRecorder(stream, {
                        mimeType: 'video/webm;codecs=vp9',
                        videoBitsPerSecond: bitrate
                    });
                    
                    const chunks = [];
                    recorder.ondataavailable = (e) => {
                        if (e.data.size > 0) {
                            chunks.push(e.data);
                        }
                    };
                    
                    recorder.onstop = () => {
                        const optimizedBlob = new Blob(chunks, { type: 'video/webm' });
                        const optimizedFile = new File([optimizedBlob], videoFile.name.replace(/\.[^/.]+$/, '.webm'), {
                            type: 'video/webm',
                            lastModified: Date.now()
                        });
                        URL.revokeObjectURL(video.src);
                        resolve(optimizedFile);
                    };
                    
                    recorder.onerror = (e) => {
                        URL.revokeObjectURL(video.src);
                        reject(new Error('视频优化失败: ' + e.error));
                    };
                    
                    // 开始录制
                    recorder.start();
                    
                    // 播放视频并绘制每一帧
                    video.play().catch(error => {
                        URL.revokeObjectURL(video.src);
                        reject(new Error('视频播放失败: ' + error.message));
                    });
                    
                    const drawFrame = () => {
                        if (!recorder.state || recorder.state === 'inactive') {
                            return;
                        }
                        
                        ctx.drawImage(video, 0, 0, width, height);
                        requestAnimationFrame(drawFrame);
                    };
                    
                    video.addEventListener('ended', () => {
                        recorder.stop();
                    });
                    
                    drawFrame();
                };
                
                video.onerror = (e) => {
                    URL.revokeObjectURL(video.src);
                    reject(new Error('视频加载失败: ' + e.error));
                };
            });
        }
        
        // 优化图片：降低分辨率、减少颜色信息、选择更优格式
        async function optimizeImage(imageFile) {
            return new Promise((resolve, reject) => {
                const img = new Image();
                img.src = URL.createObjectURL(imageFile);
                
                img.onload = () => {
                    // 设置优化参数
                    const maxWidth = 1280;
                    const maxHeight = 720;
                    const quality = 0.7;
                    
                    // 计算缩放比例
                    let width = img.width;
                    let height = img.height;
                    if (width > maxWidth || height > maxHeight) {
                        const aspectRatio = width / height;
                        if (aspectRatio > 1) {
                            width = maxWidth;
                            height = Math.round(maxWidth / aspectRatio);
                        } else {
                            height = maxHeight;
                            width = Math.round(maxHeight * aspectRatio);
                        }
                    }
                    
                    // 创建canvas用于图片绘制
                    const canvas = document.createElement('canvas');
                    canvas.width = width;
                    canvas.height = height;
                    const ctx = canvas.getContext('2d');
                    
                    // 绘制图片
                    ctx.drawImage(img, 0, 0, width, height);
                    
                    // 选择最优格式
                    const format = imageFile.type === 'image/png' && width < 500 ? 'png' : 'jpeg';
                    
                    // 转换为Blob
                    canvas.toBlob((blob) => {
                        const optimizedFile = new File([blob], imageFile.name.replace(/\.[^/.]+$/, `.${format}`), {
                            type: `image/${format}`,
                            lastModified: Date.now()
                        });
                        URL.revokeObjectURL(img.src);
                        resolve(optimizedFile);
                    }, `image/${format}`, quality);
                };
                
                img.onerror = (e) => {
                    URL.revokeObjectURL(img.src);
                    reject(new Error('图片加载失败: ' + e.error));
                };
            });
        }
        
        function sendFile(file, blobUrl = null) {
            const chatType = '<?php echo $chat_type; ?>';
            const chatId = '<?php echo $selected_id; ?>';
            
            if (!chatId) {
                showNotification('请先选择聊天对象', 'error');
                return;
            }
            
            // 检查文件大小是否超过限制
            const uploadMaxConfig = <?php echo getConfig('upload_files_max', 150); ?>; // 默认150MB
            const maxFileSize = uploadMaxConfig * 1024 * 1024; // 转换为字节
            if (file.size > maxFileSize) {
                const maxSizeMB = uploadMaxConfig.toFixed(1);
                const fileSizeMB = (file.size / (1024 * 1024)).toFixed(1);
                showNotification(`文件太大，无法上传。文件大小：${fileSizeMB}MB，最大允许：${maxSizeMB}MB`, 'error');
                return;
            }
            
            // 检查IndexedDB是否支持
            if (!window.indexedDB) {
                showNotification('您的浏览器不支持IndexedDB，无法上传文件', 'error');
                return;
            }
            
            // 创建文件上传中的提示消息
            const messagesContainer = document.getElementById('messages-container');
            const uploadingMessage = document.createElement('div');
            uploadingMessage.className = 'message sent';
            
            // 格式化时间为 X年X月X日X时X分
            const date = new Date();
            const formattedTime = `${date.getFullYear()}年${(date.getMonth() + 1).toString().padStart(2, '0')}月${date.getDate().toString().padStart(2, '0')}日${date.getHours().toString().padStart(2, '0')}时${date.getMinutes().toString().padStart(2, '0')}分`;
            const timeHtml = `<div class='message-time'>${formattedTime}</div>`;
            
            // 创建带进度条的上传消息
            uploadingMessage.innerHTML = `
                <div class='message-content'>
                    <div class='message-text'>
                        <div style='margin-bottom: 8px;'><strong>${file.name}</strong></div>
                        <div style='margin-bottom: 8px;'>文件大小：${(file.size / (1024 * 1024)).toFixed(2)} MB</div>
                        <div style='margin-bottom: 5px;'>上传中：</div>
                        <div style='width: 100%; height: 8px; background: #e0e0e0; border-radius: 4px; overflow: hidden; margin-bottom: 5px;'>
                            <div id='upload-progress-bar' style='width: 0%; height: 100%; background: linear-gradient(90deg, #667eea 0%, #764ba2 100%); transition: width 0.3s ease; border-radius: 4px;'></div>
                        </div>
                        <div style='display: flex; justify-content: space-between; font-size: 12px; color: #666;'>
                            <span id='upload-percentage'>0%</span>
                            <span id='upload-speed'>0 KB/s</span>
                        </div>
                    </div>
                    ${timeHtml}
                </div>
                <div class='message-avatar'>
                    <?php if (!empty($current_user['avatar'])): ?>
                        <img src='<?php echo $current_user['avatar']; ?>' alt='<?php echo $username; ?>' style='width: 100%; height: 100%; border-radius: 50%; object-fit: cover;'>
                    <?php else: ?>
                        <?php echo substr($username, 0, 2); ?>
                    <?php endif; ?>
                </div>
            `;
            messagesContainer.appendChild(uploadingMessage);
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
            
            // 更新进度显示
            const progressBar = uploadingMessage.querySelector('#upload-progress-bar');
            const percentageText = uploadingMessage.querySelector('#upload-percentage');
            const speedText = uploadingMessage.querySelector('#upload-speed');
            
            // 优化文件
            (async () => {
                let optimizedFile = file;
                
                try {
                    // 检查文件类型并优化
                    if (file.type.startsWith('video/')) {
                        // 优化视频
                        optimizedFile = await optimizeVideo(file);
                    } else if (file.type.startsWith('image/')) {
                        // 优化图片
                        optimizedFile = await optimizeImage(file);
                    }
                    
                    // 模拟进度更新
                    let progress = 0;
                    const progressInterval = setInterval(() => {
                        progress += 10;
                        if (progress > 90) progress = 90;
                        progressBar.style.width = `${progress}%`;
                        percentageText.textContent = `${progress}%`;
                    }, 100);
                    
                    // 保存文件到IndexedDB
                    saveFileToCache(optimizedFile)
                        .then(async (fileId) => {
                            clearInterval(progressInterval);
                            progressBar.style.width = '100%';
                            percentageText.textContent = '100%';
                            
                            // 准备消息数据
                            // 清理文件名：如果包含 IndexedDB ID 前缀，则去除
                            let displayFileName = optimizedFile.name;
                            if (displayFileName.startsWith('Audio_') || displayFileName.startsWith('Video_') || displayFileName.startsWith('Picture_') || displayFileName.startsWith('File_')) {
                                // 尝试从ID中提取原始文件名，或者生成一个更友好的名称
                                // 简单策略：如果是 Audio_ 开头，显示为 "语音消息.webm"
                                if (displayFileName.startsWith('Audio_')) {
                                    displayFileName = '语音消息.webm';
                                } else {
                                    // 移除前缀和可能的时间戳ID
                                    displayFileName = displayFileName.replace(/^(Audio|Video|Picture|File)_.*?_/, '');
                                }
                            }

                            const messageData = {
                                id: 'msg_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9),
                                sender_id: '<?php echo $user_id; ?>',
                                content: '',
                                file_path: fileId, // 使用localStorage文件ID作为路径
                                file_name: displayFileName, // 使用清理后的友好文件名
                                file_size: optimizedFile.size,
                                file_type: optimizedFile.type,
                                blob_url: blobUrl, // 传入Blob URL用于立即播放
                                type: 'file',
                                created_at: new Date().toISOString(),
                                status: 'sent',
                                sender_name: '<?php echo $username; ?>',
                                sender_avatar: '<?php echo !empty($current_user['avatar']) ? $current_user['avatar'] : ''; ?>'
                            };
                            
                            // 创建消息元素
                            const messageElement = createMessageElement(messageData, chatType, chatId);
                            // 移除上传中的提示消息（先检查是否存在）
                            if (uploadingMessage.parentElement === messagesContainer) {
                                messagesContainer.removeChild(uploadingMessage);
                            }
                            messagesContainer.appendChild(messageElement);
                            messagesContainer.scrollTop = messagesContainer.scrollHeight;
                            
                            // 初始化新添加的音频播放器
                            initAudioPlayers();
                            
                            // 初始化新添加的聊天视频，转换为Blob URL
                            initChatVideos();
                            
                            // 发送消息到服务器（同时发送文件内容和元数据）
                            try {
                                const formData = new FormData();
                                formData.append('chat_type', chatType);
                                formData.append('content', '');
                                formData.append('file_path', fileId);
                                formData.append('file_name', optimizedFile.name);
                                formData.append('file_size', optimizedFile.size);
                                formData.append('file_type', optimizedFile.type);
                                // 同时上传实际文件内容
                                formData.append('file', optimizedFile);
                                if (chatType === 'friend') {
                                    formData.append('friend_id', chatId);
                                } else {
                                    formData.append('id', chatId);
                                }
                                
                                // 使用XMLHttpRequest替代fetch来获取上传进度
                                const xhr = new XMLHttpRequest();
                                
                                // 显示服务器上传的真实进度
                                xhr.upload.onprogress = function(e) {
                                    if (e.lengthComputable) {
                                        // 计算上传进度（50%本地存储 + 50%服务器上传）
                                        const uploadProgress = 50 + (e.loaded / e.total) * 50;
                                        progressBar.style.width = `${uploadProgress}%`;
                                        percentageText.textContent = `${Math.round(uploadProgress)}%`;
                                        
                                        // 计算上传速度
                                        const now = Date.now();
                                        const elapsed = (now - startTime) / 1000;
                                        const speed = e.loaded / elapsed;
                                        speedText.textContent = `${(speed / 1024).toFixed(0)} KB/s`;
                                    }
                                };
                                
                                // 记录开始时间用于计算速度
                                const startTime = Date.now();
                                
                                // 发送请求
                                xhr.open('POST', 'send_message.php', true);
                                xhr.withCredentials = true;
                                
                                xhr.onload = function() {
                                    if (xhr.status === 200) {
                                        // 请求成功
                                        try {
                                            const data = JSON.parse(xhr.responseText);
                                            if (!data.success) {
                                                showNotification('消息发送失败', 'error');
                                            } else if (data.message && data.message.file_path) {
                                                // 更新DOM中的音频源为服务器路径，防止本地缓存失效导致404
                                                const uploadedMessageId = data.message_id; // 服务器返回的消息ID
                                                
                                                // 关键修复：更新消息元素的ID为服务器返回的真实ID
                                                // 这样getNewMessages轮询时就不会重复添加这条消息了
                                                if (uploadedMessageId) {
                                                    messageElement.dataset.messageId = uploadedMessageId;
                                                }
                                                
                                                // 查找对应的消息元素 (注意：这里我们可能找不到它，因为我们只有temp ID或没有ID)
                                                // 更好的方式是使用我们刚刚创建的 messageElement 引用
                                                
                                                // 更新音频元素
                                                const audioElements = messageElement.querySelectorAll('.audio-element');
                                                audioElements.forEach(audio => {
                                                    // 只有当src是本地ID时才更新，或者强制更新
                                                    // 将 file_path 属性更新为服务器路径
                                                    audio.setAttribute('data-file-path', data.message.file_path);
                                                    
                                                    // 如果当前src是ID格式（不是blob或http），则更新src
                                                    // 但实际上，我们希望它在下次加载时使用服务器路径。
                                                    // 如果当前正在播放，不要打断。
                                                    // 重点是：如果本地缓存丢失，我们需要一种机制回退。
                                                    
                                                    // 我们可以将服务器URL存储在另一个属性中，或者如果当前src加载失败，就使用这个
                                                    // 但目前最简单的是：如果它是ID，就替换为服务器URL？
                                                    // 不，ID用于IndexedDB。
                                                    
                                                    // 让我们直接更新 data-file-path，这样下次加载页面或重新渲染时会使用正确的路径
                                                    // 并且，如果当前src失效（404），audio元素的 error 事件监听器应该处理它
                                                });
                                                
                                                // 还要更新 message-file 链接
                                                const fileLinks = messageElement.querySelectorAll('.message-file, .message-image, .video-element');
                                                fileLinks.forEach(el => {
                                                    el.setAttribute('data-file-path', data.message.file_path);
                                                });
                                                
                                                // 强制将文件路径保存到 localStorage 映射（如果需要）
                                                // 但最重要的是更新 HTML 中的 data-file-path
                                            }
                                        } catch (e) {
                                            console.error('解析响应失败', e);
                                        }
                                    } else {
                                        // 请求失败
                                        console.error('消息发送到服务器失败:', xhr.statusText);
                                    }
                                };
                                
                                xhr.onerror = function() {
                                    console.error('消息发送到服务器失败: 网络错误');
                                };
                                
                                xhr.send(formData);
                            } catch (error) {
                                console.error('消息发送到服务器失败:', error);
                                // 本地消息已显示，仅记录错误
                            }
                            
                            // 重置文件输入
                            const fileInput = document.getElementById('file-input');
                            if (fileInput) {
                                fileInput.value = '';
                            }
                        })
                        .catch((error) => {
                            clearInterval(progressInterval);
                            // 移除上传中的提示消息（先检查是否存在）
                            if (uploadingMessage.parentElement === messagesContainer) {
                                messagesContainer.removeChild(uploadingMessage);
                            }
                            
                            const errorMessage = document.createElement('div');
                            errorMessage.className = 'message sent';
                            errorMessage.innerHTML = `
                                <div class='message-content'>
                                    <div class='message-text' style='color: #ff4d4f;'>文件存储失败：${error}</div>
                                    ${timeHtml}
                                </div>
                                <div class='message-avatar'>
                                    <?php if (!empty($current_user['avatar'])): ?>
                                        <img src='<?php echo $current_user['avatar']; ?>' alt='<?php echo $username; ?>' style='width: 100%; height: 100%; border-radius: 50%; object-fit: cover;'>
                                    <?php else: ?>
                                        <?php echo substr($username, 0, 2); ?>
                                    <?php endif; ?>
                                </div>
                            `;
                            messagesContainer.appendChild(errorMessage);
                            messagesContainer.scrollTop = messagesContainer.scrollHeight;
                            
                            // 重置文件输入
                            const fileInput = document.getElementById('file-input');
                            if (fileInput) {
                                fileInput.value = '';
                            }
                        });
                } catch (optimizeError) {
                    console.error('文件优化失败:', optimizeError);
                    // 移除上传中的提示消息（先检查是否存在）
                    if (uploadingMessage.parentElement === messagesContainer) {
                        messagesContainer.removeChild(uploadingMessage);
                    }
                    
                    const errorMessage = document.createElement('div');
                    errorMessage.className = 'message sent';
                    errorMessage.innerHTML = `
                        <div class='message-content'>
                            <div class='message-text' style='color: #ff4d4f;'>文件优化失败：${optimizeError.message}</div>
                            ${timeHtml}
                        </div>
                        <div class='message-avatar'>
                            <?php if (!empty($current_user['avatar'])): ?>
                                <img src='<?php echo $current_user['avatar']; ?>' alt='<?php echo $username; ?>' style='width: 100%; height: 100%; border-radius: 50%; object-fit: cover;'>
                            <?php else: ?>
                                <?php echo substr($username, 0, 2); ?>
                            <?php endif; ?>
                        </div>
                    `;
                    messagesContainer.appendChild(errorMessage);
                    messagesContainer.scrollTop = messagesContainer.scrollHeight;
                    
                    // 重置文件输入
                    const fileInput = document.getElementById('file-input');
                    if (fileInput) {
                        fileInput.value = '';
                    }
                }
            })();
        }
        
        function sendMessage() {
            const input = document.getElementById('message-input');
            let message = input.value.trim();
            
            // 严格检查消息是否包含HTML标签、HTML实体或脚本
            function containsHtmlContent(text) {
                // 简单检测HTML标签（避免复杂正则表达式导致的解析问题）
                const hasHtmlTags = text.includes('<') && text.includes('>');
                // 检测HTML实体
                const hasHtmlEntities = text.includes('&');
                // 检测脚本相关内容
                const hasScriptContent = text.includes('<script') || text.includes('javascript:') || text.includes('vbscript:');
                // 检测常见的XSS攻击向量
                const hasXssVectors = text.match(/on[a-zA-Z]+\s*=|expression\(|eval\(|alert\(/i);
                
                return hasHtmlTags || hasHtmlEntities || hasScriptContent || hasXssVectors;
            }
            
            if (message) {
                // 前端严格HTML内容校验
                if (containsHtmlContent(message)) {
                    showNotification('禁止发送HTML代码、脚本或特殊字符 ❌', 'error');
                    return;
                }
                
                // 额外安全措施：移除所有可能的HTML标签（双重保险）
                message = message.replace(/<[^>]*>/g, '');
                message = message.replace(/&[a-zA-Z0-9#]+;/g, '');
                message = message.trim();
                
                // 如果移除HTML标签后消息为空，不发送
                if (!message) {
                    showNotification('消息内容不能为空 ❌', 'error');
                    return;
                }
                
                const chatType = '<?php echo $chat_type; ?>';
                const chatId = '<?php echo $selected_id; ?>';
                
                if (!chatId) {
                    showNotification('请先选择聊天对象', 'error');
                    return;
                }
                
                // 检测消息是否包含链接
                const messageWithLinks = message.replace(/(https?:\/\/[^\s]+)/g, function(link) {
                    return `<a href="#" onclick="event.preventDefault(); handleLinkClick('${link}')" style="color: #12b7f5; text-decoration: underline;">${link}</a>`;
                });
                
                // 创建临时消息ID
                const tempMessageId = 'temp_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
                
                // 格式化时间为 X年X月X日X时X分
                const date = new Date();
                const formattedTime = `${date.getFullYear()}年${(date.getMonth() + 1).toString().padStart(2, '0')}月${date.getDate().toString().padStart(2, '0')}日${date.getHours().toString().padStart(2, '0')}时${date.getMinutes().toString().padStart(2, '0')}分`;
                
                // 创建消息元素
                const messagesContainer = document.getElementById('messages-container');
                const messageElement = document.createElement('div');
                messageElement.className = 'message sent';
                messageElement.dataset.messageId = tempMessageId;
                messageElement.dataset.chatType = chatType;
                messageElement.dataset.chatId = chatId;
                // 保存消息发送时间，用于撤回功能
                const messageTime = Date.now();
                messageElement.dataset.messageTime = messageTime;
                messageElement.innerHTML = `
                    <div class='message-content'>
                        <div class='message-text'>${messageWithLinks}</div>
                        <div class='message-time'>${formattedTime}</div>
                        <div class='message-actions'>
                            <div style='position: relative; z-index: 4000;'>
                                <button class='message-action-btn' onclick='toggleMessageActions(this)' style='width: 28px; height: 28px; font-size: 18px; background: rgba(0,0,0,0.1); border: none; border-radius: 50%; color: #333; cursor: pointer; display: flex; align-items: center; justify-content: center; opacity: 1; transition: all 0.2s ease;'>⋮</button>
                                <div class='message-actions-menu' style='display: none; position: absolute; top: 100%; right: 0; background: white; border-radius: 8px; box-shadow: 0 2px 12px rgba(0,0,0,0.15); padding: 8px 0; z-index: 5000; min-width: 100px;'>
                                    <button class='message-action-item' onclick='recallMessage(this, "${tempMessageId}", "${chatType}", "${chatId}")' style='width: 100%; text-align: left; padding: 8px 16px; border: none; background: transparent; cursor: pointer; transition: all 0.2s ease; color: #333;'>撤回消息</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class='message-avatar'>
                        <?php if (!empty($current_user['avatar'])): ?>
                            <img src='<?php echo $current_user['avatar']; ?>' alt='<?php echo $username; ?>' style='width: 100%; height: 100%; border-radius: 50%; object-fit: cover;'>
                        <?php else: ?>
                            <?php echo substr($username, 0, 2); ?>
                        <?php endif; ?>
                    </div>
                `;
                messagesContainer.appendChild(messageElement);
                messagesContainer.scrollTop = messagesContainer.scrollHeight;
                
                // 发送消息到服务器
                const formData = new URLSearchParams();
                formData.append('message', message);
                formData.append('chat_type', chatType);
                if (chatType === 'friend') {
                    formData.append('friend_id', chatId);
                } else {
                    formData.append('id', chatId);
                }
                
                fetch('send_message.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.message_id) {
                        // 消息发送成功，更新临时消息的ID为真实ID
                        messageElement.dataset.messageId = data.message_id;
                    } else if (!data.success) {
                        // 发送失败，移除临时消息
                        messagesContainer.removeChild(messageElement);
                        // 显示错误消息
                        showNotification('发送消息失败：' + (data.message || '未知错误'), 'error');
                    }
                })
                .catch(error => {
                    console.error('发送消息失败:', error);
                    // 发送失败，移除临时消息
                    messagesContainer.removeChild(messageElement);
                    // 显示错误消息
                    showNotification('发送消息失败，请检查网络连接', 'error');
                });
                
                input.value = '';
            }
        }
        
        // 好友申请相关函数
        function showFriendRequests() {
            document.getElementById('friend-requests-modal').style.display = 'flex';
        }
        
        function closeFriendRequestsModal() {
            document.getElementById('friend-requests-modal').style.display = 'none';
        }
        

        
        // 初始化音频播放器
        function initAudioPlayers() {
            document.querySelectorAll('.custom-audio-player').forEach(player => {
                // 检查是否已经初始化
                if (player.classList.contains('initialized')) {
                    return;
                }
                
                // 标记为已初始化
                player.classList.add('initialized');
                
                const audio = player.querySelector('.audio-element');
                const playBtn = player.querySelector('.audio-play-btn');
                const progressBar = player.querySelector('.audio-progress-bar');
                const progress = player.querySelector('.audio-progress');
                const currentTimeEl = player.querySelector('.current-time');
                const durationEl = player.querySelector('.audio-duration');
                
                // 检查必要元素是否存在
                if (!audio || !playBtn || !progressBar || !progress || !currentTimeEl || !durationEl) {
                    return;
                }
                
                // 设置音频时长
                audio.addEventListener('loadedmetadata', function() {
                    durationEl.textContent = formatTime(audio.duration);
                });
                
                // 播放/暂停控制
                playBtn.addEventListener('click', function() {
                    if (audio.paused) {
                        audio.play();
                        playBtn.classList.add('playing');
                    } else {
                        audio.pause();
                        playBtn.classList.remove('playing');
                    }
                });
                
                // 更新进度条和时间
                audio.addEventListener('timeupdate', function() {
                    // 确保duration有效才计算进度
                    if (!isFinite(audio.duration) || isNaN(audio.duration) || audio.duration <= 0) {
                        return;
                    }
                    const progressPercent = (audio.currentTime / audio.duration) * 100;
                    progress.style.width = progressPercent + '%';
                    currentTimeEl.textContent = formatTime(audio.currentTime);
                });
                
                // 音频结束时重置
                audio.addEventListener('ended', function() {
                    playBtn.classList.remove('playing');
                    progress.style.width = '0%';
                    currentTimeEl.textContent = '0:00';
                });
                
                // 进度条点击跳转到指定位置
                progressBar.addEventListener('click', function(e) {
                    // 确保duration有效才允许跳转
                    if (!isFinite(audio.duration) || isNaN(audio.duration) || audio.duration <= 0) {
                        return;
                    }
                    const progressWidth = progressBar.clientWidth;
                    // 使用clientX和getBoundingClientRect获取准确的点击位置
                    const rect = progressBar.getBoundingClientRect();
                    const clickX = e.clientX - rect.left;
                    const duration = audio.duration;
                    
                    audio.currentTime = (clickX / progressWidth) * duration;
                });
                
                // 进度条拖动功能
                let isDragging = false;
                
                const handleDragStart = (e) => {
                    isDragging = true;
                    updateProgressFromEvent(e);
                };
                
                const handleDragMove = (e) => {
                    if (!isDragging) return;
                    e.preventDefault(); // 防止触摸滚动
                    updateProgressFromEvent(e);
                };
                
                const handleDragEnd = () => {
                    isDragging = false;
                };
                
                const updateProgressFromEvent = (e) => {
                    if (!isFinite(audio.duration) || isNaN(audio.duration) || audio.duration <= 0) return;
                    
                    const progressWidth = progressBar.clientWidth;
                    const rect = progressBar.getBoundingClientRect();
                    
                    // 获取X坐标 (兼容鼠标和触摸)
                    let clientX;
                    if (e.type.startsWith('touch')) {
                        clientX = e.touches[0].clientX;
                    } else {
                        clientX = e.clientX;
                    }
                    
                    let clickX = clientX - rect.left;
                    
                    // 限制范围
                    clickX = Math.max(0, Math.min(clickX, progressWidth));
                    
                    const duration = audio.duration;
                    const newTime = (clickX / progressWidth) * duration;
                    
                    audio.currentTime = newTime;
                    
                    // 实时更新UI
                    const progressPercent = (newTime / duration) * 100;
                    progress.style.width = progressPercent + '%';
                    currentTimeEl.textContent = formatTime(newTime);
                };
                
                // 鼠标事件
                progressBar.addEventListener('mousedown', handleDragStart);
                document.addEventListener('mousemove', handleDragMove);
                document.addEventListener('mouseup', handleDragEnd);
                
                // 触摸事件
                progressBar.addEventListener('touchstart', handleDragStart, { passive: false });
                document.addEventListener('touchmove', handleDragMove, { passive: false });
                document.addEventListener('touchend', handleDragEnd);
                
                // 鼠标离开窗口
                document.addEventListener('mouseleave', handleDragEnd);
            });
        }

        // 初始化
        document.addEventListener('DOMContentLoaded', async function() {
            // 加载聊天记录
            loadChatHistory();
            
            // 初始化聊天视频，转换为Blob URL
            initChatVideos();
            
            // 初始化所有媒体
            await initChatMedia();
            
            // 如果是群聊，检查是否被封禁
            <?php if ($chat_type === 'group' && $selected_id): ?>
                checkGroupBanStatus(<?php echo $selected_id; ?>);
            <?php endif; ?>
            
            // 为搜索按钮添加点击事件
            const searchButton = document.getElementById('search-user-button');
            if (searchButton) {
                searchButton.addEventListener('click', searchUser);
            }
            
            // 为搜索输入框添加回车键事件
            const searchInput = document.getElementById('search-user-input');
            if (searchInput) {
                searchInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        searchUser();
                    }
                });
            }
        });
        
        // 文件类型检测函数
        function getFileType(fileName) {
            const ext = fileName.toLowerCase().split('.').pop();
            const imageExts = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
            const videoExts = ['mp4', 'avi', 'mov', 'wmv', 'flv', 'webm'];
            const audioExts = ['mp3', 'wav', 'ogg', 'aac', 'wma', 'm4a'];
            
            if (imageExts.includes(ext)) {
                return 'image';
            } else if (videoExts.includes(ext)) {
                return 'video';
            } else if (audioExts.includes(ext)) {
                return 'audio';
            } else {
                return 'file';
            }
        }
        
        // 文件请求重试计数器
        const fileRetryCounter = {};
        const MAX_RETRIES = 5;

        // 缓存控制标志 - 确保同一时间只有一个缓存进程在运行
        let isCaching = false;







        // 格式化文件大小
        function formatFileSize(bytes) {
            if (bytes === 0) return '0 B';
            const k = 1024;
            const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        // 格式化下载速度
        function formatSpeed(bytesPerSecond) {
            if (bytesPerSecond === 0) return '0 KB/s';
            return bytesPerSecond.toFixed(2) + ' KB/s';
        }

        // 下载面板控制功能
        function toggleDownloadPanel() {
            const panel = document.getElementById('download-panel');
            panel.classList.toggle('visible');
            downloadPanelVisible = panel.classList.contains('visible');
        }

        // 更新下载面板
        function updateDownloadPanel() {
            const tasksList = document.getElementById('download-tasks-list');
            
            if (downloadTasks.length === 0) {
                tasksList.innerHTML = '<div style="padding: 10px; text-align: center; color: #666;">暂无下载任务</div>';
                return;
            }

            let html = '';
            for (const task of downloadTasks) {
                // 根据文件类型选择图标
                let fileIcon = '📁';
                if (task.fileType === 'image') fileIcon = '🖼️';
                else if (task.fileType === 'audio') fileIcon = '🎵';
                else if (task.fileType === 'video') fileIcon = '🎬';

                // 状态文本
                let statusText = '';
                switch (task.status) {
                    case DownloadStatus.PENDING: statusText = '等待下载'; break;
                    case DownloadStatus.DOWNLOADING: statusText = '下载中'; break;
                    case DownloadStatus.PAUSED: statusText = '已暂停'; break;
                    case DownloadStatus.COMPLETED: statusText = '已完成'; break;
                    case DownloadStatus.FAILED: statusText = '下载失败'; break;
                    case DownloadStatus.CANCELED: statusText = '已取消'; break;
                }

                html += `
                    <div class="download-task" data-task-id="${task.id}">
                        <div class="download-task-header">
                            <div class="download-file-icon">${fileIcon}</div>
                            <div class="download-file-info">
                                <div class="download-file-name">${task.fileName}</div>
                                <div class="download-file-meta">${statusText}</div>
                            </div>
                        </div>
                        <div class="download-progress-container">
                            <div class="download-progress-bar" style="width: ${task.progress}%"></div>
                        </div>
                        <div class="download-progress-info">
                            <span>${formatFileSize(task.downloadedSize)} / ${formatFileSize(task.fileSize)}</span>
                            <span>${formatSpeed(task.speed)}</span>
                        </div>
                        <div class="download-controls">
                            ${task.status === DownloadStatus.DOWNLOADING ? `
                                <button class="download-control-btn" onclick="pauseDownload('${task.id}')">暂停</button>
                            ` : ''}
                            ${(task.status === DownloadStatus.PENDING || task.status === DownloadStatus.PAUSED || task.status === DownloadStatus.FAILED) ? `
                                <button class="download-control-btn primary" onclick="startDownload('${task.id}')">开始</button>
                            ` : ''}
                            ${task.status === DownloadStatus.COMPLETED ? `
                                <button class="download-control-btn primary" onclick="openDownloadedFile('${task.id}')">打开</button>
                            ` : ''}
                            <button class="download-control-btn danger" onclick="deleteDownloadTask('${task.id}')">删除</button>
                        </div>
                    </div>
                `;
            }

            tasksList.innerHTML = html;
        }

        // 开始下载
        function startDownload(taskId) {
            const task = downloadTasks.find(t => t.id === taskId);
            if (!task) return;

            // 更新任务状态为下载中
            updateDownloadStatus(taskId, DownloadStatus.DOWNLOADING);
            
            // 如果是第一次下载，确保chunks数组初始化
            if (!task.chunks) {
                task.chunks = [];
            }
            
            console.log(`开始下载: ${task.fileName}，已下载${task.downloadedSize}字节`);
            
            // 开始下载
            downloadFile(task);
        }

        // 暂停下载
        function pauseDownload(taskId) {
            const task = downloadTasks.find(t => t.id === taskId);
            if (!task) return;
            
            // 取消当前的fetch请求
            if (task.abortController) {
                task.abortController.abort();
                task.abortController = null;
            }
            
            updateDownloadStatus(taskId, DownloadStatus.PAUSED);
        }

        // 打开已下载文件
        function openDownloadedFile(taskId) {
            const task = downloadTasks.find(t => t.id === taskId);
            if (!task) return;

            // 这里可以添加打开文件的逻辑，比如使用window.open或创建a标签下载
            window.open(task.filePath, '_blank');
        }
        
        // 将DataURL转换为Blob对象
        function dataURLToBlob(dataURL) {
            const parts = dataURL.split(';base64,');
            const contentType = parts[0].split(':')[1];
            const raw = window.atob(parts[1]);
            const rawLength = raw.length;
            const uInt8Array = new Uint8Array(rawLength);
            
            for (let i = 0; i < rawLength; ++i) {
                uInt8Array[i] = raw.charCodeAt(i);
            }
            
            return new Blob([uInt8Array], { type: contentType });
        }
        
        // 下载Blob对象
        function downloadBlob(blob, fileName) {
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.style.display = 'none';
            a.href = url;
            a.download = fileName;
            document.body.appendChild(a);
            a.click();
            window.URL.revokeObjectURL(url);
            document.body.removeChild(a);
        }

        // 主下载函数
        async function downloadFile(task) {
            // 检查任务状态，如果不是下载中，直接返回
            if (task.status !== DownloadStatus.DOWNLOADING) {
                return;
            }
            
            try {
                // 检查是否是localStorage文件，支持多种前缀
                if (task.filePath && (task.filePath.startsWith('Picture_') || task.filePath.startsWith('Video_') || task.filePath.startsWith('Audio_') || task.filePath.startsWith('File_'))) {
                    // 从localStorage获取文件数据
                    const fileData = localStorage.getItem(task.filePath);
                    if (fileData) {
                        const fileInfo = JSON.parse(fileData);
                        // 创建Blob对象
                        const blob = dataURLToBlob(fileInfo.data);
                        
                        // 更新任务状态为已完成
                        updateDownloadStatus(task.id, DownloadStatus.COMPLETED);
                        updateDownloadProgress(task.id, blob.size, blob.size);
                        
                        // 触发文件下载
                        downloadBlob(blob, task.fileName);
                        
                        // 更新下载面板
                        updateDownloadPanel();
                        return;
                    }
                }
                
                // 对于服务器文件，直接使用原始路径
                let downloadUrl = task.filePath;
                
                // 创建AbortController用于取消请求
                task.abortController = new AbortController();
                
                // 设置请求选项，支持断点续传
                const fetchOptions = {
                    mode: 'cors',
                    signal: task.abortController.signal
                };
                
                // 只有非音乐文件才使用credentials
                if (task.fileType !== 'audio') {
                    fetchOptions.credentials = 'include';
                }
                
                // 设置请求头
                const headers = {};
                // 如果已经有部分下载，设置Range头
                if (task.downloadedSize > 0) {
                    headers.Range = `bytes=${task.downloadedSize}-`;
                    console.log(`继续下载: ${task.fileName}，从${task.downloadedSize}字节开始`);
                }
                
                // 添加headers到fetchOptions
                fetchOptions.headers = headers;
                
                // 尝试从服务器下载
                const response = await fetch(downloadUrl, fetchOptions);

                if (response.ok || response.status === 206) { // 206是部分内容
                    // 服务器返回成功，开始下载
                    const isPartial = response.status === 206;
                    const totalSize = parseInt(response.headers.get('content-length') || '0', 10);
                    const contentLength = isPartial ? totalSize + task.downloadedSize : totalSize;
                    
                    // 如果是第一次下载，设置总大小
                    if (!isPartial) {
                        task.fileSize = contentLength;
                        // 重置chunks数组，确保只包含本次下载的内容
                        task.chunks = [];
                    }
                    
                    const reader = response.body.getReader();
                    let receivedLength = task.downloadedSize;
                    
                    // 下载循环
                    while (true) {
                        // 检查任务状态，如果已暂停，退出循环
                        if (task.status !== DownloadStatus.DOWNLOADING) {
                            console.log(`下载已暂停: ${task.fileName}`);
                            break;
                        }
                        
                        const { done, value } = await reader.read();
                        if (done) break;
                        
                        // 将下载的chunk添加到任务的chunks数组
                        task.chunks.push(value);
                        receivedLength += value.length;
                        
                        // 更新下载进度
                        updateDownloadProgress(task.id, receivedLength, contentLength);
                    }
                    
                    // 检查是否完成下载
                    if (receivedLength >= contentLength) {
                        console.log(`下载完成: ${task.fileName}`);
                        // 合并所有chunk
                        const blob = new Blob(task.chunks);
                        
                        // 保存文件到浏览器
                        saveFileToBrowser(blob, task.fileName);
                        
                        // 更新任务状态
                        updateDownloadStatus(task.id, DownloadStatus.COMPLETED);
                        
                        // 清理资源
                        task.abortController = null;
                        task.chunks = [];
                    } else {
                        console.log(`下载暂停: ${task.fileName}，已下载${receivedLength}/${contentLength}字节`);
                        // 下载暂停，保留已下载的chunks
                        task.abortController = null;
                    }
                } else if (response.status === 404) {
                    // 服务器返回404，尝试从缓存获取
                    console.log('服务器返回404，尝试从缓存获取文件');
                    
                    // 尝试使用缓存获取文件
                    const cachedResponse = await fetch(task.filePath, {
                        credentials: 'include',
                        cache: 'force-cache',
                        signal: task.abortController.signal
                    });
                    
                    if (cachedResponse.ok) {
                        const blob = await cachedResponse.blob();
                        saveFileToBrowser(blob, task.fileName);
                        updateDownloadStatus(task.id, DownloadStatus.COMPLETED);
                    } else {
                        // 缓存也没有，下载失败
                        updateDownloadStatus(task.id, DownloadStatus.FAILED);
                    }
                    
                    // 清理资源
                    task.abortController = null;
                    task.chunks = [];
                } else {
                    // 其他错误
                    console.error(`下载错误: ${response.status} ${response.statusText}`);
                    updateDownloadStatus(task.id, DownloadStatus.FAILED);
                    
                    // 清理资源
                    task.abortController = null;
                    task.chunks = [];
                }
            } catch (error) {
                console.error('下载错误:', error);
                // 如果是abort错误，不更新状态为失败，保持暂停状态
                if (error.name === 'AbortError') {
                    console.log(`下载已取消: ${task.fileName}`);
                    // 保持暂停状态
                    updateDownloadStatus(task.id, DownloadStatus.PAUSED);
                } else {
                    updateDownloadStatus(task.id, DownloadStatus.FAILED);
                    // 清理资源
                    task.chunks = [];
                }
                
                // 清理资源
                task.abortController = null;
            }
        }

        // 保存文件到浏览器
        function saveFileToBrowser(blob, fileName) {
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = fileName;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        }

        // 切换媒体操作菜单显示
        function toggleMediaActionsMenu(event, button) {
            event.stopPropagation();
            
            // 关闭所有其他菜单
            document.querySelectorAll('.file-actions-menu').forEach(menu => {
                menu.style.display = 'none';
            });

            // 显示当前菜单
            const menu = button.nextElementSibling;
            if (menu) {
                menu.style.display = menu.style.display === 'block' ? 'none' : 'block';
            }

            // 点击其他地方关闭菜单
            document.addEventListener('click', function closeMenu(e) {
                if (!button.contains(e.target) && !menu.contains(e.target)) {
                    menu.style.display = 'none';
                    document.removeEventListener('click', closeMenu);
                }
            });
        }

        // 切换群聊菜单显示
        function toggleGroupMenu(event, groupId) {
            event.stopPropagation();
            
            // 关闭所有其他菜单
            document.querySelectorAll('.friend-menu, [id^="group-menu-"]').forEach(menu => {
                if (menu.id !== `group-menu-${groupId}`) {
                    menu.style.display = 'none';
                }
            });
            
            // 切换当前菜单
            const menu = document.getElementById(`group-menu-${groupId}`);
            if (menu) {
                menu.style.display = menu.style.display === 'block' ? 'none' : 'block';
            }
            
            // 点击其他地方关闭菜单
            document.addEventListener('click', function closeMenu(e) {
                if (!e.target.closest('[id="group-menu-' + groupId + '"]') && !e.target.closest(`[onclick*="toggleGroupMenu"]`)) {
                    const menu = document.getElementById(`group-menu-${groupId}`);
                    if (menu) {
                        menu.style.display = 'none';
                    }
                    document.removeEventListener('click', closeMenu);
                }
            });
        }

        // 添加好友窗口功能
        function showAddFriendWindow() {
            const modal = document.getElementById('add-friend-modal');
            modal.style.display = 'flex';
            
            // 加载好友申请列表
            loadFriendRequests();
        }

        function closeAddFriendWindow() {
            const modal = document.getElementById('add-friend-modal');
            modal.style.display = 'none';
        }

        // 切换添加好友窗口选项卡
        function switchAddFriendTab(tabName) {
            // 切换选项卡样式
            document.querySelectorAll('.add-friend-tab').forEach(tab => {
                tab.classList.remove('active');
                tab.style.color = '#666';
                tab.style.borderBottom = '1px solid #eaeaea';
            });
            
            document.getElementById(tabName + '-tab').classList.add('active');
            document.getElementById(tabName + '-tab').style.color = '#12b7f5';
            document.getElementById(tabName + '-tab').style.borderBottom = '2px solid #12b7f5';
            
            // 切换内容显示
            document.querySelectorAll('.add-friend-content').forEach(content => {
                content.style.display = 'none';
            });
            
            document.getElementById(tabName + '-content').style.display = 'block';
            
            // 根据选项卡类型加载对应的数据
            if (tabName === 'requests') {
                loadFriendRequests();
            }
        }

        // 搜索用户功能（添加好友弹窗）
        function searchUser() {
            const searchInput = document.getElementById('search-user-input');
            const searchTerm = searchInput.value.trim();
            const resultsDiv = document.getElementById('search-results');
            
            if (!searchTerm) {
                resultsDiv.innerHTML = '<p style="text-align: center; color: #666; padding: 20px;">请输入用户名或邮箱进行搜索</p>';
                return;
            }
            
            resultsDiv.innerHTML = '<p style="text-align: center; color: #666; padding: 20px;">搜索中...</p>';
            
            // 发送搜索请求到服务器
            fetch(`search_users.php?q=${encodeURIComponent(searchTerm)}`, {
                credentials: 'include'
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('网络请求失败');
                }
                return response.json();
            })
            .then(data => {
                let html = '';
                
                try {
                    // 处理用户列表
                    let users = [];
                    if (data && data.success) {
                        if (data.users) {
                            if (Array.isArray(data.users)) {
                                users = data.users;
                            } else if (typeof data.users === 'object') {
                                users = Object.values(data.users);
                            }
                        }
                    }

                    if (users.length > 0) {
                        users.forEach(user => {
                            // 安全获取属性
                            const userId = user.id || 0;
                            const userName = user.username || '未知用户';
                            const userEmail = user.email || '';
                            const safeUserName = userName.replace(/'/g, "\\'");
                            
                            html += `<div style="display: flex; align-items: center; padding: 12px; border-bottom: 1px solid #f0f0f0;">
                                <div style="flex: 1;">
                                    <div style="font-weight: 600; margin-bottom: 2px;">${userName}</div>
                                    <div style="font-size: 12px; color: #666;">${userEmail}</div>
                                </div>
                                <div style="position: relative;" class="search-user-action">
                                    <button onclick="toggleSearchUserMenu(event, ${userId})" style="background: none; border: none; cursor: pointer; color: #666; font-size: 20px; padding: 0 10px;">⋮</button>
                                    <div id="search-user-menu-${userId}" class="search-user-menu" style="display: none; position: absolute; right: 0; top: 100%; background: white; border: 1px solid #eee; border-radius: 6px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); z-index: 10; min-width: 100px;">
                                        <button onclick="sendFriendRequest(${userId}, '${safeUserName}')" style="display: block; width: 100%; text-align: left; padding: 8px 12px; background: none; border: none; cursor: pointer; color: #333; font-size: 13px;">添加好友</button>
                                    </div>
                                </div>
                            </div>`;
                        });
                    } else {
                        html = '<p style="text-align: center; color: #666; padding: 20px;">未找到匹配的用户</p>';
                    }
                } catch (e) {
                    console.error('Mobile search render error:', e);
                    html = '<p style="text-align: center; color: #ff4d4f; padding: 20px;">渲染错误</p>';
                }
                
                resultsDiv.innerHTML = html;
            })
            .catch(error => {
                console.error('搜索用户失败:', error);
                resultsDiv.innerHTML = '<p style="text-align: center; color: #ff4d4f; padding: 20px;">搜索失败，请重试</p>';
            });
        }
        
        // 确保 searchUser 在全局作用域可访问
        window.searchUser = searchUser;
        
        // 切换搜索用户菜单显示
        window.toggleSearchUserMenu = function toggleSearchUserMenu(event, userId) {
            event.stopPropagation();
            
            // 关闭所有其他菜单
            document.querySelectorAll('.search-user-menu').forEach(menu => {
                menu.style.display = 'none';
            });
            
            // 切换当前菜单
            const menu = document.getElementById(`search-user-menu-${userId}`);
            if (menu) {
                menu.style.display = menu.style.display === 'block' ? 'none' : 'block';
            }
        }
        
        // 点击其他地方关闭搜索用户菜单
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.search-user-action')) {
                document.querySelectorAll('.search-user-menu').forEach(menu => {
                    menu.style.display = 'none';
                });
            }
        });

        // 主界面搜索好友和群聊功能
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('search-input');
            if (searchInput) {
                searchInput.addEventListener('input', function(e) {
                    const searchTerm = e.target.value.trim().toLowerCase();
                    const searchResults = document.getElementById('main-search-results');
                    const chatList = document.getElementById('combined-chat-list');
                    
                    // 重置所有聊天项显示
                    const allChatItems = document.querySelectorAll('.chat-item');
                    
                    if (searchTerm.length < 1) {
                        searchResults.style.display = 'none';
                        allChatItems.forEach(item => item.style.display = 'flex');
                        return;
                    }
                    
                    // 本地搜索：过滤聊天列表
                    allChatItems.forEach(item => {
                        const nameElement = item.querySelector('.chat-name');
                        const name = nameElement ? nameElement.textContent.trim().toLowerCase() : '';
                        
                        if (name.includes(searchTerm)) {
                            item.style.display = 'flex';
                        } else {
                            item.style.display = 'none';
                        }
                    });
                    
                    // 隐藏原来的下拉搜索结果，因为现在直接在列表中过滤
                    if (searchResults) {
                        searchResults.style.display = 'none';
                    }
                });
            }
        });
        
        // 切换到指定聊天
        function switchToChat(chatType, chatId) {
            window.location.href = `?chat_type=${chatType}&id=${chatId}`;
        }

        // 发送好友请求
        window.sendFriendRequest = function sendFriendRequest(userId, username) {
            // 发送请求到服务器
            fetch(`send_friend_request.php?friend_id=${userId}`, {
                method: 'POST',
                credentials: 'include'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(`已向 ${username} 发送好友请求`, 'success');
                } else {
                    showNotification(`发送好友请求失败: ${data.message}`, 'error');
                }
            })
            .catch(error => {
                console.error('发送好友请求失败:', error);
                showNotification('发送好友请求失败，请重试', 'error');
            });
        }

        // 加载好友申请列表
        function loadFriendRequests() {
            console.log('开始加载申请列表');
            try {
                let requestsList;
                
                // 查找所有friend-requests-list元素
                const allRequestLists = document.querySelectorAll('#friend-requests-list');
                
                // 检查哪个弹窗是可见的，选择正确的元素
                if (allRequestLists.length > 0) {
                    // 先检查add-friend-modal中的好友申请列表（最常用的）
                    const addFriendModal = document.getElementById('add-friend-modal');
                    const friendRequestsModal = document.getElementById('friend-requests-modal');
                    
                    if (addFriendModal && addFriendModal.style.display !== 'none') {
                        // add-friend-modal可见，使用第三个元素（索引2）
                        requestsList = allRequestLists[2];
                    } else if (friendRequestsModal && friendRequestsModal.style.display !== 'none') {
                        // friend-requests-modal可见，使用第一个元素（索引0）
                        requestsList = allRequestLists[0];
                    } else {
                        // 默认使用第一个元素
                        requestsList = allRequestLists[0];
                    }
                } else {
                    console.error('申请列表元素不存在');
                    return;
                }
                
                // 检查DOM元素是否存在
                if (!requestsList) {
                    console.error('申请列表元素不存在');
                    return;
                }
                
                requestsList.innerHTML = '<p style="text-align: center; color: #666; padding: 20px;">加载中...</p>';
                
                // 从服务器获取好友申请列表
                console.log('发送请求到get_friend_requests.php');
                fetch('get_friend_requests.php', {
                    credentials: 'include'
                })
                .then(response => {
                    console.log('收到响应:', response.status, response.statusText);
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    console.log('响应数据:', data);
                    // 检查数据格式是否正确
                    if (!data || typeof data !== 'object') {
                        throw new Error('无效的响应数据格式');
                    }
                    
                    let html = '';
                    if (data.success && Array.isArray(data.requests) && data.requests.length > 0) {
                        console.log('申请列表:', data.requests);
                        data.requests.forEach(request => {
                            // 格式化时间
                            const formattedTime = request.created_at ? new Date(request.created_at).toLocaleString('zh-CN', {year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute:'2-digit'}) : '';
                            
                            if (request.type === 'friend') {
                                // 好友请求
                                const avatar = request.avatar ? `<img src="${request.avatar}" alt="${request.username}" style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover;">` : `<div style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; font-size: 14px;">${request.username.substring(0, 2)}</div>`;
                                
                                html += `<div style="display: flex; align-items: center; padding: 12px; border-bottom: 1px solid #f0f0f0;">
                                    <div style="margin-right: 12px;">${avatar}</div>
                                    <div style="flex: 1;">
                                        <div style="font-weight: 600; margin-bottom: 2px;">${request.username}</div>
                                        <div style="font-size: 12px; color: #666;">${request.email}</div>
                                        <div style="font-size: 11px; color: #999; margin-top: 2px;">${formattedTime}</div>
                                    </div>
                                    <div style="display: flex; gap: 8px;">
                                        <button onclick="acceptFriendRequest(${request.request_id}, ${request.id}, '${request.username}')" style="padding: 6px 12px; background: #4CAF50; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 12px;">接受</button>
                                        <button onclick="rejectFriendRequest(${request.request_id}, '${request.username}')" style="padding: 6px 12px; background: #f44336; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 12px;">拒绝</button>
                                    </div>
                                </div>`;
                            } else if (request.type === 'group') {
                                // 群聊邀请
                                // 群聊头像（使用默认头像，因为群聊没有实际头像字段）
                                const groupAvatar = `<div style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; font-size: 14px;">${request.group_name.substring(0, 2)}</div>`;
                                
                                html += `<div style="display: flex; align-items: center; padding: 12px; border-bottom: 1px solid #f0f0f0;">
                                    <div style="margin-right: 12px;">${groupAvatar}</div>
                                    <div style="flex: 1;">
                                        <div style="font-weight: 600; margin-bottom: 2px;">${request.group_name}</div>
                                        <div style="font-size: 12px; color: #666;">${request.inviter_name} 邀请您加入群聊</div>
                                        <div style="font-size: 11px; color: #999; margin-top: 2px;">${formattedTime}</div>
                                    </div>
                                    <div style="display: flex; gap: 8px;">
                                        <button onclick="acceptGroupInvitation(${request.id})" style="padding: 6px 12px; background: #4CAF50; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 12px;">接受</button>
                                        <button onclick="rejectGroupInvitation(${request.id})" style="padding: 6px 12px; background: #f44336; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 12px;">拒绝</button>
                                    </div>
                                </div>`;
                            }
                        });
                    } else {
                        console.log('暂无申请或请求失败:', data);
                        if (data.success === false) {
                            html = `<p style="text-align: center; color: #ff4d4f; padding: 20px;">${data.message || '获取申请失败'}</p>`;
                        } else {
                            html = '<p style="text-align: center; color: #666; padding: 20px;">暂无申请</p>';
                        }
                    }
                    
                    requestsList.innerHTML = html;
                })
                .catch(error => {
                    console.error('获取申请列表失败:', error);
                    requestsList.innerHTML = `<p style="text-align: center; color: #ff4d4f; padding: 20px;">获取申请失败: ${error.message}</p>`;
                });
            } catch (error) {
                console.error('加载申请列表时发生错误:', error);
                // 错误处理时也需要选择正确的元素
                const allRequestLists = document.querySelectorAll('#friend-requests-list');
                let requestsList;
                if (allRequestLists.length > 0) {
                    // 检查哪个弹窗是可见的
                    const addFriendModal = document.getElementById('add-friend-modal');
                    const friendRequestsModal = document.getElementById('friend-requests-modal');
                    
                    if (addFriendModal && addFriendModal.style.display !== 'none') {
                        requestsList = allRequestLists[2];
                    } else if (friendRequestsModal && friendRequestsModal.style.display !== 'none') {
                        requestsList = allRequestLists[0];
                    } else {
                        requestsList = allRequestLists[allRequestLists.length - 1]; // 默认为最后一个
                    }
                    
                    if (requestsList) {
                        requestsList.innerHTML = `<p style="text-align: center; color: #ff4d4f; padding: 20px;">加载失败: ${error.message}</p>`;
                    }
                }
            }
        }

        // 接受好友请求
        function acceptFriendRequest(requestId, userId, username) {
            console.log('Accepting request:', requestId, userId, username); // Debug log
            
            // 发送接受好友请求到服务器
            const formData = new FormData();
            formData.append('request_id', requestId);
            
            fetch(`accept_request.php?request_id=${requestId}`, {
                credentials: 'include',
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(`已接受 ${username} 的好友请求`, 'success');
                    // 重新加载好友申请列表
                    loadFriendRequests();
                    // 重新加载好友列表
                    loadFriendsList();
                } else {
                    showNotification(`接受好友请求失败: ${data.message}`, 'error');
                }
            })
            .catch(error => {
                console.error('接受好友请求失败:', error);
                showNotification('接受好友请求失败', 'error');
            });
        }

        // 拒绝好友请求
        function rejectFriendRequest(requestId, username) {
            // 发送拒绝好友请求到服务器
            const formData = new FormData();
            formData.append('request_id', requestId);
            
            fetch(`reject_request.php?request_id=${requestId}`, {
                credentials: 'include',
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(`已拒绝 ${username} 的好友请求`, 'success');
                    // 重新加载好友申请列表
                    loadFriendRequests();
                } else {
                    showNotification(`拒绝好友请求失败: ${data.message}`, 'error');
                }
            })
            .catch(error => {
                console.error('拒绝好友请求失败:', error);
                showNotification('拒绝好友请求失败', 'error');
            });
        }
        
        // 加载好友列表
        function loadFriendsList() {
            // 重新加载页面以更新好友列表
            window.location.reload();
        }
        
        // 加载好友列表用于创建群聊
        function loadFriendsForGroup() {
            const friendsContainer = document.getElementById('select-friends-container');
            
            friendsContainer.innerHTML = '<p style="text-align: center; color: #666; padding: 20px;">加载中...</p>';
            
            // 从服务器获取好友列表
            fetch('get_available_friends.php', {
                credentials: 'include'
            })
            .then(response => response.json())
            .then(data => {
                let html = '';
                if (data.success && data.friends.length > 0) {
                    // 添加清空和创建按钮的容器
                    html += `<div style="display: flex; justify-content: flex-end; gap: 10px; margin-bottom: 15px;">
                        <button onclick="clearSelectedFriends()" style="
                            padding: 8px 16px;
                            background: #f5f5f5;
                            color: #333;
                            border: 1px solid #ddd;
                            border-radius: 6px;
                            cursor: pointer;
                            font-size: 14px;
                            transition: all 0.2s;
                        ">清空</button>
                        <button onclick="createGroup()" style="
                            padding: 8px 20px;
                            background: #12b7f5;
                            color: white;
                            border: none;
                            border-radius: 6px;
                            cursor: pointer;
                            font-size: 14px;
                            font-weight: 500;
                            transition: all 0.2s;
                        ">创建</button>
                    </div>`;
                    
                    // 生成好友选择列表
                    html += `<div style="display: grid; gap: 10px;">`;
                    data.friends.forEach(friend => {
                        // 生成头像HTML
                        const avatar = friend.avatar ? 
                            `<img src="${friend.avatar}" alt="${friend.username}" style="
                                width: 48px;
                                height: 48px;
                                border-radius: 50%;
                                object-fit: cover;
                                border: 2px solid #eaeaea;
                                transition: all 0.2s;
                            ">` : 
                            `<div style="
                                width: 48px;
                                height: 48px;
                                border-radius: 50%;
                                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                                display: flex;
                                align-items: center;
                                justify-content: center;
                                color: white;
                                font-weight: 600;
                                font-size: 18px;
                                border: 2px solid #eaeaea;
                                transition: all 0.2s;
                            ">${friend.username.substring(0, 2)}</div>`;
                        
                        // 生成好友项HTML
                        html += `<div class="friend-select-item" id="friend-item-${friend.id}" style="
                            display: flex;
                            align-items: center;
                            padding: 12px;
                            background: white;
                            border: 2px solid #f0f0f0;
                            border-radius: 12px;
                            cursor: pointer;
                            transition: all 0.2s ease;
                            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
                        " onmouseenter="this.style.borderColor='#12b7f5'; this.style.boxShadow='0 4px 12px rgba(18, 183, 245, 0.15)';" 
                           onmouseleave="if(!this.querySelector('input').checked) { this.style.borderColor='#f0f0f0'; this.style.boxShadow='0 2px 4px rgba(0, 0, 0, 0.05)'; this.style.background='white'; }" 
                           onclick="toggleFriendSelection(${friend.id})">
                            
                            <!-- 好友头像 -->
                            <div style="flex-shrink: 0;">${avatar}</div>
                            
                            <!-- 好友名称 -->
                            <div style="flex: 1; margin-left: 15px; font-weight: 500; color: #333; font-size: 15px;">
                                ${friend.username}
                            </div>
                            
                            <!-- 美化的选择按钮 -->
                            <div style="flex-shrink: 0;">
                                <label class="custom-checkbox" style="
                                    position: relative;
                                    display: inline-block;
                                    width: 24px;
                                    height: 24px;
                                    cursor: pointer;
                                ">
                                    <input type="checkbox" id="friend-${friend.id}" value="${friend.id}" style="
                                        opacity: 0;
                                        width: 0;
                                        height: 0;
                                    ">
                                    <span style="
                                        position: absolute;
                                        top: 0;
                                        left: 0;
                                        width: 24px;
                                        height: 24px;
                                        background-color: #f5f5f5;
                                        border: 2px solid #ddd;
                                        border-radius: 6px;
                                        transition: all 0.2s ease;
                                    "></span>
                                    <span style="
                                        position: absolute;
                                        top: 4px;
                                        left: 4px;
                                        width: 16px;
                                        height: 16px;
                                        background: white;
                                        border-radius: 3px;
                                        opacity: 0;
                                        transition: all 0.2s ease;
                                        background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='white'%3e%3cpath fill-rule='evenodd' d='M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z' clip-rule='evenodd'/%3e%3c/svg%3e");
                                        background-repeat: no-repeat;
                                        background-position: center;
                                        background-size: 12px;
                                    "></span>
                                </label>
                            </div>
                        </div>`;
                    });
                    html += `</div>`;
                    
                    // 添加样式
                    html += `<style>
                        /* 美化复选框选中状态 */
                        .custom-checkbox input:checked + span {
                            background-color: #12b7f5;
                            border-color: #12b7f5;
                        }
                        
                        .custom-checkbox input:checked + span + span {
                            opacity: 1;
                        }
                        
                        /* 选中好友项的样式 */
                        .friend-select-item input:checked + span {
                            background-color: #12b7f5;
                            border-color: #12b7f5;
                        }
                        
                        .friend-select-item input:checked + span + span {
                            opacity: 1;
                        }
                        
                        /* 按钮悬停效果 */
                        button:hover {
                            opacity: 0.9;
                            transform: translateY(-1px);
                            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
                        }
                        
                        button:active {
                            transform: translateY(0);
                        }
                    </style>`;
                } else {
                    html = `<div style="text-align: center; color: #6c757d; padding: 40px 20px; background: white; border-radius: 12px; margin: 0;">
                        <div style="font-size: 48px; margin-bottom: 15px;">👥</div>
                        <p style="font-size: 16px; margin: 0;">暂无好友</p>
                        <p style="font-size: 14px; margin-top: 8px; color: #999;">添加好友后即可创建群聊</p>
                    </div>`;
                }
                
                friendsContainer.innerHTML = html;
            })
            .catch(error => {
                console.error('获取好友列表失败:', error);
                friendsContainer.innerHTML = `<div style="text-align: center; color: #ff6b6b; padding: 40px 20px; background: white; border-radius: 12px; margin: 0;">
                    <div style="font-size: 48px; margin-bottom: 15px;">❌</div>
                    <p style="font-size: 16px; margin: 0;">加载失败</p>
                    <p style="font-size: 14px; margin-top: 8px; color: #999;">请检查网络连接后重试</p>
                    <button onclick="loadFriendsForGroup()" style="
                        margin-top: 15px;
                        padding: 8px 20px;
                        background: #12b7f5;
                        color: white;
                        border: none;
                        border-radius: 6px;
                        cursor: pointer;
                        font-size: 14px;
                        font-weight: 500;
                        transition: all 0.2s;
                    ">重试</button>
                </div>`;
            });
        }
        
        // 切换好友选择状态
        function toggleFriendSelection(friendId) {
            const checkbox = document.getElementById(`friend-${friendId}`);
            if (checkbox) {
                checkbox.checked = !checkbox.checked;
            }
        }
        
        // 清空选中的好友
        function clearSelectedFriends() {
            document.querySelectorAll('#select-friends-container input[type="checkbox"]').forEach(checkbox => {
                checkbox.checked = false;
            });
        }
        
        // 添加好友选择项的样式和交互
        function addFriendSelectStyles() {
            // 添加自定义复选框选中效果
            document.querySelectorAll('.friend-select-item input[type="checkbox"]').forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    const item = this.closest('.friend-select-item');
                    const checkboxBg = this.nextElementSibling;
                    const checkboxCheck = checkboxBg.nextElementSibling;
                    const avatar = item.querySelector('.friend-avatar');
                    
                    if (this.checked) {
                        // 选中状态
                        item.style.background = '#e3f2fd';
                        item.style.borderColor = '#667eea';
                        checkboxBg.style.background = '#667eea';
                        checkboxBg.style.borderColor = '#667eea';
                        checkboxCheck.style.opacity = '1';
                        checkboxCheck.style.color = 'white';
                        avatar.style.borderColor = '#667eea';
                    } else {
                        // 未选中状态
                        item.style.background = '#f8f9fa';
                        item.style.borderColor = 'transparent';
                        checkboxBg.style.background = '#e9ecef';
                        checkboxBg.style.borderColor = '#dee2e6';
                        checkboxCheck.style.opacity = '0';
                        avatar.style.borderColor = 'transparent';
                    }
                });
                
                // 点击好友项时切换复选框状态
                checkbox.closest('.friend-select-item').addEventListener('click', function(e) {
                    if (!e.target.closest('input[type="checkbox"]')) {
                        checkbox.checked = !checkbox.checked;
                        checkbox.dispatchEvent(new Event('change'));
                    }
                });
            });
            
            // 添加悬停效果
            document.querySelectorAll('.friend-select-item').forEach(item => {
                item.addEventListener('mouseenter', function() {
                    if (!this.querySelector('input[type="checkbox"]').checked) {
                        this.style.background = '#e9ecef';
                        this.style.transform = 'translateY(-1px)';
                        this.style.boxShadow = '0 4px 12px rgba(0, 0, 0, 0.08)';
                    }
                });
                
                item.addEventListener('mouseleave', function() {
                    if (!this.querySelector('input[type="checkbox"]').checked) {
                        this.style.background = '#f8f9fa';
                        this.style.transform = 'translateY(0)';
                        this.style.boxShadow = 'none';
                    }
                });
            });
        }
        
        // 切换好友选择状态
        function toggleFriendSelection(friendId) {
            const checkbox = document.getElementById(`friend-${friendId}`);
            const friendItem = checkbox.closest('div');
            if (checkbox.checked) {
                checkbox.checked = false;
                friendItem.style.borderColor = '#f0f0f0';
                friendItem.style.boxShadow = '0 2px 4px rgba(0, 0, 0, 0.05)';
                friendItem.style.background = 'white';
            } else {
                checkbox.checked = true;
                friendItem.style.borderColor = '#12b7f5';
                friendItem.style.boxShadow = '0 4px 12px rgba(18, 183, 245, 0.15)';
                friendItem.style.background = 'rgba(18, 183, 245, 0.05)';
            }
        }
        
        // 清空选中的好友
        function clearSelectedFriends() {
            document.querySelectorAll('#select-friends-container input[type="checkbox"]:checked').forEach(checkbox => {
                checkbox.checked = false;
                const friendItem = checkbox.closest('div');
                friendItem.style.borderColor = '#f0f0f0';
                friendItem.style.boxShadow = '0 2px 4px rgba(0, 0, 0, 0.05)';
                friendItem.style.background = 'white';
            });
        }
        
        // 创建群聊
        function createGroup() {
            const groupNameInput = document.getElementById('group-name-input');
            const groupName = groupNameInput.value.trim();
            
            // 验证群聊名称
            if (!groupName) {
                showNotification('请输入群聊名称', 'error');
                return;
            }
            
            // 验证名称不包含HTML代码
            if (/[<>]/.test(groupName)) {
                showNotification('名称不能包含HTML代码', 'error');
                return;
            }
            
            // 获取选中的好友
            const selectedFriends = [];
            document.querySelectorAll('#select-friends-container input[type="checkbox"]:checked').forEach(checkbox => {
                selectedFriends.push(parseInt(checkbox.value));
            });
            
            // 发送请求创建群聊
            fetch('create_group.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                credentials: 'include',
                body: JSON.stringify({
                    name: groupName,
                    member_ids: selectedFriends
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('群聊创建成功', 'success');
                    // 关闭添加好友窗口
                    closeAddFriendWindow();
                    // 刷新页面或更新群聊列表
                    window.location.reload();
                } else {
                    showNotification(data.message || '群聊创建失败', 'error');
                }
            })
            .catch(error => {
                console.error('群聊创建失败:', error);
                showNotification('群聊创建失败', 'error');
            });
        }
        
        // 清空群聊表单
        // 获取文件过期时间（秒）
        function getFileExpirySeconds(fileType) {
            switch(fileType) {
                case 'file':
                    return 7 * 24 * 60 * 60; // 7天
                case 'image':
                    return 30 * 24 * 60 * 60; // 30天
                case 'video':
                    return 14 * 24 * 60 * 60; // 14天
                case 'audio':
                    return 20 * 24 * 60 * 60; // 20天
                default:
                    return 7 * 24 * 60 * 60; // 默认7天
            }
        }
        
        // 检查文件是否过期
        function isFileExpired(filePath, fileType = '') {
            // 如果是localStorage文件ID，直接返回false（因为存储在localStorage中）
            if (filePath && (filePath.startsWith('Picture_') || filePath.startsWith('Video_') || filePath.startsWith('Audio_') || filePath.startsWith('File_'))) {
                return false;
            }
            
            // 从filePath中提取文件名，作为缓存的键
            const fileName = filePath.split('/').pop().split('?')[0];
            // 根据文件类型设置不同的前缀
            let prefix = 'File_';
            if (fileType === 'video') {
                prefix = 'Video_';
            } else if (fileType === 'image') {
                prefix = 'Picture_';
            } else if (fileType === 'audio') {
                prefix = 'Audio_';
            }
            
            const targetStorageKey = `${prefix}${encodeURIComponent(fileName)}`;
            
            // 检查localStorage中是否存在该文件的缓存记录
            return !localStorage.getItem(targetStorageKey);
        }
        
        // 设置文件缓存记录到localStorage
        function setFileCache(filePath, fileType, fileSize = 0) {
            // 如果是localStorage文件ID，不需要设置缓存记录
            if (filePath && (filePath.startsWith('Picture_') || filePath.startsWith('Video_') || filePath.startsWith('Audio_') || filePath.startsWith('File_'))) {
                return;
            }
            
            // 从filePath中提取文件名，作为缓存的键
            const fileName = filePath.split('/').pop().split('?')[0];
            // 根据文件类型设置不同的前缀
            let prefix = 'File_';
            if (fileType === 'video') {
                prefix = 'Video_';
            } else if (fileType === 'image') {
                prefix = 'Picture_';
            } else if (fileType === 'audio') {
                prefix = 'Audio_';
            }
            
            const storageKey = `${prefix}${encodeURIComponent(fileName)}`;
            // 存储文件类型和大小，格式为"type:size"
            localStorage.setItem(storageKey, `${fileType}:${fileSize}`);
        }
        
        // 加载聊天记录
        function loadChatHistory() {
            const messagesContainer = document.getElementById('messages-container');
            if (!messagesContainer) return;
            
            // 调用initChatMedia函数来初始化所有媒体文件，优先从IndexedDB获取
            initChatMedia();
            
            // 初始化音频播放器
            initAudioPlayers();
            
            // 滚动到底部
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }
        
        // 从服务器获取文件并缓存
        function fetchFileFromServer(filePath, fileName, fileLink) {
            // 初始化重试计数器（仅在第一次调用时）
            if (!fileRetryCounter[filePath]) {
                fileRetryCounter[filePath] = 0;
            }
            
            // 检查是否已达到最大重试次数
            if (fileRetryCounter[filePath] >= MAX_RETRIES) {
                // 达到最大重试次数，显示已清理提示
                fileLink.innerHTML = `<span class="file-icon">📁</span><div class="file-info"><h4>文件不存在或已被清理</h4><p>${fileName}</p></div>`;
                fileLink.removeAttribute('href');
                fileLink.style.pointerEvents = 'none';
                fileLink.style.opacity = '0.6';
                // 移除重试计数器
                delete fileRetryCounter[filePath];
                return;
            }
            
            // 直接使用原始路径
            let fetchUrl = filePath;
            
            fetch(fetchUrl)
                .then(response => {
                    if (response.ok) {
                        // 文件存在，获取文件大小
                        const fileSize = parseInt(response.headers.get('content-length') || '0');
                        const fileType = getFileType(fileName);
                        // 只在localStorage缓存不存在时才缓存文件
                        if (isFileExpired(filePath, fileType)) {
                            setFileCache(filePath, fileType, fileSize);
                        }
                        // 重置重试计数器
                        delete fileRetryCounter[filePath];
                    } else {
                        // 文件不存在，增加重试计数器并继续重试
                        fileRetryCounter[filePath]++;
                        setTimeout(() => {
                            fetchFileFromServer(filePath, fileName, fileLink);
                        }, 1000); // 1秒后重试
                    }
                })
                .catch(error => {
                    // 网络错误，增加重试计数器并继续重试
                    fileRetryCounter[filePath]++;
                    setTimeout(() => {
                        fetchFileFromServer(filePath, fileName, fileLink);
                    }, 1000); // 1秒后重试
                });
        }
        
        // 初始化所有媒体文件（图片、音频、视频），优先从IndexedDB获取
        async function initChatMedia() {
            // 处理所有图片元素
            document.querySelectorAll('.message-image').forEach(async img => {
                const fileUrl = img.src;
                
                // 如果src已经是blob:开头，说明已经是本地Blob URL，无需再次处理
                if (fileUrl && fileUrl.startsWith('blob:')) {
                    return;
                }

                const fileName = img.getAttribute('data-file-name') || '未知图片';
                const filePath = img.getAttribute('data-file-path') || fileUrl;
                
                // 优先从IndexedDB获取图片
                try {
                    const fileData = await indexedDBManager.getFile(filePath);
                    if (fileData && fileData.data) {
                        // 从IndexedDB获取成功，转换为Blob URL
                        const blob = new Blob([fileData.data], { type: fileData.type });
                        const blobUrl = URL.createObjectURL(blob);
                        img.src = blobUrl;
                        return;
                    }
                } catch (error) {
                    // 忽略IndexedDB错误，继续尝试从服务器获取
                }
                
                // IndexedDB中没有，尝试从服务器获取，最多重试3次
                let success = false;
                for (let i = 0; i < 3 && !success; i++) {
                    try {
                        const response = await fetch(fileUrl, {
                            credentials: 'include',
                            cache: 'no-cache'
                        });
                        
                        if (response.ok) {
                            // 请求成功，获取Blob并转换为Blob URL
                            const blob = await response.blob();
                            const blobUrl = URL.createObjectURL(blob);
                            img.src = blobUrl;
                            success = true;
                            
                            // 缓存到IndexedDB
                            try {
                                await indexedDBManager.saveFile({
                                    id: filePath,
                                    name: fileName,
                                    type: blob.type,
                                    size: blob.size,
                                    data: blob,
                                    url: fileUrl,
                                    uploadedAt: new Date().toISOString(),
                                    fileType: 'image'
                                });
                            } catch (cacheError) {
                                // 忽略缓存错误
                            }
                        } else if (response.status === 404) {
                            // 忽略404错误，不向控制台报错
                            break; // 404直接退出重试
                        }
                    } catch (error) {
                        // 忽略所有错误，不向控制台报错
                    }
                    
                    // 重试间隔1秒
                    if (!success && i < 2) {
                        await new Promise(resolve => setTimeout(resolve, 1000));
                    }
                }
                
                // 如果所有尝试都失败，显示"消息已被清理：{文件名}"
                if (!success) {
                    // 创建一个错误提示元素
                    const errorDiv = document.createElement('div');
                    errorDiv.style.cssText = `
                        background: #f8f9fa;
                        border: 1px solid #dee2e6;
                        border-radius: 8px;
                        padding: 20px;
                        text-align: center;
                        color: #6c757d;
                        font-size: 14px;
                    `;
                    errorDiv.textContent = `消息已被清理：${fileName}`;
                    
                    // 替换图片元素
                    if (img.parentNode) {
                        img.parentNode.replaceChild(errorDiv, img);
                    }
                }
            });
            
            // 处理所有音频元素
            document.querySelectorAll('.audio-element').forEach(async audio => {
                const fileUrl = audio.src;
                
                // 如果src已经是blob:开头，说明已经是本地Blob URL，无需再次处理
                if (fileUrl && fileUrl.startsWith('blob:')) {
                    return;
                }

                const fileName = audio.getAttribute('data-file-name') || '未知音频';
                const filePath = audio.getAttribute('data-file-path') || fileUrl;
                
                // 优先从IndexedDB获取音频
                try {
                    const fileData = await indexedDBManager.getFile(filePath);
                    if (fileData && fileData.data) {
                        // 从IndexedDB获取成功，转换为Blob URL
                        const blob = new Blob([fileData.data], { type: fileData.type });
                        const blobUrl = URL.createObjectURL(blob);
                        audio.src = blobUrl;
                        return;
                    }
                } catch (error) {
                    // 忽略IndexedDB错误，继续尝试从服务器获取
                }
                
                // IndexedDB中没有，尝试从服务器获取，最多重试3次
                let success = false;
                for (let i = 0; i < 3 && !success; i++) {
                    try {
                        const response = await fetch(fileUrl, {
                            credentials: 'include',
                            cache: 'no-cache'
                        });
                        
                        if (response.ok) {
                            // 请求成功，获取Blob并转换为Blob URL
                            const blob = await response.blob();
                            const blobUrl = URL.createObjectURL(blob);
                            audio.src = blobUrl;
                            success = true;
                            
                            // 缓存到IndexedDB
                            try {
                                await indexedDBManager.saveFile({
                                    id: filePath,
                                    name: fileName,
                                    type: blob.type,
                                    size: blob.size,
                                    data: blob,
                                    url: fileUrl,
                                    uploadedAt: new Date().toISOString(),
                                    fileType: 'audio'
                                });
                            } catch (cacheError) {
                                // 忽略缓存错误
                            }
                        } else if (response.status === 404) {
                            // 忽略404错误，不向控制台报错
                            break; // 404直接退出重试
                        }
                    } catch (error) {
                        // 忽略所有错误，不向控制台报错
                    }
                    
                    // 重试间隔1秒
                    if (!success && i < 2) {
                        await new Promise(resolve => setTimeout(resolve, 1000));
                    }
                }
                
                // 如果所有尝试都失败，显示"消息已被清理：{文件名}"
                if (!success) {
                    // 创建一个错误提示元素
                    const errorDiv = document.createElement('div');
                    errorDiv.style.cssText = `
                        background: #f8f9fa;
                        border: 1px solid #dee2e6;
                        border-radius: 8px;
                        padding: 20px;
                        text-align: center;
                        color: #6c757d;
                        font-size: 14px;
                    `;
                    errorDiv.textContent = `消息已被清理：${fileName}`;
                    
                    // 查找父级播放器容器
                    const playerContainer = audio.closest('.custom-audio-player');
                    if (playerContainer && playerContainer.parentNode) {
                        // 替换整个播放器容器
                        playerContainer.parentNode.replaceChild(errorDiv, playerContainer);
                    } else if (audio.parentNode) {
                        // 如果找不到容器，仅替换音频元素
                        audio.parentNode.replaceChild(errorDiv, audio);
                    }
                }
            });
        }
        
        // 处理媒体文件加载失败
        function handleMediaLoadError(media, filePath, fileName, fileType) {
            // 初始化重试计数器（仅在第一次调用时）
            if (!fileRetryCounter[filePath]) {
                fileRetryCounter[filePath] = 0;
            }
            
            // 检查是否已达到最大重试次数
            if (fileRetryCounter[filePath] >= MAX_RETRIES) {
                // 达到最大重试次数，显示已清理提示
                const errorDiv = document.createElement('div');
                errorDiv.style.cssText = `
                    background: #f8f9fa;
                    border: 1px solid #dee2e6;
                    border-radius: 8px;
                    padding: 20px;
                    text-align: center;
                    color: #6c757d;
                    font-size: 14px;
                `;
                errorDiv.textContent = `消息已被清理：${fileName}`;
                
                // 替换媒体元素
                if (media.tagName === 'AUDIO') {
                    const playerContainer = media.closest('.custom-audio-player');
                    if (playerContainer && playerContainer.parentNode) {
                        playerContainer.parentNode.replaceChild(errorDiv, playerContainer);
                    } else if (media.parentNode) {
                        media.parentNode.replaceChild(errorDiv, media);
                    }
                } else if (media.tagName === 'VIDEO') {
                    const videoContainer = media.closest('.video-container');
                    if (videoContainer && videoContainer.parentNode) {
                        videoContainer.parentNode.replaceChild(errorDiv, videoContainer);
                    } else if (media.parentNode) {
                        media.parentNode.replaceChild(errorDiv, media);
                    }
                } else if (media.parentNode) {
                    media.parentNode.replaceChild(errorDiv, media);
                }
                
                // 移除重试计数器
                delete fileRetryCounter[filePath];
                return;
            }
            
            // 增加重试计数器
            fileRetryCounter[filePath]++;
            
            // 执行获取媒体文件操作
            // 这里不再使用fetchMediaFromServer，而是直接使用新的加载逻辑
            setTimeout(() => {
                // 重新加载媒体文件
                if (media.tagName === 'IMG') {
                    media.src = media.src + '?' + new Date().getTime();
                } else if (media.tagName === 'AUDIO' || media.tagName === 'VIDEO') {
                    media.src = media.src + '?' + new Date().getTime();
                    media.load();
                }
            }, 1000); // 1秒后重试
        }
        
        // 视频播放器相关变量
        let currentVideoUrl = '';
        let currentVideoName = '';
        let currentVideoSize = 0;
        

        
        // 更多设置相关函数
        function showMoreSettings() {
            document.getElementById('more-settings-modal').style.display = 'flex';
        }

        function closeMoreSettingsModal() {
            document.getElementById('more-settings-modal').style.display = 'none';
        }

        function showChangePasswordModal() {
            document.getElementById('change-password-modal').style.display = 'flex';
        }

        function closeChangePasswordModal() {
            document.getElementById('change-password-modal').style.display = 'none';
        }

        function showChangeNameModal() {
            document.getElementById('change-name-modal').style.display = 'flex';
        }

        function closeChangeNameModal() {
            document.getElementById('change-name-modal').style.display = 'none';
        }

        function showChangeEmailModal() {
            document.getElementById('change-email-modal').style.display = 'flex';
        }

        function closeChangeEmailModal() {
            document.getElementById('change-email-modal').style.display = 'none';
        }

        // 修改密码
        function changePassword() {
            const oldPassword = document.getElementById('old-password').value;
            const newPassword = document.getElementById('new-password').value;
            const confirmPassword = document.getElementById('confirm-password').value;

            // 验证新密码和确认密码是否一致
            if (newPassword !== confirmPassword) {
                alert('两次输入的密码不同');
                return;
            }

            // 发送请求到服务器
            fetch('update_password.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                credentials: 'include',
                body: JSON.stringify({
                    old_password: oldPassword,
                    new_password: newPassword
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('密码修改成功');
                    closeChangePasswordModal();
                } else {
                    alert(data.message || '原密码不正确');
                }
            })
            .catch(error => {
                console.error('修改密码失败:', error);
                alert('修改密码失败，请重试');
            });
        }

        // 修改名称
        function changeName() {
            const newName = document.getElementById('new-name').value.trim();
            
            // 验证名称长度
            const user_name_max = <?php echo getConfig('user_name_max', 12); ?>;
            if (newName.length > user_name_max) {
                alert(`名称长度不能超过${user_name_max}个字符`);
                return;
            }
            
            // 验证名称不包含HTML代码
            if (/[<>]/.test(newName)) {
                alert('名称不能包含HTML代码');
                return;
            }

            if (!newName) {
                alert('请输入名称');
                return;
            }

            // 发送请求到服务器
            fetch('update_name.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                credentials: 'include',
                body: JSON.stringify({
                    new_name: newName
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('名称修改成功');
                    // 更新页面上的用户名显示
                    window.location.reload();
                    closeChangeNameModal();
                } else {
                    alert(data.message || '名称修改失败');
                }
            })
            .catch(error => {
                console.error('修改名称失败:', error);
                alert('修改名称失败，请重试');
            });
        }

        // 修改邮箱
        function changeEmail() {
            const newEmail = document.getElementById('new-email').value.trim();

            // 验证邮箱格式
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(newEmail)) {
                alert('请输入有效的邮箱格式');
                return;
            }

            // 发送请求到服务器
            fetch('update_email.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                credentials: 'include',
                body: JSON.stringify({
                    new_email: newEmail
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('邮箱修改成功');
                    // 更新页面上的邮箱显示
                    window.location.reload();
                    closeChangeEmailModal();
                } else {
                    alert(data.message || '邮箱修改失败');
                }
            })
            .catch(error => {
                console.error('修改邮箱失败:', error);
                alert('修改邮箱失败，请重试');
            });
        }

        // 关闭更多设置弹窗
        function closeMoreSettingsModal() {
            document.getElementById('more-settings-modal').style.display = 'none';
        }

        // 关闭修改密码弹窗
        function closeChangePasswordModal() {
            document.getElementById('change-password-modal').style.display = 'none';
        }

        // 关闭修改名称弹窗
        function closeChangeNameModal() {
            document.getElementById('change-name-modal').style.display = 'none';
        }

        // 关闭修改邮箱弹窗
        function closeChangeEmailModal() {
            document.getElementById('change-email-modal').style.display = 'none';
        }

        // 修改头像相关功能
        function showChangeAvatarModal() {
            document.getElementById('change-avatar-modal').style.display = 'flex';
        }

        function closeChangeAvatarModal() {
            document.getElementById('change-avatar-modal').style.display = 'none';
        }

        // 监听头像文件选择并显示文件名
        document.addEventListener('DOMContentLoaded', function() {
            const avatarFileInput = document.getElementById('avatar-file');
            if (avatarFileInput) {
                avatarFileInput.addEventListener('change', function(e) {
                    const file = e.target.files[0];
                    if (!file) return;
                    
                    // 检查文件类型
                    if (!file.type.match('image.*')) {
                        alert('请选择图片文件');
                        return;
                    }
                    
                    // 检查文件大小（限制为5MB）
                    const maxSize = 5 * 1024 * 1024;
                    if (file.size > maxSize) {
                        alert('图片大小不能超过5MB');
                        return;
                    }
                    
                    // 读取文件并预览
                    const reader = new FileReader();
                    reader.onload = function(event) {
                        const avatarPreview = document.getElementById('avatar-preview');
                        // 清空预览区域
                        avatarPreview.innerHTML = '';
                        // 创建预览图片
                        const img = document.createElement('img');
                        img.src = event.target.result;
                        img.style.width = '100%';
                        img.style.height = '100%';
                        img.style.objectFit = 'cover';
                        avatarPreview.appendChild(img);
                        
                        // 显示文件名
                        const fileNameDiv = document.getElementById('selected-file-name');
                        if (fileNameDiv) {
                            fileNameDiv.textContent = '已选择: ' + file.name;
                        }
                    };
                    reader.readAsDataURL(file);
                });
            }
        });

        // 修改头像
        function changeAvatar() {
            const avatarFile = document.getElementById('avatar-file').files[0];
            if (!avatarFile) {
                alert('请选择头像图片');
                return false;
            }
            
            const formData = new FormData();
            formData.append('avatar', avatarFile);
            formData.append('action', 'change_avatar');
            
            fetch('change_avatar.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('头像修改成功');
                    // 刷新页面以显示新头像
                    location.reload();
                } else {
                    alert('头像修改失败：' + data.message);
                }
            })
            .catch(error => {
                console.error('头像修改错误：', error);
                alert('头像修改失败，请重试');
            });
            
            return false;
        }

        // 截图功能
        async function takeScreenshot() {
            try {
                // 检查navigator.mediaDevices和getDisplayMedia是否可用
                if (!navigator.mediaDevices || !navigator.mediaDevices.getDisplayMedia) {
                    alert('截图功能不可用：您的浏览器不支持屏幕捕获');
                    return;
                }
                
                // 请求屏幕捕获
                const stream = await navigator.mediaDevices.getDisplayMedia({
                    video: { cursor: 'always' },
                    audio: false
                });
                
                // 创建视频元素来显示流
                const video = document.createElement('video');
                video.srcObject = stream;
                
                // 使用Promise确保视频元数据加载完成
                await new Promise((resolve) => {
                    video.onloadedmetadata = resolve;
                });
                
                // 播放视频
                await video.play();
                
                // 创建Canvas元素
                const canvas = document.createElement('canvas');
                canvas.width = video.videoWidth;
                canvas.height = video.videoHeight;
                
                // 绘制视频帧到Canvas
                const ctx = canvas.getContext('2d');
                ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                
                // 停止流
                stream.getTracks().forEach(track => track.stop());
                
                // 将Canvas转换为Blob，使用Promise处理
                const blob = await new Promise((resolve) => {
                    canvas.toBlob(resolve, 'image/png');
                });
                
                if (blob) {
                    // 创建文件对象
                    const screenshotFile = new File([blob], `screenshot_${Date.now()}.png`, {
                        type: 'image/png'
                    });
                    
                    // 创建DataTransfer对象
                    const dataTransfer = new DataTransfer();
                    dataTransfer.items.add(screenshotFile);
                    
                    // 将文件添加到file-input中
                    const fileInput = document.getElementById('file-input');
                    if (fileInput) {
                        fileInput.files = dataTransfer.files;
                        
                        // 触发change事件，自动提交表单
                        const event = new Event('change', { bubbles: true });
                        fileInput.dispatchEvent(event);
                    } else {
                        console.error('未找到file-input元素');
                        alert('截图失败：未找到文件输入元素');
                    }
                } else {
                    console.error('Canvas转换为Blob失败');
                    alert('截图失败：无法处理截图数据');
                }
            } catch (error) {
                console.error('截图失败:', error);
                // 根据错误类型提供更具体的提示
                if (error.name === 'NotAllowedError') {
                    alert('截图失败：您拒绝了屏幕捕获请求');
                } else if (error.name === 'NotFoundError') {
                    alert('截图失败：未找到可捕获的屏幕');
                } else if (error.name === 'NotReadableError') {
                    alert('截图失败：无法访问屏幕内容');
                } else {
                    alert(`截图失败：${error.message || '请重试'}`);
                }
            }
        }

        // 初始化视频播放器
        function initVideoPlayer() {
            console.log('开始初始化视频播放器...');
            
            // 获取视频元素和控件
            const videoElement = document.getElementById('custom-video-element');
            const playBtn = document.getElementById('video-play-btn');
            const progressBar = document.getElementById('video-progress-bar');
            const progress = document.getElementById('video-progress');
            const currentTimeEl = document.querySelector('.video-time.current-time');
            const totalTimeEl = document.querySelector('.video-time.total-time');
            const muteBtn = document.getElementById('video-mute-btn');
            const volumeSlider = document.getElementById('volume-slider');
            const videoControls = document.querySelector('.video-controls');
            const videoPlayer = document.querySelector('.custom-video-player');
            const videoHeader = document.querySelector('.video-player-header');
            
            // 检查必要元素是否存在
            console.log('元素检查结果:');
            console.log('videoElement:', !!videoElement);
            console.log('playBtn:', !!playBtn);
            console.log('progressBar:', !!progressBar);
            console.log('progress:', !!progress);
            console.log('currentTimeEl:', !!currentTimeEl);
            console.log('totalTimeEl:', !!totalTimeEl);
            console.log('muteBtn:', !!muteBtn);
            console.log('volumeSlider:', !!volumeSlider);
            console.log('videoControls:', !!videoControls);
            console.log('videoPlayer:', !!videoPlayer);
            console.log('videoHeader:', !!videoHeader);
            
            if (!videoElement || !playBtn || !progressBar || !progress || !currentTimeEl || !totalTimeEl || !muteBtn || !volumeSlider || !videoControls || !videoPlayer || !videoHeader) {
                console.error('视频播放器初始化失败：缺少必要元素');
                return;
            }
            
            console.log('所有必要元素已找到，开始绑定事件...');
            
            // 添加CSS过渡效果
            const style = document.createElement('style');
            style.textContent = `
                .video-controls {
                    transition: opacity 0.3s ease, transform 0.3s ease;
                    opacity: 1;
                    transform: translateY(0);
                }
                .video-controls.hidden {
                    opacity: 0;
                    transform: translateY(20px);
                    pointer-events: none;
                }
                .video-player-header {
                    transition: opacity 0.3s ease, transform 0.3s ease;
                    opacity: 1;
                    transform: translateY(0);
                }
                .video-player-header.hidden {
                    opacity: 0;
                    transform: translateY(-20px);
                    pointer-events: none;
                }
            `;
            document.head.appendChild(style);
            
            // 移除默认控件（如果存在）
            if (videoElement.hasAttribute('controls')) {
                videoElement.removeAttribute('controls');
            }
            
            // 播放/暂停控制 - 直接在HTML中添加onclick属性，确保事件能被正确触发
            playBtn.onclick = function(event) {
                console.log('播放/暂停按钮被点击! 当前状态:', videoElement.paused ? '已暂停' : '播放中');
                event.stopPropagation(); // 防止事件冒泡
                
                if (videoElement.paused) {
                    videoElement.play().catch(error => {
                        console.error('播放视频失败:', error);
                    });
                    playBtn.textContent = '⏸';
                    console.log('切换为播放状态');
                } else {
                    videoElement.pause();
                    playBtn.textContent = '▶';
                    console.log('切换为暂停状态');
                }
            };
            
            // 也添加一个事件监听器作为备份
            playBtn.addEventListener('click', function(event) {
                console.log('播放/暂停按钮事件监听器被触发!');
                // 这里不执行实际操作，只是作为调试用
            });
            
            // 确保按钮没有被其他元素覆盖
            console.log('播放按钮尺寸:', playBtn.offsetWidth, 'x', playBtn.offsetHeight);
            console.log('播放按钮位置:', playBtn.getBoundingClientRect());
            console.log('播放按钮z-index:', window.getComputedStyle(playBtn).zIndex);
            
            // 视频播放状态变化
            videoElement.addEventListener('play', function() {
                playBtn.textContent = '⏸';
            });
            
            videoElement.addEventListener('pause', function() {
                playBtn.textContent = '▶';
            });
            
            // 本地formatTime函数，避免被其他formatTime函数覆盖
            function formatTime(seconds) {
                if (isNaN(seconds)) return '0:00';
                const mins = Math.floor(seconds / 60);
                const secs = Math.floor(seconds % 60);
                return `${mins}:${secs.toString().padStart(2, '0')}`;
            }
            
            // 设置视频时长
            videoElement.addEventListener('loadedmetadata', function() {
                totalTimeEl.textContent = formatTime(videoElement.duration);
            });
            
            // 更新进度条和时间
            videoElement.addEventListener('timeupdate', function() {
                // 确保duration有效才计算进度
                if (isNaN(videoElement.duration) || videoElement.duration <= 0) {
                    return;
                }
                const progressPercent = (videoElement.currentTime / videoElement.duration) * 100;
                progress.style.width = progressPercent + '%';
                currentTimeEl.textContent = formatTime(videoElement.currentTime);
            });
            
            // 进度条点击跳转到指定位置
            progressBar.onclick = function(e) {
                // 确保duration有效才允许跳转
                if (isNaN(videoElement.duration) || videoElement.duration <= 0) {
                    return;
                }
                const progressWidth = progressBar.clientWidth;
                const clickX = e.offsetX;
                const duration = videoElement.duration;
                
                videoElement.currentTime = (clickX / progressWidth) * duration;
            };
            
            // 音量控制
            volumeSlider.oninput = function() {
                videoElement.volume = this.value;
                if (this.value === 0) {
                    muteBtn.textContent = '🔇';
                } else {
                    muteBtn.textContent = '🔊';
                }
            };
            
            // 静音切换
            muteBtn.onclick = function() {
                if (videoElement.volume > 0) {
                    volumeSlider.value = 0;
                    videoElement.volume = 0;
                    muteBtn.textContent = '🔇';
                } else {
                    volumeSlider.value = 1;
                    videoElement.volume = 1;
                    muteBtn.textContent = '🔊';
                }
            };
            
            // 视频结束时重置
            videoElement.addEventListener('ended', function() {
                playBtn.textContent = '▶';
                videoElement.currentTime = 0;
            });
            
            // 鼠标移动显示/隐藏控件逻辑
            let hideControlsTimer;
            const showControls = () => {
                // 清除之前的计时器
                clearTimeout(hideControlsTimer);
                
                // 显示控件
                videoControls.classList.remove('hidden');
                videoHeader.classList.remove('hidden');
                
                // 设置3秒后隐藏控件（只有在播放状态且非全屏时）
                hideControlsTimer = setTimeout(() => {
                    if (!videoElement.paused && !document.fullscreenElement) {
                        videoControls.classList.add('hidden');
                        videoHeader.classList.add('hidden');
                    }
                }, 3000);
            };
            
            // 鼠标移动事件
            videoPlayer.addEventListener('mousemove', showControls);
            videoPlayer.addEventListener('mouseenter', showControls);
            
            // 全屏变化事件
            document.addEventListener('fullscreenchange', () => {
                if (document.fullscreenElement) {
                    // 进入全屏，隐藏标题栏，显示控制按钮
                    videoControls.classList.remove('hidden');
                    videoHeader.classList.add('hidden');
                    clearTimeout(hideControlsTimer);
                    
                    // 确保视频元素充满整个屏幕
                    videoPlayer.style.height = '100%';
                    videoElement.style.height = '100%';
                    videoElement.style.width = '100%';
                } else {
                    // 退出全屏，显示标题栏，恢复自动隐藏逻辑
                    videoControls.classList.remove('hidden');
                    videoHeader.classList.remove('hidden');
                    if (!videoElement.paused) {
                        hideControlsTimer = setTimeout(() => {
                            videoControls.classList.add('hidden');
                            videoHeader.classList.add('hidden');
                        }, 3000);
                    }
                    
                    // 恢复原始尺寸
                    videoPlayer.style.height = '';
                    videoElement.style.height = '';
                    videoElement.style.width = '';
                }
            });
            
            // 初始状态：显示控件
            showControls();
        }
        
        // 打开视频播放器
        // 打开视频播放器
        async function openVideoPlayer(videoUrl, videoName, videoSize) {
            const videoModal = document.getElementById('video-player-modal');
            const videoElement = document.getElementById('custom-video-element');
            const videoTitle = document.getElementById('video-player-title');
            const cacheStatus = document.getElementById('video-cache-status');
            
            // 设置当前视频信息
            currentVideoUrl = videoUrl;
            currentVideoName = videoName;
            currentVideoSize = videoSize;
            
            // 更新视频标题
            videoTitle.textContent = videoName;
            
            // 显示视频播放器弹窗
            videoModal.classList.add('visible');
            
            // 优先从IndexedDB获取视频
            try {
                const fileData = await indexedDBManager.getFile(videoUrl);
                if (fileData && fileData.data) {
                    // 从IndexedDB获取成功，转换为Blob URL
                    const blob = new Blob([fileData.data], { type: fileData.type });
                    const blobUrl = URL.createObjectURL(blob);
                    videoElement.src = blobUrl;
                    videoElement.play().catch(error => {
                        console.error('播放视频失败:', error);
                        // 播放失败时保持视频源不变，等待用户手动点击
                        videoElement.pause();
                    });
                    // 不显示缓存状态
                    cacheStatus.style.display = 'none';
                    return;
                }
            } catch (error) {
                // 忽略IndexedDB错误，继续尝试其他方式
                console.error('从IndexedDB获取视频失败:', error);
            }
            
            // 如果IndexedDB中没有，检查是否已经缓存（兼容旧版本）
            if (!isFileExpired(videoUrl, 'video')) {
                console.log('视频已缓存，直接使用URL播放');
                // 视频已缓存，直接使用URL播放，不重新缓存
                videoElement.src = videoUrl;
                videoElement.play().catch(error => {
                    console.error('播放视频失败:', error);
                    // 播放失败时保持视频源不变，等待用户手动点击
                    videoElement.pause();
                });
                // 不显示缓存状态
                cacheStatus.style.display = 'none';
            } else {
                // 显示缓存状态
                const cacheFileName = document.getElementById('cache-file-name');
                cacheFileName.textContent = videoName;
                cacheStatus.style.display = 'block';
                
                // 设置顶部缓存状态的文件名
                const topCacheFileName = document.getElementById('top-cache-file-name');
                topCacheFileName.textContent = videoName;
                
                // 初始化缓存状态
                updateCacheStatus(0, 0, 0, 0, videoSize);
                
                // 缓存视频并播放
                cacheVideo(videoUrl, videoName, videoSize, videoElement, cacheStatus);
            }
        }
        
        // 缓存完整视频
        async function cacheVideo(videoUrl, videoName, videoSize, videoElement, cacheStatus) {
            // 检查是否已有缓存进程在运行
            if (isCaching) {
                console.log('已有缓存进程在运行，跳过当前缓存');
                cacheStatus.style.display = 'none';
                return;
            }
            
            // 检查视频URL是否有效
            if (!videoUrl) {
                console.error('无效的视频URL');
                cacheStatus.style.display = 'none';
                showNotification('缓存视频失败：无效的视频URL', 'error');
                return;
            }
            
            // 优先从IndexedDB获取视频
            try {
                const fileData = await indexedDBManager.getFile(videoUrl);
                if (fileData && fileData.data) {
                    // 从IndexedDB获取成功，转换为Blob URL
                    const blob = new Blob([fileData.data], { type: fileData.type });
                    const blobUrl = URL.createObjectURL(blob);
                    videoElement.src = blobUrl;
                    videoElement.play().catch(error => {
                        console.error('播放视频失败:', error);
                        // 播放失败时保持视频源不变，等待用户手动点击
                        videoElement.pause();
                    });
                    cacheStatus.style.display = 'none';
                    isCaching = false;
                    return;
                }
            } catch (error) {
                // 忽略IndexedDB错误，继续尝试其他方式
                console.error('从IndexedDB获取视频失败:', error);
            }
            
            // 如果IndexedDB中没有，检查是否已经缓存（兼容旧版本）
            if (!isFileExpired(videoUrl, 'video')) {
                console.log('视频已缓存，直接使用缓存播放');
                // 直接使用视频URL，浏览器会自动使用缓存
                videoElement.src = videoUrl;
                videoElement.play().catch(error => {
                    console.error('播放视频失败:', error);
                    // 播放失败时保持视频源不变，等待用户手动点击
                    videoElement.pause();
                });
                cacheStatus.style.display = 'none';
                isCaching = false;
                return;
            }
            
            // 设置缓存标志为true
            isCaching = true;
            
            let downloadedBytes = 0;
            let startTime = Date.now();
            let lastTime = Date.now();
            let lastDownloaded = 0;
            
            // 禁用视频播放，直到缓存完成
            videoElement.pause();
            // 保存原视频URL，以便出错时恢复
            const originalSrc = videoUrl;
            
            fetch(videoUrl, {
                credentials: 'include',
                cache: 'force-cache'
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                
                // 获取视频总大小
                const totalBytes = parseInt(response.headers.get('content-length') || '0');
                
                // 更新总进度
                updateCacheStatus(0, 0, 0, totalBytes, videoSize);
                
                // 创建读取器
                const reader = response.body.getReader();
                const chunks = [];
                
                // 读取数据
                return reader.read().then(function processChunk({ done, value }) {
                    if (done) {
                        // 下载完成
                        const blob = new Blob(chunks, { type: 'video/mp4' });
                        const blobUrl = URL.createObjectURL(blob);
                        
                        // 设置视频源为缓存的blob URL
                        videoElement.src = blobUrl;
                        
                        // 开始播放，使用catch处理可能的播放失败
                        videoElement.play().catch(error => {
                            console.error('播放视频失败:', error);
                            // 如果播放失败，尝试直接使用原URL
                            videoElement.src = originalSrc;
                            videoElement.load();
                        });
                        
                        // 更新缓存状态为100%
                        updateCacheStatus(100, 0, totalBytes, totalBytes, videoSize);
                        
                        // 隐藏缓存状态
                        setTimeout(() => {
                            cacheStatus.style.display = 'none';
                        }, 1000);
                        
                        // 设置localStorage缓存信息
                        const fileType = 'video';
                        setFileCache(videoUrl, fileType, totalBytes);
                        
                        // 重置缓存标志
                        isCaching = false;
                        
                        return blobUrl;
                    }
                    
                    // 添加到chunks
                    chunks.push(value);
                    downloadedBytes += value.length;
                    
                    // 计算进度和速度
                    const percentage = Math.round((downloadedBytes / totalBytes) * 100);
                    const currentTime = Date.now();
                    const elapsed = (currentTime - startTime) / 1000;
                    const speed = elapsed > 0 ? Math.round(downloadedBytes / elapsed / 1024) : 0; // KB/s
                    
                    // 更新缓存状态
                    updateCacheStatus(percentage, speed, downloadedBytes, totalBytes, videoSize);
                    
                    // 保存当前状态
                    lastDownloaded = downloadedBytes;
                    lastTime = currentTime;
                    
                    // 继续读取
                    return reader.read().then(processChunk);
                });
            })
            .catch(error => {
                console.error('视频缓存失败:', error);
                cacheStatus.style.display = 'none';
                showNotification('视频缓存失败，无法播放', 'error');
                
                // 重置缓存标志
                isCaching = false;
                // 恢复视频源
                videoElement.src = originalSrc;
                videoElement.load();
            });
        }
        
        // 更新缓存状态
        function updateCacheStatus(percentage, speed, loaded, total, fileSize) {
            // 更新播放器内的缓存状态
            const cachePercentage = document.getElementById('cache-percentage');
            const cacheSpeed = document.getElementById('cache-speed');
            const cacheSize = document.getElementById('cache-size');
            const cacheTotalSize = document.getElementById('cache-total-size');
            
            // 更新百分比
            cachePercentage.textContent = `${percentage}%`;
            
            // 更新速度，转换为MB/s
            const speedMB = (speed / 1024).toFixed(2);
            cacheSpeed.textContent = `${speedMB} MB/s`;
            
            // 更新已缓存大小和总大小
            const loadedMB = total > 0 ? (loaded / (1024 * 1024)).toFixed(2) : '0.00';
            const totalMB = total > 0 ? (total / (1024 * 1024)).toFixed(2) : '0.00';
            cacheSize.textContent = `${loadedMB} MB`;
            cacheTotalSize.textContent = `${totalMB} MB`;
            
            // 更新页面顶部的缓存状态
            const topCacheStatus = document.getElementById('top-video-cache-status');
            const topCachePercentage = document.getElementById('top-cache-percentage');
            const topCacheProgressBar = document.getElementById('top-cache-progress-bar');
            const topCacheSpeed = document.getElementById('top-cache-speed');
            const topCacheSize = document.getElementById('top-cache-size');
            const topCacheTotalSize = document.getElementById('top-cache-total-size');
            
            if (percentage > 0 && percentage < 100) {
                topCacheStatus.style.display = 'block';
            }
            
            topCachePercentage.textContent = `${percentage}%`;
            topCacheProgressBar.style.width = `${percentage}%`;
            topCacheSpeed.textContent = `${speedMB} MB/s`;
            topCacheSize.textContent = `${loadedMB} MB`;
            topCacheTotalSize.textContent = `${totalMB} MB`;
            
            // 如果缓存完成，隐藏顶部缓存状态
            if (percentage === 100) {
                setTimeout(() => {
                    topCacheStatus.style.display = 'none';
                }, 1000);
            }
        }
        
        // 切换视频全屏
        function toggleVideoFullscreen() {
            const videoPlayer = document.querySelector('.video-player-content');
            
            if (!document.fullscreenElement) {
                // 进入全屏
                videoPlayer.requestFullscreen().catch(err => {
                    console.error(`Error attempting to enable fullscreen: ${err.message}`);
                });
            } else {
                // 退出全屏
                if (document.exitFullscreen) {
                    document.exitFullscreen();
                }
            }
        }
        
        // 关闭视频播放器
        function closeVideoPlayer() {
            const videoModal = document.getElementById('video-player-modal');
            const videoElement = document.getElementById('custom-video-element');
            const playBtn = document.getElementById('video-play-btn');
            const progress = document.getElementById('video-progress');
            const currentTimeEl = document.querySelector('.video-time.current-time');
            
            // 暂停视频
            videoElement.pause();
            
            // 重置播放按钮和进度条
            playBtn.textContent = '▶';
            progress.style.width = '0%';
            currentTimeEl.textContent = '0:00';
            
            // 停止缓存
            if (isCaching) {
                isCaching = false;
                console.log('视频缓存已停止');
            }
            
            // 隐藏缓存状态
            const cacheStatus = document.getElementById('video-cache-status');
            if (cacheStatus) {
                cacheStatus.style.display = 'none';
            }
            
            // 隐藏顶部缓存状态
            const topCacheStatus = document.getElementById('top-video-cache-status');
            if (topCacheStatus) {
                topCacheStatus.style.display = 'none';
            }
            
            // 隐藏视频播放器弹窗
            videoModal.classList.remove('visible');
        }
        
        // 添加下载任务并立即下载
        function addDownloadTask(fileName, filePath, fileSize, fileType) {
            console.log('开始下载文件:', fileName, filePath, fileSize, fileType);
            
            // 使用a标签下载文件
            const a = document.createElement('a');
            a.href = filePath;
            a.download = fileName;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        }
        
        // 下载当前视频
        function downloadCurrentVideo() {
            if (currentVideoUrl && currentVideoName) {
                addDownloadTask(currentVideoName, currentVideoUrl, currentVideoSize, 'video');
            }
        }
        
        // 播放压缩视频
        function playCompressedVideo(videoUrl, videoName) {
            try {
                const videoModal = document.getElementById('video-player-modal');
                const videoElement = document.getElementById('custom-video-element');
                const videoTitle = document.getElementById('video-player-title');
                const cacheStatus = document.getElementById('video-cache-status');
                
                // 设置当前视频信息
                currentVideoUrl = videoUrl;
                currentVideoName = videoName;
                currentVideoSize = 0; // 假设视频大小未知
                
                // 更新视频标题
                videoTitle.textContent = videoName;
                
                // 显示视频播放器弹窗
                videoModal.classList.add('visible');
                
                // 检查是否已经缓存，避免二次缓存
                if (typeof isFileExpired !== 'undefined' && !isFileExpired(videoUrl, 'video')) {
                    console.log('视频已缓存，使用Blob URL播放');
                    // 使用fetch请求获取缓存的视频，然后创建Blob URL播放
                    fetch(videoUrl, {
                        credentials: 'include',
                        cache: 'force-cache'
                    })
                    .then(response => response.blob())
                    .then(blob => {
                        const blobUrl = URL.createObjectURL(blob);
                        videoElement.src = blobUrl;
                        videoElement.play().catch(error => {
                            console.error('播放视频失败:', error);
                            // 如果播放失败，尝试直接使用原URL
                            videoElement.src = videoUrl;
                            videoElement.load();
                        });
                        // 不显示缓存状态
                        cacheStatus.style.display = 'none';
                    })
                    .catch(error => {
                        console.error('获取缓存视频失败:', error);
                        // 发生错误时，降级使用服务器链接
                        videoElement.src = videoUrl;
                        videoElement.play().catch(error => {
                            console.error('播放视频失败:', error);
                        });
                        cacheStatus.style.display = 'none';
                    });
                } else {
                    // 显示缓存状态
                    const cacheFileName = document.getElementById('cache-file-name');
                    cacheFileName.textContent = videoName;
                    cacheStatus.style.display = 'block';
                    
                    // 初始化缓存状态
                    videoElement.src = videoUrl;
                    
                    // 调用cacheVideo函数进行缓存
                    if (typeof cacheVideo !== 'undefined') {
                        cacheVideo(videoUrl, videoName, 0, videoElement, cacheStatus);
                    } else {
                        // 如果cacheVideo函数不存在，直接播放
                        videoElement.play().catch(error => {
                            console.error('播放视频失败:', error);
                        });
                        cacheStatus.style.display = 'none';
                    }
                }
            } catch (error) {
                console.error('创建视频播放器失败:', error);
                // 显示错误提示，但不影响整体UI
                alert('无法播放视频，请尝试下载原画质视频');
            }
        }
        
        // 下载原画质视频
        function downloadOriginalVideo(videoUrl, videoName) {
            // 使用浏览器直接下载
            const link = document.createElement('a');
            link.href = videoUrl;
            link.download = videoName;
            link.style.display = 'none';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
        
        // 为视频缩略图添加点击事件监听器
        function initVideoElements() {
            // 为已有的视频缩略图添加点击事件
            document.querySelectorAll('.video-thumbnail').forEach(thumbnail => {
                thumbnail.addEventListener('click', function() {
                    const videoUrl = this.getAttribute('data-file-path');
                    const videoName = this.getAttribute('data-file-name');
                    playCompressedVideo(videoUrl, videoName);
                });
            });
        }
        
        // 初始化视频元素，将视频URL转换为Blob URL
        async function initChatVideos() {
            document.querySelectorAll('.video-element').forEach(async video => {
                // 只处理没有src或src为空的视频元素
                if (!video.src || video.src === '') {
                    const fileUrl = video.getAttribute('data-file-url');
                    const fileName = video.getAttribute('data-file-name');
                    const filePath = video.getAttribute('data-file-path');
                    
                    if (fileUrl) {
                        // 优先从IndexedDB获取视频
                        try {
                            const fileData = await indexedDBManager.getFile(filePath);
                            if (fileData && fileData.data) {
                                // 从IndexedDB获取成功，转换为Blob URL
                                const blob = new Blob([fileData.data], { type: fileData.type });
                                const blobUrl = URL.createObjectURL(blob);
                                video.src = blobUrl;
                                return;
                            }
                        } catch (error) {
                            // 忽略IndexedDB错误，继续尝试从服务器获取
                        }
                        
                        // IndexedDB中没有，尝试从服务器获取，最多重试3次
                        let success = false;
                        for (let i = 0; i < 3 && !success; i++) {
                            try {
                                const response = await fetch(fileUrl, {
                                    credentials: 'include',
                                    cache: 'no-cache'
                                });
                                
                                if (response.ok) {
                                    // 请求成功，获取Blob并转换为Blob URL
                                    const blob = await response.blob();
                                    const blobUrl = URL.createObjectURL(blob);
                                    video.src = blobUrl;
                                    success = true;
                                    
                                    // 缓存到IndexedDB
                                    try {
                                        await indexedDBManager.saveFile({
                                            id: filePath,
                                            name: fileName,
                                            type: blob.type,
                                            size: blob.size,
                                            data: blob,
                                            url: fileUrl,
                                            uploadedAt: new Date().toISOString(),
                                            fileType: 'video'
                                        });
                                    } catch (cacheError) {
                                        // 忽略缓存错误
                                    }
                                } else if (response.status === 404) {
                                    // 忽略404错误，不向控制台报错
                                    break; // 404直接退出重试
                                }
                            } catch (error) {
                                // 忽略所有错误，不向控制台报错
                            }
                            
                            // 重试间隔1秒
                            if (!success && i < 2) {
                                await new Promise(resolve => setTimeout(resolve, 1000));
                            }
                        }
                        
                        // 如果所有尝试都失败，显示"消息已被清理：{文件名}"
                        if (!success) {
                            // 创建一个错误提示元素
                            const errorDiv = document.createElement('div');
                            errorDiv.style.cssText = `
                                background: #f8f9fa;
                                border: 1px solid #dee2e6;
                                border-radius: 8px;
                                padding: 20px;
                                text-align: center;
                                color: #6c757d;
                                font-size: 14px;
                            `;
                            errorDiv.textContent = `消息已被清理：${fileName}`;
                            
                            // 查找父级视频容器并替换
                            const videoContainer = video.closest('.video-container');
                            if (videoContainer && videoContainer.parentNode) {
                                videoContainer.parentNode.replaceChild(errorDiv, videoContainer);
                            } else if (video.parentNode) {
                                // 如果找不到容器，仅替换视频元素
                                video.parentNode.replaceChild(errorDiv, video);
                            }
                        }
                    }
                }
            });
        }
        
        // 在DOM加载完成后初始化聊天视频
        document.addEventListener('DOMContentLoaded', function() {
            initChatVideos();
        });
        
        // 录音功能
        let mediaRecorder = null;
        let audioChunks = [];
        let isRecording = false;
        
        function toggleRecording() {
            const recordBtn = document.getElementById('record-btn');
            
            if (!isRecording) {
                // 开始录音
                startRecording();
                recordBtn.style.color = '#ff4757';
                recordBtn.style.transform = 'scale(1.2)';
            } else {
                // 停止录音
                stopRecording();
                recordBtn.style.color = '#666';
                recordBtn.style.transform = 'scale(1)';
            }
        }
        
        function startRecording() {
            navigator.mediaDevices.getUserMedia({ audio: true })
                .then(stream => {
                    mediaRecorder = new MediaRecorder(stream);
                    audioChunks = [];
                    
                    mediaRecorder.ondataavailable = event => {
                        if (event.data.size > 0) {
                            audioChunks.push(event.data);
                        }
                    };
                    
                    mediaRecorder.onstop = () => {
                        const audioBlob = new Blob(audioChunks, { type: 'audio/webm' });
                        const audioUrl = URL.createObjectURL(audioBlob);
                        
                        // 创建录音文件名（使用简单格式：录音_时间.webm）
                        const now = new Date();
                        const timeStr = `${now.getFullYear()}${(now.getMonth()+1).toString().padStart(2,'0')}${now.getDate().toString().padStart(2,'0')}_${now.getHours().toString().padStart(2,'0')}${now.getMinutes().toString().padStart(2,'0')}${now.getSeconds().toString().padStart(2,'0')}`;
                        const fileName = `录音_${timeStr}.webm`;
                        
                        // 创建File对象
                        const audioFile = new File([audioBlob], fileName, { type: 'audio/webm' });
                        
                        // 发送录音文件，传入blobUrl以便立即播放
                        sendFile(audioFile, audioUrl);
                        
                        console.log('录音完成并发送', audioUrl);
                    };
                    
                    mediaRecorder.start();
                    isRecording = true;
                })
                .catch(error => {
                    console.error('获取麦克风权限失败:', error);
                    alert('无法获取麦克风权限，请检查浏览器设置');
                });
        }
        
        function stopRecording() {
            if (mediaRecorder && isRecording) {
                mediaRecorder.stop();
                isRecording = false;
                
                // 停止所有音轨
                mediaRecorder.stream.getTracks().forEach(track => track.stop());
            }
        }
        
        // 显示群聊成员
        function showGroupMembers(groupId, event) {
            if (event) {
                event.stopPropagation(); // 阻止事件冒泡，避免关闭菜单
            }
            
            const modal = document.getElementById('group-members-modal');
            const membersList = document.getElementById('group-members-list');
            
            // 清空成员列表
            membersList.innerHTML = '<p style="text-align: center; color: #666;">加载中...</p>';
            
            // 显示弹窗
            modal.style.display = 'flex';
            
            // 从服务器获取群聊成员
            fetch(`get_group_members.php?group_id=${groupId}`, {
                credentials: 'include' // 包含cookie
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // 渲染成员列表（横列显示）
                    let membersHtml = '<div style="display: flex; flex-direction: column; gap: 12px;">';
                    data.members.forEach(member => {
                        // 确定职位和对应样式
                        let position = '成员';
                        let positionStyle = 'background: rgba(67, 160, 71, 0.1); color: #43a047;'; // 成员样式
                        if (member.id === data.group_owner_id) {
                            position = '群主';
                            positionStyle = 'background: rgba(229, 57, 53, 0.1); color: #e53935;'; // 群主样式
                        } else if (member.is_admin) {
                            position = '管理员';
                            positionStyle = 'background: rgba(251, 140, 0, 0.1); color: #fb8c00;'; // 管理员样式
                        }
                        
                        // 检查是否是当前用户
                        const isCurrentUser = member.id === data.current_user_id;
                        // 检查是否是好友
                        const isFriend = member.friendship_status === 'friends';
                        
                        // 生成操作菜单HTML
                        let actionsMenu = '';
                        if (!isCurrentUser && (!data.all_user_group || data.is_owner || data.is_admin)) {
                            actionsMenu = '<div style="position: relative;">' +
                                '<button class="group-member-actions-btn" onclick="toggleMemberActionsMenu(event, ' + groupId + ', ' + member.id + ', ' + member.is_admin + ', ' + isFriend + ', \'' + member.username + '\')" style="' +
                                    'background: none;' +
                                    'border: none;' +
                                    'width: 36px;' +
                                    'height: 36px;' +
                                    'border-radius: 50%;' +
                                    'display: flex;' +
                                    'align-items: center;' +
                                    'justify-content: center;' +
                                    'cursor: pointer;' +
                                    'font-size: 18px;' +
                                    'color: var(--text-secondary);' +
                                    'transition: all 0.2s;' +
                                    'z-index: 1000;' +
                                '">' +
                                    '•••' +
                                '</button>' +
                                '<div id="member-actions-menu-' + member.id + '" class="member-actions-menu" style="' +
                                    'display: none;' +
                                    'position: absolute;' +
                                    'right: 0;' +
                                    'top: 40px;' +
                                    'background: var(--modal-bg);' +
                                    'border: 1px solid var(--border-color);' +
                                    'border-radius: 8px;' +
                                    'box-shadow: 0 4px 12px var(--shadow-color);' +
                                    'padding: 8px 0;' +
                                    'min-width: 120px;' +
                                    'z-index: 1001;' +
                                    'backdrop-filter: blur(10px);' +
                                '">';
                            
                            // 添加好友按钮
                            if (!isFriend) {
                                actionsMenu += '<div class="member-action-item" onclick="addFriend(' + member.id + ', \'' + member.username + '\'); closeMemberActionsMenu(' + member.id + ')" style="' +
                                    'padding: 10px 16px;' +
                                    'cursor: pointer;' +
                                    'font-size: 14px;' +
                                    'color: var(--text-color);' +
                                    'transition: background-color 0.2s;' +
                                '">添加好友</div>';
                            }
                            
                            // 踢出按钮
                            if (!data.all_user_group && (data.is_owner || (data.is_admin && !member.is_admin))) {
                                actionsMenu += '<div class="member-action-item" onclick="kickMember(' + groupId + ', ' + member.id + '); closeMemberActionsMenu(' + member.id + ')" style="' +
                                    'padding: 10px 16px;' +
                                    'cursor: pointer;' +
                                    'font-size: 14px;' +
                                    'color: var(--danger-color);' +
                                    'transition: background-color 0.2s;' +
                                '">踢出</div>';
                            }
                            
                            // 设为管理员按钮
                            if (!isCurrentUser && data.is_owner && !member.is_admin) {
                                actionsMenu += '<div class="member-action-item" onclick="setGroupAdmin(' + groupId + ', ' + member.id + ', true); closeMemberActionsMenu(' + member.id + ')" style="' +
                                    'padding: 10px 16px;' +
                                    'cursor: pointer;' +
                                    'font-size: 14px;' +
                                    'color: #4CAF50;' +
                                    'transition: background-color 0.2s;' +
                                '">设为管理员</div>';
                            }
                            
                            // 取消管理员按钮
                            if (!isCurrentUser && data.is_owner && member.is_admin) {
                                actionsMenu += '<div class="member-action-item" onclick="setGroupAdmin(' + groupId + ', ' + member.id + ', false); closeMemberActionsMenu(' + member.id + ')" style="' +
                                    'padding: 10px 16px;' +
                                    'cursor: pointer;' +
                                    'font-size: 14px;' +
                                    'color: #ff9800;' +
                                    'transition: background-color 0.2s;' +
                                '">取消管理员</div>';
                            }
                            
                            actionsMenu += '</div>' +
                                '</div>';
                        }
                        
                        // 生成头像HTML
                        let avatarHtml = '';
                        if (member.avatar && member.avatar !== 'deleted_user' && member.avatar !== 'x') {
                            avatarHtml = '<img src="' + member.avatar + '" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">';
                        } else {
                            avatarHtml = member.username.substring(0, 2);
                        }
                        
                        // 生成成员项HTML
                        membersHtml += '<div style="display: flex; align-items: center; justify-content: space-between; padding: 16px; background: var(--hover-bg); border-radius: 10px; gap: 16px;">' +
                            '<div style="display: flex; align-items: center; gap: 16px; flex: 1;">' +
                                '<div style="width: 56px; height: 56px; border-radius: 50%; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; font-size: 22px;">' +
                                    avatarHtml +
                                '</div>' +
                                '<div style="flex: 1;">' +
                                    '<div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">' +
                                        '<span style="font-size: 16px; font-weight: 600; color: var(--text-color);">' + member.username + '</span>' +
                                        '<span style="padding: 2px 8px; border-radius: 10px; font-size: 12px; font-weight: 500; ' + positionStyle + '">' + position + '</span>' +
                                    '</div>' +
                                    '<div style="font-size: 14px; color: var(--text-secondary); font-weight: 500;">' + (member.email || member.status || '离线') + '</div>' +
                                '</div>' +
                            '</div>' +
                            actionsMenu +
                        '</div>';
                    });
                    
                    // 添加样式
                    membersHtml += '<style>' +
                        '/* 成员操作菜单样式 */' +
                        '.member-action-item:hover {' +
                            'background-color: var(--hover-bg) !important;' +
                        '}' +
                        '' +
                        '/* 确保删除好友UI优先显示 */' +
                        '.friend-menu {' +
                            'z-index: 2000 !important;' +
                        '}' +
                    '</style>';
                    
                    membersHtml += '</div>';
                    membersList.innerHTML = membersHtml;
                } else {
                    membersList.innerHTML = '<p style="text-align: center; color: #ff4d4f;">' + (data.message || '获取成员列表失败') + '</p>';
                }
            })
            .catch(error => {
                console.error('获取群聊成员失败:', error);
                membersList.innerHTML = '<p style="text-align: center; color: #ff4d4f;">获取成员列表失败</p>';
            });
        }
        
        // 切换成员操作菜单显示
        function toggleMemberActionsMenu(event, groupId, memberId, isAdmin, isFriend, username) {
            event.stopPropagation();
            
            // 关闭所有其他菜单
            document.querySelectorAll('.member-actions-menu').forEach(menu => {
                menu.style.display = 'none';
            });
            
            // 切换当前菜单
            const menu = document.getElementById(`member-actions-menu-${memberId}`);
            if (menu) {
                menu.style.display = menu.style.display === 'block' ? 'none' : 'block';
            }
        }
        
        // 关闭成员操作菜单
        function closeMemberActionsMenu(memberId) {
            const menu = document.getElementById(`member-actions-menu-${memberId}`);
            if (menu) {
                menu.style.display = 'none';
            }
        }
        
        // 点击其他地方关闭成员操作菜单
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.group-member-actions-btn') && !e.target.closest('.member-actions-menu')) {
                document.querySelectorAll('.member-actions-menu').forEach(menu => {
                    menu.style.display = 'none';
                });
            }
        });
        
        // 关闭群聊成员弹窗
        function closeGroupMembersModal() {
            const modal = document.getElementById('group-members-modal');
            modal.style.display = 'none';
        }
        
        // 踢出群聊成员
        function kickMember(groupId, memberId) {
            if (confirm('确定要将该成员踢出群聊吗？')) {
                fetch(`remove_group_member.php?group_id=${groupId}&member_id=${memberId}`, {
                    credentials: 'include',
                    method: 'POST'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification('成员已成功踢出群聊', 'success');
                        // 重新加载成员列表
                        showGroupMembers(groupId);
                    } else {
                        showNotification(data.message || '踢出成员失败', 'error');
                    }
                })
                .catch(error => {
                    console.error('踢出成员失败:', error);
                    showNotification('踢出成员失败', 'error');
                });
            }
        }
        
        // 设置群管理员
        function setGroupAdmin(groupId, memberId, isAdmin) {
            const action = isAdmin ? '设为管理员' : '取消管理员';
            if (confirm(`确定要${action}吗？`)) {
                // 显示加载状态
                showNotification(`${action}中...`, 'info');
                
                fetch(`set_group_admin.php?group_id=${groupId}&member_id=${memberId}&is_admin=${isAdmin ? 1 : 0}`, {
                    credentials: 'include',
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: '' // 必须有body，即使是空字符串
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification(`${action}成功`, 'success');
                        // 延迟刷新成员列表，确保服务器数据已更新
                        setTimeout(() => {
                            // 强制从服务器获取最新数据，避免缓存
                            const membersList = document.getElementById('group-members-list');
                            if (membersList) {
                                membersList.innerHTML = '<p style="text-align: center; color: #666;">刷新成员列表中...</p>';
                            }
                            showGroupMembers(groupId);
                        }, 1000); // 增加延迟时间到1秒，确保服务器数据已更新
                    } else {
                        showNotification(`${action}失败：${data.message || '未知错误'}`, 'error');
                    }
                })
                .catch(error => {
                    console.error(`${action}失败:`, error);
                    showNotification(`${action}失败`, 'error');
                });
            }
        }
        
        // 添加好友
        function addFriend(userId, username) {
            fetch(`send_friend_request.php?friend_id=${userId}`, {
                credentials: 'include',
                method: 'POST'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(`已发送好友请求给 ${username}`, 'success');
                } else {
                    showNotification(data.message || '添加好友失败', 'error');
                }
            })
            .catch(error => {
                console.error('添加好友失败:', error);
                showNotification('添加好友失败', 'error');
            });
        }
        
        // 格式化时间显示（秒 -> mm:ss）
        function formatTime(seconds) {
            if (isNaN(seconds) || !isFinite(seconds) || seconds < 0) return '0:00';
            
            const mins = Math.floor(seconds / 60);
            const secs = Math.floor(seconds % 60);
            return `${mins}:${secs.toString().padStart(2, '0')}`;
        }
        
        // 获取新消息
        function getNewMessages() {
            const chatType = '<?php echo $chat_type; ?>';
            const chatId = '<?php echo $selected_id; ?>';
            
            if (!chatId) return;
            
            // 获取最后一条消息的ID
            const messages = document.querySelectorAll('.message');
            let lastMessageId = 0;
            // 从后往前查找最近一条非临时消息的ID
            for (let i = messages.length - 1; i >= 0; i--) {
                const id = messages[i].dataset.messageId;
                // 假设服务器ID是纯数字，临时ID包含非数字字符（如 'temp_' 或 'msg_'）
                // 这里简单判断：如果是数字则认为是有效ID
                if (id && /^\d+$/.test(id)) {
                    lastMessageId = id;
                    break;
                }
            }
            
            // 构造请求URL
            let url;
            if (chatType === 'friend') {
                url = `get_new_messages.php?friend_id=${chatId}&last_message_id=${lastMessageId}`;
            } else {
                url = `get_new_group_messages.php?group_id=${chatId}&last_message_id=${lastMessageId}`;
            }
            
            // 发送请求获取新消息
            fetch(url)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.messages.length > 0) {
                        const messagesContainer = document.getElementById('messages-container');
                        
                        // 只添加新消息，避免重复
                        data.messages.forEach(msg => {
                            // 检查消息是否已经存在于当前聊天中
                            const existingMessage = document.querySelector(`[data-message-id="${msg.id}"][data-chat-type="${chatType}"][data-chat-id="${chatId}"]`);
                            if (!existingMessage) {
                                // 创建消息元素
                                const messageElement = createMessageElement(msg, chatType, chatId);
                                messagesContainer.appendChild(messageElement);
                            }
                        });
                        
                        // 滚动到底部
                        messagesContainer.scrollTop = messagesContainer.scrollHeight;
                        
                        // 处理新消息中的媒体文件
                document.querySelectorAll('.message-file').forEach(fileLink => {
                    const filePath = fileLink.getAttribute('data-file-path');
                    const fileName = fileLink.getAttribute('data-file-name');
                    if (filePath && fileName) {
                        const fileType = getFileType(fileName);
                        setFileCache(filePath, fileType, 0);
                    }
                });
                
                // 初始化新添加的音频播放器
                initAudioPlayers();
                
                // 初始化新添加的视频元素
                initVideoElements();
                
                // 初始化新添加的聊天视频，转换为Blob URL
                initChatVideos();
                    }
                })
                .catch(error => {
                    console.error('获取新消息失败:', error);
                });
        }
        
        // 创建消息元素
        function createMessageElement(msg, chatType, chatId) {
            const messageDiv = document.createElement('div');
            // 确保类型匹配，使用 == 进行比较
            const isSent = parseInt(msg.sender_id) == parseInt(<?php echo $user_id; ?>);
            messageDiv.className = `message ${isSent ? 'sent' : 'received'}`;
            messageDiv.dataset.messageId = msg.id;
            messageDiv.dataset.chatType = chatType;
            messageDiv.dataset.chatId = chatId;
            
            let avatarHtml;
            if (isSent) {
                // 当前用户的头像
                avatarHtml = `<?php if (!empty($current_user['avatar'])): ?>
                    <img src="<?php echo $current_user['avatar']; ?>" alt="<?php echo $username; ?>" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                <?php else: ?>
                    <?php echo substr($username, 0, 2); ?>
                <?php endif; ?>`;
            } else {
                // 对方的头像
                if (chatType === 'friend') {
                    avatarHtml = `<?php if (isset($selected_friend) && is_array($selected_friend) && isset($selected_friend['avatar']) && !empty($selected_friend['avatar'])): ?>
                        <img src="<?php echo $selected_friend['avatar']; ?>" alt="<?php echo $selected_friend['username'] ?? ''; ?>" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                    <?php elseif (isset($selected_friend) && is_array($selected_friend) && isset($selected_friend['username'])): ?>
                        <?php echo substr($selected_friend['username'], 0, 2); ?>
                    <?php else: ?>
                        群
                    <?php endif; ?>`;
                } else {
                    // 群聊成员头像
                    avatarHtml = msg.avatar ? `<img src="${msg.avatar}" alt="${msg.sender_username}" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">` : msg.sender_username.substring(0, 2);
                }
            }
            
            let contentHtml;
            let hasInternalMenu = false;
            if (msg.type === 'file' || msg.file_path) {
                let file_path = msg.file_path;
                const file_name = msg.file_name;
                const file_size = msg.file_size;
                const file_type = msg.type;
                
                // 从localStorage获取文件数据
                function getFileUrlFromLocalStorage(filePath) {
                    if (typeof(Storage) === "undefined") {
                        return filePath;
                    }
                    
                    // 检查是否是localStorage文件ID，支持多种前缀
            if (filePath && (filePath.startsWith('Picture_') || filePath.startsWith('Video_') || filePath.startsWith('Audio_') || filePath.startsWith('File_'))) {
                const fileData = localStorage.getItem(filePath);
                if (fileData) {
                    try {
                        const fileInfo = JSON.parse(fileData);
                        return fileInfo.data;
                    } catch (e) {
                        console.error('Failed to parse file data from localStorage:', e);
                    }
                }
            }
            // 直接返回原始路径
            return filePath;
                }
                
                // 从localStorage获取文件URL
                // 如果有传入的blob_url，优先使用它
                const fileUrl = msg.blob_url || getFileUrlFromLocalStorage(file_path);
                
                // 检测文件的实际类型
                // 如果文件名包含 localStorage ID 前缀（如 Audio_...），尝试提取真实扩展名或默认为 webm/mp3
                let ext;
                if (file_name.startsWith('Audio_')) {
                    ext = 'webm'; // 录音文件默认格式
                } else {
                    ext = file_name.toLowerCase().split('.').pop();
                }
                
                const imageExts = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
                const audioExts = ['mp3', 'wav', 'ogg', 'aac', 'wma', 'm4a', 'webm'];
                const videoExts = ['mp4', 'avi', 'mov', 'wmv', 'flv'];
                
                if (imageExts.includes(ext)) {
                    // 图片类型
                    contentHtml = `<div class='message-media'>
                        <img src='${fileUrl}' alt='${file_name}' class='message-image' data-file-name='${file_name}' data-file-type='image' data-file-path='${file_path}'>
                    </div>`;
                } else if (audioExts.includes(ext)) {
                    hasInternalMenu = true;
                    // 检查是否可以撤回
                    const messageTime = new Date(msg.created_at);
                    const now = new Date();
                    const diffInMinutes = (now - messageTime) / (1000 * 60);
                    const canRecall = isSent && diffInMinutes <= 2;
                    let recallHtml = '';
                    if (canRecall) {
                        recallHtml = `<button class='file-action-item' onclick="event.stopPropagation(); recallMessage(this, '${msg.id}', '${chatType}', '${chatId}')" style='display: block; width: 100%; padding: 8px 16px; text-align: left; border: none; background: none; cursor: pointer; font-size: 14px; color: #333; transition: background-color 0.2s ease;'>撤回</button>`;
                    }

                    // 音频类型
                    contentHtml = `<div class='message-media' style='position: relative;'>
                        <div class='custom-audio-player'>
                            <audio src='${fileUrl}' class='audio-element' data-file-name='${file_name}' data-file-type='audio' data-file-path='${file_path}'></audio>
                            <button class='audio-play-btn' title='播放'></button>
                            <div class='audio-progress-container'>
                                <div class='audio-progress-bar'>
                                    <div class='audio-progress'></div>
                                </div>
                            </div>
                            <span class='audio-time current-time'>0:00</span>
                            <span class='audio-duration'>0:00</span>
                            <!-- 音频操作按钮 -->
                            <div style='position: relative; display: inline-block; margin-left: 10px;'>
                                <button class='media-action-btn' onclick="event.stopPropagation(); toggleMediaActionsMenu(event, this)" style='width: 28px; height: 28px; font-size: 14px; background: rgba(0,0,0,0.1); border: none; border-radius: 50%; color: #666; cursor: pointer;'>⋮</button>
                                <div class='file-actions-menu' style='display: none; position: absolute; top: 35px; right: 0; background: white; border-radius: 8px; box-shadow: 0 2px 12px rgba(0,0,0,0.15); padding: 8px 0; z-index: 5000; min-width: 80px;'>
                                    <button class='file-action-item' onclick="event.stopPropagation(); addDownloadTask('${file_name}', '${file_path}', ${file_size}, 'audio');" style='display: block; width: 100%; padding: 8px 16px; text-align: left; border: none; background: none; cursor: pointer; font-size: 14px; color: #333; transition: background-color 0.2s ease;'>下载</button>
                                    ${recallHtml}
                                </div>
                            </div>
                        </div>
                    </div>`;
                    // 只在localStorage缓存不存在时才缓存文件
                    if (isFileExpired(file_path, 'audio')) {
                        setFileCache(file_path, 'audio', file_size);
                    }
                } else if (videoExts.includes(ext)) {
                    hasInternalMenu = true;
                    // 检查是否可以撤回
                    const messageTime = new Date(msg.created_at);
                    const now = new Date();
                    const diffInMinutes = (now - messageTime) / (1000 * 60);
                    const canRecall = isSent && diffInMinutes <= 2;
                    let recallHtml = '';
                    if (canRecall) {
                        recallHtml = `<button class='file-action-item' onclick="event.stopPropagation(); recallMessage(this, '${msg.id}', '${chatType}', '${chatId}')" style='display: block; width: 100%; padding: 8px 16px; text-align: left; border: none; background: none; cursor: pointer; font-size: 14px; color: #333; transition: background-color 0.2s ease;'>撤回</button>`;
                    }

                    // 视频类型 - 使用空src，后续通过JS加载为Blob URL
                    contentHtml = `<div class='message-media' style='position: relative;'>
                        <div class='video-container' style='position: relative;'>
                            <video src='' class='video-element' data-file-name='${file_name}' data-file-type='video' data-file-path='${file_path}' data-file-url='${fileUrl}' controlsList='nodownload'>
                            </video>
                            <!-- 视频操作按钮 -->
                            <div class='media-actions' style='position: absolute; top: 10px; right: 10px; display: flex; gap: 5px; opacity: 1 !important;'>
                                <div style='position: relative;'>
                                    <button class='media-action-btn' onclick="event.stopPropagation(); toggleMediaActionsMenu(event, this)" style='width: 32px; height: 32px; font-size: 16px; background: rgba(0,0,0,0.6); border: none; border-radius: 50%; color: white; cursor: pointer; display: flex; align-items: center; justify-content: center;'>⋮</button>
                                    <div class='file-actions-menu' style='display: none; position: absolute; top: 40px; right: 0; background: white; border-radius: 8px; box-shadow: 0 2px 12px rgba(0,0,0,0.15); padding: 8px 0; z-index: 5000; min-width: 80px;'>
                                        <button class='file-action-item' onclick="event.stopPropagation(); addDownloadTask('${file_name}', '${file_path}', ${file_size}, 'video');" style='display: block; width: 100%; padding: 8px 16px; text-align: left; border: none; background: none; cursor: pointer; font-size: 14px; color: #333; transition: background-color 0.2s ease;'>下载</button>
                                        ${recallHtml}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>`;
                    // 只在localStorage缓存不存在时才缓存文件
                    if (isFileExpired(file_path, 'video')) {
                        setFileCache(file_path, 'video', file_size);
                    }
                } else {
                // 其他文件类型
                contentHtml = `<div class='message-file' onclick="event.preventDefault(); addDownloadTask('${file_name}', '${file_path}', ${file_size}, 'file');">
                    <span class='file-icon' style='font-size: 24px;'>📁</span>
                    <div class='file-info' style='flex: 1;'>
                        <h4 style='margin: 0; font-size: 14px; font-weight: 500;'>${file_name}</h4>
                        <p style='margin: 2px 0 0 0; font-size: 12px; color: #666;'>${(file_size / 1024).toFixed(2)} KB</p>
                    </div>
                    <button style='background: #667eea; color: white; border: none; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 12px; transition: all 0.2s ease;' onclick="event.stopPropagation(); addDownloadTask('${file_name}', '${file_path}', ${file_size}, 'file');">下载</button>
                </div>`;
                // 只在localStorage缓存不存在时才缓存文件
                if (isFileExpired(file_path, 'file')) {
                    setFileCache(file_path, 'file', file_size);
                }
            }
            } else {
                // 检测消息是否包含链接
                const messageWithLinks = msg.content.replace(/(https?:\/\/[^\s]+)/g, function(link) {
                    return `<a href="#" onclick="event.preventDefault(); handleLinkClick('${link}')" style="color: #12b7f5; text-decoration: underline;">${link}</a>`;
                });
                contentHtml = `<div class='message-text'>${messageWithLinks}</div>`;
            }
            
            // 将时间格式化为 X年X月X日X时X分
            const date = new Date(msg.created_at);
            const formattedTime = `${date.getFullYear()}年${(date.getMonth() + 1).toString().padStart(2, '0')}月${date.getDate().toString().padStart(2, '0')}日${date.getHours().toString().padStart(2, '0')}时${date.getMinutes().toString().padStart(2, '0')}分`;
            const timeHtml = `<div class='message-time'>${formattedTime}</div>`;
            
            // 只有发送者可以看到操作按钮
            let messageActionsHtml = '';
            if (isSent) {
                // 检查消息是否在2分钟内，只有2分钟内的消息可以撤回
                const messageTime = new Date(msg.created_at);
                const now = new Date();
                const diffInMinutes = (now - messageTime) / (1000 * 60);
                
                // 只有2分钟内的消息才显示三个点按钮和撤回选项
                if (diffInMinutes <= 2 && !hasInternalMenu) {
                    const recallButtonHtml = `
                    <button class='message-action-item' onclick="event.stopPropagation(); recallMessage(this, '${msg.id}', '${chatType}', '${chatId}')" 
                            style='display: block; width: 100%; padding: 8px 16px; text-align: left; border: none; 
                                   background: none; cursor: pointer; font-size: 14px; color: var(--text-color); transition: background-color 0.2s ease;'>撤回</button>
                    `;
                
                    messageActionsHtml = `
                    <div class='message-actions' style='position: absolute; top: 50%; right: -10px; transform: translateY(-50%); display: flex; align-items: center; gap: 5px; z-index: 4000;'>
                        <div style='position: relative; z-index: 4000;'>
                            <button class='message-action-btn' onclick="event.stopPropagation(); toggleMessageActions(this)" 
                                    style='width: 28px; height: 28px; font-size: 18px; background: rgba(0,0,0,0.2); border: none; border-radius: 50%; 
                                           color: var(--text-color); cursor: pointer; display: flex; align-items: center; justify-content: center; opacity: 1; 
                                           transition: all 0.2s ease; position: relative; z-index: 4000;'>⋮</button>
                                <div class='message-actions-menu' style='display: none; position: absolute; top: 35px; right: 0; 
                                                                 background: var(--modal-bg); border-radius: 8px; box-shadow: 0 2px 12px rgba(0,0,0,0.3); 
                                                                 padding: 8px 0; z-index: 5000; min-width: 80px; border: 1px solid var(--border-color);'>
                                    ${recallButtonHtml}
                                </div>
                            </div>
                        </div>`;
                } else {
                    messageActionsHtml = '';
                }
            }
            
            // 为发送者的消息添加右键和长按事件
            if (isSent) {
                // 发送者的消息，头像在右，内容在左
                messageDiv.innerHTML = `
                    <div class='message-content' style='position: relative;'>
                        ${contentHtml}
                        ${timeHtml}
                        ${messageActionsHtml}
                    </div>
                    <div class='message-avatar'>${avatarHtml}</div>
                `;
                
                // 添加右键事件，禁止浏览器默认右键菜单
                messageDiv.addEventListener('contextmenu', function(event) {
                    event.preventDefault(); // 禁止浏览器默认右键菜单
                });
                

                
                // 添加点击事件关闭菜单
                messageDiv.addEventListener('click', function() {
                    const menu = this.querySelector('.message-actions-menu');
                    if (menu) {
                        menu.style.display = 'none';
                    }
                });
            } else {
                // 接收者的消息，头像在左，内容在右
                messageDiv.innerHTML = `
                    <div class='message-avatar'>${avatarHtml}</div>
                    <div class='message-content'>
                        ${contentHtml}
                        ${timeHtml}
                    </div>
                `;
            }
            
            return messageDiv;
        }
        
        // 全局对象，用于跟踪URL请求失败次数
        const urlFailureCount = {};
        const MAX_FAILURES = 5;
        
        // 检查URL是否已达到最大失败次数
        function shouldBlockRequest(url) {
            return urlFailureCount[url] >= MAX_FAILURES;
        }
        
        // 增加URL失败次数
        function incrementFailureCount(url) {
            if (!urlFailureCount[url]) {
                urlFailureCount[url] = 0;
            }
            urlFailureCount[url]++;
        }
        
        // 页面加载完成后初始化
        document.addEventListener('DOMContentLoaded', function() {
            // 初始化视频播放器
            initVideoPlayer();
            
            // 初始化视频元素
            initVideoElements();
            
            // 为所有图片添加错误处理，限制404请求次数
            document.addEventListener('error', function(e) {
                if (e.target.tagName === 'IMG') {
                    const imgUrl = e.target.src;
                    incrementFailureCount(imgUrl);
                    // 如果达到最大失败次数，替换为默认头像
                    if (shouldBlockRequest(imgUrl)) {
                        const username = e.target.alt || '';
                        e.target.style.display = 'none';
                        const parent = e.target.parentNode;
                        if (parent && parent.tagName === 'DIV') {
                            parent.innerHTML = username.substring(0, 2);
                        }
                    }
                }
            }, true);
        });
        
        // 撤回消息

        
        // 切换消息操作菜单
        function toggleMessageActions(button) {
            // 关闭所有其他消息操作菜单
            document.querySelectorAll('.message-actions-menu, .message-action-menu').forEach(menu => {
                if (menu !== button.nextElementSibling) {
                    menu.style.display = 'none';
                }
            });
            // 切换当前菜单
            const menu = button.nextElementSibling;
            menu.style.display = menu.style.display === 'block' ? 'none' : 'block';
        }
        
        // 点击页面其他地方关闭所有消息操作菜单
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.message-actions') && !e.target.closest('.message-action-btn') && !e.target.closest('.message-actions-menu')) {
                document.querySelectorAll('.message-actions-menu').forEach(menu => {
                    menu.style.display = 'none';
                });
            }
        });
        
        // 定期获取新消息
        setInterval(getNewMessages, 3000);
        
        // 标记消息为已读
        function markMessagesAsRead() {
            const chatType = '<?php echo $chat_type; ?>';
            const selectedId = '<?php echo $selected_id; ?>';
            
            if (!selectedId) return;
            
            fetch('mark_messages_read.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `chat_type=${chatType}&chat_id=${selectedId}`
            })
            .then(response => response.json())
            .then(data => {
                if (!data.success) {
                    console.error('标记消息为已读失败:', data.message);
                } else {
                    // 更新当前聊天项的未读角标
                    const chatItem = document.querySelector(`[data-chat-id="${selectedId}"][data-chat-type="${chatType}"]`);
                    if (chatItem) {
                        const unreadCountElement = chatItem.querySelector('.unread-count');
                        if (unreadCountElement) {
                            unreadCountElement.remove();
                        }
                    }
                }
            })
            .catch(error => {
                console.error('标记消息为已读失败:', error);
            });
        }
        
        // 页面加载时标记当前聊天的消息为已读
        document.addEventListener('DOMContentLoaded', function() {
            // 初始化视频元素
            initVideoElements();
            
            // 标记当前聊天的消息为已读
            markMessagesAsRead();
        });
        
        // 确保视频媒体操作按钮在悬停时显示
        document.addEventListener('mouseover', function(e) {
            const mediaContainer = e.target.closest('.video-container');
            if (mediaContainer) {
                const mediaActions = mediaContainer.querySelector('.media-actions');
                if (mediaActions) {
                    mediaActions.style.opacity = '1';
                }
            }
        });
        
        // 确保图片点击能弹出查看器
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('message-image')) {
                const imageUrl = e.target.src;
                const imageName = e.target.getAttribute('data-file-name');
                // 这里可以添加图片查看器的代码
                console.log('查看图片:', imageName, imageUrl);
            }
        });

        // 深色模式切换
        function toggleTheme(isDark) {
            const html = document.documentElement;
            if (isDark) {
                html.setAttribute('data-theme', 'dark');
                localStorage.setItem('theme', 'dark');
            } else {
                html.removeAttribute('data-theme');
                localStorage.setItem('theme', 'light');
            }
        }

        // 初始化深色模式
        (function initTheme() {
            const savedTheme = localStorage.getItem('theme');
            const darkModeToggle = document.getElementById('dark-mode-toggle');
            
            if (savedTheme === 'dark') {
                document.documentElement.setAttribute('data-theme', 'dark');
                if (darkModeToggle) darkModeToggle.checked = true;
            } else if (!savedTheme && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                // 如果没有保存的偏好，但系统是深色模式，也自动切换
                document.documentElement.setAttribute('data-theme', 'dark');
                if (darkModeToggle) darkModeToggle.checked = true;
            }

            // 监听系统主题变化
            if (window.matchMedia) {
                window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', e => {
                    if (!localStorage.getItem('theme')) {
                        if (e.matches) {
                            document.documentElement.setAttribute('data-theme', 'dark');
                            if (darkModeToggle) darkModeToggle.checked = true;
                        } else {
                            document.documentElement.removeAttribute('data-theme');
                            if (darkModeToggle) darkModeToggle.checked = false;
                        }
                    }
                });
            }
        })();
    </script>
    <!-- 音乐播放器 -->
    <?php if (false): // 手机端禁用音乐播放器 ?>
    <style>
        /* 音乐播放器样式 */
        #music-player {
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 300px;
            background: var(--panel-bg);
            border-radius: 20px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid var(--border-color);
            z-index: 9999;
            overflow: hidden;
            transition: all 0.3s ease;
            color: var(--text-color);
        }
        
        /* 拖拽时禁止文字选择 */
        #music-player.dragging {
            cursor: grabbing;
            user-select: none;
        }
        
        /* 播放器头部 */
        #player-header {
            cursor: move;
        }
        
        /* 音量控制 */
        #volume-container {
            position: relative;
            display: inline-block;
        }
        
        /* 新的音量调节UI */
        #volume-control {
            position: absolute;
            right: -15px;
            top: -110px;
            background: var(--panel-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
            z-index: 1001;
        }
        
        #volume-slider {
            width: 80px;
            height: 5px;
            background: var(--border-color);
            border-radius: 3px;
            cursor: pointer;
            overflow: hidden;
        }
        
        #volume-level {
            height: 100%;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
            border-radius: 3px;
            transition: width 0.1s ease;
            width: 80%; /* 默认音量80% */
        }
        
        /* 音量增减按钮 */
        .volume-btn {
            width: 24px;
            height: 24px;
            border: none;
            background: var(--input-bg);
            color: var(--text-color);
            border-radius: 50%;
            cursor: pointer;
            font-size: 12px;
            font-weight: bold;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }
        
        .volume-btn:hover {
            background: #667eea;
            color: white;
            transform: scale(1.1);
        }
        
        /* 音量按钮 */
        #volume-btn {
            position: relative;
        }
        
        #music-player.minimized {
            width: 344px;
            height: 60px;
            bottom: 10px;
            right: 10px;
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        #music-player.minimized #player-header {
            display: none;
        }
        
        #player-header {
            padding: 10px 15px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 14px;
            font-weight: 600;
            cursor: move;
        }
        
        #player-toggle {
            background: none;
            border: none;
            color: white;
            font-size: 18px;
            cursor: pointer;
            padding: 5px;
        }
        
        #player-content {
            padding: 15px;
        }
        
        #music-player.minimized #player-content {
            padding: 10px;
            display: flex;
            align-items: center;
        }
        
        /* 专辑图片 */
        #album-art {
            width: 150px;
            height: 150px;
            margin: 0 auto 15px;
            border-radius: 50%;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        
        #music-player.minimized #album-art {
            width: 40px;
            height: 40px;
            margin: 0 10px 0 0;
            flex-shrink: 0;
        }
        
        #album-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: none;
        }
        
        /* 歌曲信息 */
        #song-info {
            text-align: center;
            margin-bottom: 15px;
        }
        
        #music-player.minimized #song-info {
            display: none;
        }
        
        /* 缩小状态下播放控制的布局 */
        #music-player.minimized #player-content {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            gap: 10px;
            padding: 10px;
        }
        
        /* 缩小状态下只显示必要的控制按钮 */
        #music-player.minimized #player-controls {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        #music-player.minimized #prev-btn,
        #music-player.minimized #next-btn {
            display: none;
        }
        
        #music-player.minimized #volume-container {
            display: flex;
            align-items: center;
        }
        
        #song-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-color);
            margin: 0 0 5px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        #music-player.minimized #song-title {
            font-size: 14px;
            margin: 0 0 2px;
        }
        
        #artist-name {
            font-size: 14px;
            color: var(--text-color);
            opacity: 0.8;
            margin: 0;
        }
        
        #music-player.minimized #artist-name {
            font-size: 12px;
        }
        
        /* 播放控制 */
        #player-controls {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        #music-player.minimized #player-controls {
            gap: 10px;
            margin: 0;
        }
        
        .control-btn {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            border: none;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            font-size: 16px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            transition: all 0.2s ease;
        }
        
        #music-player.minimized .control-btn {
            width: 30px;
            height: 30px;
            font-size: 14px;
        }
        
        .control-btn:hover {
            transform: scale(1.1);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }
        
        #play-btn {
            width: 50px;
            height: 50px;
            font-size: 20px;
        }
        
        #music-player.minimized #play-btn {
            width: 35px;
            height: 35px;
            font-size: 16px;
        }
        
        /* 进度条 */
        #progress-container {
            margin-bottom: 10px;
        }
        
        #music-player.minimized #progress-container {
            flex: 1;
            margin: 0 10px;
            position: relative;
        }
        
        #progress-bar {
            width: 100%;
            height: 5px;
            background: #e0e0e0;
            border-radius: 3px;
            cursor: pointer;
            overflow: hidden;
        }
        
        /* 缩小状态下的播放按钮样式 */
        #music-player.minimized #play-btn {
            width: 35px;
            height: 35px;
            font-size: 16px;
        }
        
        /* 缩小状态下的专辑图片位置 */
        #music-player.minimized #album-art {
            width: 40px;
            height: 40px;
            flex-shrink: 0;
            margin: 0;
        }
        
        /* 缩小状态下的音量按钮 */
        #music-player.minimized #volume-btn {
            width: 35px;
            height: 35px;
            font-size: 16px;
        }
        
        #progress {
            height: 100%;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
            border-radius: 3px;
            transition: width 0.1s ease;
        }
        
        /* 时间显示 */
        #time-display {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            color: #999;
            margin-top: 5px;
        }
        
        #music-player.minimized #time-display {
            display: none;
        }
        
        /* 确保进度条上边的歌曲信息能正确显示 */
        #progress-song-info {
            font-size: 12px;
            color: var(--text-color);
            opacity: 0.8;
            margin-bottom: 5px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        /* 缩小状态下也显示歌曲信息 */
        #music-player.minimized #progress-song-info {
            display: none;
        }
        
        /* 确保音量控制UI能被点击 */
        #volume-control {
            z-index: 1001;
            pointer-events: auto;
            position: absolute;
            bottom: 100%;
            right: 0;
            margin-bottom: 10px;
            background: var(--panel-bg);
            padding: 10px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid var(--border-color);
        }
        
        /* 小窗模式下音量控制UI的特殊定位 - 显示在容器外 */
        #music-player.minimized #volume-control {
            position: fixed !important;
            bottom: auto !important;
            top: auto !important;
            left: auto !important;
            right: 10px !important;
            bottom: 80px !important;
            z-index: 9999 !important;
            margin-bottom: 0 !important;
            background: var(--panel-bg) !important;
            padding: 10px !important;
            border-radius: 8px !important;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1) !important;
            backdrop-filter: blur(10px) !important;
            border: 1px solid var(--border-color) !important;
        }
        
        /* 确保音量按钮能正确触发事件 */
        #volume-btn {
            position: relative;
            z-index: 1002;
        }
        
        /* 状态信息 */
        #player-status {
            font-size: 12px;
            color: #999;
            text-align: center;
            margin-top: 10px;
        }
        
        #music-player.minimized #player-status {
            display: none;
        }
        
        /* 迷你播放器模式 */
        #music-player.mini-minimized {
            width: 30px;
            height: 70px;
            bottom: 10px;
            right: 10px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 15px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            transition: all 0.3s ease;
        }
        
        /* 迷你模式下隐藏所有内容，只显示恢复按钮 */
        #music-player.mini-minimized > *:not(#mini-toggle-btn) {
            display: none !important;
            visibility: hidden !important;
            opacity: 0 !important;
        }
        
        /* 确保恢复按钮显示 - 更大更醒目 */
        #music-player.mini-minimized #mini-toggle-btn {
            display: flex !important;
            visibility: visible !important;
            opacity: 1 !important;
            position: absolute !important;
            top: 50% !important;
            left: 50% !important;
            transform: translate(-50%, -50%) !important;
            width: 100% !important;
            height: 100% !important;
            background: transparent !important;
            border: none !important;
            color: white !important;
            font-size: 24px !important;
            font-weight: bold !important;
            z-index: 1000 !important;
            cursor: pointer !important;
        }
        
        /* 迷你模式下移除默认指示器，使用按钮文字 */
        #music-player.mini-minimized::before {
            content: none !important;
        }
        
        /* 增强迷你模式的视觉效果 - 右边贴合浏览器边框 */
        #music-player.mini-minimized {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
            border: 2px solid white !important;
            border-right: none !important;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2) !important;
            border-radius: 15px 0 0 15px !important;
            right: 0 !important;
            margin-right: 0 !important;
        }
        
        /* 迷你模式切换按钮 */
        #mini-toggle-btn {
            position: absolute;
            bottom: 10px;
            right: 10px;
            width: 25px;
            height: 25px;
            background: rgba(0, 0, 0, 0.3);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            cursor: pointer;
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            z-index: 1003;
            font-weight: bold;
        }
        
        #mini-toggle-btn:hover {
            background: rgba(0, 0, 0, 0.5);
            transform: scale(1.1);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
        }
        
        /* 小窗模式下显示迷你切换按钮 */
        #music-player.minimized #mini-toggle-btn {
            display: flex !important;
        }
        
        /* 迷你模式下显示恢复按钮 */
        #music-player.mini-minimized #mini-toggle-btn {
            display: flex !important;
            width: 100%;
            height: 100%;
            background: transparent;
            border: none;
            border-radius: 15px;
            font-size: 16px;
            font-weight: bold;
        }
        
        /* 迷你模式下其他按钮不可点击 */
        #music-player.mini-minimized .control-btn,
        #music-player.mini-minimized #prev-btn,
        #music-player.mini-minimized #play-btn,
        #music-player.mini-minimized #next-btn,
        #music-player.mini-minimized #volume-btn,
        #music-player.mini-minimized #progress-bar {
            pointer-events: none;
        }
        
        /* 确保按钮在各种播放器状态下都能正确显示 */
        #mini-toggle-btn {
            display: flex;
        }
        
        /* 大窗模式下隐藏迷你切换按钮 */
        #music-player:not(.minimized):not(.mini-minimized) #mini-toggle-btn {
            display: none !important;
        }
        
        /* 隐藏原生音频控件 */
        #audio-player {
            display: none;
        }
        
        /* 下载链接样式 */
        #download-link {
            display: block;
            text-align: center;
            padding: 8px;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 0 0 15px 15px;
            font-size: 12px;
            font-weight: 500;
            transition: background-color 0.2s ease;
        }
        
        #download-link:hover {
            background: #5a6fd8;
        }
        
        /* 确保下载链接没有多余的图标 */
        #download-link::after {
            content: none;
        }
        
        /* 缩小状态下隐藏下载链接 */
        #music-player.minimized #download-link,
        #music-player.mini-minimized #download-link {
            display: none;
        }
        
        /* 确保小窗模式下+按钮正确显示 */
        #music-player.minimized #minimized-toggle {
            display: block;
            position: absolute;
            top: 5px;
            right: 5px;
            width: 25px;
            height: 25px;
            font-size: 16px;
            background: rgba(0, 0, 0, 0.2);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            cursor: pointer;
            z-index: 1001;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }
        
        #music-player.minimized #minimized-toggle:hover {
            background: rgba(0, 0, 0, 0.4);
            transform: scale(1.1);
        }
    </style>
    

    
    <script>
        // 全局变量
        let currentSong = null;
        let isPlaying = false;
        let isMinimized = false;
        let isMiniMinimized = false;
        let isPlayerDragging = false;
        let playerStartX = 0;
        let playerStartY = 0;
        let initialX = 0;
        let initialY = 0;
        
        // HOYO-MiX 音乐模式相关变量
        let currentMusicMode = 'random'; // 当前音乐模式：'random' 或 'hoyo'
        let hoyoSongList = []; // HOYO-MiX 歌曲列表
        let hoyoCurrentIndex = 0; // 当前播放的歌曲索引
        let hoyoUsedPages = []; // 已使用的随机页码列表
        let hoyoCurrentPage = 0; // 当前页码
        
        // 自定义歌单相关变量
        let customPlaylistName = '';
        let customPlaylistData = [];
        let customPlaylistIndex = 0;
        
        // 获取自定义歌单列表
        async function fetchPlaylists() {
            try {
                const response = await fetch('get_playlist_config.php');
                const data = await response.json();
                
                // 检查是否已存在选择器
                let select = document.getElementById('playlist-select');
                if (!select) {
                    // 动态创建选择器UI
                    const songInfo = document.getElementById('song-info');
                    if (songInfo) {
                        const container = document.createElement('div');
                        container.style.padding = '0 10px 10px 10px';
                        
                        select = document.createElement('select');
                        select.id = 'playlist-select';
                        select.onchange = function() { changePlaylist(this.value); };
                        select.style.width = '100%';
                        select.style.padding = '4px';
                        select.style.borderRadius = '4px';
                        select.style.border = '1px solid #ddd';
                        select.style.fontSize = '12px';
                        select.style.background = 'rgba(255,255,255,0.9)';
                        select.style.outline = 'none';
                        
                        // 默认选项
                        const defaultOpt = document.createElement('option');
                        defaultOpt.value = 'random';
                        defaultOpt.textContent = '随机热歌 (默认)';
                        select.appendChild(defaultOpt);
                        
                        const springOpt = document.createElement('option');
                        springOpt.value = 'spring_festival';
                        springOpt.textContent = '春节特别歌单';
                        select.appendChild(springOpt);
                        
                        const hoyoOpt = document.createElement('option');
                        hoyoOpt.value = 'hoyo';
                        hoyoOpt.textContent = 'HOYO-MiX';
                        select.appendChild(hoyoOpt);
                        
                        container.appendChild(select);
                        songInfo.parentNode.insertBefore(container, songInfo.nextSibling);
                    }
                }
                
                if (data && data.playlists && select) {
                    // 清除旧的自定义选项
                    Array.from(select.options).forEach(opt => {
                        if (opt.value.startsWith('custom_')) select.removeChild(opt);
                    });
                    
                    data.playlists.forEach(playlist => {
                        const option = document.createElement('option');
                        option.value = 'custom_' + playlist.name;
                        option.textContent = playlist.name;
                        select.appendChild(option);
                    });
                    
                    // 恢复保存的选择
                    const savedMode = localStorage.getItem('music_mode');
                    if (savedMode && select.querySelector(`option[value="${savedMode}"]`)) {
                        select.value = savedMode;
                    }
                }
            } catch (error) {
                console.error('Failed to fetch playlists:', error);
            }
        }
        
        // 切换歌单
        async function changePlaylist(mode) {
            if (mode.startsWith('custom_')) {
                const name = mode.substring(7);
                customPlaylistName = name;
                currentMusicMode = 'custom';
                // 重置
                customPlaylistData = [];
                customPlaylistIndex = 0;
            } else {
                currentMusicMode = mode;
            }
            
            // 保存偏好
            localStorage.setItem('music_mode', mode);
            
            // 立即加载新歌
            loadNewSong();
        }

        // 加载自定义歌单歌曲
        async function loadCustomPlaylistSong() {
            if (customPlaylistData.length === 0) {
                const status = document.getElementById('player-status');
                if(status) status.textContent = '加载歌单中...';
                try {
                    const response = await fetch(`get_playlist_music.php?name=${encodeURIComponent(customPlaylistName)}`);
                    const songs = await response.json();
                    
                    if (songs && songs.length > 0) {
                        customPlaylistData = songs;
                        // 随机打乱
                        customPlaylistData.sort(() => Math.random() - 0.5);
                    } else {
                        if(status) status.textContent = '歌单为空';
                        return;
                    }
                } catch (error) {
                    if(status) status.textContent = '歌单加载失败';
                    return;
                }
            }
            
            if (customPlaylistIndex >= customPlaylistData.length) {
                // 重新打乱
                customPlaylistData.sort(() => Math.random() - 0.5);
                customPlaylistIndex = 0;
            }
            
            const song = customPlaylistData[customPlaylistIndex++];
            
            currentSong = {
                name: song.title,
                artistsname: song.artist,
                url: song.url,
                picurl: song.cover
            };
            
            // 更新UI
            document.getElementById('song-title').textContent = `${song.name} - ${song.artistsname}`;
            document.getElementById('artist-name').textContent = song.artistsname;
            const progressInfo = document.getElementById('progress-song-info');
            if(progressInfo) progressInfo.textContent = `${song.name} - ${song.artistsname}`;
            
            const albumImage = document.getElementById('album-image');
            if (song.picurl && song.picurl !== 'assets/default_music_cover.png') {
                 albumImage.src = song.picurl;
            } else {
                // 默认图
                albumImage.src = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxMDAiIGhlaWdodD0iMTAwIiB2aWV3Qm94PSIwIDAgMTAwIDEwMCI+PHJlY3Qgd2lkdGg9IjEwMCIgaGVpZ2h0PSIxMDAiIGZpbGw9IiNkZGQiLz48dGV4dCB4PSI1MCIgeT0iNTAiIGZvbnQtZmFtaWx5PSJzYW5zLXNlcmlmIiBmb250LXNpemU9IjE0IiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBkbWluYW50LWJhc2VsaW5lPSJtaWRkbGUiIGZpbGw9IiM4ODgiPuVjb3ZlcjwvdGV4dD48L3N2Zz4=';
            }
            albumImage.style.display = 'block';
            
            // 播放
            const audioPlayer = document.getElementById('audio-player');
            audioPlayer.src = currentSong.url;
            audioPlayer.play().then(() => {
                isPlaying = true;
                const playBtn = document.getElementById('play-btn');
                if(playBtn) playBtn.textContent = '⏸';
                const status = document.getElementById('player-status');
                if(status) status.textContent = '正在播放';
            }).catch(e => console.error(e));
        }
        
        // 格式化时间显示（秒 -> mm:ss）
        function formatTime(seconds) {
            if (isNaN(seconds)) return '0:00';
            
            const mins = Math.floor(seconds / 60);
            const secs = Math.floor(seconds % 60);
            return `${mins}:${secs.toString().padStart(2, '0')}`;
        }
        
        // 页面加载完成后初始化音乐播放器
        window.addEventListener('load', () => {
            console.log('页面加载完成，检查音乐播放器设置...');
            
            // 加载设置
            const musicPlayerSetting = localStorage.getItem('setting-music-player') !== 'false';
            console.log('音乐播放器设置:', musicPlayerSetting);
            
            // 为音乐图标添加点击事件
            const musicIcon = document.getElementById('music-icon');
            if (musicIcon) {
                console.log('找到音乐图标，添加点击事件监听器...');
                musicIcon.addEventListener('click', toggleMusicPlayer);
            } else {
                console.log('未找到音乐图标元素');
            }
            
            // 只有当设置开启时才初始化播放器
            if (false) { // 手机端禁用音乐播放器
                console.log('初始化音乐播放器...');
                initMusicPlayer();
                initDrag();
            } else {
                console.log('音乐播放器设置已关闭，不初始化播放器');
                // 隐藏播放器
                const player = document.getElementById('music-player');
                if (player) {
                    player.style.display = 'none';
                }
                // 更新音乐图标为关闭状态
                if (musicIcon) {
                    musicIcon.innerHTML = '🎵<span style="color: red; font-size: 12px; position: absolute; top: 5px; right: 5px;">✕</span>';
                    musicIcon.style.position = 'relative';
                }
            }
        });
        
        // 初始化拖拽功能
        function initDrag() {
            const player = document.getElementById('music-player');
            const header = document.getElementById('player-header');
            const playerContent = document.getElementById('player-content');
            
            // 鼠标按下事件 - 开始拖拽
            const startDrag = (e) => {
                // 检查是否点击了按钮，如果是则不开始拖拽
                if (e.target.tagName === 'BUTTON') return;
                
                // 检查是否点击了进度条，如果是则不开始拖拽
                if (e.target.id === 'progress-bar' || e.target.closest('#progress-bar')) return;
                
                isPlayerDragging = true;
                player.classList.add('dragging');
                
                // 获取鼠标初始位置
                playerStartX = e.clientX;
                playerStartY = e.clientY;
                
                // 获取播放器当前位置
                initialX = player.offsetLeft;
                initialY = player.offsetTop;
                
                // 阻止默认行为和冒泡
                e.preventDefault();
                e.stopPropagation();
            };
            
            // 为播放器头部添加拖拽事件（所有模式）
            header.addEventListener('mousedown', startDrag);
            
            // 为播放器内容区域添加拖拽事件（所有模式）
            playerContent.addEventListener('mousedown', startDrag);
            
            // 为播放器本身添加拖拽事件（所有模式）
            player.addEventListener('mousedown', startDrag);
            
            // 鼠标移动事件 - 拖动元素
            document.addEventListener('mousemove', (e) => {
                if (!isPlayerDragging) return;
                
                // 检查是否为迷你模式
                const isMiniMode = player.classList.contains('mini-minimized');
                
                // 计算移动距离
                const dx = e.clientX - playerStartX;
                const dy = e.clientY - playerStartY;
                
                // 计算新位置
                let newX = initialX + dx;
                let newY = initialY + dy;
                
                // 获取播放器尺寸
                const playerWidth = player.offsetWidth;
                const playerHeight = player.offsetHeight;
                
                // 获取屏幕尺寸（考虑滚动条）
                const screenWidth = window.innerWidth;
                const screenHeight = window.innerHeight;
                
                if (isMiniMode) {
                    // 迷你模式：只能在最右边上下拖动
                    // 固定x坐标在最右边
                    newX = screenWidth - playerWidth;
                    
                    // 只限制y坐标
                    if (newY < 0) newY = 0;
                    if (newY > screenHeight - playerHeight) {
                        newY = screenHeight - playerHeight;
                    }
                } else {
                    // 正常模式和小窗模式：可以随意拖动
                    // 左侧边界：不能小于0
                    if (newX < 0) newX = 0;
                    
                    // 右侧边界：不能超过屏幕宽度 - 播放器宽度
                    if (newX > screenWidth - playerWidth) {
                        newX = screenWidth - playerWidth;
                    }
                    
                    // 顶部边界：不能小于0
                    if (newY < 0) newY = 0;
                    
                    // 底部边界：不能超过屏幕高度 - 播放器高度
                    if (newY > screenHeight - playerHeight) {
                        newY = screenHeight - playerHeight;
                    }
                }
                
                // 更新播放器位置
                player.style.left = `${newX}px`;
                player.style.top = `${newY}px`;
                
                // 移除bottom和right属性，避免冲突
                player.style.bottom = 'auto';
                player.style.right = 'auto';
                
                // 阻止默认行为
                e.preventDefault();
            });
            
            // 鼠标释放事件 - 结束拖拽
            document.addEventListener('mouseup', () => {
                if (isPlayerDragging) {
                    isPlayerDragging = false;
                    player.classList.remove('dragging');
                }
            });
            
            // 初始化音量
            const audioPlayer = document.getElementById('audio-player');
            audioPlayer.volume = 0.8; // 默认音量80%
        }
        
        // 获取当前音乐模式
        async function getCurrentMusicMode() {
            try {
                const settings = await indexedDBManager.getSettings();
                return settings['setting-music-mode'] || 'random';
            } catch (error) {
                return localStorage.getItem('setting-music-mode') || 'random';
            }
        }
        
        // 生成1-329之间的随机页码，确保不重复
        function generateRandomPage() {
            if (hoyoUsedPages.length >= 329) {
                // 重置已使用列表
                hoyoUsedPages = [];
            }
            
            let page;
            do {
                page = Math.floor(Math.random() * 329) + 1;
            } while (hoyoUsedPages.includes(page));
            
            hoyoUsedPages.push(page);
            return page;
        }
        
        // 获取HOYO-MiX歌曲列表
        async function getHoyoSongList() {
            try {
                // 生成随机页码
                const page = generateRandomPage();
                hoyoCurrentPage = page;
                
                // 请求歌曲列表
                const response = await fetch(`https://api.vkeys.cn/v2/music/tencent/singer/songlist?mid=001uz8tl04tdL8&page=${page}`);
                const data = await response.json();
                
                if (data.code === 200 && data.data && Array.isArray(data.data)) {
                    // 提取歌曲ID列表
                    const songIds = data.data.map(item => item.id).filter(id => id);
                    
                    if (songIds.length > 0) {
                        // 获取每首歌的详细信息
                        const songDetails = [];
                        for (const id of songIds) {
                            try {
                                const detailResponse = await fetch(`https://api.vkeys.cn/v2/music/tencent?id=${id}`);
                                const detailData = await detailResponse.json();
                                
                                if (detailData.code === 200 && detailData.data) {
                                    songDetails.push({
                                        id: id,
                                        name: detailData.data.song || '未知歌曲',
                                        artistsname: detailData.data.singer || '未知歌手',
                                        picurl: detailData.data.cover || '',
                                        url: detailData.data.url || ''
                                    });
                                }
                            } catch (error) {
                                console.error(`获取歌曲${id}详情失败:`, error);
                                // 忽略失败的歌曲，继续处理下一首
                            }
                        }
                        
                        return songDetails;
                    }
                }
                
                return [];
            } catch (error) {
                console.error('获取HOYO-MiX歌曲列表失败:', error);
                return [];
            }
        }
        
        // 加载HOYO-MiX歌曲
        async function loadHoyoSong() {
            // 如果歌曲列表为空或已播放完毕，获取新的歌曲列表
            if (hoyoSongList.length === 0 || hoyoCurrentIndex >= hoyoSongList.length) {
                document.getElementById('player-status').textContent = '正在获取HOYO-MiX歌曲列表...';
                hoyoSongList = await getHoyoSongList();
                hoyoCurrentIndex = 0;
                
                // 如果获取失败，显示错误信息
                if (hoyoSongList.length === 0) {
                    document.getElementById('player-status').textContent = '获取HOYO-MiX歌曲失败，请重试';
                    return false;
                }
            }
            
            // 获取当前要播放的歌曲
            const song = hoyoSongList[hoyoCurrentIndex];
            currentSong = song;
            hoyoCurrentIndex++;
            
            // 更新歌曲信息
            document.getElementById('song-title').textContent = `${song.name} - ${song.artistsname}`;
            document.getElementById('artist-name').textContent = song.artistsname;
            
            // 在进度条上边显示歌曲信息
            const progressSongInfo = document.getElementById('progress-song-info');
            progressSongInfo.textContent = `${song.name} - ${song.artistsname}`;
            
            // 设置专辑图片，确保使用HTTPS
            const albumImage = document.getElementById('album-image');
            if (song.picurl) {
                let picUrl = song.picurl;
                if (picUrl.startsWith('http://')) {
                    picUrl = picUrl.replace('http://', 'https://');
                }
                albumImage.src = picUrl;
                albumImage.style.display = 'block';
            } else {
                albumImage.style.display = 'none';
            }
            
            // 确保使用HTTPS
            let audioUrl = song.url;
            if (audioUrl && audioUrl.startsWith('http://')) {
                audioUrl = audioUrl.replace('http://', 'https://');
            }
            
            // 如果没有音频URL，尝试下一首
            if (!audioUrl) {
                return await loadHoyoSong();
            }
            
            // 设置音频源
            const audioPlayer = document.getElementById('audio-player');
            
            // 移除之前的事件监听器
            audioPlayer.removeEventListener('canplaythrough', updateDuration);
            audioPlayer.removeEventListener('timeupdate', updateProgress);
            audioPlayer.removeEventListener('ended', loadNewSong);
            
            // 设置新的音频源
            audioPlayer.src = audioUrl;
            
            // 重新添加事件监听器
            audioPlayer.addEventListener('canplaythrough', updateDuration);
            audioPlayer.addEventListener('timeupdate', updateProgress);
            audioPlayer.addEventListener('ended', loadNewSong);
            
            // 添加错误处理
            audioPlayer.addEventListener('error', (event) => {
                console.error('音频播放错误:', event);
                // 播放出错时尝试下一首
                setTimeout(() => {
                    loadNewSong();
                }, 1000);
            });
            
            // 自动播放，添加错误处理
            try {
                await audioPlayer.play();
                isPlaying = true;
                document.getElementById('play-btn').textContent = '⏸';
                document.getElementById('player-status').textContent = '正在播放';
                return true;
            } catch (playError) {
                console.error('自动播放失败:', playError);
                isPlaying = false;
                document.getElementById('play-btn').textContent = '▶';
                document.getElementById('player-status').textContent = '已暂停（点击播放）';
                return true;
            }
        }
        
        // 初始化音乐播放器
        async function initMusicPlayer() {
            try {
                // 加载自定义歌单列表
                await fetchPlaylists();

                // 先显示播放器
                const player = document.getElementById('music-player');
                player.style.display = 'block';
                player.style.position = 'fixed';
                player.style.bottom = '20px';
                player.style.right = '20px';
                player.style.zIndex = '9999'; // 确保播放器显示在最顶层
                
                // 请求音乐数据
                await loadNewSong();
            } catch (error) {
                console.error('音乐加载失败:', error);
                const player = document.getElementById('music-player');
                player.style.display = 'block';
                player.style.position = 'fixed';
                player.style.bottom = '20px';
                player.style.right = '20px';
                player.style.zIndex = '9999'; // 确保播放器显示在最顶层
                document.getElementById('player-status').textContent = '加载失败，请刷新页面重试';
            }
        }
        
        // 加载新歌曲
        async function loadNewSong() {
            document.getElementById('player-status').textContent = '正在加载音乐...';
            
            try {
                // 检查是否是首次加载且有保存的模式
                if (currentMusicMode === 'random' && localStorage.getItem('music_mode')) {
                    const savedMode = localStorage.getItem('music_mode');
                    const select = document.getElementById('playlist-select');
                    
                    if (savedMode && savedMode.startsWith('custom_')) {
                         // 自定义歌单逻辑
                         const name = savedMode.substring(7);
                         customPlaylistName = name;
                         currentMusicMode = 'custom';
                         if (select) select.value = savedMode;
                    } else if (savedMode && savedMode !== 'random') {
                        currentMusicMode = savedMode;
                        if (select) select.value = savedMode;
                    }
                }

                if (currentMusicMode === 'custom') {
                    await loadCustomPlaylistSong();
                    return;
                }
                
                // 获取当前音乐模式
                currentMusicMode = await getCurrentMusicMode();
                
                if (currentMusicMode === 'hoyo') {
                    // HOYO-MiX模式
                    await loadHoyoSong();
                    return;
                }
                
                // 随机音乐模式
                // 请求音乐数据
                const response = await fetch('https://api.qqsuu.cn/api/dm-randmusic?sort=%E7%83%AD%E6%AD%8C%E6%A6%9C&format=json');
                const data = await response.json();
                
                if (data.code === 1 && data.data) {
                    currentSong = data.data;
                    
                    // 更新歌曲信息
                    document.getElementById('song-title').textContent = `${currentSong.name} - ${currentSong.artistsname}`;
                    document.getElementById('artist-name').textContent = currentSong.artistsname;
                    
                    // 在进度条上边显示歌曲信息
                    const progressSongInfo = document.getElementById('progress-song-info');
                    progressSongInfo.textContent = `${currentSong.name} - ${currentSong.artistsname}`;
                    
                    // 设置专辑图片，确保使用HTTPS
                    const albumImage = document.getElementById('album-image');
                    let picUrl = currentSong.picurl;
                    if (picUrl.startsWith('http://')) {
                        picUrl = picUrl.replace('http://', 'https://');
                    }
                    albumImage.src = picUrl;
                    albumImage.style.display = 'block';
                    
                    // 请求新的音乐API
                    let audioUrl = null;
                    let songId = null;
                    const songName = encodeURIComponent(currentSong.name + ' ' + currentSong.artistsname);
                    
                    // 从URL中提取歌曲ID
                    const url = currentSong.url;
                    const idMatch = url.match(/id=(\d+)/);
                    if (idMatch && idMatch[1]) {
                        songId = idMatch[1];
                        console.log(`[音乐播放器] 从URL中提取到歌曲ID: ${songId}`);
                    }
                    
                    // 优先使用ID请求音乐链接，最多重试3次
                    if (songId) {
                        let retryCount = 0;
                        const maxRetries = 3;
                        
                        while (retryCount < maxRetries && !audioUrl) {
                            try {
                                const apiUrl = `https://api.vkeys.cn/v2/music/netease?id=${songId}`;
                                console.log(`[音乐播放器] 使用ID请求音乐链接: ${apiUrl} (${retryCount + 1}/${maxRetries})`);
                                
                                const newResponse = await fetch(apiUrl);
                                const newData = await newResponse.json();
                                
                                console.log(`[音乐播放器] ID请求返回结果:`, newData);
                                
                                if (newData.code === 200 && newData.data && newData.data.url) {
                                    audioUrl = newData.data.url;
                                    console.log(`[音乐播放器] ID请求成功获取音乐链接`);
                                    break;
                                } else {
                                    retryCount++;
                                    console.log(`[音乐播放器] ID请求失败，重试... (${retryCount}/${maxRetries})`);
                                    await new Promise(resolve => setTimeout(resolve, 500));
                                }
                            } catch (retryError) {
                                retryCount++;
                                console.log(`[音乐播放器] ID请求出错，重试... (${retryCount}/${maxRetries}):`, retryError);
                                await new Promise(resolve => setTimeout(resolve, 500));
                            }
                        }
                    }
                    
                    // 如果ID请求失败或没有ID，使用歌曲名称请求
                    if (!audioUrl) {
                        try {
                            const apiUrl = `https://api.vkeys.cn/v2/music/netease?word=${songName}&choose=1&quality=9`;
                            console.log(`[音乐播放器] 使用歌曲名称请求音乐链接: ${apiUrl}`);
                            
                            const newResponse = await fetch(apiUrl);
                            const newData = await newResponse.json();
                            
                            console.log(`[音乐播放器] 名称请求返回结果:`, newData);
                            
                            if (newData.code === 200 && newData.data && newData.data.url) {
                                audioUrl = newData.data.url;
                                console.log(`[音乐播放器] 名称请求成功获取音乐链接`);
                            }
                        } catch (nameError) {
                            console.error(`[音乐播放器] 名称请求出错:`, nameError);
                        }
                    }
                    
                    // 如果所有请求都失败，使用原链接作为最后的备选
                    if (!audioUrl) {
                        audioUrl = currentSong.url;
                        console.log(`[音乐播放器] 所有请求失败，使用原链接: ${audioUrl}`);
                    }
                    
                    // 确保使用HTTPS
                    if (audioUrl.startsWith('http://')) {
                        audioUrl = audioUrl.replace('http://', 'https://');
                    }
                    
                    console.log(`[音乐播放器] 最终使用的音乐URL: ${audioUrl}`);
                    
                    // 设置音频源
                    const audioPlayer = document.getElementById('audio-player');
                    
                    // 移除之前的事件监听器
                    audioPlayer.removeEventListener('canplaythrough', updateDuration);
                    audioPlayer.removeEventListener('timeupdate', updateProgress);
                    audioPlayer.removeEventListener('ended', loadNewSong);
                    
                    // 设置新的音频源
                    audioPlayer.src = audioUrl;
                    

                    
                    // 重新添加事件监听器
                    audioPlayer.addEventListener('canplaythrough', updateDuration);
                    audioPlayer.addEventListener('timeupdate', updateProgress);
                    audioPlayer.addEventListener('ended', loadNewSong);
                    
                    // 添加错误处理
                    audioPlayer.addEventListener('error', (event) => {
                        console.error('音频播放错误:', event);
                        // 播放出错时不做任何操作，也不切歌曲
                        document.getElementById('player-status').textContent = '播放出错';
                    });
                    
                    // 自动播放，添加错误处理
                    try {
                        await audioPlayer.play();
                        isPlaying = true;
                        document.getElementById('play-btn').textContent = '⏸';
                        document.getElementById('player-status').textContent = '正在播放';
                    } catch (playError) {
                        console.error('自动播放失败:', playError);
                        isPlaying = false;
                        document.getElementById('play-btn').textContent = '▶';
                        document.getElementById('player-status').textContent = '已暂停（点击播放）';
                    }
                } else {
                    document.getElementById('player-status').textContent = '加载失败，请刷新页面重试';
                }
            } catch (error) {
                console.error('加载歌曲失败:', error);
                document.getElementById('player-status').textContent = '加载失败，请刷新页面重试';
            }
        }
        
        // 切换播放/暂停
        async function togglePlay() {
            const audioPlayer = document.getElementById('audio-player');
            const playBtn = document.getElementById('play-btn');
            
            // 检查用户设置
            const isUserEnabled = localStorage.getItem('setting-music-player') !== 'false';
            
            // 检查服务器配置是否在HTML中渲染了音乐播放器
            const player = document.getElementById('music-player');
            const isServerEnabled = !!player;
            
            // 如果服务器未启用音乐播放
            if (!isServerEnabled) {
                // 显示服务器未启用提示
                showSystemModal(
                    '提示 - 音乐播放器',
                    '服务器未启用音乐播放，请联系系统管理员开启',
                    'warning'
                );
                return;
            }
            
            // 如果用户设置中未开启音乐播放器
            if (!isUserEnabled) {
                // 显示设置未开启提示
                showSystemModal(
                    '提示 - 音乐播放器',
                    '设置中未开启音乐播放器，请检查设置',
                    'warning'
                );
                return;
            }
            
            if (isPlaying) {
                try {
                    audioPlayer.pause();
                    playBtn.textContent = '▶';
                    document.getElementById('player-status').textContent = '已暂停';
                    isPlaying = false;
                } catch (error) {
                    console.error('暂停播放失败:', error);
                }
            } else {
                try {
                    // 检查是否有有效的音频源
                    if (!audioPlayer.src) {
                        // 重新加载音频源
                        await loadNewSong();
                        return;
                    }
                    
                    await audioPlayer.play();
                    playBtn.textContent = '⏸';
                    document.getElementById('player-status').textContent = '正在播放';
                    isPlaying = true;
                } catch (error) {
                    console.error('播放失败:', error);
                    
                    // 播放失败时，尝试重新请求第二个API获取新的音乐URL
                    try {
                        document.getElementById('player-status').textContent = '尝试重新获取音乐链接...';
                        
                        // 使用歌曲名称构建API请求链接
                        const songName = encodeURIComponent(currentSong.name + ' ' + currentSong.artistsname);
                        const apiUrl = `https://api.vkeys.cn/v2/music/netease?word=${songName}&choose=1&quality=9`;
                        console.log(`[音乐播放器] 重新构建的API请求链接: ${apiUrl}`);
                        
                        // 请求新的API
                        const newResponse = await fetch(apiUrl);
                        const newData = await newResponse.json();
                        
                        // 记录API返回的JSON结果
                        console.log(`[音乐播放器] 重新请求API返回的JSON结果:`, newData);
                        
                        if (newData.code === 200 && newData.data && newData.data.url) {
                            // 获取新的音乐URL
                            const newAudioUrl = newData.data.url;
                            // 确保使用HTTPS
                            const audioUrl = newAudioUrl.startsWith('http://') ? newAudioUrl.replace('http://', 'https://') : newAudioUrl;
                            
                            // 更新音频源
                            audioPlayer.src = audioUrl;
                            // 更新下载链接
                            const downloadLink = document.getElementById('download-link');
                            downloadLink.href = audioUrl;
                            downloadLink.download = `${currentSong.name} - ${currentSong.artistsname}.mp3`;
                            
                            // 再次尝试播放
                            await audioPlayer.play();
                            playBtn.textContent = '⏸';
                            document.getElementById('player-status').textContent = '正在播放';
                            isPlaying = true;
                            console.log(`[音乐播放器] 重新获取音乐链接成功，正在播放`);
                        } else {
                            // API请求失败，更新状态
                            document.getElementById('player-status').textContent = '播放失败，重新获取链接失败';
                        }
                    } catch (retryError) {
                        console.error('重新获取音乐链接失败:', retryError);
                        // 重新请求也失败，更新状态
                        document.getElementById('player-status').textContent = '播放失败';
                    }
                }
            }
        }
        
        // 播放上一首
        async function playPrevious() {
            try {
                await loadNewSong();
            } catch (error) {
                console.error('播放上一首失败:', error);
                document.getElementById('player-status').textContent = '加载失败，请重试';
            }
        }
        
        // 播放下一首
        async function playNext() {
            try {
                await loadNewSong();
            } catch (error) {
                console.error('播放下一首失败:', error);
                document.getElementById('player-status').textContent = '加载失败，请重试';
            }
        }
        
        // 下载音乐
        function downloadMusic() {
            if (currentSong) {
                const audioPlayer = document.getElementById('audio-player');
                const audioUrl = audioPlayer.src;
                const fileName = `${currentSong.name} - ${currentSong.artistsname}.mp3`;
                addDownloadTask(fileName, audioUrl, 0, 'audio');
            }
        }
        
        // 更新进度条
        function updateProgress() {
            const audioPlayer = document.getElementById('audio-player');
            const progress = document.getElementById('progress');
            const currentTime = document.getElementById('current-time');
            
            const duration = audioPlayer.duration;
            const current = audioPlayer.currentTime;
            const progressPercent = (current / duration) * 100;
            
            progress.style.width = `${progressPercent}%`;
            currentTime.textContent = formatTime(current);
        }
        
        // 更新总时长
        function updateDuration() {
            const audioPlayer = document.getElementById('audio-player');
            const duration = document.getElementById('duration');
            duration.textContent = formatTime(audioPlayer.duration);
        }
        
        // 跳转进度
        function seek(event) {
            const audioPlayer = document.getElementById('audio-player');
            const progressBar = document.getElementById('progress-bar');
            const rect = progressBar.getBoundingClientRect();
            const x = event.clientX - rect.left;
            const percent = x / rect.width;
            audioPlayer.currentTime = percent * audioPlayer.duration;
        }
        
        // 切换播放器显示状态
        function togglePlayer() {
            const player = document.getElementById('music-player');
            const toggleBtn = document.getElementById('player-toggle');
            const minimizedToggle = document.getElementById('minimized-toggle');
            
            if (isMinimized) {
                // 恢复正常状态
                player.classList.remove('minimized');
                toggleBtn.textContent = '-';
                minimizedToggle.style.display = 'none';
                isMinimized = false;
            } else {
                // 最小化
                player.classList.add('minimized');
                toggleBtn.textContent = '+';
                minimizedToggle.style.display = 'block';
                isMinimized = true;
            }
        }
        
        // 切换迷你模式
        function toggleMiniMode() {
            const player = document.getElementById('music-player');
            const miniToggleBtn = document.getElementById('mini-toggle-btn');
            
            if (isMiniMinimized) {
                // 恢复正常大小（最小化状态）
                player.classList.remove('mini-minimized');
                player.classList.add('minimized');
                isMiniMinimized = false;
                isMinimized = true;
                // 更新图标为 >
                miniToggleBtn.innerHTML = '&gt;';
            } else {
                // 进入迷你模式
                player.classList.remove('minimized');
                player.classList.add('mini-minimized');
                isMiniMinimized = true;
                isMinimized = false;
                // 更新图标为 <
                miniToggleBtn.innerHTML = '&lt;';
            }
        }
        
        // 切换音量控制显示
        function toggleVolumeControl() {
            const volumeControl = document.getElementById('volume-control');
            volumeControl.style.display = volumeControl.style.display === 'none' ? 'block' : 'none';
        }
        
        // 调整音量
        function adjustVolume(event) {
            const audioPlayer = document.getElementById('audio-player');
            const volumeSlider = document.getElementById('volume-slider');
            const volumeLevel = document.getElementById('volume-level');
            const rect = volumeSlider.getBoundingClientRect();
            const y = event.clientY - rect.top;
            let percent = y / rect.height;
            
            // 转换为从上到下为增大音量
            percent = 1 - Math.max(0, Math.min(1, percent));
            
            audioPlayer.volume = percent;
            volumeLevel.style.height = `${(1 - percent) * 100}%`;
        }
        
        // 按步长调整音量
        function adjustVolumeByStep(step) {
            const audioPlayer = document.getElementById('audio-player');
            const volumeLevel = document.getElementById('volume-level');
            
            audioPlayer.volume = Math.max(0, Math.min(1, audioPlayer.volume + step));
            volumeLevel.style.height = `${(1 - audioPlayer.volume) * 100}%`;
        }
        
        // 切换音乐播放器显示/隐藏
        function toggleMusicPlayer() {
            console.log('toggleMusicPlayer 被调用');
            
            // 检查用户设置
            const isUserEnabled = localStorage.getItem('setting-music-player') !== 'false';
            
            // 检查服务器配置是否在HTML中渲染了音乐播放器
            const player = document.getElementById('music-player');
            const isServerEnabled = !!player;
            
            const musicIcon = document.getElementById('music-icon');
            
            // 如果服务器未启用音乐播放
            if (!isServerEnabled) {
                // 显示服务器未启用提示
                showSystemModal(
                    '提示 - 音乐播放器',
                    '服务器未启用音乐播放，请联系系统管理员开启',
                    'warning'
                );
                return;
            }
            
            // 如果用户设置中未开启音乐播放器
            if (!isUserEnabled) {
                // 显示设置未开启提示
                showSystemModal(
                    '提示 - 音乐播放器',
                    '设置中未开启音乐播放器，请检查设置',
                    'warning'
                );
                return;
            }
            
            const audioPlayer = document.getElementById('audio-player');
            
            console.log('播放器当前显示状态:', player.style.display);
            const isVisible = player.style.display !== 'none';
            
            if (isVisible) {
                // 隐藏播放器
                player.style.display = 'none';
                // 暂停音乐
                audioPlayer.pause();
                // 更新音乐图标为关闭状态（带红色撇号）
                if (musicIcon) {
                    musicIcon.innerHTML = '🎵<span style="color: red; font-size: 12px; position: absolute; top: 5px; right: 5px;">✕</span>';
                    musicIcon.style.position = 'relative';
                }
            } else {
                // 显示播放器
                player.style.display = 'block';
                player.style.zIndex = '9999'; // 确保播放器显示在最顶层
                // 更新音乐图标为正常状态
                if (musicIcon) {
                    musicIcon.innerHTML = '🎵';
                }
            }
            console.log('播放器新显示状态:', player.style.display);
        }
        
        // 显示入群申请弹窗
        function showJoinRequests(groupId) {
            const modal = document.getElementById('join-requests-modal');
            modal.style.display = 'flex';
            loadJoinRequests(groupId);
        }
        
        // 关闭入群申请弹窗
        function closeJoinRequestsModal() {
            const modal = document.getElementById('join-requests-modal');
            modal.style.display = 'none';
        }
        
        // 加载入群申请列表
        async function loadJoinRequests(groupId) {
            const listContainer = document.getElementById('join-requests-list');
            listContainer.innerHTML = '<p style="text-align: center; color: #666; margin: 20px 0;">加载中...</p>';
            
            try {
                const response = await fetch(`get_join_requests.php?group_id=${groupId}`);
                const data = await response.json();
                
                if (data.success && data.requests) {
                    if (data.requests.length === 0) {
                        listContainer.innerHTML = '<p style="text-align: center; color: #666; margin: 20px 0;">暂无入群申请</p>';
                        return;
                    }
                    
                    let html = '';
                    data.requests.forEach(req => {
                        html += `
                            <div style="display: flex; align-items: center; justify-content: space-between; background: #f8f9fa; border-radius: 8px; padding: 15px; margin-bottom: 12px; transition: all 0.2s ease;">
                                <div style="display: flex; align-items: center;">
                                    <div style="width: 48px; height: 48px; border-radius: 50%; overflow: hidden; margin-right: 12px;">
                                        ${req.avatar ? `<img src="${req.avatar}" alt="${req.username}" style="width: 100%; height: 100%; object-fit: cover;">` : `<div style="width: 100%; height: 100%; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); display: flex; align-items: center; justify-content: center; color: white; font-weight: 600;">${req.username.substring(0, 2)}</div>`}
                                    </div>
                                    <div>
                                        <div style="font-weight: 500; color: #333;">${req.username}</div>
                                        <div style="font-size: 12px; color: #666; margin-top: 2px;">${new Date(req.created_at).toLocaleString('zh-CN', {year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute:'2-digit'})}</div>
                                    </div>
                                </div>
                                <div style="display: flex; gap: 8px;">
                                    <button onclick="approveJoinRequest(${req.id}, ${groupId})" style="padding: 6px 16px; background: #4CAF50; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; transition: all 0.2s ease;">批准</button>
                                    <button onclick="rejectJoinRequest(${req.id}, ${groupId})" style="padding: 6px 16px; background: #f44336; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; transition: all 0.2s ease;">拒绝</button>
                                </div>
                            </div>
                        `;
                    });
                    listContainer.innerHTML = html;
                } else {
                    listContainer.innerHTML = '<p style="text-align: center; color: #ff4757; margin: 20px 0;">加载失败，请重试</p>';
                }
            } catch (error) {
                console.error('加载入群申请失败:', error);
                listContainer.innerHTML = '<p style="text-align: center; color: #ff4757; margin: 20px 0;">网络错误，请重试</p>';
            }
        }
        
        // 批准入群申请
        async function approveJoinRequest(requestId, groupId) {
            try {
                const response = await fetch('approve_join_request.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ request_id: requestId, group_id: groupId })
                });
                
                const data = await response.json();
                if (data.success) {
                    // 重新加载申请列表
                    loadJoinRequests(groupId);
                    // 显示成功通知
                    showNotification('已批准入群申请', 'success');
                } else {
                    showNotification('操作失败: ' + data.message, 'error');
                }
            } catch (error) {
                console.error('批准入群申请失败:', error);
                showNotification('网络错误，请重试', 'error');
            }
        }
        
        // 拒绝入群申请
        async function rejectJoinRequest(requestId, groupId) {
            try {
                const response = await fetch('reject_join_request.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ request_id: requestId, group_id: groupId })
                });
                
                const data = await response.json();
                if (data.success) {
                    // 重新加载申请列表
                    loadJoinRequests(groupId);
                    // 显示成功通知
                    showNotification('已拒绝入群申请', 'success');
                } else {
                    showNotification('操作失败: ' + data.message, 'error');
                }
            } catch (error) {
                console.error('拒绝入群申请失败:', error);
                showNotification('网络错误，请重试', 'error');
            }
        }
    </script>
    <?php endif; ?>

    <!-- 系统提示弹窗样式 -->
    <style>
        /* 弹窗容器 - 覆盖所有UI之上 */
        .system-modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.8);
            z-index: 999999;
            display: none;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(5px);
        }
        
        /* 弹窗内容 */
        .system-modal {
            background: white;
            border-radius: 12px;
            padding: 24px;
            max-width: 500px;
            width: 90%;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
            animation: modalSlideIn 0.3s ease-out;
        }
        
        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-50px) scale(0.9);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }
        
        /* 弹窗标题 */
        .system-modal-title {
            font-size: 20px;
            font-weight: bold;
            color: #333;
            margin-bottom: 16px;
            text-align: center;
        }
        
        /* 弹窗内容 */
        .system-modal-content {
            font-size: 16px;
            color: #666;
            line-height: 1.6;
            margin-bottom: 24px;
            text-align: center;
        }
        
        /* 感叹号图标 */
        .exclamation-icon {
            font-size: 48px;
            color: #ff6b35;
            margin-bottom: 16px;
        }
        
        /* 倒计时显示 */
        .countdown-text {
            font-size: 24px;
            font-weight: bold;
            color: #ff6b35;
            margin: 16px 0;
        }
        
        /* 按钮容器 */
        .system-modal-buttons {
            display: flex;
            justify-content: center;
            gap: 12px;
        }
        
        /* 确认按钮 */
        .system-modal-btn {
            padding: 10px 24px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .system-modal-btn.primary {
            background-color: #667eea;
            color: white;
        }
        
        .system-modal-btn.primary:hover {
            background-color: #5a6fd8;
            transform: translateY(-1px);
        }
    </style>

    <!-- 系统提示弹窗HTML -->
    <div id="systemModal" class="system-modal-overlay">
        <div class="system-modal">
            <h2 class="system-modal-title" id="modalTitle">系统提示</h2>
            <div class="system-modal-content">
                <div class="exclamation-icon" id="modalIcon">⚠️</div>
                <div id="modalMessage"></div>
                <div id="modalCountdown" class="countdown-text" style="display: none;"></div>
            </div>
            <div class="system-modal-buttons">
                <button id="modalConfirmBtn" class="system-modal-btn primary">确定</button>
            </div>
        </div>
    </div>
    
    <!-- 公告弹窗HTML -->
    <div id="announcementModal" class="system-modal-overlay">
        <div class="system-modal" style="max-width: 600px;">
            <h2 class="system-modal-title" id="announcementTitle">系统公告</h2>
            <div class="system-modal-content">
                <div class="exclamation-icon" style="color: #667eea;">📢</div>
                <div id="announcementContent" style="margin: 16px 0; font-size: 16px; line-height: 1.6;"></div>
                <div id="announcementFooter" style="font-size: 12px; color: #666; text-align: right; margin-top: 16px;"></div>
            </div>
            <div class="system-modal-buttons">
                <button id="announcementReceivedBtn" class="system-modal-btn primary">收到</button>
            </div>
        </div>
    </div>

    <!-- 系统提示弹窗JavaScript -->
    <script>
        // 全局变量
        let countdownInterval = null;
        let countdownSeconds = 0;
        
        // 显示系统弹窗
        function showSystemModal(title, message, type = 'info', options = {}) {
            const modal = document.getElementById('systemModal');
            const modalTitle = document.getElementById('modalTitle');
            const modalMessage = document.getElementById('modalMessage');
            const modalIcon = document.getElementById('modalIcon');
            const modalCountdown = document.getElementById('modalCountdown');
            const modalConfirmBtn = document.getElementById('modalConfirmBtn');
            
            // 设置标题
            modalTitle.textContent = title;
            
            // 设置图标
            if (type === 'warning') {
                modalIcon.textContent = '⚠️';
            } else if (type === 'error') {
                modalIcon.textContent = '❌';
            } else if (type === 'success') {
                modalIcon.textContent = '✅';
            } else {
                modalIcon.textContent = 'ℹ️';
            }
            
            // 设置消息
            modalMessage.innerHTML = message.replace(/\\n/g, '<br>');
            
            // 处理倒计时
            if (options.countdown) {
                countdownSeconds = options.countdown;
                modalCountdown.textContent = `${countdownSeconds}s`;
                modalCountdown.style.display = 'block';
                
                // 清除之前的定时器
                if (countdownInterval) {
                    clearInterval(countdownInterval);
                }
                
                // 开始倒计时
                countdownInterval = setInterval(() => {
                    countdownSeconds--;
                    modalCountdown.textContent = `${countdownSeconds}s`;
                    
                    if (countdownSeconds <= 0) {
                        clearInterval(countdownInterval);
                        modalCountdown.style.display = 'none';
                        
                        // 倒计时结束后执行回调
                        if (options.onCountdownEnd) {
                            options.onCountdownEnd();
                        }
                    }
                }, 1000);
            } else {
                modalCountdown.style.display = 'none';
            }
            
            // 设置确认按钮回调
            modalConfirmBtn.onclick = () => {
                if (countdownInterval) {
                    clearInterval(countdownInterval);
                    countdownInterval = null;
                }
                
                modal.style.display = 'none';
                
                if (options.onConfirm) {
                    options.onConfirm();
                }
            };
            
            // 显示弹窗
            modal.style.display = 'flex';
        }
        
        // 显示封禁提示
        function showBanNotification(reason, endTime) {
            showSystemModal(
                '系统提示 - 你已被封禁',
                `系统提示您：<br>您因为 ${reason} 被系统管理员封禁至 ${endTime} <br>如有疑问请发送邮件到563245597@qq.com或3316225191@qq.com`,
                'error',
                {
                    countdown: 10,
                    onCountdownEnd: () => {
                        // 10秒后自动退出登录
                        window.location.href = 'logout.php';
                    },
                    onConfirm: () => {
                        // 点击确定也退出登录
                        window.location.href = 'logout.php';
                    }
                }
            );
        }
        
        // 显示禁言提示
        function showMuteNotification(totalTime, remainingTime) {
            showSystemModal(
                '系统提示 - 您已被禁言',
                `您因为发送违禁词被系统禁言${totalTime}，还剩下${remainingTime}`,
                'warning',
                {
                    countdown: remainingTime,
                    onCountdownEnd: () => {
                        // 禁言时间结束后，可以执行相关操作
                    },
                    onConfirm: () => {
                        // 点击确定关闭弹窗
                    }
                }
            );
        }
        
        // 显示被踢出群聊提示
        function showKickNotification(kickedBy, groupName) {
            showSystemModal(
                '提示 - 你已被踢出群聊',
                `您已被 ${kickedBy} 踢出了 ${groupName}`,
                'warning',
                {
                    onConfirm: () => {
                        // 点击确定关闭弹窗
                    }
                }
            );
        }
        
        // 显示群聊封禁提示
        function showGroupBanNotification(groupName, reason, endTime) {
            showSystemModal(
                `提示 - 群聊 ${groupName} 被封禁`,
                `${groupName} 因 ${reason} 被封禁至 ${endTime} <br>在此期间，您无法进入群聊，如您是该群群主或管理员请提交反馈，带我们核实后会给您回复，请保证邮箱畅通`,
                'warning',
                {
                    onConfirm: () => {
                        // 点击确定跳转到主页面
                        window.location.href = 'index.php';
                    }
                }
            );
        }
        
        // 示例：可以通过WebSocket或其他方式调用这些函数
        // 例如：showBanNotification('违规行为', '2024-12-31 23:59:59');
        // 例如：showMuteNotification('1小时');
        // 例如：showKickNotification('群主', '测试群聊');
        // 例如：showGroupBanNotification('测试群聊', '违规内容', '2024-12-31 23:59:59');
        
        // 公告系统相关函数
        
        // 显示公告弹窗
        function showAnnouncementModal(announcement) {
            const modal = document.getElementById('announcementModal');
            const titleElement = document.getElementById('announcementTitle');
            const contentElement = document.getElementById('announcementContent');
            const footerElement = document.getElementById('announcementFooter');
            const receivedBtn = document.getElementById('announcementReceivedBtn');
            
            // 设置公告内容
            titleElement.textContent = `系统公告 - ${announcement.title}`;
            contentElement.textContent = announcement.content;
            
            // 格式化日期
            const date = new Date(announcement.created_at);
            const formattedDate = date.toLocaleString('zh-CN', {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit'
            });
            
            footerElement.innerHTML = `发布时间：${formattedDate} | 发布人：${announcement.admin_name}`;
            
            // 添加收到按钮的点击事件
            receivedBtn.onclick = async () => {
                // 标记公告为已读
                await markAnnouncementAsRead(announcement.id);
                // 隐藏弹窗
                modal.style.display = 'none';
            };
            
            // 显示弹窗
            modal.style.display = 'flex';
        }
        
        // 标记公告为已读
        async function markAnnouncementAsRead(announcementId) {
            try {
                const response = await fetch('mark_announcement_read.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    credentials: 'include',
                    body: JSON.stringify({
                        announcement_id: announcementId
                    })
                });
                
                const data = await response.json();
                if (!data.success) {
                    console.error('标记公告为已读失败:', data.message);
                }
            } catch (error) {
                console.error('标记公告为已读失败:', error);
            }
        }
        
        // 获取并显示最新公告
        async function checkAndShowAnnouncement() {
            try {
                const response = await fetch('get_announcements.php', {
                    credentials: 'include'
                });
                
                const data = await response.json();
                
                if (data.success && data.has_new_announcement && !data.has_read) {
                    // 有新公告且未读，显示弹窗
                    showAnnouncementModal(data.announcement);
                }
            } catch (error) {
                console.error('获取公告失败:', error);
            }
        }
        
        // 页面加载完成后检查公告
        document.addEventListener('DOMContentLoaded', function() {
            // 延迟一秒检查公告，确保页面其他内容已加载完成
            setTimeout(checkAndShowAnnouncement, 1000);
            
            // 检查用户封禁状态
            <?php if ($ban_info): ?>
                showBanNotification(
                    '<?php echo addslashes($ban_info['reason']); ?>',
                    '<?php echo $ban_info['expires_at'] ? $ban_info['expires_at'] : '永久'; ?>'
                );
            <?php endif; ?>
            
            // 检查用户是否需要设置密保
            <?php if ($need_security_question): ?>
                // 强制显示密保设置弹窗，阻止进入其他内容
                document.getElementById('security-question-modal').style.display = 'flex';
                document.getElementById('security-question-close').style.display = 'none';
            <?php endif; ?>
        });
        
        // 密保设置相关函数
        function showSecurityQuestionModal() {
            document.getElementById('security-question-modal').style.display = 'flex';
        }
        
        function closeSecurityQuestionModal() {
            document.getElementById('security-question-modal').style.display = 'none';
        }
    </script>

    <!-- 菜单面板 -->
    <div class="menu-panel" id="menu-panel">
        <div class="menu-header">
            <div class="menu-avatar">
                <?php echo substr($username, 0, 2); ?>
            </div>
            <div class="menu-username"><?php echo htmlspecialchars($username); ?></div>
            <div class="menu-email"><?php echo $current_user['email']; ?></div>
            <div class="menu-ip">IP: <?php echo $user_ip; ?></div>
        </div>
        <div class="menu-items">
            <button class="menu-item" onclick="showAddFriendModal()">添加好友</button>
            <button class="menu-item" onclick="showFriendRequests()">好友申请</button>
            <button class="menu-item" onclick="showCreateGroupModal()">创建群聊</button>
            <button class="menu-item" onclick="showScanLoginModal()">扫码登录PC端</button>
            <a href="https://github.com/LzdqesjG/modern-chat" target="_blank" class="menu-item">GitHub开源地址</a>
            <button class="menu-item" onclick="openSettingsModal()">设置</button>
            <button class="menu-item menu-item-danger" onclick="window.location.href='logout.php'">退出登录</button>
        </div>
    </div>

    <!-- 遮罩层 -->
    <div class="overlay" id="overlay" onclick="toggleMenu()"></div>

    <!-- 图片查看器 -->
    <div class="image-viewer" id="image-viewer" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.9); z-index: 9999; justify-content: center; align-items: center;">
        <button class="image-viewer-close" id="image-viewer-close" style="position: absolute; top: 20px; right: 20px; background: rgba(255, 255, 255, 0.2); color: white; border: none; border-radius: 50%; width: 40px; height: 40px; font-size: 24px; cursor: pointer; display: flex; align-items: center; justify-content: center;">
            ×
        </button>
        <img id="image-viewer-content" style="max-width: 90%; max-height: 90%; object-fit: contain; transition: transform 0.3s ease;" />
    </div>

    <script>
        // 切换菜单
        function toggleMenu() {
            const menuPanel = document.getElementById('menu-panel');
            const overlay = document.getElementById('overlay');
            menuPanel.classList.toggle('open');
            overlay.classList.toggle('open');
        }

        // 显示添加好友模态框
        function showAddFriendModal() {
            const modal = document.getElementById('add-friend-modal');
            if (modal) {
                modal.style.display = 'flex';
            }
            toggleMenu();
        }

        // 显示好友申请
        function showFriendRequests() {
            const modal = document.getElementById('friend-requests-modal');
            if (modal) {
                modal.style.display = 'flex';
            }
            toggleMenu();
        }

        // 显示创建群聊模态框
        function showCreateGroupModal() {
            const modal = document.getElementById('create-group-modal');
            if (modal) {
                modal.style.display = 'flex';
                loadFriendsForGroup();
            }
            toggleMenu();
        }



        // 打开图片查看器
        function openImageViewer(imageUrl) {
            const imageViewer = document.getElementById('image-viewer');
            const imageViewerContent = document.getElementById('image-viewer-content');
            if (imageViewer && imageViewerContent) {
                imageViewerContent.src = imageUrl;
                // 重置缩放和拖拽状态
                currentScale = 1;
                translateX = 0;
                translateY = 0;
                imageViewerContent.style.transform = 'translate(0, 0) scale(1)';
                imageViewer.style.display = 'flex';
            }
        }

        // 关闭图片查看器
        function closeImageViewer() {
            const imageViewer = document.getElementById('image-viewer');
            if (imageViewer) {
                imageViewer.style.display = 'none';
                // 重置缩放和拖拽状态
                currentScale = 1;
                translateX = 0;
                translateY = 0;
            }
        }

        // 点击图片打开查看器
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('message-image')) {
                const imageUrl = e.target.src;
                openImageViewer(imageUrl);
            }
        });

        // 关闭图片查看器事件
        const closeBtn = document.getElementById('image-viewer-close');
        if (closeBtn) {
            closeBtn.onclick = closeImageViewer;
        }

        // 点击查看器背景关闭
        const imageViewer = document.getElementById('image-viewer');
        if (imageViewer) {
            imageViewer.addEventListener('click', function(e) {
                if (e.target === imageViewer) {
                    closeImageViewer();
                }
            });
        }

        // 键盘ESC键关闭
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeImageViewer();
            }
        });

        // 图片缩放和拖拽功能
        let currentScale = 1;
        let translateX = 0;
        let translateY = 0;
        let isDragging = false;
        let startX = 0;
        let startY = 0;
        let startDistance = 0;
        let startScale = 0;
        let lastTouchPoints = [];

        const imageViewerContent = document.getElementById('image-viewer-content');
        if (imageViewerContent) {
            // 鼠标滚轮缩放
            imageViewerContent.addEventListener('wheel', function(e) {
                e.preventDefault();
                const delta = e.deltaY > 0 ? -0.1 : 0.1;
                currentScale = Math.min(Math.max(0.1, currentScale + delta), 5);
                imageViewerContent.style.transform = `translate(${translateX}px, ${translateY}px) scale(${currentScale})`;
            });

            // 鼠标拖拽
            imageViewerContent.addEventListener('mousedown', function(e) {
                isDragging = true;
                startX = e.clientX - translateX;
                startY = e.clientY - translateY;
            });

            document.addEventListener('mousemove', function(e) {
                if (isDragging) {
                    translateX = e.clientX - startX;
                    translateY = e.clientY - startY;
                    imageViewerContent.style.transform = `translate(${translateX}px, ${translateY}px) scale(${currentScale})`;
                }
            });

            document.addEventListener('mouseup', function() {
                isDragging = false;
            });

            // 触摸事件：双指缩放
            imageViewerContent.addEventListener('touchstart', function(e) {
                e.preventDefault();
                const touches = e.touches;
                if (touches.length === 2) {
                    // 双指触摸开始，记录初始距离和缩放值
                    startDistance = getDistance(touches[0], touches[1]);
                    startScale = currentScale;
                    lastTouchPoints = [touches[0], touches[1]];
                } else if (touches.length === 1) {
                    // 单指触摸开始，记录初始位置
                    isDragging = true;
                    startX = touches[0].clientX - translateX;
                    startY = touches[0].clientY - translateY;
                }
            });

            imageViewerContent.addEventListener('touchmove', function(e) {
                e.preventDefault();
                const touches = e.touches;
                if (touches.length === 2) {
                    // 双指触摸移动，计算缩放比例
                    const currentDistance = getDistance(touches[0], touches[1]);
                    const scaleFactor = currentDistance / startDistance;
                    currentScale = Math.min(Math.max(0.1, startScale * scaleFactor), 5);
                    
                    // 计算双指中心点
                    const centerX = (touches[0].clientX + touches[1].clientX) / 2;
                    const centerY = (touches[0].clientY + touches[1].clientY) / 2;
                    
                    // 计算上次中心点
                    const lastCenterX = (lastTouchPoints[0].clientX + lastTouchPoints[1].clientX) / 2;
                    const lastCenterY = (lastTouchPoints[0].clientY + lastTouchPoints[1].clientY) / 2;
                    
                    // 计算位移变化
                    const deltaX = centerX - lastCenterX;
                    const deltaY = centerY - lastCenterY;
                    
                    // 更新位移
                    translateX += deltaX * currentScale;
                    translateY += deltaY * currentScale;
                    
                    // 更新图片变换
                    imageViewerContent.style.transform = `translate(${translateX}px, ${translateY}px) scale(${currentScale})`;
                    
                    // 保存当前触摸点
                    lastTouchPoints = [touches[0], touches[1]];
                } else if (touches.length === 1 && isDragging) {
                    // 单指触摸移动，拖拽图片
                    translateX = touches[0].clientX - startX;
                    translateY = touches[0].clientY - startY;
                    imageViewerContent.style.transform = `translate(${translateX}px, ${translateY}px) scale(${currentScale})`;
                }
            });

            imageViewerContent.addEventListener('touchend', function(e) {
                isDragging = false;
            });

            // 计算两点之间的距离
            function getDistance(touch1, touch2) {
                const deltaX = touch2.clientX - touch1.clientX;
                const deltaY = touch2.clientY - touch1.clientY;
                return Math.sqrt(deltaX * deltaX + deltaY * deltaY);
            }
            
            // 打开图片查看器时，确保图片按屏幕大小正确缩放
            imageViewerContent.addEventListener('load', function() {
                // 重置缩放和位移
                currentScale = 1;
                translateX = 0;
                translateY = 0;
                
                // 获取图片的实际尺寸
                const imgWidth = this.naturalWidth;
                const imgHeight = this.naturalHeight;
                
                // 获取屏幕可用尺寸
                const screenWidth = window.innerWidth * 0.95; // 留5%的边距
                const screenHeight = window.innerHeight * 0.95; // 留5%的边距
                
                // 计算缩放比例，确保图片完全适应屏幕
                const scaleX = screenWidth / imgWidth;
                const scaleY = screenHeight / imgHeight;
                const fitScale = Math.min(scaleX, scaleY);
                
                // 如果图片比屏幕小，就使用原始大小
                currentScale = Math.min(1, fitScale);
                
                // 更新图片变换
                this.style.transform = `translate(0, 0) scale(${currentScale})`;
            });
        }
    </script>
<!-- GitHub角标 -->
    <a href="https://github.com/LzdqesjG/modern-chat" class="github-corner" aria-label="View source on GitHub"><svg width="80" height="80" viewBox="0 0 250 250" style="fill:#151513; color:#fff; position: absolute; top: 0; border: 0; right: 0;" aria-hidden="true"><path d="M0,0 L115,115 L130,115 L142,142 L250,250 L250,0 Z"/><path d="M128.3,109.0 C113.8,99.7 119.0,89.6 119.0,89.6 C122.0,82.7 120.5,78.6 120.5,78.6 C119.2,72.0 123.4,76.3 123.4,76.3 C127.3,80.9 125.5,87.3 125.5,87.3 C122.9,97.6 130.6,101.9 134.4,103.2" fill="currentColor" style="transform-origin: 130px 106px;" class="octo-arm"/><path d="M115.0,115.0 C114.9,115.1 118.7,116.5 119.8,115.4 L133.7,101.6 C136.9,99.2 139.9,98.4 142.2,98.6 C133.8,88.0 127.5,74.4 143.8,58.0 C148.5,53.4 154.0,51.2 159.7,51.0 C160.3,49.4 163.2,43.6 171.4,40.1 C171.4,40.1 176.1,42.5 178.8,56.2 C183.1,58.6 187.2,61.8 190.9,65.4 C194.5,69.0 197.7,73.2 200.1,77.6 C213.8,80.2 216.3,84.9 216.3,84.9 C212.7,93.1 206.9,96.0 205.4,96.6 C205.1,102.4 203.0,107.8 198.3,112.5 C181.9,128.9 168.3,122.5 157.7,114.1 C157.9,116.9 156.7,120.9 152.7,124.9 L141.0,136.5 C139.8,137.7 141.6,141.9 141.8,141.8 Z" fill="currentColor" class="octo-body"/></svg></a><style>.github-corner:hover .octo-arm{animation:octocat-wave 560ms ease-in-out}@keyframes octocat-wave{0%,100%{transform:rotate(0)}20%,60%{transform:rotate(-25deg)}40%,80%{transform:rotate(10deg)}}@media (max-width:500px){.github-corner:hover .octo-arm{animation:none}.github-corner .octo-arm{animation:octocat-wave 560ms ease-in-out}}</style>
        <script>
        // 手机号绑定相关
        let bindGeetestCaptcha = null;
        let bindSmsCountdownTimer = null;
        const BIND_SMS_COOLDOWN_KEY = 'bind_sms_cooldown_end_time';
        
        // 确保这些函数在全局作用域可访问
        window.showPhoneBindModal = showPhoneBindModal;
        window.closePhoneBindModal = closePhoneBindModal;
        window.submitPhoneBind = submitPhoneBind;
        
        // 绑定获取验证码按钮事件
        document.addEventListener('DOMContentLoaded', function() {
            const getBindCodeBtn = document.getElementById('get-bind-code-btn');
            if (getBindCodeBtn) {
                getBindCodeBtn.addEventListener('click', function() {
                    if (this.disabled) return;
                    
                    const phone = document.getElementById('bind-phone-input').value;
                    if (!/^1[3-9]\d{9}$/.test(phone)) {
                        alert('请输入有效的11位手机号');
                        return;
                    }
                    
                    if (!bindGeetestCaptcha) {
                         alert('验证码组件初始化失败');
                         return;
                    }

                    const validate = bindGeetestCaptcha.getValidate();
                    if (!validate) {
                        alert('请先完成验证码验证');
                        return;
                    }
                    
                    const formData = new FormData();
                    formData.append('phone', phone);
                    formData.append('geetest_challenge', validate.lot_number);
                    formData.append('geetest_validate', validate.captcha_output);
                    formData.append('geetest_seccode', validate.pass_token);
                    formData.append('gen_time', validate.gen_time);
                    formData.append('captcha_id', '55574dfff9c40f2efeb5a26d6d188245');
                    
                    this.disabled = true;
                    
                    fetch('send_sms.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('验证码已发送');
                            startBindSmsCountdown(60);
                        } else {
                            alert(data.message || '发送失败');
                            if (!data.message.includes('秒后')) {
                                 resetBindSmsButton();
                            }
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('发送失败，请重试');
                        resetBindSmsButton();
                    });
                });
            }
        });

        function showPhoneBindModal() {
            document.getElementById('phone-bind-modal').style.display = 'flex';
            
            // 设置标题
            const currentPhone = '<?php echo $current_user['phone'] ?? ''; ?>';
            document.getElementById('phone-bind-title').textContent = currentPhone ? '修改绑定手机号' : '绑定手机号';
            
            // 默认禁用按钮
            const btn = document.getElementById('get-bind-code-btn');
            if (!localStorage.getItem(BIND_SMS_COOLDOWN_KEY)) {
                btn.disabled = true;
                btn.style.background = '#ccc';
                btn.style.cursor = 'not-allowed';
            }

            // 初始化极验
            if (!bindGeetestCaptcha && typeof initGeetest4 === 'function') {
                initGeetest4({
                    captchaId: '55574dfff9c40f2efeb5a26d6d188245'
                }, function (captcha) {
                    bindGeetestCaptcha = captcha;
                    captcha.appendTo("#bind-phone-captcha");
                    
                    captcha.onSuccess(function() {
                        const btn = document.getElementById('get-bind-code-btn');
                        if (!localStorage.getItem(BIND_SMS_COOLDOWN_KEY)) {
                            btn.disabled = false;
                            btn.style.background = '#667eea'; // 使用移动端主题色
                            btn.style.cursor = 'pointer';
                        }
                    });
                });
            } else if (bindGeetestCaptcha) {
                bindGeetestCaptcha.reset();
            } else if (!bindGeetestCaptcha) {
                 console.error('initGeetest4 未定义，请检查极验JS库是否加载');
                 // 尝试动态加载JS
                 const script = document.createElement('script');
                 script.src = 'https://static.geetest.com/v4/gt4.js';
                 script.onload = function() {
                     showPhoneBindModal(); // 重新调用
                 };
                 script.onerror = function() {
                     alert('安全组件加载失败，请刷新页面重试');
                 };
                 document.body.appendChild(script);
                 return;
            }
            
            // 检查倒计时
            checkBindSmsCooldown();
        }
        
        function closePhoneBindModal() {
            document.getElementById('phone-bind-modal').style.display = 'none';
        }
        
        function checkBindSmsCooldown() {
            const endTime = localStorage.getItem(BIND_SMS_COOLDOWN_KEY);
            if (endTime) {
                const now = Date.now();
                const remaining = Math.ceil((parseInt(endTime) - now) / 1000);
                
                if (remaining > 0) {
                    startBindSmsCountdown(remaining);
                } else {
                    localStorage.removeItem(BIND_SMS_COOLDOWN_KEY);
                    resetBindSmsButton();
                }
            }
        }
        
        function startBindSmsCountdown(seconds) {
            const btn = document.getElementById('get-bind-code-btn');
            
            if (!localStorage.getItem(BIND_SMS_COOLDOWN_KEY)) {
                const endTime = Date.now() + (seconds * 1000);
                localStorage.setItem(BIND_SMS_COOLDOWN_KEY, endTime);
            }
            
            btn.disabled = true;
            btn.style.background = '#ccc';
            btn.style.cursor = 'not-allowed';
            
            clearInterval(bindSmsCountdownTimer);
            
            function updateBtn() {
                btn.textContent = `${seconds}s`;
                if (seconds <= 0) {
                    clearInterval(bindSmsCountdownTimer);
                    localStorage.removeItem(BIND_SMS_COOLDOWN_KEY);
                    resetBindSmsButton();
                }
                seconds--;
            }
            
            updateBtn();
            bindSmsCountdownTimer = setInterval(updateBtn, 1000);
        }
        
        function resetBindSmsButton() {
            const btn = document.getElementById('get-bind-code-btn');
            if (bindGeetestCaptcha && bindGeetestCaptcha.getValidate()) {
                btn.disabled = false;
                btn.style.background = '#667eea'; // 使用移动端主题色
                btn.style.cursor = 'pointer';
            } else {
                btn.disabled = true;
                btn.style.background = '#ccc';
                btn.style.cursor = 'not-allowed';
            }
            btn.textContent = '获取验证码';
        }
        
        // 提交绑定
        function submitPhoneBind() {
            const phone = document.getElementById('bind-phone-input').value;
            const code = document.getElementById('bind-sms-code').value;
            
            if (!/^1[3-9]\d{9}$/.test(phone)) {
                alert('请输入有效的11位手机号');
                return;
            }
            if (!code || code.length !== 6) {
                alert('请输入6位验证码');
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'bind_phone');
            formData.append('phone', phone);
            formData.append('sms_code', code);
            
            fetch('update_profile.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('绑定成功');
                    closePhoneBindModal();
                    location.reload(); // 刷新页面更新状态
                } else {
                    alert(data.message || '绑定失败');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('请求失败，请重试');
            });
        }
    </script>
</body>
</html>