<?php
// PHP 8.0 以下版本的兼容性函数
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        return (string)$needle !== '' && strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle) {
        return $needle !== '' && substr($haystack, -strlen($needle)) === (string)$needle;
    }
}

// 启用会话，必须在任何输出之前调用
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 加载安全辅助函数
require_once __DIR__ . '/includes/security_helper.php';

// 设置安全响应头
setSecurityHeaders();

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
    
    // 2. 尝试多种 $_SERVER 键名格式
    $keys = [$key, strtolower($key), str_replace('_', '.', strtolower($key))];
    foreach ($keys as $k) {
        if (isset($_SERVER[$k])) {
            return $_SERVER[$k];
        }
    }
    
    // 3. getenv 函数
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

// IP地址获取函数 - 安全版本，防止IP欺骗攻击
function getUserIP() {
    $ip = '';
    $trusted_proxies = getEnvVar('TRUSTED_PROXIES', '');
    $trusted_proxy_list = $trusted_proxies ? array_map('trim', explode(',', $trusted_proxies)) : [];
    
    $remote_addr = $_SERVER['REMOTE_ADDR'] ?? '';
    
    if (in_array($remote_addr, $trusted_proxy_list) || !empty($trusted_proxy_list)) {
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $forwarded_ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $forwarded_ips = array_map('trim', $forwarded_ips);
            $forwarded_ips = array_filter($forwarded_ips, function($ip) {
                return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
            });
            if (!empty($forwarded_ips)) {
                $ip = end($forwarded_ips);
            }
        }
        if (empty($ip) && !empty($_SERVER['HTTP_CLIENT_IP'])) {
            $client_ip = trim($_SERVER['HTTP_CLIENT_IP']);
            if (filter_var($client_ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                $ip = $client_ip;
            }
        }
    }
    
    if (empty($ip)) {
        $ip = $remote_addr;
    }
    
    $ip = filter_var($ip, FILTER_VALIDATE_IP);
    return $ip ?: '0.0.0.0';
}

// 数据库配置 - 必须通过环境变量或配置文件设置，不再使用硬编码默认密码
define('DB_HOST', getEnvVar('DB_HOST') ?: getEnvVar('DB_HOSTNAME') ?: 'localhost');
define('DB_NAME', getEnvVar('DB_NAME') ?: getEnvVar('DATABASE_NAME') ?: 'chat');
define('DB_USER', getEnvVar('DB_USER') ?: getEnvVar('DB_USERNAME') ?: 'root');
$db_pass = getEnvVar('DB_PASS') ?: getEnvVar('DB_PASSWORD') ?: getEnvVar('MYSQL_ROOT_PASSWORD') ?: getConfig('db_password');
if (empty($db_pass)) {
    error_log("SECURITY WARNING: Database password not configured. Please set DB_PASS in .env file or db_password in config.json");
}
define('DB_PASS', $db_pass ?: '');

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

// API文件列表（不受会话超时跳转影响）
define('API_FILES', [
    'get_new_messages.php', 'get_group_members.php', 'mark_messages_read.php', 'get_new_group_messages.php', 
    'send_message.php', 'add_group_members.php', 'create_group.php', 'delete_friend.php', 
    'delete_group.php', 'get_available_friends.php', 'get_ban_records.php', 'leave_group.php', 
    'remove_group_member.php', 'send_friend_request.php', 'set_group_admin.php', 'transfer_ownership.php',
    'get_group_invitations.php', 'accept_group_invitation.php', 'reject_group_invitation.php',
    'send_join_request.php', 'get_join_requests.php', 'approve_join_request.php', 'reject_join_request.php',
    'recall_message.php'
]);

// 会话超时检查
if (isset($_SESSION['last_activity']) && isset($_SESSION['user_id'])) {
    $session_duration = time() - $_SESSION['last_activity'];
    
    if ($session_duration > SESSION_TIMEOUT) {
        session_unset();
        session_destroy();
        $current_file = basename($_SERVER['PHP_SELF']);
        if (!in_array($current_file, API_FILES)) {
            header('Location: login.php?error=' . urlencode('会话已过期，请重新登录'));
            exit;
        }
    } else {
        $_SESSION['last_activity'] = time();
    }
}
