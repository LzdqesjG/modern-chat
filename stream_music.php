<?php
/**
 * stream_music.php �� ����������ʽ����
 *
 * ͨ�� files.modern-chat.top/list.php ͳһ�ṩ�ļ�����
 * ʵ���ļ������� list.php ������
 */
require_once 'security_check.php';
require_once 'config.php';
check_api_access();
require_once 'db.php';

// ����Ƿ��¼
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit;
}

$filename = isset($_GET['file']) ? $_GET['file'] : '';
if (empty($filename)) {
    http_response_code(400);
    exit;
}

// ��ȫ��飬��ֹĿ¼����
$filename = basename($filename);
$filepath = __DIR__ . '/new_music/' . $filename;

if (file_exists($filepath)) {
    // ͨ�� list.php ����ǩ�� URL
    $user_id = $_SESSION['user_id'];
    $vkey = ($conn !== null) ? get_vkey_by_user_id($user_id, $conn) : null;

    if ($vkey) {
        $url = generate_file_url('new_music/' . $filename, $vkey);
        header('Location: ' . $url, true, 302);
        exit;
    }

    // ���ˣ��� vkey ʱֱ����������������ԣ�
    $ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
    $mime_types = [
        'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4',
        'ogg' => 'audio/ogg', 'wav' => 'audio/wav',
        'flac' => 'audio/flac', 'webm' => 'audio/webm'
    ];
    $mime_type = $mime_types[$ext] ?? 'application/octet-stream';

    if ($mime_type === 'application/octet-stream' && function_exists('mime_content_type')) {
        $detected = @mime_content_type($filepath);
        if ($detected) $mime_type = $detected;
    }

    @ini_set('zlib.output_compression', 'Off');
    @ini_set('output_buffering', 'Off');
    @ini_set('output_handler', '');
    while (ob_get_level()) ob_end_clean();

    $filesize = filesize($filepath);
    header('Cache-Control: public, max-age=31536000');
    header('Content-Disposition: inline; filename="' . basename($filename) . '"');
    header('Content-Transfer-Encoding: binary');
    header('Accept-Ranges: bytes');

    $range = $_SERVER['HTTP_RANGE'] ?? null;
    if ($range) {
        [$param, $range_val] = explode('=', $range, 2);
        if (strtolower(trim($param)) !== 'bytes') { http_response_code(400); exit; }
        $range_parts = explode('-', $range_val);
        $start = (int) $range_parts[0];
        $end = (isset($range_parts[1]) && $range_parts[1] !== '') ? (int) $range_parts[1] : $filesize - 1;
        if ($start > $end || $start >= $filesize) {
            http_response_code(416);
            header("Content-Range: bytes */{$filesize}");
            exit;
        }
        $length = $end - $start + 1;
        http_response_code(206);
        header("Content-Type: {$mime_type}");
        header("Content-Range: bytes {$start}-{$end}/{$filesize}");
        header("Content-Length: {$length}");
        $fp = fopen($filepath, 'rb');
        fseek($fp, $start);
        $buffer = 8192;
        while (!feof($fp) && ($p = ftell($fp)) <= $end) {
            if ($p + $buffer > $end) $buffer = $end - $p + 1;
            echo fread($fp, $buffer);
            flush();
        }
        fclose($fp);
        exit;
    }

    http_response_code(200);
    header("Content-Type: {$mime_type}");
    header("Content-Length: {$filesize}");
    readfile($filepath);
    exit;
}

http_response_code(404);
