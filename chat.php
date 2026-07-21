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

// 安全检查函数
function checkSafetyStatus() {
    // 检查是否存在安全锁
    if (file_exists(__DIR__ . '/Safety_locked.lock')) {
        // 显示安全警告
        echo '<!DOCTYPE html>
        <html lang="zh-CN">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>安全警告 - Modern Chat</title>
            <style>
                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }
                body {
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
                    background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 20px;
                }
                .warning-container {
                    background: white;
                    border-radius: 16px;
                    box-shadow: 0 20px 40px rgba(0,0,0,0.1);
                    max-width: 500px;
                    width: 100%;
                    padding: 40px;
                    text-align: center;
                }
                .warning-icon {
                    font-size: 64px;
                    margin-bottom: 20px;
                }
                .warning-title {
                    font-size: 24px;
                    font-weight: 600;
                    color: #ff4d4f;
                    margin-bottom: 16px;
                }
                .warning-message {
                    font-size: 16px;
                    color: #666;
                    line-height: 1.6;
                    margin-bottom: 30px;
                }
                .update-link {
                    display: inline-block;
                    padding: 12px 30px;
                    background: linear-gradient(135deg, #12b7f5 0%, #00a2e8 100%);
                    color: white;
                    text-decoration: none;
                    border-radius: 8px;
                    font-weight: 600;
                    transition: all 0.3s ease;
                    box-shadow: 0 4px 15px rgba(18, 183, 245, 0.4);
                }
                .update-link:hover {
                    transform: translateY(-2px);
                    box-shadow: 0 6px 20px rgba(18, 183, 245, 0.5);
                }
            </style>
        </head>
        <body>
            <div class="warning-container">
                <div class="warning-icon"><svg viewBox="0 0 24 24" width="64" height="64" fill="#ff9800"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/></svg></div>
                <h2 class="warning-title">安全警告</h2>
                <p class="warning-message">您的服务器正处于不安全状态，请登录系统管理员账号访问 <a href="updata.php" class="update-link">系统更新</a> 进行安全更新后即可解锁</p>
            </div>
        </body>
        </html>';
        exit;
    }
    
    // 检查版本是否需要锁定
    $distinctionVerUrl = 'https://updata.sunaookami-shiroko.top/distinction_ver.json';
    $distinctionVerJson = @file_get_contents($distinctionVerUrl);
    
    if ($distinctionVerJson !== false) {
        $distinctionVerData = json_decode($distinctionVerJson, true);
        if ($distinctionVerData !== null && isset($distinctionVerData['version'])) {
            $serverVer = $distinctionVerData['version'];
            
            // 检查本地Safety_distinction.json
            if (file_exists('Safety_distinction.json')) {
                $localSafetyJson = @file_get_contents('Safety_distinction.json');
                if ($localSafetyJson !== false) {
                    $localSafety = json_decode($localSafetyJson, true);
                    if ($localSafety !== null && isset($localSafety['version'])) {
                        if ($localSafety['version'] !== $serverVer) {
                            // 版本不一致，创建安全锁
                            file_put_contents(__DIR__ . '/Safety_locked.lock', 'Locked due to version mismatch');
                            // 重新检查安全状态
                            checkSafetyStatus();
                        }
                    }
                }
            } else {
                // 本地文件不存在，创建安全锁
                file_put_contents(__DIR__ . '/Safety_locked.lock', 'Locked due to missing Safety_distinction.json');
                // 重新检查安全状态
                checkSafetyStatus();
            }
        }
    }
}

// 执行安全检查
checkSafetyStatus();

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

