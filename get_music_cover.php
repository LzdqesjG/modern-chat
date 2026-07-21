<?php
require_once 'security_check.php';
require_once 'config.php';
check_api_access();

// 检查是否登�?
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['code' => 401, 'msg' => '未授权']);
    exit;
}

$filename = isset($_GET['file']) ? $_GET['file'] : '';
if (empty($filename)) {
    http_response_code(400);
    echo json_encode(['code' => 400, 'msg' => '文件名是必需的']);
    exit;
}

// 安全检�?
$filename = basename($filename);
$filepath = __DIR__ . '/new_music/' . $filename;

if (!file_exists($filepath)) {
    http_response_code(404);
    echo json_encode(['code' => 404, 'msg' => '文件未找到']);
    exit;
}

// 简单的 ID3v2 APIC 帧解析器
function getID3Cover($path) {
    $fd = fopen($path, 'rb');
    if (!$fd) return null;

    $header = fread($fd, 10);
    if (substr($header, 0, 3) !== 'ID3') {
        fclose($fd);
        return null;
    }

    $major_version = ord($header[3]);
    $flags = ord($header[5]);
    
    // 计算标签大小 (Synchsafe integers)
    $size = (ord($header[6]) << 21) | (ord($header[7]) << 14) | (ord($header[8]) << 7) | ord($header[9]);
    
    // 如果有扩展头，跳�?    
if ($flags & 0x40) {
        // 简单跳过，不严谨但通常够用
        // ID3v2.3 扩展头大小在头里，ID3v2.4 �?synchsafe
        // 这里简化处理，假设没有扩展头或者运气好
    }

    $end = ftell($fd) + $size;
    $cover_data = null;
    $mime_type = 'image/jpeg';

    while (ftell($fd) < $end) {
        // 读取帧头
        $frame_header = fread($fd, 10);
        if (strlen($frame_header) < 10) break;
        
        $frame_id = substr($frame_header, 0, 4);
        
        // 帧大�?        
if ($major_version == 4) {
            $frame_size = (ord($frame_header[4]) << 21) | (ord($frame_header[5]) << 14) | (ord($frame_header[6]) << 7) | ord($frame_header[7]);
        } else {
            $frame_size = (ord($frame_header[4]) << 24) | (ord($frame_header[5]) << 16) | (ord($frame_header[6]) << 8) | ord($frame_header[7]);
        }
        
        if ($frame_size == 0) break; // Padding

        if ($frame_id === 'APIC') {
            $frame_data = fread($fd, $frame_size);
            
            // 解析 APIC 数据
            // [0] Text encoding
            // [1..] MIME type (null terminated)
            // [x] Picture type
            // [x+1..] Description (null terminated)
            // [y..] Picture data
            
            $encoding = ord($frame_data[0]);
            $offset = 1;
            
            // MIME type
            $mime_end = strpos($frame_data, "\0", $offset);
            $mime_type = substr($frame_data, $offset, $mime_end - $offset);
            $offset = $mime_end + 1;
            
            // Picture type
            $pic_type = ord($frame_data[$offset]);
            $offset++;
            
            // Description
            // 根据编码跳过描述
            // 这里简化：寻找下一�?\0 (ISO-8859-1) �?\0\0 (UTF-16) 并跳过图片数据的开�?            // 实际上比较复杂，我们采用简单的启发式查�?JPEG/PNG �?            
            // 查找 JPEG (FF D8) �?PNG (89 50 4E 47)
            $jpg_start = strpos($frame_data, "\xFF\xD8", $offset);
            $png_start = strpos($frame_data, "\x89PNG", $offset);
            
            $data_start = false;
            if ($jpg_start !== false && ($png_start === false || $jpg_start < $png_start)) {
                $data_start = $jpg_start;
                if (!$mime_type) $mime_type = 'image/jpeg';
            } elseif ($png_start !== false) {
                $data_start = $png_start;
                if (!$mime_type) $mime_type = 'image/png';
            }
            
            if ($data_start !== false) {
                $cover_data = substr($frame_data, $data_start);
                break;
            }
        } else {
            fseek($fd, $frame_size, SEEK_CUR);
        }
    }

    fclose($fd);
    return $cover_data ? ['data' => $cover_data, 'mime' => $mime_type] : null;
}

$cover = getID3Cover($filepath);

if ($cover) {
    echo json_encode([
        'code' => 200,
        'data' => base64_encode($cover['data']),
        'mime' => $cover['mime']
    ]);
} else {
    // 返回默认图片或空
    echo json_encode(['code' => 404, 'msg' => '未找到封面']);
}
?>