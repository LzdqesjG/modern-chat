<?php
// 启用会话，必须在任何输出之前调用
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';
check_api_access();

// 读取配置文件
$config_file = 'config/config.json';
$config_data = json_decode(file_get_contents($config_file), true);

// 获取请求的配置键
$key = isset($_GET['key']) ? $_GET['key'] : '';

// 返回对应配置值
if (!empty($key)) {
    $value = isset($config_data[$key]) ? $config_data[$key] : '';
    echo json_encode(['value' => $value]);
} else {
    echo json_encode(['value' => '']);
}
?>