<?php
// 启用会话，必须在任何输出之前调用
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 缓存.env文件内容
$env_vars = [];
$env_file = __DIR__ . '/.env';
if (file_exists($env_file)) {
    $lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // 跳过注释行
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }
        
        // 解析键值对
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) continue;
        
        $key = trim($parts[0]);
        $value = trim($parts[1]);
        
        // 移除引号（如果有）
        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || 
            (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            $value = substr($value, 1, -1);
        }
        
        $env_vars[$key] = $value;
        
        // 设置环境变量
        if (function_exists('putenv')) {
            putenv("$key=$value");
        }
    }
}

// 获取环境变量的辅助函数
function getEnvVar($key, $default = '') {
    global $env_vars;
    
    // 1. 优先从环境变量缓存获取
    if (isset($env_vars[$key])) {
        return $env_vars[$key];
    }
    
    // 2. 从 $_SERVER 获取
    if (isset($_SERVER[$key])) {
        return $_SERVER[$key];
    }
    
    // 3. $_SERVER 小写形式
    $lowerKey = strtolower($key);
    if (isset($_SERVER[$lowerKey])) {
        return $_SERVER[$lowerKey];
    }
    
    // 4. $_SERVER 下划线转点
    $dotKey = str_replace('_', '.', $lowerKey);
    if (isset($_SERVER[$dotKey])) {
        return $_SERVER[$dotKey];
    }
    
    // 5. getenv 函数
    if (function_exists('getenv')) {
        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }
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
define('DB_PASS', getEnvVar('DB_PASS') ?: getEnvVar('DB_PASSWORD') ?: getEnvVar('MYSQL_ROOT_PASSWORD') ?: getConfig('db_password') ?: 'cf211396ab9363ad');

// 应用配置
define('APP_NAME', 'Modern Chat');
define('APP_URL', 'http://localhost/chat');

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
