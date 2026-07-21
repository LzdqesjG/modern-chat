<?php
require_once 'security_check.php';
header('Content-Type: application/json');
require_once 'config.php';
check_api_access();

$playlist_name = isset($_GET['name']) ? $_GET['name'] : '';

// 检查时间是否在早上8点到晚上22点之间
$current_hour = date('H');
if ($current_hour >= 8 && $current_hour < 22) {
    // 在指定时间范围内，使用temp_song_config.json
    $config_file = __DIR__ . '/config/temp_song_config.json';
} else {
    // 不在指定时间范围内，使用song_config.json
    $config_file = __DIR__ . '/config/song_config.json';
}

if (!file_exists($config_file) || empty($playlist_name)) {
    echo json_encode([]);
    exit;
}

$config = json_decode(file_get_contents($config_file), true);

if (!isset($config[$playlist_name])) {
    echo json_encode([]);
    exit;
}

$settings = $config[$playlist_name];
$type = $settings['type'];
$data = $settings['data'];

$music_list = [];

if ($type === 'local') {
    // 本地模式：扫描目录
    // 确保目录安全，防止遍历
    $base_dir = __DIR__ . '/';
    $target_dir = realpath($base_dir . $data);
    
    if ($target_dir && strpos($target_dir, $base_dir) === 0 && is_dir($target_dir)) {
        $files = scandir($target_dir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            
            $file_path = $target_dir . '/' . $file;
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            
            if (in_array($ext, ['mp3', 'm4a', 'flac', 'wav', 'ogg'])) {
                // 尝试解析元数据
                $title = pathinfo($file, PATHINFO_FILENAME);
                $artist = '未知歌手';
                $cover = 'assets/default_music_cover.png'; // 默认封面
                
                // 这里的路径需要是相对于web根目录的
                $web_path = str_replace('\\', '/', substr($file_path, strlen($base_dir)));
                
                $music_list[] = [
                    'title' => $title,
                    'artist' => $artist,
                    'url' => $web_path,
                    'cover' => $cover,
                    'lrc' => ''
                ];
            }
        }
    }
} elseif ($type === 'qqmusic') {
    // QQ音乐模式：返回歌曲名称列表，前端负责解析
    if (is_array($data)) {
        foreach ($data as $index => $item) {
            $song_name = '';
            $choose_id = '';
            
            if (is_array($item)) {
                // 处理 {"歌名": "ID"} 格式
                $song_name = key($item);
                $choose_id = current($item);
            } else {
                // 处理纯字符串格式
                $song_name = trim($item);
            }
            
            if (empty($song_name)) continue;
            
            $music_list[] = [
                'title' => $song_name, // 暂时用歌名作为标题
                'artist' => '加载中...',
                'url' => '', // 前端解析
                'cover' => 'assets/default_music_cover.png',
                'lrc' => '',
                'source_type' => 'qqmusic',
                'query_name' => $song_name,
                'choose_id' => $choose_id // 传递选择ID给前端
            ];
        }
    }
} elseif ($type === 'url') {
    // 链接模式：直接返回列表
    if (is_array($data)) {
        foreach ($data as $index => $url) {
            $url = trim($url);
            if (empty($url)) continue;
            
            // 尝试从URL获取文件名作为标题
            $filename = basename(parse_url($url, PHP_URL_PATH));
            $title = $filename ? urldecode(pathinfo($filename, PATHINFO_FILENAME)) : "Track " . ($index + 1);
            
            $music_list[] = [
                'title' => $title,
                'artist' => '网络歌曲',
                'url' => $url,
                'cover' => 'assets/default_music_cover.png',
                'lrc' => ''
            ];
        }
    }
}

echo json_encode($music_list);
