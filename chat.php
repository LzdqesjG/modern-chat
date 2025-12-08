<?php
// 检查用户是否登录
require_once 'config.php';
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
        }
        error_log("群聊相关数据表创建成功");
    } catch(PDOException $e) {
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

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// 调用函数创建数据表
createGroupTables();

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

// 获取当前用户信息
$current_user = $user->getUserById($user_id);

// 获取好友列表
$friends = $friend->getFriends($user_id);

// 获取群聊列表
$groups = $group->getUserGroups($user_id);

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
    if ($chat_type === 'friend' && !empty($friends)) {
        $selected_id = $friends[0]['id'];
        $selected_friend = $friends[0];
        $selected_friend_id = $selected_id;
    } elseif ($chat_type === 'group' && !empty($groups)) {
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
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>聊天 - Modern Chat</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f5;
            height: 100vh;
            overflow: hidden;
        }
        
        .chat-container {
            display: flex;
            height: 100vh;
            background: white;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.1);
        }
        
        /* 链接样式 */
        .message-link {
            color: #3498db;
            text-decoration: none;
            border-bottom: 1px dashed #3498db;
            transition: all 0.2s ease;
        }
        
        .message-link:hover {
            color: #2980b9;
            border-bottom: 1px solid #2980b9;
        }
        
        /* 录音样式 */
        .recording-dots {
            animation: recordingPulse 1s infinite;
        }
        
        @keyframes recordingPulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.3; }
        }
        
        #record-btn.recording {
            color: #ff4757;
            animation: recordingBtnPulse 1s infinite;
        }
        
        @keyframes recordingBtnPulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }
        
        .input-wrapper {
            position: relative;
        }
        
        /* 自定义音频控件样式 - 新设计 */
        .custom-audio-player {
            display: flex;
            align-items: center;
            background: #667eea;
            border-radius: 25px;
            padding: 12px 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            max-width: 300px;
            width: 100%;
            box-sizing: border-box;
            color: white;
        }
        
        .audio-play-btn {
            width: 36px;
            height: 36px;
            border: none;
            background: white;
            color: #667eea;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            font-weight: bold;
            transition: all 0.2s ease;
            margin-right: 15px;
        }
        
        .audio-play-btn:hover {
            transform: scale(1.1);
            box-shadow: 0 4px 12px rgba(255, 255, 255, 0.3);
        }
        
        .audio-play-btn.paused {
            background: white;
            color: #667eea;
        }
        
        .audio-progress-container {
            flex: 1;
            margin: 0 15px;
            position: relative;
        }
        
        .audio-progress-bar {
            width: 100%;
            height: 6px;
            background: rgba(255, 255, 255, 0.3);
            border-radius: 3px;
            cursor: pointer;
            overflow: hidden;
        }
        
        .audio-progress {
            height: 100%;
            background: white;
            border-radius: 3px;
            transition: width 0.1s ease;
            position: relative;
        }
        
        .audio-progress::after {
            content: '';
            position: absolute;
            right: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 12px;
            height: 12px;
            background: white;
            border-radius: 50%;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }
        
        .audio-time {
            font-size: 14px;
            color: white;
            min-width: 50px;
            text-align: center;
            font-weight: 500;
        }
        
        .audio-duration {
            font-size: 14px;
            color: white;
            min-width: 50px;
            text-align: right;
            font-weight: 500;
        }
        
        /* 隐藏默认音频控件 */
        .custom-audio-player audio {
            display: none;
        }
        
        /* 左侧边栏 */
        .sidebar {
            width: 300px;
            background: #f8f9fa;
            border-right: 1px solid #e0e0e0;
            display: flex;
            flex-direction: column;
        }
        
        /* 顶部导航 */
        .sidebar-header {
            padding: 20px;
            background: white;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .sidebar-header h2 {
            font-size: 20px;
            color: #333;
            font-weight: 600;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .avatar {
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
        }
        
        .user-details h3 {
            font-size: 14px;
            color: #333;
            font-weight: 600;
        }
        
        .user-details p {
            font-size: 12px;
            color: #666;
        }
        
        /* 搜索栏 */
        .search-bar {
            padding: 15px;
            background: white;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .search-bar input {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 25px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: #fafafa;
        }
        
        .search-bar input:focus {
            outline: none;
            border-color: #667eea;
            background: white;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        /* 好友列表 */
        .friends-list {
            flex: 1;
            overflow-y: auto;
        }
        
        .friend-item {
            padding: 15px;
            display: flex;
            align-items: center;
            gap: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .friend-item:hover {
            background: #e8f0fe;
        }
        
        .friend-item.active {
            background: #d4e4fc;
            border-left: 4px solid #667eea;
        }
        
        .friend-avatar {
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
            position: relative;
        }
        
        .status-indicator {
            position: absolute;
            bottom: 2px;
            right: 2px;
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
        
        .status-indicator.away {
            background: #ff9800;
        }
        
        .friend-info {
            flex: 1;
        }
        
        .friend-info h3 {
            font-size: 15px;
            color: #333;
            font-weight: 600;
            margin-bottom: 4px;
        }
        
        .friend-info p {
            font-size: 13px;
            color: #666;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .unread-count {
            background: #ff4757;
            color: white;
            font-size: 12px;
            font-weight: 600;
            padding: 4px 8px;
            border-radius: 12px;
            min-width: 24px;
            text-align: center;
        }
        
        /* 聊天区域 */
        .chat-area {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: #f5f5f5;
        }
        
        .chat-header {
            padding: 20px;
            background: white;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .chat-header-info h2 {
            font-size: 18px;
            color: #333;
            font-weight: 600;
            margin-bottom: 4px;
        }
        
        .chat-header-info p {
            font-size: 13px;
            color: #666;
        }
        
        /* 消息区域 */
        .messages-container {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .message {
            display: flex;
            gap: 12px;
            max-width: 80%;
            animation: messageSlide 0.3s ease-out;
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
            align-self: flex-end;
            flex-direction: row-reverse;
        }
        
        .message.received {
            align-self: flex-start;
        }
        
        .message-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 14px;
            flex-shrink: 0;
        }
        
        .message-content {
            background: white;
            padding: 12px 16px;
            border-radius: 18px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            position: relative;
        }
        
        .message.sent .message-content {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .message-text {
            font-size: 14px;
            line-height: 1.4;
            margin-bottom: 6px;
        }
        
        .message-file {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            text-decoration: none;
            color: inherit;
        }
        
        .message.sent .message-file {
            background: rgba(255, 255, 255, 0.2);
        }
        
        .file-icon {
            font-size: 24px;
        }
        
        .file-info h4 {
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 2px;
        }
        
        .file-info p {
            font-size: 11px;
            opacity: 0.8;
        }
        
        .message-time {
            font-size: 11px;
            opacity: 0.7;
            text-align: right;
        }
        
        /* 输入区域 */
        .input-area {
            padding: 20px;
            background: white;
            border-top: 1px solid #e0e0e0;
        }
        
        .input-container {
            display: flex;
            align-items: flex-end;
            gap: 10px;
            background: #f5f5f5;
            padding: 15px;
            border-radius: 25px;
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
            background: #667eea;
            color: white;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            transition: all 0.3s ease;
        }
        
        .btn-icon:hover {
            background: #764ba2;
            transform: scale(1.1);
        }
        
        .btn-icon:active {
            transform: scale(0.95);
        }
        
        #file-input {
            display: none;
        }
        
        /* 右侧边栏 */
        .right-sidebar {
            width: 280px;
            background: #f8f9fa;
            border-left: 1px solid #e0e0e0;
            display: flex;
            flex-direction: column;
        }
        
        .sidebar-section {
            padding: 20px;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .sidebar-section h3 {
            font-size: 16px;
            color: #333;
            font-weight: 600;
            margin-bottom: 15px;
        }
        
        .user-profile {
            text-align: center;
        }
        
        .profile-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 32px;
            margin: 0 auto 15px;
        }
        
        .profile-info h2 {
            font-size: 18px;
            color: #333;
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .profile-info p {
            font-size: 13px;
            color: #666;
            margin-bottom: 15px;
        }
        
        .btn {
            padding: 10px 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 100%;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #ff4757 0%, #ff3742 100%);
        }
        
        .btn-danger:hover {
            box-shadow: 0 4px 12px rgba(255, 71, 87, 0.3);
        }
        
        /* 滚动条样式 */
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
        
        /* 群聊菜单样式 */
        .group-menu-item {
            display: block;
            width: 100%;
            padding: 10px 15px;
            border: none;
            background: transparent;
            cursor: pointer;
            text-align: left;
            font-size: 14px;
            color: #333;
            transition: background-color 0.2s;
            border-radius: 8px;
        }
        
        .group-menu-item:hover {
            background-color: #f5f5f5;
        }
        
        .group-menu-item:active {
            background-color: #e0e0e0;
        }
        
        /* 响应式设计 */
        @media (max-width: 1024px) {
            .right-sidebar {
                display: none;
            }
            
            .message {
                max-width: 90%;
            }
        }
        
        @media (max-width: 768px) {
            .sidebar {
                width: 250px;
            }
            
            .messages-container {
                padding: 15px;
            }
            
            .input-area {
                padding: 15px;
            }
        }
        
        @media (max-width: 576px) {
            .sidebar {
                display: none;
            }
            
            .message {
                max-width: 100%;
            }
        }
    </style>
</head>
<body>
    <!-- 封禁提示弹窗 -->
    <div id="ban-notification-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.8); z-index: 5000; flex-direction: column; align-items: center; justify-content: center;">
        <div style="background: white; padding: 30px; border-radius: 12px; width: 90%; max-width: 500px; text-align: center;">
            <h2 style="color: #d32f2f; margin-bottom: 20px; font-size: 24px;">账号已被封禁</h2>
            <p style="color: #666; margin-bottom: 15px; font-size: 16px;">您的账号已被封禁，即将退出登录</p>
            <p id="ban-reason" style="color: #333; margin-bottom: 20px; font-weight: 500;"></p>
            <p id="ban-countdown" style="color: #d32f2f; font-size: 36px; font-weight: bold; margin-bottom: 20px;">10</p>
            <p style="color: #999; font-size: 14px;">如有疑问请联系管理员</p>
        </div>
    </div>
    
    <!-- 协议同意提示弹窗 -->
    <div id="terms-agreement-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.9); z-index: 5000; flex-direction: column; align-items: center; justify-content: center; overflow: auto;">
        <div style="background: white; padding: 30px; border-radius: 12px; width: 90%; max-width: 600px; margin: 20px;">
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
    
    <?php if (isset($_SESSION['feedback_received']) && $_SESSION['feedback_received']): ?>
        <div style="position: fixed; top: 20px; right: 20px; background: #4caf50; color: white; padding: 15px 20px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15); z-index: 1000; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;">
            您的反馈已收到，正在修复中，感谢您的反馈！
        </div>
        <?php unset($_SESSION['feedback_received']); ?>
    <?php endif; ?>
    
    <!-- 群聊邀请通知 -->
    <div id="group-invitation-notifications" style="position: fixed; top: 80px; right: 20px; z-index: 1000;"></div>
    <div class="chat-container">
        <!-- 左侧边栏 -->
        <div class="sidebar">
            <div class="sidebar-header">
                <h2>Modern Chat</h2>
                <div class="user-info">
                    <div class="avatar">
                        <?php if (!empty($current_user['avatar'])): ?>
                            <img src="<?php echo $current_user['avatar']; ?>" alt="<?php echo $username; ?>" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                        <?php else: ?>
                            <?php echo substr($username, 0, 2); ?>
                        <?php endif; ?>
                    </div>
                    <div class="user-details">
                        <h3><?php echo $username; ?></h3>
                        <p>在线</p>
                    </div>
                </div>
            </div>
            
            <div class="search-bar">
                <input type="text" placeholder="搜索好友或群聊..." id="search-input">
            </div>
            
            <!-- 搜索结果区域 -->
            <div id="search-results" style="display: none; padding: 15px; background: white; border-bottom: 1px solid #e0e0e0;">
                <p style="color: #666; font-size: 14px; margin-bottom: 10px;">输入用户名或群聊名称进行搜索</p>
            </div>
            
            <!-- 创建群聊按钮 -->
            <div style="padding: 15px; background: white; border-bottom: 1px solid #e0e0e0;">
                <button class="btn" style="width: 100%; padding: 10px; font-size: 14px;" onclick="showCreateGroupForm()">+ 建立群聊</button>
            </div>
            
            <!-- 聊天类型切换 -->
            <div style="display: flex; background: white; border-bottom: 1px solid #e0e0e0;">
                <button class="chat-type-btn <?php echo $chat_type === 'friend' ? 'active' : ''; ?>" onclick="switchChatType('friend')" style="flex: 1; padding: 12px; border: none; background: transparent; cursor: pointer; font-size: 14px; font-weight: 600; color: <?php echo $chat_type === 'friend' ? '#667eea' : '#666'; ?>; border-bottom: 2px solid <?php echo $chat_type === 'friend' ? '#667eea' : 'transparent'; ?>;">好友</button>
                <button class="chat-type-btn <?php echo $chat_type === 'group' ? 'active' : ''; ?>" onclick="switchChatType('group')" style="flex: 1; padding: 12px; border: none; background: transparent; cursor: pointer; font-size: 14px; font-weight: 600; color: <?php echo $chat_type === 'group' ? '#667eea' : '#666'; ?>; border-bottom: 2px solid <?php echo $chat_type === 'group' ? '#667eea' : 'transparent'; ?>;">群聊</button>
            </div>
            
            <!-- 好友列表 -->
            <div class="friends-list" id="friends-list" style="<?php echo $chat_type === 'friend' ? 'display: block;' : 'display: none;'; ?>">
                <?php foreach ($friends as $friend_item): ?>
                    <?php 
                        $friend_unread_key = 'friend_' . $friend_item['id'];
                        $friend_unread_count = isset($unread_counts[$friend_unread_key]) ? $unread_counts[$friend_unread_key] : 0;
                    ?>
                    <div class="friend-item <?php echo $chat_type === 'friend' && $selected_id == $friend_item['id'] ? 'active' : ''; ?>" data-friend-id="<?php echo $friend_item['id']; ?>">
                        <div class="friend-avatar">
                            <?php if (!empty($friend_item['avatar'])): ?>
                                <img src="<?php echo $friend_item['avatar']; ?>" alt="<?php echo $friend_item['username']; ?>" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                            <?php else: ?>
                                <?php echo substr($friend_item['username'], 0, 2); ?>
                            <?php endif; ?>
                            <div class="status-indicator <?php echo $friend_item['status']; ?>"></div>
                        </div>
                        <div class="friend-info" style="position: relative;">
                            <h3><?php echo $friend_item['username']; ?></h3>
                            <p><?php echo $friend_item['status'] == 'online' ? '在线' : '离线'; ?></p>
                            <?php if ($friend_unread_count > 0): ?>
                                <div style="position: absolute; top: 0; right: -10px; background: #ff4757; color: white; border-radius: 50%; width: 20px; height: 20px; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: bold;">
                                    <?php echo $friend_unread_count > 99 ? '99+' : $friend_unread_count; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <!-- 三个点菜单 -->
                        <div style="position: relative;">
                            <button class="btn-icon" style="width: 30px; height: 30px; font-size: 12px;" onclick="toggleFriendMenu(event, <?php echo $friend_item['id']; ?>, '<?php echo $friend_item['username']; ?>')">
                                ⋮
                            </button>
                            <!-- 好友菜单 -->
                            <div class="friend-menu" id="friend-menu-<?php echo $friend_item['id']; ?>" style="display: none; position: absolute; top: 0; right: 0; background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1); z-index: 1000; min-width: 120px;">
                                <button class="group-menu-item" onclick="deleteFriend(<?php echo $friend_item['id']; ?>, '<?php echo $friend_item['username']; ?>')">删除好友</button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <!-- 群聊列表 -->
            <div class="friends-list" id="groups-list" style="<?php echo $chat_type === 'group' ? 'display: block;' : 'display: none;'; ?>">
                <?php foreach ($groups as $group_item): ?>
                    <?php 
                        $group_unread_key = 'group_' . $group_item['id'];
                        $group_unread_count = isset($unread_counts[$group_unread_key]) ? $unread_counts[$group_unread_key] : 0;
                    ?>
                    <div class="friend-item <?php echo $chat_type === 'group' && $selected_id == $group_item['id'] ? 'active' : ''; ?>" data-group-id="<?php echo $group_item['id']; ?>">
                        <div class="friend-avatar" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                            👥
                        </div>
                        <div class="friend-info" style="position: relative;">
                            <h3><?php echo $group_item['name']; ?></h3>
                            <p><?php echo $group_item['member_count']; ?> 成员</p>
                            <?php if ($group_unread_count > 0): ?>
                                <div style="position: absolute; top: 0; right: -10px; background: #ff4757; color: white; border-radius: 50%; width: 20px; height: 20px; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: bold;">
                                    <?php echo $group_unread_count > 99 ? '99+' : $group_unread_count; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <!-- 三个点菜单 -->
                        <div style="position: relative;">
                            <button class="btn-icon" style="width: 30px; height: 30px; font-size: 12px;" onclick="toggleGroupMenu(event, <?php echo $group_item['id']; ?>)">
                                ⋮
                            </button>
                            <!-- 群聊菜单 -->
                            <div class="group-menu" id="group-menu-<?php echo $group_item['id']; ?>" style="display: none; position: absolute; top: 0; right: 0; background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1); z-index: 1000; min-width: 150px;">
                                <button class="group-menu-item" onclick="showGroupMembers(<?php echo $group_item['id']; ?>)">查看成员</button>
                                <button class="group-menu-item" onclick="inviteFriendsToGroup(<?php echo $group_item['id']; ?>)">邀请好友</button>
                                <?php if ($group_item['owner_id'] == $user_id): ?>
                                    <button class="group-menu-item" onclick="transferGroupOwnership(<?php echo $group_item['id']; ?>)">转让群主</button>
                                    <button class="group-menu-item" onclick="deleteGroup(<?php echo $group_item['id']; ?>)">解散群聊</button>
                                <?php else: ?>
                                    <button class="group-menu-item" onclick="leaveGroup(<?php echo $group_item['id']; ?>)">退出群聊</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <!-- 创建群聊表单 -->
            <div id="create-group-form" style="display: none; padding: 15px; background: white; border-bottom: 1px solid #e0e0e0;">
                <h4 style="margin-bottom: 15px; font-size: 14px; color: #333;">创建群聊</h4>
                <div style="margin-bottom: 15px;">
                    <label for="group-name" style="display: block; margin-bottom: 8px; font-size: 13px; color: #555;">群聊名称</label>
                    <input type="text" id="group-name" placeholder="输入群聊名称" style="width: 100%; padding: 8px; border: 1px solid #e0e0e0; border-radius: 4px; font-size: 14px;">
                </div>
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 8px; font-size: 13px; color: #555;">选择好友</label>
                    <div id="group-members-select" style="max-height: 200px; overflow-y: auto; border: 1px solid #e0e0e0; border-radius: 4px; padding: 10px;">
                        <?php foreach ($friends as $friend_item): ?>
                            <div style="display: flex; align-items: center; margin-bottom: 10px;">
                                <input type="checkbox" id="member-<?php echo $friend_item['id']; ?>" value="<?php echo $friend_item['id']; ?>" style="margin-right: 10px;">
                                <label for="member-<?php echo $friend_item['id']; ?>" style="font-size: 14px; color: #333;"><?php echo $friend_item['username']; ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div style="display: flex; gap: 10px;">
                    <button class="btn" style="flex: 1; padding: 6px; font-size: 12px;" onclick="createGroup()">创建群聊</button>
                    <button class="btn btn-secondary" style="flex: 1; padding: 6px; font-size: 12px;" onclick="hideCreateGroupForm()">取消</button>
                </div>
            </div>
        </div>
        
        <!-- 聊天区域 -->
        <div class="chat-area">
            <?php if (($chat_type === 'friend' && $selected_friend) || ($chat_type === 'group' && $selected_group)): ?>
                <div class="chat-header">
                    <?php if ($chat_type === 'friend'): ?>
                        <div class="friend-avatar">
                            <?php if (!empty($selected_friend['avatar'])): ?>
                                <img src="<?php echo $selected_friend['avatar']; ?>" alt="<?php echo $selected_friend['username']; ?>" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                            <?php else: ?>
                                <?php echo substr($selected_friend['username'], 0, 2); ?>
                            <?php endif; ?>
                            <div class="status-indicator <?php echo $selected_friend['status']; ?>"></div>
                        </div>
                        <div class="chat-header-info">
                            <h2><?php echo $selected_friend['username']; ?></h2>
                            <p><?php echo $selected_friend['status'] == 'online' ? '在线' : '离线'; ?></p>
                        </div>
                    <?php else: ?>
                        <div class="friend-avatar" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                            👥
                        </div>
                        <div class="chat-header-info">
                            <h2><?php echo $selected_group['name']; ?></h2>
                            <p><?php echo $group->getGroupMembers($selected_group['id']) ? count($group->getGroupMembers($selected_group['id'])) : 0; ?> 成员</p>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="messages-container" id="messages-container">
                    <!-- 聊天记录将通过JavaScript动态加载 -->
                </div>
                
                <!-- 初始聊天记录数据 -->
                <script>
                    // 初始聊天记录数据
                    const initialChatHistory = <?php echo json_encode($chat_history); ?>;
                    const chatType = '<?php echo $chat_type; ?>';
                    const selectedId = '<?php echo $selected_id; ?>';
                    
                    // 加载初始聊天记录
                    function loadInitialChatHistory() {
                        const messagesContainer = document.getElementById('messages-container');
                        if (!messagesContainer) return;
                        
                        initialChatHistory.forEach(msg => {
                            const isSent = msg.sender_id == <?php echo $user_id; ?>;
                            const messageElement = createMessage(msg, isSent);
                            messagesContainer.appendChild(messageElement);
                        });
                        
                        // 滚动到底部
                        messagesContainer.scrollTop = messagesContainer.scrollHeight;
                    }
                    
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
                        }
                    })
                    .catch(error => {
                        console.error('标记消息为已读失败:', error);
                    });
                }
                
                // 页面加载完成后加载初始聊天记录和设置
                document.addEventListener('DOMContentLoaded', () => {
                    loadInitialChatHistory();
                    loadSettings();
                    
                    // 标记消息为已读
                    markMessagesAsRead();
                });
                </script>
                
                <div class="input-area">
                    <form id="message-form" enctype="multipart/form-data">
                        <input type="hidden" name="chat_type" value="<?php echo $chat_type; ?>">
                        <input type="hidden" name="id" value="<?php echo $selected_id; ?>">
                        <?php if ($chat_type === 'friend'): ?>
                            <input type="hidden" name="friend_id" value="<?php echo $selected_id; ?>">
                        <?php endif; ?>
                        <div class="input-container">
                            <div class="input-actions">
                                <label for="file-input" class="btn-icon" title="发送文件">
                                    📎
                                </label>
                                <input type="file" id="file-input" name="file" accept="*/*">
                                
                                <!-- 录音按钮 -->
                                <button type="button" id="record-btn" class="btn-icon" title="长按Q键录音" onclick="toggleRecording()">
                                    🎤
                                </button>
                            </div>
                            <div class="input-wrapper" style="position: relative;">
                                <textarea id="message-input" name="message" placeholder="输入消息..."></textarea>
                                
                                <!-- 录音状态指示器 -->
                                <div id="recording-indicator" style="display: none; position: absolute; bottom: 10px; left: 10px; color: #ff4757; font-size: 12px; font-weight: bold;">
                                    <span class="recording-dots">● ● ●</span> 录音中...
                                </div>
                                
                                <!-- @用户下拉选择框 -->
                                <div id="mention-dropdown" style="display: none; position: absolute; bottom: 100%; left: 0; width: 100%; max-height: 200px; overflow-y: auto; background: white; border: 1px solid #e0e0e0; border-radius: 8px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1); z-index: 1000;">
                                    <!-- 成员列表将通过JavaScript动态生成 -->
                                </div>
                            </div>
                            <div class="input-actions">
                                <button type="submit" class="btn-icon" title="发送消息">
                                    ➤
                                </button>
                            </div>
                        </div>
                    </form>
                    
                    <!-- 录音提示 -->
                    <div id="recording-hint" style="display: none; position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%); background: rgba(0, 0, 0, 0.8); color: white; padding: 10px 20px; border-radius: 20px; font-size: 14px; z-index: 1000;">
                        <span style="margin-right: 10px;">🎤</span> 长按Q键录音，松开发送
                    </div>
                </div>
            <?php else: ?>
                <div class="messages-container" style="justify-content: center; align-items: center; text-align: center;">
                    <h2 style="color: #666; margin-bottom: 10px;">选择一个聊天对象开始聊天</h2>
                    <p style="color: #999;">从左侧列表中选择好友或群聊，开始你们的对话</p>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- 右侧边栏 -->
        <div class="right-sidebar">
            <a href="https://github.com/LzdqesjG/modern-chat" class="github-corner" aria-label="View source on GitHub"><svg width="80" height="80" viewBox="0 0 250 250" style="fill:#151513; color:#fff; position: absolute; top: 0; border: 0; right: 0;" aria-hidden="true"><path d="M0,0 L115,115 L130,115 L142,142 L250,250 L250,0 Z"/><path d="M128.3,109.0 C113.8,99.7 119.0,89.6 119.0,89.6 C122.0,82.7 120.5,78.6 120.5,78.6 C119.2,72.0 123.4,76.3 123.4,76.3 C127.3,80.9 125.5,87.3 125.5,87.3 C122.9,97.6 130.6,101.9 134.4,103.2" fill="currentColor" style="transform-origin: 130px 106px;" class="octo-arm"/><path d="M115.0,115.0 C114.9,115.1 118.7,116.5 119.8,115.4 L133.7,101.6 C136.9,99.2 139.9,98.4 142.2,98.6 C133.8,88.0 127.5,74.4 143.8,58.0 C148.5,53.4 154.0,51.2 159.7,51.0 C160.3,49.4 163.2,43.6 171.4,40.1 C171.4,40.1 176.1,42.5 178.8,56.2 C183.1,58.6 187.2,61.8 190.9,65.4 C194.5,69.0 197.7,73.2 200.1,77.6 C213.8,80.2 216.3,84.9 216.3,84.9 C212.7,93.1 206.9,96.0 205.4,96.6 C205.1,102.4 203.0,107.8 198.3,112.5 C181.9,128.9 168.3,122.5 157.7,114.1 C157.9,116.9 156.7,120.9 152.7,124.9 L141.0,136.5 C139.8,137.7 141.6,141.9 141.8,141.8 Z" fill="currentColor" class="octo-body"/></svg></a><style>.github-corner:hover .octo-arm{animation:octocat-wave 560ms ease-in-out}@keyframes octocat-wave{0%,100%{transform:rotate(0)}20%,60%{transform:rotate(-25deg)}40%,80%{transform:rotate(10deg)}}@media (max-width:500px){.github-corner:hover .octo-arm{animation:none}.github-corner .octo-arm{animation:octocat-wave 560ms ease-in-out}}</style>
            <div class="sidebar-section">
                <div class="user-profile">
                        <div class="profile-avatar">
                            <?php if (!empty($current_user['avatar'])): ?>
                                <img src="<?php echo $current_user['avatar']; ?>" alt="<?php echo $username; ?>" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                            <?php else: ?>
                                <?php echo substr($username, 0, 2); ?>
                            <?php endif; ?>
                        </div>
                        <div class="profile-info">
                            <h2><?php echo $username; ?></h2>
                            <p><?php echo $_SESSION['email']; ?></p>
                            <?php 
                            // 获取用户IP地址
                            function getUserIP() {
                                if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
                                    return $_SERVER['HTTP_CLIENT_IP'];
                                } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                                    return $_SERVER['HTTP_X_FORWARDED_FOR'];
                                } else {
                                    return $_SERVER['REMOTE_ADDR'];
                                }
                            }
                            $user_ip = getUserIP();
                            ?>
                            <p style="font-size: 12px; color: #666; margin-top: 2px;">IP地址: <?php echo $user_ip; ?></p>
                        </div>
                    <a href="edit_profile.php" class="btn" style="margin-top: 15px; text-decoration: none; display: block; text-align: center;">编辑资料</a>
                    
                    <!-- 添加好友功能 -->
                    <button class="btn" style="margin-top: 10px;" onclick="showAddFriendForm()">添加好友</button>
                    
                    <!-- 添加好友表单 -->
                    <div id="add-friend-form" style="display: none; margin-top: 15px; padding: 15px; background: #f8f9fa; border-radius: 8px;">
                        <h4 style="margin-bottom: 15px; font-size: 14px; color: #333;">添加好友</h4>
                        <div style="margin-bottom: 15px;">
                            <label for="add-friend-username" style="display: block; margin-bottom: 8px; font-size: 13px; color: #555;">用户名</label>
                            <input type="text" id="add-friend-username" placeholder="输入好友用户名" style="width: 100%; padding: 8px; border: 1px solid #e0e0e0; border-radius: 4px; font-size: 14px;">
                        </div>
                        <div style="display: flex; gap: 10px;">
                            <button class="btn" style="flex: 1; padding: 6px; font-size: 12px;" onclick="addFriend()">发送请求</button>
                            <button class="btn btn-secondary" style="flex: 1; padding: 6px; font-size: 12px;" onclick="hideAddFriendForm()">取消</button>
                        </div>
                    </div>
                    
                    <!-- 设置按钮 -->
                    <button class="btn" style="margin-top: 10px;" onclick="toggleSettings()">设置</button>
                    
                    <!-- 设置面板 -->
                    <div id="settings-panel" style="display: none; margin-top: 15px; padding: 15px; background: #f8f9fa; border-radius: 8px;">
                        <h4 style="margin-bottom: 15px; font-size: 14px; color: #333;">设置</h4>
                        
                        <!-- 新消息提示设置 -->
                        <div style="margin-bottom: 15px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                                <label for="notification-sound" style="font-size: 13px; color: #555;">新消息提示音</label>
                                <input type="checkbox" id="notification-sound" checked>
                            </div>
                            <p style="font-size: 12px; color: #999; margin-top: 4px;">收到新消息时播放提示音</p>
                        </div>
                        
                        <!-- 任务栏通知设置 -->
                        <div style="margin-bottom: 15px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                                <label for="taskbar-notification" style="font-size: 13px; color: #555;">任务栏通知</label>
                                <input type="checkbox" id="taskbar-notification" checked>
                            </div>
                            <p style="font-size: 12px; color: #999; margin-top: 4px;">收到新消息时显示任务栏通知</p>
                        </div>
                        
                        <!-- 链接弹窗设置 -->
                        <div style="margin-bottom: 15px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                                <label for="link-popup" style="font-size: 13px; color: #555;">链接弹窗显示</label>
                                <input type="checkbox" id="link-popup">
                            </div>
                            <p style="font-size: 12px; color: #999; margin-top: 4px;">点击链接时使用弹窗iframe显示</p>
                            
                            <!-- 传递cookie选项 -->
                            <div style="margin-top: 10px; margin-left: 15px;">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <label for="pass-cookies" style="font-size: 12px; color: #666;">传递Cookie</label>
                                    <input type="checkbox" id="pass-cookies" checked>
                                </div>
                                <p style="font-size: 11px; color: #999; margin-top: 2px;">允许弹窗iframe传递Cookie</p>
                            </div>
                        </div>
                        
                        <!-- 保存设置按钮 -->
                        <button class="btn" style="width: 100%; padding: 6px; font-size: 12px;" onclick="saveSettings()">保存设置</button>
                    </div>
                    
                    <!-- 反馈按钮 -->
                    <button class="btn" style="margin-top: 10px;" onclick="showFeedbackModal()">反馈问题</button>
                    
                    <button class="btn btn-danger" style="margin-top: 10px;" onclick="logout()">退出登录</button>
                </div>
            </div>
            
            <div class="sidebar-section">
                <h3>好友请求 <?php if (!empty($pending_requests)): ?><span style="background: #ff4757; color: white; padding: 2px 6px; border-radius: 10px; font-size: 12px; margin-left: 8px;"><?php echo count($pending_requests); ?></span><?php endif; ?></h3>
                <?php $pending_requests = $friend->getPendingRequests($user_id); ?>
                <?php if (empty($pending_requests)): ?>
                    <p style="color: #999; font-size: 14px;">没有待处理的好友请求</p>
                <?php else: ?>
                    <?php foreach ($pending_requests as $request): ?>
                        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 15px; padding: 10px; background: #f8f9fa; border-radius: 8px;">
                            <div class="friend-avatar" style="width: 40px; height: 40px; font-size: 16px;">
                                <?php echo substr($request['username'], 0, 2); ?>
                            </div>
                            <div style="flex: 1;">
                                <h4 style="font-size: 14px; margin-bottom: 2px;"><?php echo $request['username']; ?></h4>
                                <p style="font-size: 12px; color: #999;"><?php echo $request['email']; ?></p>
                                <p style="font-size: 11px; color: #999; margin-top: 2px;"><?php echo date('Y-m-d H:i', strtotime($request['created_at'])); ?></p>
                            </div>
                            <div style="display: flex; gap: 5px;">
                                <button class="btn" style="padding: 4px 8px; font-size: 11px; background: #4caf50;" onclick="acceptRequest(<?php echo $request['id']; ?>)">接受</button>
                                <button class="btn" style="padding: 4px 8px; font-size: 11px; background: #ff4757;" onclick="rejectRequest(<?php echo $request['id']; ?>)">拒绝</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
        // 录音相关变量
        let mediaRecorder = null;
        let audioChunks = [];
        let isRecording = false;
        let isQKeyPressed = false;
        
        // 录音初始化函数
        async function initRecording() {
            try {
                const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                mediaRecorder = new MediaRecorder(stream);
                
                mediaRecorder.ondataavailable = (event) => {
                    if (event.data.size > 0) {
                        audioChunks.push(event.data);
                    }
                };
                
                mediaRecorder.onstop = () => {
                    const audioBlob = new Blob(audioChunks, { type: 'audio/webm' });
                    sendAudioMessage(audioBlob);
                    audioChunks = [];
                };
                
                return true;
            } catch (error) {
                console.error('录音初始化失败:', error);
                alert('无法访问麦克风，请检查权限设置');
                return false;
            }
        }
        
        // 开始录音
        async function startRecording() {
            if (!mediaRecorder) {
                const success = await initRecording();
                if (!success) return;
            }
            
            isRecording = true;
            audioChunks = [];
            
            // 更新UI
            document.getElementById('record-btn').classList.add('recording');
            document.getElementById('recording-indicator').style.display = 'block';
            document.getElementById('recording-hint').style.display = 'block';
            
            // 开始录音
            mediaRecorder.start();
            console.log('开始录音');
        }
        
        // 停止录音
        function stopRecording() {
            if (!isRecording || !mediaRecorder) return;
            
            isRecording = false;
            
            // 更新UI
            document.getElementById('record-btn').classList.remove('recording');
            document.getElementById('recording-indicator').style.display = 'none';
            document.getElementById('recording-hint').style.display = 'none';
            
            // 停止录音
            mediaRecorder.stop();
            console.log('停止录音');
        }
        
        // 切换录音状态（点击按钮）
        function toggleRecording() {
            if (isRecording) {
                stopRecording();
            } else {
                startRecording();
            }
        }
        
        // 发送音频消息
        async function sendAudioMessage(audioBlob) {
            if (!audioBlob || audioBlob.size === 0) {
                console.error('音频文件为空');
                return;
            }
            
            const messagesContainer = document.getElementById('messages-container');
            if (!messagesContainer) return;
            
            // 创建临时音频消息
            const tempMessage = createTempAudioMessage(audioBlob);
            messagesContainer.appendChild(tempMessage);
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
            
            try {
                // 创建FormData
                const formData = new FormData();
                
                // 动态获取当前聊天类型和选中的ID
                const currentChatType = document.querySelector('input[name="chat_type"]').value;
                const currentSelectedId = document.querySelector('input[name="id"]').value;
                
                // 根据聊天类型添加不同的参数
                formData.append('chat_type', currentChatType);
                formData.append('id', currentSelectedId);
                
                if (currentChatType === 'friend') {
                    formData.append('friend_id', currentSelectedId);
                }
                
                formData.append('file', audioBlob, 'recording.webm');
                
                // 发送请求
                const response = await fetch('send_message.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    // 替换临时消息为真实消息
                    tempMessage.remove();
                    const newMessage = createMessage(result.message, true);
                    messagesContainer.appendChild(newMessage);
                    messagesContainer.scrollTop = messagesContainer.scrollHeight;
                    
                    // 更新lastMessageId
                    if (result.message.id > lastMessageId) {
                        lastMessageId = result.message.id;
                    }
                } else {
                    // 显示错误
                    tempMessage.remove();
                    alert(result.message);
                }
            } catch (error) {
                console.error('发送音频消息失败:', error);
                tempMessage.remove();
                alert('发送音频消息失败，请稍后重试');
            }
        }
        
        // 创建临时音频消息
        function createTempAudioMessage(audioBlob) {
            const messageDiv = document.createElement('div');
            messageDiv.className = 'message sent';
            
            const avatarDiv = document.createElement('div');
            avatarDiv.className = 'message-avatar';
            
            // 获取当前用户头像
            const currentUserAvatar = '<?php echo !empty($current_user['avatar']) ? $current_user['avatar'] : ''; ?>';
            
            if (currentUserAvatar) {
                const img = document.createElement('img');
                img.src = currentUserAvatar;
                img.alt = '<?php echo $username; ?>';
                img.style.cssText = 'width: 100%; height: 100%; border-radius: 50%; object-fit: cover;';
                avatarDiv.appendChild(img);
            } else {
                avatarDiv.textContent = '<?php echo substr($username, 0, 2); ?>';
            }
            
            const contentDiv = document.createElement('div');
            contentDiv.className = 'message-content';
            
            const audioContainer = document.createElement('div');
            audioContainer.style.cssText = 'margin: 5px 0;';
            
            const audio = document.createElement('audio');
            audio.src = URL.createObjectURL(audioBlob);
            audio.controls = true;
            audio.style.cssText = 'max-width: 300px; width: 100%;';
            audio.setAttribute('preload', 'metadata');
            
            audioContainer.appendChild(audio);
            contentDiv.appendChild(audioContainer);
            
            const timeDiv = document.createElement('div');
            timeDiv.className = 'message-time';
            timeDiv.textContent = new Date().toLocaleTimeString('zh-CN', { hour: '2-digit', minute: '2-digit' });
            contentDiv.appendChild(timeDiv);
            
            messageDiv.appendChild(avatarDiv);
            messageDiv.appendChild(contentDiv);
            
            return messageDiv;
        }
        
        // 键盘事件监听 - 长按Q键录音
        document.addEventListener('keydown', async (e) => {
            if (e.key.toLowerCase() === 'q' && !isQKeyPressed && !isRecording) {
                isQKeyPressed = true;
                await startRecording();
            }
        });
        
        document.addEventListener('keyup', (e) => {
            if (e.key.toLowerCase() === 'q' && isQKeyPressed) {
                isQKeyPressed = false;
                stopRecording();
            }
        });
        
        // 文件选择事件监听 - 当选择文件后自动提交
        document.getElementById('file-input').addEventListener('change', (e) => {
            const file = e.target.files[0];
            if (file) {
                console.log('文件已选择，自动提交表单');
                document.getElementById('message-form').dispatchEvent(new Event('submit'));
            }
        });
        
        // 群聊@功能自动补全
        let groupMembers = [];
        let mentionDropdown = document.getElementById('mention-dropdown');
        let messageInput = document.getElementById('message-input');
        let isMentioning = false;
        let currentMentionIndex = -1;
        
        // 获取群聊成员列表
        async function fetchGroupMembers() {
            <?php if ($chat_type === 'group'): ?>
                try {
                    const response = await fetch(`get_group_members.php?group_id=<?php echo $selected_id; ?>`);
                    const data = await response.json();
                    if (data.success) {
                        groupMembers = data.members;
                    }
                } catch (error) {
                    console.error('获取群成员失败:', error);
                }
            <?php endif; ?>
        }
        
        // 初始化群成员数据
        fetchGroupMembers();
        
        // 显示@下拉列表
        function showMentionDropdown() {
            mentionDropdown.style.display = 'block';
        }
        
        // 隐藏@下拉列表
        function hideMentionDropdown() {
            mentionDropdown.style.display = 'none';
            isMentioning = false;
            currentMentionIndex = -1;
        }
        
        // 更新@下拉列表内容
        function updateMentionDropdown(filter = '') {
            if (!groupMembers.length) return;
            
            let filteredMembers = groupMembers;
            if (filter) {
                filteredMembers = groupMembers.filter(member => 
                    member.username.toLowerCase().includes(filter.toLowerCase())
                );
            }
            
            // 显示全部成员，不再限制数量
            // filteredMembers = filteredMembers.slice(0, 5);
            
            mentionDropdown.innerHTML = '';
            
            filteredMembers.forEach((member, index) => {
                const memberItem = document.createElement('div');
                memberItem.className = 'mention-item';
                memberItem.innerHTML = `
                    <div style="display: flex; align-items: center; padding: 10px; cursor: pointer; transition: background-color 0.2s;">
                        <div style="width: 30px; height: 30px; border-radius: 50%; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 12px; margin-right: 10px;">
                            ${member.username.charAt(0)}
                        </div>
                        <div>
                            <div style="font-weight: 500; font-size: 14px;">${member.username}</div>
                            ${member.is_owner ? '<div style="font-size: 12px; color: #ff4757;">群主</div>' : member.is_admin ? '<div style="font-size: 12px; color: #ffa502;">管理员</div>' : ''}
                        </div>
                    </div>
                `;
                
                // 添加悬停效果
                memberItem.addEventListener('mouseenter', () => {
                    memberItem.style.backgroundColor = '#f0f0f0';
                });
                
                memberItem.addEventListener('mouseleave', () => {
                    memberItem.style.backgroundColor = 'transparent';
                });
                
                // 添加点击事件
                memberItem.addEventListener('click', () => {
                    insertMention(member.username);
                    hideMentionDropdown();
                });
                
                mentionDropdown.appendChild(memberItem);
            });
            
            if (filteredMembers.length > 0) {
                showMentionDropdown();
            } else {
                hideMentionDropdown();
            }
        }
        
        // 插入@用户名到输入框
        function insertMention(username) {
            const cursorPos = messageInput.selectionStart;
            const textBeforeCursor = messageInput.value.substring(0, cursorPos);
            const textAfterCursor = messageInput.value.substring(cursorPos);
            
            // 找到@符号的位置
            const atIndex = textBeforeCursor.lastIndexOf('@');
            if (atIndex !== -1) {
                // 替换@及之后的内容为@username
                const newText = textBeforeCursor.substring(0, atIndex) + '@' + username + ' ' + textAfterCursor;
                messageInput.value = newText;
                
                // 设置光标位置到@username之后
                const newCursorPos = atIndex + username.length + 2; // @ + username + 空格
                messageInput.focus();
                messageInput.setSelectionRange(newCursorPos, newCursorPos);
            }
        }
        
        // 消息输入框输入事件 - 处理@功能
        messageInput.addEventListener('input', (e) => {
            const cursorPos = messageInput.selectionStart;
            const textBeforeCursor = messageInput.value.substring(0, cursorPos);
            
            // 检查最后一个@符号的位置
            const lastAtIndex = textBeforeCursor.lastIndexOf('@');
            
            // 检查@符号后面是否有空格或其他字符
            const textAfterAt = textBeforeCursor.substring(lastAtIndex + 1);
            const hasSpaceAfterAt = textAfterAt.includes(' ');
            
            if (lastAtIndex !== -1 && !hasSpaceAfterAt) {
                // 用户正在输入@
                isMentioning = true;
                const filter = textAfterAt;
                updateMentionDropdown(filter);
            } else {
                // 用户没有在输入@或者@后面有空格
                hideMentionDropdown();
            }
        });
        
        // 消息输入框键盘事件 - Enter发送，Shift+Enter换行
        document.getElementById('message-input').addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                hideMentionDropdown();
                document.getElementById('message-form').dispatchEvent(new Event('submit'));
            } else if (e.key === 'Escape') {
                // 按ESC键隐藏下拉列表
                hideMentionDropdown();
            } else if (e.key === 'ArrowUp') {
                // 按上箭头选择上一个成员
                e.preventDefault();
                if (isMentioning && mentionDropdown.children.length > 0) {
                    currentMentionIndex = Math.max(0, currentMentionIndex - 1);
                    highlightMentionItem(currentMentionIndex);
                }
            } else if (e.key === 'ArrowDown') {
                // 按下箭头选择下一个成员
                e.preventDefault();
                if (isMentioning && mentionDropdown.children.length > 0) {
                    currentMentionIndex = Math.min(mentionDropdown.children.length - 1, currentMentionIndex + 1);
                    highlightMentionItem(currentMentionIndex);
                }
            } else if (e.key === 'Tab' || e.key === 'Enter') {
                // 按Tab或Enter键选择当前高亮的成员
                if (isMentioning && currentMentionIndex >= 0 && mentionDropdown.children.length > 0) {
                    e.preventDefault();
                    const selectedMember = groupMembers[currentMentionIndex];
                    insertMention(selectedMember.username);
                    hideMentionDropdown();
                }
            }
        });
        
        // 高亮@列表中的当前选中项
        function highlightMentionItem(index) {
            // 移除所有高亮
            Array.from(mentionDropdown.children).forEach(item => {
                item.style.backgroundColor = 'transparent';
            });
            
            // 添加当前项高亮
            if (index >= 0 && index < mentionDropdown.children.length) {
                mentionDropdown.children[index].style.backgroundColor = '#e0e0e0';
                // 滚动到可视区域
                mentionDropdown.children[index].scrollIntoView({ block: 'nearest' });
            }
        }
        
        // 点击页面其他地方隐藏@下拉列表
        document.addEventListener('click', (e) => {
            if (!messageInput.contains(e.target) && !mentionDropdown.contains(e.target)) {
                hideMentionDropdown();
            }
        });
        
        // 消息队列实现
        let isSending = false;
        const messageQueue = [];
        
        // 发送消息队列中的下一条消息
        async function processMessageQueue() {
            if (isSending || messageQueue.length === 0) {
                return;
            }
            
            // 设置发送状态为true
            isSending = true;
            
            // 从队列中取出第一条消息
            const queueItem = messageQueue.shift();
            const { formData, messageText, file, tempMessage, messageInput, messagesContainer } = queueItem;
            
            try {
                // 检查FormData内容
                console.log('FormData内容:');
                for (const [key, value] of formData.entries()) {
                    if (value instanceof File) {
                        console.log(key, value.name, value.size, value.type);
                    } else {
                        console.log(key, value);
                    }
                }
                
                // 添加调试信息
                console.log('发送消息请求:', { messageText, file: file ? { name: file.name, size: file.size, type: file.type } : null });
                
                // 开始发送请求
                console.log('开始发送fetch请求...');
                
                const response = await fetch('send_message.php', {
                    method: 'POST',
                    body: formData
                });
                
                console.log('请求已发送，等待响应...');
                
                const result = await response.json();
                
                console.log('发送消息结果:', result);
                
                if (result.success) {
                    // 替换临时消息为真实消息
                    tempMessage.remove();
                    const newMessage = createMessage(result.message, true);
                    messagesContainer.appendChild(newMessage);
                    messagesContainer.scrollTop = messagesContainer.scrollHeight;
                    
                    // 更新lastMessageId为最新消息ID
                    if (result.message.id > lastMessageId) {
                        lastMessageId = result.message.id;
                        console.log('更新lastMessageId为:', lastMessageId);
                    }
                } else {
                    // 显示错误
                    tempMessage.remove();
                    alert(result.message);
                }
            } catch (error) {
                console.error('发送消息失败:', error);
                tempMessage.remove();
                alert('发送消息失败，请稍后重试');
            } finally {
                // 设置发送状态为false
                isSending = false;
                
                // 处理队列中的下一条消息
                processMessageQueue();
            }
        }
        
        // 发送消息
        document.getElementById('message-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            
            // 添加更多调试信息
            console.log('表单提交事件触发');
            
            const formData = new FormData(e.target);
            const messageInput = document.getElementById('message-input');
            const messagesContainer = document.getElementById('messages-container');
            
            const messageText = messageInput.value.trim();
            const file = document.getElementById('file-input').files[0];
            
            console.log('消息文本:', messageText);
            console.log('文件:', file);
            
            if (!messageText && !file) {
                console.log('没有消息文本和文件，不发送');
                return;
            }
            
            // 验证消息内容，禁止HTML标签
            if (messageText && /<[^>]*>/.test(messageText)) {
                alert('消息中不能包含HTML标签');
                return;
            }
            
            // 文件大小验证（从配置中获取）
            const maxFileSize = <?php echo getConfig('upload_files_max', 150); ?> * 1024 * 1024;
            if (file && file.size > maxFileSize) {
                alert('文件大小不能超过' + <?php echo getConfig('upload_files_max', 150); ?> + 'MB');
                return;
            }
            
            // 添加临时消息
            const tempMessage = createTempMessage(messageText, file);
            messagesContainer.appendChild(tempMessage);
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
            
            // 清空输入
            messageInput.value = '';
            document.getElementById('file-input').value = '';
            
            // 将消息添加到队列
            messageQueue.push({
                formData,
                messageText,
                file,
                tempMessage,
                messageInput,
                messagesContainer
            });
            
            console.log('消息已添加到队列，当前队列长度:', messageQueue.length);
            
            // 处理消息队列
            processMessageQueue();
        });
        
        // 创建临时消息
        function createTempMessage(text, file) {
            const messageDiv = document.createElement('div');
            messageDiv.className = 'message sent';
            
            const avatarDiv = document.createElement('div');
            avatarDiv.className = 'message-avatar';
            
            // 获取当前用户头像
            const currentUserAvatar = '<?php echo !empty($current_user['avatar']) ? $current_user['avatar'] : ''; ?>';
            
            if (currentUserAvatar) {
                const img = document.createElement('img');
                img.src = currentUserAvatar;
                img.alt = '<?php echo $username; ?>';
                img.style.cssText = 'width: 100%; height: 100%; border-radius: 50%; object-fit: cover;';
                avatarDiv.appendChild(img);
            } else {
                avatarDiv.textContent = '<?php echo substr($username, 0, 2); ?>';
            }
            
            const contentDiv = document.createElement('div');
            contentDiv.className = 'message-content';
            
            if (file) {
                const fileName = file.name;
                const fileExtension = fileName.split('.').pop().toLowerCase();
                const fileUrl = URL.createObjectURL(file);
                
                console.log('临时文件信息:', {
                    fileName: fileName,
                    fileExtension: fileExtension,
                    fileUrl: fileUrl
                });
                
                // 图片类型 - 确保所有图片文件都显示为图片
                const imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'tiff', 'ico'];
                if (imageExtensions.includes(fileExtension)) {
                    console.log('临时消息: 检测到图片文件，创建img标签');
                    const imgContainer = document.createElement('div');
                    imgContainer.style.cssText = 'display: inline-block; margin: 5px;';
                    
                    const img = document.createElement('img');
                    img.src = fileUrl;
                    img.alt = fileName;
                    img.style.cssText = `
                        max-width: 200px;
                        max-height: 200px;
                        cursor: pointer;
                        border-radius: 8px;
                        transition: transform 0.2s;
                        object-fit: cover;
                    `;
                    
                    // 添加图片加载失败处理
                    img.onerror = () => {
                        imgContainer.innerHTML = '';
                        const errorMessage = document.createElement('div');
                        errorMessage.style.cssText = 'color: #999; font-size: 14px; padding: 10px; background: #f8f9fa; border-radius: 8px;';
                        errorMessage.textContent = '文件已被清理，每7天清理一次uploads目录';
                        imgContainer.appendChild(errorMessage);
                    };
                    
                    img.onclick = () => {
                        const modal = document.getElementById('image-modal');
                        const modalImg = document.getElementById('modal-image');
                        modalImg.src = fileUrl;
                        modal.style.display = 'flex';
                    };
                    
                    imgContainer.appendChild(img);
                    contentDiv.appendChild(imgContainer);
                } 
                // 音频类型 - 确保所有音频文件都显示为音频播放器
                else if (['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac', 'wma', 'aiff', 'opus', 'webm'].includes(fileExtension)) {
                    console.log('临时消息: 检测到音频文件，创建自定义音频播放器');
                    const audioContainer = document.createElement('div');
                    audioContainer.style.cssText = 'margin: 5px 0;';
                    
                    // 创建自定义音频播放器
                    const audioPlayer = new CustomAudioPlayer(fileUrl);
                    const playerElement = audioPlayer.createPlayer();
                    
                    audioContainer.appendChild(playerElement);
                    contentDiv.appendChild(audioContainer);
                } 
                // 其他文件类型
                else {
                    console.log('临时消息: 检测到其他文件，创建下载链接');
                    const fileLink = document.createElement('a');
                    fileLink.className = 'message-file';
                    fileLink.href = fileUrl;
                    fileLink.download = fileName;
                    fileLink.style.cssText = `
                        display: inline-block;
                        padding: 8px 12px;
                        background: #f0f0f0;
                        color: #333;
                        text-decoration: none;
                        border-radius: 4px;
                        margin: 5px 0;
                        transition: background-color 0.2s;
                    `;
                    fileLink.onmouseover = () => {
                        fileLink.style.background = '#e0e0e0';
                    };
                    fileLink.onmouseout = () => {
                        fileLink.style.background = '#f0f0f0';
                    };
                    
                    const fileIcon = document.createElement('span');
                    fileIcon.textContent = '📎 ';
                    
                    const fileNameSpan = document.createElement('span');
                    fileNameSpan.textContent = fileName;
                    
                    fileLink.appendChild(fileIcon);
                    fileLink.appendChild(fileNameSpan);
                    contentDiv.appendChild(fileLink);
                }
            } else {
                const textDiv = document.createElement('div');
                textDiv.className = 'message-text';
                // 转换URL为链接
                const textWithLinks = convertUrlsToLinks(text);
                textDiv.innerHTML = textWithLinks;
                contentDiv.appendChild(textDiv);
            }
            
            const timeDiv = document.createElement('div');
            timeDiv.className = 'message-time';
            timeDiv.textContent = new Date().toLocaleTimeString('zh-CN', { hour: '2-digit', minute: '2-digit' });
            contentDiv.appendChild(timeDiv);
            
            messageDiv.appendChild(avatarDiv);
            messageDiv.appendChild(contentDiv);
            
            return messageDiv;
        }
        
        // 创建图片放大模态框
        function createImageModal() {
            const modal = document.createElement('div');
            modal.id = 'image-modal';
            modal.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.8);
                display: none;
                justify-content: center;
                align-items: center;
                z-index: 1000;
            `;
            
            const modalContent = document.createElement('div');
            modalContent.style.cssText = `
                max-width: 90%;
                max-height: 90%;
                position: relative;
            `;
            
            const img = document.createElement('img');
            img.id = 'modal-image';
            img.style.cssText = `
                max-width: 100%;
                max-height: 100vh;
                object-fit: contain;
            `;
            
            const closeBtn = document.createElement('span');
            closeBtn.textContent = '×';
            closeBtn.style.cssText = `
                position: absolute;
                top: -30px;
                right: -30px;
                color: white;
                font-size: 40px;
                cursor: pointer;
                font-weight: bold;
            `;
            closeBtn.onclick = () => {
                modal.style.display = 'none';
            };
            
            // 点击模态框背景关闭
            modal.onclick = (e) => {
                if (e.target === modal) {
                    modal.style.display = 'none';
                }
            };
            
            modalContent.appendChild(img);
            modalContent.appendChild(closeBtn);
            modal.appendChild(modalContent);
            document.body.appendChild(modal);
        }
        
        // 初始化图片模态框
        createImageModal();
        
        // URL检测和转换函数
        function convertUrlsToLinks(text) {
            // URL正则表达式
            const urlRegex = /(https?:\/\/[^\s]+)/g;
            
            // 替换URL为可点击的链接
            return text.replace(urlRegex, (url) => {
                // 创建链接HTML
                return `<a href="${url}" class="message-link" onclick="return confirmLinkClick(event, '${url}')">${url}</a>`;
            });
        }
        
        // 链接点击确认函数
        function confirmLinkClick(event, url) {
            // 阻止默认跳转
            event.preventDefault();
            
            // 检查是否为本站链接
            const siteUrl = '<?php echo APP_URL; ?>';
            const isSameSite = url.startsWith(siteUrl);
            
            // 如果是本站链接，直接跳转
            if (isSameSite) {
                window.open(url, '_blank');
                return true;
            }
            
            // 非本站链接，显示确认提示
            const confirmed = confirm('非本站链接，请仔细辨别！\n\n' + url + '\n\n是否继续访问？');
            
            if (confirmed) {
                window.open(url, '_blank');
                return true;
            }
            
            return false;
        }
        
        // 创建消息元素
        function createMessage(message, isSent) {
            const messageDiv = document.createElement('div');
            messageDiv.className = `message ${isSent ? 'sent' : 'received'}`;
            
            const avatarDiv = document.createElement('div');
            avatarDiv.className = 'message-avatar';
            
            // 获取当前用户头像
            const currentUserAvatar = '<?php echo !empty($current_user['avatar']) ? $current_user['avatar'] : ''; ?>';
            
            if (isSent) {
                // 发送的消息，使用当前用户头像
                if (currentUserAvatar) {
                    const img = document.createElement('img');
                    img.src = currentUserAvatar;
                    img.alt = '<?php echo $username; ?>';
                    img.style.cssText = 'width: 100%; height: 100%; border-radius: 50%; object-fit: cover;';
                    avatarDiv.appendChild(img);
                } else {
                    avatarDiv.textContent = '<?php echo substr($username, 0, 2); ?>';
                }
            } else {
                // 接收的消息，使用发送者头像（适用于群聊和好友聊天）
                if (message.avatar) {
                    // 群聊消息，使用发送者的头像
                    const img = document.createElement('img');
                    img.src = message.avatar;
                    img.alt = message.username || '未知用户';
                    img.style.cssText = 'width: 100%; height: 100%; border-radius: 50%; object-fit: cover;';
                    avatarDiv.appendChild(img);
                } else {
                    // 好友聊天，使用好友头像或用户名首字母
                    const friendAvatar = '<?php echo $selected_friend && !empty($selected_friend['avatar']) ? $selected_friend['avatar'] : ''; ?>';
                    const friendName = '<?php echo $selected_friend ? $selected_friend['username'] : ''; ?>';
                    
                    if (friendAvatar) {
                        const img = document.createElement('img');
                        img.src = friendAvatar;
                        img.alt = friendName;
                        img.style.cssText = 'width: 100%; height: 100%; border-radius: 50%; object-fit: cover;';
                        avatarDiv.appendChild(img);
                    } else {
                        avatarDiv.textContent = friendName ? friendName.substring(0, 2) : '?';
                    }
                }
            }
            
            const contentDiv = document.createElement('div');
            contentDiv.className = 'message-content';
            
            // 处理文本消息
            if ((message.type === 'text' || !message.type) && message.content) {
                const textDiv = document.createElement('div');
                textDiv.className = 'message-text';
                // 转换URL为链接
                const textWithLinks = convertUrlsToLinks(message.content);
                textDiv.innerHTML = textWithLinks;
                contentDiv.appendChild(textDiv);
            }
            
            // 处理文件消息
            if (message.file_path) {
                // 获取文件扩展名和MIME类型
                const fileName = message.file_name;
                const fileUrl = message.file_path;
                
                // 确保fileName存在且有扩展名
                let fileExtension = '';
                if (fileName && fileName.includes('.')) {
                    fileExtension = fileName.split('.').pop().toLowerCase();
                }
                
                // 禁止显示的文件扩展名
                const forbiddenExtensions = ['php', 'html', 'js', 'htm', 'css', 'xml'];
                
                // 如果是禁止的文件扩展名，不显示该文件
                if (forbiddenExtensions.includes(fileExtension)) {
                    const forbiddenMessage = document.createElement('div');
                    forbiddenMessage.style.cssText = 'color: #999; font-size: 14px; padding: 10px; background: #f8f9fa; border-radius: 8px;';
                    forbiddenMessage.textContent = '该文件类型不支持显示';
                    contentDiv.appendChild(forbiddenMessage);
                } else {
                    console.log('文件信息:', {
                        fileName: fileName,
                        fileExtension: fileExtension,
                        fileUrl: fileUrl,
                        messageType: message.type
                    });
                    
                    // 图片类型 - 确保所有图片文件都显示为图片
                    const imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'tiff', 'ico'];
                    if (imageExtensions.includes(fileExtension)) {
                    console.log('检测到图片文件，创建img标签');
                    const imgContainer = document.createElement('div');
                    imgContainer.style.cssText = 'display: inline-block; margin: 5px;';
                    
                    const img = document.createElement('img');
                    img.src = fileUrl;
                    img.alt = fileName;
                    img.style.cssText = `
                        max-width: 200px;
                        max-height: 200px;
                        cursor: pointer;
                        border-radius: 8px;
                        transition: transform 0.2s;
                        object-fit: cover;
                    `;
                    
                    img.onclick = () => {
                        const modal = document.getElementById('image-modal');
                        const modalImg = document.getElementById('modal-image');
                        modalImg.src = fileUrl;
                        modal.style.display = 'flex';
                    };
                    
                    imgContainer.appendChild(img);
                    contentDiv.appendChild(imgContainer);
                } 
                // 音频类型 - 确保所有音频文件都显示为自定义音频播放器
                else if (['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac', 'wma', 'aiff', 'opus', 'webm'].includes(fileExtension)) {
                    console.log('检测到音频文件，创建自定义音频播放器');
                    const audioContainer = document.createElement('div');
                    audioContainer.style.cssText = 'margin: 5px 0;';
                    
                    // 创建自定义音频播放器
                    const audioPlayer = new CustomAudioPlayer(fileUrl);
                    const playerElement = audioPlayer.createPlayer();
                    
                    // 添加音频加载失败处理
                    const audioElement = playerElement.querySelector('audio');
                    audioElement.onerror = () => {
                        audioContainer.innerHTML = '';
                        const errorMessage = document.createElement('div');
                        errorMessage.style.cssText = 'color: #999; font-size: 14px; padding: 10px; background: #f8f9fa; border-radius: 8px;';
                        errorMessage.textContent = '文件已被清理，每7天清理一次uploads目录';
                        audioContainer.appendChild(errorMessage);
                    };
                    
                    audioContainer.appendChild(playerElement);
                    contentDiv.appendChild(audioContainer);
                } 
                // 其他文件类型
                else {
                    console.log('检测到其他文件，创建下载链接');
                    const fileLinkContainer = document.createElement('div');
                    
                    const fileLink = document.createElement('a');
                    fileLink.className = 'message-file';
                    fileLink.href = fileUrl;
                    fileLink.download = fileName;
                    fileLink.style.cssText = `
                        display: inline-block;
                        padding: 8px 12px;
                        background: #f0f0f0;
                        color: #333;
                        text-decoration: none;
                        border-radius: 4px;
                        margin: 5px 0;
                        transition: background-color 0.2s;
                    `;
                    fileLink.onmouseover = () => {
                        fileLink.style.background = '#e0e0e0';
                    };
                    fileLink.onmouseout = () => {
                        fileLink.style.background = '#f0f0f0';
                    };
                    
                    // 添加点击事件处理，检查文件是否存在
                    fileLink.onclick = async (e) => {
                        e.preventDefault();
                        
                        try {
                            // 发送HEAD请求检查文件是否存在
                            const response = await fetch(fileUrl, { method: 'HEAD' });
                            if (response.ok) {
                                // 文件存在，执行下载
                                window.location.href = fileUrl;
                            } else {
                                // 文件不存在，显示错误信息
                                fileLinkContainer.innerHTML = '';
                                const errorMessage = document.createElement('div');
                                errorMessage.style.cssText = 'color: #999; font-size: 14px; padding: 10px; background: #f8f9fa; border-radius: 8px;';
                                errorMessage.textContent = '文件已被清理，每7天清理一次uploads目录';
                                fileLinkContainer.appendChild(errorMessage);
                            }
                        } catch (error) {
                            // 请求失败，显示错误信息
                            fileLinkContainer.innerHTML = '';
                            const errorMessage = document.createElement('div');
                            errorMessage.style.cssText = 'color: #999; font-size: 14px; padding: 10px; background: #f8f9fa; border-radius: 8px;';
                            errorMessage.textContent = '文件已被清理，每15天清理一次uploads目录';
                            fileLinkContainer.appendChild(errorMessage);
                        }
                    };
                    
                    const fileIcon = document.createElement('span');
                    fileIcon.textContent = '📎 ';
                    
                    const fileNameSpan = document.createElement('span');
                    fileNameSpan.textContent = fileName;
                    
                    fileLink.appendChild(fileIcon);
                    fileLink.appendChild(fileNameSpan);
                    fileLinkContainer.appendChild(fileLink);
                    contentDiv.appendChild(fileLinkContainer);
                }
            }
            }
            
            const timeDiv = document.createElement('div');
            timeDiv.className = 'message-time';
            timeDiv.textContent = new Date(message.created_at).toLocaleTimeString('zh-CN', { hour: '2-digit', minute: '2-digit' });
            contentDiv.appendChild(timeDiv);
            
            messageDiv.appendChild(avatarDiv);
            messageDiv.appendChild(contentDiv);
            
            return messageDiv;
        }
        
        // 确保DOM加载完成后执行点击事件绑定
        document.addEventListener('DOMContentLoaded', function() {
            // 使用事件委托处理好友和群聊项点击
            document.addEventListener('click', function(e) {
                // 查找点击的元素是否是friend-item或其子元素
                const friendItem = e.target.closest('.friend-item');
                if (friendItem) {
                    // 检查点击的是否是菜单按钮或菜单内的元素，如果是则不执行导航
                    if (e.target.closest('.btn-icon') || e.target.closest('.friend-menu') || e.target.closest('.group-menu')) {
                        return;
                    }
                    
                    const friendId = friendItem.dataset.friendId;
                    const groupId = friendItem.dataset.groupId;
                    
                    if (friendId) {
                        window.location.href = `chat.php?chat_type=friend&id=${friendId}`;
                    } else if (groupId) {
                        window.location.href = `chat.php?chat_type=group&id=${groupId}`;
                    }
                }
            });
        });
        
        // 搜索好友
        document.getElementById('search-input').addEventListener('input', async (e) => {
            const searchTerm = e.target.value.trim();
            const searchResults = document.getElementById('search-results');
            
            if (searchTerm.length < 1) {
                searchResults.style.display = 'none';
                return;
            }
            
            try {
                const response = await fetch(`search_users.php?q=${encodeURIComponent(searchTerm)}`);
                const data = await response.json();
                
                if (data.success && data.users.length > 0) {
                    let resultsHTML = '<h4 style="margin-bottom: 10px; font-size: 14px; color: #333;">搜索结果</h4>';
                    
                    data.users.forEach(user => {
                        let statusText = user.status === 'online' ? '在线' : '离线';
                        let friendshipButton = '';
                        
                        switch (user.friendship_status) {
                            case 'accepted':
                                friendshipButton = '<button class="btn" style="padding: 4px 10px; font-size: 12px; background: #4caf50;">已成为好友</button>';
                                break;
                            case 'pending':
                                friendshipButton = '<button class="btn" style="padding: 4px 10px; font-size: 12px; background: #ff9800;">请求已发送</button>';
                                break;
                            default:
                                friendshipButton = `<button class="btn" style="padding: 4px 10px; font-size: 12px;" onclick="sendFriendRequest(${user.id})">添加好友</button>`;
                        }
                        
                        resultsHTML += `
                            <div style="display: flex; align-items: center; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #f0f0f0;">
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <div class="friend-avatar" style="width: 40px; height: 40px; font-size: 16px;">
                                        ${user.username.substring(0, 2)}
                                        <div class="status-indicator ${user.status}"></div>
                                    </div>
                                    <div>
                                        <h3 style="font-size: 14px; margin-bottom: 2px;">${user.username}</h3>
                                        <p style="font-size: 12px; color: #666;">${statusText}</p>
                                    </div>
                                </div>
                                ${friendshipButton}
                            </div>
                        `;
                    });
                    
                    searchResults.innerHTML = resultsHTML;
                    searchResults.style.display = 'block';
                } else {
                    searchResults.innerHTML = '<p style="color: #999; font-size: 14px;">未找到匹配的用户</p>';
                    searchResults.style.display = 'block';
                }
            } catch (error) {
                console.error('搜索用户失败:', error);
                searchResults.innerHTML = '<p style="color: #d32f2f; font-size: 14px;">搜索失败，请稍后重试</p>';
                searchResults.style.display = 'block';
            }
        });
        
        // 点击页面其他地方关闭搜索结果
        document.addEventListener('click', (e) => {
            const searchInput = document.getElementById('search-input');
            const searchResults = document.getElementById('search-results');
            
            if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
                searchResults.style.display = 'none';
            }
        });
        
        // 发送好友请求
        function sendFriendRequest(friendId) {
            if (confirm('确定要发送好友请求吗？')) {
                fetch('send_friend_request.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: `friend_id=${friendId}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('好友请求已发送');
                        // 重新加载搜索结果
                        document.getElementById('search-input').dispatchEvent(new Event('input'));
                    } else {
                        alert(data.message);
                    }
                })
                .catch(error => {
                    console.error('发送好友请求失败:', error);
                    alert('发送好友请求失败，请稍后重试');
                });
            }
        }
        
        // 退出登录
        function logout() {
            if (confirm('确定要退出登录吗？')) {
                window.location.href = 'logout.php';
            }
        }
        
        // 接受好友请求
        function acceptRequest(requestId) {
            if (confirm('确定要接受这个好友请求吗？')) {
                window.location.href = `accept_request.php?request_id=${requestId}`;
            }
        }
        
        // 拒绝好友请求
        function rejectRequest(requestId) {
            if (confirm('确定要拒绝这个好友请求吗？')) {
                window.location.href = `reject_request.php?request_id=${requestId}`;
            }
        }
        
        // 显示添加好友表单
        function showAddFriendForm() {
            document.getElementById('add-friend-form').style.display = 'block';
        }
        
        // 隐藏添加好友表单
        function hideAddFriendForm() {
            document.getElementById('add-friend-form').style.display = 'none';
            document.getElementById('add-friend-username').value = '';
        }
        
        // 添加好友
        function addFriend() {
            const username = document.getElementById('add-friend-username').value.trim();
            
            if (!username) {
                alert('请输入好友用户名');
                return;
            }
            
            // 通过用户名获取用户ID
            fetch(`get_user_id.php?username=${encodeURIComponent(username)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // 发送好友请求
                        fetch('send_friend_request.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded'
                            },
                            body: `friend_id=${data.user_id}`
                        })
                        .then(response => response.json())
                        .then(result => {
                            if (result.success) {
                                alert('好友请求已发送');
                                hideAddFriendForm();
                            } else {
                                alert(result.message);
                            }
                        })
                        .catch(error => {
                            console.error('发送好友请求失败:', error);
                            alert('发送好友请求失败，请稍后重试');
                        });
                    } else {
                        alert(data.message);
                    }
                })
                .catch(error => {
                    console.error('获取用户信息失败:', error);
                    alert('获取用户信息失败，请稍后重试');
                });
        }
        
        // 自动滚动到底部
        const messagesContainer = document.getElementById('messages-container');
        if (messagesContainer) {
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }
        
        // 实时更新消息
        let lastMessageId = <?php echo end($chat_history)['id'] ?? 0; ?>;
        
        function fetchNewMessages() {
            // 动态获取当前聊天类型和选中的ID
            const chatType = document.querySelector('input[name="chat_type"]')?.value;
            const selectedId = document.querySelector('input[name="id"]')?.value;
            
            if (chatType && selectedId) {
                let url = '';
                
                if (chatType === 'friend') {
                    url = `get_new_messages.php?friend_id=${selectedId}&last_message_id=${lastMessageId}`;
                } else {
                    url = `get_new_group_messages.php?group_id=${selectedId}&last_message_id=${lastMessageId}`;
                }
                
                fetch(url)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success && data.messages.length > 0) {
                            const messagesContainer = document.getElementById('messages-container');
                            let hasNewMessages = false;
                            
                            data.messages.forEach(msg => {
                                // 添加所有新消息，包括自己发送的和其他成员发送的
                                const isSent = msg.sender_id == <?php echo $user_id; ?>;
                                const newMessage = createMessage(msg, isSent);
                                messagesContainer.appendChild(newMessage);
                                hasNewMessages = true;
                                // 更新lastMessageId为最新消息ID
                                if (msg.id > lastMessageId) {
                                    lastMessageId = msg.id;
                                }
                            });
                            
                            if (hasNewMessages) {
                                // 滚动到底部
                                messagesContainer.scrollTop = messagesContainer.scrollHeight;
                                
                                // 检查是否免打扰
                                if (!data.is_muted) {
                                    // 播放新消息提示音
                                    playNotificationSound();
                                    
                                    // 显示任务栏通知
                                    showTaskbarNotification('新消息', '您有一条新消息');
                                }
                            }
                        }
                    })
                    .catch(error => console.error('获取新消息失败:', error));
            }
        }
        
        // 每3秒获取一次新消息
        setInterval(fetchNewMessages, 3000);
        
        // 每5秒获取一次新的群聊邀请
        setInterval(fetchGroupInvitations, 5000);
        
        // 页面加载时获取一次群聊邀请
        document.addEventListener('DOMContentLoaded', () => {
            fetchGroupInvitations();
        });
        
        // 已处理的邀请ID列表，用于避免重复显示
        let processedInvitations = new Set();
        
        // 获取新的群聊邀请
        function fetchGroupInvitations() {
            fetch('get_group_invitations.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.invitations.length > 0) {
                        const notificationsContainer = document.getElementById('group-invitation-notifications');
                        
                        data.invitations.forEach(invitation => {
                            // 只显示未处理的邀请
                            if (!processedInvitations.has(invitation.id)) {
                                const notification = document.createElement('div');
                                notification.id = `invitation-${invitation.id}`;
                                notification.style.cssText = `
                                    background: white;
                                    border-radius: 8px;
                                    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
                                    padding: 15px;
                                    margin-bottom: 10px;
                                    max-width: 300px;
                                    animation: slideInRight 0.3s ease-out;
                                `;
                                
                                notification.innerHTML = `
                                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px;">
                                        <div>
                                            <h4 style="margin: 0 0 5px 0; font-size: 14px; font-weight: 600;">${invitation.inviter_name}邀请您加入群聊</h4>
                                            <p style="margin: 0; font-size: 12px; color: #666;">${invitation.group_name}</p>
                                        </div>
                                        <button onclick="this.parentElement.parentElement.remove(); processedInvitations.add(${invitation.id});" style="
                                            background: none;
                                            border: none;
                                            font-size: 16px;
                                            cursor: pointer;
                                            color: #666;
                                            padding: 0;
                                        ">×</button>
                                    </div>
                                    <div style="display: flex; gap: 8px;">
                                        <button onclick="acceptGroupInvitation(${invitation.id}, this)" style="
                                            flex: 1;
                                            padding: 6px;
                                            background: #4caf50;
                                            color: white;
                                            border: none;
                                            border-radius: 4px;
                                            font-size: 12px;
                                            font-weight: 600;
                                            cursor: pointer;
                                        ">接受</button>
                                        <button onclick="rejectGroupInvitation(${invitation.id}, this)" style="
                                            flex: 1;
                                            padding: 6px;
                                            background: #ff4757;
                                            color: white;
                                            border: none;
                                            border-radius: 4px;
                                            font-size: 12px;
                                            font-weight: 600;
                                            cursor: pointer;
                                        ">拒绝</button>
                                    </div>
                                `;
                                
                                notificationsContainer.appendChild(notification);
                            }
                        });
                    }
                })
                .catch(error => {
                    console.error('获取群聊邀请失败:', error);
                });
        }
        
        // 接受群聊邀请
        function acceptGroupInvitation(invitationId, button) {
            fetch('accept_group_invitation.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `invitation_id=${invitationId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // 移除通知
                    const notification = document.getElementById(`invitation-${invitationId}`);
                    if (notification) {
                        notification.remove();
                    }
                    // 添加到已处理列表，避免重复显示
                    processedInvitations.add(invitationId);
                    // 不刷新页面，直接更新群聊列表
                    updateGroupList();
                } else {
                    alert(data.message);
                }
            })
            .catch(error => {
                console.error('接受群聊邀请失败:', error);
                alert('接受群聊邀请失败，请稍后重试');
            });
        }
        
        // 拒绝群聊邀请
        function rejectGroupInvitation(invitationId, button) {
            fetch('reject_group_invitation.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `invitation_id=${invitationId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // 移除通知
                    const notification = document.getElementById(`invitation-${invitationId}`);
                    if (notification) {
                        notification.remove();
                    }
                    // 添加到已处理列表，避免重复显示
                    processedInvitations.add(invitationId);
                } else {
                    alert(data.message);
                }
            })
            .catch(error => {
                console.error('拒绝群聊邀请失败:', error);
                alert('拒绝群聊邀请失败，请稍后重试');
            });
        }
        
        // 更新群聊列表
        function updateGroupList() {
            // 获取当前用户ID（从会话中获取）
            const currentUserId = <?php echo $user_id; ?>;
            
            // 获取当前聊天类型和选中的ID
            const currentChatType = document.querySelector('input[name="chat_type"]')?.value;
            const currentSelectedId = document.querySelector('input[name="id"]')?.value;
            
            // 重新获取群聊列表
            fetch(`get_user_groups.php`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // 更新群聊列表UI
                        const groupsList = document.getElementById('groups-list');
                        if (groupsList) {
                            // 移除旧的群聊列表
                            groupsList.innerHTML = '';
                            
                            // 添加新的群聊列表
                            data.groups.forEach(group => {
                                const groupItem = document.createElement('div');
                                groupItem.className = `friend-item ${currentChatType === 'group' && currentSelectedId == group.id ? 'active' : ''}`;
                                groupItem.dataset.groupId = group.id;
                                
                                // 添加点击事件
                                groupItem.addEventListener('click', () => {
                                    window.location.href = `chat.php?chat_type=group&id=${group.id}`;
                                });
                                
                                // 创建群聊菜单HTML
                                let groupMenuHTML = `
                                    <button class="group-menu-item" onclick="event.stopPropagation(); showGroupMembers(${group.id});">查看成员</button>
                                    <button class="group-menu-item" onclick="event.stopPropagation(); inviteFriendsToGroup(${group.id});">邀请好友</button>`;
                                
                                // 判断是否是群主
                                if (group.owner_id == currentUserId) {
                                    groupMenuHTML += `
                                        <button class="group-menu-item" onclick="event.stopPropagation(); transferGroupOwnership(${group.id});">转让群主</button>
                                        <button class="group-menu-item" onclick="event.stopPropagation(); deleteGroup(${group.id});">解散群聊</button>`;
                                } else {
                                    groupMenuHTML += `
                                        <button class="group-menu-item" onclick="event.stopPropagation(); leaveGroup(${group.id});">退出群聊</button>`;
                                }
                                
                                groupItem.innerHTML = `
                                    <div class="friend-avatar" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                                        👥
                                    </div>
                                    <div class="friend-info">
                                        <h3>${group.name}</h3>
                                        <p>${group.member_count} 成员</p>
                                    </div>
                                    <div style="position: relative;">
                                        <button class="btn-icon" style="width: 30px; height: 30px; font-size: 12px;" onclick="event.stopPropagation(); toggleGroupMenu(event, ${group.id});">
                                            ⋮
                                        </button>
                                        <!-- 群聊菜单 -->
                                        <div class="group-menu" id="group-menu-${group.id}" style="display: none; position: absolute; top: 0; right: 0; background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1); z-index: 1000; min-width: 150px;">
                                            ${groupMenuHTML}
                                        </div>
                                    </div>
                                `;
                                groupsList.appendChild(groupItem);
                            });
                        }
                    }
                })
                .catch(error => {
                    console.error('更新群聊列表失败:', error);
                });
        }
        
        // 添加动画样式
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideInRight {
                from {
                    opacity: 0;
                    transform: translateX(100%);
                }
                to {
                    opacity: 1;
                    transform: translateX(0);
                }
            }
        `;
        document.head.appendChild(style);
        
        // 更新用户状态
        function updateUserStatus() {
            fetch('update_status.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: 'status=online'
            });
        }
        
        // 每5分钟更新一次状态
        setInterval(updateUserStatus, 300000);
        
        // 页面可见性变化时更新状态
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                // 页面隐藏时，更新状态为离开
                updateStatus('away');
            } else {
                // 页面显示时，更新状态为在线
                updateStatus('online');
            }
        });
        
        // 页面关闭或刷新时更新状态为离线
        window.addEventListener('beforeunload', () => {
            // 使用navigator.sendBeacon确保请求可靠发送
            const formData = new FormData();
            formData.append('status', 'offline');
            navigator.sendBeacon('update_status.php', formData);
        });
        
        // 页面加载完成后更新状态为在线
        document.addEventListener('DOMContentLoaded', () => {
            // 页面完全加载后才更新状态为在线
            updateStatus('online');
        });
        
        // 页面卸载时更新状态为离线
        window.addEventListener('unload', () => {
            // 双重保险，确保状态更新
            const formData = new FormData();
            formData.append('status', 'offline');
            navigator.sendBeacon('update_status.php', formData);
        });
        
        // 统一的状态更新函数
        function updateStatus(status) {
            fetch('update_status.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `status=${status}`
            }).catch(error => {
                console.error('状态更新失败:', error);
            });
        }
        
        // 封禁检查和处理
        function checkBanStatus() {
            fetch('check_ban_status.php')
                .then(response => response.json())
                .then(data => {
                    if (data.banned) {
                        showBanNotification(data.reason, data.expires_at);
                    }
                })
                .catch(error => {
                    console.error('检查封禁状态失败:', error);
                });
        }
        
        // 显示封禁通知
        function showBanNotification(reason, expires_at) {
            const modal = document.getElementById('ban-notification-modal');
            const reasonEl = document.getElementById('ban-reason');
            const countdownEl = document.getElementById('ban-countdown');
            
            reasonEl.textContent = `原因：${reason}，预计解封时间：${expires_at}`;
            modal.style.display = 'flex';
            
            // 倒计时退出
            let countdown = 10;
            countdownEl.textContent = countdown;
            
            const countdownInterval = setInterval(() => {
                countdown--;
                countdownEl.textContent = countdown;
                
                if (countdown <= 0) {
                    clearInterval(countdownInterval);
                    window.location.href = 'logout.php';
                }
            }, 1000);
        }
        
        // 页面加载完成后立即检查一次封禁状态和协议同意状态
        document.addEventListener('DOMContentLoaded', () => {
            // 初始封禁检查
            <?php if ($ban_info): ?>
                showBanNotification('<?php echo $ban_info['reason']; ?>', '<?php echo $ban_info['expires_at']; ?>');
            <?php endif; ?>
            
            // 检查用户是否同意协议
            <?php if (!$agreed_to_terms): ?>
                showTermsAgreementModal();
            <?php endif; ?>
            
            // 每30秒检查一次封禁状态
            setInterval(checkBanStatus, 30000);
        });
        
        // 显示协议同意弹窗
        function showTermsAgreementModal() {
            const modal = document.getElementById('terms-agreement-modal');
            modal.style.display = 'flex';
        }
        
        // 同意协议
        document.getElementById('agree-terms-btn')?.addEventListener('click', async () => {
            try {
                // 发送请求更新协议同意状态
                const response = await fetch('update_terms_agreement.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'agreed=true'
                });
                
                const result = await response.json();
                if (result.success) {
                    // 隐藏弹窗
                    const modal = document.getElementById('terms-agreement-modal');
                    modal.style.display = 'none';
                } else {
                    alert('更新协议同意状态失败，请刷新页面重试');
                }
            } catch (error) {
                console.error('同意协议失败:', error);
                alert('同意协议失败，请刷新页面重试');
            }
        });
        
        // 不同意协议并注销账号
        document.getElementById('disagree-terms-btn')?.addEventListener('click', async () => {
            if (confirm('确定要注销账号吗？此操作不可恢复。')) {
                try {
                    // 发送请求注销账号
                    const response = await fetch('delete_account.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        }
                    });
                    
                    const result = await response.json();
                    if (result.success) {
                        // 注销成功，跳转到登录页面
                        window.location.href = 'login.php?message=' + encodeURIComponent('账号已注销');
                    } else {
                        alert('注销账号失败，请刷新页面重试');
                    }
                } catch (error) {
                    console.error('注销账号失败:', error);
                    alert('注销账号失败，请刷新页面重试');
                }
            }
        });
        
        // 设置面板相关函数
        function toggleSettings() {
            const settingsPanel = document.getElementById('settings-panel');
            settingsPanel.style.display = settingsPanel.style.display === 'block' ? 'none' : 'block';
        }
        
        // 保存设置
        function saveSettings() {
            const settings = {
                notificationSound: document.getElementById('notification-sound').checked,
                taskbarNotification: document.getElementById('taskbar-notification').checked,
                linkPopup: document.getElementById('link-popup').checked,
                passCookies: document.getElementById('pass-cookies').checked
            };
            
            localStorage.setItem('chatSettings', JSON.stringify(settings));
            alert('设置已保存');
        }
        
        // 加载设置
        function loadSettings() {
            const settings = JSON.parse(localStorage.getItem('chatSettings')) || {
                notificationSound: true,
                taskbarNotification: true,
                linkPopup: false,
                passCookies: true
            };
            
            document.getElementById('notification-sound').checked = settings.notificationSound;
            document.getElementById('taskbar-notification').checked = settings.taskbarNotification;
            document.getElementById('link-popup').checked = settings.linkPopup;
            document.getElementById('pass-cookies').checked = settings.passCookies;
        }
        
        // 播放新消息提示音
        function playNotificationSound() {
            const settings = JSON.parse(localStorage.getItem('chatSettings')) || {
                notificationSound: true
            };
            
            if (settings.notificationSound) {
                // 创建音频元素并播放
                const audio = new Audio('data:audio/wav;base64,UklGRigAAABXQVZFZm10IBAAAAABAAEARKwAAIhYAQACABAAZGF0YQAAAAA=');
                audio.play().catch(error => {
                    console.error('播放提示音失败:', error);
                });
            }
        }
        
        // 显示任务栏通知
        function showTaskbarNotification(title, body) {
            const settings = JSON.parse(localStorage.getItem('chatSettings')) || {
                taskbarNotification: true
            };
            
            if (settings.taskbarNotification && 'Notification' in window) {
                // 请求通知权限
                if (Notification.permission === 'granted') {
                    new Notification(title, {
                        body: body,
                        icon: 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAiIGhlaWdodD0iMjAiIHZpZXdCb3g9IjAgMCAyMCAyMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KICA8cmVjdCB3aWR0aD0iMjAiIGhlaWdodD0iMjAiIGZpbGw9IiM2NjdlZWEiLz4KICA8Y2lyY2xlIGN4PSIxMCIgY3k9IjEwIiByPSI4IiBmaWxsPSIjNzY0YmEyIi8+Cjwvc3ZnPg==',
                        badge: 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAiIGhlaWdodD0iMjAiIHZpZXdCb3g9IjAgMCAyMCAyMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KICA8cmVjdCB3aWR0aD0iMjAiIGhlaWdodD0iMjAiIGZpbGw9IiM2NjdlZWEiLz4KICA8Y2lyY2xlIGN4PSIxMCIgY3k9IjEwIiByPSI4IiBmaWxsPSIjNzY0YmEyIi8+Cjwvc3ZnPg=='
                    });
                } else if (Notification.permission !== 'denied') {
                    Notification.requestPermission().then(permission => {
                        if (permission === 'granted') {
                            new Notification(title, {
                                body: body,
                                icon: 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAiIGhlaWdodD0iMjAiIHZpZXdCb3g9IjAgMCAyMCAyMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KICA8cmVjdCB3aWR0aD0iMjAiIGhlaWdodD0iMjAiIGZpbGw9IiM2NjdlZWEiLz4KICA8Y2lyY2xlIGN4PSIxMCIgY3k9IjEwIiByPSI4IiBmaWxsPSIjNzY0YmEyIi8+Cjwvc3ZnPg==',
                                badge: 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAiIGhlaWdodD0iMjAiIHZpZXdCb3g9IjAgMCAyMCAyMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KICA8cmVjdCB3aWR0aD0iMjAiIGhlaWdodD0iMjAiIGZpbGw9IiM2NjdlZWEiLz4KICA8Y2lyY2xlIGN4PSIxMCIgY3k9IjEwIiByPSI4IiBmaWxsPSIjNzY0YmEyIi8+Cjwvc3ZnPg=='
                            });
                        }
                    });
                }
            }
        }
        
        // 创建链接弹窗
        function createLinkPopup(url) {
            // 检查设置
            const settings = JSON.parse(localStorage.getItem('chatSettings')) || {
                linkPopup: false,
                passCookies: true
            };
            
            console.log('链接弹窗设置:', settings);
            console.log('当前URL:', url);
            
            if (!settings.linkPopup) {
                console.log('链接弹窗已关闭，返回false');
                return false;
            }
            
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
                position: relative;
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
            `;
            
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
            
            // 创建iframe
            const iframe = document.createElement('iframe');
            iframe.src = url;
            iframe.style.cssText = `
                flex: 1;
                border: none;
                width: 100%;
                height: calc(100% - 48px); /* 减去头部高度 */
                min-height: 0;
            `;
            
            // 设置iframe的sandbox属性
            // 不设置sandbox属性时，默认会传递cookie
            // 设置sandbox属性时，需要显式允许才能传递cookie
            if (!settings.passCookies) {
                console.log('不传递cookie，设置严格的sandbox属性');
                // 严格的sandbox设置，不允许传递cookie
                iframe.sandbox = 'allow-scripts allow-same-origin allow-popups';
            } else {
                console.log('传递cookie，不设置sandbox属性');
                // 不设置sandbox属性，允许传递cookie
                // 或者使用宽松的sandbox设置
                // iframe.sandbox = 'allow-scripts allow-same-origin allow-popups allow-top-navigation allow-forms allow-modals';
            }
            
            // 组装弹窗
            popupContent.appendChild(popupHeader);
            popupContent.appendChild(iframe);
            popup.appendChild(popupContent);
            
            // 添加到页面
            document.body.appendChild(popup);
            console.log('链接弹窗已创建并添加到页面');
            
            return true;
        }
        
        // 链接点击确认函数
        function confirmLinkClick(event, url) {
            // 阻止默认跳转
            event.preventDefault();
            
            console.log('链接点击事件触发，URL:', url);
            
            // 检查是否使用弹窗显示
            const popupShown = createLinkPopup(url);
            console.log('弹窗显示结果:', popupShown);
            
            if (popupShown) {
                console.log('弹窗已显示，返回true');
                return true;
            }
            
            console.log('弹窗未显示，继续执行后续逻辑');
            
            // 检查是否为本站链接
            const siteUrl = '<?php echo APP_URL; ?>';
            const isSameSite = url.startsWith(siteUrl);
            
            // 如果是本站链接，直接跳转
            if (isSameSite) {
                console.log('本站链接，直接跳转');
                window.open(url, '_blank');
                return true;
            }
            
            // 非本站链接，显示确认提示
            console.log('非本站链接，显示确认提示');
            const confirmed = confirm('非本站链接，请仔细辨别！\n\n' + url + '\n\n是否继续访问？');
            
            if (confirmed) {
                console.log('用户确认访问，打开新窗口');
                window.open(url, '_blank');
                return true;
            }
            
            console.log('用户取消访问，返回false');
            return false;
        }
        
        // 自定义音频播放器类
        class CustomAudioPlayer {
            constructor(audioUrl) {
                this.audioUrl = audioUrl;
                this.isPlaying = false;
                this.audio = null;
                this.container = null;
            }
            
            // 创建音频播放器
            createPlayer() {
                // 创建容器
                this.container = document.createElement('div');
                this.container.className = 'custom-audio-player';
                
                // 创建播放按钮
                const playBtn = document.createElement('button');
                playBtn.className = 'audio-play-btn';
                playBtn.innerHTML = '▶';
                playBtn.title = '播放';
                
                // 创建进度条容器
                const progressContainer = document.createElement('div');
                progressContainer.className = 'audio-progress-container';
                
                // 创建进度条
                const progressBar = document.createElement('div');
                progressBar.className = 'audio-progress-bar';
                
                // 创建进度
                const progress = document.createElement('div');
                progress.className = 'audio-progress';
                progress.style.width = '0%';
                
                // 创建时间显示
                const timeDisplay = document.createElement('span');
                timeDisplay.className = 'audio-time';
                timeDisplay.textContent = '0:00';
                
                // 创建时长显示
                const durationDisplay = document.createElement('span');
                durationDisplay.className = 'audio-duration';
                durationDisplay.textContent = '0:00';
                
                // 创建隐藏的audio元素
                this.audio = document.createElement('audio');
                this.audio.src = this.audioUrl;
                this.audio.preload = 'metadata';
                
                // 组装播放器
                progressBar.appendChild(progress);
                progressContainer.appendChild(progressBar);
                this.container.appendChild(playBtn);
                this.container.appendChild(progressContainer);
                this.container.appendChild(timeDisplay);
                this.container.appendChild(durationDisplay);
                this.container.appendChild(this.audio);
                
                // 添加事件监听
                this.setupEventListeners(playBtn, progressBar, progress, timeDisplay, durationDisplay);
                
                return this.container;
            }
            
            // 设置事件监听
            setupEventListeners(playBtn, progressBar, progress, timeDisplay, durationDisplay) {
                // 播放/暂停按钮点击事件
                playBtn.addEventListener('click', () => {
                    this.togglePlay(playBtn);
                });
                
                // 音频播放事件
                this.audio.addEventListener('play', () => {
                    this.isPlaying = true;
                    playBtn.innerHTML = '⏸';
                    playBtn.className = 'audio-play-btn paused';
                });
                
                // 音频暂停事件
                this.audio.addEventListener('pause', () => {
                    this.isPlaying = false;
                    playBtn.innerHTML = '▶';
                    playBtn.className = 'audio-play-btn';
                });
                
                // 音频结束事件
                this.audio.addEventListener('ended', () => {
                    this.isPlaying = false;
                    playBtn.innerHTML = '▶';
                    playBtn.className = 'audio-play-btn';
                    progress.style.width = '0%';
                    timeDisplay.textContent = '0:00';
                    this.audio.currentTime = 0;
                });
                
                // 音频时间更新事件
                this.audio.addEventListener('timeupdate', () => {
                    this.updateProgress(progress, timeDisplay);
                });
                
                // 音频加载元数据事件
                this.audio.addEventListener('loadedmetadata', () => {
                    durationDisplay.textContent = this.formatTime(this.audio.duration);
                });
                
                // 进度条点击事件
                progressBar.addEventListener('click', (e) => {
                    this.seek(e, progressBar, progress);
                });
            }
            
            // 切换播放/暂停
            togglePlay(playBtn) {
                if (this.isPlaying) {
                    this.audio.pause();
                } else {
                    this.audio.play();
                }
            }
            
            // 更新进度
            updateProgress(progress, timeDisplay) {
                const percent = (this.audio.currentTime / this.audio.duration) * 100;
                progress.style.width = percent + '%';
                timeDisplay.textContent = this.formatTime(this.audio.currentTime);
            }
            
            // 进度条拖动定位
            seek(e, progressBar, progress) {
                const rect = progressBar.getBoundingClientRect();
                const percent = (e.clientX - rect.left) / rect.width;
                this.audio.currentTime = percent * this.audio.duration;
                progress.style.width = percent * 100 + '%';
            }
            
            // 格式化时间
            formatTime(seconds) {
                if (isNaN(seconds)) return '0:00';
                const mins = Math.floor(seconds / 60);
                const secs = Math.floor(seconds % 60);
                return `${mins}:${secs.toString().padStart(2, '0')}`;
            }
        }
        
        // 显示创建群聊表单
        function showCreateGroupForm() {
            document.getElementById('create-group-form').style.display = 'block';
        }
        
        // 隐藏创建群聊表单
        function hideCreateGroupForm() {
            document.getElementById('create-group-form').style.display = 'none';
        }
        
        // 切换聊天类型
        function switchChatType(type) {
            const friendsList = document.getElementById('friends-list');
            const groupsList = document.getElementById('groups-list');
            const friendBtn = document.querySelector('.chat-type-btn:nth-child(1)');
            const groupBtn = document.querySelector('.chat-type-btn:nth-child(2)');
            
            if (type === 'friend') {
                friendsList.style.display = 'block';
                groupsList.style.display = 'none';
                friendBtn.classList.add('active');
                groupBtn.classList.remove('active');
                friendBtn.style.color = '#667eea';
                friendBtn.style.borderBottomColor = '#667eea';
                groupBtn.style.color = '#666';
                groupBtn.style.borderBottomColor = 'transparent';
            } else {
                friendsList.style.display = 'none';
                groupsList.style.display = 'block';
                friendBtn.classList.remove('active');
                groupBtn.classList.add('active');
                friendBtn.style.color = '#666';
                friendBtn.style.borderBottomColor = 'transparent';
                groupBtn.style.color = '#667eea';
                groupBtn.style.borderBottomColor = '#667eea';
            }
        }
        
        // 创建群聊
        async function createGroup() {
            const groupName = document.getElementById('group-name').value.trim();
            const checkboxes = document.querySelectorAll('#group-members-select input[type="checkbox"]:checked');
            const memberIds = Array.from(checkboxes).map(checkbox => checkbox.value);
            
            if (!groupName) {
                alert('请输入群聊名称');
                return;
            }
            
            if (memberIds.length === 0) {
                alert('请选择至少一个好友');
                return;
            }
            
            try {
                const response = await fetch('create_group.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        name: groupName,
                        member_ids: memberIds
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert('群聊创建成功！');
                    hideCreateGroupForm();
                    // 刷新页面或更新群聊列表
                    window.location.reload();
                } else {
                    alert('群聊创建失败：' + result.message);
                }
            } catch (error) {
                console.error('创建群聊失败:', error);
                alert('创建群聊失败，请稍后重试');
            }
        }
        
        // 好友项点击事件
        document.querySelectorAll('.friend-item[data-friend-id]').forEach(item => {
            item.addEventListener('click', () => {
                const friendId = item.dataset.friendId;
                window.location.href = `chat.php?chat_type=friend&id=${friendId}`;
            });
        });
        
        // 群聊项点击事件
        document.querySelectorAll('.friend-item[data-group-id]').forEach(item => {
            item.addEventListener('click', (e) => {
                // 如果点击的是菜单按钮，不跳转
                if (e.target.closest('.btn-icon') || e.target.closest('.group-menu')) {
                    return;
                }
                const groupId = item.dataset.groupId;
                window.location.href = `chat.php?chat_type=group&id=${groupId}`;
            });
        });
        
        // 切换群聊菜单显示
        function toggleGroupMenu(event, groupId) {
            event.stopPropagation();
            
            // 关闭所有其他群聊菜单
            document.querySelectorAll('.group-menu').forEach(menu => {
                if (menu.id !== `group-menu-${groupId}`) {
                    menu.style.display = 'none';
                }
            });
            
            // 切换当前群聊菜单
            const menu = document.getElementById(`group-menu-${groupId}`);
            menu.style.display = menu.style.display === 'block' ? 'none' : 'block';
        }
        
        // 点击页面其他地方关闭菜单
        document.addEventListener('click', () => {
            // 关闭群聊菜单
            document.querySelectorAll('.group-menu').forEach(menu => {
                menu.style.display = 'none';
            });
            // 关闭成员菜单
            document.querySelectorAll('[id^="member-menu-"]').forEach(menu => {
                menu.style.display = 'none';
            });
            // 关闭好友菜单
            document.querySelectorAll('[id^="friend-menu-"]').forEach(menu => {
                menu.style.display = 'none';
            });
        });
        
        // 阻止菜单内部点击关闭菜单
        document.querySelectorAll('.group-menu, [id^="member-menu-"], [id^="friend-menu-"]').forEach(menu => {
            menu.addEventListener('click', (e) => {
                e.stopPropagation();
            });
        });
        
        // 查看群聊成员
        function showGroupMembers(groupId) {
            // 显示弹窗
            const modal = document.getElementById('group-members-modal');
            const title = document.getElementById('modal-title');
            const content = document.getElementById('modal-content');
            
            title.textContent = '群聊成员';
            content.innerHTML = '<div style="text-align: center; padding: 20px; color: #666;"><div style="font-size: 48px; margin-bottom: 10px;">⏳</div>加载中...</div>';
            modal.style.display = 'flex';
            
            // 加载群聊成员数据
            fetch(`get_group_members.php?group_id=${groupId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        let membersHtml = `
                            <div style="margin-bottom: 20px;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                    <h4 style="margin: 0; font-size: 16px; color: #333;">群聊成员 (${data.members.length}/${data.max_members})</h4>
                                    ${(data.is_owner || data.is_admin) ? `
                                        <button onclick="addMembersToGroup(${groupId})" style="padding: 8px 16px; background: #667eea; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; transition: background-color 0.2s;">
                                            <span style="margin-right: 5px;">➕</span>添加成员
                                        </button>
                                    ` : ''}
                                </div>
                                <div style="background: #e8f5e8; padding: 10px; border-radius: 6px; font-size: 14px; color: #2e7d32;">
                                    当前群聊共有 ${data.members.length} 名成员，群聊上限为 ${data.max_members} 名成员
                                </div>
                            </div>
                            <div style="display: flex; flex-direction: column; gap: 15px;">
                        `;
                        
                        data.members.forEach(member => {
                            const role = member.is_owner ? '群主' : (member.is_admin ? '管理员' : '成员');
                            const roleClass = member.is_owner ? 'background: #ff4757; color: white;' : (member.is_admin ? 'background: #ffa502; color: white;' : 'background: #667eea; color: white;');
                            const isCurrentUser = member.id == <?php echo $user_id; ?>;
                            
                            membersHtml += `
                                <div style="display: flex; align-items: center; justify-content: space-between; padding: 12px; background: #f8f9fa; border-radius: 8px; position: relative;">
                                    <div style="display: flex; align-items: center; gap: 12px;">
                                        <div style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; font-size: 14px;">
                                            ${member.username.substring(0, 2)}
                                        </div>
                                        <div>
                                            <h5 style="margin: 0 0 4px 0; font-size: 15px; color: #333;">${member.username}</h5>
                                            <p style="margin: 0; font-size: 12px; color: #666;">${member.email}</p>
                                        </div>
                                    </div>
                                    <div style="display: flex; align-items: center; gap: 12px;">
                                        <span style="padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 600; ${roleClass}">
                                            ${role}
                                        </span>
                                        ${!isCurrentUser ? `
                                            <div style="position: relative;">
                                                <button onclick="toggleMemberMenu(event, ${member.id}, '${member.username}', ${member.is_admin}, ${member.is_owner}, ${groupId})" style="background: none; border: none; font-size: 18px; color: #666; cursor: pointer; padding: 4px; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; border-radius: 50%; transition: background-color 0.2s;">
                                                    ⋮
                                                </button>
                                                <div id="member-menu-${member.id}" style="display: none; position: absolute; top: 0; right: 0; background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1); z-index: 2001; min-width: 150px;">
                                                    <button onclick="sendFriendRequest(${member.id}, '${member.username}')" style="display: block; width: 100%; padding: 10px 15px; text-align: left; border: none; background: none; cursor: pointer; font-size: 14px; color: #333; transition: background-color 0.2s; border-radius: 8px;">添加好友</button>
                                                    ${data.is_owner || data.is_admin ? `
                                                        <button onclick="removeMember(${groupId}, ${member.id}, '${member.username}')" style="display: block; width: 100%; padding: 10px 15px; text-align: left; border: none; background: none; cursor: pointer; font-size: 14px; color: #333; transition: background-color 0.2s; border-radius: 8px;">踢出群聊</button>
                                                    ` : ''}
                                                    ${data.is_owner && !member.is_owner ? `
                                                        <button onclick="toggleAdmin(${groupId}, ${member.id}, '${member.username}', ${member.is_admin})" style="display: block; width: 100%; padding: 10px 15px; text-align: left; border: none; background: none; cursor: pointer; font-size: 14px; color: #333; transition: background-color 0.2s; border-radius: 8px;">${member.is_admin ? '取消管理员' : '设置为管理员'}</button>
                                                    ` : ''}
                                                </div>
                                            </div>
                                        ` : ''}
                                    </div>
                                </div>
                            `;
                        });
                        
                        membersHtml += '</div>';
                        content.innerHTML = membersHtml;
                    } else {
                        content.innerHTML = `<div style="text-align: center; padding: 20px; color: #ff4757;"><div style="font-size: 48px; margin-bottom: 10px;">❌</div>加载失败：${data.message}</div>`;
                    }
                })
                .catch(error => {
                    console.error('加载群聊成员失败:', error);
                    content.innerHTML = '<div style="text-align: center; padding: 20px; color: #ff4757;"><div style="font-size: 48px; margin-bottom: 10px;">❌</div>加载失败：网络错误</div>';
                });
        }
        
        // 关闭群聊成员弹窗
        function closeGroupMembersModal() {
            const modal = document.getElementById('group-members-modal');
            modal.style.display = 'none';
            // 关闭所有成员菜单
            document.querySelectorAll('[id^="member-menu-"]').forEach(menu => {
                menu.style.display = 'none';
            });
        }
        
        // 添加成员到群聊
        function addMembersToGroup(groupId) {
            // 显示弹窗
            const modal = document.getElementById('group-members-modal');
            const title = document.getElementById('modal-title');
            const content = document.getElementById('modal-content');
            
            title.textContent = '添加群聊成员';
            content.innerHTML = '<div style="text-align: center; padding: 20px; color: #666;"><div style="font-size: 48px; margin-bottom: 10px;">⏳</div>加载中...</div>';
            modal.style.display = 'flex';
            
            // 加载可添加的好友列表
            fetch(`get_available_friends.php?group_id=${groupId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        let friendsHtml = `
                            <div style="margin-bottom: 20px;">
                                <h4 style="margin: 0 0 10px 0; font-size: 16px; color: #333;">选择好友添加到群聊</h4>
                                <div style="background: #e8f5e8; padding: 10px; border-radius: 6px; font-size: 14px; color: #2e7d32;">
                                    您有 ${data.friends.length} 位好友可以添加到该群聊
                                </div>
                            </div>
                            <div style="max-height: 400px; overflow-y: auto; margin-bottom: 20px;">
                                <div style="display: flex; flex-direction: column; gap: 10px;">
                        `;
                        
                        data.friends.forEach(friend => {
                            friendsHtml += `
                                <div style="display: flex; align-items: center; gap: 12px; padding: 12px; background: #f8f9fa; border: 1px solid #e0e0e0; border-radius: 8px;">
                                    <div style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; font-size: 14px;">
                                        ${friend.username.substring(0, 2)}
                                    </div>
                                    <div style="flex: 1;">
                                        <h5 style="margin: 0 0 4px 0; font-size: 15px; color: #333;">${friend.username}</h5>
                                        <p style="margin: 0; font-size: 12px; color: #666;">${friend.email}</p>
                                    </div>
                                    <div>
                                        <input type="checkbox" id="friend-${friend.id}" value="${friend.id}" style="width: 18px; height: 18px; cursor: pointer;">
                                    </div>
                                </div>
                            `;
                        });
                        
                        friendsHtml += `
                                </div>
                            </div>
                            <div style="display: flex; gap: 10px; justify-content: flex-end;">
                                <button onclick="closeGroupMembersModal()" style="padding: 10px 20px; background: #f8f9fa; border: 1px solid #e0e0e0; border-radius: 6px; cursor: pointer; font-size: 14px; transition: background-color 0.2s;">
                                    取消
                                </button>
                                <button onclick="confirmAddMembers(${groupId})" style="padding: 10px 20px; background: #667eea; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; transition: background-color 0.2s;">
                                    添加成员
                                </button>
                            </div>
                        `;
                        
                        content.innerHTML = friendsHtml;
                    } else {
                        content.innerHTML = `<div style="text-align: center; padding: 20px; color: #ff4757;"><div style="font-size: 48px; margin-bottom: 10px;">❌</div>加载失败：${data.message}</div>`;
                    }
                })
                .catch(error => {
                    console.error('加载可添加好友失败:', error);
                    content.innerHTML = '<div style="text-align: center; padding: 20px; color: #ff4757;"><div style="font-size: 48px; margin-bottom: 10px;">❌</div>加载失败：网络错误</div>';
                });
        }
        
        // 确认添加成员到群聊
        function confirmAddMembers(groupId) {
            // 获取选中的好友ID
            const selectedFriends = [];
            document.querySelectorAll('input[type="checkbox"]:checked').forEach(checkbox => {
                if (checkbox.id.startsWith('friend-')) {
                    selectedFriends.push(checkbox.value);
                }
            });
            
            if (selectedFriends.length === 0) {
                alert('请选择至少一位好友添加到群聊');
                return;
            }
            
            // 发送添加成员请求
            fetch('add_group_members.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `group_id=${groupId}&friend_ids=${selectedFriends.join(',')}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(`成功添加 ${data.added_count} 位成员到群聊`);
                    // 重新加载群聊成员列表
                    showGroupMembers(groupId);
                } else {
                    alert('添加成员失败：' + data.message);
                }
            })
            .catch(error => {
                console.error('添加成员失败:', error);
                alert('添加成员失败：网络错误');
            });
        }
        
        // 切换成员菜单显示
        function toggleMemberMenu(event, memberId, memberName, isAdmin, isOwner, groupId) {
            event.stopPropagation();
            
            // 关闭所有其他成员菜单
            document.querySelectorAll('[id^="member-menu-"]').forEach(menu => {
                if (menu.id !== `member-menu-${memberId}`) {
                    menu.style.display = 'none';
                }
            });
            
            // 切换当前成员菜单
            const menu = document.getElementById(`member-menu-${memberId}`);
            menu.style.display = menu.style.display === 'block' ? 'none' : 'block';
        }
        
        // 切换好友菜单显示
        function toggleFriendMenu(event, friendId, friendName) {
            event.stopPropagation();
            
            // 关闭所有其他好友菜单
            document.querySelectorAll('[id^="friend-menu-"]').forEach(menu => {
                if (menu.id !== `friend-menu-${friendId}`) {
                    menu.style.display = 'none';
                }
            });
            
            // 切换当前好友菜单
            const menu = document.getElementById(`friend-menu-${friendId}`);
            menu.style.display = menu.style.display === 'block' ? 'none' : 'block';
        }
        
        // 删除好友
        function deleteFriend(friendId, friendName) {
            if (confirm(`确定要删除好友 ${friendName} 吗？`)) {
                // 发送请求删除好友
                fetch(`delete_friend.php?friend_id=${friendId}`, {
                    method: 'POST'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(`已成功删除好友 ${friendName}`);
                        // 刷新页面或更新好友列表
                        window.location.reload();
                    } else {
                        alert(`删除失败：${data.message}`);
                    }
                })
                .catch(error => {
                    console.error('删除好友失败:', error);
                    alert('删除失败：网络错误');
                });
            }
        }
        
        // 发送好友请求
        function sendFriendRequest(memberId, memberName) {
            // 发送好友请求
            fetch(`send_friend_request.php?friend_id=${memberId}`, {
                method: 'POST'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(`已向 ${memberName} 发送好友请求`);
                } else {
                    alert(`发送请求失败：${data.message}`);
                }
            })
            .catch(error => {
                console.error('发送好友请求失败:', error);
                alert('发送请求失败：网络错误');
            });
        }
        
        // 踢出群聊成员
        function removeMember(groupId, memberId, memberName) {
            if (confirm(`确定要将 ${memberName} 踢出群聊吗？`)) {
                // 踢出群聊
                fetch(`remove_group_member.php?group_id=${groupId}&member_id=${memberId}`, {
                    method: 'POST'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(`${memberName} 已被踢出群聊`);
                        // 刷新成员列表
                        showGroupMembers(groupId);
                    } else {
                        alert(`踢出失败：${data.message}`);
                    }
                })
                .catch(error => {
                    console.error('踢出群聊失败:', error);
                    alert('踢出失败：网络错误');
                });
            }
        }
        
        // 设置或取消管理员
        function toggleAdmin(groupId, memberId, memberName, isAdmin) {
            const action = isAdmin ? '取消管理员' : '设置为管理员';
            if (confirm(`确定要${action} ${memberName}吗？`)) {
                // 设置或取消管理员
                fetch(`set_group_admin.php?group_id=${groupId}&member_id=${memberId}&is_admin=${isAdmin ? 0 : 1}`, {
                    method: 'POST'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(`${memberName} 已被${action}`);
                        // 刷新成员列表
                        showGroupMembers(groupId);
                    } else {
                        alert(`${action}失败：${data.message}`);
                    }
                })
                .catch(error => {
                    console.error(`${action}失败:`, error);
                    alert(`${action}失败：网络错误`);
                });
            }
        }
        
        // 转让群主
        function transferGroupOwnership(groupId) {
            // 显示弹窗
            const modal = document.getElementById('group-members-modal');
            const title = document.getElementById('modal-title');
            const content = document.getElementById('modal-content');
            
            title.textContent = '转让群主';
            content.innerHTML = '<div style="text-align: center; padding: 20px; color: #666;"><div style="font-size: 48px; margin-bottom: 10px;">⏳</div>加载中...</div>';
            modal.style.display = 'flex';
            
            // 加载群聊成员数据
            fetch(`get_group_members.php?group_id=${groupId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        let membersHtml = `
                            <div style="margin-bottom: 20px;">
                                <h4 style="margin: 0 0 10px 0; font-size: 16px; color: #333;">选择新群主</h4>
                                <p style="margin: 0; font-size: 14px; color: #666;">请从以下成员中选择一位作为新群主</p>
                            </div>
                            <div style="display: flex; flex-direction: column; gap: 10px;">
                        `;
                        
                        data.members.forEach(member => {
                            if (!member.is_owner) { // 排除当前群主
                                membersHtml += `
                                    <button onclick="confirmTransferOwnership(${groupId}, ${member.id}, '${member.username}')" style="display: flex; align-items: center; justify-content: space-between; padding: 12px; background: #f8f9fa; border: 1px solid #e0e0e0; border-radius: 8px; cursor: pointer; text-align: left;">
                                        <div style="display: flex; align-items: center; gap: 12px;">
                                            <div style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; font-size: 14px;">
                                                ${member.username.substring(0, 2)}
                                            </div>
                                            <div>
                                                <h5 style="margin: 0 0 4px 0; font-size: 15px; color: #333;">${member.username}</h5>
                                                <p style="margin: 0; font-size: 12px; color: #666;">${member.email}</p>
                                            </div>
                                        </div>
                                        <span style="font-size: 18px;">→</span>
                                    </button>
                                `;
                            }
                        });
                        
                        membersHtml += '</div>';
                        content.innerHTML = membersHtml;
                    } else {
                        content.innerHTML = `<div style="text-align: center; padding: 20px; color: #ff4757;"><div style="font-size: 48px; margin-bottom: 10px;">❌</div>加载失败：${data.message}</div>`;
                    }
                })
                .catch(error => {
                    console.error('加载群聊成员失败:', error);
                    content.innerHTML = '<div style="text-align: center; padding: 20px; color: #ff4757;"><div style="font-size: 48px; margin-bottom: 10px;">❌</div>加载失败：网络错误</div>';
                });
        }
        
        // 确认转让群主
        function confirmTransferOwnership(groupId, newOwnerId, newOwnerName) {
            if (confirm(`确定要将群主转让给 ${newOwnerName} 吗？`)) {
                // 这里可以实现转让群主的功能
                fetch(`transfer_ownership.php?group_id=${groupId}&new_owner_id=${newOwnerId}`, {
                    method: 'POST'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(`群主已成功转让给 ${newOwnerName}`);
                        closeGroupMembersModal();
                        // 刷新页面或更新群聊信息
                        window.location.reload();
                    } else {
                        alert(`转让失败：${data.message}`);
                    }
                })
                .catch(error => {
                    console.error('转让群主失败:', error);
                    alert('转让失败：网络错误');
                });
            }
        }
        
        // 退出群聊
        function leaveGroup(groupId) {
            if (confirm('确定要退出该群聊吗？')) {
                // 这里可以实现退出群聊的功能
                fetch(`leave_group.php?group_id=${groupId}`, {
                    method: 'POST'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('已成功退出群聊');
                        // 跳转到聊天列表页面或刷新页面
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
                // 这里可以实现解散群聊的功能
                fetch(`delete_group.php?group_id=${groupId}`, {
                    method: 'POST'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('群聊已成功解散');
                        // 跳转到聊天列表页面
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
    </script>
    
    <!-- 群聊成员弹窗 -->
    <div id="group-members-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); z-index: 2000; justify-content: center; align-items: center;">
        <div style="background: white; border-radius: 12px; width: 90%; max-width: 500px; max-height: 80%; overflow: hidden; display: flex; flex-direction: column;">
            <!-- 弹窗头部 -->
            <div style="padding: 20px; background: #667eea; color: white; display: flex; justify-content: space-between; align-items: center;">
                <h3 id="modal-title" style="margin: 0; font-size: 18px;">群聊成员</h3>
                <button onclick="closeGroupMembersModal()" style="background: none; border: none; color: white; font-size: 24px; cursor: pointer; padding: 0; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center;">×</button>
            </div>
            
            <!-- 弹窗内容 -->
            <div style="padding: 20px; overflow-y: auto; flex: 1;">
                <div id="modal-content">
                    <!-- 群聊成员列表将通过JavaScript动态加载 -->
                </div>
            </div>
            
            <!-- 弹窗底部 -->
            <div style="padding: 15px 20px; background: #f8f9fa; border-top: 1px solid #e0e0e0; display: flex; justify-content: flex-end; gap: 10px;">
                <button onclick="closeGroupMembersModal()" style="padding: 8px 16px; background: #e0e0e0; border: none; border-radius: 6px; cursor: pointer; font-size: 14px;">关闭</button>
            </div>
        </div>
    </div>
    
    <!-- 反馈模态框 -->
    <div id="feedback-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); z-index: 2000; justify-content: center; align-items: center;">
        <div style="background: white; border-radius: 12px; width: 90%; max-width: 500px; overflow: hidden; display: flex; flex-direction: column;">
            <!-- 弹窗头部 -->
            <div style="padding: 20px; background: #667eea; color: white; display: flex; justify-content: space-between; align-items: center;">
                <h3 style="margin: 0; font-size: 18px;">反馈问题</h3>
                <button onclick="closeFeedbackModal()" style="background: none; border: none; color: white; font-size: 24px; cursor: pointer; padding: 0; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center;">×</button>
            </div>
            
            <!-- 弹窗内容 -->
            <div style="padding: 20px; overflow-y: auto; flex: 1;">
                <form id="feedback-form" enctype="multipart/form-data">
                    <div style="margin-bottom: 20px;">
                        <label for="feedback-content" style="display: block; margin-bottom: 8px; font-weight: 600; color: #333;">问题描述</label>
                        <textarea id="feedback-content" name="content" placeholder="请详细描述您遇到的问题" rows="5" style="width: 100%; padding: 12px; border: 1px solid #e0e0e0; border-radius: 8px; font-size: 14px; resize: vertical; outline: none;" required></textarea>
                    </div>
                    <div style="margin-bottom: 20px;">
                        <label for="feedback-image" style="display: block; margin-bottom: 8px; font-weight: 600; color: #333;">添加图片（可选）</label>
                        <input type="file" id="feedback-image" name="image" accept="image/*" style="width: 100%; padding: 10px; border: 1px solid #e0e0e0; border-radius: 8px; font-size: 14px;">
                        <p style="font-size: 12px; color: #666; margin-top: 5px;">支持JPG、PNG、GIF格式，最大5MB</p>
                    </div>
                    <div style="display: flex; gap: 10px; justify-content: flex-end;">
                        <button type="button" onclick="closeFeedbackModal()" style="padding: 10px 20px; background: #e0e0e0; border: none; border-radius: 6px; cursor: pointer; font-size: 14px;">取消</button>
                        <button type="submit" style="padding: 10px 20px; background: #667eea; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px;">提交反馈</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        // 显示反馈模态框
        function showFeedbackModal() {
            document.getElementById('feedback-modal').style.display = 'flex';
        }
        
        // 关闭反馈模态框
        function closeFeedbackModal() {
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
    </script>
</body>
</html>