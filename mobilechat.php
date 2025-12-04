<?php
require_once 'config.php';
require_once 'db.php';
require_once 'User.php';
require_once 'Friend.php';
require_once 'Message.php';

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

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];

// 获取GET参数
$selected_friend_id = isset($_GET['friend_id']) ? intval($_GET['friend_id']) : 0;

// 创建实例
$user = new User($conn);
$friend = new Friend($conn);
$message = new Message($conn);

// 获取当前用户信息
$current_user = $user->getUserById($user_id);

// 获取好友列表
$friends = $friend->getFriends($user_id);

// 获取当前选中的好友信息
$selected_friend = null;
if ($selected_friend_id) {
    $selected_friend = $user->getUserById($selected_friend_id);
}

// 获取聊天记录
$chat_history = [];
if ($selected_friend_id) {
    $chat_history = $message->getChatHistory($user_id, $selected_friend_id);
}

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
            padding-bottom: 140px;
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
            <button class="menu-item" onclick="showScanLoginModal()">扫码登录PC端</button>
            <a href="logout.php" class="menu-item menu-item-danger">退出登录</a>
        </div>
    </div>
    
    <!-- 遮罩层 -->
    <div class="overlay" id="overlay" onclick="toggleMenu()"></div>
    
    <!-- 主内容区域 -->
    <div class="main-content">
        <!-- 好友列表 -->
        <div class="friends-list <?php echo $selected_friend_id ? 'hidden' : ''; ?>">
            <div class="friends-header">
                <input type="text" class="search-input" placeholder="搜索好友..." id="search-input">
            </div>
            <?php foreach ($friends as $friend_item): ?>
                <div class="friend-item <?php echo $selected_friend_id == $friend_item['id'] ? 'active' : ''; ?>" data-friend-id="<?php echo $friend_item['id']; ?>">
                    <div class="friend-avatar">
                        <?php if (!empty($friend_item['avatar'])): ?>
                            <img src="<?php echo $friend_item['avatar']; ?>" alt="<?php echo $friend_item['username']; ?>" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                        <?php else: ?>
                            <?php echo substr($friend_item['username'], 0, 2); ?>
                        <?php endif; ?>
                        <div class="status-indicator <?php echo $friend_item['status']; ?>"></div>
                    </div>
                    <div class="friend-info">
                        <h3><?php echo $friend_item['username']; ?></h3>
                        <p><?php echo $friend_item['status'] == 'online' ? '在线' : '离线'; ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <!-- 聊天区域 -->
        <div class="chat-area <?php echo $selected_friend_id ? 'active' : ''; ?>">
            <?php if ($selected_friend): ?>
                <div class="chat-header">
                    <button class="back-btn" onclick="showFriendsList()" style="background: none; border: none; font-size: 18px; color: #667eea; margin-right: 10px;">
                        ←
                    </button>
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
                    
                    // 页面加载完成后加载初始聊天记录
                    document.addEventListener('DOMContentLoaded', loadInitialChatHistory);
                </script>
                
                <div class="input-area">
                    <form id="message-form" enctype="multipart/form-data">
                        <input type="hidden" name="friend_id" value="<?php echo $selected_friend_id; ?>">
                        <div class="input-wrapper">
                            <textarea id="message-input" name="message" placeholder="输入消息..."></textarea>
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
            <?php else: ?>
                <div class="messages-container" style="justify-content: center; align-items: center; text-align: center;">
                    <h2 style="color: #666; margin-bottom: 10px;">选择一个好友开始聊天</h2>
                    <p style="color: #999;">从左侧列表中选择一个好友，开始你们的对话</p>
                </div>
            <?php endif; ?>
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
            alert('添加好友功能开发中...');
            toggleMenu();
        }
        
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
                // 请求相机权限，优先使用前置相机
                const stream = await navigator.mediaDevices.getUserMedia({
                    video: {
                        facingMode: 'user',
                        width: { ideal: 400 },
                        height: { ideal: 400 }
                    }
                });
                
                const video = document.getElementById('qr-video');
                video.srcObject = stream;
                await video.play();
                
                // 等待视频加载完成后开始扫描
                video.onloadeddata = () => {
                    startScanning(video);
                };
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
            
            function scanFrame() {
                if (video.readyState === video.HAVE_ENOUGH_DATA) {
                    canvas.width = video.videoWidth;
                    canvas.height = video.videoHeight;
                    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                    
                    // 获取图像数据
                    const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
                    
                    // 使用ZXing库或其他方式解码二维码（这里使用简单的方式，实际项目中应使用专门的二维码库）
                    // 这里我们使用一个简单的方法，通过canvas.toDataURL()传递给服务器解码
                    // 但为了简化，我们假设前端可以直接解码
                    // 实际项目中建议使用jsQR库等前端二维码解码库
                    
                    // 这里模拟二维码解码，实际项目中应替换为真实的解码逻辑
                    // 由于浏览器限制，我们使用一个简单的方法来模拟
                    
                    // 注意：实际项目中，您应该引入一个二维码解码库，如jsQR
                    // 这里我们使用一个简化的方式，直接读取URL参数
                    
                    // 假设我们已经解码出二维码内容
                    // 这里我们使用一个定时器来模拟扫描过程
                    
                    // 实际项目中，您应该使用类似这样的代码：
                    /*
                    const code = jsQR(imageData.data, imageData.width, imageData.height);
                    if (code) {
                        handleScanResult(code.data);
                    } else {
                        requestAnimationFrame(scanFrame);
                    }
                    */
                    
                    // 简化版本：每500毫秒检查一次
                    setTimeout(scanFrame, 500);
                } else {
                    requestAnimationFrame(scanFrame);
                }
            }
            
            scanFrame();
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
        
        // 处理扫描结果（实际项目中应连接到二维码解码库）
        function handleScanResult(result) {
            if (!result) return;
            
            // 检查是否是本站的扫码登录链接
            const domain = window.location.host;
            if (result.includes(domain) && result.includes('scan_login.php')) {
                // 解析URL获取qid
                const url = new URL(result);
                const qid = url.searchParams.get('qid');
                
                if (qid) {
                    currentScanUrl = result;
                    currentQid = qid;
                    
                    // 获取当前IP地址
                    currentIpAddress = '<?php echo $user_ip; ?>';
                    
                    // 显示确认登录对话框
                    showConfirmModal();
                    
                    // 停止扫描
                    closeScanModal();
                }
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
                        'username': '<?php echo $username; ?>',
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
        
        // 好友选择
        document.querySelectorAll('.friend-item').forEach(item => {
            item.addEventListener('click', () => {
                const friendId = item.dataset.friendId;
                window.location.href = `mobilechat.php?friend_id=${friendId}`;
            });
        });
        
        // 显示好友列表
        function showFriendsList() {
            window.location.href = 'mobilechat.php';
        }
        
        // 消息相关函数
        function createMessage(message, isSent) {
            const messageDiv = document.createElement('div');
            messageDiv.className = `message ${isSent ? 'sent' : 'received'}`;
            
            const avatarDiv = document.createElement('div');
            avatarDiv.className = 'message-avatar';
            
            // 获取当前用户头像和好友头像
            const currentUserAvatar = '<?php echo !empty($current_user['avatar']) ? $current_user['avatar'] : ''; ?>';
            const friendAvatar = '<?php echo $selected_friend && !empty($selected_friend['avatar']) ? $selected_friend['avatar'] : ''; ?>';
            
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
                if (friendAvatar) {
                    const img = document.createElement('img');
                    img.src = friendAvatar;
                    img.alt = '<?php echo $selected_friend ? $selected_friend['username'] : ''; ?>';
                    img.style.cssText = 'width: 100%; height: 100%; border-radius: 50%; object-fit: cover;';
                    avatarDiv.appendChild(img);
                } else {
                    avatarDiv.textContent = '<?php echo $selected_friend ? substr($selected_friend['username'], 0, 2) : ''; ?>';
                }
            }
            
            const contentDiv = document.createElement('div');
            contentDiv.className = 'message-content';
            
            if (message.type === 'text') {
                const textDiv = document.createElement('div');
                textDiv.className = 'message-text';
                textDiv.textContent = message.content;
                contentDiv.appendChild(textDiv);
            } else {
                const fileName = message.file_name;
                const fileExtension = fileName.split('.').pop().toLowerCase();
                const fileUrl = message.file_path;
                
                // 禁止显示的文件扩展名
                const forbiddenExtensions = ['php', 'html', 'js', 'htm', 'css', 'xml'];
                
                // 如果是禁止的文件扩展名，不显示该文件
                if (forbiddenExtensions.includes(fileExtension)) {
                    const forbiddenMessage = document.createElement('div');
                    forbiddenMessage.style.cssText = 'color: #999; font-size: 14px; padding: 10px; background: #f8f9fa; border-radius: 8px;';
                    forbiddenMessage.textContent = '该文件类型不支持显示';
                    contentDiv.appendChild(forbiddenMessage);
                } else {
                    // 图片类型
                    const imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'tiff', 'ico'];
                    if (imageExtensions.includes(fileExtension)) {
                        const img = document.createElement('img');
                        img.src = fileUrl;
                        img.alt = fileName;
                        img.style.cssText = 'max-width: 200px; max-height: 200px; cursor: pointer; border-radius: 8px; transition: transform 0.2s;';
                        
                        // 添加图片加载失败处理
                        img.onerror = () => {
                            img.remove();
                            const errorMessage = document.createElement('div');
                            errorMessage.style.cssText = 'color: #999; font-size: 14px; padding: 10px; background: #f8f9fa; border-radius: 8px;';
                            errorMessage.textContent = '文件已被清理，每15天清理一次uploads目录';
                            contentDiv.appendChild(errorMessage);
                        };
                        
                        contentDiv.appendChild(img);
                    } 
                    // 音频类型
                    else if (['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac', 'wma', 'aiff', 'opus', 'webm'].includes(fileExtension)) {
                        // 创建简单的音频播放器
                        const audio = document.createElement('audio');
                        audio.src = fileUrl;
                        audio.controls = true;
                        audio.style.cssText = 'max-width: 250px;';
                        
                        // 添加音频加载失败处理
                        audio.onerror = () => {
                            audio.remove();
                            const errorMessage = document.createElement('div');
                            errorMessage.style.cssText = 'color: #999; font-size: 14px; padding: 10px; background: #f8f9fa; border-radius: 8px;';
                            errorMessage.textContent = '文件已被清理，每15天清理一次uploads目录';
                            contentDiv.appendChild(errorMessage);
                        };
                        
                        contentDiv.appendChild(audio);
                    } 
                    // 其他文件类型
                    else {
                        const fileLinkContainer = document.createElement('div');
                        
                        const fileLink = document.createElement('a');
                        fileLink.href = fileUrl;
                        fileLink.download = fileName;
                        fileLink.style.cssText = 'color: #667eea; text-decoration: none; font-weight: 600;';
                        
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
                                    errorMessage.textContent = '文件已被清理，每15天清理一次uploads目录';
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
                        
                        fileLink.textContent = fileName;
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
                
                // 刷新页面或添加新消息
                window.location.reload();
            } else {
                alert(result.message);
            }
        });
        
        // 文件选择事件
        document.getElementById('file-input')?.addEventListener('change', (e) => {
            const file = e.target.files[0];
            if (file) {
                document.getElementById('message-form').dispatchEvent(new Event('submit'));
            }
        });
    </script>
    </div>
</body>
</html>