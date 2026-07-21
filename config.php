<?php
if (basename($_SERVER['SCRIPT_NAME'] ?? '') === basename(__FILE__)) {
    http_response_code(404);
    exit;
}

// 启用会话，必须在任何输出之前调用
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 读取.env文件（如果存在）
$env_file = __DIR__ . '/.env';
if (file_exists($env_file)) {
    $lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // 跳过注释行
        if (str_starts_with(trim($line), '#')) {
            continue;
        }
        
        // 解析键值对
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        
        // 移除引号（如果有）
        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            $value = substr($value, 1, -1);
        }
        
        // 设置环境变量（如果putenv函数可用）
        if (function_exists('putenv')) {
            putenv("$key=$value");
        }
    }
}

// 获取环境变量的辅助函数
function getEnvVar($key, $default = '') {
    // 尝试从超级全局变量获取
    if (isset($_SERVER[$key])) {
        return $_SERVER[$key];
    }
    
    // 尝试从超级全局变量获取（小写形式）
    if (isset($_SERVER[strtolower($key)])) {
        return $_SERVER[strtolower($key)];
    }
    
    // 尝试从超级全局变量获取（下划线转换为点）
    $dot_key = str_replace('_', '.', strtolower($key));
    if (isset($_SERVER[$dot_key])) {
        return $_SERVER[$dot_key];
    }
    
    // 如果getenv函数可用，尝试使用getenv获取
    if (function_exists('getenv')) {
        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }
    }
    
    // 尝试从.env文件中读取（如果存在）
    static $env_vars = null;
    if ($env_vars === null) {
        $env_file = __DIR__ . '/.env';
        $env_vars = [];
        if (file_exists($env_file)) {
            $lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (str_starts_with(trim($line), '#')) {
                    continue;
                }
                list($env_key, $env_value) = explode('=', $line, 2);
                $env_key = trim($env_key);
                $env_value = trim($env_value);
                if ((str_starts_with($env_value, '"') && str_ends_with($env_value, '"')) || (str_starts_with($env_value, "'") && str_ends_with($env_value, "'"))) {
                    $env_value = substr($env_value, 1, -1);
                }
                $env_vars[$env_key] = $env_value;
            }
        }
    }
    
    if (isset($env_vars[$key])) {
        return $env_vars[$key];
    }
    
    return $default;
}

// 错误报告配置
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', 'error.log');

// 设置时区
date_default_timezone_set('Asia/Shanghai');

/**
 * 读取配置文件
 * @param string $key 配置项键名
 * @param mixed $default 默认值
 * @return mixed 配置值
 */
function getConfig($key = null, $default = null) {
    $config_path = __DIR__ . '/config/config.json';
    static $config = null;
    
    // 只读取一次配置文件
    if ($config === null) {
        // 检查配置文件是否存在
        if (!file_exists($config_path)) {
            $config = [];
        } else {
            // 读取配置文件
            $config_content = file_get_contents($config_path);
            // 解析配置文件
            $config = json_decode($config_content, true);
            
            // 处理解析错误
            if (json_last_error() !== JSON_ERROR_NONE) {
                $config = [];
            }
        }
    }
    
    // 如果没有指定键名，返回所有配置
    if ($key === null) {
        return $config;
    }
    
    // 返回指定键名的配置值，如果不存在则返回默认值
    return isset($config[$key]) ? $config[$key] : $default;
}

/**
 * 获取用户名最大长度
 * @return int 用户名最大长度
 */
function getUserNameMaxLength() {
    return getConfig('user_name_max', 12);
}

// IP地址获取函数
function getUserIP() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    } else {
        return $_SERVER['REMOTE_ADDR'];
    }
}

// 数据库配置
define('DB_HOST', getEnvVar('DB_HOST') ?: getEnvVar('DB_HOSTNAME') ?: 'localhost');
define('DB_NAME', getEnvVar('DB_NAME') ?: getEnvVar('DATABASE_NAME') ?: 'chat');
define('DB_USER', getEnvVar('DB_USER') ?: getEnvVar('DB_USERNAME') ?: 'root');
define('DB_PASS', getEnvVar('DB_PASS') ?: getEnvVar('DB_PASSWORD') ?: getEnvVar('MYSQL_ROOT_PASSWORD') ?: getConfig('db_password') ?: 'iYTdbpki8Eb4mytB');

// 应用配置
define('APP_NAME', 'Modern Chat');
define('APP_URL', 'https://chat.hyacine.com.cn/chat');

// ═══════════════════════════════════════════════════════
// API安全检查 - 只允许指定API入口对外开放
// ═══════════════════════════════════════════════════════
define('API_ACCESS_KEY', hash('sha256', APP_NAME . DB_PASS . 'api_access_key'));

