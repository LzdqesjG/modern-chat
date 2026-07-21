<?php
require_once 'config.php';
check_api_access();

/**
 * proxy_file.php — PHP 代理，转发请求到 files.modern-chat.top/list.php
 * ================================================================
 *
 * 目的：避免浏览器跨域 CORS 限制。
 * 前端 JS (fetch/XHR) 请求同域 proxy_file.php → cURL → files.modern-chat.top/list.php
 *
 * 用法（透传所有 GET 参数）：
 *   proxy_file.php?file=uploads/images/photo.jpg&vkey=xxx&token=yyy
 *   proxy_file.php?id=abc123&file=video.mp4&vkey=xxx&token=yyy
 *   proxy_file.php?file=uploads/images/photo.jpg&vkey=xxx&token=yyy&action=query
 */

// ═══════════════════════════════════════════════════════
// 配置
// ═══════════════════════════════════════════════════════
define('TARGET_HOST', 'https://files.modern-chat.top');
define('TARGET_PATH', '/list.php');
define('CURL_TIMEOUT', 120);       // cURL 超时（秒）

// ═══════════════════════════════════════════════════════
// 仅接受 GET 请求
// ═══════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => '仅支持 GET 请求', 'code' => 405], JSON_UNESCAPED_UNICODE);
    exit;
}

// ═══════════════════════════════════════════════════════
// 安全检查：禁止访问敏感文件类型和非 uploads 目录
// ═══════════════════════════════════════════════════════
$file = $_GET['file'] ?? '';
if (!empty($file)) {
    $file = str_replace('\\', '/', $file);
    if (strpos($file, '..') !== false) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => '无效的路径', 'code' => 403], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $blocked_extensions = ['php', 'php3', 'php4', 'php5', 'phtml', 'pht', 'inc', 'json', 'xml', 'ini', 'conf', 'env', 'htaccess', 'sql', 'log', 'htpasswd', 'git'];
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if (in_array($ext, $blocked_extensions)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => '禁止访问该类型文件', 'code' => 403], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $file = ltrim($file, '/');

    $allowed_dirs = ['uploads/', 'avatars/', 'new_music/', 'download/'];
    $is_allowed = false;
    foreach ($allowed_dirs as $dir) {
        if (strpos($file, $dir) === 0) {
            $is_allowed = true;
            break;
        }
    }
    if (!$is_allowed) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => '禁止访问该目录', 'code' => 403], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ═══════════════════════════════════════════════════════
// 构建目标 URL
// ═══════════════════════════════════════════════════════
$query_string = $_SERVER['QUERY_STRING'] ?? '';
$target_url = TARGET_HOST . TARGET_PATH;
if ($query_string !== '') {
    $target_url .= '?' . $query_string;
}

// ═══════════════════════════════════════════════════════
// 收集需要转发的请求头
// ═══════════════════════════════════════════════════════
$forward_headers = [];

// 转发 Range 头（视频/音频拖动进度条需要）
if (!empty($_SERVER['HTTP_RANGE'])) {
    $forward_headers[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
}

// 转发 User-Agent
if (!empty($_SERVER['HTTP_USER_AGENT'])) {
    $forward_headers[] = 'User-Agent: ' . $_SERVER['HTTP_USER_AGENT'];
}

// ═══════════════════════════════════════════════════════
// 流式代理 — 边下载边输出，不缓冲整个响应
// ═══════════════════════════════════════════════════════

$response_headers = [];     // 收集目标服务器的响应头
$header_sent      = false;  // 是否已向客户端输出响应头
$first_chunk      = true;   // 是否是第一个数据块
$body_buffer      = '';     // 小缓冲（用于判断前几个字节是否是 JSON）

$ch = curl_init($target_url);

// 设置自定义请求头
if (!empty($forward_headers)) {
    curl_setopt($ch, CURLOPT_HTTPHEADER, $forward_headers);
}

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER  => false,       // 流式：不缓冲到内存
    CURLOPT_FOLLOWLOCATION  => true,
    CURLOPT_TIMEOUT         => CURL_TIMEOUT,
    CURLOPT_CONNECTTIMEOUT  => 10,
    CURLOPT_SSL_VERIFYPEER  => true,
    CURLOPT_SSL_VERIFYHOST  => 2,
    CURLOPT_ENCODING        => '',
    CURLOPT_BUFFERSIZE      => 128 * 1024,
    CURLOPT_HEADERFUNCTION  => function($ch, $header_line) use (&$response_headers, &$header_sent) {
        $trimmed = trim($header_line);
        if ($trimmed === '') {
            // 空行表示头部结束 → 立即输出收集到的响应头
            if (!$header_sent) {
                _proxy_flush_headers($response_headers);
                $header_sent = true;
            }
            return strlen($header_line);
        }

        // 跳过 HTTP 状态行
        if (stripos($trimmed, 'HTTP/') === 0) {
            // 解析状态码
            if (preg_match('/^HTTP\/\d\.\d\s+(\d+)/', $trimmed, $m)) {
                $response_headers['_http_code'] = (int)$m[1];
            }
            return strlen($header_line);
        }

        $response_headers[] = $trimmed;
        return strlen($header_line);
    },
    CURLOPT_WRITEFUNCTION   => function($ch, $data) use (&$header_sent, &$response_headers) {
        // 确保响应头已经输出
        if (!$header_sent) {
            _proxy_flush_headers($response_headers);
            $header_sent = true;
        }

        // 直接输出数据块
        echo $data;
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();

        return strlen($data);
    },
]);

$result = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error_msg = curl_error($ch);
$error_no  = curl_errno($ch);

curl_close($ch);

// ═══════════════════════════════════════════════════════
// 错误处理（cURL 失败时才会走到这里）
// ═══════════════════════════════════════════════════════
if ($error_no !== 0) {
    if (!$header_sent) {
        http_response_code(502);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'error'  => '代理请求失败',
        'code'   => 502,
        'detail' => $error_msg
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 如果目标服务器完全无响应且还没输出内容
if ($result === false && !$header_sent && empty($response_headers)) {
    http_response_code(502);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error' => '目标服务器无响应',
        'code'  => 502
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ═══════════════════════════════════════════════════════
// 辅助函数：输出转发响应头
// ═══════════════════════════════════════════════════════
function _proxy_flush_headers(array $response_headers): void {
    $skip_headers = [
        'transfer-encoding',
        'connection',
        'keep-alive',
        'proxy-authenticate',
        'proxy-authorization',
        'te',
        'trailers',
        'upgrade',
    ];

    // 设置 HTTP 状态码（404 → 410，防止 Nginx 拦截）
    $http_code = $response_headers['_http_code'] ?? 200;
    if ($http_code === 404) {
        $http_code = 410;
    }
    http_response_code($http_code);

    // 输出收集的响应头
    foreach ($response_headers as $header_line) {
        if (!is_string($header_line)) continue;
        $colon_pos = strpos($header_line, ':');
        if ($colon_pos === false) continue;

        $header_key = strtolower(trim(substr($header_line, 0, $colon_pos)));
        if (in_array($header_key, $skip_headers, true)) continue;

        header($header_line, false);
    }
}
