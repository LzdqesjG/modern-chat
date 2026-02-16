<?php
require_once 'security_check.php';
/**
 * 音乐代理脚本
 * 用于解决跨域(CORS)问题和处理重定向链接
 * 
 * 使用方法: proxy_music.php?url=ENCODED_URL
 */

// 允许跨域访问
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
header('Access-Control-Allow-Headers: Range');
header('Access-Control-Expose-Headers: Content-Length, Content-Range, Content-Type');

$url = isset($_GET['url']) ? $_GET['url'] : '';

if (empty($url)) {
    http_response_code(400);
    exit('Missing URL parameter');
}

// 检查是否为内部 IP 地址
function isInternalIP($ip) {
    $private_ranges = [
        '127.0.0.0/8',    // 本地回环
        '10.0.0.0/8',      // A类私有地址
        '172.16.0.0/12',   // B类私有地址
        '192.168.0.0/16',   // C类私有地址
        '100.64.0.0/10',   // 共享地址空间
        '169.254.0.0/16',   // 链路本地地址
        '::1/128',          // IPv6本地回环
        'fc00::/7',         // IPv6唯一本地地址
        'fe80::/10'         // IPv6链路本地地址
    ];
    
    foreach ($private_ranges as $range) {
        list($range_ip, $prefix) = explode('/', $range);
        if (ip_in_range($ip, $range_ip, $prefix)) {
            return true;
        }
    }
    return false;
}

// 检查 IP 是否在指定范围内
function ip_in_range($ip, $range_ip, $prefix) {
    $ip_bin = inet_pton($ip);
    $range_bin = inet_pton($range_ip);
    if ($ip_bin === false || $range_bin === false) {
        return false;
    }
    
    $prefix_len = intval($prefix);
    $mask = str_repeat('f', strlen($ip_bin) * 2);
    $mask = substr($mask, 0, $prefix_len / 4) . str_repeat('0', (strlen($mask) - $prefix_len / 4));
    $mask = pack('H*', $mask);
    
    return ($ip_bin & $mask) === ($range_bin & $mask);
}

// 简单的 URL 验证
if (!filter_var($url, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    exit('Invalid URL');
}

// 解析 URL 获取主机名
$parsed_url = parse_url($url);
$host = $parsed_url['host'] ?? '';

// 检查主机名是否为内部 IP
if (filter_var($host, FILTER_VALIDATE_IP)) {
    if (isInternalIP($host)) {
        http_response_code(403);
        exit('Access denied: Internal IP addresses are not allowed');
    }
} else {
    // 检查主机名是否解析为内部 IP
    $ips = gethostbynamel($host);
    if ($ips) {
        foreach ($ips as $ip) {
            if (isInternalIP($ip)) {
                http_response_code(403);
                exit('Access denied: Internal IP addresses are not allowed');
            }
        }
    }
}

// 初始�?CURL
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // 关键：跟随重定向
curl_setopt($ch, CURLOPT_RETURNTRANSFER, false); // 直接输出内容
curl_setopt($ch, CURLOPT_HEADER, false); // 不输出头部信息到内容�?curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // 忽略 SSL 验证
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
// 伪装 User-Agent，避免部分服务器拒绝请求
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');

// 转发 Range 头，支持拖动进度�?
$headers = [];
if (isset($_SERVER['HTTP_RANGE'])) {
    $headers[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
    curl_setopt($ch, CURLOPT_RANGE, str_replace('bytes=', '', $_SERVER['HTTP_RANGE']));
}
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

// 处理头部回调，转�?Content-Type �?Content-Length
curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($curl, $header) {
    $len = strlen($header);
    $header = trim($header);
    if (empty($header)) return $len;
    
    // 转发关键头部
    if (stripos($header, 'Content-Type:') === 0 || 
        stripos($header, 'Content-Length:') === 0 || 
        stripos($header, 'Content-Range:') === 0 ||
        stripos($header, 'Accept-Ranges:') === 0 ||
        stripos($header, 'HTTP/') === 0) { // 转发状态码，如 HTTP/1.1 206 Partial Content
        header($header);
    }
    
    return $len;
});

// 执行请求
curl_exec($ch);

// 错误处理
if (curl_errno($ch)) {
    http_response_code(500);
    echo 'Curl error: ' . curl_error($ch);
}

curl_close($ch);