function check_api_access() {
    $current_file = basename($_SERVER['SCRIPT_NAME'] ?? '');
    
    $allowed_public = [
        'api.php', 'api-pc.php', 'music-api.php'
    ];
    
    if (in_array($current_file, $allowed_public)) {
        return true;
    }
    
    $frontend_files = [
        'chat.php', 'mobilechat.php', 'oldchat.php', 'login.php', 'register.php', 
        'admin.php', 'edit_profile.php', 'help.php', 'scan_login.php', 'logout.php',
        'forgetpassword.php', 'verify_email.php'
    ];
    
    if (in_array($current_file, $frontend_files)) {
        return true;
    }
    
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    $base_url = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '');
    
    if (!empty($referer) && strpos($referer, $base_url) === 0) {
        return true;
    }
    
    if (isset($_SERVER['HTTP_X_API_ACCESS_KEY']) && 
        hash_equals(API_ACCESS_KEY, $_SERVER['HTTP_X_API_ACCESS_KEY'])) {
        return true;
    }
    
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => '禁止直接访问']);
    exit;
}

// 安全配置
define('HASH_ALGO', PASSWORD_DEFAULT);
define('HASH_COST', 12);

// 登录安全配置
define('MAX_LOGIN_ATTEMPTS', getConfig('Number_of_incorrect_password_attempts', 10));
define('DEFAULT_BAN_DURATION', getConfig('Limit_login_duration', 24) * 3600); // 默认24小时，转换为秒

// 上传配置
define('UPLOAD_DIR', 'uploads/');
define('MAX_FILE_SIZE', getConfig('upload_files_max', 150) * 1024 * 1024); // 从config.json读取，默认150MB

define('ALLOWED_FILE_TYPES', [
    'image/jpeg', 'image/png', 'image/gif', 'image/webp',
    'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'text/plain', 'text/csv',
    'video/mp4', 'video/webm', 'video/ogg',
    'audio/mpeg', 'audio/wav', 'audio/ogg'
]);

// 会话配置，从config.json读取，默认1小时
define('SESSION_TIMEOUT', getConfig('Session_Duration', 1) * 3600); // 转换为秒

// 会话超时检查
if (isset($_SESSION['last_activity']) && isset($_SESSION['user_id'])) {
    // 计算会话持续时间（秒）
    $session_duration = time() - $_SESSION['last_activity'];
    
    // 如果会话持续时间超过配置的超时时间，销毁会话并跳转到登录页面
    if ($session_duration > SESSION_TIMEOUT) {
        session_unset();
        session_destroy();
        // 跳转到登录页面，但仅当不是API请求时
        $current_file = basename($_SERVER['PHP_SELF']);
        $api_files = [
            'get_new_messages.php', 'get_group_members.php', 'mark_messages_read.php', 'get_new_group_messages.php', 
            'send_message.php', 'add_group_members.php', 'create_group.php', 'delete_friend.php', 
            'delete_group.php', 'get_available_friends.php', 'get_ban_records.php', 'leave_group.php', 
            'remove_group_member.php', 'send_friend_request.php', 'set_group_admin.php', 'transfer_ownership.php',
            'get_group_invitations.php', 'accept_group_invitation.php', 'reject_group_invitation.php',
            'send_join_request.php', 'get_join_requests.php', 'approve_join_request.php', 'reject_join_request.php',
            'recall_message.php'
        ];
        if (!in_array($current_file, $api_files)) {
            header('Location: login.php?error=' . urlencode('会话已过期，请重新登录'));
            exit;
        }
    } else {
        // 更新最后活动时间
        $_SESSION['last_activity'] = time();
    }
}

// ═══════════════════════════════════════════════════════
// 文件访问 Token 系统（供 files.modern-chat.top/list.php 及相关脚本使用）
// ═══════════════════════════════════════════════════════
$file_token_secret = getEnvVar('FILE_TOKEN_SECRET');
if (empty($file_token_secret)) {
    $file_token_secret = hash('sha256', APP_NAME . DB_PASS . 'file_get_v2_2026');
}
define('FILE_TOKEN_SECRET', $file_token_secret);
define('FILE_TOKEN_TTL', 300);           // Token 有效期（秒）
define('FILE_RATE_LIMIT_MAX', 100);      // 每窗口最大请求数
define('FILE_RATE_LIMIT_WINDOW', 5);     // 窗口大小（秒）

// ═══════════════════════════════════════════════════════
// 上传 Token 系统（供 files.modern-chat.top/uploads.php 使用）
// ═══════════════════════════════════════════════════════
$upload_token_secret = getEnvVar('UPLOAD_TOKEN_SECRET');
if (empty($upload_token_secret)) {
    $upload_token_secret = hash('sha256', APP_NAME . DB_PASS . 'upload_v2_2026');
}
define('UPLOAD_TOKEN_SECRET', $upload_token_secret);
define('UPLOAD_TOKEN_TTL', 300);  // Token 有效期（秒）

/**
 * 生成 files.modern-chat.top/uploads.php 的上传认证参数
 *
 * 返回一个关联数组，包含上传 uploads.php 所需的全部认证字段：
 *   user, vkey, time, token, id
 *
 * @param int    $user_id  用户 ID
 * @param string $vkey     用户 vkey
 * @param string $id       上传会话 ID（由调用方生成，如 uniqid().'_'.time()）
 * @return array 认证参数字典
 */
