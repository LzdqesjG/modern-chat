<?php
require_once 'security_check.php';
header('Content-Type: application/json');
require_once 'config.php';

// 读取歌单配置
// 检查时间是否在早上11点到晚上11点之间
$current_hour = date('H');
if ($current_hour >= 11 && $current_hour < 23) {
    // 在指定时间范围内，使用temp_song_config.json
    $config_file = __DIR__ . '/config/temp_song_config.json';
} else {
    // 不在指定时间范围内，使用song_config.json
    $config_file = __DIR__ . '/config/song_config.json';
}
if (!file_exists($config_file)) {
    echo json_encode([]);
    exit;
}

$config = json_decode(file_get_contents($config_file), true);
$playlists = [];

if ($config) {
    foreach ($config as $name => $settings) {
        $playlists[] = [
            'name' => $name,
            'type' => $settings['type']
        ];
    }
}

echo json_encode(['playlists' => $playlists]);