// 生成并存储退出登录token
if (!isset($_SESSION['logout_token'])) {
    $user = new User($conn);
    $logout_token = $user->generateLogoutToken($_SESSION['user_id']);
    $_SESSION['logout_token'] = $logout_token;
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

// 确保用户有 vkey（文件访问密钥）
$user_vkey = $current_user['vkey'] ?? null;
if (empty($user_vkey)) {
    // 自动生成 vkey
    $vkey = bin2hex(random_bytes(32));
    $stmt = $conn->prepare("UPDATE users SET vkey = ? WHERE id = ?");
    $stmt->execute([$vkey, $user_id]);
    $user_vkey = $vkey;
}

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
// 严格验证chat_type，只能是'friend'或'group'
$chat_type = isset($_GET['chat_type']) ? $_GET['chat_type'] : 'friend';
$chat_type = ($chat_type === 'friend' || $chat_type === 'group') ? $chat_type : 'friend';

// 严格验证selected_id，只能是数字
$selected_id = isset($_GET['id']) ? $_GET['id'] : null;
if ($selected_id !== null) {
    $selected_id = preg_replace('/[^0-9]/', '', $selected_id);
    $selected_id = $selected_id === '' ? null : $selected_id;
}
$selected_friend = null;
$selected_group = null;

// 初始化变量
$selected_friend_id = null;

// 如果有选中的聊天对象，获取详细信息
if ($selected_id) {
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
    <link rel="stylesheet" href="Modern-chat-video-player.css">
    <script src="Modern-chat-video-player.js"></script>
    <style>
        :root {
            --bg-url: url('<?php echo $bg_url; ?>');
            --access-display: <?php 
                if ($chat_type === 'friend') {
                    echo ($selected_friend_id && !$friend->isFriend($user_id, $selected_friend_id)) ? 'none' : 'block';
                } elseif ($chat_type === 'group') {
                    echo ($selected_id && !$group->isUserInGroup($selected_id, $user_id)) ? 'none' : 'block';
                } else {
                    echo 'block';
                }
            ?>;
        }
    </style>
    <link rel="stylesheet" href="./css/chat/chat.css">
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
        <div class="modal-content" style="width: 90%; max-width: 400px; background: var(--modal-bg); color: var(--text-color);">
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
        <div class="modal-content" style="width: 90%; max-width: 400px; background: var(--modal-bg); color: var(--text-color);">
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
        <div class="modal-content" style="width: 90%; max-width: 400px; background: var(--modal-bg); color: var(--text-color);">
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
        <div class="modal-content" style="width: 90%; max-width: 800px; height: 80vh; max-height: 800px; display: flex; flex-direction: column; background: var(--modal-bg); color: var(--text-color);">
            <div style="display: flex; justify-content: space-between; align-items: center; padding: 20px; border-bottom: 1px solid var(--border-color);">
                <h2 style="color: var(--text-color); font-size: 20px; font-weight: 600;">更多设置</h2>
                <button onclick="closeMoreSettingsModal()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: var(--text-secondary);">×</button>
            </div>
            <div class="more-settings-content" style="flex: 1; overflow-y: auto; padding: 20px;">
                <!-- 系统设置 -->
                <div style="padding: 20px; background: var(--panel-bg); border-radius: 8px; margin-bottom: 20px; transition: background-color 0.3s;">
                    <h3 style="color: var(--text-color); font-size: 16px; font-weight: 600; margin-bottom: 15px;">系统设置</h3>
                    <div style="display: flex; align-items: center; justify-content: space-between;">
                        <div>
                            <div style="font-size: 14px; font-weight: 500; color: var(--text-color);">清除文件缓存</div>
                            <div style="font-size: 12px; color: var(--text-secondary);">清除所有本地存储的文件数据，此操作不可恢复</div>
                        </div>
                        <button onclick="clearFileCache()" style="
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
                            <img src="<?php echo (strpos($current_user['avatar'], 'http') === 0) ? htmlspecialchars($current_user['avatar']) : htmlspecialchars(generate_file_url($current_user['avatar'], $user_vkey)); ?>" alt="用户头像" style="width: 32px; height: 32px; border-radius: 50%; object-fit: cover;">
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
        <div class="modal-content" style="width: 90%; max-width: 400px; background: var(--modal-bg); color: var(--text-color);">
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
        <div class="modal-content" style="width: 90%; max-width: 400px; background: var(--modal-bg); color: var(--text-color);">
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
        <div class="modal-content" style="width: 90%; max-width: 400px; background: var(--modal-bg); color: var(--text-color);">
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
        <div class="modal-content" style="width: 90%; max-width: 400px; background: var(--modal-bg); color: var(--text-color);">
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
        <div class="modal-content" style="width: 90%; max-width: 600px; background: var(--modal-bg); color: var(--text-color);">
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
        <div class="modal-content" style="width: 90%; max-width: 400px; background: var(--modal-bg); color: var(--text-color);">
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
                <div id="select-friends-container-modal" style="
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
                    
                    <!-- 视频元素，由 Modern-chat-video-player.js 动态创建控件 -->
                    <video id="custom-video-element" class="custom-video-element" controlsList="nodownload" data-modern-player data-skip-init="true"></video>
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
                <svg style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); width: 16px; height: 16px;" viewBox="0 0 24 24" fill="currentColor"><path d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.77l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
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
                <button id="create-group-tab" class="add-friend-tab" onclick="switchAddFriendTab('create-group')" style="flex: 1; padding: 12px; border: none; background: transparent; cursor: pointer; font-size: 14px; font-weight: 600; color: var(--text-secondary);">创建群聊</button>
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
            
            <!-- 创建群聊内容 -->
            <div id="create-group-content" class="add-friend-content" style="display: none;">
                <div style="margin-bottom: 20px;">
                    <label for="group-name-add" style="display: block; margin-bottom: 5px; color: var(--text-color); font-weight: 500;">群聊名称</label>
                    <input type="text" id="group-name-add" placeholder="请输入群聊名称" style="
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
                    <button onclick="closeAddFriendWindow()" style="
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
    </div>
    
    <!-- 左侧边栏图标 -->
    <script>
    // PHP 桥接配置 - 由 chat.php 自动生成
    window.CHAT_CONFIG = {
        userId: <?php echo $user_id; ?>,
        username: '<?php echo addslashes($username); ?>',
        vkey: '<?php echo addslashes($user_vkey); ?>',
        chatType: '<?php echo $chat_type; ?>',
        selectedId: '<?php echo $selected_id; ?>',
        logoutToken: '<?php echo $_SESSION['logout_token']; ?>',
        currentUserAvatar: '<?php echo !empty($current_user['avatar']) ? addslashes($current_user['avatar']) : ''; ?>',
        usernameShort: '<?php echo addslashes(substr($username, 0, 2)); ?>',
        isAdmin: <?php echo $is_admin ? 'true' : 'false'; ?>,
        isSpringFestivalPeriod: <?php echo $is_spring_festival_period ? 'true' : 'false'; ?>,
        isRadioPeriod: <?php echo $is_radio_period ? 'true' : 'false'; ?>,
        uploadFilesMax: <?php echo getConfig('upload_files_max', 150); ?>,
        banReason: '<?php echo isset($ban_info) && $ban_info ? addslashes($ban_info['reason']) : ''; ?>',
        banExpiresAt: '<?php echo isset($ban_info) && $ban_info && $ban_info['expires_at'] ? $ban_info['expires_at'] : ''; ?>',
        currentPhone: '<?php echo $current_user['phone'] ?? ''; ?>',
        userNameMax: <?php echo getConfig('user_name_max', 12); ?>,
        lunarConfig: <?php echo isset($lunar_config) ? json_encode($lunar_config) : 'null'; ?>,
        needSecurityQuestion: <?php echo isset($need_security_question) && $need_security_question ? 'true' : 'false'; ?>,
        selectedFriendAvatar: '<?php echo isset($selected_friend) && is_array($selected_friend) && isset($selected_friend['avatar']) ? ((strpos($selected_friend['avatar'], 'http') === 0) ? addslashes($selected_friend['avatar']) : addslashes(generate_file_url($selected_friend['avatar'], $user_vkey))) : ''; ?>',
        selectedFriendUsername: '<?php echo isset($selected_friend) && is_array($selected_friend) && isset($selected_friend['username']) ? addslashes($selected_friend['username']) : ''; ?>',
        selectedFriendUsernameShort: '<?php echo isset($selected_friend) && is_array($selected_friend) && isset($selected_friend['username']) ? addslashes(substr($selected_friend['username'], 0, 2)) : ''; ?>'
    };
    </script>

    <script src="./js/shared/file-helper.js"></script>
    <script src="./js/chat/chat.js"></script>
    <div class="sidebar-icons">
        <div class="footer-icon" title="主页" onclick="window.location.href='chat.php'"><svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg></div>
        <div class="footer-icon <?php echo $chat_type === 'friend' ? 'active' : ''; ?>" title="好友" onclick="switchChatType('friend')"><svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg></div>
        <div class="footer-icon <?php echo $chat_type === 'group' ? 'active' : ''; ?>" title="群聊" onclick="switchChatType('group')"><svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4zm6.406-11.845c-.783-.198-1.618.042-2.207.631l-1.49 1.49c-.54.54-.54 1.406 0 1.946l1.49 1.49c.589.589.829 1.426.631 2.207-.198.783-.042 1.618.631 2.207l1.49 1.49c.54.54 1.406.54 1.946 0l1.49-1.49c.589-.589.829-1.426.631-2.207.198-.783.042-1.618-.631-2.207l-1.49-1.49c-.54-.54-1.406-.54-1.946 0l-1.49-1.49c-.589-.589-.829-1.426-.631-2.207z"/></svg></div>
        <div class="footer-icon" title="搜索" onclick="document.getElementById('search-input').focus()"><svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.77l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg></div>
        <div class="footer-icon" title="添加好友" onclick="showAddFriendWindow()"><svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg></div>
        <div id="music-icon" class="footer-icon" title="音乐播放"><svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg></div>
        <div class="footer-icon" title="反馈" onclick="window.location.href='https://src.hyacine.com.cn/'"><svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7l-7-7z"/></svg></div>
        <div class="footer-icon" title="设置" onclick="openSettingsModal()"><svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M19.43 12.98c.04-.32.07-.64.07-.98s-.03-.66-.07-.98l2.11-1.65c.19-.15.24-.42.12-.64l-2-3.46c-.12-.22-.39-.3-.61-.22l-2.49 1c-.52-.39-1.08-.7-1.66-.94l-.38-2.65c-.03-.24-.24-.42-.48-.42h-4c-.24 0-.45.18-.48.42l-.38 2.65c-.58.24-1.14.55-1.66.94l-2.49-1c-.22-.08-.49 0-.61.22l-2 3.46c-.12.22-.07.49.12.64l2.11 1.65c-.04.32-.07.64-.07.98s.03.66.07.98l-2.11 1.65c-.19.15-.24.42-.12.64l2 3.46c.12.22.39.3.61.22l2.49-1c.52.39 1.08.7 1.66.94l.38 2.65c.03.24.24.42.48.42h4c.24 0 .45-.18.48-.42l.38-2.65c.58-.24 1.14-.55 1.66-.94l2.49 1c.22.08.49 0 .61-.22l2-3.46c.12-.22.07-.49-.12-.64l-2.11-1.65zm-7.43 2.52c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2z"/></svg></div>
    </div>

    <!-- 主聊天容器 -->
    <!-- 警告信息容器 -->
        <div id="warning-container" style="position: fixed; top: 20px; right: 20px; z-index: 9999; max-width: 300px;"></div>
        
        <div class="chat-container">
        <!-- 左侧边栏 -->
        <div class="sidebar">
            <!-- 顶部用户信息 -->
            <div class="sidebar-header">
                <div class="user-avatar">
                    <?php if (!empty($current_user['avatar'])): ?>
                        <img src="<?php echo (strpos($current_user['avatar'], 'http') === 0) ? htmlspecialchars($current_user['avatar']) : htmlspecialchars(generate_file_url($current_user['avatar'], $user_vkey)); ?>" alt="<?php echo $username; ?>" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
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
                <?php if ($chat_type === 'friend'): ?>
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
                                <img src="<?php echo (strpos($friend_item['avatar'], 'http') === 0) ? htmlspecialchars($friend_item['avatar']) : htmlspecialchars(generate_file_url($friend_item['avatar'], $user_vkey)); ?>" alt="<?php echo $friend_item['username']; ?>" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;" onerror="this.onerror=null; this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($friend_item['username']); ?>&background=random';">
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
                <?php endif; ?>
                
                <!-- 群聊列表 -->
                <?php if ($chat_type === 'group'): ?>
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
                            <svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
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
                                    // 获取当前用户在该群的角色
                                    $current_user_role = 'member';
                                    if ($group_item['owner_id'] == $user_id) {
                                        $current_user_role = 'Master';
                                    } else {
                                        // 检查是否是管理员
                                        $group_members = $group->getGroupMembers($group_item['id']);
                                        foreach ($group_members as $member) {
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
                                ?>
                                <?php if (($current_user_role == 'Master' || $current_user_role == 'admin') && $group_item['all_user_group'] != 1): ?>
                                    <button class="friend-menu-item" onclick="showJoinRequests(<?php echo $group_item['id']; ?>)">入群申请</button>
                                <?php endif; ?>
                                <?php if ($current_user_role == 'Master'): ?>
                                    <?php if ($group_item['all_user_group'] != 1): ?>
                                        <button class="friend-menu-item" onclick="transferGroupOwnership(<?php echo $group_item['id']; ?>)">转让群主</button>
                                    <?php endif; ?>
                                    <button class="friend-menu-item" onclick="deleteGroup(<?php echo $group_item['id']; ?>)">解散群聊</button>
                                <?php elseif ($group_item['all_user_group'] != 1): ?>
                                    <button class="friend-menu-item" onclick="leaveGroup(<?php echo $group_item['id']; ?>)">退出群聊</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php endif; ?>
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
                                <img src="<?php echo (strpos($selected_friend['avatar'], 'http') === 0) ? htmlspecialchars($selected_friend['avatar']) : htmlspecialchars(generate_file_url($selected_friend['avatar'], $user_vkey)); ?>" alt="<?php echo $selected_friend['username']; ?>" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
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
                            <svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
                        </div>
                        <div class="chat-header-info">
                            <div class="chat-header-name"><?php echo htmlspecialchars($selected_group['name']); ?></div>
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
                    <!-- 返回底部按钮 -->
                    <button class="scroll-to-bottom-btn" id="scroll-to-bottom-btn" onclick="scrollToBottom()" title="回到底部"><svg viewBox="0 0 24 24" width="20" height="20" fill="white"><path d="M7 10l5 5 5-5z"/></svg></button>
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
                                        $upload_id = isset($msg['upload_id']) ? $msg['upload_id'] : '';
                                        $file_url  = !empty($file_path) ? generate_file_url($file_path, $user_vkey) : '';
                                        
                                        // 检测文件的实际类型
                                        $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                                        $image_exts = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
                                        $audio_exts = ['mp3', 'wav', 'ogg', 'aac', 'wma', 'm4a', 'webm'];
                                        $video_exts = ['mp4', 'avi', 'mov', 'wmv', 'flv'];
                                        
                                        if (in_array($ext, $image_exts)) {
                                            // 图片类型
                                            echo "<div class='message-media'>";
                                            echo "<img src='".htmlspecialchars($file_url)."' alt='".htmlspecialchars($file_name)."' class='message-image' data-file-name='".htmlspecialchars($file_name)."' data-file-type='image' data-file-path='".htmlspecialchars($file_path)."' data-file-url='".htmlspecialchars($file_url)."' data-upload-id='".htmlspecialchars($upload_id)."' onerror=\"if(this.parentElement && document.body.contains(this)){this.style.display='none'; this.parentElement.insertAdjacentHTML('afterend', '<div class=&quot;file-cleaned-tip&quot;>文件已被清理</div>');}\">";

                                            echo "</div>";
                                        } elseif (in_array($ext, $audio_exts)) {
                                            // 音频类型
                                            echo "<div class='message-media' style='overflow: visible; box-shadow: none; background: transparent;'>";
                                            echo "<div class='custom-audio-player'>";
                                            echo "<audio src='".htmlspecialchars($file_url)."' class='audio-element rfc-remote-audio' data-file-name='".htmlspecialchars($file_name)."' data-file-type='audio' data-file-path='".htmlspecialchars($file_path)."' data-file-url='".htmlspecialchars($file_url)."' data-upload-id='".htmlspecialchars($upload_id)."' onerror=\"if(this.parentElement && document.body.contains(this)){this.parentElement.style.display='none'; this.parentElement.insertAdjacentHTML('afterend', '<div class=&quot;file-cleaned-tip&quot;>文件已被清理</div>');}\"></audio>";
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
                                            echo "<button class='media-action-btn' onclick='event.stopPropagation(); addDownloadTask({$js_file_name}, {$js_file_path}, ".htmlspecialchars($file_size).", \"audio\");' title='下载' style='width: 28px; height: 28px; font-size: 16px; background: rgba(0,0,0,0.1); border: none; border-radius: 50%; color: #666; cursor: pointer; margin-left: 10px; z-index: 4000; position: relative;'><svg viewBox='0 0 24 24' width='16' height='16' fill='currentColor'><path d='M7 10l5 5 5-5z'/></svg></button>";
                                            echo "</div>";
                                            echo "</div>";
                                        } elseif (in_array($ext, $video_exts)) {
                                            // 视频类型
                                            echo "<div class='message-media'>";
                                            echo "<div class='video-container' style='position: relative; width: 100%; max-width: 300px; height: 200px; background: #000; border-radius: 8px; display: flex; align-items: center; justify-content: center; cursor: pointer; overflow: hidden;'>";
                                            echo "<video src='".htmlspecialchars($file_url)."' class='video-element' data-file-name='".htmlspecialchars($file_name)."' data-file-type='video' data-file-path='".htmlspecialchars($file_path)."' data-file-url='".htmlspecialchars($file_url)."' data-upload-id='".htmlspecialchars($upload_id)."' controlsList='nodownload' data-modern-player style='display: none;' onerror=\"if(this.parentElement && document.body.contains(this)){this.parentElement.style.display='none'; this.parentElement.insertAdjacentHTML('afterend', '<div class=&quot;file-cleaned-tip&quot;>文件已被清理</div>');}\">";
                                            echo "</video>";

                                            echo "</div>";
                                            echo "</div>";
                                        } elseif (isset($msg['type']) && $msg['type'] == 'file') {
                                            // 其他文件类型
                                        ?>
                                            <div class="message-file" onclick="addDownloadTask(<?php echo htmlspecialchars(json_encode($file_name), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode($file_path), ENT_QUOTES); ?>, <?php echo $file_size; ?>, 'file')" style="position: relative; background: var(--message-sent-bg); border-radius: 8px; padding: 12px; display: flex; align-items: center; gap: 12px; cursor: pointer; max-width: 100%; box-sizing: border-box;">
                                                <div class="message-file-link" data-file-name="<?php echo htmlspecialchars($file_name); ?>" data-file-size="<?php echo $file_size; ?>" data-file-type="file" data-file-path="<?php echo htmlspecialchars($file_path); ?>" style="display: flex; align-items: center; gap: 12px; text-decoration: none; color: inherit; flex: 1;">
                                                    <svg class="file-icon" viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M10 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/></svg>
                                                    <div class="file-info" style="flex: 1;">
                                                        <h4 style="margin: 0; font-size: 14px; font-weight: 500; word-break: break-all;"><?php echo htmlspecialchars($file_name); ?></h4>
                                                        <p style="margin: 2px 0 0 0; font-size: 12px; color: var(--text-desc);"><?php echo round($file_size / 1024, 2); ?> KB</p>
                                                    </div>
                                                </div>
                                                <button onclick="event.stopPropagation(); addDownloadTask(<?php echo htmlspecialchars(json_encode($file_name), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode($file_path), ENT_QUOTES); ?>, <?php echo $file_size; ?>, 'file')" style="background: var(--primary-color); color: white; border: none; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 12px; transition: all 0.2s ease;">下载</button>
                                            </div>
                                        <?php 
                                        } else {
                                            // 文本消息，检测并转换链接
                                            $content = $msg['content'];
                                            // 进行HTML转义，确保HTML标签显示为纯文本
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
                                        <img src="<?php echo (strpos($current_user['avatar'], 'http') === 0) ? htmlspecialchars($current_user['avatar']) : htmlspecialchars(generate_file_url($current_user['avatar'], $user_vkey)); ?>" alt="<?php echo $username; ?>" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                                    <?php else: ?>
                                        <?php echo substr($username, 0, 2); ?>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <!-- 接收者的消息，头像在左，内容在右 -->
                                <div class="message-avatar">
                                    <?php if (isset($msg['avatar']) && !empty($msg['avatar'])): ?>
                                        <img src="<?php echo (strpos($msg['avatar'], 'http') === 0) ? htmlspecialchars($msg['avatar']) : htmlspecialchars(generate_file_url($msg['avatar'], $user_vkey)); ?>" alt="<?php echo $msg['sender_username']; ?>" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
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
                                        $upload_id = isset($msg['upload_id']) ? $msg['upload_id'] : '';
                                        $file_url  = !empty($file_path) ? generate_file_url($file_path, $user_vkey) : '';
                                        
                                        // 检测文件的实际类型
                                        $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                                        $image_exts = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
                                        $audio_exts = ['mp3', 'wav', 'ogg', 'aac', 'wma', 'm4a', 'webm'];
                                        $video_exts = ['mp4', 'avi', 'mov', 'wmv', 'flv'];
                                        
                                        if (in_array($ext, $image_exts)) {
                                            // 图片类型
                                            echo "<div class='message-media'>";
                                            echo "<img src='".htmlspecialchars($file_url)."' alt='".htmlspecialchars($file_name)."' class='message-image' data-file-name='".htmlspecialchars($file_name)."' data-file-type='image' data-file-path='".htmlspecialchars($file_path)."' data-file-url='".htmlspecialchars($file_url)."' data-upload-id='".htmlspecialchars($upload_id)."' onerror=\"if(this.parentElement && document.body.contains(this)){this.style.display='none'; this.parentElement.insertAdjacentHTML('afterend', '<div class=&quot;file-cleaned-tip&quot;>文件已被清理</div>');}\">";

                                            echo "</div>";
                                        } elseif (in_array($ext, $audio_exts)) {
                                            // 音频类型
                                            echo "<div class='message-media'>";
                                            echo "<div class='custom-audio-player'>";
                                            echo "<audio src='".htmlspecialchars($file_url)."' class='audio-element rfc-remote-audio' data-file-name='".htmlspecialchars($file_name)."' data-file-type='audio' data-file-path='".htmlspecialchars($file_path)."' data-file-url='".htmlspecialchars($file_url)."' data-upload-id='".htmlspecialchars($upload_id)."' onerror=\"if(this.parentElement && document.body.contains(this)){this.parentElement.style.display='none'; this.parentElement.insertAdjacentHTML('afterend', '<div class=&quot;file-cleaned-tip&quot;>文件已被清理</div>');}\"></audio>";
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
                                            echo "<button class='media-action-btn' onclick='event.stopPropagation(); addDownloadTask({$js_file_name}, {$js_file_path}, ".htmlspecialchars($file_size).", \"audio\");' title='下载' style='width: 28px; height: 28px; font-size: 16px; background: rgba(0,0,0,0.1); border: none; border-radius: 50%; color: #666; cursor: pointer; margin-left: 10px; z-index: 4000; position: relative;'><svg viewBox='0 0 24 24' width='16' height='16' fill='currentColor'><path d='M7 10l5 5 5-5z'/></svg></button>";
                                            echo "</div>";
                                            echo "</div>";
                                        } elseif (in_array($ext, $video_exts)) {
                                            // 视频类型
                                            echo "<div class='message-media'>";
                                            echo "<div class='video-container'>";
                                            echo "<video src='".htmlspecialchars($file_url)."' class='video-element' data-file-name='".htmlspecialchars($file_name)."' data-file-type='video' data-file-path='".htmlspecialchars($file_path)."' data-file-url='".htmlspecialchars($file_url)."' data-upload-id='".htmlspecialchars($upload_id)."' controlsList='nodownload' data-modern-player style='display: none;'>";
                                            echo "</video>";

                                            echo "</div>";
                                            echo "</div>";
                                        } elseif (isset($msg['type']) && $msg['type'] == 'file') {
                                            // 其他文件类型
                                        ?>
                                            <div class="message-file" onclick="addDownloadTask(<?php echo htmlspecialchars(json_encode($file_name), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode($file_path), ENT_QUOTES); ?>, <?php echo $file_size; ?>, 'file')" style="position: relative; background: var(--message-received-bg); border-radius: 8px; padding: 12px; display: flex; align-items: center; gap: 12px; cursor: pointer; max-width: 100%; box-sizing: border-box;">
                                                <div class="message-file-link" data-file-name="<?php echo htmlspecialchars($file_name); ?>" data-file-size="<?php echo $file_size; ?>" data-file-type="file" data-file-path="<?php echo htmlspecialchars($file_path); ?>" style="display: flex; align-items: center; gap: 12px; text-decoration: none; color: inherit; flex: 1;">
                                                    <svg class="file-icon" viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M10 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/></svg>
                                                    <div class="file-info" style="flex: 1;">
                                                        <h4 style="margin: 0; font-size: 14px; font-weight: 500; word-break: break-all;"><?php echo htmlspecialchars($file_name); ?></h4>
                                                        <p style="margin: 2px 0 0 0; font-size: 12px; color: var(--text-desc);"><?php echo round($file_size / 1024, 2); ?> KB</p>
                                                    </div>
                                                </div>
                                                <button onclick="event.stopPropagation(); addDownloadTask(<?php echo htmlspecialchars(json_encode($file_name), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode($file_path), ENT_QUOTES); ?>, <?php echo $file_size; ?>, 'file')" style="background: var(--primary-color); color: white; border: none; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 12px; transition: all 0.2s ease;">下载</button>
                                            </div>
                                        <?php 
                                        } else {
                                            // 文本消息，检测并转换链接
                                            $content = $msg['content'];
                                            // 进行HTML转义，确保HTML标签显示为纯文本
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
                        background: var(--modal-bg);
                        border-radius: 8px;
                        box-shadow: 0 4px 12px var(--shadow-color);
                        max-height: 200px;
                        overflow-y: auto;
                        z-index: 1000;
                        min-width: 200px;
                        border: 1px solid var(--border-color);
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
                        color: var(--text-color);
                    }
                    
                    .mention-item:hover {
                        background-color: var(--hover-bg);
                    }
                    
                    .mention-item.active {
                        background-color: rgba(102, 126, 234, 0.1);
                    }
                    
                    .mention-avatar {
                        width: 32px;
                        height: 32px;
                        border-radius: 50%;
                        background: linear-gradient(135deg, var(--primary-color) 0%, #0095ff 100%);
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
                        color: var(--text-color);
                    }
                    
                    .mention-nickname {
                        font-size: 12px;
                        color: var(--text-desc);
                    }
                    
                    .mention-all {
                        color: var(--danger-color);
                        font-weight: 600;
                    }
                    
                    .message-text .mention {
                        color: var(--primary-color);
                        font-weight: 600;
                    }
                    
                    .mention-badge {
                        background: var(--danger-color);
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
                <div class="messages-container" style="justify-content: center; align-items: center; color: var(--text-color); font-size: 16px; display: flex;">
                    请选择一个聊天对象
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- 初始聊天记录数据 -->

    <!-- 音乐播放器 -->
    <?php if (getConfig('Random_song', false)): ?>

    
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
                <button class="control-btn" id="prev-btn" onclick="playPrevious()" title="上一首"><svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M6 6h2v12H6zm3.5 6l8.5 6V6z"/></svg></button>
                <button class="control-btn" id="play-btn" onclick="togglePlay()" title="播放/暂停"><svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M8 5v14l11-7z"/></svg></button>
                <button class="control-btn" id="next-btn" onclick="playNext()" title="下一首"><svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M6 18l8.5-6L6 6v12zM16 6v12h2V6h-2z"/></svg></button>
                <div id="volume-container">
                    <button class="control-btn" id="volume-btn" onclick="toggleVolumeControl()" title="音量"><svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-3.97zm-2.5-.75c.94 0 1.7-.76 1.7-1.7s-.76-1.7-1.7-1.7-1.7.76-1.7 1.7.76 1.7 1.7 1.7z"/></svg></button>
                    <!-- 新的音量调节UI -->
                    <div id="volume-control" style="display: none;">
                        <button class="volume-btn" id="volume-up" onclick="adjustVolumeByStep(0.1)" title="增大音量">+</button>
                        <div id="vertical-volume-slider" onclick="adjustVolume(event)">
                            <div id="vertical-volume-level"></div>
                        </div>
                        <button class="volume-btn" id="volume-down" onclick="adjustVolumeByStep(-0.1)" title="减小音量">-</button>
                    </div>
                </div>
                <button class="control-btn" id="download-btn" onclick="downloadMusic()" title="下载"><svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M7 10l5 5 5-5z"/></svg></button>
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
                <div class="exclamation-icon" id="modalIcon"><svg viewBox="0 0 24 24" width="48" height="48" fill="#ff9800"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/></svg></div>
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



</body>
</html>