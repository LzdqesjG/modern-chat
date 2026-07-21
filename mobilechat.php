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
            <link rel="stylesheet" href="./css/chat/mobilechat.css">
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
    // 确保user_id从会话中正确获取
    $session_user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
    if ($session_user_id) {
        $group->ensureAllUserGroups($session_user_id);
    }
}

// 确保user_id和username从会话中正确获取
$user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
$username = isset($_SESSION['username']) ? $_SESSION['username'] : null;

// 如果用户未登录，重定向到登录页面
if (!$user_id) {
    header('Location: login.php');
    exit;
}

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
    $vkey = bin2hex(random_bytes(32));
    $stmt = $conn->prepare("UPDATE users SET vkey = ? WHERE id = ?");
    $stmt->execute([$vkey, $user_id]);
    $user_vkey = $vkey;
}

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

// 预计算 MOBILE_CHAT_CONFIG JS 桥接值
$mobile_ban_reason = $ban_info ? addslashes($ban_info['reason']) : '';
$mobile_ban_expires_at = $ban_info ? ($ban_info['expires_at'] ? $ban_info['expires_at'] : '永久') : '';
$mobile_need_security = $need_security_question ? 'true' : 'false';
$mobile_current_phone = $current_user['phone'] ?? '';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>手机端 - Modern Chat</title>
    <link rel="stylesheet" href="./css/chat/mobilechat.css">
    <link rel="icon" href="aconvert.ico" type="image/x-icon"></head>
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
    
    <!-- 警告容器 -->
    <div id="warning-container" style="
        position: fixed;
        top: 80px;
        right: 20px;
        z-index: 10001;
        max-width: 300px;
    "></div>
    
    <style>
        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        @keyframes slideOut {
            from {
                transform: translateX(0);
                opacity: 1;
            }
            to {
                transform: translateX(100%);
                opacity: 0;
            }
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
                
                <!-- 设置项：3D环绕音效 -->
                <div class="setting-item" style="display: flex; justify-content: space-between; align-items: center; padding: 15px 0; border-bottom: 1px solid var(--border-color);">
                    <div>
                        <div style="font-size: 14px; font-weight: 600; color: var(--text-color);">3D环绕音效</div>
                        <div style="font-size: 12px; color: var(--text-secondary); margin-top: 2px;">启用音频的3D环绕效果</div>
                    </div>
                    <label class="switch">
                        <input type="checkbox" id="setting-3d-audio">
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
                            <img src="<?php echo (strpos($current_user['avatar'], 'http') === 0) ? htmlspecialchars($current_user['avatar']) : htmlspecialchars(generate_file_url($current_user['avatar'], $user_vkey)); ?>" alt="用户头像" style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover;">
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
        <div class="modal-content" style="width: 90%; max-width: 400px;">
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
                                <img src="<?php echo (strpos($current_user['avatar'], 'http') === 0) ? htmlspecialchars($current_user['avatar']) : htmlspecialchars(generate_file_url($current_user['avatar'], $user_vkey)); ?>" alt="当前头像" style="width: 100%; height: 100%; object-fit: cover;">
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
        <div class="modal-content" style="width: 90%; max-width: 400px;">
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
        <div class="modal-content" style="width: 90%; max-width: 400px;">
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
        <div class="modal-content" style="width: 90%; max-width: 400px;">
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
        <div class="modal-content" style="width: 90%; max-width: 350px;">
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
                                <button class="video-control-btn" id="video-play-btn" title="播放/暂停"><svg viewBox="0 0 24 24" width="20" height="20" fill="white"><path d="M8 5v14l11-7z"/></svg></button>

                                <button class="video-control-btn" id="video-fullscreen-btn" title="放大/缩小" onclick="toggleVideoFullscreen()"><svg viewBox="0 0 24 24" width="20" height="20" fill="white"><path d="M7 14H5v5h5v-2H7v-3zm-2-4h2V7h3V5H5v5zm12 7h-3v2h5v-5h-2v3zM14 5v2h3v3h2V5h-5z"/></svg></button>
                            </div>
                            <div class="video-volume-control">
                                <button class="video-control-btn" id="video-mute-btn" title="静音"><svg viewBox="0 0 24 24" width="20" height="20" fill="white"><path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-3.97zm-2.5-.75c.94 0 1.7-.76 1.7-1.7s-.76-1.7-1.7-1.7-1.7.76-1.7 1.7.76 1.7 1.7 1.7z"/></svg></button>
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
                        window.location.href = 'mobilechat.php';
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
                        window.location.href = 'mobilechat.php';
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
                <div class="modal-content" style="background: var(--modal-bg); color: var(--text-color); width: 90%; max-width: 400px; border-radius: 12px; overflow: hidden; display: flex; flex-direction: column; max-height: 80vh;">
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
                                // 排除自己（使用data.current_user_id来判断）
                                if (member.id !== data.current_user_id) {
                                    hasCandidates = true;
                                    const avatar = member.avatar && member.avatar !== 'default_avatar.png' 
                                        ? `<img src="${member.avatar}" style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover;">`
                                        : `<div style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; display: flex; align-items: center; justify-content: center; font-weight: bold;">${member.username.substring(0, 2)}</div>`;
                                        
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
                            <button class="chat-item-menu-btn" onclick="toggleGroupMenu(event, <?php echo $group_item['id']; ?>, '<?php echo htmlspecialchars($group_item['name'], ENT_QUOTES); ?>')">
                                ⋮
                            </button>
                            <!-- 群聊菜单 -->
                            <div class="friend-menu" id="group-menu-<?php echo $group_item['id']; ?>" style="display: none; position: absolute; top: 100%; right: 0; background: var(--modal-bg); border-radius: 8px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.3); z-index: 99999; min-width: 150px; margin-top: 5px; border: 1px solid var(--border-color);">
                                <button class="friend-menu-item" onclick="showGroupMembers(<?php echo $group_item['id']; ?>)" style="color: var(--text-color);">查看成员</button>
                                <button class="friend-menu-item" onclick="inviteFriendsToGroup(<?php echo $group_item['id']; ?>)" style="color: var(--text-color);">邀请好友</button>
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
                    <?php elseif ($chat_type === 'group' && $selected_group): ?>
                        <div class="chat-avatar group" style="margin-right: 12px;">
                            <svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
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
                                            echo "<img src='".htmlspecialchars($file_url)."' alt='".htmlspecialchars($file_name)."' class='message-image' data-file-name='".htmlspecialchars($file_name)."' data-file-type='image' data-file-path='".htmlspecialchars($file_path)."' data-file-url='".htmlspecialchars($file_url)."' data-upload-id='".htmlspecialchars($upload_id)."'>";
                                            echo "</div>";
                                        } elseif (in_array($ext, $audio_exts)) {
                                            // 音频类型 - 作为普通文件显示
                                            echo "<div class='message-media'>";
                                            echo "<div class='message-file'>";
                                            echo "<svg class='file-icon' viewBox='0 0 24 24' width='24' height='24' fill='currentColor'><path d='M8 5v14l11-7z'/></svg>";
                                            echo "<div class='file-info' style='flex: 1;'>";
                                            echo "<h4 style='margin: 0; font-size: 14px; font-weight: 500;'>".htmlspecialchars($file_name)."</h4>";
                                            echo "<p style='margin: 2px 0 0 0; font-size: 12px; color: #666;'>".round($file_size / 1024, 2)." KB</p>";
                                            echo "</div>";
                                            echo "<button style='background: #667eea; color: white; border: none; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 12px; transition: all 0.2s ease;' onclick='addDownloadTask(\"".htmlspecialchars($file_name)."\", \"".htmlspecialchars($file_path)."\", ".$file_size.", \"audio\")'>下载</button>";
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
                                                    <svg class="file-icon" viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M10 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/></svg>
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
                                            // 进行HTML转义，确保HTML标签被显示而不是执行
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
                                            echo "<img src='".htmlspecialchars($file_url)."' alt='".htmlspecialchars($file_name)."' class='message-image' data-file-name='".htmlspecialchars($file_name)."' data-file-type='image' data-file-path='".htmlspecialchars($file_path)."' data-file-url='".htmlspecialchars($file_url)."' data-upload-id='".htmlspecialchars($upload_id)."'>";
                                            echo "</div>";
                                        } elseif (in_array($ext, $audio_exts)) {
                                            // 音频类型
                                            echo "<div class='message-media'>";
                                            echo "<div class='custom-audio-player'>";
                                            echo "<audio src='".htmlspecialchars($file_url)."' class='audio-element rfc-remote-audio' data-file-name='{$file_name}' data-file-type='audio' data-file-path='{$file_path}' data-file-url='".htmlspecialchars($file_url)."' data-upload-id='".htmlspecialchars($upload_id)."'></audio>";
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
                                                    <svg class="file-icon" viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M10 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/></svg>
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
                                            // 进行HTML转义，确保HTML标签被显示而不是执行
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
            <div id="scan-hint" style="color: white; text-align: center; margin-top: 20px; font-size: 16px;">请将二维码对准相机<br><small style="font-size: 12px; opacity: 0.8;">如果二维码背景为黑色，建议开启手机颜色反转功能</small></div>
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
            <svg viewBox="0 0 24 24" width="48" height="48" fill="#4caf50" style="margin-bottom: 15px;"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
            <h3 style="margin-bottom: 10px; color: #333;">登录成功</h3>
            <p style="margin-bottom: 20px; color: #666; font-size: 14px;">已成功在PC端登录</p>
            <button onclick="closeSuccessModal()" style="padding: 10px 20px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 500;">确定</button>
        </div>
    </div>
    
    <!-- 初始聊天记录数据 -->
    <script>
        window.MOBILE_CHAT_CONFIG = {
            userId: '<?php echo $user_id; ?>',
            username: '<?php echo $username; ?>',
            vkey: '<?php echo $user_vkey; ?>',
            chatType: '<?php echo $chat_type; ?>',
            selectedId: '<?php echo $selected_id; ?>',
            userAvatar: '<?php echo !empty($current_user['avatar']) ? ((strpos($current_user['avatar'], 'http') === 0) ? $current_user['avatar'] : generate_file_url($current_user['avatar'], $user_vkey)) : ''; ?>',
            usernameShort: '<?php echo substr($username, 0, 2); ?>',
            userNameMax: <?php echo getConfig('user_name_max', 12); ?>,
            uploadMaxConfig: <?php echo getConfig('upload_files_max', 150); ?>,
            hasAvatar: <?php echo !empty($current_user['avatar']) ? 'true' : 'false'; ?>,
            selectedFriendAvatar: '<?php echo (isset($selected_friend) && is_array($selected_friend) && isset($selected_friend["avatar"]) && !empty($selected_friend["avatar"])) ? ((strpos($selected_friend["avatar"], 'http') === 0) ? $selected_friend["avatar"] : generate_file_url($selected_friend["avatar"], $user_vkey)) : ""; ?>',
            selectedFriendUsername: '<?php echo (isset($selected_friend) && is_array($selected_friend) && isset($selected_friend["username"])) ? $selected_friend["username"] : ""; ?>',
            selectedFriendUsernameShort: '<?php echo (isset($selected_friend) && is_array($selected_friend) && isset($selected_friend["username"])) ? substr($selected_friend["username"], 0, 2) : "群"; ?>',
            banReason: '<?php echo $mobile_ban_reason; ?>',
            banExpiresAt: '<?php echo $mobile_ban_expires_at; ?>',
            needSecurityQuestion: <?php echo $mobile_need_security; ?>,
            currentPhone: '<?php echo $mobile_current_phone; ?>'
        };
        document.documentElement.style.setProperty('--mobile-item-display', '<?php echo ($chat_type === "friend" && $selected_friend_id && !$friend->isFriend($user_id, $selected_friend_id)) ? "none" : (($chat_type === "group" && $selected_id && !$group->isUserInGroup($selected_id, $user_id)) ? "none" : "block"); ?>');
    </script>
    <script src="./js/shared/file-helper.js"></script>
    <script src="./js/chat/mobilechat.js"></script>
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
            
            // 确保URL使用HTTPS
            let audioUrl = song.url;
            if (audioUrl && audioUrl.startsWith('http://')) {
                audioUrl = audioUrl.replace('http://', 'https://');
            }
            
            let picUrl = song.cover;
            if (picUrl && picUrl.startsWith('http://')) {
                picUrl = picUrl.replace('http://', 'https://');
            }
            
            currentSong = {
                name: song.title,
                artistsname: song.artist,
                url: audioUrl,
                picurl: picUrl
            };
            
            // 更新UI
            document.getElementById('song-title').textContent = `${song.name} - ${song.artistsname}`;
            document.getElementById('artist-name').textContent = song.artistsname;
            const progressInfo = document.getElementById('progress-song-info');
            if(progressInfo) progressInfo.textContent = `${song.name} - ${song.artistsname}`;
            
            const albumImage = document.getElementById('album-image');
            if (picUrl && picUrl !== 'assets/default_music_cover.png') {
                 albumImage.src = picUrl;
            } else {
                // 默认图
                albumImage.src = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxMDAiIGhlaWdodD0iMTAwIiB2aWV3Qm94PSIwIDAgMTAwIDEwMCI+PHJlY3Qgd2lkdGg9IjEwMCIgaGVpZ2h0PSIxMDAiIGZpbGw9IiNkZGQiLz48dGV4dD48L3N2Zz4=';
            }
            albumImage.style.display = 'block';
            
            // 播放
            const audioPlayer = document.getElementById('audio-player');
            audioPlayer.src = currentSong.url;
            audioPlayer.play().then(() => {
                isPlaying = true;
                const playBtn = document.getElementById('play-btn');
                if(playBtn) playBtn.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>';
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
                    musicIcon.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M8 5v14l11-7z"/></svg><span style="color: red; font-size: 12px; position: absolute; top: 5px; right: 5px;">✕</span>';
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
                document.getElementById('play-btn').innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>';
                document.getElementById('player-status').textContent = '正在播放';
                return true;
            } catch (playError) {
                console.error('自动播放失败:', playError);
                isPlaying = false;
                document.getElementById('play-btn').innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>';
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
                        document.getElementById('play-btn').innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>';
                        document.getElementById('player-status').textContent = '正在播放';
                    } catch (playError) {
                        console.error('自动播放失败:', playError);
                        isPlaying = false;
                        document.getElementById('play-btn').innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>';
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
                    playBtn.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>';
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
                    playBtn.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>';
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
                            playBtn.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>';
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
                    musicIcon.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M8 5v14l11-7z"/></svg><span style="color: red; font-size: 12px; position: absolute; top: 5px; right: 5px;">✕</span>';
                    musicIcon.style.position = 'relative';
                }
            } else {
                // 显示播放器
                player.style.display = 'block';
                player.style.zIndex = '9999'; // 确保播放器显示在最顶层
                // 更新音乐图标为正常状态
                if (musicIcon) {
                    musicIcon.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>';
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
                modalIcon.innerHTML = '<svg viewBox="0 0 24 24" width="48" height="48" fill="#ff9800"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/></svg>';
            } else if (type === 'error') {
                modalIcon.innerHTML = '<svg viewBox="0 0 24 24" width="48" height="48" fill="#f44336"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/></svg>';
            } else if (type === 'success') {
                modalIcon.innerHTML = '<svg viewBox="0 0 24 24" width="48" height="48" fill="#4caf50"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>';
            } else {
                modalIcon.innerHTML = '<svg viewBox="0 0 24 24" width="48" height="48" fill="#2196f3"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/></svg>';
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
            if (MOBILE_CHAT_CONFIG.banReason) {
                showBanNotification(
                    MOBILE_CHAT_CONFIG.banReason,
                    MOBILE_CHAT_CONFIG.banExpiresAt
                );
            }
            
            // 检查用户是否需要设置密保
            if (MOBILE_CHAT_CONFIG.needSecurityQuestion) {
                // 强制显示密保设置弹窗，阻止进入其他内容
                document.getElementById('security-question-modal').style.display = 'flex';
                document.getElementById('security-question-close').style.display = 'none';
            }
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
            const currentPhone = MOBILE_CHAT_CONFIG.currentPhone;
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
                    captchaId: '55574dfff9c40f2efeb5a26d6d188245',
                    https: true
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