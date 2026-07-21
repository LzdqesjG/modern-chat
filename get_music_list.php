<?php
require_once 'security_check.php';
require_once 'config.php';
check_api_access();

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// 检查是否登录
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['code' => 401, 'msg' => 'Unauthorized']);
    exit;
}

$music_dir = __DIR__ . '/new_music';
if (!is_dir($music_dir)) {
    echo json_encode(['code' => 404, 'msg' => 'Music directory not found']);
    exit;
}

$files = scandir($music_dir);
$music_list = [];

// 简单的 ID3 解析，只获取标题和艺术家
function getID3Info($path) {
    $fd = fopen($path, 'rb');
    if (!$fd) return false;

    $info = ['title' => null, 'artist' => null];
    
    // 尝试读取 ID3v1 (最后128字节)
    fseek($fd, -128, SEEK_END);
    $tag = fread($fd, 128);
    
    if (substr($tag, 0, 3) === 'TAG') {
        $info['title'] = trim(substr($tag, 3, 30));
        $info['artist'] = trim(substr($tag, 33, 30));
    }
    
    // 如果没有 ID3v1 或者想优先 ID3v2
    rewind($fd);
    $header = fread($fd, 10);
    if (substr($header, 0, 3) === 'ID3') {
        // ID3v2 解析比较复杂，这里简化处理：
        // 如果 ID3v1 获取到了，就用 ID3v1，否则为了性能暂不深入解析 ID3v2 文本
        // 完整的 ID3v2 解析会显著增加响应时间
    }

    fclose($fd);
    
    // 如果是GBK/GB2312 编码，转换为 UTF-8
    // 简单的检测方式    
    foreach ($info as $key => $val) {
        if ($val) {
            $encoding = mb_detect_encoding($val, ['UTF-8', 'GBK', 'GB2312', 'ISO-8859-1']);
            if ($encoding && $encoding !== 'UTF-8') {
                $info[$key] = mb_convert_encoding($val, 'UTF-8', $encoding);
            }
        }
    }
    
    return $info;
}

foreach ($files as $file) {
    if ($file === '.' || $file === '..') continue;
    
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if (in_array($ext, ['mp3', 'm4a', 'flac'])) {
        $filepath = $music_dir . '/' . $file;
        $id3 = getID3Info($filepath);
        
        $title = $id3['title'] ?: pathinfo($file, PATHINFO_FILENAME);
        $artist = $id3['artist'] ?: '未知歌手';
        
        $music_list[] = [
            'filename' => $file,
            'name' => $title,
            'artistsname' => $artist,
            'url' => 'new_music/' . rawurlencode($file),
            'picurl' => 'get_music_cover.php?file=' . urlencode($file)
        ];
    }
}

echo json_encode(['code' => 200, 'data' => $music_list]);
?>
