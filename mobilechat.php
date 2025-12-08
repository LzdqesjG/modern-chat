<?php
require_once 'config.php';
require_once 'db.php';
require_once 'User.php';
require_once 'Friend.php';
require_once 'Message.php';
require_once 'Group.php';

// 检测设备类型
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

// 如果是电脑设备，跳转到桌面端聊天页面
if (!isMobileDevice()) {
    header('Location: chat.php');
    exit;
}

// 检查用户是否登录
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
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

// 获取GET参数
$selected_friend_id = isset($_GET['friend_id']) ? intval($_GET['friend_id']) : 0;

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

// 获取聊天类型和选中的聊天对象
$chat_type = isset($_GET['chat_type']) ? $_GET['chat_type'] : 'friend'; // 'friend' 或 'group'
$selected_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$selected_friend = null;
$selected_group = null;

// 处理选中的聊天对象
if ($selected_id) {
    if ($chat_type === 'friend') {
        $selected_friend = $user->getUserById($selected_id);
    } elseif ($chat_type === 'group') {
        $selected_group = $group->getGroupInfo($selected_id);
    }
}

// 获取聊天记录
$chat_history = [];
if ($selected_id) {
    if ($chat_type === 'friend') {
        $chat_history = $message->getChatHistory($user_id, $selected_id);
    } elseif ($chat_type === 'group') {
        $chat_history = $group->getGroupMessages($selected_id, $user_id);
    }
}

// 检查用户是否被封禁
$ban_info = $user->isBanned($user_id);

// 检查用户是否同意协议
$agreed_to_terms = $user->hasAgreedToTerms($user_id);