function generate_upload_token(int $user_id, string $vkey, string $id): array {
    $ts   = time();
    $hmac = hash_hmac('sha256', "{$user_id}|{$vkey}|{$id}|{$ts}", UPLOAD_TOKEN_SECRET);
    $token = base64_encode("{$ts}:{$hmac}");
    return [
        'user'  => (string) $user_id,
        'vkey'  => $vkey,
        'time'  => (string) $ts,
        'token' => $token,
        'id'    => $id,
    ];
}

/**
 * 生成 files.modern-chat.top/list.php 的签名访问 URL
 *
 * file_path 采用 {upload_id}/{stored_name} 格式（由 FileUpload 返回并存入 messages.file_path），
 * 函数自动拆分 / 为 id + file 参数。
 *
 * @param string $file 文件相对路径（{upload_id}/{stored_name} 或纯文件名回退）
 * @param string $vkey 用户 vkey
 * @return string 完整签名 URL
 */
function generate_file_url(string $file, string $vkey): string {
    if (strpos($file, 'uploads/avatars/') === 0) {
        return '/' . $file;
    }
    
    if (strpos($file, 'avatars/') === 0) {
        return '/' . $file;
    }

    $ts = time();

    $slashPos = strpos($file, '/');
    if ($slashPos !== false) {
        $uid        = substr($file, 0, $slashPos);
        $storedName = substr($file, $slashPos + 1);
        $hmac  = hash_hmac('sha256', "{$uid}|{$storedName}|{$vkey}|{$ts}", FILE_TOKEN_SECRET);
        $token = base64_encode("{$ts}:{$hmac}");
        return 'https://files.modern-chat.top/list.php'
             . '?id='    . urlencode($uid)
             . '&file='  . urlencode($storedName)
             . '&vkey='  . urlencode($vkey)
             . '&token=' . urlencode($token);
    }

    $hmac  = hash_hmac('sha256', "{$file}|{$vkey}|{$ts}", FILE_TOKEN_SECRET);
    $token = base64_encode("{$ts}:{$hmac}");
    return 'https://files.modern-chat.top/list.php?file=' . urlencode($file)
         . '&vkey='  . urlencode($vkey)
         . '&token=' . urlencode($token);
}

/**
 * 生成同域 proxy_file.php 的代理访问 URL（解决 CORS 跨域问题）
 *
 * 与 generate_file_url() 的区别：
 *   - 该函数返回同域 proxy_file.php 的 URL，前端 JS fetch 时不会触发 CORS
 *   - generate_file_url() 返回 files.modern-chat.top 的直接 URL，仅用于 302 重定向（img/video/audio）
 *
 * @param string $file 文件相对路径（如 uploads/images/photo.png）
 * @param string $vkey 用户 vkey
 * @return string 同域代理 URL
 */
function generate_proxy_url(string $file, string $vkey): string {
    $ts      = time();
    $hmac    = hash_hmac('sha256', "{$file}|{$vkey}|{$ts}", FILE_TOKEN_SECRET);
    $token   = base64_encode("{$ts}:{$hmac}");

    // 使用当前请求的域名 + 协议构建代理 URL
    $scheme      = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $server_host = $_SERVER['HTTP_HOST'] ?? '';

    return "{$scheme}://{$server_host}/proxy_file.php?file=" . urlencode($file)
         . '&vkey='  . urlencode($vkey)
         . '&token=' . urlencode($token);
}

/**
 * 生成同域 proxy_file.php 的代理访问 URL（带 upload_id 参数）
 *
 * 与 generate_proxy_url() 的区别：支持 upload_id 定位文件（从 file_uploads 表查找的场景）
 *
 * @param string $upload_id  上传记录 ID
 * @param string $file       文件名
 * @param string $vkey       用户 vkey
 * @return string 同域代理 URL
 */
function generate_proxy_url_with_id(string $upload_id, string $file, string $vkey): string {
    $ts      = time();
    // 签名时用 id|file 组合保证安全性
    $hmac    = hash_hmac('sha256', "{$upload_id}|{$file}|{$vkey}|{$ts}", FILE_TOKEN_SECRET);
    $token   = base64_encode("{$ts}:{$hmac}");

    $scheme      = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $server_host = $_SERVER['HTTP_HOST'] ?? '';

    return "{$scheme}://{$server_host}/proxy_file.php?id=" . urlencode($upload_id)
         . '&file=' . urlencode($file)
         . '&vkey='  . urlencode($vkey)
         . '&token=' . urlencode($token);
}

/**
 * 根据 user_id 查询 vkey（需要有效的数据库连接 $conn 或 $pdo）
 *
 * @param int   $user_id
 * @param PDO   $db   数据库连接
 * @return string|null
 */
function get_vkey_by_user_id(int $user_id, PDO $db): ?string {
    $stmt = $db->prepare("SELECT vkey FROM users WHERE id = ? AND is_deleted = FALSE");
    $stmt->execute([$user_id]);
    $row = $stmt->fetch();
    return ($row && !empty($row['vkey'])) ? $row['vkey'] : null;
}
