<?php
require_once 'security_check.php';
header('Content-Type: application/json');

// 检查是否是POST请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => '无效的请求方法']);
    exit;
}

// 获取请求数据
$input = json_decode(file_get_contents('php://input'), true);
$song_name = isset($input['song_name']) ? $input['song_name'] : '';

if (empty($song_name)) {
    echo json_encode(['success' => false, 'message' => '缺少歌曲名称']);
    exit;
}

// 检查时间是否在早上11点到晚上11点之间
$current_hour = date('H');
if ($current_hour >= 9 && $current_hour < 23) {
    // 在指定时间范围内，使用temp_song_config.json
    $config_file = __DIR__ . '/config/temp_song_config.json';
} else {
    // 不在指定时间范围内不处理
    echo json_encode(['success' => false, 'message' => '点歌功能仅在早上11点到晚上11点开放']);
    exit;
}

if (!file_exists($config_file)) {
    echo json_encode(['success' => false, 'message' => '配置文件不存在']);
    exit;
}

// 读取配置文件
$config = json_decode(file_get_contents($config_file), true);

// 检查歌单是否存在
if (!isset($config['歌单']) || !isset($config['歌单']['data'])) {
    echo json_encode(['success' => false, 'message' => '歌单不存在']);
    exit;
}

// 从歌单中删除歌曲
$updated = false;
$new_data = [];

foreach ($config['歌单']['data'] as $item) {
    if (is_array($item)) {
        $current_song = key($item);
        if (strpos($current_song, $song_name) === false) {
            $new_data[] = $item;
        } else {
            $updated = true;
        }
    }
}

// 更新配置文件
if ($updated) {
    $config['歌单']['data'] = $new_data;
    file_put_contents($config_file, json_encode($config, JSON_PRETTY_PRINT));
    echo json_encode(['success' => true, 'message' => '歌曲删除成功']);
} else {
    // 歌曲不存在，返回成功以便前端知道歌曲已被删除
    echo json_encode(['success' => true, 'message' => '歌曲已被删除']);
}
?>