// 获取待处理的好友请求
$pending_requests = $friend->getPendingRequests($user_id);

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
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Modern Chat - 移动端</title>
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
            flex-direction: column;
        }
        
        /* 顶部导航栏 */
        .top-nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 16px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        }
        
        .top-nav h1 {
            font-size: 18px;
            font-weight: 600;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .user-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: white;
            color: #667eea;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 14px;
        }
        
        .user-status {
            font-size: 12px;
        }
        
        .menu-btn {
            background: none;
            border: none;
            color: white;
            font-size: 20px;
            cursor: pointer;
            padding: 5px;
        }
        
        /* 菜单面板 */
        .menu-panel {
            position: fixed;
            top: 0;
            right: -100%;
            width: 80%;
            max-width: 300px;
            height: 100vh;
            background: white;
            box-shadow: -2px 0 10px rgba(0, 0, 0, 0.1);
            transition: right 0.3s ease;
            z-index: 1000;
            overflow-y: auto;
        }
        
        .menu-panel.open {
            right: 0;
        }
        
        .menu-header {
            padding: 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-align: center;
        }
        
        .menu-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: white;
            color: #667eea;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 32px;
            margin: 0 auto 15px;
        }
        
        .menu-username {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .menu-email {
            font-size: 14px;
            margin-bottom: 5px;
        }
        
        .menu-ip {
            font-size: 12px;
            opacity: 0.8;
        }
        
        .menu-items {
            padding: 20px;
        }
        
        .menu-item {
            display: block;
            width: 100%;
            padding: 15px;
            margin-bottom: 10px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            text-align: center;
            text-decoration: none;
        }
        
        .menu-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }
        
        .menu-item-danger {
            background: linear-gradient(135deg, #ff4757 0%, #ff3742 100%);
        }
        
        .menu-item-danger:hover {
            box-shadow: 0 4px 12px rgba(255, 71, 87, 0.3);
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
        
        /* 主内容区域 */
        .main-content {
            display: flex;
            flex: 1;
            overflow: hidden;
        }
        
        /* 好友列表 */
        .friends-list {
            width: 100%;
            background: white;
            overflow-y: auto;
            border-right: 1px solid #e0e0e0;
        }
        
        .friends-header {
            padding: 15px;
            background: #f8f9fa;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .search-input {
            width: 100%;
            padding: 10px;
            border: 1px solid #e0e0e0;
            border-radius: 20px;
            font-size: 14px;
            outline: none;
            transition: all 0.2s ease;
        }
        
        .search-input:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .friend-item {
            display: flex;
            align-items: center;
            padding: 15px;
            border-bottom: 1px solid #f0f0f0;
            cursor: pointer;
            transition: background 0.2s ease;
        }
        
        .friend-item:hover {
            background: #f8f9fa;
        }
        
        .friend-item.active {
            background: #e8f0fe;
        }
        
        .friend-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 16px;
            position: relative;
            margin-right: 12px;
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
            background: #ffa502;
        }
        
        .friend-info {
            flex: 1;
        }
        
        .friend-info h3 {
            font-size: 15px;
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
        }
        
        .friend-info p {
            font-size: 13px;
            color: #666;
        }
        
        .unread-count {
            background: #ff4757;
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 600;
        }
        
        /* 聊天区域 */
        .chat-area {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: #f8f9fa;
        }
        
        .chat-header {
            padding: 15px;
            background: white;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            align-items: center;
        }
        
        .chat-header .friend-avatar {
            margin-right: 12px;
        }
        
        .chat-header-info h2 {
            font-size: 16px;
            font-weight: 600;
            color: #333;
            margin-bottom: 2px;
        }
        
        .chat-header-info p {
            font-size: 13px;
            color: #666;
        }
        
        .messages-container {
            flex: 1;
            padding: 20px;
            padding-bottom: 190px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
        }
        
        .message {
            max-width: 70%;
            margin-bottom: 15px;
            display: flex;
            animation: fadeIn 0.3s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .message.sent {
            align-self: flex-end;
            flex-direction: row-reverse;
        }
        
        .message.received {
            align-self: flex-start;
        }
        
        .message-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 14px;
            margin: 0 8px;
        }
        
        .message-content {
            background: white;
            padding: 12px 16px;
            border-radius: 18px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            word-break: break-word;
        }
        
        .message.sent .message-content {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .message-text {
            font-size: 14px;
            line-height: 1.4;
        }
        
        .message-time {
            font-size: 11px;
            opacity: 0.7;
            margin-top: 5px;
            text-align: right;
        }
        
        .message.sent .message-time {
            text-align: right;
        }
        
        .message.received .message-time {
            text-align: left;
        }
        
        /* 输入区域 */
        .input-area {
            padding: 15px;
            background: white;
            border-top: 1px solid #e0e0e0;
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            z-index: 100;
            box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.1);
        }
        
        #message-form {
            display: flex;
            gap: 10px;
            align-items: flex-end;
        }
        
        .input-wrapper {
            flex: 1;
            position: relative;
        }
        
        #message-input {
            width: 100%;
            min-height: 40px;
            max-height: 120px;
            padding: 10px 15px;
            border: 1px solid #e0e0e0;
            border-radius: 20px;
            font-size: 14px;
            resize: none;
            outline: none;
            transition: all 0.2s ease;
        }
        
        #message-input:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .input-actions {
            display: flex;
            gap: 10px;
        }
        
        .btn-icon {
            width: 40px;
            height: 40px;
            border: none;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            font-size: 16px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }
        
        .btn-icon:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }
        
        #file-input {
            display: none;
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
        
        /* 自定义音频播放器 */
        .custom-audio-player {
            display: flex;
            align-items: center;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            border-radius: 8px;
            padding: 8px 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            max-width: 300px;
            width: 100%;
            box-sizing: border-box;
        }
        
        .audio-play-btn {
            width: 32px;
            height: 32px;
            border: none;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            transition: all 0.2s ease;
            margin-right: 12px;
        }
        
        .audio-play-btn:hover {
            transform: scale(1.1);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }
        
        .audio-play-btn.paused {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%);
        }
        
        .audio-progress-container {
            flex: 1;
            margin: 0 12px;
            position: relative;
        }
        
        .audio-progress-bar {
            width: 100%;
            height: 6px;
            background: rgba(0, 0, 0, 0.2);
            border-radius: 3px;
            cursor: pointer;
            overflow: hidden;
        }
        
        .audio-progress {
            height: 100%;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
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
            font-size: 12px;
            color: #666;
            min-width: 70px;
            text-align: center;
        }
        
        .audio-duration {
            font-size: 12px;
            color: #666;
            min-width: 40px;
            text-align: right;
        }
        
        /* 图片样式 */
        .message-content img {
            max-width: 200px;
            max-height: 200px;
            border-radius: 8px;
            cursor: pointer;
            transition: transform 0.2s ease;
        }
        
        .message-content img:hover {
            transform: scale(1.05);
        }
        
        /* 响应式设计 */
        @media (max-width: 768px) {
            .main-content {
                flex-direction: column;
            }
            
            .friends-list {
                width: 100%;
                height: 100%;
            }
            
            .friends-list.hidden {
                display: none;
            }
            
            .chat-area {
                display: none;
                height: 100%;
            }
            
            .chat-area.active {
                display: flex;
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <?php if (isset($_SESSION['feedback_received']) && $_SESSION['feedback_received']): ?>
        <div style="position: fixed; top: 20px; right: 20px; background: #4caf50; color: white; padding: 15px 20px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15); z-index: 1000; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;">
            您的反馈已收到，正在修复中，感谢您的反馈！
        </div>
        <?php unset($_SESSION['feedback_received']); ?>
    <?php endif; ?>
    
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
    <div class="chat-container">
    <!-- 顶部导航栏 -->
    <div class="top-nav">
        <h1>Modern Chat</h1>
        <div class="user-info">
            <div class="user-avatar">
                <?php if (!empty($current_user['avatar'])): ?>
                    <img src="<?php echo $current_user['avatar']; ?>" alt="<?php echo $username; ?>" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                <?php else: ?>
                    <?php echo substr($username, 0, 2); ?>
                <?php endif; ?>
            </div>
            <span class="user-status">在线</span>
            <button class="menu-btn" onclick="toggleMenu()">⋮</button>
        </div>
    </div>
    
    <!-- 菜单面板 -->
    <div class="menu-panel" id="menu-panel">
        <div class="menu-header">
            <div class="menu-avatar">
                <?php if (!empty($current_user['avatar'])): ?>
                    <img src="<?php echo $current_user['avatar']; ?>" alt="<?php echo $username; ?>" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                <?php else: ?>
                    <?php echo substr($username, 0, 2); ?>
                <?php endif; ?>
            </div>
            <div class="menu-username"><?php echo $username; ?></div>
            <div class="menu-email"><?php echo $_SESSION['email']; ?></div>
            <div class="menu-ip">IP地址: <?php echo $user_ip; ?></div>
        </div>
        <div class="menu-items">
            <a href="edit_profile.php" class="menu-item">编辑资料</a>
            <button class="menu-item" onclick="showAddFriendModal()">添加好友</button>
            <button class="menu-item" onclick="showFeedbackModal()">反馈问题</button>
            <button class="menu-item" onclick="showScanLoginModal()">扫码登录PC端</button>
            <a href="logout.php" class="menu-item menu-item-danger">退出登录</a>
        </div>
    </div>
    
    <!-- 遮罩层 -->
    <div class="overlay" id="overlay" onclick="toggleMenu()"></div>
    
    <!-- 主内容区域 -->
    <div class="main-content">
        <!-- 好友列表 -->
        <div class="friends-list <?php echo $selected_id ? 'hidden' : ''; ?>">
            <div class="friends-header">
                <input type="text" class="search-input" placeholder="搜索好友..." id="search-input">
            </div>
            
            <!-- 聊天类型切换 -->
            <div style="display: flex; background: white; border-bottom: 1px solid #e0e0e0;">
                <button class="chat-type-btn <?php echo $chat_type === 'friend' ? 'active' : ''; ?>" onclick="switchChatType('friend')" style="flex: 1; padding: 12px; border: none; background: transparent; cursor: pointer; font-size: 14px; font-weight: 600; color: <?php echo $chat_type === 'friend' ? '#667eea' : '#666'; ?>; border-bottom: 2px solid <?php echo $chat_type === 'friend' ? '#667eea' : 'transparent'; ?>">好友</button>
                <button class="chat-type-btn <?php echo $chat_type === 'group' ? 'active' : ''; ?>" onclick="switchChatType('group')" style="flex: 1; padding: 12px; border: none; background: transparent; cursor: pointer; font-size: 14px; font-weight: 600; color: <?php echo $chat_type === 'group' ? '#667eea' : '#666'; ?>; border-bottom: 2px solid <?php echo $chat_type === 'group' ? '#667eea' : 'transparent'; ?>">群聊</button>
            </div>
            
            <!-- 好友列表内容 -->
            <div id="friends-list-content" style="<?php echo $chat_type === 'friend' ? 'display: block;' : 'display: none;'; ?>">
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
                    </div>
                <?php endforeach; ?>
            </div>
            
            <!-- 群聊列表内容 -->
            <div id="groups-list-content" style="<?php echo $chat_type === 'group' ? 'display: block;' : 'display: none;'; ?>">
                <?php foreach ($groups as $group_item): ?>
                    <?php 
                        $group_unread_key = 'group_' . $group_item['id'];
                        $group_unread_count = isset($unread_counts[$group_unread_key]) ? $unread_counts[$group_unread_key] : 0;
                    ?>
                    <div class="friend-item <?php echo $chat_type === 'group' && $selected_id == $group_item['id'] ? 'active' : ''; ?>" data-group-id="<?php echo $group_item['id']; ?>">
                        <div class="friend-avatar" style="background: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%);">
                            <?php echo substr($group_item['name'], 0, 2); ?>
                        </div>
                        <div class="friend-info" style="position: relative;">
                            <h3><?php echo $group_item['name']; ?></h3>
                            <p>成员: <?php echo $group_item['member_count']; ?>人</p>
                            <?php if ($group_unread_count > 0): ?>
                                <div style="position: absolute; top: 0; right: -10px; background: #ff4757; color: white; border-radius: 50%; width: 20px; height: 20px; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: bold;">
                                    <?php echo $group_unread_count > 99 ? '99+' : $group_unread_count; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- 聊天区域 -->
        <div class="chat-area <?php echo $selected_id ? 'active' : ''; ?>">
            <?php if ($selected_friend || $selected_group) { ?>
                <div class="chat-header">
                    <button class="back-btn" onclick="showFriendsList()" style="background: none; border: none; font-size: 18px; color: #667eea; margin-right: 10px;">
                        ←
                    </button>
                    <div class="friend-avatar" style="<?php echo $selected_group ? 'background: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%);' : ''; ?>">
                        <?php if ($selected_friend) { ?>
                            <?php if (!empty($selected_friend['avatar'])) { ?>
                                <img src="<?php echo $selected_friend['avatar']; ?>" alt="<?php echo $selected_friend['username']; ?>" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                            <?php } else { ?>
                                <?php echo substr($selected_friend['username'], 0, 2); ?>
                            <?php } ?>
                            <div class="status-indicator <?php echo $selected_friend['status']; ?>"></div>
                        <?php } elseif ($selected_group) { ?>
                            <?php echo substr($selected_group['name'], 0, 2); ?>
                        <?php } ?>
                    </div>
                    <div class="chat-header-info">
                        <h2><?php echo $selected_friend ? $selected_friend['username'] : ($selected_group ? $selected_group['name'] : ''); ?></h2>
                        <p>
                            <?php if ($selected_friend) { ?>
                                <?php echo $selected_friend['status'] == 'online' ? '在线' : '离线'; ?>
                            <?php } elseif ($selected_group) { ?>
                                成员: <?php echo $selected_group['member_count']; ?>人
                            <?php } ?>
                        </p>
                    </div>
                </div>
                
                <div class="messages-container" id="messages-container">
                    <!-- 聊天记录将通过JavaScript动态加载 -->
                </div>
                
                <!-- 初始聊天记录数据 -->
                <script>
                    // 初始聊天记录数据
                    const initialChatHistory = <?php echo json_encode($chat_history); ?>;
                    
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
                    
                    // 页面加载完成后加载初始聊天记录和标记消息为已读
                    document.addEventListener('DOMContentLoaded', () => {
                        loadInitialChatHistory();
                        markMessagesAsRead();
                    });
                </script>
                
                <div class="input-area">
                    <form id="message-form" enctype="multipart/form-data">
                        <?php if ($selected_friend) { ?>
                            <input type="hidden" name="chat_type" value="friend">
                            <input type="hidden" name="id" value="<?php echo $selected_id; ?>">
                            <input type="hidden" name="friend_id" value="<?php echo $selected_id; ?>">
                        <?php } elseif ($selected_group) { ?>
                            <input type="hidden" name="chat_type" value="group">
                            <input type="hidden" name="id" value="<?php echo $selected_id; ?>">
                            <input type="hidden" name="group_id" value="<?php echo $selected_id; ?>">
                        <?php } ?>
                        <div class="input-wrapper">
                            <textarea id="message-input" name="message" placeholder="输入消息..."></textarea>
                            
                            <!-- @用户下拉选择框 -->
                            <div id="mention-dropdown" style="display: none; position: absolute; bottom: 100%; left: 0; width: 100%; max-height: 200px; overflow-y: auto; background: white; border: 1px solid #e0e0e0; border-radius: 8px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1); z-index: 1000;">
                                <!-- 成员列表将通过JavaScript动态生成 -->
                            </div>
                        </div>
                        <div class="input-actions">
                            <label for="file-input" class="btn-icon" title="发送文件">
                                📎
                            </label>
                            <input type="file" id="file-input" name="file" accept="*/*">
                            <button type="submit" class="btn-icon" title="发送消息">
                                ➤
                            </button>
                        </div>
                    </form>
                </div>
            <?php } else { ?>
                <div class="messages-container" style="justify-content: center; align-items: center; text-align: center;">
                    <h2 style="color: #666; margin-bottom: 10px;">选择一个好友开始聊天</h2>
                    <p style="color: #999;">从左侧列表中选择一个好友，开始你们的对话</p>
                </div>
            <?php } ?>
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
    
    <!-- 添加好友模态框 -->
    <div class="modal" id="add-friend-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); z-index: 2000; flex-direction: column; align-items: center; justify-content: center;">
        <div style="background: white; padding: 20px; border-radius: 12px; width: 90%; max-width: 400px;">
            <h3 style="margin-bottom: 20px; color: #333; text-align: center;">添加好友</h3>
            <form id="add-friend-form">
                <div style="margin-bottom: 20px;">
                    <label for="friend-username" style="display: block; margin-bottom: 8px; color: #666; font-weight: 500;">用户名</label>
                    <input type="text" id="friend-username" name="username" placeholder="请输入要添加的好友用户名" style="width: 100%; padding: 12px; border: 1px solid #e0e0e0; border-radius: 8px; font-size: 14px; outline: none; transition: all 0.2s ease;" required>
                </div>
                <div style="margin-bottom: 20px;">
                    <label for="friend-message" style="display: block; margin-bottom: 8px; color: #666; font-weight: 500;">验证消息</label>
                    <textarea id="friend-message" name="message" placeholder="请输入验证消息" rows="3" style="width: 100%; padding: 12px; border: 1px solid #e0e0e0; border-radius: 8px; font-size: 14px; resize: vertical; outline: none; transition: all 0.2s ease;"></textarea>
                </div>
                <div style="display: flex; gap: 10px; justify-content: center;">
                    <button type="button" onclick="closeAddFriendModal()" style="flex: 1; padding: 12px; background: #f5f5f5; color: #333; border: none; border-radius: 8px; cursor: pointer; font-weight: 500;">取消</button>
                    <button type="submit" style="flex: 1; padding: 12px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 500;">发送请求</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- 反馈模态框 -->
    <div class="modal" id="feedback-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); z-index: 2000; flex-direction: column; align-items: center; justify-content: center;">
        <div style="background: white; padding: 20px; border-radius: 12px; width: 90%; max-width: 400px;">
            <h3 style="margin-bottom: 20px; color: #333; text-align: center;">反馈问题</h3>
            <form id="feedback-form" enctype="multipart/form-data">
                <div style="margin-bottom: 20px;">
                    <label for="feedback-content" style="display: block; margin-bottom: 8px; color: #666; font-weight: 500;">问题描述</label>
                    <textarea id="feedback-content" name="content" placeholder="请详细描述您遇到的问题" rows="5" style="width: 100%; padding: 12px; border: 1px solid #e0e0e0; border-radius: 8px; font-size: 14px; resize: vertical; outline: none; transition: all 0.2s ease;" required></textarea>
                </div>
                <div style="margin-bottom: 20px;">
                    <label for="feedback-image" style="display: block; margin-bottom: 8px; color: #666; font-weight: 500;">添加图片（可选）</label>
                    <input type="file" id="feedback-image" name="image" accept="image/*" style="width: 100%; padding: 12px; border: 1px solid #e0e0e0; border-radius: 8px; font-size: 14px; outline: none; transition: all 0.2s ease;">
                    <p style="font-size: 12px; color: #999; margin-top: 5px;">支持JPG、PNG、GIF格式，最大5MB</p>
                </div>
                <div style="display: flex; gap: 10px; justify-content: center;">
                    <button type="button" onclick="closeFeedbackModal()" style="flex: 1; padding: 12px; background: #f5f5f5; color: #333; border: none; border-radius: 8px; cursor: pointer; font-weight: 500;">取消</button>
                    <button type="submit" style="flex: 1; padding: 12px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 500;">提交反馈</button>
                </div>
            </form>
        </div>
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
            modal.style.display = 'flex';
            toggleMenu();
        }
        
        // 关闭添加好友模态框
        function closeAddFriendModal() {
            const modal = document.getElementById('add-friend-modal');
            modal.style.display = 'none';
            // 重置表单
            document.getElementById('add-friend-form').reset();
        }
        
        // 处理添加好友表单提交
        document.getElementById('add-friend-form')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const formData = new FormData(e.target);
            const username = formData.get('username').trim();
            const message = formData.get('message')?.trim() || '';
            
            if (!username) {
                alert('请输入好友用户名');
                return;
            }
            
            try {
                // 首先通过用户名获取用户ID
                const userResponse = await fetch(`get_user_id.php?username=${encodeURIComponent(username)}`);
                const userData = await userResponse.json();
                
                if (userData.success) {
                    // 发送好友请求
                    const requestResponse = await fetch('send_friend_request.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `friend_id=${userData.user_id}`
                    });
                    
                    const requestResult = await requestResponse.json();
                    
                    if (requestResult.success) {
                        alert('好友请求已发送');
                        closeAddFriendModal();
                    } else {
                        alert(requestResult.message || '发送失败，请稍后重试');
                    }
                } else {
                    alert(userData.message || '未找到该用户');
                }
            } catch (error) {
                console.error('添加好友请求失败:', error);
                alert('网络错误，请稍后重试');
            }
        });
        
        // 显示反馈模态框
        function showFeedbackModal() {
            const modal = document.getElementById('feedback-modal');
            modal.style.display = 'flex';
            toggleMenu();
        }
        
        // 关闭反馈模态框
        function closeFeedbackModal() {
            const modal = document.getElementById('feedback-modal');
            modal.style.display = 'none';
            // 重置表单
            document.getElementById('feedback-form')?.reset();
        }
        
        // 处理反馈表单提交
        document.getElementById('feedback-form')?.addEventListener('submit', async (e) => {
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
        
        // 扫码登录相关变量
        let scanner = null;
        let currentScanUrl = '';
        let currentQid = '';
        let currentIpAddress = '';
        
        // 显示扫码登录模态框
        function showScanLoginModal() {
            toggleMenu(); // 关闭菜单
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
                // 请求相机权限，优先使用后置相机（适合扫码）
                // 提高相机分辨率，添加自动对焦
                const stream = await navigator.mediaDevices.getUserMedia({
                    video: {
                        facingMode: 'environment',
                        width: { ideal: 1280 },
                        height: { ideal: 720 },
                        focusMode: 'continuous',
                        exposureMode: 'continuous'
                    }
                });
                
                const video = document.getElementById('qr-video');
                video.srcObject = stream;
                await video.play();
                
                // 立即开始扫描，不需要等待onloadeddata
                startScanning(video);
            } catch (error) {
                console.error('相机访问失败:', error);
                const hint = document.getElementById('scan-hint');
                hint.textContent = '相机访问失败，请检查权限设置';
                hint.style.color = '#ff4757';
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
                            console.log('jsQR库未加载，等待加载完成');
                            return;
                        }
                        
                        // 使用jsQR库解码二维码，添加更详细的配置
                        const code = jsQR(imageData.data, imageData.width, imageData.height, {
                            inversionAttempts: 'both', // 尝试识别正常和反色二维码，提高识别率
                            // 提高识别率的配置
                        });
                        
                        if (code) {
                            // 扫描成功，更新提示
                            hint.textContent = '扫描成功！';
                            hint.style.color = '#4caf50';
                            console.log('扫描成功，二维码内容:', code.data);
                            // 处理扫描结果
                            handleScanResult(code.data);
                        } else {
                            // 继续扫描
                            requestAnimationFrame(scanFrame);
                            console.log('未识别到二维码，继续扫描');
                        }
                    } catch (error) {
                        console.error('扫描错误:', error);
                        // 继续扫描
                        requestAnimationFrame(scanFrame);
                    }
                } else {
                    // 视频还没准备好，继续等待
                    requestAnimationFrame(scanFrame);
                }
            }
            
            // 使用requestAnimationFrame提高扫描频率
            requestAnimationFrame(scanFrame);
        }
        
        // 停止扫描
        function stopScanner() {
            const video = document.getElementById('qr-video');
            if (video.srcObject) {
                const tracks = video.srcObject.getTracks();
                tracks.forEach(track => track.stop());
                video.srcObject = null;
            }
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
                        
                        // 获取当前IP地址
                        currentIpAddress = '<?php echo $user_ip; ?>';
                        
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
            message.innerHTML = `确定要在PC网页端登录吗？<br><br>登录IP地址: <strong>${currentIpAddress}</strong>`;
            modal.style.display = 'flex';
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
        
        // 显示登录成功提示
        function showSuccessModal() {
            const modal = document.getElementById('success-modal');
            modal.style.display = 'flex';
        }
        
        // 关闭登录成功提示
        function closeSuccessModal() {
            const modal = document.getElementById('success-modal');
            modal.style.display = 'none';
        }
        
        // 手动触发扫码结果（用于测试）
        function testScanResult() {
            const testUrl = window.location.origin + '/chat/scan_login.php?qid=test123';
            handleScanResult(testUrl);
        }
        
        // 添加jsQR库（实际项目中应在HTML头部引入）
        // 这里我们动态添加jsQR库
        const script = document.createElement('script');
        script.src = 'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js';
        script.onload = () => {
            console.log('jsQR库加载完成');
            // 重新定义startScanning函数，使用jsQR库
            startScanning = function(video) {
                function scanFrame() {
                    if (video.readyState === video.HAVE_ENOUGH_DATA) {
                        const canvas = document.createElement('canvas');
                        canvas.width = video.videoWidth;
                        canvas.height = video.videoHeight;
                        const ctx = canvas.getContext('2d');
                        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                        const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
                        const code = jsQR(imageData.data, imageData.width, imageData.height);
                        
                        if (code) {
                            handleScanResult(code.data);
                        } else {
                            requestAnimationFrame(scanFrame);
                        }
                    } else {
                        requestAnimationFrame(scanFrame);
                    }
                }
                scanFrame();
            };
        };
        document.head.appendChild(script);
        
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
        
        // 转换URL为链接
        function convertUrlsToLinks(text) {
            const urlRegex = /(https?:\/\/[^\s]+)/g;
            return text.replace(urlRegex, '<a href="$1" class="message-link" target="_blank" rel="noopener noreferrer">$1</a>');
        }
        
        // 好友和群聊选择 - 使用事件委托确保所有动态生成的元素都能被正确处理
        document.addEventListener('click', (e) => {
            const friendItem = e.target.closest('.friend-item');
            if (friendItem) {
                const friendId = friendItem.dataset.friendId;
                const groupId = friendItem.dataset.groupId;
                if (friendId) {
                    window.location.href = `mobilechat.php?chat_type=friend&id=${friendId}`;
                } else if (groupId) {
                    window.location.href = `mobilechat.php?chat_type=group&id=${groupId}`;
                }
            }
        });
        
        // 显示好友列表
        function showFriendsList() {
            window.location.href = 'mobilechat.php';
        }
        
        // 切换聊天类型
        function switchChatType(chatType) {
            window.location.href = `mobilechat.php?chat_type=${chatType}`;
        }
        
        // 消息相关函数
        function createMessage(message, isSent) {
            const messageDiv = document.createElement('div');
            messageDiv.className = `message ${isSent ? 'sent' : 'received'}`;
            
            const avatarDiv = document.createElement('div');
            avatarDiv.className = 'message-avatar';
            
            // 获取当前用户头像
            const currentUserAvatar = '<?php echo !empty($current_user['avatar']) ? $current_user['avatar'] : ''; ?>';
            
            if (isSent) {
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
                    // 图片类型 - 确保所有图片文件都显示为图片
                    const imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'tiff', 'ico'];
                    if (imageExtensions.includes(fileExtension)) {
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
                            img.remove();
                            const errorMessage = document.createElement('div');
                            errorMessage.style.cssText = 'color: #999; font-size: 14px; padding: 10px; background: #f8f9fa; border-radius: 8px;';
                            errorMessage.textContent = '文件已被清理，每7天清理一次uploads目录';
                            contentDiv.appendChild(errorMessage);
                        };
                        
                        imgContainer.appendChild(img);
                        contentDiv.appendChild(imgContainer);
                    } 
                    // 音频类型 - 确保所有音频文件都显示为自定义音频播放器
                    else if (['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac', 'wma', 'aiff', 'opus', 'webm'].includes(fileExtension)) {
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
                        const fileLinkContainer = document.createElement('div');
                        
                        const fileLink = document.createElement('a');
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
                                errorMessage.textContent = '文件已被清理，每7天清理一次uploads目录';
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
                memberItem.addEventListener('touchstart', () => {
                    memberItem.style.backgroundColor = '#f0f0f0';
                });
                
                memberItem.addEventListener('touchend', () => {
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
        messageInput?.addEventListener('input', (e) => {
            if (!messageInput) return;
            
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
        messageInput?.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                hideMentionDropdown();
                document.getElementById('message-form')?.dispatchEvent(new Event('submit'));
            } else if (e.key === 'Escape') {
                // 按ESC键隐藏下拉列表
                hideMentionDropdown();
            } else if (e.key === 'ArrowUp') {
                // 按上箭头选择上一个成员
                e.preventDefault();
                if (isMentioning && mentionDropdown?.children.length > 0) {
                    currentMentionIndex = Math.max(0, currentMentionIndex - 1);
                    highlightMentionItem(currentMentionIndex);
                }
            } else if (e.key === 'ArrowDown') {
                // 按下箭头选择下一个成员
                e.preventDefault();
                if (isMentioning && mentionDropdown?.children.length > 0) {
                    currentMentionIndex = Math.min(mentionDropdown.children.length - 1, currentMentionIndex + 1);
                    highlightMentionItem(currentMentionIndex);
                }
            } else if (e.key === 'Tab' || e.key === 'Enter') {
                // 按Tab或Enter键选择当前高亮的成员
                if (isMentioning && currentMentionIndex >= 0 && mentionDropdown?.children.length > 0) {
                    e.preventDefault();
                    const selectedMember = groupMembers[currentMentionIndex];
                    insertMention(selectedMember.username);
                    hideMentionDropdown();
                }
            }
        });
        
        // 高亮@列表中的当前选中项
        function highlightMentionItem(index) {
            if (!mentionDropdown) return;
            
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
            if (messageInput && mentionDropdown && !messageInput.contains(e.target) && !mentionDropdown.contains(e.target)) {
                hideMentionDropdown();
            }
        });
        
        // 添加发送状态锁和消息队列，确保消息按顺序发送
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
            const { formData, messageText, file, tempMessage, messageInput, messagesContainer, originalForm } = queueItem;
            
            try {
                // 发送消息请求
                const response = await fetch('send_message.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    // 清空输入
                    messageInput.value = '';
                    document.getElementById('file-input').value = '';
                    
                    // 替换临时消息为真实消息
                    tempMessage.remove();
                    const isSent = result.message.sender_id == <?php echo $user_id; ?>;
                    const newMessage = createMessage(result.message, isSent);
                    messagesContainer.appendChild(newMessage);
                    messagesContainer.scrollTop = messagesContainer.scrollHeight;
                } else {
                    // 显示错误并移除临时消息
                    tempMessage.remove();
                    alert(result.message);
                }
            } catch (error) {
                console.error('发送消息失败:', error);
                // 移除临时消息并显示错误
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
        document.getElementById('message-form')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const formData = new FormData(e.target);
            const messageInput = document.getElementById('message-input');
            const messagesContainer = document.getElementById('messages-container');
            
            const messageText = messageInput.value.trim();
            const file = document.getElementById('file-input').files[0];
            
            if (!messageText && !file) {
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
            
            // 创建临时消息对象
            const tempMessageData = {
                content: messageText,
                file_path: file ? URL.createObjectURL(file) : null,
                file_name: file ? file.name : null,
                created_at: new Date().toISOString()
            };
            
            // 创建临时消息元素
            const tempMessage = createMessage(tempMessageData, true);
            tempMessage.style.opacity = '0.7'; // 临时消息半透明显示
            messagesContainer.appendChild(tempMessage);
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
            
            // 将消息添加到队列
            messageQueue.push({
                formData,
                messageText,
                file,
                tempMessage,
                messageInput,
                messagesContainer,
                originalForm: e.target
            });
            
            // 开始处理消息队列
            processMessageQueue();
        });
        
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
        
        // 文件选择事件
        document.getElementById('file-input')?.addEventListener('change', (e) => {
            const file = e.target.files[0];
            if (file) {
                document.getElementById('message-form').dispatchEvent(new Event('submit'));
            }
        });
        
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
        
        // 页面加载完成后立即检查一次封禁状态
        document.addEventListener('DOMContentLoaded', () => {
            // 初始封禁检查
            <?php if ($ban_info): ?>
                showBanNotification('<?php echo $ban_info['reason']; ?>', '<?php echo $ban_info['expires_at']; ?>');
            <?php endif; ?>
            
            // 每30秒检查一次封禁状态
            setInterval(checkBanStatus, 30000);
        });
    </script>
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
    </div>
</body>
</html>