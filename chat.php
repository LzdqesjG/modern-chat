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
    /** @var \PDO $conn */
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
        if ($conn instanceof PDO) {
            // @phpstan-ignore-next-line
            $conn->exec($create_tables_sql);
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

// 如果是手机设备，跳转到移动端聊天页面
if (isMobileDevice()) {
    header('Location: mobilechat.php');
    exit;
}

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// 检查是否登录
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

// 自动创建点歌群聊和Music_Bot用户
try {
    // 1. 检查并添加Music_all_group字段
    $stmt = $conn->prepare("SHOW COLUMNS FROM `groups` LIKE 'Music_all_group'");
    $stmt->execute();
    $column_exists = $stmt->fetch();
    
    if (!$column_exists) {
        $conn->exec("ALTER TABLE `groups` ADD COLUMN Music_all_group INT DEFAULT 0 AFTER is_muted");
        error_log("Added Music_all_group column to groups table");
    }
    
    // 2. 创建点歌群聊
    $stmt = $conn->prepare("SELECT id FROM `groups` WHERE Music_all_group = 1");
    $stmt->execute();
    $music_group = $stmt->fetch();
    
    if (!$music_group) {
        // 查找第一个系统管理员
        $stmt = $conn->prepare("SELECT id FROM users WHERE is_admin = 1 ORDER BY id ASC LIMIT 1");
        $stmt->execute();
        $admin = $stmt->fetch();
        
        if ($admin) {
            $stmt = $conn->prepare("INSERT INTO `groups` (name, creator_id, owner_id, Music_all_group) VALUES (?, ?, ?, 1)");
            $stmt->execute(['点歌群聊', $admin['id'], $admin['id']]);
            $music_group_id = $conn->lastInsertId();
            error_log("Created music group chat with ID: $music_group_id");
        }
    }
    
    // 3. 创建Music_Bot用户
    $stmt = $conn->prepare("SELECT id FROM users WHERE username = 'Music_Bot'");
    $stmt->execute();
    $music_bot = $stmt->fetch();
    
    if (!$music_bot) {
        $password = password_hash('MusicBot123!', PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO users (username, email, password, is_admin) VALUES (?, ?, ?, 0)");
        $stmt->execute(['Music_Bot', 'music_bot@example.com', $password]);
        $music_bot_id = $conn->lastInsertId();
        error_log("Created Music_Bot user with ID: $music_bot_id");
    }
    
    // 4. 将Music_Bot添加为点歌群聊的管理员
    if (isset($music_group_id) && isset($music_bot_id)) {
        $stmt = $conn->prepare("SELECT id FROM group_members WHERE group_id = ? AND user_id = ?");
        $stmt->execute([$music_group_id, $music_bot_id]);
        $member_exists = $stmt->fetch();
        
        if (!$member_exists) {
            $stmt = $conn->prepare("INSERT INTO group_members (group_id, user_id, role) VALUES (?, ?, 'admin')");
            $stmt->execute([$music_group_id, $music_bot_id]);
            error_log("Added Music_Bot as admin to music group");
        }
    }
    
    // 5. 确保temp_song_config.json文件存在
    $config_file = 'config/temp_song_config.json';
    if (!file_exists($config_file)) {
        $default_config = [
            '歌单' => [
                'type' => 'qqmusic',
                'data' => [
                    [
                        '星之映像' => '1'
                    ]
                ]
            ]
        ];
        file_put_contents($config_file, json_encode($default_config, JSON_PRETTY_PRINT));
        error_log("Created temp_song_config.json file");
    }
} catch (PDOException $e) {
    error_log("Error creating music group or bot: " . $e->getMessage());
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

// 检查是否是管理员
$is_admin = isset($current_user['is_admin']) && $current_user['is_admin'];

// 春节相关时间判断 (使用Lunar类动态计算)
require_once 'Lunar.php';
$lunar_config = Lunar::getConfig();
$is_music_locked = $lunar_config['is_music_locked'];

// 检查时间是否在早上11点到晚上11点之间
$current_hour = date('H');
$is_radio_period = $current_hour >= 11 && $current_hour < 23;

// 背景图片逻辑
$default_bg = 'https://bing.biturl.top/?resolution=1920&format=image&index=0&mkt=zh-CN';
$bg_url = $default_bg;

// 优先使用用户自定义背景
if (isset($current_user['background_image']) && !empty($current_user['background_image'])) {
    $bg_url = $current_user['background_image'];
}

if ($lunar_config['is_bg_active']) {
    $pic_dir = __DIR__ . '/new_year_pic';
    if (is_dir($pic_dir)) {
        $files = scandir($pic_dir);
        $images = [];
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            // check extension
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) {
                $images[] = $file;
            }
        }
        if (!empty($images)) {
            $random_image = $images[array_rand($images)];
            $bg_url = 'new_year_pic/' . $random_image;
        }
    }
}

// 保持变量兼容性
$is_spring_festival_period = $lunar_config['is_bg_active'];
$is_after_new_year_eve = $lunar_config['is_bg_active']; // 简化处理，仅在春节背景活动期间为真

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

// 如果没有选中的聊天对象，自动选择第一个好友或群聊
if (!$selected_id) {
    if ($chat_type === 'friend' && !empty($friends) && isset($friends[0]['id'])) {
        $selected_id = $friends[0]['id'];
        $selected_friend = $friends[0];
        $selected_friend_id = $selected_id;
    } elseif ($chat_type === 'group' && !empty($groups) && isset($groups[0]['id'])) {
        $selected_id = $groups[0]['id'];
        $selected_group = $group->getGroupInfo($selected_id);
    }
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
<html lang="zh-CN" data-theme="<?php echo htmlspecialchars($current_user['theme'] ?? 'light'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>主页 - Modern Chat</title>
    <link rel="icon" href="aconvert.ico" type="image/x-icon">
    <style>
        :root {
            /* 浅色模式变量 */
            --bg-color: #ffffff;
            --text-color: #333333;
            --text-secondary: #666666;
            --text-desc: #999999;
            --border-color: rgba(234, 234, 234, 0.5);
            --panel-bg: rgba(248, 249, 250, 0.85);
            --sidebar-bg: rgba(247, 247, 247, 0.75);
            --message-sent-bg: #95ec69;
            --message-received-bg: rgba(255, 255, 255, 0.9);
            --hover-bg: rgba(245, 245, 245, 0.8);
            --modal-bg: rgba(255, 255, 255, 0.95);
            --input-bg: rgba(255, 255, 255, 0.8);
            --shadow-color: rgba(0,0,0,0.1);
            --primary-color: #667eea;
            --danger-color: #ff4d4f;
            --header-bg: rgba(255, 255, 255, 0.7);
            --chat-area-bg: rgba(229, 229, 229, 0.4);
        }

        [data-theme="dark"] {
            /* 深色模式变量 */
            --bg-color: #1a1b1e;
            --text-color: #e1e1e6;
            --text-secondary: #a1a1aa;
            --text-desc: #71717a;
            --border-color: rgba(44, 46, 51, 0.5);
            --panel-bg: rgba(37, 38, 43, 0.85);
            --sidebar-bg: rgba(32, 33, 36, 0.75);
            --message-sent-bg: rgba(55, 65, 81, 0.9); /* 深灰色气泡 */
            --message-received-bg: rgba(44, 46, 51, 0.9);
            --hover-bg: rgba(44, 46, 51, 0.8);
            --modal-bg: rgba(37, 38, 43, 0.95);
            --input-bg: rgba(44, 46, 51, 0.8);
            --shadow-color: rgba(0,0,0,0.5);
            --primary-color: #5c7cfa; /* 稍微亮一点的蓝色 */
            --danger-color: #ff6b6b;
            --header-bg: rgba(32, 33, 36, 0.7);
            --chat-area-bg: rgba(0, 0, 0, 0.6);
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
        
        /* 录音动画样式 */
        .recording-dots {
            animation: recordingPulse 1s infinite;
        }
        
        @keyframes recordingPulse {
            0% { opacity: 0.3; }
            50% { opacity: 1; }
            100% { opacity: 0.3; }
        }
        
        #record-btn.recording {
            color: #ff4757;
            animation: recordingBtnPulse 1s infinite;
        }
        
        @keyframes recordingBtnPulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); }
        }
        
        body {
            font-family: 'Microsoft YaHei', Tahoma, Geneva, Verdana, sans-serif;
            background: url('<?php echo $bg_url; ?>') no-repeat center center fixed;
            background-size: cover;
            height: 100vh;
            overflow: hidden;
            color: var(--text-color);
        }
        
        /* 主容器 */
        .chat-container {
            display: flex;
            height: 100vh;
            background: transparent;
        }
        
        /* 左侧边栏 - 微信风格 */
        .sidebar {
            width: 300px;
            background: var(--sidebar-bg);
            backdrop-filter: blur(10px);
            border-right: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
        }
        
        /* 左侧边栏顶部 - 用户信息 */
        .sidebar-header {
            height: 75px;
            background: var(--header-bg);
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            padding: 10px 15px;
        }
        
        .user-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4285f4 0%, #1a73e8 100%);
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
            /* 如果不是好友，隐藏名称 */
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
            /* 如果不是好友，隐藏IP/在线状态 */
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
            background: var(--header-bg);
            border-bottom: 1px solid var(--border-color);
        }
        
        .search-input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid var(--border-color);
            border-radius: 18px;
            font-size: 13px;
            background: var(--input-bg);
            color: var(--text-color);
            outline: none;
            transition: all 0.3s ease;
        }
        
        .search-input:focus {
            border-color: #12b7f5;
            box-shadow: 0 0 0 2px rgba(18, 183, 245, 0.1);
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
            padding: 12px 15px;
            padding-right: 60px; /* 为右侧菜单按钮留出足够空间 */
            cursor: pointer;
            border-bottom: 1px solid var(--border-color);
            transition: background-color 0.2s ease;
            position: relative;
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
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            z-index: 9999;
            min-width: 120px;
            margin-top: 5px;
            overflow: visible;
        }
        
        /* 确保聊天列表不会裁剪菜单 */
        .chat-list {
            overflow-y: auto;
            overflow-x: hidden;
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
            color: #333;
            transition: background-color 0.2s;
            border-radius: 8px;
        }
        
        /* 好友菜单项悬停效果 */
        .friend-menu-item:hover {
            background-color: #f5f5f5;
        }
        
        .chat-item:hover {
            background-color: var(--hover-bg);
        }
        
        .chat-item.active {
            background-color: var(--hover-bg);
        }
        
        .chat-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #1976d2; /* 普遍的蓝色 */
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 14px;
            margin-right: 12px;
        }
        
        .chat-avatar.group {
            background: linear-gradient(135deg, #34a853 0%, #1688f0 100%);
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
            color: #999;
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
            color: #12b7f5;
        }
        
        /* 聊天区域 */
        .chat-area {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: var(--chat-area-bg);
            backdrop-filter: blur(5px);
        }
        
        /* 聊天区域顶部 - 对方信息 */
        .chat-header {
            height: 60px;
            background: var(--header-bg);
            border-bottom: 1px solid var(--border-color);
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
            overflow-x: hidden; /* 防止水平滚动条 */
            padding: 20px;
            background: transparent;
            min-height: 0;
            box-sizing: border-box; /* 确保padding不撑大容器 */
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
            background: #1976d2; /* 普遍的蓝色 */
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
            border-top: 1px solid var(--border-color);
            padding: 15px;
        }
        
        .input-container {
            display: flex;
            align-items: flex-end;
            gap: 10px;
            background: var(--input-bg);
            padding: 10px 15px;
            border-radius: 20px;
            box-shadow: 0 1px 3px var(--shadow-color);
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
            color: var(--text-secondary);
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            transition: all 0.2s ease;
        }
        
        .btn-icon:hover {
            background: var(--hover-bg);
            color: var(--primary-color);
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
        
        /* 搜索结果项样式 */
        .search-item {
            display: flex;
            align-items: center;
            padding: 12px;
            border-bottom: 1px solid var(--border-color);
            background: transparent;
            transition: background-color 0.2s;
        }

        .search-item:hover {
            background-color: var(--hover-bg);
        }

        .search-item-info {
            flex: 1;
            min-width: 0; /* 防止文本溢出 */
        }

        .search-item-name {
            font-weight: 600;
            margin-bottom: 2px;
            color: var(--text-color);
        }

        .search-item-email {
            font-size: 12px;
            color: var(--text-secondary);
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
            border-radius: 8px;
            padding: 20px;
            max-width: 500px;
            width: 90%;
            color: var(--text-color);
            border: 1px solid var(--border-color);
        }
        
        /* 聊天类型切换 */
        .chat-type-tabs {
            display: flex;
            background: var(--sidebar-bg);
            border-bottom: 1px solid var(--border-color);
        }
        
        .chat-type-tab {
            flex: 1;
            padding: 12px;
            border: none;
            background: transparent;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            color: var(--text-secondary);
            transition: all 0.2s ease;
        }
        
        .chat-type-tab.active {
            color: var(--primary-color);
            background: var(--bg-color);
            border-bottom: 2px solid var(--primary-color);
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
            border-radius: 12px;
            padding: 8px 12px;
            max-width: 100%;
            width: 260px;
            position: relative;
            z-index: 1000;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            transition: all 0.2s ease;
            height: auto;
            min-height: 40px;
            overflow: visible;
            border: 1px solid var(--border-color);
            color: var(--text-color);
        }
        
        /* 音频播放器头像 */
        .audio-sender-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            margin-right: 12px;
            object-fit: cover;
            border: 2px solid #12b7f5;
        }
        
        .message.sent .custom-audio-player {
            background: rgba(18, 183, 245, 0.1);
            border-color: rgba(18, 183, 245, 0.3);
        }
        
        .message.received .custom-audio-player {
            background: var(--panel-bg);
            border: 1px solid var(--border-color);
        }
        
        .audio-element {
            display: none;
        }
        
        .audio-play-btn {
            width: 32px;
            height: 32px;
            border: none;
            background: linear-gradient(135deg, #12b7f5 0%, #00a2e8 100%);
            color: white;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 10px;
            transition: all 0.2s ease;
            z-index: 2001;
            position: relative;
            box-shadow: 0 2px 6px rgba(18, 183, 245, 0.4);
            flex-shrink: 0;
        }
        
        .audio-play-btn:hover {
            background: linear-gradient(135deg, #00a2e8 0%, #008cba 100%);
            transform: scale(1.1);
            box-shadow: 0 4px 12px rgba(18, 183, 245, 0.5);
        }
        
        .audio-play-btn:active {
            transform: scale(0.95);
        }
        
        .audio-play-btn.playing {
            background: linear-gradient(135deg, #ff4d4f 0%, #ff3333 100%);
            box-shadow: 0 2px 8px rgba(255, 77, 79, 0.4);
        }
        
        .audio-play-btn.playing:hover {
            background: linear-gradient(135deg, #ff3333 0%, #e60000 100%);
            box-shadow: 0 4px 12px rgba(255, 77, 79, 0.5);
        }
        
        .audio-play-btn::before {
            content: '▶';
            font-size: 14px;
            margin-left: 3px;
            font-weight: bold;
        }
        
        .audio-play-btn.playing::before {
            content: '⏸';
            margin-left: 0;
            font-size: 14px;
        }
        
        .audio-progress-container {
            flex: 1;
            margin: 0 10px;
            position: relative;
            z-index: 2001;
            display: flex;
            align-items: center;
        }
        
        .audio-progress-bar {
            width: 100%;
            height: 6px;
            background: #e9ecef;
            border-radius: 3px;
            cursor: pointer;
            overflow: visible;
            position: relative;
            z-index: 2002;
            pointer-events: all;
            transition: all 0.2s ease;
            border: none;
        }
        
        .audio-progress-bar:hover {
            height: 8px;
        }
        
        .audio-progress {
            height: 100%;
            background: linear-gradient(90deg, #12b7f5 0%, #00a2e8 100%);
            border-radius: 3px;
            transition: width 0.1s ease;
            position: relative;
            z-index: 2003;
        }
        
        .audio-progress::after {
            content: '';
            position: absolute;
            right: -6px;
            top: 50%;
            transform: translateY(-50%);
            width: 12px;
            height: 12px;
            background: white;
            border-radius: 50%;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
            z-index: 2004;
            transition: all 0.2s ease;
            cursor: pointer;
            border: 2px solid #12b7f5;
        }
        
        .audio-progress-bar:hover .audio-progress::after {
            transform: translateY(-50%) scale(1.2);
            width: 14px;
            height: 14px;
            right: -7px;
        }
        
        .audio-time {
            font-size: 12px;
            color: #666;
            min-width: auto;
            text-align: right;
            font-weight: 500;
            margin-left: 8px;
            white-space: nowrap;
        }
        
        .message.sent .audio-time {
            color: #555;
        }
        
        /* 确保所有消息操作菜单都在最上层 */
        .message-actions-menu,
        .member-actions-menu,
        .file-actions-menu,
        .friend-menu {
            z-index: 3000 !important;
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
        
        /* 文件已清理提示 */
        .file-cleaned-tip {
            background: #f5f5f5;
            color: #999;
            padding: 15px 20px;
            border-radius: 8px;
            font-size: 13px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px dashed #ccc;
            min-width: 120px;
            text-align: center;
            user-select: none;
        }
        .file-cleaned-tip::before {
            content: '⚠️';
            margin-right: 6px;
            font-size: 14px;
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
            background: #1976d2; /* 普遍的蓝色 */
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
            object-fit: contain;
            background: #000;
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
            background: linear-gradient(90deg, #4285f4 0%, #1a73e8 100%);
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

        /* 全屏模式下的容器样式 */
        :fullscreen .video-player-content {
            width: 100% !important;
            height: 100% !important;
            display: flex;
            flex-direction: column;
        }

        :fullscreen .video-player-body {
            flex: 1;
            width: 100%;
            height: 100%;
            margin: 0;
            padding: 0;
            overflow: hidden;
        }

        :fullscreen .custom-video-player {
            width: 100% !important;
            height: 100% !important;
            display: flex;
            flex-direction: column;
            position: relative;
        }

        /* 全屏模式下的视频元素样式 */
        :fullscreen .custom-video-element {
            flex: 1;
            width: 100% !important;
            height: 100% !important;
            object-fit: contain;
            background: #000;
        }

        /* 全屏模式下的控件样式 */
        :fullscreen .video-controls {
            background: rgba(0, 0, 0, 0.8);
            color: white;
            padding: 15px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            position: relative;
            z-index: 1000;
            opacity: 1 !important;
            transform: translateY(0) !important;
            pointer-events: auto !important;
            margin: 0;
            width: auto !important;
        }

        /* 确保全屏模式下控件始终可见，不被隐藏 */
        :fullscreen .video-controls.hidden {
            opacity: 1 !important;
            transform: translateY(0) !important;
            pointer-events: auto !important;
        }

        /* 增强全屏模式下控件的可见性 */
        :fullscreen .video-control-btn {
            font-size: 24px;
            width: 48px;
            height: 48px;
        }

        /* 修复全屏模式下进度条和时间显示 */
        :fullscreen .video-progress-container {
            display: flex;
            align-items: center;
            gap: 10px;
            width: 100%;
        }

        :fullscreen .video-progress-bar {
            flex: 1;
        }

        /* 修复全屏模式下音量控制 */
        :fullscreen .video-volume-control {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* 修复全屏模式下音量滑块 */
        :fullscreen .volume-slider {
            width: 100px;
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
            z-index: 2000;
        }
        
        .media-action-btn {
            z-index: 2000;
        }
        
        /* 确保文件操作菜单显示在最上层 */
        .file-actions-menu {
            z-index: 3000 !important;
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
            background: var(--modal-bg);
            border-radius: 12px;
            box-shadow: 0 8px 32px var(--shadow-color);
            z-index: 3000;
            display: flex;
            flex-direction: column;
            display: none;
            color: var(--text-color);
        }

        .download-panel.visible {
            display: flex;
        }

        .download-panel-header {
            padding: 15px 20px;
            background: var(--primary-color);
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-radius: 12px 12px 0 0;
            cursor: move; /* 添加移动光标 */
            user-select: none; /* 防止选中文字 */
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
            background: var(--input-bg);
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 10px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            color: var(--text-color);
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
            background: linear-gradient(90deg, #4285f4 0%, #1a73e8 100%);
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
            z-index: 3000 !important;
            min-width: 120px;
            overflow: visible;
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
        /* Falling Fu Animation */
        @keyframes fall {
            0% { transform: translateY(-100px) rotate(0deg); opacity: 1; }
            100% { transform: translateY(100vh) rotate(360deg); opacity: 0; }
        }
        
        .falling-fu {
            position: fixed;
            top: -50px;
            font-size: 30px;
            z-index: 10002;
            pointer-events: none;
            user-select: none;
            font-family: "Microsoft YaHei", sans-serif;
            color: #ff4d4f;
            text-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
    </style>
</head>
<body>
    <!-- 页面顶部缓存进度条（支持音频和视频） -->
    <div id="top-cache-status" style="
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
            <span id="top-cache-type-text">正在缓存</span>
        </div>
        <div style="margin-bottom: 8px;">
            <span id="top-cache-file-name"></span>
        </div>
        <div id="top-cache-percentage" style="font-size: 24px; font-weight: bold; margin-bottom: 8px;">0%</div>
        <div style="width: 100%; height: 6px; background: #333; border-radius: 3px; overflow: hidden; margin-bottom: 8px;">
            <div id="top-cache-progress-bar" style="height: 100%; background: linear-gradient(90deg, #12b7f5 0%, #00a2e8 100%); border-radius: 3px; width: 0%; transition: width 0.3s ease;"></div>
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
        <div class="modal-content" style="width: 400px; background: var(--modal-bg); color: var(--text-color);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 1px solid var(--border-color);">
                <h2 style="color: var(--text-color); font-size: 18px; font-weight: 600;">设置</h2>
                <button onclick="closeSettingsModal()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: var(--text-secondary);">×</button>
            </div>
            <div class="settings-content">
                <!-- 设置项：使用弹窗显示链接 -->
                <div class="setting-item" style="display: flex; justify-content: space-between; align-items: center; padding: 15px 0; border-bottom: 1px solid var(--border-color);">
                    <div>
                        <div style="font-size: 14px; font-weight: 600; color: var(--text-color);">使用弹窗显示链接</div>
                        <div style="font-size: 12px; color: var(--text-desc); margin-top: 2px;">点击链接时使用弹窗显示</div>
                    </div>
                    <label class="switch">
                        <input type="checkbox" id="setting-link-popup" checked>
                        <span class="slider"></span>
                    </label>
                </div>
                
                <!-- 设置项：音乐播放器 -->
                <div class="setting-item" style="display: flex; justify-content: space-between; align-items: center; padding: 15px 0; border-bottom: 1px solid var(--border-color);">
                    <div>
                        <div style="font-size: 14px; font-weight: 600; color: var(--text-color);">音乐播放器</div>
                        <div style="font-size: 12px; color: var(--text-desc); margin-top: 2px;">在聊天中播放音乐</div>
                    </div>
                    <label class="switch">
                        <input type="checkbox" id="setting-music-player">
                        <span class="slider"></span>
                    </label>
                </div>
                
                <!-- 设置项：音乐模式 -->
                <div class="setting-item" style="display: flex; justify-content: space-between; align-items: center; padding: 15px 0; border-bottom: 1px solid var(--border-color);">
                    <div>
                        <div style="font-size: 14px; font-weight: 600; color: var(--text-color);">音乐模式</div>
                        <div style="font-size: 12px; color: var(--text-desc); margin-top: 2px;">选择播放的音乐类型</div>
                    </div>
                    <select id="setting-music-mode" style="
                        padding: 8px 12px;
                        border: 1px solid var(--border-color);
                        border-radius: 6px;
                        background: var(--input-bg);
                        color: var(--text-color);
                        font-size: 13px;
                        cursor: <?php echo $is_music_locked ? 'not-allowed' : 'pointer'; ?>;
                        <?php if ($is_music_locked) echo 'opacity: 0.9; pointer-events: none;'; ?>
                    ">
                        <?php if ($is_music_locked): ?>
                        <option value="spring_festival" selected>春节歌单</option>
                        <?php else: ?>
                        <option value="spring_festival">春节歌单</option>
                        <option value="random">随机音乐</option>
                        <option value="custom">更多自定义歌曲</option>
                        <?php endif; ?>
                    </select>
                </div>
                
                <!-- 设置项：字体设置 -->
                <div class="setting-item" style="display: flex; justify-content: space-between; align-items: center; padding: 15px 0; border-bottom: 1px solid var(--border-color);">
                    <div>
                        <div style="font-size: 14px; font-weight: 600; color: var(--text-color);">字体设置</div>
                        <div style="font-size: 12px; color: var(--text-desc); margin-top: 2px;">设置聊天界面使用的字体</div>
                    </div>
                    <button onclick="openFontSettingsModal()" style="
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
                
                <!-- 设置项：更多设置 -->
                <div class="setting-item" style="display: flex; justify-content: space-between; align-items: center; padding: 15px 0; border-bottom: 1px solid var(--border-color);">
                    <div>
                        <div style="font-size: 14px; font-weight: 600; color: var(--text-color);">更多设置</div>
                        <div style="font-size: 12px; color: var(--text-desc); margin-top: 2px;">修改个人信息</div>
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
                        <div style="font-size: 12px; color: var(--text-desc); margin-top: 2px;">查看和管理已缓存的文件</div>
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
                        <div style="font-size: 12px; color: var(--text-desc); margin-top: 2px;">清除所有本地存储的文件数据，此操作不可恢复</div>
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
                        <div style="font-size: 12px; color: var(--text-desc); margin-top: 2px;">设置密保问题和答案，用于账号安全</div>
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
                
                <!-- 设置项：退出登录 -->
                <div class="setting-item" style="display: flex; justify-content: space-between; align-items: center; padding: 15px 0; border-top: 1px solid var(--border-color); margin-top: 10px;">
                    <div>
                        <div style="font-size: 14px; font-weight: 600; color: var(--text-color);">退出登录</div>
                        <div style="font-size: 12px; color: var(--text-desc); margin-top: 2px;">退出当前账号，返回登录页面</div>
                    </div>
                    <button onclick="logout()" style="
                        padding: 8px 16px;
                        background: var(--danger-color);
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
    
    <!-- 字体设置弹窗 -->
    <div id="font-settings-modal" class="modal" style="display: none;">
        <div class="modal-content" style="width: 500px; background: var(--modal-bg); color: var(--text-color);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 1px solid var(--border-color);">
                <h2 style="color: var(--text-color); font-size: 18px; font-weight: 600;">字体设置</h2>
                <button onclick="closeFontSettingsModal()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: var(--text-secondary);">×</button>
            </div>
            <div class="settings-content" style="padding: 0 20px 20px;">
                <div style="margin-bottom: 20px;">
                    <div style="font-size: 14px; color: var(--text-secondary); margin-bottom: 15px;">设置聊天界面使用的字体</div>
                    
                    <!-- 字体选择 -->
                    <div style="margin-bottom: 15px;">
                        <label style="display: block; font-size: 14px; font-weight: 600; color: var(--text-color); margin-bottom: 8px;">选择字体</label>
                        
                        <!-- 隐藏的原生Select，用于保持原有逻辑兼容 -->
                        <select id="font-select" style="display: none;">
                            <option value="default">默认字体</option>
                            <optgroup label="常用中文字体">
                                <option value="Microsoft YaHei">微软雅黑</option>
                                <option value="SimHei">黑体</option>
                                <option value="SimSun">宋体</option>
                                <option value="KaiTi">楷体</option>
                                <option value="FangSong">仿宋</option>
                                <option value="Microsoft JhengHei">微软正黑体</option>
                                <option value="PingFang SC">苹方</option>
                                <option value="Hiragino Sans GB">冬青黑体</option>
                                <option value="Heiti SC">黑体-简</option>
                                <option value="Songti SC">宋体-简</option>
                                <option value="Kaiti SC">楷体-简</option>
                            </optgroup>
                            <optgroup label="常用英文字体">
                                <option value="Arial">Arial</option>
                                <option value="Tahoma">Tahoma</option>
                                <option value="Verdana">Verdana</option>
                                <option value="Times New Roman">Times New Roman</option>
                                <option value="Courier New">Courier New</option>
                                <option value="Georgia">Georgia</option>
                                <option value="Impact">Impact</option>
                                <option value="Helvetica Neue">Helvetica Neue</option>
                                <option value="Helvetica">Helvetica</option>
                            </optgroup>
                            <optgroup label="开源/Web字体">
                                <option value="noto-sans-sc">Noto Sans SC</option>
                                <option value="noto-serif-sc">Noto Serif SC</option>
                            </optgroup>
                            <option value="custom">自定义字体...</option>
                        </select>

                        <!-- 新的现代化字体选择UI -->
                        <div id="modern-font-selector" style="max-height: 400px; overflow-y: auto; padding-right: 5px;">
                            <style>
                                .font-category-title { font-size: 12px; color: var(--text-desc); margin: 15px 0 8px; font-weight: 600; letter-spacing: 0.5px; }
                                .font-category-title:first-child { margin-top: 0; }
                                .font-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; }
                                .font-card { 
                                    border: 1px solid var(--border-color); border-radius: 8px; padding: 12px; 
                                    text-align: center; cursor: pointer; transition: all 0.2s ease; 
                                    background: var(--panel-bg); position: relative; display: flex; flex-direction: column; align-items: center; justify-content: center;
                                    height: 70px;
                                }
                                .font-card:hover { border-color: var(--primary-color); background: var(--hover-bg); box-shadow: 0 4px 12px rgba(102, 126, 234, 0.1); transform: translateY(-2px); }
                                .font-card.active { border-color: var(--primary-color); background: rgba(102, 126, 234, 0.1); color: var(--primary-color); }
                                .font-card.active::after {
                                    content: '✓'; position: absolute; top: 5px; right: 5px; 
                                    font-size: 12px; color: var(--primary-color); font-weight: bold;
                                }
                                .font-preview-text { font-size: 18px; line-height: 1.2; margin-bottom: 4px; color: var(--text-color); }
                                .font-name-text { font-size: 11px; color: var(--text-desc); }
                                .font-card.active .font-preview-text { color: var(--primary-color); }
                                .font-card.active .font-name-text { color: var(--primary-color); opacity: 0.8; }
                            </style>

                            <div class="font-category-title">基础选项</div>
                            <div class="font-grid">
                                <div class="font-card" onclick="selectFontUI('default')" data-value="default">
                                    <span class="font-preview-text">默认</span>
                                    <span class="font-name-text">Default</span>
                                </div>
                                <div class="font-card" onclick="selectFontUI('custom')" data-value="custom">
                                    <span class="font-preview-text" style="font-family: sans-serif;">自定义</span>
                                    <span class="font-name-text">Custom Font</span>
                                </div>
                            </div>

                            <div class="font-category-title">常用中文字体</div>
                            <div class="font-grid">
                                <div class="font-card" onclick="selectFontUI('Microsoft YaHei')" data-value="Microsoft YaHei">
                                    <span class="font-preview-text" style="font-family: 'Microsoft YaHei'">微软雅黑</span>
                                    <span class="font-name-text">Microsoft YaHei</span>
                                </div>
                                <div class="font-card" onclick="selectFontUI('SimHei')" data-value="SimHei">
                                    <span class="font-preview-text" style="font-family: 'SimHei'">黑体</span>
                                    <span class="font-name-text">SimHei</span>
                                </div>
                                <div class="font-card" onclick="selectFontUI('SimSun')" data-value="SimSun">
                                    <span class="font-preview-text" style="font-family: 'SimSun'">宋体</span>
                                    <span class="font-name-text">SimSun</span>
                                </div>
                                <div class="font-card" onclick="selectFontUI('KaiTi')" data-value="KaiTi">
                                    <span class="font-preview-text" style="font-family: 'KaiTi'">楷体</span>
                                    <span class="font-name-text">KaiTi</span>
                                </div>
                                <div class="font-card" onclick="selectFontUI('FangSong')" data-value="FangSong">
                                    <span class="font-preview-text" style="font-family: 'FangSong'">仿宋</span>
                                    <span class="font-name-text">FangSong</span>
                                </div>
                                <div class="font-card" onclick="selectFontUI('PingFang SC')" data-value="PingFang SC">
                                    <span class="font-preview-text" style="font-family: 'PingFang SC'">苹方</span>
                                    <span class="font-name-text">PingFang SC</span>
                                </div>
                            </div>

                            <div class="font-category-title">常用英文字体</div>
                            <div class="font-grid">
                                <div class="font-card" onclick="selectFontUI('Arial')" data-value="Arial">
                                    <span class="font-preview-text" style="font-family: 'Arial'">Arial</span>
                                    <span class="font-name-text">Sans-serif</span>
                                </div>
                                <div class="font-card" onclick="selectFontUI('Times New Roman')" data-value="Times New Roman">
                                    <span class="font-preview-text" style="font-family: 'Times New Roman'">Times</span>
                                    <span class="font-name-text">Serif</span>
                                </div>
                                <div class="font-card" onclick="selectFontUI('Courier New')" data-value="Courier New">
                                    <span class="font-preview-text" style="font-family: 'Courier New'">Courier</span>
                                    <span class="font-name-text">Monospace</span>
                                </div>
                                <div class="font-card" onclick="selectFontUI('Georgia')" data-value="Georgia">
                                    <span class="font-preview-text" style="font-family: 'Georgia'">Georgia</span>
                                    <span class="font-name-text">Serif</span>
                                </div>
                            </div>
                            
                            <div class="font-category-title">Web字体</div>
                            <div class="font-grid">
                                <div class="font-card" onclick="selectFontUI('noto-sans-sc')" data-value="noto-sans-sc">
                                    <span class="font-preview-text" style="font-family: 'Noto Sans SC', sans-serif;">思源黑体</span>
                                    <span class="font-name-text">Noto Sans</span>
                                </div>
                                <div class="font-card" onclick="selectFontUI('noto-serif-sc')" data-value="noto-serif-sc">
                                    <span class="font-preview-text" style="font-family: 'Noto Serif SC', serif;">思源宋体</span>
                                    <span class="font-name-text">Noto Serif</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 自定义字体导入 -->
                    <div id="custom-font-section" style="margin-bottom: 15px; display: none;">
                        <label style="display: block; font-size: 14px; font-weight: 600; color: var(--text-color); margin-bottom: 8px;">导入自定义字体</label>
                        <input type="file" id="custom-font-file" accept=".ttf,.otf,.woff,.woff2" style="display: none;">
                        <button onclick="document.getElementById('custom-font-file').click()" style="
                            padding: 8px 16px;
                            background: var(--primary-color);
                            color: white;
                            border: none;
                            border-radius: 6px;
                            cursor: pointer;
                            font-size: 13px;
                            transition: background-color 0.2s;
                        ">选择字体文件</button>
                        <div id="custom-font-name" style="margin-top: 10px; font-size: 13px; color: var(--text-secondary);"></div>
                    </div>
                    
                    <!-- 字体样式设置 -->
                    <div style="margin-bottom: 15px;">
                        <label style="display: block; font-size: 14px; font-weight: 600; color: var(--text-color); margin-bottom: 8px;">字体样式</label>
                        <div style="display: flex; gap: 15px;">
                            <div style="display: flex; align-items: center;">
                                <input type="checkbox" id="font-bold" style="margin-right: 6px;">
                                <label for="font-bold" style="font-size: 13px; color: var(--text-color); cursor: pointer;">加粗</label>
                            </div>
                            <div style="display: flex; align-items: center;">
                                <input type="checkbox" id="font-italic" style="margin-right: 6px;">
                                <label for="font-italic" style="font-size: 13px; color: var(--text-color); cursor: pointer;">斜体</label>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 字体预览 -->
                    <div style="
                        padding: 15px;
                        background: var(--panel-bg);
                        border: 1px solid var(--border-color);
                        border-radius: 6px;
                        margin-bottom: 15px;
                        font-size: 16px;
                        color: var(--text-color);
                    " id="font-preview">
                        字体预览：这是一段测试文字，用于预览所选字体的效果。
                    </div>
                    
                    <!-- 操作按钮 -->
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <button onclick="applyFont()" style="
                            padding: 8px 16px;
                            background: #4CAF50;
                            color: white;
                            border: none;
                            border-radius: 6px;
                            cursor: pointer;
                            font-size: 13px;
                            transition: background-color 0.2s;
                        ">应用字体</button>
                        
                        <button onclick="resetFont()" style="
                            padding: 8px 16px;
                            background: #ff4d4f;
                            color: white;
                            border: none;
                            border-radius: 6px;
                            cursor: pointer;
                            font-size: 13px;
                            transition: background-color 0.2s;
                        ">重置字体</button>
                    </div>
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
    
    <!-- 手机号绑定弹窗 -->
    <div id="phone-bind-modal" class="modal" style="display: none; z-index: 10000;">
        <div class="modal-content" style="width: 400px; background: var(--modal-bg); color: var(--text-color);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 1px solid var(--border-color);">
                <h2 style="color: var(--text-color); font-size: 18px; font-weight: 600;" id="phone-bind-title">绑定手机号</h2>
                <button onclick="closePhoneBindModal()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: var(--text-secondary);">×</button>
            </div>
            <div style="padding: 0 20px 20px;">
                <div class="form-group" style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px; font-size: 14px; color: var(--text-secondary);">手机号</label>
                    <input type="tel" id="bind-phone-input" placeholder="请输入手机号" style="
                        width: 100%;
                        padding: 10px;
                        border: 1px solid var(--border-color);
                        border-radius: 4px;
                        background: var(--input-bg);
                        color: var(--text-color);
                        font-size: 14px;
                    ">
                </div>
                
                <!-- 极验验证码容器 -->
                <div class="form-group" style="margin-bottom: 15px;">
                    <div id="bind-phone-captcha"></div>
                </div>
                
                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 5px; font-size: 14px; color: var(--text-secondary);">验证码</label>
                    <div style="display: flex; gap: 10px;">
                        <input type="text" id="bind-sms-code" placeholder="6位验证码" maxlength="6" style="
                            flex: 1;
                            padding: 10px;
                            border: 1px solid var(--border-color);
                            border-radius: 4px;
                            background: var(--input-bg);
                            color: var(--text-color);
                            font-size: 14px;
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
                    background: var(--primary-color);
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

    <!-- 更多设置弹窗 -->
    <div id="more-settings-modal" class="modal" style="display: none;">
        <div class="modal-content" style="width: 800px; height: 80vh; max-height: 800px; display: flex; flex-direction: column; background: var(--modal-bg); color: var(--text-color);">
            <div style="display: flex; justify-content: space-between; align-items: center; padding: 20px; border-bottom: 1px solid var(--border-color);">
                <h2 style="color: var(--text-color); font-size: 20px; font-weight: 600;">更多设置</h2>
                <button onclick="closeMoreSettingsModal()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: var(--text-secondary);">×</button>
            </div>
            <div class="more-settings-content" style="flex: 1; overflow-y: auto; padding: 20px;">
                <!-- Service Worker 缓存清理 -->
                <div style="padding: 20px; background: var(--panel-bg); border-radius: 8px; margin-bottom: 20px; transition: background-color 0.3s;">
                    <h3 style="color: var(--text-color); font-size: 16px; font-weight: 600; margin-bottom: 15px;">系统设置</h3>
                    <div style="display: flex; align-items: center; justify-content: space-between;">
                        <div>
                            <div style="font-size: 14px; font-weight: 500; color: var(--text-color);">清除文件缓存</div>
                            <div style="font-size: 12px; color: var(--text-secondary);">清除所有本地存储的文件数据，此操作不可恢复</div>
                        </div>
                        <button onclick="clearServiceWorkerCache()" style="
                            padding: 8px 16px;
                            background: #ff4757;
                            color: white;
                            border: none;
                            border-radius: 6px;
                            cursor: pointer;
                            font-size: 13px;
                            font-weight: 500;
                            transition: background-color 0.2s;
                        ">清除</button>
                    </div>
                </div>

                <!-- 用户信息部分 -->
                <div style="display: flex; align-items: flex-start; padding: 20px; background: var(--panel-bg); border-radius: 8px; margin-bottom: 20px;">
                    <!-- 左侧32*32头像 -->
                    <div style="margin-right: 15px; text-align: center;">
                        <?php if (isset($current_user['avatar']) && $current_user['avatar'] && $current_user['avatar'] !== 'deleted_user'): ?>
                            <img src="<?php echo $current_user['avatar']; ?>" alt="用户头像" style="width: 32px; height: 32px; border-radius: 50%; object-fit: cover;">
                        <?php else: ?>
                            <div style="width: 32px; height: 32px; border-radius: 50%; background: linear-gradient(135deg, #4285f4 0%, #1a73e8 100%); display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; font-size: 14px;">
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
                            transition: background-color 0.2s;
                        ">修改头像</button>
                    </div>
                    
                    <!-- 右侧用户信息 -->
                    <div style="flex: 1;">
                        <div style="display: flex; align-items: center; margin-bottom: 4px;">
                            <div style="font-size: 16px; font-weight: 600; color: var(--text-color); margin-right: 10px;"><?php echo htmlspecialchars($username); ?></div>
                            <button onclick="showChangeNameModal()" style="
                                padding: 6px 12px;
                                background: var(--primary-color);
                                color: white;
                                border: none;
                                border-radius: 6px;
                                cursor: pointer;
                                font-size: 12px;
                                transition: background-color 0.2s;
                            ">修改名称</button>
                        </div>
                        <div style="display: flex; align-items: center; margin-bottom: 15px;">
                            <div style="font-size: 14px; color: var(--text-secondary); margin-right: 10px;"><?php echo htmlspecialchars($current_user['email']); ?></div>
                            <button onclick="showChangeEmailModal()" style="
                                padding: 6px 12px;
                                background: var(--primary-color);
                                color: white;
                                border: none;
                                border-radius: 6px;
                                cursor: pointer;
                                font-size: 12px;
                                transition: background-color 0.2s;
                            ">修改邮箱</button>
                        </div>
                        <!-- Service Worker 缓存清理 -->
                        <div style="margin-top: 10px;">
                            <div style="font-size: 14px; font-weight: 600; color: var(--text-color); margin-bottom: 5px;">Service Worker 缓存</div>
                            <div style="font-size: 12px; color: var(--text-secondary); margin-bottom: 8px;">清除所有本地缓存文件，解决页面加载问题</div>
                            <button onclick="clearServiceWorkerCache()" style="
                                padding: 6px 12px;
                                background: #ff4757;
                                color: white;
                                border: none;
                                border-radius: 6px;
                                cursor: pointer;
                                font-size: 12px;
                                transition: background-color 0.2s;
                            ">清除缓存并注销</button>
                        </div>
                    </div>
                </div>
                
                <!-- 密码修改部分 -->
                <div style="padding: 20px; background: var(--panel-bg); border-radius: 8px; margin-bottom: 20px;">
                    <div style="display: flex; align-items: center; justify-content: space-between;">
                        <div style="font-size: 14px; color: var(--text-secondary);">密码相关</div>
                        <button onclick="showChangePasswordModal()" style="
                            padding: 8px 16px;
                            background: var(--danger-color);
                            color: white;
                            border: none;
                            border-radius: 6px;
                            cursor: pointer;
                            font-size: 13px;
                            transition: background-color 0.2s;
                        ">修改密码</button>
                    </div>
                </div>
                
                <!-- 背景图片设置 -->
                <div style="padding: 20px; background: var(--panel-bg); border-radius: 8px;">
                    <div style="margin-bottom: 15px;">
                        <h3 style="color: var(--text-color); font-size: 16px; font-weight: 600; margin-bottom: 10px;">背景设置</h3>
                        <div style="font-size: 14px; color: var(--text-secondary); margin-bottom: 15px;">设置聊天界面背景图片</div>
                        
                        <!-- 背景预览 -->
                        <div id="background-preview" style="
                            width: 100%;
                            height: 150px;
                            background-color: var(--hover-bg);
                            border-radius: 8px;
                            margin-bottom: 15px;
                            background-size: cover;
                            background-position: center;
                            border: 2px dashed var(--border-color);
                            display: flex;
                            align-items: center;
                            justify-content: center;
                            color: var(--text-secondary);
                            font-size: 14px;
                        ">
                            <span id="background-preview-text">点击选择背景图片</span>
                        </div>
                        
                        <!-- 每日必应壁纸开关 -->
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 15px; padding: 10px; background: var(--input-bg); border-radius: 6px; border: 1px solid var(--border-color);">
                            <div>
                                <div style="font-size: 14px; font-weight: 500; color: var(--text-color);">每日必应壁纸</div>
                                <div style="font-size: 12px; color: var(--text-desc);">仅在未设置自定义背景时生效</div>
                            </div>
                            <label class="switch" style="position: relative; display: inline-block; width: 52px; height: 32px;">
                                <input type="checkbox" id="bing-wallpaper-toggle" onchange="toggleBingWallpaper(this.checked)" style="opacity: 0; width: 0; height: 0; position: absolute; z-index: -1;">
                                <span class="slider"></span>
                            </label>
                        </div>

                        <!-- 图片选择按钮 -->
                        <div style="display: flex; gap: 10px; align-items: center; position: relative; z-index: 1;">
                            <input type="file" id="background-file" accept="image/*" style="display: none;">
                            <button onclick="document.getElementById('background-file').click()" style="
                                padding: 8px 16px;
                                background: var(--primary-color);
                                color: white;
                                border: none;
                                border-radius: 6px;
                                cursor: pointer;
                                font-size: 13px;
                                transition: background-color 0.2s;
                            ">选择图片</button>
                            
                            <!-- 应用背景按钮 -->
                            <button onclick="applyBackground()" style="
                                padding: 8px 16px;
                                background: #4CAF50;
                                color: white;
                                border: none;
                                border-radius: 6px;
                                cursor: pointer;
                                font-size: 13px;
                                transition: background-color 0.2s;
                            ">应用背景</button>
                            
                            <!-- 移除背景按钮 -->
                            <button onclick="removeBackground()" style="
                                padding: 8px 16px;
                                background: var(--danger-color);
                                color: white;
                                border: none;
                                border-radius: 6px;
                                cursor: pointer;
                                font-size: 13px;
                                transition: background-color 0.2s;
                            ">移除背景</button>
                        </div>
                        
                        <!-- 图片要求说明 -->
                        <div style="margin-top: 10px; font-size: 12px; color: var(--text-desc);">
                            要求：图片尺寸≥1920×1080，大小≤100MB
                        </div>
                    </div>
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
                        ">进入管理页面</button>
                    </div>
                </div>
                <?php endif; ?>

                <!-- 系统设置 -->
                <div style="padding: 20px; background: var(--panel-bg); border-radius: 8px; margin-bottom: 20px; transition: background-color 0.3s;">
                    <h3 style="color: var(--text-color); font-size: 16px; font-weight: 600; margin-bottom: 15px;">系统设置</h3>
                    
                    <!-- Service Worker 缓存清理 -->
                    <div style="display: flex; align-items: center; justify-content: space-between;">
                        <div>
                            <div style="font-size: 14px; font-weight: 500; color: var(--text-color);">清除文件缓存</div>
                            <div style="font-size: 12px; color: var(--text-secondary);">清除所有本地存储的文件数据，此操作不可恢复</div>
                        </div>
                        <button onclick="clearServiceWorkerCache()" style="
                            padding: 8px 16px;
                            background: #ff4757;
                            color: white;
                            border: none;
                            border-radius: 6px;
                            cursor: pointer;
                            font-size: 13px;
                            font-weight: 500;
                            transition: background-color 0.2s;
                        ">清除</button>
                    </div>
                </div>

                <!-- 外观设置 -->
                <div style="padding: 20px; background: var(--panel-bg); border-radius: 8px; margin-bottom: 20px; transition: background-color 0.3s;">
                    <h3 style="color: var(--text-color); font-size: 16px; font-weight: 600; margin-bottom: 15px;">外观设置</h3>
                    
                    <!-- 深色模式开关 -->
                    <div style="display: flex; align-items: center; justify-content: space-between;">
                        <div>
                            <div style="font-size: 14px; font-weight: 500; color: var(--text-color);">深色模式</div>
                            <div style="font-size: 12px; color: var(--text-secondary);">切换界面颜色为深色风格</div>
                        </div>
                        <label class="switch" style="position: relative; display: inline-block; width: 52px; height: 32px;">
                            <input type="checkbox" id="dark-mode-toggle" onchange="toggleTheme(this.checked)" style="opacity: 0; width: 0; height: 0; position: absolute; z-index: -1;">
                            <span class="slider"></span>
                        </label>
                    </div>
                </div>

                <!-- 安全设置 -->
                <?php
                // 读取phone_sms配置
                $phone_sms_enabled = getConfig('phone_sms', false);
                // 确保是布尔值
                $phone_sms_enabled = filter_var($phone_sms_enabled, FILTER_VALIDATE_BOOLEAN);
                
                if ($phone_sms_enabled):
                ?>
                <div style="padding: 20px; background: var(--panel-bg); border-radius: 8px; margin-bottom: 20px; transition: background-color 0.3s;">
                    <h3 style="color: var(--text-color); font-size: 16px; font-weight: 600; margin-bottom: 15px;">安全设置</h3>
                    
                    <!-- 安全手机号 -->
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 15px;">
                        <div>
                            <div style="font-size: 14px; font-weight: 500; color: var(--text-color);">
                                <?php echo !empty($current_user['phone']) ? '修改绑定手机号' : '手机号绑定'; ?>
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
                            transition: background-color 0.2s;
                        ">
                            <?php echo !empty($current_user['phone']) ? '修改' : '绑定'; ?>
                        </button>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </div>
    </div>
    
    <!-- 修改头像弹窗 -->
    <div id="change-avatar-modal" class="modal" style="display: none;">
        <div class="modal-content" style="width: 400px; background: var(--modal-bg); color: var(--text-color);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 1px solid var(--border-color);">
                <h2 style="color: var(--text-color); font-size: 20px; font-weight: 600;">修改头像</h2>
                <button onclick="closeChangeAvatarModal()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: var(--text-secondary);">×</button>
            </div>
            <div class="change-avatar-content" style="padding: 0 20px 20px;">
                <div style="text-align: center; margin-bottom: 20px;">
                    <div style="font-size: 14px; color: var(--text-desc); margin-bottom: 15px;">支持JPG、PNG、GIF格式，系统会自动调整为32x32像素</div>
                    
                    <input type="file" id="avatar-file" name="avatar" accept="image/*" style="display: none;">
                    <button type="button" onclick="document.getElementById('avatar-file').click()" style="
                        padding: 10px 20px;
                        background: var(--primary-color);
                        color: white;
                        border: none;
                        border-radius: 6px;
                        cursor: pointer;
                        font-size: 14px;
                        transition: background-color 0.2s;
                    ">选择图片</button>
                    
                    <!-- 文件名显示 -->
                    <div id="selected-file-name" style="margin-top: 10px; font-size: 13px; color: var(--text-color);"></div>
                </div>
                
                <div style="display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" onclick="closeChangeAvatarModal()" style="
                        padding: 8px 16px;
                        background: var(--bg-color);
                        color: var(--text-color);
                        border: 1px solid var(--border-color);
                        border-radius: 6px;
                        cursor: pointer;
                        font-size: 14px;
                        transition: background-color 0.2s;
                    ">取消</button>
                    <button type="button" onclick="changeAvatar()" style="
                        padding: 8px 16px;
                        background: var(--primary-color);
                        color: white;
                        border: none;
                        border-radius: 6px;
                        cursor: pointer;
                        font-size: 14px;
                        transition: background-color 0.2s;
                    ">确定上传</button>
                </div>
            </div>
        </div>
    </div>

    <!-- 修改密码弹窗 -->
    <div id="change-password-modal" class="modal" style="display: none;">
        <div class="modal-content" style="width: 400px; background: var(--modal-bg); color: var(--text-color);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 1px solid var(--border-color);">
                <h2 style="color: var(--text-color); font-size: 20px; font-weight: 600;">修改密码</h2>
                <button onclick="closeChangePasswordModal()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: var(--text-secondary);">×</button>
            </div>
            <div class="change-password-content" style="padding: 0 20px 20px;">
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500; color: var(--text-color);">请输入原密码</label>
                    <input type="password" id="old-password" style="
                        width: 100%;
                        padding: 10px;
                        border: 1px solid var(--border-color);
                        border-radius: 6px;
                        font-size: 14px;
                        box-sizing: border-box;
                        background: var(--input-bg);
                        color: var(--text-color);
                    " placeholder="请输入原密码">
                </div>
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500; color: var(--text-color);">请输入新密码</label>
                    <input type="password" id="new-password" style="
                        width: 100%;
                        padding: 10px;
                        border: 1px solid var(--border-color);
                        border-radius: 6px;
                        font-size: 14px;
                        box-sizing: border-box;
                        background: var(--input-bg);
                        color: var(--text-color);
                    " placeholder="请输入新密码">
                </div>
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500; color: var(--text-color);">请二次输入新密码</label>
                    <input type="password" id="confirm-password" style="
                        width: 100%;
                        padding: 10px;
                        border: 1px solid var(--border-color);
                        border-radius: 6px;
                        font-size: 14px;
                        box-sizing: border-box;
                        background: var(--input-bg);
                        color: var(--text-color);
                    " placeholder="请再次输入新密码">
                </div>
                <div style="display: flex; justify-content: flex-end; gap: 10px;">
                    <button onclick="closeChangePasswordModal()" style="
                        padding: 10px 20px;
                        background: var(--hover-bg);
                        color: var(--text-color);
                        border: 1px solid var(--border-color);
                        border-radius: 6px;
                        cursor: pointer;
                        font-size: 14px;
                        transition: background-color 0.2s;
                    ">取消</button>
                    <button onclick="changePassword()" style="
                        padding: 10px 20px;
                        background: var(--primary-color);
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
        <div class="modal-content" style="width: 400px; background: var(--modal-bg); color: var(--text-color);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 1px solid var(--border-color);">
                <h2 style="color: var(--text-color); font-size: 20px; font-weight: 600;">修改名称</h2>
                <button onclick="closeChangeNameModal()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: var(--text-secondary);">×</button>
            </div>
            <div class="change-name-content" style="padding: 0 20px 20px;">
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500; color: var(--text-color);">请输入要修改的名称</label>
                    <input type="text" id="new-name" value="<?php echo htmlspecialchars($username); ?>" style="
                        width: 100%;
                        padding: 10px;
                        border: 1px solid var(--border-color);
                        border-radius: 6px;
                        font-size: 14px;
                        box-sizing: border-box;
                        background: var(--input-bg);
                        color: var(--text-color);
                    " placeholder="请输入新名称">
                </div>
                <div style="display: flex; justify-content: flex-end; gap: 10px;">
                    <button onclick="closeChangeNameModal()" style="
                        padding: 10px 20px;
                        background: var(--hover-bg);
                        color: var(--text-color);
                        border: 1px solid var(--border-color);
                        border-radius: 6px;
                        cursor: pointer;
                        font-size: 14px;
                        transition: background-color 0.2s;
                    ">取消</button>
                    <button onclick="changeName()" style="
                        padding: 10px 20px;
                        background: var(--primary-color);
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
        <div class="modal-content" style="width: 400px; background: var(--modal-bg); color: var(--text-color);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 1px solid var(--border-color);">
                <h2 style="color: var(--text-color); font-size: 20px; font-weight: 600;">修改邮箱</h2>
                <button onclick="closeChangeEmailModal()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: var(--text-secondary);">×</button>
            </div>
            <div class="change-email-content" style="padding: 0 20px 20px;">
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500; color: var(--text-color);">请输入要修改的邮箱</label>
                    <input type="email" id="new-email" value="<?php echo htmlspecialchars($current_user['email']); ?>" style="
                        width: 100%;
                        padding: 10px;
                        border: 1px solid var(--border-color);
                        border-radius: 6px;
                        font-size: 14px;
                        box-sizing: border-box;
                        background: var(--input-bg);
                        color: var(--text-color);
                    " placeholder="请输入新邮箱">
                </div>
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500; color: var(--text-color);">Service Worker 缓存</label>
                    <button onclick="clearServiceWorkerCache()" style="
                        padding: 10px 20px;
                        background: #ff4757;
                        color: white;
                        border: none;
                        border-radius: 6px;
                        cursor: pointer;
                        font-size: 14px;
                        transition: background-color 0.2s;
                    ">清除缓存并注销</button>
                    <p style="margin-top: 5px; font-size: 12px; color: var(--text-desc);">如果遇到页面显示问题，可以尝试清除缓存。</p>
                </div>
                <div style="display: flex; justify-content: flex-end; gap: 10px;">
                    <button onclick="closeChangeEmailModal()" style="
                        padding: 10px 20px;
                        background: var(--hover-bg);
                        color: var(--text-color);
                        border: 1px solid var(--border-color);
                        border-radius: 6px;
                        cursor: pointer;
                        font-size: 14px;
                        transition: background-color 0.2s;
                    ">取消</button>
                    <button onclick="changeEmail()" style="
                        padding: 10px 20px;
                        background: var(--primary-color);
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
    
    <!-- 缓存查看弹窗 -->
    <div id="cache-viewer-modal" class="modal" style="display: none;">
        <div class="modal-content" style="width: 600px; background: var(--modal-bg); color: var(--text-color);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 1px solid var(--border-color);">
                <h2 style="color: var(--text-color); font-size: 20px; font-weight: 600;">查看缓存</h2>
                <button onclick="closeCacheViewer()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: var(--text-secondary);">×</button>
            </div>
            
            <div id="cache-stats" style="margin-bottom: 20px;">
                <!-- 缓存统计信息将通过JavaScript动态加载 -->
                <p style="text-align: center; color: var(--text-secondary);">加载缓存信息中...</p>
            </div>
            
            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button onclick="closeCacheViewer()" style="
                    padding: 10px 20px;
                    background: var(--hover-bg);
                    color: var(--text-color);
                    border: 1px solid var(--border-color);
                    border-radius: 6px;
                    cursor: pointer;
                    font-size: 14px;
                ">关闭</button>
                <button onclick="showClearCacheConfirm()" style="
                    padding: 10px 20px;
                    background: var(--danger-color);
                    color: white;
                    border: none;
                    border-radius: 6px;
                    cursor: pointer;
                    font-size: 14px;
                ">清空缓存</button>
            </div>
        </div>
    </div>
    
    <!-- 清空缓存确认弹窗 -->
    <div id="clear-cache-confirm-modal" class="modal" style="display: none;">
        <div class="modal-content" style="width: 400px; background: var(--modal-bg); color: var(--text-color);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 1px solid var(--border-color);">
                <h2 style="color: var(--text-color); font-size: 20px; font-weight: 600;">清空缓存？</h2>
                <button onclick="closeClearCacheConfirm()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: var(--text-secondary);">×</button>
            </div>
            
            <div id="clear-cache-info" style="margin-bottom: 20px;">
                <p>你将要清除缓存的全部文件（包括图片 视频 音频 文件）总大小为：<strong id="clear-cache-size">0 B</strong></p>
                <p>确定要清除吗？</p>
            </div>
            
            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button onclick="closeClearCacheConfirm()" style="
                    padding: 10px 20px;
                    background: var(--hover-bg);
                    color: var(--text-color);
                    border: 1px solid var(--border-color);
                    border-radius: 6px;
                    cursor: pointer;
                    font-size: 14px;
                ">取消</button>
                <button onclick="clearCache()" style="
                    padding: 10px 20px;
                    background: var(--danger-color);
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
        <div class="modal-content">
            <h2 style="color: #d32f2f; margin-bottom: 20px; font-size: 24px;">群聊已被封禁</h2>
            <div id="group-ban-info" style="color: #666; margin-bottom: 25px; font-size: 14px;">
                <!-- 群聊封禁信息将通过JavaScript动态加载 -->
            </div>
            <button onclick="document.getElementById('group-ban-modal').style.display = 'none'" style="
                padding: 12px 30px;
                background: #667eea;
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
        <div class="modal-content" style="background: var(--modal-bg); color: var(--text-color);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 style="color: var(--text-color); font-size: 20px; font-weight: 600;">建立群聊</h2>
                <button onclick="document.getElementById('create-group-modal').style.display = 'none'" style="background: none; border: none; font-size: 24px; cursor: pointer; color: var(--text-secondary);">×</button>
            </div>
            <div style="margin-bottom: 20px;">
                <label for="group-name" style="display: block; margin-bottom: 5px; color: var(--text-color); font-weight: 500;">群聊名称</label>
                <input type="text" id="group-name" placeholder="请输入群聊名称" style="
                    width: 100%;
                    padding: 10px;
                    border: 1px solid var(--border-color);
                    border-radius: 6px;
                    font-size: 14px;
                    margin-bottom: 20px;
                    background: var(--input-bg);
                    color: var(--text-color);
                    box-sizing: border-box;
                ">
            </div>
            
            <div style="margin-bottom: 20px;">
                <h3 style="color: var(--text-color); font-size: 16px; font-weight: 600; margin-bottom: 10px;">选择好友</h3>
                <div id="select-friends-container" style="
                    max-height: 300px;
                    overflow-y: auto;
                    border: 1px solid var(--border-color);
                    border-radius: 6px;
                    padding: 10px;
                    background: var(--input-bg);
                ">
                    <!-- 好友选择列表将通过JavaScript动态生成 -->
                </div>
            </div>
            
            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button onclick="document.getElementById('create-group-modal').style.display = 'none'" style="
                    padding: 10px 20px;
                    background: var(--hover-bg);
                    color: var(--text-color);
                    border: 1px solid var(--border-color);
                    border-radius: 6px;
                    cursor: pointer;
                    font-size: 14px;
                    transition: background-color 0.2s;
                ">取消</button>
                <button onclick="createGroup()" style="
                    padding: 10px 20px;
                    background: #12b7f5;
                    color: white;
                    border: none;
                    border-radius: 6px;
                    cursor: pointer;
                    font-size: 14px;
                    transition: background-color 0.2s;
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
                                <button class="video-control-btn" id="video-download-btn" title="下载" onclick="event.stopPropagation(); downloadCurrentVideo()">⬇</button>
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
            <div style="background: linear-gradient(135deg, #667eea 0%, #0095ff 100%); color: white; padding: 20px; display: flex; justify-content: space-between; align-items: center;">
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
            <!-- 搜索框 -->
            <div style="margin-bottom: 15px; position: relative;">
                <input type="text" id="group-member-search" placeholder="搜索成员..." oninput="filterGroupMembers()" style="width: 100%; padding: 10px 15px 10px 35px; border: 1px solid var(--border-color); background: var(--input-bg); color: var(--text-color); border-radius: 8px; font-size: 14px; outline: none; transition: border-color 0.2s;">
                <span style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-desc);">🔍</span>
            </div>
            <div id="group-members-list" style="max-height: 400px; overflow-y: auto; padding: 10px;">
                <!-- 群聊成员列表将通过JavaScript动态加载 -->
                <p style="text-align: center; color: var(--text-secondary);">加载中...</p>
            </div>
        </div>
    </div>

        </div>
    </div>
    
    <!-- 设置弹窗 -->
    <div id="settings-modal" class="modal" style="display: none;">
        <div class="modal-content">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 style="color: #333; font-size: 20px; font-weight: 600;">设置</h2>
                <button onclick="document.getElementById('settings-modal').style.display = 'none'" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #666;">×</button>
            </div>
            <div class="settings-content">
                <div class="setting-item">
                    <label for="use-popup-for-links" class="setting-label">使用弹窗显示链接</label>
                    <label class="switch">
                        <input type="checkbox" id="use-popup-for-links" checked>
                        <span class="slider"></span>
                    </label>
                </div>
                <div class="setting-item">
                    <label for="enable-music-player" class="setting-label">音乐播放器</label>
                    <label class="switch">
                        <input type="checkbox" id="enable-music-player" checked>
                        <span class="slider"></span>
                    </label>
                </div>
            </div>
            <div style="margin-top: 20px; display: flex; justify-content: flex-end;">
                <button onclick="saveSettings()" style="
                    padding: 10px 20px;
                    background: #667eea;
                    color: white;
                    border: none;
                    border-radius: 6px;
                    cursor: pointer;
                    font-size: 14px;
                    font-weight: 500;
                ">
                    保存设置
                </button>
            </div>
        </div>
    </div>
    
    <!-- 图片查看器 -->
    <div class="image-viewer" id="image-viewer">
        <div class="image-viewer-content">
            <img src="" alt="" class="image-viewer-image" id="image-viewer-image">
            <div class="image-viewer-controls">
                <div class="zoom-level" id="zoom-level">100%</div>
                <button class="image-viewer-btn" onclick="zoomOut()">-</button>
                <button class="image-viewer-btn" onclick="resetZoom()">重置</button>
                <button class="image-viewer-btn" onclick="zoomIn()">+</button>
            </div>
        </div>
        <button class="image-viewer-close" onclick="closeImageViewer()">&times;</button>
    </div>

    <!-- 下载面板 -->
    <div class="download-panel" id="download-panel">
        <div class="download-panel-header">
            <h3>📥 下载</h3>
            <div class="download-panel-controls">
                <button class="download-panel-btn" onclick="event.stopPropagation(); clearAllDownloadTasks()">清除全部</button>
                <button class="download-panel-btn" onclick="toggleDownloadPanel()">关闭</button>
            </div>
        </div>
        <div class="download-panel-content" id="download-panel-content">
            <div id="download-tasks-list" style="padding: 10px; text-align: center; color: #666;">
                暂无下载任务
            </div>
        </div>
    </div>

    <!-- 反馈弹窗 -->
    <div id="feedback-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); z-index: 2000; justify-content: center; align-items: center;">
        <div style="background: var(--modal-bg); color: var(--text-color); border-radius: 12px; width: 90%; max-width: 500px; overflow: hidden; display: flex; flex-direction: column; border: 1px solid var(--border-color);">
            <!-- 弹窗头部 -->
            <div style="padding: 20px; background: #12b7f5; color: white; display: flex; justify-content: space-between; align-items: center;">
                <h3 style="margin: 0; font-size: 18px; color: white;">反馈问题</h3>
                <button onclick="closeFeedbackModal()" style="background: none; border: none; color: white; font-size: 24px; cursor: pointer; padding: 0; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center;">×</button>
            </div>
            
            <!-- 弹窗内容 -->
            <div style="padding: 20px; overflow-y: auto; flex: 1;">
                <form id="feedback-form" enctype="multipart/form-data">
                    <div style="margin-bottom: 20px;">
                        <label for="feedback-content" style="display: block; margin-bottom: 8px; font-weight: 600; color: var(--text-color);">问题描述</label>
                        <textarea id="feedback-content" name="content" placeholder="请详细描述您遇到的问题" rows="5" style="width: 100%; padding: 12px; border: 1px solid var(--border-color); border-radius: 8px; font-size: 14px; resize: vertical; outline: none; background: var(--input-bg); color: var(--text-color); box-sizing: border-box;" required></textarea>
                    </div>
                    <div style="margin-bottom: 20px;">
                        <label for="feedback-image" style="display: block; margin-bottom: 8px; font-weight: 600; color: var(--text-color);">添加图片（可选）</label>
                        <input type="file" id="feedback-image" name="image" accept="image/*" style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 8px; font-size: 14px; background: var(--input-bg); color: var(--text-color); box-sizing: border-box;">
                        <p style="font-size: 12px; color: var(--text-secondary); margin-top: 5px;">支持JPG、PNG、GIF格式，最大5MB</p>
                    </div>
                    <div style="display: flex; gap: 10px; justify-content: flex-end;">
                        <button type="button" onclick="closeFeedbackModal()" style="padding: 10px 20px; background: var(--hover-bg); color: var(--text-color); border: 1px solid var(--border-color); border-radius: 6px; cursor: pointer; font-size: 14px; transition: background-color 0.2s;">取消</button>
                        <button type="submit" style="padding: 10px 20px; background: #12b7f5; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px;">提交反馈</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- 添加好友窗口 -->
    <div id="add-friend-modal" class="modal" style="display: none;">
        <div class="modal-content" style="width: 500px; background: var(--modal-bg); color: var(--text-color);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 1px solid var(--border-color);">
                <h2 style="color: var(--text-color); font-size: 20px; font-weight: 600;">添加</h2>
                <button onclick="closeAddFriendWindow()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: var(--text-secondary);">×</button>
            </div>
            
            <!-- 选项卡 -->
            <div style="display: flex; margin-bottom: 20px; border-bottom: 1px solid var(--border-color);">
                <button id="search-tab" class="add-friend-tab active" onclick="switchAddFriendTab('search')" style="flex: 1; padding: 12px; border: none; background: transparent; cursor: pointer; font-size: 14px; font-weight: 600; color: var(--primary-color); border-bottom: 2px solid var(--primary-color);">搜索用户</button>
                <button id="requests-tab" class="add-friend-tab" onclick="switchAddFriendTab('requests')" style="flex: 1; padding: 12px; border: none; background: transparent; cursor: pointer; font-size: 14px; font-weight: 600; color: var(--text-secondary);">申请列表 <?php if ($pending_requests_count > 0): ?><span id="friend-request-count" style="background: var(--danger-color); color: white; border-radius: 10px; padding: 2px 8px; font-size: 12px; margin-left: 5px;"><?php echo $pending_requests_count; ?></span><?php endif; ?></button>
            </div>
            
            <!-- 搜索用户内容 -->
            <div id="search-content" class="add-friend-content" style="display: block;">
                <div style="margin-bottom: 15px;">
                    <input type="text" id="search-user-input" placeholder="输入用户名或邮箱搜索" style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; font-size: 14px; background: var(--input-bg); color: var(--text-color);">
                </div>
                <div style="margin-bottom: 15px;">
                    <button id="search-user-button" onclick="searchUser()" style="width: 100%; padding: 10px; background: var(--primary-color); color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 500; transition: background-color 0.2s;">搜索</button>
                </div>
                <div id="search-results" style="max-height: 300px; overflow-y: auto;">
                    <p style="text-align: center; color: var(--text-secondary); padding: 20px;">请输入用户名或邮箱进行搜索</p>
                </div>
            </div>
            
            <!-- 申请列表内容 -->
            <div id="requests-content" class="add-friend-content" style="display: none;">
                <div id="friend-requests-list" style="max-height: 350px; overflow-y: auto;">
                    <!-- 申请列表将通过JavaScript动态加载 -->
                    <p style="text-align: center; color: var(--text-secondary); padding: 20px;">加载中...</p>
                </div>
            </div>
            

        </div>
    </div>
    
    <!-- 主聊天容器 -->
    <div class="chat-container">
        <!-- 左侧边栏 -->
        <div class="sidebar">
            <!-- 顶部用户信息 -->
            <div class="sidebar-header">
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
            <div id="main-search-results" style="display: none; padding: 15px; background: white; border-bottom: 1px solid #eaeaea; max-height: 300px; overflow-y: auto; position: absolute; width: calc(300px - 30px); z-index: 1000;">
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
                            <button class="chat-item-menu-btn" onclick="toggleFriendMenu(event, <?php echo $friend_id; ?>, <?php echo htmlspecialchars(json_encode($friend_item['username']), ENT_QUOTES); ?>)">
                                ⋮
                            </button>
                            <!-- 好友菜单 -->
                            <div class="friend-menu" id="friend-menu-<?php echo $friend_id; ?>" style="display: none; position: absolute; top: 100%; right: 0; background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1); z-index: 1000; min-width: 120px; margin-top: 5px;">
                                <button class="friend-menu-item" onclick="deleteFriend(<?php echo $friend_id; ?>, <?php echo htmlspecialchars(json_encode($friend_item['username']), ENT_QUOTES); ?>)">删除好友</button>
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
                            <button class="chat-item-menu-btn" onclick="toggleGroupMenu(event, <?php echo $group_item['id']; ?>, <?php echo htmlspecialchars(json_encode($group_item['name']), ENT_QUOTES); ?>)">
                                ⋮
                            </button>
                            <!-- 群聊菜单 -->
                            <div class="friend-menu" id="group-menu-<?php echo $group_item['id']; ?>" style="display: none; position: absolute; top: 100%; right: 0; background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1); z-index: 1000; min-width: 150px; margin-top: 5px;">
                                <button class="friend-menu-item" onclick="showGroupMembers(<?php echo $group_item['id']; ?>)">查看成员</button>
                                <button class="friend-menu-item" onclick="inviteFriendsToGroup(<?php echo $group_item['id']; ?>)">邀请好友</button>
                                <?php 
                                    // 检查用户是否是群主或管理员
                                    $is_admin = false;
                                    if ($group_item['owner_id'] == $user_id) {
                                        $is_admin = true;
                                    } else {
                                        // 检查是否是管理员
                                        $group_members = $group->getGroupMembers($group_item['id']);
                                        foreach ($group_members as $member) {
                                            if (isset($member['user_id']) && isset($member['is_admin']) && $member['user_id'] == $user_id && $member['is_admin']) {
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
            
            <!-- 底部功能图标 -->
            <div class="sidebar-footer">
                <div class="footer-icon" title="下载" onclick="toggleDownloadPanel()">📥</div>
                <div id="music-icon" class="footer-icon" title="音乐播放">🎵</div>
                <div class="footer-icon" title="创建群聊" onclick="showCreateGroupModal()">👥</div>
                <div class="footer-icon" title="反馈问题" onclick="showFeedbackModal()">⁉️</div>
                <div class="footer-icon" title="添加好友" onclick="showAddFriendWindow()">➕</div>
                <div class="footer-icon" title="设置" onclick="openSettingsModal()">⚙️</div>
            </div>
        </div>
        
        <!-- 聊天区域 -->
        <div class="chat-area">
            <?php if (($chat_type === 'friend' && $selected_friend) || ($chat_type === 'group' && $selected_group)): ?>
                <!-- 聊天区域顶部 -->
                <div class="chat-header">
                    <?php if ($chat_type === 'friend'): ?>
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
                    <?php else: ?>
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
                            $is_within_2_minutes = $time_diff_minutes < 2;
                        ?>
                        <div class="message <?php echo $is_sent ? 'sent' : 'received'; ?>" 
                            data-message-id="<?php echo $msg['id']; ?>" 
                            data-chat-type="<?php echo $chat_type; ?>" 
                            data-chat-id="<?php echo $selected_id; ?>" 
                            data-message-time="<?php echo $msg_time * 1000; ?>">
                            <?php if ($is_sent): ?>
                                <!-- 发送者的消息，内容在左，头像在右 -->
                                <div class="message-content" style="position: relative;">
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
                                            echo "<img src='".htmlspecialchars($file_path)."' alt='".htmlspecialchars($file_name)."' class='message-image' data-file-name='".htmlspecialchars($file_name)."' data-file-type='image' data-file-path='".htmlspecialchars($file_path)."' onerror=\"this.style.display='none'; this.insertAdjacentHTML('afterend', '<div class=&quot;file-cleaned-tip&quot;>文件已被清理</div>');\">";
                                            echo "</div>";
                                        } elseif (in_array($ext, $audio_exts)) {
                                            // 音频类型
                                            echo "<div class='message-media' style='overflow: visible; box-shadow: none; background: transparent;'>";
                                            echo "<div class='custom-audio-player'>";
                                            echo "<audio src='".htmlspecialchars($file_path)."' class='audio-element' data-file-name='".htmlspecialchars($file_name)."' data-file-type='audio' data-file-path='".htmlspecialchars($file_path)."' onerror=\"if(this.parentElement){this.parentElement.style.display='none'; this.parentElement.insertAdjacentHTML('afterend', '<div class=&quot;file-cleaned-tip&quot;>文件已被清理</div>');}\"></audio>";
                                            echo "<button class='audio-play-btn' title='播放'></button>";
                                            echo "<div class='audio-progress-container'>";
                                            echo "<div class='audio-progress-bar'>";
                                            echo "<div class='audio-progress'></div>";
                                            echo "</div>";
                                            echo "</div>";
                                            echo "<span class='audio-time current-time'>0:00</span>";
                                            echo "<span class='audio-duration'>0:00</span>";
                                            $js_file_name = htmlspecialchars(json_encode($file_name), ENT_QUOTES);
                                            $js_file_path = htmlspecialchars(json_encode($file_path), ENT_QUOTES);
                                            echo "<button class='media-action-btn' onclick='event.stopPropagation(); addDownloadTask({$js_file_name}, {$js_file_path}, ".htmlspecialchars($file_size).", \"audio\");' title='下载' style='width: 28px; height: 28px; font-size: 16px; background: rgba(0,0,0,0.1); border: none; border-radius: 50%; color: #666; cursor: pointer; margin-left: 10px; z-index: 4000; position: relative;'>⬇</button>";
                                            echo "</div>";
                                            echo "</div>";
                                        } elseif (in_array($ext, $video_exts)) {
                                            // 视频类型
                                            echo "<div class='message-media'>";
                                            echo "<div class='video-container' style='position: relative;'>";
                                            echo "<video src='".htmlspecialchars($file_path)."' class='video-element' data-file-name='".htmlspecialchars($file_name)."' data-file-type='video' data-file-path='".htmlspecialchars($file_path)."' controlsList='nodownload' onerror=\"if(this.parentElement){this.parentElement.style.display='none'; this.parentElement.insertAdjacentHTML('afterend', '<div class=&quot;file-cleaned-tip&quot;>文件已被清理</div>');}\">";
                                            echo "</video>";
                                            echo "</div>";
                                            echo "</div>";
                                        } elseif (isset($msg['type']) && $msg['type'] == 'file') {
                                            // 其他文件类型
                                        ?>
                                            <div class="message-file" onclick="addDownloadTask(<?php echo htmlspecialchars(json_encode($file_name), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode($file_path), ENT_QUOTES); ?>, <?php echo $file_size; ?>, 'file')" style="position: relative; background: #f0f0f0; border-radius: 8px; padding: 12px; display: flex; align-items: center; gap: 12px; cursor: pointer;">
                                                <div class="message-file-link" data-file-name="<?php echo htmlspecialchars($file_name); ?>" data-file-size="<?php echo $file_size; ?>" data-file-type="file" data-file-path="<?php echo htmlspecialchars($file_path); ?>" style="display: flex; align-items: center; gap: 12px; text-decoration: none; color: inherit; flex: 1;">
                                                    <span class="file-icon" style="font-size: 24px;">📁</span>
                                                    <div class="file-info" style="flex: 1;">
                                                        <h4 style="margin: 0; font-size: 14px; font-weight: 500;"><?php echo htmlspecialchars($file_name); ?></h4>
                                                        <p style="margin: 2px 0 0 0; font-size: 12px; color: #666;"><?php echo round($file_size / 1024, 2); ?> KB</p>
                                                    </div>
                                                </div>
                                                <button onclick="event.stopPropagation(); addDownloadTask(<?php echo htmlspecialchars(json_encode($file_name), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode($file_path), ENT_QUOTES); ?>, <?php echo $file_size; ?>, 'file')" style="background: #667eea; color: white; border: none; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 12px; transition: all 0.2s ease;">下载</button>
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
                                            $content_with_links = preg_replace_callback($pattern, function($matches) {
                                                $url = $matches[0];
                                                $safe_url = htmlspecialchars($url, ENT_QUOTES);
                                                $js_url = str_replace("'", "\\'", $url); // 转义单引号用于JS字符串
                                                return '<a href="#" onclick="event.preventDefault(); handleLinkClick(\'' . $js_url . '\')" style="color: #12b7f5; text-decoration: underline;">' . $safe_url . '</a>';
                                            }, $content);
                                            echo "<div class='message-text'>{$content_with_links}</div>";
                                        }
                                    ?>
                                    <div class="message-time"><?php echo date('Y年m月d日 H:i', strtotime($msg['created_at'])); ?></div>
                                    <?php if (true): // 始终显示三个点按钮，撤回功能在菜单内判断 ?>
                                        <div class='message-actions' style='position: absolute; top: 50%; right: -10px; transform: translateY(-50%); display: flex; align-items: center; gap: 5px; z-index: 9999;'>
                                            <div style='position: relative; z-index: 9999;'>
                                                <button class='message-action-btn' onclick='toggleMessageActions(this)' style='width: 28px; height: 28px; font-size: 18px; background: rgba(0,0,0,0.2); border: none; border-radius: 50%; color: #333; cursor: pointer; display: flex; align-items: center; justify-content: center; opacity: 1; transition: all 0.2s ease; position: relative; z-index: 9999;'>⋮</button>
                                                <div class='message-action-menu' style='display: none; position: absolute; top: 35px; right: 0; background: white; border-radius: 8px; box-shadow: 0 4px 16px rgba(0,0,0,0.2); padding: 8px 0; z-index: 10000; min-width: 100px; border: 1px solid var(--border-color);'>
                                                    <?php if ($is_within_2_minutes): ?>
                                                        <button class='message-action-item' onclick='recallMessage(this, "<?php echo $msg['id']; ?>", "<?php echo $chat_type; ?>", "<?php echo $selected_id; ?>")' style='display: block; width: 100%; text-align: left; padding: 8px 16px; border: none; background: transparent; cursor: pointer; transition: all 0.2s ease; color: #333;'>撤回</button>
                                                    <?php endif; ?>
                                                    
                                                    <?php 
                                                    // 如果是文件消息，添加下载按钮
                                                    if (isset($msg['type']) && $msg['type'] == 'file' || (isset($msg['file_path']) && !empty($msg['file_path']))) {
                                                        $dl_file_name = isset($msg['file_name']) ? $msg['file_name'] : '';
                                                        $dl_file_path = isset($msg['file_path']) ? $msg['file_path'] : '';
                                                        $dl_file_size = isset($msg['file_size']) ? $msg['file_size'] : 0;
                                                        $dl_file_type = isset($msg['type']) ? $msg['type'] : 'file';
                                                        
                                                        // 转义用于JS
                                                        $js_file_name = htmlspecialchars(json_encode($dl_file_name), ENT_QUOTES);
                                                        $js_file_path = htmlspecialchars(json_encode($dl_file_path), ENT_QUOTES);
                                                    ?>
                                                        <button class='message-action-item' onclick='event.stopPropagation(); addDownloadTask(<?php echo $js_file_name; ?>, <?php echo $js_file_path; ?>, <?php echo $dl_file_size; ?>, "<?php echo $dl_file_type; ?>")' style='display: block; width: 100%; text-align: left; padding: 8px 16px; border: none; background: transparent; cursor: pointer; transition: all 0.2s ease; color: #333;'>下载</button>
                                                    <?php } ?>
                                                </div>
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
                                            echo "<img src='".htmlspecialchars($file_path)."' alt='".htmlspecialchars($file_name)."' class='message-image' onerror=\"this.style.display='none'; this.insertAdjacentHTML('afterend', '<div class=&quot;file-cleaned-tip&quot;>文件已被清理</div>');\">";
                                            echo "</div>";
                                        } elseif (in_array($ext, $audio_exts)) {
                                            // 音频类型
                                            echo "<div class='message-media'>";
                                            echo "<div class='custom-audio-player'>";
                                            echo "<audio src='{$file_path}' class='audio-element' data-file-name='{$file_name}' data-file-type='audio' data-file-path='{$file_path}' onerror=\"if(this.parentElement){this.parentElement.style.display='none'; this.parentElement.insertAdjacentHTML('afterend', '<div class=&quot;file-cleaned-tip&quot;>文件已被清理</div>');}\"></audio>";
                                            echo "<button class='audio-play-btn' title='播放'></button>";
                                            echo "<div class='audio-progress-container'>";
                                            echo "<div class='audio-progress-bar'>";
                                            echo "<div class='audio-progress'></div>";
                                            echo "</div>";
                                            echo "</div>";
                                            echo "<span class='audio-time current-time'>0:00</span>";
                                            echo "<span class='audio-duration'>0:00</span>";
                                            $js_file_name = htmlspecialchars(json_encode($file_name), ENT_QUOTES);
                                            $js_file_path = htmlspecialchars(json_encode($file_path), ENT_QUOTES);
                                            echo "<button class='media-action-btn' onclick='event.stopPropagation(); addDownloadTask({$js_file_name}, {$js_file_path}, ".htmlspecialchars($file_size).", \"audio\");' title='下载' style='width: 28px; height: 28px; font-size: 16px; background: rgba(0,0,0,0.1); border: none; border-radius: 50%; color: #666; cursor: pointer; margin-left: 10px; z-index: 4000; position: relative;'>⬇</button>";
                                            echo "</div>";
                                            echo "</div>";
                                        } elseif (in_array($ext, $video_exts)) {
                                            // 视频类型
                                            echo "<div class='message-media'>";
                                            echo "<div class='video-container'>";
                                            echo "<video src='{$file_path}' class='video-element' data-file-name='{$file_name}' data-file-type='video' data-file-path='{$file_path}' controlsList='nodownload'>";
                                            echo "</video>";
                                            echo "</div>";
                                            echo "</div>";
                                        } elseif (isset($msg['type']) && $msg['type'] == 'file') {
                                            // 其他文件类型
                                        ?>
                                            <div class="message-file" onclick="addDownloadTask(<?php echo htmlspecialchars(json_encode($file_name), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode($file_path), ENT_QUOTES); ?>, <?php echo $file_size; ?>, 'file')" style="position: relative; background: #f0f0f0; border-radius: 8px; padding: 12px; display: flex; align-items: center; gap: 12px; cursor: pointer;">
                                                <div class="message-file-link" data-file-name="<?php echo htmlspecialchars($file_name); ?>" data-file-size="<?php echo $file_size; ?>" data-file-type="file" data-file-path="<?php echo htmlspecialchars($file_path); ?>" style="display: flex; align-items: center; gap: 12px; text-decoration: none; color: inherit; flex: 1;">
                                                    <span class="file-icon" style="font-size: 24px;">📁</span>
                                                    <div class="file-info" style="flex: 1;">
                                                        <h4 style="margin: 0; font-size: 14px; font-weight: 500;"><?php echo htmlspecialchars($file_name); ?></h4>
                                                        <p style="margin: 2px 0 0 0; font-size: 12px; color: #666;"><?php echo round($file_size / 1024, 2); ?> KB</p>
                                                    </div>
                                                </div>
                                                <button onclick="event.stopPropagation(); addDownloadTask(<?php echo htmlspecialchars(json_encode($file_name), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode($file_path), ENT_QUOTES); ?>, <?php echo $file_size; ?>, 'file')" style="background: #667eea; color: white; border: none; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 12px; transition: all 0.2s ease;">下载</button>
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
                    <!-- 录音指示器 -->
                    <div id="recording-indicator" style="display: none; position: absolute; bottom: 10px; left: 10px; color: #ff4757; font-size: 12px; font-weight: bold;">
                        <span class="recording-dots">● ● ●</span> 录音中...
                    </div>
                    
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
                            <button class="btn-icon" id="file-input-btn" title="发送文件">📎</button>
                            <input type="file" id="file-input" style="display: none;">
                            <button class="btn-icon" id="screenshot-btn" onclick="takeScreenshot()" title="截图 (Ctrl+Alt+D)">📸</button>
                            <button class="btn-icon" id="record-btn" onclick="toggleRecording()" title="录音 (按Q键开始/结束)" 
                                    style="color: #666; transition: all 0.2s ease;">🎤</button>
                            <button class="btn-icon" id="send-btn" title="发送消息">➤</button>
                        </div>
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
                        background: linear-gradient(135deg, #667eea 0%, #0095ff 100%);
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
                
                <!-- 全局录音提示 -->
                <div id="recording-hint" style="display: none; position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%); background: rgba(0, 0, 0, 0.8); color: white; padding: 10px 20px; border-radius: 20px; font-size: 14px; z-index: 1000;">
                    <span class="recording-dots">● ● ●</span> 录音中...
                </div>
            <?php else: ?>
                <!-- 未选择聊天对象时显示 -->
                <div class="chat-header">
                    <div class="chat-header-info">
                        <div class="chat-header-name">选择聊天对象</div>
                        <div class="chat-header-status">请从左侧列表选择好友或群聊开始聊天</div>
                    </div>
                </div>
                <div class="messages-container" style="justify-content: center; align-items: center; color: #999; font-size: 16px;">
                    请选择一个聊天对象开始聊天
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- 初始聊天记录数据 -->
    <script>
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
                // 获取群成员失败，忽略错误
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
                // 提取@符号后的文字（直到空格或光标位置）
                const afterAt = text.substring(atIndex + 1, cursorPos);
                
                // 检查@符号后是否有空格
                if (afterAt.includes(' ')) {
                    // 有空格，隐藏提及列表
                    hideMentionList();
                    return;
                }
                
                // 显示提及列表，并传递搜索关键词
                showMentionList(input, atIndex + 1, afterAt);
            } else {
                // 没有@符号，隐藏提及列表
                hideMentionList();
            }
        }
        
        // 显示提及列表
        function showMentionList(input, startIndex, searchKeyword = '') {
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
            
            // 根据搜索关键词过滤成员
            let filteredOptions = mentionOptions;
            if (searchKeyword) {
                const keyword = searchKeyword.toLowerCase();
                filteredOptions = mentionOptions.filter(member => {
                    // "全体成员"始终显示
                    if (member.is_all) {
                        return true;
                    }
                    // 搜索用户名或昵称
                    return member.username.toLowerCase().includes(keyword) || 
                           member.nickname.toLowerCase().includes(keyword);
                });
            }
            
            // 渲染提及列表
            let html = '';
            
            if (filteredOptions.length === 0) {
                // 无搜索结果
                html = `<div class="mention-item no-results" style="color: #999; cursor: default; text-align: center; padding: 10px;">
                            无搜索结果
                        </div>`;
            } else {
                // 渲染过滤后的成员列表
                html = filteredOptions.map((member, index) => {
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
            }
            
            mentionList.innerHTML = html;
            
            // 显示列表
            mentionList.style.display = 'block';
            mentionListVisible = true;
            selectedMentionIndex = -1;
            
            // 添加点击事件
            mentionList.querySelectorAll('.mention-item:not(.no-results)').forEach(item => {
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
        
        // 页面加载完成后初始化@功能
        document.addEventListener('DOMContentLoaded', () => {
            initMentionFeature();
        });
        
        // 切换聊天时重新初始化群成员并重置@提及标记
        function switchChat(chatType, chatId) {
            if (chatType === 'group') {
                initGroupMembers();
                
                // 重置@提及标记
                fetch(`reset_mention.php?chat_type=group&chat_id=${chatId}`)
                    .catch(error => {
                        // 重置@提及标记失败，忽略错误
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
                    // 检查群聊封禁状态失败，忽略错误
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
                window.location.href = `chat.php?chat_type=${chatType}`;
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
                        window.location.href = `chat.php?chat_type=friend&id=${friendId}`;
                    } else if (this.dataset.groupId) {
                        const groupId = this.dataset.groupId;
                        window.location.href = `chat.php?chat_type=group&id=${groupId}`;
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
                    // 删除好友失败，忽略错误
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
        
        // 页面加载时加载设置
        document.addEventListener('DOMContentLoaded', function() {
            // 延迟执行loadSettings，确保indexedDBManager已经初始化
            setTimeout(loadSettings, 100);
        });
        
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
                        localStorage.removeItem('setting-music-player');
                        // 重定向到登录页面
                        window.location.href = 'login.php';
                    } else {
                        // 退出失败，显示错误信息
                        showNotification(data.message || '退出登录失败', 'error');
                    }
                })
                .catch(error => {
                    // 退出登录请求失败，忽略错误
                    // 即使请求失败，也尝试直接跳转
                    localStorage.removeItem('setting-link-popup');
                    localStorage.removeItem('setting-music-player');
                    window.location.href = 'login.php';
                });
            }
        }
        
        // 加载设置
        async function loadSettings() {
            try {
                let settings;
                let linkPopup = true; // 默认值
                let musicPlayer = true; // 默认值
                let musicMode = 'random'; // 默认值
                
                // 检查indexedDBManager是否已经初始化
                if (typeof indexedDBManager !== 'undefined') {
                    try {
                        // 从IndexedDB加载设置
                        settings = await indexedDBManager.getSettings();
                        linkPopup = settings['setting-link-popup'] !== false;
                        musicPlayer = settings['setting-music-player'] !== false;
                        musicMode = settings['setting-music-mode'] || 'random';
                    } catch (error) {
                        // IndexedDB加载失败，使用localStorage
                        linkPopup = localStorage.getItem('setting-link-popup') === 'false' ? false : true;
                        musicPlayer = localStorage.getItem('setting-music-player') === 'false' ? false : true;
                        musicMode = localStorage.getItem('setting-music-mode') || 'random';
                    }
                } else {
                    // indexedDBManager未初始化，使用localStorage
                    linkPopup = localStorage.getItem('setting-link-popup') === 'false' ? false : true;
                    musicPlayer = localStorage.getItem('setting-music-player') === 'false' ? false : true;
                    musicMode = localStorage.getItem('setting-music-mode') || 'random';
                }
                
                // 设置开关状态
                document.getElementById('setting-link-popup').checked = linkPopup;
                document.getElementById('setting-music-player').checked = musicPlayer;
                document.getElementById('setting-music-mode').value = musicMode;
                
                // 如果indexedDBManager已初始化且使用了localStorage，将设置保存到IndexedDB
                if (typeof indexedDBManager !== 'undefined' && !settings) {
                    // 直接使用读取到的值保存，而不是从DOM读取，防止因选项不存在导致设置丢失
                    await indexedDBManager.saveSettings({
                        'setting-link-popup': linkPopup,
                        'setting-music-player': musicPlayer,
                        'setting-music-mode': musicMode
                    });
                }
            } catch (error) {
                // 加载设置失败，忽略错误
                // 降级到使用localStorage
                const linkPopup = localStorage.getItem('setting-link-popup') === 'false' ? false : true;
                const musicPlayer = localStorage.getItem('setting-music-player') === 'false' ? false : true;
                const musicMode = localStorage.getItem('setting-music-mode') || 'random';
                
                // 设置开关状态
                document.getElementById('setting-link-popup').checked = linkPopup;
                document.getElementById('setting-music-player').checked = musicPlayer;
                document.getElementById('setting-music-mode').value = musicMode;
            }
        }
        
        // 保存设置
        async function saveSettings() {
            // 获取开关状态
            const linkPopup = document.getElementById('setting-link-popup').checked;
            const musicPlayer = document.getElementById('setting-music-player').checked;
            const musicMode = document.getElementById('setting-music-mode').value;
            
            try {
                // 检查indexedDBManager是否已经初始化
                if (typeof indexedDBManager !== 'undefined') {
                    try {
                        // 保存到IndexedDB
                        await indexedDBManager.saveSettings({
                            'setting-link-popup': linkPopup,
                            'setting-music-player': musicPlayer,
                            'setting-music-mode': musicMode
                        });
                        
                        // 应用设置
                        applySettings();
                    } catch (error) {
                        // IndexedDB保存失败，降级到localStorage
                        localStorage.setItem('setting-link-popup', linkPopup);
                        localStorage.setItem('setting-music-player', musicPlayer);
                        localStorage.setItem('setting-music-mode', musicMode);
                    }
                } else {
                    // indexedDBManager未初始化，使用localStorage
                    localStorage.setItem('setting-link-popup', linkPopup);
                    localStorage.setItem('setting-music-player', musicPlayer);
                    localStorage.setItem('setting-music-mode', musicMode);
                }
            } catch (error) {
                // 保存设置失败，忽略错误
                // 降级到localStorage
                localStorage.setItem('setting-link-popup', linkPopup);
                localStorage.setItem('setting-music-player', musicPlayer);
                localStorage.setItem('setting-music-mode', musicMode);
            }
        }
        
        // 字体设置相关功能
        let customFontData = null;
        
        // 现代化字体选择UI交互函数
        function selectFontUI(value) {
            // 移除所有 active 类
            document.querySelectorAll('.font-card').forEach(el => el.classList.remove('active'));
            // 添加 active 类到选中项
            const selected = document.querySelector(`.font-card[data-value="${value}"]`);
            if (selected) selected.classList.add('active');
            
            // 更新隐藏的 select
            const select = document.getElementById('font-select');
            if (select) {
                select.value = value;
                // 触发 change 事件，这将联动原有的预览和应用逻辑
                const event = new Event('change');
                select.dispatchEvent(event);
            }
        }
        
        // 打开字体设置弹窗
        function openFontSettingsModal() {
            const modal = document.getElementById('font-settings-modal');
            if (modal) {
                modal.style.display = 'flex';
                // 加载已保存的字体设置
                loadFontSettings();
            }
        }
        
        // 关闭字体设置弹窗
        function closeFontSettingsModal() {
            const modal = document.getElementById('font-settings-modal');
            if (modal) {
                modal.style.display = 'none';
            }
        }
        
        // 初始化字体设置
        async function initFontSettings() {
            // 监听字体选择变化
            const fontSelect = document.getElementById('font-select');
            if (fontSelect) {
                fontSelect.addEventListener('change', updateFontPreview);
                // 也监听change事件，实时应用到页面
                fontSelect.addEventListener('change', applyFont);
            }
            
            // 监听自定义字体文件选择
            const customFontFile = document.getElementById('custom-font-file');
            if (customFontFile) {
                customFontFile.addEventListener('change', handleCustomFontSelect);
            }
            
            // 监听加粗和斜体复选框变化，实时更新预览并应用到页面
            const fontBoldCheckbox = document.getElementById('font-bold');
            const fontItalicCheckbox = document.getElementById('font-italic');
            if (fontBoldCheckbox) {
                fontBoldCheckbox.addEventListener('change', updateFontPreview);
                fontBoldCheckbox.addEventListener('change', applyFont);
            }
            if (fontItalicCheckbox) {
                fontItalicCheckbox.addEventListener('change', updateFontPreview);
                fontItalicCheckbox.addEventListener('change', applyFont);
            }
        }
        
        // 更新字体预览
        function updateFontPreview() {
            const fontSelect = document.getElementById('font-select');
            const fontPreview = document.getElementById('font-preview');
            const customFontSection = document.getElementById('custom-font-section');
            const fontBoldCheckbox = document.getElementById('font-bold');
            const fontItalicCheckbox = document.getElementById('font-italic');
            
            // 检查所有必要元素是否存在
            if (!fontSelect || !fontPreview || !customFontSection || !fontBoldCheckbox || !fontItalicCheckbox) {
                return;
            }
            
            if (fontSelect.value === 'custom') {
                customFontSection.style.display = 'block';
            } else {
                customFontSection.style.display = 'none';
            }
            
            // 更新预览文字字体
            applyFontToElement(fontPreview, fontSelect.value);
            
            // 更新预览文字的斜体和加粗样式
            const fontWeight = fontBoldCheckbox.checked ? 'bold' : 'normal';
            const fontStyle = fontItalicCheckbox.checked ? 'italic' : 'normal';
            
            // 使用 !important 确保样式生效，覆盖可能的全局样式
            fontPreview.style.setProperty('font-weight', fontWeight, 'important');
            fontPreview.style.setProperty('font-style', fontStyle, 'important');
            
            // 确保样式也应用到 custom-font-style 如果存在
            const customFontStyle = document.getElementById('custom-font-style');
            if (customFontStyle && customFontStyle.textContent.includes('CustomFont')) {
                // 如果正在预览自定义字体，我们需要更新它的 @font-face 或者相关规则吗？
                // 不需要，因为 @font-face 不包含 weight/style 除非是特定变体。
                // 我们主要依靠元素的 inline style。
            }
        }
        
        // 处理自定义字体选择
        function handleCustomFontSelect(event) {
            const file = event.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    customFontData = {
                        name: file.name,
                        data: e.target.result,
                        type: file.type
                    };
                    document.getElementById('custom-font-name').textContent = `已选择：${file.name}`;
                    updateFontPreview();
                };
                reader.readAsDataURL(file);
            }
        }
        
        // 应用字体到指定元素
        function applyFontToElement(element, fontValue) {
            let fontFamily = '';
            
            switch (fontValue) {
                case 'default':
                    fontFamily = '"Helvetica Neue", Arial, "Microsoft YaHei", sans-serif';
                    break;
                case 'noto-sans-sc':
                    fontFamily = '"Noto Sans SC", sans-serif';
                    break;
                case 'noto-serif-sc':
                    fontFamily = '"Noto Serif SC", serif';
                    break;
                case 'custom':
                    if (customFontData) {
                        // 创建自定义字体样式
                        const fontName = 'CustomFont';
                        let style = document.getElementById('custom-font-style');
                        if (!style) {
                            style = document.createElement('style');
                            style.id = 'custom-font-style';
                            document.head.appendChild(style);
                        }
                        style.textContent = `@font-face {\n  font-family: '${fontName}';\n  src: url('${customFontData.data}');\n}`;
                        fontFamily = `'${fontName}', sans-serif`;
                    } else {
                        fontFamily = '"Helvetica Neue", Arial, "Microsoft YaHei", sans-serif';
                    }
                    break;
                default:
                    // 系统字体处理
                    if (fontValue === 'Microsoft YaHei') {
                        fontFamily = '"Microsoft YaHei", "微软雅黑", sans-serif';
                    } else if (fontValue === 'SimHei') {
                        fontFamily = 'SimHei, "黑体", sans-serif';
                    } else if (fontValue === 'SimSun') {
                        fontFamily = 'SimSun, "宋体", serif';
                    } else if (fontValue === 'KaiTi') {
                        fontFamily = 'KaiTi, "楷体", serif';
                    } else if (fontValue === 'FangSong') {
                        fontFamily = 'FangSong, "仿宋", serif';
                    } else if (fontValue === 'Microsoft JhengHei') {
                        fontFamily = '"Microsoft JhengHei", "微软正黑体", sans-serif';
                    } else if (fontValue === 'PingFang SC') {
                        fontFamily = '"PingFang SC", sans-serif';
                    } else if (fontValue === 'Heiti SC') {
                        fontFamily = '"Heiti SC", sans-serif';
                    } else if (fontValue === 'Songti SC') {
                        fontFamily = '"Songti SC", serif';
                    } else if (fontValue === 'Kaiti SC') {
                        fontFamily = '"Kaiti SC", serif';
                    } else if (fontValue === 'Courier New') {
                        fontFamily = '"Courier New", monospace';
                    } else {
                        // 其他通用字体
                        fontFamily = `"${fontValue}", sans-serif`;
                    }
            }
            
            element.style.setProperty('font-family', fontFamily, 'important');
        }
        
        // 应用字体到整个页面
        function applyFontToPage(fontValue, fontBold, fontItalic, customFontDataOverride) {
            // 如果未提供参数，尝试从存储加载
            if (fontValue === undefined) {
                fontValue = localStorage.getItem('setting-font') || 'default';
                fontBold = localStorage.getItem('setting-font-bold') === 'true';
                fontItalic = localStorage.getItem('setting-font-italic') === 'true';
                
                // 尝试获取自定义字体数据
                const customFontDataStr = localStorage.getItem('setting-custom-font');
                if (customFontDataStr) {
                    try {
                        customFontData = JSON.parse(customFontDataStr);
                    } catch (e) {
                        console.error('解析自定义字体数据失败', e);
                    }
                }
            } else {
                // 如果提供了参数，同时也更新全局变量 customFontData（如果是自定义字体）
                if (customFontDataOverride) {
                    customFontData = customFontDataOverride;
                }
            }
            
            // 再次检查 customFontData
            if (!customFontData) {
                 const customFontDataStr = localStorage.getItem('setting-custom-font');
                 if (customFontDataStr) {
                    try {
                        customFontData = JSON.parse(customFontDataStr);
                    } catch (e) {}
                 }
            }
            
            // 创建或更新字体样式
            let style = document.getElementById('page-font-style');
            if (!style) {
                style = document.createElement('style');
                style.id = 'page-font-style';
                document.head.appendChild(style);
            }
            
            let fontFamily = '';
            const fontWeight = fontBold ? 'bold' : 'normal';
            const fontStyle = fontItalic ? 'italic' : 'normal';
            
            switch (fontValue) {
                case 'default':
                    fontFamily = '"Helvetica Neue", Arial, "Microsoft YaHei", sans-serif';
                    break;
                case 'noto-sans-sc':
                    fontFamily = '"Noto Sans SC", sans-serif';
                    break;
                case 'noto-serif-sc':
                    fontFamily = '"Noto Serif SC", serif';
                    break;
                case 'custom':
                    if (customFontData) {
                        const fontName = 'CustomFont';
                        style.textContent = `@font-face {\n  font-family: '${fontName}';\n  src: url('${customFontData.data}');\n}\n* {\n  font-family: '${fontName}', sans-serif !important;\n  font-weight: ${fontWeight} !important;\n  font-style: ${fontStyle} !important;\n}`;
                        return;
                    }
                    // 如果自定义字体数据不存在，使用默认字体
                    fontFamily = '"Helvetica Neue", Arial, "Microsoft YaHei", sans-serif';
                    break;
                default:
                    // 系统字体处理
                    if (fontValue === 'Microsoft YaHei') {
                        fontFamily = '"Microsoft YaHei", "微软雅黑", sans-serif';
                    } else if (fontValue === 'SimHei') {
                        fontFamily = 'SimHei, "黑体", sans-serif';
                    } else if (fontValue === 'SimSun') {
                        fontFamily = 'SimSun, "宋体", serif';
                    } else if (fontValue === 'KaiTi') {
                        fontFamily = 'KaiTi, "楷体", serif';
                    } else if (fontValue === 'FangSong') {
                        fontFamily = 'FangSong, "仿宋", serif';
                    } else if (fontValue === 'Microsoft JhengHei') {
                        fontFamily = '"Microsoft JhengHei", "微软正黑体", sans-serif';
                    } else if (fontValue === 'PingFang SC') {
                        fontFamily = '"PingFang SC", sans-serif';
                    } else if (fontValue === 'Heiti SC') {
                        fontFamily = '"Heiti SC", sans-serif';
                    } else if (fontValue === 'Songti SC') {
                        fontFamily = '"Songti SC", serif';
                    } else if (fontValue === 'Kaiti SC') {
                        fontFamily = '"Kaiti SC", serif';
                    } else if (fontValue === 'Courier New') {
                        fontFamily = '"Courier New", monospace';
                    } else {
                        // 其他通用字体
                        fontFamily = `"${fontValue}", sans-serif`;
                    }
            }
            
            // 应用到所有元素
            style.textContent = `* {\n  font-family: ${fontFamily} !important;\n  font-weight: ${fontWeight} !important;\n  font-style: ${fontStyle} !important;\n}`;
        }
        
        // 加载字体设置
        async function loadFontSettings() {
            try {
                let settings;
                let fontValue = 'default';
                let fontBold = false;
                let fontItalic = false;
                let customFontDataStr = null;
                
                // 检查indexedDBManager是否已经初始化
                if (typeof indexedDBManager !== 'undefined') {
                    try {
                        // 从IndexedDB加载设置
                        settings = await indexedDBManager.getSettings();
                        fontValue = settings['setting-font'] || 'default';
                        fontBold = settings['setting-font-bold'] || false;
                        fontItalic = settings['setting-font-italic'] || false;
                        customFontDataStr = settings['setting-custom-font'];
                    } catch (error) {
                        // IndexedDB加载失败，使用localStorage
                        fontValue = localStorage.getItem('setting-font') || 'default';
                        fontBold = localStorage.getItem('setting-font-bold') === 'true';
                        fontItalic = localStorage.getItem('setting-font-italic') === 'true';
                        customFontDataStr = localStorage.getItem('setting-custom-font');
                    }
                } else {
                    // indexedDBManager未初始化，使用localStorage
                    fontValue = localStorage.getItem('setting-font') || 'default';
                    fontBold = localStorage.getItem('setting-font-bold') === 'true';
                    fontItalic = localStorage.getItem('setting-font-italic') === 'true';
                    customFontDataStr = localStorage.getItem('setting-custom-font');
                }
                
                // 设置字体选择器
                const fontSelect = document.getElementById('font-select');
                if (fontSelect) {
                    fontSelect.value = fontValue;
                    
                    // 同步更新现代化UI的高亮状态
                    document.querySelectorAll('.font-card').forEach(el => el.classList.remove('active'));
                    const activeCard = document.querySelector(`.font-card[data-value="${fontValue}"]`);
                    if (activeCard) {
                        activeCard.classList.add('active');
                        // 确保选中项在可视区域内
                        setTimeout(() => {
                            activeCard.scrollIntoView({ behavior: 'auto', block: 'nearest' });
                        }, 100);
                    }
                }
                
                // 设置斜体和加粗复选框
                const fontBoldCheckbox = document.getElementById('font-bold');
                const fontItalicCheckbox = document.getElementById('font-italic');
                if (fontBoldCheckbox) {
                    fontBoldCheckbox.checked = fontBold;
                }
                if (fontItalicCheckbox) {
                    fontItalicCheckbox.checked = fontItalic;
                }
                
                // 加载自定义字体数据
                if (customFontDataStr) {
                    customFontData = JSON.parse(customFontDataStr);
                    const customFontName = document.getElementById('custom-font-name');
                    if (customFontName) {
                        customFontName.textContent = `已选择：${customFontData.name}`;
                    }
                }
                
                // 更新预览
                updateFontPreview();
            } catch (error) {
                // 加载字体设置失败，忽略错误
            }
        }
        
        // 应用字体设置
        function applyFont() {
            const fontSelect = document.getElementById('font-select');
            const fontBoldCheckbox = document.getElementById('font-bold');
            const fontItalicCheckbox = document.getElementById('font-italic');
            
            // 检查所有必要元素是否存在
            if (!fontSelect || !fontBoldCheckbox || !fontItalicCheckbox) {
                return;
            }
            
            const fontValue = fontSelect.value;
            const fontBold = fontBoldCheckbox.checked;
            const fontItalic = fontItalicCheckbox.checked;
            
            // 保存字体设置
            saveFontSettings(fontValue, fontBold, fontItalic);
            
            // 应用字体到页面 (直接传递参数，不依赖尚未完成的存储)
            applyFontToPage(fontValue, fontBold, fontItalic, (fontValue === 'custom' ? customFontData : null));
            
            // 显示通知
            showNotification('字体设置已应用', 'success');
        }
        
        // 保存字体设置
        async function saveFontSettings(fontValue, fontBold = false, fontItalic = false) {
            try {
                const settingsToSave = {
                    'setting-font': fontValue,
                    'setting-font-bold': fontBold,
                    'setting-font-italic': fontItalic
                };
                
                // 如果是自定义字体，保存字体数据
                if (fontValue === 'custom' && customFontData) {
                    settingsToSave['setting-custom-font'] = JSON.stringify(customFontData);
                }
                
                // 检查indexedDBManager是否已经初始化
                if (typeof indexedDBManager !== 'undefined') {
                    try {
                        // 保存到IndexedDB
                        await indexedDBManager.saveSettings(settingsToSave);
                    } catch (error) {
                        console.error('IndexedDB保存失败', error);
                    }
                }
                
                // 始终保存到localStorage作为备份，以便同步读取（如页面加载时）
                localStorage.setItem('setting-font', fontValue);
                localStorage.setItem('setting-font-bold', fontBold);
                localStorage.setItem('setting-font-italic', fontItalic);
                if (fontValue === 'custom' && customFontData) {
                    localStorage.setItem('setting-custom-font', JSON.stringify(customFontData));
                } else {
                    localStorage.removeItem('setting-custom-font');
                }
            } catch (error) {
                // 保存字体设置失败，忽略错误
            }
        }
        
        // 重置字体设置
        function resetFont() {
            // 重置字体选择器
            const fontSelect = document.getElementById('font-select');
            if (fontSelect) {
                fontSelect.value = 'default';
            }
            
            // 重置斜体和加粗复选框
            const fontBoldCheckbox = document.getElementById('font-bold');
            const fontItalicCheckbox = document.getElementById('font-italic');
            if (fontBoldCheckbox) {
                fontBoldCheckbox.checked = false;
            }
            if (fontItalicCheckbox) {
                fontItalicCheckbox.checked = false;
            }
            
            // 重置自定义字体数据
            customFontData = null;
            const customFontName = document.getElementById('custom-font-name');
            if (customFontName) {
                customFontName.textContent = '';
            }
            
            // 更新预览
            updateFontPreview();
            
            // 保存重置后的设置
            saveFontSettings('default', false, false);
            
            // 应用重置后的字体
            applyFontToPage('default', false, false, null);
            
            // 显示通知
            showNotification('字体设置已重置', 'success');
        }
        
        // 页面加载时应用字体设置
        document.addEventListener('DOMContentLoaded', function() {
            // 初始化字体设置
            initFontSettings();
            // 应用字体到页面
            applyFontToPage();
        });
        
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
                    // 清除文件缓存失败，忽略错误
                    showNotification('清除文件缓存失败，请稍后重试', 'error');
                }
            }
        }
        
        // 应用设置
        async function applySettings() {
            try {
                let settings;
                let musicMode = 'random'; // 默认值
                let musicPlayerSetting = false; // 默认关闭
                
                // 检查indexedDBManager是否已经初始化
                if (typeof indexedDBManager !== 'undefined') {
                    try {
                        // 从IndexedDB获取设置
                        settings = await indexedDBManager.getSettings();
                        musicMode = settings['setting-music-mode'] || 'random';
                        // 如果未设置过，默认关闭
                        musicPlayerSetting = settings['setting-music-player'] === true;
                    } catch (error) {
                        // IndexedDB加载失败，使用localStorage
                        musicMode = localStorage.getItem('setting-music-mode') || 'random';
                        // 如果未设置过，默认关闭
                        musicPlayerSetting = localStorage.getItem('setting-music-player') === 'true';
                    }
                } else {
                    // indexedDBManager未初始化，使用localStorage
                    musicMode = localStorage.getItem('setting-music-mode') || 'random';
                    // 如果未设置过，默认关闭
                    musicPlayerSetting = localStorage.getItem('setting-music-player') === 'true';
                }
                
                // 处理音乐播放器开关状态
                const player = document.getElementById('music-player');
                const audioPlayer = document.getElementById('audio-player');
                const musicIcon = document.getElementById('music-icon');
                
                if (player && audioPlayer) {
                    if (!musicPlayerSetting) {
                        // 音乐播放器设置为关闭，暂停音乐并隐藏播放器
                        audioPlayer.pause();
                        player.style.display = 'none';
                        // 更新音乐图标为关闭状态
                        if (musicIcon) {
                            musicIcon.innerHTML = '🎵<span style="color: red; font-size: 12px; position: absolute; top: 5px; right: 5px;">✕</span>';
                            musicIcon.style.position = 'relative';
                        }
                    } else {
                        // 更新音乐图标为正常状态
                        if (musicIcon) {
                            musicIcon.innerHTML = '🎵';
                        }
                    }
                }
                
                // 只在currentMusicMode和loadNewSong都存在时才执行音乐模式切换
                if (typeof currentMusicMode !== 'undefined' && typeof loadNewSong !== 'undefined') {
                    // 如果音乐模式改变，立即刷新歌曲
                    if (currentMusicMode !== musicMode && musicPlayerSetting) {
                        currentMusicMode = musicMode;
                        // 立即刷新歌曲
                        await loadNewSong();
                    }
                }
            } catch (error) {
                // 忽略错误，不向控制台报错
            }
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
            
            // 解析缓存信息
            const cacheInfo = await parseCacheCookies();
            
            // 生成统计HTML
            let statsHtml = `
                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px;">
                    <div style="background: #f0f8ff; padding: 10px 15px; border-radius: 6px; border-left: 3px solid #12b7f5;">
                        <h3 style="margin: 0 0 6px 0; color: #12b7f5; font-size: 14px; font-weight: 600;">音频文件</h3>
                        <p style="margin: 0; font-size: 20px; font-weight: 600; color: #333;">${cacheInfo.audio.count}</p>
                        <p style="margin: 3px 0 0 0; font-size: 12px; color: #666;">总大小: ${formatFileSize(cacheInfo.audio.size)}</p>
                    </div>
                    
                    <div style="background: #f0fff4; padding: 10px 15px; border-radius: 6px; border-left: 3px solid #52c41a;">
                        <h3 style="margin: 0 0 6px 0; color: #52c41a; font-size: 14px; font-weight: 600;">视频文件</h3>
                        <p style="margin: 0; font-size: 20px; font-weight: 600; color: #333;">${cacheInfo.video.count}</p>
                        <p style="margin: 3px 0 0 0; font-size: 12px; color: #666;">总大小: ${formatFileSize(cacheInfo.video.size)}</p>
                    </div>
                    
                    <div style="background: #fffbe6; padding: 10px 15px; border-radius: 6px; border-left: 3px solid #faad14;">
                        <h3 style="margin: 0 0 6px 0; color: #faad14; font-size: 14px; font-weight: 600;">图片文件</h3>
                        <p style="margin: 0; font-size: 20px; font-weight: 600; color: #333;">${cacheInfo.image.count}</p>
                        <p style="margin: 3px 0 0 0; font-size: 12px; color: #666;">总大小: ${formatFileSize(cacheInfo.image.size)}</p>
                    </div>
                    
                    <div style="background: #fff2f0; padding: 10px 15px; border-radius: 6px; border-left: 3px solid #ff4d4f;">
                        <h3 style="margin: 0 0 6px 0; color: #ff4d4f; font-size: 14px; font-weight: 600;">其他文件</h3>
                        <p style="margin: 0; font-size: 20px; font-weight: 600; color: #333;">${cacheInfo.file.count}</p>
                        <p style="margin: 3px 0 0 0; font-size: 12px; color: #666;">总大小: ${formatFileSize(cacheInfo.file.size)}</p>
                    </div>
                </div>
                <div style="margin-top: 15px; padding: 12px; background: #fafafa; border-radius: 6px; text-align: center;">
                    <h3 style="margin: 0 0 6px 0; color: #333; font-size: 16px; font-weight: 600;">总计</h3>
                    <p style="margin: 0; font-size: 24px; font-weight: 600; color: #333;">${cacheInfo.total.count}</p>
                    <p style="margin: 3px 0 0 0; font-size: 14px; color: #666;">总大小: ${formatFileSize(cacheInfo.total.size)}</p>
                </div>
            `;
            
            statsContainer.innerHTML = statsHtml;
        }
        
        // 解析缓存信息（同时考虑localStorage和cookie）
        // 异步解析缓存信息（支持IndexedDB和旧版cookie）
        function parseCacheCookies() {
            return new Promise((resolve, reject) => {
                const cacheInfo = {
                    audio: { count: 0, size: 0 },
                    video: { count: 0, size: 0 },
                    image: { count: 0, size: 0 },
                    file: { count: 0, size: 0 },
                    total: { count: 0, size: 0 }
                };
                
                // 只使用IndexedDBManager获取缓存统计信息，不再处理localStorage和cookie中的旧数据
                // 这样可以避免重复计算和统计错误
                indexedDBManager.getCacheStats()
                    .then(stats => {
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
                        
                        resolve(cacheInfo);
                    })
                    .catch(error => {
                        // 获取缓存统计失败，忽略错误
                        // 如果IndexedDB获取失败，返回空统计信息
                        resolve(cacheInfo);
                    });
            });
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
            // 使用IndexedDBManager清除所有缓存
            try {
                await indexedDBManager.clearAllCache();
            } catch (e) {
                // 清除缓存失败，忽略错误
            }
            
            // 清除所有缓存相关的cookie
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
        
        // 文件上传
        const fileInputBtn = document.getElementById('file-input-btn');
        const fileInput = document.getElementById('file-input');
        
        if (fileInputBtn) {
            fileInputBtn.addEventListener('click', function() {
                if (fileInput) {
                    fileInput.click();
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
        const sendBtn = document.getElementById('send-btn');
        if (sendBtn) {
            sendBtn.addEventListener('click', sendMessage);
        }
        
        // 回车键发送消息
        const messageInput = document.getElementById('message-input');
        if (messageInput) {
            messageInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    sendMessage();
                }
            });
        }
        
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
                background: rgba(0, 0, 0, 0.5);
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
                width: 80%;
                height: 80%;
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
            `;
            
            // 创建弹窗头部
            const popupHeader = document.createElement('div');
            popupHeader.style.cssText = `
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 12px 16px;
                background: #1976d2; /* 普遍的蓝色 */
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
            iframe.style.cssText = `
                flex: 1;
                border: none;
                width: 100%;
                height: calc(100% - 48px); /* 减去头部高度 */
                min-height: 0;
                cursor: default;
                background: white;
            `;
            
            // 组装弹窗
            popupContent.appendChild(popupHeader);
            popupContent.appendChild(iframe);
            popup.appendChild(popupContent);
            
            // 添加到页面
            document.body.appendChild(popup);
            
            // 默认显示加载中
            iframe.srcdoc = `
                <html>
                <body style="margin:0;display:flex;align-items:center;justify-content:center;height:100%;font-family:sans-serif;color:#666;">
                    <div style="text-align:center;">
                        <div style="margin-bottom:10px;">正在加载预览...</div>
                        <div style="font-size:12px;color:#999;">正在检测目标网站安全策略</div>
                    </div>
                </body>
                </html>
            `;
            
            // 获取标题并检测是否可嵌入
            fetch('get_url_title.php?url=' + encodeURIComponent(url))
                .then(response => response.json())
                .then(data => {
                    if (data) {
                        if (data.title) {
                            popupTitle.textContent = data.title;
                            popupTitle.title = url;
                        }
                        
                        if (data.embeddable === false) {
                            // 不允许嵌入，显示提示
                            iframe.removeAttribute('src');
                            iframe.srcdoc = `
                                <html>
                                <body style="margin:0;display:flex;align-items:center;justify-content:center;height:100%;font-family:sans-serif;background:#f9f9f9;">
                                    <div style="text-align:center;padding:20px;">
                                        <div style="font-size:48px;margin-bottom:20px;">🚫</div>
                                        <h3 style="color:#333;margin-bottom:10px;">无法在预览中打开此网站</h3>
                                        <p style="color:#666;margin-bottom:25px;font-size:14px;line-height:1.5;">
                                            目标网站 (${new URL(url).hostname}) 设置了安全策略，<br>禁止在第三方网页中嵌入显示。
                                        </p>
                                        <a href="${url}" target="_blank" style="
                                            display:inline-block;
                                            padding:10px 25px;
                                            background:#0095ff;
                                            color:white;
                                            text-decoration:none;
                                            border-radius:6px;
                                            font-size:14px;
                                            font-weight:500;
                                            transition:background 0.2s;
                                        ">在新窗口打开</a>
                                    </div>
                                </body>
                                </html>
                            `;
                        } else {
                            // 允许嵌入，加载 URL
                            iframe.removeAttribute('srcdoc');
                            iframe.src = url;
                        }
                    } else {
                        // 接口异常，尝试直接加载
                        iframe.removeAttribute('srcdoc');
                        iframe.src = url;
                    }
                })
                .catch(e => {
                    console.log('Failed to fetch info', e);
                    // 出错也尝试直接加载
                    iframe.removeAttribute('srcdoc');
                    iframe.src = url;
                });
            
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
        const feedbackForm = document.getElementById('feedback-form');
        if (feedbackForm) {
            feedbackForm.addEventListener('submit', async (e) => {
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
        }
        
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
        

        
        // 点击其他区域关闭消息操作菜单
        document.addEventListener('click', function(event) {
            if (!event.target.closest('.message-actions')) {
                document.querySelectorAll('.message-action-menu').forEach(menu => {
                    menu.style.display = 'none';
                });
            }
        });
        
        // 撤回消息功能
        // 撤回消息功能 - 全局函数
        window.recallMessage = function(button, messageId, chatType, chatId) {
            console.log('调用撤回消息函数:', {button, messageId, chatType, chatId});
            // 获取消息元素
            const messageElement = button.closest('.message');
            if (!messageElement) {
                console.error('未找到消息元素');
                showNotification('未找到消息元素', 'error');
                return;
            }
            const messageTime = parseInt(messageElement.dataset.messageTime);
            const now = Date.now();
            const timeDiff = (now - messageTime) / 1000 / 60; // 转换为分钟
            
            // 检查是否在2分钟内
            if (timeDiff > 2) {
                showNotification('消息已超过2分钟，无法撤回', 'error');
                return;
            }
            
            // 从消息元素的data-message-id属性获取真实的消息ID，而不是使用传入的临时ID
            const realMessageId = messageElement.dataset.messageId;
            const realChatType = messageElement.dataset.chatType;
            const realChatId = messageElement.dataset.chatId;
            
            console.log('真实消息ID:', realMessageId, '真实聊天类型:', realChatType, '真实聊天ID:', realChatId);
            
            // 发送撤回请求到服务器
            const formData = new URLSearchParams();
            formData.append('action', 'recall');
            formData.append('message_id', realMessageId);
            formData.append('chat_type', realChatType);
            if (realChatType === 'friend') {
                formData.append('friend_id', realChatId);
            } else {
                formData.append('id', realChatId);
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
                    messageElement.innerHTML = `
                        <div class='message-content'>
                            <div class='message-text' style='color: #999; font-style: italic;'>[消息已撤回]</div>
                        </div>
                        <div class='message-avatar'>
                            <?php if (!empty($current_user['avatar'])): ?>
                                <img src='<?php echo $current_user['avatar']; ?>' alt='<?php echo $username; ?>' style='width: 100%; height: 100%; border-radius: 50%; object-fit: cover;'>
                            <?php else: ?>
                                <?php echo substr($username, 0, 2); ?>
                            <?php endif; ?>
                        </div>
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
            
            // 从IndexedDB获取所有设置
            getSettings() {
                return new Promise((resolve, reject) => {
                    this.openDB().then(db => {
                        const transaction = db.transaction([this.stores.settings], 'readonly');
                        const objectStore = transaction.objectStore(this.stores.settings);
                        const settings = {};
                        
                        objectStore.openCursor().onsuccess = (event) => {
                            const cursor = event.target.result;
                            if (cursor) {
                                settings[cursor.value.key] = cursor.value.value;
                                cursor.continue();
                            } else {
                                resolve(settings);
                            }
                        };
                    }).catch(error => {
                        reject(error);
                    });
                });
            }
            
            // 保存多个设置到IndexedDB
            saveSettings(settings) {
                return new Promise((resolve, reject) => {
                    this.openDB().then(db => {
                        const transaction = db.transaction([this.stores.settings], 'readwrite');
                        const objectStore = transaction.objectStore(this.stores.settings);
                        
                        const savePromises = [];
                        
                        for (const [key, value] of Object.entries(settings)) {
                            savePromises.push(new Promise((resolveSave, rejectSave) => {
                                const settingData = {
                                    key: key,
                                    value: value,
                                    timestamp: new Date().toISOString()
                                };
                                
                                const request = objectStore.put(settingData);
                                request.onerror = () => rejectSave(`保存设置 ${key} 失败`);
                                request.onsuccess = () => resolveSave(true);
                            }));
                        }
                        
                        Promise.all(savePromises).then(() => {
                            resolve(true);
                        }).catch(error => {
                            reject(error);
                        });
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
                // localStorage迁移失败，忽略错误
            });
        });
        
        // 保存文件到IndexedDB (兼容旧函数调用)
        function saveFileToIndexedDB(fileData) {
            return indexedDBManager.saveFile(fileData);
        }
        
        // 从IndexedDB获取文件 (兼容旧函数调用)
        function getFileFromIndexedDB(fileId) {
            return indexedDBManager.getFile(fileId);
        }
        
        // 从IndexedDB删除文件 (兼容旧函数调用)
        function deleteFileFromIndexedDB(fileId) {
            return indexedDBManager.deleteFile(fileId);
        }
        
        // 获取所有文件 (兼容旧函数调用)
        function getAllFilesFromIndexedDB() {
            return indexedDBManager.getAllFiles();
        }
        
        // 清空IndexedDB文件存储
        function clearFilesFromIndexedDB() {
            return new Promise((resolve, reject) => {
                indexedDBManager.clearAllCache().then(() => {
                    resolve(true);
                }).catch(error => {
                    reject('清空文件存储失败: ' + error.message);
                });
            });
        }
        
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
        async function cleanupOldFiles(requiredSpace) {
            try {
                // 获取所有文件
                const allFiles = await indexedDBManager.getAllFiles();
                if (allFiles.length === 0) {
                    return false;
                }
                
                // 按上传时间排序，最旧的文件排在前面
                const sortedFiles = allFiles.sort((a, b) => new Date(a.uploadedAt) - new Date(b.uploadedAt));
                
                // 计算需要清理的空间
                let cleanedSize = 0;
                const filesToRemove = [];
                
                // 从最旧的文件开始清理，直到有足够空间
                for (const file of sortedFiles) {
                    if (cleanedSize >= requiredSpace) {
                        break;
                    }
                    
                    filesToRemove.push(file.id);
                    cleanedSize += file.size;
                }
                
                // 执行清理
                for (const fileId of filesToRemove) {
                    await indexedDBManager.deleteFile(fileId);
                }
                
                return true;
            } catch (error) {
                console.error('清理旧文件失败:', error);
                return false;
            }
        }
        
        // 发送文件函数
        // IndexedDB文件存储管理
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
                        // 从IndexedDB获取文件失败，忽略错误
                        resolve(null);
                    });
            });
        }
        
        function sendFile(file) {
            const chatType = '<?php echo $chat_type; ?>';
            const chatId = '<?php echo $selected_id; ?>';
            
            if (!chatId) {
                showNotification('请先选择聊天对象', 'error');
                return;
            }
            
            // 检查文件大小是否超过限制
            const uploadMaxConfig = <?php echo getConfig('upload_files_max', 150); ?>; // 默认50MB
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
                            <div id='upload-progress-bar' style='width: 0%; height: 100%; background: linear-gradient(90deg, #667eea 0%, #0095ff 100%); transition: width 0.3s ease; border-radius: 4px;'></div>
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
            
            // 模拟进度更新
            let progress = 0;
            const progressInterval = setInterval(() => {
                progress += 10;
                if (progress > 90) progress = 90;
                progressBar.style.width = `${progress}%`;
                percentageText.textContent = `${progress}%`;
            }, 100);
            
            // 保存文件到IndexedDB
            saveFileToCache(file)
                .then(async (fileId) => {
                    clearInterval(progressInterval);
                    // 不要在上传未完成时就显示100%
                    // progressBar.style.width = '100%';
                    // percentageText.textContent = '100%';
                    
                    // 准备消息数据
                    const messageData = {
                        id: 'msg_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9),
                        sender_id: '<?php echo $user_id; ?>',
                        content: '',
                        file_path: fileId, // 使用localStorage文件ID作为路径
                        file_name: file.name,
                        file_size: file.size,
                        file_type: file.type,
                        type: 'file',
                        created_at: new Date().toISOString(),
                        status: 'sent',
                        sender_name: '<?php echo $username; ?>',
                        sender_avatar: '<?php echo !empty($current_user['avatar']) ? $current_user['avatar'] : ''; ?>'
                    };
                    
                    // 注意：不要立即移除uploadingMessage和添加messageElement
                    // 等到服务器响应成功后再做这些操作
                    
                    // 发送消息到服务器（同时发送文件内容和元数据）
                    try {
                        const formData = new FormData();
                        formData.append('chat_type', chatType);
                        formData.append('content', '');
                        formData.append('file_path', fileId);
                        formData.append('file_name', file.name);
                        formData.append('file_size', file.size);
                        formData.append('file_type', file.type);
                        // 同时上传实际文件内容
                        formData.append('file', file);
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
                                // 保存到本地已完成，现在是上传到服务器
                                // 进度条从 0% 开始计算（或者保留之前的进度）
                                const uploadProgress = (e.loaded / e.total) * 100;
                                progressBar.style.width = `${uploadProgress}%`;
                                percentageText.textContent = `${Math.round(uploadProgress)}%`;
                                
                                // 计算上传速度
                                const now = Date.now();
                                const elapsed = (now - startTime) / 1000;
                                if (elapsed > 0) {
                                    const speed = e.loaded / elapsed;
                                    speedText.textContent = `${(speed / 1024).toFixed(0)} KB/s`;
                                }
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
                                    if (data.success) {
                                        // 更新消息ID
                                        messageData.id = data.message_id;
                                        
                                        // 创建最终的消息元素
                                        const messageElement = createMessageElement(messageData, chatType, '<?php echo $selected_id; ?>');
                                        
                                        // 移除上传进度消息
                                        if (uploadingMessage.parentNode) {
                                            messagesContainer.removeChild(uploadingMessage);
                                        }
                                        
                                        // 添加最终消息
                                        messagesContainer.appendChild(messageElement);
                                        messagesContainer.scrollTop = messagesContainer.scrollHeight;
                                        
                                        // 初始化新添加的音频播放器
                                        initAudioPlayers();
                                        
                                        // 更新消息中所有媒体元素的file_path
                                        // 注意：createMessageElement已经使用了messageData中的file_path (fileId)
                                    } else {
                                        showNotification('消息发送失败: ' + (data.message || '未知错误'), 'error');
                                        // 显示失败状态
                                        if (percentageText) percentageText.textContent = '失败';
                                        if (progressBar) progressBar.style.backgroundColor = '#ff4d4f';
                                    }
                                } catch (e) {
                                    console.error('解析响应失败:', e);
                                    showNotification('消息发送失败: 服务器响应错误', 'error');
                                }
                            } else {
                                // 请求失败
                                console.error('消息发送到服务器失败:', xhr.statusText);
                                showNotification('消息发送失败: 网络错误', 'error');
                            }
                        };
                        
                        xhr.onerror = function() {
                            console.error('消息发送到服务器失败: 网络错误');
                            showNotification('消息发送失败: 网络错误', 'error');
                        };
                        
                        xhr.send(formData);
                    } catch (error) {
                        console.error('消息发送到服务器失败:', error);
                        showNotification('消息发送失败: ' + error.message, 'error');
                    }
                    
                    // 重置文件输入
                    const fileInput = document.getElementById('file-input');
                    if (fileInput) {
                        fileInput.value = '';
                    }
                })
                .catch((error) => {
                    clearInterval(progressInterval);
                    messagesContainer.removeChild(uploadingMessage);
                    
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
        }
        
        // 点歌功能相关变量
let currentSongSearch = {
    songName: '',
    page: 1,
    results: []
};

// 处理点歌请求
function handleMusicRequest(action, groupId, message, page = 1, songName = '', choice = '') {
    fetch('handle_music_request.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            action: action,
            group_id: groupId,
            message: message,
            page: page,
            song_name: songName,
            choice: choice
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const messagesContainer = document.getElementById('messages-container');
            const chatType = 'group';
            const chatId = groupId;
            
            if (data.action === 'search_song') {
                // 更新当前搜索状态
                currentSongSearch = {
                    songName: data.song_name,
                    page: data.page,
                    results: data.results
                };
                
                // 显示搜索结果
                const resultMessage = document.createElement('div');
                resultMessage.className = 'message received';
                resultMessage.dataset.chatType = chatType;
                resultMessage.dataset.chatId = chatId;
                
                let resultText = `点歌搜索结果 - ${data.song_name}<br><br>`;
                data.results.forEach(item => {
                    resultText += `${item.index}. ${item.song} - ${item.singer}<br>`;
                });
                resultText += '<br>回复歌曲编号选择，输入"下一页"查看更多';
                
                resultMessage.innerHTML = `
                    <div class='message-avatar'>
                        <span style='font-size: 24px;'>🎵</span>
                    </div>
                    <div class='message-content'>
                        <div class='message-text'>${resultText}</div>
                        <div class='message-time'>${new Date().toLocaleString('zh-CN')}</div>
                    </div>
                `;
                messagesContainer.appendChild(resultMessage);
            } else if (data.action === 'next_page') {
                // 更新当前搜索状态
                currentSongSearch = {
                    songName: data.song_name,
                    page: data.page,
                    results: data.results
                };
                
                // 显示下一页结果
                const resultMessage = document.createElement('div');
                resultMessage.className = 'message received';
                resultMessage.dataset.chatType = chatType;
                resultMessage.dataset.chatId = chatId;
                
                let resultText = `点歌搜索结果 - ${data.song_name} (第${data.page}页)<br><br>`;
                data.results.forEach(item => {
                    resultText += `${item.index}. ${item.song} - ${item.singer}<br>`;
                });
                resultText += '<br>回复歌曲编号选择，输入"下一页"查看更多';
                
                resultMessage.innerHTML = `
                    <div class='message-avatar'>
                        <span style='font-size: 24px;'>🎵</span>
                    </div>
                    <div class='message-content'>
                        <div class='message-text'>${resultText}</div>
                        <div class='message-time'>${new Date().toLocaleString('zh-CN')}</div>
                    </div>
                `;
                messagesContainer.appendChild(resultMessage);
            } else if (data.action === 'select_song') {
                // 显示选择结果
                const resultMessage = document.createElement('div');
                resultMessage.className = 'message received';
                resultMessage.dataset.chatType = chatType;
                resultMessage.dataset.chatId = chatId;
                
                resultMessage.innerHTML = `
                    <div class='message-avatar'>
                        <span style='font-size: 24px;'>🎵</span>
                    </div>
                    <div class='message-content'>
                        <div class='message-text'>${data.message}</div>
                        <div class='message-time'>${new Date().toLocaleString('zh-CN')}</div>
                    </div>
                `;
                messagesContainer.appendChild(resultMessage);
            }
            
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        } else {
            showNotification(data.message || '点歌失败', 'error');
        }
    })
    .catch(error => {
        console.error('点歌请求失败:', error);
        showNotification('点歌请求失败，请稍后重试', 'error');
    });
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
                const timeHtml = `<div class='message-time'>${formattedTime}</div>`;
                
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
                        ${timeHtml}
                        <div class='message-actions' style='position: relative;'>
                            <button class='message-action-btn' onclick='toggleMessageActions(this)' style='width: 28px; height: 28px; font-size: 18px; background: rgba(0,0,0,0.1); border: none; border-radius: 50%; color: #333; cursor: pointer; display: flex; align-items: center; justify-content: center; opacity: 1; transition: all 0.2s ease;'>...</button>
                            <div class='message-action-menu' style='display: none; position: absolute; top: 100%; right: 0; background: white; border-radius: 8px; box-shadow: 0 2px 12px rgba(0,0,0,0.15); padding: 8px 0; z-index: 5000; min-width: 100px;'>
                                <button class='message-action-item' onclick='recallMessage(this, "${tempMessageId}", "${chatType}", "${chatId}")' style='width: 100%; text-align: left; padding: 8px 16px; border: none; background: transparent; cursor: pointer; transition: all 0.2s ease; color: #333;'>撤回消息</button>
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
                
                // 处理点歌命令
                if (chatType === 'group') {
                    // 检查是否是点歌命令
                    if (message.startsWith('点歌：')) {
                        const songName = message.substring(3).trim();
                        if (songName) {
                            handleMusicRequest('search_song', chatId, message, 1, songName, '');
                        }
                    } else if (message === '下一页') {
                        handleMusicRequest('next_page', chatId, message, currentSongSearch.page + 1, currentSongSearch.songName, '');
                    } else if (!isNaN(message) && parseInt(message) > 0) {
                        // 检查是否是选择歌曲的编号
                        handleMusicRequest('select_song', chatId, message, currentSongSearch.page, currentSongSearch.songName, message);
                    }
                }
                
                input.value = '';
            }
        }
        
        // 接受好友请求
        function acceptFriendRequest(requestId, userId, username) {
            console.log('Accepting request:', requestId, userId, username); // Debug log
            
            // 发送接受好友请求到服务器
            const formData = new FormData();
            formData.append('request_id', requestId);
            
            // 确保URL中也包含参数，以防万一
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
            if (!confirm(`确定要拒绝 ${username} 的好友请求吗？`)) {
                return;
            }
            
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
                    showNotification(`操作失败: ${data.message}`, 'error');
                }
            })
            .catch(error => {
                console.error('拒绝好友请求失败:', error);
                showNotification('操作失败，请检查网络连接', 'error');
            });
        }

        // 好友申请相关函数
        function showFriendRequests() {
            document.getElementById('friend-requests-modal').style.display = 'flex';
        }
        
        function closeFriendRequestsModal() {
            document.getElementById('friend-requests-modal').style.display = 'none';
        }
        
        // 图片查看器功能
        let currentZoom = 1;
        let isDragging = false;
        let startX, startY, initialTranslateX, initialTranslateY;
        let translateX = 0;
        let translateY = 0;
        
        // 打开图片查看器
        function openImageViewer(imageSrc) {
            const imageViewer = document.getElementById('image-viewer');
            const imageViewerImage = document.getElementById('image-viewer-image');
            const zoomLevel = document.getElementById('zoom-level');
            
            imageViewerImage.src = imageSrc;
            imageViewer.classList.add('active');
            
            // 重置状态
            currentZoom = 1;
            translateX = 0;
            translateY = 0;
            updateZoomDisplay();
            updateImageTransform();
            
            // 阻止页面滚动
            document.body.style.overflow = 'hidden';
        }
        
        // 关闭图片查看器
        function closeImageViewer() {
            const imageViewer = document.getElementById('image-viewer');
            imageViewer.classList.remove('active');
            document.body.style.overflow = 'auto';
        }
        
        // 放大图片
        function zoomIn() {
            if (currentZoom < 1000) {
                currentZoom *= 1.2;
                updateZoomDisplay();
                updateImageTransform();
            }
        }
        
        // 缩小图片
        function zoomOut() {
            if (currentZoom > 0.1) {
                currentZoom /= 1.2;
                updateZoomDisplay();
                updateImageTransform();
            }
        }
        
        // 重置缩放
        function resetZoom() {
            currentZoom = 1;
            translateX = 0;
            translateY = 0;
            updateZoomDisplay();
            updateImageTransform();
        }
        
        // 更新缩放显示
        function updateZoomDisplay() {
            const zoomLevel = document.getElementById('zoom-level');
            zoomLevel.textContent = Math.round(currentZoom * 100) + '%';
        }
        
        // 更新图片变换
        function updateImageTransform() {
            const image = document.getElementById('image-viewer-image');
            image.style.transform = `scale(${currentZoom}) translate(${translateX}px, ${translateY}px)`;
        }
        
        // 添加图片点击事件
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('message-image')) {
                openImageViewer(e.target.src);
            }
        });
        
        // 添加图片查看器关闭事件
        document.getElementById('image-viewer').addEventListener('click', function(e) {
            if (e.target === this) {
                closeImageViewer();
            }
        });
        
        // 添加鼠标滚轮缩放事件
        document.getElementById('image-viewer-image').addEventListener('wheel', function(e) {
            e.preventDefault();
            
            // 计算缩放方向
            const delta = e.deltaY > 0 ? -1 : 1;
            
            // 根据滚轮方向缩放
            if (delta > 0 && currentZoom < 1000) {
                currentZoom *= 1.1;
            } else if (delta < 0 && currentZoom > 0.1) {
                currentZoom /= 1.1;
            }
            
            updateZoomDisplay();
            updateImageTransform();
        });
        
        // 添加拖拽功能
        document.getElementById('image-viewer-image').addEventListener('mousedown', function(e) {
            isDragging = true;
            startX = e.clientX;
            startY = e.clientY;
            initialTranslateX = translateX;
            initialTranslateY = translateY;
        });
        
        document.addEventListener('mousemove', function(e) {
            if (!isDragging) return;
            
            const deltaX = e.clientX - startX;
            const deltaY = e.clientY - startY;
            
            translateX = initialTranslateX + deltaX;
            translateY = initialTranslateY + deltaY;
            
            updateImageTransform();
        });
        
        document.addEventListener('mouseup', function() {
            isDragging = false;
        });
        
        // 初始化
        document.addEventListener('DOMContentLoaded', async function() {
            // 检查URL参数中的ID是否有效
            const urlParams = new URLSearchParams(window.location.search);
            const chatType = urlParams.get('chat_type');
            const id = urlParams.get('id');
            
            if (chatType && id) {
                if (chatType === 'friend') {
                    // 如果是好友聊天，检查是否为好友关系
                    const friendElement = document.querySelector(`.chat-item[data-friend-id="${id}"][data-chat-type="friend"]`);
                    
                    if (!friendElement) {
                        showNotification('发起链接失败：你们还不是好友，无法建立链接', 'error');
                        setTimeout(() => {
                            window.location.href = 'chat.php';
                        }, 3000);
                        return;
                    }
                } else if (chatType === 'group') {
                    // 如果是群聊，检查是否已加入该群
                    // 注意：这里只做弱检查，如果找不到元素，暂时允许通过
                    const groupElement = document.querySelector(`.chat-item[data-group-id="${id}"][data-chat-type="group"]`);
                    
                    if (!groupElement) {
                        console.warn(`未在左侧列表中找到群组 ${id}，可能是列表未加载完成或用户未加入`);
                    }
                }
                
                // 只有当有chatType和id时才加载聊天记录
                loadChatHistory();
                
                // 初始化聊天视频，转换为Blob URL
                initChatVideos();
                
                // 初始化所有媒体
                await initChatMedia();
                
                // 如果是群聊，检查是否被封禁
                <?php if ($chat_type === 'group' && $selected_id): ?>
                    checkGroupBanStatus(<?php echo $selected_id; ?>);
                <?php endif; ?>
            } else {
                // 如果没有选择聊天对象，不自动选择第一个，而是显示默认欢迎界面
                // 确保右侧聊天区域显示为空或欢迎信息
                const chatArea = document.querySelector('.chat-area');
                if (chatArea) {
                    // 如果需要显示特定的欢迎页面，可以在这里操作DOM
                    // 例如：chatArea.innerHTML = '<div class="welcome-screen">...</div>';
                    // 或者保持PHP渲染的默认状态
                }
            }
            
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

        // 下载管理功能
        // 下载状态枚举
        const DownloadStatus = {
            PENDING: 'pending',
            DOWNLOADING: 'downloading',
            PAUSED: 'paused',
            COMPLETED: 'completed',
            FAILED: 'failed',
            CANCELED: 'canceled'
        };

        // 下载任务列表
        const downloadTasks = [];

        // 下载面板显示状态
        let downloadPanelVisible = false;

        // 添加下载任务
        function addDownloadTask(fileName, filePath, fileSize = 0, fileType = 'file') {
            const taskId = Date.now() + Math.random().toString(36).substr(2, 9);
            const task = {
                id: taskId,
                fileName: fileName,
                filePath: filePath,
                fileSize: fileSize,
                fileType: fileType,
                downloadedSize: 0,
                status: DownloadStatus.PENDING,
                progress: 0,
                speed: 0,
                startTime: null,
                endTime: null,
                chunks: [], // 用于存储已下载的chunk
                abortController: null // 用于取消fetch请求
            };
            downloadTasks.push(task);
            updateDownloadPanel();
            return taskId;
        }

        // 更新下载进度
        function updateDownloadProgress(taskId, downloadedSize, totalSize = null) {
            const task = downloadTasks.find(t => t.id === taskId);
            if (!task) return;

            task.downloadedSize = downloadedSize;
            if (totalSize) {
                task.fileSize = totalSize;
            }
            task.progress = task.fileSize > 0 ? Math.round((downloadedSize / task.fileSize) * 100) : 0;

            // 计算下载速度
            if (task.status === DownloadStatus.DOWNLOADING) {
                const currentTime = Date.now();
                const elapsedTime = (currentTime - task.startTime) / 1000; // 秒
                if (elapsedTime > 0) {
                    task.speed = Math.round(task.downloadedSize / elapsedTime / 1024); // KB/s
                }
            }

            updateDownloadPanel();
        }

        // 更新下载状态
        function updateDownloadStatus(taskId, status) {
            const task = downloadTasks.find(t => t.id === taskId);
            if (!task) return;

            task.status = status;
            if (status === DownloadStatus.DOWNLOADING) {
                task.startTime = Date.now();
            } else if (status === DownloadStatus.COMPLETED || status === DownloadStatus.FAILED || status === DownloadStatus.CANCELED) {
                task.endTime = Date.now();
                if (status === DownloadStatus.COMPLETED) {
                    task.speed = 0;
                    task.progress = 100;
                }
            }

            updateDownloadPanel();
        }

        // 删除下载任务
        function deleteDownloadTask(taskId) {
            const index = downloadTasks.findIndex(t => t.id === taskId);
            if (index > -1) {
                downloadTasks.splice(index, 1);
                updateDownloadPanel();
            }
        }

        // 清除所有下载任务
        function clearAllDownloadTasks() {
            downloadTasks.length = 0;
            updateDownloadPanel();
        }

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
                    updateDownloadStatus(task.id, DownloadStatus.FAILED);
                    
                    // 清理资源
                    task.abortController = null;
                    task.chunks = [];
                }
            } catch (error) {
                // 如果是abort错误，不更新状态为失败，保持暂停状态
                if (error.name === 'AbortError') {
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
            // 尝试查找下一个兄弟元素，如果没找到，尝试在父元素中查找（兼容不同的HTML结构）
            let menu = button.nextElementSibling;
            
            // 如果直接的下一个兄弟不是菜单，尝试在父容器中查找
            if (!menu || !menu.classList.contains('file-actions-menu')) {
                const parent = button.parentElement;
                if (parent) {
                    menu = parent.querySelector('.file-actions-menu');
                }
            }
            
            if (menu) {
                menu.style.display = menu.style.display === 'block' ? 'none' : 'block';
            } else {
                console.error('Menu not found for button:', button);
            }

            // 点击其他地方关闭菜单
            document.addEventListener('click', function closeMenu(e) {
                if (!button.contains(e.target) && (!menu || !menu.contains(e.target))) {
                    if (menu) menu.style.display = 'none';
                    document.removeEventListener('click', closeMenu);
                }
            });
        }

        // 切换群聊菜单显示
        function toggleGroupMenu(event, groupId) {
            event.stopPropagation();
            
            // 关闭所有其他菜单，并重置所有chat-item的z-index
            document.querySelectorAll('.friend-menu, [id^="group-menu-"]').forEach(menu => {
                if (menu.id !== `group-menu-${groupId}`) {
                    menu.style.display = 'none';
                }
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
            
            // 点击其他地方关闭菜单
            document.addEventListener('click', function closeMenu(e) {
                if (!e.target.closest('[id="group-menu-' + groupId + '"]') && !e.target.closest(`[onclick*="toggleGroupMenu"]`)) {
                    const menu = document.getElementById(`group-menu-${groupId}`);
                    if (menu) {
                        menu.style.display = 'none';
                        // 菜单关闭时重置z-index
                        document.querySelectorAll('.chat-item').forEach(item => {
                            item.style.zIndex = '';
                        });
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
            if (tabName === 'create-group') {
                loadFriendsForGroup();
            } else if (tabName === 'requests') {
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
            
            console.log('开始搜索用户:', searchTerm);
            
            // 发送搜索请求到服务器
            const searchUrl = `search_users.php?q=${encodeURIComponent(searchTerm)}`;
            console.log('请求URL:', searchUrl);
            
            fetch(searchUrl, {
                credentials: 'include'
            })
            .then(response => {
                console.log('收到响应状态:', response.status);
                if (!response.ok) {
                    throw new Error('网络请求失败: ' + response.status);
                }
                return response.json();
            })
            .then(data => {
                console.log('搜索结果数据:', data);
                
                // 强制重置 HTML
                let html = '';
                
                try {
                    // 处理用户列表
                    let users = [];
                    
                    // 更加健壮的数据提取逻辑
                    if (data && data.success) {
                        if (data.users) {
                            if (Array.isArray(data.users)) {
                                users = data.users;
                            } else if (typeof data.users === 'object') {
                                // 尝试处理类数组对象或普通对象
                                users = Object.values(data.users);
                            }
                        }
                    } else {
                        console.error('搜索返回 success=false 或数据格式不正确', data);
                        html = `<p style="text-align: center; color: #ff4d4f; padding: 20px;">${data.message || '搜索未返回有效数据'}</p>`;
                    }
    
                    console.log('提取到的用户数组:', users);
    
                    if (users.length > 0) {
                        users.forEach(user => {
                            // 安全获取属性，防止 undefined
                            const userId = user.id || 0;
                            const userName = user.username || '未知用户';
                            const userEmail = user.email || '';
                            
                            // 构建 HTML，注意转义单引号以防 JS 报错
                            const safeUserName = userName.replace(/'/g, "\\'");
                            
                            html += `<div class="search-item">
                                <div class="search-item-info">
                                    <div class="search-item-name">${userName}</div>
                                    <div class="search-item-email">${userEmail}</div>
                                </div>
                                <div style="position: relative;" class="search-user-action">
                                    <button onclick="toggleSearchUserMenu(event, ${userId})" style="background: none; border: none; cursor: pointer; color: var(--text-secondary); font-size: 20px; padding: 0 10px;">⋮</button>
                                    <div id="search-user-menu-${userId}" class="search-user-menu" style="display: none; position: absolute; right: 0; top: 100%; background: var(--modal-bg); border: 1px solid var(--border-color); border-radius: 6px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); z-index: 10; min-width: 100px;">
                                        <button onclick="sendFriendRequest(${userId}, '${safeUserName}')" style="display: block; width: 100%; text-align: left; padding: 8px 12px; background: none; border: none; cursor: pointer; color: var(--text-color); font-size: 13px;">添加好友</button>
                                    </div>
                                </div>
                            </div>`;
                        });
                    } else {
                        html = '<p style="text-align: center; color: var(--text-secondary); padding: 20px;">未找到匹配的用户</p>';
                    }
                } catch (e) {
                    console.error('渲染搜索结果时出错:', e);
                    html = '<p style="text-align: center; color: #ff4d4f; padding: 20px;">渲染结果出错</p>';
                }
                
                console.log('最终生成的 HTML 长度:', html.length);
                if (html.length > 0) {
                     resultsDiv.innerHTML = html;
                } else {
                     resultsDiv.innerHTML = '<p style="text-align: center; color: var(--text-secondary); padding: 20px;">无内容显示</p>';
                }
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
                                // 检查头像是否为默认头像或不存在，避免404错误
                                const isDefaultAvatar = request.avatar && (request.avatar === 'default_avatar.png' || request.avatar.includes('default_avatar.png'));
                                const avatar = request.avatar && !isDefaultAvatar ? `<img src="${request.avatar}" alt="${request.username}" style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover;" onerror="this.style.display='none'; this.parentElement.innerHTML='${request.username.substring(0, 2)}'">` : `<div style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, #667eea 0%, #0095ff 100%); display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; font-size: 14px;">${request.username.substring(0, 2)}</div>`;
                                
                                html += `<div style="display: flex; align-items: center; padding: 12px; border-bottom: 1px solid #f0f0f0;">
                                    <div style="margin-right: 12px;">${avatar}</div>
                                    <div style="flex: 1;">
                                        <div style="font-weight: 600; margin-bottom: 2px;">${request.username}</div>
                                        <div style="font-size: 12px; color: #666;">${request.email}</div>
                                        <div style="font-size: 11px; color: #999; margin-top: 2px;">${formattedTime}</div>
                                    </div>
                                    <div style="display: flex; gap: 8px;">
                                        <button onclick="acceptFriendRequest(${request.request_id}, ${request.id}, '${request.username}')" style="padding: 6px 12px; background: #4CAF50; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 12px;">接受</button>
                                        <button onclick="rejectFriendRequest(${request.request_id}, ${request.id}, '${request.username}')" style="padding: 6px 12px; background: #f44336; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 12px;">拒绝</button>
                                    </div>
                                </div>`;
                            } else if (request.type === 'group') {
                                // 群聊邀请
                                // 群聊头像（使用默认头像，因为群聊没有实际头像字段）
                                const groupAvatar = `<div style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, #34a853 0%, #1688f0 100%); display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; font-size: 14px;">${request.group_name.substring(0, 2)}</div>`;
                                
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
            
            // 确保URL中也包含参数，以防万一
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
        function rejectFriendRequest(requestId, userId, username) {
            // 发送拒绝好友请求到服务器
            fetch(`reject_request.php?request_id=${requestId}`, {
                credentials: 'include',
                method: 'POST'
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
        
        // 显示创建群聊模态框
        function showCreateGroupModal() {
            const modal = document.getElementById('create-group-modal');
            modal.style.display = 'flex';
            loadFriendsForGroup();
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
                                background: #1976d2; /* 普遍的蓝色 */
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
                                    " onchange="updateFriendItemStyle(${friend.id})">
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
                updateFriendItemStyle(friendId);
            }
        }
        
        // 更新好友项选中样式
        function updateFriendItemStyle(friendId) {
            const friendItem = document.getElementById(`friend-item-${friendId}`);
            const checkbox = document.getElementById(`friend-${friendId}`);
            if (friendItem && checkbox) {
                if (checkbox.checked) {
                    // 选中状态
                    friendItem.style.background = '#e3f2fd';
                    friendItem.style.borderColor = '#12b7f5';
                    friendItem.style.boxShadow = '0 4px 12px rgba(18, 183, 245, 0.15)';
                } else {
                    // 未选中状态
                    friendItem.style.background = 'white';
                    friendItem.style.borderColor = '#f0f0f0';
                    friendItem.style.boxShadow = '0 2px 4px rgba(0, 0, 0, 0.05)';
                }
            }
        }
        
        // 清空选中的好友
        function clearSelectedFriends() {
            document.querySelectorAll('#select-friends-container input[type="checkbox"]').forEach(checkbox => {
                checkbox.checked = false;
                const friendId = checkbox.value;
                updateFriendItemStyle(friendId);
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
        
        // 创建群聊
        function createGroup() {
            const groupNameInput = document.getElementById('group-name');
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
                    // 关闭创建群聊模态框
                    document.getElementById('create-group-modal').style.display = 'none';
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
        function clearGroupForm() {
            document.getElementById('group-name').value = '';
            document.querySelectorAll('#select-friends-container input[type="checkbox"]').forEach(checkbox => {
                checkbox.checked = false;
            });
        }
        
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
        function isFileExpired(fileName, fileType = 'file') {
            // 如果是localStorage文件ID，直接返回false（因为存储在localStorage中）
            if (fileName && (fileName.startsWith('Picture_') || fileName.startsWith('Video_') || fileName.startsWith('Audio_') || fileName.startsWith('File_'))) {
                return false;
            }
            
            // 根据文件类型获取缓存前缀
            let prefix = 'File_';
            switch(fileType) {
                case 'video':
                    prefix = 'Video_';
                    break;
                case 'audio':
                    prefix = 'Audio_';
                    break;
                case 'image':
                    prefix = 'Picture_';
                    break;
                default:
                    prefix = 'File_';
            }
            
            const targetStorageKey = `${prefix}${encodeURIComponent(fileName)}`;
            
            // 检查localStorage中是否存在该文件的缓存记录
            return !localStorage.getItem(targetStorageKey);
        }
        
        // 设置文件缓存记录到localStorage
        function setFileCache(fileName, fileType, fileSize = 0) {
            // 如果是localStorage文件ID，不需要设置缓存记录
            if (fileName && (fileName.startsWith('Picture_') || fileName.startsWith('Video_') || fileName.startsWith('Audio_') || fileName.startsWith('File_'))) {
                return;
            }
            
            // 根据文件类型获取缓存前缀
            let prefix = 'File_';
            switch(fileType) {
                case 'video':
                    prefix = 'Video_';
                    break;
                case 'audio':
                    prefix = 'Audio_';
                    break;
                case 'image':
                    prefix = 'Picture_';
                    break;
                default:
                    prefix = 'File_';
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
                        setFileCache(fileName, fileType, fileSize);
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
        
        // 从服务器获取媒体文件并缓存
        function fetchMediaFromServer(mediaElement, filePath, fileName, fileType) {
            // 初始化重试计数器（仅在第一次调用时）
            if (!fileRetryCounter[filePath]) {
                fileRetryCounter[filePath] = 0;
            }
            
            // 检查是否已达到最大重试次数
            if (fileRetryCounter[filePath] >= MAX_RETRIES) {
                // 达到最大重试次数，显示已清理提示
                if (mediaElement.tagName === 'IMG') {
                    mediaElement.src = '';
                    mediaElement.alt = `文件不存在或已被清理: ${fileName}`;
                    mediaElement.style.opacity = '0.6';
                } else if (mediaElement.tagName === 'AUDIO') {
                    mediaElement.controls = false;
                    mediaElement.innerHTML = `<span style="color: #999; font-size: 12px;">音频文件不存在或已被清理: ${fileName}</span>`;
                    mediaElement.style.opacity = '0.6';
                }
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
                        setFileCache(fileName, fileType, fileSize);
                        // 重置重试计数器
                        delete fileRetryCounter[filePath];
                        // 使用Blob URL隐藏真实URL，防止IDM等工具检测
                        return response.blob();
                    } else {
                        // 文件不存在，增加重试计数器并继续重试
                        fileRetryCounter[filePath]++;
                        setTimeout(() => {
                            fetchMediaFromServer(mediaElement, filePath, fileName, fileType);
                        }, 1000); // 1秒后重试
                        return Promise.reject('File not found');
                    }
                })
                .then(blob => {
                    // 设置Blob URL
                    const blobUrl = URL.createObjectURL(blob);
                    mediaElement.src = blobUrl;
                })
                .catch(error => {
                    // 仅在非404错误时增加重试计数器
                    if (error !== 'File not found') {
                        fileRetryCounter[filePath]++;
                        setTimeout(() => {
                            fetchMediaFromServer(mediaElement, filePath, fileName, fileType);
                        }, 1000); // 1秒后重试
                    }
                });
        }
        
        // 初始化所有媒体文件（图片、音频、视频），优先从IndexedDB获取
        async function initChatMedia() {
            // 处理所有图片元素
            document.querySelectorAll('.message-image').forEach(async img => {
                const fileUrl = img.src;
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
                    // 创建一个错误提示元素，替换图片元素
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
                    
                    // 替换图片元素，确保parentNode存在
                    if (img.parentNode) {
                        img.parentNode.replaceChild(errorDiv, img);
                    }
                }
            });
            
            // 处理所有音频元素
            document.querySelectorAll('.audio-element').forEach(async audio => {
                const fileUrl = audio.src;
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
                    // 创建一个错误提示元素，替换音频元素
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
                    
                    // 替换音频元素，确保parentNode存在
                    if (audio.parentNode) {
                        audio.parentNode.replaceChild(errorDiv, audio);
                    }
                }
            });
        }
        
        // 清除Service Worker缓存并注销
        async function clearServiceWorkerCache() {
            if ('serviceWorker' in navigator) {
                try {
                    // 1. 获取所有Service Worker注册并注销
                    const registrations = await navigator.serviceWorker.getRegistrations();
                    for (let registration of registrations) {
                        await registration.unregister();
                        console.log('Service Worker 已注销');
                    }
                    
                    // 2. 清除缓存存储
                    if ('caches' in window) {
                        const keys = await caches.keys();
                        for (let key of keys) {
                            await caches.delete(key);
                            console.log(`缓存 ${key} 已删除`);
                        }
                    }
                    
                    alert('缓存已清除，页面将重新加载');
                    location.reload();
                } catch (error) {
                    console.error('清除缓存失败:', error);
                    alert('清除缓存失败，请手动在浏览器设置中清除');
                }
            } else {
                alert('您的浏览器不支持 Service Worker');
            }
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
                
                // 替换媒体元素，确保parentNode存在
                if (media.parentNode) {
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
        
        // 初始化音频播放器
        function initAudioPlayers(container = document) {
            container.querySelectorAll('.custom-audio-player').forEach(player => {
                // 防止重复初始化
                if (player.dataset.initialized === 'true') return;

                const audio = player.querySelector('.audio-element');
                const playBtn = player.querySelector('.audio-play-btn');
                const progressBar = player.querySelector('.audio-progress-bar');
                const progress = player.querySelector('.audio-progress');
                const currentTimeEl = player.querySelector('.current-time');
                const durationEl = player.querySelector('.audio-duration');
                
                // 检查必要元素是否存在，防止报错
                if (!audio || !playBtn || !progressBar || !progress || !currentTimeEl || !durationEl) {
                    return;
                }

                // 标记为已初始化
                player.dataset.initialized = 'true';
                
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
                
                const onMouseMove = function(e) {
                    if (!isDragging) return;
                    
                    // 确保duration有效才允许拖动
                    if (!isFinite(audio.duration) || isNaN(audio.duration) || audio.duration <= 0) {
                        return;
                    }
                    
                    const progressWidth = progressBar.clientWidth;
                    const rect = progressBar.getBoundingClientRect();
                    let clickX = e.clientX - rect.left;
                    
                    // 限制拖动范围在进度条内
                    clickX = Math.max(0, Math.min(clickX, progressWidth));
                    
                    const duration = audio.duration;
                    audio.currentTime = (clickX / progressWidth) * duration;
                };
                
                const onMouseUp = function() {
                    if (isDragging) {
                        isDragging = false;
                        document.removeEventListener('mousemove', onMouseMove);
                        document.removeEventListener('mouseup', onMouseUp);
                    }
                };
                
                // 开始拖动
                progressBar.addEventListener('mousedown', function(e) {
                    isDragging = true;
                    // 防止选中文本
                    e.preventDefault();
                    document.addEventListener('mousemove', onMouseMove);
                    document.addEventListener('mouseup', onMouseUp);
                });
                
                // 鼠标离开窗口时结束拖动
                document.addEventListener('mouseleave', function() {
                    isDragging = false;
                });
            });
        }
        
        // 更多设置相关函数
        function showMoreSettings() {
            document.getElementById('more-settings-modal').style.display = 'flex';
            
            // 初始化每日必应壁纸开关状态
            const bingEnabled = localStorage.getItem('bingWallpaperEnabled') !== 'false'; // 默认为true
            const toggle = document.getElementById('bing-wallpaper-toggle');
            if (toggle) {
                toggle.checked = bingEnabled;
            }
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

        // 头像裁剪相关变量
        let selectedAvatarFile = null;
        let isDraggingSelection = false;
        let isDraggingImage = false;
        let dragStartX = 0;
        let dragStartY = 0;
        let selectionStartX = 0;
        let selectionStartY = 0;
        let imageStartX = 0;
        let imageStartY = 0;
        let cropImage = null;
        
        // 监听头像文件选择并显示文件名
        document.addEventListener('DOMContentLoaded', function() {
            const avatarFileInput = document.getElementById('avatar-file');
            if (avatarFileInput) {
                avatarFileInput.addEventListener('change', function(e) {
                    const file = e.target.files[0];
                    if (!file) return;
                    
                    selectedAvatarFile = file;
                    
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
                    
                    // 显示文件名
                    const fileNameDiv = document.getElementById('selected-file-name');
                    if (fileNameDiv) {
                        fileNameDiv.textContent = '已选择: ' + file.name;
                    }
                });
            }
        });
        
        // 初始化裁剪相关事件 - 已废弃
        function initAvatarCropEvents() {
            // 此函数已废弃，保留函数名防止报错
        }
        
        // 拖动选择框
        function dragSelection(e) {
            const container = document.getElementById('avatar-crop-container');
            const selection = document.getElementById('avatar-selection');
            
            if (!container || !selection) return; // 检查元素是否存在
            
            const deltaX = e.clientX - dragStartX;
            const deltaY = e.clientY - dragStartY;
            
            let newLeft = selectionStartX + deltaX;
            let newTop = selectionStartY + deltaY;
            
            // 限制选择框在容器内
            const containerWidth = container.offsetWidth;
            const containerHeight = container.offsetHeight;
            const selectionWidth = selection.offsetWidth;
            const selectionHeight = selection.offsetHeight;
            
            newLeft = Math.max(0, Math.min(newLeft, containerWidth - selectionWidth));
            newTop = Math.max(0, Math.min(newTop, containerHeight - selectionHeight));
            
            selection.style.left = newLeft + 'px';
            selection.style.top = newTop + 'px';
            
            // 更新预览
            updateAvatarPreview();
        }
        
        // 拖动图片
        function dragImage(e) {
            const img = document.getElementById('avatar-crop-image');
            
            if (!img) return; // 检查元素是否存在
            
            const deltaX = e.clientX - dragStartX;
            const deltaY = e.clientY - dragStartY;
            
            let newLeft = imageStartX + deltaX;
            let newTop = imageStartY + deltaY;
            
            img.style.left = newLeft + 'px';
            img.style.top = newTop + 'px';
            
            // 更新预览
            updateAvatarPreview();
        }
        
        // 更新头像预览
        function updateAvatarPreview() {
            const img = document.getElementById('avatar-crop-image');
            const selection = document.getElementById('avatar-selection');
            const canvas = document.getElementById('avatar-preview');
            
            if (!img || !selection || !canvas) return; // 检查元素是否存在
            
            const ctx = canvas.getContext('2d');
            
            if (!img.src) return;
            
            // 清空画布
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            
            // 计算裁剪区域
            const selectionLeft = selection.offsetLeft;
            const selectionTop = selection.offsetTop;
            const selectionWidth = selection.offsetWidth;
            const selectionHeight = selection.offsetHeight;
            
            // 计算图片缩放比例
            const imgNaturalWidth = img.naturalWidth;
            const imgNaturalHeight = img.naturalHeight;
            const imgDisplayWidth = img.offsetWidth;
            const imgDisplayHeight = img.offsetHeight;
            const scaleX = imgNaturalWidth / imgDisplayWidth;
            const scaleY = imgNaturalHeight / imgDisplayHeight;
            
            // 计算实际裁剪位置和尺寸
            const cropX = Math.abs(img.offsetLeft - selectionLeft) * scaleX;
            const cropY = Math.abs(img.offsetTop - selectionTop) * scaleY;
            const cropWidth = selectionWidth * scaleX;
            const cropHeight = selectionHeight * scaleY;
            
            // 绘制裁剪后的图片到预览画布
            ctx.drawImage(
                img, 
                cropX, cropY, cropWidth, cropHeight, 
                0, 0, canvas.width, canvas.height
            );
        }
        
        // 修改头像
        function changeAvatar() {
            if (!selectedAvatarFile) {
                alert('请选择头像图片');
                return false;
            }
            
            // 直接上传原文件，由后端处理压缩
            const formData = new FormData();
            formData.append('avatar', selectedAvatarFile);
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

        // 背景图片相关功能
        let selectedBackgroundFile = null;
        
        // 监听背景图片选择
        const backgroundFile = document.getElementById('background-file');
        if (backgroundFile) {
            backgroundFile.addEventListener('change', function(e) {
                const file = e.target.files[0];
                if (!file) return;
                
                // 检查文件大小（100MB = 1024 * 1024 * 100 bytes）
                const maxSize = 1024 * 1024 * 100;
                if (file.size > maxSize) {
                    alert('图片大小不能超过100MB');
                    return;
                }
                
                // 读取文件并处理
                const reader = new FileReader();
                reader.onload = function(event) {
                    const img = new Image();
                    img.onload = function() {
                        // 如果图片尺寸大于1920×1080，压缩到1920×1080
                        if (img.width > 1920 || img.height > 1920) {
                            const canvas = document.createElement('canvas');
                            const ctx = canvas.getContext('2d');
                            
                            // 计算压缩后的尺寸，保持比例
                            let newWidth = img.width;
                            let newHeight = img.height;
                            
                            if (newWidth > newHeight) {
                                if (newWidth > 1920) {
                                    newHeight = Math.round((1920 / newWidth) * newHeight);
                                    newWidth = 1920;
                                }
                            } else {
                                if (newHeight > 1080) {
                                    newWidth = Math.round((1080 / newHeight) * newWidth);
                                    newHeight = 1080;
                                }
                            }
                            
                            // 设置画布尺寸
                            canvas.width = newWidth;
                            canvas.height = newHeight;
                            
                            // 绘制压缩后的图片
                            ctx.drawImage(img, 0, 0, newWidth, newHeight);
                            
                            // 转换为data URL
                            const compressedDataUrl = canvas.toDataURL('image/jpeg', 0.8);
                            
                            // 显示预览
                            const preview = document.getElementById('background-preview');
                            const previewText = document.getElementById('background-preview-text');
                            if (preview) preview.style.backgroundImage = `url(${compressedDataUrl})`;
                            if (previewText) previewText.style.display = 'none';
                            selectedBackgroundFile = compressedDataUrl;
                        } else {
                            // 图片尺寸合适，直接使用
                            const preview = document.getElementById('background-preview');
                            const previewText = document.getElementById('background-preview-text');
                            if (preview) preview.style.backgroundImage = `url(${event.target.result})`;
                            if (previewText) previewText.style.display = 'none';
                            selectedBackgroundFile = event.target.result;
                        }
                    };
                    img.onerror = function() {
                        alert('无法读取图片文件');
                    };
                    img.src = event.target.result;
                };
                reader.readAsDataURL(file);
            });
        }
        
        // 应用背景图片
        function applyBackground() {
            if (!selectedBackgroundFile) {
                alert('请先选择背景图片');
                return;
            }
            
            // 将背景图片保存到localStorage
            localStorage.setItem('chatBackground', selectedBackgroundFile);
            
            // 刷新背景
            refreshBackground();
            
            alert('背景图片设置成功');
        }
        
        // 移除背景图片
        function removeBackground() {
            // 从localStorage移除背景图片
            localStorage.removeItem('chatBackground');
            
            // 刷新背景（可能会应用Bing壁纸）
            refreshBackground();
            
            // 重置预览
            const preview = document.getElementById('background-preview');
            const previewText = document.getElementById('background-preview-text');
            preview.style.backgroundImage = '';
            previewText.style.display = 'block';
            selectedBackgroundFile = null;
            
            alert('背景图片已移除');
        }

        // 切换每日必应壁纸
        function toggleBingWallpaper(enabled) {
            // 如果是春节期间，强制关闭并提示
            if (IS_SPRING_FESTIVAL_PERIOD) {
                alert('春节期间（小年到元宵节）已启用限定主题，暂时无法开启必应壁纸。');
                const bingSwitch = document.getElementById('bing-wallpaper-switch');
                if (bingSwitch) {
                    bingSwitch.checked = false;
                    bingSwitch.disabled = true;
                }
                localStorage.setItem('bingWallpaperEnabled', 'false');
                return;
            }
            
            localStorage.setItem('bingWallpaperEnabled', enabled);
            refreshBackground();
        }

        // 刷新背景显示
        function refreshBackground() {
            // 如果是春节期间，不加载 Bing 壁纸，而是由 PHP 后端渲染的背景生效
            if (IS_SPRING_FESTIVAL_PERIOD) {
                // 再次确保开关状态正确
                const bingSwitch = document.getElementById('bing-wallpaper-switch');
                if (bingSwitch && !bingSwitch.disabled) {
                    bingSwitch.checked = false;
                    bingSwitch.disabled = true;
                    // 更新提示文本
                    const label = bingSwitch.parentElement.querySelector('span');
                    if (label) {
                        label.title = '春节期间（小年到元宵节）不可用';
                        label.style.opacity = '0.7';
                        label.style.cursor = 'not-allowed';
                    }
                }
                localStorage.setItem('bingWallpaperEnabled', 'false');
                return;
            }

            const savedBackground = localStorage.getItem('chatBackground');
            const bingEnabled = localStorage.getItem('bingWallpaperEnabled') !== 'false'; // 默认为true
            
            if (savedBackground) {
                applyChatBackground(savedBackground);
            } else if (bingEnabled) {
                applyChatBackground('https://bing.biturl.top/?resolution=1920&format=image&index=0&mkt=zh-CN');
            } else {
                // 如果既没有自定义背景也没有启用Bing壁纸，则完全移除背景
                removeChatBackground();
            }
        }
        
        // 应用聊天背景
        function applyChatBackground(backgroundUrl) {
            try {
                // 验证backgroundUrl是否有效
                if (!backgroundUrl || typeof backgroundUrl !== 'string') {
                    return;
                }
                
                // 创建Image对象来测试图片是否能正常加载
                const img = new Image();
                img.onload = function() {
                    // 图片加载成功，应用背景到整个页面
                    document.body.style.backgroundImage = `url(${backgroundUrl})`;
                    document.body.style.backgroundSize = 'cover';
                    document.body.style.backgroundPosition = 'center';
                    document.body.style.backgroundAttachment = 'fixed';
                    document.body.style.backgroundRepeat = 'no-repeat';
                    
                    // 应用背景到主容器
                    const chatContainer = document.querySelector('.chat-container');
                    if (chatContainer) {
                        chatContainer.style.backgroundImage = `url(${backgroundUrl})`;
                        chatContainer.style.backgroundSize = 'cover';
                        chatContainer.style.backgroundPosition = 'center';
                        chatContainer.style.backgroundAttachment = 'fixed';
                        chatContainer.style.backgroundRepeat = 'no-repeat';
                    }
                    
                    // 应用背景到消息容器
                    const messagesContainer = document.getElementById('messages-container');
                    if (messagesContainer) {
                        messagesContainer.style.backgroundImage = 'none';
                    }
                    
                    // 应用背景到聊天区域
                    const chatArea = document.querySelector('.chat-area');
                    if (chatArea) {
                        chatArea.style.backgroundImage = 'none';
                    }
                };
                img.onerror = function() {
                    // 图片加载失败，忽略错误，不向控制台报错
                    // 清除背景设置
                    document.body.style.backgroundImage = '';
                    const chatContainer = document.querySelector('.chat-container');
                    if (chatContainer) {
                        chatContainer.style.backgroundImage = '';
                    }
                };
                img.src = backgroundUrl;
            } catch (error) {
                // 忽略错误，不向控制台报错
            }
        }
        
        // 移除聊天背景
        function removeChatBackground() {
            // 移除整个页面背景
            document.body.style.backgroundImage = '';
            document.body.style.backgroundSize = '';
            document.body.style.backgroundPosition = '';
            document.body.style.backgroundAttachment = '';
            document.body.style.backgroundRepeat = '';
            
            // 移除主容器背景
            const chatContainer = document.querySelector('.chat-container');
            if (chatContainer) {
                chatContainer.style.backgroundImage = '';
                chatContainer.style.backgroundSize = '';
                chatContainer.style.backgroundPosition = '';
                chatContainer.style.backgroundAttachment = '';
                chatContainer.style.backgroundRepeat = '';
            }
            
            // 移除消息容器背景
            const messagesContainer = document.getElementById('messages-container');
            if (messagesContainer) {
                messagesContainer.style.backgroundImage = '';
                messagesContainer.style.backgroundSize = '';
                messagesContainer.style.backgroundPosition = '';
                messagesContainer.style.backgroundAttachment = '';
            }
            
            // 移除聊天区域背景
            const chatArea = document.querySelector('.chat-area');
            if (chatArea) {
                chatArea.style.backgroundImage = '';
                chatArea.style.backgroundSize = '';
                chatArea.style.backgroundPosition = '';
                chatArea.style.backgroundAttachment = '';
            }
        }
        
        // 页面加载时检查并应用保存的背景
        window.addEventListener('load', function() {
            // 刷新背景（优先使用自定义背景，其次是Bing壁纸）
            refreshBackground();
            
            // 如果有自定义背景，更新预览
            const savedBackground = localStorage.getItem('chatBackground');
            if (savedBackground) {
                selectedBackgroundFile = savedBackground;
                const preview = document.getElementById('background-preview');
                const previewText = document.getElementById('background-preview-text');
                if (preview && previewText) {
                    preview.style.backgroundImage = `url(${savedBackground})`;
                    previewText.style.display = 'none';
                }
            }
            
            // 初始化每日必应壁纸开关状态
            const bingSwitch = document.getElementById('bing-wallpaper-switch');
            if (bingSwitch) {
                bingSwitch.checked = localStorage.getItem('bingWallpaperEnabled') !== 'false';
                bingSwitch.addEventListener('change', function(e) {
                    toggleBingWallpaper(e.target.checked);
                });
            }
        });

        // 截图功能
        async function takeScreenshot() {
            // 检查是否为Windows 7系统
            if (navigator.userAgent.indexOf('Windows NT 6.1') > -1) {
                alert('由于Windows版本问题，此页面的截图功能无法在Windows7上运行，请升级系统后再试！');
                return;
            }

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

        // 添加Ctrl+Alt+D快捷键监听
        document.addEventListener('keydown', (e) => {
            if (e.ctrlKey && e.altKey && e.key === 'd') {
                e.preventDefault();
                takeScreenshot();
            }
        });

        // 新春倒计时功能
        (function() {
            // 获取PHP注入的配置，如果失败则使用默认值
            let lunarConfig = {};
            try {
                <?php if (isset($lunar_config)): ?>
                lunarConfig = <?php echo json_encode($lunar_config); ?>;
                <?php else: ?>
                // 默认配置
                lunarConfig = {
                    show_countdown: true,
                    show_festival_text: false,
                    target_timestamp: 1771516800, // 2026-02-17
                    festival_name: '2026年马新春',
                    title_template: '🏮 2026年马新春倒计时 🏮'
                };
                <?php endif; ?>
            } catch (e) {
                console.error('Failed to parse lunar config:', e);
                return;
            }
            
            // 暴露给全局，以便其他脚本使用
            window.LUNAR_CONFIG = lunarConfig;
            
            // 如果不显示倒计时也不显示节日文字，则不创建元素
            if (!lunarConfig.show_countdown && !lunarConfig.show_festival_text) {
                return;
            }

            // 创建倒计时容器
            const container = document.createElement('div');
            container.id = 'spring-festival-countdown';
            container.style.cssText = `
                position: fixed;
                top: 70px;
                right: 20px;
                background: linear-gradient(135deg, #ff4d4f 0%, #ff7875 100%);
                color: white;
                padding: 15px;
                border-radius: 12px;
                box-shadow: 0 4px 12px rgba(255, 77, 79, 0.4);
                z-index: 9999;
                font-family: 'Microsoft YaHei', sans-serif;
                transition: all 0.3s ease;
                min-width: 200px;
                cursor: pointer;
                user-select: none;
            `;
            
            // 标题
            const title = document.createElement('div');
            title.innerHTML = lunarConfig.title_template || '🏮 欢度春节 🏮';
            title.style.cssText = `
                font-size: 14px;
                font-weight: bold;
                text-align: center;
                margin-bottom: 8px;
                text-shadow: 0 1px 2px rgba(0,0,0,0.1);
            `;
            
            // 时间显示
            const timer = document.createElement('div');
            timer.id = 'sfc-timer';
            timer.style.cssText = `
                font-size: 18px;
                font-weight: bold;
                text-align: center;
                font-family: monospace;
                white-space: nowrap;
                min-height: 1.5em;
                line-height: 1.5em;
                overflow: hidden;
                display: flex;
                justify-content: center;
                align-items: center;
            `;
            
            // 最小化按钮（实际上点击整个容器即可切换，但为了视觉提示可以加个小图标或提示）
            // 这里我们用状态变量控制显示模式
            
            container.appendChild(title);
            container.appendChild(timer);
            document.body.appendChild(container);
            
            // 拖动相关变量
            let isDragging = false;
            let startX, startY;
            let initialRight, initialTop;
            let hasMoved = false; // 用于区分点击和拖动
            let isMinimized = false; // 最小化状态
            
            // 鼠标按下事件
            container.addEventListener('mousedown', function(e) {
                isDragging = true;
                hasMoved = false;
                startX = e.clientX;
                startY = e.clientY;
                
                // 获取当前位置（使用getComputedStyle以确保准确）
                const rect = container.getBoundingClientRect();
                
                // 计算当前的right和top值
                const windowWidth = window.innerWidth;
                initialRight = windowWidth - rect.right;
                initialTop = rect.top;
                
                // 防止文本选中
                e.preventDefault();
                
                // 设置鼠标样式
                container.style.cursor = 'grabbing';
                // 暂时移除过渡效果，使拖动更跟手
                container.style.transition = 'none';
            });
            
            // 鼠标移动事件（绑定到document以防止鼠标移出容器）
            document.addEventListener('mousemove', function(e) {
                if (!isDragging) return;
                
                const deltaX = startX - e.clientX;
                const deltaY = e.clientY - startY; // 向下移动，top增加
                
                // 如果移动距离超过3像素，则视为拖动
                if (Math.abs(deltaX) > 3 || Math.abs(deltaY) > 3) {
                    hasMoved = true;
                }
                
                let newRight = initialRight + deltaX;
                let newTop = initialTop + deltaY;
                
                // 边界限制
                const windowWidth = window.innerWidth;
                const windowHeight = window.innerHeight;
                const rect = container.getBoundingClientRect();
                
                // 限制在屏幕范围内
                // 右边界
                if (newRight < 0) newRight = 0;
                // 左边界 (right的最大值 = windowWidth - width)
                if (newRight > windowWidth - rect.width) newRight = windowWidth - rect.width;
                // 上边界
                if (newTop < 0) newTop = 0;
                // 下边界 (top的最大值 = windowHeight - height)
                if (newTop > windowHeight - rect.height) newTop = windowHeight - rect.height;
                
                container.style.right = newRight + 'px';
                container.style.top = newTop + 'px';
                container.style.bottom = 'auto';
            });
            
            // 鼠标释放事件
            document.addEventListener('mouseup', function() {
                if (isDragging) {
                    isDragging = false;
                    container.style.cursor = 'pointer';
                    // 恢复过渡效果
                    container.style.transition = 'all 0.3s ease';
                    
                    // 如果是最小化状态，吸附到最近的边
                    if (isMinimized) {
                         // 这里可以添加吸附逻辑，暂时保持原位
                         // updateDisplayState会处理吸附逻辑，但这里我们只在点击时触发updateDisplayState
                         // 或者在拖动结束后，如果需要特殊吸附，可以在这里处理
                         
                         // 强制吸附到右侧（如果需要的话，或者保持自由位置）
                         // 如果想保持“只能隐藏到侧边栏”的特性，可以在这里判断
                         // 但用户的要求是“可以拖动”，通常意味着自由拖动
                         // “倒计时只能隐藏到侧边栏”可能指的是最小化时的形态，而不是强制位置
                    }
                }
            });
            
            // 切换最小化/展开状态
            container.addEventListener('click', function(e) {
                // 如果发生了拖动，则不触发点击事件
                if (hasMoved) {
                    return;
                }
                isMinimized = !isMinimized;
                updateDisplayState();
            });
            
            function updateDisplayState() {
                if (isMinimized) {
                    container.style.width = '40px';
                    container.style.height = '40px';
                    container.style.padding = '0';
                    container.style.minWidth = 'unset';
                    container.style.borderRadius = '50%';
                    container.style.overflow = 'hidden';
                    container.style.display = 'flex';
                    container.style.alignItems = 'center';
                    container.style.justifyContent = 'center';
                    
                    title.style.display = 'none';
                    timer.style.display = 'none';
                    
                    // 显示“福”字或图标
                    if (!document.getElementById('sfc-mini-icon')) {
                        const icon = document.createElement('div');
                        icon.id = 'sfc-mini-icon';
                        icon.innerHTML = '福';
                        icon.style.cssText = `
                            font-size: 20px;
                            font-weight: bold;
                            color: #fff0f0;
                        `;
                        container.appendChild(icon);
                    } else {
                        document.getElementById('sfc-mini-icon').style.display = 'block';
                    }
                    
                    // 只有在从未移动过时才强制吸附到右侧
                    if (!hasMoved && !container.style.right) {
                        container.style.right = '0';
                        container.style.borderTopRightRadius = '0';
                        container.style.borderBottomRightRadius = '0';
                    }
                } else {
                    container.style.width = 'auto';
                    container.style.height = 'auto';
                    container.style.padding = '15px';
                    container.style.minWidth = '200px';
                    container.style.borderRadius = '12px';
                    
                    // 恢复正常布局
                    container.style.display = 'block';
                    container.style.alignItems = 'unset';
                    container.style.justifyContent = 'unset';
                    
                    // 只有在从未移动过时才重置位置
                    if (!hasMoved && !container.style.right) {
                        container.style.right = '20px';
                    }
                    
                    title.style.display = 'block';
                    timer.style.display = 'flex';
                    
                    if (document.getElementById('sfc-mini-icon')) {
                        document.getElementById('sfc-mini-icon').style.display = 'none';
                    }
                }
            }
            
            // 渲染滚动数字效果
            function renderRollingText(container, text, enableAnimation) {
                // 确保容器有足够的子元素
                let children = Array.from(container.children);
                
                // 移除多余的子元素
                while (children.length > text.length) {
                    container.removeChild(children[children.length - 1]);
                    children.pop();
                }
                
                // 更新或创建子元素
                for (let i = 0; i < text.length; i++) {
                    let char = text[i];
                    let span;
                    
                    if (i < children.length) {
                        span = children[i];
                    } else {
                        span = document.createElement('span');
                        span.className = 'sfc-char';
                        span.style.cssText = 'display: inline-block; position: relative; min-width: 0.6em; text-align: center; height: 1.5em; line-height: 1.5em; vertical-align: bottom;';
                        container.appendChild(span);
                    }
                    
                    // 如果内容改变了
                    if (span.innerText !== char) {
                        // 如果是数字且启用动画
                        if (enableAnimation && /[0-9]/.test(char) && /[0-9]/.test(span.innerText)) {
                             const oldChar = span.innerText;
                             
                             // 创建滚动容器结构
                             span.innerHTML = '';
                             span.style.overflow = 'hidden';
                             // 临时移除span自身的过渡，防止冲突
                             span.style.transition = 'none';
                             
                             const stack = document.createElement('div');
                             stack.style.display = 'flex';
                             stack.style.flexDirection = 'column';
                             stack.style.transition = 'transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1)';
                             stack.style.transform = 'translateY(0)';
                             stack.style.lineHeight = '1.5em';
                             stack.style.height = '3em'; // 2 characters height
                             
                             const oldEl = document.createElement('div');
                             oldEl.innerText = oldChar;
                             oldEl.style.height = '1.5em';
                             oldEl.style.lineHeight = '1.5em';
                             oldEl.style.display = 'flex';
                             oldEl.style.justifyContent = 'center';
                             oldEl.style.alignItems = 'center';
                             
                             const newEl = document.createElement('div');
                             newEl.innerText = char;
                             newEl.style.height = '1.5em';
                             newEl.style.lineHeight = '1.5em';
                             newEl.style.display = 'flex';
                             newEl.style.justifyContent = 'center';
                             newEl.style.alignItems = 'center';
                             
                             stack.appendChild(oldEl);
                             stack.appendChild(newEl);
                             span.appendChild(stack);
                             
                             // 强制重绘
                             void stack.offsetWidth;
                             
                             // 执行动画：向上滚动
                             stack.style.transform = 'translateY(-1.5em)';
                             
                             setTimeout(() => {
                                 span.innerText = char;
                                 span.style.overflow = '';
                                 span.style.transition = '';
                             }, 300);
                        } else {
                            span.innerText = char;
                        }
                    }
                }
            }

            // 更新倒计时
            function updateTimer() {
                const now = new Date();
                
                if (lunarConfig.show_festival_text) {
                    // 节日展示模式
                    const endTimestamp = lunarConfig.festival_end_timestamp * 1000;
                    if (now.getTime() >= endTimestamp) {
                        container.style.display = 'none';
                        return;
                    }
                    
                    // 确保标题是空的或者适当的
                    title.style.display = 'none'; // 隐藏标题，因为内容都在timer里显示了
                    
                    const festivalName = lunarConfig.festival_name;
                    const timeStr = now.toLocaleString('zh-CN', { timeZone: 'Asia/Shanghai', hour12: false });
                    
                    timer.innerHTML = `<div style="font-size: 15px; line-height: 1.6; text-align: center;">今天是${festivalName}<br>当前时间：${timeStr}</div>`;
                    timer.style.whiteSpace = 'normal';
                    
                    setTimeout(updateTimer, 1000);
                    return;
                }
                
                if (lunarConfig.show_countdown) {
                    const targetDate = new Date(lunarConfig.target_timestamp * 1000);
                    const diff = targetDate - now;
                    
                    if (diff <= 0) {
                        // 倒计时结束，不再刷新页面
                        container.style.display = 'none';
                        
                        // Check if we should show the celebration
                        checkAndShowSpringFestivalCelebration();
                        
                        return;
                    }
                    
                    // 判断是否是除夕 (target - 24h <= now < target)
                    // 除夕24小时内
                    const oneDay = 24 * 60 * 60 * 1000;
                    const isNewYearEve = diff <= oneDay;
                    
                    if (isNewYearEve) {
                        // 除夕：精确到毫秒，去掉天
                        const hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                        const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
                        const seconds = Math.floor((diff % (1000 * 60)) / 1000);
                        const milliseconds = diff % 1000;
                        
                        // 毫秒级不使用滚动动画
                        timer.innerHTML = `
                            ${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}<span style="font-size: 14px">.${milliseconds.toString().padStart(3, '0')}</span>
                        `;
                        
                        setTimeout(updateTimer, 10);
                    } else {
                        // 平时：精确到秒
                        const days = Math.floor(diff / (1000 * 60 * 60 * 24));
                        const hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                        const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
                        const seconds = Math.floor((diff % (1000 * 60)) / 1000);
                        
                        const timeString = `${days}天 ${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
                        
                        renderRollingText(timer, timeString, true);
                        
                        setTimeout(updateTimer, 1000);
                    }
                }
            }
            
            // 启动倒计时
            updateTimer();
            
            // 默认展开
            updateDisplayState();
            
            function checkAndShowSpringFestivalCelebration() {
                const now = new Date();
                const currentYear = now.getFullYear();
                const storageKey = 'spring_festival_shown_' + currentYear;
                
                // Only show if not shown before this year
                if (!localStorage.getItem(storageKey)) {
                    const modal = document.getElementById('spring-festival-modal');
                    const msg = document.getElementById('spring-festival-msg');
                    const btn = document.getElementById('spring-festival-confirm-btn');
                    
                    if (modal && msg && btn) {
                        // Format time: "今天是2026年02月17日大年初一"
                        const dateStr = now.toLocaleDateString('zh-CN', {year: 'numeric', month: '2-digit', day: '2-digit'});
                        msg.textContent = `今天是${dateStr}大年初一`;
                        
                        modal.style.display = 'flex';
                        
                        btn.onclick = function() {
                            modal.style.display = 'none';
                            localStorage.setItem(storageKey, 'true');
                            startFallingFuAnimation();
                        };
                    }
                }
            }
            
            function startFallingFuAnimation() {
                const duration = 3000; // 3 seconds
                const endTime = Date.now() + duration;
                
                const interval = setInterval(() => {
                    if (Date.now() > endTime) {
                        clearInterval(interval);
                        return;
                    }
                    createFallingFu();
                }, 100); // Create a new Fu every 100ms
                
                // Initial burst
                for(let i=0; i<10; i++) createFallingFu();
            }
            
            function createFallingFu() {
                const fu = document.createElement('div');
                fu.innerText = '福';
                fu.className = 'falling-fu';
                fu.style.left = Math.random() * 100 + 'vw';
                fu.style.animation = `fall ${Math.random() * 2 + 1}s linear`;
                fu.style.fontSize = (Math.random() * 30 + 20) + 'px';
                
                document.body.appendChild(fu);
                
                // Remove after animation
                setTimeout(() => {
                    fu.remove();
                }, 3000);
            }
        })();

        // 初始化视频播放器
        function initVideoPlayer() {
            console.log('开始初始化视频播放器...');
            
            // 确保 IS_SPRING_FESTIVAL_PERIOD 变量存在
            if (typeof IS_SPRING_FESTIVAL_PERIOD === 'undefined') {
                window.IS_SPRING_FESTIVAL_PERIOD = false;
            }
            
            // 获取视频元素和控件
            let videoElement = document.getElementById('custom-video-element');
            let playBtn = document.getElementById('video-play-btn');
            let progressBar = document.getElementById('video-progress-bar');
            let progress = document.getElementById('video-progress');
            let currentTimeEl = document.querySelector('.video-time.current-time');
            let totalTimeEl = document.querySelector('.video-time.total-time');
            let muteBtn = document.getElementById('video-mute-btn');
            let volumeSlider = document.getElementById('volume-slider');
            let videoControls = document.querySelector('.video-controls');
            let videoPlayer = document.querySelector('.custom-video-player');
            let videoHeader = document.querySelector('.video-player-header');
            
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
            let showControls = () => {
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
                    
                    // 重置视频播放器尺寸，确保视频充满屏幕
                    videoPlayer.style.height = '100%';
                    videoPlayer.style.width = '100%';
                    videoElement.style.height = '100%';
                    videoElement.style.width = '100%';
                    
                    // 确保视频元素使用contain模式，避免被裁剪
                    videoElement.style.objectFit = 'contain';
                    
                    // 确保控件始终可见
                    videoControls.style.opacity = '1';
                    videoControls.style.transform = 'translateY(0)';
                    videoControls.style.pointerEvents = 'auto';
                    
                    // 添加全屏样式，确保控件始终可见
                    videoPlayer.classList.add('fullscreen');
                    videoControls.classList.add('fullscreen');
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
                    
                    // 恢复原始尺寸和样式
                    videoPlayer.style.height = '';
                    videoPlayer.style.width = '';
                    videoElement.style.height = '';
                    videoElement.style.width = '';
                    videoElement.style.objectFit = '';
                    videoControls.style.opacity = '';
                    videoControls.style.transform = '';
                    videoControls.style.pointerEvents = '';
                    
                    // 移除全屏样式
                    videoPlayer.classList.remove('fullscreen');
                    videoControls.classList.remove('fullscreen');
                }
            });
            
            // 修改控件显示逻辑，确保全屏模式下始终显示控件
            const enhancedShowControls = () => {
                // 清除之前的计时器
                clearTimeout(hideControlsTimer);
                
                // 显示控件
                videoControls.classList.remove('hidden');
                
                // 非全屏模式下才显示标题栏
                if (!document.fullscreenElement) {
                    videoHeader.classList.remove('hidden');
                }
                
                // 只有在非全屏且播放状态下才自动隐藏控件
                if (!videoElement.paused && !document.fullscreenElement) {
                    hideControlsTimer = setTimeout(() => {
                        videoControls.classList.add('hidden');
                        videoHeader.classList.add('hidden');
                    }, 3000);
                }
            };
            
            // 替换原始的showControls函数
            showControls = enhancedShowControls;
            
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
            
            // 从URL中提取文件名，用于缓存检查
            const fileNameFromUrl = videoUrl.split('/').pop().split('?')[0];
            
            // 优先从IndexedDB获取视频
            try {
                const fileData = await indexedDBManager.getFile(videoUrl);
                if (fileData && fileData.data) {
                    // 从IndexedDB获取成功，转换为Blob URL
                    const blob = new Blob([fileData.data], { type: fileData.type });
                    const blobUrl = URL.createObjectURL(blob);
                    videoElement.src = blobUrl;
                    // 不自动播放，等待用户手动点击
                    videoElement.pause();
                    // 不显示缓存状态
                    cacheStatus.style.display = 'none';
                    return;
                }
            } catch (error) {
                // 忽略IndexedDB错误，继续尝试其他方式
                console.error('从IndexedDB获取视频失败:', error);
            }
            
            // 如果IndexedDB中没有，检查是否已经缓存（兼容旧版本）
            if (typeof isFileExpired !== 'undefined' && !isFileExpired(fileNameFromUrl, 'video')) {
                console.log('视频已缓存，直接使用URL播放');
                // 视频已缓存，直接使用URL播放，不重新缓存
                videoElement.src = videoUrl;
                // 不自动播放，等待用户手动点击
                videoElement.pause();
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
                
                // 显示顶部缓存状态条
                updateTopCacheStatus('video', videoName, 0, '0 KB/s', 0, 0);
                
                // 初始化缓存状态
                updateCacheStatus(0, 0, 0, 0, videoSize);
                
                // 缓存视频并播放
                cacheVideo(videoUrl, videoName, videoSize, videoElement, cacheStatus);
            }
        }
        
        // 更新顶部缓存状态
        function updateTopCacheStatus(type, fileName, percentage, speed, downloadedSize, totalSize) {
            const cacheStatusEl = document.getElementById('top-cache-status');
            const typeTextEl = document.getElementById('top-cache-type-text');
            const fileNameEl = document.getElementById('top-cache-file-name');
            const percentageEl = document.getElementById('top-cache-percentage');
            const progressBarEl = document.getElementById('top-cache-progress-bar');
            const speedEl = document.getElementById('top-cache-speed');
            const sizeEl = document.getElementById('top-cache-size');
            const totalSizeEl = document.getElementById('top-cache-total-size');
            
            if (type && fileName && cacheStatusEl) {
                // 更新缓存类型文本
                typeTextEl.textContent = `正在缓存${type === 'video' ? '视频' : type === 'audio' ? '音频' : '文件'}`;
                
                // 更新文件名
                fileNameEl.textContent = fileName;
                
                // 更新百分比
                percentageEl.textContent = `${percentage}%`;
                
                // 更新进度条
                progressBarEl.style.width = `${percentage}%`;
                
                // 更新速度
                speedEl.textContent = speed;
                
                // 更新大小信息
                sizeEl.textContent = formatFileSize(downloadedSize);
                totalSizeEl.textContent = formatFileSize(totalSize);
                
                // 显示缓存状态
                cacheStatusEl.style.display = 'block';
            }
        }
        
        // 完成缓存
        function completeCache(fileName, fileType, fileSize) {
            // 设置文件缓存
            setFileCache(fileName, fileType, fileSize);
            
            // 隐藏顶部缓存状态
            const cacheStatusEl = document.getElementById('top-cache-status');
            if (cacheStatusEl) {
                cacheStatusEl.style.display = 'none';
            }
            
            // 重置缓存标志
            isCaching = false;
        }
        
        // 缓存完整视频
        function cacheVideo(videoUrl, videoName, videoSize, videoElement, cacheStatus) {
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
            
            // 从URL中提取文件名，用于缓存检查和设置cookie
            const fileNameFromUrl = videoUrl.split('/').pop().split('?')[0];
            
            // 检查当前视频是否已经被缓存，避免二次缓存
            if (!isFileExpired(fileNameFromUrl, 'video')) {
                console.log('视频已缓存，直接使用缓存播放');
                // 直接使用视频URL，浏览器会自动使用缓存
                videoElement.src = videoUrl;
                // 不自动播放，等待用户手动点击
                videoElement.pause();
                // 不显示缓存状态
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
            // 设置视频源为空，确保无法播放
            videoElement.src = '';
            videoElement.load();
            
            fetch(videoUrl, {
                credentials: 'include',
                cache: 'no-cache'
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                
                // 获取视频总大小
                const totalBytes = parseInt(response.headers.get('content-length') || '0');
                console.log('视频总大小:', totalBytes);
                
                // 更新总进度
                updateCacheStatus(0, 0, 0, totalBytes, videoSize);
                
                // 更新顶部缓存状态
                updateTopCacheStatus('video', videoName, 0, '0 KB/s', 0, totalBytes);
                
                // 创建读取器
                const reader = response.body.getReader();
                const chunks = [];
                console.log('开始读取视频数据...');
                
                // 读取数据
                return reader.read().then(function processChunk({ done, value }) {
                    // 检查是否需要停止缓存
                    if (!isCaching) {
                        console.log('缓存已被用户停止');
                        // 取消读取器
                        reader.cancel();
                        // 隐藏缓存状态
                        cacheStatus.style.display = 'none';
                        // 隐藏顶部缓存状态
                        const topCacheStatus = document.getElementById('top-cache-status');
                        if (topCacheStatus) {
                            topCacheStatus.style.display = 'none';
                        }
                        // 重置缓存标志
                        isCaching = false;
                        // 保持视频源为空，确保无法播放
                        videoElement.src = '';
                        videoElement.load();
                        return;
                    }
                    
                    if (done) {
                        // 下载完成
                        const blob = new Blob(chunks, { type: 'video/mp4' });
                        const blobUrl = URL.createObjectURL(blob);
                        
                        // 设置视频源为缓存的blob URL
                        videoElement.src = blobUrl;
                        // 不自动播放，等待用户手动点击
                        videoElement.pause();
                        
                        // 更新缓存状态为100%
                        updateCacheStatus(100, 0, totalBytes, totalBytes, videoSize);
                        
                        // 更新顶部缓存状态
                        updateTopCacheStatus('video', videoName, 100, '0 KB/s', totalBytes, totalBytes);
                        
                        // 隐藏缓存状态
                        setTimeout(() => {
                            cacheStatus.style.display = 'none';
                        }, 1000);
                        
                        // 完成缓存，设置文件缓存
                        completeCache(fileNameFromUrl, 'video', totalBytes);
                        
                        return blobUrl;
                    }
                    
                    // 添加到chunks
                    chunks.push(value);
                    downloadedBytes += value.length;
                    
                    // 计算进度和速度
                    const percentage = Math.round((downloadedBytes / totalBytes) * 100);
                    const currentTime = Date.now();
                    const elapsed = (currentTime - startTime) / 1000;
                    const avgSpeed = elapsed > 0 ? Math.round(downloadedBytes / elapsed / 1024) : 0; // KB/s
                    
                    // 计算最近速度（每秒更新一次）
                    let recentSpeed = avgSpeed; // 默认使用平均速度
                    if (currentTime - lastTime >= 1000) {
                        recentSpeed = Math.round((downloadedBytes - lastDownloaded) / ((currentTime - lastTime) / 1000) / 1024);
                        lastTime = currentTime;
                        lastDownloaded = downloadedBytes;
                    }
                    
                    // 使用有效的速度值（优先使用最近速度，如果为0则使用平均速度）
                    const displaySpeed = recentSpeed > 0 ? recentSpeed : avgSpeed;
                    
                    // 更新缓存状态
                    updateCacheStatus(percentage, displaySpeed, downloadedBytes, totalBytes, videoSize);
                    
                    // 更新顶部缓存状态
                    updateTopCacheStatus('video', videoName, percentage, `${displaySpeed} KB/s`, downloadedBytes, totalBytes);
                    
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
                // 缓存失败时不回退到服务器URL，保持视频不可播放
                videoElement.src = '';
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
            
            if (cachePercentage && cacheSpeed && cacheSize && cacheTotalSize) {
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
            const topCacheStatus = document.getElementById('top-cache-status');
            if (topCacheStatus) {
                topCacheStatus.style.display = 'none';
            }
            
            // 清除视频源
            videoElement.src = '';
            
            // 隐藏视频播放器弹窗
            videoModal.classList.remove('visible');
        }
        
        // 下载当前视频
        function downloadCurrentVideo() {
            if (currentVideoUrl && currentVideoName) {
                addDownloadTask(currentVideoName, currentVideoUrl, currentVideoSize, 'video');
            }
        }
        
        // 为视频元素添加点击事件监听器
        function initVideoElements(container = document) {
            // 为已有的视频元素添加点击事件
            container.querySelectorAll('.video-element').forEach(video => {
                video.addEventListener('click', function() {
                    const videoUrl = this.src;
                    const videoName = this.getAttribute('data-file-name');
                    const videoSize = 0; // 可以从数据属性中获取，这里暂时设为0
                    openVideoPlayer(videoUrl, videoName, videoSize);
                });
            });
        }
        
        // 初始化视频元素，将视频URL转换为Blob URL
        async function initChatVideos(container = document) {
            container.querySelectorAll('.video-element').forEach(async video => {
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
                            // 创建一个错误提示元素，替换视频元素
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
                            
                            // 替换视频元素
                            video.parentNode.replaceChild(errorDiv, video);
                        }
                    }
                }
            });
        }
        
        // 录音功能相关变量
        let mediaRecorder = null;
        let audioChunks = [];
        let isRecording = false;
        let recordTimeout = null;
        
        // 初始化录音功能
        function initRecording() {
            // 为录音按钮添加点击事件
            const recordBtn = document.getElementById('record-btn');
            if (recordBtn) {
                recordBtn.addEventListener('click', toggleRecording);
            }
            
            // 按Q键开始/停止录音
            document.addEventListener('keydown', function(e) {
                // 防止在输入框中按下Q键时触发录音
                if (!e.target.matches('input, textarea')) {
                    if (e.key === 'q' || e.key === 'Q') {
                        e.preventDefault();
                        toggleRecording();
                    }
                }
            });
        }
        
        // 切换录音状态
        function toggleRecording() {
            if (!isRecording) {
                startRecording();
            } else {
                stopRecording();
            }
        }
        
        // 开始录音
        function startRecording() {
            const chatId = '<?php echo $selected_id; ?>';
            if (!chatId) {
                showNotification('请先选择聊天对象', 'error');
                return;
            }

            // 请求麦克风权限
            navigator.mediaDevices.getUserMedia({ audio: true })
                .then(stream => {
                    // 创建媒体记录器
                    mediaRecorder = new MediaRecorder(stream);
                    audioChunks = [];

                    // 录音开始事件
                    mediaRecorder.addEventListener('start', () => {
                        isRecording = true;
                        const recordBtn = document.getElementById('record-btn');
                        if (recordBtn) {
                            recordBtn.classList.add('recording');
                        }
                        
                        // 显示录音指示器和提示
                        const recordingIndicator = document.getElementById('recording-indicator');
                        const recordingHint = document.getElementById('recording-hint');
                        if (recordingIndicator) {
                            recordingIndicator.style.display = 'block';
                        }
                        if (recordingHint) {
                            recordingHint.style.display = 'block';
                        }
                        
                        showNotification('开始录音...', 'info');
                    });

                    // 数据可用事件
                    mediaRecorder.addEventListener('dataavailable', event => {
                        audioChunks.push(event.data);
                    });

                    // 录音结束事件
                    mediaRecorder.addEventListener('stop', () => {
                        // 停止所有音频轨道
                        stream.getTracks().forEach(track => track.stop());
                        
                        // 创建音频Blob
                        const audioBlob = new Blob(audioChunks, { type: 'audio/wav' });
                        
                        // 自动发送录音
                        sendRecording(audioBlob);
                    });

                    // 开始录音
                    mediaRecorder.start();
                    
                    // 1分钟自动停止录音
                    recordTimeout = setTimeout(() => {
                        stopRecording();
                    }, 60000);
                })
                .catch(error => {
                    console.error('获取麦克风权限失败:', error);
                    showNotification('获取麦克风权限失败，请检查设置', 'error');
                });
        }
        
        // 停止录音
        function stopRecording() {
            if (mediaRecorder && isRecording) {
                mediaRecorder.stop();
                isRecording = false;
                
                // 清除自动停止定时器
                if (recordTimeout) {
                    clearTimeout(recordTimeout);
                    recordTimeout = null;
                }
                
                // 恢复按钮样式
                const recordBtn = document.getElementById('record-btn');
                if (recordBtn) {
                    recordBtn.classList.remove('recording');
                }
                
                // 隐藏录音指示器和提示
                const recordingIndicator = document.getElementById('recording-indicator');
                const recordingHint = document.getElementById('recording-hint');
                if (recordingIndicator) {
                    recordingIndicator.style.display = 'none';
                }
                if (recordingHint) {
                    recordingHint.style.display = 'none';
                }
                
                showNotification('录音结束，正在发送...', 'info');
            }
        }
        
        // 发送录音

        
        // 全局变量存储当前群成员数据
        let currentGroupMembers = [];
        let currentGroupData = null;
        let currentGroupId = null;

        // 过滤群成员
        function filterGroupMembers() {
            const searchInput = document.getElementById('group-member-search');
            const keyword = searchInput.value.trim().toLowerCase();
            
            if (!currentGroupMembers || !currentGroupMembers.length) return;
            
            const filteredMembers = currentGroupMembers.filter(member => {
                const username = (member.username || '').toLowerCase();
                const nickname = (member.nickname || '').toLowerCase();
                const email = (member.email || '').toLowerCase();
                
                return username.includes(keyword) || nickname.includes(keyword) || email.includes(keyword);
            });
            
            renderGroupMembers(filteredMembers, currentGroupData, currentGroupId);
        }

        // 渲染群成员列表
        function renderGroupMembers(members, data, groupId) {
            const membersList = document.getElementById('group-members-list');
            
            if (!members || members.length === 0) {
                membersList.innerHTML = '<p style="text-align: center; color: #666;">未找到匹配的成员</p>';
                return;
            }

            // 渲染成员列表（横列显示）
            let membersHtml = '<div style="display: flex; flex-direction: column; gap: 12px;">';
            members.forEach(member => {
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
                // 修复：使用后端返回的is_owner和is_admin来判断当前用户权限
                // 当前用户是群主，或者当前用户是管理员且目标不是群主和管理员
                const canManage = data.is_owner || (data.is_admin && !member.is_owner && !member.is_admin);
                
                if (!isCurrentUser && (!data.all_user_group || canManage)) {
                    actionsMenu = '<div style="position: relative;">' +
                        '<button class="group-member-actions-btn" onclick="toggleMemberActionsMenu(event, ' + groupId + ', ' + member.id + ', ' + (member.is_admin ? 1 : 0) + ', ' + isFriend + ', \'' + member.username + '\')" style="' +
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
                    
                    // 踢出按钮 - 群主可以踢任何人(除了自己)，管理员可以踢普通成员
                    if (!data.all_user_group && (data.is_owner || (data.is_admin && !member.is_admin && !member.is_owner))) {
                        actionsMenu += '<div class="member-action-item" onclick="kickMember(' + groupId + ', ' + member.id + '); closeMemberActionsMenu(' + member.id + ')" style="' +
                            'padding: 10px 16px;' +
                            'cursor: pointer;' +
                            'font-size: 14px;' +
                            'color: var(--danger-color);' +
                            'transition: background-color 0.2s;' +
                        '">踢出</div>';
                    }
                    
                    // 设为管理员按钮 - 只有群主可以设置管理员，且目标不能是管理员或群主
                    if (!isCurrentUser && data.is_owner && !member.is_admin && !member.is_owner) {
                        actionsMenu += '<div class="member-action-item" onclick="setGroupAdmin(' + groupId + ', ' + member.id + ', true); closeMemberActionsMenu(' + member.id + ')" style="' +
                            'padding: 10px 16px;' +
                            'cursor: pointer;' +
                            'font-size: 14px;' +
                            'color: #4CAF50;' +
                            'transition: background-color 0.2s;' +
                        '">设为管理员</div>';
                    }
                    
                    // 取消管理员按钮 - 只有群主可以取消管理员
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
                        '<div style="width: 56px; height: 56px; border-radius: 50%; background: linear-gradient(135deg, #667eea 0%, #0095ff 100%); display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; font-size: 22px;">' +
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
        }

        // 显示群聊成员
        function showGroupMembers(groupId, event) {
            if (event) {
                event.stopPropagation(); // 阻止事件冒泡，避免关闭菜单
            }
            
            const modal = document.getElementById('group-members-modal');
            const membersList = document.getElementById('group-members-list');
            const searchInput = document.getElementById('group-member-search');
            
            // 判断是否是首次打开（弹窗当前是隐藏的）
            const isFirstOpen = modal.style.display === 'none' || modal.style.display === '';
            
            if (isFirstOpen) {
                // 首次打开时，清空搜索框并显示加载状态
                if (searchInput) searchInput.value = '';
                membersList.innerHTML = '<p style="text-align: center; color: #666;">加载中...</p>';
                modal.style.display = 'flex';
            }
            
            // 保存当前GroupId
            currentGroupId = groupId;
            
            // 从服务器获取群聊成员
            fetch(`get_group_members.php?group_id=${groupId}`, {
                credentials: 'include' // 包含cookie
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // 保存数据到全局变量
                    currentGroupMembers = data.members;
                    currentGroupData = data;
                    
                    // 检查当前是否有搜索内容
                    if (searchInput && searchInput.value.trim() !== '') {
                        // 如果有搜索内容，重新应用过滤
                        filterGroupMembers();
                    } else {
                        // 否则渲染完整列表
                        renderGroupMembers(currentGroupMembers, currentGroupData, currentGroupId);
                    }
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
                        // 重新加载成员列表，延迟一点时间确保数据库已更新
                        setTimeout(() => {
                            showGroupMembers(groupId);
                        }, 500);
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
                            // 刷新成员列表（showGroupMembers内部会处理搜索状态保持）
                            showGroupMembers(groupId);
                        }, 500); 
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
        
        // 歌词相关变量
        let lyricData = { lrc: [], trans: [], yrc: [] };
        let currentLyricIndex = -1;
        let hasTranslation = false;
        let hasYrc = false; // 是否有逐字歌词
        
        // 解析 YRC 逐字歌词
        function parseYrc(yrcText) {
            if (!yrcText) return [];
            
            const lines = yrcText.split('\n');
            const result = [];

            lines.forEach((line, index) => {
                // 兼容两种格式：[startTime,duration]text 和 (startTime,duration)text
                if (!line || (!line.includes('[') && !line.includes('('))) return;

                const lineData = {
                    startTime: 0, 
                    endTime: 0,
                    content: '',
                    words: []
                };

                // 正则匹配：支持 [123,456]text 和 (123,456)text 两种格式
                // 捕获组1: 开始时间, 捕获组2: 持续时间, 捕获组3: 文字
                const regex = /(?:\[|\()(\d+),(\d+)(?:\]|\))([^\[\(]+)/g;
                let match;
                let isFirstWord = true;

                while ((match = regex.exec(line)) !== null) {
                    const startTime = parseInt(match[1]) / 1000;
                    const duration = parseInt(match[2]) / 1000;
                    const text = match[3];

                    if (isFirstWord) {
                        lineData.startTime = startTime;
                        isFirstWord = false;
                    }

                    const wordEndTime = startTime + duration;
                    if (wordEndTime > lineData.endTime) {
                        lineData.endTime = wordEndTime;
                    }

                    lineData.content += text;
                    lineData.words.push({
                        time: startTime,
                        duration: duration,
                        text: text
                    });
                }

                if (lineData.words.length > 0) {
                    // 按时间排序，防止YRC数据乱序导致计算错误
                    lineData.words.sort((a, b) => a.time - b.time);

                    // 修正重叠时间：确保前一个字的结束时间不超过后一个字的开始时间
                    // 这解决了“第一个字进度慢”（实际上是时长过长覆盖了后面）的问题
                    for (let i = 0; i < lineData.words.length - 1; i++) {
                        const currentWord = lineData.words[i];
                        const nextWord = lineData.words[i + 1];
                        
                        // 计算到下一个字的理论间隔
                        const timeUntilNext = nextWord.time - currentWord.time;
                        
                        // 如果当前字的时长超过了与下一个字的间隔（重叠）
                        if (currentWord.duration > timeUntilNext) {
                            // 如果间隔是正数（正常情况），截断
                            if (timeUntilNext > 0) {
                                currentWord.duration = timeUntilNext;
                            } 
                            // 如果间隔是0或负数（同时开始或乱序），强制给一个极短时长(10ms)
                            // 这种情况如果不处理，duration会保持原值，导致进度条极慢
                            else {
                                currentWord.duration = 0.01;
                            }
                        }
                    }

                    // 再次检查第一个字：如果经过修正后，它的持续时间依然异常长（比如超过5秒，且后面还有字），
                    // 这通常意味着数据源本身有问题（例如第一字的duration包含了整句时长）
                    // 此时我们强制将其duration限制在合理范围内（例如到下一个字开始前）
                    if (lineData.words.length > 1) {
                        const firstWord = lineData.words[0];
                        const secondWord = lineData.words[1];
                        if (firstWord.duration > 5.0 && secondWord.time > firstWord.time) {
                            firstWord.duration = secondWord.time - firstWord.time;
                        }
                    }
                    
                    result.push(lineData);
                }
            });

            return result;
        }

        // 确保播放时显示歌词容器
        function showLyricContainer() {
            const lyricContainer = document.getElementById('lyric-container');
            const playerStatus = document.getElementById('player-status');
            
            if ((hasYrc || lyricData.lrc.length > 0) && isPlaying) {
                playerStatus.style.display = 'none';
                lyricContainer.style.display = 'block';
            }
        }

        // 解析歌词 (LRC)
        function parseLyric(lrcText) {
            const lines = lrcText.split('\n');
            const result = [];
            const timeExp = /\[(\d{2}):(\d{2})\.(\d{2,3})\]/;
            
            for (let line of lines) {
                const match = timeExp.exec(line);
                if (match) {
                    const minutes = parseInt(match[1]);
                    const seconds = parseInt(match[2]);
                    const milliseconds = parseInt(match[3].padEnd(3, '0'));
                    const time = minutes * 60 + seconds + milliseconds / 1000;
                    const text = line.replace(timeExp, '').trim();
                    
                    if (text) {
                        result.push({ time, text });
                    }
                }
            }
            return result;
        }
        
        // 获取歌词
        async function fetchLyrics(songId) {
            const lyricContainer = document.getElementById('lyric-container');
            const lyricContent = document.getElementById('lyric-content');
            const playerStatus = document.getElementById('player-status');
            
            // 只有正在播放且是QQ音乐时才尝试加载歌词
            if (!songId) return;
            
            try {
                const response = await fetch(`https://api.vkeys.cn/v2/music/tencent/lyric?id=${songId}`);
                const data = await response.json();
                
                if (data.code === 200 && data.data) {
                    // 解析原歌词
                    const lrc = data.data.lrc ? parseLyric(data.data.lrc) : [];
                    // 解析翻译歌词（如果有）
                    const trans = data.data.trans ? parseLyric(data.data.trans) : [];
                    // 解析逐字歌词（如果有）
                    const yrc = data.data.yrc ? parseYrc(data.data.yrc) : [];
                    
                    lyricData = { lrc, trans, yrc };
                    hasTranslation = trans.length > 0;
                    hasYrc = yrc.length > 0;
                    currentLyricIndex = -1;
                    
                    if (lrc.length > 0 || yrc.length > 0) {
                        renderLyrics();
                        // 切换显示模式：隐藏状态，显示歌词
                        playerStatus.style.display = 'none';
                        lyricContainer.style.display = 'block';
                    } else {
                        // 无歌词
                        playerStatus.style.display = 'block';
                        lyricContainer.style.display = 'none';
                    }
                }
            } catch (error) {
                console.error('Failed to fetch lyrics:', error);
                playerStatus.style.display = 'block';
                lyricContainer.style.display = 'none';
            }
        }
        
        // 渲染歌词DOM
        function renderLyrics() {
            const content = document.getElementById('lyric-content');
            content.innerHTML = '';
            content.style.transform = 'translateY(0)';
            
            // 优先使用 YRC 数据，如果没有则回退到 LRC
            const lyricsToRender = hasYrc ? lyricData.yrc : lyricData.lrc;
            
            lyricsToRender.forEach((item, index) => {
                const container = document.createElement('div');
                container.className = 'lyric-item';
                container.dataset.index = index;
                container.style.marginBottom = '12px'; // 增加行间距，确保容纳两行文字
                
                // 原文（强制显示在第一行）
                const lrcP = document.createElement('p');
                lrcP.className = 'lyric-line lrc-text';
                lrcP.style.display = 'block';
                lrcP.style.width = '100%';
                lrcP.style.margin = '0';
                lrcP.style.lineHeight = '1.4';
                
                if (hasYrc && item.words) {
                    // 如果字数大于2，启用动态效果
                    if (item.words.length > 2) {
                        container.classList.add('dynamic-effect');
                    }

                    // 逐字渲染
                    item.words.forEach(word => {
                        const span = document.createElement('span');
                        span.textContent = word.text;
                        span.className = 'yrc-word';
                        span.dataset.start = word.time;
                        span.dataset.duration = word.duration;
                        // 移除硬编码样式，使用 CSS 类
                        lrcP.appendChild(span);
                    });
                } else {
                    lrcP.textContent = item.text || item.content; // 兼容LRC和YRC的结构差异
                }
                
                container.appendChild(lrcP);
                
                // 译文（强制显示在第二行，如果有）
                if (hasTranslation) {
                    // 查找对应时间的译文
                    // YRC 的时间可能是 startTime，LRC 是 time
                    const itemTime = hasYrc ? item.startTime : item.time;
                    const transItem = lyricData.trans.find(t => Math.abs(t.time - itemTime) < 0.5);
                    
                    if (transItem) {
                        const transP = document.createElement('p');
                        transP.className = 'lyric-line trans-text';
                        transP.textContent = transItem.text;
                        transP.style.fontSize = '11px';
                        transP.style.color = '#aaa';
                        transP.style.display = 'block'; // 块级显示，确保换行
                        transP.style.width = '100%';
                        transP.style.margin = '2px 0 0 0'; // 顶部微小间距
                        transP.style.lineHeight = '1.2';
                        container.appendChild(transP);
                    }
                }
                
                content.appendChild(container);
            });
        }
        
        // 同步歌词
        function syncLyrics(currentTime) {
            // 优先使用 YRC 数据
            const lyricsList = hasYrc ? lyricData.yrc : lyricData.lrc;
            if (!lyricsList.length) return;
            
            // 找到当前播放到的歌词行
            let activeIndex = -1;
            for (let i = 0; i < lyricsList.length; i++) {
                const time = hasYrc ? lyricsList[i].startTime : lyricsList[i].time;
                if (currentTime >= time) {
                    activeIndex = i;
                } else {
                    break;
                }
            }
            
            if (activeIndex !== -1) {
                // 如果行发生了变化，更新容器高亮
                if (activeIndex !== currentLyricIndex) {
                    currentLyricIndex = activeIndex;
                    
                    // 高亮当前行容器
                    const items = document.querySelectorAll('.lyric-item');
                    items.forEach(item => {
                        // 移除容器的高亮类
                        item.classList.remove('active-container');
                        
                        // 对于普通 LRC，移除行高亮
                        if (!hasYrc) {
                            item.querySelectorAll('.lyric-line').forEach(line => line.classList.remove('active'));
                        }
                    });
                    
                    if (items[currentLyricIndex]) {
                        items[currentLyricIndex].classList.add('active-container');
                        
                        if (!hasYrc) {
                            items[currentLyricIndex].querySelectorAll('.lyric-line').forEach(line => line.classList.add('active'));
                        }
                        
                        // 居中逻辑 (复用之前的)
                        const currentEl = items[currentLyricIndex];
                        const elTop = currentEl.offsetTop;
                        const elHeight = currentEl.offsetHeight;
                        let translateY = -(elTop + elHeight / 2);
                        document.getElementById('lyric-content').style.transform = `translateY(${translateY}px)`;
                    }
                }
            }
        }

        // 逐字高亮动画循环 (requestAnimationFrame)
        function animateLyrics() {
            if (!isPlaying || !hasYrc) {
                if (isPlaying) {
                    requestAnimationFrame(animateLyrics); // 如果在播放但不是YRC，继续循环以防切换到YRC
                }
                return;
            }

            const audioPlayer = document.getElementById('audio-player');
            if (!audioPlayer) return;

            const currentTime = audioPlayer.currentTime;
            
            // 找到当前活跃的行（不用重新遍历所有行，直接用 currentLyricIndex）
            if (currentLyricIndex !== -1) {
                const activeContainer = document.querySelector(`.lyric-item[data-index="${currentLyricIndex}"]`);
                if (activeContainer) {
                    const words = activeContainer.querySelectorAll('.yrc-word');
                    words.forEach(wordSpan => {
                        const start = parseFloat(wordSpan.dataset.start);
                        const duration = parseFloat(wordSpan.dataset.duration);
                        const end = start + duration;
                        
                        if (currentTime >= end) {
                            // 已经播放完的字 - 全蓝
                            wordSpan.style.backgroundPosition = '0 0';
                            wordSpan.classList.remove('active');
                            wordSpan.classList.add('active-playing');
                        } else if (currentTime >= start && currentTime <= end + 0.1) { // 稍微放宽结束判断，防止最后一帧跳变
                            // 正在播放的字 - 动态填充
                            // 使用 Math.max(0, ...) 确保不会出现负数，防止计算误差
                            const progress = Math.max(0, (currentTime - start) / duration);
                            const percentage = Math.min(100, progress * 100);
                            
                            // 0% -> 100% 0 (全灰)
                            // 100% -> 0 0 (全蓝)
                            wordSpan.style.backgroundPosition = `${100 - percentage}% 0`;
                            
                            wordSpan.classList.add('active');
                            wordSpan.classList.add('active-playing');
                        } else if (currentTime < start) {
                            // 还没播放的字 - 全灰
                            wordSpan.style.backgroundPosition = '100% 0';
                            wordSpan.classList.remove('active');
                            wordSpan.classList.remove('active-playing');
                        }
                    });
                }
            }

            requestAnimationFrame(animateLyrics);
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
            const lastMessage = document.querySelector('.message:last-child');
            const lastMessageId = lastMessage ? lastMessage.dataset.messageId : 0;
            
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
                            // 保存到IndexedDB (异步，不阻塞渲染)
                            if (window.IDBManager) {
                                IDBManager.saveMessages([msg]).catch(console.error);
                            }
                            
                            // 检查消息是否已经存在于当前聊天中
                            const existingMessage = document.querySelector(`[data-message-id="${msg.id}"][data-chat-type="${chatType}"][data-chat-id="${chatId}"]`);
                            if (!existingMessage) {
                                // 创建消息元素
                                const messageElement = createMessageElement(msg, chatType, chatId);
                                messagesContainer.appendChild(messageElement);
                                
                                // 初始化新添加的音频播放器（局部）
                                initAudioPlayers(messageElement);
                                
                                // 初始化新添加的视频元素（局部）
                                initVideoElements(messageElement);
                                
                                // 初始化新添加的聊天视频，转换为Blob URL（局部）
                                initChatVideos(messageElement);
                                
                                // 处理新消息中的媒体文件（局部）
                                messageElement.querySelectorAll('.message-file').forEach(fileLink => {
                                    const filePath = fileLink.getAttribute('data-file-path');
                                    const fileName = fileLink.getAttribute('data-file-name');
                                    if (filePath && fileName) {
                                        const fileType = getFileType(fileName);
                                        setFileCache(filePath, fileType, 0);
                                    }
                                });
                            }
                        });
                        
                        // 滚动到底部
                        messagesContainer.scrollTop = messagesContainer.scrollHeight;
                    }
                })
                .catch(error => {
                    console.error('获取新消息失败:', error);
                });
        }
        
        // 创建消息元素
        function createMessageElement(msg, chatType, chatId = '') {
            const messageDiv = document.createElement('div');
            // 确保类型匹配，使用 == 进行比较
            const isSent = parseInt(msg.sender_id) == parseInt(<?php echo $user_id; ?>);
            messageDiv.className = `message ${isSent ? 'sent' : 'received'}`;
            messageDiv.dataset.messageId = msg.id;
            messageDiv.dataset.chatType = chatType;
            // 优先使用传入的chatId，其次是msg中的chat_id，最后使用空字符串
            messageDiv.dataset.chatId = chatId || msg.chat_id || '';
            
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
            
            // 从存储中获取文件URL（兼容旧的localStorage和新的IndexedDB）
            function getFileUrlFromLocalStorage(filePath) {
                // 检查是否是本地文件ID，支持多种前缀
                if (filePath && (filePath.startsWith('Picture_') || filePath.startsWith('Video_') || filePath.startsWith('Audio_') || filePath.startsWith('File_'))) {
                    // 对于本地文件ID，先返回一个占位符，后续会异步加载
                    return filePath;
                }
                // 直接返回原始路径
                return filePath;
            }
            
            // 异步从IndexedDB加载文件并更新DOM
            function loadFileFromIndexedDB(element, filePath, fileType) {
                // 检查是否是本地文件ID
                if (filePath && (filePath.startsWith('Picture_') || filePath.startsWith('Video_') || filePath.startsWith('Audio_') || filePath.startsWith('File_'))) {
                    getFileFromIndexedDB(filePath)
                        .then(fileData => {
                            if (fileData && fileData.data) {
                                // 根据文件类型更新不同的元素
                                if (element.tagName === 'IMG') {
                                    element.src = fileData.data;
                                } else if (element.tagName === 'AUDIO' || element.tagName === 'VIDEO') {
                                    element.src = fileData.data;
                                } else if (element.tagName === 'A') {
                                    // 对于链接元素，更新download属性和onclick事件
                                    element.setAttribute('href', fileData.data);
                                }
                            }
                        })
                        .catch(error => {
                            console.error('从IndexedDB加载文件失败：', error);
                        });
                }
            }
            
            let contentHtml;
            if (msg.type === 'file' || msg.file_path) {
                let file_path = msg.file_path;
                const file_name = msg.file_name;
                const file_size = msg.file_size;
                const file_type = msg.type;
                
                // 检查文件是否已被清理（后端返回的状态）
                const fileExists = msg.file_exists !== false; // 如果后端没返回，默认认为是存在的，兼容旧数据
                
                // 从localStorage获取文件URL
                const fileUrl = getFileUrlFromLocalStorage(file_path);
                
                // 检测文件的实际类型
                const ext = file_name.toLowerCase().split('.').pop();
                const imageExts = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
                const audioExts = ['mp3', 'wav', 'ogg', 'aac', 'wma', 'm4a', 'webm'];
                const videoExts = ['mp4', 'avi', 'mov', 'wmv', 'flv'];
                
                // 转义文件名和路径，防止XSS和语法错误
                const safeFileNameAttr = file_name.replace(/'/g, "&#39;").replace(/"/g, "&quot;");
                const safeFileNameJs = file_name.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
                const safeFilePathAttr = file_path.replace(/'/g, "&#39;").replace(/"/g, "&quot;");
                const safeFilePathJs = file_path.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
                
                if (!fileExists) {
                    // 文件不存在，显示清理提示
                    contentHtml = `<div class='message-media'>
                        <div class='file-cleaned-tip'>文件已被清理</div>
                    </div>`;
                } else if (imageExts.includes(ext)) {
                    // 图片类型
                    contentHtml = `<div class='message-media'>
                        <img src='${fileUrl}' alt='${safeFileNameAttr}' class='message-image' data-file-name='${safeFileNameAttr}' data-file-type='image' data-file-path='${safeFilePathAttr}' onerror="this.style.display='none'; this.insertAdjacentHTML('afterend', '<div class=&quot;file-cleaned-tip&quot;>文件已被清理</div>');">
                    </div>`;
                } else if (audioExts.includes(ext)) {
                    // 音频类型
                    contentHtml = `<div class='message-media' style='position: relative; overflow: visible; box-shadow: none; background: transparent;'>
                        <div class='custom-audio-player'>
                            <audio src='${fileUrl}' class='audio-element' data-file-name='${safeFileNameAttr}' data-file-type='audio' data-file-path='${safeFilePathAttr}' onerror="this.parentElement.style.display='none'; this.parentElement.insertAdjacentHTML('afterend', '<div class=&quot;file-cleaned-tip&quot;>文件已被清理</div>');"></audio>
                            <button class='audio-play-btn' title='播放'></button>
                            <div class='audio-progress-container'>
                                <div class='audio-progress-bar'>
                                    <div class='audio-progress'></div>
                                </div>
                            </div>
                            <span class='audio-time current-time'>0:00</span>
                            <span class='audio-duration'>0:00</span>
                            <!-- 音频操作按钮 - 将菜单移到外面 -->
                            <div style='position: relative; display: inline-block; margin-left: 10px; z-index: 4000;'>
                                <button class='media-action-btn' onclick="event.stopPropagation(); toggleMediaActionsMenu(event, this)" style='width: 28px; height: 28px; font-size: 14px; background: rgba(0,0,0,0.1); border: none; border-radius: 50%; color: #666; cursor: pointer; z-index: 4000; position: relative;'>⋮</button>
                                <!-- 文件操作菜单 - 移回 div 内部，确保 nextElementSibling 能找到 -->
                                <div class='file-actions-menu' style='display: none; position: absolute; top: 35px; right: 0; background: white; border-radius: 8px; box-shadow: 0 2px 12px rgba(0,0,0,0.15); padding: 8px 0; z-index: 5000; min-width: 80px;'>
                                    <button class='file-action-item' onclick="event.stopPropagation(); addDownloadTask('${safeFileNameJs}', '${safeFilePathJs}', ${file_size}, 'audio');" style='display: block; width: 100%; padding: 8px 16px; text-align: left; border: none; background: none; cursor: pointer; font-size: 14px; color: #333; transition: background-color 0.2s ease;'>下载</button>
                                </div>
                            </div>
                        </div>
                    </div>`;
                } else if (videoExts.includes(ext)) {
                    // 视频类型
                    contentHtml = `<div class='message-media' style='position: relative;'>
                        <div class='video-container' style='position: relative;'>
                            <video src='' class='video-element' data-file-name='${safeFileNameAttr}' data-file-type='video' data-file-path='${safeFilePathAttr}' data-file-url='${fileUrl}' controlsList='nodownload' playsinline onerror="this.parentElement.style.display='none'; this.parentElement.insertAdjacentHTML('afterend', '<div class=&quot;file-cleaned-tip&quot;>文件已被清理</div>');">
                            </video>
                            <!-- 视频操作按钮 - 默认隐藏，hover时显示 -->
                            <div class='media-actions' style='position: absolute; top: 10px; right: 10px; display: flex; gap: 5px; opacity: 0; transition: opacity 0.2s ease; z-index: 3000;'>
                                <div style='position: relative;'>
                                    <button class='media-action-btn' onclick="event.stopPropagation(); toggleMediaActionsMenu(event, this)" style='width: 32px; height: 32px; font-size: 16px; background: rgba(0,0,0,0.6); border: none; border-radius: 50%; color: white; cursor: pointer; display: flex; align-items: center; justify-content: center;'>⋮</button>
                                    <div class='file-actions-menu' style='display: none; position: absolute; top: 40px; right: 0; background: white; border-radius: 8px; box-shadow: 0 2px 12px rgba(0,0,0,0.15); padding: 8px 0; z-index: 3000;'>
                                        <button class='file-action-item' onclick="event.stopPropagation(); addDownloadTask('${safeFileNameJs}', '${safeFilePathJs}', ${file_size}, 'video');" style='display: block; width: 100%; padding: 8px 16px; text-align: left; border: none; background: none; cursor: pointer; font-size: 14px; color: #333; transition: background-color 0.2s ease;'>下载</button>
                                    </div>
                                </div>
                            </div>
                            <style>
                                /* 视频容器hover时显示操作按钮 */
                                .video-container:hover .media-actions {
                                    opacity: 1;
                                }
                                /* 确保视频可以直接点击播放 */
                                .video-element {
                                    pointer-events: auto;
                                }
                            </style>
                        </div>
                    </div>`;
                } else {
                // 其他文件类型
                contentHtml = `<div class='message-file' onclick="event.preventDefault(); addDownloadTask('${safeFileNameJs}', '${safeFilePathJs}', ${file_size}, 'file');">
                    <span class='file-icon' style='font-size: 24px;'>📁</span>
                    <div class='file-info' style='flex: 1;'>
                        <h4 style='margin: 0; font-size: 14px; font-weight: 500;'>${safeFileNameAttr}</h4>
                        <p style='margin: 2px 0 0 0; font-size: 12px; color: #666;'>${(file_size / 1024).toFixed(2)} KB</p>
                    </div>
                    <button style='background: #667eea; color: white; border: none; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 12px; transition: all 0.2s ease;' onclick="event.stopPropagation(); addDownloadTask('${safeFileNameJs}', '${safeFilePathJs}', ${file_size}, 'file');">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>
                    </button>
                    <img src="" onerror="this.parentElement.style.display='none'; this.parentElement.insertAdjacentHTML('afterend', '<div class=&quot;file-cleaned-tip&quot;>文件已被清理</div>');" style="display:none;" src="${fileUrl}">
                </div>`;
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
            
            // 添加消息操作菜单
            let messageActionsHtml = '';
            if (isSent) {
                // 检查消息是否在2分钟内，只有2分钟内的消息可以撤回
                // 尝试兼容多种时间格式
                let messageTime = new Date(msg.created_at.replace(/-/g, '/'));
                if (isNaN(messageTime.getTime())) {
                    messageTime = new Date(msg.created_at);
                }
                
                const now = new Date();
                const diffInMinutes = (now - messageTime) / (1000 * 60);
                
                // 生成撤回按钮HTML，只有2分钟内的消息才显示
                // 宽松判断：只要 diffInMinutes <= 2 即可（忽略负数，防止客户端时间慢于服务器时间导致无法撤回）
                const recallButtonHtml = diffInMinutes <= 2 ? `
                    <button class='message-action-item' onclick="event.stopPropagation(); recallMessage(this, '${msg.id}', '${chatType}', '${chatId}')" 
                            style='display: block; width: 100%; padding: 8px 16px; text-align: left; border: none; 
                                   background: none; cursor: pointer; font-size: 14px; color: var(--text-color); transition: background-color 0.2s ease;'>撤回</button>
                ` : '';
                
                // 生成下载按钮HTML（如果是文件类型）
                let downloadButtonHtml = '';
                if (msg.type === 'file' || msg.file_path) {
                    // 转义文件名和路径
                    const safeFileNameJs = (msg.file_name || '').replace(/\\/g, '\\\\').replace(/'/g, "\\'");
                    const safeFilePathJs = (msg.file_path || '').replace(/\\/g, '\\\\').replace(/'/g, "\\'");
                    const fileSize = msg.file_size || 0;
                    const fileType = msg.type || 'file';
                    
                    downloadButtonHtml = `
                        <button class='message-action-item' onclick="event.stopPropagation(); addDownloadTask('${safeFileNameJs}', '${safeFilePathJs}', ${fileSize}, '${fileType}');" 
                                style='display: block; width: 100%; padding: 8px 16px; text-align: left; border: none; 
                                       background: none; cursor: pointer; font-size: 14px; color: var(--text-color); transition: background-color 0.2s ease;'>下载</button>
                    `;
                }
                
                // 只有当有可用的操作按钮时才显示菜单
                if (recallButtonHtml || downloadButtonHtml) {
                    messageActionsHtml = `
                    <div class='message-actions' style='position: absolute; top: 50%; right: -10px; transform: translateY(-50%); display: flex; align-items: center; gap: 5px; z-index: 9999;'>
                        <div style='position: relative; z-index: 9999;'>
                            <button class='message-action-btn' onclick="event.stopPropagation(); toggleMessageActions(this)" 
                                    style='width: 28px; height: 28px; font-size: 18px; background: rgba(0,0,0,0.2); border: none; border-radius: 50%; 
                                           color: var(--text-color); cursor: pointer; display: flex; align-items: center; justify-content: center; opacity: 1; 
                                           transition: all 0.2s ease; position: relative; z-index: 9999;'>⋮</button>
                            <div class='message-actions-menu' style='display: none; position: absolute; top: 35px; right: 0; 
                                                             background: var(--modal-bg); border-radius: 8px; box-shadow: 0 4px 16px rgba(0,0,0,0.2); 
                                                             padding: 8px 0; z-index: 10000; min-width: 100px; border: 1px solid var(--border-color);'>
                                ${recallButtonHtml}
                                ${downloadButtonHtml}
                            </div>
                        </div>
                    </div>`;
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
            
            // 异步加载文件数据
            if (msg.type === 'file' || msg.file_path) {
                const file_path = msg.file_path;
                const file_type = msg.type;
                
                // 查找所有媒体元素并异步加载文件数据
                const imgElements = messageDiv.querySelectorAll('img[data-file-path]');
                imgElements.forEach(img => {
                    loadFileFromIndexedDB(img, img.dataset.filePath, 'image');
                });
                
                const audioElements = messageDiv.querySelectorAll('audio[data-file-path]');
                audioElements.forEach(audio => {
                    loadFileFromIndexedDB(audio, audio.dataset.filePath, 'audio');
                });
                
                const videoElements = messageDiv.querySelectorAll('video[data-file-path]');
                videoElements.forEach(video => {
                    loadFileFromIndexedDB(video, video.dataset.filePath, 'video');
                });
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
        
        // 初始化录音功能
        function initRecording() {
            // 按Q键开始/停止录音
            document.addEventListener('keydown', function(e) {
                if (e.key === 'q' || e.key === 'Q') {
                    toggleRecording();
                }
            });
        }

        // 发送录音
        function sendRecording(audioBlob) {
            const chatType = '<?php echo $chat_type; ?>';
            const chatId = '<?php echo $selected_id; ?>';
            
            if (!chatId) {
                showNotification('请先选择聊天对象', 'error');
                return;
            }

            const formData = new FormData();
            formData.append('file', audioBlob, `recording_${Date.now()}.wav`);
            formData.append('chat_type', chatType);
            if (chatType === 'friend') {
                formData.append('friend_id', chatId);
            } else {
                formData.append('id', chatId);
            }

            // 发送录音文件（使用现有的send_message.php）
            fetch('send_message.php', {
                method: 'POST',
                body: formData,
                credentials: 'include'
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('网络错误');
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    // 录音发送成功，添加到消息列表
                    const messageElement = createMessageElement(data.message, chatType, chatId);
                    const messagesContainer = document.getElementById('messages-container');
                    messagesContainer.appendChild(messageElement);
                    messagesContainer.scrollTop = messagesContainer.scrollHeight;
                    
                    // 初始化新添加的音频播放器
                    initAudioPlayers();
                } else {
                    showNotification('录音发送失败：' + (data.message || '未知错误'), 'error');
                }
            })
            .catch(error => {
                console.error('发送录音失败:', error);
                showNotification('录音发送失败：网络错误', 'error');
            });
        }

        // 页面加载完成后初始化
        document.addEventListener('DOMContentLoaded', function() {
            // 初始化视频播放器
            initVideoPlayer();
            
            // 初始化视频元素
            initVideoElements();
            
            // 初始化录音功能
            initRecording();
            
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
        
        // IndexedDB Manager with Encryption
        const IDBManager = {
            dbName: 'ChatDB',
            version: 1,
            db: null,
            key: null,

            async init() {
                // Initialize Encryption Key
                await this.initKey();
                
                return new Promise((resolve, reject) => {
                    const request = indexedDB.open(this.dbName, this.version);
                    
                    request.onupgradeneeded = (event) => {
                        const db = event.target.result;
                        if (!db.objectStoreNames.contains('messages')) {
                            const store = db.createObjectStore('messages', { keyPath: 'id' });
                            store.createIndex('chat_id', 'chat_id', { unique: false });
                            store.createIndex('chat_type', 'chat_type', { unique: false });
                        }
                        if (!db.objectStoreNames.contains('files')) {
                            db.createObjectStore('files', { keyPath: 'path' });
                        }
                    };
                    
                    request.onsuccess = (event) => {
                        this.db = event.target.result;
                        console.log("IDB Initialized");
                        resolve(this.db);
                    };
                    
                    request.onerror = (event) => reject(event.target.error);
                });
            },

            async initKey() {
                const rawKey = localStorage.getItem('chat_enc_key');
                if (rawKey) {
                    const keyData = new Uint8Array(JSON.parse(rawKey));
                    this.key = await window.crypto.subtle.importKey(
                        "raw", keyData, { name: "AES-GCM" }, true, ["encrypt", "decrypt"]
                    );
                } else {
                    this.key = await window.crypto.subtle.generateKey(
                        { name: "AES-GCM", length: 256 }, true, ["encrypt", "decrypt"]
                    );
                    const exported = await window.crypto.subtle.exportKey("raw", this.key);
                    localStorage.setItem('chat_enc_key', JSON.stringify(Array.from(new Uint8Array(exported))));
                }
            },

            async encrypt(data) {
                const iv = window.crypto.getRandomValues(new Uint8Array(12));
                const encoded = new TextEncoder().encode(JSON.stringify(data));
                const encrypted = await window.crypto.subtle.encrypt(
                    { name: "AES-GCM", iv: iv }, this.key, encoded
                );
                return { iv: Array.from(iv), data: Array.from(new Uint8Array(encrypted)) };
            },

            async decrypt(encryptedObj) {
                const iv = new Uint8Array(encryptedObj.iv);
                const data = new Uint8Array(encryptedObj.data);
                const decrypted = await window.crypto.subtle.decrypt(
                    { name: "AES-GCM", iv: iv }, this.key, data
                );
                return JSON.parse(new TextDecoder().decode(decrypted));
            },

            async saveMessages(messages) {
                if (!messages || !messages.length) return;
                const tx = this.db.transaction(['messages'], 'readwrite');
                const store = tx.objectStore('messages');
                
                for (const msg of messages) {
                    // Encrypt content before saving
                    const encryptedContent = await this.encrypt(msg);
                    // Store encrypted wrapper, but keep ID and chat info clear for indexing
                    store.put({
                        id: msg.id,
                        chat_id: msg.chat_id,
                        chat_type: msg.chat_type || 'friend', // Assuming default
                        timestamp: msg.created_at,
                        encrypted: encryptedContent
                    });
                }
            },

            async getMessage(id) {
                return new Promise((resolve, reject) => {
                    const tx = this.db.transaction(['messages'], 'readonly');
                    const store = tx.objectStore('messages');
                    const request = store.get(id);
                    request.onsuccess = async () => {
                        if (request.result) {
                            try {
                                const decrypted = await this.decrypt(request.result.encrypted);
                                resolve(decrypted);
                            } catch (e) {
                                console.error("Decryption failed", e);
                                resolve(null);
                            }
                        } else {
                            resolve(null);
                        }
                    };
                    request.onerror = () => reject(request.error);
                });
            },
            
            async saveFile(path, blob) {
                // For files, we encrypt the Blob
                const arrayBuffer = await blob.arrayBuffer();
                const iv = window.crypto.getRandomValues(new Uint8Array(12));
                const encrypted = await window.crypto.subtle.encrypt(
                    { name: "AES-GCM", iv: iv }, this.key, arrayBuffer
                );
                
                const tx = this.db.transaction(['files'], 'readwrite');
                const store = tx.objectStore('files');
                store.put({
                    path: path,
                    iv: Array.from(iv),
                    data: Array.from(new Uint8Array(encrypted)),
                    type: blob.type
                });
            },
            
            async getFile(path) {
                return new Promise((resolve, reject) => {
                    const tx = this.db.transaction(['files'], 'readonly');
                    const store = tx.objectStore('files');
                    const request = store.get(path);
                    request.onsuccess = async () => {
                        if (request.result) {
                            try {
                                const iv = new Uint8Array(request.result.iv);
                                const data = new Uint8Array(request.result.data);
                                const decrypted = await window.crypto.subtle.decrypt(
                                    { name: "AES-GCM", iv: iv }, this.key, data
                                );
                                resolve(new Blob([decrypted], { type: request.result.type }));
                            } catch (e) {
                                console.error("File decryption failed", e);
                                resolve(null);
                            }
                        } else {
                            resolve(null);
                        }
                    };
                    request.onerror = () => reject(request.error);
                });
            }
        };

        // Initialize DB on load
        document.addEventListener('DOMContentLoaded', () => {
            IDBManager.init().catch(console.error);
        });

        // Override getFileFromIndexedDB to use our new manager
        async function getFileFromIndexedDB(filePath) {
            try {
                if (!IDBManager.db) await IDBManager.init();
                const blob = await IDBManager.getFile(filePath);
                if (blob) {
                    return { data: URL.createObjectURL(blob) };
                }
            } catch (e) {
                console.error("Get file error", e);
            }
            return null;
        }

        // Helper to cache file if not exists
        async function setFileCache(filePath, fileType, size) {
             // Logic: Check if in DB, if not, fetch and save
             if (!IDBManager.db) await IDBManager.init();
             const existing = await IDBManager.getFile(filePath);
             if (!existing) {
                 // Fetch from server
                 try {
                     const response = await fetch(filePath);
                     if (response.ok) {
                         const blob = await response.blob();
                         await IDBManager.saveFile(filePath, blob);
                     }
                 } catch (e) {
                     console.error("Cache file error", e);
                 }
             }
        }

        // 定期获取新消息
        setInterval(getNewMessages, 3000);
        
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

        // 切换媒体操作菜单
        function toggleMediaActionsMenu(event, button) {
            event.stopPropagation();
            const menu = button.nextElementSibling;
            menu.style.display = menu.style.display === 'block' ? 'none' : 'block';
        }

        // 撤回消息

        
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
            .then(data => {                if (!data.success) {
                    console.error('标记消息为已读失败:', data.message);
                } else {
                    // 更新当前聊天项的未读角标
                    let chatItem;
                    if (chatType === 'friend') {
                        chatItem = document.querySelector(`.chat-item[data-friend-id="${selectedId}"]`);
                    } else {
                        chatItem = document.querySelector(`.chat-item[data-group-id="${selectedId}"]`);
                    }
                    
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
                background: white;
                border-radius: 12px;
                width: 90%;
                max-width: 500px;
                max-height: 80vh;
                overflow: hidden;
            `;

            // 弹窗标题
            const modalHeader = document.createElement('div');
            modalHeader.style.cssText = `
                padding: 20px;
                border-bottom: 1px solid #e0e0e0;
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
                    color: #666;
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
            modalBody.innerHTML = '<div style="text-align: center; padding: 20px; color: #666;">加载好友列表中...</div>';
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
                                        border-bottom: 1px solid #f0f0f0;
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
                                                <h4 style="margin: 0 0 4px 0; font-size: 14px; font-weight: 600;">${friend.username}</h4>
                                                <p style="margin: 0; font-size: 12px; color: #666;">${friend.status === 'online' ? '在线' : '离线'}</p>
                                            </div>
                                        </div>
                                        <div>
                                            ${friend.in_group ? 
                                                '<span style="color: #666; font-size: 14px; padding: 6px 12px; background: #f0f0f0; border-radius: 16px;">用户已存在</span>' : 
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
                            friendsHTML = '<div style="text-align: center; padding: 20px; color: #666;">没有可用的好友可以邀请</div>';
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
        // 退出群聊
        function leaveGroup(groupId) {
            if (confirm('确定要退出该群聊吗？')) {
                fetch(`leave_group.php?group_id=${groupId}`, {
                    method: 'POST'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('已成功退出群聊');
                        window.location.href = 'chat.php';
                    } else {
                        alert(`退出失败：${data.message}`);
                    }
                })
                .catch(error => {
                    console.error('退出群聊失败:', error);
                    alert('退出失败：网络错误');
                });
            }
        }
        
        // 解散群聊
        function deleteGroup(groupId) {
            if (confirm('确定要解散该群聊吗？此操作不可恢复！')) {
                fetch(`delete_group.php?group_id=${groupId}`, {
                    method: 'POST'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('群聊已成功解散');
                        window.location.href = 'chat.php';
                    } else {
                        alert(`解散失败：${data.message}`);
                    }
                })
                .catch(error => {
                    console.error('解散群聊失败:', error);
                    alert('解散失败：网络错误');
                });
            }
        }

        // 转让群主
        function transferGroupOwnership(groupId) {
            // 创建并显示转让群主弹窗
            const modalId = 'transfer-ownership-modal';
            let modal = document.getElementById(modalId);
            
            if (!modal) {
                modal = document.createElement('div');
                modal.id = modalId;
                modal.className = 'modal';
                modal.style.cssText = `
                    display: flex;
                    position: fixed;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                    background: rgba(0, 0, 0, 0.5);
                    z-index: 2000;
                    justify-content: center;
                    align-items: center;
                `;
                document.body.appendChild(modal);
            }
            
            modal.innerHTML = `
                <div class="modal-content" style="background: var(--modal-bg); color: var(--text-color); width: 400px; max-width: 90%; border-radius: 12px; overflow: hidden; display: flex; flex-direction: column; max-height: 80vh;">
                    <div style="padding: 15px 20px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
                        <h3 style="margin: 0; font-size: 18px;">转让群主</h3>
                        <button onclick="document.getElementById('${modalId}').remove()" style="background: none; border: none; color: var(--text-secondary); font-size: 24px; cursor: pointer;">×</button>
                    </div>
                    <div id="transfer-members-list" style="padding: 20px; overflow-y: auto; flex: 1;">
                        <p style="text-align: center; color: var(--text-desc);">加载成员中...</p>
                    </div>
                </div>
            `;
            
            // 加载群成员
            fetch(`get_group_members.php?group_id=${groupId}`)
                .then(response => response.json())
                .then(data => {
                    const container = document.getElementById('transfer-members-list');
                    if (data.success) {
                        if (data.members && data.members.length > 1) { // 只有自己不算
                            let html = '<p style="margin-bottom: 15px; font-size: 14px; color: var(--text-desc);">请选择一位成员作为新群主：</p>';
                            html += '<div style="display: flex; flex-direction: column; gap: 10px;">';
                            
                            let hasCandidates = false;
                            data.members.forEach(member => {
                                // 排除自己（假设没有is_owner字段，通过其他方式判断，或者后端已经过滤，或者前端点击自己没反应）
                                // 这里假设后端返回的members包含所有成员
                                // 我们可以简单地列出所有人，如果是自己，点击时提示，或者在confirmTransferOwnership中处理
                                // 更严谨的是排除当前用户。但JS里没有currentUserId，除非从全局变量拿。
                                // 通常 get_group_members 返回包含 is_owner 字段。
                                
                                // 检查是否是当前用户 (假设全局有 current_user_id 变量，或者通过 is_owner 判断)
                                // 如果 member.is_owner 为 true，则是自己，跳过
                                if (!member.is_owner) {
                                    hasCandidates = true;
                                    const avatar = member.avatar && member.avatar !== 'default_avatar.png' 
                                        ? `<img src="${member.avatar}" style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover;">`
                                        : `<div style="width: 40px; height: 40px; border-radius: 50%; background: #3498db; color: white; display: flex; align-items: center; justify-content: center; font-weight: bold;">${member.username.substring(0, 2)}</div>`;
                                        
                                    html += `
                                        <div onclick="confirmTransferOwnership(${groupId}, ${member.id}, '${member.username}')" style="display: flex; align-items: center; padding: 10px; border: 1px solid var(--border-color); border-radius: 8px; cursor: pointer; transition: background 0.2s;" onmouseover="this.style.background='var(--hover-bg)'" onmouseout="this.style.background='transparent'">
                                            <div style="margin-right: 12px;">${avatar}</div>
                                            <div>
                                                <div style="font-weight: 600;">${member.username}</div>
                                                <div style="font-size: 12px; color: var(--text-desc);">${member.email || ''}</div>
                                            </div>
                                            <div style="margin-left: auto; color: var(--text-desc);">➡</div>
                                        </div>
                                    `;
                                }
                            });
                            html += '</div>';
                            
                            if (!hasCandidates) {
                                container.innerHTML = '<p style="text-align: center; color: var(--text-desc);">群里只有你自己，无法转让。</p>';
                            } else {
                                container.innerHTML = html;
                            }
                        } else {
                            container.innerHTML = '<p style="text-align: center; color: var(--text-desc);">群里没有其他成员，无法转让。</p>';
                        }
                    } else {
                        container.innerHTML = `<p style="text-align: center; color: #ff4757;">加载失败: ${data.message}</p>`;
                    }
                })
                .catch(error => {
                    console.error('加载成员失败:', error);
                    document.getElementById('transfer-members-list').innerHTML = '<p style="text-align: center; color: #ff4757;">加载失败: 网络错误</p>';
                });
        }
        
        // 确认转让
        function confirmTransferOwnership(groupId, newOwnerId, username) {
            if (confirm(`确定要将群主转让给 ${username} 吗？此操作不可撤销，您将变为普通成员。`)) {
                fetch(`transfer_ownership.php?group_id=${groupId}&new_owner_id=${newOwnerId}`, {
                    method: 'POST'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(`已成功将群主转让给 ${username}`);
                        window.location.reload();
                    } else {
                        alert(`转让失败：${data.message}`);
                    }
                })
                .catch(error => {
                    console.error('转让失败:', error);
                    alert('转让失败：网络错误');
                });
            }
        }
    </script>
    <!-- 音乐播放器 -->
    <?php if (getConfig('Random_song', false)): ?>
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
            right: -10px;
            bottom: 45px; /* 调整位置到按钮上方 */
            top: auto; /* 取消 top 定位 */
            background: var(--panel-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 12px 10px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3);
            z-index: 1001;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
            backdrop-filter: blur(10px);
        }
        
        #volume-slider {
            width: 6px;
            height: 120px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 3px;
            cursor: pointer;
            position: relative;
        }
        
        #volume-level {
            width: 100%;
            background: linear-gradient(to top, #667eea 0%, #0095ff 100%); /* 蓝色渐变 */
            border-radius: 3px;
            transition: height 0.1s ease;
            position: absolute;
            bottom: 0;
            left: 0;
            height: 80%; /* 默认音量80% */
        }
        
        /* 音量增减按钮 */
        .volume-btn {
            width: 32px;
            height: 32px;
            border: none;
            background: var(--input-bg);
            color: var(--text-color);
            border-radius: 50%;
            cursor: pointer;
            font-size: 20px;
            font-weight: 900;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }
        
        .volume-btn:hover {
            background: #0095ff;
            color: white;
            transform: scale(1.1);
            box-shadow: 0 2px 8px rgba(0, 149, 255, 0.4);
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
            background: var(--panel-bg);
            border-radius: 20px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid var(--border-color);
            color: var(--text-color);
        }
        
        #music-player.minimized #player-header {
            display: none;
        }
        
        #player-header {
            padding: 10px 15px;
            background: #1976d2; /* 普遍的蓝色 */
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
            background: #1976d2; /* 普遍的蓝色 */
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
        
        #music-player.minimized #song-info,
        #music-player.minimized #player-status,
        #music-player.minimized #playlist-container {
            display: none !important;
            width: 0 !important;
            height: 0 !important;
            opacity: 0 !important;
            visibility: hidden !important;
            position: absolute !important;
            z-index: -1 !important;
        }
        
        /* 缩小状态下播放控制的布局 */
        #music-player.minimized #player-content {
            display: flex;
            align-items: center;
            justify-content: space-between; /* 两端对齐，但因为有 flex-grow 的进度条，实际上会铺满 */
            gap: 10px;
            padding: 8px 12px;
            height: 100%;
            flex-wrap: nowrap; /* 强制不换行 */
            overflow: hidden; /* 防止溢出 */
        }
        
        #music-player.minimized #player-content > *:not(#song-info):not(#progress-song-info):not(#player-status):not(#playlist-container) {
            display: flex; /* 确保所有直接子元素都显示 */
            flex-shrink: 0; /* 防止元素被压缩（除了进度条） */
        }
        
        #music-player.minimized #minimized-actions {
            display: flex !important;
        }

        /* 隐藏旧的绝对定位按钮 */
        #music-player.minimized #minimized-toggle-container,
        #music-player.minimized #mini-toggle-btn {
            display: none !important;
        }
        
        /* 缩小状态下只显示必要的控制按钮 */
        #music-player.minimized #player-controls {
            display: flex !important;
            align-items: center;
            gap: 12px;
            margin: 0;
            order: 2; /* 放在中间 */
            visibility: visible !important;
            opacity: 1 !important;
        }
        
        #music-player.minimized #prev-btn,
        #music-player.minimized #next-btn {
            display: none !important;
        }

        /* 确保下载按钮显示 */
        #music-player.minimized #download-btn {
            display: flex !important;
            visibility: visible !important;
            opacity: 1 !important;
            width: 32px !important;
            height: 32px !important;
        }
        
        #music-player.minimized #volume-container {
            display: flex !important;
            align-items: center;
        }

        /* 缩小状态下进度条 */
        #music-player.minimized #progress-container {
            flex: 1; /* 自动占据剩余空间 */
            flex-shrink: 1; /* 允许缩小 */
            margin: 0 10px; /* 左右留出间距 */
            position: relative;
            height: 4px;
            background: rgba(0,0,0,0.1);
            order: 3; /* 放在右侧 */
            border-radius: 2px;
            min-width: 40px; /* 最小宽度，防止彻底消失 */
            display: block !important;
            visibility: visible !important;
            opacity: 1 !important;
        }

        #music-player.minimized #progress-bar {
            height: 100%;
            background: transparent;
        }

        #music-player.minimized #progress {
            height: 100%;
            border-radius: 2px;
        }

        /* 缩小状态下的专辑图片位置 */
        #music-player.minimized #album-art {
            width: 36px !important;
            height: 36px !important;
            flex-shrink: 0;
            margin: 0;
            order: 1; /* 放在最左侧 */
            border: 2px solid white;
            box-shadow: 0 2px 6px rgba(0,0,0,0.1);
            display: block !important;
            visibility: visible !important;
            opacity: 1 !important;
        }

        /* 统一控制按钮样式 */
        #music-player.minimized .control-btn {
            width: 32px !important;
            height: 32px !important;
            font-size: 14px !important;
            background: #1976d2 !important;
            color: white !important;
            box-shadow: none !important;
            display: flex !important;
            visibility: visible !important;
            opacity: 1 !important;
        }

        #music-player.minimized #play-btn {
            width: 36px !important;
            height: 36px !important;
            font-size: 16px !important;
            display: flex !important;
        }
        
        /* 迷你模式右侧操作按钮容器 */
        #music-player.minimized #minimized-actions {
            display: flex !important;
            flex-direction: column;
            gap: 4px;
            margin-left: 5px;
            order: 4; /* 放在最右侧 */
            flex-shrink: 0;
        }

        /* 迷你模式（侧边栏模式） */
        #music-player.mini-minimized {
            width: 40px !important;
            height: 40px !important;
            border-radius: 20px !important;
            padding: 0 !important;
            overflow: hidden !important;
            right: 0 !important;
            left: auto !important;
            top: auto !important;
            bottom: 100px !important; /* 调整位置 */
            transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            background: #fff;
            box-shadow: -2px 2px 10px rgba(0,0,0,0.1);
            cursor: pointer;
            z-index: 10000;
        }

        /* 迷你模式下隐藏所有内容，只显示一个图标 */
        #music-player.mini-minimized #player-content,
        #music-player.mini-minimized #minimized-actions {
            display: none !important;
        }

        /* 添加一个恢复按钮 */
        #music-player.mini-minimized::after {
            content: "🎵"; /* 音乐图标 */
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            animation: spin 4s linear infinite; /* 旋转动画 */
        }
        
        @keyframes spin { 
            100% { transform: rotate(360deg); } 
        }

        #music-player.minimized .action-btn {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            border: none;
            background: rgba(0, 0, 0, 0.05);
            color: #666;
            cursor: pointer;
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            font-weight: bold;
            position: relative;
            z-index: 10002;
            pointer-events: auto;
        }

        #music-player.minimized .action-btn:hover {
            background: rgba(0, 0, 0, 0.1);
            color: #1976d2;
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
            background: #1976d2; /* 普遍的蓝色 */
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
        
        /* 缩小状态下不显示歌曲信息 */
        #music-player.minimized #progress-song-info {
            display: none !important;
            opacity: 0;
            visibility: hidden;
            width: 0;
            height: 0;
            margin: 0;
            padding: 0;
            position: absolute;
        }

        /* 修复按钮遮挡问题 - 强制显示并提高层级 */
        #music-player.minimized #minimized-actions {
            display: flex !important;
            flex-direction: column;
            gap: 4px;
            margin-left: 10px;
            order: 4; /* 放在最右侧 */
            position: absolute; /* 确保不被遮挡 */
            right: 15px; /* 增加右边距，避免贴边 */
            top: 50%;
            transform: translateY(-50%);
            z-index: 99999; /* 极大值 */
            opacity: 1 !important;
            visibility: visible !important;
            background: transparent !important;
            pointer-events: auto !important;
        }

        #music-player.minimized .action-btn {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            border: none;
            background: rgba(0, 0, 0, 0.1); /* 加深背景色 */
            color: #666;
            cursor: pointer;
            font-size: 14px;
            display: flex !important; /* 强制显示 */
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            font-weight: bold;
            z-index: 100000;
        }

        #music-player.minimized .action-btn:hover {
            background: rgba(0, 0, 0, 0.2);
            color: #1976d2;
        }

        /* 确保控制按钮不会过大挤占空间 */
        #music-player.minimized .control-btn {
            width: 32px;
            height: 32px;
            min-width: 32px; /* 防止被压缩 */
            font-size: 14px;
        }

        /* 隐藏旧的重叠按钮 */
        #music-player.minimized #minimized-toggle-container,
        #music-player.minimized #mini-toggle-btn {
            display: none !important;
        }
        
        #progress {
            height: 100%;
            background: linear-gradient(90deg, #667eea 0%, #0095ff 100%);
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
            background: rgba(30, 30, 30, 0.95);
            padding: 12px 10px;
            border-radius: 12px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
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
            padding: 12px 10px !important;
            border-radius: 12px !important;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3) !important;
            backdrop-filter: blur(10px) !important;
            border: 1px solid var(--border-color) !important;
            display: flex !important;
            flex-direction: column !important;
            align-items: center !important;
            gap: 12px !important;
        }
        
        /* 确保音量按钮能正确触发事件 */
        #volume-btn {
            position: relative;
            z-index: 1002;
        }
        
        #lyric-container {
            height: 80px; /* 增加高度以容纳更多行 */
            overflow: hidden;
            margin-top: 10px;
            text-align: center;
            position: relative;
            display: none;
            /* 移除 mask-image 渐变，因为用户要求不显示已播放歌词 */
            /* mask-image: linear-gradient(to bottom, transparent, black 15%, black 85%, transparent); */
            /* -webkit-mask-image: linear-gradient(to bottom, transparent, black 15%, black 85%, transparent); */
        }
        
        #lyric-content {
            transition: transform 0.3s ease-out;
            position: absolute;
            width: 100%;
            top: 50%; /* 初始位置居中 */
        }
        
        .lyric-item {
            opacity: 0; /* 默认完全隐藏 */
            transition: all 0.3s;
            transform: scale(0.95);
            display: none; /* 默认不显示，只显示当前行 */
        }
        
        .lyric-item.active-container {
            opacity: 1;
            transform: scale(1);
            display: block; /* 只显示当前行 */
        }
        
        .lyric-line {
            color: #888;
            font-size: 13px;
            /* height: auto; 移除固定高度 */
            /* line-height: 1.4; */
            transition: all 0.3s;
            margin: 0;
            white-space: normal; /* 允许换行 */
            overflow: visible; /* 允许溢出 */
            text-overflow: clip;
        }
        
        .lyric-line.active {
            color: #1989fa;
            font-weight: bold;
            font-size: 13px;
            transform: scale(1.05);
        }
        
        /* 状态信息 */
        #player-status {
            font-size: 12px;
            color: #999;
            text-align: center;
            margin-top: 10px;
        }
        
        #playlist-select {
            width: 100%;
            padding: 4px;
            border-radius: 4px;
            border: 1px solid var(--border-color);
            font-size: 12px;
            background: var(--input-bg);
            color: var(--text-color);
            outline: none;
        }
        
        #player-header span {
            color: var(--text-color);
            font-weight: bold;
        }
        
        #player-toggle {
            background: none;
            border: none;
            color: var(--text-color);
            font-size: 20px;
            cursor: pointer;
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
            background: #1976d2; /* 普遍的蓝色 */
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
            background: linear-gradient(135deg, #667eea 0%, #0095ff 100%) !important;
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
        
        /* 确保小窗模式下+按钮和下载按钮正确显示 */
        #music-player.minimized #minimized-toggle-container {
            display: block;
            position: absolute;
            top: 5px;
            right: 5px;
            width: 25px;
            height: auto;
            font-size: 16px;
            background: rgba(0, 0, 0, 0.2);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 12px;
            cursor: pointer;
            z-index: 1001;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 3px;
            gap: 2px;
            transition: all 0.2s ease;
        }
        
        #music-player.minimized #minimized-toggle-container:hover {
            background: rgba(0, 0, 0, 0.4);
        }
        
        #music-player.minimized #minimized-toggle-container button {
            color: white;
            font-weight: bold;
        }

        /* 动态歌词效果 */
        .yrc-word {
            display: inline-block;
            background-image: linear-gradient(to right, #1989fa 50%, #aaa 50%);
            background-size: 200% 100%;
            background-position: 100% 0; /* 初始全灰 */
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
            color: transparent; /* Fallback */
            transition: transform 0.2s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            will-change: background-position, transform;
            margin: 0 1px;
            font-weight: bold; /* 加粗效果更好 */
        }

        /* 长句（>2字）的激活状态会有缩放效果 */
        .lyric-item.dynamic-effect .yrc-word.active {
            transform: scale(1.3) translateY(-2px);
        }
        
        /* 正在播放的字添加发光滤镜 */
        .lyric-item.dynamic-effect .yrc-word.active-playing {
             filter: drop-shadow(0 0 3px rgba(25, 137, 250, 0.6));
        }
    </style>
    
    <div id="music-player" style="display: none;">
        <!-- 播放器头部 -->
        <div id="player-header">
            <span>音乐播放器</span>
            <button id="player-toggle" onclick="event.stopPropagation(); togglePlayer();">-</button>
        </div>
        
        <!-- 缩小状态下的切换按钮 -->
        <div style="display: none; position: absolute; top: 20px; right: 15px; z-index: 1001;" id="minimized-toggle-container">
            <button onclick="event.stopPropagation(); togglePlayer();" style="width: 20px; height: 20px; font-size: 14px; background: none; border: none; cursor: pointer; color: #666; padding: 0; margin: 0;">+</button>
        </div>
        
        <!-- 迷你模式切换按钮 (已删除) -->
        
        <!-- 小窗模式右侧操作按钮组 -->
        <!-- 移除这里的冗余定义 -->
        
        <!-- 播放器内容 -->
        <div id="player-content">
            <!-- 专辑图片 -->
            <div id="album-art">
                <img id="album-image" src="" alt="Album Art">
            </div>
            
            <!-- 歌曲信息 -->
            <div id="song-info">
                <h3 id="song-title">加载中...</h3>
                <p id="artist-name"></p>
            </div>
            
            <!-- 歌单选择 -->
            <div id="playlist-container" style="padding: 0 15px 10px 15px;">
                <select id="playlist-select" onchange="changePlaylist(this.value)" style="<?php if ($is_radio_period || ($is_spring_festival_period && !$is_admin)) echo 'cursor: not-allowed; opacity: 0.9; pointer-events: none;'; ?>">
                    <?php if ($is_radio_period): ?>
                    <!-- 电台时间段，只显示歌单歌单 -->
                    <option value="custom_歌单" selected>歌单</option>
                    <?php else: ?>
                    <?php if ($is_spring_festival_period && !$is_admin): ?>
                    <option value="spring_festival" selected>春节特别歌单</option>
                    <?php else: ?>
                    <?php if ($is_spring_festival_period): ?>
                    <option value="spring_festival">春节特别歌单</option>
                    <?php endif; ?>
                    <option value="random">随机热歌 (默认)</option>
                    
                    <?php
                    // 读取自定义歌单
                    $song_config_file = __DIR__ . '/config/song_config.json';
                    if (file_exists($song_config_file)) {
                        $custom_playlists = json_decode(file_get_contents($song_config_file), true);
                        if ($custom_playlists) {
                            foreach ($custom_playlists as $pl_name => $pl_settings) {
                                echo '<option value="custom_' . htmlspecialchars($pl_name) . '">' . htmlspecialchars($pl_name) . '</option>';
                            }
                        }
                    }
                    ?>
                    <?php endif; ?>
                    <?php endif; ?>
                </select>
            </div>
            
            <!-- 播放控制 -->
            <div id="player-controls">
                <button class="control-btn" id="prev-btn" onclick="playPrevious()" title="上一首">⏮</button>
                <button class="control-btn" id="play-btn" onclick="togglePlay()" title="播放/暂停">▶</button>
                <button class="control-btn" id="next-btn" onclick="playNext()" title="下一首">⏭</button>
                <div id="volume-container">
                    <button class="control-btn" id="volume-btn" onclick="toggleVolumeControl()" title="音量">🔊</button>
                    <!-- 新的音量调节UI -->
                    <div id="volume-control" style="display: none;">
                        <button class="volume-btn" id="volume-up" onclick="adjustVolumeByStep(0.1)" title="增大音量">+</button>
                        <div id="volume-slider" onclick="adjustVolume(event)">
                            <div id="volume-level"></div>
                        </div>
                        <button class="volume-btn" id="volume-down" onclick="adjustVolumeByStep(-0.1)" title="减小音量">-</button>
                    </div>
                </div>
                <button class="control-btn" id="download-btn" onclick="downloadMusic()" title="下载">⬇</button>
            </div>
            
            <!-- 进度条 -->
            <div id="progress-container">
                <!-- 歌曲信息显示 -->
                <div id="progress-song-info" style="font-size: 12px; color: #666; margin-bottom: 5px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"></div>
                <div id="progress-bar" onclick="seek(event)">
                    <div id="progress"></div>
                </div>
                <div id="time-display">
                    <span id="current-time">0:00</span>
                    <span id="duration">0:00</span>
                </div>
            </div>

            <!-- 放在进度条后面，确保在 flex 布局中的顺序 -->
        <!-- 小窗模式右侧操作按钮组 -->
        <div id="minimized-actions" style="display: none;">
            <button class="action-btn" onclick="event.stopPropagation(); togglePlayer();" title="恢复大窗">+</button>
            <button class="action-btn" onclick="event.stopPropagation(); toggleMiniMode(event)" title="切换侧边栏模式">&lt;</button>
        </div>
            
            <!-- 歌词容器 -->
            <div id="lyric-container">
                <div id="lyric-content">
                    <p class="lyric-line">暂无歌词</p>
                </div>
            </div>

            <!-- 状态信息 -->
            <div id="player-status">正在加载音乐...</div>
        </div>
        
        <!-- 隐藏的音频元素 -->
        <audio id="audio-player" preload="metadata"></audio>
    </div>
    
    <script>
        // 全局变量
        const IS_ADMIN = <?php echo $is_admin ? 'true' : 'false'; ?>;
        const IS_SPRING_FESTIVAL_PERIOD = <?php echo $is_spring_festival_period ? 'true' : 'false'; ?>;
        const IS_RADIO_PERIOD = <?php echo $is_radio_period ? 'true' : 'false'; ?>;
        
        let currentSong = null;
        let isPlaying = false;
        let isMinimized = false;
        let isMiniMinimized = false;
        let isPlayerDragging = false;
        let playerStartX = 0;
        let playerStartY = 0;
        let initialX = 0;
        let initialY = 0;
        
        // 音乐模式变量
        let currentMusicMode = 'random'; // 当前音乐模式：'random' 或 'spring_festival'
        
        // 春节歌单相关变量
        let springFestivalPlaylist = [];
        let springFestivalCurrentIndex = 0;
        
        // 格式化时间显示（秒 -> mm:ss）
        function formatTime(seconds) {
            if (isNaN(seconds)) return '0:00';
            
            const mins = Math.floor(seconds / 60);
            const secs = Math.floor(seconds % 60);
            return `${mins}:${secs.toString().padStart(2, '0')}`;
        }
        
        // 页面加载完成后初始化音乐播放器
        window.addEventListener('load', async () => {
            let musicPlayerSetting = false; // 默认关闭
            
            // 如果在春节期间，强制启用播放器和春节模式
            if (IS_SPRING_FESTIVAL_PERIOD && !IS_ADMIN) {
                // 强制设置为春节模式
                currentMusicMode = 'spring_festival';
                localStorage.setItem('setting-music-mode', 'spring_festival');
                localStorage.setItem('music_mode', 'spring_festival'); // 确保音乐播放器也使用此设置
                
                const modeSelect = document.getElementById('setting-music-mode');
                if (modeSelect) {
                    modeSelect.value = 'spring_festival';
                    // modeSelect.disabled = true; // 移除 disabled，改用 CSS 控制
                    modeSelect.style.pointerEvents = 'none';
                    modeSelect.style.opacity = '0.9';
                    modeSelect.style.cursor = 'not-allowed';
                    
                    // 添加提示（如果还未添加）
                    const wrapper = modeSelect.parentElement;
                    if (wrapper && !wrapper.querySelector('.spring-festival-tip')) {
                        const tip = document.createElement('div');
                        tip.className = 'spring-festival-tip';
                        tip.textContent = '春节期间限定歌单';
                        tip.style.cssText = 'color: #ff4d4f; font-size: 12px; margin-top: 4px;';
                        wrapper.appendChild(tip);
                    }
                }
                
                // 强制开启播放器（可选，如果不希望强制开启播放器可移除此行，但需求说“显示春节歌曲”，暗示需要能看到播放器或至少设置里是开启的）
                // musicPlayerSetting = true; 
                // 保持用户对是否开启播放器的选择权，但一旦开启，只能听春节歌单
            }
            
            try {
                // 检查indexedDBManager是否已经初始化
                if (typeof indexedDBManager !== 'undefined') {
                    try {
                        // 从IndexedDB加载设置
                        const settings = await indexedDBManager.getSettings();
                        // 如果未设置过，默认关闭
                        musicPlayerSetting = settings['setting-music-player'] === true;
                    } catch (error) {
                        // 降级到localStorage
                        // 如果未设置过，默认关闭
                        musicPlayerSetting = localStorage.getItem('setting-music-player') === 'true';
                    }
                } else {
                    // indexedDBManager未初始化，使用localStorage
                    // 如果未设置过，默认关闭
                    musicPlayerSetting = localStorage.getItem('setting-music-player') === 'true';
                }
            } catch (error) {
                // 使用默认设置
                // 如果未设置过，默认关闭
                musicPlayerSetting = localStorage.getItem('setting-music-player') === 'true';
            }
            
            // 为音乐图标添加点击事件
            const musicIcon = document.getElementById('music-icon');
            if (musicIcon) {
                musicIcon.addEventListener('click', toggleMusicPlayer);
            }
            
            // 只有当设置开启时才初始化播放器
            if (musicPlayerSetting) {
                initMusicPlayer();
                initDrag();
            } else {
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
            
            // 确保变量初始化
            isPlayerDragging = false;
            playerStartX = 0;
            playerStartY = 0;
            initialX = 0;
            initialY = 0;
            
            // 鼠标按下事件 - 开始拖拽
            const startDrag = (e) => {
                // 检查是否点击了按钮或交互元素，如果是则不开始拖拽
                if (e.target.closest('button') || e.target.closest('.action-btn') || e.target.closest('.control-btn') || e.target.closest('input') || e.target.closest('select') || e.target.closest('a')) return;
                
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
            
            // 鼠标移动事件 - 拖动元素
            const drag = (e) => {
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
            };
            
            // 鼠标释放事件 - 结束拖拽
            const stopDrag = () => {
                if (isPlayerDragging) {
                    isPlayerDragging = false;
                    player.classList.remove('dragging');
                }
            };
            
            // 为播放器头部添加拖拽事件（所有模式）
            if (header) {
                header.addEventListener('mousedown', startDrag);
            }
            
            // 为播放器内容区域添加拖拽事件（所有模式）
            if (playerContent) {
                playerContent.addEventListener('mousedown', startDrag);
            }
            
            // 为播放器本身添加拖拽事件（所有模式）
            if (player) {
                player.addEventListener('mousedown', startDrag);
            }
            
            // 添加全局事件监听器
            document.addEventListener('mousemove', drag);
            document.addEventListener('mouseup', stopDrag);
            
            // 初始化音量
            const audioPlayer = document.getElementById('audio-player');
            if (audioPlayer) {
                audioPlayer.volume = 0.8; // 默认音量80%
            }
        }
        
        // 获取当前音乐模式
        async function getCurrentMusicMode() {
            // 如果在春节期间，强制返回 'spring_festival'
            if (IS_SPRING_FESTIVAL_PERIOD) {
                return 'spring_festival';
            }
            
            try {
                // 检查indexedDBManager是否已经初始化
                if (typeof indexedDBManager !== 'undefined') {
                    try {
                        const settings = await indexedDBManager.getSettings();
                        return settings['setting-music-mode'] || 'random';
                    } catch (error) {
                        // IndexedDB获取失败，使用localStorage
                        return localStorage.getItem('setting-music-mode') || 'random';
                    }
                } else {
                    // indexedDBManager未初始化，使用localStorage
                    return localStorage.getItem('setting-music-mode') || 'random';
                }
            } catch (error) {
                // 忽略所有错误，返回默认值
                return localStorage.getItem('setting-music-mode') || 'random';
            }
        }
        
        // 尝试解析跳转链接并播放
        async function tryResolveAndPlay(originalUrl, audioPlayer) {
            try {
                console.log('Attempting to resolve redirect for:', originalUrl);
                
                // 使用后端代理来处理重定向和CORS问题
                const proxyUrl = `proxy_music.php?url=${encodeURIComponent(originalUrl)}`;
                
                // 先尝试用 HEAD 请求检查代理是否有效
                try {
                    const response = await fetch(proxyUrl, { method: 'HEAD' });
                    if (response.ok) {
                        console.log('Proxy resolved:', proxyUrl);
                        audioPlayer.src = proxyUrl;
                        
                        try {
                            await audioPlayer.play();
                            isPlaying = true;
                            document.getElementById('play-btn').textContent = '⏸';
                            document.getElementById('player-status').textContent = '正在播放';
                            
                            // 确保歌词容器显示
                            showLyricContainer();
                            
                            requestAnimationFrame(animateLyrics); // 启动动画
                            return true;
                        } catch (playError) {
                            console.error('Play failed with proxy:', playError);
                        }
                    }
                } catch (proxyError) {
                    console.error('Proxy check failed:', proxyError);
                }
                
                // 如果代理也失败了，尝试直接使用 HEAD 请求获取最终 URL (仅当没有CORS限制时有效)
                try {
                    const response = await fetch(originalUrl, { method: 'HEAD', mode: 'cors' });
                    const finalUrl = response.url;
                    
                    if (finalUrl && finalUrl !== originalUrl && finalUrl !== window.location.href) {
                        console.log('Redirect resolved (direct):', finalUrl);
                        audioPlayer.src = finalUrl;
                        
                        try {
                            await audioPlayer.play();
                            isPlaying = true;
                            document.getElementById('play-btn').textContent = '⏸';
                            document.getElementById('player-status').textContent = '正在播放';
                            
                            // 确保歌词容器显示
                            showLyricContainer();
                            
                            requestAnimationFrame(animateLyrics); // 启动动画
                            return true;
                        } catch (playError) {
                            console.error('Play failed after direct redirect:', playError);
                        }
                    }
                } catch (directError) {
                    console.warn('Direct redirect resolution failed (likely CORS):', directError);
                }
                
            } catch (error) {
                console.error('Failed to resolve redirect:', error);
            }
            return false;
        }

        // 自定义歌单相关变量
        let customPlaylistName = '';
        let customPlaylistData = [];
        let customPlaylistIndex = 0;

        // 初始化播放器
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
                
                // 设置初始模式
                const playerContent = document.getElementById('player-content');
                const playerHeader = document.getElementById('player-header');
                const musicIcon = document.getElementById('music-icon');
                
                // 默认展开
                player.classList.remove('minimized', 'mini-minimized');
                playerContent.style.display = 'block';
                musicIcon.style.display = 'none'; // 隐藏图标
                
                // 获取用户偏好的歌单模式
                let savedMode = localStorage.getItem('music_mode');
                
                // 电台时间段处理逻辑（早上10点到晚上11点）
                if (IS_RADIO_PERIOD) {
                    // 不论管理员还是普通用户，都强制使用歌单歌单
                    savedMode = 'custom_歌单';
                } else if (IS_SPRING_FESTIVAL_PERIOD) {
                    // 春节期间处理逻辑
                    // 如果是管理员，允许使用保存的模式，但如果没保存过则默认春节模式
                    if (IS_ADMIN) {
                        if (!savedMode) savedMode = 'spring_festival';
                    } else {
                        // 非管理员强制春节模式
                        savedMode = 'spring_festival';
                    }
                } else {
                    if (!savedMode) savedMode = 'random';
                }

                // 更新下拉菜单
                const select = document.getElementById('playlist-select');
                if (select) {
                    select.value = savedMode;
                    
                    // 如果是自定义歌单，但select里没有（比如config变了），回退到默认
                    if (select.value === '' && savedMode !== '') {
                        savedMode = 'random';
                        select.value = 'random';
                    }
                }
                
                // 调用切换逻辑以初始化状态
                changePlaylist(savedMode);
                
                initDrag();
                
                // 添加定时器，每1分钟更新一次歌单和检查时间
                setInterval(() => {
                    // 检查时间是否在指定时间段
                    const currentHour = new Date().getHours();
                    const isCurrentRadioPeriod = currentHour >= 10 && currentHour < 20;
                    
                    // 如果时间范围变化，静默更新播放器模式
                    if (isCurrentRadioPeriod !== IS_RADIO_PERIOD) {
                        console.log('时间范围变化，静默更新播放器模式');
                        
                        // 模拟新的时间设置
                        const newRadioPeriod = isCurrentRadioPeriod;
                        
                        // 根据新的时间设置更新播放器模式
                        if (newRadioPeriod) {
                            // 进入电台时间段，切换到歌单歌单
                            changePlaylist('custom_歌单');
                        } else {
                            // 退出电台时间段，恢复到用户保存的模式或默认模式
                            const savedMode = localStorage.getItem('music_mode') || 'random';
                            changePlaylist(savedMode);
                        }
                        return;
                    }
                    
                    // 定时更新歌单 - 静默更新，不影响当前播放
                    if (currentMusicMode === 'custom') {
                        console.log('静默更新歌单-1分钟');
                        
                        // 从服务器获取最新的歌单数据
                        fetch(`get_playlist_music.php?name=${encodeURIComponent(customPlaylistName)}`)
                            .then(response => response.json())
                            .then(songs => {
                                if (songs && songs.length > 0) {
                                    // 只更新歌单数据，不影响当前播放状态
                                    // 保存当前播放的歌曲信息
                                    const currentSongInfo = currentSong;
                                    
                                    // 更新歌单数据
                                    customPlaylistData = songs;

                                    
                                    console.log('歌单更新成功，共', songs.length, '首歌曲');
                                    
                                    // 如果当前没有播放歌曲，重置索引
                                    if (!currentSongInfo) {
                                        customPlaylistIndex = 0;
                                    }
                                }
                            })
                            .catch(error => {
                                console.error('静默更新歌单失败:', error);
                            });
                    }
                }, 60000); // 60秒 = 1分钟
            } catch (error) {
                console.error('Init music player failed:', error);
            }
        }
        
        // 获取自定义歌单列表
        async function fetchPlaylists() {
            try {
                const response = await fetch('get_playlist_config.php');
                const data = await response.json();
                
                if (data && data.playlists) {
                    const select = document.getElementById('playlist-select');
                    // 清除之前的选项（除了默认的选项）
                    const defaultOption = select.querySelector('option[value="random"]');
                    const springOption = select.querySelector('option[value="spring_festival"]');
                    
                    select.innerHTML = '';
                    if (defaultOption) select.appendChild(defaultOption);
                    if (springOption) select.appendChild(springOption);
                    
                    data.playlists.forEach(playlist => {
                        // 检查是否已存在同名选项，避免重复添加
                        if (!select.querySelector(`option[value="custom_${playlist.name}"]`)) {
                            const option = document.createElement('option');
                            option.value = 'custom_' + playlist.name;
                            option.textContent = playlist.name;
                            select.appendChild(option);
                        }
                    });
                }
            } catch (error) {
                console.error('Failed to fetch playlists:', error);
            }
        }
        // 切换歌单
        async function changePlaylist(mode) {
            // 电台时间段强制锁定，不论管理员还是普通用户
            if (IS_RADIO_PERIOD) {
                if (mode !== 'custom_歌单') {
                    // 如果试图切换到其他模式，强制切回
                    console.log('电台时间段禁止切换歌单');
                    const select = document.getElementById('playlist-select');
                    if (select) select.value = 'custom_歌单';
                    return;
                }
            } else if (IS_SPRING_FESTIVAL_PERIOD && !IS_ADMIN) {
                // 春节期间强制锁定，但管理员除外
                if (mode !== 'spring_festival') {
                    // 如果试图切换到其他模式，强制切回
                    console.log('春节期间禁止切换歌单');
                    const select = document.getElementById('playlist-select');
                    if (select) select.value = 'spring_festival';
                    return;
                }
            }

            if (mode.startsWith('custom_')) {
                const name = mode.substring(7);
                customPlaylistName = name;
                currentMusicMode = 'custom';
                // 重置，确保下次加载时从服务器获取最新数据
                customPlaylistData = [];
                customPlaylistIndex = 0;
            } else {
                currentMusicMode = mode;
                // 如果切换回非自定义模式，也要清理可能残留的自定义状态
                if (mode === 'random') {
                    // 重置随机播放的一些状态
                } else if (mode === 'spring_festival') {
                    springFestivalPlaylist = []; // 清空缓存以重新加载
                    springFestivalCurrentIndex = 0;
                }
            }
            
            // 只有在切换到不同歌单时才停止当前播放并加载新歌
            // 如果是相同歌单的刷新，保持当前播放状态
            const currentMode = localStorage.getItem('music_mode');
            
            // 保存偏好
            localStorage.setItem('music_mode', mode);
            
            if (mode !== currentMode) {
                // 停止当前播放并重置播放器状态
                const audioPlayer = document.getElementById('audio-player');
                if (audioPlayer) {
                    audioPlayer.pause();
                    audioPlayer.currentTime = 0;
                    isPlaying = false;
                    document.getElementById('play-btn').textContent = '▶';
                }
                
                // 立即加载新歌
                // 使用 setTimeout 确保状态更新完成
                setTimeout(() => {
                    loadNewSong();
                }, 100);
            } else {
                // 相同歌单，保持当前播放状态，只更新歌单数据
                console.log('刷新歌单数据，保持当前播放状态');
                if (currentMusicMode === 'custom') {
                    // 清空缓存，确保下次加载时获取最新数据
                    customPlaylistData = [];
                    customPlaylistIndex = 0;
                }
            }
            
            // 同步更新设置弹窗中的下拉菜单（如果存在）
            const settingSelect = document.getElementById('setting-music-mode');
            if (settingSelect) {
                // 如果是自定义歌单，设置弹窗里可能没有对应的option，这里暂时不做处理或者设为random
                // 如果是random或spring_festival，直接同步
                if (mode === 'random' || mode === 'spring_festival') {
                    settingSelect.value = mode;
                } else {
                    // 对于自定义歌单，设置弹窗里没有对应选项，保持原样或者设为默认
                    // 最好是在设置弹窗里也能显示自定义歌单，但那是另一个功能
                    // 这里为了避免saveSettings覆盖回去，我们最好也更新settingSelect
                    // 但如果没有option，value设进去也没用。
                    // 既然saveSettings是读取value，如果value不在option里，value会是空或者默认
                    // 暂时只同步标准模式
                }
            }
        }

        // 加载自定义歌单歌曲
        async function loadCustomPlaylistSong() {
            if (customPlaylistData.length === 0) {
                document.getElementById('player-status').textContent = '加载歌单中...';
                try {
                    const response = await fetch(`get_playlist_music.php?name=${encodeURIComponent(customPlaylistName)}`);
                    const songs = await response.json();
                    
                    if (songs && songs.length > 0) {
                        customPlaylistData = songs;
                    } else {
                        document.getElementById('player-status').textContent = '歌单为空';
                        return;
                    }
                } catch (error) {
                    document.getElementById('player-status').textContent = '歌单加载失败';
                    return;
                }
            }
            
            if (customPlaylistIndex >= customPlaylistData.length) {
                // 播放完所有歌曲后，重新从服务器获取最新的歌单数据
                document.getElementById('player-status').textContent = '更新歌单中...';
                try {
                    const response = await fetch(`get_playlist_music.php?name=${encodeURIComponent(customPlaylistName)}`);
                    const songs = await response.json();
                    
                    if (songs && songs.length > 0) {
                        customPlaylistData = songs;
                    }
                } catch (error) {
                    console.error('更新歌单失败:', error);
                }
                customPlaylistIndex = 0;
            }
            
            // 记录当前歌曲索引，用于播放完成后删除
            const currentIndex = customPlaylistIndex;
            const song = customPlaylistData[customPlaylistIndex++];
            
            // 处理QQ音乐解析逻辑
            if (song.source_type === 'qqmusic') {
                document.getElementById('player-status').textContent = '正在解析歌曲...';
                try {
                    // 使用后端返回的 choose_id 和 page (如果存在)
                    let apiUrl = `https://api.vkeys.cn/v2/music/tencent?word=${encodeURIComponent(song.query_name)}&quality=8`;
                    if (song.choose_id) {
                        apiUrl += `&choose=${song.choose_id}`;
                    } else {
                        apiUrl += `&choose=1`; // 默认第一个
                    }
                    if (song.page && song.page > 1) {
                        apiUrl += `&page=${song.page}`;
                    }
                    
                    const res = await fetch(apiUrl);
                    const data = await res.json();
                    if (data.code === 200) {
                        song.title = data.data.song;
                        song.artist = data.data.singer;
                        
                        // 确保封面URL使用HTTPS
                        let coverUrl = data.data.cover;
                        if (coverUrl && coverUrl.startsWith('http://')) {
                            coverUrl = coverUrl.replace('http://', 'https://');
                        }
                        song.cover = coverUrl;
                        
                        // 确保音频URL使用HTTPS
                        let audioUrl = data.data.url;
                        if (audioUrl && audioUrl.startsWith('http://')) {
                            audioUrl = audioUrl.replace('http://', 'https://');
                        }
                        song.url = audioUrl;
                        
                        song.id = data.data.id; // 保存歌曲ID用于获取歌词
                    } else {
                        console.error('QQMusic API解析失败:', data);
                        // 如果解析失败，可能会导致播放失败，自动跳下一首由onerror处理
                    }
                } catch (e) {
                    console.error('QQMusic请求错误:', e);
                }
            }
            
            currentSong = {
                name: song.title,
                artistsname: song.artist,
                url: song.url,
                picurl: song.cover,
                source_type: song.source_type,
                id: song.id // 传递ID
            };
            
            updatePlayerUI(currentSong);
            
            // 尝试获取歌词（如果是QQ音乐）
            if (currentSong.source_type === 'qqmusic' && currentSong.id) {
                fetchLyrics(currentSong.id);
            } else {
                // 隐藏歌词，显示状态
                document.getElementById('lyric-container').style.display = 'none';
                document.getElementById('player-status').style.display = 'block';
            }
            
            playCurrentSong();
        }

        // 更新UI辅助函数
        function updatePlayerUI(song) {
            document.getElementById('song-title').textContent = `${song.name} - ${song.artistsname}`;
            document.getElementById('artist-name').textContent = song.artistsname;
            document.getElementById('progress-song-info').textContent = `${song.name} - ${song.artistsname}`;
            
            const albumImage = document.getElementById('album-image');
            if (song.picurl && song.picurl !== 'assets/default_music_cover.png') {
                 albumImage.src = song.picurl;
            } else {
                // 默认图
                albumImage.src = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxMDAiIGhlaWdodD0iMTAwIiB2aWV3Qm94PSIwIDAgMTAwIDEwMCI+PHJlY3Qgd2lkdGg9IjEwMCIgaGVpZ2h0PSIxMDAiIGZpbGw9IiNkZGQiLz48dGV4dCB4PSI1MCIgeT0iNTAiIGZvbnQtZmFtaWx5PSJzYW5zLXNlcmlmIiBmb250LXNpemU9IjE0IiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBkbWluYW50LWJhc2VsaW5lPSJtaWRkbGUiIGZpbGw9IiM4ODgiPuVjb3ZlcjwvdGV4dD48L3N2Zz4=';
            }
            albumImage.style.display = 'block';
        }

        // 播放当前歌曲辅助函数
        function playCurrentSong() {
            const audioPlayer = document.getElementById('audio-player');
            
            // 清除所有事件监听器，确保不影响新歌曲播放
            audioPlayer.removeEventListener('canplaythrough', updateDuration);
            audioPlayer.removeEventListener('timeupdate', updateProgress);
            audioPlayer.removeEventListener('ended', loadNewSong);
            audioPlayer.removeEventListener('timeupdate', syncLyricHandler);
            
            // 重置音频元素，确保加载新歌曲
            audioPlayer.src = '';
            audioPlayer.innerHTML = '';
            
            // 加载新歌曲
            audioPlayer.src = currentSong.url;
            audioPlayer.load();
            
            // 添加歌词同步事件
            if (currentSong.source_type === 'qqmusic') {
                audioPlayer.addEventListener('timeupdate', syncLyricHandler);
            }
            
            // 添加新的事件监听器
            audioPlayer.addEventListener('canplaythrough', updateDuration);
            audioPlayer.addEventListener('timeupdate', updateProgress);
            audioPlayer.addEventListener('ended', async function() {
                // 播放完成后删除当前歌曲
                // 尝试从服务器端的temp_song_config.json文件中删除这首歌
                try {
                    let songName = '';
                    
                    // 优先从customPlaylistData中获取歌曲名称
                    if (currentMusicMode === 'custom' && customPlaylistData.length > 0) {
                        const lastIndex = customPlaylistIndex - 1;
                        if (lastIndex >= 0 && lastIndex < customPlaylistData.length) {
                            const playedSong = customPlaylistData[lastIndex];
                            songName = playedSong.query_name || playedSong.title;
                            // 从前端数组中删除当前歌曲
                            customPlaylistData.splice(lastIndex, 1);
                        }
                    } else {
                        // 从currentSong中获取歌曲名称
                        songName = currentSong.name || '';
                    }
                    
                    // 如果获取到了歌曲名称，发送删除请求
                    if (songName) {
                        const response = await fetch('remove_song.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify({
                                song_name: songName
                            })
                        });
                        const result = await response.json();
                        if (result.success) {
                            console.log(result.message);
                        } else {
                            console.error('删除歌曲失败:', result.message);
                        }
                    }
                } catch (error) {
                    console.error('删除歌曲失败:', error);
                }
                loadNewSong();
            });
            
            // 直接播放，不需要等待oncanplay事件
            audioPlayer.play().then(() => {
                isPlaying = true;
                document.getElementById('play-btn').textContent = '⏸';
                // 确保歌词容器显示
                if (currentSong.source_type === 'qqmusic') {
                    document.getElementById('player-status').style.display = 'none';
                    document.getElementById('lyric-container').style.display = 'block';
                    // 启动歌词动画
                    startLyricAnimation();
                } else {
                    document.getElementById('player-status').textContent = '正在播放';
                    document.getElementById('player-status').style.display = 'block';
                    document.getElementById('lyric-container').style.display = 'none';
                }
            }).catch(err => {
                console.error('Play failed:', err);
                isPlaying = false;
                document.getElementById('play-btn').textContent = '▶';
                
                // 播放失败时切回状态显示
                document.getElementById('player-status').textContent = '点击播放';
                document.getElementById('player-status').style.display = 'block';
                document.getElementById('lyric-container').style.display = 'none';
            });
            
            audioPlayer.onpause = () => {
                isPlaying = false;
                document.getElementById('play-btn').textContent = '▶';
                // 暂停时显示状态
                document.getElementById('player-status').textContent = '已暂停';
                document.getElementById('player-status').style.display = 'block';
                document.getElementById('lyric-container').style.display = 'none';
            };
            
            audioPlayer.onplay = () => {
                isPlaying = true;
                document.getElementById('play-btn').textContent = '⏸';
                // 播放时如果歌词已加载，切换回歌词
                if (document.getElementById('lyric-content').innerHTML !== '' && currentSong.source_type === 'qqmusic') {
                    document.getElementById('player-status').style.display = 'none';
                    document.getElementById('lyric-container').style.display = 'block';
                } else {
                    document.getElementById('player-status').textContent = '正在播放';
                    document.getElementById('player-status').style.display = 'block';
                    document.getElementById('lyric-container').style.display = 'none';
                }
            };
            
            audioPlayer.onerror = async () => {
                // 防止无限重试
                if (audioPlayer.dataset.retrying === 'true') {
                    audioPlayer.dataset.retrying = 'false';
                    setTimeout(() => {
                        loadNewSong();
                    }, 2000);
                    return;
                }
                
                // 尝试解析重定向
                document.getElementById('player-status').textContent = '尝试解析跳转链接...';
                audioPlayer.dataset.retrying = 'true';
                
                const currentSrc = audioPlayer.src;
                const success = await tryResolveAndPlay(currentSrc, audioPlayer);
                
                if (!success) {
                    audioPlayer.dataset.retrying = 'false';
                    document.getElementById('player-status').textContent = '播放失败，尝试下一首';
                    setTimeout(loadNewSong, 2000);
                }
            };
        }
        
        // 独立的歌词同步处理函数，方便移除监听
        function syncLyricHandler() {
            const audioPlayer = document.getElementById('audio-player');
            syncLyrics(audioPlayer.currentTime);
        }
        
        // 启动歌词动画
        function startLyricAnimation() {
            // 确保只启动一次动画循环
            if (!window.lyricAnimationStarted) {
                window.lyricAnimationStarted = true;
                requestAnimationFrame(animateLyrics);
            }
        }

        // 获取春节歌单
        async function getSpringFestivalPlaylist() {
            try {
                // 添加时间戳防止缓存
                const response = await fetch(`get_music_list.php?t=${new Date().getTime()}`);
                if (!response.ok) return [];
                
                const data = await response.json();
                if (data.code === 200 && data.data && Array.isArray(data.data)) {
                    // 确保 URL 是正确的
                    return data.data.map(item => {
                        // 强制修正旧的 stream_music.php 链接
                        if (item.url && item.url.includes('stream_music.php')) {
                            // 提取文件名
                            const match = item.url.match(/file=([^&]+)/);
                            if (match && match[1]) {
                                item.url = `new_music/${match[1]}`;
                            }
                        }
                        return item;
                    });
                }
                return [];
            } catch (error) {
                console.error('Failed to get spring festival playlist:', error);
                return [];
            }
        }

        // 加载春节歌曲
        async function loadSpringFestivalSong() {
            // 如果列表为空，获取列表
            if (springFestivalPlaylist.length === 0) {
                document.getElementById('player-status').textContent = '正在获取春节歌单...';
                springFestivalPlaylist = await getSpringFestivalPlaylist();
                
                if (springFestivalPlaylist.length === 0) {
                    document.getElementById('player-status').textContent = '春节歌单暂无歌曲';
                    return;
                }
                
                springFestivalCurrentIndex = 0;
            }
            
            // 如果索引超出，重置索引
            if (springFestivalCurrentIndex >= springFestivalPlaylist.length) {
                springFestivalCurrentIndex = 0;
            }
            
            const song = springFestivalPlaylist[springFestivalCurrentIndex];
            springFestivalCurrentIndex++;
            
            // 再次确保 URL 是正确的 (防止使用缓存中的旧数据)
            if (song.url && song.url.includes('stream_music.php')) {
                const match = song.url.match(/file=([^&]+)/);
                if (match && match[1]) {
                    song.url = `new_music/${match[1]}`;
                }
            }
            
            currentSong = {
                name: song.name,
                artistsname: song.artistsname,
                url: song.url,
                picurl: song.picurl,
                source_type: 'spring_festival' // 标记来源
            };
            
            // 更新UI
            document.getElementById('song-title').textContent = `${song.name} - ${song.artistsname}`;
            document.getElementById('artist-name').textContent = song.artistsname;
            const progressSongInfo = document.getElementById('progress-song-info');
            progressSongInfo.textContent = `${song.name} - ${song.artistsname}`;
            
            // 设置图片
            const albumImage = document.getElementById('album-image');
            
            // 检查是否是后端 API 提供的封面 URL (get_music_cover.php)
            if (song.picurl && song.picurl.includes('get_music_cover.php')) {
                // 异步获取 Base64 数据
                fetch(song.picurl)
                    .then(response => response.json())
                    .then(data => {
                        if (data.code === 200 && data.data) {
                            albumImage.src = `data:${data.mime};base64,${data.data}`;
                            albumImage.style.display = 'block';
                        } else {
                            // 加载失败，使用默认图
                            albumImage.src = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxMDAiIGhlaWdodD0iMTAwIiB2aWV3Qm94PSIwIDAgMTAwIDEwMCI+PHJlY3Qgd2lkdGg9IjEwMCIgaGVpZ2h0PSIxMDAiIGZpbGw9IiNkZGQiLz48dGV4dCB4PSI1MCIgeT0iNTAiIGZvbnQtZmFtaWx5PSJzYW5zLXNlcmlmIiBmb250LXNpemU9IjE0IiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBkbWluYW50LWJhc2VsaW5lPSJtaWRkbGUiIGZpbGw9IiM4ODgiPuVjb3ZlcjwvdGV4dD48L3N2Zz4=';
                        }
                    })
                    .catch(err => {
                        console.error('Fetch cover failed:', err);
                        albumImage.src = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxMDAiIGhlaWdodD0iMTAwIiB2aWV3Qm94PSIwIDAgMTAwIDEwMCI+PHJlY3Qgd2lkdGg9IjEwMCIgaGVpZ2h0PSIxMDAiIGZpbGw9IiNkZGQiLz48dGV4dCB4PSI1MCIgeT0iNTAiIGZvbnQtZmFtaWx5PSJzYW5zLXNlcmlmIiBmb250LXNpemU9IjE0IiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBkbWluYW50LWJhc2VsaW5lPSJtaWRkbGUiIGZpbGw9IiM4ODgiPuVjb3ZlcjwvdGV4dD48L3N2Zz4=';
                    });
            } else {
                albumImage.src = song.picurl;
                albumImage.style.display = 'block';
            }
            
            albumImage.onerror = function() {
                // 如果加载失败，使用默认图片或隐藏
                this.src = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxMDAiIGhlaWdodD0iMTAwIiB2aWV3Qm94PSIwIDAgMTAwIDEwMCI+PHJlY3Qgd2lkdGg9IjEwMCIgaGVpZ2h0PSIxMDAiIGZpbGw9IiNkZGQiLz48dGV4dCB4PSI1MCIgeT0iNTAiIGZvbnQtZmFtaWx5PSJzYW5zLXNlcmlmIiBmb250LXNpemU9IjE0IiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBkbWluYW50LWJhc2VsaW5lPSJtaWRkbGUiIGZpbGw9IiM4ODgiPuVjb3ZlcjwvdGV4dD48L3N2Zz4=';
            };
            
            // 隐藏歌词，显示状态
            document.getElementById('lyric-container').style.display = 'none';
            document.getElementById('player-status').style.display = 'block';
            
            // 使用统一播放函数
            playCurrentSong();
        }

        // 加载新歌曲
        async function loadNewSong() {
            document.getElementById('player-status').textContent = '正在加载音乐...';
            
            try {
                // 强制同步当前模式，防止状态不一致
                const select = document.getElementById('playlist-select');
                if (select && select.value && !select.value.startsWith('custom_')) {
                     // 只有当select的值与currentMusicMode不一致，且不是自定义歌单时才同步
                     // 自定义歌单在changePlaylist里已经处理了
                     if (currentMusicMode === 'random' && select.value === 'spring_festival') {
                         currentMusicMode = 'spring_festival';
                     }
                }

                // 检查是否是首次加载且有保存的模式
                if (currentMusicMode === 'random' && localStorage.getItem('music_mode')) {
                    const savedMode = localStorage.getItem('music_mode');
                    // 确保选项存在（对于动态加载的选项，可能需要稍后设置）
                    if (savedMode.startsWith('custom_')) {
                         // 自定义歌单逻辑
                         const name = savedMode.substring(7);
                         // 等待 fetchPlaylists 完成（如果需要），这里简单处理
                         customPlaylistName = name;
                         currentMusicMode = 'custom';
                         if (select) select.value = savedMode;
                    } else if (savedMode !== 'random') {
                        currentMusicMode = savedMode;
                        if (select) select.value = savedMode;
                    }
                }

                if (currentMusicMode === 'custom') {
                    await loadCustomPlaylistSong();
                    return;
                }
                
                // 获取当前音乐模式
                // currentMusicMode = await getCurrentMusicMode(); // 移除这行，因为我们已经手动管理 mode 了
                
                if (currentMusicMode === 'spring_festival') {
                    // 春节歌单模式
                    await loadSpringFestivalSong();
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
                    
                    // 检查是否是后端 API 提供的封面 URL (get_music_cover.php)
                    if (currentSong.picurl && currentSong.picurl.includes('get_music_cover.php')) {
                        // 异步获取 Base64 数据
                        fetch(currentSong.picurl)
                            .then(response => response.json())
                            .then(data => {
                                if (data.code === 200 && data.data) {
                                    albumImage.src = `data:${data.mime};base64,${data.data}`;
                                    albumImage.style.display = 'block';
                                } else {
                                    // 加载失败，使用默认图
                                    albumImage.src = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxMDAiIGhlaWdodD0iMTAwIiB2aWV3Qm94PSIwIDAgMTAwIDEwMCI+PHJlY3Qgd2lkdGg9IjEwMCIgaGVpZ2h0PSIxMDAiIGZpbGw9IiNkZGQiLz48dGV4dCB4PSI1MCIgeT0iNTAiIGZvbnQtZmFtaWx5PSJzYW5zLXNlcmlmIiBmb250LXNpemU9IjE0IiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBkbWluYW50LWJhc2VsaW5lPSJtaWRkbGUiIGZpbGw9IiM4ODgiPuVjb3ZlcjwvdGV4dD48L3N2Zz4=';
                                }
                            })
                            .catch(err => {
                                console.error('Fetch cover failed:', err);
                                albumImage.src = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxMDAiIGhlaWdodD0iMTAwIiB2aWV3Qm94PSIwIDAgMTAwIDEwMCI+PHJlY3Qgd2lkdGg9IjEwMCIgaGVpZ2h0PSIxMDAiIGZpbGw9IiNkZGQiLz48dGV4dCB4PSI1MCIgeT0iNTAiIGZvbnQtZmFtaWx5PSJzYW5zLXNlcmlmIiBmb250LXNpemU9IjE0IiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBkbWluYW50LWJhc2VsaW5lPSJtaWRkbGUiIGZpbGw9IiM4ODgiPuVjb3ZlcjwvdGV4dD48L3N2Zz4=';
                            });
                    } else {
                        // 普通 URL
                        let picUrl = currentSong.picurl;
                        if (picUrl && picUrl.startsWith('http://')) {
                            picUrl = picUrl.replace('http://', 'https://');
                        }
                        albumImage.src = picUrl || '';
                        albumImage.style.display = 'block';
                    }
                    
                    // 请求新的音乐API
                    let audioUrl = null;
                    let songId = null;
                    const songName = encodeURIComponent(currentSong.name + ' ' + currentSong.artistsname);
                    
                    // 从URL中提取歌曲ID
                    const url = currentSong.url;
                    const idMatch = url.match(/id=(\d+)/);
                    if (idMatch && idMatch[1]) {
                        songId = idMatch[1];
                        
                    }
                    
                    // 优先使用ID请求音乐链接，最多重试3次
                    if (songId) {
                        let retryCount = 0;
                        const maxRetries = 3;
                        
                        while (retryCount < maxRetries && !audioUrl) {
                            try {
                                const apiUrl = `https://api.vkeys.cn/v2/music/netease?id=${songId}`;
                                
                                const newResponse = await fetch(apiUrl);
                                const newData = await newResponse.json();
                                
                                if (newData.code === 200 && newData.data && newData.data.url) {
                                    audioUrl = newData.data.url;
                                    break;
                                } else {
                                    retryCount++;
                                    await new Promise(resolve => setTimeout(resolve, 500));
                                }
                            } catch (retryError) {
                                retryCount++;
                                await new Promise(resolve => setTimeout(resolve, 500));
                            }
                        }
                    }
                    
                    // 如果ID请求失败或没有ID，使用歌曲名称请求
                    if (!audioUrl) {
                        try {
                            const apiUrl = `https://api.vkeys.cn/v2/music/netease?word=${songName}&choose=1&quality=9`;
                            
                            const newResponse = await fetch(apiUrl);
                            const newData = await newResponse.json();
                            
                            if (newData.code === 200 && newData.data && newData.data.url) {
                                audioUrl = newData.data.url;
                            }
                        } catch (nameError) {
                            // 忽略错误，不向控制台报错
                        }
                    }
                    
                    // 如果所有请求都失败，使用原链接作为最后的备选
                    if (!audioUrl) {
                        audioUrl = currentSong.url;
                    }
                    
                    // 确保使用HTTPS（如果是 URL 链接）
                    if (audioUrl.startsWith('http://')) {
                        audioUrl = audioUrl.replace('http://', 'https://');
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
                        // 忽略错误，不向控制台报错
                        document.getElementById('player-status').textContent = '播放出错';
                    });
                    
                    // 自动播放，添加错误处理
                    try {
                        await audioPlayer.play();
                        isPlaying = true;
                        document.getElementById('play-btn').textContent = '⏸';
                        document.getElementById('player-status').textContent = '正在播放';
                        
                        // 尝试加载歌词
                        if (songId) {
                            await fetchLyrics(songId);
                        } else {
                            // 尝试用歌名+歌手搜索ID来获取歌词
                            // 这是一个备用方案，可能需要额外的API调用，或者直接根据audioUrl反查ID
                            // 如果实在没有ID，无法获取歌词
                            document.getElementById('lyric-container').style.display = 'none';
                            document.getElementById('player-status').style.display = 'block';
                        }

                        // 确保歌词容器显示（如果有歌词）
                        showLyricContainer();
                        
                        // 启动歌词动画
                        requestAnimationFrame(animateLyrics);
                    } catch (playError) {
                        // 忽略错误，不向控制台报错
                        isPlaying = false;
                        document.getElementById('play-btn').textContent = '▶';
                        document.getElementById('player-status').textContent = '已暂停（点击播放）';
                    }
                } else {
                    document.getElementById('player-status').textContent = '加载失败，请刷新页面重试';
                }
            } catch (error) {
                // 忽略错误，不向控制台报错
                document.getElementById('player-status').textContent = '加载失败，请刷新页面重试';
            }
        }
        
        // 切换播放/暂停
        async function togglePlay() {
            const audioPlayer = document.getElementById('audio-player');
            const playBtn = document.getElementById('play-btn');
            
            // 检查用户设置
            let isUserEnabled = true;
            try {
                // 从IndexedDB加载设置
                const settings = await indexedDBManager.getSettings();
                isUserEnabled = settings['setting-music-player'] !== false;
            } catch (error) {
                // 忽略错误，不向控制台报错
                // 降级到localStorage
                isUserEnabled = localStorage.getItem('setting-music-player') !== 'false';
            }
            
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
                    // 忽略错误，不向控制台报错
                }
            } else {
                try {
                    // 检查是否有有效的音频源
                    // 注意：QQ音乐模式使用innerHTML插入source标签，audioPlayer.src可能为空
                    // 所以我们需要检查 currentSrc 或者判断 currentSong 是否存在
                    const hasSource = audioPlayer.src || (audioPlayer.currentSrc && audioPlayer.currentSrc !== window.location.href) || (currentSong && currentSong.source_type === 'qqmusic' && currentSong.url);
                    
                    if (!hasSource) {
                        // 重新加载音频源
                        await loadNewSong();
                        return;
                    }
                    
                    await audioPlayer.play();
                    playBtn.textContent = '⏸';
                    document.getElementById('player-status').textContent = '正在播放';
                    isPlaying = true;
                    
                    // 启动歌词动画循环
                    requestAnimationFrame(animateLyrics);
                } catch (error) {
                    // 忽略错误，不向控制台报错
                    
                    // 播放失败时，尝试重新请求第二个API获取新的音乐URL
                    try {
                        document.getElementById('player-status').textContent = '尝试重新获取音乐链接...';
                        
                        // 使用歌曲名称构建API请求链接
                        const songName = encodeURIComponent(currentSong.name + ' ' + currentSong.artistsname);
                        const apiUrl = `https://api.vkeys.cn/v2/music/netease?word=${songName}&choose=1&quality=9`;
                        
                        // 请求新的API
                        const newResponse = await fetch(apiUrl);
                        
                        // 检查是否为404错误，如果是则不处理
                        if (!newResponse.ok && newResponse.status === 404) {
                            // 不向控制台报错，直接显示失败
                            document.getElementById('player-status').textContent = '播放失败，重新获取链接失败';
                        } else {
                            const newData = await newResponse.json();
                            
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
                                
                                // 启动歌词动画循环
                                requestAnimationFrame(animateLyrics);
                            } else {
                                // API请求失败，更新状态
                                document.getElementById('player-status').textContent = '播放失败，重新获取链接失败';
                            }
                        }
                    } catch (retryError) {
                        // 忽略错误，不向控制台报错
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
            const durationEl = document.getElementById('duration');
            
            // 确保 duration 是有效的
            const duration = audioPlayer.duration;
            const current = audioPlayer.currentTime;
            
            if (isFinite(duration) && duration > 0) {
                const progressPercent = (current / duration) * 100;
                progress.style.width = `${progressPercent}%`;
                durationEl.textContent = formatTime(duration);
            } else {
                progress.style.width = '0%';
                durationEl.textContent = '0:00';
            }
            
            currentTime.textContent = formatTime(current);
        }
        
        // 更新总时长
        function updateDuration() {
            const audioPlayer = document.getElementById('audio-player');
            const duration = document.getElementById('duration');
            
            if (isFinite(audioPlayer.duration)) {
                duration.textContent = formatTime(audioPlayer.duration);
            }
        }
        
        // 跳转进度
        function seek(event) {
            const audioPlayer = document.getElementById('audio-player');
            const progressBar = document.getElementById('progress-bar');
            
            // 如果音频未加载完成或无法获取时长，不执行跳转
            if (!isFinite(audioPlayer.duration) || audioPlayer.duration <= 0) {
                return;
            }
            
            const rect = progressBar.getBoundingClientRect();
            const x = event.clientX - rect.left;
            const width = rect.width;
            const percent = x / width;
            
            audioPlayer.currentTime = percent * audioPlayer.duration;
        }
        
        // 切换播放器显示状态
        function togglePlayer() {
            const player = document.getElementById('music-player');
            const minimizedToggle = document.getElementById('minimized-toggle-container');
            const minimizedActions = document.getElementById('minimized-actions');
            
            // 如果处于迷你模式，恢复到小窗模式
            if (player.classList.contains('mini-minimized')) {
                player.classList.remove('mini-minimized');
                player.classList.add('minimized');
                isMiniMinimized = false;
                isMinimized = true;
                return;
            }
            
            if (player.classList.contains('minimized')) {
                // 恢复大窗
                player.classList.remove('minimized');
                minimizedToggle.style.display = 'none';
                if (minimizedActions) minimizedActions.style.display = 'none';
                isMinimized = false;
            } else {
                // 最小化
                player.classList.add('minimized');
                minimizedToggle.style.display = 'block';
                if (minimizedActions) minimizedActions.style.display = 'flex';
                isMinimized = true;
            }
        }
        
        // 切换迷你模式（侧边栏模式）
        function toggleMiniMode(e) {
            if (e) {
                e.preventDefault();
                e.stopPropagation();
            }
            
            const player = document.getElementById('music-player');
            
            if (player.classList.contains('mini-minimized')) {
                // 从迷你模式恢复到小窗模式
                player.classList.remove('mini-minimized');
                player.classList.add('minimized');
                isMiniMinimized = false;
                isMinimized = true;
            } else {
                // 切换到迷你模式
                player.classList.remove('minimized'); // 确保先移除小窗样式，避免冲突
                player.classList.add('mini-minimized');
                isMiniMinimized = true;
                isMinimized = false;
            }
        }

        // 点击迷你模式的图标（旋转的音乐符号）恢复小窗模式
        document.addEventListener('click', function(e) {
            const player = document.getElementById('music-player');
            if (player && player.classList.contains('mini-minimized') && e.target.closest('#music-player')) {
                toggleMiniMode();
            }
        });
        
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
            
            // 计算点击位置相对于滑块底部的距离
            // Y轴向下增加，所以 rect.bottom 是底部坐标
            // 但 rect.height 比较好用
            // 点击位置 y = event.clientY
            // 相对顶部距离 = event.clientY - rect.top
            // 相对底部距离 = rect.bottom - event.clientY
            
            const relativeY = event.clientY - rect.top;
            let volume = 1 - (relativeY / rect.height);
            
            // 限制在 0-1 之间
            volume = Math.max(0, Math.min(1, volume));
            
            audioPlayer.volume = volume;
            volumeLevel.style.height = `${volume * 100}%`;
        }
        
        // 按步长调整音量
        function adjustVolumeByStep(step) {
            const audioPlayer = document.getElementById('audio-player');
            const volumeLevel = document.getElementById('volume-level');
            
            audioPlayer.volume = Math.max(0, Math.min(1, audioPlayer.volume + step));
            volumeLevel.style.height = `${audioPlayer.volume * 100}%`;
        }
        
        // 切换音乐播放器显示/隐藏
        async function toggleMusicPlayer() {
            // 检查用户设置
            let isUserEnabled = true;
            try {
                // 从IndexedDB加载设置
                const settings = await indexedDBManager.getSettings();
                isUserEnabled = settings['setting-music-player'] !== false;
            } catch (error) {
                // 降级到localStorage
                isUserEnabled = localStorage.getItem('setting-music-player') !== 'false';
            }
            
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
                                        ${req.avatar ? `<img src="${req.avatar}" alt="${req.username}" style="width: 100%; height: 100%; object-fit: cover;">` : `<div style="width: 100%; height: 100%; background: linear-gradient(135deg, #667eea 0%, #0095ff 100%); display: flex; align-items: center; justify-content: center; color: white; font-weight: 600;">${req.username.substring(0, 2)}</div>`}
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
            background: var(--modal-bg);
            color: var(--text-color);
            border-radius: 12px;
            padding: 24px;
            max-width: 500px;
            width: 90%;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
            animation: modalSlideIn 0.3s ease-out;
            border: 1px solid var(--border-color);
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
            color: var(--text-color);
            margin-bottom: 16px;
            text-align: center;
        }
        
        /* 弹窗内容 */
        .system-modal-content {
            font-size: 16px;
            color: var(--text-secondary);
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
        
        // 确保页面加载时获取歌单列表
        /*
        document.addEventListener('DOMContentLoaded', () => {
            // 检查fetchPlaylists是否定义
            if (typeof fetchPlaylists === 'function') {
                fetchPlaylists();
            } else {
                console.error('fetchPlaylists is not defined');
            }
        });
        */
    </script>
<!-- GitHub角标 -->
    <a href="https://github.com/LzdqesjG/modern-chat" class="github-corner" aria-label="View source on GitHub"><svg width="80" height="80" viewBox="0 0 250 250" style="fill:#151513; color:#fff; position: absolute; top: 0; border: 0; right: 0;" aria-hidden="true"><path d="M0,0 L115,115 L130,115 L142,142 L250,250 L250,0 Z"/><path d="M128.3,109.0 C113.8,99.7 119.0,89.6 119.0,89.6 C122.0,82.7 120.5,78.6 120.5,78.6 C119.2,72.0 123.4,76.3 123.4,76.3 C127.3,80.9 125.5,87.3 125.5,87.3 C122.9,97.6 130.6,101.9 134.4,103.2" fill="currentColor" style="transform-origin: 130px 106px;" class="octo-arm"/><path d="M115.0,115.0 C114.9,115.1 118.7,116.5 119.8,115.4 L133.7,101.6 C136.9,99.2 139.9,98.4 142.2,98.6 C133.8,88.0 127.5,74.4 143.8,58.0 C148.5,53.4 154.0,51.2 159.7,51.0 C160.3,49.4 163.2,43.6 171.4,40.1 C171.4,40.1 176.1,42.5 178.8,56.2 C183.1,58.6 187.2,61.8 190.9,65.4 C194.5,69.0 197.7,73.2 200.1,77.6 C213.8,80.2 216.3,84.9 216.3,84.9 C212.7,93.1 206.9,96.0 205.4,96.6 C205.1,102.4 203.0,107.8 198.3,112.5 C181.9,128.9 168.3,122.5 157.7,114.1 C157.9,116.9 156.7,120.9 152.7,124.9 L141.0,136.5 C139.8,137.7 141.6,141.9 141.8,141.8 Z" fill="currentColor" class="octo-body"/></svg></a><style>.github-corner:hover .octo-arm{animation:octocat-wave 560ms ease-in-out}@keyframes octocat-wave{0%,100%{transform:rotate(0)}20%,60%{transform:rotate(-25deg)}40%,80%{transform:rotate(10deg)}}@media (max-width:500px){.github-corner:hover .octo-arm{animation:none}.github-corner .octo-arm{animation:octocat-wave 560ms ease-in-out}}</style>
    <!-- Spring Festival Celebration Modal -->
    <div id="spring-festival-modal" class="modal" style="display: none; z-index: 10001;">
        <div class="modal-content" style="width: 400px; text-align: center; background: linear-gradient(135deg, #ff4d4f 0%, #ff7875 100%); color: white; border-radius: 12px; border: none;">
            <div style="font-size: 60px; margin-bottom: 10px;">🧧</div>
            <h2 style="margin-bottom: 15px; font-size: 24px; text-shadow: 0 2px 4px rgba(0,0,0,0.2);">新春快乐</h2>
            <p id="spring-festival-msg" style="font-size: 18px; margin-bottom: 25px; line-height: 1.5;"></p>
            <button id="spring-festival-confirm-btn" style="
                background: white;
                color: #ff4d4f;
                border: none;
                padding: 10px 30px;
                border-radius: 20px;
                font-size: 16px;
                font-weight: bold;
                cursor: pointer;
                box-shadow: 0 4px 8px rgba(0,0,0,0.1);
                transition: transform 0.1s;
            ">确定</button>
        </div>
    </div>

    <!-- Service Worker 注册 -->
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('/chat/service-worker.js')
                    .then((registration) => {
                        console.log('Service Worker 注册成功:', registration.scope);
                    })
                    .catch((error) => {
                        console.log('Service Worker 注册失败:', error);
                    });
            });
        }
    </script>
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
                            btn.style.background = 'var(--primary-color)';
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
                btn.style.background = 'var(--primary-color)';
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

        // 下载面板拖拽逻辑
        document.addEventListener('DOMContentLoaded', function() {
            const panel = document.getElementById('download-panel');
            const header = panel.querySelector('.download-panel-header');
            
            let isDragging = false;
            let startX, startY, initialLeft, initialTop;

            header.addEventListener('mousedown', function(e) {
                if (e.target.tagName === 'BUTTON') return; // 防止点击按钮触发拖拽
                
                isDragging = true;
                startX = e.clientX;
                startY = e.clientY;
                
                // 获取当前位置并转换为绝对像素定位
                const rect = panel.getBoundingClientRect();
                panel.style.left = rect.left + 'px';
                panel.style.top = rect.top + 'px';
                panel.style.transform = 'none'; // 移除 transform
                panel.style.bottom = 'auto';
                panel.style.right = 'auto';
                
                initialLeft = rect.left;
                initialTop = rect.top;
                
                document.addEventListener('mousemove', onMouseMove);
                document.addEventListener('mouseup', onMouseUp);
            });

            function onMouseMove(e) {
                if (!isDragging) return;
                
                const dx = e.clientX - startX;
                const dy = e.clientY - startY;
                
                panel.style.left = (initialLeft + dx) + 'px';
                panel.style.top = (initialTop + dy) + 'px';
            }

            function onMouseUp() {
                isDragging = false;
                document.removeEventListener('mousemove', onMouseMove);
                document.removeEventListener('mouseup', onMouseUp);
            }
        });
    </script>
</body>
</html>