<?php
// 数据库配置
define('DB_HOST', 'localhost');
define('DB_NAME', 'chat');
define('DB_USER', 'root');
define('DB_PASS', 'cf211396ab9363ad');

// 应用配置
define('APP_NAME', 'Modern Chat');
define('APP_URL', 'http://localhost/chat');

// 上传配置
define('UPLOAD_DIR', 'uploads/');
define('MAX_FILE_SIZE', 150 * 1024 * 1024); // 150MB

define('ALLOWED_FILE_TYPES', [
    'image/jpeg', 'image/png', 'image/gif', 'image/webp',
    'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'text/plain', 'text/csv',
    'video/mp4', 'video/webm', 'video/ogg',
    'audio/mpeg', 'audio/wav', 'audio/ogg'
]);

// 会话配置
define('SESSION_TIMEOUT', 3600); // 1小时

// 安全配置
define('HASH_ALGO', PASSWORD_DEFAULT);
define('HASH_COST', 12);

// 错误报告配置
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', 'error.log');

// 设置时区
date_default_timezone_set('Asia/Shanghai');

// 启用会话
if (!isset($_SESSION)) {
    session_start();
}

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
